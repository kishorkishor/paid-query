<?php
// /api/add_admin_message.php
// Add an admin message (internal note, or outbound contact/update)

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../_php_errors.log');

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---- auth (uses your existing backoffice session) ----
$adminId = (int)($_SESSION['admin']['id'] ?? 0);
if ($adminId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = db();

    // Accept either `query_id` (preferred) or legacy `id`
    $qid       = (int)($_POST['query_id'] ?? $_POST['id'] ?? 0);
    $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
    $medium    = strtolower(trim((string)($_POST['medium'] ?? '')));
    $body      = trim((string)($_POST['body'] ?? ''));

    if ($qid <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing input: query_id']);
        exit;
    }

    // Normalize & validate direction/medium
    // Allowed:
    //   internal/note
    //   outbound/message
    //   outbound/whatsapp|email|voice|other
    $allowedDirections = ['internal', 'outbound'];
    if (!in_array($direction, $allowedDirections, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad direction']);
        exit;
    }

    if ($direction === 'internal') {
        // Force internal medium to 'note'
        $medium = 'note';
    } else {
        // outbound
        if ($medium === 'portal') $medium = 'message'; // backward-compat
        $allowedOutbound = ['message', 'whatsapp', 'email', 'voice', 'other'];
        if (!in_array($medium, $allowedOutbound, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad medium']);
            exit;
        }
    }

    // For notes and messages we require text; for contact stubs (whatsapp/email/voice/other) body may be empty
    $needsText = ($medium === 'note' || $medium === 'message');
    if ($needsText && $body === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing input: body']);
        exit;
    }

    // Ensure the query exists (and optionally you could check permissions by team)
    $q = $pdo->prepare("SELECT id FROM queries WHERE id = ? LIMIT 1");
    $q->execute([$qid]);
    if (!$q->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Query not found']);
        exit;
    }

    // Insert message
    $ins = $pdo->prepare("
        INSERT INTO messages
            (query_id, direction, medium, body, sender_admin_id, created_at)
        VALUES
            (?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([$qid, $direction, $medium, $body, $adminId]);

    // Touch queries.updated_at if the column exists
    $col = $pdo->query("SHOW COLUMNS FROM queries LIKE 'updated_at'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->prepare("UPDATE queries SET updated_at = NOW() WHERE id = ?")->execute([$qid]);
    }

    echo json_encode(['ok' => true, 'message_id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
