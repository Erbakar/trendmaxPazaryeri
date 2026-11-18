<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Services\Marketplace\TrendyolAttributeResolver;

/**
 * Finds the Trendyol option of a required attribute in what the product already says about itself
 * (its name, or an XML value that has no option of its own). Pure and unit tested.
 *
 * It never guesses: an option is taken only when its full name stands in the text as whole words and
 * no other option competes. When the text says nothing, the caller may allow a neutral option
 * ("Çok Renkli", "Unisex", "Belirtilmemiş") — a true statement about an unknown value, unlike a
 * random pick. Free-text attributes are left to the user.
 */
final class AttributeInference
{
    /** Option names too generic to mean anything inside a product name. */
    private const STOP = ['var', 'yok', 'evet', 'hayir', 'diger', 'standart', 'normal', 'yeni', 'tek', 'cok', 'set', 'adet'];
    /** Says "unknown" and nothing else; preferred whenever the text is silent. */
    private const UNKNOWN = ['belirtilmemis', 'bilinmiyor'];
    /** Options that truthfully cover several or any value, by a word of the attribute name. */
    private const MIXED = [
        'renk' => ['cok renkli', 'karisik renkli', 'karisik'],
        'color' => ['cok renkli', 'karisik renkli', 'karisik', 'multicolor'],
        'cinsiyet' => ['unisex'],
    ];
    /** Names of the Türkiye option in an origin list. */
    private const ORIGIN_TR = ['tr', 'turkiye', 'turkiye tr', 'tr turkiye'];
    /** Last resort for a silent text. */
    private const OTHER = ['diger'];
    /** Colour words for the free-text colour field: key as mapKey() writes it => display name. */
    private const COLOURS = [
        'beyaz' => 'Beyaz', 'siyah' => 'Siyah', 'kirmizi' => 'Kırmızı', 'mavi' => 'Mavi', 'yesil' => 'Yeşil', 'sari' => 'Sarı',
        'turuncu' => 'Turuncu', 'mor' => 'Mor', 'pembe' => 'Pembe', 'gri' => 'Gri', 'kahverengi' => 'Kahverengi', 'lacivert' => 'Lacivert',
        'bej' => 'Bej', 'bordo' => 'Bordo', 'turkuaz' => 'Turkuaz', 'fusya' => 'Fuşya', 'krem' => 'Krem', 'lila' => 'Lila', 'haki' => 'Haki',
        'ekru' => 'Ekru', 'antrasit' => 'Antrasit', 'fume' => 'Füme', 'seffaf' => 'Şeffaf', 'gumus' => 'Gümüş', 'altin' => 'Altın',
    ];

    /**
     * @param list<string> $texts most specific first (unmatched XML value, then product name)
     * @param bool $originTr the store states its products are made in Türkiye: an empty origin becomes TR
     * @return array{value:string,option:string,basis:string}|null basis: text | text_multi | neutral | default
     */
    public static function suggest(array $attribute, array $texts, bool $allowNeutral, bool $originTr = false, ?array $prepared = null): ?array
    {
        $prepared ??= self::prepare($attribute);
        if ($originTr && self::isOrigin($attribute)) return self::originTr($attribute, $prepared);
        if (!empty($attribute['allowCustom'])) return self::isColour($attribute) ? self::colourText($texts, $allowNeutral) : null;
        if (empty($attribute['values'])) return null;
        foreach ($texts as $text) {
            $found = self::optionsInText($attribute, (string)$text, $prepared);
            if (count($found) === 1) return ['value' => (string)$found[0]['id'], 'option' => (string)$found[0]['name'], 'basis' => 'text'];
            if (count($found) > 1 && ($first = self::firstCompatible($found, (string)$text)) !== null) {
                return ['value' => (string)$first['id'], 'option' => (string)$first['name'], 'basis' => 'text'];
            }
            if (count($found) > 1) {
                // Several different values in one text ("Kırmızı Beyaz"): only a "mixed" option states that truthfully.
                $mixed = self::neutral($attribute, false, $prepared);
                return $mixed ? $mixed + ['basis' => 'text_multi'] : null;
            }
        }
        $neutral = $allowNeutral ? self::neutral($attribute, true, $prepared) : null;
        return $neutral ? $neutral + ['basis' => 'neutral'] : null;
    }

    /** @return list<array{id:int|string,name:string}> options named in the text, longest phrase wins over its parts */
    public static function optionsInText(array $attribute, string $text, ?array $prepared = null): array
    {
        $name = TrendyolAttributeResolver::mapKey($text);
        if ($name === '') return [];
        $hay = ' '.$name.' ';
        $index = ($prepared ?? self::prepare($attribute))['index'];
        $hits = [];
        // Only options that start with a word of the text can be in it.
        foreach (array_unique(explode(' ', $name)) as $word) foreach ($index[$word] ?? [] as $key => $option) {
            if (str_contains($hay, ' '.$key.' ')) $hits[$key] = $option;
        }
        foreach (array_keys($hits) as $short) foreach (array_keys($hits) as $long) {
            if ($short !== $long && str_contains(' '.$long.' ', ' '.$short.' ')) { unset($hits[$short]); break; }
        }
        // Two spellings of one option id are one hit.
        $byId = [];
        foreach ($hits as $hit) $byId[(string)$hit['id']] = $hit;
        return array_values($byId);
    }

    /** Sub-brand => brand, as phone titles name them together ("Xiaomi Redmi Note 9", "Xiaomi Poco X6"). */
    private const SUB_BRANDS = ['redmi' => 'xiaomi', 'poco' => 'xiaomi'];

