<?php
/**
 * OzyConnect Panel — ImportController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Marketplace;
use App\Models\ProductImportJob;
use App\Services\Import\CsvImportService;
use App\Services\Import\ProductImportService;
use App\Services\Import\XmlFileImportService;

final class ImportController extends BaseController
{
    private readonly Marketplace $marketplaceModel;
    private readonly ProductImportJob $importJobModel;
    private readonly ProductImportService $importService;
    private readonly CsvImportService $csvImportService;
    private readonly XmlFileImportService $xmlImportService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->importJobModel   = $container->get(ProductImportJob::class);
        $this->importService    = $container->get(ProductImportService::class);
        $this->csvImportService = $container->get(CsvImportService::class);
        $this->xmlImportService = $container->get(XmlFileImportService::class);
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getActive();
        $recentJobs = $this->importJobModel->all([], 'id DESC', 20);

        $settings = $this->container->get(\App\Models\Setting::class);
        $feedToken = $settings->get('feed_token', '');
        if (empty($feedToken)) {
            $feedToken = bin2hex(random_bytes(16));
            $settings->set('feed_token', $feedToken);
        }

        $this->view('import.index', [
            'pageTitle'       => 'İçe / Dışa Aktarma',
            'marketplaces'    => $marketplaces,
            'recentJobs'      => $recentJobs,
            'xmlFields'       => XmlFileImportService::SYSTEM_FIELDS,
            'xmlUploadLimit'  => (string) ini_get('upload_max_filesize'),
            'xmlUploadBytes'  => XmlFileImportService::uploadLimitBytes(),
            'feedToken'       => $feedToken,
            'feedIpWhitelist' => $settings->get('feed_ip_whitelist', ''),
            'feedPassword'    => $settings->get('feed_password', ''),
            'feedEnabled'     => (bool) $settings->get('feed_enabled', '0'),
        ]);
    }

    public function startMarketplace(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $mp = $this->marketplaceModel->find($marketplaceId);
        if ($mp === false) {
            $this->redirectWithError('/import', 'Pazaryeri bulunamadı');
            return;
        }

        // AJAX modunda mı? İlk sayfayı direkt döndür
        if ($request->isAjax()) {
            try {
                $mode = $request->post('import_mode', 'full');
                $this->importService->loadMarketplaceCredentials($mp['slug']);
                $firstPage = $this->importService->importSinglePage($marketplaceId, $mp['slug'], 0, 100, $mode);
                $this->success($firstPage, 'İçe aktarma başladı');
            } catch (\Throwable $e) {
                $this->error('Import hatası: ' . $e->getMessage());
            }
            return;
        }

        // Normal form mode - redirect
        try {
            $mode = $request->post('import_mode', 'full');
            $result = $this->importService->importFromMarketplace(
                $marketplaceId,
                $mp['slug'],
                $mode,
                $this->session->userId()
            );

            $msg = "{$result->created} yeni, {$result->updated} güncellendi";
            if ($result->failed > 0) {
                $msg .= ", {$result->failed} hatalı";
            }

            $this->redirectWithSuccess('/import', "Import tamamlandı: {$msg}");
        } catch (\Throwable $e) {
            $this->redirectWithError('/import', 'Import hatası: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Tek sayfa import et — progress bar için
     */
    public function importPage(Request $request): void
    {
        $marketplaceId = $request->integer('marketplace_id');
        $page = $request->integer('page', 0);
        $slug = $request->post('slug', '');
        $mode = $request->post('import_mode', 'full');

        try {
            $this->importService->loadMarketplaceCredentials($slug);
            $result = $this->importService->importSinglePage($marketplaceId, $slug, $page, 100, $mode);
            $this->success($result, 'Sayfa işlendi');
        } catch (\Throwable $e) {
            $this->error('Import hatası: ' . $e->getMessage());
        }
    }

    public function uploadCsv(Request $request): void
    {
        $file = $request->file('csv_file');
        if ($file === null || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithError('/import', 'Dosya yüklenemedi');
        }

        $delimiter = $request->post('delimiter', ';');

        try {
            $result = $this->csvImportService->import($file['tmp_name'], $delimiter);
            $msg = "CSV Import: {$result->created} yeni, {$result->updated} güncellendi";
            if ($result->failed > 0) {
                $msg .= ", {$result->failed} hatalı";
            }

            $this->redirectWithSuccess('/import', $msg);
        } catch (\Throwable $e) {
            $this->redirectWithError('/import', 'CSV Import hatası: ' . $e->getMessage());
        }
    }

    public function downloadTemplate(Request $request): void
    {
        $csv = $this->csvImportService->getTemplateCsv();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="EntegratorYazilimi_import_template.csv"');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /**
     * XML içe aktarma — tek uç nokta, dört mod:
     *
     *   xml_action=analyze → kaynağı (dosya/link) al, ürün düğümünü ve alanları çöz
     *   xml_action=node    → kullanıcı ürün düğümünü elle değiştirdi, yeniden örnekle
     *   xml_action=chunk   → eşlemeye göre bir parçayı içe aktar (ilerleme çubuğu)
     *   xml_action=cancel  → geçici kaynağı sil
     *   (boş)              → JavaScript kapalıysa tek istekte tamamını aktar
     */
    public function uploadXml(Request $request): void
    {
        $action = (string) $request->post('xml_action', '');

        match ($action) {
            'analyze' => $this->xmlAnalyze($request),
            'node'    => $this->xmlChangeNode($request),
            'chunk'   => $this->xmlChunk($request),
            'cancel'  => $this->xmlCancel($request),
            default   => $this->xmlImportWholeFile($request),
        };
    }

    /**
     * Kaynağı hazırla ve yapısını çöz (AJAX)
     */
    private function xmlAnalyze(Request $request): void
    {
        try {
            $source = (string) $request->post('xml_source', 'file');

            if ($source === 'url') {
                $url = trim((string) $request->post('xml_url', ''));
                if ($url === '') {
                    $this->error('XML linki boş — adresi yapıştırıp tekrar deneyin.');
                }
                $token = $this->xmlImportService->stageUrl($url);
            } else {
                $file = $request->file('xml_file');
                if ($file === null) {
                    $this->error('XML dosyası seçilmedi. (Dosya sunucu sınırını aşıyorsa "XML Linki" seçeneğini kullanın.)');
                }
                $token = $this->xmlImportService->stageUpload($file);
            }

            $this->success($this->xmlImportService->analyze($token), 'XML çözümlendi');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Ürün düğümünü elle değiştir (AJAX)
     */
    private function xmlChangeNode(Request $request): void
    {
        try {
            $result = $this->xmlImportService->useItemPath(
                (string) $request->post('xml_token', ''),
                (string) $request->post('item_path', '')
            );
            $this->success($result, 'Ürün düğümü güncellendi');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Bir parçayı içe aktar (AJAX)
     */
    private function xmlChunk(Request $request): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $this->xmlImportService->importChunk(
                (string) $request->post('xml_token', ''),
                $this->xmlFieldMap($request),
                (string) $request->post('xml_mode', 'skip'),
                $request->integer('offset', 0),
                200,
                15.0
            );

            $this->success($result, $result['message']);
        } catch (\Throwable $e) {
            $this->error('XML Import hatası: ' . $e->getMessage());
        }
    }

    /**
     * Geçici kaynağı sil (AJAX)
     */
    private function xmlCancel(Request $request): void
    {
        $this->xmlImportService->discard((string) $request->post('xml_token', ''));
        $this->success([], 'İptal edildi');
    }

    /**
     * JavaScript devre dışıysa: tek istekte tüm dosyayı aktar
     */
    private function xmlImportWholeFile(Request $request): void
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $token = trim((string) $request->post('xml_token', ''));
            $map = $this->xmlFieldMap($request);

            if ($token === '') {
                $file = $request->file('xml_file');
                if ($file === null) {
                    $this->redirectWithError('/import', 'XML dosyası seçilmedi');
                    return;
                }
                $token = $this->xmlImportService->stageUpload($file);
                $analysis = $this->xmlImportService->analyze($token);

                // JavaScript kapalıysa eşleme gelmez — otomatik öneriyi kullan
                if (empty($map['sku']) || empty($map['name'])) {
                    $map = $analysis['suggested'];
                }
            }

            $result = $this->xmlImportService->importAll(
                $token,
                $map,
                (string) $request->post('xml_mode', 'skip')
            );

            $this->redirectWithSuccess('/import', $result['message']);
        } catch (\Throwable $e) {
            $this->redirectWithError('/import', 'XML Import hatası: ' . $e->getMessage());
        }
    }

    /**
     * field_map JSON'unu diziye çevir
     *
     * @return array<string, mixed>
     */
    private function xmlFieldMap(Request $request): array
    {
        $decoded = json_decode((string) $request->post('field_map', '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }


    /**
     * Excel (CSV) olarak dışa aktar
     */
    public function exportExcel(Request $request): void
    {
        $productModel = $this->container->get(\App\Models\Product::class);
        $products = $productModel->all(['is_archived' => 0], 'name ASC');

        $headers = ['SKU', 'Barkod', 'Ürün Adı', 'Kategori ID', 'Marka ID', 'Satış Fiyatı', 'Liste Fiyatı', 'Maliyet', 'Stok', 'KDV %', 'Ağırlık', 'Desi', 'Durum', 'Parent SKU'];
        $rows = [];
        // Parent SKU'ları ön belleğe al
        $skuMap = [];
        foreach ($products as $p) {
            $skuMap[(int)($p['id'] ?? 0)] = $p['sku'] ?? '';
        }
        foreach ($products as $p) {
            $parentSku = '';
            if (!empty($p['parent_id'])) {
                $parentSku = $skuMap[(int) $p['parent_id']] ?? '';
            }
            $rows[] = [
                $p['sku'], $p['barcode'] ?? '', $p['name'],
                $p['category_id'] ?? '', $p['brand_id'] ?? '',
                $p['base_price'] ?? 0, $p['list_price'] ?? 0, $p['cost_price'] ?? 0,
                $p['stock_qty'] ?? 0, $p['tax_rate'] ?? 20,
                $p['weight'] ?? '', $p['desi'] ?? '',
                ($p['is_active'] ?? 1) ? 'Aktif' : 'Pasif',
                $parentSku,
            ];
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="EntegratorYazilimi_products_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM
        fputcsv($out, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }

    /**
     * XML olarak dışa aktar (indir)
     */
    public function exportXml(Request $request): void
    {
        $xml = $this->generateProductXml();
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="EntegratorYazilimi_products_' . date('Y-m-d') . '.xml"');
        echo $xml;
        exit;
    }

    /**
     * Public XML feed — token + IP korumalı
     */
    public function publicXmlFeed(Request $request): void
    {
        $settings = $this->container->get(\App\Models\Setting::class);

        if (!((bool) $settings->get('feed_enabled', '0'))) {
            http_response_code(403);
            echo '<?xml version="1.0"?><error>Feed disabled</error>';
            exit;
        }

        // Token check
        $token = $request->query('token', '');
        $validToken = $settings->get('feed_token', '');
        $password = $settings->get('feed_password', '');

        if (!empty($password) && $request->query('pass', '') !== $password) {
            http_response_code(403);
            echo '<?xml version="1.0"?><error>Invalid password</error>';
            exit;
        }

        if (empty($validToken) || $token !== $validToken) {
            http_response_code(403);
            echo '<?xml version="1.0"?><error>Invalid token</error>';
            exit;
        }

        // IP whitelist
        $ipWhitelist = trim($settings->get('feed_ip_whitelist', ''));
        if (!empty($ipWhitelist)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $allowed = array_map('trim', explode("\n", $ipWhitelist));
            $allowed = array_filter($allowed);
            if (!empty($allowed) && !in_array($clientIp, $allowed)) {
                http_response_code(403);
                echo '<?xml version="1.0"?><error>IP not allowed</error>';
                exit;
            }
        }

        $xml = $this->generateProductXml();
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo $xml;
        exit;
    }

    /**
     * Feed ayarlarını kaydet
     */
    public function saveFeedSettings(Request $request): void
    {
        $settings = $this->container->get(\App\Models\Setting::class);
        $settings->set('feed_enabled', $request->post('feed_enabled', '0'));
        $settings->set('feed_password', $request->post('feed_password', ''));
        $settings->set('feed_ip_whitelist', $request->post('feed_ip_whitelist', ''));

        // Token yenile istendi mi?
        if ($request->post('regenerate_token') === '1') {
            $settings->set('feed_token', bin2hex(random_bytes(16)));
        }

        $this->redirectWithSuccess('/import', 'Feed ayarları kaydedildi');
    }

    /**
     * Ürünleri XML formatında üret
     */
    private function generateProductXml(): string
    {
        $productModel = $this->container->get(\App\Models\Product::class);
        $products = $productModel->all(['is_active' => 1, 'is_archived' => 0], 'name ASC');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<products generated="' . date('Y-m-d\TH:i:s') . '" count="' . count($products) . '">' . "\n";

        foreach ($products as $p) {
            $images = json_decode($p['images_json'] ?? '[]', true);
            $xml .= "  <product>\n";
            $xml .= "    <sku>" . htmlspecialchars($p['sku'] ?? '') . "</sku>\n";
            $xml .= "    <barcode>" . htmlspecialchars($p['barcode'] ?? '') . "</barcode>\n";
            $xml .= "    <name>" . htmlspecialchars($p['name'] ?? '') . "</name>\n";
            $xml .= "    <description><![CDATA[" . ($p['description'] ?? '') . "]]></description>\n";
            $xml .= "    <category_id>" . ($p['category_id'] ?? '') . "</category_id>\n";
            $xml .= "    <brand_id>" . ($p['brand_id'] ?? '') . "</brand_id>\n";
            $xml .= "    <price>" . number_format((float)($p['base_price'] ?? 0), 2, '.', '') . "</price>\n";
            $xml .= "    <list_price>" . number_format((float)($p['list_price'] ?? 0), 2, '.', '') . "</list_price>\n";
            $xml .= "    <stock>" . (int)($p['stock_qty'] ?? 0) . "</stock>\n";
            $xml .= "    <tax_rate>" . (int)($p['tax_rate'] ?? 20) . "</tax_rate>\n";
            $xml .= "    <weight>" . ($p['weight'] ?? '') . "</weight>\n";
            $xml .= "    <desi>" . ($p['desi'] ?? '') . "</desi>\n";
            $xml .= "    <parent_id>" . ($p['parent_id'] ?? '') . "</parent_id>\n";
            $xml .= "    <has_variants>" . ($p['has_variants'] ?? 0) . "</has_variants>\n";
            $xml .= "    <main_image>" . htmlspecialchars($p['main_image'] ?? '') . "</main_image>\n";
            if (is_array($images) && !empty($images)) {
                $xml .= "    <images>\n";
                foreach ($images as $img) {
                    $url = is_array($img) ? ($img['url'] ?? '') : $img;
                    if (!empty($url)) {
                        $xml .= "      <image>" . htmlspecialchars($url) . "</image>\n";
                    }
                }
                $xml .= "    </images>\n";
            }
            $xml .= "  </product>\n";
        }

        $xml .= "</products>\n";
        return $xml;
    }
}

