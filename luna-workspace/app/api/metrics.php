<?php
require_once '../config.php';
$me = requireAuth();
$db = getDB();

$wsId = intval($_SERVER['HTTP_X_WORKSPACE_ID'] ?? $_GET['ws'] ?? 1);
if ($wsId < 1) $wsId = 1;

// Verificar acceso al workspace — sin esto cualquier usuario autenticado
// podía ver las métricas de cualquier otro workspace cambiando ?ws=
if ($me['role'] !== 'admin') {
    $acc = $db->prepare("SELECT role FROM ".tb('workspace_members')." WHERE workspace_id=? AND user_id=?");
    $acc->execute([$wsId, $me['id']]);
    if (!$acc->fetch()) jsonErr('Sin acceso a este workspace', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'dashboard';

if ($method === 'GET' && $action === 'dashboard') {

    // ── Date range for historical queries (completed, velocity, sparkline) ──
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    // Basic validation — reject anything that's not a date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];

    // Helper: subquery for completed-column detection
    $completedColSub = "(SELECT MAX(position) FROM ".tb('columns_k')." WHERE workspace_id=?)";

    // ── CURRENT STATE KPIs (always real-time, no date filter) ───────────────

    // Total tasks
    $st = $db->prepare("SELECT COUNT(*) FROM ".tb('cards')." WHERE workspace_id=?");
    $st->execute([$wsId]);
    $totalCards = (int)$st->fetchColumn();

    // Pending tasks (not in completed columns)
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND k.title NOT LIKE '%complet%'
          AND k.position < {$completedColSub}
    ");
    $st->execute([$wsId, $wsId]);
    $pending = (int)$st->fetchColumn();

    // Completed (last column or title contains 'complet')
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND (k.position = {$completedColSub} OR k.title LIKE '%complet%')
    ");
    $st->execute([$wsId, $wsId]);
    $completed = (int)$st->fetchColumn();

    // Overdue — pending only, past due date
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=? AND c.due_date < CURDATE() AND c.due_date IS NOT NULL
          AND k.title NOT LIKE '%complet%'
          AND k.position < {$completedColSub}
    ");
    $st->execute([$wsId, $wsId]);
    $overdue = (int)$st->fetchColumn();

    // At risk this week — pending, due within next 7 days
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND c.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND k.title NOT LIKE '%complet%'
          AND k.position < {$completedColSub}
    ");
    $st->execute([$wsId, $wsId]);
    $dueWeek = (int)$st->fetchColumn();

    // ── PERIOD KPIs (filtered by from/to) ───────────────────────────────────

    // Completed in period
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND (k.position = {$completedColSub} OR k.title LIKE '%complet%')
          AND DATE(c.updated_at) BETWEEN ? AND ?
    ");
    $st->execute([$wsId, $wsId, $from, $to]);
    $completedInPeriod = (int)$st->fetchColumn();

    // Created in period
    $st = $db->prepare("
        SELECT COUNT(*) FROM ".tb('cards')." WHERE workspace_id=?
          AND DATE(created_at) BETWEEN ? AND ?
    ");
    $st->execute([$wsId, $from, $to]);
    $createdInPeriod = (int)$st->fetchColumn();

    // ── BY COLUMN (current state, all tasks) ─────────────────────────────────
    $st = $db->prepare("
        SELECT k.id, k.title, k.color, k.position, COUNT(c.id) as count
        FROM ".tb('columns_k')." k
        LEFT JOIN ".tb('cards')." c ON c.column_id = k.id AND c.workspace_id=?
        WHERE k.workspace_id=?
        GROUP BY k.id, k.title, k.color, k.position
        ORDER BY k.position
    ");
    $st->execute([$wsId, $wsId]);
    $byColumn = $st->fetchAll();

    // ── BY PRIORITY — pending tasks only ────────────────────────────────────
    $st = $db->prepare("
        SELECT COALESCE(c.priority,'none') as priority, COUNT(*) as count
        FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND k.title NOT LIKE '%complet%'
          AND k.position < {$completedColSub}
        GROUP BY c.priority
        ORDER BY count DESC
    ");
    $st->execute([$wsId, $wsId]);
    $byPriority = $st->fetchAll();

    // ── BY ASSIGNEE — pending tasks only ────────────────────────────────────
    $st = $db->prepare("
        SELECT ca.user_id, u.name, u.color, u.photo, COUNT(ca.card_id) as count
        FROM ".tb('card_assignees')." ca
        JOIN ".tb('users')." u ON u.id = ca.user_id
        JOIN ".tb('cards')." c ON c.id = ca.card_id AND c.workspace_id=?
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE k.title NOT LIKE '%complet%'
          AND k.position < {$completedColSub}
        GROUP BY ca.user_id, u.name, u.color, u.photo
        ORDER BY count DESC LIMIT 10
    ");
    $st->execute([$wsId, $wsId]);
    $byAssignee = $st->fetchAll();

    // ── WEEKLY SPARKLINE (completed per week in period) ──────────────────────
    $st = $db->prepare("
        SELECT DATE_FORMAT(c.updated_at, '%Y-%u') as week_key,
               MIN(DATE(c.updated_at)) as week_start,
               COUNT(*) as count
        FROM ".tb('cards')." c
        JOIN ".tb('columns_k')." k ON k.id = c.column_id
        WHERE c.workspace_id=?
          AND (k.title LIKE '%complet%' OR k.position = {$completedColSub})
          AND DATE(c.updated_at) BETWEEN ? AND ?
        GROUP BY week_key ORDER BY week_key
    ");
    $st->execute([$wsId, $wsId, $from, $to]);
    $weeklyDone = $st->fetchAll();

    // Velocity = avg completadas por semana en el período
    $daysDiff = max(1, (strtotime($to) - strtotime($from)) / 86400);
    $weeks    = $daysDiff / 7;
    $velocity = $weeks > 0 ? round($completedInPeriod / $weeks, 1) : 0;

    jsonOut([
        'total'               => $totalCards,
        'pending'             => $pending,
        'completed'           => $completed,
        'overdue'             => $overdue,
        'due_week'            => $dueWeek,
        'completed_in_period' => $completedInPeriod,
        'created_in_period'   => $createdInPeriod,
        'velocity'            => $velocity,
        'by_column'           => $byColumn,
        'by_priority'         => $byPriority,
        'by_assignee'         => $byAssignee,
        'weekly_done'         => $weeklyDone,
        'period_from'         => $from,
        'period_to'           => $to,
    ]);
}

jsonErr('Acción no encontrada', 404);
