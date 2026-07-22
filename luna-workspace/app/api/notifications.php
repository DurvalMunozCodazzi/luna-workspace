<?php
require_once '../config.php';
$me     = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET unread notifications ──────────────────
if ($method === 'GET' && $action === 'list') {
    $st = $db->prepare("
        SELECT n.*, u.name as from_name, u.color as from_color,
               c.title as card_title, w.name as workspace_name
        FROM ".tb('notifications')." n
        LEFT JOIN ".tb('users')." u ON u.id = n.from_user_id
        LEFT JOIN ".tb('cards')." c ON c.id = n.card_id
        LEFT JOIN ".tb('workspaces')." w ON w.id = n.workspace_id
        WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 50
    ");
    $st->execute([$me['id']]);
    jsonOut(['notifications' => $st->fetchAll()]);
}

// ── POST mark as read ─────────────────────────
if ($method === 'POST' && $action === 'read') {
    $b  = json_decode(file_get_contents('php://input'), true);
    $id = intval($b['id'] ?? 0);
    if ($id) {
        $db->prepare("UPDATE ".tb('notifications')." SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $me['id']]);
    } else {
        $db->prepare("UPDATE ".tb('notifications')." SET is_read=1 WHERE user_id=?")->execute([$me['id']]);
    }
    jsonOut(['ok' => true]);
}

// ── POST mark all read ────────────────────────
if ($method === 'POST' && $action === 'read_all') {
    $db->prepare("UPDATE ".tb('notifications')." SET is_read=1 WHERE user_id=?")->execute([$me['id']]);
    jsonOut(['ok' => true]);
}

// ── GET unread count ──────────────────────────
if ($method === 'GET' && $action === 'count') {
    $count = $db->prepare("SELECT COUNT(*) FROM ".tb('notifications')." WHERE user_id=? AND is_read=0");
    $count->execute([$me['id']]);
    jsonOut(['count' => (int)$count->fetchColumn()]);
}

// ── POST send WhatsApp via CallMeBot (solo admin — envía mensajes a
// costo/nombre de otros usuarios, no puede quedar abierto a cualquier rol) ─
if ($method === 'POST' && $action === 'send_whatsapp') {
    if ($me['role'] !== 'admin') jsonErr('Solo el administrador puede enviar notificaciones por WhatsApp', 403);
    try { $lic = getLicenseInfo(); } catch (\Exception $e) { $lic = ['plan' => 'free']; }
    if (($lic['plan'] ?? '') === 'free' || ($lic['notifications'] ?? true) === false) {
        jsonErr('Las notificaciones no están disponibles en el plan Gratis. Actualizá tu licencia.', 403);
    }
    $b       = json_decode(file_get_contents('php://input'), true);
    $userIds = array_map('intval', (array)($b['user_ids'] ?? []));
    $message = trim($b['message'] ?? '');
    if (empty($userIds) || !$message) jsonErr('Datos incompletos');

    $results = [];
    foreach ($userIds as $uid) {
        $st = $db->prepare("SELECT name, phone, whatsapp_apikey FROM ".tb('users')." WHERE id=? AND active=1");
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u || empty($u['phone']) || empty($u['whatsapp_apikey'])) {
            $results[] = ['id' => $uid, 'ok' => false, 'error' => 'Sin teléfono o API Key configurado'];
            continue;
        }
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone'  => $u['phone'],
            'text'   => $message,
            'apikey' => $u['whatsapp_apikey'],
        ]);
        $ctx  = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $ok   = ($resp !== false && (stripos($resp, 'Message Sent') !== false || stripos($resp, 'Message queued') !== false));
        $results[] = ['id' => $uid, 'name' => $u['name'], 'ok' => $ok, 'error' => $ok ? null : 'CallMeBot no confirmó envío'];
    }
    jsonOut(['results' => $results]);
}

jsonErr('Acción no encontrada', 404);
