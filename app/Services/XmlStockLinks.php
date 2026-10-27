<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Services\Marketplace\TrendyolProductRules;

/** Stock-only aliases. Listing and pricing ownership deliberately remain unchanged. */
final class XmlStockLinks
{
    public function __construct(private readonly Database $db, private readonly TenantContext $tenant) {}

    public function acquire(): string
    {
        $key = 'trendyol_inventory_' . $this->tenant->currentStoreId();
        $row = $this->db->fetchOne('SELECT GET_LOCK(?,15) AS acquired',[$key]);
        if ((int)($row['acquired'] ?? 0) !== 1) throw new \RuntimeException('Başka bir stok gönderimi sürüyor; tekrar denenecek.');
        return $key;
    }

    public function release(string $key): void
    {
        $this->db->query('SELECT RELEASE_LOCK(?)',[$key]);
    }

    public function register(int $marketplaceId, int $profileId, array $link): void
    {
        $sid = $this->tenant->currentStoreId();
        if (!$this->db->fetchOne('SELECT id FROM xml_profiles WHERE id=? AND store_id=? AND direction=?', [$profileId,$sid,'import'])) {
            throw new \RuntimeException('Stok kaynağı bu mağazaya ait değil.');
        }
        $barcode = trim((string)($link['barcode'] ?? ''));
        $sku = trim((string)($link['source_sku'] ?? ''));
        if ($barcode === '' || $sku === '' || empty($link['evidence'])) throw new \InvalidArgumentException('Kanıtlı kaynak ve barkod gerekli.');
        $legacy = !empty($link['legacy_product_id']) ? (int)$link['legacy_product_id'] : null;
        if ($legacy && !$this->db->fetchOne('SELECT id FROM products WHERE id=? AND store_id=? AND (xml_profile_id IS NULL OR xml_profile_id=?)', [$legacy,$sid,$profileId])) {
            throw new \RuntimeException('Eski ürün başka mağazaya veya XML kaynağına ait.');
        }
        $existing = $this->db->fetchOne('SELECT * FROM marketplace_stock_links WHERE store_id=? AND marketplace_id=? AND barcode=?', [$sid,$marketplaceId,$barcode]);
        if ($existing) {
            if ((int)$existing['xml_profile_id'] !== $profileId || $existing['source_sku'] !== $sku) throw new \RuntimeException('Mevcut stok kaynağı çakışıyor.');
            return;
        }
        $this->db->query('INSERT INTO marketplace_stock_links (store_id,marketplace_id,barcode,marketplace_sku,xml_profile_id,source_sku,legacy_product_id,match_method,evidence_json) VALUES (?,?,?,?,?,?,?,?,?)',
            [$sid,$marketplaceId,$barcode,(string)($link['marketplace_sku']??''),$profileId,$sku,$legacy,(string)$link['match_method'],json_encode($link['evidence'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    public function rows(int $marketplaceId = 0): array
    {
        $sid = $this->tenant->currentStoreId();
        return $this->db->fetchAll('SELECT l.*,p.id AS source_product_id,p.stock_qty,p.is_active,p.is_archived,p.missing_stock,
                old.is_active AS legacy_active,old.is_archived AS legacy_archived,mp.is_active AS listing_active,mp.stock_offset
            FROM marketplace_stock_links l
            LEFT JOIN products p ON p.store_id=l.store_id AND p.xml_profile_id=l.xml_profile_id AND BINARY p.sku=BINARY l.source_sku
            LEFT JOIN products old ON old.id=l.legacy_product_id AND old.store_id=l.store_id
            LEFT JOIN marketplace_products mp ON mp.product_id=old.id AND mp.store_id=l.store_id AND mp.marketplace_id=l.marketplace_id
            WHERE l.store_id=?'.($marketplaceId ? ' AND l.marketplace_id=?' : ''), $marketplaceId ? [$sid,$marketplaceId] : [$sid]);
    }

    public static function quantity(array $row): int
    {
        if (empty($row['source_product_id']) || !empty($row['missing_stock'])
            || (isset($row['legacy_active']) && !(int)$row['legacy_active']) || !empty($row['legacy_archived'])
            || (isset($row['listing_active']) && !(int)$row['listing_active'])) return 0;
        return TrendyolProductRules::quantity(max(0, StockAvailability::quantity($row) - max(0,(int)($row['stock_offset']??0))));
    }

    /** Called by every inventory writer, including manual updates and returns. */
    public function guardPayload(array $items, int $marketplaceId): array
    {
        $links = array_column($this->rows($marketplaceId), null, 'barcode');
        foreach ($items as &$item) {
            if (array_key_exists('quantity',$item) && isset($links[(string)($item['barcode']??'')])) {
                $item['quantity'] = self::quantity($links[(string)$item['barcode']]);
            }
        }
        unset($item);
        return $items;
    }

    public function items(int $marketplaceId, ?array $productIds = null, bool $changedOnly = true): array
    {
        $items = [];
        foreach ($this->rows($marketplaceId) as $row) {
            if ($productIds !== null && !in_array((int)$row['source_product_id'],$productIds,true) && !in_array((int)$row['legacy_product_id'],$productIds,true)) continue;
            $quantity = self::quantity($row);
            if ($quantity > 0 && !empty($row['blocked_reason'])) continue;
            if ($changedOnly && $row['observed_quantity'] !== null && (int)$row['observed_quantity'] === $quantity
                && ($quantity > 0 || !(bool)$row['observed_on_sale'])) continue;
            $items[] = ['barcode'=>$row['barcode'],'quantity'=>$quantity];
        }
        return $items;
    }

    /** Resolve a renamed listing to its real stock owner before new order reservations. */
    public function productForListing(int $marketplaceId, string $barcode): ?int
    {
        foreach ($this->rows($marketplaceId) as $row) {
            if ($row['barcode'] === $barcode && !empty($row['source_product_id'])) return (int)$row['source_product_id'];
        }
        return null;
    }

    /** Only stock fields on old local cards are mirrored; no product metadata is copied. */
    public function refreshLegacyStock(?int $profileId = null): void
    {
        $sid = $this->tenant->currentStoreId();
        foreach ($this->rows() as $row) {
            if ($profileId !== null && (int)$row['xml_profile_id'] !== $profileId) continue;
            if (empty($row['legacy_product_id']) || (int)$row['legacy_product_id'] === (int)$row['source_product_id']) continue;
            $quantity = empty($row['source_product_id']) || !empty($row['missing_stock']) ? 0 : StockAvailability::quantity($row);
            $this->db->query('UPDATE products SET stock_qty=?,missing_stock=? WHERE store_id=? AND id=? AND xml_profile_id IS NULL AND (stock_qty<>? OR missing_stock<>?)',
                [$quantity,$quantity===0?1:0,$sid,$row['legacy_product_id'],$quantity,$quantity===0?1:0]);
        }
    }

    public function observe(int $marketplaceId, array $products): void
    {
        $sid = $this->tenant->currentStoreId();
        $linked = array_fill_keys(array_column($this->rows($marketplaceId),'barcode'),true);
        $receipts = array_fill_keys(array_column($this->db->fetchAll('SELECT barcode FROM trendyol_stock_receipts WHERE store_id=?',[$sid]),'barcode'),true);
        foreach ($products as $product) {
            if (!array_key_exists('stock',$product)) continue;
            $barcode = (string)($product['barcode'] ?? '');
            if (!isset($linked[$barcode]) && !isset($receipts[$barcode])) continue;
            $raw = $product['raw']['variant'] ?? $product['raw'] ?? [];
            $qty = (int)$product['stock'];
            $sale = isset($raw['onSale']) ? (int)(bool)$raw['onSale'] : null;
            $blocked = !empty($raw['blacklisted']) ? (string)($raw['blacklistReason']??'Trendyol ilanı engelledi') : null;
            if (isset($linked[$barcode])) $this->db->query('UPDATE marketplace_stock_links SET observed_quantity=?,observed_on_sale=?,observed_at=NOW(),blocked_reason=? WHERE store_id=? AND marketplace_id=? AND barcode=?',
                [$qty,$sale,$blocked,$sid,$marketplaceId,$product['barcode']]);
            if (isset($receipts[$barcode]) && ($qty > 0 || $sale === 0)) $this->db->query("UPDATE trendyol_stock_receipts SET status='confirmed',error_text=NULL,checked_at=NOW() WHERE store_id=? AND barcode=? AND quantity=?", [$sid,$product['barcode'],$qty]);
        }
    }

    public function receipt(string $batch, array $items): void
    {
        foreach ($items as $item) $this->db->query("INSERT INTO trendyol_stock_receipts (store_id,barcode,quantity,batch_id,status) VALUES (?,?,?,?,'pending')
            ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),batch_id=VALUES(batch_id),status='pending',error_text=NULL,created_at=NOW(),checked_at=NULL",
            [$this->tenant->currentStoreId(),$item['barcode'],$item['quantity'],$batch]);
    }

    public function recordBatch(string $batch, array $result): array
    {
        $counts=['success'=>0,'failed'=>0,'errors'=>[]];
        foreach ($result['items'] ?? [] as $item) {
            $barcode = (string)($item['requestItem']['barcode'] ?? $item['barcode'] ?? '');
            $status = strtoupper((string)($item['status']??''));
            if ($barcode==='' || !in_array($status,['SUCCESS','FAILED'],true)) continue;
            $error = $status==='FAILED' ? json_encode($item['failureReasons']??[],JSON_UNESCAPED_UNICODE) : null;
            $this->db->query("UPDATE trendyol_stock_receipts SET status=?,error_text=?,checked_at=NOW() WHERE store_id=? AND barcode=? AND batch_id=? AND status<>'confirmed'",
                [$status==='SUCCESS'?'accepted':'failed',$error,$this->tenant->currentStoreId(),$barcode,$batch]);
            $counts[$status==='SUCCESS'?'success':'failed']++;
            if ($error!==null) $counts['errors'][]=['barcode'=>$barcode,'error'=>$error];
        }
        return $counts;
    }
}
