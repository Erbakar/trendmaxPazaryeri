<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,Marketplace,MarketplaceProduct};
use App\Services\{CryptoService,TrendyolPendingPublishResolver};
use App\Services\Marketplace\{AbstractMarketplace,TrendyolService};
use PHPUnit\Framework\TestCase;
final class TrendyolPendingPublishResolverTest extends TestCase
{
    private array $env=[];
    private Container $c;
    private Database $db;
    private MarketplaceProduct $listings;
    protected function setUp(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local fixture only.');
        $this->env=$_ENV;
        foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$l)if(str_contains($l,'=')&&!str_starts_with(trim($l),'#')){[$k,$v]=explode('=',$l,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Local DB only.');
        $this->c=new Container([]);$this->db=$this->c->get(Database::class);$this->db->beginTransaction();
        $this->db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Receipt test','receipt-'.bin2hex(random_bytes(6))]);
        $this->c->get(TenantContext::class)->setStoreId((int)$this->db->lastInsertId());
        $crypto=$this->c->get(CryptoService::class);$this->c->get(Marketplace::class)->saveStoreConfig(1,['is_enabled'=>1,'api_key_encrypted'=>$crypto->encrypt('local-key'),'api_secret_encrypted'=>$crypto->encrypt('local-secret'),'extra_config_json'=>'{"supplier_id":"999"}']);
        $this->endpoint('receipts');$this->listings=$this->c->get(MarketplaceProduct::class);
    }
    protected function tearDown(): void
    {
        if(isset($this->db)&&$this->db->getConnection()->inTransaction())$this->db->rollBack();
        if($this->env)$_ENV=$this->env;
    }
    private function endpoint(string $path): void
    {
        (new ReflectionProperty(AbstractMarketplace::class,'baseUrl'))->setValue($this->c->get(TrendyolService::class),'http://127.0.0.1:8091/'.$path.'/integration');
    }
    private function row(string $sku,string $batch='expired',bool $listed=false): int
    {
        $pid=$this->c->get(Product::class)->create(['sku'=>$sku,'barcode'=>$sku,'name'=>'Fixture '.$sku,'stock_qty'=>4,'base_price'=>123,'is_active'=>1]);
        $this->listings->upsertByProductAndMarketplace($pid,1,['is_listed'=>(int)$listed,'marketplace_sku'=>$sku,'marketplace_barcode'=>$sku,'last_price_sent'=>123,'last_stock_sent'=>4,'last_synced_at'=>date('Y-m-d H:i:s',time()-($batch==='expired'?18000:30)),'sync_error'=>'Batch kontrol bekliyor: '.$batch]);
        return $pid;
    }
    public function testExpiredRowsUseCompleteCatalogAndDoNotAlterProducts(): void
    {
        $ids=[];foreach(['OK','REJECT','WAIT','ABSENT','CONFLICT']as$sku)$ids[$sku]=$this->row($sku);
        $before=$this->db->fetchAll('SELECT * FROM products WHERE store_id=?',[$this->c->get(TenantContext::class)->currentStoreId()]);
        $r=$this->c->get(TrendyolPendingPublishResolver::class)->resolve(1,$this->listings->getPendingBatches(1));
        self::assertSame(1,$r['approved']);self::assertSame(1,$r['rejected']);self::assertSame(1,$r['expired']);self::assertSame(2,$r['pending']);self::assertCount(1,$r['errors']);
        self::assertSame(1,(int)$this->listings->findByProductAndMarketplace($ids['OK'],1)['is_listed']);
        self::assertStringContainsString('Yeniden gönderilebilir',$this->listings->findByProductAndMarketplace($ids['ABSENT'],1)['sync_error']);
        self::assertStringContainsString('Fixture rejection',$this->listings->findByProductAndMarketplace($ids['REJECT'],1)['sync_error']);
        self::assertStringStartsWith('Batch kontrol bekliyor:',$this->listings->findByProductAndMarketplace($ids['WAIT'],1)['sync_error']);
        self::assertSame($before,$this->db->fetchAll('SELECT * FROM products WHERE store_id=?',[$this->c->get(TenantContext::class)->currentStoreId()]));
    }
    public function testTemporaryCatalogueOverlapIsResolvedByDirectBarcodeLookup(): void
    {
        $id=$this->row('TRANSIENT');
        $result=$this->c->get(TrendyolPendingPublishResolver::class)->resolve(1,$this->listings->getPendingBatches(1));
        self::assertSame(1,$result['approved']);self::assertSame([],$result['errors']);
        self::assertSame(1,(int)$this->listings->findByProductAndMarketplace($id,1)['is_listed']);
    }
    public function testBatchSuccessAloneIsNotApprovalAndFailedUpdatesRemainFailures(): void
    {
        $absent=$this->row('ABSENT','success');$ok=$this->row('OK','failed',true);
        $r=$this->c->get(TrendyolPendingPublishResolver::class)->resolve(1,$this->listings->getPendingBatches(1));
        self::assertSame(0,$r['approved']);self::assertSame(1,$r['pending']);self::assertSame(1,$r['rejected']);
        self::assertSame(0,(int)$this->listings->findByProductAndMarketplace($absent,1)['is_listed']);
        self::assertStringContainsString('Failed update',$this->listings->findByProductAndMarketplace($ok,1)['sync_error']);
    }
    public function testOnePassHasABoundedReceiptRequestBudget(): void
    {
        for($i=0;$i<25;$i++)$this->row('BUDGET-'.$i,'success-'.$i);
        $r=$this->c->get(TrendyolPendingPublishResolver::class)->resolve(1,$this->listings->getPendingBatches(1));
        self::assertSame(20,$r['checked']);self::assertSame(20,$r['pending']);
        self::assertCount(25,$this->listings->getPendingBatches(1));
    }
    public function testXmlSourceLinkedToLegacyListingCannotCreateADuplicate(): void
    {
        $profile=$this->c->get(App\Models\XmlProfile::class)->create(['name'=>'Alias source','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/feed.xml']);
        $products=$this->c->get(Product::class);
        $old=$products->create(['sku'=>'OLD-SOURCE','name'=>'Old listing','stock_qty'=>5,'is_active'=>1]);
        $source=$products->create(['sku'=>'NEW-SOURCE','name'=>'New XML sku','xml_profile_id'=>$profile,'stock_qty'=>5,'is_active'=>1]);
        $this->c->get(App\Services\XmlStockLinks::class)->register(1,$profile,['barcode'=>'LEGACY','source_sku'=>'NEW-SOURCE','legacy_product_id'=>$old,'match_method'=>'verified_rename_image','evidence'=>['fixture'=>true]]);
        $result=$this->c->get(App\Services\ProductPublishService::class)->publishProducts([$source],1);
        self::assertSame(1,$result['skipped']);self::assertSame(0,$result['processing']);
        self::assertStringContainsString('mükerrer ilan açılmadı',$result['errors'][0]['error']);
    }
    public function testPartialCatalogCannotReleaseReceiptForResubmission(): void
    {
        $id=$this->row('ABSENT');$this->endpoint('receipts-incomplete');$before=$this->listings->findByProductAndMarketplace($id,1);
        try{$this->c->get(TrendyolPendingPublishResolver::class)->resolve(1,$this->listings->getPendingBatches(1));self::fail('Partial catalogue was accepted');}
        catch(RuntimeException$e){self::assertStringContainsString('kataloğu tamamlanamadı',$e->getMessage());}
        self::assertSame($before,$this->listings->findByProductAndMarketplace($id,1));
    }
}
