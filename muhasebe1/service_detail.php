<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$serviceId = (int) ($_GET['id'] ?? 0);
if ($serviceId <= 0) {
    http_response_code(400);
    exit('Gecerli bir servis kaydi secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT
        s.*,
        c.company_name,
        c.full_name,
        c.phone AS cari_phone,
        c.email AS cari_email,
        p.name AS product_name,
        p.sku AS product_sku,
        f.name AS fault_name,
        st.name AS status_name,
        st.is_closed,
        u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN stock_products p ON p.id = s.product_id
    LEFT JOIN service_fault_types f ON f.id = s.fault_type_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    WHERE s.id = :id
    LIMIT 1
', ['id' => $serviceId]);

if (!$rows) {
    http_response_code(404);
    exit('Servis kaydi bulunamadi.');
}

$service = $rows[0];
$serviceTitle = (string) ($service['service_no'] ?: ('Servis #' . $serviceId));
$cariName = (string) ($service['company_name'] ?: $service['full_name'] ?: '-');

$notes = app_fetch_all($db, '
    SELECT n.id, n.note_text, n.is_customer_visible, n.created_at, u.full_name AS created_name
    FROM service_notes n
    LEFT JOIN core_users u ON u.id = n.created_by
    WHERE n.service_record_id = :service_record_id
    ORDER BY n.id DESC
    LIMIT 20
', ['service_record_id' => $serviceId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'servis',
    'related_table' => 'service_records',
    'related_id' => $serviceId,
]);

$appointments = app_fetch_all($db, '
    SELECT a.id, a.appointment_at, a.appointment_type, a.location_text, a.status, a.notes, u.full_name AS assigned_name
    FROM service_appointments a
    LEFT JOIN core_users u ON u.id = a.assigned_user_id
    WHERE a.service_record_id = :service_record_id
    ORDER BY a.appointment_at DESC, a.id DESC
', ['service_record_id' => $serviceId]);

$steps = app_fetch_all($db, '
    SELECT stp.id, stp.step_name, stp.status, stp.sort_order, stp.started_at, stp.completed_at, stp.notes, u.full_name AS assigned_name
    FROM service_steps stp
    LEFT JOIN core_users u ON u.id = stp.assigned_user_id
    WHERE stp.service_record_id = :service_record_id
    ORDER BY stp.sort_order ASC, stp.id ASC
', ['service_record_id' => $serviceId]);

$parts = app_fetch_all($db, '
    SELECT sp.id, sp.quantity, sp.unit_cost, (sp.quantity * sp.unit_cost) AS line_total, sp.used_at, sp.notes,
           p.sku, p.name AS product_name, p.unit
    FROM service_parts sp
    INNER JOIN stock_products p ON p.id = sp.product_id
    WHERE sp.service_record_id = :service_record_id
    ORDER BY sp.id DESC
', ['service_record_id' => $serviceId]);

$photos = app_fetch_all($db, '
    SELECT ph.id, ph.file_name, ph.file_path, ph.caption, ph.created_at, u.full_name AS uploaded_name
    FROM service_photos ph
    LEFT JOIN core_users u ON u.id = ph.uploaded_by
    WHERE ph.service_record_id = :service_record_id
    ORDER BY ph.id DESC
', ['service_record_id' => $serviceId]);

$partTotal = 0.0;
foreach ($parts as $part) {
    $partTotal += (float) $part['line_total'];
}
$laborCost = (float) ($service['labor_cost'] ?? 0);
$externalCost = (float) ($service['external_cost'] ?? 0);
$serviceRevenue = (float) ($service['service_revenue'] ?? 0);
$analysisTotalCost = $partTotal + $laborCost + $externalCost;
$analysisProfit = $serviceRevenue - $analysisTotalCost;
$warrantyDaysLeft = null;
if (!empty($service['warranty_end_date'])) {
    $warrantyDaysLeft = (int) floor((strtotime((string) $service['warranty_end_date']) - strtotime(date('Y-m-d'))) / 86400);
}
$warrantyDayLabel = $warrantyDaysLeft === null ? '-' : ($warrantyDaysLeft < 0 ? 'Suresi doldu' : $warrantyDaysLeft . ' gun');
$slaResponseLabel = '-';
if (!empty($service['sla_response_due_at'])) {
    $slaResponseMinutes = (int) floor((strtotime((string) $service['sla_response_due_at']) - time()) / 60);
    $slaResponseLabel = $slaResponseMinutes < 0 ? 'Gecikti' : 'Takipte';
}
$slaResolutionLabel = '-';
if (!empty($service['sla_resolution_due_at'])) {
    $slaResolutionMinutes = (int) floor((strtotime((string) $service['sla_resolution_due_at']) - time()) / 60);
    $slaResolutionLabel = $slaResolutionMinutes < 0 && empty($service['sla_resolved_at']) ? 'Gecikti' : 'Takipte';
}

$summary = [
    'Durum' => (string) ($service['status_name'] ?: '-'),
    'Maliyet' => number_format($analysisTotalCost, 2, ',', '.'),
    'Kar/Zarar' => number_format($analysisProfit, 2, ',', '.'),
    'Not' => (string) count($notes),
    'Evrak' => (string) count($docs),
    'Randevu' => (string) count($appointments),
    'Adim' => (string) count($steps),
    'Parca' => (string) count($parts),
    'Onay' => (string) (($service['customer_approval_status'] ?? '') ?: 'bekliyor'),
    'Fotograf' => (string) count($photos),
    'Garanti' => (string) (($service['warranty_status'] ?: '-') . ' / ' . $warrantyDayLabel),
    'SLA' => (string) (($service['sla_status'] ?? '') ?: $slaResolutionLabel),
    'Personel' => (string) ($service['assigned_name'] ?: '-'),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($serviceTitle) ?> | Servis Detay</title>
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
        .photo-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-top:12px; }
        .photo-card { border:1px solid #eee4d6; border-radius:18px; overflow:hidden; background:#fff; }
        .photo-card img { width:100%; height:130px; object-fit:cover; display:block; }
        .photo-card div { padding:10px; }
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
                    <h1><?= app_h($serviceTitle) ?></h1>
                    <p><?= app_h($cariName) ?> icin acilan servis kaydi. Ariza, teshis, notlar ve bagli belgeler tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=servis">Servis Listeye Don</a>
                    <a class="btn btn-soft" href="print.php?type=service&id=<?= $serviceId ?>" target="_blank">PDF / Yazdir</a>
                    <a class="btn btn-soft" href="print.php?type=service_acceptance&id=<?= $serviceId ?>" target="_blank">Kabul Formu</a>
                    <a class="btn btn-soft" href="print.php?type=service_delivery&id=<?= $serviceId ?>" target="_blank">Teslim Formu</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('servis', 'service_records', $serviceId, 'service_detail.php?id=' . $serviceId)) ?>">Hizli Evrak Yukle</a>
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
                    <h3>Servis Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Cari</strong><?= app_h($cariName) ?></div>
                        <div class="meta-item"><strong>Urun</strong><?= app_h((string) (($service['product_name'] ?: '-') . (($service['product_sku'] ?? '') !== '' ? ' / ' . $service['product_sku'] : ''))) ?></div>
                        <div class="meta-item"><strong>Ariza Tipi</strong><?= app_h((string) ($service['fault_name'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Seri No</strong><?= app_h((string) ($service['serial_no'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Acilis</strong><?= app_h((string) ($service['opened_at'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Kapanis</strong><?= app_h((string) ($service['closed_at'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Ariza ve Teshis</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Sikayet / Ariza</strong><?= app_h((string) ($service['complaint'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teshis</strong><?= app_h((string) ($service['diagnosis'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Kabul Bilgileri</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Teslim Alma Tipi</strong><?= app_h((string) ($service['acceptance_type'] ?? '-')) ?></div>
                        <div class="meta-item"><strong>Teslim Alan</strong><?= app_h((string) (($service['received_by'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Tahmini Teslim</strong><?= app_h((string) (($service['estimated_delivery_date'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teslim Eden / Imza</strong><?= app_h((string) (($service['acceptance_signed_by'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Aksesuarlar</strong><?= app_h((string) (($service['received_accessories'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Fiziksel Durum</strong><?= app_h((string) (($service['device_condition'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Musteri Onay Notu</strong><?= app_h((string) (($service['customer_approval_note'] ?? '') ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Garanti Takibi</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Garanti Durumu</strong><?= app_h((string) ($service['warranty_status'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Baslangic</strong><?= app_h((string) (($service['warranty_start_date'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Bitis</strong><?= app_h((string) (($service['warranty_end_date'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Kalan Sure</strong><?= app_h($warrantyDayLabel) ?></div>
                        <div class="meta-item"><strong>Saglayici</strong><?= app_h((string) (($service['warranty_provider'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Belge No</strong><?= app_h((string) (($service['warranty_document_no'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Garanti Sonucu</strong><?= app_h((string) (($service['warranty_result'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Garanti Notu</strong><?= app_h((string) (($service['warranty_notes'] ?? '') ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis SLA</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Oncelik</strong><?= app_h((string) (($service['sla_priority'] ?? '') ?: 'normal')) ?></div>
                        <div class="meta-item"><strong>SLA Durumu</strong><?= app_h((string) (($service['sla_status'] ?? '') ?: $slaResolutionLabel)) ?></div>
                        <div class="meta-item"><strong>Mudahale Hedefi</strong><?= app_h((string) (($service['sla_response_due_at'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Gercek Mudahale</strong><?= app_h((string) (($service['sla_responded_at'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Cozum Hedefi</strong><?= app_h((string) (($service['sla_resolution_due_at'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Gercek Cozum</strong><?= app_h((string) (($service['sla_resolved_at'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Mudahale Takibi</strong><?= app_h($slaResponseLabel) ?></div>
                        <div class="meta-item"><strong>Cozum Takibi</strong><?= app_h($slaResolutionLabel) ?></div>
                        <div class="meta-item"><strong>SLA Notu</strong><?= app_h((string) (($service['sla_notes'] ?? '') ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Teslim Bilgileri</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Teslim Durumu</strong><?= app_h((string) (($service['delivery_status'] ?? '') ?: 'bekliyor')) ?></div>
                        <div class="meta-item"><strong>Teslim Tarihi</strong><?= app_h((string) (($service['delivery_date'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teslim Eden</strong><?= app_h((string) (($service['delivered_by'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teslim Alan</strong><?= app_h((string) (($service['delivered_to'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teslim Notu</strong><?= app_h((string) (($service['delivery_notes'] ?? '') ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Musteri Onayi</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Onay Durumu</strong><?= app_h((string) (($service['customer_approval_status'] ?? '') ?: 'bekliyor')) ?></div>
                        <div class="meta-item"><strong>Onay Tarihi</strong><?= app_h((string) (($service['customer_approval_at'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Onaylayan</strong><?= app_h((string) (($service['customer_approved_by'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Onay Kanali</strong><?= app_h((string) (($service['customer_approval_channel'] ?? '') ?: '-')) ?></div>
                        <div class="meta-item"><strong>Onay Notu</strong><?= app_h((string) (($service['customer_approval_description'] ?? '') ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Fotograflari</h3>
                    <div class="photo-grid">
                        <?php foreach ($photos as $photo): ?>
                            <a class="photo-card" href="<?= app_h((string) $photo['file_path']) ?>" target="_blank" style="text-decoration:none;color:inherit;">
                                <img src="<?= app_h((string) $photo['file_path']) ?>" alt="<?= app_h((string) ($photo['caption'] ?: $photo['file_name'])) ?>">
                                <div>
                                    <strong><?= app_h((string) ($photo['caption'] ?: 'Servis fotografi')) ?></strong>
                                    <small style="display:block;color:var(--muted);margin-top:4px;"><?= app_h((string) $photo['created_at']) ?> / <?= app_h((string) ($photo['uploaded_name'] ?: '-')) ?></small>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!$photos): ?>
                            <p>Henuz servis fotografi yok.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Notlari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Not</th><th>Musteri</th><th>Kullanici</th></tr></thead>
                            <tbody>
                            <?php foreach ($notes as $note): ?>
                                <tr>
                                    <td><?= app_h($note['created_at']) ?></td>
                                    <td><?= app_h($note['note_text']) ?></td>
                                    <td><?= (int) $note['is_customer_visible'] === 1 ? 'Gorur' : 'Gormez' ?></td>
                                    <td><?= app_h((string) ($note['created_name'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Randevulari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Tip</th><th>Personel</th><th>Konum</th><th>Durum</th><th>Not</th></tr></thead>
                            <tbody>
                            <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td><?= app_h((string) $appointment['appointment_at']) ?></td>
                                    <td><?= app_h((string) ($appointment['appointment_type'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($appointment['assigned_name'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($appointment['location_text'] ?: '-')) ?></td>
                                    <td><?= app_h((string) $appointment['status']) ?></td>
                                    <td><?= app_h((string) ($appointment['notes'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$appointments): ?>
                                <tr><td colspan="6">Henuz servis randevusu yok.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Servis Adimlari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Sira</th><th>Adim</th><th>Teknisyen</th><th>Durum</th><th>Baslangic</th><th>Bitis</th><th>Not</th></tr></thead>
                            <tbody>
                            <?php foreach ($steps as $step): ?>
                                <tr>
                                    <td><?= (int) $step['sort_order'] ?></td>
                                    <td><?= app_h((string) $step['step_name']) ?></td>
                                    <td><?= app_h((string) ($step['assigned_name'] ?: '-')) ?></td>
                                    <td><?= app_h((string) $step['status']) ?></td>
                                    <td><?= app_h((string) ($step['started_at'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($step['completed_at'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($step['notes'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$steps): ?>
                                <tr><td colspan="7">Henuz servis adimi yok.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Kullanilan Parcalar</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Parca</th><th>Miktar</th><th>Birim Maliyet</th><th>Tutar</th><th>Tarih</th><th>Not</th></tr></thead>
                            <tbody>
                            <?php foreach ($parts as $part): ?>
                                <tr>
                                    <td><?= app_h((string) $part['product_name']) ?><br><small><?= app_h((string) ($part['sku'] ?: '-')) ?></small></td>
                                    <td><?= number_format((float) $part['quantity'], 3, ',', '.') ?> <?= app_h((string) ($part['unit'] ?: '')) ?></td>
                                    <td><?= number_format((float) $part['unit_cost'], 2, ',', '.') ?></td>
                                    <td><?= number_format((float) $part['line_total'], 2, ',', '.') ?></td>
                                    <td><?= app_h((string) ($part['used_at'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($part['notes'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$parts): ?>
                                <tr><td colspan="6">Henuz parca kullanimi yok.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Maliyet Analizi</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Parca Maliyeti</strong><?= number_format($partTotal, 2, ',', '.') ?></div>
                        <div class="meta-item"><strong>Iscilik Maliyeti</strong><?= number_format($laborCost, 2, ',', '.') ?></div>
                        <div class="meta-item"><strong>Dis / Ek Maliyet</strong><?= number_format($externalCost, 2, ',', '.') ?></div>
                        <div class="meta-item"><strong>Toplam Maliyet</strong><?= number_format($analysisTotalCost, 2, ',', '.') ?></div>
                        <div class="meta-item"><strong>Musteriye Yansitilan</strong><?= number_format($serviceRevenue, 2, ',', '.') ?></div>
                        <div class="meta-item"><strong>Kar / Zarar</strong><?= number_format($analysisProfit, 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Iletisim ve Operasyon</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($service['cari_phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($service['cari_email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Teknik Personel</strong><?= app_h((string) ($service['assigned_name'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Durum Kapali mi?</strong><?= (int) ($service['is_closed'] ?? 0) === 1 ? 'Evet' : 'Hayir' ?></div>
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
