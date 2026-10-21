<?php
/**
 * OzyConnect Panel — BaseModel
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

namespace App\Models;

use App\Core\Database;
use App\Core\TenantContext;
use PDOStatement;

abstract class BaseModel
{
    protected readonly Database $db;
    protected readonly TenantContext $tenant;
    protected string $table;
    protected string $primaryKey = 'id';

    /**
     * Bu model tenant-scoped mı? (true = tüm CRUD store_id ile zorunlu filtrelenir)
     * Global/paylaşımlı tablolar (settings, roles, marketplaces, marketplace_brands,
     * marketplace_categories, password_resets, login_attempts, backups,
     * schema_migrations, product_attributes, attribute_values) bunu false ilan eder.
     */
    protected bool $tenantScoped = true;

    public function __construct(Database $db, TenantContext $tenant)
    {
        $this->db = $db;
        $this->tenant = $tenant;
    }

    /**
     * Aktif mağaza ID'si — ham SQL kullanan alt sınıflar için yardımcı.
     * Tenant-scoped değilse 0 döner (raw sorguya store filtresi eklenmemeli).
     */
    protected function tenantId(): int
    {
        return $this->tenantScoped ? $this->tenant->currentStoreId() : 0;
    }

    public function isTenantScoped(): bool
    {
        return $this->tenantScoped;
    }

    // ─── CRUD ──────────────────────────────────────────────

    /**
     * ID ile tek kayıt getir
     *
     * @return array<string, mixed>|false
     */
    public function find(int $id): array|false
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $params = ['id' => $id];
        // PK ile cross-tenant okuma engellenir (find/findOrFail buildWhere'i atlar).
        if ($this->tenantScoped) {
            $sql .= " AND `store_id` = :__store_id";
            $params['__store_id'] = $this->tenant->currentStoreId();
        }
        $sql .= ' LIMIT 1';
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * ID ile tek kayıt getir — bulamazsa exception fırlat
     *
     * @return array<string, mixed>
     */
    public function findOrFail(int $id): array
    {
        $row = $this->find($id);
        if ($row === false) {
            throw new \RuntimeException("{$this->table} kaydı bulunamadı: #{$id}");
        }
        return $row;
    }

    /**
     * Koşula göre tek kayıt getir
     *
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>|false
     */
    public function findBy(array $conditions): array|false
    {
        [$where, $params] = $this->buildWhere($conditions);
        $sql = "SELECT * FROM `{$this->table}`";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        $sql .= ' LIMIT 1';
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Tüm kayıtları getir
     *
     * @param array<string, mixed> $conditions
     * @return array<int, array<string, mixed>>
     */
    public function all(array $conditions = [], string $orderBy = 'id DESC', ?int $limit = null): array
    {
        $sql = "SELECT * FROM `{$this->table}`";

        $params = [];
        // tenantScoped ise koşul boş olsa bile WHERE store_id zorunlu uygulanır.
        if ($this->tenantScoped || !empty($conditions)) {
            [$where, $params] = $this->buildWhere($conditions);
            if ($where !== '') {
                $sql .= " WHERE {$where}";
            }
        }

        $sql .= " ORDER BY {$orderBy}";

        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Sayfalı kayıt getir
     *
     * @param array<string, mixed> $conditions
     * @return array{data: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginate(
        int $page = 1,
        int $perPage = 25,
        array $conditions = [],
        string $orderBy = 'id DESC'
    ): array {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as cnt FROM `{$this->table}`";
        $dataSql  = "SELECT * FROM `{$this->table}`";

        $params = [];
        // tenantScoped ise koşul boş olsa bile WHERE store_id zorunlu uygulanır.
        if ($this->tenantScoped || !empty($conditions)) {
            [$where, $params] = $this->buildWhere($conditions);
            if ($where !== '') {
                $countSql .= " WHERE {$where}";
                $dataSql  .= " WHERE {$where}";
            }
        }

        $dataSql .= " ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}";

        $countRow = $this->db->fetchOne($countSql, $params);
        $total = (int) ($countRow['cnt'] ?? 0);

        $data = $this->db->fetchAll($dataSql, $params);

        return [
            'data'       => $data,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Yeni kayıt ekle
     *
     * @param array<string, mixed> $data
     * @return int Yeni kayıt ID'si
     */
    public function create(array $data): int
    {
        // Anti-spoof: tenant-scoped modelde store_id DAİMA aktif bağlamdan gelir;
        // caller'ın gönderdiği store_id (varsa) ezilir.
        if ($this->tenantScoped) {
            $data['store_id'] = $this->tenant->currentStoreId();
        }

        $columns = array_keys($data);
        $placeholders = array_map(fn(string $col) => ":{$col}", $columns);

        $sql = sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s)",
            $this->table,
            implode(', ', array_map(fn(string $c) => "`{$c}`", $columns)),
            implode(', ', $placeholders)
        );

        $this->db->query($sql, $data);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Kayıt güncelle
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $setClauses = [];
        $params = ['_id' => $id];

        // Anti-spoof: store_id güncellenemez (caller göndermiş olsa bile atılır).
        if ($this->tenantScoped) {
            unset($data['store_id']);
        }

        foreach ($data as $column => $value) {
            $setClauses[] = "`{$column}` = :{$column}";
            $params[$column] = $value;
        }

        if ($setClauses === []) {
            return false; // güncellenecek alan yok
        }

        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE `%s` = :_id",
            $this->table,
            implode(', ', $setClauses),
            $this->primaryKey
        );

        // PK ile cross-tenant güncellemeyi engelle.
        if ($this->tenantScoped) {
            $sql .= " AND `store_id` = :__store_id";
            $params['__store_id'] = $this->tenant->currentStoreId();
        }

        $stmt = $this->db->query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kayıt sil
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $params = ['id' => $id];
        // PK ile cross-tenant silmeyi engelle.
        if ($this->tenantScoped) {
            $sql .= " AND `store_id` = :__store_id";
            $params['__store_id'] = $this->tenant->currentStoreId();
        }
        $stmt = $this->db->query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kayıt sayısı
     *
     * @param array<string, mixed> $conditions
     */
    public function count(array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM `{$this->table}`";
        $params = [];

        // tenantScoped ise koşul boş olsa bile WHERE store_id zorunlu uygulanır.
        if ($this->tenantScoped || !empty($conditions)) {
            [$where, $params] = $this->buildWhere($conditions);
            if ($where !== '') {
                $sql .= " WHERE {$where}";
            }
        }

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Kayıt var mı
     *
     * @param array<string, mixed> $conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    // ─── Yardımcılar ──────────────────────────────────────

    /**
     * WHERE koşulu oluştur
     *
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function buildWhere(array $conditions): array
    {
        $clauses = [];
        $params = [];

        // ZORUNLU tenant filtresi — her tenant-scoped sorguya store_id eklenir.
        // (find/update/delete buildWhere'i atladığından onlar ayrıca yamalandı.)
        if ($this->tenantScoped) {
            $clauses[] = "`store_id` = :__store_id";
            $params['__store_id'] = $this->tenant->currentStoreId();
        }

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $clauses[] = "`{$column}` IS NULL";
            } elseif (is_array($value)) {
                // IN koşulu
                $placeholders = [];
                foreach ($value as $i => $v) {
                    $key = "{$column}_{$i}";
                    $placeholders[] = ":{$key}";
                    $params[$key] = $v;
                }
                $clauses[] = "`{$column}` IN (" . implode(', ', $placeholders) . ')';
            } else {
                $clauses[] = "`{$column}` = :{$column}";
                $params[$column] = $value;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Ham SQL çalıştır
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function rawQuery(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Ham SQL tek satır
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|false
     */
    protected function rawQueryOne(string $sql, array $params = []): array|false
    {
        return $this->db->fetchOne($sql, $params);
    }
}
