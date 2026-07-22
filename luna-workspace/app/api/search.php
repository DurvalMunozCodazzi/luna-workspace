<?php
require_once '../config.php';
$me = requireAuth();
$db = getDB();

$q     = trim($_GET['q'] ?? '');
$wsId  = intval($_SERVER['HTTP_X_WORKSPACE_ID'] ?? $_GET['ws'] ?? 0);
$limit = min(50, intval($_GET['limit'] ?? 20));

if (strlen($q) < 2) jsonErr('Búsqueda muy corta — mínimo 2 caracteres');

$like = '%' . $q . '%';

// Search cards across all workspaces the user has access to
if ($me['role'] === 'admin') {
    // Admin searches all workspaces
    $wsFilter = $wsId ? "AND c.workspace_id = ?" : "";
    $params   = $wsId ? [$like, $like, $like, $wsId] : [$like, $like, $like];
    $st = $db->prepare("
        SELECT c.id, c.title, c.description, c.priority, c.due_date, c.start_date,
               c.workspace_id, c.column_id,
               k.title as column_title, k.color as column_color,
               w.name as workspace_name,
               (SELECT COUNT(*) FROM ".tb('card_checklist')." cc WHERE cc.card_id=c.id) as checklist_count,
               (SELECT COUNT(*) FROM ".tb('card_checklist')." cc WHERE cc.card_id=c.id AND cc.is_done=1) as checklist_done
        FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        JOIN ".tb('workspaces')." w ON w.id = c.workspace_id
        WHERE (c.title LIKE ? OR c.description LIKE ? OR EXISTS (
            SELECT 1 FROM ".tb('card_tags')." t WHERE t.card_id=c.id AND t.label LIKE ?
        )) $wsFilter
        ORDER BY c.updated_at DESC LIMIT $limit
    ");
    $st->execute($params);
} else {
    // Members only see their workspaces
    $params = [$like, $like, $like, $me['id']];
    if ($wsId) $params[] = $wsId;
    $wsFilter = $wsId ? "AND c.workspace_id=?" : "";
    $st = $db->prepare("
        SELECT c.id, c.title, c.description, c.priority, c.due_date, c.start_date,
               c.workspace_id, c.column_id,
               k.title as column_title, k.color as column_color,
               w.name as workspace_name,
               (SELECT COUNT(*) FROM ".tb('card_checklist')." cc WHERE cc.card_id=c.id) as checklist_count,
               (SELECT COUNT(*) FROM ".tb('card_checklist')." cc WHERE cc.card_id=c.id AND cc.is_done=1) as checklist_done
        FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        JOIN ".tb('workspaces')." w ON w.id = c.workspace_id
        JOIN ".tb('workspace_members')." wm ON wm.workspace_id=c.workspace_id AND wm.user_id=?
        WHERE (c.title LIKE ? OR c.description LIKE ? OR EXISTS (
            SELECT 1 FROM ".tb('card_tags')." t WHERE t.card_id=c.id AND t.label LIKE ?
        )) $wsFilter
        ORDER BY c.updated_at DESC LIMIT $limit
    ");
    // Reorder params: user_id first, then search params
    $params = [$me['id'], $like, $like, $like];
    if ($wsId) $params[] = $wsId;
    $st->execute($params);
}

$results = $st->fetchAll();

// Also search users (admin only)
$users = [];
if ($me['role'] === 'admin') {
    $u = $db->prepare("SELECT id,name,email,cargo,dept,role,color FROM ".tb('users')." WHERE (name LIKE ? OR email LIKE ? OR username LIKE ?) AND active=1 LIMIT 5");
    $u->execute([$like, $like, $like]);
    $users = $u->fetchAll();
}

jsonOut([
    'query'   => $q,
    'cards'   => $results,
    'users'   => $users,
    'total'   => count($results) + count($users),
]);
