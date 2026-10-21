<?php
/**
 * OzyConnect Panel — AiVisionService (Modül #3: AI Görsel Analizi)
 *
 * Ürün görselini (URL ya da yerel dosya) inceleyip ürün türü/kategori/marka/renk/
 * malzeme/özellik + başlık & açıklama önerisi üretir. AiKernel üzerinden çalışır
 * (erişim + kredi + log). Sağlayıcı vision desteklemiyorsa hata AiKernel'de iade edilir.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

final class AiVisionService
{
    private const MODULE = 'ai_vision';
    private const MAX_BYTES = 5_000_000; // 5 MB

    public function __construct(private readonly AiKernel $kernel) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /**
     * @param array{type?:string,id?:int} $ref
     * @return array{product_type:string,category:string,brand:string,color:string,material:string,key_features:array<int,string>,suggested_title:string,suggested_description:string}
     */
    public function analyze(string $imageUrl, array $ref = []): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') {
            throw new \InvalidArgumentException('Görsel adresi boş.');
        }
        [$b64, $mime] = $this->fetchImage($imageUrl);

        $system = 'Sen Türkiye pazaryerleri için uzman bir ürün kataloglama asistanısın. '
            . 'Verilen ürün görselini incele. Yalnızca GEÇERLİ JSON döndür; kod bloğu, ``` veya açıklama ekleme. '
            . 'Türkçe yaz. Emin olmadığın alanı boş bırak; marka/değer uydurma.';

        $prompt = "Bu ürün görselini analiz et ve şu JSON şemasını doldur:\n"
            . '{'
            . '"product_type":"ürünün ne olduğu (ör. spor ayakkabı)",'
            . '"category":"tahmini kategori",'
            . '"brand":"görselde net görünüyorsa marka, yoksa boş",'
            . '"color":"baskın renk(ler)",'
            . '"material":"görünen malzeme, bilinmiyorsa boş",'
            . '"key_features":["öne çıkan 3-6 özellik"],'
            . '"suggested_title":"satışa uygun ürün başlığı, en fazla 60 karakter",'
            . '"suggested_description":"2-3 cümlelik ürün açıklaması"'
            . '}';

        $res = $this->kernel->execute(self::MODULE, self::MODULE, fn(AiClient $ai) => $ai->vision(
            $prompt,
            $b64,
            $mime,
            $system,
            ['max_tokens' => 700, 'json' => true, 'temperature' => 0.4]
        ), $ref);

        $j = $res->json() ?? [];

        $feat = $j['key_features'] ?? [];
        if (is_string($feat)) { $feat = array_filter(array_map('trim', explode(',', $feat))); }
        if (!is_array($feat)) { $feat = []; }
        $feat = array_values(array_slice(array_map(fn($v) => trim((string) $v), $feat), 0, 8));

        return [
            'product_type'          => trim((string) ($j['product_type'] ?? '')),
            'category'              => trim((string) ($j['category'] ?? '')),
            'brand'                 => trim((string) ($j['brand'] ?? '')),
            'color'                 => trim((string) ($j['color'] ?? '')),
            'material'              => trim((string) ($j['material'] ?? '')),
            'key_features'          => $feat,
            'suggested_title'       => mb_substr(trim((string) ($j['suggested_title'] ?? '')), 0, 255),
            'suggested_description' => trim((string) ($j['suggested_description'] ?? '')),
        ];
    }

    /**
     * Görseli indir/oku → [base64, mime]. http(s) URL ya da public altı yerel yol.
     * @return array{0:string,1:string}
     */
    private function fetchImage(string $url): array
    {
        if (preg_match('#^https?://#i', $url)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'OzyConnect-AI/1.0',
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, int $dlTotal, int $dlNow) {
                    return $dlNow > self::MAX_BYTES ? 1 : 0; // boyut aşımında indirmeyi durdur
                },
            ]);
            $bytes = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Görsel indirilemedi: ' . ($err !== '' ? $err : 'boş yanıt'));
            }
        } else {
            $rel = ltrim($url, '/');
            $base = rtrim(BASE_PATH, '\\/');
            $path = $base . '/public/' . $rel;
            if (!is_file($path)) {
                $alt = $base . '/' . $rel;
                if (is_file($alt)) { $path = $alt; }
            }
            if (!is_file($path)) {
                throw new \RuntimeException('Yerel görsel bulunamadı.');
            }
            if ((int) filesize($path) > self::MAX_BYTES) {
                throw new \RuntimeException('Görsel çok büyük (5MB üstü).');
            }
            $bytes = (string) file_get_contents($path);
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('Görsel çok büyük (5MB üstü).');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || empty($info['mime'])) {
            throw new \RuntimeException('Geçerli bir görsel değil.');
        }
        return [base64_encode($bytes), (string) $info['mime']];
    }
}
