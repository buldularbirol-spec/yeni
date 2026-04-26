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
    SELECT id, company_name, full_name, phone, email, city, notes
    FROM cari_cards
    WHERE id = :id
    LIMIT 1
', ['id' => $cariId]);

if (!$rows) {
    http_response_code(404);
    exit('Cari kaydi bulunamadi.');
}

$cari = $rows[0];
$title = (string) ($cari['company_name'] ?: $cari['full_name'] ?: ('Cari #' . $cariId));

$notes = app_fetch_all($db, '
    SELECT n.note_text, n.created_at, u.full_name AS created_by_name
    FROM crm_notes n
    LEFT JOIN core_users u ON u.id = n.created_by
    WHERE n.cari_id = :cari_id
    ORDER BY n.id DESC
    LIMIT 30
', ['cari_id' => $cariId]);

$reminders = app_fetch_all($db, '
    SELECT reminder_text, remind_at, status, created_at
    FROM crm_reminders
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 30
', ['cari_id' => $cariId]);

$opportunities = app_fetch_all($db, '
    SELECT title, stage, amount, expected_close_date, created_at
    FROM crm_opportunities
    WHERE cari_id = :cari_id
    ORDER BY id DESC
    LIMIT 30
', ['cari_id' => $cariId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'crm',
    'related_table' => 'cari_cards',
    'related_id' => $cariId,
]);

$summary = [
    'CRM Notu' => (string) count($notes),
    'Hatirlatma' => (string) count($reminders),
    'Firsat' => (string) count($opportunities),
    'Firsat Toplami' => number_format((float) array_sum(array_map(static fn ($row) => (float) ($row['amount'] ?? 0), $opportunities)), 2, ',', '.'),
    'Evrak' => (string) count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | CRM Detay</title>
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
                    <p>CRM notlari, hatirlatmalar, firsatlar ve bagli belgeler tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=crm">CRM Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('crm', 'cari_cards', $cariId, 'crm_detail.php?id=' . $cariId)) ?>">Hizli Evrak Yukle</a>
                    <a class="btn btn-soft" href="index.php?module=evrak&filter_module=crm&filter_related_table=cari_cards&filter_related_id=<?= $cariId ?>&prefill_module=crm&prefill_related_table=cari_cards&prefill_related_id=<?= $cariId ?>">Arsivi Ac</a>
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
                    <h3>Musteri Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($cari['phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($cari['email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Sehir</strong><?= app_h((string) ($cari['city'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Cari Notu</strong><?= app_h((string) ($cari['notes'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>CRM Notlari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Not</th><th>Olusturan</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($notes as $item): ?>
                                <tr>
                                    <td><?= app_h($item['note_text']) ?></td>
                                    <td><?= app_h((string) ($item['created_by_name'] ?: '-')) ?></td>
                                    <td><?= app_h($item['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Hatirlatmalar</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Hatirlatma</th><th>Zaman</th><th>Durum</th></tr></thead>
                            <tbody>
                            <?php foreach ($reminders as $item): ?>
                                <tr>
                                    <td><?= app_h($item['reminder_text']) ?></td>
                                    <td><?= app_h($item['remind_at']) ?></td>
                                    <td><?= app_h($item['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Firsatlar</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Baslik</th><th>Asama</th><th>Tutar</th><th>Kapanis</th></tr></thead>
                            <tbody>
                            <?php foreach ($opportunities as $item): ?>
                                <tr>
                                    <td><?= app_h($item['title']) ?></td>
                                    <td><?= app_h($item['stage']) ?></td>
                                    <td><?= app_h(number_format((float) $item['amount'], 2, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['expected_close_date'] ?: '-')) ?></td>
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
