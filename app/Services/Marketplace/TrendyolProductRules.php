<?php
declare(strict_types=1);
namespace App\Services\Marketplace;

final class TrendyolProductRules
{
    public static function images(array $images): array
    {
        // This supplier CDN serves the identical path over HTTPS (verified with the source feed).
        // Other HTTP hosts are left intact so validation can request a working HTTPS address.
        foreach ($images as &$image) {
            $url=(string)($image['url']??'');
            if(strtolower((string)parse_url($url,PHP_URL_SCHEME))==='http'
                && strtolower((string)parse_url($url,PHP_URL_HOST))==='cdn1.xmlbankasi.com') {
                $image['url']='https://'.substr($url,7);
            }
        }
        unset($image);
        return array_slice($images,0,8);
    }
    /**
     * Device brands Trendyol rejects as "yasaklı kelime" in the title or description of an accessory
     * that is not that brand's own product (seen in 2026-11 rejections: "Apple Iphone 16 Kılıf").
     * Only trademark names: a prohibited product type is never reworded here.
     */
    public const FOREIGN_BRANDS = ['apple', 'xiaomi', 'oppo', 'panasonic', 'dji'];

    /**
     * Drops those brand names as whole words ("Apple Iphone 16 Kılıf" → "Iphone 16 Kılıf"), unless the
     * product is sold under that brand itself. The compatible brand still travels as an attribute.
     */
    public static function withoutForeignBrands(string $text, string $ownBrand = ''): string
    {
        $own = mb_strtolower(trim($ownBrand), 'UTF-8');
        $out = $text;
        foreach (self::FOREIGN_BRANDS as $word) {
            if ($own !== '' && preg_match('/(^|\W)'.preg_quote($word, '/').'(\W|$)/u', $own)) continue;
            $out = (string)preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', '', $out);
        }
        if ($out === $text) return $text;
        // Tidy the gap the word left: double spaces, a space before punctuation, a leading separator.
        $out = (string)preg_replace('/[ \t]{2,}/u', ' ', $out);
        $out = (string)preg_replace('/ +([,.;:!?)])/u', '$1', $out);
        $out = (string)preg_replace('/(^|>)\s*[-–,\/]\s*/u', '$1', $out);
        $out = (string)preg_replace('/(^|>)[ \t]+/u', '$1', $out);
        return trim($out);
    }
    public static function quantity(int $quantity): int
    {
        return max(0, min(19999, $quantity));
    }
    public static function errors(array $product, array $images): array
    {
        $errors=[];
        foreach($images as $image) if(strtolower((string)parse_url((string)$image['url'],PHP_URL_SCHEME))!=='https') {
            $errors[]='Trendyol görselleri HTTPS adresi olmalı. Ürünün görsel adreslerini güncelleyin.';break;
        }
        if(mb_strlen((string)($product['description']??''))>30000) $errors[]='Trendyol açıklaması en fazla 30.000 karakter olabilir. Ürün açıklamasını kısaltın.';
        return $errors;
    }
}
