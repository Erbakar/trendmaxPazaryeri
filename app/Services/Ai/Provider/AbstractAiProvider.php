<?php
/**
 * OzyConnect Panel — AbstractAiProvider
 *
 * Ortak HTTP (curl) tabanı — AbstractMarketplace deseni: timeout, retry (429/5xx),
 * üstel bekleme, JSON gönder/al. Adaptörler bunu genişletip sağlayıcıya özel
 * istek/yanıt eşlemesini yapar. Bağımlılık-hafif: AiClient tarafından (DI değil)
 * apiKey + baseUrl ile kurulur.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai\Provider;

use RuntimeException;

abstract class AbstractAiProvider implements AiProviderInterface
{
    protected int $timeout = 60;        // AI yanıtları yavaş olabilir
    protected int $maxRetries = 3;
    protected int $retryBaseDelayMs = 1200;

    public function __construct(
        protected readonly string $apiKey,
        protected string $baseUrl = '',
    ) {}

    public function supportsVision(): bool { return true; }
    public function supportsBatch(): bool { return false; }

    /**
     * JSON POST — retry'li. Başarılıda çözülmüş gövde (array) döner; aksi halde RuntimeException.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>   $body
     * @return array<string,mixed>
     */
    protected function postJson(string $url, array $headers, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('AI isteği JSON kodlanamadı: ' . json_last_error_msg());
        }

        $hdr = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        $lastErr = '';
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $hdr,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                $lastErr = "Bağlantı hatası: {$cerr}";
                $this->sleep($this->retryBaseDelayMs * $attempt);
                continue;
            }

            $decoded = json_decode((string) $resp, true);

            if ($code >= 200 && $code < 300) {
                if (!is_array($decoded)) {
                    throw new RuntimeException('AI yanıtı geçersiz JSON.');
                }
                return $decoded;
            }

            // Hata mesajını çıkar
            $msg = '';
            if (is_array($decoded)) {
                $msg = (string) ($decoded['error']['message'] ?? $decoded['error']['status'] ?? $decoded['message'] ?? '');
            }
            $lastErr = "HTTP {$code}" . ($msg !== '' ? ": {$msg}" : '');

            if ($code === 429 || $code >= 500) {           // geçici → retry
                $this->sleep($this->retryBaseDelayMs * $attempt * 2);
                continue;
            }
            // 4xx (auth/format) → kalıcı, hemen at
            throw new RuntimeException("AI sağlayıcı hatası ({$this->name()}): {$lastErr}");
        }

        throw new RuntimeException("AI sağlayıcı ({$this->name()}) {$this->maxRetries} denemede yanıt vermedi: {$lastErr}");
    }

    protected function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
