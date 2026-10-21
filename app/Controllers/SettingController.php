<?php
/**
 * OzyConnect Panel — SettingController (Merchant Ayarlar kabuğu)
 *
 * settings.html mockup tasarımı: sol bölüm menüsü + sağ içerik.
 * Her bölüm ya bir store_settings formu (genel/mağaza/kargo/görünüm/güvenlik),
 * ya da mevcut bir özellik görünümünü (sync/import/destek/güncellemeler/fatura/
 * api/ekip/roller/loglar) gömerek render eder — böylece tüm işlevsellik korunur.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Location;
use App\Models\Store;
use App\Models\StoreDocument;
use App\Models\StoreSetting;
use App\Services\AuthorizationService;

final class SettingController extends BaseController
{
    /** Sol menü bölümleri (slug => etiket/ikon/grup). */
    private const SECTIONS = [
        'magaza'        => ['label' => 'Mağaza',           'icon' => 'store',              'group' => 'Mağaza'],
        'fatura'        => ['label' => 'Fatura & GİB',     'icon' => 'file-text',          'group' => 'Mağaza'],
        'bildirimler'   => ['label' => 'Bildirimler',      'icon' => 'bell',               'group' => 'Mağaza'],
        'gorunum'       => ['label' => 'Görünüm',          'icon' => 'palette',            'group' => 'Mağaza'],
        'api'           => ['label' => 'API & Webhooks',   'icon' => 'webhook',            'group' => 'Sistem'],
        'ekip'          => ['label' => 'Ekip',             'icon' => 'users',              'group' => 'Sistem'],
        'roller'        => ['label' => 'Roller & Yetkiler','icon' => 'shield-check',       'group' => 'Sistem'],
        'guvenlik'      => ['label' => 'Güvenlik',         'icon' => 'lock',               'group' => 'Sistem'],
        'sync'          => ['label' => 'Senkronizasyon',   'icon' => 'refresh-cw',         'group' => 'Araçlar'],
        'import'        => ['label' => 'İçe / Dışa Aktarma','icon' => 'download-cloud',     'group' => 'Araçlar'],
        'destek'        => ['label' => 'Destek',           'icon' => 'life-buoy',          'group' => 'Araçlar'],
        'guncellemeler' => ['label' => 'Güncellemeler',    'icon' => 'megaphone',          'group' => 'Araçlar'],
    ];

    /** Mevcut özellik görünümünü gömerek render edilen bölümler (slug => view). */
    private const SECTION_VIEWS = [
        'fatura'        => 'invoices.settings',
        'api'           => 'settings.api_tokens',
        'ekip'          => 'users.index',
        'roller'        => 'roles.index',
        'sync'          => 'sync.index',
        'import'        => 'import.index',
        'destek'        => 'support.index',
        'guncellemeler' => 'updates.index',
    ];

    /**
     * Bölüm görünürlük/erişim kuralı (slug => [RBAC izinleri, entitlement modülü]).
     * main.php üst-menü filtresiyle TUTARLI: satın alınmamış (entitlement-gated) modül
     * veya yetkisiz bölüm gizlenir VE doğrudan URL ile açılamaz. Burada listelenmeyen
     * bölümler her zaman görünür (yalnız /settings rotasının settings.view'ı ile korunur).
     *
     * @var array<string, array{0: array<int, string>, 1: ?string}>
     */
    private const SECTION_ACCESS = [
        'fatura' => [['invoices.view'], 'invoices'], // Fatura & GİB → invoices modülü
        'ekip'   => [['users.view'],    null],       // Ekip → users.view (RBAC)
        'roller' => [['roles.view'],    null],       // Roller & Yetkiler → roles.view (RBAC)
        'sync'   => [['sync.trigger'],  'sync'],     // Senkronizasyon → sync modülü
        'import' => [['products.view', 'products.create'], 'import'], // İçe/Dışa Aktarma → import modülü
    ];

    /** store_settings'e kaydedilen bölümler ve izin verilen anahtarları. */
    private const SAVE_KEYS = [
        'magaza'   => ['store_name', 'store_email', 'store_phone', 'store_address', 'store_description'],
        'gorunum'  => ['appearance_theme', 'appearance_accent', 'appearance_density'],
        'guvenlik' => ['security_session_timeout', 'security_login_alerts'],
    ];

    private readonly StoreSetting $storeSetting;
    private readonly Store $storeModel;
    private readonly TenantContext $tenant;
    private readonly Location $location;
    private readonly StoreDocument $storeDoc;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->storeSetting = $container->get(StoreSetting::class);
        $this->storeModel   = $container->get(Store::class);
        $this->tenant       = $container->get(TenantContext::class);
        $this->location     = $container->get(Location::class);
        $this->storeDoc     = $container->get(StoreDocument::class);
    }

    public function index(Request $request, ?string $section = null): void
    {
        $section = $this->resolveSection($section, $request);

        // Görünür bölümler = RBAC ∩ Store Entitlement (admin'in mağazaya tanımladığı modüller).
        $visibleSections = $this->visibleSections();

        // Doğrudan URL koruması ("menüde yok ≠ güvenlik"): satın alınmamış/yetkisiz bölüme
        // erişim ilk görünür bölüme yönlenir — gömülü sync/import/fatura görünümü render EDİLMEZ.
        if (!isset($visibleSections[$section])) {
            $fallback = (string) (array_key_first($visibleSections) ?: 'magaza');
            $this->redirectWithError('/settings/' . $fallback, 'Bu bölüme erişim yetkiniz yok veya mağaza paketinizde bulunmuyor.');
            return;
        }

        $payload = [
            'pageTitle'     => 'Ayarlar',
            'activeSection' => $section,
            'sections'      => $visibleSections,
            'sectionView'   => self::SECTION_VIEWS[$section] ?? null,
        ];
        $payload += $this->sectionData($section);

        $this->view('settings.index', $payload);
    }

    /**
     * Aktif mağaza için görünür bölümler (SECTION_ACCESS kuralına göre RBAC ∩ entitlement).
     *
     * @return array<string, array{label: string, icon: string, group: string}>
     */
    private function visibleSections(): array
    {
        /** @var AuthorizationService $auth */
        $auth = $this->container->get(AuthorizationService::class);
        $visible = [];
        foreach (self::SECTIONS as $slug => $meta) {
            [$perms, $module] = self::SECTION_ACCESS[$slug] ?? [[], null];
            if ($auth->menuItemAllowed($perms, $module)) {
                $visible[$slug] = $meta;
            }
        }
        return $visible;
    }

    public function save(Request $request, ?string $section = null): void
    {
        $section = (string) ($section ?: $request->post('active_tab', 'magaza'));
        if ($section === 'profile') { $section = 'magaza'; }
        if ($section === 'sounds')  { $section = 'bildirimler'; }

        // Güvenlik bölümü: kullanıcının KENDİ şifresini değiştirmesi (yalnız oturum + mevcut şifre ister)
        if ($section === 'guvenlik' && $request->post('sifre_degistir') === '1') {
            $this->changeOwnPassword($request);
            return;
        }

        // Mağaza ayarlarını değiştirmek settings.edit ister (rota haritasında 'save' bölüm bazlı
        // olduğu için burada denetlenir; settings.view tek başına kayıt yetkisi vermez).
        if (!$this->container->get(AuthorizationService::class)->hasPermission('settings.edit')) {
            $this->redirectWithError('/settings/' . ($this->isSection($section) ? $section : 'magaza'), 'Ayarları değiştirme yetkiniz yok.');
            return;
        }
        if (!isset(self::SAVE_KEYS[$section])) {
            $this->redirectWithError('/settings/' . ($this->isSection($section) ? $section : 'magaza'), 'Bu bölüm kaydedilemez.');
            return;
        }

        try {
            if ($section === 'magaza') {
                $logoPath = $this->handleStoreLogoUpload($request);
                if ($logoPath !== null) {
                    $previousLogo = trim((string) $this->storeSetting->getValue('store_logo', ''));
                    $this->deleteUploadedStoreLogo($previousLogo);
                    $this->storeSetting->setValue('store_logo', $logoPath);
                }
            }

            $in = $request->post('s', []);
            if (is_array($in)) {
                $pairs = [];
                foreach (self::SAVE_KEYS[$section] as $f) {
                    if (array_key_exists($f, $in)) {
                        $pairs[$f] = mb_substr(trim((string) $in[$f]), 0, 1000);
                    }
                }
                if ($pairs !== []) {
                    $this->storeSetting->setMany($pairs);
                }
            }

            // Mağaza bölümü: vergi + fatura adresi (il/ilçe dahil) stores tablosuna yazılır
            // (admin "Mağaza Düzenle" ile AYNI kolonlar → admin & merchant aynı veriyi görür).
            if ($section === 'magaza') {
                $this->saveStoreBilling($request);
            }
        } catch (\RuntimeException $e) {
            $this->redirectWithError('/settings/' . $section, $e->getMessage());
            return;
        }

        $this->redirectWithSuccess('/settings/' . $section, 'Ayarlar güncellendi');
    }

    /* ─── Yardımcılar ─────────────────────────────────────── */

    private function isSection(string $s): bool
    {
        return isset(self::SECTIONS[$s]);
    }

    private function resolveSection(?string $section, Request $request): string
    {
        if ($section === null || $section === '') {
            $tab = (string) $request->query('tab', '');
            if ($tab === 'sounds')  { return 'bildirimler'; }
            if ($tab === 'profile') { return 'magaza'; }
            return 'magaza';
        }
        return $this->isSection($section) ? $section : 'magaza';
    }

    /** Mağaza profili — store_settings (kimlik/iletişim) + stores (vergi/fatura adresi) + belge durumu. */
    /**
     * Oturumdaki kullanıcının kendi şifresini değiştirmesi.
     *
     * Yönetici "Ekip > kullanıcı düzenle" ekranından BAŞKASININ şifresini değiştirebiliyordu;
     * kullanıcının kendi şifresini değiştirebileceği bir yer yoktu (Güvenlik bölümündeki
     * bağlantı var olmayan /profile sayfasına gidiyordu). Mevcut şifre doğrulaması şarttır.
     */
    private function changeOwnPassword(Request $request): void
    {
        $userId = (int) ($this->session->userId() ?? 0);
        if ($userId <= 0) {
            $this->redirectWithError('/settings/guvenlik', 'Oturum bulunamadı, lütfen tekrar giriş yapın.');
            return;
        }

        $current = (string) $request->post('mevcut_sifre', '');
        $new     = (string) $request->post('yeni_sifre', '');
        $repeat  = (string) $request->post('yeni_sifre_tekrar', '');

        if ($current === '' || $new === '') {
            $this->redirectWithError('/settings/guvenlik', 'Mevcut ve yeni şifre alanları zorunludur.');
            return;
        }
        if ($new !== $repeat) {
            $this->redirectWithError('/settings/guvenlik', 'Yeni şifreler birbiriyle eşleşmiyor.');
            return;
        }
        if (mb_strlen($new) < 8) {
            $this->redirectWithError('/settings/guvenlik', 'Yeni şifre en az 8 karakter olmalıdır.');
            return;
        }
        if ($new === $current) {
            $this->redirectWithError('/settings/guvenlik', 'Yeni şifre mevcut şifreyle aynı olamaz.');
            return;
        }

        /** @var \App\Models\User $userModel */
        $userModel = $this->container->get(\App\Models\User::class);
        $user = $userModel->findAuthUserById($userId);

        if ($user === false || !$userModel->verifyPassword($current, (string) ($user['password_hash'] ?? ''))) {
            $this->logger->warning("Şifre değiştirme reddedildi (mevcut şifre hatalı): kullanıcı #{$userId}", 'Auth');
            $this->redirectWithError('/settings/guvenlik', 'Mevcut şifreniz hatalı.');
            return;
        }

        $userModel->updatePasswordGlobal($userId, $new);
        $this->logger->info("Kullanıcı kendi şifresini değiştirdi: #{$userId}", 'Auth');

        $this->redirectWithSuccess('/settings/guvenlik', 'Şifreniz güncellendi.');
    }

    private function profileData(): array
    {
        $store = $this->storeModel->find($this->tenant->currentStoreId()) ?: [];
        return [
            'store_name'        => $this->storeSetting->getValue('store_name', (string) ($store['name'] ?? '')),
            'store_email'       => $this->storeSetting->getValue('store_email', ''),
            'store_phone'       => $this->storeSetting->getValue('store_phone', ''),
            'store_address'     => $this->storeSetting->getValue('store_address', ''),
            'store_description' => $this->storeSetting->getValue('store_description', ''),
            'store_logo'        => $this->storeSetting->getValue('store_logo', ''),
            // Vergi + fatura adresi (stores tablosu — admin "Mağaza Düzenle" ile ortak alanlar)
            'legal_name'        => (string) ($store['legal_name'] ?? ''),
            'tax_id'            => (string) ($store['tax_id'] ?? ''),
            'tax_office'        => (string) ($store['tax_office'] ?? ''),
            'billing_address'   => (string) ($store['billing_address'] ?? ''),
            'billing_city'      => (string) ($store['billing_city'] ?? ''),
            'billing_district'  => (string) ($store['billing_district'] ?? ''),
            'billing_postcode'  => (string) ($store['billing_postcode'] ?? ''),
            'province_id'       => (int) ($store['province_id'] ?? 0),
            'district_id'       => (int) ($store['district_id'] ?? 0),
            // Belge doğrulama durumu (kapı + rozet için)
            'doc_verified'      => !empty($store['verified_at']),
            'doc_pending'       => $this->storeDoc->currentStorePendingCount(),
        ];
    }

    /**
     * Mağaza bölümü vergi + fatura adresini stores tablosuna yazar (tenant'ın kendi kaydı).
     * İl/ilçe id'leri provinces/districts'ten doğrulanır; ad'ları billing_city/billing_district'e
     * yazılır (e-Arşiv uyumu). Geçersiz/boş seçim ilgili kolonları temizler.
     */
    private function saveStoreBilling(Request $request): void
    {
        $b = $request->post('b', []);
        if (!is_array($b)) {
            return;
        }
        $cut = static fn(string $v, int $n): string => mb_substr(trim($v), 0, $n);

        $update = [
            'legal_name'       => $cut((string) ($b['legal_name'] ?? ''), 200),
            'tax_id'           => $cut((string) ($b['tax_id'] ?? ''), 20),
            'tax_office'       => $cut((string) ($b['tax_office'] ?? ''), 120),
            'billing_address'  => $cut((string) ($b['billing_address'] ?? ''), 300),
            'billing_postcode' => $cut((string) ($b['billing_postcode'] ?? ''), 10),
        ];

        // İl: id'den ad çöz; ilçe il'e ait olmalı.
        $pid = (int) ($b['province_id'] ?? 0);
        $did = (int) ($b['district_id'] ?? 0);
        $province = $pid > 0 ? $this->location->province($pid) : false;
        if ($province !== false) {
            $update['province_id']  = $pid;
            $update['billing_city'] = $cut((string) $province['name'], 80);
            $district = $did > 0 ? $this->location->district($did) : false;
            if ($district !== false && (int) $district['province_id'] === $pid) {
                $update['district_id']      = $did;
                $update['billing_district'] = $cut((string) $district['name'], 80);
            } else {
                $update['district_id']      = null;
                $update['billing_district'] = '';
            }
        } else {
            $update['province_id']      = null;
            $update['district_id']      = null;
            $update['billing_city']     = '';
            $update['billing_district'] = '';
        }

        $this->storeModel->update($this->tenant->currentStoreId(), $update);
    }

    /**
     * Aktif bölümün ihtiyaç duyduğu veriyi toplar. store_settings bölümleri için
     * 'sv' (anahtar=>değer) + 'profile'; gömülü görünüm bölümleri için o görünümün
     * controller'ındaki index() ile birebir aynı anahtarlar döndürülür.
     */
    private function sectionData(string $section): array
    {
        $c = $this->container;

        switch ($section) {
            case 'magaza':
                return [
                    'sv'              => $this->storeSetting->allForStore(),
                    'profile'         => $this->profileData(),
                    'provinces'       => $this->location->provinces(),
                    'districtsByProv' => $this->location->districtsGrouped(),
                ];

            case 'gorunum':
            case 'guvenlik':
                return ['sv' => $this->storeSetting->allForStore(), 'profile' => $this->profileData()];

            case 'bildirimler':
                return []; // tarayıcı (localStorage) tabanlı

            case 'fatura':
                // Per-store: fatura/GİB ayarları mağazaya özel (store_settings)
                return ['values' => $this->storeSetting->allForStore()];

            case 'api':
                $tm = $c->get(\App\Models\ApiToken::class);
                $tokens = $tm->allWithCreator();
                foreach ($tokens as &$t) {
                    $t['stats'] = $tm->getUsageStats((int) $t['id']);
                    $t['permissions_list'] = json_decode($t['permissions'] ?? '[]', true) ?: [];
                }
                unset($t);
                return ['tokens' => $tokens];

            case 'ekip':
                return ['users' => $c->get(\App\Models\User::class)->allWithRoles()];

            case 'roller':
                $rm = $c->get(\App\Models\Role::class);
                return [
                    'roles'         => $rm->allWithCounts(),
                    'permissionMap' => \App\Models\Role::PERMISSION_MAP,
                    'moduleLabels'  => \App\Models\Role::MODULE_LABELS,
                ];

            case 'sync':
                // /sync ile TEK veri kaynağı: mağaza ayarları (store_settings) + yetkili pazaryerleri.
                // Platform cron anahtarı mağaza ekranında üretilmez/gösterilmez (H15).
                return $c->get(\App\Controllers\SyncController::class)->pageData();

            case 'import':
                $mk = $c->get(\App\Models\Marketplace::class);
                $ij = $c->get(\App\Models\ProductImportJob::class);
                $st = $c->get(\App\Models\Setting::class);
                $ft = $st->get('feed_token', '');
                if (empty($ft)) { $ft = bin2hex(random_bytes(16)); $st->set('feed_token', $ft); }
                return [
                    'marketplaces'    => $mk->getActive(),
                    'recentJobs'      => $ij->all([], 'id DESC', 20),
                    // XML ekranı buraya GÖMÜLÜ render ediliyor; bu üç anahtar eksik olduğu
                    // için alan eşleştirme satırları hiç üretilmiyor ve ekran
                    // "Zorunlu alanları eşleştirin" hatasında kilitleniyordu.
                    'xmlFields'       => \App\Services\Import\XmlFileImportService::SYSTEM_FIELDS,
                    'xmlUploadLimit'  => (string) ini_get('upload_max_filesize'),
                    'xmlUploadBytes'  => \App\Services\Import\XmlFileImportService::uploadLimitBytes(),
                    'feedToken'       => $ft,
                    'feedIpWhitelist' => $st->get('feed_ip_whitelist', ''),
                    'feedPassword'    => $st->get('feed_password', ''),
                    'feedEnabled'     => (bool) $st->get('feed_enabled', '0'),
                ];

            case 'destek':
                $tk = $c->get(\App\Models\SupportTicket::class);
                return [
                    'tickets' => $tk->forStore(null),
                    'counts'  => $tk->statusCountsForStore(),
                    'current' => '',
                ];

            case 'guncellemeler':
                return ['updates' => $c->get(\App\Models\AppUpdate::class)->published()];
        }

        return [];
    }

    private function handleStoreLogoUpload(Request $request): ?string
    {
        $file = $request->file('store_logo');
        if ($file === null || !is_array($file)) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Logo yüklemesi tamamlanamadı.');
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Logo dosyası doğrulanamadı.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new \RuntimeException('Logo dosyası en fazla 5 MB olabilir.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        if (!isset($extensions[$mime])) {
            throw new \RuntimeException('Logo formatı PNG, JPG, WEBP veya SVG olmalı.');
        }
        $subDir = date('Y/m');
        $uploadDir = BASE_PATH . '/public/uploads/branding/' . $subDir;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Logo klasörü oluşturulamadı.');
        }
        $filename = 'store-' . $this->tenant->currentStoreId() . '-logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $targetPath = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new \RuntimeException('Logo dosyası kaydedilemedi.');
        }
        $relativePath = 'uploads/branding/' . $subDir . '/' . $filename;
        return function_exists('public_url') ? public_url($relativePath) : '/public/' . $relativePath;
    }

    private function deleteUploadedStoreLogo(string $logoPath): void
    {
        if ($logoPath === '') {
            return;
        }
        $baseDir = realpath(BASE_PATH . '/public/uploads/branding');
        $path = (string) (parse_url($logoPath, PHP_URL_PATH) ?: $logoPath);
        $publicPrefix = function_exists('url')
            ? (string) (parse_url(url('/public'), PHP_URL_PATH) ?: '/public')
            : '/public';
        $relativePath = null;
        foreach (array_unique([rtrim($publicPrefix, '/') . '/uploads/branding/', '/public/uploads/branding/']) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $relativePath = substr($path, strlen($prefix));
                break;
            }
        }
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        $filePath = BASE_PATH . '/public/uploads/branding/' . ltrim($relativePath, '/');
        $realPath = realpath($filePath);
        if ($baseDir === false || $realPath === false) {
            return;
        }
        if (!str_starts_with($realPath, $baseDir . DIRECTORY_SEPARATOR)) {
            return;
        }
        if (is_file($realPath)) {
            @unlink($realPath);
        }
    }
}
