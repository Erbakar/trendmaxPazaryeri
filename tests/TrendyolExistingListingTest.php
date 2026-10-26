<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolExistingListing as Existing;
use PHPUnit\Framework\TestCase;
final class TrendyolExistingListingTest extends TestCase
{
    private function content(): array { return ['barcode'=>'123','stockCode'=>'SKU','productMainId'=>'MODEL','status'=>'rejected','rejectReasonDetails'=>[['rejectReasonDetail'=>'Açıklamadaki marka ifadesi uygun değil.']]]; }
    public function testDuplicateMustBeConfirmedByExactSellerBarcodeAndVariantSku(): void
    {
        $p=['sku'=>'SKU','barcode'=>'TPTSKU'];$c=$this->content();
        $state=Existing::verified(['approved'=>['content'=>[$c]]],$p,'123');
        self::assertSame(1,$state['is_listed']);self::assertNull($state['sync_error']);self::assertSame('123',$state['marketplace_barcode']);
        self::assertTrue(Existing::canUpdate($p,$state));
        self::assertNull(Existing::verified(['approved'=>['content'=>[$c]]],['sku'=>'OTHER'],'123'));
        self::assertNull(Existing::verified(['approved'=>['content'=>[$c]]],$p,'456'));
        self::assertNull(Existing::verified(['approved'=>['content'=>[$c,$c]]],$p,'123'));
        unset($c['stockCode']);self::assertNull(Existing::verified(['approved'=>['content'=>[$c]]],$p,'123'));
    }
    public function testRejectedListingShowsActualReasonWithoutBeingCountedAsListed(): void
    {
        $state=Existing::verified(['unapproved'=>['content'=>[$this->content()]]],['sku'=>'SKU'],'123');
        self::assertSame(0,$state['is_listed']);self::assertStringContainsString('marka ifadesi',$state['sync_error']);
        self::assertSame('123',Existing::duplicateBarcode($state['sync_error']));
        self::assertFalse(Existing::canUpdate(['sku'=>'SKU','barcode'=>'TPTSKU'],$state));
        self::assertSame('123',Existing::duplicateBarcode('Aynı marka, categori, ürün kodu ve ürün özelliklerine sahip farklı barkodlu bir ürün zaten mevcut. Barkod: 123'));
        self::assertNull(Existing::duplicateBarcode('Geçersiz Barkod: 123'));
    }
    public function testVariantsCannotBeMatchedBySharedModelCode(): void
    {
        $c=['productMainId'=>'MODEL','variants'=>[['barcode'=>'123','stockCode'=>'SKU'],['barcode'=>'456','stockCode'=>'OTHER']]];
        self::assertSame('SKU',Existing::verified(['approved'=>['content'=>[$c]]],['sku'=>'SKU'],'123')['marketplace_sku']);
        self::assertNull(Existing::verified(['approved'=>['content'=>[$c]]],['sku'=>'SKU'],'456'));
        self::assertFalse(Existing::canUpdate(['sku'=>'SKU','barcode'=>'NEW'],['marketplace_barcode'=>'OLD','is_listed'=>1,'marketplace_product_id'=>'MODEL','marketplace_sku'=>'OTHER']));
    }
}
