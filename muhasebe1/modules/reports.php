<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Rapor merkezi icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function reports_csv_download(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('CSV cikti olusturulamadi.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}

function reports_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'focus' => trim((string) ($_GET['focus'] ?? '')),
        'report_sort' => trim((string) ($_GET['report_sort'] ?? 'default')),
        'report_page' => max(1, (int) ($_GET['report_page'] ?? 1)),
    ];
}

function reports_row_matches(array $row, string $search): bool
{
    if ($search === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', array_map(static fn($value) => is_scalar($value) ? (string) $value : '', $row)));
    return strpos($haystack, strtolower($search)) !== false;
}

function reports_default_recipient(PDO $db, array $authUser): array
{
    $email = trim(app_setting($db, 'notifications.webhook_alert_email', ''));
    $name = 'Sistem Yoneticisi';

    if ($email === '') {
        $email = trim((string) ($authUser['email'] ?? ''));
        $name = trim((string) ($authUser['full_name'] ?? '')) ?: $name;
    }

    if ($email === '') {
        $rows = app_fetch_all($db, 'SELECT full_name, email FROM core_users WHERE status = 1 AND email <> "" ORDER BY id ASC LIMIT 1');
        if ($rows) {
            $email = trim((string) ($rows[0]['email'] ?? ''));
            $name = trim((string) ($rows[0]['full_name'] ?? '')) ?: $name;
        }
    }

    return ['name' => $name, 'email' => $email];
}

function reports_queue_pos_reconciliation_email(PDO $db, array $authUser, string $recipientEmail): bool
{
    app_notifications_ensure_schema($db);

    $recipientEmail = trim($recipientEmail);
    $recipient = reports_default_recipient($db, $authUser);
    if ($recipientEmail === '') {
        $recipientEmail = $recipient['email'];
    }

    if ($recipientEmail === '') {
        throw new RuntimeException('Mutabakat raporu icin e-posta adresi bulunamadi.');
    }

    $baseUrl = rtrim(app_base_url(), '/');
    $printUrl = $baseUrl . '/print.php?type=pos_reconciliation_report';
    $successfulTotal = app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'basarili'");
    $pendingTotal = app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'bekliyor'");
    $openWebhookTotal = app_metric($db, "SELECT COUNT(*) FROM collections_webhook_logs WHERE verification_status = 'hatali' AND resolved_at IS NULL");
    $collectedTotal = number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM collections_transactions WHERE status = 'basarili'"), 2, ',', '.');
    $commissionTotal = number_format((float) app_metric($db, "SELECT COALESCE(SUM(commission_amount),0) FROM collections_transactions WHERE status IN ('basarili','iade')"), 2, ',', '.');

    $body = "POS mutabakat raporu hazirlandi.\n\n";
    $body .= 'Basarili islem: ' . $successfulTotal . "\n";
    $body .= 'Bekleyen islem: ' . $pendingTotal . "\n";
    $body .= 'Acik webhook alarmi: ' . $openWebhookTotal . "\n";
    $body .= 'Toplam tahsilat: ' . $collectedTotal . " TRY\n";
    $body .= 'Toplam komisyon: ' . $commissionTotal . " TRY\n\n";
    $body .= 'PDF / yazdir ciktisi: ' . $printUrl . "\n";
    $body .= 'Olusturma: ' . date('Y-m-d H:i:s');

    return app_queue_notification($db, [
        'module_name' => 'rapor',
        'notification_type' => 'pos_reconciliation_report',
        'source_table' => 'collections_transactions',
        'source_id' => null,
        'channel' => 'email',
        'recipient_name' => $recipient['name'],
        'recipient_contact' => $recipientEmail,
        'subject_line' => 'POS Mutabakat Raporu / ' . date('Y-m-d'),
        'message_body' => $body,
        'status' => 'pending',
        'planned_at' => date('Y-m-d H:i:s'),
        'unique_key' => 'pos_reconciliation_report_' . date('Ymd') . '_' . sha1($recipientEmail),
        'provider_name' => 'report_center',
    ]);
}

$filters = reports_build_filters();
$feedback = trim((string) ($_GET['ok'] ?? ''));
$action = $_POST['action'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'queue_pos_reconciliation_email') {
            $queued = reports_queue_pos_reconciliation_email($db, $authUser, trim((string) ($_POST['recipient_email'] ?? '')));
            if (!$queued) {
                throw new RuntimeException('Bugun icin bu aliciya ait rapor kuyruga zaten alinmis olabilir.');
            }

            app_audit_log('rapor', 'queue_pos_reconciliation_email', 'notification_queue', null, 'POS mutabakat raporu e-posta kuyruguna alindi.');
            app_redirect('index.php?module=rapor&ok=pos_email');
        }

        if ($action === 'cancel_pos_report_email') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId <= 0) {
                throw new RuntimeException('Iptal icin gecerli kuyruk kaydi secilmedi.');
            }

            $stmt = $db->prepare("
                UPDATE notification_queue
                SET status = 'cancelled', processed_at = :processed_at, last_error = NULL
                WHERE id = :id
                  AND module_name = 'rapor'
                  AND notification_type = 'pos_reconciliation_report'
                  AND channel = 'email'
                  AND status = 'pending'
            ");
            $stmt->execute([
                'processed_at' => date('Y-m-d H:i:s'),
                'id' => $queueId,
            ]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Sadece bekleyen POS rapor e-postalari iptal edilebilir.');
            }

            app_audit_log('rapor', 'cancel_pos_report_email', 'notification_queue', $queueId, 'POS rapor e-postasi iptal edildi.');
            app_redirect('index.php?module=rapor&ok=pos_email_cancel');
        }

        if ($action === 'retry_pos_report_email') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId <= 0) {
                throw new RuntimeException('Tekrar kuyruk icin gecerli kayit secilmedi.');
            }

            $stmt = $db->prepare("
                UPDATE notification_queue
                SET status = 'pending', planned_at = :planned_at, processed_at = NULL, last_error = NULL,
                    unique_key = CONCAT(COALESCE(unique_key, 'pos-report-retry'), '-retry-', :retry_token)
                WHERE id = :id
                  AND module_name = 'rapor'
                  AND notification_type = 'pos_reconciliation_report'
                  AND channel = 'email'
                  AND status IN ('failed', 'cancelled')
            ");
            $stmt->execute([
                'planned_at' => date('Y-m-d H:i:s'),
                'retry_token' => date('YmdHis'),
                'id' => $queueId,
            ]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Sadece hatali veya iptal edilmis POS rapor e-postalari tekrar kuyruga alinabilir.');
            }

            app_audit_log('rapor', 'retry_pos_report_email', 'notification_queue', $queueId, 'POS rapor e-postasi tekrar kuyruga alindi.');
            app_redirect('index.php?module=rapor&ok=pos_email_retry');
        }
    } catch (Throwable $e) {
        $feedback = 'error:' . $e->getMessage();
    }
}

