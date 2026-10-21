<?php
/**
 * OzyConnect — Zengin DEMO seed (store #1). İDEMPOTENT: her çalıştırmada önce DEMO-işaretli
 * kayıtları siler, sonra yeniden üretir. Sadece demo-işaretli satırlara dokunur (gerçek veri güvende).
 * Markerlar: products.sku LIKE 'DEMO-%', customers.email LIKE '%@demo.ozyconnect.local',
 *            orders.marketplace_order_id LIKE 'DEMO-%', financial_transactions.external_id LIKE 'DEMO-%',
 *            returns.marketplace_return_id LIKE 'DEMO-%', sync_logs.error_details='demo-seed'.
 */
// Normal CLI kullanımında uygulamayı başlat; installer kendi güvenli DB adaptörünü sağlar.
if (!isset($GLOBALS['container'])) {
  require __DIR__ . '/../../public/index.php';
}
$db = $GLOBALS['container']->get(\App\Core\Database::class);
$STORE = 1;
mt_srand(20260619);

$marketplaceConfig = [
  ['slug'=>'trendyol','w'=>30,'fin'=>true,'comm'=>0.135],
  ['slug'=>'hepsiburada','w'=>16,'fin'=>true,'comm'=>0.150],
  ['slug'=>'shopify','w'=>12,'fin'=>false,'comm'=>0.0],
  ['slug'=>'n11','w'=>10,'fin'=>false,'comm'=>0.12],
  ['slug'=>'amazon','w'=>8,'fin'=>false,'comm'=>0.15],
  ['slug'=>'woocommerce','w'=>7,'fin'=>false,'comm'=>0.0],
  ['slug'=>'pazarama','w'=>6,'fin'=>false,'comm'=>0.11],
  ['slug'=>'ciceksepeti','w'=>5,'fin'=>false,'comm'=>0.13],
  ['slug'=>'opencart','w'=>5,'fin'=>false,'comm'=>0.0],
];
$MPS = [];
foreach ($marketplaceConfig as $marketplace) {
  $row = $db->fetchOne('SELECT id FROM marketplaces WHERE slug=:slug LIMIT 1', ['slug'=>$marketplace['slug']]);
  if ($row !== false) {
    $marketplace['id'] = (int)$row['id'];
    $MPS[] = $marketplace;
  }
}
if ($MPS === []) {
  throw new RuntimeException('Demo verisi için pazaryeri tanımı bulunamadı.');
}
$wpool = []; foreach ($MPS as $i => $m) { for ($k = 0; $k < $m['w']; $k++) { $wpool[] = $i; } }
$mpById = []; foreach ($MPS as $m) { $mpById[$m['id']] = $m; }

echo "=== CLEANUP (önceki demo) ===\n";
$db->query("DELETE oi FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.store_id=:s AND o.marketplace_order_id LIKE 'DEMO-%'", ['s'=>$STORE]);
$db->query("DELETE FROM returns WHERE store_id=:s AND marketplace_return_id LIKE 'DEMO-%'", ['s'=>$STORE]);
$db->query("DELETE FROM financial_transactions WHERE store_id=:s AND external_id LIKE 'DEMO-%'", ['s'=>$STORE]);
$db->query("DELETE FROM orders WHERE store_id=:s AND marketplace_order_id LIKE 'DEMO-%'", ['s'=>$STORE]);
$db->query("DELETE FROM products WHERE store_id=:s AND sku LIKE 'DEMO-%'", ['s'=>$STORE]);
$db->query("DELETE FROM customers WHERE store_id=:s AND email LIKE '%@demo.ozyconnect.local'", ['s'=>$STORE]);
$db->query("DELETE FROM sync_logs WHERE store_id=:s AND error_details='demo-seed'", ['s'=>$STORE]);
echo "  ok\n";

