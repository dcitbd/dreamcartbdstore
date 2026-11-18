"""
DREAM CART BD — UNIFIED MULTI-SOURCE FRAUD & COURIER RISK ENGINE (python_engine/fraud_engine.py)
Mirrors includes/fraud_engine.php for cPanel Python Passenger / WSGI mode.
"""

import json
import os
import re
import time
import base64
import urllib.request
import urllib.parse
import urllib.error
from datetime import datetime, timezone, timedelta

BD_TZ = timezone(timedelta(hours=6))

COURIER_DIRECTORY = [
    {'key': 'steadfast', 'name': 'Steadfast Courier', 'base_url': 'https://portal.packzy.com/api/v1'},
    {'key': 'pathao', 'name': 'Pathao Courier', 'base_url': 'https://api-hermes.pathao.com'},
    {'key': 'redx', 'name': 'RedX Delivery', 'base_url': 'https://openapi.redx.com.bd/v1.0.0-beta'},
    {'key': 'ecourier', 'name': 'eCourier', 'base_url': 'https://backoffice.ecourier.com.bd/api'},
    {'key': 'paperfly', 'name': 'Paperfly Courier', 'base_url': 'https://api.paperfly.com.bd'},
    {'key': 'carrybee', 'name': 'CarryBee', 'base_url': 'https://merchant.carrybee.com'},
    {'key': 'parceldex', 'name': 'ParcelDex', 'base_url': ''},
    {'key': 'sundarban', 'name': 'Sundarban Courier', 'base_url': 'https://www.sundarban.com.bd'},
    {'key': 'saparibahan', 'name': 'S.A. Paribahan', 'base_url': 'https://www.saparibahan.com'},
    {'key': 'karatoa', 'name': 'Karatoa Courier', 'base_url': 'https://karatoacourierservice.com'},
    {'key': 'ajr', 'name': 'AJR Courier', 'base_url': 'https://ajrcms.ajrgroupbd.org'},
    {'key': 'janani', 'name': 'Janani Express', 'base_url': 'https://jananiexpress.com'},
    {'key': 'tiger', 'name': 'Tiger Courier', 'base_url': 'https://www.tigercourierbd.com'},
    {'key': 'bdpost', 'name': 'Bangladesh Post Office', 'base_url': 'https://bdpost.gov.bd'},
    {'key': 'deliverytiger', 'name': 'Delivery Tiger', 'base_url': ''},
    {'key': 'continental', 'name': 'Continental Courier', 'base_url': ''},
    {'key': 'pandago', 'name': 'Pandago', 'base_url': 'https://pandagobox.com'},
]

SECRET_FIELDS = {
    'fraudbd_api_key', 'ecourier_api_key', 'ecourier_api_secret',
    'steadfast_api_key', 'steadfast_api_secret', 'pathao_access_token',
    'redx_api_token', 'paperfly_api_key', 'carrybee_client_id',
    'carrybee_client_secret', 'custom_fraud_api_key',
    'woocommerce_consumer_key', 'woocommerce_consumer_secret',
}


def normalize_phone(raw: str) -> str:
    digits = re.sub(r'\D+', '', str(raw or '').strip())
    if len(digits) >= 13 and digits.startswith('880'):
        digits = '0' + digits[3:]
    elif len(digits) == 10 and digits.startswith('1'):
        digits = '0' + digits
    if len(digits) > 11 and digits[-11:].startswith('01'):
        digits = digits[-11:]
    return digits


def is_valid_bd_phone(phone: str) -> bool:
    return bool(re.match(r'^01[3-9][0-9]{8}$', phone or ''))


def detect_courier_key(name: str) -> str:
    low = re.sub(r'[^a-z0-9]+', '', str(name or '').lower())
    mapping = [
        ('steadfast', ('steadfast', 'packzy')),
        ('pathao', ('pathao',)),
        ('redx', ('redx',)),
        ('ecourier', ('ecourier',)),
        ('paperfly', ('paperfly',)),
        ('carrybee', ('carrybee',)),
        ('parceldex', ('parceldex',)),
        ('sundarban', ('sundarban',)),
        ('saparibahan', ('saparibahan', 'paribahan')),
        ('karatoa', ('karatoa',)),
        ('ajr', ('ajr',)),
        ('janani', ('janani',)),
        ('deliverytiger', ('deliverytiger',)),
        ('tiger', ('tiger',)),
        ('bdpost', ('bdpost', 'postoffice')),
        ('continental', ('continental',)),
        ('pandago', ('pandago',)),
    ]
    for key, needles in mapping:
        if any(n in low for n in needles):
            return key
    return low or 'other'


