<?php
// ── Capturar cualquier error PHP y devolverlo como JSON ──
// Solo instalar handlers propios cuando NO estamos dentro de WordPress
if (!defined('ABSPATH')) {
    set_exception_handler(function($e) {
        http_response_code(500);
        @header('Content-Type: application/json');
        if (defined('LUNA_DEBUG') && LUNA_DEBUG) {
            echo json_encode([
                'error' => 'PHP Exception: ' . $e->getMessage(),
                'file'  => str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $e->getFile()),
                'line'  => $e->getLine(),
            ]);
        } else {
            echo json_encode(['error' => 'Error interno del servidor']);
        }
        exit;
    });
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false;
        throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
    }, E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// 1) Intentar cargar credenciales generadas por el plugin WP
$_luna_cred = __DIR__ . '/luna-wp-config.php';
if (file_exists($_luna_cred)) {
    require_once $_luna_cred;
}

// 2) Fallback: leer wp-config.php sin ejecutarlo (extrae solo los define)
if (!defined('DB_HOST')) {
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        $dir = dirname($dir);
        $candidate = $dir . '/wp-config.php';
        if (file_exists($candidate)) {
            $raw = file_get_contents($candidate);
            // Extract define('CONSTANT', 'value') — single-quoted values
            preg_match_all("/define\s*\(\s*['\"](\w+)['\"]\s*,\s*'([^']*)'\s*\)/", $raw, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                if (!defined($m[1])) define($m[1], $m[2]);
            }
            // Double-quoted values
            preg_match_all('/define\s*\(\s*[\'"](\w+)[\'"]\s*,\s*"([^"]*)"\s*\)/', $raw, $mm, PREG_SET_ORDER);
            foreach ($mm as $m) {
                if (!defined($m[1])) define($m[1], $m[2]);
            }
            // $table_prefix
            if (!defined('WP_TABLE_PREFIX')) {
                preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $pm);
                if ($pm) define('WP_TABLE_PREFIX', $pm[1]);
            }
            break;
        }
    }
    // Build LUNA_TB_PREFIX from WP prefix if not already defined
    if (!defined('LUNA_TB_PREFIX') && defined('WP_TABLE_PREFIX')) {
        define('LUNA_TB_PREFIX', WP_TABLE_PREFIX . 'luna_');
    }
    // wp-config.php uses DB_PASSWORD; alias to DB_PASS used by getDB()
    if (defined('DB_PASSWORD') && !defined('DB_PASS')) {
        define('DB_PASS', DB_PASSWORD);
    }
}

// 3) Defaults si aún no están definidas
if (!defined('DB_HOST'))        define('DB_HOST',        'localhost');
if (!defined('DB_CHARSET'))     define('DB_CHARSET',     'utf8mb4');
if (!defined('LUNA_TB_PREFIX')) define('LUNA_TB_PREFIX', 'wp_luna_');
if (!defined('SESSION_HOURS'))  define('SESSION_HOURS',  24);
if (!defined('LUNA_VERSION'))   define('LUNA_VERSION',   '2.0');
if (!defined('LUNA_LICENSE_KEY'))    define('LUNA_LICENSE_KEY',    '');
if (!defined('LUNA_LICENSE_SERVER')) define('LUNA_LICENSE_SERVER', 'https://websobreruedas.com/wp-json/luna-licenses/v1/verify');
if (!defined('LUNA_SITE_URL'))       define('LUNA_SITE_URL',   '');
if (!defined('LUNA_UPLOAD_URL'))     define('LUNA_UPLOAD_URL', '');
if (!defined('LUNA_CRON_SECRET'))    define('LUNA_CRON_SECRET', '');

// ── Funciones principales ─────────────────────────────────────────────────────

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        // Parse DB_HOST: may contain port (host:port) or socket (localhost:/path/socket)
        $host   = DB_HOST;
        $port   = '';
        $socket = '';
        if (strpos($host, ':/') !== false) {
            [$host, $socket] = explode(':', $host, 2);
        } elseif (preg_match('/^(.*):(\d+)$/', $host, $hm)) {
            $host = $hm[1];
            $port = $hm[2];
        }
        if ($socket)    $dsn = "mysql:unix_socket={$socket};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        elseif ($port)  $dsn = "mysql:host={$host};port={$port};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        else            $dsn = "mysql:host={$host};dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        if (defined('LUNA_DEBUG') && LUNA_DEBUG) {
            die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
        }
        die(json_encode(['error' => 'Error de conexión a la base de datos']));
    }
}

function tb($name) { return LUNA_TB_PREFIX . $name; }

function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonErr($msg, $code = 400) { jsonOut(['error' => $msg], $code); }

function getBearerToken() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v)
            if (strtolower($k) === 'authorization') { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    // Note: GET token is intentionally not supported (tokens in URLs leak via logs/referer)
    if (!empty($_COOKIE['luna_token'])) return trim($_COOKIE['luna_token']);
    return null;
}

