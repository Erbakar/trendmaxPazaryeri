<?php
/**
 * OzyConnect Panel — AdminBlogController (Faz 10: kurumsal site Blog CRUD)
 *
 * Blog yazıları CRUD + soft-delete. GLOBAL `blog_posts` (tenant-scoped DEĞİL).
 * Kapak görseli: public/assets/uploads/site/ altına (AdminScreenshotController deseni); DB'ye
 * asset() ile çözülmüş doğrudan kullanılabilir URL yazılır (public blog görünümleri cover_url'i
 * asset() ile sarmadan <img src> içine basar).
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
use App\Models\BlogPost;

final class AdminBlogController
{
    /** Geçerli durum değerleri (enum draft|published). */
    private const STATUSES = ['draft' => 'Taslak', 'published' => 'Yayında'];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly BlogPost $posts;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->posts        = $container->get(BlogPost::class);
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
        $this->render('admin.blog_index', [
            'pageTitle' => 'Blog',
            'posts'     => $this->posts->allAdmin(),
            'statuses'  => self::STATUSES,
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.blog_form', [
            'pageTitle' => 'Yeni Yazı',
            'post'      => null,
            'statuses'  => self::STATUSES,
        ]);
    }

    public function store(Request $request): void
    {
        $title = trim((string) $request->post('title', ''));
        if ($title === '') {
            $this->session->flash('error', 'Başlık zorunludur.');
            $this->response->redirectTo('/admin/blog/create');
            return;
        }
        try {
            $data = $this->collect($request, $title, null);
            $cover = $this->handleUpload($request, 'cover_file');
            $data['cover_url'] = $cover; // store: her zaman set (null = kapaksız)
            $this->posts->createOne($data);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/blog/create');
            return;
        }
        $this->session->flash('success', 'Yazı oluşturuldu.');
        $this->response->redirectTo('/admin/blog');
    }

    public function edit(Request $request, string $id): void
    {
        $post = $this->posts->findOne((int) $id);
        if ($post === false) {
            $this->session->flash('error', 'Yazı bulunamadı.');
            $this->response->redirectTo('/admin/blog');
            return;
        }
        $this->render('admin.blog_form', [
            'pageTitle' => 'Yazı Düzenle',
            'post'      => $post,
            'statuses'  => self::STATUSES,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $postId = (int) $id;
        $post = $this->posts->findOne($postId);
        if ($post === false) {
            $this->session->flash('error', 'Yazı bulunamadı.');
            $this->response->redirectTo('/admin/blog');
            return;
        }
        $title = trim((string) $request->post('title', ''));
        if ($title === '') {
            $this->session->flash('error', 'Başlık zorunludur.');
            $this->response->redirectTo('/admin/blog/' . $postId . '/edit');
            return;
        }
        try {
            $data = $this->collect($request, $title, $postId);
            // Yeni dosya yüklendiyse yolu değiştir + eskisini sil; aksi halde cover_url anahtarı eklenmez (mevcut korunur).
            $cover = $this->handleUpload($request, 'cover_file');
            if ($cover !== null) {
                $data['cover_url'] = $cover;
                $this->deleteUploaded((string) ($post['cover_url'] ?? ''));
            }
            $this->posts->updateOne($postId, $data);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/blog/' . $postId . '/edit');
            return;
        }
        $this->session->flash('success', 'Yazı güncellendi.');
        $this->response->redirectTo('/admin/blog');
    }

    public function delete(Request $request, string $id): void
    {
        $post = $this->posts->findOne((int) $id);
        if ($post === false) {
            $this->session->flash('error', 'Yazı bulunamadı.');
        } else {
            $this->posts->softDelete((int) $id);
            // Soft-delete: fiziksel kapak korunur (geri alınabilirlik için silinmez).
            $this->session->flash('success', 'Yazı silindi.');
        }
        $this->response->redirectTo('/admin/blog');
    }

    /**
     * Form alanlarını model alanlarına topla (cover_url hariç). Slug üret/benzersizleştir,
     * durum normalize, published ise published_at boşsa NOW().
     *
     * @return array<string,mixed>
     */
    private function collect(Request $request, string $title, ?int $excludeId): array
    {
        $status = (string) $request->post('status', 'draft');
        if (!isset(self::STATUSES[$status])) {
            $status = 'draft';
        }

        // Slug: boşsa başlıktan üret, benzersizleştir.
        $rawSlug = trim((string) $request->post('slug', ''));
        $slug = $this->slugify($rawSlug !== '' ? $rawSlug : $title);
        if ($slug === '') {
            $slug = 'yazi';
        }
        $slug = $this->uniqueSlug($slug, $excludeId);

        // published_at: form (date) > NOW() (published ise) > null.
        $publishedAt = $this->parseDate((string) $request->post('published_at', ''));
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $excerpt = trim((string) $request->post('excerpt', ''));
        $body    = (string) $request->post('body', '');
        $author  = trim((string) $request->post('author', ''));

        return [
            'slug'         => mb_substr($slug, 0, 180),
            'title'        => mb_substr($title, 0, 200),
            'excerpt'      => $excerpt !== '' ? mb_substr($excerpt, 0, 400) : null,
            'body'         => $body !== '' ? $this->sanitizeHtml($body) : null,
            'status'       => $status,
            'published_at' => $publishedAt,
            'author'       => $author !== '' ? mb_substr($author, 0, 120) : null,
        ];
    }

    /** "YYYY-MM-DD" (HTML date input) → "YYYY-MM-DD 00:00:00"; geçersiz/boş → null. */
    private function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            return null;
        }
        return $d->format('Y-m-d') . ' 00:00:00';
    }

    /** TR karakter sadeleştir → küçük harf → a-z0-9- (slug). */
    private function slugify(string $text): string
    {
        $map = [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i̇' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
        ];
        $text = strtr($text, $map);
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /** Benzersiz değilse -2,-3,... ekle. */
    private function uniqueSlug(string $slug, ?int $excludeId): string
    {
        $candidate = $slug;
        $n = 2;
        while ($this->posts->slugExists($candidate, $excludeId)) {
            $candidate = $slug . '-' . $n;
            $n++;
        }
        return $candidate;
    }

    /** Admin-yazımı body HTML: script/iframe/style/on* kaldır (AdminBrandingController deseni). */
    private function sanitizeHtml(string $html): string
    {
        $html = (string) preg_replace('#<\s*(script|iframe|object|embed|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = (string) preg_replace('#javascript:#i', '', $html);
        return mb_substr($html, 0, 60000);
    }

    /**
     * AdminScreenshotController::handleUpload deseni — finfo MIME + 5MB; public/assets/uploads/site/ altına.
     * DB'ye doğrudan kullanılabilir URL döner (asset() ile çözülmüş; public blog görünümleri sarmaz).
     */
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
            throw new \RuntimeException('Kapak görseli PNG, JPG, WEBP veya SVG olmalı.');
        }
        $dir = BASE_PATH . '/public/assets/uploads/site';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Yükleme klasörü oluşturulamadı.');
        }
        $filename = 'blog-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
            throw new \RuntimeException('Dosya kaydedilemedi.');
        }
        $rel = 'uploads/site/' . $filename;
        // Public blog görünümleri cover_url'i doğrudan <img src> içine basar (asset() ile sarmaz),
        // bu yüzden DB'ye doğrudan kullanılabilir URL yazıyoruz.
        return function_exists('asset') ? asset($rel) : '/public/assets/' . $rel;
    }

    /** Kapak değişiminde eski dosyayı güvenli sil (path-traversal koruması). */
    private function deleteUploaded(string $url): void
    {
        if ($url === '') {
            return;
        }
        $baseDir = realpath(BASE_PATH . '/public/assets/uploads/site');
        $path    = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $marker  = 'uploads/site/';
        $pos     = strpos($path, $marker);
        if ($pos === false || $baseDir === false) {
            return;
        }
        $real = realpath(BASE_PATH . '/public/assets/' . substr($path, $pos));
        if ($real !== false && str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR) && is_file($real)) {
            @unlink($real);
        }
    }
}
