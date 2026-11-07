<?php
declare(strict_types=1);
use App\Controllers\TrendyolAttributeController;
use App\Services\Catalog\AttributeIssueReport;
use App\Services\Marketplace\TrendyolAttributeResolver as Resolver;
use PHPUnit\Framework\TestCase;

final class AttributeValueMapTest extends TestCase
{
    private function definitions(): array
    {
        return [
            ['id'=>348,'name'=>'Renk','required'=>true,'varianter'=>true,'allowCustom'=>false,'values'=>[['id'=>1001,'name'=>'Lacivert'],['id'=>1002,'name'=>'Çok Renkli']]],
            ['id'=>1192,'name'=>'Menşei','required'=>true,'allowCustom'=>false,'values'=>[['id'=>10617344,'name'=>'TR'],['id'=>10617345,'name'=>'CN']]],
            ['id'=>14,'name'=>'Materyal','required'=>false,'allowCustom'=>false,'values'=>[['id'=>501,'name'=>'Pamuk']]],
        ];
    }
    private function local(string $name, string $value, int $id): array
    {
        return ['attribute_id'=>$id,'attribute_name'=>$name,'attribute_value_id'=>null,'custom_value'=>$value,'attribute_value_text'=>null];
    }
    public function testXmlValueWithoutOptionIsReportedAsUnmatchedNotMissing(): void
    {
        $r=Resolver::resolve(['id'=>7],[],$this->definitions(),[$this->local('Renk','Lacivert-Beyaz',47),$this->local('Menşei','TR',90)]);
        self::assertSame([],$r['missing']);
        self::assertSame('Lacivert-Beyaz',$r['unmatched'][0]['value']);
        self::assertSame(47,$r['unmatched'][0]['local_attribute_id']);
        self::assertCount(1,$r['errors']);
    }
    public function testValueMapTurnsXmlValueIntoChosenTrendyolOption(): void
    {
        $mapping=['attribute_mapping_json'=>json_encode(['value_map'=>['348'=>['lacivert beyaz'=>'1002'],'1192'=>['turkiye'=>'10617344']]])];
        $r=Resolver::resolve(['id'=>7],$mapping,$this->definitions(),[$this->local('Renk','Lacivert-Beyaz',47),$this->local('Menşei','Türkiye',90)]);
        self::assertSame([],$r['errors']);
        self::assertSame(['attributeId'=>348,'attributeValueId'=>1002],$r['attributes'][0]);
        self::assertSame(['attributeId'=>1192,'attributeValueId'=>10617344],$r['attributes'][1]);
    }
    public function testValueMapCannotSmuggleForeignOptionId(): void
    {
        $mapping=['attribute_mapping_json'=>json_encode(['value_map'=>['348'=>['lacivert beyaz'=>'999999']]])];
        $r=Resolver::resolve(['id'=>7],$mapping,[$this->definitions()[0]],[$this->local('Renk','Lacivert-Beyaz',47)]);
        self::assertSame([],$r['attributes']);self::assertCount(1,$r['errors']);
    }
    public function testUnknownOptionalSameNameValueIsSkippedButExplicitSourceStillBlocks(): void
    {
        $defs=$this->definitions();
        $r=Resolver::resolve(['id'=>7],['attribute_mapping_json'=>json_encode(['defaults'=>['348'=>'1001','1192'=>'10617344']])],$defs,[$this->local('Materyal','%100 Polyester',55)]);
        self::assertSame([],$r['errors']);self::assertCount(1,$r['warnings']);self::assertCount(2,$r['attributes']);
        $r=Resolver::resolve(['id'=>7],['attribute_mapping_json'=>json_encode(['defaults'=>['348'=>'1001','1192'=>'10617344'],'sources'=>['14'=>'55']])],$defs,[$this->local('Materyal','%100 Polyester',55)]);
        self::assertCount(1,$r['errors']);self::assertSame('14',$r['unmatched'][0]['id']);
    }
    public function testAggregateGroupsProductsByAttributeAndValue(): void
    {
        $products=[['id'=>1,'sku'=>'A1'],['id'=>2,'sku'=>'A2'],['id'=>3,'sku'=>'A3']];
        $locals=[1=>[$this->local('Renk','Lacivert-Beyaz',47)],2=>[$this->local('Renk','lacivert beyaz',47),$this->local('Menşei','TR',90)],3=>[$this->local('Renk','Lacivert',47),$this->local('Menşei','TR',90)]];
        $rows=AttributeIssueReport::aggregate([],$this->definitions(),$products,$locals);
        $by=[];foreach($rows as$row)$by[$row['issue_type']][]=$row;
        self::assertSame(2,$by['summary'][0]['product_count']);self::assertSame(3,$by['summary'][0]['checked_count']);
        self::assertSame('1192',$by['missing'][0]['attribute_id']);self::assertSame(['A1'],$by['missing'][0]['sample_skus']);
        self::assertCount(1,$by['unmatched']);self::assertSame(2,$by['unmatched'][0]['product_count']);self::assertSame(1,$by['unmatched'][0]['is_varianter']);
    }
    public function testValueMapValidationKeepsValidChoicesAndDropsStaleOnes(): void
    {
        $defs=array_column($this->definitions(),null,'id');
        self::assertSame([['348'=>['lacivert beyaz'=>'1002']],[]],TrendyolAttributeController::validValueMap($defs,['348'=>['Lacivert-Beyaz'=>'1002','Mavi'=>'']]));
        [$map,$warnings]=TrendyolAttributeController::validValueMap($defs,['348'=>['Lacivert-Beyaz'=>'10617344','Lacivert'=>'1001'],'777'=>['x'=>'1']]);
        self::assertSame(['348'=>['lacivert'=>'1001']],$map);self::assertCount(2,$warnings);
        $this->expectException(\InvalidArgumentException::class);
        TrendyolAttributeController::validValueMap($defs,['348'=>'not-a-table']);
    }
    public function testMapKeyKeepsNumberBoundariesAndValueMapNeverRedirectsMatchingNames(): void
    {
        self::assertNotSame(Resolver::mapKey('1-2 Yaş'),Resolver::mapKey('12 Yaş'));
        self::assertSame(Resolver::mapKey('Lacivert-Beyaz'),Resolver::mapKey('lacivert  beyaz'));
        // A value that matches a Trendyol option by name keeps that option even if a map entry exists for it.
        $mapping=['attribute_mapping_json'=>json_encode(['value_map'=>['348'=>['lacivert'=>'1002']],'defaults'=>['1192'=>'10617344']])];
        $r=Resolver::resolve(['id'=>1],$mapping,$this->definitions(),[$this->local('Renk','Lacivert',47)]);
        self::assertSame(['attributeId'=>348,'attributeValueId'=>1001],$r['attributes'][0]);
    }
    public function testAmbiguousOptionNamesAndOptionalVariantValuesAreNotGuessed(): void
    {
        $size=['id'=>293,'name'=>'Yaş','required'=>true,'allowCustom'=>false,'values'=>[['id'=>1,'name'=>'1-2 Yaş'],['id'=>2,'name'=>'12 Yaş']]];
        $r=Resolver::resolve(['id'=>1],[],[$size],[$this->local('Yaş','12 yaş',5)]);
        self::assertSame(['attributeId'=>293,'attributeValueId'=>2],$r['attributes'][0]); // numbers must agree
        $only12=['id'=>293,'name'=>'Yaş','required'=>true,'allowCustom'=>false,'values'=>[['id'=>2,'name'=>'12 Yaş'],['id'=>3,'name'=>'1-3 Yaş']]];
        $r=Resolver::resolve(['id'=>1],[],[$only12],[$this->local('Yaş','1-2 Yaş',5)]);
        self::assertSame([],$r['attributes']);self::assertCount(1,$r['unmatched']);   // "1-2" is never sent as "12"
        $r=Resolver::resolve(['id'=>1],['attribute_mapping_json'=>json_encode(['value_map'=>['293'=>['1 2 yas'=>'3']]])],[$only12],[$this->local('Yaş','1-2 Yaş',5)]);
        self::assertSame(['attributeId'=>293,'attributeValueId'=>3],$r['attributes'][0]);
        self::assertTrue(Resolver::sameName('%100 Pamuk','100% pamuk'));self::assertTrue(Resolver::sameName('ÇokRenkli','Çok Renkli'));
        $variant=['id'=>9,'name'=>'Beden','required'=>false,'varianter'=>true,'allowCustom'=>false,'values'=>[['id'=>3,'name'=>'S']]];
        $r=Resolver::resolve(['id'=>1],[],[$variant],[$this->local('Beden','XXS',6)]);
        self::assertCount(1,$r['errors']);self::assertSame([],$r['warnings']);
    }
}
