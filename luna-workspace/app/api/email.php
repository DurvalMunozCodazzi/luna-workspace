<?php
require_once __DIR__ . '/../config.php';
// ══════════════════════════════════════════════
//  LUNA — Email via SMTP (PHPMailer-compatible)
//  Soporta Gmail, Outlook, SMTP custom
// ══════════════════════════════════════════════

function getEmailSettings() {
    $db = getDB();
    try {
        $st = $db->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='email_settings' LIMIT 1");
        $row = $st->fetch();
        if ($row) return json_decode($row['meta_value'], true) ?: defaultEmailSettings();
    } catch (Exception $e) {}
    return defaultEmailSettings();
}

function defaultEmailSettings() {
    return [
        'enabled'    => false,
        'smtp_host'  => 'smtp.gmail.com',
        'smtp_port'  => 587,
        'smtp_user'  => '',
        'smtp_pass'  => '',
        'from_email' => '',
        'from_name'  => 'Luna Workspace',
        'encryption' => 'tls',
    ];
}

function saveEmailSettings($settings) {
    $db = getDB();
    $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('email_settings',?)
                  ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([json_encode($settings), json_encode($settings)]);
}

// ── Local SMTP injection via localhost:25 (Postfix/Plesk, no SSL, no auth) ──
// Same approach that works in WP admin test. Fast, no external connections.
function sendNativeMail($to, $toName, $subject, $htmlBody) {
    $cfg      = getEmailSettings();
    $from     = $cfg['from_email'] ?: ('info@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = $cfg['from_name']  ?: 'Luna Workspace';
    $body     = emailTemplate($subject, $htmlBody, $fromName);

    // Try localhost:25 first (Postfix local injection — instant, no SSL)
    $sock = @stream_socket_client('tcp://127.0.0.1:25', $errno, $errstr, 5);
    if ($sock) {
        stream_set_timeout($sock, 5);
        fgets($sock, 512); // 220 greeting
        fwrite($sock, "EHLO localhost\r\n"); smtpReadAll($sock);
        fwrite($sock, "MAIL FROM:<{$from}>\r\n"); fgets($sock, 512);
        fwrite($sock, "RCPT TO:<{$to}>\r\n");
        $rcpt = fgets($sock, 512);
        fwrite($sock, "DATA\r\n"); fgets($sock, 512);
        $msg  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $msg .= "To: <{$to}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n.";
        fwrite($sock, $msg . "\r\n");
        $r = fgets($sock, 512);
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        if (strpos($r, '250') === 0) return ['ok' => true];
    }

    // Fallback: PHP mail() nativo
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
             . "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'No se pudo enviar (localhost:25 ni mail())'];
}

// ── SMTP send via socket ───────────────────────────────────────────────────────
function sendSMTP($to, $toName, $subject, $htmlBody) {
    // ALWAYS try localhost:25 first — fast, no SSL, no external connection
    // External SMTP (websobreruedas.ar:465) hangs on SSL handshake and kills the process
    $native = sendNativeMail($to, $toName, $subject, $htmlBody);
    if (!empty($native['ok'])) return $native;

    // localhost:25 failed — try external SMTP as last resort
    $cfg = getEmailSettings();
    if (empty($cfg['enabled']) || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])) {
        return $native; // nothing else to try
    }

    $host     = $cfg['smtp_host']  ?: 'smtp.gmail.com';
    $port     = intval($cfg['smtp_port'] ?: 587);
    $user     = $cfg['smtp_user'];
    $pass     = $cfg['smtp_pass'];
    $from     = $cfg['from_email'] ?: $user;
    $fromName = $cfg['from_name']  ?: 'Luna Workspace';
    $enc      = $cfg['encryption'] ?: 'tls';

    try {
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);

        $sock = $enc === 'ssl'
            ? @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context)
            : @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);

        // Si SMTP no conecta, caer a mail() nativo
        if (!$sock) {
            error_log("Luna SMTP: no se pudo conectar a {$host}:{$port} ({$errstr}), usando mail() nativo");
            return sendNativeMail($to, $toName, $subject, $htmlBody);
        }

        // Timeout de 10s en todas las operaciones de lectura/escritura
        stream_set_timeout($sock, 10);

        $read = fgets($sock, 512);
        if (strpos($read, '220') !== 0) return smtpErr($sock, "Saludo fallido: {$read}");

        smtpCmd($sock, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $ehlo = smtpReadAll($sock);

        if ($enc === 'tls') {
            smtpCmd($sock, "STARTTLS");
            $tls = fgets($sock, 512);
            if (strpos($tls, '220') !== 0) return smtpErr($sock, "STARTTLS fallido: {$tls}");
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
                return smtpErr($sock, "No se pudo iniciar TLS");
            smtpCmd($sock, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            smtpReadAll($sock);
        }

        smtpCmd($sock, "AUTH LOGIN");
        $r = fgets($sock, 512);
        if (strpos($r, '334') !== 0) return smtpErr($sock, "AUTH fallido: {$r}");
        smtpCmd($sock, base64_encode($user));
        $r = fgets($sock, 512);
        if (strpos($r, '334') !== 0) return smtpErr($sock, "Usuario fallido");
        smtpCmd($sock, base64_encode($pass));
        $r = fgets($sock, 512);
        if (strpos($r, '235') !== 0) return smtpErr($sock, "Contraseña incorrecta");

        smtpCmd($sock, "MAIL FROM:<{$from}>");
        fgets($sock, 512);
        smtpCmd($sock, "RCPT TO:<{$to}>");
        $r = fgets($sock, 512);
        if (strpos($r, '250') !== 0) return smtpErr($sock, "Destinatario rechazado: {$r}");
        smtpCmd($sock, "DATA");
        fgets($sock, 512);

        $msg  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $msg .= "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$to}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\nX-Mailer: Luna Workspace\r\n\r\n";
        $msg .= chunk_split(base64_encode(emailTemplate($subject, $htmlBody, $fromName)));
        $msg .= "\r\n.";
        smtpCmd($sock, $msg);
        $r = fgets($sock, 512);
        smtpCmd($sock, "QUIT");
        fclose($sock);

        if (strpos($r, '250') !== 0) return ['ok' => false, 'error' => "Email rechazado: {$r}"];
        return ['ok' => true];

    } catch (Exception $e) {
        // Fallar a mail() nativo si SMTP lanza excepción
        error_log("Luna SMTP exception: " . $e->getMessage() . ", usando mail() nativo");
        return sendNativeMail($to, $toName, $subject, $htmlBody);
    }
}

