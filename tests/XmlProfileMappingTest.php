<?php
declare(strict_types=1);
use App\Services\Import\XmlProfileMapping;
use App\Services\Import\XmlProfileImportService;
use PHPUnit\Framework\TestCase;

final class XmlProfileMappingTest extends TestCase
{
    private function service(): XmlProfileImportService
    {
        return (new ReflectionClass(XmlProfileImportService::class))->newInstanceWithoutConstructor();
    }
    public function testSupplierTagsProduceDistinctCategoryLevelsAndImagePrefix(): void
    {
        $map = XmlProfileMapping::suggest(['Product_code','Name','mainCategory','category','subCategory','kategorialt','kategorialt2','Price','Stock','Tax','Image1','CurrencyType'], 'Product');
        XmlProfileMapping::validate($map);
        self::assertSame('Product', $map['item_tag']);
        self::assertSame('Product_code', $map['sku']);
        self::assertSame('mainCategory', $map['category']);
        self::assertSame('category', $map['category2']);
        self::assertSame('subCategory', $map['category3']);
        self::assertSame('Image', $map['image_prefix']);
    }
    public function testSelectedCategoryLevelsAndNumberedImagesAreActuallyImported(): void
    {
        $xml = simplexml_load_string('<Product><Product_code>SKU-1</Product_code><Name>Test</Name><mainCategory>Bakım</mainCategory><category>Saç</category><subCategory>Tarak</subCategory><kategorialt>Fırça</kategorialt><Price>26.00</Price><Stock>618</Stock><Weight>3.25</Weight><Image1>https://example.com/1.jpg</Image1><Image2>https://example.com/2.jpg</Image2></Product>');
        $tags = array_map(fn($n)=>$n->getName(), iterator_to_array($xml->children()));
        $map = XmlProfileMapping::suggest($tags, 'Product');
        $parse = new ReflectionMethod(XmlProfileImportService::class, 'parseGenericItem');
        $row = $parse->invoke($this->service(), $xml, $map);
        self::assertSame(['Bakım','Saç','Tarak','Fırça'], $row['category_path']);
        self::assertCount(2, $row['images']);
        self::assertSame(26.0, $row['base_price']);
        self::assertSame(618, $row['stock_qty']);
        self::assertSame(3.25, $row['weight']);
        // Preserve old saved Image1 prefix selections.
        $map['image_prefix'] = 'Image1';
        self::assertCount(2, $parse->invoke($this->service(), $xml, $map)['images']);
    }
    public function testMissingProductNodeIsRejectedBeforeSaving(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XmlProfileMapping::validate(['sku'=>'Product_code','name'=>'Name']);
    }
    public function testAnalyzerReturnsSamplesAndSuggestedMapping(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'xml-profile-test-');
        file_put_contents($file, '<Products><Product><Product_code>A</Product_code><Name>Bir</Name><Image1>https://example.com/1.jpg</Image1></Product><Product><Product_code>B</Product_code><Name>İki</Name></Product></Products>');
        try {
            $result = $this->service()->analyzeFile($file);
            self::assertSame(2, $result['count']);
            self::assertSame('Product', $result['item_tag']);
            self::assertSame('Product_code', $result['suggested_mapping']['sku']);
            self::assertSame('Bir', ((array)$result['samples'])['Name']);
        } finally { unlink($file); }
    }
    public function testMappedSupplierAttributesAndSparseImagesAreRetained(): void
    {
        $xml=simplexml_load_string('<Item><sku>A</sku><Name>Tarak</Name><Color>Siyah</Color><Mensei>Türkiye</Mensei><Material>Plastik</Material><Size>Tek Ebat</Size><Image1>https://example.com/1.jpg</Image1><Image3>https://example.com/3.jpg</Image3></Item>');
        $tags=array_map(fn($x)=>$x->getName(),iterator_to_array($xml->children()));
        $map=XmlProfileMapping::suggest($tags,'Item');
        self::assertSame('Color',$map['attribute_color']);
        self::assertSame('Color',$map['attribute_web_color']);
        $row=(new ReflectionMethod(XmlProfileImportService::class,'parseGenericItem'))->invoke($this->service(),$xml,$map);
        self::assertSame(['Renk'=>'Siyah','Web Color'=>'Siyah','Menşei'=>'TR','Beden'=>'Tek Ebat','Materyal'=>'Plastik'],$row['import_attributes']);
        self::assertSame(['https://example.com/1.jpg','https://example.com/3.jpg'],$row['images']);
        $xml->Color='';
        $row=(new ReflectionMethod(XmlProfileImportService::class,'parseGenericItem'))->invoke($this->service(),$xml,$map);
        self::assertSame('',$row['import_attributes']['Renk']);
        unset($map['attribute_color']);
        $row=(new ReflectionMethod(XmlProfileImportService::class,'parseGenericItem'))->invoke($this->service(),$xml,$map);
        self::assertArrayNotHasKey('Renk',$row['import_attributes']);
    }
}
