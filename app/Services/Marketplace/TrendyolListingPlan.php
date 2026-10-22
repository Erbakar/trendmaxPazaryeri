<?php
declare(strict_types=1);
namespace App\Services\Marketplace;
final class TrendyolListingPlan
{
    public static function operation(array|false $listing): string
    {
        if ((!empty($listing['is_listed']) && !empty($listing['marketplace_product_id'])) ||
            str_contains((string)($listing['sync_error'] ?? ''), 'Aynı barkodlu bir ürününüz')) {
            return 'updateListingProducts';
        }
        return 'createProducts';
    }
    public static function preserveContent(array $state, array|false $listing): array
    {
        if (!$listing) return $state;
        foreach (['marketplace_product_id','marketplace_sku','marketplace_barcode','marketplace_category_id',
            'marketplace_category_name','marketplace_brand_id','marketplace_brand_name','product_name','main_image'] as $field) {
            if (($listing[$field] ?? null) !== null && $listing[$field] !== '') $state[$field]=$listing[$field];
        }
        if (!empty($listing['is_listed'])) $state['is_listed']=1;
        return $state;
    }
}
