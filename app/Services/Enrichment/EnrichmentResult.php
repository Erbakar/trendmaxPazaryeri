<?php
/**
 * OzyConnect Panel — EnrichmentResult DTO
 * Zenginleştirme işlemi sonuç raporu
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

namespace App\Services\Enrichment;

final class EnrichmentResult
{
    public int $totalProcessed = 0;
    public int $categoryMatched = 0;
    public int $categoryUnmatched = 0;
    public int $brandMatched = 0;
    public int $brandUnmatched = 0;
    public int $imageFilled = 0;
    public int $imageMissing = 0;
    public int $priceNormalized = 0;
    public int $priceIncomplete = 0;
    public int $stockNormalized = 0;
    public int $stockIncomplete = 0;

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] */
    public array $errors = [];

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function merge(EnrichmentResult $other): void
    {
        $this->totalProcessed += $other->totalProcessed;
        $this->categoryMatched += $other->categoryMatched;
        $this->categoryUnmatched += $other->categoryUnmatched;
        $this->brandMatched += $other->brandMatched;
        $this->brandUnmatched += $other->brandUnmatched;
        $this->imageFilled += $other->imageFilled;
        $this->imageMissing += $other->imageMissing;
        $this->priceNormalized += $other->priceNormalized;
        $this->priceIncomplete += $other->priceIncomplete;
        $this->stockNormalized += $other->stockNormalized;
        $this->stockIncomplete += $other->stockIncomplete;
        $this->warnings = array_merge($this->warnings, $other->warnings);
        $this->errors = array_merge($this->errors, $other->errors);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'totalProcessed'    => $this->totalProcessed,
            'categoryMatched'   => $this->categoryMatched,
            'categoryUnmatched' => $this->categoryUnmatched,
            'brandMatched'      => $this->brandMatched,
            'brandUnmatched'    => $this->brandUnmatched,
            'imageFilled'       => $this->imageFilled,
            'imageMissing'      => $this->imageMissing,
            'priceNormalized'   => $this->priceNormalized,
            'priceIncomplete'   => $this->priceIncomplete,
            'stockNormalized'   => $this->stockNormalized,
            'stockIncomplete'   => $this->stockIncomplete,
            'warnings'          => $this->warnings,
            'errors'            => $this->errors,
        ];
    }
}
