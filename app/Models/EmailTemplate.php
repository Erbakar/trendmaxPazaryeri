<?php
/**
 * OzyConnect Panel — EmailTemplate (Faz 8: admin-düzenlenebilir mail şablonları)
 *
 * GLOBAL tablo (tenantScoped=false). NotificationService gönderirken byKey() ile DB sürümünü render eder;
 * kayıt yoksa/aktif değilse çağıran kod-içi varsayılana düşer → regresyon yok.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class EmailTemplate extends BaseModel
{
    protected string $table = 'email_templates';
    protected bool $tenantScoped = false;

    /** @return array<int,array<string,mixed>> */
    public function allTemplates(): array
    {
        return $this->rawQuery("SELECT * FROM email_templates ORDER BY `key` ASC");
    }

    public function byKey(string $key): array|false
    {
        return $this->rawQueryOne("SELECT * FROM email_templates WHERE `key` = :k", ['k' => $key]);
    }

    /** Aktif şablonu döndür (yoksa false → çağıran fallback kullanır). */
    public function activeByKey(string $key): array|false
    {
        return $this->rawQueryOne(
            "SELECT * FROM email_templates WHERE `key` = :k AND is_active = 1",
            ['k' => $key]
        );
    }

    public function updateByKey(string $key, string $subject, string $bodyHtml, ?int $adminId = null): void
    {
        $this->db->query(
            "UPDATE email_templates SET subject = :s, body_html = :b, updated_by = :u WHERE `key` = :k",
            ['s' => $subject, 'b' => $bodyHtml, 'u' => $adminId, 'k' => $key]
        );
    }

    /**
     * {placeholder} değişkenlerini değiştir.
     * @param array<string,string|int> $vars
     */
    public static function renderVars(string $text, array $vars): string
    {
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{' . $k . '}'] = (string) $v;
        }
        return strtr($text, $repl);
    }
}
