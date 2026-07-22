<?php
/**
 * Plugin Name:       Luna Workspace
 * Plugin URI:        https://websobreruedas.com
 * Description:       Pizarra Colaborativa, gestión de tareas, equipos y proyectos. Versión 11.1.53 | Por Web Sobre Ruedas | 2026 | websobreruedas.com
 * Version:           11.1.94
 * Author:            Web Sobre Ruedas
 * License:           Proprietary
 * Text Domain:       luna-workspace
 */

defined('ABSPATH') || exit;

define('LUNA_VERSION',     '11.1.94');
define('LUNA_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('LUNA_PLUGIN_URL',  plugin_dir_url(__FILE__));
define('LUNA_APP_DIR',     LUNA_PLUGIN_DIR . 'app/');
define('LUNA_APP_URL',     LUNA_PLUGIN_URL . 'app/');

require_once LUNA_PLUGIN_DIR . 'includes/class-luna-activator.php';
require_once LUNA_PLUGIN_DIR . 'includes/class-luna-admin.php';
require_once LUNA_PLUGIN_DIR . 'includes/class-luna-license.php';
require_once LUNA_PLUGIN_DIR . 'includes/class-luna-register.php';
add_action('init', ['Luna_Register', 'init']);

register_activation_hook(__FILE__,   ['Luna_Activator', 'activate']);
register_activation_hook(__FILE__,   'luna_set_activation_redirect');
register_deactivation_hook(__FILE__, ['Luna_Activator', 'deactivate']);

function luna_set_activation_redirect() {
    // Solo redirigir si es una activación real (no una actualización masiva)
    if (!isset($_GET['activate-multi'])) {
        set_transient('luna_activation_redirect', 1, 60);
    }
}

add_action('plugins_loaded', 'luna_init');
function luna_init() {
    // Always regenerate app/luna-wp-config.php if missing.
    if (!file_exists(LUNA_APP_DIR . 'luna-wp-config.php')) {
        Luna_Activator::regenerate_app_config();
    }
    // Si el config actual funciona y aún no hay respaldo en wp_options,
    // guardarlo (solo corre si falta la opción — barato)
    Luna_Activator::maybe_backup_current_config();

    Luna_Activator::ensure_client_tables();
    Luna_Activator::migrate_cobros_tables();
    Luna_Activator::migrate_subscription_fields();

    if (!wp_next_scheduled('luna_daily_billing')) {
        wp_schedule_event(time(), 'daily', 'luna_daily_billing');
    }
    add_action('luna_daily_billing', 'luna_run_daily_billing_check');

    if (is_admin()) {
        new Luna_Admin();
    }
    add_shortcode('luna_workspace', 'luna_render_shortcode');
    add_action('template_redirect',  'luna_fullpage_redirect');
    add_action('init',               'luna_handle_admin_enter');
    add_action('init',               'luna_handle_admin_enter_legacy');
    add_action('wp_ajax_luna_save_license',        'luna_ajax_save_license');
    add_action('wp_ajax_luna_check_license_status','luna_ajax_check_license_status');
    add_action('wp_ajax_luna_cobros',              'luna_ajax_cobros');
    add_action('wp_ajax_nopriv_luna_cobros',       'luna_ajax_cobros');
    add_action('luna_hourly_check', 'luna_run_hourly_check');
}

function luna_run_hourly_check() {
    // Read reminder hour from Luna DB
    global $wpdb;
    $p = $wpdb->prefix . 'luna_';
    $row = $wpdb->get_row("SELECT meta_value FROM `{$p}app_settings` WHERE meta_key='reminder_schedule'", ARRAY_A);
    $schedule = $row ? json_decode($row['meta_value'], true) : [];
    if (empty($schedule['enabled'])) return;

    $hour = (int)($schedule['hour'] ?? 8);
    $current_hour = (int)date('G'); // server hour
    if ($current_hour !== $hour) return;

    // Fire the reminders endpoint — cron_secret va en el body POST (no en la URL para evitar logs)
    $secret = get_option('luna_cron_secret', '');
    if (!$secret) return;
    $url = LUNA_APP_URL . 'api/reminders.php?action=send';
    wp_remote_post($url, [
        'timeout'   => 60,
        'blocking'  => false,
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode(['cron_secret' => $secret]),
    ]);
}

// ── Admin enter con token permanente (no requiere sesión WP, no expira) ──────
function luna_handle_admin_enter() {
    if (!isset($_GET['luna_enter'])) return;

    $stored = get_option('luna_entry_token', '');
    if (!$stored || !hash_equals($stored, (string)$_GET['luna_enter'])) {
        wp_die('Token inválido. Regenerá el token en Luna Workspace → Configuración.');
    }

    global $wpdb;
    $p = $wpdb->prefix . 'luna_';

    // Buscar usuario admin de Luna
    $admin = $wpdb->get_row("SELECT * FROM `{$p}users` WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    if (!$admin) {
        Luna_Activator::activate();
        $admin = $wpdb->get_row("SELECT * FROM `{$p}users` WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    }
    if (!$admin) {
        wp_die('No se encontró usuario admin de Luna. Ir a Luna Workspace → Base de datos → Resetear contraseña admin.');
    }

    // Crear sesión Luna
    $token   = bin2hex(random_bytes(32));
    $hours   = (int) get_option('luna_session_hours', 24);
    $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
    $wpdb->insert("{$p}sessions", ['token' => $token, 'user_id' => $admin['id'], 'expires_at' => $expires]);
    $wpdb->update("{$p}users", ['last_login' => current_time('mysql')], ['id' => $admin['id']]);

    // Cookie para el frontend
    setcookie('luna_token', $token, [
        'expires'  => time() + $hours * 3600,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    wp_redirect(home_url('/?luna_app=1'));
    exit;
}

// ── Admin enter legado (con nonce WP) — se mantiene por compatibilidad ───────
function luna_handle_admin_enter_legacy() {
    if (!isset($_GET['luna_admin_enter'])) return;
    if (!current_user_can('manage_options')) wp_die('Sin permisos. Iniciá sesión en WordPress primero.');
    if (!check_admin_referer('luna_admin_enter')) wp_die('Nonce inválido o expirado. Volvé al panel de WordPress y usá el botón desde ahí.');

    global $wpdb;
    $p = $wpdb->prefix . 'luna_';
    $admin = $wpdb->get_row("SELECT * FROM `{$p}users` WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
    if (!$admin) { wp_redirect(admin_url('admin.php?page=luna-database&luna_msg=no_admin')); exit; }

    $token   = bin2hex(random_bytes(32));
    $hours   = (int) get_option('luna_session_hours', 24);
    $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
    $wpdb->insert("{$p}sessions", ['token' => $token, 'user_id' => $admin['id'], 'expires_at' => $expires]);
    $wpdb->update("{$p}users", ['last_login' => current_time('mysql')], ['id' => $admin['id']]);
    setcookie('luna_token', $token, ['expires' => time() + $hours * 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    wp_redirect(home_url('/?luna_app=1'));
    exit;
}

// ── Full-page redirect ──────────────────────────────────────────────────────
function luna_fullpage_redirect() {
    $slug = get_option('luna_page_slug', 'luna-app');
    if (is_page($slug) || !empty($_GET['luna_app'])) {
        luna_serve_app();
        exit;
    }
}

// ── Shortcode ───────────────────────────────────────────────────────────────
function luna_render_shortcode($atts) {
    $height = isset($atts['height']) ? esc_attr($atts['height']) : '100vh';
    $app_url = home_url('/?luna_app=1');
    $license = get_option('luna_license_key', '');
    ob_start(); ?>
    <div id="luna-wp-container" style="width:100%;height:<?php echo $height ?>;overflow:hidden;position:relative">
      <script>
        window.LUNA_WP = {
          licenseKey: <?php echo json_encode($license) ?>,
          apiUrl:     <?php echo json_encode(LUNA_APP_URL . 'api') ?>,
          ajaxUrl:    <?php echo json_encode(admin_url('admin-ajax.php')) ?>,
          nonce:      <?php echo json_encode(wp_create_nonce('luna_nonce')) ?>,
          showGantt:  <?php echo get_option('luna_show_gantt', 1) ? 'true' : 'false' ?>
        };
      </script>
      <iframe src="<?php echo esc_url($app_url) ?>"
              style="width:100%;height:100%;border:none;display:block"
              allow="fullscreen">
      </iframe>
    </div>
    <?php return ob_get_clean();
}

// ── Full-page server (bypasses WP theme entirely) ───────────────────────────
function luna_serve_app() {
    $index = LUNA_APP_DIR . 'index.html';
    if (!file_exists($index)) {
        wp_die('Luna Workspace: el archivo app/index.html no está instalado en el plugin.');
    }
    // Prevent browser and proxy caching of the app shell
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    $license = get_option('luna_license_key', '');
    $content = file_get_contents($index);
    // Inject LUNA_WP config before </head>
    $inject = '<style>'
        . '.ws-item .ws-img{width:38px!important;height:38px!important;border-radius:10px!important;flex-shrink:0!important;object-fit:cover;display:block}'
        . '.ws-item{position:relative;overflow:visible!important}'
        . '.ws-item .ws-actions{position:relative;z-index:2}'
        . '</style>'
        . '<script>window.LUNA_WP={licenseKey:' . json_encode($license)
        . ',apiUrl:'    . json_encode(LUNA_APP_URL . 'api')
        . ',ajaxUrl:'   . json_encode(admin_url('admin-ajax.php'))
        . ',nonce:'     . json_encode(wp_create_nonce('luna_nonce'))
        . ',showGantt:' . (get_option('luna_show_gantt', 1) ? 'true' : 'false')
        . '};</script>';
    // Usar la PRIMERA ocurrencia de </head> y la ÚLTIMA de </body>: el archivo
    // contiene literales "</head>" y "</body>" dentro de un template literal JS
    // (función printInforme), y un str_replace global ahí corta el <script>
    // principal de la página por la mitad (el navegador cierra el script en el
    // primer "</script>" que encuentra como texto, sin entender que está dentro
    // de un string), dejando doLogin sin definir.
    $headPos = strpos($content, '</head>');
    if ($headPos !== false) {
        $content = substr_replace($content, $inject . '</head>', $headPos, strlen('</head>'));
    }
    // Fix relative API paths to absolute plugin paths
    $content = str_replace('src="api/', 'src="' . LUNA_APP_URL . 'api/', $content);
    $content = str_replace("src='api/", "src='" . LUNA_APP_URL . "api/", $content);
    // Inject permanent branding bar — always visible on all plans
    $bodyPos = strrpos($content, '</body>');
    if ($bodyPos !== false) {
        $content = substr_replace($content, luna_branding_bar() . '</body>', $bodyPos, strlen('</body>'));
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $content;
}

// ── Branding bar — always last child of body, survives SPA renders ───────────
function luna_branding_bar(): string {
    $css = '#lads{'
        . 'position:fixed!important;bottom:0!important;left:0!important;right:0!important;'
        . 'height:36px!important;background:#050810!important;'
        . 'border-top:1px solid #161d38!important;'
        . 'display:flex!important;align-items:center!important;justify-content:center!important;'
        . 'z-index:2147483647!important;font-family:\"Segoe UI\",system-ui,sans-serif!important;'
        . 'pointer-events:auto!important;visibility:visible!important;opacity:1!important;'
        . '}'
        . '#lads a{'
        . 'color:#2e3a6e!important;text-decoration:none!important;font-size:11px!important;'
        . 'font-weight:600!important;letter-spacing:.4px!important;'
        . 'display:flex!important;align-items:center!important;gap:5px!important;'
        . '}'
        . '#lads a:hover{color:#5b6af0!important;}';

    return '<script>'
        . '(function(){'
        .   'var CSS=' . json_encode($css) . ';'
        .   'function ensureStyle(){'
        .     'if(document.getElementById("lads-css"))return;'
        .     'var s=document.createElement("style");s.id="lads-css";s.textContent=CSS;'
        .     '(document.head||document.documentElement).appendChild(s);'
        .   '}'
        .   'function ensureBar(){'
        .     'ensureStyle();'
        .     'document.documentElement.style.setProperty("padding-bottom","36px","important");'
        .     'var bar=document.getElementById("lads");'
        .     'if(!bar){'
        .       'bar=document.createElement("div");bar.id="lads";'
        .       'bar.innerHTML="<a href=\"https://websobreruedas.com\" target=\"_blank\" rel=\"noopener\">'
        .         '\xf0\x9f\x8c\x99 Luna Workspace &nbsp;\xc2\xb7&nbsp; websobreruedas.com</a>";'
        .     '}'
        .     'document.body.appendChild(bar);'
        .   '}'
        .   'function run(){'
        .     'ensureBar();'
        .     'new MutationObserver(function(ml){'
        .       'for(var i=0;i<ml.length;i++){'
        .         'if(ml[i].addedNodes.length&&ml[i].addedNodes[0].id!=="lads"){ensureBar();break;}'
        .       '}'
        .     '}).observe(document.body,{childList:true});'
        .     'setInterval(ensureBar,2000);'
        .   '}'
        .   'if(document.readyState==="loading"){'
        .     'document.addEventListener("DOMContentLoaded",run);'
        .   '}else{run();}'
        . '})();'
        . '</script>';
}
// ── AJAX: save license key ───────────────────────────────────────────────────
function luna_ajax_save_license() {
    check_ajax_referer('luna_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $key = sanitize_text_field($_POST['license_key'] ?? '');
    update_option('luna_license_key', $key);
    // Also write to app/config-license.php so API files can read it without WP
    luna_write_license_config($key);
    wp_send_json_success(['message' => 'Licencia guardada']);
}

// ── AJAX: verify license status ──────────────────────────────────────────────
function luna_ajax_check_license_status() {
    check_ajax_referer('luna_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $key    = get_option('luna_license_key', '');
    $result = Luna_License::verify($key, $_SERVER['HTTP_HOST'] ?? '');
    wp_send_json($result);
}

// ── When license key is saved, regenerate credentials file ───────────────────
function luna_write_license_config($key) {
    Luna_Activator::regenerate_app_config();
    // Also delete license cache so it gets re-verified with new key
    @unlink(LUNA_APP_DIR . 'luna-license-cache.json');
}

// ── Recordatorio diario de cobranzas ─────────────────────────────────────────
function luna_run_daily_billing_check() {
    global $wpdb;
    $p = $wpdb->prefix . 'luna_';

    $admin_email = get_option('admin_email');
    $site_name   = get_bloginfo('name');
    $fecha       = date('d/m/Y');

    // ── WhatsApp admin (se reutiliza abajo) ───────────────────────────────────
    $admin_wa = $wpdb->get_row(
        "SELECT phone, whatsapp_apikey FROM `{$p}users`
         WHERE role='admin' AND active=1 AND phone != '' AND whatsapp_apikey != ''
         ORDER BY id LIMIT 1",
        ARRAY_A
    );

    // ── 1. Abonos con billing_day = hoy ──────────────────────────────────────
    $today_day = (int) date('j');
    $clients = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, name, domain, renewal_amount
         FROM `{$p}clients`
         WHERE is_subscription = 1 AND billing_day = %d AND active = 1
         ORDER BY name",
        $today_day
    ), ARRAY_A );

    if ( ! empty( $clients ) ) {
        $lines = [];
        $total = 0;
        foreach ( $clients as $c ) {
            $monto   = '$' . number_format( (float)$c['renewal_amount'], 0, ',', '.' );
            $domain  = $c['domain'] ? " ({$c['domain']})" : '';
            $lines[] = "• {$c['name']}{$domain} — {$monto}";
            $total  += (float) $c['renewal_amount'];
        }
        $resumen    = implode( "\n", $lines );
        $total_fmt  = '$' . number_format( $total, 0, ',', '.' );
        $cantidad   = count( $clients );
        $titulo_wa  = "🔔 Cobranzas del {$fecha} ({$cantidad} cliente" . ($cantidad > 1 ? 's' : '') . ")";
        $msg_wa     = "{$titulo_wa}\n\n{$resumen}\n\nTotal del día: {$total_fmt}";

        wp_mail( $admin_email, "[{$site_name}] Cobranzas de hoy — {$fecha}",
            "Recordatorio de cobranzas — {$fecha}\n\n{$resumen}\n\nTotal del día: {$total_fmt}\n\nGenerado por Luna Workspace." );

        if ( $admin_wa ) {
            wp_remote_get( 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
                'phone'  => $admin_wa['phone'],
                'text'   => $msg_wa,
                'apikey' => $admin_wa['whatsapp_apikey'],
            ]), ['timeout' => 15, 'blocking' => false] );
        }
    }

    // ── 2. Vencimientos próximos (clientes no abonados) ───────────────────────
    $days_ahead  = (int) get_option('luna_renewal_reminder_days', 7);
    $target_date = date('Y-m-d', strtotime("+{$days_ahead} days"));
    $upcoming    = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, name, domain, renewal_date, renewal_amount
         FROM `{$p}clients`
         WHERE is_subscription = 0 AND renewal_date = %s AND active = 1
         ORDER BY name",
        $target_date
    ), ARRAY_A );

    if ( ! empty( $upcoming ) ) {
        $fecha_venc = date('d/m/Y', strtotime($target_date));
        $up_lines   = [];
        $up_total   = 0;
        foreach ( $upcoming as $c ) {
            $monto      = '$' . number_format( (float)$c['renewal_amount'], 0, ',', '.' );
            $domain     = $c['domain'] ? " ({$c['domain']})" : '';
            $up_lines[] = "• {$c['name']}{$domain} — Vence: {$fecha_venc} — {$monto}";
            $up_total  += (float) $c['renewal_amount'];
        }
        $up_resumen   = implode( "\n", $up_lines );
        $up_total_fmt = '$' . number_format( $up_total, 0, ',', '.' );
        $up_cant      = count( $upcoming );
        $titulo_up    = "⚠️ Vencimientos en {$days_ahead} días ({$up_cant} cliente" . ($up_cant > 1 ? 's' : '') . ")";

        wp_mail( $admin_email, "[{$site_name}] Vencimientos próximos — {$fecha_venc}",
            "Vencimientos en {$days_ahead} días ({$fecha_venc})\n\n{$up_resumen}\n\nTotal: {$up_total_fmt}\n\nGenerado por Luna Workspace." );

        if ( $admin_wa ) {
            wp_remote_get( 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
                'phone'  => $admin_wa['phone'],
                'text'   => "{$titulo_up}\n\n{$up_resumen}\n\nTotal: {$up_total_fmt}",
                'apikey' => $admin_wa['whatsapp_apikey'],
            ]), ['timeout' => 15, 'blocking' => false] );
        }
    }
}

// ── Validate Luna session from cookie ────────────────────────────────────────
function luna_validate_session() {
    global $wpdb;
    $p     = $wpdb->prefix . 'luna_';
    $token = sanitize_text_field($_COOKIE['luna_token'] ?? '');
    if (!$token) return null;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT s.user_id, u.role, u.name FROM `{$p}sessions` s
         JOIN `{$p}users` u ON u.id = s.user_id
         WHERE s.token = %s AND s.expires_at > NOW()",
        $token
    ), ARRAY_A);
}

// ── AJAX: Cobranza y Cta Cte (accesible a todos los usuarios autenticados) ───
function luna_ajax_cobros() {
    $session = luna_validate_session();
    if (!$session) { wp_send_json_error('No autorizado'); return; }

    $sub = sanitize_text_field($_POST['sub'] ?? '');
    global $wpdb;
    $p = $wpdb->prefix . 'luna_';

    switch ($sub) {

        case 'get_clients':
            $rows = $wpdb->get_results(
                "SELECT id, name, cuit, email, phone FROM `{$p}clients` WHERE active=1 ORDER BY name",
                ARRAY_A
            );
            wp_send_json_success($rows ?: []);
            break;

        case 'get_card_cobros':
            $card_id = (int)($_POST['card_id'] ?? 0);
            if (!$card_id) { wp_send_json_error('card_id requerido'); return; }

            $meta = $wpdb->get_row($wpdb->prepare(
                "SELECT m.client_id, m.total_amount, c.name AS client_name
                 FROM `{$p}card_cobros_meta` m
                 LEFT JOIN `{$p}clients` c ON c.id = m.client_id
                 WHERE m.card_id = %d",
                $card_id
            ), ARRAY_A);

            $payments = $wpdb->get_results($wpdb->prepare(
                "SELECT p.id, p.amount, p.payment_date, p.method, p.notes,
                        u.name AS created_by_name
                 FROM `{$p}card_payments` p
                 LEFT JOIN `{$p}users` u ON u.id = p.created_by
                 WHERE p.card_id = %d
                 ORDER BY p.payment_date DESC, p.id DESC",
                $card_id
            ), ARRAY_A);

            $cobrado = array_sum(array_column($payments ?: [], 'amount'));
            wp_send_json_success([
                'meta'     => $meta,
                'payments' => $payments ?: [],
                'cobrado'  => $cobrado,
            ]);
            break;

        case 'save_card_meta':
            $card_id      = (int)($_POST['card_id'] ?? 0);
            $client_id    = (int)($_POST['client_id'] ?? 0) ?: null;
            $total_amount = (float)($_POST['total_amount'] ?? 0);
            if (!$card_id) { wp_send_json_error('card_id requerido'); return; }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT card_id FROM `{$p}card_cobros_meta` WHERE card_id = %d", $card_id
            ));
            if ($existing) {
                $wpdb->update("{$p}card_cobros_meta",
                    ['client_id' => $client_id, 'total_amount' => $total_amount],
                    ['card_id'   => $card_id]
                );
            } else {
                $wpdb->insert("{$p}card_cobros_meta", [
                    'card_id'      => $card_id,
                    'client_id'    => $client_id,
                    'total_amount' => $total_amount,
                ]);
            }
            wp_send_json_success();
            break;

        case 'add_payment':
            $card_id = (int)($_POST['card_id'] ?? 0);
            $amount  = (float)($_POST['amount'] ?? 0);
            $date    = sanitize_text_field($_POST['payment_date'] ?? date('Y-m-d'));
            $method  = sanitize_text_field($_POST['method'] ?? '');
            $notes   = sanitize_textarea_field($_POST['notes'] ?? '');
            if (!$card_id || $amount <= 0) { wp_send_json_error('Datos inválidos'); return; }

            $wpdb->insert("{$p}card_payments", [
                'card_id'      => $card_id,
                'amount'       => $amount,
                'payment_date' => $date,
                'method'       => $method,
                'notes'        => $notes,
                'created_by'   => (int)$session['user_id'],
                'created_at'   => current_time('mysql'),
            ]);
            wp_send_json_success(['id' => $wpdb->insert_id]);
            break;

        case 'del_payment':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { wp_send_json_error('id requerido'); return; }
            $wpdb->delete("{$p}card_payments", ['id' => $id]);
            wp_send_json_success();
            break;

        default:
            wp_send_json_error('Acción desconocida');
    }
}
