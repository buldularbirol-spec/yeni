<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$db = app_db();
$ready = $db && app_database_ready();

header('Content-Type: application/json; charset=utf-8');

if (!$db || !$ready) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'database_not_ready']);
    exit;
}

app_notifications_ensure_schema($db);
$token = trim((string) ($_GET['token'] ?? ''));
$expectedToken = app_notification_runner_token($db);

if ($token === '' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'invalid_token']);
    exit;
}

$scan = app_generate_notification_queue($db);
$process = app_process_notification_queue($db, 50);

app_audit_log('bildirim', 'runner', 'notification_queue', null, 'Runner calisti.');

echo json_encode([
    'ok' => true,
    'scan' => $scan,
    'process' => $process,
    'ran_at' => date('Y-m-d H:i:s'),
]);
