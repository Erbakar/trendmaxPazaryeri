<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Marketplace,Product,MarketplaceProduct};
use App\Services\CryptoService;
use App\Services\Marketplace\{AbstractMarketplace,TrendyolService};
use App\Services\Sync\StockSyncService;
use PHPUnit\Framework\TestCase;
final class StockSyncReconciliationTest extends TestCase
{
 public function testClosedListingSendsOnlyZeroAndQueueReceiptDoesNotConfirmStock(): void
 {
  if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB and loopback fixture only.');
  $env=$_ENV;
  foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$l){if(str_contains($l,'=')&&!str_starts_with(trim($l),'#')){[$k,$v]=explode('=',$l,2);$_ENV[trim($k)]=trim($v," \t\"'");}}
  if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Only local test DB.');
  $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();
  try{
   $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Price reconciliation test','recon-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$c->get(TenantContext::class)->setStoreId($sid);
   $db->query("INSERT INTO store_marketplaces(store_id,marketplace_id,status) VALUES(?,1,'active')",[$sid]);
   $crypto=$c->get(CryptoService::class);$c->get(Marketplace::class)->saveStoreConfig(1,['is_enabled'=>1,'api_key_encrypted'=>$crypto->encrypt('local-test-key'),'api_secret_encrypted'=>$crypto->encrypt('local-test-secret'),'extra_config_json'=>'{"supplier_id":"999","shipping_price_addition":80}']);
   $ty=$c->get(TrendyolService::class);(new ReflectionProperty(AbstractMarketplace::class,'baseUrl'))->setValue($ty,'http://127.0.0.1:8091/integration');
   $pid=$c->get(Product::class)->create(['sku'=>'RECON80','name'=>'Reconcile fixture','barcode'=>'TPTRECON80','base_price'=>40,'cost_price'=>40,'list_price'=>40,'stock_qty'=>0,'is_active'=>0]);
   $mid=$c->get(MarketplaceProduct::class)->create(['product_id'=>$pid,'marketplace_id'=>1,'is_active'=>1,'is_listed'=>0,'price_rule_type'=>'percent_increase','price_rule_value'=>115,'last_price_sent'=>166]);
   $result=$c->get(StockSyncService::class)->syncMarketplace(1,'trendyol');
   self::assertSame(1,$result['sent'],json_encode($result));self::assertSame(0,$result['failed']);
   $map=$c->get(MarketplaceProduct::class)->find($mid);self::assertSame(1,(int)$map['is_listed']);self::assertSame('RAW80',$map['marketplace_barcode']);self::assertSame(86.0,(float)$map['last_price_sent']);self::assertSame(498,(int)$map['last_stock_sent']);
   // Remote still reports 498: the rejected/pending zero must remain eligible.
   self::assertCount(1,$c->get(MarketplaceProduct::class)->getStockChanged(1));
   self::assertSame(40.0,(float)$c->get(Product::class)->find($pid)['cost_price']);
   // The immediate order/return path must also send stock only and leave receipt unconfirmed.
   self::assertTrue($c->get(\App\Services\StockManager::class)->syncStockToMarketplace($pid,1));
   $map=$c->get(MarketplaceProduct::class)->find($mid);
   self::assertSame(498,(int)$map['last_stock_sent']);
   self::assertSame(86.0,(float)$map['last_price_sent']);

  }finally{$db->rollBack();$_ENV=$env;}
 }
}
