<?php
declare(strict_types=1);
use App\Services\Sync\TrendyolQuestionData;
use PHPUnit\Framework\TestCase;
final class TrendyolQuestionDataTest extends TestCase
{
    public function testPublishedAnswerUsesActualApiObjectAndImage(): void
    {
        $q=TrendyolQuestionData::normalize(1,['id'=>12,'status'=>'ANSWERED','answer'=>['text'=>'Cevap','creationDate'=>1792660000000],'imageUrl'=>'https://example.com/item.jpg']);
        self::assertSame('answered',$q['status']);self::assertSame('Cevap',$q['answer_text']);
        self::assertSame('https://example.com/item.jpg',$q['product_image_url']);
    }
    public function testExpiredAndReportedQuestionsAreNotShownAsWaiting(): void
    {
        foreach(['UNANSWERED','REPORTED','REJECTED'] as $status) self::assertSame('rejected',TrendyolQuestionData::normalize(1,['id'=>12,'status'=>$status])['status']);
        self::assertSame('waiting',TrendyolQuestionData::normalize(1,['id'=>12,'status'=>'WAITING_FOR_APPROVE'])['status']);
    }
}
