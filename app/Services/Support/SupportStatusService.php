<?php
declare(strict_types=1);
namespace App\Services\Support;

use App\Core\{Container,Database,TenantContext};
use App\Models\{Marketplace,Store,Subscription};
use App\Services\Catalog\{AttributeIssueReport,CatalogAuditService};

/**
 * Read-only health snapshot of ONE store for the trendmaxdestek.com support panel.
 * Deliberately excludes credentials, connection strings, customer/order personal data and raw logs:
 * the support panel only needs to know *whether* something is wrong and where to look.
 */
final class SupportStatusService
{
    public function __construct(private readonly Container $container, private readonly Database $db, private readonly TenantContext $tenant) {}

    private function store(): int { return $this->tenant->currentStoreId(); }

    public function snapshot(): array
    {
        $sid = $this->store();
        $store = $this->db->fetchOne('SELECT id,name,status,created_at FROM stores WHERE id=?', [$sid]) ?: ['id' => $sid];
        $out = [
            'generated_at' => date(DATE_ATOM),
            'app' => ['name' => (string)$this->container->config('app_name', 'Trendmax'), 'version' => (string)$this->container->config('version', ''), 'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION],
            'store' => ['id' => (int)$store['id'], 'name' => (string)($store['name'] ?? ''), 'status' => (string)($store['status'] ?? ''), 'created_at' => $store['created_at'] ?? null],
            'subscription' => $this->section(fn() => $this->subscription($sid)),
            'marketplaces' => $this->section(fn() => $this->marketplaces($sid)),
            'jobs' => $this->section(fn() => $this->jobs($sid)),
            'catalog_audit' => $this->section(fn() => $this->catalogAudit()),
            'attribute_issues' => $this->section(fn() => $this->attributeIssues()),
        ];
        $out['alerts'] = self::alerts($out);
        $out['level'] = in_array('critical', array_column($out['alerts'], 'level'), true) ? 'critical' : ($out['alerts'] ? 'warning' : 'ok');
        return $out;
    }

    /** One failing section (e.g. a table not migrated yet) must not hide the others. */
    private function section(callable $read): array
    {
        try { return $read(); } catch (\Throwable $e) { return ['error' => 'Okunamadı: '.mb_substr($e->getMessage(), 0, 200)]; }
    }

    private function subscription(int $sid): array
    {
        $s = $this->container->get(Subscription::class)->forStore($sid);
        if (!$s) return ['status' => 'none'];
        $end = $s['status'] === 'trial' ? ($s['trial_ends_at'] ?? null) : ($s['current_period_end'] ?? null);
        $days = $end ? (int)floor((strtotime((string)$end) - time()) / 86400) : null;
        return ['package' => (string)($s['package_name'] ?? ''), 'status' => (string)$s['status'], 'billing_cycle' => (string)($s['billing_cycle'] ?? ''),
            'ends_at' => $end, 'days_left' => $days];
    }

    private function marketplaces(int $sid): array
    {
        $model = $this->container->get(Marketplace::class);
        $rows = [];
        foreach ($model->getActiveForStore($sid) as $mp) {
            $last = $this->db->fetchOne("SELECT MAX(CASE WHEN status='success' THEN finished_at END) ok_at, MAX(CASE WHEN status='failed' OR records_failed>0 THEN started_at END) failed_at,
                SUM(CASE WHEN started_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) AND (status='failed' OR records_failed>0) THEN 1 ELSE 0 END) failed_24h
                FROM sync_logs WHERE store_id=? AND marketplace_id=?", [$sid, (int)$mp['id']]) ?: [];
            $rows[] = ['slug' => (string)$mp['slug'], 'name' => (string)($mp['display_name'] ?? $mp['slug']), 'connected' => $model->isConnectedForStore((int)$mp['id']),
                'last_success_at' => $last['ok_at'] ?? null, 'last_failure_at' => $last['failed_at'] ?? null, 'failed_syncs_24h' => (int)($last['failed_24h'] ?? 0)];
        }
        return ['items' => $rows];
    }

    private function jobs(int $sid): array
    {
        $row = $this->db->fetchOne("SELECT SUM(status='pending') pending, SUM(status='running') running,
                SUM(status='failed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)) failed_24h, SUM(status='dead' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) dead_7d,
                MAX(CASE WHEN status='done' THEN finished_at END) last_done_at, MIN(CASE WHEN status='pending' THEN COALESCE(next_run_at,created_at) END) oldest_pending_at
            FROM sync_jobs WHERE store_id=?", [$sid]) ?: [];
        $types = $this->db->fetchAll("SELECT job_type, COUNT(*) n, MAX(updated_at) last_at FROM sync_jobs WHERE store_id=? AND status IN ('failed','dead') AND updated_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) GROUP BY job_type ORDER BY n DESC LIMIT 20", [$sid]);
        return ['pending' => (int)($row['pending'] ?? 0), 'running' => (int)($row['running'] ?? 0), 'failed_24h' => (int)($row['failed_24h'] ?? 0),
            'dead_7d' => (int)($row['dead_7d'] ?? 0), 'last_done_at' => $row['last_done_at'] ?? null, 'oldest_pending_at' => $row['oldest_pending_at'] ?? null,
            'failed_by_type' => array_map(static fn($t) => ['type' => (string)$t['job_type'], 'count' => (int)$t['n'], 'last_at' => $t['last_at']], $types)];
    }

    private function catalogAudit(): array
    {
        $service = $this->container->get(CatalogAuditService::class);
        $mid = $service->marketplace();
        $config = $service->configuration();
        $run = $this->db->fetchOne('SELECT status,cursor_pos,total,error_text,updated_at FROM catalog_audit_runs WHERE store_id=? AND marketplace_id=? ORDER BY id DESC LIMIT 1', [$this->store(), $mid]) ?: null;
        $counts = array_column($this->db->fetchAll('SELECT status,COUNT(*) n FROM catalog_audit_items WHERE store_id=? AND marketplace_id=? GROUP BY status', [$this->store(), $mid]), 'n', 'status');
        $rejected = $this->db->fetchOne("SELECT COUNT(*) n FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND status='rejected' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)", [$this->store(), $mid]);
        $reasons = $this->db->fetchAll("SELECT JSON_UNQUOTE(JSON_EXTRACT(result_json,'$.rejection')) reason, COUNT(*) n FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND status='rejected' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY reason ORDER BY n DESC LIMIT 10", [$this->store(), $mid]);
        return ['enabled' => $config['enabled'], 'auto_publish' => $config['publish'], 'heartbeat' => $config['heartbeat'],
            'last_run' => $run ? ['status' => $run['status'], 'checked' => (int)$run['cursor_pos'], 'total' => (int)$run['total'], 'error' => $run['error_text'], 'updated_at' => $run['updated_at']] : null,
            'counts' => array_map('intval', $counts), 'rejections_7d' => (int)($rejected['n'] ?? 0),
            'top_rejection_reasons' => array_map(static fn($r) => ['reason' => mb_substr((string)$r['reason'], 0, 300), 'count' => (int)$r['n']], $reasons)];
    }

    private function attributeIssues(): array
    {
        $summary = $this->container->get(AttributeIssueReport::class)->summary();
        $state = (array)($summary['state'] ?? []);
        unset($summary['state']);
        return $summary + ['refreshed_at' => $state['refreshed_at'] ?? null, 'refreshing' => !empty($state['cursor_active']), 'last_error' => $state['error'] ?? null];
    }

    /** Pure, unit tested: human readable alerts the support panel shows on its home page. */
    public static function alerts(array $s): array
    {
        $alerts = [];
        $add = static function (string $level, string $code, string $message) use (&$alerts): void { $alerts[] = ['level' => $level, 'code' => $code, 'message' => $message]; };
        $sub = $s['subscription'] ?? [];
        if (isset($sub['days_left']) && $sub['days_left'] !== null) {
            if ($sub['days_left'] < 0) $add('critical', 'subscription_expired', 'Paket süresi '.abs((int)$sub['days_left']).' gün önce doldu.');
            elseif ($sub['days_left'] <= 7) $add('warning', 'subscription_ending', 'Paket süresinin bitmesine '.(int)$sub['days_left'].' gün kaldı.');
        }
        if (in_array($sub['status'] ?? '', ['suspended', 'expired', 'passive'], true)) $add('critical', 'subscription_inactive', 'Abonelik durumu: '.$sub['status'].'.');
        foreach ($s['marketplaces']['items'] ?? [] as $mp) {
            if (!$mp['connected']) $add('warning', 'marketplace_not_connected', $mp['name'].' API bilgileri girilmemiş.');
            elseif ($mp['failed_syncs_24h'] > 0) $add('warning', 'marketplace_sync_failed', $mp['name'].': son 24 saatte '.$mp['failed_syncs_24h'].' başarısız senkron.');
        }
        $jobs = $s['jobs'] ?? [];
        if (($jobs['dead_7d'] ?? 0) > 0) $add('warning', 'jobs_dead', ($jobs['dead_7d']).' görev tekrar denemelerine rağmen tamamlanamadı (son 7 gün).');
        if (!empty($jobs['oldest_pending_at']) && strtotime((string)$jobs['oldest_pending_at']) < time() - 1800) $add('critical', 'queue_stalled', 'Kuyrukta 30 dakikadan uzun bekleyen görev var; cron/worker çalışmıyor olabilir.');
        $ai = $s['attribute_issues'] ?? [];
        if (($ai['products_blocked'] ?? 0) > 0) $add('warning', 'attribute_mapping_missing', (int)$ai['products_blocked'].' üründe zorunlu özellik eşleştirmesi bulunamadı ('.(int)($ai['categories_blocked'] ?? 0).' kategori).');
        if (array_key_exists('refreshed_at', $ai) && $ai['refreshed_at'] === null && empty($ai['error'])) $add('info', 'attribute_report_pending', 'Zorunlu özellik raporu henüz hesaplanmadı (cron/attribute_issues.php).');
        $audit = $s['catalog_audit'] ?? [];
        if (($audit['rejections_7d'] ?? 0) > 0) $add('warning', 'publish_rejected', 'Trendyol son 7 günde '.(int)$audit['rejections_7d'].' ürün gönderimini reddetti.');
        if (!empty($audit['enabled']) && !empty($audit['heartbeat']) && strtotime((string)$audit['heartbeat']) < time() - 1800) $add('critical', 'catalog_audit_stalled', 'Katalog kontrolü 30 dakikadır çalışmadı; cron/catalog_audit.php kontrol edilmeli.');
        if (($audit['last_run']['status'] ?? '') === 'failed') $add('warning', 'catalog_audit_failed', 'Son katalog kontrolü hata verdi: '.mb_substr((string)$audit['last_run']['error'], 0, 200));
        foreach (['subscription', 'marketplaces', 'jobs', 'catalog_audit', 'attribute_issues'] as $section) {
            if (isset($s[$section]['error']) && !($section === 'catalog_audit' && str_contains((string)$s[$section]['error'], 'Trendyol'))) $add('info', 'section_unavailable', $section.' bölümü okunamadı.');
        }
        return $alerts;
    }
}
