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
 * JSON geri yükleme yalnız aynı kimlikli, boş mağazada ve işlem bütünlüğüyle desteklenir.
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
        $path = $dir . '/store-' . $data['store_id'] . '-' . date('Ymd_His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temporary = tempnam($dir, '.archive-');
        if ($temporary === false) throw new RuntimeException('Arşiv dosyası oluşturulamadı.');
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException('Arşiv dosyası kaydedilemedi.');
            }
        } finally { if (is_file($temporary)) unlink($temporary); }
        return $path;
    }

    /**
     * Restore this service's JSON archive into the SAME, EMPTY store.
     * Existing data is never deleted or overwritten; IDs/FKs remain checked by MySQL.
     * Cross-store migration requires a separate identity remapping workflow.
     * @return array<string,int>
     */
    public function importIntoCurrentStore(string $jsonPath, bool $dryRun = false): array
    {
        $storeId = $this->tenant->currentStoreId();
        if (!is_file($jsonPath) || filesize($jsonPath) > 100 * 1024 * 1024) {
            throw new RuntimeException('Geçerli, en fazla 100 MB JSON arşivi gerekir.');
        }
        $data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['store_id'] ?? null) !== $storeId || !is_array($data['tables'] ?? null)) {
            throw new RuntimeException('Arşiv yalnız ait olduğu mağazaya geri yüklenebilir.');
        }
        $keys = array_keys($data['tables']);
        $expected = self::TENANT_TABLES;
        sort($keys); sort($expected);
        if ($keys !== $expected) throw new RuntimeException('Arşiv tablo listesi beklenen kapsamla uyuşmuyor.');
        $pdo = $this->db->getConnection();
        if ($pdo->inTransaction()) throw new RuntimeException('İçe aktarma ayrı bir işlemde çalıştırılmalıdır.');
        $counts = [];
        $pending = [];
        $archiveIds = [];
        foreach ($data['tables'] as $table => $rows) {
            if (!is_array($rows)) throw new RuntimeException('Geçersiz tablo verisi.');
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['id'])) $archiveIds[$table][(string) $row['id']] = true;
            }
        }
        $fkRows = $pdo->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=k.REFERENCED_TABLE_SCHEMA
              AND c.TABLE_NAME=k.REFERENCED_TABLE_NAME AND c.COLUMN_NAME='store_id'
            WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_COLUMN_NAME='id'")->fetchAll(\PDO::FETCH_ASSOC);
        $foreignReferences = [];
        foreach ($fkRows as $fk) $foreignReferences[$fk['TABLE_NAME']][$fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
        $pdo->beginTransaction();
        try {
            $store = $pdo->prepare('SELECT id FROM stores WHERE id = ? FOR UPDATE');
            $store->execute([$storeId]);
            if (!$store->fetchColumn()) throw new RuntimeException('Hedef mağaza bulunamadı.');
            foreach (self::TENANT_TABLES as $table) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE store_id = ?");
                $count->execute([$storeId]);
                if ((int) $count->fetchColumn() !== 0) throw new RuntimeException("Hedef mağaza boş değil: {$table}. Veri üzerine yazılmaz.");
                if (!is_array($data['tables'][$table]) || !array_is_list($data['tables'][$table])) {
                    throw new RuntimeException('Arşiv satır listesi geçersiz.');
                }
                $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_COLUMN);
                $counts[$table] = count($data['tables'][$table]);
                foreach ($data['tables'][$table] as $row) {
                    if (!is_array($row) || ($row['store_id'] ?? null) !== $storeId ||
                        !isset($row['id']) || !is_numeric($row['id']) || (int) $row['id'] <= 0 ||
                        array_diff(array_keys($row), $columns)) {
                        throw new RuntimeException("Geçersiz veya başka mağazaya ait satır: {$table}");
                    }
                    foreach ($row as $value) {
                        if (!is_scalar($value) && $value !== null) throw new RuntimeException('Arşiv hücresi geçersiz.');
                    }
                    // Check references to tenant-owned rows even where old schemas lack a tenant FK.
                    $references = ['category_id' => 'categories', 'brand_id' => 'brands', 'product_id' => 'products',
                        'order_id' => 'orders', 'customer_id' => 'customers', 'invoice_id' => 'invoices'];
                    if ($table === 'categories') $references['parent_id'] = 'categories';
                    if ($table === 'products') $references['parent_id'] = 'products';
                    $references = ($foreignReferences[$table] ?? []) + $references;
                    foreach ($references as $column => $target) {
                        if (empty($row[$column])) continue;
                        $ref = $pdo->prepare("SELECT store_id FROM `{$target}` WHERE id = ?");
                        $ref->execute([$row[$column]]);
                        $owner = $ref->fetchColumn();
                        if ($owner !== false && (int) $owner !== $storeId) {
                            throw new RuntimeException('Arşiv başka mağazaya referans içeriyor.');
                        }
                        $inArchive = isset($archiveIds[$target][(string) $row[$column]]);
                        if (!$inArchive && $owner === false) throw new RuntimeException('Arşiv ilişkili kayıt içermiyor.');
                    }
                    $pending[] = [$table, $row];
                }
            }
            // Parent rows may appear after children; retry only FK-order errors.
            // All other errors (including duplicate IDs) abort and roll back the entire archive.
            while ($pending !== []) {
                $next = [];
                foreach ($pending as [$table, $row]) {
                    $quoted = array_map(static fn($c) => '`' . $c . '`', array_keys($row));
                    $sql = "INSERT INTO `{$table}` (" . implode(',', $quoted) . ') VALUES (' .
                        implode(',', array_fill(0, count($row), '?')) . ')';
                    try { $pdo->prepare($sql)->execute(array_values($row)); }
                    catch (\PDOException $e) {
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1452) throw $e;
                        $next[] = [$table, $row];
                    }
                }
                if (count($next) === count($pending)) throw new RuntimeException('Arşiv ilişkileri çözülemedi; hiçbir kayıt yüklenmedi.');
                $pending = $next;
            }
            if ($dryRun) $pdo->rollBack(); else $pdo->commit();
            return $counts;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
