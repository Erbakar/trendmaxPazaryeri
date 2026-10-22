<?php
declare(strict_types=1);
namespace App\Services\Sync;

final class TrendyolQuestionData
{
    public static function normalize(int $marketplaceId, array $q): array
    {
        $answer = $q['answer'] ?? $q['answers'][0] ?? [];
        $rawStatus = strtoupper((string)($q['status'] ?? ''));
        $closed = in_array($rawStatus,['REJECTED','REPORTED','UNANSWERED'],true);
        return [
            'marketplace_id'=>$marketplaceId, 'marketplace_question_id'=>(string)$q['id'],
            'marketplace_product_id'=>(string)($q['productId'] ?? $q['productMainId'] ?? ''),
            'product_name'=>$q['productName'] ?? null, 'product_image_url'=>$q['imageUrl'] ?? $q['productImage'] ?? null,
            'question_text'=>$q['text'] ?? '',
            'question_date'=>isset($q['creationDate']) ? date('Y-m-d H:i:s',intdiv((int)$q['creationDate'],1000)) : date('Y-m-d H:i:s'),
            'customer_name'=>$q['userName'] ?? $q['customerFirstName'] ?? null,
            'status'=>$closed ? 'rejected' : (($rawStatus==='ANSWERED' || ($rawStatus==='' && !empty($answer))) ? 'answered' : 'waiting'),
            'answer_text'=>$answer['text'] ?? null,
            'answer_date'=>isset($answer['creationDate']) ? date('Y-m-d H:i:s',intdiv((int)$answer['creationDate'],1000)) : null,
            'extra_data_json'=>json_encode(['trendyol_status'=>$rawStatus,'reason'=>$q['reason'] ?? $q['reportReason'] ?? null],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'synced_at'=>date('Y-m-d H:i:s'),
        ];
    }
}
