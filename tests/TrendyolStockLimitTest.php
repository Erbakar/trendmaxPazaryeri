<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolProductRules;
use PHPUnit\Framework\TestCase;
final class TrendyolStockLimitTest extends TestCase
{
    public function testOutboundStockIsNeverInflatedAndStaysBelowMarketplaceLimit(): void
    {
        self::assertSame(0,TrendyolProductRules::quantity(-1));
        self::assertSame(50,TrendyolProductRules::quantity(50));
        self::assertSame(19999,TrendyolProductRules::quantity(19999));
        self::assertSame(19999,TrendyolProductRules::quantity(20000));
        self::assertSame(19999,TrendyolProductRules::quantity(999999));
    }
}
