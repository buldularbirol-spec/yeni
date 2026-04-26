<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Fatura modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function invoice_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function invoice_next_number(PDO $db, string $invoiceType = 'satis'): string
{
    $seriesKeyMap = [
        'satis' => 'docs.invoice_sales_series',
        'alis' => 'docs.invoice_purchase_series',
        'iade' => 'docs.invoice_return_series',
    ];
    $legacyPrefixMap = [
        'satis' => 'docs.invoice_prefix',
        'alis' => 'docs.invoice_purchase_prefix',
        'iade' => 'docs.invoice_return_prefix',
    ];
    $defaultPrefixMap = [
        'satis' => 'FAT',
        'alis' => 'ALI',
        'iade' => 'IAD',
    ];

    $seriesKey = $seriesKeyMap[$invoiceType] ?? 'docs.invoice_sales_series';
    $legacyKey = $legacyPrefixMap[$invoiceType] ?? 'docs.invoice_prefix';
    $defaultPrefix = $defaultPrefixMap[$invoiceType] ?? 'FAT';

    return app_document_series_number(
        $db,
        $seriesKey,
        $legacyKey,
        $defaultPrefix,
        'invoice_headers',
        'invoice_date',
        ['invoice_type = :series_invoice_type'],
        ['series_invoice_type' => $invoiceType]
    );
}

function invoice_post_redirect(string $result): void
{
    app_redirect('index.php?module=fatura&ok=' . urlencode($result));
}

function invoice_selected_ids(): array
{
    $values = $_POST['invoice_ids'] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function invoice_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'invoice_type' => trim((string) ($_GET['invoice_type'] ?? '')),
        'edocument_status' => trim((string) ($_GET['edocument_status'] ?? '')),
        'invoice_sort' => trim((string) ($_GET['invoice_sort'] ?? 'date_desc')),
        'item_sort' => trim((string) ($_GET['item_sort'] ?? 'id_desc')),
        'invoice_page' => max(1, (int) ($_GET['invoice_page'] ?? 1)),
        'item_page' => max(1, (int) ($_GET['item_page'] ?? 1)),
    ];
}

function invoice_payment_status(float $grandTotal, float $paidTotal): string
{
    if ($paidTotal <= 0) {
        return 'odenmedi';
    }

    if ($paidTotal + 0.01 < $grandTotal) {
        return 'kismi';
    }

    return 'odendi';
}

