<?php
/**
 * OzyConnect — StoreExportService (mağaza-bazlı veri dışa aktarımı / arşiv).
 *
 * `store_id` kolonu olan TÜM tablolardan ilgili mağazanın satırlarını + `stores` satırını
 * INSERT'ler hâlinde .sql üretir (arşiv/taşıma/KVKK). Tablo/kolon adları information_schema'dan
 * (kullanıcı girdisi değil); değerler PDO::quote ile güvenli. NOT: bu bir DIŞA AKTARIM/arşivdir,
 * otomatik geri-yükleme amaçlı değildir (tek tenant'ı canlıya geri yüklemek id/FK çakışması yaratır).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class StoreExportService
{
    public function __construct(private readonly Database $db) {}

    /** store_id kolonu olan tablolar (information_schema). @return list<string> */
    public function storeScopedTables(): array
    {
        $pdo = $this->db->getConnection();
        return $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'store_id'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Mağazanın tüm verisini .sql dökümü olarak üret. */
    public function dumpStoreSql(int $storeId, string $stamp): string
    {
        $pdo = $this->db->getConnection();
        $out  = "-- OzyConnect — Mağaza veri dışa aktarımı (store_id = {$storeId})\n";
        $out .= "-- Üretim: {$stamp}\n";
        $out .= "-- UYARI: Bu bir ARŞİV/DIŞA-AKTARIMDIR. Doğrudan canlıya geri yükleme ÖNERİLMEZ\n";
        $out .= "--        (birincil anahtar / yabancı anahtar çakışması olur). Taşıma/KVKK/arşiv amaçlıdır.\n";
        $out .= "SET NAMES utf8mb4;\n\n";

        // Önce stores satırının kendisi (id ile)
        $out .= $this->dumpRows($pdo, 'stores', 'id', $storeId);
        // Sonra store_id taşıyan tüm tablolar
        foreach ($this->storeScopedTables() as $table) {
            if ($table === 'stores') { continue; }
            $out .= $this->dumpRows($pdo, $table, 'store_id', $storeId);
        }
        return $out;
    }

    /** Bir tablodaki ilgili satırları INSERT olarak döker. */
    private function dumpRows(PDO $pdo, string $table, string $col, int $val): string
    {
        // $table/$col information_schema'dan veya sabit ('stores'/'store_id') — kullanıcı girdisi DEĞİL.
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$col}` = :v");
        $stmt->execute(['v' => $val]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return "-- {$table}: 0 satır\n\n";
        }
        $cols    = array_keys($rows[0]);
        $colList = '`' . implode('`,`', $cols) . '`';
        $sql     = "-- {$table}: " . count($rows) . " satır\n";
        foreach ($rows as $r) {
            $vals = [];
            foreach ($r as $v) {
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
            }
            $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(',', $vals) . ");\n";
        }
        return $sql . "\n";
    }

    /** Mağazanın işlem kaydı (audit) satırları — CSV için. @return list<array<string,mixed>> */
    public function auditRows(int $storeId, int $limit = 5000): array
    {
        return $this->db->fetchAll(
            "SELECT created_at, actor_type, actor_email, action, target, result, ip_address
             FROM system_audit_log WHERE store_id = :s ORDER BY id DESC LIMIT {$limit}",
            ['s' => $storeId]
        );
    }
}
