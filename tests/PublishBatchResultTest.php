<?php
declare(strict_types=1);
use App\Services\ProductPublishService;
use PHPUnit\Framework\TestCase;
final class PublishBatchResultTest extends TestCase
{
    public function testNestedTrendyolStockCodeResolvesAfterXmlBarcodeChanges(): void
    {
        $service=(new ReflectionClass(ProductPublishService::class))->newInstanceWithoutConstructor();
        $remote=new class {
            public function getBatchStatus(string $id): array {return ['status'=>'COMPLETED','items'=>[
                ['requestItem'=>['product'=>['barcode'=>'ORIGINAL','stockCode'=>'SKU-1']], 'status'=>'FAILED','failureReasons'=>['Missing category attribute']]
            ]];}
        };
        $statuses=(new ReflectionMethod($service,'checkBatchStatus'))->invoke($service,$remote,'batch');
        $result=(new ReflectionMethod($service,'batchStatusForProduct'))->invoke($service,['batch_status_by_barcode'=>$statuses],['sku'=>'SKU-1','barcode'=>'NEW-PREFIX']);
        self::assertSame('FAILED',$result['status']);
        self::assertSame('Missing category attribute',$result['message']);
    }
    public function testBackgroundChecksExposeApiFailures(): void
    {
        $service=(new ReflectionClass(ProductPublishService::class))->newInstanceWithoutConstructor();
        $remote=new class {public function getBatchStatus(string $id): array {throw new RuntimeException('receipt unavailable');}};
        $method=new ReflectionMethod($service,'checkBatchStatus');
        self::assertSame([],$method->invoke($service,$remote,'batch'));
        $this->expectException(RuntimeException::class);
        $method->invoke($service,$remote,'batch',true);
    }
}
