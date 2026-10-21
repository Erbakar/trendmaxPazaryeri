<?php
/**
 * OzyConnect — Admin (Modernize) sunum yardımcıları.
 * public/index.php tarafından view render'ından ÖNCE require edilir, böylece
 * hem view'lar (views/admin/*.php) hem layout (views/layouts/admin.php) kullanabilir.
 * Yalnız sunum: iş mantığı yok. url() index.php'de bu dosyadan ÖNCE tanımlı.
 */

if (!function_exists('bti')) {
    /** lucide ikon adı → tabler (ti) ikon adı eşlemesi (Modernize kabuk + view ikonları). */
    function bti(string $n): string
    {
        static $m = [
            // grup ikonları
            'layout-dashboard' => 'layout-dashboard', 'store' => 'building-store', 'settings-2' => 'settings',
            'life-buoy' => 'lifebuoy', 'megaphone' => 'speakerphone', 'shopping-cart' => 'shopping-cart',
            'globe' => 'world', 'shield-check' => 'shield-check',
            // alt-öğe / sayfa ikonları
            'gauge' => 'gauge', 'layers' => 'stack-2', 'cpu' => 'cpu', 'activity' => 'activity',
            'scroll-text' => 'file-text', 'box' => 'box', 'tags' => 'tags', 'refresh-cw' => 'refresh',
            'banknote' => 'cash', 'credit-card' => 'credit-card', 'user-plus' => 'user-plus',
            'badge-check' => 'circle-check', 'grid-3x3' => 'layout-grid', 'monitor' => 'device-desktop',
            'quote' => 'quote', 'newspaper' => 'news', 'help-circle' => 'help', 'inbox' => 'inbox',
            'mail' => 'mail', 'key-round' => 'key', 'database-backup' => 'database', 'scroll' => 'file-text',
            'receipt' => 'receipt', 'file-text' => 'file-text', 'file-check' => 'file-check',
            'shield-lock' => 'shield-lock', 'user-cog' => 'user-cog', 'user-shield' => 'user-shield',
            // çeşitli
            'home' => 'home', 'user' => 'user', 'settings' => 'settings', 'folder' => 'folder',
            'tool' => 'tool', 'message-square' => 'message', 'files' => 'files', 'wrench' => 'tool',
            'hourglass' => 'hourglass', 'clock' => 'clock', 'alert-octagon' => 'alert-octagon',
            'terminal' => 'terminal-2', 'check-check' => 'checks', 'archive' => 'archive', 'ticket' => 'ticket',
            'search' => 'search', 'plus' => 'plus', 'pencil' => 'pencil', 'edit' => 'edit', 'trash-2' => 'trash',
            'eye' => 'eye', 'download' => 'download', 'upload' => 'upload', 'external-link' => 'external-link',
            'arrow-up-right' => 'arrow-up-right', 'chevron-right' => 'chevron-right', 'filter' => 'filter',
            'shield-alert' => 'shield-x', 'refresh-cw-off' => 'refresh-off', 'wallet' => 'wallet',
            'package' => 'package', 'image' => 'photo', 'star' => 'star', 'check-circle-2' => 'circle-check',
            'x-circle' => 'circle-x', 'alert-circle' => 'alert-circle', 'alert-triangle' => 'alert-triangle',
            'users' => 'users', 'building-2' => 'building', 'cpu-2' => 'cpu', 'gauge-2' => 'gauge',
        ];
        // Haritada yoksa adı AYNEN geçir (geçerli Tabler ikon adları doğrudan çalışsın);
        // 'point' fallback'i geçerli isimleri de noktaya çeviriyordu (bell/palette/building-store vb.).
        return $m[$n] ?? $n;
    }
}

if (!function_exists('social_svg')) {
    /**
     * Sosyal medya marka logosu (inline SVG, currentColor). lucide/tabler marka ikonları
     * bazı sürümlerde olmadığından (boş kutu) güvenilir inline SVG kullanılır.
     * Anahtarlar: instagram · x · linkedin · facebook · youtube.
     */
    function social_svg(string $key, int $size = 18): string
    {
        static $p = [
            'instagram' => 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.43.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.43.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.43-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.43-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.38C1.35 2.68.94 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13.67.67 1.34 1.08 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.9 5.9 0 0 0 2.13-1.38 5.9 5.9 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.9 5.9 0 0 0-1.38-2.13A5.9 5.9 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.41-10.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z',
            'x'         => 'M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.68l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23zm-1.16 17.52h1.83L7.08 4.13H5.12l11.96 15.64z',
            'linkedin'  => 'M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zm1.78 13.02H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45C23.2 24 24 23.23 24 22.27V1.73C24 .77 23.2 0 22.22 0z',
            'facebook'  => 'M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 5.99 4.39 10.95 10.13 11.85v-8.38H7.08v-3.47h3.05V9.43c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87v2.25h3.33l-.53 3.47h-2.8v8.38C19.61 23.02 24 18.06 24 12.07z',
            'youtube'   => 'M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81zM9.55 15.57V8.43L15.82 12l-6.27 3.57z',
        ];
        $d = $p[$key] ?? null;
        if ($d === null) {
            return '';
        }
        return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
    }
}

