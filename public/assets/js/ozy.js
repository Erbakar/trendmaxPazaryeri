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
  window.ozyFilterCmd = function (q) {
    q = (q || '').toLowerCase();
    var shown = 0;
    document.querySelectorAll('#ozyCmdkList .ozy-cmd').forEach(function (b) {
      const hay = ((b.dataset.k || '') + b.textContent).toLowerCase();
      const ok = (!q || hay.indexOf(q) !== -1);
      b.style.display = ok ? 'flex' : 'none';
      if (ok) shown++;
    });
    const empty = document.querySelector('#ozyCmdkList .ozy-cmd-empty');
    if (empty) empty.style.display = shown ? 'none' : 'block';
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
    if (inp) inp.addEventListener('input', function (e) { window.ozyFilterCmd(e.target.value); });
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
