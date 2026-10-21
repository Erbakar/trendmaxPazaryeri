/* ================================================
   OzyConnect API Docs — Küçük yardımcı script
   - Kod bloklarına "Kopyala" butonu ekler
   - Tab geçişlerini yönetir
   - Baskı butonu
   © 2025 OziyWeb
================================================ */

(function () {
    'use strict';

    // --- Kopyala butonu ---
    document.querySelectorAll('pre').forEach(function (pre) {
        if (pre.querySelector('.copy-btn')) return;
        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.type = 'button';
        btn.textContent = 'Kopyala';
        btn.addEventListener('click', function () {
            var code = pre.querySelector('code');
            var text = code ? code.innerText : pre.innerText;
            // Butonun kendi metnini dışla
            text = text.replace(/Kopyala$/, '').replace(/Kopyalandı$/, '').trim();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(onCopied, onCopyFail);
            } else {
                fallbackCopy(text);
            }
        });
        pre.appendChild(btn);

        function onCopied() {
            btn.textContent = 'Kopyalandı';
            btn.classList.add('copied');
            setTimeout(function () {
                btn.textContent = 'Kopyala';
                btn.classList.remove('copied');
            }, 1600);
        }

        function onCopyFail() {
            btn.textContent = 'Hata';
            setTimeout(function () {
                btn.textContent = 'Kopyala';
            }, 1600);
        }

        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                onCopied();
            } catch (e) {
                onCopyFail();
            }
            document.body.removeChild(ta);
        }
    });

    // --- Tab yönetimi ---
    document.querySelectorAll('.tabs').forEach(function (tabs) {
        var buttons = tabs.querySelectorAll('.tabs-nav button');
        var panels = tabs.querySelectorAll('.tabs-panel');

        buttons.forEach(function (btn, idx) {
            btn.addEventListener('click', function () {
                buttons.forEach(function (b) { b.classList.remove('active'); });
                panels.forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                if (panels[idx]) panels[idx].classList.add('active');
            });
        });
    });

    // --- Baskı butonu ---
    var printBtn = document.querySelector('[data-action="print"]');
    if (printBtn) {
        printBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.print();
        });
    }

    // --- Aktif menü öğesi (sayfa bazlı) ---
    var path = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.sidebar-nav a').forEach(function (a) {
        var href = a.getAttribute('href');
        if (href && href === path) a.classList.add('active');
    });
})();
