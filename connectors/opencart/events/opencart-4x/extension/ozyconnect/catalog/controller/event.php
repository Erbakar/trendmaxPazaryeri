<?php
namespace Opencart\Catalog\Controller\Extension\Ozyconnect;

/**
 * OzyConnect — Anlık Sipariş Bildirimi (OpenCart 4.0.x / 4.1.x event handler)
 *
 * KURULUM:
 *   Bu dosyayı (klasör yapısıyla birlikte) şu konuma yükleyin:
 *     extension/ozyconnect/catalog/controller/event.php
 *   Ardından OzyConnect panelinden "Anlık sipariş bildirimini etkinleştir" + OpenCart sürümü = 4.x
 *   seçip kaydedin (event kaydı ve webhook adresi connector tarafından otomatik yazılır).
 *
 * Çalışma: sipariş durumu eklendiğinde (addHistory) tetiklenir; sipariş numarasını OzyConnect
 * paneline (HTTPS, fire-and-forget) bildirir; panel siparişi anında çeker.
 *
 * @author OziyWeb · © 2025 — Proprietary
 */
class Event extends \Opencart\System\Engine\Controller
{
    public function order(string &$route, array &$args, &$output): void
    {
        $orderId = isset($args[0]) ? (int) $args[0] : 0;
        if ($orderId <= 0) {
            return;
        }
        $q = $this->db->query("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `key` = 'ozyconnect_webhook_url' LIMIT 1");
        $url = ($q->num_rows) ? (string) $q->row['value'] : '';
        if (strpos($url, 'http') !== 0) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['order_id' => $orderId]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }
}