if (!function_exists('admin_auth_logo')) {
    /**
     * Admin auth (login/2fa/şifre) sol marka logosu: yüklü logo (brand logo_url) varsa onu,
     * yoksa marka rengi kutuda 'O' baş harfini döndürür. px=kutu boyutu, radius, textPx='O' font.
     */
    function admin_auth_logo(int $px = 40, int $radius = 12, int $textPx = 18): string
    {
        $logo = trim((string) brand('logo_url', ''));
        $box  = 'width:' . $px . 'px;height:' . $px . 'px;border-radius:' . $radius . 'px;';
        if ($logo !== '') {
            return '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="logo" class="shadow-lift shadow-brand/20" style="' . $box . 'object-fit:contain;background:#fff;padding:3px;">';
        }
        return '<span class="grid place-items-center bg-brand text-white font-bold shadow-lift shadow-brand/30" style="' . $box . 'font-size:' . $textPx . 'px;">O</span>';
    }
}

if (!function_exists('boss_page_header')) {
    /**
     * Modernize sayfa başlığı + breadcrumb + (opsiyonel) sağ aksiyon HTML'i.
     * @param string $title
     * @param array<int,array{l:string,u?:string}> $crumbs  son öğe aktif
     * @param string $actions  sağda gösterilecek ham HTML (buton vb.)
     * @param string $subtitle başlık altı opsiyonel açıklama (düz metin, escape edilir)
     */
    function boss_page_header(string $title, array $crumbs = [], string $actions = '', string $subtitle = ''): void
    {
        $h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        echo '<div class="boss-page-header">';
        echo '<div class="bph-title">';
        echo '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">';
        echo '<li class="breadcrumb-item"><a href="' . $h(url('/admin')) . '"><i class="ti ti-home"></i></a></li>';
        $n = count($crumbs);
        foreach ($crumbs as $i => $c) {
            $last = ($i === $n - 1);
            if ($last || empty($c['u'])) {
                echo '<li class="breadcrumb-item active" aria-current="page">' . $h($c['l']) . '</li>';
            } else {
                echo '<li class="breadcrumb-item"><a href="' . $h(url($c['u'])) . '">' . $h($c['l']) . '</a></li>';
            }
        }
        echo '</ol></nav>';
        echo '<h1>' . $h($title) . '</h1>';
        if ($subtitle !== '') { echo '<p class="bph-subtitle">' . $h($subtitle) . '</p>'; }
        echo '</div>';
        if ($actions !== '') { echo '<div class="bph-actions">' . $actions . '</div>'; }
        echo '</div>';
    }
}

