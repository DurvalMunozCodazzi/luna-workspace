<?php
// ============================================================
//  LUNA — Recordatorios de vencimiento por email
//  Llamar via cron: php /httpdocs/api/reminders.php
//  O desde el panel admin en Luna
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/email.php';
// SITE_URL se obtiene automáticamente de config.php (LUNA_SITE_URL)

// Auth: 3 métodos — cron_secret, one-time bypass token (WP admin), session cookie
if (php_sapi_name() !== 'cli') {
    $body_raw  = file_get_contents('php://input');
    $body_json = json_decode($body_raw, true) ?: [];
    $authorized = false;

    // 1) cron_secret en body o header
    $cron_secret = $body_json['cron_secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if ($cron_secret && defined('LUNA_CRON_SECRET') && LUNA_CRON_SECRET && hash_equals(LUNA_CRON_SECRET, $cron_secret)) {
        $authorized = true;
    }

    // 2) one-time bypass token escrito por WP admin en app_settings
    if (!$authorized) {
        $bypass_token = $_SERVER['HTTP_X_WP_BYPASS_TOKEN'] ?? '';
        if ($bypass_token) {
            try {
                $db2 = getDB();
                $row2 = $db2->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='wp_admin_bypass' LIMIT 1")->fetch();
                if ($row2) {
                    $bdata = json_decode($row2['meta_value'], true);
                    if (!empty($bdata['token']) && $bdata['token'] === $bypass_token && ($bdata['expires'] ?? 0) > time()) {
                        $authorized = true;
                        $db2->exec("DELETE FROM ".tb('app_settings')." WHERE meta_key='wp_admin_bypass'");
                    }
                }
            } catch (Exception $e) {}
        }
    }

    // 3) session cookie (usuario admin logueado en la app)
    if (!$authorized) {
        $me = requireAuth();
        if ($me['role'] !== 'admin') jsonErr('Solo admins', 403);
    }
} else {
    $body_json = [];
}

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$action = $_GET['action'] ?? 'send';

// Skip if already sent today (prevents duplicate sends if cron fires multiple times)
if ($action === 'send') {
    $last = null;
    try {
        $st = getDB()->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='reminders_last_sent' LIMIT 1");
        $row = $st->fetch();
        $last = $row ? $row['meta_value'] : null;
    } catch (Exception $e) {}
    if ($last === date('Y-m-d') && php_sapi_name() !== 'cli') {
        // Already sent today — but allow CLI to force-send
        jsonOut(['ok' => true, 'sent' => 0, 'skipped' => true, 'reason' => 'Ya enviado hoy']);
    }
}

// ── Obtener tareas vencidas, de hoy y de esta semana ──────
function getUpcomingCards($db) {
    $nextWeek = date('Y-m-d', strtotime('+7 days'));

    $st = $db->prepare("
        SELECT c.id, c.title, c.due_date, c.priority,
               k.title as col_title, k.color as col_color,
               w.name as ws_name, w.id as ws_id,
               u.id as user_id, u.name as user_name, u.email as user_email,
               u.phone as phone, u.whatsapp_apikey, u.telegram_chat_id, u.notification_channel
        FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        JOIN ".tb('workspaces')." w ON w.id = c.workspace_id
        JOIN ".tb('card_assignees')." ca ON ca.card_id = c.id
        JOIN ".tb('users')." u ON u.id = ca.user_id AND u.active=1 AND u.email != ''
        WHERE c.due_date IS NOT NULL
          AND c.due_date <= ?
          AND k.title NOT LIKE '%complet%'
          AND k.position < (SELECT MAX(position) FROM ".tb('columns_k')." WHERE workspace_id=w.id)
        ORDER BY c.due_date ASC, u.id ASC
    ");
    $st->execute([$nextWeek]);
    return $st->fetchAll();
}

// ── Agrupar por usuario ────────────────────────────────────
function groupByUser($cards) {
    $byUser   = [];
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    foreach ($cards as $c) {
        $uid = $c['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id'              => $uid,
                'user_name'            => $c['user_name'],
                'user_email'           => $c['user_email'],
                'phone'                => $c['phone'] ?? '',
                'whatsapp_apikey'      => $c['whatsapp_apikey'] ?? '',
                'telegram_chat_id'     => $c['telegram_chat_id'] ?? null,
                'notification_channel' => $c['notification_channel'] ?? 'email',
                'overdue'              => [],
                'today'                => [],
                'tomorrow'             => [],
                'this_week'            => [],
            ];
        }
        if ($c['due_date'] < $today)          $byUser[$uid]['overdue'][]    = $c;
        elseif ($c['due_date'] === $today)     $byUser[$uid]['today'][]      = $c;
        elseif ($c['due_date'] === $tomorrow)  $byUser[$uid]['tomorrow'][]   = $c;
        else                                   $byUser[$uid]['this_week'][]  = $c;
    }
    return $byUser;
}