// ─── PRODUCTS (40) ───
$pNames = [
  'Kablosuz Kulaklık Pro ANC','Bluetooth Hoparlör 30W','Akıllı Saat Series 6','Powerbank 20.000mAh','USB-C Hızlı Şarj Adaptörü 65W',
  'Mekanik Oyuncu Klavyesi RGB','Kablosuz Mouse Sessiz','4K Webcam Full HD','Taşınabilir SSD 1TB','Tablet Kalemi Aktif',
  'Çelik Tencere Seti 7 Parça','Granit Tava 28cm','Kahve Makinesi Filtre','Su Isıtıcı 1.7L Cam','Blender Set 600W',
  'Nevresim Takımı Çift Kişilik','Yastık Seti Visco 2li','Havlu Seti Bambu 4lü','Masa Lambası LED Dokunmatik','Halı Yıkanabilir 160x230',
  'Erkek Spor Ayakkabı','Kadın Günlük Sneaker','Unisex Sırt Çantası','Deri Cüzdan El Yapımı','Güneş Gözlüğü Polarize',
  'Tişört %100 Pamuk','Sweatshirt Kapüşonlu','Kot Pantolon Slim Fit','Spor Eşofman Takımı','Şapka Beyzbol',
  'Cilt Bakım Seti 3lü','Saç Kurutma Makinesi 2200W','Elektrikli Diş Fırçası','Parfüm EDP 100ml','Makyaj Çantası Büyük',
  'Yoga Matı Kaymaz 6mm','Dambıl Seti 20kg','Akıllı Tartı Bluetooth','Termos Çelik 1L','Kamp Sandalyesi Katlanır',
];
$productIds = [];
foreach ($pNames as $i => $name) {
  $sku  = 'DEMO-P' . str_pad((string)($i+1), 4, '0', STR_PAD_LEFT);
  $cost = mt_rand(3000, 250000) / 100;          // 30 - 2500
  $base = round($cost * (1.25 + mt_rand(0, 90)/100), 2);
  $list = round($base * (1.05 + mt_rand(0, 25)/100), 2);
  $stock = (($i + 1) % 8 === 0) ? mt_rand(0, 4) : mt_rand(6, 320);  // ~5 ürün düşük stok
  $db->query(
    "INSERT INTO products (store_id,sku,barcode,name,cost_price,base_price,list_price,stock_qty,min_stock_alert,unit,tax_rate,is_active,is_archived,source,enrichment_status,main_image)
     VALUES (:s,:sku,:bc,:n,:cost,:base,:list,:st,5,'adet',20.00,1,0,'demo','done',:img)",
    ['s'=>$STORE,'sku'=>$sku,'bc'=>'868'.mt_rand(1000000000,9999999999),'n'=>$name,'cost'=>$cost,'base'=>$base,'list'=>$list,'st'=>$stock,'img'=>'']
  );
  $productIds[] = ['id'=>(int)$db->lastInsertId(),'sku'=>$sku,'name'=>$name,'price'=>$base];
}
echo "PRODUCTS: " . count($productIds) . "\n";

// ─── CUSTOMERS (40) ───
$fn = ['Ahmet','Mehmet','Mustafa','Ayşe','Fatma','Emine','Ali','Hüseyin','Hatice','Zeynep','İbrahim','Murat','Elif','Can','Cem','Deniz','Burak','Seda','Merve','Okan','Pelin','Onur','Gizem','Kaan','Buse','Emre','Ece','Tolga','Sıla','Berk'];
$ln = ['Yılmaz','Kaya','Demir','Şahin','Çelik','Yıldız','Yıldırım','Öztürk','Aydın','Arslan','Doğan','Kılıç','Aslan','Çetin','Kara','Koç','Kurt','Özkan','Şimşek','Polat'];
$cities = [['İstanbul','Kadıköy'],['Ankara','Çankaya'],['İzmir','Konak'],['Bursa','Nilüfer'],['Antalya','Muratpaşa'],['Adana','Seyhan'],['Konya','Selçuklu'],['Gaziantep','Şehitkamil'],['Kayseri','Melikgazi'],['Eskişehir','Tepebaşı']];
$customerIds = [];
for ($i = 1; $i <= 40; $i++) {
  $name = $fn[array_rand($fn)] . ' ' . $ln[array_rand($ln)];
  $email = 'demo' . $i . '@demo.ozyconnect.local';
  $phone = '5' . mt_rand(300000000, 599999999);
  [$city,$dist] = $cities[array_rand($cities)];
  $mp = $MPS[$wpool[array_rand($wpool)]];
  $db->query(
    "INSERT INTO customers (store_id,name,email,phone,city,district,source_marketplace_id) VALUES (:s,:n,:e,:p,:c,:d,:mp)",
    ['s'=>$STORE,'n'=>$name,'e'=>$email,'p'=>$phone,'c'=>$city,'d'=>$dist,'mp'=>$mp['id']]
  );
  $customerIds[] = ['id'=>(int)$db->lastInsertId(),'name'=>$name,'email'=>$email,'phone'=>$phone,'city'=>$city,'district'=>$dist];
}
echo "CUSTOMERS: " . count($customerIds) . "\n";

