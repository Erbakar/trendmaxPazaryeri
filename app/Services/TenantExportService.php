<?php
/**
 * OzyConnect Panel — TenantExportService
 *
 * R1 — SİSTEM yedeği ile TENANT verisi dışa/içe aktarımının KESİN AYRIMI.
 *
 * Bu servis YALNIZCA aktif mağazanın (tenant) verisini, store_id ile sıkı
 * filtreleyerek dışa aktarır. Sistem-seviyesi full-DB dump/restore (BackupService)
 * ile HİÇBİR ortak yolu yoktur; başka mağazanın verisine ERİŞEMEZ.
 *
 * Tenant kullanıcıları (mağaza admini) kendi verilerini buradan dışa aktarabilir;
 * tüm-DB yedek/geri-yükleme YALNIZCA Super Admin'dedir (BackupService).
 *
 * NOT: Tenant IÇE-aktarım (import/restore) bilinçli olarak Faz 4'e bırakıldı
 * (veri bütünlüğü/çakışma riski; ayrı tasarım gerektirir).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use RuntimeException;

final class TenantExportService
{
    /** Dışa aktarılan tenant-scoped tablolar (sabit beyaz liste — kullanıcı girdisi DEĞİL). */
    private const TENANT_TABLES = [
        'products', 'orders', 'order_items', 'customers', 'invoices',
        'financial_transactions', 'returns', 'questions', 'marketplace_products',
        'brands', 'categories', 'price_rules', 'xml_feeds',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Aktif mağazanın verisini store-scoped dışa aktar.
     * TenantContext kurulmamışsa fail-closed exception (sistem bağlamından çağrılamaz).
     *
     * @return array{store_id:int, exported_at:string, tables: array<string, array<int, array<string,mixed>>>}
     */
    public function exportCurrentStore(): array
    {
        $storeId = $this->tenant->currentStoreId(); // fail-closed

        $tables = [];
        foreach (self::TENANT_TABLES as $table) {
            // Tablo adı sabit beyaz listeden; store_id bağlı parametre.
            $tables[$table] = $this->db->fetchAll(
                "SELECT * FROM `{$table}` WHERE `store_id` = :sid",
                ['sid' => $storeId]
            );
        }

        return [
            'store_id'    => $storeId,
            'exported_at' => date('c'),
            'tables'      => $tables,
        ];
    }

    /**
     * Aktif mağaza verisini JSON dosyasına yaz (storage/exports/store-<id>-<ts>.json).
     */
    public function exportCurrentStoreToFile(): string
    {
        $data = $this->exportCurrentStore();
        $dir = BASE_PATH . '/storage/exports';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Export dizini oluşturulamadı');
        }
        $path = $dir . '/store-' . $data['store_id'] . '-' . date('Ymd_His') . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $path;
    }

    /**
     * Tenant içe-aktarım: BİLİNÇLİ OLARAK Faz 4'e ertelendi.
     */
    public function importIntoCurrentStore(string $jsonPath): never
    {
        throw new RuntimeException(
            'Tenant içe-aktarım henüz desteklenmiyor (Faz 4). Sistem geri-yükleme için BackupService (Super Admin) kullanılır.'
        );
    }
}
