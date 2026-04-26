<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$db = app_db();
$ready = $db && app_database_ready();
$ref = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? $_GET['transaction_ref'] ?? $_POST['transaction_ref'] ?? ''));
$result = strtolower(trim((string) ($_GET['result'] ?? $_POST['result'] ?? $_GET['status'] ?? $_POST['status'] ?? '')));
$timestamp = (string) ($_GET['timestamp'] ?? $_POST['timestamp'] ?? '');
$nonce = (string) ($_GET['nonce'] ?? $_POST['nonce'] ?? '');
$signature = (string) ($_GET['signature'] ?? $_POST['signature'] ?? '');
$feedback = '';
$error = '';
$successStatuses = ['success', 'basarili', 'approved', 'ok', 'paid'];
$failStatuses = ['fail', 'failed', 'hatali', 'declined', 'cancel', 'cancelled', 'error'];

if (!$db || !$ready) {
    http_response_code(503);
    $error = 'Odeme sistemi su anda hazir degil.';
}

if ($error === '' && $ref === '') {
    http_response_code(400);
    $error = 'Islem referansi eksik.';
}

$transaction = null;
if ($error === '') {
    $rows = app_fetch_all($db, '
        SELECT t.*, l.link_code, c.company_name, c.full_name, p.callback_secret
        FROM collections_transactions t
        LEFT JOIN collections_links l ON l.id = t.link_id
        LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
        INNER JOIN cari_cards c ON c.id = t.cari_id
        WHERE t.transaction_ref = :ref
        ORDER BY t.id DESC
        LIMIT 1
    ', ['ref' => $ref]);

    if (!$rows) {
        http_response_code(404);
        $error = 'Odeme islemi bulunamadi.';
    } else {
        $transaction = $rows[0];
    }
}

if ($error === '' && $transaction !== null) {
    try {
        $expectedSecret = trim((string) ($transaction['callback_secret'] ?? ''));
        $signedPayload = [
            'ref' => $ref,
            'status' => $result,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
        ];
        if ($expectedSecret === '' || !app_verify_payment_signature($signedPayload, $expectedSecret)) {
            throw new RuntimeException('Odeme donus imzasi dogrulanamadi.');
        }

        $providerResponse = json_encode([
            'get' => $_GET,
            'post' => $_POST,
            'received_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $threeDStatus = in_array($result, $successStatuses, true) ? 'basarili' : (in_array($result, $failStatuses, true) ? 'hatali' : ($result ?: 'bekliyor'));

        $stmt = $db->prepare('
            UPDATE collections_transactions
            SET three_d_status = :three_d_status, three_d_response = :three_d_response, three_d_completed_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            'three_d_status' => $threeDStatus,
            'three_d_response' => $providerResponse,
            'id' => (int) $transaction['id'],
        ]);

        if ($threeDStatus === 'basarili') {
            $bankRows = app_fetch_all($db, '
                SELECT id
                FROM finance_bank_accounts
                ORDER BY id ASC
                LIMIT 1
            ');
            if (!$bankRows) {
                throw new RuntimeException('Tahsilat icin tanimli banka hesabi bulunamadi.');
            }

            app_complete_collection_transaction($db, (int) $transaction['id'], (int) $bankRows[0]['id'], '3D Secure online tahsilat / ' . $ref);
            app_audit_log('tahsilat_public', 'public_payment_3d_success', 'collections_transactions', (int) $transaction['id'], $ref . ' 3D Secure odemesi tamamlandi.');
            $feedback = 'Odemeniz basariyla tamamlandi. Ref: ' . $ref;
        } elseif ($threeDStatus === 'hatali') {
            $stmt = $db->prepare("
                UPDATE collections_transactions
                SET status = 'hatali', processed_at = NOW()
                WHERE id = :id AND status = 'bekliyor'
            ");
            $stmt->execute(['id' => (int) $transaction['id']]);
            app_audit_log('tahsilat_public', 'public_payment_3d_fail', 'collections_transactions', (int) $transaction['id'], $ref . ' 3D Secure odemesi basarisiz.');
            $error = 'Odeme dogrulamasi basarisiz oldu. Ref: ' . $ref;
        } else {
            $feedback = 'Odeme dogrulama sonucu bekleniyor. Ref: ' . $ref;
        }
    } catch (Throwable $e) {
        $error = 'Odeme sonucu dogrulanamadi. Lutfen destek ile iletisime gecin.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Odeme Sonucu</title>
    <style>
        :root { --ink:#1f2937; --muted:#667085; --line:#eadfce; --accent:#c2410c; --paper:rgba(255,255,255,.96); }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at 0% 0%,rgba(34,197,94,.22),transparent 22rem),radial-gradient(circle at 100% 20%,rgba(251,146,60,.25),transparent 18rem),linear-gradient(145deg,#faf6ee,#f3eadc); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .wrap { width:min(680px,100%); background:var(--paper); border:1px solid var(--line); border-radius:30px; padding:30px; box-shadow:0 24px 60px rgba(124,45,18,.08); }
        h1 { margin:0 0 8px; font-size:2rem; }
        p { color:var(--muted); line-height:1.7; }
        .notice { margin:16px 0; padding:14px 16px; border-radius:16px; font-weight:700; }
        .ok { background:#dcfce7; color:#166534; }
        .err { background:#fee2e2; color:#991b1b; }
        a { color:#7c2d12; font-weight:700; text-decoration:none; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Odeme Sonucu</h1>
        <p>3D Secure dogrulama sonucu sistem tarafindan islendi.</p>

        <?php if ($feedback !== ''): ?>
            <div class="notice ok"><?= app_h($feedback) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice err"><?= app_h($error) ?></div>
        <?php endif; ?>

        <?php if ($transaction !== null): ?>
            <p>Cari: <strong><?= app_h((string) ($transaction['company_name'] ?: $transaction['full_name'] ?: '-')) ?></strong></p>
            <p>Tutar: <strong><?= number_format((float) $transaction['amount'], 2, ',', '.') ?> TRY</strong></p>
        <?php endif; ?>
    </div>
</body>
</html>
