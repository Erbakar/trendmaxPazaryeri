<?php
/**
 * OzyConnect — AdminNotificationController (üst-menü bildirim sayaçları / poll).
 *
 * Admin'in ilgilenmesi gereken BEKLEYEN işler: açık destek talebi + onay bekleyen
 * mağaza başvurusu + inceleme bekleyen belge. JS periyodik çağırır (sesli uyarı + rozet).
 * AdminAuthMiddleware (adminMw) arkasında.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Database;
use App\Core\Response;

final class AdminNotificationController
{
    private readonly Response $response;
    private readonly Database $db;

    public function __construct(Container $container)
    {
        $this->response = $container->get(Response::class);
        $this->db       = $container->get(Database::class);
    }

    public function poll(): void
    {
        $count = function (string $sql): int {
            try {
                return (int) ($this->db->fetchOne($sql)['c'] ?? 0);
            } catch (\Throwable $e) {
                return 0;
            }
        };
        $support   = $count("SELECT COUNT(*) c FROM support_tickets WHERE status = 'open'");
        $signups   = $count("SELECT COUNT(*) c FROM store_signup_requests WHERE status = 'pending'");
        $documents = $count("SELECT COUNT(*) c FROM store_documents WHERE status = 'pending'");

        $this->response->json([
            'support'   => $support,
            'signups'   => $signups,
            'documents' => $documents,
            'total'     => $support + $signups + $documents,
        ]);
    }
}
