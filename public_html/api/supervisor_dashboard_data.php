<?php
// supervisor_dashboard_data.php
// Returns JSON data for supervisor dashboard notifications and metrics.

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

require_once __DIR__ . '/lib.php';

// Simple JSON output helper
function json_out_super($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

session_start();
// Ensure logged in and has supervisor permission
if (empty($_SESSION['admin']) || empty($_SESSION['perms']) || !in_array('assign_team_member', $_SESSION['perms'], true)) {
    json_out_super(['ok' => false, 'error' => 'Unauthorized'], 403);
}

$adminId = $_SESSION['admin']['id'];
$pdo = db();

// Determine teams this admin oversees (leader or member)
$teamIds = [];
$st = $pdo->prepare("SELECT id FROM teams WHERE leader_admin_user_id=?");
$st->execute([$adminId]);
foreach ($st->fetchAll() as $row) $teamIds[] = (int)$row['id'];
$st = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=?");
$st->execute([$adminId]);
foreach ($st->fetchAll() as $row) $teamIds[] = (int)$row['team_id'];
$teamIds = array_unique($teamIds);
if (!$teamIds) $teamIds = [1];

$inTeams = implode(',', array_map('intval', $teamIds));

// Query counts
$counts = [
    'total' => 0,
    'new' => 0,
    'inproc' => 0,
    'red' => 0,
    'pending' => 0,
];
// Total and statuses
$rows = $pdo->query(
    "SELECT status, forward_request_team_id FROM queries WHERE current_team_id IN ($inTeams)"
)->fetchAll();
foreach ($rows as $r) {
    $counts['total']++;
    if (in_array($r['status'], ['new','elaborated','assigned'], true)) $counts['new']++;
    elseif ($r['status'] === 'in_process') $counts['inproc']++;
    elseif ($r['status'] === 'red_flag') $counts['red']++;
    if ($r['forward_request_team_id']) $counts['pending']++;
}

// Team members
$teamMembers = [];
$members = $pdo->query(
    "SELECT au.id, au.name, ut.team_id FROM admin_users au
     JOIN admin_user_teams ut ON ut.admin_user_id = au.id
     WHERE ut.team_id IN ($inTeams) AND au.is_active=1"
)->fetchAll();
foreach ($members as $m) $teamMembers[$m['id']] = ['name' => $m['name'], 'team_id' => $m['team_id']];

// Metrics initialisation
$metrics = [];
foreach ($teamMembers as $uid => $info) {
    $metrics[$uid] = [
        'name' => $info['name'],
        'team_id' => $info['team_id'],
        'assigned' => 0,
        'closed' => 0,
        'avg_res_seconds' => null,
    ];
}
if ($metrics) {
    $uids = implode(',', array_keys($metrics));
    // assigned
    $resA = $pdo->query(
        "SELECT assigned_admin_user_id AS uid, COUNT(*) AS cnt
         FROM queries
         WHERE assigned_admin_user_id IN ($uids)
         GROUP BY assigned_admin_user_id"
    )->fetchAll();
    foreach ($resA as $r) {
        $uid = (int)$r['uid'];
        if (isset($metrics[$uid])) $metrics[$uid]['assigned'] = (int)$r['cnt'];
    }
    // closed/converted
    $resC = $pdo->query(
        "SELECT assigned_admin_user_id AS uid, COUNT(*) AS cnt,
                AVG(TIMESTAMPDIFF(SECOND, created_at, IFNULL(updated_at, created_at))) AS avg_sec
         FROM queries
         WHERE assigned_admin_user_id IN ($uids) AND status IN ('closed','converted')
         GROUP BY assigned_admin_user_id"
    )->fetchAll();
    foreach ($resC as $r) {
        $uid = (int)$r['uid'];
        if (isset($metrics[$uid])) {
            $metrics[$uid]['closed'] = (int)$r['cnt'];
            $metrics[$uid]['avg_res_seconds'] = (float)$r['avg_sec'];
        }
    }
}

// Fetch recent audit logs for queries in these teams (latest 20)
$logs = [];
$stmt = $pdo->query(
    "SELECT l.id, l.entity_id AS query_id, q.query_code, l.action, l.meta, l.created_at
     FROM audit_logs l
     JOIN queries q ON q.id = l.entity_id
     WHERE l.entity_type='query' AND q.current_team_id IN ($inTeams)
     ORDER BY l.id DESC
     LIMIT 20"
);
foreach ($stmt->fetchAll() as $row) {
    $metaStr = '';
    if (!empty($row['meta'])) {
        $metaArr = json_decode($row['meta'], true);
        if (is_array($metaArr)) {
            foreach ($metaArr as $k => $v) {
                $metaStr .= $k . ':' . $v . ' ';
            }
            $metaStr = trim($metaStr);
        }
    }
    $logs[] = [
        'id' => (int)$row['id'],
        'query_id' => (int)$row['query_id'],
        'query_code' => $row['query_code'],
        'action' => $row['action'],
        'meta' => $metaStr,
        'created_at' => $row['created_at'],
    ];
}

json_out_super(['ok' => true, 'counts' => $counts, 'metrics' => $metrics, 'logs' => $logs]);