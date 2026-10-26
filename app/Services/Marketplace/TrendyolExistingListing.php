<?php
declare(strict_types=1);
namespace App\Services\Marketplace;

/** Resolve a duplicate response only against the seller's actual catalog. */
final class TrendyolExistingListing
{
    public static function duplicateBarcode(string $error): ?string
    {
        if (!str_contains($error, 'farklı barkodlu bir ürün') && !str_starts_with($error, 'Mevcut Trendyol kaydı')) return null;
        return preg_match('/Barkod:\s*([^\s;]+)/u', $error, $m) ? $m[1] : null;
    }

    public static function verified(array $response, array $product, string $barcode): ?array
    {
        $matches = [];
        foreach (['approved','unapproved'] as $bucket) {
            foreach ($response[$bucket]['content'] ?? [] as $content) {
                foreach ($content['variants'] ?? [$content] as $variant) {
                    if ((string)($variant['barcode'] ?? '') !== $barcode) continue;
                    // A model code alone is not sufficient: variants can share it.
                    if (trim((string)($variant['stockCode'] ?? '')) !== trim((string)$product['sku'])) return null;
                    $matches[] = ['content'=>$content,'variant'=>$variant,'approved'=>$bucket === 'approved'];
                }
            }
        }
        if (count($matches) !== 1) return null;
        $match = $matches[0]; $content = $match['content']; $v = $match['variant'];
        $remoteId = (string)($content['productMainId'] ?? $content['contentId'] ?? $content['id'] ?? '');
        if ($remoteId === '') return null;
        $reason = [];
        foreach ($content['rejectReasonDetails'] ?? [] as $r) {
            $reason[] = trim((string)($r['rejectReasonDetail'] ?? $r['rejectReason'] ?? ''));
        }
        $status = (string)($content['status'] ?? 'pendingApproval');
        $message = $match['approved'] ? null : 'Mevcut Trendyol kaydı — Barkod: '.$barcode.'; '.
            ($reason ? implode(' | ', $reason) : ($status === 'rejected' ? 'Trendyol reddetti; ayrıntı bildirilmedi.' : 'Onay bekliyor ('.$status.').'));
        return ['marketplace_barcode'=>$barcode,'marketplace_sku'=>(string)$v['stockCode'],
            'marketplace_product_id'=>$remoteId,
            'is_listed'=>$match['approved'] ? 1 : 0,'sync_error'=>$message === null ? null : mb_substr($message,0,500)];
    }

    public static function canUpdate(array $product, array $listing): bool
    {
        if (empty($listing['marketplace_barcode'])) return false;
        return (string)$listing['marketplace_barcode'] === (string)$product['barcode'] ||
            (!empty($listing['is_listed']) && !empty($listing['marketplace_product_id']) &&
             (string)($listing['marketplace_sku'] ?? '') === (string)$product['sku']);
    }
}
