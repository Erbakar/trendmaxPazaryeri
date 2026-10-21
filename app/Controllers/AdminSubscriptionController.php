<?php
/**
 * OzyConnect Panel — AdminSubscriptionController (Faz 8: abonelik yönetimi + trial dönüşüm)
 *
 * Liste + durum yönetimi (askıya al/iptal/yeniden etkinleştir/pakete re-sync) + paket geçmişi.
 * Tüm geçişler SubscriptionService üzerinden (state machine + history). AdminAuthMiddleware (Pattern A).
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
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Services\SubscriptionService;

final class AdminSubscriptionController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Subscription $subscriptions;
    private readonly SubscriptionHistory $history;
    private readonly SubscriptionService $service;
    private readonly Container $container;

    public function __construct(Container $container)
    {
        $this->response      = $container->get(Response::class);
        $this->session       = $container->get(Session::class);
        $this->adminSession  = $container->get(AdminSession::class);
        $this->subscriptions = $container->get(Subscription::class);
        $this->history       = $container->get(SubscriptionHistory::class);
        $this->service       = $container->get(SubscriptionService::class);
        $this->container     = $container;
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
        $status = (string) $request->query('status', '');
        $this->render('admin.subscriptions_index', [
            'pageTitle' => 'Abonelikler',
            'subs'      => $this->subscriptions->adminList(null), // tümü gelir; filtre client-side (DataTables)
            'counts'    => $this->subscriptions->statusCounts(),
            'metrics'   => $this->subscriptions->conversionMetrics(),
            'current'   => $status,
        ]);
    }

    /** Mağaza add-on (ek pazaryeri/modül) yönetim ekranı. */
    public function addons(Request $request, string $storeId): void
    {
        $sid = (int) $storeId;
        $db  = $this->container->get(\App\Core\Database::class);
        $store = $db->fetchOne("SELECT * FROM stores WHERE id = :id", ['id' => $sid]);
        if ($store === false) {
            $this->session->flash('error', 'Mağaza bulunamadı.');
            $this->response->redirectTo('/admin/subscriptions');
            return;
        }
        $sub   = $this->subscriptions->forStore($sid);
        $pkgId = $sub !== false ? (int) ($sub['package_id'] ?? 0) : 0;
        $pkg   = $this->container->get(\App\Models\Package::class);

        $activeMpIds = array_map('intval', array_column(
            $db->fetchAll("SELECT marketplace_id FROM store_marketplaces WHERE store_id = :s AND status = 'active'", ['s' => $sid]),
            'marketplace_id'
        ));
        $activeModKeys = array_column(
            $db->fetchAll("SELECT module_key FROM store_modules WHERE store_id = :s AND status = 'active'", ['s' => $sid]),
            'module_key'
        );
        $addonMpIds = $addonModKeys = [];
        foreach ($this->container->get(\App\Models\StoreAddon::class)->activeAddons($sid) as $a) {
            if (($a['addon_type'] ?? '') === 'marketplace' && $a['marketplace_id'] !== null) {
                $addonMpIds[(int) $a['marketplace_id']] = (float) $a['price_monthly'];
            } elseif (!empty($a['module_key'])) {
                $addonModKeys[(string) $a['module_key']] = (float) $a['price_monthly'];
            }
        }

        $this->render('admin.subscription_addons', [
            'pageTitle'     => 'Eklentiler — ' . (string) ($store['name'] ?? ('#' . $sid)),
            'store'         => $store,
            'sub'           => $sub,
            'marketplaces'  => $this->container->get(\App\Models\Marketplace::class)->allCatalog(),
            'modules'       => $this->container->get(\App\Models\ModuleCatalog::class)->allAdmin(),
            'activeMpIds'   => $activeMpIds,
            'activeModKeys' => $activeModKeys,
            'pkgMpIds'      => $pkgId > 0 ? $pkg->marketplaceIds($pkgId) : [],
            'pkgModKeys'    => $pkgId > 0 ? $pkg->moduleKeys($pkgId) : [],
            'addonMpIds'    => $addonMpIds,
            'addonModKeys'  => $addonModKeys,
        ]);
    }

    /** Add-on ekle (POST). type=marketplace|module + create_invoice. */
    public function addonAdd(Request $request, string $storeId): void
    {
        $sid  = (int) $storeId;
        $type = (string) $request->post('type', '');
        $inv  = $request->post('create_invoice') === '1';
        $svc  = $this->container->get(\App\Services\AddonService::class);
        try {
            if ($type === 'marketplace') {
                $name = $svc->addMarketplace($sid, (int) $request->post('marketplace_id', 0), $inv);
            } elseif ($type === 'module') {
                $name = $svc->addModule($sid, (string) $request->post('module_key', ''), $inv);
            } else {
                throw new \RuntimeException('Geçersiz add-on türü.');
            }
            $this->session->flash('success', '“' . $name . '” eklendi' . ($inv ? ' ve ek fatura oluşturuldu.' : '.'));
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        $this->response->redirectTo('/admin/subscriptions/' . $sid . '/addons');
    }

    /** Add-on çıkar (POST). */
    public function addonRemove(Request $request, string $storeId): void
    {
        $sid  = (int) $storeId;
        $type = (string) $request->post('type', '');
        $svc  = $this->container->get(\App\Services\AddonService::class);
        try {
            if ($type === 'marketplace') {
                $name = $svc->removeMarketplace($sid, (int) $request->post('marketplace_id', 0));
            } elseif ($type === 'module') {
                $name = $svc->removeModule($sid, (string) $request->post('module_key', ''));
            } else {
                throw new \RuntimeException('Geçersiz add-on türü.');
            }
            $this->session->flash('success', '“' . $name . '” çıkarıldı. Yenileme faturası buna göre düşecek.');
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        $this->response->redirectTo('/admin/subscriptions/' . $sid . '/addons');
    }

    public function history(Request $request, string $storeId): void
    {
        $sid = (int) $storeId;
        $sub = $this->subscriptions->forStore($sid);
        $this->render('admin.subscription_history', [
            'pageTitle' => 'Paket Geçmişi — store#' . $sid,
            'storeId'   => $sid,
            'sub'       => $sub,
            'rows'      => $this->history->forStore($sid, 200),
        ]);
    }

    public function suspend(Request $request, string $storeId): void
    {
        $this->service->suspend((int) $storeId, (int) $this->adminSession->adminId() ?: null, trim((string) $request->post('reason', '')) ?: 'admin_suspend');
        $this->done('Abonelik askıya alındı.');
    }

    public function cancel(Request $request, string $storeId): void
    {
        $this->service->cancel((int) $storeId, (int) $this->adminSession->adminId() ?: null, trim((string) $request->post('reason', '')) ?: 'admin_cancel');
        $this->done('Abonelik iptal edildi.');
    }

    public function reactivate(Request $request, string $storeId): void
    {
        $this->service->reactivate((int) $storeId, (int) $this->adminSession->adminId() ?: null);
        $this->done('Abonelik yeniden etkinleştirildi.');
    }

    public function resync(Request $request, string $storeId): void
    {
        $this->service->resyncEntitlements((int) $storeId);
        $this->done('Entitlement pakete göre yeniden eşitlendi.');
    }

    private function done(string $msg): void
    {
        $this->session->flash('success', $msg);
        $this->response->redirectTo('/admin/subscriptions');
    }
}
