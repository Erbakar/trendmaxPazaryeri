<?php
/**
 * OzyConnect Panel — MarginCalculator
 *
 * Pazaryerine göndermeden ÖNCE ürün başına kâr/marj hesabı.
 *
 * DİKKAT: PriceRuleEngine fiyatı maliyetin altına düşürmüyor, ancak bu koruma
 * KDV'yi ve pazaryeri komisyonunu HESABA KATMAZ. Satış fiyatı maliyete eşit olan
 * bir ürün, KDV + komisyon sonrası kesin zarardır. Bu sınıf o satırları işaretler.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-26
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Publish;

use App\Enums\PriceRuleType;
use App\Models\Product;
use App\Services\PriceRuleEngine;

final class MarginCalculator
{
    public function __construct(
        private readonly PriceRuleEngine $priceEngine,
        private readonly Product $productModel,
    ) {}

    /**
     * Ürün başına kâr satırları.
     *
     * @param array<int, int>      $productIds
     * @param array<string, mixed> $assumptions commission_pct, shipping_cost, packaging_cost
     * @param array<string, mixed> $override    price_rule_type + price_rule_value (geçici simülasyon)
     * @return array<int, array<string, mixed>>
     */
    public function rows(array $productIds, int $marketplaceId, array $assumptions = [], array $override = []): array
    {
        if ($productIds === []) {
            return [];
        }

        $komisyon = max(0.0, min(100.0, (float) ($assumptions['commission_pct'] ?? 0)));
        $kargo    = max(0.0, (float) ($assumptions['shipping_cost'] ?? 0));
        $paket    = max(0.0, (float) ($assumptions['packaging_cost'] ?? 0));

        $urunler = $this->productModel->getByIds($productIds);
        $urunHaritasi = [];
        foreach ($urunler as $u) {
            $urunHaritasi[(int) $u['id']] = $u;
        }

        // Kural motorunun hesabı (mağaza kuralları + pazaryeri override'ı)
        $fiyatlar = [];
        foreach ($this->priceEngine->previewPrices($productIds, $marketplaceId) as $satir) {
            $fiyatlar[(int) $satir['product_id']] = $satir;
        }

        $kuralTipi  = trim((string) ($override['price_rule_type'] ?? ''));
        $kuralDeger = (float) ($override['price_rule_value'] ?? 0);

        $satirlar = [];

        foreach ($productIds as $pid) {
            $pid = (int) $pid;
            $urun = $urunHaritasi[$pid] ?? null;
            if ($urun === null) {
                continue;
            }

            $baz = (float) ($urun['base_price'] ?? 0);

            // Geçici kural verildiyse DB'ye yazmadan simüle et
            if ($kuralTipi !== '' && $kuralTipi !== 'none') {
                $satis = $this->applyRule($baz, $kuralTipi, $kuralDeger);
                $liste = max((float) ($urun['list_price'] ?? 0), $satis);
                $kural = $kuralTipi . ' (' . $kuralDeger . ')';
            } else {
                $hesap = $fiyatlar[$pid] ?? null;
                $satis = $hesap !== null ? (float) $hesap['sale_price'] : $baz;
                $liste = $hesap !== null ? (float) $hesap['list_price'] : $baz;
                $kural = (string) ($hesap['rule'] ?? 'yok');
            }

            $kdv     = (float) ($urun['tax_rate'] ?? 20);
            $maliyet = (float) ($urun['cost_price'] ?? 0);

            // KDV hariç net gelir
            $net = $kdv > 0 ? $satis / (1 + ($kdv / 100)) : $satis;
            $komisyonTutar = $satis * ($komisyon / 100);
            $kar = $net - $maliyet - $komisyonTutar - $kargo - $paket;
            $marj = $satis > 0 ? ($kar / $satis) * 100 : 0.0;

            $uyari = null;
            if ($maliyet <= 0) {
                $uyari = 'maliyet_yok';
            } elseif ($kar <= 0) {
                $uyari = 'zarar';
            } elseif ($marj < 5) {
                $uyari = 'dusuk';
            }

            $satirlar[] = [
                'product_id' => $pid,
                'sku'        => (string) ($urun['sku'] ?? ''),
                'name'       => (string) ($urun['name'] ?? ''),
                'base_price' => round($baz, 2),
                'sale_price' => round($satis, 2),
                'list_price' => round($liste, 2),
                'cost_price' => round($maliyet, 2),
                'tax_rate'   => $kdv,
                'net'        => round($net, 2),
                'commission' => round($komisyonTutar, 2),
                'profit'     => round($kar, 2),
                'margin_pct' => round($marj, 1),
                'rule'       => $kural,
                'warning'    => $uyari,
            ];
        }

        return $satirlar;
    }

    /**
     * Seçimin tamamı için özet.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function summary(array $rows): array
    {
        $ciro = 0.0;
        $kar = 0.0;
        $zarar = 0;
        $maliyetsiz = 0;

        foreach ($rows as $satir) {
            $ciro += (float) $satir['sale_price'];
            $kar  += (float) $satir['profit'];
            if ($satir['warning'] === 'zarar') {
                $zarar++;
            }
            if ($satir['warning'] === 'maliyet_yok') {
                $maliyetsiz++;
            }
        }

        return [
            'count'          => count($rows),
            'revenue'        => round($ciro, 2),
            'profit'         => round($kar, 2),
            'avg_margin_pct' => $ciro > 0 ? round(($kar / $ciro) * 100, 1) : 0.0,
            'loss_count'     => $zarar,
            'no_cost_count'  => $maliyetsiz,
        ];
    }

    /**
     * Geçici kural uygulaması — DB'ye yazmadan "ne olurdu" simülasyonu.
     */
    private function applyRule(float $basePrice, string $type, float $value): float
    {
        $tip = PriceRuleType::tryFrom($type);
        if ($tip === null) {
            return $basePrice;
        }

        return max(0.0, round($tip->apply($basePrice, $value), 2));
    }
}
