<?php
declare(strict_types=1);
use App\Core\{Database,TenantContext};
use App\Models\{Product,ProductCategoryOverride,CategoryMarketplaceMapping};
use PHPUnit\Framework\TestCase;
final class ProductCategoryOverrideTest extends TestCase
{
    public function testOverridePrecedenceAtomicBulkIsolationAndRefreshPersistence(): void
    {
        if(getenv('TRENDMAX_LOCAL_INTEGRATION')!=='1') $this->markTestSkipped('Isolated local DB only.');
        $before=$_ENV;
        foreach(file(BASE_PATH.'/.env',FILE_IGNORE_NEW_LINES) as $line){if(!str_contains($line,'=')||str_starts_with(trim($line),'#'))continue;[$k,$v]=explode('=',$line,2);$_ENV[trim($k)]=trim($v," \t\"'");}
        if(($_ENV['DB_NAME']??'')!=='trendmax_local'||($_ENV['DB_HOST']??'')!=='127.0.0.1')throw new RuntimeException('Only local isolated DB.');
        $db=new Database();$tenant=new TenantContext();$tenant->setStoreId(1);$own=new ProductCategoryOverride($db,$tenant);$maps=new CategoryMarketplaceMapping($db,$tenant);$products=new Product($db,$tenant);
        $db->beginTransaction();
        try{
            $rows=$db->fetchAll('SELECT * FROM products WHERE store_id=1 AND category_id=19 ORDER BY id LIMIT 2');
            self::assertCount(2,$rows);$ids=array_map('intval',array_column($rows,'id'));
            $targets=$db->fetchAll('SELECT mp_category_id FROM marketplace_categories WHERE marketplace_id=1 AND store_id=0 AND is_leaf=1 AND (is_active IS NULL OR is_active=1) AND (is_available IS NULL OR is_available=1) LIMIT 2');
            $a=(string)$targets[0]['mp_category_id'];$b=(string)$targets[1]['mp_category_id'];
            $default=$maps->findMapping(19,1);
            $own->assign(19,1,$ids,null);self::assertSame($default,$maps->forProduct($rows[0],1));
            self::assertSame(2,$own->assign(19,1,$ids,$a));
            self::assertSame($a,$maps->forProduct($rows[0],1)['marketplace_category_id']);
            $override=$own->forProduct($ids[0],1);
            $own->saveAttributes((int)$override['id'],$a,['47'=>'test'],[],$ids[0]);
            self::assertSame('test',CategoryMarketplaceMapping::productAttributeValues($maps->forProduct($rows[0],1),'id:'.$ids[0])['47']);
            $own->assign(19,1,[$ids[0]],$a);
            self::assertSame('test',CategoryMarketplaceMapping::productAttributeValues($maps->forProduct($rows[0],1),'id:'.$ids[0])['47']);
            $db->query('UPDATE products SET name=name,category_id=19 WHERE id=? AND store_id=1',[$ids[0]]);
            self::assertSame($a,$maps->forProduct($products->find($ids[0]),1)['marketplace_category_id']);
            $own->assign(19,1,[$ids[0]],$b);
            self::assertSame([],CategoryMarketplaceMapping::productAttributeValues($maps->forProduct($rows[0],1),'id:'.$ids[0]));
            try{$own->saveAttributes((int)$override['id'],$a,['47'=>'old'],[],$ids[0]);self::fail('Stale category accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('Kategori değişti',$e->getMessage());}
            $outside=(int)$db->fetchOne('SELECT id FROM products WHERE store_id=1 AND category_id<>19 LIMIT 1')['id'];
            try{$own->assign(19,1,[$ids[0],$outside],$a);self::fail('Cross category accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('ait değil',$e->getMessage());}
            self::assertSame($b,$maps->forProduct($rows[0],1)['marketplace_category_id']);
            $tenant->setStoreId(2147483647);self::assertFalse($own->forProduct($ids[0],1));
            try{$own->assign(19,1,[$ids[0]],null);self::fail('Cross tenant accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('ait değil',$e->getMessage());}
            $tenant->setStoreId(1);
            $readiness=$products->getPublishReadiness([],1);self::assertGreaterThan(0,$readiness['total']);
            $own->assign(19,1,$ids,null);self::assertSame($default,$maps->forProduct($rows[0],1));
            try{$own->assign(19,1,$ids,'invalid');self::fail('Unknown category accepted');}catch(InvalidArgumentException $e){self::assertStringContainsString('alt kategori',$e->getMessage());}
        } finally {$db->rollBack();$_ENV=$before;}
    }
}
