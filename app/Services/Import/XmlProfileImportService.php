<?php
/**
 * OzyConnect Panel — XmlProfileImportService
 *
 * XML Profili içe aktarım motoru (Ticimax / dbentegrasyon uyumlu):
 *   - URL veya dosyadan XML alır, XMLReader ile AKIŞ halinde işler (13MB+ feed'ler
 *     belleğe yüklenmez)
 *   - Ticimax V2 yapısını otomatik tanır: UrunAdi, Aciklama, Marka, KategoriTree,
 *     StokKodu, Barkod, StokAdedi, AlisFiyati_TL, KUR_Deger(x2), SatisFiyati_TL,
 *     KdvOrani, Desi, Resim_1..N
 *   - KUR_Deger "Birim:USD Kur_Deger:46.8906 Tutar:11.72" çözümlenir; döviz-cinsli
 *     ürünlerde orijinal döviz tutarı saklanır (currency_code/currency_price/
 *     currency_cost), TL fiyat GÜNCEL TCMB satış kuruyla hesaplanır. Kur cron'u
 *     (sync_exchange_rates.php) fiyatları her koşumda yeniden hesaplar.
 *   - KategoriTree "A|B|C" → hiyerarşik kategori zinciri oluşturur
 *   - Marka bul/oluştur; resim URL'leri images_json + main_image
 *   - SKU (StokKodu) ile upsert — tekrar senkronda fiyat/stok güncellenir
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

namespace App\Services\Import;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Helpers\StringHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\XmlProfile;
use App\Models\XmlProfileLog;
use App\Services\ExchangeRateService;

final class XmlProfileImportService
{
    /** Ticimax feed'ini tanımak için aranan zorunlu etiketler */
    private const TICIMAX_MARKERS = ['UrunAdi', 'StokKodu'];

    /**
     * Bir düğümün çocuk etiketleri Ticimax ürünü mü?
     * V1 (dbentegrasyon düz): UrunAdi + StokKodu ürün düzeyinde.
     * V2 (gerçek Ticimax mağaza XML'i): UrunAdi + UrunSecenek (StokKodu seçenek içinde).
     *
     * @param array<int, string> $tags
     */
    private static function isTicimaxTags(array $tags): bool
    {
        return count(array_intersect(self::TICIMAX_MARKERS, $tags)) === count(self::TICIMAX_MARKERS)
            || (in_array('UrunAdi', $tags, true) && in_array('UrunSecenek', $tags, true));
    }

    /** @var array<string, int> Kategori yolu → id (koşum içi önbellek) */
    private array $categoryCache = [];

    /** @var array<string, int> Marka adı → id (koşum içi önbellek) */
    private array $brandCache = [];

    private array $attributeCache = [];

    /**
     * Bu koşumda geçerli profil ayarları (barkod ön eki, kritik stok, varsayılanlar).
     * Her tedarikçinin kuralı farklı olduğu için profil bazında okunur.
     *
     * @var array<string, mixed>
     */
    private array $profilAyari = [];

    public function __construct(
        private readonly XmlProfile $profileModel,
        private readonly XmlProfileLog $logModel,
        private readonly Product $productModel,
        private readonly Category $categoryModel,
        private readonly Brand $brandModel,
        private readonly ExchangeRateService $exchangeRates,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
        private readonly XmlProfileSettings $profileSettings,
        private readonly ProductAttributeValue $productAttributeValues,
    ) {}

    /**
     * Profili çalıştır (indir + senkronize et).
     *
     * @return array{ok: bool, message: string, total: int, created: int, updated: int, skipped: int, failed: int}
     */
    public function run(int $profileId, string $triggeredBy = 'manual'): array
    {
        $profile = $this->profileModel->findOrFail($profileId);

        if (($profile['direction'] ?? 'import') !== 'import') {
            return ['ok' => false, 'message' => 'Bu profil içe aktarım profili değil.', 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // Eşzamanlılık kilidi: aynı profil için cron + manuel (veya iki manuel) koşum
        // çakışırsa çift kategori/marka ve deadlock oluşur. GET_LOCK aynı PDO bağlantısı
        // ömrü boyunca tutulur; alınamazsa koşum reddedilir (log açmadan).
        $lockKey = 'xmlprofile_' . $this->tenant->currentStoreId() . '_' . $profileId;
        $lockRow = $this->db->fetchOne("SELECT GET_LOCK(:k, 0) AS l", ['k' => $lockKey]);
        if ((int) ($lockRow['l'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Bu profil şu anda zaten senkronize ediliyor — lütfen bekleyin.', 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $logId = $this->logModel->start($profileId, 'sync', $triggeredBy);
        $this->categoryCache = [];
        $this->brandCache = [];
        $this->attributeCache = [];
        $this->profilAyari = $this->profileSettings->forProfile($profileId);

        $localFile = null;
        $isTemp = false;

        try {
            // 1. Kaynağı yerel dosyaya al
            [$localFile, $isTemp] = $this->resolveSource($profile);

            // 2. Döviz kurlarını tazele (eskiyse) — USD/EUR bazlı feed'ler için şart
            $this->exchangeRates->ensureFresh(12);

            // 3. Format / eşleme çöz
            $mapping = $this->resolveMapping($profile, $localFile);

            // 4. Akış halinde işle
            $result = $this->processFile($localFile, $profile, $mapping);

            $status = $result['failed'] > 0
                ? ($result['created'] + $result['updated'] > 0 ? 'partial' : 'failed')
                : 'success';

            $message = sprintf(
                '%d ürün işlendi: %d yeni, %d güncellendi, %d atlandı, %d hatalı',
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                $result['failed']
            );

            $this->logModel->finish(
                $logId,
                $status,
                $message,
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                $result['failed'],
                $result['errors']
            );
            $this->profileModel->markRun($profileId, $status, $message, $this->profileModel->getProductCount($profileId));

            $this->logger->info("XML profil #{$profileId} senkronu: {$message}", 'XmlProfile');

            return ['ok' => true, 'message' => $message] + $result;
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 480);
            $this->logModel->finish($logId, 'failed', 'Hata: ' . $err);
            $this->profileModel->markRun($profileId, 'failed', 'Hata: ' . $err);
            $this->logger->error("XML profil #{$profileId} senkron hatası: " . $e->getMessage(), 'XmlProfile');

            return ['ok' => false, 'message' => 'Hata: ' . $err, 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        } finally {
            if ($isTemp && $localFile !== null) {
                @unlink($localFile);
            }
            $this->db->query("SELECT RELEASE_LOCK(:k)", ['k' => $lockKey]);
        }
    }

    /**
     * XML kaynağını analiz et (kaydetmeden önizleme): ürün düğümü, adet, etiketler.
     *
     * @return array{ok: bool, message: string, item_tag: string, count: int, tags: array<int, string>, is_ticimax: bool}
     */
    public function analyze(string $sourceUrl): array
    {
        $tmp = $this->downloadToTemp($sourceUrl);
        try { return $this->analyzeFile($tmp); }
        finally { @unlink($tmp); }
    }

    public function analyzeProfile(int $profileId): array
    {
        $profile = $this->profileModel->findOrFail($profileId);
        [$file, $temporary] = $this->resolveSource($profile);
        try { return $this->analyzeFile($file); }
        finally { if ($temporary) @unlink($file); }
    }

    public function analyzeFile(string $file): array
    {
        [$itemTag, $tags] = $this->detectStructure($file);
        if ($itemTag === '') {
            throw new \RuntimeException('XML içinde ürün düğümü bulunamadı.');
        }
        $count = 0;
        $samples = [];
        $reader = $this->openReader($file);
        $ok = $reader->read();
        while ($ok) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === $itemTag) {
                $count++;
                if ($count <= 5) {
                    $node = $reader->expand();
                    if ($node !== false) foreach ($node->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE) {
                            $tags[] = $child->nodeName;
                            $text = trim($child->textContent);
                            if ($text !== '' && empty($samples[$child->nodeName])) {
                                $samples[$child->nodeName] = mb_substr(strip_tags($text), 0, 160);
                            }
                        }
                    }
                }
                $ok = $reader->next();
            } else { $ok = $reader->read(); }
        }
        $reader->close();
        $tags = array_values(array_unique($tags));
        return ['ok'=>true, 'message'=>"{$count} ürün bulundu", 'item_tag'=>$itemTag,
            'count'=>$count, 'tags'=>$tags, 'samples'=>(object)$samples,
            'suggested_mapping'=>XmlProfileMapping::suggest($tags, $itemTag),
            'is_ticimax'=>self::isTicimaxTags($tags)];
    }

    // ─── Kaynak çözümleme ─────────────────────────────────

    /**
     * Profil kaynağını yerel dosya yoluna çevir (URL → geçici dosyaya indir).
     *
     * @return array{0: string, 1: bool} [dosya yolu, geçici mi]
     */
    private function resolveSource(array $profile): array
    {
        $sourceType = (string) ($profile['source_type'] ?? 'url');
        $sourceUrl  = trim((string) ($profile['source_url'] ?? ''));

        if ($sourceUrl === '') {
            throw new \RuntimeException('Profilde XML kaynağı (URL/dosya) tanımlı değil.');
        }

        if ($sourceType === 'file') {
            $path = BASE_PATH . '/storage/xmlprofiles/' . basename($sourceUrl);
            if (!is_file($path)) {
                throw new \RuntimeException('Yüklenen XML dosyası bulunamadı: ' . basename($sourceUrl));
            }
            return [$path, false];
        }

        return [$this->downloadToTemp($sourceUrl), true];
    }

    /**
     * URL'i geçici dosyaya indir (büyük feed'ler için akışla, belleğe almadan)
     */
    private function downloadToTemp(string $url): string
    {
        // SSRF koruması: yalnız http(s) + herkese açık IP'ler (iç ağ/bulut metadata engellenir)
        $this->assertPublicUrl($url);

        $dir = BASE_PATH . '/storage/xmlprofiles/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/dl_' . bin2hex(random_bytes(8)) . '.xml';

        if (function_exists('curl_init')) {
            $fp = fopen($tmp, 'wb');
            if ($fp === false) {
                throw new \RuntimeException('Geçici dosya oluşturulamadı.');
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_CONNECTTIMEOUT => 20,
                // Yönlendirmeleri elle izle: her adımın hedef IP'sini yeniden doğrula
                // (SSRF: harici görünen URL 302 ile iç ağa yönlendiremesin)
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 OzyConnect XmlProfile',
            ]);
            $current = $url;
            $redirects = 0;
            $ok = false; $err = ''; $httpCode = 0;
            while (true) {
                // Her denemede dosyayı sıfırla — ara (3xx) yanıt gövdeleri birikmesin
                ftruncate($fp, 0);
                rewind($fp);
                curl_setopt($ch, CURLOPT_URL, $current);
                $ok = curl_exec($ch) === true;
                $err = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($ok && $httpCode >= 300 && $httpCode < 400 && $redirects < 5) {
                    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                    if ($loc !== '') {
                        $this->assertPublicUrl($loc);            // yönlendirme hedefini yeniden doğrula
                        $current = $loc;
                        $redirects++;
                        continue;
                    }
                }
                break;
            }
            curl_close($ch);
            fclose($fp);
            if ($ok && $httpCode >= 400) {
                $ok = false;
                $err = 'HTTP ' . $httpCode;
            }
            if (!$ok) {
                @unlink($tmp);
                throw new \RuntimeException('XML indirilemedi: ' . ($err ?: 'bilinmeyen hata'));
            }
        } elseif (ini_get('allow_url_fopen')) {
            $body = @file_get_contents($url);
            if ($body === false) {
                @unlink($tmp);
                throw new \RuntimeException('XML indirilemedi (sunucuda curl/allow_url_fopen gerekli).');
            }
            file_put_contents($tmp, $body, LOCK_EX);
        } else {
            throw new \RuntimeException('Sunucuda curl veya allow_url_fopen etkin olmalı.');
        }

        if (!is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new \RuntimeException('XML kaynağı boş ya da erişilemez.');
        }

        return $tmp;
    }

    // ─── Format / eşleme ──────────────────────────────────

    /**
     * Profilin alan eşlemesini çöz. format=auto → dosyadan tespit;
     * format=generic → field_mapping_json kullanılır; format=ticimax → sabit şablon.
     *
     * @return array<string, mixed>
     */
    private function resolveMapping(array $profile, string $localFile): array
    {
        $format = (string) ($profile['format'] ?? 'auto');

        if ($format === 'generic') {
            $map = json_decode((string) ($profile['field_mapping_json'] ?? ''), true) ?: [];
            XmlProfileMapping::validate($map);
            $map['_ticimax'] = false;
            return $map;
        }

        // auto / ticimax → yapıyı dosyadan tespit et
        [$itemTag, $tags] = $this->detectStructure($localFile);
        if ($itemTag === '') {
            throw new \RuntimeException('XML içinde tekrar eden ürün düğümü bulunamadı.');
        }

        $isTicimax = self::isTicimaxTags($tags);
        if ($format === 'ticimax' && !$isTicimax) {
            throw new \RuntimeException('Kaynak, Ticimax XML yapısında değil (UrunAdi/StokKodu etiketleri yok).');
        }

        if ($isTicimax) {
            return ['item_tag' => $itemTag, '_ticimax' => true];
        }

        $map = XmlProfileMapping::suggest($tags, $itemTag) + ['_ticimax'=>false, 'sku'=>'', 'name'=>''];

        if ($map['sku'] === '' || $map['name'] === '') {
            throw new \RuntimeException('Alan eşlemesi otomatik çözülemedi — profil ayarlarından genel format eşlemesi girin. Bulunan etiketler: ' . implode(', ', array_slice($tags, 0, 25)));
        }

        return $map;
    }

    /**
     * Ürün düğümünü tespit et. Kökün ilk seviyesindeki element düğümleri tür-tür
     * sayılır; EN SIK tekrar eden düğüm ürün kabul edilir (baştaki tekil metadata
     * bloğu — <bilgi>, <firma>, <XML_Url> vb. — ürün sanılmaz). Herhangi bir
     * aday Ticimax işaretlerini (UrunAdi + StokKodu) taşıyorsa sayıya bakılmaksızın
     * o tercih edilir. Performans için ilk ~50 düğüm örneklenir.
     *
     * @return array{0: string, 1: array<int, string>} [item_tag, child_tags]
     */
    private function detectStructure(string $file): array
    {
        $reader = $this->openReader($file);

        $counts = [];                 // düğüm adı → görülme sayısı
        $childTagsByName = [];        // düğüm adı → çocuk etiketleri
        $ticimaxTag = '';
        $sampled = 0;

        $ok = $reader->read();
        while ($ok) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 1) {
                $name = $reader->name;
                $node = $reader->expand();
                $childTags = [];
                if ($node !== false) {
                    foreach ($node->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE) {
                            $childTags[] = $child->nodeName;
                        }
                    }
                }

                if ($childTags !== []) {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                    if (!isset($childTagsByName[$name])) {
                        $childTagsByName[$name] = array_values(array_unique($childTags));
                    }
                    // Ticimax işaretli aday → hemen kilitle
                    if ($ticimaxTag === '' && self::isTicimaxTags($childTags)) {
                        $ticimaxTag = $name;
                        break;
                    }
                    // Derin yapı: <Root><Urunler><Urun>… — ürün düğümü bir seviye
                    // İÇERİDE olabilir. İlk element torununa bak; Ticimax işaretliyse
                    // ürün etiketi odur (Ticimax V2 mağaza XML'inin standart yapısı).
                    if ($ticimaxTag === '' && $node !== false) {
                        foreach ($node->childNodes as $child) {
                            if ($child->nodeType !== XML_ELEMENT_NODE) {
                                continue;
                            }
                            $grandTags = [];
                            foreach ($child->childNodes as $g) {
                                if ($g->nodeType === XML_ELEMENT_NODE) {
                                    $grandTags[] = $g->nodeName;
                                }
                            }
                            if ($grandTags !== [] && self::isTicimaxTags($grandTags)) {
                                $ticimaxTag = $child->nodeName;
                                $childTagsByName[$ticimaxTag] = array_values(array_unique($grandTags));
                            }
                            break; // yalnız ilk element çocuğu örneklenir
                        }
                        if ($ticimaxTag !== '') {
                            break;
                        }
                    }
                    if (++$sampled >= 50) {
                        break;
                    }
                }
                $ok = $reader->next();  // alt ağacı atla, sıradaki kardeşe geç
                continue;
            }
            $ok = $reader->read();
        }
        $reader->close();

        if ($ticimaxTag !== '') {
            return [$ticimaxTag, $childTagsByName[$ticimaxTag] ?? []];
        }
        if ($counts === []) {
            return ['', []];
        }
        // En sık tekrar eden düğüm = ürün
        arsort($counts);
        $itemTag = array_key_first($counts);
        return [$itemTag, $childTagsByName[$itemTag] ?? []];
    }

    // ─── Ana işleme döngüsü ───────────────────────────────

    /**
     * @param array<string, mixed> $mapping
     * @return array{total: int, created: int, updated: int, skipped: int, failed: int, errors: array<int, array<string, string>>}
     */
    private function processFile(string $file, array $profile, array $mapping): array
    {
        $itemTag = (string) $mapping['item_tag'];
        $isTicimax = (bool) ($mapping['_ticimax'] ?? false);

        $total = 0; $created = 0; $updated = 0; $skipped = 0; $failed = 0;
        $errors = [];

        // Koşum boyu kur önbelleği (TCMB yoksa feed'in kendi kuru yedek olur)
        $rateCache = [];
        $getRate = function (string $code, float $feedRate) use (&$rateCache): float {
            $code = strtoupper($code);
            if (!isset($rateCache[$code])) {
                $rateCache[$code] = $this->exchangeRates->getRate($code);
            }
            return $rateCache[$code] > 0 ? $rateCache[$code] : $feedRate;
        };

        $reader = $this->openReader($file);
        $inTx = false;
        $batch = 0;
        // Batch'te işlenen ürün sayısı — transaction sunucu tarafında (deadlock/1213)
        // topluca geri alınırsa sayaçları geri sarmak için tutulur.
        $txCreated = 0; $txUpdated = 0; $txSkipped = 0;
        // expand() düğümleri belgeye bağlamak için ortak DOMDocument
        // (simplexml_import_dom "must have associated Document" ister)
        $doc = new \DOMDocument('1.0', 'UTF-8');

        try {
        $ok = $reader->read();
        while ($ok) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== $itemTag) {
                $ok = $reader->read();
                continue;
            }

            $node = $reader->expand($doc);
            // Alt ağacı atla — okuyucuyu sıradaki düğüme taşı (expand konumu değiştirmez)
            $ok = $reader->next();

            if ($node === false) {
                continue;
            }
            $item = simplexml_import_dom($node);
            if (!($item instanceof \SimpleXMLElement)) {
                continue;
            }

            $total++;
            $row = null;

            try {
                $row = $isTicimax
                    ? $this->parseTicimaxItem($item, $getRate)
                    : $this->parseGenericItem($item, $mapping);

                if ($row === null || $row['sku'] === '') {
                    $failed++;
                    if (count($errors) < 100) {
                        $errors[] = ['sku' => '', 'error' => 'StokKodu (SKU) boş — satır ' . $total];
                    }
                    continue;
                }

                if (!$inTx) {
                    $this->db->beginTransaction();
                    $inTx = true;
                    $txCreated = $txUpdated = $txSkipped = 0;
                }

                $outcome = $this->upsertProduct($row, $profile);
                if ($outcome === 'created') {
                    $created++; $txCreated++;
                } elseif ($outcome === 'updated') {
                    $updated++; $txUpdated++;
                } else {
                    $skipped++; $txSkipped++;
                }

                if (++$batch >= 200) {
                    $this->db->commit();
                    $inTx = false;
                    $batch = 0;
                }
            } catch (\Throwable $e) {
                // Sunucu transaction'ı topluca geri aldıysa (deadlock 1213, lock timeout
                // 1205) TÜM batch kayboldu — tek-ürün hatası sanıp sayaçları şişirme.
                $serverAborted = $inTx && !$this->db->getConnection()->inTransaction();
                if ($serverAborted) {
                    // Batch'te başarılı sayılan ürünleri geri sar
                    $created -= $txCreated; $updated -= $txUpdated; $skipped -= $txSkipped;
                    $failed  += $txCreated + $txUpdated + $txSkipped + 1;
                    // Database::$transactionDepth'i 0'a senkronla (PDO zaten geri aldı)
                    try { $this->db->rollBack(); } catch (\Throwable) {}
                    $inTx = false; $batch = 0;
                    $txCreated = $txUpdated = $txSkipped = 0;
                    if (count($errors) < 100) {
                        $errors[] = ['sku' => (string) ($row['sku'] ?? '?'), 'error' => 'Transaction geri alındı (kilit/deadlock): ' . mb_substr($e->getMessage(), 0, 160)];
                    }
                } else {
                    $failed++;
                    if (count($errors) < 100) {
                        $errors[] = ['sku' => (string) ($row['sku'] ?? '?'), 'error' => mb_substr($e->getMessage(), 0, 200)];
                    }
                }
            }
        }

        if ($inTx) {
            $this->db->commit();
            $inTx = false;
        }
        } catch (\Throwable $e) {
            // Açık transaction bırakma — uzun ömürlü cron sürecinde sonraki
            // mağazaların işlemlerini kilitler/kirletir
            if ($inTx) {
                try { $this->db->rollBack(); } catch (\Throwable) {}
            }
            $reader->close();
            throw $e;
        }
        $reader->close();

        return [
            'total'   => $total,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'errors'  => $errors,
        ];
    }

    // ─── Satır çözümleyiciler ─────────────────────────────

    /**
     * Ticimax V2 <Urun> düğümünü normalize satıra çevir.
     *
     * KUR_Deger etiketi ürün başına İKİ kez geçer: ilki AlisFiyati_TL'nin,
     * ikincisi SatisFiyati_TL'nin döviz karşılığıdır.
     * Format: "Birim:USD Kur_Deger:46.8906 Tutar:11.72"
     *
     * @param callable(string, float): float $getRate
     * @return array<string, mixed>|null
     */
    private function parseTicimaxItem(\SimpleXMLElement $item, callable $getRate): ?array
    {
        // Gerçek Ticimax V2 (mağaza XML'i): StokKodu <UrunSecenek><Secenek> İÇİNDEDİR
        // ve ürün çok seçenekliyse (beden/renk) her Secenek bir VARYANTTIR.
        if (isset($item->UrunSecenek->Secenek)) {
            return $this->parseTicimaxV2Item($item, $getRate);
        }

        $sku = trim((string) $item->StokKodu);
        if ($sku === '') {
            return null;
        }

        // KUR_Deger çiftleri (0 → alış, 1 → satış)
        $kurAlis  = $this->parseKurDeger((string) ($item->KUR_Deger[0] ?? ''));
        $kurSatis = $this->parseKurDeger((string) ($item->KUR_Deger[1] ?? ''));
        if ($kurSatis === null && $kurAlis !== null) {
            // Tek KUR_Deger varsa satış için kullan
            $kurSatis = $kurAlis;
            $kurAlis = null;
        }

        $satisTl = $this->toFloat((string) $item->SatisFiyati_TL);
        $alisTl  = $this->toFloat((string) $item->AlisFiyati_TL);

        $currencyCode  = 'TRY';
        $currencyPrice = 0.0;
        $currencyCost  = 0.0;
        $basePrice     = $satisTl;
        $costPrice     = $alisTl;

        if ($kurSatis !== null && !in_array($kurSatis['birim'], ['TL', 'TRY'], true) && $kurSatis['tutar'] > 0) {
            // Döviz bazlı ürün: orijinal tutarı sakla, TL fiyatı GÜNCEL kurla hesapla
            $currencyCode  = $kurSatis['birim'];
            $currencyPrice = $kurSatis['tutar'];
            $rate = $getRate($currencyCode, $kurSatis['kur']);
            $basePrice = round($currencyPrice * $rate, 2);

            if ($kurAlis !== null && $kurAlis['birim'] === $currencyCode && $kurAlis['tutar'] > 0) {
                $currencyCost = $kurAlis['tutar'];
                $costPrice = round($currencyCost * $rate, 2);
            }
        }

        // Resim_1..Resim_12
        $images = [];
        for ($i = 1; $i <= 12; $i++) {
            $tag = 'Resim_' . $i;
            if (!isset($item->{$tag})) {
                break;
            }
            $url = trim((string) $item->{$tag});
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $images[] = $url;
            }
        }

        return [
            'sku'            => $sku,
            'name'           => trim((string) $item->UrunAdi),
            'description'    => trim((string) $item->Aciklama),
            'barcode'        => trim((string) $item->Barkod),
            'brand'          => trim((string) $item->Marka),
            'category_path'  => array_values(array_filter(array_map('trim', explode('|', (string) $item->KategoriTree)))),
            'stock_qty'      => max(0, (int) $this->toFloat((string) $item->StokAdedi)),
            'tax_rate'       => $this->toFloat((string) $item->KdvOrani),
            'desi'           => $this->toFloat((string) $item->Desi),
            'base_price'     => $basePrice,
            'cost_price'     => $costPrice,
            'currency_code'  => $currencyCode,
            'currency_price' => $currencyPrice,
            'currency_cost'  => $currencyCost,
            'images'         => $images,
        ];
    }

    /**
     * Gerçek Ticimax V2 <Urun> düğümü: ortak bilgiler ürün düzeyinde,
     * SKU/barkod/stok/fiyat <UrunSecenek><Secenek> altındadır.
     * TEK seçenek → düz ürün satırı; ÇOK seçenek → parent satır + 'variants' listesi
     * (her varyant EkSecenekOzellik'ten beden/renk özellikleriyle gelir).
     *
     * @param callable(string, float): float $getRate
     * @return array<string, mixed>|null
     */
    private function parseTicimaxV2Item(\SimpleXMLElement $item, callable $getRate): ?array
    {
        $name = trim((string) $item->UrunAdi);

        // KategoriTree ayracı: dbentegrasyon '|', Ticimax V2 '/' kullanır
        $tree = (string) $item->KategoriTree;
        $sep  = str_contains($tree, '|') ? '|' : '/';
        $categoryPath = array_values(array_filter(array_map('trim', explode($sep, $tree))));

        // Görseller: <Resimler><Resim> listesi (+ Resim_1..12 eski biçim yedeği)
        $images = [];
        if (isset($item->Resimler->Resim)) {
            foreach ($item->Resimler->Resim as $img) {
                $url = trim((string) $img);
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $images[] = $url;
                }
            }
        }
        for ($i = 1; $i <= 12 && $images === []; $i++) {
            $url = trim((string) ($item->{'Resim_' . $i} ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $images[] = $url;
            }
        }

        $common = [
            'name'          => $name,
            'description'   => trim((string) $item->Aciklama),
            'brand'         => trim((string) $item->Marka),
            'category_path' => $categoryPath,
            'images'        => $images,
        ];

        // Seçenekleri çöz
        $options = [];
        foreach ($item->UrunSecenek->Secenek as $sec) {
            $sku = trim((string) $sec->StokKodu);
            if ($sku === '') {
                continue;
            }

            $satis    = $this->toFloat((string) $sec->SatisFiyati);
            $indirimli = $this->toFloat((string) $sec->IndirimliFiyat);
            $alis     = $this->toFloat((string) $sec->AlisFiyati);
            $base     = ($indirimli > 0 && $indirimli < $satis) ? $indirimli : $satis;
            $list     = ($indirimli > 0 && $indirimli < $satis) ? $satis : 0.0;

            $currencyCode  = strtoupper(trim((string) ($sec->ParaBirimiKodu ?: 'TRY')));
            $currencyPrice = 0.0;
            $currencyCost  = 0.0;
            if (!in_array($currencyCode, ['TL', 'TRY', ''], true)) {
                $rate = $getRate($currencyCode, 0.0);
                if ($rate > 0) {
                    $currencyPrice = $base;
                    $currencyCost  = $alis;
                    $base = round($base * $rate, 2);
                    $list = $list > 0 ? round($list * $rate, 2) : 0.0;
                    $alis = round($alis * $rate, 2);
                } else {
                    $currencyCode = 'TRY'; // kur bulunamadı — tutarı TL varsay
                }
            } else {
                $currencyCode = 'TRY';
            }

            // Varyant özellikleri: <EkSecenekOzellik><Ozellik Tanim="Beden">36</Ozellik>
            $attrs = [];
            if (isset($sec->EkSecenekOzellik->Ozellik)) {
                foreach ($sec->EkSecenekOzellik->Ozellik as $oz) {
                    $tanim = trim((string) ($oz['Tanim'] ?? $oz['tanim'] ?? ''));
                    $deger = trim((string) $oz);
                    if ($deger !== '') {
                        $attrs[$tanim !== '' ? $tanim : ('Özellik ' . (count($attrs) + 1))] = $deger;
                    }
                }
            }

            $options[] = [
                'sku'            => $sku,
                'barcode'        => trim((string) $sec->Barkod),
                'stock_qty'      => max(0, (int) $this->toFloat((string) $sec->StokAdedi)),
                'tax_rate'       => $this->toFloat((string) $sec->KdvOrani),
                'desi'           => $this->toFloat((string) $sec->Desi),
                'base_price'     => $base,
                'list_price'     => $list,
                'cost_price'     => $alis,
                'currency_code'  => $currencyCode,
                'currency_price' => $currencyPrice,
                'currency_cost'  => $currencyCost,
                'attrs'          => $attrs,
            ];
        }

        if ($options === []) {
            return null;
        }

        // TEK seçenek → varyantsız düz ürün (dbentegrasyon düz formatıyla aynı sonuç)
        if (count($options) === 1) {
            $o = $options[0];
            unset($o['attrs']);
            return $common + $o;
        }

        // ÇOK seçenek → parent + varyantlar. Parent SKU'su feed'de yoktur;
        // UrunKartiID'den deterministik türetilir (tekrar koşumda aynı karta eşleşir).
        $kartId = trim((string) $item->UrunKartiID);
        $parentSku = $kartId !== '' ? ('KART-' . $kartId) : ($options[0]['sku'] . '-ANA');

        $toplamStok = 0;
        $minFiyat = null;
        foreach ($options as $o) {
            $toplamStok += $o['stock_qty'];
            if ($o['base_price'] > 0 && ($minFiyat === null || $o['base_price'] < $minFiyat)) {
                $minFiyat = $o['base_price'];
            }
        }
        $first = $options[0];

        return $common + [
            'sku'            => $parentSku,
            'barcode'        => '',
            'stock_qty'      => $toplamStok,
            'tax_rate'       => $first['tax_rate'],
            'desi'           => $first['desi'],
            'base_price'     => $minFiyat ?? $first['base_price'],
            'list_price'     => $first['list_price'],
            'cost_price'     => $first['cost_price'],
            'currency_code'  => $first['currency_code'],
            'currency_price' => $first['currency_price'],
            'currency_cost'  => $first['currency_cost'],
            'variants'       => $options,
        ];
    }

    /**
     * Genel (eşlemeli) düğümü normalize satıra çevir.
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>|null
     */
    private function parseGenericItem(\SimpleXMLElement $item, array $mapping): ?array
    {
        $get = static function (string $key) use ($item, $mapping): string {
            $tag = (string) ($mapping[$key] ?? '');
            if ($tag === '') {
                return '';
            }
            return trim((string) ($item->{$tag} ?? ''));
        };

        $sku = $get('sku');
        if ($sku === '') {
            return null;
        }

        $sep = (string) ($mapping['category_sep'] ?? '|');
        if ($sep === '') {
            $sep = '|';
        }
        $categoryPath = [];
        foreach (['category','category2','category3','category4','category5'] as $field) {
            foreach (explode($sep, $get($field)) as $part) {
                $part = trim($part);
                if ($part !== '' && end($categoryPath) !== $part) $categoryPath[] = $part;
            }
        }

        // Resimler: image_prefix (Resim / resim gibi) → Resim_1..12 / resim1..12,
        // yoksa tek 'image' etiketi
        $images = [];
        $prefix = (string) ($mapping['image_prefix'] ?? '');
        // Older UI saved Image1 as a prefix; normalize it to Image.
        $prefix = (string) preg_replace('/_?1$/', '', $prefix);
        if ($prefix !== '') {
            for ($i = 1; $i <= 12; $i++) {
                $found = false;
                foreach ([$prefix . '_' . $i, $prefix . $i] as $tag) {
                    if (isset($item->{$tag})) {
                        $found = true;
                        $url = trim((string) $item->{$tag});
                        if ($url !== '' && preg_match('#^https?://#i', $url)) {
                            $images[] = $url;
                        }
                        break;
                    }
                }
                // Suppliers may omit Image2 while still supplying Image3.
                if (!$found) continue;
            }
        }
        if ($images === [] && !empty($mapping['image'])) {
            $url = $get('image');
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $images[] = $url;
            }
        }

        $attributes = [];
        foreach (XmlProfileMapping::ATTRIBUTE_FIELDS as $field => $name) {
            if (trim((string)($mapping[$field] ?? '')) !== '') {
                $value = $get($field);
                if ($field === 'attribute_origin' && in_array(mb_strtolower($value), ['türkiye','turkiye','turkey'], true)) $value = 'TR';
                if (mb_strlen($value) > 1000) throw new \RuntimeException($name . ' en fazla 1000 karakter olabilir.');
                $attributes[$name] = $value;
            }
        }

        $price = $this->toFloat($get('price'));
        $listPrice = $this->toFloat($get('list_price'));
        $cost = $this->toFloat($get('cost'));
        $currency = strtoupper($get('currency'));
        if ($currency === '' || $currency === 'TRL' || $currency === 'TL') $currency = 'TRY';
        $currencyPrice = 0.0; $currencyCost = 0.0;
        if ($currency !== 'TRY') {
            if (!in_array($currency, ['USD','EUR','GBP'], true)) throw new \RuntimeException('Desteklenmeyen para birimi: '.$currency);
            $rate = $this->exchangeRates->getRate($currency);
            if ($rate <= 0) throw new \RuntimeException($currency.' kuru bulunamadı; fiyat aktarılmadı.');
            $currencyPrice = $price; $currencyCost = $cost;
            $price = round($price * $rate, 2); $cost = round($cost * $rate, 2);
            $listPrice = round($listPrice * $rate, 2);
        }


        return [
            'sku'            => $sku,
            'name'           => $get('name'),
            'description'    => $get('description'),
            'barcode'        => $get('barcode'),
            'brand'          => $get('brand'),
            'category_path'  => $categoryPath,
            'stock_qty'      => max(0, (int) $this->toFloat($get('stock'))),
            'tax_rate'       => $this->toFloat($get('tax')),
            'desi'           => $this->toFloat($get('desi')),
            'weight'         => !empty($mapping['weight']) ? max(0.0, $this->toFloat($get('weight'))) : null,
            'base_price'     => $price,
            'list_price'     => $listPrice > 0 ? $listPrice : $price,
            'cost_price'     => $cost,
            'currency_code'  => $currency,
            'currency_price' => $currencyPrice,
            'currency_cost'  => $currencyCost,
            'images'         => $images,
            'import_attributes' => $attributes,
        ];
    }

    /**
     * "Birim:USD Kur_Deger:46.8906 Tutar:11.72" → parçala
     *
     * @return array{birim: string, kur: float, tutar: float}|null
     */
    private function parseKurDeger(string $raw): ?array
    {
        if ($raw === '' || !preg_match('/Birim:\s*([A-Za-z]+)\s+Kur_Deger:\s*([\d.,]+)\s+Tutar:\s*([\d.,]+)/u', $raw, $m)) {
            return null;
        }
        return [
            'birim' => strtoupper($m[1]),
            'kur'   => $this->toFloat($m[2]),
            'tutar' => $this->toFloat($m[3]),
        ];
    }

    // ─── Upsert ───────────────────────────────────────────

    /**
     * Normalize satırı ürüne çevir (SKU upsert).
     *
     * @param array<string, mixed> $row
     * @return string 'created' | 'updated' | 'skipped'
     */
    private function upsertProduct(array $row, array $profile): string
    {
        // Çok seçenekli Ticimax V2 ürünü: ana kart + varyantlar olarak işlenir
        if (!empty($row['variants'])) {
            return $this->upsertVariantFamily($row, $profile);
        }

        $existing = $this->productModel->findBySku($row['sku']);
        $profileId = (int) $profile['id'];
        $listPrice = (float) ($row['list_price'] ?? 0) > 0 ? (float) $row['list_price'] : (float) $row['base_price'];

        $categoryId = null;
        if (!empty($row['category_path']) && (int) ($profile['create_categories'] ?? 1) === 1) {
            $categoryId = $this->ensureCategoryChain($row['category_path']);
        }

        $brandId = null;
        if ($row['brand'] !== '' && (int) ($profile['create_brands'] ?? 1) === 1) {
            $brandId = $this->ensureBrand($row['brand']);
        }

        // Profil varsayılanları: feed'de marka/kategori yoksa boş bırakma —
        // markasız ürün pazaryerine gönderilemez, kategorisiz ürün eşleşemez.
        if ($brandId === null && ($this->profilAyari['varsayilan_marka_id'] ?? 0) > 0) {
            $brandId = (int) $this->profilAyari['varsayilan_marka_id'];
        }
        if ($categoryId === null && ($this->profilAyari['varsayilan_kategori_id'] ?? 0) > 0) {
            $categoryId = (int) $this->profilAyari['varsayilan_kategori_id'];
        }

        $barkod = $this->barkodUygula((string) $row['barcode'], (string) $row['sku']);
        $kritikStok = (int) ($this->profilAyari['kritik_stok'] ?? 0);

        $images = (int) ($profile['import_images'] ?? 1) === 1 ? $row['images'] : [];

        if ($existing === false) {
            if ($row['name'] === '') {
                return 'skipped';
            }
            $yeni = [
                'sku'            => $row['sku'],
                'barcode'        => $barkod !== '' ? $barkod : null,
                'name'           => mb_substr($row['name'], 0, 490),
                'description'    => $row['description'],
                'category_id'    => $categoryId,
                'brand_id'       => $brandId,
                'base_price'     => $row['base_price'],
                'list_price'     => $listPrice,
                'cost_price'     => $row['cost_price'],
                'currency_code'  => $row['currency_code'],
                'currency_price' => $row['currency_price'],
                'currency_cost'  => $row['currency_cost'],
                'stock_qty'      => $row['stock_qty'],
                'tax_rate'       => $row['tax_rate'] > 0 ? $row['tax_rate'] : 20,
                'desi'           => $row['desi'] > 0 ? $row['desi'] : null,
                'weight'         => $row['weight'] ?? null,
                'main_image'     => $images[0] ?? null,
                'images_json'    => $images !== [] ? json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'source'         => 'xml',
                'source_id'      => (string) $profileId,
                'xml_profile_id' => $profileId,
                'is_active'      => 1,
                'has_variants'   => (int) ($row['has_variants'] ?? 0),
                'parent_id'      => $row['parent_id'] ?? null,
                'variant_attributes' => $row['variant_attributes'] ?? null,
                'missing_price'  => $row['base_price'] > 0 ? 0 : 1,
                'missing_images' => $images !== [] ? 0 : 1,
                'missing_stock'  => $row['stock_qty'] > 0 ? 0 : 1,
            ];
            if ($kritikStok > 0) {
                // 0 ise şema varsayılanı (5) korunur
                $yeni['min_stock_alert'] = $kritikStok;
            }

            $productId = $this->productModel->create($yeni);
            $this->syncImportedAttributes($productId, $row['import_attributes'] ?? []);
            return 'created';
        }

        if ((int) ($profile['update_existing'] ?? 1) !== 1) {
            return 'skipped';
        }

        $data = [
            'name'           => mb_substr($row['name'] !== '' ? $row['name'] : (string) $existing['name'], 0, 490),
            'xml_profile_id' => $profileId,
        ];
        if (isset($row['has_variants'])) {
            $data['has_variants'] = (int) $row['has_variants'];
        }
        if (array_key_exists('parent_id', $row)) {
            $data['parent_id'] = $row['parent_id'];
        }
        if (array_key_exists('variant_attributes', $row)) {
            $data['variant_attributes'] = $row['variant_attributes'];
        }
        if (isset($row['weight'])) $data['weight'] = $row['weight'];
        if ($row['description'] !== '') {
            $data['description'] = $row['description'];
        }
        if ($barkod !== '') {
            $data['barcode'] = $barkod;
        }
        if ($kritikStok > 0) {
            $data['min_stock_alert'] = $kritikStok;
        }
        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }
        if ($brandId !== null) {
            $data['brand_id'] = $brandId;
        }
        // Fiyat güncelleme: feed'de fiyat GEÇERLİ geldiyse yaz. Geçici olarak boş/0
        // gelen üründe mevcut fiyatı/dövizi SIFIRLAMA (veri kaybı) — yalnız eksik işaretle.
        if ((int) ($profile['update_prices'] ?? 1) === 1) {
            if ((float) $row['base_price'] > 0 || (float) $row['currency_price'] > 0) {
                $data['base_price']     = $row['base_price'];
                $data['list_price']     = $listPrice;
                $data['cost_price']     = $row['cost_price'];
                $data['currency_code']  = $row['currency_code'];
                $data['currency_price'] = $row['currency_price'];
                $data['currency_cost']  = $row['currency_cost'];
                $data['missing_price']  = 0;
            } elseif ((float) $existing['base_price'] <= 0) {
                $data['missing_price'] = 1;
            }
        }
        if ((int) ($profile['update_stock'] ?? 1) === 1) {
            $data['stock_qty']     = $row['stock_qty'];
            $data['missing_stock'] = $row['stock_qty'] > 0 ? 0 : 1;
        }
        if ($row['tax_rate'] > 0) {
            $data['tax_rate'] = $row['tax_rate'];
        }
        if ($row['desi'] > 0) {
            $data['desi'] = $row['desi'];
        }
        if ($images !== []) {
            $data['main_image']     = $images[0];
            $data['images_json']    = json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data['missing_images'] = 0;
        }

        // Dirty-check: hiçbir alan gerçekten değişmediyse UPDATE atma (koşulsuz yazım,
        // 50k üründe her koşumda 50k gereksiz UPDATE'e yol açar). name + xml_profile_id
        // her zaman set edildiğinden onları da mevcut değerle karşılaştır.
        $attributesChanged = $this->syncImportedAttributes((int)$existing['id'], $row['import_attributes'] ?? []);
        if (!$this->hasChanges($existing, $data)) {
            if ($attributesChanged) return 'updated';
            return 'skipped';
        }

        $this->productModel->update((int) $existing['id'], $data);
        return 'updated';
    }

    /** Only explicitly mapped XML fields are authoritative; marketplace overrides stay separate. */
    private function syncImportedAttributes(int $productId, array $values): bool
    {
        $changed = false;
        foreach ($values as $name => $value) {
            if (!in_array($name, XmlProfileMapping::ATTRIBUTE_FIELDS, true)) continue;
            if (!isset($this->attributeCache[$name])) {
                $found = $this->db->fetchOne('SELECT id FROM product_attributes WHERE name=? ORDER BY id LIMIT 1', [$name]);
                if ($found === false) {
                    $this->db->query('INSERT INTO product_attributes (name,slug,input_type) VALUES (?,?,?)', [$name,'xml-'.StringHelper::slugify($name),'text']);
                    $this->attributeCache[$name] = (int)$this->db->lastInsertId();
                } else $this->attributeCache[$name] = (int)$found['id'];
            }
            $attributeId = $this->attributeCache[$name];
            $current = $this->productAttributeValues->findBy(['product_id'=>$productId, 'attribute_id'=>$attributeId]);
            if ($value === '') {
                if ($current !== false) { $this->productAttributeValues->delete((int)$current['id']); $changed = true; }
            } elseif ($current === false || ($current['custom_value'] ?? '') !== $value || $current['attribute_value_id'] !== null) {
                $this->productAttributeValues->setProductAttribute($productId, $attributeId, null, $value);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Çok seçenekli Ticimax V2 ürününü ana kart + varyant satırları olarak işler.
     * Ana kart parent SKU ('KART-{UrunKartiID}') ile, varyantlar kendi StokKodu ile
     * eşleşir; tekrar koşumda aynı kayıtlar güncellenir (varyantlı eşleştirme).
     *
     * @param array<string, mixed> $row 'variants' listesi dolu parent satırı
     */
    private function upsertVariantFamily(array $row, array $profile): string
    {
        $variants = $row['variants'];
        unset($row['variants']);

        // 1) Ana kart
        $row['has_variants'] = 1;
        $outcome = $this->upsertProduct($row, $profile);

        $parent = $this->productModel->findBySku($row['sku']);
        if ($parent === false) {
            return $outcome; // ana kart yazılamadıysa varyant bağlanamaz
        }
        $parentId = (int) $parent['id'];

        // 2) Varyantlar — ad: "Ürün Adı 36 / Siyah"; kategori/marka/görsel anadan
        foreach ($variants as $v) {
            $attrs = $v['attrs'] ?? [];
            unset($v['attrs']);

            $suffix = $attrs !== [] ? ' ' . implode(' / ', array_values($attrs)) : '';
            $vRow = $v + [
                'name'          => trim($row['name'] . $suffix),
                'description'   => $row['description'],
                'brand'         => $row['brand'],
                'category_path' => $row['category_path'],
                'images'        => $row['images'],
            ];
            $vRow['has_variants'] = 0;
            $vRow['parent_id'] = $parentId;
            $vRow['variant_attributes'] = $attrs !== []
                ? json_encode($attrs, JSON_UNESCAPED_UNICODE)
                : null;

            $this->upsertProduct($vRow, $profile);
        }

        return $outcome;
    }

    /**
     * $data'daki alanların mevcut satırdan farklı olup olmadığını sayısal/metinsel
     * olarak karşılaştır. (DECIMAL alanlar string döndüğünden gevşek karşılaştırma.)
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     */
    private function hasChanges(array $existing, array $data): bool
    {
        $numeric = ['base_price', 'list_price', 'cost_price', 'currency_price', 'currency_cost', 'stock_qty', 'tax_rate', 'desi', 'missing_price', 'missing_stock', 'missing_images', 'category_id', 'brand_id', 'xml_profile_id', 'has_variants', 'parent_id', 'min_stock_alert'];
        foreach ($data as $col => $new) {
            if (!array_key_exists($col, $existing)) {
                return true; // yeni kolon → değişiklik say
            }
            $old = $existing[$col];
            if (in_array($col, $numeric, true)) {
                if ((float) $old !== (float) $new) {
                    return true;
                }
            } elseif ((string) $old !== (string) $new) {
                return true;
            }
        }
        return false;
    }

    /**
     * "A|B|C" kategori zincirini oluştur/getir → en derin kategorinin id'si.
     *
     * @param array<int, string> $path
     */
    private function ensureCategoryChain(array $path): ?int
    {
        $path = array_slice($path, 0, 5); // aşırı derin ağaçlara sınır
        if ($path === []) {
            return null;
        }

        $parentId = null;
        $walked = [];
        foreach ($path as $name) {
            $name = mb_substr($name, 0, 290);
            $walked[] = $name;
            $walkKey = implode('|', $walked);

            if (isset($this->categoryCache[$walkKey])) {
                $parentId = $this->categoryCache[$walkKey];
                continue;
            }

            $row = $this->findCategory($name, $parentId);
            if ($row !== false) {
                $parentId = (int) $row['id'];
            } else {
                $parentId = $this->categoryModel->create([
                    'parent_id' => $parentId,
                    'name'      => $name,
                    'slug'      => StringHelper::slugify($name),
                    'full_path' => implode(' > ', $walked),
                    'is_active' => 1,
                ]);
            }
            $this->categoryCache[$walkKey] = $parentId;
        }

        return $parentId;
    }

    /**
     * Aynı parent altında isimle kategori ara (case-insensitive)
     *
     * @return array<string, mixed>|false
     */
    private function findCategory(string $name, ?int $parentId): array|false
    {
        $storeId = $this->tenant->currentStoreId();
        if ($parentId === null) {
            return $this->db->fetchOne(
                "SELECT id FROM categories WHERE store_id = :sid AND parent_id IS NULL AND LOWER(name) = LOWER(:name) LIMIT 1",
                ['sid' => $storeId, 'name' => $name]
            );
        }
        return $this->db->fetchOne(
            "SELECT id FROM categories WHERE store_id = :sid AND parent_id = :pid AND LOWER(name) = LOWER(:name) LIMIT 1",
            ['sid' => $storeId, 'pid' => $parentId, 'name' => $name]
        );
    }

    /**
     * Barkoda profil ön ekini uygula.
     *
     * İki tedarikçinin aynı barkodu göndermesi tek ürüne çakışmaya yol açıyordu;
     * ön ek kaynağı ayırır. Ön ek zaten uygulanmışsa tekrar eklenmez (idempotent).
     */
    /**
     * Barkod: ön ek uygula; barkod YOKSA stok kodundan türet.
     *
     * Tedarikçi feed'lerinin çoğunda barkod etiketi hiç bulunmuyor. Barkodsuz ürün
     * pazaryerlerine gönderilemez (Trendyol zorunlu tutar) ve kullanıcı bunu ancak
     * gönderim reddedildiğinde fark ediyordu. Boş barkod zaten hiçbir işe yaramadığı
     * için stok kodundan türetmek güvenlidir; stok kodu ürün başına benzersizdir.
     */
    private function barkodUygula(string $barcode, string $sku = ''): string
    {
        $onek = (string) ($this->profilAyari['barkod_onek'] ?? '');

        if ($barcode === '') {
            $barcode = trim($sku);
            if ($barcode === '') {
                return '';
            }
        }

        if ($onek === '' || str_starts_with($barcode, $onek)) {
            return mb_substr($barcode, 0, 100);
        }

        return mb_substr($onek . $barcode, 0, 100);
    }

    private function ensureBrand(string $name): int
    {
        $key = mb_strtolower($name);
        if (!isset($this->brandCache[$key])) {
            $this->brandCache[$key] = $this->brandModel->findOrCreate(mb_substr($name, 0, 190));
        }
        return $this->brandCache[$key];
    }

    // ─── Yardımcılar ──────────────────────────────────────

    private function openReader(string $file): \XMLReader
    {
        $reader = new \XMLReader();
        // LIBXML_NONET: dış kaynak/XXE erişimini kapat
        if (!@$reader->open($file, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('XML dosyası açılamadı.');
        }
        return $reader;
    }

    private function toFloat(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }

        $hasComma = str_contains($raw, ',');
        $hasDot   = str_contains($raw, '.');

        if ($hasComma && $hasDot) {
            // İki ayraç birden: SON görülen ondalık ayracıdır, diğeri binlik ayracıdır.
            // "1.234,56" → 1234.56 (TR) | "1,234.56" → 1234.56 (US)
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);   // nokta binlik
                $raw = str_replace(',', '.', $raw);  // virgül ondalık
            } else {
                $raw = str_replace(',', '', $raw);   // virgül binlik, nokta ondalık
            }
        } elseif ($hasComma) {
            // Yalnız virgül: ondalık ayracı say ("46,8906" → 46.8906)
            $raw = str_replace(',', '.', $raw);
        } elseif ($hasDot && substr_count($raw, '.') > 1) {
            // Çok noktalı, virgülsüz ("1.234.567" → 1234567): tüm noktalar binlik
            $raw = str_replace('.', '', $raw);
        }
        // Tek nokta ("549.557832") olduğu gibi bırakılır → ondalık

        return (float) preg_replace('/[^0-9.\-]/', '', $raw);
    }

    /**
     * SSRF koruması: yalnız http(s) şemasına ve HERKESE AÇIK bir IP'ye çözülen
     * host'lara izin ver. Loopback / özel / link-local / bulut metadata (169.254.169.254)
     * adresleri reddedilir. downloadToTemp'in hem doğrudan çağrısında hem de her
     * yönlendirme adımında uygulanır.
     */
    private function assertPublicUrl(string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Geçersiz XML URL adresi (yalnız http/https).');
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('XML URL adresi çözümlenemedi.');
        }
        $host = trim($host, '[]'); // IPv6 köşeli parantez

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip']))   { $ips[] = $r['ip']; }
                if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
            }
            if ($ips === []) {
                $resolved = @gethostbynamel($host);
                if (is_array($resolved)) { $ips = $resolved; }
            }
        }

        if ($ips === []) {
            throw new \RuntimeException('XML kaynağının adresi çözümlenemedi: ' . $host);
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \RuntimeException('Güvenlik: iç ağ / özel adreslere erişime izin verilmez.');
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        // Genel (public) aralık dışı her şey reddedilir:
        // özel (RFC1918/ULA), rezerve ve loopback/link-local aralıkları elenir.
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
