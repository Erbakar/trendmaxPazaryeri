<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 *  OzyConnect — OpenCart Connector (DB-direkt JSON köprüsü)
 * ════════════════════════════════════════════════════════════════════════
 *  Sürüm        : 1.0.0
 *  Uyumluluk    : OpenCart 2.3.x · 3.0.x · 4.0.x · 4.1.x
 *                 (ürün/sipariş/kategori/üretici tablo şeması bu sürümlerde ortak)
 *  Gereksinim   : PHP 7.2+ , MySQLi eklentisi
 *
 *  KURULUM (3 adım):
 *   1) Bu dosyayı OpenCart mağazanızın KÖK dizinine (config.php'nin yanına) yükleyin.
 *      Örn: https://magazaniz.com/ozyconnect.php
 *   2) Aşağıdaki OZYCONNECT_TOKEN satırını, OzyConnect panelindeki "Bağlantı Token'ı"
 *      ile birebir değiştirin.
 *   3) Panelde "Connector URL" alanına bu dosyanın tam adresini girin
 *      (örn. https://magazaniz.com/ozyconnect.php) ve "Test Et" deyin.
 *
 *  GÜVENLİK: Erişim token ile korunur (sabit-zaman karşılaştırma). Token'ı gizli tutun,
 *  yalnızca HTTPS üzerinden kullanın. Bu dosya OpenCart veritabanına config.php'deki
 *  bilgilerle bağlanır; başka kimseyle paylaşmayın.
 *
 *  @author OziyWeb · © 2025 — Proprietary
 * ════════════════════════════════════════════════════════════════════════
 */

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  AYAR — Panelden aldığınız token'ı tırnak içine yapıştırın:            ║
   define('OZYCONNECT_TOKEN', 'BURAYA-PANEL-TOKENINI-YAPISTIRIN');
// ╚══════════════════════════════════════════════════════════════════════╝

define('OZYCONNECT_VERSION', '1.0.0');
@set_time_limit(180);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
header('X-OzyConnect-Connector: ' . OZYCONNECT_VERSION);

function ozy_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Token doğrulama (header veya ?token=) ───────────────────────────────
$reqToken = isset($_SERVER['HTTP_X_OZYCONNECT_TOKEN'])
    ? (string) $_SERVER['HTTP_X_OZYCONNECT_TOKEN']
    : (string) ($_GET['token'] ?? '');

if (OZYCONNECT_TOKEN === 'BURAYA-PANEL-TOKENINI-YAPISTIRIN') {
    ozy_out(['success' => false, 'error' => 'Connector token ayarlanmamış. ozyconnect.php içindeki OZYCONNECT_TOKEN değerini panelden aldığınız token ile değiştirin.'], 500);
}
if ($reqToken === '' || !hash_equals(OZYCONNECT_TOKEN, $reqToken)) {
    ozy_out(['success' => false, 'error' => 'Yetkisiz erişim (geçersiz token).'], 401);
}

// ── OpenCart config.php (DB bilgileri) ──────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    ozy_out(['success' => false, 'error' => 'config.php bulunamadı. ozyconnect.php OpenCart KÖK dizinine (config.php yanına) konmalı.'], 500);
}
require $configPath;
if (!defined('DB_HOSTNAME') || !defined('DB_DATABASE') || !defined('DB_USERNAME')) {
    ozy_out(['success' => false, 'error' => 'OpenCart config.php geçersiz (DB sabitleri yok).'], 500);
}

$port   = (defined('DB_PORT') && DB_PORT) ? (int) DB_PORT : 3306;
$mysqli = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, $port);
if ($mysqli->connect_errno) {
    ozy_out(['success' => false, 'error' => 'Veritabanı bağlantı hatası: ' . $mysqli->connect_error], 500);
}
@$mysqli->set_charset('utf8mb4');
$P = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

