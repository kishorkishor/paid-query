<?php
// /app/chinese_inbound.php — Team dashboard for Chinese Inbound
require_once __DIR__ . '/auth.php';

require_login();
require_perm('chinese_inbound_access');

error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

$pdo = db();
$me  = (int)($_SESSION['admin']['id'] ?? 0);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Resolve a team id by name first, then by code (fallback) */
function team_id_by_name_or_code(PDO $pdo, string $name, string $code = null): ?int {
  $st = $pdo->prepare("SELECT id FROM teams WHERE name=? LIMIT 1");
  $st->execute([$name]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return (int)$row['id'];
  if ($code) {
    $st = $pdo->prepare("SELECT id FROM teams WHERE code=? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['id'];
  }
  return null;
}

$inboundTeamId = team_id_by_name_or_code($pdo, 'Chinese Inbound', 'ch_inbound');

/* ===========================
   POST: Mark as Delivered (for sourcing orders)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_delivered') {
  $orderId = (int)($_POST['order_id'] ?? 0);
  
  if ($orderId > 0) {
    try {
      $pdo->beginTransaction();
      
      // Update order status to "delivered"
      $pdo->prepare("UPDATE orders SET status = 'delivered', updated_at = NOW() WHERE id = ?")
          ->execute([$orderId]);
      
      // Audit log
      $pdo->prepare("INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
                     VALUES ('order', ?, ?, 'mark_delivered', JSON_OBJECT('status', 'delivered'), NOW())")
          ->execute([$orderId, $me]);
      
      // Get query_id for message
      $st = $pdo->prepare("SELECT query_id FROM orders WHERE id = ? LIMIT 1");
      $st->execute([$orderId]);
      $order = $st->fetch(PDO::FETCH_ASSOC);
      
      // Message
      if ($order) {
        $pdo->prepare("INSERT INTO messages (query_id, order_id, sender_admin_id, direction, medium, body, created_at)
                       VALUES (?, ?, ?, 'internal', 'note', ?, NOW())")
            ->execute([$order['query_id'], $orderId, $me, 'Inbound: Order marked as Delivered']);
      }
      
      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      error_log('[chinese_inbound:mark_delivered] ' . $e->getMessage());
    }
  }
  
  header('Location: /app/chinese_inbound.php');
  exit;
}

/*
  Keep existing behavior:
  - Show only orders currently assigned to Chinese Inbound team
  - Exclude fully shipped orders from this dashboard
  - Include country_id and ship_paid for shipping payment check
*/
$sql = "SELECT o.id, o.code, o.customer_name, o.quantity, o.status, o.updated_at, o.order_type, o.country_id, o.ship_paid,
               q.product_name, q.query_type, q.shipping_mode, q.label_type, q.carton_count
        FROM orders o
        LEFT JOIN queries q ON q.id = o.query_id
        WHERE o.current_team_id = :tid
          AND (o.status IS NULL OR o.status NOT IN ('Shipped', 'shipped', 'delivered'))
        ORDER BY o.updated_at DESC";

$st = $pdo->prepare($sql);
$st->execute([':tid' => $inboundTeamId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ========== NEW: Calculate BD due and auto-update ship_paid & status ==========
foreach ($rows as &$row) {
    $orderId = (int)$row['id'];
    
    try {
        // Fetch cartons for this order
        $ct = $pdo->prepare("
            SELECT c.*
            FROM inbound_cartons c
            JOIN inbound_packing_lists p ON p.id = c.packing_list_id
            WHERE p.order_id = ?
            ORDER BY c.id ASC
        ");
        $ct->execute([$orderId]);
        $cartons = $ct->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate BD due
        $bdDue = 0.0;
        
        if (!empty($cartons)) {
            // Check which columns exist in first row
            $first = $cartons[0];
            $has_bd = isset($first['bd_total_price']);
            $has_tot_due = isset($first['total_due']);
            $has_tot_paid = isset($first['total_paid']);
            $has_payment_status = isset($first['bd_payment_status']);
            
            if ($has_bd) {
                foreach ($cartons as $c) {
                    $due = 0.0;
                    
                    if ($has_tot_due) {
                        // Use total_due if available
                        $due = (float)($c['total_due'] ?? 0);
                    } elseif ($has_tot_paid) {
                        // Calculate: bd_total_price - total_paid
                        $due = (float)($c['bd_total_price'] ?? 0) - (float)($c['total_paid'] ?? 0);
                    } elseif ($has_payment_status) {
                        // Check payment status
                        if (strtolower(trim((string)($c['bd_payment_status'] ?? ''))) === 'pending') {
                            $due = (float)($c['bd_total_price'] ?? 0);
                        }
                    }
                    
                    if ($due > 0) {
                        $bdDue += $due;
                    }
                }
            }
        }
        
        // Auto-update ship_paid and status if conditions are met
        if ($bdDue <= 0 && (int)$row['country_id'] !== 1 && !empty($cartons)) {
            $currentStatus = strtolower(trim((string)($row['status'] ?? '')));
            $currentShipPaid = strtolower(trim((string)($row['ship_paid'] ?? '')));
            
            // Update ship_paid to "paid" if not already
            if ($currentShipPaid !== 'paid') {
                $updateSt = $pdo->prepare("UPDATE orders SET ship_paid = 'paid' WHERE id = ?");
                $updateSt->execute([$orderId]);
                $row['ship_paid'] = 'paid';
                $currentShipPaid = 'paid'; // Update current value for next check
            }
            
            // If ship_paid is "paid" and status is "partially_paid", update to "Ready to ship"
            if ($currentShipPaid === 'paid') {
                if ($currentStatus === 'partially_paid' || $currentStatus === 'partially paid') {
                    $updateStatusSt = $pdo->prepare("UPDATE orders SET status = 'Ready to ship' WHERE id = ?");
                    $updateStatusSt->execute([$orderId]);
                    $row['status'] = 'Ready to ship';
                }
            }
        }
        
    } catch (Exception $e) {
        // Log error but don't break the page
        error_log('[chinese_inbound:bd_due_calc] Order ' . $orderId . ': ' . $e->getMessage());
    }
}
unset($row); // Break reference
// ========== END: BD due calculation ==========

// helper to detect partial/fully shipped text variations
function is_partially_shipped($status) {
  $s = strtolower(trim((string)$status));
  return in_array($s, ['partially shipped','partial','partially_shipped'], true);
}
function is_fully_shipped($status) {
  $s = strtolower(trim((string)$status));
  return in_array($s, ['shipped','fully shipped','fully_shipped'], true);
}
function is_ready_to_ship($status) {
  $s = strtolower(trim((string)$status));
  return in_array($s, ['ready to ship','ready_to_ship'], true);
}

// helper to check if shipping payment is satisfied
function can_ship_based_on_payment($countryId, $shipPaid) {
  // If country_id is 1, no payment check needed
  if ((int)$countryId === 1) {
    return true;
  }
  // For other countries, ship_paid must be "paid" or "Paid"
  $paid = strtolower(trim((string)$shipPaid));
  return ($paid === 'paid');
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/><title>Chinese Inbound — Dashboard</title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f7f7fb}
    header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:#111827;color:#fff}
    .container{max-width:1200px;margin:24px auto;padding:18px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.06)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #111827;border-radius:10px;text-decoration:none;font-weight:600;color:#111827;background:#fff;cursor:pointer}
    .btn + .btn{margin-left:8px}
    .btn-disabled{opacity:0.5;cursor:not-allowed;pointer-events:none;background:#f5f5f5}
    .btn-success{background:#10b981;color:#fff;border-color:#10b981}
  </style>
</head>
<body>
<header>
  <div><strong>Chinese Inbound</strong> — Team Dashboard</div>
  <nav><a class="btn" href="/app/">Home</a></nav>
</header>
<div class="container">
  <h2 style="margin-top:0">Assigned Orders</h2>
  <?php if(!$rows): ?>
    <p>No orders at Chinese Inbound.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Order</th><th>Customer</th><th>Product</th><th>Qty</th><th>Type</th><th>Status</th><th>Updated</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): 
        $isSourcing = (strtolower(trim((string)($r['order_type'] ?? ''))) === 'sourcing');
        $canShip = can_ship_based_on_payment($r['country_id'], $r['ship_paid']);
      ?>
        <tr>
          <td><?= e($r['code']) ?></td>
          <td><?= e($r['customer_name']) ?></td>
          <td><?= e($r['product_name'] ?? '-') ?></td>
          <td><?= (int)($r['quantity'] ?? 0) ?></td>
          <td><?= e($r['order_type'] ?? '-') ?></td>
          <td><?= e($r['status']) ?></td>
          <td><?= e($r['updated_at']) ?></td>
          <td>
            <!-- keep existing actions -->
            <a class="btn" href="/app/chinese_inbound_order.php?id=<?= (int)$r['id'] ?>">Open</a>
            
            <?php if (is_ready_to_ship($r['status'])): ?>
              <?php if ($isSourcing): ?>
                <!-- For sourcing orders: show Delivered button -->
                <form method="post" style="display:inline">
                  <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="mark_delivered">
                  <button type="submit" class="btn btn-success">Delivered</button>
                </form>
              <?php else: ?>
                <!-- For other order types: show Ship button (disabled if payment not satisfied) -->
                <?php if ($canShip): ?>
                  <a class="btn" href="/app/inbound_ship.php?order_id=<?= (int)$r['id'] ?>">Ship</a>
                <?php else: ?>
                  <span class="btn btn-disabled" title="Shipping payment not yet marked as 'paid'">Ship</span>
                <?php endif; ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="btn btn-disabled" title="Only available when status is 'ready to ship'">
                <?= $isSourcing ? 'Delivered' : 'Ship' ?>
              </span>
            <?php endif; ?>

            <!-- NEW: show Manage Cartons only once shipping has begun (not for sourcing) -->
            <?php if (!$isSourcing && (is_partially_shipped($r['status']) || is_fully_shipped($r['status']))): ?>
              <a class="btn" href="/app/order_cartons.php?order_id=<?= (int)$r['id'] ?>">Manage Cartons</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</body>
</html>