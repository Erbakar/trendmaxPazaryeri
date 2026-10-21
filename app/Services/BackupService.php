<?php
/**
 * OzyConnect Panel — BackupService (Yedekleme & Geri Yükleme)
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\AdminSession;
use App\Core\BackupAuthorizationException;
use RuntimeException;

final class BackupService
{
    private string $backupDir;

    public function __construct(
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly AdminSession $adminSession,
        private readonly StoreExportService $storeExport,
    ) {
        $this->backupDir = BASE_PATH . '/storage/backups';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    // ─── R1: Süper Admin yetki + denetim + doğrulama ───────────────────────

    /**
     * Sistem-seviyesi yedek/geri-yükleme YETKİ KAPISI.
     * İzin: CLI (operatör/cron) VEYA oturum Super Admin. Aksi halde reddedilir.
     * Tenant kullanıcıları (mağaza admini dahil) bu işlemlere ERİŞEMEZ.
     */
    private function assertSystemBackupAuthorized(string $action, ?string $target = null): void
    {
        // YETKİ: CLI (operatör/cron) VEYA tam yetkili admin (AdminSession, 2FA dahil).
        $authorized = (PHP_SAPI === 'cli') || $this->adminSession->isAuthenticated();
        if (!$authorized) {
            $this->audit($action . '.denied', $target, 'denied', ['reason' => 'not_admin']);
            throw new BackupAuthorizationException(
                'Sistem yedek/geri-yükleme işlemleri yalnızca platform admin tarafından yapılabilir.'
            );
        }
    }

    /**
     * Sistem denetim günlüğüne yaz (system_audit_log; başarısız olsa bile akışı bozmaz).
     *
     * @param array<string, mixed> $detail
     */
    private function audit(string $action, ?string $target, string $result, array $detail = []): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_audit_log (user_id, admin_user_id, actor_type, actor_email, action, target, result, detail_json, ip_address, created_at)
                 VALUES (NULL, ?, 'admin', ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $this->adminSession->adminId(),
                    $this->adminSession->adminEmail(),
                    $action,
                    $target,
                    $result,
                    json_encode($detail, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Audit log yazılamadı: ' . $e->getMessage(), 'Backup');
        }
    }

    /**
     * Geri yükleme ÖNCESİ doğrulama (DB'ye dokunmadan önce). Hatalıysa exception.
     *
     * @param array<string, mixed> $backup
     */
    private function validateBackupForRestore(array $backup): void
    {
        if (($backup['status'] ?? '') !== 'completed') {
            throw new RuntimeException('Bu yedek geri yüklenebilir durumda değil (status != completed)');
        }
        $zipPath = (string) ($backup['file_path'] ?? '');
        if ($zipPath === '' || !is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException('Yedek dosyası bulunamadı/okunamıyor: ' . ($backup['filename'] ?? '?'));
        }
        if (filesize($zipPath) < 1) {
            throw new RuntimeException('Yedek dosyası boş/bozuk');
        }
        // ZIP + manifest + database.sql ön doğrulaması (DB'ye dokunmadan)
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Yedek ZIP açılamadı (bozuk olabilir)');
        }
        $hasManifest = $zip->locateName('manifest.json') !== false;
        $hasSql      = $zip->locateName('database.sql') !== false;
        $manifestRaw = $hasManifest ? $zip->getFromName('manifest.json') : '';
        $zip->close();
        if (!$hasManifest || !$hasSql) {
            throw new RuntimeException('Geçersiz yedek: manifest.json veya database.sql eksik');
        }
        $manifest = json_decode((string) $manifestRaw, true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            throw new RuntimeException('Geçersiz manifest (version yok)');
        }
    }

    /**
     * Tam yedek oluştur (DB + dosyalar → ZIP)
     *
     * @return array{id: int, filename: string, file_size: int, tables_count: int, files_count: int}
     */
    public function createBackup(string $type = 'manual', ?int $userId = null, string $notes = ''): array
    {
        $this->assertSystemBackupAuthorized('backup.create');
        $timestamp = date('Ymd_His');
        $filename = "{$timestamp}_backup.zip";
        $zipPath = $this->backupDir . '/' . $filename;
        $tempDir = sys_get_temp_dir() . '/ozyconnect_backup_' . $timestamp;

        // DB kaydı oluştur
        $this->db->query(
            "INSERT INTO backups (filename, file_path, type, status, created_by, notes, created_at)
             VALUES (?, ?, ?, 'creating', ?, ?, NOW())",
            [$filename, $zipPath, $type, $userId, $notes]
        );
        $backupId = (int) $this->db->lastInsertId();

        try {
            @mkdir($tempDir, 0755, true);

            // 1. Veritabanı dump
            $tablesCount = $this->dumpDatabase($tempDir . '/database.sql');

            // 2. Dosyaları topla
            $filesCount = 0;
            $fileDirs = [
                'storage/invoices' => 'invoices',
                'storage/labels'   => 'labels',
                'public/uploads'   => 'uploads',
                'config'           => 'config',
            ];

            foreach ($fileDirs as $sourceRel => $targetDir) {
                $sourcePath = BASE_PATH . '/' . $sourceRel;
                if (is_dir($sourcePath)) {
                    $copied = $this->copyDirectory($sourcePath, $tempDir . '/files/' . $targetDir);
                    $filesCount += $copied;
                }
            }

            // 3. Manifest oluştur
            $manifest = [
                'version'      => '1.0',
                'app_version'  => '1.0.0',
                'created_at'   => date('Y-m-d H:i:s'),
                'type'         => $type,
                'tables_count' => $tablesCount,
                'files_count'  => $filesCount,
                'php_version'  => PHP_VERSION,
                'file_dirs'    => array_keys($fileDirs),
            ];
            file_put_contents(
                $tempDir . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            // 4. ZIP oluştur
            $this->createZip($tempDir, $zipPath);

            $fileSize = filesize($zipPath) ?: 0;

            // 5. DB kaydını güncelle
            $this->db->query(
                "UPDATE backups SET status = 'completed', file_size = ?, tables_count = ?,
                 files_count = ?, manifest_json = ?, completed_at = NOW() WHERE id = ?",
                [$fileSize, $tablesCount, $filesCount, json_encode($manifest, JSON_UNESCAPED_UNICODE), $backupId]
            );

            $this->logger->info("Yedek oluşturuldu: {$filename} ({$this->formatBytes($fileSize)})", 'Backup');

            return [
                'id'           => $backupId,
                'filename'     => $filename,
                'file_size'    => $fileSize,
                'tables_count' => $tablesCount,
                'files_count'  => $filesCount,
            ];

        } catch (\Throwable $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);

            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }

            $this->logger->error("Yedek oluşturma hatası: " . $e->getMessage(), 'Backup');
            throw new RuntimeException('Yedek oluşturulamadı: ' . $e->getMessage(), 0, $e);

        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Tek mağaza yedeği oluştur (yalnız o mağazanın verisi → SQL → ZIP). store_id set edilir.
     * Geri-yükleme tam-sistem akışına dahil DEĞİL (indir/arşiv amaçlı).
     *
     * @return array{id:int, filename:string, file_size:int, store_id:int}
     */
    public function createStoreBackup(int $storeId, ?int $userId = null, string $notes = ''): array
    {
        $this->assertSystemBackupAuthorized('backup.create.store', 'store #' . $storeId);
        $store = $this->db->fetchOne("SELECT id, name, slug FROM stores WHERE id = ?", [$storeId]);
        if ($store === false) {
            throw new RuntimeException('Mağaza bulunamadı.');
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($store['slug'] ?? ''))) ?: ('magaza' . $storeId);
        $timestamp = date('Ymd_His');
        $filename = "{$timestamp}_magaza-{$slug}.zip";
        $zipPath = $this->backupDir . '/' . $filename;
        $tempDir = sys_get_temp_dir() . '/ozyconnect_storebackup_' . $timestamp . '_' . $storeId;

        $this->db->query(
            "INSERT INTO backups (store_id, filename, file_path, type, status, created_by, notes, created_at)
             VALUES (?, ?, ?, 'manual', 'creating', ?, ?, NOW())",
            [$storeId, $filename, $zipPath, $userId, $notes]
        );
        $backupId = (int) $this->db->lastInsertId();

        try {
            @mkdir($tempDir, 0755, true);
            $sql = $this->storeExport->dumpStoreSql($storeId, date('c'));
            file_put_contents($tempDir . '/database.sql', $sql);
            $manifest = [
                'version'     => '1.0',
                'scope'       => 'store',
                'store_id'    => $storeId,
                'store_name'  => (string) ($store['name'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
                'type'        => 'manual',
                'php_version' => PHP_VERSION,
            ];
            file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->createZip($tempDir, $zipPath);
            $fileSize = filesize($zipPath) ?: 0;
            $this->db->query(
                "UPDATE backups SET status = 'completed', file_size = ?, tables_count = 0, files_count = 0,
                 manifest_json = ?, completed_at = NOW() WHERE id = ?",
                [$fileSize, json_encode($manifest, JSON_UNESCAPED_UNICODE), $backupId]
            );
            $this->logger->info("Mağaza yedeği: {$filename} ({$this->formatBytes($fileSize)})", 'Backup');
            return ['id' => $backupId, 'filename' => $filename, 'file_size' => $fileSize, 'store_id' => $storeId];
        } catch (\Throwable $e) {
            $this->db->query("UPDATE backups SET status = 'failed' WHERE id = ?", [$backupId]);
            if (file_exists($zipPath)) { @unlink($zipPath); }
            $this->logger->error('Mağaza yedek hatası: ' . $e->getMessage(), 'Backup');
            throw new RuntimeException('Mağaza yedeği oluşturulamadı: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Yedekten geri yükle
     *
     * @return array{success: bool, tables_count: int, files_restored: int, message: string}
     */
    public function restoreBackup(int $backupId): array
    {
        // R1: YETKİ KAPISI — yalnız Super Admin / CLI.
        $this->assertSystemBackupAuthorized('backup.restore', 'backup #' . $backupId);

        $backup = $this->db->fetchOne("SELECT * FROM backups WHERE id = ?", [$backupId]);
        if ($backup === false) {
            $this->audit('backup.restore', 'backup #' . $backupId, 'failed', ['reason' => 'not_found']);
            throw new RuntimeException('Yedek bulunamadı');
        }

        // R1: DB'ye DOKUNMADAN önce doğrulama katmanı (status/dosya/ZIP/manifest/database.sql).
        $this->validateBackupForRestore($backup);

        $zipPath = $backup['file_path'];

        $this->audit('backup.restore.start', 'backup #' . $backupId, 'success', ['filename' => $backup['filename'] ?? null]);
        $this->db->query("UPDATE backups SET status = 'restoring' WHERE id = ?", [$backupId]);

        $tempDir = sys_get_temp_dir() . '/ozyconnect_restore_' . time();

        try {
            // 1. ZIP aç
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('ZIP dosyası açılamadı');
            }
            $zip->extractTo($tempDir);
            $zip->close();

            // 2. Manifest doğrula
            $manifestPath = $tempDir . '/manifest.json';
            if (!file_exists($manifestPath)) {
                throw new RuntimeException('Geçersiz yedek dosyası — manifest.json bulunamadı');
            }

            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (empty($manifest['version'])) {
                throw new RuntimeException('Geçersiz manifest dosyası');
            }

            // 3. Veritabanını geri yükle
            $sqlFile = $tempDir . '/database.sql';
            if (!file_exists($sqlFile)) {
                throw new RuntimeException('Veritabanı dump dosyası bulunamadı');
            }

            $this->restoreDatabase($sqlFile);

            // 4. Dosyaları geri yükle
            $restoredFiles = 0;
            $fileMapping = [
                'invoices' => BASE_PATH . '/storage/invoices',
                'labels'   => BASE_PATH . '/storage/labels',
                'uploads'  => BASE_PATH . '/public/uploads',
                'config'   => BASE_PATH . '/config',
            ];

            foreach ($fileMapping as $backupSubDir => $targetPath) {
                $sourcePath = $tempDir . '/files/' . $backupSubDir;
                if (is_dir($sourcePath)) {
                    $restoredFiles += $this->copyDirectory($sourcePath, $targetPath);
                }
            }

            // Backup kaydını tamamla (yeni DB'de bu kayıt olmayabilir)
            try {
                $this->db->query("UPDATE backups SET status = 'completed' WHERE id = ?", [$backupId]);
            } catch (\Throwable $e) {
                $this->logger->warning("Backup status güncellenemedi #{$backupId}: " . $e->getMessage(), 'Backup');
            }

            $this->logger->info("Yedek geri yüklendi: {$backup['filename']}", 'Backup');
            $this->audit('backup.restore', 'backup #' . $backupId, 'success', [
                'filename'       => $backup['filename'] ?? null,
                'tables_count'   => $manifest['tables_count'] ?? 0,
                'files_restored' => $restoredFiles,
            ]);

            return [
                'success'        => true,
                'tables_count'   => $manifest['tables_count'] ?? 0,
                'files_restored' => $restoredFiles,
                'message'        => 'Yedek başarıyla geri yüklendi',
            ];

        } catch (\Throwable $e) {
            try {
                $this->db->query("UPDATE backups SET status = 'completed' WHERE id = ?", [$backupId]);
            } catch (\Throwable $inner) {
                $this->logger->warning("Backup status güncellenemedi (catch içi) #{$backupId}: " . $inner->getMessage(), 'Backup');
            }

            $this->logger->error("Geri yükleme hatası: " . $e->getMessage(), 'Backup');
            $this->audit('backup.restore', 'backup #' . $backupId, 'failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Geri yükleme başarısız: ' . $e->getMessage(), 0, $e);

        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * Yedek sil
     */
    public function deleteBackup(int $backupId): void
    {
        $backup = $this->db->fetchOne("SELECT * FROM backups WHERE id = ?", [$backupId]);
        if ($backup === false) {
            throw new RuntimeException('Yedek bulunamadı');
        }

        if (file_exists($backup['file_path'])) {
            if (!@unlink($backup['file_path'])) {
                throw new RuntimeException('Yedek dosyası silinemedi');
            }
        }

        $this->db->query("DELETE FROM backups WHERE id = ?", [$backupId]);
        $this->logger->info("Yedek silindi: {$backup['filename']}", 'Backup');
    }

    /**
     * Yedek listesi
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(): array
    {
        return $this->db->fetchAll(
            "SELECT b.*, u.name AS created_by_name, s.name AS store_name
             FROM backups b
             LEFT JOIN users u ON b.created_by = u.id
             LEFT JOIN stores s ON s.id = b.store_id
             ORDER BY b.created_at DESC"
        );
    }

    /**
     * Yedek detayı
     *
     * @return array<string, mixed>|false
     */
    public function getBackup(int $id): array|false
    {
        return $this->db->fetchOne(
            "SELECT b.*, u.name AS created_by_name
             FROM backups b
             LEFT JOIN users u ON b.created_by = u.id
             WHERE b.id = ?",
            [$id]
        );
    }

    /**
     * İstatistikler
     *
     * @return array{total: int, total_size: int, last_backup: string|null, auto_count: int, manual_count: int}
     */
    public function getStats(): array
    {
        $stats = $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(file_size), 0) AS total_size,
                MAX(created_at) AS last_backup,
                SUM(CASE WHEN type = 'auto' THEN 1 ELSE 0 END) AS auto_count,
                SUM(CASE WHEN type = 'manual' THEN 1 ELSE 0 END) AS manual_count
             FROM backups WHERE status = 'completed'"
        );

        return [
            'total'        => (int) ($stats['total'] ?? 0),
            'total_size'   => (int) ($stats['total_size'] ?? 0),
            'last_backup'  => $stats['last_backup'] ?? null,
            'auto_count'   => (int) ($stats['auto_count'] ?? 0),
            'manual_count' => (int) ($stats['manual_count'] ?? 0),
        ];
    }

    /**
     * Eski otomatik yedekleri temizle
     */
    public function cleanOldBackups(int $keepDays = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));

        $oldBackups = $this->db->fetchAll(
            "SELECT id, file_path, filename FROM backups
             WHERE type = 'auto' AND status = 'completed' AND created_at < ?",
            [$cutoff]
        );

        $deleted = 0;
        foreach ($oldBackups as $backup) {
            try {
                if (file_exists($backup['file_path'])) {
                    @unlink($backup['file_path']);
                }
                $this->db->query("DELETE FROM backups WHERE id = ?", [$backup['id']]);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning("Eski yedek silinemedi: {$backup['filename']} — {$e->getMessage()}", 'Backup');
            }
        }

        if ($deleted > 0) {
            $this->logger->info("{$deleted} eski otomatik yedek temizlendi", 'Backup');
        }

        return $deleted;
    }

    /**
     * Yedek dosya yolunu getir (indirme için)
     */
    public function getBackupFilePath(int $id): string
    {
        $backup = $this->db->fetchOne("SELECT file_path, filename, status FROM backups WHERE id = ?", [$id]);
        if ($backup === false) {
            throw new RuntimeException('Yedek bulunamadı');
        }
        if ($backup['status'] !== 'completed') {
            throw new RuntimeException('Bu yedek indirilebilir durumda değil');
        }
        if (!file_exists($backup['file_path'])) {
            throw new RuntimeException('Yedek dosyası bulunamadı');
        }
        return $backup['file_path'];
    }

    /**
     * Byte değerini okunabilir formata çevir
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    // ═══════════════════════════════════════════════════════
    // PRİVATE YARDIMCILAR
    // ═══════════════════════════════════════════════════════

    /**
     * Veritabanını SQL'e dök
     */
    private function dumpDatabase(string $outputFile): int
    {
        $config = require BASE_PATH . '/config/database.php';

        $host = $config['host'];
        $port = $config['port'];
        $name = $config['name'];
        $user = $config['user'];
        $pass = $config['pass'];

        // mysqldump yolu bul (WAMP ortamı)
        $mysqldumpBin = null;
        $wampMysqlDir = glob('c:/wamp64/bin/mysql/mysql*/bin/mysqldump.exe');
        if (!empty($wampMysqlDir)) {
            $mysqldumpBin = end($wampMysqlDir);
        }

        if ($mysqldumpBin === null) {
            return $this->dumpDatabasePHP($outputFile);
        }

        $cmd = sprintf(
            '%s --host=%s --port=%d --user=%s %s --single-transaction --routines --triggers --add-drop-table --default-character-set=utf8mb4 %s > %s 2>&1',
            escapeshellarg($mysqldumpBin),
            escapeshellarg($host),
            $port,
            escapeshellarg($user),
            $pass !== '' ? '--password=' . escapeshellarg($pass) : '',
            escapeshellarg($name),
            escapeshellarg($outputFile)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputFile) || filesize($outputFile) === 0) {
            $this->logger->warning('mysqldump başarısız, PHP dump kullanılıyor', 'Backup');
            return $this->dumpDatabasePHP($outputFile);
        }

        $content = file_get_contents($outputFile);
        return substr_count($content, 'CREATE TABLE');
    }

    /**
     * PHP ile veritabanı dump (mysqldump yoksa fallback)
     */
    private function dumpDatabasePHP(string $outputFile): int
    {
        $pdo = $this->db->getConnection();

        $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        $sql = "-- OzyConnect Database Backup\n";
        $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- PHP Dump\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "SET NAMES utf8mb4;\n\n";

        foreach ($tables as $table) {
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createStmt['Create Table'] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';

                foreach (array_chunk($rows, 500) as $chunk) {
                    $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
                    $values = [];
                    foreach ($chunk as $row) {
                        $vals = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = $pdo->quote((string) $value);
                            }
                        }
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        file_put_contents($outputFile, $sql);

        return count($tables);
    }

    /**
     * SQL dump'ı geri yükle
     */
    private function restoreDatabase(string $sqlFile): void
    {
        $pdo = $this->db->getConnection();

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException('SQL dosyası okunamadı');
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        try {
            $statements = array_filter(
                array_map('trim', explode(";\n", $sql)),
                fn($s) => $s !== '' && !str_starts_with($s, '--')
            );

            foreach ($statements as $statement) {
                $statement = trim($statement, " \t\n\r\0\x0B;");
                if ($statement === '') continue;
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * Dizini recursive olarak kopyala
     */
    private function copyDirectory(string $source, string $dest): int
    {
        if (!is_dir($source)) {
            return 0;
        }

        @mkdir($dest, 0755, true);
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                @mkdir($target, 0755, true);
            } else {
                @mkdir(dirname($target), 0755, true);
                if (copy($item->getPathname(), $target)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Dizinden ZIP oluştur
     */
    private function createZip(string $sourceDir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP dosyası oluşturulamadı');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();
    }

    /**
     * Dizini recursive olarak sil
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
