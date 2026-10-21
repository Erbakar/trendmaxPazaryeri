<?php
/**
 * OzyConnect Panel — AdminPackageController (Faz 8: paket yönetimi)
 *
 * Paket CRUD + kopyala + soft-delete + pazaryeri/modül/limit seçimi. AdminAuthMiddleware (Pattern A).
 * Paket = entitlement demeti tanımı; aboneliğe uygulanması PackageEntitlementSync üzerinden (burada YALNIZ tanım).
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
use App\Models\Marketplace;
use App\Models\Package;
use App\Models\ModuleCatalog;
use App\Services\EntitlementService;

final class AdminPackageController
{
    /** @deprecated Etiket kaynağı artık module_catalog (ModuleCatalog::labelMap). Geriye-uyum için bırakıldı. */
    public const MODULE_LABELS = [
        'orders' => 'Siparişler', 'returns' => 'İadeler', 'questions' => 'Sorular', 'products' => 'Ürünler',
        'categories' => 'Kategoriler', 'brands' => 'Markalar', 'customers' => 'Müşteriler', 'invoices' => 'Faturalar',
        'finance' => 'Finans', 'shipping' => 'Kargo', 'feeds' => 'XML Feed', 'import' => 'İçe Aktarma', 'sync' => 'Senkronizasyon',
    ];

    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Package $packages;
    private readonly Marketplace $marketplaces;
    private readonly ModuleCatalog $moduleCat;
    private readonly \App\Core\Database $db;
    private readonly \App\Services\SubscriptionService $subscriptionService;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->packages     = $container->get(Package::class);
        $this->marketplaces = $container->get(Marketplace::class);
        $this->moduleCat    = $container->get(ModuleCatalog::class);
        $this->db           = $container->get(\App\Core\Database::class);
        $this->subscriptionService = $container->get(\App\Services\SubscriptionService::class);
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
        $this->render('admin.packages_index', [
            'pageTitle' => 'Paketler',
            'packages'  => $this->packages->allAdmin(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->renderForm(null);
    }

    public function edit(Request $request, string $id): void
    {
        $pkg = $this->packages->adminFind((int) $id);
        if ($pkg === false) {
            $this->session->flash('error', 'Paket bulunamadı.');
            $this->response->redirectTo('/admin/packages');
            return;
        }
        $this->renderForm($pkg);
    }

    private function renderForm(?array $pkg): void
    {
        $id = $pkg !== null ? (int) $pkg['id'] : 0;
        $this->render('admin.package_form', [
            'pageTitle'     => $pkg !== null ? 'Paket Düzenle' : 'Yeni Paket',
            'pkg'           => $pkg,
            'limits'        => $id > 0 ? ($this->packages->limits($id) ?: []) : [],
            'allMarketplaces' => $this->marketplaces->all([], 'display_name ASC'),
            'selectedMp'    => $id > 0 ? $this->packages->marketplaceIds($id) : [],
            'moduleLabels'  => $this->moduleCat->labelMap(),
            'selectedMod'   => $id > 0 ? $this->packages->moduleKeys($id) : [],
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validateInput($request, null);
        if ($data === null) {
            $this->response->redirectTo('/admin/packages/create');
            return;
        }
        $id = $this->packages->createPackage($data['fields']);
        $this->packages->setMarketplaces($id, $data['marketplaces']);
        $this->packages->setModules($id, $data['modules']);
        $this->packages->upsertLimits($id, $data['limits']);
        $this->session->flash('success', 'Paket oluşturuldu.');
        $this->response->redirectTo('/admin/packages');
    }

    public function update(Request $request, string $id): void
    {
        $pkgId = (int) $id;
        $pkg = $this->packages->adminFind($pkgId);
        if ($pkg === false) {
            $this->session->flash('error', 'Paket bulunamadı.');
            $this->response->redirectTo('/admin/packages');
            return;
        }
        $data = $this->validateInput($request, $pkgId);
        if ($data === null) {
            $this->response->redirectTo('/admin/packages/' . $pkgId . '/edit');
            return;
        }
        if ((int) $pkg['is_internal'] === 1) {
            // Internal paket tanımı (id=1) korunur — yalnız temel alanlar; entitlement/junction boş kalmalı (store#1 kalkanı).
            $this->packages->updatePackage($pkgId, $data['fields']);
            $this->session->flash('success', 'Paket güncellendi (internal — içerik korunur).');
            $this->response->redirectTo('/admin/packages');
            return;
        }
        $this->packages->updatePackage($pkgId, $data['fields']);
        $this->packages->setMarketplaces($pkgId, $data['marketplaces']);
        $this->packages->setModules($pkgId, $data['modules']);
        $this->packages->upsertLimits($pkgId, $data['limits']);

        // Paket içeriği değişti → bu pakete abone TÜM mağazaların pazaryeri/modül
        // yetkilerini (store_marketplaces) pakete göre yeniden eşitle. Aksi halde
        // pakete eklenen pazaryeri mağazada görünmez (eski davranış: ancak
        // pazaryerini elle kapatıp açınca düzeliyordu).
        $resynced = $this->resyncSubscribers($pkgId);

        $this->session->flash(
            'success',
            'Paket güncellendi.' . ($resynced > 0 ? " {$resynced} abone mağazanın yetkileri pakete göre eşitlendi." : '')
        );
        $this->response->redirectTo('/admin/packages');
    }

    /**
     * Pakete abone (trial/active) mağazaların entitlement'ını pakete göre yeniden eşitler.
     */
    private function resyncSubscribers(int $pkgId): int
    {
        $rows = $this->db->fetchAll(
            "SELECT store_id FROM subscriptions WHERE package_id = :p AND status IN ('trial','active')",
            ['p' => $pkgId]
        );

        foreach ($rows as $row) {
            try {
                $this->subscriptionService->resyncEntitlements((int) $row['store_id']);
            } catch (\Throwable $e) {
                // Tek mağazanın eşitleme hatası diğerlerini durdurmasın
            }
        }

        return count($rows);
    }

    public function copy(Request $request, string $id): void
    {
        $newId = $this->packages->duplicate((int) $id);
        $this->session->flash($newId !== null ? 'success' : 'error', $newId !== null ? 'Paket kopyalandı (taslak).' : 'Paket bulunamadı.');
        $this->response->redirectTo('/admin/packages');
    }

    public function toggle(Request $request, string $id): void
    {
        $pkg = $this->packages->adminFind((int) $id);
        if ($pkg !== false) {
            $this->packages->update((int) $id, ['is_active' => (int) $pkg['is_active'] === 1 ? 0 : 1]);
            $this->session->flash('success', 'Paket durumu değişti.');
        }
        $this->response->redirectTo('/admin/packages');
    }

    public function delete(Request $request, string $id): void
    {
        $pkg = $this->packages->adminFind((int) $id);
        if ($pkg !== false && (int) $pkg['is_internal'] === 1) {
            $this->session->flash('error', 'Internal paket silinemez.');
        } else {
            $this->packages->softDelete((int) $id);
            $this->session->flash('success', 'Paket silindi.');
        }
        $this->response->redirectTo('/admin/packages');
    }

    /** @return array{fields:array,marketplaces:int[],modules:string[],limits:array}|null */
    private function validateInput(Request $request, ?int $ignoreId): ?array
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->session->flash('error', 'Paket adı zorunludur.');
            return null;
        }
        $slug = trim((string) $request->post('slug', ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        }
        $slug = $this->slugify($slug);
        if ($slug === '') { $slug = 'paket-' . bin2hex(random_bytes(3)); }
        if ($this->packages->slugExists($slug, $ignoreId)) {
            $this->session->flash('error', 'Bu slug zaten kullanımda: ' . $slug);
            return null;
        }
        $lim = fn(string $k) => ($v = trim((string) $request->post($k, ''))) === '' ? null : max(0, (int) $v);
        return [
            'fields' => [
                'name' => $name, 'slug' => $slug, 'description' => $request->post('description', ''),
                'price_monthly' => $request->post('price_monthly', 0), 'price_yearly' => $request->post('price_yearly', 0),
                'trial_days' => $request->post('trial_days', 0), 'is_active' => $request->post('is_active', '0') === '1',
                'status' => $request->post('status', 'active'), 'sort_order' => $request->post('sort_order', 0),
            ],
            'marketplaces' => array_map('intval', (array) $request->post('marketplaces', [])),
            'modules'      => array_values((array) $request->post('modules', [])),
            'limits'       => [
                'max_products' => $lim('max_products'), 'max_orders_month' => $lim('max_orders_month'),
                'max_users' => $lim('max_users'), 'max_api_tokens' => $lim('max_api_tokens'), 'api_rate_per_min' => $lim('api_rate_per_min'),
            ],
        ];
    }

    private function slugify(string $s): string
    {
        $tr = ['ç'=>'c','ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','Ç'=>'c','Ğ'=>'g','Ö'=>'o','Ş'=>'s','Ü'=>'u'];
        $s = mb_strtolower(strtr($s, $tr), 'UTF-8');
        return trim((string) preg_replace('/[^a-z0-9]+/u', '-', $s), '-');
    }
}
