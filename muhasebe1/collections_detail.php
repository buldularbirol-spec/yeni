<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$type = trim((string) ($_GET['type'] ?? 'link'));
$id = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['link', 'transaction', 'webhook'], true) || $id <= 0) {
    http_response_code(400);
    exit('Gecerli tahsilat kaydi secilmedi.');
}

$payloadPreview = '';
$rawBodyPreview = '';

if ($type === 'link') {
    $rows = app_fetch_all($db, '
        SELECT l.*, c.company_name, c.full_name, c.phone, c.email, p.provider_name, p.merchant_code
        FROM collections_links l
        INNER JOIN cari_cards c ON c.id = l.cari_id
        LEFT JOIN collections_pos_accounts p ON p.id = l.pos_account_id
        WHERE l.id = :id
        LIMIT 1
    ', ['id' => $id]);
    if (!$rows) {
        http_response_code(404);
        exit('Odeme linki bulunamadi.');
    }
    $header = $rows[0];
    $title = (string) ($header['link_code'] ?: ('Link #' . $id));
    $transactions = app_fetch_all($db, '
        SELECT id, amount, installment_count, commission_amount, net_amount, status, transaction_ref, processed_at
        FROM collections_transactions
        WHERE link_id = :link_id
        ORDER BY id DESC
        LIMIT 20
    ', ['link_id' => $id]);
    $docs = app_fetch_all($db, '
        SELECT id, file_name, file_type, created_at
        FROM docs_files
        WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
        ORDER BY id DESC
        LIMIT 12
    ', ['module_name' => 'tahsilat', 'related_table' => 'collections_links', 'related_id' => $id]);
    $summary = [
        'Durum' => (string) ($header['status'] ?: '-'),
        'Tutar' => number_format((float) ($header['amount'] ?? 0), 2, ',', '.'),
        'Taksit' => (string) ($header['installment_count'] ?: '1'),
        'Islem' => (string) count($transactions),
        'Evrak' => (string) count($docs),
    ];
    $docUrl = app_doc_upload_url('tahsilat', 'collections_links', $id, 'collections_detail.php?type=link&id=' . $id);
    $infoRows = [
        'Cari' => (string) ($header['company_name'] ?: $header['full_name'] ?: '-'),
        'Telefon' => (string) ($header['phone'] ?: '-'),
        'E-posta' => (string) ($header['email'] ?: '-'),
        'POS Saglayici' => (string) ($header['provider_name'] ?: '-'),
        'Merchant' => (string) ($header['merchant_code'] ?: '-'),
        'Son Gecerlilik' => (string) ($header['expires_at'] ?: '-'),
    ];
} elseif ($type === 'transaction') {
    $rows = app_fetch_all($db, '
        SELECT t.*, c.company_name, c.full_name, c.phone, c.email, p.provider_name, p.merchant_code, l.link_code
        FROM collections_transactions t
        INNER JOIN cari_cards c ON c.id = t.cari_id
        LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
        LEFT JOIN collections_links l ON l.id = t.link_id
        WHERE t.id = :id
        LIMIT 1
    ', ['id' => $id]);
    if (!$rows) {
        http_response_code(404);
        exit('POS islemi bulunamadi.');
    }
    $header = $rows[0];
    $title = (string) (($header['transaction_ref'] ?: '') !== '' ? $header['transaction_ref'] : ('POS#' . $id));
    $cariEffects = app_fetch_all($db, '
        SELECT movement_type, amount, currency_code, description, movement_date
        FROM cari_movements
        WHERE source_module IN ("tahsilat", "tahsilat_iade") AND source_table = :source_table AND source_id = :source_id
        ORDER BY id DESC
        LIMIT 12
    ', ['source_table' => 'collections_transactions', 'source_id' => $id]);
    $bankEffects = app_fetch_all($db, '
        SELECT movement_type, amount, description, movement_date, b.bank_name, b.account_name
        FROM finance_bank_movements m
        LEFT JOIN finance_bank_accounts b ON b.id = m.bank_account_id
        WHERE m.description LIKE :pattern
        ORDER BY m.id DESC
        LIMIT 12
    ', ['pattern' => '%' . ($header['transaction_ref'] ?: '') . '%']);
    $commissionExpenses = app_fetch_all($db, '
        SELECT expense_type, amount, description, expense_date
        FROM finance_expenses
        WHERE source_module = :source_module AND source_table = :source_table AND source_id = :source_id
        ORDER BY id DESC
        LIMIT 12
    ', ['source_module' => 'tahsilat', 'source_table' => 'collections_transactions', 'source_id' => $id]);
    $docs = app_fetch_all($db, '
        SELECT id, file_name, file_type, created_at
        FROM docs_files
        WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
        ORDER BY id DESC
        LIMIT 12
    ', ['module_name' => 'tahsilat', 'related_table' => 'collections_transactions', 'related_id' => $id]);
    $summary = [
        'Durum' => (string) ($header['status'] ?: '-'),
        'Tutar' => number_format((float) ($header['amount'] ?? 0), 2, ',', '.'),
        'Net' => number_format((float) ($header['net_amount'] ?? $header['amount'] ?? 0), 2, ',', '.'),
        'Komisyon' => number_format((float) ($header['commission_amount'] ?? 0), 2, ',', '.'),
        'Iade' => number_format((float) ($header['refunded_amount'] ?? 0), 2, ',', '.'),
        'Taksit' => (string) ($header['installment_count'] ?: '1'),
        'Link' => (string) ($header['link_code'] ?: '-'),
        'Cari Etki' => (string) count($cariEffects),
        'Banka Etki' => (string) count($bankEffects),
        'Gider Etki' => (string) count($commissionExpenses),
        'Evrak' => (string) count($docs),
    ];
    $docUrl = app_doc_upload_url('tahsilat', 'collections_transactions', $id, 'collections_detail.php?type=transaction&id=' . $id);
    $infoRows = [
        'Cari' => (string) ($header['company_name'] ?: $header['full_name'] ?: '-'),
        'Telefon' => (string) ($header['phone'] ?: '-'),
        'E-posta' => (string) ($header['email'] ?: '-'),
        'POS Saglayici' => (string) ($header['provider_name'] ?: '-'),
        'Merchant' => (string) ($header['merchant_code'] ?: '-'),
        'Islem Zamani' => (string) ($header['processed_at'] ?: '-'),
        'Komisyon Orani' => '%' . number_format((float) ($header['commission_rate'] ?? 0), 2, ',', '.'),
        'Saglayici Durumu' => (string) ($header['provider_status'] ?: '-'),
        'Son Sorgu' => (string) ($header['last_status_check_at'] ?: '-'),
        'Mutabakat' => (string) ($header['reconciled_at'] ?: '-'),
        'Iade Tarihi' => (string) ($header['refunded_at'] ?: '-'),
        'Iade Nedeni' => (string) ($header['refund_reason'] ?: '-'),
        'Bagli Link' => (string) ($header['link_code'] ?: '-'),
    ];
} else {
    $rows = app_fetch_all($db, '
        SELECT w.*, t.status AS transaction_status, t.amount, t.transaction_ref AS linked_ref, p.provider_name, p.merchant_code,
               q.id AS notification_id, q.status AS notification_status, q.recipient_contact AS notification_recipient,
               q.subject_line AS notification_subject, q.processed_at AS notification_processed_at
        FROM collections_webhook_logs w
        LEFT JOIN collections_transactions t ON t.id = w.transaction_id
        LEFT JOIN collections_pos_accounts p ON p.id = w.pos_account_id
        LEFT JOIN notification_queue q ON q.source_table = "collections_webhook_logs" AND q.source_id = w.id AND q.notification_type = "webhook_error"
        WHERE w.id = :id
        LIMIT 1
    ', ['id' => $id]);
    if (!$rows) {
        http_response_code(404);
        exit('Webhook logu bulunamadi.');
    }
    $header = $rows[0];
    $title = 'Webhook Log #' . $id;
    $docs = [];
    $docUrl = app_doc_upload_url('tahsilat', 'collections_webhook_logs', $id, 'collections_detail.php?type=webhook&id=' . $id);
    $payloadDecoded = json_decode((string) ($header['payload'] ?? ''), true);
    $payloadPreview = $payloadDecoded !== null
        ? json_encode($payloadDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string) ($header['payload'] ?? '');
    $rawBodyPreview = (string) ($header['raw_body'] ?? '');
    $summary = [
        'Dogrulama' => (string) ($header['verification_status'] ?: '-'),
        'HTTP' => (string) ($header['http_status'] ?: '-'),
        'Saglayici' => (string) ($header['provider_status'] ?: '-'),
        'Islem' => (string) ($header['transaction_status'] ?: '-'),
        'Tarih' => (string) ($header['processed_at'] ?: $header['created_at'] ?: '-'),
    ];
    $infoRows = [
        'Ref' => (string) ($header['transaction_ref'] ?: '-'),
        'Bagli Islem ID' => (string) ($header['transaction_id'] ?: '-'),
        'Bagli Ref' => (string) ($header['linked_ref'] ?: '-'),
        'POS Saglayici' => (string) ($header['provider_name'] ?: '-'),
        'Merchant' => (string) ($header['merchant_code'] ?: '-'),
        'Event' => (string) ($header['event_type'] ?: '-'),
        'Remote IP' => (string) ($header['remote_ip'] ?: '-'),
        'Alarm Bildirimi' => !empty($header['notification_id']) ? ('#' . $header['notification_id'] . ' / ' . ($header['notification_status'] ?: '-')) : '-',
        'Alarm Alicisi' => (string) ($header['notification_recipient'] ?: '-'),
        'Cozum Tarihi' => (string) ($header['resolved_at'] ?: '-'),
        'Cozum Notu' => (string) ($header['resolution_note'] ?: '-'),
        'Kayit Zamani' => (string) ($header['created_at'] ?: '-'),
    ];
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | Tahsilat Detay</title>
    <style>
        :root { --paper:rgba(255,255,255,.95); --ink:#1f2937; --muted:#667085; --line:#eadfce; --accent:#c2410c; --accent2:#7c2d12; --soft:#fff1df; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at top left,rgba(251,191,36,.22),transparent 20rem),linear-gradient(145deg,#faf6ee,#f3eadc); }
        .shell { width:min(1380px,100% - 36px); margin:24px auto 40px; }
        .hero { background:linear-gradient(135deg,rgba(255,255,255,.88),rgba(255,245,230,.96)); border:1px solid var(--line); border-radius:30px; padding:28px; box-shadow:0 24px 60px rgba(124,45,18,.08); }
        .hero-top { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; }
        h1 { margin:0 0 8px; font-size:2rem; }
        p { margin:0; color:var(--muted); line-height:1.6; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
        .btn { display:inline-block; text-decoration:none; padding:12px 16px; border-radius:14px; font-weight:700; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-soft { background:var(--soft); color:var(--accent2); border:1px solid #fed7aa; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-top:20px; }
        .card { background:var(--paper); border:1px solid var(--line); border-radius:22px; padding:18px; }
        .card small { display:block; color:var(--muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:.05em; }
        .card strong { font-size:1.35rem; color:var(--accent2); }
        .section { margin-top:22px; display:grid; grid-template-columns:1.05fr .95fr; gap:18px; }
        .stack { display:grid; gap:18px; }
        .meta { display:grid; gap:12px; }
        .meta-item { padding:14px 16px; border:1px solid #eee4d6; border-radius:18px; background:#fff; }
        .meta-item strong { display:block; margin-bottom:6px; font-size:.9rem; color:var(--muted); }
        .table-wrap { overflow:auto; margin-top:12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 10px; border-bottom:1px solid #eee4d6; text-align:left; font-size:.94rem; vertical-align:top; }
        th { color:var(--accent2); text-transform:uppercase; font-size:.78rem; letter-spacing:.05em; }
        @media (max-width:960px) { .section { grid-template-columns:1fr; } .hero-top { flex-direction:column; } }
    </style>
</head>
<body>
    <div class="shell">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <h1><?= app_h($title) ?></h1>
                    <p><?= $type === 'link' ? 'Odeme linki, musteri bilgisi ve bagli POS islemleri tek ekranda.' : ($type === 'transaction' ? 'POS islemi, cari etkisi, banka etkisi ve bagli belge akisi tek ekranda.' : 'Webhook bildirimi, ham veri ve bagli POS sonucu tek ekranda.') ?></p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=tahsilat">Tahsilat Listeye Don</a>
                    <?php if ($type === 'link'): ?>
                        <a class="btn btn-soft" href="payment.php?code=<?= urlencode((string) $header['link_code']) ?>" target="_blank">Musteri Ekrani</a>
                    <?php endif; ?>
                    <a class="btn btn-soft" href="<?= app_h($docUrl) ?>">Hizli Evrak Yukle</a>
                </div>
            </div>
            <?php if ($type === 'webhook' && !empty($header['transaction_id']) && in_array((string) $header['verification_status'], ['bekliyor', 'hatali'], true)): ?>
                <form method="post" action="index.php?module=tahsilat" class="actions" style="margin-top:18px;">
                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="retry_webhook_log">
                    <input type="hidden" name="webhook_log_id" value="<?= (int) $header['id'] ?>">
                    <input name="bank_account_id" placeholder="Banka ID (basarili bildirimde gerekebilir)" style="padding:12px;border:1px solid var(--line);border-radius:14px;">
                    <button class="btn btn-primary" type="submit" style="border:0;cursor:pointer;">Webhook Tekrar Isle</button>
                </form>
            <?php endif; ?>
            <?php if ($type === 'webhook' && empty($header['resolved_at'])): ?>
                <form method="post" action="index.php?module=tahsilat" class="actions" style="margin-top:12px;">
                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                    <input type="hidden" name="action" value="resolve_webhook_log">
                    <input type="hidden" name="webhook_log_id" value="<?= (int) $header['id'] ?>">
                    <input name="resolution_note" placeholder="Cozum notu" style="padding:12px;border:1px solid var(--line);border-radius:14px;min-width:260px;">
                    <button class="btn btn-soft" type="submit" style="cursor:pointer;">Alarmi Kapat</button>
                </form>
            <?php endif; ?>
            <div class="grid">
                <?php foreach ($summary as $label => $value): ?>
                    <div class="card">
                        <small><?= app_h($label) ?></small>
                        <strong><?= app_h($value) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section">
            <div class="stack">
                <div class="card">
                    <h3><?= $type === 'link' ? 'Link Ozeti' : ($type === 'transaction' ? 'Islem Ozeti' : 'Webhook Ozeti') ?></h3>
                    <div class="meta">
                        <?php foreach ($infoRows as $label => $value): ?>
                            <div class="meta-item"><strong><?= app_h($label) ?></strong><?= app_h($value) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($type === 'link'): ?>
                    <div class="card">
                        <h3>Bagli POS Islemleri</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Ref</th><th>Durum</th><th>Tutar</th><th>Taksit</th><th>Komisyon</th><th>Islem Zamani</th></tr></thead>
                                <tbody>
                                <?php foreach ($transactions as $item): ?>
                                    <tr>
                                        <td><?= app_h((string) ($item['transaction_ref'] ?: ('POS#' . $item['id']))) ?></td>
                                        <td><?= app_h($item['status']) ?></td>
                                        <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.')) ?></td>
                                        <td><?= (int) ($item['installment_count'] ?? 1) ?></td>
                                        <td><?= app_h(number_format((float) ($item['commission_amount'] ?? 0), 2, ',', '.') . ' / Net ' . number_format((float) ($item['net_amount'] ?? $item['amount']), 2, ',', '.')) ?></td>
                                        <td><?= app_h((string) ($item['processed_at'] ?: '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php elseif ($type === 'transaction'): ?>
                    <div class="card">
                        <h3>Cari Etkisi</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Tarih</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                                <tbody>
                                <?php foreach ($cariEffects as $item): ?>
                                    <tr>
                                        <td><?= app_h($item['movement_date']) ?></td>
                                        <td><?= app_h($item['movement_type']) ?></td>
                                        <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.') . ' ' . ($item['currency_code'] ?: 'TRY')) ?></td>
                                        <td><?= app_h((string) ($item['description'] ?: '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <h3>Banka Etkisi</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Tarih</th><th>Banka</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                                <tbody>
                                <?php foreach ($bankEffects as $item): ?>
                                    <tr>
                                        <td><?= app_h($item['movement_date']) ?></td>
                                        <td><?= app_h((string) (($item['bank_name'] ?: '-') . ' / ' . ($item['account_name'] ?: '-'))) ?></td>
                                        <td><?= app_h($item['movement_type']) ?></td>
                                        <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.')) ?></td>
                                        <td><?= app_h((string) ($item['description'] ?: '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <h3>Komisyon Gideri</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Tarih</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                                <tbody>
                                <?php foreach ($commissionExpenses as $item): ?>
                                    <tr>
                                        <td><?= app_h($item['expense_date']) ?></td>
                                        <td><?= app_h($item['expense_type']) ?></td>
                                        <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.')) ?></td>
                                        <td><?= app_h((string) ($item['description'] ?: '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <h3>Payload</h3>
                        <pre style="white-space:pre-wrap;word-break:break-word;background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:14px;max-height:420px;overflow:auto;"><?= app_h($payloadPreview !== '' ? $payloadPreview : '-') ?></pre>
                    </div>

                    <div class="card">
                        <h3>Raw Body</h3>
                        <pre style="white-space:pre-wrap;word-break:break-word;background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:14px;max-height:260px;overflow:auto;"><?= app_h($rawBodyPreview !== '' ? $rawBodyPreview : '-') ?></pre>
                    </div>

                    <div class="card">
                        <h3>Alarm Bildirimi</h3>
                        <?php if (!empty($header['notification_id'])): ?>
                            <p><strong>#<?= (int) $header['notification_id'] ?></strong> / <?= app_h((string) ($header['notification_status'] ?: '-')) ?></p>
                            <p><?= app_h((string) ($header['notification_subject'] ?: 'Webhook uyarisi')) ?></p>
                            <p>Alici: <?= app_h((string) ($header['notification_recipient'] ?: '-')) ?></p>
                            <a href="index.php?module=bildirim&search=<?= urlencode((string) $header['notification_id']) ?>">Bildirim merkezinde ac</a>
                        <?php else: ?>
                            <p>Bu webhook logu icin alarm bildirimi olusmamis.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Bagli Evraklar</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Dosya</th><th>Tur</th><th>Tarih</th><th>Islem</th></tr></thead>
                            <tbody>
                            <?php foreach ($docs as $doc): ?>
                                <tr>
                                    <td><?= app_h($doc['file_name']) ?></td>
                                    <td><?= app_h((string) ($doc['file_type'] ?: '-')) ?></td>
                                    <td><?= app_h($doc['created_at']) ?></td>
                                    <td><a href="<?= app_h(app_doc_view_url((int) $doc['id'])) ?>" target="_blank">Ac</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