// ─── ORDERS (160) — son 30 güne ağırlıklı, gerçekçi statü hunisi ───
$cargo = ['Yurtiçi Kargo','Aras Kargo','MNG Kargo','Trendyol Express','PTT Kargo','Sürat Kargo','HepsiJet'];
$nextReturnId = (int)($db->fetchOne("SELECT COALESCE(MAX(id),0)+1 AS x FROM returns")['x'] ?? 1);
$ORDERS = 160; $seq = 100001; $cntFin = 0; $cntRet = 0; $cntItems = 0;
$statusCount = [];
for ($n = 0; $n < $ORDERS; $n++) {
  $mp = $MPS[$wpool[array_rand($wpool)]];
  $cust = $customerIds[array_rand($customerIds)];
  // tarih: %48 son 7 gün (grafik dolsun), kalanı 8-29 gün
  $daysAgo = (mt_rand(1,100) <= 48) ? mt_rand(0,6) : mt_rand(7,29);
  $ts = time() - $daysAgo*86400 - mt_rand(0, 80000);
  $orderedAt = date('Y-m-d H:i:s', $ts);
  // statü: yaşa göre huni
  if ($daysAgo <= 2)      { $pool = ['yeni','yeni','hazirlaniyor','hazirlaniyor','kargoda']; }
  elseif ($daysAgo <= 7)  { $pool = ['hazirlaniyor','kargoda','kargoda','teslim_edildi','iptal']; }
  else                    { $pool = ['teslim_edildi','teslim_edildi','teslim_edildi','teslim_edildi','iptal','iade']; }
  $status = $pool[array_rand($pool)];
  $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;

  // kalemler 1-3
  $nItems = mt_rand(1,3); $subtotal = 0.0; $items = [];
  $used = [];
  for ($it = 0; $it < $nItems; $it++) {
    $p = $productIds[array_rand($productIds)];
    if (isset($used[$p['id']])) { continue; }
    $used[$p['id']] = true;
    $qty = mt_rand(1,3); $unit = (float)$p['price']; $line = round($unit*$qty, 2);
    $subtotal += $line;
    $items[] = ['p'=>$p,'qty'=>$qty,'unit'=>$unit,'line'=>$line];
  }
  $discount = (mt_rand(1,100) <= 28) ? round($subtotal * mt_rand(5,18)/100, 2) : 0.00;
  $total = round($subtotal - $discount, 2);
  $tax   = round($total * 20 / 120, 2);
  $moid  = 'DEMO-' . strtoupper(substr($mp['slug'],0,2)) . '-' . $seq; $seq++;
  $hasCargo = in_array($status, ['kargoda','teslim_edildi','iade'], true);
  $cargoCo  = $hasCargo ? $cargo[array_rand($cargo)] : null;
  $trk      = $hasCargo ? strtoupper(substr($mp['slug'],0,2)) . mt_rand(100000000,999999999) : null;
  $sentAt   = $hasCargo ? date('Y-m-d H:i:s', $ts + mt_rand(3600, 172800)) : null;
  $addr = json_encode(['name'=>$cust['name'],'address'=>$cust['district'].' Mah. '.mt_rand(1,200).'. Sk No:'.mt_rand(1,90),'city'=>$cust['city'],'district'=>$cust['district'],'phone'=>$cust['phone']], JSON_UNESCAPED_UNICODE);

  $db->query(
    "INSERT INTO orders (store_id,marketplace_id,marketplace_order_id,customer_id,status,customer_name,customer_email,customer_phone,billing_address_json,shipping_address_json,subtotal,tax_total,discount_total,total_amount,currency,cargo_company,cargo_tracking_no,cargo_sent_at,ordered_at,created_at)
     VALUES (:s,:mp,:moid,:cid,:st,:cn,:ce,:cp,:bj,:sj,:sub,:tax,:disc,:tot,'TRY',:cc,:trk,:sent,:oa,:oa2)",
    ['s'=>$STORE,'mp'=>$mp['id'],'moid'=>$moid,'cid'=>$cust['id'],'st'=>$status,'cn'=>$cust['name'],'ce'=>$cust['email'],'cp'=>$cust['phone'],
     'bj'=>$addr,'sj'=>$addr,'sub'=>$subtotal,'tax'=>$tax,'disc'=>$discount,'tot'=>$total,'cc'=>$cargoCo,'trk'=>$trk,'sent'=>$sentAt,'oa'=>$orderedAt,'oa2'=>$orderedAt]
  );
  $oid = (int)$db->lastInsertId();
  foreach ($items as $d) {
    $db->query(
      "INSERT INTO order_items (store_id,order_id,product_id,sku,name,qty,unit_price,discount,tax_rate,line_total)
       VALUES (:s,:o,:pid,:sku,:n,:q,:u,0,20.00,:lt)",
      ['s'=>$STORE,'o'=>$oid,'pid'=>$d['p']['id'],'sku'=>$d['p']['sku'],'n'=>$d['p']['name'],'q'=>$d['qty'],'u'=>$d['unit'],'lt'=>$d['line']]
    );
    $cntItems++;
  }

  // Finans hareketleri (yalnız finans-API'li pazaryeri + iptal değil)
  if ($mp['fin'] && $status !== 'iptal') {
    $comm = round($total * $mp['comm'], 2);
    $db->query("INSERT INTO financial_transactions (store_id,marketplace_id,transaction_type,transaction_date,order_number,description,gross_amount,commission_amount,net_amount,currency,status,external_id,synced_at) VALUES (:s,:mp,'sale',:dt,:on,'Satış geliri',:g,0,:g2,'TRY','completed',:eid,:dt2)",
      ['s'=>$STORE,'mp'=>$mp['id'],'dt'=>$orderedAt,'on'=>$moid,'g'=>$total,'g2'=>$total,'eid'=>'DEMO-S-'.$oid,'dt2'=>$orderedAt]);
    $db->query("INSERT INTO financial_transactions (store_id,marketplace_id,transaction_type,transaction_date,order_number,description,gross_amount,commission_amount,net_amount,currency,status,external_id,synced_at) VALUES (:s,:mp,'commission',:dt,:on,'Komisyon kesintisi',:c,:c2,:cneg,'TRY','completed',:eid,:dt2)",
      ['s'=>$STORE,'mp'=>$mp['id'],'dt'=>$orderedAt,'on'=>$moid,'c'=>$comm,'c2'=>$comm,'cneg'=>-$comm,'eid'=>'DEMO-C-'.$oid,'dt2'=>$orderedAt]);
    if ($status === 'teslim_edildi') {
      $net = round($total - $comm, 2);
      $payDt = date('Y-m-d H:i:s', $ts + 7*86400);
      $db->query("INSERT INTO financial_transactions (store_id,marketplace_id,transaction_type,transaction_date,order_number,description,gross_amount,commission_amount,net_amount,currency,status,external_id,synced_at) VALUES (:s,:mp,'payment',:dt,:on,'Hakediş ödemesi',:g,0,:g2,'TRY','completed',:eid,:dt2)",
        ['s'=>$STORE,'mp'=>$mp['id'],'dt'=>$payDt,'on'=>$moid,'g'=>$net,'g2'=>$net,'eid'=>'DEMO-P-'.$oid,'dt2'=>$payDt]);
    }
    $cntFin++;
  }

  // İade kaydı (status=iade)
  if ($status === 'iade') {
    $rstatus = ['requested','approved','completed'][array_rand([0,1,2])];
    $fi = $items[0];
    try {
      $db->query("INSERT INTO returns (id,store_id,order_id,marketplace_return_id,marketplace_order_id,customer_name,customer_email,product_name,product_sku,quantity,marketplace_id,status,refund_amount,request_date,created_at) VALUES (:id,:s,:o,:rid,:moid,:cn,:ce,:pn,:psku,:q,:mp,:st,:ref,:rd,:rd2)",
        ['id'=>$nextReturnId++,'s'=>$STORE,'o'=>$oid,'rid'=>'DEMO-R-'.$oid,'moid'=>$moid,'cn'=>$cust['name'],'ce'=>$cust['email'],'pn'=>$fi['p']['name'],'psku'=>$fi['p']['sku'],'q'=>$fi['qty'],'mp'=>$mp['id'],'st'=>$rstatus,'ref'=>$total,'rd'=>$orderedAt,'rd2'=>$orderedAt]);
      $cntRet++;
    } catch (\Throwable $e) {
      echo "  (returns insert atlandı: " . $e->getMessage() . ")\n";
    }
  }
}
echo "ORDERS: {$ORDERS} (items={$cntItems}, finansHareket={$cntFin}, iade={$cntRet})\n";
echo "  statü dağılımı: " . json_encode($statusCount, JSON_UNESCAPED_UNICODE) . "\n";

