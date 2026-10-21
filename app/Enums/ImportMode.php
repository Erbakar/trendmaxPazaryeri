<?php
/**
 * OzyConnect Panel — ImportMode Enum
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

namespace App\Enums;

enum ImportMode: string
{
    case FULL       = 'full';
    case DELTA      = 'delta';
    case SELECTIVE  = 'selective';
    case MATCH_ONLY = 'match_only';

    public function label(): string
    {
        return match ($this) {
            self::FULL       => 'Tam İçe Aktarma',
            self::DELTA      => 'Fark Güncelleme (Delta)',
            self::SELECTIVE  => 'Seçimli İçe Aktarma',
            self::MATCH_ONLY => 'Sadece Eşleştir',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FULL       => 'Pazaryerindeki tüm ürünleri çek — eşleşmeyenler yeni ürün olarak oluşturulur',
            self::DELTA      => 'Sadece son senkronizasyondan bu yana değişen ürünleri güncelle',
            self::SELECTIVE  => 'Önce listele, istediğini seç, sonra aktar',
            self::MATCH_ONLY => 'Sadece mevcut ürünlerle eşleştir — eşleşmeyenler atlanır, yeni ürün oluşturulmaz',
        };
    }

    /**
     * Bu modda eşleşmeyen ürünler için yeni master product oluşturulabilir mi?
     */
    public function allowsProductCreation(): bool
    {
        return match ($this) {
            self::FULL, self::SELECTIVE => true,
            self::DELTA, self::MATCH_ONLY => false,
        };
    }
}
