<?php
/**
 * OzyConnect Panel — Router
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

use RuntimeException;
use App\Services\AuthorizationService;

final class Router
{
    /** @var array<string, array<int, array{pattern: string, handler: array{0: string, 1: string}, middlewares: array<int, string>}>> */
    private array $routes = [];

    /** @var array<int, string> Genel middleware'ler */
    private array $globalMiddlewares = [];

    private readonly Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Global middleware ekle
     */
    public function middleware(string $middlewareClass): self
    {
        $this->globalMiddlewares[] = $middlewareClass;
        return $this;
    }

    /**
     * GET rota ekle
     *
     * @param array{0: string, 1: string} $handler [ControllerClass, method]
     * @param array<int, string> $middlewares
     */
    public function get(string $path, array $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /**
     * POST rota ekle
     *
     * @param array{0: string, 1: string} $handler
     * @param array<int, string> $middlewares
     */
    public function post(string $path, array $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    /**
     * PUT rota ekle
     *
     * @param array{0: string, 1: string} $handler
     * @param array<int, string> $middlewares
     */
    public function put(string $path, array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    /**
     * DELETE rota ekle
     *
     * @param array{0: string, 1: string} $handler
     * @param array<int, string> $middlewares
     */
    public function delete(string $path, array $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Rota kaydet (dahili)
     *
     * @param array{0: string, 1: string} $handler
     * @param array<int, string> $middlewares
     */
    private function addRoute(string $method, string $path, array $handler, array $middlewares): self
    {
        // Path'i regex pattern'e çevir: {id} → (?P<id>[^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = [
            'pattern'     => $pattern,
            'handler'     => $handler,
            'middlewares'  => $middlewares,
        ];

        return $this;
    }

    /**
     * İsteği rotala ve çalıştır
     */
    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path   = $request->getPath();

        // Trailing slash temizle (/orders/ → /orders) ama root hariç
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // Route parametrelerini çıkar
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);

                // Middleware'leri çalıştır
                $allMiddlewares = array_merge($this->globalMiddlewares, $route['middlewares']);
                foreach ($allMiddlewares as $middlewareClass) {
                    $middleware = $this->container->get($middlewareClass);
                    if (method_exists($middleware, 'handle')) {
                        $middleware->handle($request);
                    }
                }

                // Controller'ı çalıştır
                [$controllerClass, $methodName] = $route['handler'];
                /** @var AuthorizationService $authorization */
                $authorization = $this->container->get(AuthorizationService::class);
                $authorization->authorizeRoute($request, $controllerClass, $methodName);

                // Admin RBAC (platform): admin oturumu varsa + controller haritalıysa yetki zorla. Aksi NO-OP.
                $this->container->get(\App\Services\AdminAuthorizationService::class)
                    ->authorizeRoute($request, $controllerClass, $methodName);

                $controller = $this->container->get($controllerClass);

                if (!method_exists($controller, $methodName)) {
                    throw new RuntimeException(
                        "Metot bulunamadı: {$controllerClass}::{$methodName}"
                    );
                }

                // Parametreleri controller metoduna geç
                $controller->$methodName($request, ...array_values($params));
                return;
            }
        }

        // Eşleşme bulunamadı — 404
        $response = $this->container->get(Response::class);
        if ($request->isAjax()) {
            $response->error('Endpoint bulunamadı', 404);
        }
        $response->notFound();
    }
}
