<?php
/**
 * OzyConnect Panel — OpsController (Faz 7: Operations & Observability)
 *
 * Admin'e özel gözlem panelleri: Queue dashboard, Worker yönetimi, Sistem sağlığı,
 * Audit dashboard. Tüm rotalar AdminAuthMiddleware arkasında (merchant ERİŞEMEZ).
 * Aksiyonlar (dead-letter retry, purge, recover) admin + CSRF korumalı.
 *
 * Salt gözlem + operasyonel bakım; tenant verisine dokunmaz (queue/worker/audit altyapısı).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\AdminSession;
use App\Models\SystemAuditLog;
use App\Models\AdminLoginAttempt;
use App\Services\Queue\QueueService;
use App\Services\Queue\WorkerHeartbeatService;
use App\Services\SystemHealthService;

final class OpsController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly QueueService $queue;
    private readonly WorkerHeartbeatService $workers;
    private readonly SystemHealthService $health;
    private readonly SystemAuditLog $audit;
    private readonly AdminLoginAttempt $loginAttempts;
    private readonly Database $db;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->queue         = $container->get(QueueService::class);
        $this->workers       = $container->get(WorkerHeartbeatService::class);
        $this->health        = $container->get(SystemHealthService::class);
        $this->audit         = $container->get(SystemAuditLog::class);
        $this->loginAttempts = $container->get(AdminLoginAttempt::class);
        $this->db            = $container->get(Database::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    /** Ops ana sayfa — özet sağlık + alt panel bağlantıları. */
    public function home(Request $request): void
    {
        $jobs = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status='running'),0) running,
                    COALESCE(SUM(status='dead'),0) dead, COALESCE(SUM(status='failed'),0) failed
             FROM sync_jobs"
        ) ?: [];
        $this->render('ops.home', [
            'pageTitle'    => 'Operasyon Paneli',
            'health'       => $this->health->snapshot(),
            'jobs'         => $jobs,
            'activeStores' => (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM stores WHERE status = 'active'")['c'] ?? 0),
            'audit24'      => (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM system_audit_log WHERE created_at >= NOW() - INTERVAL 24 HOUR")['c'] ?? 0),
            'sync24'       => (int) ($this->db->fetchOne("SELECT COUNT(*) c FROM sync_logs WHERE started_at >= NOW() - INTERVAL 24 HOUR")['c'] ?? 0),
            'stores'       => $this->db->fetchAll("SELECT id, name FROM stores WHERE status <> 'archived' ORDER BY name ASC"),
        ]);
    }

    /** Mağaza-bazlı operasyon özeti (JSON) — Ops Özet'teki mağaza seçici doldurur. */
    public function storeSummary(Request $request): void
    {
        $sid = (int) $request->query('store_id', 0);
        if ($sid <= 0) { $this->response->json(['ok' => false, 'message' => 'Geçersiz mağaza']); return; }
        $store = $this->db->fetchOne("SELECT id, name, status, verified_at FROM stores WHERE id = :s", ['s' => $sid]);
        if ($store === false) { $this->response->json(['ok' => false, 'message' => 'Mağaza bulunamadı']); return; }

        $jobs = $this->db->fetchOne(
            "SELECT COALESCE(SUM(status='pending'),0) pending, COALESCE(SUM(status='running'),0) running,
                    COALESCE(SUM(status='dead'),0) dead, COALESCE(SUM(status='failed'),0) failed, COUNT(*) total
             FROM sync_jobs WHERE store_id = :s", ['s' => $sid]
        ) ?: [];
        $lastSync = $this->db->fetchOne(
            "SELECT status, started_at FROM sync_logs WHERE store_id = :s ORDER BY started_at DESC LIMIT 1", ['s' => $sid]
        );
        $audit24 = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM system_audit_log WHERE store_id = :s AND created_at >= NOW() - INTERVAL 24 HOUR", ['s' => $sid]
        )['c'] ?? 0);
        $lastLogin = $this->db->fetchOne(
            "SELECT MAX(last_login_at) l FROM users WHERE store_id = :s", ['s' => $sid]
        )['l'] ?? null;

        $this->response->json([
            'ok'    => true,
            'store' => [
                'id' => $sid, 'name' => (string) $store['name'], 'status' => (string) $store['status'],
                'verified' => !empty($store['verified_at']),
            ],
            'jobs'       => array_map('intval', $jobs),
            'last_sync'  => $lastSync !== false ? ['status' => $lastSync['status'], 'at' => $lastSync['started_at']] : null,
            'audit_24h'  => $audit24,
            'last_login' => $lastLogin,
        ]);
    }

    /** Queue dashboard — durum sayıları + son işler + dead-letter. */
    public function queue(Request $request): void
    {
        $this->render('ops.queue', [
            'pageTitle'  => 'Queue Dashboard',
            'stats'      => $this->queue->stats(),
            'recent'     => $this->queue->recentJobs(null, 50),
            'dead'       => $this->queue->deadJobs(50),
        ]);
    }

    /** Worker yönetimi — aktif/durmuş worker'lar, son heartbeat, işlenen iş. */
    public function workers(Request $request): void
    {
        $this->render('ops.workers', [
            'pageTitle'    => 'Worker Yönetimi',
            'workers'      => $this->workers->allWorkers(),
            'activeCount'  => $this->workers->activeCount(),
            'threshold'    => WorkerHeartbeatService::ACTIVE_THRESHOLD_SECONDS,
        ]);
    }

    /** Sistem sağlığı — DB / queue / worker / cron / API. */
    public function healthView(Request $request): void
    {
        $this->render('ops.health', [
            'pageTitle' => 'Sistem Sağlığı',
            'health'    => $this->health->snapshot(),
        ]);
    }

    /** Sağlık JSON endpoint'i (uptime monitor / dış izleme için). */
    public function healthJson(Request $request): void
    {
        $snap = $this->health->snapshot();
        // Bilgi sızıntısını azalt: DB adı/sürümü dış monitor JSON'ına gerekmez
        // (HTML /ops/health'te operatör için kalır). Yalnız bağlantı durumu yeter.
        if (isset($snap['db'])) {
            $snap['db'] = ['level' => $snap['db']['level'], 'connected' => $snap['db']['connected'] ?? false];
        }
        $code = $snap['overall'] === 'down' ? 503 : 200;
        $this->response->json($snap, $code);
    }

    /** Audit dashboard — admin işlemleri, entitlement değişiklikleri, login hataları, kritik olaylar. */
    public function audit(Request $request): void
    {
        $this->render('ops.audit', [
            'pageTitle'       => 'Audit Dashboard',
            'adminActions'    => $this->audit->adminActions(40),
            'entitlement'     => $this->audit->entitlementChanges(40),
            'critical'        => $this->audit->criticalEvents(40),
            'loginFailures'   => $this->loginAttempts->recentFailures(40),
            'loginStats'      => $this->loginAttempts->stats(24),
            'resultCounts'    => $this->audit->countsByResult(24),
        ]);
    }

    // ── Operasyonel aksiyonlar (admin + CSRF) ──

    /** Tek dead-letter işini yeniden kuyruğa al. */
    public function retryDeadJob(Request $request, string $id): void
    {
        $ok = $this->queue->retryDead((int) $id);
        $this->session->flash($ok ? 'success' : 'error', $ok ? "İş #{$id} yeniden kuyruğa alındı." : "İş #{$id} dead-letter'da bulunamadı.");
        $this->response->redirectTo('/ops/queue');
    }

    /** Tüm dead-letter işlerini yeniden kuyruğa al. */
    public function retryAllDead(Request $request): void
    {
        $n = $this->queue->retryAllDead();
        $this->session->flash('success', "{$n} dead-letter işi yeniden kuyruğa alındı.");
        $this->response->redirectTo('/ops/queue');
    }

    /** Tamamlanmış eski işleri temizle (varsayılan 7 günden eski). */
    public function purgeCompleted(Request $request): void
    {
        $days = max(1, (int) $request->post('days', 7));
        $n = $this->queue->purgeCompleted($days * 86400);
        $this->session->flash('success', "{$n} tamamlanmış iş temizlendi ({$days} günden eski).");
        $this->response->redirectTo('/ops/queue');
    }

    /** Takılı işleri elle kurtar (running'de kalmış). */
    public function recoverStuck(Request $request): void
    {
        $n = $this->queue->recoverStuck(900);
        $this->session->flash('success', "{$n} takılı iş yeniden kuyruğa alındı.");
        $this->response->redirectTo('/ops/queue');
    }
}
