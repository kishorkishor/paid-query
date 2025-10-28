<?php
// Fetch full query + messages (customer view)

require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');

try {
    $pdo = db();

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad id']); exit; }

    // Query info
    $st = $pdo->prepare("SELECT * FROM queries WHERE id=?");
    $st->execute([$id]);
    $query = $st->fetch(PDO::FETCH_ASSOC);
    if (!$query) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    // Messages
    $ms = $pdo->prepare("
        SELECT id, direction, medium, body, sender_admin_id, sender_clerk_user_id, created_at
        FROM messages
        WHERE query_id=?
          AND medium <> 'internal'
        ORDER BY created_at ASC, id ASC
    ");
    $ms->execute([$id]);
    $messages = $ms->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'query'=>$query,'messages'=>$messages]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error']);
}
