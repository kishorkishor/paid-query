<?php
// /app/order_supervisor.php — Supervisor dashboard (sections: sourcing, shipping, both)
// Features: search + pagination, forward/approve/reject actions, details popup,
// shows previous/assigned agent, team, country, amounts, parses items_json,
// lists attachments by query_id, fills missing team/agent from the source query.
// UPDATED WORKFLOW: order_placing → supervisor approval → Chinese Accounts
// NEW: Shipping section functional — receives 'processing' & 'shipping' orders; on approval → Chinese Inbound (team_id 12)
//       Preserves previous_team_id and previous assigned agent.
// FIX: Shipping section now lists ONLY orders assigned to THIS supervisor's team.
// NEW: Added separate Sourcing section with same workflow as Both (forward/approve/reject → Chinese Accounts)

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // supervisors/team leads

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ----- generic column introspection ----- */
function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key, $cache)) return $cache[$key];
  try {
    $s = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$key] = (bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $cache[$key] = false;
  }
  return $cache[$key];
}

/* ----- team helpers ----- */
function team_id_by_name(PDO $pdo, string $name): ?int {
  $s=$pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$name]);
  $r=$s->fetch(PDO::FETCH_ASSOC); return $r? (int)$r['id'] : null;
}
$chinaAccountsTeamId = team_id_by_name($pdo,'Chinese Accounts');
$chinaInboundTeamId  = team_id_by_name($pdo,'Chinese Inbound');
if (!$chinaInboundTeamId) { $chinaInboundTeamId = 12; } // hard fallback per requirement

function team_members(PDO $pdo, int $teamId): array {
  // Supports either: admin_users.team_id OR join table admin_user_teams(admin_user_id, team_id)
  $hasDirect = table_has_col($pdo, 'admin_users', 'team_id');
  try {
    if ($hasDirect) {
      $s=$pdo->prepare("SELECT id,name,email FROM admin_users WHERE team_id=? ORDER BY name");
      $s->execute([$teamId]);
    } else {
      $s=$pdo->prepare("
        SELECT u.id, u.name, u.email
          FROM admin_user_teams ut
          JOIN admin_users u ON u.id = ut.admin_user_id
         WHERE ut.team_id=?
         ORDER BY u.name
      ");
      $s->execute([$teamId]);
    }
    return $s->fetchAll(PDO::FETCH_ASSOC);
  } catch(Throwable $e){
    error_log('[order_supervisor:team_members] '.$e->getMessage());
    return [];
  }
}

/* ---- items_json parsing (safe, supports multiple items) ---- */
function split_links_any($raw): array {
  if (is_array($raw)) { $parts = $raw; }
  else { $parts = preg_split('/[,\r\n]+/u', (string)$raw) ?: []; }
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
  }
  return $out;
}
function parse_items_json(?string $json): array {
  $json = (string)$json;
  if ($json === '') return [];
  $data = json_decode($json, true);
  if (json_last_error() !== JSON_ERROR_NONE) return [];
  if (isset($data['product_name']) || isset($data['details']) || isset($data['links'])) { $data = [$data]; }
  if (!is_array($data)) return [];
  $out = [];
  foreach ($data as $row) {
    if (!is_array($row)) continue;
    $prod = trim((string)($row['product_name'] ?? ''));
    $det  = trim((string)($row['details'] ?? ''));
    $lnks = split_links_any($row['links'] ?? '');
    if ($prod === '' && $det === '' && !$lnks) continue;
    $out[] = ['product_name'=>$prod, 'details'=>$det, 'links'=>$lnks];
  }
  return $out;
}

