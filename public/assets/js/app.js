/**
 * OzyConnect Panel — UI Engine
 * © 2025 OziyWeb — Tüm Hakları Saklıdır
 * Theme toggle, sidebar, toast, AJAX, Chart.js
 */

(function () {
    'use strict';

    const AppPath = {
        base: (document.querySelector('meta[name="app-base-path"]')?.getAttribute('content') || '').replace(/\/+$/, ''),
        url(path) {
            path = '/' + String(path || '').replace(/^\/+/, '');
            return this.base + path;
        }
    };

    // ─── THEME SYSTEM ─────────────────────────────────────
    const ThemeManager = {
        init() {
            const saved = localStorage.getItem('OzyConnect-theme') || 'dark';
            this.apply(saved);

            const toggle = document.getElementById('themeToggle');
            if (toggle) {
                toggle.addEventListener('click', () => {
                    const current = document.documentElement.getAttribute('data-theme');
                    const next = current === 'dark' ? 'light' : 'dark';
                    this.apply(next);
                    localStorage.setItem('OzyConnect-theme', next);
                });
            }
        },
        apply(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            // Chart.js tema renkleri
            if (typeof Chart !== 'undefined') {
                Chart.defaults.color = theme === 'dark' ? '#94a3b8' : '#64748b';
                Chart.defaults.borderColor = theme === 'dark' ? '#262a3a' : '#e2e8f0';
            }
        }
    };

    // ─── SIDEBAR ──────────────────────────────────────────
    const SidebarManager = {
        init() {
            const toggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (toggle && sidebar) {
                toggle.addEventListener('click', () => {
                    sidebar.classList.toggle('open');
                    overlay?.classList.toggle('show');
                });
            }

            if (overlay) {
                overlay.addEventListener('click', () => {
                    sidebar?.classList.remove('open');
                    overlay.classList.remove('show');
                });
            }
        }
    };

    // ─── TOAST SYSTEM ─────────────────────────────────────
    const Toast = {
        show(message, type = 'success', duration = 4000) {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<span>${icons[type] || ''}</span><span>${message}</span>`;
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(40px)';
                toast.style.transition = 'all .3s ease';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
    };

    window.Toast = Toast;

    // ─── FLASH MESSAGES (auto dismiss) ────────────────────
    document.querySelectorAll('[data-dismiss]').forEach(el => {
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            el.style.transition = 'all .3s ease';
            setTimeout(() => el.remove(), 300);
        }, 5000);
    });

    // ─── AJAX FORM HANDLER ────────────────────────────────
    document.querySelectorAll('form[data-ajax]').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn?.textContent;
            if (btn) { btn.disabled = true; btn.textContent = 'İşleniyor...'; }

            try {
                const fd = new FormData(form);
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                if (csrf) fd.append('_csrf_token', csrf);

                const res = await fetch(form.action, { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    Toast.show(data.message || 'İşlem başarılı', 'success');
                    if (data.redirect) setTimeout(() => window.location.href = data.redirect, 800);
                } else {
                    Toast.show(data.message || 'Bir hata oluştu', 'error');
                }
            } catch (err) {
                Toast.show('Bağlantı hatası: ' + err.message, 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = originalText; }
            }
        });
    });

    // ─── COPY TO CLIPBOARD ────────────────────────────────
    document.querySelectorAll('[data-copy]').forEach(btn => {
        btn.addEventListener('click', () => {
            const text = btn.dataset.copy;
            navigator.clipboard.writeText(text).then(() => Toast.show('Kopyalandı!', 'success', 2000));
        });
    });

    // ─── CONFIRM DELETE ───────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm || 'Emin misiniz?')) {
                e.preventDefault();
            }
        });
    });

    // ─── CONNECTION TEST ─────────────────────────────────
    document.querySelectorAll('[data-test-connection]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const url = btn.dataset.testConnection;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '⏳ Test ediliyor...';

            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf || ''
                    }
                });
                const data = await res.json();

                if (data.success) {
                    Toast.show(data.message || 'Bağlantı başarılı!', 'success');
                } else {
                    Toast.show(data.message || 'Bağlantı başarısız', 'error', 6000);
                }
            } catch (err) {
                Toast.show('İstek hatası: ' + err.message, 'error', 6000);
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
    });

    // ─── TABLE ROW CLICK ──────────────────────────────────
    document.querySelectorAll('tr[data-href]').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', (e) => {
            if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a, button')) return;
            window.location.href = row.dataset.href;
        });
    });

    // ─── DASHBOARD CHART ──────────────────────────────────
    const chartEl = document.getElementById('orderChart');
    if (chartEl && typeof Chart !== 'undefined') {
        const theme = document.documentElement.getAttribute('data-theme');
        const gridColor = theme === 'dark' ? '#262a3a' : '#e2e8f0';

        try {
            const labels = JSON.parse(chartEl.dataset.labels || '[]');
            const orders = JSON.parse(chartEl.dataset.orders || '[]');
            const revenue = JSON.parse(chartEl.dataset.revenue || '[]');

            new Chart(chartEl, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Sipariş',
                            data: orders,
                            backgroundColor: 'rgba(25,103,242,.7)',
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.5,
                        },
                        {
                            label: 'Ciro (₺)',
                            data: revenue,
                            backgroundColor: 'rgba(6,182,212,.5)',
                            borderRadius: 6,
                            borderSkipped: false,
                            barPercentage: 0.5,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10 } } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: gridColor } },
                        x: { grid: { display: false } }
                    },
                    interaction: { mode: 'index', intersect: false }
                }
            });
        } catch (e) {
            console.warn('Chart data parse error', e);
        }
    }

    // ─── NOTIFICATION SOUND SYSTEM ──────────────────────────
    const NotificationSounds = {
        ctx: null,
        getCtx() {
            if (!this.ctx) this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            return this.ctx;
        },
        play(name, volume = 0.7) {
            try {
                const ctx = this.getCtx();
                if (ctx.state === 'suspended') ctx.resume();
                const gen = this.generators[name] || this.generators['ding'];
                gen.call(this, ctx, volume);
            } catch (e) { console.warn('Sound error:', e); }
        },
        generators: {
            ding(ctx, vol) {
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = 'sine'; o.frequency.setValueAtTime(880, ctx.currentTime);
                o.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.15);
                g.gain.setValueAtTime(vol, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
                o.start(ctx.currentTime); o.stop(ctx.currentTime + 0.5);
            },
            chime(ctx, vol) {
                [880, 1108.73, 1318.51].forEach((freq, i) => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.connect(g); g.connect(ctx.destination);
                    o.type = 'sine'; o.frequency.value = freq;
                    const t = ctx.currentTime + i * 0.12;
                    g.gain.setValueAtTime(0, t);
                    g.gain.linearRampToValueAtTime(vol * 0.6, t + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.01, t + 0.4);
                    o.start(t); o.stop(t + 0.4);
                });
            },
            bell(ctx, vol) {
                const o = ctx.createOscillator();
                const o2 = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); o2.connect(g); g.connect(ctx.destination);
                o.type = 'sine'; o.frequency.value = 830;
                o2.type = 'sine'; o2.frequency.value = 1245;
                g.gain.setValueAtTime(vol * 0.5, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.8);
                o.start(ctx.currentTime); o.stop(ctx.currentTime + 0.8);
                o2.start(ctx.currentTime); o2.stop(ctx.currentTime + 0.8);
            },
            cash(ctx, vol) {
                const freqs = [523.25, 659.25, 783.99, 1046.5];
                freqs.forEach((freq, i) => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.connect(g); g.connect(ctx.destination);
                    o.type = 'square';
                    o.frequency.value = freq;
                    const t = ctx.currentTime + i * 0.07;
                    g.gain.setValueAtTime(vol * 0.3, t);
                    g.gain.exponentialRampToValueAtTime(0.01, t + 0.15);
                    o.start(t); o.stop(t + 0.15);
                });
            },
            money(ctx, vol) {
                // Para gelme sesi - MP3 dosyasından
                const audio = new Audio(AppPath.url('/public/assets/sounds/para.mp3'));
                audio.volume = Math.min(1, Math.max(0, vol));
                audio.play().catch(e => console.warn('Para sesi çalınamadı:', e));
            },
            notification(ctx, vol) {
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.type = 'sine';
                o.frequency.setValueAtTime(523.25, ctx.currentTime);
                o.frequency.setValueAtTime(659.25, ctx.currentTime + 0.1);
                o.frequency.setValueAtTime(783.99, ctx.currentTime + 0.2);
                g.gain.setValueAtTime(vol * 0.5, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.45);
                o.start(ctx.currentTime); o.stop(ctx.currentTime + 0.45);
            },
            alert(ctx, vol) {
                [0, 0.2].forEach(offset => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.connect(g); g.connect(ctx.destination);
                    o.type = 'sawtooth';
                    const t = ctx.currentTime + offset;
                    o.frequency.setValueAtTime(880, t);
                    o.frequency.linearRampToValueAtTime(440, t + 0.15);
                    g.gain.setValueAtTime(vol * 0.3, t);
                    g.gain.exponentialRampToValueAtTime(0.01, t + 0.18);
                    o.start(t); o.stop(t + 0.18);
                });
            }
        }
    };

    window.NotificationSounds = NotificationSounds;

    // ─── NOTIFICATION MANAGER (Polling) ──────────────────
    const NotificationManager = {
        interval: 30000,
        lastCheck: null,
        timer: null,
        totalBadge: { orders: 0, returns: 0, questions: 0 },

        getSettings() {
            try {
                return JSON.parse(localStorage.getItem('OzyConnect-notif-settings') || '{}');
            } catch { return {}; }
        },
        saveSettings(s) {
            localStorage.setItem('OzyConnect-notif-settings', JSON.stringify(s));
        },
        getDefaults() {
            return {
                enabled: true,
                volume: 70,
                orders_enabled: true,
                orders_sound: 'money',
                returns_enabled: true,
                returns_sound: 'alert',
                questions_enabled: true,
                questions_sound: 'notification'
            };
        },
        getSetting(key) {
            const s = this.getSettings();
            const d = this.getDefaults();
            return s[key] !== undefined ? s[key] : d[key];
        },

        // Yerel saat (UTC değil!) — server ile aynı timezone
        _localNow() {
            const d = new Date();
            const p = n => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
        },

        init() {
            // localStorage'dan son bilinen server zamanını oku (sayfa değişimlerinde korunur)
            const stored = localStorage.getItem('OzyConnect-notif-lastCheck');
            this.lastCheck = stored || this._localNow();
            this.timer = setInterval(() => this.check(), this.interval);
            // İlk kontrolü 5 sn sonra yap (sayfa yüklenmesini bekle)
            setTimeout(() => this.check(), 5000);
            this.updateBadgeUI();
        },

        async check() {
            if (!this.getSetting('enabled')) return;
            try {
                const res = await fetch(AppPath.url('/notifications/check') + '?since=' + encodeURIComponent(this.lastCheck));
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                const d = data.data || {};
                const vol = (this.getSetting('volume') || 70) / 100;
                let played = false;

                if (d.orders > 0 && this.getSetting('orders_enabled')) {
                    this.totalBadge.orders += d.orders;
                    if (!played) { NotificationSounds.play(this.getSetting('orders_sound'), vol); played = true; }
                    this.showToast(d.orders + ' yeni sipariş geldi!', 'success');
                }
                if (d.returns > 0 && this.getSetting('returns_enabled')) {
                    this.totalBadge.returns += d.returns;
                    if (!played) { NotificationSounds.play(this.getSetting('returns_sound'), vol); played = true; }
                    this.showToast(d.returns + ' yeni iade geldi!', 'warning');
                }
                if (d.questions > 0 && this.getSetting('questions_enabled')) {
                    this.totalBadge.questions += d.questions;
                    if (!played) { NotificationSounds.play(this.getSetting('questions_sound'), vol); played = true; }
                    this.showToast(d.questions + ' yeni soru geldi!', 'info');
                }

                if (d.timestamp) {
                    this.lastCheck = d.timestamp;
                    localStorage.setItem('OzyConnect-notif-lastCheck', d.timestamp);
                }
                this.updateBadgeUI();
            } catch (e) {
                // Polling hatası sessizce geç
            }
        },

        showToast(msg, type) {
            if (window.Toast) Toast.show(msg, type, 5000);
        },

        updateBadgeUI() {
            const total = this.totalBadge.orders + this.totalBadge.returns + this.totalBadge.questions;
            const badge = document.getElementById('notifBadge');
            const countEl = document.getElementById('notifCount');
            const dropdown = document.getElementById('notifDropdown');

            if (badge) {
                badge.style.display = total > 0 ? 'flex' : 'none';
                badge.textContent = total > 99 ? '99+' : total;
            }
            if (countEl) countEl.textContent = total;

            // Dropdown içeriğini güncelle
            const listEl = document.getElementById('notifList');
            if (listEl) {
                let html = '';
                if (this.totalBadge.orders > 0) {
                    html += '<div class="notif-item notif-order"><i data-lucide="package"></i><span>' + this.totalBadge.orders + ' yeni sipariş</span></div>';
                }
                if (this.totalBadge.returns > 0) {
                    html += '<div class="notif-item notif-return"><i data-lucide="rotate-ccw"></i><span>' + this.totalBadge.returns + ' yeni iade</span></div>';
                }
                if (this.totalBadge.questions > 0) {
                    html += '<div class="notif-item notif-question"><i data-lucide="message-circle-question"></i><span>' + this.totalBadge.questions + ' yeni soru</span></div>';
                }
                if (html === '') {
                    html = '<div class="notif-empty">Yeni bildirim yok</div>';
                }
                listEl.innerHTML = html;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        },

        clearAll() {
            this.totalBadge = { orders: 0, returns: 0, questions: 0 };
            // lastCheck server zamanında zaten (son poll'dan), localStorage'a kaydet
            localStorage.setItem('OzyConnect-notif-lastCheck', this.lastCheck);
            this.updateBadgeUI();
        },

        toggleMute() {
            const s = this.getSettings();
            const d = this.getDefaults();
            const current = s.enabled !== undefined ? s.enabled : d.enabled;
            s.enabled = !current;
            this.saveSettings(s);
            this.updateMuteIcon();
            Toast.show(s.enabled ? 'Bildirim sesleri açıldı' : 'Bildirim sesleri kapatıldı', 'info', 2000);
        },

        updateMuteIcon() {
            const btn = document.getElementById('notifMuteBtn');
            if (!btn) return;
            const enabled = this.getSetting('enabled');
            btn.innerHTML = enabled
                ? '<i data-lucide="bell" style="width:14px;height:14px"></i><span>Sesi Kapat</span>'
                : '<i data-lucide="bell-off" style="width:14px;height:14px"></i><span>Sesi Aç</span>';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    };

    window.NotificationManager = NotificationManager;

    // ─── INIT ─────────────────────────────────────────────
    ThemeManager.init();
    SidebarManager.init();
    NotificationManager.init();
    NotificationManager.updateMuteIcon();

    // Bildirim dropdown toggle
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('#notifToggle');
        const dropdown = document.getElementById('notifDropdown');
        if (!dropdown) return;

        if (toggle) {
            e.preventDefault();
            dropdown.classList.toggle('show');
        } else if (!e.target.closest('#notifDropdown')) {
            dropdown.classList.remove('show');
        }
    });

    // "Tümünü Gördüm" butonu
    document.addEventListener('click', (e) => {
        if (e.target.closest('#notifClearAll')) {
            NotificationManager.clearAll();
        }
    });

    // Ses açma/kapatma
    document.addEventListener('click', (e) => {
        if (e.target.closest('#notifMuteBtn')) {
            NotificationManager.toggleMute();
        }
    });

    console.log('%c OzyConnect Panel v1.0 — OziyWeb ', 'background: linear-gradient(135deg, #1967F2, #06b6d4); color: #fff; padding: 8px 16px; border-radius: 6px; font-weight: bold;');

})();
