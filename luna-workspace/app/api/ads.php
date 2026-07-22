<?php
// Devuelve los banners publicitarios del plan gratuito/trial, obtenidos del
// luna-ads.json publicado por Luna License Server. Solo se muestran para
// cuentas en plan free/trial — un cliente pago nunca ve publicidad.
require_once '../config.php';
requireAuth();

$lic  = getLicenseInfo();
$plan = $lic['plan'] ?? 'none';

if (!in_array($plan, ['free', 'none'], true)) {
    jsonOut(['banners' => [], 'upgrade_url' => '', 'upgrade_text' => '', 'plan' => $plan]);
}

$empty = ['banners' => [], 'upgrade_url' => 'https://websobreruedas.com/luna-planes', 'upgrade_text' => '⚡ Quitar anuncios'];

$cache_file = __DIR__ . '/../luna-ads-cache.json';
$ttl        = 6 * 3600; // 6 horas
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if (is_array($cached)) jsonOut($cached + ['plan' => $plan]);
}

$host   = parse_url(LUNA_LICENSE_SERVER, PHP_URL_HOST)   ?: 'websobreruedas.com';
$scheme = parse_url(LUNA_LICENSE_SERVER, PHP_URL_SCHEME) ?: 'https';
$url    = $scheme . '://' . $host . '/luna-ads.json';

$ctx  = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
$resp = @file_get_contents($url, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;

if (!is_array($data)) {
    // Sin conexión — usar cache vieja si existe, si no un set vacío (nunca bloquea la app)
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
    }
    if (!is_array($data)) $data = $empty;
} else {
    @file_put_contents($cache_file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

jsonOut($data + ['plan' => $plan]);
