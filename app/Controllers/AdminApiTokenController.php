<?php
/**
 * OzyConnect Panel — AdminApiTokenController (Platform admin: tüm mağazaların API anahtarları)
 *
 * API anahtarları artık ADMIN kontrolünde: admin tüm mağazalar için token oluşturur,
 * aktif/pasif yapar, yeniler, siler. Merchant tarafı salt-görüntülemedir. AdminAuthMiddleware
 * arkasındadır (merchant erişemez). Cross-store ham model metotları kullanılır (admin* önekli).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\ApiToken;
use App\Models\Store;

final class AdminApiTokenController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly ApiToken $tokenModel;
    private readonly Store $storeModel;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->tokenModel   = $container->get(ApiToken::class);
        $this->storeModel   = $container->get(Store::class);
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
        $storeId = (int) $request->query('store', 0);
        $q       = trim((string) $request->query('q', ''));

        $tokens = $this->tokenModel->adminAll($storeId > 0 ? $storeId : null, $q);
        foreach ($tokens as &$t) {
            $t['stats'] = $this->tokenModel->adminUsageStats((int) $t['id']);
            $t['permissions_list'] = json_decode($t['permissions'] ?? '[]', true) ?: [];
        }
        unset($t);

        $this->render('admin.api_tokens_index', [
            'pageTitle'   => 'API Anahtarları (tüm mağazalar)',
            'tokens'      => $tokens,
            'stores'      => $this->storeModel->allStores(),
            'currentStore' => $storeId,
            'q'           => $q,
            'showSecret'  => $this->session->getFlash('api_secret'),
            'showKey'     => $this->session->getFlash('api_key'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('admin.api_token_form', [
            'pageTitle'   => 'Yeni API Anahtarı',
            'stores'      => $this->storeModel->allStores(),
            'permissions' => ApiToken::availablePermissions(),
        ]);
    }

    public function store(Request $request): void
    {
        $storeId = (int) $request->post('store_id', 0);
        $validStore = false;
        foreach ($this->storeModel->allStores() as $s) {
            if ((int) $s['id'] === $storeId) { $validStore = true; break; }
        }
        if (!$validStore) {
            $this->session->flash('error', 'Geçerli bir mağaza seçin');
            $this->response->redirectTo('/admin/api-tokens/create');
            return;
        }

        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->session->flash('error', 'Token adı zorunlu');
            $this->response->redirectTo('/admin/api-tokens/create');
            return;
        }

        $permissions = $request->post('permissions', []);
        if (!is_array($permissions)) { $permissions = []; }

        $rateLimit = max(1, min(1000, (int) $request->integer('rate_limit', 60)));

        $ipWhitelist = [];
        $ipInput = trim((string) $request->post('ip_whitelist', ''));
        if ($ipInput !== '') {
            $ipWhitelist = array_values(array_filter(array_map('trim', explode("\n", $ipInput))));
        }

        $expiresAt = $request->post('expires_at');
        $expiresAt = ($expiresAt !== null && $expiresAt !== '') ? date('Y-m-d H:i:s', strtotime((string) $expiresAt)) : null;

        $result = $this->tokenModel->adminCreateForStore($storeId, [
            'name'         => $name,
            'permissions'  => $permissions,
            'rate_limit'   => $rateLimit,
            'ip_whitelist' => $ipWhitelist ?: null,
            'expires_at'   => $expiresAt,
        ]);

        $this->session->flash('api_secret', $result['api_secret']);
        $this->session->flash('api_key', $result['api_key']);
        $this->session->flash('success', 'API anahtarı oluşturuldu. Secret\'ı kaydedin — bir daha gösterilmeyecek!');
        $this->response->redirectTo('/admin/api-tokens?store=' . $storeId);
    }

    public function toggle(Request $request, string $id): void
    {
        $token = $this->tokenModel->adminFind((int) $id);
        if ($token === false) {
            $this->session->flash('error', 'Token bulunamadı');
            $this->response->redirectTo('/admin/api-tokens');
            return;
        }
        $new = $token['is_active'] ? 0 : 1;
        $this->tokenModel->adminSetActive((int) $id, $new);
        $this->session->flash('success', $new ? 'Token aktifleştirildi' : 'Token devre dışı bırakıldı');
        $this->response->redirectTo('/admin/api-tokens?store=' . (int) $token['store_id']);
    }

    public function regenerate(Request $request, string $id): void
    {
        $token = $this->tokenModel->adminFind((int) $id);
        if ($token === false) {
            $this->session->flash('error', 'Token bulunamadı');
            $this->response->redirectTo('/admin/api-tokens');
            return;
        }
        $res = $this->tokenModel->adminRegenerate((int) $id);
        if ($res !== false) {
            $this->session->flash('api_secret', $res['api_secret']);
            $this->session->flash('api_key', $res['api_key']);
            $this->session->flash('success', 'Yeni secret oluşturuldu. Kaydetmeyi unutmayın!');
        }
        $this->response->redirectTo('/admin/api-tokens?store=' . (int) $token['store_id']);
    }

    public function delete(Request $request, string $id): void
    {
        $token = $this->tokenModel->adminFind((int) $id);
        if ($token === false) {
            $this->session->flash('error', 'Token bulunamadı');
            $this->response->redirectTo('/admin/api-tokens');
            return;
        }
        $this->tokenModel->adminDelete((int) $id);
        $this->session->flash('success', 'API anahtarı silindi');
        $this->response->redirectTo('/admin/api-tokens?store=' . (int) $token['store_id']);
    }
}
