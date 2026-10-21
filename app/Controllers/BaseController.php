<?php
/**
 * OzyConnect Panel — BaseController
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
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Setting;
use App\Services\AuthorizationService;

abstract class BaseController
{
    protected readonly Response $response;
    protected readonly Session $session;
    protected readonly Logger $logger;
    protected readonly Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->response  = $container->get(Response::class);
        $this->session   = $container->get(Session::class);
        $this->logger    = $container->get(Logger::class);
    }

    /**
     * View render et (layout'lu)
     *
     * @param array<string, mixed> $data
     */
    /**
     * Faz 2 — Controller guard (defense-in-depth): mağaza bu modülü almamışsa 403.
     * Route guard (authorizeRoute) zaten zorlar; bu, controller içinden ek katmandır.
     */
    protected function requireModule(string $module): void
    {
        $authorization = $this->container->get(AuthorizationService::class);
        if (!$authorization->canAccessModule($module)) {
            $this->response->forbidden('Mağazanız bu modülü kullanma yetkisine sahip değil: ' . $module);
        }
    }

    protected function view(string $viewPath, array $data = [], ?string $layout = 'main'): void
    {
        /** @var AuthorizationService $authorization */
        $authorization = $this->container->get(AuthorizationService::class);
        $authorization->refreshCurrentUserContext();

        // Her view'a ortak değişkenler ekle
        $data['_session'] = $this->session;
        $data['_csrf']    = $this->session->csrfToken();
        $data['_flash']   = [
            'success' => $this->session->getFlash('success'),
            'error'   => $this->session->getFlash('error'),
            'warning' => $this->session->getFlash('warning'),
            'info'    => $this->session->getFlash('info'),
        ];
        $data['_user'] = [
            'id'          => $this->session->userId(),
            'name'        => $this->session->get('user_name'),
            'email'       => $this->session->get('user_email'),
            'role'        => $this->session->userRole(),
            'role_id'     => $this->session->userRoleId(),
            'role_name'   => $this->session->userRoleName(),
            'permissions' => $authorization->currentPermissions(),
        ];
        $data['_branding'] = $this->resolveBranding();
        $data['_can'] = static fn(string $permission): bool => $authorization->hasPermission($permission);
        $data['_canAny'] = static fn(array $permissions): bool => $authorization->hasAnyPermission($permissions);
        // Faz 2 — Store Entitlement (menü görünürlüğü): modül/pazaryeri satın alınmamışsa gizle.
        $data['_canModule']      = static fn(string $module): bool => $authorization->canAccessModule($module);
        $data['_canMarketplace'] = static fn(int $marketplaceId): bool => $authorization->canAccessMarketplace($marketplaceId);

        // Nav rozeti — okunmamış yayınlanmış güncelleme sayısı (kişi-başı, fail-safe).
        $data['_updateUnread'] = 0;
        try {
            $uid = (int) $this->session->userId();
            if ($uid > 0) {
                $appUpdate  = $this->container->get(\App\Models\AppUpdate::class);
                $updateRead = $this->container->get(\App\Models\UpdateRead::class);
                $data['_updateUnread'] = $appUpdate->unreadCount($updateRead->lastSeen($uid));
            }
        } catch (\Throwable) {
            $data['_updateUnread'] = 0;
        }

        // Nav rozeti — destek: admin'in yanıtladığı (merchant'ın görmesi gereken) ticket sayısı.
        $data['_supportUnread'] = 0;
        try {
            $uid = (int) $this->session->userId();
            if ($uid > 0) {
                $counts = $this->container->get(\App\Models\SupportTicket::class)->statusCountsForStore();
                $data['_supportUnread'] = (int) ($counts['answered'] ?? 0);
            }
        } catch (\Throwable) {
            $data['_supportUnread'] = 0;
        }

        // Faz 8 — in-app Bildirim Merkezi okunmamış sayısı (mağaza-bazlı, fail-safe).
        $data['_notifUnread'] = 0;
        try {
            if ((int) $this->session->userId() > 0) {
                $sid = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();
                $data['_notifUnread'] = $this->container->get(\App\Models\Notification::class)->unreadCount($sid);
            }
        } catch (\Throwable) {
            $data['_notifUnread'] = 0;
        }

        // Nav rozeti — belgeler: bekleyen+reddedilen sayısı (merchant'ın aksiyon alması gereken durumlar).
        $data['_documentsPending'] = 0;
        try {
            if ((int) $this->session->userId() > 0) {
                $data['_documentsPending'] = $this->container->get(\App\Models\StoreDocument::class)->currentStorePendingCount();
            }
        } catch (\Throwable) {
            $data['_documentsPending'] = 0;
        }

        $this->response->view($viewPath, $data, $layout);
    }

    /**
     * @return array{logo_url: string, custom_logo_url: string, site_name: string}
     */
    private function resolveBranding(): array
    {
        $logoCandidates = [
            'assets/img/branding/entegrator/entegrator-logo.svg',
            'assets/img/branding/logom.png',
            'assets/img/branding/ozyconnect/ozyconnect-logo-login-blue.png',
            'assets/img/branding/ozyconnect/ozyconnect-logo-login-blue.svg',
            'assets/img/branding/ozyconnect-logo-login-blue.svg',
        ];

        $generatedLogoPath = $logoCandidates[0];
        foreach ($logoCandidates as $candidate) {
            if (is_file(BASE_PATH . '/public/' . $candidate)) {
                $generatedLogoPath = $candidate;
                break;
            }
        }

        $generatedLogo = function_exists('public_url')
            ? public_url($generatedLogoPath)
            : '/public/' . $generatedLogoPath;
        $legacyLogo = function_exists('public_url')
            ? public_url('assets/img/oziyweb-logo.svg')
            : '/public/assets/img/oziyweb-logo.svg';
        $defaultLogo = is_file(BASE_PATH . '/public/' . $generatedLogoPath)
            ? $generatedLogo
            : $legacyLogo;

        $branding = [
            'logo_url' => $defaultLogo,
            'custom_logo_url' => '',
            'store_logo_url' => '',
            'site_name' => 'Entegratör Yazılımı',
        ];

        try {
            /** @var Setting $settingModel */
            $settingModel = $this->container->get(Setting::class);
            $siteName = trim((string) $settingModel->get('site_name', 'Entegratör Yazılımı'));
            $customLogo = trim((string) $settingModel->get('panel_logo_path', ''));
            if ($customLogo !== '' && function_exists('app_rewrite_legacy_path')) {
                $customLogo = app_rewrite_legacy_path($customLogo);
            }

            if ($siteName !== '') {
                $branding['site_name'] = $siteName;
            }

            $branding['custom_logo_url'] = $customLogo;
            $branding['logo_url'] = $customLogo !== '' ? $customLogo : $defaultLogo;
        } catch (\Throwable) {
            // Varsayilan branding ile devam et.
        }

        // Mağaza markası (giriş yapmış merchant): logo alanı = mağaza logosu (varsa), aksi halde
        // platform logosu; AYRICA store_name = avatar/üst sağda gösterilecek MAĞAZA ADI (kullanıcı adı değil).
        // site_name (logo metni) panel markası kalır. Login/pre-auth'ta mağaza yok → catch.
        $branding['store_name'] = '';
        try {
            $sid = $this->container->get(\App\Core\TenantContext::class)->currentStoreId();
            $svals = $this->container->get(\App\Models\StoreSetting::class)->allForStore();
            $storeName = trim((string) ($svals['store_name'] ?? ''));
            if ($storeName === '') {
                $storeRow = $this->container->get(\App\Models\Store::class)->find($sid);
                $storeName = trim((string) ($storeRow['name'] ?? ''));
            }
            $branding['store_name'] = $storeName;
            $storeLogo = trim((string) ($svals['store_logo'] ?? ''));
            if ($storeLogo !== '') {
                // store_logo_url AYRI tutulur: şablon "bu mağazanın kendi logosu var mı"
                // sorusunu buradan yanıtlar (platform logosuyla karışmasın).
                $branding['store_logo_url']  = $storeLogo;
                $branding['custom_logo_url'] = $storeLogo;
                $branding['logo_url'] = $storeLogo;
            }
        } catch (\Throwable) {
            // Aktif mağaza yok (login) → panel markası korunur.
        }

        return $branding;
    }

    /**
     * JSON yanıt
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): never
    {
        $this->response->json($data, $status);
    }

    /**
     * Başarılı JSON
     *
     * @param array<string, mixed> $data
     */
    protected function success(array $data = [], string $message = 'Başarılı'): never
    {
        $this->response->success($data, $message);
    }

    /**
     * Hata JSON
     */
    protected function error(string $message = 'Hata', int $status = 400): never
    {
        $this->response->error($message, $status);
    }

    /**
     * Yönlendir
     */
    protected function redirect(string $path): never
    {
        $this->response->redirectTo($path);
    }

    /**
     * Flash mesaj ile yönlendir
     */
    protected function redirectWithSuccess(string $path, string $message): never
    {
        $this->session->flash('success', $message);
        $this->redirect($path);
    }

    /**
     * Flash hata ile yönlendir
     */
    protected function redirectWithError(string $path, string $message): never
    {
        $this->session->flash('error', $message);
        $this->redirect($path);
    }
}
