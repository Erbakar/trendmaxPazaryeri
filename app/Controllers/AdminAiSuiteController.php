<?php
/**
 * OzyConnect Panel — AdminAiSuiteController
 *
 * AI Suite yönetimi (settings group='ai_suite'): aktif sağlayıcı, şifreli API anahtarları,
 * modül-tipi model seçimi, model token fiyatları, işlem başı kredi maliyetleri, bağlantı testi
 * ve mağaza kredi yönetimi. Pattern A (AdminMarketingController ile aynı).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Setting;
use App\Services\AiCreditService;
use App\Services\Ai\AiClient;
use App\Services\CryptoService;

final class AdminAiSuiteController
{
    private const MASK = '••••••••';
    private const PROVIDERS = ['gemini', 'openai', 'claude'];
    private const ACTIONS = ['ai_content', 'ai_bulk', 'ai_vision', 'ai_optimize', 'ai_competitor', 'ai_advisor', 'ai_trends'];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Setting $settingModel;
    private readonly CryptoService $crypto;
    private readonly AiClient $ai;
    private readonly AiCreditService $credits;
    private readonly Database $db;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->settingModel = $container->get(Setting::class);
        $this->crypto       = $container->get(CryptoService::class);
        $this->ai           = $container->get(AiClient::class);
        $this->credits      = $container->get(AiCreditService::class);
        $this->db           = $container->get(Database::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $g = fn(string $k, string $d = '') => (string) $this->settingModel->get($k, $d);
        $hasKey = [];
        foreach (self::PROVIDERS as $p) {
            $hasKey[$p] = $g('ai_key_' . $p) !== '';
        }
        $costs = [];
        foreach (self::ACTIONS as $a) {
            $costs[$a] = $this->ai->creditCost($a);
        }
        $this->render('admin.ai_suite', [
            'pageTitle'  => 'AI Suite',
            'enabled'    => $g('ai_enabled', '0') === '1',
            'provider'   => $g('ai_provider', 'gemini'),
            'hasKey'     => $hasKey,
            'baseUrl'    => $g('ai_openai_base_url'),
            'modelDef'   => $g('ai_model_default'),
            'modelVis'   => $g('ai_model_vision'),
            'modelAdv'   => $g('ai_model_advisor'),
            'prices'     => $g('ai_model_prices'),
            'costs'      => $costs,
            'packages'   => $this->ai->creditPackages(),
            'maskValue'  => self::MASK,
        ]);
    }

    public function save(Request $request): void
    {
        $in = $request->post('s', []);
        $in = is_array($in) ? $in : [];
        $set = fn(string $k, string $v) => $this->settingModel->set($k, $v, null, 'string', 'ai_suite');

        try {
            $set('ai_enabled', !empty($in['ai_enabled']) ? '1' : '0');
            $prov = (string) ($in['ai_provider'] ?? 'gemini');
            $set('ai_provider', in_array($prov, self::PROVIDERS, true) ? $prov : 'gemini');
            $set('ai_openai_base_url', trim((string) ($in['ai_openai_base_url'] ?? '')));
            $set('ai_model_default', trim((string) ($in['ai_model_default'] ?? '')));
            $set('ai_model_vision', trim((string) ($in['ai_model_vision'] ?? '')));
            $set('ai_model_advisor', trim((string) ($in['ai_model_advisor'] ?? '')));

            // Şifreli anahtarlar: boş veya maske → mevcut korunur; yeni değer → şifrele
            foreach (self::PROVIDERS as $p) {
                $val = trim((string) ($in['ai_key_' . $p] ?? ''));
                if ($val !== '' && $val !== self::MASK) {
                    $set('ai_key_' . $p, $this->crypto->encrypt($val));
                }
            }

            // İşlem başı kredi maliyetleri → JSON
            $costs = [];
            foreach (self::ACTIONS as $a) {
                $costs[$a] = max(0, (int) ($in['cost'][$a] ?? 0));
            }
            $set('ai_credit_costs', json_encode($costs, JSON_UNESCAPED_UNICODE));

            // Model fiyatları (gelişmiş JSON) — geçerliyse kaydet
            $pricesRaw = trim((string) ($in['ai_model_prices'] ?? ''));
            if ($pricesRaw === '' || json_decode($pricesRaw, true) !== null) {
                $set('ai_model_prices', $pricesRaw);
            }

            // Kredi paketi satış fiyatları (TL) → JSON {key:price}
            $pkgPrices = [];
            foreach (array_keys($this->ai->creditPackages()) as $key) {
                $p = (float) str_replace(',', '.', (string) ($in['credit_price'][$key] ?? 0));
                if ($p > 0) { $pkgPrices[$key] = round($p, 2); }
            }
            $set('ai_credit_package_prices', json_encode($pkgPrices, JSON_UNESCAPED_UNICODE));

            Setting::clearCache();
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/ai-suite');
            return;
        }

        $this->session->flash('success', 'AI Suite ayarları kaydedildi.');
        $this->response->redirectTo('/admin/ai-suite');
    }

    /** Aktif sağlayıcıya küçük bir istek atıp bağlantıyı doğrular. */
    public function test(Request $request): void
    {
        try {
            $res = $this->ai->chat('Yalnızca "OK" yaz.', [['role' => 'user', 'content' => 'ping']], ['max_tokens' => 16]);
            $txt = trim($res->text) !== '' ? trim($res->text) : '(boş)';
            $this->session->flash('success', "Bağlantı başarılı ({$res->provider}/{$res->model}). Yanıt: " . mb_substr($txt, 0, 80));
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Bağlantı testi başarısız: ' . $e->getMessage());
        }
        $this->response->redirectTo('/admin/ai-suite');
    }

    /** Mağaza kredi bakiyeleri + manuel kredi tanımlama. */
    public function credits(Request $request): void
    {
        $rows = $this->db->fetchAll(
            "SELECT sc.store_id, s.name AS store_name, sc.balance, sc.total_purchased, sc.total_used, sc.updated_at
             FROM store_credits sc JOIN stores s ON s.id = sc.store_id
             ORDER BY sc.updated_at DESC LIMIT 200"
        );
        $stores = $this->db->fetchAll("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name ASC");
        $this->render('admin.ai_suite_credits', [
            'pageTitle' => 'AI Kredi Yönetimi',
            'rows'      => $rows,
            'stores'    => $stores,
        ]);
    }

    public function creditsAdd(Request $request): void
    {
        $storeId = (int) $request->post('store_id', 0);
        $amount  = (int) $request->post('amount', 0);
        $note    = trim((string) $request->post('note', ''));
        if ($storeId > 0 && $amount !== 0) {
            $this->credits->add(
                abs($amount),
                $amount > 0 ? 'grant' : 'adjust',
                ['type' => 'admin'],
                $storeId,
                $this->adminSession->adminId(),
                $note !== '' ? $note : 'Admin kredi tanımı'
            );
            $this->session->flash('success', "Mağaza #{$storeId} için {$amount} kredi işlendi.");
        } else {
            $this->session->flash('error', 'Geçerli mağaza ve tutar girin.');
        }
        $this->response->redirectTo('/admin/ai-suite/credits');
    }
}
