const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateServerUrl } = require('../electron/server-url');
test('HTTPS and loopback development URLs are accepted', () => {
    assert.equal(validateServerUrl('https://panel.example.com/'), 'https://panel.example.com');
    assert.equal(validateServerUrl('http://127.0.0.1:8080'), 'http://127.0.0.1:8080');
});
test('credentials, scripts, remote HTTP and secret URL parameters are rejected', () => {
    for (const url of ['javascript:alert(1)', 'file:///etc/passwd', 'http://example.com', 'https://user:pass@example.com', 'https://example.com?token=secret', 'https://example.com#token']) {
        assert.throws(() => validateServerUrl(url));
    }
});
