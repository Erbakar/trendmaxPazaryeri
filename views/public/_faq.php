<?php
/** Partial — SSS akordeon (ozyconnect faq-item tasarımı, dinamik $faqs + birebir fallback). */
$e = $e ?? fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$faqs = $faqs ?? [];
$fallback = [
    ['Entegratör Yazılımı nasıl çalışır?', 'Entegratör Yazılımı, pazaryeri API\'leri üzerinden sipariş, stok, fiyat, fatura ve kargo işlemlerini otomatik yönetir. Cron görevleri ile 7/24 senkronizasyon sağlar; tüm operasyon tek panelden yürütülür.'],
    ['Hangi pazaryerleri destekleniyor?', 'Trendyol, Hepsiburada, Amazon TR, n11, Pazarama, PttAVM, ÇiçekSepeti, İdefix ve e-ticaret platformları Shopify, WooCommerce, OpenCart — toplamda 11 pazaryeri/platform tam entegrasyon ile desteklenmektedir.'],
    ['Mağazamı nasıl oluşturabilirim?', '"Mağaza Oluştur" formunu doldurun; başvurunuz onaylandığında hesabınız dakikalar içinde hazırlanır ve giriş bilgileriniz e-posta ile iletilir.'],
    ['E-Arşiv Fatura entegrasyonu nasıl çalışıyor?', 'GİB (Gelir İdaresi Başkanlığı) ile uyumlu çalışır. E-Arşiv Fatura belgelerini panelden oluşturabilir, toplu faturalama yapabilir ve PDF gönderebilirsiniz.'],
    ['Deneme süresi var mı?', 'Evet, paketlere bağlı olarak ücretsiz deneme süresi sunulmaktadır. Deneme süresince tüm özellikleri test edebilir, dilediğiniz zaman aboneliğe geçebilirsiniz.'],
    ['Verilerim güvende mi?', 'Tüm API anahtarları AES-256 ile şifrelenir, her mağaza izole (çok-kiracılı) çalışır, verileriniz 3. parti ile paylaşılmaz. CSRF koruması, 2FA ve güvenli oturum yönetimi standarttır.'],
    ['Plan yükseltme yapılabiliyor mu?', 'Evet, istediğiniz zaman üst pakete yükseltebilirsiniz. Mevcut aboneliğinizin kalan değeri yeni plandan düşülür.'],
    ['Güncelleme ve destek nasıl sağlanıyor?', 'Abonelik süresince otomatik güncelleme ve teknik destek sunulmaktadır. Destek talepleri panel içinden ve WhatsApp üzerinden karşılanır.'],
];
$items = [];
if (!empty($faqs)) {
    foreach ($faqs as $f) { $items[] = [(string) ($f['question'] ?? ''), (string) ($f['answer'] ?? '')]; }
} else {
    $items = $fallback;
}
?>
<div class="faq-list">
  <?php foreach ($items as $i => [$q, $a]): ?>
  <div class="faq-item<?= $i === 0 ? ' open' : '' ?>">
    <div class="faq-q"><?= $e($q) ?><span class="faq-icon"><i data-lucide="plus"></i></span></div>
    <div class="faq-a"><p><?= nl2br($e($a)) ?></p></div>
  </div>
  <?php endforeach; ?>
</div>
