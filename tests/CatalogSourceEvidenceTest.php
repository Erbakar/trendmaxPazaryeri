<?php
declare(strict_types=1);
use App\Services\Catalog\CatalogSourceEvidence as Evidence;
use PHPUnit\Framework\TestCase;
final class CatalogSourceEvidenceTest extends TestCase
{
    private function attributes(): array
    {
        return [
            ['id'=>'47','allowCustom'=>true,'required'=>true,'values'=>[]],
            ['id'=>'348','allowCustom'=>false,'required'=>true,'values'=>[['id'=>'1','name'=>'Siyah'],['id'=>'2','name'=>'Mavi'],['id'=>'3','name'=>'Gümüş']]],
            ['id'=>'14','allowCustom'=>false,'required'=>true,'values'=>[['id'=>'10','name'=>'Ahşap'],['id'=>'11','name'=>'Metal'],['id'=>'12','name'=>'Belirtilmemiş']]],
            ['id'=>'1192','allowCustom'=>false,'required'=>true,'values'=>[['id'=>'90','name'=>'TR'],['id'=>'91','name'=>'CN']]],
            ['id'=>'478','allowCustom'=>false,'required'=>true,'values'=>[['id'=>'20','name'=>'Pilli'],['id'=>'21','name'=>'Şarjlı'],['id'=>'22','name'=>'Solar']]],
            ['id'=>'338','allowCustom'=>false,'required'=>true,'values'=>[['id'=>'30','name'=>'50 cm'],['id'=>'31','name'=>'Tek Ebat']]],
        ];
    }
    public function testMixedGiftCategoryIsSplitByActualObjectAndAmbiguityIsHeld(): void
    {
        foreach(['Siyah Termos'=>'900','Mini Ahşap Kum Saati'=>'4673','Gümüş Kumbaralı Biblo'=>'4672','Mavi Defter'=>'1520','Beyaz Gösteri Ponponları'=>'4077','Kar Küresi Biblo'=>'3011']as$name=>$id)self::assertSame($id,Evidence::category(['name'=>$name])['id']);
        foreach(['Defter ve Termos Seti','Ayna Çerçeve Seti','Gümüş Renk Kolye','Gümüş Kaplama Kolye','Masa Saati ve Anahtarlık Seti','Peluş Gül Buketi']as$name)self::assertNull(Evidence::category(['name'=>$name]),$name);
        self::assertSame('1247',Evidence::category(['name'=>'Gümüş Zincir','description'=>'925 ayar gümüşten üretilmiştir.'])['id']);
    }
    public function testMissingFactsAreNotInventedAndExistingChoicesSurvive(): void
    {
        $r=Evidence::attributes(['name'=>'Ahşap Kutulu Kalem','description'=>'Türkiye adresinden gönderilir.'],$this->attributes(),[]);
        self::assertSame(['14'=>'12'],$r['values']); // Packaging is not product material, shipping country is not origin.
        $r=Evidence::attributes(['name'=>'Siyah Metal Kalem','description'=>''],$this->attributes(),['47'=>'Mavi','348'=>'2']);
        self::assertSame('Mavi',$r['values'][47]);self::assertSame('2',$r['values'][348]);self::assertSame('11',$r['values'][14]);
        self::assertArrayNotHasKey(1192,$r['values']);self::assertArrayNotHasKey(338,$r['values']);
    }
    public function testContradictoryAndUnlabelledMeasurementsCannotBecomeFacts(): void
    {
        $r=Evidence::attributes(['name'=>'Pilli Şarjlı Siyah Mavi Fener 50','description'=>'Model 50 cm kutu'],$this->attributes(),[]);
        self::assertArrayNotHasKey(478,$r['values']);self::assertArrayNotHasKey(47,$r['values']);self::assertArrayNotHasKey(338,$r['values']);
        $r=Evidence::attributes(['name'=>'Zincir','description'=>'<p>Uzunluk: 50 cm</p><p>Micro gümüş kaplama</p><p>Menşei: TR</p>'],$this->attributes(),[]);
        self::assertSame('30',$r['values'][338]);self::assertSame('Gümüş',$r['values'][47]);self::assertSame('3',$r['values'][348]);self::assertSame('90',$r['values'][1192]);
    }
    public function testProductMeasurementsUseExactOptionsWithoutGuessingOrUsingPackaging(): void
    {
        $attributes=[['id'=>'92','allowCustom'=>false,'required'=>true,'values'=>[
            ['id'=>'1','name'=>'60 x 42'],['id'=>'2','name'=>'8,5 x 6'],['id'=>'3','name'=>'25 x 15'],
        ]]];
        foreach(['<p>Ürün Ölçüleri;60*42 Cm</p>'=>'1','Boyut: 8,50 cm × 6 cm'=>'2','Ürünün ebatları: 25 x 15 cm'=>'3']as$description=>$value){
            $r=Evidence::attributes(['name'=>'Duvar Saati','description'=>$description],$attributes,[]);
            self::assertSame($value,$r['values'][92]);self::assertArrayHasKey(92,$r['evidence']);
        }
        foreach([
            'Ürün ölçüleri: 60 x 42 x 3 cm', 'Ürün ölçüleri: 60 x 42 cm x 3 cm',
            'Ürün ölçüleri: 60 x 42 mm', 'Ürün ölçüleri: 60 x 42',
            'Kutu ölçüleri: 60 x 42 cm', 'Paket boyutu: 60 x 42 cm',
            'Ambalajın ebatları: 60 x 42 cm', 'Kutusunun dış ölçüleri: 60 x 42 cm', 'Model 60x42 cm',
            'Ürün ölçüleri: 60 x 42 cm. Boyut: 25 x 15 cm',
            'Ürün ölçüleri: 60 x 42 cm. Boyut: 60 x 42 x 3 cm',
            'Ürün ölçüleri: 60.1 x 42 cm', 'Ürün ölçüleri: 42 x 60 cm',
        ]as$description){
            $r=Evidence::attributes(['name'=>'Duvar Saati','description'=>$description],$attributes,[]);
            self::assertArrayNotHasKey(92,$r['values'],$description);
        }
        $r=Evidence::attributes(['name'=>'Duvar Saati','description'=>'Ürün ölçüleri: 60 x 42 cm'],$attributes,['92'=>'3']);
        self::assertSame('3',$r['values'][92]);self::assertArrayNotHasKey(92,$r['evidence']);
    }
}
