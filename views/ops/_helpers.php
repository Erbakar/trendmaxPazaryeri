<?php
/**
 * Ops panel — paylaşılan yardımcılar (çıktısız include). Admin layout (emerald) altında.
 * Eski _layout.php'den $badge buraya taşındı; premium pill'e çevrildi. $e fallback'i verir.
 */
$e = $e ?? static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** Sağlık seviyesi pill'i: ok=yeşil, degraded=amber, down=kırmızı. */
$opsBadge = static function (string $level): string {
    $map = [
        'ok'       => ['bg-success-subtle text-success', 'OK'],
        'degraded' => ['bg-warning-subtle text-warning', 'Degraded'],
        'down'     => ['bg-danger-subtle text-danger',   'Down'],
    ];
    [$cls, $txt] = $map[$level] ?? ['bg-secondary-subtle text-secondary', $level];
    return '<span class="badge ' . $cls . '">' . htmlspecialchars((string) $txt, ENT_QUOTES, 'UTF-8') . '</span>';
};

/** İş/worker durum pill'i (running/done/idle/failed/dead/pending). */
$opsStatusPill = static function (string $status): string {
    $map = [
        'running' => 'bg-success-subtle text-success',
        'done'    => 'bg-success-subtle text-success',
        'idle'    => 'bg-secondary-subtle text-secondary',
        'pending' => 'bg-primary-subtle text-primary',
        'failed'  => 'bg-warning-subtle text-warning',
        'dead'    => 'bg-danger-subtle text-danger',
        'stopped' => 'bg-secondary-subtle text-secondary',
    ];
    $cls = $map[$status] ?? 'bg-secondary-subtle text-secondary';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
};
