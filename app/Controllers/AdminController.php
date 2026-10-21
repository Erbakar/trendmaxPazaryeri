<?php
/**
 * OzyConnect Panel — AdminController (Faz 3/4: korumalı admin paneli + 2FA onboarding)
 *
 * /admin (home) + /admin/2fa/setup (TOTP kurulum: secret üret → otpauth/QR → doğrula → enable
 * + recovery code üret/göster). AdminAuthMiddleware arkasında (tam yetkili admin).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TotpService;
use App\Models\AdminUser;
use App\Services\AdminAuthService;

final class AdminController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly AdminAuthService $adminAuth;
    private readonly TotpService $totp;
    private readonly AdminUser $adminUserModel;
    private readonly Container $container;

    public function __construct(Container $container)
    {
        $this->container      = $container;
        $this->response       = $container->get(Response::class);
        $this->session        = $container->get(Session::class);
        $this->adminSession   = $container->get(AdminSession::class);
        $this->adminAuth      = $container->get(AdminAuthService::class);
        $this->totp           = $container->get(TotpService::class);
        $this->adminUserModel = $container->get(AdminUser::class);
    }

    /**
     * Admin sayfası render. $layout='admin' → birleşik üst-navbar kabuğu (home).
     * Pre/odak akışları (2FA kurulum/kurtarma) $layout=null ile standalone kalır.
     */
    private function render(string $view, array $data = [], ?string $layout = 'admin'): void
    {
        $data['_csrf']  = $this->session->csrfToken();
        $data['_flash'] = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, $layout);
    }

    /** Admin paneli ana sayfa + SaaS Dashboard (KPI + grafik + sağlık + son etkinlik). */
    public function home(Request $request): void
    {
        $admin = $this->adminUserModel->find((int) $this->adminSession->adminId());
        $data = $this->dashboardData();
        $data['pageTitle']   = 'Admin Paneli';
        $data['totpEnabled'] = (int) ($admin['totp_enabled'] ?? 0) === 1;
        $this->render('admin.home', $data);
    }

    /**
     * Dashboard verisi — HEPSİ gerçek DB'den, her blok ayrı fail-safe (tablo yoksa boş/0).
     * @return array<string,mixed>
     */
    private function dashboardData(): array
    {
        $c = $this->container;
        $d = [
            'kpis'           => ['active_stores' => 0, 'trial' => 0, 'month_total' => 0.0, 'month_delta' => null, 'pending_payments' => 0, 'open_critical' => 0, 'new_signups' => 0],
            'revenue'        => [],
            'subStatus'      => ['trial' => 0, 'active' => 0, 'passive' => 0, 'suspended' => 0, 'expired' => 0, 'cancelled' => 0, 'all' => 0],
            'conversion'     => ['trial' => 0, 'active_paid' => 0, 'ended' => 0, 'rate' => 0.0],
            'stores'         => [],
            'health'         => null,
            'recentSignups'  => [],
            'recentPayments' => [],
        ];

        // ── KPI'lar ──
        try {
            $db  = $c->get(\App\Core\Database::class);
            $pay = $c->get(\App\Models\Payment::class);
            $d['kpis']['active_stores'] = (int) ($db->fetchOne("SELECT COUNT(*) c FROM stores WHERE status = 'active'")['c'] ?? 0);
            $d['kpis']['trial']         = (int) ($c->get(\App\Models\Subscription::class)->statusCounts()['trial'] ?? 0);
            $thisMonth = $pay->confirmedThisMonth();
            $lastMonth = $pay->confirmedLastMonth();
            $d['kpis']['month_total'] = $thisMonth;
            if ($lastMonth > 0) {
                $pct = ($thisMonth - $lastMonth) / $lastMonth * 100;
                $d['kpis']['month_delta'] = ['v' => sprintf('%+d%%', (int) round($pct)), 'up' => $pct >= 0];
            }
            $d['kpis']['pending_payments'] = $pay->pendingCount();
            $d['kpis']['open_critical']    = $c->get(\App\Models\SupportTicket::class)->adminOpenCriticalCount();
            $d['kpis']['new_signups']      = (int) ($c->get(\App\Models\StoreSignupRequest::class)->statusCounts()['pending'] ?? 0);
        } catch (\Throwable) {
        }

        // ── Aylık ciro serisi (6 ay) ──
        try { $d['revenue'] = $c->get(\App\Models\Payment::class)->confirmedMonthlySeries(6); } catch (\Throwable) {
        }

        // ── Abonelik durum dağılımı + dönüşüm ──
        try {
            $sub = $c->get(\App\Models\Subscription::class);
            $d['subStatus']  = $sub->statusCounts();
            $d['conversion'] = $sub->conversionMetrics();
        } catch (\Throwable) {
        }

        // ── Yeni mağaza (son 6 ay) ──
        try {
            $db = $c->get(\App\Core\Database::class);
            $mon = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
            $base = strtotime(date('Y-m-01'));
            $buckets = [];
            for ($i = 5; $i >= 0; $i--) {
                $ts = strtotime("-{$i} month", $base);
                $buckets[date('Y-m', $ts)] = ['label' => $mon[(int) date('n', $ts)], 'count' => 0];
            }
            $start = date('Y-m-01 00:00:00', strtotime('-5 month', $base));
            $rows = $db->fetchAll("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM stores WHERE created_at >= :d GROUP BY ym", ['d' => $start]);
            foreach ($rows as $r) {
                $ym = (string) ($r['ym'] ?? '');
                if (isset($buckets[$ym])) { $buckets[$ym]['count'] = (int) $r['c']; }
            }
            $d['stores'] = array_values($buckets);
        } catch (\Throwable) {
        }

        // ── Sistem sağlığı ──
        try { $d['health'] = $c->get(\App\Services\SystemHealthService::class)->snapshot(); } catch (\Throwable) {
        }

        // ── Son etkinlik ──
        try { $d['recentSignups']  = $c->get(\App\Models\StoreSignupRequest::class)->adminList('pending', 6); } catch (\Throwable) {
        }
        try { $d['recentPayments'] = $c->get(\App\Models\Payment::class)->adminList(null, 6); } catch (\Throwable) {
        }

        return $d;
    }

    /** 2FA kurulum ekranı — yeni secret üret + otpauth URI (QR). */
    public function setup2faShow(Request $request): void
    {
        $secret = $this->totp->generateSecret();
        $_SESSION['admin']['totp_setup_secret'] = $secret; // geçici (enable'a kadar)
        $uri = $this->totp->provisioningUri($secret, (string) $this->adminSession->adminEmail());
        $this->render('admin.twofa_setup', [
            'pageTitle' => '2FA Kurulumu',
            'secret'    => $secret,
            'otpauthUri'=> $uri,
        ], null);
    }

    /** 2FA kurulum doğrula — kod doğruysa enable + recovery code üret/göster. */
    public function setup2faVerify(Request $request): void
    {
        $secret = (string) ($_SESSION['admin']['totp_setup_secret'] ?? '');
        $code = trim((string) $request->post('code', ''));
        if ($secret === '' || !$this->totp->verify($secret, $code)) {
            $this->session->flash('error', 'Kod doğrulanamadı, tekrar deneyin.');
            $this->response->redirectTo('/admin/2fa/setup');
        }
        $adminId = (int) $this->adminSession->adminId();
        $this->adminAuth->enableTotp($adminId, $secret);
        $codes = $this->adminAuth->generateRecoveryCodes($adminId, 10);
        unset($_SESSION['admin']['totp_setup_secret']);
        $this->render('admin.twofa_recovery', [
            'pageTitle' => 'Kurtarma Kodları',
            'codes'     => $codes,
        ], null);
    }

    /** Recovery kodlarını yenile (tek kullanımlık, eskiler iptal). */
    public function regenerateRecovery(Request $request): void
    {
        $codes = $this->adminAuth->generateRecoveryCodes((int) $this->adminSession->adminId(), 10);
        $this->render('admin.twofa_recovery', ['pageTitle' => 'Kurtarma Kodları', 'codes' => $codes], null);
    }
}
