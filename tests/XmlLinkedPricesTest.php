<?php
declare(strict_types=1);
use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile,Marketplace};
use App\Services\{XmlStockLinks,XmlLinkedPrices,CryptoService};
use App\Services\Import\XmlProfileSettings;
use App\Services\Marketplace\{AbstractMarketplace,TrendyolService};
use App\Services\Sync\PriceSyncService;
use PHPUnit\Framework\TestCase;
final class XmlLinkedPricesTest extends TestCase
{
    public function testPriceOwnershipRequiresOptInAndReachesAliasesWithoutChangingStock(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1')$this->markTestSkipped('Local DB/fixture only.');
        $env=$_ENV;foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES)as$l)if(str_contains($l,'=')&&!str_starts_with(trim($l),'#')){[$k,$v]=explode('=',$l,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Local DB only.');
        $c=new Container([]);$db=$c->get(Database::class);$db->beginTransaction();
        try{
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Linked price test','linked-price-'.bin2hex(random_bytes(6))]);$sid=(int)$db->lastInsertId();$tenant=$c->get(TenantContext::class);$tenant->setStoreId($sid);
            $db->query("INSERT INTO store_marketplaces(store_id,marketplace_id,status) VALUES(?,1,'active')",[$sid]);
            $crypto=$c->get(CryptoService::class);$mp=$c->get(Marketplace::class);$mp->saveStoreConfig(1,['is_enabled'=>1,'api_key_encrypted'=>$crypto->encrypt('local-key'),'api_secret_encrypted'=>$crypto->encrypt('local-secret'),'extra_config_json'=>'{"supplier_id":"999","shipping_price_addition":80}']);
            $ty=$c->get(TrendyolService::class);(new ReflectionProperty(AbstractMarketplace::class,'baseUrl'))->setValue($ty,'http://127.0.0.1:8091/integration');
            $profile=$c->get(XmlProfile::class)->create(['name'=>'Source','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/feed']);
            $c->get(XmlProfileSettings::class)->save($profile,['kar_tipi'=>'yuzde','kar_degeri'=>115]);
            $product=$c->get(Product::class);$pid=$product->create(['sku'=>'REAL-SOURCE','barcode'=>'NEW','name'=>'Source','xml_profile_id'=>$profile,'cost_price'=>40,'base_price'=>40,'stock_qty'=>5,'is_active'=>1]);
            $links=$c->get(XmlStockLinks::class);$links->register(1,$profile,['barcode'=>'RAW80','source_sku'=>'REAL-SOURCE','match_method'=>'verified_image','evidence'=>['same_image'=>true]]);
            $prices=$c->get(XmlLinkedPrices::class);$payload=[['barcode'=>'RAW80','salePrice'=>86,'listPrice'=>86],['barcode'=>'RAW80','quantity'=>0],['barcode'=>'UNRELATED','salePrice'=>7]];
            self::assertSame($payload,$prices->guard($payload,1));
            $mp->saveStoreConfig(1,['extra_config_json'=>'{"supplier_id":"999","shipping_price_addition":80,"xml_linked_prices":true}']);
            $guarded=$prices->guard($payload,1);self::assertSame(166.0,$guarded[0]['salePrice']);self::assertSame(166.0,$guarded[0]['listPrice']);self::assertSame($payload[1],$guarded[1]);self::assertSame($payload[2],$guarded[2]);self::assertArrayNotHasKey('quantity',$guarded[0]);
            $result=$c->get(PriceSyncService::class)->syncMarketplace(1,'trendyol');self::assertSame(1,$result['sent'],json_encode($result));self::assertSame(0,$result['failed']);self::assertSame(5,(int)$product->find($pid)['stock_qty']);self::assertSame(40.0,(float)$product->find($pid)['cost_price']);
            $product->update($pid,['missing_stock'=>1]);self::assertSame([],$prices->all(1));$product->update($pid,['missing_stock'=>0]);
            $tenant->setStoreId(2147483647);self::assertSame([],$prices->all(1));self::assertSame($payload,$prices->guard($payload,1));
        }finally{$db->rollBack();$_ENV=$env;}
    }
}
