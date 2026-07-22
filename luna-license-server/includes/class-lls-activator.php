<?php
defined('ABSPATH') || exit;

class LLS_Activator {

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t_lic   = $wpdb->prefix . 'lls_licenses';
        $t_log   = $wpdb->prefix . 'lls_verify_log';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Create/update tables via dbDelta
        dbDelta("CREATE TABLE {$t_lic} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key   VARCHAR(64)  NOT NULL,
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_email VARCHAR(120) NOT NULL DEFAULT '',
            domain        VARCHAR(255) NOT NULL DEFAULT '',
            plan          VARCHAR(32)  NOT NULL DEFAULT 'starter',
            status        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            max_workspaces SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            max_sites      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            max_users      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            expires_at    DATE         NULL DEFAULT NULL,
            notes         TEXT         NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_key (license_key),
            KEY          idx_domain (domain),
            KEY          idx_status (status)
        ) {$charset};");

        $t_req = $wpdb->prefix . 'lls_requests';
        dbDelta("CREATE TABLE {$t_req} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre         VARCHAR(120) NOT NULL DEFAULT '',
            email          VARCHAR(120) NOT NULL DEFAULT '',
            telefono       VARCHAR(30)  NOT NULL DEFAULT '',
            dominio        VARCHAR(255) NOT NULL DEFAULT '',
            plan           VARCHAR(32)  NOT NULL DEFAULT 'free',
            status         ENUM('pending','sent','rejected') NOT NULL DEFAULT 'pending',
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t_log} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(64)  NOT NULL,
            domain      VARCHAR(255) NOT NULL DEFAULT '',
            result      ENUM('valid','invalid','expired','suspended','not_found') NOT NULL,
            ip          VARCHAR(45)  NOT NULL DEFAULT '',
            verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_key (license_key),
            KEY idx_date (verified_at)
        ) {$charset};");

        // Force-migrate licenses table — dbDelta doesn't modify existing column definitions
        $migrations_lic = [
            "max_workspaces" => "ALTER TABLE `{$t_lic}` ADD COLUMN `max_workspaces` SMALLINT UNSIGNED NOT NULL DEFAULT 1",
            "max_sites"      => "ALTER TABLE `{$t_lic}` ADD COLUMN `max_sites` SMALLINT UNSIGNED NOT NULL DEFAULT 1",
            "notes"          => "ALTER TABLE `{$t_lic}` ADD COLUMN `notes` TEXT NULL",
            "updated_at"     => "ALTER TABLE `{$t_lic}` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        $lic_cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$t_lic}`", ARRAY_A), 'Field');
        foreach ($migrations_lic as $col => $sql) {
            if (!in_array($col, $lic_cols)) {
                $wpdb->query($sql);
            }
        }
        // Always ensure expires_at allows NULL (old schemas may have had NOT NULL)
        $wpdb->query("ALTER TABLE `{$t_lic}` MODIFY COLUMN `expires_at` DATE NULL DEFAULT NULL");

        // Force-migrate log table
        $log_cols = array_column($wpdb->get_results("SHOW COLUMNS FROM `{$t_log}`", ARRAY_A), 'Field');
        if (!in_array('verified_at', $log_cols)) {
            if (in_array('created_at', $log_cols)) {
                $wpdb->query("ALTER TABLE `{$t_log}` CHANGE `created_at` `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            } else {
                $wpdb->query("ALTER TABLE `{$t_log}` ADD COLUMN `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                $wpdb->query("ALTER TABLE `{$t_log}` ADD KEY `idx_date` (`verified_at`)");
            }
        }

        update_option('lls_version', LLS_VERSION);
    }

    public static function deactivate(): void {}
}