function smtpCmd($sock, $cmd) { fwrite($sock, $cmd . "\r\n"); }
function smtpReadAll($sock) {
    $out = '';
    while ($line = fgets($sock, 512)) {
        $out .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }
    return $out;
}
function smtpErr($sock, $msg) {
    if ($sock) { fwrite($sock, "QUIT\r\n"); fclose($sock); }
    return ['ok' => false, 'error' => $msg];
}

function emailTemplate($subject, $body, $appName) {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
    <style>
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f8;margin:0;padding:20px}
    .wrap{max-width:520px;margin:0 auto}
    .card{background:#fff;border-radius:12px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
    .logo{font-size:22px;font-weight:800;color:#5b6af0;margin-bottom:24px;display:flex;align-items:center;gap:8px}
    .content{font-size:14px;color:#1a1a2e;line-height:1.75}
    .btn{display:inline-block;background:#5b6af0;color:#fff!important;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;margin:20px 0;font-size:14px}
    .footer{margin-top:24px;font-size:11px;color:#8888aa;border-top:1px solid #e8eaf2;padding-top:16px;text-align:center}
    blockquote{border-left:3px solid #5b6af0;padding:8px 16px;background:#f0f2f8;border-radius:0 8px 8px 0;color:#4a4a6a;margin:12px 0}
    </style></head><body><div class='wrap'>
    <div class='card'>
      <div class='logo'>🌙 {$appName}</div>
      <div class='content'>{$body}</div>
      <div class='footer'>Mensaje automático de {$appName} · No respondas este email</div>
    </div></div></body></html>";
}

// ── WhatsApp via CallMeBot ────────────────────
function getWhatsAppSettings() {
    $db = getDB();
    try {
        $st = $db->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='whatsapp_settings' LIMIT 1");
        $row = $st->fetch();
        if ($row) return json_decode($row['meta_value'], true) ?: defaultWhatsAppSettings();
    } catch (Exception $e) {}
    return defaultWhatsAppSettings();
}

function defaultWhatsAppSettings() {
    return ['enabled' => false, 'phone' => '', 'apikey' => ''];
}

function saveWhatsAppSettings($s) {
    $db = getDB();
    $json = json_encode($s);
    $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('whatsapp_settings',?) ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([$json, $json]);
}

function sendWhatsApp($text) {
    $cfg = getWhatsAppSettings();
    if (empty($cfg['enabled']) || empty($cfg['phone']) || empty($cfg['apikey'])) {
        return ['ok' => false, 'error' => 'WhatsApp no configurado o deshabilitado'];
    }
    $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
        'phone'  => $cfg['phone'],
        'text'   => $text,
        'apikey' => $cfg['apikey'],
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx);
    return ['ok' => $r !== false, 'response' => $r];
}

// ── WhatsApp Business Cloud API (Meta) ───────────────────────
function getWhatsAppBusinessSettings() {
    $db = getDB();
    try {
        $st = $db->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='whatsapp_business_settings' LIMIT 1");
        $row = $st->fetch();
        if ($row) return json_decode($row['meta_value'], true) ?: defaultWhatsAppBusinessSettings();
    } catch (Exception $e) {}
    return defaultWhatsAppBusinessSettings();
}

function defaultWhatsAppBusinessSettings() {
    return [
        'enabled'         => false,
        'phone_number_id' => '',   // Meta phone number ID (not the actual number)
        'access_token'    => '',   // Meta permanent access token
        'template_name'   => 'hello_world',  // approved template name
    ];
}

function saveWhatsAppBusinessSettings($s) {
    $db = getDB();
    $json = json_encode($s);
    $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('whatsapp_business_settings',?) ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([$json, $json]);
}

/**
 * Send WhatsApp message via Meta Business Cloud API.
 * Uses template message (required for business-initiated conversations).
 * $to: phone number with country code, no + or spaces, e.g. "5491187654321"
 */
function sendWhatsAppBusiness($to, $userName, $taskCount, $details) {
    $cfg = getWhatsAppBusinessSettings();
    if (empty($cfg['enabled']) || empty($cfg['phone_number_id']) || empty($cfg['access_token'])) {
        return ['ok' => false, 'error' => 'WhatsApp Business no configurado'];
    }
    // Clean phone number: remove +, spaces, dashes
    $to = preg_replace('/[^0-9]/', '', $to);
    if (!$to) return ['ok' => false, 'error' => 'Número de teléfono inválido'];

    $url = "https://graph.facebook.com/v19.0/{$cfg['phone_number_id']}/messages";
    $body = json_encode([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $details],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$cfg['access_token']}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body),
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $r    = @file_get_contents($url, false, $ctx);
    $data = $r ? json_decode($r, true) : null;
    if ($data && isset($data['messages'])) return ['ok' => true];
    $err = $data['error']['message'] ?? ($r ?: 'Sin respuesta');
    return ['ok' => false, 'error' => $err];
}

// ── Telegram Bot API ──────────────────────────────────────────
function getTelegramSettings() {
    $db = getDB();
    try {
        $st = $db->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='telegram_settings' LIMIT 1");
        $row = $st->fetch();
        if ($row) return json_decode($row['meta_value'], true) ?: defaultTelegramSettings();
    } catch (Exception $e) {}
    return defaultTelegramSettings();
}

function defaultTelegramSettings() {
    return ['enabled' => false, 'bot_token' => '', 'bot_username' => ''];
}

function saveTelegramSettings($s) {
    $db = getDB();
    $json = json_encode($s);
    $db->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('telegram_settings',?) ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([$json, $json]);
}

/**
 * Send a Telegram message to a specific chat_id.
 */
function sendTelegram($chatId, $text) {
    $cfg = getTelegramSettings();
    if (empty($cfg['enabled']) || empty($cfg['bot_token'])) {
        return ['ok' => false, 'error' => 'Telegram no configurado'];
    }
    if (!$chatId) return ['ok' => false, 'error' => 'chat_id vacío'];

    $url  = "https://api.telegram.org/bot{$cfg['bot_token']}/sendMessage";
    $body = json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $r    = @file_get_contents($url, false, $ctx);
    $data = $r ? json_decode($r, true) : null;
    if ($data && !empty($data['ok'])) return ['ok' => true];
    $err  = $data['description'] ?? ($r ?: 'Sin respuesta');
    return ['ok' => false, 'error' => $err];
}

/**
 * Send notification to a user via their preferred channel.
 * $user: array with keys: name, email, phone, telegram_chat_id, notification_channel
 * $subject: email subject
 * $htmlBody: email HTML body
 * $plainText: text for WhatsApp/Telegram
 */
function sendNotificationToUser($user, $subject, $htmlBody, $plainText) {
    // Free plan: notifications disabled
    try { $lic = getLicenseInfo(); } catch (\Exception $e) { $lic = ['plan' => 'free']; }
    if (($lic['plan'] ?? '') === 'free' || ($lic['notifications'] ?? true) === false) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'free_plan'];
    }

    $channel = $user['notification_channel'] ?? 'email';
    $results = [];

    $sendEmail = in_array($channel, ['email', 'all']);
    $sendWa    = in_array($channel, ['whatsapp', 'all']) && !empty($user['phone']);
    $sendTg    = in_array($channel, ['telegram', 'all']) && !empty($user['telegram_chat_id']);

    if ($channel === 'none') return ['ok' => true, 'skipped' => true];

    if ($sendEmail && !empty($user['email'])) {
        $results['email'] = sendSMTP($user['email'], $user['name'], $subject, $htmlBody);
    }
    if ($sendWa) {
        $apikey = $user['whatsapp_apikey'] ?? '';
        if ($apikey) {
            // Per-user CallMeBot: uses their own phone + apikey — no central account needed
            $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
                'phone'  => $user['phone'],
                'text'   => $plainText,
                'apikey' => $apikey,
            ]);
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
            $r = @file_get_contents($url, false, $ctx);
            $results['whatsapp'] = ['ok' => $r !== false];
        } else {
            // Fallback: WhatsApp Business API (central sender)
            $results['whatsapp'] = sendWhatsAppBusiness($user['phone'], $user['name'], 1, $plainText);
        }
    }
    if ($sendTg) {
        $results['telegram'] = sendTelegram($user['telegram_chat_id'], $plainText);
    }

    // If no channel sent anything (e.g. whatsapp chosen but no phone), fall back to email
    if (empty($results) && !empty($user['email'])) {
        $results['email'] = sendSMTP($user['email'], $user['name'], $subject, $htmlBody);
    }

    $anyOk = false;
    foreach ($results as $r) { if (!empty($r['ok'])) { $anyOk = true; break; } }
    return ['ok' => $anyOk, 'channels' => $results];
}

