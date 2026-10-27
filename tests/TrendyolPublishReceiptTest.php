<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolPublishReceipt;
use PHPUnit\Framework\TestCase;
final class TrendyolPublishReceiptTest extends TestCase
{
    public function testExpiryUsesSubmissionTimeRatherThanPollingTime(): void
    {
        $now=strtotime('2026-10-27 20:00:00');
        self::assertTrue(TrendyolPublishReceipt::expired(['last_synced_at'=>'2026-10-27 15:59:00','updated_at'=>'2026-10-27 20:00:00'],$now));
        self::assertFalse(TrendyolPublishReceipt::expired(['last_synced_at'=>'2026-10-27 16:01:00'],$now));
        self::assertFalse(TrendyolPublishReceipt::expired([],$now));
    }
    public function testNestedReceiptCanMatchChangedBarcodeByExactSku(): void
    {
        $r=TrendyolPublishReceipt::states(['items'=>[['requestItem'=>['product'=>['barcode'=>'OLD','stockCode'=>'SMR-SİYAH']], 'status'=>'FAILED','failureReasons'=>[['message'=>'Missing size']]]]]);
        self::assertSame('Missing size',$r['sku:SMR-SİYAH']['message']);
        self::assertSame('FAILED',$r['barcode:OLD']['status']);
        self::assertSame([],TrendyolPublishReceipt::states(['status'=>'COMPLETED','items'=>[]]));
    }
    public function testAmbiguousAndConflictingIdentitiesAreNeverSelected(): void
    {
        $raw=[['productMainId'=>'M1','variants'=>[['barcode'=>'ONE','stockCode'=>'SKU'],['barcode'=>'TWO','stockCode'=>'SKU']]]];
        $index=TrendyolPublishReceipt::index($raw,[]);
        self::assertTrue(TrendyolPublishReceipt::match(['barcode'=>'NEW','sku'=>'SKU'],$index)['conflict']);
        self::assertTrue(TrendyolPublishReceipt::match(['barcode'=>'ONE','sku'=>'OTHER'],$index)['conflict']);
        self::assertSame('ONE',TrendyolPublishReceipt::match(['barcode'=>'ONE','sku'=>'SKU'],$index)['barcode']);
        self::assertSame([],TrendyolPublishReceipt::match(['barcode'=>'ABSENT','sku'=>'ABSENT'],$index));
    }
}
