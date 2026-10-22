<?php
declare(strict_types=1);
namespace App\Services\Marketplace;
/** Preserve a seller's verified product-specific brand before applying generic defaults. */
final class TrendyolBrandResolver
{
    public static function resolve(array|false $listing, array|false $fallback): array|false
    {
        $id = trim((string) ($listing['marketplace_brand_id'] ?? ''));
        if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
            return ['marketplace_brand_id'=>$id,'marketplace_brand_name'=>$listing['marketplace_brand_name'] ?? ''];
        }
        return $fallback;
    }
}
