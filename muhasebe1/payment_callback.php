<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Odeme sistemi hazir degil.']);
    exit;
}

$callbackLimit = app_rate_limit_check('payment_callback:' . app_client_ip(), 120, 60);
if (!$callbackLimit['allowed']) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'message' => 'Cok fazla callback istegi alindi.']);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);
$payload = is_array($jsonBody) ? $jsonBody : array_merge($_GET, $_POST);
$ref = trim((string) ($payload['transaction_ref'] ?? $payload['ref'] ?? $payload['reference'] ?? ''));
$providerStatus = trim((string) ($payload['provider_status'] ?? $payload['payment_status'] ?? $payload['status'] ?? $payload['result'] ?? ''));
$providedSecret = trim((string) ($payload['secret'] ?? $payload['callback_secret'] ?? $_SERVER['HTTP_X_CALLBACK_SECRET'] ?? ''));
$webhookLogId = null;

$writeWebhookLog = static function (
    PDO $db,
    ?int $logId,
    ?array $transaction,
    string $ref,
    string $providerStatus,
    string $verificationStatus,
    int $httpStatus,
    array $payload,
    string $rawBody,
    bool $processed
): int {
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($logId !== null) {
        $stmt = $db->prepare('
            UPDATE collections_webhook_logs
            SET transaction_id = :transaction_id,
                pos_account_id = :pos_account_id,
                transaction_ref = :transaction_ref,
                provider_status = :provider_status,
                verification_status = :verification_status,
                http_status = :http_status,
                payload = :payload,
                raw_body = :raw_body,
                processed_at = :processed_at
            WHERE id = :id
        ');
        $stmt->execute([
            'transaction_id' => $transaction ? (int) ($transaction['id'] ?? 0) ?: null : null,
            'pos_account_id' => $transaction ? (int) ($transaction['pos_account_id'] ?? 0) ?: null : null,
            'transaction_ref' => $ref !== '' ? $ref : null,
            'provider_status' => $providerStatus !== '' ? $providerStatus : null,
            'verification_status' => $verificationStatus,
            'http_status' => $httpStatus,
            'payload' => $encodedPayload,
            'raw_body' => $rawBody !== '' ? $rawBody : null,
            'processed_at' => $processed ? date('Y-m-d H:i:s') : null,
            'id' => $logId,
        ]);

        return $logId;
    }

    $stmt = $db->prepare('
        INSERT INTO collections_webhook_logs (
            transaction_id, pos_account_id, transaction_ref, event_type, provider_status,
            verification_status, http_status, payload, raw_body, remote_ip, processed_at
        ) VALUES (
            :transaction_id, :pos_account_id, :transaction_ref, :event_type, :provider_status,
            :verification_status, :http_status, :payload, :raw_body, :remote_ip, :processed_at
        )
    ');
    $stmt->execute([
        'transaction_id' => $transaction ? (int) ($transaction['id'] ?? 0) ?: null : null,
        'pos_account_id' => $transaction ? (int) ($transaction['pos_account_id'] ?? 0) ?: null : null,
        'transaction_ref' => $ref !== '' ? $ref : null,
        'event_type' => 'payment_callback',
        'provider_status' => $providerStatus !== '' ? $providerStatus : null,
        'verification_status' => $verificationStatus,
        'http_status' => $httpStatus,
        'payload' => $encodedPayload,
        'raw_body' => $rawBody !== '' ? $rawBody : null,
        'remote_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'processed_at' => $processed ? date('Y-m-d H:i:s') : null,
    ]);

    return (int) $db->lastInsertId();
};

$webhookLogId = $writeWebhookLog($db, null, null, $ref, $providerStatus, 'bekliyor', 202, $payload, $rawBody, false);

if ($ref === '') {
    http_response_code(400);
    $writeWebhookLog($db, $webhookLogId, null, $ref, $providerStatus, 'hatali', 400, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Islem referansi eksik callback alindi.', $ref, $providerStatus, 400);
    echo json_encode(['ok' => false, 'message' => 'Islem referansi eksik.']);
    exit;
}

$rows = app_fetch_all($db, '
    SELECT t.*, p.callback_secret
    FROM collections_transactions t
    LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
    WHERE t.transaction_ref = :ref
    ORDER BY t.id DESC
    LIMIT 1
', ['ref' => $ref]);

if (!$rows) {
    http_response_code(404);
    $writeWebhookLog($db, $webhookLogId, null, $ref, $providerStatus, 'hatali', 404, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Callback icin POS islemi bulunamadi.', $ref, $providerStatus, 404);
    echo json_encode(['ok' => false, 'message' => 'Islem bulunamadi.']);
    exit;
}

$transaction = $rows[0];
$expectedSecret = trim((string) ($transaction['callback_secret'] ?? ''));
if ($expectedSecret === '') {
    http_response_code(403);
    $writeWebhookLog($db, $webhookLogId, $transaction, $ref, $providerStatus, 'hatali', 403, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Callback secret tanimsiz oldugu icin istek reddedildi.', $ref, $providerStatus, 403);
    echo json_encode(['ok' => false, 'message' => 'POS callback ayarlari eksik.']);
    exit;
}

if (!hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    $writeWebhookLog($db, $webhookLogId, $transaction, $ref, $providerStatus, 'hatali', 403, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Callback secret dogrulamasi basarisiz.', $ref, $providerStatus, 403);
    echo json_encode(['ok' => false, 'message' => 'Callback dogrulamasi basarisiz.']);
    exit;
}

$signedPayload = [
    'ref' => $ref,
    'status' => $providerStatus,
    'timestamp' => (string) ($payload['timestamp'] ?? ''),
    'nonce' => (string) ($payload['nonce'] ?? ''),
    'signature' => (string) ($payload['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? ''),
];
if (!app_verify_payment_signature($signedPayload, $expectedSecret)) {
    http_response_code(403);
    $writeWebhookLog($db, $webhookLogId, $transaction, $ref, $providerStatus, 'hatali', 403, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Callback imza dogrulamasi basarisiz.', $ref, $providerStatus, 403);
    echo json_encode(['ok' => false, 'message' => 'Callback imzasi gecersiz.']);
    exit;
}

$providerResponse = json_encode([
    'payload' => $payload,
    'raw_body' => $rawBody,
    'headers' => [
        'x_callback_secret' => $_SERVER['HTTP_X_CALLBACK_SECRET'] ?? null,
        'x_signature' => $_SERVER['HTTP_X_SIGNATURE'] ?? null,
    ],
    'received_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    $newStatus = app_process_collection_callback($db, $transaction, $providerStatus, $providerResponse);
    if ($newStatus === 'basarili') {
        app_audit_log('tahsilat_public', 'public_payment_callback_success', 'collections_transactions', (int) $transaction['id'], $ref . ' callback ile tamamlandi.');
    } elseif ($newStatus === 'hatali') {
        app_audit_log('tahsilat_public', 'public_payment_callback_fail', 'collections_transactions', (int) $transaction['id'], $ref . ' callback ile hatali kapandi.');
        app_queue_webhook_failure_notification($db, $webhookLogId, 'Saglayici callback islemi hatali bildirdi.', $ref, $providerStatus, 200);
    }

    echo json_encode(['ok' => true, 'status' => $newStatus, 'transaction_ref' => $ref]);
    $writeWebhookLog($db, $webhookLogId, $transaction, $ref, $providerStatus, 'dogrulandi', 200, $payload, $rawBody, true);
} catch (Throwable $e) {
    http_response_code(500);
    $writeWebhookLog($db, $webhookLogId, $transaction ?? null, $ref, $providerStatus, 'hatali', 500, $payload, $rawBody, true);
    app_queue_webhook_failure_notification($db, $webhookLogId, 'Callback islenirken sistem hatasi olustu.', $ref, $providerStatus, 500);
    echo json_encode(['ok' => false, 'message' => 'Callback islenemedi.']);
}
