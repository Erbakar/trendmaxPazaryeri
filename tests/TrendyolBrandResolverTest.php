<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolBrandResolver;
use PHPUnit\Framework\TestCase;
final class TrendyolBrandResolverTest extends TestCase
{
    public function testExistingSpecificBrandWinsOverGenericDefault(): void
    {
        $specific=['marketplace_brand_id'=>'1712328','marketplace_brand_name'=>'Existing brand'];
        self::assertSame($specific,TrendyolBrandResolver::resolve($specific+['is_listed'=>1],['marketplace_brand_id'=>'26122']));
    }
    public function testFailedSubmissionDoesNotPinAnIncorrectOldBrand(): void
    {
        $fallback=['marketplace_brand_id'=>'1041874','marketplace_brand_name'=>'Genel Markalar'];
        self::assertSame($fallback,TrendyolBrandResolver::resolve(['is_listed'=>0,'marketplace_brand_id'=>'26122','sync_error'=>'Gönderim sonucu süresi dolmuş'],$fallback));
    }
    public function testMissingOrInvalidListingBrandUsesConfiguredDefaultOnly(): void
    {
        self::assertFalse(TrendyolBrandResolver::resolve(false,false));
        self::assertSame(['marketplace_brand_id'=>'26122'],TrendyolBrandResolver::resolve(['marketplace_brand_id'=>'brand-name'],['marketplace_brand_id'=>'26122']));
    }
}