$defaultReportRecipient = reports_default_recipient($db, $authUser);
app_notifications_ensure_schema($db);

$summary = [
    'Toplam Cari Bakiye' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(x.bakiye), 0) FROM (
            SELECT COALESCE(SUM(CASE WHEN movement_type = 'borc' THEN amount ELSE -amount END), 0) AS bakiye
            FROM cari_movements
            GROUP BY cari_id
        ) x
    "), 2, ',', '.'),
    'Kasa Toplami' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_cash_movements),0)
        FROM finance_cashboxes
    "), 2, ',', '.'),
    'Banka Toplami' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_bank_movements),0)
        FROM finance_bank_accounts
    "), 2, ',', '.'),
    'Aylik Satis' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(grand_total),0) FROM invoice_headers
        WHERE invoice_type = 'satis' AND DATE_FORMAT(invoice_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    "), 2, ',', '.'),
    'Aktif Kira Tutari' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(monthly_rent),0) FROM rental_contracts WHERE status = 'aktif'
    "), 2, ',', '.'),
    'Acik Servis' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE closed_at IS NULL'),
    'Aktif Personel' => app_metric($db, 'SELECT COUNT(*) FROM hr_employees WHERE status = 1'),
    'Basarili POS' => app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'basarili'"),
    'Aylik POS Tahsilat' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(amount),0) FROM collections_transactions
        WHERE status = 'basarili' AND DATE_FORMAT(processed_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    "), 2, ',', '.'),
    'Aylik POS Iade' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(refunded_amount),0) FROM collections_transactions
        WHERE status = 'iade' AND DATE_FORMAT(refunded_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    "), 2, ',', '.'),
    'Aylik POS Komisyon' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(amount),0) FROM finance_expenses
        WHERE source_module = 'tahsilat' AND source_table = 'collections_transactions' AND DATE_FORMAT(expense_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    "), 2, ',', '.'),
    'Webhook Hata' => app_metric($db, "SELECT COUNT(*) FROM collections_webhook_logs WHERE verification_status = 'hatali' AND resolved_at IS NULL"),
    'Tamamlanan Uretim' => app_metric($db, "SELECT COUNT(*) FROM production_orders WHERE status = 'tamamlandi'"),
];

