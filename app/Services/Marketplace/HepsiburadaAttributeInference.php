<?php
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Services\Catalog\AttributeInference;

/**
 * Hepsiburada zorunlu özelliği için, ürün değeri ve kategori varsayılanı yoksa, ürünün kendi
 * söylediğinden değer bulur. Trendyol'daki AttributeInference kurallarıyla aynı: tahmin yok.
 *
 * Sıra:
 *  1. XML'den gelen aynı adlı ürün özelliği (HB listesindeki yazımıyla eşleşiyorsa)
 *  2. Ürün adında tam kelime olarak geçen TEK seçenek. Marka/model listelerinde ad birden çok
 *     seçenek içeriyorsa ("Xiaomi Redmi 14c") adda ilk geçen alınır: başlıklar cihazla başlar.
 *  3. Menşei → Türkiye (mağaza ürünlerinin Türkiye'de satıldığını beyan etti)
 *  4. Nötr seçenek (Belirtilmemiş / Unisex / Çok Renkli / Diğer). Ölçü, beden, hacim, güç
 *     gibi değerler hiçbir zaman varsayılanla doldurulmaz.
 *
 * Saf sınıf; birim testli.
 */
final class HepsiburadaAttributeInference
{
    /** Bu kelimeyi adında taşıyan özellik nötr seçenekle doldurulmaz (gerçek bir ölçü/miktar ister). */
    private const NO_NEUTRAL = [
        'boy', 'en', 'beden', 'hacim', 'kapasite', 'olcu', 'olculer', 'uzunluk', 'ebat', 'ebatlar', 'boyut', 'boyutlar',
        'agirlik', 'guc', 'watt', 'voltaj', 'cap', 'yukseklik', 'genislik', 'derinlik', 'numara', 'miktar', 'gramaj',
        'inc', 'mah', 'amper', 'litre', 'ml', 'cm', 'mm', 'kg', 'gb', 'tb', 'hz', 'adet', 'sayisi', 'yas',
    ];

    /**
     * Adda birden çok seçenek geçtiğinde ilk geçeni almanın doğru olduğu listeler (cihaz markası/modeli).
     * Kelime başı olarak aranır: HB "Telefon Modeli", "Uyumlu Marka" yazar.
     */
    private const FIRST_HIT = ['marka', 'model', 'uyumlu'];

    /** Ürün adında yoksa HB kategori yolundan da okunabilen özellikler ("… > Kadın > Çanta"). */
    private const FROM_PATH = ['cinsiyet'];

