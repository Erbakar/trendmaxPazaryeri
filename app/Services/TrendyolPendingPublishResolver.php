<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Database,TenantContext};
use App\Models\MarketplaceProduct;
use App\Services\Marketplace\{TrendyolService,TrendyolPublishReceipt,TrendyolExistingListing};

/** Repairs expired publish receipts using complete, read-only catalogue snapshots. */
final class TrendyolPendingPublishResolver
{
    public function __construct(private readonly Database $db, private readonly TenantContext $tenant,
        private readonly MarketplaceProduct $listings, private readonly TrendyolService $trendyol) {}

    public function resolve(int $marketplaceId, array $rows): array
    {
        $result=['checked'=>0,'approved'=>0,'rejected'=>0,'pending'=>0,'expired'=>0,'errors'=>[]];
        if (!$rows) return $result;
        $receipts=[]; $needCatalog=false; $attempted=0;
        foreach ($rows as $row) {
            if (TrendyolPublishReceipt::expired($row)) { $needCatalog=true; continue; }
            $batch=substr($row['sync_error'],strlen('Batch kontrol bekliyor: '));
            if (array_key_exists($batch,$receipts) || $attempted>=20) continue;
            $attempted++;
            try {
                $receipts[$batch]=TrendyolPublishReceipt::states($this->trendyol->getBatchStatus($batch));
                foreach ($receipts[$batch] as $state) if (in_array($state['status'],['SUCCESS','FAILED'],true)) $needCatalog=true;
            } catch (\Throwable $e) { $receipts[$batch]=null; $result['errors'][]='Gönderim sonucu okunamadı: '.$e->getMessage(); }
        }
        $index=null;
        if ($needCatalog) {
            // Failure or incomplete pagination must never turn listings into "absent".
            $approved=$this->trendyol->readProductCatalog(true,true);
            $unapproved=$this->trendyol->readProductCatalog(false,true);
            $index=TrendyolPublishReceipt::index($approved,$unapproved);
        }
        foreach ($rows as $row) {
            $expired=TrendyolPublishReceipt::expired($row);
            $batch=substr($row['sync_error'],strlen('Batch kontrol bekliyor: '));
            if (!$expired && !array_key_exists($batch,$receipts)) continue;
            $result['checked']++;
            $this->listings->update((int)$row['id'],['updated_at'=>date('Y-m-d H:i:s')]);
            $state=$receipts[$batch]['barcode:'.(string)$row['barcode']]
                ?? $receipts[$batch]['sku:'.(string)$row['sku']] ?? null;
            // An existing catalogue entry does not prove that a new price/stock update succeeded.
            if (!empty($row['is_listed'])) {
                if (($state['status'] ?? '') === 'SUCCESS') {
                    $this->listings->update((int)$row['id'], ['sync_error'=>null]); $result['approved']++;
                } elseif (($state['status'] ?? '') === 'FAILED') {
                    $this->listings->update((int)$row['id'], ['sync_error'=>mb_substr('Pazaryeri reddetti: '.$state['message'],0,500)]); $result['rejected']++;
                } elseif ($expired) {
                    $this->listings->update((int)$row['id'], ['sync_error'=>'Gönderim sonucu süresi dolmuş; stok/fiyat güncellemesi ayrıca doğrulanmalı.']); $result['expired']++;
                } else $result['pending']++;
                continue;
            }
            $match=$index===null ? [] : TrendyolPublishReceipt::match($row,$index);
            if (!empty($match['conflict'])) {
                $this->listings->update((int)$row['id'],['sync_error'=>'Mevcut Trendyol kaydı — eşleştirme belirsiz; barkod/stok kodu kontrol edilmeli.']);
                $result['errors'][]='Ürün #'.$row['product_id'].': kimlik çakışması';
                continue;
            }
            if ($match) {
                $owners=$this->db->fetchAll('SELECT DISTINCT p.id FROM products p
                    LEFT JOIN marketplace_products mp ON mp.product_id=p.id AND mp.store_id=p.store_id AND mp.marketplace_id=?
                    WHERE p.store_id=? AND (p.barcode=? OR mp.marketplace_barcode=?)',
                    [$marketplaceId,$this->tenant->currentStoreId(),$match['barcode'],$match['barcode']]);
                if ($owners && (count($owners)!==1 || (int)$owners[0]['id']!==(int)$row['product_id'])) {
                    $result['errors'][]='Ürün #'.$row['product_id'].': barkod başka yerel ürüne bağlı';
                    $this->listings->update((int)$row['id'],['sync_error'=>'Mevcut Trendyol kaydı — barkod başka yerel ürüne bağlı; eşleştirme kontrol edilmeli.']);
                    continue;
                }
                $state=TrendyolExistingListing::verified([$match['bucket']=>['content'=>[$match['content']]]],$row,$match['barcode']);
                if ($state===null) { $result['pending']++; continue; }
                if ($match['bucket']==='approved') {
                    $this->listings->update((int)$row['id'],$state+['last_synced_at'=>date('Y-m-d H:i:s')]);$result['approved']++;
                } elseif (($match['content']['status']??'')==='rejected') {
                    $this->listings->update((int)$row['id'],$state);$result['rejected']++;
                } else {
                    // Keep polling approval after the batch succeeds. Do not claim it is live.
                    $result['pending']++;
                }
                continue;
            }
            if (($state['status'] ?? '')==='FAILED') {
                $this->listings->update((int)$row['id'],['sync_error'=>mb_substr('Pazaryeri reddetti: '.$state['message'],0,500)]);$result['rejected']++;
            } elseif ($expired && $index!==null) {
                $this->listings->update((int)$row['id'],['sync_error'=>'Gönderim sonucu süresi dolmuş; ürün Trendyol kataloğunda bulunamadı. Yeniden gönderilebilir.']);$result['expired']++;
            } else $result['pending']++;
        }
        return $result;
    }
}
