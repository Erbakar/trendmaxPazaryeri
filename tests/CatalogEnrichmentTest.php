<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile,Category,ProductCategoryOverride};
use App\Services\Catalog\CatalogEvidenceEnricher;
use App\Services\Marketplace\TrendyolAttributeCatalog;
use PHPUnit\Framework\TestCase;
final class CatalogEnrichmentTest extends TestCase
{
    public function testCompletionIsSourceBoundReversibleAndDoesNotModifyLiveContent(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB only.');
        $env=$_ENV;foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$l)if(str_contains($l,'=')&&!str_starts_with(trim($l),'#')){[$k,$v]=explode('=',$l,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Local DB only.');
        $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();
        try{
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Enrichment test','enrichment-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$tenant=$c->get(TenantContext::class);$tenant->setStoreId($sid);
            $profile=$c->get(XmlProfile::class)->create(['name'=>'Supplier','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/feed','is_active'=>1]);
            $cat=$c->get(Category::class)->create(['name'=>'Hediyelik','slug'=>'gift']);
            $p=$c->get(Product::class);$pid=$p->create(['name'=>'Siyah Plastik Tarak','sku'=>'SOURCE','barcode'=>'B','description'=>'Tarak','category_id'=>$cat,'xml_profile_id'=>$profile,'cost_price'=>30,'base_price'=>30,'stock_qty'=>7,'is_active'=>1,'main_image'=>'https://example.com/a.jpg']);
            $original=$p->find($pid);
            $attrs=['attributes'=>[['id'=>'14','required'=>true,'allowCustom'=>false,'values'=>[['id'=>'501','name'=>'Plastik']]],['id'=>'47','required'=>true,'allowCustom'=>true,'values'=>[]]]];
            (new ReflectionProperty(TrendyolAttributeCatalog::class,'memory'))->setValue($c->get(TrendyolAttributeCatalog::class),[$sid.'_1017'=>$attrs]);
            $entity=['product_id'=>$pid,'source_product_id'=>$pid,'profile_id'=>$profile,'sku'=>'SOURCE','remote'=>null];
            $source=['sku'=>'SOURCE','name'=>$original['name'],'description'=>$original['description'],'stock_qty'=>7,'cost_price'=>30,'base_price'=>30,'images'=>['https://example.com/a.jpg']];$snap=['sources'=>[$profile=>['rows'=>['SOURCE'=>$source]]]];
            $enrich=$c->get(CatalogEvidenceEnricher::class);
            self::assertNull($enrich->complete(1,$entity,array_replace_recursive($snap,['sources'=>[$profile=>['rows'=>['SOURCE'=>['description'=>'Changed source']]]]])));
            self::assertNull($enrich->complete(1,array_replace($entity,['remote'=>['status'=>'approved','categoryId'=>'460']]),$snap),'An approved category is never moved');
            self::assertNotNull($enrich->complete(1,$entity,$snap));$own=$c->get(ProductCategoryOverride::class)->forProduct($pid,1);
            self::assertSame('1017',$own['marketplace_category_id']);$values=json_decode($own['attribute_mapping_json'],true)['products']['id:'.$pid];self::assertSame('501',$values[14]);self::assertSame('Siyah',$values[47]);
            self::assertSame($original,$p->find($pid),'All product data, including price/stock/images, is unchanged');
            self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) n FROM catalog_enrichment_events WHERE store_id=?',[$sid])['n']);
            $enrich->complete(1,$entity,$snap);self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) n FROM catalog_enrichment_events WHERE store_id=?',[$sid])['n'],'Repeated passes are idempotent');
            $event=$db->fetchOne('SELECT * FROM catalog_enrichment_events WHERE store_id=?',[$sid]);self::assertNull(json_decode($event['before_json'],true));self::assertSame($pid,(int)json_decode($event['after_json'],true)['product_id']);
            $repo=$c->get(App\Services\Catalog\CatalogAuditRepository::class);
            $fingerprint=App\Services\Catalog\CatalogAuditPolicy::fingerprint($original,$own);
            $repo->review(1,$pid,$fingerprint,['blocked_reason'=>'Image and name conflict','image_content_hash'=>$repo::imageContentHash($original)],null);
            self::assertFalse($repo->reviewed(1,$pid,$fingerprint));self::assertSame('Image and name conflict',$repo->blockedReason(1,$original));
            self::assertNull($enrich->complete(1,$entity,$snap),'Automated enrichment cannot override a recorded image conflict');
            self::assertNull($repo->blockedReason(1,array_replace($original,['main_image'=>'https://example.com/correct.jpg'])),'A corrected image permits a fresh check');
            $tenant->setStoreId(2147483647);self::assertNull($enrich->complete(1,$entity,$snap));
        }finally{$db->rollBack();$_ENV=$env;}
    }
}
