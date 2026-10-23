<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\Marketplace;
use App\Services\PriceRuleEngine;
use App\Services\Import\XmlProfileSettings;
use PHPUnit\Framework\TestCase;
final class ShippingPriceAdditionTest extends TestCase
{
 public function testShippingIsAddedOnceAfterProfitRoundingAndRemainsStoreScoped(): void
 {
  if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB integration only.');
  $env=$_ENV;
  foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$line){if(!str_contains($line,'=')||str_starts_with(trim($line),'#'))continue;[$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");}
  if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Only local test DB.');
  $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();
  try{
   $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Shipping test','shipping-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$tenant=$c->get(TenantContext::class);$tenant->setStoreId($sid);
   $c->get(XmlProfileSettings::class)->save(12345,['kar_tipi'=>'yuzde','kar_degeri'=>115]);
   $mp=$c->get(Marketplace::class);$mp->saveStoreConfig(1,['extra_config_json'=>'{"shipping_price_addition":50}']);
   $engine=$c->get(PriceRuleEngine::class);$product=['id'=>0,'base_price'=>168.75,'cost_price'=>168.75,'list_price'=>168.75,'xml_profile_id'=>12345];
   self::assertSame(413.0,$engine->calculatePrice($product,1)['sale_price']);
   self::assertSame(413.0,$engine->calculatePrice($product,1)['list_price']);
   self::assertSame(413.0,$engine->calculatePrice($product,1)['sale_price']);
   self::assertSame(168.75,$product['cost_price']);
   $mp->saveStoreConfig(1,['extra_config_json'=>'{"shipping_price_addition":25}']);
   self::assertSame(388.0,$engine->calculatePrice($product,1)['sale_price']);
   $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Other shipping test','shipping-'.bin2hex(random_bytes(6))]);$tenant->setStoreId((int)$db->lastInsertId());
   self::assertSame(0.0,$engine->shippingAddition(1));
  }finally{$db->rollBack();$_ENV=$env;}
 }
}
