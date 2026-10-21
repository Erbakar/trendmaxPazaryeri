<?php
/**
 * OzyConnect Panel — AdminAuthController (Faz 3)
 *
 * Merchant'tan AYRI admin giriş akışı: parola → 2FA (TOTP/recovery) → admin paneli.
 * Şifre sıfırlama (tek kullanımlık token). admin_users + AdminSession + AdminAuthService.
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
use App\Services\AdminAuthService;

final class AdminAuthController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly AdminAuthService $adminAuth;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->adminAuth    = $container->get(AdminAuthService::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf'] = $this->session->csrfToken();
        $data['_flash'] = [
            'error'   => $this->session->getFlash('error'),
            'success' => $this->session->getFlash('success'),
        ];
        $this->response->view($view, $data, null); // standalone (merchant layout YOK)
    }

    public function showLogin(Request $request): void
    {
        if ($this->adminSession->isAuthenticated()) {
            $this->response->redirectTo('/admin');
        }
        $this->render('admin.login', ['pageTitle' => 'Admin Giriş']);
    }

    public function login(Request $request): void
    {
        $email = $request->filtered('email', FILTER_SANITIZE_EMAIL, '');
        $password = $request->post('password', '');
        $admin = $this->adminAuth->attemptLogin($email, $password, $request->ip(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($admin === false) {
            $this->session->flash('error', 'E-posta veya şifre hatalı.');
            $this->response->redirectTo('/admin/login');
        }
        // 2FA gerekiyorsa pending; değilse tam yetkili.
        $this->response->redirectTo($this->adminSession->isAuthenticated() ? '/admin' : '/admin/2fa');
    }

    public function show2fa(Request $request): void
    {
        if ($this->adminSession->isAuthenticated()) {
            $this->response->redirectTo('/admin');
        }
        if (!$this->adminSession->isPending2fa()) {
            $this->response->redirectTo('/admin/login');
        }
        $this->render('admin.twofa', ['pageTitle' => '2FA Doğrulama']);
    }

    public function verify2fa(Request $request): void
    {
        if (!$this->adminSession->isPending2fa()) {
            $this->response->redirectTo('/admin/login');
        }
        $code = trim((string) $request->post('code', ''));
        $ip = $request->ip();
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ok = $request->post('recovery', '') !== ''
            ? $this->adminAuth->useRecoveryCode($code, $ip, $ua)
            : $this->adminAuth->verifyTotp($code, $ip, $ua);
        if (!$ok) {
            $this->session->flash('error', 'Doğrulama kodu geçersiz.');
            $this->response->redirectTo('/admin/2fa');
        }
        $this->response->redirectTo('/admin');
    }

    public function logout(Request $request): void
    {
        $this->adminAuth->logout();
        $this->session->flash('success', 'Admin oturumu kapatıldı.');
        $this->response->redirectTo('/admin/login');
    }

    public function showForgot(Request $request): void
    {
        $this->render('admin.forgot', ['pageTitle' => 'Admin Şifre Sıfırlama']);
    }

    public function forgot(Request $request): void
    {
        $email = $request->filtered('email', FILTER_SANITIZE_EMAIL, '');
        $token = $this->adminAuth->createPasswordReset($email);
        // Güvenlik: kullanıcı var/yok ayrımı sızdırılmaz. (Gerçekte e-posta gönderilir.)
        $this->session->flash('success', 'Eğer bu e-posta kayıtlıysa sıfırlama bağlantısı gönderildi.');
        $this->response->redirectTo('/admin/login');
    }

    public function showReset(Request $request): void
    {
        $this->render('admin.reset', ['pageTitle' => 'Yeni Şifre', 'token' => (string) $request->get('token', '')]);
    }

    public function reset(Request $request): void
    {
        $token = (string) $request->post('token', '');
        $password = (string) $request->post('password', '');
        if (strlen($password) < 8) {
            $this->session->flash('error', 'Şifre en az 8 karakter olmalı.');
            $this->response->redirectTo('/admin/reset?token=' . urlencode($token));
        }
        if (!$this->adminAuth->resetPassword($token, $password)) {
            $this->session->flash('error', 'Geçersiz veya süresi dolmuş bağlantı.');
            $this->response->redirectTo('/admin/login');
        }
        $this->session->flash('success', 'Şifre güncellendi, giriş yapabilirsiniz.');
        $this->response->redirectTo('/admin/login');
    }
}
