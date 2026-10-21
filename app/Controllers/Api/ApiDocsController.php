<?php
/**
 * OzyConnect Panel — API Docs Controller
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

namespace App\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class ApiDocsController
{
    private readonly Response $response;

    public function __construct(Container $container)
    {
        $this->response = $container->get(Response::class);
    }

    /**
     * GET /api/docs — Dokümantasyon sayfası (public, layout yok)
     */
    public function index(Request $request): void
    {
        $baseUrl = function_exists('app_base_url')
            ? app_base_url()
            : rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');

        $viewFile = BASE_PATH . '/views/api/docs.php';
        if (!is_file($viewFile)) {
            $this->response->error('Döküman sayfası bulunamadı', 404);
        }

        $data = ['baseUrl' => $baseUrl];
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $html = (string) ob_get_clean();
        echo function_exists('app_rewrite_output') ? app_rewrite_output($html) : $html;
        exit;
    }
}
