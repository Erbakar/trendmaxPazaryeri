<?php
/**
 * OzyConnect Panel — AiResult
 *
 * Sağlayıcı-bağımsız AI yanıt değer nesnesi. Tüm adaptörler bunu döner;
 * üst katman (AbstractAiModule) token sayılarından kredi/maliyet hesaplar.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

final class AiResult
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly string $provider = '',
        /** @var array<string,mixed> Ham yanıt (debug/iz) */
        public readonly array $raw = [],
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /** Yanıt metnini JSON olarak çöz (AI'dan yapılandırılmış çıktı beklenen yerlerde). */
    public function json(): ?array
    {
        $t = trim($this->text);
        // Modeller bazen ```json ... ``` sarmalar; ilk { veya [ ile son } veya ] arasını al.
        if (str_starts_with($t, '```')) {
            $t = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $t) ?? $t;
            $t = trim($t);
        }
        $decoded = json_decode($t, true);
        return is_array($decoded) ? $decoded : null;
    }
}
