<?php
defined('ABSPATH') || exit;

class Luna_Activator {

    public static function activate() {
        // Solo inicializar luna_manual_db si no está configurado (preservar config existente)
        if (!get_option('luna_manual_db')) {
            update_option('luna_manual_db', [
                'db_host'   => '',
                'db_name'   => '',
                'db_user'   => '',
                'db_pass'   => '',
                'tb_prefix' => '',
            ]);
        }
        self::create_tables();
        self::seed_initial_data();
        self::create_app_page();
        self::write_app_config();
        update_option('luna_version', LUNA_VERSION);
        // Generate cron secret if not set
        if (!get_option('luna_cron_secret')) {
            update_option('luna_cron_secret', bin2hex(random_bytes(16)));
        }
        // Token permanente para acceso directo (botón Plesk/marcador)
        if (!get_option('luna_entry_token')) {
            update_option('luna_entry_token', bin2hex(random_bytes(24)));
        }
        // Schedule hourly WP cron (checks reminder hour each time it fires)
        if (!wp_next_scheduled('luna_hourly_check')) {
            wp_schedule_event(time(), 'hourly', 'luna_hourly_check');
        }
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('luna_hourly_check');
        wp_clear_scheduled_hook('luna_daily_billing');
        flush_rewrite_rules();
    }

