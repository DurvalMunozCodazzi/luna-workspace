<?php
require_once '../config.php';
$db = getDB();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// POST login
if ($method === 'POST' && $action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true);
    $login = trim($body['username'] ?? '');
    $pass  = trim($body['password'] ?? '');
    if (!$login || !$pass) jsonErr('Usuario y contraseña requeridos');

    // ── Rate limiting: máx 10 intentos fallidos por IP en 15 minutos ────────
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateKey = 'rate_login_' . substr(hash('sha256', $ip), 0, 20);
    $maxAttempts = 10;
    $windowSec   = 900; // 15 minutos
    $rateData    = null;
    try {
        $rateSt = $db->prepare("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key=? LIMIT 1");
        $rateSt->execute([$rateKey]);
        $rateRow = $rateSt->fetch();
        $rateData = $rateRow ? json_decode($rateRow['meta_value'], true) : null;
        if (!is_array($rateData)) $rateData = ['count' => 0, 'since' => time()];
        // Reset window if expired
        if ((time() - ($rateData['since'] ?? 0)) >= $windowSec) {
            $rateData = ['count' => 0, 'since' => time()];
        }
        if ($rateData['count'] >= $maxAttempts) {
            $wait = $windowSec - (time() - $rateData['since']);
            jsonErr('Demasiados intentos fallidos. Esperá ' . ceil($wait / 60) . ' minuto(s).', 429);
        }
    } catch (Exception $e) { $rateData = null; } // Si la tabla no existe, omitir rate limiting
    // ────────────────────────────────────────────────────────────────────────

    try {
        $st = $db->prepare("SELECT * FROM ".tb('users')." WHERE (username=? OR email=?) AND active=1");
        $st->execute([$login, $login]);
        $user = $st->fetch();
    } catch (Exception $e) {
        // Tabla users inexistente con este prefijo → mensaje claro en vez de 500 mudo
        jsonErr('No se pudo leer la tabla de usuarios (prefijo "' . LUNA_TB_PREFIX . '"). '
              . 'Desactivá y reactivá el plugin Luna Workspace en WordPress para regenerar la configuración.', 500);
    }

    if (!$user || !password_verify($pass, $user['password'])) {
        // Incrementar contador de intentos fallidos
        if ($rateData !== null) {
            $rateData['count']++;
            try {
                $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=?")
                   ->execute([$rateKey, json_encode($rateData), json_encode($rateData)]);
            } catch (Exception $e) {}
        }
        jsonErr('Usuario o contraseña incorrectos', 401);
    }

    // Login exitoso — limpiar contador
    if ($rateData !== null) {
        try { $db->prepare("DELETE FROM ".tb('app_settings')." WHERE meta_key=?")->execute([$rateKey]); } catch (Exception $e) {}
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_HOURS * 3600);
    $db->prepare("INSERT INTO ".tb('sessions')." (token, user_id, expires_at) VALUES (?,?,?)")
       ->execute([$token, $user['id'], $expires]);
    try {
        $db->prepare("UPDATE ".tb('users')." SET last_login=NOW() WHERE id=?")
           ->execute([$user['id']]);
    } catch (PDOException $e) { /* column may not exist in older installs */ }
    $db->exec("DELETE FROM ".tb('sessions')." WHERE expires_at < NOW()");

    // También setear cookie para que funcione sin header Authorization
    $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieExpires = time() + SESSION_HOURS * 3600;
    setcookie('luna_token', $token, [
        'expires'  => $cookieExpires,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    jsonOut([
        'token' => $token,
        'user'  => [
            'id'       => (int)$user['id'],
            'name'     => $user['name'] ?? '',
            'username' => $user['username'] ?? '',
            'email'    => $user['email'] ?? '',
            'role'     => $user['role'] ?? 'member',
            'color'    => $user['color'] ?? '#5b6af0',
            'cargo'    => $user['cargo'] ?? '',
            'dept'     => $user['dept'] ?? '',
        ]
    ]);
}

// POST forgot_password — genera una contraseña nueva y la envía por WhatsApp
// al teléfono que el usuario ya tiene configurado en su perfil (mismo
// mecanismo de CallMeBot que usan las notificaciones — requiere que el
// usuario ya haya vinculado su número y API Key con anterioridad).
if ($method === 'POST' && $action === 'forgot_password') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $email = trim($body['email'] ?? '');
    $generic = 'Si el email corresponde a una cuenta activa con WhatsApp configurado, vas a recibir la contraseña nueva en breve.';
    if (!$email) jsonErr('Ingresá tu email');

    // ── Rate limiting: máx 5 solicitudes por IP en 15 minutos ────────────────
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateKey = 'rate_forgot_' . substr(hash('sha256', $ip), 0, 20);
    try {
        $rateSt = $db->prepare("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key=? LIMIT 1");
        $rateSt->execute([$rateKey]);
        $rateRow  = $rateSt->fetch();
        $rateData = $rateRow ? json_decode($rateRow['meta_value'], true) : null;
        if (!is_array($rateData)) $rateData = ['count' => 0, 'since' => time()];
        if ((time() - ($rateData['since'] ?? 0)) >= 900) $rateData = ['count' => 0, 'since' => time()];
        if ($rateData['count'] >= 5) {
            $wait = 900 - (time() - $rateData['since']);
            jsonErr('Demasiados intentos. Esperá ' . ceil($wait / 60) . ' minuto(s).', 429);
        }
        $rateData['count']++;
        $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=?")
           ->execute([$rateKey, json_encode($rateData), json_encode($rateData)]);
    } catch (Exception $e) {}
    // ────────────────────────────────────────────────────────────────────────

    $st = $db->prepare("SELECT id, name, phone, whatsapp_apikey FROM ".tb('users')." WHERE email=? AND active=1 LIMIT 1");
    $st->execute([$email]);
    $user = $st->fetch();

    // Mismo mensaje exista o no la cuenta — no revelar qué emails están registrados
    if (!$user || empty($user['phone']) || empty($user['whatsapp_apikey'])) {
        jsonOut(['message' => $generic]);
    }

    $new_pass = bin2hex(random_bytes(8)); // 16 caracteres, legible

    // Enviar PRIMERO y solo cambiar la contraseña si CallMeBot confirmó el
    // envío — si el mensaje no sale (número mal configurado, sin señal, etc.)
    // no tiene sentido invalidar la clave vieja: el usuario quedaría afuera
    // sin ninguna forma de saber la nueva.
    $text = "🔑 Luna Workspace — tu contraseña nueva es: {$new_pass}\n\nUsala para ingresar y, si querés, cambiala después desde tu perfil.";
    $url  = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $user['phone'],
        'text'   => $text,
        'apikey' => $user['whatsapp_apikey'],
    ]);
    $ctx  = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $sent = ($resp !== false && (stripos($resp, 'Message Sent') !== false || stripos($resp, 'Message queued') !== false));

    if ($sent) {
        $hash = password_hash($new_pass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE ".tb('users')." SET password=? WHERE id=?")->execute([$hash, $user['id']]);
        // Invalidar sesiones activas — obliga a re-loguear con la clave nueva
        try { $db->prepare("DELETE FROM ".tb('sessions')." WHERE user_id=?")->execute([$user['id']]); } catch (Exception $e) {}
    }

    jsonOut(['message' => $generic]);
}

