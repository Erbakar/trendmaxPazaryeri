<?php
/**
 * OzyConnect Panel — AdminTestimonialController (Faz 10: kurumsal site "Referanslar" CRUD)
 *
 * Müşteri referansları (company/person/role/quote + opsiyonel logo). GLOBAL `Testimonial` modeli.
 * Logo yükleme: public/assets/uploads/site/ altına; DB'ye RELATIVE 'uploads/site/<dosya>' yazılır
 * (public references view'i asset() ile çözer; asset() 'uploads/...' yolunu public/assets/uploads/... olarak çözer).
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
use App\Models\Testimonial;

final class AdminTestimonialController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Testimonial $model;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->model        = $container->get(Testimonial::class);
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
        $this->render('admin.testimonials_index', [
            'pageTitle'    => 'Referanslar',
            'testimonials' => $this->model->allAdmin(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.testimonial_form', [
            'pageTitle'   => 'Yeni Referans',
            'testimonial' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $in = $this->collect($request);
        if ($in === null) {
            $this->response->redirectTo('/admin/testimonials/create');
            return;
        }
        try {
            $path = $this->handleUpload($request, 'logo_file');
            if ($path !== null) {
                $in['logo_url'] = $path;
            }
            $this->model->createOne($in);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/testimonials/create');
            return;
        }
        $this->session->flash('success', 'Referans eklendi.');
        $this->response->redirectTo('/admin/testimonials');
    }

    public function edit(Request $request, string $id): void
    {
        $row = $this->model->findOne((int) $id);
        if ($row === false) {
            $this->session->flash('error', 'Referans bulunamadı.');
            $this->response->redirectTo('/admin/testimonials');
            return;
        }
        $this->render('admin.testimonial_form', [
            'pageTitle'   => 'Referans Düzenle',
            'testimonial' => $row,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $row = $this->model->findOne((int) $id);
        if ($row === false) {
            $this->session->flash('error', 'Referans bulunamadı.');
            $this->response->redirectTo('/admin/testimonials');
            return;
        }
        $in = $this->collect($request);
        if ($in === null) {
            $this->response->redirectTo('/admin/testimonials/' . (int) $id . '/edit');
            return;
        }
        try {
            // Yeni logo yüklendiyse eskisini sil ve yolu değiştir; aksi halde logo_url anahtarı atlanır (mevcut korunur).
            $path = $this->handleUpload($request, 'logo_file');
            if ($path !== null) {
                $in['logo_url'] = $path;
                $this->deleteUploaded((string) ($row['logo_url'] ?? ''));
            }
            $this->model->updateOne((int) $id, $in);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/testimonials/' . (int) $id . '/edit');
            return;
        }
        $this->session->flash('success', 'Referans güncellendi.');
        $this->response->redirectTo('/admin/testimonials');
    }

    public function delete(Request $request, string $id): void
    {
        $row = $this->model->findOne((int) $id);
        if ($row === false) {
            $this->session->flash('error', 'Referans bulunamadı.');
        } else {
            $this->model->softDelete((int) $id);
            // Soft-delete: fiziksel logo korunur (geri alınabilirlik için silinmez).
            $this->session->flash('success', 'Referans silindi.');
        }
        $this->response->redirectTo('/admin/testimonials');
    }

    /** Form alanlarını model alanlarına topla (logo_url hariç). company+quote zorunlu. @return array<string,mixed>|null */
    private function collect(Request $request): ?array
    {
        $company = trim((string) $request->post('company', ''));
        $quote   = trim((string) $request->post('quote', ''));
        if ($company === '' || $quote === '') {
            $this->session->flash('error', 'Firma ve referans metni (alıntı) zorunludur.');
            return null;
        }
        return [
            'company'    => mb_substr($company, 0, 150),
            'person'     => mb_substr(trim((string) $request->post('person', '')), 0, 120) ?: null,
            'role'       => mb_substr(trim((string) $request->post('role', '')), 0, 120) ?: null,
            'quote'      => mb_substr($quote, 0, 20000),
            'sort_order' => max(0, (int) $request->post('sort_order', 0)),
            'is_active'  => $request->post('is_active') !== null ? 1 : 0,
        ];
    }

    /** AdminScreenshotController::handleUpload deseni — finfo MIME + 5MB; public/assets/uploads/site/ altına. */
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
            throw new \RuntimeException('Logo PNG, JPG, WEBP veya SVG olmalı.');
        }
        $dir = BASE_PATH . '/public/assets/uploads/site';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Yükleme klasörü oluşturulamadı.');
        }
        $filename = 'testimonial-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
            throw new \RuntimeException('Dosya kaydedilemedi.');
        }
        // DB'ye RELATIVE yol; public references view'i asset() ile çözer (asset → public/assets/uploads/site/...).
        return 'uploads/site/' . $filename;
    }

    /** Logo değişiminde eski dosyayı güvenli sil (path-traversal koruması). */
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
