<?php
/**
 * OzyConnect Panel — XmlProfileController
 *
 * XML Profilleri (Ticimax/dbentegrasyon tarzı): içe & dışa aktarım profilleri,
 * periyotlu senkron, profil işlemleri (Profili Sil, Stokları Sıfırla, Ürünleri Sil,
 * Kayıtlar) ve public dışa aktarım ucu (/xml-islemleri/xml-ver-x?profil=TOKEN).
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Helpers\StringHelper;
use App\Models\XmlProfile;
use App\Models\XmlProfileLog;
use App\Services\Feed\XmlProfileExportService;
use App\Models\Brand;
use App\Models\Category;
use App\Services\Import\XmlProfileImportService;
use App\Services\Import\XmlProfileSettings;

final class XmlProfileController extends BaseController
{
    private readonly XmlProfile $profileModel;
    private readonly XmlProfileLog $logModel;
    private readonly XmlProfileImportService $importService;
    private readonly XmlProfileExportService $exportService;
    private readonly XmlProfileSettings $profileSettings;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->profileModel  = $container->get(XmlProfile::class);
        $this->logModel      = $container->get(XmlProfileLog::class);
        $this->importService = $container->get(XmlProfileImportService::class);
        $this->exportService = $container->get(XmlProfileExportService::class);
        $this->profileSettings = $container->get(XmlProfileSettings::class);
    }

    public function index(Request $request): void
    {
        $this->requireModule('products');
        $profiles = $this->profileModel->all([], 'id ASC');
        foreach ($profiles as &$p) {
            $p['product_count'] = ((string) $p['direction'] === 'import')
                ? $this->profileModel->getProductCount((int) $p['id'])
                : (int) $p['total_products'];
        }
        unset($p);

        $rates = $this->container->get(\App\Models\ExchangeRate::class)->all([], 'currency_code ASC');

        $this->view('xml_profiles.index', [
            'pageTitle' => 'XML Profilleri',
            'profiles'  => $profiles,
            'rates'     => $rates,
            'appUrl'    => function_exists('app_base_url') ? app_base_url() : env('APP_URL', 'http://localhost'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->requireModule('products');
        $this->view('xml_profiles.form', [
            'pageTitle'  => 'Yeni XML Profili',
            'profile'    => null,
            'settings'   => $this->profileSettings->forProfile(0),
            'brands'     => $this->container->get(Brand::class)->getActive(),
            'categories' => $this->container->get(Category::class)->getFlatTree(),
        ]);
    }

    public function store(Request $request): void
    {
        $this->requireModule('products');
        try {
            $data = $this->collectFormData($request);

            // Dosya kaynaklı içe aktarımda dosya seçimi ZORUNLU — yetim profil oluşturma
            if ($data['direction'] === 'import' && $data['source_type'] === 'file') {
                $file = $request->file('xml_file');
                if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('Dosya tipli profil için bir XML dosyası seçin.');
                }
            }

            if ($data['direction'] === 'export') {
                $data['token'] = StringHelper::randomToken(32);
                $data['source_type'] = 'file'; // çıktı dosya olarak üretilir
                $data['source_url'] = null;
            }

            $profileId = $this->profileModel->create($data);

            // Profile ÖZEL içe aktarım ayarları (kâr/barkod ön eki/kritik stok/
            // varsayılan marka-kategori) — şema değişmeden store_settings'te tutulur
            if ($data['direction'] === 'import') {
                $this->profileSettings->save($profileId, $this->ayarGirdisi($request));
            }

            // Dosya kaynağıysa yüklenen XML'i kaydet
            if ($data['direction'] === 'import' && $data['source_type'] === 'file') {
                $this->storeUploadedFile($request, $profileId);
            }

            if ($data['direction'] === 'export') {
                try {
                    $this->exportService->generate($profileId);
                } catch (\Throwable $e) {
                    // Üretim hatası profili engellemesin — URL çağrısında yeniden denenir
                    $this->logger->warning('İlk export üretimi başarısız: ' . $e->getMessage(), 'XmlProfile');
                }
            }

            $this->redirectWithSuccess('/xml-profilleri', 'XML profili oluşturuldu');
        } catch (\Throwable $e) {
            $this->redirectWithError('/xml-profilleri/yeni', 'Hata: ' . $e->getMessage());
        }
    }

    public function edit(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profile = $this->profileModel->find((int) $id);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        $this->view('xml_profiles.form', [
            'pageTitle'  => 'XML Profili Düzenle',
            'profile'    => $profile,
            'settings'   => $this->profileSettings->forProfile((int) $id),
            'brands'     => $this->container->get(Brand::class)->getActive(),
            'categories' => $this->container->get(Category::class)->getFlatTree(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        try {
            // Yön sonradan değiştirilemez (ürün bağları bozulmasın) — mevcut yön
            // form doğrulamasına da temel olur (export formunda kaynak alanı yoktur)
            $data = $this->collectFormData($request, (string) $profile['direction']);

            unset($data['direction']);
            if (($profile['direction'] ?? '') === 'export') {
                unset($data['source_type'], $data['source_url']);
            }

            // Dosya kaynaklı profilde yeni dosya yüklenmediyse mevcut dosya adı korunur
            if (($data['source_type'] ?? '') === 'file') {
                $file = $request->file('xml_file');
                if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    unset($data['source_url']);
                }
            }

            $this->profileModel->update($profileId, $data);

            if (($profile['direction'] ?? '') === 'import') {
                $this->profileSettings->save($profileId, $this->ayarGirdisi($request));
            }

            if (($profile['direction'] ?? '') === 'import' && ($data['source_type'] ?? '') === 'file') {
                $this->storeUploadedFile($request, $profileId);
            }

            $this->redirectWithSuccess('/xml-profilleri', 'Profil güncellendi');
        } catch (\Throwable $e) {
            $this->redirectWithError('/xml-profilleri/' . $profileId . '/duzenle', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * Profili ŞİMDİ çalıştır (import → senkron, export → yeniden üret)
     */
    public function run(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            if (($profile['direction'] ?? '') === 'export') {
                $count = $this->exportService->generate($profileId);
                $this->redirectWithSuccess('/xml-profilleri', "XML çıktısı yeniden üretildi: {$count} ürün");
            }

            $result = $this->importService->run($profileId, 'manual');
            if ($result['ok']) {
                $this->redirectWithSuccess('/xml-profilleri', 'Senkron tamamlandı — ' . $result['message']);
            }
            $this->redirectWithError('/xml-profilleri', $result['message']);
        } catch (\Throwable $e) {
            $this->redirectWithError('/xml-profilleri', 'Hata: ' . $e->getMessage());
        }
    }

    /**
     * Profili Sil — ürünler kalır (xml_profile_id bağları koparılır)
     */
    public function delete(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        // Ürün bağlarını kopar (ürünlere dokunma)
        $this->unbindProducts($profileId);

        // Export önbellek dosyasını temizle
        if (!empty($profile['token'])) {
            @unlink(BASE_PATH . '/storage/xmlprofiles/export_' . $profile['token'] . '.xml');
        }

        $this->profileSettings->deleteFor($profileId);
        $this->profileModel->delete($profileId);
        $this->redirectWithSuccess('/xml-profilleri', 'Profil silindi (ürünler korundu)');
    }

    /**
     * Stokları Sıfırla — bu profilin ürünlerinin stok adedini 0 yapar
     */
    public function resetStock(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        $db = $this->container->get(\App\Core\Database::class);
        $storeId = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();

        $stmt = $db->query(
            "UPDATE products SET stock_qty = 0, missing_stock = 1
             WHERE xml_profile_id = :pid AND store_id = :sid",
            ['pid' => $profileId, 'sid' => $storeId]
        );
        $count = $stmt->rowCount();

        $logId = $this->logModel->start($profileId, 'stok_sifirla', 'manual');
        $this->logModel->finish($logId, 'success', "{$count} ürünün stoğu sıfırlandı", $count, 0, $count);

        $this->redirectWithSuccess('/xml-profilleri', "{$count} ürünün stoğu sıfırlandı");
    }

    /**
     * Ürünleri Sil — bu profille içe aktarılan TÜM ürünleri siler
     * (FK'ler CASCADE/SET NULL: sipariş kalemleri korunur, product_id NULL olur)
     */
    public function deleteProducts(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        @set_time_limit(0);

        $db = $this->container->get(\App\Core\Database::class);
        $storeId = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();

        $stmt = $db->query(
            "DELETE FROM products WHERE xml_profile_id = :pid AND store_id = :sid",
            ['pid' => $profileId, 'sid' => $storeId]
        );
        $count = $stmt->rowCount();

        $this->profileModel->markRun($profileId, 'success', "{$count} ürün silindi", 0);

        $logId = $this->logModel->start($profileId, 'urunleri_sil', 'manual');
        $this->logModel->finish($logId, 'success', "{$count} ürün silindi", $count);

        $this->redirectWithSuccess('/xml-profilleri', "{$count} ürün silindi");
    }

    /**
     * Kayıtlar — profil çalışma geçmişi
     */
    public function logs(Request $request, string $id): void
    {
        $this->requireModule('products');
        $profileId = (int) $id;
        $profile = $this->profileModel->find($profileId);
        if ($profile === false) {
            $this->redirectWithError('/xml-profilleri', 'Profil bulunamadı');
        }

        $this->view('xml_profiles.logs', [
            'pageTitle' => 'XML Profili Kayıtları',
            'profile'   => $profile,
            'logs'      => $this->logModel->getForProfile($profileId, 100),
        ]);
    }

    /**
     * AJAX: XML URL analizi (form yardımı — ürün sayısı + format tespiti)
     */
    public function analyze(Request $request): void
    {
        $this->requireModule('products');
        $url = trim((string) $request->post('source_url', ''));
        @set_time_limit(120);

        try {
            $file = $request->file('xml_file');
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                    throw new \RuntimeException('XML dosyası yüklenemedi; sunucunun yükleme boyutu sınırını kontrol edin.');
                }
                if (($file['size'] ?? 0) > 50 * 1024 * 1024) throw new \RuntimeException('XML dosyası en fazla 50 MB olabilir.');
                $result = $this->importService->analyzeFile($file['tmp_name']);
            } elseif ($request->post('source_type') === 'file' && $request->integer('profile_id') > 0) {
                $result = $this->importService->analyzeProfile($request->integer('profile_id'));
            } elseif ($url !== '') {
                $result = $this->importService->analyze($url);
            } else {
                throw new \RuntimeException('XML URL girin veya analiz edilecek XML dosyasını seçin.');
            }
            if (!$result['ok']) {
                $this->error($result['message']);
            }
            $this->success($result, $result['message']);
        } catch (\Throwable $e) {
            $this->error('Analiz hatası: ' . $e->getMessage());
        }
    }

    /**
     * PUBLIC: Dışa aktarım XML çıktısı (auth gerektirmez, token ile)
     * URL: /xml-islemleri/xml-ver-x?profil={token}
     */
    public function xmlVerX(Request $request): void
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string) $request->query('profil', ''));
        if ($token === '') {
            http_response_code(404);
            echo '<?xml version="1.0"?><error>Profil belirtilmedi</error>';
            exit;
        }

        $file = $this->exportService->serveFile($token);
        if ($file === null) {
            http_response_code(404);
            echo '<?xml version="1.0"?><error>Profil bulunamadı</error>';
            exit;
        }

        // Büyük çıktıyı belleğe almadan akıt + koşullu istek desteği (Last-Modified)
        $mtime = filemtime($file);
        $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($ims !== '' && @strtotime($ims) >= $mtime) {
            http_response_code(304);
            exit;
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    // ─── Yardımcılar ──────────────────────────────────────

    /**
     * @param string|null $forcedDirection Düzenlemede mevcut yön (form yön alanı içermez)
     * @return array<string, mixed>
     */
    /**
     * Profil-özel ayar alanlarını formdan topla.
     *
     * @return array<string, mixed>
     */
    private function ayarGirdisi(Request $request): array
    {
        return [
            'kar_tipi'               => (string) $request->post('kar_tipi', 'yuzde'),
            'kar_degeri'             => $request->post('kar_degeri', '0'),
            'barkod_onek'            => (string) $request->post('barkod_onek', ''),
            'kritik_stok'            => $request->post('kritik_stok', '0'),
            'varsayilan_marka_id'    => $request->post('varsayilan_marka_id', '0'),
            'varsayilan_kategori_id' => $request->post('varsayilan_kategori_id', '0'),
        ];
    }

    private function collectFormData(Request $request, ?string $forcedDirection = null): array
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            throw new \RuntimeException('Profil adı zorunludur.');
        }

        $direction = $forcedDirection !== null
            ? ($forcedDirection === 'export' ? 'export' : 'import')
            : ($request->post('direction', 'import') === 'export' ? 'export' : 'import');
        $sourceType = $request->post('source_type', 'url') === 'file' ? 'file' : 'url';
        $sourceUrl = trim((string) $request->post('source_url', ''));

        if ($direction === 'import' && $sourceType === 'url' && !preg_match('#^https?://#i', $sourceUrl)) {
            throw new \RuntimeException('Geçerli bir XML URL adresi girin (http/https).');
        }

        $format = (string) $request->post('format', 'auto');
        if (!in_array($format, ['auto', 'ticimax', 'generic'], true)) {
            $format = 'auto';
        }

        $mappingJson = trim((string) $request->post('field_mapping_json', ''));
        if ($direction === 'import' && $format === 'generic') {
            $mapping = json_decode($mappingJson, true);
            if (!is_array($mapping) || array_is_list($mapping)) throw new \RuntimeException('Önce XML’i analiz edip alan eşleştirmesini tamamlayın.');
            \App\Services\Import\XmlProfileMapping::validate($mapping);
        }

        return [
            'name'               => mb_substr($name, 0, 190),
            'direction'          => $direction,
            'source_type'        => $sourceType,
            'source_url'         => $sourceType === 'url' ? $sourceUrl : null,
            'format'             => $format,
            'field_mapping_json' => $mappingJson !== '' ? $mappingJson : null,
            'period_hours'       => max(0, min(720, $request->integer('period_hours', 0))),
            'update_existing'    => $request->boolean('update_existing') ? 1 : 0,
            'update_prices'      => $request->boolean('update_prices') ? 1 : 0,
            'update_stock'       => $request->boolean('update_stock') ? 1 : 0,
            'create_categories'  => $request->boolean('create_categories') ? 1 : 0,
            'create_brands'      => $request->boolean('create_brands') ? 1 : 0,
            'import_images'      => $request->boolean('import_images') ? 1 : 0,
            'is_active'          => $request->boolean('is_active') ? 1 : 0,
        ];
    }

    /**
     * Dosya kaynaklı profil için yüklenen XML'i storage/xmlprofiles altına koy
     */
    private function storeUploadedFile(Request $request, int $profileId): void
    {
        $file = $request->file('xml_file');
        if ($file === null || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return; // dosya değişmeden bırakıldı
        }

        $dir = BASE_PATH . '/storage/xmlprofiles';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $target = 'profil_' . $profileId . '.xml';
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $target)) {
            // CLI/test bağlamında düz kopya dene
            if (!@copy($file['tmp_name'], $dir . '/' . $target)) {
                throw new \RuntimeException('XML dosyası kaydedilemedi.');
            }
        }

        $this->profileModel->update($profileId, ['source_url' => $target]);
    }

    /**
     * Profil ürün bağlarını kopar (profil silinirken)
     */
    private function unbindProducts(int $profileId): void
    {
        $db = $this->container->get(\App\Core\Database::class);
        $storeId = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();
        $db->query(
            "UPDATE products SET xml_profile_id = NULL WHERE xml_profile_id = :pid AND store_id = :sid",
            ['pid' => $profileId, 'sid' => $storeId]
        );
    }
}
