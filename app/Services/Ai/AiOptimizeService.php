<?php
/**
 * OzyConnect Panel — AiOptimizeService (Modül #4: AI Pazaryeri Optimizasyonu)
 *
 * Ürünün liste kalitesini YEREL olarak puanlar (görsel/başlık/açıklama/özellik/SEO/
 * kategori-marka eşleme/listeleme) → AI'ya somut metrik verir → önceliklendirilmiş
 * iyileştirme önerileri ve "neden satmıyor" değerlendirmesi alır. Veriye dayalı,
 * uydurmaya kapalı. AiKernel üzerinden (erişim + kredi + log).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\MarketplaceProduct;
use App\Models\Product;

final class AiOptimizeService
{
    private const MODULE = 'ai_optimize';

    public function __construct(
        private readonly AiKernel $kernel,
        private readonly Product $products,
        private readonly MarketplaceProduct $mpProducts,
    ) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /**
     * @return array{score:int,metrics:array<string,mixed>,summary:string,issues:array<int,array<string,string>>,quick_wins:array<int,string>}
     */
    public function analyzeProduct(int $productId, array $ref = []): array
    {
        $p = $this->products->find($productId);
        if ($p === false) {
            throw new \InvalidArgumentException('Ürün bulunamadı.');
        }

        $m = $this->metrics($p);

        $context = "Ürün: \"" . mb_substr((string) $p['name'], 0, 120) . "\"\n"
            . "Mevcut durum (otomatik ölçüm):\n"
            . "- Görsel sayısı: {$m['images']}\n"
            . "- Başlık uzunluğu: {$m['title_len']} karakter\n"
            . "- Açıklama uzunluğu: {$m['desc_len']} karakter\n"
            . "- Kısa açıklama: " . ($m['has_short'] ? 'var' : 'YOK') . "\n"
            . "- Teknik özellik sayısı: {$m['specs']}\n"
            . "- SEO başlığı: " . ($m['has_seo_title'] ? 'var' : 'YOK') . ", SEO açıklaması: " . ($m['has_seo_desc'] ? 'var' : 'YOK') . "\n"
            . "- Kategori eşleştirilmiş: " . ($m['category_mapped'] ? 'evet' : 'HAYIR') . ", Marka: " . ($m['brand_mapped'] ? 'evet' : 'HAYIR') . "\n"
            . "- Barkod: " . ($m['has_barcode'] ? 'var' : 'YOK') . "\n"
            . "- Aktarıldığı pazaryeri: {$m['mp_count']}, listelenen: {$m['listed_count']}\n"
            . "- Liste kalite puanı (0-100): {$m['score']}";

        $system = 'Sen Türkiye pazaryerleri (Trendyol, Hepsiburada, N11 vb.) için uzman bir liste optimizasyon danışmanısın. '
            . 'Sana verilen ürün ölçümlerine dayanarak bu ürünün neden yeterince satmadığını ve nasıl iyileştirileceğini değerlendir. '
            . 'Yalnızca verilen ölçümlere dayan; uydurma. Yalnızca GEÇERLİ JSON döndür; kod bloğu/``` ekleme. Türkçe yaz.';

        $user = $context . "\n\nŞu JSON şemasını doldur:\n"
            . '{'
            . '"summary":"2-3 cümlelik genel değerlendirme",'
            . '"issues":[{"title":"sorun başlığı","severity":"yüksek|orta|düşük","suggestion":"somut, uygulanabilir öneri"}],'
            . '"quick_wins":["bugün yapılabilecek 2-4 hızlı iyileştirme"]'
            . '}';

        $res = $this->kernel->execute(self::MODULE, self::MODULE, fn(AiClient $ai) => $ai->chat(
            $system,
            [['role' => 'user', 'content' => $user]],
            ['max_tokens' => 900, 'json' => true, 'temperature' => 0.5],
            'default'
        ), $ref);

        $j = $res->json() ?? [];

        $issues = [];
        foreach (($j['issues'] ?? []) as $it) {
            if (!is_array($it)) { continue; }
            $sev = strtolower(trim((string) ($it['severity'] ?? 'orta')));
            if (!in_array($sev, ['yüksek', 'orta', 'düşük'], true)) { $sev = 'orta'; }
            $issues[] = [
                'title'      => mb_substr(trim((string) ($it['title'] ?? '')), 0, 160),
                'severity'   => $sev,
                'suggestion' => trim((string) ($it['suggestion'] ?? '')),
            ];
            if (count($issues) >= 10) { break; }
        }

        $wins = [];
        foreach (($j['quick_wins'] ?? []) as $w) {
            $w = trim((string) $w);
            if ($w !== '') { $wins[] = $w; }
            if (count($wins) >= 6) { break; }
        }

        return [
            'score'      => $m['score'],
            'metrics'    => $m,
            'summary'    => trim((string) ($j['summary'] ?? '')),
            'issues'     => $issues,
            'quick_wins' => $wins,
        ];
    }

    /**
     * Ürünün liste kalite metrikleri + 0-100 puan (yerel, AI'sız).
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function metrics(array $p): array
    {
        $imgs = json_decode((string) ($p['images_json'] ?? '[]'), true);
        $imgCount = is_array($imgs) ? count($imgs) : 0;
        if ($imgCount === 0 && trim((string) ($p['main_image'] ?? '')) !== '') { $imgCount = 1; }

        $titleLen = mb_strlen(trim((string) ($p['name'] ?? '')));
        $descLen  = mb_strlen(trim((string) ($p['description'] ?? '')));
        $hasShort = trim((string) ($p['short_description'] ?? '')) !== '';

        $specs = json_decode((string) ($p['technical_specs'] ?? ''), true);
        $specsCount = is_array($specs) ? count($specs) : (trim((string) ($p['technical_specs'] ?? '')) !== '' ? 1 : 0);

        $hasSeoTitle = trim((string) ($p['seo_title'] ?? '')) !== '';
        $hasSeoDesc  = trim((string) ($p['seo_description'] ?? '')) !== '';
        $catMapped   = (int) ($p['needs_category_mapping'] ?? 0) === 0 && (int) ($p['category_id'] ?? 0) > 0;
        $brandMapped = (int) ($p['needs_brand_mapping'] ?? 0) === 0 && (int) ($p['brand_id'] ?? 0) > 0;
        $hasBarcode  = trim((string) ($p['barcode'] ?? '')) !== '';

        $mpRows = $this->mpProducts->all(['product_id' => (int) $p['id']], 'id DESC', 50);
        $mpCount = count($mpRows);
        $listedCount = 0;
        foreach ($mpRows as $r) { if ((int) ($r['is_listed'] ?? 0) === 1) { $listedCount++; } }

        // Puanlama (0-100)
        $score = 0;
        $score += $imgCount >= 5 ? 20 : ($imgCount >= 3 ? 14 : ($imgCount >= 1 ? 7 : 0));
        $score += ($titleLen >= 40 && $titleLen <= 80) ? 15 : (($titleLen >= 20 && $titleLen <= 120) ? 9 : ($titleLen > 0 ? 3 : 0));
        $score += $descLen >= 200 ? 15 : ($descLen >= 80 ? 9 : ($descLen > 0 ? 4 : 0));
        $score += $hasShort ? 6 : 0;
        $score += $specsCount >= 5 ? 12 : ($specsCount >= 1 ? 6 : 0);
        $score += $hasSeoTitle ? 6 : 0;
        $score += $hasSeoDesc ? 6 : 0;
        $score += $catMapped ? 6 : 0;
        $score += $brandMapped ? 6 : 0;
        $score += $hasBarcode ? 4 : 0;
        $score += $listedCount >= 1 ? 8 : 0;
        $score = min(100, $score);

        return [
            'images'         => $imgCount,
            'title_len'      => $titleLen,
            'desc_len'       => $descLen,
            'has_short'      => $hasShort,
            'specs'          => $specsCount,
            'has_seo_title'  => $hasSeoTitle,
            'has_seo_desc'   => $hasSeoDesc,
            'category_mapped'=> $catMapped,
            'brand_mapped'   => $brandMapped,
            'has_barcode'    => $hasBarcode,
            'mp_count'       => $mpCount,
            'listed_count'   => $listedCount,
            'score'          => $score,
        ];
    }
}
