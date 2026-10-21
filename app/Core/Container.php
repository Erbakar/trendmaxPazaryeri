<?php
/**
 * OzyConnect Panel — DI Container
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

final class Container
{
    /** @var array<string, object> Singleton örnekleri */
    private array $instances = [];

    /** @var array<string, callable> Fabrika fonksiyonları */
    private array $factories = [];

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Kendisini kaydet
        $this->instances[self::class] = $this;

        // Temel servisleri kaydet
        $this->registerCoreServices();
    }

    /**
     * Servis kaydet (factory)
     */
    public function bind(string $abstract, callable $factory): void
    {
        $this->factories[$abstract] = $factory;
    }

    /**
     * Singleton servis kaydet
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->factories[$abstract] = function () use ($abstract, $factory): object {
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = $factory($this);
            }
            return $this->instances[$abstract];
        };
    }

    /**
     * Hazır örnek kaydet
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Servis getir
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function get(string $abstract): object
    {
        // Zaten örneklenmiş mi?
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Factory var mı?
        if (isset($this->factories[$abstract])) {
            $instance = ($this->factories[$abstract])($this);
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        // Auto-resolve dene (class varsa)
        if (class_exists($abstract)) {
            return $this->resolve($abstract);
        }

        throw new RuntimeException("Servis bulunamadı: {$abstract}");
    }

    /**
     * Yapılandırma değeri getir
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Otomatik bağımlılık çözümleme (constructor injection)
     */
    private function resolve(string $class): object
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Sınıf örneklenemiyor: {$class}");
        }

        $constructor = $reflection->getConstructor();

        // Constructor yoksa direkt oluştur
        if ($constructor === null) {
            $instance = new $class();
            $this->instances[$class] = $instance;
            return $instance;
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $param) {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException(
                    "Bağımlılık çözümlenemedi: {$class}::\${$param->getName()}"
                );
            }
        }

        $instance = $reflection->newInstanceArgs($dependencies);
        $this->instances[$class] = $instance;
        return $instance;
    }

    /**
     * Core servisleri kaydet
     */
    private function registerCoreServices(): void
    {
        // Database — singleton
        $this->singleton(Database::class, fn() => new Database());

        // Logger — singleton (Faz 8: TenantContext geçilir → mağaza-bazlı log etiketi)
        $this->singleton(Logger::class, function () {
            $logger = new Logger();
            try {
                $logger->setTenantContext($this->get(TenantContext::class));
            } catch (\Throwable) {
                // TenantContext yoksa logger yine çalışır ([Global] etiketiyle)
            }
            return $logger;
        });

        // Session — singleton
        $this->singleton(Session::class, fn() => new Session());

        // TenantContext — singleton (aktif mağazanın TEK doğruluk kaynağı).
        // Web'de Session'dan, CLI/worker/API'de açıkça beslenir; asla static.
        $this->singleton(TenantContext::class, fn() => new TenantContext());

        // Request — singleton (her istek için tek)
        $this->singleton(Request::class, fn() => new Request());

        // Response — singleton
        $this->singleton(Response::class, fn() => new Response());

        // Router — singleton (container inject)
        $this->singleton(Router::class, fn(Container $c) => new Router($c));

        // EnrichmentDispatcher — interface → SyncEnrichmentDispatcher (MVP)
        // Gelecekte AsyncEnrichmentDispatcher ile swap edilebilir
        $this->singleton(
            \App\Services\Enrichment\EnrichmentDispatcher::class,
            fn(Container $c) => $c->get(\App\Services\Enrichment\SyncEnrichmentDispatcher::class)
        );
    }
}
