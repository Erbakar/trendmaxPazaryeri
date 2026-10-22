<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\DemoStoreService;
use PHPUnit\Framework\TestCase;

final class DemoStoreServiceTest extends TestCase
{
    public function testConfiguredDemoStoresCanBeResolvedWithoutConnectingToDatabase(): void
    {
        $_ENV['DEMO_STORE_IDS'] = '2, 7, 0, -1, invalid, 7';
        try {
            $service = new DemoStoreService(new Database());
            self::assertTrue($service->isDemoStore(2));
            self::assertTrue($service->isDemoStore(7));
            self::assertTrue($service->isDemoStore(2));
            self::assertFalse($service->isDemoStore(0));
            self::assertFalse($service->isDemoStore(-1));
        } finally {
            unset($_ENV['DEMO_STORE_IDS']);
        }
    }

    public function testEmptyDemoConfigurationIsSupported(): void
    {
        unset($_ENV['DEMO_STORE_IDS']);
        self::assertFalse((new DemoStoreService(new Database()))->isDemoStore(0));
    }
}
