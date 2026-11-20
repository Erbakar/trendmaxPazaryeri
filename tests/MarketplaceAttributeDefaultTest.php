<?php
declare(strict_types=1);

use App\Services\Mapping\MarketplaceAttributeDefaultService as S;
use PHPUnit\Framework\TestCase;

final class MarketplaceAttributeDefaultTest extends TestCase
{
    public function testReadsEveryMissingAttributeFromTheValidationError(): void
    {
        $e = "Validasyon hatası: Hepsiburada zorunlu özelliği çözülemedi: Malzeme — Kategori Eşleştirme > Kategori Özellikleri'nden değer seçin, "
           . "Hepsiburada zorunlu özelliği çözülemedi: Ampul Cinsi — Kategori Eşleştirme > Kategori Özellikleri'nden değer seçin";
        self::assertSame(['Malzeme', 'Ampul Cinsi'], S::missingAttributes($e));
        self::assertSame([], S::missingAttributes('Validasyon hatası: Geçersiz fiyat (base_price > 0 olmalı)'));
    }

    public function testRecognisesProductsWithoutAUsableCategory(): void
    {
        self::assertTrue(S::lacksCategory('Validasyon hatası: Kategori pazaryeri eşleştirmesi yapılmamış'));
        self::assertTrue(S::lacksCategory('Validasyon hatası: Eşlenen Hepsiburada kategorisi satışa kapalı: Hediye > Hediyelik Eşya.'));
        self::assertFalse(S::lacksCategory('Validasyon hatası: Hepsiburada zorunlu özelliği çözülemedi: Boy — Kategori Eşleştirme'));
    }
}
