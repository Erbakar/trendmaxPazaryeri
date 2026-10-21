<?php
/**
 * OzyConnect — MarketingTrackingService (sunucu-taraflı dönüşüm API'leri)
 *
 * Public kurumsal sitedeki dönüşüm olaylarını (Lead/CompleteRegistration/Purchase/Contact) sunucu
 * tarafında reklam platformlarına gönderir — client pixel'in iOS/adblock kaybını telafi eder.
 * Platformlar (Setting group='marketing', toggle ile): Meta Conversions API, Google Analytics 4
 * Measurement Protocol, TikTok Events API. Tümü en-iyi-çaba: hata fırlatmaz, loglar.
 *
 * PII (e-posta/telefon) SHA-256 ile hashlenir. Client ile aynı event_id kullanılırsa platform
 * çift-sayımı önler (dedup).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Setting;

final class MarketingTrackingService
{
    private const META_API_VERSION = 'v19.0';

    /** Dahili olay adı → [meta, ga4, tiktok] platform karşılıkları. */
    private const EVENT_MAP = [
        'PageView'             => ['PageView',             'page_view',     'Pageview'],
        'Lead'                 => ['Lead',                 'generate_lead', 'SubmitForm'],
        'Contact'              => ['Contact',              'contact',       'Contact'],
        'CompleteRegistration' => ['CompleteRegistration', 'sign_up',       'CompleteRegistration'],
        'Purchase'             => ['Purchase',             'purchase',      'CompletePayment'],
    ];

    public function __construct(
        private readonly Setting $settings,
        private readonly Logger $logger,
    ) {}

    private function cfg(string $key, string $default = ''): string
    {
        return trim((string) $this->settings->get($key, $default));
    }

    private function enabled(string $platformKey): bool
    {
        return $this->cfg($platformKey, '0') === '1';
    }

    /**
     * Bir dönüşüm olayını tüm etkin platformlara sunucu-taraflı gönder (en-iyi-çaba).
     *
     * @param string $event   Dahili olay (Lead/Contact/CompleteRegistration/Purchase/PageView)
     * @param array<string,mixed> $user   ['email'=>, 'phone'=>]
     * @param array<string,mixed> $custom ['value'=>, 'currency'=>, 'content_name'=>, ...]
     * @param string $eventId  Client ile ortak dedup id (boşsa üretilir)
     * @param string $sourceUrl Olayın geçtiği URL
     */
    public function track(string $event, array $user = [], array $custom = [], string $eventId = '', string $sourceUrl = ''): void
    {
        if (!isset(self::EVENT_MAP[$event])) {
            return;
        }
        if ($eventId === '') {
            $eventId = bin2hex(random_bytes(8));
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        [$metaEvt, $ga4Evt, $ttEvt] = self::EVENT_MAP[$event];

        try { if ($this->enabled('meta_enabled'))    { $this->sendMeta($metaEvt, $user, $custom, $eventId, $sourceUrl, $ip, $ua); } }
        catch (\Throwable $e) { $this->logger->warning('Meta CAPI hatası: ' . $e->getMessage(), 'Marketing'); }

        try { if ($this->enabled('google_enabled'))  { $this->sendGa4($ga4Evt, $custom, $eventId); } }
        catch (\Throwable $e) { $this->logger->warning('GA4 MP hatası: ' . $e->getMessage(), 'Marketing'); }

        try { if ($this->enabled('tiktok_enabled'))  { $this->sendTiktok($ttEvt, $user, $custom, $eventId, $sourceUrl, $ip, $ua); } }
        catch (\Throwable $e) { $this->logger->warning('TikTok Events hatası: ' . $e->getMessage(), 'Marketing'); }
    }

    // ─── Meta Conversions API ───
    private function sendMeta(string $eventName, array $user, array $custom, string $eventId, string $url, string $ip, string $ua): void
    {
        $pixel = $this->cfg('meta_pixel_id');
        $token = $this->cfg('meta_capi_token');
        if ($pixel === '' || $token === '') { return; }

        $ud = ['client_ip_address' => $ip, 'client_user_agent' => $ua];
        if (!empty($user['email'])) { $ud['em'] = [$this->hashEmail((string) $user['email'])]; }
        if (!empty($user['phone'])) { $ud['ph'] = [$this->hashPhone((string) $user['phone'])]; }

        $payload = ['data' => [array_filter([
            'event_name'       => $eventName,
            'event_time'       => time(),
            'event_id'         => $eventId,
            'action_source'    => 'website',
            'event_source_url' => $url !== '' ? $url : null,
            'user_data'        => $ud,
            'custom_data'      => $custom ?: null,
        ], static fn($v) => $v !== null)]];
        $test = $this->cfg('meta_test_event_code');
        if ($test !== '') { $payload['test_event_code'] = $test; }

        $endpoint = 'https://graph.facebook.com/' . self::META_API_VERSION . '/' . rawurlencode($pixel) . '/events?access_token=' . rawurlencode($token);
        $this->post($endpoint, $payload);
    }

    // ─── Google Analytics 4 Measurement Protocol ───
    private function sendGa4(string $eventName, array $custom, string $eventId): void
    {
        $mid    = $this->cfg('google_analytics_id');
        $secret = $this->cfg('ga4_api_secret');
        if ($secret === '' || !preg_match('/^G-[A-Za-z0-9]+$/', $mid)) { return; } // MP yalnız GA4 (G-) destekler

        $params = $custom;
        $params['engagement_time_msec'] = 1;
        $params['event_id'] = $eventId;
        $payload = [
            'client_id' => $this->ga4ClientId(),
            'events'    => [['name' => $eventName, 'params' => $params]],
        ];
        $endpoint = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($mid) . '&api_secret=' . rawurlencode($secret);
        $this->post($endpoint, $payload);
    }

    // ─── TikTok Events API ───
    private function sendTiktok(string $eventName, array $user, array $custom, string $eventId, string $url, string $ip, string $ua): void
    {
        $pixel = $this->cfg('tiktok_pixel_id');
        $token = $this->cfg('tiktok_events_token');
        if ($pixel === '' || $token === '') { return; }

        $u = ['ip' => $ip, 'user_agent' => $ua];
        if (!empty($user['email'])) { $u['email'] = $this->hashEmail((string) $user['email']); }
        if (!empty($user['phone'])) { $u['phone'] = $this->hashPhone((string) $user['phone']); }

        $payload = ['event_source' => 'web', 'event_source_id' => $pixel, 'data' => [array_filter([
            'event'      => $eventName,
            'event_time' => time(),
            'event_id'   => $eventId,
            'user'       => $u,
            'page'       => $url !== '' ? ['url' => $url] : null,
            'properties' => $custom ?: null,
        ], static fn($v) => $v !== null)]];

        $this->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload, ['Access-Token: ' . $token]);
    }

    // ─── Yardımcılar ───
    private function hashEmail(string $v): string { return hash('sha256', strtolower(trim($v))); }
    private function hashPhone(string $v): string { $d = preg_replace('/\D/', '', $v) ?? ''; return $d === '' ? '' : hash('sha256', $d); }

    /** GA4 client_id: _ga çerezinden (GA1.1.X.Y → X.Y) ya da rastgele. */
    private function ga4ClientId(): string
    {
        $ga = (string) ($_COOKIE['_ga'] ?? '');
        if ($ga !== '' && preg_match('/GA\d\.\d\.(\d+\.\d+)/', $ga, $m)) {
            return $m[1];
        }
        return random_int(1000000000, 2147483647) . '.' . time();
    }

    /** @param array<string,mixed> $payload @param array<int,string> $headers */
    private function post(string $url, array $payload, array $headers = []): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            $this->logger->warning('Marketing API yanıtı: HTTP ' . $code . ' ' . $err . ' ' . substr((string) $resp, 0, 300), 'Marketing', ['url' => $url]);
        }
    }
}
