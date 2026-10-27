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
   self::assertSame(0,$result['sent'],json_encode($result));self::assertSame(1,$result['pending']);self::assertSame(0,$result['failed']);
   $map=$c->get(MarketplaceProduct::class)->find($mid);self::assertSame(1,(int)$map['is_listed']);self::assertSame('RAW80',$map['marketplace_barcode']);self::assertSame(86.0,(float)$map['last_price_sent']);self::assertSame(498,(int)$map['last_stock_sent']);
   // Remote still reports 498: the rejected/pending zero must remain eligible.
   self::assertCount(1,$c->get(MarketplaceProduct::class)->getStockChanged(1));
   self::assertSame(40.0,(float)$c->get(Product::class)->find($pid)['cost_price']);
   // The immediate order/return path must also send stock only and leave receipt unconfirmed.
   self::assertTrue($c->get(\App\Services\StockManager::class)->syncStockToMarketplace($pid,1));
   $map=$c->get(MarketplaceProduct::class)->find($mid);
   self::assertSame(498,(int)$map['last_stock_sent']);
   self::assertSame(86.0,(float)$map['last_price_sent']);
   $model=$c->get(MarketplaceProduct::class);
   $model->update($mid,['sync_error'=>'Batch kontrol bekliyor: old','updated_at'=>'2000-01-01 00:00:00']);
   $pid2=$c->get(Product::class)->create(['sku'=>'SECOND','name'=>'Second pending','barcode'=>'NEW','stock_qty'=>1]);
   $mid2=$model->create(['product_id'=>$pid2,'marketplace_id'=>1,'sync_error'=>'Batch kontrol bekliyor: new','marketplace_barcode'=>'SENT','updated_at'=>'2001-01-01 00:00:00']);
   self::assertSame($mid,(int)$model->getPendingBatches(1,1)[0]['id']);
   $model->update($mid,['updated_at'=>date('Y-m-d H:i:s')]);
   $next=$model->getPendingBatches(1,1)[0];
   self::assertSame($mid2,(int)$next['id']);
   self::assertSame('SENT',$next['barcode']);
   $duplicate=$c->get(Product::class)->create(['sku'=>'DUP-SKU','name'=>'Duplicate fixture','barcode'=>'NEW-DUP','stock_qty'=>7,'cost_price'=>100]);
   $duplicateMap=$model->create(['product_id'=>$duplicate,'marketplace_id'=>1,'marketplace_barcode'=>'NEW-DUP','sync_error'=>'farklı barkodlu bir ürün mevcut. Barkod: OLD-DUP']);
   $publisher=$c->get(\App\Services\ProductPublishService::class);
   $resolved=$publisher->resolveExistingTrendyolListing($duplicate,1);
   self::assertSame('OLD-DUP',$resolved['marketplace_barcode']);self::assertSame(1,$resolved['is_listed']);
   self::assertSame(7,(int)$c->get(Product::class)->find($duplicate)['stock_qty']);
   self::assertSame(100.0,(float)$c->get(Product::class)->find($duplicate)['cost_price']);
   $model->update($duplicateMap,['is_listed'=>0,'sync_error'=>'farklı barkodlu bir ürün mevcut. Barkod: OLD-REJECT']);
   $resolved=$publisher->resolveExistingTrendyolListing($duplicate,1);
   self::assertSame(0,$resolved['is_listed']);self::assertStringContainsString('Fixture content rejected',$resolved['sync_error']);
   $retry=$publisher->publishProducts([$duplicate],1);
   self::assertSame(1,$retry['skipped']);self::assertSame(0,$retry['success']);self::assertEmpty($retry['batch_ids']);
   $c->get(Product::class)->create(['sku'=>'CONFLICT','name'=>'Another local owner','barcode'=>'OLD-REJECT','stock_qty'=>2]);
   try { $publisher->resolveExistingTrendyolListing($duplicate,1); self::fail('Conflicting owner must not be overwritten'); }
   catch(RuntimeException $e){ self::assertStringContainsString('çakışıyor',$e->getMessage()); }


  }finally{$db->rollBack();$_ENV=$env;}
 }
}
