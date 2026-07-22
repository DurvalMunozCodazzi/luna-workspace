<?php
require_once '../config.php';
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);

$me = requireAuth();

// ── GET lista de workspaces del usuario ──────────────────────
if ($method === 'GET' && $action === 'list') {
    // Admin ve todos; miembro/visitante ve los que tiene acceso
    if ($me['role'] === 'admin') {
        $rows = $db->query("
            SELECT w.*, u.name as owner_name,
                   (SELECT COUNT(*) FROM ".tb('columns_k')." c WHERE c.workspace_id=w.id) as col_count,
                   (SELECT COUNT(*) FROM ".tb('cards')." ca WHERE ca.workspace_id=w.id) as card_count
            FROM ".tb('workspaces')." w
            LEFT JOIN ".tb('users')." u ON u.id = w.created_by
            ORDER BY w.updated_at DESC
        ")->fetchAll();
    } else {
        // Miembros ven workspaces donde tienen acceso
        $rows = $db->prepare("
            SELECT w.*, u.name as owner_name,
                   (SELECT COUNT(*) FROM ".tb('columns_k')." c WHERE c.workspace_id=w.id) as col_count,
                   (SELECT COUNT(*) FROM ".tb('cards')." ca WHERE ca.workspace_id=w.id) as card_count
            FROM ".tb('workspaces')." w
            LEFT JOIN ".tb('users')." u ON u.id = w.created_by
            JOIN ".tb('workspace_members')." wm ON wm.workspace_id = w.id AND wm.user_id = ?
            ORDER BY w.updated_at DESC
        ");
        $rows->execute([$me['id']]);
        $rows = $rows->fetchAll();
    }
    jsonOut(['workspaces' => $rows]);
}

// ── GET workspace activo del usuario ─────────────────────────
if ($method === 'GET' && $action === 'current') {
    // Leer el último workspace activo del usuario
    $wsId = intval(getUserMeta($db, $me['id'], 'active_workspace') ?: 1);
    // Verificar acceso
    if ($me['role'] !== 'admin') {
        $acc = $db->prepare("SELECT id FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?");
        $acc->execute([$wsId, $me['id']]);
        if (!$acc->fetch()) {
            // Buscar cualquier workspace al que tenga acceso
            $any = $db->prepare("SELECT workspace_id FROM ".tb('workspace_members')." WHERE user_id=? LIMIT 1");
            $any->execute([$me['id']]);
            $row = $any->fetch();
            $wsId = $row ? $row['workspace_id'] : 1;
        }
    }
    jsonOut(['workspace_id' => $wsId]);
}

// ── POST crear workspace ──────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    if ($me['role'] === 'visitor') jsonErr('Sin permisos', 403);

    // License check
    try { $lic = getLicenseInfo(); } catch (\Exception $e) { $lic = ['valid' => true, 'plan' => 'offline', 'max_workspaces' => 1]; }
    $max = isset($lic['max_workspaces']) ? (int)$lic['max_workspaces'] : 1;
    if ($max < 9999) {  // 9999 = Unlimited plan stored in DB; anything less is enforced
        $total = (int)$db->query("SELECT COUNT(*) FROM ".tb('workspaces'))->fetchColumn();
        if ($total >= $max) {
            jsonErr('Límite de workspaces alcanzado para tu plan (' . $max . '). Actualizá tu licencia en el panel de WordPress.', 403);
        }
    }
    $b    = json_decode(file_get_contents('php://input'), true);
    $name = trim($b['name'] ?? 'Nuevo Workspace');
    $desc = trim($b['description'] ?? '');

    $color = substr(trim($b['color'] ?? '#5b6af0'),0,10);
    $icon  = trim($b['icon'] ?? 'fa-project-diagram');
    $image = $b['image'] ?? null;
    $db->prepare("INSERT INTO ".tb('workspaces')." (name, description, created_by, color, icon, image) VALUES (?,?,?,?,?,?)")
       ->execute([$name, $desc, $me['id'], $color, $icon, $image]);
    $newId = $db->lastInsertId();

    // Crear columnas iniciales
    $cols = [
        ['Por hacer',   '#5b6af0', 0],
        ['En progreso', '#f59e0b', 1],
        ['En revisión', '#06b6d4', 2],
        ['Completado',  '#22d3a0', 3],
    ];
    // Use template columns if provided
    $templateCols = null;
    if (!empty($b['template_columns'])) {
        $templateCols = json_decode($b['template_columns'], true);
    }
    if ($templateCols && count($templateCols) > 0) {
        foreach ($templateCols as $pos => $col) {
            $db->prepare("INSERT INTO ".tb('columns_k')." (workspace_id,title,color,position) VALUES (?,?,?,?)")
               ->execute([$newId, $col['title'], $col['color']??'#5b6af0', $pos]);
        }
    } else {
        foreach ($cols as $col) {
            $db->prepare("INSERT INTO ".tb('columns_k')." (workspace_id,title,color,position) VALUES (?,?,?,?)")
               ->execute([$newId, $col[0], $col[1], $col[2]]);
        }
    }

    // Agregar al creador como miembro admin del workspace
    $db->prepare("INSERT IGNORE INTO ".tb('workspace_members')." (workspace_id,user_id,role) VALUES (?,?,'admin')")
       ->execute([$newId, $me['id']]);

    // Si es admin del sistema, agregar a todos los admins
    if ($me['role'] === 'admin') {
        $admins = $db->query("SELECT id FROM ".tb('users')." WHERE role='admin' AND active=1")->fetchAll();
        foreach ($admins as $a) {
            $db->prepare("INSERT IGNORE INTO ".tb('workspace_members')." (workspace_id,user_id,role) VALUES (?,?,'admin')")
               ->execute([$newId, $a['id']]);
        }
    }

    $ws = $db->prepare("SELECT * FROM ".tb('workspaces')." WHERE id=?");
    $ws->execute([$newId]);
    jsonOut(['workspace' => $ws->fetch()], 201);
}

// ── PUT renombrar/editar workspace ───────────────────────────
if ($method === 'PUT' && $action === 'update') {
    if (!$id) jsonErr('ID requerido');
    checkWsAccess($db, $me, $id, true); // requiere ser admin del ws
    $b = json_decode(file_get_contents('php://input'), true);
    $sets = []; $vals = [];
    if (isset($b['name']))        { $sets[]='name=?';        $vals[]=trim($b['name']); }
    if (isset($b['description'])) { $sets[]='description=?'; $vals[]=trim($b['description']); }
    if (isset($b['canvas']))      { $sets[]='canvas=?';      $vals[]=$b['canvas']; }
    if (isset($b['color']))           { $sets[]='color=?'; $vals[]=substr(trim($b['color']),0,10); }
    if (isset($b['icon']))            { $sets[]='icon=?';  $vals[]=trim($b['icon']); }
    if (array_key_exists('image',$b)) { $sets[]='image=?'; $vals[]=$b['image']; }
    if ($sets) { $vals[]=$id; $db->prepare("UPDATE ".tb('workspaces')." SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    jsonOut(['ok' => true]);
}

// ── DELETE workspace ──────────────────────────────────────────
if ($method === 'DELETE' && $action === 'delete') {
    if (!$id) jsonErr('ID requerido');
    if ($id === 1) jsonErr('No se puede eliminar el workspace principal');
    checkWsAccess($db, $me, $id, true);
    $db->prepare("DELETE FROM ".tb('workspaces')." WHERE id=?")->execute([$id]);
    jsonOut(['ok' => true]);
}

// ── POST gestionar miembros del workspace ─────────────────────
if ($method === 'POST' && $action === 'add_member') {
    checkWsAccess($db, $me, $id, true);
    $b      = json_decode(file_get_contents('php://input'), true);
    $userId = intval($b['user_id'] ?? 0);
    $role   = in_array($b['role']??'', ['admin','member','visitor']) ? $b['role'] : 'member';
    if (!$userId) jsonErr('user_id requerido');
    $db->prepare("INSERT INTO ".tb('workspace_members')." (workspace_id,user_id,role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role=?")
       ->execute([$id, $userId, $role, $role]);
    jsonOut(['ok' => true]);
}

if ($method === 'DELETE' && $action === 'remove_member') {
    checkWsAccess($db, $me, $id, true);
    $userId = intval($_GET['user_id'] ?? 0);
    if (!$userId) jsonErr('user_id requerido');
    $db->prepare("DELETE FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?")->execute([$id, $userId]);
    jsonOut(['ok' => true]);
}

// ── GET miembros de un workspace ─────────────────────────────
if ($method === 'GET' && $action === 'members') {
    checkWsAccess($db, $me, $id, false);
    $rows = $db->prepare("
        SELECT u.id, u.name, u.email, u.cargo, u.dept, u.color, u.photo,
               wm.role as ws_role
        FROM ".tb('workspace_members')." wm
        JOIN ".tb('users')." u ON u.id = wm.user_id
        WHERE wm.workspace_id = ? AND u.active = 1
        ORDER BY wm.role, u.name
    ");
    $rows->execute([$id]);
    jsonOut(['members' => $rows->fetchAll()]);
}

// ── POST cambiar workspace activo del usuario ─────────────────
if ($method === 'POST' && $action === 'switch') {
    $b    = json_decode(file_get_contents('php://input'), true);
    $wsId = intval($b['workspace_id'] ?? 1);
    setUserMeta($db, $me['id'], 'active_workspace', $wsId);
    jsonOut(['ok' => true, 'workspace_id' => $wsId]);
}

// ── Helpers ───────────────────────────────────────────────────
function checkWsAccess($db, $me, $wsId, $requireAdmin = false) {
    if ($me['role'] === 'admin') return; // admin del sistema tiene acceso a todo
    $st = $db->prepare("SELECT role FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?");
    $st->execute([$wsId, $me['id']]);
    $row = $st->fetch();
    if (!$row) jsonErr('Sin acceso a este workspace', 403);
    if ($requireAdmin && $row['role'] !== 'admin') jsonErr('Se requiere rol admin en el workspace', 403);
}

function getUserMeta($db, $userId, $key) {
    $st = $db->prepare("SELECT meta_value FROM ".tb('user_meta')." WHERE user_id=? AND meta_key=?");
    $st->execute([$userId, $key]);
    $row = $st->fetch();
    return $row ? $row['meta_value'] : null;
}

function setUserMeta($db, $userId, $key, $value) {
    $db->prepare("INSERT INTO ".tb('user_meta')." (user_id,meta_key,meta_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE meta_value=?")
       ->execute([$userId, $key, $value, $value]);
}

// ── GET templates ────────────────────────────────────────────
if ($method === 'GET' && $action === 'templates') {
    requireAuth();
    $db = getDB();
    try {
        $rows = $db->query("SELECT * FROM ".tb('workspace_templates')." WHERE is_default=1 ORDER BY id")->fetchAll();
        jsonOut(['templates' => $rows]);
    } catch (Exception $e) {
        jsonOut(['templates' => []]);
    }
}

// ── Handle template_columns on create ────────────────────────
// (Already handled in create endpoint - need to check for template_columns)

jsonErr('Acción no encontrada', 404);
