<?php
/**
 * OzyConnect Panel — String Helper
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

namespace App\Helpers;

final class StringHelper
{
    /**
     * Türkçe karakterleri slug'a çevir
     */
    public static function slugify(string $text): string
    {
        $turkishMap = [
            'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
        ];

        $text = strtr($text, $turkishMap);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? '';
        $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text;
    }

    /**
     * XSS güvenli çıktı
     */
    public static function escape(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Kısa metin (truncate)
     */
    public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
    }

    /**
     * UUID v4 oluştur
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Güvenli rastgele token oluştur
     */
    public static function randomToken(int $length = 64): string
    {
        return bin2hex(random_bytes(intdiv($length, 2)));
    }

    /**
     * Dosya boyutunu okunaklı formata çevir
     */
    public static function fileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . $units[$index];
    }

    /**
     * Türkçe karakter duyarsız metin normalizasyonu
     * Eşleştirme (matching) işlemleri için kullanılır.
     * slugify() ile karıştırılmamalı — slugify URL için, normalize karşılaştırma için.
     *
     * Dönüşümler:
     *   - Türkçe karakterler → ASCII karşılıkları (ç→c, ğ→g, ı→i, İ→i, ö→o, ş→s, ü→u)
     *   - Tüm harfler küçük harfe çevrilir
     *   - Baştaki/sondaki boşluklar temizlenir
     *   - Ardışık boşluklar tek boşluğa indirilir
     */
    public static function normalize(string $text): string
    {
        $turkishMap = [
            'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u',
        ];

        $text = strtr($text, $turkishMap);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Telefon numarası formatla
     */
    public static function formatPhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '-';
        }

        // Sadece rakamları al
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Türkiye formatı: 05XX XXX XX XX
        if (strlen($digits) === 10) {
            return sprintf('0%s %s %s %s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 2),
                substr($digits, 8, 2)
            );
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return sprintf('%s %s %s %s',
                substr($digits, 0, 4),
                substr($digits, 4, 3),
                substr($digits, 7, 2),
                substr($digits, 9, 2)
            );
        }

        return $phone;
    }
}
