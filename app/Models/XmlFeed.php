<?php
/**
 * OzyConnect Panel — XmlFeed Model
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

final class XmlFeed extends BaseModel
{
    protected string $table = 'xml_feeds';

    /**
     * Token ile bul (public feed serving)
     *
     * @return array<string, mixed>|false
     */
    public function findByToken(string $token): array|false
    {
        return $this->findBy(['token' => $token]);
    }

    /**
     * Aktif feedleri getir
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActive(): array
    {
        return $this->all(['is_active' => 1], 'name ASC');
    }

    /**
     * Son üretim bilgisini güncelle
     */
    public function updateGenerated(int $feedId, int $count): bool
    {
        return $this->update($feedId, [
            'last_generated_at' => date('Y-m-d H:i:s'),
            'generated_count'   => $count,
        ]);
    }
}
