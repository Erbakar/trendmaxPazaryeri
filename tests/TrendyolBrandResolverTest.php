<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolBrandResolver;
use PHPUnit\Framework\TestCase;
final class TrendyolBrandResolverTest extends TestCase
{
    public function testExistingSpecificBrandWinsOverGenericDefault(): void
    {
        $specific=['marketplace_brand_id'=>'1712328','marketplace_brand_name'=>'Existing brand'];
        self::assertSame($specific,TrendyolBrandResolver::resolve($specific,['marketplace_brand_id'=>'26122']));
    }
    public function testMissingOrInvalidListingBrandUsesConfiguredDefaultOnly(): void
    {
        self::assertFalse(TrendyolBrandResolver::resolve(false,false));
        self::assertSame(['marketplace_brand_id'=>'26122'],TrendyolBrandResolver::resolve(['marketplace_brand_id'=>'brand-name'],['marketplace_brand_id'=>'26122']));
    }
}
