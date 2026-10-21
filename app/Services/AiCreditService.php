<?php
/**
 * OzyConnect Panel — AiCreditService (AI kredi cüzdanı)
 *
 * Mağaza başına tam-sayı kredi bakiyesi. Tüm değişimler atomik (transaction + FOR UPDATE)
 * ve hareket defterine (store_credit_transactions) yazılır. AI işlemleri kredi harcar;
 * paket yenileme / kredi paketi satın alma kredi ekler.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Services\Ai\InsufficientCreditsException;

final class AiCreditService
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
    ) {}

    private function sid(?int $storeId): int
    {
        return $storeId ?? $this->tenant->currentStoreId();
    }

    public function ensureRow(int $storeId): void
    {
        $this->db->query("INSERT IGNORE INTO store_credits (store_id, balance) VALUES (:s, 0)", ['s' => $storeId]);
    }

    public function balance(?int $storeId = null): int
    {
        $row = $this->db->fetchOne("SELECT balance FROM store_credits WHERE store_id = :s", ['s' => $this->sid($storeId)]);
        return $row === false ? 0 : (int) $row['balance'];
    }

    public function hasCredits(int $cost, ?int $storeId = null): bool
    {
        return $cost <= 0 || $this->balance($storeId) >= $cost;
    }

    /**
     * Kredi düş (AI kullanımı). Yetersizse InsufficientCreditsException.
     * @param array{type?:string,id?:int,note?:string} $ref
     * @return int Yeni bakiye
     */
    public function deduct(string $action, int $cost, array $ref = [], ?int $storeId = null, ?int $userId = null): int
    {
        $sid = $this->sid($storeId);
        if ($cost <= 0) {
            return $this->balance($sid);
        }
        $this->ensureRow($sid);
        return (int) $this->db->transaction(function () use ($sid, $action, $cost, $ref, $userId) {
            $row = $this->db->fetchOne("SELECT balance FROM store_credits WHERE store_id = :s FOR UPDATE", ['s' => $sid]);
            $bal = $row === false ? 0 : (int) $row['balance'];
            if ($bal < $cost) {
                throw new InsufficientCreditsException("Yetersiz kredi: gerekli {$cost}, mevcut {$bal}.");
            }
            $newBal = $bal - $cost;
            $this->db->query(
                "UPDATE store_credits SET balance = :b, total_used = total_used + :c WHERE store_id = :s",
                ['b' => $newBal, 'c' => $cost, 's' => $sid]
            );
            $this->ledger($sid, 'usage', -$cost, $newBal, $action, $ref, null, $userId);
            return $newBal;
        });
    }

    /**
     * Kredi ekle (satın alma / paket tahsisi / iade / düzeltme).
     * @param array{type?:string,id?:int} $ref
     * @return int Yeni bakiye
     */
    public function add(int $amount, string $type = 'purchase', array $ref = [], ?int $storeId = null, ?int $createdBy = null, ?string $note = null): int
    {
        $sid = $this->sid($storeId);
        if ($amount <= 0) {
            return $this->balance($sid);
        }
        $this->ensureRow($sid);
        return (int) $this->db->transaction(function () use ($sid, $amount, $type, $ref, $createdBy, $note) {
            $row = $this->db->fetchOne("SELECT balance FROM store_credits WHERE store_id = :s FOR UPDATE", ['s' => $sid]);
            $bal = $row === false ? 0 : (int) $row['balance'];
            $newBal = $bal + $amount;
            if (in_array($type, ['purchase', 'grant'], true)) {
                $this->db->query(
                    "UPDATE store_credits SET balance = :b, total_purchased = total_purchased + :a WHERE store_id = :s",
                    ['b' => $newBal, 'a' => $amount, 's' => $sid]
                );
            } else {
                $this->db->query("UPDATE store_credits SET balance = :b WHERE store_id = :s", ['b' => $newBal, 's' => $sid]);
            }
            $this->ledger($sid, $type, $amount, $newBal, null, $ref, $note, $createdBy);
            return $newBal;
        });
    }

    /** @param array{type?:string,id?:int} $ref */
    private function ledger(int $sid, string $type, int $amount, int $balanceAfter, ?string $action, array $ref, ?string $note, ?int $by): void
    {
        $this->db->query(
            "INSERT INTO store_credit_transactions
               (store_id,type,amount,balance_after,action,ref_type,ref_id,note,created_by,created_at)
             VALUES (:s,:t,:a,:ba,:ac,:rt,:ri,:n,:by,NOW())",
            [
                's' => $sid, 't' => $type, 'a' => $amount, 'ba' => $balanceAfter, 'ac' => $action,
                'rt' => $ref['type'] ?? null, 'ri' => $ref['id'] ?? null, 'n' => $note, 'by' => $by,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function transactions(?int $storeId = null, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM store_credit_transactions WHERE store_id = :s ORDER BY id DESC LIMIT " . (int) $limit,
            ['s' => $this->sid($storeId)]
        );
    }
}