    /**
     * @param array{id?: string, name: string, type?: string, values?: array<int, string>} $attribute HB zorunlu özelliği
     * @param array<string, string> $localValues XML ürün özellikleri: ad => değer
     * @param array<string, mixed>|null $prepared AttributeInference::prepare() sonucu (büyük listeler için önbellek)
     * @return array{value: string, source: string}|null source: xml | ad | mensei | notr
     */
    public static function infer(array $attribute, string $productName, array $localValues = [], ?array $prepared = null, string $categoryPath = ''): ?array
    {
        $values = array_values(array_filter(array_map(static fn($v) => trim((string) $v), (array) ($attribute['values'] ?? [])), 'strlen'));
        $name = (string) ($attribute['name'] ?? '');
        $isEnum = ($attribute['type'] ?? 'enum') === 'enum' && $values !== [];

        // 1) XML'deki aynı adlı özellik
        $key = TrendyolAttributeResolver::normalize($name);
        foreach ($localValues as $localName => $localValue) {
            if (TrendyolAttributeResolver::normalize((string) $localName) !== $key || trim((string) $localValue) === '') {
                continue;
            }
            if (!$isEnum) {
                return ['value' => trim((string) $localValue), 'source' => 'xml'];
            }
            $canonical = HepsiburadaService::canonicalAttributeValue((string) $localValue, $values);
            if ($canonical !== null) {
                return ['value' => $canonical, 'source' => 'xml'];
            }
        }

        if (!$isEnum) {
            return null; // serbest metin: kullanıcıya bırakılır (renk dahil — HB renkleri listeden ister)
        }

        $shape = self::shape($name, $values);
        $prepared ??= AttributeInference::prepare($shape);

        // 2) Ürün adı
        $hits = AttributeInference::optionsInText($shape, $productName, $prepared);
        if (count($hits) === 1) {
            return ['value' => (string) $hits[0]['name'], 'source' => 'ad'];
        }
        if (count($hits) > 1 && self::takesFirstHit($name)) {
            $first = self::firstInText($hits, $productName);
            if ($first !== null) {
                return ['value' => $first, 'source' => 'ad'];
            }
        }
        if ($hits === [] && $categoryPath !== '' && self::readsPath($name)) {
            $pathHits = AttributeInference::optionsInText($shape, $categoryPath, $prepared);
            if (count($pathHits) === 1) {
                return ['value' => (string) $pathHits[0]['name'], 'source' => 'ad'];
            }
        }
        if (count($hits) > 1) {
            // Adda birden çok değer ("Kırmızı Beyaz"): yalnız "karışık" türünde bir seçenek bunu doğru söyler.
            $mixed = AttributeInference::neutral($shape, false, $prepared);
            return $mixed !== null ? ['value' => $mixed['option'], 'source' => 'ad'] : null;
        }

        // 3) Menşei
        $suggestion = AttributeInference::suggest($shape, [], false, true, $prepared);
        if ($suggestion !== null && ($suggestion['basis'] ?? '') === 'default') {
            return ['value' => $suggestion['option'], 'source' => 'mensei'];
        }

        // 4) Nötr seçenek
        if (self::allowsNeutral($name)) {
            $neutral = AttributeInference::neutral($shape, true, $prepared);
            if ($neutral !== null) {
                return ['value' => $neutral['option'], 'source' => 'notr'];
            }
        }

        return null;
    }

    /** HB değer listesini AttributeInference'ın beklediği biçime çevirir (id = ad). */
    public static function shape(string $name, array $values): array
    {
        return [
            'name'   => $name,
            'values' => array_map(static fn(string $v): array => ['id' => $v, 'name' => $v], array_values($values)),
        ];
    }

    public static function prepare(array $attribute): array
    {
        $values = array_values(array_filter(array_map(static fn($v) => trim((string) $v), (array) ($attribute['values'] ?? [])), 'strlen'));

        return AttributeInference::prepare(self::shape((string) ($attribute['name'] ?? ''), $values));
    }

    public static function allowsNeutral(string $attributeName): bool
    {
        $words = explode(' ', TrendyolAttributeResolver::mapKey($attributeName));
        return array_intersect($words, self::NO_NEUTRAL) === [];
    }

    private static function takesFirstHit(string $attributeName): bool
    {
        return self::hasWordPrefix($attributeName, self::FIRST_HIT);
    }

    private static function readsPath(string $attributeName): bool
    {
        return self::hasWordPrefix($attributeName, self::FROM_PATH);
    }

    /** @param list<string> $prefixes */
    private static function hasWordPrefix(string $text, array $prefixes): bool
    {
        foreach (explode(' ', TrendyolAttributeResolver::mapKey($text)) as $word) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($word, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Adda ilk geçen seçenek. Model listelerinde rakam içeren seçenek ("A26", "14C") genel bir
     * kelimenin ("Galaxy", "Plus") önüne geçer: model adı rakamla ayrışır.
     *
     * @param list<array{id: int|string, name: string}> $hits
     */
    private static function firstInText(array $hits, string $text): ?string
    {
        $hay = ' ' . TrendyolAttributeResolver::mapKey($text) . ' ';
        $best = null;
        $bestRank = null;
        foreach ($hits as $hit) {
            $key = TrendyolAttributeResolver::mapKey((string) $hit['name']);
            $needle = str_ends_with($key, ' uyumlu') ? substr($key, 0, -7) : $key;
            $pos = strpos($hay, ' ' . $needle . ' ');
            if ($pos === false) {
                continue;
            }
            $rank = [preg_match('/\d/', $needle) ? 0 : 1, $pos, -strlen($needle)];
            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = (string) $hit['name'];
            }
        }

        return $best;
    }
}
