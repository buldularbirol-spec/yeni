<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$cariId = (int) ($_GET['id'] ?? 0);
if ($cariId <= 0) {
    http_response_code(400);
    exit('Gecerli bir cari secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT id, card_type, is_company, company_name, full_name, phone, email, city, risk_limit, notes, created_at
    FROM cari_cards
    WHERE id = :id
    LIMIT 1
', ['id' => $cariId]);

if (!$rows) {
    http_response_code(404);
    exit('Cari kaydi bulunamadi.');
}

$cari = $rows[0];
$cariName = (string) ($cari['company_name'] ?: $cari['full_name'] ?: ('Cari #' . (int) $cari['id']));

$balance = app_fetch_all($db, "
    SELECT
        COALESCE(SUM(CASE WHEN movement_type = 'borc' THEN amount ELSE 0 END), 0) AS borc_total,
        COALESCE(SUM(CASE WHEN movement_type = 'alacak' THEN amount ELSE 0 END), 0) AS alacak_total,
        COALESCE(SUM(CASE WHEN movement_type = 'borc' THEN amount ELSE -amount END), 0) AS bakiye
    FROM cari_movements
    WHERE cari_id = :cari_id
", ['cari_id' => $cariId])[0] ?? ['borc_total' => 0, 'alacak_total' => 0, 'bakiye' => 0];

$ledger = app_fetch_all($db, '
    SELECT movement_type, source_module, source_table, amount, currency_code, description, movement_date
    FROM cari_movements
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 25
', ['cari_id' => $cariId]);

$invoices = app_fetch_all($db, '
    SELECT id, invoice_no, invoice_type, invoice_date, due_date, grand_total, edocument_status
    FROM invoice_headers
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 12
', ['cari_id' => $cariId]);

$salesOrders = app_fetch_all($db, '
    SELECT id, order_no, order_date, status, grand_total
    FROM sales_orders
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 12
', ['cari_id' => $cariId]);

$collections = app_fetch_all($db, '
    SELECT t.id, t.amount, t.status, t.transaction_ref, t.processed_at, l.link_code
    FROM collections_transactions t
    LEFT JOIN collections_links l ON l.id = t.link_id
    WHERE t.cari_id = :cari_id
    ORDER BY t.id DESC
    LIMIT 12
', ['cari_id' => $cariId]);

$rentals = app_fetch_all($db, '
    SELECT id, contract_no, start_date, end_date, monthly_rent, status
    FROM rental_contracts
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 12
', ['cari_id' => $cariId]);

$services = app_fetch_all($db, '
    SELECT id, service_no, complaint, cost_total, opened_at, closed_at
    FROM service_records
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 12
', ['cari_id' => $cariId]);

$crmItems = app_fetch_all($db, '
    SELECT "Not" AS crm_type, note_text AS detail_text, created_at AS event_date
    FROM crm_notes
    WHERE cari_id = :cari_id
    UNION ALL
    SELECT "Hatirlatma" AS crm_type, reminder_text AS detail_text, remind_at AS event_date
    FROM crm_reminders
    WHERE cari_id = :cari_id
    UNION ALL
    SELECT "Firsat" AS crm_type, CONCAT(title, " / ", stage) AS detail_text, expected_close_date AS event_date
    FROM crm_opportunities
    WHERE cari_id = :cari_id
    ORDER BY event_date DESC
    LIMIT 15
', ['cari_id' => $cariId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'cari',
    'related_table' => 'cari_cards',
    'related_id' => $cariId,
]);

$summary = [
    'Borc' => number_format((float) $balance['borc_total'], 2, ',', '.'),
    'Alacak' => number_format((float) $balance['alacak_total'], 2, ',', '.'),
    'Bakiye' => number_format((float) $balance['bakiye'], 2, ',', '.'),
    'Fatura' => count($invoices),
    'Siparis' => count($salesOrders),
    'Tahsilat' => count($collections),
    'Servis' => count($services),
    'Evrak' => count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($cariName) ?> | Cari Detay</title>
    <style>
        :root { --paper:rgba(255,255,255,.95); --ink:#1f2937; --muted:#667085; --line:#eadfce; --accent:#c2410c; --accent2:#7c2d12; --soft:#fff1df; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at top left,rgba(250,204,21,.25),transparent 20rem),linear-gradient(145deg,#faf6ee,#f3eadc); }
        .shell { width:min(1400px,100% - 36px); margin:24px auto 40px; }
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
        .card strong { font-size:1.5rem; color:var(--accent2); }
        .section { margin-top:22px; display:grid; grid-template-columns:1.15fr .85fr; gap:18px; }
        .stack { display:grid; gap:18px; }
        .table-wrap { overflow:auto; margin-top:12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:12px 10px; border-bottom:1px solid #eee4d6; text-align:left; font-size:.94rem; vertical-align:top; }
        th { color:var(--accent2); text-transform:uppercase; font-size:.78rem; letter-spacing:.05em; }
        .meta { display:grid; gap:12px; }
        .meta-item { padding:14px 16px; border:1px solid #eee4d6; border-radius:18px; background:#fff; }
        .meta-item strong { display:block; margin-bottom:6px; font-size:.9rem; color:var(--muted); }
        @media (max-width:960px) { .section { grid-template-columns:1fr; } .hero-top { flex-direction:column; } }
    </style>
</head>
<body>
    <div class="shell">
        <section class="hero">
            <div class="hero-top">
                <div>
                    <h1><?= app_h($cariName) ?></h1>
                    <p><?= app_h((string) $cari['card_type']) ?> cari karti. Telefon, bakiye, hareket, belge ve operasyon kayitlari tek ekranda toplandi.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=cari">Cari Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('cari', 'cari_cards', $cariId, 'cari_detail.php?id=' . $cariId)) ?>">Hizli Evrak Yukle</a>
                    <a class="btn btn-soft" href="index.php?module=evrak&filter_module=cari&filter_related_table=cari_cards&filter_related_id=<?= $cariId ?>&prefill_module=cari&prefill_related_table=cari_cards&prefill_related_id=<?= $cariId ?>">Arsivi Ac</a>
                </div>
            </div>

            <div class="grid">
                <?php foreach ($summary as $label => $value): ?>
                    <div class="card">
                        <small><?= app_h($label) ?></small>
                        <strong><?= app_h((string) $value) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section">
            <div class="stack">
                <div class="card">
                    <h3>Cari Ekstresi</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Tur</th><th>Kaynak</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                            <tbody>
                            <?php foreach ($ledger as $item): ?>
                                <tr>
                                    <td><?= app_h($item['movement_date']) ?></td>
                                    <td><?= app_h($item['movement_type']) ?></td>
                                    <td><?= app_h($item['source_module'] . ' / ' . $item['source_table']) ?></td>
                                    <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.') . ' ' . ($item['currency_code'] ?: 'TRY')) ?></td>
                                    <td><?= app_h((string) ($item['description'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Fatura ve Siparisler</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>No</th><th>Tarih</th><th>Durum</th><th>Tutar</th></tr></thead>
                            <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>Fatura</td>
                                    <td><?= app_h($invoice['invoice_no']) ?></td>
                                    <td><?= app_h($invoice['invoice_date']) ?></td>
                                    <td><?= app_h((string) ($invoice['edocument_status'] ?: $invoice['invoice_type'])) ?></td>
                                    <td><?= app_h(number_format((float) $invoice['grand_total'], 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($salesOrders as $order): ?>
                                <tr>
                                    <td>Siparis</td>
                                    <td><?= app_h($order['order_no']) ?></td>
                                    <td><?= app_h($order['order_date']) ?></td>
                                    <td><?= app_h($order['status']) ?></td>
                                    <td><?= app_h(number_format((float) $order['grand_total'], 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Tahsilat ve Sozlesmeler</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>Ref</th><th>Durum</th><th>Tutar</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($collections as $collection): ?>
                                <tr>
                                    <td>Tahsilat</td>
                                    <td><?= app_h((string) ($collection['link_code'] ?: $collection['transaction_ref'] ?: ('POS#' . $collection['id']))) ?></td>
                                    <td><?= app_h($collection['status']) ?></td>
                                    <td><?= app_h(number_format((float) $collection['amount'], 2, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($collection['processed_at'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($rentals as $rental): ?>
                                <tr>
                                    <td>Kira</td>
                                    <td><?= app_h($rental['contract_no']) ?></td>
                                    <td><?= app_h($rental['status']) ?></td>
                                    <td><?= app_h(number_format((float) $rental['monthly_rent'], 2, ',', '.')) ?></td>
                                    <td><?= app_h($rental['start_date']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Kart Bilgileri</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($cari['phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($cari['email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Sehir</strong><?= app_h((string) ($cari['city'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Risk Limiti</strong><?= app_h(number_format((float) $cari['risk_limit'], 2, ',', '.')) ?></div>
                        <div class="meta-item"><strong>Olusturma</strong><?= app_h((string) $cari['created_at']) ?></div>
                        <div class="meta-item"><strong>Notlar</strong><?= app_h((string) ($cari['notes'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis ve CRM</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>Detay</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($services as $service): ?>
                                <tr>
                                    <td>Servis</td>
                                    <td><?= app_h($service['service_no'] . ' / ' . $service['complaint']) ?></td>
                                    <td><?= app_h((string) ($service['closed_at'] ?: $service['opened_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($crmItems as $crm): ?>
                                <tr>
                                    <td><?= app_h($crm['crm_type']) ?></td>
                                    <td><?= app_h((string) $crm['detail_text']) ?></td>
                                    <td><?= app_h((string) ($crm['event_date'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

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