// ── Generar HTML del email ─────────────────────────────────
function buildReminderBody($data, $siteUrl, $plainText = '') {
    $prioColors = ['low'=>'#22d3a0','medium'=>'#f59e0b','high'=>'#f97316','critical'=>'#ef4444'];
    $prioLabels = ['low'=>'Baja','medium'=>'Media','high'=>'Alta','critical'=>'Crítica'];

    $html = "<p>Hola <strong>" . htmlspecialchars($data['user_name'], ENT_QUOTES, 'UTF-8') . "</strong>,</p>
             <p>Aquí tu resumen de tareas por vencer:</p>";

    $sections = [
        'overdue'   => ['⚠️ VENCIDAS',         $data['overdue'] ?? []],
        'today'     => ['🔴 Vence HOY',         $data['today']],
        'tomorrow'  => ['🟡 Vence mañana',      $data['tomorrow']],
        'this_week' => ['📅 Esta semana',        $data['this_week']],
    ];

    foreach ($sections as [$label, $cards]) {
        if (!$cards) continue;
        $html .= "<h3 style='margin:20px 0 10px;font-size:14px;font-family:Syne,sans-serif'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</h3>";
        $html .= "<div style='background:#f8f9fc;border-radius:8px;overflow:hidden;border:1px solid #e8eaf2'>";
        foreach ($cards as $c) {
            // Validate $pColor is a safe hex color to prevent CSS injection
            $rawColor = $prioColors[$c['priority']??''] ?? $c['col_color'] ?? '#5b6af0';
            $pColor = preg_match('/^#[0-9a-fA-F]{3,6}$/', $rawColor) ? $rawColor : '#5b6af0';
            $pLabel = $prioLabels[$c['priority']??''] ?? '';
            $eDueDate = htmlspecialchars($c['due_date'] ?? '', ENT_QUOTES, 'UTF-8');
            $ePrioLabel = htmlspecialchars($pLabel, ENT_QUOTES, 'UTF-8');
            $html .= "<div style='display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #e8eaf2'>
                <div style='width:3px;height:36px;border-radius:99px;background:{$pColor};flex-shrink:0'></div>
                <div style='flex:1'>
                  <div style='font-weight:700;font-size:13px'>" . htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') . "</div>
                  <div style='font-size:11px;color:#8888aa;margin-top:2px'>" . htmlspecialchars($c['ws_name'], ENT_QUOTES, 'UTF-8') . " · " . htmlspecialchars($c['col_title'], ENT_QUOTES, 'UTF-8') . ($ePrioLabel ? " · <span style='color:{$pColor}'>{$ePrioLabel}</span>" : '') . "</div>
                </div>
                <div style='font-size:11px;font-weight:700;color:{$pColor};font-family:monospace'>{$eDueDate}</div>
              </div>";
        }
        $html .= "</div>";
    }

    $total = count($data['overdue'] ?? []) + count($data['today']) + count($data['tomorrow']) + count($data['this_week']);

    // Botones de acción
    $html .= "<br><div style='display:flex;gap:12px;flex-wrap:wrap;margin-top:4px'>";
    $html .= "<a href='{$siteUrl}' style='display:inline-block;background:#5b6af0;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px'>Ver todas mis tareas ({$total}) →</a>";

    if ($plainText) {
        $waUrl = 'https://wa.me/?text=' . rawurlencode($plainText);
        $html .= "<a href='{$waUrl}' style='display:inline-block;background:#25D366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px'>Compartir en WhatsApp 📲</a>";
    }

    $html .= "</div>";
    return $html;
}