// ── Yardımcılar ─────────────────────────────────────────────────────────
function ozy_q(mysqli $db, string $sql)
{
    $r = $db->query($sql);
    if ($r === false) {
        ozy_out(['success' => false, 'error' => 'SQL hatası: ' . $db->error], 500);
    }
    return $r;
}
function ozy_esc(mysqli $db, $v): string { return $db->real_escape_string((string) $v); }
function ozy_setting(mysqli $db, string $P, string $key, $default = '')
{
    $k = $db->real_escape_string($key);
    $r = $db->query("SELECT `value` FROM `{$P}setting` WHERE `key`='{$k}' ORDER BY store_id ASC LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) { return $row['value']; }
    return $default;
}

// Mağaza dili: setting'te config_language (KOD, örn 'tr-tr') tutulur, _id değil → language_id'ye çevir.
// (Türkçe mağazalarda dil id 1 değildir; sabit 1 kullanmak isimleri NULL yapardı.)
$langCode = (string) ozy_setting($mysqli, $P, 'config_language', 'en-gb');
$langId   = 0;
$lc = $mysqli->real_escape_string($langCode);
$lr = $mysqli->query("SELECT language_id FROM `{$P}language` WHERE code = '{$lc}' LIMIT 1");
if ($lr && ($lrow = $lr->fetch_assoc())) { $langId = (int) $lrow['language_id']; }
if ($langId <= 0) {
    $lr = $mysqli->query("SELECT MIN(language_id) AS lid FROM `{$P}language` WHERE status = 1");
    if ($lr && ($lrow = $lr->fetch_assoc())) { $langId = (int) $lrow['lid']; }
}
if ($langId <= 0) { $langId = 1; }

// Mağaza taban URL'si DB'de DEĞİL config.php'de (HTTP_SERVER). Görsel URL'leri buradan kurulur.
$catalogUrl = rtrim((string) (defined('HTTP_SERVER') ? HTTP_SERVER : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '')), '/');
$action     = (string) ($_GET['action'] ?? 'ping');

