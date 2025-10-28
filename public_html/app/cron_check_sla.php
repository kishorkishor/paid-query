<?php
/**
 * app/cron_sla.php
 * Run via Hostinger Cron (e.g., every 15–60 minutes).
 *
 * Merged behaviors:
 * 1) Assignment SLA: if a query is still NEW or FORWARDED after 24h since last update → red_flag.
 * 2) Generic SLA window: if sla_due_at passed for NEW/ELABORATED/ASSIGNED/IN_PROCESS → red_flag,
 *    audit (reason: SLA_24h_missed), and escalate to Regular team (current_team_id=1).
 * 3) Reply SLA: if sla_reply_due_at passed for ASSIGNED/PRICE_SUBMITTED/PRICE_REJECTED/NEGOTIATION_PENDING:
 *      - 36–72 hours overdue → yellow_flag
 *      - >72 hours overdue → red_flag
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_php_errors.log');

require_once __DIR__ . '/api/lib.php';
$pdo = db();

$now = date('Y-m-d H:i:s');

/** small helpers */
function upd(PDO $pdo, string $sql, array $params = []): int {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->rowCount();
}
function fetchCol(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** counters for reporting */
$counts = [
  'assignment_24h_red' => 0,
  'sla_due_red'        => 0,
  'reply_yellow'       => 0,
  'reply_red'          => 0,
];

try {
  // -------------------------------------------------------------
  // (A) Assignment SLA (24h since last update on NEW/FORWARDED)
  // -------------------------------------------------------------
  $counts['assignment_24h_red'] = upd($pdo, "
    UPDATE queries
       SET status='red_flag',
           flagged_at=NOW(),
           updated_at=NOW()
     WHERE status IN ('new','forwarded')
       AND TIMESTAMPDIFF(HOUR, updated_at, NOW()) > 24
       AND status <> 'red_flag'
  ");

  // -------------------------------------------------------------
  // (B) Generic SLA window using sla_due_at (escalate & audit)
  // Only act on records not already flagged
  // -------------------------------------------------------------
  $overdueIds = fetchCol($pdo, "
    SELECT q.id
      FROM queries q
     WHERE q.sla_due_at IS NOT NULL
       AND q.sla_due_at < NOW()
       AND q.status IN ('new','elaborated','assigned','in_process')
       AND q.flagged_at IS NULL
  ");

  foreach ($overdueIds as $qid) {
    // Mark red_flag & flagged_at
    upd($pdo, "UPDATE queries SET status='red_flag', flagged_at=?, updated_at=NOW() WHERE id=?", [$now, $qid]);

    // Audit breadcrumb (SLA_24h_missed)
    upd($pdo, "
      INSERT INTO audit_logs (entity_type, entity_id, admin_user_id, action, meta, created_at)
      VALUES ('query', ?, NULL, 'escalated', JSON_OBJECT('reason','SLA_24h_missed'), NOW())
    ", [$qid]);

    // Escalate to Regular (team_id=1) — can be removed if not desired
    upd($pdo, "UPDATE queries SET current_team_id=1 WHERE id=?", [$qid]);
  }
  $counts['sla_due_red'] = count($overdueIds);

  // -------------------------------------------------------------
  // (C) Reply SLA using sla_reply_due_at
  //   36–72h overdue  -> yellow_flag
  //   >72h overdue    -> red_flag
  // -------------------------------------------------------------
  $counts['reply_yellow'] = upd($pdo, "
    UPDATE queries
       SET status='yellow_flag',
           updated_at=NOW()
     WHERE status IN ('assigned','price_submitted','price_rejected','negotiation_pending')
       AND sla_reply_due_at IS NOT NULL
       AND TIMESTAMPDIFF(HOUR, sla_reply_due_at, NOW()) BETWEEN 36 AND 72
  ");

  $counts['reply_red'] = upd($pdo, "
    UPDATE queries
       SET status='red_flag',
           updated_at=NOW()
     WHERE status IN ('assigned','price_submitted','price_rejected','negotiation_pending')
       AND sla_reply_due_at IS NOT NULL
       AND TIMESTAMPDIFF(HOUR, sla_reply_due_at, NOW()) > 72
  ");

} catch (Throwable $ex) {
  error_log('[cron_sla] '.$ex->getMessage().' @'.$ex->getFile().':'.$ex->getLine());
  // Non-zero exit to signal failure in cron logs if desired
  // exit(1);
}

// plain-text summary for cron output
header('Content-Type: text/plain; charset=UTF-8');
echo "OK " . date('Y-m-d H:i:s') . PHP_EOL;
echo "Assignment 24h → red_flag: {$counts['assignment_24h_red']}" . PHP_EOL;
echo "SLA due_at     → red_flag (escalated): {$counts['sla_due_red']}" . PHP_EOL;
echo "Reply 36–72h   → yellow_flag: {$counts['reply_yellow']}" . PHP_EOL;
echo "Reply >72h     → red_flag: {$counts['reply_red']}" . PHP_EOL;
