<?php
/**
 * OzyConnect Panel — Response
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

namespace App\Core;

final class Response
{
    /**
     * JSON yanıt gönder
     *
     * @param array<string, mixed> $data
     */
    public function json(array $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Başarılı JSON yanıt
     *
     * @param array<string, mixed> $data
     */
    public function success(array $data = [], string $message = 'Başarılı'): never
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * Hata JSON yanıt
     *
     * @param array<string, mixed> $errors
     */
    public function error(string $message = 'Bir hata oluştu', int $statusCode = 400, array $errors = []): never
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        $this->json($payload, $statusCode);
    }

    /**
     * Yönlendirme
     */
    public function redirect(string $url, int $statusCode = 302): never
    {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Panel içi yönlendirme.
     */
    public function redirectTo(string $path): never
    {
        $this->redirect(function_exists('url') ? url($path) : $path);
    }

    /**
     * 404 sayfası
     */
    public function notFound(string $message = 'Sayfa bulunamadı'): never
    {
        http_response_code(404);
        $this->renderErrorPage(
            404,
            'Sayfa bulunamadı',
            $message,
            'brand',
            '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>'
        );
    }

    /**
     * 403 yetkisiz
     */
    public function forbidden(string $message = 'Yetkisiz erişim'): never
    {
        http_response_code(403);
        $this->renderErrorPage(
            403,
            'Erişim yetkiniz yok',
            $message,
            'warning',
            '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
        );
    }

    /**
     * Stilli tam-sayfa hata ekranı (403/404). Yeni SaaS tasarımı; layout'suz,
     * kendi içinde stilli (Tailwind JIT'e bağımlı değil). Yalnız presentation:
     * status kodu ve tetikleme mantığı değişmez.
     */
    private function renderErrorPage(int $code, string $title, string $message, string $accent, string $iconPaths): never
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $codeTxt  = (string) $code;
        $titleTxt = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $msgTxt   = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Faz 8 — marka adı admin'den (Setting). brand() global fonksiyonu; heredoc fonksiyon çağıramaz, önce hesapla.
        $brandRaw  = function_exists('brand') ? brand('site_name') : 'Entegratör Yazılımı';
        $brandName = htmlspecialchars($brandRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $brandInit = htmlspecialchars(mb_strtoupper(mb_substr($brandRaw, 0, 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // accent: warning (403) -> amber, brand (404) -> mor
        $isWarn   = $accent === 'warning';
        $tileBg   = $isWarn ? 'rgba(245,158,11,.12)'  : 'rgba(91,92,235,.10)';
        $tileFg   = $isWarn ? '#d97706'               : '#5b5ceb';
        $tileBgD  = $isWarn ? 'rgba(245,158,11,.16)'  : 'rgba(124,125,240,.18)';
        $tileFgD  = $isWarn ? '#fbbf24'               : '#a5a6f6';

        echo <<<HTML
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$codeTxt} · {$brandName}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#f8fafc; --surface:#fff; --line:#e9edf3;
    --text:#0f172a; --muted:#64748b; --soft:#94a3b8;
    --brand:#5b5ceb; --brand-d:#4344d4;
    --tile-bg:{$tileBg}; --tile-fg:{$tileFg};
    --shadow:0 1px 2px rgba(16,24,40,.04), 0 24px 60px -24px rgba(16,24,40,.22);
  }
  html.dark{
    --bg:#090a10; --surface:#14161f; --line:#262a38;
    --text:#fafafa; --muted:#9aa3b2; --soft:#6b7280;
    --tile-bg:{$tileBgD}; --tile-fg:{$tileFgD};
    --shadow:0 1px 2px rgba(0,0,0,.4), 0 24px 60px -20px rgba(0,0,0,.6);
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0; font-family:'Poppins',ui-sans-serif,system-ui,sans-serif;
    background:
      radial-gradient(900px 480px at 50% -120px, rgba(91,92,235,.08), transparent 70%),
      var(--bg);
    color:var(--text); -webkit-font-smoothing:antialiased;
    display:grid; place-items:center; padding:24px;
  }
  .card{
    width:100%; max-width:460px; background:var(--surface);
    border:1px solid var(--line); border-radius:20px;
    box-shadow:var(--shadow); padding:40px 36px; text-align:center;
  }
  .tile{
    width:64px; height:64px; margin:0 auto 22px; border-radius:18px;
    background:var(--tile-bg); color:var(--tile-fg);
    display:grid; place-items:center;
  }
  .tile svg{width:30px; height:30px; stroke:currentColor; stroke-width:1.8; fill:none; stroke-linecap:round; stroke-linejoin:round}
  .eyebrow{font-size:11px; font-weight:600; letter-spacing:.12em; text-transform:uppercase; color:var(--soft); margin:0 0 8px}
  h1{font-size:21px; font-weight:700; letter-spacing:-.01em; margin:0 0 10px}
  p.msg{font-size:14.5px; line-height:1.6; color:var(--muted); margin:0 0 28px}
  .actions{display:flex; gap:10px; justify-content:center; flex-wrap:wrap}
  .btn{
    display:inline-flex; align-items:center; gap:8px; height:42px; padding:0 18px;
    border-radius:12px; font-size:13.5px; font-weight:600; cursor:pointer;
    text-decoration:none; border:1px solid transparent; transition:all .16s ease;
  }
  .btn svg{width:16px;height:16px;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round}
  .btn-primary{background:var(--brand); color:#fff}
  .btn-primary:hover{background:var(--brand-d)}
  .btn-ghost{background:transparent; color:var(--muted); border-color:var(--line)}
  .btn-ghost:hover{color:var(--text); background:rgba(100,116,139,.07)}
  .brandline{margin-top:30px; display:flex; align-items:center; justify-content:center; gap:8px; color:var(--soft); font-size:12px; font-weight:500}
  .brandmark{width:22px;height:22px;border-radius:7px;background:linear-gradient(135deg,#5b5ceb,#7c7df0);color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700}
</style>
<script>try{if(localStorage.getItem('ozy-theme')==='dark')document.documentElement.classList.add('dark');}catch(e){}</script>
</head>
<body>
  <main class="card" role="alert" aria-labelledby="errTitle">
    <div class="tile"><svg viewBox="0 0 24 24">{$iconPaths}</svg></div>
    <p class="eyebrow">Hata {$codeTxt}</p>
    <h1 id="errTitle">{$titleTxt}</h1>
    <p class="msg">{$msgTxt}</p>
    <div class="actions">
      <a href="javascript:history.back()" class="btn btn-ghost"><svg viewBox="0 0 24 24"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>Geri dön</a>
      <a href="/dashboard" class="btn btn-primary"><svg viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>Panele dön</a>
    </div>
    <div class="brandline"><span class="brandmark">{$brandInit}</span> {$brandName}</div>
  </main>
</body>
</html>
HTML;
        exit;
    }

    /**
     * View render et
     *
     * @param array<string, mixed> $data
     */
    public function view(string $viewPath, array $data = [], ?string $layout = 'main'): void
    {
        // UTF-8 charset header
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        // Veriyi extract et (view dosyasında $değişken olarak erişilebilir)
        extract($data, EXTR_SKIP);

        // View dosya yolu
        $viewFile = BASE_PATH . '/views/' . str_replace('.', '/', $viewPath) . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View dosyası bulunamadı: {$viewFile}");
        }

        if ($layout !== null) {
            // Layout ile render et
            $layoutFile = BASE_PATH . '/views/layouts/' . $layout . '.php';
            if (!file_exists($layoutFile)) {
                throw new \RuntimeException("Layout dosyası bulunamadı: {$layoutFile}");
            }

            // İçerik bölümünü output buffer ile yakala
            ob_start();
            require $viewFile;
            $content = (string) ob_get_clean();
            if (function_exists('app_rewrite_output')) {
                $content = app_rewrite_output($content);
            }

            // Layout'u render et ($content değişkeni layout içinde kullanılacak)
            ob_start();
            require $layoutFile;
            $html = (string) ob_get_clean();
            echo function_exists('app_rewrite_output') ? app_rewrite_output($html) : $html;
        } else {
            // Layoutsuz direk render
            ob_start();
            require $viewFile;
            $html = (string) ob_get_clean();
            echo function_exists('app_rewrite_output') ? app_rewrite_output($html) : $html;
        }
    }

    /**
     * Dosya indir
     */
    public function download(string $filePath, string $filename, string $mimeType = 'application/octet-stream'): never
    {
        if (!file_exists($filePath)) {
            $this->notFound('Dosya bulunamadı');
        }

        http_response_code(200);
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        readfile($filePath);
        exit;
    }
}
