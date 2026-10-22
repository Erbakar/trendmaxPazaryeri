<?php
/**
 * OzyConnect Panel — AbstractMarketplace (Ortak API davranışları)
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

namespace App\Services\Marketplace;

use App\Core\Logger;
use RuntimeException;

abstract class AbstractMarketplace implements MarketplaceInterface
{
    /** Rate-limit cezasında beklenebilecek azami süre (sn) — üstü anında hata */
    private const RATE_LIMIT_MAX_WAIT_SEC = 120;

    /** Bir istek için azami rate-limit bekleme sayısı */
    private const RATE_LIMIT_MAX_WAITS = 5;

    protected readonly Logger $logger;
    protected string $baseUrl = '';
    protected int $timeout = 30;
    protected int $maxRetries = 3;
    protected int $retryBaseDelayMs = 1000;
    protected int $rateLimitPerSecond = 10;

    /** Ardışık istekler arası asgari süre (ms) — saniyede 1'den seyrek limitler için (ör. ÇiçekSepeti 5 sn) */
    protected int $minRequestIntervalMs = 0;

    /**
     * Ad çözümleme sabitleme (CURLOPT_RESOLVE: "host:port:ip"). Mağazanın kendi sitesine bağlanan servisler
     * (ör. WooCommerce) doğrulanmış IP'yi burada sabitler; DNS yeniden bağlama (rebinding) ile iç ağa
     * yönlendirme engellenir. Boşsa normal çözümleme.
     *
     * @var list<string>
     */
    protected array $curlResolve = [];

    /** Tüm istekler için ortak cURL seçenekleri: yalnız http/https; yönlendirme izlenmez. */
    private function baseCurlOptions(): array
    {
        $options = [
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($this->curlResolve !== []) {
            $options[CURLOPT_RESOLVE] = $this->curlResolve;
        }

        return $options;
    }

    /** @var float Son istek zamanı (rate limit için) */
    private float $lastRequestTime = 0;

    /** Sayfalama döngüsü için varsayılan üst sınırlar */
    protected int $pagingMaxPages = 60;
    protected float $pagingMaxSeconds = 45.0;

    /**
     * Aktif sayfalama döngülerinin durumu.
     *
     * @var array<string, array{page: int, started: float, maxPages: int, maxSeconds: float, signature: string, stopped: bool}>
     */
    private array $pagingGuards = [];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    // ─── Bekleme bütçesi (yükleme/görev durumu sorgulama) ──

    /**
     * Bir istek boyunca "işlem tamamlandı mı" beklemesine ayrılabilecek TOPLAM süre.
     *
     * Pazaryerleri toplu yükleme/görev sonucunu kuyrukta işler. Kod her yükleme için
     * birkaç saniye bekleyince, 70 yüklemelik bir yayınlama 10 dakikaya çıkıp isteği
     * zaman aşımına düşürüyordu. Yükleme pazaryeri tarafından zaten KABUL EDİLMİŞ
     * oluyor; beklemek yalnız "hemen doğrula" kolaylığı. Bütçe dolunca kalanlar
     * "işleniyor" olarak işaretlenir ve istek hızla biter.
     */
    protected float $pollBudgetSeconds = 20.0;

    /** Bu istekte beklemede geçen toplam süre (saniye) */
    private float $pollSpentSeconds = 0.0;

    /**
     * Durum sorgusu öncesi bekle. Bütçe dolduysa BEKLEMEZ ve false döner
     * (çağıran döngüsünü sonlandırmalıdır).
     */
    protected function pollWait(int $delayMs): bool
    {
        if ($this->pollSpentSeconds + ($delayMs / 1000) > $this->pollBudgetSeconds) {
            return false;
        }

        $this->sleep($delayMs);
        $this->pollSpentSeconds += $delayMs / 1000;

        return true;
    }

    protected function pollBudgetExhausted(): bool
    {
        return $this->pollSpentSeconds >= $this->pollBudgetSeconds;
    }

    // ─── Sayfalama koruması ───────────────────────────────

    /**
     * Sayfalama döngüsü başlat.
     *
     * Pazaryeri API'leri bazen "page" parametresini yok sayar ya da totalPages'i
     * gerçekte olduğundan çok büyük bildirir. Böyle bir durumda döngü binlerce
     * isteğe kadar sürüp süreci (ve paylaşımlı hosting'de tüm hesabı) kilitler.
     * Bu koruma sayfa sayısı / süre / tekrar eden yanıt sınırlarını uygular.
     */
    protected function startPaging(string $key, ?int $maxPages = null, ?float $maxSeconds = null): void
    {
        $this->pagingGuards[$key] = [
            'page'      => 0,
            'started'   => microtime(true),
            'maxPages'  => $maxPages ?? $this->pagingMaxPages,
            'maxSeconds'=> $maxSeconds ?? $this->pagingMaxSeconds,
            'signature' => '',
            'stopped'   => false,
        ];
    }

    /**
     * Bir sonraki sayfaya geçilebilir mi? Döngü koşuluna EKLENİR:
     *
     *     $this->startPaging('brands');
     *     do { ... } while ($page < $totalPages && $this->keepPaging('brands', $page, $items));
     *
     * @param mixed $items Bu sayfadan dönen kayıtlar (tekrar tespiti için)
     */
    protected function keepPaging(string $key, int $page, mixed $items = null): bool
    {
        if (!isset($this->pagingGuards[$key])) {
            $this->startPaging($key);
        }

        $guard = &$this->pagingGuards[$key];
        $guard['page'] = $page;

        if ($page >= $guard['maxPages']) {
            return $this->stopPaging($key, sprintf('sayfa sınırına ulaşıldı (%d)', $guard['maxPages']));
        }

        $elapsed = microtime(true) - $guard['started'];
        if ($elapsed >= $guard['maxSeconds']) {
            return $this->stopPaging($key, sprintf('süre sınırına ulaşıldı (%.0f sn)', $elapsed));
        }

        // API "page" parametresini yok sayıyorsa aynı içerik döner → sonsuz döngü
        if ($items !== null) {
            $signature = md5(serialize($items));
            if ($signature === $guard['signature']) {
                return $this->stopPaging($key, 'API aynı sayfayı tekrar döndürdü (sayfalama desteklenmiyor olabilir)');
            }
            $guard['signature'] = $signature;
        }

        return true;
    }

    /**
     * Döngü koruma nedeniyle erken kesildi mi? (Çağıran "kısmi sonuç" bilgisi verebilir.)
     */
    protected function pagingStopped(string $key): bool
    {
        return (bool) ($this->pagingGuards[$key]['stopped'] ?? false);
    }

    private function stopPaging(string $key, string $reason): bool
    {
        $this->pagingGuards[$key]['stopped'] = true;

        $this->logger->warning(
            sprintf('Sayfalama durduruldu [%s]: %s — sonuç kısmi olabilir.', $key, $reason),
            'MarketplaceAPI',
            ['key' => $key, 'page' => $this->pagingGuards[$key]['page'] ?? 0]
        );

        return false;
    }

    /**
     * Günlük ve hata metinleri için URL: sorgudaki kimlik/imza parametrelerinin değeri gizlenir
     * (ör. WooCommerce https mağazada consumer_key/consumer_secret sorguda taşınır). İstek değişmez.
     */
    protected static function redactUrl(string $url): string
    {
        return (string) preg_replace(
            '/([?&](?:consumer_key|consumer_secret|oauth_consumer_key|oauth_signature|oauth_nonce|oauth_token|'
            . 'api_?key|api_?secret|access_token|refresh_token|client_secret|secret|token|password|signature)=)[^&#\s]*/i',
            '$1***',
            $url
        );
    }

    /**
     * HTTP GET isteği (retry + rate limit + logging)
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpGet(string $url, array $headers = []): array
    {
        return $this->httpRequest('GET', $url, null, $headers);
    }

    /**
     * HTTP POST isteği
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpPost(string $url, ?array $data = null, array $headers = []): array
    {
        return $this->httpRequest('POST', $url, $data, $headers);
    }

    /**
     * HTTP PUT isteği
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpPut(string $url, ?array $data = null, array $headers = []): array
    {
        return $this->httpRequest('PUT', $url, $data, $headers);
    }

    /**
     * HTTP DELETE isteği
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpDelete(string $url, array $headers = []): array
    {
        return $this->httpRequest('DELETE', $url, null, $headers);
    }

    /**
     * Ham gövdeli POST (XML vb. — JSON'a çevrilmeden gönderilir).
     *
     * Hepsiburada listeleme servisleri gövdeyi XML bekler; httpPost() gövdeyi
     * json_encode ettiği için bu servislerde kullanılamaz.
     *
     * @param array<int, string> $headers Content-Type dahil TÜM header'lar
     * @return array<string, mixed>
     */
    protected function httpPostRaw(string $url, string $body, array $headers = []): array
    {
        $this->rateLimit();
        $logUrl = self::redactUrl($url);

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->logger->debug(
                    "API Ham İstek: POST {$logUrl} (Deneme {$attempt}/{$this->maxRetries})",
                    'MarketplaceAPI',
                    ['body_length' => strlen($body)]
                );

                $response = $this->executeCurlRaw($url, $body, $headers);

                if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
                    return $response['body'];
                }

                $bodyJson = json_encode($response['body'], JSON_UNESCAPED_UNICODE) ?: '';

                if ($response['httpCode'] === 429) {
                    $this->logger->warning('Rate limit aşıldı, bekleniyor...', 'MarketplaceAPI', ['url' => $logUrl]);
                    $this->sleep($this->retryBaseDelayMs * $attempt * 2);
                    continue;
                }

                if ($response['httpCode'] >= 400 && $response['httpCode'] < 500) {
                    $lastException = new RuntimeException("API hatası ({$response['httpCode']}): {$bodyJson}");
                    break;
                }

                $lastException = new RuntimeException("API sunucu hatası ({$response['httpCode']}): {$bodyJson}");
                $this->sleep($this->retryBaseDelayMs * $attempt);
            } catch (RuntimeException $e) {
                $lastException = $e;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->retryBaseDelayMs * $attempt);
                }
            }
        }

        $this->logger->error(
            'Ham API isteği başarısız',
            'MarketplaceAPI',
            ['url' => $logUrl, 'error' => $lastException?->getMessage()]
        );

        throw $lastException ?? new RuntimeException("API isteği başarısız: {$logUrl}");
    }

    /**
     * cURL ham gövde POST
     *
     * @param array<int, string> $headers
     * @return array{httpCode: int, body: array<string, mixed>}
     */
    private function executeCurlRaw(string $url, string $body, array $headers): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER     => array_values($headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        curl_setopt_array($ch, $this->baseCurlOptions());

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL hatası: {$error}");
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $decoded = ['raw_response' => (string) $response];
        }

        return [
            'httpCode' => (int) $httpCode,
            'body'     => $decoded,
        ];
    }

    /**
     * Multipart form-data ile dosya gönder (JSON dosyası upload vb.)
     *
     * @param array<string, string> $headers  Auth header'ları (Content-Type GÖNDERMEYİN, curl otomatik set eder)
     * @return array<string, mixed>
     */
    protected function httpPostMultipart(string $url, string $filePath, string $fieldName, array $headers = []): array
    {
        $this->rateLimit();
        $logUrl = self::redactUrl($url);

        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->logger->debug(
                    "API Multipart İstek: POST {$logUrl} (Deneme {$attempt}/{$this->maxRetries})",
                    'MarketplaceAPI',
                    ['file' => basename($filePath), 'field' => $fieldName]
                );

                $response = $this->executeCurlMultipart($url, $filePath, $fieldName, $headers);

                $this->logger->debug(
                    "API Multipart Yanıt: HTTP {$response['httpCode']}",
                    'MarketplaceAPI',
                    ['url' => $logUrl, 'http_code' => $response['httpCode']]
                );

                if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
                    return $response['body'];
                }

                if ($response['httpCode'] === 429) {
                    $this->logger->warning("Rate limit aşıldı, bekleniyor...", 'MarketplaceAPI', ['url' => $logUrl]);
                    $this->sleep($this->retryBaseDelayMs * $attempt * 2);
                    continue;
                }

                if ($response['httpCode'] === 401 || $response['httpCode'] === 403) {
                    throw new RuntimeException(
                        "API yetkilendirme hatası ({$response['httpCode']}): " .
                        json_encode($response['body'], JSON_UNESCAPED_UNICODE)
                    );
                }

                if ($response['httpCode'] >= 500) {
                    $lastException = new RuntimeException("API sunucu hatası ({$response['httpCode']})");
                    $this->sleep($this->retryBaseDelayMs * $attempt);
                    continue;
                }

                throw new RuntimeException(
                    "API hatası ({$response['httpCode']}): " .
                    json_encode($response['body'], JSON_UNESCAPED_UNICODE)
                );

            } catch (RuntimeException $e) {
                $lastException = $e;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->retryBaseDelayMs * $attempt);
                }
            }
        }

        throw $lastException ?? new RuntimeException("Multipart API isteği başarısız: {$logUrl}");
    }

    /**
     * Temel HTTP istek motoru (retry + rate limit + logging)
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function httpRequest(string $method, string $url, ?array $data, array $headers): array
    {
        $this->rateLimit();
        $logUrl = self::redactUrl($url);

        $lastException = null;
        $rateLimitWaits = 0;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->logger->debug(
                    "API İstek: {$method} {$logUrl} (Deneme {$attempt}/{$this->maxRetries})",
                    'MarketplaceAPI',
                    ['data_keys' => $data !== null ? array_keys($data) : []]
                );

                $this->beforeHttpRequest($method, $url);
                $response = $this->executeCurl($method, $url, $data, $headers);

                $this->logger->debug(
                    "API Yanıt: HTTP {$response['httpCode']}",
                    'MarketplaceAPI',
                    ['url' => $logUrl, 'http_code' => $response['httpCode']]
                );

                // Başarılı
                if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
                    return $response['body'];
                }

                // Rate limit — 429 veya gövdesinde limit mesajı taşıyan 400 (ör. ÇiçekSepeti
                // "Limit aşımı! ... Kalan Süre: N saniye"). Aynı isteği hemen tekrarlamak
                // cezayı büyütür; yanıttaki kalan süre kadar bekleyip öyle tekrar dene.
                $bodyJson = json_encode($response['body'], JSON_UNESCAPED_UNICODE) ?: '';
                $isRateLimited = $response['httpCode'] === 429
                    || ($response['httpCode'] === 400 && (
                        str_contains($bodyJson, 'Limit aşımı') || stripos($bodyJson, 'rate limit') !== false
                    ));

                if ($isRateLimited) {
                    $waitSec = $response['retryAfter'] ?? $this->parseRateLimitWaitSeconds($bodyJson);

                    if ($waitSec !== null && $waitSec > self::RATE_LIMIT_MAX_WAIT_SEC) {
                        // 10 dakikalık ceza gibi uzun süreler — beklemek anlamsız, net mesajla bırak
                        $lastException = new RuntimeException(
                            "API rate limit cezası: {$waitSec} sn sonra tekrar denenebilir. Yanıt: {$bodyJson}"
                        );
                        break;
                    }

                    if ($rateLimitWaits >= self::RATE_LIMIT_MAX_WAITS) {
                        $lastException = new RuntimeException(
                            "API rate limit: {$rateLimitWaits} bekleme sonrası hâlâ limitli. Yanıt: {$bodyJson}"
                        );
                        break;
                    }

                    $rateLimitWaits++;
                    // Kalan süre bilinmiyorsa üstel geri çekilme: 2s, 4s, 8s, 16s, 32s
                    // (attempt-- ile deneme sayacı sabit kaldığından çarpan
                    // rateLimitWaits üzerinden büyütülür)
                    $sleepMs = $waitSec !== null
                        ? ($waitSec + 2) * 1000
                        : $this->retryBaseDelayMs * (2 ** $rateLimitWaits);

                    $this->logger->warning(
                        "Rate limit aşıldı, " . (int) ceil($sleepMs / 1000) . " sn bekleniyor...",
                        'MarketplaceAPI',
                        ['url' => $logUrl, 'attempt' => $attempt, 'wait_ms' => $sleepMs]
                    );

                    // Süre sınırını yalnız YÜKSELT. Önceden koşulsuz set_time_limit()
                    // çağrılıyordu; çağıran set_time_limit(0) ile sınırı kaldırmış olsa
                    // bile burada sonlu bir değere geri çekiliyor ve uzun içe aktarımlar
                    // "Maximum execution time exceeded" ölümcül hatasıyla düşüyordu
                    // (yakalanamadığı için tarayıcıya HTML 500 dönüyordu).
                    if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') !== 0) {
                        set_time_limit((int) ceil($sleepMs / 1000) + 60);
                    }

                    $this->sleep((int) $sleepMs);
                    $attempt--; // rate-limit beklemesi normal deneme hakkını yakmasın
                    continue;
                }

                // Auth hatası — retry anlamsız, ANINDA bırak
                if ($response['httpCode'] === 401 || $response['httpCode'] === 403) {
                    $lastException = new RuntimeException(
                        "API yetkilendirme hatası ({$response['httpCode']}): " .
                        json_encode($response['body'], JSON_UNESCAPED_UNICODE)
                    );
                    break;
                }

                // Diğer 4xx iş hataları KALICIDIR — aynı isteği tekrarlamak sonucu
                // değiştirmez, üstelik bazı pazaryerlerinde (ör. Trendyol
                // "recurring create" koruması) tekrar denemek satıcıyı geçici
                // bloğa sokar. Anında raporla, retry etme.
                if ($response['httpCode'] >= 400 && $response['httpCode'] < 500) {
                    $lastException = new RuntimeException(
                        "API hatası ({$response['httpCode']}): {$bodyJson}"
                    );
                    break;
                }

                // Server hatası — retry
                if ($response['httpCode'] >= 500) {
                    $bodyStr = json_encode($response['body'] ?? [], JSON_UNESCAPED_UNICODE);
                    $this->logger->warning(
                        "API sunucu hatası ({$response['httpCode']}): {$bodyStr}",
                        'MarketplaceAPI',
                        ['url' => $logUrl, 'http_code' => $response['httpCode']]
                    );
                    $lastException = new RuntimeException(
                        "API sunucu hatası ({$response['httpCode']})"
                    );
                    $this->sleep($this->retryBaseDelayMs * $attempt);
                    continue;
                }

                // Diğer hatalar
                throw new RuntimeException(
                    "API hatası ({$response['httpCode']}): " .
                    json_encode($response['body'], JSON_UNESCAPED_UNICODE)
                );

            } catch (RuntimeException $e) {
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    $this->logger->warning(
                        "API hatası, yeniden deneniyor: {$e->getMessage()}",
                        'MarketplaceAPI',
                        ['attempt' => $attempt, 'url' => $logUrl]
                    );
                    $this->sleep($this->retryBaseDelayMs * $attempt);
                }
            }
        }

        $this->logger->error(
            'API isteği başarısız',
            'MarketplaceAPI',
            ['url' => $logUrl, 'error' => $lastException?->getMessage()]
        );

        throw $lastException ?? new RuntimeException("API isteği başarısız: {$logUrl}");
    }

    /**
     * cURL Multipart form-data çalıştır (dosya gönderme)
     *
     * @param array<string, string> $headers
     * @return array{httpCode: int, body: array<string, mixed>}
     */
    private function executeCurlMultipart(string $url, string $filePath, string $fieldName, array $headers): array
    {
        $ch = curl_init();

        // Content-Type header'ını kaldır — curl multipart boundary'yi kendisi set edecek
        $filteredHeaders = array_filter($headers, fn(string $h) => !str_starts_with(strtolower($h), 'content-type'));
        $filteredHeaders[] = 'Accept: application/json';

        $curlFile = new \CURLFile($filePath, 'application/json', basename($filePath));

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // flaky IPv6 DNS'i bypass et (Could not resolve host)
            CURLOPT_HTTPHEADER     => array_values($filteredHeaders),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [$fieldName => $curlFile],
        ]);
        curl_setopt_array($ch, $this->baseCurlOptions());

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL multipart hatası: {$error}");
        }

        $body = json_decode((string) $response, true);
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            $body = ['raw_response' => (string) $response];
        }

        return [
            'httpCode' => (int) $httpCode,
            'body'     => is_array($body) ? $body : ['raw_response' => $body],
        ];
    }

    /**
     * cURL çalıştır
     *
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array{httpCode: int, body: array<string, mixed>}
     */
    private function executeCurl(string $method, string $url, ?array $data, array $headers): array
    {
        $ch = curl_init();
        $retryAfter = null;

        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(.+)$/i', trim($line), $match)) {
                    $raw=trim($match[1]);
                    $retryAfter=ctype_digit($raw)?(int)$raw:max(0,(int)strtotime($raw)-time());
                }
                return strlen($line);
            },
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // flaky IPv6 DNS'i bypass et (Could not resolve host)
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_setopt_array($ch, $this->baseCurlOptions());

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL hatası: {$error}");
        }

        $body = json_decode((string) $response, true);
        if ($body === null && json_last_error() !== JSON_ERROR_NONE) {
            $body = ['raw_response' => (string) $response];
        }

        return [
            'retryAfter' => $retryAfter,
            'httpCode' => (int) $httpCode,
            'body'     => is_array($body) ? $body : ['raw_response' => $body],
        ];
    }

    /**
     * Rate limit — istek arasında minimum süre bırak
     *
     * rateLimitPerSecond saniyede N istek sınırı verir; minRequestIntervalMs ise
     * saniyede 1'den SEYREK limitler içindir (ör. ÇiçekSepeti: farklı isteklere
     * 5 sn'de 1). İkisinden büyük olan aralık uygulanır.
     */
    protected function beforeHttpRequest(string $method, string $url): void {}

    private function rateLimit(): void
    {
        $minInterval = $this->rateLimitPerSecond > 0 ? 1.0 / $this->rateLimitPerSecond : 0.0;

        if ($this->minRequestIntervalMs > 0) {
            $minInterval = max($minInterval, $this->minRequestIntervalMs / 1000);
        }

        if ($minInterval <= 0) {
            return;
        }

        $elapsed = microtime(true) - $this->lastRequestTime;

        if ($this->lastRequestTime > 0 && $elapsed < $minInterval) {
            $sleepUs = (int) (($minInterval - $elapsed) * 1_000_000);
            usleep($sleepUs);
        }

        $this->lastRequestTime = microtime(true);
    }

    /**
     * Rate-limit yanıt gövdesinden kalan ceza süresini (sn) ayrıştır
     * ÇiçekSepeti örneği: "Limit aşımı! ... Kalan Süre: 44 saniye"
     */
    private function parseRateLimitWaitSeconds(string $body): ?int
    {
        if (preg_match('/Kalan\s+S(?:ü|\\\\u00fc)re\s*:?\s*(\d+)/iu', $body, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/retry[-_ ]?after["\s:]+(\d+)/i', $body, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Milisaniye bekleme
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