    // MySQL 5.x-compatible ADD COLUMN: checks SHOW COLUMNS before altering
    public static function add_column_if_missing($wpdb, string $table, string $col, string $definition): void {
        $exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (empty($exists)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    }

    // ── DB tables (same schema as setup.php) ────────────────────────────────
    private static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL UNIQUE,
                email VARCHAR(120) NOT NULL,
                name VARCHAR(120) NOT NULL,
                cargo VARCHAR(120) DEFAULT '',
                dept VARCHAR(120) DEFAULT '',
                role ENUM('admin','member','visitor') NOT NULL DEFAULT 'member',
                color VARCHAR(10) DEFAULT '#5b6af0',
                password VARCHAR(255) NOT NULL,
                photo MEDIUMTEXT DEFAULT NULL,
                active TINYINT(1) DEFAULT 1,
                notes TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME NULL
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_workspaces (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL DEFAULT 'Mi Workspace',
                description VARCHAR(500) DEFAULT '',
                canvas LONGTEXT DEFAULT NULL,
                color VARCHAR(10) DEFAULT '#5b6af0',
                icon VARCHAR(50) DEFAULT 'fa-project-diagram',
                image MEDIUMTEXT DEFAULT NULL,
                created_by INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_workspace_members (
                workspace_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('admin','member','visitor') DEFAULT 'member',
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (workspace_id, user_id),
                FOREIGN KEY (workspace_id) REFERENCES {$wpdb->prefix}luna_workspaces(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}luna_users(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_user_meta (
                user_id INT NOT NULL,
                meta_key VARCHAR(100) NOT NULL,
                meta_value MEDIUMTEXT DEFAULT NULL,
                PRIMARY KEY (user_id, meta_key),
                FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}luna_users(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_columns_k (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL DEFAULT 1,
                title VARCHAR(200) NOT NULL,
                color VARCHAR(10) DEFAULT '#5b6af0',
                position INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (workspace_id) REFERENCES {$wpdb->prefix}luna_workspaces(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_cards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                column_id INT NOT NULL,
                workspace_id INT NOT NULL DEFAULT 1,
                title VARCHAR(500) NOT NULL,
                description TEXT DEFAULT '',
                priority ENUM('','low','medium','high','critical') DEFAULT '',
                due_date DATE NULL,
                start_date DATE NULL,
                estimated DECIMAL(5,1) DEFAULT NULL,
                progress INT DEFAULT 0,
                position INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (column_id) REFERENCES {$wpdb->prefix}luna_columns_k(id) ON DELETE CASCADE,
                FOREIGN KEY (workspace_id) REFERENCES {$wpdb->prefix}luna_workspaces(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_card_tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                label VARCHAR(100) NOT NULL,
                color VARCHAR(10) DEFAULT '#5b6af0',
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_card_assignees (
                card_id INT NOT NULL,
                user_id INT NOT NULL,
                PRIMARY KEY (card_id, user_id),
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}luna_users(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                name VARCHAR(300) NOT NULL,
                type ENUM('local','drive') DEFAULT 'local',
                url VARCHAR(500) DEFAULT '',
                drive_id VARCHAR(200) DEFAULT '',
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_sessions (
                token VARCHAR(64) PRIMARY KEY,
                user_id INT NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}luna_users(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                from_user_id INT NOT NULL DEFAULT 0,
                type VARCHAR(30) NOT NULL DEFAULT 'info',
                card_id INT DEFAULT NULL,
                workspace_id INT DEFAULT 1,
                message TEXT DEFAULT '',
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES {$wpdb->prefix}luna_users(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_app_settings (
                meta_key VARCHAR(100) PRIMARY KEY,
                meta_value TEXT DEFAULT ''
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_workspace_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                description VARCHAR(500) DEFAULT '',
                icon VARCHAR(50) DEFAULT 'fa-project-diagram',
                color VARCHAR(10) DEFAULT '#5b6af0',
                columns TEXT DEFAULT '[]',
                is_default TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_card_checklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                title VARCHAR(500) NOT NULL,
                is_done TINYINT(1) DEFAULT 0,
                position INT DEFAULT 0,
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_card_dependencies (
                card_id INT NOT NULL,
                depends_on_id INT NOT NULL,
                PRIMARY KEY (card_id, depends_on_id),
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE,
                FOREIGN KEY (depends_on_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                card_id INT NOT NULL,
                workspace_id INT NOT NULL DEFAULT 1,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL DEFAULT 'update',
                field VARCHAR(100) DEFAULT '',
                new_value TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (card_id) REFERENCES {$wpdb->prefix}luna_cards(id) ON DELETE CASCADE
            ) $c",

            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}luna_workspace_labels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(10) DEFAULT '#5b6af0',
                created_by INT DEFAULT 0,
                FOREIGN KEY (workspace_id) REFERENCES {$wpdb->prefix}luna_workspaces(id) ON DELETE CASCADE
            ) $c",
        ];

        foreach ($tables as $sql) {
            $wpdb->query($sql);
        }

        // Migrate existing tables: add columns that may be missing from older installs
        // Uses SHOW COLUMNS to check first — compatible with MySQL 5.x (no IF NOT EXISTS support)
        $p = $wpdb->prefix;
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'cargo',                "VARCHAR(120) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'dept',                 "VARCHAR(120) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'photo',                "MEDIUMTEXT DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'notes',                "TEXT DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'last_login',           "DATETIME NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_workspace_templates", 'icon',       "VARCHAR(50) DEFAULT 'fa-project-diagram'");
        self::add_column_if_missing($wpdb, "{$p}luna_workspace_templates", 'color',      "VARCHAR(10) DEFAULT '#5b6af0'");
        self::add_column_if_missing($wpdb, "{$p}luna_workspace_templates", 'is_default', "TINYINT(1) DEFAULT 0");
        self::add_column_if_missing($wpdb, "{$p}luna_workspace_templates", 'columns',    "TEXT DEFAULT '[]'");
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'start_date', "DATE NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'estimated',  "DECIMAL(5,1) DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'progress',   "INT DEFAULT 0");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'canvas',     "LONGTEXT DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'image',      "MEDIUMTEXT DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'created_by', "INT DEFAULT 0");

        // Fix luna_app_settings: if meta_key column doesn't exist the table has old schema.
        // NEVER drop it — rename to backup so existing data is preserved, then create fresh table.
        $has_meta_key = $wpdb->get_results("SHOW COLUMNS FROM `{$p}luna_app_settings` LIKE 'meta_key'");
        if (empty($has_meta_key)) {
            $bak = $p . 'luna_app_settings_bak_' . date('Ymd');
            // Drop any previous same-day backup to avoid rename collision
            $wpdb->query("DROP TABLE IF EXISTS `{$bak}`");
            $wpdb->query("RENAME TABLE `{$p}luna_app_settings` TO `{$bak}`");
            $wpdb->query("CREATE TABLE `{$p}luna_app_settings` (meta_key VARCHAR(100) PRIMARY KEY, meta_value TEXT DEFAULT '') $c");
        }

        // Notification channel columns
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'phone',                "VARCHAR(30) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'whatsapp_apikey',       "VARCHAR(100) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'telegram_chat_id',      "VARCHAR(50) DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_users", 'notification_channel',  "ENUM('email','whatsapp','telegram','all','none') DEFAULT 'email'");
        // Attachment timestamp column (added in later schema versions)
        self::add_column_if_missing($wpdb, "{$p}luna_attachments", 'added_at', "DATETIME DEFAULT CURRENT_TIMESTAMP");
        // Cards columns that may be missing in older installs
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'start_date', "DATE DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'progress',   "INT DEFAULT 0");
        self::add_column_if_missing($wpdb, "{$p}luna_cards", 'estimated',  "INT DEFAULT NULL");
        // user_meta: upgrade meta_value from TEXT to MEDIUMTEXT to support bg images (up to 16MB)
        $col_type = $wpdb->get_var("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$p}luna_user_meta' AND COLUMN_NAME='meta_value'");
        if ($col_type && strtolower($col_type) === 'text') {
            $wpdb->query("ALTER TABLE `{$p}luna_user_meta` MODIFY COLUMN `meta_value` MEDIUMTEXT DEFAULT NULL");
        }
        // Workspaces columns
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'color',       "VARCHAR(10) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'icon',        "VARCHAR(10) DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'image',       "TEXT DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'description', "TEXT DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'canvas',      "TEXT DEFAULT NULL");
        // Activity log table (may not exist in old installs)
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_activity_log` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_id INT NOT NULL,
            workspace_id INT NOT NULL DEFAULT 1,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL DEFAULT 'update',
            field VARCHAR(100) DEFAULT '',
            new_value TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(card_id), INDEX(workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Card dependencies table
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_card_dependencies` (
            card_id INT NOT NULL,
            depends_on_id INT NOT NULL,
            PRIMARY KEY(card_id, depends_on_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Workspace labels table
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_workspace_labels` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(10) DEFAULT '#5b6af0',
            created_by INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Clientes (ABM)
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_clients` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            cuit VARCHAR(20) DEFAULT '',
            email VARCHAR(200) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            address TEXT DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            iva_condition VARCHAR(50) DEFAULT 'Consumidor Final',
            notes TEXT DEFAULT '',
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Pagos y trabajos por cliente
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_payments` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            workspace_id INT DEFAULT NULL,
            concept VARCHAR(300) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) DEFAULT 'ARS',
            payment_date DATE DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            method VARCHAR(50) DEFAULT 'Transferencia',
            status VARCHAR(20) DEFAULT 'pending',
            invoice_number VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(client_id), INDEX(workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Vínculo cliente ↔ pizarra (opcional)
        self::add_column_if_missing($wpdb, "{$p}luna_workspaces", 'client_id', "INT DEFAULT NULL");
        // Cobros por tarjeta kanban
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_card_payments` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_date DATE NOT NULL,
            method VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(card_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_card_cobros_meta` (
            card_id INT NOT NULL PRIMARY KEY,
            client_id INT DEFAULT NULL,
            total_amount DECIMAL(10,2) DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Garantiza que luna_clients y luna_payments existan (sin gate, idempotente) ─
    public static function ensure_client_tables() {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_clients` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            cuit VARCHAR(20) DEFAULT '',
            email VARCHAR(200) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            address TEXT DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            iva_condition VARCHAR(50) DEFAULT 'Consumidor Final',
            notes TEXT DEFAULT '',
            domain VARCHAR(200) NOT NULL DEFAULT '',
            renewal_date DATE DEFAULT NULL,
            renewal_amount DECIMAL(10,2) DEFAULT 0.00,
            is_subscription TINYINT(1) DEFAULT 0,
            billing_day TINYINT(2) DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $c");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_payments` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            workspace_id INT DEFAULT NULL,
            concept VARCHAR(300) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(10) DEFAULT 'ARS',
            payment_date DATE DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            method VARCHAR(50) DEFAULT 'Transferencia',
            status VARCHAR(20) DEFAULT 'pending',
            invoice_number VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(client_id), INDEX(workspace_id)
        ) $c");
        // Columnas opcionales en instalaciones antiguas
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'domain',          "VARCHAR(200) NOT NULL DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'renewal_date',    "DATE DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'renewal_amount',  "DECIMAL(10,2) DEFAULT 0.00");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'is_subscription',  "TINYINT(1) DEFAULT 0");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'billing_day',      "TINYINT(2) DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'subscription_type',"VARCHAR(20) DEFAULT 'none'");
        // Cuenta corriente: tipo de movimiento (cargo/cobro) y referencia al cargo original
        self::add_column_if_missing($wpdb, "{$p}luna_payments", 'type',     "VARCHAR(10) NOT NULL DEFAULT 'cargo'");
        self::add_column_if_missing($wpdb, "{$p}luna_payments", 'cargo_id', "INT DEFAULT NULL");
    }

    // ── Migración v2: columnas de abono/suscripción (se llama en plugins_loaded) ─
    public static function migrate_subscription_fields() {
        if (get_option('luna_cobros_tables_v2')) return;
        global $wpdb;
        $p = $wpdb->prefix;
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'domain',          "VARCHAR(200) NOT NULL DEFAULT ''");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'renewal_date',    "DATE DEFAULT NULL");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'renewal_amount',  "DECIMAL(10,2) DEFAULT 0.00");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'is_subscription', "TINYINT(1) DEFAULT 0");
        self::add_column_if_missing($wpdb, "{$p}luna_clients", 'billing_day',     "TINYINT(2) DEFAULT NULL");
        update_option('luna_cobros_tables_v2', 1);
    }

