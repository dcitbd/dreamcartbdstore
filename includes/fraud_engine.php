<?php
/**
 * DREAM CART BD — UNIFIED MULTI-SOURCE FRAUD & COURIER RISK ENGINE (includes/fraud_engine.php)
 * PHP 7.0+ Compatible (Zero PHP 8-only syntax, cPanel Safe)
 *
 * Aggregates customer delivery & fraud intelligence from:
 * 1. Website Internal Orders (Dream Cart BD SQLite/JSON Orders + Incomplete Orders + Courier Consignments)
 * 2. FraudBD Multi-Courier API (https://fraudbd.com/api/check-courier-info -> Steadfast, Pathao, RedX, Paperfly, etc.)
 * 3. eCourier Direct Fraud Status Check API (POST /fraud-status-check -> Verified / Warning! / New Customer)
 * 4. Direct Courier Connectors (Steadfast, Pathao, RedX, Paperfly, CarryBee, Sundarban, S.A. Paribahan, Karatoa, AJR, Janani, Tiger, BD Post, Delivery Tiger, Continental, Pandago + Custom Couriers)
 * 5. Optional Fraud.bd / Custom Multi-Courier Fraud API (Admin-configured endpoint + key)
 * 6. Optional Connected Marketplace Orders (WooCommerce / Dokan / WCFM REST API v3)
 * 7. Internal Seller Complaints & Blocked Customers Registry
 */

if (!defined('DCBD_INIT')) {
    require_once __DIR__ . '/config.php';
}
if (!class_exists('DcbdCacheEngine')) {
    require_once __DIR__ . '/cache_engine.php';
}

class DcbdFraudEngine
{
    const CONFIG_FILE = 'fraud_config.json';
    const CACHE_COLLECTION = 'fraud_lookup_cache';
    const COMPLAINTS_COLLECTION = 'fraud_complaints';
    const BLOCKED_COLLECTION = 'blocked_customers';

    /**
     * Master directory of Bangladesh couriers from fraud check.md
     */
    public static function getCourierDirectory(): array
    {
        return [
            ['key' => 'steadfast',    'name' => 'Steadfast Courier',       'base_url' => 'https://portal.packzy.com/api/v1'],
            ['key' => 'pathao',       'name' => 'Pathao Courier',          'base_url' => 'https://api-hermes.pathao.com'],
            ['key' => 'redx',         'name' => 'RedX Delivery',           'base_url' => 'https://openapi.redx.com.bd/v1.0.0-beta'],
            ['key' => 'ecourier',     'name' => 'eCourier',                'base_url' => 'https://backoffice.ecourier.com.bd/api'],
            ['key' => 'paperfly',     'name' => 'Paperfly Courier',        'base_url' => 'https://api.paperfly.com.bd'],
            ['key' => 'carrybee',     'name' => 'CarryBee',                'base_url' => 'https://merchant.carrybee.com'],
            ['key' => 'parceldex',    'name' => 'ParcelDex',               'base_url' => ''],
            ['key' => 'sundarban',    'name' => 'Sundarban Courier',       'base_url' => 'https://www.sundarban.com.bd'],
            ['key' => 'saparibahan',  'name' => 'S.A. Paribahan',          'base_url' => 'https://www.saparibahan.com'],
            ['key' => 'karatoa',      'name' => 'Karatoa Courier',         'base_url' => 'https://karatoacourierservice.com'],
            ['key' => 'ajr',          'name' => 'AJR Courier',             'base_url' => 'https://ajrcms.ajrgroupbd.org'],
            ['key' => 'janani',       'name' => 'Janani Express',          'base_url' => 'https://jananiexpress.com'],
            ['key' => 'tiger',        'name' => 'Tiger Courier',           'base_url' => 'https://www.tigercourierbd.com'],
            ['key' => 'bdpost',       'name' => 'Bangladesh Post Office',  'base_url' => 'https://bdpost.gov.bd'],
            ['key' => 'deliverytiger','name' => 'Delivery Tiger',          'base_url' => ''],
            ['key' => 'continental',  'name' => 'Continental Courier',     'base_url' => ''],
            ['key' => 'pandago',      'name' => 'Pandago',                 'base_url' => 'https://pandagobox.com'],
        ];
    }

