<?php
/**
 * OzyConnect Panel — NotificationService
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
use App\Models\EmailTemplate;
use App\Models\Setting;

final class NotificationService
{
    public function __construct(
        private readonly Setting $settingModel,
        private readonly Logger $logger,
        private readonly EmailTemplate $templateModel,
    ) {}

    /** Marka adı (Setting → fallback). */
    private function siteName(): string
    {
        $v = trim((string) $this->settingModel->get('site_name', 'Entegratör Yazılımı'));
        return $v !== '' ? $v : 'Entegratör Yazılımı';
    }

    /**
     * Faz 8 — Mail şablonundan e-posta gönder. DB'deki aktif şablon render edilir; yoksa $fallbackSubject/$fallbackBody.
     * {site_name} her zaman otomatik enjekte edilir.
     *
     * @param array<string,string|int> $vars
     */
    public function sendTemplate(string $to, string $key, array $vars = [], string $fallbackSubject = '', string $fallbackBody = ''): bool
    {
        $vars += ['site_name' => $this->siteName()];
        $tpl = $this->templateModel->activeByKey($key);
        if ($tpl !== false) {
            $subject = EmailTemplate::renderVars((string) $tpl['subject'], $vars);
            $body    = EmailTemplate::renderVars((string) $tpl['body_html'], $vars);
        } else {
            // Şablon yoksa kod-içi varsayılan (regresyon yok).
            $subject = $fallbackSubject !== '' ? EmailTemplate::renderVars($fallbackSubject, $vars) : $this->siteName();
            $body    = $fallbackBody !== ''    ? EmailTemplate::renderVars($fallbackBody, $vars)    : '';
            $this->logger->warning("E-posta şablonu bulunamadı: {$key} — fallback kullanıldı", 'Notification');
        }
        return $this->sendMail($to, $subject, $body);
    }

    /**
     * E-posta gönder (PHPMailer ile)
     */
    public function sendMail(string $to, string $subject, string $body): bool
    {
        $host       = (string) $this->settingModel->get('smtp_host', '');
        $port       = (int) $this->settingModel->get('smtp_port', 587);
        $user       = (string) $this->settingModel->get('smtp_user', '');
        $pass       = (string) $this->settingModel->get('smtp_pass', '');
        $encryption = (string) $this->settingModel->get('smtp_encryption', 'tls');

        if ($host === '' || $user === '' || $pass === '') {
            $this->logger->warning('SMTP ayarları eksik, e-posta gönderilemedi', 'Notification');
            return false;
        }

        try {
            // PHPMailer yüklü mü kontrol
            if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                $this->logger->error('PHPMailer yüklü değil. composer require phpmailer/phpmailer çalıştırın.', 'Notification');
                return false;
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = $encryption === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';

            $fromMail = (string) $this->settingModel->get('admin_email', $user);
            $siteName = (string) $this->settingModel->get('site_name', 'Entegratör Yazılımı');

            $mail->setFrom($fromMail, $siteName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $this->wrapHtml($subject, $body);
            $mail->AltBody = strip_tags($body);

            $mail->send();

            $this->logger->info("E-posta gönderildi: {$to} — {$subject}", 'Notification');
            return true;

        } catch (\Throwable $e) {
            $this->logger->error("E-posta gönderim hatası: " . $e->getMessage(), 'Notification');
            return false;
        }
    }

    /**
     * Stok uyarısı gönder
     *
     * @param array<int, array<string, mixed>> $lowStockProducts
     */
    public function sendStockAlert(array $lowStockProducts): bool
    {
        if (!(bool) $this->settingModel->get('notify_stock_alert', true)) {
            return false;
        }

        $adminEmail = (string) $this->settingModel->get('admin_email', '');
        if ($adminEmail === '') {
            return false;
        }

        $body = '<h2>⚠️ Kritik Stok Uyarısı</h2>';
        $body .= '<p>' . count($lowStockProducts) . ' üründe stok kritik seviyenin altında:</p>';
        $body .= '<table style="border-collapse:collapse;width:100%">';
        $body .= '<tr style="background:#f1f5f9"><th style="padding:8px;border:1px solid #e2e8f0;text-align:left">SKU</th><th style="padding:8px;border:1px solid #e2e8f0;text-align:left">Ürün</th><th style="padding:8px;border:1px solid #e2e8f0;text-align:right">Stok</th></tr>';

        foreach (array_slice($lowStockProducts, 0, 50) as $p) {
            $body .= '<tr>';
            $body .= '<td style="padding:8px;border:1px solid #e2e8f0">' . htmlspecialchars($p['sku'] ?? '', ENT_QUOTES) . '</td>';
            $body .= '<td style="padding:8px;border:1px solid #e2e8f0">' . htmlspecialchars($p['name'] ?? '', ENT_QUOTES) . '</td>';
            $body .= '<td style="padding:8px;border:1px solid #e2e8f0;text-align:right;color:#ef4444;font-weight:bold">' . ((int) ($p['stock_qty'] ?? 0)) . '</td>';
            $body .= '</tr>';
        }

        $body .= '</table>';

        return $this->sendMail($adminEmail, '⚠️ Kritik Stok Uyarısı — ' . $this->siteName(), $body);
    }

    /**
     * API hatası bildirimi
     */
    public function sendApiErrorAlert(string $marketplace, string $error): bool
    {
        if (!(bool) $this->settingModel->get('notify_api_error', true)) {
            return false;
        }

        $adminEmail = (string) $this->settingModel->get('admin_email', '');
        if ($adminEmail === '') {
            return false;
        }

        $body = '<h2>🔴 API Hatası</h2>';
        $body .= '<p><strong>Pazaryeri:</strong> ' . htmlspecialchars($marketplace, ENT_QUOTES) . '</p>';
        $body .= '<p><strong>Hata:</strong> ' . htmlspecialchars($error, ENT_QUOTES) . '</p>';
        $body .= '<p><strong>Zaman:</strong> ' . date('d.m.Y H:i:s') . '</p>';

        return $this->sendMail($adminEmail, "🔴 API Hatası — {$marketplace} — " . $this->siteName(), $body);
    }

    /**
     * HTML e-posta şablonu
     */
    private function wrapHtml(string $title, string $body): string
    {
        $site = htmlspecialchars($this->siteName(), ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        return <<<HTML
        <!DOCTYPE html>
        <html lang="tr">
        <head><meta charset="UTF-8"><title>{$title}</title></head>
        <body style="font-family:'Segoe UI',Roboto,sans-serif;background:#f8fafc;padding:20px;color:#1e293b">
          <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,.07)">
            <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:24px;color:#fff">
              <h1 style="margin:0;font-size:20px">{$site}</h1>
            </div>
            <div style="padding:24px">{$body}</div>
            <div style="padding:16px 24px;background:#f1f5f9;text-align:center;font-size:12px;color:#94a3b8">
              © {$year} {$site}
            </div>
          </div>
        </body>
        </html>
        HTML;
    }
}
