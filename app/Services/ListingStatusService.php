<?php
/**
 * OzyConnect Panel — ListingStatusService (Pazaryerinde satışa aç/kapat)
 *
 * Panelde bir ürün pasife alındığında bu SADECE yerel veritabanını
 * güncelliyordu; pazaryerindeki ilan satışta kalmaya devam ediyordu.
 * Bu servis, durumu bağlı pazaryerlerine gerçekten iletir.
 *
 * Pazaryeri yetenekleri:
 *  - Servis setListingStatus(items, active) sunuyorsa o kullanılır
 *    (n11: product-update status Active/Suspended,
 *     Hepsiburada: listing activate/deactivate, olmazsa stok 0).
 *  - Sunmuyorsa: stok 0 = satışa kapalı, gerçek stok = satışta
 *    (Trendyol dahil pazaryerlerinin standart yöntemi).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Logger;
use App\Models\MarketplaceProduct;
use App\Models\Product;

final class ListingStatusService
{
    /** @var array<string, class-string> */
    private const SERVICE_MAP = [
        'trendyol'    => \App\Services\Marketplace\TrendyolService::class,
        'n11'         => \App\Services\Marketplace\N11Service::class,
        'hepsiburada' => \App\Services\Marketplace\HepsiburadaService::class,
        'pazarama'    => \App\Services\Marketplace\PazaramaService::class,
        'pttavm'      => \App\Services\Marketplace\PttavmService::class,
        'ciceksepeti' => \App\Services\Marketplace\CiceksepetiService::class,
        'idefix'      => \App\Services\Marketplace\IdefixService::class,
        'amazon'      => \App\Services\Marketplace\AmazonService::class,
        'oziygo'      => \App\Services\Marketplace\OziygoService::class,
        'shopify'     => \App\Services\Marketplace\ShopifyService::class,
        'woocommerce' => \App\Services\Marketplace\WooCommerceService::class,
        'opencart'    => \App\Services\Marketplace\OpenCartService::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly Product $productModel,
        private readonly Logger $logger,
    ) {}

    /**
     * Ürünü bağlı pazaryerlerinde satışa aç / satıştan kaldır.
     *
     * @param array<int>|null $onlyMarketplaceIds null = tüm bağlı pazaryerleri
     * @return array<int, array{marketplace_id: int, slug: string, display_name: string, status: string, message: string}>
     */
    public function apply(int $productId, bool $active, ?array $onlyMarketplaceIds = null): array
    {
        $product = $this->productModel->find($productId);
        if ($product === false) {
            return [];
        }

        $mpDetails = $this->mpProductModel->getMarketplaceDetailsForProducts([$productId]);
        $mpList    = $mpDetails[$productId] ?? [];
        $results   = [];

        foreach ($mpList as $mp) {
            $mpId        = (int) ($mp['marketplace_id'] ?? 0);
            $slug        = (string) ($mp['slug'] ?? '');
            $displayName = (string) ($mp['display_name'] ?? $slug);

            if ($onlyMarketplaceIds !== null && !in_array($mpId, $onlyMarketplaceIds, true)) {
                continue;
            }

            // Pazaryerinde hiç yayınlanmamış ürün için yapacak bir şey yok
            if ((int) ($mp['is_listed'] ?? 0) !== 1) {
                continue;
            }

            $results[] = $this->applyToMarketplace($product, $mp, $mpId, $slug, $displayName, $active);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $mp
     * @return array{marketplace_id: int, slug: string, display_name: string, status: string, message: string}
     */
    private function applyToMarketplace(
        array $product,
        array $mp,
        int $mpId,
        string $slug,
        string $displayName,
        bool $active
    ): array {
        $item = [
            'hbSku'                  => (string) ($mp['marketplace_product_id'] ?? ''),
            'marketplace_product_id' => (string) ($mp['marketplace_product_id'] ?? ''),
            'stockCode'              => (string) ($mp['marketplace_sku'] ?? ''),
            'sku'                    => (string) ($mp['marketplace_sku'] ?? $product['sku'] ?? ''),
            'barcode'                => (string) ($mp['marketplace_barcode'] ?? $product['barcode'] ?? ''),
            'quantity'               => max(0, (int) ($product['stock_qty'] ?? 0) - (int) ($mp['stock_offset'] ?? 0)),
        ];

        try {
            $service = $this->resolveService($slug);
            if ($service === null) {
                return $this->result($mpId, $slug, $displayName, 'error', 'Pazaryeri servisi bulunamadı');
            }

            if (method_exists($service, 'setListingStatus')) {
                $apiResult = $service->setListingStatus([$item], $active);
                $method    = (string) ($apiResult['method'] ?? 'api');
            } else {
                // Ortak yöntem: stok 0 = satışa kapalı
                if ($active && $item['quantity'] <= 0) {
                    return $this->result(
                        $mpId,
                        $slug,
                        $displayName,
                        'error',
                        'Satışa açmak için stok 0 olamaz — önce panelden stok girin'
                    );
                }

                // Satıştan kaldırmada miktar 0 gönderilir ($item + [...] mevcut 'quantity'yi ezmiyordu)
                $apiResult = $service->updateStock([
                    ['quantity' => $active ? $item['quantity'] : 0] + $item,
                ]);
                $method = 'stok';
            }

            $ok = ((int) ($apiResult['success'] ?? 0)) > 0 && ((int) ($apiResult['failed'] ?? 0)) === 0;

            if ($ok) {
                $this->mpProductModel->updateByProductAndMarketplace(
                    (int) $product['id'],
                    $mpId,
                    [
                        'is_active'      => $active ? 1 : 0,
                        'sync_error'     => null,
                        'last_synced_at' => date('Y-m-d H:i:s'),
                    ]
                );

                $this->logger->info(
                    ($active ? 'Satışa açıldı' : 'Satıştan kaldırıldı')
                    . ": ürün #{$product['id']} / {$slug} ({$method})",
                    'ListingStatus'
                );

                return $this->result(
                    $mpId,
                    $slug,
                    $displayName,
                    'success',
                    ($active ? 'Satışa açıldı' : 'Satışa kapatıldı') . " ({$method})"
                );
            }

            $message = $this->formatErrors($apiResult['errors'] ?? []);

            $this->mpProductModel->updateByProductAndMarketplace(
                (int) $product['id'],
                $mpId,
                [
                    'sync_error'     => mb_substr($message, 0, 500),
                    'last_synced_at' => date('Y-m-d H:i:s'),
                ]
            );

            return $this->result($mpId, $slug, $displayName, 'error', $message);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Listeleme durumu değiştirilemedi ({$slug}): " . $e->getMessage(),
                'ListingStatus'
            );

            return $this->result($mpId, $slug, $displayName, 'error', $e->getMessage());
        }
    }

    private function resolveService(string $slug): ?object
    {
        $class = self::SERVICE_MAP[$slug] ?? null;
        if ($class === null) {
            return null;
        }

        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->error("Pazaryeri servisi yüklenemedi ({$slug}): " . $e->getMessage(), 'ListingStatus');
            return null;
        }
    }

    /**
     * @param array<int, mixed> $errors
     */
    private function formatErrors(array $errors): string
    {
        $messages = [];
        foreach ($errors as $error) {
            if (is_string($error)) {
                $messages[] = $error;
            } elseif (is_array($error)) {
                $messages[] = (string) ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE));
            }
        }

        $messages = array_values(array_filter(array_unique($messages)));

        return $messages === []
            ? 'Pazaryeri durumu değiştirmedi (ayrıntı bildirilmedi)'
            : implode(' | ', array_slice($messages, 0, 3));
    }

    /**
     * @return array{marketplace_id: int, slug: string, display_name: string, status: string, message: string}
     */
    private function result(int $mpId, string $slug, string $displayName, string $status, string $message): array
    {
        return [
            'marketplace_id' => $mpId,
            'slug'           => $slug,
            'display_name'   => $displayName,
            'status'         => $status,
            'message'        => $message,
        ];
    }
}
