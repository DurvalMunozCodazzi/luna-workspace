<?php
defined('ABSPATH') || exit;

/**
 * Luna_Register — Landing page + registro Plan Gratis
 * Shortcode: [luna_gratis]
 * Crea la página automáticamente en la activación del plugin.
 */
class Luna_Register {

    const OTP_TTL     = 600; // 10 minutos
    const VENDOR_EMAIL = 'pedidos47@gmail.com';
    const MAIL_FROM    = 'no-reply@websobreruedas.com';
    const MAIL_NAME    = 'Luna Workspace';
    const WA_SOPORTE   = '5491153283558';

    // CallMeBot — notificaciones WA al vendor (ya activado para WA_SOPORTE)
    const WA_CALLMEBOT_APIKEY = '6291539';

    public static function init(): void {
        add_shortcode('luna_gratis', [self::class, 'shortcode']);
        add_action('wp_ajax_nopriv_luna_reg_send_otp',   [self::class, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_luna_reg_verify_otp', [self::class, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_luna_reg_resend_otp', [self::class, 'ajax_resend_otp']);
        add_action('wp_ajax_nopriv_luna_reg_check_domain',[self::class, 'ajax_check_domain']);
        add_action('wp_ajax_luna_reg_send_otp',   [self::class, 'ajax_send_otp']);
        add_action('wp_ajax_luna_reg_verify_otp', [self::class, 'ajax_verify_otp']);
        add_action('wp_ajax_luna_reg_resend_otp', [self::class, 'ajax_resend_otp']);
        add_action('wp_ajax_luna_reg_check_domain',[self::class, 'ajax_check_domain']);
        self::create_tables();
    }

    // ── Tablas ────────────────────────────────────────────────────────────────
    public static function create_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_free_pending (
            id         BIGINT AUTO_INCREMENT PRIMARY KEY,
            token      CHAR(64)     NOT NULL UNIQUE,
            name       VARCHAR(100) NOT NULL,
            email      VARCHAR(180) NOT NULL,
            phone      VARCHAR(30)  NOT NULL,
            domain     VARCHAR(255) NOT NULL,
            otp        CHAR(6)      NOT NULL,
            attempts   TINYINT      DEFAULT 0,
            verified   TINYINT      DEFAULT 0,
            created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX(token), INDEX(email), INDEX(domain)
        ) $c;");
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_free_licenses (
            id          BIGINT AUTO_INCREMENT PRIMARY KEY,
            license_key CHAR(36)     NOT NULL UNIQUE,
            name        VARCHAR(100) NOT NULL,
            email       VARCHAR(180) NOT NULL,
            phone       VARCHAR(30)  NOT NULL,
            domain      VARCHAR(255) NOT NULL,
            plan        VARCHAR(20)  DEFAULT 'free',
            status      ENUM('active','revoked','expired') DEFAULT 'active',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_verify DATETIME DEFAULT NULL,
            INDEX(license_key), INDEX(email), INDEX(domain)
        ) $c;");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private static function json_out(array $d): void {
        wp_send_json($d);
    }

