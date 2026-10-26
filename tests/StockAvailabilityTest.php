<?php
declare(strict_types=1);
use App\Services\StockAvailability;
use PHPUnit\Framework\TestCase;
final class StockAvailabilityTest extends TestCase
{
    public function testSupplierUnavailableCannotBeReopenedByReturnedQuantity(): void
    {
        $product = ['is_active'=>1, 'stock_qty'=>3, 'xml_profile_id'=>1, 'missing_stock'=>1];
        self::assertSame(0, StockAvailability::quantity($product));
        $product['missing_stock']=0; // A subsequent valid supplier snapshot restores availability.
        self::assertSame(3, StockAvailability::quantity($product));
    }
    public function testManualStockAndClosedProducts(): void
    {
        self::assertSame(3, StockAvailability::quantity(['stock_qty'=>3,'missing_stock'=>1]));
        self::assertSame(0, StockAvailability::quantity(['stock_qty'=>3,'is_active'=>0]));
        self::assertSame(0, StockAvailability::quantity(['stock_qty'=>3,'is_archived'=>1]));
        self::assertSame(0, StockAvailability::quantity(['stock_qty'=>-3]));
    }
}
