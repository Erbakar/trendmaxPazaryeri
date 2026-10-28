<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile,ProductCategoryOverride};
use App\Services\Catalog\{SupplierPage,SupplierPageClient,SupplierCatalog,CatalogEvidenceEnricher,CatalogAuditRepository};
use App\Services\Import\{XmlProfileImportService,XmlProfileSettings};
use App\Services\Marketplace\TrendyolAttributeCatalog;
use PHPUnit\Framework\TestCase;

final class SupplierCatalogTest extends TestCase
{
    private const URL='https://www.toptanarabul.com/urun/plastik-tarak-123';
    private static function page(string $sku='SUP-1',string $name='Plastik Tarak'): string
    {
        return '<html><body><h1>'.htmlspecialchars($name).'</h1><div class="product-list-row"><div class="product-list-title">Stok Kodu</div><div class="product-list-content">'.$sku.'</div></div><div class="product-list-row"><div class="product-list-title">Kategori</div><div class="product-list-content">Yanlış Kategori</div></div><div class="product-list-row"><div class="product-list-title">Garanti Süresi</div><div class="product-list-content">24 Ay</div></div><div class="product-list-row"><div class="product-list-title">Fiyat</div><div class="product-list-content">9999</div></div><div class="product-detail"><p>Renk: Siyah</p><p>Menşei: Türkiye</p><script>Menşei: Çin</script></div></body></html>';
    }
    public function testSupplierIdentityAndProductOnlyFields(): void
    {
        $page=SupplierPage::product(self::page(),self::URL);
        self::assertSame('SUP-1',$page['sku']);self::assertSame('Plastik Tarak',$page['name']);self::assertSame([],$page['fields']);
        self::assertStringContainsString('Renk: Siyah',$page['description']);self::assertStringNotContainsString('Çin',$page['description']);self::assertStringNotContainsString('9999',json_encode($page));
        self::assertNotNull(SupplierPage::supplement(['sku'=>'SUP-1','name'=>'Plastik Tarak','description'=>'Tarak'],$page));
        self::assertNull(SupplierPage::supplement(['sku'=>'SUP-2','name'=>'Plastik Tarak','description'=>'Tarak'],$page));
        self::assertNull(SupplierPage::supplement(['sku'=>'SUP-1','name'=>'Mavi Plastik Tarak','description'=>'Tarak'],$page));
        self::assertSame('',SupplierPage::supplement(['sku'=>'SUP-1','name'=>'Plastik Tarak','description'=>'<p>Renk: Siyah</p><p>Menşei: Türkiye</p>'],$page));
        $withDescriptionTitle=str_replace('<div class="product-detail">','<div class="product-detail"><h1>Açıklamadaki farklı başlık</h1>',self::page());
        self::assertSame('Plastik Tarak',SupplierPage::product($withDescriptionTitle,self::URL)['name'],'A heading inside the description is not the product identity');
    }
    public function testSupplierRequestsCannotEscapeKnownPublicProductPaths(): void
    {
        foreach([self::URL,'https://www.toptanarabul.com/arama/SMR-D263-S%C4%B0YAH']as$url)self::assertTrue(SupplierPage::allowedUrl($url));
        foreach(['http://www.toptanarabul.com/urun/a','https://evil.com/urun/a','https://www.toptanarabul.com.evil.com/urun/a','https://127.0.0.1/urun/a','https://u:p@www.toptanarabul.com/urun/a','https://www.toptanarabul.com:8080/urun/a','https://www.toptanarabul.com/urun/a?url=http://localhost','https://www.toptanarabul.com/admin','https://www.toptanarabul.com/urun/a#x']as$url)self::assertFalse(SupplierPage::allowedUrl($url),$url);
        self::assertSame([self::URL],SupplierPage::searchLinks('<a href="/urun/plastik-tarak-123">A</a><a href="'.self::URL.'">B</a><a href="https://evil.com/urun/a">C</a><a href="javascript:alert(1)">D</a>'));
    }
    public function testSupplementSurvivesXmlRefreshWhileXmlKeepsInventoryAndPriceAuthority(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB only.');
        $env=$_ENV;foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$l)if(str_contains($l,'=')&&!str_starts_with(trim($l),'#')){[$k,$v]=explode('=',$l,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Local DB only.');
        $c=new Container([]);$db=$c->get(Database::class);$timezone=date_default_timezone_get();$sqlTimezone=$db->fetchOne('SELECT @@session.time_zone tz')['tz'];
        $db->query("SET time_zone='+03:00'");date_default_timezone_set('UTC'); // Retry/expiry must not depend on matching PHP/MySQL time zones.
        $db->beginTransaction();$file=BASE_PATH.'/storage/xmlprofiles/supplier-test-'.bin2hex(random_bytes(5)).'.xml';
        try{
            $db->query('INSERT INTO stores(name,slug)VALUES(?,?)',['Supplier test','supplier-'.bin2hex(random_bytes(5))]);$sid=(int)$db->lastInsertId();$c->get(TenantContext::class)->setStoreId($sid);
            $xml=static fn($price,$stock)=>'<Catalog><Item><sku>SUP-1</sku><Name>Plastik Tarak</Name><Description>Tarak</Description><Price>'.$price.'</Price><Cost>'.$price.'</Cost><Stock>'.$stock.'</Stock><Brand>Test</Brand><category>Test supplier</category><Image1>https://example.com/supplier.jpg</Image1></Item></Catalog>';
            file_put_contents($file,$xml(10,8));$import=$c->get(XmlProfileImportService::class);$mapping=$import->analyzeFile($file)['suggested_mapping'];
            $pid=$c->get(XmlProfile::class)->create(['name'=>'Supplement','direction'=>'import','source_type'=>'file','source_url'=>basename($file),'format'=>'generic','field_mapping_json'=>json_encode($mapping),'update_existing'=>1,'update_prices'=>1,'update_stock'=>1,'create_categories'=>1,'create_brands'=>1,'import_images'=>1,'is_active'=>1]);
            $settings=$c->get(XmlProfileSettings::class);$settings->save($pid,['tedarikci_bilgisi'=>'toptanarabul','kar_degeri'=>115,'kargo_tutari'=>80]);self::assertTrue($import->run($pid)['ok']);
            $p=$c->get(Product::class)->findBySku('SUP-1');$original=$p;$supplier=$c->get(SupplierCatalog::class);
            $attributes=[['id'=>'14','name'=>'Materyal','required'=>true,'allowCustom'=>false,'values'=>[['id'=>'501','name'=>'Plastik']]],['id'=>'47','name'=>'Renk','required'=>true,'allowCustom'=>true,'values'=>[]],['id'=>'348','name'=>'Web Color','required'=>true,'allowCustom'=>false,'values'=>[['id'=>'9001','name'=>'Siyah']]],['id'=>'1192','name'=>'Menşei','required'=>true,'allowCustom'=>false,'values'=>[['id'=>'100','name'=>'TR']]]];
            (new ReflectionProperty(TrendyolAttributeCatalog::class,'memory'))->setValue($c->get(TrendyolAttributeCatalog::class),[$sid.'_1017'=>['attributes'=>$attributes]]);
            // Exercise the durable worker with a local in-memory transport, never a supplier request.
            $repo=$c->get(CatalogAuditRepository::class);$run=$repo->create(1);$repo->put(1,(int)$run['id'],['key'=>'supplier-test','product_id'=>$p['id'],'sku'=>$p['sku'],'name'=>$p['name'],'status'=>'needs_review','checks'=>['errors'=>['Renk eksik']]]);
            $http=new class extends SupplierPageClient {public int $calls=0;public function get(string $url): string{$this->calls++;return str_contains($url,'/arama/')?'<a href="/urun/plastik-tarak-123">Tarak</a>':SupplierCatalogTest::fixture();}};
            $worker=new SupplierCatalog($db,$c->get(TenantContext::class),$c->get(Product::class),$settings,$http);
            self::assertSame(1,$worker->tick(40)['checked']);self::assertSame(2,$http->calls);self::assertSame(0,$worker->tick(40)['checked']);self::assertSame(2,$http->calls,'A fresh cache is not fetched again');
            self::assertNotNull($supplier->evidence($p));
            $entity=['product_id'=>$p['id'],'source_product_id'=>$p['id'],'profile_id'=>$pid,'sku'=>$p['sku'],'remote'=>null];$snap=['sources'=>[$pid=>$import->snapshotForAudit($pid)]];
            $proof=$c->get(CatalogEvidenceEnricher::class)->complete(1,$entity,$snap);self::assertSame(self::URL,$proof['web_source']['url']);
            $own=$c->get(ProductCategoryOverride::class)->forProduct((int)$p['id'],1);$values=json_decode($own['attribute_mapping_json'],true)['products']['id:'.$p['id']];self::assertSame('Siyah',$values[47]);self::assertSame('100',$values[1192]);
            self::assertSame($original,$c->get(Product::class)->find((int)$p['id']));self::assertSame([],$supplier->errors($p));
            file_put_contents($file,$xml(20,3));self::assertTrue($import->run($pid)['ok']);$p=$c->get(Product::class)->find((int)$p['id']);
            self::assertSame(3,(int)$p['stock_qty']);self::assertSame(20.0,(float)$p['cost_price']);self::assertSame([],$supplier->errors($p));
            self::assertSame($own,$c->get(ProductCategoryOverride::class)->forProduct((int)$p['id'],1),'XML refresh preserves supplemental marketplace values');
            self::assertSame(123.0,$c->get(App\Services\PriceRuleEngine::class)->calculatePrice($p,1)['sale_price']);
            self::assertSame(0,$worker->tick(40)['checked']);self::assertSame(2,$http->calls,'Inventory changes do not trigger a supplier metadata recrawl');
            file_put_contents($file,$xml(20,0));self::assertTrue($import->run($pid)['ok']);self::assertSame(0,(int)$c->get(Product::class)->find((int)$p['id'])['stock_qty']);
            $db->query('UPDATE products SET description=? WHERE id=? AND store_id=?',['Changed product',$p['id'],$sid]);$p=$c->get(Product::class)->find((int)$p['id']);self::assertNotEmpty($supplier->errors($p));self::assertNull($supplier->evidence($p),'A changed source cannot reuse earlier supplier facts');
            try{$supplier->acknowledge($p);self::fail('An outdated supplier check cannot be acknowledged');}catch(RuntimeException $e){self::assertStringContainsString('güncel kontrolü',$e->getMessage());}
            $supplier->record($p,SupplierPage::product(self::page(),self::URL));self::assertNotEmpty($supplier->errors($p),'A fresh page does not silently approve changed content');
            $supplier->acknowledge($p);self::assertSame([],$supplier->errors($p));
            $supplier->record($p,SupplierPage::product(str_replace('Siyah','Mavi',self::page()),self::URL));self::assertNotEmpty($supplier->errors($p),'Changed supplier facts require a new review');
            $db->query('UPDATE catalog_supplier_pages SET next_check_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE store_id=?',[$sid]);
            $limited=new class extends SupplierPageClient {public int $calls=0;public function get(string $url):string{$this->calls++;throw new RuntimeException('Tedarikçi sayfası okunamadı (HTTP 429).');}};
            $limitedWorker=new SupplierCatalog($db,$c->get(TenantContext::class),$c->get(Product::class),$settings,$limited);
            self::assertSame(0,$limitedWorker->tick(40)['checked'],'An XML-disabled product is not used for supplier enrichment');
            $db->query('UPDATE products SET stock_qty=2,is_active=1,missing_stock=0 WHERE store_id=? AND id=?',[$sid,$p['id']]);
            self::assertSame(1,$limitedWorker->tick(40)['checked']);self::assertSame(0,$limitedWorker->tick(40)['checked']);self::assertSame(1,$limited->calls,'Rate-limit errors back off instead of requesting the same item every minute');
            $settings->save($pid,['kar_degeri'=>115,'kargo_tutari'=>80]);self::assertSame('toptanarabul',$settings->forProfile($pid)['tedarikci_bilgisi'],'A pre-existing form submission cannot silently disable source completion');
            $c->get(TenantContext::class)->setStoreId(2147483647);self::assertNull($supplier->evidence($p));self::assertSame([],$supplier->summary());
        }finally{$db->rollBack();$db->query('SET time_zone=?',[$sqlTimezone]);date_default_timezone_set($timezone);@unlink($file);$_ENV=$env;}
    }
    public static function fixture(): string{return self::page();}
}