/* ---- modal_payload helper ---- */
function modal_payload(array $o, PDO $pdo): array {
  $payload = [
    'id' => (int)$o['id'],
    'code' => $o['code'] ?? ('#'.$o['id']),
    'status' => $o['status'] ?? '—',
    'payment_status' => $o['payment_status'] ?? '—',
    'order_type' => $o['order_type'] ?? '—',
    'qty' => $o['quantity'] ?? '—',
    'amount_total' => $o['amount_total'] ?? null,
    'paid_amount' => $o['paid_amount'] ?? null,
    'product_price' => $o['product_price'] ?? null,
    'shipping_price' => $o['shipping_price'] ?? null,
    'shipping_mode' => $o['shipping_mode'] ?? '—',
    'carton_count' => $o['carton_count'] ?? '—',
    'cbm' => $o['cbm'] ?? '—',
    'label_type' => $o['label_type'] ?? '—',
    'created_at' => $o['created_at'] ?? '—',
    'updated_at' => $o['updated_at'] ?? '—',
    'country' => $o['country_name'] ?? '—',
    'customer' => [
      'name' => $o['customer_name'] ?? '—',
      'email' => $o['customer_email'] ?? '',
      'phone' => $o['customer_phone'] ?? '',
      'address' => $o['customer_address'] ?? '',
    ],
    'assigned' => [
      'id' => $o['assigned_admin_user_id'] ?? null,
      'name' => $o['agent_name'] ?? '—',
    ],
    'previous_agent' => [
      'id' => $o['last_agent_id'] ?? null,
      'name' => $o['last_agent_name'] ?? '—',
    ],
    'team' => [
      'id' => $o['current_team_id'] ?? null,
      'name' => $o['team_name'] ?? '—',
    ],
    'items' => parse_items_json($o['items_json'] ?? ''),
    'attachments' => [],
  ];
  if (!empty($o['query_id'])) {
    try {
      $s = $pdo->prepare("SELECT id, path, original_name, mime_type, size FROM attachments WHERE query_id=? ORDER BY id");
      $s->execute([(int)$o['query_id']]);
      $atts = $s->fetchAll(PDO::FETCH_ASSOC);
      foreach ($atts as $a) {
        $payload['attachments'][] = [
          'id' => (int)$a['id'],
          'path' => $a['path'],
          'original_name' => $a['original_name'],
          'mime' => $a['mime_type'],
          'size' => $a['size'],
        ];
      }
    } catch (Throwable $e) {
      error_log('[order_supervisor:attachments] '.$e->getMessage());
    }
  }
  return $payload;
}

