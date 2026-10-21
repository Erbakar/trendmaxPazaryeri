<?php
/**
 * OzyConnect Panel — Date Helper
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

final class DateHelper
{
    /**
     * Tarih formatla (Türkçe görüntüleme)
     */
    public static function format(?string $dateStr, string $format = 'd.m.Y H:i'): string
    {
        if ($dateStr === null || $dateStr === '') {
            return '-';
        }

        $dt = new \DateTimeImmutable($dateStr);
        return $dt->format($format);
    }

    /**
     * Görece zaman (5 dk önce, 2 saat önce vb.)
     */
    public static function timeAgo(?string $dateStr): string
    {
        if ($dateStr === null || $dateStr === '') {
            return '-';
        }

        $now  = new \DateTimeImmutable();
        $date = new \DateTimeImmutable($dateStr);
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 0) {
            return 'gelecekte';
        }

        return match (true) {
            $diff < 60       => 'az önce',
            $diff < 3600     => (int)($diff / 60) . ' dk önce',
            $diff < 86400    => (int)($diff / 3600) . ' saat önce',
            $diff < 604800   => (int)($diff / 86400) . ' gün önce',
            $diff < 2592000  => (int)($diff / 604800) . ' hafta önce',
            $diff < 31536000 => (int)($diff / 2592000) . ' ay önce',
            default          => (int)($diff / 31536000) . ' yıl önce',
        };
    }

    /**
     * Unix timestamp (ms) → DateTime string
     */
    public static function fromUnixMs(int $timestampMs): string
    {
        $dt = \DateTimeImmutable::createFromFormat('U', (string) intdiv($timestampMs, 1000));
        return $dt !== false ? $dt->format('Y-m-d H:i:s') : '';
    }

    /**
     * DateTime → Unix timestamp (ms) (Trendyol API için)
     */
    public static function toUnixMs(string $dateStr): int
    {
        $dt = new \DateTimeImmutable($dateStr);
        return $dt->getTimestamp() * 1000;
    }

    /**
     * Bugünün tarihi (Y-m-d)
     */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Şu anki tarih/saat (Y-m-d H:i:s)
     */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
