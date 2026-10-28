<?php
/**
 * OzyConnect Panel — XmlProfileSettings
 *
 * XML profiline ÖZEL içe aktarım ayarları (kâr kuralı, barkod ön eki, kritik stok,
 * varsayılan marka/kategori). Ayarlar `store_settings` anahtar-değer tablosunda
 * `xmlprofil_{profilId}_{alan}` deseniyle tutulur; şema DEĞİŞMEZ, müşterinin
 * migration çalıştırması gerekmez (migration yalnız CLI'dan çalışır, müşteri FTP kullanır).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-08-28
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\StoreSetting;

final class XmlProfileSettings
{
    private const PREFIX = 'xmlprofil_';

    /** Kâr tipi seçenekleri — bkz. MarginCalculator */
    public const PROFIT_TYPES = ['yuzde', 'tutar'];

    public function __construct(private readonly StoreSetting $settingModel) {}

    /**
     * Profilin ayarlarını tiplenmiş olarak getir. Kayıt yoksa varsayılanlar döner.
     *
     * @return array{kar_tipi: string, kar_degeri: float, barkod_onek: string,
     *               kargo_tutari: ?float, kritik_stok: int, varsayilan_marka_id: int, varsayilan_kategori_id: int}
     */
    public function forProfile(int $profileId): array
    {
        $oku = fn(string $alan): ?string => $this->settingModel->getValue($this->key($profileId, $alan));

        $tip = (string) ($oku('kar_tipi') ?? 'yuzde');

        $kargo = $oku('kargo_tutari');

        return [
            'tedarikci_bilgisi'      => $oku('tedarikci_bilgisi') === 'toptanarabul' ? 'toptanarabul' : '',
            'kar_tipi'               => in_array($tip, self::PROFIT_TYPES, true) ? $tip : 'yuzde',
            'kar_degeri'             => (float) ($oku('kar_degeri') ?? 0),
            'kargo_tutari'           => $kargo === null || $kargo === '' ? null : max(0.0, round((float) $kargo, 2)),
            'barkod_onek'            => trim((string) ($oku('barkod_onek') ?? '')),
            'kritik_stok'            => (int) ($oku('kritik_stok') ?? 0),
            'varsayilan_marka_id'    => (int) ($oku('varsayilan_marka_id') ?? 0),
            'varsayilan_kategori_id' => (int) ($oku('varsayilan_kategori_id') ?? 0),
        ];
    }

    /**
     * Formdan gelen ham değerleri doğrulayıp kaydet.
     *
     * @param array<string, mixed> $ham
     */
    public function save(int $profileId, array $ham): void
    {
        $tip = (string) ($ham['kar_tipi'] ?? 'yuzde');

        $kargo = trim(str_replace(',', '.', (string) ($ham['kargo_tutari'] ?? '')));
        if ($kargo !== '' && (!is_numeric($kargo) || !is_finite((float) $kargo) || (float) $kargo < 0)) {
            throw new \InvalidArgumentException('Kargo tutarı sıfır veya pozitif bir sayı olmalıdır.');
        }

        $this->settingModel->setMany([
            $this->key($profileId, 'tedarikci_bilgisi')     => ($ham['tedarikci_bilgisi'] ?? $this->forProfile($profileId)['tedarikci_bilgisi']) === 'toptanarabul' ? 'toptanarabul' : '',
            $this->key($profileId, 'kargo_tutari')          => $kargo === '' ? null : (string) round((float) $kargo, 2),
            $this->key($profileId, 'kar_tipi')               => in_array($tip, self::PROFIT_TYPES, true) ? $tip : 'yuzde',
            $this->key($profileId, 'kar_degeri')             => (string) max(0, (float) str_replace(',', '.', (string) ($ham['kar_degeri'] ?? 0))),
            $this->key($profileId, 'barkod_onek')            => mb_substr(trim((string) ($ham['barkod_onek'] ?? '')), 0, 20),
            $this->key($profileId, 'kritik_stok')            => (string) max(0, (int) ($ham['kritik_stok'] ?? 0)),
            $this->key($profileId, 'varsayilan_marka_id')    => (string) max(0, (int) ($ham['varsayilan_marka_id'] ?? 0)),
            $this->key($profileId, 'varsayilan_kategori_id') => (string) max(0, (int) ($ham['varsayilan_kategori_id'] ?? 0)),
        ]);
    }

    /**
     * Profil silindiğinde ayarları da temizle — yetim anahtar bırakma.
     */
    public function deleteFor(int $profileId): void
    {
        $this->settingModel->setMany([
            $this->key($profileId, 'tedarikci_bilgisi')     => null,
            $this->key($profileId, 'kargo_tutari')          => null,
            $this->key($profileId, 'kar_tipi')               => null,
            $this->key($profileId, 'kar_degeri')             => null,
            $this->key($profileId, 'barkod_onek')            => null,
            $this->key($profileId, 'kritik_stok')            => null,
            $this->key($profileId, 'varsayilan_marka_id')    => null,
            $this->key($profileId, 'varsayilan_kategori_id') => null,
        ]);
    }

    private function key(int $profileId, string $alan): string
    {
        return self::PREFIX . $profileId . '_' . $alan;
    }
}
