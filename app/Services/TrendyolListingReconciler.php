<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Services\Marketplace\TrendyolService;

/** Reconcile existing mappings with approved listings without importing supplier data. */
final class TrendyolListingReconciler
{
    public function __construct(private readonly Database $db, private readonly TenantContext $tenant,
        private readonly Marketplace $marketplace, private readonly TrendyolService $trendyol,
        private readonly EntitlementService $entitlements, private readonly XmlStockLinks $stockLinks) {}

    public static function match(array $remote, array $skuIndex, array $barcodeIndex): ?int
    {
        $sku = trim((string)($remote['marketplace_sku'] ?? ''));
        $barcode = trim((string)($remote['barcode'] ?? ''));
        if ($barcode === '') return null;
        $bySku = $sku !== '' ? array_values(array_unique($skuIndex[$sku] ?? [])) : [];
        $byBarcode = array_values(array_unique($barcodeIndex[$barcode] ?? []));
        if (count($bySku) > 1 || count($byBarcode) > 1) return null;
        if ($bySku && $byBarcode && $bySku[0] !== $byBarcode[0]) return null;
        return $byBarcode[0] ?? $bySku[0] ?? null;
    }

    public function run(bool $apply = false, bool $includeCatalog = false): array
    {
        $store = $this->tenant->currentStoreId();
        $mp = $this->marketplace->findBySlug('trendyol');
        if (!$mp || !$this->entitlements->canAccessMarketplace((int)$mp['id']) || !(int)$mp['is_active']) {
            throw new \RuntimeException('Aktif ve yetkili Trendyol bağlantısı gerekli.');
        }
        $mid = (int)$mp['id'];
        $rows = $this->db->fetchAll('SELECT p.id,p.sku,p.barcode,mp.marketplace_sku,mp.marketplace_barcode
            FROM products p JOIN marketplace_products mp ON mp.product_id=p.id AND mp.store_id=p.store_id
            WHERE p.store_id=:sid AND mp.marketplace_id=:mid', ['sid'=>$store,'mid'=>$mid]);
        $skuIndex = $barcodeIndex = [];
        foreach ($rows as $row) {
            foreach (['sku','marketplace_sku'] as $key) if (trim((string)$row[$key]) !== '') $skuIndex[trim($row[$key])][] = (int)$row['id'];
            foreach (['barcode','marketplace_barcode'] as $key) if (trim((string)$row[$key]) !== '') $barcodeIndex[trim($row[$key])][] = (int)$row['id'];
        }
        // Finish all reads and validate pagination before writing any mapping.
        $remote = []; $pages = null;
        for ($page=0; $pages === null || $page<$pages; $page++) {
            if ($page>=1000) throw new \RuntimeException('Ürün sayfalama sınırı aşıldı.');
            $response = $this->trendyol->importProducts($page, 100);
            if (!isset($response['totalPages'],$response['totalElements']) || !is_numeric($response['totalPages'])) throw new \RuntimeException('Eksik katalog yanıtı.');
            $currentPages=max(1,(int)$response['totalPages']);
            $pages=max($pages??1,$currentPages);
            // Approval can change totals during the scan. Only positive matches are
            // applied; absent listings are never disabled or inferred from totals.
            if (empty($response['products']) && $page >= $currentPages) break;
            if ((int)$response['totalElements']>0 && empty($response['products'])) throw new \RuntimeException('Katalog sayfası eksik; eşleştirme yapılmadı.');
            array_push($remote, ...$response['products']);
        }
        $blocked = [];
        foreach ($remote as $item) {
            $variant = $item['raw']['variant'] ?? $item['raw'] ?? [];
            if (!empty($variant['blacklisted'])) $blocked[(string)$item['barcode']] = (string)($variant['blacklistReason'] ?? 'Engelli ilan');
        }
        $plan=[]; $unmatched=0; $conflicts=[];
        foreach ($remote as $item) {
            $pid=self::match($item,$skuIndex,$barcodeIndex);
            if ($pid===null) { $unmatched++; continue; }
            // One local item must not be attached to multiple remote barcodes.
            if (isset($plan[$pid]) && $plan[$pid]['barcode']!==$item['barcode']) { $conflicts[$pid]=true; continue; }
            $plan[$pid]=$item;
        }
        foreach ($conflicts as $pid=>$_) unset($plan[$pid]);
        if ($apply) {
            $this->db->beginTransaction();
            try {
                foreach ($plan as $pid=>$item) {
                    $this->db->query('UPDATE marketplace_products SET is_listed=1,marketplace_barcode=:barcode,
                        marketplace_sku=:sku,marketplace_product_id=:remote_id,last_price_sent=:price,
                        last_stock_sent=COALESCE(:stock,last_stock_sent),sync_error=NULL
                        WHERE store_id=:sid AND marketplace_id=:mid AND product_id=:pid',
                        ['barcode'=>$item['barcode'],'sku'=>$item['marketplace_sku'],'remote_id'=>$item['marketplace_product_id'],
                         'price'=>$item['price'],'stock'=>$item['stock']??null,'sid'=>$store,'mid'=>$mid,'pid'=>$pid]);
                }
                $this->stockLinks->observe($mid, $remote);
                $this->db->commit();
            } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        }
        return ($includeCatalog?['approved_inventory'=>$remote]:[])+['store'=>$store,'mode'=>$apply?'applied':'dry_run','approved_variants'=>count($remote),
            'blocked_barcodes'=>$blocked,'matched'=>count($plan),'unmatched'=>$unmatched,'conflicting_products'=>count($conflicts),
            'samples'=>array_slice(array_map(static fn($r)=>['sku'=>$r['marketplace_sku'],'barcode'=>$r['barcode'],'remote_price'=>$r['price'],'remote_stock'=>$r['stock']??null],array_values($plan)),0,5)];
    }
}
