<?php
declare(strict_types=1);
namespace App\Services\Sync;

/** Keep the latest marketplace package details without changing stock reservations twice. */
final class TrendyolOrderSnapshot
{
    public static function isCurrent(array $existing, array $incoming): bool
    {
        $old = json_decode($existing['raw_data_json'] ?? '{}', true) ?: [];
        $oldTime = (int)($old['lastModifiedDate'] ?? 0);
        $newTime = (int)($incoming['raw_data']['lastModifiedDate'] ?? 0);
        return $oldTime === 0 || $newTime === 0 || $newTime >= $oldTime;
    }

    public static function fields(array $incoming): array
    {
        $out = [];
        foreach (['customer_name','customer_email','customer_phone','customer_id_no','customer_tax_office',
            'cargo_company','cargo_tracking_no','cargo_sent_at','ordered_at'] as $key) {
            if (isset($incoming[$key]) && $incoming[$key] !== '') $out[$key] = $incoming[$key];
        }
        foreach (['subtotal','tax_total','discount_total','total_amount'] as $key) {
            if (array_key_exists($key, $incoming) && is_numeric($incoming[$key])) $out[$key] = $incoming[$key];
        }
        foreach (['billing_address','shipping_address'] as $key) {
            if (!empty($incoming[$key]) && is_array($incoming[$key]) && array_filter($incoming[$key])) {
                $out[$key.'_json'] = json_encode($incoming[$key], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        if (!empty($incoming['raw_data'])) {
            $out['raw_data_json'] = json_encode($incoming['raw_data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return $out;
    }
}
