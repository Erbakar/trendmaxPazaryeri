<?php
declare(strict_types=1);
use App\Services\Sync\TrendyolOrderSnapshot;
use PHPUnit\Framework\TestCase;

final class TrendyolOrderSnapshotTest extends TestCase
{
    public function testLateOlderPackageCannotOverwriteNewerDetails(): void
    {
        $existing=['raw_data_json'=>json_encode(['lastModifiedDate'=>200])];
        self::assertFalse(TrendyolOrderSnapshot::isCurrent($existing,['raw_data'=>['lastModifiedDate'=>100]]));
        self::assertTrue(TrendyolOrderSnapshot::isCurrent($existing,['raw_data'=>['lastModifiedDate'=>201]]));
    }
    public function testUpdatedTrackingFinancialTotalsAndAddressAreRefreshedWithoutStockMutation(): void
    {
        $fields=TrendyolOrderSnapshot::fields(['cargo_tracking_no'=>'NEW-TRACK','total_amount'=>0,'discount_total'=>0,
            'shipping_address'=>['city'=>'Ankara'],'customer_phone'=>'','raw_data'=>['lastModifiedDate'=>201]]);
        self::assertSame('NEW-TRACK',$fields['cargo_tracking_no']);
        self::assertSame(0,$fields['total_amount']);
        self::assertSame('Ankara',json_decode($fields['shipping_address_json'],true)['city']);
        self::assertArrayNotHasKey('customer_phone',$fields);
        self::assertArrayNotHasKey('stock_qty',$fields);
    }
}