// ─── Müşteri toplamlarını güncelle ───
$db->query("UPDATE customers c
  SET total_orders=(SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id AND o.store_id=:s1),
      total_spent=(SELECT COALESCE(SUM(o.total_amount),0) FROM orders o WHERE o.customer_id=c.id AND o.store_id=:s2),
      last_order_at=(SELECT MAX(o.ordered_at) FROM orders o WHERE o.customer_id=c.id AND o.store_id=:s3),
      first_order_at=(SELECT MIN(o.ordered_at) FROM orders o WHERE o.customer_id=c.id AND o.store_id=:s4)
  WHERE c.store_id=:s5 AND c.email LIKE '%@demo.ozyconnect.local'",
  ['s1'=>$STORE,'s2'=>$STORE,'s3'=>$STORE,'s4'=>$STORE,'s5'=>$STORE]);

// ─── SYNC LOGS (son başarılı senkronlar) ───
$marketplaceIds = [];
foreach ($MPS as $marketplace) {
  $marketplaceIds[$marketplace['slug']] = $marketplace['id'];
}
foreach ([['orders','trendyol'],['orders','hepsiburada'],['orders','shopify'],['stock','trendyol'],['prices','trendyol'],['orders','n11']] as [$type,$slug]) {
  if (!isset($marketplaceIds[$slug])) { continue; }
  $mid = $marketplaceIds[$slug];
  $st = time() - mt_rand(600, 86400);
  $db->query("INSERT INTO sync_logs (store_id,marketplace_id,sync_type,status,records_fetched,records_processed,records_failed,error_details,started_at,finished_at,duration_ms,triggered_by) VALUES (:s,:mp,:t,'success',:f,:p,0,'demo-seed',:sa,:fa,:d,'cron')",
    ['s'=>$STORE,'mp'=>$mid,'t'=>$type,'f'=>mt_rand(5,60),'p'=>mt_rand(5,60),'sa'=>date('Y-m-d H:i:s',$st),'fa'=>date('Y-m-d H:i:s',$st+mt_rand(2000,30000)),'d'=>mt_rand(2000,30000)]);
}
echo "SYNC_LOGS: 6\n";

