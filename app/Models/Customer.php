<?php
/**
 * OzyConnect Panel — Customer Model
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 */
declare(strict_types=1);

namespace App\Models;

final class Customer extends BaseModel
{
    protected string $table = 'customers';

    /**
     * Sipariş verisinden müşteri bul veya oluştur.
     *
     * Trendyol maskelenmiş e-posta kullanıyor (pf+xxx@trendyolmail.com)
     * bu yüzden e-posta güvenilir değil. Eşleştirme önceliği:
     *   1. TC/Vergi no (en güvenilir)
     *   2. Telefon (Trendyol bazen null gönderiyor)
     *   3. İsim (tam eşleşme)
     */
    public function findOrCreateFromOrder(array $orderData): int
    {
        $name  = trim($orderData['customer_name'] ?? '');
        $email = trim($orderData['customer_email'] ?? '');
        $phone = trim($orderData['customer_phone'] ?? '');
        $idNo  = trim($orderData['customer_id_no'] ?? '');

        // Maskelenmiş e-postaları yoksay
        $isMaskedEmail = str_contains($email, 'trendyolmail.com')
                      || str_contains($email, 'marketplace.com')
                      || str_contains($email, 'masked');

        $existing = false;

        // 1. TC/Vergi no ile ara (en güvenilir)
        if ($existing === false && !empty($idNo) && $idNo !== '1111111111' && $idNo !== '11111111111') {
            $existing = $this->rawQueryOne(
                "SELECT id FROM customers WHERE id_no = :id_no AND store_id = :store_id LIMIT 1",
                ['id_no' => $idNo, 'store_id' => $this->tenantId()]
            );
        }

        // 2. Telefon ile ara (rakamları temizleyerek)
        if ($existing === false && !empty($phone)) {
            $cleanPhone = preg_replace('/\D/', '', $phone);
            if (strlen($cleanPhone) >= 10) {
                // Son 10 haneyi al (başındaki 0 veya +90 kaldır)
                $last10 = substr($cleanPhone, -10);
                $existing = $this->rawQueryOne(
                    "SELECT id FROM customers
                     WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 10) = :phone
                       AND store_id = :store_id
                     LIMIT 1",
                    ['phone' => $last10, 'store_id' => $this->tenantId()]
                );
            }
        }

        // 3. Gerçek e-posta ile ara (maskelenmiş değilse)
        if ($existing === false && !empty($email) && !$isMaskedEmail) {
            $existing = $this->rawQueryOne(
                "SELECT id FROM customers WHERE email = :email AND store_id = :store_id LIMIT 1",
                ['email' => $email, 'store_id' => $this->tenantId()]
            );
        }

        // 4. İsim ile ara (tam eşleşme — son çare)
        if ($existing === false && !empty($name)) {
            $existing = $this->rawQueryOne(
                "SELECT id FROM customers WHERE name = :name AND store_id = :store_id LIMIT 1",
                ['name' => $name, 'store_id' => $this->tenantId()]
            );
        }

        if ($existing !== false && !empty($existing['id'])) {
            $this->updateOnOrder((int) $existing['id'], $orderData);
            return (int) $existing['id'];
        }

        // Yeni müşteri oluştur
        $shipping = $orderData['shipping_address'] ?? [];
        if (is_string($shipping)) {
            $shipping = json_decode($shipping, true) ?: [];
        }

