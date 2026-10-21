<?php
/**
 * OzyConnect Panel — XmlProfileExportService
 *
 * XML Profili dışa aktarımı: mağaza ürünlerini Ticimax V2 uyumlu XML olarak üretir.
 * Public URL: /xml-islemleri/xml-ver-x?profil={token}
 * Çıktı storage/xmlprofiles/export_{token}.xml dosyasında önbellekte tutulur;
 * profil periyodu (period_hours) kadar tazedir, süresi dolunca yeniden üretilir.
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2026-07-07
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Feed;

use App\Core\Database;
use App\Core\Logger;
use App\Models\ExchangeRate;
use App\Core\TenantContext;
use App\Models\XmlProfile;

final class XmlProfileExportService
{
    /** Bellek sabit kalsın diye ürünler PK aralığıyla parça parça okunur */
    private const CHUNK = 1000;

    private readonly string $exportDir;

    public function __construct(
        private readonly XmlProfile $profileModel,
        private readonly ExchangeRate $rateModel,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
    ) {
        $this->exportDir = BASE_PATH . '/storage/xmlprofiles';
    }

    /**
     * Export profilinin XML çıktısını üret ve dosyaya yaz.
     * Ürünler PK aralığıyla parça parça okunur ve çıktı doğrudan geçici dosyaya
     * akıtılır (tüm katalog belleğe alınmaz) — 100k+ üründe bile sabit bellek.
     *
     * @return int Ürün sayısı
     */
    public function generate(int $profileId): int
    {
        $profile = $this->profileModel->findOrFail($profileId);
        if (($profile['direction'] ?? '') !== 'export') {
            throw new \RuntimeException('Bu profil dışa aktarım profili değil.');
        }

        $storeId = (int) $profile['store_id'];
        $rates = $this->rateModel->getAllRates();

        if (!is_dir($this->exportDir)) {
            @mkdir($this->exportDir, 0755, true);
        }
        $finalFile = $this->exportDir . '/export_' . $profile['token'] . '.xml';
        $tmpFile   = $finalFile . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $fp = fopen($tmpFile, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Export dosyası oluşturulamadı.');
        }

        $count = 0;
        try {
            fwrite($fp, '<?xml version="1.0" encoding="UTF-8" ?>' . "\n<urunler>\n");

            $lastId = 0;
            while (true) {
                // Yalnız gereken kolonlar + PK aralıklı sayfalama (OFFSET yok)
                $rows = $this->db->fetchAll(
                    "SELECT p.id, p.sku, p.name, p.description, p.barcode, p.stock_qty,
                            p.base_price, p.cost_price, p.currency_code, p.currency_price,
                            p.currency_cost, p.tax_rate, p.desi, p.images_json, p.main_image,
                            b.name AS brand_name, c.full_path AS category_path
                     FROM products p
                     LEFT JOIN brands b ON b.id = p.brand_id
                     LEFT JOIN categories c ON c.id = p.category_id
                     WHERE p.store_id = :store_id
                       AND p.is_active = 1
                       AND p.is_archived = 0
                       AND (p.parent_id IS NULL OR p.parent_id = 0)
                       AND p.id > :last_id
                     ORDER BY p.id ASC
                     LIMIT " . self::CHUNK,
                    ['store_id' => $storeId, 'last_id' => $lastId]
                );

                if ($rows === []) {
                    break;
                }
                foreach ($rows as $p) {
                    fwrite($fp, $this->buildUrunNode($p, $rates));
                    $lastId = (int) $p['id'];
                    $count++;
                }
                if (count($rows) < self::CHUNK) {
                    break;
                }
            }

            fwrite($fp, "</urunler>\n");
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($tmpFile);
            throw $e;
        }
        fclose($fp);

        // Atomik yayınlama: tamamlanan dosyayı yerine taşı (yarım okuma olmaz)
        if (!@rename($tmpFile, $finalFile)) {
            @copy($tmpFile, $finalFile);
            @unlink($tmpFile);
        }

        $this->profileModel->markRun($profileId, 'success', $count . ' ürün dışa aktarıldı', $count);
        $this->logger->info("XML export profili #{$profileId} üretildi: {$count} ürün", 'XmlProfile');

        return $count;
    }

    /**
     * Public serving: token ile çıktı DOSYA YOLUNU getir (gerekirse yeniden üret).
     * İçeriği belleğe almaz — çağıran readfile ile akıtır.
     * Public uçtan (oturumsuz) gelince tenant bağlamı profil kaydından kurulur.
     *
     * @return string|null Dosya yolu, yoksa null
     */
    public function serveFile(string $token): ?string
    {
        $profile = $this->profileModel->findByTokenGlobal($token);
        if ($profile === false || ($profile['direction'] ?? '') !== 'export' || (int) $profile['is_active'] !== 1) {
            return null;
        }

        $file = $this->exportDir . '/export_' . $token . '.xml';
        $cacheHours = max(1, ((int) ($profile['period_hours'] ?? 0)) ?: 4);
        $stale = !is_file($file) || (time() - filemtime($file)) > $cacheHours * 3600;

        if ($stale) {
            // Stampede önleme: yalnız bir istek üretsin (LOCK_EX|LOCK_NB).
            // Kilit alınamazsa ve elde bayat dosya varsa onu servis et.
            $lockFp = @fopen($file . '.lock', 'c');
            $locked = $lockFp !== false && flock($lockFp, LOCK_EX | LOCK_NB);

            if ($locked) {
                try {
                    // Public (oturumsuz) çağrıda tenant bağlamını profil sahibinden kur
                    $hadStore = $this->tenant->hasStore();
                    if (!$hadStore) {
                        $this->tenant->setStoreId((int) $profile['store_id']);
                    }
                    try {
                        $this->generate((int) $profile['id']);
                    } finally {
                        if (!$hadStore) {
                            $this->tenant->clear();
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('XML export üretim hatası: ' . $e->getMessage(), 'XmlProfile');
                } finally {
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                }
            } elseif ($lockFp !== false) {
                fclose($lockFp);
            }
        }

        return is_file($file) ? $file : null;
    }

    /**
     * @deprecated serveFile() kullanın — bu, tüm dosyayı belleğe alır.
     */
    public function serve(string $token): ?string
    {
        $file = $this->serveFile($token);
        return $file !== null ? (file_get_contents($file) ?: null) : null;
    }

    /**
     * Tek ürün için Ticimax V2 <Urun> düğümü üret.
     *
     * @param array<string, mixed> $p
     * @param array<string, float> $rates
     */
    private function buildUrunNode(array $p, array $rates): string
    {
        $currency = strtoupper((string) ($p['currency_code'] ?? 'TRY'));
        $isTl = $currency === 'TRY' || $currency === 'TL' || $currency === '';
        $rate = $isTl ? 1.0 : (float) ($rates[$currency] ?? 0);

        $basePrice = (float) ($p['base_price'] ?? 0);
        $costPrice = (float) ($p['cost_price'] ?? 0);

        if ($isTl || $rate <= 0) {
            $kurSatis = sprintf('Birim:TL Kur_Deger:1 Tutar:%.2f', $basePrice);
            $kurAlis  = sprintf('Birim:TL Kur_Deger:1 Tutar:%.2f', $costPrice);
        } else {
            $rateStr = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
            $kurSatis = sprintf('Birim:%s Kur_Deger:%s Tutar:%.2f', $currency, $rateStr, (float) $p['currency_price']);
            $kurAlis  = sprintf('Birim:%s Kur_Deger:%s Tutar:%.2f', $currency, $rateStr, (float) $p['currency_cost']);
        }

        // Kategori yolu: "A > B > C" → "A|B|C"
        $categoryTree = str_replace(' > ', '|', (string) ($p['category_path'] ?? ''));

        // Resimler
        $images = json_decode((string) ($p['images_json'] ?? '[]'), true) ?: [];
        $imageUrls = [];
        foreach ($images as $img) {
            $url = is_array($img) ? (string) ($img['url'] ?? $img['src'] ?? '') : (string) $img;
            if ($url !== '') {
                $imageUrls[] = $url;
            }
        }
        if ($imageUrls === [] && !empty($p['main_image'])) {
            $imageUrls[] = (string) $p['main_image'];
        }

        $out  = "<Urun>\n";
        $out .= '<UrunAdi>' . $this->cdata((string) $p['name']) . "</UrunAdi>\n";
        $out .= '<Aciklama>' . $this->cdata((string) ($p['description'] ?? '')) . "</Aciklama>\n";
        $out .= '<Marka>' . $this->cdata((string) ($p['brand_name'] ?? '')) . "</Marka>\n";
        $out .= '<KategoriTree>' . $this->cdata($categoryTree) . "</KategoriTree>\n";
        $out .= '<StokKodu>' . $this->cdata((string) $p['sku']) . "</StokKodu>\n";
        $out .= '<Barkod>' . $this->cdata((string) ($p['barcode'] ?? '')) . "</Barkod>\n";
        $out .= '<StokAdedi>' . $this->cdata((string) (int) ($p['stock_qty'] ?? 0)) . "</StokAdedi>\n";
        $out .= '<AlisFiyati_TL>' . $this->cdata($this->num($costPrice)) . "</AlisFiyati_TL>\n";
        $out .= '<KUR_Deger>' . $this->cdata($kurAlis) . "</KUR_Deger>\n";
        $out .= '<SatisFiyati_TL>' . $this->cdata($this->num($basePrice)) . "</SatisFiyati_TL>\n";
        $out .= '<KUR_Deger>' . $this->cdata($kurSatis) . "</KUR_Deger>\n";
        $out .= '<KdvOrani>' . $this->cdata($this->num((float) ($p['tax_rate'] ?? 20))) . "</KdvOrani>\n";
        $out .= '<Desi>' . $this->cdata($this->num((float) ($p['desi'] ?? 1))) . "</Desi>\n";
        for ($i = 1; $i <= 4; $i++) {
            $out .= '<Resim_' . $i . '>' . $this->cdata($imageUrls[$i - 1] ?? '') . '</Resim_' . $i . ">\n";
        }
        $out .= "</Urun>\n";

        return $out;
    }

    private function cdata(string $value): string
    {
        // CDATA içinde "]]>" geçerse böl
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    private function num(float $value): string
    {
        $s = number_format($value, 2, '.', '');
        return str_ends_with($s, '.00') ? substr($s, 0, -3) : $s;
    }
}
