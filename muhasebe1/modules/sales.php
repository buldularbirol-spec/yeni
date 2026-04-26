<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Satis modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function sales_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function sales_next_number(PDO $db, string $table, string $prefix): string
{
    if ($table === 'sales_offers') {
        return app_document_series_number($db, 'docs.offer_series', 'docs.offer_prefix', $prefix, 'sales_offers', 'offer_date');
    }

    if ($table === 'sales_orders') {
        return app_document_series_number($db, 'docs.order_series', 'docs.order_prefix', $prefix, 'sales_orders', 'order_date');
    }

    return app_document_series_number($db, 'docs.generic_series', 'docs.generic_prefix', $prefix, $table, 'created_at');
}

function sales_post_redirect(string $result): void
{
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo !== '' && strpos($returnTo, 'sales_detail.php') === 0) {
        app_redirect($returnTo . (strpos($returnTo, '?') !== false ? '&' : '?') . 'ok=' . urlencode($result));
    }

    app_redirect('index.php?module=satis&ok=' . urlencode($result));
}

function sales_offer_approval_row(PDO $db, int $offerId): ?array
{
    if ($offerId <= 0) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT id, offer_no, status, grand_total
        FROM sales_offers
        WHERE id = :id
        LIMIT 1
    ', ['id' => $offerId]);

    return $rows[0] ?? null;
}

function sales_guard_offer_approval(PDO $db, array $authUser, int $offerId, string $note): void
{
    $result = sales_offer_approval_guard_result($db, $authUser, $offerId, $note);
    if (!empty($result['finalize'])) {
        return;
    }

    sales_post_redirect((string) ($result['redirect'] ?? 'offer_waiting_second_approval'));
}

function sales_offer_latest_approval_log(PDO $db, int $offerId): ?array
{
    if ($offerId <= 0) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT id, action_name, previous_status, current_status, note_text, created_by, created_at
        FROM sales_offer_approval_logs
        WHERE offer_id = :offer_id
        ORDER BY id DESC
        LIMIT 1
    ', ['offer_id' => $offerId]);

    return $rows[0] ?? null;
}

function sales_offer_approval_guard_result(PDO $db, array $authUser, int $offerId, string $note): array
{
    $offerRow = sales_offer_approval_row($db, $offerId);
    if (!$offerRow) {
        throw new RuntimeException('Teklif kaydi bulunamadi.');
    }

    $rule = app_approval_rule('sales.offer_approve');
    if (!app_approval_rule_matches($rule, ['amount' => (float) ($offerRow['grand_total'] ?? 0)])) {
        return ['finalize' => true, 'action_name' => 'onayla'];
    }

    if ((string) ($offerRow['status'] ?? '') !== 'gonderildi') {
        throw new RuntimeException('Bu teklif icin onay kurali aktif. Once teklifi onaya gonderin.');
    }

    if (!app_approval_rule_user_allowed($authUser, $rule)) {
        $requiredRole = (string) ($rule['approver_role_code'] ?? '');
        throw new RuntimeException('Bu teklif onayi yalnizca ' . ($requiredRole !== '' ? $requiredRole : 'yetkili rol') . ' tarafindan verilebilir.');
    }

    if (!empty($rule['require_note']) && trim($note) === '') {
        throw new RuntimeException('Bu teklif onayi icin aciklama/not girmek zorunludur.');
    }

    if (empty($rule['require_second_approval'])) {
        return ['finalize' => true, 'action_name' => 'onayla'];
    }

    $latestLog = sales_offer_latest_approval_log($db, $offerId);
    if ($latestLog && (string) ($latestLog['action_name'] ?? '') === 'ilk_onay') {
        if ((int) ($latestLog['created_by'] ?? 0) === (int) ($authUser['id'] ?? 0)) {
            throw new RuntimeException('Cift onay gereken islemlerde ikinci onay farkli bir kullanici tarafindan verilmelidir.');
        }

        $secondRole = trim((string) ($rule['second_approver_role_code'] ?? ''));
        if ($secondRole !== '' && (string) ($authUser['role_code'] ?? '') !== $secondRole) {
            throw new RuntimeException('Bu teklifin ikinci onayi yalnizca ' . $secondRole . ' rolundeki kullanici tarafindan verilebilir.');
        }

        return ['finalize' => true, 'action_name' => 'cift_onay_tamamla'];
    }

    sales_offer_log_approval($db, $offerId, 'ilk_onay', 'gonderildi', 'ikinci_onay_bekliyor', $note);
    app_audit_log('satis', 'ilk_onay', 'sales_offers', $offerId, ($offerRow['offer_no'] ?? 'Teklif') . ' icin ilk onay verildi, ikinci onay bekleniyor.');

    return [
        'finalize' => false,
        'redirect' => 'offer_waiting_second_approval',
    ];
}

function sales_offer_template(PDO $db, int $templateId): ?array
{
    if ($templateId <= 0) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT id, template_name, category_name, product_id, default_description, default_quantity, default_unit_price, default_status, valid_day_count, notes
        FROM sales_offer_templates
        WHERE id = :id AND status = 1
        LIMIT 1
    ', ['id' => $templateId]);

    return $rows[0] ?? null;
}

function sales_edispatch_settings(PDO $db): array
{
    return [
        'mode' => app_setting($db, 'edispatch.mode', 'mock'),
        'api_url' => app_setting($db, 'edispatch.api_url', ''),
        'api_method' => app_setting($db, 'edispatch.api_method', 'POST'),
        'api_content_type' => app_setting($db, 'edispatch.api_content_type', 'application/json'),
        'api_headers' => app_setting($db, 'edispatch.api_headers', ''),
        'api_body' => app_setting($db, 'edispatch.api_body', '{"shipment_no":"{{shipment_no}}"}'),
        'status_url' => app_setting($db, 'edispatch.status_url', ''),
        'status_method' => app_setting($db, 'edispatch.status_method', 'POST'),
        'status_content_type' => app_setting($db, 'edispatch.status_content_type', 'application/json'),
        'status_headers' => app_setting($db, 'edispatch.status_headers', ''),
        'status_body' => app_setting($db, 'edispatch.status_body', '{"irsaliye_no":"{{irsaliye_no}}","uuid":"{{uuid}}"}'),
        'timeout' => app_setting($db, 'edispatch.timeout', '15'),
    ];
}

function sales_selected_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function sales_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'offer_status' => trim((string) ($_GET['offer_status'] ?? '')),
        'order_status' => trim((string) ($_GET['order_status'] ?? '')),
        'offer_sort' => trim((string) ($_GET['offer_sort'] ?? 'date_desc')),
        'order_sort' => trim((string) ($_GET['order_sort'] ?? 'date_desc')),
        'offer_page' => max(1, (int) ($_GET['offer_page'] ?? 1)),
        'order_page' => max(1, (int) ($_GET['order_page'] ?? 1)),
    ];
}

function sales_current_user_id(): ?int
{
    $user = app_auth_user();
    return isset($user['id']) ? (int) $user['id'] : null;
}

function sales_next_document_number(PDO $db, string $table, string $column, string $prefix): string
{
    if ($table === 'sales_shipments' && $column === 'shipment_no') {
        return app_document_series_number($db, 'docs.shipment_series', 'docs.shipment_prefix', $prefix, 'sales_shipments', 'shipment_date');
    }

    if ($table === 'sales_shipments' && $column === 'irsaliye_no') {
        return app_document_series_number($db, 'docs.dispatch_series', 'docs.dispatch_prefix', $prefix, 'sales_shipments', 'shipment_date');
    }

    return app_document_series_number($db, 'docs.generic_series', 'docs.generic_prefix', $prefix, $table, 'created_at');
}

function sales_tracking_number(int $orderId, string $providerCode): string
{
    return strtoupper($providerCode) . '-' . date('ymd') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
}

function sales_log_cargo(PDO $db, int $orderId, ?int $shipmentId, ?int $providerId, string $actionName, ?string $trackingNo, ?string $labelNo, array $requestPayload = [], array $responsePayload = []): void
{
    $stmt = $db->prepare('
        INSERT INTO sales_cargo_logs (
            order_id, shipment_id, provider_id, action_name, tracking_no, label_no, request_payload, response_payload, created_by
        ) VALUES (
            :order_id, :shipment_id, :provider_id, :action_name, :tracking_no, :label_no, :request_payload, :response_payload, :created_by
        )
    ');
    $stmt->execute([
        'order_id' => $orderId,
        'shipment_id' => $shipmentId,
        'provider_id' => $providerId,
        'action_name' => $actionName,
        'tracking_no' => $trackingNo,
        'label_no' => $labelNo,
        'request_payload' => $requestPayload ? json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'response_payload' => $responsePayload ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'created_by' => sales_current_user_id(),
    ]);
}

function sales_commission_rows(PDO $db): array
{
    return app_fetch_all($db, '
        SELECT
            u.id AS user_id,
            u.full_name,
            COALESCE(r.rate_percent, 0) AS rate_percent,
            COALESCE(r.target_amount, 0) AS target_amount,
            COALESCE(SUM(CASE WHEN o.status NOT IN ("iptal") THEN o.grand_total ELSE 0 END), 0) AS order_total,
            COALESCE(SUM(CASE WHEN l.invoice_id IS NOT NULL THEN o.grand_total ELSE 0 END), 0) AS invoiced_total
        FROM core_users u
        LEFT JOIN sales_commission_rules r ON r.user_id = u.id
        LEFT JOIN sales_orders o ON o.sales_user_id = u.id
        LEFT JOIN sales_order_invoice_links l ON l.order_id = o.id
        WHERE u.status = 1
        GROUP BY u.id, u.full_name, r.rate_percent, r.target_amount
        ORDER BY invoiced_total DESC, order_total DESC, u.full_name ASC
    ');
}

function sales_target_rows(PDO $db, int $year, int $month): array
{
    return app_fetch_all($db, '
        SELECT
            u.id AS user_id,
            u.full_name,
            COALESCE(t.target_amount, 0) AS target_amount,
            COALESCE(SUM(CASE
                WHEN o.status NOT IN ("iptal")
                 AND YEAR(o.order_date) = :target_year
                 AND MONTH(o.order_date) = :target_month
                THEN o.grand_total ELSE 0 END), 0) AS order_total,
            COALESCE(SUM(CASE
                WHEN l.invoice_id IS NOT NULL
                 AND YEAR(h.invoice_date) = :target_year
                 AND MONTH(h.invoice_date) = :target_month
                THEN h.grand_total ELSE 0 END), 0) AS invoice_total
        FROM core_users u
        LEFT JOIN sales_targets t
            ON t.user_id = u.id
           AND t.target_year = :target_year
           AND t.target_month = :target_month
        LEFT JOIN sales_orders o ON o.sales_user_id = u.id
        LEFT JOIN sales_order_invoice_links l ON l.order_id = o.id
        LEFT JOIN invoice_headers h ON h.id = l.invoice_id
        WHERE u.status = 1
        GROUP BY u.id, u.full_name, t.target_amount
        ORDER BY invoice_total DESC, order_total DESC, u.full_name ASC
    ', [
        'target_year' => $year,
        'target_month' => $month,
    ]);
}

function sales_customer_price(PDO $db, int $cariId, ?int $productId, float $quantity = 1): ?array
{
    if ($cariId <= 0 || !$productId) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT price_amount, currency_code, min_quantity, valid_from, valid_until, notes
        FROM sales_customer_prices
        WHERE cari_id = :cari_id
          AND product_id = :product_id
          AND status = 1
          AND min_quantity <= :quantity
          AND (valid_from IS NULL OR valid_from <= CURDATE())
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY min_quantity DESC, id DESC
        LIMIT 1
    ', [
        'cari_id' => $cariId,
        'product_id' => $productId,
        'quantity' => $quantity,
    ]);

    return $rows[0] ?? null;
}

function sales_discount_row(PDO $db, int $cariId, float $subtotal): ?array
{
    if ($subtotal <= 0) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT id, rule_name, discount_type, discount_value, min_amount
        FROM sales_discount_rules
        WHERE status = 1
          AND (cari_id IS NULL OR cari_id = :cari_id)
          AND min_amount <= :subtotal
          AND (valid_from IS NULL OR valid_from <= CURDATE())
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY CASE WHEN cari_id = :cari_id THEN 0 ELSE 1 END, min_amount DESC, id DESC
        LIMIT 1
    ', [
        'cari_id' => $cariId,
        'subtotal' => $subtotal,
    ]);

    return $rows[0] ?? null;
}

function sales_discount_amount(?array $discountRow, float $subtotal): float
{
    if (!$discountRow || $subtotal <= 0) {
        return 0.0;
    }

    if (($discountRow['discount_type'] ?? '') === 'oran') {
        return round($subtotal * ((float) $discountRow['discount_value'] / 100), 2);
    }

    return min($subtotal, (float) $discountRow['discount_value']);
}

function sales_promotion_row(PDO $db, int $cariId, ?int $productId, float $quantity, float $amount): ?array
{
    if ($amount <= 0) {
        return null;
    }

    $rows = app_fetch_all($db, '
        SELECT id, promo_name, promo_type, promo_value, min_quantity, min_amount
        FROM sales_promotion_rules
        WHERE status = 1
          AND (cari_id IS NULL OR cari_id = :cari_id)
          AND (product_id IS NULL OR product_id = :product_id)
          AND min_quantity <= :quantity
          AND min_amount <= :amount
          AND (valid_from IS NULL OR valid_from <= CURDATE())
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY
            CASE WHEN cari_id = :cari_id THEN 0 ELSE 1 END,
            CASE WHEN product_id = :product_id THEN 0 ELSE 1 END,
            min_amount DESC,
            min_quantity DESC,
            id DESC
        LIMIT 1
    ', [
        'cari_id' => $cariId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'amount' => $amount,
    ]);

    return $rows[0] ?? null;
}

