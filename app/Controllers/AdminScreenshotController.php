<?php
/**
 * OzyConnect Panel — AdminScreenshotController (Faz 9: kurumsal site "Ekran Görüntüleri" CRUD)
 *
 * Hero slider + öne çıkan (big) + galeri (gallery) görselleri. GLOBAL `SiteScreenshot` modeli.
 * Görsel yükleme: public/assets/uploads/site/ altına; DB'ye RELATIVE 'uploads/site/<dosya>' yazılır
 * (public home view'i asset() ile çözer; asset() 'uploads/...' yolunu public/assets/uploads/... olarak çözer).
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
use App\Models\SiteScreenshot;

final class AdminScreenshotController
{
    /** Geçerli slot değerleri (enum hero|big|gallery). */
    private const SLOTS = ['hero' => 'Hero (üst slider)', 'big' => 'Öne Çıkan (tek)', 'gallery' => 'Galeri'];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly SiteScreenshot $model;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->model        = $container->get(SiteScreenshot::class);
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
        $this->render('admin.screenshots_index', [
            'pageTitle'   => 'Ekran Görüntüleri',
            'screenshots' => $this->model->allAdmin(),
            'slots'       => self::SLOTS,
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.screenshot_form', [
            'pageTitle' => 'Yeni Ekran Görüntüsü',
            'shot'      => null,
            'slots'     => self::SLOTS,
        ]);
    }

    public function store(Request $request): void
    {
        $in = $this->collect($request);
        try {
            $path = $this->handleUpload($request, 'image_file');
            if ($path === null) {
                throw new \RuntimeException('Görsel zorunludur.');
            }
            $in['image_path'] = $path;
            $this->model->createOne($in);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/screenshots/create');
            return;
        }
        $this->session->flash('success', 'Ekran görüntüsü eklendi.');
        $this->response->redirectTo('/admin/screenshots');
    }

    public function edit(Request $request, string $id): void
    {
        $shot = $this->model->findOne((int) $id);
        if ($shot === false) {
            $this->session->flash('error', 'Kayıt bulunamadı.');
            $this->response->redirectTo('/admin/screenshots');
            return;
        }
        $this->render('admin.screenshot_form', [
            'pageTitle' => 'Ekran Görüntüsü Düzenle',
            'shot'      => $shot,
            'slots'     => self::SLOTS,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $shot = $this->model->findOne((int) $id);
        if ($shot === false) {
            $this->session->flash('error', 'Kayıt bulunamadı.');
            $this->response->redirectTo('/admin/screenshots');
            return;
        }
        $in = $this->collect($request);
        try {
            // Yeni dosya yüklendiyse eskisini sil ve yolu değiştir; aksi halde mevcut korunur.
            $path = $this->handleUpload($request, 'image_file');
            if ($path !== null) {
                $in['image_path'] = $path;
                $this->deleteUploaded((string) ($shot['image_path'] ?? ''));
            }
            $this->model->updateOne((int) $id, $in);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/screenshots/' . (int) $id . '/edit');
            return;
        }
        $this->session->flash('success', 'Ekran görüntüsü güncellendi.');
        $this->response->redirectTo('/admin/screenshots');
    }

    public function delete(Request $request, string $id): void
    {
        $shot = $this->model->findOne((int) $id);
        if ($shot === false) {
            $this->session->flash('error', 'Kayıt bulunamadı.');
            $this->response->redirectTo('/admin/screenshots');
            return;
        }
        $this->model->softDelete((int) $id);
        // Soft-delete: fiziksel dosya korunur (geri alınabilirlik için silinmez).
        $this->session->flash('success', 'Ekran görüntüsü silindi.');
        $this->response->redirectTo('/admin/screenshots');
    }

    /** Form alanlarını model alanlarına topla (image_path hariç). @return array<string,mixed> */
    private function collect(Request $request): array
    {
        $slot = (string) $request->post('slot', 'gallery');
        if (!isset(self::SLOTS[$slot])) {
            $slot = 'gallery';
        }
        return [
            'slot'       => $slot,
            'title'      => mb_substr(trim((string) $request->post('title', '')), 0, 120) ?: null,
            'caption'    => mb_substr(trim((string) $request->post('caption', '')), 0, 200) ?: null,
            'sort_order' => max(0, (int) $request->post('sort_order', 0)),
            'is_active'  => $request->post('is_active') !== null ? 1 : 0,
        ];
    }

    /** AdminBrandingController::handleUpload deseni — finfo MIME + 5MB; public/assets/uploads/site/ altına. */
    private function handleUpload(Request $request, string $field): ?string
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
        $mime    = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        if (!isset($allowed[$mime])) {
            throw new \RuntimeException('Görsel PNG, JPG, WEBP veya SVG olmalı.');
        }
        $dir = BASE_PATH . '/public/assets/uploads/site';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Yükleme klasörü oluşturulamadı.');
        }
        $filename = 'shot-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
            throw new \RuntimeException('Dosya kaydedilemedi.');
        }
        // DB'ye RELATIVE yol; public home view'i asset() ile çözer (asset → public/assets/uploads/site/...).
        return 'uploads/site/' . $filename;
    }

    /** Görsel değişiminde/silmede eski dosyayı güvenli sil (path-traversal koruması). */
    private function deleteUploaded(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }
        $baseDir = realpath(BASE_PATH . '/public/assets/uploads/site');
        $marker  = 'uploads/site/';
        $pos     = strpos($relPath, $marker);
        if ($pos === false || $baseDir === false) {
            return;
        }
        $real = realpath(BASE_PATH . '/public/assets/' . substr($relPath, $pos));
        if ($real !== false && str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR) && is_file($real)) {
            @unlink($real);
        }
    }
}
