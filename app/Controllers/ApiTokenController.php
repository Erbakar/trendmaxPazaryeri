<?php
/**
 * OzyConnect Panel — API Token Controller (Panel Yönetimi)
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
use App\Models\ApiLog;
use App\Models\ApiToken;

final class ApiTokenController extends BaseController
{
    private readonly ApiToken $tokenModel;
    private readonly ApiLog $logModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->tokenModel = $container->get(ApiToken::class);
        $this->logModel   = $container->get(ApiLog::class);
    }

    /**
     * GET /settings/api-tokens — Token listesi
     */
    public function index(Request $request): void
    {
        $tokens = $this->tokenModel->allWithCreator();

        // Her token için kullanım istatistikleri
        foreach ($tokens as &$token) {
            $token['stats'] = $this->tokenModel->getUsageStats((int) $token['id']);
            $token['permissions_list'] = json_decode($token['permissions'] ?? '[]', true) ?: [];
        }
        unset($token);

        $this->view('settings.api_tokens', [
            'pageTitle' => 'API Anahtarları',
            'tokens'    => $tokens,
        ]);
    }

    /**
     * GET /settings/api-tokens/create — Token oluşturma formu
     */
    public function create(Request $request): void
    {
        $this->view('settings.api_token_form', [
            'pageTitle'   => 'Yeni API Anahtarı',
            'permissions' => ApiToken::availablePermissions(),
        ]);
    }

    /**
     * POST /settings/api-tokens — Token oluştur
     */
    public function store(Request $request): void
    {
        $name = trim($request->post('name', ''));

        if ($name === '') {
            $this->redirectWithError('/settings/api-tokens/create', 'Token adı zorunlu');
        }

        $permissions = $request->post('permissions', []);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        $rateLimit = max(1, min(1000, $request->integer('rate_limit', 60)));

        $ipWhitelist = [];
        $ipInput = trim($request->post('ip_whitelist', ''));
        if ($ipInput !== '') {
            $ipWhitelist = array_filter(array_map('trim', explode("\n", $ipInput)));
        }

        $expiresAt = $request->post('expires_at');
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
        } else {
            $expiresAt = null;
        }

        $result = $this->tokenModel->generateToken([
            'name'         => $name,
            'permissions'  => $permissions,
            'rate_limit'   => $rateLimit,
            'ip_whitelist' => $ipWhitelist ?: null,
            'expires_at'   => $expiresAt,
            'created_by'   => $this->session->userId(),
        ]);

        // Secret'ı session'a kaydet — sadece bir kez gösterilecek
        $this->session->flash('api_secret', $result['api_secret']);
        $this->session->flash('api_key', $result['api_key']);
        $this->session->flash('success', 'API anahtarı oluşturuldu. Secret\'ı kaydedin, bir daha gösterilmeyecek!');

        $this->redirect('/settings/api-tokens/' . $result['id']);
    }

    /**
     * GET /settings/api-tokens/{id} — Token detay
     */
    public function show(Request $request, string $id): void
    {
        $token = $this->tokenModel->find((int) $id);
        if ($token === false) {
            $this->redirectWithError('/settings/api-tokens', 'Token bulunamadı');
        }

        $token['permissions_list'] = json_decode($token['permissions'] ?? '[]', true) ?: [];
        $token['ip_whitelist_list'] = json_decode($token['ip_whitelist'] ?? 'null', true) ?: [];
        $stats = $this->tokenModel->getUsageStats((int) $id);
        $recentLogs = $this->logModel->getByToken((int) $id, 20);

        $this->view('settings.api_token_detail', [
            'pageTitle'      => 'API Anahtarı: ' . $token['name'],
            'token'          => $token,
            'stats'          => $stats,
            'recentLogs'     => $recentLogs,
            'allPermissions' => ApiToken::availablePermissions(),
            'showSecret'     => $this->session->getFlash('api_secret'),
            'showKey'        => $this->session->getFlash('api_key'),
        ]);
    }

    /**
     * POST /settings/api-tokens/{id}/toggle — Aktif/Pasif
     */
    public function toggle(Request $request, string $id): void
    {
        $token = $this->tokenModel->find((int) $id);
        if ($token === false) {
            $this->error('Token bulunamadı', 404);
        }

        $newStatus = $token['is_active'] ? 0 : 1;
        $this->tokenModel->update((int) $id, ['is_active' => $newStatus]);

        if ($request->isAjax()) {
            $this->success([
                'is_active' => (bool) $newStatus,
            ], $newStatus ? 'Token aktifleştirildi' : 'Token devre dışı bırakıldı');
        }

        $this->redirectWithSuccess('/settings/api-tokens', $newStatus ? 'Token aktif' : 'Token pasif');
    }

    /**
     * POST /settings/api-tokens/{id}/delete — Token sil
     */
    public function delete(Request $request, string $id): void
    {
        $token = $this->tokenModel->find((int) $id);
        if ($token === false) {
            $this->error('Token bulunamadı', 404);
        }

        $this->tokenModel->delete((int) $id);

        if ($request->isAjax()) {
            $this->success([], 'API anahtarı silindi');
        }

        $this->redirectWithSuccess('/settings/api-tokens', 'API anahtarı silindi');
    }

    /**
     * POST /settings/api-tokens/{id}/regenerate — Secret yenile
     */
    public function regenerate(Request $request, string $id): void
    {
        $token = $this->tokenModel->find((int) $id);
        if ($token === false) {
            $this->error('Token bulunamadı', 404);
        }

        $newSecret = bin2hex(random_bytes(32));
        $this->tokenModel->update((int) $id, [
            'api_secret_hash' => password_hash($newSecret, PASSWORD_BCRYPT),
        ]);

        $this->session->flash('api_secret', $newSecret);
        $this->session->flash('api_key', $token['api_key']);
        $this->session->flash('success', 'Yeni secret oluşturuldu. Kaydetmeyi unutmayın!');

        $this->redirect('/settings/api-tokens/' . $id);
    }
}
