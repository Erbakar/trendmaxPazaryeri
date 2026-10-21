<?php
/**
 * OzyConnect Panel — WebhookController (pazaryeri/e-ticaret platform webhook ALICISI)
 *
 * Public + CSRF muaf (sunucu-sunucu). URL: POST /webhooks/{slug}/{token}
 *  - {token} önce MAĞAZAYA ÖZEL anahtar olarak aranır (store_marketplace_configs → webhook_token):
 *    anahtar mağazayı ve pazaryerini birlikte belirler, TenantContext o mağazaya kurulur.
 *  - Geriye uyum: bulunamazsa eski GLOBAL marketplaces.webhook_token yalnız o pazaryerine yetkili
 *    TEK aktif mağaza varsa kabul edilir. Birden çok mağazada hangi mağazaya ait olduğu
 *    bilinemeyeceği için 404 (sipariş yanlış mağazaya düşmesin).
 *  - İmza: ÇÖZÜLEN mağazanın webhook_secret'ı ile — base64(hmac_sha256(gövde, sır)).
 *    Shopify: X-Shopify-Hmac-Sha256 · WooCommerce: X-WC-Webhook-Signature.
 *    OpenCart imza üretemez; yalnız URL'deki gizli anahtar ile korunur.
 *  - Sipariş konuları → OrderSyncService::ingestWebhookOrder (sipariş anında işlenir).
 *
 * Yanıtlar: tanınmayan anahtar 404 · imza hatası 401 · anahtar tanındı ama mağaza/yetki
 * pasif 200 (platform boşuna yeniden denemesin) · işleme hatası 200 + günlük.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Services\Marketplace\ShopifyService;
use App\Services\Marketplace\WooCommerceService;
use App\Services\Marketplace\OpenCartService;
use App\Services\Sync\OrderSyncService;

final class WebhookController
{
    /** Webhook alıcısı olan platformlar. */
    private const SUPPORTED_SLUGS = ['shopify', 'woocommerce', 'opencart'];

    /** İşlenen WooCommerce konuları (order.deleted yükü yalnız id taşır → işlenmez). */
    private const WOO_ORDER_TOPICS = ['order.created', 'order.updated', 'order.restored'];

    /** URL'den gelen anahtar için üst sınır (sütun 64, mağaza anahtarı en çok 128). */
    private const MAX_TOKEN_LENGTH = 128;

    public function __construct(
        private readonly Database $db,
        private readonly Marketplace $marketplaceModel,
        private readonly OrderSyncService $orderSync,
        private readonly ShopifyService $shopify,
        private readonly WooCommerceService $woocommerce,
        private readonly OpenCartService $opencart,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
    ) {}

    public function handle(Request $request, string $slug, string $token): void
    {
        $rawBody = file_get_contents('php://input');
        $rawBody = $rawBody === false ? '' : $rawBody;

        $token = trim($token);
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH || !in_array($slug, self::SUPPORTED_SLUGS, true)) {
            $this->respond(404, 'not found');
            return;
        }

        $target = $this->resolveTarget($slug, $token);
        if ($target === null) {
            $this->respond(404, 'not found');
            return;
        }

        $marketplaceId = $target['marketplace_id'];
        $storeId       = $target['store_id'];
        if ($storeId === 0) {
            $this->logger->info('Webhook atlandı: mağaza ya da pazaryeri yetkisi aktif değil', 'Webhook', ['slug' => $slug, 'mp' => $marketplaceId, 'store' => $target['matched_store_id']]);
            $this->respond(200, 'no active store');
            return;
        }

        $this->tenant->setStoreId($storeId);
        try {
            [$code, $body] = match ($slug) {
                'shopify'     => $this->handleShopify($request, $rawBody, $marketplaceId, $storeId),
                'woocommerce' => $this->handleWooCommerce($request, $rawBody, $marketplaceId, $storeId),
                'opencart'    => $this->handleOpenCart($rawBody, $marketplaceId, $storeId),
            };
        } catch (\Throwable $e) {
            // Platformlar hata kodunda yeniden dener; kalıcı hatalarda kuyruk şişmesin diye 200 + günlük.
            $this->logger->error('Webhook işleme hatası: ' . $e->getMessage(), 'Webhook', ['slug' => $slug, 'mp' => $marketplaceId, 'store' => $storeId]);
            [$code, $body] = [200, 'OK'];
        } finally {
            $this->tenant->clear();
        }

        $this->respond($code, $body);
    }

    /**
     * Anahtar → pazaryeri + mağaza. null: tanınmadı (404). store_id 0: tanındı ama mağaza pasif
     * (matched_store_id hangi mağaza olduğunu günlük için taşır).
     *
     * @return array{marketplace_id: int, store_id: int, matched_store_id: int}|null
     */
    private function resolveTarget(string $slug, string $token): ?array
    {
        $marketplace = $this->db->fetchOne(
            "SELECT id, webhook_token, is_active FROM marketplaces WHERE slug = :s LIMIT 1",
            ['s' => $slug]
        );
        if ($marketplace === false) {
            return null;
        }
        $marketplaceId = (int) $marketplace['id'];
        // Platform pazaryerini kapattıysa hiçbir mağaza için işlenmez (anahtar tanınsa da)
        $platformActive = (int) ($marketplace['is_active'] ?? 0) === 1;

        // 1) Mağazaya özel anahtar
        $matches = $this->marketplaceModel->findStoreConfigsByWebhookToken($token, $marketplaceId);
        if (count($matches) > 1) {
            $this->logger->error('Webhook anahtarı birden çok mağazada kayıtlı; bildirim reddedildi', 'Webhook', ['slug' => $slug, 'mp' => $marketplaceId]);
            return null;
        }
        if (count($matches) === 1) {
            $storeId = $matches[0]['store_id'];
            // Mağaza pazaryerini panelde kapattıysa (is_enabled = 0) sipariş işlenmez → 200 'no active store'
            // (platform yeniden denemesin); paket yetkisi ve mağaza durumu da aranır.
            $active = $platformActive
                && (int) ($matches[0]['is_enabled'] ?? 0) === 1
                && $this->isStoreActiveFor($storeId, $marketplaceId);
            return [
                'marketplace_id'   => $marketplaceId,
                'store_id'         => $active ? $storeId : 0,
                'matched_store_id' => $storeId,
            ];
        }

        // 2) Geriye uyum: eski global anahtar — yalnız TEK aktif yetkili mağaza varsa
        $globalToken = (string) ($marketplace['webhook_token'] ?? '');
        if ($globalToken === '' || !hash_equals($globalToken, $token)) {
            return null;
        }

        if (!$platformActive) {
            $this->logger->info('Webhook (eski global anahtar) atlandı: pazaryeri platformda kapalı', 'Webhook', ['slug' => $slug, 'mp' => $marketplaceId]);
            return ['marketplace_id' => $marketplaceId, 'store_id' => 0, 'matched_store_id' => 0];
        }

        $storeIds = $this->activeStoreIdsFor($marketplaceId);
        if (count($storeIds) !== 1) {
            $this->logger->warning(
                'Eski (global) webhook anahtarı reddedildi: pazaryerine yetkili aktif mağaza sayısı ' . count($storeIds) . '. Mağazaya özel webhook adresine geçin.',
                'Webhook',
                ['slug' => $slug, 'mp' => $marketplaceId]
            );
            return null;
        }

        $this->logger->info('Webhook eski (global) anahtarla geldi; mağazaya özel adrese geçilmesi önerilir', 'Webhook', ['slug' => $slug, 'mp' => $marketplaceId, 'store' => $storeIds[0]]);

        return ['marketplace_id' => $marketplaceId, 'store_id' => $storeIds[0], 'matched_store_id' => $storeIds[0]];
    }

    /** Mağaza aktif ve bu pazaryerine geçerli yetkisi (store_marketplaces) var mı? */
    private function isStoreActiveFor(int $storeId, int $marketplaceId): bool
    {
        return $this->db->fetchOne(
            "SELECT sm.store_id FROM store_marketplaces sm
               JOIN stores s ON s.id = sm.store_id
              WHERE sm.store_id = :sid AND sm.marketplace_id = :m AND sm.status = 'active'
                AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
                AND s.status = 'active'
              LIMIT 1",
            ['sid' => $storeId, 'm' => $marketplaceId]
        ) !== false;
    }

    /**
     * Pazaryerine yetkili, bağlantısı kayıtlı ve açık aktif mağazalar (en çok 2 — tekil mi değil mi bilmek
     * yeter). Bağlantısı (store_marketplace_configs) olmayan mağaza imzayı zaten doğrulayamaz; sayıma girerse
     * tek gerçek kullanıcının eski adresli webhook'ları da reddedilirdi.
     *
     * @return list<int>
     */
    private function activeStoreIdsFor(int $marketplaceId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT sm.store_id FROM store_marketplaces sm
               JOIN stores s ON s.id = sm.store_id
               JOIN store_marketplace_configs smc
                    ON smc.store_id = sm.store_id AND smc.marketplace_id = sm.marketplace_id AND smc.is_enabled = 1
              WHERE sm.marketplace_id = :m AND sm.status = 'active'
                AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
                AND s.status = 'active'
              ORDER BY sm.store_id
              LIMIT 2",
            ['m' => $marketplaceId]
        );

        return array_map(static fn(array $row): int => (int) $row['store_id'], $rows);
    }

    /** @return array{0: int, 1: string} */
    private function handleShopify(Request $request, string $rawBody, int $marketplaceId, int $storeId): array
    {
        $hmac = (string) $request->server('HTTP_X_SHOPIFY_HMAC_SHA256', '');
        if (!$this->signatureValid($rawBody, $hmac, $this->storeWebhookSecret($marketplaceId))) {
            $this->logger->warning('Shopify webhook imza doğrulanamadı', 'Webhook', ['mp' => $marketplaceId, 'store' => $storeId]);
            return [401, 'bad hmac'];
        }

        $topic   = (string) $request->server('HTTP_X_SHOPIFY_TOPIC', '');
        $payload = json_decode($rawBody, true);
        // orders/delete yükü yalnız id taşır; işlenirse boş sipariş yazılırdı.
        if (is_array($payload) && str_starts_with($topic, 'orders/') && $topic !== 'orders/delete') {
            $normalized = $this->shopify->normalizeWebhookOrder($payload);
            $this->orderSync->ingestWebhookOrder($marketplaceId, $normalized);
            $this->logger->info("Shopify webhook işlendi: {$topic} #" . ($normalized['marketplace_order_id'] ?? '?'), 'Webhook', ['store' => $storeId]);
        }

        return [200, 'OK'];
    }

    /** @return array{0: int, 1: string} */
    private function handleWooCommerce(Request $request, string $rawBody, int $marketplaceId, int $storeId): array
    {
        $signature = (string) $request->server('HTTP_X_WC_WEBHOOK_SIGNATURE', '');
        $topic     = (string) $request->server('HTTP_X_WC_WEBHOOK_TOPIC', '');

        // Webhook kurulurken WooCommerce teslim adresini imzasız "webhook_id=<n>" gövdesiyle yoklar;
        // 200 dönmezse webhook'u hatalı sayar. Yoklama hiçbir şey işlemez.
        if ($signature === '' && $topic === '' && preg_match('/^webhook_id=\d+$/', trim($rawBody)) === 1) {
            return [200, 'pong'];
        }

        if (!$this->signatureValid($rawBody, $signature, $this->storeWebhookSecret($marketplaceId))) {
            $this->logger->warning('WooCommerce webhook imza doğrulanamadı', 'Webhook', ['mp' => $marketplaceId, 'store' => $storeId]);
            return [401, 'bad signature'];
        }

        if (!in_array($topic, self::WOO_ORDER_TOPICS, true)) {
            return [200, 'ignored'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['id'])) {
            return [200, 'ignored'];
        }

        $normalized = $this->woocommerce->normalizeWebhookOrder($payload);
        $this->orderSync->ingestWebhookOrder($marketplaceId, $normalized);
        $this->logger->info("WooCommerce webhook işlendi: {$topic} #" . ($normalized['marketplace_order_id'] ?? '?'), 'Webhook', ['store' => $storeId]);

        return [200, 'OK'];
    }

    /** @return array{0: int, 1: string} */
    private function handleOpenCart(string $rawBody, int $marketplaceId, int $storeId): array
    {
        // OpenCart event eklentisi yalnız order_id gönderir; sipariş connector üzerinden okunur.
        $payload = json_decode($rawBody, true);
        $orderId = is_array($payload) ? (int) ($payload['order_id'] ?? 0) : 0;
        if ($orderId > 0) {
            $normalized = $this->opencart->fetchOrderById($orderId);
            if ($normalized !== null) {
                $this->orderSync->ingestWebhookOrder($marketplaceId, $normalized);
                $this->logger->info('OpenCart webhook işlendi: #' . ($normalized['marketplace_order_id'] ?? $orderId), 'Webhook', ['store' => $storeId]);
            }
        }

        return [200, 'OK'];
    }

    /** Aktif (çözülen) mağazanın bu pazaryeri için webhook imza anahtarı. */
    private function storeWebhookSecret(int $marketplaceId): string
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            return '';
        }

        $extra = json_decode((string) ($marketplace['extra_config_json'] ?? '{}'), true);

        return is_array($extra) ? (string) ($extra['webhook_secret'] ?? '') : '';
    }

    /** base64(hmac_sha256(gövde, sır)) — Shopify ve WooCommerce aynı biçimi kullanır. */
    private function signatureValid(string $rawBody, string $signature, string $secret): bool
    {
        $signature = trim($signature);
        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    private function respond(int $code, string $body): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
    }
}