    private static function gen_otp(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function gen_token(): string { return bin2hex(random_bytes(32)); }

    private static function gen_key(): string {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function clean_domain(string $url): string {
        $url = trim($url);
        if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
        return strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    }

    private static function rate_limit(string $key, int $max, int $window): bool {
        $f    = sys_get_temp_dir() . '/lr_' . md5($key);
        $now  = time();
        $hits = file_exists($f) ? array_filter(json_decode(file_get_contents($f), true) ?: [], fn($t) => $t > $now - $window) : [];
        if (count($hits) >= $max) return false;
        $hits[] = $now;
        file_put_contents($f, json_encode(array_values($hits)), LOCK_EX);
        return true;
    }

    // ── AJAX: verificar dominio (tiempo real) ─────────────────────────────────
    public static function ajax_check_domain(): void {
        global $wpdb;
        $domain = self::clean_domain(sanitize_text_field($_POST['domain'] ?? ''));
        if (!$domain) self::json_out(['available' => false, 'msg' => 'Dominio inválido']);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}luna_free_licenses WHERE domain=%s AND status='active'", $domain
        ));
        if (!$exists) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}luna_free_pending WHERE domain=%s AND verified=1", $domain
            ));
        }
        self::json_out(['available' => !$exists, 'domain' => $domain]);
    }

    // ── AJAX: Paso 1 — Enviar OTP ─────────────────────────────────────────────
    public static function ajax_send_otp(): void {
        global $wpdb;
        $name   = sanitize_text_field($_POST['name']   ?? '');
        $email  = sanitize_email($_POST['email']       ?? '');
        $phone  = preg_replace('/[^0-9+\s\-()]/', '', $_POST['phone'] ?? '');
        $domain = self::clean_domain($_POST['domain']  ?? '');

        if (!$name)                              self::json_out(['ok'=>false,'field'=>'name',  'msg'=>'Ingresá tu nombre completo.']);
        if (!is_email($email))                   self::json_out(['ok'=>false,'field'=>'email', 'msg'=>'Email inválido.']);
        if (strlen(preg_replace('/\D/','',$phone)) < 8) self::json_out(['ok'=>false,'field'=>'phone','msg'=>'Teléfono inválido (mínimo 8 dígitos).']);
        if (!$domain)                            self::json_out(['ok'=>false,'field'=>'domain','msg'=>'Dominio inválido.']);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!self::rate_limit('otp_ip_'.$ip, 4, 600))
            self::json_out(['ok'=>false,'msg'=>'Demasiados intentos. Esperá 10 minutos.']);

        $taken_domain = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}luna_free_licenses WHERE domain=%s AND status='active'", $domain));
        if (!$taken_domain) {
            $taken_domain = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}luna_free_pending WHERE domain=%s AND verified=1", $domain));
        }
        if ($taken_domain)
            self::json_out(['ok'=>false,'field'=>'domain','msg'=>'Ese dominio ya tiene un registro pendiente o una licencia activa. Revisá tu email.']);

        $taken_email = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}luna_free_licenses WHERE email=%s AND status='active'", $email));
        if (!$taken_email) {
            $taken_email = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}luna_free_pending WHERE email=%s AND verified=1", $email));
        }
        if ($taken_email)
            self::json_out(['ok'=>false,'field'=>'email','msg'=>'Ese email ya tiene un registro pendiente. Revisá tu bandeja o escribinos.']);

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}luna_free_pending WHERE (email=%s AND verified=0) OR (created_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE) AND verified=0)", $email));

        $otp   = self::gen_otp();
        $token = self::gen_token();
        $wpdb->insert("{$wpdb->prefix}luna_free_pending",
            ['token'=>$token,'name'=>$name,'email'=>$email,'phone'=>$phone,'domain'=>$domain,'otp'=>$otp]);

        if (!self::send_otp_email($email, $name, $otp, $domain))
            self::json_out(['ok'=>false,'msg'=>'No se pudo enviar el email. Verificá la dirección.']);

        self::json_out(['ok'=>true,'token'=>$token,'email_hint'=>substr($email,0,3).'***@'.explode('@',$email)[1]]);
    }

    // ── AJAX: Paso 2 — Verificar OTP y emitir clave ───────────────────────────
    public static function ajax_verify_otp(): void {
        global $wpdb;
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $otp   = preg_replace('/\D/', '', $_POST['otp'] ?? '');
        if (!$token || !$otp) self::json_out(['ok'=>false,'msg'=>'Datos incompletos.']);

        if (!self::rate_limit('otp_tok_'.$token, 5, 600))
            self::json_out(['ok'=>false,'msg'=>'Demasiados intentos. Solicitá un nuevo código.']);

        $p = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}luna_free_pending WHERE token=%s AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)", $token), ARRAY_A);

        if (!$p) self::json_out(['ok'=>false,'msg'=>'El código expiró. Iniciá el registro nuevamente.','expired'=>true]);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}luna_free_pending SET attempts=attempts+1 WHERE token=%s", $token));

        if ($p['otp'] !== $otp) {
            $left = max(0, 5 - (int)$p['attempts']);
            self::json_out(['ok'=>false,'msg'=>"Código incorrecto. Intentos restantes: $left."]);
        }

        // Mark as verified — do NOT generate key. Company reviews manually and sends key by email.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}luna_free_pending SET verified=1, otp='', attempts=0 WHERE token=%s", $token));

        self::notify_vendor($p['name'], $p['email'], $p['phone'], $p['domain']);

        self::json_out(['ok'=>true,'name'=>$p['name'],'domain'=>$p['domain']]);
    }

    // ── AJAX: Reenviar OTP ────────────────────────────────────────────────────
    public static function ajax_resend_otp(): void {
        global $wpdb;
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        if (!$token) self::json_out(['ok'=>false,'msg'=>'Token inválido.']);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!self::rate_limit('resend_'.$ip, 2, 600))
            self::json_out(['ok'=>false,'msg'=>'Límite de reenvíos alcanzado. Esperá 10 minutos.']);

        $p = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}luna_free_pending WHERE token=%s",$token), ARRAY_A);
        if (!$p) self::json_out(['ok'=>false,'msg'=>'Sesión expirada.','expired'=>true]);

        $otp = self::gen_otp();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}luna_free_pending SET otp=%s, attempts=0, created_at=NOW() WHERE token=%s",$otp,$token));
        self::send_otp_email($p['email'], $p['name'], $otp, $p['domain']);
        self::json_out(['ok'=>true]);
    }

    // ── Email ─────────────────────────────────────────────────────────────────
    private static function send_otp_email(string $to, string $name, string $otp, string $domain): bool {
        $subject = "Tu código Luna Workspace: $otp";
        $body = self::email_tpl($name, "
            <p>Ingresaste <strong style='color:#67e8f9'>$domain</strong> como dominio para tu Plan Gratis de Luna Workspace.</p>
            <p style='margin-top:16px'>Tu código de verificación:</p>
            <div style='text-align:center;margin:28px 0;background:#0f1322;border:1px solid #1e2540;border-radius:14px;padding:28px'>
              <span style='font-size:46px;font-weight:900;letter-spacing:12px;color:#5b6af0;font-family:Courier New,monospace'>$otp</span>
              <p style='margin-top:12px;color:#3d4470;font-size:12px'>Válido por 10 minutos</p>
            </div>
            <p style='color:#3d4470;font-size:12px'>Si no solicitaste esto, ignorá este mensaje.</p>
        ");
        return (bool) wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8', 'From: '.self::MAIL_NAME.' <'.self::MAIL_FROM.'>']);
    }

    private static function send_key_email(string $to, string $name, string $key, string $domain): bool {
        $subject = '🌙 Tu clave Luna Workspace — Plan Gratis activado';
        $siteUrl = get_site_url();
        $body = self::email_tpl($name, "
            <p>¡Tu cuenta fue verificada! La licencia <strong style='color:#86efac'>Plan Gratis</strong> está activa para:</p>
            <p style='text-align:center;color:#67e8f9;font-weight:800;font-size:17px;margin:16px 0'>🌐 $domain</p>
            <p>Tu clave de licencia (guardala en un lugar seguro):</p>
            <div style='background:#0f1322;border:1.5px solid rgba(91,106,240,.45);border-radius:12px;padding:22px;text-align:center;margin:20px 0'>
              <code style='font-size:14px;letter-spacing:2px;color:#a5b4fc;font-family:Courier New,monospace;word-break:break-all'>$key</code>
            </div>
            <p style='color:#8892c0;font-size:13px;margin-bottom:8px'><strong style='color:#e8edf8'>Pasos para activar:</strong></p>
            <table style='width:100%;font-size:12px;color:#8892c0'>
              <tr><td style='padding:6px 0;vertical-align:top'><span style='background:#5b6af0;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;margin-right:8px;font-size:11px'>1</span></td><td>Instalá el plugin <strong style='color:#e8edf8'>luna-workspace.zip</strong> desde WordPress → Plugins → Añadir</td></tr>
              <tr><td style='padding:6px 0;vertical-align:top'><span style='background:#5b6af0;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;margin-right:8px;font-size:11px'>2</span></td><td>Andá a <strong style='color:#e8edf8'>Luna Workspace → Licencia</strong> en el menú de administración</td></tr>
              <tr><td style='padding:6px 0;vertical-align:top'><span style='background:#5b6af0;color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;margin-right:8px;font-size:11px'>3</span></td><td>Pegá la clave y guardá. ¡Listo!</td></tr>
            </table>
            <div style='margin-top:24px;padding:16px;background:#0f1322;border-radius:10px;border:1px solid #1e2540;font-size:12px;color:#3d4470'>
              Plan Gratis incluye 1 usuario · 1 pizarra · tareas ilimitadas · calendario<br>
              <a href='$siteUrl/luna-gratis#planes' style='color:#5b6af0'>Ver todos los planes →</a>
            </div>
        ");
        return (bool) wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8', 'From: '.self::MAIL_NAME.' <'.self::MAIL_FROM.'>']);
    }

    private static function notify_vendor(string $name, string $email, string $phone, string $domain): void {
        if (!self::VENDOR_EMAIL) return;
        $subject = "🌙 Nuevo registro Plan Gratis PENDIENTE — $domain";
        $body    = "Nuevo registro verificado — pendiente de revisión y envío de clave:\n\n"
                 . "Nombre:   $name\n"
                 . "Email:    $email\n"
                 . "Teléfono: $phone\n"
                 . "Dominio:  $domain\n"
                 . "Fecha:    " . date('d/m/Y H:i') . " UTC\n\n"
                 . "El email fue verificado por OTP. Revisá los datos y enviá la clave manualmente.";
        wp_mail(self::VENDOR_EMAIL, $subject, $body, ['From: '.self::MAIL_NAME.' <'.self::MAIL_FROM.'>']);

        $wa = "🌙 *Luna Workspace — Nuevo registro*\n\n"
            . "Nombre: $name\n"
            . "Email: $email\n"
            . "Teléfono: $phone\n"
            . "Dominio: $domain\n\n"
            . "✅ Email verificado por OTP. Revisá y enviá la clave.";
        self::send_whatsapp($wa);
    }

    private static function send_whatsapp(string $msg): void {
        if (!self::WA_CALLMEBOT_APIKEY || !self::WA_SOPORTE) return;
        $url = 'https://api.callmebot.com/whatsapp.php'
             . '?phone=' . self::WA_SOPORTE
             . '&text='  . rawurlencode($msg)
             . '&apikey=' . self::WA_CALLMEBOT_APIKEY;
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $ctx);
    }

    private static function email_tpl(string $name, string $content): string {
        $first = esc_html(explode(' ', $name)[0]);
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#080b14;font-family:'Segoe UI',Arial,sans-serif">
<div style="max-width:580px;margin:40px auto">
  <div style="background:linear-gradient(135deg,#0a0d1a,#111630);border:1px solid #1e2540;border-radius:20px;overflow:hidden">
    <div style="padding:32px;text-align:center;border-bottom:1px solid #1e2540;position:relative">
      <div style="font-size:32px;margin-bottom:8px">🌙</div>
      <div style="font-size:21px;font-weight:900;color:#e8edf8;letter-spacing:-.3px">Luna Workspace</div>
      <div style="font-size:11px;color:#3d4470;margin-top:3px;text-transform:uppercase;letter-spacing:.6px">Plan Gratis</div>
    </div>
    <div style="padding:32px;color:#8892c0;font-size:14px;line-height:1.75">
      <p style="font-size:16px;font-weight:700;color:#e8edf8;margin-bottom:18px">Hola, $first 👋</p>
      $content
    </div>
    <div style="padding:18px 32px;border-top:1px solid #1e2540;text-align:center;font-size:11px;color:#3d4470">
      Luna Workspace · websobreruedas.com
    </div>
  </div>
</div>
</body></html>
HTML;
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────
    public static function shortcode(): string {
        $ajax = admin_url('admin-ajax.php');
        $wa   = self::WA_SOPORTE ? 'https://wa.me/'.self::WA_SOPORTE : '#';
        ob_start();
        ?>
<style>
#lr-wrap *{box-sizing:border-box;margin:0;padding:0}
#lr-wrap{
  --acc:#5b6af0;--acc2:#8b5cf6;--grn:#22c55e;--red:#ef4444;--ora:#f59e0b;--cya:#06b6d4;
  --bg:#080b14;--surf:#0f1322;--elev:#161b2e;--bdr:#1e2540;--bdrl:#2a3260;
  --t1:#e8edf8;--t2:#8892c0;--t3:#3d4470;
  font-family:'Segoe UI',system-ui,sans-serif;color:var(--t1);
}

/* ── HERO ── */
.lr-hero{background:linear-gradient(160deg,#070a12 0%,#0d1128 50%,#070a12 100%);padding:80px 24px 0;text-align:center;position:relative;overflow:hidden;border-bottom:1px solid var(--bdr)}
.lr-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 50% at 50% -5%,rgba(91,106,240,.22),transparent)}

/* floating cards bg */
.lr-cards-bg{position:absolute;inset:0;overflow:hidden;pointer-events:none}
.lr-fc{position:absolute;background:var(--surf);border:1px solid var(--bdr);border-radius:10px;padding:10px 14px;font-size:11px;color:var(--t2);white-space:nowrap;opacity:0;animation:lr-float 12s ease-in-out infinite}
.lr-fc:nth-child(1){left:5%;top:15%;animation-delay:0s}
.lr-fc:nth-child(2){left:12%;top:55%;animation-delay:2s}
.lr-fc:nth-child(3){right:6%;top:20%;animation-delay:1s}
.lr-fc:nth-child(4){right:10%;top:60%;animation-delay:3s}
.lr-fc:nth-child(5){left:30%;top:10%;animation-delay:4s}
.lr-fc:nth-child(6){right:28%;top:68%;animation-delay:1.5s}
.lr-fc-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;vertical-align:middle}
@keyframes lr-float{0%,100%{opacity:0;transform:translateY(12px)}15%,85%{opacity:.7}50%{transform:translateY(-8px)}}

.lr-badge{display:inline-block;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:5px 16px;border-radius:99px;margin-bottom:22px;animation:lr-fadein .6s ease}
.lr-hero h1{font-size:clamp(28px,5vw,52px);font-weight:900;letter-spacing:-1.5px;line-height:1.1;margin-bottom:16px;animation:lr-fadein .7s ease}
.lr-hero h1 .gr{background:linear-gradient(135deg,#a5b4fc 0%,#67e8f9 50%,#c4b5fd 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.lr-hero-sub{font-size:clamp(14px,2vw,17px);color:var(--t2);max-width:560px;margin:0 auto 36px;line-height:1.7;animation:lr-fadein .8s ease}

/* hero stats */
.lr-stats{display:inline-flex;gap:0;border:1px solid var(--bdr);border-radius:14px;overflow:hidden;margin-bottom:0;background:var(--surf);animation:lr-fadein .9s ease}
.lr-stat{padding:16px 28px;text-align:center;border-right:1px solid var(--bdr)}
.lr-stat:last-child{border:none}
.lr-stat-n{font-size:26px;font-weight:900;background:linear-gradient(135deg,#a5b4fc,#67e8f9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1}
.lr-stat-l{font-size:10px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}

/* ── MOCKUP ── */
.lr-mockup-wrap{position:relative;margin:0 auto;max-width:900px;padding:0 20px 0}
.lr-mockup{background:var(--surf);border:1px solid var(--bdr);border-radius:16px 16px 0 0;overflow:hidden;box-shadow:0 -20px 80px rgba(0,0,0,.5);margin-top:40px;transform:perspective(1200px) rotateX(6deg);transform-origin:bottom center;transition:.4s}
.lr-mockup:hover{transform:perspective(1200px) rotateX(2deg)}
.lr-mock-bar{background:var(--elev);padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--bdr)}
.lr-mock-dots{display:flex;gap:6px}
.lr-mock-dots span{width:10px;height:10px;border-radius:50%;background:var(--bdr)}
.lr-mock-dots span:nth-child(1){background:#ef4444}
.lr-mock-dots span:nth-child(2){background:#f59e0b}
.lr-mock-dots span:nth-child(3){background:#22c55e}
.lr-mock-title{font-size:11px;color:var(--t3);font-weight:700;margin-left:8px}
.lr-mock-body{padding:16px;display:flex;gap:12px;min-height:160px;overflow:hidden}
.lr-mock-col{background:rgba(30,37,64,.4);border-radius:10px;padding:10px;flex:1;min-width:0}
.lr-mock-col-h{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.lr-mock-card{background:var(--surf);border:1px solid var(--bdr);border-radius:7px;padding:8px 10px;margin-bottom:6px;font-size:10px;color:var(--t2);border-left:3px solid}
.lr-mock-card .lr-tag{display:inline-block;font-size:9px;font-weight:700;padding:2px 6px;border-radius:99px;margin-top:4px}
.lr-mock-count{background:rgba(91,106,240,.2);color:#a5b4fc;font-size:9px;font-weight:800;padding:2px 6px;border-radius:99px}

/* ── SECTIONS ── */
.lr-section{padding:72px 24px;max-width:1100px;margin:0 auto}
.lr-section-center{text-align:center}
.lr-sec-badge{display:inline-block;background:rgba(91,106,240,.15);border:1px solid rgba(91,106,240,.3);color:#a5b4fc;font-size:10px;font-weight:800;padding:3px 12px;border-radius:99px;letter-spacing:.6px;text-transform:uppercase;margin-bottom:14px}
.lr-section h2{font-size:clamp(22px,3.5vw,34px);font-weight:900;letter-spacing:-.6px;margin-bottom:12px;line-height:1.15}
.lr-section h2 em{font-style:normal;background:linear-gradient(135deg,#a5b4fc,#67e8f9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.lr-sub{font-size:15px;color:var(--t2);line-height:1.7;max-width:560px;margin:0 auto 40px}

/* ── FEATURES ── */
.lr-feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:16px}
@media(max-width:768px){.lr-feat-grid{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.lr-feat-grid{grid-template-columns:1fr}}
.lr-feat{background:var(--surf);border:1px solid var(--bdr);border-radius:16px;padding:24px;transition:.25s;position:relative;overflow:hidden}
.lr-feat::before{content:'';position:absolute;inset:0;opacity:0;transition:.3s;border-radius:16px}
.lr-feat:hover{border-color:var(--bdrl);transform:translateY(-3px);box-shadow:0 12px 40px rgba(0,0,0,.35)}
.lr-feat:hover::before{opacity:1;background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(91,106,240,.07),transparent)}
.lr-feat-icon{font-size:28px;margin-bottom:14px}
.lr-feat h3{font-size:14px;font-weight:800;color:var(--t1);margin-bottom:8px}
.lr-feat p{font-size:12px;color:var(--t2);line-height:1.6}
.lr-feat-new{position:absolute;top:14px;right:14px;font-size:9px;font-weight:800;background:rgba(91,106,240,.25);color:#a5b4fc;padding:3px 8px;border-radius:99px;text-transform:uppercase;letter-spacing:.5px}

/* ── PLANS ── */
.lr-plans{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:8px}
@media(max-width:900px){.lr-plans{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.lr-plans{grid-template-columns:1fr}}
.lr-plan{background:var(--surf);border:1px solid var(--bdr);border-radius:16px;padding:22px 18px;position:relative;transition:.2s}
.lr-plan.current{background:linear-gradient(145deg,rgba(34,197,94,.09),rgba(6,182,212,.06));border:1.5px solid rgba(34,197,94,.35)}
.lr-plan.pop{background:linear-gradient(145deg,rgba(91,106,240,.1),rgba(139,92,246,.07));border:1.5px solid rgba(91,106,240,.4)}
.lr-plan-tag{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:3px 9px;border-radius:99px;display:inline-block;margin-bottom:12px}
.lr-plan-name{font-size:16px;font-weight:900;color:var(--t1);margin-bottom:6px}
.lr-plan-price{font-size:28px;font-weight:900;line-height:1;margin-bottom:2px}
.lr-plan-note{font-size:10px;color:var(--t3);margin-bottom:14px}
.lr-plan-li{list-style:none;font-size:11px;color:var(--t2)}
.lr-plan-li li{padding:4px 0;display:flex;gap:7px;align-items:flex-start;border-bottom:1px solid rgba(30,37,64,.6)}
.lr-plan-li li:last-child{border:none}
.lr-plan-li .g{color:var(--grn)}.lr-plan-li .r{color:var(--red)}.lr-plan-li .b{color:#a5b4fc}
.lr-plan-cta{display:block;text-align:center;margin-top:16px;padding:9px;border-radius:9px;font-size:12px;font-weight:800;text-decoration:none;transition:.2s;border:1.5px solid var(--bdr);color:var(--t2)}
.lr-plan-cta:hover{border-color:var(--acc);color:var(--t1)}
.lr-plan-cta.active{background:linear-gradient(135deg,var(--acc),var(--acc2));border:none;color:#fff;box-shadow:0 4px 16px rgba(91,106,240,.4)}
.lr-best{position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;font-size:9px;font-weight:800;padding:4px 14px;border-radius:0 0 10px 10px;white-space:nowrap;letter-spacing:.4px;text-transform:uppercase}

/* ── FORM CARD ── */
.lr-form-bg{background:linear-gradient(160deg,#070a12,#0d1128 50%,#070a12);padding:72px 24px;border-top:1px solid var(--bdr);border-bottom:1px solid var(--bdr);position:relative;overflow:hidden}
.lr-form-bg::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 50%,rgba(91,106,240,.12),transparent)}
.lr-form-card{background:var(--surf);border:1px solid var(--bdr);border-radius:22px;max-width:500px;margin:0 auto;overflow:hidden;box-shadow:0 32px 100px rgba(0,0,0,.6);position:relative;z-index:1}
.lr-form-head{background:linear-gradient(135deg,#0a0d1a,#111630);padding:30px 32px 24px;text-align:center;border-bottom:1px solid var(--bdr)}
.lr-form-head .moon{font-size:30px;margin-bottom:8px;display:block;animation:lr-pulse 3s ease-in-out infinite}
@keyframes lr-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
.lr-form-title{font-size:18px;font-weight:900;letter-spacing:-.3px}
.lr-form-subtitle{font-size:12px;color:var(--t3);margin-top:4px}

/* progress bar */
.lr-progress{height:3px;background:var(--bdr);position:relative;overflow:hidden}
.lr-progress-bar{height:100%;background:linear-gradient(90deg,var(--acc),var(--acc2));border-radius:99px;transition:width .5s cubic-bezier(.4,0,.2,1)}

/* steps labels */
.lr-step-row{display:flex;justify-content:space-between;padding:14px 32px 0;font-size:10px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.lr-step-row span.on{color:var(--acc)}
.lr-step-row span.done{color:var(--grn)}

/* form body */
.lr-form-body{padding:24px 32px 30px}
.lr-step-title{font-size:16px;font-weight:800;color:var(--t1);margin-bottom:4px}
.lr-step-sub{font-size:12px;color:var(--t2);margin-bottom:20px;line-height:1.55}

/* fields */
.lr-field{margin-bottom:14px;position:relative}
.lr-field label{display:block;font-size:10px;font-weight:800;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.lr-field input{width:100%;background:var(--elev);border:1.5px solid var(--bdr);border-radius:9px;padding:11px 14px;color:var(--t1);font-size:14px;font-family:inherit;outline:none;transition:.2s;caret-color:var(--acc)}
.lr-field input:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(91,106,240,.15)}
.lr-field.err input{border-color:var(--red)!important;box-shadow:0 0 0 3px rgba(239,68,68,.1)!important}
.lr-field input::placeholder{color:var(--t3)}
.lr-field-msg{font-size:11px;margin-top:5px;display:none;align-items:center;gap:5px}
.lr-field.err .lr-field-msg{display:flex;color:#fca5a5}
.lr-field.ok .lr-field-msg{display:flex;color:#86efac}
.lr-field.ok input{border-color:rgba(34,197,94,.4)}

/* domain checker */
.lr-domain-wrap{position:relative}
.lr-domain-wrap input{padding-right:36px}
.lr-domain-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none}

/* otp */
.lr-otp-wrap{display:flex;gap:8px;justify-content:center;margin:12px 0}
.lr-otp-wrap input{width:46px;height:54px;text-align:center;font-size:20px;font-weight:900;font-family:'Courier New',monospace;background:var(--elev);border:1.5px solid var(--bdr);border-radius:9px;color:var(--t1);outline:none;transition:.2s;caret-color:var(--acc)}
.lr-otp-wrap input:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(91,106,240,.15)}
.lr-otp-wrap.err input{border-color:var(--red)!important}

/* btn */
.lr-btn{width:100%;padding:13px;border-radius:9px;border:none;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;transition:.2s;position:relative;overflow:hidden}
.lr-btn-primary{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;box-shadow:0 4px 20px rgba(91,106,240,.3)}
.lr-btn-primary:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 6px 28px rgba(91,106,240,.45)}
.lr-btn-primary:disabled{opacity:.45;cursor:not-allowed;transform:none!important}
.lr-btn-ghost{background:transparent;border:1.5px solid var(--bdr);color:var(--t2);font-size:12px;padding:10px;margin-top:10px}
.lr-btn-ghost:hover{border-color:var(--bdrl);color:var(--t1)}
.lr-spin{display:none;width:16px;height:16px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:lr-spin .6s linear infinite;margin:0 auto}
@keyframes lr-spin{to{transform:rotate(360deg)}}
.lr-btn.loading .lr-btn-txt{display:none}
.lr-btn.loading .lr-spin{display:block}

/* global error */
.lr-gerr{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:9px;padding:10px 14px;font-size:12px;color:#fca5a5;margin-bottom:14px;display:none;align-items:center;gap:8px}
.lr-gerr.show{display:flex}

/* resend */
.lr-resend{text-align:center;margin-top:14px;font-size:12px;color:var(--t3)}
.lr-resend a{color:var(--acc);cursor:pointer;font-weight:700}
.lr-resend a:hover{text-decoration:underline}

/* ── SUCCESS ── */
.lr-success{text-align:center;padding:8px 0}
.lr-success-icon{font-size:52px;margin-bottom:16px;animation:lr-bounce .6s ease}
@keyframes lr-bounce{0%{transform:scale(0)}70%{transform:scale(1.15)}100%{transform:scale(1)}}
.lr-success-title{font-size:22px;font-weight:900;color:var(--grn);margin-bottom:8px}
.lr-success-sub{font-size:13px;color:var(--t2);margin-bottom:22px;line-height:1.6}
.lr-key-box{background:var(--elev);border:1.5px solid rgba(91,106,240,.4);border-radius:10px;padding:16px;cursor:pointer;transition:.2s;text-align:left;position:relative}
.lr-key-box:hover{border-color:var(--acc);background:rgba(91,106,240,.08)}
.lr-key-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);margin-bottom:8px}
.lr-key-val{font-family:'Courier New',monospace;font-size:13px;color:#a5b4fc;letter-spacing:1.5px;word-break:break-all;line-height:1.5}
.lr-copy-badge{position:absolute;top:10px;right:10px;font-size:9px;font-weight:800;background:rgba(91,106,240,.2);color:#a5b4fc;padding:3px 8px;border-radius:99px;text-transform:uppercase;letter-spacing:.4px;transition:.2s}
.lr-key-box:hover .lr-copy-badge{background:var(--acc);color:#fff}
.lr-copied{color:var(--grn)!important;background:rgba(34,197,94,.2)!important}
.lr-wa-btn{display:inline-flex;align-items:center;gap:8px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;padding:10px 22px;border-radius:99px;font-size:12px;font-weight:800;text-decoration:none;margin-top:16px;transition:.2s}
.lr-wa-btn:hover{background:rgba(34,197,94,.22);transform:translateY(-1px)}
.lr-steps-mini{margin-top:22px;text-align:left;background:var(--elev);border-radius:10px;padding:16px}
.lr-steps-mini-title{font-size:11px;font-weight:800;color:var(--t1);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px}
.lr-steps-mini ol{padding-left:0;list-style:none;font-size:12px;color:var(--t2)}
.lr-steps-mini li{padding:5px 0;display:flex;gap:10px;align-items:flex-start;border-bottom:1px solid rgba(30,37,64,.5)}
.lr-steps-mini li:last-child{border:none}
.lr-num{background:var(--acc);color:#fff;min-width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;margin-top:1px}

/* ── CONFETTI ── */
.lr-confetti-canvas{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999}

/* ── FOOTER SEC ── */
.lr-footer-sec{padding:48px 24px;text-align:center;border-top:1px solid var(--bdr)}
.lr-footer-sec p{font-size:12px;color:var(--t3);line-height:1.7}
.lr-footer-sec a{color:var(--t2)}

@keyframes lr-fadein{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
</style>

<div id="lr-wrap">

<!-- ══ HERO ══════════════════════════════════════════ -->
<div class="lr-hero">
  <div class="lr-cards-bg">
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#5b6af0"></span>Diseñar landing page</div>
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#22c55e"></span>Reunión con cliente ✓</div>
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#f59e0b"></span>Revisar propuesta</div>
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#ef4444"></span>Entrega de informe</div>
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#06b6d4"></span>Seguimiento de leads</div>
    <div class="lr-fc"><span class="lr-fc-dot" style="background:#8b5cf6"></span>Actualizar catálogo</div>
  </div>

  <div style="position:relative;z-index:1;max-width:760px;margin:0 auto">
    <div class="lr-badge">✦ Sin tarjeta de crédito · Gratis para siempre</div>
    <h1>Organizá tu equipo<br>con <span class="gr">Luna Workspace</span></h1>
    <p class="lr-hero-sub">Kanban, calendario, notificaciones por WhatsApp y Telegram.<br>Instalado en <strong style="color:var(--t1)">tu propio WordPress</strong> — tus datos, en tu servidor.</p>

    <div class="lr-stats">
      <div class="lr-stat"><div class="lr-stat-n">$0</div><div class="lr-stat-l">Plan gratis<br>para siempre</div></div>
      <div class="lr-stat"><div class="lr-stat-n">25%</div><div class="lr-stat-l">Más barato que<br>Trello en equipo</div></div>
      <div class="lr-stat"><div class="lr-stat-n">📲</div><div class="lr-stat-l">WhatsApp nativo<br>único en el mercado</div></div>
    </div>
  </div>

  <!-- Mockup kanban -->
  <div class="lr-mockup-wrap">
    <div class="lr-mockup">
      <div class="lr-mock-bar">
        <div class="lr-mock-dots"><span></span><span></span><span></span></div>
        <span class="lr-mock-title">🌙 Luna Workspace — Mi Proyecto</span>
      </div>
      <div class="lr-mock-body">
        <div class="lr-mock-col">
          <div class="lr-mock-col-h">Por hacer <span class="lr-mock-count">3</span></div>
          <div class="lr-mock-card" style="border-left-color:#5b6af0">Diseñar landing<div class="lr-tag" style="background:rgba(91,106,240,.15);color:#a5b4fc">diseño</div></div>
          <div class="lr-mock-card" style="border-left-color:#f59e0b">Revisar propuesta<div class="lr-tag" style="background:rgba(245,158,11,.15);color:#fde68a">urgente</div></div>
          <div class="lr-mock-card" style="border-left-color:#3d4470">Actualizar catálogo</div>
        </div>
        <div class="lr-mock-col">
          <div class="lr-mock-col-h">En progreso <span class="lr-mock-count">2</span></div>
          <div class="lr-mock-card" style="border-left-color:#06b6d4">Campaña email<div class="lr-tag" style="background:rgba(6,182,212,.15);color:#67e8f9">marketing</div></div>
          <div class="lr-mock-card" style="border-left-color:#8b5cf6">Reunión cliente</div>
        </div>
        <div class="lr-mock-col">
          <div class="lr-mock-col-h">Listo <span class="lr-mock-count">4</span></div>
          <div class="lr-mock-card" style="border-left-color:#22c55e;opacity:.7">Setup WordPress ✓</div>
          <div class="lr-mock-card" style="border-left-color:#22c55e;opacity:.7">Brief de proyecto ✓</div>
        </div>
        <div class="lr-mock-col" style="display:none;display:block">
          <div class="lr-mock-col-h">Revisión <span class="lr-mock-count">1</span></div>
          <div class="lr-mock-card" style="border-left-color:#ec4899">Demo al cliente<div class="lr-tag" style="background:rgba(236,72,153,.15);color:#f9a8d4">esta semana</div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ FEATURES ══════════════════════════════════════ -->
<div class="lr-section lr-section-center">
  <div class="lr-sec-badge">Funcionalidades</div>
  <h2>Todo lo que necesitás<br><em>sin la complejidad</em></h2>
  <p class="lr-sub">Sin curva de aprendizaje. Sin datos en manos ajenas. Funciona desde el primer día.</p>

  <div class="lr-feat-grid">
    <div class="lr-feat">
      <div class="lr-feat-icon">📋</div>
      <h3>Kanban drag & drop</h3>
      <p>Columnas ilimitadas, tarjetas con etiquetas, asignación de responsables y fechas de vencimiento.</p>
    </div>
    <div class="lr-feat">
      <div class="lr-feat-icon">📲</div>
      <h3>WhatsApp & Telegram</h3>
      <p>Notificaciones de tareas directamente en WhatsApp o Telegram. El único en el mercado con esto integrado.</p>
      <div class="lr-feat-new">Único</div>
    </div>
    <div class="lr-feat">
      <div class="lr-feat-icon">📅</div>
      <h3>Calendario integrado</h3>
      <p>Visualizá todas las tareas con fecha en un calendario mensual. Reagendá con drag & drop.</p>
    </div>
    <div class="lr-feat">
      <div class="lr-feat-icon">🏠</div>
      <h3>Self-hosted total</h3>
      <p>Se instala en tu WordPress. Tus datos nunca salen de tu servidor — ideal para salud, jurídico y finanzas.</p>
      <div class="lr-feat-new">Único</div>
    </div>
    <div class="lr-feat">
      <div class="lr-feat-icon">🎨</div>
      <h3>Temas y paleta propia</h3>
      <p>Modo oscuro, paleta de colores personalizable con picker hexadecimal. Fondo propio por usuario.</p>
    </div>
    <div class="lr-feat">
      <div class="lr-feat-icon">⚡</div>
      <h3>Ultra rápido</h3>
      <p>Construido con vanilla JS, sin frameworks pesados. Carga instantánea incluso en conexiones lentas.</p>
    </div>
  </div>
</div>

<!-- ══ PLANES ══════════════════════════════════════════ -->
<div style="background:var(--surf);border-top:1px solid var(--bdr);border-bottom:1px solid var(--bdr);padding:72px 24px" id="planes">
  <div class="lr-section" style="padding:0">
    <div class="lr-section-center" style="margin-bottom:40px">
      <div class="lr-sec-badge">Precios</div>
      <h2>Empezá gratis.<br><em>Crecé cuando el equipo crece.</em></h2>
      <p class="lr-sub">Tarifa fija por rango — no sube cuando sumás integrantes.</p>
    </div>
    <div class="lr-plans">
      <div class="lr-plan current">
        <div class="lr-plan-tag" style="background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.25)">Gratis</div>
        <div class="lr-plan-name">Gratis</div>
        <div class="lr-plan-price" style="color:#86efac">$0</div>
        <div class="lr-plan-note">Para siempre · sin tarjeta</div>
        <ul class="lr-plan-li">
          <li><span class="g">✓</span>1 usuario</li>
          <li><span class="g">✓</span>1 pizarra kanban</li>
          <li><span class="g">✓</span>Tareas ilimitadas</li>
          <li><span class="g">✓</span>Calendario</li>
          <li><span class="r">✗</span>Sin notificaciones</li>
        </ul>
        <a href="#registrate" class="lr-plan-cta active">Empezar gratis →</a>
      </div>
      <div class="lr-plan">
        <div class="lr-plan-tag" style="background:rgba(6,182,212,.1);color:#67e8f9;border:1px solid rgba(6,182,212,.25)">Básico</div>
        <div class="lr-plan-name">Básico</div>
        <div class="lr-plan-price" style="color:#67e8f9"><sup style="font-size:14px;vertical-align:top;margin-top:6px;display:inline-block">$</sup>19</div>
        <div class="lr-plan-note">por mes · hasta 5 usuarios</div>
        <ul class="lr-plan-li">
          <li><span class="g">✓</span>Hasta 5 usuarios</li>
          <li><span class="g">✓</span>Pizarras ilimitadas</li>
          <li><span class="g">✓</span>Email + WhatsApp</li>
          <li><span class="g">✓</span>Recordatorios auto</li>
          <li><span class="g">✓</span>Métricas</li>
        </ul>
        <a href="<?= get_site_url() ?>/luna-planes" class="lr-plan-cta">Ver plan →</a>
      </div>
      <div class="lr-plan pop">
        <div class="lr-best">Más popular</div>
        <div class="lr-plan-tag" style="background:rgba(91,106,240,.15);color:#a5b4fc;border:1px solid rgba(91,106,240,.3)">Profesional</div>
        <div class="lr-plan-name">Profesional</div>
        <div class="lr-plan-price" style="background:linear-gradient(135deg,#a5b4fc,#67e8f9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text"><sup style="font-size:14px;vertical-align:top;margin-top:6px;display:inline-block;-webkit-text-fill-color:#a5b4fc">$</sup>39</div>
        <div class="lr-plan-note">por mes · hasta 20 usuarios</div>
        <ul class="lr-plan-li">
          <li><span class="b">✓</span>Hasta 20 usuarios</li>
          <li><span class="b">✓</span>Todo del Básico</li>
          <li><span class="b">✓</span>Métricas avanzadas</li>
          <li><span class="b">✓</span>Fondo personalizado</li>
          <li><span class="b">✓</span>Soporte prioritario</li>
        </ul>
        <a href="<?= get_site_url() ?>/luna-planes" class="lr-plan-cta">Ver plan →</a>
      </div>
      <div class="lr-plan">
        <div class="lr-plan-tag" style="background:rgba(139,92,246,.12);color:#c4b5fd;border:1px solid rgba(139,92,246,.25)">Corporativo</div>
        <div class="lr-plan-name">Corporativo</div>
        <div class="lr-plan-price" style="color:#c4b5fd"><sup style="font-size:14px;vertical-align:top;margin-top:6px;display:inline-block">$</sup>89</div>
        <div class="lr-plan-note">por mes · ilimitados</div>
        <ul class="lr-plan-li">
          <li><span class="b">✓</span>Usuarios ilimitados</li>
          <li><span class="b">✓</span>Todo del Profesional</li>
          <li><span class="b">✓</span>Multi-workspace</li>
          <li><span class="b">✓</span>Instalación asistida</li>
          <li><span class="b">✓</span>Soporte directo</li>
        </ul>
        <a href="<?= get_site_url() ?>/luna-planes" class="lr-plan-cta">Ver plan →</a>
      </div>
    </div>
  </div>
</div>

<!-- ══ FORM ═══════════════════════════════════════════ -->
<div class="lr-form-bg" id="registrate">
  <div style="text-align:center;margin-bottom:40px;position:relative;z-index:1">
    <div class="lr-sec-badge">Registro gratuito</div>
    <h2 style="font-size:clamp(22px,3.5vw,34px);font-weight:900;letter-spacing:-.6px;margin-bottom:10px">Conseguí tu clave <em style="font-style:normal;background:linear-gradient(135deg,#a5b4fc,#67e8f9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">en 2 minutos</em></h2>
    <p style="font-size:14px;color:var(--t2)">Sin tarjeta de crédito. Registrate y te enviamos tu clave por email.</p>
  </div>

  <div class="lr-form-card">
    <div class="lr-form-head">
      <span class="moon">🌙</span>
      <div class="lr-form-title">Plan Gratis — Activar ahora</div>
      <div class="lr-form-subtitle">Licencia vinculada a tu dominio · Sin vencimiento</div>
    </div>

    <div class="lr-progress"><div class="lr-progress-bar" id="lr-pbar" style="width:33%"></div></div>
    <div class="lr-step-row">
      <span id="lr-sl1" class="on">Tus datos</span>
      <span id="lr-sl2">Verificación</span>
      <span id="lr-sl3">¡Lista!</span>
    </div>

    <div class="lr-form-body">

      <!-- PASO 1 -->
      <div id="lr-s1">
        <div class="lr-step-title">Crear cuenta gratuita</div>
        <p class="lr-step-sub">Completá tus datos. Enviamos un código de verificación a tu email.</p>
        <div class="lr-gerr" id="lr-e1"><span>⚠</span><span id="lr-e1-txt"></span></div>

        <div class="lr-field" id="lf-name">
          <label>Nombre completo</label>
          <input type="text" id="li-name" placeholder="María García" autocomplete="name">
          <div class="lr-field-msg" id="lm-name"></div>
        </div>
        <div class="lr-field" id="lf-email">
          <label>Email</label>
          <input type="email" id="li-email" placeholder="maria@empresa.com" autocomplete="email">
          <div class="lr-field-msg" id="lm-email"></div>
        </div>
        <div class="lr-field" id="lf-phone">
          <label>Teléfono WhatsApp</label>
          <input type="tel" id="li-phone" placeholder="+54 9 11 1234 5678" autocomplete="tel">
          <div class="lr-field-msg" id="lm-phone">⚠ Con código de país. Ej: +5491112345678</div>
        </div>
        <div class="lr-field" id="lf-domain">
          <label>Dominio WordPress</label>
          <div class="lr-domain-wrap">
            <input type="text" id="li-domain" placeholder="miempresa.com" autocomplete="url">
            <span class="lr-domain-icon" id="lr-di"></span>
          </div>
          <div class="lr-field-msg" id="lm-domain">La licencia queda vinculada a este dominio</div>
        </div>

        <button class="lr-btn lr-btn-primary" id="lr-b1" onclick="lrSendOtp()">
          <span class="lr-btn-txt">Enviar código de verificación →</span>
          <div class="lr-spin"></div>
        </button>
        <p style="font-size:10px;color:var(--t3);text-align:center;margin-top:14px;line-height:1.6">
          Al registrarte aceptás el uso de tus datos para gestionar tu licencia. No compartimos tu información.
        </p>
      </div>

      <!-- PASO 2 -->
      <div id="lr-s2" style="display:none">
        <div class="lr-step-title">Verificá tu email</div>
        <p class="lr-step-sub" id="lr-otp-hint">Ingresá el código de 6 dígitos que te enviamos.</p>
        <div class="lr-gerr" id="lr-e2"><span>⚠</span><span id="lr-e2-txt"></span></div>

        <div class="lr-otp-wrap" id="lr-otp">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
        </div>

        <button class="lr-btn lr-btn-primary" id="lr-b2" style="margin-top:18px" onclick="lrVerifyOtp()" disabled>
          <span class="lr-btn-txt">Verificar y enviar solicitud →</span>
          <div class="lr-spin"></div>
        </button>
        <button class="lr-btn lr-btn-ghost" onclick="lrBack()">← Cambiar datos</button>

        <div class="lr-resend">
          ¿No llegó? <a id="lr-ra" onclick="lrResend()" style="display:none">Reenviar código</a>
          <span id="lr-rt"></span>
        </div>
      </div>

      <!-- PASO 3 -->
      <div id="lr-s3" style="display:none">
        <div class="lr-success">
          <div class="lr-success-icon">📬</div>
          <div class="lr-success-title" style="color:var(--cya)">¡Registro recibido!</div>
          <p class="lr-success-sub">Tu email fue verificado. Vamos a revisar tus datos y te enviamos la clave de licencia a <strong id="lr-s3-email" style="color:var(--t1)"></strong> a la brevedad.</p>
          <div style="background:var(--elev);border:1px solid var(--bdr);border-radius:10px;padding:18px;text-align:left;margin-bottom:16px">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);margin-bottom:10px">Datos registrados</div>
            <div style="font-size:13px;color:var(--t2);line-height:1.9">
              🌐 Dominio: <strong id="lr-s3-domain" style="color:var(--t1)"></strong><br>
              📧 Email: <strong id="lr-s3-email2" style="color:var(--t1)"></strong>
            </div>
          </div>
          <div style="background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.25);border-radius:10px;padding:14px 16px;font-size:12px;color:#67e8f9;line-height:1.7;margin-bottom:16px">
            ⏱ El proceso de validación puede tomar hasta 24 hs hábiles. Revisá también tu carpeta de spam.
          </div>
          <a class="lr-wa-btn" href="<?= esc_url($wa) ?>" target="_blank">
            📲 Escribinos si tenés preguntas
          </a>
        </div>
      </div>

    </div><!-- /form-body -->
  </div><!-- /form-card -->
</div>

<!-- ══ FOOTER ══════════════════════════════════════════ -->
<div class="lr-footer-sec">
  <p>Luna Workspace es un producto de <a href="<?= get_site_url() ?>">websobreruedas.com</a><br>
  ¿Tenés dudas? <a href="<?= esc_url($wa) ?>">Escribinos por WhatsApp</a> · <a href="mailto:<?= self::VENDOR_EMAIL ?>">o por email</a></p>
</div>

</div><!-- /lr-wrap -->

<canvas class="lr-confetti-canvas" id="lr-cnf" style="display:none"></canvas>

<script>
(function(){
const AJAX = <?= json_encode($ajax) ?>;
let TOKEN = '', resendTmr = null;

// ── OTP inputs ──
const otpInputs = [...document.querySelectorAll('#lr-otp input')];
otpInputs.forEach((inp, i) => {
  inp.addEventListener('input', e => {
    const v = e.target.value.replace(/\D/,'');
    e.target.value = v;
    if (v && i < otpInputs.length-1) otpInputs[i+1].focus();
    document.getElementById('lr-b2').disabled = otpInputs.map(x=>x.value).join('').length < 6;
  });
  inp.addEventListener('keydown', e => {
    if (e.key==='Backspace' && !e.target.value && i>0) otpInputs[i-1].focus();
  });
  inp.addEventListener('paste', e => {
    e.preventDefault();
    const digits = (e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6);
    [...digits].forEach((d,j) => { if(otpInputs[j]) otpInputs[j].value = d; });
    if (otpInputs[digits.length-1]) otpInputs[digits.length-1].focus();
    document.getElementById('lr-b2').disabled = digits.length < 6;
  });
});

// ── Domain checker (debounced) ──
let domainTimer;
document.getElementById('li-domain').addEventListener('input', function(){
  clearTimeout(domainTimer);
  const v = this.value.trim();
  setDomainIcon('');
  if (!v || v.length < 4) return;
  domainTimer = setTimeout(async () => {
    const fd = new FormData();
    fd.append('action','luna_reg_check_domain');
    fd.append('domain', v);
    try {
      const r = await fetch(AJAX, {method:'POST',body:fd});
      const d = await r.json();
      setDomainIcon(d.available ? '✅' : '❌');
      const f = document.getElementById('lf-domain');
      const m = document.getElementById('lm-domain');
      if (!d.available) {
        f.className = 'lr-field err';
        m.textContent = '⚠ Ese dominio ya tiene una licencia activa.';
      } else {
        f.className = 'lr-field ok';
        m.textContent = '✓ Dominio disponible';
      }
    } catch(e){}
  }, 600);
});

function setDomainIcon(v){ document.getElementById('lr-di').textContent = v; }

// ── Paso 1 ──
window.lrSendOtp = async function(){
  clearFieldErrors();
  setLoad('lr-b1', true);
  const fd = new FormData();
  fd.append('action','luna_reg_send_otp');
  fd.append('name',   document.getElementById('li-name').value);
  fd.append('email',  document.getElementById('li-email').value);
  fd.append('phone',  document.getElementById('li-phone').value);
  fd.append('domain', document.getElementById('li-domain').value);
  try {
    const r = await fetch(AJAX, {method:'POST',body:fd});
    const d = await r.json();
    if (!d.ok) { d.field ? showFieldErr(d.field, d.msg) : showGErr('lr-e1', d.msg); return; }
    TOKEN = d.token;
    document.getElementById('lr-otp-hint').textContent = `Enviamos el código a ${d.email_hint}. Revisá también la carpeta de spam.`;
    goStep(2);
    startResend(60);
    otpInputs[0].focus();
  } catch(e){ showGErr('lr-e1','Error de conexión. Intentá nuevamente.'); }
  finally{ setLoad('lr-b1',false); }
};

// ── Paso 2 ──
window.lrVerifyOtp = async function(){
  const otp = otpInputs.map(x=>x.value).join('');
  if (otp.length < 6) return;
  setLoad('lr-b2',true);
  document.getElementById('lr-otp').classList.remove('err');
  const fd = new FormData();
  fd.append('action','luna_reg_verify_otp');
  fd.append('token', TOKEN);
  fd.append('otp',   otp);
  try {
    const r = await fetch(AJAX, {method:'POST',body:fd});
    const d = await r.json();
    if (!d.ok) {
      document.getElementById('lr-otp').classList.add('err');
      if (d.expired){ showGErr('lr-e2',d.msg); lrBack(); return; }
      showGErr('lr-e2', d.msg);
      otpInputs.forEach(x=>x.value='');
      otpInputs[0].focus();
      return;
    }
    // Populate confirmation data
    const emailVal = document.getElementById('li-email').value;
    ['lr-s3-email','lr-s3-email2'].forEach(id => {
      const el = document.getElementById(id); if(el) el.textContent = emailVal;
    });
    const domEl = document.getElementById('lr-s3-domain');
    if(domEl) domEl.textContent = d.domain || document.getElementById('li-domain').value;
    clearInterval(resendTmr);
    goStep(3);
  } catch(e){ showGErr('lr-e2','Error de conexión.'); }
  finally{ setLoad('lr-b2',false); }
};

// ── Reenviar ──
window.lrResend = async function(){
  document.getElementById('lr-ra').style.display='none';
  const fd = new FormData();
  fd.append('action','luna_reg_resend_otp');
  fd.append('token', TOKEN);
  try {
    const r = await fetch(AJAX, {method:'POST',body:fd});
    const d = await r.json();
    if (!d.ok){ if(d.expired) lrBack(); else showGErr('lr-e2',d.msg); return; }
    otpInputs.forEach(x=>x.value='');
    otpInputs[0].focus();
    document.getElementById('lr-otp').classList.remove('err');
    hideGErr('lr-e2');
    startResend(90);
  } catch(e){ showGErr('lr-e2','Error al reenviar.'); }
};

function startResend(sec){
  clearInterval(resendTmr);
  const ra = document.getElementById('lr-ra');
  const rt = document.getElementById('lr-rt');
  ra.style.display='none';
  rt.textContent = `Reenviar en ${sec}s`;
  resendTmr = setInterval(()=>{
    sec--;
    if(sec<=0){ clearInterval(resendTmr); rt.textContent=''; ra.style.display='inline'; }
    else rt.textContent = `Reenviar en ${sec}s`;
  },1000);
}

window.lrBack = function(){
  clearInterval(resendTmr); TOKEN='';
  otpInputs.forEach(x=>x.value='');
  hideGErr('lr-e2');
  goStep(1);
};

// ── Navegación entre pasos ──
function goStep(n){
  [1,2,3].forEach(i=>{
    document.getElementById('lr-s'+i).style.display = i===n?'':'none';
  });
  const pct = {1:'33%',2:'66%',3:'100%'}[n];
  document.getElementById('lr-pbar').style.width = pct;
  ['lr-sl1','lr-sl2','lr-sl3'].forEach((id,i)=>{
    const el = document.getElementById(id);
    el.className = i+1 < n ? 'done' : i+1===n ? 'on' : '';
  });
}

// ── UI helpers ──
function setLoad(id, on){
  const b = document.getElementById(id);
  b.classList.toggle('loading',on);
  b.disabled = on;
}

function showFieldErr(field, msg){
  const f = document.getElementById('lf-'+field);
  const m = document.getElementById('lm-'+field);
  if(f){ f.className='lr-field err'; }
  if(m){ m.innerHTML='⚠ '+msg; }
}

function clearFieldErrors(){
  ['name','email','phone','domain'].forEach(f=>{
    const el = document.getElementById('lf-'+f);
    const m  = document.getElementById('lm-'+f);
    if(el) el.className='lr-field';
    if(m && f!=='phone' && f!=='domain') m.innerHTML='';
  });
  hideGErr('lr-e1');
}

function showGErr(id, msg){
  const el = document.getElementById(id);
  if(!el) return;
  el.classList.add('show');
  document.getElementById(id+'-txt').textContent = msg;
}

function hideGErr(id){
  const el = document.getElementById(id);
  if(el) el.classList.remove('show');
}

// Enter en campos
['li-name','li-email','li-phone','li-domain'].forEach(id=>{
  document.getElementById(id)?.addEventListener('keydown', e=>{ if(e.key==='Enter') lrSendOtp(); });
});

// ── Confetti ──────────────────────────────────────────
function lrConfetti(){
  const canvas = document.getElementById('lr-cnf');
  canvas.style.display = 'block';
  const ctx = canvas.getContext('2d');
  canvas.width  = window.innerWidth;
  canvas.height = window.innerHeight;
  const pieces = Array.from({length:120},()=>({
    x: Math.random()*canvas.width, y: -20,
    r: Math.random()*6+4,
    d: Math.random()*80+40,
    color: ['#5b6af0','#8b5cf6','#22c55e','#06b6d4','#f59e0b','#ec4899','#a5b4fc'][Math.floor(Math.random()*7)],
    tilt: Math.random()*10-10,
    tiltAngle: 0, tiltSpeed: Math.random()*.07+.05,
    speed: Math.random()*3+2,
  }));
  let frame=0, done=false;
  function draw(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    pieces.forEach(p=>{
      p.tiltAngle += p.tiltSpeed;
      p.y += p.speed;
      p.tilt = Math.sin(p.tiltAngle)*12;
      ctx.beginPath();
      ctx.lineWidth = p.r;
      ctx.strokeStyle = p.color;
      ctx.moveTo(p.x+p.tilt+p.r/2, p.y);
      ctx.lineTo(p.x+p.tilt, p.y+p.tilt+p.r/2);
      ctx.stroke();
    });
    frame++;
    if(frame < 180 && !done) requestAnimationFrame(draw);
    else { canvas.style.display='none'; ctx.clearRect(0,0,canvas.width,canvas.height); }
  }
  requestAnimationFrame(draw);
  setTimeout(()=>done=true, 3500);
}

})();
</script>
<?php
        return ob_get_clean();
    }
}
