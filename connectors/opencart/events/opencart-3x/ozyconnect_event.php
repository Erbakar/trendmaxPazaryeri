<?php
/**
 * OzyConnect — Anlık Sipariş Bildirimi (OpenCart 2.3.x / 3.0.x event handler)
 *
 * KURULUM:
 *   Bu dosyayı şu konuma yükleyin:
 *     catalog/controller/extension/ozyconnect_event.php
 *   Ardından OzyConnect panelinden "Anlık sipariş bildirimini etkinleştir" işaretleyip kaydedin
 *   (event kaydı ve webhook adresi connector tarafından otomatik yazılır).
 *
 * Çalışma: sipariş oluştuğunda/durumu değiştiğinde (addOrderHistory) tetiklenir,
 * sipariş numarasını OzyConnect paneline (HTTPS, fire-and-forget) bildirir; panel siparişi anında çeker.
 *
 * @author OziyWeb · © 2025 — Proprietary
 */
class ControllerExtensionOzyconnectEvent extends Controller
{
    public function order($route, $args)
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
