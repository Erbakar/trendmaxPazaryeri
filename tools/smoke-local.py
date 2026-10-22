#!/usr/bin/env python3
"""Read-only local HTTP smoke checks. Never changes products or calls marketplace APIs."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
settings = {}
for line in (ROOT / '.env').read_text().splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        settings[key.strip()] = value.strip().strip('\"\'')
assert settings.get('APP_ENV') == 'development' and settings.get('DB_NAME') == 'trendmax_local', 'Only the local test database is supported.'
BASE = settings.get('APP_URL', '').rstrip('/')
assert BASE == 'http://127.0.0.1:8080', 'Only the loopback development server is supported.'
PASSWORD = os.environ['LOCAL_TEST_PASSWORD']
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0


def request(path, data=None, ajax=False):
    headers = {'X-Requested-With': 'XMLHttpRequest'} if ajax else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    req = urllib.request.Request(BASE + path, body, headers)
    try:
        with client.open(req, timeout=15) as response:
            return response.status, response.url.removeprefix(BASE), response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.url.removeprefix(BASE), error.read().decode()


def verify(condition, label):
    global checks
    assert condition, label
    checks += 1
    print('PASS', label)


def token(html):
    return re.search(r'name="_csrf_token"\s+value="([^"]+)"', html).group(1)


def post(path, data):
    status, _, body = request(path, dict(data, _csrf_token=csrf), True)
    result = json.loads(body)
    verify(status == 200 and result.get('success') is True, path)
    return result.get('data', result)


verify(request('/products')[1] == '/login', 'Unauthenticated requests require login')
csrf = token(request('/login')[2])
status, path, body = request('/login', {'email': 'local@trendmax.test', 'password': 'incorrect-test-password', '_csrf_token': csrf})
verify(path == '/login' and 'E-posta veya şifre hatalı' in body, 'Incorrect password rejected')
csrf = token(body)
status, path, _ = request('/login', {'email': 'local@trendmax.test', 'password': PASSWORD, '_csrf_token': csrf})
verify(status == 200 and path == '/dashboard', 'Merchant login')
csrf = token(request('/products/create')[2])
for path in ['/dashboard', '/products', '/products/create', '/orders', '/orders/1', '/customers', '/customers/1', '/categories', '/brands', '/marketplaces', '/finance', '/returns', '/invoices', '/questions', '/settings', '/subscription', '/feeds', '/sync', '/import', '/roles', '/users', '/documents', '/xml-profilleri', '/notifications']:
    status, final, body = request(path)
    verify(status == 200 and final == path and not any(x in body for x in ['<b>Warning</b>', 'Fatal error:', 'SQLSTATE[']), path)
for path in ['/products/ajax', '/orders/ajax', '/customers/ajax', '/categories/ajax', '/brands/ajax', '/returns/ajax', '/invoices/ajax', '/questions/ajax']:
    status, _, body = request(path + '?draw=1&start=0&length=10', ajax=True)
    result = json.loads(body)
    verify(status == 200 and ('data' in result or result.get('success') is True), path)
verify(request('/admin')[1] == '/admin/login', 'Merchant session cannot access platform admin')
verify(request('/products', {'name': 'CSRF probe'}, True)[0] == 403, 'Missing CSRF token rejected')
admin_csrf = token(request('/admin/login')[2])
status, path, _ = request('/admin/login', {'email': 'local@trendmax.test', 'password': PASSWORD, '_csrf_token': admin_csrf})
verify(status == 200 and path == '/admin', 'Platform admin login')
for path in ['/admin', '/stores', '/stores/1', '/admin/packages', '/admin/payments', '/admin/admin-users', '/admin/admin-roles', '/admin/support', '/admin/branding', '/admin/sync-settings']:
    status, final, body = request(path)
    verify(status == 200 and final == path and 'SQLSTATE[' not in body, path)
# Admin and merchant share a CSRF token, but have independent authentication state.
status, path, _ = request('/admin/logout', {'_csrf_token': csrf})
verify(status == 200 and path == '/admin/login', 'Platform admin logout')
request('/logout')
verify(request('/products')[1] == '/login', 'Merchant logout')
print(f'Completed: {checks} read-only checks; no catalog or marketplace writes.')
