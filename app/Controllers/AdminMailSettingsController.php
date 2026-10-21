<?php
/**
 * OzyConnect Panel — AdminMailSettingsController
 *
 * E-posta (SMTP) + Platform e-Arşiv (GİB) GLOBAL ayarları (`Setting`) + bağlantı testleri.
 * Pattern A: BaseController yok, AdminSession self-guard. settings.updated_by = null
 * (admin id'si users tablosunda yok → FK ihlali olmasın). Sırlar (smtp_pass / gib_password)
 * password gibi: yalnız POST'ta dolu gelirse güncellenir. İki form ayrı bölüm (section) gönderir.
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
use App\Services\NotificationService;
use App\Services\Invoice\GibEarsivProvider;

final class AdminMailSettingsController
{
    private const MAIL_TEXT   = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
    private const MAIL_SECRET = ['smtp_pass'];
    private const GIB_TEXT    = ['platform_gib_username', 'invoice_series', 'invoice_vat_rate', 'invoice_vat_mode'];
    private const GIB_SECRET  = ['platform_gib_password'];
    private const GIB_BOOL    = ['platform_gib_test_mode'];

    private readonly Container $container;
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Setting $settingModel;

    public function __construct(Container $container)
    {
        $this->container    = $container;
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
        $defaults = ['smtp_port' => '587', 'smtp_encryption' => 'tls'];
        $values = [];
        foreach (array_merge(self::MAIL_TEXT, self::MAIL_SECRET) as $k) {
            $values[$k] = (string) $this->settingModel->get($k, $defaults[$k] ?? '');
        }
        $this->render('admin.mail_settings', [
            'pageTitle'  => 'E-posta Ayarları',
            'values'     => $values,
            'mailSecret' => self::MAIL_SECRET,
            'stores'     => $this->storeRecipients(),
        ]);
    }

    /** e-Arşiv (GİB) ayarları — Faturalar menüsünden ayrı sayfa. */
    public function earsiv(Request $request): void
    {
        $defaults = ['platform_gib_test_mode' => '1', 'invoice_vat_rate' => '20', 'invoice_vat_mode' => 'included'];
        $values = [];
        foreach (array_merge(self::GIB_TEXT, self::GIB_SECRET, self::GIB_BOOL) as $k) {
            $values[$k] = (string) $this->settingModel->get($k, $defaults[$k] ?? '');
        }
        $this->render('admin.earsiv_settings', [
            'pageTitle' => 'e-Arşiv (GİB) Ayarları',
            'values'    => $values,
            'gibSecret' => self::GIB_SECRET,
        ]);
    }

    /** @return array<int,array<string,mixed>> Mağaza + sahip e-postası (toplu e-posta için). */
    private function storeRecipients(): array
    {
        return $this->container->get(\App\Core\Database::class)->fetchAll(
            "SELECT s.id, s.name,
                    (SELECT u.email FROM users u WHERE u.store_id = s.id AND u.role = 'admin' ORDER BY u.id ASC LIMIT 1) AS owner_email
             FROM stores s WHERE s.status <> 'archived' ORDER BY s.name ASC"
        );
    }

    public function save(Request $request): void
    {
        $section = (string) $request->post('section', '');
        $in = $request->post('s', []);
        if (!is_array($in)) { $in = []; }

        if ($section === 'smtp') {
            foreach (self::MAIL_TEXT as $k) {
                if (array_key_exists($k, $in)) {
                    $this->settingModel->set($k, trim((string) $in[$k]), null, 'string', 'mail');
                }
            }
            foreach (self::MAIL_SECRET as $k) {
                $v = trim((string) ($in[$k] ?? ''));
                if ($v !== '') { $this->settingModel->set($k, $v, null, 'string', 'mail'); }
            }
            $this->session->flash('success', 'SMTP ayarları kaydedildi.');
        } elseif ($section === 'earsiv') {
            foreach (self::GIB_TEXT as $k) {
                if (array_key_exists($k, $in)) {
                    $this->settingModel->set($k, trim((string) $in[$k]), null, 'string', 'earsiv');
                }
            }
            foreach (self::GIB_SECRET as $k) {
                $v = trim((string) ($in[$k] ?? ''));
                if ($v !== '') { $this->settingModel->set($k, $v, null, 'string', 'earsiv'); }
            }
            foreach (self::GIB_BOOL as $k) {
                $on = !empty($in[$k]) && (string) $in[$k] !== '0';
                $this->settingModel->set($k, $on ? '1' : '0', null, 'string', 'earsiv');
            }
            $this->session->flash('success', 'e-Arşiv ayarları kaydedildi.');
        } else {
            $this->session->flash('error', 'Geçersiz bölüm.');
        }

        Setting::clearCache();
        $this->response->redirectTo($section === 'earsiv' ? '/admin/earsiv-settings' : '/admin/mail-settings');
    }

    /** Toplu e-posta: seçili mağazaların sahip e-postalarına duyuru gönder (best-effort, senkron). */
    public function sendBulk(Request $request): void
    {
        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body', ''));
        $ids     = array_map('intval', (array) $request->post('store_ids', []));
        $ids     = array_values(array_filter($ids, static fn($i) => $i > 0));

        if ($subject === '' || $body === '') {
            $this->session->flash('error', 'Konu ve mesaj zorunludur.');
            $this->response->redirectTo('/admin/mail-settings');
            return;
        }
        if ($ids === []) {
            $this->session->flash('error', 'En az bir mağaza seçin.');
            $this->response->redirectTo('/admin/mail-settings');
            return;
        }

        $targets = [];
        foreach ($this->storeRecipients() as $r) {
            if (!in_array((int) $r['id'], $ids, true)) { continue; }
            $em = trim((string) ($r['owner_email'] ?? ''));
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) { $targets[$em] = true; }
        }
        if ($targets === []) {
            $this->session->flash('error', 'Seçili mağazalarda geçerli sahip e-postası bulunamadı.');
            $this->response->redirectTo('/admin/mail-settings');
            return;
        }

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937">' . nl2br($body) . '</div>';
        $notifier = $this->container->get(NotificationService::class);
        $sent = 0; $fail = 0;
        foreach (array_keys($targets) as $em) {
            try { $notifier->sendMail($em, $subject, $html) ? $sent++ : $fail++; }
            catch (\Throwable) { $fail++; }
        }

        $msg = $sent . ' mağazaya gönderildi' . ($fail > 0 ? ', ' . $fail . ' başarısız (SMTP ayarlarını kontrol edin)' : '') . '.';
        $this->session->flash($sent > 0 ? 'success' : 'error', $msg);
        $this->response->redirectTo('/admin/mail-settings');
    }

    /** SMTP testi: kaydedilmiş ayarlarla verilen adrese test maili gönder. */
    public function testSmtp(Request $request): void
    {
        $to = trim((string) $request->post('to', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Geçerli bir e-posta adresi girin.');
            $this->response->redirectTo('/admin/mail-settings');
            return;
        }
        try {
            $ok = $this->container->get(NotificationService::class)->sendMail(
                $to,
                'Entegratör Yazılımı — SMTP Test',
                '<p>Bu bir test e-postasıdır. SMTP ayarlarınız çalışıyor. 🎉</p><p>— Entegratör Yazılımı Yönetim Paneli</p>'
            );
            if ($ok) {
                $this->session->flash('success', 'Test e-postası gönderildi: ' . $to . ' (gelen kutusu/spam kontrol edin).');
            } else {
                $this->session->flash('error', 'E-posta gönderilemedi — SMTP ayarlarını (sunucu/port/kullanıcı/şifre) kontrol edin.');
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', 'SMTP hatası: ' . $e->getMessage());
        }
        $this->response->redirectTo('/admin/mail-settings');
    }

    /** Platform e-Arşiv (GİB) bağlantı testi — kaydedilmiş platform kimlik bilgileriyle. */
    public function testEarsiv(Request $request): void
    {
        $u    = (string) $this->settingModel->get('platform_gib_username', '');
        $p    = (string) $this->settingModel->get('platform_gib_password', '');
        $test = (string) $this->settingModel->get('platform_gib_test_mode', '1') === '1';
        if ($u === '' || $p === '') {
            $this->session->flash('error', 'Önce GİB kullanıcı adı ve şifresini kaydedin.');
            $this->response->redirectTo('/admin/earsiv-settings');
            return;
        }
        try {
            $provider = $this->container->get(GibEarsivProvider::class);
            $provider->useExplicitCredentials($u, $p, $test);
            $res = $provider->testConnection();
            if (!empty($res['success'])) {
                $this->session->flash('success', 'GİB bağlantısı başarılı (' . ($test ? 'TEST' : 'CANLI') . ' ortam).');
            } else {
                $this->session->flash('error', 'GİB bağlantı hatası: ' . ($res['message'] ?? 'bilinmeyen'));
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', 'GİB test hatası: ' . $e->getMessage());
        }
        $this->response->redirectTo('/admin/earsiv-settings');
    }
}
