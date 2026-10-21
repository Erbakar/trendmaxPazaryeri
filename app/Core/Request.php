<?php
/**
 * OzyConnect Panel — Request
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

final class Request
{
    private readonly string $method;
    private readonly string $uri;
    private readonly string $path;

    /** @var array<string, mixed> */
    private readonly array $query;

    /** @var array<string, mixed> */
    private readonly array $post;

    /** @var array<string, mixed> */
    private readonly array $server;

    /** @var array<string, mixed> */
    private readonly array $cookies;

    /** @var array<string, array<string, mixed>> */
    private readonly array $files;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $this->query   = $_GET;
        $this->post    = $_POST;
        $this->server  = $_SERVER;
        $this->cookies = $_COOKIE;
        $this->files   = $_FILES;

        // Path çıkar — query string hariç, base path çıkarılmış
        $path = parse_url($this->uri, PHP_URL_PATH) ?: '/';

        $configuredBase = function_exists('app_base_path') ? app_base_path() : '';
        $prefixes = array_filter(array_unique([
            $configuredBase !== '' ? $configuredBase . '/public' : '/public',
            $configuredBase,
            '/public',
            '/OzyConnect',
        ]));

        usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        $this->path = $path === '' ? '/' : $path;
    }

    public function getMethod(): string
    {
        // _method override (HTML form'lar PUT/DELETE desteklemez)
        if ($this->method === 'POST') {
            $override = $this->post('_method');
            if ($override !== null && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                return strtoupper($override);
            }
        }

        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * GET parametresi
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * POST parametresi
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Genel input (POST varsa POST, yoksa GET)
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Tüm inputları getir
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /**
     * Filtrelenmiş input
     */
    public function filtered(string $key, int $filter = FILTER_SANITIZE_SPECIAL_CHARS, mixed $default = null): mixed
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }

        $filtered = filter_var($value, $filter);
        return $filtered === false ? $default : $filtered;
    }

    /**
     * Integer input
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        return $filtered === false ? $default : $filtered;
    }

    /**
     * Boolean input
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Dosya upload
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Server değişkeni
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * IP adresi
     */
    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_CLIENT_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    /**
     * AJAX isteği mi kontrolü
     */
    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /**
     * POST isteği mi
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * JSON body parse
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
