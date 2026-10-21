<?php
/**
 * OzyConnect Panel — XmlFeedService
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Feed;

use App\Core\Logger;
use App\Helpers\StringHelper;
use App\Models\Product;
use App\Models\Setting;
use App\Models\XmlFeed;
use App\Services\PriceRuleEngine;

final class XmlFeedService
{
    private readonly string $feedDir;

    public function __construct(
        private readonly Product $productModel,
        private readonly XmlFeed $xmlFeedModel,
        private readonly Setting $settingModel,
        private readonly PriceRuleEngine $priceEngine,
        private readonly Logger $logger,
    ) {
        $this->feedDir = BASE_PATH . '/storage/feeds';
    }

    /**
     * Feed oluştur
     *
     * marketplace_id NULL → mevcut davranış (base_price/list_price direkt kullanılır)
     * marketplace_id set → PriceRuleEngine ile pazaryerine özel fiyat hesaplanır
     */
    public function generate(int $feedId): int
    {
        $feed = $this->xmlFeedModel->findOrFail($feedId);
        $feedType = $feed['feed_type'] ?? 'generic';
        $filters = json_decode($feed['filter_json'] ?? '{}', true) ?: [];
        $marketplaceId = isset($feed['marketplace_id']) ? (int) $feed['marketplace_id'] : 0;

        $products = $this->productModel->getForFeed($filters);

        if (empty($products)) {
            $this->logger->warning("Feed #{$feedId}: Ürün bulunamadı", 'XmlFeed');
            return 0;
        }

        // Marketplace-specific: PriceRuleEngine ile fiyat hesapla
        if ($marketplaceId > 0) {
            $products = $this->applyMarketplacePricing($products, $marketplaceId);
        }

        $products = $this->applyPublicImageUrls($products);

        $generator = match ($feedType) {
            'google_shopping' => new GoogleShoppingFeed(),
            'meta_catalog'    => new MetaCatalogFeed(),
            default           => new GenericFeed(),
        };

        $xml = $generator->generate($products, $this->getSiteSettings());

        // Dosyaya yaz
        $filename = $feed['token'] . '.xml';
        $filePath = $this->feedDir . '/' . $filename;

        if (!is_dir($this->feedDir)) {
            @mkdir($this->feedDir, 0755, true);
        }

        file_put_contents($filePath, $xml, LOCK_EX);

        // DB güncelle
        $count = count($products);
        $this->xmlFeedModel->updateGenerated($feedId, $count);

        $this->logger->info("Feed #{$feedId} üretildi: {$count} ürün" . ($marketplaceId > 0 ? " (marketplace:{$marketplaceId})" : ''), 'XmlFeed');

        return $count;
    }

    /**
     * Tüm aktif feedleri yeniden üret
     */
    public function regenerateAll(): array
    {
        $feeds = $this->xmlFeedModel->getActive();
        $results = [];

        foreach ($feeds as $feed) {
            try {
                $results[(int) $feed['id']] = $this->generate((int) $feed['id']);
            } catch (\Throwable $e) {
                $results[(int) $feed['id']] = -1;
                $this->logger->error("Feed üretim hatası (#{$feed['id']}): " . $e->getMessage(), 'XmlFeed');
            }
        }

        return $results;
    }

    /**
     * Feed dosyasını oku (public serving için)
     */
    public function serveFeed(string $token): ?string
    {
        $feed = $this->xmlFeedModel->findByToken($token);
        if ($feed === false || !(bool) $feed['is_active']) {
            return null;
        }

        $filePath = $this->feedDir . '/' . $token . '.xml';
        if (!file_exists($filePath)) {
            return null;
        }

        // Cache kontrolü
        $cacheHours = (int) ($this->settingModel->get('feed_cache_hours', 4));
        $fileAge = time() - filemtime($filePath);

        if ($fileAge > ($cacheHours * 3600)) {
            // Yeniden üret
            $this->generate((int) $feed['id']);
        }

        return file_get_contents($filePath) ?: null;
    }

    /**
     * Yeni feed kaydı oluştur
     *
     * @param array<string, mixed> $data
     */
    public function createFeed(array $data): int
    {
        $data['token'] = StringHelper::randomToken(32);
        return $this->xmlFeedModel->create($data);
    }

    /**
     * @return array<string, string>
     */
    private function getSiteSettings(): array
    {
        return [
            'site_name'  => (string) $this->settingModel->get('site_name', 'Entegratör Yazılımı'),
            'site_url'   => $this->siteBaseUrl(),
            'currency'   => (string) $this->settingModel->get('currency', 'TRY'),
        ];
    }

    /**
     * Feed'deki site adresi: web isteğinde app_base_url(); CLI'da (cron) app_base_url() betik
     * yolundan yanlış alt dizin türettiği için .env APP_URL. public/index.php yardımcısı
     * app_asset_base_url() bu kuralı (ve yerel APP_URL'de canlı istek adresine düşmeyi) uygular.
     */
    private function siteBaseUrl(): string
    {
        if (function_exists('app_asset_base_url')) {
            return app_asset_base_url();
        }

        if (PHP_SAPI !== 'cli' && function_exists('app_base_url')) {
            return app_base_url();
        }

        return rtrim((string) env('APP_URL', 'http://localhost'), '/');
    }

    /**
     * Feed görsellerini dışarıdan erişilebilir mutlak adrese çevir (göreli / localhost görsel → site kökü).
     * Google/Meta kataloğu localhost ya da göreli görsel adresini çekemez.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function applyPublicImageUrls(array $products): array
    {
        if (!function_exists('app_asset_url')) {
            return $products;
        }

        foreach ($products as &$product) {
            if (!empty($product['main_image'])) {
                $product['main_image'] = app_asset_url((string) $product['main_image']);
            }
        }
        unset($product);

        return $products;
    }

    /**
     * Ürünlere pazaryerine özel fiyat uygula (PriceRuleEngine ile)
     * base_price ve list_price alanları hesaplanan değerlerle değiştirilir.
     * main_image alanı korunur — mevcut görsel mantığı bozulmaz.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function applyMarketplacePricing(array $products, int $marketplaceId): array
    {
        foreach ($products as &$product) {
            try {
                $price = $this->priceEngine->calculatePrice($product, $marketplaceId);
                $product['base_price'] = $price['sale_price'];
                $product['list_price'] = $price['list_price'];
            } catch (\Throwable $e) {
                // Fiyat hesaplama hatası — orijinal fiyatları koru, ürünü atla
                $this->logger->warning(
                    "Feed fiyat hesaplama hatası (ürün #{$product['id']}): " . $e->getMessage(),
                    'XmlFeed'
                );
            }
        }
        unset($product);

        return $products;
    }
}
