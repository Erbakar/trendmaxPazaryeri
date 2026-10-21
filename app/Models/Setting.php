<?php
/**
 * OzyConnect Panel — Setting Model
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

final class Setting extends BaseModel
{
    protected string $table = 'settings';
    protected string $primaryKey = 'key';
    protected bool $tenantScoped = false; // GLOBAL ayarlar (D5); per-tenant icin store_settings

    /** @var array<string, mixed> Cache */
    private static array $cache = [];

    /**
     * Ayar değeri getir
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Cache kontrol
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = $this->rawQueryOne(
            "SELECT `value`, `type` FROM settings WHERE `key` = :key LIMIT 1",
            ['key' => $key]
        );

        if ($row === false) {
            return $default;
        }

        $value = $this->castValue($row['value'], $row['type']);
        self::$cache[$key] = $value;

        return $value;
    }

    /**
     * Ayar değeri yaz
     */
    public function set(
        string $key,
        mixed $value,
        ?int $userId = null,
        string $type = 'string',
        string $group = 'general',
        ?string $label = null
    ): void
    {
        $strValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $label ??= $key;

        $this->db->query(
            "INSERT INTO settings (`key`, `value`, `type`, `group`, `label`, `updated_by`, `updated_at`)
             VALUES (:key, :val, :type, :grp, :label, :uid, NOW())
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                `updated_by` = VALUES(`updated_by`),
                `updated_at` = NOW()",
            [
                'key' => $key,
                'val' => $strValue,
                'type' => $type,
                'grp' => $group,
                'label' => $label,
                'uid' => $userId,
            ]
        );

        // Cache güncelle
        self::$cache[$key] = $value;
    }

    /**
     * Toplu güncelle
     *
     * @param array<string, mixed> $settings
     */
    public function setMany(array $settings, ?int $userId = null): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $userId);
        }
    }

    /**
     * Grup bazlı ayarları getir
     *
     * @return array<string, mixed>
     */
    public function getByGroup(string $group): array
    {
        $rows = $this->rawQuery(
            "SELECT * FROM settings WHERE `group` = :grp ORDER BY `key` ASC",
            ['grp' => $group]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = [
                'value' => $this->castValue($row['value'], $row['type']),
                'label' => $row['label'],
                'type'  => $row['type'],
            ];
        }

        return $result;
    }

    /**
     * Tüm ayarları cache'e yükle (performans)
     */
    public function loadAll(): void
    {
        $rows = $this->rawQuery("SELECT `key`, `value`, `type` FROM settings");
        foreach ($rows as $row) {
            self::$cache[$row['key']] = $this->castValue($row['value'], $row['type']);
        }
    }

    /**
     * Tip dönüşümü
     */
    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int'    => (int) $value,
            'bool'   => (bool) $value && $value !== '0',
            'json'   => json_decode($value, true) ?? [],
            default  => $value,
        };
    }

    /**
     * Cache temizle
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
