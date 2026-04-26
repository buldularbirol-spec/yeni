<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$productId = (int) ($_GET['id'] ?? 0);
if ($productId <= 0) {
    http_response_code(400);
    exit('Gecerli bir urun secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT p.*, c.name AS category_name
    FROM stock_products p
    LEFT JOIN stock_categories c ON c.id = p.category_id
    WHERE p.id = :id
    LIMIT 1
', ['id' => $productId]);

if (!$rows) {
    http_response_code(404);
    exit('Urun bulunamadi.');
}

$product = $rows[0];
$title = (string) (($product['name'] ?: 'Urun') . ' / ' . ($product['sku'] ?: ('STK#' . $productId)));

$stockByWarehouse = app_fetch_all($db, '
    SELECT
        w.name AS warehouse_name,
        COALESCE(SUM(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.quantity ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN m.movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis") THEN m.quantity ELSE 0 END), 0) AS current_stock
    FROM stock_warehouses w
    LEFT JOIN stock_movements m ON m.warehouse_id = w.id AND m.product_id = :product_id
    GROUP BY w.id, w.name
    ORDER BY w.id ASC
', ['product_id' => $productId]);

$movements = app_fetch_all($db, '
    SELECT m.movement_type, m.quantity, m.unit_cost, m.lot_no, m.serial_no, m.reference_type, m.movement_date, w.name AS warehouse_name
    FROM stock_movements m
    LEFT JOIN stock_warehouses w ON w.id = m.warehouse_id
    WHERE m.product_id = :product_id
    ORDER BY m.id DESC
    LIMIT 40
', ['product_id' => $productId]);

$salesLinks = app_fetch_all($db, '
    SELECT "Teklif" AS source_type, o.offer_no AS ref_no, i.quantity, i.line_total, o.offer_date AS ref_date
    FROM sales_offer_items i
    INNER JOIN sales_offers o ON o.id = i.offer_id
    WHERE i.product_id = :product_id
    UNION ALL
    SELECT "Siparis" AS source_type, o.order_no AS ref_no, i.quantity, i.line_total, o.order_date AS ref_date
    FROM sales_order_items i
    INNER JOIN sales_orders o ON o.id = i.order_id
    WHERE i.product_id = :product_id
    ORDER BY ref_date DESC
    LIMIT 20
', ['product_id' => $productId]);

$productionLinks = app_fetch_all($db, '
    SELECT "Recete" AS source_type, r.recipe_code AS ref_no, i.quantity, i.unit, NULL AS event_date
    FROM production_recipe_items i
    INNER JOIN production_recipes r ON r.id = i.recipe_id
    WHERE i.material_product_id = :product_id
    UNION ALL
    SELECT "Sarf" AS source_type, o.order_no AS ref_no, c.quantity, "adet" AS unit, c.created_at AS event_date
    FROM production_consumptions c
    INNER JOIN production_orders o ON o.id = c.production_order_id
    WHERE c.product_id = :product_id
    UNION ALL
    SELECT "Cikti" AS source_type, o.order_no AS ref_no, x.quantity, "adet" AS unit, x.created_at AS event_date
    FROM production_outputs x
    INNER JOIN production_orders o ON o.id = x.production_order_id
    WHERE x.product_id = :product_id
    ORDER BY event_date DESC
    LIMIT 20
', ['product_id' => $productId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'stok',
    'related_table' => 'stock_products',
    'related_id' => $productId,
]);

$totalStock = 0.0;
foreach ($stockByWarehouse as $item) {
    $totalStock += (float) $item['current_stock'];
}

$summary = [
    'Tip' => (string) ($product['product_type'] ?: '-'),
    'Toplam Stok' => number_format($totalStock, 3, ',', '.'),
    'Kritik' => number_format((float) ($product['critical_stock'] ?? 0), 3, ',', '.'),
    'Depo' => (string) count($stockByWarehouse),
    'Hareket' => (string) count($movements),
    'Evrak' => (string) count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | Stok Detay</title>
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
                    <p>Urun karti, depo stoklari, hareket gecmisi, satis ve uretim baglantilari tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=stok">Stok Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('stok', 'stock_products', $productId, 'stock_detail.php?id=' . $productId)) ?>">Hizli Evrak Yukle</a>
                    <a class="btn btn-soft" href="index.php?module=evrak&filter_module=stok&filter_related_table=stock_products&filter_related_id=<?= $productId ?>&prefill_module=stok&prefill_related_table=stock_products&prefill_related_id=<?= $productId ?>">Arsivi Ac</a>
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
                    <h3>Urun Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Kategori</strong><?= app_h((string) ($product['category_name'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Barkod</strong><?= app_h((string) ($product['barcode'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Birim</strong><?= app_h((string) ($product['unit'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Alis Fiyati</strong><?= app_h(number_format((float) ($product['purchase_price'] ?? 0), 2, ',', '.')) ?></div>
                        <div class="meta-item"><strong>Satis Fiyati</strong><?= app_h(number_format((float) ($product['sale_price'] ?? 0), 2, ',', '.')) ?></div>
                        <div class="meta-item"><strong>Lot Takibi</strong><?= (int) ($product['track_lot'] ?? 0) === 1 ? 'Aktif' : 'Kapali' ?></div>
                        <div class="meta-item"><strong>Seri Takibi</strong><?= (int) ($product['track_serial'] ?? 0) === 1 ? 'Aktif' : 'Kapali' ?></div>
                        <div class="meta-item"><strong>Durum</strong><?= (int) ($product['status'] ?? 0) === 1 ? 'Aktif' : 'Pasif' ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Depo Bazli Stok</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Depo</th><th>Mevcut Stok</th></tr></thead>
                            <tbody>
                            <?php foreach ($stockByWarehouse as $item): ?>
                                <tr>
                                    <td><?= app_h($item['warehouse_name']) ?></td>
                                    <td><?= app_h(number_format((float) $item['current_stock'], 3, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Stok Hareketleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Depo</th><th>Tur</th><th>Miktar</th><th>Referans</th></tr></thead>
                            <tbody>
                            <?php foreach ($movements as $item): ?>
                                <tr>
                                    <td><?= app_h($item['movement_date']) ?></td>
                                    <td><?= app_h((string) ($item['warehouse_name'] ?: '-')) ?></td>
                                    <td><?= app_h($item['movement_type']) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['reference_type'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Satis Baglantilari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>Ref</th><th>Miktar</th><th>Tutar</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($salesLinks as $item): ?>
                                <tr>
                                    <td><?= app_h($item['source_type']) ?></td>
                                    <td><?= app_h($item['ref_no']) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h(number_format((float) $item['line_total'], 2, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['ref_date'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Uretim Baglantilari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tur</th><th>Ref</th><th>Miktar</th><th>Birim</th><th>Tarih</th></tr></thead>
                            <tbody>
                            <?php foreach ($productionLinks as $item): ?>
                                <tr>
                                    <td><?= app_h($item['source_type']) ?></td>
                                    <td><?= app_h($item['ref_no']) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['unit'] ?: '-')) ?></td>
                                    <td><?= app_h((string) ($item['event_date'] ?: '-')) ?></td>
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
