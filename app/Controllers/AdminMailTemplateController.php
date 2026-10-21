<?php
/**
 * OzyConnect Panel — AdminMailTemplateController (Faz 8: admin-düzenlenebilir mail şablonları)
 *
 * 6 sistem şablonu (welcome/password_reset/subscription_ended/trial_ending/payment_confirmed/support_replied)
 * konu+gövde düzenleme. NotificationService gönderirken bu şablonları render eder.
 * AdminAuthMiddleware arkasında (Pattern A).
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
use App\Models\EmailTemplate;

final class AdminMailTemplateController
{
    private const LABELS = [
        'welcome'             => 'Hoş Geldin',
        'password_reset'      => 'Şifre Sıfırlama',
        'subscription_ended'  => 'Abonelik Sona Erdi',
        'trial_ending'        => 'Deneme Bitiyor',
        'payment_confirmed'   => 'Ödeme Onaylandı',
        'support_replied'     => 'Destek Yanıtlandı',
    ];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly EmailTemplate $templateModel;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->templateModel = $container->get(EmailTemplate::class);
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
        $this->render('admin.mail_templates_index', [
            'pageTitle' => 'Mail Şablonları',
            'templates' => $this->templateModel->allTemplates(),
            'labels'    => self::LABELS,
        ]);
    }

    public function edit(Request $request, string $key): void
    {
        $tpl = $this->templateModel->byKey($key);
        if ($tpl === false) {
            $this->session->flash('error', 'Şablon bulunamadı.');
            $this->response->redirectTo('/admin/mail-templates');
            return;
        }
        $this->render('admin.mail_template_form', [
            'pageTitle' => 'Mail Şablonu — ' . (self::LABELS[$key] ?? $key),
            'tpl'       => $tpl,
            'label'     => self::LABELS[$key] ?? $key,
        ]);
    }

    public function update(Request $request, string $key): void
    {
        $tpl = $this->templateModel->byKey($key);
        if ($tpl === false) {
            $this->session->flash('error', 'Şablon bulunamadı.');
            $this->response->redirectTo('/admin/mail-templates');
            return;
        }
        $subject = trim((string) $request->post('subject', ''));
        $body    = (string) $request->post('body_html', '');
        if ($subject === '' || trim($body) === '') {
            $this->session->flash('error', 'Konu ve gövde zorunludur.');
            $this->response->redirectTo('/admin/mail-templates/' . rawurlencode($key));
            return;
        }
        $this->templateModel->updateByKey($key, mb_substr($subject, 0, 255), mb_substr($body, 0, 60000), (int) $this->adminSession->adminId() ?: null);
        $this->session->flash('success', 'Şablon güncellendi.');
        $this->response->redirectTo('/admin/mail-templates');
    }
}