if (!function_exists('boss_status_badge')) {
    /** Aktif/Pasif rozeti (Modernize subtle badge). */
    function boss_status_badge($durum, string $onLabel = 'Aktif', string $offLabel = 'Pasif'): string
    {
        $on  = ($durum == '1' || $durum === 1 || $durum === true);
        $cls = $on ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
        $txt = $on ? $onLabel : $offLabel;
        return '<span class="badge ' . $cls . ' fw-medium">' . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('boss_switch')) {
    /**
     * Şık "Aktif/Pasif" toggle (Lumina .ozy-switch). Checkbox semantiği korunur:
     * işaretliyse name=value POST edilir, değilse hiç gönderilmez (controller'lar değişmez).
     * Durum kelimesi (Aktif/Pasif veya on/off) canlı güncellenir (admin layout JS).
     *
     * @param array{id?:string,value?:string,title?:string,label?:string,on?:string,off?:string,attrs?:string} $opts
     *   title  → toggle'ın solundaki kısa açıklama (opsiyonel; geriye-uyum: 'label' de kabul)
     *   on/off → işaretli/işaretsiz durum kelimesi (varsayılan Aktif/Pasif)
     */
    function boss_switch(string $name, bool $checked = false, array $opts = []): string
    {
        $h     = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $id    = $opts['id']    ?? preg_replace('/[^a-z0-9_]/i', '_', $name);
        $value = $opts['value'] ?? '1';
        $title = $opts['title'] ?? ($opts['label'] ?? '');
        $on    = $opts['on']    ?? 'Aktif';
        $off   = $opts['off']   ?? 'Pasif';
        $attrs = $opts['attrs'] ?? '';
        $chk   = $checked ? ' checked' : '';
        $state = $checked ? $on : $off;

        $titleHtml = $title !== ''
            ? '<label class="ozy-switch-title" for="' . $h($id) . '">' . $h($title) . '</label>'
            : '';

        return '<div class="ozy-switch-field">'
             . $titleHtml
             . '<label class="ozy-switch">'
             . '<input class="ozy-switch-input" type="checkbox" role="switch" id="' . $h($id) . '"'
             . ' name="' . $h($name) . '" value="' . $h($value) . '"'
             . ' data-on="' . $h($on) . '" data-off="' . $h($off) . '"' . $chk . ($attrs !== '' ? ' ' . $attrs : '') . '>'
             . '<span class="ozy-switch-track"><span class="ozy-switch-thumb"></span></span>'
             . '</label>'
             . '<span class="ozy-switch-state' . ($checked ? ' on' : '') . '">' . $h($state) . '</span>'
             . '</div>';
    }
}

if (!function_exists('boss_icon_picker')) {
    /**
     * Görsel Lucide ikon seçici (ad yazmak yerine ızgaradan seç).
     * Gizli input ($name) seçili ikon adını tutar; tetik butonu önizleme + ad gösterir;
     * açılır panelde aranabilir ikon ızgarası + "manuel ad" alanı. Lucide admin layout'ta yüklü.
     * JS + CSS tek seferlik (ozy-admin.css '.ozy-iconpicker'). Birden çok kullanılabilir.
     *
     * @param array{placeholder?:string,size?:int} $opts
     */
    function boss_icon_picker(string $name, string $value = 'box', array $opts = []): string
    {
        static $seq = 0; static $jsOut = false;
        $seq++;
        $h   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $val = preg_replace('/[^a-z0-9\-]/', '', strtolower($value)) ?: 'box';
        $id  = 'ozyip_' . $seq;

        // Küratörlü, geçerli Lucide ikonları (e-ticaret/SaaS odaklı). Listede olmayan için "manuel ad".
        $icons = [
            'box','package','package-2','boxes','shopping-cart','shopping-bag','shopping-basket','store','truck',
            'credit-card','wallet','banknote','receipt','tag','tags','percent','gift','ticket','barcode','qr-code',
            'warehouse','clipboard','clipboard-list','list','list-checks','layout-dashboard','layout-grid','home',
            'building','building-2','briefcase','users','user','user-plus','user-check','contact','mail','mail-open',
            'send','inbox','message-square','message-circle','bell','bell-ring','phone','phone-call','headphones',
            'life-buoy','help-circle','info','settings','settings-2','sliders-horizontal','wrench','cog','key',
            'key-round','lock','lock-open','shield','shield-check','shield-alert','eye','eye-off','search','filter',
            'star','heart','bookmark','flag','calendar','calendar-days','clock','timer','history','refresh-cw',
            'rotate-cw','repeat','upload','download','cloud','cloud-upload','cloud-download','database','server',
            'hard-drive','file','file-text','file-check','files','folder','folder-open','folder-tree','image','images',
            'camera','video','paperclip','link','link-2','external-link','globe','map','map-pin','navigation','compass',
            'plus','plus-circle','minus','x','check','check-check','circle-check','circle-alert','triangle-alert','ban',
            'trash-2','pencil','copy','save','printer','share-2','bar-chart-3','line-chart','pie-chart','trending-up',
            'trending-down','activity','zap','flame','award','trophy','badge-check','crown','gem','palette','brush',
            'layers','component','puzzle','plug','power','wifi','smartphone','monitor','laptop','tablet','hand',
            'thumbs-up','smile','coffee','sun','moon','leaf','sprout','calculator','coins','dollar-sign','euro','hash',
            'at-sign','sparkles','rocket','target','megaphone','music','play','pause','fingerprint','scan-line',
            'archive','network','git-branch','code','terminal','bug','book','book-open','graduation-cap','newspaper',
            'table','menu','move','maximize-2','minimize-2',
        ];
        if (!in_array($val, $icons, true)) { array_unshift($icons, $val); } // mevcut değer listede yoksa ekle

        $items = '';
        foreach ($icons as $ic) {
            $items .= '<button type="button" class="ozy-ip-item' . ($ic === $val ? ' active' : '')
                    . '" data-icon="' . $h($ic) . '" title="' . $h($ic) . '"><i data-lucide="' . $h($ic) . '"></i></button>';
        }

        $out = '<div class="ozy-iconpicker" data-target="' . $id . '">'
             . '<input type="hidden" name="' . $h($name) . '" id="' . $id . '" value="' . $h($val) . '">'
             . '<button type="button" class="ozy-ip-trigger" data-ip-toggle>'
             . '<span class="ozy-ip-cur"><i data-lucide="' . $h($val) . '"></i></span>'
             . '<span class="ozy-ip-name">' . $h($val) . '</span>'
             . '<i data-lucide="chevron-down" class="ozy-ip-caret"></i>'
             . '</button>'
             . '<div class="ozy-ip-panel" hidden>'
             . '<div class="ozy-ip-search"><i data-lucide="search"></i><input type="text" class="ozy-ip-q" placeholder="İkon ara…" autocomplete="off"></div>'
             . '<div class="ozy-ip-grid">' . $items . '</div>'
             . '<div class="ozy-ip-manual"><span>Listede yok mu?</span><input type="text" class="ozy-ip-custom" placeholder="ikon-adı yaz" autocomplete="off"></div>'
             . '</div>'
             . '</div>';

        if (!$jsOut) {
            $jsOut = true;
            $out .= <<<'HTML'
<script>
(function(){
  if (window.__ozyIconPicker) { return; } window.__ozyIconPicker = true;
  function refreshLucide(){ if (window.lucide && window.lucide.createIcons) { try { window.lucide.createIcons(); } catch(e){} } }
  function norm(v){ return (v||'').toLowerCase().replace(/[^a-z0-9\-]/g,''); }
  function close(p){ var pnl = p.querySelector('.ozy-ip-panel'); if(pnl){ pnl.hidden = true; } p.classList.remove('open'); }
  function setIcon(p, name){
    name = norm(name) || 'box';
    var hid = p.querySelector('input[type=hidden]');
    var cur = p.querySelector('.ozy-ip-cur');
    var nm  = p.querySelector('.ozy-ip-name');
    if(hid){ hid.value = name; hid.dispatchEvent(new Event('change', {bubbles:true})); }
    if(cur){ cur.innerHTML = '<i data-lucide="'+name+'"></i>'; }
    if(nm){ nm.textContent = name; }
    p.querySelectorAll('.ozy-ip-item').forEach(function(b){ b.classList.toggle('active', b.dataset.icon === name); });
    refreshLucide();
  }
  document.addEventListener('click', function(e){
    var tgl = e.target.closest('[data-ip-toggle]');
    if (tgl){
      var p = tgl.closest('.ozy-iconpicker'); var pnl = p.querySelector('.ozy-ip-panel');
      var willOpen = pnl.hidden;
      document.querySelectorAll('.ozy-iconpicker').forEach(close);
      if (willOpen){ pnl.hidden = false; p.classList.add('open'); var q = p.querySelector('.ozy-ip-q'); if(q){ q.value=''; q.focus(); } p.querySelectorAll('.ozy-ip-item').forEach(function(b){ b.style.display=''; }); refreshLucide(); }
      return;
    }
    var item = e.target.closest('.ozy-ip-item');
    if (item){ var p2 = item.closest('.ozy-iconpicker'); setIcon(p2, item.dataset.icon); close(p2); return; }
    if (!e.target.closest('.ozy-iconpicker')){ document.querySelectorAll('.ozy-iconpicker').forEach(close); }
  });
  document.addEventListener('input', function(e){
    if (e.target.classList.contains('ozy-ip-q')){
      var p = e.target.closest('.ozy-iconpicker'); var q = norm(e.target.value);
      p.querySelectorAll('.ozy-ip-item').forEach(function(b){ b.style.display = (!q || b.dataset.icon.indexOf(q) !== -1) ? '' : 'none'; });
    }
  });
  document.addEventListener('keydown', function(e){
    if (e.target.classList.contains('ozy-ip-custom') && e.key === 'Enter'){
      e.preventDefault(); var p = e.target.closest('.ozy-iconpicker'); setIcon(p, e.target.value); close(p);
    }
  });
  document.addEventListener('change', function(e){
    if (e.target.classList.contains('ozy-ip-custom')){ var p = e.target.closest('.ozy-iconpicker'); if(e.target.value.trim()){ setIcon(p, e.target.value); } }
  });
})();
</script>
HTML;
        }
        return $out;
    }
}

if (!function_exists('boss_image_upload')) {
    /**
     * Modern resim yükleme — küçük önizleme (mevcut + seçilen) + tıkla/sürükle-bırak.
     * @param array{accept?:string,required?:bool,hint?:string,id?:string} $opts
     */
    function boss_image_upload(string $name, string $currentUrl = '', array $opts = []): string
    {
        static $seq = 0; static $jsOut = false;
        $seq++;
        $h      = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $accept = $opts['accept'] ?? 'image/png,image/jpeg,image/webp,image/svg+xml';
        $hint   = $opts['hint'] ?? 'PNG, JPG, WEBP veya SVG · ≤5MB';
        $req    = !empty($opts['required']) ? ' required' : '';
        $id     = $opts['id'] ?? ('ozyimg_' . $seq);
        $hasImg = trim($currentUrl) !== '';

        $out = '<div class="ozy-imgup">'
             . '<div class="ozy-imgup-thumb"' . ($hasImg ? '' : ' data-empty') . '>'
             . ($hasImg ? '<img src="' . $h($currentUrl) . '" alt="" onerror="this.remove()">' : '<i class="ti ti-photo"></i>')
             . '</div>'
             . '<label class="ozy-imgup-drop" for="' . $h($id) . '">'
             . '<input type="file" id="' . $h($id) . '" name="' . $h($name) . '" accept="' . $h($accept) . '"' . $req . ' class="ozy-imgup-input">'
             . '<span class="ozy-imgup-cta"><i class="ti ti-upload"></i> Görsel seç <span class="ozy-imgup-or">veya sürükle-bırak</span></span>'
             . '<span class="ozy-imgup-hint">' . $h($hint) . '</span>'
             . '<span class="ozy-imgup-file"></span>'
             . '</label>'
             . '</div>';

        if (!$jsOut) {
            $jsOut = true;
            $out .= <<<'HTML'
<script>
(function(){
  if (window.__ozyImgUp) { return; } window.__ozyImgUp = true;
  function preview(wrap, file){
    if (!wrap || !file || !/^image\//.test(file.type || '')) { return; }
    var thumb = wrap.querySelector('.ozy-imgup-thumb'), nameEl = wrap.querySelector('.ozy-imgup-file');
    if (nameEl) { nameEl.textContent = file.name; }
    var r = new FileReader();
    r.onload = function(ev){ if (thumb){ thumb.innerHTML = '<img src="'+ev.target.result+'" alt="">'; thumb.removeAttribute('data-empty'); } };
    r.readAsDataURL(file);
  }
  document.addEventListener('change', function(e){
    if (!e.target.classList || !e.target.classList.contains('ozy-imgup-input')) { return; }
    if (e.target.files && e.target.files[0]) { preview(e.target.closest('.ozy-imgup'), e.target.files[0]); }
  });
  document.addEventListener('dragover', function(e){ var d=e.target.closest && e.target.closest('.ozy-imgup-drop'); if(d){ e.preventDefault(); d.classList.add('drag'); } });
  document.addEventListener('dragleave', function(e){ var d=e.target.closest && e.target.closest('.ozy-imgup-drop'); if(d){ d.classList.remove('drag'); } });
  document.addEventListener('drop', function(e){
    var d = e.target.closest && e.target.closest('.ozy-imgup-drop'); if (!d) { return; }
    e.preventDefault(); d.classList.remove('drag');
    var inp = d.querySelector('.ozy-imgup-input');
    if (inp && e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
      try { inp.files = e.dataTransfer.files; } catch(_){}
      preview(d.closest('.ozy-imgup'), e.dataTransfer.files[0]);
    }
  });
})();
</script>
HTML;
        }
        return $out;
    }
}

if (!function_exists('admin_perms')) {
    /**
     * Aktif platform admininin yetkileri (request başına bir kez yüklenir, statik cache).
     * Menü görünürlüğü içindir (güvenlik sınırı route enforcement'tadır). Hata → fail-open.
     * @return array<int,string>
     */
    function admin_perms(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $cache = $GLOBALS['container']->get(\App\Services\AdminAuthorizationService::class)->permissions();
        } catch (\Throwable) {
            $cache = ['*']; // menü çizilemezse gizleme; gerçek kapı route enforcement'tır
        }
        return $cache;
    }
}

if (!function_exists('admin_can')) {
    /** Aktif admin verilen yetkiye sahip mi? Boş yetki = herkese açık. */
    function admin_can(string $permission): bool
    {
        if ($permission === '') {
            return true;
        }
        $p = admin_perms();
        return in_array('*', $p, true) || in_array($permission, $p, true);
    }
}
