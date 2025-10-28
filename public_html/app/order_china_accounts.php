<?php
// /app/order_china_accounts.php — Chinese Accounts workflow (stage 2 + complete), return to previous team/agent.
// Uses orders.previous_team_id to restore the team; robust message inserts; no reliance on admin_users.team_id.
// NOW SHOWS: both 'both' and 'sourcing' order types
// BUTTON LOGIC: Set Stage 2 only enabled at stage 1; Complete only enabled at stage 2 or 3

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member'); // accounts users

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- schema helpers (cached) ---------- */
function col_info(PDO $pdo, string $table, string $col): ?array {
  static $cache = [];
  $k = $table.'|'.$col;
  if (array_key_exists($k,$cache)) return $cache[$k];
  try {
    $s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    $cache[$k] = $row ?: null;
  } catch(Throwable $e){
    error_log("[china_accounts:col_info] $table.$col :: ".$e->getMessage());
    $cache[$k] = null;
  }
  return $cache[$k];
}
function table_has_col(PDO $pdo, string $table, string $col): bool {
  return (bool)col_info($pdo,$table,$col);
}
function col_is_nullable(PDO $pdo, string $table, string $col): bool {
  $i = col_info($pdo,$table,$col);
  if (!$i) return true;
  return strtoupper((string)($i['Null'] ?? 'YES')) === 'YES';
}
function team_id_by_name(PDO $pdo, string $name): ?int {
  $s=$pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1"); $s->execute([$name]);
  $r=$s->fetch(PDO::FETCH_ASSOC); return $r? (int)$r['id'] : null;
}
$chinaAccountsTeamId = team_id_by_name($pdo,'Chinese Accounts');

