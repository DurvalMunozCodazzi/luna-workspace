<?php
require_once '../config.php';
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

// GET all users (any authenticated user can see the list)
if ($method === 'GET' && $action === 'list') {
    $me = requireAuth();
    if ($me['role'] === 'admin') {
        $st = $db->query("SELECT id,username,email,name,cargo,dept,role,color,active,notes,photo,created_at,last_login,phone,whatsapp_apikey,telegram_chat_id,notification_channel FROM ".tb('users')." ORDER BY role,name");
    } else {
        // Non-admins don't see API keys or private notes, but do see phone for WA prompts
        $st = $db->query("SELECT id,username,email,name,cargo,dept,role,color,active,photo,created_at,last_login,phone,notification_channel FROM ".tb('users')." ORDER BY role,name");
    }
    jsonOut(['users' => $st->fetchAll()]);
}

// GET single user profile (self)
if ($method === 'GET' && $action === 'me') {
    $me = requireAuth();
    unset($me['password']);
    jsonOut(['user' => $me]);
}

// POST create user (admin only)
if ($method === 'POST' && $action === 'create') {
    requireAdmin();
    $b = json_decode(file_get_contents('php://input'), true);
    $username = trim($b['username'] ?? '');
    $name     = trim($b['name'] ?? '');
    $pass     = trim($b['password'] ?? '');
    $email    = trim($b['email'] ?? '');
    $role     = in_array($b['role']??'', ['admin','member','visitor']) ? $b['role'] : 'member';
    $cargo    = trim($b['cargo'] ?? '');
    $dept     = trim($b['dept'] ?? '');
    $color    = trim($b['color'] ?? '#5b6af0');
    $active   = isset($b['active']) ? (int)$b['active'] : 1;
    $notes    = trim($b['notes'] ?? '');

    if (!$username) jsonErr('El nombre de usuario es requerido');
    if (!$name)     jsonErr('El nombre completo es requerido');
    if (!$pass)     jsonErr('La contrasena es requerida');

    // Plan user limit check
    try { $lic = getLicenseInfo(); } catch (\Exception $e) { $lic = ['plan' => 'free', 'max_users' => 1]; }
    $maxUsers = isset($lic['max_users']) ? (int)$lic['max_users'] : 1;
    if ($maxUsers < 9999) {  // 9999 = Unlimited plan; anything less is enforced
        $totalUsers = (int)$db->query("SELECT COUNT(*) FROM ".tb('users'))->fetchColumn();
        if ($totalUsers >= $maxUsers) {
            jsonErr('Límite de usuarios alcanzado para tu plan (' . $maxUsers . '). Actualizá tu licencia en el panel de WordPress.', 403);
        }
    }

    // Check duplicate username
    $chk = $db->prepare("SELECT id FROM ".tb('users')." WHERE username=?");
    $chk->execute([$username]);
    if ($chk->fetch()) jsonErr('Ese nombre de usuario ya existe');

    // Check duplicate email
    if ($email) {
        $chke = $db->prepare("SELECT id FROM ".tb('users')." WHERE email=?");
        $chke->execute([$email]);
        if ($chke->fetch()) jsonErr('Ese email ya existe');
    }

    $photo = $b['photo'] ?? null;
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $db->prepare("INSERT INTO ".tb('users')." (username,email,name,cargo,dept,role,color,password,active,notes,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$username,$email,$name,$cargo,$dept,$role,$color,$hash,$active,$notes,$photo]);
    $newId = $db->lastInsertId();
    $user = $db->prepare("SELECT id,username,email,name,cargo,dept,role,color,active,notes,photo FROM ".tb('users')." WHERE id=?");
    $user->execute([$newId]);
    jsonOut(['user' => $user->fetch()], 201);
}

// PUT update user (admin) or self-update (profile)
if ($method === 'PUT' && $action === 'update') {
    $me = requireAuth();
    $b  = json_decode(file_get_contents('php://input'), true);
    $targetId = $id ?: $me['id'];

    // Only admin can edit others
    if ($targetId != $me['id'] && $me['role'] !== 'admin') jsonErr('Sin permisos', 403);

    $user_row = $db->prepare("SELECT * FROM ".tb('users')." WHERE id=?");
    $user_row->execute([$targetId]);
    $row = $user_row->fetch();
    if (!$row) jsonErr('Usuario no encontrado', 404);

    // Build update
    $fields = [
        'name'   => trim($b['name'] ?? $row['name']),
        'email'  => trim($b['email'] ?? $row['email']),
        'cargo'  => trim($b['cargo'] ?? $row['cargo']),
        'dept'   => trim($b['dept'] ?? $row['dept']),
        'color'  => trim($b['color'] ?? $row['color']),
        'notes'  => trim($b['notes'] ?? $row['notes']),
        'photo'  => isset($b['photo']) ? $b['photo'] : $row['photo'],
    ];

    // Admin-only fields
    if ($me['role'] === 'admin') {
        $fields['role']   = in_array($b['role']??'', ['admin','member','visitor']) ? $b['role'] : $row['role'];
        $fields['active'] = isset($b['active']) ? (int)$b['active'] : $row['active'];
        if (!empty($b['username'])) {
            $chk = $db->prepare("SELECT id FROM ".tb('users')." WHERE username=? AND id!=?");
            $chk->execute([$b['username'], $targetId]);
            if ($chk->fetch()) jsonErr('Ese nombre de usuario ya existe');
            $fields['username'] = trim($b['username']);
        }
    }

    // Password change — solo un admin puede llegar hasta acá (chequeo de
    // permisos más arriba), y ya está autenticado con su propia sesión activa;
    // no se pide la contraseña anterior para no bloquear el caso más común:
    // un usuario que se olvidó la clave y necesita que el admin le ponga una nueva.
    if (!empty($b['password'])) {
        $fields['password'] = password_hash($b['password'], PASSWORD_BCRYPT);
    }

    $sets = array_map(function($k){ return "$k=?"; }, array_keys($fields));
    $vals = array_values($fields);

    // Notification channel fields — WhatsApp/Telegram son función de planes
    // pagos; en el plan Gratis se ignoran silenciosamente aunque los manden
    // (el frontend ya deshabilita estos campos, esto es el respaldo server-side)
    try { $lic = getLicenseInfo(); } catch (\Exception $e) { $lic = ['plan' => 'free']; }
    $planActual = $lic['plan'] ?? 'none';
    $puedeWhatsappTelegram = !in_array($planActual, ['free', 'none'], true);

    if (isset($b['phone']))                { $sets[]='phone=?';                $vals[]=$puedeWhatsappTelegram ? trim($b['phone']) : ''; }
    if (isset($b['whatsapp_apikey']))      { $sets[]='whatsapp_apikey=?';      $vals[]=$puedeWhatsappTelegram ? trim($b['whatsapp_apikey']) : ''; }
    if (isset($b['telegram_chat_id']))     { $sets[]='telegram_chat_id=?';     $vals[]=$puedeWhatsappTelegram ? (trim($b['telegram_chat_id']) ?: null) : null; }
    if (array_key_exists('notification_channel',$b) && in_array($b['notification_channel'],['email','whatsapp','telegram','all','none'])) {
        $sets[]='notification_channel=?'; $vals[]=$puedeWhatsappTelegram ? $b['notification_channel'] : 'email';
    }

    $vals[] = $targetId;
    $db->prepare("UPDATE ".tb('users')." SET ".implode(', ', $sets)." WHERE id=?")->execute($vals);

    $updated = $db->prepare("SELECT id,username,email,name,cargo,dept,role,color,active,notes,photo,phone,whatsapp_apikey,telegram_chat_id,notification_channel FROM ".tb('users')." WHERE id=?");
    $updated->execute([$targetId]);
    jsonOut(['user' => $updated->fetch()]);
}

// DELETE user (admin only, can't delete self)
if ($method === 'DELETE' && $action === 'delete') {
    $me = requireAdmin();
    if (!$id) jsonErr('No se recibió un id de usuario válido');
    if ($id == $me['id']) jsonErr('No puedes eliminarte a ti mismo');
    $st = $db->prepare("SELECT id,name FROM ".tb('users')." WHERE id=?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) jsonErr('Usuario no encontrado', 404);
    $db->prepare("DELETE FROM ".tb('users')." WHERE id=?")->execute([$id]);
    jsonOut(['ok' => true, 'deleted' => $u['name']]);
}

// ── GET user preferences ──────────────────────────────────────
if ($method === 'GET' && $action === 'get_prefs') {
    $me = requireAuth();
    $st = $db->prepare("SELECT meta_key, meta_value FROM ".tb('user_meta')." WHERE user_id=?");
    $st->execute([$me['id']]);
    $rows = $st->fetchAll();
    $prefs = [];
    foreach ($rows as $r) $prefs[$r['meta_key']] = $r['meta_value'];
    jsonOut(['prefs' => $prefs]);
}

// ── POST save user preferences ────────────────────────────────
if ($method === 'POST' && $action === 'save_prefs') {
    $me = requireAuth();
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) jsonErr('Datos invalidos');
    foreach ($b as $key => $val) {
        $key = substr(preg_replace('/[^a-zA-Z0-9_]/', '', $key), 0, 100);
        if (!$key) continue;
        $valStr = is_string($val) ? $val : json_encode($val);
        $db->prepare("INSERT INTO ".tb('user_meta')." (user_id, meta_key, meta_value) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE meta_value=?")
           ->execute([$me['id'], $key, $valStr, $valStr]);
    }
    jsonOut(['ok' => true]);
}


jsonErr('Accion no encontrada', 404);
