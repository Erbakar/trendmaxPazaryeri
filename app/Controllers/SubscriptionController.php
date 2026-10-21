<?php
/**
 * OzyConnect Panel — SubscriptionController (Faz 8/9: MERCHANT abonelik & fatura + yükseltme + modül add-on + PayTR)
 *
 * AuthMiddleware + billing.view. Mağaza KENDİ aboneliğini TenantContext store_id ile yönetir.
 * Ödeme: Havale/EFT (dekont→admin onay) VEYA PayTR kart (callback→otomatik onay). Faturalar subscription_invoices
 * (SaaS; GİB DEĞİL). Yükseltme/modül-satın-alma açık fatura(kind=upgrade/addon) oluşturur; onayda uygulanır.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Models\ModuleCatalog;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Payment;
use App\Models\StoreAddon;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\Ai\AiClient;
use App\Services\EntitlementResolver;
use App\Services\PayTrService;
use App\Services\UsageService;

final class SubscriptionController extends BaseController
{
    private readonly TenantContext $tenant;
    private readonly Subscription $subscriptions;
    private readonly SubscriptionInvoice $invoices;
    private readonly Payment $payments;
    private readonly Package $packages;
    private readonly Marketplace $marketplaces;
    private readonly Notification $notifications;
    private readonly UsageService $usage;
    private readonly ModuleCatalog $catalog;
    private readonly StoreAddon $addons;
    private readonly EntitlementResolver $resolver;
    private readonly PayTrService $paytr;
    private readonly AiClient $aiClient;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->tenant        = $container->get(TenantContext::class);
        $this->subscriptions = $container->get(Subscription::class);
        $this->invoices      = $container->get(SubscriptionInvoice::class);
        $this->payments      = $container->get(Payment::class);
        $this->packages      = $container->get(Package::class);
        $this->marketplaces  = $container->get(Marketplace::class);
        $this->notifications = $container->get(Notification::class);
        $this->usage         = $container->get(UsageService::class);
        $this->catalog       = $container->get(ModuleCatalog::class);
        $this->addons        = $container->get(StoreAddon::class);
        $this->resolver      = $container->get(EntitlementResolver::class);
        $this->paytr         = $container->get(PayTrService::class);
        $this->aiClient      = $container->get(AiClient::class);
    }

    public function index(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);

        $mpNames = [];
        $modKeys = [];
        if ($sub !== false) {
            $pid = (int) $sub['package_id'];
            $modKeys = $this->packages->moduleKeys($pid);
            $mpIds = $this->packages->marketplaceIds($pid);
            if ($mpIds !== []) {
                foreach ($this->marketplaces->all([], 'display_name ASC') as $m) {
                    if (in_array((int) $m['id'], $mpIds, true)) {
                        $mpNames[] = (string) ($m['display_name'] ?? $m['slug']);
                    }
                }
            }
        }

        // GERÇEK yetkiler (paket + admin-eklenen ekstralar; durum + süre ile)
        $mpRows  = $this->container->get(\App\Models\StoreMarketplace::class)->listForStore($storeId);
        $modRows = $this->container->get(\App\Models\StoreModule::class)->listForStore($storeId);

        $this->view('subscription.index', [
            'pageTitle'      => 'Abonelik & Fatura',
            'mpRows'         => $mpRows,
            'modRows'        => $modRows,
            'subscription'   => $sub,
            'usage'          => $this->usage->forCurrentStore(),
            'invoices'       => $this->invoices->forStore($storeId, 50),
            'payments'       => $this->payments->forStore($storeId, 50),
            'openInvoice'    => $this->invoices->openForStore($storeId),
            'mpNames'        => $mpNames,
            'moduleKeys'     => $modKeys,
            'moduleLabels'   => $this->catalog->labelMap(),
            'mpLabels'       => $this->marketplaceLabelMap(),
            'addons'         => $this->addons->forStore($storeId),
            'paytrEnabled'   => $this->paytr->isConfigured(),
            'upgradeCount'   => count($this->upgradablePackages($sub)),
            'buyableCount'   => count($this->buyableModules($storeId)) + count($this->buyableMarketplaces($storeId)),
        ]);
    }

    // ─── Paket Yükseltme ────────────────────────────────────────────────────
    public function upgrade(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        $this->view('subscription.upgrade', [
            'pageTitle'    => 'Paketi Yükselt',
            'subscription' => $sub,
            'packages'     => $this->upgradablePackages($sub),
            'openInvoice'  => $this->invoices->openForStore($storeId),
        ]);
    }

    public function upgradeSubmit(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif aboneliğiniz yok.');
            return;
        }
        if ($this->invoices->openForStore($storeId) !== false) {
            $this->redirectWithError('/subscription', 'Açık bir ödemeniz var. Önce onu tamamlayın.');
            return;
        }
        $pkgId = (int) $request->post('package_id', 0);
        $valid = false;
        $pkg = null;
        foreach ($this->upgradablePackages($sub) as $p) {
            if ((int) $p['id'] === $pkgId) { $valid = true; $pkg = $p; break; }
        }
        if (!$valid || $pkg === null) {
            $this->redirectWithError('/subscription/upgrade', 'Geçersiz paket seçimi.');
            return;
        }
        $cycle  = (string) ($sub['billing_cycle'] ?? 'monthly');
        $amount = (float) ($cycle === 'yearly' ? ($pkg['price_yearly'] ?? 0) : ($pkg['price_monthly'] ?? 0));
        $end    = date('Y-m-d', strtotime($cycle === 'yearly' ? '+1 year' : '+1 month'));
        $this->invoices->create([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'],
            'kind' => 'upgrade', 'meta_json' => json_encode(['package_id' => $pkgId]),
            'number' => $this->invoices->nextNumber(), 'period_start' => date('Y-m-d'), 'period_end' => $end,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'sent', 'due_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->redirectWithSuccess('/subscription', '"' . (string) $pkg['name'] . '" paketine yükseltme faturası oluşturuldu. Ödeme yapınca uygulanır.');
    }

    // ─── Eklenti Satın Alma (Modül + Pazaryeri) ──────────────────────────────
    public function modules(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $this->view('subscription.modules', [
            'pageTitle'      => 'Eklentiler',
            'buyable'        => $this->buyableModules($storeId),
            'buyableMarkets' => $this->buyableMarketplaces($storeId),
            'addons'         => $this->addons->forStore($storeId),
            'moduleLabels'   => $this->catalog->labelMap(),
            'mpLabels'       => $this->marketplaceLabelMap(),
            'openInvoice'    => $this->invoices->openForStore($storeId),
        ]);
    }

    public function buyModule(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif aboneliğiniz yok.');
            return;
        }
        if ($this->invoices->openForStore($storeId) !== false) {
            $this->redirectWithError('/subscription/modules', 'Açık bir ödemeniz var. Önce onu tamamlayın.');
            return;
        }
        $key = (string) $request->post('module_key', '');
        $buyable = array_column($this->buyableModules($storeId), null, 'module_key');
        if (!isset($buyable[$key])) {
            $this->redirectWithError('/subscription/modules', 'Bu modül satın alınamaz veya zaten sizde.');
            return;
        }
        $row    = $buyable[$key];
        $amount = (float) $row['price_monthly'];
        $end    = date('Y-m-d', strtotime('+1 month'));
        $this->invoices->create([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'],
            'kind' => 'addon', 'meta_json' => json_encode(['module_key' => $key]),
            'number' => $this->invoices->nextNumber(), 'period_start' => date('Y-m-d'), 'period_end' => $end,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'sent', 'due_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->redirectWithSuccess('/subscription', '"' . (string) $row['label'] . '" modülü için fatura oluşturuldu. Ödeme yapınca aktifleşir.');
    }

    public function buyMarketplace(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif aboneliğiniz yok.');
            return;
        }
        if ($this->invoices->openForStore($storeId) !== false) {
            $this->redirectWithError('/subscription/modules', 'Açık bir ödemeniz var. Önce onu tamamlayın.');
            return;
        }
        $mpId    = (int) $request->post('marketplace_id', 0);
        $buyable = array_column($this->buyableMarketplaces($storeId), null, 'id');
        if (!isset($buyable[$mpId])) {
            $this->redirectWithError('/subscription/modules', 'Bu pazaryeri satın alınamaz veya zaten sizde.');
            return;
        }
        $row    = $buyable[$mpId];
        $amount = (float) $row['price_monthly'];
        $end    = date('Y-m-d', strtotime('+1 month'));
        $this->invoices->create([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'],
            'kind' => 'addon', 'meta_json' => json_encode(['marketplace_id' => $mpId]),
            'number' => $this->invoices->nextNumber(), 'period_start' => date('Y-m-d'), 'period_end' => $end,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'sent', 'due_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->redirectWithSuccess('/subscription', '"' . (string) $row['display_name'] . '" pazaryeri için fatura oluşturuldu. Ödeme yapınca aktifleşir.');
    }

    /** AI kredi paketi satın al → kind='credit' fatura oluştur (ödeme onayında bakiyeye eklenir). */
    public function buyCredits(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif aboneliğiniz yok.');
            return;
        }
        if ($this->invoices->openForStore($storeId) !== false) {
            $this->redirectWithError('/ai/credits', 'Açık bir ödemeniz var. Önce onu tamamlayın.');
            return;
        }
        $key = (string) $request->post('package', '');
        $packages = $this->aiClient->creditPackages();
        if (!isset($packages[$key])) {
            $this->redirectWithError('/ai/credits', 'Geçersiz kredi paketi.');
            return;
        }
        $pkg    = $packages[$key];
        $amount = (float) $pkg['price'];
        $end    = date('Y-m-d', strtotime('+1 month'));
        $this->invoices->create([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'],
            'kind' => 'credit', 'meta_json' => json_encode(['credit_amount' => (int) $pkg['credits'], 'package' => $key]),
            'number' => $this->invoices->nextNumber(), 'period_start' => date('Y-m-d'), 'period_end' => $end,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'sent', 'due_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->redirectWithSuccess('/subscription', '"' . (string) $pkg['label'] . '" paketi için fatura oluşturuldu. Ödeme yapınca krediler hesabınıza eklenir.');
    }

    // ─── Ödeme: Havale/EFT (dekont → admin onay) ─────────────────────────────
    public function pay(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif bir aboneliğiniz bulunmuyor.');
            return;
        }
        $method = $request->post('method', 'havale');
        if (!in_array($method, ['havale', 'eft', 'manual'], true)) { $method = 'havale'; }
        $bankRef = mb_substr(trim((string) $request->post('bank_ref', '')), 0, 120);
        $note    = mb_substr(trim((string) $request->post('note', '')), 0, 2000);

        try {
            $receiptPath = $this->handleReceiptUpload($request);
        } catch (\RuntimeException $e) {
            $this->redirectWithError('/subscription', $e->getMessage());
            return;
        }

        [$invId, $amount] = $this->resolvePayableInvoice($storeId, $sub);
        $this->payments->create([
            'store_id' => $storeId, 'subscription_invoice_id' => $invId, 'method' => $method,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'pending',
            'receipt_path' => $receiptPath, 'bank_ref' => $bankRef !== '' ? $bankRef : null, 'note' => $note !== '' ? $note : null,
        ]);
        $this->notifications->push($storeId, 'payment', 'Ödeme bildiriminiz alındı', 'Onay sonrası işleminiz uygulanacak.', '/subscription');
        $this->redirectWithSuccess('/subscription', 'Ödeme bildiriminiz alındı. Onaylandığında işleminiz tamamlanacak.');
    }

    // ─── Ödeme: PayTR Kart (callback → otomatik onay) ─────────────────────────
    public function payCard(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        $sub = $this->subscriptions->forStore($storeId);
        if ($sub === false) {
            $this->redirectWithError('/subscription', 'Aktif bir aboneliğiniz bulunmuyor.');
            return;
        }
        if (!$this->paytr->isConfigured()) {
            $this->redirectWithError('/subscription', 'Kart ödemesi şu an kullanılamıyor.');
            return;
        }
        [$invId, $amount] = $this->resolvePayableInvoice($storeId, $sub);
        if ($amount <= 0) {
            $this->redirectWithError('/subscription', 'Ödenecek tutar bulunamadı.');
            return;
        }
        $merchantOid = 'OZY' . str_pad((string) $invId, 6, '0', STR_PAD_LEFT) . bin2hex(random_bytes(4));
        $payId = $this->payments->create([
            'store_id' => $storeId, 'subscription_invoice_id' => $invId, 'method' => 'paytr',
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'pending', 'merchant_oid' => $merchantOid,
        ]);

        $owner = $this->currentUser();
        $base  = $this->origin();
        $res = $this->paytr->createToken([
            'merchant_oid' => $merchantOid,
            'amount' => $amount,
            'email' => (string) ($owner['email'] ?? 'musteri@entegrator.local'),
            'user_ip' => $request->ip(),
            'user_name' => (string) ($owner['name'] ?? 'Müşteri'),
            'user_phone' => (string) ($owner['phone'] ?? '-'),
            'user_address' => '-',
            'ok_url' => $base . '/subscription?paytr=ok',
            'fail_url' => $base . '/subscription?paytr=fail',
            'basket_label' => 'Entegratör Yazılımı Ödeme #' . $invId,
        ]);
        if (!($res['ok'] ?? false)) {
            $this->payments->setStatus((int) $payId, 'rejected', null);
            $this->redirectWithError('/subscription', (string) ($res['error'] ?? 'Kart ödemesi başlatılamadı.'));
            return;
        }
        $this->view('subscription.paytr', [
            'pageTitle' => 'Kart ile Ödeme',
            'token'     => (string) $res['token'],
            'amount'    => $amount,
        ]);
    }

    // ─── Yardımcılar ──────────────────────────────────────────────────────────
    /** Açık fatura varsa onu kullan; yoksa abonelik yenileme faturası oluştur. @return array{0:int,1:float} */
    private function resolvePayableInvoice(int $storeId, array $sub): array
    {
        $inv = $this->invoices->openForStore($storeId);
        if ($inv !== false) {
            return [(int) $inv['id'], (float) $inv['amount']];
        }
        $cycle  = (string) ($sub['billing_cycle'] ?? 'monthly');
        $base   = (float) ($cycle === 'yearly' ? ($sub['price_yearly'] ?? 0) : ($sub['price_monthly'] ?? 0));
        // Faz 11: yenileme tutarı = paket + aktif add-on'lar (aylık toplam; yıllık → ×12). Add-on çıkınca düşer.
        $addonMonthly = $this->addons->monthlyTotal($storeId);
        $addonAmount  = $cycle === 'yearly' ? $addonMonthly * 12 : $addonMonthly;
        $amount = $base + $addonAmount;
        $end    = date('Y-m-d', strtotime($cycle === 'yearly' ? '+1 year' : '+1 month'));
        $meta   = $addonMonthly > 0
            ? json_encode(['base' => $base, 'addons_monthly' => $addonMonthly, 'cycle' => $cycle])
            : null;
        $invId  = $this->invoices->create([
            'store_id' => $storeId, 'subscription_id' => (int) $sub['id'], 'kind' => 'subscription',
            'meta_json' => $meta,
            'number' => $this->invoices->nextNumber(), 'period_start' => date('Y-m-d'), 'period_end' => $end,
            'amount' => $amount, 'currency' => 'TRY', 'status' => 'sent', 'due_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        return [(int) $invId, $amount];
    }

    /** Mevcut paketten daha yüksek fiyatlı (yükseltilebilir) aktif paketler. @return array<int,array<string,mixed>> */
    private function upgradablePackages(array|false $sub): array
    {
        if ($sub === false) { return []; }
        $curPrice = (float) ($sub['price_monthly'] ?? 0);
        $curId    = (int) ($sub['package_id'] ?? 0);
        $out = [];
        foreach ($this->packages->activePublic() as $p) {
            if ((int) $p['id'] !== $curId && (float) ($p['price_monthly'] ?? 0) > $curPrice) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Mağazanın sahip OLMADIĞI satılabilir modüller (module_catalog − etkin modüller). @return array<int,array<string,mixed>> */
    private function buyableModules(int $storeId): array
    {
        $owned = $this->resolver->forStore($storeId)['moduleKeys'];
        $out = [];
        foreach ($this->catalog->sellable() as $m) {
            if (!in_array((string) $m['module_key'], $owned, true)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** Mağazanın sahip OLMADIĞI satılabilir pazaryerleri (fiyatı > 0 + aktif entitlement'te olmayan). @return array<int,array<string,mixed>> */
    private function buyableMarketplaces(int $storeId): array
    {
        $ownedIds = array_map(static fn($m) => (int) $m['id'], $this->marketplaces->getActiveForStore($storeId));
        $out = [];
        foreach ($this->marketplaces->sellable() as $m) {
            if ((float) ($m['price_monthly'] ?? 0) > 0 && !in_array((int) $m['id'], $ownedIds, true)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** Pazaryeri id → görünen ad eşlemesi (add-on etiketleri için). @return array<int,string> */
    private function marketplaceLabelMap(): array
    {
        $map = [];
        foreach ($this->marketplaces->all([], 'display_name ASC') as $m) {
            $map[(int) $m['id']] = (string) ($m['display_name'] ?? $m['slug'] ?? ('#' . $m['id']));
        }
        return $map;
    }

    private function currentUser(): array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) { return []; }
        // NOT: users tablosunda 'phone' kolonu yok — SELECT'e eklenirse 42S22 ile
        // kart ödeme akışı patlar. PayTR'a telefon 'user_phone' ?? '-' ile gider.
        $u = $this->container->get(\App\Core\Database::class)->fetchOne("SELECT name, email FROM users WHERE id = :id LIMIT 1", ['id' => $uid]);
        return $u === false ? [] : $u;
    }

    private function origin(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;
        if (function_exists('app_base_path')) { $base .= rtrim((string) app_base_path(), '/'); }
        return rtrim($base, '/');
    }

    private function handleReceiptUpload(Request $request): ?string
    {
        $file = $request->file('receipt');
        if ($file === null || !is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Dekont yüklenemedi.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Dekont doğrulanamadı.');
        }
        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Dekont en fazla 8 MB olabilir.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null) {
            throw new \RuntimeException('Dekont PDF, PNG, JPG veya WEBP olmalı.');
        }
        $dir = BASE_PATH . '/storage/receipts/' . $this->tenant->currentStoreId();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dekont klasörü oluşturulamadı.');
        }
        $name = 'receipt-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Dekont kaydedilemedi.');
        }
        return 'receipts/' . $this->tenant->currentStoreId() . '/' . $name;
    }
}
