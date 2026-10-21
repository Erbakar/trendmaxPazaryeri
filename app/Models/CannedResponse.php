<?php
/**
 * OzyConnect Panel — CannedResponse (Hazır Yanıt şablonları)
 *
 * Mağaza-bazlı soru cevap şablonları. BaseModel tenant-scoped (store_id DAİMA aktif
 * bağlamdan gelir; mağaza yalnız kendi şablonlarını görür/oluşturur/siler).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Models;

final class CannedResponse extends BaseModel
{
    protected string $table = 'question_canned_responses';

    /**
     * Aktif mağazanın tüm hazır yanıtları (sıraya göre).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forStore(): array
    {
        return $this->all([], 'sort_order ASC, id DESC');
    }
}
