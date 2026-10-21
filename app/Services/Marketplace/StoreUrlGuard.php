<?php
/**
 * OzyConnect Panel — Mağaza adresi güvenlik denetimi (SSRF koruması)
 *
 * Kendi mağazası olan platformlarda (WooCommerce) adres mağaza kullanıcısı tarafından girilir ve panel
 * sunucusu bu adrese kimlikli istek atar. Çok kiracılı canlı panelde bir mağaza, adresi iç ağa
 * (127.0.0.1, 10.x, 169.254.169.254 bulut üst verisi, ondalık/onaltılık IP yazımları …) yönelterek
 * sunucuya iç ağ isteği attırabilirdi.
 *
 * Kural: panel internette yayındaysa (APP_URL herkese açık bir adres) hedef ad çözülür; çözülen IP'lerin
 * HEPSİ herkese açık olmalıdır, aksi halde bağlantı reddedilir. Kabul edilen IP, DNS yeniden bağlamaya
 * karşı cURL'e sabitlenir (CURLOPT_RESOLVE). Panel yerelde çalışıyorsa (APP_URL localhost/özel ağ —
 * geliştirme/test kurulumu) yerel mağazaya izin verilir; .env OUTBOUND_ALLOW_PRIVATE=true da izin verir.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Marketplace;

final class StoreUrlGuard
{
    /** Ad olarak her zaman yerel sayılanlar (IP çözümlemesinden önce). */
    private const LOCAL_HOSTS = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.test'];

    /**
     * Adresi denetler. ok=false ise error kullanıcıya gösterilebilir. resolve: cURL'e sabitlenecek
     * "host:port:ip" (yerel izinli modda boş).
     *
     * @return array{ok: bool, error: string, resolve: list<string>}
     */
    public static function check(string $url): array
    {
        $parts = parse_url(trim($url));
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return self::fail('Mağaza adresi geçersiz: http:// ya da https:// ile başlayan bir site adresi girin.');
        }

        if (self::privateTargetsAllowed()) {
            return ['ok' => true, 'error' => '', 'resolve' => []];
        }

        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return self::fail(self::privateMessage());
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::fail(self::privateMessage());
            }
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                return self::fail(self::privateMessage());
            }
            return ['ok' => true, 'error' => '', 'resolve' => []];
        }

        // Ad çözümleme: tüm IPv4 adresleri herkese açık olmalı (istekler IPv4 ile yapılır)
        $ips = @gethostbynamel($host);
        if ($ips === false || $ips === []) {
            return self::fail("Mağaza adresinin sunucusu bulunamadı ({$host}). Adresi kontrol edin.");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return self::fail(self::privateMessage());
            }
        }

        return ['ok' => true, 'error' => '', 'resolve' => ["{$host}:{$port}:{$ips[0]}"]];
    }

    /**
     * Yerel/özel ağ hedeflerine izin var mı: .env OUTBOUND_ALLOW_PRIVATE=true ya da panelin kendi adresi
     * (APP_URL) internete açık değil (yerel geliştirme/test kurulumu).
     */
    public static function privateTargetsAllowed(): bool
    {
        $override = strtolower(trim((string) self::env('OUTBOUND_ALLOW_PRIVATE')));
        if (in_array($override, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $appUrl = trim((string) self::env('APP_URL'));
        if ($appUrl === '') {
            return false;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $appUrl) !== 1) {
            $appUrl = 'http://' . $appUrl;
        }

        return !self::isPublicUrl($appUrl);
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function isPublicUrl(string $url): bool
    {
        if (function_exists('app_url_is_public')) {
            return (bool) app_url_is_public($url);
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]'));
        if ($host === '' || in_array($host, self::LOCAL_HOSTS, true)) {
            return false;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        return str_contains($host, '.');
    }

    private static function env(string $key): string
    {
        if (function_exists('env')) {
            $value = env($key, '');
            return is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : '';
        }
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }

    private static function privateMessage(): string
    {
        return 'Mağaza adresi yerel ya da iç ağdaki bir sunucuya çıkıyor. Canlı panel yalnız internetteki '
            . 'mağaza adreslerine bağlanır; WooCommerce sitenizin herkese açık adresini girin.';
    }

    /** @return array{ok: bool, error: string, resolve: list<string>} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'resolve' => []];
    }
}