function sales_promotion_amount(?array $promotionRow, float $amount): float
{
    if (!$promotionRow || $amount <= 0) {
        return 0.0;
    }

    if (($promotionRow['promo_type'] ?? '') === 'oran') {
        return round($amount * ((float) $promotionRow['promo_value'] / 100), 2);
    }

    return min($amount, (float) $promotionRow['promo_value']);
}

function sales_offer_snapshot(PDO $db, int $offerId): array
{
    $offerRows = app_fetch_all($db, '
        SELECT id, cari_id, offer_no, offer_date, valid_until, status, subtotal, grand_total, notes
        FROM sales_offers
        WHERE id = :id
        LIMIT 1
    ', ['id' => $offerId]);

    if (!$offerRows) {
        throw new RuntimeException('Teklif kaydi bulunamadi.');
    }

    $itemRows = app_fetch_all($db, '
        SELECT product_id, description, quantity, unit_price, line_total
        FROM sales_offer_items
        WHERE offer_id = :offer_id
        ORDER BY id ASC
    ', ['offer_id' => $offerId]);

    return [
        'offer' => $offerRows[0],
        'items' => $itemRows,
    ];
}

function sales_offer_log_revision(PDO $db, int $offerId, string $note = ''): void
{
    $snapshot = sales_offer_snapshot($db, $offerId);
    $revisionNo = (int) app_metric($db, 'SELECT COALESCE(MAX(revision_no), 0) FROM sales_offer_revisions WHERE offer_id = :offer_id', ['offer_id' => $offerId]) + 1;

    $stmt = $db->prepare('
        INSERT INTO sales_offer_revisions (
            offer_id, revision_no, version_label, status, valid_until, subtotal, grand_total, notes, snapshot_json, created_by
        ) VALUES (
            :offer_id, :revision_no, :version_label, :status, :valid_until, :subtotal, :grand_total, :notes, :snapshot_json, :created_by
        )
    ');
    $stmt->execute([
        'offer_id' => $offerId,
        'revision_no' => $revisionNo,
        'version_label' => 'v' . $revisionNo,
        'status' => (string) $snapshot['offer']['status'],
        'valid_until' => $snapshot['offer']['valid_until'] ?: null,
        'subtotal' => (float) $snapshot['offer']['subtotal'],
        'grand_total' => (float) $snapshot['offer']['grand_total'],
        'notes' => $note !== '' ? $note : ($snapshot['offer']['notes'] ?? null),
        'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_by' => sales_current_user_id(),
    ]);
}

function sales_offer_log_approval(PDO $db, int $offerId, string $actionName, ?string $previousStatus, ?string $currentStatus, string $note = ''): void
{
    $stmt = $db->prepare('
        INSERT INTO sales_offer_approval_logs (offer_id, action_name, previous_status, current_status, note_text, created_by)
        VALUES (:offer_id, :action_name, :previous_status, :current_status, :note_text, :created_by)
    ');
    $stmt->execute([
        'offer_id' => $offerId,
        'action_name' => $actionName,
        'previous_status' => $previousStatus,
        'current_status' => $currentStatus,
        'note_text' => $note !== '' ? $note : null,
        'created_by' => sales_current_user_id(),
    ]);
}

function sales_update_offer_status(PDO $db, int $offerId, string $status, string $actionName = 'durum_guncelle', string $note = ''): void
{
    $allowedStatuses = ['taslak', 'gonderildi', 'onaylandi', 'reddedildi'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new RuntimeException('Teklif durumu gecersiz.');
    }

    $offerRows = app_fetch_all($db, '
        SELECT id, offer_no, status
        FROM sales_offers
        WHERE id = :id
        LIMIT 1
    ', ['id' => $offerId]);

    if (!$offerRows) {
        throw new RuntimeException('Gecerli bir teklif secilmedi.');
    }
    app_assert_branch_access($db, 'sales_offers', $offerId);

    $previousStatus = (string) $offerRows[0]['status'];

    $stmt = $db->prepare('UPDATE sales_offers SET status = :status WHERE id = :id');
    $stmt->execute([
        'status' => $status,
        'id' => $offerId,
    ]);

    sales_offer_log_approval($db, $offerId, $actionName, $previousStatus, $status, $note);
    app_audit_log('satis', $actionName, 'sales_offers', $offerId, $offerRows[0]['offer_no'] . ' teklifi ' . $status . ' durumuna alindi.');
}

function sales_order_stock_deduct(PDO $db, int $orderId, int $warehouseId): void
{
    $alreadyDeducted = (int) app_metric($db, "
        SELECT COUNT(*) FROM stock_movements
        WHERE reference_type = 'satis_siparis' AND reference_id = :reference_id
    ", ['reference_id' => $orderId]) > 0;

    if ($alreadyDeducted) {
        return;
    }

    if ($warehouseId <= 0) {
        throw new RuntimeException('Stok dusumu icin depo secimi zorunludur.');
    }

    $items = app_fetch_all($db, '
        SELECT i.product_id, i.quantity, p.purchase_price
        FROM sales_order_items i
        LEFT JOIN stock_products p ON p.id = i.product_id
        WHERE i.order_id = :order_id AND i.product_id IS NOT NULL
    ', ['order_id' => $orderId]);

    foreach ($items as $item) {
        app_insert_stock_movement($db, [
            'warehouse_id' => $warehouseId,
            'product_id' => (int) $item['product_id'],
            'movement_type' => 'cikis',
            'quantity' => (float) $item['quantity'],
            'unit_cost' => (float) ($item['purchase_price'] ?? 0),
            'reference_type' => 'satis_siparis',
            'reference_id' => $orderId,
            'movement_date' => date('Y-m-d H:i:s'),
        ]);
    }
}

function sales_sync_order_delivery(PDO $db, int $orderId, string $status, ?string $deliveryStatus = null, ?string $deliveredAt = null): void
{
    app_assert_branch_access($db, 'sales_orders', $orderId);

    $stmt = $db->prepare('
        UPDATE sales_orders
        SET status = :status,
            delivery_status = :delivery_status,
            delivered_at = :delivered_at
        WHERE id = :id
    ');
    $stmt->execute([
        'status' => $status,
        'delivery_status' => $deliveryStatus ?? ($status === 'tamamlandi' ? 'teslim_edildi' : ($status === 'sevk' ? 'yolda' : 'hazirlaniyor')),
        'delivered_at' => $deliveredAt,
        'id' => $orderId,
    ]);
}

function sales_link_invoice_to_shipments(PDO $db, int $orderId, int $invoiceId, ?int $singleShipmentId = null): void
{
    if ($orderId <= 0 || $invoiceId <= 0) {
        return;
    }

    $params = ['order_id' => $orderId];
    $sql = '
        SELECT id
        FROM sales_shipments
        WHERE order_id = :order_id
    ';
    if ($singleShipmentId !== null && $singleShipmentId > 0) {
        $sql .= ' AND id = :shipment_id';
        $params['shipment_id'] = $singleShipmentId;
    }

    $shipmentRows = app_fetch_all($db, $sql, $params);
    if ($shipmentRows === []) {
        return;
    }

    $stmt = $db->prepare('
        INSERT IGNORE INTO sales_shipment_invoice_links (shipment_id, invoice_id)
        VALUES (:shipment_id, :invoice_id)
    ');
    foreach ($shipmentRows as $shipmentRow) {
        $stmt->execute([
            'shipment_id' => (int) $shipmentRow['id'],
            'invoice_id' => $invoiceId,
        ]);
    }
}

function sales_create_invoice_from_order_id(PDO $db, int $orderId, bool $skipIfAlreadyInvoiced = false): array
{
    if ($orderId <= 0) {
        throw new RuntimeException('Gecerli bir siparis secilmedi.');
    }

    $order = app_fetch_all($db, '
        SELECT id, cari_id, order_no, order_date, status
        FROM sales_orders
        WHERE id = :id
        LIMIT 1
    ', ['id' => $orderId]);
    $items = app_fetch_all($db, '
        SELECT product_id, description, quantity, unit_price, line_total
        FROM sales_order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ', ['order_id' => $orderId]);

    if (!$order || !$items) {
        throw new RuntimeException('Siparis verisi bulunamadi.');
    }
    app_assert_branch_access($db, 'sales_orders', $orderId);

    $orderRow = $order[0];
    if ((string) $orderRow['status'] === 'iptal') {
        throw new RuntimeException($orderRow['order_no'] . ' iptal oldugu icin faturalanamaz.');
    }

    $existingInvoiceCount = (int) app_metric($db, '
        SELECT COUNT(*)
        FROM sales_order_invoice_links
        WHERE order_id = :order_id
    ', ['order_id' => $orderId]);

    if ($skipIfAlreadyInvoiced && $existingInvoiceCount > 0) {
        return [
            'created' => false,
            'skipped' => true,
            'reason' => 'already_invoiced',
            'order_no' => (string) $orderRow['order_no'],
            'invoice_id' => null,
        ];
    }

    $invoiceItems = [];
    foreach ($items as $item) {
        $invoiceItems[] = [
            'product_id' => (int) ($item['product_id'] ?? 0) ?: null,
            'description' => $item['description'],
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'vat_rate' => 20,
            'line_total' => (float) $item['line_total'],
        ];
    }

    $invoiceId = app_create_invoice_from_source($db, [
        'branch_id' => app_default_branch_id($db),
        'cari_id' => (int) $orderRow['cari_id'],
        'invoice_type' => 'satis',
        'invoice_date' => date('Y-m-d'),
        'items' => $invoiceItems,
        'notes' => $orderRow['order_no'] . ' siparisinden otomatik olusturuldu.',
        'source_module' => 'satis',
        'movement_description' => $orderRow['order_no'] . ' siparisinden otomatik satis faturasi',
    ]);

    $stmt = $db->prepare('
        INSERT IGNORE INTO sales_order_invoice_links (order_id, invoice_id)
        VALUES (:order_id, :invoice_id)
    ');
    $stmt->execute([
        'order_id' => $orderId,
        'invoice_id' => $invoiceId,
    ]);
    sales_link_invoice_to_shipments($db, $orderId, $invoiceId);
    app_audit_log('satis', 'order_invoice', 'sales_orders', $orderId, $orderRow['order_no'] . ' siparisi faturaya cevrildi.');

    return [
        'created' => true,
        'skipped' => false,
        'reason' => null,
        'order_no' => (string) $orderRow['order_no'],
        'invoice_id' => $invoiceId,
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_offer') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0) ?: null;
            $templateId = (int) ($_POST['template_id'] ?? 0);
            $templateRow = sales_offer_template($db, $templateId);
            $productId = (int) ($_POST['product_id'] ?? 0) ?: (isset($templateRow['product_id']) ? (int) $templateRow['product_id'] : null);
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($description === '' && $templateRow) {
                $description = (string) $templateRow['default_description'];
            }
            $quantity = (float) ($_POST['quantity'] ?? 0);
            if ($quantity <= 0 && $templateRow) {
                $quantity = (float) $templateRow['default_quantity'];
            }
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            if ($unitPrice <= 0 && $templateRow) {
                $unitPrice = (float) $templateRow['default_unit_price'];
            }
            $lineTotal = $quantity * $unitPrice;
            $discountAmount = max(0, (float) ($_POST['discount_amount'] ?? 0));
            $discountRow = null;
            $subtotal = $lineTotal;
            if ($discountAmount <= 0) {
                $discountRow = sales_discount_row($db, $cariId, $subtotal);
                $discountAmount = sales_discount_amount($discountRow, $subtotal);
            }
            $promotionAmount = max(0, (float) ($_POST['promotion_amount'] ?? 0));
            $promotionRow = null;
            $discountedTotal = max(0, $subtotal - $discountAmount);
            if ($promotionAmount <= 0) {
                $promotionRow = sales_promotion_row($db, $cariId, $productId, $quantity, $discountedTotal);
                $promotionAmount = sales_promotion_amount($promotionRow, $discountedTotal);
            }
            $grandTotal = max(0, $discountedTotal - $promotionAmount);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($templateRow) {
                $templateNote = 'Kullanilan sablon: ' . (string) $templateRow['template_name'];
                $notes = $notes !== '' ? ($notes . PHP_EOL . $templateNote) : $templateNote;
                if (!empty($templateRow['notes'])) {
                    $notes .= PHP_EOL . (string) $templateRow['notes'];
                }
            }
            if ($discountAmount > 0) {
                $discountNote = $discountRow
                    ? sprintf('Uygulanan iskonto: %s / %s', (string) $discountRow['rule_name'], number_format($discountAmount, 2, ',', '.') . ' TRY')
                    : sprintf('Manuel iskonto uygulandi: %s', number_format($discountAmount, 2, ',', '.') . ' TRY');
                $notes = $notes !== '' ? ($notes . PHP_EOL . $discountNote) : $discountNote;
            }
            if ($promotionAmount > 0) {
                $promotionNote = $promotionRow
                    ? sprintf('Uygulanan kampanya: %s / %s', (string) $promotionRow['promo_name'], number_format($promotionAmount, 2, ',', '.') . ' TRY')
                    : sprintf('Manuel promosyon uygulandi: %s', number_format($promotionAmount, 2, ',', '.') . ' TRY');
                $notes = $notes !== '' ? ($notes . PHP_EOL . $promotionNote) : $promotionNote;
            }

            if ($cariId <= 0 || $description === '' || $quantity <= 0) {
                throw new RuntimeException('Cari, kalem aciklamasi ve miktar zorunludur.');
            }

            $offerNo = sales_next_number($db, 'sales_offers', 'TKL');

            $stmt = $db->prepare('
                INSERT INTO sales_offers (branch_id, cari_id, sales_user_id, offer_no, offer_date, valid_until, status, subtotal, grand_total, notes)
                VALUES (:branch_id, :cari_id, :sales_user_id, :offer_no, :offer_date, :valid_until, :status, :subtotal, :grand_total, :notes)
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'cari_id' => $cariId,
                'sales_user_id' => $salesUserId,
                'offer_no' => $offerNo,
                'offer_date' => date('Y-m-d'),
                'valid_until' => trim((string) ($_POST['valid_until'] ?? '')) ?: ($templateRow ? date('Y-m-d', strtotime('+' . max(1, (int) $templateRow['valid_day_count']) . ' days')) : null),
                'status' => $_POST['status'] ?? ($templateRow['default_status'] ?? 'taslak'),
                'subtotal' => $subtotal,
                'grand_total' => $grandTotal,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $offerId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO sales_offer_items (offer_id, product_id, description, quantity, unit_price, line_total)
                VALUES (:offer_id, :product_id, :description, :quantity, :unit_price, :line_total)
            ');
            $stmt->execute([
                'offer_id' => $offerId,
                'product_id' => $productId,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            sales_offer_log_revision($db, $offerId, 'Ilk teklif versiyonu olusturuldu.');
            sales_offer_log_approval($db, $offerId, 'olusturuldu', null, (string) ($_POST['status'] ?? 'taslak'), 'Teklif ilk kez kaydedildi.');
            app_audit_log('satis', 'create_offer', 'sales_offers', $offerId, $offerNo . ' teklifi olusturuldu.');

            sales_post_redirect('offer');
        }

        if ($action === 'save_offer_template') {
            $templateName = trim((string) ($_POST['template_name'] ?? ''));
            $categoryName = trim((string) ($_POST['category_name'] ?? ''));
            $productId = (int) ($_POST['template_product_id'] ?? 0) ?: null;
            $defaultDescription = trim((string) ($_POST['default_description'] ?? ''));
            $defaultQuantity = max(0.001, (float) ($_POST['default_quantity'] ?? 1));
            $defaultUnitPrice = max(0, (float) ($_POST['default_unit_price'] ?? 0));
            $defaultStatus = trim((string) ($_POST['default_status'] ?? 'taslak'));
            $validDayCount = max(1, (int) ($_POST['valid_day_count'] ?? 15));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($templateName === '' || $defaultDescription === '' || !in_array($defaultStatus, ['taslak', 'gonderildi', 'onaylandi', 'reddedildi'], true)) {
                throw new RuntimeException('Teklif sablonu icin ad, aciklama ve durum zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_offer_templates (
                    template_name, category_name, product_id, default_description, default_quantity, default_unit_price, default_status, valid_day_count, notes, status
                ) VALUES (
                    :template_name, :category_name, :product_id, :default_description, :default_quantity, :default_unit_price, :default_status, :valid_day_count, :notes, 1
                )
            ');
            $stmt->execute([
                'template_name' => $templateName,
                'category_name' => $categoryName !== '' ? $categoryName : null,
                'product_id' => $productId,
                'default_description' => $defaultDescription,
                'default_quantity' => $defaultQuantity,
                'default_unit_price' => $defaultUnitPrice,
                'default_status' => $defaultStatus,
                'valid_day_count' => $validDayCount,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'offer_template_save', 'sales_offer_templates', (int) $db->lastInsertId(), 'Teklif sablonu kaydedildi.');
            sales_post_redirect('offer_template');
        }

        if ($action === 'create_order') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $salesUserId = (int) ($_POST['sales_user_id'] ?? 0) ?: null;
            $productId = (int) ($_POST['product_id'] ?? 0) ?: null;
            $description = trim((string) ($_POST['description'] ?? ''));
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            $lineTotal = $quantity * $unitPrice;
            $discountAmount = max(0, (float) ($_POST['discount_amount'] ?? 0));
            $discountRow = null;
            if ($discountAmount <= 0) {
                $discountRow = sales_discount_row($db, $cariId, $lineTotal);
                $discountAmount = sales_discount_amount($discountRow, $lineTotal);
            }
            $promotionAmount = max(0, (float) ($_POST['promotion_amount'] ?? 0));
            $promotionRow = null;
            $discountedTotal = max(0, $lineTotal - $discountAmount);
            if ($promotionAmount <= 0) {
                $promotionRow = sales_promotion_row($db, $cariId, $productId, $quantity, $discountedTotal);
                $promotionAmount = sales_promotion_amount($promotionRow, $discountedTotal);
            }
            $grandTotal = max(0, $discountedTotal - $promotionAmount);
            $offerId = (int) ($_POST['offer_id'] ?? 0) ?: null;
            $providerId = (int) ($_POST['cargo_provider_id'] ?? 0) ?: null;
            $cargoCompany = trim((string) ($_POST['cargo_company'] ?? ''));
            $notes = '';
            if ($discountAmount > 0) {
                $notes = $discountRow
                    ? sprintf('Uygulanan iskonto: %s / %s', (string) $discountRow['rule_name'], number_format($discountAmount, 2, ',', '.') . ' TRY')
                    : sprintf('Manuel iskonto uygulandi: %s', number_format($discountAmount, 2, ',', '.') . ' TRY');
            }
            if ($promotionAmount > 0) {
                $promotionNote = $promotionRow
                    ? sprintf('Uygulanan kampanya: %s / %s', (string) $promotionRow['promo_name'], number_format($promotionAmount, 2, ',', '.') . ' TRY')
                    : sprintf('Manuel promosyon uygulandi: %s', number_format($promotionAmount, 2, ',', '.') . ' TRY');
                $notes = $notes !== '' ? ($notes . ' | ' . $promotionNote) : $promotionNote;
            }

            if ($cariId <= 0 || $description === '' || $quantity <= 0) {
                throw new RuntimeException('Cari, kalem aciklamasi ve miktar zorunludur.');
            }

            $orderNo = sales_next_number($db, 'sales_orders', 'SIP');

            $stmt = $db->prepare('
                INSERT INTO sales_orders (branch_id, cari_id, offer_id, sales_user_id, cargo_provider_id, order_no, order_date, status, delivery_status, grand_total, cargo_company, tracking_no)
                VALUES (:branch_id, :cari_id, :offer_id, :sales_user_id, :cargo_provider_id, :order_no, :order_date, :status, :delivery_status, :grand_total, :cargo_company, :tracking_no)
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'cari_id' => $cariId,
                'offer_id' => $offerId,
                'sales_user_id' => $salesUserId,
                'cargo_provider_id' => $providerId,
                'order_no' => $orderNo,
                'order_date' => date('Y-m-d'),
                'status' => $_POST['status'] ?? 'bekliyor',
                'delivery_status' => in_array(($_POST['status'] ?? 'bekliyor'), ['sevk', 'tamamlandi'], true) ? 'yolda' : 'bekliyor',
                'grand_total' => $grandTotal,
                'cargo_company' => $cargoCompany !== '' ? ($notes !== '' ? $cargoCompany . ' | ' . $notes : $cargoCompany) : ($notes !== '' ? $notes : null),
                'tracking_no' => trim((string) ($_POST['tracking_no'] ?? '')) ?: null,
            ]);

            $orderId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO sales_order_items (order_id, product_id, description, quantity, unit_price, line_total)
                VALUES (:order_id, :product_id, :description, :quantity, :unit_price, :line_total)
            ');
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => $productId,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            sales_post_redirect('order');
        }

        if ($action === 'create_cargo_provider') {
            $providerName = trim((string) ($_POST['provider_name'] ?? ''));
            $providerCode = strtoupper(trim((string) ($_POST['provider_code'] ?? '')));

            if ($providerName === '' || $providerCode === '') {
                throw new RuntimeException('Kargo saglayici adi ve kodu zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_cargo_providers (provider_name, provider_code, api_mode, account_no, sender_city, status)
                VALUES (:provider_name, :provider_code, :api_mode, :account_no, :sender_city, 1)
            ');
            $stmt->execute([
                'provider_name' => $providerName,
                'provider_code' => $providerCode,
                'api_mode' => $_POST['api_mode'] ?? 'mock',
                'account_no' => trim((string) ($_POST['account_no'] ?? '')) ?: null,
                'sender_city' => trim((string) ($_POST['sender_city'] ?? '')) ?: null,
            ]);

            app_audit_log('satis', 'cargo_provider_create', 'sales_cargo_providers', (int) $db->lastInsertId(), $providerName . ' kargo saglayicisi eklendi.');
            sales_post_redirect('cargo_provider');
        }

        if ($action === 'save_commission_rule') {
            $userId = (int) ($_POST['commission_user_id'] ?? 0);
            $ratePercent = (float) ($_POST['rate_percent'] ?? 0);
            $targetAmount = (float) ($_POST['target_amount'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($userId <= 0) {
                throw new RuntimeException('Komisyon kurali icin temsilci secilmedi.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_commission_rules (user_id, rate_percent, target_amount, notes, status)
                VALUES (:user_id, :rate_percent, :target_amount, :notes, 1)
                ON DUPLICATE KEY UPDATE
                    rate_percent = VALUES(rate_percent),
                    target_amount = VALUES(target_amount),
                    notes = VALUES(notes),
                    status = 1
            ');
            $stmt->execute([
                'user_id' => $userId,
                'rate_percent' => $ratePercent,
                'target_amount' => $targetAmount,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'commission_rule_save', 'sales_commission_rules', $userId, 'Komisyon kurali kaydedildi.');
            sales_post_redirect('commission_rule');
        }

        if ($action === 'save_sales_target') {
            $userId = (int) ($_POST['target_user_id'] ?? 0);
            $targetYear = max(2024, (int) ($_POST['target_year'] ?? (int) date('Y')));
            $targetMonth = max(1, min(12, (int) ($_POST['target_month'] ?? (int) date('n'))));
            $targetAmount = (float) ($_POST['target_amount'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($userId <= 0) {
                throw new RuntimeException('Satis hedefi icin temsilci secilmedi.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_targets (user_id, target_year, target_month, target_amount, notes, status)
                VALUES (:user_id, :target_year, :target_month, :target_amount, :notes, 1)
                ON DUPLICATE KEY UPDATE
                    target_amount = VALUES(target_amount),
                    notes = VALUES(notes),
                    status = 1
            ');
            $stmt->execute([
                'user_id' => $userId,
                'target_year' => $targetYear,
                'target_month' => $targetMonth,
                'target_amount' => $targetAmount,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'sales_target_save', 'sales_targets', $userId, 'Satis hedefi kaydedildi.');
            sales_post_redirect('sales_target');
        }

        if ($action === 'save_customer_price') {
            $cariId = (int) ($_POST['price_cari_id'] ?? 0);
            $productId = (int) ($_POST['price_product_id'] ?? 0);
            $priceAmount = (float) ($_POST['price_amount'] ?? 0);
            $minQuantity = max(0.001, (float) ($_POST['min_quantity'] ?? 1));
            $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
            $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($cariId <= 0 || $productId <= 0 || $priceAmount < 0) {
                throw new RuntimeException('Musteri bazli fiyat icin cari, urun ve fiyat zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_customer_prices (
                    cari_id, product_id, price_amount, currency_code, min_quantity, valid_from, valid_until, notes, status
                ) VALUES (
                    :cari_id, :product_id, :price_amount, :currency_code, :min_quantity, :valid_from, :valid_until, :notes, 1
                )
                ON DUPLICATE KEY UPDATE
                    price_amount = VALUES(price_amount),
                    currency_code = VALUES(currency_code),
                    valid_from = VALUES(valid_from),
                    valid_until = VALUES(valid_until),
                    notes = VALUES(notes),
                    status = 1
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'product_id' => $productId,
                'price_amount' => $priceAmount,
                'currency_code' => 'TRY',
                'min_quantity' => $minQuantity,
                'valid_from' => $validFrom !== '' ? $validFrom : null,
                'valid_until' => $validUntil !== '' ? $validUntil : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'customer_price_save', 'sales_customer_prices', $cariId, 'Musteri bazli fiyat kaydedildi.');
            sales_post_redirect('customer_price');
        }

        if ($action === 'save_discount_rule') {
            $cariId = (int) ($_POST['discount_cari_id'] ?? 0) ?: null;
            $ruleName = trim((string) ($_POST['rule_name'] ?? ''));
            $discountType = trim((string) ($_POST['discount_type'] ?? 'oran'));
            $discountValue = max(0, (float) ($_POST['discount_value'] ?? 0));
            $minAmount = max(0, (float) ($_POST['min_amount'] ?? 0));
            $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
            $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($ruleName === '' || !in_array($discountType, ['oran', 'tutar'], true)) {
                throw new RuntimeException('Iskonto kurali icin ad ve tip zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_discount_rules (
                    cari_id, rule_name, discount_type, discount_value, min_amount, valid_from, valid_until, notes, status
                ) VALUES (
                    :cari_id, :rule_name, :discount_type, :discount_value, :min_amount, :valid_from, :valid_until, :notes, 1
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'rule_name' => $ruleName,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'min_amount' => $minAmount,
                'valid_from' => $validFrom !== '' ? $validFrom : null,
                'valid_until' => $validUntil !== '' ? $validUntil : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'discount_rule_save', 'sales_discount_rules', (int) $db->lastInsertId(), 'Iskonto kurali kaydedildi.');
            sales_post_redirect('discount_rule');
        }

        if ($action === 'preview_customer_price') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 1);
            $priceRow = sales_customer_price($db, $cariId, $productId ?: null, $quantity);

            if (!$priceRow) {
                throw new RuntimeException('Bu musteri ve urun icin aktif fiyat listesi bulunamadi.');
            }

            $feedback = 'price:' . number_format((float) $priceRow['price_amount'], 2, '.', '');
        }

        if ($action === 'preview_discount') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 1);
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            $manualDiscount = max(0, (float) ($_POST['discount_amount'] ?? 0));
            $subtotal = max(0, $quantity * $unitPrice);

            if ($manualDiscount > 0) {
                $feedback = 'discount:' . number_format($manualDiscount, 2, '.', '');
            } else {
                $discountRow = sales_discount_row($db, $cariId, $subtotal);
                if (!$discountRow) {
                    throw new RuntimeException('Bu cari ve tutar icin aktif iskonto kurali bulunamadi.');
                }

                $feedback = 'discount:' . number_format(sales_discount_amount($discountRow, $subtotal), 2, '.', '');
            }
        }

        if ($action === 'save_promotion_rule') {
            $cariId = (int) ($_POST['promotion_cari_id'] ?? 0) ?: null;
            $productId = (int) ($_POST['promotion_product_id'] ?? 0) ?: null;
            $promoName = trim((string) ($_POST['promo_name'] ?? ''));
            $promoType = trim((string) ($_POST['promo_type'] ?? 'oran'));
            $promoValue = max(0, (float) ($_POST['promo_value'] ?? 0));
            $minQuantity = max(0.001, (float) ($_POST['min_quantity'] ?? 1));
            $minAmount = max(0, (float) ($_POST['min_amount'] ?? 0));
            $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
            $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($promoName === '' || !in_array($promoType, ['oran', 'tutar'], true)) {
                throw new RuntimeException('Kampanya kurali icin ad ve tip zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO sales_promotion_rules (
                    cari_id, product_id, promo_name, promo_type, promo_value, min_quantity, min_amount, valid_from, valid_until, notes, status
                ) VALUES (
                    :cari_id, :product_id, :promo_name, :promo_type, :promo_value, :min_quantity, :min_amount, :valid_from, :valid_until, :notes, 1
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'product_id' => $productId,
                'promo_name' => $promoName,
                'promo_type' => $promoType,
                'promo_value' => $promoValue,
                'min_quantity' => $minQuantity,
                'min_amount' => $minAmount,
                'valid_from' => $validFrom !== '' ? $validFrom : null,
                'valid_until' => $validUntil !== '' ? $validUntil : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            app_audit_log('satis', 'promotion_rule_save', 'sales_promotion_rules', (int) $db->lastInsertId(), 'Kampanya kurali kaydedildi.');
            sales_post_redirect('promotion_rule');
        }

        if ($action === 'preview_promotion') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0) ?: null;
            $quantity = (float) ($_POST['quantity'] ?? 1);
            $unitPrice = (float) ($_POST['unit_price'] ?? 0);
            $discountAmount = max(0, (float) ($_POST['discount_amount'] ?? 0));
            $manualPromotion = max(0, (float) ($_POST['promotion_amount'] ?? 0));
            $subtotal = max(0, $quantity * $unitPrice);
            $discountRow = $discountAmount > 0 ? null : sales_discount_row($db, $cariId, $subtotal);
            $netAmount = max(0, $subtotal - ($discountAmount > 0 ? $discountAmount : sales_discount_amount($discountRow, $subtotal)));

            if ($manualPromotion > 0) {
                $feedback = 'promotion:' . number_format($manualPromotion, 2, '.', '');
            } else {
                $promotionRow = sales_promotion_row($db, $cariId, $productId, $quantity, $netAmount);
                if (!$promotionRow) {
                    throw new RuntimeException('Bu kayit icin aktif kampanya / promosyon bulunamadi.');
                }

                $feedback = 'promotion:' . number_format(sales_promotion_amount($promotionRow, $netAmount), 2, '.', '');
            }
        }

        if ($action === 'preview_offer_template') {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            $templateRow = sales_offer_template($db, $templateId);
            if (!$templateRow) {
                throw new RuntimeException('Secilen teklif sablonu bulunamadi.');
            }

            $feedback = 'template:' . (string) $templateRow['template_name'];
        }

        if ($action === 'update_offer_status') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'taslak'));

            if ($offerId <= 0) {
                throw new RuntimeException('Gecerli bir teklif secilmedi.');
            }

            sales_update_offer_status($db, $offerId, $status, 'manuel_durum');

            sales_post_redirect('offer_status');
        }

        if ($action === 'bulk_update_offer_status') {
            $offerIds = sales_selected_ids('offer_ids');
            $status = trim((string) ($_POST['bulk_status'] ?? 'taslak'));
            $allowedStatuses = ['taslak', 'gonderildi', 'onaylandi', 'reddedildi'];

            if ($offerIds === [] || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Teklif secimi veya toplu durum gecersiz.');
            }

            foreach ($offerIds as $offerId) {
                sales_update_offer_status($db, $offerId, $status, 'toplu_durum');
            }

            sales_post_redirect('offer_bulk_status');
        }

        if ($action === 'create_offer_revision') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            $revisionNote = trim((string) ($_POST['revision_note'] ?? ''));
            $validUntil = trim((string) ($_POST['valid_until'] ?? ''));

            if ($offerId <= 0) {
                throw new RuntimeException('Revizyon icin teklif secilmedi.');
            }

            $offerRows = app_fetch_all($db, '
                SELECT id, status, notes
                FROM sales_offers
                WHERE id = :id
                LIMIT 1
            ', ['id' => $offerId]);

            if (!$offerRows) {
                throw new RuntimeException('Teklif bulunamadi.');
            }

            $previousStatus = (string) $offerRows[0]['status'];
            $stmt = $db->prepare('
                UPDATE sales_offers
                SET status = :status, valid_until = :valid_until, notes = :notes
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => 'taslak',
                'valid_until' => $validUntil !== '' ? $validUntil : null,
                'notes' => $revisionNote !== '' ? $revisionNote : ($offerRows[0]['notes'] ?: null),
                'id' => $offerId,
            ]);

            sales_offer_log_revision($db, $offerId, $revisionNote !== '' ? $revisionNote : 'Teklif revizyonu acildi.');
            sales_offer_log_approval($db, $offerId, 'revizyon', $previousStatus, 'taslak', $revisionNote);
            app_audit_log('satis', 'offer_revision', 'sales_offers', $offerId, 'Teklif icin yeni revizyon acildi.');

            sales_post_redirect('offer_revision');
        }

        if ($action === 'submit_offer_approval') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            if ($offerId <= 0) {
                throw new RuntimeException('Onay icin teklif secilmedi.');
            }

            sales_update_offer_status($db, $offerId, 'gonderildi', 'onaya_gonder', trim((string) ($_POST['approval_note'] ?? '')));
            sales_post_redirect('offer_submit');
        }

        if ($action === 'approve_offer') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            if ($offerId <= 0) {
                throw new RuntimeException('Onay icin teklif secilmedi.');
            }

            $approvalNote = trim((string) ($_POST['approval_note'] ?? ''));
            $approvalResult = sales_offer_approval_guard_result($db, $authUser, $offerId, $approvalNote);
            if (empty($approvalResult['finalize'])) {
                sales_post_redirect((string) ($approvalResult['redirect'] ?? 'offer_waiting_second_approval'));
            }

            sales_update_offer_status($db, $offerId, 'onaylandi', (string) ($approvalResult['action_name'] ?? 'onayla'), $approvalNote);
            sales_post_redirect('offer_approved');
        }

        if ($action === 'reject_offer') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            if ($offerId <= 0) {
                throw new RuntimeException('Red icin teklif secilmedi.');
            }

            sales_update_offer_status($db, $offerId, 'reddedildi', 'reddet', trim((string) ($_POST['approval_note'] ?? '')));
            sales_post_redirect('offer_rejected');
        }

        if ($action === 'bulk_export_offers_csv') {
            $offerIds = sales_selected_ids('offer_ids');
            if ($offerIds === []) {
                throw new RuntimeException('CSV icin teklif secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($offerIds), '?'));
            $rows = app_fetch_all($db, "
                SELECT o.offer_no, c.company_name, c.full_name, o.offer_date, o.status, o.grand_total
                FROM sales_offers o
                INNER JOIN cari_cards c ON c.id = o.cari_id
                WHERE o.id IN ({$placeholders})
                ORDER BY o.id DESC
            ", $offerIds);

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['offer_no'],
                    $row['company_name'] ?: $row['full_name'] ?: '-',
                    $row['offer_date'],
                    $row['status'],
                    number_format((float) $row['grand_total'], 2, '.', ''),
                ];
            }

            app_csv_download('secili-teklifler.csv', ['Teklif No', 'Cari', 'Tarih', 'Durum', 'Toplam'], $exportRows);
        }

        if ($action === 'update_order_status') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'bekliyor'));

            if ($orderId <= 0) {
                throw new RuntimeException('Gecerli bir siparis secilmedi.');
            }

            $allowedStatuses = ['bekliyor', 'hazirlaniyor', 'sevk', 'tamamlandi', 'iptal'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Siparis durumu gecersiz.');
            }

            $orderRows = app_fetch_all($db, '
                SELECT id, order_no, status
                FROM sales_orders
                WHERE id = :id
                LIMIT 1
            ', ['id' => $orderId]);

            if (!$orderRows) {
                throw new RuntimeException('Siparis kaydi bulunamadi.');
            }

            $orderRow = $orderRows[0];
            $alreadyDeducted = (int) app_metric($db, "
                SELECT COUNT(*) FROM stock_movements
                WHERE reference_type = 'satis_siparis' AND reference_id = :reference_id
            ", ['reference_id' => $orderId]) > 0;

            if ($alreadyDeducted && !in_array($status, ['sevk', 'tamamlandi'], true)) {
                throw new RuntimeException('Stok cikisi yapilmis siparis geri durumlara cekilemez.');
            }

            if (!$alreadyDeducted && in_array($status, ['sevk', 'tamamlandi'], true)) {
                $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
                sales_order_stock_deduct($db, $orderId, $warehouseId);
            }

            sales_sync_order_delivery(
                $db,
                $orderId,
                $status,
                $status === 'tamamlandi' ? 'teslim_edildi' : ($status === 'sevk' ? 'yolda' : null),
                $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null
            );

            sales_post_redirect('order_status');
        }

        if ($action === 'bulk_update_order_status') {
            $orderIds = sales_selected_ids('order_ids');
            $status = trim((string) ($_POST['bulk_status'] ?? 'bekliyor'));
            $allowedStatuses = ['bekliyor', 'hazirlaniyor', 'sevk', 'tamamlandi', 'iptal'];

            if ($orderIds === [] || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Siparis secimi veya toplu durum gecersiz.');
            }

            $warehouseId = (int) ($_POST['bulk_warehouse_id'] ?? 0);
            foreach ($orderIds as $orderId) {
                $_POST['order_id'] = $orderId;
                $_POST['status'] = $status;
                $_POST['warehouse_id'] = $warehouseId;
                $action = 'update_order_status';

                $allowedStatuses = ['bekliyor', 'hazirlaniyor', 'sevk', 'tamamlandi', 'iptal'];
                $orderRows = app_fetch_all($db, '
                    SELECT id, order_no, status
                    FROM sales_orders
                    WHERE id = :id
                    LIMIT 1
                ', ['id' => $orderId]);

                if (!$orderRows) {
                    continue;
                }

                $orderRow = $orderRows[0];
                $alreadyDeducted = (int) app_metric($db, "
                    SELECT COUNT(*) FROM stock_movements
                    WHERE reference_type = 'satis_siparis' AND reference_id = :reference_id
                ", ['reference_id' => $orderId]) > 0;

                if ($alreadyDeducted && !in_array($status, ['sevk', 'tamamlandi'], true)) {
                    continue;
                }

                if (!$alreadyDeducted && in_array($status, ['sevk', 'tamamlandi'], true)) {
                    if ($warehouseId <= 0) {
                        throw new RuntimeException('Toplu sevk/tamamlama icin depo secimi zorunludur.');
                    }
                    sales_order_stock_deduct($db, $orderId, $warehouseId);
                }

                sales_sync_order_delivery(
                    $db,
                    $orderId,
                    $status,
                    $status === 'tamamlandi' ? 'teslim_edildi' : ($status === 'sevk' ? 'yolda' : null),
                    $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null
                );
            }

            sales_post_redirect('order_bulk_status');
        }

        if ($action === 'bulk_export_orders_csv') {
            $orderIds = sales_selected_ids('order_ids');
            if ($orderIds === []) {
                throw new RuntimeException('CSV icin siparis secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $rows = app_fetch_all($db, "
                SELECT o.order_no, c.company_name, c.full_name, o.order_date, o.status, o.cargo_company, o.tracking_no, o.grand_total
                FROM sales_orders o
                INNER JOIN cari_cards c ON c.id = o.cari_id
                WHERE o.id IN ({$placeholders})
                ORDER BY o.id DESC
            ", $orderIds);

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['order_no'],
                    $row['company_name'] ?: $row['full_name'] ?: '-',
                    $row['order_date'],
                    $row['status'],
                    $row['cargo_company'] ?: '-',
                    $row['tracking_no'] ?: '-',
                    number_format((float) $row['grand_total'], 2, '.', ''),
                ];
            }

            app_csv_download('secili-siparisler.csv', ['Siparis No', 'Cari', 'Tarih', 'Durum', 'Kargo', 'Takip No', 'Toplam'], $exportRows);
        }

        if ($action === 'bulk_create_invoices_from_orders') {
            $orderIds = sales_selected_ids('order_ids');
            if ($orderIds === []) {
                throw new RuntimeException('Toplu fatura icin siparis secilmedi.');
            }

            $createdCount = 0;
            $skippedCount = 0;
            foreach ($orderIds as $orderId) {
                $result = sales_create_invoice_from_order_id($db, $orderId, true);
                if ($result['created']) {
                    $createdCount++;
                } else {
                    $skippedCount++;
                }
            }

            app_audit_log('satis', 'bulk_order_invoice', 'sales_orders', null, 'Toplu siparis faturasi olusturuldu. Yeni: ' . $createdCount . ', Atlanan: ' . $skippedCount);
            sales_post_redirect('order_bulk_invoice_' . $createdCount . '_' . $skippedCount);
        }

        if ($action === 'delete_offer') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);

            if ($offerId <= 0) {
                throw new RuntimeException('Gecerli bir teklif secilmedi.');
            }
            app_assert_branch_access($db, 'sales_offers', $offerId);

            $stmt = $db->prepare('DELETE FROM sales_offers WHERE id = :id');
            $stmt->execute(['id' => $offerId]);

            sales_post_redirect('delete_offer');
        }

        if ($action === 'delete_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);

            if ($orderId <= 0) {
                throw new RuntimeException('Gecerli bir siparis secilmedi.');
            }
            app_assert_branch_access($db, 'sales_orders', $orderId);

            $stmt = $db->prepare('DELETE FROM sales_orders WHERE id = :id');
            $stmt->execute(['id' => $orderId]);

            sales_post_redirect('delete_order');
        }

        if ($action === 'create_shipment_from_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $shipmentNote = trim((string) ($_POST['shipment_note'] ?? ''));

            if ($orderId <= 0) {
                throw new RuntimeException('Gecerli bir siparis secilmedi.');
            }

            $orderRows = app_fetch_all($db, '
                SELECT id, order_no, cargo_company, tracking_no, cargo_provider_id
                FROM sales_orders
                WHERE id = :id
                LIMIT 1
            ', ['id' => $orderId]);

            if (!$orderRows) {
                throw new RuntimeException('Siparis kaydi bulunamadi.');
            }

            if ($warehouseId <= 0) {
                throw new RuntimeException('Sevk icin depo secimi zorunludur.');
            }

            sales_order_stock_deduct($db, $orderId, $warehouseId);

            $shipmentNo = sales_next_document_number($db, 'sales_shipments', 'shipment_no', 'SVK');
            $dispatchNo = sales_next_document_number($db, 'sales_shipments', 'irsaliye_no', 'IRS');

            $provider = null;
            if (!empty($orderRows[0]['cargo_provider_id'])) {
                $providerRows = app_fetch_all($db, '
                    SELECT id, provider_name
                    FROM sales_cargo_providers
                    WHERE id = :id
                    LIMIT 1
                ', ['id' => (int) $orderRows[0]['cargo_provider_id']]);
                $provider = $providerRows[0] ?? null;
            }

            $stmt = $db->prepare('
                INSERT INTO sales_shipments (
                    order_id, warehouse_id, provider_id, shipment_no, irsaliye_no, label_no, shipment_date, shipment_status, delivery_status,
                    cargo_company, tracking_no, notes, created_by
                ) VALUES (
                    :order_id, :warehouse_id, :provider_id, :shipment_no, :irsaliye_no, :label_no, :shipment_date, :shipment_status, :delivery_status,
                    :cargo_company, :tracking_no, :notes, :created_by
                )
            ');
            $stmt->execute([
                'order_id' => $orderId,
                'warehouse_id' => $warehouseId,
                'provider_id' => $provider['id'] ?? null,
                'shipment_no' => $shipmentNo,
                'irsaliye_no' => $dispatchNo,
                'label_no' => 'LBL-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT),
                'shipment_date' => date('Y-m-d H:i:s'),
                'shipment_status' => 'sevk_edildi',
                'delivery_status' => 'yolda',
                'cargo_company' => $orderRows[0]['cargo_company'] ?: ($provider['provider_name'] ?? null),
                'tracking_no' => $orderRows[0]['tracking_no'] ?: null,
                'notes' => $shipmentNote !== '' ? $shipmentNote : ($orderRows[0]['order_no'] . ' siparisinden sevk olusturuldu.'),
                'created_by' => sales_current_user_id(),
            ]);

            sales_sync_order_delivery($db, $orderId, 'sevk', 'yolda', null);
            app_audit_log('satis', 'create_shipment', 'sales_orders', $orderId, $orderRows[0]['order_no'] . ' siparisinden sevk/irsaliye olusturuldu.');

            sales_post_redirect('order_shipment');
        }

        if ($action === 'integrate_cargo_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $providerId = (int) ($_POST['provider_id'] ?? 0);

            if ($orderId <= 0 || $providerId <= 0) {
                throw new RuntimeException('Kargo entegrasyonu icin siparis ve saglayici secimi zorunludur.');
            }

            $orderRows = app_fetch_all($db, '
                SELECT id, order_no, cargo_company, tracking_no
                FROM sales_orders
                WHERE id = :id
                LIMIT 1
            ', ['id' => $orderId]);
            $providerRows = app_fetch_all($db, '
                SELECT id, provider_name, provider_code, api_mode, account_no
                FROM sales_cargo_providers
                WHERE id = :id
                LIMIT 1
            ', ['id' => $providerId]);
            $shipmentRows = app_fetch_all($db, '
                SELECT id, shipment_no, label_no
                FROM sales_shipments
                WHERE order_id = :order_id
                ORDER BY id DESC
                LIMIT 1
            ', ['order_id' => $orderId]);

            if (!$orderRows || !$providerRows) {
                throw new RuntimeException('Siparis veya kargo saglayicisi bulunamadi.');
            }

            $orderRow = $orderRows[0];
            $providerRow = $providerRows[0];
            $shipmentRow = $shipmentRows[0] ?? null;
            $trackingNo = $orderRow['tracking_no'] ?: sales_tracking_number($orderId, (string) $providerRow['provider_code']);
            $labelNo = $shipmentRow['label_no'] ?? ('LBL-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT));

            $stmt = $db->prepare('
                UPDATE sales_orders
                SET cargo_provider_id = :provider_id,
                    cargo_company = :cargo_company,
                    tracking_no = :tracking_no
                WHERE id = :id
            ');
            $stmt->execute([
                'provider_id' => $providerId,
                'cargo_company' => $providerRow['provider_name'],
                'tracking_no' => $trackingNo,
                'id' => $orderId,
            ]);

            if ($shipmentRow) {
                $stmt = $db->prepare('
                    UPDATE sales_shipments
                    SET provider_id = :provider_id,
                        cargo_company = :cargo_company,
                        tracking_no = :tracking_no,
                        label_no = :label_no
                    WHERE id = :id
                ');
                $stmt->execute([
                    'provider_id' => $providerId,
                    'cargo_company' => $providerRow['provider_name'],
                    'tracking_no' => $trackingNo,
                    'label_no' => $labelNo,
                    'id' => $shipmentRow['id'],
                ]);
            }

            sales_log_cargo(
                $db,
                $orderId,
                $shipmentRow ? (int) $shipmentRow['id'] : null,
                $providerId,
                'entegrasyon',
                $trackingNo,
                $labelNo,
                ['provider' => $providerRow['provider_code'], 'mode' => $providerRow['api_mode']],
                ['status' => 'ok', 'tracking_no' => $trackingNo, 'label_no' => $labelNo]
            );
            app_audit_log('satis', 'cargo_integrate', 'sales_orders', $orderId, $orderRow['order_no'] . ' siparisi kargo entegrasyonuna gonderildi.');

            sales_post_redirect('cargo_integrated');
        }

        if ($action === 'mark_order_delivered') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new RuntimeException('Teslim icin gecerli bir siparis secilmedi.');
            }

            $orderRows = app_fetch_all($db, '
                SELECT id, order_no
                FROM sales_orders
                WHERE id = :id
                LIMIT 1
            ', ['id' => $orderId]);

            if (!$orderRows) {
                throw new RuntimeException('Siparis kaydi bulunamadi.');
            }

            $stmt = $db->prepare('
                UPDATE sales_shipments
                SET shipment_status = :shipment_status,
                    delivery_status = :delivery_status
                WHERE order_id = :order_id
            ');
            $stmt->execute([
                'shipment_status' => 'teslim_edildi',
                'delivery_status' => 'teslim_edildi',
                'order_id' => $orderId,
            ]);

            sales_sync_order_delivery($db, $orderId, 'tamamlandi', 'teslim_edildi', date('Y-m-d H:i:s'));
            app_audit_log('satis', 'deliver_order', 'sales_orders', $orderId, $orderRows[0]['order_no'] . ' siparisi teslim edildi.');

            sales_post_redirect('order_delivered');
        }

        if ($action === 'create_invoice_from_offer') {
            $offerId = (int) ($_POST['offer_id'] ?? 0);

            if ($offerId <= 0) {
                throw new RuntimeException('Gecerli bir teklif secilmedi.');
            }

            $offer = app_fetch_all($db, '
                SELECT id, cari_id, offer_no, offer_date
                FROM sales_offers
                WHERE id = :id
                LIMIT 1
            ', ['id' => $offerId]);
            $items = app_fetch_all($db, '
                SELECT product_id, description, quantity, unit_price, line_total
                FROM sales_offer_items
                WHERE offer_id = :offer_id
                ORDER BY id ASC
            ', ['offer_id' => $offerId]);

            if (!$offer || !$items) {
                throw new RuntimeException('Teklif verisi bulunamadi.');
            }

            $offerRow = $offer[0];
            $invoiceItems = [];
            foreach ($items as $item) {
                $invoiceItems[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0) ?: null,
                    'description' => $item['description'],
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'vat_rate' => 20,
                    'line_total' => (float) $item['line_total'],
                ];
            }

            app_create_invoice_from_source($db, [
                'branch_id' => app_default_branch_id($db),
                'cari_id' => (int) $offerRow['cari_id'],
                'invoice_type' => 'satis',
                'invoice_date' => date('Y-m-d'),
                'items' => $invoiceItems,
                'notes' => $offerRow['offer_no'] . ' teklifinden otomatik olusturuldu.',
                'source_module' => 'satis',
                'movement_description' => $offerRow['offer_no'] . ' teklifinden otomatik satis faturasi',
            ]);

            sales_post_redirect('offer_invoice');
        }

        if ($action === 'create_invoice_from_order') {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            sales_create_invoice_from_order_id($db, $orderId, false);
            sales_post_redirect('order_invoice');
        }

        if ($action === 'create_invoice_from_shipment') {
            $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                throw new RuntimeException('Gecerli bir sevk/irsaliye secilmedi.');
            }

            $shipmentRows = app_fetch_all($db, '
                SELECT sh.id, sh.order_id, sh.shipment_no, sh.irsaliye_no, o.cari_id, o.order_no
                FROM sales_shipments sh
                INNER JOIN sales_orders o ON o.id = sh.order_id
                WHERE sh.id = :id
                LIMIT 1
            ', ['id' => $shipmentId]);

            if (!$shipmentRows) {
                throw new RuntimeException('Sevk/irsaliye kaydi bulunamadi.');
            }

            $shipmentRow = $shipmentRows[0];
            $items = app_fetch_all($db, '
                SELECT product_id, description, quantity, unit_price, line_total
                FROM sales_order_items
                WHERE order_id = :order_id
                ORDER BY id ASC
            ', ['order_id' => (int) $shipmentRow['order_id']]);

            if (!$items) {
                throw new RuntimeException('Sevke bagli siparis kalemi bulunamadi.');
            }

            $invoiceItems = [];
            foreach ($items as $item) {
                $invoiceItems[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0) ?: null,
                    'description' => $item['description'],
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'vat_rate' => 20,
                    'line_total' => (float) $item['line_total'],
                ];
            }

            $invoiceId = app_create_invoice_from_source($db, [
                'branch_id' => app_default_branch_id($db),
                'cari_id' => (int) $shipmentRow['cari_id'],
                'invoice_type' => 'satis',
                'invoice_date' => date('Y-m-d'),
                'items' => $invoiceItems,
                'notes' => $shipmentRow['irsaliye_no'] . ' irsaliyesinden otomatik olusturuldu.',
                'source_module' => 'satis',
                'movement_description' => $shipmentRow['irsaliye_no'] . ' irsaliyesinden otomatik satis faturasi',
            ]);

            $stmt = $db->prepare('
                INSERT IGNORE INTO sales_order_invoice_links (order_id, invoice_id)
                VALUES (:order_id, :invoice_id)
            ');
            $stmt->execute([
                'order_id' => (int) $shipmentRow['order_id'],
                'invoice_id' => $invoiceId,
            ]);
            sales_link_invoice_to_shipments($db, (int) $shipmentRow['order_id'], $invoiceId, $shipmentId);
            app_audit_log('satis', 'shipment_invoice', 'sales_shipments', $shipmentId, $shipmentRow['irsaliye_no'] . ' irsaliyesi faturaya cevrildi.');

            sales_post_redirect('shipment_invoice');
        }

        if ($action === 'save_edispatch_settings') {
            app_set_setting($db, 'edispatch.mode', trim((string) ($_POST['edispatch_mode'] ?? 'mock')) ?: 'mock', 'satis');
            app_set_setting($db, 'edispatch.api_url', trim((string) ($_POST['edispatch_api_url'] ?? '')), 'satis');
            app_set_setting($db, 'edispatch.api_method', trim((string) ($_POST['edispatch_api_method'] ?? 'POST')) ?: 'POST', 'satis');
            app_set_setting($db, 'edispatch.api_content_type', trim((string) ($_POST['edispatch_api_content_type'] ?? 'application/json')) ?: 'application/json', 'satis');
            app_set_setting($db, 'edispatch.api_headers', trim((string) ($_POST['edispatch_api_headers'] ?? '')), 'satis');
            app_set_setting($db, 'edispatch.api_body', (string) ($_POST['edispatch_api_body'] ?? '{}'), 'satis');
            app_set_setting($db, 'edispatch.status_url', trim((string) ($_POST['edispatch_status_url'] ?? '')), 'satis');
            app_set_setting($db, 'edispatch.status_method', trim((string) ($_POST['edispatch_status_method'] ?? 'POST')) ?: 'POST', 'satis');
            app_set_setting($db, 'edispatch.status_content_type', trim((string) ($_POST['edispatch_status_content_type'] ?? 'application/json')) ?: 'application/json', 'satis');
            app_set_setting($db, 'edispatch.status_headers', trim((string) ($_POST['edispatch_status_headers'] ?? '')), 'satis');
            app_set_setting($db, 'edispatch.status_body', (string) ($_POST['edispatch_status_body'] ?? '{}'), 'satis');
            app_set_setting($db, 'edispatch.timeout', trim((string) ($_POST['edispatch_timeout'] ?? '15')) ?: '15', 'satis');
            app_audit_log('satis', 'save_edispatch_settings', 'core_settings', null, 'e-Irsaliye entegrasyon ayarlari guncellendi.');
            sales_post_redirect('edispatch_settings');
        }

        if ($action === 'send_edispatch') {
            $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                throw new RuntimeException('Gonderim icin gecerli bir sevk secilmedi.');
            }

            $shipmentRows = app_fetch_all($db, '
                SELECT sh.*, o.order_no, c.company_name, c.full_name
                FROM sales_shipments sh
                INNER JOIN sales_orders o ON o.id = sh.order_id
                INNER JOIN cari_cards c ON c.id = o.cari_id
                WHERE sh.id = :id
                LIMIT 1
            ', ['id' => $shipmentId]);
            if (!$shipmentRows) {
                throw new RuntimeException('Sevk/irsaliye bulunamadi.');
            }

            $result = app_edispatch_send_shipment($db, $shipmentRows[0]);
            $stmt = $db->prepare('
                UPDATE sales_shipments
                SET edispatch_status = :edispatch_status,
                    edispatch_uuid = :edispatch_uuid,
                    edispatch_response = :edispatch_response,
                    edispatch_sent_at = :edispatch_sent_at
                WHERE id = :id
            ');
            $stmt->execute([
                'edispatch_status' => $result['status'],
                'edispatch_uuid' => $result['uuid'],
                'edispatch_response' => $result['response'],
                'edispatch_sent_at' => date('Y-m-d H:i:s'),
                'id' => $shipmentId,
            ]);

            app_audit_log('satis', 'send_edispatch', 'sales_shipments', $shipmentId, $shipmentRows[0]['irsaliye_no'] . ' e-Irsaliye gonderimi yapildi.');
            sales_post_redirect('edispatch_send');
        }

        if ($action === 'query_edispatch_status') {
            $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                throw new RuntimeException('Durum sorgusu icin gecerli bir sevk secilmedi.');
            }

            $shipmentRows = app_fetch_all($db, '
                SELECT sh.*, o.order_no, c.company_name, c.full_name
                FROM sales_shipments sh
                INNER JOIN sales_orders o ON o.id = sh.order_id
                INNER JOIN cari_cards c ON c.id = o.cari_id
                WHERE sh.id = :id
                LIMIT 1
            ', ['id' => $shipmentId]);
            if (!$shipmentRows) {
                throw new RuntimeException('Sevk/irsaliye bulunamadi.');
            }

            $result = app_edispatch_query_shipment($db, $shipmentRows[0]);
            $stmt = $db->prepare('
                UPDATE sales_shipments
                SET edispatch_status = :edispatch_status,
                    edispatch_response = :edispatch_response
                WHERE id = :id
            ');
            $stmt->execute([
                'edispatch_status' => $result['status'],
                'edispatch_response' => $result['response'],
                'id' => $shipmentId,
            ]);

            app_audit_log('satis', 'query_edispatch_status', 'sales_shipments', $shipmentId, $shipmentRows[0]['irsaliye_no'] . ' e-Irsaliye durumu sorgulandi.');
            sales_post_redirect('edispatch_query');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Satis islemi tamamlanamadi. Lutfen girdileri kontrol edip tekrar deneyin.';
    }
}

$filters = sales_build_filters();
$edispatchSettings = sales_edispatch_settings($db);
[$salesCariScopeWhere, $salesCariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$offerScopeWhere, $offerScopeParams] = app_branch_scope_filter($db, null, 'o');
[$orderScopeWhere, $orderScopeParams] = app_branch_scope_filter($db, null, 'o');
[$warehouseScopeWhere, $warehouseScopeParams] = app_branch_scope_filter($db, null);

$cariCards = app_fetch_all($db, 'SELECT c.id, c.company_name, c.full_name FROM cari_cards c ' . ($salesCariScopeWhere !== '' ? 'WHERE ' . $salesCariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 100', $salesCariScopeParams);
$products = app_fetch_all($db, 'SELECT id, sku, name, sale_price FROM stock_products ORDER BY id DESC LIMIT 100');
$offerTemplates = app_fetch_all($db, '
    SELECT id, template_name, category_name, product_id, default_description, default_quantity, default_unit_price, default_status, valid_day_count, notes
    FROM sales_offer_templates
    WHERE status = 1
    ORDER BY template_name ASC
');
$warehouses = app_fetch_all($db, 'SELECT id, name FROM stock_warehouses ' . ($warehouseScopeWhere !== '' ? 'WHERE ' . $warehouseScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $warehouseScopeParams);
$cargoProviders = app_fetch_all($db, 'SELECT id, provider_name, provider_code, api_mode, account_no, sender_city FROM sales_cargo_providers WHERE status = 1 ORDER BY provider_name ASC');
$salesUsers = app_fetch_all($db, "SELECT id, full_name, email FROM core_users WHERE status = 1 ORDER BY full_name ASC LIMIT 100");
$commissionRows = sales_commission_rows($db);
$targetYear = (int) date('Y');
$targetMonth = (int) date('n');
$targetRows = sales_target_rows($db, $targetYear, $targetMonth);
$customerPriceRows = app_fetch_all($db, '
    SELECT p.id, c.company_name, c.full_name, s.name AS product_name, s.sku, p.price_amount, p.min_quantity, p.valid_from, p.valid_until
    FROM sales_customer_prices p
    INNER JOIN cari_cards c ON c.id = p.cari_id
    INNER JOIN stock_products s ON s.id = p.product_id
    WHERE p.status = 1
    ORDER BY p.id DESC
    LIMIT 50
');
$discountRows = app_fetch_all($db, '
    SELECT d.id, d.rule_name, d.discount_type, d.discount_value, d.min_amount, d.valid_from, d.valid_until, c.company_name, c.full_name
    FROM sales_discount_rules d
    LEFT JOIN cari_cards c ON c.id = d.cari_id
    WHERE d.status = 1
    ORDER BY d.id DESC
    LIMIT 50
');
$promotionRows = app_fetch_all($db, '
    SELECT p.id, p.promo_name, p.promo_type, p.promo_value, p.min_quantity, p.min_amount, p.valid_from, p.valid_until, c.company_name, c.full_name, s.name AS product_name, s.sku
    FROM sales_promotion_rules p
    LEFT JOIN cari_cards c ON c.id = p.cari_id
    LEFT JOIN stock_products s ON s.id = p.product_id
    WHERE p.status = 1
    ORDER BY p.id DESC
    LIMIT 50
');
$offerWhere = [];
$offerParams = [];

if ($filters['search'] !== '') {
    $offerWhere[] = '(o.offer_no LIKE :offer_search OR c.company_name LIKE :offer_search OR c.full_name LIKE :offer_search)';
    $offerParams['offer_search'] = '%' . $filters['search'] . '%';
}

if ($filters['offer_status'] !== '') {
    $offerWhere[] = 'o.status = :offer_status';
    $offerParams['offer_status'] = $filters['offer_status'];
}
if ($offerScopeWhere !== '') {
    $offerWhere[] = $offerScopeWhere;
    $offerParams = array_merge($offerParams, $offerScopeParams);
}

$offerWhereSql = $offerWhere ? 'WHERE ' . implode(' AND ', $offerWhere) : '';

$offers = app_fetch_all($db, "
    SELECT
        o.id, o.offer_no, o.offer_date, o.valid_until, o.status, o.grand_total, c.company_name, c.full_name, u.full_name AS sales_user_name,
        (SELECT COUNT(*) FROM sales_offer_revisions r WHERE r.offer_id = o.id) AS revision_count,
        (SELECT r.version_label FROM sales_offer_revisions r WHERE r.offer_id = o.id ORDER BY r.revision_no DESC LIMIT 1) AS current_version,
        (SELECT COUNT(*) FROM sales_offer_approval_logs a WHERE a.offer_id = o.id) AS approval_count
    FROM sales_offers o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    LEFT JOIN core_users u ON u.id = o.sales_user_id
    {$offerWhereSql}
    ORDER BY o.id DESC
    LIMIT 50
", $offerParams);

$orderWhere = [];
$orderParams = [];

if ($filters['search'] !== '') {
    $orderWhere[] = '(o.order_no LIKE :order_search OR c.company_name LIKE :order_search OR c.full_name LIKE :order_search OR o.cargo_company LIKE :order_search OR o.tracking_no LIKE :order_search)';
    $orderParams['order_search'] = '%' . $filters['search'] . '%';
}

if ($filters['order_status'] !== '') {
    $orderWhere[] = 'o.status = :order_status';
    $orderParams['order_status'] = $filters['order_status'];
}
if ($orderScopeWhere !== '') {
    $orderWhere[] = $orderScopeWhere;
    $orderParams = array_merge($orderParams, $orderScopeParams);
}

$orderWhereSql = $orderWhere ? 'WHERE ' . implode(' AND ', $orderWhere) : '';

$orders = app_fetch_all($db, "
    SELECT
        o.id, o.order_no, o.order_date, o.status, o.delivery_status, o.delivered_at, o.grand_total, o.cargo_company, o.tracking_no, o.cargo_provider_id,
        c.company_name, c.full_name, s.offer_no, cp.provider_name, cp.provider_code, u.full_name AS sales_user_name,
        (SELECT COUNT(*) FROM sales_shipments sh WHERE sh.order_id = o.id) AS shipment_count,
        (SELECT COUNT(*) FROM sales_order_invoice_links l WHERE l.order_id = o.id) AS invoice_count
    FROM sales_orders o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    LEFT JOIN sales_offers s ON s.id = o.offer_id
    LEFT JOIN sales_cargo_providers cp ON cp.id = o.cargo_provider_id
    LEFT JOIN core_users u ON u.id = o.sales_user_id
    {$orderWhereSql}
    ORDER BY o.id DESC
    LIMIT 50
", $orderParams);
$offerItems = app_fetch_all($db, "
    SELECT i.description, i.quantity, i.unit_price, i.line_total, o.offer_no
    FROM sales_offer_items i
    INNER JOIN sales_offers o ON o.id = i.offer_id
    INNER JOIN cari_cards c ON c.id = o.cari_id
    " . ($offerWhere ? $offerWhereSql : '') . ($filters['search'] !== '' ? ($offerWhere ? ' AND ' : ' WHERE ') . 'i.description LIKE :offer_item_search' : '') . "
    ORDER BY i.id DESC
    LIMIT 30
", $filters['search'] !== '' ? array_merge($offerParams, ['offer_item_search' => '%' . $filters['search'] . '%']) : $offerParams);
$orderItems = app_fetch_all($db, "
    SELECT i.description, i.quantity, i.unit_price, i.line_total, o.order_no
    FROM sales_order_items i
    INNER JOIN sales_orders o ON o.id = i.order_id
    INNER JOIN cari_cards c ON c.id = o.cari_id
    " . ($orderWhere ? $orderWhereSql : '') . ($filters['search'] !== '' ? ($orderWhere ? ' AND ' : ' WHERE ') . 'i.description LIKE :order_item_search' : '') . "
    ORDER BY i.id DESC
    LIMIT 30
", $filters['search'] !== '' ? array_merge($orderParams, ['order_item_search' => '%' . $filters['search'] . '%']) : $orderParams);

$offers = app_sort_rows($offers, $filters['offer_sort'], [
    'date_desc' => ['offer_date', 'desc'],
    'date_asc' => ['offer_date', 'asc'],
    'total_desc' => ['grand_total', 'desc'],
    'total_asc' => ['grand_total', 'asc'],
    'no_asc' => ['offer_no', 'asc'],
]);
$orders = app_sort_rows($orders, $filters['order_sort'], [
    'date_desc' => ['order_date', 'desc'],
    'date_asc' => ['order_date', 'asc'],
    'total_desc' => ['grand_total', 'desc'],
    'total_asc' => ['grand_total', 'asc'],
    'no_asc' => ['order_no', 'asc'],
]);
$offersPagination = app_paginate_rows($offers, $filters['offer_page'], 10);
$ordersPagination = app_paginate_rows($orders, $filters['order_page'], 10);
$offers = $offersPagination['items'];
$orders = $ordersPagination['items'];

$summary = [
    'Teklif Sayisi' => app_table_count($db, 'sales_offers'),
    'Siparis Sayisi' => app_table_count($db, 'sales_orders'),
    'Teklif Toplami' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(grand_total),0) FROM sales_offers'), 2, ',', '.'),
    'Siparis Toplami' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(grand_total),0) FROM sales_orders'), 2, ',', '.'),
    'Onayli Teklif' => app_metric($db, "SELECT COUNT(*) FROM sales_offers WHERE status = 'onaylandi'"),
    'Onay Bekleyen Teklif' => app_metric($db, "SELECT COUNT(*) FROM sales_offers WHERE status = 'gonderildi'"),
    'Bekleyen Siparis' => app_metric($db, "SELECT COUNT(*) FROM sales_orders WHERE status = 'bekliyor'"),
    'Yoldaki Teslimat' => app_metric($db, "SELECT COUNT(*) FROM sales_orders WHERE delivery_status = 'yolda'"),
    'Tahmini Komisyon' => number_format(array_reduce($commissionRows, static function (float $carry, array $row): float {
        return $carry + (((float) $row['invoiced_total']) * ((float) $row['rate_percent'] / 100));
    }, 0.0), 2, ',', '.'),
    'Aylik Hedef' => number_format(array_reduce($targetRows, static function (float $carry, array $row): float {
        return $carry + (float) $row['target_amount'];
    }, 0.0), 2, ',', '.'),
];
?>

<?php if ($feedback !== ''): ?>
    <div class="notice <?= strpos($feedback, 'error:') === 0 ? 'notice-error' : 'notice-ok' ?>">
        <?=
        app_h(
            strpos($feedback, 'error:') === 0
                ? substr($feedback, 6)
                : (strpos($feedback, 'price:') === 0
                    ? 'Ozel fiyat bulundu: ' . number_format((float) substr($feedback, 6), 2, ',', '.') . ' TRY'
                    : (strpos($feedback, 'discount:') === 0
                        ? 'Uygulanacak iskonto: ' . number_format((float) substr($feedback, 9), 2, ',', '.') . ' TRY'
                        : (strpos($feedback, 'promotion:') === 0
                            ? 'Uygulanacak kampanya/promosyon: ' . number_format((float) substr($feedback, 10), 2, ',', '.') . ' TRY'
                            : (strpos($feedback, 'template:') === 0
                                ? 'Secilen teklif sablonu: ' . substr($feedback, 9)
                                : (strpos($feedback, 'order_bulk_invoice_') === 0
                                    ? (function (string $value): string {
                                        $parts = explode('_', $value);
                                        $created = (int) ($parts[3] ?? 0);
                                        $skipped = (int) ($parts[4] ?? 0);
                                        return 'Toplu fatura tamamlandi. Yeni fatura: ' . $created . ' / Atlanan siparis: ' . $skipped;
                                    })($feedback)
                    : 'Islem kaydedildi.')
                )
                )
                )
                )
        )
        ?>
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

<section class="card">
    <h3>Satis Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="satis">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Teklif, siparis, cari, kargo">
        </div>
        <div>
            <label>Teklif Durumu</label>
            <select name="offer_status">
                <option value="">Tum durumlar</option>
                <option value="taslak" <?= $filters['offer_status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                <option value="gonderildi" <?= $filters['offer_status'] === 'gonderildi' ? 'selected' : '' ?>>Gonderildi</option>
                <option value="onaylandi" <?= $filters['offer_status'] === 'onaylandi' ? 'selected' : '' ?>>Onaylandi</option>
                <option value="reddedildi" <?= $filters['offer_status'] === 'reddedildi' ? 'selected' : '' ?>>Reddedildi</option>
            </select>
        </div>
        <div>
            <label>Siparis Durumu</label>
            <select name="order_status">
                <option value="">Tum durumlar</option>
                <option value="bekliyor" <?= $filters['order_status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                <option value="hazirlaniyor" <?= $filters['order_status'] === 'hazirlaniyor' ? 'selected' : '' ?>>Hazirlaniyor</option>
                <option value="sevk" <?= $filters['order_status'] === 'sevk' ? 'selected' : '' ?>>Sevk</option>
                <option value="tamamlandi" <?= $filters['order_status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                <option value="iptal" <?= $filters['order_status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=satis" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="satis">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="offer_status" value="<?= app_h($filters['offer_status']) ?>">
        <input type="hidden" name="order_status" value="<?= app_h($filters['order_status']) ?>">
        <div>
            <label>Teklif Siralama</label>
            <select name="offer_sort">
                <option value="date_desc" <?= $filters['offer_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['offer_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="total_desc" <?= $filters['offer_sort'] === 'total_desc' ? 'selected' : '' ?>>Toplam yuksek</option>
                <option value="total_asc" <?= $filters['offer_sort'] === 'total_asc' ? 'selected' : '' ?>>Toplam dusuk</option>
                <option value="no_asc" <?= $filters['offer_sort'] === 'no_asc' ? 'selected' : '' ?>>Belge no A-Z</option>
            </select>
        </div>
        <div>
            <label>Siparis Siralama</label>
            <select name="order_sort">
                <option value="date_desc" <?= $filters['order_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['order_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="total_desc" <?= $filters['order_sort'] === 'total_desc' ? 'selected' : '' ?>>Toplam yuksek</option>
                <option value="total_asc" <?= $filters['order_sort'] === 'total_asc' ? 'selected' : '' ?>>Toplam dusuk</option>
                <option value="no_asc" <?= $filters['order_sort'] === 'no_asc' ? 'selected' : '' ?>>Belge no A-Z</option>
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
        <h3>e-Irsaliye Entegrasyon Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_edispatch_settings">
            <div>
                <label>Mod</label>
                <select name="edispatch_mode">
                    <option value="mock" <?= $edispatchSettings['mode'] === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="http_api" <?= $edispatchSettings['mode'] === 'http_api' ? 'selected' : '' ?>>HTTP API</option>
                </select>
            </div>
            <div>
                <label>Timeout</label>
                <input name="edispatch_timeout" value="<?= app_h($edispatchSettings['timeout']) ?>">
            </div>
            <div class="full">
                <label>Gonderim URL</label>
                <input name="edispatch_api_url" value="<?= app_h($edispatchSettings['api_url']) ?>" placeholder="https://api.ornek.com/eirsaliye/send">
            </div>
            <div>
                <label>Metod</label>
                <input name="edispatch_api_method" value="<?= app_h($edispatchSettings['api_method']) ?>">
            </div>
            <div>
                <label>Content-Type</label>
                <input name="edispatch_api_content_type" value="<?= app_h($edispatchSettings['api_content_type']) ?>">
            </div>
            <div class="full">
                <label>Headerlar</label>
                <textarea name="edispatch_api_headers" rows="2"><?= app_h($edispatchSettings['api_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Gonderim Govdesi</label>
                <textarea name="edispatch_api_body" rows="4"><?= app_h($edispatchSettings['api_body']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum URL</label>
                <input name="edispatch_status_url" value="<?= app_h($edispatchSettings['status_url']) ?>" placeholder="https://api.ornek.com/eirsaliye/status">
            </div>
            <div>
                <label>Durum Metodu</label>
                <input name="edispatch_status_method" value="<?= app_h($edispatchSettings['status_method']) ?>">
            </div>
            <div>
                <label>Durum Content-Type</label>
                <input name="edispatch_status_content_type" value="<?= app_h($edispatchSettings['status_content_type']) ?>">
            </div>
            <div class="full">
                <label>Durum Headerlar</label>
                <textarea name="edispatch_status_headers" rows="2"><?= app_h($edispatchSettings['status_headers']) ?></textarea>
            </div>
            <div class="full">
                <label>Durum Govdesi</label>
                <textarea name="edispatch_status_body" rows="4"><?= app_h($edispatchSettings['status_body']) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">e-Irsaliye Ayarlarini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Komisyon Kurali</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_commission_rule">
            <div>
                <label>Temsilci</label>
                <select name="commission_user_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($salesUsers as $salesUser): ?>
                        <option value="<?= (int) $salesUser['id'] ?>"><?= app_h($salesUser['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Komisyon %</label>
                <input type="number" name="rate_percent" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Hedef Tutar</label>
                <input type="number" name="target_amount" step="0.01" min="0" value="0">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Komisyon notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Komisyon Kuralini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Komisyon Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Temsilci</th><th>Oran</th><th>Hedef</th><th>Siparis</th><th>Faturalanan</th><th>Tahmini Komisyon</th></tr></thead>
                <tbody>
                <?php foreach ($commissionRows as $commissionRow): ?>
                    <?php $estimatedCommission = (float) $commissionRow['invoiced_total'] * ((float) $commissionRow['rate_percent'] / 100); ?>
                    <tr>
                        <td><?= app_h($commissionRow['full_name']) ?></td>
                        <td>%<?= number_format((float) $commissionRow['rate_percent'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $commissionRow['target_amount'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $commissionRow['order_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $commissionRow['invoiced_total'], 2, ',', '.') ?></td>
                        <td><?= number_format($estimatedCommission, 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Satis Hedefi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_sales_target">
            <div>
                <label>Temsilci</label>
                <select name="target_user_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($salesUsers as $salesUser): ?>
                        <option value="<?= (int) $salesUser['id'] ?>"><?= app_h($salesUser['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Yil</label>
                <input type="number" name="target_year" min="2024" value="<?= $targetYear ?>">
            </div>
            <div>
                <label>Ay</label>
                <input type="number" name="target_month" min="1" max="12" value="<?= $targetMonth ?>">
            </div>
            <div>
                <label>Hedef Tutar</label>
                <input type="number" name="target_amount" step="0.01" min="0" value="0">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Aylik hedef notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Satis Hedefini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3><?= app_h($targetYear . ' / ' . str_pad((string) $targetMonth, 2, '0', STR_PAD_LEFT)) ?> Hedef Gerceklesme</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Temsilci</th><th>Hedef</th><th>Siparis</th><th>Faturalanan</th><th>Basari</th></tr></thead>
                <tbody>
                <?php foreach ($targetRows as $targetRow): ?>
                    <?php
                    $progressBase = (float) $targetRow['target_amount'];
                    $progressRate = $progressBase > 0 ? (((float) $targetRow['invoice_total']) / $progressBase) * 100 : 0;
                    ?>
                    <tr>
                        <td><?= app_h($targetRow['full_name']) ?></td>
                        <td><?= number_format((float) $targetRow['target_amount'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $targetRow['order_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $targetRow['invoice_total'], 2, ',', '.') ?></td>
                        <td>%<?= number_format($progressRate, 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Musteri Bazli Fiyat</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_customer_price">
            <div>
                <label>Cari</label>
                <select name="price_cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(sales_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun</label>
                <select name="price_product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Ozel Fiyat</label>
                <input type="number" name="price_amount" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label>Min. Miktar</label>
                <input type="number" name="min_quantity" step="0.001" min="0.001" value="1">
            </div>
            <div>
                <label>Baslangic</label>
                <input type="date" name="valid_from">
            </div>
            <div>
                <label>Bitis</label>
                <input type="date" name="valid_until">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Fiyat listesi notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Fiyat Listesini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktif Fiyat Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cari</th><th>Urun</th><th>Min.</th><th>Fiyat</th><th>Gecerlilik</th></tr></thead>
                <tbody>
                <?php foreach ($customerPriceRows as $priceRow): ?>
                    <tr>
                        <td><?= app_h($priceRow['company_name'] ?: $priceRow['full_name'] ?: '-') ?></td>
                        <td><?= app_h($priceRow['product_name'] . ' / ' . $priceRow['sku']) ?></td>
                        <td><?= number_format((float) $priceRow['min_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $priceRow['price_amount'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) (($priceRow['valid_from'] ?: '-') . ' / ' . ($priceRow['valid_until'] ?: '-'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Iskonto Kurali</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_discount_rule">
            <div>
                <label>Cari</label>
                <select name="discount_cari_id">
                    <option value="">Genel kural</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(sales_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kural Adi</label>
                <input name="rule_name" placeholder="Genel Bahar Iskontosu" required>
            </div>
            <div>
                <label>Tip</label>
                <select name="discount_type">
                    <option value="oran">Oran (%)</option>
                    <option value="tutar">Tutar</option>
                </select>
            </div>
            <div>
                <label>Deger</label>
                <input type="number" name="discount_value" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label>Min. Tutar</label>
                <input type="number" name="min_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Baslangic</label>
                <input type="date" name="valid_from">
            </div>
            <div>
                <label>Bitis</label>
                <input type="date" name="valid_until">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Iskonto kosulu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Iskonto Kuralini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktif Iskonto Kurallari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kural</th><th>Cari</th><th>Tip</th><th>Deger</th><th>Min.</th><th>Gecerlilik</th></tr></thead>
                <tbody>
                <?php foreach ($discountRows as $discountRow): ?>
                    <tr>
                        <td><?= app_h($discountRow['rule_name']) ?></td>
                        <td><?= app_h($discountRow['company_name'] ?: $discountRow['full_name'] ?: 'Genel') ?></td>
                        <td><?= app_h($discountRow['discount_type']) ?></td>
                        <td><?= number_format((float) $discountRow['discount_value'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $discountRow['min_amount'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) (($discountRow['valid_from'] ?: '-') . ' / ' . ($discountRow['valid_until'] ?: '-'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kampanya / Promosyon</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_promotion_rule">
            <div>
                <label>Cari</label>
                <select name="promotion_cari_id">
                    <option value="">Tum cariler</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(sales_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun</label>
                <select name="promotion_product_id">
                    <option value="">Tum urunler</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kampanya Adi</label>
                <input name="promo_name" placeholder="2 adet alana ek indirim" required>
            </div>
            <div>
                <label>Tip</label>
                <select name="promo_type">
                    <option value="oran">Oran (%)</option>
                    <option value="tutar">Tutar</option>
                </select>
            </div>
            <div>
                <label>Deger</label>
                <input type="number" name="promo_value" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label>Min. Miktar</label>
                <input type="number" name="min_quantity" step="0.001" min="0.001" value="1">
            </div>
            <div>
                <label>Min. Tutar</label>
                <input type="number" name="min_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Baslangic</label>
                <input type="date" name="valid_from">
            </div>
            <div>
                <label>Bitis</label>
                <input type="date" name="valid_until">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Promosyon kurali notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Kampanya Kuralini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktif Kampanyalar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kampanya</th><th>Cari</th><th>Urun</th><th>Tip</th><th>Deger</th><th>Kosul</th></tr></thead>
                <tbody>
                <?php foreach ($promotionRows as $promotionRow): ?>
                    <tr>
                        <td><?= app_h($promotionRow['promo_name']) ?></td>
                        <td><?= app_h($promotionRow['company_name'] ?: $promotionRow['full_name'] ?: 'Tum cariler') ?></td>
                        <td><?= app_h(($promotionRow['product_name'] ?? '') !== '' ? ($promotionRow['product_name'] . ' / ' . $promotionRow['sku']) : 'Tum urunler') ?></td>
                        <td><?= app_h($promotionRow['promo_type']) ?></td>
                        <td><?= number_format((float) $promotionRow['promo_value'], 2, ',', '.') ?></td>
                        <td><?= app_h('Min. miktar: ' . number_format((float) $promotionRow['min_quantity'], 3, ',', '.') . ' / Min. tutar: ' . number_format((float) $promotionRow['min_amount'], 2, ',', '.')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Teklif Sablonu</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_offer_template">
            <div>
                <label>Sablon Adi</label>
                <input name="template_name" placeholder="Standart Hizmet Teklifi" required>
            </div>
            <div>
                <label>Kategori</label>
                <input name="category_name" placeholder="Genel / Yazilim / Donanim">
            </div>
            <div>
                <label>Urun</label>
                <select name="template_product_id">
                    <option value="">Serbest kalem</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Varsayilan Durum</label>
                <select name="default_status">
                    <option value="taslak">Taslak</option>
                    <option value="gonderildi">Gonderildi</option>
                    <option value="onaylandi">Onaylandi</option>
                    <option value="reddedildi">Reddedildi</option>
                </select>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <input name="default_description" placeholder="Sablon aciklamasi" required>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" name="default_quantity" step="0.001" min="0.001" value="1">
            </div>
            <div>
                <label>Birim Fiyat</label>
                <input type="number" name="default_unit_price" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Gecerlilik Gunu</label>
                <input type="number" name="valid_day_count" min="1" value="15">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Sablon notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Teklif Sablonunu Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktif Teklif Sablonlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sablon</th><th>Kategori</th><th>Urun</th><th>Miktar</th><th>Fiyat</th><th>Gecerlilik</th></tr></thead>
                <tbody>
                <?php foreach ($offerTemplates as $template): ?>
                    <?php
                    $templateProductLabel = 'Serbest kalem';
                    foreach ($products as $product) {
                        if ((int) $product['id'] === (int) ($template['product_id'] ?? 0)) {
                            $templateProductLabel = $product['name'] . ' / ' . $product['sku'];
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td><?= app_h($template['template_name']) ?></td>
                        <td><?= app_h((string) ($template['category_name'] ?: '-')) ?></td>
                        <td><?= app_h($templateProductLabel) ?></td>
                        <td><?= number_format((float) $template['default_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $template['default_unit_price'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) $template['valid_day_count']) ?> gun</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yeni Teklif</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_offer">
            <div>
                <label>Teklif Sablonu</label>
                <select name="template_id">
                    <option value="">Manuel teklif</option>
                    <?php foreach ($offerTemplates as $template): ?>
                        <option value="<?= (int) $template['id'] ?>"><?= app_h($template['template_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(sales_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="taslak">Taslak</option>
                    <option value="gonderildi">Gonderildi</option>
                    <option value="onaylandi">Onaylandi</option>
                    <option value="reddedildi">Reddedildi</option>
                </select>
            </div>
            <div>
                <label>Satis Temsilcisi</label>
                <select name="sales_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($salesUsers as $salesUser): ?>
                        <option value="<?= (int) $salesUser['id'] ?>"><?= app_h($salesUser['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun</label>
                <select name="product_id">
                    <option value="">Serbest kalem</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Gecerlilik</label>
                <input type="date" name="valid_until">
            </div>
            <div class="full">
                <label>Kalem Aciklamasi</label>
                <input name="description" placeholder="Hizmet veya urun aciklamasi" required>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" name="quantity" step="0.001" min="0.001" value="1" required>
            </div>
            <div>
                <label>Birim Fiyat</label>
                <input type="number" name="unit_price" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label>Iskonto</label>
                <input type="number" name="discount_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Promosyon</label>
                <input type="number" name="promotion_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_offer_template'">Sablonu Goster</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_customer_price'">Ozel Fiyat Getir</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_discount'">Iskonto Hesapla</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_promotion'">Kampanya Hesapla</button>
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Teklif notlari"></textarea>
            </div>
            <div class="full">
                <button type="submit">Teklif Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Kargo Saglayicisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_cargo_provider">
            <div>
                <label>Saglayici Adi</label>
                <input name="provider_name" placeholder="Yurtici Kargo" required>
            </div>
            <div>
                <label>Kod</label>
                <input name="provider_code" placeholder="YURTICI" required>
            </div>
            <div>
                <label>Mod</label>
                <select name="api_mode">
                    <option value="mock">Mock</option>
                    <option value="manual">Manual</option>
                </select>
            </div>
            <div>
                <label>Hesap No</label>
                <input name="account_no" placeholder="Musteri no">
            </div>
            <div>
                <label>Gonderici Sehir</label>
                <input name="sender_city" placeholder="Istanbul">
            </div>
            <div class="full">
                <button type="submit">Saglayici Kaydet</button>
            </div>
        </form>
        <?php if ($cargoProviders): ?>
            <div class="table-wrap" style="margin-top:14px;">
                <table>
                    <thead><tr><th>Adi</th><th>Kod</th><th>Mod</th><th>Hesap</th></tr></thead>
                    <tbody>
                    <?php foreach ($cargoProviders as $provider): ?>
                        <tr>
                            <td><?= app_h($provider['provider_name']) ?></td>
                            <td><?= app_h($provider['provider_code']) ?></td>
                            <td><?= app_h($provider['api_mode']) ?></td>
                            <td><?= app_h((string) ($provider['account_no'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Yeni Siparis</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_order">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(sales_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Bagli Teklif</label>
                <select name="offer_id">
                    <option value="">Bagimsiz siparis</option>
                    <?php foreach ($offers as $offer): ?>
                        <option value="<?= (int) $offer['id'] ?>"><?= app_h($offer['offer_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="hazirlaniyor">Hazirlaniyor</option>
                    <option value="sevk">Sevk</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Satis Temsilcisi</label>
                <select name="sales_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($salesUsers as $salesUser): ?>
                        <option value="<?= (int) $salesUser['id'] ?>"><?= app_h($salesUser['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun</label>
                <select name="product_id">
                    <option value="">Serbest kalem</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Kalem Aciklamasi</label>
                <input name="description" placeholder="Siparis kalemi" required>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" name="quantity" step="0.001" min="0.001" value="1" required>
            </div>
            <div>
                <label>Birim Fiyat</label>
                <input type="number" name="unit_price" step="0.01" min="0" value="0" required>
            </div>
            <div>
                <label>Iskonto</label>
                <input type="number" name="discount_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>Promosyon</label>
                <input type="number" name="promotion_amount" step="0.01" min="0" value="0">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_customer_price'">Ozel Fiyat Getir</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_discount'">Iskonto Hesapla</button>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='preview_promotion'">Kampanya Hesapla</button>
            </div>
            <div>
                <label>Kargo</label>
                <input name="cargo_company" placeholder="Yurtici / Aras / MNG">
            </div>
            <div>
                <label>Kargo Saglayicisi</label>
                <select name="cargo_provider_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($cargoProviders as $provider): ?>
                        <option value="<?= (int) $provider['id'] ?>"><?= app_h($provider['provider_name'] . ' / ' . $provider['provider_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Takip No</label>
                <input name="tracking_no" placeholder="Kargo takip no">
            </div>
            <div class="full">
                <button type="submit">Siparis Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Son Teklifler</h3>
        <form method="post">
            <div class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
                <input type="hidden" name="action" value="bulk_update_offer_status">
                <select name="bulk_status">
                    <option value="taslak">Taslak</option>
                    <option value="gonderildi">Gonderildi</option>
                    <option value="onaylandi">Onaylandi</option>
                    <option value="reddedildi">Reddedildi</option>
                </select>
                <button type="submit">Secili Teklifleri Guncelle</button>
                <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_export_offers_csv'">Secili Teklifleri CSV</button>
            </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.offer-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Versiyon</th><th>Cari</th><th>Temsilci</th><th>Tarih</th><th>Durum</th><th>Toplam</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($offers as $offer): ?>
                    <tr>
                        <td><input class="offer-check" type="checkbox" name="offer_ids[]" value="<?= (int) $offer['id'] ?>"></td>
                        <td><?= app_h($offer['offer_no']) ?></td>
                        <td><?= app_h(($offer['current_version'] ?: 'v0') . ' / ' . (int) $offer['revision_count'] . ' rev.') ?></td>
                        <td><?= app_h($offer['company_name'] ?: $offer['full_name'] ?: '-') ?></td>
                        <td><?= app_h((string) ($offer['sales_user_name'] ?: '-')) ?></td>
                        <td><?= app_h($offer['offer_date']) ?></td>
                        <td><?= app_h($offer['status']) ?></td>
                        <td><?= number_format((float) $offer['grand_total'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="sales_detail.php?type=offer&id=<?= (int) $offer['id'] ?>">Detay</a>
                                <a href="print.php?type=offer&id=<?= (int) $offer['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#fff1df;color:#7c2d12;font-weight:700;text-decoration:none;">PDF / Yazdir</a>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_offer_status">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <select name="status">
                                        <option value="taslak" <?= $offer['status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                                        <option value="gonderildi" <?= $offer['status'] === 'gonderildi' ? 'selected' : '' ?>>Gonderildi</option>
                                        <option value="onaylandi" <?= $offer['status'] === 'onaylandi' ? 'selected' : '' ?>>Onaylandi</option>
                                        <option value="reddedildi" <?= $offer['status'] === 'reddedildi' ? 'selected' : '' ?>>Reddedildi</option>
                                    </select>
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="create_offer_revision">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Revizyon Ac</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="submit_offer_approval">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Onaya Gonder</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="approve_offer">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Onayla</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="reject_offer">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Reddet</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu teklif silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_offer">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="create_invoice_from_offer">
                                    <input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
                                    <button type="submit">Faturaya Cevir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($offersPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $offersPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'satis', 'search' => $filters['search'], 'offer_status' => $filters['offer_status'], 'order_status' => $filters['order_status'], 'offer_sort' => $filters['offer_sort'], 'order_sort' => $filters['order_sort'], 'offer_page' => $page, 'order_page' => $ordersPagination['page']])) ?>"><?= $page === $offersPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Son Siparisler</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_order_status">
            <select name="bulk_status">
                <option value="bekliyor">Bekliyor</option>
                <option value="hazirlaniyor">Hazirlaniyor</option>
                <option value="sevk">Sevk</option>
                <option value="tamamlandi">Tamamlandi</option>
                <option value="iptal">Iptal</option>
            </select>
            <select name="bulk_warehouse_id">
                <option value="">Depo Secin</option>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Secili Siparisleri Guncelle</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_export_orders_csv'">Secili Siparisleri CSV</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_create_invoices_from_orders'">Secili Siparisleri Faturalandir</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.order-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Cari</th><th>Temsilci</th><th>Siparis</th><th>Teslim</th><th>Kargo</th><th>Baglanti</th><th>Toplam</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><input class="order-check" type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>"></td>
                        <td><?= app_h($order['order_no']) ?></td>
                        <td><?= app_h($order['company_name'] ?: $order['full_name'] ?: '-') ?></td>
                        <td><?= app_h((string) ($order['sales_user_name'] ?: '-')) ?></td>
                        <td><?= app_h($order['status']) ?></td>
                        <td><?= app_h($order['delivery_status'] ?: '-') ?></td>
                        <td><?= app_h((($order['provider_name'] ?: $order['cargo_company']) ?: '-') . (($order['tracking_no'] ?? '') !== '' ? ' / ' . $order['tracking_no'] : '')) ?></td>
                        <td><?= (int) $order['shipment_count'] ?> sevk / <?= (int) $order['invoice_count'] ?> fatura</td>
                        <td><?= number_format((float) $order['grand_total'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="sales_detail.php?type=order&id=<?= (int) $order['id'] ?>">Detay</a>
                                <a href="print.php?type=order&id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#fff1df;color:#7c2d12;font-weight:700;text-decoration:none;">PDF / Yazdir</a>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_order_status">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <select name="status">
                                        <option value="bekliyor" <?= $order['status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                                        <option value="hazirlaniyor" <?= $order['status'] === 'hazirlaniyor' ? 'selected' : '' ?>>Hazirlaniyor</option>
                                        <option value="sevk" <?= $order['status'] === 'sevk' ? 'selected' : '' ?>>Sevk</option>
                                        <option value="tamamlandi" <?= $order['status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                                        <option value="iptal" <?= $order['status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
                                    </select>
                                    <select name="warehouse_id">
                                        <option value="">Depo Secin</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="create_shipment_from_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <select name="warehouse_id">
                                        <option value="">Depo Secin</option>
                                        <?php foreach ($warehouses as $warehouse): ?>
                                            <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Sevk / Irsaliye</button>
                                </form>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="integrate_cargo_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <select name="provider_id">
                                        <option value="">Kargo Secin</option>
                                        <?php foreach ($cargoProviders as $provider): ?>
                                            <option value="<?= (int) $provider['id'] ?>" <?= (int) ($order['cargo_provider_id'] ?? 0) === (int) $provider['id'] ? 'selected' : '' ?>><?= app_h($provider['provider_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Kargo Entegre</button>
                                </form>
                                <?php if ((int) $order['shipment_count'] > 0): ?>
                                    <a href="print.php?type=shipment&id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#ecfccb;color:#3f6212;font-weight:700;text-decoration:none;">Irsaliye Yazdir</a>
                                    <a href="print.php?type=cargo_label&id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#dbeafe;color:#1d4ed8;font-weight:700;text-decoration:none;">Kargo Etiketi</a>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="mark_order_delivered">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button type="submit">Teslim Et</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu siparis silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="create_invoice_from_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <button type="submit">Faturaya Cevir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($ordersPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $ordersPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'satis', 'search' => $filters['search'], 'offer_status' => $filters['offer_status'], 'order_status' => $filters['order_status'], 'offer_sort' => $filters['offer_sort'], 'order_sort' => $filters['order_sort'], 'offer_page' => $offersPagination['page'], 'order_page' => $page])) ?>"><?= $page === $ordersPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Teklif Kalemleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Teklif</th><th>Aciklama</th><th>Miktar</th><th>Birim</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($offerItems as $item): ?>
                    <tr>
                        <td><?= app_h($item['offer_no']) ?></td>
                        <td><?= app_h($item['description']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['unit_price'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['line_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Siparis Kalemleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Siparis</th><th>Aciklama</th><th>Miktar</th><th>Birim</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($orderItems as $item): ?>
                    <tr>
                        <td><?= app_h($item['order_no']) ?></td>
                        <td><?= app_h($item['description']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['unit_price'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['line_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
