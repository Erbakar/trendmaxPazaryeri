<?php
/**
 * OzyConnect Panel — ImportResult DTO
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

namespace App\Services\Import;

final class ImportResult
{
    public int $total = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public int $failed = 0;

    /** Kategori eşleştirme sayaçları */
    public int $categoryMatched = 0;
    public int $categoryUnmatched = 0;

    /** Marka eşleştirme sayaçları */
    public int $brandMatched = 0;
    public int $brandUnmatched = 0;

    /** @var array<int, array{row: int|string, error: string}> */
    public array $errors = [];

    public function addError(int|string $row, string $error): void
    {
        $this->errors[] = ['row' => $row, 'error' => $error];
        $this->failed++;
    }

    /**
     * Kategori eşleştirme oranı (0.0 - 1.0)
     */
    public function getCategoryMatchRate(): float
    {
        $total = $this->categoryMatched + $this->categoryUnmatched;
        return $total > 0 ? round($this->categoryMatched / $total, 4) : 0.0;
    }

    /**
     * Marka eşleştirme oranı (0.0 - 1.0)
     */
    public function getBrandMatchRate(): float
    {
        $total = $this->brandMatched + $this->brandUnmatched;
        return $total > 0 ? round($this->brandMatched / $total, 4) : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total'              => $this->total,
            'created'            => $this->created,
            'updated'            => $this->updated,
            'skipped'            => $this->skipped,
            'failed'             => $this->failed,
            'categoryMatchRate'  => $this->getCategoryMatchRate(),
            'brandMatchRate'     => $this->getBrandMatchRate(),
            'categoryMatched'    => $this->categoryMatched,
            'categoryUnmatched'  => $this->categoryUnmatched,
            'brandMatched'       => $this->brandMatched,
            'brandUnmatched'     => $this->brandUnmatched,
            'errors'             => $this->errors,
        ];
    }
}
