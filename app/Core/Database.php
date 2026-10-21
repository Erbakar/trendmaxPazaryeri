<?php
/**
 * OzyConnect Panel — Database (PDO Singleton)
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

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;
    private int $transactionDepth = 0;

    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct()
    {
        $this->config = require BASE_PATH . '/config/database.php';
    }

    /**
     * PDO bağlantısını getir (lazy singleton)
     */
    public function getConnection(): PDO
    {
        if (self::$instance === null) {
            self::$instance = $this->createConnection();
        }

        return self::$instance;
    }

    /**
     * Bağlantının hâlâ canlı olup olmadığını kontrol et
     */
    public function ping(): bool
    {
        if (self::$instance === null) {
            return false;
        }

        try {
            self::$instance->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * MySQL "gone away" durumunda bağlantıyı yeniden kur.
     * Uzun süren API çağrılarından sonra, DB işlemleri öncesi çağrılmalı.
     */
    public function reconnect(): void
    {
        if (self::$instance !== null && !$this->ping()) {
            self::$instance = null;
            self::$instance = $this->createConnection();
        }
    }

    /**
     * Bağlantıyı zorla yenile (ping kontrolü olmadan)
     */
    public function forceReconnect(): void
    {
        self::$instance = null;
        self::$instance = $this->createConnection();
    }

    /**
     * Yeni PDO bağlantısı oluştur
     */
    private function createConnection(): PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['driver'],
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset']
        );

        try {
            $pdo = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['pass'],
                $this->config['options']
            );

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Veritabanı bağlantısı kurulamadı: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Transaction başlat (nested-safe with SAVEPOINT)
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionDepth === 0) {
            $result = $this->getConnection()->beginTransaction();
        } else {
            $this->getConnection()->exec("SAVEPOINT sp_{$this->transactionDepth}");
            $result = true;
        }
        $this->transactionDepth++;
        return $result;
    }

    /**
     * Transaction onayla (nested-safe)
     */
    public function commit(): bool
    {
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            return $this->getConnection()->commit();
        }
        $this->getConnection()->exec("RELEASE SAVEPOINT sp_{$this->transactionDepth}");
        return true;
    }

    /**
     * Transaction geri al (nested-safe)
     */
    public function rollBack(): bool
    {
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            return $this->getConnection()->rollBack();
        }
        $this->getConnection()->exec("ROLLBACK TO SAVEPOINT sp_{$this->transactionDepth}");
        return true;
    }

    /**
     * Atomik işlem wrapper'ı — callback içinde hata olursa rollback yapar
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Prepared statement çalıştır
     *
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Tek satır getir
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|false
     */
    public function fetchOne(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetch();
    }

    /**
     * Tüm satırları getir
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Son eklenen ID
     */
    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    /**
     * Bağlantıyı test et (installer için)
     *
     * @param array<string, mixed> $credentials
     */
    public static function testConnection(array $credentials): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $credentials['host'],
                (int) ($credentials['port'] ?? 3306)
            );

            new PDO($dsn, $credentials['user'], $credentials['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Singleton temizle (test için)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
