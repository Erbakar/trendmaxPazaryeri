<?php
/**
 * OzyConnect Panel — Notification (Faz 8: in-app bildirim merkezi — store-bağlı, tenantScoped=false)
 *
 * DUYURUDAN (AppUpdate) AYRI: mağazaya özel olaylar (abonelik/ödeme/destek). Navbar zili buradan beslenir.
 * Merchant okumaları açık store_id ile (BaseModel-tenant-scope değil — cron/admin de yazabilsin).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class Notification extends BaseModel
{
    protected string $table = 'notifications';
    protected bool $tenantScoped = false;

    public function push(int $storeId, string $type, string $title, string $body = '', string $link = '', ?int $userId = null): int
    {
        return $this->create([
            'store_id' => $storeId,
            'user_id'  => $userId,
            'type'     => in_array($type, ['subscription', 'payment', 'support', 'system'], true) ? $type : 'system',
            'title'    => mb_substr($title, 0, 200),
            'body'     => mb_substr($body, 0, 500),
            'link'     => mb_substr($link, 0, 255),
            'is_read'  => 0,
        ]);
    }

    public function forStore(int $storeId, int $limit = 20): array
    {
        $lim = max(1, min(100, $limit));
        return $this->rawQuery(
            "SELECT * FROM notifications WHERE store_id = :s ORDER BY id DESC LIMIT {$lim}",
            ['s' => $storeId]
        );
    }

    public function unreadCount(int $storeId): int
    {
        return (int) ($this->rawQueryOne(
            "SELECT COUNT(*) c FROM notifications WHERE store_id = :s AND is_read = 0",
            ['s' => $storeId]
        )['c'] ?? 0);
    }

    public function markAllRead(int $storeId): void
    {
        $this->db->query(
            "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE store_id = :s AND is_read = 0",
            ['s' => $storeId]
        );
    }
}