    // ── Migración de tablas de Cobranza (se llama en plugins_loaded) ──────────
    public static function migrate_cobros_tables() {
        if (get_option('luna_cobros_tables_v1')) return;
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_card_payments` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_date DATE NOT NULL,
            method VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(card_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}luna_card_cobros_meta` (
            card_id INT NOT NULL PRIMARY KEY,
            client_id INT DEFAULT NULL,
            total_amount DECIMAL(10,2) DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        update_option('luna_cobros_tables_v1', 1);
    }

    // ── Initial data ─────────────────────────────────────────────────────────
    private static function seed_initial_data() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Workspace inicial
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM {$p}luna_workspaces WHERE id=1");
        if (!$exists) {
            $wpdb->query("INSERT INTO {$p}luna_workspaces (id,name,color,icon) VALUES (1,'Mi Workspace','#5b6af0','fa-project-diagram')");
            $wpdb->query("INSERT INTO {$p}luna_columns_k (workspace_id,title,color,position) VALUES
                (1,'Por hacer','#5b6af0',0),
                (1,'En progreso','#f59e0b',1),
                (1,'En revisión','#06b6d4',2),
                (1,'Completado','#22d3a0',3)");
        }

        // Admin user — contraseña inicial aleatoria, se muestra una vez en pantalla de configuración
        $admin_exists = $wpdb->get_var("SELECT COUNT(*) FROM {$p}luna_users WHERE username='admin'");
        if (!$admin_exists) {
            $initial_pass = bin2hex(random_bytes(8)); // 16 chars hex
            update_option('luna_initial_admin_pass', $initial_pass); // saved once to show in admin
            $hash = password_hash($initial_pass, PASSWORD_BCRYPT);
            $wpdb->insert("{$p}luna_users", [
                'username' => 'admin',
                'email'    => get_option('admin_email'),
                'name'     => 'Administrador',
                'cargo'    => 'Admin',
                'dept'     => '',
                'role'     => 'admin',
                'color'    => '#5b6af0',
                'password' => $hash,
                'active'   => 1,
            ]);
            $admin_id = $wpdb->insert_id;
            $wpdb->query("INSERT IGNORE INTO {$p}luna_workspace_members (workspace_id,user_id,role) VALUES (1,$admin_id,'admin')");
        }

        // App settings
        $wpdb->query("INSERT IGNORE INTO {$p}luna_app_settings (meta_key,meta_value) VALUES
            ('email_settings','{\"enabled\":true,\"from_email\":\"\",\"from_name\":\"Luna Workspace\"}')");

        // Workspace templates
        $tpl_count = $wpdb->get_var("SELECT COUNT(*) FROM {$p}luna_workspace_templates");
        if (!$tpl_count) {
            $templates = [
                ['Desarrollo de Software','Para equipos de desarrollo ágil','fa-code','#5b6af0','[{"title":"Backlog","color":"#8888aa"},{"title":"Sprint actual","color":"#5b6af0"},{"title":"En desarrollo","color":"#f59e0b"},{"title":"En revisión","color":"#06b6d4"},{"title":"Completado","color":"#22d3a0"}]'],
                ['Campaña de Marketing','Para planificar y ejecutar campañas','fa-bullhorn','#ec4899','[{"title":"Ideas","color":"#8b5cf6"},{"title":"Planificación","color":"#06b6d4"},{"title":"En producción","color":"#f59e0b"},{"title":"En revisión","color":"#f97316"},{"title":"Publicado","color":"#22d3a0"}]'],
                ['Gestión de Proyectos','Seguimiento general de proyectos','fa-tasks','#22d3a0','[{"title":"Por hacer","color":"#5b6af0"},{"title":"En progreso","color":"#f59e0b"},{"title":"Bloqueado","color":"#ef4444"},{"title":"En revisión","color":"#06b6d4"},{"title":"Completado","color":"#22d3a0"}]'],
                ['Blank','Workspace en blanco','fa-plus','#5b6af0','[{"title":"Por hacer","color":"#5b6af0"},{"title":"En progreso","color":"#f59e0b"},{"title":"Completado","color":"#22d3a0"}]'],
            ];
            foreach ($templates as $t) {
                $wpdb->insert("{$p}luna_workspace_templates", [
                    'name'        => $t[0],
                    'description' => $t[1],
                    'icon'        => $t[2],
                    'color'       => $t[3],
                    'columns'     => $t[4],
                    'is_default'  => 1,
                ]);
            }
        }
    }

    // ── Auto-create WordPress page for the app ───────────────────────────────
    private static function create_app_page() {
        $slug = 'luna-app';
        // get_page_by_path también devuelve páginas en la papelera; verificar que esté publicada
        $existing = get_page_by_path($slug);
        if (!$existing || $existing->post_status !== 'publish') {
            if ($existing) {
                // Restaurar/republicar si estaba en papelera o borrador
                wp_update_post(['ID' => $existing->ID, 'post_status' => 'publish', 'post_name' => $slug]);
            } else {
                wp_insert_post([
                    'post_title'   => 'Luna Workspace',
                    'post_name'    => $slug,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '[luna_workspace height="100vh"]',
                ]);
            }
        }
        update_option('luna_page_slug', $slug);
    }

    // ── Write app/luna-wp-config.php with WP DB credentials ─────────────────
    // Solo escribe el config si NO existe o si está vacío/sin credenciales.
    // Si ya existe con DB_NAME configurado, lo deja intacto para no pisar
    // las credenciales de producción en cada desactivación/reactivación.
    public static function write_app_config() {
        $cfg_path = LUNA_APP_DIR . 'luna-wp-config.php';
        if ( file_exists( $cfg_path ) ) {
            $defs = self::parse_app_config( file_get_contents( $cfg_path ) );
            if ( ! empty( $defs['DB_NAME'] ) ) {
                // Validar la conexión REAL del config existente conectándose.
                // OJO: el prefijo '' es VÁLIDO (producción puede usar una BD
                // externa con tablas sin prefijo: users, workspaces…). Nunca
                // juzgar el config por el texto — solo por si conecta y tiene
                // la tabla users.
                if ( self::test_db_config(
                        $defs['DB_HOST'] ?? 'localhost', $defs['DB_NAME'],
                        $defs['DB_USER'] ?? '', $defs['DB_PASS'] ?? '',
                        $defs['LUNA_TB_PREFIX'] ?? '' ) ) {
                    // Config funciona → respaldar credenciales en wp_options
                    // (sobrevive a updates por ZIP) y solo parchear lo no sensible
                    self::backup_db_config( $defs );
                    self::patch_app_config();
                    return;
                }
                // Config roto (no conecta o no tiene tabla users) → regenerar
            }
        }
        self::regenerate_app_config();
    }

    // Extrae los define('X','y') de un luna-wp-config.php
    public static function parse_app_config( $content ) {
        $defs = [];
        preg_match_all( "/define\('([^']+)',\s*'([^']*)'\)/", $content, $m, PREG_SET_ORDER );
        foreach ( $m as $row ) $defs[ $row[1] ] = $row[2];
        return $defs;
    }

    // ¿Estas credenciales conectan y tienen la tabla {prefijo}users?
    // Usa PDO (igual que app/config.php::getDB()) — NO mysqli — para que este
    // chequeo tenga exactamente la misma confiabilidad que la conexión real
    // que usa la app. Antes usaba mysqli, una librería distinta que puede
    // comportarse diferente en algunos hostings y fallar en silencio,
    // impidiendo que se guarde el respaldo de credenciales.
    public static function test_db_config( $db_host, $db_name, $db_user, $db_pass, $tb_prefix ) {
        $host = $db_host; $port = ''; $socket = '';
        if ( strpos( $host, ':/' ) !== false ) {
            list( $host, $socket ) = explode( ':', $host, 2 );
        } elseif ( preg_match( '/^(.*):(\d+)$/', $host, $hm ) ) {
            $host = $hm[1]; $port = $hm[2];
        }
        if ( $socket )    $dsn = "mysql:unix_socket={$socket};dbname={$db_name};charset=utf8mb4";
        elseif ( $port )  $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
        else              $dsn = "mysql:host={$host};dbname={$db_name};charset=utf8mb4";
        try {
            $pdo = new \PDO( $dsn, $db_user, $db_pass, [ \PDO::ATTR_TIMEOUT => 5 ] );
            $tbl = $tb_prefix . 'users';
            $st  = $pdo->prepare( "SHOW TABLES LIKE " . $pdo->quote( $tbl ) );
            $st->execute();
            return (bool) $st->fetch();
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // Cantidad de filas en {prefijo}users — a diferencia de test_db_config()
    // (que solo confirma que la tabla EXISTE), esto confirma que hay datos
    // reales. Usado por la red de seguridad del fallback a la BD de WordPress.
    public static function count_users( $db_host, $db_name, $db_user, $db_pass, $tb_prefix ) {
        $host = $db_host; $port = ''; $socket = '';
        if ( strpos( $host, ':/' ) !== false ) {
            list( $host, $socket ) = explode( ':', $host, 2 );
        } elseif ( preg_match( '/^(.*):(\d+)$/', $host, $hm ) ) {
            $host = $hm[1]; $port = $hm[2];
        }
        if ( $socket )    $dsn = "mysql:unix_socket={$socket};dbname={$db_name};charset=utf8mb4";
        elseif ( $port )  $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
        else              $dsn = "mysql:host={$host};dbname={$db_name};charset=utf8mb4";
        try {
            $pdo = new \PDO( $dsn, $db_user, $db_pass, [ \PDO::ATTR_TIMEOUT => 5 ] );
            return (int) $pdo->query( "SELECT COUNT(*) FROM `{$tb_prefix}users`" )->fetchColumn();
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    // Respalda credenciales VALIDADAS en wp_options — sobrevive a los updates
    // por ZIP (que borran luna-wp-config.php) y permite regenerarlo bien.
    public static function backup_db_config( $defs ) {
        update_option( 'luna_db_backup', [
            'db_host'   => $defs['DB_HOST'] ?? 'localhost',
            'db_name'   => $defs['DB_NAME'] ?? '',
            'db_user'   => $defs['DB_USER'] ?? '',
            'db_pass'   => $defs['DB_PASS'] ?? '',
            'tb_prefix' => $defs['LUNA_TB_PREFIX'] ?? '',
        ], false );
    }

    // Si el config actual funciona, mantener el respaldo de wp_options
    // sincronizado con él. Se llama en plugins_loaded (todos los requests).
    // IMPORTANTE: siempre se re-valida y re-guarda si el config vigente
    // conecta bien — NO solo "una vez si falta la opción". La versión
    // anterior solo guardaba si la opción estaba vacía; si en algún momento
    // se guardó un respaldo incorrecto (o nunca se guardó porque esta misma
    // validación fallaba con mysqli), quedaba PERMANENTEMENTE sin corregirse
    // aunque el archivo real se arreglara después. Ahora se refresca en cada
    // carga mientras el archivo conecte, así el respaldo nunca queda obsoleto.
    public static function maybe_backup_current_config() {
        // Throttle: re-validar como máximo 1 vez cada 6hs (no en cada request
        // de todo el sitio WP) — pero SIEMPRE re-validar, nunca "una sola vez
        // para siempre", que es el bug que dejó el respaldo desactualizado.
        if ( get_transient( 'luna_db_backup_check' ) ) return;
        set_transient( 'luna_db_backup_check', 1, 6 * HOUR_IN_SECONDS );

        $cfg_path = LUNA_APP_DIR . 'luna-wp-config.php';
        if ( ! file_exists( $cfg_path ) ) return;
        $defs = self::parse_app_config( file_get_contents( $cfg_path ) );
        if ( empty( $defs['DB_NAME'] ) ) return;
        if ( self::test_db_config(
                $defs['DB_HOST'] ?? 'localhost', $defs['DB_NAME'],
                $defs['DB_USER'] ?? '', $defs['DB_PASS'] ?? '',
                $defs['LUNA_TB_PREFIX'] ?? '' ) ) {
            self::backup_db_config( $defs );
        }
    }

    // Actualiza solo los valores no-credenciales del config existente
    private static function patch_app_config() {
        $cfg_path = LUNA_APP_DIR . 'luna-wp-config.php';
        if ( ! file_exists( $cfg_path ) ) return;

        $content      = file_get_contents( $cfg_path );
        $license_key  = get_option( 'luna_license_key', '' );
        $license_srv  = get_option( 'luna_license_server_url', 'https://websobreruedas.com/wp-json/luna-licenses/v1/verify' );
        $site_url     = get_site_url();
        $upload_url   = LUNA_PLUGIN_URL . 'app/uploads/';
        $cron_secret  = get_option( 'luna_cron_secret', '' );
        $version      = LUNA_VERSION;

        $replacements = [
            "/define\('LUNA_VERSION',\s*[^)]+\);/"      => "define('LUNA_VERSION',   " . var_export($version,     true) . ");",
            "/define\('LUNA_LICENSE_KEY',\s*[^)]+\);/"  => "define('LUNA_LICENSE_KEY',    " . var_export($license_key,  true) . ");",
            "/define\('LUNA_LICENSE_SERVER',\s*[^)]+\);/" => "define('LUNA_LICENSE_SERVER', " . var_export($license_srv,  true) . ");",
            "/define\('LUNA_SITE_URL',\s*[^)]+\);/"     => "define('LUNA_SITE_URL',       " . var_export($site_url,    true) . ");",
            "/define\('LUNA_UPLOAD_URL',\s*[^)]+\);/"   => "define('LUNA_UPLOAD_URL',     " . var_export($upload_url,  true) . ");",
            "/define\('LUNA_CRON_SECRET',\s*[^)]+\);/"  => "define('LUNA_CRON_SECRET',  " . var_export($cron_secret,  true) . ");",
        ];

        foreach ( $replacements as $pattern => $replacement ) {
            if ( preg_match( $pattern, $content ) ) {
                $content = preg_replace( $pattern, $replacement, $content );
            } else {
                // La línea no existe en el archivo, agregarla al final
                $content = rtrim( $content ) . "\n" . $replacement . "\n";
            }
        }

        file_put_contents( $cfg_path, $content );
    }

    // ── Detectar el prefijo real de las tablas Luna escaneando la BD ─────────
    // Busca todas las tablas que terminan en "luna_users" (más "users" a secas,
    // por instalaciones standalone viejas) y devuelve el prefijo de la que tiene
    // usuarios. Prioriza el prefijo del WP actual: ahí escriben el reset de
    // contraseña y todas las herramientas del admin de WordPress.
    public static function detect_tb_prefix($db_host, $db_name, $db_user, $db_pass, $is_manual) {
        global $wpdb;
        $default = $wpdb->prefix . 'luna_';
        $tables  = []; // nombre de tabla users => cantidad de filas

        if (!$is_manual) {
            // Misma BD que WordPress → usar $wpdb
            $rows = $wpdb->get_col("SHOW TABLES LIKE '%luna\\_users'");
            if ($wpdb->get_var("SHOW TABLES LIKE 'users'")) $rows[] = 'users';
            foreach ((array)$rows as $t) {
                $tables[$t] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`");
            }
        } else {
            // BD manual/externa → conectar aparte para escanear (PDO, igual que
            // el resto del plugin — ver test_db_config() para el porqué).
            $host = $db_host; $port = ''; $socket = '';
            if (strpos($host, ':/') !== false) {
                list($host, $socket) = explode(':', $host, 2);
            } elseif (preg_match('/^(.*):(\d+)$/', $host, $hm)) {
                $host = $hm[1]; $port = $hm[2];
            }
            if ($socket)    $dsn = "mysql:unix_socket={$socket};dbname={$db_name};charset=utf8mb4";
            elseif ($port)  $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
            else            $dsn = "mysql:host={$host};dbname={$db_name};charset=utf8mb4";
            try {
                $pdo = new \PDO($dsn, $db_user, $db_pass, [\PDO::ATTR_TIMEOUT => 5]);
                $rows = array_column($pdo->query("SHOW TABLES LIKE '%luna\\_users'")->fetchAll(\PDO::FETCH_NUM), 0);
                if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) $rows[] = 'users';
                foreach ($rows as $t) {
                    $tables[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                }
            } catch (\Throwable $e) { /* no conecta: $tables queda vacío, se usa $default abajo */ }
        }

        if (!$tables) return $default;

        // Gana la tabla users con MÁS filas; el prefijo del WP actual solo
        // desempata. Antes el prefijo WP ganaba con tener >0 filas, pero la
        // activación del plugin siembra {prefijo}luna_users con 1 admin nuevo:
        // en sitios con los datos reales bajo otro prefijo (instalación vieja),
        // esa tabla recién sembrada le ganaba a la tabla real con todos los
        // usuarios, y ninguna credencial conocida funcionaba (401 para todos).
        $max = max($tables);
        if ($max <= 0) return $default;
        if (($tables[$default . 'users'] ?? -1) === $max) {
            return $default; // el prefijo WP tiene el máximo (o empata) → gana
        }
        arsort($tables); // estable: en empate conserva el orden de inserción
        reset($tables);
        $best = key($tables);
        if (substr($best, -5) === 'users') {
            return substr($best, 0, -5);
        }
        return $default;
    }

    public static function regenerate_app_config() {
        global $wpdb;

        // Prioridad de credenciales:
        // 1. Manuales (luna_manual_db) — configuración explícita
        // 2. Respaldo validado (luna_db_backup) — última config que funcionó,
        //    guardada en wp_options: sobrevive a updates por ZIP
        // 3. Credenciales de WordPress + auto-detección de prefijo
        $manual_db = get_option('luna_manual_db', []);
        $backup_db = get_option('luna_db_backup', []);
        if (!empty($manual_db['db_name'])) {
            $db_host = $manual_db['db_host'] ?? 'localhost';
            $db_name = $manual_db['db_name'];
            $db_user = $manual_db['db_user'] ?? DB_USER;
            $db_pass = $manual_db['db_pass'] ?? DB_PASSWORD;
            // tb_prefix vacío → auto-detectar igual que si fuera null
            $raw_prefix = isset($manual_db['tb_prefix']) ? trim($manual_db['tb_prefix']) : '';
            $tb_prefix = ($raw_prefix !== '') ? $raw_prefix : null;
        } elseif (!empty($backup_db['db_name']) && self::test_db_config(
                    $backup_db['db_host'] ?? 'localhost', $backup_db['db_name'],
                    $backup_db['db_user'] ?? '', $backup_db['db_pass'] ?? '',
                    $backup_db['tb_prefix'] ?? '')) {
            // El respaldo sigue funcionando → reusar sus credenciales (prefijo
            // incluido; '' es un prefijo válido en BD externa sin prefijo)
            $db_host   = $backup_db['db_host'] ?? 'localhost';
            $db_name   = $backup_db['db_name'];
            $db_user   = $backup_db['db_user'] ?? '';
            $db_pass   = $backup_db['db_pass'] ?? '';
            $tb_prefix = $backup_db['tb_prefix'] ?? '';
            // Re-verificar el PREFIJO del respaldo contra esa misma BD: si otra
            // tabla users tiene MÁS usuarios, el respaldo quedó apuntando a una
            // tabla equivocada (ej: la sembrada por la activación con 1 admin)
            // y hay que corregirlo — mismas credenciales, prefijo re-detectado.
            $detectado = self::detect_tb_prefix($db_host, $db_name, $db_user, $db_pass, true);
            if ($detectado !== $tb_prefix
                && self::count_users($db_host, $db_name, $db_user, $db_pass, $detectado)
                 > self::count_users($db_host, $db_name, $db_user, $db_pass, $tb_prefix)) {
                $tb_prefix = $detectado;
            }
        } else {
            $db_host = DB_HOST;
            $db_name = DB_NAME;
            $db_user = DB_USER;
            $db_pass = DB_PASSWORD;
            $tb_prefix = null; // se detecta abajo
            // Ni credenciales manuales ni respaldo válido: vamos a usar la BD de
            // WordPress como último recurso. Si esa BD no tiene NINGÚN dato real
            // de Luna, esto probablemente significa que el sitio usa una BD
            // externa cuyas credenciales se perdieron — avisar en vez de fallar
            // en silencio (se verifica más abajo, después de detectar el prefijo).
            $usar_fallback_wp = true;
        }

        $license_key    = get_option('luna_license_key', '');
        $license_server = get_option('luna_license_server_url', 'https://websobreruedas.com/wp-json/luna-licenses/v1/verify');
        $site_url       = get_site_url();
        $upload_url     = LUNA_PLUGIN_URL . 'app/uploads/';

        // ── Auto-detectar el prefijo real de las tablas ──────────────────────
        // Escanea la BD destino buscando CUALQUIER tabla *luna_users existente
        // (cubre sitios migrados donde el prefijo WP cambió, ej: HDrbEC_ vs wp_)
        // y elige la que tiene usuarios, priorizando el prefijo WP actual.
        if ($tb_prefix === null) {
            $tb_prefix = self::detect_tb_prefix($db_host, $db_name, $db_user, $db_pass, !empty($manual_db['db_name']));
        }

        // Red de seguridad: si estamos por escribir la BD de WordPress como
        // último recurso (sin manual ni respaldo) y no hay NINGÚN usuario real
        // ahí, dejar un aviso visible en wp-admin en vez de fallar en silencio.
        // OJO: no alcanza con que la tabla EXISTA — ensure_client_tables() la
        // crea vacía en cada request (CREATE TABLE IF NOT EXISTS). Hace falta
        // que tenga al menos una fila real.
        if (!empty($usar_fallback_wp)) {
            $hay_datos = self::count_users($db_host, $db_name, $db_user, $db_pass, $tb_prefix) > 0;
            if (!$hay_datos) {
                update_option('luna_config_recovery_warning', current_time('mysql'));
            } else {
                delete_option('luna_config_recovery_warning');
            }
        }

        $content = "<?php\n"
            . "// Auto-generated by Luna Workspace — do not edit.\n"
            . "// Prefijo detectado automáticamente: '{$tb_prefix}'\n"
            . "define('DB_HOST',        " . var_export($db_host,        true) . ");\n"
            . "define('DB_NAME',        " . var_export($db_name,        true) . ");\n"
            . "define('DB_USER',        " . var_export($db_user,        true) . ");\n"
            . "define('DB_PASS',        " . var_export($db_pass,        true) . ");\n"
            . "define('DB_CHARSET',     'utf8mb4');\n"
            . "define('LUNA_TB_PREFIX', " . var_export($tb_prefix,      true) . ");\n"
            . "define('SESSION_HOURS',  24);\n"
            . "define('LUNA_VERSION',   " . var_export(LUNA_VERSION, true) . ");\n"
            . "define('LUNA_LICENSE_KEY',    " . var_export($license_key,    true) . ");\n"
            . "define('LUNA_LICENSE_SERVER', " . var_export($license_server, true) . ");\n"
            . "define('LUNA_SITE_URL',       " . var_export($site_url,       true) . ");\n"
            . "define('LUNA_UPLOAD_URL',     " . var_export($upload_url,     true) . ");\n"
            . "define('LUNA_CRON_SECRET',  " . var_export(get_option('luna_cron_secret',''), true)  . ");\n";

        $result = file_put_contents(LUNA_APP_DIR . 'luna-wp-config.php', $content);

        if ($result === false) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Luna Workspace:</strong> No se pudo escribir <code>app/luna-wp-config.php</code>. Verificá los permisos de escritura en la carpeta del plugin.</p></div>';
            });
        }

        // Si lo generado realmente funciona, respaldarlo en wp_options
        if (self::test_db_config($db_host, $db_name, $db_user, $db_pass, $tb_prefix)) {
            self::backup_db_config([
                'DB_HOST' => $db_host, 'DB_NAME' => $db_name,
                'DB_USER' => $db_user, 'DB_PASS' => $db_pass,
                'LUNA_TB_PREFIX' => $tb_prefix,
            ]);
        }
        return $tb_prefix; // retorna el prefijo detectado
    }
}