    /**
     * Normalize Bangladesh mobile number to 11-digit format (01[3-9]XXXXXXXX)
     * PHP 7.0+ compatible (no str_starts_with)
     */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw));
        if ($digits === null) {
            $digits = '';
        }
        if (strlen($digits) >= 13 && substr($digits, 0, 3) === '880') {
            $digits = '0' . substr($digits, 3);
        } elseif (strlen($digits) === 10 && substr($digits, 0, 1) === '1') {
            $digits = '0' . $digits;
        }
        if (strlen($digits) > 11 && substr($digits, -11, 2) === '01') {
            $digits = substr($digits, -11);
        }
        return $digits;
    }

    public static function isValidBdPhone(string $phone): bool
    {
        return (bool)preg_match('/^01[3-9][0-9]{8}$/', $phone);
    }

    /**
     * Match any courier name string to a canonical key
     */
    public static function detectCourierKey(string $name): string
    {
        $low = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
        if (strpos($low, 'steadfast') !== false || strpos($low, 'packzy') !== false) return 'steadfast';
        if (strpos($low, 'pathao') !== false) return 'pathao';
        if (strpos($low, 'redx') !== false) return 'redx';
        if (strpos($low, 'ecourier') !== false) return 'ecourier';
        if (strpos($low, 'paperfly') !== false) return 'paperfly';
        if (strpos($low, 'carrybee') !== false) return 'carrybee';
        if (strpos($low, 'parceldex') !== false) return 'parceldex';
        if (strpos($low, 'sundarban') !== false) return 'sundarban';
        if (strpos($low, 'saparibahan') !== false || strpos($low, 'paribahan') !== false) return 'saparibahan';
        if (strpos($low, 'karatoa') !== false) return 'karatoa';
        if (strpos($low, 'ajr') !== false) return 'ajr';
        if (strpos($low, 'janani') !== false) return 'janani';
        if (strpos($low, 'tiger') !== false && strpos($low, 'delivery') === false) return 'tiger';
        if (strpos($low, 'deliverytiger') !== false) return 'deliverytiger';
        if (strpos($low, 'bdpost') !== false || strpos($low, 'postoffice') !== false) return 'bdpost';
        if (strpos($low, 'continental') !== false) return 'continental';
        if (strpos($low, 'pandago') !== false) return 'pandago';
        return $low !== '' ? $low : 'other';
    }

    public static function getCourierDisplayName(string $key, string $fallback = ''): string
    {
        foreach (self::getCourierDirectory() as $c) {
            if ($c['key'] === $key) {
                return $c['name'];
            }
        }
        return $fallback !== '' ? $fallback : ucfirst($key);
    }

    /**
     * Secure server-side config storage path (inside storage/, protected by .htaccess)
     */
    protected static function getConfigPath(): string
    {
        return DCBD_STORAGE_DIR . '/' . self::CONFIG_FILE;
    }

    protected static function envVal(string $name, string $default = ''): string
    {
        $v = getenv($name);
        if ($v !== false && trim((string)$v) !== '') {
            return trim((string)$v);
        }
        if (isset($_ENV[$name]) && trim((string)$_ENV[$name]) !== '') {
            return trim((string)$_ENV[$name]);
        }
        return $default;
    }

    /**
     * Load server-side fraud API configuration (file + env fallback)
     */
    public static function getConfig(): array
    {
        $defaults = [
            'enabled'                    => true,
            'timeout_sec'                => 15,
            'cache_minutes'              => 60,
            // FraudBD Multi-Courier API (https://fraudbd.com/api-documentation)
            'fraudbd_base_url'           => self::envVal('FRAUDBD_BASE_URL', 'https://fraudbd.com'),
            'fraudbd_api_key'            => self::envVal('FRAUDBD_API_KEY', ''),
            // eCourier Direct Fraud API (POST /fraud-status-check)
            'ecourier_base_url'          => self::envVal('ECOURIER_BASE_URL', 'https://backoffice.ecourier.com.bd/api'),
            'ecourier_api_key'           => self::envVal('ECOURIER_API_KEY', ''),
            'ecourier_api_secret'        => self::envVal('ECOURIER_API_SECRET', ''),
            'ecourier_user_id'           => self::envVal('ECOURIER_USER_ID', ''),
            // Steadfast optional merchant fraud endpoint
            'steadfast_base_url'         => self::envVal('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),
            'steadfast_api_key'          => self::envVal('STEADFAST_API_KEY', ''),
            'steadfast_api_secret'       => self::envVal('STEADFAST_API_SECRET', ''),
            'steadfast_fraud_endpoint'   => self::envVal('STEADFAST_FRAUD_ENDPOINT', ''),
            // Pathao optional merchant endpoint
            'pathao_base_url'            => self::envVal('PATHAO_BASE_URL', 'https://api-hermes.pathao.com'),
            'pathao_access_token'        => self::envVal('PATHAO_ACCESS_TOKEN', ''),
            'pathao_fraud_endpoint'      => self::envVal('PATHAO_FRAUD_ENDPOINT', ''),
            // RedX optional merchant endpoint
            'redx_base_url'              => self::envVal('REDX_BASE_URL', 'https://openapi.redx.com.bd/v1.0.0-beta'),
            'redx_api_token'             => self::envVal('REDX_API_TOKEN', ''),
            'redx_fraud_endpoint'        => self::envVal('REDX_FRAUD_ENDPOINT', ''),
            // Paperfly optional
            'paperfly_base_url'          => self::envVal('PAPERFLY_BASE_URL', 'https://api.paperfly.com.bd'),
            'paperfly_api_key'           => self::envVal('PAPERFLY_API_KEY', ''),
            'paperfly_fraud_endpoint'    => self::envVal('PAPERFLY_FRAUD_ENDPOINT', ''),
            // CarryBee optional
            'carrybee_base_url'          => self::envVal('CARRYBEE_BASE_URL', ''),
            'carrybee_client_id'         => self::envVal('CARRYBEE_CLIENT_ID', ''),
            'carrybee_client_secret'     => self::envVal('CARRYBEE_CLIENT_SECRET', ''),
            'carrybee_client_context'    => self::envVal('CARRYBEE_CLIENT_CONTEXT', ''),
            'carrybee_fraud_endpoint'    => self::envVal('CARRYBEE_FRAUD_ENDPOINT', ''),
            // Fraud.bd / Custom Multi-Courier Fraud API (configured when provider docs received)
            'custom_fraud_name'          => self::envVal('CUSTOM_FRAUD_NAME', 'Fraud.bd'),
            'custom_fraud_url'           => self::envVal('FRAUD_BD_URL', ''),
            'custom_fraud_api_key'       => self::envVal('FRAUD_BD_API_KEY', ''),
            'custom_fraud_header'        => self::envVal('FRAUD_BD_HEADER', 'api_key'),
            // WooCommerce / Dokan / WCFM Order History Check (GET /wp-json/wc/v3/orders?search={phone})
            'woocommerce_url'            => self::envVal('WOOCOMMERCE_URL', ''),
            'woocommerce_consumer_key'   => self::envVal('WOOCOMMERCE_CONSUMER_KEY', ''),
            'woocommerce_consumer_secret'=> self::envVal('WOOCOMMERCE_CONSUMER_SECRET', ''),
        ];

        $saved = [];
        $path = self::getConfigPath();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $dec = @json_decode($raw, true);
                if (is_array($dec)) {
                    $saved = $dec;
                }
            }
        }

        $cfg = array_merge($defaults, $saved);

        // Auto-inherit non-masked credentials from connected Admin Couriers if not explicitly set
        $couriers = DcbdCacheEngine::getCouriers();
        if (is_array($couriers)) {
            foreach ($couriers as $c) {
                if (!is_array($c)) continue;
                if (isset($c['status']) && strtolower((string)$c['status']) === 'paused') continue;
                $ck = self::detectCourierKey((string)($c['name'] ?? ''));
                $rk = trim((string)($c['raw_api_key'] ?? ''));
                $rs = trim((string)($c['raw_secret_key'] ?? ''));
                $bu = trim((string)($c['base_url'] ?? ''));
                if ($rk !== '' && strpos($rk, '••') === false) {
                    if ($ck === 'steadfast' && empty($cfg['steadfast_api_key'])) {
                        $cfg['steadfast_api_key'] = $rk;
                        if ($rs !== '' && strpos($rs, '••') === false) $cfg['steadfast_api_secret'] = $rs;
                        if ($bu !== '') $cfg['steadfast_base_url'] = $bu;
                    } elseif ($ck === 'ecourier' && empty($cfg['ecourier_api_key'])) {
                        $cfg['ecourier_api_key'] = $rk;
                        if ($rs !== '' && strpos($rs, '••') === false) $cfg['ecourier_api_secret'] = $rs;
                        if ($bu !== '') $cfg['ecourier_base_url'] = $bu;
                    } elseif ($ck === 'pathao' && empty($cfg['pathao_access_token'])) {
                        if ($rs !== '' && strpos($rs, '••') === false) $cfg['pathao_access_token'] = $rs;
                        if ($bu !== '') $cfg['pathao_base_url'] = $bu;
                    } elseif ($ck === 'redx' && empty($cfg['redx_api_token'])) {
                        $cfg['redx_api_token'] = ($rs !== '' && strpos($rs, '••') === false) ? $rs : $rk;
                        if ($bu !== '') $cfg['redx_base_url'] = $bu;
                    } elseif ($ck === 'paperfly' && empty($cfg['paperfly_api_key'])) {
                        $cfg['paperfly_api_key'] = $rk;
                        if ($bu !== '') $cfg['paperfly_base_url'] = $bu;
                    }
                }
            }
        }

        return $cfg;
    }

    /**
     * Save fraud API config securely on server (preserving existing secrets if masked/blank placeholder passed)
     */
    public static function saveConfig(array $input): array
    {
        $current = self::getConfig();
        $secretFields = [
            'fraudbd_api_key',
            'ecourier_api_key',
            'ecourier_api_secret',
            'steadfast_api_key',
            'steadfast_api_secret',
            'pathao_access_token',
            'redx_api_token',
            'paperfly_api_key',
            'carrybee_client_id',
            'carrybee_client_secret',
            'custom_fraud_api_key',
            'woocommerce_consumer_key',
            'woocommerce_consumer_secret',
        ];
        $allowedFields = array_merge($secretFields, [
            'enabled',
            'timeout_sec',
            'cache_minutes',
            'fraudbd_base_url',
            'ecourier_base_url',
            'ecourier_user_id',
            'steadfast_base_url',
            'steadfast_fraud_endpoint',
            'pathao_base_url',
            'pathao_fraud_endpoint',
            'redx_base_url',
            'redx_fraud_endpoint',
            'paperfly_base_url',
            'paperfly_fraud_endpoint',
            'carrybee_base_url',
            'carrybee_client_context',
            'carrybee_fraud_endpoint',
            'custom_fraud_name',
            'custom_fraud_url',
            'custom_fraud_header',
            'woocommerce_url',
        ]);

        foreach ($allowedFields as $k) {
            if (!array_key_exists($k, $input)) continue;
            $val = $input[$k];
            if (in_array($k, $secretFields, true)) {
                $strVal = trim((string)$val);
                // Keep existing secret if user left masked value untouched
                if ($strVal === '' || strpos($strVal, '••••') !== false) {
                    if ($strVal === '__CLEAR__') {
                        $current[$k] = '';
                    }
                    continue;
                }
                $current[$k] = $strVal;
            } elseif ($k === 'enabled') {
                $current[$k] = (bool)$val;
            } elseif ($k === 'timeout_sec') {
                $current[$k] = max(3, min(30, (int)$val));
            } elseif ($k === 'cache_minutes') {
                $current[$k] = max(0, min(1440, (int)$val));
            } else {
                $current[$k] = trim((string)$val);
            }
        }

        $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(self::getConfigPath(), $json, LOCK_EX);

        return self::getPublicConfig();
    }

    protected static function maskSecret(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '';
        $len = strlen($val);
        if ($len <= 4) return '••••';
        return '••••••••' . substr($val, -4);
    }

    /**
     * Public config returned to Admin UI — NEVER exposes raw API keys or secrets!
     */
    public static function getPublicConfig(): array
    {
        $c = self::getConfig();
        return [
            'enabled'                      => (bool)$c['enabled'],
            'timeout_sec'                  => (int)$c['timeout_sec'],
            'cache_minutes'                => (int)$c['cache_minutes'],
            'fraudbd_base_url'             => $c['fraudbd_base_url'],
            'fraudbd_api_key_masked'       => self::maskSecret((string)$c['fraudbd_api_key']),
            'fraudbd_configured'           => trim((string)$c['fraudbd_api_key']) !== '',
            'ecourier_base_url'            => $c['ecourier_base_url'],
            'ecourier_user_id'             => $c['ecourier_user_id'],
            'ecourier_api_key_masked'      => self::maskSecret((string)$c['ecourier_api_key']),
            'ecourier_api_secret_masked'   => self::maskSecret((string)$c['ecourier_api_secret']),
            'ecourier_configured'          => (trim((string)$c['ecourier_api_key']) !== '' && trim((string)$c['ecourier_api_secret']) !== '' && trim((string)$c['ecourier_user_id']) !== ''),
            'steadfast_base_url'           => $c['steadfast_base_url'],
            'steadfast_fraud_endpoint'     => $c['steadfast_fraud_endpoint'],
            'steadfast_api_key_masked'     => self::maskSecret((string)$c['steadfast_api_key']),
            'steadfast_configured'         => (trim((string)$c['steadfast_api_key']) !== '' && trim((string)$c['steadfast_fraud_endpoint']) !== ''),
            'pathao_base_url'              => $c['pathao_base_url'],
            'pathao_fraud_endpoint'        => $c['pathao_fraud_endpoint'],
            'pathao_configured'            => (trim((string)$c['pathao_access_token']) !== '' && trim((string)$c['pathao_fraud_endpoint']) !== ''),
            'redx_base_url'                => $c['redx_base_url'],
            'redx_fraud_endpoint'          => $c['redx_fraud_endpoint'],
            'redx_configured'              => (trim((string)$c['redx_api_token']) !== '' && trim((string)$c['redx_fraud_endpoint']) !== ''),
            'paperfly_base_url'            => $c['paperfly_base_url'],
            'paperfly_fraud_endpoint'      => $c['paperfly_fraud_endpoint'],
            'paperfly_configured'          => (trim((string)$c['paperfly_api_key']) !== '' && trim((string)$c['paperfly_fraud_endpoint']) !== ''),
            'carrybee_base_url'            => $c['carrybee_base_url'],
            'carrybee_client_context'      => $c['carrybee_client_context'],
            'carrybee_fraud_endpoint'      => $c['carrybee_fraud_endpoint'],
            'carrybee_configured'          => (trim((string)$c['carrybee_client_id']) !== '' && trim((string)$c['carrybee_fraud_endpoint']) !== ''),
            'custom_fraud_name'            => $c['custom_fraud_name'],
            'custom_fraud_url'             => $c['custom_fraud_url'],
            'custom_fraud_header'          => $c['custom_fraud_header'],
            'custom_fraud_api_key_masked'  => self::maskSecret((string)$c['custom_fraud_api_key']),
            'custom_fraud_configured'      => (trim((string)$c['custom_fraud_url']) !== '' && trim((string)$c['custom_fraud_api_key']) !== ''),
            'woocommerce_url'              => $c['woocommerce_url'],
            'woocommerce_key_masked'       => self::maskSecret((string)$c['woocommerce_consumer_key']),
            'woocommerce_configured'       => (trim((string)$c['woocommerce_url']) !== '' && trim((string)$c['woocommerce_consumer_key']) !== '' && trim((string)$c['woocommerce_consumer_secret']) !== ''),
        ];
    }

    /**
     * Generic HTTP JSON request helper (cURL) with status code + error reporting
     */
    protected static function httpRequestJson(string $method, string $url, array $headers = [], $body = null, int $timeoutSec = 15, array $basicAuth = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'cURL extension unavailable on server', 'json' => null, 'raw' => ''];
        }
        $ch = curl_init($url);
        $hdrs = array_merge(['Accept: application/json'], $headers);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeoutSec),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'DreamCartBD-FraudEngine/2.0 (PHP)',
        ];

        $upper = strtoupper($method);
        if ($upper === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $jsonBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $opts[CURLOPT_POSTFIELDS] = $jsonBody;
                $hdrs[] = 'Content-Type: application/json';
            }
        } elseif ($upper !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $upper;
        }

        if (!empty($basicAuth) && count($basicAuth) === 2) {
            $opts[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
        }

        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'http_code' => $code, 'error' => $err ?: ('cURL error #' . $errno), 'json' => null, 'raw' => ''];
        }

        $decoded = @json_decode((string)$raw, true);
        $ok = ($code >= 200 && $code < 300);
        return [
            'ok'        => $ok,
            'http_code' => $code,
            'error'     => $ok ? '' : ('HTTP ' . $code),
            'json'      => is_array($decoded) ? $decoded : null,
            'raw'       => substr((string)$raw, 0, 500),
        ];
    }

    /**
     * Complaints CRUD (Server-side persistent)
     */
    public static function getComplaints(): array
    {
        $age = 0;
        $list = DcbdCacheEngine::readCache(self::COMPLAINTS_COLLECTION, [], $age);
        return is_array($list) ? $list : [];
    }

    public static function addComplaint(array $payload): array
    {
        $phone = self::normalizePhone((string)($payload['phone'] ?? ''));
        $details = trim((string)($payload['details'] ?? ''));
        if ($phone === '' || $details === '') {
            return ['success' => false, 'message' => 'Phone and details are required'];
        }
        $item = [
            'id'            => !empty($payload['id']) ? (string)$payload['id'] : ('CMP-' . substr((string)time(), -4) . mt_rand(10, 99)),
            'phone'         => $phone,
            'customer_name' => trim((string)($payload['customer_name'] ?? 'অজ্ঞাত কাস্টমার')),
            'courier'       => trim((string)($payload['courier'] ?? 'Steadfast Courier')),
            'seller_name'   => trim((string)($payload['seller_name'] ?? 'Dream Cart BD Seller')),
            'severity'      => trim((string)($payload['severity'] ?? 'High Risk')),
            'details'       => $details,
            'date'          => !empty($payload['date']) ? (string)$payload['date'] : date('Y-m-d'),
            'created_at'    => date('c'),
        ];
        $list = self::getComplaints();
        array_unshift($list, $item);
        DcbdCacheEngine::writeCache(self::COMPLAINTS_COLLECTION, $list, true);
        return ['success' => true, 'item' => $item, 'items' => $list];
    }

    /**
     * Blocked Numbers Registry
     */
    public static function getBlockedNumbers(): array
    {
        $age = 0;
        $list = DcbdCacheEngine::readCache(self::BLOCKED_COLLECTION, [], $age);
        return is_array($list) ? $list : [];
    }

    public static function toggleBlockPhone(string $rawPhone, string $reason = ''): array
    {
        $phone = self::normalizePhone($rawPhone);
        if ($phone === '') {
            return ['success' => false, 'message' => 'Invalid phone number'];
        }
        $list = self::getBlockedNumbers();
        $foundIdx = -1;
        foreach ($list as $idx => $entry) {
            if (is_array($entry) && self::normalizePhone((string)($entry['phone'] ?? '')) === $phone) {
                $foundIdx = $idx;
                break;
            }
        }
        if ($foundIdx >= 0) {
            array_splice($list, $foundIdx, 1);
            $blocked = false;
        } else {
            array_unshift($list, [
                'phone'      => $phone,
                'reason'     => trim($reason) !== '' ? trim($reason) : 'Manual Admin Block',
                'blocked_at' => date('c'),
            ]);
            $blocked = true;
        }
        DcbdCacheEngine::writeCache(self::BLOCKED_COLLECTION, $list, true);
        return ['success' => true, 'phone' => $phone, 'blocked' => $blocked, 'items' => $list];
    }

    /**
     * Extract numeric courier stats from FraudBD JSON response (handles multiple documented shapes)
     */
    protected static function parseFraudBdResponse(array $json): array
    {
        $parsedCouriers = [];
        $candidates = [];

        if (isset($json['data']) && is_array($json['data'])) {
            $data = $json['data'];
            if (isset($data['Summaries']) && is_array($data['Summaries'])) {
                $candidates = $data['Summaries'];
            } elseif (isset($data['courierData']) && is_array($data['courierData'])) {
                $candidates = $data['courierData'];
            } elseif (isset($data['couriers']) && is_array($data['couriers'])) {
                $candidates = $data['couriers'];
            } else {
                $candidates = $data;
            }
        } elseif (isset($json['Summaries']) && is_array($json['Summaries'])) {
            $candidates = $json['Summaries'];
        } elseif (isset($json['courierData']) && is_array($json['courierData'])) {
            $candidates = $json['courierData'];
        } else {
            $candidates = $json;
        }

        $skipKeys = ['summary', 'total', 'total_summary', 'status', 'success', 'message', 'phone', 'phone_number', 'error', 'code'];

        foreach ($candidates as $k => $v) {
            if (!is_array($v)) continue;
            $kLow = strtolower((string)$k);
            if (in_array($kLow, $skipKeys, true)) continue;

            // Check if this sub-array looks like a courier stats object
            $hasStats = isset($v['total']) || isset($v['total_parcel']) || isset($v['total_parcels']) ||
                        isset($v['success']) || isset($v['delivered']) || isset($v['success_parcel']) ||
                        isset($v['cancel']) || isset($v['cancelled']) || isset($v['cancelled_parcel']) ||
                        isset($v['customer_rating']) || isset($v['rating']);
            if (!$hasStats) continue;

            $cNameRaw = isset($v['name']) ? (string)$v['name'] : (isset($v['courier']) ? (string)$v['courier'] : (string)$k);
            $cKey = self::detectCourierKey($cNameRaw);

            $total = (int)($v['total'] ?? $v['total_parcel'] ?? $v['total_parcels'] ?? 0);
            $success = (int)($v['success'] ?? $v['delivered'] ?? $v['success_parcel'] ?? $v['delivered_parcels'] ?? 0);
            $cancel = (int)($v['cancel'] ?? $v['cancelled'] ?? $v['cancelled_parcel'] ?? $v['returned'] ?? 0);
            if ($total === 0 && ($success > 0 || $cancel > 0)) {
                $total = $success + $cancel;
            }
            $rating = '';
            if (!empty($v['customer_rating'])) {
                $rating = (string)$v['customer_rating'];
            } elseif (!empty($v['rating'])) {
                $rating = (string)$v['rating'];
            }

            $parsedCouriers[$cKey] = [
                'key'     => $cKey,
                'name'    => self::getCourierDisplayName($cKey, ucfirst($cNameRaw)),
                'total'   => $total,
                'success' => $success,
                'cancel'  => $cancel,
                'rating'  => $rating,
                'source'  => 'FraudBD API',
            ];
        }

        return $parsedCouriers;
    }

    /**
     * Parse generic courier/fraud API response into normalized row(s)
     */
    protected static function parseGenericFraudResponse(array $json, string $fallbackKey, string $fallbackName, string $sourceLabel): array
    {
        // First try multi-courier shape
        $multi = self::parseFraudBdResponse($json);
        if (!empty($multi)) {
            foreach ($multi as $mk => $mv) {
                $multi[$mk]['source'] = $sourceLabel;
            }
            return $multi;
        }

        // Single-courier shape
        $target = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : $json;
        $total = (int)($target['total'] ?? $target['total_parcels'] ?? $target['total_orders'] ?? 0);
        $success = (int)($target['success'] ?? $target['delivered'] ?? $target['completed'] ?? 0);
        $cancel = (int)($target['cancel'] ?? $target['cancelled'] ?? $target['returned'] ?? 0);
        if ($total === 0 && ($success > 0 || $cancel > 0)) {
            $total = $success + $cancel;
        }
        $rating = (string)($target['customer_rating'] ?? $target['rating'] ?? $target['customer_status'] ?? '');
        if ($total > 0 || $rating !== '') {
            return [
                $fallbackKey => [
                    'key'     => $fallbackKey,
                    'name'    => $fallbackName,
                    'total'   => $total,
                    'success' => $success,
                    'cancel'  => $cancel,
                    'rating'  => $rating,
                    'source'  => $sourceLabel,
                ],
            ];
        }
        return [];
    }

    /**
     * Run Unified Multi-Source Fraud Check for a single phone number
     */
    public static function check(string $rawPhone, array $options = []): array
    {
        $phone = self::normalizePhone($rawPhone);
        if ($phone === '') {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => 'অনুগ্রহ করে সঠিক ১১ ডিজিটের মোবাইল নাম্বার দিন।',
            ];
        }

        $cfg = self::getConfig();
        $forceRefresh = !empty($options['force']);
        $timeout = max(3, min(25, (int)($cfg['timeout_sec'] ?? 15)));
        $cacheMinutes = max(0, (int)($cfg['cache_minutes'] ?? 60));

        // 1. Always compute fresh Website Internal Orders (sub-millisecond local read!)
        $orders = DcbdCacheEngine::getOrders(false);
        $ageInc = 0;
        $incompleteOrders = DcbdCacheEngine::readCache('incomplete_orders', [], $ageInc);
        $matchedCustomerName = '';
        $siteTotal = 0;
        $siteSuccess = 0;
        $siteCancel = 0;
        $siteDelivered = 0;
        $sitePending = 0;
        $internalByCourier = [];

        $cancelStatuses = ['cancelled', 'canceled', 'returned', 'return', 'failed', 'refunded', 'rejected', 'fake'];
        $deliveredStatuses = ['delivered', 'completed', 'complete', 'success'];

        if (is_array($orders)) {
            foreach ($orders as $o) {
                if (!is_array($o)) continue;
                $oPhone = self::normalizePhone((string)($o['phone'] ?? $o['mobile'] ?? $o['customer_phone'] ?? ''));
                if ($oPhone !== $phone) continue;

                $siteTotal++;
                if ($matchedCustomerName === '' && !empty($o['customer_name'])) {
                    $matchedCustomerName = trim((string)$o['customer_name']);
                }

                $st = strtolower(trim((string)($o['order_status'] ?? $o['status'] ?? 'pending')));
                $isCancel = in_array($st, $cancelStatuses, true);
                $isDeliv  = in_array($st, $deliveredStatuses, true);

                if ($isCancel) {
                    $siteCancel++;
                } else {
                    $siteSuccess++;
                    if ($isDeliv) {
                        $siteDelivered++;
                    } else {
                        $sitePending++;
                    }
                }

                // Track per-courier breakdown from website's own dispatched orders
                $cName = trim((string)($o['courier_name'] ?? $o['courier'] ?? ''));
                if ($cName !== '' && strtolower($cName) !== 'n/a' && strtolower($cName) !== 'none') {
                    $ck = self::detectCourierKey($cName);
                    if (!isset($internalByCourier[$ck])) {
                        $internalByCourier[$ck] = [
                            'key'     => $ck,
                            'name'    => self::getCourierDisplayName($ck, $cName),
                            'total'   => 0,
                            'success' => 0,
                            'cancel'  => 0,
                        ];
                    }
                    $internalByCourier[$ck]['total']++;
                    if ($isCancel) {
                        $internalByCourier[$ck]['cancel']++;
                    } else {
                        $internalByCourier[$ck]['success']++;
                    }
                }
            }
        }

        // Count incomplete/abandoned checkouts for this phone
        $incompleteCount = 0;
        if (is_array($incompleteOrders)) {
            foreach ($incompleteOrders as $io) {
                if (!is_array($io)) continue;
                $ioPhone = self::normalizePhone((string)($io['phone'] ?? $io['mobile'] ?? ''));
                if ($ioPhone === $phone) {
                    $incompleteCount++;
                    if ($matchedCustomerName === '' && !empty($io['customer_name'])) {
                        $matchedCustomerName = trim((string)$io['customer_name']);
                    }
                }
            }
        }

        // 2. Check server-side external API lookup cache (default 60 min per fraud check.md)
        $externalData = null;
        $fromCache = false;
        if (!$forceRefresh && $cacheMinutes > 0) {
            $cacheAge = 0;
            $lookupCache = DcbdCacheEngine::readCache(self::CACHE_COLLECTION, [], $cacheAge);
            if (is_array($lookupCache) && isset($lookupCache[$phone]) && is_array($lookupCache[$phone])) {
                $entry = $lookupCache[$phone];
                $ts = (int)($entry['cached_at_ts'] ?? 0);
                if ($ts > 0 && (time() - $ts) < ($cacheMinutes * 60)) {
                    $externalData = $entry;
                    $fromCache = true;
                }
            }
        }

        // 3. Query External APIs if not cached (graceful partial results if any API fails)
        if ($externalData === null) {
            $providersStatus = [];
            $externalCouriers = [];
            $ecourierStatus = '';
            $ecourierMessages = [];
            $ecourierWarning = false;
            $marketplaceRows = [];

            if (!empty($cfg['enabled']) && self::isValidBdPhone($phone)) {
                // 3A. FraudBD Multi-Courier API (https://fraudbd.com/api/check-courier-info)
                if (trim((string)$cfg['fraudbd_api_key']) !== '') {
                    $fbdUrl = rtrim((string)$cfg['fraudbd_base_url'], '/') . '/api/check-courier-info';
                    $res = self::httpRequestJson(
                        'POST',
                        $fbdUrl,
                        ['api_key: ' . trim((string)$cfg['fraudbd_api_key'])],
                        ['phone_number' => $phone],
                        $timeout
                    );
                    if ($res['ok'] && is_array($res['json'])) {
                        $parsed = self::parseFraudBdResponse($res['json']);
                        foreach ($parsed as $ck => $row) {
                            $externalCouriers[$ck] = $row;
                        }
                        $providersStatus[] = [
                            'provider' => 'FraudBD',
                            'status'   => 'success',
                            'message'  => count($parsed) > 0 ? (count($parsed) . ' টি কুরিয়ার ডেটা পাওয়া গেছে') : 'কোনো কুরিয়ার রেকর্ড পাওয়া যায়নি',
                        ];
                    } else {
                        $providersStatus[] = [
                            'provider' => 'FraudBD',
                            'status'   => 'error',
                            'message'  => $res['error'] ?: 'API response failed',
                        ];
                    }
                } else {
                    $providersStatus[] = [
                        'provider' => 'FraudBD',
                        'status'   => 'not_configured',
                        'message'  => 'API Key সেট করা হয়নি',
                    ];
                }

                // 3B. eCourier Direct Fraud Status Check API (POST /fraud-status-check)
                if (trim((string)$cfg['ecourier_api_key']) !== '' && trim((string)$cfg['ecourier_api_secret']) !== '' && trim((string)$cfg['ecourier_user_id']) !== '') {
                    $ecUrl = rtrim((string)$cfg['ecourier_base_url'], '/') . '/fraud-status-check';
                    $res = self::httpRequestJson(
                        'POST',
                        $ecUrl,
                        [
                            'API-KEY: ' . trim((string)$cfg['ecourier_api_key']),
                            'API-SECRET: ' . trim((string)$cfg['ecourier_api_secret']),
                            'USER-ID: ' . trim((string)$cfg['ecourier_user_id']),
                        ],
                        ['number' => $phone],
                        $timeout
                    );
                    if ($res['ok'] && is_array($res['json'])) {
                        $ecourierStatus = trim((string)($res['json']['customer_status'] ?? ''));
                        if (isset($res['json']['message']) && is_array($res['json']['message'])) {
                            $ecourierMessages = $res['json']['message'];
                        } elseif (!empty($res['json']['message'])) {
                            $ecourierMessages = [(string)$res['json']['message']];
                        }
                        $ecourierWarning = (stripos($ecourierStatus, 'warning') !== false || stripos($ecourierStatus, 'fraud') !== false);
                        $providersStatus[] = [
                            'provider'        => 'eCourier',
                            'status'          => 'success',
                            'customer_status' => $ecourierStatus ?: 'Checked',
                            'message'         => $ecourierStatus ?: 'Verified',
                        ];
                    } else {
                        $providersStatus[] = [
                            'provider' => 'eCourier',
                            'status'   => 'error',
                            'message'  => $res['error'] ?: 'API response failed',
                        ];
                    }
                } else {
                    $providersStatus[] = [
                        'provider' => 'eCourier',
                        'status'   => 'not_configured',
                        'message'  => 'API Key / Secret / User-ID সেট করা হয়নি',
                    ];
                }

                // 3C. Direct Courier Optional Fraud Endpoints (Steadfast, Pathao, RedX, Paperfly, CarryBee)
                $directConfigs = [
                    [
                        'key'      => 'steadfast',
                        'name'     => 'Steadfast Courier',
                        'base'     => (string)$cfg['steadfast_base_url'],
                        'endpoint' => (string)$cfg['steadfast_fraud_endpoint'],
                        'headers'  => [
                            'API-KEY: ' . trim((string)$cfg['steadfast_api_key']),
                            'API-SECRET-KEY: ' . trim((string)$cfg['steadfast_api_secret']),
                        ],
                        'ready'    => trim((string)$cfg['steadfast_api_key']) !== '' && trim((string)$cfg['steadfast_fraud_endpoint']) !== '',
                    ],
                    [
                        'key'      => 'pathao',
                        'name'     => 'Pathao Courier',
                        'base'     => (string)$cfg['pathao_base_url'],
                        'endpoint' => (string)$cfg['pathao_fraud_endpoint'],
                        'headers'  => ['Authorization: Bearer ' . trim((string)$cfg['pathao_access_token'])],
                        'ready'    => trim((string)$cfg['pathao_access_token']) !== '' && trim((string)$cfg['pathao_fraud_endpoint']) !== '',
                    ],
                    [
                        'key'      => 'redx',
                        'name'     => 'RedX Delivery',
                        'base'     => (string)$cfg['redx_base_url'],
                        'endpoint' => (string)$cfg['redx_fraud_endpoint'],
                        'headers'  => ['API-ACCESS-TOKEN: Bearer ' . trim((string)$cfg['redx_api_token'])],
                        'ready'    => trim((string)$cfg['redx_api_token']) !== '' && trim((string)$cfg['redx_fraud_endpoint']) !== '',
                    ],
                    [
                        'key'      => 'paperfly',
                        'name'     => 'Paperfly Courier',
                        'base'     => (string)$cfg['paperfly_base_url'],
                        'endpoint' => (string)$cfg['paperfly_fraud_endpoint'],
                        'headers'  => ['Authorization: Bearer ' . trim((string)$cfg['paperfly_api_key'])],
                        'ready'    => trim((string)$cfg['paperfly_api_key']) !== '' && trim((string)$cfg['paperfly_fraud_endpoint']) !== '',
                    ],
                    [
                        'key'      => 'carrybee',
                        'name'     => 'CarryBee',
                        'base'     => (string)$cfg['carrybee_base_url'],
                        'endpoint' => (string)$cfg['carrybee_fraud_endpoint'],
                        'headers'  => [
                            'Client-ID: ' . trim((string)$cfg['carrybee_client_id']),
                            'Client-Secret: ' . trim((string)$cfg['carrybee_client_secret']),
                            'Client-Context: ' . trim((string)$cfg['carrybee_client_context']),
                        ],
                        'ready'    => trim((string)$cfg['carrybee_client_id']) !== '' && trim((string)$cfg['carrybee_fraud_endpoint']) !== '',
                    ],
                ];

                foreach ($directConfigs as $dc) {
                    if (!$dc['ready']) continue;
                    $ep = str_replace('{phone}', urlencode($phone), trim($dc['endpoint']));
                    $fullUrl = (strpos($ep, 'http://') === 0 || strpos($ep, 'https://') === 0)
                        ? $ep
                        : (rtrim($dc['base'], '/') . '/' . ltrim($ep, '/'));
                    $res = self::httpRequestJson('POST', $fullUrl, $dc['headers'], ['phone' => $phone, 'phone_number' => $phone, 'number' => $phone], $timeout);
                    if ($res['ok'] && is_array($res['json'])) {
                        $parsed = self::parseGenericFraudResponse($res['json'], $dc['key'], $dc['name'], $dc['name'] . ' Direct API');
                        foreach ($parsed as $pk => $pv) {
                            $externalCouriers[$pk] = $pv;
                        }
                        $providersStatus[] = ['provider' => $dc['name'], 'status' => 'success', 'message' => 'Direct API checked'];
                    } else {
                        $providersStatus[] = ['provider' => $dc['name'], 'status' => 'error', 'message' => $res['error'] ?: 'Direct API error'];
                    }
                }

                // 3D. Custom / Fraud.bd Multi-Courier API (only if admin configured URL + API Key)
                if (trim((string)$cfg['custom_fraud_url']) !== '' && trim((string)$cfg['custom_fraud_api_key']) !== '') {
                    $cName = trim((string)$cfg['custom_fraud_name']) ?: 'Fraud.bd';
                    $cHeader = trim((string)$cfg['custom_fraud_header']) ?: 'api_key';
                    $hdrLine = (strtolower($cHeader) === 'authorization')
                        ? ('Authorization: Bearer ' . trim((string)$cfg['custom_fraud_api_key']))
                        : ($cHeader . ': ' . trim((string)$cfg['custom_fraud_api_key']));
                    $res = self::httpRequestJson(
                        'POST',
                        trim((string)$cfg['custom_fraud_url']),
                        [$hdrLine],
                        ['phone_number' => $phone, 'phone' => $phone],
                        $timeout
                    );
                    if ($res['ok'] && is_array($res['json'])) {
                        $parsed = self::parseGenericFraudResponse($res['json'], 'custom_fraud', $cName, $cName . ' API');
                        foreach ($parsed as $pk => $pv) {
                            if (!isset($externalCouriers[$pk]) || (int)$pv['total'] > (int)$externalCouriers[$pk]['total']) {
                                $externalCouriers[$pk] = $pv;
                            }
                        }
                        $providersStatus[] = ['provider' => $cName, 'status' => 'success', 'message' => 'API checked'];
                    } else {
                        $providersStatus[] = ['provider' => $cName, 'status' => 'error', 'message' => $res['error'] ?: 'API error'];
                    }
                }

                // 3E. WooCommerce / Dokan / WCFM Connected Store Orders (GET /wp-json/wc/v3/orders?search={phone})
                if (trim((string)$cfg['woocommerce_url']) !== '' && trim((string)$cfg['woocommerce_consumer_key']) !== '' && trim((string)$cfg['woocommerce_consumer_secret']) !== '') {
                    $wcUrl = rtrim((string)$cfg['woocommerce_url'], '/') . '/wp-json/wc/v3/orders?search=' . urlencode($phone) . '&per_page=50';
                    $res = self::httpRequestJson(
                        'GET',
                        $wcUrl,
                        [],
                        null,
                        $timeout,
                        [trim((string)$cfg['woocommerce_consumer_key']), trim((string)$cfg['woocommerce_consumer_secret'])]
                    );
                    if ($res['ok'] && is_array($res['json'])) {
                        $wcTot = 0;
                        $wcSuc = 0;
                        $wcCan = 0;
                        foreach ($res['json'] as $wo) {
                            if (!is_array($wo)) continue;
                            $bPhone = self::normalizePhone((string)($wo['billing']['phone'] ?? ''));
                            if ($bPhone !== '' && $bPhone !== $phone) continue;
                            $wcTot++;
                            $wst = strtolower((string)($wo['status'] ?? ''));
                            if (in_array($wst, ['cancelled', 'refunded', 'failed', 'trash'], true)) {
                                $wcCan++;
                            } else {
                                $wcSuc++;
                            }
                        }
                        $marketplaceRows[] = [
                            'key'      => 'woocommerce',
                            'name'     => '🛒 WooCommerce / Marketplace Orders',
                            'category' => 'marketplace',
                            'total'    => $wcTot,
                            'success'  => $wcSuc,
                            'cancel'   => $wcCan,
                            'ratio'    => $wcTot > 0 ? (int)round(($wcSuc / $wcTot) * 100) : null,
                            'rating'   => '',
                            'source'   => 'WooCommerce REST API v3',
                            'connected'=> true,
                        ];
                        $providersStatus[] = ['provider' => 'WooCommerce', 'status' => 'success', 'message' => $wcTot . ' orders found'];
                    } else {
                        $providersStatus[] = ['provider' => 'WooCommerce', 'status' => 'error', 'message' => $res['error'] ?: 'API error'];
                    }
                }
            }

            $externalData = [
                'cached_at_ts'      => time(),
                'providers_status'  => $providersStatus,
                'external_couriers' => $externalCouriers,
                'ecourier_status'   => $ecourierStatus,
                'ecourier_messages' => $ecourierMessages,
                'ecourier_warning'  => $ecourierWarning,
                'marketplace_rows'  => $marketplaceRows,
            ];

            // Save to server-side cache if any live API was actually configured & queried
            $hadLiveQuery = false;
            foreach ($providersStatus as $ps) {
                if (isset($ps['status']) && $ps['status'] === 'success') {
                    $hadLiveQuery = true;
                    break;
                }
            }
            if ($hadLiveQuery && $cacheMinutes > 0) {
                $cacheAge = 0;
                $lookupCache = DcbdCacheEngine::readCache(self::CACHE_COLLECTION, [], $cacheAge);
                if (!is_array($lookupCache)) $lookupCache = [];
                $lookupCache[$phone] = $externalData;
                // Keep cache compact (max 300 recent phones)
                if (count($lookupCache) > 300) {
                    $lookupCache = array_slice($lookupCache, -300, null, true);
                }
                DcbdCacheEngine::writeCache(self::CACHE_COLLECTION, $lookupCache, false);
            }
        }

        // 4. Build Unified Rows Table: Website Row FIRST, then every Courier & Marketplace Row!
        $rows = [];

        // Row 1: Website Orders (Dream Cart BD)
        $webRatio = $siteTotal > 0 ? (int)round(($siteSuccess / $siteTotal) * 100) : null;
        $rows[] = [
            'key'           => 'website',
            'name'          => '🌐 ওয়েবসাইট অর্ডার (Dream Cart BD)',
            'category'      => 'website',
            'isWebsite'     => true,
            'total'         => $siteTotal,
            'success'       => $siteSuccess,
            'cancel'        => $siteCancel,
            'delivered'     => $siteDelivered,
            'pending'       => $sitePending,
            'ratio'         => $webRatio,
            'rating'        => '',
            'status_label'  => $siteTotal === 0 ? 'কোনো অর্ডার নেই' : ($webRatio >= 80 ? 'Safe ✓' : ($webRatio >= 60 ? 'Moderate' : 'High Risk ⚠️')),
            'source'        => 'Website Database (Live)',
            'connected'     => true,
        ];

        // Collect all courier keys to display:
        // Core Bangladesh couriers + any connected admin couriers + any couriers returned by APIs + any internal courier orders
        $coreKeys = ['steadfast', 'pathao', 'redx', 'ecourier', 'paperfly', 'carrybee'];
        $orderedKeys = $coreKeys;

        $adminCouriers = DcbdCacheEngine::getCouriers();
        $connectedAdminKeys = [];
        if (is_array($adminCouriers)) {
            foreach ($adminCouriers as $ac) {
                if (!is_array($ac)) continue;
                $ck = self::detectCourierKey((string)($ac['name'] ?? ''));
                if ($ck !== '' && !in_array($ck, $orderedKeys, true)) {
                    $orderedKeys[] = $ck;
                }
                if (strtolower((string)($ac['status'] ?? 'connected')) !== 'paused') {
                    $connectedAdminKeys[$ck] = true;
                }
            }
        }

        $extCouriers = is_array($externalData['external_couriers'] ?? null) ? $externalData['external_couriers'] : [];
        foreach ($extCouriers as $ek => $ev) {
            if (!in_array($ek, $orderedKeys, true)) {
                $orderedKeys[] = $ek;
            }
        }
        foreach ($internalByCourier as $ik => $iv) {
            if (!in_array($ik, $orderedKeys, true)) {
                $orderedKeys[] = $ik;
            }
        }

        $ecourierStatus = (string)($externalData['ecourier_status'] ?? '');
        $ecourierMessages = is_array($externalData['ecourier_messages'] ?? null) ? $externalData['ecourier_messages'] : [];
        $ecourierWarning = !empty($externalData['ecourier_warning']);
        $pathaoRiskyRating = false;

        foreach ($orderedKeys as $ck) {
            $ext = isset($extCouriers[$ck]) ? $extCouriers[$ck] : null;
            $int = isset($internalByCourier[$ck]) ? $internalByCourier[$ck] : null;

            $cTotal = $ext ? (int)$ext['total'] : 0;
            $cSuccess = $ext ? (int)$ext['success'] : 0;
            $cCancel = $ext ? (int)$ext['cancel'] : 0;
            $cRating = $ext ? (string)($ext['rating'] ?? '') : '';
            $cSource = $ext ? (string)($ext['source'] ?? 'API') : '';

            // If external API had no count for this courier, use internal website dispatched orders for this courier
            if ($cTotal === 0 && $int && (int)$int['total'] > 0) {
                $cTotal = (int)$int['total'];
                $cSuccess = (int)$int['success'];
                $cCancel = (int)$int['cancel'];
                $cSource = 'ওয়েবসাইট কুরিয়ার লগ';
            }

            // eCourier direct fraud-status-check status
            if ($ck === 'ecourier' && $ecourierStatus !== '') {
                $cRating = $ecourierStatus;
                $cSource = $cSource !== '' ? ($cSource . ' + eCourier Fraud API') : 'eCourier Fraud API';
            }

            if ($ck === 'pathao' && $cRating !== '' && stripos($cRating, 'risk') !== false) {
                $pathaoRiskyRating = true;
            }

            $cRatio = $cTotal > 0 ? (int)round(($cSuccess / $cTotal) * 100) : null;
            $cDisplayName = $ext ? (string)$ext['name'] : ($int ? (string)$int['name'] : self::getCourierDisplayName($ck));

            // Determine clean status label (never mark 0-order courier as FRAUD)
            if ($ck === 'ecourier' && $ecourierWarning) {
                $statusLabel = 'Warning! ⚠️';
            } elseif ($ck === 'ecourier' && stripos($ecourierStatus, 'verified') !== false) {
                $statusLabel = 'Verified ✓';
            } elseif ($cTotal > 0) {
                $statusLabel = $cRatio >= 80 ? 'Safe ✓' : ($cRatio >= 60 ? 'Moderate' : 'High Risk ⚠️');
            } elseif ($cRating !== '') {
                $statusLabel = $cRating;
            } else {
                $statusLabel = 'ডেটা নেই';
            }

            $rows[] = [
                'key'          => $ck,
                'name'         => $cDisplayName,
                'category'     => 'courier',
                'isWebsite'    => false,
                'total'        => $cTotal,
                'success'      => $cSuccess,
                'cancel'       => $cCancel,
                'ratio'        => $cRatio,
                'rating'       => $cRating,
                'status_label' => $statusLabel,
                'source'       => $cSource !== '' ? $cSource : (isset($connectedAdminKeys[$ck]) ? 'Connected Courier' : 'API Ready'),
                'connected'    => ($ext !== null || isset($connectedAdminKeys[$ck]) || ($ck === 'ecourier' && $ecourierStatus !== '')),
            ];
        }

        // Append any connected Marketplace rows (e.g. WooCommerce)
        if (!empty($externalData['marketplace_rows']) && is_array($externalData['marketplace_rows'])) {
            foreach ($externalData['marketplace_rows'] as $mr) {
                $mr['status_label'] = ($mr['total'] ?? 0) === 0
                    ? 'কোনো অর্ডার নেই'
                    : (($mr['ratio'] ?? 0) >= 80 ? 'Safe ✓' : (($mr['ratio'] ?? 0) >= 60 ? 'Moderate' : 'High Risk ⚠️'));
                $rows[] = $mr;
            }
        }

        // 5. Internal Seller Complaints & Blocked Status
        $allComplaints = self::getComplaints();
        $phoneComplaints = [];
        foreach ($allComplaints as $cmp) {
            if (!is_array($cmp)) continue;
            if (self::normalizePhone((string)($cmp['phone'] ?? '')) === $phone) {
                $phoneComplaints[] = $cmp;
                if ($matchedCustomerName === '' && !empty($cmp['customer_name'])) {
                    $matchedCustomerName = trim((string)$cmp['customer_name']);
                }
            }
        }

        $isBlocked = false;
        $blockReason = '';
        foreach (self::getBlockedNumbers() as $blk) {
            if (is_array($blk) && self::normalizePhone((string)($blk['phone'] ?? '')) === $phone) {
                $isBlocked = true;
                $blockReason = (string)($blk['reason'] ?? '');
                break;
            }
        }

        // 6. Grand Summary (ONLY actual numeric delivery counts — rating-only rows are NOT counted as parcels!)
        $grandTotal = 0;
        $grandSuccess = 0;
        $grandCancel = 0;
        foreach ($rows as $r) {
            $grandTotal += (int)$r['total'];
            $grandSuccess += (int)$r['success'];
            $grandCancel += (int)$r['cancel'];
        }
        $grandRatio = $grandTotal > 0 ? round(($grandSuccess / $grandTotal) * 100, 1) : null;
        $cancelRate = $grandTotal > 0 ? round(($grandCancel / $grandTotal) * 100, 1) : null;

        // 7. Fraud Risk Engine (Part 12 of fraud check.md: Score 0-100, UNKNOWN for new customers!)
        $hasAnySignal = ($grandTotal > 0 || $ecourierWarning || $pathaoRiskyRating || count($phoneComplaints) > 0 || $isBlocked);

        if (!$hasAnySignal) {
            $risk = [
                'score'          => null,
                'level'          => 'UNKNOWN',
                'label'          => 'নতুন কাস্টমার (No History)',
                'action'         => 'Call Confirmation / Normal',
                'recommendation' => 'UNKNOWN',
                'badge_class'    => 'badge-warning',
            ];
        } else {
            $score = 0;
            if ($grandTotal > 0) {
                // Base risk from cancellation rate (0 to 70 points)
                $score += (int)round(($cancelRate / 100) * 70);
                // Small sample dampening if only 1-2 total orders
                if ($grandTotal <= 2 && $grandCancel === 0) {
                    $score = max(5, $score);
                }
            } else {
                $score += 20;
            }
            if ($ecourierWarning) {
                $score += 30;
            }
            if ($pathaoRiskyRating) {
                $score += 20;
            }
            if (count($phoneComplaints) > 0) {
                $score += min(35, count($phoneComplaints) * 18);
            }
            if ($isBlocked) {
                $score = max($score, 85);
            }
            $score = max(0, min(100, $score));

            if ($score <= 10) {
                $level = 'VERY_LOW';
                $label = 'Very Low Risk (Safe)';
                $action = 'Normal';
                $rec = 'APPROVE';
                $bClass = 'badge-success';
            } elseif ($score <= 30) {
                $level = 'LOW';
                $label = 'Low Risk (Safe)';
                $action = 'Approve';
                $rec = 'APPROVE';
                $bClass = 'badge-success';
            } elseif ($score <= 50) {
                $level = 'MEDIUM';
                $label = 'Medium Risk';
                $action = 'Call Confirmation';
                $rec = 'CALL_CONFIRMATION';
                $bClass = 'badge-warning';
            } elseif ($score <= 75) {
                $level = 'HIGH';
                $label = 'High Risk';
                $action = 'Manual Review / Advance Payment';
                $rec = 'MANUAL_REVIEW';
                $bClass = 'badge-danger';
            } else {
                $level = 'VERY_HIGH';
                $label = 'Very High Risk';
                $action = 'Manual Review / COD Restriction';
                $rec = 'COD_RESTRICTION';
                $bClass = 'badge-danger';
            }

            $risk = [
                'score'          => $score,
                'level'          => $level,
                'label'          => $label,
                'action'         => $action,
                'recommendation' => $rec,
                'badge_class'    => $bClass,
            ];
        }

        // Format short status string for Order / Visitor badges
        if ($risk['level'] === 'UNKNOWN') {
            $shortStatus = 'New (No Data)';
        } elseif (in_array($risk['level'], ['HIGH', 'VERY_HIGH'], true)) {
            $shortStatus = 'High Risk (' . ($grandRatio !== null ? (int)round($grandRatio) . '%' : ('Score ' . $risk['score'])) . ')';
        } elseif ($risk['level'] === 'MEDIUM') {
            $shortStatus = 'Moderate (' . ($grandRatio !== null ? (int)round($grandRatio) . '%' : ('Score ' . $risk['score'])) . ')';
        } else {
            $shortStatus = 'Safe (' . ($grandRatio !== null ? (int)round($grandRatio) . '%' : 'Verified') . ')';
        }

        return [
            'success'           => true,
            'status'            => 'success',
            'phone'             => $phone,
            'valid_bd_phone'    => self::isValidBdPhone($phone),
            'customer_name'     => $matchedCustomerName !== '' ? $matchedCustomerName : 'নতুন / সাধারণ কাস্টমার',
            'checked_at'        => date('c'),
            'cached'            => $fromCache,
            'is_blocked'        => $isBlocked,
            'block_reason'      => $blockReason,
            'rows'              => $rows,
            'summary'           => [
                'total_parcels'     => $grandTotal,
                'delivered'         => $grandSuccess,
                'cancelled'         => $grandCancel,
                'returned'          => $grandCancel,
                'success_rate'      => $grandRatio,
                'cancellation_rate' => $cancelRate,
                'incomplete_orders' => $incompleteCount,
            ],
            'risk'              => $risk,
            'short_status'      => $shortStatus,
            'ecourier_status'   => $ecourierStatus,
            'ecourier_messages' => $ecourierMessages,
            'providers_status'  => $externalData['providers_status'] ?? [],
            'complaints'        => $phoneComplaints,
            'all_complaints'    => array_slice($allComplaints, 0, 50),
        ];
    }

    /**
     * Bulk check up to 30 unique phones
     */
    public static function bulkCheck(array $rawPhones, array $options = []): array
    {
        $unique = [];
        foreach ($rawPhones as $p) {
            $n = self::normalizePhone((string)$p);
            if ($n !== '' && !in_array($n, $unique, true)) {
                $unique[] = $n;
            }
            if (count($unique) >= 30) {
                break;
            }
        }
        $results = [];
        foreach ($unique as $phone) {
            $results[$phone] = self::check($phone, $options);
        }
        return [
            'success' => true,
            'status'  => 'success',
            'count'   => count($results),
            'results' => $results,
        ];
    }
}