/* ------------------------------ filters ------------------------------ */
$q       = trim((string)($_GET['q'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));
$pageBoth     = max(1, (int)($_GET['p_both'] ?? 1));
$pageSourcing = max(1, (int)($_GET['p_sourcing'] ?? 1));
$pageShipping = max(1, (int)($_GET['p_shipping'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['pp'] ?? 20)));

/* Old common list (kept) */
$commonStatuses = [
  'paid for sourcing','order_placing',
  'payment processing stage 1','payment processing stage 2','payment processing stage 3'
];
/* New: shipping-specific statuses to RECEIVE here */
$shippingStatuses = ['processing','shipping'];

/* For the dropdown, include both sets so supervisors can filter by processing/shipping too */
$dropdownStatuses = array_values(array_unique(array_merge($commonStatuses, $shippingStatuses)));

/* ------------------------------- POST actions ------------------------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['order_id'], $_POST['act'])) {
  $oid = (int)$_POST['order_id'];
  $act = $_POST['act'];

  $st=$pdo->prepare("
    SELECT o.*,
           au.name AS agent_name, au.email AS agent_email
      FROM orders o
      LEFT JOIN admin_users au ON au.id = o.assigned_admin_user_id
     WHERE o.id=? LIMIT 1
  ");
  $st->execute([$oid]);
  $o=$st->fetch(PDO::FETCH_ASSOC);

  if ($o) {
    try {
      $pdo->beginTransaction();

      $queryRow = null;
      if (!empty($o['query_id'])) {
        $qs = $pdo->prepare("SELECT id, current_team_id, assigned_admin_user_id FROM queries WHERE id=? LIMIT 1");
        $qs->execute([(int)$o['query_id']]);
        $queryRow = $qs->fetch(PDO::FETCH_ASSOC) ?: null;
      }

      if ($act==='forward_to_agent') {
        $remark = trim((string)($_POST['remark'] ?? ''));
        $chosen = (int)($_POST['agent_id'] ?? 0);

        $prevAgent = (int)($o['assigned_admin_user_id'] ?? 0);
        $lastAgent = (int)($o['last_assigned_admin_user_id'] ?? 0);
        $qAgent    = $queryRow ? (int)($queryRow['assigned_admin_user_id'] ?? 0) : 0;

        $newAgent = $chosen ?: ($lastAgent ?: ($prevAgent ?: $qAgent));
        if ($newAgent <= 0) throw new RuntimeException('No agent available to forward to (please select one).');

        if ((int)($o['current_team_id'] ?? 0) <= 0 && $queryRow && (int)$queryRow['current_team_id'] > 0) {
          $pdo->prepare("UPDATE orders SET current_team_id=? WHERE id=?")->execute([(int)$queryRow['current_team_id'], $oid]);
          $o['current_team_id'] = (int)$queryRow['current_team_id'];
        }

        $pdo->prepare("UPDATE orders SET last_assigned_admin_user_id = assigned_admin_user_id WHERE id=?")
            ->execute([$oid]);

        $pdo->prepare("UPDATE orders SET assigned_admin_user_id=?, status='processing', updated_at=NOW() WHERE id=?")
            ->execute([$newAgent, $oid]);

        $msg = "Supervisor forwarded order to agent #$newAgent.".($remark!==''?(" Note: ".$remark):'');
        try {
          if (!empty($o['query_id']) && table_has_col($pdo,'messages','query_id')) {
            $pdo->prepare("INSERT INTO messages (order_id, query_id, direction, medium, body, created_at) VALUES (?, ?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, (int)$o['query_id'], $msg]);
          } else {
            $pdo->prepare("INSERT INTO messages (order_id, direction, medium, body, created_at) VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, $msg]);
          }
        } catch (Throwable $merr) {
          error_log('[order_supervisor:messages-forward] '.$merr->getMessage());
        }

        $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('order', ?, ?, 'supervisor_forward', ?, NOW())")
          ->execute([
            $oid,
            (int)($_SESSION['admin']['id']??0),
            json_encode(['to'=>$newAgent,'note'=>$remark],JSON_UNESCAPED_SLASHES)
          ]);

      } elseif ($act==='reject_order_placing') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason==='') throw new RuntimeException('Please enter a correction note.');
        $back = (int)($o['last_assigned_admin_user_id'] ?? 0);
        if (!$back) $back = (int)($o['assigned_admin_user_id'] ?? 0);
        if (!$back && $queryRow) $back = (int)($queryRow['assigned_admin_user_id'] ?? 0);

        $pdo->prepare("UPDATE orders
                          SET assigned_admin_user_id=?,
                              status='processing',
                              updated_at=NOW()
                        WHERE id=?")->execute([$back, $oid]);

        try {
          if (!empty($o['query_id']) && table_has_col($pdo,'messages','query_id')) {
            $pdo->prepare("INSERT INTO messages (order_id, query_id, direction, medium, body, created_at)
                           VALUES (?, ?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, (int)$o['query_id'], 'Supervisor rejected the order placing. Corrections: '.$reason]);
          } else {
            $pdo->prepare("INSERT INTO messages (order_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, 'Supervisor rejected the order placing. Corrections: '.$reason]);
          }
        } catch (Throwable $merr) {
          error_log('[order_supervisor:messages-reject] '.$merr->getMessage());
        }

        $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('order', ?, ?, 'order_place_rejected', ?, NOW())")
          ->execute([
            $oid,
            (int)($_SESSION['admin']['id']??0),
            json_encode(['back_to'=>$back,'reason'=>$reason],JSON_UNESCAPED_SLASHES)
          ]);

      } elseif ($act==='approve_order_placing') {
        $note = trim((string)($_POST['note'] ?? ''));

        $sql = "UPDATE orders
                   SET previous_team_id = current_team_id,
                       current_team_id  = ?,
                       last_assigned_admin_user_id = IFNULL(last_assigned_admin_user_id, assigned_admin_user_id),
                       assigned_admin_user_id = NULL,
                       status = 'payment processing stage 1',
                       updated_at = NOW()".
                 (table_has_col($pdo,'orders','supervisor_note') ? ", supervisor_note = ?" : "") .
                 " WHERE id=?";
        $params = [$chinaAccountsTeamId];
        if (table_has_col($pdo,'orders','supervisor_note')) $params[] = $note;
        $params[] = $oid;
        $pdo->prepare($sql)->execute($params);

        try {
          $body = 'Supervisor approved order placing. Sent to Chinese Accounts.'
                . ($note!==''?(' Note: '.$note):'');
          if (!empty($o['query_id']) && table_has_col($pdo,'messages','query_id')) {
            $pdo->prepare("INSERT INTO messages (order_id, query_id, direction, medium, body, created_at)
                           VALUES (?, ?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, (int)$o['query_id'], $body]);
          } else {
            $pdo->prepare("INSERT INTO messages (order_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, $body]);
          }
        } catch (Throwable $merr) {
          error_log('[order_supervisor:messages-approve] '.$merr->getMessage());
        }

        $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('order', ?, ?, 'sent_to_china_accounts', ?, NOW())")
          ->execute([
            $oid,
            (int)($_SESSION['admin']['id']??0),
            json_encode(['note'=>$note,'team'=>$chinaAccountsTeamId],JSON_UNESCAPED_SLASHES)
          ]);

      } elseif ($act==='approve_shipping_to_inbound') {
        $note = trim((string)($_POST['note'] ?? ''));

        $sql = "UPDATE orders
                   SET previous_team_id = current_team_id,
                       current_team_id  = ?,
                       last_assigned_admin_user_id = IFNULL(last_assigned_admin_user_id, assigned_admin_user_id),
                       assigned_admin_user_id = NULL,
                       status = 'processing',
                       updated_at = NOW()".
                 (table_has_col($pdo,'orders','supervisor_note') ? ", supervisor_note = ?" : "") .
                 " WHERE id=?";
        $params = [$chinaInboundTeamId];
        if (table_has_col($pdo,'orders','supervisor_note')) $params[] = $note;
        $params[] = $oid;
        $pdo->prepare($sql)->execute($params);

        try {
          $body = 'Supervisor approved Shipping. Sent to Chinese Inbound (Team '.$chinaInboundTeamId.').'
                . ($note!==''?(' Note: '.$note):'');
          if (!empty($o['query_id']) && table_has_col($pdo,'messages','query_id')) {
            $pdo->prepare("INSERT INTO messages (order_id, query_id, direction, medium, body, created_at)
                           VALUES (?, ?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, (int)$o['query_id'], $body]);
          } else {
            $pdo->prepare("INSERT INTO messages (order_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, $body]);
          }
        } catch (Throwable $merr) {
          error_log('[order_supervisor:messages-ship-approve] '.$merr->getMessage());
        }

        $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('order', ?, ?, 'sent_to_chinese_inbound', ?, NOW())")
          ->execute([
            $oid,
            (int)($_SESSION['admin']['id']??0),
            json_encode(['note'=>$note,'team'=>$chinaInboundTeamId],JSON_UNESCAPED_SLASHES)
          ]);

      } elseif ($act==='reject_shipping_back') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason==='') throw new RuntimeException('Please enter a correction note.');
        $back = (int)($o['last_assigned_admin_user_id'] ?? 0);
        if (!$back) $back = (int)($o['assigned_admin_user_id'] ?? 0);
        if (!$back && $queryRow) $back = (int)($queryRow['assigned_admin_user_id'] ?? 0);

        $pdo->prepare("UPDATE orders
                          SET assigned_admin_user_id=?,
                              status='processing',
                              updated_at=NOW()
                        WHERE id=?")->execute([$back, $oid]);

        try {
          $body = 'Supervisor rejected Shipping step. Corrections needed: '.$reason;
          if (!empty($o['query_id']) && table_has_col($pdo,'messages','query_id')) {
            $pdo->prepare("INSERT INTO messages (order_id, query_id, direction, medium, body, created_at)
                           VALUES (?, ?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, (int)$o['query_id'], $body]);
          } else {
            $pdo->prepare("INSERT INTO messages (order_id, direction, medium, body, created_at)
                           VALUES (?, 'internal', 'note', ?, NOW())")
                ->execute([$oid, $body]);
          }
        } catch (Throwable $merr) {
          error_log('[order_supervisor:messages-ship-reject] '.$merr->getMessage());
        }

        $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
          VALUES ('order', ?, ?, 'shipping_rejected', ?, NOW())")
          ->execute([
            $oid,
            (int)($_SESSION['admin']['id']??0),
            json_encode(['back_to'=>$back,'reason'=>$reason],JSON_UNESCAPED_SLASHES)
          ]);
      }

      $pdo->commit();
    } catch(Throwable $e){
      $pdo->rollBack();
      error_log('[order_supervisor_action] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
    }
  }

  $qs = $_SERVER['QUERY_STRING'] ? ('?'.$_SERVER['QUERY_STRING']) : '';
  header('Location: '.$_SERVER['PHP_SELF'].$qs); exit;
}

/* ------------------------ Section fetchers w/ pagination ------------------------ */
function fetch_section(
  PDO $pdo,
  string $type,
  array $statuses,
  string $q,
  string $fStatus,
  int $page,
  int $perPage,
  ?int $teamIdFilter = null  // NEW: optional team filter
): array {
  $where = ["o.order_type = ?"];
  $args  = [$type];

  if ($statuses) {
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $where[] = "o.status IN ($in)";
    $args = array_merge($args, $statuses);
  }
  if ($fStatus !== '') { $where[] = "o.status = ?"; $args[] = $fStatus; }
  if ($q !== '') {
    $where[] = "(o.code LIKE ? OR o.product_name LIKE ? OR o.customer_name LIKE ?)";
    $like = '%'.$q.'%'; $args[]=$like; $args[]=$like; $args[]=$like;
  }
  if ($teamIdFilter) { // ← only for Shipping section
    $where[] = "o.current_team_id = ?";
    $args[]  = $teamIdFilter;
  }

  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  // Count first
  $st = $pdo->prepare("SELECT COUNT(*) FROM orders o $whereSql");
  $st->execute($args);
  $total = (int)$st->fetchColumn();

  $pages = max(1, (int)ceil($total / $perPage));
  $page  = max(1, min($page, $pages));
  $off   = ($page-1)*$perPage;

  $sql = "
    SELECT o.*,
           c.name AS country_name,
           au.name AS agent_name, au.email AS agent_email,
           la.name AS last_agent_name, la.id AS last_agent_id,
           t.name AS team_name
      FROM orders o
      LEFT JOIN countries c  ON c.id = o.country_id
      LEFT JOIN admin_users au ON au.id = o.assigned_admin_user_id
      LEFT JOIN admin_users la ON la.id = o.last_assigned_admin_user_id
      LEFT JOIN teams t ON t.id = o.current_team_id
      $whereSql
     ORDER BY o.updated_at DESC, o.id DESC
     LIMIT $off, $perPage
  ";
  $st = $pdo->prepare($sql); $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  return ['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'perPage'=>$perPage];
}

/* ------------------------------- UI helpers ------------------------------- */
function first_team_id_for_user(PDO $pdo, int $userId): ?int {
  if ($userId <= 0) return null;
  if (table_has_col($pdo, 'admin_users', 'team_id')) {
    $s = $pdo->prepare("SELECT team_id FROM admin_users WHERE id=? LIMIT 1");
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r && $r['team_id'] ? (int)$r['team_id'] : null;
  }
  $s = $pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=? ORDER BY team_id LIMIT 1");
  $s->execute([$userId]);
  $r = $s->fetch(PDO::FETCH_ASSOC);
  return $r ? (int)$r['team_id'] : null;
}

function agent_select(PDO $pdo, array $o): string {
  $teamId = (int)($o['current_team_id'] ?? 0);
  if (!$teamId && !empty($o['assigned_admin_user_id'])) {
    $maybeTeam = first_team_id_for_user($pdo, (int)$o['assigned_admin_user_id']);
    if ($maybeTeam) $teamId = $maybeTeam;
  }
  $opts = '<option value="">— previous agent —</option>';
  if ($teamId) {
    foreach (team_members($pdo, $teamId) as $m) {
      $opts .= '<option value="'.(int)$m['id'].'">'.e($m['name']).' ('.e($m['email']).')</option>';
    }
  }
  return '<select name="agent_id">'.$opts.'</select>';
}

function pager_html(string $sectionKey, array $D): string {
  $params = $_GET;
  $pageParam = [
    'both'     => 'p_both',
    'sourcing' => 'p_sourcing',
    'shipping' => 'p_shipping',
  ][$sectionKey] ?? 'p_both';

  $html = '<div class="pager">';
  $html .= '<span class="muted">Page '.$D['page'].' of '.$D['pages'].' ('.$D['total'].' orders)</span>';

  if ($D['page'] > 1) { $params[$pageParam] = $D['page']-1;
    $html .= ' <a class="btn outline" href="'.e($_SERVER['PHP_SELF'].'?'.http_build_query($params)).'">Prev</a>';
  } else { $html .= ' <span class="btn outline disabled">Prev</span>'; }

  if ($D['page'] < $D['pages']) { $params[$pageParam] = $D['page']+1;
    $html .= ' <a class="btn outline" href="'.e($_SERVER['PHP_SELF'].'?'.http_build_query($params)).'">Next</a>';
  } else { $html .= ' <span class="btn outline disabled">Next</span>'; }

  $html .= '</div>';
  return $html;
}

/* ---------- Resolve "this team" for the current supervisor ---------- */
$myTeamId = first_team_id_for_user($pdo, $me);

/* ------------------------ Fetch sections ------------------------ */
$D_both     = fetch_section($pdo, 'both',     $commonStatuses,   $q, $fStatus, $pageBoth,     $perPage);
$D_sourcing = fetch_section($pdo, 'sourcing', $commonStatuses,   $q, $fStatus, $pageSourcing, $perPage);
/* Shipping: restrict to the supervisor's team */
$D_shipping = fetch_section($pdo, 'shipping', $shippingStatuses, $q, $fStatus, $pageShipping, $perPage, $myTeamId);

/* ------------------------------ HTML + JS ------------------------------ */
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Orders • Supervisor</title>
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--bg:#f7f8fb;--chip:#eef2ff;--chipb:#c7d2fe;--btn:#0ea5e9}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#0f172a;color:#fff}
  header a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:6px 10px;border-radius:10px}
  .wrap{max-width:1400px;margin:22px auto;padding:0 16px}
  h2{margin:12px 0}
  .filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
  .filters input,.filters select{padding:.55rem .7rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  .grid{display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:14px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
  .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:var(--chip);border:1px solid var(--chipb);font-weight:700;margin-right:6px}
  .muted{color:var(--muted)}
  .row{border:1px solid #f1f5f9;border-radius:12px;padding:10px;margin:10px 0}
  .btn{border:0;background:var(--btn);color:#fff;padding:.5rem .8rem;border-radius:8px;cursor:pointer;font-weight:600;text-decoration:none}
  .btn.outline{background:#fff;color:#0f172a;border:1px solid var(--line)}
  .btn.outline.disabled{opacity:.5;pointer-events:none}
  textarea,input,select{width:100%;padding:.55rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .danger{background:#ef4444}
  .ok{background:#10b981}
  .headrow{display:flex;justify-content:space-between;align-items:center}
  .pager{margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .modal{position:fixed;inset:0;background:rgba(2,6,23,.55);display:none;align-items:center;justify-content:center;z-index:50}
  .modal.show{display:flex}
  .modal .box{background:#fff;border-radius:14px;border:1px solid var(--line);max-width:900px;width:95%;max-height:85vh;overflow:auto}
  .box header{display:flex;justify-content:space-between;align-items:center;background:#fff;color:#0f172a;border-bottom:1px solid var(--line)}
  .box header h3{margin:0}
  .box .content{padding:14px}
  .kv{display:grid;grid-template-columns:200px 1fr;gap:8px;border-top:1px dashed #e5e7eb;margin-top:6px;padding-top:6px}
  .kv .k{color:#334155}
  .kv .v{color:#0f172a}
  .att a{word-break:break-all}
</style>
<header>
  <div><strong>Orders — Supervisor</strong></div>
  <div><a href="/app/">Back to Admin</a></div>
</header>
<div class="wrap">

  <form class="filters" method="get" action="">
    <input type="search" name="q" placeholder="Search code / product / customer…" value="<?= e($q) ?>">
    <select name="status">
      <option value="">— Any status —</option>
      <?php foreach ($dropdownStatuses as $st): ?>
        <option value="<?= e($st) ?>" <?= $fStatus===$st?'selected':'' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="pp">
      <?php foreach ([10,20,30,50,100] as $pp): ?>
        <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?>/page</option>
      <?php endforeach; ?>
    </select>
    <button class="btn outline" type="submit">Apply</button>
  </form>

  <div class="grid">
    <!-- BOTH -->
    <div class="card">
      <div class="headrow">
        <h2>BOTH — Active</h2>
        <?= pager_html('both', $D_both) ?>
      </div>

      <?php if (!$D_both['rows']): ?>
        <div class="muted">No active orders.</div>
      <?php else: foreach ($D_both['rows'] as $o):
        $payload = modal_payload($o,$pdo);
        $itemsForRow = parse_items_json((string)($o['items_json'] ?? ''));
        $firstName = $itemsForRow[0]['product_name'] ?? ($o['product_name'] ?? '');
      ?>
        <div class="row">
          <div>
            <span class="pill"><?= e($o['code'] ?? ('#'.$o['id'])) ?></span>
            <span class="pill">Status: <?= e($o['status']) ?></span>
            <span class="pill">Team: <?= e($o['team_name'] ?? (string)($o['current_team_id'] ?? '')) ?></span>
          </div>
          <div class="muted" style="margin-top:4px">
            <?= e($o['customer_name'] ?? '') ?> — <?= e($o['country_name'] ?? '') ?> · Qty <?= e($o['quantity'] ?? '-') ?> · Product <?= e($firstName) ?>
          </div>
          <div class="actions" style="margin-top:8px">
            <button class="btn outline view-btn"
              data-json='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>View details</button>
          </div>

          <?php if ($o['status']==='paid for sourcing'): ?>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="forward_to_agent">
              <?= agent_select($pdo,$o) ?>
              <textarea name="remark" rows="2" placeholder="Instructions for the agent (optional)"></textarea>
              <button class="btn" type="submit">Forward to Agent (set Processing)</button>
            </form>
          <?php endif; ?>

          <?php if ($o['status']==='order_placing'): ?>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="approve_order_placing">
              <input type="text" name="note" placeholder="Note to Chinese Accounts (optional)">
              <button class="btn ok" type="submit">Approve ⇒ Chinese Accounts (Stage 1)</button>
            </form>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="reject_order_placing">
              <textarea name="reason" rows="2" placeholder="What to correct? (required)"></textarea>
              <button class="btn danger" type="submit">Reject & send back</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <?= pager_html('both', $D_both) ?>
    </div>

    <!-- Sourcing -->
    <div class="card">
      <div class="headrow">
        <h2>Sourcing — Active</h2>
        <?= pager_html('sourcing', $D_sourcing) ?>
      </div>
      <?php if (!$D_sourcing['rows']): ?>
        <div class="muted">No active orders.</div>
      <?php else: foreach ($D_sourcing['rows'] as $o):
        $payload = modal_payload($o,$pdo);
        $itemsForRow = parse_items_json((string)($o['items_json'] ?? ''));
        $firstName = $itemsForRow[0]['product_name'] ?? ($o['product_name'] ?? '');
      ?>
        <div class="row">
          <div>
            <span class="pill"><?= e($o['code'] ?? ('#'.$o['id'])) ?></span>
            <span class="pill">Status: <?= e($o['status']) ?></span>
            <span class="pill">Team: <?= e($o['team_name'] ?? (string)($o['current_team_id'] ?? '')) ?></span>
          </div>
          <div class="muted" style="margin-top:4px">
            <?= e($o['customer_name'] ?? '') ?> — <?= e($o['country_name'] ?? '') ?> · Qty <?= e($o['quantity'] ?? '-') ?> · Product <?= e($firstName) ?>
          </div>
          <div class="actions" style="margin-top:8px">
            <button class="btn outline view-btn"
              data-json='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>View details</button>
          </div>

          <?php if ($o['status']==='paid for sourcing'): ?>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="forward_to_agent">
              <?= agent_select($pdo,$o) ?>
              <textarea name="remark" rows="2" placeholder="Instructions for the agent (optional)"></textarea>
              <button class="btn" type="submit">Forward to Agent (set Processing)</button>
            </form>
          <?php endif; ?>

          <?php if ($o['status']==='order_placing'): ?>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="approve_order_placing">
              <input type="text" name="note" placeholder="Note to Chinese Accounts (optional)">
              <button class="btn ok" type="submit">Approve ⇒ Chinese Accounts (Stage 1)</button>
            </form>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="reject_order_placing">
              <textarea name="reason" rows="2" placeholder="What to correct? (required)"></textarea>
              <button class="btn danger" type="submit">Reject & send back</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
      <?= pager_html('sourcing', $D_sourcing) ?>
    </div>

    <!-- Shipping -->
    <div class="card">
      <div class="headrow">
        <h2>Shipping — Supervisor</h2>
        <?= pager_html('shipping', $D_shipping) ?>
      </div>
      <?php if (!$D_shipping['rows']): ?>
        <div class="muted">No active orders.</div>
      <?php else: foreach ($D_shipping['rows'] as $o):
        $payload = modal_payload($o,$pdo);
        $itemsForRow = parse_items_json((string)($o['items_json'] ?? ''));
        $firstName = $itemsForRow[0]['product_name'] ?? ($o['product_name'] ?? '');
      ?>
        <div class="row">
          <div>
            <span class="pill"><?= e($o['code'] ?? ('#'.$o['id'])) ?></span>
            <span class="pill">Status: <?= e($o['status']) ?></span>
            <span class="pill">Team: <?= e($o['team_name'] ?? (string)($o['current_team_id'] ?? '')) ?></span>
          </div>
          <div class="muted" style="margin-top:4px">
            <?= e($o['customer_name'] ?? '') ?> — <?= e($o['country_name'] ?? '') ?> · Qty <?= e($o['quantity'] ?? '-') ?> · Product <?= e($firstName) ?>
          </div>
          <div class="actions" style="margin-top:8px">
            <button class="btn outline view-btn"
              data-json='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>View details</button>
          </div>

          <?php if (in_array($o['status'], ['processing','shipping'], true)): ?>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="approve_shipping_to_inbound">
              <input type="text" name="note" placeholder="Note to Chinese Inbound (optional)">
              <button class="btn ok" type="submit">Approve ⇒ Chinese Inbound (Team 12)</button>
            </form>
            <form method="post" class="actions">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="act" value="reject_shipping_back">
              <textarea name="reason" rows="2" placeholder="What to correct? (required)"></textarea>
              <button class="btn danger" type="submit">Reject & send back</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
      <?= pager_html('shipping', $D_shipping) ?>
    </div>
  </div>
</div>

<!-- Details Modal -->
<div class="modal" id="orderModal" aria-hidden="true">
  <div class="box">
    <header style="padding:12px 14px">
      <h3 id="mTitle">Order —</h3>
      <button class="btn outline" type="button" id="mClose">Close</button>
    </header>
    <div class="content">
      <div id="mBadges" style="margin-bottom:10px"></div>
      <div class="kv" id="mKV"></div>
      <div id="mActions" style="margin-top:14px"></div>
    </div>
  </div>
</div>

<script>
const $ = s => document.querySelector(s);

function kvRow(k, v){
  const d = document.createElement('div');
  d.className='k'; d.textContent=k;
  const vDiv = document.createElement('div');
  vDiv.className='v'; vDiv.innerHTML=v;
  return [d,vDiv];
}
function fmtMoney(n){ try { return '$'+Number(n).toFixed(2); } catch(e){ return n; } }
function linkify(u){ if (!u) return ''; return /^https?:/i.test(u) ? u : 'http://' + u; }

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.view-btn');
  if(!btn) return;
  e.preventDefault();
  const data = JSON.parse(btn.dataset.json || '{}');

  document.getElementById('mTitle').textContent = `Order ${data.code}`;
  const badges = [];
  badges.push(`<span class="pill">Status: ${data.status||'-'}</span>`);
  badges.push(`<span class="pill">Payment: ${data.payment_status||'-'}</span>`);
  badges.push(`<span class="pill">Type: ${data.order_type||'-'}</span>`);
  badges.push(`<span class="pill">Team: ${data.team?.name || data.team?.id || '-'}</span>`);
  document.getElementById('mBadges').innerHTML = badges.join(' ');

  const kv = document.getElementById('mKV'); kv.innerHTML = '';
  const rows = [
    ['Order Code', data.code],
    ['Previous Agent', `${data.previous_agent?.name||'—'} (ID: ${data.previous_agent?.id||'—'})`],
    ['Assigned Agent', `${data.assigned?.name||'—'} ${data.assigned?.id?('(ID: '+data.assigned.id+')'):''}`],
    ['Country', data.country||'—'],
    ['Customer', `${data.customer?.name||'—'} · ${data.customer?.email||''} · ${data.customer?.phone||''}`],
    ['Address', (data.customer?.address||'').replace(/\n/g,'<br>')],
    ['Quantity', data.qty],
    ['Amount Total', fmtMoney(data.amount_total)],
    ['Paid Amount', data.paid_amount!=null ? fmtMoney(data.paid_amount) : '—'],
    ['Product Price', data.product_price!=null ? fmtMoney(data.product_price) : '—'],
    ['Shipping Price', data.shipping_price!=null ? fmtMoney(data.shipping_price) : '—'],
    ['Shipping Mode', data.shipping_mode||'—'],
    ['Cartons / CBM / Label', `${data.carton_count||'—'} / ${data.cbm||'—'} / ${data.label_type||'—'}`],
    ['Created / Updated', `${data.created_at||'—'} · ${data.updated_at||'—'}`],
  ];
  rows.forEach(([k,v])=>{ const [dk, dv] = kvRow(k, v==null?'—':v); kv.appendChild(dk); kv.appendChild(dv); });

  if (Array.isArray(data.items) && data.items.length) {
    const list = data.items.map((it,i)=>{
      const lnks = (it.links||[]).map(u=>`<a href="${linkify(u)}" target="_blank">${u}</a>`).join('<br>');
      const det  = (it.details||'').replace(/\n/g,'<br>');
      return `
        <div style="border:1px solid #eef2f7;border-radius:8px;padding:8px;margin:6px 0">
          <div><strong>${i+1}. ${it.product_name || '—'}</strong></div>
          ${det?`<div class="muted" style="margin-top:2px">${det}</div>`:''}
          ${lnks?`<div style="margin-top:4px">${lnks}</div>`:''}
        </div>
      `;
    }).join('');
    const [dk,dv]= kvRow('Items', list);
    kv.appendChild(dk); kv.appendChild(dv);
  }

  if (Array.isArray(data.attachments) && data.attachments.length) {
    const list = data.attachments.map(a=>{
      const name = a.original_name || a.path || ('#'+a.id);
      return `<div class="att"><a href="${a.path}" target="_blank">${name}</a> <span class="muted">(${a.mime||'file'}, ${a.size||''} bytes)</span></div>`;
    }).join('');
    const [dk, dv] = kvRow('Attachments', list);
    kv.appendChild(dk); kv.appendChild(dv);
  }

  document.getElementById('orderModal').classList.add('show');
});

document.getElementById('mClose').addEventListener('click', ()=> document.getElementById('orderModal').classList.remove('show'));
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') document.getElementById('orderModal').classList.remove('show'); });
document.getElementById('orderModal').addEventListener('click', (e)=>{ if(e.target.id==='orderModal') document.getElementById('orderModal').classList.remove('show'); });
</script>