$topCariBalances = app_fetch_all($db, "
    SELECT *
    FROM (
        SELECT
            c.id,
            c.company_name,
            c.full_name,
            COALESCE(SUM(CASE WHEN m.movement_type = 'borc' THEN m.amount ELSE -m.amount END), 0) AS bakiye
        FROM cari_cards c
        LEFT JOIN cari_movements m ON m.cari_id = c.id
        GROUP BY c.id, c.company_name, c.full_name
    ) x
    WHERE x.bakiye <> 0
    ORDER BY ABS(x.bakiye) DESC
    LIMIT 10
");

$criticalStocks = app_fetch_all($db, "
    SELECT
        p.sku,
        p.name,
        p.unit,
        p.critical_stock,
        COALESCE(SUM(CASE WHEN m.movement_type IN ('giris','transfer','sayim','uretim_giris') THEN m.quantity ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN m.movement_type IN ('cikis','uretim_cikis','servis_cikis','kira_cikis') THEN m.quantity ELSE 0 END), 0) AS current_stock
    FROM stock_products p
    LEFT JOIN stock_movements m ON m.product_id = p.id
    GROUP BY p.id, p.sku, p.name, p.unit, p.critical_stock
    HAVING current_stock <= p.critical_stock
    ORDER BY current_stock ASC
    LIMIT 10
");

$upcomingRentalPayments = app_fetch_all($db, "
    SELECT
        p.due_date,
        p.amount,
        p.status,
        r.contract_no,
        c.company_name,
        c.full_name
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    WHERE p.status IN ('bekliyor', 'gecikmis')
    ORDER BY p.due_date ASC
    LIMIT 10
");

$salesAndInvoices = app_fetch_all($db, "
    SELECT 'Teklif' AS report_type, offer_no AS ref_no, offer_date AS ref_date, status, grand_total AS total_amount
    FROM sales_offers
    UNION ALL
    SELECT 'Siparis' AS report_type, order_no AS ref_no, order_date AS ref_date, status, grand_total AS total_amount
    FROM sales_orders
    UNION ALL
    SELECT 'Fatura' AS report_type, invoice_no AS ref_no, invoice_date AS ref_date, invoice_type AS status, grand_total AS total_amount
    FROM invoice_headers
    ORDER BY ref_date DESC
    LIMIT 15
");

$serviceOverview = app_fetch_all($db, "
    SELECT
        s.service_no,
        c.company_name,
        c.full_name,
        st.name AS status_name,
        u.full_name AS assigned_name,
        s.cost_total,
        s.opened_at
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ORDER BY s.id DESC
    LIMIT 10
");

$hrOverview = app_fetch_all($db, "
    SELECT
        e.full_name,
        e.title,
        e.phone,
        e.email,
        d.name AS department_name,
        e.start_date,
        e.status
    FROM hr_employees e
    LEFT JOIN hr_departments d ON d.id = e.department_id
    ORDER BY e.id DESC
    LIMIT 10
");

$productionOverview = app_fetch_all($db, "
    SELECT
        o.order_no,
        p.name AS product_name,
        o.planned_quantity,
        o.actual_quantity,
        o.status,
        o.batch_no
    FROM production_orders o
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    ORDER BY o.id DESC
    LIMIT 10
");

$collectionsOverview = app_fetch_all($db, "
    SELECT
        COALESCE(l.link_code, '-') AS link_code,
        c.company_name,
        c.full_name,
        t.amount,
        t.status,
        t.transaction_ref,
        p.provider_name
    FROM collections_transactions t
    INNER JOIN cari_cards c ON c.id = t.cari_id
    LEFT JOIN collections_links l ON l.id = t.link_id
    LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
    ORDER BY t.id DESC
    LIMIT 10
");

$posDailyOverview = app_fetch_all($db, "
    SELECT
        DATE(COALESCE(processed_at, created_at)) AS report_date,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(CASE WHEN status = 'basarili' THEN amount ELSE 0 END), 0) AS collected_total,
        COALESCE(SUM(CASE WHEN status = 'iade' THEN refunded_amount ELSE 0 END), 0) AS refunded_total,
        COALESCE(SUM(commission_amount), 0) AS commission_total
    FROM collections_transactions
    WHERE COALESCE(processed_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(COALESCE(processed_at, created_at))
    ORDER BY report_date DESC
    LIMIT 30
");

$posProviderOverview = app_fetch_all($db, "
    SELECT
        COALESCE(p.provider_name, 'POS Yok') AS provider_name,
        COUNT(t.id) AS transaction_count,
        COALESCE(SUM(CASE WHEN t.status = 'basarili' THEN t.amount ELSE 0 END), 0) AS collected_total,
        COALESCE(SUM(t.commission_amount), 0) AS commission_total,
        COALESCE(SUM(t.refunded_amount), 0) AS refunded_total
    FROM collections_transactions t
    LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
    GROUP BY COALESCE(p.provider_name, 'POS Yok')
    ORDER BY collected_total DESC
    LIMIT 10
");

$webhookErrorOverview = app_fetch_all($db, "
    SELECT id, transaction_ref, provider_status, http_status, resolved_at, created_at
    FROM collections_webhook_logs
    WHERE verification_status = 'hatali'
    ORDER BY id DESC
    LIMIT 10
");

$posReconciliationOverview = app_fetch_all($db, "
    SELECT
        t.id,
        COALESCE(l.link_code, '-') AS link_code,
        COALESCE(p.provider_name, 'POS Yok') AS provider_name,
        c.company_name,
        c.full_name,
        t.transaction_ref,
        t.status,
        t.provider_status,
        t.amount,
        t.commission_amount,
        t.refunded_amount,
        t.reconciled_at,
        t.processed_at,
        COUNT(CASE WHEN w.verification_status = 'hatali' AND w.resolved_at IS NULL THEN 1 END) AS open_webhook_errors,
        CASE
            WHEN t.status = 'bekliyor' THEN 'Mutabakat bekliyor'
            WHEN t.status = 'hatali' THEN 'Saglayici hatasi'
            WHEN t.status = 'iade' THEN 'Iade edildi'
            WHEN t.status = 'basarili' AND t.reconciled_at IS NULL THEN 'Muhasebelesti / mutabakat eksik'
            WHEN COUNT(CASE WHEN w.verification_status = 'hatali' AND w.resolved_at IS NULL THEN 1 END) > 0 THEN 'Webhook alarmi acik'
            ELSE 'Mutabik'
        END AS reconciliation_status
    FROM collections_transactions t
    INNER JOIN cari_cards c ON c.id = t.cari_id
    LEFT JOIN collections_links l ON l.id = t.link_id
    LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
    LEFT JOIN collections_webhook_logs w ON w.transaction_id = t.id
    GROUP BY t.id, l.link_code, p.provider_name, c.company_name, c.full_name, t.transaction_ref, t.status, t.provider_status,
             t.amount, t.commission_amount, t.refunded_amount, t.reconciled_at, t.processed_at
    ORDER BY t.id DESC
    LIMIT 50
");

$posReportQueueHistory = app_fetch_all($db, "
    SELECT id, recipient_name, recipient_contact, subject_line, status, planned_at, processed_at, provider_name, last_error, created_at
    FROM notification_queue
    WHERE module_name = 'rapor'
      AND notification_type = 'pos_reconciliation_report'
      AND channel = 'email'
    ORDER BY id DESC
    LIMIT 25
");

if ($filters['search'] !== '') {
    $topCariBalances = array_values(array_filter($topCariBalances, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $criticalStocks = array_values(array_filter($criticalStocks, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $upcomingRentalPayments = array_values(array_filter($upcomingRentalPayments, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $salesAndInvoices = array_values(array_filter($salesAndInvoices, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $serviceOverview = array_values(array_filter($serviceOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $hrOverview = array_values(array_filter($hrOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $productionOverview = array_values(array_filter($productionOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $collectionsOverview = array_values(array_filter($collectionsOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $posDailyOverview = array_values(array_filter($posDailyOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $posProviderOverview = array_values(array_filter($posProviderOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $webhookErrorOverview = array_values(array_filter($webhookErrorOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $posReconciliationOverview = array_values(array_filter($posReconciliationOverview, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
    $posReportQueueHistory = array_values(array_filter($posReportQueueHistory, static fn(array $row): bool => reports_row_matches($row, $filters['search'])));
}

$topCariBalances = app_sort_rows($topCariBalances, $filters['report_sort'], [
    'default' => ['bakiye', 'desc'],
    'name_asc' => ['company_name', 'asc'],
    'value_desc' => ['bakiye', 'desc'],
    'value_asc' => ['bakiye', 'asc'],
]);
$criticalStocks = app_sort_rows($criticalStocks, $filters['report_sort'], [
    'default' => ['current_stock', 'asc'],
    'name_asc' => ['name', 'asc'],
    'value_desc' => ['current_stock', 'desc'],
    'value_asc' => ['current_stock', 'asc'],
]);
$upcomingRentalPayments = app_sort_rows($upcomingRentalPayments, $filters['report_sort'], [
    'default' => ['due_date', 'asc'],
    'name_asc' => ['contract_no', 'asc'],
    'value_desc' => ['amount', 'desc'],
    'value_asc' => ['amount', 'asc'],
]);
$salesAndInvoices = app_sort_rows($salesAndInvoices, $filters['report_sort'], [
    'default' => ['ref_date', 'desc'],
    'name_asc' => ['ref_no', 'asc'],
    'value_desc' => ['total_amount', 'desc'],
    'value_asc' => ['total_amount', 'asc'],
]);
$serviceOverview = app_sort_rows($serviceOverview, $filters['report_sort'], [
    'default' => ['opened_at', 'desc'],
    'name_asc' => ['service_no', 'asc'],
    'value_desc' => ['cost_total', 'desc'],
    'value_asc' => ['cost_total', 'asc'],
]);
$hrOverview = app_sort_rows($hrOverview, $filters['report_sort'], [
    'default' => ['start_date', 'desc'],
    'name_asc' => ['full_name', 'asc'],
]);
$productionOverview = app_sort_rows($productionOverview, $filters['report_sort'], [
    'default' => ['order_no', 'desc'],
    'name_asc' => ['product_name', 'asc'],
    'value_desc' => ['planned_quantity', 'desc'],
    'value_asc' => ['planned_quantity', 'asc'],
]);
$collectionsOverview = app_sort_rows($collectionsOverview, $filters['report_sort'], [
    'default' => ['amount', 'desc'],
    'name_asc' => ['link_code', 'asc'],
    'value_desc' => ['amount', 'desc'],
    'value_asc' => ['amount', 'asc'],
]);
$posDailyOverview = app_sort_rows($posDailyOverview, $filters['report_sort'], [
    'default' => ['report_date', 'desc'],
    'value_desc' => ['collected_total', 'desc'],
    'value_asc' => ['collected_total', 'asc'],
]);
$posProviderOverview = app_sort_rows($posProviderOverview, $filters['report_sort'], [
    'default' => ['collected_total', 'desc'],
    'name_asc' => ['provider_name', 'asc'],
    'value_desc' => ['collected_total', 'desc'],
    'value_asc' => ['collected_total', 'asc'],
]);
$posReconciliationOverview = app_sort_rows($posReconciliationOverview, $filters['report_sort'], [
    'default' => ['id', 'desc'],
    'name_asc' => ['provider_name', 'asc'],
    'value_desc' => ['amount', 'desc'],
    'value_asc' => ['amount', 'asc'],
    'status_asc' => ['reconciliation_status', 'asc'],
]);
$posReportQueueHistory = app_sort_rows($posReportQueueHistory, $filters['report_sort'], [
    'default' => ['id', 'desc'],
    'name_asc' => ['recipient_contact', 'asc'],
    'status_asc' => ['status', 'asc'],
]);

$topCariBalancesPagination = app_paginate_rows($topCariBalances, $filters['report_page'], 5);
$criticalStocksPagination = app_paginate_rows($criticalStocks, $filters['report_page'], 5);
$upcomingRentalPaymentsPagination = app_paginate_rows($upcomingRentalPayments, $filters['report_page'], 5);
$salesAndInvoicesPagination = app_paginate_rows($salesAndInvoices, $filters['report_page'], 5);
$serviceOverviewPagination = app_paginate_rows($serviceOverview, $filters['report_page'], 5);
$hrOverviewPagination = app_paginate_rows($hrOverview, $filters['report_page'], 5);
$productionOverviewPagination = app_paginate_rows($productionOverview, $filters['report_page'], 5);
$collectionsOverviewPagination = app_paginate_rows($collectionsOverview, $filters['report_page'], 5);
$posDailyOverviewPagination = app_paginate_rows($posDailyOverview, $filters['report_page'], 7);
$posProviderOverviewPagination = app_paginate_rows($posProviderOverview, $filters['report_page'], 7);
$webhookErrorOverviewPagination = app_paginate_rows($webhookErrorOverview, $filters['report_page'], 7);
$posReconciliationOverviewPagination = app_paginate_rows($posReconciliationOverview, $filters['report_page'], 10);
$posReportQueueHistoryPagination = app_paginate_rows($posReportQueueHistory, $filters['report_page'], 10);
$topCariBalances = $topCariBalancesPagination['items'];
$criticalStocks = $criticalStocksPagination['items'];
$upcomingRentalPayments = $upcomingRentalPaymentsPagination['items'];
$salesAndInvoices = $salesAndInvoicesPagination['items'];
$serviceOverview = $serviceOverviewPagination['items'];
$hrOverview = $hrOverviewPagination['items'];
$productionOverview = $productionOverviewPagination['items'];
$collectionsOverview = $collectionsOverviewPagination['items'];
$posDailyOverview = $posDailyOverviewPagination['items'];
$posProviderOverview = $posProviderOverviewPagination['items'];
$webhookErrorOverview = $webhookErrorOverviewPagination['items'];
$posReconciliationOverview = $posReconciliationOverviewPagination['items'];
$posReportQueueHistory = $posReportQueueHistoryPagination['items'];

$cashTotalValue = (float) str_replace(',', '.', str_replace('.', '', $summary['Kasa Toplami']));
$bankTotalValue = (float) str_replace(',', '.', str_replace('.', '', $summary['Banka Toplami']));
$monthlySalesValue = (float) str_replace(',', '.', str_replace('.', '', $summary['Aylik Satis']));
$activeRentalValue = (float) str_replace(',', '.', str_replace('.', '', $summary['Aktif Kira Tutari']));
$financeBase = max($cashTotalValue + $bankTotalValue, 1);
$commercialBase = max($monthlySalesValue + $activeRentalValue, 1);

$export = trim((string) ($_GET['export'] ?? ''));
if ($export !== '') {
    if ($export === 'cari') {
        $rows = [];
        foreach ($topCariBalances as $row) {
            $rows[] = [
                $row['company_name'] ?: $row['full_name'] ?: ('Cari #' . $row['id']),
                number_format((float) $row['bakiye'], 2, '.', ''),
            ];
        }

        reports_csv_download('cari-bakiyeleri.csv', ['Cari', 'Bakiye'], $rows);
    }

    if ($export === 'stok') {
        $rows = [];
        foreach ($criticalStocks as $row) {
            $rows[] = [
                $row['sku'],
                $row['name'],
                number_format((float) $row['current_stock'], 3, '.', ''),
                $row['unit'],
                number_format((float) $row['critical_stock'], 3, '.', ''),
            ];
        }

        reports_csv_download('kritik-stoklar.csv', ['SKU', 'Urun', 'Mevcut', 'Birim', 'Kritik'], $rows);
    }

    if ($export === 'kira') {
        $rows = [];
        foreach ($upcomingRentalPayments as $row) {
            $rows[] = [
                $row['contract_no'],
                $row['company_name'] ?: $row['full_name'] ?: '-',
                $row['due_date'],
                $row['status'],
                number_format((float) $row['amount'], 2, '.', ''),
            ];
        }

        reports_csv_download('kira-tahsilatlari.csv', ['Sozlesme', 'Cari', 'Vade', 'Durum', 'Tutar'], $rows);
    }

    if ($export === 'akis') {
        $rows = [];
        foreach ($salesAndInvoices as $row) {
            $rows[] = [
                $row['report_type'],
                $row['ref_no'],
                $row['ref_date'],
                $row['status'],
                number_format((float) $row['total_amount'], 2, '.', ''),
            ];
        }

        reports_csv_download('satis-fatura-akisi.csv', ['Tur', 'Belge', 'Tarih', 'Durum', 'Tutar'], $rows);
    }

    if ($export === 'ik') {
        $rows = [];
        foreach ($hrOverview as $row) {
            $rows[] = [
                $row['full_name'],
                $row['department_name'] ?: '-',
                $row['title'] ?: '-',
                $row['phone'] ?: '-',
                $row['email'] ?: '-',
                $row['start_date'] ?: '-',
                (int) $row['status'] === 1 ? 'Aktif' : 'Pasif',
            ];
        }

        reports_csv_download('personel-ozeti.csv', ['Personel', 'Departman', 'Unvan', 'Telefon', 'E-posta', 'Baslama', 'Durum'], $rows);
    }

    if ($export === 'uretim') {
        $rows = [];
        foreach ($productionOverview as $row) {
            $rows[] = [
                $row['order_no'],
                $row['product_name'],
                number_format((float) $row['planned_quantity'], 3, '.', ''),
                number_format((float) ($row['actual_quantity'] ?? 0), 3, '.', ''),
                $row['status'],
                $row['batch_no'] ?: '-',
            ];
        }

        reports_csv_download('uretim-emirleri.csv', ['Emir', 'Urun', 'Plan', 'Gerceklesen', 'Durum', 'Batch'], $rows);
    }

    if ($export === 'tahsilat') {
        $rows = [];
        foreach ($collectionsOverview as $row) {
            $rows[] = [
                $row['link_code'],
                $row['company_name'] ?: $row['full_name'] ?: '-',
                $row['provider_name'] ?: '-',
                number_format((float) $row['amount'], 2, '.', ''),
                $row['status'],
                $row['transaction_ref'] ?: '-',
            ];
        }

        reports_csv_download('tahsilat-islemleri.csv', ['Link', 'Cari', 'POS', 'Tutar', 'Durum', 'Ref'], $rows);
    }

    if ($export === 'pos_rapor') {
        $rows = [];
        foreach ($posDailyOverview as $row) {
            $rows[] = [
                $row['report_date'],
                (int) $row['transaction_count'],
                number_format((float) $row['collected_total'], 2, '.', ''),
                number_format((float) $row['refunded_total'], 2, '.', ''),
                number_format((float) $row['commission_total'], 2, '.', ''),
            ];
        }

        reports_csv_download('pos-gunluk-rapor.csv', ['Tarih', 'Islem', 'Tahsilat', 'Iade', 'Komisyon'], $rows);
    }

    if ($export === 'pos_mutabakat') {
        $rows = [];
        foreach ($posReconciliationOverview as $row) {
            $rows[] = [
                $row['link_code'],
                $row['company_name'] ?: $row['full_name'] ?: '-',
                $row['provider_name'],
                $row['transaction_ref'] ?: '-',
                $row['status'],
                $row['provider_status'] ?: '-',
                number_format((float) $row['amount'], 2, '.', ''),
                number_format((float) $row['commission_amount'], 2, '.', ''),
                number_format((float) $row['refunded_amount'], 2, '.', ''),
                $row['reconciled_at'] ?: '-',
                (int) $row['open_webhook_errors'],
                $row['reconciliation_status'],
            ];
        }

        reports_csv_download('pos-mutabakat-raporu.csv', ['Link', 'Cari', 'POS', 'Ref', 'Durum', 'Saglayici', 'Tutar', 'Komisyon', 'Iade', 'Mutabakat', 'Acik Webhook', 'Rapor Durumu'], $rows);
    }

    if ($export === 'pos_rapor_kuyruk') {
        $rows = [];
        foreach ($posReportQueueHistory as $row) {
            $rows[] = [
                (int) $row['id'],
                $row['recipient_name'] ?: '-',
                $row['recipient_contact'],
                $row['subject_line'] ?: '-',
                $row['status'],
                $row['provider_name'] ?: '-',
                $row['planned_at'],
                $row['processed_at'] ?: '-',
                $row['created_at'],
                $row['last_error'] ?: '-',
            ];
        }

        reports_csv_download('pos-rapor-kuyruk-gecmisi.csv', ['ID', 'Alici', 'E-posta', 'Konu', 'Durum', 'Saglayici', 'Plan', 'Islenen', 'Olusturma', 'Son Hata'], $rows);
    }
}
?>

<style>
    .report-toolbar { display:flex; flex-wrap:wrap; gap:10px; margin-top:24px; }
    .report-toolbar a, .report-toolbar button { text-decoration:none; padding:10px 14px; border-radius:12px; background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; font-weight:700; cursor:pointer; }
    .report-toolbar form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0; }
    .report-toolbar input { min-width:220px; border:1px solid #fed7aa; border-radius:12px; padding:10px 12px; }
    .report-feedback { margin-top:18px; padding:14px 16px; border-radius:16px; border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; font-weight:700; }
    .report-feedback.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .report-signal { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; margin-top:24px; }
    .signal-card { background:#fff; border:1px solid #eadfce; border-radius:22px; padding:18px; }
    .signal-card h4 { margin:0 0 12px; font-size:1rem; color:#7c2d12; }
    .signal-metric { display:flex; justify-content:space-between; gap:12px; margin-bottom:10px; font-size:.95rem; }
    .signal-bar { height:12px; border-radius:999px; background:#f3e8d9; overflow:hidden; }
    .signal-fill { height:100%; background:linear-gradient(90deg,#f97316,#ea580c); border-radius:999px; }
</style>

<section class="module-grid">
    <?php foreach ($summary as $label => $value): ?>
        <div class="card">
            <small><?= app_h($label) ?></small>
            <strong><?= app_h((string) $value) ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($feedback !== ''): ?>
    <div class="report-feedback <?= strpos($feedback, 'error:') === 0 ? 'error' : '' ?>">
        <?php
            $feedbackMessages = [
                'pos_email' => 'POS mutabakat raporu e-posta kuyruguna alindi.',
                'pos_email_cancel' => 'POS rapor e-postasi iptal edildi.',
                'pos_email_retry' => 'POS rapor e-postasi tekrar kuyruga alindi.',
            ];
        ?>
        <?= app_h($feedbackMessages[$feedback] ?? preg_replace('/^error:/', '', $feedback)) ?>
    </div>
<?php endif; ?>

<section class="card">
    <h3>Rapor Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="rapor">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Cari, belge, personel, sozlesme, servis">
        </div>
        <div>
            <label>Odak</label>
            <select name="focus">
                <option value="">Tum raporlar</option>
                <option value="finans" <?= $filters['focus'] === 'finans' ? 'selected' : '' ?>>Finans</option>
                <option value="stok" <?= $filters['focus'] === 'stok' ? 'selected' : '' ?>>Stok</option>
                <option value="kira" <?= $filters['focus'] === 'kira' ? 'selected' : '' ?>>Kira</option>
                <option value="operasyon" <?= $filters['focus'] === 'operasyon' ? 'selected' : '' ?>>Operasyon</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=rapor" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="rapor">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="focus" value="<?= app_h($filters['focus']) ?>">
        <div>
            <label>Genel Siralama</label>
            <select name="report_sort">
                <option value="default" <?= $filters['report_sort'] === 'default' ? 'selected' : '' ?>>Varsayilan</option>
                <option value="name_asc" <?= $filters['report_sort'] === 'name_asc' ? 'selected' : '' ?>>Metin A-Z</option>
                <option value="value_desc" <?= $filters['report_sort'] === 'value_desc' ? 'selected' : '' ?>>Deger yuksek</option>
                <option value="value_asc" <?= $filters['report_sort'] === 'value_asc' ? 'selected' : '' ?>>Deger dusuk</option>
            </select>
        </div>
        <div>
            <label>Sayfa</label>
            <input type="number" name="report_page" min="1" value="<?= (int) $filters['report_page'] ?>">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="report-toolbar">
    <a href="?module=rapor&export=cari">Cari CSV</a>
    <a href="?module=rapor&export=stok">Kritik Stok CSV</a>
    <a href="?module=rapor&export=kira">Kira Tahsilat CSV</a>
    <a href="?module=rapor&export=akis">Satis/Fatura CSV</a>
    <a href="?module=rapor&export=ik">Personel CSV</a>
    <a href="?module=rapor&export=uretim">Uretim CSV</a>
    <a href="?module=rapor&export=tahsilat">Tahsilat CSV</a>
    <a href="?module=rapor&export=pos_rapor">POS Gunluk CSV</a>
    <a href="?module=rapor&export=pos_mutabakat">POS Mutabakat CSV</a>
    <a href="?module=rapor&export=pos_rapor_kuyruk">POS Rapor Kuyruk CSV</a>
    <a href="print.php?type=pos_reconciliation_report" target="_blank" rel="noopener">POS Mutabakat Yazdir</a>
    <form method="post">
        <input type="hidden" name="action" value="queue_pos_reconciliation_email">
        <input type="email" name="recipient_email" value="<?= app_h($defaultReportRecipient['email']) ?>" placeholder="rapor@alanadi.com">
        <button type="submit">POS Raporunu E-postaya Al</button>
    </form>
</section>

<section class="report-signal">
    <div class="signal-card">
        <h4>Finans Dagilimi</h4>
        <div class="signal-metric"><span>Kasa</span><strong><?= app_h($summary['Kasa Toplami']) ?></strong></div>
        <div class="signal-bar"><div class="signal-fill" style="width: <?= number_format(($cashTotalValue / $financeBase) * 100, 2, '.', '') ?>%"></div></div>
        <div class="signal-metric" style="margin-top:12px;"><span>Banka</span><strong><?= app_h($summary['Banka Toplami']) ?></strong></div>
        <div class="signal-bar"><div class="signal-fill" style="width: <?= number_format(($bankTotalValue / $financeBase) * 100, 2, '.', '') ?>%"></div></div>
    </div>

    <div class="signal-card">
        <h4>Ticari Akis</h4>
        <div class="signal-metric"><span>Aylik Satis</span><strong><?= app_h($summary['Aylik Satis']) ?></strong></div>
        <div class="signal-bar"><div class="signal-fill" style="width: <?= number_format(($monthlySalesValue / $commercialBase) * 100, 2, '.', '') ?>%"></div></div>
        <div class="signal-metric" style="margin-top:12px;"><span>Aktif Kira</span><strong><?= app_h($summary['Aktif Kira Tutari']) ?></strong></div>
        <div class="signal-bar"><div class="signal-fill" style="width: <?= number_format(($activeRentalValue / $commercialBase) * 100, 2, '.', '') ?>%"></div></div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>En Yuksek Cari Bakiyeleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cari</th><th>Bakiye</th></tr></thead>
                <tbody>
                <?php foreach ($topCariBalances as $row): ?>
                    <tr>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: ('Cari #' . $row['id'])) ?></td>
                        <td><?= number_format((float) $row['bakiye'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Kritik Stoklar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>SKU</th><th>Urun</th><th>Mevcut</th><th>Kritik</th></tr></thead>
                <tbody>
                <?php foreach ($criticalStocks as $row): ?>
                    <tr>
                        <td><?= app_h($row['sku']) ?></td>
                        <td><?= app_h($row['name']) ?></td>
                        <td><?= number_format((float) $row['current_stock'], 3, ',', '.') . ' ' . app_h($row['unit']) ?></td>
                        <td><?= number_format((float) $row['critical_stock'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yaklasan Kira Tahsilatlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Vade</th><th>Durum</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($upcomingRentalPayments as $row): ?>
                    <tr>
                        <td><?= app_h($row['contract_no']) ?></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['due_date']) ?></td>
                        <td><?= app_h($row['status']) ?></td>
                        <td><?= number_format((float) $row['amount'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Satis ve Fatura Akisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tur</th><th>Belge</th><th>Tarih</th><th>Durum</th><th>Tutar</th></tr></thead>
                <tbody>
                <?php foreach ($salesAndInvoices as $row): ?>
                    <tr>
                        <td><?= app_h($row['report_type']) ?></td>
                        <td><?= app_h($row['ref_no']) ?></td>
                        <td><?= app_h($row['ref_date']) ?></td>
                        <td><?= app_h($row['status']) ?></td>
                        <td><?= number_format((float) $row['total_amount'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Servis Operasyon Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Servis</th><th>Cari</th><th>Durum</th><th>Personel</th><th>Maliyet</th></tr></thead>
                <tbody>
                <?php foreach ($serviceOverview as $row): ?>
                    <tr>
                        <td><?= app_h($row['service_no']) ?></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['status_name'] ?: '-') ?></td>
                        <td><?= app_h($row['assigned_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $row['cost_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Personel ve IK Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Personel</th><th>Departman</th><th>Unvan</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($hrOverview as $row): ?>
                    <tr>
                        <td><?= app_h($row['full_name']) ?></td>
                        <td><?= app_h($row['department_name'] ?: '-') ?></td>
                        <td><?= app_h($row['title'] ?: '-') ?></td>
                        <td><?= (int) $row['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Uretim Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Urun</th><th>Plan</th><th>Gerceklesen</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($productionOverview as $row): ?>
                    <tr>
                        <td><?= app_h($row['order_no']) ?></td>
                        <td><?= app_h($row['product_name']) ?></td>
                        <td><?= number_format((float) $row['planned_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) ($row['actual_quantity'] ?? 0), 3, ',', '.') ?></td>
                        <td><?= app_h($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Tahsilat ve POS Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Link</th><th>Cari</th><th>POS</th><th>Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($collectionsOverview as $row): ?>
                    <tr>
                        <td><?= app_h($row['link_code']) ?></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['provider_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $row['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>POS Gunluk Performans</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Islem</th><th>Tahsilat</th><th>Iade</th><th>Komisyon</th></tr></thead>
                <tbody>
                <?php foreach ($posDailyOverview as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['report_date']) ?></td>
                        <td><?= (int) $row['transaction_count'] ?></td>
                        <td><?= number_format((float) $row['collected_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['refunded_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['commission_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>POS Saglayici Kirilimi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>POS</th><th>Islem</th><th>Tahsilat</th><th>Iade</th><th>Komisyon</th></tr></thead>
                <tbody>
                <?php foreach ($posProviderOverview as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['provider_name']) ?></td>
                        <td><?= (int) $row['transaction_count'] ?></td>
                        <td><?= number_format((float) $row['collected_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['refunded_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['commission_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Webhook Hata Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Log</th><th>Ref</th><th>Status</th><th>HTTP</th><th>Cozum</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($webhookErrorOverview as $row): ?>
                    <tr>
                        <td><a href="collections_detail.php?type=webhook&id=<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a></td>
                        <td><?= app_h($row['transaction_ref'] ?: '-') ?></td>
                        <td><?= app_h($row['provider_status'] ?: '-') ?></td>
                        <td><?= app_h((string) ($row['http_status'] ?: '-')) ?></td>
                        <td><?= app_h($row['resolved_at'] ?: 'Acik') ?></td>
                        <td><?= app_h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>POS Mutabakat Raporu</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Link</th><th>Cari</th><th>POS</th><th>Ref</th><th>Tutar</th><th>Durum</th><th>Saglayici</th><th>Mutabakat</th><th>Acik Webhook</th><th>Rapor Durumu</th></tr></thead>
                <tbody>
                <?php foreach ($posReconciliationOverview as $row): ?>
                    <tr>
                        <td><?= app_h($row['link_code']) ?></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['provider_name']) ?></td>
                        <td><?= app_h($row['transaction_ref'] ?: '-') ?></td>
                        <td>
                            <?= number_format((float) $row['amount'], 2, ',', '.') ?><br>
                            <small>Kom: <?= number_format((float) $row['commission_amount'], 2, ',', '.') ?> / Iade: <?= number_format((float) $row['refunded_amount'], 2, ',', '.') ?></small>
                        </td>
                        <td><?= app_h($row['status']) ?></td>
                        <td><?= app_h($row['provider_status'] ?: '-') ?></td>
                        <td><?= app_h($row['reconciled_at'] ?: '-') ?></td>
                        <td><?= (int) $row['open_webhook_errors'] ?></td>
                        <td><?= app_h($row['reconciliation_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>POS Rapor E-posta Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Alici</th><th>Konu</th><th>Durum</th><th>Saglayici</th><th>Plan</th><th>Islenen</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($posReportQueueHistory as $row): ?>
                    <tr>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td>
                            <?= app_h($row['recipient_name'] ?: '-') ?><br>
                            <small><?= app_h($row['recipient_contact']) ?></small>
                        </td>
                        <td><?= app_h($row['subject_line'] ?: '-') ?></td>
                        <td><?= app_h($row['status']) ?></td>
                        <td><?= app_h($row['provider_name'] ?: '-') ?></td>
                        <td><?= app_h($row['planned_at']) ?></td>
                        <td><?= app_h($row['processed_at'] ?: '-') ?></td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" onsubmit="return confirm('Bekleyen POS rapor e-postasi iptal edilsin mi?');">
                                    <input type="hidden" name="action" value="cancel_pos_report_email">
                                    <input type="hidden" name="queue_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit">Iptal</button>
                                </form>
                            <?php elseif (in_array($row['status'], ['failed', 'cancelled'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="retry_pos_report_email">
                                    <input type="hidden" name="queue_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit">Tekrar Kuyruga Al</button>
                                </form>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($row['last_error'])): ?>
                        <tr>
                            <td colspan="8" style="color:#991b1b;"><?= app_h((string) $row['last_error']) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$posReportQueueHistory): ?>
                    <tr><td colspan="8">Henuz POS rapor e-posta kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
