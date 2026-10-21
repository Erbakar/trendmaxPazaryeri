<?php
/**
 * OzyConnect Panel — AiCompetitorService (Modül #5: AI Rakip Analizi)
 *
 * SINIR (beklenti yönetimi): Aktif AI sağlayıcısında web arama aracı YOKTUR ve
 * tüm pazaryerlerinde rakip verisi API'den GÜVENİLİR şekilde gelmez. Bu yüzden v1
 * MANUEL GİRİŞ ile çalışır: merchant kendi ürünü + rakip başlık/fiyatlarını verir,
 * AI fiyat konumlandırma + başlık + strateji önerisi üretir. Veriye dayalı; uydurmasız.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

final class AiCompetitorService
{
    private const MODULE = 'ai_competitor';

    public function __construct(private readonly AiKernel $kernel) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /**
     * @param array{name:string,price:float,features?:string} $own
     * @param array<int,array{title:string,price:float}> $competitors
     * @param array{type?:string,id?:int} $ref
     * @return array{positioning:string,price_analysis:string,suggested_price:string,title_suggestions:array<int,string>,recommendations:array<int,string>}
     */
    public function analyze(array $own, array $competitors, array $ref = []): array
    {
        $ownName = trim((string) ($own['name'] ?? ''));
        if ($ownName === '') {
            throw new \InvalidArgumentException('Kendi ürün adınız gerekli.');
        }
        $competitors = array_values(array_filter($competitors, fn($c) => trim((string) ($c['title'] ?? '')) !== ''));
        if ($competitors === []) {
            throw new \InvalidArgumentException('En az bir rakip ürün (başlık + fiyat) girin.');
        }

        $ownPrice = (float) ($own['price'] ?? 0);
        $lines = "BENİM ÜRÜNÜM:\n- Başlık: \"{$ownName}\"\n- Fiyat: " . ($ownPrice > 0 ? number_format($ownPrice, 2, ',', '.') . ' TL' : 'belirtilmemiş') . "\n";
        if (!empty($own['features'])) {
            $lines .= "- Öne çıkan özellikler: " . trim((string) $own['features']) . "\n";
        }
        $lines .= "\nRAKİPLER:\n";
        foreach ($competitors as $i => $c) {
            $cp = (float) ($c['price'] ?? 0);
            $lines .= ($i + 1) . ". \"" . trim((string) $c['title']) . "\" — " . ($cp > 0 ? number_format($cp, 2, ',', '.') . ' TL' : 'fiyat yok') . "\n";
        }

        $system = 'Sen Türkiye pazaryerleri için uzman bir rekabet ve fiyatlandırma analistisin. '
            . 'Sana verilen "benim ürünüm" ile rakip ürünleri karşılaştır; fiyat konumlandırması, başlık iyileştirmesi ve strateji öner. '
            . 'YALNIZCA verilen verilere dayan; rakip verisi/fiyat uydurma. Yalnızca GEÇERLİ JSON döndür; kod bloğu/``` ekleme. Türkçe yaz.';

        $user = $lines . "\n\nŞu JSON şemasını doldur:\n"
            . '{'
            . '"positioning":"ürünün rakipler arasındaki konumu, 2-3 cümle",'
            . '"price_analysis":"fiyatın rekabetçiliği (pahalı/ucuz/dengeli) ve nedeni, 1-2 cümle",'
            . '"suggested_price":"önerilen satış fiyatı ya da aralık (ör. 249-279 TL) + kısa gerekçe",'
            . '"title_suggestions":["daha iyi 2-3 ürün başlığı önerisi"],'
            . '"recommendations":["uygulanabilir 3-5 rekabet önerisi"]'
            . '}';

        $res = $this->kernel->execute(self::MODULE, self::MODULE, fn(AiClient $ai) => $ai->chat(
            $system,
            [['role' => 'user', 'content' => $user]],
            ['max_tokens' => 900, 'json' => true, 'temperature' => 0.5],
            'default'
        ), $ref);

        $j = $res->json() ?? [];

        $titles = $j['title_suggestions'] ?? [];
        if (is_string($titles)) { $titles = [$titles]; }
        $titles = array_values(array_slice(array_filter(array_map(fn($v) => trim((string) $v), is_array($titles) ? $titles : [])), 0, 5));

        $recs = $j['recommendations'] ?? [];
        if (is_string($recs)) { $recs = [$recs]; }
        $recs = array_values(array_slice(array_filter(array_map(fn($v) => trim((string) $v), is_array($recs) ? $recs : [])), 0, 6));

        return [
            'positioning'       => trim((string) ($j['positioning'] ?? '')),
            'price_analysis'    => trim((string) ($j['price_analysis'] ?? '')),
            'suggested_price'   => trim((string) ($j['suggested_price'] ?? '')),
            'title_suggestions' => $titles,
            'recommendations'   => $recs,
        ];
    }
}