// ── Generar texto plano para WhatsApp/Telegram ─────────
function buildReminderPlainText($data) {
    $lines = ["🌙 *Hola {$data['user_name']}* — Resumen de tareas por vencer:\n"];
    $sections = [
        'overdue'   => '⚠️ *VENCIDAS*',
        'today'     => '🔴 *Vence HOY*',
        'tomorrow'  => '🟡 *Vence mañana*',
        'this_week' => '📅 *Esta semana*',
    ];
    foreach ($sections as $key => $label) {
        if (empty($data[$key])) continue;
        $lines[] = "\n{$label}";
        foreach ($data[$key] as $c) {
            $colLabel = !empty($c['col_title']) ? $c['col_title'] : 'Sin columna';
            $lines[] = "  • " . $c['title'] . " [{$colLabel}] ({$c['ws_name']}) — {$c['due_date']}";
        }
    }
    $total = count($data['overdue'] ?? []) + count($data['today']) + count($data['tomorrow']) + count($data['this_week']);
    $lines[] = "\n📊 Total: {$total} tarea(s)";
    return implode("\n", $lines);
}

// ── Main: send reminders ───────────────────────────────────
if ($action === 'send' || $action === 'preview') {
    // Leer matriz de filtrado del body o de app_settings
    $filter_matrix = $body_json['matrix'] ?? null;
    if ($filter_matrix === null) {
        try {
            $cfg_row = getDB()->query("SELECT meta_value FROM ".tb('app_settings')." WHERE meta_key='reminder_config' LIMIT 1")->fetch();
            if ($cfg_row) {
                $cfg_data = json_decode($cfg_row['meta_value'], true);
                $filter_matrix = $cfg_data['matrix'] ?? null;
            }
        } catch (Exception $e) {}
    }

    $cards   = getUpcomingCards($db);
    $byUser  = groupByUser($cards);

    // Aplicar filtro de matriz por usuario × columna
    if ($filter_matrix !== null && is_array($filter_matrix)) {
        foreach ($byUser as $uid => &$userData) {
            $allowedCols = isset($filter_matrix[(string)$uid]) ? (array)$filter_matrix[(string)$uid] : null;
            if ($allowedCols === null) continue; // null = todas las columnas permitidas
            foreach (['overdue','today','tomorrow','this_week'] as $grp) {
                $userData[$grp] = array_values(array_filter($userData[$grp], fn($c) => in_array($c['col_title'] ?? '', $allowedCols)));
            }
        }
        unset($userData);
        // Quitar usuarios sin tareas tras el filtro
        $byUser = array_filter($byUser, fn($ud) =>
            count($ud['overdue'] ?? []) + count($ud['today']) + count($ud['tomorrow']) + count($ud['this_week']) > 0
        );
    }
    $siteUrl = defined('LUNA_SITE_URL') ? LUNA_SITE_URL : ((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost'));

    $sent    = 0;
    $errors  = 0;
    $preview = [];

    foreach ($byUser as $userData) {
        if (!$userData['user_email']) continue;
        $total = count($userData['overdue'] ?? []) + count($userData['today']) + count($userData['tomorrow']) + count($userData['this_week']);
        if ($total === 0) continue;

        $hasOverdue = count($userData['overdue'] ?? []) > 0;
        $hasToday   = count($userData['today']) > 0;
        $subject = $hasOverdue
            ? "⚠️ Tenés " . count($userData['overdue']) . " tarea(s) vencidas — Luna Workspace"
            : ($hasToday
                ? "🔴 Tenés {$total} tarea(s) que vencen hoy — Luna Workspace"
                : "📅 Recordatorio: {$total} tarea(s) por vencer esta semana");

        $plain = buildReminderPlainText($userData);
        $body = buildReminderBody($userData, $siteUrl, $plain);
        $preview[] = ['to'=>$userData['user_email'],'name'=>$userData['user_name'],'total'=>$total,'subject'=>$subject];

        if ($action === 'send') {
            $userForNotif = [
                'name'                 => $userData['user_name'],
                'email'                => $userData['user_email'],
                'phone'                => $userData['phone'] ?? '',
                'whatsapp_apikey'      => $userData['whatsapp_apikey'] ?? '',
                'telegram_chat_id'     => $userData['telegram_chat_id'] ?? '',
                'notification_channel' => $userData['notification_channel'] ?? 'email',
            ];
            $result = sendNotificationToUser($userForNotif, $subject, $body, $plain);
            if ($result['ok']) {
                $sent++;
                // Log notification
                foreach (array_merge($userData['overdue'] ?? [],$userData['today'],$userData['tomorrow'],$userData['this_week']) as $c) {
                    try{
                        $db->prepare("INSERT INTO ".tb('notifications')." (user_id,from_user_id,type,card_id,workspace_id,message) VALUES (?,0,'due_soon',?,?,?)")
                           ->execute([$userData['user_id'],$c['id'],$c['ws_id'],"Vence: {$c['due_date']}"]);
                    }catch(Exception $e){}
                }
            } else { $errors++; }
        }
    }

    if ($action === 'send') {
        try {
            getDB()->prepare("INSERT INTO ".tb('app_settings')." (meta_key,meta_value) VALUES ('reminders_last_sent',?) ON DUPLICATE KEY UPDATE meta_value=?")
                   ->execute([date('Y-m-d'), date('Y-m-d')]);
        } catch (Exception $e) {}
    }

    // ── Send WhatsApp summary to admin ────────────
    if ($action === 'send') {
        $overdueTotal = 0; $todayTotal = 0; $weekTotal = 0;
        foreach ($byUser as $ud) {
            $overdueTotal += count($ud['overdue'] ?? []);
            $todayTotal   += count($ud['today']);
            $weekTotal    += count($ud['tomorrow']) + count($ud['this_week']);
        }
        if ($overdueTotal + $todayTotal + $weekTotal > 0) {
            $waTxt = "🌙 *Luna Workspace — Recordatorio*\n";
            if ($overdueTotal > 0) $waTxt .= "⚠️ *{$overdueTotal}* tarea(s) *VENCIDAS*\n";
            if ($todayTotal   > 0) $waTxt .= "🔴 *{$todayTotal}* tarea(s) vencen *HOY*\n";
            if ($weekTotal    > 0) $waTxt .= "📅 *{$weekTotal}* tarea(s) vencen esta semana\n";
            $waTxt .= "📧 {$sent} recordatorio(s) enviado(s) por email";
            sendWhatsApp($waTxt);
        }
    }

    if (php_sapi_name() === 'cli') {
        echo "Recordatorios: $sent enviados, $errors errores\n";
        foreach ($preview as $p) echo "  → {$p['user_email']} ({$p['total']} tareas)\n";
    } else {
        jsonOut([
            'ok'      => true,
            'sent'    => $sent,
            'errors'  => $errors,
            'preview' => $preview,
            'action'  => $action,
        ]);
    }
    exit;
}

jsonErr('Acción no encontrada', 404);