def get_courier_display_name(key: str, fallback: str = '') -> str:
    for c in COURIER_DIRECTORY:
        if c['key'] == key:
            return c['name']
    return fallback or key.capitalize()


def mask_secret(val: str) -> str:
    val = str(val or '').strip()
    if not val:
        return ''
    if len(val) <= 4:
        return '••••'
    return '••••••••' + val[-4:]


def _http_json(method: str, url: str, headers=None, body=None, timeout: int = 15, basic_auth=None):
    hdrs = {'Accept': 'application/json', 'User-Agent': 'DreamCartBD-FraudEngine/2.0 (Python)'}
    if headers:
        hdrs.update(headers)
    data_bytes = None
    if body is not None:
        data_bytes = json.dumps(body, ensure_ascii=False).encode('utf-8')
        hdrs['Content-Type'] = 'application/json'
    if basic_auth:
        token = base64.b64encode(f"{basic_auth[0]}:{basic_auth[1]}".encode('utf-8')).decode('ascii')
        hdrs['Authorization'] = f"Basic {token}"
    req = urllib.request.Request(url, data=data_bytes, headers=hdrs, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode('utf-8', errors='replace')
            code = getattr(resp, 'status', 200)
            try:
                parsed = json.loads(raw)
            except Exception:
                parsed = None
            return {'ok': 200 <= code < 300, 'http_code': code, 'error': '', 'json': parsed}
    except urllib.error.HTTPError as e:
        return {'ok': False, 'http_code': e.code, 'error': f'HTTP {e.code}', 'json': None}
    except Exception as e:
        return {'ok': False, 'http_code': 0, 'error': str(e), 'json': None}


def _parse_fraudbd_response(data: dict, source_label: str = 'FraudBD API') -> dict:
    if not isinstance(data, dict):
        return {}
    candidates = data
    if isinstance(data.get('data'), dict):
        d = data['data']
        candidates = d.get('Summaries') or d.get('courierData') or d.get('couriers') or d
    elif isinstance(data.get('Summaries'), dict):
        candidates = data['Summaries']
    elif isinstance(data.get('courierData'), dict):
        candidates = data['courierData']

    skip = {'summary', 'total', 'total_summary', 'status', 'success', 'message', 'phone', 'phone_number', 'error', 'code'}
    out = {}
    if not isinstance(candidates, dict):
        return out
    for k, v in candidates.items():
        if not isinstance(v, dict) or str(k).lower() in skip:
            continue
        stat_keys = {'total', 'total_parcel', 'total_parcels', 'success', 'delivered', 'success_parcel', 'cancel', 'cancelled', 'cancelled_parcel', 'customer_rating', 'rating'}
        if not any(sk in v for sk in stat_keys):
            continue
        c_raw = str(v.get('name') or v.get('courier') or k)
        ck = detect_courier_key(c_raw)
        tot = int(v.get('total') or v.get('total_parcel') or v.get('total_parcels') or 0)
        suc = int(v.get('success') or v.get('delivered') or v.get('success_parcel') or v.get('delivered_parcels') or 0)
        can = int(v.get('cancel') or v.get('cancelled') or v.get('cancelled_parcel') or v.get('returned') or 0)
        if tot == 0 and (suc > 0 or can > 0):
            tot = suc + can
        rating = str(v.get('customer_rating') or v.get('rating') or '')
        out[ck] = {
            'key': ck,
            'name': get_courier_display_name(ck, c_raw.capitalize()),
            'total': tot,
            'success': suc,
            'cancel': can,
            'rating': rating,
            'source': source_label,
        }
    return out


class PythonFraudEngine:
    def __init__(self, storage_dir, read_cache_fn, write_cache_fn):
        self.storage_dir = str(storage_dir)
        self.config_path = os.path.join(self.storage_dir, 'fraud_config.json')
        self.read_cache = read_cache_fn
        self.write_cache = write_cache_fn

    def get_config(self) -> dict:
        defaults = {
            'enabled': True,
            'timeout_sec': 15,
            'cache_minutes': 60,
            'fraudbd_base_url': os.environ.get('FRAUDBD_BASE_URL', 'https://fraudbd.com'),
            'fraudbd_api_key': os.environ.get('FRAUDBD_API_KEY', ''),
            'ecourier_base_url': os.environ.get('ECOURIER_BASE_URL', 'https://backoffice.ecourier.com.bd/api'),
            'ecourier_api_key': os.environ.get('ECOURIER_API_KEY', ''),
            'ecourier_api_secret': os.environ.get('ECOURIER_API_SECRET', ''),
            'ecourier_user_id': os.environ.get('ECOURIER_USER_ID', ''),
            'steadfast_base_url': os.environ.get('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),
            'steadfast_api_key': os.environ.get('STEADFAST_API_KEY', ''),
            'steadfast_api_secret': os.environ.get('STEADFAST_API_SECRET', ''),
            'steadfast_fraud_endpoint': os.environ.get('STEADFAST_FRAUD_ENDPOINT', ''),
            'pathao_base_url': os.environ.get('PATHAO_BASE_URL', 'https://api-hermes.pathao.com'),
            'pathao_access_token': os.environ.get('PATHAO_ACCESS_TOKEN', ''),
            'pathao_fraud_endpoint': os.environ.get('PATHAO_FRAUD_ENDPOINT', ''),
            'redx_base_url': os.environ.get('REDX_BASE_URL', 'https://openapi.redx.com.bd/v1.0.0-beta'),
            'redx_api_token': os.environ.get('REDX_API_TOKEN', ''),
            'redx_fraud_endpoint': os.environ.get('REDX_FRAUD_ENDPOINT', ''),
            'paperfly_base_url': os.environ.get('PAPERFLY_BASE_URL', 'https://api.paperfly.com.bd'),
            'paperfly_api_key': os.environ.get('PAPERFLY_API_KEY', ''),
            'paperfly_fraud_endpoint': os.environ.get('PAPERFLY_FRAUD_ENDPOINT', ''),
            'carrybee_base_url': os.environ.get('CARRYBEE_BASE_URL', ''),
            'carrybee_client_id': os.environ.get('CARRYBEE_CLIENT_ID', ''),
            'carrybee_client_secret': os.environ.get('CARRYBEE_CLIENT_SECRET', ''),
            'carrybee_client_context': os.environ.get('CARRYBEE_CLIENT_CONTEXT', ''),
            'carrybee_fraud_endpoint': os.environ.get('CARRYBEE_FRAUD_ENDPOINT', ''),
            'custom_fraud_name': os.environ.get('CUSTOM_FRAUD_NAME', 'Fraud.bd'),
            'custom_fraud_url': os.environ.get('FRAUD_BD_URL', ''),
            'custom_fraud_api_key': os.environ.get('FRAUD_BD_API_KEY', ''),
            'custom_fraud_header': os.environ.get('FRAUD_BD_HEADER', 'api_key'),
            'woocommerce_url': os.environ.get('WOOCOMMERCE_URL', ''),
            'woocommerce_consumer_key': os.environ.get('WOOCOMMERCE_CONSUMER_KEY', ''),
            'woocommerce_consumer_secret': os.environ.get('WOOCOMMERCE_CONSUMER_SECRET', ''),
        }
        if os.path.isfile(self.config_path):
            try:
                with open(self.config_path, 'r', encoding='utf-8') as f:
                    saved = json.load(f)
                if isinstance(saved, dict):
                    defaults.update(saved)
            except Exception:
                pass
        return defaults

    def get_public_config(self) -> dict:
        c = self.get_config()
        return {
            'enabled': bool(c.get('enabled', True)),
            'timeout_sec': int(c.get('timeout_sec') or 15),
            'cache_minutes': int(c.get('cache_minutes') or 60),
            'fraudbd_base_url': c.get('fraudbd_base_url', ''),
            'fraudbd_api_key_masked': mask_secret(c.get('fraudbd_api_key', '')),
            'fraudbd_configured': bool(str(c.get('fraudbd_api_key') or '').strip()),
            'ecourier_base_url': c.get('ecourier_base_url', ''),
            'ecourier_user_id': c.get('ecourier_user_id', ''),
            'ecourier_api_key_masked': mask_secret(c.get('ecourier_api_key', '')),
            'ecourier_api_secret_masked': mask_secret(c.get('ecourier_api_secret', '')),
            'ecourier_configured': bool(str(c.get('ecourier_api_key') or '').strip() and str(c.get('ecourier_api_secret') or '').strip() and str(c.get('ecourier_user_id') or '').strip()),
            'steadfast_base_url': c.get('steadfast_base_url', ''),
            'steadfast_fraud_endpoint': c.get('steadfast_fraud_endpoint', ''),
            'steadfast_api_key_masked': mask_secret(c.get('steadfast_api_key', '')),
            'steadfast_configured': bool(str(c.get('steadfast_api_key') or '').strip() and str(c.get('steadfast_fraud_endpoint') or '').strip()),
            'pathao_base_url': c.get('pathao_base_url', ''),
            'pathao_fraud_endpoint': c.get('pathao_fraud_endpoint', ''),
            'pathao_configured': bool(str(c.get('pathao_access_token') or '').strip() and str(c.get('pathao_fraud_endpoint') or '').strip()),
            'redx_base_url': c.get('redx_base_url', ''),
            'redx_fraud_endpoint': c.get('redx_fraud_endpoint', ''),
            'redx_configured': bool(str(c.get('redx_api_token') or '').strip() and str(c.get('redx_fraud_endpoint') or '').strip()),
            'paperfly_base_url': c.get('paperfly_base_url', ''),
            'paperfly_fraud_endpoint': c.get('paperfly_fraud_endpoint', ''),
            'paperfly_configured': bool(str(c.get('paperfly_api_key') or '').strip() and str(c.get('paperfly_fraud_endpoint') or '').strip()),
            'carrybee_base_url': c.get('carrybee_base_url', ''),
            'carrybee_client_context': c.get('carrybee_client_context', ''),
            'carrybee_fraud_endpoint': c.get('carrybee_fraud_endpoint', ''),
            'carrybee_configured': bool(str(c.get('carrybee_client_id') or '').strip() and str(c.get('carrybee_fraud_endpoint') or '').strip()),
            'custom_fraud_name': c.get('custom_fraud_name', 'Fraud.bd'),
            'custom_fraud_url': c.get('custom_fraud_url', ''),
            'custom_fraud_header': c.get('custom_fraud_header', 'api_key'),
            'custom_fraud_api_key_masked': mask_secret(c.get('custom_fraud_api_key', '')),
            'custom_fraud_configured': bool(str(c.get('custom_fraud_url') or '').strip() and str(c.get('custom_fraud_api_key') or '').strip()),
            'woocommerce_url': c.get('woocommerce_url', ''),
            'woocommerce_key_masked': mask_secret(c.get('woocommerce_consumer_key', '')),
            'woocommerce_configured': bool(str(c.get('woocommerce_url') or '').strip() and str(c.get('woocommerce_consumer_key') or '').strip() and str(c.get('woocommerce_consumer_secret') or '').strip()),
        }

    def save_config(self, payload: dict) -> dict:
        cur = self.get_config()
        for k, v in (payload or {}).items():
            if k not in cur:
                continue
            if k in SECRET_FIELDS:
                sv = str(v or '').strip()
                if not sv or '••••' in sv:
                    if sv == '__CLEAR__':
                        cur[k] = ''
                    continue
                cur[k] = sv
            elif k == 'enabled':
                cur[k] = bool(v)
            elif k == 'timeout_sec':
                cur[k] = max(3, min(30, int(v or 15)))
            elif k == 'cache_minutes':
                cur[k] = max(0, min(1440, int(v or 60)))
            else:
                cur[k] = str(v or '').strip()
        os.makedirs(self.storage_dir, exist_ok=True)
        with open(self.config_path, 'w', encoding='utf-8') as f:
            json.dump(cur, f, ensure_ascii=False, indent=2)
        return self.get_public_config()

    def get_complaints(self) -> list:
        res = self.read_cache('fraud_complaints', [])
        return res if isinstance(res, list) else []

    def add_complaint(self, payload: dict) -> dict:
        phone = normalize_phone(payload.get('phone', ''))
        details = str(payload.get('details', '')).strip()
        if not phone or not details:
            return {'success': False, 'status': 'error', 'message': 'Phone and details are required'}
        item = {
            'id': str(payload.get('id') or f"CMP-{int(time.time()) % 100000}"),
            'phone': phone,
            'customer_name': str(payload.get('customer_name') or 'অজ্ঞাত কাস্টমার').strip(),
            'courier': str(payload.get('courier') or 'Steadfast Courier').strip(),
            'seller_name': str(payload.get('seller_name') or 'Dream Cart BD Seller').strip(),
            'severity': str(payload.get('severity') or 'High Risk').strip(),
            'details': details,
            'date': str(payload.get('date') or datetime.now(BD_TZ).strftime('%Y-%m-%d')),
            'created_at': datetime.now(BD_TZ).isoformat(),
        }
        items = self.get_complaints()
        items.insert(0, item)
        self.write_cache('fraud_complaints', items)
        return {'success': True, 'status': 'success', 'item': item, 'items': items}

    def toggle_block_phone(self, raw_phone: str, reason: str = '') -> dict:
        phone = normalize_phone(raw_phone)
        if not phone:
            return {'success': False, 'status': 'error', 'message': 'Invalid phone number'}
        items = self.read_cache('blocked_customers', [])
        if not isinstance(items, list):
            items = []
        idx = next((i for i, x in enumerate(items) if isinstance(x, dict) and normalize_phone(x.get('phone', '')) == phone), -1)
        if idx >= 0:
            items.pop(idx)
            blocked = False
        else:
            items.insert(0, {'phone': phone, 'reason': reason.strip() or 'Manual Admin Block', 'blocked_at': datetime.now(BD_TZ).isoformat()})
            blocked = True
        self.write_cache('blocked_customers', items)
        return {'success': True, 'status': 'success', 'phone': phone, 'blocked': blocked, 'items': items}

    def check(self, raw_phone: str, force: bool = False) -> dict:
        phone = normalize_phone(raw_phone)
        if not phone:
            return {'success': False, 'status': 'error', 'message': 'অনুগ্রহ করে সঠিক ১১ ডিজিটের মোবাইল নাম্বার দিন।'}

        cfg = self.get_config()
        timeout = max(3, min(25, int(cfg.get('timeout_sec') or 15)))
        cache_min = max(0, int(cfg.get('cache_minutes') or 60))

        orders = self.read_cache('orders', []) or []
        incomplete = self.read_cache('incomplete_orders', []) or []
        cancel_set = {'cancelled', 'canceled', 'returned', 'return', 'failed', 'refunded', 'rejected', 'fake'}
        deliv_set = {'delivered', 'completed', 'complete', 'success'}

        matched_name = ''
        site_tot = site_suc = site_can = site_deliv = site_pend = 0
        internal_by_courier = {}

        for o in orders:
            if not isinstance(o, dict):
                continue
            if normalize_phone(o.get('phone') or o.get('mobile') or o.get('customer_phone') or '') != phone:
                continue
            site_tot += 1
            if not matched_name and o.get('customer_name'):
                matched_name = str(o['customer_name']).strip()
            st = str(o.get('order_status') or o.get('status') or 'pending').strip().lower()
            is_can = st in cancel_set
            if is_can:
                site_can += 1
            else:
                site_suc += 1
                if st in deliv_set:
                    site_deliv += 1
                else:
                    site_pend += 1
            cn = str(o.get('courier_name') or o.get('courier') or '').strip()
            if cn and cn.lower() not in ('n/a', 'none'):
                ck = detect_courier_key(cn)
                entry = internal_by_courier.setdefault(ck, {'key': ck, 'name': get_courier_display_name(ck, cn), 'total': 0, 'success': 0, 'cancel': 0})
                entry['total'] += 1
                if is_can:
                    entry['cancel'] += 1
                else:
                    entry['success'] += 1

        inc_count = 0
        for io in incomplete:
            if isinstance(io, dict) and normalize_phone(io.get('phone') or io.get('mobile') or '') == phone:
                inc_count += 1
                if not matched_name and io.get('customer_name'):
                    matched_name = str(io['customer_name']).strip()

        ext_data = None
        from_cache = False
        if not force and cache_min > 0:
            lcache = self.read_cache('fraud_lookup_cache', {})
            if isinstance(lcache, dict) and isinstance(lcache.get(phone), dict):
                ent = lcache[phone]
                if time.time() - int(ent.get('cached_at_ts') or 0) < cache_min * 60:
                    ext_data = ent
                    from_cache = True

        if ext_data is None:
            providers_status = []
            external_couriers = {}
            ec_status = ''
            ec_msgs = []
            ec_warn = False
            mp_rows = []

            if cfg.get('enabled', True) and is_valid_bd_phone(phone):
                # FraudBD
                if str(cfg.get('fraudbd_api_key') or '').strip():
                    url = str(cfg.get('fraudbd_base_url') or 'https://fraudbd.com').rstrip('/') + '/api/check-courier-info'
                    r = _http_json('POST', url, {'api_key': cfg['fraudbd_api_key'].strip()}, {'phone_number': phone}, timeout)
                    if r['ok'] and isinstance(r['json'], dict):
                        parsed = _parse_fraudbd_response(r['json'], 'FraudBD API')
                        external_couriers.update(parsed)
                        providers_status.append({'provider': 'FraudBD', 'status': 'success', 'message': f"{len(parsed)} টি কুরিয়ার ডেটা পাওয়া গেছে" if parsed else 'কোনো কুরিয়ার রেকর্ড পাওয়া যায়নি'})
                    else:
                        providers_status.append({'provider': 'FraudBD', 'status': 'error', 'message': r['error'] or 'API error'})
                else:
                    providers_status.append({'provider': 'FraudBD', 'status': 'not_configured', 'message': 'API Key সেট করা হয়নি'})

                # eCourier
                if all(str(cfg.get(k) or '').strip() for k in ('ecourier_api_key', 'ecourier_api_secret', 'ecourier_user_id')):
                    url = str(cfg.get('ecourier_base_url') or 'https://backoffice.ecourier.com.bd/api').rstrip('/') + '/fraud-status-check'
                    r = _http_json('POST', url, {
                        'API-KEY': cfg['ecourier_api_key'].strip(),
                        'API-SECRET': cfg['ecourier_api_secret'].strip(),
                        'USER-ID': cfg['ecourier_user_id'].strip(),
                    }, {'number': phone}, timeout)
                    if r['ok'] and isinstance(r['json'], dict):
                        ec_status = str(r['json'].get('customer_status') or '').strip()
                        raw_m = r['json'].get('message')
                        ec_msgs = raw_m if isinstance(raw_m, list) else ([str(raw_m)] if raw_m else [])
                        ec_warn = ('warning' in ec_status.lower() or 'fraud' in ec_status.lower())
                        providers_status.append({'provider': 'eCourier', 'status': 'success', 'customer_status': ec_status or 'Checked', 'message': ec_status or 'Verified'})
                    else:
                        providers_status.append({'provider': 'eCourier', 'status': 'error', 'message': r['error'] or 'API error'})
                else:
                    providers_status.append({'provider': 'eCourier', 'status': 'not_configured', 'message': 'API Key / Secret / User-ID সেট করা হয়নি'})

                # WooCommerce
                if all(str(cfg.get(k) or '').strip() for k in ('woocommerce_url', 'woocommerce_consumer_key', 'woocommerce_consumer_secret')):
                    wc_url = cfg['woocommerce_url'].rstrip('/') + '/wp-json/wc/v3/orders?search=' + urllib.parse.quote(phone) + '&per_page=50'
                    r = _http_json('GET', wc_url, timeout=timeout, basic_auth=(cfg['woocommerce_consumer_key'].strip(), cfg['woocommerce_consumer_secret'].strip()))
                    if r['ok'] and isinstance(r['json'], list):
                        wt = ws = wc = 0
                        for wo in r['json']:
                            if not isinstance(wo, dict):
                                continue
                            bp = normalize_phone((wo.get('billing') or {}).get('phone', ''))
                            if bp and bp != phone:
                                continue
                            wt += 1
                            if str(wo.get('status') or '').lower() in ('cancelled', 'refunded', 'failed', 'trash'):
                                wc += 1
                            else:
                                ws += 1
                        mp_rows.append({
                            'key': 'woocommerce', 'name': '🛒 WooCommerce / Marketplace Orders', 'category': 'marketplace',
                            'total': wt, 'success': ws, 'cancel': wc, 'ratio': round((ws / wt) * 100) if wt > 0 else None,
                            'rating': '', 'source': 'WooCommerce REST API v3', 'connected': True,
                        })
                        providers_status.append({'provider': 'WooCommerce', 'status': 'success', 'message': f'{wt} orders found'})
                    else:
                        providers_status.append({'provider': 'WooCommerce', 'status': 'error', 'message': r['error'] or 'API error'})

            ext_data = {
                'cached_at_ts': int(time.time()),
                'providers_status': providers_status,
                'external_couriers': external_couriers,
                'ecourier_status': ec_status,
                'ecourier_messages': ec_msgs,
                'ecourier_warning': ec_warn,
                'marketplace_rows': mp_rows,
            }
            if any(p.get('status') == 'success' for p in providers_status) and cache_min > 0:
                lcache = self.read_cache('fraud_lookup_cache', {})
                if not isinstance(lcache, dict):
                    lcache = {}
                lcache[phone] = ext_data
                self.write_cache('fraud_lookup_cache', lcache)

        web_ratio = round((site_suc / site_tot) * 100) if site_tot > 0 else None
        rows = [{
            'key': 'website',
            'name': '🌐 ওয়েবসাইট অর্ডার (Dream Cart BD)',
            'category': 'website',
            'isWebsite': True,
            'total': site_tot,
            'success': site_suc,
            'cancel': site_can,
            'delivered': site_deliv,
            'pending': site_pend,
            'ratio': web_ratio,
            'rating': '',
            'status_label': 'কোনো অর্ডার নেই' if site_tot == 0 else ('Safe ✓' if web_ratio >= 80 else ('Moderate' if web_ratio >= 60 else 'High Risk ⚠️')),
            'source': 'Website Database (Live)',
            'connected': True,
        }]

        ordered_keys = ['steadfast', 'pathao', 'redx', 'ecourier', 'paperfly', 'carrybee']
        admin_couriers = self.read_cache('couriers', []) or []
        connected_admin = set()
        for ac in admin_couriers:
            if isinstance(ac, dict):
                ck = detect_courier_key(ac.get('name', ''))
                if ck and ck not in ordered_keys:
                    ordered_keys.append(ck)
                if str(ac.get('status') or 'connected').lower() != 'paused':
                    connected_admin.add(ck)

        ext_couriers = ext_data.get('external_couriers') or {}
        for k in list(ext_couriers.keys()) + list(internal_by_courier.keys()):
            if k not in ordered_keys:
                ordered_keys.append(k)

        ec_status = str(ext_data.get('ecourier_status') or '')
        ec_warn = bool(ext_data.get('ecourier_warning'))
        pathao_risky = False

        for ck in ordered_keys:
            ext = ext_couriers.get(ck)
            loc = internal_by_courier.get(ck)
            ct = int(ext['total']) if ext else 0
            cs = int(ext['success']) if ext else 0
            cc = int(ext['cancel']) if ext else 0
            cr = str(ext.get('rating') or '') if ext else ''
            csrc = str(ext.get('source') or '') if ext else ''
            if ct == 0 and loc and int(loc['total']) > 0:
                ct, cs, cc = int(loc['total']), int(loc['success']), int(loc['cancel'])
                csrc = 'ওয়েবসাইট কুরিয়ার লগ'
            if ck == 'ecourier' and ec_status:
                cr = ec_status
                csrc = (csrc + ' + eCourier Fraud API') if csrc else 'eCourier Fraud API'
            if ck == 'pathao' and 'risk' in cr.lower():
                pathao_risky = True
            cratio = round((cs / ct) * 100) if ct > 0 else None
            cname = (ext or loc or {}).get('name') or get_courier_display_name(ck)
            if ck == 'ecourier' and ec_warn:
                slabel = 'Warning! ⚠️'
            elif ck == 'ecourier' and 'verified' in ec_status.lower():
                slabel = 'Verified ✓'
            elif ct > 0:
                slabel = 'Safe ✓' if cratio >= 80 else ('Moderate' if cratio >= 60 else 'High Risk ⚠️')
            elif cr:
                slabel = cr
            else:
                slabel = 'ডেটা নেই'
            rows.append({
                'key': ck, 'name': cname, 'category': 'courier', 'isWebsite': False,
                'total': ct, 'success': cs, 'cancel': cc, 'ratio': cratio, 'rating': cr,
                'status_label': slabel,
                'source': csrc or ('Connected Courier' if ck in connected_admin else 'API Ready'),
                'connected': bool(ext or ck in connected_admin or (ck == 'ecourier' and ec_status)),
            })

        for mr in (ext_data.get('marketplace_rows') or []):
            m = dict(mr)
            m['status_label'] = 'কোনো অর্ডার নেই' if not m.get('total') else ('Safe ✓' if (m.get('ratio') or 0) >= 80 else ('Moderate' if (m.get('ratio') or 0) >= 60 else 'High Risk ⚠️'))
            rows.append(m)

        all_cmps = self.get_complaints()
        phone_cmps = [c for c in all_cmps if isinstance(c, dict) and normalize_phone(c.get('phone', '')) == phone]
        if not matched_name and phone_cmps:
            matched_name = str(phone_cmps[0].get('customer_name') or '').strip()

        blocked_list = self.read_cache('blocked_customers', []) or []
        blk_match = next((b for b in blocked_list if isinstance(b, dict) and normalize_phone(b.get('phone', '')) == phone), None)
        is_blocked = blk_match is not None
        block_reason = str(blk_match.get('reason') or '') if blk_match else ''

        gt = sum(int(r['total']) for r in rows)
        gs = sum(int(r['success']) for r in rows)
        gc = sum(int(r['cancel']) for r in rows)
        gratio = round((gs / gt) * 100, 1) if gt > 0 else None
        crate = round((gc / gt) * 100, 1) if gt > 0 else None

        has_signal = (gt > 0 or ec_warn or pathao_risky or len(phone_cmps) > 0 or is_blocked)
        if not has_signal:
            risk = {'score': None, 'level': 'UNKNOWN', 'label': 'নতুন কাস্টমার (No History)', 'action': 'Call Confirmation / Normal', 'recommendation': 'UNKNOWN', 'badge_class': 'badge-warning'}
        else:
            score = round((crate / 100) * 70) if gt > 0 else 20
            if gt <= 2 and gc == 0 and gt > 0:
                score = max(5, score)
            if ec_warn:
                score += 30
            if pathao_risky:
                score += 20
            if phone_cmps:
                score += min(35, len(phone_cmps) * 18)
            if is_blocked:
                score = max(score, 85)
            score = max(0, min(100, int(score)))
            if score <= 10:
                risk = {'score': score, 'level': 'VERY_LOW', 'label': 'Very Low Risk (Safe)', 'action': 'Normal', 'recommendation': 'APPROVE', 'badge_class': 'badge-success'}
            elif score <= 30:
                risk = {'score': score, 'level': 'LOW', 'label': 'Low Risk (Safe)', 'action': 'Approve', 'recommendation': 'APPROVE', 'badge_class': 'badge-success'}
            elif score <= 50:
                risk = {'score': score, 'level': 'MEDIUM', 'label': 'Medium Risk', 'action': 'Call Confirmation', 'recommendation': 'CALL_CONFIRMATION', 'badge_class': 'badge-warning'}
            elif score <= 75:
                risk = {'score': score, 'level': 'HIGH', 'label': 'High Risk', 'action': 'Manual Review / Advance Payment', 'recommendation': 'MANUAL_REVIEW', 'badge_class': 'badge-danger'}
            else:
                risk = {'score': score, 'level': 'VERY_HIGH', 'label': 'Very High Risk', 'action': 'Manual Review / COD Restriction', 'recommendation': 'COD_RESTRICTION', 'badge_class': 'badge-danger'}

        if risk['level'] == 'UNKNOWN':
            short_status = 'New (No Data)'
        elif risk['level'] in ('HIGH', 'VERY_HIGH'):
            short_status = f"High Risk ({round(gratio)}%)" if gratio is not None else f"High Risk (Score {risk['score']})"
        elif risk['level'] == 'MEDIUM':
            short_status = f"Moderate ({round(gratio)}%)" if gratio is not None else f"Moderate (Score {risk['score']})"
        else:
            short_status = f"Safe ({round(gratio)}%)" if gratio is not None else 'Safe (Verified)'

        return {
            'success': True, 'status': 'success', 'phone': phone, 'valid_bd_phone': is_valid_bd_phone(phone),
            'customer_name': matched_name or 'নতুন / সাধারণ কাস্টমার',
            'checked_at': datetime.now(BD_TZ).isoformat(), 'cached': from_cache,
            'is_blocked': is_blocked, 'block_reason': block_reason,
            'rows': rows,
            'summary': {
                'total_parcels': gt, 'delivered': gs, 'cancelled': gc, 'returned': gc,
                'success_rate': gratio, 'cancellation_rate': crate, 'incomplete_orders': inc_count,
            },
            'risk': risk, 'short_status': short_status,
            'ecourier_status': ec_status, 'ecourier_messages': ext_data.get('ecourier_messages') or [],
            'providers_status': ext_data.get('providers_status') or [],
            'complaints': phone_cmps, 'all_complaints': all_cmps[:50],
        }

    def bulk_check(self, raw_phones: list, force: bool = False) -> dict:
        unique = []
        for p in (raw_phones or []):
            n = normalize_phone(p)
            if n and n not in unique:
                unique.append(n)
            if len(unique) >= 30:
                break
        return {'success': True, 'status': 'success', 'count': len(unique), 'results': {p: self.check(p, force=force) for p in unique}}
