'use strict';
function validateServerUrl(value) {
    const url = new URL(String(value).trim());
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if (url.username || url.password || url.search || url.hash ||
        !(url.protocol === 'https:' || (url.protocol === 'http:' && local))) {
        throw new Error('HTTPS adresi girin. Yerel testte http://127.0.0.1 kullanılabilir.');
    }
    return url.href.replace(/\/$/, '');
}
module.exports = { validateServerUrl };
