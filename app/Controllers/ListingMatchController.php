<?php
/**
 * OzyConnect Panel — ListingMatchController
 * Hepsiburada İlan Eşleştirme sayfası ve JSON uçları (yalnız yerel kayıt; Hepsiburada'ya yazmaz).
 *
 * Kapılar: pazaryeri slug = hepsiburada · 'products' modül yetkisi · mağaza pazaryeri yetkisi (entitlement)
 * · değişikliklerde products.edit izni. Rota parametreleri string gelir (Router strict_types).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-10-09
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Services\AuthorizationService;
use App\Services\Marketplace\HepsiburadaListingMatchService;

final class ListingMatchController extends BaseController
{
    private const MODULE = 'products';
    private const EDIT_PERMISSION = 'products.edit';
    private const NOT_ENTITLED = 'Bu pazaryeri paketinizde bulunmuyor; önce eklenti olarak eklenmeli.';

    private readonly Marketplace $marketplaceModel;
    private readonly HepsiburadaListingMatchService $matchService;
    private readonly AuthorizationService $authorization;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->matchService     = $container->get(HepsiburadaListingMatchService::class);
        $this->authorization    = $container->get(AuthorizationService::class);
    }

    /**
     * GET /marketplaces/{id}/listing-match — sayfa kabuğu (veri /data ile yüklenir)
     */
    public function index(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, false);

        $this->view('marketplaces.listing_match', [
            'pageTitle'   => 'Hepsiburada İlan Eşleştirme',
            'marketplace' => [
                'id'        => (int) $marketplace['id'],
                'name'      => (string) ($marketplace['display_name'] ?? 'Hepsiburada'),
                'connected' => !empty($marketplace['store_connected']),
            ],
            'canEdit'     => $this->authorization->hasPermission(self::EDIT_PERMISSION),
            'thresholds'  => [
                'suggest' => HepsiburadaListingMatchService::SUGGEST_THRESHOLD,
                'bulk'    => HepsiburadaListingMatchService::BULK_THRESHOLD,
            ],
        ]);
    }

    /**
     * GET /marketplaces/{id}/listing-match/data — eşleşme durumu, sayaçlar, karşılıksız kayıtlar
     */
    public function data(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);

        try {
            $this->success($this->matchService->overview((int) $marketplace['id']));
        } catch (\Throwable $e) {
            $this->fail('Eşleştirme verileri okunamadı', $e);
        }
    }

    /**
     * POST /marketplaces/{id}/listing-match/refresh — tek yenileme adımı (step, cursor, round); HB'ye yalnız GET
     */
    public function refresh(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);
        $this->requireEdit();
        if (empty($marketplace['store_connected'])) {
            $this->error('Hepsiburada bağlantısı kurulmamış — önce pazaryeri ayarlarından Mağaza ID ve Servis Anahtarı girin.', 409);
        }

        $this->releaseSession();
        @set_time_limit(120);
        @ignore_user_abort(true);

        $step = (string) preg_replace('/[^a-z]/', '', (string) $request->post('step', ''));
        try {
            $this->success($this->matchService->refreshStep($step, $request->integer('cursor'), $request->integer('round')));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail('Hepsiburada verileri alınamadı', $e, 502);
        }
    }

    /**
     * POST /marketplaces/{id}/listing-match/approve — product_id + hb_sku eşleşmesini kaydet
     */
    public function approve(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);
        $this->requireEdit();

        try {
            $result = $this->matchService->approve(
                (int) $marketplace['id'],
                $request->integer('product_id'),
                (string) $request->post('hb_sku', '')
            );
        } catch (\Throwable $e) {
            $this->fail('Eşleşme kaydedilemedi', $e);
        }

        if (!$result['ok']) {
            $this->error($result['message'], 422);
        }
        $this->success(['transferred' => (int) ($result['transferred'] ?? 0)], $result['message']);
    }

    /**
     * POST /marketplaces/{id}/listing-match/unlink — ürünün HB eşleşmesini kaldır (HB ilanına dokunmaz)
     */
    public function unlink(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);
        $this->requireEdit();

        $productId = $request->integer('product_id');
        if ($productId <= 0) {
            $this->error('Ürün seçilmedi.', 422);
        }

        try {
            $removed = $this->matchService->unlink((int) $marketplace['id'], $productId);
        } catch (\Throwable $e) {
            $this->fail('Eşleşme kaldırılamadı', $e);
        }

        $this->success(['removed' => $removed], $removed > 0 ? 'Eşleşme kaldırıldı.' : 'Kaldırılacak eşleşme yoktu.');
    }

    /**
     * POST /marketplaces/{id}/listing-match/bulk-approve — mode=exact|suggested, threshold (sunucu yeniden hesaplar)
     */
    public function bulkApprove(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);
        $this->requireEdit();

        $this->releaseSession();
        @set_time_limit(300);
        @ignore_user_abort(true);

        $mode = (string) $request->post('mode', HepsiburadaListingMatchService::MODE_EXACT);
        $threshold = $request->integer('threshold', HepsiburadaListingMatchService::BULK_THRESHOLD);

        try {
            $result = $this->matchService->bulkApprove((int) $marketplace['id'], $mode, $threshold);
        } catch (\Throwable $e) {
            $this->fail('Toplu onay tamamlanamadı', $e);
        }

        $message = $result['approved'] . ' eşleşme onaylandı'
            . ($result['failed'] > 0 ? ', ' . $result['failed'] . ' ürün onaylanamadı' : '') . '.';

        $this->json([
            'success' => $result['failed'] === 0,
            'message' => $message,
            'data'    => $result,
        ]);
    }

    /**
     * GET /marketplaces/{id}/listing-match/search — product_id, q ('Değiştir' için HB kayıtları)
     */
    public function search(Request $request, string $id): void
    {
        $marketplace = $this->resolveMarketplace($id, true);

        try {
            $items = $this->matchService->search(
                (int) $marketplace['id'],
                $request->integer('product_id'),
                (string) $request->query('q', '')
            );
        } catch (\Throwable $e) {
            $this->fail('Arama yapılamadı', $e);
        }

        $this->success(['items' => $items]);
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCILAR
    // ═══════════════════════════════════════════════════════

    /**
     * Pazaryeri Hepsiburada mı, modül ve pazaryeri yetkisi var mı? Değilse sayfada yönlendirir, JSON'da 4xx döner.
     *
     * @return array<string, mixed>
     */
    private function resolveMarketplace(string $id, bool $json): array
    {
        $marketplaceId = (int) $id;
        $marketplace = $marketplaceId > 0 ? $this->marketplaceModel->find($marketplaceId) : false;

        if ($marketplace === false || ($marketplace['slug'] ?? '') !== HepsiburadaListingMatchService::SLUG) {
            $message = 'İlan eşleştirme yalnız Hepsiburada için kullanılabilir.';
            if ($json) {
                $this->error($message, 404);
            }
            $this->response->notFound($message);
        }

        if (!$this->authorization->canAccessModule(self::MODULE)) {
            $message = 'Mağazanız bu modülü kullanma yetkisine sahip değil: ' . self::MODULE;
            if ($json) {
                $this->error($message, 403);
            }
            $this->response->forbidden($message);
        }

        if (!$this->authorization->canAccessMarketplace($marketplaceId)) {
            if ($json) {
                $this->error(self::NOT_ENTITLED, 403);
            }
            $this->redirectWithError('/marketplaces', self::NOT_ENTITLED);
        }

        return $marketplace;
    }

    private function requireEdit(): void
    {
        if (!$this->authorization->hasPermission(self::EDIT_PERMISSION)) {
            $this->error('Bu işlem için ürün düzenleme yetkiniz yok.', 403);
        }
    }

    /** Uzun isteklerde panel oturum kilidi diğer sekmeleri bekletmesin. */
    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Hata günlüğe yazılır; kullanıcıya iç SQL ayrıntısı gösterilmez.
     */
    private function fail(string $context, \Throwable $e, int $status = 500): never
    {
        $this->logger->error($context . ': ' . $e->getMessage(), 'ListingMatch');

        if ($e instanceof \PDOException) {
            $message = (string) $e->getCode() === '42S02'
                ? 'İlan önbelleği tablosu bulunamadı — veritabanı güncellemesi (053_hb_listing_cache) uygulanmalı.'
                : $context . ' — veritabanı hatası; ayrıntı sistem günlüğüne yazıldı.';
        } else {
            $message = $context . ': ' . mb_substr($e->getMessage(), 0, 300);
        }

        $this->error($message, $status);
    }
}
