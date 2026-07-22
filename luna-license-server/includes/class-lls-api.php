<?php
defined('ABSPATH') || exit;

class LLS_Api {

    public function register_routes(): void {
        register_rest_route('luna-licenses/v1', '/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'verify'],
            'permission_callback' => '__return_true',
            'args'                => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'domain'      => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('luna-licenses/v1', '/solicitar', [
            'methods'             => 'POST',
            'callback'            => [$this, 'solicitar'],
            'permission_callback' => '__return_true',
            'args'                => [
                'nombre'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'email'    => ['required' => true, 'sanitize_callback' => 'sanitize_email'],
                'telefono' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'dominio'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'plan'     => ['required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => 'free'],
            ],
        ]);
    }

    public function verify(WP_REST_Request $request): WP_REST_Response {
        // Rate limit: max 10 requests per IP per minute
        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rate_key = 'lls_rate_' . md5($ip);
        $hits     = (int) get_transient($rate_key);
        if ($hits >= 10) {
            return new WP_REST_Response(['valid' => false, 'reason' => 'rate_limit', 'message' => 'Demasiadas verificaciones. Esperá 1 minuto.'], 429);
        }
        set_transient($rate_key, $hits + 1, MINUTE_IN_SECONDS);

        $key    = $request->get_param('license_key');
        $domain = $request->get_param('domain');

        if (empty($key) || empty($domain)) {
            return new WP_REST_Response(['valid' => false, 'reason' => 'missing_params', 'message' => 'Faltan parámetros requeridos.'], 400);
        }

        $result = LLS_License::verify($key, $domain);
        // Always return 200 — callers check the 'valid' field in the JSON body.
        // Returning 4xx causes security layers (Wordfence, mod_security) to intercept
        // the response and replace our JSON with their own error page.
        return new WP_REST_Response($result, 200);
    }

    public function solicitar(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $nombre   = $request->get_param('nombre');
        $email    = $request->get_param('email');
        $telefono = $request->get_param('telefono');
        $dominio  = LLS_License::normalize_domain($request->get_param('dominio'));
        $plan_raw = $request->get_param('plan') ?: 'free';
        $plan     = in_array($plan_raw, ['free','starter','pyme','professional','unlimited'], true) ? $plan_raw : 'free';

        $plan_labels = [
            'free'         => 'Gratis ($0/mes)',
            'starter'      => 'Emprendedor ($19/mes)',
            'pyme'         => 'Pyme ($29/mes)',
            'professional' => 'Profesional ($59/mes)',
            'unlimited'    => 'Corporativo ($129/mes)',
        ];
        $plan_label = $plan_labels[$plan];

        if (!$nombre || !is_email($email) || !$telefono || !$dominio) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Datos incompletos o inválidos.'], 200);
        }

        // Rate limit: 3 solicitudes por IP por hora
        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = 'lls_sol_rate_' . md5($ip);
        $hits     = (int) get_transient($rate_key);
        if ($hits >= 3) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Demasiadas solicitudes. Intentá en una hora.'], 200);
        }
        set_transient($rate_key, $hits + 1, HOUR_IN_SECONDS);

        // Guardar en tabla
        $t = $wpdb->prefix . 'lls_requests';
        $wpdb->insert($t, compact('nombre', 'email', 'telefono', 'dominio', 'plan'));

        // Notificar a Durval por WhatsApp (CallMeBot)
        $apikey = get_option('lls_callmebot_apikey', '6291539');
        $phone  = get_option('lls_callmebot_phone',  '5491153283558');
        $espera_pago = $plan !== 'free' ? "\n⏳ Plan pago — esperar comprobante antes de crear la licencia." : '';
        $texto  = "🌙 Nueva solicitud Luna:\n👤 {$nombre}\n📧 {$email}\n📱 {$telefono}\n🌐 {$dominio}\n💼 Plan: {$plan_label}{$espera_pago}";
        wp_remote_get(add_query_arg([
            'phone'  => $phone,
            'text'   => urlencode($texto),
            'apikey' => $apikey,
        ], 'https://api.callmebot.com/whatsapp.php'), ['timeout' => 10, 'blocking' => false]);

        // Email de confirmación al cliente
        $pago_info = $plan !== 'free'
            ? "Para activar tu licencia, transferí el pago por uno de estos medios y mandanos el "
              . "comprobante por WhatsApp al +54 9 11 5328-3558:\n"
              . "  • Transferencia bancaria — alias: durvaldemisiones\n"
              . "  • Mercado Pago — alias: websobreruedascom.mp\n\n"
            : '';
        $asunto  = '🌙 Recibimos tu solicitud — Luna Workspace';
        $cuerpo  = "Hola {$nombre},\n\n"
            . "Recibimos tu solicitud del plan {$plan_label} para el dominio {$dominio}.\n\n"
            . $pago_info
            . "En las próximas horas te enviamos:\n"
            . "  • El plugin Luna Workspace por este email\n"
            . "  • Tu clave de activación por WhatsApp al {$telefono}\n\n"
            . "Si tenés alguna duda podés escribirnos directamente:\n"
            . "  WhatsApp: +54 9 11 5328-3558\n"
            . "  Web: https://websobreruedas.com\n\n"
            . "¡Gracias por elegir Luna Workspace!\n"
            . "— Equipo Web Sobre Ruedas";
        wp_mail(
            $email,
            $asunto,
            $cuerpo,
            ['From: Luna Workspace <noreply@websobreruedas.com>', 'Content-Type: text/plain; charset=UTF-8']
        );

        return new WP_REST_Response(['ok' => true], 200);
    }
}
