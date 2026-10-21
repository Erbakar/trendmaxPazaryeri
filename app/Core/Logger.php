<?php
/**
 * OzyConnect Panel — Logger
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

final class Logger
{
    private const LEVELS = [
        'DEBUG'    => 0,
        'INFO'     => 1,
        'WARNING'  => 2,
        'ERROR'    => 3,
        'CRITICAL' => 4,
    ];

    private readonly string $logDir;
    private readonly int $minLevel;
    private readonly int $retentionDays;
    /** Faz 8 — mağaza-bazlı log etiketi için (opsiyonel; set edilmezse [Global]). */
    private ?TenantContext $tenant = null;

    public function __construct()
    {
        $this->logDir        = BASE_PATH . '/storage/logs';
        $this->minLevel      = self::LEVELS[strtoupper(env('LOG_LEVEL', 'DEBUG'))] ?? 0;
        $this->retentionDays = (int) env('LOG_RETENTION_DAYS', 7);
    }

    /** Faz 8 — TenantContext'i geç (Container kurar). Log satırlarına [Store#N] etiketi ekler. */
    public function setTenantContext(TenantContext $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Debug seviyesinde log
     *
     * @param array<string, mixed> $context
     */
    public function debug(string $message, string $channel = 'App', array $context = []): void
    {
        $this->log('DEBUG', $message, $channel, $context);
    }

    /**
     * Info seviyesinde log
     *
     * @param array<string, mixed> $context
     */
    public function info(string $message, string $channel = 'App', array $context = []): void
    {
        $this->log('INFO', $message, $channel, $context);
    }

    /**
     * Warning seviyesinde log
     *
     * @param array<string, mixed> $context
     */
    public function warning(string $message, string $channel = 'App', array $context = []): void
    {
        $this->log('WARNING', $message, $channel, $context);
    }

    /**
     * Error seviyesinde log
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, string $channel = 'App', array $context = []): void
    {
        $this->log('ERROR', $message, $channel, $context);
    }

    /**
     * Critical seviyesinde log
     *
     * @param array<string, mixed> $context
     */
    public function critical(string $message, string $channel = 'App', array $context = []): void
    {
        $this->log('CRITICAL', $message, $channel, $context);
    }

    /**
     * Exception logla
     */
    public function exception(\Throwable $e, string $channel = 'App'): void
    {
        $this->error(
            $e->getMessage(),
            $channel,
            [
                'exception' => get_class($e),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]
        );
    }

    /**
     * Ana log yazma metodu
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, string $channel, array $context): void
    {
        // Minimum seviye kontrolü
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
            return;
        }

        // Log dizini kontrolü
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }

        // Log satırı oluştur (Faz 8: mağaza etiketi — fail-safe).
        $timestamp = date('Y-m-d H:i:s');
        $storeTag = '[Global]';
        try {
            if ($this->tenant !== null && $this->tenant->hasStore()) {
                $storeTag = '[Store#' . $this->tenant->currentStoreId() . ']';
            }
        } catch (\Throwable) {
            $storeTag = '[Global]';
        }
        $line = "[{$timestamp}] {$storeTag} [{$level}] [{$channel}] {$message}";

        if (!empty($context)) {
            $line .= "\n  Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line .= "\n";

        // Dosyaya yaz
        $filename = $this->logDir . '/' . date('Y-m-d') . '.log';
        file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Eski log dosyalarını temizle
     */
    public function cleanOldLogs(bool $deleteAll = false): int
    {
        $deleted = 0;
        $today = date('Y-m-d') . '.log';

        $files = glob($this->logDir . '/*.log');
        if ($files === false) {
            return 0;
        }

        if ($deleteAll) {
            // Bugünkü hariç hepsini sil
            foreach ($files as $file) {
                if (basename($file) !== $today) {
                    if (@unlink($file)) $deleted++;
                }
            }
        } else {
            // Sadece retentionDays'den eski olanları sil
            $threshold = time() - ($this->retentionDays * 86400);
            foreach ($files as $file) {
                if (filemtime($file) < $threshold) {
                    if (@unlink($file)) $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Log dosyasını oku (log viewer için)
     *
     * @return array<int, string>
     */
    public function readLog(string $date, int $limit = 500, ?string $levelFilter = null): array
    {
        $filename = $this->logDir . '/' . $date . '.log';
        if (!file_exists($filename)) {
            return [];
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return [];
        }

        // Satırlara böl
        $lines = explode("\n", $content);

        // Seviye filtresi
        if ($levelFilter !== null && $levelFilter !== '') {
            $filtered = [];
            foreach ($lines as $line) {
                if (str_contains($line, "[{$levelFilter}]")) {
                    $filtered[] = $line;
                } elseif (str_starts_with(trim($line), 'Context:') && !empty($filtered)) {
                    // Önceki satırın context'i — dahil et
                    $filtered[] = $line;
                }
            }
            $lines = $filtered;
        }

        // Son $limit satır
        $lines = array_slice($lines, -$limit);

        return array_values($lines);
    }

    /**
     * Mevcut log dosyalarının listesi
     *
     * @return array<int, array{date: string, size: int}>
     */
    public function getLogFiles(): array
    {
        $files = glob($this->logDir . '/*.log');
        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'date' => basename($file, '.log'),
                'size' => filesize($file),
            ];
        }

        // En yeniden eskiye sırala
        usort($result, fn(array $a, array $b) => strcmp($b['date'], $a['date']));

        return $result;
    }
}
