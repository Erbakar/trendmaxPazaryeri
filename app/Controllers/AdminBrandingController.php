<?php
/**
 * OzyConnect Panel — AdminBrandingController (Faz 8: platform marka yönetimi)
 *
 * Sistem adı/logo/favicon/firma/sosyal/footer/SEO/SMTP → GLOBAL `Setting` (group='branding').
 * Merchant SettingController per-tenant StoreSetting'e yazar; bu AYRI: platform geneli + admin yetkisi.
 * AdminAuthMiddleware arkasında (Pattern A: BaseController yok, AdminSession self-guard).
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

final class AdminBrandingController
{
    /** Metin alanları (whitelist) — type=string, group=branding. */
    private const TEXT_KEYS = [
        'site_name', 'legal_name', 'brand_color',
        'contact_email', 'contact_phone', 'contact_address',
        'social_instagram', 'social_x', 'social_linkedin', 'social_facebook', 'social_youtube',
        'seo_default_title', 'seo_default_description', 'seo_default_keywords', 'seo_default_og_image',
        'google_analytics_id',
    ];
    /** HTML içerik (footer) — sınırlı sanitize ile saklanır. */
    private const HTML_KEYS = ['footer_html'];
    /** Ham kod alanları (analytics/reklam head kodu/harita iframe) — sanitize EDİLMEZ; admin-güvenli, scriptler korunur. */
    private const CODE_KEYS = ['head_code', 'google_maps_embed'];
    /** Dosya yükleme alanları: form input adı => Setting anahtarı. */
    private const UPLOAD_FIELDS = ['logo_file' => 'logo_url', 'logo_dark_file' => 'logo_dark_url', 'favicon_file' => 'favicon_url'];

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
            'site_name' => 'Entegratör Yazılımı', 'legal_name' => 'Entegratör Yazılımı', 'brand_color' => '#2563eb',
        ];
        $values = [];
        foreach (array_merge(self::TEXT_KEYS, self::HTML_KEYS, self::CODE_KEYS, ['logo_url', 'logo_dark_url', 'favicon_url']) as $k) {
            $values[$k] = (string) $this->settingModel->get($k, $defaults[$k] ?? '');
        }
        $this->render('admin.branding', [
            'pageTitle' => 'Site Ayarları',
            'values'    => $values,
        ]);
    }

    public function save(Request $request): void
    {
        // settings.updated_by FK → users(id). Admin oturumu admin_users tablosundadır; admin id'si
        // users'ta YOK → FK 1452 ihlali. Bu yüzden settings yazımında updated_by = null.
        $adminId = null;

        try {
            // Dosya yüklemeleri (logo / koyu logo / favicon)
            foreach (self::UPLOAD_FIELDS as $input => $settingKey) {
                $isFavicon = $settingKey === 'favicon_url';
                $path = $this->handleUpload($request, $input, $isFavicon);
                if ($path !== null) {
                    $this->deleteUploaded((string) $this->settingModel->get($settingKey, ''));
                    $this->settingModel->set($settingKey, $path, $adminId, 'string', 'branding');
                }
            }

            $in = $request->post('s', []);
            if (is_array($in)) {
                // Marka rengi: hex'e normalize (#rrggbb veya "R G B" kabul; geçersiz → boş = varsayılan mavi).
                if (array_key_exists('brand_color', $in)) {
                    $bc = trim((string) $in['brand_color']);
                    if (preg_match('/^#?[0-9a-fA-F]{6}$/', $bc)) {
                        $in['brand_color'] = '#' . strtolower(ltrim($bc, '#'));
                    } elseif (preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', $bc, $m)) {
                        $in['brand_color'] = sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
                    } else {
                        $in['brand_color'] = '';
                    }
                }
                foreach (self::TEXT_KEYS as $k) {
                    if (array_key_exists($k, $in)) {
                        $this->settingModel->set($k, mb_substr(trim((string) $in[$k]), 0, 500), $adminId, 'string', 'branding');
                    }
                }
                foreach (self::HTML_KEYS as $k) {
                    if (array_key_exists($k, $in)) {
                        $this->settingModel->set($k, $this->sanitizeHtml((string) $in[$k]), $adminId, 'string', 'branding');
                    }
                }
                // Analytics / reklam / harita kodları: HAM saklanır (scriptler korunur). Admin-güvenli alan.
                foreach (self::CODE_KEYS as $k) {
                    if (array_key_exists($k, $in)) {
                        $this->settingModel->set($k, mb_substr((string) $in[$k], 0, 20000), $adminId, 'string', 'branding');
                    }
                }
            }
            Setting::clearCache();
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/branding');
            return;
        }

        $this->session->flash('success', 'Marka ayarları güncellendi.');
        $this->response->redirectTo('/admin/branding');
    }

    /** Admin-yazımı footer HTML: script/iframe/on* kaldır (çıktıda da kullanılır ama girişte de temizle). */
    private function sanitizeHtml(string $html): string
    {
        $html = (string) preg_replace('#<\s*(script|iframe|object|embed|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = (string) preg_replace('#javascript:#i', '', $html);
        return mb_substr($html, 0, 20000);
    }

    private function handleUpload(Request $request, string $field, bool $isFavicon): ?string
    {
        $file = $request->file($field);
        if ($file === null || !is_array($file)) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Dosya yüklemesi tamamlanamadı.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Dosya doğrulanamadı.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new \RuntimeException('Dosya en fazla 5 MB olabilir.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $logoExt    = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $faviconExt = ['image/png' => 'png', 'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
        $allowed = $isFavicon ? $faviconExt : $logoExt;
        if (!isset($allowed[$mime])) {
            throw new \RuntimeException($isFavicon ? 'Favicon PNG, SVG veya ICO olmalı.' : 'Logo PNG, JPG, WEBP veya SVG olmalı.');
        }
        $dir = BASE_PATH . '/public/uploads/branding/global';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Yükleme klasörü oluşturulamadı.');
        }
        $prefix = $isFavicon ? 'favicon' : ($field === 'logo_dark_file' ? 'logo-dark' : 'logo');
        $filename = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
            throw new \RuntimeException('Dosya kaydedilemedi.');
        }
        $rel = 'uploads/branding/global/' . $filename;
        return function_exists('public_url') ? public_url($rel) : '/public/' . $rel;
    }

    private function deleteUploaded(string $url): void
    {
        if ($url === '') {
            return;
        }
        $baseDir = realpath(BASE_PATH . '/public/uploads/branding/global');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $marker = 'uploads/branding/global/';
        $pos = strpos($path, $marker);
        if ($pos === false || $baseDir === false) {
            return;
        }
        $real = realpath(BASE_PATH . '/public/' . substr($path, $pos));
        if ($real !== false && str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR) && is_file($real)) {
            @unlink($real);
        }
    }
}
