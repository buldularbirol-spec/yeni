<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$contractId = (int) ($_GET['id'] ?? 0);
if ($contractId <= 0) {
    http_response_code(400);
    exit('Gecerli bir kira sozlesmesi secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT
        r.*,
        c.company_name,
        c.full_name,
        c.phone AS cari_phone,
        c.email AS cari_email,
        d.device_name,
        d.serial_no,
        d.status AS device_status,
        d.location_text,
        cat.name AS category_name
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    LEFT JOIN rental_device_categories cat ON cat.id = d.category_id
    WHERE r.id = :id
    LIMIT 1
', ['id' => $contractId]);

if (!$rows) {
    http_response_code(404);
    exit('Kira sozlesmesi bulunamadi.');
}

$contract = $rows[0];
$contractTitle = (string) ($contract['contract_no'] ?: ('Kira #' . $contractId));
$cariName = (string) ($contract['company_name'] ?: $contract['full_name'] ?: '-');

$payments = app_fetch_all($db, '
    SELECT id, due_date, amount, status, paid_at
    FROM rental_payments
    WHERE contract_id = :contract_id
    ORDER BY id DESC
    LIMIT 24
', ['contract_id' => $contractId]);

$logs = app_fetch_all($db, '
    SELECT id, event_type, event_date, description
    FROM rental_service_logs
    WHERE device_id = :device_id
    ORDER BY id DESC
    LIMIT 24
', ['device_id' => (int) $contract['device_id']]);

$cariMovements = app_fetch_all($db, '
    SELECT movement_type, amount, description, movement_date
    FROM cari_movements
    WHERE source_module = :source_module
      AND source_table IN ("rental_contracts", "rental_payments")
      AND cari_id = :cari_id
      AND (source_id = :contract_id OR source_table = "rental_payments")
    ORDER BY id DESC
    LIMIT 24
', [
    'source_module' => 'kira',
    'cari_id' => (int) $contract['cari_id'],
    'contract_id' => $contractId,
]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'kira',
    'related_table' => 'rental_contracts',
    'related_id' => $contractId,
]);

$summary = [
    'Durum' => (string) ($contract['status'] ?: '-'),
    'Aylik Kira' => number_format((float) ($contract['monthly_rent'] ?? 0), 2, ',', '.'),
    'Depozito' => number_format((float) ($contract['deposit_amount'] ?? 0), 2, ',', '.'),
    'Odeme' => (string) count($payments),
    'Olay' => (string) count($logs),
    'Evrak' => (string) count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($contractTitle) ?> | Kira Detay</title>
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
                    <h1><?= app_h($contractTitle) ?></h1>
                    <p><?= app_h($cariName) ?> ile yapilan kira sozlesmesi. Cihaz, odeme plani, tahsilat hareketi ve evraklar tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=kira">Kira Listeye Don</a>
                    <a class="btn btn-soft" href="print.php?type=rental&id=<?= $contractId ?>" target="_blank">PDF / Yazdir</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('kira', 'rental_contracts', $contractId, 'rental_detail.php?id=' . $contractId)) ?>">Hizli Evrak Yukle</a>
                </div>
            </div>

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
                    <h3>Sozlesme Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Cari</strong><?= app_h($cariName) ?></div>
                        <div class="meta-item"><strong>Cihaz</strong><?= app_h((string) (($contract['device_name'] ?: '-') . ' / ' . ($contract['serial_no'] ?: '-'))) ?></div>
                        <div class="meta-item"><strong>Kategori</strong><?= app_h((string) ($contract['category_name'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Baslangic</strong><?= app_h((string) ($contract['start_date'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Bitis</strong><?= app_h((string) ($contract['end_date'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Faturalama Gunu</strong><?= app_h((string) ($contract['billing_day'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Odeme Plani</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Vade</th><th>Tutar</th><th>Durum</th><th>Odeme</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= app_h($payment['due_date']) ?></td>
                                    <td><?= app_h(number_format((float) $payment['amount'], 2, ',', '.')) ?></td>
                                    <td><?= app_h($payment['status']) ?></td>
                                    <td><?= app_h((string) ($payment['paid_at'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Kira Cari Hareketleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                            <tbody>
                            <?php foreach ($cariMovements as $item): ?>
                                <tr>
                                    <td><?= app_h($item['movement_date']) ?></td>
                                    <td><?= app_h($item['movement_type']) ?></td>
                                    <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['description'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Cihaz ve Cari Bilgileri</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($contract['cari_phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($contract['cari_email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Cihaz Durumu</strong><?= app_h((string) ($contract['device_status'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Lokasyon</strong><?= app_h((string) ($contract['location_text'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Notlar</strong><?= app_h((string) ($contract['notes'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Cihaz Olay Gecmisi</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Olay</th><th>Aciklama</th></tr></thead>
                            <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= app_h($log['event_date']) ?></td>
                                    <td><?= app_h($log['event_type']) ?></td>
                                    <td><?= app_h($log['description']) ?></td>
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
