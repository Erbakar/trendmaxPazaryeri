<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{XmlProfile,Product,Marketplace,CategoryMarketplaceMapping,BrandMarketplaceMapping,ProductAttributeValue};
use App\Services\{CryptoService,ProductPublishService,PriceRuleEngine};
use App\Services\Import\{XmlProfileImportService,XmlProfileSettings};
use App\Services\Marketplace\{AbstractMarketplace,TrendyolService,TrendyolAttributeCatalog};
use PHPUnit\Framework\TestCase;

/** Local DB transaction and loopback-only HTTP; never publishes to a real seller. */
final class XmlUserPublishFlowTest extends TestCase
{
    public function testOwnXmlImportMappingPublishAndRefresh(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Run only with the local HTTP fixture on port 8091.');
        $env=$_ENV;
        foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$line){if(!str_contains($line,'=')||str_starts_with(trim($line),'#'))continue;[$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Only local test DB.');
        $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();$file=BASE_PATH.'/storage/xmlprofiles/xml-user-flow-'.bin2hex(random_bytes(6)).'.xml';
        try{
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['XML flow test','xml-flow-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$c->get(TenantContext::class)->setStoreId($sid);
            $crypto=$c->get(CryptoService::class);$c->get(Marketplace::class)->saveStoreConfig(1,['is_enabled'=>1,'api_key_encrypted'=>$crypto->encrypt('local-test-key'),'api_secret_encrypted'=>$crypto->encrypt('local-test-secret'),'extra_config_json'=>'{"supplier_id":"999"}']);
            $ty=$c->get(TrendyolService::class);(new ReflectionProperty(AbstractMarketplace::class,'baseUrl'))->setValue($ty,'http://127.0.0.1:8091/integration');
            // In-memory metadata belongs to this isolated test tenant; no seller cache is overwritten.
            $attrs=[['id'=>14,'name'=>'Materyal','required'=>true,'allowCustom'=>false,'values'=>[['id'=>501,'name'=>'Plastik']]],['id'=>47,'name'=>'Renk','required'=>true,'allowCustom'=>true,'values'=>[]],['id'=>348,'name'=>'Web Color','required'=>true,'allowCustom'=>false,'values'=>[['id'=>9001,'name'=>'Siyah'],['id'=>9002,'name'=>'Mavi']]],['id'=>1192,'name'=>'Menşei','required'=>true,'allowCustom'=>false,'values'=>[['id'=>100,'name'=>'TR']]]];
            (new ReflectionProperty(TrendyolAttributeCatalog::class,'memory'))->setValue($c->get(TrendyolAttributeCatalog::class),[$sid.'_1017'=>['attributes'=>$attrs]]);
            $xml=$this->xml();file_put_contents($file,$xml);$import=$c->get(XmlProfileImportService::class);$analysis=$import->analyzeFile($file);self::assertSame(3,$analysis['count']);$map=$analysis['suggested_mapping'];self::assertSame('Color',$map['attribute_color']);
            $profile=$c->get(XmlProfile::class);$pid=$profile->create(['name'=>'Own XML test','direction'=>'import','source_type'=>'file','source_url'=>basename($file),'format'=>'generic','field_mapping_json'=>json_encode($map),'update_existing'=>1,'update_prices'=>1,'update_stock'=>1,'create_categories'=>1,'create_brands'=>1,'import_images'=>1,'is_active'=>1]);
            $c->get(XmlProfileSettings::class)->save($pid,['kar_tipi'=>'yuzde','kar_degeri'=>115]);
            $result=$import->run($pid);self::assertTrue($result['ok'],$result['message']);self::assertSame(3,$result['created']);self::assertSame(0,$result['failed']);
            $products=$c->get(Product::class);$a=$products->findBySku('XMLFLOW-A');$b=$products->findBySku('XMLFLOW-B');$bad=$products->findBySku('XMLFLOW-C');self::assertSame(3,$profile->getProductCount($pid));
            $publish=$c->get(ProductPublishService::class);$initial=$publish->previewForPublish((int)$a['id'],1);self::assertFalse($initial['valid']);self::assertContains('Kategori pazaryeri eşleştirmesi yapılmamış',$initial['errors']);
            $maps=$c->get(CategoryMarketplaceMapping::class);$mappingId=$maps->saveMapping((int)$a['category_id'],1,'1017','Saç Fırçası ve Tarak');$c->get(BrandMarketplaceMapping::class)->saveMapping((int)$a['brand_id'],1,'999','Test marka');
            foreach([$a,$b]as$p){$preview=$publish->previewForPublish((int)$p['id'],1);self::assertTrue($preview['valid'],implode(' / ',$preview['errors']));self::assertCount(2,$preview['item']['images']);self::assertSame(ceil((float)$p['cost_price']*2.15),$preview['item']['salePrice']);}
            $invalid=$publish->previewForPublish((int)$bad['id'],1);self::assertFalse($invalid['valid']);self::assertStringContainsString('Materyal',implode(' / ',$invalid['errors']));
            $sent=$publish->publishProducts([(int)$a['id'],(int)$b['id']],1);self::assertSame(2,$sent['success'],json_encode($sent));self::assertSame(0,$sent['failed']);
            $unchanged=$import->run($pid);self::assertSame(0,$unchanged['created']);self::assertSame(0,$unchanged['updated']);self::assertSame(3,$unchanged['skipped']);
            $maps->saveTrendyolAttributes($mappingId,['47'=>'Lacivert'],[],(int)$a['id']);
            $doc=simplexml_load_string($xml);$doc->Item[0]->Color='Mavi';$doc->Item[0]->Price='120';$doc->Item[0]->Cost='120';$doc->Item[0]->Stock='7';$xml=$doc->asXML();file_put_contents($file,$xml);
            $refresh=$import->run($pid);self::assertSame(1,$refresh['updated']);self::assertSame(3,$profile->getProductCount($pid));$a=$products->find((int)$a['id']);self::assertSame(7,(int)$a['stock_qty']);self::assertSame(258.0,$c->get(PriceRuleEngine::class)->calculatePrice($a,1)['sale_price']);
            $local=array_column($c->get(ProductAttributeValue::class)->getByProduct((int)$a['id']),'custom_value','attribute_name');self::assertSame('Mavi',$local['Renk']);self::assertSame('Lacivert',$publish->resolveTrendyolAttributes($a,$maps->forProduct($a,1))['details'][1]['value']);
            $updated=$publish->publishProducts([(int)$a['id']],1);self::assertSame(1,$updated['success'],json_encode($updated));self::assertSame(0,$updated['failed']);
            // A mapped blank must not keep a previous supplier color; unmapped fields stay untouched.
            $doc=simplexml_load_string($xml);$doc->Item[0]->Color='';$xml=$doc->asXML();file_put_contents($file,$xml);$import->run($pid);$local=array_column($c->get(ProductAttributeValue::class)->getByProduct((int)$a['id']),'custom_value','attribute_name');self::assertArrayNotHasKey('Renk',$local);self::assertSame('TR',$local['Menşei']);
            self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) AS n FROM products WHERE store_id=1 AND sku LIKE ?', ['XMLFLOW-%'])['n']);
        }finally{$db->rollBack();@unlink($file);$_ENV=$env;}
    }
    private function xml(): string
    {
        $rows=[];foreach([['A','Siyah',100,3,'Plastik'],['B','Mavi',200,5,'Plastik'],['C','Siyah',100,3,'']]as[$id,$color,$cost,$stock,$material])$rows[]='<Item><sku>XMLFLOW-'.$id.'</sku><Name>Test Tarak '.$id.'</Name><barcode>XMLFLOW-'.$id.'</barcode><Price>'.$cost.'</Price><Cost>'.$cost.'</Cost><Stock>'.$stock.'</Stock><Brand>XML test brand</Brand><category>XML test combs</category><Color>'.$color.'</Color><Mensei>Türkiye</Mensei><Material>'.$material.'</Material><Image1>https://example.com/xml-flow-1.jpg</Image1><Image3>https://example.com/xml-flow-3.jpg</Image3></Item>';
        return '<Catalog>'.implode('',$rows).'</Catalog>';
    }
}
