<?php
defined('ABSPATH') || exit;

class Luna_Admin {

    public function __construct() {
        add_action('admin_menu',            [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_notices',         [$this, 'show_notices']);
        add_action('admin_init',            [$this, 'maybe_migrate']);
        add_action('admin_init',            [$this, 'maybe_redirect_to_wizard']);
        add_action('wp_ajax_luna_wizard_validate_license', [$this, 'ajax_wizard_validate_license']);
        add_action('wp_ajax_luna_dismiss_pass',            [$this, 'ajax_dismiss_initial_pass']);
        add_action('wp_ajax_luna_wizard_done',             [$this, 'ajax_wizard_done']);
        add_action('wp_ajax_luna_test_notification',  [$this, 'ajax_test_notification']);
        add_action('wp_ajax_luna_save_user_contact',  [$this, 'ajax_save_user_contact']);
        add_action('wp_ajax_luna_reset_admin_pass',   [$this, 'ajax_reset_admin_pass']);
        add_action('wp_ajax_luna_db_maintenance',     [$this, 'ajax_db_maintenance']);
        add_action('wp_ajax_luna_save_reminders',     [$this, 'ajax_save_reminders']);
        add_action('wp_ajax_luna_send_reminders_now', [$this, 'ajax_send_reminders_now']);
        add_action('wp_ajax_luna_backup_create',      [$this, 'ajax_backup_create']);
        add_action('wp_ajax_luna_backup_list',        [$this, 'ajax_backup_list']);
        add_action('wp_ajax_luna_backup_delete',      [$this, 'ajax_backup_delete']);
        add_action('wp_ajax_luna_backup_download',    [$this, 'ajax_backup_download']);
        add_action('wp_ajax_luna_backup_restore',     [$this, 'ajax_backup_restore']);
        add_action('wp_ajax_luna_list_clients',      [$this, 'ajax_list_clients']);
        add_action('wp_ajax_luna_save_client',       [$this, 'ajax_save_client']);
        add_action('wp_ajax_luna_delete_client',     [$this, 'ajax_delete_client']);
        add_action('wp_ajax_luna_import_clients',    [$this, 'ajax_import_clients']);
        add_action('wp_ajax_luna_list_payments',     [$this, 'ajax_list_payments']);
        add_action('wp_ajax_luna_list_invoices',     [$this, 'ajax_list_invoices']);
        add_action('wp_ajax_luna_save_payment',      [$this, 'ajax_save_payment']);
        add_action('wp_ajax_luna_delete_payment',    [$this, 'ajax_delete_payment']);
        add_action('wp_ajax_luna_mark_payment_paid', [$this, 'ajax_mark_payment_paid']);
        add_action('wp_ajax_luna_estado_cuenta',        [$this, 'ajax_estado_cuenta']);
        add_action('wp_ajax_luna_report_payments',      [$this, 'ajax_report_payments']);
        add_action('wp_ajax_luna_send_client_reminder', [$this, 'ajax_send_client_reminder']);
        add_action('wp_ajax_luna_cobranzas_list',   [$this, 'ajax_cobranzas_list']);
        add_action('wp_ajax_luna_cobranzas_registrar_pago', [$this, 'ajax_cobranzas_registrar_pago']);
        add_action('wp_ajax_luna_cobranzas_pagos',  [$this, 'ajax_cobranzas_pagos']);
        add_action('wp_ajax_luna_cobranzas_kpis',   [$this, 'ajax_cobranzas_kpis']);
        add_action('wp_ajax_luna_cobranzas_update_mov', [$this, 'ajax_cobranzas_update_mov']);
        add_action('wp_ajax_luna_cobranzas_delete_mov', [$this, 'ajax_cobranzas_delete_mov']);
        add_action('wp_ajax_luna_cobranzas_import', [$this, 'ajax_cobranzas_import']);
    }

    public function show_db_diagnostic() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'luna-notifications') === false) return;

        global $wpdb;
        $cfg_file = plugin_dir_path(__FILE__) . '../app/luna-wp-config.php';
        $cfg_exists = file_exists($cfg_file);
        $cfg_defs = [];
        if ($cfg_exists) {
            preg_match_all("/define\('([^']+)',\s*'([^']*)'\)/", file_get_contents($cfg_file), $cm, PREG_SET_ORDER);
            foreach ($cm as $row) $cfg_defs[$row[1]] = $row[2];
        }
        $cfg_db   = $cfg_defs['DB_NAME']        ?? '—';
        $cfg_host = $cfg_defs['DB_HOST']         ?? '—';
        $cfg_user = $cfg_defs['DB_USER']         ?? '—';
        $cfg_pfx  = $cfg_defs['LUNA_TB_PREFIX']  ?? '—';

        $app_count = 0;
        $app_error = '';
        $appDb = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        if ($appDb && $appPfx !== null) {
            $tbl = $appPfx ? "`{$appPfx}users`" : '`users`';
            try {
                $app_count = (int) $appDb->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            } catch (Exception $e) {
                $app_error = $e->getMessage();
            }
        } elseif (!$appDb) {
            $app_error = 'no se pudo conectar (config inválida o DB inaccesible)';
        } else {
            $app_error = 'LUNA_TB_PREFIX no encontrado en config';
        }

