<?php
/**
 * OzyConnect Panel — SupportTicketMessage (Destek talebi mesajları)
 *
 * MERCHANT: tenant-scoped (yalnız kendi store'unun mesajları). ADMIN: cross-store ham metodlar.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class SupportTicketMessage extends BaseModel
{
    protected string $table = 'support_ticket_messages';

    /** Merchant: ticket mesajları (store_id otomatik + ticket_id). @return array<int,array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->all(['ticket_id' => $ticketId], 'id ASC');
    }

    /** Admin: ticket mesajları (store filtresi YOK). @return array<int,array<string,mixed>> */
    public function adminForTicket(int $ticketId): array
    {
        return $this->rawQuery(
            "SELECT * FROM support_ticket_messages WHERE ticket_id = :t ORDER BY id ASC",
            ['t' => $ticketId]
        );
    }

    /** Admin: mesaj ekle. store_id açıkça verilir (admin'in tenant context'i yoktur). */
    public function adminCreate(int $ticketId, int $storeId, ?int $senderId, ?string $senderName, string $body): int
    {
        $this->db->query(
            "INSERT INTO support_ticket_messages (ticket_id, store_id, sender_type, sender_id, sender_name, body, created_at)
             VALUES (:t, :s, 'admin', :sid, :sn, :b, NOW())",
            ['t' => $ticketId, 's' => $storeId, 'sid' => $senderId, 'sn' => $senderName, 'b' => $body]
        );
        return (int) $this->db->lastInsertId();
    }
}
