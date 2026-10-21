<?php
/**
 * OzyConnect Panel — HepsiburadaService (Hepsiburada API Entegrasyonu)
 *
 * SIT (Test) ortamı hazır. Canlıya geçişte config/marketplaces.php'deki
 * URL'lerden "-sit" kaldırılması yeterlidir.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    2.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Logger;
use App\Services\CryptoService;
use App\Models\Marketplace;
use RuntimeException;

final class HepsiburadaService extends AbstractMarketplace
{
    /** Katalog detay haritası disk önbelleği ömrü (saniye) */
    private const CATALOG_CACHE_TTL = 3600;


    private const SLUG = 'hepsiburada';

    private string $apiSecret = '';
    private string $merchantId = '';
    private string $integratorUsername = '';
    /** @var array<string, mixed> */
    private array $extraConfig = [];

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, array<string, mixed>> */
    private array $orderDetailCache = [];

    /** @var array<string, string> Modül bazlı base URL'ler */
    private array $baseUrls = [];

    /** @var string 'sit' veya 'production' */
    private string $environment = 'sit';

    /** Listeleme yüklemesi sonucu için azami sorgu denemesi */
    private const UPLOAD_POLL_ATTEMPTS = 5;

    /** Yükleme durumu sorguları arası bekleme (ms) */
    private const UPLOAD_POLL_DELAY_MS = 2000;

    /** Yükleme hâlâ işleniyor anlamına gelen durumlar (boşluk/tire temizlenmiş) */
    private const UPLOAD_PENDING_STATES = ['NEW', 'PROCESSING', 'INPROGRESS', 'WAITING', 'PENDING', 'QUEUED', 'STARTED', 'RUNNING'];

    /** Yükleme başarısız anlamına gelen durumlar */
    private const UPLOAD_FAILED_STATES = ['FAILED', 'FAIL', 'ERROR', 'REJECTED', 'CANCELLED'];

    public function __construct(
        Logger $logger,
        private readonly CryptoService $crypto,
        private readonly Marketplace $marketplaceModel,
    ) {
        parent::__construct($logger);

        $marketplacesConfig = require BASE_PATH . '/config/marketplaces.php';
        $this->config = $marketplacesConfig[self::SLUG] ?? [];

        // Ortam seçimi (sit = test, production = canlı)
        $this->environment = $this->config['environment'] ?? 'sit';

        // Ortama göre base URL'leri belirle
        $urlKey = $this->environment === 'production' ? 'base_urls_production' : 'base_urls_sit';
        $this->baseUrls = $this->config[$urlKey] ?? $this->config['base_urls'] ?? [];

        $this->baseUrl             = $this->baseUrls['catalog'] ?? $this->config['base_url'] ?? 'https://mpop-sit.hepsiburada.com';
        $this->timeout             = $this->config['api']['timeout_seconds'] ?? 60;
        $this->maxRetries          = $this->config['api']['max_retries'] ?? 3;
        $this->retryBaseDelayMs    = $this->config['api']['retry_base_delay_ms'] ?? 1000;
        $this->rateLimitPerSecond  = $this->config['api']['rate_limit_per_second'] ?? 5;
    }

    /**
     * Mevcut ortamı döndür ('sit' veya 'production')
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * API bilgilerini veritabanından yükle
     */
    public function loadCredentials(): void
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            throw new RuntimeException('Hepsiburada pazaryeri kaydı bulunamadı');
        }

        if (empty($marketplace['api_secret_encrypted'])) {
            throw new RuntimeException('Hepsiburada şifresi (Servis Anahtarı) henüz girilmemiş');
        }

        $this->apiSecret = $this->crypto->decrypt($marketplace['api_secret_encrypted']);

        $this->extraConfig = json_decode($marketplace['extra_config_json'] ?? '{}', true) ?: [];
        $this->merchantId = (string) ($this->extraConfig['merchant_id'] ?? '');
        $this->integratorUsername = (string) (
            $this->config['developer_username']
            ?? $this->extraConfig['integrator_username']
            ?? 'oziyweb_dev'
        );

        if ($this->merchantId === '') {
            throw new RuntimeException('Hepsiburada Merchant ID (Mağaza ID) girilmemiş');
        }

        if ($this->integratorUsername === '') {
            throw new RuntimeException('Hepsiburada Entegratör Kullanıcı Adı girilmemiş');
        }
    }

    /**
     * Auth header'ı oluştur (Basic Auth)
     *
     * HB dokümantasyonu: Username = MerchantId, Password = SecretKey
     * User-Agent = Developer Username (entegratör kullanıcı adı)
     *
     * @return array<int, string>
     */
    private function authHeaders(): array
    {
        $token = base64_encode($this->merchantId . ':' . $this->apiSecret);
        return [
            "Authorization: Basic {$token}",
            "User-Agent: {$this->integratorUsername}",
            "Accept: application/json",
            "Content-Type: application/json",
        ];
    }

    /**
     * Modüle göre base URL al
     */
    private function getBaseUrl(string $module = 'catalog'): string
    {
        return $this->baseUrls[$module] ?? $this->baseUrl;
    }

    /**
     * Endpoint URL'ini oluştur
     */
    private function buildUrl(string $endpoint, string $module = 'catalog'): string
    {
        $path = str_replace('{merchantId}', $this->merchantId, $endpoint);
        return $this->getBaseUrl($module) . $path;
    }

    // ═══════════════════════════════════════════════════════
    // BAĞLANTI TESTİ
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        try {
            $this->loadCredentials();

            // Doğrudan curl ile test — hata detaylarını yakalamak için
            $url = $this->getBaseUrl('listing')
                 . '/listings/merchantid/' . $this->merchantId . '?offset=0&limit=1';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // flaky IPv6 DNS'i bypass et (Could not resolve host)
                CURLOPT_HTTPHEADER     => $this->authHeaders(),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return [
                    'success' => false,
                    'message' => 'Bağlantı hatası: ' . $curlError,
                ];
            }

            // Başarılı
            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Hepsiburada bağlantısı başarılı! (Ortam: ' . strtoupper($this->environment) . ')',
                ];
            }

            // HB hata mesajını parse et
            $body = json_decode((string) $response, true);
            $hbMessage = $body['message'] ?? $body['error'] ?? (string) $response;

            // 401/403 = auth reddedildi
            if ($httpCode === 401 || $httpCode === 403) {
                return [
                    'success' => false,
                    'message' => 'Kimlik doğrulama başarısız (' . $httpCode . '). '
                        . 'HB yanıtı: ' . $hbMessage . ' — '
                        . 'Entegratör kullanıcı adı ve servis anahtarını kontrol edin. '
                        . 'Not: Yeni entegratör yetkilendirmesi 2 saate kadar sürebilir.',
                ];
            }

            // 5xx = sunucu hatası
            if ($httpCode >= 500) {
                return [
                    'success' => false,
                    'message' => 'Hepsiburada SIT sunucusu geçici olarak hata döndürdü (' . $httpCode . '). '
                        . 'Yanıt: ' . $hbMessage . ' — Lütfen birkaç dakika sonra tekrar deneyin.',
                ];
            }

            return [
                'success' => false,
                'message' => 'Beklenmeyen yanıt (' . $httpCode . '): ' . $hbMessage,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Bağlantı hatası: ' . $e->getMessage(),
            ];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Hepsiburada OMS'ten siparişleri çeker ve SİPARİŞ BAŞINA tek kayıt döner (kalemler items[] içinde).
     *
     * Kapsamlar (developers.hepsiburada.com — Sipariş Entegrasyonu):
     *  - orders    : Ödemesi tamamlanmış, paketlenecek kalemler (Open/Unpacked). ANLIK liste → tarihsiz,
     *                tamamı çekilir (havaleli/geç onaylanan siparişler de kaçmaz). limit ≤ 100. Bu kapsam
     *                hata verirse senkron BAŞARISIZ sayılır (eskiden sessizce 0 dönüp "başarılı" görünüyordu).
     *  - packages  : Paketlenmiş, kargoya verilmeyi bekleyen paketler. ANLIK liste → tarihsiz. limit ≤ 10.
     *  - shipped / delivered / undelivered / cancelled : durum güncellemesi. limit ≤ 50. Kısa pencerede
     *                (cron) begindate/enddate ("yyyy-MM-dd HH:mm", en fazla 24 saat) ile, uzun pencerede
     *                tarihsiz listelenir; olay tarihi pencere dışındaysa detay çağrısı YAPILMAZ.
     *  Bozulan paket listesi (status/unpacked) sipariş numarası taşımaz (yalnız paket no) ve o kalemler
     *  zaten 'orders' listesine Unpacked statüsüyle döner → sipariş kaynağı olarak kullanılmaz.
     *
     * $filters: begin_date / end_date (Y-m-d [H:i:s]), timespan (gün), limit (sayfa boyu üst sınırı),
     *           known_order_ids (yerelde zaten olan sipariş no'ları → durum kapsamlarında detay çağrısı atlanır).
     */
    public function fetchOrders(array $filters = []): array
    {
        $this->loadCredentials();

        $endTs = !empty($filters['end_date']) ? strtotime((string) $filters['end_date']) : time();
        if ($endTs === false) {
            $endTs = time();
        }

        $days = max(1, (int) ($filters['timespan'] ?? 30));
        $startTs = !empty($filters['begin_date'])
            ? strtotime((string) $filters['begin_date'])
            : strtotime("-{$days} days", $endTs);
        if ($startTs === false) {
            $startTs = strtotime("-30 days", $endTs);
        }
        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        $knownOrderIds = [];
        foreach ((array) ($filters['known_order_ids'] ?? []) as $knownId) {
            $knownOrderIds[(string) $knownId] = true;
        }

        $pageLimit = (int) ($filters['limit'] ?? 0);
        $rows = [];

        foreach ($this->hbOrderScopes() as $scope) {
            $endpoint = $this->config['endpoints'][$scope['key']] ?? $scope['endpoint'];
            $pageSize = $pageLimit > 0 ? min($pageLimit, $scope['limit']) : $scope['limit'];

            try {
                $summaries = $this->fetchHbScopeSummaries($scope, $endpoint, $pageSize, $startTs, $endTs);
            } catch (\Throwable $e) {
                if ($scope['critical']) {
                    throw new RuntimeException('Hepsiburada sipariş listesi alınamadı: ' . $e->getMessage(), 0, $e);
                }
                $this->logger->warning(
                    "HB siparis kapsami atlandi: {$scope['key']} - {$e->getMessage()}",
                    'HepsiburadaService',
                    ['endpoint' => $endpoint]
                );
                continue;
            }

            foreach ($summaries as $summary) {
                if (!is_array($summary)) {
                    continue;
                }

                foreach ($this->splitHbSummaryByOrder($summary) as $raw) {
                    // Durum kapsamlarında olay tarihi pencere dışındaysa detay çağrısı yapmadan ele
                    if ($scope['window'] && !$this->hbSummaryEventInRange($raw, $startTs, $endTs)) {
                        continue;
                    }

                    $orderNumber = $this->extractHbOrderNumber($raw);
                    if ($orderNumber === '') {
                        continue; // yalnız paket no taşıyan kayıt sipariş oluşturmaz
                    }

                    // Yerelde zaten olan siparişin durum güncellemesi için detay gerekmez (API yükü)
                    if (!($scope['window'] && isset($knownOrderIds[$orderNumber]))) {
                        $enriched = $this->enrichHbOrderSummary($raw);
                        // Detayı alınamayan YENİ sipariş boş (müşterisiz/tutarsız) kayıt olarak yazılmaz; sonraki koşumda denenir
                        if ($scope['window'] && $enriched === $raw && $this->needsHbOrderDetail($raw)) {
                            continue;
                        }
                        $raw = $enriched;
                    }

                    foreach ($this->expandHbOrderRows($raw, $scope['status']) as $row) {
                        if (($row['marketplace_order_id'] ?? '') === '') {
                            continue;
                        }
                        if ($scope['window'] && !$this->hbOrderInDateRange($row, $startTs, $endTs)) {
                            continue;
                        }
                        $rows[] = $row;
                    }
                }
            }
        }

        return $this->groupHbOrderLines($this->deduplicateHbOrders($rows));
    }

    /** Kritik sipariş kapsamının (açık siparişler) sayfalama süre bütçesi (sn). */
    private const CRITICAL_LIST_MAX_SECONDS = 300.0;

    /**
     * Sipariş kapsamları: HB statüsü, sayfa boyu üst sınırı (dokümandaki azami limit), tarih davranışı.
     *
     * @return array<int, array{key: string, endpoint: string, status: string, limit: int, window: bool, critical: bool, offset_keys: array<int, string>}>
     */
    private function hbOrderScopes(): array
    {
        return [
            ['key' => 'orders', 'endpoint' => '/orders/merchantid/{merchantId}', 'status' => 'Open', 'limit' => 100, 'window' => false, 'critical' => true, 'offset_keys' => ['offset']],
            // Dokümanda paket listesinin sayfa parametresi "Offset" (büyük harf) — ikisi birden gönderilir
            ['key' => 'packages', 'endpoint' => '/packages/merchantid/{merchantId}', 'status' => 'Packaged', 'limit' => 10, 'window' => false, 'critical' => false, 'offset_keys' => ['offset', 'Offset']],
            ['key' => 'packages_shipped', 'endpoint' => '/packages/merchantid/{merchantId}/shipped', 'status' => 'Shipped', 'limit' => 50, 'window' => true, 'critical' => false, 'offset_keys' => ['offset']],
            ['key' => 'packages_delivered', 'endpoint' => '/packages/merchantid/{merchantId}/delivered', 'status' => 'Delivered', 'limit' => 50, 'window' => true, 'critical' => false, 'offset_keys' => ['offset']],
            ['key' => 'packages_undelivered', 'endpoint' => '/packages/merchantid/{merchantId}/undelivered', 'status' => 'UnDelivered', 'limit' => 50, 'window' => true, 'critical' => false, 'offset_keys' => ['offset']],
            ['key' => 'orders_cancelled', 'endpoint' => '/orders/merchantid/{merchantId}/cancelled', 'status' => 'Cancelled', 'limit' => 50, 'window' => true, 'critical' => false, 'offset_keys' => ['offset']],
        ];
    }

    /**
     * Bir kapsamın ham özet kayıtlarını çeker. Durum kapsamlarında pencere 20 saatten kısaysa
     * (cron) son ~23,5 saat begindate/enddate ile istenir; HB 24 saati aşan aralıkta enddate'i yok sayar.
     * Tarihli istek reddedilirse tarihsiz listeye düşülür.
     *
     * @param array<string, mixed> $scope
     * @return array<int, mixed>
     */
    private function fetchHbScopeSummaries(array $scope, string $endpoint, int $pageSize, int $startTs, int $endTs): array
    {
        $maxPages = $scope['window'] ? 20 : 50;

        if ($scope['window'] && ($endTs - $startTs) <= 20 * 3600) {
            $now = time();
            $dated = [
                'begindate' => date('Y-m-d H:i', $now - (23 * 3600)),
                'enddate'   => date('Y-m-d H:i', $now + 1800),
            ];
            try {
                return $this->fetchHbListPages($endpoint, $pageSize, $scope['offset_keys'], $dated, $maxPages, !empty($scope['critical']));
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "HB tarihli liste reddedildi, tarihsiz deneniyor: {$scope['key']} - {$e->getMessage()}",
                    'HepsiburadaService'
                );
            }
        }

        return $this->fetchHbListPages($endpoint, $pageSize, $scope['offset_keys'], [], $maxPages, !empty($scope['critical']));
    }

    /**
     * Sayfalı liste çekimi. offset dönen kayıt sayısı kadar ilerler (API limiti düşürse bile kayıt atlanmaz);
     * totalCount varsa ona, yoksa "sayfa dolu mu" bilgisine göre durur. API offset'i yok sayıp aynı sayfayı
     * dönerse döngü kırılır (sonsuz döngü / mükerrer kayıt koruması).
     *
     * @param array<int, string> $offsetKeys
     * @param array<string, string> $query
     * @return array<int, mixed>
     */
    private function fetchHbListPages(string $endpoint, int $pageSize, array $offsetKeys, array $query, int $maxPages, bool $critical = false): array
    {
        $all = [];
        $offset = 0;
        $previousPage = '';
        // Kritik kapsam (açık siparişler) genel 45 sn bütçesiyle kesilirse listenin sonundaki siparişler
        // her koşuda yeniden kaçar → kritik kapsamda süre bütçesi geniş tutulur (sayfa sınırı aynı).
        $this->startPaging('hb.orders', $maxPages, $critical ? self::CRITICAL_LIST_MAX_SECONDS : null);

        for ($page = 0; $page < $maxPages; $page++) {
            $params = $query;
            foreach ($offsetKeys as $offsetKey) {
                $params[$offsetKey] = $offset;
            }
            $params['limit'] = $pageSize;

            $url = $this->buildUrl($endpoint, 'order') . '?' . http_build_query($params);
            $response = $this->httpGet($url, $this->authHeaders());
            $items = $this->extractHbItems($response);

            if ($items === []) {
                break;
            }

            $signature = md5((string) json_encode($items));
            if ($signature === $previousPage) {
                $this->logger->warning(
                    'HB sayfalama ilerlemedi (ayni sayfa tekrar dondu), liste kesildi',
                    'HepsiburadaService',
                    ['endpoint' => $endpoint, 'offset' => $offset]
                );
                break;
            }
            $previousPage = $signature;

            array_push($all, ...$items);
            $offset += count($items);

            $totalCount = (int) ($response['totalCount'] ?? $response['totalElements'] ?? $response['total'] ?? 0);
            $hasMore = $totalCount > 0 ? $offset < $totalCount : count($items) >= $pageSize;
            if (!$hasMore) {
                break;
            }

            // Süre/sayfa bütçesi: yavaş API ya da şişkin liste süreci (paylaşımlı hosting'de hesabı) kilitlemesin
            if (!$this->keepPaging('hb.orders', $page + 1, $items)) {
                break;
            }
        }

        if ($this->pagingStopped('hb.orders')) {
            $message = sprintf('HB sipariş listesi kısmi çekildi (bütçe doldu): %s — %d kayıt', $endpoint, count($all));
            if ($critical) {
                $this->logger->warning($message . ' — açık sipariş listesi eksik; kalan siparişler sonraki koşuda denenecek', 'HepsiburadaService');
            } else {
                $this->logger->info($message, 'HepsiburadaService');
            }
        }

        return $all;
    }

    /**
     * Birden çok siparişi taşıyan paket özetini (OrderNumbers) sipariş başına ayırır.
     *
     * @param array<string, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private function splitHbSummaryByOrder(array $raw): array
    {
        $numbers = $raw['orderNumbers'] ?? $raw['OrderNumbers'] ?? null;
        if (!is_array($numbers)) {
            return [$raw];
        }

        $numbers = array_values(array_unique(array_filter(array_map('strval', $numbers), static fn(string $n): bool => $n !== '')));
        if (count($numbers) <= 1) {
            return [$raw];
        }

        $out = [];
        foreach ($numbers as $number) {
            $copy = $raw;
            unset($copy['orderNumbers'], $copy['OrderNumbers'], $copy['orderNumber']);
            $copy['OrderNumber'] = $number;
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * Durum özetinin kendi olay tarihi (kargo/teslim/iptal) pencere içinde mi? Tarih alanı yoksa true
     * (karar detaydan sonra hbOrderInDateRange ile verilir).
     *
     * @param array<string, mixed> $raw
     */
    private function hbSummaryEventInRange(array $raw, int $startTs, int $endTs): bool
    {
        $rangeStart = strtotime(date('Y-m-d 00:00:00', $startTs));
        $rangeEnd = strtotime(date('Y-m-d 23:59:59', $endTs));
        $hasDate = false;

        foreach (['ShippedDate', 'shippedDate', 'DeliveredDate', 'deliveredDate', 'UndeliveredDate', 'undeliveredDate', 'CancelDate', 'cancelDate'] as $key) {
            if (empty($raw[$key])) {
                continue;
            }
            $eventTs = strtotime((string) $raw[$key]);
            if ($eventTs === false) {
                continue;
            }
            $hasDate = true;
            if ($eventTs >= $rangeStart && $eventTs <= $rangeEnd) {
                return true;
            }
        }

        return !$hasDate;
    }

    /**
     * Aynı siparişin kalemlerini TEK sipariş kaydında birleştirir. OrderSyncService siparişleri sipariş no ile
     * tekilleştirdiğinden gruplanmadan gelen çok kalemli siparişlerde yalnız bir kalem kalıyordu.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupHbOrderLines(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['marketplace_order_id'] ?? '');
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $row;
        }

        $orders = [];
        foreach ($groups as $group) {
            $orders[] = count($group) === 1 ? $group[0] : $this->mergeHbOrderRows($group);
        }

        return $orders;
    }

    /**
     * @param array<int, array<string, mixed>> $group
     * @return array<string, mixed>
     */
    private function mergeHbOrderRows(array $group): array
    {
        $active = array_values(array_filter($group, static fn(array $r): bool => ($r['status'] ?? '') !== 'iptal'));

        // Durum: aktif kalemlerin en geride olanı (işlem bekleyen kalem görünür kalsın); hepsi iptalse iptal
        if ($active === []) {
            $status = 'iptal';
        } else {
            $status = (string) ($active[0]['status'] ?? '');
            foreach ($active as $row) {
                if ($this->hbStatusPriority((string) ($row['status'] ?? '')) < $this->hbStatusPriority($status)) {
                    $status = (string) $row['status'];
                }
            }
        }

        $order = $group[0];
        $order['status'] = $status;

        foreach (['customer_name', 'customer_email', 'customer_phone', 'customer_id_no', 'customer_tax_office', 'cargo_company', 'cargo_tracking_no', 'cargo_sent_at'] as $field) {
            foreach ($group as $row) {
                if (($row[$field] ?? '') !== '' && $row[$field] !== null) {
                    $order[$field] = $row[$field];
                    break;
                }
            }
        }

        foreach (['shipping_address', 'billing_address'] as $field) {
            foreach ($group as $row) {
                $address = $row[$field] ?? [];
                if (is_array($address) && (trim((string) ($address['address'] ?? '')) !== '' || trim((string) ($address['city'] ?? '')) !== '')) {
                    $order[$field] = $address;
                    break;
                }
            }
        }

        $orderedAt = (string) ($order['ordered_at'] ?? '');
        foreach ($group as $row) {
            $candidate = (string) ($row['ordered_at'] ?? '');
            if ($candidate !== '' && ($orderedAt === '' || strcmp($candidate, $orderedAt) < 0)) {
                $orderedAt = $candidate;
            }
        }
        $order['ordered_at'] = $orderedAt;

        // Tutarlar: iptal olmayan kalemlerin toplamı (hepsi iptalse tümü)
        $totalRows = $active !== [] ? $active : $group;
        foreach (['subtotal', 'tax_total', 'discount_total', 'total_amount'] as $field) {
            $sum = 0.0;
            foreach ($totalRows as $row) {
                $sum += (float) ($row[$field] ?? 0);
            }
            $order[$field] = round($sum, 2);
        }

        $items = [];
        $blankItems = [];
        foreach ($group as $row) {
            foreach ((array) ($row['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $isBlank = trim((string) ($item['name'] ?? '')) === ''
                    && trim((string) ($item['sku'] ?? '')) === ''
                    && (float) ($item['line_total'] ?? 0) <= 0;
                if ($isBlank) {
                    $blankItems[] = $item;
                } else {
                    $items[] = $item;
                }
            }
        }
        $order['items'] = $items !== [] ? $items : array_slice($blankItems, 0, 1);

        $raw = is_array($order['raw_data'] ?? null) ? $order['raw_data'] : [];
        $raw['hbLineItems'] = array_map(static fn(array $r) => $r['raw_data'] ?? [], $group);
        $order['raw_data'] = $raw;

        return $order;
    }

    /**
     * HB'nin kargoda/teslim/iptal paket listeleri sadece paket ozeti dondurebiliyor.
     * Siparis detayi musteri, adres, urun ve fiyat alanlarini tasir.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function enrichHbOrderSummary(array $raw): array
    {
        if (!$this->needsHbOrderDetail($raw)) {
            return $raw;
        }

        $orderNumber = $this->extractHbOrderNumber($raw);
        if ($orderNumber === '') {
            return $raw;
        }

        try {
            $detail = $this->fetchOrderDetailCached($orderNumber);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "HB siparis detayi alinamadi: {$orderNumber} - {$e->getMessage()}",
                'HepsiburadaService'
            );
            return $raw;
        }

        if ($detail === []) {
            return $raw;
        }

        $merged = array_replace($detail, $raw);

        if (!empty($detail['items']) && is_array($detail['items'])) {
            $detailLines = $detail['items'];

            // Kalem bazlı özet (ör. iptal edilen kalem): siparişin YALNIZ o kalemi işaretlenir, diğerleri değil
            $lineId = (string) ($raw['lineItemId'] ?? $raw['LineItemId'] ?? '');
            if ($lineId !== '') {
                $matching = array_values(array_filter(
                    $detailLines,
                    static fn($line): bool => is_array($line) && (string) ($line['id'] ?? $line['lineItemId'] ?? '') === $lineId
                ));
                if ($matching !== []) {
                    $detailLines = $matching;
                }
            }

            $merged['items'] = [];
            foreach ($detailLines as $line) {
                $merged['items'][] = is_array($line) ? array_replace($raw, $line) : $line;
            }
        }

        if (!empty($detail['deliveryAddress']) && empty($merged['shippingAddress'])) {
            $merged['shippingAddress'] = $detail['deliveryAddress'];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function needsHbOrderDetail(array $raw): bool
    {
        // Ödemesi tamamlanmış sipariş listesi kalem bazlı ve düzdür (adet + SKU + ürün adı kaydın kendisinde);
        // bu kayıt kendi başına kalemdir, her açık sipariş için ayrıca detay çağrısı gerekmez.
        $hasItems = isset($raw['quantity'])
            && (!empty($raw['name']) || !empty($raw['productName']))
            && (!empty($raw['sku']) || !empty($raw['merchantSKU']) || !empty($raw['merchantSku']));
        foreach (['lineItems', 'items', 'orderItems', 'packageItems', 'packageLines', 'lines'] as $key) {
            if ($hasItems) {
                break;
            }
            if (!empty($raw[$key]) && is_array($raw[$key])) {
                $hasItems = true;
            }
        }

        $hasCustomer = !empty($raw['customer'])
            || !empty($raw['customerName'])
            || !empty($raw['customerFullName'])
            || !empty($raw['recipientName']);

        $hasAmount = $this->extractAmount(
            $raw['totalPrice'] ?? $raw['TotalPrice'] ?? $raw['lineTotal'] ?? $raw['LineTotal'] ?? $raw['price'] ?? $raw['Price'] ?? 0
        ) > 0;

        return !$hasItems || !$hasCustomer || !$hasAmount;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractHbOrderNumber(array $raw): string
    {
        $orderNumber = (string) ($raw['orderNumber'] ?? $raw['OrderNumber'] ?? $raw['orderId'] ?? $raw['OrderId'] ?? '');
        if ($orderNumber !== '') {
            return $orderNumber;
        }

        $orderNumbers = $raw['orderNumbers'] ?? $raw['OrderNumbers'] ?? [];
        if (is_array($orderNumbers) && !empty($orderNumbers[0])) {
            return (string) $orderNumbers[0];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrderDetailCached(string $orderNumber): array
    {
        if (!array_key_exists($orderNumber, $this->orderDetailCache)) {
            $this->orderDetailCache[$orderNumber] = $this->fetchOrderDetail($orderNumber);
        }

        return $this->orderDetailCache[$orderNumber];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    private function extractHbItems(array $response): array
    {
        foreach (['items', 'content', 'data', 'orders', 'packages'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        return $this->isListArray($response) ? $response : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private function expandHbOrderRows(array $raw, ?string $status): array
    {
        foreach (['lineItems', 'items', 'orderItems', 'packageItems', 'packageLines', 'lines'] as $key) {
            if (empty($raw[$key]) || !is_array($raw[$key])) {
                continue;
            }

            $rows = [];
            foreach ($raw[$key] as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $package = $raw;
                unset($package[$key]);
                $rows[] = $this->normalizeOrder(array_replace($package, $line), $status);
            }

            return $rows;
        }

        return [$this->normalizeOrder($raw, $status)];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateHbOrders(array $orders): array
    {
        $deduped = [];

        foreach ($orders as $order) {
            $orderId = (string) ($order['marketplace_order_id'] ?? '');
            $firstItem = $order['items'][0] ?? [];
            $lineId = is_array($firstItem)
                ? (string) ($firstItem['marketplace_item_id'] ?? $firstItem['sku'] ?? $firstItem['barcode'] ?? '')
                : '';
            $key = $orderId . '|' . $lineId;

            if (!isset($deduped[$key])) {
                $deduped[$key] = $order;
                continue;
            }

            if ($this->hbStatusPriority((string) ($order['status'] ?? '')) >= $this->hbStatusPriority((string) ($deduped[$key]['status'] ?? ''))) {
                $deduped[$key] = $order;
            }
        }

        return array_values($deduped);
    }

    private function hbStatusPriority(string $status): int
    {
        return [
            'yeni' => 10,
            'hazirlaniyor' => 20,
            'bolundu' => 30,
            'kargoda' => 40,
            'teslim_edilemedi' => 50,
            'iptal' => 60,
            'teslim_edildi' => 70,
        ][$status] ?? 0;
    }

    private function hbOrderInDateRange(array $order, ?int $startTs, ?int $endTs): bool
    {
        if ($startTs === null || $endTs === null) {
            return true;
        }

        $raw = is_array($order['raw_data'] ?? null) ? $order['raw_data'] : [];
        $rangeStart = strtotime(date('Y-m-d 00:00:00', $startTs));
        $rangeEnd = strtotime(date('Y-m-d 23:59:59', $endTs));

        foreach ([
            'ShippedDate', 'shippedDate',
            'DeliveredDate', 'deliveredDate',
            'UndeliveredDate', 'undeliveredDate',
            'CancelDate', 'cancelDate',
            'lastStatusUpdateDate', 'LastStatusUpdateDate',
            'orderDate', 'OrderDate',
            'createdDate', 'CreatedDate',
        ] as $key) {
            if (empty($raw[$key])) {
                continue;
            }

            $eventTs = strtotime((string) $raw[$key]);
            if ($eventTs !== false && $eventTs >= $rangeStart && $eventTs <= $rangeEnd) {
                return true;
            }
        }

        $orderedAt = (string) ($order['ordered_at'] ?? '');
        $orderTs = $orderedAt !== '' ? strtotime($orderedAt) : false;
        if ($orderTs === false) {
            return true;
        }

        return $orderTs >= $rangeStart && $orderTs <= $rangeEnd;
    }

    /**
     * @param array<mixed> $value
     */
    private function isListArray(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    /**
     * HB kalem/paket kaydını ortak sipariş biçimine çevirir (tek kalemli; groupHbOrderLines birleştirir).
     * Şema: LineRepresentation (orders listesi / sipariş detayı), ExternalRawPackageRepresentation + PackageLine
     * (paket listesi), Shipped/Delivered/Undelivered özetleri, CancelledOrderLineRepresentation.
     * Adres: HB "town" = ilçe, "district" = mahalle/semt.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeOrder(array $raw, ?string $statusOverride = null): array
    {
        $hbStatus = $statusOverride ?? (string) ($raw['status'] ?? $raw['Status'] ?? $raw['orderStatus'] ?? $raw['OrderStatus'] ?? $raw['packageStatus'] ?? $raw['PackageStatus'] ?? 'Open');
        $status = $this->mapHbStatus($hbStatus);

        // Müşteri bilgileri
        $customerData = is_array($raw['customer'] ?? null) ? $raw['customer'] : [];
        $shippingAddr = $raw['shippingAddress'] ?? $raw['deliveryAddress'] ?? $raw['shippingAddressDetail'] ?? '';
        $shipArr      = is_array($shippingAddr) ? $shippingAddr : [];
        $invoiceData  = $raw['invoice'] ?? $raw['invoiceAddress'] ?? $raw['invoiceDetail'] ?? [];
        $invoiceData  = is_array($invoiceData) ? $invoiceData : [];
        $invoiceAddr  = is_array($invoiceData['address'] ?? null) ? $invoiceData['address'] : [];

        $customerName = $this->firstHbValue([
            $raw['customerName'] ?? null, $raw['customerFullName'] ?? null, $customerData['name'] ?? null,
            $raw['recipientName'] ?? null, $shipArr['name'] ?? null, $invoiceAddr['name'] ?? null,
        ]);
        $phone = $this->firstHbValue([
            $raw['phoneNumber'] ?? null, $raw['customerPhone'] ?? null, $raw['recipientPhone'] ?? null, $customerData['phone'] ?? null,
            $shipArr['phoneNumber'] ?? null, $shipArr['alternatePhoneNumber'] ?? null, $invoiceAddr['phoneNumber'] ?? null,
        ]);
        $email = $this->firstHbValue([
            $raw['email'] ?? null, $raw['customerEmail'] ?? null, $customerData['email'] ?? null,
            $shipArr['email'] ?? null, $invoiceAddr['email'] ?? null,
        ]);

        // TC / Vergi bilgileri (kalem: invoice.*, paket: identityNo/taxNumber/taxOffice)
        $taxOffice = $this->firstHbValue([$invoiceData['taxOffice'] ?? null, $raw['taxOffice'] ?? null]);
        $taxNumber = $this->firstHbValue([$invoiceData['taxNumber'] ?? null, $raw['taxNumber'] ?? null]);
        $idNo = $this->firstHbValue([$invoiceData['turkishIdentityNumber'] ?? null, $raw['identityNo'] ?? null]);
        if (in_array($idNo, ['11111111111', '00000000000', ''], true)) {
            $idNo = $taxNumber;
        }

        // Fiyat — HB Money {amount, currency} veya düz sayı
        $unitPrice  = $this->extractAmount($raw['unitPrice'] ?? $raw['UnitPrice'] ?? $raw['price'] ?? $raw['Price'] ?? $raw['merchantUnitPrice'] ?? $raw['itemPrice'] ?? $raw['ItemPrice'] ?? 0);
        $totalPrice = $this->extractAmount($raw['totalPrice'] ?? $raw['TotalPrice'] ?? $raw['merchantTotalPrice'] ?? $raw['lineTotal'] ?? $raw['LineTotal'] ?? 0);
        $vatRate    = (float) ($raw['vatRate'] ?? $raw['VatRate'] ?? 0);
        $qty        = max(1, (int) ($raw['quantity'] ?? $raw['Quantity'] ?? $raw['qty'] ?? $raw['Qty'] ?? 1));
        if ($totalPrice <= 0 && $unitPrice > 0) {
            $totalPrice = $unitPrice * $qty;
        }

        // İndirim: kalem (merchantDiscount/hbDiscount.totalPrice) veya paket kalemi (totalMerchantDiscount/totalHBDiscount)
        $discount = $this->extractAmount($raw['totalMerchantDiscount'] ?? $raw['totalmerchantDiscount'] ?? ($raw['merchantDiscount']['totalPrice'] ?? 0))
                  + $this->extractAmount($raw['totalHBDiscount'] ?? ($raw['hbDiscount']['totalPrice'] ?? 0));

        $taxAmount = ($totalPrice > 0 && $vatRate > 0)
            ? round($totalPrice * $vatRate / (100 + $vatRate), 2)
            : 0;

        $items = [
            [
                // Paket kaydında "id" PAKETİN id'sidir; kalem id'si lineItemId → önce o
                'marketplace_item_id' => (string) ($raw['lineItemId'] ?? $raw['LineItemId'] ?? $raw['id'] ?? $raw['Id'] ?? $raw['orderLineId'] ?? $raw['OrderLineId'] ?? $raw['orderItemId'] ?? $raw['OrderItemId'] ?? ''),
                'sku'                 => (string) ($raw['merchantSku'] ?? $raw['MerchantSku'] ?? $raw['merchantSKU'] ?? $raw['MerchantSKU'] ?? $raw['stockCode'] ?? $raw['StockCode'] ?? $raw['merchant_sku'] ?? $raw['sku'] ?? $raw['Sku'] ?? ''),
                // HB şemalarında "barcode" paketin TESLİMAT numarasıdır; ürün barkodu productBarcode'dur
                'barcode'             => $this->firstHbValue([$raw['productBarcode'] ?? null, $raw['ProductBarcode'] ?? null, $raw['hbSku'] ?? null, $raw['HbSku'] ?? null, $raw['sku'] ?? null, $raw['Sku'] ?? null]),
                'name'                => (string) ($raw['productName'] ?? $raw['ProductName'] ?? $raw['name'] ?? $raw['Name'] ?? $raw['product_name'] ?? ''),
                'qty'                 => $qty,
                'unit_price'          => $unitPrice,
                'discount'            => $discount,
                'tax_rate'            => $vatRate,
                'line_total'          => $totalPrice,
            ],
        ];

        // Teslimat adresi (kalem: shippingAddress{town=ilçe, district=mahalle}; paket: shippingTown/shippingDistrict)
        $shipTown = $this->firstHbValue([$raw['shippingTown'] ?? null, $shipArr['town'] ?? null]);
        $shipDistrict = $this->firstHbValue([$raw['shippingDistrict'] ?? null, $shipArr['district'] ?? null, $shipArr['neighborhood'] ?? null]);
        $shippingAddress = [
            'name'         => $this->firstHbValue([$raw['recipientName'] ?? null, $shipArr['name'] ?? null, $customerName]),
            'address'      => is_string($shippingAddr) ? $shippingAddr : (string) ($shipArr['address'] ?? ''),
            'city'         => $this->firstHbValue([$raw['shippingCity'] ?? null, $shipArr['city'] ?? null, $shipArr['cityName'] ?? null]),
            'district'     => $shipTown !== '' ? $shipTown : $shipDistrict,
            'postal_code'  => $this->firstHbValue([$raw['shippingPostalCode'] ?? null, $shipArr['postalCode'] ?? null]),
            'phone'        => $phone,
            'neighborhood' => $shipTown !== '' ? $shipDistrict : '',
        ];

        // Fatura adresi
        $billTown = $this->firstHbValue([$raw['billingTown'] ?? null, $invoiceAddr['town'] ?? null]);
        $billDistrict = $this->firstHbValue([$raw['billingDistrict'] ?? null, $invoiceAddr['district'] ?? null]);
        $billingAddress = [
            'name'         => $this->firstHbValue([$raw['companyName'] ?? null, $invoiceAddr['name'] ?? null, $customerName]),
            'address'      => $this->firstHbValue([is_string($raw['billingAddress'] ?? null) ? $raw['billingAddress'] : null, $invoiceAddr['address'] ?? null]),
            'city'         => $this->firstHbValue([$raw['billingCity'] ?? null, $invoiceAddr['city'] ?? null]),
            'district'     => $billTown !== '' ? $billTown : $billDistrict,
            'postal_code'  => $this->firstHbValue([$raw['billingPostalCode'] ?? null, $invoiceAddr['postalCode'] ?? null]),
            'neighborhood' => $billTown !== '' ? $billDistrict : '',
            'company'      => (string) ($raw['companyName'] ?? ''),
            'tax_office'   => $taxOffice,
            'tax_number'   => $taxNumber,
        ];

        // Kargo bilgisi
        $cargoCompany = (string) ($raw['cargoCompany'] ?? $raw['cargoCompanyName'] ?? '');
        if ($cargoCompany === '' && is_array($raw['cargoCompanyModel'] ?? null)) {
            $cargoCompany = (string) ($raw['cargoCompanyModel']['name'] ?? '');
        }
        $cargoTrackingNo = (string) ($raw['trackingInfoCode'] ?? $raw['trackingNumber'] ?? $raw['cargoTrackingNumber'] ?? '');
        $shippedDate = $raw['shippedDate'] ?? $raw['ShippedDate'] ?? '';

        // Müşteri notu (detay: orderNote, kalem: deliveryNote)
        $noteSource = $raw['orderNote'] ?? $raw['deliveryNote'] ?? null;
        $notes = is_array($noteSource) ? (string) ($noteSource['value'] ?? '') : (is_string($noteSource) ? $noteSource : '');

        // Sipariş tarihi
        $orderedAt = $this->parseHbDate($raw['orderDate'] ?? $raw['OrderDate'] ?? $raw['createdDate'] ?? $raw['CreatedDate'] ?? $raw['packageDate'] ?? $raw['PackageDate'] ?? $raw['lastModifiedDate'] ?? $raw['LastModifiedDate'] ?? $raw['shippedDate'] ?? $raw['ShippedDate'] ?? $raw['deliveredDate'] ?? $raw['DeliveredDate'] ?? $raw['undeliveredDate'] ?? $raw['UndeliveredDate'] ?? $raw['cancelDate'] ?? $raw['CancelDate'] ?? '');

        return [
            'marketplace_order_id' => $this->extractHbOrderNumber($raw),
            'status'               => $status,
            'customer_name'        => $customerName,
            'customer_email'       => $email,
            'customer_phone'       => $phone,
            'customer_id_no'       => $idNo,
            'customer_tax_office'  => $taxOffice,
            'notes'                => $notes,
            'billing_address'      => $billingAddress,
            'shipping_address'     => $shippingAddress,
            'subtotal'             => round($totalPrice - $taxAmount, 2),
            'tax_total'            => round($taxAmount, 2),
            'discount_total'       => round($discount, 2),
            'total_amount'         => round($totalPrice, 2),
            'cargo_company'        => $cargoCompany,
            'cargo_tracking_no'    => $cargoTrackingNo,
            'cargo_sent_at'        => !empty($shippedDate) ? $this->parseHbDate($shippedDate) : null,
            'ordered_at'           => $orderedAt,
            'items'                => $items,
            'raw_data'             => $raw,
        ];
    }

    /**
     * HB statüsünü iç duruma çevirir (config status_mapping; büyük/küçük harf duyarsız — HB "Unpacked" döner).
     */
    private function mapHbStatus(string $hbStatus): string
    {
        $statusMapping = $this->config['status_mapping'] ?? [];
        if (isset($statusMapping[$hbStatus])) {
            return (string) $statusMapping[$hbStatus];
        }
        foreach ($statusMapping as $key => $value) {
            if (strcasecmp((string) $key, $hbStatus) === 0) {
                return (string) $value;
            }
        }

        return $hbStatus;
    }

    /**
     * İlk dolu (boş/'-' olmayan) değeri metin olarak döner.
     *
     * @param array<int, mixed> $values
     */
    private function firstHbValue(array $values): string
    {
        foreach ($values as $value) {
            if (is_array($value) || $value === null) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $this->loadCredentials();

        $endpointKey = match ($status) {
            'intransit', 'kargoda'         => 'package_intransit',
            'deliver', 'teslim_edildi'     => 'package_deliver',
            'undeliver', 'teslim_edilemedi' => 'package_undeliver',
            'unpack', 'bolundu'            => 'package_unpack',
            default => throw new RuntimeException("Desteklenmeyen sipariş durumu: {$status}"),
        };

        $endpoint = $this->config['endpoints'][$endpointKey] ?? '';
        $url = str_replace('{packageNumber}', $orderId, $this->buildUrl($endpoint, 'order'));

        $this->httpPost($url, $extra, $this->authHeaders());
        return true;
    }

    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool
    {
        return $this->updateOrderStatus($orderId, 'intransit', [
            'trackingNumber' => $trackingNo,
            'cargoCompany'   => $cargoCompany,
        ]);
    }

    /**
     * Paket oluştur (Kalem veya Kalemleri Paketleme)
     *
     * @param array<int, array{id: string, quantity: int}> $lineItems
     * @return array<string, mixed>
     */
    public function createPackage(array $lineItems): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_create'] ?? '/packages/merchantid/{merchantId}';
        $url = $this->buildUrl($endpoint, 'order');

        $payload = [
            'lineItemRequests' => array_map(fn(array $item) => [
                'id'       => $item['id'],
                'quantity' => $item['quantity'],
            ], $lineItems),
        ];

        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * Paket bilgilerini listele
     */
    public function fetchPackages(array $filters = []): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages'] ?? '/packages/merchantid/{merchantId}';
        $url = $this->buildUrl($endpoint, 'order');

        $queryParams = [];
        $endDate = !empty($filters['end_date']) ? (string) $filters['end_date'] : date('Y-m-d');
        $beginDate = !empty($filters['begin_date'])
            ? (string) $filters['begin_date']
            : date('Y-m-d', strtotime('-' . max(1, (int) ($filters['timespan'] ?? 30)) . ' days'));
        $queryParams['begindate'] = $this->formatHbDateParam($beginDate);
        $queryParams['enddate'] = $this->formatHbDateParam($endDate);
        $queryParams['offset'] = $filters['offset'] ?? 0;
        $queryParams['limit']  = $filters['limit'] ?? 10;

        $url .= '?' . http_build_query($queryParams);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Fatura linki gönder
     */
    public function sendInvoice(string $packageNumber, string $invoiceUrl): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_invoice']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/invoice';
        $url = str_replace('{packageNumber}', rawurlencode($packageNumber), $this->buildUrl($endpoint, 'order'));

        // HB linki KENDİ sunucusundan indirir: adres .pdf/.html ile bitmeli ve
        // ilgili Content-Type ile dönmelidir. Aksi halde "Url is not correct format"
        // hatası verir. Gönderim öncesi biçim kontrolü yapılır.
        if (!preg_match('#^https?://#i', $invoiceUrl)) {
            throw new RuntimeException('Fatura adresi http(s) ile başlamalı: ' . $invoiceUrl);
        }
        if (!preg_match('#\.(pdf|html?)($|\?)#i', $invoiceUrl)) {
            throw new RuntimeException(
                'Hepsiburada fatura adresi .pdf veya .html ile bitmelidir. Gönderilen: ' . $invoiceUrl
            );
        }

        $this->httpPut($url, ['invoiceLink' => $invoiceUrl], $this->authHeaders());
        return true;
    }

    /**
     * Sipariş kalemi iptal et (sadece Open statüde)
     */
    public function cancelOrderLine(string $lineId): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['cancel_line']
            ?? '/lineitems/merchantid/{merchantId}/id/{lineId}/cancelbymerchant';
        $url = str_replace('{lineId}', $lineId, $this->buildUrl($endpoint, 'order'));

        $this->httpPost($url, null, $this->authHeaders());
        return true;
    }

    /**
     * Test siparişi oluştur (sadece SIT ortamında çalışır)
     *
     * @param array<int, array{Sku: string, Quantity?: int, Price?: float}> $lineItems
     */
    public function createTestOrder(string $orderNumber, array $lineItems): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['test_order_create']
            ?? '/orders/merchantid/{merchantId}';
        $url = $this->environment === 'sit'
            ? 'https://oms-stub-external-sit.hepsiburada.com/orders/merchantId/' . $this->merchantId
            : $this->buildUrl($endpoint, 'order');

        $payload = [
            'OrderNumber' => $orderNumber,
            'OrderDate'   => date('Y-m-d\TH:i:s'),
            'PaymentStatus' => 'Paid',
            'Customer'    => [
                'CustomerId' => 'test-customer-' . uniqid(),
                'Name'       => 'Test Kullanıcı',
            ],
            'DeliveryAddress' => [
                'AddressId'    => 'test-addr-' . uniqid(),
                'Name'         => 'Test Adresi',
                'AddressDetail'=> 'Test Mahallesi, Test Sokak No:1',
                'Email'        => 'test@example.com',
                'CountryCode'  => 'TR',
                'PhoneNumber'  => '905001234567',
                'Town'         => 'Kadıköy',
                'District'     => 'Fenerbahçe',
                'City'         => 'İstanbul',
            ],
            'LineItems' => array_map(fn(array $item) => [
                'Sku'              => $item['Sku'],
                'MerchantId'       => $this->merchantId,
                'Quantity'         => $item['Quantity'] ?? 1,
                'Price'            => ['Amount' => $item['Price'] ?? 100, 'Currency' => 'TRY'],
                'Vat'              => 0,
                'TotalPrice'       => ['Amount' => ($item['Price'] ?? 100) * ($item['Quantity'] ?? 1), 'Currency' => 'TRY'],
                'CargoCompanyId'   => 1,
                'DeliveryOptionId' => 1,
            ], $lineItems),
        ];

        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * Ortak barkod / etiket oluştur
     */
    public function getPackageLabels(string $packageNumber, string $format = 'pdf'): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_labels']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/labels';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));
        $url .= '?format=' . urlencode($format);

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchProducts(int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['products_all'] ?? '/product/api/products/all-products-of-merchant/{merchantId}';
        $url = $this->buildUrl($endpoint, 'catalog');
        $url .= '?' . http_build_query(['page' => $page, 'size' => $size]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Listing kimliğini çöz: HB listeleme servisleri HepsiburadaSku ve/veya
     * MerchantSku bekler. Barkod HB SKU'su DEĞİLDİR — barkodu HepsiburadaSku
     * olarak göndermek güncellemenin sessizce düşmesine yol açıyordu.
     *
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function resolveListingKeys(array $item): array
    {
        $keys = [];

        $hbSku = trim((string) ($item['hbSku'] ?? $item['HepsiburadaSku'] ?? $item['marketplace_product_id'] ?? ''));
        if ($hbSku !== '') {
            $keys['HepsiburadaSku'] = $hbSku;
        }

        // MerchantSku satıcının kendi stok kodudur; HB büyük/küçük harfe duyarlıdır,
        // bu yüzden strtoupper YAPILMAZ.
        $merchantSku = trim((string) ($item['sku'] ?? $item['MerchantSku'] ?? $item['merchant_sku'] ?? ''));
        if ($merchantSku !== '') {
            $keys['MerchantSku'] = $merchantSku;
        }

        return $keys;
    }

    /**
     * XML gövdeli listeleme yükleme isteği için header seti.
     *
     * @return array<int, string>
     */
    private function listingJsonHeaders(): array
    {
        $headers = [];
        foreach ($this->authHeaders() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                continue;
            }
            $headers[] = $header;
        }
        $headers[] = 'Content-Type: application/json';

        return $headers;
    }

    /**
     * Listeleme yükleme isteği gönder ve SONUCUNU DOĞRULA.
     *
     * @param array<int, array<string, string|int>> $listings
     * @param array<int, array<string, mixed>> $preErrors
     * @return array{success: int, failed: int, errors: array, uploadId: ?string, trackingId: ?string, pending?: bool, notice?: string}
     */
    private function submitListingUpload(string $type, array $listings, array $preErrors = []): array
    {
        if ($listings === []) {
            return [
                'success'    => 0,
                'failed'     => count($preErrors),
                'errors'     => $preErrors,
                'uploadId'   => null,
                'trackingId' => null,
            ];
        }

        $this->loadCredentials();

        $endpointKey = "listing_{$type}";
        $endpoint = $this->config['endpoints'][$endpointKey]
            ?? "/listings/merchantid/{merchantId}/{$type}-uploads";
        $url = $this->getBaseUrl('listing') . str_replace('{merchantId}', $this->merchantId, $endpoint);

        // HB aynı anda EN FAZLA 5 bekleyen yükleme kabul eder; parçalar sırayla
        // gönderilip her biri sonuçlanana kadar beklenir (waitForListingUpload).
        $chunkSize = (int) ($this->config['api']['listing_batch_limit'] ?? 1000);
        $chunks    = array_chunk($listings, max(1, $chunkSize));

        $success  = 0;
        $failed   = count($preErrors);
        $errors   = $preErrors;
        $uploadId = null;
        $pending  = false;
        $notice   = '';

        foreach ($chunks as $chunk) {
            try {
                $response = $this->httpPostRaw(
                    $url,
                    (string) json_encode(array_values($chunk), JSON_UNESCAPED_UNICODE),
                    $this->listingJsonHeaders()
                );
                $chunkUploadId = $this->extractUploadId($response);

                if ($chunkUploadId === null) {
                    $failed += count($chunk);
                    $errors[] = ['error' => 'Hepsiburada yükleme kimliği (uploadId) dönmedi: '
                        . json_encode($response, JSON_UNESCAPED_UNICODE)];
                    continue;
                }

                $uploadId = $chunkUploadId;

                $status = $this->waitForListingUpload($type, $chunkUploadId, count($chunk));
                $success += $status['success'];
                $failed  += $status['failed'];
                foreach ($status['errors'] as $error) {
                    $errors[] = $error;
                }
                if (!empty($status['pending'])) {
                    $pending = true;
                    $notice  = (string) ($status['notice'] ?? '');
                }
            } catch (\Throwable $e) {
                $failed += count($chunk);
                $errors[] = ['error' => $e->getMessage()];
            }
        }

        $result = [
            'success'    => $success,
            'failed'     => $failed,
            'errors'     => $errors,
            'uploadId'   => $uploadId,
            'trackingId' => $uploadId,
        ];

        if ($pending) {
            $result['pending'] = true;
            $result['notice']  = $notice;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractUploadId(array $response): ?string
    {
        $candidates = [
            $response['id'] ?? null,
            $response['Id'] ?? null,
            $response['data']['id'] ?? null,
            $response['inventoryUploadId'] ?? null,
            $response['uploadId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_int($candidate) && $candidate > 0) {
                return (string) $candidate;
            }
        }

        // Bazı yanıtlar düz metin (GUID) döner
        $raw = trim((string) ($response['raw_response'] ?? ''));
        if ($raw !== '' && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $raw, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Yükleme tamamlanana kadar durum sorgula.
     *
     * @return array{success: int, failed: int, errors: array, pending: bool, notice?: string}
     */
    private function waitForListingUpload(string $type, string $uploadId, int $expected): array
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::UPLOAD_POLL_ATTEMPTS; $attempt++) {
            try {
                $response = $this->checkUploadStatus($type, $uploadId);

                $parsed = $this->parseUploadStatus($response, $uploadId, $expected);
                if (!$parsed['pending']) {
                    return $parsed;
                }
            } catch (\Throwable $e) {
                // İşlenmekte olan yükleme sorgusu geçici olarak hata dönebilir.
                // İstek HB tarafından KABUL EDİLDİ (uploadId var); sorgu hatasını
                // "gönderim başarısız" saymak yanlış olur.
                $lastError = $e->getMessage();
                $this->logger->warning(
                    "Hepsiburada {$type} yükleme durumu sorgulanamadı ({$uploadId}): {$lastError}",
                    'HepsiburadaService'
                );
            }

            // Toplu yayınlamada istek başına bekleme bütçesi aşılmasın
            if ($attempt < self::UPLOAD_POLL_ATTEMPTS && !$this->pollWait(self::UPLOAD_POLL_DELAY_MS)) {
                $lastError = $lastError !== '' ? $lastError : 'bekleme bütçesi doldu (yükleme işleniyor)';
                break;
            }
        }

        $notice = "Hepsiburada {$type} yüklemesi hâlâ işleniyor (id: {$uploadId})."
            . ($lastError !== '' ? " Son sorgu hatası: {$lastError}" : '');
        $this->logger->info($notice, 'HepsiburadaService');

        return [
            'success' => $expected,
            'failed'  => 0,
            'errors'  => [],
            'pending' => true,
            'notice'  => $notice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUploadStatus(string $type, string $uploadId): array
    {
        $endpoint = $this->config['endpoints']["listing_{$type}_check"]
            ?? "/listings/merchantid/{merchantId}/{$type}-uploads/id/{uploadId}";

        $path = str_replace(
            ['{merchantId}', '{uploadId}'],
            [$this->merchantId, rawurlencode($uploadId)],
            $endpoint
        );

        return $this->httpGet($this->getBaseUrl('listing') . $path, $this->authHeaders());
    }

    /**
     * HB yükleme durumu yanıtını sayısal sonuca çevir.
     *
     * @param array<string, mixed> $response
     * @return array{success: int, failed: int, errors: array, pending: bool}
     */
    private function parseUploadStatus(array $response, string $uploadId, int $expected): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

        $status = strtoupper(trim((string) (
            $data['status'] ?? $data['Status'] ?? $data['state'] ?? $response['status'] ?? ''
        )));
        $normalizedStatus = str_replace([' ', '-', '_'], '', $status);

        $errorRows = [];
        foreach (['errors', 'Errors', 'validationErrors', 'failedItems', 'errorList'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $errorRows = $data[$key];
                break;
            }
        }

        $failed = 0;
        $errors = [];
        foreach ($errorRows as $row) {
            if (is_string($row)) {
                $failed++;
                $errors[] = ['error' => $row];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }

            $failed++;
            $sku = (string) ($row['sku'] ?? $row['merchantSku'] ?? $row['hepsiburadaSku'] ?? '');
            $msg = (string) ($row['message'] ?? $row['error'] ?? $row['description'] ?? $row['errorMessage']
                ?? json_encode($row, JSON_UNESCAPED_UNICODE));
            $errors[] = ['sku' => $sku, 'error' => trim($sku . ': ' . $msg, ': ')];
        }

        if ($normalizedStatus !== '' && in_array($normalizedStatus, self::UPLOAD_PENDING_STATES, true)) {
            return ['success' => 0, 'failed' => 0, 'errors' => [], 'pending' => true];
        }

        if ($normalizedStatus !== '' && in_array($normalizedStatus, self::UPLOAD_FAILED_STATES, true) && $errors === []) {
            return [
                'success' => 0,
                'failed'  => $expected,
                'errors'  => [['error' => "Hepsiburada yüklemesi başarısız (id: {$uploadId}, durum: {$status})"]],
                'pending' => false,
            ];
        }

        // Durum bilgisi yoksa ve hata listesi de yoksa yükleme henüz işlenmemiş olabilir.
        if ($normalizedStatus === '' && $errors === [] && !array_key_exists('id', $data) && !array_key_exists('Id', $data)) {
            return ['success' => 0, 'failed' => 0, 'errors' => [], 'pending' => true];
        }

        return [
            'success' => max(0, $expected - $failed),
            'failed'  => $failed,
            'errors'  => $errors,
            'pending' => false,
        ];
    }

    public function updateStock(array $items): array
    {
        $listings  = [];
        $preErrors = [];

        foreach ($items as $item) {
            $keys = $this->resolveListingKeys($item);
            if ($keys === []) {
                $preErrors[] = ['error' => 'Hepsiburada SKU bilgisi yok (HB SKU veya satıcı stok kodu gerekli) — stok gönderilemedi'];
                continue;
            }

            $listings[] = $keys + ['AvailableStock' => max(0, (int) ($item['quantity'] ?? 0))];
        }

        return $this->submitListingUpload('stock', $listings, $preErrors);
    }

    public function updatePrice(array $items): array
    {
        $listings  = [];
        $preErrors = [];

        foreach ($items as $item) {
            $keys = $this->resolveListingKeys($item);
            if ($keys === []) {
                $preErrors[] = ['error' => 'Hepsiburada SKU bilgisi yok (HB SKU veya satıcı stok kodu gerekli) — fiyat gönderilemedi'];
                continue;
            }

            $price = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
            if ($price <= 0) {
                $preErrors[] = ['error' => 'Geçersiz fiyat (0) — Hepsiburada güncellemesi atlandı: '
                    . implode('/', $keys)];
                continue;
            }

            // JSON gövdede fiyat SAYIDIR; XML gövdedeki virgüllü metin biçimi
            // (118,97) JSON'da geçersizdir.
            $listings[] = $keys + ['Price' => round($price, 2)];
        }

        return $this->submitListingUpload('price', $listings, $preErrors);
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN YÜKLEME (mpop import — resmi şema)
    // ═══════════════════════════════════════════════════════

    /** HB import dosyası başına en fazla ürün */
    private const IMPORT_MAX_ITEMS = 100;

    /** HB import'ta gönderilebilen görsel sayısı (Image1..Image10) */
    private const IMPORT_IMAGE_SLOTS = 10;

    /** Yükleme sonucu (trackingId) sorgu yolu — version=1 ile çağrılır */
    private const IMPORT_STATUS_PATH = '/product/api/products/status/{trackingId}';

    /** Ürün alanlarından üretilen temel özellikler — kategori özellikleri bunları ezemez */
    private const IMPORT_BASE_KEYS = [
        'merchantSku', 'VaryantGroupID', 'Barcode', 'UrunAdi', 'UrunAciklamasi', 'Marka',
        'GarantiSuresi', 'kg', 'tax_vat_rate', 'price', 'stock',
    ];

    /**
     * Ürün bilgisi gönder (JSON dosyası multipart 'file' alanıyla, en fazla 100 ürün)
     *
     * @param array<int, array<string, mixed>> $products HB import şemasında ürün dizisi (buildImportPayload çıktısı)
     * @return array{trackingId: string|null, success: bool, message: string}
     */
    public function uploadProduct(array $products): array
    {
        $this->loadCredentials();

        if (count($products) > self::IMPORT_MAX_ITEMS) {
            throw new RuntimeException('Hepsiburada tek yüklemede en fazla ' . self::IMPORT_MAX_ITEMS . ' ürün kabul eder');
        }

        $endpoint = $this->config['endpoints']['product_upload'] ?? '/product/api/products/import';
        $url = $this->getBaseUrl('catalog') . $endpoint;

        // JSON dosyasını temp olarak oluştur
        $tmpDir = BASE_PATH . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpFile = $tmpDir . '/hb_product_upload_' . uniqid() . '.json';

        try {
            $jsonContent = json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($jsonContent === false) {
                throw new RuntimeException('Ürün verileri JSON\'a dönüştürülemedi');
            }
            file_put_contents($tmpFile, $jsonContent);

            $response = $this->httpPostMultipart($url, $tmpFile, 'file', $this->authHeaders());

            $trackingId = trim((string) ($response['data']['trackingId'] ?? $response['trackingId'] ?? ''));
            $apiAccepted = !array_key_exists('success', $response) || $response['success'] !== false;
            $message = trim((string) ($response['message'] ?? ''));

            return [
                'trackingId' => $trackingId !== '' ? $trackingId : null,
                'success'    => $apiAccepted && $trackingId !== '',
                'message'    => $message !== '' ? $message : 'Ürün yükleme isteği gönderildi',
            ];
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Ürün oluştur (toplu) — ProductPublishService'in beklediği ortak sözleşme.
     * Ürünler HB resmi import şemasına çevrilir ({categoryId, merchant, attributes{...}}),
     * 100'lük dosyalar halinde yüklenir; dönen trackingId batch takibi için kullanılır.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success: bool, trackingId: ?string, trackingIds: array<int, string>, errors: array}
     */
    public function createProducts(array $items): array
    {
        try {
            $this->loadCredentials();
        } catch (\Throwable $e) {
            return ['success' => false, 'trackingId' => null, 'trackingIds' => [], 'errors' => [['error' => $e->getMessage()]]];
        }

        $payload = $this->buildImportPayload($items);
        if ($payload['errors'] !== []) {
            return ['success' => false, 'trackingId' => null, 'trackingIds' => [], 'errors' => $payload['errors']];
        }
        if ($payload['products'] === []) {
            return ['success' => false, 'trackingId' => null, 'trackingIds' => [], 'errors' => [['error' => 'Gönderilecek ürün yok']]];
        }

        $trackingIds = [];
        try {
            foreach (array_chunk($payload['products'], self::IMPORT_MAX_ITEMS) as $chunk) {
                $result = $this->uploadProduct($chunk);
                if (!$result['success']) {
                    return [
                        'success'     => false,
                        'trackingId'  => $trackingIds[0] ?? null,
                        'trackingIds' => $trackingIds,
                        'errors'      => [['error' => 'Hepsiburada ürün yüklemeyi kabul etmedi: ' . $result['message']]],
                    ];
                }
                $trackingIds[] = (string) $result['trackingId'];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'trackingId' => $trackingIds[0] ?? null, 'trackingIds' => $trackingIds, 'errors' => [['error' => $e->getMessage()]]];
        }

        return ['success' => true, 'trackingId' => $trackingIds[0], 'trackingIds' => $trackingIds, 'errors' => []];
    }

    /**
     * Ürünleri HB import şemasına çevir (gönderim YAPMAZ — önizleme/test için de kullanılır).
     *
     * Beklenen ürün alanları: categoryId, merchantSku|stockCode|sku, barcode, productName|title,
     * description, brand, salePrice|price, quantity|availableStock, vatRate, desi, images[],
     * variantGroupId (ops.), hbAttributes {özellikId: değer} (kategori özellikleri).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{products: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
     */
    public function buildImportPayload(array $items): array
    {
        $merchantId = $this->merchantIdForPayload();
        $products = [];
        $errors = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $built = $this->buildImportProduct($item, $merchantId);
            if ($built['errors'] !== []) {
                $errors[] = [
                    'barcode'     => $built['barcode'],
                    'merchantSku' => $built['merchantSku'],
                    'error'       => 'Hepsiburada ürün verisi eksik (' . ($built['merchantSku'] !== '' ? $built['merchantSku'] : '?') . '): '
                        . implode('; ', $built['errors']),
                ];
                continue;
            }

            $products[] = $built['product'];
        }

        return ['products' => $products, 'errors' => $errors];
    }

    /**
     * Tek ürünü resmi şemaya çevir:
     * {"categoryId": int, "merchant": "<merchantId>", "attributes": {merchantSku, VaryantGroupID, Barcode,
     *  UrunAdi, UrunAciklamasi, Marka, GarantiSuresi, kg, tax_vat_rate, price, stock, Image1.., <özellikId>: değer}}
     *
     * @param array<string, mixed> $item
     * @return array{product: array<string, mixed>, errors: array<int, string>, merchantSku: string, barcode: string}
     */
    private function buildImportProduct(array $item, string $merchantId): array
    {
        $errors = [];

        $sku = self::normalizeMerchantSku((string) (
            $item['merchantSku'] ?? $item['stockCode'] ?? $item['merchant_sku'] ?? $item['sku'] ?? ''
        ));
        $barcode = trim((string) ($item['barcode'] ?? ''));
        $rawName = strip_tags((string) ($item['productName'] ?? $item['title'] ?? $item['name'] ?? ''));
        $name = trim((string) preg_replace('/\s+/u', ' ', $rawName));
        $brand = trim((string) ($item['brand'] ?? $item['brandName'] ?? ''));
        $categoryId = (int) ($item['categoryId'] ?? 0);
        $price = round((float) ($item['salePrice'] ?? $item['price'] ?? 0), 2);
        $stock = max(0, (int) ($item['quantity'] ?? $item['availableStock'] ?? 0));
        $desi = (float) ($item['desi'] ?? 0);
        $images = $this->collectImportImageUrls((array) ($item['images'] ?? []));

        if ($merchantId === '') {
            $errors[] = 'Merchant ID (Mağaza ID) girilmemiş';
        }
        if ($categoryId <= 0) {
            $errors[] = 'Hepsiburada kategori ID yok';
        }
        if ($sku === '') {
            $errors[] = 'merchantSku (stok kodu) yok';
        }
        if ($barcode === '') {
            $errors[] = 'Barkod yok';
        }
        if ($name === '') {
            $errors[] = 'Ürün adı yok';
        }
        if ($brand === '') {
            $errors[] = 'Marka yok';
        }
        if ($images === []) {
            $errors[] = 'Görsel (Image1) yok';
        }
        if ($price <= 0) {
            $errors[] = 'Fiyat 0';
        }

        $variantGroupId = self::normalizeMerchantSku((string) ($item['variantGroupId'] ?? ''));

        $attributes = [
            'merchantSku'    => $sku,
            // Tekil ürün: varyant grubu = kendi stok kodu (varyantlı gruplar ortak ID taşır)
            'VaryantGroupID' => $variantGroupId !== '' ? $variantGroupId : $sku,
            'Barcode'        => $barcode,
            'UrunAdi'        => mb_substr($name, 0, 200),
            'UrunAciklamasi' => self::cleanDescriptionHtml((string) ($item['description'] ?? ''), $name),
            'Marka'          => $brand,
            'GarantiSuresi'  => max(0, (int) ($item['warrantyMonths'] ?? 0)),
            'tax_vat_rate'   => self::formatVatRate($item['vatRate'] ?? 20),
            // HB ondalık ayırıcı virgül ister; fiyat/stok metin olarak gider
            'price'          => number_format($price, 2, ',', ''),
            'stock'          => (string) $stock,
        ];
        if ($desi > 0) {
            $attributes['kg'] = self::formatHbDecimal($desi);
        }

        foreach (array_slice($images, 0, self::IMPORT_IMAGE_SLOTS) as $index => $url) {
            $attributes['Image' . ($index + 1)] = $url;
        }

        foreach ((array) ($item['hbAttributes'] ?? []) as $attrId => $value) {
            $attrId = trim((string) $attrId);
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($attrId === '' || $value === '' || in_array($attrId, self::IMPORT_BASE_KEYS, true)) {
                continue;
            }
            $attributes[$attrId] = $value;
        }

        return [
            'product'     => ['categoryId' => $categoryId, 'merchant' => $merchantId, 'attributes' => $attributes],
            'errors'      => $errors,
            'merchantSku' => $sku,
            'barcode'     => $barcode,
        ];
    }

    /**
     * Payload için Merchant ID — servis anahtarı gerektirmez (önizleme gönderim olmadan da kurulabilsin).
     *
     * Çok kiracılı: her çağrıda AKTİF mağazanın bağlantı kaydı okunur. $this->merchantId bir önceki
     * mağazanın loadCredentials() sonucundan kalmış olabilir (cron mağaza döngüsü) — ona güvenilmez.
     */
    private function merchantIdForPayload(): string
    {
        $marketplace = $this->marketplaceModel->findBySlug(self::SLUG);
        if ($marketplace === false) {
            return '';
        }
        $extra = json_decode((string) ($marketplace['extra_config_json'] ?? '{}'), true);

        return is_array($extra) ? trim((string) ($extra['merchant_id'] ?? '')) : '';
    }

    /**
     * @param array<int|string, mixed> $images
     * @return array<int, string>
     */
    private function collectImportImageUrls(array $images): array
    {
        $urls = [];
        foreach ($images as $img) {
            $url = trim(is_array($img) ? (string) ($img['url'] ?? '') : (string) $img);
            if ($url !== '' && preg_match('~^https?://~i', $url) === 1) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * HB merchantSku kuralı: BÜYÜK HARF, boşluksuz, Türkçe karaktersiz
     */
    public static function normalizeMerchantSku(string $sku): string
    {
        $sku = strtr(trim($sku), [
            'ç' => 'C', 'Ç' => 'C', 'ğ' => 'G', 'Ğ' => 'G', 'ı' => 'I', 'İ' => 'I',
            'ö' => 'O', 'Ö' => 'O', 'ş' => 'S', 'Ş' => 'S', 'ü' => 'U', 'Ü' => 'U',
        ]);
        $sku = strtoupper((string) preg_replace('/\s+/', '-', $sku));
        $sku = (string) preg_replace('/[^A-Z0-9\-_.\/]/', '', $sku);

        return mb_substr(trim($sku, '-'), 0, 100);
    }

    /**
     * Açıklamayı HB kurallarına makul uyumlu HTML'e çevir:
     * script/style/iframe yok, öznitelik yok (style/class/on*), bağlantı ve e-posta yok,
     * düz metin paragraflara bölünür. Boşsa ürün adı kullanılır.
     */
    public static function cleanDescriptionHtml(string $html, string $fallback = ''): string
    {
        $html = trim($html);
        if ($html === '') {
            $html = trim($fallback);
        }

        if ($html === strip_tags($html)) {
            $paragraphs = preg_split('/\R{2,}/u', $html) ?: [];
            $parts = [];
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph !== '') {
                    $parts[] = '<p>' . nl2br(htmlspecialchars($paragraph, ENT_NOQUOTES, 'UTF-8'), false) . '</p>';
                }
            }
            $html = implode('', $parts);
        } else {
            $html = (string) preg_replace('#<(script|style|iframe|object|embed|form|noscript|svg)\b[^>]*>.*?</\1>#isu', '', $html);
            $html = strip_tags($html, '<p><br><ul><ol><li><strong><b><em><i><u><h2><h3><h4><table><thead><tbody><tr><td><th>');
            $html = (string) preg_replace('#<(/?)([a-z0-9]+)\b[^>]*>#iu', '<$1$2>', $html);
        }

        // Önce e-posta, sonra bağlantı/alan adı (alan adı silinince e-postanın yarısı kalmasın)
        $html = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $html);
        $html = (string) preg_replace('#\b(?:https?://|www\.)[^\s<]+#iu', '', $html);
        $html = (string) preg_replace('#\b[a-z0-9][a-z0-9\-]*\.(?:com\.tr|net\.tr|org\.tr|com|net|org|info|shop|store)\b[^\s<]*#iu', '', $html);
        $html = (string) preg_replace('#<p>(?:\s|&nbsp;|<br>)*</p>#iu', '', $html);

        return mb_substr(trim($html), 0, 60000);
    }

    /**
     * KDV oranı metni: 1, 10, 20, 0 …
     */
    private static function formatVatRate(mixed $rate): string
    {
        $value = is_scalar($rate) ? (float) str_replace(',', '.', (string) $rate) : 20.0;

        return (string) (int) round(max(0.0, $value));
    }

    /**
     * HB ondalık biçimi: 2 → "2", 2.5 → "2,5"
     */
    private static function formatHbDecimal(float $value): string
    {
        $text = number_format($value, 2, ',', '');

        return rtrim(rtrim($text, '0'), ',');
    }

    /**
     * Hepsiburada'ya varyantlı ürün grubu gönder.
     * Aynı VaryantGroupID ile gönderilen ürünler tek kart altında gruplanır. Gönderim createProducts
     * üzerinden RESMİ import şemasıyla yapılır ({categoryId, merchant, attributes{merchantSku, Barcode,
     * UrunAdi, Marka, …, <özellikId>}}); eksik zorunlu alan (marka, görsel, barkod…) varsa hiçbir şey
     * gönderilmez, hatalar döner.
     *
     * Öğe alanları: categoryId, merchantSku|stockCode, barcode, productName|title, description, brand,
     * salePrice, quantity, vatRate, desi|dimensionalWeight, images[], variantGroupId, hbAttributes{id: değer}.
     *
     * @param array{items: array, groupId: string} $variantGroup
     * @return array{success: bool, trackingId: ?string, trackingIds: array<int, string>, errors: array}
     */
    public function createVariantProducts(array $variantGroup): array
    {
        $groupId = (string) ($variantGroup['groupId'] ?? '');

        $items = [];
        foreach ((array) ($variantGroup['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'categoryId'     => $item['categoryId'] ?? ($variantGroup['categoryId'] ?? 0),
                'merchantSku'    => $item['merchantSku'] ?? $item['stockCode'] ?? $item['barcode'] ?? '',
                'barcode'        => $item['barcode'] ?? '',
                'productName'    => $item['productName'] ?? $item['title'] ?? '',
                'description'    => $item['description'] ?? '',
                'brand'          => $item['brand'] ?? ($variantGroup['brand'] ?? ''),
                'salePrice'      => $item['salePrice'] ?? $item['price'] ?? 0,
                'quantity'       => $item['quantity'] ?? $item['availableStock'] ?? 0,
                'vatRate'        => $item['vatRate'] ?? 20,
                'desi'           => $item['desi'] ?? $item['dimensionalWeight'] ?? 0,
                'images'         => $item['images'] ?? [],
                'variantGroupId' => $item['variantGroupId'] ?? $groupId,
                'hbAttributes'   => is_array($item['hbAttributes'] ?? null) ? $item['hbAttributes'] : [],
            ];
        }

        return $this->createProducts($items);
    }

    /**
     * Ürün yükleme sonucu sorgula (trackingId ile) — GET /product/api/products/status/{trackingId}?version=1
     *
     * @return array<string, mixed> Ham HB yanıtı (data[]: merchantSku, barcode, productStatus, validationResults…)
     */
    public function checkProductStatus(string $trackingId, int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_import_status'] ?? self::IMPORT_STATUS_PATH;
        $url = str_replace('{trackingId}', rawurlencode(trim($trackingId)), $this->getBaseUrl('catalog') . $endpoint);
        $url .= '?' . http_build_query(['page' => $page, 'size' => $size, 'version' => 1]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Yükleme sonucunu ürün bazında özetle.
     * durum: 'isleniyor' (HB henüz işlemedi) | 'tamamlandi' | 'kismi' | 'hata'
     * ürün durumu: 'basarili' | 'hata' | 'isleniyor'
     *
     * @return array{trackingId: string, durum: string, mesaj: string, items: array<int, array<string, mixed>>, ozet: array<string, int>}
     */
    public function fetchImportResult(string $trackingId): array
    {
        $trackingId = trim($trackingId);
        $items = [];
        $hbMessage = '';
        $page = 0;

        do {
            $response = $this->checkProductStatus($trackingId, $page, 100);
            $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
            if ($rows === [] && ($response['success'] ?? true) === false) {
                $hbMessage = trim((string) ($response['message'] ?? ''));
                break;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = $this->normalizeImportStatusRow($row);
                }
            }
            $totalPages = (int) ($response['totalPages'] ?? 1);
            $page++;
        } while ($page < $totalPages && $page < 50);

        $ozet = ['basarili' => 0, 'hata' => 0, 'isleniyor' => 0];
        foreach ($items as $item) {
            $ozet[$item['durum']]++;
        }

        if ($items === []) {
            $durum = 'isleniyor';
            $mesaj = 'Hepsiburada yüklemeyi henüz işlemedi — işleniyor. Birkaç dakika sonra tekrar sorgulayın.'
                . ($hbMessage !== '' ? ' (HB: ' . $hbMessage . ')' : '');
        } elseif ($ozet['isleniyor'] > 0) {
            $durum = 'isleniyor';
            $mesaj = $ozet['isleniyor'] . ' ürün hâlâ işleniyor; ' . $ozet['basarili'] . ' başarılı, ' . $ozet['hata'] . ' hatalı.';
        } elseif ($ozet['hata'] > 0) {
            $durum = $ozet['basarili'] > 0 ? 'kismi' : 'hata';
            $mesaj = $ozet['hata'] . ' ürün reddedildi' . ($ozet['basarili'] > 0 ? ', ' . $ozet['basarili'] . ' ürün başarılı.' : '.');
        } else {
            $durum = 'tamamlandi';
            $mesaj = $ozet['basarili'] . ' ürün Hepsiburada tarafından kabul edildi.';
        }

        return ['trackingId' => $trackingId, 'durum' => $durum, 'mesaj' => $mesaj, 'items' => $items, 'ozet' => $ozet];
    }

    /**
     * ProductPublishService bekleyen-batch sözleşmesi (Trendyol getBatchStatus biçimi):
     * {status: PROCESSING|COMPLETED, items: [{barcode, status: SUCCESS|FAILED|PROCESSING, failureReasons[]}]}
     *
     * @return array{status: string, items: array<int, array<string, mixed>>}
     */
    public function getBatchStatus(string $trackingId): array
    {
        $result = $this->fetchImportResult($trackingId);
        $statusMap = ['basarili' => 'SUCCESS', 'hata' => 'FAILED', 'isleniyor' => 'PROCESSING'];

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = [
                'barcode'        => $row['barcode'],
                'merchantSku'    => $row['merchantSku'],
                // Diğer pazaryerlerinin ortak sözleşmesindeki ad (batchStatusForProduct barkod VEYA sku eşler)
                'sku'            => $row['merchantSku'],
                'status'         => $statusMap[$row['durum']] ?? 'PROCESSING',
                'failureReasons' => $row['mesajlar'],
            ];
        }

        return ['status' => $result['durum'] === 'isleniyor' ? 'PROCESSING' : 'COMPLETED', 'items' => $items];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{merchantSku: string, barcode: string, hbSku: string, productName: string, importStatus: string, durum: string, mesajlar: array<int, string>}
     */
    private function normalizeImportStatusRow(array $row): array
    {
        $status = '';
        foreach (['importStatus', 'productStatus', 'status', 'taskStatus'] as $key) {
            if (is_scalar($row[$key] ?? null) && trim((string) $row[$key]) !== '') {
                $status = trim((string) $row[$key]);
                break;
            }
        }

        $messages = [];
        foreach (['validationResults', 'importMessages', 'rejectReasonsMessages', 'rejectReasons', 'failureReasons', 'errors'] as $key) {
            foreach ((array) ($row[$key] ?? []) as $entry) {
                $text = $this->importStatusMessage($entry);
                if ($text !== '') {
                    $messages[] = $text;
                }
            }
        }
        $messages = array_values(array_unique($messages));

        return [
            'merchantSku'  => trim((string) ($row['merchantSku'] ?? '')),
            'barcode'      => trim((string) ($row['barcode'] ?? $row['Barcode'] ?? '')),
            'hbSku'        => trim((string) ($row['hbSku'] ?? $row['hepsiburadaSku'] ?? '')),
            'productName'  => trim((string) ($row['productName'] ?? '')),
            'importStatus' => $status,
            'durum'        => $this->classifyImportStatus($status, $messages !== []),
            'mesajlar'     => $messages,
        ];
    }

    private function importStatusMessage(mixed $entry): string
    {
        if (is_scalar($entry)) {
            return trim((string) $entry);
        }
        if (!is_array($entry)) {
            return '';
        }

        $text = '';
        foreach (['message', 'description', 'reason', 'detail'] as $key) {
            if (is_scalar($entry[$key] ?? null) && trim((string) $entry[$key]) !== '') {
                $text = trim((string) $entry[$key]);
                break;
            }
        }
        if ($text === '') {
            return '';
        }

        $attribute = trim((string) ($entry['attributeName'] ?? $entry['attribute'] ?? $entry['field'] ?? ''));

        return $attribute !== '' ? $attribute . ': ' . $text : $text;
    }

    /**
     * HB ürün/import durum metnini sınıflandır (İngilizce kod ya da Türkçe etiket gelebilir)
     */
    private function classifyImportStatus(string $status, bool $hasMessages): string
    {
        $key = str_replace(' ', '_', self::foldText($status));

        $failed = ['rejected', 'reddedildi', 'missing_info', 'eksik_bilgi', 'failed', 'fail', 'error', 'hata', 'invalid', 'basarisiz', 'cancelled'];
        if (in_array($key, $failed, true) || str_contains($key, 'reject') || str_contains($key, 'redded')) {
            return 'hata';
        }
        if ($hasMessages) {
            return 'hata';
        }

        $done = ['matched', 'created', 'success', 'succeeded', 'completed', 'done', 'satisa_hazir', 'eslesti', 'olusturuldu', 'matched_with_staged', 'approved', 'onaylandi'];
        if (in_array($key, $done, true) || str_contains($key, 'satisa_hazir')) {
            return 'basarili';
        }

        return 'isleniyor';
    }

    /**
     * TrackingId geçmişini sorgula
     */
    public function fetchTrackingHistory(int $page = 0, int $size = 20): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_tracking_list']
            ?? '/product/api/products/tracking-list';
        $url = $this->getBaseUrl('catalog') . $endpoint;
        $url .= '?' . http_build_query(['page' => $page, 'size' => $size]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Ürüne ait statü bilgisi çek
     */
    public function fetchProductStatuses(): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_status']
            ?? '/product/api/products/status/{merchantId}';
        $url = $this->buildUrl($endpoint, 'catalog');

        return $this->httpPost($url, null, $this->authHeaders());
    }

    /**
     * Statü bazlı ürün bilgisi çek
     *
     * @param string $productStatus WAITING|MISSING_INFO|MATCHED|PRE_MATCHED|REJECTED|MATCHED_WITH_STAGED|CREATED
     */
    public function fetchProductsByStatus(string $productStatus, int $page = 0, int $size = 100): array
    {
        $this->loadCredentials();

        // Doğru endpoint: products-by-merchant-and-status (merchantId + productStatus QUERY paramı).
        // Eski yol /product/api/products/{merchantId}/products/{status} HB'de 404 veriyordu.
        $endpoint = $this->config['endpoints']['product_by_status']
            ?? '/product/api/products/products-by-merchant-and-status';
        $url = $this->buildUrl($endpoint, 'catalog');
        $url .= '?' . http_build_query([
            'merchantId'    => $this->merchantId,
            'productStatus' => $productStatus,
            'page'          => $page,
            'size'          => $size,
        ]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Eşleşen statü onay (pre-match approve)
     */
    public function approvePreMatch(string $merchantSku, string $hbSku): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_prematch_approve']
            ?? '/product/api/products/prematch-approve';
        $url = $this->getBaseUrl('catalog') . $endpoint;

        $this->httpPost($url, [
            'merchantSku' => $merchantSku,
            'hbSku'       => $hbSku,
            'merchantId'  => $this->merchantId,
        ], $this->authHeaders());

        return true;
    }

    /**
     * Eşleşen statü red (pre-match reject)
     */
    public function rejectPreMatch(string $merchantSku): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_prematch_reject']
            ?? '/product/api/products/prematch-reject';
        $url = $this->getBaseUrl('catalog') . $endpoint;

        $this->httpPost($url, [
            'merchantSku' => $merchantSku,
            'merchantId'  => $this->merchantId,
        ], $this->authHeaders());

        return true;
    }

    /**
     * Aksiyon bekleyen ürünleri sil
     *
     * @param array<int, string> $merchantSkus
     */
    public function deleteActionPendingProducts(array $merchantSkus): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_delete']
            ?? '/product/api/products/{merchantId}/delete';
        $url = $this->buildUrl($endpoint, 'catalog');

        return $this->httpPost($url, ['merchantSkuList' => $merchantSkus], $this->authHeaders());
    }

    /**
     * Ürün silme işlem durumu kontrol
     */
    public function checkDeleteStatus(string $trackingId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_delete_status']
            ?? '/product/api/products/{merchantId}/delete/{trackingId}';
        $url = str_replace('{trackingId}', $trackingId, $this->buildUrl($endpoint, 'catalog'));

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // LİSTELEME İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    /**
     * Listing aktivasyonu (satışa aç)
     *
     * @param array<int, string> $hbSkus
     */
    public function activateListing(array $hbSkus): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_activate']
            ?? '/listings/merchantid/{merchantId}/sku/{sku}/activate';

        return $this->changeListingActivation($hbSkus, $endpoint);
    }

    /**
     * Listing deaktivasyonu (satıştan kapat)
     *
     * @param array<int, string> $hbSkus
     */
    public function deactivateListing(array $hbSkus): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_deactivate']
            ?? '/listings/merchantid/{merchantId}/sku/{sku}/deactivate';

        return $this->changeListingActivation($hbSkus, $endpoint);
    }

    /**
     * @param array<int, string> $hbSkus
     */
    private function changeListingActivation(array $hbSkus, string $endpoint): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach (array_values(array_unique(array_filter($hbSkus))) as $sku) {
            $path = str_replace(
                ['{merchantId}', '{sku}'],
                [$this->merchantId, rawurlencode((string) $sku)],
                $endpoint
            );
            $url = $this->getBaseUrl('listing') . $path;

            try {
                $this->httpPost($url, null, $this->authHeaders());
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['sku' => (string) $sku, 'error' => $e->getMessage()];
            }
        }

        return [
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
        ];
    }

    /**
     * Ürünü Hepsiburada'da satışa aç / satıştan kaldır.
     *
     * Pazaryeri servislerinin ORTAK sözleşmesi: setListingStatus(items, active).
     *
     * İki yol denenir:
     *  1) Listing activate/deactivate uç noktası (varsa en temiz yol),
     *  2) HB dokümanının resmî yöntemi: satışa kapatmak için stok 0 gönderilir,
     *     açarken paneldeki gerçek stok geri yazılır.
     *
     * @param array<int, array<string, mixed>|string> $items
     * @return array{success: int, failed: int, errors: array, method: string}
     */
    public function setListingStatus(array $items, bool $active): array
    {
        $this->loadCredentials();

        $normalizedItems = [];
        $errors = [];
        $hbSkus = [];

        foreach ($items as $item) {
            $row = is_array($item) ? $item : ['hbSku' => (string) $item];
            $keys = $this->resolveListingKeys($row);

            if ($keys === []) {
                $errors[] = ['error' => 'Hepsiburada SKU bilgisi yok — satış durumu değiştirilemedi'];
                continue;
            }

            $normalizedItems[] = $row;
            if (!empty($keys['HepsiburadaSku'])) {
                $hbSkus[] = $keys['HepsiburadaSku'];
            }
        }

        if ($normalizedItems === []) {
            return ['success' => 0, 'failed' => count($errors), 'errors' => $errors, 'method' => 'none'];
        }

        // 1) Dedike uç nokta (tüm SKU'ların HB SKU'su varsa denenir)
        if ($hbSkus !== [] && count($hbSkus) === count($normalizedItems)) {
            $endpointResult = $active
                ? $this->activateListing($hbSkus)
                : $this->deactivateListing($hbSkus);

            if (($endpointResult['failed'] ?? 0) === 0 && ($endpointResult['success'] ?? 0) > 0) {
                return [
                    'success' => (int) $endpointResult['success'],
                    'failed'  => count($errors),
                    'errors'  => $errors,
                    'method'  => $active ? 'listing-activate' : 'listing-deactivate',
                ];
            }

            $this->logger->warning(
                'Hepsiburada activate/deactivate uç noktası sonuç vermedi, stok yöntemine düşülüyor: '
                . json_encode($endpointResult['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'HepsiburadaService'
            );
        }

        // 2) Resmî yöntem: stok 0 = satışa kapalı, stok > 0 = satışta
        $stockItems = [];
        foreach ($normalizedItems as $row) {
            $quantity = $active ? max(1, (int) ($row['quantity'] ?? 0)) : 0;

            if ($active && (int) ($row['quantity'] ?? 0) <= 0) {
                $errors[] = [
                    'error' => 'Satışa açmak için stok 0 olamaz — panelde stok girin: '
                        . implode('/', $this->resolveListingKeys($row)),
                ];
                continue;
            }

            $stockItems[] = $row + ['quantity' => $quantity];
        }

        if ($stockItems === []) {
            return ['success' => 0, 'failed' => count($errors), 'errors' => $errors, 'method' => 'stock-upload'];
        }

        $result = $this->updateStock($stockItems);

        return [
            'success' => (int) $result['success'],
            'failed'  => (int) $result['failed'] + count($errors),
            'errors'  => array_merge($errors, $result['errors']),
            'method'  => 'stock-upload',
        ];
    }

    /**
     * Listing silme (DELETE)
     *
     * HB uç noktası hem HB SKU'sunu hem satıcı stok kodunu ister:
     * /listings/merchantid/{merchantId}/sku/{sku}/merchantsku/{merchantSku}
     */
    public function deleteListing(string $hbSku, string $merchantSku = ''): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_delete']
            ?? '/listings/merchantid/{merchantId}/sku/{sku}/merchantsku/{merchantSku}';

        $path = str_replace(
            ['{merchantId}', '{sku}', '{hbSku}', '{merchantSku}'],
            [$this->merchantId, rawurlencode($hbSku), rawurlencode($hbSku), rawurlencode($merchantSku !== '' ? $merchantSku : $hbSku)],
            $endpoint
        );

        $this->httpDelete($this->getBaseUrl('listing') . $path, $this->authHeaders());
        return true;
    }

    /**
     * Listing bilgilerini sorgula
     */
    public function fetchListings(int $offset = 0, int $limit = 100): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listings'] ?? '/listings/merchantid/{merchantId}';
        $url = $this->buildUrl($endpoint, 'listing');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN İÇE AKTARMA
    // ═══════════════════════════════════════════════════════

    public function importProducts(int $page = 0, int $size = 100): array
    {
        // HB'de satışta + tükenmiş TÜM ürünler LISTINGS endpoint'inde bulunur.
        // (catalog/all-products-of-merchant yalnız satıcının kendi kataloğunu = az ürün döndürür.)
        // Caller page/size verir; HB listings offset/limit ister.
        $offset = max(0, $page) * $size;
        $response = $this->fetchListings($offset, $size);

        $rawProducts = (isset($response['listings']) && is_array($response['listings']))
            ? $response['listings']
            : $this->extractHbItems($response);

        $totalElements = (int) ($response['totalCount']
            ?? $response['totalElements']
            ?? $response['totalNumberOfElements']
            ?? count($rawProducts));
        $totalPages = ($size > 0) ? max(1, (int) ceil($totalElements / $size)) : 1;

        // Listings yanıtı yalnız sku/fiyat/stok taşır; ad/barkod/görsel/kategori
        // satıcının katalog ürünlerinden (eşleşiyorsa) tamamlanır.
        $detailMap = $this->loadCatalogDetailMap();

        $products = [];
        foreach ($rawProducts as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // Detay eşleştir (listings → catalog): hbSku / merchantSku / productId
            foreach ([$raw['hepsiburadaSku'] ?? null, $raw['hbSku'] ?? null, $raw['merchantSku'] ?? null, $raw['productId'] ?? null] as $lookup) {
                $lookup = (string) ($lookup ?? '');
                if ($lookup !== '' && isset($detailMap[$lookup])) {
                    // Yalnız listings'te olmayan detay alanlarını ekle (fiyat/stok ezilmez)
                    $raw += $detailMap[$lookup];
                    break;
                }
            }

            // Ad boşsa SKU'yu fallback yap ki ürün listesinde boş kalmasın
            if ((string) ($raw['productName'] ?? $raw['name'] ?? $raw['UrunAdi'] ?? '') === '') {
                $raw['productName'] = (string) ($raw['merchantSku'] ?? $raw['hepsiburadaSku'] ?? $raw['hbSku'] ?? 'HB Ürün');
            }

            $products[] = $this->normalizeImportProduct($raw);
        }

        return [
            'products'      => $products,
            'totalPages'    => $totalPages,
            'totalElements' => $totalElements,
        ];
    }

    /**
     * Satıcı katalog ürünlerinden detay haritası (barkod/görsel/kategori/marka).
     * Listings endpoint'i bu alanları taşımaz; all-products-of-merchant tüm sayfaları
     * bir kez gezilir ve hbSku + merchantSku ile indekslenir. Eşleşen listing'ler
     * bu detayla tamamlanır. (HB paylaşımlı katalog ürünlerinde detay satıcı API'sinde
     * bulunmayabilir; o ürünler sku/fiyat/stok ile gelir.)
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadCatalogDetailMap(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // Süreç-içi static önbellek TEK istekte işe yarar; sihirbaz her ürün sayfasını
        // AYRI HTTP isteğinde çektiği için harita her seferinde sıfırdan kuruluyordu:
        // sayfa başına 50 ek API çağrısı (rate limit ile ~10 sn). Disk önbelleği bu
        // maliyeti koşum başına BİR kez ödetir.
        $diskCache = $this->catalogCachePath();
        if ($diskCache !== '' && is_file($diskCache) && (time() - (int) @filemtime($diskCache)) < self::CATALOG_CACHE_TTL) {
            $kayitli = json_decode((string) @file_get_contents($diskCache), true);
            if (is_array($kayitli)) {
                $cache = $kayitli;
                return $cache;
            }
        }

        $cache = [];
        $page = 0;
        $size = 100;
        $totalPages = 1;

        try {
            do {
                $resp  = $this->fetchProducts($page, $size);
                $items = $this->extractHbItems($resp);
                $totalPages = (int) ($resp['totalPages'] ?? 1);

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $detail = [];
                    foreach (['barcode', 'images', 'productName', 'brand', 'categoryId', 'categoryName', 'variantGroupId', 'description'] as $f) {
                        if (isset($item[$f]) && $item[$f] !== '' && $item[$f] !== []) {
                            $detail[$f] = $item[$f];
                        }
                    }
                    if ($detail === []) {
                        continue;
                    }
                    foreach ([$item['hbSku'] ?? null, $item['merchantSku'] ?? null] as $key) {
                        $key = (string) ($key ?? '');
                        if ($key !== '') {
                            $cache[$key] = $detail;
                        }
                    }
                }

                $page++;
            } while ($page < $totalPages && $page < 50);
        } catch (\Throwable $e) {
            // Detay zenginleştirme başarısız olsa bile listings importu sürmeli
            $this->logger->warning('HB katalog detay haritası yüklenemedi: ' . $e->getMessage(), 'Hepsiburada');
        }

        if ($cache !== [] && $diskCache !== '') {
            @file_put_contents($diskCache, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        return $cache;
    }

    /**
     * Katalog detay haritasının disk önbellek yolu (mağaza + merchant bazında).
     * Yol üretilemezse '' döner ve önbellek devre dışı kalır.
     */
    private function catalogCachePath(): string
    {
        if (!defined('BASE_PATH') || $this->merchantId === '') {
            return '';
        }

        $dizin = BASE_PATH . '/storage/cache';
        if (!is_dir($dizin)) {
            @mkdir($dizin, 0755, true);
        }
        if (!is_dir($dizin) || !is_writable($dizin)) {
            return '';
        }

        return $dizin . '/hb_catalog_' . substr(sha1($this->merchantId), 0, 16) . '.json';
    }

    /**
     * HB ürün verisini normalize et (Mağaza Bazlı Ürün Bilgisi Listeleme)
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeImportProduct(array $raw): array
    {
        // Görseller — HB Image1-Image5 formatı
        $images = [];
        for ($i = 1; $i <= 5; $i++) {
            $imgUrl = (string) ($raw["Image{$i}"] ?? $raw["image{$i}"] ?? '');
            if ($imgUrl !== '') {
                $images[] = [
                    'url'      => $imgUrl,
                    'is_cover' => $i === 1,
                    'sort'     => $i,
                ];
            }
        }
        // Alternatif: images array formatı
        if (empty($images) && !empty($raw['images'])) {
            foreach ($raw['images'] as $idx => $img) {
                $url = is_string($img) ? $img : (string) ($img['url'] ?? '');
                if ($url !== '') {
                    $images[] = [
                        'url'      => $url,
                        'is_cover' => $idx === 0,
                        'sort'     => $idx + 1,
                    ];
                }
            }
        }

        // Özellikler
        $attributes = [];
        if (!empty($raw['attributes']) && is_array($raw['attributes'])) {
            foreach ($raw['attributes'] as $attrKey => $attrVal) {
                if (is_array($attrVal)) {
                    $attributes[] = [
                        'name'  => (string) ($attrVal['name'] ?? $attrKey),
                        'value' => (string) ($attrVal['value'] ?? ''),
                    ];
                } else {
                    $attributes[] = [
                        'name'  => (string) $attrKey,
                        'value' => (string) $attrVal,
                    ];
                }
            }
        }

        // Marka — HB'de attribute olarak gelir
        $brandName = (string) ($raw['Marka'] ?? $raw['brand'] ?? $raw['brandName'] ?? '');
        if (empty($brandName)) {
            foreach ($attributes as $attr) {
                if (in_array(strtolower($attr['name']), ['marka', 'brand'], true)) {
                    $brandName = $attr['value'];
                    break;
                }
            }
        }

        $normalized = [
            'marketplace_sku'        => (string) ($raw['merchantSku'] ?? $raw['merchantsku'] ?? ''),
            'marketplace_product_id' => (string) ($raw['hbSku'] ?? $raw['hepsiburadaSku'] ?? ''),
            'barcode'                => (string) ($raw['Barcode'] ?? $raw['barcode'] ?? ''),
            'name'                   => (string) ($raw['UrunAdi'] ?? $raw['productName'] ?? $raw['name'] ?? ''),
            'category_name'          => (string) ($raw['categoryName'] ?? ''),
            'category_id'            => (string) ($raw['categoryId'] ?? ''),
            'brand_name'             => $brandName,
            'brand_id'               => '',
            'price'                  => (float) ($raw['price'] ?? 0),
            'list_price'             => (float) ($raw['listPrice'] ?? $raw['price'] ?? 0),
            'currency'               => 'TRY',
            'is_active'              => (isset($raw['isSuspended']) || isset($raw['isFrozen']))
                ? !(($raw['isSuspended'] ?? false) || ($raw['isFrozen'] ?? false)) // tükenmiş ama askıda değilse aktif kalsın
                : (bool) ($raw['isSalable'] ?? $raw['isActive'] ?? true),
            'group_id'               => (string) ($raw['variantGroupId'] ?? $raw['productCode'] ?? ''),
            'images'                 => $images,
            'attributes'             => $attributes,
            'raw'                    => $raw,
        ];

        if (array_key_exists('availableStock', $raw)) {
            $normalized['stock'] = (int) $raw['availableStock'];
        } elseif (array_key_exists('stock', $raw)) {
            $normalized['stock'] = (int) $raw['stock'];
        }

        return $normalized;
    }

    // ═══════════════════════════════════════════════════════
    // KATEGORİ / MARKA / ÖZELLİKLER
    // ═══════════════════════════════════════════════════════

    public function fetchCategories(int $parentId = 0): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['category_list']
            ?? '/product/api/categories/get-all-categories';
        $baseUrl = $this->getBaseUrl('catalog') . $endpoint;

        $allCategories = [];
        $page = 0;
        $size = 2000;
        $totalPages = null;
        $this->categoryFetchShort = false;
        $this->startPaging('hb.categories');

        do {
            $url = $baseUrl . '?' . http_build_query(['page' => $page, 'size' => $size]);
            $response = $this->httpGet($url, $this->authHeaders());

            $items = $response['data'] ?? [];
            if (empty($items)) {
                // İlk sayfa boş ya da bildirilen toplam sayfaya ulaşılmadan boş sayfa → liste EKSİK
                // (çağıran listede olmayan kategorileri satışa kapalı işaretlememeli).
                if ($totalPages === null || $page < $totalPages) {
                    $this->categoryFetchShort = true;
                }
                break;
            }

            foreach ($items as $item) {
                $allCategories[] = [
                    'id'   => (string) ($item['categoryId'] ?? ''),
                    'name' => $item['name'] ?? $item['displayName'] ?? '',
                    'parentId' => !empty($item['parentCategoryId']) ? (string) $item['parentCategoryId'] : null,
                    'leaf' => (bool) ($item['leaf'] ?? false),
                    'paths' => $item['paths'] ?? [],
                    'status' => $this->normalizeCategoryStatus($item['status'] ?? null),
                    'available' => $this->normalizeCategoryAvailable($item['available'] ?? null),
                ];
            }

            $totalPages = (int) ($response['totalPages'] ?? 1);
            $page++;
        } while ($page < $totalPages && $this->keepPaging('hb.categories', $page, $items));

        return $allCategories;
    }

    /**
     * Son fetchCategories() listenin TAMAMINI getirdi mi? Sayfalama bütçesi (süre/sayfa) dolup liste kısmi
     * kaldıysa false — çağıran listede olmayan kategorileri "satışa kapalı" işaretlememelidir.
     */
    public function lastCategoryFetchComplete(): bool
    {
        return !$this->categoryFetchShort && !$this->pagingStopped('hb.categories');
    }

    /** Son fetchCategories() toplam sayfaya ulaşmadan boş sayfa aldı mı (liste eksik). */
    private bool $categoryFetchShort = false;

    /**
     * HB kategori durumu: 'ACTIVE' | 'INACTIVE' | null (API alanı göndermediyse bilinmiyor)
     */
    private function normalizeCategoryStatus(mixed $status): ?string
    {
        if (!is_string($status) || trim($status) === '') {
            return null;
        }

        return strtoupper(trim($status));
    }

    /**
     * HB "available" (satışa açık) bayrağı: bool | null (alan yoksa bilinmiyor)
     */
    private function normalizeCategoryAvailable(mixed $available): ?bool
    {
        if (is_bool($available)) {
            return $available;
        }
        if (is_int($available) || (is_string($available) && in_array(strtolower($available), ['0', '1', 'true', 'false'], true))) {
            return in_array(strtolower((string) $available), ['1', 'true'], true);
        }

        return null;
    }

    public function fetchBrands(string $search = ''): array
    {
        // HB'de ayrı marka API'si yok — ürünlerden unique markaları çıkar
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['products_all']
            ?? '/product/api/products/all-products-of-merchant/{merchantId}';
        $baseUrl = $this->getBaseUrl('catalog') . str_replace('{merchantId}', $this->merchantId, $endpoint);

        $brands = [];
        $page = 0;
        $size = 500;

        // HB'de marka API'si yok; markalar ÜRÜN kataloğundan çıkarılıyor. Katalog
        // büyükse (ya da API sayfalamayı yok sayıyorsa) bu döngü binlerce isteğe
        // kadar sürer ve süreci kilitler — 2026-08-25'te 4.735 sayfa / 60 dakika.
        // Bu yüzden dar bir bütçe uygulanır; sonuç kısmi olabilir.
        $this->startPaging('hb.brands', 25, 30.0);

        do {
            $url = $baseUrl . '?' . http_build_query(['page' => $page, 'size' => $size]);

            try {
                $response = $this->httpGet($url, $this->authHeaders());
            } catch (\Throwable $e) {
                break;
            }

            $items = $response['data'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $brandName = (string) ($item['brand'] ?? $item['Marka'] ?? '');
                if ($brandName === '') {
                    // baseAttributes'ten çıkar
                    foreach ($item['baseAttributes'] ?? [] as $attr) {
                        if (in_array(strtolower($attr['name'] ?? ''), ['marka', 'brand'], true)) {
                            $brandName = (string) ($attr['value'] ?? '');
                            break;
                        }
                    }
                }
                if ($brandName !== '' && ($search === '' || stripos($brandName, $search) !== false)) {
                    $key = mb_strtolower($brandName);
                    if (!isset($brands[$key])) {
                        $brands[$key] = [
                            'id'   => md5('hb_brand_' . $key),
                            'name' => $brandName,
                        ];
                    }
                }
            }

            $totalPages = $response['totalPages'] ?? 1;
            $page++;
        } while ($page < $totalPages && $this->keepPaging('hb.brands', $page, $items));

        if ($this->pagingStopped('hb.brands')) {
            $this->logger->info(
                sprintf('HB marka listesi kısmi çıkarıldı: %d marka (%d sayfa tarandı).', count($brands), $page),
                'HepsiburadaService'
            );
        }

        return array_values($brands);
    }

    /** Kategori özellik / enum değer önbellek süresi (sn) — 7 gün */
    private const ATTRIBUTE_CACHE_TTL = 604800;

    /**
     * Zorla yenilemenin (refresh) en kısa aralığı (sn): önbellek bundan yeniyse "yenile" isteği
     * HB'ye gitmez. Art arda / dışarıdan tetiklenen GET ?refresh=1 istekleri ortak hız sınırını tüketmesin.
     */
    private const ATTRIBUTE_REFRESH_MIN_AGE = 600;

    /** Otomatik miktar eşleşmesinde kabul edilen en büyük göreli fark */
    private const QUANTITY_TOLERANCE = 0.15;

    /** @var array<string, mixed> İstek içi özellik önbelleği (dosya önbelleğinin önünde) */
    private array $attributeMemo = [];

    /** @var array<string, bool> Bu istekte zorla yenilenen önbellek anahtarları */
    private array $attributeRefreshed = [];

    /**
     * Ham kategori özellikleri: {baseAttributes[], attributes[], variantAttributes[]}
     *
     * @return array<string, mixed>
     */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['categories']
            ?? '/product/api/categories/{categoryId}/attributes';
        $url = str_replace('{categoryId}', rawurlencode($marketplaceCategoryId), $this->getBaseUrl('catalog') . $endpoint);

        $response = $this->httpGet($url, $this->authHeaders());

        return $response['data'] ?? $response ?? [];
    }

    /**
     * Enum özellik değerleri (tek sayfa). HB yolu TEKİL 'attribute' + version=4 ister;
     * '/attributes/{id}/values' 404 döner.
     *
     * @return array<int, array{value: string}>
     */
    public function fetchAttributeValues(string $categoryId, string $attributeId, int $page = 0, int $limit = 1000): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['attribute_values']
            ?? '/product/api/categories/{categoryId}/attribute/{attributeId}/values';
        $url = str_replace(
            ['{categoryId}', '{attributeId}'],
            [rawurlencode($categoryId), rawurlencode($attributeId)],
            $this->getBaseUrl('catalog') . $endpoint
        );
        $url .= '?' . http_build_query(['page' => $page, 'size' => $limit, 'version' => 4]);

        $response = $this->httpGet($url, $this->authHeaders());

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    /**
     * Kategori özellik şeması (önbellekli): base / attributes / variant grupları,
     * her biri {id, name, mandatory, type, multiValue}
     *
     * @return array{base: array<int, array<string, mixed>>, attributes: array<int, array<string, mixed>>, variant: array<int, array<string, mixed>>}
     */
    public function getCategoryAttributeSchema(string $categoryId, bool $refresh = false): array
    {
        $categoryId = trim($categoryId);

        return $this->cachedAttributeData('attrs_' . $categoryId, $refresh, function () use ($categoryId): array {
            $raw = $this->fetchCategoryAttributes($categoryId);
            $schema = ['base' => [], 'attributes' => [], 'variant' => []];
            foreach (['baseAttributes' => 'base', 'attributes' => 'attributes', 'variantAttributes' => 'variant'] as $source => $target) {
                foreach ((array) ($raw[$source] ?? []) as $attr) {
                    $id = is_array($attr) ? trim((string) ($attr['id'] ?? '')) : '';
                    if ($id === '') {
                        continue;
                    }
                    $schema[$target][] = [
                        'id'         => $id,
                        'name'       => trim((string) ($attr['name'] ?? $id)),
                        'mandatory'  => (bool) ($attr['mandatory'] ?? false),
                        'type'       => strtolower(trim((string) ($attr['type'] ?? 'string'))),
                        'multiValue' => (bool) ($attr['multiValue'] ?? false),
                    ];
                }
            }

            if ($schema['base'] === [] && $schema['attributes'] === [] && $schema['variant'] === []) {
                throw new RuntimeException("Hepsiburada kategori {$categoryId} için özellik listesi boş döndü");
            }

            return $schema;
        });
    }

    /**
     * Enum özelliğin tüm geçerli değerleri (önbellekli, sayfalı çekim)
     *
     * @return array<int, string>
     */
    public function getAttributeValueList(string $categoryId, string $attributeId, bool $refresh = false): array
    {
        $categoryId = trim($categoryId);
        $attributeId = trim($attributeId);

        return $this->cachedAttributeData('vals_' . $categoryId . '_' . $attributeId, $refresh, function () use ($categoryId, $attributeId): array {
            $values = [];
            $pageSize = 1000;
            for ($page = 0; $page < 20; $page++) {
                $rows = $this->fetchAttributeValues($categoryId, $attributeId, $page, $pageSize);
                foreach ($rows as $row) {
                    $value = is_array($row) ? trim((string) ($row['value'] ?? $row['name'] ?? '')) : trim((string) $row);
                    if ($value !== '') {
                        $values[$value] = true;
                    }
                }
                if (count($rows) < $pageSize) {
                    break;
                }
            }

            return array_keys($values);
        });
    }

    /**
     * Kategoriye özgü ZORUNLU özellikler (attributes + variantAttributes; baseAttributes ürün alanlarından
     * gelir). Enum özellikler geçerli değer listesiyle döner.
     *
     * @return array<int, array{id: string, name: string, type: string, group: string, mandatory: bool, multiValue: bool, values: array<int, string>}>
     */
    public function getRequiredCategoryAttributes(string $categoryId, bool $refresh = false): array
    {
        $schema = $this->getCategoryAttributeSchema($categoryId, $refresh);

        $required = [];
        foreach (['attributes', 'variant'] as $group) {
            foreach ($schema[$group] as $attr) {
                if (empty($attr['mandatory'])) {
                    continue;
                }
                $attr['group'] = $group;
                $attr['values'] = $attr['type'] === 'enum'
                    ? $this->getAttributeValueList($categoryId, (string) $attr['id'], $refresh)
                    : [];
                $required[] = $attr;
            }
        }

        return $required;
    }

    /**
     * Değeri geçerli listedeki yazımına çevir (birebir ya da büyük/küçük harf-Türkçe karakter farkı);
     * listede yoksa null.
     *
     * @param array<int, string> $values
     */
    public static function canonicalAttributeValue(string $value, array $values): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (in_array($value, $values, true)) {
            return $value;
        }

        $folded = self::foldText($value);
        foreach ($values as $candidate) {
            if (self::foldText((string) $candidate) === $folded) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Zorunlu özellik için ürün adından/ağırlığından otomatik değer öner — YALNIZ geçerli liste değeri döner.
     *   Türü (bal): karakovan → Karakovan Balı, çiçek → Çiçek Balı, kekik → Kekik Balı, yoksa tüm kelimeleri
     *               adda geçen tür; hiçbiri adda geçmiyorsa öneri YOK (varsayılan tür dayatılmaz)
     *   Miktar: addaki gramaj/kilo ya da ağırlıktan en yakın geçerli değer ('1,5 kg', '850 gr' …)
     *   İçerik Tipi: form kelimesi (kapsül, damla…) yoksa propolis → Damla, arı sütü → Sıvı, polen/perga → Toz
     *
     * @param array<string, mixed> $attribute {id, name, values[]}
     * @param array<string, mixed> $product   {name, weight (kg)|null}
     */
    public function suggestAttributeValue(array $attribute, array $product): ?string
    {
        $values = array_values(array_filter(
            array_map(static fn($v) => trim((string) $v), (array) ($attribute['values'] ?? [])),
            static fn(string $v) => $v !== ''
        ));
        if ($values === []) {
            return null;
        }

        $id = strtolower(trim((string) ($attribute['id'] ?? '')));
        $label = self::foldText((string) ($attribute['name'] ?? ''));
        $name = (string) ($product['name'] ?? '');

        if ($id === 'turu' || in_array($label, ['turu', 'tur'], true)) {
            return self::suggestHoneyType($values, $name);
        }
        if ($id === 'icerik_tipi' || $label === 'icerik tipi') {
            return self::suggestContentType($values, $name);
        }
        if ($id === '00001stc' || in_array($label, ['miktar', 'net miktar', 'gramaj', 'agirlik', 'hacim'], true)) {
            $target = self::parseQuantity($name, false) ?? self::quantityFromWeight($product['weight'] ?? null);

            return $target === null ? null : self::nearestQuantityValue($values, $target);
        }

        return null;
    }

    /**
     * @param array<int, string> $values
     */
    private static function suggestHoneyType(array $values, string $name): ?string
    {
        $words = array_flip(explode(' ', self::foldText($name)));

        foreach (['karakovan' => 'Karakovan Balı', 'cicek' => 'Çiçek Balı', 'kekik' => 'Kekik Balı'] as $word => $value) {
            if (isset($words[$word]) && in_array($value, $values, true)) {
                return $value;
            }
        }

        // Adda tüm kelimeleri geçen en özel tür (ör. 'Petek Bal', 'Arı Sütü', 'Krem Bal')
        $priority = ['ari', 'propolis', 'perga', 'polen', 'kestane', 'krem', 'suzme', 'petek'];
        $best = null;
        $bestKey = null;
        foreach ($values as $value) {
            $valueWords = array_values(array_diff(explode(' ', self::foldText($value)), ['bal', 'bali', 've']));
            if ($valueWords === []) {
                continue;
            }
            foreach ($valueWords as $word) {
                if (!isset($words[$word])) {
                    continue 2;
                }
            }
            $rank = count($priority);
            foreach ($valueWords as $word) {
                $index = array_search($word, $priority, true);
                if ($index !== false) {
                    $rank = min($rank, (int) $index);
                }
            }
            $key = [-count($valueWords), $rank];
            if ($bestKey === null || $key < $bestKey) {
                $best = $value;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * @param array<int, string> $values
     */
    private static function suggestContentType(array $values, string $name): ?string
    {
        $text = ' ' . self::foldText($name) . ' ';
        $rules = [
            // Adda açık form kelimesi varsa o
            ' kapsul' => 'Kapsül', ' tablet' => 'Tablet', ' softjel' => 'Softjel', ' sprey' => 'Sprey',
            ' surup' => 'Şurup', ' damla' => 'Damla', ' ampul' => 'Ampul', ' sase ' => 'Saşe',
            ' toz ' => 'Toz', ' sivi ' => 'Sıvı',
            // Arı ürünü türüne göre makul varsayılan
            ' propolis' => 'Damla', ' ari sutu' => 'Sıvı', ' polen' => 'Toz', ' perga' => 'Toz', ' ari ekmegi' => 'Toz',
        ];
        foreach ($rules as $needle => $value) {
            if (str_contains($text, $needle) && in_array($value, $values, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Metindeki miktarı taban birime çevir (kütle: gram, hacim: ml).
     * $anchored = true: metnin tamamı miktar olmalı (enum değerleri için).
     *
     * @return array{base: float, dim: string, unit: string}|null
     */
    private static function parseQuantity(string $text, bool $anchored): ?array
    {
        $text = self::foldText($text, true);
        $pattern = '(\d+(?:[.,]\d+)?)\s*(kilogram|kilo|kg|gram|gr|g|mg|litre|lt|l|ml|cl)';
        $regex = $anchored ? '/^' . $pattern . '$/' : '/(?<![\d.,])' . $pattern . '\b/';
        if (preg_match($regex, $text, $m) !== 1) {
            return null;
        }

        $units = [
            'kilogram' => ['mass', 1000.0, 'kg'], 'kilo' => ['mass', 1000.0, 'kg'], 'kg' => ['mass', 1000.0, 'kg'],
            'gram' => ['mass', 1.0, 'g'], 'gr' => ['mass', 1.0, 'g'], 'g' => ['mass', 1.0, 'g'],
            'mg' => ['mass', 0.001, 'mg'],
            'litre' => ['volume', 1000.0, 'l'], 'lt' => ['volume', 1000.0, 'l'], 'l' => ['volume', 1000.0, 'l'],
            'ml' => ['volume', 1.0, 'ml'], 'cl' => ['volume', 10.0, 'ml'],
        ];
        [$dim, $factor, $unit] = $units[$m[2]];
        $amount = (float) str_replace(',', '.', $m[1]);
        if ($amount <= 0) {
            return null;
        }

        return ['base' => $amount * $factor, 'dim' => $dim, 'unit' => $unit];
    }

    /**
     * @return array{base: float, dim: string, unit: string}|null
     */
    private static function quantityFromWeight(mixed $weightKg): ?array
    {
        $kg = is_numeric($weightKg) ? (float) $weightKg : 0.0;
        if ($kg <= 0) {
            return null;
        }

        return ['base' => $kg * 1000, 'dim' => 'mass', 'unit' => $kg >= 1 ? 'kg' : 'g'];
    }

    /**
     * Geçerli değerlerden hedefe en yakın miktar (aynı boyut, ±%15). Eşit yakınlıkta
     * addaki birimle aynı yazım tercih edilir ('1,5 kg' > '1500 g', '850 gr' > '850 g').
     *
     * @param array<int, string> $values
     * @param array{base: float, dim: string, unit: string} $target
     */
    private static function nearestQuantityValue(array $values, array $target): ?string
    {
        $best = null;
        $bestKey = null;
        foreach ($values as $value) {
            // Aralık ('1000 - 1200 g') ve çoklu paket ('3 x 850 gr') değerleri tekil ürüne uymaz
            if (preg_match('/\d\s*-\s*\d|\bx\b/iu', $value) === 1) {
                continue;
            }
            $quantity = self::parseQuantity($value, true);
            if ($quantity === null || $quantity['dim'] !== $target['dim']) {
                continue;
            }

            $diff = abs($quantity['base'] - $target['base']) / max($target['base'], 1e-9);
            if ($diff > self::QUANTITY_TOLERANCE) {
                continue;
            }

            $folded = self::foldText($value, true);
            $style = ($quantity['unit'] === $target['unit'] ? 0 : 2)
                + (str_contains($folded, 'kilo') ? 1 : 0)
                + ($quantity['unit'] === 'g' && !str_contains($folded, 'gr') ? 1 : 0);
            $key = [$diff < 1e-9 ? 0 : 1, round($diff, 9), $style];
            if ($bestKey === null || $key < $bestKey) {
                $best = $value;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Türkçe karakterleri sadeleştirip küçük harfe çevir; noktalama boşluk olur
     * ($keepNumberMarks: sayılardaki virgül/nokta korunur).
     */
    public static function foldText(string $text, bool $keepNumberMarks = false): string
    {
        $text = strtr($text, [
            'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i', 'I' => 'i',
            'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u',
            'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i', 'û' => 'u', 'Û' => 'u',
        ]);
        $text = strtolower($text);
        $text = (string) preg_replace($keepNumberMarks ? '/[^a-z0-9.,]+/' : '/[^a-z0-9]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Önbellekten oku; yoksa/eskiyse çek ve yaz. Çekim başarısızsa eski önbellek kullanılır.
     */
    private function cachedAttributeData(string $key, bool $refresh, callable $fetch): mixed
    {
        $forceFetch = $refresh && empty($this->attributeRefreshed[$key]);
        if ($forceFetch) {
            $cachePath = $this->attributeCachePath($key);
            clearstatcache(true, $cachePath);
            $mtime = @filemtime($cachePath);
            if ($mtime !== false && (time() - $mtime) < self::ATTRIBUTE_REFRESH_MIN_AGE) {
                $forceFetch = false; // yakın zamanda yenilendi → önbellek yeterli
            }
        }
        if (!$forceFetch) {
            $cached = $this->readAttributeCache($key, false);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $data = $fetch();
        } catch (\Throwable $e) {
            $stale = $this->readAttributeCache($key, true);
            if ($stale !== null) {
                $this->logger->warning('HB özellik önbelleği yenilenemedi, eski kayıt kullanıldı: ' . $e->getMessage(), 'Hepsiburada');
                return $stale;
            }
            throw $e;
        }

        $this->attributeRefreshed[$key] = true;
        $this->writeAttributeCache($key, $data);

        return $data;
    }

    private function attributeCachePath(string $key): string
    {
        return BASE_PATH . '/storage/cache/hepsiburada/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) . '.json';
    }

    private function readAttributeCache(string $key, bool $allowStale): mixed
    {
        if (array_key_exists($key, $this->attributeMemo)) {
            return $this->attributeMemo[$key];
        }

        $path = $this->attributeCachePath($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !array_key_exists('data', $raw)) {
            return null;
        }
        if (!$allowStale && (time() - (int) ($raw['fetched_at'] ?? 0)) > self::ATTRIBUTE_CACHE_TTL) {
            return null;
        }

        return $this->attributeMemo[$key] = $raw['data'];
    }

    private function writeAttributeCache(string $key, mixed $data): void
    {
        $this->attributeMemo[$key] = $data;

        $path = $this->attributeCachePath($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents(
            $path,
            (string) json_encode(['fetched_at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    // ═══════════════════════════════════════════════════════
    // İADE (TALEP) İŞLEMLERİ
    // ═══════════════════════════════════════════════════════

    public function fetchReturns(array $filters = []): array
    {
        $this->loadCredentials();

        $allClaims = [];
        $limit = (int) ($this->config['api']['page_size'] ?? 100);
        $statuses = !empty($filters['status'])
            ? [(string) $filters['status']]
            : [''];

        foreach ($statuses as $status) {
            $offset = 0;
            $this->startPaging('hb.claims.' . $status);
            do {
                if ($status !== '') {
                $statusEndpoint = $this->config['endpoints']['claims_by_status']
                    ?? '/claims/merchantid/{merchantId}/status/{status}';
                $url = str_replace('{status}', $status, $this->buildUrl($statusEndpoint, 'claim'));
            } else {
                $endpoint = $this->config['endpoints']['claims'] ?? '/claims/merchantid/{merchantId}';
                $url = $this->buildUrl($endpoint, 'claim');
            }

            $queryParams = ['offset' => $offset, 'limit' => $limit];

            // Tarih filtreleri
            if (!empty($filters['begin_date'])) {
                $queryParams['begindate'] = $this->formatHbDateParam($filters['begin_date']);
            }
            if (!empty($filters['end_date'])) {
                $queryParams['enddate'] = $this->formatHbDateParam($filters['end_date']);
            }

            $url .= '?' . http_build_query($queryParams);

            $response = $this->httpGet($url, $this->authHeaders());

            $claims = $this->extractHbItems($response);
            if (empty($claims)) {
                break;
            }

            $allClaims = array_merge($allClaims, $claims);
            $offset += $limit;

            } while (count($claims) === $limit
                && $this->keepPaging('hb.claims.' . $status, (int) ($offset / max(1, $limit)), $claims));
        }

        return $this->deduplicateHbClaims($allClaims);
    }

    private function formatHbDateParam(mixed $value): string
    {
        $raw = (string) $value;
        $ts = strtotime($raw);

        return $ts !== false ? date('Y-m-d', $ts) : $raw;
    }

    private function deduplicateHbClaims(array $claims): array
    {
        $deduped = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $key = (string) ($claim['claimNumber'] ?? $claim['claimId'] ?? $claim['id'] ?? md5(json_encode($claim)));
            $deduped[$key] = $claim;
        }

        return array_values($deduped);
    }

    public function approveReturn(string $returnId): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['claim_accept'] ?? '/claims/number/{claimNumber}/accept';
        $url = str_replace('{claimNumber}', $returnId, $this->getBaseUrl('claim') . $endpoint);

        $this->httpPost($url, null, $this->authHeaders());
        return true;
    }

    public function rejectReturn(string $returnId, string $reason = ''): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['claim_reject'] ?? '/claims/number/{claimNumber}/reject';
        $url = str_replace('{claimNumber}', $returnId, $this->getBaseUrl('claim') . $endpoint);

        $body = [];
        if ($reason !== '') {
            $body['ClaimRejectionReason'] = $reason;
        }

        $this->httpPost($url, !empty($body) ? $body : null, $this->authHeaders());
        return true;
    }

    // ═══════════════════════════════════════════════════════
    // MUHASEBE
    // ═══════════════════════════════════════════════════════

    /**
     * Muhasebe kayıtlarını al
     */
    public function fetchTransactions(array $filters = []): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['transactions']
            ?? '/transactions/merchantid/{merchantId}';
        $url = $this->buildUrl($endpoint, 'finance');

        $queryParams = [];
        // HB finans API'si begindate/enddate yerine timespan (gün) bekliyor
        if (!empty($filters['begin_date']) && !empty($filters['end_date'])) {
            $startTs = strtotime($filters['begin_date']);
            $endTs   = strtotime($filters['end_date']);
            if ($startTs !== false && $endTs !== false) {
                $days = max(1, (int) ceil(($endTs - $startTs) / 86400));
                $queryParams['timespan'] = $days;
            }
        } elseif (!empty($filters['timespan'])) {
            $queryParams['timespan'] = (int) $filters['timespan'];
        }
        $queryParams['offset'] = $filters['offset'] ?? 0;
        $queryParams['limit']  = $filters['limit'] ?? 100;

        $url .= '?' . http_build_query($queryParams);

        try {
            return $this->httpGet($url, $this->authHeaders());
        } catch (\Throwable $e) {
            $this->logger->warning(
                "HB finans/transactions API erişilemiyor: {$e->getMessage()}",
                'HepsiburadaService'
            );
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════
    // SATICIYA SOR (api-asktoseller-merchant)
    // ═══════════════════════════════════════════════════════

    /**
     * Satıcıya Sor soru durumları. Liste ucu `status` olmadan BOŞ döner; durum verilmezse hepsi sırayla çekilir.
     */
    public const QUESTION_STATUSES = ['WaitingForAnswer', 'Answered', 'Rejected', 'AutoClosed'];

    /** HB soru durumu → questions.status (tablo: waiting | answered | rejected). */
    private const QUESTION_STATUS_MAP = [
        'WaitingForAnswer' => 'waiting',
        'Answered'         => 'answered',
        'Rejected'         => 'rejected',
        'AutoClosed'       => 'rejected',
    ];

    private const QUESTION_TIMEZONE = 'Europe/Istanbul';
    private const QUESTION_IMAGE_SIZE = '500';

    /** @var array<string, list<array<string, mixed>>> offset/limit ile dilimleyen eski çağıranlar için istek içi tam liste */
    private array $questionListMemo = [];

    /**
     * Soru listesini çeker (GET /api/v1.0/issues, sayfa 1 tabanlı, totalPageCount'a kadar).
     *
     * Ağ/HTTP hatası YUTULMAZ (RuntimeException) — senkron özeti "0 soru, başarılı" yerine hatayı göstersin.
     * Aynı soru iki sayfada/durumda görünürse issueNumber ile tekilleştirilir.
     *
     * $filters: status (WaitingForAnswer|Answered|Rejected|AutoClosed ya da 1-4; boşsa tüm durumlar),
     *           size (sayfa boyu 1-100), max_pages (durum başına üst sınır),
     *           offset/limit (eski çağıranlar: tam liste dilimlenerek döner).
     *
     * @return list<array<string, mixed>> Ham HB soru nesneleri (issueNumber, conversations, product, ...)
     */
    public function fetchQuestions(array $filters = []): array
    {
        $this->loadCredentials();

        $status   = (string) ($filters['status'] ?? '');
        $statuses = $status !== '' ? [$status] : self::QUESTION_STATUSES;
        $size     = max(1, min(100, (int) ($filters['size'] ?? 100)));
        $maxPages = max(1, (int) ($filters['max_pages'] ?? 50));

        if (!isset($filters['offset']) && !isset($filters['limit'])) {
            return $this->fetchQuestionList($statuses, $size, $maxPages);
        }

        // Eski çağıranlar offset/limit döngüsüyle ilerler: tam liste döngünün ilk çağrısında (offset 0) bir kez
        // çekilir, devam dilimleri aynı listeden verilir (her dilimde tüm durumlar yeniden istenmez).
        // Anahtar mağazanın Merchant ID'sini içerir → çok kiracılı döngüde başka mağazanın listesi dönmez.
        $offset  = max(0, (int) ($filters['offset'] ?? 0));
        $limit   = max(1, (int) ($filters['limit'] ?? 50));
        $memoKey = implode('|', [$this->merchantId, implode(',', $statuses), $size, $maxPages]);
        if ($offset === 0 || !isset($this->questionListMemo[$memoKey])) {
            $this->questionListMemo[$memoKey] = $this->fetchQuestionList($statuses, $size, $maxPages);
        }

        return array_slice($this->questionListMemo[$memoKey], $offset, $limit);
    }

    /**
     * Verilen durumların tüm sayfalarını çeker; issueNumber ile tekilleştirilmiş düz liste döner.
     *
     * @param array<int, string> $statuses
     * @return list<array<string, mixed>>
     */
    private function fetchQuestionList(array $statuses, int $size, int $maxPages): array
    {
        $baseUrl = $this->questionUrl('questions', '/api/v1.0/issues');

        $questions = [];
        foreach ($statuses as $statusFilter) {
            $page = 1;
            do {
                $query    = http_build_query(['page' => $page, 'size' => $size, 'status' => $statusFilter]);
                $response = $this->httpGet($baseUrl . '?' . $query, $this->questionHeaders());
                $items    = $response['data'] ?? null;
                if (!is_array($items)) {
                    throw new RuntimeException("Hepsiburada soru listesi beklenmeyen yanıt döndürdü (durum: {$statusFilter}, sayfa: {$page})");
                }

                foreach ($items as $item) {
                    $issueNumber = is_array($item) ? (string) ($item['issueNumber'] ?? '') : '';
                    if ($issueNumber !== '') {
                        $questions[$issueNumber] = $item;
                    }
                }

                $totalPages = (int) ($response['totalPageCount'] ?? 0);
                $page++;
            } while ($items !== [] && $page <= $totalPages && $page <= $maxPages);
        }

        return array_values($questions);
    }

    /**
     * Soruyu cevapla (POST /api/v1.0/issues/{issueNumber}/answer). $questionId = HB issueNumber.
     */
    public function answerQuestion(string $questionId, string $answer): bool
    {
        $this->loadCredentials();

        $url = $this->questionUrl(
            'question_answer',
            '/api/v1.0/issues/{questionId}/answer',
            $this->hbIssueNumber($questionId)
        );

        $this->httpPost($url, ['answer' => $answer, 'files' => []], $this->questionHeaders());
        return true;
    }

    /**
     * Ham HB soru nesnesini questions tablosu satırına çevirir (marketplace_id hariç).
     *
     * Soru = ilk Customer mesajı (yoksa lastContent), cevap = son Merchant mesajı.
     * AutoClosed (süresi dolan) soru cevaplanamaz → cevap yoksa 'rejected'; orijinal durum extra_data_json.hb_status.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null issueNumber yoksa null
     */
    public function normalizeQuestion(array $raw): ?array
    {
        $issueNumber = (string) ($raw['issueNumber'] ?? '');
        if ($issueNumber === '' || $issueNumber === '0') {
            return null;
        }

        $question = null;
        $answer   = null;
        foreach ((array) ($raw['conversations'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $from = (string) ($message['from'] ?? '');
            if ($from === 'Customer' && $question === null) {
                $question = $message;
            } elseif ($from === 'Merchant') {
                $answer = $message;
            }
        }

        $answerText = trim((string) ($answer['content'] ?? ''));
        $hbStatus   = (string) ($raw['status'] ?? '');
        $status     = $answerText !== ''
            ? 'answered'
            : (self::QUESTION_STATUS_MAP[$hbStatus] ?? 'waiting');

        $product      = is_array($raw['product'] ?? null) ? $raw['product'] : [];
        $imageUrl     = trim((string) ($product['imageUrl'] ?? ''));
        $orderNumber  = $raw['orderNumber'] ?? null;
        $questionText = trim((string) ($question['content'] ?? $raw['lastContent'] ?? ''));

        $extra = array_filter([
            'hb_status'    => $hbStatus,
            'subject'      => $raw['subject']['description'] ?? null,
            'stock_code'   => $product['stockCode'] ?? null,
            'expire_date'  => $this->hbQuestionDate($raw['expireDate'] ?? null),
            'order_number' => $orderNumber,
            'line_item_id' => $raw['lineItemId'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        return [
            'marketplace_question_id' => $issueNumber,
            'marketplace_product_id'  => (string) ($product['sku'] ?? ''),
            'product_name'            => $product['name'] ?? null,
            'product_image_url'       => $imageUrl !== '' ? str_replace('{size}', self::QUESTION_IMAGE_SIZE, $imageUrl) : null,
            'question_text'           => $questionText,
            'question_date'           => $this->hbQuestionDate($question['createdAt'] ?? $raw['createdAt'] ?? null)
                ?? date('Y-m-d H:i:s'),
            'customer_name'           => null,
            'status'                  => $status,
            'answer_text'             => $answerText !== '' ? $answerText : null,
            'answer_date'             => $answerText !== '' ? $this->hbQuestionDate($answer['createdAt'] ?? null) : null,
            'is_public'               => empty($orderNumber) ? 1 : 0,
            'extra_data_json'         => json_encode($extra, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Satıcıya Sor başlıkları: Basic auth + User-Agent + ZORUNLU merchantId başlığı.
     *
     * @return array<int, string>
     */
    private function questionHeaders(): array
    {
        return array_merge($this->authHeaders(), ["merchantId: {$this->merchantId}"]);
    }

    /**
     * Satıcıya Sor uç URL'i ({questionId} = issueNumber).
     */
    private function questionUrl(string $endpointKey, string $default, string $issueNumber = ''): string
    {
        $url = $this->buildUrl($this->config['endpoints'][$endpointKey] ?? $default, 'question');
        return str_replace('{questionId}', rawurlencode($issueNumber), $url);
    }

    /**
     * HB issueNumber yalnız rakamdır; eski/yerel kimliklerle (ör. demo "Q123") canlı çağrı yapılmaz.
     */
    private function hbIssueNumber(string $questionId): string
    {
        $issueNumber = trim($questionId);
        if (!ctype_digit($issueNumber)) {
            throw new RuntimeException("Geçersiz Hepsiburada soru numarası: {$questionId}");
        }
        return $issueNumber;
    }

    /**
     * HB ISO-8601 (UTC "Z") tarihini Europe/Istanbul "Y-m-d H:i:s" yapar; geçersizse null.
     */
    private function hbQuestionDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(self::QUESTION_TIMEZONE))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════
    // LİSTELEME — ENVANTER GÜNCELLEME (Fiyat+Stok+Dispatch birlikte)
    // ═══════════════════════════════════════════════════════

    /**
     * Listing envanter güncelleme (Fiyat + Stok + DispatchTime birlikte)
     *
     * HB listeleme entegrasyonundaki ana güncelleme endpoint'i.
     * Toplu güncelleme - tek seferde max 4000 SKU.
     *
     * @param array<int, array{
     *   HepsiburadaSku?: string,
     *   MerchantSku?: string,
     *   Price: string,
     *   AvailableStock: int,
     *   DispatchTime: int,
     *   CargoCompany1?: string,
     *   CargoCompany2?: string,
     *   CargoCompany3?: string,
     *   MaximumPurchasableQuantity?: int,
     *   ShippingProfileName?: string
     * }> $listings
     * @return array{uploadId: string|null, success: bool}
     */
    public function updateInventory(array $listings): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_inventory']
            ?? '/listings/merchantid/{merchantId}/inventory-uploads';
        $url = $this->getBaseUrl('listing') . str_replace('{merchantId}', $this->merchantId, $endpoint);

        // Listeleme uçlarının tamamı JSON bekler (price/stock/inventory).
        $response = $this->httpPostRaw(
            $url,
            (string) json_encode(array_values($listings), JSON_UNESCAPED_UNICODE),
            $this->listingJsonHeaders()
        );
        $uploadId = $this->extractUploadId($response);

        return [
            'uploadId' => $uploadId,
            'success'  => $uploadId !== null,
        ];
    }

    /**
     * Listing envanter güncelleme durumu sorgulama
     */
    public function checkInventoryStatus(string $uploadId): array
    {
        $this->loadCredentials();

        return $this->checkUploadStatus('inventory', $uploadId);
    }

    /**
     * Listing stok güncelleme durumu sorgulama
     */
    public function checkStockStatus(string $uploadId): array
    {
        $this->loadCredentials();

        return $this->checkUploadStatus('stock', $uploadId);
    }

    /**
     * Listing fiyat güncelleme durumu sorgulama
     */
    public function checkPriceStatus(string $uploadId): array
    {
        $this->loadCredentials();

        return $this->checkUploadStatus('price', $uploadId);
    }

    /**
     * Listing teslimat güncelleme (DispatchTime)
     *
     * @param array<int, array{HepsiburadaSku?: string, MerchantSku?: string, DispatchTime: int}> $listings
     */
    public function updateDelivery(array $listings): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_delivery'] ?? '/listings/delivery-uploads';
        $url = $this->getBaseUrl('listing') . $endpoint;

        $response = $this->httpPost($url, ['listings' => $listings], $this->authHeaders());

        return [
            'uploadId' => $response['id'] ?? null,
            'success'  => !empty($response['id']),
        ];
    }

    /**
     * Listing teslimat güncelleme durumu sorgulama
     */
    public function checkDeliveryStatus(string $uploadId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_delivery_check']
            ?? '/listings/delivery-uploads/{uploadId}';
        $url = str_replace('{uploadId}', $uploadId, $this->getBaseUrl('listing') . $endpoint);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Listing ek bilgi güncelleme (CustomizableProperties vb.)
     *
     * @param array<int, array{HepsiburadaSku?: string, MerchantSku?: string}> $listings
     */
    public function updateExtraInfo(array $listings): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_extra_info'] ?? '/listings/extra-info-uploads';
        $url = $this->getBaseUrl('listing') . $endpoint;

        $response = $this->httpPost($url, ['listings' => $listings], $this->authHeaders());

        return [
            'uploadId' => $response['id'] ?? null,
            'success'  => !empty($response['id']),
        ];
    }

    /**
     * Listing ek bilgi güncelleme durumu sorgulama
     */
    public function checkExtraInfoStatus(string $uploadId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_extra_info_check']
            ?? '/listings/extra-info-uploads/{uploadId}';
        $url = str_replace('{uploadId}', $uploadId, $this->getBaseUrl('listing') . $endpoint);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Tekil listing güncelleme (tek SKU için fiyat/stok)
     */
    public function updateSingleListing(string $hbSku, float $price, int $stock, int $dispatchTime = 3): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_single_update'] ?? '/listings/tekil-update';
        $url = $this->getBaseUrl('listing') . $endpoint;

        $payload = [
            'listings' => [[
                'HepsiburadaSku'  => $hbSku,
                'MerchantId'      => $this->merchantId,
                // JSON gövdede fiyat SAYIDIR; virgüllü metin (118,97) geçersizdir.
                'Price'           => round($price, 2),
                'AvailableStock'  => $stock,
                'DispatchTime'    => $dispatchTime,
            ]],
        ];

        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * Toplu kilit kaldırma (fiyat kilidi sonrası)
     *
     * @param array<int, string> $hbSkus
     */
    public function unlockListings(array $hbSkus): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_unlock'] ?? '/listings/unlock';
        $url = $this->getBaseUrl('listing') . $endpoint;

        $payload = ['listings' => array_map(fn(string $sku) => [
            'HepsiburadaSku' => $sku,
            'MerchantId'     => $this->merchantId,
        ], $hbSkus)];

        return $this->httpPost($url, $payload, $this->authHeaders());
    }

    /**
     * Buybox sıralama sorgulama
     *
     * @param array<int, string> $hbSkus Maksimum 10 SKU
     */
    public function fetchBuyboxInfo(array $hbSkus): array
    {
        $this->loadCredentials();

        $skuParam = implode(',', array_slice($hbSkus, 0, 10));
        $endpoint = $this->config['endpoints']['listing_buybox'] ?? '/listings/buybox';
        $url = $this->getBaseUrl('listing') . $endpoint;
        $url .= '?' . http_build_query(['sku' => $skuParam]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Komisyon bilgisi sorgulama
     *
     * @param array<int, string> $hbSkus Maksimum 50 SKU
     */
    public function fetchCommissions(array $hbSkus): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_commissions'] ?? '/listings/commissions';
        $url = $this->getBaseUrl('listing') . $endpoint;

        $skuParam = implode(',', array_slice($hbSkus, 0, 50));
        $url .= '?' . http_build_query(['merchantId' => $this->merchantId, 'sku' => $skuParam]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Listing bilgisini SKU ile sorgula
     */
    public function fetchListingBySku(string $sku, string $type = 'merchant'): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['listing_by_sku'] ?? '/listings/merchantid/{merchantId}';
        $url = $this->buildUrl($endpoint, 'listing');

        $param = $type === 'hb' ? 'hepsiburadaSku' : 'merchantSku';
        $url .= '?' . http_build_query([$param => $sku]);

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // PAKET — EK İŞLEMLER
    // ═══════════════════════════════════════════════════════

    /**
     * Faturası yüklenmemiş paketleri listele
     */
    public function fetchMissingInvoicePackages(int $offset = 0, int $limit = 50): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages_missing_invoice']
            ?? '/packages/merchantid/{merchantId}/missing-invoice';
        $url = $this->buildUrl($endpoint, 'order');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Kargoya verilmiş paketleri listele
     */
    public function fetchShippedPackages(int $offset = 0, int $limit = 50, array $filters = []): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages_shipped']
            ?? '/packages/merchantid/{merchantId}/shipped';
        $url = $this->buildUrl($endpoint, 'order');

        $queryParams = ['offset' => $offset, 'limit' => $limit];
        if (!empty($filters['begin_date'])) {
            $queryParams['begindate'] = $this->formatHbDateParam($filters['begin_date']);
        }
        if (!empty($filters['end_date'])) {
            $queryParams['enddate'] = $this->formatHbDateParam($filters['end_date']);
        }
        $url .= '?' . http_build_query($queryParams);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Teslim edilmiş paketleri listele
     */
    public function fetchDeliveredPackages(int $offset = 0, int $limit = 50, array $filters = []): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages_delivered']
            ?? '/packages/merchantid/{merchantId}/delivered';
        $url = $this->buildUrl($endpoint, 'order');

        $queryParams = ['offset' => $offset, 'limit' => $limit];
        if (!empty($filters['begin_date'])) {
            $queryParams['begindate'] = $this->formatHbDateParam($filters['begin_date']);
        }
        if (!empty($filters['end_date'])) {
            $queryParams['enddate'] = $this->formatHbDateParam($filters['end_date']);
        }
        $url .= '?' . http_build_query($queryParams);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Teslim edilemedi paketleri listele
     */
    public function fetchUndeliveredPackages(int $offset = 0, int $limit = 50): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages_undelivered']
            ?? '/packages/merchantid/{merchantId}/undelivered';
        $url = $this->buildUrl($endpoint, 'order');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Bozulan (unpack) paketleri listele
     */
    public function fetchUnpackedPackages(int $offset = 0, int $limit = 50): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packages_unpacked']
            ?? '/packages/merchantid/{merchantId}/status/unpacked';
        $url = $this->buildUrl($endpoint, 'order');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Paket kargo bilgisi detayı
     */
    public function fetchPackageCargoInfo(string $packageNumber): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_cargo_info']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Paket için değiştirilebilir kargo firmalarını listele
     */
    public function fetchChangeableCargoCompanies(string $packageNumber): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_changeable_cargo']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/changablecargocompanies';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Paket kargo firmasını değiştir
     */
    public function changePackageCargo(string $packageNumber, string $cargoCompany): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_change_cargo']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/changecargocompany';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));

        $this->httpPut($url, ['cargoCompany' => $cargoCompany], $this->authHeaders());
        return true;
    }

    /**
     * Paket bölme
     *
     * @param array<int, array{id: string, quantity: int}> $lineItems
     */
    public function splitPackage(string $packageNumber, array $lineItems): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_split']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/split';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));

        return $this->httpPost($url, ['lineItemRequests' => $lineItems], $this->authHeaders());
    }

    /**
     * Paket depo bilgisi güncelleme
     */
    public function updatePackageWarehouse(string $packageNumber, string $warehouseId): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['package_warehouse']
            ?? '/packages/merchantid/{merchantId}/packagenumber/{packageNumber}/warehouse';
        $url = str_replace('{packageNumber}', $packageNumber, $this->buildUrl($endpoint, 'order'));

        $this->httpPut($url, ['warehouseId' => $warehouseId], $this->authHeaders());
        return true;
    }

    // ═══════════════════════════════════════════════════════
    // SATIR İŞLEMLERİ — EK
    // ═══════════════════════════════════════════════════════

    /**
     * Paketlenecek sipariş kaleminin kargo firmasını değiştir
     */
    public function changeLineCargo(string $lineItemId, string $cargoCompany): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['line_cargo_change']
            ?? '/lineitems/merchantid/{merchantId}/orderlineid/{id}/cargocompany';
        $url = str_replace('{id}', $lineItemId, $this->buildUrl($endpoint, 'order'));

        $this->httpPut($url, ['cargoCompany' => $cargoCompany], $this->authHeaders());
        return true;
    }

    /**
     * Paketlenecek sipariş kaleminin değiştirilebilir kargo firmalarını listele
     */
    public function fetchLineChangeableCargoCompanies(string $lineItemId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['line_changeable_cargo']
            ?? '/delivery/changeablecargocompanies/merchantid/{merchantId}/orderlineid/{id}';
        $url = str_replace('{id}', $lineItemId, $this->buildUrl($endpoint, 'order'));

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Sipariş kalemi işçilik maliyeti güncelleme
     */
    public function updateLineLaborCost(string $lineItemId, float $laborCost): bool
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['line_labor_cost']
            ?? '/lineitems/merchantid/{merchantId}/orderlineid/{id}/laborcost';
        $url = str_replace('{id}', $lineItemId, $this->buildUrl($endpoint, 'order'));

        $this->httpPut($url, ['laborCost' => $laborCost], $this->authHeaders());
        return true;
    }

    /**
     * Aynı pakete konulabilecek kalemleri listele
     */
    public function fetchPackageableItems(string $lineItemId): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['packageable_items']
            ?? '/lineitems/merchantid/{merchantId}/packageablewith/lineitemid/{lineItemId}';
        $url = str_replace('{lineItemId}', $lineItemId, $this->buildUrl($endpoint, 'order'));

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // PROFİL / KARGO FİRMASI / TESLİMAT PROFİLİ
    // ═══════════════════════════════════════════════════════

    /**
     * Kargo firmalarını listele
     */
    public function fetchCargoFirms(): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['cargo_firms'] ?? '/cargofirms/{merchantId}';
        $url = $this->buildUrl($endpoint, 'order');

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Teslimat profili oluştur
     *
     * @param array{
     *   name: string,
     *   cargoCompany1: string,
     *   cargoCompany2?: string,
     *   cargoCompany3?: string,
     *   city?: string,
     *   district?: string
     * } $profileData
     */
    public function createProfile(array $profileData): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['profile_create'] ?? '/profile/createByMerchantId';
        $url = $this->getBaseUrl('order') . $endpoint;

        $profileData['merchantId'] = $this->merchantId;

        return $this->httpPost($url, $profileData, $this->authHeaders());
    }

    /**
     * Teslimat profili güncelle
     */
    public function updateProfile(array $profileData): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['profile_update'] ?? '/profile/updateByMerchantId';
        $url = $this->getBaseUrl('order') . $endpoint;

        $profileData['merchantId'] = $this->merchantId;

        return $this->httpPut($url, $profileData, $this->authHeaders());
    }

    /**
     * Teslimat profillerini listele
     */
    public function fetchProfiles(): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['profile_list'] ?? '/profiles/{merchantId}';
        $url = $this->buildUrl($endpoint, 'order');

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * ShippingProfileName listele (listing bazlı)
     */
    public function fetchShippingProfiles(): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['shipping_profiles'] ?? '/listings/shipping-profiles';
        $url = $this->getBaseUrl('listing') . $endpoint;
        $url .= '?' . http_build_query(['merchantId' => $this->merchantId]);

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // SATICIYA SOR — EK İŞLEMLER
    // ═══════════════════════════════════════════════════════

    /**
     * Soru detayı çek (GET /api/v1.0/issues/{issueNumber})
     */
    public function fetchQuestionDetail(string $questionId): array
    {
        $this->loadCredentials();

        $url = $this->questionUrl(
            'question_detail',
            '/api/v1.0/issues/{questionId}',
            $this->hbIssueNumber($questionId)
        );

        return $this->httpGet($url, $this->questionHeaders());
    }

    /**
     * Soru sorun bildirme — Satıcıya Sor API'sinde doğrulanmış bir uç yok; config'te
     * 'question_report' tanımlanmadıkça canlı çağrı yapılmaz.
     */
    public function reportQuestion(string $questionId, string $reason = ''): bool
    {
        $endpoint = (string) ($this->config['endpoints']['question_report'] ?? '');
        if ($endpoint === '') {
            throw new RuntimeException('Hepsiburada soru bildirme ucu tanımlı değil; bildirimi Hepsiburada satıcı panelinden yapın.');
        }

        $this->loadCredentials();

        $url  = $this->questionUrl('question_report', $endpoint, $this->hbIssueNumber($questionId));
        $body = $reason !== '' ? ['reason' => $reason] : null;

        $this->httpPost($url, $body, $this->questionHeaders());
        return true;
    }

    /**
     * Durum bazlı soru sayıları (GET /api/v1.0/issues/count)
     *
     * @return array{waitingForAnswer?: int, answered?: int, reported?: int, autoClosedInLastWeek?: int}
     */
    public function fetchQuestionCounts(): array
    {
        $this->loadCredentials();

        return $this->httpGet(
            $this->questionUrl('question_counts', '/api/v1.0/issues/count'),
            $this->questionHeaders()
        );
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞ — EK SORGULAMA
    // ═══════════════════════════════════════════════════════

    /**
     * İptal edilen siparişleri listele
     */
    public function fetchCancelledOrders(int $offset = 0, int $limit = 50): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['orders_cancelled']
            ?? '/orders/merchantid/{merchantId}/cancelled';
        $url = $this->buildUrl($endpoint, 'order');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Ödemesi beklenen siparişleri listele
     */
    public function fetchPaymentAwaitingOrders(int $offset = 0, int $limit = 50): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['orders_payment_awaiting']
            ?? '/orders/merchantid/{merchantId}/paymentawaiting';
        $url = $this->buildUrl($endpoint, 'order');
        $url .= '?' . http_build_query(['offset' => $offset, 'limit' => $limit]);

        return $this->httpGet($url, $this->authHeaders());
    }

    /**
     * Sipariş detayı al (orderNumber ile)
     */
    public function fetchOrderDetail(string $orderNumber): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['order_detail']
            ?? '/orders/merchantid/{merchantId}/ordernumber/{orderNumber}';
        $url = str_replace('{orderNumber}', $orderNumber, $this->buildUrl($endpoint, 'order'));

        return $this->httpGet($url, $this->authHeaders());
    }

    // ═══════════════════════════════════════════════════════
    // ÜRÜN GÜNCELLEME (Ticket sistemi)
    // ═══════════════════════════════════════════════════════

    /**
     * Ürün güncelleme isteği gönder (Katalog'da onaylanmış ürünler için)
     *
     * @param array<int, array<string, mixed>> $products
     * @return array{trackingId: string|null, success: bool, message: string}
     */
    public function updateProduct(array $products): array
    {
        $this->loadCredentials();

        $endpoint = $this->config['endpoints']['product_update'] ?? '/product/api/products/ticket-import';
        $url = $this->getBaseUrl('catalog') . $endpoint;

        // JSON dosyası form-data olarak yüklenir (uploadProduct ile aynı mantık)
        $tmpDir = BASE_PATH . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpFile = $tmpDir . '/hb_product_update_' . uniqid() . '.json';

        try {
            $jsonContent = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($jsonContent === false) {
                throw new RuntimeException('Ürün güncelleme verileri JSON\'a dönüştürülemedi');
            }
            file_put_contents($tmpFile, $jsonContent);

            $response = $this->httpPostMultipart($url, $tmpFile, 'file', $this->authHeaders());

            $trackingId = $response['data']['trackingId'] ?? $response['trackingId'] ?? null;

            return [
                'trackingId' => $trackingId,
                'success'    => !empty($trackingId),
                'message'    => $response['message'] ?? 'Ürün güncelleme isteği gönderildi',
            ];
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ═══════════════════════════════════════════════════════
    // YARDIMCI METHODLAR
    // ═══════════════════════════════════════════════════════

    /**
     * HB fiyat objesinden tutarı çıkar ({Amount: x, Currency: y} veya direkt float)
     */
    private function extractAmount(mixed $value): float
    {
        if (is_array($value)) {
            return (float) ($value['amount'] ?? $value['Amount'] ?? 0);
        }
        return (float) $value;
    }

    /**
     * HB tarih formatını Y-m-d H:i:s'e çevir
     */
    private function parseHbDate(mixed $dateValue): string
    {
        if (empty($dateValue)) {
            return date('Y-m-d H:i:s');
        }

        $dateStr = (string) $dateValue;

        // Epoch ms
        if (is_numeric($dateStr) && (float) $dateStr > 10000000000) {
            return date('Y-m-d H:i:s', (int) ((float) $dateStr / 1000));
        }

        // ISO 8601
        if (str_contains($dateStr, 'T')) {
            return str_replace('T', ' ', substr($dateStr, 0, 19));
        }

        // Y-m-d H:i:s
        if (str_contains($dateStr, '-') && strlen($dateStr) >= 10) {
            return substr($dateStr, 0, 19);
        }

        return date('Y-m-d H:i:s');
    }
}
 
