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
     * @return array{value:string,option:string,basis:string}|null basis: text | text_multi | neutral
     */
    public static function suggest(array $attribute, array $texts, bool $allowNeutral): ?array
    {
        if (!empty($attribute['allowCustom'])) return self::isColour($attribute) ? self::colourText($texts, $allowNeutral) : null;
        if (empty($attribute['values'])) return null;
        foreach ($texts as $text) {
            $found = self::optionsInText($attribute, (string)$text);
            if (count($found) === 1) return ['value' => (string)$found[0]['id'], 'option' => (string)$found[0]['name'], 'basis' => 'text'];
            if (count($found) > 1) {
                // Several different values in one text ("Kırmızı Beyaz"): only a "mixed" option states that truthfully.
                $mixed = self::neutral($attribute, false);
                return $mixed ? $mixed + ['basis' => 'text_multi'] : null;
            }
        }
        $neutral = $allowNeutral ? self::neutral($attribute, true) : null;
        return $neutral ? $neutral + ['basis' => 'neutral'] : null;
    }

    /** @return list<array{id:int|string,name:string}> options named in the text, longest phrase wins over its parts */
    public static function optionsInText(array $attribute, string $text): array
    {
        $hay = ' '.TrendyolAttributeResolver::mapKey($text).' ';
        if (trim($hay) === '') return [];
        $hits = [];
        foreach ($attribute['values'] ?? [] as $option) {
            $key = TrendyolAttributeResolver::mapKey((string)($option['name'] ?? ''));
            // A bare number ("3", "12") or a one/two letter code ("M", "XL") matches unrelated words and counts.
            if ($key === '' || mb_strlen($key) < 3 || ctype_digit(str_replace(' ', '', $key)) || in_array($key, self::STOP, true)) continue;
            if (str_contains($hay, ' '.$key.' ')) $hits[$key] = ['id' => $option['id'], 'name' => (string)$option['name']];
        }
        foreach (array_keys($hits) as $short) foreach (array_keys($hits) as $long) {
            if ($short !== $long && str_contains(' '.$long.' ', ' '.$short.' ')) { unset($hits[$short]); break; }
        }
        // Two spellings of one option id are one hit.
        $byId = [];
        foreach ($hits as $hit) $byId[(string)$hit['id']] = $hit;
        return array_values($byId);
    }

    /**
     * @param bool $silent true: the text says nothing (an "unknown" option fits best);
     *                     false: the text names several values (only a "mixed" option fits)
     * @return array{value:string,option:string}|null
     */
    public static function neutral(array $attribute, bool $silent): ?array
    {
        $name = ' '.TrendyolAttributeResolver::mapKey((string)($attribute['name'] ?? '')).' ';
        $mixed = [];
        foreach (self::MIXED as $word => $names) if (str_contains($name, ' '.$word.' ')) $mixed = array_merge($mixed, $names);
        $wanted = $silent ? array_merge(self::UNKNOWN, $mixed, self::OTHER) : $mixed;
        foreach ($wanted as $want) foreach ($attribute['values'] ?? [] as $option) {
            if (TrendyolAttributeResolver::mapKey((string)($option['name'] ?? '')) === $want) return ['value' => (string)$option['id'], 'option' => (string)$option['name']];
        }
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