    /**
     * Compatibility lists hold a brand and its sub-brands ("Xiaomi Uyumlu", "Redmi Uyumlu", "POCO Uyumlu").
     * When the hits are exactly one brand plus its own sub-brands, the device is that brand's and the
     * brand option is true. Two unrelated brands ("Apple Realme Kılıf Seti") stay open.
     * @param list<array{id:int|string,name:string}> $found
     * @return array{id:int|string,name:string}|null
     */
    private static function firstCompatible(array $found, string $text): ?array
    {
        $parent = null; $stems = [];
        foreach ($found as $option) {
            $key = TrendyolAttributeResolver::mapKey($option['name']);
            if (!str_ends_with($key, ' uyumlu')) return null;
            $stems[substr($key, 0, -7)] = $option;
        }
        foreach ($stems as $stem => $option) {
            if (isset(self::SUB_BRANDS[$stem])) continue;
            if ($parent !== null) return null; // two top-level brands
            $parent = $stem;
        }
        if ($parent === null) return null;
        foreach (array_keys($stems) as $stem) if ($stem !== $parent && (self::SUB_BRANDS[$stem] ?? null) !== $parent) return null;
        return $stems[$parent];
    }

    /**
     * One pass over an attribute's options: matchable options by their first word, and the few
     * options looked up by name (neutral ones, Türkiye). Compatible-model lists hold tens of
     * thousands of options, so a caller working through a category prepares each attribute once
     * and passes the result to suggest().
     * @return array{index:array<string,array<string,array{id:int|string,name:string}>>,named:array<string,array{value:string,option:string}>}
     */
    public static function prepare(array $attribute): array
    {
        $wanted = array_flip(array_merge(self::UNKNOWN, self::OTHER, self::ORIGIN_TR, ...array_values(self::MIXED)));
        $index = []; $named = [];
        foreach ($attribute['values'] ?? [] as $option) {
            $key = TrendyolAttributeResolver::mapKey((string)($option['name'] ?? ''));
            if (isset($wanted[$key]) && !isset($named[$key])) $named[$key] = ['value' => (string)$option['id'], 'option' => (string)$option['name']];
            // A bare number ("3", "12") or a one/two letter code ("M", "XL") matches unrelated words and counts.
            if ($key === '' || mb_strlen($key) < 3 || ctype_digit(str_replace(' ', '', $key)) || in_array($key, self::STOP, true)) continue;
            $index[explode(' ', $key)[0]][$key] = ['id' => $option['id'], 'name' => (string)$option['name']];
            // Compatibility lists name options "Apple Uyumlu"; a title says "iPhone 13 Kılıf ... Apple" or
            // "Realme 7 Pro ...". The brand alone stands for its "... Uyumlu" option.
            if (str_ends_with($key, ' uyumlu')) {
                $stem = substr($key, 0, -7);
                if (mb_strlen($stem) >= 3 && !ctype_digit(str_replace(' ', '', $stem)) && !in_array($stem, self::STOP, true)) {
                    $index[explode(' ', $stem)[0]][$stem] ??= ['id' => $option['id'], 'name' => (string)$option['name']];
                }
            }
        }
        return ['index' => $index, 'named' => $named];
    }

    /**
     * @param bool $silent true: the text says nothing (an "unknown" option fits best);
     *                     false: the text names several values (only a "mixed" option fits)
     * @return array{value:string,option:string}|null
     */
    public static function neutral(array $attribute, bool $silent, ?array $prepared = null): ?array
    {
        $name = ' '.TrendyolAttributeResolver::mapKey((string)($attribute['name'] ?? '')).' ';
        $mixed = [];
        foreach (self::MIXED as $word => $names) if (str_contains($name, ' '.$word.' ')) $mixed = array_merge($mixed, $names);
        $wanted = $silent ? array_merge(self::UNKNOWN, $mixed, self::OTHER) : $mixed;
        $named = ($prepared ?? self::prepare($attribute))['named'];
        foreach ($wanted as $want) if (isset($named[$want])) return $named[$want];
        return null;
    }

    private static function isOrigin(array $attribute): bool
    {
        $name = ' '.TrendyolAttributeResolver::mapKey((string)($attribute['name'] ?? '')).' ';
        return str_contains($name, ' mensei ') || str_contains($name, ' mense ') || str_contains($name, ' uretim yeri ');
    }

    /** The Türkiye option of an origin list (Trendyol lists country codes), or the word itself for free text. */
    private static function originTr(array $attribute, array $prepared): ?array
    {
        if (!empty($attribute['allowCustom'])) return ['value' => 'Türkiye', 'option' => 'Türkiye', 'basis' => 'default'];
        $named = $prepared['named'];
        foreach (self::ORIGIN_TR as $want) if (isset($named[$want])) return $named[$want] + ['basis' => 'default'];
        return null;
    }

    private static function isColour(array $attribute): bool
    {
        $name = ' '.TrendyolAttributeResolver::mapKey((string)($attribute['name'] ?? '')).' ';
        return str_contains($name, ' renk ') || str_contains($name, ' color ');
    }

    /** Free-text colour: the colour word(s) of the text; never an invented colour. */
    private static function colourText(array $texts, bool $allowNeutral): ?array
    {
        foreach ($texts as $text) {
            $hay = ' '.TrendyolAttributeResolver::mapKey((string)$text).' ';
            $found = [];
            foreach (self::COLOURS as $key => $label) if (str_contains($hay, ' '.$key.' ')) $found[] = $label;
            if (count($found) === 1) return ['value' => $found[0], 'option' => $found[0], 'basis' => 'text'];
            if (count($found) > 1) return ['value' => 'Çok Renkli', 'option' => 'Çok Renkli', 'basis' => 'text_multi'];
        }
        return $allowNeutral ? ['value' => 'Belirtilmemiş', 'option' => 'Belirtilmemiş', 'basis' => 'neutral'] : null;
    }
}
