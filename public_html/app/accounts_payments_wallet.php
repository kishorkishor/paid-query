<?php
// /app/accounts_payments_wallet.php — Accounts UI to verify wallet top-ups (wallet only)

require_once __DIR__ . '/auth.php';
require_perm('assign_team_member');

error_reporting(E_ALL);
ini_set('display_errors','0');                 // keep off in prod
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

require_once __DIR__ . '/config.php';

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- helpers ---------- */
function table_has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key,$cache)) return $cache[$key];
  try {
    $s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $s->execute([$col]);
    $cache[$key]=(bool)$s->fetch(PDO::FETCH_ASSOC);
  } catch(Throwable $e){ $cache[$key]=false; }
  return $cache[$key];
}

/** Find or create a wallet for a given Clerk user id */
function find_or_create_wallet(PDO $pdo, ?string $clerkUserId): ?array {
  if (!$clerkUserId) return null;

  try {
    $s = $pdo->prepare("SELECT * FROM customer_wallets WHERE clerk_user_id=? LIMIT 1");
    $s->execute([$clerkUserId]);
    $w = $s->fetch(PDO::FETCH_ASSOC);
    if ($w) return $w;

    // create
    $pdo->prepare("INSERT INTO customer_wallets (clerk_user_id, currency, created_at) VALUES (?, 'USD', NOW())")
        ->execute([$clerkUserId]);

    $s->execute([$clerkUserId]);
    return $s->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    error_log('[wallet create failed] '.$e->getMessage());
    return null;
  }
}

/** Credit wallet by inserting a row into wallet_ledger */
function credit_wallet_from_payment(PDO $pdo, array $wallet, float $amount, int $paymentId, int $adminUserId): bool {
  try {
    // Match your schema (screenshot): wallet_ledger columns:
    // id, wallet_id, entry_type, amount, currency, order_id, carton_id, payment_id, notes, created_at, created_by
    $sql = "INSERT INTO wallet_ledger
              (wallet_id, entry_type, amount, currency, order_id, carton_id, payment_id, notes, created_at, created_by)
            VALUES
              (?, 'topup_verified', ?, 'USD', NULL, NULL, ?, 'wallet top-up via bank proof', NOW(), ?)";
    $pdo->prepare($sql)->execute([(int)$wallet['id'], $amount, $paymentId, $adminUserId]);
    return true;
  } catch (Throwable $e) {
    error_log('[wallet ledger insert failed] '.$e->getMessage());
    return false;
  }
}

