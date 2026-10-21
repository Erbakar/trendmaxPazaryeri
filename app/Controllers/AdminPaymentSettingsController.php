<?php
/**
 * OzyConnect Panel — AdminPaymentSettingsController
 *
 * Ödeme sağlayıcı ayarları (PayTR + Havale/EFT) → GLOBAL `Setting` (group='payment').
 * AdminAuthMiddleware arkasında (Pattern A: BaseController yok, AdminSession self-guard).
 *
 * Sır niteliğindeki alanlar (merchant_key, merchant_salt) password gibi davranır:
 * yalnız POST'ta dolu geldiyse güncellenir; boş gelirse mevcut değer korunur.
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

final class AdminPaymentSettingsController
{
    /** Düz metin alanları — type=string, group=payment. */
    private const TEXT_KEYS = ['paytr_merchant_id', 'havale_info'];
    /** Sır niteliğindeki alanlar (password gibi): yalnız dolu gelirse güncelle. */
    private const SECRET_KEYS = ['paytr_merchant_key', 'paytr_merchant_salt'];
    /** Checkbox alanları: işaretliyse '1', değilse '0'. */
    private const BOOL_KEYS = ['paytr_test_mode', 'havale_enabled'];

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
        $defaults = [
            'paytr_test_mode' => '1',
            'havale_enabled'  => '1',
        ];
        $keys = array_merge(self::TEXT_KEYS, self::SECRET_KEYS, self::BOOL_KEYS);
        $values = [];
        foreach ($keys as $k) {
            $values[$k] = (string) $this->settingModel->get($k, $defaults[$k] ?? '');
        }

        $this->render('admin.payment_settings', [
            'pageTitle' => 'Ödeme Ayarları',
            'values'    => $values,
        ]);
    }

    public function save(Request $request): void
    {
        // settings.updated_by FK → users(id). Admin oturumu admin_users tablosundadır; admin id'si
        // users'ta YOK → FK 1452 ihlali. Bu yüzden settings yazımında updated_by = null.
        $adminId = null;

        $in = $request->post('s', []);
        if (is_array($in)) {
            // Düz metin alanları
            foreach (self::TEXT_KEYS as $k) {
                if (array_key_exists($k, $in)) {
                    $this->settingModel->set($k, trim((string) $in[$k]), $adminId, 'string', 'payment');
                }
            }
            // Sır alanları: yalnız dolu gelirse güncelle (boş → mevcut korunur).
            foreach (self::SECRET_KEYS as $k) {
                $val = trim((string) ($in[$k] ?? ''));
                if ($val !== '') {
                    $this->settingModel->set($k, $val, $adminId, 'string', 'payment');
                }
            }
            // Checkbox alanları → '1'/'0'
            foreach (self::BOOL_KEYS as $k) {
                $on = !empty($in[$k]) && (string) $in[$k] !== '0';
                $this->settingModel->set($k, $on ? '1' : '0', $adminId, 'string', 'payment');
            }
        }

        Setting::clearCache();
        $this->session->flash('success', 'Ödeme ayarları güncellendi.');
        $this->response->redirectTo('/admin/payment-settings');
    }
}
