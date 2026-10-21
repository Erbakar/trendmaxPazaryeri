<?php
/**
 * OzyConnect Panel — CryptoService (AES-256-CBC)
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

namespace App\Services;

use RuntimeException;

final class CryptoService
{
    private const CIPHER = 'aes-256-cbc';

    private readonly string $key;

    public function __construct()
    {
        $key = env('ENCRYPTION_KEY', '');
        if ($key === '' || $key === null) {
            throw new RuntimeException('ENCRYPTION_KEY ayarlanmamış. .env dosyasını kontrol edin.');
        }

        // Hex key'i binary'ye çevir (32 byte)
        $this->key = hex2bin($key) ?: throw new RuntimeException('ENCRYPTION_KEY geçersiz hex formatında.');
    }

    /**
     * Veriyi şifrele
     */
    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Şifreleme IV uzunluğu alınamadı.');
        }

        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Şifreleme başarısız: ' . openssl_error_string());
        }

        // HMAC ile bütünlük kontrolü
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $this->key, true);

        // IV + HMAC + CipherText → Base64
        return base64_encode($iv . $hmac . $ciphertext);
    }

    /**
     * Şifreli veriyi çöz
     */
    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            throw new RuntimeException('Şifre çözme başarısız: geçersiz base64.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Şifreleme IV uzunluğu alınamadı.');
        }

        $hmacLength = 32; // SHA-256

        if (strlen($data) < $ivLength + $hmacLength) {
            throw new RuntimeException('Şifre çözme başarısız: veri çok kısa.');
        }

        $iv         = substr($data, 0, $ivLength);
        $hmac       = substr($data, $ivLength, $hmacLength);
        $ciphertext = substr($data, $ivLength + $hmacLength);

        // HMAC doğrula
        $calcHmac = hash_hmac('sha256', $iv . $ciphertext, $this->key, true);
        if (!hash_equals($hmac, $calcHmac)) {
            throw new RuntimeException('Şifre çözme başarısız: HMAC doğrulama hatası (veri bozulmuş olabilir).');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            throw new RuntimeException('Şifre çözme başarısız: ' . openssl_error_string());
        }

        return $plaintext;
    }

    /**
     * Yeni şifreleme anahtarı oluştur (installer için)
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
