<?php
/**
 * OzyConnect Panel — AuthController
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Middleware\RateLimitMiddleware;
use App\Models\User;

final class AuthController extends BaseController
{
    private readonly User $userModel;
    private readonly RateLimitMiddleware $rateLimiter;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->userModel   = $container->get(User::class);
        $this->rateLimiter = $container->get(RateLimitMiddleware::class);
    }

    /**
     * Login sayfası
     */
    public function loginForm(Request $request): void
    {
        if ($this->session->isAuthenticated()) {
            $this->redirect('/dashboard');
        }

        $this->view('auth.login', ['pageTitle' => 'Giriş'], 'site_auth');
    }

    /**
     * Login işlemi
     */
    public function login(Request $request): void
    {
        $email    = $request->filtered('email', FILTER_SANITIZE_EMAIL, '');
        $password = $request->post('password', '');

        if ($email === '' || $password === '') {
            $this->redirectWithError('/login', 'E-posta ve şifre gereklidir.');
        }

        $user = $this->userModel->findAuthUserByEmail($email);

        if ($user === false || !$this->userModel->verifyPassword($password, $user['password_hash'])) {
            // Başarısız denemeyi kaydet
            $this->rateLimiter->recordAttempt($request->ip(), $email, false);
            $this->logger->warning("Başarısız giriş: {$email}", 'Auth', ['ip' => $request->ip()]);
            $this->redirectWithError('/login', 'E-posta veya şifre hatalı.');
        }

        if (!(bool) $user['is_active']) {
            $this->redirectWithError('/login', 'Hesabınız devre dışı bırakılmıştır.');
        }

        // Başarılı giriş
        $this->rateLimiter->recordAttempt($request->ip(), $email, true);
        $this->session->login($user);
        // D1: Aktif mağazayı bu istek için de kur — updateLastLogin gibi tenant-scoped
        // çağrılar TenantContext'siz patlamasın (sonraki istekler index.php'den doldurur).
        if (isset($user['store_id'])) {
            $this->container->get(\App\Core\TenantContext::class)->setStoreId((int) $user['store_id']);
        }
        $this->userModel->updateLastLogin((int) $user['id']);

        // "Beni hatırla" seçildiyse cookie ömrünü 30 gün yap
        $remember = $request->post('remember', '');
        if ($remember) {
            $lifetime = 60 * 60 * 24 * 30; // 30 gün
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                session_id(),
                time() + $lifetime,
                $params['path'],
                $params['domain'] ?? '',
                $params['secure'],
                $params['httponly']
            );
        }

        $this->logger->info("Giriş başarılı: {$email}" . ($remember ? ' (beni hatırla)' : ''), 'Auth', ['ip' => $request->ip()]);
        $this->redirect('/dashboard');
    }

    /**
     * Çıkış
     */
    public function logout(Request $request): void
    {
        $this->logger->info("Çıkış: " . $this->session->get('user_email', ''), 'Auth');
        $this->session->logout();
        $this->redirect('/login');
    }

    // ─── Şifre Sıfırlama ──────────────────────────────────

    /**
     * Şifremi unuttum formu
     */
    public function forgotForm(Request $request): void
    {
        if ($this->session->isAuthenticated()) {
            $this->redirect('/dashboard');
        }

        $this->view('auth.forgot', ['pageTitle' => 'Şifremi Unuttum'], 'site_auth');
    }

    /**
     * Şifremi unuttum — e-posta gönder veya token göster
     */
    public function forgotSubmit(Request $request): void
    {
        $email = trim($request->filtered('email', FILTER_SANITIZE_EMAIL, ''));

        if ($email === '') {
            $this->redirectWithError('/password/forgot', 'Lütfen e-posta adresinizi girin.');
        }

        /** @var \App\Services\PasswordResetService $resetService */
        $resetService = $this->container->get(\App\Services\PasswordResetService::class);
        $result = $resetService->requestReset($email);

        if (!$result['success']) {
            $this->redirectWithError('/password/forgot', $result['message']);
        }

        // Token ekranda gösterilecek mi (SMTP yok)?
        if (isset($result['token'])) {
            $this->session->flash('reset_token', $result['token']);
            $this->session->flash('reset_email', $email);
        }

        $this->session->flash('success', $result['message']);
        $this->redirect('/password/forgot');
    }

    /**
     * Şifre sıfırlama formu (token ile)
     */
    public function resetForm(Request $request): void
    {
        if ($this->session->isAuthenticated()) {
            $this->redirect('/dashboard');
        }

        $token = $request->query('token', '');

        if ($token === '') {
            $this->redirectWithError('/password/forgot', 'Geçersiz sıfırlama bağlantısı.');
        }

        // Token geçerli mi kontrol et
        /** @var \App\Models\PasswordReset $resetModel */
        $resetModel = $this->container->get(\App\Models\PasswordReset::class);
        $resetRecord = $resetModel->verifyToken($token);

        if ($resetRecord === false) {
            $this->redirectWithError('/password/forgot', 'Sıfırlama kodu geçersiz veya süresi dolmuş. Lütfen yeni kod talep edin.');
        }

        $this->view('auth.reset', ['token' => $token, 'pageTitle' => 'Yeni Şifre'], 'site_auth');
    }

    /**
     * Şifre sıfırlama — yeni şifre kaydet
     */
    public function resetSubmit(Request $request): void
    {
        $token           = $request->post('token', '');
        $newPassword     = $request->post('password', '');
        $confirmPassword = $request->post('password_confirmation', '');

        if ($token === '') {
            $this->redirectWithError('/password/forgot', 'Geçersiz istek.');
        }

        /** @var \App\Services\PasswordResetService $resetService */
        $resetService = $this->container->get(\App\Services\PasswordResetService::class);
        $result = $resetService->resetPassword($token, $newPassword, $confirmPassword);

        if (!$result['success']) {
            $this->session->flash('error', $result['message']);
            $this->redirect('/password/reset?token=' . urlencode($token));
            return;
        }

        $this->session->flash('success', $result['message']);
        $this->redirect('/login');
    }
}
