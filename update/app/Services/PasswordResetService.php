<?php
/**
 * OzyConnect Panel — PasswordResetService
 *
 * Şifre sıfırlama iş mantığını yönetir.
 * SMTP yapılandırılmışsa e-posta gönderir, aksi halde ekranda token gösterir.
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

namespace App\Services;

use App\Core\Logger;
use App\Models\PasswordReset;
use App\Models\User;

final class PasswordResetService
{
    private readonly PasswordReset $resetModel;
    private readonly User $userModel;
    private readonly Logger $logger;

    /** Dakikada izin verilen maksimum sıfırlama talebi */
    private const MAX_ATTEMPTS_PER_15MIN = 3;

    /** Token süresi (dakika) */
    private const TOKEN_LIFETIME_MINUTES = 15;

    public function __construct(PasswordReset $resetModel, User $userModel, Logger $logger)
    {
        $this->resetModel = $resetModel;
        $this->userModel  = $userModel;
        $this->logger     = $logger;
    }

    /**
     * Şifre sıfırlama talebi oluştur
     *
     * @return array{success: bool, message: string, token?: string, email_sent?: bool}
     */
    public function requestReset(string $email): array
    {
        // 1) Kullanıcı var mı?
        $user = $this->userModel->findByEmail($email);
        if ($user === false) {
            // Güvenlik: Kullanıcı olmasa bile aynı mesajı ver (enumeration saldırısı koruması)
            return [
                'success' => true,
                'message' => 'Eğer bu e-posta kayıtlıysa, şifre sıfırlama bağlantısı gönderildi.',
            ];
        }

        // 2) Aktif mi?
        if (!(bool) $user['is_active']) {
            return [
                'success' => false,
                'message' => 'Bu hesap devre dışı bırakılmıştır.',
            ];
        }

        // 3) Rate limiting
        $recentCount = $this->resetModel->recentAttemptCount($email, self::TOKEN_LIFETIME_MINUTES);
        if ($recentCount >= self::MAX_ATTEMPTS_PER_15MIN) {
            $this->logger->warning("Şifre sıfırlama rate limit: {$email}", 'Auth');
            return [
                'success' => false,
                'message' => 'Çok fazla sıfırlama talebi. Lütfen 15 dakika sonra tekrar deneyin.',
            ];
        }

        // 4) Token oluştur
        $tokenData = $this->resetModel->createToken($email);
        $this->logger->info("Şifre sıfırlama token oluşturuldu: {$email}", 'Auth');

        // 5) SMTP yapılandırılmış mı?
        $smtpConfigured = $this->isSmtpConfigured();

        if ($smtpConfigured) {
            $sent = $this->sendResetEmail($email, $tokenData['token'], $user['name'] ?? '');
            if ($sent) {
                return [
                    'success'    => true,
                    'message'    => 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.',
                    'email_sent' => true,
                ];
            }
            // E-posta gönderilemezse token'ı göster (fallback)
            $this->logger->warning("SMTP gönderim başarısız, token ekranda gösteriliyor: {$email}", 'Auth');
        }

        // SMTP yoksa veya başarısız olursa: token'ı ekranda göster
        return [
            'success'    => true,
            'message'    => 'Şifre sıfırlama kodu oluşturuldu.',
            'token'      => $tokenData['token'],
            'email_sent' => false,
        ];
    }

    /**
     * Token ile şifreyi sıfırla
     *
     * @return array{success: bool, message: string}
     */
    public function resetPassword(string $token, string $newPassword, string $confirmPassword): array
    {
        // 1) Şifreler eşleşiyor mu?
        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'message' => 'Şifreler eşleşmiyor.'];
        }

        // 2) Şifre uzunluğu
        if (mb_strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'Şifre en az 6 karakter olmalıdır.'];
        }

        // 3) Token geçerli mi?
        $resetRecord = $this->resetModel->verifyToken($token);
        if ($resetRecord === false) {
            return ['success' => false, 'message' => 'Geçersiz veya süresi dolmuş sıfırlama kodu.'];
        }

        // 4) Kullanıcıyı bul
        $user = $this->userModel->findByEmail($resetRecord['email']);
        if ($user === false) {
            return ['success' => false, 'message' => 'Kullanıcı bulunamadı.'];
        }

        // 5) Şifreyi güncelle
        $this->userModel->updatePassword((int) $user['id'], $newPassword);

        // 6) Kullanılan token'ı sil
        $this->resetModel->deleteTokensByEmail($resetRecord['email']);

        $this->logger->info("Şifre başarıyla sıfırlandı: {$resetRecord['email']}", 'Auth');

        return ['success' => true, 'message' => 'Şifreniz başarıyla güncellendi. Yeni şifrenizle giriş yapabilirsiniz.'];
    }

    /**
     * SMTP ayarları yapılandırılmış mı kontrol et
     */
    private function isSmtpConfigured(): bool
    {
        $host = trim((string) env('SMTP_HOST', ''));
        $user = trim((string) env('SMTP_USER', ''));

        return $host !== '' && $user !== '';
    }

    /**
     * Şifre sıfırlama e-postası gönder
     */
    private function sendResetEmail(string $email, string $token, string $userName): bool
    {
        try {
            $host       = env('SMTP_HOST', '');
            $port       = (int) env('SMTP_PORT', 587);
            $user       = env('SMTP_USER', '');
            $pass       = env('SMTP_PASS', '');
            $fromEmail  = env('SMTP_FROM_EMAIL', 'noreply@example.com');
            $fromName   = env('SMTP_FROM_NAME', 'OzyConnect');
            $encryption = env('SMTP_ENCRYPTION', 'tls');

            // Dinamik URL — installer ile kurulduğunda hangi sunucuda olursa olsun çalışır
            $appUrl  = $this->resolveBaseUrl();
            $resetUrl = $appUrl . '/password/reset?token=' . urlencode($token);

            $subject = 'OzyConnect - Şifre Sıfırlama';
            $htmlBody = $this->buildResetEmailHtml($userName, $resetUrl, $token);

            // PHPMailer varsa kullan, yoksa mail() ile dene
            $mailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
            if (class_exists($mailerClass)) {
                return $this->sendWithPhpMailer(
                    $host, $port, $user, $pass, $encryption,
                    $fromEmail, $fromName, $email, $subject, $htmlBody
                );
            }

            // Basit mail() fallback
            $headers  = "From: {$fromName} <{$fromEmail}>\r\n";
            $headers .= "Reply-To: {$fromEmail}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            return mail($email, $subject, $htmlBody, $headers);
        } catch (\Throwable $e) {
            $this->logger->error("Şifre sıfırlama e-posta hatası: " . $e->getMessage(), 'Auth');
            return false;
        }
    }

    /**
     * PHPMailer ile e-posta gönder
     */
    private function sendWithPhpMailer(
        string $host, int $port, string $user, string $pass, string $encryption,
        string $fromEmail, string $fromName, string $toEmail, string $subject, string $htmlBody
    ): bool {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $host;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $user;
        $mailer->Password   = $pass;
        $mailer->SMTPSecure = $encryption;
        $mailer->Port       = $port;
        $mailer->CharSet    = 'UTF-8';

        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($toEmail);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body    = $htmlBody;

        return $mailer->send();
    }

    /**
     * Sunucudan dinamik base URL çöz — installer ile kurulduğunda her yerde çalışır
     */
    private function resolveBaseUrl(): string
    {
        // Önce uygulama helper fonksiyonunu dene
        if (function_exists('app_base_url')) {
            $url = app_base_url();
            if ($url !== '' && $url !== '/') {
                return rtrim($url, '/');
            }
        }

        // $_SERVER'dan dinamik hesapla
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    /**
     * Şifre sıfırlama e-posta HTML'i
     */
    private function buildResetEmailHtml(string $userName, string $resetUrl, string $token): string
    {
        $name = htmlspecialchars($userName ?: 'Kullanıcı');
        $safeUrl = htmlspecialchars($resetUrl);
        $safeToken = htmlspecialchars($token);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="tr">
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f5;">
        <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px;">
        <tr><td align="center">
        <table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
            <tr><td style="background:linear-gradient(135deg,#1967f2,#1355cb);padding:32px 36px;text-align:center;">
                <h1 style="margin:0;color:#fff;font-size:22px;letter-spacing:-.02em;">OzyConnect</h1>
                <p style="margin:8px 0 0;color:rgba(255,255,255,.8);font-size:13px;">Şifre Sıfırlama</p>
            </td></tr>
            <tr><td style="padding:36px;">
                <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px;">Merhaba <strong>{$name}</strong>,</p>
                <p style="margin:0 0 24px;color:#555;font-size:14px;line-height:1.7;">
                    Hesabınız için şifre sıfırlama talebi aldık. Aşağıdaki butona tıklayarak yeni şifrenizi belirleyebilirsiniz.
                </p>
                <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
                    <a href="{$safeUrl}" style="display:inline-block;padding:14px 36px;background:#1967f2;color:#fff;text-decoration:none;border-radius:10px;font-size:14px;font-weight:600;">
                        Şifremi Sıfırla
                    </a>
                </td></tr></table>
                <p style="margin:24px 0 0;color:#888;font-size:12px;line-height:1.6;">
                    Bu bağlantı <strong>15 dakika</strong> geçerlidir.<br>
                    Eğer bu talebi siz yapmadıysanız, bu e-postayı dikkate almayınız.
                </p>
                <hr style="margin:24px 0;border:0;border-top:1px solid #eee;">
                <p style="margin:0;color:#aaa;font-size:11px;text-align:center;">
                    Sıfırlama kodu: <code style="background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:11px;">{$safeToken}</code>
                </p>
            </td></tr>
        </table>
        </td></tr></table>
        </body></html>
        HTML;
    }
}
