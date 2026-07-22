<?php
require_once '../config.php';
$me = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Método no permitido', 405);
if (isVisitorRole($me)) jsonErr('Sin permisos', 403);

// Config
define('UPLOAD_DIR',  dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL',  defined('LUNA_UPLOAD_URL') ? LUNA_UPLOAD_URL : '/uploads/');
define('MAX_SIZE',    10 * 1024 * 1024); // 10MB
define('ALLOWED_EXT', ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','mp4','mp3']);

// Crear carpeta si no existe
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
// Proteger la carpeta: deshabilitar ejecución PHP y listado de directorio
$htaccess = UPLOAD_DIR . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Options -Indexes\nphp_flag engine off\nAddType text/plain .php .php3 .php4 .php5 .phtml .phar\n");
}

$cardId = intval($_POST['card_id'] ?? 0);
if (!$cardId) jsonErr('card_id requerido');

// Verificar que la tarjeta existe Y que pertenece a un workspace del usuario
// (antes solo se verificaba que la tarjeta existiera — cualquier usuario
// autenticado podía adjuntar archivos a tarjetas de otros workspaces)
$db = getDB();
$card = $db->prepare("SELECT id, workspace_id FROM ".tb('cards')." WHERE id=?");
$card->execute([$cardId]);
$cardRow = $card->fetch();
if (!$cardRow) jsonErr('Tarjeta no encontrada', 404);
if ($me['role'] !== 'admin') {
    $acc = $db->prepare("SELECT role FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?");
    $acc->execute([$cardRow['workspace_id'], $me['id']]);
    if (!$acc->fetch()) jsonErr('Sin acceso a este workspace', 403);
}

if (empty($_FILES['file'])) jsonErr('No se recibió ningún archivo');

$file   = $_FILES['file'];
$errors = [1=>'El archivo es muy grande',2=>'Parcialmente subido',3=>'Subida incompleta',4=>'Sin archivo'];

if ($file['error'] !== UPLOAD_ERR_OK) jsonErr($errors[$file['error']] ?? 'Error desconocido');
if ($file['size'] > MAX_SIZE) jsonErr('El archivo supera el límite de 10MB');

// Validar extensión
$origName = $file['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXT)) jsonErr("Tipo de archivo no permitido: .$ext");

// Validar MIME type con lista estricta (sin wildcards ni application/octet-stream)
$allowedMimes = [
    'image/jpeg','image/png','image/gif','image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain','text/csv',
    'video/mp4','audio/mpeg','audio/mp3',
];
$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowedMimes, true)) {
    jsonErr("Tipo de archivo no permitido");
}

// Nombre único para evitar colisiones
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$safeName = preg_replace('/_+/', '_', $safeName);
$unique   = date('Ymd_His') . '_' . $me['id'] . '_' . uniqid() . '.' . $ext;
$destPath = UPLOAD_DIR . $unique;

if (!move_uploaded_file($file['tmp_name'], $destPath)) jsonErr('Error al guardar el archivo en el servidor');

// Guardar en BD
$url = UPLOAD_URL . $unique;
$db->prepare("INSERT INTO ".tb('attachments')." (card_id, name, type, url, drive_id) VALUES (?,?,?,?,?)")
   ->execute([$cardId, $origName, 'local', $url, '']);
$newId = $db->lastInsertId();

// Actualizar updated_at de la tarjeta para polling
$db->prepare("UPDATE ".tb('cards')." SET updated_at=NOW() WHERE id=?")->execute([$cardId]);

jsonOut([
    'attachment' => [
        'id'      => $newId,
        'card_id' => $cardId,
        'name'    => $origName,
        'type'    => 'local',
        'url'     => $url,
        'ext'     => $ext,
        'size'    => $file['size'],
    ]
], 201);

function isVisitorRole($user) { return $user['role'] === 'visitor'; }
