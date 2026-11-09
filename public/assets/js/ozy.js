/* OzyConnect SaaS — chrome JS (tema, command palette, drawer). Eski app.js (Toast vb.) korunur. */
(function () {
  'use strict';

  // ---- Tema (dark/light), kalici ----
  try { if (localStorage.getItem('ozy-theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}
  window.ozyToggleTheme = function () {
    const on = document.documentElement.classList.toggle('dark');
    try { localStorage.setItem('ozy-theme', on ? 'dark' : 'light'); } catch (e) {}
  };

  // ---- Command Palette (⌘K / Ctrl+K) ----
  window.ozyOpenCmdk = function () {
    const m = document.getElementById('ozyCmdk'); if (!m) return;
    m.hidden = false;
    const i = document.getElementById('ozyCmdkInput');
    if (i) setTimeout(function () { i.focus(); }, 20);
  };
  window.ozyCloseCmdk = function () {
    const m = document.getElementById('ozyCmdk'); if (!m) return;
    m.hidden = true;
    const i = document.getElementById('ozyCmdkInput'); if (i) { i.value = ''; ozyFilterCmd(''); }
  };
  // Türkçe duyarsız karşılaştırma: "iade" = "İadeler", "siparis" = "Siparişler",
  // "KATEGORİ" = "Kategori". PHP mb_strtolower('İ') "i̇" (i + birleşik nokta) üretir;
  // NFD + işaret silme bunu da düz "i"ye indirir.
  function ozyNorm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/ı/g, 'i').replace(/\s+/g, ' ').trim();
  }
  window.ozyNorm = ozyNorm;

  var cmdActive = -1;
  function cmdVisible() {
    return Array.prototype.filter.call(document.querySelectorAll('#ozyCmdkList .ozy-cmd, #ozyCmdkList .ozy-cmd-search'),
      function (a) { return a.style.display !== 'none' && !a.classList.contains('hidden'); });
  }
  function cmdSetActive(idx) {
    var items = cmdVisible();
    items.forEach(function (a) { a.classList.remove('ozy-cmd-active'); });
    if (!items.length) { cmdActive = -1; return; }
    cmdActive = Math.max(0, Math.min(idx, items.length - 1));
    items[cmdActive].classList.add('ozy-cmd-active');
    if (items[cmdActive].scrollIntoView) items[cmdActive].scrollIntoView({ block: 'nearest' });
  }
  // Menüde izinli sayfa varsa o sayfada arama kısayolu ekle (yetkisiz modül çıkmaz).
  function cmdHasPage(path) {
    return Array.prototype.some.call(document.querySelectorAll('#ozyCmdkList .ozy-cmd'), function (a) {
      try { return new URL(a.getAttribute('href'), location.href).pathname.replace(/\/+$/, '').endsWith(path); } catch (e) { return false; }
    });
  }
  function cmdSearchLinks(raw) {
    var list = document.getElementById('ozyCmdkList'); if (!list) return;
    list.querySelectorAll('.ozy-cmd-search').forEach(function (el) { el.remove(); });
    var q = String(raw || '').trim(); if (!q) return;
    var targets = [['/products', 'Ürünlerde ara'], ['/orders', 'Siparişlerde ara']];
    var empty = list.querySelector('.ozy-cmd-empty');
    targets.forEach(function (t) {
      if (!cmdHasPage(t[0])) return;
      var base = '';
      Array.prototype.some.call(list.querySelectorAll('.ozy-cmd'), function (a) {
        var u = new URL(a.getAttribute('href'), location.href);
        if (u.pathname.replace(/\/+$/, '').endsWith(t[0])) { base = u.pathname; return true; } return false;
      });
      var a = document.createElement('a');
      a.className = 'ozy-cmd-search t flex items-center gap-3 px-3 h-11 rounded-xl hover:bg-brand/[.08] text-[13.5px]';
      a.href = base + '?search=' + encodeURIComponent(q);
      var ic = document.createElement('span');
      ic.className = 'grid place-items-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-white/10 text-slate-500';
      ic.textContent = '⌕';
      var tx = document.createElement('span');
      tx.textContent = t[1] + ': “' + q + '”';
      a.appendChild(ic); a.appendChild(tx);
      list.insertBefore(a, empty || null);
    });
  }
  window.ozyFilterCmd = function (q) {
    var raw = q || '';
    q = ozyNorm(raw);
    var shown = 0;
    document.querySelectorAll('#ozyCmdkList .ozy-cmd').forEach(function (b) {
      const hay = ozyNorm((b.dataset.k || '') + ' ' + b.textContent);
      const ok = (!q || hay.indexOf(q) !== -1);
      b.style.display = ok ? 'flex' : 'none';
      b.classList.toggle('hidden', !ok);
      if (ok) shown++;
    });
    cmdSearchLinks(raw);
    const extra = document.querySelectorAll('#ozyCmdkList .ozy-cmd-search').length;
    const empty = document.querySelector('#ozyCmdkList .ozy-cmd-empty');
    if (empty) { empty.style.display = (shown + extra) ? 'none' : 'block'; empty.classList.toggle('hidden', (shown + extra) > 0); }
    cmdSetActive(0);
  };

  // ---- Generic drawer (sağdan kayan panel): data-ozy-drawer-open="ID" ----
  window.ozyOpenDrawer = function (id) {
    const p = document.getElementById(id); if (!p) return;
    p.classList.remove('translate-x-full');
    const bd = document.getElementById(id + 'Bd'); if (bd) bd.classList.remove('opacity-0', 'pointer-events-none');
  };
  window.ozyCloseDrawer = function (id) {
    const p = document.getElementById(id); if (!p) return;
    p.classList.add('translate-x-full');
    const bd = document.getElementById(id + 'Bd'); if (bd) bd.classList.add('opacity-0', 'pointer-events-none');
  };

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      const m = document.getElementById('ozyCmdk');
      if (m) { m.hidden ? window.ozyOpenCmdk() : window.ozyCloseCmdk(); }
    }
    if (e.key === 'Escape') {
      window.ozyCloseCmdk();
      document.querySelectorAll('[data-ozy-drawer]').forEach(function (d) {
        d.classList.add('translate-x-full');
        const bd = document.getElementById(d.id + 'Bd'); if (bd) bd.classList.add('opacity-0', 'pointer-events-none');
      });
    }
  });

  // ---- init ----
  function init() {
    const inp = document.getElementById('ozyCmdkInput');
    if (inp) {
      inp.addEventListener('input', function (e) { window.ozyFilterCmd(e.target.value); });
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); cmdSetActive(cmdActive + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cmdSetActive(cmdActive - 1); }
        else if (e.key === 'Enter' && !e.isComposing) {
          var items = cmdVisible(); var hit = items[cmdActive] || items[0];
          if (hit) { e.preventDefault(); window.location.href = hit.href; }
        }
      });
    }
    if (!document.getElementById('ozyCmdStyle')) {
      var st = document.createElement('style'); st.id = 'ozyCmdStyle';
      st.textContent = '.ozy-cmd-active{background:rgb(var(--brand,37 99 235)/.10)}';
      document.head.appendChild(st);
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
