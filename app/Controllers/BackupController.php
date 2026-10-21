<?php
/**
 * OzyConnect Panel — BackupController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Services\BackupService;

final class BackupController extends BaseController
{
    private readonly BackupService $backupService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->backupService = $container->get(BackupService::class);
    }

    /**
     * R1 — Sistem yedek/geri-yükleme YALNIZCA Super Admin'e açık.
     * Tenant kullanıcıları (mağaza admini dahil) buraya hiçbir şekilde erişemez.
     */
    private function requireSuperAdmin(): void
    {
        // Faz 3: platform admin (admin_users + AdminSession, 2FA dahil). Merchant erişemez.
        if (!$this->container->get(\App\Core\AdminSession::class)->isAuthenticated()) {
            $this->logger->warning('Yetkisiz sistem-yedek erişim denemesi', 'Backup', ['user' => $this->session->userId()]);
            $this->redirectWithError('/admin/login', 'Bu işlem yalnızca platform admin yetkisine açıktır.');
        }
    }

    /**
     * Yedek listesi sayfası
     */
    public function index(): void
    {
        $this->requireSuperAdmin();
        $backups = $this->backupService->listBackups();
        $stats = $this->backupService->getStats();

        $this->view('backups.index', [
            'pageTitle' => 'Yedekler',
            'backups'   => $backups,
            'stats'     => $stats,
            'stores'    => $this->container->get(\App\Core\Database::class)->fetchAll(
                "SELECT id, name FROM stores WHERE status <> 'archived' ORDER BY name ASC"
            ),
            'formatBytes' => fn(int $bytes) => $this->backupService->formatBytes($bytes),
            '_adminEmail' => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /**
     * Yeni yedek oluştur
     */
    public function store(Request $request): void
    {
        $this->requireSuperAdmin();
        $scope  = (string) $request->post('scope', 'full');
        $notes  = trim($request->post('notes', ''));
        $userId = $_SESSION['user_id'] ?? null;
        try {
            if ($scope === 'store') {
                $storeId = (int) $request->post('store_id', 0);
                if ($storeId <= 0) {
                    $this->redirectWithError('/backups', 'Lütfen bir mağaza seçin.');
                    return;
                }
                $result = $this->backupService->createStoreBackup($storeId, $userId, $notes);
                $size = $this->backupService->formatBytes($result['file_size']);
                $this->redirectWithSuccess('/backups', "Mağaza yedeği oluşturuldu ({$size}).");
            } else {
                $result = $this->backupService->createBackup('manual', $userId, $notes);
                $size = $this->backupService->formatBytes($result['file_size']);
                $this->redirectWithSuccess('/backups', "Tüm sistem yedeği oluşturuldu ({$size}).");
            }
        } catch (\Throwable $e) {
            $this->redirectWithError('/backups', 'Yedek oluşturulamadı: ' . $e->getMessage());
        }
    }

    /**
     * Yedek dosyasını indir
     */
    public function download(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $id = (int) $id;

        try {
            $filePath = $this->backupService->getBackupFilePath($id);
            $backup = $this->backupService->getBackup($id);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . ($backup['filename'] ?? 'backup.zip') . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: no-cache, no-store, must-revalidate');

            readfile($filePath);
            exit;

        } catch (\Throwable $e) {
            $this->redirectWithError('/backups', $e->getMessage());
        }
    }

    /**
     * Yedekten geri yükle
     */
    public function restore(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $id = (int) $id;

        try {
            $result = $this->backupService->restoreBackup($id);
            $this->redirectWithSuccess('/backups', $result['message']);
        } catch (\Throwable $e) {
            $this->redirectWithError('/backups', 'Geri yükleme hatası: ' . $e->getMessage());
        }
    }

    /**
     * Yedek sil
     */
    public function delete(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $id = (int) $id;

        try {
            $this->backupService->deleteBackup($id);
            $this->redirectWithSuccess('/backups', 'Yedek başarıyla silindi');
        } catch (\Throwable $e) {
            $this->redirectWithError('/backups', 'Silme hatası: ' . $e->getMessage());
        }
    }
}
