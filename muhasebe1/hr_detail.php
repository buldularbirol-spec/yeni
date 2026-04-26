<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$employeeId = (int) ($_GET['id'] ?? 0);
if ($employeeId <= 0) {
    http_response_code(400);
    exit('Gecerli bir personel secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT e.*, d.name AS department_name, u.full_name AS user_name, u.email AS user_email
    FROM hr_employees e
    LEFT JOIN hr_departments d ON d.id = e.department_id
    LEFT JOIN core_users u ON u.id = e.user_id
    WHERE e.id = :id
    LIMIT 1
', ['id' => $employeeId]);

if (!$rows) {
    http_response_code(404);
    exit('Personel bulunamadi.');
}

$employee = $rows[0];
$title = (string) ($employee['full_name'] ?: ('Personel #' . $employeeId));

$shifts = app_fetch_all($db, '
    SELECT shift_date, start_time, end_time, status
    FROM hr_shifts
    WHERE employee_id = :employee_id
    ORDER BY id DESC
    LIMIT 24
', ['employee_id' => $employeeId]);

$assignments = app_fetch_all($db, '
    SELECT assignment_type, description, assigned_at
    FROM hr_assignments
    WHERE employee_id = :employee_id
    ORDER BY id DESC
    LIMIT 24
', ['employee_id' => $employeeId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'ik',
    'related_table' => 'hr_employees',
    'related_id' => $employeeId,
]);

$summary = [
    'Departman' => (string) ($employee['department_name'] ?: '-'),
    'Unvan' => (string) ($employee['title'] ?: '-'),
    'Durum' => (int) ($employee['status'] ?? 0) === 1 ? 'Aktif' : 'Pasif',
    'Vardiya' => (string) count($shifts),
    'Zimmet' => (string) count($assignments),
    'Evrak' => (string) count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | Personel Detay</title>
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
                    <p>Personel karti, vardiya, zimmet ve belge akisi tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=ik">IK Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('ik', 'hr_employees', $employeeId, 'hr_detail.php?id=' . $employeeId)) ?>">Hizli Evrak Yukle</a>
                    <a class="btn btn-soft" href="index.php?module=evrak&filter_module=ik&filter_related_table=hr_employees&filter_related_id=<?= $employeeId ?>&prefill_module=ik&prefill_related_table=hr_employees&prefill_related_id=<?= $employeeId ?>">Arsivi Ac</a>
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
                    <h3>Personel Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Departman</strong><?= app_h((string) ($employee['department_name'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Unvan</strong><?= app_h((string) ($employee['title'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($employee['phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($employee['email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Baslama Tarihi</strong><?= app_h((string) ($employee['start_date'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Kullanici Hesabi</strong><?= app_h((string) ($employee['user_name'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Vardiya Gecmisi</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Saat</th><th>Durum</th></tr></thead>
                            <tbody>
                            <?php foreach ($shifts as $shift): ?>
                                <tr>
                                    <td><?= app_h($shift['shift_date']) ?></td>
                                    <td><?= app_h((string) (($shift['start_time'] ?: '-') . ' / ' . ($shift['end_time'] ?: '-'))) ?></td>
                                    <td><?= app_h((string) ($shift['status'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Zimmet ve Gorevler</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tip</th><th>Aciklama</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?= app_h($assignment['assignment_type']) ?></td>
                                    <td><?= app_h((string) ($assignment['description'] ?: '-')) ?></td>
                                    <td><?= app_h($assignment['assigned_at']) ?></td>
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