function requireAuth() {
    $token = getBearerToken();
    if (!$token) jsonErr('No autenticado', 401);
    $db = getDB();
    $st = $db->prepare("SELECT u.id,u.username,u.email,u.name,u.cargo,u.dept,u.role,u.color,u.active,u.notes,u.photo,u.phone,u.whatsapp_apikey,u.telegram_chat_id,u.notification_channel,u.last_login,u.created_at FROM " . tb('sessions') . " s
        JOIN " . tb('users') . " u ON u.id = s.user_id
        WHERE s.token=? AND s.expires_at>NOW() AND u.active=1");
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) jsonErr('Sesión inválida o expirada', 401);
    return $user;
}

function requireAdmin() {
    $user = requireAuth();
    if ($user['role'] !== 'admin') jsonErr('Solo el administrador puede realizar esta acción', 403);
    return $user;
}

function getLicenseInfo() {
    static $info = null;
    if ($info !== null) return $info;
    $key = LUNA_LICENSE_KEY;
    if (!$key) { $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0, 'reason' => 'no_key']; return $info; }
    $cache_file = __DIR__ . '/luna-license-cache.json';
    if (file_exists($cache_file)) {
        $c = json_decode(file_get_contents($cache_file), true);
        if ($c && isset($c['expires']) && $c['expires'] > time()) { $info = $c; return $info; }
    }
    try {
        $domain  = defined('LUNA_SITE_URL') && LUNA_SITE_URL ? parse_url(LUNA_SITE_URL, PHP_URL_HOST) : ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '');
        $payload = json_encode(['license_key' => $key, 'domain' => $domain]);
        $r       = null;

        // Usar cURL si está disponible (más confiable que file_get_contents)
        if (function_exists('curl_init')) {
            $ch = curl_init(LUNA_LICENSE_SERVER);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $r    = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 429) {
                // Rate limited — use cached data if available, otherwise deny
                if (file_exists($cache_file)) {
                    $c = json_decode(file_get_contents($cache_file), true);
                    if ($c && is_array($c)) { $info = $c; return $info; }
                }
                $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0, 'reason' => 'rate_limited'];
                return $info;
            }
            if (!$r || $code < 200 || $code >= 300) $r = null;
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json",
                'content'       => $payload,
                'timeout'       => 8,
                'ignore_errors' => true,
            ]]);
            $r = @file_get_contents(LUNA_LICENSE_SERVER, false, $ctx);
        }

        $data = $r ? json_decode($r, true) : null;

        if ($data && isset($data['valid'])) {
            // Verificar firma HMAC — obligatoria si HMAC_SECRET está configurado
            if (defined('LUNA_HMAC_SECRET') && LUNA_HMAC_SECRET) {
                if (empty($data['hmac']) || empty($data['issued_at'])) {
                    // Respuesta sin firma cuando el servidor requiere firma — rechazar
                    $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0, 'reason' => 'missing_signature'];
                    return $info;
                }
            }
            if (!empty($data['hmac']) && !empty($data['issued_at']) && defined('LUNA_HMAC_SECRET') && LUNA_HMAC_SECRET) {
                $sign_payload = implode('|', [
                    $key,
                    $data['domain']     ?? $domain,
                    !empty($data['valid']) ? 'true' : 'false',
                    $data['plan']       ?? '',
                    $data['expires_at'] ?? '',
                    $data['issued_at'],
                ]);
                $expected = hash_hmac('sha256', $sign_payload, LUNA_HMAC_SECRET);
                if (!hash_equals($expected, $data['hmac'])) {
                    // Firma inválida — tratar como offline restrictivo
                    $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0,
                             'reason' => 'invalid_signature'];
                    return $info;
                }
            }
            $data['expires'] = time() + 86400 * (($data['grace'] ?? false) ? 1 : 30);
            @file_put_contents($cache_file, json_encode($data));
            $info = $data;
        } else {
            // Servidor inalcanzable — usar caché expirada (tolerancia a caídas temporales)
            if (file_exists($cache_file)) {
                $c = json_decode(file_get_contents($cache_file), true);
                if ($c && is_array($c)) { $info = $c; return $info; }
            }
            // Sin caché y servidor inalcanzable: denegar acceso
            $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0, 'reason' => 'server_unreachable'];
        }
    } catch (\Exception $e) {
        if (file_exists($cache_file)) {
            $c = @json_decode(@file_get_contents($cache_file), true);
            if ($c && is_array($c)) { $info = $c; return $info; }
        }
        $info = ['valid' => false, 'plan' => 'none', 'max_workspaces' => 0, 'max_users' => 0, 'reason' => 'exception'];
    }
    return $info;
}

// ── CORS headers ──────────────────────────────────────────────────────────────
$_cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_cors_allowed = array_filter(array_map('trim', [
    defined('LUNA_SITE_URL') ? LUNA_SITE_URL : '',
    defined('LUNA_SITE_URL') ? rtrim(LUNA_SITE_URL, '/') : '',
]));
// Also allow same-origin requests (no Origin header) and WordPress admin origin if defined
if (!$_cors_origin || in_array($_cors_origin, $_cors_allowed, true)) {
    $origin = $_cors_origin ?: (defined('LUNA_SITE_URL') ? LUNA_SITE_URL : '');
    if ($origin) header("Access-Control-Allow-Origin: $origin");
} else {
    // Origin not in allowlist — deny CORS (browsers will block the response)
    $origin = defined('LUNA_SITE_URL') ? LUNA_SITE_URL : '';
    if ($origin) header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Expose-Headers: Authorization');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}
