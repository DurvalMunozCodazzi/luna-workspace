<?php
defined('ABSPATH') || exit;

class LLS_License {

    const PLANS = [
        'free'         => ['label' => 'Gratis',       'max_workspaces' => 1,   'max_sites' => 1,   'max_users' => 1],
        'starter'      => ['label' => 'Emprendedor',  'max_workspaces' => 1,   'max_sites' => 1,   'max_users' => 5],
        'pyme'         => ['label' => 'Pyme',          'max_workspaces' => 2,   'max_sites' => 1,   'max_users' => 10],
        'professional' => ['label' => 'Profesional',  'max_workspaces' => 3,   'max_sites' => 3,   'max_users' => 20],
        'unlimited'    => ['label' => 'Corporativo',  'max_workspaces' => 999, 'max_sites' => 999, 'max_users' => 999],
    ];

    // Generate a unique license key: LUNA-XXXX-XXXX-XXXX-XXXX
    public static function generate_key(): string {
        do {
            $parts = [];
            for ($i = 0; $i < 4; $i++) {
                $parts[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            }
            $key = 'LUNA-' . implode('-', $parts);
        } while (self::key_exists($key));
        return $key;
    }

    public static function key_exists(string $key): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_licenses';
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$t}` WHERE license_key = %s LIMIT 1", $key));
    }

    public static function create(array $data): int|false {
        global $wpdb;
        $t    = $wpdb->prefix . 'lls_licenses';
        $plan = $data['plan'] ?? 'starter';
        $caps = self::PLANS[$plan] ?? self::PLANS['starter'];

        $row = [
            'license_key'    => $data['license_key'] ?? self::generate_key(),
            'customer_name'  => sanitize_text_field($data['customer_name']  ?? ''),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'domain'         => self::normalize_domain($data['domain'] ?? ''),
            'plan'           => $plan,
            'status'         => 'active',
            'max_workspaces' => (int)($data['max_workspaces'] ?? $caps['max_workspaces']),
            'max_sites'      => (int)($data['max_sites']      ?? $caps['max_sites']),
            'max_users'      => (int)($data['max_users']      ?? $caps['max_users']),
            'expires_at'     => !empty($data['expires_at']) ? $data['expires_at'] : null,
            'notes'          => sanitize_textarea_field($data['notes'] ?? ''),
        ];

        $result = $wpdb->insert($t, $row);
        return $result ? $wpdb->insert_id : false;
    }

    public static function get_by_key(string $key): ?array {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_licenses';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t}` WHERE license_key = %s LIMIT 1", $key), ARRAY_A);
        return $row ?: null;
    }

    public static function get_all(int $per_page = 50, int $page = 1, string $search = '', string $status = ''): array {
        global $wpdb;
        $t      = $wpdb->prefix . 'lls_licenses';
        $offset = ($page - 1) * $per_page;
        $where  = ['1=1'];
        $args   = [];

        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(license_key LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR domain LIKE %s)";
            $args = array_merge($args, [$like, $like, $like, $like]);
        }
        if ($status) {
            $where[] = "status = %s";
            $args[]  = $status;
        }

        $sql_where  = implode(' AND ', $where);
        $args_paged = array_merge($args, [$per_page, $offset]);

        // prepare() requires at least one placeholder — use it only when we have args
        if ($args_paged) {
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$t}` WHERE {$sql_where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$args_paged),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM `{$t}` WHERE {$sql_where} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}",
                ARRAY_A
            );
        }

        if ($args) {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE {$sql_where}", ...$args));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}` WHERE {$sql_where}");
        }

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function update(int $id, array $data): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_licenses';

        $allowed = ['customer_name','customer_email','domain','plan','status','max_workspaces','max_sites','max_users','expires_at','notes'];
        $update  = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (isset($update['domain']))         $update['domain']         = self::normalize_domain($update['domain']);
        if (isset($update['customer_name']))  $update['customer_name']  = sanitize_text_field($update['customer_name']);
        if (isset($update['customer_email'])) $update['customer_email'] = sanitize_email($update['customer_email']);
        if (isset($update['notes']))          $update['notes']          = sanitize_textarea_field($update['notes']);
        if (isset($update['plan'])) {
            $caps = self::PLANS[$update['plan']] ?? self::PLANS['starter'];
            if (!isset($data['max_workspaces'])) $update['max_workspaces'] = $caps['max_workspaces'];
            if (!isset($data['max_sites']))      $update['max_sites']      = $caps['max_sites'];
            if (!isset($data['max_users']))      $update['max_users']      = $caps['max_users'];
        }

        if (empty($update)) return false;
        return (bool) $wpdb->update($t, $update, ['id' => $id]);
    }

    public static function delete(int $id): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_licenses';
        return (bool) $wpdb->delete($t, ['id' => $id]);
    }

    public static function verify(string $key, string $domain): array {
        global $wpdb;
        $t_log  = $wpdb->prefix . 'lls_verify_log';
        $ip     = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $domain = self::normalize_domain($domain);

        $lic = self::get_by_key($key);

        if (!$lic) {
            self::log($key, $domain, 'not_found', $ip);
            return ['valid' => false, 'reason' => 'not_found', 'message' => 'Clave de licencia no encontrada.'];
        }

        if ($lic['status'] === 'suspended') {
            self::log($key, $domain, 'suspended', $ip);
            return ['valid' => false, 'reason' => 'suspended', 'message' => 'Esta licencia está suspendida.'];
        }

        if ($lic['status'] === 'inactive') {
            self::log($key, $domain, 'invalid', $ip);
            return ['valid' => false, 'reason' => 'inactive', 'message' => 'Esta licencia está inactiva.'];
        }

        if ($lic['expires_at'] && $lic['expires_at'] < date('Y-m-d')) {
            self::log($key, $domain, 'expired', $ip);
            return ['valid' => false, 'reason' => 'expired', 'message' => 'Esta licencia expiró el ' . $lic['expires_at'] . '.'];
        }

        // Domain check — if license has a domain, it must match
        if (!empty($lic['domain']) && $lic['domain'] !== $domain) {
            self::log($key, $domain, 'invalid', $ip);
            return [
                'valid'   => false,
                'reason'  => 'domain_mismatch',
                'message' => 'Esta licencia no corresponde al dominio ' . $domain . '.',
            ];
        }

        // Valid — build signed response
        self::log($key, $domain, 'valid', $ip);

        $issued_at  = date('Y-m-d\TH:i:s\Z');
        $expires_at = $lic['expires_at'] ?? '';

        $sign_payload = implode('|', [
            $key,
            $lic['domain'] ?: $domain,
            'true',
            $lic['plan'],
            $expires_at,
            $issued_at,
        ]);

        $sig = '';
        $private_pem = get_option('lls_private_key', '');
        if ($private_pem) {
            $pkey = openssl_pkey_get_private($private_pem);
            if ($pkey) {
                openssl_sign($sign_payload, $raw_sig, $pkey, OPENSSL_ALGO_SHA256);
                $sig = base64_encode($raw_sig);
            }
        }

        return [
            'valid'          => true,
            'plan'           => $lic['plan'],
            'domain'         => $lic['domain'] ?: $domain,
            'expires_at'     => $expires_at,
            'issued_at'      => $issued_at,
            'max_workspaces' => (int) $lic['max_workspaces'],
            'max_sites'      => (int) $lic['max_sites'],
            'max_users'      => isset($lic['max_users']) ? (int) $lic['max_users'] : 999,
            'sig'            => $sig,
        ];
    }

    public static function log(string $key, string $domain, string $result, string $ip): void {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_verify_log';
        $wpdb->insert($t, [
            'license_key' => $key,
            'domain'      => $domain,
            'result'      => $result,
            'ip'          => $ip,
        ]);
        // Keep log table from growing unbounded — prune entries older than 90 days
        $wpdb->query("DELETE FROM `{$t}` WHERE verified_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    public static function get_log(string $key = '', int $limit = 50): array {
        global $wpdb;
        $t = $wpdb->prefix . 'lls_verify_log';
        if ($key) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM `{$t}` WHERE license_key = %s ORDER BY verified_at DESC LIMIT %d", $key, $limit
            ), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$t}` ORDER BY verified_at DESC LIMIT %d", $limit
        ), ARRAY_A) ?: [];
    }

    public static function normalize_domain(string $d): string {
        $d = trim($d);
        // Strip protocol and path, keep only hostname
        if (str_contains($d, '://')) {
            $d = parse_url($d, PHP_URL_HOST) ?? $d;
        }
        // Remove www. prefix
        $d = preg_replace('/^www\./i', '', $d);
        return strtolower(trim($d, '/'));
    }

    public static function plan_label(string $plan): string {
        return self::PLANS[$plan]['label'] ?? ucfirst($plan);
    }
}
