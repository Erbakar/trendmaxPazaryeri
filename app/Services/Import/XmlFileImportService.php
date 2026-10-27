<?php
/**
 * OzyConnect Panel — XmlFileImportService
 *
 * "İçe / Dışa Aktarma" ekranındaki XML içe aktarımının motoru.
 *
 *  - Kaynak: yüklenen DOSYA ya da doğrudan XML LİNKİ (piyasadaki entegratörler gibi;
 *    kullanıcının XML'i tarayıcıda açıp Ctrl+S ile kaydetmesine gerek yok)
 *  - XMLReader ile AKIŞ halinde okur — 25 MB / 10.000 ürünlük feed'ler belleğe
 *    yüklenmez (simplexml_load_string tüm belgeyi DOM'a açar; büyük feed'lerde
 *    paylaşımlı hosting'de süreç şişer)
 *  - Ürün düğümünü (item) otomatik bulur: <Products><Product>, <root><item>,
 *    <rss><channel><item> gibi yapılar dahil
 *  - Alan eşlemesi kullanıcıdan gelir; kategori ve marka İSİMLE eşleşir/oluşturulur
 *    (ham ID yazımı yabancı anahtar hatası üretiyordu)
 *  - Görseller: Image1..N / Resim_1..N / <images><image> desenlerini toplar
 *  - Parça parça (chunk) işler; her istek kendi zaman bütçesiyle sınırlıdır, böylece
 *    max_execution_time dolmadan ilerleme kaydedilir
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2026 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.1.0
 * @since      2026-08-25
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

final class XmlFileImportService
{
    /** Kaynak dosyanın üst sınırı (bayt) — 250 MB */
    private const MAX_SOURCE_BYTES = 262144000;

    /** Onarım (belleğe alma) için üst sınır — 64 MB */
    private const REPAIR_MAX_BYTES = 67108864;

    /** Bir üründen alınacak en fazla görsel */
    private const MAX_IMAGES = 12;

    /** Kategori zincirinde izin verilen en fazla seviye */
    private const MAX_CATEGORY_DEPTH = 5;

    /** products.description TEXT sütunu (65535 bayt) için güvenli sınır */
    private const DESCRIPTION_MAX_BYTES = 64000;

    /** Geçici kaynak dosyalarının yaşam süresi (saniye) */
    private const TEMP_TTL = 86400;

    /**
     * Eşleştirilmesi ZORUNLU alanlar.
     *
     * Bu liste ekranda da uygulanıyordu ama JavaScript içinde ayrı bir sabit
     * olarak duruyordu. Tek kaynaktan beslensin diye buraya taşındı ve
     * çözümleme yanıtıyla birlikte ekrana gönderiliyor.
     */
    public const REQUIRED_FIELDS = ['sku', 'name'];

    /** Eşlenebilir sistem alanları — anahtar => etiket */
    public const SYSTEM_FIELDS = [
        'sku'         => 'SKU / Stok Kodu',
        'name'        => 'Ürün Adı',
        'barcode'     => 'Barkod',
        'description' => 'Açıklama',
        'base_price'  => 'Satış Fiyatı',
        'list_price'  => 'Liste Fiyatı',
        'cost_price'  => 'Alış / Maliyet',
        'stock_qty'   => 'Stok',
        'tax_rate'    => 'KDV %',
        'weight'      => 'Ağırlık (kg)',
        'desi'        => 'Desi',
        'image'       => 'Görsel (1. görsel)',
        'brand'       => 'Marka (ad)',
        'category_1'  => 'Kategori — 1. seviye',
        'category_2'  => 'Kategori — 2. seviye',
        'category_3'  => 'Kategori — 3. seviye',
    ];

    /**
     * Sunucunun gerçek yükleme sınırı (bayt): upload_max_filesize ile post_max_size'ın küçüğü.
     *
     * post_max_size aşılırsa PHP $_POST'u tamamen boşaltır (CSRF hatası gibi görünür) —
     * bu yüzden sınır tarayıcı tarafında da uygulanır. ImportController'da private bir
     * metottu; XML ekranı Ayarlar üzerinden de render edildiği için ortak yere alındı.
     */
    public static function uploadLimitBytes(): int
    {
        $toBytes = static function (string $value): int {
            $value = trim($value);
            if ($value === '' || $value === '0') {
                return 0;
            }

            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g'     => $number * 1073741824,
                'm'     => $number * 1048576,
                'k'     => $number * 1024,
                default => $number,
            };
        };

        $limits = array_filter([
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')),
        ]);

        return $limits === [] ? 0 : (int) min($limits);
    }

    /** @var array<string, int> Kategori yolu → id (istek içi önbellek) */
    private array $categoryCache = [];

    /** @var array<string, int> Marka adı → id (istek içi önbellek) */
    private array $brandCache = [];

    public function __construct(
        private readonly Product $productModel,
        private readonly Brand $brandModel,
        private readonly Category $categoryModel,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
    ) {}

    // ─── Kaynak hazırlama ─────────────────────────────────

    /**
     * Yüklenen dosyayı geçici depoya al ve jeton üret.
     *
     * @param array<string, mixed> $file $_FILES girdisi
     * @return string Jeton
     */
    public function stageUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage($error));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new \RuntimeException('Yüklenen dosya sunucuda bulunamadı.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_SOURCE_BYTES) {
            throw new \RuntimeException('XML dosyası çok büyük (en fazla 250 MB).');
        }

        $token = $this->newToken();
        $target = $this->sourcePath($token);

        // move_uploaded_file yalnız gerçek HTTP yüklemelerinde çalışır; CLI/testte copy'ye düş
        $moved = is_uploaded_file($tmpName)
            ? @move_uploaded_file($tmpName, $target)
            : @copy($tmpName, $target);

        if ($moved === false) {
            throw new \RuntimeException('XML dosyası geçici klasöre taşınamadı (storage yazma izni?).');
        }

        $this->writeMeta($token, [
            'source'        => 'file',
            'original_name' => $this->safeName((string) ($file['name'] ?? 'import.xml')),
            'size'          => (int) ($file['size'] ?? filesize($target) ?: 0),
        ]);

        return $token;
    }

    /**
     * XML linkini indirip geçici depoya al.
     */
    public function stageUrl(string $url): string
    {
        $url = trim($url);
        $this->assertPublicUrl($url);

        $token  = $this->newToken();
        $target = $this->sourcePath($token);

        $this->downloadTo($url, $target);

        $size = (int) (@filesize($target) ?: 0);
        if ($size === 0) {
            @unlink($target);
            throw new \RuntimeException('XML kaynağı boş döndü — linki tarayıcıda açıp kontrol edin.');
        }
        if ($size > self::MAX_SOURCE_BYTES) {
            @unlink($target);
            throw new \RuntimeException('XML kaynağı çok büyük (en fazla 250 MB).');
        }

        $this->writeMeta($token, [
            'source'        => 'url',
            'original_name' => $this->safeName(basename((string) parse_url($url, PHP_URL_PATH)) ?: 'feed.xml'),
            'source_url'    => $url,
            'size'          => $size,
        ]);

        return $token;
    }

    // ─── Çözümleme ────────────────────────────────────────

    /**
     * Kaynağı çözümle: ürün düğümü, ürün sayısı, etiket listesi, örnek değerler ve
     * önerilen alan eşlemesi.
     *
     * @return array{
     *     token: string, item_path: string, item_tag: string, count: int,
     *     tags: array<int, string>, candidates: array<int, array{path: string, count: int}>,
     *     sample: array<string, string>, suggested: array<string, string>,
     *     original_name: string, size: int, repaired: bool
     * }
     */
    public function analyze(string $token): array
    {
        $meta = $this->readMeta($token);
        $file = $this->sourcePath($token);

        // Tarayıcıda kaydedilmiş sayfalar GEÇERLİ XML olarak da ayrışabiliyor; o durumda
        // hata oluşmadığı için onarım tetiklenmez ve sarmalayıcı <div> ürün düğümü sanılır.
        // Bu yüzden HTML sarmalı ÖNCEDEN, ayrıştırmaya bakılmaksızın sökülür.
        $repaired = $this->looksLikeHtml($file) && $this->repairSource($file);

        $structure = $this->detectStructure($file);

        // Düğüm bulunamadıysa ya da belge yarıda bozulduysa kaynağı onarıp yeniden dene
        // (tarayıcıda Ctrl+S ile kaydedilen HTML sarmalı, BOM, yanlış kodlama, kaçırılmamış &)
        if (!$repaired && ($structure['item_path'] === '' || $structure['fatal'])) {
            if ($this->repairSource($file)) {
                $retry = $this->detectStructure($file);
                if ($retry['item_path'] !== '' && $retry['count'] >= $structure['count']) {
                    $structure = $retry;
                    $repaired = true;
                }
            }
        }

        if ($structure['item_path'] === '') {
            throw new \RuntimeException(
                'XML okunamadı — dosyada ürün listesi bulunamadı.'
                . ($structure['error'] !== '' ? ' Ayrıntı: ' . $structure['error'] : '')
            );
        }

        [$tags, $sample] = $this->sampleItems($file, $structure['item_path']);
        if ($tags === []) {
            throw new \RuntimeException('Ürün düğümünün altında alan (etiket) bulunamadı.');
        }

        $meta['item_path'] = $structure['item_path'];
        $meta['item_tag']  = $structure['item_tag'];
        $meta['count']     = $structure['count'];
        $meta['tags']      = $tags;
        $meta['repaired']  = $repaired;
        $this->putMeta($token, $meta);

        return [
            'token'         => $token,
            // Alan listesi yanıtın içinde de gider: ekran hangi kontrolcünün
            // render ettiğinden bağımsız olarak eşleştirme satırlarını kurabilsin.
            'fields'        => self::SYSTEM_FIELDS,
            'required'      => self::REQUIRED_FIELDS,
            'item_path'     => $structure['item_path'],
            'item_tag'      => $structure['item_tag'],
            'count'         => $structure['count'],
            'tags'          => $tags,
            'candidates'    => $structure['candidates'],
            'sample'        => $sample,
            'suggested'     => $this->suggestMapping($tags),
            'original_name' => (string) ($meta['original_name'] ?? 'import.xml'),
            'size'          => (int) ($meta['size'] ?? 0),
            'repaired'      => $repaired,
            'warning'       => $structure['fatal']
                ? sprintf(
                    'XML dosyası eksik ya da bozuk görünüyor — yalnız okunabilen %d ürün aktarılacak. (%s)',
                    $structure['count'],
                    $structure['error'] !== '' ? $structure['error'] : 'biçim hatası'
                )
                : '',
        ];
    }

    /**
     * Kullanıcı ürün düğümünü elle seçtiyse metayı güncelle.
     *
     * @return array{tags: array<int, string>, sample: array<string, string>, count: int}
     */
    public function useItemPath(string $token, string $itemPath): array
    {
        $meta = $this->readMeta($token);
        $file = $this->sourcePath($token);

        $count = $this->countItems($file, $itemPath);
        if ($count === 0) {
            throw new \RuntimeException('Seçilen düğüm bu XML içinde bulunamadı.');
        }

        [$tags, $sample] = $this->sampleItems($file, $itemPath);

        $segments = explode('/', $itemPath);
        $meta['item_path'] = $itemPath;
        $meta['item_tag']  = (string) end($segments);
        $meta['count']     = $count;
        $meta['tags']      = $tags;
        $this->putMeta($token, $meta);

        return [
            'tags'     => $tags,
            'sample'   => $sample,
            'count'    => $count,
            'fields'   => self::SYSTEM_FIELDS,
            'required' => self::REQUIRED_FIELDS,
        ];
    }

    // ─── İçe aktarma ──────────────────────────────────────

    /**
     * Kaynağın bir bölümünü işle.
     *
     * @param array<string, mixed> $rawMap  Kullanıcı alan eşlemesi
     * @param string               $mode    skip | update | overwrite
     * @param int                  $offset  Kaçıncı üründen devam edilecek
     * @param int                  $limit   Bu isteğe kadar kaç ürün işlenecek
     * @param float                $budget  Bu isteğin saniye cinsinden zaman bütçesi
     * @return array{
     *     offset: int, processed: int, created: int, updated: int, skipped: int,
     *     failed: int, total: int, done: bool, errors: array<int, string>, message: string
     * }
     */
    public function importChunk(
        string $token,
        array $rawMap,
        string $mode,
        int $offset,
        int $limit = 200,
        float $budget = 15.0
    ): array {
        $meta = $this->readMeta($token);
        $map  = $this->normalizeMap($rawMap);

        if (empty($meta['item_path'])) {
            throw new \RuntimeException('Kaynak henüz çözümlenmedi — sayfayı yenileyip tekrar deneyin.');
        }

        $mode   = in_array($mode, ['skip', 'update', 'overwrite'], true) ? $mode : 'skip';
        $offset = max(0, $offset);
        $limit  = max(1, min(1000, $limit));
        $total  = (int) ($meta['count'] ?? 0);

        if ($offset === 0) {
            $meta['job_id']   = $this->startJob($meta, $mode);
            $meta['created']  = 0;
            $meta['updated']  = 0;
            $meta['skipped']  = 0;
            $meta['failed']   = 0;
            $meta['errors']   = [];
            $meta['started']  = time();
        }

        $stats = $this->processRange(
            $this->sourcePath($token),
            (string) $meta['item_path'],
            $map,
            $mode,
            $offset,
            $limit,
            $budget
        );

        $meta['created'] = (int) ($meta['created'] ?? 0) + $stats['created'];
        $meta['updated'] = (int) ($meta['updated'] ?? 0) + $stats['updated'];
        $meta['skipped'] = (int) ($meta['skipped'] ?? 0) + $stats['skipped'];
        $meta['failed']  = (int) ($meta['failed'] ?? 0) + $stats['failed'];

        $errors = array_slice(array_merge((array) ($meta['errors'] ?? []), $stats['errors']), 0, 100);
        $meta['errors'] = $errors;

        $newOffset = $offset + $stats['processed'];
        $done = $stats['processed'] === 0 || ($total > 0 && $newOffset >= $total);

        $meta['offset'] = $newOffset;
        $this->putMeta($token, $meta);

        if ($done) {
            $this->finishJob($meta);
            $this->discard($token);
        }

        return [
            'offset'    => $newOffset,
            'processed' => $stats['processed'],
            'created'   => (int) $meta['created'],
            'updated'   => (int) $meta['updated'],
            'skipped'   => (int) $meta['skipped'],
            'failed'    => (int) $meta['failed'],
            'total'     => $total,
            'done'      => $done,
            'errors'    => array_slice($errors, 0, 10),
            'message'   => $this->summaryMessage($meta),
        ];
    }

    /**
     * Tümünü tek istekte işle (JavaScript devre dışıysa kullanılan yedek yol).
     *
     * @param array<string, mixed> $rawMap
     * @return array{created: int, updated: int, skipped: int, failed: int, total: int, message: string}
     */
    public function importAll(string $token, array $rawMap, string $mode): array
    {
        $offset = 0;
        $result = [];

        do {
            $result = $this->importChunk($token, $rawMap, $mode, $offset, 500, 0.0);
            if ($result['offset'] <= $offset) {
                break;
            }
            $offset = $result['offset'];
        } while (!$result['done']);

        return [
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'failed'  => $result['failed'] ?? 0,
            'total'   => $result['total'] ?? 0,
            'message' => $result['message'] ?? '',
        ];
    }

    /**
     * Geçici kaynağı ve metasını sil.
     */
    public function discard(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }
        @unlink($this->sourcePath($token));
        @unlink($this->metaPath($token));
    }

    // ─── Ana işleme döngüsü ───────────────────────────────

    /**
     * @param array<string, string> $map
     * @return array{processed: int, created: int, updated: int, skipped: int, failed: int, errors: array<int, string>}
     */
    private function processRange(
        string $file,
        string $itemPath,
        array $map,
        string $mode,
        int $offset,
        int $limit,
        float $budget
    ): array {
        $processed = 0; $created = 0; $updated = 0; $skipped = 0; $failed = 0;
        $errors = [];

        $this->categoryCache = [];
        $this->brandCache = [];

        $deadline = $budget > 0 ? microtime(true) + $budget : 0.0;
        $reader   = $this->openReader($file);
        $doc      = new \DOMDocument('1.0', 'UTF-8');

        $segments = explode('/', $itemPath);
        $itemTag  = (string) end($segments);
        $depth    = count($segments) - 1;

        $seen  = 0;
        $inTx  = false;
        $txCreated = 0; $txUpdated = 0; $txSkipped = 0;

        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType !== \XMLReader::ELEMENT
                    || $reader->name !== $itemTag
                    || $reader->depth !== $depth
                ) {
                    $ok = $reader->read();
                    continue;
                }

                if ($seen < $offset) {
                    $seen++;
                    $ok = $reader->next();
                    continue;
                }

                if ($processed >= $limit || ($deadline > 0.0 && microtime(true) >= $deadline)) {
                    break;
                }

                $node = $reader->expand($doc);
                $ok = $reader->next();
                $seen++;

                $processed++;

                if (!($node instanceof \DOMElement)) {
                    $failed++;
                    continue;
                }

                $item = $node;
                $sku = '';

                try {
                    $row = $this->buildRow($item, $map);
                    $sku = $row['sku'];

                    if ($sku === '') {
                        $failed++;
                        $this->pushError($errors, 'Satır ' . $seen . ': SKU boş');
                        continue;
                    }
                    if ($row['name'] === '') {
                        $failed++;
                        $this->pushError($errors, $sku . ': ürün adı boş');
                        continue;
                    }

                    if (!$inTx) {
                        $this->db->beginTransaction();
                        $inTx = true;
                        $txCreated = $txUpdated = $txSkipped = 0;
                    }

                    $outcome = $this->upsert($row, $mode);
                    if ($outcome === 'created') {
                        $created++; $txCreated++;
                    } elseif ($outcome === 'updated') {
                        $updated++; $txUpdated++;
                    } else {
                        $skipped++; $txSkipped++;
                    }
                } catch (\Throwable $e) {
                    // Sunucu tüm işlemi geri aldıysa (deadlock/kilit) batch sayaçlarını geri sar
                    $serverAborted = $inTx && !$this->db->getConnection()->inTransaction();
                    if ($serverAborted) {
                        $created -= $txCreated; $updated -= $txUpdated; $skipped -= $txSkipped;
                        $failed  += $txCreated + $txUpdated + $txSkipped + 1;
                        try { $this->db->rollBack(); } catch (\Throwable) {}
                        $inTx = false;
                        $txCreated = $txUpdated = $txSkipped = 0;
                        $this->pushError($errors, ($sku !== '' ? $sku . ': ' : '') . 'İşlem geri alındı (kilit): ' . $this->shortError($e));
                    } else {
                        $failed++;
                        $this->pushError($errors, ($sku !== '' ? $sku . ': ' : '') . $this->shortError($e));
                    }
                }
            }

            if ($inTx) {
                $this->db->commit();
                $inTx = false;
            }
        } catch (\Throwable $e) {
            if ($inTx) {
                try { $this->db->rollBack(); } catch (\Throwable) {}
            }
            $reader->close();
            throw $e;
        }

        $reader->close();

        return [
            'processed' => $processed,
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'errors'    => $errors,
        ];
    }

    /**
     * XML düğümünü normalize ürün satırına çevir.
     *
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    private function buildRow(\DOMElement $item, array $map): array
    {
        $index = $this->indexChildren($item);
        $get = fn(string $field): string => $this->valueFrom($item, $index, (string) ($map[$field] ?? ''));

        $categoryPath = [];
        foreach (['category_1', 'category_2', 'category_3'] as $level) {
            $raw = $get($level);
            if ($raw === '') {
                continue;
            }
            foreach ($this->splitCategory($raw) as $part) {
                $categoryPath[] = $part;
            }
        }

        $taxRaw = $get('tax_rate');
        $tax = $taxRaw !== '' ? $this->toFloat($taxRaw) : -1.0;

        return [
            'sku'           => mb_substr($this->clean($get('sku')), 0, 100),
            'name'          => mb_substr($this->clean($get('name')), 0, 490),
            'barcode'       => mb_substr($this->clean($get('barcode')), 0, 100),
            'description'   => $this->limitBytes($get('description'), self::DESCRIPTION_MAX_BYTES),
            // Sütun sınırlarına kırp: bozuk tek bir satır yüzünden INSERT patlamasın
            'base_price'    => $this->clamp($this->toFloat($get('base_price')), 9999999999.99),
            'list_price'    => $this->clamp($this->toFloat($get('list_price')), 9999999999.99),
            'cost_price'    => $this->clamp($this->toFloat($get('cost_price')), 9999999999.99),
            'stock_qty'     => (int) $this->clamp((float) round($this->toFloat($get('stock_qty'))), 2147483647.0),
            'tax_rate'      => ($tax >= 0 && $tax <= 100) ? $tax : -1.0,
            'weight'        => $this->clamp($this->toFloat($get('weight')), 99999.999),
            'desi'          => XmlDesiValue::parse($get('desi')),
            'brand'         => mb_substr($this->clean($get('brand')), 0, 190),
            'category_path' => array_slice($categoryPath, 0, self::MAX_CATEGORY_DEPTH),
            'images'        => $this->collectImages($item, $index, (string) ($map['image'] ?? '')),
            'has_price'     => $map['base_price'] !== '' && $get('base_price') !== '',
            'has_stock'     => $map['stock_qty'] !== '' && $get('stock_qty') !== '',
        ];
    }

    /**
     * SKU ile ekle/güncelle.
     *
     * @param array<string, mixed> $row
     * @return string created | updated | skipped
     */
    private function upsert(array $row, string $mode): string
    {
        $existing = $this->productModel->findBySku($row['sku']);

        if ($existing !== false && $mode === 'skip') {
            return 'skipped';
        }

        $categoryId = $row['category_path'] !== [] ? $this->ensureCategoryChain($row['category_path']) : null;
        $brandId    = $row['brand'] !== '' ? $this->ensureBrand($row['brand']) : null;

        $images    = $row['images'];
        $mainImage = $images[0] ?? null;
        if (is_string($mainImage) && mb_strlen($mainImage) > 500) {
            $mainImage = null;
        }

        // Barkod yoksa stok kodundan türetilir: tedarikçi feed'lerinin çoğunda barkod
        // etiketi bulunmuyor ve barkodsuz ürün pazaryerine gönderilemiyor.
        $barkod = $row['barcode'] !== '' ? $row['barcode'] : trim((string) $row['sku']);
        $barkod = $barkod !== '' ? mb_substr($barkod, 0, 100) : null;

        $listPrice = $row['list_price'] > 0 ? $row['list_price'] : $row['base_price'];
        $taxRate   = $row['tax_rate'] >= 0 ? $row['tax_rate'] : 20.0;

        if ($existing === false) {
            $this->productModel->create([
                'sku'            => $row['sku'],
                'barcode'        => $barkod,
                'name'           => $row['name'],
                'description'    => $row['description'] !== '' ? $row['description'] : null,
                'category_id'    => $categoryId,
                'brand_id'       => $brandId,
                'base_price'     => $row['base_price'],
                'list_price'     => $listPrice,
                'cost_price'     => $row['cost_price'],
                'stock_qty'      => $row['stock_qty'],
                'tax_rate'       => $taxRate,
                'weight'         => $row['weight'] > 0 ? $row['weight'] : null,
                'desi'           => $row['desi'] > 0 ? $row['desi'] : null,
                'main_image'     => $mainImage,
                'images_json'    => $images !== [] ? $this->encodeImages($images) : null,
                'source'         => 'xml',
                'is_active'      => 1,
                'missing_price'  => $row['base_price'] > 0 ? 0 : 1,
                'missing_images' => $images !== [] ? 0 : 1,
                'missing_stock'  => $row['stock_qty'] > 0 ? 0 : 1,
            ]);
            return 'created';
        }

        $overwrite = $mode === 'overwrite';
        $data = [];

        $this->setIf($data, 'name', $row['name'], true);
        $this->setIf($data, 'barcode', $barkod, $overwrite || $barkod !== null);
        $this->setIf($data, 'description', $row['description'], $overwrite || $row['description'] !== '');

        if ($categoryId !== null) {
            $data['category_id'] = $categoryId;
        }
        if ($brandId !== null) {
            $data['brand_id'] = $brandId;
        }

        // Fiyat/stok: feed'de geçerli değer geldiyse yaz. Boş geldiğinde mevcut
        // veriyi SIFIRLAMA (geçici olarak eksik gelen feed veri kaybettirmesin).
        if ($row['has_price'] && ($row['base_price'] > 0 || $overwrite)) {
            $data['base_price']    = $row['base_price'];
            $data['list_price']    = $listPrice;
            $data['missing_price'] = $row['base_price'] > 0 ? 0 : 1;
        }
        if ($row['cost_price'] > 0 || ($overwrite && $row['cost_price'] >= 0)) {
            $data['cost_price'] = $row['cost_price'];
        }
        if ($row['has_stock']) {
            $data['stock_qty']     = $row['stock_qty'];
            $data['missing_stock'] = $row['stock_qty'] > 0 ? 0 : 1;
        }
        if ($row['tax_rate'] >= 0) {
            $data['tax_rate'] = $row['tax_rate'];
        }
        if ($row['weight'] > 0) {
            $data['weight'] = $row['weight'];
        }
        if ($row['desi'] > 0) {
            $data['desi'] = $row['desi'];
        }
        if ($images !== []) {
            $data['main_image']     = $mainImage;
            $data['images_json']    = $this->encodeImages($images);
            $data['missing_images'] = 0;
        }

        if ($data === [] || !$this->hasChanges($existing, $data)) {
            return 'skipped';
        }

        $this->productModel->update((int) $existing['id'], $data);
        return 'updated';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setIf(array &$data, string $column, mixed $value, bool $condition): void
    {
        if ($condition) {
            $data[$column] = $value;
        }
    }

    /**
     * Gerçekten değişen alan var mı? (Yoksa gereksiz UPDATE atmayalım.)
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $data
     */
    private function hasChanges(array $existing, array $data): bool
    {
        $numeric = [
            'base_price', 'list_price', 'cost_price', 'stock_qty', 'tax_rate',
            'weight', 'desi', 'category_id', 'brand_id',
            'missing_price', 'missing_stock', 'missing_images',
        ];

        foreach ($data as $column => $new) {
            if (!array_key_exists($column, $existing)) {
                return true;
            }
            $old = $existing[$column];

            if (in_array($column, $numeric, true)) {
                if ((float) $old !== (float) $new) {
                    return true;
                }
            } elseif ((string) $old !== (string) $new) {
                return true;
            }
        }

        return false;
    }

    // ─── Alan okuma ───────────────────────────────────────

    /**
     * Düğümün doğrudan alt elemanlarını adlarına göre indeksle.
     * Ad-alanlı etiketler (g:sku) hem "g:sku" hem "sku" anahtarıyla bulunur.
     *
     * @return array<string, array<int, \DOMElement>>
     */
    private function indexChildren(\DOMElement $item): array
    {
        $index = [];

        foreach ($item->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }

            $index[mb_strtolower($child->nodeName)][] = $child;
            if ($child->localName !== null && $child->localName !== $child->nodeName) {
                $index[mb_strtolower($child->localName)][] = $child;
            }
        }

        return $index;
    }

    /**
     * Eşlenen ifadeyi düğümden oku. Desteklenen biçimler:
     *   Etiket            → <Etiket>
     *   Ust/Alt           → <Ust><Alt>
     *   @nitelik          → düğümün niteliği
     *   Etiket@nitelik    → <Etiket nitelik="...">
     *
     * @param array<string, array<int, \DOMElement>> $index
     */
    private function valueFrom(\DOMElement $item, array $index, string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            return '';
        }

        $attribute = '';
        if (str_contains($expression, '@')) {
            [$expression, $attribute] = array_pad(explode('@', $expression, 2), 2, '');
            $expression = trim($expression);
            $attribute  = trim($attribute);
        }

        $node = $item;
        if ($expression !== '') {
            $segments = explode('/', $expression);

            $first = $index[mb_strtolower(trim($segments[0]))][0] ?? null;
            if ($first === null) {
                return '';
            }
            $node = $first;

            foreach (array_slice($segments, 1) as $segment) {
                $node = $this->firstChildNamed($node, trim($segment));
                if ($node === null) {
                    return '';
                }
            }
        }

        return $attribute !== ''
            ? trim($node->getAttribute($attribute))
            : trim($node->textContent);
    }

    private function firstChildNamed(\DOMElement $parent, string $name): ?\DOMElement
    {
        if ($name === '') {
            return $parent;
        }

        foreach ($parent->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            if (strcasecmp($child->nodeName, $name) === 0
                || ($child->localName !== null && strcasecmp($child->localName, $name) === 0)
            ) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Görselleri topla: eşlenen etiket + aynı önekli kardeşler (Image1..N),
     * tekrar eden aynı adlı etiketler ve <images><image> yapısı.
     *
     * @param array<string, array<int, \DOMElement>> $index
     * @return array<int, string>
     */
    /**
     * Görsel adresini panelde gösterilebilir hale getir.
     *
     * Panelin güvenlik başlığı (CSP) `img-src 'self' data: https:` gönderir;
     * http:// adresli görseller tarayıcıda ENGELLENİR. Panel HTTPS üzerinden
     * yayınlandığında da http:// görseller "karışık içerik" sayılıp engellenir.
     * Neredeyse tüm tedarikçi CDN'leri https destekler (çoğu http'yi zaten
     * https'e yönlendirir), bu yüzden adres kayıt anında yükseltilir.
     */
    public static function normalizeImageUrl(string $url): string
    {
        $url = trim($url);

        if (stripos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    private function collectImages(\DOMElement $item, array $index, string $mapped): array
    {
        $mapped = trim($mapped);
        if ($mapped === '') {
            return [];
        }

        $urls = [];
        $push = function (string $url) use (&$urls): void {
            $url = trim($url);
            if ($url === '' || mb_strlen($url) > 500 || count($urls) >= self::MAX_IMAGES) {
                return;
            }
            if (!preg_match('#^https?://#i', $url)) {
                return;
            }

            $url = self::normalizeImageUrl($url);

            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        };

        // Nitelik ya da iç içe yol eşlemesi → doğrudan oku
        if (str_contains($mapped, '@') || str_contains($mapped, '/')) {
            $push($this->valueFrom($item, $index, $mapped));
            return $urls;
        }

        // Numaralı kardeş etiketleri yakala. Rakam sonda ya da ORTADA olabilir:
        //   Image1 · Resim_1 · img-01 · picture1Path · foto1Url
        $pattern = '';
        if (preg_match('/^(.*?)(\d+)([^\d]*)$/', $mapped, $parts) === 1) {
            $pattern = '/^'
                . preg_quote($parts[1], '/')
                . '\d+'
                . preg_quote($parts[3], '/')
                . '$/i';
        }

        foreach ($item->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }

            $names = array_filter([$child->nodeName, $child->localName]);
            $isMatch = false;
            foreach ($names as $name) {
                if (strcasecmp((string) $name, $mapped) === 0
                    || ($pattern !== '' && preg_match($pattern, (string) $name) === 1)
                ) {
                    $isMatch = true;
                    break;
                }
            }
            if (!$isMatch) {
                continue;
            }

            // <images><image>…</image></images> gibi kapsayıcılar
            $hasElementChild = false;
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof \DOMElement) {
                    $hasElementChild = true;
                    $push($grandChild->textContent);
                }
            }
            if (!$hasElementChild) {
                $push($child->textContent);
            }
        }

        return $urls;
    }

    /**
     * Kategori metnini seviyelere böl ("A > B > C", "A|B", "A/B").
     *
     * @return array<int, string>
     */
    private function splitCategory(string $raw): array
    {
        $raw = $this->clean($raw);
        if ($raw === '') {
            return [];
        }

        foreach (['|', '>', '»'] as $separator) {
            if (str_contains($raw, $separator)) {
                $parts = array_map('trim', explode($separator, $raw));
                return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
            }
        }

        return [$raw];
    }

    // ─── Kategori / marka ─────────────────────────────────

    /**
     * @param array<int, string> $path
     */
    private function ensureCategoryChain(array $path): ?int
    {
        $parentId = null;
        $walked   = [];

        foreach ($path as $name) {
            $name = mb_substr($name, 0, 290);
            if ($name === '') {
                continue;
            }

            $walked[] = $name;
            $key = mb_strtolower(implode('|', $walked));

            if (isset($this->categoryCache[$key])) {
                $parentId = $this->categoryCache[$key];
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

            $this->categoryCache[$key] = $parentId;
        }

        return $parentId;
    }

    /**
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

    private function ensureBrand(string $name): int
    {
        $key = mb_strtolower($name);
        if (!isset($this->brandCache[$key])) {
            $this->brandCache[$key] = $this->brandModel->findOrCreate($name);
        }
        return $this->brandCache[$key];
    }

    // ─── Yapı tespiti ─────────────────────────────────────

    /**
     * Ürün düğümünü bul. Kural: kök altında TEKRAR EDEN en sığ düğüm üründür
     * (<Products><Product>, <rss><channel><item>, <root><urun> ...).
     *
     * @return array{item_path: string, item_tag: string, count: int, candidates: array<int, array{path: string, count: int}>, fatal: bool, error: string}
     */
    private function detectStructure(string $file): array
    {
        $reader = $this->openReader($file);

        $counts     = [];   // yol → görülme sayısı
        $hasChild   = [];   // yol → altında element var mı
        $childPaths = [];   // yol → çocuk yolları kümesi
        $stack      = [];

        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $depth = $reader->depth;
                    $stack[$depth] = $reader->name;
                    $path = implode('/', array_slice($stack, 0, $depth + 1));

                    $counts[$path] = ($counts[$path] ?? 0) + 1;

                    if ($depth > 0) {
                        $parent = implode('/', array_slice($stack, 0, $depth));
                        $hasChild[$parent] = true;
                        // Her düğümün çocuk YOLLARINI da tut: tek ürünlü dosyalarda
                        // "hangi düğüm gerçek üründür" kararı yaprak alan sayısıyla verilir
                        $childPaths[$parent][$path] = true;
                    }
                }
                $ok = $reader->read();
            }
        } finally {
            $reader->close();
        }

        // Belge yarıda bozulduysa (kaçırılmamış &, yanlış kodlama, HTML kuyruğu…)
        $fatal = false;
        $error = '';
        foreach (libxml_get_errors() as $libxmlError) {
            if ($libxmlError->level === LIBXML_ERR_FATAL) {
                $fatal = true;
                if ($error === '') {
                    $error = trim($libxmlError->message) . ' (satır ' . $libxmlError->line . ')';
                }
            }
        }
        libxml_clear_errors();

        $empty = ['item_path' => '', 'item_tag' => '', 'count' => 0, 'candidates' => [], 'fatal' => $fatal, 'error' => $error];

        if ($counts === []) {
            return $empty;
        }

        // Aday: altında element bulunan, kökün altındaki düğümler
        $candidates = [];
        foreach ($counts as $path => $count) {
            $depth = substr_count($path, '/');
            if ($depth < 1 || !isset($hasChild[$path])) {
                continue;
            }
            $candidates[] = ['path' => $path, 'count' => $count, 'depth' => $depth];
        }

        if ($candidates === []) {
            return $empty;
        }

        // 1) Tekrar eden (count > 1) en SIĞ düğüm; eşitlikte en çok tekrar eden
        // 2) Hiç tekrar yoksa (tek ürünlü feed) en DERİN aday
        $repeating = array_values(array_filter($candidates, static fn(array $c): bool => $c['count'] > 1));

        if ($repeating !== []) {
            usort($repeating, static function (array $a, array $b): int {
                return $a['depth'] <=> $b['depth'] ?: $b['count'] <=> $a['count'];
            });
            $best = $repeating[0];
        } else {
            // Tek ürünlü dosya: hiçbir düğüm tekrar etmiyor. Burada "en derin" almak
            // yanlış — <Urun><Resimler><Resim>… yapısında kapsayıcı ürün sanılıyordu.
            // Gerçek ürün düğümü, EN ÇOK YAPRAK ALANI (ad/fiyat/stok…) olan düğümdür.
            foreach ($candidates as $i => $aday) {
                $yaprak = 0;
                foreach (array_keys($childPaths[$aday['path']] ?? []) as $cocukYol) {
                    if (!isset($hasChild[$cocukYol])) {
                        $yaprak++;
                    }
                }
                $candidates[$i]['leaves'] = $yaprak;
            }

            usort($candidates, static function (array $a, array $b): int {
                return $b['leaves'] <=> $a['leaves'] ?: $a['depth'] <=> $b['depth'];
            });
            $best = $candidates[0];
        }

        usort($candidates, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        $list = array_map(
            static fn(array $c): array => ['path' => $c['path'], 'count' => $c['count']],
            array_slice($candidates, 0, 10)
        );

        $segments = explode('/', $best['path']);

        return [
            'item_path'  => $best['path'],
            'item_tag'   => (string) end($segments),
            'count'      => $best['count'],
            'candidates' => $list,
            'fatal'      => $fatal,
            'error'      => $error,
        ];
    }

    private function countItems(string $file, string $itemPath): int
    {
        $segments = explode('/', $itemPath);
        $itemTag  = (string) end($segments);
        $depth    = count($segments) - 1;

        $reader = $this->openReader($file);
        $count  = 0;

        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::ELEMENT
                    && $reader->name === $itemTag
                    && $reader->depth === $depth
                ) {
                    $count++;
                    $ok = $reader->next();
                    continue;
                }
                $ok = $reader->read();
            }
        } finally {
            $reader->close();
        }

        return $count;
    }

    /**
     * İlk ürünlerden etiket envanteri + örnek değerler çıkar.
     *
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function sampleItems(string $file, string $itemPath, int $sampleSize = 25): array
    {
        $segments = explode('/', $itemPath);
        $itemTag  = (string) end($segments);
        $depth    = count($segments) - 1;

        $reader = $this->openReader($file);
        $doc    = new \DOMDocument('1.0', 'UTF-8');

        $tags   = [];
        $sample = [];
        $seen   = 0;

        try {
            $ok = $reader->read();
            while ($ok && $seen < $sampleSize) {
                if ($reader->nodeType !== \XMLReader::ELEMENT
                    || $reader->name !== $itemTag
                    || $reader->depth !== $depth
                ) {
                    $ok = $reader->read();
                    continue;
                }

                $node = $reader->expand($doc);
                $ok = $reader->next();
                if (!($node instanceof \DOMElement)) {
                    continue;
                }

                $seen++;

                foreach ($node->attributes ?? [] as $attribute) {
                    $key = '@' . $attribute->nodeName;
                    if (!in_array($key, $tags, true)) {
                        $tags[] = $key;
                    }
                    if (!isset($sample[$key]) && trim($attribute->value) !== '') {
                        $sample[$key] = $this->preview($attribute->value);
                    }
                }

                foreach ($node->childNodes as $child) {
                    if (!($child instanceof \DOMElement)) {
                        continue;
                    }

                    $name = $child->nodeName;
                    if (!in_array($name, $tags, true)) {
                        $tags[] = $name;
                    }

                    $hasElementChild = false;
                    foreach ($child->childNodes as $grandChild) {
                        if (!($grandChild instanceof \DOMElement)) {
                            continue;
                        }
                        $hasElementChild = true;
                        $path = $name . '/' . $grandChild->nodeName;
                        if (!in_array($path, $tags, true)) {
                            $tags[] = $path;
                        }
                        if (!isset($sample[$path]) && trim($grandChild->textContent) !== '') {
                            $sample[$path] = $this->preview($grandChild->textContent);
                        }
                    }

                    if (!$hasElementChild && !isset($sample[$name]) && trim($child->textContent) !== '') {
                        $sample[$name] = $this->preview($child->textContent);
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return [$tags, $sample];
    }

    /**
     * Etiket adlarından sistem alanlarına otomatik eşleme öner.
     *
     * @param array<int, string> $tags
     * @return array<string, string>
     */
    private function suggestMapping(array $tags): array
    {
        $candidates = [
            'sku'         => ['sku', 'stokkodu', 'stok_kodu', 'productcode', 'product_code', 'urunkodu', 'urun_kodu', 'kod', 'code', 'stockcode', 'stock_code', 'merchantsku', 'shopsku'],
            'name'        => ['name', 'urunadi', 'urun_adi', 'productname', 'product_name', 'title', 'baslik', 'ad', 'isim'],
            'barcode'     => ['barcode', 'barkod', 'gtin', 'ean', 'ean13', 'upc'],
            'description' => ['description', 'aciklama', 'detay', 'icerik', 'urunaciklama', 'detail'],
            'base_price'  => ['price', 'fiyat', 'satisfiyati', 'satis_fiyati', 'satisfiyat', 'saleprice', 'sale_price', 'urunfiyat', 'fiyati'],
            'list_price'  => ['listprice', 'list_price', 'listefiyati', 'liste_fiyati', 'piyasafiyati', 'psf', 'marketprice'],
            'cost_price'  => ['cost', 'costprice', 'cost_price', 'maliyet', 'alisfiyati', 'alis_fiyati'],
            'stock_qty'   => ['stock', 'stok', 'stokadedi', 'stok_adedi', 'quantity', 'qty', 'miktar', 'adet', 'stockamount'],
            'tax_rate'    => ['tax', 'vergi', 'kdv', 'kdvorani', 'kdv_orani', 'taxrate', 'tax_rate', 'vat'],
            'weight'      => ['weight', 'agirlik', 'kg', 'gross_weight'],
            'desi'        => ['desi', 'dessi', 'volumetricweight'],
            'brand'       => ['brand', 'marka', 'brandname', 'manufacturer', 'uretici'],
            // KAPSAYICI adlar (resimler/gorseller/images) önce gelir: <Resimler><Resim>…
            // yapısında collectImages() alt düğümlerin TAMAMINI toplar; tekil etiket
            // seçilirse yalnız bir görsel gelir.
            'image'       => [
                'resimler', 'gorseller', 'images', 'imagelist', 'picturelist', 'pictures',
                'image1', 'image_1', 'resim1', 'resim_1', 'picture1', 'picture1path', 'img1',
                'image', 'resim', 'gorsel', 'img', 'imageurl', 'image_url', 'gorselurl',
                'resimurl', 'picturepath', 'imagepath', 'picture',
            ],
            'category_1'  => ['maincategory', 'main_category', 'anakategori', 'ana_kategori', 'kategoriagaci', 'kategoriyolu', 'kategoriyol', 'categorypath', 'categorytree', 'kategoritree', 'kategori', 'category'],
            'category_2'  => ['category', 'kategori', 'subcategory', 'altkategori', 'category2', 'kategori2'],
            'category_3'  => ['subcategory', 'sub_category', 'altkategori', 'alt_kategori', 'kategorialt', 'category3', 'kategori3'],
        ];

        // "g:sku" → "sku": ad-alanı öneki eşleşmeyi bozmasın
        $normalize = static fn(string $tag): string => strtolower(
            (string) preg_replace('/[^a-z0-9]/i', '', (string) preg_replace('/^[^:]+:/', '', $tag))
        );

        $normalized = [];
        foreach ($tags as $tag) {
            $normalized[$tag] = $normalize($tag);
        }

        $map  = [];
        $used = [];

        foreach ($candidates as $field => $names) {
            foreach ($names as $wanted) {
                foreach ($normalized as $tag => $flat) {
                    if ($flat !== $wanted || in_array($tag, $used, true)) {
                        continue;
                    }
                    $map[$field] = $tag;
                    $used[] = $tag;
                    break 2;
                }
            }
        }

        // Görsel: sabit liste tutmaz — "image/resim/picture/foto/görsel/img" geçen
        // etiketlerden numarası EN KÜÇÜK olanı seç (picture1Path, img-01, foto_1 …).
        if (empty($map['image'])) {
            $numberedTag = '';
            $numberedValue = PHP_INT_MAX;
            $plainTag = '';

            foreach ($normalized as $tag => $flat) {
                if (str_starts_with($tag, '@') || in_array($tag, $used, true)) {
                    continue;
                }
                if (preg_match('/(image|resim|picture|foto|gorsel|img)/', $flat) !== 1) {
                    continue;
                }

                if (preg_match('/(\d+)/', $flat, $digits) === 1) {
                    // Numaralı seri (galeri) → en küçük numaralı olan başlangıç noktasıdır
                    if ((int) $digits[1] < $numberedValue) {
                        $numberedValue = (int) $digits[1];
                        $numberedTag = $tag;
                    }
                } elseif ($plainTag === '') {
                    $plainTag = $tag;
                }
            }

            $pick = $numberedTag !== '' ? $numberedTag : $plainTag;
            if ($pick !== '') {
                $map['image'] = $pick;
                $used[] = $pick;
            }
        }

        // Aynı etiket birden fazla kategori seviyesine düşmüşse tekrarı at
        $seenCategories = [];
        foreach (['category_1', 'category_2', 'category_3'] as $level) {
            if (!isset($map[$level])) {
                continue;
            }
            if (in_array($map[$level], $seenCategories, true)) {
                unset($map[$level]);
                continue;
            }
            $seenCategories[] = $map[$level];
        }

        return $map;
    }

    // ─── Kaynak onarımı ───────────────────────────────────

    /**
     * Bozuk kaynağı kurtarmayı dene:
     *   - UTF-8 BOM / başta boşluk-çöp
     *   - Tarayıcıda kaydedilmiş XML görüntüleyici HTML sarmalı
     *   - UTF-8 olmayan içerik (Windows-1254 / ISO-8859-9)
     *
     * @return bool Dosya değiştirildiyse true
     */
    private function repairSource(string $file): bool
    {
        $size = (int) (@filesize($file) ?: 0);
        if ($size === 0 || $size > self::REPAIR_MAX_BYTES) {
            return false;
        }

        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return false;
        }

        $original = $content;

        // 1) UTF-8 BOM
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // 2) Tarayıcıda kaydedilmiş sayfadan gerçek XML'i çıkar
        $content = $this->extractXmlFromHtml($content);

        // 3) Baştaki çöpü at (bildirim ya da ilk etiketten önce ne varsa)
        $start = strpos($content, '<?xml');
        if ($start === false) {
            $start = strpos($content, '<');
        }
        if ($start !== false && $start > 0) {
            $content = substr($content, $start);
        }

        // 4) Kök etiketin son kapanışından sonrasını at (sarmalın HTML kuyruğu)
        $content = $this->trimAfterRoot($content);

        // 5) Kodlama: içerik UTF-8 değilse Türkçe Windows kod sayfasından çevir
        if (!$this->isUtf8($content)) {
            $converted = @iconv('WINDOWS-1254', 'UTF-8//IGNORE', $content);
            if (is_string($converted) && $converted !== '') {
                $content = (string) preg_replace(
                    '/(<\?xml[^>]*encoding=")([^"]+)(")/i',
                    '${1}UTF-8${3}',
                    $converted,
                    1
                );
            }
        }

        // 6) Kaçırılmamış & işaretleri (CDATA blokları dokunulmadan bırakılır)
        $content = $this->escapeLooseAmpersands($content);

        if ($content === $original || trim($content) === '') {
            return false;
        }

        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }

    /**
     * Dosyanın başı HTML sayfası mı? (Tarayıcıda "Farklı kaydet" ile alınan XML'ler)
     */
    private function looksLikeHtml(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        $head = ltrim($head, "\xEF\xBB\xBF \t\r\n");

        return stripos($head, 'webkit-xml-viewer-source-xml') !== false
            || preg_match('/^(<!doctype\s+html|<html[\s>])/i', $head) === 1;
    }

    /** Gerçek XML'in içinde bulunamayacağı, tarayıcı sarmalına ait etiketler */
    private const HTML_TAGS = [
        'html', 'head', 'body', 'div', 'span', 'script', 'style', 'meta', 'link',
        'title', 'p', 'br', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'ol',
        'li', 'a', 'img', 'form', 'input', 'button', 'header', 'footer', 'main',
        'section', 'nav', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * Tarayıcının kaydettiği HTML sayfasından gerçek XML gövdesini çıkar.
     *
     * İki durum var:
     *   a) Chrome/Edge: <div id="webkit-xml-viewer-source-xml"> … XML … </div>
     *   b) Diğerleri: HTML içinde, HTML olmayan ilk etiketten itibaren XML
     *
     * HTML sarmalı yoksa içerik olduğu gibi döner.
     */
    private function extractXmlFromHtml(string $content): string
    {
        $marker = stripos($content, 'webkit-xml-viewer-source-xml');
        if ($marker !== false) {
            $tagEnd = strpos($content, '>', $marker);
            if ($tagEnd !== false) {
                $content = substr($content, $tagEnd + 1);
            }
        }

        // Hâlâ HTML ile başlıyorsa, HTML olmayan ilk elemandan itibaren kes
        $basi = ltrim($content);
        if (preg_match('/^<!doctype\s+html/i', $basi) === 1
            || (preg_match('/^<\s*([A-Za-z_][\w.\-]*)/', $basi, $ilk) === 1
                && in_array(strtolower($ilk[1]), self::HTML_TAGS, true))
        ) {
            if (preg_match_all('/<\s*([A-Za-z_][\w.\-]*)[\s>\/]/', $content, $eslesmeler, PREG_OFFSET_CAPTURE)) {
                foreach ($eslesmeler[1] as $index => $eslesme) {
                    if (in_array(strtolower($eslesme[0]), self::HTML_TAGS, true)) {
                        continue;
                    }
                    $content = substr($content, $eslesmeler[0][$index][1]);
                    break;
                }
            }
        }

        return $content;
    }

    /**
     * Kök elemanın son kapanış etiketinden sonrasını kes.
     */
    private function trimAfterRoot(string $content): string
    {
        if (!preg_match('/<\s*([A-Za-z_][\w.\-]*)(?:\s|>|\/)/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $root = $m[1][0];
        if (strcasecmp($root, 'html') === 0) {
            return $content;
        }

        $close = '</' . $root . '>';
        $last  = strripos($content, $close);
        if ($last === false) {
            return $content;
        }

        return substr($content, 0, $last + strlen($close));
    }

    /**
     * Tek başına duran & işaretlerini kaçır. CDATA bölümleri olduğu gibi korunur
     * (orada & zaten düz metindir; kaçırmak içeriği bozar).
     */
    private function escapeLooseAmpersands(string $content): string
    {
        $parts = preg_split('/(<!\[CDATA\[.*?\]\]>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        $pattern = '/&(?!(?:[a-zA-Z][a-zA-Z0-9]{1,30}|#\d{1,7}|#x[0-9a-fA-F]{1,6});)/';

        foreach ($parts as $index => $part) {
            if (str_starts_with($part, '<![CDATA[')) {
                continue;
            }
            $parts[$index] = (string) preg_replace($pattern, '&amp;', $part);
        }

        return implode('', $parts);
    }

    private function isUtf8(string $value): bool
    {
        return (bool) preg_match('//u', $value);
    }

    // ─── İş kaydı (Son İçe Aktarma İşlemleri) ─────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function startJob(array $meta, string $mode): int
    {
        try {
            return $this->insertJob($meta, $mode);
        } catch (\Throwable $e) {
            $this->logger->warning('XML import iş kaydı açılamadı: ' . $e->getMessage(), 'XmlImport');
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function insertJob(array $meta, string $mode): int
    {
        $this->db->query(
            "INSERT INTO product_import_jobs
                (store_id, filename, original_filename, file_type, import_mode, status, total_rows, started_at, created_at)
             VALUES (:sid, :fn, :ofn, 'xml', :mode, 'processing', :total, NOW(), NOW())",
            [
                'sid'   => $this->tenant->currentStoreId(),
                'fn'    => mb_substr((string) ($meta['source_url'] ?? $meta['original_name'] ?? 'import.xml'), 0, 490),
                'ofn'   => mb_substr((string) ($meta['original_name'] ?? 'import.xml'), 0, 490),
                'mode'  => mb_substr('xml-' . $mode, 0, 20),
                'total' => (int) ($meta['count'] ?? 0),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function finishJob(array $meta): void
    {
        $jobId = (int) ($meta['job_id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        $created = (int) ($meta['created'] ?? 0);
        $updated = (int) ($meta['updated'] ?? 0);
        $skipped = (int) ($meta['skipped'] ?? 0);
        $failed  = (int) ($meta['failed'] ?? 0);
        $success = $created + $updated;

        try {
            $this->db->query(
                "UPDATE product_import_jobs
                    SET status = :status, total_rows = :total, processed_rows = :processed,
                        success_rows = :success, failed_rows = :failed,
                        error_log = :errors, finished_at = NOW()
                  WHERE id = :id AND store_id = :sid",
                [
                    'status'    => ($failed > 0 && $success === 0) ? 'failed' : 'completed',
                    'total'     => (int) ($meta['count'] ?? ($success + $skipped + $failed)),
                    'processed' => $success + $skipped + $failed,
                    'success'   => $success,
                    'failed'    => $failed,
                    'errors'    => $meta['errors'] ? json_encode(array_slice((array) $meta['errors'], 0, 100), JSON_UNESCAPED_UNICODE) : null,
                    'id'        => $jobId,
                    'sid'       => $this->tenant->currentStoreId(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('XML import iş kaydı kapatılamadı: ' . $e->getMessage(), 'XmlImport');
        }

        $this->logger->info(
            sprintf(
                'XML import tamamlandı: %d yeni, %d güncellendi, %d atlandı, %d hatalı',
                $created,
                $updated,
                $skipped,
                $failed
            ),
            'XmlImport'
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function summaryMessage(array $meta): string
    {
        $message = sprintf(
            'XML Import: %d yeni, %d güncellendi',
            (int) ($meta['created'] ?? 0),
            (int) ($meta['updated'] ?? 0)
        );

        if ((int) ($meta['skipped'] ?? 0) > 0) {
            $message .= sprintf(', %d atlandı', (int) $meta['skipped']);
        }
        if ((int) ($meta['failed'] ?? 0) > 0) {
            $message .= sprintf(', %d hatalı', (int) $meta['failed']);
        }

        return $message;
    }

    // ─── Geçici depo ──────────────────────────────────────

    private function storageDir(): string
    {
        $dir = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3)) . '/storage/import_xml';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir() . '/ozy_import_xml';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException('Geçici XML klasörü oluşturulamadı: storage/import_xml');
        }

        // Web erişimine kapat (kök .htaccess zaten storage'ı kapatıyor; ikinci katman)
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }

        $this->cleanupExpired($dir);

        return $dir;
    }

    private function cleanupExpired(string $dir): void
    {
        $files = @glob($dir . '/*.{xml,json}', GLOB_BRACE);
        if ($files === false) {
            return;
        }

        $threshold = time() - self::TEMP_TTL;
        foreach ($files as $file) {
            if (@filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function sourcePath(string $token): string
    {
        return $this->storageDir() . '/' . $this->assertToken($token) . '.xml';
    }

    private function metaPath(string $token): string
    {
        return $this->storageDir() . '/' . $this->assertToken($token) . '.json';
    }

    private function assertToken(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException('Geçersiz kaynak anahtarı.');
        }
        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeMeta(string $token, array $data): void
    {
        $this->putMeta($token, $data + [
            'store_id'   => $this->tenant->currentStoreId(),
            'created_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function putMeta(string $token, array $meta): void
    {
        $meta['store_id'] = $meta['store_id'] ?? $this->tenant->currentStoreId();
        @file_put_contents($this->metaPath($token), json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $token): array
    {
        $path = $this->metaPath($token);
        if (!is_file($path)) {
            throw new \RuntimeException('Yüklenen XML kaynağı bulunamadı (süresi dolmuş olabilir) — dosyayı tekrar seçin.');
        }

        $meta = json_decode((string) @file_get_contents($path), true);
        if (!is_array($meta)) {
            throw new \RuntimeException('XML kaynağı okunamadı — dosyayı tekrar seçin.');
        }

        if ((int) ($meta['store_id'] ?? 0) !== $this->tenant->currentStoreId()) {
            throw new \RuntimeException('Bu XML kaynağı başka bir mağazaya ait.');
        }

        if (!is_file($this->sourcePath($token))) {
            throw new \RuntimeException('Yüklenen XML dosyası bulunamadı — dosyayı tekrar seçin.');
        }

        return $meta;
    }

    // ─── Yardımcılar ──────────────────────────────────────

    private function openReader(string $file): \XMLReader
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        // LIBXML_NONET: dış varlık (XXE) ve ağ erişimi kapalı.
        // NOT: libxml_disable_entity_loader() PHP 8.0'da "deprecated", 8.4'te kaldırıldı;
        // PHP 8'de dış varlık yükleme zaten varsayılan olarak kapalıdır.
        // Hatalar bastırılmaz, libxml_get_errors() ile toplanır (onarım kararı için).
        $opened = @$reader->open($file, null, LIBXML_NONET);

        if ($opened === false) {
            throw new \RuntimeException('XML dosyası açılamadı.');
        }

        // İlk okuma denemesi burada yapılmaz; biçim hatası ilk read()'te yüzeye çıkar.
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
            // İki ayraç birden: SON görülen ondalık ayracıdır
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace(['.', ','], ['', '.'], $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($hasComma) {
            $raw = str_replace(',', '.', $raw);
        } elseif ($hasDot && substr_count($raw, '.') > 1) {
            $raw = str_replace('.', '', $raw);
        }

        $clean = (string) preg_replace('/[^0-9.\-]/', '', $raw);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }

        return (float) $clean;
    }

    /**
     * Negatifleri sıfırla, sütun tavanını aşanları tavana çek.
     */
    private function clamp(float $value, float $max): float
    {
        if ($value < 0) {
            return 0.0;
        }
        return $value > $max ? $max : $value;
    }

    private function clean(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = (string) preg_replace('/\s{2,}/u', ' ', $value);
        return trim($value);
    }

    /**
     * Metni sütun sınırına göre bayt bazında güvenle kırp (TEXT = 65535 bayt).
     */
    private function limitBytes(string $value, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) <= $maxBytes) {
            return $value;
        }
        return (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }

    private function preview(string $value): string
    {
        $value = $this->clean($value);
        return mb_strlen($value) > 80 ? mb_substr($value, 0, 80) . '…' : $value;
    }

    /**
     * @param array<int, string> $images
     */
    private function encodeImages(array $images): string
    {
        return (string) json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Kullanıcı eşlemesini güvenli hale getir (yalnız bilinen alanlar, temiz değerler).
     *
     * @param array<string, mixed> $rawMap
     * @return array<string, string>
     */
    private function normalizeMap(array $rawMap): array
    {
        $map = [];
        foreach (array_keys(self::SYSTEM_FIELDS) as $field) {
            $value = $rawMap[$field] ?? '';
            $value = is_string($value) ? trim($value) : '';
            // Etiket adı + isteğe bağlı yol/nitelik dışında hiçbir şeye izin verme
            $map[$field] = preg_match('#^[A-Za-z0-9_.:@/\-]{0,120}$#', $value) === 1 ? $value : '';
        }

        if ($map['sku'] === '' || $map['name'] === '') {
            throw new \RuntimeException('SKU ve Ürün Adı alanları eşleştirilmelidir.');
        }

        return $map;
    }

    /**
     * @param array<int, string> $errors
     */
    private function pushError(array &$errors, string $message): void
    {
        if (count($errors) < 100) {
            $errors[] = mb_substr($message, 0, 220);
        }
    }

    private function shortError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // PDO mesajlarını kullanıcıya anlaşılır hale getir
        if (str_contains($message, 'foreign key constraint fails')) {
            return 'İlişkili kayıt bulunamadı (kategori/marka)';
        }
        if (str_contains($message, 'Data too long')) {
            return 'Alan veritabanı sınırını aşıyor';
        }
        if (str_contains($message, 'Duplicate entry')) {
            return 'Bu SKU zaten kayıtlı';
        }

        return mb_substr($message, 0, 180);
    }

    private function safeName(string $name): string
    {
        $name = (string) preg_replace('/[^\w.\- ()\p{L}]/u', '', $name);
        return mb_substr(trim($name), 0, 190) ?: 'import.xml';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'XML dosyası sunucu sınırından büyük. Hosting panelinden upload_max_filesize / post_max_size değerlerini yükseltin '
                . '(şu an: ' . ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size') . ') ya da "XML Linki" seçeneğini kullanın.',
            UPLOAD_ERR_PARTIAL   => 'Dosya yüklemesi yarıda kesildi — tekrar deneyin.',
            UPLOAD_ERR_NO_FILE   => 'Dosya seçilmedi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör tanımlı değil (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'Sunucu diske yazamadı.',
            UPLOAD_ERR_EXTENSION => 'Yükleme bir PHP eklentisi tarafından durduruldu.',
            default              => 'XML dosyası yüklenemedi (hata kodu: ' . $error . ').',
        };
    }

    // ─── İndirme + SSRF koruması ──────────────────────────

    private function downloadTo(string $url, string $target): void
    {
        if (function_exists('curl_init')) {
            $fp = fopen($target, 'wb');
            if ($fp === false) {
                throw new \RuntimeException('Geçici dosya oluşturulamadı (storage yazma izni?).');
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false, // yönlendirmeleri elle izle (SSRF)
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 OzyConnect XmlImport',
                CURLOPT_ACCEPT_ENCODING => '',
            ]);

            $current = $url;
            $redirects = 0;
            $ok = false; $error = ''; $status = 0;

            while (true) {
                ftruncate($fp, 0);
                rewind($fp);
                curl_setopt($ch, CURLOPT_URL, $current);

                $ok = curl_exec($ch) === true;
                $error = curl_error($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($ok && $status >= 300 && $status < 400 && $redirects < 5) {
                    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                    if ($location !== '') {
                        $this->assertPublicUrl($location);
                        $current = $location;
                        $redirects++;
                        continue;
                    }
                }
                break;
            }

            curl_close($ch);
            fclose($fp);

            if ($ok && $status >= 400) {
                $ok = false;
                $error = 'HTTP ' . $status;
            }
            if (!$ok) {
                @unlink($target);
                throw new \RuntimeException('XML indirilemedi: ' . ($error !== '' ? $error : 'bilinmeyen hata'));
            }

            return;
        }

        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException('Sunucuda curl veya allow_url_fopen etkin olmalı — link yerine dosya yükleyin.');
        }

        $source = @fopen($url, 'rb');
        if ($source === false) {
            throw new \RuntimeException('XML indirilemedi — linki tarayıcıda açıp kontrol edin.');
        }

        $destination = fopen($target, 'wb');
        if ($destination === false) {
            fclose($source);
            throw new \RuntimeException('Geçici dosya oluşturulamadı (storage yazma izni?).');
        }

        stream_copy_to_stream($source, $destination, self::MAX_SOURCE_BYTES);
        fclose($source);
        fclose($destination);
    }

    /**
     * Yalnız http(s) ve HERKESE AÇIK IP'lere izin ver (iç ağ / bulut metadata engellenir).
     */
    private function assertPublicUrl(string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Geçersiz XML linki — http:// veya https:// ile başlamalı.');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('XML linki çözümlenemedi.');
        }
        $host = trim($host, '[]');

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach ((@dns_get_record($host, DNS_A | DNS_AAAA) ?: []) as $record) {
                if (!empty($record['ip']))   { $ips[] = $record['ip']; }
                if (!empty($record['ipv6'])) { $ips[] = $record['ipv6']; }
            }
            if ($ips === []) {
                $resolved = @gethostbynamel($host);
                if (is_array($resolved)) {
                    $ips = $resolved;
                }
            }
        }

        if ($ips === []) {
            throw new \RuntimeException('XML kaynağının adresi çözümlenemedi: ' . $host);
        }

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new \RuntimeException('Güvenlik: iç ağ / özel adreslere erişime izin verilmez.');
            }
        }
    }
}