// ── Create notification + send email ──────────
function createNotification($db, $userId, $fromUserId, $type, $cardId, $workspaceId, $message) {
    // Allow self-assignment notifications — user needs confirmation when assigning tasks to themselves
    // Only skip if it's a modification/comment triggered by the same user on someone else's card
    if ($userId == $fromUserId && in_array($type, ['updated', 'comment'])) return;

    // Save to DB
    try {
        $db->prepare("INSERT INTO ".tb('notifications')." (user_id,from_user_id,type,card_id,workspace_id,message) VALUES (?,?,?,?,?,?)")
           ->execute([$userId, $fromUserId, $type, $cardId, $workspaceId, $message]);
    } catch (Exception $e) {}

    // Get recipient — fetch ALL fields needed for multi-channel dispatch
    $u = $db->prepare("SELECT name,email,phone,whatsapp_apikey,telegram_chat_id,notification_channel FROM ".tb('users')." WHERE id=? AND active=1");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return;

    // Get sender
    $f = $db->prepare("SELECT name FROM ".tb('users')." WHERE id=?");
    $f->execute([$fromUserId]);
    $from = $f->fetch();
    $fromName = $from ? $from['name'] : 'Un compañero';

    // Get card with full details
    $c = $db->prepare("SELECT c.title, c.description, c.priority, c.start_date, c.due_date,
                              col.title AS column_title
                       FROM ".tb('cards')." c
                       LEFT JOIN ".tb('columns_k')." col ON col.id = c.column_id
                       WHERE c.id=?");
    $c->execute([$cardId]);
    $card      = $c->fetch();
    $cardTitle = $card ? $card['title'] : 'una tarea';

    // Get card tags
    $tSt = $db->prepare("SELECT label FROM ".tb('card_tags')." WHERE card_id=?");
    $tSt->execute([$cardId]);
    $tags = array_column($tSt->fetchAll(), 'label');

    // Get card assignees names
    $aSt = $db->prepare("SELECT u.name FROM ".tb('card_assignees')." ca JOIN ".tb('users')." u ON u.id=ca.user_id WHERE ca.card_id=?");
    $aSt->execute([$cardId]);
    $assignees = array_column($aSt->fetchAll(), 'name');

    // Get workspace
    $w = $db->prepare("SELECT name FROM ".tb('workspaces')." WHERE id=?");
    $w->execute([$workspaceId]);
    $ws     = $w->fetch();
    $wsName = $ws ? $ws['name'] : 'el workspace';

    $siteUrl = defined('SITE_URL') ? SITE_URL : ((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??''));

    $prioLabels = ['high' => '🔴 Alta', 'medium' => '🟡 Media', 'low' => '🟢 Baja'];
    $prio       = $card ? ($prioLabels[$card['priority']] ?? '') : '';

    // Build rich WA/Telegram plain text for 'assigned' type
    $assignedPlain = implode("\n", array_filter([
        '🔔 *Tarea en Luna Workspace*',
        '📌 *' . $cardTitle . '*',
        $card && $card['column_title']  ? '📋 Estado: '      . $card['column_title']      : '',
        $card && $card['description']   ? '📝 '              . $card['description']        : '',
        $prio                           ? '⚡ Prioridad: '   . $prio                       : '',
        $assignees                      ? '👥 Asignado a: '  . implode(', ', $assignees)   : '',
        '👤 Asignado por: '            . $fromName,
        $card && $card['start_date']    ? '🗓 Inicio: '      . $card['start_date']         : '',
        $card && $card['due_date']      ? '⏰ Vence: '       . $card['due_date']           : '',
        $tags                           ? '🏷 Etiquetas: '   . implode(', ', $tags)        : '',
    ]));

    $eUser    = htmlspecialchars($user['name'],  ENT_QUOTES, 'UTF-8');
    $eFrom    = htmlspecialchars($fromName,       ENT_QUOTES, 'UTF-8');
    $eCard    = htmlspecialchars($cardTitle,      ENT_QUOTES, 'UTF-8');
    $eWs      = htmlspecialchars($wsName,         ENT_QUOTES, 'UTF-8');
    $eSiteUrl = htmlspecialchars($siteUrl,        ENT_QUOTES, 'UTF-8');

    // Build detailed HTML body for 'assigned' email
    $detailRows = '';
    if ($card && $card['column_title'])  $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Estado</td><td><strong>" . htmlspecialchars($card['column_title'], ENT_QUOTES, 'UTF-8') . "</strong></td></tr>";
    if ($card && $card['description'])   $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0;vertical-align:top'>Descripción</td><td>" . nl2br(htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8')) . "</td></tr>";
    if ($prio)                           $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Prioridad</td><td>{$prio}</td></tr>";
    if ($assignees)                      $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Asignado a</td><td>" . htmlspecialchars(implode(', ', $assignees), ENT_QUOTES, 'UTF-8') . "</td></tr>";
    if ($card && $card['start_date'])    $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Inicio</td><td>" . htmlspecialchars($card['start_date'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
    if ($card && $card['due_date'])      $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Vence</td><td>" . htmlspecialchars($card['due_date'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
    if ($tags)                           $detailRows .= "<tr><td style='color:#64748b;padding:2px 8px 2px 0'>Etiquetas</td><td>" . htmlspecialchars(implode(', ', $tags), ENT_QUOTES, 'UTF-8') . "</td></tr>";

    $assignedHtml = "<p>Hola <strong>{$eUser}</strong>,</p>
        <p><strong>{$eFrom}</strong> te asignó la tarea <strong>&quot;{$eCard}&quot;</strong> en <strong>{$eWs}</strong>.</p>"
        . ($detailRows ? "<table style='border-collapse:collapse;margin:12px 0'>{$detailRows}</table>" : '')
        . "<a href='{$eSiteUrl}' class='btn'>Ver en Luna →</a>";

    $subjects = [
        'assigned'        => "✅ Te asignaron una tarea en {$wsName}",
        'updated'         => "✏️ Se modificó una tarea: {$cardTitle}",
        'comment'         => "💬 {$fromName} comentó en una tarea",
        'due_soon'        => "⚠️ Tarea por vencer: {$cardTitle}",
        'dependency_done' => "🔓 Una tarea desbloqueada: {$cardTitle}",
    ];
    $htmlBodies = [
        'assigned' => $assignedHtml,
        'updated'  => "<p>Hola <strong>{$eUser}</strong>,</p>
            <p><strong>{$eFrom}</strong> modificó la tarea <strong>&quot;{$eCard}&quot;</strong> en <strong>{$eWs}</strong>.</p>
            <a href='{$eSiteUrl}' class='btn'>Ver tarea →</a>",
        'comment'  => "<p>Hola <strong>{$eUser}</strong>,</p>
            <p><strong>{$eFrom}</strong> comentó en la tarea <strong>&quot;{$eCard}&quot;</strong>:</p>
            <blockquote>" . htmlspecialchars(substr($message,0,200), ENT_QUOTES, 'UTF-8') . "</blockquote>
            <a href='{$eSiteUrl}' class='btn'>Ver comentario →</a>",
        'due_soon' => "<p>Hola <strong>{$eUser}</strong>,</p>
            <p>La tarea <strong>&quot;{$eCard}&quot;</strong> vence pronto en <strong>{$eWs}</strong>.</p>
            <a href='{$eSiteUrl}' class='btn'>Ver tarea →</a>",
        'dependency_done' => "<p>Hola <strong>{$eUser}</strong>,</p>
            <p>La tarea <strong>&quot;{$eCard}&quot;</strong> fue desbloqueada y está lista para continuar.</p>
            <a href='{$eSiteUrl}' class='btn'>Ver tarea →</a>",
    ];
    $plainTexts = [
        'assigned'        => $assignedPlain,
        'updated'         => "✏️ {$fromName} modificó \"{$cardTitle}\" en {$wsName}.",
        'comment'         => "💬 {$fromName} comentó en \"{$cardTitle}\": " . substr($message,0,200),
        'due_soon'        => "⚠️ Tarea por vencer: \"{$cardTitle}\" en {$wsName}.",
        'dependency_done' => "🔓 Tarea desbloqueada: \"{$cardTitle}\".",
    ];

    $subject   = $subjects[$type]   ?? "Nueva notificación en {$wsName}";
    $htmlBody  = $htmlBodies[$type] ?? "<p>{$message}</p>";
    $plainText = $plainTexts[$type] ?? $message;

    // Dispatch via whichever channel the user has configured
    sendNotificationToUser($user, $subject, $htmlBody, $plainText);
}
