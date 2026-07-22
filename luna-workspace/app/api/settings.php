<?php
require_once '../config.php';
require_once 'email.php';

$me     = requireAdmin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET all settings ──────────────────────────
if ($method === 'GET' && $action === 'get') {
    $st   = $db->query("SELECT meta_key, meta_value FROM ".tb('app_settings')."");
    $rows = $st->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $val = json_decode($r['meta_value'], true);
        $out[$r['meta_key']] = $val !== null ? $val : $r['meta_value'];
    }
    // Remove password from response
    if (isset($out['email_settings']['smtp_pass'])) {
        $out['email_settings']['smtp_pass'] = $out['email_settings']['smtp_pass'] ? '••••••••' : '';
    }
    if (isset($out['whatsapp_settings']['apikey'])) {
        $out['whatsapp_settings']['apikey'] = $out['whatsapp_settings']['apikey'] ? '••••••••' : '';
    }
    if (isset($out['whatsapp_business_settings']['access_token'])) {
        $out['whatsapp_business_settings']['access_token'] = $out['whatsapp_business_settings']['access_token'] ? '••••••••' : '';
    }
    if (isset($out['telegram_settings']['bot_token'])) {
        $out['telegram_settings']['bot_token'] = $out['telegram_settings']['bot_token'] ? '••••••••' : '';
    }
    jsonOut(['settings' => $out]);
}

// ── POST save email settings ──────────────────
if ($method === 'POST' && $action === 'save_email') {
    $b = json_decode(file_get_contents('php://input'), true);
    $current = getEmailSettings();

    $settings = [
        'enabled'    => !empty($b['enabled']),
        'smtp_host'  => trim($b['smtp_host']  ?? 'smtp.gmail.com'),
        'smtp_port'  => intval($b['smtp_port'] ?? 587),
        'smtp_user'  => trim($b['smtp_user']  ?? ''),
        'smtp_pass'  => $b['smtp_pass'] === '••••••••' ? ($current['smtp_pass'] ?? '') : trim($b['smtp_pass'] ?? ''),
        'from_email' => trim($b['from_email'] ?? ''),
        'from_name'  => trim($b['from_name']  ?? 'Luna Workspace'),
        'encryption' => in_array($b['encryption']??'tls', ['tls','ssl','none']) ? $b['encryption'] : 'tls',
    ];
    saveEmailSettings($settings);
    jsonOut(['ok' => true, 'message' => 'Configuración guardada']);
}

// ── POST test email ───────────────────────────
if ($method === 'POST' && $action === 'test_email') {
    $b    = json_decode(file_get_contents('php://input'), true);
    $to   = trim($b['to'] ?? $me['email'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) jsonErr('Email inválido');

    $result = sendSMTP($to, $me['name'], '✅ Test de Luna Workspace',
        "<p>Hola <strong>{$me['name']}</strong>,</p>
         <p>Si recibís este email, la configuración SMTP de <strong>Luna Workspace</strong> funciona correctamente. 🎉</p>
         <p style='color:#8888aa;font-size:12px'>Enviado desde: " . ($_SERVER['HTTP_HOST']??'') . "</p>"
    );

    if ($result['ok']) {
        jsonOut(['ok' => true, 'message' => "Email enviado a {$to}. Revisá tu bandeja."]);
    } else {
        jsonErr('Error al enviar: ' . ($result['error'] ?? 'Error desconocido'));
    }
}

// ── POST save WhatsApp settings ───────────────
if ($method === 'POST' && $action === 'save_whatsapp') {
    $b = json_decode(file_get_contents('php://input'), true);
    $s = [
        'enabled' => !empty($b['enabled']),
        'phone'   => trim($b['phone']  ?? ''),
        'apikey'  => trim($b['apikey'] ?? ''),
    ];
    saveWhatsAppSettings($s);
    jsonOut(['ok' => true]);
}

// ── POST test WhatsApp ────────────────────────
if ($method === 'POST' && $action === 'test_whatsapp') {
    $result = sendWhatsApp("🌙 Luna Workspace — Test de conexión. Si recibís este mensaje, WhatsApp está configurado correctamente.");
    if ($result['ok']) {
        jsonOut(['ok' => true, 'message' => 'Mensaje enviado. Revisá tu WhatsApp.']);
    } else {
        jsonErr('Error: ' . ($result['error'] ?? 'No se pudo enviar'));
    }
}

// ── POST save reminder schedule ───────────────
if ($method === 'POST' && $action === 'save_reminder_schedule') {
    $b = json_decode(file_get_contents('php://input'), true);
    $s = [
        'enabled' => !empty($b['enabled']),
        'hour'    => max(0, min(23, (int)($b['hour'] ?? 8))),
    ];
    $json = json_encode($s);
    $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('reminder_schedule',?) ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([$json, $json]);
    jsonOut(['ok' => true]);
}

// ── POST save WhatsApp Business settings ─────
if ($method === 'POST' && $action === 'save_whatsapp_business') {
    $b = json_decode(file_get_contents('php://input'), true);
    $current = getWhatsAppBusinessSettings();
    $s = [
        'enabled'         => !empty($b['enabled']),
        'phone_number_id' => trim($b['phone_number_id'] ?? ''),
        'access_token'    => $b['access_token'] === '••••••••' ? ($current['access_token'] ?? '') : trim($b['access_token'] ?? ''),
        'template_name'   => trim($b['template_name'] ?? 'hello_world'),
    ];
    saveWhatsAppBusinessSettings($s);
    jsonOut(['ok' => true]);
}

// ── POST test WhatsApp Business ───────────────
if ($method === 'POST' && $action === 'test_whatsapp_business') {
    $b    = json_decode(file_get_contents('php://input'), true);
    $phone = trim($b['phone'] ?? $me['phone'] ?? '');
    if (!$phone) jsonErr('Ingresá un número de teléfono para la prueba');
    $result = sendWhatsAppBusiness($phone, $me['name'], 1,
        "🌙 *Luna Workspace — Test*\nSi recibís este mensaje, WhatsApp Business está configurado correctamente."
    );
    if ($result['ok']) jsonOut(['ok' => true, 'message' => 'Mensaje enviado. Revisá tu WhatsApp.']);
    else jsonErr('Error: ' . ($result['error'] ?? 'No se pudo enviar'));
}

// ── POST save Telegram settings ───────────────
if ($method === 'POST' && $action === 'save_telegram') {
    $b = json_decode(file_get_contents('php://input'), true);
    $current = getTelegramSettings();
    $s = [
        'enabled'      => !empty($b['enabled']),
        'bot_token'    => $b['bot_token'] === '••••••••' ? ($current['bot_token'] ?? '') : trim($b['bot_token'] ?? ''),
        'bot_username' => ltrim(trim($b['bot_username'] ?? ''), '@'),
    ];
    saveTelegramSettings($s);
    jsonOut(['ok' => true]);
}

// ── POST test Telegram ────────────────────────
if ($method === 'POST' && $action === 'test_telegram') {
    $b      = json_decode(file_get_contents('php://input'), true);
    $chatId = trim($b['chat_id'] ?? $me['telegram_chat_id'] ?? '');
    if (!$chatId) jsonErr('Ingresá un chat_id de Telegram para la prueba');
    $result = sendTelegram($chatId, "🌙 *Luna Workspace — Test*\nSi recibís este mensaje, Telegram está configurado correctamente\\.");
    if ($result['ok']) jsonOut(['ok' => true, 'message' => 'Mensaje enviado. Revisá tu Telegram.']);
    else jsonErr('Error: ' . ($result['error'] ?? 'No se pudo enviar'));
}

jsonErr('Acción no encontrada', 404);