/* Team helper for bridge/direct schemas */
function first_team_id_for_user(PDO $pdo, int $userId): ?int {
  if ($userId <= 0) return null;
  if (table_has_col($pdo,'admin_users','team_id')) {
    $s=$pdo->prepare("SELECT team_id FROM admin_users WHERE id=? LIMIT 1");
    $s->execute([$userId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return ($r && !empty($r['team_id'])) ? (int)$r['team_id'] : null;
  }
  try {
    $s=$pdo->prepare("SELECT team_id FROM admin_user_teams WHERE admin_user_id=? ORDER BY team_id LIMIT 1");
    $s->execute([$userId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r ? (int)$r['team_id'] : null;
  } catch(Throwable $e){
    error_log('[china_accounts:first_team_id_for_user] '.$e->getMessage());
    return null;
  }
}

/* ---------- safe message writer (schema-aware) ---------- */
function add_internal_message(PDO $pdo, array $orderRow, string $body): void {
  $orderId = (int)$orderRow['id'];
  $qid     = isset($orderRow['query_id']) ? (int)$orderRow['query_id'] : null;

  $hasQid       = table_has_col($pdo,'messages','query_id');
  $qidNullable  = $hasQid ? col_is_nullable($pdo,'messages','query_id') : true;
  $hasAdminCol  = table_has_col($pdo,'messages','admin_user_id');
  $adminNullable= $hasAdminCol ? col_is_nullable($pdo,'messages','admin_user_id') : true;
  $adminId      = (int)($_SESSION['admin']['id'] ?? 0);

  try {
    if ($hasQid && !$qidNullable && !$qid) {
      error_log("[china_accounts:add_internal_message] SKIP insert: messages.query_id NOT NULL but order {$orderId} has no query_id.");
      return;
    }

    $cols = ['order_id','direction','medium','body','created_at'];
    $vals = [$orderId,'internal','note',$body,date('Y-m-d H:i:s')];

    if ($hasQid) {
      $cols[] = 'query_id';
      $vals[] = $qidNullable ? $qid : ($qid ?: 0);
    }
    if ($hasAdminCol) {
      if (!$adminNullable && $adminId<=0) {
        error_log("[china_accounts:add_internal_message] SKIP: messages.admin_user_id NOT NULL but no session id (order {$orderId}).");
      } else {
        $cols[]='admin_user_id';
        $vals[]=$adminId>0?$adminId:($adminNullable?null:null);
      }
    }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO messages (".implode(',',$cols).") VALUES ($placeholders)";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($vals);

  } catch(Throwable $e){
    error_log('[china_accounts:add_internal_message:EXCEPTION] '.$e->getMessage().' SQL='.($sql??'n/a').' OID='.$orderId);
  }
}

/* ---------- POST: stage transitions (stage2, complete) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['order_id'], $_POST['act'])) {
  $oid  = (int)$_POST['order_id'];
  $act  = (string)$_POST['act'];
  $info = trim((string)($_POST['info']??'')); // note for all actions

  // Pull order (orders.* only)
  $st=$pdo->prepare("SELECT o.* FROM orders o WHERE o.id=? LIMIT 1");
  $st->execute([$oid]);
  $o=$st->fetch(PDO::FETCH_ASSOC);

  if ($o && (int)($o['current_team_id'] ?? 0) === (int)$chinaAccountsTeamId) {
    $status = (string)($o['status'] ?? '');

    // stage2 allowed only from stage1
    // complete allowed from stage2 or stage3 (auto-advance to stage3 if currently stage2)
    $allow = false;
    if ($act==='stage2') {
      $allow = ($status === 'payment processing stage 1');
    } elseif ($act==='complete_stage3_and_return') {
      $allow = in_array($status, ['payment processing stage 2','payment processing stage 3'], true);
    }

    if ($allow) {
      try{
        $pdo->beginTransaction();

        // Optional note columns
        $hasLast = table_has_col($pdo,'orders','accounts_last_note');
        $hasS2   = table_has_col($pdo,'orders','accounts_stage2_note');
        $hasS3   = table_has_col($pdo,'orders','accounts_stage3_note');

        if ($act==='stage2') {
          $pdo->prepare("UPDATE orders SET status='payment processing stage 2', updated_at=NOW() WHERE id=?")->execute([$oid]);

          if ($info!=='') {
            if ($hasS2) { $pdo->prepare("UPDATE orders SET accounts_stage2_note=? WHERE id=?")->execute([$info,$oid]); }
            add_internal_message($pdo, $o, 'Accounts note (Stage 2): '.$info);
            if ($hasLast) { $pdo->prepare("UPDATE orders SET accounts_last_note=? WHERE id=?")->execute([$info,$oid]); }
          }

        } elseif ($act==='complete_stage3_and_return') {
          // Auto-advance to stage 3 if still at stage 2
          if ($status === 'payment processing stage 2') {
            $pdo->prepare("UPDATE orders SET status='payment processing stage 3', updated_at=NOW() WHERE id=?")->execute([$oid]);
            if ($info!=='') {
              if ($hasS3) { $pdo->prepare("UPDATE orders SET accounts_stage3_note=? WHERE id=?")->execute([$info,$oid]); }
              add_internal_message($pdo, $o, 'Accounts note (Stage 3): '.$info);
              if ($hasLast) { $pdo->prepare("UPDATE orders SET accounts_last_note=? WHERE id=?")->execute([$info,$oid]); }
            }
          }

          // Decide return team: prefer previous_team_id; else compute from previous agent
          $prevTeamId = isset($o['previous_team_id']) ? (int)$o['previous_team_id'] : null;
          $prevAgentId= (int)($o['last_assigned_admin_user_id'] ?? 0);
          $fallbackTeam = $prevAgentId ? first_team_id_for_user($pdo, $prevAgentId) : null;

          $targetTeam = $prevTeamId ?: ($fallbackTeam ?: ($o['current_team_id'] ?? null));

          $pdo->prepare("UPDATE orders
                            SET current_team_id=?,
                                assigned_admin_user_id=?,
                                previous_team_id=NULL,           -- clear after return
                                status='payment processing stage 3',
                                updated_at=NOW()
                          WHERE id=?")
              ->execute([
                $targetTeam,
                $prevAgentId ?: null,
                $oid
              ]);

          $txt = 'Chinese Accounts finished payment processing and returned to agent.'
               . ($info!=='' ? (' Info: '.$info) : '');
          add_internal_message($pdo, $o, $txt);

          if ($info!=='' && $hasLast) {
            $pdo->prepare("UPDATE orders SET accounts_last_note=? WHERE id=?")->execute([$info,$oid]);
          }

          $pdo->commit();
          header('Location: '.$_SERVER['REQUEST_URI']);
          exit;
        }

        $pdo->commit();
      } catch(Throwable $e){
        $pdo->rollBack();
        error_log('[china_accounts:POST] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine().' OID='.$oid.' ACT='.$act);
      }
    } else {
      error_log("[china_accounts:flow_guard] Blocked transition: OID={$oid} ACT={$act} STATUS={$status}");
    }
  } else {
    error_log("[china_accounts:team_guard] OID={$oid} not in Chinese Accounts team.");
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

/* ---------- List orders in Accounts queue, both 'both' and 'sourcing' types ---------- */
$rows = $pdo->prepare("
  SELECT o.*
    FROM orders o
   WHERE o.current_team_id=? AND o.order_type IN ('both', 'sourcing')
     AND o.status IN ('payment processing stage 1','payment processing stage 2','payment processing stage 3')
   ORDER BY o.updated_at DESC, o.id DESC
");
$rows->execute([$chinaAccountsTeamId]);
$orders = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chinese Accounts — Payments</title>
<style>
  :root{--ink:#0f172a;--muted:#64748b;--line:#e5e7eb;--bg:#f7f8fb;--btn:#0ea5e9;--ok:#10b981}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink)}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#0f172a;color:#fff}
  header a{color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.2);padding:6px 10px;border-radius:10px}
  .wrap{max-width:1100px;margin:22px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:12px}
  .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;font-weight:700;margin-right:6px}
  .btn{border:0;background:var(--btn);color:#fff;padding:.5rem .8rem;border-radius:8px;cursor:pointer;font-weight:600}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .ok{background:var(--ok)}
  .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  textarea{width:100%;padding:.55rem;border:1px solid var(--line);border-radius:10px;background:#fff}
  .notice{background:#fefce8;border:1px solid #fde68a;padding:8px;border-radius:8px;margin-top:10px}
</style>
<header>
  <div><strong>Chinese Accounts — Payments</strong></div>
  <div><a href="/app/">Back</a></div>
</header>
<div class="wrap">
  <?php if (!$orders): ?>
    <div class="card">No orders in processing stages.</div>
  <?php else: foreach ($orders as $o): 
    $currentStatus = (string)($o['status'] ?? '');
    $isStage1 = ($currentStatus === 'payment processing stage 1');
    $isStage2or3 = in_array($currentStatus, ['payment processing stage 2','payment processing stage 3'], true);
  ?>
    <div class="card">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="pill"><?= e($o['code'] ?? ('#'.$o['id'])) ?></span>
        <span class="pill">Status: <?= e($o['status']) ?></span>
        <span class="pill">Type: <?= e($o['order_type'] ?? 'both') ?></span>
      </div>

      <?php if (!empty($o['supervisor_note'])): ?>
        <div class="notice">
          <strong>Supervisor Instruction:</strong><br>
          <?= nl2br(e($o['supervisor_note'])) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="actions">
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <textarea name="info" rows="2" placeholder="Internal note (optional)"></textarea>

        <!-- Set Stage 2: only enabled at stage 1 -->
        <button class="btn" type="submit" name="act" value="stage2"
          <?= $isStage1 ? '' : 'disabled title="Only available at payment processing stage 1"' ?>>
          Set Stage 2
        </button>

        <!-- Complete: only enabled at stage 2 or 3 -->
        <button class="btn ok" type="submit" name="act" value="complete_stage3_and_return"
          <?= $isStage2or3 ? '' : 'disabled title="Only available at payment processing stage 2 or 3"' ?>>
          Complete & Return to Agent
        </button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</div>