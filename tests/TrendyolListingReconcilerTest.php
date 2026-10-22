<?php
declare(strict_types=1);
use App\Services\TrendyolListingReconciler;
use PHPUnit\Framework\TestCase;
final class TrendyolListingReconcilerTest extends TestCase
{
    public function testExactSkuCanRestoreAChangedMarketplaceBarcode(): void
    {
        self::assertSame(14,TrendyolListingReconciler::match(['marketplace_sku'=>'HIS-1523','barcode'=>'12226'],['HIS-1523'=>[14]],['TPTHIS-1523'=>[14]]));
    }
    public function testConflictingIdentifiersAndDuplicateSkusAreNeverGuessed(): void
    {
        $remote=['marketplace_sku'=>'SKU','barcode'=>'BAR'];
        self::assertNull(TrendyolListingReconciler::match($remote,['SKU'=>[1]],['BAR'=>[2]]));
        self::assertNull(TrendyolListingReconciler::match($remote,['SKU'=>[1,2]],[]));
        self::assertNull(TrendyolListingReconciler::match(['marketplace_sku'=>'SKU','barcode'=>''],['SKU'=>[1]],[]));
    }
    public function testBarcodeMatchAndDuplicateReferencesToSameProductAreSafe(): void
    {
        self::assertSame(5,TrendyolListingReconciler::match(['barcode'=>'BAR'],[],['BAR'=>[5,5]]));
        self::assertNull(TrendyolListingReconciler::match(['barcode'=>'unknown'],[],[]));
    }
}