        $wp_p = $wpdb->prefix . 'luna_';
        $wp_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wp_p}users`");
        $wp_error = $wpdb->last_error;

        echo '<div class="notice notice-info" style="font-family:monospace;font-size:12px">';
        echo '<p><strong>🔍 Luna DB Diagnóstico (Notificaciones)</strong></p>';
        echo '<table style="border-collapse:collapse">';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">Config existe:</td><td>' . ($cfg_exists ? '<b style="color:green">Sí</b>' : '<b style="color:red">NO</b>') . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">Config DB_HOST:</td><td>' . esc_html($cfg_host) . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">Config DB_NAME:</td><td>' . esc_html($cfg_db) . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">Config DB_USER:</td><td>' . esc_html($cfg_user) . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">Config LUNA_TB_PREFIX:</td><td>' . esc_html($cfg_pfx) . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">App DB usuarios:</td><td><b>' . $app_count . '</b>' . ($app_error ? ' — <span style="color:red">' . esc_html($app_error) . '</span>' : '') . '</td></tr>';
        echo '<tr><td style="padding:2px 12px 2px 0;color:#555">WP DB (' . esc_html($wpdb->dbname) . ') prefix ' . esc_html($wp_p) . ' usuarios:</td><td><b>' . $wp_count . '</b>' . ($wp_error ? ' — <span style="color:red">' . esc_html($wp_error) . '</span>' : '') . '</td></tr>';
        echo '</table></div>';
    }

    // Connect to the same DB the Luna app uses (may differ from WordPress DB)
    private function get_app_db(): ?PDO {
        $cfg = plugin_dir_path(__FILE__) . '../app/luna-wp-config.php';
        if (!file_exists($cfg)) return null;
        $defs = [];
        preg_match_all("/define\('([^']+)',\s*'([^']*)'\)/", file_get_contents($cfg), $m, PREG_SET_ORDER);
        foreach ($m as $row) $defs[$row[1]] = $row[2];
        if (empty($defs['DB_HOST']) || empty($defs['DB_NAME'])) return null;
        try {
            $dsn = "mysql:host={$defs['DB_HOST']};dbname={$defs['DB_NAME']};charset=" . ($defs['DB_CHARSET'] ?? 'utf8mb4');
            $pdo = new PDO($dsn, $defs['DB_USER'] ?? '', $defs['DB_PASS'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (Exception $e) { return null; }
    }

    // Return the table prefix the app uses, or null if the config file is missing.
    // Empty string is a valid prefix (means tables have no prefix: `users`, `workspaces`…).
    private function get_app_prefix(): ?string {
        $cfg = plugin_dir_path(__FILE__) . '../app/luna-wp-config.php';
        if (!file_exists($cfg)) return null;
        preg_match("/define\('LUNA_TB_PREFIX',\s*'([^']*)'\)/", file_get_contents($cfg), $m);
        return $m[1] ?? null; // null = define not found; '' = explicitly empty (valid)
    }

    private function get_app_cron_secret(): string {
        $cfg = plugin_dir_path(__FILE__) . '../app/luna-wp-config.php';
        if (!file_exists($cfg)) return get_option('luna_cron_secret', '');
        preg_match("/define\('LUNA_CRON_SECRET',\s*'([^']*)'\)/", file_get_contents($cfg), $m);
        return $m[1] ?? get_option('luna_cron_secret', '');
    }

    public function maybe_migrate(): void {
        // Run at most once per day — prevents SHOW COLUMNS on every AJAX request
        if (get_transient('luna_migration_done_' . LUNA_VERSION)) return;
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'phone',               "VARCHAR(30) DEFAULT ''");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'whatsapp_apikey',      "VARCHAR(100) DEFAULT ''");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'telegram_chat_id',     "VARCHAR(50) DEFAULT NULL");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'notification_channel', "ENUM('email','whatsapp','telegram','all','none') DEFAULT 'email'");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'cargo',                "VARCHAR(120) DEFAULT ''");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'dept',                 "VARCHAR(120) DEFAULT ''");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'color',                "VARCHAR(10) DEFAULT '#5b6af0'");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'photo',                "MEDIUMTEXT DEFAULT NULL");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'notes',                "TEXT DEFAULT ''");
        Luna_Activator::add_column_if_missing($wpdb, "{$p}users", 'last_login',           "DATETIME NULL");
        set_transient('luna_migration_done_' . LUNA_VERSION, 1, DAY_IN_SECONDS);
    }

    public function register_menu() {
        add_menu_page(
            'Luna Workspace',
            'Luna Workspace',
            'manage_options',
            'luna-workspace',
            [$this, 'render_main_page'],
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAYAAADimHc4AAAHQ0lEQVR4nO2dy6tcVRaHv1VVVyO2ia2ChM7AFwqZBUxHtBEbtfMHBGwfEBAVByI6cCr3DnrkwD9AEUEQdNCDpkFiuqGlRQgd0IGIdGwfoILiI1GJktxb9evB3itne27VTZ3HvfU4+4PiVJ2qc846a6299t7r7L0LMplMJpPJZDKZTCaTyWQyXcFmLcDFkGQEOf2FmW3MVKhlR1JP0kBSf8L3Y/cvIoNZC+BET+8BMrMRMIr7+8DNwO+A24HfAH8H3pZkZqYZibwcSLKyR0vaJ+lhSS9L+kDSORU8EI+Z+/A515QVL+lSSUcl/U3SD9rMN5IOxN/2Zif5EpAqUNK1ktYkfVhS+Iak8/H9d5IOxt+vzE7yJUDSIG5XJD0p6auS0jckjZLXt4ny56bOWjhiyOnF94cknUgUvy5pOKYESNI98Zjs+XUpxfrVqHBX8mhMvPfv1+IxWfl1ceVLulrSsTEeXsb3/1NjWkiZCiTx/qCkU4l3j/N6xf0bks5IujEem1s8dSgp//tSaJmEe/9aeo5MRZKwkyp/UshxhrEEnJK0RyEdkTtbVVHR0rk1UX65hbOV9z8Sj8/eXxXFFIFChfvJlJ4vFW3+ryVdow6lGtqu4Px8rwHXAxvANK2YISHV/IqZfQv0c5KtIioq3dXo1RercFM8/h9SbnpWR0XcP6CQv5nUwZqkfCnkgi7pSuhxGocgV1gsAS8B3mudVpGjuD1mZueZLmQtDW3UAb34AOVx4AAhnldRohvq33GbY/+0qGj1XKPQ5PRYPi3+27OS9vk5Z31fO0nTEuCtlSeA3xLCSRUFurd/A5zumvKhgQGisoaSdgFHCcqsej43wKdmdhbo3DPeJiXAvf8IcAPB++ue70wDORaaJgbw1stDDc7h3v5u3HYu81nrhhWGg4wk7QXuaHKuSGfzPnWV5s3MPwG7KVIJdelU3E+pawBX2B9Ln+uSDVCRkcJz2tsansfp7DPfyoqT1Iutn5sIGc86zU/Hw9bBuB1N+uGyUkdxrrTrgEtoJ3xcpo4++21igANx28Rr/fq3AJfHllWnesNNvO7yFq5vhBK0G9ib7OsMdQzgHu9xu6nCRsAuwtDzujItLE1utq2Wi9chd5U+d4ImBmhLURc6dZJ2m9mwS/XAPBR3I4ShvcAf4r55kGtHaHKjbXqp1ysPtnjOhaCJAdZbkyKEIQFHJN1A6Gl3ohTUuUk/5mTctlEXGCGhtws4Gnva2QAX4WxrUgT6hFD0lMLI6E6Ugjo36B7/XoNzjMM7ZVcCq3GkRTbAGNwAnwHnabcy7hNC0YOS7jazjWUfJVdLebGdPgDeJ+Rx2vRWP9fHhN72D4TJ20vZQaurtJ6ZrQMn4uc208g9Qim4EXgxhqLBsnbOmubx/1X63BZ9wsjqI5Kejsbu7HPjTSTjQfeqmNVeZUTctKPmfIT1Y/F62QiOihHRb0QlTTMRo44RfPS0G2FFSxSOmlScfuyrbQgyAV8jaAi8IOmxGI566kAfYUtUDMzdJenjkrduZ0lYS2TodkhSs1kxTYxwTGFQmC/u1M3SoObD0+vgRv5S0v2JLH1tY6ct3uf8lTgVc4KfiIrZjsq4THqN45IOJfKYwnJnrcz+iYYdlPbPjyGikL7G27s7aIQ0JJ2X9Iqke8fINohK7GuL6a8qSnNfY9arU2h9PSrpeUlXaJ7CnppN0mtK2djvSHpG0v6t5C2/JvyuL+l2Sc8pLJ0mSXem99yU1trTkgYxebYKrBF6sjtVVEWRQ/J7GhKGvb8DvA18DvwPOGdmP286gbSHMNBsP2G+w53A7+Nn51kz+4ukvpkN2xC81Q6NQrEdAceBe9hZIzhDgkHGXfcM8BPBECl9gqJXgD2l79bj/uNmdrhN5UP7BvDzXUV4YnY91WdNtiYOwRlEuM+0dGyFH+PvV4BPCZnZ7wHazMy2WpFEwczMvgPuA05TPOnaaSxeexC3/sBnRHCK8ksUA4399yuEe7gv3lPrc9i2JafixVRhsb03CTMoZ1US6uCyngYOm9nJtkOPsy1Nqaj8gZmdBA5TlIRFWPPZFxhJlT/YDuXDNj5zjS2i1AgfEcLBBvM5/FAUjYaP+LXyF8FxxqPqi/bNglSWY5KuTmVfeFR92cqdwhcJlIJMq+NkXgpUfeHW7WSoX2duTyjmk7Tsa9Vp+qWL28a9PQ03X0UZVlLZlh5Nv3i3l4w6BvFk3bo21zcfxmteO06mnWRmRU3xDxu8eSfpUuDPhLUn7iJMW0q58KcOTJbbW1c9NrfwfgTeAv4KvG5m5+J1+8BoVuOOZh7ryoaI+/YB91IkxG6heiduCPwX+A9hMah/mNkXyTVmqnhn5gZw3BBw4S9MfL//hck+gjEuI+RlVig83ghJs5PALwSlfwGcKhnW80EzV7wzNwZIiYpyYzTqgUYDutLnbiL4XBogRWP+xooicZYy7vulHVOayWQymUwmk8lkMplMJpNZPP4P3XSS8Qz0NOgAAAAASUVORK5CYII=',
            30
        );
        add_submenu_page('luna-workspace', 'Configuración', 'Configuración', 'manage_options', 'luna-workspace', [$this, 'render_main_page']);
        add_submenu_page('luna-workspace', 'Licencia',      'Licencia',      'manage_options', 'luna-license',         [$this, 'render_license_page']);
        add_submenu_page('luna-workspace', 'Notificaciones','Notificaciones','manage_options', 'luna-notifications',   [$this, 'render_notifications_page']);
        add_submenu_page('luna-workspace', 'Base de datos', 'Base de datos', 'manage_options', 'luna-database',        [$this, 'render_database_page']);
        add_submenu_page('luna-workspace', 'Backup & Restore', 'Backup & Restore', 'manage_options', 'luna-backup', [$this, 'render_backup_page']);
        add_submenu_page('luna-workspace', 'Clientes',         'Clientes',         'manage_options', 'luna-clientes', [$this, 'render_clients_page']);
        add_submenu_page('luna-workspace', 'Cobranzas',        'Cobranzas',        'manage_options', 'luna-cobranzas', [$this, 'render_cobranzas_page']);
        // Wizard — oculto del menú pero accesible por URL
        add_submenu_page(null, 'Luna — Configuración inicial', '', 'manage_options', 'luna-onboarding', [$this, 'render_onboarding_wizard']);
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'luna') === false) return;
        wp_enqueue_style('luna-admin-css', LUNA_PLUGIN_URL . 'admin/admin.css', [], LUNA_VERSION);
        wp_enqueue_script('luna-admin-js', LUNA_PLUGIN_URL . 'admin/admin.js', ['jquery'], LUNA_VERSION, true);
        wp_localize_script('luna-admin-js', 'lunaAdmin', [
            'nonce'   => wp_create_nonce('luna_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
    }

    public function show_notices() {
        // Aviso crítico, visible en TODO wp-admin (no solo páginas de Luna):
        // la configuración de BD se regeneró sin poder confirmar datos reales
        // de Luna — probablemente se perdió la conexión a una BD externa.
        $recovery_warning = get_option('luna_config_recovery_warning');
        if ($recovery_warning && current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>'
                . '<strong>⚠️ Luna Workspace:</strong> no se pudo confirmar la base de datos configurada '
                . '(desde ' . esc_html($recovery_warning) . '). Si tu sitio usa una base de datos externa para Luna, '
                . 'las credenciales pueden haberse perdido en la última actualización del plugin. '
                . 'Contactá a soporte antes de que tus clientes intenten iniciar sesión — '
                . 'revisá <a href="' . admin_url('admin.php?page=luna-database') . '">Luna Workspace → Base de datos</a>.</p></div>';
        }
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'luna') === false) return;
        // Show banner but do NOT delete the option here — render_main_page() deletes it
        // after displaying it in the yellow box so the user can copy it
        $initial_pass = get_option('luna_initial_admin_pass');
        if ($initial_pass) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . '<strong>Luna Workspace — Primera instalación:</strong> '
                . 'La contraseña del admin se muestra más abajo en esta página bajo <strong>"🔐 Contraseña del administrador Luna"</strong>. '
                . 'Andate a <a href="' . admin_url('admin.php?page=luna-workspace') . '">Luna Workspace → Configuración</a> para verla.</p></div>';
        }
        $key = get_option('luna_license_key', '');
        if (empty($key)) {
            echo '<div class="notice notice-warning"><p><strong>Luna Workspace:</strong> No hay licencia configurada. <a href="' . admin_url('admin.php?page=luna-license') . '">Activar licencia →</a></p></div>';
        }
    }

    // ── Main settings page ────────────────────────────────────────────────────
    public function render_main_page() {
        if (isset($_POST['luna_save_settings']) && check_admin_referer('luna_settings')) {
            update_option('luna_page_slug',    sanitize_text_field($_POST['luna_page_slug'] ?? 'luna-app'));
            update_option('luna_session_hours', absint($_POST['luna_session_hours'] ?? 24));
            update_option('luna_show_gantt',   isset($_POST['luna_show_gantt']) ? 1 : 0);
            Luna_Activator::regenerate_app_config();
            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        }
        $slug        = get_option('luna_page_slug', 'luna-app');
        $hours       = get_option('luna_session_hours', 24);
        $show_gantt  = get_option('luna_show_gantt', 1);
        $entry_token = get_option('luna_entry_token', '');
        // Generar token si todavía no existe (instalaciones anteriores)
        if (!$entry_token) {
            $entry_token = bin2hex(random_bytes(24));
            update_option('luna_entry_token', $entry_token);
        }
        $permanent_url = add_query_arg('luna_enter', $entry_token, home_url('/'));
        $app_direct    = home_url('/?luna_app=1');

        // ¿Regenerar token?
        if (isset($_POST['luna_regen_token']) && check_admin_referer('luna_settings')) {
            $entry_token   = bin2hex(random_bytes(24));
            update_option('luna_entry_token', $entry_token);
            $permanent_url = add_query_arg('luna_enter', $entry_token, home_url('/'));
            echo '<div class="notice notice-success"><p>✅ Token regenerado. Actualizá el botón en Plesk con la nueva URL.</p></div>';
        }
        ?>
        <div class="wrap luna-wrap">
          <h1>🌙 Luna Workspace — Configuración</h1>

          <div class="luna-card" style="margin-bottom:20px;background:linear-gradient(135deg,#5b6af0,#7c3aed);color:#fff;border-radius:12px;padding:28px 32px">
            <h2 style="color:#fff;margin:0 0 6px">🚀 Acceso directo a Luna</h2>
            <p style="color:rgba(255,255,255,.8);margin:0 0 14px;font-size:13px">Esta URL es permanente — no expira y no requiere estar logueado en WordPress. Usala en Plesk o como marcador.</p>
            <div style="display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.25);border-radius:8px;padding:10px 14px;margin-bottom:14px">
              <code id="luna-perm-url" style="color:#fff;font-size:12px;word-break:break-all;flex:1"><?php echo esc_url($permanent_url) ?></code>
              <button onclick="navigator.clipboard.writeText(document.getElementById('luna-perm-url').textContent);this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar',2000)"
                      style="background:#fff;color:#5b6af0;border:none;border-radius:6px;padding:6px 14px;font-weight:700;cursor:pointer;white-space:nowrap">Copiar</button>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <a href="<?php echo esc_url($permanent_url) ?>" target="_blank"
                 style="display:inline-block;background:#fff;color:#5b6af0;padding:10px 24px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none">
                Entrar a Luna →
              </a>
              <form method="POST" style="margin:0">
                <?php wp_nonce_field('luna_settings') ?>
                <button type="submit" name="luna_regen_token"
                        style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:8px;padding:10px 18px;font-size:13px;cursor:pointer"
                        onclick="return confirm('¿Regenerar token? La URL anterior dejará de funcionar.')">
                  🔄 Regenerar token
                </button>
              </form>
            </div>
          </div>

          <div class="luna-grid">
            <div class="luna-card">
              <h2>URL directa del app</h2>
              <p><a href="<?php echo esc_url($app_direct) ?>" target="_blank"><?php echo esc_html($app_direct) ?></a></p>
              <p style="color:#666;font-size:12px">Sin auto-login. Mostrará el formulario de ingreso de Luna.</p>
              <p>Shortcode: <code>[luna_workspace]</code></p>
            </div>
            <form method="POST" class="luna-card">
              <?php wp_nonce_field('luna_settings') ?>
              <h2>Ajustes generales</h2>
              <table class="form-table">
                <tr>
                  <th>Slug de la página</th>
                  <td><input type="text" name="luna_page_slug" value="<?php echo esc_attr($slug) ?>" class="regular-text">
                      <p class="description">URL: <?php echo home_url('/') ?>[ slug ]/</p></td>
                </tr>
                <tr>
                  <th>Duración de sesión (horas)</th>
                  <td><input type="number" name="luna_session_hours" value="<?php echo esc_attr($hours) ?>" min="1" max="720" class="small-text"></td>
                </tr>
                <tr>
                  <th>Vista Gantt</th>
                  <td>
                    <label>
                      <input type="checkbox" name="luna_show_gantt" value="1" <?php checked(1, $show_gantt) ?>>
                      Mostrar pestaña Gantt en la aplicación
                    </label>
                    <p class="description">Si está desactivada, solo se ve la pizarra Kanban.</p>
                  </td>
                </tr>
              </table>
              <p><button type="submit" name="luna_save_settings" class="button button-primary">Guardar cambios</button></p>
            </form>
          </div>

          <?php
          // ── Admin password panel ─────────────────────────────────────────────
          // Check if there's a stored initial/reset password to show
          $pending_pass = get_option('luna_initial_admin_pass', '');
          ?>
          <div class="luna-card" style="margin-top:20px" id="luna-admin-pass-card">
            <h2 style="margin-top:0">🔐 Contraseña del administrador Luna</h2>
            <p style="color:#666;font-size:13px;margin-top:-8px">
              El usuario <code>admin</code> es quien ingresa al tablero de Luna.
              Si olvidaste la contraseña, generá una nueva acá — no necesitás entrar a MySQL.
            </p>
            <?php if ($pending_pass): ?>
              <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:16px 20px;margin-bottom:16px">
                <strong style="color:#854d0e">⚠️ Contraseña inicial — guardala ahora:</strong><br>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                  <code id="luna-init-pass" style="font-size:18px;letter-spacing:2px;background:#fff;padding:8px 14px;border-radius:8px;border:1px solid #fde047;color:#1e1e1e"><?php echo esc_html($pending_pass) ?></code>
                  <button onclick="navigator.clipboard.writeText('<?php echo esc_js($pending_pass) ?>');this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar',2000)"
                          style="background:#854d0e;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-weight:700;cursor:pointer">Copiar</button>
                </div>
                <p style="margin:10px 0 0;font-size:12px;color:#854d0e">
                  Entrá a Luna con usuario <strong>admin</strong> y esta contraseña. Podés cambiarla desde tu perfil dentro de Luna.<br>
                  <a href="#" onclick="if(confirm('¿Confirmas que ya guardaste la contraseña?')){fetch('<?php echo esc_js(admin_url('admin-ajax.php')) ?>?action=luna_dismiss_pass&nonce=<?php echo wp_create_nonce('luna_dismiss_pass') ?>').then(()=>location.reload())};return false"
                     style="font-size:11px;color:#854d0e;margin-top:4px;display:inline-block">✓ Ya la guardé, ocultar este cuadro</a>
                </p>
              </div>
            <?php endif; ?>
            <div id="luna-new-pass-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:16px 20px;margin-bottom:16px">
              <strong style="color:#166534">✅ Nueva contraseña generada — guardala ahora:</strong><br>
              <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                <code id="luna-new-pass" style="font-size:18px;letter-spacing:2px;background:#fff;padding:8px 14px;border-radius:8px;border:1px solid #86efac;color:#1e1e1e"></code>
                <button onclick="navigator.clipboard.writeText(document.getElementById('luna-new-pass').textContent);this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar',2000)"
                        style="background:#166534;color:#fff;border:none;border-radius:7px;padding:8px 16px;font-weight:700;cursor:pointer">Copiar</button>
              </div>
              <p style="margin:10px 0 0;font-size:12px;color:#166534">Ingresá a Luna con usuario <strong>admin</strong> y esta contraseña.</p>
            </div>
            <button id="luna-btn-reset-pass" class="button button-secondary" style="font-size:13px;padding:6px 18px">
              🔄 Generar nueva contraseña para admin Luna
            </button>
            <span id="luna-reset-pass-msg" style="margin-left:10px;font-size:13px;color:#dc2626;display:none"></span>
          </div>

          <?php
          // ── SMTP settings ────────────────────────────────────────────────────
          global $wpdb;
          $smtp_p = $wpdb->prefix . 'luna_';
          if (isset($_POST['luna_save_smtp']) && check_admin_referer('luna_settings')) {
              $smtp_cfg = [
                  'enabled'    => !empty($_POST['smtp_enabled']),
                  'smtp_host'  => sanitize_text_field($_POST['smtp_host']  ?? 'smtp.gmail.com'),
                  'smtp_port'  => absint($_POST['smtp_port']  ?? 587),
                  'encryption' => sanitize_text_field($_POST['smtp_enc']   ?? 'tls'),
                  'smtp_user'  => sanitize_text_field($_POST['smtp_user']  ?? ''),
                  'smtp_pass'  => $_POST['smtp_pass'] ?? '',
                  'from_email' => sanitize_email($_POST['from_email']      ?? ''),
                  'from_name'  => sanitize_text_field($_POST['from_name']  ?? 'Luna Workspace'),
              ];
              $existing = $wpdb->get_var("SELECT meta_value FROM `{$smtp_p}app_settings` WHERE meta_key='email_settings'");
              if ($existing !== null) {
                  $wpdb->update("{$smtp_p}app_settings", ['meta_value' => wp_json_encode($smtp_cfg)], ['meta_key' => 'email_settings']);
              } else {
                  $wpdb->insert("{$smtp_p}app_settings", ['meta_key' => 'email_settings', 'meta_value' => wp_json_encode($smtp_cfg)]);
              }
              echo '<div class="notice notice-success"><p>✅ Configuración SMTP guardada.</p></div>';
          }
          $smtp_row = $wpdb->get_var("SELECT meta_value FROM `{$smtp_p}app_settings` WHERE meta_key='email_settings'");
          $smtp_cfg = $smtp_row ? (json_decode($smtp_row, true) ?: []) : [];
          ?>
          <div class="luna-card" style="margin-top:20px">
            <h2 style="margin-top:0">📧 Configuración SMTP (Email)</h2>
            <p style="color:#666;font-size:13px;margin-top:-8px">Estos datos se usan para enviar notificaciones por email desde Luna Workspace.</p>
            <form method="POST">
              <?php wp_nonce_field('luna_settings') ?>
              <table class="form-table">
                <tr>
                  <th>Activar SMTP</th>
                  <td><label><input type="checkbox" name="smtp_enabled" value="1" <?php checked(!empty($smtp_cfg['enabled'])) ?>> Enviar emails via SMTP personalizado</label></td>
                </tr>
                <tr>
                  <th>Host SMTP</th>
                  <td><input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_cfg['smtp_host'] ?? 'smtp.gmail.com') ?>" class="regular-text" placeholder="smtp.gmail.com"></td>
                </tr>
                <tr>
                  <th>Puerto</th>
                  <td><input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_cfg['smtp_port'] ?? 587) ?>" class="small-text" placeholder="587">
                      <span class="description"> (587 para TLS, 465 para SSL)</span></td>
                </tr>
                <tr>
                  <th>Cifrado</th>
                  <td>
                    <select name="smtp_enc">
                      <option value="tls" <?php selected(($smtp_cfg['encryption'] ?? 'tls'), 'tls') ?>>TLS (recomendado)</option>
                      <option value="ssl" <?php selected(($smtp_cfg['encryption'] ?? 'tls'), 'ssl') ?>>SSL</option>
                    </select>
                  </td>
                </tr>
                <tr>
                  <th>Usuario SMTP</th>
                  <td><input type="text" name="smtp_user" value="<?php echo esc_attr($smtp_cfg['smtp_user'] ?? '') ?>" class="regular-text" placeholder="tu@gmail.com"></td>
                </tr>
                <tr>
                  <th>Contraseña SMTP</th>
                  <td><input type="password" name="smtp_pass" value="<?php echo esc_attr($smtp_cfg['smtp_pass'] ?? '') ?>" class="regular-text" placeholder="contraseña o app-password">
                      <p class="description">Para Gmail usá una <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a> (requiere 2FA activo).</p></td>
                </tr>
                <tr>
                  <th>Email remitente</th>
                  <td><input type="email" name="from_email" value="<?php echo esc_attr($smtp_cfg['from_email'] ?? '') ?>" class="regular-text" placeholder="notificaciones@tudominio.com"></td>
                </tr>
                <tr>
                  <th>Nombre remitente</th>
                  <td><input type="text" name="from_name" value="<?php echo esc_attr($smtp_cfg['from_name'] ?? 'Luna Workspace') ?>" class="regular-text"></td>
                </tr>
              </table>
              <p><button type="submit" name="luna_save_smtp" class="button button-primary">Guardar configuración SMTP</button></p>
            </form>
          </div>

          <?php
          // ── Reminder settings ────────────────────────────────────────────────
          $appDb  = $this->get_app_db();
          $appPfx = $this->get_app_prefix();
          $rem    = [];
          if ($appDb && $appPfx !== null) {
              $tbl = $appPfx !== '' ? "{$appPfx}app_settings" : 'app_settings';
              try {
                  $row = $appDb->query("SELECT meta_value FROM `{$tbl}` WHERE meta_key='reminder_schedule' LIMIT 1")->fetch();
                  $rem = $row ? (json_decode($row['meta_value'], true) ?: []) : [];
              } catch (Exception $e) {}
          }
          if (empty($rem)) {
              $row = $wpdb->get_var("SELECT meta_value FROM `{$smtp_p}app_settings` WHERE meta_key='reminder_schedule'");
              $rem = $row ? (json_decode($row, true) ?: []) : [];
          }
          $rem_enabled = !empty($rem['enabled']);
          $rem_hour    = (int)($rem['hour'] ?? 8);
          $last_sent   = '';
          if ($appDb && $appPfx !== null) {
              $tbl = $appPfx !== '' ? "{$appPfx}app_settings" : 'app_settings';
              try {
                  $r = $appDb->query("SELECT meta_value FROM `{$tbl}` WHERE meta_key='reminders_last_sent' LIMIT 1")->fetch();
                  $last_sent = $r ? $r['meta_value'] : '';
              } catch (Exception $e) {}
          }
          if (!$last_sent) {
              $last_sent = $wpdb->get_var("SELECT meta_value FROM `{$smtp_p}app_settings` WHERE meta_key='reminders_last_sent'") ?: '';
          }
          ?>
          <div class="luna-card" style="margin-top:20px" id="luna-reminders-card">
            <h2 style="margin-top:0">⏰ Recordatorios diarios</h2>
            <p style="color:#666;font-size:13px;margin-top:-8px">
              Cada día a la hora configurada Luna envía a cada usuario un resumen personalizado de sus tareas vencidas,
              de hoy y de esta semana — por email, WhatsApp o Telegram según la preferencia de cada uno.
            </p>

            <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:14px 18px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px">
              <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600">
                <input type="checkbox" id="rem-enabled" <?php checked($rem_enabled) ?>
                       style="width:18px;height:18px;accent-color:#16a34a;cursor:pointer">
                Activar recordatorios automáticos
              </label>
              <?php if ($rem_enabled): ?>
                <span style="font-size:12px;color:#16a34a;font-weight:600">✅ Activo</span>
              <?php else: ?>
                <span style="font-size:12px;color:#94a3b8">Desactivado</span>
              <?php endif; ?>
            </div>

            <table class="form-table" style="margin-bottom:16px">
              <tr>
                <th>Hora de envío</th>
                <td>
                  <select id="rem-hour" style="font-size:13px;padding:5px 10px;border-radius:7px;border:1px solid #ddd">
                    <?php for ($h = 0; $h < 24; $h++):
                      $label = sprintf('%02d:00 hs', $h);
                      if ($h === 8)  $label .= ' (mañana — recomendado)';
                      if ($h === 9)  $label .= ' (mañana)';
                      if ($h === 18) $label .= ' (tarde)';
                      if ($h === 19) $label .= ' (tarde)';
                    ?>
                      <option value="<?php echo $h ?>" <?php selected($rem_hour, $h) ?>><?php echo $label ?></option>
                    <?php endfor; ?>
                  </select>
                  <p class="description" style="margin-top:6px">
                    Hora del servidor. <?php
                      echo 'Ahora son las <strong>' . date('H:i') . ' hs</strong> en el servidor.';
                    ?>
                  </p>
                </td>
              </tr>
              <tr>
                <th>Último envío</th>
                <td>
                  <span style="font-size:13px">
                    <?php echo $last_sent
                      ? '<strong style="color:#16a34a">' . esc_html($last_sent) . '</strong>'
                      : '<span style="color:#94a3b8">Nunca enviado</span>'; ?>
                  </span>
                </td>
              </tr>
            </table>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button id="luna-btn-save-reminders" class="button button-primary" style="font-size:13px;padding:6px 18px">
                💾 Guardar configuración
              </button>
              <button id="luna-btn-send-now" class="button button-secondary" style="font-size:13px;padding:6px 18px">
                🚀 Enviar ahora (prueba)
              </button>
              <span id="luna-rem-msg" style="font-size:13px;display:none"></span>
            </div>

            <div id="luna-rem-preview" style="display:none;margin-top:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px">
              <strong style="font-size:13px">📋 Resultado del envío:</strong>
              <div id="luna-rem-preview-body" style="margin-top:10px;font-size:12px"></div>
            </div>
          </div>
        </div>

        <script>
        jQuery(function($){
          var nonce = <?php echo wp_json_encode(wp_create_nonce('luna_admin_nonce')); ?>;

          // ── Reset admin password ─────────────────────────────────────────────
          $('#luna-btn-reset-pass').on('click', function(){
            if (!confirm('¿Generar una nueva contraseña para el admin de Luna?\nLa contraseña actual dejará de funcionar y se cerrarán todas las sesiones.')) return;
            var btn = $(this);
            var msg = $('#luna-reset-pass-msg');
            btn.prop('disabled', true).text('Generando…');
            msg.hide();
            $.post(ajaxurl, { action: 'luna_reset_admin_pass', nonce: nonce }, function(res){
              btn.prop('disabled', false).text('🔄 Generar nueva contraseña para admin Luna');
              if (res.success && res.data && res.data.password) {
                $('#luna-new-pass').text(res.data.password);
                $('#luna-new-pass-result').show();
                $('html,body').animate({scrollTop: $('#luna-new-pass-result').offset().top - 80}, 300);
              } else {
                msg.text('Error: ' + (res.data || 'No se pudo resetear')).show();
              }
            }).fail(function(){
              btn.prop('disabled', false).text('🔄 Generar nueva contraseña para admin Luna');
              msg.text('Error de conexión. Recargá la página e intentá de nuevo.').show();
            });
          });

          // ── Guardar config recordatorios ─────────────────────────────────────
          $('#luna-btn-save-reminders').on('click', function(){
            var btn = $(this);
            var msg = $('#luna-rem-msg');
            btn.prop('disabled', true).text('Guardando…');
            msg.hide();
            $.post(ajaxurl, {
              action:  'luna_save_reminders',
              nonce:   nonce,
              enabled: $('#rem-enabled').is(':checked') ? 1 : 0,
              hour:    parseInt($('#rem-hour').val())
            }, function(res){
              btn.prop('disabled', false).text('💾 Guardar configuración');
              if (res.success) {
                msg.css('color','#16a34a').text('✅ ' + (res.data.message || 'Configuración guardada.')).show();
              } else {
                msg.css('color','#dc2626').text('❌ ' + (res.data || 'Error al guardar.')).show();
              }
              setTimeout(function(){ msg.fadeOut(); }, 4000);
            }).fail(function(){
              btn.prop('disabled', false).text('💾 Guardar configuración');
              msg.css('color','#dc2626').text('❌ Error de conexión.').show();
            });
          });

          // ── Enviar recordatorios ahora ───────────────────────────────────────
          $('#luna-btn-send-now').on('click', function(){
            if (!confirm('¿Enviar recordatorios ahora a todos los usuarios con tareas pendientes?\n(Ignorará el control de "ya enviado hoy")')) return;
            var btn = $(this);
            var msg = $('#luna-rem-msg');
            btn.prop('disabled', true).text('Enviando…');
            msg.hide();
            $('#luna-rem-preview').hide();
            $.ajax({
              url:     ajaxurl,
              type:    'POST',
              timeout: 60000,
              data:    { action: 'luna_send_reminders_now', nonce: nonce },
              success: function(res){
                btn.prop('disabled', false).text('🚀 Enviar ahora (prueba)');
                if (res.success && res.data) {
                  var d = res.data;
                  msg.css('color','#16a34a').text('✅ Enviados: ' + d.sent + ' · Errores: ' + (d.errors||0)).show();
                  if (d.preview && d.preview.length) {
                    var rows = '';
                    $.each(d.preview, function(_, p){
                      rows += '<div style="padding:6px 0;border-bottom:1px solid #e2e8f0">'
                            + '<strong>' + p.name + '</strong> (' + p.to + ') — '
                            + p.total + ' tarea(s) · <em style="color:#64748b;font-size:11px">' + p.subject + '</em></div>';
                    });
                    if (!rows) rows = '<p style="color:#94a3b8;margin:0">No hay tareas próximas a vencer para ningún usuario.</p>';
                    $('#luna-rem-preview-body').html(rows);
                    $('#luna-rem-preview').show();
                  }
                } else {
                  msg.css('color','#dc2626').text('❌ ' + (res.data || 'Error al enviar.')).show();
                }
              },
              error: function(xhr, status){
                btn.prop('disabled', false).text('🚀 Enviar ahora (prueba)');
                var m = status === 'timeout' ? 'Tiempo de espera agotado (60s).' : 'Error de conexión.';
                msg.css('color','#dc2626').text('❌ ' + m).show();
              }
            });
          });
        });
        </script>
        <?php
    }

    // ── License page ──────────────────────────────────────────────────────────
    public function render_license_page() {
        if (isset($_POST['luna_save_license_settings']) && check_admin_referer('luna_license_settings')) {
            $new_key = sanitize_text_field($_POST['luna_license_key'] ?? '');
            if (!empty($_POST['luna_license_server_url'])) {
                update_option('luna_license_server_url', esc_url_raw(trim($_POST['luna_license_server_url'])));
            }
            update_option('luna_license_key', $new_key);
            Luna_Activator::regenerate_app_config();
            @unlink(LUNA_APP_DIR . 'luna-license-cache.json');
            Luna_License::clear_cache($new_key);
            echo '<div class="notice notice-success"><p>✅ Licencia guardada. Verificando...</p></div>';
        }

        if (isset($_POST['luna_clear_license_cache']) && check_admin_referer('luna_license_settings')) {
            $cur_key = get_option('luna_license_key', '');
            if ($cur_key) Luna_License::clear_cache($cur_key);
            echo '<div class="notice notice-success"><p>🔄 Cache de licencia limpiada. Verificando de nuevo...</p></div>';
        }

        $key    = get_option('luna_license_key', '');
        $domain = parse_url(get_site_url(), PHP_URL_HOST) ?? ($_SERVER['HTTP_HOST'] ?? '');

        $status_html = '';
        if ($key) {
            $result = Luna_License::verify($key, $domain);
            if (!empty($result['valid'])) {
                $plan    = Luna_License::plan_label($result['plan'] ?? 'unknown');
                $expires = $result['expires_at'] ?? '—';
                $extra   = '';
                if (!empty($result['offline'])) $extra .= ' <em style="color:#f59e0b">(verificación offline)</em>';
                if (!empty($result['grace']))   $extra .= ' <em style="color:#f59e0b">⚠️ Gracia: ' . ($result['grace_days'] ?? 0) . ' días restantes</em>';
                $status_html = '<div class="notice notice-success inline" style="margin:0"><p>✅ Licencia <strong>activa</strong> — Plan: <strong>' . esc_html($plan) . '</strong> — Vence: <strong>' . esc_html($expires) . '</strong>' . $extra . '</p></div>';
            } else {
                $msg    = $result['message'] ?? ($result['reason'] ?? 'Licencia inválida');
                $reason = $result['reason'] ?? '';
                $detail = '';
                if ($reason === 'server_unreachable') {
                    $detail = '<br><small style="color:#999">Servidor: <code>' . esc_html(get_option('luna_license_server_url', Luna_License::SERVER)) . '</code> — verificá que el plugin Luna Licencias esté activo en ese dominio y que el dominio sea accesible.</small>';
                } else {
                    $detail = '<br><small style="color:#999">Razón: <code>' . esc_html($reason ?: 'sin_razón') . '</code> — Dominio enviado: <code>' . esc_html($domain) . '</code></small>';
                }
                $status_html = '<div class="notice notice-error inline" style="margin:0"><p>❌ ' . esc_html($msg) . $detail . '</p></div>';
            }
        }
        ?>
        <div class="wrap luna-wrap">
          <h1>🔑 Luna Workspace — Activación</h1>

          <?php if ($status_html): ?>
            <div style="margin-bottom:20px"><?php echo $status_html ?></div>
          <?php endif; ?>

          <div class="luna-grid">
            <div class="luna-card">
              <h2>Activar licencia</h2>
              <p style="color:#666;margin-bottom:16px">Ingresá la clave que recibiste al comprar Luna Workspace. La verificación es automática.</p>
              <form method="POST">
                <?php wp_nonce_field('luna_license_settings') ?>
                <table class="form-table">
                  <tr>
                    <th scope="row"><label for="luna-license-input">Clave de licencia</label></th>
                    <td>
                      <input type="text" id="luna-license-input" name="luna_license_key"
                             value="<?php echo esc_attr($key) ?>"
                             placeholder="LUNA-XXXX-XXXX-XXXX-XXXX"
                             class="regular-text"
                             style="font-family:monospace;letter-spacing:2px;font-size:16px;width:320px">
                      <p class="description" style="margin-top:6px">
                        Tu dominio: <code><?php echo esc_html($domain) ?></code> — se valida automáticamente.
                      </p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="luna-server-url">URL del servidor de licencias</label></th>
                    <td>
                      <input type="url" id="luna-server-url" name="luna_license_server_url"
                             value="<?php echo esc_attr(get_option('luna_license_server_url', Luna_License::SERVER)) ?>"
                             class="large-text" style="font-family:monospace;font-size:12px">
                      <p class="description">Dejá el valor por defecto si no cambiaste el servidor.</p>
                    </td>
                  </tr>
                </table>
                <p>
                  <button type="submit" name="luna_save_license_settings" class="button button-primary" style="font-size:14px;padding:6px 20px">
                    ✅ Activar licencia
                  </button>
                  &nbsp;
                  <button type="submit" name="luna_clear_license_cache" class="button"
                          title="Borra el cache guardado y vuelve a consultar el servidor de licencias">
                    🔄 Limpiar cache y reverificar
                  </button>
                </p>
              </form>
              <div id="luna-license-result" style="margin-top:16px;display:none"></div>
            </div>

            <div class="luna-card">
              <h2>Planes</h2>
              <table class="widefat striped">
                <thead><tr><th>Plan</th><th>Precio/mes</th><th>Usuarios</th><th>Pizarras</th><th>Notificaciones</th></tr></thead>
                <tbody>
                  <tr><td><strong>Gratis</strong></td><td>$0</td><td>1</td><td>1</td><td>No</td></tr>
                  <tr><td><strong>Básico</strong></td><td>$19</td><td>Hasta 5</td><td>Ilimitadas</td><td>Sí</td></tr>
                  <tr><td><strong>Profesional</strong></td><td>$39</td><td>Hasta 20</td><td>Ilimitadas</td><td>Sí</td></tr>
                  <tr><td><strong>Corporativo</strong></td><td>$89</td><td>Ilimitados</td><td>Ilimitadas</td><td>Sí</td></tr>
                </tbody>
              </table>
              <p style="margin-top:12px;color:#666;font-size:12px">
                Licencias mensuales, vinculadas al dominio para prevenir uso no autorizado.<br>
                Comprar o renovar: <a href="https://websobreruedas.com" target="_blank">websobreruedas.com</a>
              </p>
            </div>
          </div>
        </div>
        <?php
    }

    // ── Notifications page ────────────────────────────────────────────────────
    public function render_notifications_page() {
        global $wpdb;

        // Try app DB first (may differ from WP DB)
        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        $users  = [];
        $db_source = '';
        if ($appDb !== null && $appPfx !== null) {
            $usersTable = $appPfx !== '' ? "`{$appPfx}users`" : '`users`';
            $pfxLabel   = $appPfx !== '' ? $appPfx : '(sin prefijo)';
            // First attempt: full query with optional columns
            try {
                $st = $appDb->query(
                    "SELECT id, name, email,
                            COALESCE(phone,'') AS phone,
                            COALESCE(whatsapp_apikey,'') AS whatsapp_apikey,
                            telegram_chat_id,
                            COALESCE(notification_channel,'email') AS notification_channel,
                            active
                     FROM {$usersTable} ORDER BY name ASC"
                );
                $users = $st->fetchAll();
                $db_source = "App DB (<code>{$cfg_db_name}</code>) · prefix <code>{$pfxLabel}</code>";
            } catch (Exception $e) {
                // Optional columns may not exist yet — retry with basic columns only
                // so we still get the users even without phone/channel fields
                try {
                    $st = $appDb->query(
                        "SELECT id, name, email,
                                '' AS phone,
                                '' AS whatsapp_apikey,
                                NULL AS telegram_chat_id,
                                'email' AS notification_channel,
                                active
                         FROM {$usersTable} ORDER BY name ASC"
                    );
                    $users = $st->fetchAll();
                    $db_source = "App DB (<code>{$cfg_db_name}</code>) · prefix <code>{$pfxLabel}</code> (columnas básicas)";
                } catch (Exception $e2) {
                    $db_source = "Error App DB: " . esc_html($e2->getMessage());
                }
            }
        }
        // Fallback: WordPress DB (same two-level approach)
        if (empty($users)) {
            $p = $wpdb->prefix . 'luna_';
            $users = $wpdb->get_results(
                "SELECT id, name, email,
                        COALESCE(phone,'') AS phone,
                        COALESCE(whatsapp_apikey,'') AS whatsapp_apikey,
                        telegram_chat_id,
                        COALESCE(notification_channel,'email') AS notification_channel,
                        active
                 FROM `{$p}users` ORDER BY name ASC",
                ARRAY_A
            ) ?: [];
            if (empty($users) && !$wpdb->last_error) {
                // Retry with basic columns
                $users = $wpdb->get_results(
                    "SELECT id, name, email,
                            '' AS phone,
                            '' AS whatsapp_apikey,
                            NULL AS telegram_chat_id,
                            'email' AS notification_channel,
                            active
                     FROM `{$p}users` ORDER BY name ASC",
                    ARRAY_A
                ) ?: [];
            }
            $db_source = $db_source ?: "WP DB (<code>{$wpdb->dbname}</code>) · prefix <code>{$p}</code>";
        }

        $channel_labels = [
            'email'     => '📧 Email',
            'whatsapp'  => '💬 WhatsApp',
            'telegram'  => '✈️ Telegram',
            'all'       => '📡 Todos',
            'none'      => '🔕 Ninguno',
        ];
        ?>
        <div class="wrap luna-wrap">
          <h1>🔔 Luna Workspace — Notificaciones</h1>

          <div class="luna-card" style="margin-bottom:20px">
            <h2 style="margin-top:0">Cómo funciona cada canal</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:12px">
              <div style="padding:14px;background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe">
                <strong>📧 Email</strong><br>
                <span style="font-size:12px;color:#374151">Requiere SMTP configurado en Luna → Configuración → Email. Funciona sin datos extra en el perfil.</span>
              </div>
              <div style="padding:14px;background:#f0fdf4;border-radius:10px;border:1px solid #86efac">
                <strong>💬 WhatsApp (CallMeBot)</strong><br>
                <span style="font-size:12px;color:#374151">El usuario debe: 1) Enviar <code>I allow callmebot to send me messages</code> a <strong>+34 644 59 60 32</strong> en WhatsApp. 2) Ingresar su número y API Key en su perfil de Luna.</span>
              </div>
              <div style="padding:14px;background:#fdf4ff;border-radius:10px;border:1px solid #d8b4fe">
                <strong>✈️ Telegram</strong><br>
                <span style="font-size:12px;color:#374151">El usuario debe iniciar chat con el bot de Luna y copiar su Chat ID en su perfil.</span>
              </div>
            </div>
          </div>

          <div class="luna-card">
            <h2 style="margin-top:0">Usuarios y estado de notificaciones <span style="font-size:13px;font-weight:normal;color:#6b7280">(<?= count($users) ?> usuario<?= count($users) != 1 ? 's' : '' ?> en Luna)</span></h2>
            <p style="color:#666;font-size:13px;margin-top:-8px">Podés editar el teléfono, WA API Key y canal directamente aquí. Hacé clic en 💾 para guardar cada fila.</p>
            <table class="widefat fixed striped" style="font-size:12px">
              <thead>
                <tr>
                  <th style="width:130px">Nombre</th>
                  <th style="width:160px">Email</th>
                  <th style="width:130px">Teléfono</th>
                  <th style="width:130px">WA API Key</th>
                  <th style="width:120px">Canal</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u):
                  $channel = $u['notification_channel'] ?: 'email';
                  $waReady = !empty($u['phone']) && !empty($u['whatsapp_apikey']);
                ?>
                <tr>
                  <td>
                    <strong><?= esc_html($u['name']) ?></strong>
                    <?= !$u['active'] ? ' <span style="background:#fee2e2;color:#dc2626;font-size:10px;padding:1px 5px;border-radius:4px;vertical-align:middle">inactivo</span>' : '' ?>
                  </td>
                  <td style="font-size:11px"><?= esc_html($u['email'] ?: '—') ?></td>
                  <td>
                    <input type="text" class="luna-uf-phone" data-uid="<?= (int)$u['id'] ?>"
                           value="<?= esc_attr($u['phone'] ?? '') ?>"
                           placeholder="+549..." style="width:100%;font-size:11px;padding:3px 5px">
                  </td>
                  <td>
                    <input type="text" class="luna-uf-wakey" data-uid="<?= (int)$u['id'] ?>"
                           value="<?= esc_attr($u['whatsapp_apikey'] ?? '') ?>"
                           placeholder="API Key CallMeBot" style="width:100%;font-size:11px;padding:3px 5px">
                  </td>
                  <td>
                    <select class="luna-uf-channel" data-uid="<?= (int)$u['id'] ?>" style="width:100%;font-size:11px">
                      <?php foreach ($channel_labels as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= selected($channel, $val, false) ?>><?= $lbl ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <button class="button button-small luna-save-user" data-uid="<?= (int)$u['id'] ?>" style="margin-right:3px">💾</button>
                    <?php if ($u['active']): ?>
                      <button class="button button-small luna-test-notif" data-uid="<?= (int)$u['id'] ?>" data-name="<?= esc_attr($u['name']) ?>" style="margin-right:3px" title="Probar email">📧</button>
                      <?php if ($waReady): ?>
                        <button class="button button-small luna-test-notif-wa" data-uid="<?= (int)$u['id'] ?>" data-name="<?= esc_attr($u['name']) ?>" title="Probar WhatsApp">💬</button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <span class="luna-save-msg-<?= (int)$u['id'] ?>" style="font-size:11px;margin-left:4px"></span>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                  <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px">No hay usuarios en Luna Workspace todavía.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div id="luna-notif-result" style="display:none;margin-top:16px"></div>

          <?php
          // ── Recordatorios: cargar datos ───────────────────────────────────────
          $rem2      = [];
          $rem2_cols = [];
          $rem2_cfg  = [];
          $rem2_last = '';
          if ($appDb && $appPfx !== null) {
              $tbl2 = $appPfx !== '' ? "{$appPfx}app_settings" : 'app_settings';
              try {
                  $r = $appDb->query("SELECT meta_value FROM `{$tbl2}` WHERE meta_key='reminder_schedule' LIMIT 1")->fetch();
                  $rem2 = $r ? (json_decode($r['meta_value'], true) ?: []) : [];
              } catch (Exception $e) {}
              try {
                  $r = $appDb->query("SELECT meta_value FROM `{$tbl2}` WHERE meta_key='reminder_config' LIMIT 1")->fetch();
                  $rem2_cfg = $r ? (json_decode($r['meta_value'], true) ?: []) : [];
              } catch (Exception $e) {}
              try {
                  $r = $appDb->query("SELECT meta_value FROM `{$tbl2}` WHERE meta_key='reminders_last_sent' LIMIT 1")->fetch();
                  $rem2_last = $r ? $r['meta_value'] : '';
              } catch (Exception $e) {}
              try {
                  $ws1 = $appDb->query("SELECT id FROM `{$appPfx}workspaces` ORDER BY id LIMIT 1")->fetchColumn();
                  if ($ws1) {
                      $st2 = $appDb->prepare("SELECT id, title, color FROM `{$appPfx}columns_k` WHERE workspace_id=? ORDER BY position, id");
                      $st2->execute([(int)$ws1]);
                      $rem2_cols = $st2->fetchAll(PDO::FETCH_ASSOC);
                  }
              } catch (Exception $e) {}
          }
          $rem2_enabled = !empty($rem2['enabled']);
          $rem2_hour    = (int)($rem2['hour'] ?? 8);
          $rem2_matrix  = isset($rem2_cfg['matrix']) ? $rem2_cfg['matrix'] : null;
          $rem2_users   = array_filter($users, fn($u) => !empty($u['active']));
          ?>

          <?php /* show reminders card always */ if (true): ?>
          <div class="luna-card" style="margin-top:20px" id="luna-reminders-card">
            <h2 style="margin-top:0">⏰ Recordatorios diarios</h2>
            <p style="color:#666;font-size:13px;margin-top:-8px">
              Cada día a la hora configurada se envía a cada destinatario un resumen de sus tareas por columna —
              por el canal ya configurado en su perfil (email, WhatsApp o Telegram).
            </p>

            <!-- Activar + Hora -->
            <div style="display:flex;align-items:center;gap:20px;margin-bottom:16px;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
                <input type="checkbox" id="rem2-enabled" <?php checked($rem2_enabled) ?> style="width:17px;height:17px;accent-color:#16a34a;cursor:pointer">
                Activar recordatorios automáticos
              </label>
              <div style="display:flex;align-items:center;gap:8px">
                <label for="rem2-hour" style="font-size:13px;font-weight:600">Hora de envío:</label>
                <select id="rem2-hour" style="font-size:13px;padding:4px 8px;border-radius:7px;border:1px solid #ddd">
                  <?php for ($h = 0; $h < 24; $h++):
                    $lbl = sprintf('%02d:00 hs', $h);
                    if ($h === 8) $lbl .= ' (recomendado)';
                  ?>
                    <option value="<?= $h ?>" <?= selected($rem2_hour, $h, false) ?>><?= $lbl ?></option>
                  <?php endfor; ?>
                </select>
                <span style="font-size:11px;color:#64748b">Hora del servidor: <strong><?= date('H:i') ?></strong></span>
              </div>
              <?php if ($rem2_last): ?>
              <span style="font-size:12px;color:#16a34a">Último envío: <strong><?= esc_html($rem2_last) ?></strong></span>
              <?php endif; ?>
            </div>

            <!-- Grilla miembros × columnas -->
            <div style="overflow-x:auto;margin-bottom:16px">
              <table style="border-collapse:collapse;width:100%;font-size:12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
                <thead>
                  <tr style="background:#f1f5f9">
                    <th style="padding:10px 14px;text-align:left;font-size:12px;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap">
                      Miembro
                    </th>
                    <?php foreach ($rem2_cols as $col): ?>
                    <th style="padding:10px 10px;text-align:center;font-size:11px;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;min-width:80px">
                      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= esc_attr($col['color']?:'#5b6af0') ?>;margin-right:4px;vertical-align:middle"></span>
                      <?= esc_html($col['title']) ?>
                    </th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (array_values($rem2_users) as $i => $u):
                    $uid      = (int)$u['id'];
                    $ch       = $u['notification_channel'] ?: 'email';
                    $icon     = $ch === 'whatsapp' ? '📱' : ($ch === 'telegram' ? '✈️' : '✉️');
                    $init     = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w,0,1), array_slice(preg_split('/\s+/', trim($u['name']??'?')), 0, 2))));
                    $bg       = esc_attr($u['color'] ?? '#5b6af0');
                    $rowBg    = $i % 2 === 0 ? '#fff' : '#f8fafc';
                    $userCols = ($rem2_matrix !== null && isset($rem2_matrix[(string)$uid]))
                                ? (array)$rem2_matrix[(string)$uid]
                                : null; // null = all checked
                  ?>
                  <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #f1f5f9" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='<?= $rowBg ?>'">
                    <td style="padding:9px 14px;white-space:nowrap">
                      <div style="display:flex;align-items:center;gap:8px">
                        <span style="width:26px;height:26px;border-radius:50%;background:<?= $bg ?>;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:#fff;flex-shrink:0"><?= esc_html($init) ?></span>
                        <span style="font-weight:600;color:#1e293b"><?= esc_html($u['name']) ?></span>
                        <span style="font-size:12px" title="Canal: <?= esc_attr($ch) ?>"><?= $icon ?></span>
                      </div>
                    </td>
                    <?php foreach ($rem2_cols as $col):
                      $colName = $col['title'];
                      $checked = ($userCols === null || in_array($colName, $userCols));
                    ?>
                    <td style="text-align:center;padding:9px 10px">
                      <input type="checkbox"
                             class="rem2-cell"
                             data-rem-uid="<?= $uid ?>"
                             data-rem-col="<?= esc_attr($colName) ?>"
                             <?php checked($checked) ?>
                             style="width:16px;height:16px;accent-color:<?= esc_attr($col['color']?:'#5b6af0') ?>;cursor:pointer">
                    </td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button id="rem2-btn-save" class="button button-primary" style="font-size:13px;padding:6px 18px">💾 Guardar configuración</button>
              <button id="rem2-btn-send" class="button button-secondary" style="font-size:13px;padding:6px 18px">🚀 Enviar ahora (prueba)</button>
              <button type="button" class="button" style="font-size:11px" onclick="$('.rem2-cell').prop('checked',true)">Todos ☑</button>
              <button type="button" class="button" style="font-size:11px" onclick="$('.rem2-cell').prop('checked',false)">Ninguno □</button>
              <span id="rem2-msg" style="font-size:13px;display:none"></span>
            </div>

            <div id="rem2-preview" style="display:none;margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px">
              <strong style="font-size:13px">📋 Resultado:</strong>
              <div id="rem2-preview-body" style="margin-top:8px;font-size:12px"></div>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <script>
        jQuery(function($){
          var nonce = '<?= wp_create_nonce('luna_admin_nonce') ?>';

          // ── Recordatorios: construir matriz ──────────────────────────────────
          function buildMatrix2() {
            var matrix = {};
            $('.rem2-cell').each(function(){
              var uid = String($(this).data('rem-uid'));
              var col = String($(this).data('rem-col'));
              if (!matrix[uid]) matrix[uid] = [];
              if ($(this).is(':checked')) matrix[uid].push(col);
            });
            return matrix;
          }

          $('#rem2-btn-save').on('click', function(){
            var btn = $(this), msg = $('#rem2-msg');
            btn.prop('disabled', true).text('Guardando…'); msg.hide();
            $.post(ajaxurl, {
              action:  'luna_save_reminders',
              nonce:   nonce,
              enabled: $('#rem2-enabled').is(':checked') ? 1 : 0,
              hour:    parseInt($('#rem2-hour').val()),
              matrix:  JSON.stringify(buildMatrix2())
            }, function(res){
              btn.prop('disabled', false).text('💾 Guardar configuración');
              msg.css('color', res.success ? '#16a34a' : '#dc2626')
                 .text(res.success ? '✅ ' + (res.data.message || 'Guardado.') : '❌ ' + (res.data || 'Error.')).show();
              setTimeout(function(){ msg.fadeOut(); }, 4000);
            }).fail(function(){ btn.prop('disabled', false).text('💾 Guardar configuración'); msg.css('color','#dc2626').text('❌ Error de conexión.').show(); });
          });

          $('#rem2-btn-send').on('click', function(){
            var btn = $(this), msg = $('#rem2-msg');
            btn.prop('disabled', true).text('Enviando…'); msg.hide(); $('#rem2-preview').hide();
            $.ajax({
              url: ajaxurl, type: 'POST', timeout: 60000,
              data: { action: 'luna_send_reminders_now', nonce: nonce, matrix: JSON.stringify(buildMatrix2()) },
              success: function(res){
                btn.prop('disabled', false).text('🚀 Enviar ahora (prueba)');
                if (res.success && res.data) {
                  var d = res.data;
                  var detail = d.detail || {};
                  var sentTxt = d.message || ('✅ Enviados: ' + (detail.sent ?? '?') + (detail.errors ? ' · Errores: ' + detail.errors : ''));
                  msg.css('color','#16a34a').html(sentTxt).show();
                  var preview = detail.preview || d.preview || [];
                  if (preview.length) {
                    var rows = preview.map(function(p){ return '<div style="padding:5px 0;border-bottom:1px solid #e2e8f0"><strong>' + p.name + '</strong> (' + p.to + ') — ' + p.total + ' tarea(s)</div>'; }).join('');
                    $('#rem2-preview-body').html(rows || '<p style="color:#94a3b8;margin:0">Sin tareas próximas.</p>');
                    $('#rem2-preview').show();
                  }
                } else { msg.css('color','#dc2626').text('❌ ' + (res.data || 'Error.')).show(); }
              },
              error: function(xhr, status){
                btn.prop('disabled', false).text('🚀 Enviar ahora (prueba)');
                msg.css('color','#dc2626').text('❌ ' + (status === 'timeout' ? 'Tiempo agotado.' : 'Error de conexión.')).show();
              }
            });
          });

          // Reset admin password (runs on both main page and notifications page)
          $(document).on('click', '#luna-btn-reset-pass', function(){
            if (!confirm('¿Generar una nueva contraseña para el admin de Luna?\nLa contraseña actual dejará de funcionar.')) return;
            var btn = $(this);
            var msg = $('#luna-reset-pass-msg');
            btn.prop('disabled', true).text('Generando…');
            msg.hide();
            $.post(ajaxurl, { action: 'luna_reset_admin_pass', nonce: nonce }, function(res){
              btn.prop('disabled', false).text('🔄 Generar nueva contraseña para admin Luna');
              if (res.success && res.data && res.data.password) {
                $('#luna-new-pass').text(res.data.password);
                $('#luna-new-pass-result').show();
                $('html,body').animate({scrollTop: $('#luna-new-pass-result').offset().top - 60}, 300);
              } else {
                msg.text('Error: ' + (res.data || 'No se pudo resetear')).show();
              }
            }).fail(function(){
              btn.prop('disabled', false).text('🔄 Generar nueva contraseña para admin Luna');
              msg.text('Error de conexión. Recargá la página e intentá de nuevo.').show();
            });
          });

          // Save user contact fields
          $(document).on('click', '.luna-save-user', function(){
            var uid  = $(this).data('uid');
            var row  = $(this).closest('tr');
            var msg  = $('.luna-save-msg-'+uid);
            $(this).prop('disabled', true).text('…');
            $.post(ajaxurl, {
              action:  'luna_save_user_contact',
              nonce:   nonce,
              user_id: uid,
              phone:   row.find('.luna-uf-phone').val(),
              wakey:   row.find('.luna-uf-wakey').val(),
              channel: row.find('.luna-uf-channel').val()
            }, function(res){
              if (res.success) {
                msg.css('color','#166534').text('✓ Guardado');
                // Enable WA test button if both fields filled
                var phone = row.find('.luna-uf-phone').val().trim();
                var wakey = row.find('.luna-uf-wakey').val().trim();
                if (phone && wakey && !row.find('.luna-test-notif-wa').length) {
                  row.find('.luna-test-notif').after(' <button class="button button-small luna-test-notif-wa" data-uid="'+uid+'" data-name="'+row.find('strong').first().text()+'" title="Probar WhatsApp">💬</button>');
                }
              } else {
                msg.css('color','#dc2626').text('✗ ' + (res.data || 'Error'));
              }
              setTimeout(function(){ msg.text(''); }, 3000);
              $('.luna-save-user[data-uid='+uid+']').prop('disabled', false).text('💾');
            });
          });

          // Test notifications
          function testNotif(uid, name, channel) {
            var btn = $('[data-uid="'+uid+'"]').filter(channel === 'wa' ? '.luna-test-notif-wa' : '.luna-test-notif');
            btn.prop('disabled', true).text('Enviando…');
            $.ajax({
              url:     ajaxurl,
              type:    'POST',
              timeout: 25000,
              data: {
                action:  'luna_test_notification',
                nonce:   nonce,
                user_id: uid,
                channel: channel
              },
              success: function(res){
                var box = $('#luna-notif-result');
                if (res.success) {
                  box.html('<div class="notice notice-success" style="padding:10px 14px"><p>✅ Notificación de prueba enviada a <strong>'+name+'</strong>.</p></div>').show();
                } else {
                  box.html('<div class="notice notice-error" style="padding:10px 14px"><p>❌ Error: ' + (res.data || 'No se pudo enviar') + '</p></div>').show();
                }
                btn.prop('disabled', false).text(channel === 'wa' ? '💬' : '📧');
                $('html,body').animate({scrollTop: box.offset().top - 40}, 300);
              },
              error: function(xhr, status){
                var box = $('#luna-notif-result');
                var msg = status === 'timeout'
                  ? 'Tiempo de espera agotado (504). El servidor no pudo conectarse al SMTP. Verificá Host/Puerto/SSL en Configuración → SMTP.'
                  : 'Error HTTP ' + xhr.status + '. Revisá los logs de PHP en el hosting.';
                box.html('<div class="notice notice-error" style="padding:10px 14px"><p>❌ ' + msg + '</p></div>').show();
                btn.prop('disabled', false).text(channel === 'wa' ? '💬' : '📧');
              }
            });
          }

          $(document).on('click', '.luna-test-notif',    function(){ testNotif($(this).data('uid'), $(this).data('name'), 'email'); });
          $(document).on('click', '.luna-test-notif-wa', function(){ testNotif($(this).data('uid'), $(this).data('name'), 'wa'); });
        });
        </script>
        <?php
    }

    // ── AJAX: send test notification ──────────────────────────────────────────
    public function ajax_test_notification() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $uid     = (int)($_POST['user_id'] ?? 0);
        $channel = sanitize_text_field($_POST['channel'] ?? 'email');

        global $wpdb;
        $p    = $wpdb->prefix . 'luna_';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT name, email, phone, whatsapp_apikey, telegram_chat_id, notification_channel FROM `{$p}users` WHERE id=%d AND active=1",
            $uid
        ), ARRAY_A);

        if (!$user) wp_send_json_error('Usuario no encontrado');

        $subject = '🔔 Prueba de notificación — Luna Workspace';
        $plain   = 'Hola ' . $user['name'] . ', esta es una notificación de prueba de Luna Workspace. Si la recibís, todo está correcto.';

        // ── WhatsApp ──────────────────────────────────────────────────────────
        if ($channel === 'wa') {
            if (empty($user['phone']) || empty($user['whatsapp_apikey'])) {
                wp_send_json_error('El usuario no tiene teléfono o API Key de CallMeBot configurado');
            }
            $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
                'phone'  => $user['phone'],
                'text'   => $plain,
                'apikey' => $user['whatsapp_apikey'],
            ]);
            $resp = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($resp)) wp_send_json_error('CallMeBot no respondió: ' . $resp->get_error_message());
            wp_send_json_success();
        }

        // ── Email via Postfix local (localhost:25, sin SSL, sin auth) ─────────────
        // Conecta directo al MTA del servidor — sin colgar, sin 504
        if (empty($user['email'])) wp_send_json_error('El usuario no tiene email configurado');

        $st  = $wpdb->get_row("SELECT meta_value FROM `{$p}app_settings` WHERE meta_key='email_settings' LIMIT 1");
        $cfg = $st ? (json_decode($st->meta_value, true) ?: []) : [];

        $from_email = !empty($cfg['from_email']) ? $cfg['from_email'] : (!empty($cfg['smtp_user']) ? $cfg['smtp_user'] : 'info@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $from_name  = !empty($cfg['from_name'])  ? $cfg['from_name']  : 'Luna Workspace';
        $to         = $user['email'];

        $html = '<p>Hola <strong>' . esc_html($user['name']) . '</strong>,</p>'
              . '<p>Esta es una notificación de prueba de Luna Workspace. Si la recibís, todo está configurado correctamente.</p>';

        // Inyectar via localhost:25 (Postfix local — Plesk siempre lo tiene activo)
        // Sin SSL, sin auth, timeout 5s → respuesta instantánea
        $mail_error = '';
        add_action('phpmailer_init', function($m) use ($from_email, $from_name) {
            $m->isSMTP();
            $m->Host       = '127.0.0.1';
            $m->Port       = 25;
            $m->SMTPAuth   = false;
            $m->SMTPSecure = '';
            $m->Timeout    = 5;
            $m->setFrom($from_email, $from_name);
        });
        add_action('wp_mail_failed', function($e) use (&$mail_error) {
            $mail_error = $e->get_error_message();
        });
        add_filter('wp_mail_content_type', fn() => 'text/html');

        $sent = wp_mail($to, $subject, $html);

        remove_all_actions('phpmailer_init');
        remove_all_actions('wp_mail_failed');
        remove_all_filters('wp_mail_content_type');

        if ($sent) {
            wp_send_json_success('✅ Email enviado a ' . $to . ' desde ' . $from_email);
        } else {
            // Fallback: intentar PHP mail() directo
            $headers  = "From: {$from_name} <{$from_email}>\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $fallback = @mail($to, $subject, $html, $headers);
            if ($fallback) {
                wp_send_json_success('✅ Email enviado (vía mail() nativo) a ' . $to);
            } else {
                wp_send_json_error('Error: ' . ($mail_error ?: 'No se pudo conectar al servidor de correo local (localhost:25). Verificá en Plesk → Mail → Mail Settings que el servicio de correo esté activo.'));
            }
        }
    }

    // ── AJAX: reset Luna admin password ──────────────────────────────────────────
    public function ajax_reset_admin_pass() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        global $wpdb;

        // Camino preferido: escribir en la MISMA base y tabla que consulta el
        // login de la app (puede ser una BD externa distinta de la de WP).
        $pdo    = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        if ($pdo !== null && $appPfx !== null) {
            try {
                $pdo->query("SELECT 1 FROM `{$appPfx}users` LIMIT 1");
                $new_pass = bin2hex(random_bytes(8)); // 16 chars, legible
                $hash     = password_hash($new_pass, PASSWORD_BCRYPT);
                $st = $pdo->prepare("UPDATE `{$appPfx}users` SET password=? WHERE role='admin' AND active=1");
                $st->execute([$hash]);
                if ($st->rowCount() > 0) {
                    // Invalidar sesiones del admin para forzar re-login
                    try {
                        $admin = $pdo->query("SELECT id FROM `{$appPfx}users` WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetch();
                        if ($admin) $pdo->prepare("DELETE FROM `{$appPfx}sessions` WHERE user_id=?")->execute([$admin['id']]);
                    } catch (Exception $e) {}
                    wp_send_json_success(['password' => $new_pass]);
                }
                // 0 filas → no hay admin activo en la BD de la app: seguir al camino WP
            } catch (Exception $e) {
                // La tabla no existe en la BD de la app → seguir al camino WP
            }
        }

        // Camino de respaldo: BD de WordPress con el prefijo WP actual
        $p = $wpdb->prefix . 'luna_';

        // Verificar que existe la tabla
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$p}users'");
        if (!$table_exists) {
            wp_send_json_error('Las tablas de Luna no están instaladas (prefijo "' . esc_html($p) . '"). Desactivá y reactivá el plugin.');
        }

        $new_pass = bin2hex(random_bytes(8)); // 16 chars, legible
        $hash     = password_hash($new_pass, PASSWORD_BCRYPT);

        $result = $wpdb->update(
            "{$p}users",
            ['password' => $hash],
            ['role' => 'admin', 'active' => 1],
            ['%s'],
            ['%s', '%d']
        );

        if ($result === false) {
            wp_send_json_error('Error al actualizar: ' . $wpdb->last_error);
        }

        if ($result === 0) {
            // No había admin activo — intentar crear uno
            Luna_Activator::activate();
            $new_pass2 = get_option('luna_initial_admin_pass', '');
            if ($new_pass2) {
                delete_option('luna_initial_admin_pass');
                wp_send_json_success(['password' => $new_pass2]);
            }
            wp_send_json_error('No se encontró usuario admin en Luna. Intentá desactivar y reactivar el plugin.');
        }

        // También invalidar todas las sesiones activas del admin para forzar re-login
        $admin = $wpdb->get_row("SELECT id FROM `{$p}users` WHERE role='admin' AND active=1 ORDER BY id LIMIT 1", ARRAY_A);
        if ($admin) {
            $wpdb->delete("{$p}sessions", ['user_id' => $admin['id']], ['%d']);
        }

        wp_send_json_success(['password' => $new_pass]);
    }

    // ── AJAX: save user contact fields from notifications page ─────────────────
    public function ajax_save_user_contact() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $uid     = (int)($_POST['user_id'] ?? 0);
        $phone   = sanitize_text_field($_POST['phone']   ?? '');
        $wakey   = sanitize_text_field($_POST['wakey']   ?? '');
        $channel = sanitize_text_field($_POST['channel'] ?? 'email');

        if (!$uid) wp_send_json_error('Usuario inválido');

        $valid_channels = ['email', 'whatsapp', 'telegram', 'all', 'none'];
        if (!in_array($channel, $valid_channels, true)) $channel = 'email';

        // Primary: app DB (may differ from WP DB — same logic as render_notifications_page)
        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        if ($appDb && $appPfx !== null) {
            $usersTable = $appPfx !== '' ? "`{$appPfx}users`" : '`users`';
            try {
                $st = $appDb->prepare(
                    "UPDATE {$usersTable} SET phone=?, whatsapp_apikey=?, notification_channel=? WHERE id=?"
                );
                $st->execute([$phone, $wakey, $channel, $uid]);
                wp_send_json_success();
            } catch (Exception $e) {
                wp_send_json_error('Error al guardar: ' . $e->getMessage());
            }
        }
        // Fallback: WordPress DB
        global $wpdb;
        $p      = $wpdb->prefix . 'luna_';
        $result = $wpdb->update(
            "{$p}users",
            ['phone' => $phone, 'whatsapp_apikey' => $wakey, 'notification_channel' => $channel],
            ['id' => $uid], ['%s', '%s', '%s'], ['%d']
        );
        if ($result === false) wp_send_json_error('Error al guardar: ' . $wpdb->last_error);
        wp_send_json_success();
    }

    // ── Database page ─────────────────────────────────────────────────────────
    public function render_database_page() {
        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        global $wpdb;

        $useAppDb = ($appDb !== null && $appPfx !== null);
        $pfx      = $useAppDb ? $appPfx : $wpdb->prefix . 'luna_';
        $cfg_file = plugin_dir_path(__FILE__) . '../app/luna-wp-config.php';
        $cfg_ok   = file_exists($cfg_file);
        $connected = ($useAppDb && $appDb !== null) || (!$useAppDb);

        $table_names = [
            'users', 'workspaces', 'workspace_members', 'workspace_labels',
            'columns_k', 'cards', 'card_tags', 'card_assignees', 'card_checklist',
            'card_dependencies', 'attachments', 'sessions',
            'notifications', 'app_settings', 'workspace_templates', 'activity_log',
            'user_meta',
        ];

        $tables = [];
        foreach ($table_names as $tname) {
            $full = $pfx . $tname;
            $row  = ['name' => $tname, 'rows' => null, 'size_kb' => null, 'error' => ''];
            if ($useAppDb) {
                try {
                    $r = $appDb->query("SELECT TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024,1) AS kb
                                        FROM information_schema.TABLES
                                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $appDb->quote($full))->fetch();
                    if ($r) { $row['rows'] = (int)$r['TABLE_ROWS']; $row['size_kb'] = $r['kb']; }
                } catch (Exception $e) { $row['error'] = $e->getMessage(); }
            } else {
                $r = $wpdb->get_row($wpdb->prepare(
                    "SELECT TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024,1) AS kb
                     FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", $full
                ), ARRAY_A);
                if ($r) { $row['rows'] = (int)$r['TABLE_ROWS']; $row['size_kb'] = $r['kb']; }
            }
            $tables[] = $row;
        }

        $total_rows = array_sum(array_filter(array_column($tables, 'rows'), fn($v) => $v !== null));
        $ok_count   = count(array_filter($tables, fn($t) => $t['rows'] !== null && !$t['error']));
        $err_count  = count(array_filter($tables, fn($t) => $t['error'] !== ''));
        $miss_count = count(array_filter($tables, fn($t) => $t['rows'] === null && !$t['error']));
        ?>
        <div class="wrap luna-wrap">
          <h1>🗃️ Luna Workspace — Estado de la base de datos</h1>

          <?php // ── Estado general ──────────────────────────────────────── ?>
          <div class="luna-grid" style="margin-bottom:20px">

            <div class="luna-card">
              <h2 style="margin-top:0">🔌 Conexión</h2>
              <div style="display:flex;align-items:center;gap:12px;padding:14px 0">
                <?php if ($connected): ?>
                  <span style="font-size:36px">✅</span>
                  <div>
                    <div style="font-size:15px;font-weight:700;color:#16a34a">Conexión activa</div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px">
                      <?php echo $useAppDb ? 'Base de datos de la app (luna-wp-config.php)' : 'Base de datos de WordPress' ?>
                    </div>
                  </div>
                <?php else: ?>
                  <span style="font-size:36px">❌</span>
                  <div>
                    <div style="font-size:15px;font-weight:700;color:#dc2626">Sin conexión</div>
                    <div style="font-size:12px;color:#64748b;margin-top:2px">Verificá la configuración del plugin</div>
                  </div>
                <?php endif; ?>
              </div>
              <hr style="border:none;border-top:1px solid #f1f5f9;margin:4px 0 14px">
              <div style="font-size:13px">
                <span style="color:#64748b">Archivo de configuración:</span>
                <?php if ($cfg_ok): ?>
                  <span style="color:#16a34a;font-weight:600">✅ Encontrado</span>
                <?php else: ?>
                  <span style="color:#dc2626;font-weight:600">❌ No encontrado</span>
                  <p style="font-size:11px;color:#dc2626;margin:4px 0 0">
                    Contactá a Web Sobre Ruedas para regenerar el archivo de configuración.
                  </p>
                <?php endif; ?>
              </div>
            </div>

            <div class="luna-card">
              <h2 style="margin-top:0">📊 Resumen</h2>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div style="background:#f0fdf4;border-radius:10px;padding:14px;text-align:center;border:1px solid #bbf7d0">
                  <div style="font-size:28px;font-weight:800;color:#16a34a"><?php echo $total_rows ?></div>
                  <div style="font-size:11px;color:#64748b;margin-top:2px;text-transform:uppercase;letter-spacing:.5px">Registros totales</div>
                </div>
                <div style="background:#eff6ff;border-radius:10px;padding:14px;text-align:center;border:1px solid #bfdbfe">
                  <div style="font-size:28px;font-weight:800;color:#2563eb"><?php echo $ok_count ?>/<?php echo count($tables) ?></div>
                  <div style="font-size:11px;color:#64748b;margin-top:2px;text-transform:uppercase;letter-spacing:.5px">Tablas activas</div>
                </div>
              </div>
              <?php if ($err_count > 0): ?>
                <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#dc2626">
                  ⚠️ <?php echo $err_count ?> tabla(s) con error. Contactá a Web Sobre Ruedas.
                </div>
              <?php elseif ($miss_count > 0): ?>
                <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:10px 14px;font-size:12px;color:#854d0e">
                  ℹ️ <?php echo $miss_count ?> tabla(s) no encontradas (pueden no ser necesarias en esta instalación).
                </div>
              <?php else: ?>
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;font-size:12px;color:#166534">
                  ✅ Todas las tablas en orden.
                </div>
              <?php endif; ?>
            </div>

          </div>

          <?php // ── Tabla de estado ─────────────────────────────────────── ?>
          <div class="luna-card">
            <h2 style="margin-top:0;display:flex;align-items:center;justify-content:space-between">
              <span>📋 Detalle por tabla</span>
              <span style="font-size:12px;font-weight:normal;color:#94a3b8"><?php echo $total_rows ?> registros en total</span>
            </h2>
            <table class="widefat fixed striped" style="font-size:12px">
              <thead>
                <tr>
                  <th style="width:200px">Tabla</th>
                  <th style="width:90px;text-align:right">Registros</th>
                  <th style="width:90px;text-align:right">Tamaño</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tables as $t):
                  $exists = $t['rows'] !== null && !$t['error'];
                ?>
                <tr>
                  <td><code style="font-size:11px"><?php echo esc_html($t['name']) ?></code></td>
                  <td style="text-align:right"><?php echo $exists ? '<strong>' . number_format($t['rows']) . '</strong>' : '<span style="color:#94a3b8">—</span>' ?></td>
                  <td style="text-align:right"><?php echo $exists ? esc_html($t['size_kb']) . ' KB' : '<span style="color:#94a3b8">—</span>' ?></td>
                  <td>
                    <?php if ($t['error']): ?>
                      <span style="color:#dc2626;font-size:11px">❌ Error</span>
                    <?php elseif (!$exists): ?>
                      <span style="color:#94a3b8;font-size:11px">No existe</span>
                    <?php else: ?>
                      <span style="color:#16a34a;font-size:11px">✓ OK</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p style="font-size:11px;color:#94a3b8;margin-top:8px">
              Los conteos son estimaciones de InnoDB. Para mantenimiento técnico (reparar, optimizar, regenerar configuración) contactá a
              <a href="https://websobreruedas.com" target="_blank" style="color:#5b6af0">Web Sobre Ruedas</a>.
            </p>
          </div>

        </div>
        <?php
    }

    // ── AJAX: database maintenance operations ─────────────────────────────────
    public function ajax_db_maintenance() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $op     = sanitize_key($_POST['op'] ?? '');
        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        global $wpdb;

        $useAppDb = ($appDb !== null && $appPfx !== null);
        $pfx      = $useAppDb ? $appPfx : $wpdb->prefix . 'luna_';

        $table_names = [
            'users', 'workspaces', 'workspace_members', 'workspace_labels',
            'columns_k', 'cards', 'card_tags', 'card_assignees', 'card_checklist',
            'card_dependencies', 'attachments', 'sessions',
            'notifications', 'app_settings', 'workspace_templates', 'activity_log',
            'user_meta',
        ];

        // Build list of existing tables
        $existing = [];
        foreach ($table_names as $t) {
            $full = $pfx . $t;
            if ($useAppDb) {
                try {
                    $found = $appDb->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $appDb->quote($full))->fetchColumn();
                    if ($found) $existing[] = "`{$full}`";
                } catch (Exception $e) {}
            } else {
                $found = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", $full));
                if ($found) $existing[] = "`{$full}`";
            }
        }

        if (empty($existing)) {
            wp_send_json_error('No se encontraron tablas Luna en la base de datos.');
        }

        $table_list = implode(', ', $existing);

        switch ($op) {

            case 'check':
            case 'optimize':
            case 'repair':
                $sql_op  = strtoupper($op);
                $rows    = [];
                if ($useAppDb) {
                    try {
                        $st = $appDb->query("{$sql_op} TABLE {$table_list}");
                        $rows = $st->fetchAll();
                    } catch (Exception $e) {
                        wp_send_json_error("Error en {$sql_op}: " . $e->getMessage());
                    }
                } else {
                    $results = $wpdb->get_results("{$sql_op} TABLE {$table_list}", ARRAY_A);
                    if ($results === null) wp_send_json_error("Error en {$sql_op}: " . $wpdb->last_error);
                    $rows = $results;
                }
                $errors = array_filter($rows, fn($r) => isset($r['Msg_type']) && $r['Msg_type'] === 'error');
                $msg = count($errors)
                    ? count($errors) . ' tabla(s) con errores — revisá el detalle abajo.'
                    : 'Todas las tablas procesadas correctamente.';
                wp_send_json_success(['message' => $msg, 'rows' => $rows]);

            case 'clean_sessions':
                $tbl = "`{$pfx}sessions`";
                if ($useAppDb) {
                    try {
                        $st      = $appDb->prepare("DELETE FROM {$tbl} WHERE expires_at < NOW()");
                        $st->execute();
                        $deleted = $st->rowCount();
                    } catch (Exception $e) {
                        wp_send_json_error('Error: ' . $e->getMessage());
                    }
                } else {
                    $wpdb->query("DELETE FROM {$tbl} WHERE expires_at < NOW()");
                    $deleted = $wpdb->rows_affected;
                }
                wp_send_json_success(['message' => "{$deleted} sesión(es) expirada(s) eliminada(s)."]);

            case 'regen_config':
                $pfx_detected = Luna_Activator::regenerate_app_config();
                wp_send_json_success(['message' => "luna-wp-config.php regenerado. Prefijo detectado: '{$pfx_detected}'"]);

            default:
                wp_send_json_error('Operación desconocida.');
        }
    }

    // ── Save daily reminder schedule ─────────────────────────────────────
    public function ajax_save_reminders() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $enabled = (int)($_POST['enabled'] ?? 0) ? 1 : 0;
        $hour    = max(0, min(23, (int)($_POST['hour'] ?? 8)));

        // Matrix: { "uid": ["ColName1", "ColName2", ...] }
        $matrix_raw = $_POST['matrix'] ?? '{}';
        $matrix     = json_decode(stripslashes($matrix_raw), true);
        if (!is_array($matrix)) $matrix = [];
        $matrix_clean = [];
        foreach ($matrix as $uid => $cols) {
            $matrix_clean[(string)(int)$uid] = array_values(array_map('sanitize_text_field', (array)$cols));
        }

        // Save reminder_config (full config with matrix) + legacy reminder_schedule for cron
        $config = json_encode(['enabled' => (bool)$enabled, 'hour' => $hour, 'matrix' => $matrix_clean]);
        $data   = json_encode(['enabled' => (bool)$enabled, 'hour' => $hour]);

        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        $saved  = false;

        if ($appDb && $appPfx !== null) {
            $tbl = $appPfx !== '' ? "`{$appPfx}app_settings`" : '`app_settings`';
            try {
                $appDb->prepare("INSERT INTO {$tbl} (meta_key, meta_value) VALUES ('reminder_schedule', ?)
                                 ON DUPLICATE KEY UPDATE meta_value=?")
                      ->execute([$data, $data]);
                $appDb->prepare("INSERT INTO {$tbl} (meta_key, meta_value) VALUES ('reminder_config', ?)
                                 ON DUPLICATE KEY UPDATE meta_value=?")
                      ->execute([$config, $config]);
                $saved = true;
            } catch (Exception $e) {}
        }

        if (!$saved) {
            update_option('luna_reminder_schedule', $data);
            update_option('luna_reminder_config',   $config);
        }

        // Reschedule WP cron based on new config
        $hook = 'luna_send_daily_reminders';
        wp_clear_scheduled_hook($hook);
        if ($enabled) {
            $next = mktime($hour, 0, 0);
            if ($next < time()) $next = strtotime('+1 day', $next);
            wp_schedule_event($next, 'daily', $hook);
        }

        wp_send_json_success([
            'message' => $enabled
                ? "Recordatorios activados — se enviarán diariamente a las {$hour}:00 hs."
                : 'Recordatorios desactivados.',
        ]);
    }

    // ── Send reminders immediately (test/preview) ────────────────────────
    public function ajax_send_reminders_now() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        // Write a one-time bypass token into app_settings so reminders.php
        // can authenticate without needing a luna_token session cookie.
        $bypass_token = bin2hex(random_bytes(16));
        $appDb  = $this->get_app_db();
        $appPfx = $this->get_app_prefix();
        if ($appDb && $appPfx !== null) {
            $tbl = $appPfx !== '' ? "{$appPfx}app_settings" : 'app_settings';
            $val = json_encode(['token' => $bypass_token, 'expires' => time() + 30]);
            $appDb->prepare("INSERT INTO `{$tbl}` (meta_key,meta_value) VALUES ('wp_admin_bypass',?) ON DUPLICATE KEY UPDATE meta_value=?")
                  ->execute([$val, $val]);
        } else {
            wp_send_json_error('No se pudo conectar a la base de datos de la app.');
        }

        $app_url = defined('LUNA_APP_URL') ? LUNA_APP_URL : plugin_dir_url(__FILE__) . '../app/';
        $url     = rtrim($app_url, '/') . '/api/reminders.php?action=send';

        $matrix_raw = $_POST['matrix'] ?? '{}';
        $matrix     = json_decode(stripslashes($matrix_raw), true);
        if (!is_array($matrix)) $matrix = [];

        $resp = wp_remote_post($url, [
            'timeout'   => 60,
            'sslverify' => false,
            'headers'   => [
                'Content-Type'      => 'application/json',
                'X-WP-Bypass-Token' => $bypass_token,
            ],
            'body' => json_encode(['matrix' => $matrix]),
        ]);

        if (is_wp_error($resp)) {
            wp_send_json_error('No se pudo contactar reminders.php: ' . $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        if (!$data || !isset($data['ok'])) {
            wp_send_json_error("Respuesta inesperada (HTTP {$code}): " . substr($body, 0, 300));
        }

        $sent    = $data['sent']    ?? 0;
        $errors  = $data['errors']  ?? 0;
        $preview = $data['preview'] ?? [];
        $skipped = $data['skipped'] ?? false;

        $lines = $skipped
            ? ['⚠️ Ya se enviaron recordatorios hoy. Usá force=1 desde CLI para forzar.']
            : ["✅ Enviados: {$sent} | ❌ Errores: {$errors}"];

        foreach ($preview as $p) {
            $lines[] = "  → {$p['name']} &lt;{$p['to']}&gt; — {$p['total']} tarea(s)";
        }

        wp_send_json_success(['message' => implode('<br>', $lines), 'detail' => $data]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ONBOARDING WIZARD
    // ══════════════════════════════════════════════════════════════════════════

    public function maybe_redirect_to_wizard() {
        if (!get_transient('luna_activation_redirect')) return;
        if (!current_user_can('manage_options')) return;
        delete_transient('luna_activation_redirect');
        // Solo redirigir si el wizard no fue completado todavía
        if (!get_option('luna_onboarding_done')) {
            wp_redirect(admin_url('admin.php?page=luna-onboarding'));
            exit;
        }
    }

    // AJAX: dismiss contraseña inicial (usuario confirmó que la guardó)
    public function ajax_dismiss_initial_pass() {
        check_ajax_referer('luna_dismiss_pass', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');
        delete_option('luna_initial_admin_pass');
        wp_send_json_success();
    }

    // AJAX: marcar wizard como completado
    public function ajax_wizard_done() {
        if (!check_ajax_referer('luna_admin_nonce', 'nonce', false)) wp_die();
        if (!current_user_can('manage_options')) wp_die('Sin permisos');
        update_option('luna_onboarding_done', 1);
        wp_send_json_success();
    }

    // AJAX: validar licencia desde el wizard
    public function ajax_wizard_validate_license() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $key    = sanitize_text_field($_POST['license_key'] ?? '');
        $domain = parse_url(get_site_url(), PHP_URL_HOST) ?? ($_SERVER['HTTP_HOST'] ?? '');

        if (!$key) {
            wp_send_json_error('Ingresá una clave de licencia');
        }

        $result = Luna_License::verify($key, $domain);

        if (!empty($result['valid'])) {
            update_option('luna_license_key', $key);
            Luna_Activator::regenerate_app_config();
            @unlink(LUNA_APP_DIR . 'luna-license-cache.json');
            $plan = Luna_License::plan_label($result['plan'] ?? 'unknown');
            wp_send_json_success([
                'plan'       => $plan,
                'expires_at' => $result['expires_at'] ?? '—',
            ]);
        } else {
            $msg = $result['message'] ?? 'Licencia inválida';
            wp_send_json_error($msg);
        }
    }

    // ── Wizard de onboarding ──────────────────────────────────────────────────
    public function render_onboarding_wizard() {
        if (!current_user_can('manage_options')) return;

        // Si ya completó el wizard → redirigir a configuración
        if (get_option('luna_onboarding_done') && !isset($_GET['force'])) {
            wp_redirect(admin_url('admin.php?page=luna-workspace'));
            exit;
        }

        $nonce        = wp_create_nonce('luna_admin_nonce');
        $license_key  = get_option('luna_license_key', '');
        $pass         = get_option('luna_initial_admin_pass', '');
        $entry_token  = get_option('luna_entry_token', '');
        $permanent_url = $entry_token ? add_query_arg('luna_enter', $entry_token, home_url('/')) : '';
        $has_license  = !empty($license_key);

        // Marcar wizard como completado si se llega al paso 3
        if (isset($_GET['step']) && (int)$_GET['step'] === 3) {
            update_option('luna_onboarding_done', 1);
        }
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Luna Workspace — Configuración inicial</title>
            <?php wp_head(); ?>
            <style>
                *{box-sizing:border-box;margin:0;padding:0}
                body{background:#f0f2ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
                .wz-wrap{width:100%;max-width:560px}
                .wz-logo{text-align:center;margin-bottom:28px}
                .wz-logo span{font-size:40px}
                .wz-logo h1{font-size:22px;font-weight:800;color:#1e1e3f;margin-top:8px}
                .wz-logo p{color:#6b7280;font-size:14px;margin-top:4px}
                /* Progress steps */
                .wz-steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:32px}
                .wz-step{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;position:relative}
                .wz-step-num{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;border:2px solid #d1d5db;background:#fff;color:#9ca3af;transition:all .3s;z-index:1}
                .wz-step-label{font-size:11px;color:#9ca3af;font-weight:600;white-space:nowrap}
                .wz-step.active .wz-step-num{background:#5b6af0;border-color:#5b6af0;color:#fff}
                .wz-step.active .wz-step-label{color:#5b6af0}
                .wz-step.done .wz-step-num{background:#22c55e;border-color:#22c55e;color:#fff}
                .wz-step.done .wz-step-label{color:#22c55e}
                .wz-step:not(:last-child)::after{content:'';position:absolute;top:18px;left:calc(50% + 18px);right:calc(-50% + 18px);height:2px;background:#d1d5db}
                .wz-step.done:not(:last-child)::after{background:#22c55e}
                /* Card */
                .wz-card{background:#fff;border-radius:16px;padding:36px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
                .wz-card h2{font-size:20px;font-weight:800;color:#1e1e3f;margin-bottom:8px}
                .wz-card p.sub{color:#6b7280;font-size:14px;margin-bottom:24px;line-height:1.6}
                /* License input */
                .wz-input{width:100%;padding:14px 16px;border:2px solid #e5e7eb;border-radius:10px;font-size:15px;font-family:monospace;letter-spacing:2px;outline:none;transition:border .2s}
                .wz-input:focus{border-color:#5b6af0}
                .wz-input.err{border-color:#ef4444}
                .wz-input.ok{border-color:#22c55e}
                /* Buttons */
                .wz-btn{width:100%;padding:14px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;border:none;transition:all .2s;margin-top:12px}
                .wz-btn-primary{background:#5b6af0;color:#fff}
                .wz-btn-primary:hover{background:#4a58d4}
                .wz-btn-primary:disabled{background:#a5b4fc;cursor:not-allowed}
                .wz-btn-secondary{background:#f3f4f6;color:#374151;border:2px solid #e5e7eb}
                .wz-btn-secondary:hover{background:#e9eaf0}
                .wz-btn-green{background:#22c55e;color:#fff}
                .wz-btn-green:hover{background:#16a34a}
                /* Msg */
                .wz-msg{padding:12px 16px;border-radius:8px;font-size:13px;margin-top:12px;display:none;line-height:1.5}
                .wz-msg.err{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}
                .wz-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #86efac}
                /* Password box */
                .wz-pass-box{background:#fefce8;border:2px solid #fde047;border-radius:12px;padding:20px;margin:20px 0}
                .wz-pass-box label{font-size:12px;font-weight:700;color:#854d0e;display:block;margin-bottom:8px}
                .wz-pass-row{display:flex;align-items:center;gap:10px}
                .wz-pass-val{font-size:20px;font-family:monospace;letter-spacing:3px;background:#fff;padding:10px 16px;border-radius:8px;border:1px solid #fde047;color:#1e1e3f;flex:1;word-break:break-all}
                .wz-copy-btn{background:#854d0e;color:#fff;border:none;border-radius:8px;padding:10px 16px;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap}
                .wz-copy-btn:hover{background:#92400e}
                /* Success icon */
                .wz-success-icon{text-align:center;font-size:64px;margin-bottom:16px}
                .wz-skip{text-align:center;margin-top:16px}
                .wz-skip a{font-size:12px;color:#9ca3af;text-decoration:none}
                .wz-skip a:hover{color:#6b7280}
            </style>
        </head>
        <body>
        <div class="wz-wrap">

            <!-- Logo -->
            <div class="wz-logo">
                <span>🌙</span>
                <h1>Luna Workspace</h1>
                <p>Configuración inicial — solo toma 2 minutos</p>
            </div>

            <!-- Steps indicator -->
            <div class="wz-steps" id="wz-steps">
                <div class="wz-step active" id="step-ind-1">
                    <div class="wz-step-num">1</div>
                    <div class="wz-step-label">Licencia</div>
                </div>
                <div class="wz-step" id="step-ind-2">
                    <div class="wz-step-num">2</div>
                    <div class="wz-step-label">Credenciales</div>
                </div>
                <div class="wz-step" id="step-ind-3">
                    <div class="wz-step-num">3</div>
                    <div class="wz-step-label">¡Listo!</div>
                </div>
            </div>

            <!-- Paso 1: Licencia -->
            <div class="wz-card" id="wz-step-1">
                <h2>🔑 Activá tu licencia</h2>
                <p class="sub">Ingresá la clave que recibiste al registrarte. La verificación es automática y solo tarda unos segundos.</p>
                <input type="text" id="wz-license-input" class="wz-input"
                       placeholder="LUNA-XXXX-XXXX-XXXX-XXXX"
                       value="<?php echo esc_attr($license_key) ?>"
                       spellcheck="false" autocomplete="off">
                <div class="wz-msg" id="wz-license-msg"></div>
                <button class="wz-btn wz-btn-primary" id="wz-btn-license">Validar licencia →</button>
                <div class="wz-skip">
                    <a href="#" id="wz-skip-license">Continuar sin licencia (modo limitado)</a>
                </div>
            </div>

            <!-- Paso 2: Credenciales -->
            <div class="wz-card" id="wz-step-2" style="display:none">
                <h2>🔐 Tus credenciales de acceso</h2>
                <p class="sub">Estas son las credenciales para ingresar a Luna Workspace. Guardalas en un lugar seguro — también las vas a encontrar siempre en <strong>Luna Workspace → Configuración</strong>.</p>

                <div style="background:#f8f9fa;border-radius:10px;padding:16px;margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e9ecef;font-size:14px">
                        <span style="color:#6b7280;font-weight:600">Usuario</span>
                        <strong style="font-family:monospace">admin</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:14px">
                        <span style="color:#6b7280;font-weight:600">Contraseña</span>
                        <span style="display:flex;align-items:center;gap:8px">
                            <strong id="wz-pass-display" style="font-family:monospace;font-size:16px;letter-spacing:2px">
                                <?php echo esc_html($pass ?: '(generá una desde Configuración)') ?>
                            </strong>
                        </span>
                    </div>
                </div>

                <?php if ($pass): ?>
                <button onclick="navigator.clipboard.writeText('<?php echo esc_js($pass) ?>');this.textContent='✓ ¡Copiada!';this.style.background='#16a34a';setTimeout(()=>{this.textContent='📋 Copiar contraseña';this.style.background=''},2000)"
                        style="width:100%;padding:12px;background:#5b6af0;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;margin-bottom:12px">
                    📋 Copiar contraseña
                </button>
                <?php endif; ?>

                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;font-size:12px;color:#1e40af;line-height:1.6;margin-bottom:16px">
                    💡 <strong>Tip:</strong> Una vez adentro de Luna, andá a tu perfil y cambiá la contraseña por una que recuerdes fácilmente.
                </div>

                <button class="wz-btn wz-btn-primary" id="wz-btn-credentials">Continuar →</button>
            </div>

            <!-- Paso 3: Listo -->
            <div class="wz-card" id="wz-step-3" style="display:none">
                <div class="wz-success-icon">🎉</div>
                <h2 style="text-align:center;margin-bottom:12px">¡Luna está lista!</h2>
                <p class="sub" style="text-align:center">Todo está configurado. Hacé clic en el botón para ingresar a tu nuevo espacio de trabajo.</p>

                <?php if ($permanent_url): ?>
                <a href="<?php echo esc_url($permanent_url) ?>"
                   style="display:block;width:100%;padding:16px;background:linear-gradient(135deg,#5b6af0,#7c3aed);color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:800;cursor:pointer;text-align:center;text-decoration:none;margin-bottom:12px">
                    🚀 Entrar a Luna Workspace →
                </a>
                <?php endif; ?>

                <a href="<?php echo admin_url('admin.php?page=luna-workspace') ?>"
                   style="display:block;width:100%;padding:12px;background:#f3f4f6;color:#374151;border:2px solid #e5e7eb;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none">
                    ⚙️ Ir a Configuración
                </a>
            </div>

        </div>

        <script>
        (function(){
            const nonce = '<?php echo esc_js($nonce) ?>';
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')) ?>';
            let currentStep = <?php echo $has_license ? 2 : 1 ?>;

            function goToStep(n) {
                currentStep = n;
                [1,2,3].forEach(i => {
                    document.getElementById('wz-step-' + i).style.display = i === n ? '' : 'none';
                    const ind = document.getElementById('step-ind-' + i);
                    ind.className = 'wz-step' + (i < n ? ' done' : i === n ? ' active' : '');
                    if (i < n) ind.querySelector('.wz-step-num').textContent = '✓';
                    else if (i >= n) ind.querySelector('.wz-step-num').textContent = i;
                });
                // Marcar wizard como completado al llegar al paso 3
                if (n === 3) {
                    fetch(ajaxUrl + '?action=luna_wizard_done&nonce=' + nonce);
                }
            }

            // Si ya tiene licencia, ir directo al paso 2
            if (currentStep === 2) goToStep(2);

            // ── Paso 1: Validar licencia ──────────────────────────────────────
            function showMsg(id, text, type) {
                const el = document.getElementById(id);
                el.textContent = text;
                el.className = 'wz-msg ' + type;
                el.style.display = 'block';
            }

            document.getElementById('wz-btn-license').addEventListener('click', function() {
                const key = document.getElementById('wz-license-input').value.trim();
                if (!key) { showMsg('wz-license-msg', 'Ingresá tu clave de licencia', 'err'); return; }

                this.disabled = true;
                this.textContent = '⏳ Validando...';
                document.getElementById('wz-license-input').className = 'wz-input';

                const fd = new FormData();
                fd.append('action', 'luna_wizard_validate_license');
                fd.append('nonce', nonce);
                fd.append('license_key', key);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => {
                        if (r.success) {
                            document.getElementById('wz-license-input').className = 'wz-input ok';
                            showMsg('wz-license-msg', '✅ Licencia activada — Plan: ' + r.data.plan + ' (vence: ' + r.data.expires_at + ')', 'ok');
                            setTimeout(() => goToStep(2), 1200);
                        } else {
                            document.getElementById('wz-license-input').className = 'wz-input err';
                            showMsg('wz-license-msg', '❌ ' + r.data, 'err');
                        }
                    })
                    .catch(() => showMsg('wz-license-msg', '❌ Error de conexión', 'err'))
                    .finally(() => {
                        this.disabled = false;
                        this.textContent = 'Validar licencia →';
                    });
            });

            document.getElementById('wz-skip-license').addEventListener('click', function(e) {
                e.preventDefault();
                goToStep(2);
            });

            // ── Paso 2: Credenciales ──────────────────────────────────────────
            document.getElementById('wz-btn-credentials').addEventListener('click', function() {
                goToStep(3);
            });
        })();
        </script>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  BACKUP & RESTORE
    // ══════════════════════════════════════════════════════════════════════════

    private function backup_dir() {
        $dir = plugin_dir_path(__FILE__) . '../app/backups/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Bloquear acceso web directo
        $ht = $dir . '.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, "Options -Indexes\nDeny from all\n");
        }
        return $dir;
    }

    private function luna_tables() {
        return [
            // Settings y templates primero (sin dependencias)
            'app_settings', 'workspace_templates',
            // Entidades base
            'users', 'workspaces', 'workspace_members', 'user_meta',
            // Kanban
            'columns_k', 'cards',
            // Relaciones de tarjetas
            'card_tags', 'card_assignees', 'card_checklist', 'card_dependencies',
            // Contenido
            'attachments', 'notifications',
            // Extras
            'workspace_labels', 'activity_log',
            // Facturación / cobranzas
            'clients', 'payments', 'card_payments',
        ];
    }

    // ── Motivos de pago frecuentes (lista editable sin pantalla de ABM) ────────
    private function luna_payment_reasons_defaults() {
        return ['Renovación anual dominio', 'Rediseño web', 'Abono publicidad'];
    }

    private function luna_get_payment_reasons() {
        $saved = get_option('luna_payment_reasons', []);
        if (!is_array($saved)) $saved = [];
        return array_values(array_unique(array_merge($this->luna_payment_reasons_defaults(), $saved)));
    }

    private function luna_remember_payment_reason($concept) {
        $concept = trim($concept);
        if ($concept === '') return;
        $saved = get_option('luna_payment_reasons', []);
        if (!is_array($saved)) $saved = [];
        foreach (array_merge($this->luna_payment_reasons_defaults(), $saved) as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($concept)) return;
        }
        $saved[] = $concept;
        if (count($saved) > 40) $saved = array_slice($saved, -40);
        update_option('luna_payment_reasons', $saved);
    }

    // ── Crear backup ──────────────────────────────────────────────────────────
    public function ajax_backup_create() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        global $wpdb;
        $p = $wpdb->prefix . 'luna_';

        $backup = [
            'luna_backup'    => true,
            'schema_version' => '1.0',
            'plugin_version' => LUNA_VERSION,
            'created_at'     => (new DateTime('now', wp_timezone()))->format('c'),
            'wp_prefix'      => $wpdb->prefix,
            'tables'         => [],
            'counts'         => [],
        ];

        foreach ($this->luna_tables() as $table) {
            $full = $p . $table;
            if (!$wpdb->get_var("SHOW TABLES LIKE '{$full}'")) continue;
            $rows = $wpdb->get_results("SELECT * FROM `{$full}`", ARRAY_A) ?: [];
            // Excluir rate limiting de app_settings (efímero, no sirve en un restore)
            if ($table === 'app_settings') {
                $rows = array_values(array_filter($rows, fn($r) => strpos($r['meta_key'] ?? '', 'rate_login_') !== 0));
            }
            $backup['tables'][$table] = $rows;
            $backup['counts'][$table] = count($rows);
        }

        $dir      = $this->backup_dir();
        $filename = 'luna-backup-' . date('Ymd-His') . '.json';
        $filepath = $dir . $filename;
        $json     = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (file_put_contents($filepath, $json) === false) {
            wp_send_json_error('No se pudo escribir el archivo. Verificá permisos en: ' . $dir);
        }

        // Mantener solo los últimos 10 backups
        $files = glob($dir . 'luna-backup-*.json') ?: [];
        if (count($files) > 10) {
            sort($files);
            foreach (array_slice($files, 0, count($files) - 10) as $old) @unlink($old);
        }

        wp_send_json_success([
            'filename' => $filename,
            'size'     => size_format(strlen($json)),
            'total'    => array_sum($backup['counts']),
            'counts'   => $backup['counts'],
        ]);
    }

    // ── Listar backups ────────────────────────────────────────────────────────
    public function ajax_backup_list() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $dir   = $this->backup_dir();
        $files = glob($dir . 'luna-backup-*.json') ?: [];
        rsort($files);

        $list = [];
        foreach ($files as $f) {
            $name  = basename($f);
            $mtime = filemtime($f);
            // Leer solo los primeros 8KB para obtener counts sin cargar todo el archivo
            $head   = file_get_contents($f, false, null, 0, 8192);
            $meta   = json_decode($head, true);
            $counts = $meta['counts'] ?? [];
            $list[] = [
                'filename' => $name,
                'size'     => size_format(filesize($f)),
                'date'     => date_i18n('d/m/Y H:i:s', $mtime),
                'total'    => array_sum($counts),
                'counts'   => $counts,
            ];
        }

        wp_send_json_success(['backups' => $list]);
    }

    // ── Descargar backup ──────────────────────────────────────────────────────
    public function ajax_backup_download() {
        if (!check_ajax_referer('luna_admin_nonce', 'nonce', false)) wp_die('Nonce inválido');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $filename = sanitize_file_name($_GET['filename'] ?? '');
        if (!preg_match('/^luna-backup-\d{8}-\d{6}\.json$/', $filename)) wp_die('Archivo inválido');

        $filepath = $this->backup_dir() . $filename;
        if (!file_exists($filepath)) wp_die('Archivo no encontrado');

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($filepath);
        exit;
    }

    // ── Eliminar backup ───────────────────────────────────────────────────────
    public function ajax_backup_delete() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        if (!preg_match('/^luna-backup-\d{8}-\d{6}\.json$/', $filename)) {
            wp_send_json_error('Nombre de archivo inválido');
        }

        $filepath = $this->backup_dir() . $filename;
        if (!file_exists($filepath)) wp_send_json_error('Archivo no encontrado');
        if (!unlink($filepath)) wp_send_json_error('No se pudo eliminar el archivo');

        wp_send_json_success(['deleted' => $filename]);
    }

    // ── Restaurar backup ──────────────────────────────────────────────────────
    public function ajax_backup_restore() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('No se recibió ningún archivo válido');
        }

        $json = file_get_contents($_FILES['backup_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!$data || empty($data['luna_backup']) || !isset($data['tables'])) {
            wp_send_json_error('Archivo inválido — no es un backup de Luna Workspace');
        }

        global $wpdb;
        $p = $wpdb->prefix . 'luna_';

        // Crear backup automático antes de restaurar (seguro)
        $this->ajax_backup_create_silent();

        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $restored = [];

        foreach ($this->luna_tables() as $table) {
            if (!isset($data['tables'][$table])) continue;
            $full = $p . $table;
            if (!$wpdb->get_var("SHOW TABLES LIKE '{$full}'")) continue;

            $wpdb->query("TRUNCATE TABLE `{$full}`");
            $count = 0;
            foreach ($data['tables'][$table] as $row) {
                if ($wpdb->insert($full, $row) !== false) $count++;
            }
            $restored[$table] = $count;
        }

        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

        // Regenerar luna-wp-config.php por si cambió algo
        Luna_Activator::regenerate_app_config();

        wp_send_json_success([
            'message'  => 'Restore completado exitosamente',
            'restored' => $restored,
            'total'    => array_sum($restored),
        ]);
    }

    // Crear backup silencioso (sin respuesta JSON) — usado internamente antes de restore
    private function ajax_backup_create_silent() {
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        $backup = [
            'luna_backup' => true, 'schema_version' => '1.0',
            'plugin_version' => LUNA_VERSION, 'created_at' => (new DateTime('now', wp_timezone()))->format('c'),
            'wp_prefix' => $wpdb->prefix, 'tables' => [], 'counts' => [],
        ];
        foreach ($this->luna_tables() as $table) {
            $full = $p . $table;
            if (!$wpdb->get_var("SHOW TABLES LIKE '{$full}'")) continue;
            $rows = $wpdb->get_results("SELECT * FROM `{$full}`", ARRAY_A) ?: [];
            $backup['tables'][$table] = $rows;
            $backup['counts'][$table] = count($rows);
        }
        $dir      = $this->backup_dir();
        $filename = 'luna-backup-' . date('Ymd-His') . '-pre-restore.json';
        file_put_contents($dir . $filename, json_encode($backup, JSON_UNESCAPED_UNICODE));
    }

    // ── Página Backup & Restore ───────────────────────────────────────────────
    public function render_backup_page() {
        if (!current_user_can('manage_options')) return;
        $nonce = wp_create_nonce('luna_admin_nonce');
        ?>
        <div class="wrap luna-admin-wrap">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
                <span style="font-size:24px">🗄️</span> Backup &amp; Restore
            </h1>
            <p style="color:#666;margin-bottom:24px;font-size:13px">
                Exporta e importa todos los datos de Luna Workspace. Los archivos adjuntos físicos (imágenes, PDFs) no se incluyen en el backup — solo sus metadatos (nombre, URL). Se guardan hasta 10 backups en el servidor.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">

                <!-- Crear backup -->
                <div class="luna-card">
                    <h2 style="margin-top:0;font-size:15px;display:flex;align-items:center;gap:8px">
                        <span>📦</span> Crear Backup
                    </h2>
                    <p style="color:#555;font-size:13px;margin-bottom:16px">
                        Genera un archivo JSON con todas las tablas de Luna: tareas, usuarios, columnas, etiquetas, configuraciones y más.
                    </p>
                    <button id="luna-btn-backup" class="button button-primary" style="font-size:13px;padding:6px 18px">
                        ⬇️ Descargar backup completo
                    </button>
                    <div id="luna-backup-result" style="margin-top:12px;display:none"></div>
                </div>

                <!-- Restaurar -->
                <div class="luna-card">
                    <h2 style="margin-top:0;font-size:15px;display:flex;align-items:center;gap:8px">
                        <span>♻️</span> Restaurar
                    </h2>
                    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#856404;line-height:1.5">
                        ⚠️ <strong>Atención:</strong> Restaurar reemplaza TODOS los datos actuales de Luna con los del archivo. Antes de restaurar se crea un backup automático de seguridad.
                    </div>
                    <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Seleccioná un archivo .json de backup:</label>
                    <input type="file" id="luna-restore-file" accept=".json" style="margin-bottom:12px;display:block;font-size:13px">
                    <button id="luna-btn-restore" class="button" style="font-size:13px;padding:6px 18px;border-color:#dc3545;color:#dc3545" disabled>
                        ♻️ Restaurar desde archivo
                    </button>
                    <div id="luna-restore-result" style="margin-top:12px;display:none"></div>
                </div>

            </div>

            <!-- Lista de backups -->
            <div class="luna-card" style="margin-top:24px">
                <h2 style="margin-top:0;font-size:15px;display:flex;align-items:center;justify-content:space-between">
                    <span>📋 Backups guardados en el servidor</span>
                    <button id="luna-btn-refresh-list" class="button" style="font-size:11px">↻ Actualizar</button>
                </h2>
                <div id="luna-backup-list"><p style="color:#999;font-size:13px">Cargando...</p></div>
            </div>
        </div>

        <script>
        jQuery(function($){
            const nonce   = '<?php echo esc_js($nonce); ?>';
            const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            function showMsg(selector, html, type) {
                const bg = { ok:'#d4edda', err:'#f8d7da', info:'#cce5ff' };
                $(selector).html('<div style="padding:10px 14px;border-radius:6px;background:' + (bg[type]||bg.info) + ';font-size:13px;line-height:1.6">' + html + '</div>').show();
            }

            // ── Crear backup ──────────────────────────────────────────────────
            $('#luna-btn-backup').on('click', function() {
                const $btn = $(this).prop('disabled', true).text('⏳ Generando...');
                $.post(ajaxUrl, { action: 'luna_backup_create', nonce }, function(r) {
                    if (r.success) {
                        const d = r.data;
                        const dlUrl = ajaxUrl + '?action=luna_backup_download&nonce=' + nonce + '&filename=' + encodeURIComponent(d.filename);
                        showMsg('#luna-backup-result',
                            '✅ <strong>' + d.filename + '</strong> creado (' + d.size + ' — ' + d.total + ' registros)<br>' +
                            '<a href="' + dlUrl + '" style="color:#0d6efd;font-weight:600">⬇️ Descargar ahora</a>',
                            'ok');
                        loadList();
                    } else {
                        showMsg('#luna-backup-result', '❌ ' + r.data, 'err');
                    }
                }).fail(() => showMsg('#luna-backup-result', '❌ Error de conexión', 'err'))
                  .always(() => $btn.prop('disabled', false).text('⬇️ Descargar backup completo'));
            });

            // ── Restaurar ─────────────────────────────────────────────────────
            $('#luna-restore-file').on('change', function() {
                $('#luna-btn-restore').prop('disabled', !this.files.length);
            });

            $('#luna-btn-restore').on('click', function() {
                if (!confirm('⚠️ Esta acción reemplazará TODOS los datos de Luna con el archivo seleccionado.\n\nAntes de continuar se creará un backup automático de seguridad.\n\n¿Confirmas?')) return;
                const $btn = $(this).prop('disabled', true).text('⏳ Restaurando...');
                const fd = new FormData();
                fd.append('action', 'luna_backup_restore');
                fd.append('nonce', nonce);
                fd.append('backup_file', $('#luna-restore-file')[0].files[0]);
                $.ajax({
                    url: ajaxUrl, type: 'POST', data: fd,
                    contentType: false, processData: false,
                    success(r) {
                        if (r.success) {
                            const rows = Object.entries(r.data.restored)
                                .filter(([,v]) => v > 0)
                                .map(([k,v]) => k + ': ' + v).join(' · ');
                            showMsg('#luna-restore-result',
                                '✅ <strong>' + r.data.message + '</strong> — ' + r.data.total + ' registros restaurados.<br><small style="color:#555">' + rows + '</small>',
                                'ok');
                            loadList();
                        } else {
                            showMsg('#luna-restore-result', '❌ ' + r.data, 'err');
                        }
                    },
                    error() { showMsg('#luna-restore-result', '❌ Error de conexión', 'err'); },
                    complete() { $btn.prop('disabled', false).text('♻️ Restaurar desde archivo'); }
                });
            });

            // ── Lista de backups ──────────────────────────────────────────────
            function loadList() {
                $.post(ajaxUrl, { action: 'luna_backup_list', nonce }, function(r) {
                    if (!r.success || !r.data.backups.length) {
                        $('#luna-backup-list').html('<p style="color:#999;font-size:13px">No hay backups guardados aún. Creá el primero con el botón de arriba.</p>');
                        return;
                    }
                    let html = '<table style="width:100%;border-collapse:collapse;font-size:13px">'
                        + '<thead><tr style="border-bottom:2px solid #eee;text-align:left">'
                        + '<th style="padding:8px 10px">Fecha</th>'
                        + '<th style="padding:8px 10px">Archivo</th>'
                        + '<th style="padding:8px 10px;text-align:right">Tamaño</th>'
                        + '<th style="padding:8px 10px;text-align:right">Registros</th>'
                        + '<th style="padding:8px 10px;text-align:right">Acciones</th>'
                        + '</tr></thead><tbody>';
                    r.data.backups.forEach(function(b) {
                        const dlUrl = ajaxUrl + '?action=luna_backup_download&nonce=' + nonce + '&filename=' + encodeURIComponent(b.filename);
                        const isPre = b.filename.includes('pre-restore');
                        html += '<tr style="border-bottom:1px solid #f3f3f3' + (isPre ? ';background:#fffbf0' : '') + '">'
                            + '<td style="padding:8px 10px">' + b.date + (isPre ? ' <span style="font-size:10px;color:#856404;background:#fff3cd;padding:1px 6px;border-radius:4px">pre-restore</span>' : '') + '</td>'
                            + '<td style="padding:8px 10px;font-family:monospace;font-size:11px;color:#555">' + b.filename + '</td>'
                            + '<td style="padding:8px 10px;text-align:right">' + b.size + '</td>'
                            + '<td style="padding:8px 10px;text-align:right">' + b.total + '</td>'
                            + '<td style="padding:8px 10px;text-align:right;white-space:nowrap">'
                            + '<a href="' + dlUrl + '" class="button button-small" style="margin-right:6px">⬇️ Descargar</a>'
                            + '<button class="button button-small luna-del-backup" data-file="' + b.filename + '" style="color:#dc3545;border-color:#dc3545">🗑️</button>'
                            + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    $('#luna-backup-list').html(html);
                }).fail(() => $('#luna-backup-list').html('<p style="color:#c00;font-size:13px">Error al cargar la lista de backups.</p>'));
            }

            $(document).on('click', '.luna-del-backup', function() {
                const filename = $(this).data('file');
                if (!confirm('¿Eliminar el backup "' + filename + '"?\nEsta acción no se puede deshacer.')) return;
                $.post(ajaxUrl, { action: 'luna_backup_delete', nonce, filename }, function(r) {
                    if (r.success) loadList();
                    else alert('Error: ' + r.data);
                });
            });

            $('#luna-btn-refresh-list').on('click', loadList);
            loadList();
        });
        </script>
        <?php
    }

    // ════════════════════════════════════════════════════════════════════════
    // CLIENTES — ABM, pagos y presupuesto imprimible
    // ════════════════════════════════════════════════════════════════════════

    public function render_clients_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        // Pizarras disponibles para vincular
        $workspaces = $wpdb->get_results("SELECT id, name FROM `{$p}workspaces` ORDER BY name", ARRAY_A) ?: [];
        // Motivos de pago frecuentes (se amplía solo al escribir uno nuevo)
        $payment_reasons = $this->luna_get_payment_reasons();
        // Datos del prestador para el encabezado del presupuesto
        $provider_name  = get_option('luna_provider_name',  get_bloginfo('name'));
        $provider_cuit  = get_option('luna_provider_cuit',  '');
        $provider_email = get_option('luna_provider_email', get_option('admin_email'));
        $provider_phone = get_option('luna_provider_phone', '');
        ?>
        <div class="wrap" style="max-width:1100px">
        <style>
        .lc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
        .lc-title{font-size:1.4rem;font-weight:700;color:#1e1e1e}
        .lc-btn{display:inline-flex;align-items:center;gap:6px;background:#5b6af0;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
        .lc-btn:hover{background:#4a59d0;color:#fff}
        .lc-btn-sm{padding:5px 11px;font-size:12px;border-radius:5px}
        .lc-btn-ghost{background:transparent;color:#5b6af0;border:1.5px solid #5b6af0}
        .lc-btn-ghost:hover{background:#5b6af0;color:#fff}
        .lc-btn-danger{background:#ef4444}
        .lc-btn-danger:hover{background:#dc2626}
        .lc-btn-green{background:#22c55e}
        .lc-btn-green:hover{background:#16a34a}
        .lc-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
        .lc-table th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0}
        .lc-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
        .lc-table tr:last-child td{border-bottom:none}
        .lc-table tr:hover td{background:#fafbff}
        .lc-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700}
        .lc-badge-paid{background:#dcfce7;color:#16a34a}
        .lc-badge-pending{background:#fef9c3;color:#a16207}
        .lc-badge-partial{background:#dbeafe;color:#1d4ed8}
        .lc-badge-ri{background:#ede9fe;color:#6d28d9}
        .lc-badge-mono{background:#fce7f3;color:#9d174d}
        .lc-badge-cf{background:#f1f5f9;color:#475569}
        .lc-badge-ex{background:#f0fdf4;color:#166534}
        .lc-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;margin-top:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
        .lc-panel-title{font-size:1rem;font-weight:700;color:#1e1e1e;margin-bottom:16px;display:flex;align-items:center;gap:8px}
        .lc-empty{text-align:center;color:#94a3b8;padding:32px;font-size:13px}
        /* Modal */
        .lc-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center}
        .lc-overlay.open{display:flex}
        .lc-modal{background:#fff;border-radius:12px;padding:32px;width:100%;max-width:520px;position:relative;max-height:90vh;overflow-y:auto}
        .lc-modal h3{font-size:1.1rem;font-weight:700;margin-bottom:20px;color:#1e1e1e}
        .lc-close{position:absolute;top:14px;right:18px;background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1}
        .lc-close:hover{color:#334155}
        .lc-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .lc-fg{margin-bottom:14px}
        .lc-fg label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
        .lc-fg input,.lc-fg select,.lc-fg textarea{width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;font-size:13px;color:#1e1e1e;outline:none;font-family:inherit}
        .lc-fg input:focus,.lc-fg select:focus,.lc-fg textarea:focus{border-color:#5b6af0;box-shadow:0 0 0 2px rgba(91,106,240,.15)}
        .lc-suggest-box{position:absolute;left:0;right:0;z-index:200001;background:#fff;border:1px solid #cbd5e1;border-radius:8px;margin-top:2px;max-height:190px;overflow-y:auto;box-shadow:0 6px 16px rgba(0,0,0,.12)}
        .lc-suggest-item{padding:8px 12px;font-size:13px;color:#1e1e1e;cursor:pointer}
        .lc-suggest-item:hover,.lc-suggest-item.active{background:#f1f5f9}
        .lc-suggest-empty{padding:8px 12px;font-size:12px;color:#94a3b8}
        .lc-fg textarea{resize:vertical;min-height:72px}
        .lc-submit{width:100%;background:#5b6af0;color:#fff;border:none;border-radius:8px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;margin-top:6px}
        .lc-submit:hover{background:#4a59d0}
        .lc-msg{margin-top:10px;font-size:12px;text-align:center;min-height:18px}
        .lc-msg.ok{color:#16a34a} .lc-msg.err{color:#ef4444}
        /* Provider settings */
        .lc-settings-bar{background:#fafafa;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:12px}
        .lc-settings-bar label{font-weight:600;color:#475569;display:block;margin-bottom:3px}
        .lc-settings-bar input{border:1px solid #cbd5e1;border-radius:5px;padding:5px 8px;font-size:12px;width:180px}
        .lc-settings-bar .lc-btn-sm{align-self:flex-end}
        .lc-section-label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin:0 0 6px}
        /* Print presupuesto */
        @media print{.no-print{display:none!important}}
        </style>

        <div class="lc-header">
            <div class="lc-title">👥 Clientes</div>
            <div style="display:flex;gap:8px">
                <button class="lc-btn lc-btn-ghost" id="lc-btn-informe" style="font-size:12px">📊 Informe</button>
                <button class="lc-btn lc-btn-ghost" id="lc-btn-import-csv" style="font-size:12px">📥 Importar CSV</button>
                <input type="file" id="lc-csv-file" accept=".csv" style="display:none">
                <button class="lc-btn lc-btn-green" id="lc-btn-new-invoice">🧾 Nueva factura</button>
                <button class="lc-btn" id="lc-btn-new-client">+ Nuevo cliente</button>
            </div>
        </div>
        <div id="lc-import-msg" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:13px"></div>

        <!-- Datos del prestador (para el encabezado del presupuesto) -->
        <details class="lc-settings-bar no-print" style="display:block">
            <summary style="font-weight:700;font-size:12px;color:#5b6af0;cursor:pointer;margin-bottom:8px">⚙️ Datos de tu empresa (aparecen en el presupuesto)</summary>
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:10px">
                <div><label>Nombre / Razón social</label><input type="text" id="lc-prov-name" value="<?php echo esc_attr($provider_name) ?>"></div>
                <div><label>CUIT</label><input type="text" id="lc-prov-cuit" value="<?php echo esc_attr($provider_cuit) ?>" style="width:140px"></div>
                <div><label>Email</label><input type="text" id="lc-prov-email" value="<?php echo esc_attr($provider_email) ?>"></div>
                <div><label>Teléfono</label><input type="text" id="lc-prov-phone" value="<?php echo esc_attr($provider_phone) ?>" style="width:140px"></div>
                <button class="lc-btn lc-btn-sm" id="lc-save-provider">Guardar</button>
                <span id="lc-prov-msg" style="font-size:12px;color:#16a34a;align-self:flex-end"></span>
            </div>
        </details>

        <!-- Buscador + Tabla de clientes -->
        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="text" id="lc-search-client" placeholder="🔍  Buscar cliente por nombre..." style="width:100%;max-width:360px;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:13px;color:#1e1e1e;outline:none;font-family:inherit" autocomplete="off">
            <div style="display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:4px">
                <button class="lc-btn lc-btn-sm lc-view-tab active" id="lc-tab-clients" data-view="clients" style="background:#5b6af0;color:#fff">👥 Todos los clientes</button>
                <button class="lc-btn lc-btn-sm lc-view-tab" id="lc-tab-invoices" data-view="invoices" style="background:transparent;color:#475569">🧾 Facturas (cada factura por separado)</button>
            </div>
        </div>
        <div id="lc-clients-wrap">
            <p class="lc-empty">Cargando...</p>
        </div>
        <div id="lc-invoices-wrap" style="display:none">
            <p class="lc-empty">Cargando...</p>
        </div>

        <!-- Panel de pagos del cliente seleccionado -->
        <div id="lc-payments-panel" style="display:none" class="lc-panel no-print">
            <div class="lc-panel-title">
                <span id="lc-payments-title">Cuenta Corriente del cliente</span>
                <button class="lc-btn lc-btn-sm lc-btn-ghost" id="lc-btn-estado-cuenta" style="margin-left:auto">📄 Estado de Cuenta</button>
                <button class="lc-btn lc-btn-sm" id="lc-btn-new-payment" style="margin-left:8px">+ Nuevo cargo</button>
                <button class="lc-btn lc-btn-sm lc-btn-ghost" id="lc-btn-close-panel" style="margin-left:8px">✕ Cerrar</button>
            </div>
            <div id="lc-payments-wrap">
                <p class="lc-empty">Cargando...</p>
            </div>
        </div>

        <!-- Modal: cliente -->
        <div class="lc-overlay" id="lc-modal-client">
            <div class="lc-modal">
                <button class="lc-close" id="lc-close-client">×</button>
                <h3 id="lc-client-modal-title">Nuevo cliente</h3>
                <input type="hidden" id="lc-client-id" value="">
                <div class="lc-form-row">
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Nombre / Razón social *</label>
                        <input type="text" id="lc-c-name" placeholder="Empresa SA / Juan Pérez">
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Dominio web</label>
                        <input type="text" id="lc-c-domain" placeholder="ejemplo.com.ar">
                    </div>
                    <div class="lc-fg">
                        <label>CUIT</label>
                        <input type="text" id="lc-c-cuit" placeholder="20-12345678-9">
                    </div>
                    <div class="lc-fg">
                        <label>Condición IVA</label>
                        <select id="lc-c-iva">
                            <option>Consumidor Final</option>
                            <option>Responsable Inscripto</option>
                            <option>Monotributista</option>
                            <option>Exento</option>
                        </select>
                    </div>
                    <div class="lc-fg">
                        <label>Email</label>
                        <input type="email" id="lc-c-email" placeholder="cliente@email.com">
                    </div>
                    <div class="lc-fg">
                        <label>Teléfono</label>
                        <input type="text" id="lc-c-phone" placeholder="11 2345 6789">
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Dirección</label>
                        <input type="text" id="lc-c-address" placeholder="Av. Corrientes 1234">
                    </div>
                    <div class="lc-fg">
                        <label>Ciudad</label>
                        <input type="text" id="lc-c-city" placeholder="Buenos Aires">
                    </div>
                    <div class="lc-fg">
                        <label>Tipo de renovación</label>
                        <select id="lc-c-subscription">
                            <option value="none">Sin renovación periódica</option>
                            <option value="mensual">Abono mensual</option>
                            <option value="anual">Abono anual</option>
                        </select>
                    </div>
                    <div class="lc-fg" id="lc-billing-day-row" style="display:none">
                        <label>Día de cobro mensual (1-31)</label>
                        <input type="number" id="lc-c-billing-day" min="1" max="31" placeholder="Ej: 1">
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Notas internas</label>
                        <textarea id="lc-c-notes" placeholder="Observaciones..."></textarea>
                    </div>
                </div>
                <button class="lc-submit" id="lc-submit-client">Guardar cliente</button>
                <p class="lc-msg" id="lc-client-msg"></p>
            </div>
        </div>

        <!-- Modal: pago -->
        <div class="lc-overlay" id="lc-modal-payment">
            <div class="lc-modal">
                <button class="lc-close" id="lc-close-payment">×</button>
                <h3 id="lc-payment-modal-title">Nuevo cargo</h3>
                <input type="hidden" id="lc-payment-id" value="">
                <input type="hidden" id="lc-payment-client-id" value="">
                <div class="lc-form-row">
                    <div class="lc-fg" style="grid-column:1/-1;position:relative" id="lc-p-client-row">
                        <label>Cliente *</label>
                        <input type="text" id="lc-p-client-search" autocomplete="off" placeholder="Escribí para buscar un cliente...">
                        <div class="lc-suggest-box" id="lc-p-client-suggest" style="display:none"></div>
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Tipo de movimiento</label>
                        <div style="display:flex;gap:20px;margin-top:4px;padding:10px 14px;background:#f8fafc;border-radius:8px">
                            <label style="font-weight:normal;display:flex;align-items:center;gap:6px;cursor:pointer">
                                <input type="radio" name="lc-p-type" value="cargo" checked>
                                <span>📋 <strong>Cargo</strong> — factura / deuda</span>
                            </label>
                            <label style="font-weight:normal;display:flex;align-items:center;gap:6px;cursor:pointer">
                                <input type="radio" name="lc-p-type" value="cobro">
                                <span>💵 <strong>Cobro</strong> — pago recibido</span>
                            </label>
                        </div>
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1;position:relative">
                        <label>Concepto *</label>
                        <input type="text" id="lc-p-concept" autocomplete="off" placeholder="Escribí para buscar un motivo frecuente o ingresá uno nuevo...">
                        <div class="lc-suggest-box" id="lc-p-concept-suggest" style="display:none"></div>
                    </div>
                    <div class="lc-fg">
                        <label>Monto *</label>
                        <input type="number" id="lc-p-amount" placeholder="0.00" step="0.01" min="0">
                    </div>
                    <div class="lc-fg">
                        <label>Moneda</label>
                        <select id="lc-p-currency">
                            <option value="ARS">ARS — Pesos</option>
                            <option value="USD">USD — Dólares</option>
                        </select>
                    </div>
                    <div class="lc-fg">
                        <label>Fecha emisión</label>
                        <input type="date" id="lc-p-date">
                    </div>
                    <div class="lc-fg">
                        <label>Fecha vencimiento</label>
                        <input type="date" id="lc-p-due">
                    </div>
                    <div class="lc-fg">
                        <label>Medio de pago</label>
                        <select id="lc-p-method">
                            <option>Transferencia</option>
                            <option>Mercado Pago</option>
                        </select>
                    </div>
                    <div class="lc-fg">
                        <label>Estado</label>
                        <select id="lc-p-status">
                            <option value="pending">Pendiente</option>
                            <option value="partial">Parcial</option>
                            <option value="paid">Pagado</option>
                        </select>
                    </div>
                    <div class="lc-fg">
                        <label>N° de presupuesto / factura</label>
                        <input type="text" id="lc-p-invoice" placeholder="0001-00000001">
                    </div>
                    <div class="lc-fg">
                        <label>Pizarra vinculada</label>
                        <select id="lc-p-workspace">
                            <option value="">— Ninguna —</option>
                            <?php foreach ($workspaces as $ws): ?>
                            <option value="<?php echo (int)$ws['id'] ?>"><?php echo esc_html($ws['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Notas</label>
                        <textarea id="lc-p-notes" placeholder="Observaciones adicionales..."></textarea>
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1" id="lc-p-installments-row">
                        <label>Forma de pago <small style="color:#94a3b8;font-weight:normal">(solo para pagos nuevos)</small></label>
                        <div style="display:flex;align-items:center;gap:16px;margin-top:4px">
                            <label style="font-weight:normal;display:flex;align-items:center;gap:4px"><input type="radio" name="lc-p-inst-type" value="1" checked> Contado</label>
                            <label style="font-weight:normal;display:flex;align-items:center;gap:4px"><input type="radio" name="lc-p-inst-type" value="n"> Cuotas</label>
                            <input type="number" id="lc-p-inst-n" min="2" max="24" value="2" style="width:70px;display:none" placeholder="N cuotas">
                        </div>
                    </div>
                </div>
                <button class="lc-submit" id="lc-submit-payment">Guardar</button>
                <p class="lc-msg" id="lc-payment-msg"></p>
            </div>
        </div>

        <!-- Modal: Registrar cobro rápido -->
        <div class="lc-overlay" id="lc-modal-cobro">
            <div class="lc-modal" style="max-width:460px">
                <button class="lc-close" id="lc-close-cobro">×</button>
                <h3>💵 Registrar cobro</h3>
                <p style="color:#64748b;font-size:13px;margin-bottom:12px">Acreditá un pago recibido del cliente. Se registra como movimiento crédito en la cuenta corriente.</p>
                <input type="hidden" id="lc-cobro-cargo-id">
                <input type="hidden" id="lc-cobro-client-id">
                <div class="lc-form-row">
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Concepto del cobro</label>
                        <input type="text" id="lc-cobro-concept" placeholder="Cobro — Renovación...">
                    </div>
                    <div class="lc-fg">
                        <label>Monto recibido *</label>
                        <input type="number" id="lc-cobro-amount" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="lc-fg">
                        <label>Moneda</label>
                        <select id="lc-cobro-currency">
                            <option value="ARS">ARS — Pesos</option>
                            <option value="USD">USD — Dólares</option>
                        </select>
                    </div>
                    <div class="lc-fg">
                        <label>Fecha de cobro</label>
                        <input type="date" id="lc-cobro-date">
                    </div>
                    <div class="lc-fg">
                        <label>Medio de pago</label>
                        <select id="lc-cobro-method">
                            <option>Transferencia</option>
                            <option>Mercado Pago</option>
                        </select>
                    </div>
                    <div class="lc-fg" style="grid-column:1/-1">
                        <label>Notas</label>
                        <input type="text" id="lc-cobro-notes" placeholder="Referencia de transferencia, comprobante...">
                    </div>
                </div>
                <button class="lc-submit" id="lc-submit-cobro">Registrar cobro</button>
                <p class="lc-msg" id="lc-cobro-msg"></p>
            </div>
        </div>

        <!-- Modal: Estado de Cuenta -->
        <div class="lc-overlay" id="lc-modal-estado">
            <div class="lc-modal" style="max-width:860px">
                <button class="lc-close" id="lc-close-estado">×</button>
                <h3>📄 Estado de Cuenta — <span id="lc-ec-client-name" style="color:#5b6af0"></span></h3>
                <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px">
                    <div class="lc-fg" style="flex:1;min-width:130px"><label>Desde</label><input type="date" id="lc-ec-from"></div>
                    <div class="lc-fg" style="flex:1;min-width:130px"><label>Hasta</label><input type="date" id="lc-ec-to"></div>
                    <button class="lc-btn" id="lc-btn-run-estado">Ver</button>
                    <button class="lc-btn lc-btn-ghost" id="lc-btn-print-estado">🖨️ Imprimir</button>
                </div>
                <div id="lc-estado-result"></div>
            </div>
        </div>

        <!-- Modal: Informe de caja -->
        <div class="lc-overlay" id="lc-modal-informe">
            <div class="lc-modal" style="max-width:820px">
                <button class="lc-close" id="lc-close-informe">×</button>
                <h3>📊 Informe de Caja</h3>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
                    <div class="lc-fg" style="flex:1;min-width:130px">
                        <label>Desde</label>
                        <input type="date" id="lc-inf-from">
                    </div>
                    <div class="lc-fg" style="flex:1;min-width:130px">
                        <label>Hasta</label>
                        <input type="date" id="lc-inf-to">
                    </div>
                    <div class="lc-fg" style="flex:2;min-width:180px">
                        <label>Cliente</label>
                        <select id="lc-inf-client"><option value="">— Todos —</option></select>
                    </div>
                    <button class="lc-btn" id="lc-btn-run-informe" style="margin-bottom:2px">Ver informe</button>
                </div>
                <div id="lc-informe-result"></div>
            </div>
        </div>

        <!-- Modal: Recordatorio email al cliente -->
        <div class="lc-overlay" id="lc-modal-email-reminder">
            <div class="lc-modal" style="max-width:500px">
                <button class="lc-close" id="lc-close-email-reminder">×</button>
                <h3>✉️ Recordatorio de pago</h3>
                <p style="color:#64748b;font-size:13px;margin-bottom:4px">Para: <strong id="lc-reminder-client-name"></strong> — <span id="lc-reminder-client-email" style="color:#5b6af0"></span></p>
                <div class="lc-fg" style="margin-top:12px">
                    <label>Mensaje a enviar</label>
                    <textarea id="lc-reminder-body" rows="7" style="width:100%;font-size:13px;line-height:1.6;padding:10px;border:1px solid #cbd5e1;border-radius:8px;resize:vertical;font-family:inherit"></textarea>
                </div>
                <button class="lc-submit" id="lc-submit-email-reminder" style="margin-top:12px">✉️ Enviar email</button>
                <p class="lc-msg" id="lc-reminder-msg"></p>
            </div>
        </div>

        </div><!-- .wrap -->
        <script>
        (function($){
        var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')) ?>;
        var nonce   = <?php echo json_encode(wp_create_nonce('luna_admin_nonce')) ?>;
        var activeClientId = 0;
        var activeClientName = '';
        var defaultWorkspaceId = $('#lc-p-workspace option').eq(1).val() || '';
        var paymentReasons = <?php echo wp_json_encode($payment_reasons) ?>;
        // Provider data for print
        var providerData = {
            name:  <?php echo json_encode($provider_name) ?>,
            cuit:  <?php echo json_encode($provider_cuit) ?>,
            email: <?php echo json_encode($provider_email) ?>,
            phone: <?php echo json_encode($provider_phone) ?>
        };

        // ── STATUS BADGE ──────────────────────────────────────
        function statusBadge(s) {
            var map = {paid:['Pagado','lc-badge-paid'],partial:['Parcial','lc-badge-partial'],pending:['Pendiente','lc-badge-pending']};
            var d = map[s] || [s,''];
            return '<span class="lc-badge '+d[1]+'">'+d[0]+'</span>';
        }
        function ivaBadge(c) {
            var map = {'Responsable Inscripto':'lc-badge-ri','Monotributista':'lc-badge-mono','Consumidor Final':'lc-badge-cf','Exento':'lc-badge-ex'};
            return '<span class="lc-badge '+(map[c]||'lc-badge-cf')+'">'+c+'</span>';
        }
        function fmt(n){ return parseFloat(n||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

        // ── CLIENTS TABLE ─────────────────────────────────────
        var clientsData = [], sortCol = 'renewal_date', sortDir = 1, filterText = '';

        function renderClients() {
            if (!clientsData.length) { $('#lc-clients-wrap').html('<p class="lc-empty">Sin clientes aún. Creá el primero con el botón de arriba.</p>'); return; }
            var sorted = clientsData.slice().sort(function(a, b) {
                var va = a[sortCol] || '', vb = b[sortCol] || '';
                if (sortCol === 'renewal_date') {
                    va = va || '9999-99-99'; vb = vb || '9999-99-99';
                } else if (sortCol === 'renewal_amount') {
                    return (parseFloat(va||0) - parseFloat(vb||0)) * sortDir;
                }
                return va.toString().localeCompare(vb.toString(), 'es') * sortDir;
            });

            function th(label, col) {
                var arrow = sortCol===col ? (sortDir===1?' ▲':' ▼') : ' ⇅';
                return '<th style="cursor:pointer;user-select:none" data-col="'+col+'">'+label+'<span style="color:#94a3b8;font-size:10px">'+arrow+'</span></th>';
            }

            // Filtrar por nombre o dominio
            if (filterText) {
                sorted = sorted.filter(function(c){
                    return (c.name||'').toLowerCase().indexOf(filterText) !== -1
                        || (c.domain||'').toLowerCase().indexOf(filterText) !== -1;
                });
            }
            if (!sorted.length) { $('#lc-clients-wrap').html('<p class="lc-empty">Sin resultados para "'+esc(filterText)+'".</p>'); return; }

            // ── Resumen superior ─────────────────────────────────────────────────
            var sumAlDia = 0, sumDeben = 0, sumPendiente = 0, sumFacturado = 0, sumCobrado = 0;
            sorted.forEach(function(c){
                var cargo = parseFloat(c.total_cargo||0), cobro = parseFloat(c.total_cobro||0);
                var falta = Math.max(0, cargo - cobro);
                if (falta > 0) { sumDeben++; sumPendiente += falta; }
                else if (cargo > 0) sumAlDia++;
                sumFacturado += cargo;
                sumCobrado   += cobro;
            });
            var summary = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">';
            summary += '<span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">✓ Al día: '+sumAlDia+'</span>';
            summary += '<span style="background:#fff5f5;border:1px solid #fca5a5;color:#ef4444;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">⚠ Deben: '+sumDeben+'</span>';
            if (sumPendiente > 0) summary += '<span style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">💰 Pendiente total: $'+fmt(sumPendiente)+'</span>';
            summary += '<span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">🧾 Total a Facturar (12 meses): $'+fmt(sumFacturado)+'</span>';
            summary += '<span style="background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">💵 Total Cobrado (12 meses): $'+fmt(sumCobrado)+'</span>';
            summary += '<span style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;border-radius:8px;padding:6px 14px;font-size:13px">Total: '+sorted.length+' clientes</span>';
            summary += '</div>';

            // ── Tabla ────────────────────────────────────────────────────────────
            var vencLabel = sortCol === 'renewal_date' ? 'Vencimiento' : 'Vencimiento <small style="color:#94a3b8;font-weight:400">(clic→agrupa)</small>';
            var h = '<table class="lc-table"><thead><tr>'
                + th('Dominio','domain')
                + th(vencLabel,'renewal_date')
                + th('Monto','renewal_amount')
                + th('Pagos','total_cobro')
                + th('Faltante','total_cargo')
                + '<th>Estado</th><th>Acciones</th>'
                + '</tr></thead><tbody>';

            var monthColors = ['#f8faff','#fffdf5'];
            var monthIndex = -1, lastMonth = '';

            sorted.forEach(function(c){
                var subType    = c.subscription_type || (parseInt(c.is_subscription,10)===1 ? 'mensual' : 'none');
                var rd = '—';
                if (c.renewal_date) {
                    var dp = c.renewal_date.split('-');
                    rd = dp.length === 3 ? dp[2]+'/'+dp[1]+'/'+dp[0] : c.renewal_date;
                }
                var totalCargo = parseFloat(c.total_cargo || 0);
                var totalCobro = parseFloat(c.total_cobro || 0);
                var faltante   = Math.max(0, totalCargo - totalCobro);

                // Badges de abono y notas
                var subBadge = '';
                if (subType === 'mensual') subBadge = ' <span style="font-size:10px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:8px">🔄 Día '+esc(c.billing_day)+'</span>';
                if (subType === 'anual')   subBadge = ' <span style="font-size:10px;background:#ede9fe;color:#6d28d9;padding:1px 5px;border-radius:8px">📅 Anual</span>';

                // Estado badge
                var estadoHtml = '—';
                if (faltante > 0)       estadoHtml = '<span style="background:#fee2e2;color:#ef4444;font-weight:700;font-size:12px;padding:3px 10px;border-radius:20px">⚠ Debe</span>';
                else if (totalCargo > 0) estadoHtml = '<span style="background:#dcfce7;color:#16a34a;font-weight:700;font-size:12px;padding:3px 10px;border-radius:20px">✓ Al día</span>';

                // Color de fila: prioridad financiera > color de mes
                var rowBg;
                if (faltante > 0)        rowBg = 'background:#fff8f8';
                else if (totalCargo > 0) rowBg = 'background:#f8fffe';
                else if (sortCol === 'renewal_date') rowBg = 'background:'+monthColors[monthIndex % 2];
                else                     rowBg = '';

                // Separador de mes (solo al ordenar por vencimiento)
                if (sortCol === 'renewal_date') {
                    var curMonth = c.renewal_date ? c.renewal_date.substring(0,7) : '__sin_fecha__';
                    if (curMonth !== lastMonth) {
                        lastMonth = curMonth; monthIndex++;
                        var monthLabel = c.renewal_date
                            ? new Date(c.renewal_date+'T12:00:00').toLocaleDateString('es-AR',{month:'long',year:'numeric'})
                            : 'Sin fecha de vencimiento';
                        var monthCount = sorted.filter(function(x){ return (x.renewal_date||'').substring(0,7)===curMonth; }).length;
                        h += '<tr><td colspan="7" style="background:#e2e8f0;font-weight:700;font-size:11px;color:#475569;padding:5px 14px;text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid #cbd5e1">';
                        h += '📅 '+monthLabel+'<span style="font-weight:400;margin-left:8px;color:#94a3b8">('+monthCount+' cliente'+(monthCount>1?'s':'')+')</span>';
                        h += '</td></tr>';
                    }
                }

                h += '<tr style="'+rowBg+'">';

                // Dominio + nombre (pequeño) + badges
                var domLink = c.domain ? '<a href="https://'+esc(c.domain)+'" target="_blank" style="font-weight:700;color:#1e293b;text-decoration:none">'+esc(c.domain)+'</a>' : '<span style="color:#94a3b8">—</span>';
                var nameSub = c.name && c.name !== c.domain ? '<br><small style="color:#94a3b8;font-size:11px">'+esc(c.name)+'</small>' : '';
                h += '<td>'+domLink+subBadge+nameSub+'</td>';
                h += '<td style="white-space:nowrap;font-size:13px">'+rd+'</td>';
                h += '<td style="text-align:right;font-weight:600">'+(totalCargo > 0 ? '$'+fmt(totalCargo) : '—')+'</td>';
                h += '<td style="text-align:right;color:'+(totalCobro>0?'#16a34a':'#94a3b8')+';font-weight:600">'+(totalCobro>0?'$'+fmt(totalCobro):'—')+'</td>';
                h += '<td style="text-align:right;font-weight:700">'+(faltante>0?'<span style="color:#ef4444">$'+fmt(faltante)+'</span>':(totalCargo>0?'<span style="color:#16a34a">✓</span>':'—'))+'</td>';
                h += '<td>'+estadoHtml+'</td>';

                // Acciones
                var hasEmail = !!(c.email && c.email.trim());
                h += '<td style="white-space:nowrap">';
                h += '<button class="lc-btn lc-btn-sm lc-btn-ghost lc-edit-client" data-id="'+c.id+'" style="margin-right:3px" title="Editar cliente">✏️</button>';
                h += '<button class="lc-btn lc-btn-sm lc-btn-green lc-view-payments" data-id="'+c.id+'" data-name="'+esc(c.name)+'" style="margin-right:3px" title="Cuenta corriente / Registrar pago">💰 Pagos</button>';
                h += '<button class="lc-btn lc-btn-sm lc-btn-ghost lc-email-reminder" data-id="'+c.id+'" data-name="'+esc(c.name)+'" data-email="'+esc(c.email||'')+'" data-domain="'+esc(c.domain||'')+'" data-faltante="'+faltante+'" data-vencimiento="'+esc(rd)+'" style="margin-right:3px;'+(hasEmail?'':'opacity:.4;cursor:not-allowed')+'" title="'+(hasEmail?'Enviar recordatorio por email':'Sin email registrado')+'" '+(hasEmail?'':'disabled')+'>✉️</button>';
                h += '<button class="lc-btn lc-btn-sm lc-btn-danger lc-delete-client" data-id="'+c.id+'" title="Eliminar">🗑</button>';
                h += '</td></tr>';
            });
            h += '</tbody></table>';
            $('#lc-clients-wrap').html(summary + h);

            // Clic en encabezado para ordenar
            $('#lc-clients-wrap thead th[data-col]').on('click', function(){
                var col = $(this).data('col');
                if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
                renderClients();
            });
        }

        function loadClients() {
            $.post(ajaxUrl, {action:'luna_list_clients', nonce}, function(r) {
                if (!r.success) { $('#lc-clients-wrap').html('<p class="lc-empty">Error al cargar.</p>'); return; }
                clientsData = r.data;
                renderClients();
            });
            loadInvoices();
        }

        // ── FACTURAS (todas, de todos los clientes, una fila por factura) ──
        var invoicesData = [];
        function loadInvoices() {
            $.post(ajaxUrl, {action:'luna_list_invoices', nonce}, function(r) {
                if (!r.success) { $('#lc-invoices-wrap').html('<p class="lc-empty">Error al cargar.</p>'); return; }
                invoicesData = r.data;
                renderInvoices();
            });
        }

        function renderInvoices() {
            if (!invoicesData.length) { $('#lc-invoices-wrap').html('<p class="lc-empty">Sin facturas aún. Registrá la primera con "🧾 Nueva factura".</p>'); return; }

            // Pagado de cada factura = SOLO cobros vinculados explícitamente a ese cargo
            // (cargo_id). Nunca se reparte un cobro "suelto" contra otra factura del
            // mismo cliente: cada factura es independiente, como pediste.
            var cobrosMap = {};
            invoicesData.forEach(function(p){
                if (p.type === 'cobro' && p.currency !== 'USD' && p.cargo_id) {
                    cobrosMap[p.cargo_id] = (cobrosMap[p.cargo_id] || 0) + parseFloat(p.amount || 0);
                }
            });

            var facturas = invoicesData.filter(function(p){ return p.type !== 'cobro'; });
            if (filterText) {
                facturas = facturas.filter(function(p){
                    return (p.client_name||'').toLowerCase().indexOf(filterText) !== -1
                        || (p.client_domain||'').toLowerCase().indexOf(filterText) !== -1;
                });
            }
            if (!facturas.length) { $('#lc-invoices-wrap').html('<p class="lc-empty">Sin resultados para "'+esc(filterText)+'".</p>'); return; }

            facturas.sort(function(a,b){
                var da = a.due_date || a.payment_date || a.created_at || '';
                var db = b.due_date || b.payment_date || b.created_at || '';
                return db.localeCompare(da);
            });

            // ── Resumen superior (totales de las facturas listadas) ──────────────
            var sumFacturado = 0, sumPagado = 0, sumPendiente = 0;
            facturas.forEach(function(p){
                if (p.currency === 'USD') return;
                var amt    = parseFloat(p.amount||0);
                var pagado = cobrosMap[p.id] || 0;
                sumFacturado += amt;
                sumPagado    += Math.min(pagado, amt);
                sumPendiente += Math.max(0, amt - pagado);
            });
            var summary = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">';
            summary += '<span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">🧾 Total Facturado: $'+fmt(sumFacturado)+'</span>';
            summary += '<span style="background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">💵 Total Cobrado: $'+fmt(sumPagado)+'</span>';
            if (sumPendiente > 0) summary += '<span style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">⚠ Pendiente: $'+fmt(sumPendiente)+'</span>';
            summary += '<span style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;border-radius:8px;padding:6px 14px;font-size:13px">Total: '+facturas.length+' facturas</span>';
            summary += '</div>';

            var h = '<table class="lc-table"><thead><tr>'
                + '<th>Cliente</th><th>Fecha</th><th>Concepto</th>'
                + '<th style="text-align:right">Monto</th><th style="text-align:right">Pagado</th>'
                + '<th style="text-align:right">Falta</th><th>Estado</th><th>Acciones</th>'
                + '</tr></thead><tbody>';

            facturas.forEach(function(p){
                var amt     = parseFloat(p.amount||0);
                var isUSD   = p.currency === 'USD';
                var pagado  = cobrosMap[p.id] || 0;
                var falta   = Math.max(0, amt - pagado);
                var rowBg   = falta > 0 ? 'background:#fff8f8' : 'background:#f8fffe';
                var dateVal = p.due_date || p.payment_date || (p.created_at ? p.created_at.split(' ')[0] : '') || '—';
                var clienteLabel = p.client_domain || p.client_name || '—';

                h += '<tr style="'+rowBg+'">';
                h += '<td><strong>'+esc(clienteLabel)+'</strong>'+(p.client_name && p.client_name!==clienteLabel?'<br><small style="color:#94a3b8">'+esc(p.client_name)+'</small>':'')+'</td>';
                h += '<td style="white-space:nowrap;font-size:12px;color:#64748b">'+esc(dateVal)+'</td>';
                h += '<td><span style="font-size:13px">'+esc(p.concept)+'</span></td>';
                if (isUSD) {
                    h += '<td colspan="3" style="text-align:right;font-weight:700;color:#1d4ed8">USD $'+fmt(amt)+'</td>';
                } else {
                    h += '<td style="text-align:right;font-weight:700">$'+fmt(amt)+'</td>';
                    h += '<td style="text-align:right;color:'+(pagado>0?'#16a34a':'#94a3b8')+'">'+(pagado>0?'$'+fmt(pagado):'—')+'</td>';
                    h += '<td style="text-align:right">'+(falta>0?'<span style="color:#ef4444;font-weight:700">$'+fmt(falta)+'</span>':'<span style="color:#16a34a;font-weight:700">✓ Al día</span>')+'</td>';
                }
                var effectiveStatus = isUSD ? p.status : ((falta <= 0 && pagado > 0) ? 'paid' : (pagado > 0 ? 'partial' : p.status));
                h += '<td>'+statusBadge(effectiveStatus)+'</td>';
                h += '<td style="white-space:nowrap">';
                if (effectiveStatus !== 'paid') {
                    h += '<button class="lc-btn lc-btn-sm lc-btn-green lc-btn-cobro" data-id="'+p.id+'" data-amount="'+amt+'" data-concept="'+esc(p.concept)+'" data-client-id="'+p.client_id+'" style="margin-right:4px;font-size:11px" title="Registrar cobro de esta factura">💵 Cobrar</button>';
                }
                h += '<button class="lc-btn lc-btn-sm lc-btn-ghost lc-edit-payment" data-id="'+p.id+'" data-client-id="'+p.client_id+'" style="margin-right:4px" title="Editar esta factura">✏️ Editar</button>';
                h += '<button class="lc-btn lc-btn-sm" style="background:#0ea5e9;margin-right:4px" onclick="lcPrintPayment('+p.id+','+p.client_id+')" title="Imprimir">🖨️</button>';
                h += '<button class="lc-btn lc-btn-sm lc-btn-danger lc-delete-payment" data-id="'+p.id+'" data-client-id="'+p.client_id+'" title="Eliminar">🗑</button>';
                h += '</td></tr>';
            });
            h += '</tbody></table>';
            $('#lc-invoices-wrap').html(summary + h);
        }

        // ── LIBRO DE REGISTRO (Debe / Haber / Saldo) ───────────
        // Cada movimiento es una fila independiente. El Saldo es un acumulado
        // cronológico simple (Debe - Haber), sin intentar "emparejar" cada cobro
        // con una factura vieja. Una factura nueva nunca le come el pago a otra.
        function loadPayments(clientId) {
            $('#lc-payments-wrap').html('<p class="lc-empty">Cargando...</p>');
            $.post(ajaxUrl, {action:'luna_list_payments', nonce, client_id: clientId}, function(r) {
                if (!r.success) { $('#lc-payments-wrap').html('<p class="lc-empty">Error.</p>'); return; }
                var rows = r.data;
                if (!rows.length) { $('#lc-payments-wrap').html('<p class="lc-empty">Sin movimientos. Registrá el primer cargo con "+ Nuevo cargo".</p>'); return; }

                // Fecha efectiva de cada fila: fecha de emisión/pago > vencimiento > fecha de creación.
                // Así una fila nunca queda sin fecha.
                rows.forEach(function(p) {
                    p._fecha = p.payment_date || p.due_date || (p.created_at ? p.created_at.split(' ')[0] : '') || '';
                });

                // Orden cronológico ascendente (más viejo primero), como un libro diario
                rows.sort(function(a, b) {
                    if (a._fecha !== b._fecha) return a._fecha.localeCompare(b._fecha);
                    return (a.id||0) - (b.id||0);
                });

                // Totales (ARS: Debe/Haber acumulados; USD aparte, no se mezcla en el saldo)
                var totCargo = 0, totCobro = 0, totUSD = 0;
                rows.forEach(function(p) {
                    var amt = parseFloat(p.amount||0);
                    if (p.currency === 'USD') { totUSD += amt; return; }
                    if (p.type === 'cobro') totCobro += amt; else totCargo += amt;
                });
                var saldoFinal = totCargo - totCobro;

                // Barra de resumen
                var ok = saldoFinal <= 0;
                var h = '<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;background:'+(ok?'#f0fdf4':'#fef2f2')+';border:1px solid '+(ok?'#bbf7d0':'#fca5a5')+';border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px">';
                h += '<span>📋 Debe (cargos): <strong>$'+fmt(totCargo)+'</strong></span>';
                h += '<span style="color:#16a34a">💵 Haber (cobros): <strong>$'+fmt(totCobro)+'</strong></span>';
                if (saldoFinal > 0)       h += '<span style="color:#ef4444;font-weight:700;font-size:14px">⚠ Saldo deudor: $'+fmt(saldoFinal)+'</span>';
                else if (saldoFinal < 0)  h += '<span style="color:#1d4ed8;font-weight:700">✓ Saldo a favor: $'+fmt(Math.abs(saldoFinal))+'</span>';
                else                      h += '<span style="color:#16a34a;font-weight:700">✓ Saldo $0 — al día</span>';
                if (totUSD) h += '<span style="color:#1d4ed8">USD: $'+fmt(totUSD)+'</span>';
                h += '</div>';

                h += '<table class="lc-table"><thead><tr>';
                h += '<th>Fecha</th><th>Concepto</th>';
                h += '<th style="text-align:right">Debe</th><th style="text-align:right">Haber</th>';
                h += '<th>Forma de pago</th><th style="text-align:right">Saldo</th>';
                h += '<th>Estado</th><th>Acciones</th>';
                h += '</tr></thead><tbody>';

                var saldoCorrido = 0;
                rows.forEach(function(p) {
                    var amt     = parseFloat(p.amount||0);
                    var isCobro = p.type === 'cobro';
                    var isUSD   = p.currency === 'USD';
                    if (!isUSD) saldoCorrido += isCobro ? -amt : amt;
                    var rowBg = isCobro ? 'background:#f0fdf9' : '';

                    h += '<tr'+(rowBg?' style="'+rowBg+'"':'')+'>';
                    h += '<td style="white-space:nowrap;font-size:12px;color:#64748b">'+esc(p._fecha||'—')+'</td>';
                    h += '<td><span style="font-size:13px">'+esc(p.concept)+'</span>'+(p.workspace_name?'<br><small style="color:#94a3b8">📋 '+esc(p.workspace_name)+'</small>':'')+(isUSD?' <small style="color:#1d4ed8">(USD)</small>':'')+'</td>';
                    h += '<td style="text-align:right;font-weight:700">'+(!isCobro?'$'+fmt(amt):'<span style="color:#94a3b8">—</span>')+'</td>';
                    h += '<td style="text-align:right;font-weight:700;color:#16a34a">'+(isCobro?'$'+fmt(amt):'<span style="color:#94a3b8">—</span>')+'</td>';
                    h += '<td style="font-size:12px;color:#64748b">'+(isCobro?esc(p.method||'—'):'—')+'</td>';
                    h += '<td style="text-align:right;font-weight:700">'+(isUSD?'<span style="color:#94a3b8">—</span>':('<span style="color:'+(saldoCorrido>0?'#ef4444':(saldoCorrido<0?'#1d4ed8':'#16a34a'))+'">$'+fmt(saldoCorrido)+'</span>'));
                    h += '</td>';
                    h += '<td>'+(isCobro?'<span style="color:#16a34a;font-size:12px">✓ Acreditado</span>':statusBadge(p.status))+'</td>';
                    h += '<td style="white-space:nowrap">';
                    if (!isCobro && p.status !== 'paid') {
                        h += '<button class="lc-btn lc-btn-sm lc-btn-green lc-btn-cobro" data-id="'+p.id+'" data-amount="'+amt+'" data-concept="'+esc(p.concept)+'" style="margin-right:4px;font-size:11px" title="Registrar cobro de este cargo">💵 Cobrar</button>';
                    }
                    h += '<button class="lc-btn lc-btn-sm lc-btn-ghost lc-edit-payment" data-id="'+p.id+'" style="margin-right:4px" title="Editar este movimiento">✏️ Editar</button>';
                    if (!isCobro) h += '<button class="lc-btn lc-btn-sm" style="background:#0ea5e9;margin-right:4px" onclick="lcPrintPayment('+p.id+')">🖨️</button>';
                    h += '<button class="lc-btn lc-btn-sm lc-btn-danger lc-delete-payment" data-id="'+p.id+'" title="Eliminar">🗑</button>';
                    h += '</td></tr>';
                });
                h += '</tbody></table>';
                $('#lc-payments-wrap').html(h);
            });
        }

        // ── PRINT ─────────────────────────────────────────────
        window.lcPrintPayment = function(paymentId, clientId) {
            $.post(ajaxUrl, {action:'luna_list_payments', nonce, client_id: clientId || activeClientId}, function(r) {
                if(!r.success) return;
                var p = r.data.find(function(x){return x.id==paymentId});
                if(!p) return;
                var today = new Date().toLocaleDateString('es-AR');
                var html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                    + '<title>Presupuesto #'+(p.invoice_number||p.id)+'</title>'
                    + '<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",sans-serif;font-size:13px;color:#1e1e1e;padding:40px}'
                    + 'h1{font-size:22px;font-weight:900;color:#5b6af0;margin-bottom:4px}'
                    + '.sub{color:#64748b;font-size:12px;margin-bottom:24px}'
                    + '.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:32px}'
                    + '.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px}'
                    + '.box-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px}'
                    + '.box p{margin-bottom:4px;font-size:13px}'
                    + 'table{width:100%;border-collapse:collapse;margin-bottom:24px}'
                    + 'th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:700;font-size:12px;color:#475569}'
                    + 'td{padding:12px 14px;border-bottom:1px solid #f1f5f9;font-size:13px}'
                    + '.total-row td{font-weight:900;font-size:16px;color:#5b6af0;border-bottom:none;border-top:2px solid #e2e8f0}'
                    + '.status{display:inline-block;padding:4px 14px;border-radius:999px;font-size:12px;font-weight:700}'
                    + '.status-paid{background:#dcfce7;color:#16a34a}.status-pending{background:#fef9c3;color:#a16207}.status-partial{background:#dbeafe;color:#1d4ed8}'
                    + '.footer{text-align:center;color:#94a3b8;font-size:11px;margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0}'
                    + '@media print{body{padding:20px}}'
                    + '</style></head><body>';
                html += '<h1>🌙 Presupuesto</h1>';
                html += '<div class="sub">N° '+(p.invoice_number||('P-'+String(p.id).padStart(6,'0')))+' &nbsp;·&nbsp; Emitido: '+today+'</div>';
                html += '<div class="grid">';
                html += '<div class="box"><div class="box-label">De (Prestador)</div>'
                    + '<p><strong>'+esc2(providerData.name)+'</strong></p>'
                    + (providerData.cuit?'<p>CUIT: '+esc2(providerData.cuit)+'</p>':'')
                    + (providerData.email?'<p>'+esc2(providerData.email)+'</p>':'')
                    + (providerData.phone?'<p>'+esc2(providerData.phone)+'</p>':'')
                    + '</div>';
                html += '<div class="box"><div class="box-label">Para (Cliente)</div>'
                    + '<p><strong>'+esc2(activeClientName)+'</strong></p>'
                    + (p.client_cuit?'<p>CUIT: '+esc2(p.client_cuit)+'</p>':'')
                    + (p.client_email?'<p>'+esc2(p.client_email)+'</p>':'')
                    + (p.client_city?'<p>'+esc2(p.client_city)+'</p>':'')
                    + '</div>';
                html += '</div>';
                html += '<table><thead><tr><th>Concepto / Descripción</th><th>Método</th><th>Vencimiento</th><th style="text-align:right">Monto</th></tr></thead><tbody>';
                html += '<tr><td>'+esc2(p.concept)+(p.notes?'<br><small style="color:#64748b">'+esc2(p.notes)+'</small>':'')+'</td>'
                    + '<td>'+esc2(p.method||'—')+'</td>'
                    + '<td>'+(p.due_date||'—')+'</td>'
                    + '<td style="text-align:right;font-weight:700">'+p.currency+' '+parseFloat(p.amount||0).toLocaleString('es-AR',{minimumFractionDigits:2})+'</td></tr>';
                html += '<tr class="total-row"><td colspan="3"><strong>TOTAL</strong></td>'
                    + '<td style="text-align:right">'+p.currency+' '+parseFloat(p.amount||0).toLocaleString('es-AR',{minimumFractionDigits:2})+'</td></tr>';
                html += '</tbody></table>';
                var sClass = {paid:'status-paid',pending:'status-pending',partial:'status-partial'}[p.status]||'';
                var sLabel = {paid:'✓ Pagado',pending:'Pendiente de pago',partial:'Pago parcial'}[p.status]||p.status;
                html += '<p>Estado: <span class="status '+sClass+'">'+sLabel+'</span></p>';
                html += '<div class="footer">Generado con Luna Workspace · websobreruedas.com</div>';
                html += '</body></html>';
                var w = window.open('','_blank','width=820,height=700,scrollbars=yes');
                w.document.write(html);
                w.document.close();
                setTimeout(function(){w.print();},600);
            });
        };

        function esc(s){ var d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }
        function esc2(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        // ── OPEN / CLOSE MODALS ───────────────────────────────
        function openClientModal(data) {
            $('#lc-client-id').val(data ? data.id : '');
            $('#lc-c-name').val(data ? data.name : '');
            $('#lc-c-domain').val(data ? data.domain : '');
            $('#lc-c-cuit').val(data ? data.cuit : '');
            $('#lc-c-iva').val(data ? data.iva_condition : 'Consumidor Final');
            $('#lc-c-email').val(data ? data.email : '');
            $('#lc-c-phone').val(data ? data.phone : '');
            $('#lc-c-address').val(data ? data.address : '');
            $('#lc-c-city').val(data ? data.city : '');
            $('#lc-c-notes').val(data ? data.notes : '');
            var subType = (data && data.subscription_type) ? data.subscription_type : 'none';
            // compatibilidad con registros viejos (is_subscription=1 sin subscription_type)
            if (subType === 'none' && data && parseInt(data.is_subscription, 10) === 1) subType = 'mensual';
            $('#lc-c-subscription').val(subType);
            $('#lc-c-billing-day').val(data && data.billing_day ? data.billing_day : '');
            $('#lc-billing-day-row').toggle(subType === 'mensual');
            $('#lc-client-modal-title').text(data ? 'Editar cliente' : 'Nuevo cliente');
            $('#lc-client-msg').text('').removeClass('ok err');
            $('#lc-modal-client').addClass('open');
        }
        function openPaymentModal(data, forGlobal) {
            $('#lc-payment-id').val(data ? data.id : '');
            // El buscador de cliente se muestra siempre, arriba de todo el formulario.
            $('#lc-p-client-row').show();
            var ownerId = (data && data.client_id) ? data.client_id : (!forGlobal ? activeClientId : '');
            $('#lc-payment-client-id').val(ownerId || '');
            if (ownerId) {
                var ownerClient = clientsData.find(function(x){ return x.id == ownerId; });
                $('#lc-p-client-search').val(ownerClient ? (ownerClient.domain || ownerClient.name) : (activeClientName || ''));
            } else {
                $('#lc-p-client-search').val('');
            }
            $('#lc-p-concept').val(data ? data.concept : '');
            $('#lc-p-amount').val(data ? data.amount : '');
            $('#lc-p-currency').val(data ? data.currency : 'ARS');
            $('#lc-p-date').val(data ? data.payment_date : '');
            $('#lc-p-due').val(data ? data.due_date : '');
            $('#lc-p-method').val(data ? data.method : 'Transferencia');
            $('#lc-p-status').val(data ? data.status : 'pending');
            $('#lc-p-invoice').val(data ? data.invoice_number : '');
            $('#lc-p-workspace').val(data ? (data.workspace_id||'') : defaultWorkspaceId);
            $('#lc-p-notes').val(data ? data.notes : '');
            // Tipo cargo/cobro
            var pType = data ? (data.type || 'cargo') : 'cargo';
            $('input[name=lc-p-type][value='+pType+']').prop('checked', true);
            $('#lc-p-due').closest('.lc-fg').toggle(pType !== 'cobro');
            if (!data && !$('#lc-p-date').val()) $('#lc-p-date').val(new Date().toISOString().split('T')[0]);
            if (pType === 'cobro' && !data) {
                $('#lc-p-status').val('paid');
            }
            // Cuotas: solo disponible en nuevos cargos
            $('input[name=lc-p-inst-type][value=1]').prop('checked', true);
            $('#lc-p-inst-n').hide().val(2);
            $('#lc-p-installments-row').toggle(!data && pType === 'cargo');
            $('#lc-payment-modal-title').text(data ? 'Editar movimiento' : (pType === 'cobro' ? 'Nuevo cobro' : 'Nuevo cargo'));
            $('#lc-payment-msg').text('').removeClass('ok err');
            $('#lc-modal-payment').addClass('open');
        }

        // ── EVENTS ───────────────────────────────────────────
        $('#lc-btn-new-client').on('click', function(){ openClientModal(null); });
        $('#lc-btn-new-invoice').on('click', function(){ openPaymentModal(null, true); });

        // ── Buscador genérico (input + lista de sugerencias) ──────────────────
        function attachSearchSuggest(inputSel, boxSel, itemsFn, labelFn, onPick) {
            function render() {
                var filter = $.trim($(inputSel).val()).toLowerCase();
                var items = itemsFn().filter(function(it){ return labelFn(it).toLowerCase().indexOf(filter) !== -1; }).slice(0, 30);
                var $box = $(boxSel);
                if (!items.length) { $box.html('<div class="lc-suggest-empty">Sin resultados</div>').show(); return; }
                $box.html(items.map(function(it, i){
                    return '<div class="lc-suggest-item" data-i="'+i+'">'+esc(labelFn(it))+'</div>';
                }).join('')).show();
                $box.data('items', items);
            }
            $(document).on('focus input', inputSel, render);
            $(document).on('click', boxSel + ' .lc-suggest-item', function(){
                var items = $(boxSel).data('items') || [];
                var item  = items[$(this).data('i')];
                if (item) onPick(item);
                $(boxSel).hide();
            });
            $(document).on('click', function(e){
                if (!$(e.target).closest(inputSel + ', ' + boxSel).length) $(boxSel).hide();
            });
        }
        attachSearchSuggest('#lc-p-client-search', '#lc-p-client-suggest',
            function(){ return clientsData.slice().sort(function(a,b){ return (a.domain||a.name||'').localeCompare(b.domain||b.name||''); }); },
            function(c){ return c.domain || c.name; },
            function(c){ $('#lc-p-client-search').val(c.domain || c.name); $('#lc-payment-client-id').val(c.id); }
        );
        attachSearchSuggest('#lc-p-concept', '#lc-p-concept-suggest',
            function(){ return paymentReasons; },
            function(r){ return r; },
            function(r){ $('#lc-p-concept').val(r); }
        );
        $(document).on('input', '#lc-p-client-search', function(){ $('#lc-payment-client-id').val(''); });
        $('#lc-search-client').on('input', function(){
            filterText = $.trim($(this).val()).toLowerCase();
            if (activeView === 'invoices') renderInvoices(); else renderClients();
        });

        // ── Tabs Clientes / Facturas ───────────────────────────
        var activeView = 'clients';
        $('.lc-view-tab').on('click', function(){
            activeView = $(this).data('view');
            $('.lc-view-tab').css({background:'transparent',color:'#475569'}).removeClass('active');
            $(this).css({background:'#5b6af0',color:'#fff'}).addClass('active');
            if (activeView === 'invoices') { $('#lc-clients-wrap').hide(); $('#lc-invoices-wrap').show(); renderInvoices(); }
            else { $('#lc-invoices-wrap').hide(); $('#lc-clients-wrap').show(); renderClients(); }
        });
        $('#lc-c-subscription').on('change', function(){ $('#lc-billing-day-row').toggle($(this).val() === 'mensual'); });

        // Tipo cargo/cobro: ajustar formulario
        $(document).on('change', 'input[name=lc-p-type]', function(){
            var isCobro = $(this).val() === 'cobro';
            $('#lc-p-due').closest('.lc-fg').toggle(!isCobro);
            $('#lc-p-installments-row').toggle(!isCobro && !$('#lc-payment-id').val());
            if (isCobro) {
                $('#lc-p-status').val('paid');
                if (!$('#lc-p-date').val()) $('#lc-p-date').val(new Date().toISOString().split('T')[0]);
                if (!$('#lc-p-concept').val()) $('#lc-p-concept').val('Cobro — ');
                $('#lc-payment-modal-title').text('Nuevo cobro');
            } else {
                $('#lc-p-status').val('pending');
                $('#lc-payment-modal-title').text('Nuevo cargo');
            }
        });

        // Cuotas: mostrar/ocultar campo N
        $(document).on('change', 'input[name=lc-p-inst-type]', function(){
            $('#lc-p-inst-n').toggle($(this).val() === 'n');
        });

        // ── COBRO RÁPIDO ──────────────────────────────────────
        $(document).on('click', '.lc-btn-cobro', function(){
            var cargoId  = $(this).data('id');
            var amount   = $(this).data('amount');
            var concept  = $(this).data('concept');
            var clientId = $(this).data('client-id') || activeClientId;
            $('#lc-cobro-cargo-id').val(cargoId);
            $('#lc-cobro-client-id').val(clientId);
            $('#lc-cobro-amount').val(amount);
            $('#lc-cobro-concept').val('Cobro — ' + concept);
            $('#lc-cobro-date').val(new Date().toISOString().split('T')[0]);
            $('#lc-cobro-currency').val('ARS');
            $('#lc-cobro-method').val('Transferencia');
            $('#lc-cobro-notes').val('');
            $('#lc-cobro-msg').text('').removeClass('ok err');
            $('#lc-modal-cobro').addClass('open');
        });
        // ── EMAIL RECORDATORIO AL CLIENTE ────────────────────────────────────────
        $(document).on('click', '.lc-email-reminder', function(){
            var $btn    = $(this);
            if ($btn.prop('disabled')) return;
            var cid      = $btn.data('id');
            var cname    = $btn.data('name');
            var cemail   = $btn.data('email');
            var domain   = $btn.data('domain') || cname;
            var faltante = parseFloat($btn.data('faltante') || 0);
            var venc     = $btn.data('vencimiento') || '—';
            var faltaStr = faltante > 0 ? '$'+fmt(faltante) : '—';
            var template = 'Hola!\n\nTe recordamos que tenés un saldo pendiente de ' + faltaStr
                         + ' correspondiente al servicio de ' + domain + '.\n'
                         + 'Fecha de vencimiento: ' + venc + '.\n\n'
                         + 'Por favor, realizá el pago a la brevedad.\n\n'
                         + 'Ante cualquier consulta, estamos a tu disposición.\n\nSaludos!';
            $('#lc-reminder-client-name').text(cname);
            $('#lc-reminder-client-email').text(cemail);
            $('#lc-reminder-body').val(template);
            $('#lc-reminder-msg').text('').removeClass('ok err');
            $('#lc-submit-email-reminder').data('client-id', cid).prop('disabled', false).text('✉️ Enviar email');
            $('#lc-modal-email-reminder').addClass('open');
        });
        $('#lc-close-email-reminder, #lc-modal-email-reminder').on('click', function(e){ if(e.target===this) $('#lc-modal-email-reminder').removeClass('open'); });
        $('#lc-submit-email-reminder').on('click', function(){
            var body = $.trim($('#lc-reminder-body').val());
            if (!body) { $('#lc-reminder-msg').text('Escribí un mensaje.').addClass('err'); return; }
            var cid = $(this).data('client-id');
            $(this).prop('disabled', true).text('Enviando...');
            $.post(ajaxUrl, {action:'luna_send_client_reminder', nonce, client_id: cid, body}, function(r){
                $('#lc-submit-email-reminder').prop('disabled', false).text('✉️ Enviar email');
                if (r.success) {
                    $('#lc-reminder-msg').text(r.data).addClass('ok');
                    setTimeout(function(){ $('#lc-modal-email-reminder').removeClass('open'); }, 1800);
                } else {
                    $('#lc-reminder-msg').text('Error: '+r.data).addClass('err');
                }
            }).fail(function(){ $('#lc-submit-email-reminder').prop('disabled',false).text('✉️ Enviar email'); $('#lc-reminder-msg').text('Error de conexión').addClass('err'); });
        });

        $('#lc-close-cobro, #lc-modal-cobro').on('click', function(e){ if(e.target===this) $('#lc-modal-cobro').removeClass('open'); });

        $('#lc-submit-cobro').on('click', function(){
            var amount = parseFloat($('#lc-cobro-amount').val());
            if (isNaN(amount) || amount <= 0) { $('#lc-cobro-msg').text('Ingresá un monto válido.').addClass('err'); return; }
            var concept = $.trim($('#lc-cobro-concept').val()) || 'Cobro';
            $(this).prop('disabled', true).text('Guardando...');
            $.post(ajaxUrl, {
                action:       'luna_save_payment',
                nonce:        nonce,
                id:           '',
                client_id:    $('#lc-cobro-client-id').val() || activeClientId,
                cargo_id:     $('#lc-cobro-cargo-id').val(),
                type:         'cobro',
                concept:      concept,
                amount:       amount,
                currency:     $('#lc-cobro-currency').val(),
                payment_date: $('#lc-cobro-date').val(),
                method:       $('#lc-cobro-method').val(),
                status:       'paid',
                notes:        $('#lc-cobro-notes').val(),
                installments: 1,
            }, function(r){
                $('#lc-submit-cobro').prop('disabled', false).text('Registrar cobro');
                if (r.success) {
                    $('#lc-cobro-msg').text('✓ Cobro registrado').addClass('ok');
                    setTimeout(function(){
                        $('#lc-modal-cobro').removeClass('open');
                        if (activeClientId) loadPayments(activeClientId);
                        loadClients();
                    }, 700);
                } else {
                    $('#lc-cobro-msg').text('Error: ' + r.data).addClass('err');
                }
            });
        });

        // ── ESTADO DE CUENTA ──────────────────────────────────
        function buildEstadoHtml(rows, clientName) {
            if (!rows.length) return '<p class="lc-empty">Sin movimientos en ese período.</p>';
            rows.forEach(function(p) {
                p._fecha = p.payment_date || p.due_date || (p.created_at ? p.created_at.split(' ')[0] : '') || '';
            });
            rows.sort(function(a,b){
                if (a._fecha !== b._fecha) return a._fecha.localeCompare(b._fecha);
                return (a.id||0) - (b.id||0);
            });
            var totCargo = 0, totCobro = 0;
            var h = '<table class="lc-table"><thead><tr><th>Fecha</th><th>Concepto</th><th style="text-align:right">Debe</th><th style="text-align:right">Haber</th><th style="text-align:right">Saldo</th></tr></thead><tbody>';
            var saldoCorrido = 0;
            rows.forEach(function(p) {
                if (p.currency === 'USD') return;
                var amt = parseFloat(p.amount||0);
                var isCobro = p.type === 'cobro';
                if (isCobro) totCobro += amt; else totCargo += amt;
                saldoCorrido += isCobro ? -amt : amt;
                h += '<tr>';
                h += '<td style="font-size:12px;white-space:nowrap">'+esc(p._fecha||'—')+'</td>';
                h += '<td style="font-size:13px">'+esc(p.concept)+'</td>';
                h += '<td style="text-align:right;font-weight:700">'+(!isCobro?'$'+fmt(amt):'<span style="color:#94a3b8">—</span>')+'</td>';
                h += '<td style="text-align:right;font-weight:700;color:#16a34a">'+(isCobro?'$'+fmt(amt):'<span style="color:#94a3b8">—</span>')+'</td>';
                h += '<td style="text-align:right;font-weight:700;color:'+(saldoCorrido>0?'#ef4444':(saldoCorrido<0?'#1d4ed8':'#16a34a'))+'">$'+fmt(saldoCorrido)+'</td>';
                h += '</tr>';
            });
            h += '</tbody></table>';
            var saldoFinal = totCargo - totCobro;
            var sfColor = saldoFinal > 0 ? '#ef4444' : '#16a34a';
            h += '<div style="margin-top:14px;padding:12px 16px;background:#f8fafc;border-radius:8px;display:flex;gap:20px;font-size:13px">';
            h += '<span>Debe: <strong>$'+fmt(totCargo)+'</strong></span>';
            h += '<span>Haber: <strong>$'+fmt(totCobro)+'</strong></span>';
            h += '<span style="font-weight:700;color:'+sfColor+'">Saldo: $'+fmt(saldoFinal)+'</span>';
            h += '</div>';
            return h;
        }

        $('#lc-btn-estado-cuenta').on('click', function(){
            $('#lc-ec-client-name').text(activeClientName);
            var now = new Date(), y = now.getFullYear(), m = now.getMonth()+1;
            $('#lc-ec-from').val(y+'-01-01');
            $('#lc-ec-to').val(y+'-'+String(m).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0'));
            $('#lc-estado-result').html('');
            $('#lc-modal-estado').addClass('open');
        });
        $('#lc-close-estado, #lc-modal-estado').on('click', function(e){ if(e.target===this) $('#lc-modal-estado').removeClass('open'); });

        $('#lc-btn-run-estado').on('click', function(){
            var from = $('#lc-ec-from').val(), to = $('#lc-ec-to').val();
            $('#lc-estado-result').html('<p class="lc-empty">Cargando...</p>');
            $.post(ajaxUrl, {action:'luna_estado_cuenta', nonce, client_id: activeClientId, from, to}, function(r){
                if (!r.success) { $('#lc-estado-result').html('<p style="color:#ef4444">'+r.data+'</p>'); return; }
                $('#lc-estado-result').html(buildEstadoHtml(r.data, activeClientName));
            });
        });

        $('#lc-btn-print-estado').on('click', function(){
            var from = $('#lc-ec-from').val(), to = $('#lc-ec-to').val();
            $.post(ajaxUrl, {action:'luna_estado_cuenta', nonce, client_id: activeClientId, from, to}, function(r){
                if (!r.success) return;
                var rows = r.data;
                rows.forEach(function(p){
                    p._fecha = p.payment_date || p.due_date || (p.created_at ? p.created_at.split(' ')[0] : '') || '';
                });
                rows.sort(function(a,b){
                    if (a._fecha !== b._fecha) return a._fecha.localeCompare(b._fecha);
                    return (a.id||0) - (b.id||0);
                });
                var totCargo=0, totCobro=0;
                var bodyRows = '';
                var saldoCorridoP = 0;
                rows.forEach(function(p){
                    if(p.currency==='USD') return;
                    var amt=parseFloat(p.amount||0); var isCobro=p.type==='cobro';
                    if(isCobro) totCobro+=amt; else totCargo+=amt;
                    saldoCorridoP += isCobro ? -amt : amt;
                    bodyRows+='<tr>';
                    bodyRows+='<td>'+esc(p._fecha||'—')+'</td>';
                    bodyRows+='<td>'+p.concept+'</td>';
                    bodyRows+='<td style="text-align:right;font-weight:bold">'+(!isCobro?'$'+fmt(amt):'<span style="color:#aaa">—</span>')+'</td>';
                    bodyRows+='<td style="text-align:right;color:#16a34a;font-weight:bold">'+(isCobro?'$'+fmt(amt):'<span style="color:#aaa">—</span>')+'</td>';
                    bodyRows+='<td style="text-align:right;font-weight:bold;color:'+(saldoCorridoP>0?'#ef4444':(saldoCorridoP<0?'#1d4ed8':'#16a34a'))+'">$'+fmt(saldoCorridoP)+'</td>';
                    bodyRows+='</tr>';
                });
                var saldoFinal=totCargo-totCobro;
                var sfColor=saldoFinal>0?'#ef4444':'#16a34a';
                var today=new Date().toLocaleDateString('es-AR');
                var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Estado de Cuenta</title>'
                    +'<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",sans-serif;font-size:12px;color:#1e1e1e;padding:32px}'
                    +'h1{font-size:20px;font-weight:900;color:#5b6af0}h2{font-size:14px;color:#64748b;margin-bottom:4px}'
                    +'.meta{display:flex;justify-content:space-between;margin:16px 0 24px;font-size:12px;color:#64748b}'
                    +'table{width:100%;border-collapse:collapse;margin-bottom:20px}'
                    +'th{background:#f1f5f9;padding:8px 12px;text-align:left;font-size:11px;color:#475569;font-weight:700}'
                    +'td{padding:8px 12px;border-bottom:1px solid #f0f0f0}'
                    +'.total{background:#f8fafc;padding:12px 16px;border-radius:6px;display:flex;gap:24px}'
                    +'.footer{text-align:center;color:#94a3b8;font-size:10px;margin-top:24px;border-top:1px solid #e2e8f0;padding-top:12px}'
                    +'@media print{body{padding:16px}}</style></head><body>'
                    +'<h1>Luna Workspace</h1>'
                    +'<h2>Estado de Cuenta — '+activeClientName+'</h2>'
                    +'<div class="meta"><span>Período: '+from+' al '+to+'</span><span>Emitido: '+today+'</span></div>'
                    +'<table><thead><tr><th>Fecha</th><th>Concepto</th><th style="text-align:right">Debe</th><th style="text-align:right">Haber</th><th style="text-align:right">Saldo</th></tr></thead>'
                    +'<tbody>'+bodyRows+'</tbody></table>'
                    +'<div class="total"><span>Debe: <strong>$'+fmt(totCargo)+'</strong></span>'
                    +'<span>Haber: <strong>$'+fmt(totCobro)+'</strong></span>'
                    +'<span style="color:'+sfColor+';font-weight:bold">Saldo: $'+fmt(saldoFinal)+'</span></div>'
                    +'<div class="footer">Generado por Luna Workspace · '+today+'</div>'
                    +'</body></html>';
                var w=window.open('','_blank','width=900,height=700');
                w.document.write(html); w.document.close(); w.focus(); w.print();
            });
        });

        // Informe de caja
        $('#lc-btn-informe').on('click', function(){
            var sel = $('#lc-inf-client');
            sel.find('option:not(:first)').remove();
            clientsData.forEach(function(c){ sel.append('<option value="'+c.id+'">'+esc(c.name)+'</option>'); });
            var now = new Date();
            var y = now.getFullYear(), m = now.getMonth()+1;
            var from = y+'-'+String(m).padStart(2,'0')+'-01';
            var to   = y+'-'+String(m).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
            $('#lc-inf-from').val(from);
            $('#lc-inf-to').val(to);
            $('#lc-informe-result').html('');
            $('#lc-modal-informe').addClass('open');
        });
        $('#lc-close-informe, #lc-modal-informe').on('click', function(e){ if(e.target===this) $('#lc-modal-informe').removeClass('open'); });
        $('#lc-btn-run-informe').on('click', function(){
            var from = $('#lc-inf-from').val(), to = $('#lc-inf-to').val();
            if (!from || !to) { alert('Completá el rango de fechas.'); return; }
            $('#lc-informe-result').html('<p class="lc-empty">Cargando...</p>');
            $.post(ajaxUrl, {action:'luna_report_payments', nonce, from, to, client_id: $('#lc-inf-client').val()}, function(r){
                if (!r.success) { $('#lc-informe-result').html('<p style="color:#ef4444">'+r.data+'</p>'); return; }
                var rows = r.data.rows, totals = r.data.totals;
                if (!rows.length) { $('#lc-informe-result').html('<p class="lc-empty">Sin registros para ese período.</p>'); return; }
                var h = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">';
                if (totals.facturado.ARS) h += '<span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">🧾 Facturado ARS: $'+fmt(totals.facturado.ARS)+'</span>';
                if (totals.cobrado.ARS) h += '<span style="background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">💵 Cobrado ARS: $'+fmt(totals.cobrado.ARS)+'</span>';
                if (totals.facturado.USD) h += '<span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">🧾 Facturado USD: U$S '+fmt(totals.facturado.USD)+'</span>';
                if (totals.cobrado.USD) h += '<span style="background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:700">💵 Cobrado USD: U$S '+fmt(totals.cobrado.USD)+'</span>';
                h += '</div>';
                h += '<table class="lc-table"><thead><tr><th>Fecha</th><th>Cliente</th><th>Concepto</th><th>Tipo</th><th>Monto</th><th>Moneda</th><th>Estado</th></tr></thead><tbody>';
                rows.forEach(function(p){
                    h += '<tr>';
                    h += '<td style="white-space:nowrap">'+(p.payment_date||p.due_date||'—')+'</td>';
                    h += '<td>'+esc(p.client_name)+'</td>';
                    h += '<td>'+esc(p.concept)+'</td>';
                    h += '<td>'+(p.type === 'cobro' ? '<span style="color:#0f766e;font-weight:700">💵 Cobro</span>' : '<span style="color:#1d4ed8;font-weight:700">🧾 Cargo</span>')+'</td>';
                    h += '<td style="font-weight:700">'+fmt(p.amount)+'</td>';
                    h += '<td>'+esc(p.currency)+'</td>';
                    h += '<td>'+statusBadge(p.status)+'</td>';
                    h += '</tr>';
                });
                h += '</tbody></table>';
                h += '<div style="margin-top:12px;text-align:right;font-size:13px;color:#64748b">';
                if (totals.ARS) h += 'Suma bruta ARS (cargos+cobros): $'+fmt(totals.ARS)+'&nbsp;&nbsp;';
                if (totals.USD) h += 'Suma bruta USD (cargos+cobros): U$S '+fmt(totals.USD);
                h += '</div>';
                $('#lc-informe-result').html(h);
            });
        });

        // ── IMPORTAR CSV ──────────────────────────────────────
        $('#lc-btn-import-csv').on('click', function(){ $('#lc-csv-file').val('').trigger('click'); });
        $('#lc-csv-file').on('change', function(){
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e){
                var lines = e.target.result.split(/\r?\n/).filter(function(l){ return $.trim(l); });
                if (!lines.length) return;

                // Parser CSV que maneja campos entre comillas (ej: "$1,160,000")
                function parseLine(line) {
                    var cols = [], cur = '', inQ = false;
                    for (var i = 0; i < line.length; i++) {
                        var ch = line[i];
                        if (ch === '"') { inQ = !inQ; }
                        else if (ch === ',' && !inQ) { cols.push($.trim(cur)); cur = ''; }
                        else { cur += ch; }
                    }
                    cols.push($.trim(cur));
                    return cols;
                }

                // Convierte DD/M → 2026-MM-DD. Ignora "abonado" y valores no numéricos.
                function toIsoDate(s) {
                    if (!s || /[a-z]/i.test(s)) return '';
                    var p = s.split('/');
                    if (p.length !== 2) return '';
                    var d = parseInt(p[0], 10), m = parseInt(p[1], 10);
                    if (!d || !m || d > 31 || m > 12) return '';
                    return '2026-' + String(m).padStart(2,'0') + '-' + String(d).padStart(2,'0');
                }

                // Extrae el día del mes de DD/M
                function toDay(s) {
                    if (!s || /[a-z]/i.test(s)) return '';
                    var d = parseInt((s.split('/'))[0], 10);
                    return (d >= 1 && d <= 31) ? String(d) : '';
                }

                // Limpia monto: quita $, comas, espacios, comillas
                function toAmount(s) {
                    return String(s || '').replace(/[$,\s"]/g, '');
                }

                // ── Detectar formato del CSV ──────────────────────
                var firstCols = parseLine(lines[0]);
                var headerStr = firstCols.join(',');
                // Formato nuevo: col[0] vacío, header tiene CONTADO/CUOTAS/monto
                // Formato viejo: col[0]=nombre, col[3]=ABONADO, col[4]=A COBRAR
                var isNewFmt = /contado|cuotas/i.test(headerStr);
                var start = (firstCols[0] === '' || /nombre|razon|dominio|vencimiento|contado|cuotas|pago/i.test(headerStr)) ? 1 : 0;

                var rows = [];
                for (var i = start; i < lines.length; i++) {
                    var cols = parseLine(lines[i]);
                    var name = cols[0] || '';
                    if (!name) continue;

                    if (isNewFmt) {
                        // FORMATO NUEVO
                        // col[0]=nombre  col[1]=Vencimiento(DD/M)  col[2]=pago(SI/NO)
                        // col[3]=CONTADO(si/no)  col[4]=CUOTAS(SI)  col[5]=cant_cuotas
                        // col[6]=monto_cuota  col[7]=monto_total
                        var dateStr2 = cols[1] || '';
                        var pagado   = (cols[2] || '').toUpperCase() === 'SI';
                        var hasCuotas = (cols[4] || '').toUpperCase() === 'SI';
                        var nCuotas  = hasCuotas ? Math.max(1, parseInt(cols[5], 10) || 1) : 1;
                        var mCuota   = toAmount(cols[6] || '');
                        var total    = toAmount(cols[7] || '');
                        rows.push({
                            name:            name,
                            domain:          '',
                            renewal_date:    toIsoDate(dateStr2),
                            is_subscription: '0',
                            billing_day:     '',
                            notes:           pagado ? 'PAGO' : 'DEBE',
                            renewal_amount:  total,
                            a_cobrar:        pagado ? '0' : '1',
                            pago_status:     pagado ? 'paid' : 'pending',
                            cuotas:          hasCuotas ? '1' : '0',
                            num_cuotas:      String(nCuotas),
                            monto_cuota:     mCuota,
                            total_amount:    total,
                        });
                    } else {
                        // FORMATO VIEJO (con dominio, ABONADO, A COBRAR)
                        var dateStr3  = cols[2] || '';
                        var dateIsTxt = /abonado|mensual|anual/i.test(dateStr3);
                        var isSub     = (cols[3] || '').toUpperCase() === 'SI';
                        var col4      = (cols[4] || '').toUpperCase();
                        var aCobrar   = (col4 === 'SI' || col4 === 'DEBE');
                        rows.push({
                            name:            name,
                            domain:          cols[1] || '',
                            renewal_date:    dateIsTxt ? '' : toIsoDate(dateStr3),
                            is_subscription: isSub ? '1' : '0',
                            billing_day:     (isSub && !dateIsTxt) ? toDay(dateStr3) : '',
                            notes:           aCobrar ? 'DEBE' : 'PAGO',
                            renewal_amount:  toAmount(cols[5] || ''),
                            a_cobrar:        aCobrar ? '1' : '0',
                            pago_status:     aCobrar ? 'pending' : 'paid',
                            cuotas:          '0',
                            num_cuotas:      '1',
                            monto_cuota:     '',
                            total_amount:    toAmount(cols[5] || ''),
                        });
                    }
                }
                if (!rows.length) return;
                var $msg = $('#lc-import-msg');
                $msg.show().css({background:'#fefce8',border:'1px solid #fde68a',color:'#92400e'}).text('Procesando ' + rows.length + ' filas...');
                $.post(ajaxUrl, {
                    action: 'luna_import_clients',
                    nonce:  nonce,
                    rows:   JSON.stringify(rows),
                }, function(r){
                    if (r.success) {
                        var msg = '✓ Nuevos: ' + r.data.imported + ' · Actualizados: ' + r.data.updated;
                        if (r.data.payments) msg += ' · Cobros creados: ' + r.data.payments;
                        if (r.data.errors) msg += ' · Errores: ' + r.data.errors;
                        $msg.css({background:'#f0fdf4',border:'1px solid #bbf7d0',color:'#166534'}).text(msg);
                        loadClients();
                    } else {
                        $msg.css({background:'#fef2f2',border:'1px solid #fca5a5',color:'#dc2626'}).text('Error: ' + r.data);
                    }
                });
            };
            reader.readAsText(file, 'UTF-8');
        });
        $('#lc-close-client, #lc-modal-client').on('click', function(e){ if(e.target===this) $('#lc-modal-client').removeClass('open'); });
        $('#lc-close-payment, #lc-modal-payment').on('click', function(e){ if(e.target===this) $('#lc-modal-payment').removeClass('open'); });
        $('#lc-btn-close-panel').on('click', function(){ $('#lc-payments-panel').hide(); activeClientId=0; });

        $('#lc-btn-new-payment').on('click', function(){
            if(!activeClientId){ alert('Seleccioná un cliente primero.'); return; }
            openPaymentModal(null);
        });

        // Edit client
        $(document).on('click', '.lc-edit-client', function(){
            var id = $(this).data('id');
            $.post(ajaxUrl, {action:'luna_list_clients', nonce, id}, function(r){
                if(r.success && r.data.length) openClientModal(r.data[0]);
            });
        });

        // View payments
        $(document).on('click', '.lc-view-payments', function(){
            activeClientId   = $(this).data('id');
            activeClientName = $(this).data('name');
            $('#lc-payments-title').text('💰 Pagos de: ' + activeClientName);
            $('#lc-payments-panel').show();
            loadPayments(activeClientId);
            $('html,body').animate({scrollTop: $('#lc-payments-panel').offset().top - 40}, 400);
        });

        // Delete client
        $(document).on('click', '.lc-delete-client', function(){
            if(!confirm('¿Eliminar este cliente y todos sus pagos?')) return;
            $.post(ajaxUrl, {action:'luna_delete_client', nonce, id: $(this).data('id')}, function(r){
                if(r.success) { loadClients(); if($('#lc-payments-panel').is(':visible')) $('#lc-payments-panel').hide(); }
                else alert('Error: ' + r.data);
            });
        });

        // Edit payment
        $(document).on('click', '.lc-edit-payment', function(){
            var id       = $(this).data('id');
            var clientId = $(this).data('client-id') || activeClientId;
            $.post(ajaxUrl, {action:'luna_list_payments', nonce, client_id: clientId}, function(r){
                if(r.success){ var p = r.data.find(function(x){return x.id==id}); if(p) openPaymentModal(p); }
            });
        });

        // Delete payment
        $(document).on('click', '.lc-delete-payment', function(){
            if(!confirm('¿Eliminar este pago?')) return;
            $.post(ajaxUrl, {action:'luna_delete_payment', nonce, id: $(this).data('id')}, function(r){
                if(r.success) { if (activeClientId) loadPayments(activeClientId); loadClients(); }
                else alert('Error: ' + r.data);
            });
        });

        // Marcar como cobrado (acción rápida)
        $(document).on('click', '.lc-mark-paid', function(){
            var id = $(this).data('id');
            $(this).prop('disabled', true).text('...');
            $.post(ajaxUrl, {action:'luna_mark_payment_paid', nonce, id}, function(r){
                if(r.success) { loadPayments(activeClientId); loadClients(); }
                else alert('Error: ' + r.data);
            });
        });

        // Submit client form
        $('#lc-submit-client').on('click', function(){
            var name = $.trim($('#lc-c-name').val());
            if(!name){ $('#lc-client-msg').text('El nombre es obligatorio.').addClass('err'); return; }
            var data = {
                action:'luna_save_client', nonce,
                id:            $('#lc-client-id').val(),
                name:          name,
                domain:        $('#lc-c-domain').val(),
                cuit:          $('#lc-c-cuit').val(),
                iva_condition: $('#lc-c-iva').val(),
                email:         $('#lc-c-email').val(),
                phone:         $('#lc-c-phone').val(),
                address:       $('#lc-c-address').val(),
                city:          $('#lc-c-city').val(),
                notes:         $('#lc-c-notes').val(),
                subscription_type: $('#lc-c-subscription').val(),
                billing_day:       $('#lc-c-billing-day').val() || '',
            };
            $.post(ajaxUrl, data, function(r){
                if(r.success){
                    $('#lc-client-msg').text('✓ Guardado').addClass('ok');
                    setTimeout(function(){ $('#lc-modal-client').removeClass('open'); loadClients(); }, 800);
                } else {
                    $('#lc-client-msg').text('Error: '+r.data).addClass('err');
                }
            });
        });

        // Submit payment form
        $('#lc-submit-payment').on('click', function(){
            var clientId = parseInt($('#lc-payment-client-id').val(), 10) || 0;
            var concept  = $.trim($('#lc-p-concept').val());
            var amount   = parseFloat($('#lc-p-amount').val());
            if(!clientId){ $('#lc-payment-msg').text('Seleccioná un cliente.').addClass('err'); return; }
            if(!concept){ $('#lc-payment-msg').text('El concepto es obligatorio.').addClass('err'); return; }
            if(isNaN(amount)||amount<0){ $('#lc-payment-msg').text('Ingresá un monto válido.').addClass('err'); return; }
            var installments = 1;
            var instType = $('input[name=lc-p-inst-type]:checked').val();
            if (!$('#lc-payment-id').val() && instType === 'n') {
                installments = Math.max(2, parseInt($('#lc-p-inst-n').val(), 10) || 2);
            }
            var pType = $('input[name=lc-p-type]:checked').val() || 'cargo';
            var data = {
                action:'luna_save_payment', nonce,
                id:             $('#lc-payment-id').val(),
                client_id:      clientId,
                type:           pType,
                concept:        concept,
                amount:         amount,
                currency:       $('#lc-p-currency').val(),
                payment_date:   $('#lc-p-date').val(),
                due_date:       $('#lc-p-due').val(),
                method:         $('#lc-p-method').val(),
                status:         pType === 'cobro' ? 'paid' : $('#lc-p-status').val(),
                invoice_number: $('#lc-p-invoice').val(),
                workspace_id:   $('#lc-p-workspace').val(),
                notes:          $('#lc-p-notes').val(),
                installments:   installments,
            };
            $.post(ajaxUrl, data, function(r){
                if(r.success){
                    var msg = installments > 1 ? '✓ '+installments+' cuotas creadas' : '✓ Guardado';
                    $('#lc-payment-msg').text(msg).addClass('ok');
                    if (pType === 'cargo' && paymentReasons.indexOf(concept) === -1) {
                        paymentReasons.push(concept);
                    }
                    setTimeout(function(){
                        $('#lc-modal-payment').removeClass('open');
                        loadClients();
                        if ($('#lc-payments-panel').is(':visible') && activeClientId === clientId) loadPayments(activeClientId);
                    }, 800);
                } else {
                    $('#lc-payment-msg').text('Error: '+r.data).addClass('err');
                }
            });
        });

        // Save provider data
        $('#lc-save-provider').on('click', function(){
            $.post(ajaxUrl, {
                action:'luna_save_client', nonce,
                _provider: 1,
                provider_name:  $('#lc-prov-name').val(),
                provider_cuit:  $('#lc-prov-cuit').val(),
                provider_email: $('#lc-prov-email').val(),
                provider_phone: $('#lc-prov-phone').val(),
            }, function(r){
                if(r.success){
                    providerData = { name:$('#lc-prov-name').val(), cuit:$('#lc-prov-cuit').val(), email:$('#lc-prov-email').val(), phone:$('#lc-prov-phone').val() };
                    $('#lc-prov-msg').text('✓ Guardado').show();
                    setTimeout(function(){ $('#lc-prov-msg').text('') }, 2000);
                }
            });
        });

        // Escape key closes modals
        $(document).on('keydown', function(e){ if(e.key==='Escape'){ $('.lc-overlay').removeClass('open'); } });

        loadClients();
        })(jQuery);
        </script>
        <?php
    }

    // ── COBRANZAS — planilla de facturación y registro de pagos ──────────────
    // Rediseño a pedido: sin Debe/Haber/Saldo. Una fila por cliente con su
    // vencimiento y monto del año; "Registrar pago" en contado o en cuotas
    // (cantidad, monto y fecha de cada cuota definidos por el usuario); y un
    // registro de pagos día por día donde CADA línea es editable/eliminable.
    // Cabecera: Total a cobrar (año) y Total cobrado a la fecha.
    // Modelo de datos: luna_payments — type='cargo' (lo que debe pagar el
    // cliente en el año) y type='cobro' (pagos; status 'paid'=cobrado,
    // 'pending'=cuota con fecha probable, aún no cobrada).
    public function render_cobranzas_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap" style="max-width:1200px">
        <style>
        .cz-title{font-size:1.4rem;font-weight:700;color:#1e1e1e;margin-bottom:20px}
        .cz-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
        .cz-table th{background:#f1f5f9;padding:10px 14px;text-align:left;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0}
        .cz-table td{padding:8px 14px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}
        .cz-table tr:last-child td{border-bottom:none}
        .cz-num{text-align:right;font-variant-numeric:tabular-nums}
        .cz-input{border:1px solid #cbd5e1;border-radius:5px;padding:6px 8px;font-size:12px}
        .cz-input.n{width:110px;text-align:right}
        .cz-input.d{width:135px}
        .cz-select{border:1px solid #cbd5e1;border-radius:5px;padding:6px 6px;font-size:12px}
        .cz-btn{background:#5b6af0;color:#fff;border:none;border-radius:5px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer}
        .cz-btn:hover{background:#4a59d0}
        .cz-btn:disabled{opacity:.6;cursor:default}
        .cz-btn-sm{padding:4px 9px;font-size:11px}
        .cz-btn-ghost{background:transparent;color:#5b6af0;border:1px solid #5b6af0}
        .cz-btn-danger{background:#ef4444}
        .cz-btn-danger:hover{background:#dc2626}
        .cz-btn-green{background:#16a34a}
        .cz-btn-green:hover{background:#15803d}
        .cz-empty{text-align:center;color:#94a3b8;padding:32px;font-size:13px}
        .cz-kpis{display:flex;gap:16px;margin-bottom:22px;flex-wrap:wrap}
        .cz-kpi{border-radius:8px;padding:12px 20px}
        .cz-kpi-label{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
        .cz-kpi-value{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums}
        .cz-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700}
        .cz-badge.pagado{background:#dcfce7;color:#16a34a}
        .cz-badge.pendiente{background:#fef9c3;color:#a16207}
        .cz-badge.cuotas{background:#dbeafe;color:#1d4ed8}
        .cz-badge.parcial{background:#fce7f3;color:#9d174d}
        /* Modal registrar pago */
        .cz-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center}
        .cz-overlay.open{display:flex}
        .cz-modal{background:#fff;border-radius:12px;padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative}
        .cz-modal h3{font-size:1.05rem;font-weight:700;margin:0 0 16px;color:#1e1e1e}
        .cz-close{position:absolute;top:12px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1}
        .cz-fg{margin-bottom:12px}
        .cz-fg label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
        .cz-tipo-btn{flex:1;padding:9px;border-radius:6px;border:1.5px solid #cbd5e1;background:#fff;font-size:13px;font-weight:600;cursor:pointer;color:#475569}
        .cz-tipo-btn.on{border-color:#5b6af0;background:#eef0fe;color:#5b6af0}
        </style>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px">
            <div class="cz-title" style="margin-bottom:0">💵 Cobranzas</div>
            <button class="cz-btn cz-btn-sm cz-btn-ghost" id="cz-btn-import" style="margin-left:auto">📥 Importar histórico (CSV)</button>
            <input type="file" id="cz-import-file" accept=".csv" style="display:none">
        </div>
        <p style="font-size:11px;color:#94a3b8;margin:0 0 20px">
            CSV: <code>cliente,dia,mes,monto,estado</code> (estado <code>PAGO</code> o <code>DEBE</code>). No duplica si se reimporta.
        </p>

        <div class="cz-kpis">
            <div class="cz-kpi" style="background:#eef0fe;border:1px solid #c3c9f7">
                <div class="cz-kpi-label" id="cz-kpi-acobrar-label">Total a cobrar (año)</div>
                <div class="cz-kpi-value" id="cz-kpi-acobrar" style="color:#4a59d0">$0</div>
            </div>
            <div class="cz-kpi" style="background:#f0fdf4;border:1px solid #bbf7d0">
                <div class="cz-kpi-label">Total cobrado a la fecha</div>
                <div class="cz-kpi-value" id="cz-kpi-cobrado" style="color:#16a34a">$0</div>
            </div>
        </div>

        <div class="cz-title" style="font-size:1.1rem">📋 Clientes del año</div>
        <div id="cz-wrap"><p class="cz-empty">Cargando...</p></div>

        <div class="cz-title" style="margin-top:34px;font-size:1.1rem">🗓 Registro de pagos — día por día</div>
        <p style="font-size:12px;color:#64748b;margin:0 0 12px">
            Todos los pagos ordenados por fecha. Cada línea es editable con el lápiz o eliminable.
            Las cuotas pendientes se marcan cobradas con el botón verde.
        </p>
        <div id="cz-pagos-wrap"><p class="cz-empty">Cargando...</p></div>
        <div id="cz-msg" style="margin-top:10px;font-size:12px;min-height:16px"></div>

        <!-- Modal: registrar pago -->
        <div class="cz-overlay" id="cz-modal-pago">
          <div class="cz-modal">
            <button class="cz-close" onclick="jQuery('#cz-modal-pago').removeClass('open')">×</button>
            <h3 id="czp-title">Registrar pago</h3>
            <input type="hidden" id="czp-client-id">
            <div class="cz-fg">
              <label>Tipo de pago</label>
              <div style="display:flex;gap:8px">
                <button class="cz-tipo-btn on" id="czp-tab-contado" onclick="czpSetTipo('contado')">Contado</button>
                <button class="cz-tipo-btn" id="czp-tab-cuotas" onclick="czpSetTipo('cuotas')">En cuotas</button>
              </div>
            </div>
            <div class="cz-fg">
              <label>Forma de pago</label>
              <select class="cz-select" id="czp-forma" style="width:100%"><option>Transferencia</option><option>Efectivo</option><option>MercadoPago</option><option>Tarjeta</option><option>Otro</option></select>
            </div>

            <!-- Contado -->
            <div id="czp-panel-contado">
              <div style="display:flex;gap:10px">
                <div class="cz-fg" style="flex:1"><label>Monto</label><input type="number" min="0" step="1" class="cz-input" id="czp-monto" style="width:100%"></div>
                <div class="cz-fg" style="flex:1"><label>Fecha del pago</label><input type="date" class="cz-input" id="czp-fecha" style="width:100%"></div>
              </div>
            </div>

            <!-- Cuotas -->
            <div id="czp-panel-cuotas" style="display:none">
              <div class="cz-fg">
                <label>Cantidad de cuotas</label>
                <input type="number" min="1" max="36" class="cz-input" id="czp-ncuotas" value="2" style="width:90px" onchange="czpRenderCuotas()">
              </div>
              <div id="czp-cuotas-rows"></div>
              <p style="font-size:11px;color:#94a3b8;margin:4px 0 0">Definí el monto y la fecha probable de cobranza de cada cuota. Todo editable después, línea por línea.</p>
            </div>

            <button class="cz-btn" id="czp-guardar" style="width:100%;margin-top:10px;padding:10px" onclick="czpGuardar()">Guardar</button>
            <div id="czp-msg" style="margin-top:8px;font-size:12px;min-height:16px;text-align:center"></div>
          </div>
        </div>
        </div>
        <script>
        (function($){
        var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')) ?>;
        var nonce   = <?php echo json_encode(wp_create_nonce('luna_admin_nonce')) ?>;
        var czClientes = [], czPagos = [];

        function czFmt(v){ return Math.round(parseFloat(v)||0).toLocaleString('es-AR'); }
        function esc2(s){ var d=document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
        function czMsg(txt, err){ $('#cz-msg').css('color', err?'#dc2626':'#16a34a').text(txt); if(!err) setTimeout(function(){ $('#cz-msg').text(''); }, 2500); }
        function hoyISO(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }

        function loadAll(){ loadKpis(); loadClientes(); loadPagos(); }

        function loadKpis(){
            $.post(ajaxUrl, {action:'luna_cobranzas_kpis', nonce}, function(r){
                if (!r.success) return;
                $('#cz-kpi-acobrar-label').text('Total a cobrar ('+r.data.anio+')');
                $('#cz-kpi-acobrar').text('$'+czFmt(r.data.a_cobrar));
                $('#cz-kpi-cobrado').text('$'+czFmt(r.data.cobrado));
            });
        }

        // ── Planilla de clientes del año ─────────────────────────
        function loadClientes(){
            $.post(ajaxUrl, {action:'luna_cobranzas_list', nonce}, function(r){
                if (!r.success) { $('#cz-wrap').html('<p class="cz-empty">Error: '+esc2(r.data)+'</p>'); return; }
                czClientes = r.data;
                renderClientes();
            }).fail(function(){ $('#cz-wrap').html('<p class="cz-empty">Error de conexión.</p>'); });
        }
        function estadoBadge(c){
            var monto = parseFloat(c.monto_anual)||0, cobrado = parseFloat(c.cobrado)||0, pend = parseInt(c.cuotas_pendientes)||0;
            if (monto > 0 && cobrado >= monto) return '<span class="cz-badge pagado">Pagado</span>';
            if (pend > 0) return '<span class="cz-badge cuotas">En cuotas ('+pend+' pend.)</span>';
            if (cobrado > 0) return '<span class="cz-badge parcial">Parcial</span>';
            return '<span class="cz-badge pendiente">Pendiente</span>';
        }
        function renderClientes(){
            var el = $('#cz-wrap');
            if (!czClientes.length) { el.html('<p class="cz-empty">Sin clientes activos aún. Crealos en la pestaña "Clientes" o importá el CSV.</p>'); return; }
            var h = '<table class="cz-table"><thead><tr>'
                + '<th>Cliente</th><th>Vencimiento</th><th class="cz-num">Monto</th>'
                + '<th class="cz-num">Cobrado</th><th>Estado</th><th></th>'
                + '</tr></thead><tbody>';
            czClientes.forEach(function(c){
                var venc = c.vencimiento ? c.vencimiento.split('-').reverse().join('/') : '—';
                h += '<tr>'
                   + '<td><strong>'+esc2(c.name)+'</strong>'+(c.domain?' <span style="color:#94a3b8;font-size:11px">('+esc2(c.domain)+')</span>':'')+'</td>'
                   + '<td>'+venc+'</td>'
                   + '<td class="cz-num">'+(parseFloat(c.monto_anual)?'$'+czFmt(c.monto_anual):'—')+'</td>'
                   + '<td class="cz-num" style="color:#16a34a">'+(parseFloat(c.cobrado)?'$'+czFmt(c.cobrado):'')+'</td>'
                   + '<td>'+estadoBadge(c)+'</td>'
                   + '<td style="text-align:right"><button class="cz-btn cz-btn-sm" onclick="czpOpen('+c.id+')">Registrar pago</button></td>'
                   + '</tr>';
            });
            h += '</tbody></table>';
            el.html(h);
        }

        // ── Modal registrar pago ─────────────────────────────────
        var czpTipo = 'contado';
        window.czpOpen = function(clientId){
            var c = czClientes.find(function(x){ return x.id == clientId; });
            if (!c) return;
            $('#czp-client-id').val(clientId);
            $('#czp-title').text('Registrar pago — ' + c.name);
            var resto = Math.max(0, (parseFloat(c.monto_anual)||0) - (parseFloat(c.cobrado)||0));
            $('#czp-monto').val(resto || '');
            $('#czp-fecha').val(hoyISO());
            $('#czp-ncuotas').val(2);
            $('#czp-msg').text('');
            czpSetTipo('contado');
            $('#cz-modal-pago').addClass('open');
        };
        window.czpSetTipo = function(t){
            czpTipo = t;
            $('#czp-tab-contado').toggleClass('on', t==='contado');
            $('#czp-tab-cuotas').toggleClass('on', t==='cuotas');
            $('#czp-panel-contado').toggle(t==='contado');
            $('#czp-panel-cuotas').toggle(t==='cuotas');
            if (t==='cuotas') czpRenderCuotas();
        };
        window.czpRenderCuotas = function(){
            var n = Math.max(1, Math.min(36, parseInt($('#czp-ncuotas').val(),10)||1));
            var cid = parseInt($('#czp-client-id').val(),10);
            var c = czClientes.find(function(x){ return x.id == cid; }) || {};
            var resto = Math.max(0, (parseFloat(c.monto_anual)||0) - (parseFloat(c.cobrado)||0));
            var montoDef = resto > 0 ? Math.round(resto/n) : '';
            var h = '';
            var base = new Date();
            for (var i=0; i<n; i++){
                var f = new Date(base.getFullYear(), base.getMonth()+i, base.getDate());
                var fIso = f.getFullYear()+'-'+String(f.getMonth()+1).padStart(2,'0')+'-'+String(f.getDate()).padStart(2,'0');
                h += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">'
                   + '<span style="font-size:11px;color:#94a3b8;width:60px">Cuota '+(i+1)+'</span>'
                   + '<input type="number" min="0" step="1" class="cz-input n czp-c-monto" value="'+montoDef+'" placeholder="Monto">'
                   + '<input type="date" class="cz-input d czp-c-fecha" value="'+fIso+'">'
                   + '</div>';
            }
            $('#czp-cuotas-rows').html(h);
        };
        window.czpGuardar = function(){
            var cid   = parseInt($('#czp-client-id').val(),10);
            var forma = $('#czp-forma').val();
            var msg   = $('#czp-msg');
            var payload = {action:'luna_cobranzas_registrar_pago', nonce, client_id: cid, tipo: czpTipo, forma: forma};
            if (czpTipo === 'contado'){
                var monto = parseFloat($('#czp-monto').val())||0;
                var fecha = $('#czp-fecha').val();
                if (monto<=0){ msg.css('color','#dc2626').text('Ingresá un monto válido.'); return; }
                if (!fecha){ msg.css('color','#dc2626').text('Ingresá la fecha del pago.'); return; }
                payload.monto = monto; payload.fecha = fecha;
            } else {
                var cuotas = [];
                var err = '';
                $('#czp-cuotas-rows > div').each(function(i){
                    var m = parseFloat($(this).find('.czp-c-monto').val())||0;
                    var f = $(this).find('.czp-c-fecha').val();
                    if (m<=0) err = 'La cuota '+(i+1)+' no tiene monto válido.';
                    if (!f)   err = 'La cuota '+(i+1)+' no tiene fecha.';
                    cuotas.push({monto:m, fecha:f});
                });
                if (err){ msg.css('color','#dc2626').text(err); return; }
                payload.cuotas = JSON.stringify(cuotas);
            }
            $('#czp-guardar').prop('disabled', true).text('Guardando...');
            $.post(ajaxUrl, payload, function(r){
                $('#czp-guardar').prop('disabled', false).text('Guardar');
                if (!r.success){ msg.css('color','#dc2626').text('Error: '+r.data); return; }
                $('#cz-modal-pago').removeClass('open');
                czMsg('✓ Pago registrado');
                loadAll();
            }).fail(function(){
                $('#czp-guardar').prop('disabled', false).text('Guardar');
                msg.css('color','#dc2626').text('Error de conexión.');
            });
        };

        // ── Registro de pagos día por día ────────────────────────
        function loadPagos(){
            $.post(ajaxUrl, {action:'luna_cobranzas_pagos', nonce}, function(r){
                if (!r.success) { $('#cz-pagos-wrap').html('<p class="cz-empty">Error: '+esc2(r.data)+'</p>'); return; }
                czPagos = r.data;
                renderPagos();
            }).fail(function(){ $('#cz-pagos-wrap').html('<p class="cz-empty">Error de conexión.</p>'); });
        }
        function renderPagos(){
            var el = $('#cz-pagos-wrap');
            if (!czPagos.length) { el.html('<p class="cz-empty">Sin pagos registrados aún.</p>'); return; }
            var h = '<div style="overflow-x:auto"><table class="cz-table"><thead><tr>'
                + '<th>Fecha</th><th>Cliente</th><th>Concepto</th><th class="cz-num">Monto</th>'
                + '<th>Forma de pago</th><th>Estado</th><th></th>'
                + '</tr></thead><tbody>';
            czPagos.forEach(function(p){
                var fecha = p.fecha ? p.fecha.split('-').reverse().join('/') : '—';
                var estado = p.estado === 'paid'
                    ? '<span class="cz-badge pagado">Cobrado</span>'
                    : '<span class="cz-badge pendiente">Pendiente</span>';
                h += '<tr data-id="'+p.id+'">'
                   + '<td class="v-fecha">'+fecha+'</td>'
                   + '<td>'+esc2(p.cliente)+'</td>'
                   + '<td class="v-concepto">'+esc2(p.concepto||'—')+'</td>'
                   + '<td class="cz-num v-monto">$'+czFmt(p.monto)+'</td>'
                   + '<td class="v-forma">'+esc2(p.forma||'—')+'</td>'
                   + '<td class="v-estado">'+estado+'</td>'
                   + '<td style="white-space:nowrap;text-align:right">'
                   + (p.estado!=='paid' ? '<button class="cz-btn cz-btn-sm cz-btn-green cz-cobrar" title="Marcar cobrada">✓ Cobrar</button> ' : '')
                   + '<button class="cz-btn cz-btn-sm cz-btn-ghost cz-edit" title="Editar">✎</button> '
                   + '<button class="cz-btn cz-btn-sm cz-btn-danger cz-del" title="Eliminar">🗑</button>'
                   + '</td></tr>';
            });
            h += '</tbody></table></div>';
            el.html(h);
        }
        function pagoById(id){ return czPagos.find(function(x){ return x.id == id; }); }

        $(document).on('click', '.cz-cobrar', function(){
            var id = $(this).closest('tr').data('id');
            var p = pagoById(id); if (!p) return;
            $.post(ajaxUrl, {action:'luna_cobranzas_update_mov', nonce, id:id, fecha:hoyISO(), concepto:p.concepto, monto:p.monto, forma:p.forma, estado:'paid'}, function(r){
                if (!r.success){ czMsg('Error: '+r.data, true); return; }
                czMsg('✓ Cuota cobrada');
                loadAll();
            });
        });

        $(document).on('click', '.cz-edit', function(){
            var tr = $(this).closest('tr');
            var id = tr.data('id');
            var p = pagoById(id); if (!p) return;
            tr.find('.v-fecha').html('<input type="date" class="cz-input d e-fecha" value="'+esc2(p.fecha||'')+'">');
            tr.find('.v-concepto').html('<input type="text" class="cz-input e-concepto" style="width:100%" value="'+esc2(p.concepto||'')+'">');
            tr.find('.v-monto').html('<input type="number" min="0" step="1" class="cz-input n e-monto" value="'+p.monto+'">');
            tr.find('.v-forma').html('<input type="text" class="cz-input e-forma" style="width:110px" value="'+esc2(p.forma||'')+'">');
            tr.find('.v-estado').html('<select class="cz-select e-estado"><option value="paid"'+(p.estado==='paid'?' selected':'')+'>Cobrado</option><option value="pending"'+(p.estado!=='paid'?' selected':'')+'>Pendiente</option></select>');
            $(this).replaceWith('<button class="cz-btn cz-btn-sm cz-save" title="Guardar">✓</button>');
        });

        $(document).on('click', '.cz-save', function(){
            var tr = $(this).closest('tr');
            var id = tr.data('id');
            var data = {
                action:'luna_cobranzas_update_mov', nonce, id:id,
                fecha: tr.find('.e-fecha').val(),
                concepto: tr.find('.e-concepto').val(),
                monto: tr.find('.e-monto').val(),
                forma: tr.find('.e-forma').val(),
                estado: tr.find('.e-estado').val(),
            };
            if (!data.fecha){ czMsg('La fecha es obligatoria.', true); return; }
            $.post(ajaxUrl, data, function(r){
                if (!r.success){ czMsg('Error: '+r.data, true); return; }
                czMsg('✓ Pago actualizado');
                loadAll();
            });
        });

        $(document).on('click', '.cz-del', function(){
            if (!confirm('¿Eliminar este pago? No se puede deshacer.')) return;
            var id = $(this).closest('tr').data('id');
            $.post(ajaxUrl, {action:'luna_cobranzas_delete_mov', nonce, id:id}, function(r){
                if (!r.success){ czMsg('Error: '+r.data, true); return; }
                czMsg('✓ Pago eliminado');
                loadAll();
            });
        });

        // ── Importar histórico (CSV: cliente,dia,mes,monto,estado) ──
        $('#cz-btn-import').on('click', function(){ $('#cz-import-file').val('').trigger('click'); });
        $('#cz-import-file').on('change', function(){
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e){
                var lines = e.target.result.split(/\r?\n/).filter(function(l){ return $.trim(l); });
                if (!lines.length) return;
                function parseLine(line){
                    var cols=[], cur='', inQ=false;
                    for (var i=0;i<line.length;i++){
                        var ch=line[i];
                        if (ch==='"'){ inQ=!inQ; }
                        else if (ch===',' && !inQ){ cols.push($.trim(cur)); cur=''; }
                        else { cur+=ch; }
                    }
                    cols.push($.trim(cur));
                    return cols;
                }
                var first = parseLine(lines[0]);
                var start = /cliente|dia|mes|monto|estado/i.test(first.join(',')) ? 1 : 0;
                var rows = [];
                for (var i=start; i<lines.length; i++){
                    var c = parseLine(lines[i]);
                    if (!c[0]) continue;
                    rows.push({cliente:c[0], dia:c[1], mes:c[2], monto:(c[3]||'').replace(/[$,\s"]/g,''), estado:c[4]});
                }
                if (!rows.length) { alert('El archivo no tiene filas válidas.'); return; }
                czMsg('Importando ' + rows.length + ' filas...');
                $.post(ajaxUrl, {action:'luna_cobranzas_import', nonce, rows: JSON.stringify(rows)}, function(r){
                    if (!r.success) { czMsg('Error: '+r.data, true); return; }
                    czMsg('✓ Clientes creados: '+r.data.clientes_creados+' · Cargos: '+r.data.cargos+' · Cobros: '+r.data.cobros+(r.data.omitidos?' · Omitidos: '+r.data.omitidos:''));
                    loadAll();
                }).fail(function(){ czMsg('Error de conexión.', true); });
            };
            reader.readAsText(file);
        });

        loadAll();
        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Cobranzas — clientes con su cargo del año, cobrado y estado ────
    public function ajax_cobranzas_list() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        $anio = date('Y');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.name, c.domain,
                COALESCE(SUM(CASE WHEN pm.type='cargo' THEN pm.amount ELSE 0 END),0) AS monto_anual,
                COALESCE(SUM(CASE WHEN pm.type='cobro' AND pm.status='paid' THEN pm.amount ELSE 0 END),0) AS cobrado,
                COALESCE(SUM(CASE WHEN pm.type='cobro' AND pm.status='pending' THEN 1 ELSE 0 END),0) AS cuotas_pendientes,
                MIN(CASE WHEN pm.type='cargo' THEN COALESCE(pm.due_date, pm.payment_date) END) AS vencimiento
             FROM `{$p}clients` c
             LEFT JOIN `{$p}payments` pm ON pm.client_id = c.id
                  AND YEAR(COALESCE(pm.payment_date, pm.due_date)) = %d
             WHERE c.active = 1
             GROUP BY c.id
             ORDER BY (MIN(CASE WHEN pm.type='cargo' THEN COALESCE(pm.due_date, pm.payment_date) END) IS NULL),
                      MIN(CASE WHEN pm.type='cargo' THEN COALESCE(pm.due_date, pm.payment_date) END), c.name",
            $anio
        ), ARRAY_A);
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: Cobranzas — registrar pago (contado o en cuotas) ───────────────
    // Contado: un cobro pagado con fecha y monto. Cuotas: N cobros pendientes,
    // cada uno con el monto y la fecha probable que definió el usuario.
    // Si el cliente no tiene cargo este año, se crea automáticamente por el
    // total (mantiene coherentes el estado y el "Total a cobrar").
    public function ajax_cobranzas_registrar_pago() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';

        $client_id = (int) ($_POST['client_id'] ?? 0);
        $tipo      = ($_POST['tipo'] ?? '') === 'cuotas' ? 'cuotas' : 'contado';
        $forma     = sanitize_text_field($_POST['forma'] ?? '');
        if (!$client_id) wp_send_json_error('Cliente inválido.');

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM `{$p}clients` WHERE id=%d AND active=1", $client_id
        ), ARRAY_A);
        if (!$client) wp_send_json_error('Cliente no encontrado.');

        $now  = current_time('mysql');
        $anio = date('Y');
        $total_nuevo = 0.0;

        if ($tipo === 'contado') {
            $monto = (float) ($_POST['monto'] ?? 0);
            $fecha = sanitize_text_field($_POST['fecha'] ?? '');
            if ($monto <= 0) wp_send_json_error('Ingresá un monto válido.');
            if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) wp_send_json_error('Fecha inválida.');
            $wpdb->insert("{$p}payments", [
                'client_id' => $client_id, 'concept' => 'Pago contado', 'amount' => $monto,
                'currency' => 'ARS', 'payment_date' => $fecha, 'method' => $forma,
                'status' => 'paid', 'type' => 'cobro', 'created_at' => $now,
            ]);
            $total_nuevo = $monto;
        } else {
            $cuotas = json_decode(stripslashes($_POST['cuotas'] ?? '[]'), true);
            if (!is_array($cuotas) || !count($cuotas)) wp_send_json_error('Definí al menos una cuota.');
            if (count($cuotas) > 36) wp_send_json_error('Máximo 36 cuotas.');
            $n = count($cuotas);
            foreach ($cuotas as $i => $q) {
                $m = (float) ($q['monto'] ?? 0);
                $f = sanitize_text_field($q['fecha'] ?? '');
                if ($m <= 0) wp_send_json_error('La cuota ' . ($i + 1) . ' no tiene monto válido.');
                if (!$f || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) wp_send_json_error('La cuota ' . ($i + 1) . ' no tiene fecha válida.');
            }
            foreach ($cuotas as $i => $q) {
                $wpdb->insert("{$p}payments", [
                    'client_id' => $client_id,
                    'concept' => 'Cuota ' . ($i + 1) . '/' . $n,
                    'amount' => (float) $q['monto'],
                    'currency' => 'ARS',
                    'payment_date' => sanitize_text_field($q['fecha']),
                    'method' => $forma,
                    'status' => 'pending',
                    'type' => 'cobro',
                    'created_at' => $now,
                ]);
                $total_nuevo += (float) $q['monto'];
            }
        }

        // Si el cliente no tiene cargo este año, crearlo por el total del pago
        // para que "Total a cobrar" y el estado queden coherentes.
        $tiene_cargo = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$p}payments`
             WHERE client_id=%d AND type='cargo'
               AND YEAR(COALESCE(due_date, payment_date)) = %d LIMIT 1",
            $client_id, $anio
        ));
        if (!$tiene_cargo && $total_nuevo > 0) {
            $wpdb->insert("{$p}payments", [
                'client_id' => $client_id, 'concept' => 'Cargo del año', 'amount' => $total_nuevo,
                'currency' => 'ARS', 'due_date' => date('Y-m-d'), 'status' => 'pending',
                'type' => 'cargo', 'created_at' => $now,
            ]);
        }

        wp_send_json_success();
    }

    // ── AJAX: Cobranzas — registro de pagos día por día (solo cobros) ────────
    public function ajax_cobranzas_pagos() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        $rows = $wpdb->get_results(
            "SELECT pm.id, COALESCE(pm.payment_date, pm.due_date) AS fecha, pm.concept AS concepto,
                    pm.amount AS monto, pm.method AS forma, pm.status AS estado,
                    c.name AS cliente
             FROM `{$p}payments` pm
             JOIN `{$p}clients` c ON c.id = pm.client_id
             WHERE pm.type = 'cobro'
             ORDER BY COALESCE(pm.payment_date, pm.due_date) DESC, pm.id DESC",
            ARRAY_A
        );
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: Cobranzas — KPIs de cabecera ───────────────────────────────────
    // Total a cobrar (año) = suma de todos los cargos del año en curso.
    // Total cobrado a la fecha = cobros pagados del año, hasta hoy inclusive.
    public function ajax_cobranzas_kpis() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        $anio = date('Y');

        $a_cobrar = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM `{$p}payments`
             WHERE type='cargo' AND YEAR(COALESCE(due_date, payment_date)) = %d",
            $anio
        ));
        $cobrado = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM `{$p}payments`
             WHERE type='cobro' AND status='paid'
               AND YEAR(payment_date) = %d AND payment_date <= CURDATE()",
            $anio
        ));
        wp_send_json_success(['a_cobrar' => $a_cobrar, 'cobrado' => $cobrado, 'anio' => $anio]);
    }

    // ── AJAX: Cobranzas — editar una línea de pago ───────────────────────────
    public function ajax_cobranzas_update_mov() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';

        $id      = (int) ($_POST['id'] ?? 0);
        $fecha   = sanitize_text_field($_POST['fecha'] ?? '');
        $concept = sanitize_text_field($_POST['concepto'] ?? '');
        $monto   = (float) ($_POST['monto'] ?? 0);
        $forma   = sanitize_text_field($_POST['forma'] ?? '');
        $estado  = ($_POST['estado'] ?? '') === 'paid' ? 'paid' : 'pending';

        if (!$id) wp_send_json_error('Pago inválido.');
        if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) wp_send_json_error('La fecha es obligatoria.');
        if ($monto <= 0) wp_send_json_error('El monto debe ser mayor a 0.');

        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, type FROM `{$p}payments` WHERE id=%d", $id), ARRAY_A);
        if (!$existing) wp_send_json_error('Pago no encontrado.');
        if ($existing['type'] !== 'cobro') wp_send_json_error('Solo se editan líneas de pago desde acá.');

        $wpdb->update("{$p}payments", [
            'payment_date' => $fecha, 'concept' => $concept, 'amount' => $monto,
            'method' => $forma, 'status' => $estado,
        ], ['id' => $id]);
        wp_send_json_success();
    }

    // ── AJAX: Cobranzas — eliminar una línea de pago ─────────────────────────
    public function ajax_cobranzas_delete_mov() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Pago inválido.');
        $wpdb->delete("{$p}payments", ['id' => $id]);
        wp_send_json_success();
    }

    // ── AJAX: Cobranzas — importar histórico de vencimientos (CSV genérico) ──
    // Formato: cliente,dia,mes,monto,estado (estado = PAGO|DEBE). El día/mes se
    // toma como referencia del ciclo recurrente anual (se ignora cualquier año
    // que venga en el origen de los datos, ya que se repite todos los años).
    // Crea el cliente si no existe (match case-insensitive por nombre) y agrega
    // el cargo (lo que debe pagar), más el pago cobrado si estado=PAGO.
    // Idempotente: reimportar el mismo archivo no duplica movimientos.
    public function ajax_cobranzas_import() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';

        $rows = json_decode(stripslashes($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows) || !$rows) wp_send_json_error('Sin filas para importar.');

        $anio = date('Y');
        $clientes_creados = 0; $cargos = 0; $cobros = 0; $omitidos = 0;

        foreach ($rows as $r) {
            $nombre = sanitize_text_field(trim($r['cliente'] ?? ''));
            $dia    = (int) ($r['dia'] ?? 0);
            $mes    = (int) ($r['mes'] ?? 0);
            $monto  = (float) ($r['monto'] ?? 0);
            $estado = strtoupper(sanitize_text_field($r['estado'] ?? ''));
            if (!$nombre || $dia < 1 || $dia > 31 || $mes < 1 || $mes > 12 || $monto <= 0) { $omitidos++; continue; }

            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

            $client = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM `{$p}clients` WHERE LOWER(name) = LOWER(%s) LIMIT 1", $nombre
            ), ARRAY_A);
            if ($client) {
                $client_id = (int) $client['id'];
            } else {
                $now = current_time('mysql');
                $wpdb->insert("{$p}clients", [
                    'name' => $nombre, 'active' => 1,
                    'renewal_date' => $fecha, 'renewal_amount' => $monto,
                    'subscription_type' => 'none', 'is_subscription' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $client_id = $wpdb->insert_id;
                $clientes_creados++;
            }

            $ya_cargo = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$p}payments` WHERE client_id=%d AND type='cargo' AND amount=%f AND due_date=%s LIMIT 1",
                $client_id, $monto, $fecha
            ));
            if (!$ya_cargo) {
                $wpdb->insert("{$p}payments", [
                    'client_id' => $client_id, 'concept' => 'Renovación anual', 'amount' => $monto,
                    'currency' => 'ARS', 'due_date' => $fecha, 'status' => 'pending',
                    'type' => 'cargo', 'created_at' => current_time('mysql'),
                ]);
                $cargos++;
            }

            if ($estado === 'PAGO') {
                $ya_cobro = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$p}payments` WHERE client_id=%d AND type='cobro' AND amount=%f AND payment_date=%s LIMIT 1",
                    $client_id, $monto, $fecha
                ));
                if (!$ya_cobro) {
                    $wpdb->insert("{$p}payments", [
                        'client_id' => $client_id, 'concept' => 'Pago renovación', 'amount' => $monto,
                        'currency' => 'ARS', 'payment_date' => $fecha, 'method' => '',
                        'status' => 'paid', 'type' => 'cobro', 'created_at' => current_time('mysql'),
                    ]);
                    $cobros++;
                }
            }
        }

        wp_send_json_success([
            'clientes_creados' => $clientes_creados, 'cargos' => $cargos,
            'cobros' => $cobros, 'omitidos' => $omitidos,
        ]);
    }

    // ── AJAX: list clients ────────────────────────────────────────────────────
    public function ajax_list_clients() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$p}clients` WHERE id=%d", $id), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT c.*,
                    COALESCE(SUM(CASE WHEN pm.type='cargo' THEN pm.amount ELSE 0 END),0) AS total_cargo,
                    COALESCE(SUM(CASE WHEN pm.type='cobro' THEN pm.amount ELSE 0 END),0) AS total_cobro
                 FROM `{$p}clients` c
                 LEFT JOIN `{$p}payments` pm ON pm.client_id = c.id AND pm.currency = 'ARS'
                 WHERE c.active = 1
                 GROUP BY c.id
                 ORDER BY c.name ASC",
                ARRAY_A
            );
        }
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: save client (or provider data) ─────────────────────────────────
    public function ajax_save_client() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        // Provider data save
        if (!empty($_POST['_provider'])) {
            update_option('luna_provider_name',  sanitize_text_field($_POST['provider_name']  ?? ''));
            update_option('luna_provider_cuit',  sanitize_text_field($_POST['provider_cuit']  ?? ''));
            update_option('luna_provider_email', sanitize_email($_POST['provider_email']       ?? ''));
            update_option('luna_provider_phone', sanitize_text_field($_POST['provider_phone']  ?? ''));
            wp_send_json_success();
        }

        global $wpdb;
        $p    = $wpdb->prefix . 'luna_';
        $id   = (int)($_POST['id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        if (!$name) wp_send_json_error('El nombre es obligatorio.');

        $data = [
            'name'          => $name,
            'cuit'          => sanitize_text_field($_POST['cuit']          ?? ''),
            'email'         => sanitize_email($_POST['email']              ?? ''),
            'phone'         => sanitize_text_field($_POST['phone']         ?? ''),
            'address'       => sanitize_text_field($_POST['address']       ?? ''),
            'city'          => sanitize_text_field($_POST['city']          ?? ''),
            'iva_condition'   => sanitize_text_field($_POST['iva_condition'] ?? 'Consumidor Final'),
            'notes'           => sanitize_textarea_field($_POST['notes']     ?? ''),
            'subscription_type' => in_array($_POST['subscription_type'] ?? '', ['mensual','anual']) ? sanitize_text_field($_POST['subscription_type']) : 'none',
            'is_subscription'   => in_array($_POST['subscription_type'] ?? '', ['mensual','anual']) ? 1 : 0,
            'billing_day'       => !empty($_POST['billing_day']) ? min(31, max(1, (int)$_POST['billing_day'])) : null,
            'updated_at'      => current_time('mysql'),
        ];

        if ($id) {
            $wpdb->update("{$p}clients", $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert("{$p}clients", $data);
            $id = $wpdb->insert_id;
        }
        wp_send_json_success(['id' => $id]);
    }

    // ── AJAX: import clients from CSV rows ───────────────────────────────────
    public function ajax_import_clients() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $rows = json_decode(stripslashes($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows)) wp_send_json_error('Datos inválidos.');

        global $wpdb;
        $p     = $wpdb->prefix . 'luna_';
        $table = "{$p}clients";
        $now   = current_time('mysql');

        // Asegurar columnas opcionales
        $cols = ['domain' => "VARCHAR(200) NOT NULL DEFAULT ''", 'renewal_date' => "DATE DEFAULT NULL", 'renewal_amount' => "DECIMAL(10,2) DEFAULT 0.00", 'is_subscription' => "TINYINT(1) DEFAULT 0", 'billing_day' => "TINYINT(2) DEFAULT NULL"];
        foreach ($cols as $col => $def) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
            if (!$exists) $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$col} {$def}");
        }

        $imported = 0; $updated = 0; $errors = 0; $payments_created = 0;
        $pay_table = "{$p}payments";
        foreach ($rows as $row) {
            $name = sanitize_text_field($row['name'] ?? '');
            if (!$name) continue;

            $rd       = !empty($row['renewal_date']) ? sanitize_text_field($row['renewal_date']) : null;
            $ra       = strlen($row['renewal_amount'] ?? '') ? (float)preg_replace('/[^0-9.]/', '', $row['renewal_amount']) : null;
            $sub      = !empty($row['is_subscription']) && in_array(strtolower($row['is_subscription']), ['1','si','sí','yes','true','abonado']) ? 1 : 0;
            $bd       = !empty($row['billing_day']) ? min(31, max(1, (int)$row['billing_day'])) : null;
            $a_cobrar = !empty($row['a_cobrar']) && $row['a_cobrar'] === '1';

            $data = [
                'domain'     => sanitize_text_field($row['domain'] ?? ''),
                'notes'      => sanitize_text_field($row['notes']  ?? ''),
                'active'     => 1,
                'updated_at' => $now,
            ];
            if ($rd !== null)  $data['renewal_date']   = $rd;
            if ($ra !== null)  $data['renewal_amount'] = $ra;
            if ($sub) {
                $data['is_subscription'] = 1;
                $data['billing_day']     = $bd;
                $data['subscription_type'] = 'mensual';
            }

            $exists    = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE name = %s LIMIT 1", $name));
            $client_id = 0;
            if ($exists) {
                $res = $wpdb->update($table, $data, ['name' => $name]);
                $res !== false ? $updated++ : $errors++;
                $client_id = (int)$exists;
            } else {
                $data['name']            = $name;
                $data['created_at']      = $now;
                if (!$sub) $data['is_subscription'] = 0;
                $res = $wpdb->insert($table, $data);
                $res ? $imported++ : $errors++;
                $client_id = (int)$wpdb->insert_id;
            }

            // ── Crear registro(s) de pago ─────────────────────────────────────────
            $pago_status  = sanitize_text_field($row['pago_status']  ?? ($a_cobrar ? 'pending' : 'paid'));
            $has_cuotas   = !empty($row['cuotas']) && $row['cuotas'] === '1';
            $num_cuotas   = max(1, (int)($row['num_cuotas'] ?? 1));
            $monto_cuota  = strlen($row['monto_cuota'] ?? '') ? (float)preg_replace('/[^0-9.]/', '', $row['monto_cuota']) : 0;

            // Monto a usar: del CSV o del cliente en BD
            $ra_val = ($ra !== null && $ra > 0) ? $ra : (float)$wpdb->get_var(
                $wpdb->prepare("SELECT renewal_amount FROM `{$table}` WHERE id=%d", $client_id)
            );

            if ($rd && $client_id && $ra_val > 0 && ($a_cobrar || $pago_status === 'paid')) {
                $label = sanitize_text_field($row['domain'] ?? '') ?: $name;

                if ($has_cuotas && $num_cuotas > 1 && $monto_cuota > 0) {
                    // Crear N cuotas con fechas mensuales
                    $base_due = new DateTime($rd);
                    for ($qi = 0; $qi < $num_cuotas; $qi++) {
                        $due_i = clone $base_due;
                        if ($qi > 0) $due_i->modify("+{$qi} month");
                        $due_str = $due_i->format('Y-m-d');
                        $concept_i = 'Renovación — ' . $label . ' (Cuota ' . ($qi+1) . '/' . $num_cuotas . ')';
                        $exists_i  = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM `{$pay_table}` WHERE client_id=%d AND due_date=%s AND concept=%s LIMIT 1",
                            $client_id, $due_str, $concept_i
                        ));
                        if (!$exists_i) {
                            $wpdb->insert($pay_table, [
                                'client_id'  => $client_id,
                                'concept'    => $concept_i,
                                'amount'     => $monto_cuota,
                                'currency'   => 'ARS',
                                'due_date'   => $due_str,
                                'status'     => $pago_status,
                                'method'     => 'Transferencia',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $payments_created++;
                        }
                    }
                } else {
                    // Pago único
                    $pay_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM `{$pay_table}` WHERE client_id=%d AND due_date=%s LIMIT 1",
                        $client_id, $rd
                    ));
                    if (!$pay_exists) {
                        $wpdb->insert($pay_table, [
                            'client_id'  => $client_id,
                            'concept'    => 'Renovación — ' . $label,
                            'amount'     => $ra_val,
                            'currency'   => 'ARS',
                            'due_date'   => $rd,
                            'status'     => $pago_status,
                            'method'     => 'Transferencia',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $payments_created++;
                    }
                }
            }
        }
        wp_send_json_success(['imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'payments' => $payments_created]);
    }

    // ── AJAX: delete client ───────────────────────────────────────────────────
    public function ajax_delete_client() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('ID inválido.');
        $wpdb->delete("{$p}payments", ['client_id' => $id]);
        $wpdb->delete("{$p}clients",  ['id'        => $id]);
        wp_send_json_success();
    }

    // ── AJAX: list payments ───────────────────────────────────────────────────
    public function ajax_list_payments() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $cid = (int)($_POST['client_id'] ?? 0);
        if (!$cid) wp_send_json_error('Cliente inválido.');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, w.name AS workspace_name, c.cuit AS client_cuit, c.email AS client_email, c.city AS client_city
             FROM `{$p}payments` p
             LEFT JOIN `{$p}workspaces` w ON w.id = p.workspace_id
             LEFT JOIN `{$p}clients`    c ON c.id = p.client_id
             WHERE p.client_id = %d ORDER BY p.created_at DESC",
            $cid
        ), ARRAY_A);
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: list invoices (todos los movimientos, de todos los clientes) ───
    public function ajax_list_invoices() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p = $wpdb->prefix . 'luna_';
        $rows = $wpdb->get_results(
            "SELECT p.*, w.name AS workspace_name, c.name AS client_name, c.domain AS client_domain
             FROM `{$p}payments` p
             INNER JOIN `{$p}clients` c ON c.id = p.client_id AND c.active = 1
             LEFT JOIN `{$p}workspaces` w ON w.id = p.workspace_id
             ORDER BY p.created_at DESC",
            ARRAY_A
        );
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: save payment ────────────────────────────────────────────────────
    public function ajax_save_payment() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p       = $wpdb->prefix . 'luna_';
        $id      = (int)($_POST['id']        ?? 0);
        $cid     = (int)($_POST['client_id'] ?? 0);
        $concept = sanitize_text_field($_POST['concept'] ?? '');
        if (!$cid || !$concept) wp_send_json_error('Datos incompletos.');

        $wsid  = (int)($_POST['workspace_id'] ?? 0);
        $type  = in_array($_POST['type'] ?? 'cargo', ['cargo','cobro']) ? sanitize_text_field($_POST['type']) : 'cargo';
        if ($type === 'cargo') $this->luna_remember_payment_reason($concept);
        $cargo_id = !empty($_POST['cargo_id']) ? (int)$_POST['cargo_id'] : null;
        $total_amount = (float)($_POST['amount'] ?? 0);
        $data = [
            'client_id'      => $cid,
            'workspace_id'   => $wsid ?: null,
            'type'           => $type,
            'cargo_id'       => $cargo_id,
            'concept'        => $concept,
            'amount'         => $total_amount,
            'currency'       => sanitize_text_field($_POST['currency']       ?? 'ARS'),
            'payment_date'   => sanitize_text_field($_POST['payment_date']   ?? '') ?: null,
            'due_date'       => sanitize_text_field($_POST['due_date']       ?? '') ?: null,
            'method'         => sanitize_text_field($_POST['method']         ?? 'Transferencia'),
            'status'         => $type === 'cobro' ? 'paid' : sanitize_text_field($_POST['status'] ?? 'pending'),
            'invoice_number' => sanitize_text_field($_POST['invoice_number'] ?? ''),
            'notes'          => sanitize_textarea_field($_POST['notes']      ?? ''),
            'updated_at'     => current_time('mysql'),
        ];
        // Si es un cobro vinculado a un cargo, actualizar estado del cargo
        if ($type === 'cobro' && $cargo_id) {
            $cargo = $wpdb->get_row($wpdb->prepare(
                "SELECT amount FROM `{$p}payments` WHERE id=%d AND client_id=%d", $cargo_id, $cid
            ), ARRAY_A);
            if ($cargo) {
                $cobros_sum = (float)$wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM `{$p}payments` WHERE cargo_id=%d AND type='cobro'", $cargo_id
                ));
                $cobros_sum += $total_amount;
                $new_status = $cobros_sum >= (float)$cargo['amount'] ? 'paid' : 'partial';
                $wpdb->update("{$p}payments", ['status' => $new_status, 'updated_at' => current_time('mysql')], ['id' => $cargo_id]);
            }
        }

        // Cuotas: solo aplicable a nuevos registros
        $installments = $id ? 1 : max(1, (int)($_POST['installments'] ?? 1));

        if ($id) {
            $wpdb->update("{$p}payments", $data, ['id' => $id]);
            wp_send_json_success(['id' => $id]);
        }

        if ($installments > 1) {
            $base_amount  = round($total_amount / $installments, 2);
            $base_concept = $concept;
            $base_date = $data['payment_date'] ? new DateTime($data['payment_date']) : null;
            $base_due  = $data['due_date']     ? new DateTime($data['due_date'])     : null;
            $first_id  = null;
            for ($i = 0; $i < $installments; $i++) {
                $row = $data;
                $row['concept'] = $base_concept . ' (Cuota ' . ($i + 1) . '/' . $installments . ')';
                $row['amount']  = ($i === $installments - 1)
                    ? round($total_amount - $base_amount * ($installments - 1), 2)
                    : $base_amount;
                if ($i > 0) {
                    if ($base_date) {
                        $d = clone $base_date;
                        $d->modify("+{$i} month");
                        $row['payment_date'] = $d->format('Y-m-d');
                    }
                    if ($base_due) {
                        $dd = clone $base_due;
                        $dd->modify("+{$i} month");
                        $row['due_date'] = $dd->format('Y-m-d');
                    }
                }
                $row['created_at'] = current_time('mysql');
                $wpdb->insert("{$p}payments", $row);
                if ($i === 0) $first_id = $wpdb->insert_id;
            }
            wp_send_json_success(['id' => $first_id, 'installments' => $installments]);
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert("{$p}payments", $data);
        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    // ── AJAX: delete payment ──────────────────────────────────────────────────
    public function ajax_delete_payment() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('ID inválido.');
        $wpdb->delete("{$p}payments", ['id' => $id]);
        wp_send_json_success();
    }

    // ── AJAX: estado de cuenta por cliente ───────────────────────────────────
    public function ajax_estado_cuenta() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p   = $wpdb->prefix . 'luna_';
        $cid = (int)($_POST['client_id'] ?? 0);
        if (!$cid) wp_send_json_error('Cliente requerido.');
        $from = sanitize_text_field($_POST['from'] ?? '');
        $to   = sanitize_text_field($_POST['to']   ?? '');

        if ($from && $to) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$p}payments`
                 WHERE client_id = %d
                   AND COALESCE(payment_date, due_date, created_at) BETWEEN %s AND %s
                 ORDER BY COALESCE(payment_date, due_date, created_at) ASC",
                $cid, $from, $to
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$p}payments`
                 WHERE client_id = %d
                 ORDER BY COALESCE(payment_date, due_date, created_at) ASC",
                $cid
            ), ARRAY_A);
        }
        wp_send_json_success($rows ?: []);
    }

    // ── AJAX: marcar pago como cobrado ────────────────────────────────────────
    public function ajax_mark_payment_paid() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p  = $wpdb->prefix . 'luna_';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('ID inválido.');
        $wpdb->update("{$p}payments", [
            'status'       => 'paid',
            'payment_date' => current_time('Y-m-d'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $id]);
        wp_send_json_success();
    }

    // ── AJAX: informe de caja ─────────────────────────────────────────────────
    public function ajax_report_payments() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $p    = $wpdb->prefix . 'luna_';
        $from = sanitize_text_field($_POST['from'] ?? '');
        $to   = sanitize_text_field($_POST['to']   ?? '');
        $cid  = (int)($_POST['client_id'] ?? 0);
        if (!$from || !$to) wp_send_json_error('Rango de fechas requerido.');

        $cid_sql = $cid ? $wpdb->prepare(" AND pm.client_id = %d", $cid) : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.*, c.name AS client_name
             FROM `{$p}payments` pm
             JOIN `{$p}clients` c ON c.id = pm.client_id
             WHERE (pm.payment_date BETWEEN %s AND %s
                    OR (pm.payment_date IS NULL AND pm.due_date BETWEEN %s AND %s))
             {$cid_sql}
             ORDER BY COALESCE(pm.payment_date, pm.due_date) ASC",
            $from, $to, $from, $to
        ), ARRAY_A);

        $totals = [
            'ARS'        => 0.0,
            'USD'        => 0.0,
            'facturado'  => ['ARS' => 0.0, 'USD' => 0.0],
            'cobrado'    => ['ARS' => 0.0, 'USD' => 0.0],
        ];
        foreach (($rows ?: []) as $r) {
            $cur = ($r['currency'] === 'USD') ? 'USD' : 'ARS';
            $totals[$cur] += (float)$r['amount'];
            if ($r['type'] === 'cobro') {
                $totals['cobrado'][$cur] += (float)$r['amount'];
            } else {
                $totals['facturado'][$cur] += (float)$r['amount'];
            }
        }
        wp_send_json_success(['rows' => $rows ?: [], 'totals' => $totals]);
    }

    // ── Enviar recordatorio de pago por email al cliente ─────────────────────────
    public function ajax_send_client_reminder() {
        check_ajax_referer('luna_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Sin permisos');

        global $wpdb;
        $p         = $wpdb->prefix . 'luna_';
        $client_id = (int)($_POST['client_id'] ?? 0);
        $body_text = sanitize_textarea_field($_POST['body'] ?? '');

        if (!$client_id || !$body_text) wp_send_json_error('Datos incompletos');

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$p}clients` WHERE id = %d AND active = 1 LIMIT 1", $client_id
        ), ARRAY_A);

        if (!$client) wp_send_json_error('Cliente no encontrado');
        if (empty($client['email'])) wp_send_json_error('El cliente no tiene email registrado');

        $st  = $wpdb->get_var("SELECT meta_value FROM `{$p}app_settings` WHERE meta_key='email_settings' LIMIT 1");
        $cfg = $st ? (json_decode($st, true) ?: []) : [];

        $from_email = !empty($cfg['from_email']) ? $cfg['from_email'] : (!empty($cfg['smtp_user']) ? $cfg['smtp_user'] : get_option('admin_email'));
        $from_name  = !empty($cfg['from_name'])  ? $cfg['from_name']  : get_bloginfo('name');
        $to         = $client['email'];
        $subject    = 'Recordatorio de pago — ' . ($client['domain'] ?: $client['name']);

        $html = '<div style="font-family:\'Segoe UI\',sans-serif;max-width:560px;margin:0 auto;padding:32px;background:#f8fafc;border-radius:12px">'
              . '<h2 style="color:#5b6af0;margin-bottom:8px">Recordatorio de pago</h2>'
              . '<p style="color:#334155;font-size:15px;line-height:1.6">' . nl2br(esc_html($body_text)) . '</p>'
              . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">'
              . '<p style="color:#94a3b8;font-size:12px">Enviado por ' . esc_html($from_name) . '</p>'
              . '</div>';

        $mail_error = '';
        add_action('phpmailer_init', function($m) use ($from_email, $from_name) {
            $m->isSMTP();
            $m->Host       = '127.0.0.1';
            $m->Port       = 25;
            $m->SMTPAuth   = false;
            $m->SMTPSecure = '';
            $m->Timeout    = 5;
            $m->setFrom($from_email, $from_name);
        });
        add_action('wp_mail_failed', function($e) use (&$mail_error) {
            $mail_error = $e->get_error_message();
        });
        add_filter('wp_mail_content_type', fn() => 'text/html');

        $sent = wp_mail($to, $subject, $html);

        remove_all_actions('phpmailer_init');
        remove_all_actions('wp_mail_failed');
        remove_all_filters('wp_mail_content_type');

        if ($sent) {
            wp_send_json_success('✅ Email enviado a ' . $to);
        } else {
            $headers  = "From: {$from_name} <{$from_email}>\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $fallback = @mail($to, $subject, $html, $headers);
            if ($fallback) wp_send_json_success('✅ Email enviado a ' . $to);
            else           wp_send_json_error('No se pudo enviar: ' . ($mail_error ?: 'Error de servidor de correo'));
        }
    }

}