// ════════════════════════════════════════════════════════════════════════
//  EYLEMLER
// ════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── Bağlantı testi ──────────────────────────────────────────────────
    case 'ping': {
        $name = (string) ozy_setting($mysqli, $P, 'config_name', 'OpenCart');
        $pc = 0; $r = $mysqli->query("SELECT COUNT(*) c FROM `{$P}product`");          if ($r && $x = $r->fetch_assoc()) { $pc = (int) $x['c']; }
        $oc = 0; $r = $mysqli->query("SELECT COUNT(*) c FROM `{$P}order` WHERE order_status_id > 0"); if ($r && $x = $r->fetch_assoc()) { $oc = (int) $x['c']; }
        ozy_out(['success' => true, 'connector_version' => OZYCONNECT_VERSION, 'store' => [
            'name' => $name, 'products' => $pc, 'orders' => $oc, 'prefix' => $P,
        ]]);
    }

    // ── Ürünler (sayfalı) ───────────────────────────────────────────────
    case 'products': {
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(250, max(1, (int) ($_GET['limit'] ?? 100)));
        $off   = ($page - 1) * $limit;
        $sql = "SELECT p.product_id, p.model, p.sku, p.upc, p.ean, p.price, p.quantity, p.status, p.image, p.manufacturer_id,
                       pd.name AS name, m.name AS manufacturer
                FROM `{$P}product` p
                LEFT JOIN `{$P}product_description` pd ON (pd.product_id = p.product_id AND pd.language_id = {$langId})
                LEFT JOIN `{$P}manufacturer` m ON (m.manufacturer_id = p.manufacturer_id)
                ORDER BY p.product_id ASC LIMIT {$off}, {$limit}";
        $r = ozy_q($mysqli, $sql);
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $pid = (int) $row['product_id'];
            $catId = 0; $catName = '';
            $cr = $mysqli->query("SELECT ptc.category_id, cd.name FROM `{$P}product_to_category` ptc
                                  LEFT JOIN `{$P}category_description` cd ON (cd.category_id = ptc.category_id AND cd.language_id = {$langId})
                                  WHERE ptc.product_id = {$pid} ORDER BY ptc.category_id DESC LIMIT 1");
            if ($cr && ($c = $cr->fetch_assoc())) { $catId = (int) $c['category_id']; $catName = (string) $c['name']; }
            $rows[] = [
                'product_id'      => $pid,
                'model'           => (string) $row['model'],
                'sku'             => (string) $row['sku'],
                'barcode'         => (string) ($row['ean'] ?: $row['upc'] ?: $row['sku']),
                'name'            => (string) $row['name'],
                'price'           => (float) $row['price'],
                'quantity'        => (int) $row['quantity'],
                'status'          => (int) $row['status'],
                'manufacturer'    => (string) $row['manufacturer'],
                'manufacturer_id' => (int) $row['manufacturer_id'],
                'category'        => $catName,
                'category_id'     => $catId,
                'image'           => $row['image'] ? ($catalogUrl . '/image/' . $row['image']) : '',
            ];
        }
        $total = 0; $tr = $mysqli->query("SELECT COUNT(*) c FROM `{$P}product`"); if ($tr && $t = $tr->fetch_assoc()) { $total = (int) $t['c']; }
        ozy_out(['success' => true, 'data' => $rows, 'page' => $page, 'limit' => $limit, 'total' => $total]);
    }

    // ── Kategoriler ─────────────────────────────────────────────────────
    case 'categories': {
        $r = ozy_q($mysqli, "SELECT c.category_id, c.parent_id, cd.name FROM `{$P}category` c
                             LEFT JOIN `{$P}category_description` cd ON (cd.category_id = c.category_id AND cd.language_id = {$langId})
                             ORDER BY c.parent_id, c.category_id");
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $rows[] = ['category_id' => (int) $row['category_id'], 'parent_id' => (int) $row['parent_id'], 'name' => (string) $row['name']];
        }
        ozy_out(['success' => true, 'data' => $rows]);
    }

    // ── Üreticiler (markalar) ───────────────────────────────────────────
    case 'manufacturers': {
        $r = ozy_q($mysqli, "SELECT manufacturer_id, name FROM `{$P}manufacturer` ORDER BY name");
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $rows[] = ['manufacturer_id' => (int) $row['manufacturer_id'], 'name' => (string) $row['name']];
        }
        ozy_out(['success' => true, 'data' => $rows]);
    }

    // ── Siparişler (sayfalı, ?after=YYYY-MM-DD opsiyonel) ───────────────
    case 'orders': {
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
        $off   = ($page - 1) * $limit;
        $where = "o.order_status_id > 0";
        if (!empty($_GET['order_id'])) { $where .= " AND o.order_id = " . (int) $_GET['order_id']; } // tekil sipariş (webhook anlık akışı)
        if (!empty($_GET['after'])) { $where .= " AND o.date_added >= '" . ozy_esc($mysqli, $_GET['after']) . "'"; }
        $sql = "SELECT o.*, os.name AS status_name
                FROM `{$P}order` o
                LEFT JOIN `{$P}order_status` os ON (os.order_status_id = o.order_status_id AND os.language_id = {$langId})
                WHERE {$where} ORDER BY o.order_id ASC LIMIT {$off}, {$limit}";
        $r = ozy_q($mysqli, $sql);
        $rows = [];
        while ($o = $r->fetch_assoc()) {
            $oid = (int) $o['order_id'];
            $items = [];
            $ir = $mysqli->query("SELECT op.*, p.sku, p.ean, p.upc FROM `{$P}order_product` op
                                  LEFT JOIN `{$P}product` p ON (p.product_id = op.product_id)
                                  WHERE op.order_id = {$oid}");
            if ($ir) {
                while ($it = $ir->fetch_assoc()) {
                    $items[] = [
                        'product_id' => (int) $it['product_id'],
                        'name'       => (string) $it['name'],
                        'model'      => (string) $it['model'],
                        'sku'        => (string) ($it['sku'] ?? ''),
                        'barcode'    => (string) ($it['ean'] ?: $it['upc'] ?: $it['sku'] ?: ''),
                        'quantity'   => (int) $it['quantity'],
                        'price'      => (float) $it['price'],
                        'total'      => (float) $it['total'],
                    ];
                }
            }
            $rows[] = [
                'order_id'        => $oid,
                'order_status_id' => (int) $o['order_status_id'],
                'status_name'     => (string) ($o['status_name'] ?? ''),
                'date_added'      => (string) $o['date_added'],
                'currency_code'   => (string) $o['currency_code'],
                'currency_value'  => (float) $o['currency_value'],
                'total'           => (float) $o['total'],
                'firstname'       => (string) $o['firstname'],
                'lastname'        => (string) $o['lastname'],
                'email'           => (string) $o['email'],
                'telephone'       => (string) $o['telephone'],
                'comment'         => (string) $o['comment'],
                'payment'  => [
                    'firstname' => (string) $o['payment_firstname'], 'lastname' => (string) $o['payment_lastname'],
                    'company' => (string) $o['payment_company'], 'address_1' => (string) $o['payment_address_1'],
                    'address_2' => (string) $o['payment_address_2'], 'city' => (string) $o['payment_city'],
                    'zone' => (string) $o['payment_zone'], 'postcode' => (string) $o['payment_postcode'],
                    'country' => (string) $o['payment_country'],
                ],
                'shipping' => [
                    'firstname' => (string) $o['shipping_firstname'], 'lastname' => (string) $o['shipping_lastname'],
                    'company' => (string) $o['shipping_company'], 'address_1' => (string) $o['shipping_address_1'],
                    'address_2' => (string) $o['shipping_address_2'], 'city' => (string) $o['shipping_city'],
                    'zone' => (string) $o['shipping_zone'], 'postcode' => (string) $o['shipping_postcode'],
                    'country' => (string) $o['shipping_country'],
                ],
                'products' => $items,
            ];
        }
        ozy_out(['success' => true, 'data' => $rows, 'page' => $page, 'limit' => $limit]);
    }

    // ── Sipariş durumu güncelle (POST: order_id, status_id, comment?) ────
    case 'order_update': {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $oid      = (int) ($body['order_id'] ?? 0);
        $statusId = (int) ($body['status_id'] ?? 0);
        $comment  = ozy_esc($mysqli, $body['comment'] ?? 'OzyConnect senkronizasyonu');
        if ($oid <= 0 || $statusId <= 0) { ozy_out(['success' => false, 'error' => 'order_id ve status_id gerekli'], 400); }
        ozy_q($mysqli, "UPDATE `{$P}order` SET order_status_id = {$statusId}, date_modified = NOW() WHERE order_id = {$oid}");
        ozy_q($mysqli, "INSERT INTO `{$P}order_history` (order_id, order_status_id, notify, comment, date_added)
                        VALUES ({$oid}, {$statusId}, 0, '{$comment}', NOW())");
        ozy_out(['success' => true, 'order_id' => $oid, 'status_id' => $statusId]);
    }

    // ── Stok güncelle (POST: items=[{sku|barcode|model, quantity}]) ──────
    case 'stock_update': {
        $body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $items = $body['items'] ?? [];
        $ok = 0; $fail = 0; $errors = [];
        foreach ($items as $it) {
            $idv = ozy_esc($mysqli, $it['sku'] ?? $it['barcode'] ?? $it['model'] ?? '');
            $qty = (int) ($it['quantity'] ?? 0);
            if ($idv === '') { $fail++; $errors[] = 'sku/model boş'; continue; }
            // Tek tanımlayıcı; OpenCart'ta sku boş olabildiği için model ile DE eşle
            $res = $mysqli->query("UPDATE `{$P}product` SET quantity = {$qty}, date_modified = NOW() WHERE sku = '{$idv}' OR model = '{$idv}'");
            if ($res !== false && $mysqli->affected_rows > 0) { $ok++; } else { $fail++; $errors[] = 'eşleşmedi: ' . $idv; }
        }
        ozy_out(['success' => true, 'updated' => $ok, 'failed' => $fail, 'errors' => $errors]);
    }

    // ── Fiyat güncelle (POST: items=[{sku|barcode|model, price}]) ────────
    case 'price_update': {
        $body  = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $items = $body['items'] ?? [];
        $ok = 0; $fail = 0; $errors = [];
        foreach ($items as $it) {
            $idv   = ozy_esc($mysqli, $it['sku'] ?? $it['barcode'] ?? $it['model'] ?? '');
            $price = (float) ($it['price'] ?? 0);
            if ($idv === '' || $price <= 0) { $fail++; $errors[] = 'sku/model boş veya fiyat 0'; continue; }
            $pVal  = sprintf('%.4f', $price);
            $res   = $mysqli->query("UPDATE `{$P}product` SET price = {$pVal}, date_modified = NOW() WHERE sku = '{$idv}' OR model = '{$idv}'");
            if ($res !== false && $mysqli->affected_rows > 0) { $ok++; } else { $fail++; $errors[] = 'eşleşmedi: ' . $idv; }
        }
        ozy_out(['success' => true, 'updated' => $ok, 'failed' => $fail, 'errors' => $errors]);
    }

    // ── Anlık sipariş bildirimi: OpenCart event kaydı kur (POST: webhook_url, oc_version) ──
    case 'install_event': {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $wurl = trim((string) ($body['webhook_url'] ?? ''));
        $ocv  = (int) ($body['oc_version'] ?? 3);
        if ($wurl === '' || !preg_match('#^https?://#i', $wurl)) {
            ozy_out(['success' => false, 'error' => 'Geçerli webhook_url gerekli'], 400);
        }
        $wesc = ozy_esc($mysqli, $wurl);
        // Webhook URL'sini setting'e yaz (event handler buradan okur)
        $mysqli->query("DELETE FROM `{$P}setting` WHERE `key` = 'ozyconnect_webhook_url'");
        ozy_q($mysqli, "INSERT INTO `{$P}setting` (store_id, code, `key`, value, serialized)
                        VALUES (0, 'ozyconnect', 'ozyconnect_webhook_url', '{$wesc}', 0)");
        // Sürüme göre event trigger/action (OC4: nokta + addHistory; OC2.3/3: slash + addOrderHistory)
        if ($ocv >= 4) {
            $trigger = 'catalog/model/checkout/order.addHistory/after';
            $actn    = 'extension/ozyconnect/event.order';
        } else {
            $trigger = 'catalog/model/checkout/order/addOrderHistory/after';
            $actn    = 'extension/ozyconnect_event/order';
        }
        $mysqli->query("DELETE FROM `{$P}event` WHERE code = 'ozyconnect_notify'");
        $hasDesc = false;
        $cr = $mysqli->query("SHOW COLUMNS FROM `{$P}event` LIKE 'description'");
        if ($cr && $cr->num_rows > 0) { $hasDesc = true; }
        if ($hasDesc) {
            ozy_q($mysqli, "INSERT INTO `{$P}event` (code, description, `trigger`, action, status, sort_order)
                            VALUES ('ozyconnect_notify', 'OzyConnect anlık sipariş bildirimi', '{$trigger}', '{$actn}', 1, 0)");
        } else {
            ozy_q($mysqli, "INSERT INTO `{$P}event` (code, `trigger`, action, status, sort_order)
                            VALUES ('ozyconnect_notify', '{$trigger}', '{$actn}', 1, 0)");
        }
        ozy_out(['success' => true, 'installed' => true, 'oc_version' => $ocv, 'trigger' => $trigger, 'action' => $actn]);
    }

    // ── Anlık sipariş bildirimini kaldır ──
    case 'uninstall_event': {
        $mysqli->query("DELETE FROM `{$P}event` WHERE code = 'ozyconnect_notify'");
        $mysqli->query("DELETE FROM `{$P}setting` WHERE `key` = 'ozyconnect_webhook_url'");
        ozy_out(['success' => true, 'installed' => false]);
    }

    // ── Anlık bildirim event'i kurulu mu? ──
    case 'event_status': {
        $n = 0;
        $r = $mysqli->query("SELECT COUNT(*) c FROM `{$P}event` WHERE code = 'ozyconnect_notify' AND status = 1");
        if ($r && $x = $r->fetch_assoc()) { $n = (int) $x['c']; }
        ozy_out(['success' => true, 'installed' => $n > 0]);
    }

    default:
        ozy_out(['success' => false, 'error' => 'Bilinmeyen action: ' . $action], 400);
}
