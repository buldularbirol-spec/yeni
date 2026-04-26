<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$db = app_db();
$ready = $db && app_database_ready();
$code = trim((string) ($_GET['code'] ?? ''));
$feedback = '';
$error = '';

if (!$db || !$ready) {
    http_response_code(503);
    $error = 'Odeme sistemi su anda hazir degil.';
}

if ($error === '' && $code === '') {
    http_response_code(400);
    $error = 'Odeme link kodu eksik.';
}

$link = null;
if ($error === '') {
    $rows = app_fetch_all($db, '
        SELECT l.*, c.company_name, c.full_name, c.email, c.phone, p.provider_name
        FROM collections_links l
        INNER JOIN cari_cards c ON c.id = l.cari_id
        LEFT JOIN collections_pos_accounts p ON p.id = l.pos_account_id
        WHERE l.link_code = :link_code
        LIMIT 1
    ', ['link_code' => $code]);

    if (!$rows) {
        http_response_code(404);
        $error = 'Odeme linki bulunamadi.';
    } else {
        $link = $rows[0];
    }
}

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $link !== null) {
    try {
        $limit = app_rate_limit_check('payment:' . app_client_ip() . ':' . $code, 20, 600);
        if (!$limit['allowed']) {
            throw new RuntimeException('rate_limit');
        }
        app_require_csrf();
        if ((string) $link['status'] === 'odendi') {
            throw new RuntimeException('Bu odeme linki daha once kullanilmis.');
        }

        if (!empty($link['expires_at']) && strtotime((string) $link['expires_at']) < time()) {
            throw new RuntimeException('Bu odeme linkinin suresi dolmus.');
        }

        $payerName = trim((string) ($_POST['payer_name'] ?? '')) ?: 'Online Musteri';
        $maxInstallment = max(1, (int) ($link['installment_count'] ?? 1));
        $selectedInstallment = max(1, min($maxInstallment, (int) ($_POST['installment_count'] ?? 1)));
        $transactionRef = 'WEB-' . date('YmdHis');
        $posRows = [];
        if ((int) ($link['pos_account_id'] ?? 0) > 0) {
            $posRows = app_fetch_all($db, '
                SELECT *
                FROM collections_pos_accounts
                WHERE id = :id
                LIMIT 1
            ', ['id' => (int) $link['pos_account_id']]);
        }

        $posAccount = $posRows[0] ?? ['api_mode' => 'mock', 'provider_name' => 'Online Tahsilat'];
        $commissionRate = max(0, (float) ($posAccount['commission_rate'] ?? 0));
        $commissionAmount = round((float) $link['amount'] * $commissionRate / 100, 2);
        $netAmount = max(0, round((float) $link['amount'] - $commissionAmount, 2));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/payment.php'))), '/');
        $returnBase = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/payment_return.php';
        $callbackBase = $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/payment_callback.php';
        $defaultSuccessUrl = $returnBase . '?ref=' . urlencode($transactionRef) . '&result=success';
        $defaultFailUrl = $returnBase . '?ref=' . urlencode($transactionRef) . '&result=fail';
        $paymentResult = app_virtual_pos_start_transaction($db, $posAccount, $link, [
            'payer_name' => $payerName,
            'transaction_ref' => $transactionRef,
            'currency_code' => 'TRY',
            'installment_count' => $selectedInstallment,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'net_amount' => $netAmount,
            'success_url' => trim((string) ($posAccount['success_url'] ?? '')) ?: $defaultSuccessUrl,
            'fail_url' => trim((string) ($posAccount['fail_url'] ?? '')) ?: $defaultFailUrl,
            'callback_url' => $callbackBase . '?ref=' . urlencode($transactionRef),
        ]);

        if ((string) $paymentResult['status'] === 'basarili') {
            $bankRows = app_fetch_all($db, '
                SELECT id
                FROM finance_bank_accounts
                ORDER BY id ASC
                LIMIT 1
            ');
            if (!$bankRows) {
                throw new RuntimeException('Tahsilat icin tanimli banka hesabi bulunamadi.');
            }

            $transactionId = app_register_collection_payment($db, [
                'link_id' => (int) $link['id'],
                'cari_id' => (int) $link['cari_id'],
                'pos_account_id' => (int) ($link['pos_account_id'] ?? 0) ?: null,
                'bank_account_id' => (int) $bankRows[0]['id'],
                'amount' => (float) $link['amount'],
                'installment_count' => $selectedInstallment,
                'commission_rate' => $commissionRate,
                'transaction_ref' => (string) ($paymentResult['transaction_ref'] ?? $transactionRef),
                'description' => $link['link_code'] . ' online odeme / ' . $payerName,
                'currency_code' => 'TRY',
                'created_by' => 1,
            ]);

            app_audit_log('tahsilat_public', 'public_payment', 'collections_transactions', $transactionId, $link['link_code'] . ' linki odendi.');
            $feedback = 'Odemeniz basariyla alindi. Ref: ' . (string) ($paymentResult['transaction_ref'] ?? $transactionRef);
        } else {
            $stmt = $db->prepare('
                INSERT INTO collections_transactions (
                    link_id, cari_id, pos_account_id, amount, installment_count, commission_rate,
                    commission_amount, net_amount, status, transaction_ref,
                    three_d_status, three_d_redirect_url, three_d_response, processed_at
                ) VALUES (
                    :link_id, :cari_id, :pos_account_id, :amount, :installment_count, :commission_rate,
                    :commission_amount, :net_amount, :status, :transaction_ref,
                    :three_d_status, :three_d_redirect_url, :three_d_response, :processed_at
                )
            ');
            $stmt->execute([
                'link_id' => (int) $link['id'],
                'cari_id' => (int) $link['cari_id'],
                'pos_account_id' => (int) ($link['pos_account_id'] ?? 0) ?: null,
                'amount' => (float) $link['amount'],
                'installment_count' => $selectedInstallment,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commissionAmount,
                'net_amount' => $netAmount,
                'status' => 'bekliyor',
                'transaction_ref' => (string) ($paymentResult['transaction_ref'] ?? $transactionRef),
                'three_d_status' => $paymentResult['three_d_status'] ?? null,
                'three_d_redirect_url' => trim((string) ($paymentResult['three_d_redirect_url'] ?? $paymentResult['redirect_url'] ?? '')) ?: null,
                'three_d_response' => trim((string) ($paymentResult['response'] ?? '')) ?: null,
                'processed_at' => null,
            ]);
            $transactionId = (int) $db->lastInsertId();
            app_audit_log('tahsilat_public', 'public_payment_pending', 'collections_transactions', $transactionId, $link['link_code'] . ' linki POS saglayicisina gonderildi.');

            if (trim((string) ($paymentResult['redirect_url'] ?? '')) !== '') {
                header('Location: ' . trim((string) $paymentResult['redirect_url']));
                exit;
            }

            $feedback = 'Odeme isteginiz POS saglayicisina iletildi. Ref: ' . (string) ($paymentResult['transaction_ref'] ?? $transactionRef);
        }

        $rows = app_fetch_all($db, '
            SELECT l.*, c.company_name, c.full_name, c.email, c.phone, p.provider_name
            FROM collections_links l
            INNER JOIN cari_cards c ON c.id = l.cari_id
            LEFT JOIN collections_pos_accounts p ON p.id = l.pos_account_id
            WHERE l.id = :id
            LIMIT 1
        ', ['id' => (int) $link['id']]);
        $link = $rows ? $rows[0] : $link;
    } catch (Throwable $e) {
        $error = $e->getMessage() === 'rate_limit'
            ? 'Cok fazla odeme denemesi yapildi. Lutfen daha sonra tekrar deneyin.'
            : 'Odeme islemi tamamlanamadi. Lutfen bilgilerinizi kontrol edip tekrar deneyin.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Odeme Sayfasi</title>
    <style>
        :root { --ink:#1f2937; --muted:#667085; --line:#eadfce; --accent:#c2410c; --paper:rgba(255,255,255,.95); }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at 0% 0%,rgba(250,204,21,.35),transparent 22rem),radial-gradient(circle at 100% 20%,rgba(251,146,60,.25),transparent 18rem),linear-gradient(145deg,#faf6ee,#f3eadc); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .wrap { width:min(760px,100%); background:var(--paper); border:1px solid var(--line); border-radius:30px; padding:30px; box-shadow:0 24px 60px rgba(124,45,18,.08); }
        h1 { margin:0 0 8px; font-size:2rem; }
        p { color:var(--muted); line-height:1.7; }
        .notice { margin:16px 0; padding:14px 16px; border-radius:16px; font-weight:700; }
        .ok { background:#dcfce7; color:#166534; }
        .err { background:#fee2e2; color:#991b1b; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-top:20px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:20px; padding:18px; }
        .card small { display:block; color:var(--muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:.05em; }
        .card strong { font-size:1.2rem; color:#7c2d12; }
        .form-grid { display:grid; gap:14px; margin-top:22px; }
        label { display:block; margin-bottom:6px; font-weight:600; color:#4b5563; }
        input, select { width:100%; border:1px solid var(--line); border-radius:12px; padding:12px; font:inherit; background:#fff; }
        button { border:0; border-radius:14px; background:var(--accent); color:#fff; padding:14px 18px; font-weight:700; cursor:pointer; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Guvenli Odeme Sayfasi</h1>
        <p>Odeme linkiniz uzerinden tutari ve alici bilgisini kontrol edip odemeyi tamamlayabilirsiniz.</p>

        <?php if ($feedback !== ''): ?>
            <div class="notice ok"><?= app_h($feedback) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice err"><?= app_h($error) ?></div>
        <?php endif; ?>

        <?php if ($link !== null): ?>
            <div class="grid">
                <div class="card"><small>Link Kodu</small><strong><?= app_h((string) $link['link_code']) ?></strong></div>
                <div class="card"><small>Cari</small><strong><?= app_h((string) ($link['company_name'] ?: $link['full_name'] ?: '-')) ?></strong></div>
                <div class="card"><small>Tutar</small><strong><?= number_format((float) $link['amount'], 2, ',', '.') ?> TRY</strong></div>
                <div class="card"><small>Durum</small><strong><?= app_h((string) $link['status']) ?></strong></div>
                <div class="card"><small>Taksit</small><strong><?= (int) $link['installment_count'] ?></strong></div>
                <div class="card"><small>POS</small><strong><?= app_h((string) ($link['provider_name'] ?: 'Online Tahsilat')) ?></strong></div>
            </div>

            <?php if ((string) $link['status'] !== 'odendi' && (empty($link['expires_at']) || strtotime((string) $link['expires_at']) >= time())): ?>
                <form method="post" class="form-grid">
                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                    <div>
                        <label>Odeyen Adi</label>
                        <input name="payer_name" placeholder="Ad Soyad / Firma Unvani">
                    </div>
                    <?php if ((int) $link['installment_count'] > 1): ?>
                        <div>
                            <label>Taksit Secimi</label>
                            <select name="installment_count">
                                <?php for ($i = 1; $i <= (int) $link['installment_count']; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i === 1 ? 'Tek Cekim' : $i . ' Taksit' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <button type="submit">Odemeyi Tamamla</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
