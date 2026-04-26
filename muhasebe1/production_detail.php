<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Veritabani baglantisi gerekli.');
}

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    exit('Gecerli bir uretim emri secilmedi.');
}

$rows = app_fetch_all($db, '
    SELECT
        o.*,
        r.recipe_code,
        r.version_no,
        r.output_quantity,
        r.notes AS recipe_notes,
        p.name AS product_name,
        p.sku
    FROM production_orders o
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    WHERE o.id = :id
    LIMIT 1
', ['id' => $orderId]);

if (!$rows) {
    http_response_code(404);
    exit('Uretim emri bulunamadi.');
}

$order = $rows[0];
$title = (string) ($order['order_no'] ?: ('Uretim #' . $orderId));

$recipeItems = app_fetch_all($db, '
    SELECT i.quantity, i.unit, i.wastage_rate, p.name AS material_name, p.sku
    FROM production_recipe_items i
    INNER JOIN stock_products p ON p.id = i.material_product_id
    WHERE i.recipe_id = :recipe_id
    ORDER BY i.id ASC
', ['recipe_id' => (int) $order['recipe_id']]);

$consumptions = app_fetch_all($db, '
    SELECT c.quantity, c.created_at, p.name AS product_name, p.sku, w.name AS warehouse_name
    FROM production_consumptions c
    INNER JOIN stock_products p ON p.id = c.product_id
    LEFT JOIN stock_warehouses w ON w.id = c.warehouse_id
    WHERE c.production_order_id = :production_order_id
    ORDER BY c.id DESC
    LIMIT 20
', ['production_order_id' => $orderId]);

$outputs = app_fetch_all($db, '
    SELECT o.quantity, o.barcode, o.created_at, p.name AS product_name, p.sku, w.name AS warehouse_name
    FROM production_outputs o
    INNER JOIN stock_products p ON p.id = o.product_id
    LEFT JOIN stock_warehouses w ON w.id = o.warehouse_id
    WHERE o.production_order_id = :production_order_id
    ORDER BY o.id DESC
    LIMIT 20
', ['production_order_id' => $orderId]);

$stockMovements = app_fetch_all($db, '
    SELECT movement_type, quantity, unit_cost, movement_date, reference_type, w.name AS warehouse_name, p.name AS product_name
    FROM stock_movements m
    LEFT JOIN stock_warehouses w ON w.id = m.warehouse_id
    LEFT JOIN stock_products p ON p.id = m.product_id
    WHERE m.reference_id = :reference_id
      AND m.reference_type IN ("uretim_sarf", "uretim_cikti")
    ORDER BY m.id DESC
    LIMIT 25
', ['reference_id' => $orderId]);

$docs = app_fetch_all($db, '
    SELECT id, file_name, file_type, created_at
    FROM docs_files
    WHERE module_name = :module_name AND related_table = :related_table AND related_id = :related_id
    ORDER BY id DESC
    LIMIT 12
', [
    'module_name' => 'uretim',
    'related_table' => 'production_orders',
    'related_id' => $orderId,
]);

$summary = [
    'Durum' => (string) ($order['status'] ?: '-'),
    'Planlanan' => number_format((float) ($order['planned_quantity'] ?? 0), 3, ',', '.'),
    'Gerceklesen' => number_format((float) ($order['actual_quantity'] ?? 0), 3, ',', '.'),
    'Sarf' => (string) count($consumptions),
    'Cikti' => (string) count($outputs),
    'Evrak' => (string) count($docs),
];
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($title) ?> | Uretim Detay</title>
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
                    <p><?= app_h((string) ($order['product_name'] ?: '-')) ?> icin acilan uretim emri. Recete, sarf, cikti, stok ve belgeler tek ekranda.</p>
                </div>
                <div class="actions">
                    <a class="btn btn-primary" href="index.php?module=uretim">Uretim Listeye Don</a>
                    <a class="btn btn-soft" href="<?= app_h(app_doc_upload_url('uretim', 'production_orders', $orderId, 'production_detail.php?id=' . $orderId)) ?>">Hizli Evrak Yukle</a>
                    <a class="btn btn-soft" href="index.php?module=evrak&filter_module=uretim&filter_related_table=production_orders&filter_related_id=<?= $orderId ?>&prefill_module=uretim&prefill_related_table=production_orders&prefill_related_id=<?= $orderId ?>">Arsivi Ac</a>
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
                    <h3>Emir ve Recete Ozeti</h3>
                    <div class="meta">
                        <div class="meta-item"><strong>Recete</strong><?= app_h((string) ($order['recipe_code'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Versiyon</strong><?= app_h((string) ($order['version_no'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Mamul</strong><?= app_h((string) (($order['product_name'] ?: '-') . (($order['sku'] ?? '') !== '' ? ' / ' . $order['sku'] : ''))) ?></div>
                        <div class="meta-item"><strong>Batch No</strong><?= app_h((string) ($order['batch_no'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Baslangic</strong><?= app_h((string) ($order['started_at'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Bitis</strong><?= app_h((string) ($order['finished_at'] ?: '-')) ?></div>
                        <div class="meta-item"><strong>Recete Notlari</strong><?= app_h((string) ($order['recipe_notes'] ?: '-')) ?></div>
                    </div>
                </div>

                <div class="card">
                    <h3>Recete Kalemleri</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Malzeme</th><th>Miktar</th><th>Birim</th><th>Fire %</th></tr></thead>
                            <tbody>
                            <?php foreach ($recipeItems as $item): ?>
                                <tr>
                                    <td><?= app_h((string) (($item['material_name'] ?: '-') . (($item['sku'] ?? '') !== '' ? ' / ' . $item['sku'] : ''))) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h($item['unit']) ?></td>
                                    <td><?= app_h(number_format((float) $item['wastage_rate'], 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Sarf Kayitlari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Urun</th><th>Depo</th><th>Miktar</th></tr></thead>
                            <tbody>
                            <?php foreach ($consumptions as $item): ?>
                                <tr>
                                    <td><?= app_h($item['created_at']) ?></td>
                                    <td><?= app_h((string) (($item['product_name'] ?: '-') . (($item['sku'] ?? '') !== '' ? ' / ' . $item['sku'] : ''))) ?></td>
                                    <td><?= app_h((string) ($item['warehouse_name'] ?: '-')) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <h3>Uretim Ciktilari</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Tarih</th><th>Urun</th><th>Depo</th><th>Miktar</th><th>Barkod</th></tr></thead>
                            <tbody>
                            <?php foreach ($outputs as $item): ?>
                                <tr>
                                    <td><?= app_h($item['created_at']) ?></td>
                                    <td><?= app_h((string) (($item['product_name'] ?: '-') . (($item['sku'] ?? '') !== '' ? ' / ' . $item['sku'] : ''))) ?></td>
                                    <td><?= app_h((string) ($item['warehouse_name'] ?: '-')) ?></td>
                                    <td><?= app_h(number_format((float) $item['quantity'], 3, ',', '.')) ?></td>
                                    <td><?= app_h((string) ($item['barcode'] ?: '-')) ?></td>
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
                            <thead><tr><th>Tarih</th><th>Referans</th><th>Tur</th><th>Depo</th><th>Urun</th><th>Miktar</th></tr></thead>
                            <tbody>
                            <?php foreach ($stockMovements as $item): ?>
                                <tr>
                                    <td><?= app_h($item['movement_date']) ?></td>
                                    <td><?= app_h($item['reference_type']) ?></td>
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
            </div>
        </section>
    </div>
</body>
</html>
