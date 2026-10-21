<?php
/**
 * OzyConnect — StoreDocumentService (KYC belge yükleme/inceleme/doğrulama)
 *
 * Güvenlik: dosyalar webroot DIŞI BASE_PATH/storage/store-documents/{store_id}/ altına rastgele
 * adla yazılır; yalnız erişim-kontrollü indirme uçlarından (merchant=tenant-scoped, admin) sunulur.
 * MIME finfo ile doğrulanır (pdf/jpg/png/webp), boyut ≤ 8MB. Doğrulama: tüm AKTİF+ZORUNLU türler
 * onaylanınca stores.verified_at set edilir.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\StoreDocument;
use App\Models\StoreDocumentType;

final class StoreDocumentService
{
    /**
     * Format kataloğu — admin'in tür başına seçebileceği dosya biçimleri.
     * Her biri: kullanıcıya görünen etiket, kabul edilen uzantılar ve finfo MIME'leri,
     * <input accept> değeri. Office (word/excel) finfo'da çoğu zaman zip/ole/octet-stream
     * döndüğünden o MIME'ler de listeye dahildir (uzantı + MIME birlikte doğrulanır).
     */
    public const FORMATS = [
        'pdf'   => ['label' => 'PDF',        'ext' => ['pdf'],         'mime' => ['application/pdf'],
                    'accept' => '.pdf,application/pdf'],
        'jpg'   => ['label' => 'JPG / JPEG', 'ext' => ['jpg', 'jpeg'], 'mime' => ['image/jpeg'],
                    'accept' => '.jpg,.jpeg,image/jpeg'],
        'png'   => ['label' => 'PNG',        'ext' => ['png'],         'mime' => ['image/png'],
                    'accept' => '.png,image/png'],
        'gif'   => ['label' => 'GIF',        'ext' => ['gif'],         'mime' => ['image/gif'],
                    'accept' => '.gif,image/gif'],
        'webp'  => ['label' => 'WEBP',       'ext' => ['webp'],        'mime' => ['image/webp'],
                    'accept' => '.webp,image/webp'],
        'word'  => ['label' => 'Word',       'ext' => ['doc', 'docx'],
                    'mime' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                               'application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/x-cfb'],
                    'accept' => '.doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'excel' => ['label' => 'Excel',      'ext' => ['xls', 'xlsx'],
                    'mime' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                               'application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/x-cfb'],
                    'accept' => '.xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];
    private const DEFAULT_FORMATS = ['pdf', 'jpg', 'png', 'webp'];
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Admin form seçenekleri: anahtar => etiket. @return array<string,string> */
    public static function formatOptions(): array
    {
        return array_map(static fn(array $f): string => $f['label'], self::FORMATS);
    }

    /** CSV → geçerli format anahtarları (geçersizleri eler; boşsa varsayılan set). @return list<string> */
    public static function normalizeKeys(?string $csv): array
    {
        $keys  = array_filter(array_map('trim', explode(',', (string) $csv)));
        $valid = array_values(array_intersect($keys, array_keys(self::FORMATS)));
        return $valid !== [] ? $valid : self::DEFAULT_FORMATS;
    }

    /** Tür için <input accept> değeri (uzantı + MIME). */
    public static function acceptAttr(?string $csv): string
    {
        $parts = [];
        foreach (self::normalizeKeys($csv) as $k) { $parts[] = self::FORMATS[$k]['accept']; }
        return implode(',', $parts);
    }

    /** Tür için kullanıcıya gösterilecek etiketler (örn. "PDF · JPG / JPEG"). @return list<string> */
    public static function formatLabels(?string $csv): array
    {
        return array_map(static fn(string $k): string => self::FORMATS[$k]['label'], self::normalizeKeys($csv));
    }

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly StoreDocument $docs,
        private readonly StoreDocumentType $types,
        private readonly Logger $logger,
    ) {}

    /** Depolama kök dizini (webroot DIŞI). */
    public function storageRoot(): string
    {
        return BASE_PATH . '/storage/store-documents';
    }

    /** Göreli file_path'ten güvenli mutlak yol (traversal koruması). null = geçersiz. */
    public function absolutePath(string $relPath): ?string
    {
        $root = realpath($this->storageRoot());
        if ($root === false) { return null; }
        $abs = realpath($this->storageRoot() . '/' . ltrim($relPath, '/\\'));
        if ($abs === false) { return null; }
        // Yalnız storage/store-documents altındaki dosyalar.
        if (!str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) { return null; }
        return is_file($abs) ? $abs : null;
    }

    /**
     * Merchant: aktif mağazaya belge yükle. store_id TenantContext'ten (anti-spoof).
     * @param array<string,mixed> $file  $_FILES girdisi
     * @throws \RuntimeException kullanıcıya gösterilebilir hata
     */
    public function upload(int $typeId, array $file, ?int $userId): void
    {
        $storeId = $this->tenant->currentStoreId(); // fail-closed (tenant yoksa exception)

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) { throw new \RuntimeException('Lütfen bir dosya seçin.'); }
        if ($err !== UPLOAD_ERR_OK)      { throw new \RuntimeException('Dosya yüklenemedi (hata kodu ' . $err . ').'); }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { throw new \RuntimeException('Dosya doğrulanamadı.'); }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) { throw new \RuntimeException('Dosya boyutu en fazla 8 MB olabilir.'); }

        // Tür doğrula (aktif tür olmalı) — kabul edilen formatlar TÜRDEN gelir.
        $type = $this->types->find($typeId);
        if ($type === false || (int) ($type['is_active'] ?? 0) !== 1) { throw new \RuntimeException('Geçersiz belge türü.'); }

        $allowedKeys = self::normalizeKeys($type['allowed_types'] ?? '');
        $labels      = implode(', ', array_map(static fn(string $k): string => self::FORMATS[$k]['label'], $allowedKeys));

        // 1) Uzantı türün izin verdiği bir formata ait olmalı.
        $ext     = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $fmtKey  = null;
        foreach ($allowedKeys as $k) {
            if (in_array($ext, self::FORMATS[$k]['ext'], true)) { $fmtKey = $k; break; }
        }
        if ($fmtKey === null) {
            throw new \RuntimeException('Bu belge için yalnızca şu biçimler kabul edilir: ' . $labels . '.');
        }

        // 2) Dosya içeriği (finfo MIME) o formatın izinli MIME'leriyle uyuşmalı (uzantı taklidi engellenir).
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::FORMATS[$fmtKey]['mime'], true)) {
            throw new \RuntimeException('Dosya içeriği uzantısıyla uyuşmuyor. Lütfen geçerli bir ' . self::FORMATS[$fmtKey]['label'] . ' dosyası yükleyin.');
        }

        $dir = $this->storageRoot() . '/' . $storeId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Belge klasörü oluşturulamadı.');
        }
        $fname = 'doc-' . $typeId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $dir . '/' . $fname;
        if (!move_uploaded_file($tmp, $target)) { throw new \RuntimeException('Dosya kaydedilemedi.'); }
        @chmod($target, 0640);

        // create() store_id'yi TenantContext'ten ZORLAR (anti-spoof) → cross-tenant yazılamaz.
        $this->docs->create([
            'doc_type_id'      => $typeId,
            'file_path'        => $storeId . '/' . $fname,
            'original_name'    => mb_substr((string) ($file['name'] ?? ''), 0, 255),
            'mime'             => $mime,
            'size'             => $size,
            'status'           => 'pending',
            'uploaded_by_user' => $userId,
        ]);

        // Yeni yükleme önceki onayı geçersiz kılmaz; doğrulama yine de tazele (no-op olabilir).
        $this->recomputeVerification($storeId);
    }

    /** Admin: belge onay/red + ilgili mağaza doğrulamasını tazele. */
    public function review(int $docId, string $status, ?int $adminId, ?string $note): void
    {
        $doc = $this->docs->adminFind($docId);
        if ($doc === false) { throw new \RuntimeException('Belge bulunamadı.'); }
        $this->docs->adminSetStatus($docId, $status, $adminId, $note);
        $this->recomputeVerification((int) $doc['store_id']);
    }

    /**
     * Mağaza doğrulama durumunu yeniden hesapla: tüm AKTİF+ZORUNLU türler onaylı mı?
     * Zorunlu tür yoksa "doğrulanmış" sayılır (kapı yok). stores.verified_at güncellenir.
     */
    public function recomputeVerification(int $storeId): bool
    {
        $required = $this->types->requiredActiveIds();
        $approved = $this->docs->approvedTypeIds($storeId);
        $verified = $required === [] || array_diff($required, $approved) === [];

        if ($verified) {
            $this->db->query("UPDATE stores SET verified_at = COALESCE(verified_at, NOW()) WHERE id = :id", ['id' => $storeId]);
        } else {
            $this->db->query("UPDATE stores SET verified_at = NULL WHERE id = :id", ['id' => $storeId]);
        }
        return $verified;
    }

    /**
     * Tüm mağazaların doğrulama durumunu yeniden hesapla (zorunlu tür seti değişince:
     * tür ekleme/silme/zorunluluk-toggle). Mağaza sayısı düşük olduğundan basit döngü yeterli.
     */
    public function recomputeAllStores(): void
    {
        foreach ($this->db->fetchAll("SELECT id FROM stores") as $r) {
            $this->recomputeVerification((int) $r['id']);
        }
    }

    /**
     * Merchant doğrulama özeti (sayfa/banner).
     * @return array{verified:bool, required:int, approved_required:int, pending:int, rejected:int}
     */
    public function summaryForCurrentStore(): array
    {
        $storeId  = $this->tenant->currentStoreId();
        $required = $this->types->requiredActiveIds();
        $approved = $this->docs->approvedTypeIds($storeId);
        $approvedReq = count(array_intersect($required, $approved));
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) c FROM store_documents WHERE store_id = :sid GROUP BY status",
            ['sid' => $storeId]
        );
        $byStatus = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($rows as $r) { $byStatus[$r['status']] = (int) $r['c']; }
        return [
            'verified'          => $required === [] || array_diff($required, $approved) === [],
            'required'          => count($required),
            'approved_required' => $approvedReq,
            'pending'           => $byStatus['pending'],
            'rejected'          => $byStatus['rejected'],
        ];
    }
}
