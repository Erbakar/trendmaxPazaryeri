<?php
/**
 * OzyConnect Panel — PayTrService (Faz 9: PayTR iFrame API)
 *
 * Token üretimi (get-token) + callback HMAC doğrulama. Merchant bilgileri admin → Ödeme Ayarları
 * (Setting group='payment'). Bilgiler eksikse isConfigured()=false → merchant'a kart seçeneği gösterilmez.
 * Referans HMAC deseni: yedekparca/paytrcallback.php.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

final class PayTrService
{
    private const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public function __construct(private readonly Setting $settings) {}

    public function merchantId(): string  { return trim((string) $this->settings->get('paytr_merchant_id', '')); }
    public function merchantKey(): string { return trim((string) $this->settings->get('paytr_merchant_key', '')); }
    public function merchantSalt(): string{ return trim((string) $this->settings->get('paytr_merchant_salt', '')); }
    public function testMode(): int       { return (string) $this->settings->get('paytr_test_mode', '1') === '1' ? 1 : 0; }

    public function isConfigured(): bool
    {
        return $this->merchantId() !== '' && $this->merchantKey() !== '' && $this->merchantSalt() !== '';
    }

    /**
     * PayTR iFrame token al.
     * @param array{merchant_oid:string,amount:float,email:string,user_ip:string,user_name:string,user_phone?:string,user_address?:string,ok_url:string,fail_url:string,basket_label:string} $a
     * @return array{ok:bool, token?:string, error?:string}
     */
    public function createToken(array $a): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'PayTR ödeme ayarları tanımlı değil. Lütfen yöneticiyle iletişime geçin.'];
        }
        $merchantId = $this->merchantId();
        $merchantKey = $this->merchantKey();
        $merchantSalt = $this->merchantSalt();
        $testMode = $this->testMode();

        $merchantOid   = preg_replace('/[^a-zA-Z0-9]/', '', (string) $a['merchant_oid']);
        $email         = (string) $a['email'];
        $paymentAmount = (int) round(((float) $a['amount']) * 100); // kuruş
        $userIp        = (string) $a['user_ip'];
        $currency      = 'TL';
        $noInstallment = 0;
        $maxInstallment = 0;
        $basket = base64_encode(json_encode([[ (string) $a['basket_label'], number_format((float) $a['amount'], 2, '.', ''), 1 ]], JSON_UNESCAPED_UNICODE));

        $hashStr = $merchantId . $userIp . $merchantOid . $email . $paymentAmount . $basket . $noInstallment . $maxInstallment . $currency . $testMode;
        $paytrToken = base64_encode(hash_hmac('sha256', $hashStr . $merchantSalt, $merchantKey, true));

        $post = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $merchantOid,
            'email' => $email,
            'payment_amount' => $paymentAmount,
            'paytr_token' => $paytrToken,
            'user_basket' => $basket,
            'debug_on' => $testMode === 1 ? 1 : 0,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr((string) ($a['user_name'] ?? 'Müşteri'), 0, 60),
            'user_address' => mb_substr((string) ($a['user_address'] ?? '-'), 0, 200),
            'user_phone' => mb_substr((string) ($a['user_phone'] ?? '-'), 0, 30),
            'merchant_ok_url' => (string) $a['ok_url'],
            'merchant_fail_url' => (string) $a['fail_url'],
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
        ];

        $resp = $this->httpPost(self::TOKEN_URL, $post);
        if ($resp === null) {
            return ['ok' => false, 'error' => 'PayTR sunucusuna ulaşılamadı.'];
        }
        $json = json_decode($resp, true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success' || empty($json['token'])) {
            return ['ok' => false, 'error' => 'PayTR token alınamadı: ' . (string) ($json['reason'] ?? 'bilinmeyen hata')];
        }
        return ['ok' => true, 'token' => (string) $json['token']];
    }

    /** Callback HMAC doğrula (yedekparca deseni). */
    public function verifyCallback(array $post): bool
    {
        $oid = (string) ($post['merchant_oid'] ?? '');
        $status = (string) ($post['status'] ?? '');
        $total = (string) ($post['total_amount'] ?? '');
        $hash = (string) ($post['hash'] ?? '');
        if ($oid === '' || $hash === '' || !$this->isConfigured()) {
            return false;
        }
        $calc = base64_encode(hash_hmac('sha256', $oid . $this->merchantSalt() . $status . $total, $this->merchantKey(), true));
        return hash_equals($calc, $hash);
    }

    private function httpPost(string $url, array $post): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $out = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return ($err === 0 && is_string($out)) ? $out : null;
    }
}
