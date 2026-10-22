<?php
declare(strict_types=1);

use App\Core\{Container,Database,TenantContext};
use App\Models\{Product,XmlProfile,Marketplace};
use App\Services\CryptoService;
use PHPUnit\Framework\TestCase;

final class TenantIsolationTest extends TestCase
{
    public function testProductsProfilesAndCachedCredentialsNeverCrossStores(): void
    {
        if (getenv('TRENDMAX_LOCAL_INTEGRATION') !== '1') $this->markTestSkipped('Local DB only.');
        $saved = $_ENV;
        foreach (file(BASE_PATH.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) continue;
            [$k,$v] = explode('=', $line, 2); $_ENV[trim($k)] = trim($v, " \t\"'");
        }
        if (($_ENV['DB_NAME']??'') !== 'trendmax_local' || ($_ENV['DB_HOST']??'') !== '127.0.0.1') throw new RuntimeException('Local DB only.');
        $c = new Container([]); $db = $c->get(Database::class); $tenant = $c->get(TenantContext::class);
        $db->beginTransaction();
        try {
            $ids = [];
            foreach (['A','B'] as $label) {
                $db->query('INSERT INTO stores(name,slug) VALUES(?,?)', ['Isolation '.$label, 'isolation-'.bin2hex(random_bytes(6))]);
                $ids[] = (int)$db->lastInsertId();
            }
            $tenant->setStoreId($ids[0]);
            $products = $c->get(Product::class); $profiles = $c->get(XmlProfile::class); $marketplaces = $c->get(Marketplace::class);
            $productId = $products->create(['sku'=>'ISOLATION-A','name'=>'Private A']);
            $profileId = $profiles->create(['name'=>'Private XML A','direction'=>'import','source_type'=>'url','source_url'=>'https://example.com/private.xml']);
            $key = $c->get(CryptoService::class)->encrypt('fixture-only-A');
            $marketplaces->saveStoreConfig(1, ['is_enabled'=>1,'api_key_encrypted'=>$key]);
            self::assertSame($key, $marketplaces->find(1)['api_key_encrypted']);
            $tenant->setStoreId($ids[1]);
            self::assertFalse($products->find($productId));
            self::assertFalse($profiles->find($profileId));
            self::assertNull($marketplaces->find(1)['api_key_encrypted']);
            $products->update($productId, ['name'=>'Cross-store attack']);
            $profiles->delete($profileId);
            $tenant->setStoreId($ids[0]);
            self::assertSame('Private A', $products->find($productId)['name']);
            self::assertSame('Private XML A', $profiles->find($profileId)['name']);
            self::assertSame($key, $marketplaces->find(1)['api_key_encrypted']);
            $tenant->clear();
            self::assertNull($marketplaces->find(1)['api_key_encrypted']);
            $this->expectException(RuntimeException::class);
            $products->find($productId);
        } finally {
            $db->rollBack(); $_ENV = $saved;
        }
    }
}
