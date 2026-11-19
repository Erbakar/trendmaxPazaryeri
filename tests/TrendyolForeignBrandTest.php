<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolProductRules as R;
use PHPUnit\Framework\TestCase;

final class TrendyolForeignBrandTest extends TestCase
{
    public function testForeignDeviceBrandIsDroppedAsAWholeWord(): void
    {
        $this->assertSame('Iphone 16 Pro Kilif Blade Aynali Desenli', R::withoutForeignBrands('Apple Iphone 16 Pro Kilif Blade Aynali Desenli'));
        $this->assertSame('Redmi Note 9 5d Eko Cam Ekran Koruyucu', R::withoutForeignBrands('Xiaomi Redmi Note 9 5d Eko Cam Ekran Koruyucu'));
        $this->assertSame('Watch Kordon, Siyah', R::withoutForeignBrands('APPLE Watch Kordon , Siyah'));
        $this->assertSame('<p>Iphone 15 ile uyumludur.</p>', R::withoutForeignBrands('<p>Apple Iphone 15 ile uyumludur.</p>'));
    }

    public function testOtherWordsAndOwnBrandStay(): void
    {
        $this->assertSame('Pineapple Desenli Kupa', R::withoutForeignBrands('Pineapple Desenli Kupa'));   // part of a word
        $this->assertSame('Samsung Galaxy A05 Kilif', R::withoutForeignBrands('Samsung Galaxy A05 Kilif')); // not listed
        $this->assertSame('Apple AirPods Pro', R::withoutForeignBrands('Apple AirPods Pro', 'Apple'));     // the brand's own product
        $this->assertSame('Xiaomi Mi Band 8', R::withoutForeignBrands('Xiaomi Mi Band 8', 'Xiaomi'));
    }
}
