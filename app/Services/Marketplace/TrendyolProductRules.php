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