/* ---------- POST: verify / reject WALLET top-ups ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'], $_POST['id'])) {
  $pid = (int)$_POST['id'];
  $action = $_POST['action'] ?? '';

  // wallet-only rows (payment_type='wallet_topup')
  $st = $pdo->prepare("
    SELECT p.*,
           o.query_id,
           q.clerk_user_id AS q_clerk_user_id
      FROM order_payments p
 LEFT JOIN orders  o ON o.id = p.order_id
 LEFT JOIN queries q ON q.id = o.query_id
     WHERE p.id=? AND p.payment_type='wallet_topup'
     LIMIT 1
  ");
  $st->execute([$pid]);
  $p = $st->fetch(PDO::FETCH_ASSOC);

  if ($p) {
    if ($action === 'verify') {
      try {
        $pdo->beginTransaction();

        // mark payment verified
        $pdo->prepare("UPDATE order_payments SET status='verified', verified_at=NOW(), verified_by=? WHERE id=?")
            ->execute([$me, $pid]);

        // Determine owner: prefer order_payments.clerk_user_id if exists, else queries.clerk_user_id
        $clerkUserId = null;
        if (table_has_col($pdo,'order_payments','clerk_user_id')) {
          $clerkUserId = $p['clerk_user_id'] ?? null;
        }
        if (!$clerkUserId) $clerkUserId = $p['q_clerk_user_id'] ?? null;

        $wallet = find_or_create_wallet($pdo, $clerkUserId);
        if (!$wallet) {
          // cannot proceed, revert the verification to avoid dangling verified payment w/o credit
          $pdo->rollBack();
          error_log('[wallet verify] No wallet found/created for clerk_user_id='.($clerkUserId?:'NULL'));
          header('Location: '.$_SERVER['REQUEST_URI']); exit;
        }

        // credit wallet via ledger insert
        $ok = credit_wallet_from_payment($pdo, $wallet, (float)$p['amount'], $pid, $me);
        if (!$ok) {
          $pdo->rollBack();
          header('Location: '.$_SERVER['REQUEST_URI']); exit;
        }

        // internal note (optional)
        if (!empty($p['query_id'])) {
          $msg = 'Accounts verified wallet top-up (Txn '.$pid.') amount $'.number_format((float)$p['amount'],2).'.';
          $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                         VALUES (?, 'internal', 'note', ?, NOW())")
              ->execute([(int)$p['query_id'], $msg]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[wallet verify fatal] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }

    } elseif ($action === 'reject') {
      try {
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE order_payments SET status='rejected', verified_at=NOW(), verified_by=? WHERE id=?")
            ->execute([$me, $pid]);

        if (!empty($p['query_id'])) {
          $msg = 'Accounts rejected a wallet top-up (Txn '.$pid.').';
          $pdo->prepare("INSERT INTO messages (query_id, direction, medium, body, created_at)
                         VALUES (?, 'internal', 'note', ?, NOW())")
              ->execute([(int)$p['query_id'], $msg]);
        }

        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[wallet reject fatal] '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
      }
    }
  }

  header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

/* ---------- List WALLET top-ups only ---------- */
try {
  $rows = $pdo->query("
    SELECT p.*,
           b.bank_name, b.bank_branch, b.account_name,
           o.code AS order_code, o.id AS order_id
      FROM order_payments p
 LEFT JOIN orders o        ON o.id  = p.order_id
      JOIN bank_accounts b ON b.id  = p.bank_account_id
     WHERE p.status IN ('verifying','verified','rejected')
       AND p.payment_type='wallet_topup'
     ORDER BY (p.status='verifying') DESC, p.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('[wallet list fatal] '.$e->getMessage());
  $rows = [];
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accounts — Verify Wallet Top-ups</title>
<style>
  :root{--ink:#0f172a; --muted:#64748b; --line:#e5e7eb; --bg:#f6f7fb; --ok:#10b981; --no:#ef4444; --chip:#eef2ff; --chipb:#dde3ff;}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;background:var(--bg);color:#0f172a}
  header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#0f172a;color:#fff}
  header .brand{font-weight:700}
  .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
  h2{margin:0 0 10px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:top;text-align:left}
  tr:hover td{background:#fafafa}
  .badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid var(--line);font-size:.88rem}
  .b-ver{background:#fff7ed;border-color:#fed7aa;color:#92400e}
  .b-ok{background:#ecfdf5;border-color:#bbf7d0;color:#065f46}
  .b-no{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:10px;background:var(--chip);border:1px solid var(--chipb);font-weight:600}
  .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace}
  .muted{color:var(--muted)}
  .actions{display:flex;gap:6px}
  .btn{border:0;border-radius:8px;padding:.55rem .8rem;cursor:pointer;color:#fff;font-weight:600}
  .btn.ok{background:var(--ok)}
  .btn.no{background:var(--no)}
  a.nav{color:#fff;text-decoration:underline}
</style>
</head>
<body>
<header>
  <div class="brand">Accounts • Verify Wallet Top-ups</div>
  <div style="display:flex;gap:10px">
    <a class="nav" href="/app/accounts_payments_direct.php">Direct Deposits</a>
    <a class="nav" href="/app/">Admin Home</a>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 12px">Incoming Wallet Top-ups</h2>
    <div style="overflow:auto;border-radius:10px">
      <table>
        <thead>
          <tr>
            <th style="min-width:170px">Transaction</th>
            <th style="min-width:240px">Owner</th>
            <th style="min-width:240px">Bank</th>
            <th style="min-width:120px">Amount</th>
            <th style="min-width:130px">Status</th>
            <th style="min-width:110px">Proof</th>
            <th style="min-width:160px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div class="chip mono"><?= e($r['txn_code']) ?></div>
                <div class="muted"><?= e($r['created_at']) ?> · <span class="mono">WALLET TOP-UP</span></div>
              </td>
              <td>
                <?php if (!empty($r['order_id'])): ?>
                  <div class="muted">Linked to order: <strong><?= e($r['order_code'] ?: ('#'.$r['order_id'])) ?></strong></div>
                <?php else: ?>
                  <div class="muted">No order linkage</div>
                <?php endif; ?>
              </td>
              <td><?= e(($r['bank_name'] ?? '').' — '.($r['bank_branch'] ?? '')) ?><br><span class="muted"><?= e($r['account_name'] ?? '') ?></span></td>
              <td class="mono">$<?= e(number_format((float)($r['amount'] ?? 0),2)) ?></td>
              <td>
                <?php if (($r['status'] ?? '')==='verifying'): ?>
                  <span class="badge b-ver">verifying</span>
                <?php elseif (($r['status'] ?? '')==='verified'): ?>
                  <span class="badge b-ok">verified</span>
                  <div class="muted"><?= e($r['verified_at'] ?? '') ?></div>
                <?php else: ?>
                  <span class="badge b-no">rejected</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['proof_path'])): ?>
                  <a class="mono" href="<?= e($r['proof_path']) ?>" target="_blank">View</a>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td>
                <?php if (($r['status'] ?? '')==='verifying'): ?>
                  <div class="actions">
                    <form method="post" style="display:inline">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="verify">
                      <button class="btn ok" type="submit">Verify</button>
                    </form>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <button class="btn no" type="submit">Reject</button>
                    </form>
                  </div>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="muted">No wallet top-ups found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
