<?php
require_once '../config.php';
$me = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') jsonErr('Método no permitido', 405);

$id = intval($_GET['id'] ?? 0);
if (!$id) jsonErr('ID requerido');

$db = getDB();
$att = $db->prepare("SELECT * FROM ".tb('attachments')." WHERE id=?");
$att->execute([$id]);
$row = $att->fetch();
if (!$row) jsonErr('Adjunto no encontrado', 404);

// Verificar que el usuario tiene acceso al workspace que contiene la tarjeta
$card = $db->prepare("SELECT c.workspace_id FROM ".tb('cards')." c WHERE c.id=?");
$card->execute([$row['card_id']]);
$cardRow = $card->fetch();
if (!$cardRow) jsonErr('Sin permisos', 403);

if ($me['role'] !== 'admin') {
    $wsCheck = $db->prepare("SELECT role FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?");
    $wsCheck->execute([$cardRow['workspace_id'], $me['id']]);
    if (!$wsCheck->fetch()) jsonErr('Sin permisos', 403);
}

// Eliminar archivo físico de forma segura (evitar path traversal)
if ($row['type'] === 'local' && $row['url']) {
    $uploadsDir = realpath(dirname(__DIR__) . '/uploads');
    if ($uploadsDir) {
        $filePath = realpath($uploadsDir . DIRECTORY_SEPARATOR . basename($row['url']));
        if ($filePath && strpos($filePath, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && file_exists($filePath)) {
            unlink($filePath);
        }
    }
}

$db->prepare("DELETE FROM ".tb('attachments')." WHERE id=?")->execute([$id]);
$db->prepare("UPDATE ".tb('cards')." SET updated_at=NOW() WHERE id=?")->execute([$row['card_id']]);

jsonOut(['ok' => true]);
