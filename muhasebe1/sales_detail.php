<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$type = trim((string) ($_GET['type'] ?? 'offer'));
$id = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['offer', 'order'], true) || $id <= 0) {
    http_response_code(400);
    exit('Gecerli satis kaydi secilmedi.');
}

if ($type === 'offer') {
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');
    $rows = app_fetch_all($db, '
        SELECT o.*, c.company_name, c.full_name, c.phone AS cari_phone, c.email AS cari_email, u.full_name AS sales_user_name,
               r.rate_percent, r.target_amount
        FROM sales_offers o
        INNER JOIN cari_cards c ON c.id = o.cari_id
        LEFT JOIN core_users u ON u.id = o.sales_user_id
        LEFT JOIN sales_commission_rules r ON r.user_id = o.sales_user_id
        WHERE o.id = :id
        LIMIT 1
    ', ['id' => $id]);
    if (!$rows) {
        http_response_code(404);
        exit('Teklif bulunamadi.');
    }
    $header = $rows[0];
    $title = (string) ($header['offer_no'] ?: ('Teklif #' . $id));
    $items = app_fetch_all($db, '
        SELECT i.description, i.quantity, i.unit_price, i.line_total, p.name AS product_name, p.sku
        FROM sales_offer_items i
        LEFT JOIN stock_products p ON p.id = i.product_id
        WHERE i.offer_id = :offer_id
        ORDER BY i.id ASC
    ', ['offer_id' => $id]);
    $revisions = app_fetch_all($db, '
        SELECT r.revision_no, r.version_label, r.status, r.valid_until, r.grand_total, r.notes, r.created_at, u.full_name AS user_name
        FROM sales_offer_revisions r
        LEFT JOIN core_users u ON u.id = r.created_by
        WHERE r.offer_id = :offer_id
        ORDER BY r.revision_no DESC
    ', ['offer_id' => $id]);
    $approvals = app_fetch_all($db, '
        SELECT a.action_name, a.previous_status, a.current_status, a.note_text, a.created_at, u.full_name AS user_name
        FROM sales_offer_approval_logs a
        LEFT JOIN core_users u ON u.id = a.created_by
        WHERE a.offer_id = :offer_id
        ORDER BY a.id DESC
    ', ['offer_id' => $id]);
    $docs = app_fetch_all($db, '
        SELECT id, file_name, file_type, created_at
        FROM docs_files
        WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
        ORDER BY id DESC
        LIMIT 12
    ', ['module_name' => 'satis', 'related_table' => 'sales_offers', 'related_id' => $id]);
    $summary = [
        'Durum' => (string) ($header['status'] ?: '-'),
        'Toplam' => number_format((float) ($header['grand_total'] ?? 0), 2, ',', '.'),
        'Kalem' => (string) count($items),
        'Evrak' => (string) count($docs),
        'Versiyon' => (string) (($revisions[0]['version_label'] ?? 'v0')),
        'Onay Kaydi' => (string) count($approvals),
        'Gecerlilik' => (string) ($header['valid_until'] ?: '-'),
    ];
    $printUrl = 'print.php?type=offer&id=' . $id;
    $returnUrl = 'index.php?module=satis';
    $docUrl = app_doc_upload_url('satis', 'sales_offers', $id, 'sales_detail.php?type=offer&id=' . $id);
    $infoRows = [
        'Cari' => (string) ($header['company_name'] ?: $header['full_name'] ?: '-'),
        'Satis Temsilcisi' => (string) ($header['sales_user_name'] ?: '-'),
        'Komisyon Orani' => '%' . number_format((float) ($header['rate_percent'] ?? 0), 2, ',', '.'),
        'Aylik Hedef' => number_format((float) app_metric($db, 'SELECT COALESCE(target_amount,0) FROM sales_targets WHERE user_id = :user_id AND target_year = :target_year AND target_month = :target_month LIMIT 1', ['user_id' => (int) ($header['sales_user_id'] ?? 0), 'target_year' => $currentYear, 'target_month' => $currentMonth]), 2, ',', '.'),
        'Telefon' => (string) ($header['cari_phone'] ?: '-'),
        'E-posta' => (string) ($header['cari_email'] ?: '-'),
        'Teklif Tarihi' => (string) ($header['offer_date'] ?: '-'),
        'Notlar' => (string) ($header['notes'] ?: '-'),
    ];
} else {
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');
    $rows = app_fetch_all($db, '
        SELECT o.*, c.company_name, c.full_name, c.phone AS cari_phone, c.email AS cari_email, s.offer_no, cp.provider_name, cp.provider_code,
               u.full_name AS sales_user_name, r.rate_percent, r.target_amount
        FROM sales_orders o
        INNER JOIN cari_cards c ON c.id = o.cari_id
        LEFT JOIN sales_offers s ON s.id = o.offer_id
        LEFT JOIN sales_cargo_providers cp ON cp.id = o.cargo_provider_id
        LEFT JOIN core_users u ON u.id = o.sales_user_id
        LEFT JOIN sales_commission_rules r ON r.user_id = o.sales_user_id
        WHERE o.id = :id
        LIMIT 1
    ', ['id' => $id]);
    if (!$rows) {
        http_response_code(404);
        exit('Siparis bulunamadi.');
    }
    $header = $rows[0];
    $title = (string) ($header['order_no'] ?: ('Siparis #' . $id));
    $items = app_fetch_all($db, '
        SELECT i.description, i.quantity, i.unit_price, i.line_total, p.name AS product_name, p.sku
        FROM sales_order_items i
        LEFT JOIN stock_products p ON p.id = i.product_id
        WHERE i.order_id = :order_id
        ORDER BY i.id ASC
    ', ['order_id' => $id]);
    $stock = app_fetch_all($db, '
        SELECT m.movement_type, m.quantity, m.movement_date, w.name AS warehouse_name, p.name AS product_name
        FROM stock_movements m
        LEFT JOIN stock_warehouses w ON w.id = m.warehouse_id
        LEFT JOIN stock_products p ON p.id = m.product_id
        WHERE m.reference_type = "satis_siparis" AND m.reference_id = :reference_id
        ORDER BY m.id DESC
        LIMIT 20
    ', ['reference_id' => $id]);
    $shipments = app_fetch_all($db, '
        SELECT sh.*, w.name AS warehouse_name, cp.provider_name,
            (SELECT COUNT(*) FROM sales_shipment_invoice_links sil WHERE sil.shipment_id = sh.id) AS invoice_count
        FROM sales_shipments sh
        LEFT JOIN stock_warehouses w ON w.id = sh.warehouse_id
        LEFT JOIN sales_cargo_providers cp ON cp.id = sh.provider_id
        WHERE sh.order_id = :order_id
        ORDER BY sh.id DESC
    ', ['order_id' => $id]);
    $cargoLogs = app_fetch_all($db, '
        SELECT l.action_name, l.tracking_no, l.label_no, l.created_at, cp.provider_name, u.full_name AS user_name
        FROM sales_cargo_logs l
        LEFT JOIN sales_cargo_providers cp ON cp.id = l.provider_id
        LEFT JOIN core_users u ON u.id = l.created_by
        WHERE l.order_id = :order_id
        ORDER BY l.id DESC
    ', ['order_id' => $id]);
    $linkedInvoices = app_fetch_all($db, '
        SELECT h.id, h.invoice_no, h.invoice_date, h.grand_total, h.edocument_status
        FROM sales_order_invoice_links l
        INNER JOIN invoice_headers h ON h.id = l.invoice_id
        WHERE l.order_id = :order_id
        ORDER BY l.id DESC
    ', ['order_id' => $id]);
    $docs = app_fetch_all($db, '
        SELECT id, file_name, file_type, created_at
        FROM docs_files
        WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
        ORDER BY id DESC
        LIMIT 12
    ', ['module_name' => 'satis', 'related_table' => 'sales_orders', 'related_id' => $id]);
    $summary = [
        'Durum' => (string) ($header['status'] ?: '-'),
        'Toplam' => number_format((float) ($header['grand_total'] ?? 0), 2, ',', '.'),
        'Kalem' => (string) count($items),
        'Stok' => (string) count($stock),
        'Sevk' => (string) count($shipments),
        'Fatura' => (string) count($linkedInvoices),
        'Evrak' => (string) count($docs),
    ];
    $printUrl = 'print.php?type=order&id=' . $id;
    $returnUrl = 'index.php?module=satis';
    $docUrl = app_doc_upload_url('satis', 'sales_orders', $id, 'sales_detail.php?type=order&id=' . $id);
    $infoRows = [
        'Cari' => (string) ($header['company_name'] ?: $header['full_name'] ?: '-'),
        'Satis Temsilcisi' => (string) ($header['sales_user_name'] ?: '-'),
        'Komisyon Orani' => '%' . number_format((float) ($header['rate_percent'] ?? 0), 2, ',', '.'),
        'Aylik Hedef' => number_format((float) app_metric($db, 'SELECT COALESCE(target_amount,0) FROM sales_targets WHERE user_id = :user_id AND target_year = :target_year AND target_month = :target_month LIMIT 1', ['user_id' => (int) ($header['sales_user_id'] ?? 0), 'target_year' => $currentYear, 'target_month' => $currentMonth]), 2, ',', '.'),
        'Telefon' => (string) ($header['cari_phone'] ?: '-'),
        'E-posta' => (string) ($header['cari_email'] ?: '-'),
        'Siparis Tarihi' => (string) ($header['order_date'] ?: '-'),
        'Bagli Teklif' => (string) ($header['offer_no'] ?: '-'),
        'Teslim Durumu' => (string) ($header['delivery_status'] ?: '-'),
        'Teslim Tarihi' => (string) ($header['delivered_at'] ?: '-'),
        'Kargo' => (string) (($header['provider_name'] ?: $header['cargo_company']) ?: '-'),
        'Takip No' => (string) ($header['tracking_no'] ?: '-'),
    ];
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | Satis Detay</title>
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
                    <p><?= $type === 'offer' ? 'Teklif akisi ve kalemleri tek sayfada.' : 'Siparis, sevk ve stok etkileri tek sayfada.' ?></p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="<?= app_h($returnUrl) ?>">Satis Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h($printUrl) ?>" target="_blank">PDF / Yazdir</a>
                    <a class="btn btn-soft" href="<?= app_h($docUrl) ?>">Hizli Evrak Yukle</a>
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
                    <h3><?= $type === 'offer' ? 'Teklif Ozeti' : 'Siparis Ozeti' ?></h3>
                    <div class="meta">
                        <?php foreach ($infoRows as $label => $value): ?>
                            <div class="meta-item"><strong><?= app_h($label) ?></strong><?= app_h($value) ?></div>
                        <?php endforeach; ?>
                        <?php if ($type === 'offer'): ?>
                            <div class="meta-item"><strong>Notlar</strong><?= app_h((string) ($header['notes'] ?: '-')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <h3><?= $type === 'offer' ? 'Teklif Kalemleri' : 'Siparis Kalemleri' ?></h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Aciklama</th><th>Urun</th><th>Miktar</th><th>Birim</th><th>Tutar</th></tr></thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= app_h($item['description']) ?></td>
                                    <td><?= app_h((string) (($item['product_name'] ?: '-') . (($item['sku'] ?? '') !== '' ? ' / ' . $item['sku'] : ''))) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['unit_price'], 2, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['line_total'], 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <?php if ($type === 'order'): ?>
                    <div class="card">
                        <h3>Sevk ve Irsaliye</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Sevk No</th><th>Irsaliye</th><th>Durum</th><th>e-Irsaliye</th><th>Depo</th><th>Kargo</th><th>Fatura</th><th>Tarih</th><th>Islem</th></tr></thead>
                                <tbody>
                                <?php foreach ($shipments as $shipment): ?>
                                    <tr>
                                        <td><?= app_h($shipment['shipment_no']) ?></td>
                                        <td><?= app_h($shipment['irsaliye_no']) ?></td>
                                        <td><?= app_h($shipment['delivery_status']) ?></td>
                                        <td><?= app_h((string) (($shipment['edispatch_status'] ?: 'taslak') . (($shipment['edispatch_uuid'] ?? '') !== '' ? ' / ' . $shipment['edispatch_uuid'] : ''))) ?></td>
                                        <td><?= app_h((string) ($shipment['warehouse_name'] ?: '-')) ?></td>
                                        <td><?= app_h((string) (((($shipment['provider_name'] ?: null) ?: $shipment['cargo_company']) ?: '-') . (($shipment['tracking_no'] ?? '') !== '' ? ' / ' . $shipment['tracking_no'] : ''))) ?></td>
                                        <td><?= app_h((string) ((int) ($shipment['invoice_count'] ?? 0)) . ' belge') ?></td>
                                        <td><?= app_h($shipment['shipment_date']) ?></td>
                                        <td>
                                            <div class="stack">
                                                <form method="post" action="index.php?module=satis">
                                                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="send_edispatch">
                                                    <input type="hidden" name="shipment_id" value="<?= (int) $shipment['id'] ?>">
                                                    <button type="submit">e-Irsaliye Gonder</button>
                                                </form>
                                                <form method="post" action="index.php?module=satis">
                                                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="query_edispatch_status">
                                                    <input type="hidden" name="shipment_id" value="<?= (int) $shipment['id'] ?>">
                                                    <button type="submit">Durum Sorgula</button>
                                                </form>
                                                <form method="post" action="index.php?module=satis">
                                                    <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="create_invoice_from_shipment">
                                                    <input type="hidden" name="shipment_id" value="<?= (int) $shipment['id'] ?>">
                                                    <button type="submit">Faturaya Cevir</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($shipments): ?>
                            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                                <a class="btn btn-soft" href="print.php?type=shipment&id=<?= (int) $id ?>" target="_blank">Irsaliye Yazdir</a>
                                <a class="btn btn-soft" href="print.php?type=cargo_label&id=<?= (int) $id ?>" target="_blank">Kargo Etiketi</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <h3>Stok Hareketleri</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Tarih</th><th>Tur</th><th>Depo</th><th>Urun</th><th>Miktar</th></tr></thead>
                                <tbody>
                                <?php foreach ($stock as $item): ?>
                                    <tr>
                                        <td><?= app_h($item['movement_date']) ?></td>
                                        <td><?= app_h($item['movement_type']) ?></td>
                                        <td><?= app_h((string) ($item['warehouse_name'] ?: '-')) ?></td>
                                        <td><?= app_h((string) ($item['product_name'] ?: '-')) ?></td>
                                        <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Bagli Faturalar</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Fatura No</th><th>Tarih</th><th>Durum</th><th>Tutar</th><th>Islem</th></tr></thead>
                                <tbody>
                                <?php foreach ($linkedInvoices as $invoice): ?>
                                    <tr>
                                        <td><?= app_h($invoice['invoice_no']) ?></td>
                                        <td><?= app_h($invoice['invoice_date']) ?></td>
                                        <td><?= app_h((string) ($invoice['edocument_status'] ?: '-')) ?></td>
                                        <td><?= app_h(number_format((float) $invoice['grand_total'], 2, ',', '.')) ?></td>
                                        <td><a href="invoice_detail.php?id=<?= (int) $invoice['id'] ?>">Detay</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Kargo Loglari</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Aksiyon</th><th>Saglayici</th><th>Takip</th><th>Etiket</th><th>Kullanici</th><th>Tarih</th></tr></thead>
                                <tbody>
                                <?php foreach ($cargoLogs as $log): ?>
                                    <tr>
                                        <td><?= app_h($log['action_name']) ?></td>
                                        <td><?= app_h((string) ($log['provider_name'] ?: '-')) ?></td>
                                        <td><?= app_h((string) ($log['tracking_no'] ?: '-')) ?></td>
                                        <td><?= app_h((string) ($log['label_no'] ?: '-')) ?></td>
                                        <td><?= app_h((string) ($log['user_name'] ?: '-')) ?></td>
                                        <td><?= app_h($log['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <h3>Teklif Revizyon ve Onay</h3>
                        <form method="post" action="index.php?module=satis" class="meta" style="margin-top:12px;">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_offer_revision">
                            <input type="hidden" name="offer_id" value="<?= (int) $id ?>">
                            <input type="hidden" name="return_to" value="sales_detail.php?type=offer&id=<?= (int) $id ?>">
                            <div class="meta-item">
                                <strong>Yeni Revizyon Ac</strong>
                                <input type="date" name="valid_until" value="<?= app_h((string) ($header['valid_until'] ?: '')) ?>" style="width:100%;margin-bottom:8px;">
                                <textarea name="revision_note" rows="3" style="width:100%;" placeholder="Revizyon notu"></textarea>
                                <button type="submit" style="margin-top:10px;">Revizyon Kaydet</button>
                            </div>
                        </form>
                        <form method="post" action="index.php?module=satis" class="meta">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="offer_id" value="<?= (int) $id ?>">
                            <input type="hidden" name="return_to" value="sales_detail.php?type=offer&id=<?= (int) $id ?>">
                            <div class="meta-item">
                                <strong>Onay Aksiyonu</strong>
                                <textarea name="approval_note" rows="3" style="width:100%;" placeholder="Onay veya red notu"></textarea>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                                    <button type="submit" name="action" value="submit_offer_approval">Onaya Gonder</button>
                                    <button type="submit" name="action" value="approve_offer">Onayla</button>
                                    <button type="submit" name="action" value="reject_offer">Reddet</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <h3>Revizyon Gecmisi</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Versiyon</th><th>Durum</th><th>Gecerlilik</th><th>Toplam</th><th>Not</th><th>Tarih</th></tr></thead>
                                <tbody>
                                <?php foreach ($revisions as $revision): ?>
                                    <tr>
                                        <td><?= app_h($revision['version_label']) ?></td>
                                        <td><?= app_h($revision['status']) ?></td>
                                        <td><?= app_h((string) ($revision['valid_until'] ?: '-')) ?></td>
                                        <td><?= app_h(number_format((float) $revision['grand_total'], 2, ',', '.')) ?></td>
                                        <td><?= app_h((string) ($revision['notes'] ?: '-')) ?></td>
                                        <td><?= app_h($revision['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

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

                <?php if ($type === 'offer'): ?>
                    <div class="card">
                        <h3>Onay Gecmisi</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Aksiyon</th><th>Durum</th><th>Not</th><th>Kullanici</th><th>Tarih</th></tr></thead>
                                <tbody>
                                <?php foreach ($approvals as $approval): ?>
                                    <tr>
                                        <td><?= app_h($approval['action_name']) ?></td>
                                        <td><?= app_h(trim((string) (($approval['previous_status'] ?: '-') . ' > ' . ($approval['current_status'] ?: '-')))) ?></td>
                                        <td><?= app_h((string) ($approval['note_text'] ?: '-')) ?></td>
                                        <td><?= app_h((string) ($approval['user_name'] ?: '-')) ?></td>
                                        <td><?= app_h($approval['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
