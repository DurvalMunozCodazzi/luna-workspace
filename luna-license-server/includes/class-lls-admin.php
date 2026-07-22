<?php
defined('ABSPATH') || exit;

class LLS_Admin {

    public function __construct() {
        add_action('admin_menu',             [$this, 'add_menu']);
        add_action('admin_enqueue_scripts',  [$this, 'enqueue']);
        add_action('admin_post_lls_create',       [$this, 'handle_create']);
        add_action('admin_post_lls_update',       [$this, 'handle_update']);
        add_action('admin_post_lls_delete',       [$this, 'handle_delete']);
        add_action('admin_post_lls_toggle',       [$this, 'handle_toggle']);
        add_action('admin_post_lls_save_banners', [$this, 'handle_save_banners']);
        add_action('admin_post_lls_reject_request', [$this, 'handle_reject_request']);
        // Run schema migrations on every admin load (not just on plugin activation)
        add_action('admin_init', [$this, 'maybe_migrate']);
    }

    public function maybe_migrate(): void {
        global $wpdb;
        $t_lic = $wpdb->prefix . 'lls_licenses';
        $t_log = $wpdb->prefix . 'lls_verify_log';

        // Licenses table: add missing columns
        $lic_cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$t_lic}`", ARRAY_A), 'Field');
        if (!in_array('max_workspaces', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `max_workspaces` SMALLINT UNSIGNED NOT NULL DEFAULT 1");
        if (!in_array('max_sites', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `max_sites` SMALLINT UNSIGNED NOT NULL DEFAULT 1");
        if (!in_array('max_users', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `max_users` SMALLINT UNSIGNED NOT NULL DEFAULT 999");
        if (!in_array('notes', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `notes` TEXT NULL");
        if (!in_array('updated_at', $lic_cols))
            $wpdb->query("ALTER TABLE `{$t_lic}` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        // Ensure expires_at allows NULL
        $wpdb->query("ALTER TABLE `{$t_lic}` MODIFY COLUMN `expires_at` DATE NULL DEFAULT NULL");

        // Log table: add verified_at if missing
        $log_cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$t_log}`", ARRAY_A), 'Field');
        if (!in_array('verified_at', $log_cols)) {
            if (in_array('created_at', $log_cols)) {
                $wpdb->query("ALTER TABLE `{$t_log}` CHANGE `created_at` `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            } else {
                $wpdb->query("ALTER TABLE `{$t_log}` ADD COLUMN `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }

    public function add_menu(): void {
        add_menu_page(
            'Luna Licenses',
            'Luna Licenses',
            'manage_options',
            'luna-licenses',
            [$this, 'page_list'],
            'dashicons-admin-network',
            58
        );
        add_submenu_page('luna-licenses', 'Todas las Licencias', 'Todas las Licencias', 'manage_options', 'luna-licenses',          [$this, 'page_list']);
        add_submenu_page('luna-licenses', 'Nueva Licencia',      'Nueva Licencia',      'manage_options', 'luna-licenses-new',       [$this, 'page_new']);
        add_submenu_page('luna-licenses', 'Solicitudes',         $this->requests_menu_label(), 'manage_options', 'luna-licenses-requests', [$this, 'page_requests']);
        add_submenu_page('luna-licenses', 'Log de Verificaciones','Log',                'manage_options', 'luna-licenses-log',       [$this, 'page_log']);
        add_submenu_page('luna-licenses', 'Banners',              'Banners 📢',          'manage_options', 'luna-licenses-banners',   [$this, 'page_banners']);
        add_submenu_page('luna-licenses', 'Configuración',        'Configuración',       'manage_options', 'luna-licenses-settings',  [$this, 'page_settings']);
    }

    public function enqueue(string $hook): void {
        if (!str_contains($hook, 'luna-licenses')) return;
        wp_enqueue_style('lls-admin', LLS_PLUGIN_URL . 'admin/admin.css', [], LLS_VERSION);
    }

    // ── LIST ──────────────────────────────────────────────────────────────────
    public function page_list(): void {
        $search = sanitize_text_field($_GET['s']     ?? '');
        $status = sanitize_text_field($_GET['status'] ?? '');
        $page   = max(1, (int)($_GET['paged'] ?? 1));
        $data   = LLS_License::get_all(25, $page, $search, $status);
        require LLS_PLUGIN_DIR . 'admin/views/list.php';
    }

    // ── NEW ───────────────────────────────────────────────────────────────────
    public function page_new(): void {
        $editing = null;
        if (!empty($_GET['edit'])) {
            $editing = LLS_License::get_by_key(sanitize_text_field($_GET['edit']));
        }
        $prefill = [];
        if (empty($editing) && !empty($_GET['from_request'])) {
            global $wpdb;
            $req = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}lls_requests` WHERE id=%d AND status='pending'",
                (int) $_GET['from_request']
            ), ARRAY_A);
            if ($req) {
                $prefill = [
                    'request_id'     => (int) $req['id'],
                    'customer_name'  => $req['nombre'],
                    'customer_email' => $req['email'],
                    'domain'         => LLS_License::normalize_domain($req['dominio']),
                    'plan'           => $req['plan'],
                    'expires_at'     => $req['plan'] === 'free' ? date('Y-m-d', strtotime('+30 days')) : '',
                    'notes'          => 'Tel: ' . $req['telefono'],
                ];
            }
        }
        require LLS_PLUGIN_DIR . 'admin/views/form.php';
    }

    // ── REQUESTS (solicitudes de trial/gratis desde /solicitar) ────────────────
    private function requests_menu_label(): string {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_requests';
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}` WHERE status='pending'");
        return $pending ? "Solicitudes <span class=\"awaiting-mod count-{$pending}\"><span class=\"pending-count\">{$pending}</span></span>" : 'Solicitudes';
    }

    public function page_requests(): void {
        $status = sanitize_text_field($_GET['status'] ?? 'pending');
        global $wpdb;
        $t = $wpdb->prefix . 'lls_requests';
        if ($status) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t}` WHERE status=%s ORDER BY created_at DESC LIMIT 100", $status), ARRAY_A);
        } else {
            $rows = $wpdb->get_results("SELECT * FROM `{$t}` ORDER BY created_at DESC LIMIT 100", ARRAY_A);
        }
        require LLS_PLUGIN_DIR . 'admin/views/requests.php';
    }

    public function handle_reject_request(): void {
        check_admin_referer('lls_reject_request');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');
        global $wpdb;
        $id = (int) ($_POST['id'] ?? 0);
        $wpdb->update($wpdb->prefix . 'lls_requests', ['status' => 'rejected'], ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=luna-licenses-requests&msg=rejected'));
        exit;
    }

    // ── LOG ───────────────────────────────────────────────────────────────────
    public function page_log(): void {
        $key  = sanitize_text_field($_GET['key'] ?? '');
        $logs = LLS_License::get_log($key, 100);
        require LLS_PLUGIN_DIR . 'admin/views/log.php';
    }

    // ── SETTINGS ─────────────────────────────────────────────────────────────
    public function page_settings(): void {
        if (isset($_POST['lls_settings_nonce']) && wp_verify_nonce($_POST['lls_settings_nonce'], 'lls_settings')) {
            update_option('lls_hmac_secret', sanitize_text_field($_POST['hmac_secret'] ?? ''));
            if (!empty($_POST['lls_private_key'])) {
                update_option('lls_private_key', trim($_POST['lls_private_key']));
            }
            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        }
        $private_key_set = !empty(get_option('lls_private_key', ''));
        require LLS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── BANNERS ───────────────────────────────────────────────────────────────
    public function page_banners(): void {
        $msg     = sanitize_text_field($_GET['msg'] ?? '');
        $banners = get_option('lls_banners', $this->default_banners());
        require LLS_PLUGIN_DIR . 'admin/views/banners.php';
    }

    public function handle_save_banners(): void {
        check_admin_referer('lls_save_banners');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $texts   = $_POST['banner_text']   ?? [];
        $imgs    = $_POST['banner_img']    ?? [];
        $links   = $_POST['banner_link']   ?? [];
        $ctas    = $_POST['banner_cta']    ?? [];
        $actives = $_POST['banner_active'] ?? [];

        $banners = [];
        for ($i = 0; $i < 5; $i++) {
            $text = sanitize_text_field($texts[$i] ?? '');
            if (!$text) continue;
            $banners[] = [
                'text'   => $text,
                'img'    => esc_url_raw($imgs[$i]  ?? ''),
                'link'   => esc_url_raw($links[$i] ?? 'https://websobreruedas.com/luna-planes'),
                'cta'    => sanitize_text_field($ctas[$i] ?? 'Ver más →'),
                'active' => isset($actives[$i]),
            ];
        }

        $data = [
            'banners'      => array_values(array_filter($banners, fn($b) => $b['active'])),
            'upgrade_url'  => esc_url_raw($_POST['upgrade_url']  ?? 'https://websobreruedas.com/luna-planes'),
            'upgrade_text' => sanitize_text_field($_POST['upgrade_text'] ?? '⚡ Quitar anuncios'),
        ];

        // All banners (including inactive) saved to option for the admin UI
        $option = $banners;
        update_option('lls_banners', $option);
        update_option('lls_banners_meta', ['upgrade_url' => $data['upgrade_url'], 'upgrade_text' => $data['upgrade_text']]);

        // Write public JSON to WordPress root — served as websobreruedas.com/luna-ads.json
        $json_path = ABSPATH . 'luna-ads.json';
        file_put_contents($json_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        wp_redirect(admin_url('admin.php?page=luna-licenses-banners&msg=saved'));
        exit;
    }

    private function default_banners(): array {
        return [
            ['text' => 'Actualizá al plan Básico — equipos de hasta 5 personas desde $19/mes', 'img' => '', 'link' => 'https://websobreruedas.com/luna-planes', 'cta' => 'Ver planes →',  'active' => true],
            ['text' => 'Luna Workspace Pro — WhatsApp, Telegram y métricas avanzadas',          'img' => '', 'link' => 'https://websobreruedas.com/luna-planes', 'cta' => 'Conocer →',     'active' => true],
            ['text' => 'Gantt, recordatorios automáticos y soporte prioritario',                 'img' => '', 'link' => 'https://websobreruedas.com/luna-planes', 'cta' => 'Ver más →',    'active' => false],
            ['text' => '', 'img' => '', 'link' => 'https://websobreruedas.com/luna-planes', 'cta' => 'Ver →', 'active' => false],
            ['text' => '', 'img' => '', 'link' => 'https://websobreruedas.com/luna-planes', 'cta' => 'Ver →', 'active' => false],
        ];
    }

    // ── HANDLERS ─────────────────────────────────────────────────────────────
    public function handle_create(): void {
        check_admin_referer('lls_create');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = LLS_License::create([
            'customer_name'  => $_POST['customer_name']  ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
            'domain'         => $_POST['domain']         ?? '',
            'plan'           => $_POST['plan']           ?? 'starter',
            'expires_at'     => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
            'notes'          => $_POST['notes']          ?? '',
        ]);

        $redirect = admin_url('admin.php?page=luna-licenses');
        if ($id) {
            $request_id = (int) ($_POST['request_id'] ?? 0);
            if ($request_id) {
                global $wpdb;
                $wpdb->update($wpdb->prefix . 'lls_requests', ['status' => 'sent'], ['id' => $request_id]);
            }
            wp_redirect($redirect . '&msg=created');
        } else {
            global $wpdb;
            $err = urlencode($wpdb->last_error ?: 'Error desconocido al insertar en la base de datos.');
            wp_redirect($redirect . '&msg=error&dberr=' . $err);
        }
        exit;
    }

    public function handle_update(): void {
        check_admin_referer('lls_update');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = (int)($_POST['id'] ?? 0);
        LLS_License::update($id, [
            'customer_name'  => $_POST['customer_name']  ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
            'domain'         => $_POST['domain']         ?? '',
            'plan'           => $_POST['plan']           ?? 'starter',
            'status'         => $_POST['status']         ?? 'active',
            'expires_at'     => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null,
            'notes'          => $_POST['notes']          ?? '',
        ]);

        wp_redirect(admin_url('admin.php?page=luna-licenses&msg=updated'));
        exit;
    }

    public function handle_delete(): void {
        check_admin_referer('lls_delete');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id = (int)($_POST['id'] ?? 0);
        LLS_License::delete($id);
        wp_redirect(admin_url('admin.php?page=luna-licenses&msg=deleted'));
        exit;
    }

    public function handle_toggle(): void {
        check_admin_referer('lls_toggle');
        if (!current_user_can('manage_options')) wp_die('Sin permisos');

        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'active');
        LLS_License::update($id, ['status' => $status === 'active' ? 'inactive' : 'active']);
        wp_redirect(admin_url('admin.php?page=luna-licenses&msg=updated'));
        exit;
    }
}
