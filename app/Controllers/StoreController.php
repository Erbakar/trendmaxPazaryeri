<?php
/**
 * OzyConnect Panel — StoreController (Faz 2: admin mağaza ENTITLEMENT yönetimi)
 *
 * Mağaza bazlı pazaryeri/modül yetkisi VER/KALDIR. YALNIZCA Super Admin (platform operatörü).
 * Her değişiklik EntitlementService üzerinden yapılır → system_audit_log'a yazılır
 * (kim/hangi mağaza/hangi pazaryeri-modül/eski-yeni/tarih).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Store;
use App\Models\Marketplace;
use App\Models\Package;
use App\Models\StoreSignupRequest;
use App\Services\EntitlementService;
use App\Services\StoreProvisioningService;

final class StoreController extends BaseController
{
    private readonly Store $storeModel;
    private readonly Marketplace $marketplaceModel;
    private readonly EntitlementService $entitlement;
    private readonly Package $packages;
    private readonly StoreSignupRequest $signups;
    private readonly StoreProvisioningService $provisioning;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->storeModel       = $container->get(Store::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->entitlement      = $container->get(EntitlementService::class);
        $this->packages         = $container->get(Package::class);
        $this->signups          = $container->get(StoreSignupRequest::class);
        $this->provisioning     = $container->get(StoreProvisioningService::class);
    }

    /** Admin'den direkt mağaza oluşturma formu. */
    public function create(): void
    {
        $this->requireSuperAdmin();
        $loc = $this->container->get(\App\Models\Location::class);
        $this->view('admin.store_create', [
            'pageTitle'       => 'Mağaza Oluştur',
            'packages'        => $this->packages->allAdmin(),
            'docTypes'        => $this->container->get(\App\Models\StoreDocumentType::class)->active(),
            'provinces'       => $loc->provinces(),
            'districtsByProv' => $loc->districtsGrouped(),
            '_adminEmail'     => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /**
     * Mağaza oluştur (POST) — başvuru kaydı aç + StoreProvisioningService ile provizyonla
     * (store + owner + trial abonelik + paket entitlement + şifre-belirleme maili). Şema değişmez.
     */
    public function store(Request $request): void
    {
        $this->requireSuperAdmin();

        $company = trim((string) $request->post('company', ''));
        $contact = trim((string) $request->post('contact_name', ''));
        $email   = trim((string) $request->post('email', ''));
        $phone   = trim((string) $request->post('phone', ''));
        $pkgId   = (int) $request->post('package_id', 0);
        $cycle   = $request->post('billing_cycle', 'monthly') === 'yearly' ? 'yearly' : 'monthly';

        if ($company === '' || $contact === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError('/admin/stores/create', 'Firma adı, yetkili kişi ve geçerli e-posta zorunludur.');
        }
        if ($pkgId <= 0) {
            $this->redirectWithError('/admin/stores/create', 'Lütfen bir paket seçin.');
        }

        // Admin şifreyi burada verebilir: doldurulursa firma hemen giriş yapar,
        // şifre belirleme e-postası hiç gönderilmez. Boşsa eski davranış sürer.
        $sifre     = trim((string) $request->post('password', ''));
        $sifreHash = null;

        if ($sifre !== '') {
            if (mb_strlen($sifre) < 8) {
                $this->redirectWithError('/admin/stores/create', 'Şifre en az 8 karakter olmalıdır.');
                return;
            }

            $sifreHash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        // Opsiyonel fatura/adres alanları (il/ilçe id'lerinden çözülür; boş → null).
        $billing = $this->billingFromRequest($request);

        try {
            $signupId = $this->signups->createRequest([
                'company'              => $company,
                'contact_name'         => $contact,
                'email'                => $email,
                'phone'                => $phone !== '' ? $phone : null,
                'requested_package_id' => $pkgId,
                'billing_cycle'        => $cycle,
                'source'               => 'admin',
                'status'               => 'pending',
                'ip'                   => $request->ip(),
                'password_hash'        => $sifreHash,
            ]);
            $res = $this->provisioning->provision($signupId, $pkgId, $cycle);
        } catch (\RuntimeException $e) {
            $this->redirectWithError('/admin/stores/create', 'Mağaza oluşturulamadı: ' . $e->getMessage());
            return;
        }

        $storeId = (int) $res['store_id'];

        // Fatura/adres bilgilerini kaydet (en az bir alan doluysa).
        if (array_filter($billing, static fn($v) => $v !== null) !== []) {
            $this->container->get(\App\Core\Database::class)->query(
                "UPDATE stores SET legal_name = :ln, tax_id = :ti, tax_office = :to,
                        billing_address = :ba, billing_district = :bd, billing_city = :bc,
                        province_id = :pid, district_id = :did, billing_postcode = :pc
                 WHERE id = :id",
                [
                    'ln' => $billing['legal_name'], 'ti' => $billing['tax_id'], 'to' => $billing['tax_office'],
                    'ba' => $billing['billing_address'], 'bd' => $billing['billing_district'], 'bc' => $billing['billing_city'],
                    'pid' => $billing['province_id'], 'did' => $billing['district_id'], 'pc' => $billing['billing_postcode'],
                    'id' => $storeId,
                ]
            );
        }

        // Yüklenen belgeleri iliştir (best-effort; tek belge hatası mağaza oluşumunu bozmaz).
        $docWarnings = $this->attachUploadedDocuments($request, $storeId);

        if ($sifreHash !== null) {
            $msg = 'Mağaza oluşturuldu (#' . $storeId . '). Firma, belirlediğiniz şifreyle '
                 . $email . ' adresiyle hemen giriş yapabilir. Şifreyi kendiniz iletin.';
        } else {
            $msg = 'Mağaza oluşturuldu (#' . $storeId . '). Sahibe şifre-belirleme bağlantısı gönderildi.';

            if (!empty($res['reset_token'])) {
                // Posta gitmezse admin bağlantıyı elle iletebilsin diye tam adres verilir
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $kok    = function_exists('app_base_url') && rtrim((string) app_base_url(), '/') !== ''
                    ? rtrim((string) app_base_url(), '/')
                    : $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

                $msg .= ' Posta ulaşmazsa bu bağlantıyı iletin: '
                      . $kok . '/password/reset?token=' . $res['reset_token'];
            }
        }
        if ($docWarnings !== []) {
            $msg .= ' Bazı belgeler yüklenemedi: ' . implode(' | ', $docWarnings);
        }
        $this->redirectWithSuccess('/stores/' . $storeId, $msg);
    }

    /**
     * Oluşturma formundan gelen belge dosyalarını yeni mağazaya iliştirir (KYC).
     * Her aktif tür için `doc_<id>` alanı; dosya yoksa atlanır. TenantContext yeni mağazaya
     * geçici set edilir (StoreDocumentService store'u tenant'tan zorlar → cross-tenant yazılamaz).
     *
     * @return list<string> kullanıcıya gösterilecek (tür: hata) uyarıları
     */
    private function attachUploadedDocuments(Request $request, int $storeId): array
    {
        $types = $this->container->get(\App\Models\StoreDocumentType::class)->active();
        if ($types === []) {
            return [];
        }
        $tenant     = $this->container->get(\App\Core\TenantContext::class);
        $docService = $this->container->get(\App\Services\StoreDocumentService::class);
        $warnings   = [];

        $tenant->setStoreId($storeId);
        try {
            foreach ($types as $t) {
                $tid  = (int) ($t['id'] ?? 0);
                if ($tid <= 0) { continue; }
                $file = $request->file('doc_' . $tid);
                if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue; // bu tür için dosya seçilmemiş
                }
                try {
                    $docService->upload($tid, $file, null);
                } catch (\RuntimeException $e) {
                    $warnings[] = (string) ($t['label'] ?? ('Belge #' . $tid)) . ': ' . $e->getMessage();
                }
            }
        } finally {
            $tenant->clear();
        }
        return $warnings;
    }

    /**
     * POST'tan vergi + fatura adresi kolonlarını çözer. İl/ilçe id'lerinden (province_id/district_id)
     * ad'lar provinces/districts'ten alınıp billing_city/billing_district'e yazılır (e-Arşiv uyumu).
     * İlçe seçili il'e ait değilse ilçe atılır. Boş metin alanları null döner.
     *
     * @return array<string, string|int|null>
     */
    private function billingFromRequest(Request $request): array
    {
        $cut = static function (string $v, int $n): ?string {
            $v = trim($v);
            return $v === '' ? null : mb_substr($v, 0, $n);
        };
        $loc = $this->container->get(\App\Models\Location::class);
        $pid = (int) $request->post('province_id', 0);
        $did = (int) $request->post('district_id', 0);
        $province = $pid > 0 ? $loc->province($pid) : false;
        $district = $did > 0 ? $loc->district($did) : false;
        $validDistrict = $district !== false && $province !== false && (int) $district['province_id'] === $pid;

        return [
            'legal_name'       => $cut((string) $request->post('legal_name', ''), 200),
            'tax_id'           => $cut((string) $request->post('tax_id', ''), 20),
            'tax_office'       => $cut((string) $request->post('tax_office', ''), 120),
            'billing_address'  => $cut((string) $request->post('billing_address', ''), 300),
            'billing_city'     => $province !== false ? mb_substr((string) $province['name'], 0, 80) : null,
            'billing_district' => $validDistrict ? mb_substr((string) $district['name'], 0, 80) : null,
            'billing_postcode' => $cut((string) $request->post('billing_postcode', ''), 10),
            'province_id'      => $province !== false ? $pid : null,
            'district_id'      => $validDistrict ? $did : null,
        ];
    }

    /** Faz 2 — entitlement yönetimi yalnız platform Super Admin'de. */
    private function requireSuperAdmin(): void
    {
        // Faz 3: platform admin (admin_users + AdminSession, 2FA dahil). Merchant erişemez.
        if (!$this->container->get(\App\Core\AdminSession::class)->isAuthenticated()) {
            $this->logger->warning('Yetkisiz mağaza-yetki ekranı erişim denemesi', 'Store', ['user' => $this->session->userId()]);
            $this->redirectWithError('/admin/login', 'Bu alan yalnızca platform admin yetkisine açıktır.');
        }
    }

    /** Mağaza listesi. */
    public function index(): void
    {
        $this->requireSuperAdmin();
        $this->view('stores.index', [
            'pageTitle'   => 'Mağazalar',
            'stores'      => $this->storeModel->allStoresEnriched(),
            '_adminEmail' => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /** Mağaza yönetim/detay sayfası — sekmeli (Genel·Abonelik·Faturalar·Siparişler·Belgeler·Kullanıcılar). */
    public function show(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $store = $this->storeModel->find($storeId);
        if ($store === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $db = $this->container->get(\App\Core\Database::class);

        $users = $db->fetchAll(
            "SELECT id, name, email, role, is_active, last_login_at, created_at FROM users WHERE store_id = :s ORDER BY id ASC",
            ['s' => $storeId]
        );
        $sub = $db->fetchOne(
            "SELECT sub.*, p.name AS package_name, p.slug AS package_slug
             FROM subscriptions sub LEFT JOIN packages p ON p.id = sub.package_id
             WHERE sub.store_id = :s LIMIT 1",
            ['s' => $storeId]
        );
        $subHistory = $db->fetchAll(
            "SELECT h.*, np.name AS new_pkg, op.name AS old_pkg
             FROM subscription_history h
             LEFT JOIN packages np ON np.id = h.new_package_id
             LEFT JOIN packages op ON op.id = h.old_package_id
             WHERE h.store_id = :s ORDER BY h.id DESC LIMIT 25",
            ['s' => $storeId]
        );
        $invoices = $db->fetchAll(
            "SELECT * FROM subscription_invoices WHERE store_id = :s ORDER BY id DESC LIMIT 50",
            ['s' => $storeId]
        );
        $orders = $db->fetchAll(
            "SELECT id, marketplace_order_id, status, customer_name, total_amount, currency, ordered_at, created_at
             FROM orders WHERE store_id = :s ORDER BY COALESCE(ordered_at, created_at) DESC LIMIT 50",
            ['s' => $storeId]
        );
        $ordersCount = (int) ($db->fetchOne("SELECT COUNT(*) c FROM orders WHERE store_id = :s", ['s' => $storeId])['c'] ?? 0);
        $documents = $db->fetchAll(
            "SELECT d.*, t.label AS type_label FROM store_documents d
             LEFT JOIN store_document_types t ON t.id = d.doc_type_id
             WHERE d.store_id = :s ORDER BY d.id DESC",
            ['s' => $storeId]
        );

        $this->view('admin.store_detail', [
            'pageTitle'   => (string) ($store['name'] ?? 'Mağaza') . ' — Mağaza',
            'store'       => $store,
            'users'       => $users,
            'sub'         => $sub,
            'subHistory'  => $subHistory,
            'invoices'    => $invoices,
            'orders'      => $orders,
            'ordersCount' => $ordersCount,
            'documents'   => $documents,
            'packages'    => $this->packages->allAdmin(),
            'provinces'       => $this->container->get(\App\Models\Location::class)->provinces(),
            'districtsByProv' => $this->container->get(\App\Models\Location::class)->districtsGrouped(),
            'mpCount'     => count($this->rawStoreRows('store_marketplaces', 'marketplace_id', $storeId)),
            'modCount'    => count($this->rawStoreRows('store_modules', 'module_key', $storeId)),
            '_adminEmail' => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /** Genel sekmesi düzenleme (ad/durum/plan). slug DEĞİŞMEZ (URL/kayıt bütünlüğü). */
    public function update(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        if ($this->storeModel->find($storeId) === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $name   = trim((string) $request->post('name', ''));
        $status = $request->post('status', 'active');
        $status = in_array($status, ['active', 'suspended', 'archived'], true) ? $status : 'active';
        $plan   = trim((string) $request->post('plan', '')) ?: null;
        if ($name === '') {
            $this->redirectWithError("/stores/{$storeId}", 'Mağaza adı zorunludur.');
        }
        $billing = $this->billingFromRequest($request);
        $this->container->get(\App\Core\Database::class)->query(
            "UPDATE stores SET name = :n, status = :st, plan = :p,
                    legal_name = :ln, tax_id = :ti, tax_office = :to,
                    billing_address = :ba, billing_district = :bd, billing_city = :bc,
                    province_id = :pid, district_id = :did, billing_postcode = :pc
             WHERE id = :id",
            [
                'n'  => mb_substr($name, 0, 200), 'st' => $status,
                'p'  => $plan !== null ? mb_substr($plan, 0, 50) : null,
                'ln' => $billing['legal_name'], 'ti' => $billing['tax_id'], 'to' => $billing['tax_office'],
                'ba' => $billing['billing_address'], 'bd' => $billing['billing_district'], 'bc' => $billing['billing_city'],
                'pid' => $billing['province_id'], 'did' => $billing['district_id'], 'pc' => $billing['billing_postcode'],
                'id' => $storeId,
            ]
        );
        $this->audit($storeId, 'store.update', 'status=' . $status);
        $this->redirectWithSuccess("/stores/{$storeId}", 'Mağaza güncellendi.');
    }

    /** Hızlı durum değişimi (askıya al / aktifleştir / arşivle). */
    public function setStatus(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        if ($this->storeModel->find($storeId) === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $status = $request->post('status', '');
        if (!in_array($status, ['active', 'suspended', 'archived'], true)) {
            $this->redirectWithError("/stores/{$storeId}", 'Geçersiz durum.');
        }
        $this->container->get(\App\Core\Database::class)->query(
            "UPDATE stores SET status = :st WHERE id = :id",
            ['st' => $status, 'id' => $storeId]
        );
        $this->audit($storeId, 'store.status', $status);
        $labels = ['active' => 'aktifleştirildi', 'suspended' => 'askıya alındı', 'archived' => 'arşivlendi'];
        $this->redirectWithSuccess("/stores/{$storeId}", 'Mağaza ' . ($labels[$status] ?? 'güncellendi') . '.');
    }

    /** Mağaza doğrulamasını elle aç/kapat (verified_at). */
    public function verify(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        if ($this->storeModel->find($storeId) === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $on = $request->post('action', 'verify') === 'verify';
        $db = $this->container->get(\App\Core\Database::class);
        if ($on) {
            $db->query("UPDATE stores SET verified_at = COALESCE(verified_at, NOW()) WHERE id = :id", ['id' => $storeId]);
        } else {
            $db->query("UPDATE stores SET verified_at = NULL WHERE id = :id", ['id' => $storeId]);
        }
        $this->audit($storeId, 'store.verify', $on ? 'verified' : 'unverified');
        $this->redirectWithSuccess("/stores/{$storeId}", $on ? 'Mağaza doğrulandı.' : 'Doğrulama kaldırıldı.');
    }

    /** Mağazanın işlem kaydını (audit) CSV olarak indir. */
    public function auditLog(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        if ($this->storeModel->find($storeId) === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $rows = $this->container->get(\App\Services\StoreExportService::class)->auditRows($storeId);
        $this->audit($storeId, 'store.audit_export', 'csv');
        $fname = 'magaza-' . $storeId . '-islem-kaydi-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel uyumu)
        fputcsv($out, ['Tarih', 'Aktör', 'E-posta', 'İşlem', 'Hedef', 'Sonuç', 'IP']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['created_at'] ?? '', $r['actor_type'] ?? '', $r['actor_email'] ?? '', $r['action'] ?? '', $r['target'] ?? '', $r['result'] ?? '', $r['ip_address'] ?? '']);
        }
        fclose($out);
        exit;
    }

    /** Mağazanın tüm verisini .sql olarak dışa aktar (arşiv/taşıma/KVKK; otomatik geri-yükleme amaçlı DEĞİL). */
    public function dataExport(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        if ($this->storeModel->find($storeId) === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }
        $sql = $this->container->get(\App\Services\StoreExportService::class)->dumpStoreSql($storeId, date('c'));
        $this->audit($storeId, 'store.data_export', 'sql');
        $fname = 'magaza-' . $storeId . '-veri-' . date('Ymd-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $sql;
        exit;
    }

    /** Bir abonelik faturasına ELLE e-Arşiv kes (otomatik kuyruk dışında; test/yeniden-deneme). */
    public function issueEarsiv(Request $request, string $id, string $invId): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $invoiceId = (int) $invId;
        $db = $this->container->get(\App\Core\Database::class);
        if ($db->fetchOne("SELECT id FROM subscription_invoices WHERE id = :i AND store_id = :s", ['i' => $invoiceId, 's' => $storeId]) === false) {
            $this->redirectWithError("/stores/{$storeId}", 'Fatura bu mağazaya ait değil.');
        }
        $res = $this->container->get(\App\Services\SubscriptionInvoiceService::class)->issue($invoiceId, true);
        $this->audit($storeId, 'invoice.earsiv_issue', (string) ($res['status'] ?? ''));
        if (!empty($res['ok'])) {
            $this->redirectWithSuccess("/stores/{$storeId}", (string) $res['message']);
        }
        $this->redirectWithError("/stores/{$storeId}", (string) ($res['message'] ?? 'e-Arşiv kesilemedi.'));
    }

    /** Kesilmiş e-Arşiv faturasını iptal et. */
    public function cancelEarsiv(Request $request, string $id, string $invId): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $invoiceId = (int) $invId;
        $db = $this->container->get(\App\Core\Database::class);
        if ($db->fetchOne("SELECT id FROM subscription_invoices WHERE id = :i AND store_id = :s", ['i' => $invoiceId, 's' => $storeId]) === false) {
            $this->redirectWithError("/stores/{$storeId}", 'Fatura bu mağazaya ait değil.');
        }
        $res = $this->container->get(\App\Services\SubscriptionInvoiceService::class)->cancel($invoiceId, 'Admin iptali');
        $this->audit($storeId, 'invoice.earsiv_cancel', (string) ($res['status'] ?? ''));
        if (!empty($res['ok'])) {
            $this->redirectWithSuccess("/stores/{$storeId}", (string) $res['message']);
        }
        $this->redirectWithError("/stores/{$storeId}", (string) ($res['message'] ?? 'İptal edilemedi.'));
    }

    /** system_audit_log'a admin işlemi yaz (best-effort). */
    private function audit(int $storeId, string $action, ?string $target = null): void
    {
        try {
            $as = $this->container->get(\App\Core\AdminSession::class);
            $this->container->get(\App\Core\Database::class)->query(
                "INSERT INTO system_audit_log (admin_user_id, store_id, actor_type, actor_email, action, target, result)
                 VALUES (:aid, :sid, 'admin', :em, :act, :tgt, 'success')",
                ['aid' => $as->adminId(), 'sid' => $storeId, 'em' => $as->adminEmail(), 'act' => $action, 'tgt' => $target]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Audit log yazılamadı', 'Store', ['err' => $e->getMessage()]);
        }
    }

    /** Bir mağazanın pazaryeri + modül entitlement ekranı. */
    public function entitlements(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $store = $this->storeModel->find($storeId);
        if ($store === false) {
            $this->redirectWithError('/stores', 'Mağaza bulunamadı.');
        }

        $marketplaces = $this->marketplaceModel->all([], 'display_name ASC');
        $smRows = $this->indexBy($this->rawStoreRows('store_marketplaces', 'marketplace_id', $storeId), 'marketplace_id');
        $modRows = $this->indexBy($this->rawStoreRows('store_modules', 'module_key', $storeId), 'module_key');

        // (E) Paket-default kümesi → her yetkide "Paket"/"Ekstra" ayrımı + (F) modül etiketleri module_catalog'tan
        $sub = $this->container->get(\App\Core\Database::class)->fetchOne(
            "SELECT package_id FROM subscriptions WHERE store_id = :s LIMIT 1", ['s' => $storeId]
        );
        $pkgId = $sub !== false ? (int) $sub['package_id'] : 0;

        $this->view('stores.entitlements', [
            'pageTitle'    => 'Mağaza Yetkileri',
            'store'        => $store,
            'marketplaces' => $marketplaces,
            'smRows'       => $smRows,
            'modules'      => EntitlementService::ENTITLEMENT_MODULES,
            'modRows'      => $modRows,
            'pkgMpIds'     => $pkgId > 0 ? $this->packages->marketplaceIds($pkgId) : [],
            'pkgModKeys'   => $pkgId > 0 ? $this->packages->moduleKeys($pkgId) : [],
            'moduleLabels' => $this->container->get(\App\Models\ModuleCatalog::class)->labelMap(),
            '_adminEmail'  => $this->container->get(\App\Core\AdminSession::class)->adminEmail(),
        ], 'admin');
    }

    /** Pazaryeri yetkisi ver/kaldır (POST). */
    public function marketplace(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $mpId    = (int) $request->post('marketplace_id', 0);
        $action  = $request->post('action', '');
        $reason  = trim((string) $request->post('reason', '')) ?: null;

        if ($mpId <= 0) {
            $this->redirectWithError("/stores/{$storeId}/entitlements", 'Geçersiz pazaryeri.');
        }
        if ($action === 'grant') {
            $expires = trim((string) $request->post('expires_at', '')) ?: null;
            $this->entitlement->grantMarketplace($storeId, $mpId, $expires, $reason);
            $this->redirectWithSuccess("/stores/{$storeId}/entitlements", 'Pazaryeri yetkisi verildi.');
        }
        $this->entitlement->revokeMarketplace($storeId, $mpId, $reason);
        $this->redirectWithSuccess("/stores/{$storeId}/entitlements", 'Pazaryeri yetkisi kaldırıldı.');
    }

    /** Modül yetkisi ver/kaldır (POST). */
    public function module(Request $request, string $id): void
    {
        $this->requireSuperAdmin();
        $storeId = (int) $id;
        $key     = trim((string) $request->post('module_key', ''));
        $action  = $request->post('action', '');
        $reason  = trim((string) $request->post('reason', '')) ?: null;

        if ($key === '' || !in_array($key, EntitlementService::ENTITLEMENT_MODULES, true)) {
            $this->redirectWithError("/stores/{$storeId}/entitlements", 'Geçersiz modül.');
        }
        if ($action === 'grant') {
            $expires = trim((string) $request->post('expires_at', '')) ?: null;
            $this->entitlement->grantModule($storeId, $key, $expires, $reason);
            $this->redirectWithSuccess("/stores/{$storeId}/entitlements", 'Modül yetkisi verildi.');
        }
        $this->entitlement->revokeModule($storeId, $key, $reason);
        $this->redirectWithSuccess("/stores/{$storeId}/entitlements", 'Modül yetkisi kaldırıldı.');
    }

    /** @return array<int,array<string,mixed>> */
    private function rawStoreRows(string $table, string $col, int $storeId): array
    {
        // $table/$col sabit (kullanıcı girdisi değil).
        return $this->container->get(\App\Core\Database::class)->fetchAll(
            "SELECT * FROM `{$table}` WHERE store_id = :sid",
            ['sid' => $storeId]
        );
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string|int,array<string,mixed>> */
    private function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[$key]] = $r;
        }
        return $out;
    }
}
