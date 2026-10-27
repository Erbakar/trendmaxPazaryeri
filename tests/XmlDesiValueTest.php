<?php
declare(strict_types=1);

use App\Services\Import\XmlDesiValue;
use App\Services\Import\XmlFileImportService;
use App\Services\Import\XmlProfileImportService;
use PHPUnit\Framework\TestCase;

final class XmlDesiValueTest extends TestCase
{
    public function testDescriptionsAndModelCodesAreNotMeasurements(): void
    {
        foreach (['Cam Satranç Seti Oyunu Alk2545', 'ALK2545', '<h2>20</h2><p>10 adet</p>', '20x30x40', '-2', '1.2.3', ''] as $raw) {
            self::assertSame(0.0, XmlDesiValue::parse($raw), $raw);
        }
        foreach (['1'=>1.0, '2,5'=>2.5, '2.50'=>2.5, ' 3 DESİ '=>3.0, '1.234,56'=>1234.56, '1,234.56'=>1234.56] as $raw=>$expected) {
            self::assertSame($expected, XmlDesiValue::parse((string) $raw), (string) $raw);
        }
    }

    public function testWrongDescriptionMappingCannotCorruptDesiInEitherImporter(): void
    {
        $source='<Product><SKU>A</SKU><Name>Satranç</Name><Price>26.00</Price><Stock>7</Stock><Description>Cam Satranç Seti Oyunu Alk2545</Description></Product>';
        $profile=(new ReflectionClass(XmlProfileImportService::class))->newInstanceWithoutConstructor();
        $row=(new ReflectionMethod($profile,'parseGenericItem'))->invoke($profile,simplexml_load_string($source),['sku'=>'SKU','name'=>'Name','price'=>'Price','stock'=>'Stock','description'=>'Description','desi'=>'Description']);
        self::assertSame(0.0,$row['desi']);self::assertSame(26.0,$row['base_price']);self::assertSame(7,$row['stock_qty']);
        $file=(new ReflectionClass(XmlFileImportService::class))->newInstanceWithoutConstructor();$doc=new DOMDocument();$doc->loadXML($source);
        $row=(new ReflectionMethod($file,'buildRow'))->invoke($file,$doc->documentElement,['sku'=>'SKU','name'=>'Name','base_price'=>'Price','stock_qty'=>'Stock','description'=>'Description','desi'=>'Description','cost_price'=>'','list_price'=>'']);
        self::assertSame(0.0,$row['desi']);self::assertSame(26.0,$row['base_price']);self::assertSame(7,$row['stock_qty']);
    }
}
