<?php
/**
 * OzyConnect Panel — AddonService (Faz 11: admin mağaza add-on yönetimi)
 *
 * Admin'in bir mağazaya pakete EK olarak pazaryeri/modül ekleyip-çıkarması.
 *  - Ekle: entitlement ver (anında) + store_addons kaydı + (opsiyonel) kind='addon' ek fatura.
 *  - Çıkar: entitlement kaldır + add-on kaydını iptal et (yenileme faturası düşer — proration yok).
 * Fiyat kaynağı: modül → module_catalog, pazaryeri → marketplaces.price_monthly.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Marketplace;
use App\Models\ModuleCatalog;
use App\Models\StoreAddon;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;

final class AddonService
{
    public function __construct(
        private readonly EntitlementService $entitlement,
        private readonly StoreAddon $addons,
        private readonly ModuleCatalog $moduleCatalog,
        private readonly Marketplace $marketplaces,
        private readonly Subscription $subscriptions,
        private readonly SubscriptionInvoice $invoices,
        private readonly Logger $logger,
    ) {}

    /** Modül add-on ekle. @return string eklenen modülün etiketi */
    public function addModule(int $storeId, string $moduleKey, bool $createInvoice): string
    {
        if (!in_array($moduleKey, EntitlementService::ENTITLEMENT_MODULES, true)) {
            throw new \RuntimeException('Geçersiz modül.');
        }
        $label = $this->moduleCatalog->labelMap()[$moduleKey] ?? $moduleKey;
        $price = $this->moduleCatalog->priceOf($moduleKey);
        $sub   = $this->guardInvoice($storeId, $createInvoice, $price);

        $this->entitlement->grantModule($storeId, $moduleKey, null, 'admin_addon');
        $this->addons->activate($storeId, $moduleKey, $price, $this->cycleOf($sub), null);
        if ($createInvoice && $price > 0 && $sub !== false) {
            $this->createInvoice($storeId, (int) $sub['id'], ['module_key' => $moduleKey], $price);
        }
        $this->logger->info("Modül add-on eklendi: {$moduleKey}", 'Addon', ['store' => $storeId, 'invoice' => $createInvoice]);
        return $label;
    }

    /** Pazaryeri add-on ekle. @return string eklenen pazaryerinin adı */
    public function addMarketplace(int $storeId, int $marketplaceId, bool $createInvoice): string
    {
        $mp = $this->marketplaces->find($marketplaceId);
        if ($mp === false) {
            throw new \RuntimeException('Pazaryeri bulunamadı.');
        }
        $name  = (string) ($mp['display_name'] ?? ('#' . $marketplaceId));
        $price = (float) ($mp['price_monthly'] ?? 0);
        $sub   = $this->guardInvoice($storeId, $createInvoice, $price);

        $this->entitlement->grantMarketplace($storeId, $marketplaceId, null, 'admin_addon');
        $this->addons->activateMarketplace($storeId, $marketplaceId, $price, $this->cycleOf($sub), null);
        if ($createInvoice && $price > 0 && $sub !== false) {
            $this->createInvoice($storeId, (int) $sub['id'], ['marketplace_id' => $marketplaceId], $price);
        }
        $this->logger->info("Pazaryeri add-on eklendi: #{$marketplaceId}", 'Addon', ['store' => $storeId, 'invoice' => $createInvoice]);
        return $name;
    }

    /** Modül add-on'unu kaldır (yetki + add-on kaydı). @return string etiket */
    public function removeModule(int $storeId, string $moduleKey): string
    {
        $label = $this->moduleCatalog->labelMap()[$moduleKey] ?? $moduleKey;
        $this->entitlement->revokeModule($storeId, $moduleKey, 'admin_addon_remove');
        $this->addons->cancel($storeId, $moduleKey);
        $this->logger->info("Modül add-on kaldırıldı: {$moduleKey}", 'Addon', ['store' => $storeId]);
        return $label;
    }

    /** Pazaryeri add-on'unu kaldır. @return string ad */
    public function removeMarketplace(int $storeId, int $marketplaceId): string
    {
        $mp   = $this->marketplaces->find($marketplaceId);
        $name = $mp !== false ? (string) ($mp['display_name'] ?? ('#' . $marketplaceId)) : ('#' . $marketplaceId);
        $this->entitlement->revokeMarketplace($storeId, $marketplaceId, 'admin_addon_remove');
        $this->addons->cancelMarketplace($storeId, $marketplaceId);
        $this->logger->info("Pazaryeri add-on kaldırıldı: #{$marketplaceId}", 'Addon', ['store' => $storeId]);
        return $name;
    }

    /** Fatura istenmişse aboneliği doğrula (yoksa hiçbir değişiklik yapmadan hata ver). @return array|false subscription */
    private function guardInvoice(int $storeId, bool $createInvoice, float $price): array|false
    {
        $sub = $this->subscriptions->forStore($storeId);
        if ($createInvoice && $sub === false) {
            throw new \RuntimeException('Mağazanın aktif aboneliği yok; ek fatura kesilemez.');
        }
        if ($createInvoice && $price <= 0) {
            throw new \RuntimeException('Bu kalemin fiyatı 0 ₺ — ek fatura oluşturulamaz. Önce katalogdan fiyat girin.');
        }
        return $sub;
    }

    private function cycleOf(array|false $sub): string
    {
        return ($sub !== false && ($sub['billing_cycle'] ?? '') === 'yearly') ? 'yearly' : 'monthly';
    }

    /** @param array<string,mixed> $meta */
    private function createInvoice(int $storeId, int $subscriptionId, array $meta, float $amount): void
    {
        $this->invoices->create([
            'store_id'        => $storeId,
            'subscription_id' => $subscriptionId,
            'kind'            => 'addon',
            'meta_json'       => json_encode($meta),
            'number'          => $this->invoices->nextNumber(),
            'period_start'    => date('Y-m-d'),
            'period_end'      => date('Y-m-d', strtotime('+1 month')),
            'amount'          => $amount,
            'currency'        => 'TRY',
            'status'          => 'sent',
            'due_at'          => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
    }
}
