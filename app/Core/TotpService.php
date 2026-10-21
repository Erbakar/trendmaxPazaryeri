<?php
/**
 * OzyConnect Panel — TotpService (RFC 6238 TOTP / RFC 4648 Base32)
 *
 * Admin 2FA için zaman-tabanlı tek-kullanımlık parola (HMAC-SHA1, 30s, 6 hane).
 * Saat kayması toleransı için ±1 pencere. Harici bağımlılık yok.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Core;

final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Yeni base32 secret üret (varsayılan 160 bit). */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** Belirli bir zaman için 6 haneli kod. */
    public function codeAt(string $secretBase32, int $timestamp): string
    {
        $key = $this->base32Decode($secretBase32);
        $counter = intdiv($timestamp, self::PERIOD);
        // 8-byte big-endian counter
        $bin = "\0\0\0\0" . pack('N', $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);
        $code = $part % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Kodu doğrula (±$window pencere). $timestamp null ise şimdi. */
    public function verify(string $secretBase32, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $ts = $timestamp ?? time();
        for ($w = -$window; $w <= $window; $w++) {
            if (hash_equals($this->codeAt($secretBase32, $ts + $w * self::PERIOD), $code)) {
                return true;
            }
        }
        return false;
    }

    /** otpauth:// URI (authenticator app QR için). */
    public function provisioningUri(string $secretBase32, string $label, string $issuer = 'Entegratör Yazılımı'): string
    {
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&period=%d&digits=%d',
            rawurlencode($issuer . ':' . $label),
            $secretBase32,
            rawurlencode($issuer),
            self::PERIOD,
            self::DIGITS
        );
    }

    private function base32Encode(string $data): string
    {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($data) as $ch) {
            $value = ($value << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::B32[($value >> ($bits - 5)) & 0x1F];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($value << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $out = '';
        $bits = 0;
        $value = 0;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos(self::B32, $b32[$i]);
            if ($idx === false) {
                continue;
            }
            $value = ($value << 5) | $idx;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }
        return $out;
    }
}
