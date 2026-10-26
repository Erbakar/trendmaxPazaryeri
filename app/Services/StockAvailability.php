<?php
declare(strict_types=1);
namespace App\Services;

/** Supplier-managed unavailable stock must not be reopened by a cancellation or return. */
final class StockAvailability
{
    public static function quantity(array $product): int
    {
        if (!(bool) ($product['is_active'] ?? true) || !empty($product['is_archived'])
            || (!empty($product['xml_profile_id']) && !empty($product['missing_stock']))) {
            return 0;
        }
        return max(0, (int) ($product['stock_qty'] ?? 0));
    }
}