function invoice_payment_status_label(string $status): string
{
    $labels = [
        'odenmedi' => 'Odenmedi',
        'kismi' => 'Kismi Odendi',
        'odendi' => 'Odendi',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function invoice_refresh_payment_status(PDO $db, int $invoiceId): void
{
    $row = app_fetch_all($db, '
        SELECT grand_total
        FROM invoice_headers
        WHERE id = :id
        LIMIT 1
    ', ['id' => $invoiceId])[0] ?? null;

    if (!$row) {
        return;
    }

    $paidTotal = (float) app_metric($db, 'SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = :invoice_id', ['invoice_id' => $invoiceId]);
    $paymentStatus = invoice_payment_status((float) $row['grand_total'], $paidTotal);

    $stmt = $db->prepare('
        UPDATE invoice_headers
        SET paid_total = :paid_total,
            payment_status = :payment_status,
            paid_at = :paid_at
        WHERE id = :id
    ');
    $stmt->execute([
        'paid_total' => $paidTotal,
        'payment_status' => $paymentStatus,
        'paid_at' => $paidTotal > 0 ? date('Y-m-d H:i:s') : null,
        'id' => $invoiceId,
    ]);
}

function invoice_edocument_settings(PDO $db): array
{
    return [
        'mode' => app_setting($db, 'edocument.mode', 'mock'),
        'api_url' => app_setting($db, 'edocument.api_url', ''),
        'api_method' => app_setting($db, 'edocument.api_method', 'POST'),
        'api_content_type' => app_setting($db, 'edocument.api_content_type', 'application/json'),
        'api_headers' => app_setting($db, 'edocument.api_headers', ''),
        'api_body' => app_setting($db, 'edocument.api_body', '{"invoice_no":"{{invoice_no}}"}'),
        'status_url' => app_setting($db, 'edocument.status_url', ''),
        'status_method' => app_setting($db, 'edocument.status_method', 'POST'),
        'status_content_type' => app_setting($db, 'edocument.status_content_type', 'application/json'),
        'status_headers' => app_setting($db, 'edocument.status_headers', ''),
        'status_body' => app_setting($db, 'edocument.status_body', '{"invoice_no":"{{invoice_no}}","uuid":"{{uuid}}"}'),
        'timeout' => app_setting($db, 'edocument.timeout', '15'),
        'earchive_mode' => app_setting($db, 'earchive.mode', 'mock'),
        'earchive_api_url' => app_setting($db, 'earchive.api_url', ''),
        'earchive_api_method' => app_setting($db, 'earchive.api_method', 'POST'),
        'earchive_api_content_type' => app_setting($db, 'earchive.api_content_type', 'application/json'),
        'earchive_api_headers' => app_setting($db, 'earchive.api_headers', ''),
        'earchive_api_body' => app_setting($db, 'earchive.api_body', '{"invoice_no":"{{invoice_no}}"}'),
        'earchive_status_url' => app_setting($db, 'earchive.status_url', ''),
        'earchive_status_method' => app_setting($db, 'earchive.status_method', 'POST'),
        'earchive_status_content_type' => app_setting($db, 'earchive.status_content_type', 'application/json'),
        'earchive_status_headers' => app_setting($db, 'earchive.status_headers', ''),
        'earchive_status_body' => app_setting($db, 'earchive.status_body', '{"invoice_no":"{{invoice_no}}","uuid":"{{uuid}}"}'),
        'earchive_timeout' => app_setting($db, 'earchive.timeout', '15'),
    ];
}

function invoice_collect_items(): array
{
    $descriptions = $_POST['item_description'] ?? [];
    $productIds = $_POST['item_product_id'] ?? [];
    $quantities = $_POST['item_quantity'] ?? [];
    $unitPrices = $_POST['item_unit_price'] ?? [];
    $vatRates = $_POST['item_vat_rate'] ?? [];

    $maxCount = max(
        is_array($descriptions) ? count($descriptions) : 0,
        is_array($productIds) ? count($productIds) : 0,
        is_array($quantities) ? count($quantities) : 0,
        is_array($unitPrices) ? count($unitPrices) : 0,
        is_array($vatRates) ? count($vatRates) : 0
    );

    $items = [];
    for ($index = 0; $index < $maxCount; $index++) {
        $description = trim((string) ($descriptions[$index] ?? ''));
        $quantity = (float) ($quantities[$index] ?? 0);
        $unitPrice = (float) ($unitPrices[$index] ?? 0);
        $vatRate = (float) ($vatRates[$index] ?? 20);
        $productId = (int) ($productIds[$index] ?? 0) ?: null;

        if ($description === '' && $quantity <= 0 && $unitPrice <= 0 && $productId === null) {
            continue;
        }

        if ($description === '' || $quantity <= 0) {
            throw new RuntimeException('Her fatura kaleminde aciklama ve miktar zorunludur.');
        }

        $items[] = [
            'product_id' => $productId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'vat_rate' => $vatRate,
            'line_total' => $quantity * $unitPrice,
        ];
    }

    return $items;
}

function invoice_source_data(PDO $db, int $invoiceId): array
{
    $headerRows = app_fetch_all($db, '
        SELECT *
        FROM invoice_headers
        WHERE id = :id
        LIMIT 1
    ', ['id' => $invoiceId]);

    if (!$headerRows) {
        throw new RuntimeException('Kaynak fatura bulunamadi.');
    }

    $itemRows = app_fetch_all($db, '
        SELECT product_id, description, quantity, unit_price, vat_rate, line_total
        FROM invoice_items
        WHERE invoice_id = :invoice_id
        ORDER BY id ASC
    ', ['invoice_id' => $invoiceId]);

    return [
        'header' => $headerRows[0],
        'items' => $itemRows,
    ];
}

function invoice_create_related(PDO $db, int $sourceInvoiceId, string $relationType): int
{
    if (!in_array($relationType, ['iade', 'duzeltme'], true)) {
        throw new RuntimeException('Fatura iliski tipi gecersiz.');
    }

    $source = invoice_source_data($db, $sourceInvoiceId);
    $header = $source['header'];
    $items = $source['items'];

    if ($items === []) {
        throw new RuntimeException('Kaynak faturada kopyalanacak kalem bulunamadi.');
    }

    $newInvoiceType = $relationType === 'iade' ? 'iade' : (string) $header['invoice_type'];
    $newInvoiceNo = invoice_next_number($db, $newInvoiceType);
    $sourceInvoiceNo = (string) $header['invoice_no'];
    $notePrefix = $relationType === 'iade' ? 'Iade' : 'Duzeltme';
    $newNotes = $notePrefix . ' belgesi / Kaynak: ' . $sourceInvoiceNo;
    if ((string) ($header['notes'] ?? '') !== '') {
        $newNotes .= PHP_EOL . (string) $header['notes'];
    }

    $stmt = $db->prepare('
        INSERT INTO invoice_headers (
            branch_id, cari_id, invoice_type, invoice_no, invoice_date, due_date, currency_code, subtotal, vat_total, grand_total, edocument_type, edocument_status, notes
        ) VALUES (
            :branch_id, :cari_id, :invoice_type, :invoice_no, :invoice_date, :due_date, :currency_code, :subtotal, :vat_total, :grand_total, :edocument_type, :edocument_status, :notes
        )
    ');
    $stmt->execute([
        'branch_id' => (int) ($header['branch_id'] ?? app_default_branch_id($db)),
        'cari_id' => (int) $header['cari_id'],
        'invoice_type' => $newInvoiceType,
        'invoice_no' => $newInvoiceNo,
        'invoice_date' => date('Y-m-d'),
        'due_date' => null,
        'currency_code' => (string) ($header['currency_code'] ?: 'TRY'),
        'subtotal' => (float) $header['subtotal'],
        'vat_total' => (float) $header['vat_total'],
        'grand_total' => (float) $header['grand_total'],
        'edocument_type' => null,
        'edocument_status' => null,
        'notes' => $newNotes,
    ]);

    $newInvoiceId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare('
        INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total)
        VALUES (:invoice_id, :product_id, :description, :quantity, :unit_price, :vat_rate, :line_total)
    ');
    foreach ($items as $item) {
        $itemStmt->execute([
            'invoice_id' => $newInvoiceId,
            'product_id' => $item['product_id'] !== null ? (int) $item['product_id'] : null,
            'description' => (string) $item['description'],
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'vat_rate' => (float) $item['vat_rate'],
            'line_total' => (float) $item['line_total'],
        ]);
    }

    $movementType = $newInvoiceType === 'satis' ? 'borc' : 'alacak';
    $movementStmt = $db->prepare('
        INSERT INTO cari_movements (
            cari_id, movement_type, source_module, source_table, source_id, amount, currency_code, description, movement_date, created_by
        ) VALUES (
            :cari_id, :movement_type, :source_module, :source_table, :source_id, :amount, :currency_code, :description, :movement_date, :created_by
        )
    ');
    $movementStmt->execute([
        'cari_id' => (int) $header['cari_id'],
        'movement_type' => $movementType,
        'source_module' => 'fatura',
        'source_table' => 'invoice_headers',
        'source_id' => $newInvoiceId,
        'amount' => (float) $header['grand_total'],
        'currency_code' => (string) ($header['currency_code'] ?: 'TRY'),
        'description' => $newInvoiceNo . ' numarali ' . $newInvoiceType . ' faturasi / Kaynak: ' . $sourceInvoiceNo,
        'movement_date' => date('Y-m-d H:i:s'),
        'created_by' => 1,
    ]);

    if ($relationType === 'iade') {
        $stockRows = app_fetch_all($db, '
            SELECT warehouse_id, product_id, quantity, unit_cost, movement_type
            FROM stock_movements
            WHERE reference_id = :reference_id
              AND reference_type IN ("fatura_satis", "fatura_iade")
            ORDER BY id ASC
        ', ['reference_id' => $sourceInvoiceId]);

        foreach ($stockRows as $stockRow) {
            $warehouseId = isset($stockRow['warehouse_id']) ? (int) $stockRow['warehouse_id'] : 0;
            $productId = isset($stockRow['product_id']) ? (int) $stockRow['product_id'] : 0;
            if ($warehouseId <= 0 || $productId <= 0) {
                continue;
            }

            app_insert_stock_movement($db, [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => ((string) $stockRow['movement_type'] === 'giris') ? 'cikis' : 'giris',
                'quantity' => (float) $stockRow['quantity'],
                'unit_cost' => (float) ($stockRow['unit_cost'] ?? 0),
                'reference_type' => 'fatura_iade',
                'reference_id' => $newInvoiceId,
                'movement_date' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    $relationStmt = $db->prepare('
        INSERT INTO invoice_relations (source_invoice_id, target_invoice_id, relation_type)
        VALUES (:source_invoice_id, :target_invoice_id, :relation_type)
    ');
    $relationStmt->execute([
        'source_invoice_id' => $sourceInvoiceId,
        'target_invoice_id' => $newInvoiceId,
        'relation_type' => $relationType,
    ]);

    app_audit_log('fatura', 'create_' . $relationType, 'invoice_headers', $newInvoiceId, $sourceInvoiceNo . ' faturasindan ' . $relationType . ' belgesi olusturuldu.');

    return $newInvoiceId;
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_invoice') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0) ?: null;
            $invoiceType = (string) ($_POST['invoice_type'] ?? 'satis');
            $items = invoice_collect_items();

            if ($cariId <= 0 || $items === []) {
                throw new RuntimeException('Cari ve en az bir fatura kalemi zorunludur.');
            }

            $subtotal = 0.0;
            $vatTotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) $item['line_total'];
                $vatTotal += ((float) $item['line_total']) * ((float) $item['vat_rate'] / 100);
            }
            $grandTotal = $subtotal + $vatTotal;
            $invoiceNo = invoice_next_number($db, $invoiceType);

            $stmt = $db->prepare('
                INSERT INTO invoice_headers (
                    branch_id, cari_id, invoice_type, invoice_no, invoice_date, due_date, currency_code, subtotal, vat_total, grand_total, paid_total, payment_status, paid_at, edocument_type, edocument_status, notes
                ) VALUES (
                    :branch_id, :cari_id, :invoice_type, :invoice_no, :invoice_date, :due_date, :currency_code, :subtotal, :vat_total, :grand_total, :paid_total, :payment_status, :paid_at, :edocument_type, :edocument_status, :notes
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'cari_id' => $cariId,
                'invoice_type' => $invoiceType,
                'invoice_no' => $invoiceNo,
                'invoice_date' => trim((string) ($_POST['invoice_date'] ?? '')) ?: date('Y-m-d'),
                'due_date' => trim((string) ($_POST['due_date'] ?? '')) ?: null,
                'currency_code' => trim((string) ($_POST['currency_code'] ?? 'TRY')) ?: 'TRY',
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grandTotal,
                'paid_total' => 0,
                'payment_status' => 'odenmedi',
                'paid_at' => null,
                'edocument_type' => trim((string) ($_POST['edocument_type'] ?? '')) ?: null,
                'edocument_status' => trim((string) ($_POST['edocument_status'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            $invoiceId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total)
                VALUES (:invoice_id, :product_id, :description, :quantity, :unit_price, :vat_rate, :line_total)
            ');
            foreach ($items as $item) {
                $stmt->execute([
                    'invoice_id' => $invoiceId,
                    'product_id' => $item['product_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'vat_rate' => $item['vat_rate'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $movementType = 'borc';
            if ($invoiceType === 'alis' || $invoiceType === 'iade') {
                $movementType = 'alacak';
            }

            $stmt = $db->prepare('
                INSERT INTO cari_movements (
                    cari_id, movement_type, source_module, source_table, source_id, amount, currency_code, description, movement_date, created_by
                ) VALUES (
                    :cari_id, :movement_type, :source_module, :source_table, :source_id, :amount, :currency_code, :description, :movement_date, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'movement_type' => $movementType,
                'source_module' => 'fatura',
                'source_table' => 'invoice_headers',
                'source_id' => $invoiceId,
                'amount' => $grandTotal,
                'currency_code' => trim((string) ($_POST['currency_code'] ?? 'TRY')) ?: 'TRY',
                'description' => $invoiceNo . ' numarali ' . $invoiceType . ' faturasi',
                'movement_date' => date('Y-m-d H:i:s'),
                'created_by' => 1,
            ]);

            if ($warehouseId !== null && in_array($invoiceType, ['satis', 'iade'], true)) {
                foreach ($items as $item) {
                    if ($item['product_id'] === null) {
                        continue;
                    }

                    $productRows = app_fetch_all($db, '
                        SELECT purchase_price
                        FROM stock_products
                        WHERE id = :id
                        LIMIT 1
                    ', ['id' => $item['product_id']]);

                    $unitCost = 0.0;
                    if ($productRows) {
                        $unitCost = (float) ($productRows[0]['purchase_price'] ?? 0);
                    }

                    app_insert_stock_movement($db, [
                        'warehouse_id' => $warehouseId,
                        'product_id' => $item['product_id'],
                        'movement_type' => $invoiceType === 'iade' ? 'giris' : 'cikis',
                        'quantity' => (float) $item['quantity'],
                        'unit_cost' => $unitCost,
                        'reference_type' => $invoiceType === 'iade' ? 'fatura_iade' : 'fatura_satis',
                        'reference_id' => $invoiceId,
                        'movement_date' => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            invoice_post_redirect('invoice');
        }

        if ($action === 'save_edocument_settings') {
            app_set_setting($db, 'edocument.mode', trim((string) ($_POST['edoc_mode'] ?? 'mock')) ?: 'mock', 'fatura');
            app_set_setting($db, 'edocument.api_url', trim((string) ($_POST['edoc_api_url'] ?? '')), 'fatura');
            app_set_setting($db, 'edocument.api_method', trim((string) ($_POST['edoc_api_method'] ?? 'POST')) ?: 'POST', 'fatura');
            app_set_setting($db, 'edocument.api_content_type', trim((string) ($_POST['edoc_api_content_type'] ?? 'application/json')) ?: 'application/json', 'fatura');
            app_set_setting($db, 'edocument.api_headers', trim((string) ($_POST['edoc_api_headers'] ?? '')), 'fatura');
            app_set_setting($db, 'edocument.api_body', (string) ($_POST['edoc_api_body'] ?? '{}'), 'fatura');
            app_set_setting($db, 'edocument.status_url', trim((string) ($_POST['edoc_status_url'] ?? '')), 'fatura');
            app_set_setting($db, 'edocument.status_method', trim((string) ($_POST['edoc_status_method'] ?? 'POST')) ?: 'POST', 'fatura');
            app_set_setting($db, 'edocument.status_content_type', trim((string) ($_POST['edoc_status_content_type'] ?? 'application/json')) ?: 'application/json', 'fatura');
            app_set_setting($db, 'edocument.status_headers', trim((string) ($_POST['edoc_status_headers'] ?? '')), 'fatura');
            app_set_setting($db, 'edocument.status_body', (string) ($_POST['edoc_status_body'] ?? '{}'), 'fatura');
            app_set_setting($db, 'edocument.timeout', trim((string) ($_POST['edoc_timeout'] ?? '15')) ?: '15', 'fatura');
            app_set_setting($db, 'earchive.mode', trim((string) ($_POST['earchive_mode'] ?? 'mock')) ?: 'mock', 'fatura');
            app_set_setting($db, 'earchive.api_url', trim((string) ($_POST['earchive_api_url'] ?? '')), 'fatura');
            app_set_setting($db, 'earchive.api_method', trim((string) ($_POST['earchive_api_method'] ?? 'POST')) ?: 'POST', 'fatura');
            app_set_setting($db, 'earchive.api_content_type', trim((string) ($_POST['earchive_api_content_type'] ?? 'application/json')) ?: 'application/json', 'fatura');
            app_set_setting($db, 'earchive.api_headers', trim((string) ($_POST['earchive_api_headers'] ?? '')), 'fatura');
            app_set_setting($db, 'earchive.api_body', (string) ($_POST['earchive_api_body'] ?? '{}'), 'fatura');
            app_set_setting($db, 'earchive.status_url', trim((string) ($_POST['earchive_status_url'] ?? '')), 'fatura');
            app_set_setting($db, 'earchive.status_method', trim((string) ($_POST['earchive_status_method'] ?? 'POST')) ?: 'POST', 'fatura');
            app_set_setting($db, 'earchive.status_content_type', trim((string) ($_POST['earchive_status_content_type'] ?? 'application/json')) ?: 'application/json', 'fatura');
            app_set_setting($db, 'earchive.status_headers', trim((string) ($_POST['earchive_status_headers'] ?? '')), 'fatura');
            app_set_setting($db, 'earchive.status_body', (string) ($_POST['earchive_status_body'] ?? '{}'), 'fatura');
            app_set_setting($db, 'earchive.timeout', trim((string) ($_POST['earchive_timeout'] ?? '15')) ?: '15', 'fatura');
            app_audit_log('fatura', 'save_edocument_settings', 'core_settings', null, 'e-Fatura entegrasyon ayarlari guncellendi.');
            invoice_post_redirect('edocument_settings');
        }

        if ($action === 'update_invoice_edocument') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);

            if ($invoiceId <= 0) {
                throw new RuntimeException('Gecerli bir fatura secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE invoice_headers
                SET edocument_type = :edocument_type, edocument_status = :edocument_status, due_date = :due_date
                WHERE id = :id
            ');
            $stmt->execute([
                'edocument_type' => trim((string) ($_POST['edocument_type'] ?? '')) ?: null,
                'edocument_status' => trim((string) ($_POST['edocument_status'] ?? '')) ?: null,
                'due_date' => trim((string) ($_POST['due_date'] ?? '')) ?: null,
                'id' => $invoiceId,
            ]);

            invoice_post_redirect('invoice_status');
        }

        if ($action === 'bulk_update_invoice_edocument') {
            $invoiceIds = invoice_selected_ids();

            if ($invoiceIds === []) {
                throw new RuntimeException('Toplu guncelleme icin fatura secilmedi.');
            }

            $edocumentType = trim((string) ($_POST['bulk_edocument_type'] ?? '')) ?: null;
            $edocumentStatus = trim((string) ($_POST['bulk_edocument_status'] ?? '')) ?: null;
            $dueDate = trim((string) ($_POST['bulk_due_date'] ?? '')) ?: null;

            $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
            $params = array_merge([$edocumentType, $edocumentStatus, $dueDate], $invoiceIds);
            $stmt = $db->prepare("UPDATE invoice_headers SET edocument_type = ?, edocument_status = ?, due_date = ? WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            invoice_post_redirect('invoice_bulk_status');
        }

        if ($action === 'bulk_export_invoices_csv') {
            $invoiceIds = invoice_selected_ids();
            if ($invoiceIds === []) {
                throw new RuntimeException('CSV icin fatura secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
            $rows = app_fetch_all($db, "
                SELECT h.invoice_no, c.company_name, c.full_name, h.invoice_type, h.invoice_date, h.due_date, h.edocument_type, h.edocument_status, h.grand_total
                FROM invoice_headers h
                INNER JOIN cari_cards c ON c.id = h.cari_id
                WHERE h.id IN ({$placeholders})
                ORDER BY h.id DESC
            ", $invoiceIds);

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['invoice_no'],
                    $row['company_name'] ?: $row['full_name'] ?: '-',
                    $row['invoice_type'],
                    $row['invoice_date'],
                    $row['due_date'] ?: '-',
                    $row['edocument_type'] ?: '-',
                    $row['edocument_status'] ?: '-',
                    number_format((float) $row['grand_total'], 2, '.', ''),
                ];
            }

            app_csv_download('secili-faturalar.csv', ['Fatura No', 'Cari', 'Tip', 'Tarih', 'Vade', 'e-Belge', 'Durum', 'Toplam'], $exportRows);
        }

        if ($action === 'delete_invoice') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);

            if ($invoiceId <= 0) {
                throw new RuntimeException('Gecerli bir fatura secilmedi.');
            }

            $invoiceRows = app_fetch_all($db, '
                SELECT edocument_type, edocument_status
                FROM invoice_headers
                WHERE id = :id
                LIMIT 1
            ', ['id' => $invoiceId]);

            if (!$invoiceRows) {
                throw new RuntimeException('Fatura bulunamadi.');
            }
            app_assert_branch_access($db, 'invoice_headers', $invoiceId);

            $invoiceRow = $invoiceRows[0];
            $edocumentStatus = strtolower(trim((string) ($invoiceRow['edocument_status'] ?? '')));
            $lockedStatuses = ['gonderildi', 'kabul', 'onaylandi'];
            if ((string) ($invoiceRow['edocument_type'] ?? '') !== '' && in_array($edocumentStatus, $lockedStatuses, true)) {
                throw new RuntimeException('Gonderilmis veya onaylanmis e-belge silinemez.');
            }

            $stockMovementCount = (int) app_metric($db, "
                SELECT COUNT(*) FROM stock_movements
                WHERE reference_id = :reference_id
                  AND reference_type IN ('fatura_satis', 'fatura_iade')
            ", ['reference_id' => $invoiceId]);
            if ($stockMovementCount > 0) {
                throw new RuntimeException('Stok hareketi olusmus fatura silinemez. Once iade/duzeltme islemi yapin.');
            }

            $stmt = $db->prepare("DELETE FROM cari_movements WHERE source_module = 'fatura' AND source_table = 'invoice_headers' AND source_id = :source_id");
            $stmt->execute(['source_id' => $invoiceId]);

            $stmt = $db->prepare('DELETE FROM invoice_headers WHERE id = :id');
            $stmt->execute(['id' => $invoiceId]);

            invoice_post_redirect('delete_invoice');
        }

        if ($action === 'create_invoice_return') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Iade icin gecerli bir fatura secilmedi.');
            }

            invoice_create_related($db, $invoiceId, 'iade');
            invoice_post_redirect('invoice_return');
        }

        if ($action === 'create_invoice_correction') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Duzeltme icin gecerli bir fatura secilmedi.');
            }

            invoice_create_related($db, $invoiceId, 'duzeltme');
            invoice_post_redirect('invoice_correction');
        }

        if ($action === 'send_edocument') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Gonderim icin gecerli bir fatura secilmedi.');
            }

            $invoiceRows = app_fetch_all($db, '
                SELECT h.*, c.company_name, c.full_name
                FROM invoice_headers h
                INNER JOIN cari_cards c ON c.id = h.cari_id
                WHERE h.id = :id
                LIMIT 1
            ', ['id' => $invoiceId]);
            if (!$invoiceRows) {
                throw new RuntimeException('Fatura bulunamadi.');
            }

            $result = app_edocument_send_invoice($db, $invoiceRows[0]);
            $stmt = $db->prepare('
                UPDATE invoice_headers
                SET edocument_status = :edocument_status,
                    edocument_uuid = :edocument_uuid,
                    edocument_response = :edocument_response,
                    edocument_sent_at = :edocument_sent_at
                WHERE id = :id
            ');
            $stmt->execute([
                'edocument_status' => $result['status'],
                'edocument_uuid' => $result['uuid'],
                'edocument_response' => $result['response'],
                'edocument_sent_at' => date('Y-m-d H:i:s'),
                'id' => $invoiceId,
            ]);

            app_audit_log('fatura', 'send_edocument', 'invoice_headers', $invoiceId, $invoiceRows[0]['invoice_no'] . ' e-belge gonderimi yapildi.');
            invoice_post_redirect('edocument_send');
        }

        if ($action === 'query_edocument_status') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new RuntimeException('Durum sorgusu icin gecerli bir fatura secilmedi.');
            }

            $invoiceRows = app_fetch_all($db, '
                SELECT h.*, c.company_name, c.full_name
                FROM invoice_headers h
                INNER JOIN cari_cards c ON c.id = h.cari_id
                WHERE h.id = :id
                LIMIT 1
            ', ['id' => $invoiceId]);
            if (!$invoiceRows) {
                throw new RuntimeException('Fatura bulunamadi.');
            }

            $result = app_edocument_query_invoice($db, $invoiceRows[0]);
            $stmt = $db->prepare('
                UPDATE invoice_headers
                SET edocument_status = :edocument_status,
                    edocument_response = :edocument_response
                WHERE id = :id
            ');
            $stmt->execute([
                'edocument_status' => $result['status'],
                'edocument_response' => $result['response'],
                'id' => $invoiceId,
            ]);

            app_audit_log('fatura', 'query_edocument_status', 'invoice_headers', $invoiceId, $invoiceRows[0]['invoice_no'] . ' e-belge durumu sorgulandi.');
            invoice_post_redirect('edocument_query');
        }

        if ($action === 'register_invoice_payment') {
            $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
            $paymentChannel = trim((string) ($_POST['payment_channel'] ?? 'banka'));
            $paymentRefId = (int) ($_POST['payment_ref_id'] ?? 0);
            if ($paymentChannel === 'kasa') {
                $paymentRefId = (int) ($_POST['cashbox_id'] ?? $paymentRefId);
            } elseif ($paymentChannel === 'banka') {
                $paymentRefId = (int) ($_POST['bank_account_id'] ?? $paymentRefId);
            }
            $amount = (float) ($_POST['payment_amount'] ?? 0);
            $paymentDate = trim((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d H:i:s');
            $transactionRef = trim((string) ($_POST['transaction_ref'] ?? ''));
            $notes = trim((string) ($_POST['payment_notes'] ?? ''));

            if ($invoiceId <= 0 || !in_array($paymentChannel, ['kasa', 'banka'], true) || $paymentRefId <= 0 || $amount <= 0) {
                throw new RuntimeException('Odeme icin fatura, kanal, hesap ve tutar zorunludur.');
            }

            $invoiceRows = app_fetch_all($db, '
                SELECT id, cari_id, invoice_no, invoice_type, currency_code, grand_total, paid_total
                FROM invoice_headers
                WHERE id = :id
                LIMIT 1
            ', ['id' => $invoiceId]);

            if (!$invoiceRows) {
                throw new RuntimeException('Fatura bulunamadi.');
            }

            $invoiceRow = $invoiceRows[0];
            $stmt = $db->prepare('
                INSERT INTO invoice_payments (
                    invoice_id, payment_channel, payment_ref_id, amount, currency_code, transaction_ref, notes, payment_date, created_by
                ) VALUES (
                    :invoice_id, :payment_channel, :payment_ref_id, :amount, :currency_code, :transaction_ref, :notes, :payment_date, :created_by
                )
            ');
            $stmt->execute([
                'invoice_id' => $invoiceId,
                'payment_channel' => $paymentChannel,
                'payment_ref_id' => $paymentRefId,
                'amount' => $amount,
                'currency_code' => (string) ($invoiceRow['currency_code'] ?: 'TRY'),
                'transaction_ref' => $transactionRef !== '' ? $transactionRef : null,
                'notes' => $notes !== '' ? $notes : null,
                'payment_date' => $paymentDate,
                'created_by' => 1,
            ]);

            if ($paymentChannel === 'kasa') {
                $stmt = $db->prepare('
                    INSERT INTO finance_cash_movements (cashbox_id, cari_id, movement_type, amount, description, movement_date)
                    VALUES (:cashbox_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                ');
                $stmt->execute([
                    'cashbox_id' => $paymentRefId,
                    'cari_id' => (int) $invoiceRow['cari_id'],
                    'movement_type' => (string) $invoiceRow['invoice_type'] === 'satis' ? 'giris' : 'cikis',
                    'amount' => $amount,
                    'description' => $invoiceRow['invoice_no'] . ' fatura odemesi',
                    'movement_date' => $paymentDate,
                ]);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
                    VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                ');
                $stmt->execute([
                    'bank_account_id' => $paymentRefId,
                    'cari_id' => (int) $invoiceRow['cari_id'],
                    'movement_type' => (string) $invoiceRow['invoice_type'] === 'satis' ? 'giris' : 'cikis',
                    'amount' => $amount,
                    'description' => $invoiceRow['invoice_no'] . ' fatura odemesi',
                    'movement_date' => $paymentDate,
                ]);
            }

            $stmt = $db->prepare('
                INSERT INTO cari_movements (
                    cari_id, movement_type, source_module, source_table, source_id, amount, currency_code, description, movement_date, created_by
                ) VALUES (
                    :cari_id, :movement_type, :source_module, :source_table, :source_id, :amount, :currency_code, :description, :movement_date, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => (int) $invoiceRow['cari_id'],
                'movement_type' => (string) $invoiceRow['invoice_type'] === 'satis' ? 'alacak' : 'borc',
                'source_module' => 'fatura',
                'source_table' => 'invoice_payments',
                'source_id' => (int) $db->lastInsertId(),
                'amount' => $amount,
                'currency_code' => (string) ($invoiceRow['currency_code'] ?: 'TRY'),
                'description' => $invoiceRow['invoice_no'] . ' fatura odemesi',
                'movement_date' => $paymentDate,
                'created_by' => 1,
            ]);

            invoice_refresh_payment_status($db, $invoiceId);
            app_audit_log('fatura', 'register_payment', 'invoice_headers', $invoiceId, $invoiceRow['invoice_no'] . ' faturasina odeme kaydedildi.');
            invoice_post_redirect('invoice_payment');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Fatura islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = invoice_build_filters();
$edocumentSettings = invoice_edocument_settings($db);
[$invoiceCariScopeWhere, $invoiceCariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$invoiceWarehouseScopeWhere, $invoiceWarehouseScopeParams] = app_branch_scope_filter($db, null);
[$invoiceCashboxScopeWhere, $invoiceCashboxScopeParams] = app_branch_scope_filter($db, null);
[$invoiceBankScopeWhere, $invoiceBankScopeParams] = app_branch_scope_filter($db, null);
[$invoiceScopeWhere, $invoiceScopeParams] = app_branch_scope_filter($db, null, 'h');

$cariCards = app_fetch_all($db, 'SELECT c.id, c.company_name, c.full_name FROM cari_cards c ' . ($invoiceCariScopeWhere !== '' ? 'WHERE ' . $invoiceCariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 100', $invoiceCariScopeParams);
$products = app_fetch_all($db, 'SELECT id, sku, name, sale_price, purchase_price, vat_rate FROM stock_products ORDER BY id DESC LIMIT 100');
$warehouses = app_fetch_all($db, 'SELECT id, name FROM stock_warehouses ' . ($invoiceWarehouseScopeWhere !== '' ? 'WHERE ' . $invoiceWarehouseScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $invoiceWarehouseScopeParams);
$cashboxes = app_fetch_all($db, 'SELECT id, name FROM finance_cashboxes ' . ($invoiceCashboxScopeWhere !== '' ? 'WHERE ' . $invoiceCashboxScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $invoiceCashboxScopeParams);
$bankAccounts = app_fetch_all($db, 'SELECT id, bank_name, account_name FROM finance_bank_accounts ' . ($invoiceBankScopeWhere !== '' ? 'WHERE ' . $invoiceBankScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $invoiceBankScopeParams);
$invoiceWhere = [];
$invoiceParams = [];

if ($filters['search'] !== '') {
    $invoiceWhere[] = '(h.invoice_no LIKE :search OR c.company_name LIKE :search OR c.full_name LIKE :search)';
    $invoiceParams['search'] = '%' . $filters['search'] . '%';
}

if ($filters['invoice_type'] !== '') {
    $invoiceWhere[] = 'h.invoice_type = :invoice_type';
    $invoiceParams['invoice_type'] = $filters['invoice_type'];
}

if ($filters['edocument_status'] !== '') {
    $invoiceWhere[] = 'h.edocument_status LIKE :edocument_status';
    $invoiceParams['edocument_status'] = '%' . $filters['edocument_status'] . '%';
}
if ($invoiceScopeWhere !== '') {
    $invoiceWhere[] = $invoiceScopeWhere;
    $invoiceParams = array_merge($invoiceParams, $invoiceScopeParams);
}

$invoiceWhereSql = $invoiceWhere ? 'WHERE ' . implode(' AND ', $invoiceWhere) : '';

$invoices = app_fetch_all($db, '
    SELECT h.id, h.invoice_no, h.invoice_type, h.invoice_date, h.due_date, h.grand_total, h.paid_total, h.payment_status, h.paid_at, h.edocument_type, h.edocument_status, h.edocument_uuid, h.edocument_sent_at, h.edocument_response, c.company_name, c.full_name
    FROM invoice_headers h
    INNER JOIN cari_cards c ON c.id = h.cari_id
    ' . $invoiceWhereSql . '
    ORDER BY h.id DESC
    LIMIT 50
', $invoiceParams);
$invoiceItems = app_fetch_all($db, '
    SELECT i.description, i.quantity, i.unit_price, i.vat_rate, i.line_total, h.invoice_no
    FROM invoice_items i
    INNER JOIN invoice_headers h ON h.id = i.invoice_id
    INNER JOIN cari_cards c ON c.id = h.cari_id
    ' . $invoiceWhereSql . ($filters['search'] !== '' ? ($invoiceWhere ? ' AND ' : ' WHERE ') . 'i.description LIKE :item_search' : '') . '
    ORDER BY i.id DESC
    LIMIT 50
', $filters['search'] !== '' ? array_merge($invoiceParams, ['item_search' => '%' . $filters['search'] . '%']) : $invoiceParams);
$invoiceDocCounts = app_related_doc_counts($db, 'fatura', 'invoice_headers', array_column($invoices, 'id'));
$invoiceRelationCounts = [];
if ($invoices !== []) {
    $invoiceIds = array_values(array_filter(array_map('intval', array_column($invoices, 'id'))));
    if ($invoiceIds !== []) {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $relationRows = app_fetch_all($db, "
            SELECT source_invoice_id, relation_type, COUNT(*) AS total_count
            FROM invoice_relations
            WHERE source_invoice_id IN ({$placeholders})
            GROUP BY source_invoice_id, relation_type
        ", $invoiceIds);
        foreach ($relationRows as $relationRow) {
            $sourceId = (int) $relationRow['source_invoice_id'];
            $type = (string) $relationRow['relation_type'];
            $invoiceRelationCounts[$sourceId][$type] = (int) $relationRow['total_count'];
        }
    }
}

$invoices = app_sort_rows($invoices, $filters['invoice_sort'], [
    'date_desc' => ['invoice_date', 'desc'],
    'date_asc' => ['invoice_date', 'asc'],
    'total_desc' => ['grand_total', 'desc'],
    'total_asc' => ['grand_total', 'asc'],
    'no_asc' => ['invoice_no', 'asc'],
]);
$invoiceItems = app_sort_rows($invoiceItems, $filters['item_sort'], [
    'id_desc' => ['invoice_no', 'desc'],
    'desc_asc' => ['description', 'asc'],
    'amount_desc' => ['line_total', 'desc'],
    'amount_asc' => ['line_total', 'asc'],
]);
$invoicePagination = app_paginate_rows($invoices, $filters['invoice_page'], 10);
$invoiceItemsPagination = app_paginate_rows($invoiceItems, $filters['item_page'], 10);
$invoices = $invoicePagination['items'];
$invoiceItems = $invoiceItemsPagination['items'];
$recentInvoicePayments = app_fetch_all($db, '
    SELECT
        p.id,
        p.invoice_id,
        p.payment_channel,
        p.amount,
        p.currency_code,
        p.transaction_ref,
        p.notes,
        p.payment_date,
        h.invoice_no,
        c.company_name,
        c.full_name,
        cb.name AS cashbox_name,
        CONCAT(ba.bank_name, IFNULL(CONCAT(" / ", ba.account_name), "")) AS bank_label
    FROM invoice_payments p
    INNER JOIN invoice_headers h ON h.id = p.invoice_id
    INNER JOIN cari_cards c ON c.id = h.cari_id
    LEFT JOIN finance_cashboxes cb ON cb.id = p.payment_ref_id AND p.payment_channel = "kasa"
    LEFT JOIN finance_bank_accounts ba ON ba.id = p.payment_ref_id AND p.payment_channel = "banka"
    ORDER BY p.id DESC
    LIMIT 20
');

$summary = [
    'Fatura Sayisi' => app_table_count($db, 'invoice_headers'),
    'Satis Faturasi' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE invoice_type = 'satis'"),
    'Alis Faturasi' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE invoice_type = 'alis'"),
    'Fatura Toplami' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(grand_total),0) FROM invoice_headers'), 2, ',', '.'),
    'Tahsil Edilen' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(paid_total),0) FROM invoice_headers'), 2, ',', '.'),
    'Kalan Bakiye' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(grand_total - paid_total),0) FROM invoice_headers'), 2, ',', '.'),
    'Kalem Sayisi' => app_table_count($db, 'invoice_items'),
    'Odendi' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE payment_status = 'odendi'"),
    'Kismi Odendi' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE payment_status = 'kismi'"),
    'e-Donusumlu' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE edocument_type IS NOT NULL AND edocument_type <> ''"),
    'Gonderilen e-Belge' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE edocument_status IN ('gonderildi','kabul','onaylandi','sorgulandi')"),
];
?>

<?php if ($feedback !== ''): ?>
    <div class="notice <?= strpos($feedback, 'error:') === 0 ? 'notice-error' : 'notice-ok' ?>">
        <?= app_h(strpos($feedback, 'error:') === 0 ? substr($feedback, 6) : 'Islem kaydedildi.') ?>
    </div>
<?php endif; ?>

<section class="module-grid">
    <?php foreach ($summary as $label => $value): ?>
        <div class="card">
            <small><?= app_h($label) ?></small>
            <strong><?= app_h((string) $value) ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>e-Fatura ve e-Arsiv Entegrasyon Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_edocument_settings">
            <div>
                <label>Mod</label>
                <select name="edoc_mode">
                    <option value="mock" <?= $edocumentSettings['mode'] === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="http_api" <?= $edocumentSettings['mode'] === 'http_api' ? 'selected' : '' ?>>HTTP API</option>
                </select>
            </div>
            <div>
                <label>Timeout</label>
                <input name="edoc_timeout" value="<?= app_h($edocumentSettings['timeout']) ?>">
            </div>
            <div class="full">
                <label>Gonderim URL</label>
                <input name="edoc_api_url" value="<?= app_h($edocumentSettings['api_url']) ?>" placeholder="https://api.ornek.com/efatura/send">
            </div>
            <div>
                <label>Metod</label>
                <input name="edoc_api_method" value="<?= app_h($edocumentSettings['api_method']) ?>">
            </div>
            <div>
                <label>Content-Type</label>
                <input name="edoc_api_content_type" value="<?= app_h($edocumentSettings['api_content_type']) ?>">
            </div>
            <div class="full">
                <label>Headerlar</label>
                <textarea name="edoc_api_headers" rows="2" placeholder="Authorization: Bearer ..."><?= app_h($edocumentSettings['api_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Gonderim Govdesi</label>
                <textarea name="edoc_api_body" rows="4"><?= app_h($edocumentSettings['api_body']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum URL</label>
                <input name="edoc_status_url" value="<?= app_h($edocumentSettings['status_url']) ?>" placeholder="https://api.ornek.com/efatura/status">
            </div>
            <div>
                <label>Durum Metodu</label>
                <input name="edoc_status_method" value="<?= app_h($edocumentSettings['status_method']) ?>">
            </div>
            <div>
                <label>Durum Content-Type</label>
                <input name="edoc_status_content_type" value="<?= app_h($edocumentSettings['status_content_type']) ?>">
            </div>
            <div class="full">
                <label>Durum Headerlar</label>
                <textarea name="edoc_status_headers" rows="2"><?= app_h($edocumentSettings['status_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum Govdesi</label>
                <textarea name="edoc_status_body" rows="4"><?= app_h($edocumentSettings['status_body']) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">Entegrasyon Ayarlarini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">e-Arsiv Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_edocument_settings">
            <div>
                <label>e-Arsiv Mod</label>
                <select name="earchive_mode">
                    <option value="mock" <?= $edocumentSettings['earchive_mode'] === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="http_api" <?= $edocumentSettings['earchive_mode'] === 'http_api' ? 'selected' : '' ?>>HTTP API</option>
                </select>
            </div>
            <div>
                <label>Timeout</label>
                <input name="earchive_timeout" value="<?= app_h($edocumentSettings['earchive_timeout']) ?>">
            </div>
            <div class="full">
                <label>Gonderim URL</label>
                <input name="earchive_api_url" value="<?= app_h($edocumentSettings['earchive_api_url']) ?>" placeholder="https://api.ornek.com/earsiv/send">
            </div>
            <div>
                <label>Metod</label>
                <input name="earchive_api_method" value="<?= app_h($edocumentSettings['earchive_api_method']) ?>">
            </div>
            <div>
                <label>Content-Type</label>
                <input name="earchive_api_content_type" value="<?= app_h($edocumentSettings['earchive_api_content_type']) ?>">
            </div>
            <div class="full">
                <label>Headerlar</label>
                <textarea name="earchive_api_headers" rows="2"><?= app_h($edocumentSettings['earchive_api_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Gonderim Govdesi</label>
                <textarea name="earchive_api_body" rows="4"><?= app_h($edocumentSettings['earchive_api_body']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum URL</label>
                <input name="earchive_status_url" value="<?= app_h($edocumentSettings['earchive_status_url']) ?>" placeholder="https://api.ornek.com/earsiv/status">
            </div>
            <div>
                <label>Durum Metodu</label>
                <input name="earchive_status_method" value="<?= app_h($edocumentSettings['earchive_status_method']) ?>">
            </div>
            <div>
                <label>Durum Content-Type</label>
                <input name="earchive_status_content_type" value="<?= app_h($edocumentSettings['earchive_status_content_type']) ?>">
            </div>
            <div class="full">
                <label>Durum Headerlar</label>
                <textarea name="earchive_status_headers" rows="2"><?= app_h($edocumentSettings['earchive_status_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum Govdesi</label>
                <textarea name="earchive_status_body" rows="4"><?= app_h($edocumentSettings['earchive_status_body']) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">e-Arsiv Ayarlarini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>e-Belge Kurallari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kural</th><th>Deger</th></tr></thead>
                <tbody>
                    <tr><td>Aktif Mod</td><td><?= app_h($edocumentSettings['mode']) ?></td></tr>
                    <tr><td>e-Arsiv Mod</td><td><?= app_h($edocumentSettings['earchive_mode']) ?></td></tr>
                    <tr><td>Gonderim Sarkaci</td><td><code>{{invoice_no}}</code>, <code>{{invoice_type}}</code>, <code>{{grand_total}}</code>, <code>{{cari_name}}</code></td></tr>
                    <tr><td>Durum Sarkaci</td><td><code>{{invoice_no}}</code>, <code>{{uuid}}</code></td></tr>
                    <tr><td>Mock Davranisi</td><td>e-Fatura ve e-Arsiv icin gonderimde `gonderildi`, sorguda `kabul/onaylandi` akisi verir.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Fatura Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="fatura">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Fatura no veya cari">
        </div>
        <div>
            <label>Fatura Tipi</label>
            <select name="invoice_type">
                <option value="">Tum tipler</option>
                <option value="satis" <?= $filters['invoice_type'] === 'satis' ? 'selected' : '' ?>>Satis</option>
                <option value="alis" <?= $filters['invoice_type'] === 'alis' ? 'selected' : '' ?>>Alis</option>
                <option value="iade" <?= $filters['invoice_type'] === 'iade' ? 'selected' : '' ?>>Iade</option>
            </select>
        </div>
        <div>
            <label>e-Belge Durumu</label>
            <input name="edocument_status" value="<?= app_h($filters['edocument_status']) ?>" placeholder="Bekliyor / Gonderildi">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=fatura" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="fatura">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="invoice_type" value="<?= app_h($filters['invoice_type']) ?>">
        <input type="hidden" name="edocument_status" value="<?= app_h($filters['edocument_status']) ?>">
        <div>
            <label>Fatura Siralama</label>
            <select name="invoice_sort">
                <option value="date_desc" <?= $filters['invoice_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['invoice_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="total_desc" <?= $filters['invoice_sort'] === 'total_desc' ? 'selected' : '' ?>>Toplam yuksek</option>
                <option value="total_asc" <?= $filters['invoice_sort'] === 'total_asc' ? 'selected' : '' ?>>Toplam dusuk</option>
                <option value="no_asc" <?= $filters['invoice_sort'] === 'no_asc' ? 'selected' : '' ?>>Belge no A-Z</option>
            </select>
        </div>
        <div>
            <label>Kalem Siralama</label>
            <select name="item_sort">
                <option value="id_desc" <?= $filters['item_sort'] === 'id_desc' ? 'selected' : '' ?>>Belge yeni-eski</option>
                <option value="desc_asc" <?= $filters['item_sort'] === 'desc_asc' ? 'selected' : '' ?>>Aciklama A-Z</option>
                <option value="amount_desc" <?= $filters['item_sort'] === 'amount_desc' ? 'selected' : '' ?>>Tutar yuksek</option>
                <option value="amount_asc" <?= $filters['item_sort'] === 'amount_asc' ? 'selected' : '' ?>>Tutar dusuk</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yeni Fatura</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_invoice">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(invoice_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fatura Tipi</label>
                <select name="invoice_type">
                    <option value="satis">Satis</option>
                    <option value="alis">Alis</option>
                    <option value="iade">Iade</option>
                </select>
            </div>
            <div>
                <label>Fatura Tarihi</label>
                <input type="date" name="invoice_date" value="<?= app_h(date('Y-m-d')) ?>">
            </div>
            <div>
                <label>Vade Tarihi</label>
                <input type="date" name="due_date">
            </div>
            <div>
                <label>Para Birimi</label>
                <input name="currency_code" value="TRY">
            </div>
            <div>
                <label>e-Belge Tipi</label>
                <select name="edocument_type">
                    <option value="">Klasik</option>
                    <option value="e-Fatura">e-Fatura</option>
                    <option value="e-Arsiv">e-Arsiv</option>
                    <option value="e-Irsaliye">e-Irsaliye</option>
                    <option value="e-SMM">e-SMM</option>
                </select>
            </div>
            <div>
                <label>e-Belge Durumu</label>
                <input name="edocument_status" placeholder="Gonderildi / Bekliyor / Kabul">
            </div>
            <div>
                <label>Depo</label>
                <select name="warehouse_id">
                    <option value="">Stok baglama yok</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Fatura Kalemleri</label>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Urun</th><th>Aciklama</th><th>Miktar</th><th>Birim Fiyat</th><th>KDV</th></tr></thead>
                        <tbody>
                        <?php for ($row = 0; $row < 4; $row++): ?>
                            <tr>
                                <td>
                                    <select name="item_product_id[]">
                                        <option value="">Serbest kalem</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input name="item_description[]" placeholder="Fatura kalemi"></td>
                                <td><input type="number" name="item_quantity[]" step="0.001" min="0" value="<?= $row === 0 ? '1' : '0' ?>"></td>
                                <td><input type="number" name="item_unit_price[]" step="0.01" min="0" value="0"></td>
                                <td><input type="number" name="item_vat_rate[]" step="0.01" min="0" value="20"></td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Fatura notlari"></textarea>
            </div>
            <div class="full">
                <button type="submit">Fatura Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Fatura Odemesi</h3>
        <form method="post" class="form-grid compact-form" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="register_invoice_payment">
            <div>
                <label>Fatura</label>
                <select name="invoice_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($invoices as $invoice): ?>
                        <option value="<?= (int) $invoice['id'] ?>"><?= app_h($invoice['invoice_no'] . ' / ' . ($invoice['company_name'] ?: $invoice['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kanal</label>
                <select name="payment_channel" required>
                    <option value="banka">Banka</option>
                    <option value="kasa">Kasa</option>
                </select>
            </div>
            <div>
                <label>Banka Hesabi</label>
                <select name="bank_account_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($bankAccounts as $bankAccount): ?>
                        <option value="<?= (int) $bankAccount['id'] ?>"><?= app_h($bankAccount['bank_name'] . ' / ' . ($bankAccount['account_name'] ?: 'Ana Hesap')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kasa</label>
                <select name="cashbox_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($cashboxes as $cashbox): ?>
                        <option value="<?= (int) $cashbox['id'] ?>"><?= app_h($cashbox['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input type="number" step="0.01" min="0" name="payment_amount" required>
            </div>
            <div>
                <label>Odeme Tarihi</label>
                <input type="datetime-local" name="payment_date" value="<?= app_h(date('Y-m-d\TH:i')) ?>">
            </div>
            <div>
                <label>Islem Ref</label>
                <input name="transaction_ref" placeholder="Banka ref / makbuz no">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="payment_notes" rows="2" placeholder="Tahsilat notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Odeme Kaydet</button>
            </div>
        </form>

        <h3>Son Faturalar</h3>
        <form id="invoice-bulk-form" method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_invoice_edocument">
            <input name="bulk_edocument_type" placeholder="e-Fatura">
            <input name="bulk_edocument_status" placeholder="Bekliyor">
            <input type="date" name="bulk_due_date">
            <button type="submit">Secili Faturalari Guncelle</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_export_invoices_csv'">Secili Faturalari CSV</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.invoice-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Cari</th><th>Tip</th><th>Tarih</th><th>Toplam</th><th>Odeme</th><th>e-Belge</th><th>Evrak</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $remainingAmount = max(0, (float) $invoice['grand_total'] - (float) $invoice['paid_total']); ?>
                    <tr>
                        <td><input class="invoice-check" type="checkbox" name="invoice_ids[]" value="<?= (int) $invoice['id'] ?>" form="invoice-bulk-form"></td>
                        <td><?= app_h($invoice['invoice_no']) ?></td>
                        <td><?= app_h($invoice['company_name'] ?: $invoice['full_name'] ?: '-') ?></td>
                        <td><?= app_h($invoice['invoice_type']) ?></td>
                        <td><?= app_h($invoice['invoice_date']) ?></td>
                        <td><?= number_format((float) $invoice['grand_total'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <strong><?= app_h(invoice_payment_status_label((string) ($invoice['payment_status'] ?: 'odenmedi'))) ?></strong>
                                <span>Tahsil: <?= number_format((float) $invoice['paid_total'], 2, ',', '.') ?></span>
                                <span>Kalan: <?= number_format($remainingAmount, 2, ',', '.') ?></span>
                                <span>Son: <?= app_h((string) ($invoice['paid_at'] ?: '-')) ?></span>
                            </div>
                        </td>
                        <td><?= app_h((string) (($invoice['edocument_type'] ?: '-') . ' / ' . ($invoice['edocument_status'] ?: 'taslak'))) ?></td>
                        <td>
                            <div class="stack">
                                <a href="index.php?module=evrak&filter_module=fatura&filter_related_table=invoice_headers&filter_related_id=<?= (int) $invoice['id'] ?>&prefill_module=fatura&prefill_related_table=invoice_headers&prefill_related_id=<?= (int) $invoice['id'] ?>">
                                    Evrak (<?= (int) ($invoiceDocCounts[(int) $invoice['id']] ?? 0) ?>)
                                </a>
                                <span>Iade: <?= (int) (($invoiceRelationCounts[(int) $invoice['id']]['iade'] ?? 0)) ?> / Duzeltme: <?= (int) (($invoiceRelationCounts[(int) $invoice['id']]['duzeltme'] ?? 0)) ?></span>
                                <a href="<?= app_h(app_doc_upload_url('fatura', 'invoice_headers', (int) $invoice['id'], 'index.php?module=fatura')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <div class="stack">
                                <a href="invoice_detail.php?id=<?= (int) $invoice['id'] ?>">Detay</a>
                                <a href="print.php?type=invoice&id=<?= (int) $invoice['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#fff1df;color:#7c2d12;font-weight:700;text-decoration:none;">PDF / Yazdir</a>
                                <form method="post">
                                    <input type="hidden" name="action" value="send_edocument">
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button type="submit">e-Belge Gonder</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="query_edocument_status">
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button type="submit">Durum Sorgula</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="create_invoice_return">
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button type="submit">Iade Belgesi</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="create_invoice_correction">
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button type="submit">Duzeltme Belgesi</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu fatura silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_invoice">
                                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($invoicePagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $invoicePagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'fatura', 'search' => $filters['search'], 'invoice_type' => $filters['invoice_type'], 'edocument_status' => $filters['edocument_status'], 'invoice_sort' => $filters['invoice_sort'], 'item_sort' => $filters['item_sort'], 'invoice_page' => $page, 'item_page' => $invoiceItemsPagination['page']])) ?>"><?= $page === $invoicePagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Fatura Kalemleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fatura</th><th>Aciklama</th><th>Miktar</th><th>Birim</th><th>KDV</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($invoiceItems as $item): ?>
                    <tr>
                        <td><?= app_h($item['invoice_no']) ?></td>
                        <td><?= app_h($item['description']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['unit_price'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['vat_rate'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['line_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($invoiceItemsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $invoiceItemsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'fatura', 'search' => $filters['search'], 'invoice_type' => $filters['invoice_type'], 'edocument_status' => $filters['edocument_status'], 'invoice_sort' => $filters['invoice_sort'], 'item_sort' => $filters['item_sort'], 'invoice_page' => $invoicePagination['page'], 'item_page' => $page])) ?>"><?= $page === $invoiceItemsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Fatura Odeme Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Fatura</th><th>Cari</th><th>Kanal</th><th>Hesap</th><th>Tutar</th><th>Ref</th></tr></thead>
                <tbody>
                <?php foreach ($recentInvoicePayments as $payment): ?>
                    <tr>
                        <td><?= app_h((string) $payment['payment_date']) ?></td>
                        <td><a href="invoice_detail.php?id=<?= (int) $payment['invoice_id'] ?>"><?= app_h((string) $payment['invoice_no']) ?></a></td>
                        <td><?= app_h((string) ($payment['company_name'] ?: $payment['full_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($payment['payment_channel'] === 'kasa' ? 'Kasa' : 'Banka')) ?></td>
                        <td><?= app_h((string) ($payment['payment_channel'] === 'kasa' ? ($payment['cashbox_name'] ?: '-') : ($payment['bank_label'] ?: '-'))) ?></td>
                        <td><?= app_h(number_format((float) $payment['amount'], 2, ',', '.') . ' ' . ($payment['currency_code'] ?: 'TRY')) ?></td>
                        <td><?= app_h((string) ($payment['transaction_ref'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>e-Donusum Takibi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No</th><th>Tip</th><th>Belge</th><th>Durum</th><th>Vade</th><th>Guncelle</th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?= app_h($invoice['invoice_no']) ?></td>
                        <td><?= app_h($invoice['invoice_type']) ?></td>
                        <td><?= app_h($invoice['edocument_type'] ?: '-') ?></td>
                        <td><?= app_h($invoice['edocument_status'] ?: '-') ?></td>
                        <td><?= app_h($invoice['due_date'] ?: '-') ?></td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="update_invoice_edocument">
                                <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                                <input name="edocument_type" value="<?= app_h((string) ($invoice['edocument_type'] ?? '')) ?>" placeholder="e-Fatura">
                                <input name="edocument_status" value="<?= app_h((string) ($invoice['edocument_status'] ?? '')) ?>" placeholder="Bekliyor">
                                <input type="date" name="due_date" value="<?= app_h((string) ($invoice['due_date'] ?? '')) ?>">
                                <button type="submit">Kaydet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
