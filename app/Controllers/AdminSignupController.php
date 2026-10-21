<?php
/**
 * OzyConnect Panel — AdminSignupController (Faz 8: mağaza-oluştur başvuruları)
 *
 * Bekleyen başvuruları listele; "Onayla & Provizyon" → StoreProvisioningService (store+owner+trial+entitlement).
 * AdminAuthMiddleware (Pattern A).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Package;
use App\Models\Setting;
use App\Models\StoreSignupRequest;
use App\Services\StoreProvisioningService;

final class AdminSignupController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly StoreSignupRequest $signups;
    private readonly Package $packages;
    private readonly StoreProvisioningService $provisioning;
    private readonly Setting $settings;
    private readonly Database $db;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->signups      = $container->get(StoreSignupRequest::class);
        $this->packages     = $container->get(Package::class);
        $this->provisioning = $container->get(StoreProvisioningService::class);
        $this->settings     = $container->get(Setting::class);
        $this->db           = $container->get(Database::class);
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
        $status = (string) $request->query('status', '');
        $this->render('admin.signups_index', [
            'pageTitle' => 'Mağaza Başvuruları',
            'signups'   => $this->signups->adminList(null), // tümü gelir; filtre client-side (DataTables)
            'counts'    => $this->signups->statusCounts(),
            'current'   => $status,
            'packages'  => $this->packages->activePublic(),
            'regMode'    => (string) $this->settings->get('registration_mode', 'admin'),
            'regSubmode' => (string) $this->settings->get('registration_email_submode', 'admin'),
        ]);
    }

    /** Kayıt & onay modu ayarlarını kaydet (settings: registration_mode / registration_email_submode). */
    public function saveSettings(Request $request): void
    {
        $mode = (string) $request->post('registration_mode', 'admin');
        if (!in_array($mode, ['admin', 'email', 'auto'], true)) { $mode = 'admin'; }
        $sub = (string) $request->post('registration_email_submode', 'admin');
        if (!in_array($sub, ['admin', 'auto'], true)) { $sub = 'admin'; }

        // settings.updated_by → users(id) FK; admin'in merchant-user karşılığı YOK → null bırak.
        $this->settings->set('registration_mode', $mode, null, 'string', 'registration', 'Yeni mağaza kayıt onay modu');
        $this->settings->set('registration_email_submode', $sub, null, 'string', 'registration', 'E-posta doğrulama sonrası onay');

        $this->session->flash('success', 'Kayıt & onay modu güncellendi.');
        $this->response->redirectTo('/admin/signups');
    }

    /**
     * Onayla. Geri-alınabilir: pending VEYA daha önce reddedilmiş başvuru onaylanabilir.
     *  - Mağaza hiç yoksa → provizyonla (store+owner+trial+entitlement).
     *  - Mağaza varsa (önce onaylanıp sonra reddedilmiş = askıda) → yeniden AKTİFLEŞTİR.
     */
    public function approve(Request $request, string $id): void
    {
        $signupId = (int) $id;
        $signup = $this->signups->adminFind($signupId);
        if ($signup === false) {
            $this->session->flash('error', 'Başvuru bulunamadı.');
            $this->response->redirectTo('/admin/signups');
            return;
        }
        $adminId = (int) $this->adminSession->adminId() ?: null;
        $existingStoreId = (int) ($signup['store_id'] ?? 0);

        // Zaten mağazası var → tekrar provizyon YOK (çift abonelik olmasın); mağazayı aktifleştir + onaylı işaretle.
        if ($existingStoreId > 0) {
            $this->db->query("UPDATE stores SET status = 'active' WHERE id = :id", ['id' => $existingStoreId]);
            $this->signups->markReviewed($signupId, 'approved', $adminId, $existingStoreId, $signup['notes'] ?? null);
            $this->session->flash('success', 'Başvuru yeniden onaylandı; mağaza (#' . $existingStoreId . ') aktifleştirildi.');
            $this->response->redirectTo('/admin/signups');
            return;
        }

        $pkgId = (int) $request->post('package_id', (int) ($signup['requested_package_id'] ?? 0));
        if ($pkgId <= 0) {
            $this->session->flash('error', 'Lütfen bir paket seçin.');
            $this->response->redirectTo('/admin/signups');
            return;
        }
        $cycle = (string) $request->post('billing_cycle', (string) ($signup['billing_cycle'] ?? 'monthly'));
        try {
            $res = $this->provisioning->provision($signupId, $pkgId, $cycle);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', 'Provizyon başarısız: ' . $e->getMessage());
            $this->response->redirectTo('/admin/signups');
            return;
        }
        $msg = 'Mağaza oluşturuldu (#' . $res['store_id'] . '). Sahibe şifre-belirleme bağlantısı gönderildi.';
        if (!empty($res['reset_token'])) {
            // SMTP yapılandırılmamışsa token admin'e gösterilir (elle iletilsin).
            $msg .= ' SMTP kapalı — şifre token: ' . $res['reset_token'];
        }
        $this->session->flash('success', $msg);
        $this->response->redirectTo('/admin/signups');
    }

    /**
     * Reddet. Geri-alınabilir: pending VEYA onaylı başvuru reddedilebilir.
     *  - Başvurunun mağazası varsa (onaylıydı) → mağaza ASKIYA ALINIR (silinmez; yeniden onayla ile geri gelir).
     */
    public function reject(Request $request, string $id): void
    {
        $signupId = (int) $id;
        $signup = $this->signups->adminFind($signupId);
        if ($signup === false) {
            $this->session->flash('error', 'Başvuru bulunamadı.');
            $this->response->redirectTo('/admin/signups');
            return;
        }
        $adminId = (int) $this->adminSession->adminId() ?: null;
        $notes   = trim((string) $request->post('notes', ''));
        $storeId = (int) ($signup['store_id'] ?? 0);

        if ($storeId > 0) {
            // Onaylıydı → mağazayı askıya al (veri silinmez, geri alınabilir).
            $this->db->query("UPDATE stores SET status = 'suspended' WHERE id = :id", ['id' => $storeId]);
        }
        $this->signups->markReviewed($signupId, 'rejected', $adminId, $storeId ?: null, $notes !== '' ? $notes : ($signup['notes'] ?? null));
        $this->session->flash('success', $storeId > 0
            ? 'Başvuru reddedildi; mağaza (#' . $storeId . ') askıya alındı.'
            : 'Başvuru reddedildi.');
        $this->response->redirectTo('/admin/signups');
    }
}
