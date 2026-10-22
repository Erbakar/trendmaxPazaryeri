<?php
/**
 * OzyConnect Panel — PriceRuleEngine
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

namespace App\Services;

use App\Core\Logger;
use App\Enums\PriceRuleType;
use App\Models\MarketplaceProduct;
use App\Models\PriceRule;
use App\Models\Product;

final class PriceRuleEngine
{
    public function __construct(
        private readonly PriceRule $priceRuleModel,
        private readonly MarketplaceProduct $mpProductModel,
        private readonly Product $productModel,
        private readonly Logger $logger,
        private readonly \App\Services\Import\XmlProfileSettings $profileSettings,
    ) {}

    /**
     * Ürünün belirli pazaryerine gönderilecek fiyatını hesapla
     *
     * Kural hiyerarşisi (en spesifik önce):
     * 1. marketplace_products.price_rule_type (ürün bazlı kural)
     * 2. price_rules: apply_to='product'
     * 3. price_rules: apply_to='category'
     * 4. price_rules: apply_to='all'
     * 5. Kural yoksa: base_price direkt
     *
     * İŞ KURALI: min_price ve max_price sınırları SADECE price_rules tablosundan
     * gelen kurallara (adım 2-4) uygulanır. marketplace_products üzerindeki ürün
     * bazlı override'lara (adım 1) uygulanmaz. Bu kasıtlıdır — ürün bazlı
     * override'lar tam kontrol sağlar, genel kurallar ise sınırlandırılabilir.
     *
     * @param array<string, mixed> $product products tablosundan gelen veri
     * @return array{sale_price: float, list_price: float, rule_applied: string}
     */
    public function calculatePrice(array $product, int $marketplaceId): array
    {
        $basePrice = (float) ($product['base_price'] ?? 0);
        $listPrice = (float) ($product['list_price'] ?? $basePrice);
        $costPrice = (float) ($product['cost_price'] ?? 0);
        $productId = (int) ($product['id'] ?? 0);
        $categoryId = isset($product['category_id']) ? (int) $product['category_id'] : null;

        $ruleApplied = 'none';
        $calculatedPrice = $basePrice;

        // ─── 1. Ürün bazlı marketplace kural ──────────────
        $mpProduct = $this->mpProductModel->findByProductAndMarketplace($productId, $marketplaceId);
        if ($mpProduct !== false) {
            $ruleType = PriceRuleType::tryFrom($mpProduct['price_rule_type'] ?? 'none');

            if ($ruleType !== null && $ruleType !== PriceRuleType::NONE) {
                $calculatedPrice = $ruleType->apply($basePrice, (float) $mpProduct['price_rule_value']);
                $ruleApplied = "marketplace_product:{$ruleType->value}:{$mpProduct['price_rule_value']}";
            }
        }

        // A saved XML profit rule must also apply to API/worker paths, not only UI prefill.
        $profileId = (int)($product['xml_profile_id'] ?? 0);
        if ($ruleApplied === 'none' && $profileId > 0) {
            $profit = $this->profileSettings->forProfile($profileId);
            if ((float)$profit['kar_degeri'] > 0) {
                $type = $profit['kar_tipi'] === 'tutar' ? PriceRuleType::FIXED_INCREASE : PriceRuleType::PERCENT_INCREASE;
                $calculatedPrice = $type->apply($costPrice > 0 ? $costPrice : $basePrice, (float)$profit['kar_degeri']);
                $ruleApplied = 'xml_profile:' . $profileId . ':' . $type->value . ':' . $profit['kar_degeri'];
            }
        }

        // ─── 2-4. Genel fiyat kuralları (eğer ürün bazlı kural yoksa) ──
        if ($ruleApplied === 'none') {
            $rule = $this->priceRuleModel->findBestRule($marketplaceId, $productId, $categoryId);

            if ($rule !== false) {
                $direction = $rule['direction'] ?? 'increase';
                $ruleType = $rule['rule_type'] ?? 'percent';
                $value = (float) ($rule['value'] ?? 0);

                $priceRuleType = PriceRuleType::tryFrom("{$ruleType}_{$direction}");

                if ($priceRuleType !== null) {
                    $calculatedPrice = $priceRuleType->apply($basePrice, $value);
                    $ruleApplied = "price_rule:{$rule['id']}:{$rule['apply_to']}:{$priceRuleType->value}:{$value}";
                }

                // ─── min_price / max_price sınırları (kural bazlı) ──
                $minPrice = isset($rule['min_price']) && $rule['min_price'] !== null ? (float) $rule['min_price'] : null;
                $maxPrice = isset($rule['max_price']) && $rule['max_price'] !== null ? (float) $rule['max_price'] : null;

                if ($minPrice !== null && $minPrice > 0 && $calculatedPrice < $minPrice) {
                    $this->logger->info(
                        "Fiyat min_price sınırına yükseltildi. Ürün #{$productId}: hesaplanan={$calculatedPrice}, min={$minPrice}",
                        'PriceRuleEngine'
                    );
                    $calculatedPrice = $minPrice;
                    $ruleApplied .= " (min_price:{$minPrice})";
                }

                if ($maxPrice !== null && $maxPrice > 0 && $calculatedPrice > $maxPrice) {
                    $this->logger->info(
                        "Fiyat max_price sınırına düşürüldü. Ürün #{$productId}: hesaplanan={$calculatedPrice}, max={$maxPrice}",
                        'PriceRuleEngine'
                    );
                    $calculatedPrice = $maxPrice;
                    $ruleApplied .= " (max_price:{$maxPrice})";
                }
            }
        }

        // ─── Minimum fiyat kontrolü: maliyet fiyatının altına düşemez ──
        if ($costPrice > 0 && $calculatedPrice < $costPrice) {
            $this->logger->warning(
                "Fiyat maliyet altına düştü! Ürün #{$productId}: hesaplanan={$calculatedPrice}, maliyet={$costPrice}",
                'PriceRuleEngine'
            );
            $calculatedPrice = $costPrice;
            $ruleApplied .= ' (maliyet_siniri)';
        }

        // ─── list_price, sale_price'tan büyük olmalı ──
        $finalListPrice = max($listPrice, $calculatedPrice);

        return [
            'sale_price'   => round($calculatedPrice, 2),
            'list_price'   => round($finalListPrice, 2),
            'rule_applied' => $ruleApplied,
        ];
    }

    /**
     * Tüm ürünler için belirli pazaryerine fiyat hesapla
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array{product_id: int, barcode: string, sale_price: float, list_price: float, rule: string}>
     */
    public function calculateBulk(array $products, int $marketplaceId): array
    {
        $results = [];

        foreach ($products as $product) {
            $price = $this->calculatePrice($product, $marketplaceId);
            $results[] = [
                'product_id' => (int) $product['id'],
                'barcode'    => $product['barcode'] ?? '',
                'sale_price' => $price['sale_price'],
                'list_price' => $price['list_price'],
                'rule'       => $price['rule_applied'],
            ];
        }

        return $results;
    }

    /**
     * Fiyat kuralı önizleme — seçilen ürünler için hesaplanan fiyatları göster
     *
     * @param array<int> $productIds
     * @return array<int, array{product_id: int, name: string, sku: string, base_price: float, sale_price: float, list_price: float, rule: string}>
     */
    public function previewPrices(array $productIds, int $marketplaceId): array
    {
        if (empty($productIds)) {
            return [];
        }

        $products = $this->productModel->getByIds($productIds);

        $results = [];
        foreach ($products as $product) {
            $price = $this->calculatePrice($product, $marketplaceId);
            $results[] = [
                'product_id' => (int) $product['id'],
                'name'       => $product['name'] ?? '',
                'sku'        => $product['sku'] ?? '',
                'base_price' => (float) $product['base_price'],
                'sale_price' => $price['sale_price'],
                'list_price' => $price['list_price'],
                'rule'       => $price['rule_applied'],
            ];
        }

        return $results;
    }
}
