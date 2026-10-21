<?php
/**
 * OzyConnect Panel — AiProviderInterface
 *
 * Tüm AI sağlayıcı adaptörlerinin (Gemini, OpenAI-uyumlu, Claude) uyguladığı sözleşme.
 * Sağlayıcı-bağımsızlık: üst katman yalnız bu arayüze bağlıdır; admin panelinden
 * aktif sağlayıcı/model seçilir, en ucuz seçeneğe geçiş kod değişikliği gerektirmez.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai\Provider;

use App\Services\Ai\AiResult;

interface AiProviderInterface
{
    /**
     * Metin tabanlı sohbet/tamamlama.
     *
     * @param string                          $model    Sağlayıcıya özel model kimliği
     * @param string|null                     $system   Sistem talimatı
     * @param array<int,array{role:string,content:string}> $messages user/assistant sırası
     * @param array<string,mixed>             $opts     max_tokens, temperature, json (bool)
     */
    public function chat(string $model, ?string $system, array $messages, array $opts = []): AiResult;

    /**
     * Görsel + metin (vision). $imageBase64 ham base64 (data: öneki YOK).
     *
     * @param array<string,mixed> $opts
     */
    public function vision(string $model, string $prompt, string $imageBase64, string $mime = 'image/jpeg', ?string $system = null, array $opts = []): AiResult;

    public function supportsVision(): bool;

    public function supportsBatch(): bool;

    /** Sağlayıcı kısa adı: gemini | openai | claude */
    public function name(): string;
}
