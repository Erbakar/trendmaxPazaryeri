<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile};
use App\Services\Catalog\{CatalogAuditPolicy as Policy,CatalogImageProbe,CatalogAuditRepository};
use App\Services\Import\XmlProfileImportService;
use PHPUnit\Framework\TestCase;

final class CatalogAuditTest extends TestCase
{
    public function testContentApprovalExpiresForContentButNotStockOrPriceChanges(): void
    {
        $p=['sku'=>'SKU','name'=>'Termos','description'=>'Çelik','main_image'=>'https://example.com/a.jpg','brand_id'=>1];
        $m=['marketplace_category_id'=>'1','attribute_mapping_json'=>'{"defaults":{"color":"red"}}'];
        $hash=Policy::fingerprint($p,$m);
        self::assertSame($hash,Policy::fingerprint($p+['stock_qty'=>99,'base_price'=>3],$m));
        foreach(['name'=>'Ayna','description'=>'Plastik','main_image'=>'https://example.com/b.jpg','brand_id'=>2]as$key=>$value)self::assertNotSame($hash,Policy::fingerprint(array_replace($p,[$key=>$value]),$m));
        self::assertNotSame($hash,Policy::fingerprint($p,array_replace($m,['marketplace_category_id'=>'2'])));
        self::assertNotSame($hash,Policy::fingerprint($p+['_attribute_values'=>[['value'=>'blue']]],$m));
    }
    public function testPendingIsNotApprovedAndZeroRequiresClosureEvenWithMissingCategory(): void
    {
        self::assertSame('pending_approval',Policy::outcome(['errors'=>[]],['status'=>'pendingApproval'],true));
        self::assertSame('rejected',Policy::outcome(['errors'=>[]],['status'=>'rejected'],true));
        self::assertSame('needs_review',Policy::outcome(['errors'=>['Category missing']],null,true));
        self::assertSame('ready',Policy::outcome(['errors'=>[]],null,true));
        self::assertSame('close_required',Policy::outcome(['errors'=>['Category missing']],['quantity'=>3,'onSale'=>true],false));
        self::assertFalse(Policy::sameInventory(['quantity'=>0],['quantity'=>0,'onSale'=>true]));
        self::assertTrue(Policy::sameInventory(['quantity'=>0],['quantity'=>0,'onSale'=>false]));
    }
    public function testSourceAndVariantChecksDoNotTreatAReceiptAsLiveStock(): void
    {
        $p=['sku'=>'X','stock_qty'=>5,'cost_price'=>20,'base_price'=>20,'main_image'=>'http://cdn1.xmlbankasi.com/a.jpg'];
        $s=['sku'=>'X','stock_qty'=>5,'cost_price'=>20,'base_price'=>20,'images'=>['https://cdn1.xmlbankasi.com/a.jpg']];
        self::assertSame([],Policy::sourceErrors($p,$s));
        self::assertCount(1,Policy::sourceErrors($p,array_replace($s,['stock_qty'=>0])));
        self::assertNotEmpty(Policy::sourceErrors($p,null));
        $rows=Policy::remoteRows([['title'=>'X','category'=>['id'=>8],'images'=>[['url'=>'https://example.com/x.jpg']], 'variants'=>[
            ['barcode'=>'A','stockCode'=>'SKU1','stock'=>['quantity'=>3],'price'=>['salePrice'=>120,'listPrice'=>130],'onSale'=>true],
            ['barcode'=>'B','stockCode'=>'SKU2','stock'=>['quantity'=>0],'price'=>['salePrice'=>125,'listPrice'=>130],'onSale'=>false]]]],[]);
        self::assertCount(2,$rows);self::assertSame(0,$rows[1]['quantity']);self::assertSame(120.0,$rows[0]['salePrice']);self::assertSame('8',$rows[0]['categoryId']);
    }
    public function testMissingRemoteQuantityCannotProveClosure(): void
    {
        $this->expectException(RuntimeException::class);Policy::remoteRows([['barcode'=>'A','stockCode'=>'S','onSale'=>false]],[]);
    }
    public function testInvalidBarcodesAreHeldWithoutChangingTheirIdentity(): void
    {
        foreach(['TPTCml-NO:53','TPTCml-DSAYANG NO:7','A/B','A&B',str_repeat('A',41),'']as$barcode)self::assertFalse(Policy::validPublishBarcode($barcode));
        foreach(['SMR-D263-SİYAH','ÜRÜN_1.2',str_repeat('A',40)]as$barcode)self::assertTrue(Policy::validPublishBarcode($barcode));
    }
    public function testIncompleteBarcodeLookupNeverAuthorizesANewListing(): void
    {
        self::assertTrue(Policy::barcodeAbsent(['approved'=>['totalElements'=>0,'content'=>[]],'unapproved'=>['totalElements'=>0,'content'=>[]]]));
        self::assertFalse(Policy::barcodeAbsent(['approved'=>['totalElements'=>1,'content'=>[['barcode'=>'A']]],'unapproved'=>['totalElements'=>0,'content'=>[]]]));
        $rejected=['approved'=>['totalElements'=>0,'content'=>[]],'unapproved'=>['totalElements'=>1,'content'=>[['barcode'=>'A','stockCode'=>'S','status'=>'rejected']]]];
        self::assertTrue(Policy::canReplaceRejected($rejected,'A','S'));
        self::assertFalse(Policy::canReplaceRejected($rejected,'A','OTHER'));
        $rejected['unapproved']['content'][0]['status']='pendingApproval';self::assertFalse(Policy::canReplaceRejected($rejected,'A','S'));
        $this->expectException(RuntimeException::class);Policy::barcodeAbsent(['approved'=>[],'unapproved'=>[]]);
    }
    public function testImageChecksRejectPrivateAddressesAndUncertainPictures(): void
    {
        foreach(['127.0.0.1','10.0.0.1','169.254.169.254','192.168.1.1','::1','::ffff:127.0.0.1']as$ip)self::assertFalse(CatalogImageProbe::publicIp($ip));
        self::assertTrue(CatalogImageProbe::publicIp('8.8.8.8'));
        $a=['ok'=>true,'sha256'=>'a','width'=>100,'height'=>100,'hash'=>str_repeat('01',128),'color'=>[100,100,100]];
        self::assertTrue(CatalogImageProbe::similar($a,$a));
        self::assertFalse(CatalogImageProbe::similar($a,array_replace($a,['ok'=>false])));
        self::assertFalse(CatalogImageProbe::similar($a,array_replace($a,['sha256'=>'b','hash'=>str_repeat('10',128)])));
        self::assertFalse(CatalogImageProbe::similar($a,array_replace($a,['sha256'=>'b','color'=>[200,100,100]])));
    }
    public function testMarketplaceWhiteFrameDoesNotMakeTheSamePhotoLookDifferent(): void
    {
        if(!function_exists('imagecreatetruecolor'))$this->markTestSkipped('GD required for visual matching.');
        $source=imagecreatetruecolor(600,600);imagefill($source,0,0,imagecolorallocate($source,255,255,255));
        for($i=0;$i<14;$i++)imagefilledellipse($source,60+($i*37)%480,70+($i*71)%460,30+$i*4,70,imagecolorallocate($source,30+$i*12,80,180-$i*6));
        $frame=imagecreatetruecolor(1200,1800);imagefill($frame,0,0,imagecolorallocate($frame,255,255,255));imagecopyresampled($frame,$source,0,300,0,0,1200,1200,600,600);
        $encode=static function($im):string{ob_start();imagejpeg($im,null,94);return ob_get_clean();};
        $t=new TenantContext();$t->setStoreId(1);$probe=(new ReflectionClass(CatalogImageProbe::class))->newInstanceWithoutConstructor();$method=new ReflectionMethod($probe,'signature');
        $a=$method->invoke($probe,$encode($source))+['ok'=>true];$b=$method->invoke($probe,$encode($frame))+['ok'=>true];
        self::assertNotSame($a['sha256'],$b['sha256']);self::assertTrue(CatalogImageProbe::similar($a,$b));
        imagefilledrectangle($source,60,70,500,540,imagecolorallocate($source,255,0,0));
        $different=$method->invoke($probe,$encode($source))+['ok'=>true];self::assertFalse(CatalogImageProbe::similar($different,$b));
        imagedestroy($source);imagedestroy($frame);
    }
    public function testDurableStateIsTenantIsolatedAndSourceReadNeverChangesProducts(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB required.');
        $env=$_ENV;foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$line){if(!str_contains($line,'=')||str_starts_with(trim($line),'#'))continue;[$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Local DB only.');
        $c=new Container([]);$db=$c->get(Database::class);$tenant=$c->get(TenantContext::class);$repo=$c->get(CatalogAuditRepository::class);$db->beginTransaction();$file=null;
        try{
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Audit test','audit-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$tenant->setStoreId($sid);
            $pid=$c->get(Product::class)->create(['sku'=>'A','name'=>'A','stock_qty'=>9,'is_active'=>1]);
            $run=$repo->create(1);$item=['key'=>'product:'.$pid,'product_id'=>$pid,'status'=>'ready','checks'=>['errors'=>[]]];$repo->put(1,(int)$run['id'],$item);
            $repo->review(1,$pid,str_repeat('a',64),['type'=>'test'],null);$action=$repo->intent(1,(int)$run['id'],$item,'publish',['barcode'=>'A']);
            self::assertSame('submitting',$repo->pending(1,$item['key'])['status']);
            // A new process/repository sees the pre-request intent after a crash.
            $again=new CatalogAuditRepository($db,$tenant);self::assertSame($action,(int)$again->pending(1,$item['key'])['id']);
            $audit=$c->get(App\Services\Catalog\CatalogAuditService::class);
            self::assertSame(['paused'=>true],$audit->tick());
            $probe=$c->get(CatalogImageProbe::class);$url='http://127.0.0.1/catalog-audit-test';
            self::assertFalse($probe->inspect($url)['ok']);
            self::assertSame(1,(int)$db->fetchOne('SELECT COUNT(*) n FROM catalog_image_checks WHERE store_id=?',[$sid])['n']);
            self::assertSame($probe->inspect($url),$probe->inspect($url),'Image checks use a DB cache without per-image files');

            $dispatch=new ReflectionMethod($audit,'dispatch');
            $held=$dispatch->invoke($audit,1,(int)$run['id'],$item+['remote'=>null],['source_at'=>time(),'remote_at'=>date(DATE_ATOM)]);
            self::assertSame('awaiting_result',$held['status'],'A crashed request cannot be sent twice');
            $inspect=new ReflectionMethod($audit,'inspect');
            $entity=['key'=>'remote:zero','product_id'=>$pid,'source_product_id'=>$pid,'profile_id'=>999,'sku'=>'A','identity_error'=>null,'linked'=>false,
                'remote'=>['barcode'=>'A','quantity'=>8,'onSale'=>true,'name'=>'A','status'=>'approved','categoryId'=>'','images'=>[],'blocked'=>false]];
            $closed=$inspect->invoke($audit,1,$entity,['source_at'=>time(),'remote_at'=>date(DATE_ATOM),'sources'=>[999=>['checked_at'=>date(DATE_ATOM),'rows'=>[]]]]);
            self::assertSame('close_required',$closed['status']);
            $unknown=$inspect->invoke($audit,1,$entity,['source_at'=>time(),'remote_at'=>date(DATE_ATOM),'sources'=>[999=>['checked_at'=>date(DATE_ATOM),'error'=>'Source unavailable']]]);
            self::assertSame('needs_review',$unknown['status']);self::assertContains('Source unavailable',$unknown['checks']['errors']);

            $wait=$dispatch->invoke($audit,1,(int)$run['id'],$closed,['source_at'=>time(),'remote_at'=>date(DATE_ATOM)]);
            self::assertSame('close_required',$wait['status']);self::assertNotEmpty($wait['checks']['errors'],'Stale positive local stock must be imported before the stock guard can safely send zero');
            self::assertNull($repo->pending(1,'remote:zero'));
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Other audit','audit-'.bin2hex(random_bytes(6))]);$other=(int)$db->lastInsertId();$tenant->setStoreId($other);
            self::assertNull($repo->latest(1));self::assertNull($repo->pending(1,$item['key']));self::assertFalse($repo->reviewed(1,$pid,str_repeat('a',64)));
            self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) n FROM catalog_image_checks WHERE store_id=?',[$other])['n']);
            $repo->actionResult($action,'confirmed',[]);$tenant->setStoreId($sid);self::assertSame('submitting',$repo->pending(1,$item['key'])['status']);
            $dir=BASE_PATH.'/storage/xmlprofiles';if(!is_dir($dir))mkdir($dir,0700,true);$file=tempnam($dir,'audit-test-');
            $profile=$c->get(XmlProfile::class)->create(['name'=>'Audit file','direction'=>'import','source_type'=>'file','source_url'=>basename($file),'format'=>'generic','is_active'=>1,'field_mapping_json'=>json_encode(['item_tag'=>'Product','sku'=>'Code','name'=>'Name','stock'=>'Stock','price'=>'Price','cost'=>'Price'])]);
            $service=$c->get(XmlProfileImportService::class);
            file_put_contents($file,'<Products><Product><Code>A</Code><Name>A</Name><Stock>0</Stock><Price>20</Price></Product><Product><Code>B</Code><Name>B</Name><Stock>5</Stock><Price>30</Price></Product></Products>');
            $snapshot=$service->snapshotForAudit($profile);self::assertCount(2,$snapshot['rows']);self::assertSame(0,$snapshot['rows']['A']['stock_qty']);
            self::assertSame(9,(int)$c->get(Product::class)->find($pid)['stock_qty']);
            foreach(['<Products/>','<Products><Product>','<Products><Product><Code>A</Code><Name>A</Name></Product></Products>',
                '<Products><Product><Code>A</Code><Name>A</Name><Stock>3</Stock></Product><Product><Code>A</Code><Name>A</Name><Stock>4</Stock></Product></Products>']as$bad){
                file_put_contents($file,$bad);$threw=false;try{$service->snapshotForAudit($profile);}catch(Throwable){$threw=true;}self::assertTrue($threw,'Incomplete, missing stock or duplicate feeds must fail closed');
            }
        }finally{if($file&&is_file($file))unlink($file);$db->rollBack();$tenant->clear();$_ENV=$env;}
    }
}
