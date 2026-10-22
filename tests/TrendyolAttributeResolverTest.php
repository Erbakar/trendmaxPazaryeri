<?php
declare(strict_types=1);
use App\Services\Marketplace\TrendyolAttributeResolver as Resolver;
use App\Services\Marketplace\TrendyolRequestLimiter;
use PHPUnit\Framework\TestCase;
final class TrendyolAttributeResolverTest extends TestCase
{
    private function definitions(): array
    {
        return [
            ['id'=>293,'name'=>'Beden','required'=>true,'allowCustom'=>false,'values'=>[['id'=>4872,'name'=>'Tek Ebat'],['id'=>4873,'name'=>'S']]],
            ['id'=>47,'name'=>'Renk','required'=>true,'allowCustom'=>true,'values'=>[]],
        ];
    }
    public function testMissingRequiredValuesAreNotSilentlyPassed(): void
    {
        $result=Resolver::resolve(['id'=>1],[], $this->definitions(),[]);
        self::assertCount(2,$result['errors']);self::assertSame([],$result['attributes']);
    }
    public function testCategoryDefaultsBecomeTrendyolIdsAndCustomValues(): void
    {
        $mapping=['attribute_mapping_json'=>json_encode(['defaults'=>['293'=>'4872','47'=>'Siyah']])];
        $result=Resolver::resolve(['id'=>1],$mapping,$this->definitions(),[]);
        self::assertSame([],$result['errors']);
        self::assertSame([['attributeId'=>293,'attributeValueId'=>4872],['attributeId'=>47,'customAttributeValue'=>'Siyah']],$result['attributes']);
    }
    public function testProductOverridesDefaultAndLocalIdsNeverBecomeTrendyolIds(): void
    {
        $mapping=['attribute_mapping_json'=>json_encode(['defaults'=>['47'=>'Siyah'],'sources'=>['293'=>'9'],'products'=>['id:1'=>['47'=>'Beyaz']]])];
        $local=[['attribute_id'=>9,'attribute_name'=>'Ölçü','attribute_value_id'=>999,'attribute_value_text'=>'Tek Ebat']];
        $result=Resolver::resolve(['id'=>1],$mapping,$this->definitions(),$local);
        self::assertSame([],$result['errors']);self::assertSame(4872,$result['attributes'][0]['attributeValueId']);
        self::assertSame('Beyaz',$result['attributes'][1]['customAttributeValue']);
    }
    public function testForeignEnumIdIsRejectedEvenWhenAttributeIsOptional(): void
    {
        $a=$this->definitions()[0];$a['required']=false;
        $result=Resolver::resolve(['id'=>1],['attribute_mapping_json'=>'{"defaults":{"293":"999"}}'],[$a],[]);
        self::assertCount(1,$result['errors']);self::assertSame([],$result['attributes']);
    }
    public function testMultipleValuesUseV2AttributeValueIds(): void
    {
        $a=$this->definitions()[0];$a['allowMultipleAttributeValues']=true;
        self::assertSame(['attributeId'=>293,'attributeValueIds'=>[4872,4873]],Resolver::value($a,'["4872","4873"]'));
    }
    public function testSupplierCdnUsesHttpsAndTrendyolImageLimit(): void
    {
        $images=array_fill(0,10,['url'=>'http://cdn1.xmlbankasi.com/image.jpg']);
        $prepared=\App\Services\Marketplace\TrendyolProductRules::images($images);
        self::assertCount(8,$prepared);
        self::assertSame('https://cdn1.xmlbankasi.com/image.jpg',$prepared[0]['url']);
        self::assertSame([],\App\Services\Marketplace\TrendyolProductRules::errors(['description'=>'Test'],$prepared));
        self::assertCount(2,\App\Services\Marketplace\TrendyolProductRules::errors(['description'=>str_repeat('x',30001)],[['url'=>'http://example.com/image.jpg']]));
    }
    public function testIndependentRequestsShareSellerWritePacing(): void
    {
        $dir=sys_get_temp_dir().'/trendmax-limiter-'.bin2hex(random_bytes(6));mkdir($dir);
        try {
            $first=new TrendyolRequestLimiter($dir);$second=new TrendyolRequestLimiter($dir);
            $start=microtime(true);
            $first->wait('test-seller','POST','https://apigw.trendyol.com/integration/product/sellers/1/v2/products');
            $second->wait('test-seller','PUT','https://apigw.trendyol.com/integration/product/sellers/1/v2/products');
            self::assertGreaterThanOrEqual(0.30,microtime(true)-$start);
        }finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
    }
}
