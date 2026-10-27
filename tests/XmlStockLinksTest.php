<?php
declare(strict_types=1);

use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile,MarketplaceProduct};
use App\Services\XmlStockLinks;
use PHPUnit\Framework\TestCase;

/** Isolated tenants in a rolled-back local DB transaction. No external API calls. */
final class XmlStockLinksTest extends TestCase
{
    public function testLegacyListingsFollowSourceAndNeverChangePriceOrOtherTenants(): void
    {
        if (getenv('TRENDMAX_LOCAL_INTEGRATION') !== '1') $this->markTestSkipped('Local integration DB required.');
        $env=$_ENV;
        foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES) as $line) {
            if(!str_contains($line,'=') || str_starts_with(trim($line),'#')) continue;
            [$key,$value]=explode('=',$line,2); $_ENV[trim($key)]=trim($value," \t\"'");
        }
        if (($_ENV['DB_NAME']??'')!=='trendmax_local' || ($_ENV['DB_HOST']??'')!=='127.0.0.1') throw new RuntimeException('Local DB only.');
        $c=new Container([]); $db=$c->get(Database::class); $db->beginTransaction();
        try {
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Stock links test','stock-links-'.bin2hex(random_bytes(6))]);
            $sid=(int)$db->lastInsertId(); $tenant=$c->get(TenantContext::class); $tenant->setStoreId($sid);
            $profiles=$c->get(XmlProfile::class); $products=$c->get(Product::class); $links=$c->get(XmlStockLinks::class);
            $profile=$profiles->create(['name'=>'Stock source','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/feed.xml']);
            $otherProfile=$profiles->create(['name'=>'Other source','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/other.xml']);
            $source=$products->create(['sku'=>'RENAMED','barcode'=>'NEW','name'=>'Source','xml_profile_id'=>$profile,'stock_qty'=>17,'is_active'=>1,'cost_price'=>40]);
            $legacy=$products->create(['sku'=>'OLD','barcode'=>'OLD-BARCODE','name'=>'Old title','stock_qty'=>311,'is_active'=>1,'base_price'=>156,'main_image'=>'https://example.com/old.jpg']);
            $c->get(MarketplaceProduct::class)->create(['product_id'=>$legacy,'marketplace_id'=>1,'is_active'=>1,'is_listed'=>1,'stock_offset'=>2,'last_price_sent'=>156]);
            $links->register(1,$profile,['barcode'=>'OLD-BARCODE','source_sku'=>'RENAMED','legacy_product_id'=>$legacy,'match_method'=>'verified_image','evidence'=>['same_image'=>true]]);
            $links->register(1,$profile,['barcode'=>'NO-LOCAL-CARD','source_sku'=>'REMOVED','match_method'=>'historical_source','evidence'=>['historical_source'=>true]]);
            $payload=[['barcode'=>'OLD-BARCODE','quantity'=>311,'salePrice'=>156,'listPrice'=>156],['barcode'=>'NO-LOCAL-CARD','quantity'=>99],['barcode'=>'UNRELATED','quantity'=>8],['barcode'=>'OLD-BARCODE','salePrice'=>156,'listPrice'=>156]];
            $guarded=$links->guardPayload($payload,1);
            self::assertSame(15,$guarded[0]['quantity']); self::assertSame(156,$guarded[0]['salePrice']);
            self::assertSame(0,$guarded[1]['quantity']);self::assertSame($payload[2],$guarded[2]);self::assertSame($payload[3],$guarded[3]);
            self::assertSame($payload,$links->guardPayload($payload,2),'Another marketplace is unchanged');
            $links->refreshLegacyStock($profile);
            $old=$products->find($legacy); self::assertSame(17,(int)$old['stock_qty']);self::assertSame('Old title',$old['name']);self::assertSame(156.0,(float)$old['base_price']);self::assertSame('https://example.com/old.jpg',$old['main_image']);
            $match=new ReflectionMethod(App\Services\Sync\OrderSyncService::class,'matchProduct');
            self::assertSame($source,$match->invoke($c->get(App\Services\Sync\OrderSyncService::class),1,['barcode'=>'OLD-BARCODE','sku'=>'OLD']));
            $products->update($source,['stock_qty'=>0,'missing_stock'=>1]);
            // A cancellation can raise the legacy pool count, but cannot reopen the listing.
            $products->update($legacy,['stock_qty'=>312]);
            self::assertSame(0,$links->guardPayload($payload,1)[0]['quantity']);
            $products->update($source,['stock_qty'=>1]);
            self::assertSame(0,$links->guardPayload($payload,1)[0]['quantity'],'Missing supplier flag beats return quantities');
            $products->update($source,['stock_qty'=>22,'missing_stock'=>0]);
            self::assertSame(20,$links->guardPayload($payload,1)[0]['quantity']);
            // Reappearance automatically binds the previously absent exact source SKU.
            $products->create(['sku'=>'REMOVED','barcode'=>'RETURNED','name'=>'Returned','xml_profile_id'=>$profile,'stock_qty'=>4,'is_active'=>1]);
            self::assertSame(4,$links->guardPayload($payload,1)[1]['quantity']);
            $products->update($legacy,['is_active'=>0]);
            self::assertSame(0,$links->guardPayload($payload,1)[0]['quantity'],'Manual closure is preserved');
            $products->update($legacy,['is_active'=>1]);
            $links->receipt('batch-1',[['barcode'=>'OLD-BARCODE','quantity'=>20],['barcode'=>'NO-LOCAL-CARD','quantity'=>0]]);
            $counts=$links->recordBatch('batch-1',['items'=>[['requestItem'=>['barcode'=>'OLD-BARCODE'],'status'=>'SUCCESS'],['requestItem'=>['barcode'=>'NO-LOCAL-CARD'],'status'=>'FAILED','failureReasons'=>['Rejected']]]]);
            self::assertSame(1,$counts['success']);self::assertSame(1,$counts['failed']);
            $links->observe(1,[['barcode'=>'OLD-BARCODE','stock'=>17,'raw'=>['variant'=>['onSale'=>true]]]]);
            self::assertSame('accepted',$db->fetchOne('SELECT status FROM trendyol_stock_receipts WHERE store_id=? AND barcode=?',[$sid,'OLD-BARCODE'])['status']);
            $links->observe(1,[['barcode'=>'OLD-BARCODE','stock'=>20,'raw'=>['variant'=>['onSale'=>true]]]]);
            self::assertSame('confirmed',$db->fetchOne('SELECT status FROM trendyol_stock_receipts WHERE store_id=? AND barcode=?',[$sid,'OLD-BARCODE'])['status']);
            $db->query('INSERT INTO stores(name,slug) VALUES(?,?)',['Other store','stock-other-'.bin2hex(random_bytes(6))]);
            $otherStore=(int)$db->lastInsertId();$tenant->setStoreId($otherStore);
            self::assertSame([],$links->rows());self::assertSame($payload,$links->guardPayload($payload,1));self::assertNull($links->productForListing(1,'OLD-BARCODE'));
            try {$links->register(1,$profile,['barcode'=>'X','source_sku'=>'RENAMED','match_method'=>'test','evidence'=>['test'=>true]]);self::fail('Cross-store source accepted');}
            catch(RuntimeException $e){self::assertStringContainsString('bu mağazaya ait değil',$e->getMessage());}
        } finally {$db->rollBack();$_ENV=$env;}
    }
}
