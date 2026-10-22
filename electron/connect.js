'use strict';
const form = document.getElementById('connectForm');
const input = document.getElementById('serverUrl');
const status = document.getElementById('status');
window.Trendmax.getServerUrl().then(value => { input.value = value || ''; }).catch(() => {});
form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button');
    button.disabled = true;
    status.textContent = 'Bağlanılıyor…';
    try { await window.Trendmax.connect(input.value); }
    catch { status.textContent = 'Bağlantı kurulamadı. Panel adresini ve internet bağlantınızı kontrol edin.'; }
    finally { button.disabled = false; }
});
