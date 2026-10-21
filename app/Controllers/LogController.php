<?php
/**
 * OzyConnect Panel — LogController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Logger;
use App\Core\Request;

final class LogController extends BaseController
{
    private readonly Logger $loggerService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->loggerService = $container->get(Logger::class);
    }

    public function index(Request $request): void
    {
        $files = $this->loggerService->getLogFiles();
        $date = $request->query('date', date('Y-m-d'));
        $level = $request->query('level');

        $lines = $this->loggerService->readLog($date, 300, $level);

        // Faz 8 — mağaza-bazlı filtre: log satırındaki [Store#N] / [Global] etiketine göre.
        $store = trim((string) $request->query('store', ''));
        if ($store !== '') {
            $tag = ctype_digit($store) ? "[Store#{$store}]" : '[Global]';
            $lines = array_values(array_filter($lines, static fn($l) => str_contains((string) $l, $tag)));
        }

        $this->view('logs.index', [
            'pageTitle' => 'Sistem Logları',
            'files'     => $files,
            'lines'     => $lines,
            'date'      => $date,
            'level'     => $level,
            'store'     => $store,
            'stores'    => $this->container->get(\App\Core\Database::class)->fetchAll(
                "SELECT id, name FROM stores WHERE status <> 'archived' ORDER BY name ASC"
            ),
            '_adminEmail' => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /** Filtrelenmiş logları indirilebilir .txt olarak dışa aktar (tarih/seviye/mağaza). */
    public function export(Request $request): void
    {
        $date  = (string) $request->query('date', date('Y-m-d'));
        $level = (string) $request->query('level', '');
        $store = trim((string) $request->query('store', ''));
        $readLevel = in_array($level, ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'], true) ? $level : null;

        $lines = $this->loggerService->readLog($date, 100000, $readLevel);
        if ($store !== '') {
            $tag = ctype_digit($store) ? "[Store#{$store}]" : '[Global]';
            $lines = array_values(array_filter($lines, static fn($l) => str_contains((string) $l, $tag)));
        }

        $suffix = $store !== '' ? '-' . (ctype_digit($store) ? 'magaza' . $store : 'global') : '';
        if ($readLevel) { $suffix .= '-' . strtolower($readLevel); }
        $fname = 'log-' . $date . $suffix . '.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo "# OzyConnect log - {$date}" . ($store !== '' ? " - magaza={$store}" : ' - tum magazalar') . ($readLevel ? " - seviye={$readLevel}" : '') . "\n";
        echo '# satir: ' . count($lines) . ' - uretildi: ' . date('Y-m-d H:i:s') . "\n\n";
        echo implode("\n", $lines);
        exit;
    }

    public function clean(Request $request): void
    {
        $all = $request->post('delete_all', '0') === '1';
        $deleted = $this->loggerService->cleanOldLogs($all);
        $msg = $all ? 'Bugün hariç tüm loglar silindi' : "{$deleted} eski log dosyası silindi";
        $this->redirectWithSuccess('/logs', $msg);
    }
}
