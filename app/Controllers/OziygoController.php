<?php
/**
 * OzyConnect Panel — OziyGO (Kendi E-Ticaret Siten) Controller
 *
 * API bağlantı ayarları sayfası. Veri görüntüleme artık marketplace
 * sayfaları üzerinden yapılıyor (Trendyol/HB ile aynı şekilde).
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Setting;
use App\Services\Marketplace\OziygoService;

final class OziygoController extends BaseController
{
    private readonly Setting $settingModel;
    private readonly OziygoService $oziygoService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->settingModel   = $container->get(Setting::class);
        $this->oziygoService  = $container->get(OziygoService::class);
    }

    /**
     * Ana sayfa — bağlantı ayarları
     */
    public function index(Request $request): void
    {
        $apiUrl = (string) $this->settingModel->get('oziygo_api_url', '');
        $apiKey = (string) $this->settingModel->get('oziygo_api_key', '');
        $isConfigured = ($apiUrl !== '' && $apiKey !== '');

        $this->view('oziygo.index', [
            'pageTitle'    => 'Kendi Siten',
            'apiUrl'       => $apiUrl,
            'apiKey'       => $apiKey,
            'isConfigured' => $isConfigured,
        ]);
    }

    /**
     * Ayarları kaydet
     */
    public function save(Request $request): void
    {
        $apiUrl = trim((string) $request->post('api_url', ''));
        $apiKey = trim((string) $request->post('api_key', ''));

        if ($apiUrl === '' || $apiKey === '') {
            $this->redirectWithError('/oziygo', 'API URL ve API Anahtarı zorunludur.');
        }

        $userId = $this->session->userId();
        $this->settingModel->set('oziygo_api_url', $apiUrl, $userId, 'string', 'oziygo', 'OziyGO API URL');
        $this->settingModel->set('oziygo_api_key', $apiKey, $userId, 'string', 'oziygo', 'OziyGO API Key');

        $this->redirectWithSuccess('/oziygo', 'OziyGO bağlantı bilgileri kaydedildi.');
    }

    /**
     * Bağlantı testi (AJAX)
     */
    public function testConnection(Request $request): void
    {
        $result = $this->oziygoService->testConnection();
        $this->json($result);
    }
}
