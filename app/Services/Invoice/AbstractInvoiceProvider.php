<?php
/**
 * OzyConnect Panel — AbstractInvoiceProvider
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

namespace App\Services\Invoice;

use App\Core\Logger;

abstract class AbstractInvoiceProvider implements InvoiceProviderInterface
{
    protected readonly Logger $logger;
    protected string $baseUrl = '';
    protected int $timeout = 30;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * HTTP POST isteği
     *
     * @param array<string, mixed>|string $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpPost(string $url, array|string $data, array $headers = []): array
    {
        $ch = curl_init();

        $body = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;

        $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Fatura API cURL hatası: {$error}");
        }

        $decoded = json_decode((string) $response, true);

        $this->logger->debug(
            "Fatura API yanıt: HTTP {$httpCode}",
            'InvoiceProvider',
            ['url' => $url, 'code' => $httpCode]
        );

        return is_array($decoded) ? $decoded : ['raw' => (string) $response, 'httpCode' => $httpCode];
    }

    /**
     * HTTP GET isteği
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|string
     */
    protected function httpGet(string $url, array $headers = [], bool $raw = false): array|string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Fatura API cURL hatası: {$error}");
        }

        if ($raw) {
            return (string) $response;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : ['raw' => (string) $response];
    }
}
