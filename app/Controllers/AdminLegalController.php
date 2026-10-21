<?php
/**
 * OzyConnect Panel — AdminLegalController (yasal metinler: Gizlilik/KVKK, Kullanım Koşulları, Çerez Politikası)
 *
 * İçerikler GLOBAL `Setting` (group='legal') altında saklanır; kurumsal site yasal sayfalarında basılır.
 * Boş bırakılan alan için public view kendi varsayılan (gömülü) metnini gösterir.
 * AdminAuthMiddleware arkasında (Pattern A: AdminSession self-guard, AdminMarketingController ile aynı desen).
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

final class AdminLegalController
{
    /** Düzenlenebilir yasal metin alanları (group='legal'). HTML içerir (summernote WYSIWYG). */
    private const KEYS = ['legal_privacy', 'legal_terms', 'legal_cookies'];
    /** Üst sınır — uzun HTML gövdesi (summernote) için. */
    private const MAX_LEN = 200000;

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
        foreach (self::KEYS as $k) { $values[$k] = (string) $this->settingModel->get($k, ''); }
        $this->render('admin.legal', [
            'pageTitle' => 'Yasal Metinler',
            'values'    => $values,
        ]);
    }

    public function save(Request $request): void
    {
        $adminId = null; // settings.updated_by FK → users(id); admin users tablosunda yok → null
        $in = $request->post('s', []);
        $in = is_array($in) ? $in : [];

        try {
            foreach (self::KEYS as $k) {
                if (array_key_exists($k, $in)) {
                    // HTML gövde — trim YOK (anlamlı boşluklar), yalnız uzunluk sınırı.
                    $this->settingModel->set($k, mb_substr((string) $in[$k], 0, self::MAX_LEN), $adminId, 'string', 'legal');
                }
            }
            Setting::clearCache();
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/legal');
            return;
        }

        $this->session->flash('success', 'Yasal metinler güncellendi.');
        $this->response->redirectTo('/admin/legal');
    }
}