// POST logout
if ($method === 'POST' && $action === 'logout') {
    $token = getBearerToken();
    if ($token) $db->prepare("DELETE FROM ".tb('sessions')." WHERE token=?")->execute([$token]);
    setcookie('luna_token', '', time() - 3600, '/');
    jsonOut(['ok' => true]);
}

// GET me
if ($method === 'GET' && $action === 'me') {
    $user = requireAuth();
    jsonOut(['user' => [
        'id'       => (int)$user['id'],
        'name'     => $user['name'] ?? '',
        'username' => $user['username'] ?? '',
        'email'    => $user['email'] ?? '',
        'role'     => $user['role'] ?? 'member',
        'color'    => $user['color'] ?? '#5b6af0',
        'cargo'    => $user['cargo'] ?? '',
        'dept'     => $user['dept'] ?? '',
    ]]);
}

// GET diag — diagnóstico mínimo para soporte, requiere sesión de admin
// (no expone credenciales ni nombres de usuarios; solo el prefijo en uso
// y conteos)
if ($method === 'GET' && $action === 'diag') {
    requireAdmin();
    $out = [
        'version'  => defined('LUNA_VERSION') ? LUNA_VERSION : '?',
        'prefix'   => LUNA_TB_PREFIX,
        'db'       => 'ERROR',
        'users'    => null,
        'sessions' => null,
    ];
    try {
        $out['users'] = (int) $db->query("SELECT COUNT(*) FROM " . tb('users'))->fetchColumn();
        $out['db']    = 'OK';
    } catch (Exception $e) {}
    try {
        $out['sessions'] = (int) $db->query("SELECT COUNT(*) FROM " . tb('sessions'))->fetchColumn();
    } catch (Exception $e) {}
    jsonOut($out);
}

jsonErr('Acción no encontrada', 404);
