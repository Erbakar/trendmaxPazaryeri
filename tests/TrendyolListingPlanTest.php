<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolListingPlan;
use PHPUnit\Framework\TestCase;
final class TrendyolListingPlanTest extends TestCase
{
    public function testExistingAndDuplicateListingsAreUpdatedInsteadOfRecreated(): void
    {
        self::assertSame('updateListingProducts',TrendyolListingPlan::operation(['is_listed'=>1,'marketplace_product_id'=>'123']));
        self::assertSame('updateListingProducts',TrendyolListingPlan::operation(['sync_error'=>'Batch reddedildi: Aynı barkodlu bir ürününüz bulunduğundan yeni ürün oluşturulamaz.']));
        self::assertSame('createProducts',TrendyolListingPlan::operation(false));
        self::assertSame('createProducts',TrendyolListingPlan::operation(['sync_error'=>'Stok sınırı']));
    }
    public function testPriceUpdatePreservesVerifiedContentAndListing(): void
    {
        $state=TrendyolListingPlan::preserveContent(['is_listed'=>0,'marketplace_product_id'=>'sku','marketplace_category_id'=>'wrong','last_price_sent'=>165],['is_listed'=>1,'marketplace_product_id'=>'123','marketplace_category_id'=>'5300']);
        self::assertSame(1,$state['is_listed']);self::assertSame('123',$state['marketplace_product_id']);
        self::assertSame('5300',$state['marketplace_category_id']);self::assertSame(165,$state['last_price_sent']);
    }
}
