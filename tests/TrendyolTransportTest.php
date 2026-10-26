<?php
declare(strict_types=1);
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Services\CryptoService;
use App\Services\Marketplace\TrendyolService;
use PHPUnit\Framework\TestCase;

/** Opt-in local transport tests; all HTTP stays on 127.0.0.1:8091. */
final class TrendyolTransportTest extends TestCase
{
    private ?PDO $pdo=null;
    private ?Database $db=null;
    private array $savedEnv=[];
    private ?TrendyolService $service=null;
    protected function setUp(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1') $this->markTestSkipped('Start tests/fixtures/trendyol-api.php on 127.0.0.1:8091; set TRENDMAX_LOCAL_INTEGRATION=1.');
        $this->savedEnv=$_ENV;
        foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES) as $line) {
            if(trim($line)==='' || str_starts_with(trim($line),'#') || !str_contains($line,'='))continue;
            [$key,$value]=explode('=',$line,2);$_ENV[trim($key)]=trim($value," \t\"'");
        }
        if(($_ENV['DB_NAME']??'')!=='trendmax_local' || ($_ENV['DB_HOST']??'')!=='127.0.0.1') throw new RuntimeException('Only the local test DB is supported.');
        $db=new Database();$this->db=$db;$this->pdo=$db->getConnection();$this->pdo->beginTransaction();
        $tenant=new TenantContext();$tenant->setStoreId(1);
        $model=new Marketplace($db,$tenant);$crypto=new CryptoService();
        $model->saveStoreConfig(1,['is_enabled'=>1,'api_key_encrypted'=>$crypto->encrypt('local-test-key'),'api_secret_encrypted'=>$crypto->encrypt('local-test-secret'),'extra_config_json'=>'{"supplier_id":"999"}']);
        $this->service=new TrendyolService(new Logger(),$crypto,$model);
        (new ReflectionProperty(App\Services\Marketplace\AbstractMarketplace::class,'baseUrl'))->setValue($this->service,'http://127.0.0.1:8091/integration');
    }
    protected function tearDown(): void
    {
        if($this->pdo?->inTransaction())$this->pdo->rollBack();
        if($this->savedEnv!==[])$_ENV=$this->savedEnv;
    }
    public function testStockOnlyUpdateDoesNotSendZeroPrices(): void
    {
        $r=$this->service->updateListingProducts([['barcode'=>'STOCK-ONLY','quantity'=>0]]);
        self::assertTrue($r['success']);self::assertSame('local-stock-only',$r['batchRequestId']);
    }
    public function testV2MetadataAndSeparatelyPaginatedOptions(): void
    {
        $attributes=$this->service->fetchCategoryAttributes('1017');
        self::assertTrue($attributes[0]['required']);self::assertSame([],$attributes[0]['values']);
        $values=$this->service->fetchCategoryAttributeValues('1017','14');
        self::assertSame(['501','502'],array_column($values,'id'));
        self::assertSame(['Plastik','Ahşap'],array_column($values,'name'));
    }
    public function test429RespectsRetryAfterHeaderBeforeRetrying(): void
    {
        $counter=BASE_PATH.'/storage/local/trendyol-fixture-retries';
        @unlink($counter);
        $start=microtime(true);
        try {
            $attributes=$this->service->fetchCategoryAttributes('429');
            self::assertSame(47,$attributes[0]['id']);
            self::assertSame('2',file_get_contents($counter));
            self::assertGreaterThanOrEqual(2.8,microtime(true)-$start);
        }finally{@unlink($counter);}
    }
    public function testTwentyOneMappedItemsReachV2TransportWithoutLosingAttributes(): void
    {
        $items=[];
        for($i=0;$i<21;$i++)$items[]=['barcode'=>'LOCAL-'.$i,'attributes'=>[['attributeId'=>14,'attributeValueId'=>501]]];
        $result=$this->service->createProducts($items);
        self::assertTrue($result['success']);self::assertSame('local-contract-21',$result['batchRequestId']);
    }
    public function testExistingListingUsesDedicatedPriceStockEndpointWithCappedStock(): void
    {
        $result=$this->service->updateListingProducts([['barcode'=>'EXISTING','quantity'=>20000,'salePrice'=>165,'listPrice'=>165,'title'=>'Do not recreate']]);
        self::assertTrue($result['success']);
        self::assertSame('local-listing-update',$result['batchRequestId']);
    }

    public function testPriceOnlyUpdateOmitsStock(): void
    {
        $result=$this->service->updateListingProducts([['barcode'=>'PRICE-ONLY','salePrice'=>215,'listPrice'=>215]]);
        self::assertTrue($result['success']);
        self::assertSame('local-price-only',$result['batchRequestId']);
    }

    public function testRegularStockSyncAlsoCapsTheOutboundQuantity(): void
    {
        $items=[['barcode'=>'EXISTING','quantity'=>20000,'salePrice'=>165,'listPrice'=>165]];
        $result=$this->service->updateStock($items);
        self::assertSame(1,$result['success']);self::assertSame(0,$result['failed']);
        self::assertSame(20000,$items[0]['quantity']);
    }

    public function testCatalogCountersUseRemoteTotalsAndCorrectStatuses(): void
    {
        self::assertSame(['approved'=>37,'on_sale'=>31,'not_on_sale'=>6,'pending'=>13,'rejected'=>2], $this->service->fetchCatalogStatusCounts());
    }

    public function testCatalogSnapshotCachesAndKeepsPreviousCountsOnApiFailure(): void
    {
        $tenant = new TenantContext(); $tenant->setStoreId(1);
        $settings = new App\Models\StoreSetting($this->db, $tenant);
        $settings->setValue('trendyol_catalog_status_v1', '{}');
        $reader = new App\Services\MarketplaceCatalogStatus($settings, $this->service, new Marketplace($this->db, $tenant));
        $first = $reader->refresh();
        self::assertSame(31, $first['counts']['on_sale']);
        self::assertFalse($first['error']);
        $otherAccount = $first; $otherAccount['account'] = 'different-seller';
        $settings->setValue('trendyol_catalog_status_v1', json_encode($otherAccount));
        self::assertSame([], $reader->read(), 'Another seller snapshot is never reused');
        $settings->setValue('trendyol_catalog_status_v1', json_encode($first));
        (new ReflectionProperty(App\Services\Marketplace\AbstractMarketplace::class,'baseUrl'))->setValue($this->service,'http://127.0.0.1:8091/invalid');
        (new ReflectionProperty(App\Services\Marketplace\AbstractMarketplace::class,'maxRetries'))->setValue($this->service,0);
        self::assertSame($first, $reader->refresh(), 'Fresh snapshot avoids API requests');
        $first['attempted_at'] = time()-601;
        $settings->setValue('trendyol_catalog_status_v1', json_encode($first));
        $failed = $reader->refresh();
        self::assertTrue($failed['error']);
        self::assertSame($first['counts'], $failed['counts']);
        self::assertSame($first['checked_at'], $failed['checked_at']);
        $settings->setValue('trendyol_catalog_status_v1', '{}');
        $unknown = $reader->refresh();
        self::assertTrue($unknown['error']);
        self::assertArrayNotHasKey('counts', $unknown, 'Unavailable never becomes zero or local totals');
    }

    public function testOrderV2ReadsEveryPageIncludingVerifiedStatus(): void
    {
        $orders=$this->service->fetchOrders(['startDate'=>1792600000000,'endDate'=>1792700000000]);
        self::assertCount(2,$orders);
        self::assertSame(['yeni','yeni'],array_column($orders,'status'));
        self::assertSame(215.0,$orders[0]['total_amount']);
        self::assertSame('LOCAL',$orders[1]['items'][0]['sku']);
        $zone=date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Istanbul');
            $istanbul=$this->service->fetchOrders();
            self::assertSame(gmdate('Y-m-d H:i:s',1792660000),$istanbul[0]['ordered_at']);
        } finally {date_default_timezone_set($zone);}
    }
    public function testQuestionsReadAllPagesAndClaimsUseConfiguredServer(): void
    {
        self::assertSame([200,201],array_column($this->service->getQuestions(),'id'));
        self::assertSame('claim-test',$this->service->fetchReturnsResponse(['page'=>0])['content'][0]['id']);
    }

}
