<?php
/**
 * OzyConnect Panel — AiContentService (Modül #1: AI Ürün İçerik Asistanı)
 *
 * Ürün adı (+ kategori/marka/özellik) → SEO başlığı, meta açıklama, ürün açıklaması,
 * kısa açıklama, teknik özellik tablosu ve anahtar kelimeler üretir. AiKernel üzerinden
 * çalışır (erişim + kredi + kullanım logu otomatik).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

final class AiContentService
{
    private const MODULE = 'ai_content';

    public function __construct(private readonly AiKernel $kernel) {}

    /** UI buton durumu (açık + yetkili + yeterli kredi). */
    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /**
     * @param array{type?:string,id?:int} $ref  Kredi/log referansı (ör. ['type'=>'product','id'=>5])
     * @param string $moduleKey  Hangi modül/kredi kalemi (tekil=ai_content, toplu=ai_bulk)
     * @return array{seo_title:string,seo_description:string,description:string,short_description:string,technical_specs:string,keywords:string}
     */
    public function generate(string $name, ?string $category = null, ?string $brand = null, array $extra = [], array $ref = [], string $moduleKey = self::MODULE, string $action = self::MODULE): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Ürün adı boş olamaz.');
        }

        $system = 'Sen Türkiye pazaryerleri (Trendyol, Hepsiburada vb.) için uzman bir ürün içerik ve SEO yazarısın. '
            . 'Yalnızca GEÇERLİ JSON döndür; kod bloğu, ```), açıklama veya başka hiçbir metin ekleme. '
            . 'Tüm metinler akıcı, doğal Türkçe olsun; uydurma teknik değer verme, emin olmadığın özelliği yazma.';

        $ctx = "Ürün adı: \"{$name}\".";
        if ($category) { $ctx .= "\nKategori: {$category}."; }
        if ($brand)    { $ctx .= "\nMarka: {$brand}."; }
        if (!empty($extra['notes'])) { $ctx .= "\nEk bilgi: " . trim((string) $extra['notes']) . '.'; }

        $user = $ctx . "\n\nŞu JSON şemasını doldur:\n"
            . '{'
            . '"seo_title": "SEO başlığı, en fazla 60 karakter, marka+model+öne çıkan özellik",'
            . '"seo_description": "meta açıklama, 120-160 karakter, aksiyona çağıran",'
            . '"description": "satışa yönelik ürün açıklaması, 3-5 cümle, faydaları anlatan",'
            . '"short_description": "kısa özet, en fazla 160 karakter",'
            . '"technical_specs": { "Özellik Adı": "Değer", "...": "..." },'
            . '"keywords": "virgülle ayrılmış 5-8 arama anahtar kelimesi"'
            . '}';

        $res = $this->kernel->execute($moduleKey, $action, fn(AiClient $ai) => $ai->chat(
            $system,
            [['role' => 'user', 'content' => $user]],
            ['max_tokens' => 900, 'json' => true, 'temperature' => 0.6],
            'default'
        ), $ref);

        $j = $res->json() ?? [];

        $specs = $j['technical_specs'] ?? null;
        $specsJson = '';
        if (is_array($specs) && $specs !== []) {
            $specsJson = json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
        }
        $keywords = $j['keywords'] ?? '';
        if (is_array($keywords)) { $keywords = implode(', ', $keywords); }

        return [
            'seo_title'         => mb_substr(trim((string) ($j['seo_title'] ?? '')), 0, 255),
            'seo_description'   => trim((string) ($j['seo_description'] ?? '')),
            'description'       => trim((string) ($j['description'] ?? '')),
            'short_description' => mb_substr(trim((string) ($j['short_description'] ?? '')), 0, 1000),
            'technical_specs'   => $specsJson,
            'keywords'          => trim((string) $keywords),
        ];
    }
}