// ─── APP UPDATES (changelog) — idempotent (başlığa göre) ───
$ups = [
  ['2026.6','OpenCart entegrasyonu eklendi','feature','OpenCart 2.3/3.0/4.x için token korumalı connector ile sipariş, ürün, stok ve fiyat senkronizasyonu; event ile anlık sipariş bildirimi.'],
  ['2026.6','WooCommerce entegrasyonu eklendi','feature','WooCommerce REST API v3 ile tam entegrasyon; webhook ile anlık sipariş, stok/fiyat gönderimi.'],
  ['2026.5','Shopify entegrasyonu eklendi','feature','Shopify Admin API ile sipariş/ürün/stok/fiyat ve webhook ile anlık sipariş.'],
  ['2026.6','Reklam pikselleri & Conversions API','improvement','Meta, Google (GA4/Ads) ve TikTok pikselleri + sunucu-taraflı dönüşüm takibi; KVKK çerez onayı.'],
  ['2026.6','Yeni Komuta Merkezi paneli','improvement','Animasyonlu KPI kartları, canlı grafikler ve tek-tab birleşik finans ekranı.'],
];
$au = 0;
foreach ($ups as [$v,$t,$ty,$b]) {
  $ex = $db->fetchOne("SELECT id FROM app_updates WHERE title=:t LIMIT 1", ['t'=>$t]);
  if ($ex === false) {
    $db->query("INSERT INTO app_updates (version,title,body,type,is_published,published_at) VALUES (:v,:t,:b,:ty,1,:pa)",
      ['v'=>$v,'t'=>$t,'b'=>$b,'ty'=>$ty,'pa'=>date('Y-m-d H:i:s', time()-mt_rand(0,20)*86400)]);
    $au++;
  }
}
echo "APP_UPDATES: +{$au}\n";

echo "\n=== SON DURUM (store #1) ===\n";
foreach (['products'=>"sku LIKE 'DEMO-%'", 'customers'=>"email LIKE '%@demo.ozyconnect.local'", 'orders'=>"marketplace_order_id LIKE 'DEMO-%'", 'financial_transactions'=>"external_id LIKE 'DEMO-%'", 'returns'=>"marketplace_return_id LIKE 'DEMO-%'"] as $t=>$cond) {
  $n = $db->fetchOne("SELECT COUNT(*) c FROM `{$t}` WHERE store_id=:s AND {$cond}", ['s'=>$STORE])['c'] ?? 0;
  echo "  demo {$t}: {$n}\n";
}
$o7 = $db->fetchOne("SELECT COUNT(*) c FROM orders WHERE store_id=1 AND ordered_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")['c'];
$rev30 = $db->fetchOne("SELECT COALESCE(SUM(total_amount),0) c FROM orders WHERE store_id=1 AND ordered_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")['c'];
echo "  son 7g sipariş (tüm): {$o7} | son 30g ciro: " . number_format((float)$rev30,2,',','.') . " TL\n";
echo "DONE.\n";
