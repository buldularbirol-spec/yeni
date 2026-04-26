<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$invoiceId = (int) ($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    exit('Gecerli bir fatura secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT
        h.*,
        c.company_name,
        c.full_name,
        c.phone AS cari_phone,
        c.email AS cari_email
    FROM invoice_headers h
    INNER JOIN cari_cards c ON c.id = h.cari_id
    WHERE h.id = :id
    LIMIT 1
', ['id' => $invoiceId]);

if (!$rows) {
    http_response_code(404);
    exit('Fatura bulunamadi.');
}

$invoice = $rows[0];
$invoiceTitle = (string) ($invoice['invoice_no'] ?: ('Fatura #' . $invoiceId));
$cariName = (string) ($invoice['company_name'] ?: $invoice['full_name'] ?: '-');
$paymentStatusLabels = [
    'odenmedi' => 'Odenmedi',
    'kismi' => 'Kismi Odendi',
    'odendi' => 'Odendi',
];

$items = app_fetch_all($db, '
    SELECT i.description, i.quantity, i.unit_price, i.vat_rate, i.line_total, p.name AS product_name, p.sku
    FROM invoice_items i
    LEFT JOIN stock_products p ON p.id = i.product_id
    WHERE i.invoice_id = :invoice_id
    ORDER BY i.id ASC
', ['invoice_id' => $invoiceId]);

$cariMovements = app_fetch_all($db, '
    SELECT movement_type, amount, currency_code, description, movement_date
    FROM cari_movements
    WHERE source_module = :source_module AND source_table = :source_table AND source_id = :source_id
    ORDER BY id DESC
    LIMIT 10
', [
    'source_module' => 'fatura',
    'source_table' => 'invoice_headers',
    'source_id' => $invoiceId,
]);

$stockMovements = app_fetch_all($db, '
    SELECT m.movement_type, m.quantity, m.unit_cost, m.movement_date, w.name AS warehouse_name, p.name AS product_name
    FROM stock_movements m
    LEFT JOIN stock_warehouses w ON w.id = m.warehouse_id
    LEFT JOIN stock_products p ON p.id = m.product_id
    WHERE m.reference_id = :reference_id
      AND m.reference_type IN ("fatura_satis", "fatura_iade")
    ORDER BY m.id DESC
    LIMIT 20
', ['reference_id' => $invoiceId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'fatura',
    'related_table' => 'invoice_headers',
    'related_id' => $invoiceId,
]);

$relations = app_fetch_all($db, '
    SELECT
        r.relation_type,
        h.id AS related_invoice_id,
        h.invoice_no,
        h.invoice_type,
        h.invoice_date,
        h.grand_total
    FROM invoice_relations r
    INNER JOIN invoice_headers h ON h.id = r.target_invoice_id
    WHERE r.source_invoice_id = :source_invoice_id
    ORDER BY r.id DESC
', ['source_invoice_id' => $invoiceId]);

$shipmentLinks = app_fetch_all($db, '
    SELECT sh.id AS shipment_id, sh.shipment_no, sh.irsaliye_no, sh.shipment_date, sh.delivery_status, o.id AS order_id, o.order_no
    FROM sales_shipment_invoice_links sil
    INNER JOIN sales_shipments sh ON sh.id = sil.shipment_id
    INNER JOIN sales_orders o ON o.id = sh.order_id
    WHERE sil.invoice_id = :invoice_id
    ORDER BY sil.id DESC
', ['invoice_id' => $invoiceId]);

$invoicePayments = app_fetch_all($db, '
    SELECT
        p.payment_channel,
        p.amount,
        p.currency_code,
        p.transaction_ref,
        p.notes,
        p.payment_date,
        cb.name AS cashbox_name,
        CONCAT(ba.bank_name, IFNULL(CONCAT(" / ", ba.account_name), "")) AS bank_label
    FROM invoice_payments p
    LEFT JOIN finance_cashboxes cb ON cb.id = p.payment_ref_id AND p.payment_channel = "kasa"
    LEFT JOIN finance_bank_accounts ba ON ba.id = p.payment_ref_id AND p.payment_channel = "banka"
    WHERE p.invoice_id = :invoice_id
    ORDER BY p.id DESC
', ['invoice_id' => $invoiceId]);

$paidTotal = (float) ($invoice['paid_total'] ?? 0);
$grandTotal = (float) ($invoice['grand_total'] ?? 0);
$remainingTotal = max(0, $grandTotal - $paidTotal);

$summary = [
    'Tip' => (string) ($invoice['invoice_type'] ?: '-'),
    'Toplam' => number_format((float) ($invoice['grand_total'] ?? 0), 2, ',', '.'),
    'Tahsil Edilen' => number_format($paidTotal, 2, ',', '.'),
    'Kalan' => number_format($remainingTotal, 2, ',', '.'),
    'Odeme' => $paymentStatusLabels[(string) ($invoice['payment_status'] ?? 'odenmedi')] ?? (string) ($invoice['payment_status'] ?? '-'),
    'KDV' => number_format((float) ($invoice['vat_total'] ?? 0), 2, ',', '.'),
    'Kalem' => (string) count($items),
    'Stok' => (string) count($stockMovements),
    'Tahsilat' => (string) count($invoicePayments),
    'Evrak' => (string) count($docs),
    'Bagli Belge' => (string) count($relations),
    'Irsaliye' => (string) count($shipmentLinks),
    'e-Belge UUID' => (string) ($invoice['edocument_uuid'] ?: '-'),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($invoiceTitle) ?> | Fatura Detay</title>
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
                    <h1><?= app_h($invoiceTitle) ?></h1>
                    <p><?= app_h($cariName) ?> icin duzenlenen fatura. Kalemler, tahsilat durumu, cari etkisi, stok hareketleri ve e-belge yasam dongusu tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=fatura">Fatura Listeye Don</a>
                    <a class="btn btn-soft" href="print.php?type=invoice&id=<?= $invoiceId ?>" target="_blank">PDF / Yazdir</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('fatura', 'invoice_headers', $invoiceId, 'invoice_detail.php?id=' . $invoiceId)) ?>">Hizli Evrak Yukle</a>
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
                    <h3>Fatura Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Cari</strong><?= app_h($cariName) ?></div>
                        <div class="meta-item"><strong>Fatura Tarihi</strong><?= app_h((string) ($invoice['invoice_date'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Vade</strong><?= app_h((string) ($invoice['due_date'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Para Birimi</strong><?= app_h((string) ($invoice['currency_code'] ?: 'TRY')) ?></div>
                        <div class="meta-item"><strong>Odeme Durumu</strong><?= app_h($paymentStatusLabels[(string) ($invoice['payment_status'] ?? 'odenmedi')] ?? (string) ($invoice['payment_status'] ?? '-')) ?></div>
                        <div class="meta-item"><strong>Tahsil Edilen</strong><?= app_h(number_format($paidTotal, 2, ',', '.') . ' ' . ($invoice['currency_code'] ?: 'TRY')) ?></div>
                        <div class="meta-item"><strong>Kalan Bakiye</strong><?= app_h(number_format($remainingTotal, 2, ',', '.') . ' ' . ($invoice['currency_code'] ?: 'TRY')) ?></div>
                        <div class="meta-item"><strong>Son Tahsilat</strong><?= app_h((string) ($invoice['paid_at'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>e-Belge Tipi</strong><?= app_h((string) ($invoice['edocument_type'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>e-Belge Durumu</strong><?= app_h((string) ($invoice['edocument_status'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>e-Belge Gonderim</strong><?= app_h((string) ($invoice['edocument_sent_at'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Fatura Kalemleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Aciklama</th><th>Urun</th><th>Miktar</th><th>Birim</th><th>KDV</th><th>Tutar</th></tr></thead>
                            <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= app_h($item['description']) ?></td>
                                    <td><?= app_h((string) (($item['product_name'] ?: '-') . (($item['sku'] ?? '') !== '' ? ' / ' . $item['sku'] : ''))) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['unit_price'], 2, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['vat_rate'], 2, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['line_total'], 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Cari Etkisi</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                            <tbody>
                            <?php foreach ($cariMovements as $item): ?>
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
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Odeme Hareketleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Kanal</th><th>Hesap</th><th>Tutar</th><th>Ref</th><th>Not</th></tr></thead>
                            <tbody>
                            <?php foreach ($invoicePayments as $payment): ?>
                                <tr>
                                    <td><?= app_h((string) $payment['payment_date']) ?></td>
                                    <td><?= app_h((string) ($payment['payment_channel'] === 'kasa' ? 'Kasa' : 'Banka')) ?></td>
                                    <td><?= app_h((string) ($payment['payment_channel'] === 'kasa' ? ($payment['cashbox_name'] ?: '-') : ($payment['bank_label'] ?: '-'))) ?></td>
                                    <td><?= app_h(number_format((float) $payment['amount'], 2, ',', '.') . ' ' . ($payment['currency_code'] ?: 'TRY')) ?></td>
                                    <td><?= app_h((string) ($payment['transaction_ref'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($payment['notes'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Cari ve Notlar</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Telefon</strong><?= app_h((string) ($invoice['cari_phone'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>E-posta</strong><?= app_h((string) ($invoice['cari_email'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Notlar</strong><?= app_h((string) ($invoice['notes'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>e-Belge Son Cevap</strong><?= app_h((string) ($invoice['edocument_response'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Stok Hareketleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Tur</th><th>Depo</th><th>Urun</th><th>Miktar</th></tr></thead>
                            <tbody>
                            <?php foreach ($stockMovements as $item): ?>
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

                <div class="card">
                    <h3>Iade ve Duzeltme Belgeleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>No</th><th>Tarih</th><th>Tip</th><th>Toplam</th><th>Islem</th></tr></thead>
                            <tbody>
                            <?php foreach ($relations as $relation): ?>
                                <tr>
                                    <td><?= app_h($relation['relation_type']) ?></td>
                                    <td><?= app_h($relation['invoice_no']) ?></td>
                                    <td><?= app_h($relation['invoice_date']) ?></td>
                                    <td><?= app_h($relation['invoice_type']) ?></td>
                                    <td><?= app_h(number_format((float) $relation['grand_total'], 2, ',', '.')) ?></td>
                                    <td><a href="invoice_detail.php?id=<?= (int) $relation['related_invoice_id'] ?>">Detay</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Irsaliye Baglantilari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Sevk No</th><th>Irsaliye</th><th>Siparis</th><th>Durum</th><th>Tarih</th><th>Islem</th></tr></thead>
                            <tbody>
                            <?php foreach ($shipmentLinks as $shipmentLink): ?>
                                <tr>
                                    <td><?= app_h($shipmentLink['shipment_no']) ?></td>
                                    <td><?= app_h($shipmentLink['irsaliye_no']) ?></td>
                                    <td><?= app_h($shipmentLink['order_no']) ?></td>
                                    <td><?= app_h($shipmentLink['delivery_status']) ?></td>
                                    <td><?= app_h($shipmentLink['shipment_date']) ?></td>
                                    <td><a href="sales_detail.php?type=order&id=<?= (int) $shipmentLink['order_id'] ?>">Siparis Detay</a></td>
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
