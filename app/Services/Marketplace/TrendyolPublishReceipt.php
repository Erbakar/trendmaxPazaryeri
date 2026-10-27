<?php
declare(strict_types=1);
namespace App\Services\Marketplace;

/** A batch receipt is short-lived; only the seller's catalogue proves approval. */
final class TrendyolPublishReceipt
{
    public static function expired(array $row, ?int $now = null): bool
    {
        $when = strtotime((string)(($row['last_synced_at'] ?? '') ?: ($row['created_at'] ?? '')));
        return $when !== false && ($now ?? time()) - $when >= 4 * 3600;
    }

    public static function states(array $result): array
    {
        $states = [];
        foreach ($result['items'] ?? [] as $item) {
            $request = $item['requestItem']['product'] ?? $item['requestItem'] ?? [];
            $barcode = trim((string)($request['barcode'] ?? $item['barcode'] ?? ''));
            if ($barcode === '') continue;
            $reasons = $item['failureReasons'] ?? [];
            $entry = ['status'=>strtoupper((string)($item['status'] ?? '')),
                'message'=>is_array($reasons) ? implode('; ',array_map(static fn($r)=>is_array($r)
                    ? (string)($r['message'] ?? json_encode($r,JSON_UNESCAPED_UNICODE)) : (string)$r,$reasons)) : (string)$reasons];
            $states['barcode:'.$barcode] = $entry;
            $sku = trim((string)($request['stockCode'] ?? ''));
            if ($sku !== '') $states['sku:'.$sku] = $entry;
        }
        return $states;
    }

    public static function index(array $approved, array $unapproved): array
    {
        $index = [];
        foreach (['approved'=>$approved,'unapproved'=>$unapproved] as $bucket=>$contents) {
            foreach ($contents as $content) foreach ($content['variants'] ?? [$content] as $v) {
                $barcode = trim((string)($v['barcode'] ?? ''));
                if ($barcode === '') continue;
                $entry=['barcode'=>$barcode,'sku'=>trim((string)($v['stockCode'] ?? '')),'content'=>$content,'bucket'=>$bucket];
                $index[$bucket]['barcode:'.$barcode][]=$entry;
                if ($entry['sku']!=='') $index[$bucket]['sku:'.$entry['sku']][]=$entry;
            }
        }
        return $index;
    }

    /** Never choose the first of several variants or a conflicting barcode. */
    public static function match(array $row, array $index): array
    {
        $barcode=(string)($row['barcode'] ?? ''); $sku=trim((string)($row['sku'] ?? ''));
        foreach (['approved','unapproved'] as $bucket) {
            $matches=$index[$bucket]['barcode:'.$barcode] ?? [];
            if (!$matches && $sku!=='') $matches=$index[$bucket]['sku:'.$sku] ?? [];
            if (!$matches) continue;
            if (count($matches)!==1 || $matches[0]['sku']!==$sku) return ['conflict'=>true];
            return $matches[0];
        }
        return [];
    }
}