        $customerId = $this->create([
            'name'           => $name,
            'email'          => (!$isMaskedEmail && !empty($email)) ? $email : null,
            'phone'          => !empty($phone) ? $phone : null,
            'id_no'          => !empty($idNo) ? $idNo : null,
            // Bazı pazaryeri servisleri (ör. Amazon) bu anahtarı hiç göndermiyor;
            // korumasız erişim uyarı → istisna → müşteri hiç oluşturulamıyordu.
            'tax_no'         => !empty($orderData['customer_tax_office']) ? ($idNo ?: null) : null,
            'tax_office'     => $orderData['customer_tax_office'] ?? null,
            'address'        => $shipping['address'] ?? $shipping['fullAddress'] ?? null,
            'city'           => $shipping['city'] ?? null,
            'district'       => $shipping['district'] ?? null,
            'source_marketplace_id' => $orderData['marketplace_id'] ?? null,
            'total_orders'   => 1,
            'total_spent'    => (float) ($orderData['total_amount'] ?? 0),
            'first_order_at' => $orderData['ordered_at'] ?? date('Y-m-d H:i:s'),
            'last_order_at'  => $orderData['ordered_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return $customerId;
    }

    /**
     * Mevcut müşteriyi sipariş verisiyle güncelle
     */
    private function updateOnOrder(int $customerId, array $orderData): void
    {
        $customer = $this->find($customerId);
        if ($customer === false) {
            return;
        }

        $update = [];

        // Eksik bilgileri doldur
        $name = trim($orderData['customer_name'] ?? '');
        if (empty($customer['name']) && !empty($name)) {
            $update['name'] = $name;
        }

        $phone = trim($orderData['customer_phone'] ?? '');
        if (empty($customer['phone']) && !empty($phone)) {
            $update['phone'] = $phone;
        }

        $idNo = trim($orderData['customer_id_no'] ?? '');
        if (empty($customer['id_no']) && !empty($idNo) && $idNo !== '1111111111') {
            $update['id_no'] = $idNo;
        }

        $email = trim($orderData['customer_email'] ?? '');
        $isMasked = str_contains($email, 'trendyolmail.com') || str_contains($email, 'masked');
        if (empty($customer['email']) && !empty($email) && !$isMasked) {
            $update['email'] = $email;
        }

        $shipping = $orderData['shipping_address'] ?? [];
        if (is_string($shipping)) {
            $shipping = json_decode($shipping, true) ?: [];
        }
        if (empty($customer['city']) && !empty($shipping['city'])) {
            $update['city']     = $shipping['city'];
            $update['district'] = $shipping['district'] ?? '';
            $update['address']  = $shipping['address'] ?? $shipping['fullAddress'] ?? '';
        }

        if (empty($customer['source_marketplace_id']) && !empty($orderData['marketplace_id'])) {
            $update['source_marketplace_id'] = $orderData['marketplace_id'];
        }

        // İstatistikleri güncelle
        $update['total_orders'] = (int) $customer['total_orders'] + 1;
        $update['total_spent']  = (float) $customer['total_spent'] + (float) ($orderData['total_amount'] ?? 0);
        $update['last_order_at'] = date('Y-m-d H:i:s');

        $this->update($customerId, $update);
    }

    /**
     * DataTable server-side filtreli müşteri listesi
     */
    /**
     * Mevcut siparişlerden oluşmuş boş müşteri kartlarını tekrar sayım
     * yapmadan doldurur. Sync tekrarlandığında toplamları şişirmez.
     */
    public function repairFromOrder(int $customerId, array $orderData): void
    {
        $customer = $this->find($customerId);
        if ($customer === false) {
            return;
        }

        $update = [];

        $name = trim($orderData['customer_name'] ?? '');
        if (empty($customer['name']) && $name !== '') {
            $update['name'] = $name;
        }

        $phone = trim($orderData['customer_phone'] ?? '');
        if (empty($customer['phone']) && $phone !== '') {
            $update['phone'] = $phone;
        }

        $idNo = trim($orderData['customer_id_no'] ?? '');
        if (empty($customer['id_no']) && $idNo !== '' && $idNo !== '1111111111' && $idNo !== '11111111111') {
            $update['id_no'] = $idNo;
        }

        $email = trim($orderData['customer_email'] ?? '');
        $isMasked = str_contains($email, 'trendyolmail.com')
            || str_contains($email, 'marketplace.com')
            || str_contains($email, 'masked');
        if (empty($customer['email']) && $email !== '' && !$isMasked) {
            $update['email'] = $email;
        }

        if (empty($customer['tax_office']) && !empty($orderData['customer_tax_office'])) {
            $update['tax_office'] = $orderData['customer_tax_office'];
        }

        $shipping = $orderData['shipping_address'] ?? [];
        if (is_string($shipping)) {
            $shipping = json_decode($shipping, true) ?: [];
        }
        if (is_array($shipping)) {
            if (empty($customer['city']) && !empty($shipping['city'])) {
                $update['city'] = $shipping['city'];
            }
            if (empty($customer['district']) && !empty($shipping['district'])) {
                $update['district'] = $shipping['district'];
            }
            if (empty($customer['address'])) {
                $address = $shipping['address'] ?? $shipping['fullAddress'] ?? '';
                if ($address !== '') {
                    $update['address'] = $address;
                }
            }
        }

        if (empty($customer['source_marketplace_id']) && !empty($orderData['marketplace_id'])) {
            $update['source_marketplace_id'] = $orderData['marketplace_id'];
        }

        if (empty($customer['first_order_at']) && !empty($orderData['ordered_at'])) {
            $update['first_order_at'] = $orderData['ordered_at'];
        }
        if (empty($customer['last_order_at']) && !empty($orderData['ordered_at'])) {
            $update['last_order_at'] = $orderData['ordered_at'];
        }

        if ($update !== []) {
            $this->update($customerId, $update);
        }
    }

    public function getFilteredDT(array $params): array
    {
        $draw   = (int) ($params['draw'] ?? 1);
        $start  = (int) ($params['start'] ?? 0);
        $length = min((int) ($params['length'] ?? 25), 100);
        $search = trim($params['search']['value'] ?? '');
        $mpId   = $params['marketplace_id'] ?? '';

        $where  = ['1=1', 'c.store_id = :store_id'];
        $bind   = ['store_id' => $this->tenantId()];

        // Entitlement scope: yalnız mağazanın aktif/geçerli pazaryeri kaynaklı müşteriler (sayım metoduyla AYNI subquery).
        $where[]              = "c.source_marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $bind['store_id_ent'] = $this->tenantId();

        if ($search !== '') {
            $where[] = "(c.name LIKE :s1 OR c.email LIKE :s2 OR c.phone LIKE :s3 OR c.id_no LIKE :s4 OR c.city LIKE :s5)";
            $bind['s1'] = "%{$search}%";
            $bind['s2'] = "%{$search}%";
            $bind['s3'] = "%{$search}%";
            $bind['s4'] = "%{$search}%";
            $bind['s5'] = "%{$search}%";
        }

        if ($mpId !== '') {
            $where[] = "c.source_marketplace_id = :mid";
            $bind['mid'] = $mpId;
        }

        $whereStr = implode(' AND ', $where);

        // Sıralama
        $orderCol = (int) ($params['order'][0]['column'] ?? 0);
        $orderDir = strtolower($params['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $sortCols = ['c.name','c.phone','c.city','c.total_orders','c.total_spent','c.last_order_at'];
        $sort = $sortCols[$orderCol] ?? 'c.last_order_at';

        // Toplam (arama/pazaryeri filtresi hariç ama entitlement scope dahil → "Tümü" pill sayımıyla tutarlı)
        $totalRow = $this->rawQueryOne(
            "SELECT COUNT(*) as cnt FROM customers c
             WHERE c.store_id = :store_id
               AND c.source_marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))",
            ['store_id' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );
        $totalAll = (int) ($totalRow['cnt'] ?? 0);

        // Filtreli toplam
        $filtRow = $this->rawQueryOne("SELECT COUNT(*) as cnt FROM customers c WHERE {$whereStr}", $bind);
        $filtTotal = (int) ($filtRow['cnt'] ?? 0);

        // Veri çek
        $data = $this->rawQuery(
            "SELECT c.*, m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM customers c
             LEFT JOIN marketplaces m ON c.source_marketplace_id = m.id
             WHERE {$whereStr}
             ORDER BY {$sort} {$orderDir}
             LIMIT {$length} OFFSET {$start}",
            $bind
        );

        return [
            'draw'            => $draw,
            'recordsTotal'    => $totalAll,
            'recordsFiltered' => $filtTotal,
            'data'            => $data,
        ];
    }

    /**
     * Filtreli müşteri listesi (eski — sayfalama ile)
     */
    public function getFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1=1', 'store_id = :store_id'];
        $params = ['store_id' => $this->tenantId()];

        if (!empty($filters['search'])) {
            $where[]     = "(name LIKE :s OR email LIKE :s2 OR phone LIKE :s3)";
            $params['s']  = '%' . $filters['search'] . '%';
            $params['s2'] = '%' . $filters['search'] . '%';
            $params['s3'] = '%' . $filters['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $countRow = $this->rawQueryOne("SELECT COUNT(*) as cnt FROM customers WHERE {$whereStr}", $params);
        $total    = (int) ($countRow['cnt'] ?? 0);
        $data     = $this->rawQuery(
            "SELECT * FROM customers WHERE {$whereStr} ORDER BY last_order_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'data'       => $data,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * Pazaryeri bazlı müşteri sayıları
     */
    public function getMarketplaceCounts(): array
    {
        // Entitlement-scoped: filtreli liste (getFilteredDT) ile AYNI subquery → pill sayıları tabloyla tutarlı.
        $rows = $this->rawQuery(
            "SELECT source_marketplace_id, COUNT(*) as cnt FROM customers
             WHERE store_id = :store_id
               AND source_marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
             GROUP BY source_marketplace_id",
            ['store_id' => $this->tenantId(), 'store_id_ent' => $this->tenantId()]
        );
        $counts = ['all' => 0];
        foreach ($rows as $row) {
            $mpId = $row['source_marketplace_id'] ?? 'none';
            $counts[$mpId] = (int) $row['cnt'];
            $counts['all'] += (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Müşterinin tüm siparişleri
     */
    public function getOrders(int $customerId): array
    {
        return $this->rawQuery(
            "SELECT o.*, m.display_name as marketplace_name, m.slug as marketplace_slug
             FROM orders o
             JOIN marketplaces m ON o.marketplace_id = m.id
             WHERE o.customer_id = :cid AND o.store_id = :store_id
             ORDER BY o.ordered_at DESC",
            ['cid' => $customerId, 'store_id' => $this->tenantId()]
        );
    }

    /**
     * Genel müşteri istatistikleri
     */
    public function getOverviewStats(?int $marketplaceId = null): array
    {
        $where = 'store_id = :store_id';
        $bind  = ['store_id' => $this->tenantId()];
        // Entitlement: getFilteredDT + getMarketplaceCounts ile AYNI kapsam (stat kartları/başlık tutarlılığı).
        $where .= " AND source_marketplace_id IN (SELECT sm.marketplace_id FROM store_marketplaces sm WHERE sm.store_id = :store_id_ent AND sm.status = 'active' AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))";
        $bind['store_id_ent'] = $this->tenantId();
        if ($marketplaceId !== null) {
            $where .= ' AND source_marketplace_id = :mid';
            $bind['mid'] = $marketplaceId;
        }
        $row = $this->rawQueryOne(
            "SELECT COUNT(*) as total,
                    COALESCE(SUM(total_spent), 0) as total_revenue,
                    COALESCE(AVG(total_orders), 0) as avg_orders
             FROM customers WHERE {$where}",
            $bind
        );
        return [
            'total'         => (int) ($row['total'] ?? 0),
            'total_revenue' => (float) ($row['total_revenue'] ?? 0),
            'avg_orders'    => round((float) ($row['avg_orders'] ?? 0), 1),
        ];
    }
}
