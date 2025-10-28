<?php
// Customer sends a message in query thread

require_once __DIR__ . '/lib.php';  // db()

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

try {
    $pdo = db();

    $id   = (int)($_POST['id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $cust = (int)($_POST['customer_id'] ?? 0); // pass customer_id from session/form

    if ($id <= 0 || $cust <= 0 || $body === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing input']); exit;
    }

    // Check ownership: query belongs to this customer
    $chk = $pdo->prepare("SELECT id FROM queries WHERE id=? AND customer_id=?");
    $chk->execute([$id,$cust]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Not your query']); exit;
    }

    // Insert message
    $ins = $pdo->prepare("
        INSERT INTO messages
          (query_id, direction, medium, body, sender_clerk_user_id, created_at)
        VALUES
          (?, 'inbound', 'portal', ?, ?, NOW())
    ");
    $ins->execute([$id, $body, $cust]);

    // Update query timestamp
    $pdo->prepare("UPDATE queries SET updated_at=NOW() WHERE id=?")->execute([$id]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error']);
}
