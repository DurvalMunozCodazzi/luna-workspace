<?php
// Diagnóstico de conexión — requiere autenticación de admin
require_once '../config.php';
$me = requireAdmin();

$out = [
    'php'       => PHP_VERSION,
    'version'   => defined('LUNA_VERSION') ? LUNA_VERSION : '?',
    'cred_file' => file_exists(__DIR__ . '/../luna-wp-config.php') ? 'OK' : 'FALTA',
    'db'        => 'checking...',
];
try {
    $db = getDB();
    $userCount = (int)$db->query("SELECT COUNT(*) FROM " . tb('users'))->fetchColumn();
    $out['db'] = 'OK — ' . $userCount . ' usuario(s)';
} catch (Exception $e) {
    $out['db'] = 'ERROR';
}
header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
