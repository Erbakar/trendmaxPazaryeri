<?php
declare(strict_types=1);
use App\Core\Database;
use App\Core\TenantContext;
use App\Services\TenantExportService;
use PHPUnit\Framework\TestCase;
final class TenantArchiveTest extends TestCase
{
    public function testRestoreIsAtomicTenantScopedAndNeverOverwrites(): void
    {
        if (getenv('TRENDMAX_ARCHIVE_TEST') !== '1') $this->markTestSkipped('Local isolated DB integration test.');
        $before = $_ENV;
        foreach (file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line)==='' || str_starts_with(trim($line),'#') || !str_contains($line,'=')) continue;
            [$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");
        }
        if (($_ENV['DB_NAME']??'')!=='trendmax_local' || ($_ENV['DB_HOST']??'')!=='127.0.0.1') throw new RuntimeException('Only isolated local DB.');
        $db = new Database();$pdo=$db->getConnection();$sid=0;$file=tempnam(sys_get_temp_dir(),'trendmax-archive-');
        try {
            $pdo->prepare('INSERT INTO stores (name,slug) VALUES (?,?)')->execute(['Archive Test','archive-test-'.bin2hex(random_bytes(6))]);
            $sid=(int)$pdo->lastInsertId();$tenant=new TenantContext();$tenant->setStoreId($sid);$service=new TenantExportService($db,$tenant);
            $archive=$service->exportCurrentStore();
            $pdo->prepare('INSERT INTO brands (store_id,name,slug) VALUES (?,?,?)')->execute([$sid,'Restore fixture','restore-fixture']);
            $brand=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO products (store_id,sku,name,brand_id) VALUES (?,?,?,?)')->execute([$sid,'ARCHIVE-TEST','Fixture',$brand]);
            $product=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE products SET cost_price=100,base_price=100,list_price=100 WHERE id=?')->execute([$product]);
            $productModel=new App\Models\Product($db,$tenant);
            $engine=new App\Services\PriceRuleEngine(new App\Models\PriceRule($db,$tenant),new App\Models\MarketplaceProduct($db,$tenant),$productModel,new App\Core\Logger(),new App\Services\Import\XmlProfileSettings(new App\Models\StoreSetting($db,$tenant)));
            $calculator=new App\Services\Publish\MarginCalculator($engine,$productModel);
            $preview=$calculator->rows([$product],1,[],['price_rule_type'=>'percent_increase','price_rule_value'=>65]);
            self::assertSame(165.0,$preview[0]['sale_price']);
            self::assertSame(165.0,$preview[0]['list_price']);
            $settings=new App\Services\Import\XmlProfileSettings(new App\Models\StoreSetting($db,$tenant));
            $settings->save(12345,['kar_tipi'=>'yuzde','kar_degeri'=>65]);
            $price=$engine->calculatePrice(['id'=>$product,'base_price'=>200,'cost_price'=>100,'list_price'=>100,'xml_profile_id'=>12345],1);
            self::assertSame(165.0,$price['sale_price']);
            self::assertStringStartsWith('xml_profile:12345:',$price['rule_applied']);
            $archive=$service->exportCurrentStore();
            $pdo->prepare('DELETE FROM products WHERE store_id=?')->execute([$sid]);
            $pdo->prepare('DELETE FROM brands WHERE store_id=?')->execute([$sid]);
            file_put_contents($file,json_encode($archive,JSON_THROW_ON_ERROR));
            $counts=$service->importIntoCurrentStore($file,true);
            self::assertSame(1,$counts['products']);
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM products WHERE store_id={$sid}")->fetchColumn());
            $service->importIntoCurrentStore($file);
            self::assertSame($brand,(int)$pdo->query("SELECT brand_id FROM products WHERE id={$product}")->fetchColumn());
            try {$service->importIntoCurrentStore($file);self::fail('Non-empty store accepted');}catch(RuntimeException $e){self::assertStringContainsString('boş değil',$e->getMessage());}
            $pdo->prepare('DELETE FROM products WHERE store_id=?')->execute([$sid]);
            $pdo->prepare('DELETE FROM brands WHERE store_id=?')->execute([$sid]);
            $originalProductId=$archive['tables']['products'][0]['id'];
            $existingId=(int)$pdo->query('SELECT id FROM products WHERE store_id=1 LIMIT 1')->fetchColumn();
            $archive['tables']['products'][0]['id']=$existingId;
            file_put_contents($file,json_encode($archive));
            try {$service->importIntoCurrentStore($file);self::fail('Duplicate identity accepted');}catch(PDOException $e){self::assertSame(1062,(int)$e->errorInfo[1]);}
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM brands WHERE store_id={$sid}")->fetchColumn());
            $archive['tables']['products'][0]['id']=$originalProductId;
            $archive['tables']['products'][0]['brand_id']=99999999;
            file_put_contents($file,json_encode($archive));
            try {$service->importIntoCurrentStore($file);self::fail('Missing reference accepted');}catch(RuntimeException $e){self::assertStringContainsString('ilişkili kayıt',$e->getMessage());}
            self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM brands WHERE store_id={$sid}")->fetchColumn());
            $archive['store_id']=1;file_put_contents($file,json_encode($archive));
            try {$service->importIntoCurrentStore($file);self::fail('Other store accepted');}catch(RuntimeException $e){self::assertStringContainsString('ait olduğu mağazaya',$e->getMessage());}
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();
            if($sid){$pdo->prepare('DELETE FROM products WHERE store_id=?')->execute([$sid]);$pdo->prepare('DELETE FROM brands WHERE store_id=?')->execute([$sid]);$pdo->prepare('DELETE FROM stores WHERE id=?')->execute([$sid]);}
            @unlink($file);$_ENV=$before;
        }
    }
}
