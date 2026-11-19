<?php
declare(strict_types=1);

use App\Services\Marketplace\HepsiburadaAttributeInference as Inf;
use PHPUnit\Framework\TestCase;

/** Cases come from the live dry run of 19 Nov 2026 (products that failed on missing required attributes). */
final class HepsiburadaAttributeInferenceTest extends TestCase
{
    private function attr(string $name, array $values): array
    {
        return ['id' => strtolower($name), 'name' => $name, 'type' => 'enum', 'values' => $values];
    }

    public function testPhoneBrandAndModelComeFromTheTitle(): void
    {
        $marka = $this->attr('Uyumlu Marka', ['Apple', 'Samsung', 'Xiaomi', 'Redmi', 'Diğer']);
        $model = $this->attr('Telefon Modeli', ['Galaxy A26', 'Galaxy A25', 'iPhone 16 Pro', 'iPhone 16 Pro Max', 'Redmi 14C']);

        self::assertSame('Samsung', Inf::infer($marka, 'Samsung Galaxy A26 Kilif Trend S Plus Kapakli Kilif')['value']);
        self::assertSame('Galaxy A26', Inf::infer($model, 'Samsung Galaxy A26 Kilif Trend S Plus Kapakli Kilif')['value']);
        self::assertSame('iPhone 16 Pro Max', Inf::infer($model, 'Apple Iphone 16 Pro Max Desina Magsafe Kapak')['value']);
        // Brand and sub-brand both in the title: the device brand comes first
        self::assertSame('Xiaomi', Inf::infer($marka, 'Xiaomi Redmi 14c Kilif Razer Lensli Silikon - Lacivert')['value']);
        self::assertSame('Redmi 14C', Inf::infer($model, 'Xiaomi Redmi 14c Kilif Razer Lensli Silikon - Lacivert')['value']);
    }

    public function testColourFromTheTitleAndMixedWhenSeveral(): void
    {
        $renk = $this->attr('Renk', ['Kırmızı', 'Siyah', 'Beyaz', 'Çok Renkli']);
        self::assertSame(['value' => 'Kırmızı', 'source' => 'ad'], Inf::infer($renk, 'Watch 20mm Kr410 Kumas Kordon - Kirmizi'));
        self::assertSame('Çok Renkli', Inf::infer($renk, 'Siyah Beyaz Desenli Kordon')['value']);
        self::assertSame(['value' => 'Çok Renkli', 'source' => 'notr'], Inf::infer($renk, 'Dekoratif At Biblosu'));
    }

    public function testNeutralOptionOnlyWhenTheTextIsSilent(): void
    {
        $cinsiyet = $this->attr('Cinsiyet', ['Kadın', 'Erkek', 'Unisex']);
        self::assertSame('Kadın', Inf::infer($cinsiyet, 'Gumus Gold Ayarlanabilir Beyaz Tasli Yaprak Kadin Yuzuk')['value']);
        self::assertSame(['value' => 'Unisex', 'source' => 'notr'], Inf::infer($cinsiyet, 'Seffaf Semsiye'));

        $tema = $this->attr('Karakter / Tema', ['Hayvanlar', 'Belirtilmemiş', 'Diğer']);
        self::assertSame('Belirtilmemiş', Inf::infer($tema, 'Polyester Adalet Biblosu')['value']);
    }

    public function testSizesAreNeverDefaulted(): void
    {
        $boy = $this->attr('Boy', ['10 cm', '20 cm', 'Belirtilmemiş']);
        self::assertNull(Inf::infer($boy, 'Polyester Adalet Biblosu'));
        $beden = $this->attr('Beden', ['S', 'M', 'Standart', 'Belirtilmemiş']);
        self::assertNull(Inf::infer($beden, 'Kirmizi Dantel Kedi Maskesi'));
        self::assertFalse(Inf::allowsNeutral('Hacim (ml)'));
        self::assertTrue(Inf::allowsNeutral('Malzeme'));
    }

    public function testOriginAndXmlValue(): void
    {
        $mensei = $this->attr('Menşei', ['Çin', 'Türkiye']);
        self::assertSame(['value' => 'Türkiye', 'source' => 'mensei'], Inf::infer($mensei, 'Pirinc Kul Tablasi'));

        $malzeme = $this->attr('Malzeme', ['Metal', 'Plastik', 'Polyester', 'Diğer']);
        self::assertSame(['value' => 'Metal', 'source' => 'xml'], Inf::infer($malzeme, 'Kul Tablasi', ['Malzeme' => 'metal']));
        self::assertSame('Polyester', Inf::infer($malzeme, 'Polyester Adalet Biblosu')['value']);
    }

    public function testFreeTextIsLeftToTheUserUnlessXmlHasIt(): void
    {
        $free = ['id' => 'model', 'name' => 'Model Kodu', 'type' => 'string', 'values' => []];
        self::assertNull(Inf::infer($free, 'TIANQIU AG0 LR69 Pil'));
        self::assertSame('AG0', Inf::infer($free, 'Pil', ['Model Kodu' => 'AG0'])['value']);
    }
}
