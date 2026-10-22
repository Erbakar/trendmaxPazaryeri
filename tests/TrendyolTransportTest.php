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
        $db=new Database();$this->pdo=$db->getConnection();$this->pdo->beginTransaction();
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

}
