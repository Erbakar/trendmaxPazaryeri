<?php
/**
 * OzyConnect Panel — AdminMarketingController (Faz 8: reklam pikselleri / dönüşüm takibi)
 *
 * Meta Pixel + Conversions API, Google (GA4 + Ads), TikTok Pixel + Events API ayarları →
 * GLOBAL `Setting` (group='marketing'). Public kurumsal sitede çalışır (client pixel + server-side).
 * AdminAuthMiddleware arkasında (Pattern A: AdminSession self-guard, AdminBranding ile aynı desen).
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
use App\Models\Setting;

final class AdminMarketingController
{
    /** Açık/kapalı anahtarlar (checkbox → '1'/'0'). */
    private const BOOL_KEYS = ['meta_enabled', 'google_enabled', 'tiktok_enabled', 'gtm_enabled'];
    /** Metin alanları (id/token) — uzun olabilir (CAPI token'ları). */
    private const TEXT_KEYS = [
        'meta_pixel_id', 'meta_capi_token', 'meta_test_event_code',
        'google_analytics_id', 'google_ads_id', 'ga4_api_secret',
        'tiktok_pixel_id', 'tiktok_events_token',
        'gtm_container_id',
    ];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Setting $settingModel;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->settingModel = $container->get(Setting::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $values = [];
        foreach (self::TEXT_KEYS as $k) { $values[$k] = (string) $this->settingModel->get($k, ''); }
        foreach (self::BOOL_KEYS as $k) { $values[$k] = ((string) $this->settingModel->get($k, '0')) === '1'; }
        $this->render('admin.marketing', [
            'pageTitle' => 'Pazarlama & Reklam Pikselleri',
            'values'    => $values,
        ]);
    }

    public function save(Request $request): void
    {
        $adminId = null; // settings.updated_by FK → users(id); admin users tablosunda yok → null
        $in = $request->post('s', []);
        $in = is_array($in) ? $in : [];

        try {
            foreach (self::TEXT_KEYS as $k) {
                if (array_key_exists($k, $in)) {
                    $this->settingModel->set($k, mb_substr(trim((string) $in[$k]), 0, 2000), $adminId, 'string', 'marketing');
                }
            }
            // Checkbox'lar: gönderildiyse '1', formda hiç yoksa '0'
            foreach (self::BOOL_KEYS as $k) {
                $val = !empty($in[$k]) ? '1' : '0';
                $this->settingModel->set($k, $val, $adminId, 'string', 'marketing');
            }
            Setting::clearCache();
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/marketing');
            return;
        }

        $this->session->flash('success', 'Pazarlama / piksel ayarları güncellendi.');
        $this->response->redirectTo('/admin/marketing');
    }
}
