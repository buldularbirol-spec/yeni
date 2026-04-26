<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Kira modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function rental_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function rental_next_contract_no(PDO $db): string
{
    return app_document_series_number($db, 'docs.rental_series', 'docs.rental_prefix', 'KIR', 'rental_contracts', 'start_date');
}

function rental_post_redirect(string $result): void
{
    app_redirect('index.php?module=kira&ok=' . urlencode($result));
}

function rental_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'contract_status' => trim((string) ($_GET['contract_status'] ?? '')),
        'device_status' => trim((string) ($_GET['device_status'] ?? '')),
        'device_sort' => trim((string) ($_GET['device_sort'] ?? 'id_desc')),
        'contract_sort' => trim((string) ($_GET['contract_sort'] ?? 'id_desc')),
        'device_page' => max(1, (int) ($_GET['device_page'] ?? 1)),
        'contract_page' => max(1, (int) ($_GET['contract_page'] ?? 1)),
    ];
}

function rental_selected_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function rental_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
    ');
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function rental_ensure_renewal_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS rental_contract_renewals (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            contract_id BIGINT NOT NULL,
            old_end_date DATE NULL,
            new_end_date DATE NULL,
            old_monthly_rent DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            new_monthly_rent DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            renewal_date DATE NOT NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rental_contract_renewals_contract (contract_id)
        ) ENGINE=InnoDB
    ");
}

function rental_ensure_accrual_schema(PDO $db): void
{
    if (!rental_column_exists($db, 'rental_payments', 'accrual_period')) {
        $db->exec("ALTER TABLE rental_payments ADD COLUMN accrual_period VARCHAR(7) NULL AFTER paid_at");
    }

    if (!rental_column_exists($db, 'rental_payments', 'accrual_source')) {
        $db->exec("ALTER TABLE rental_payments ADD COLUMN accrual_source VARCHAR(40) NULL AFTER accrual_period");
    }
}

function rental_ensure_periodic_invoice_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS rental_payment_invoices (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            payment_id BIGINT NOT NULL,
            invoice_id BIGINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rental_payment_invoice_payment (payment_id),
            INDEX idx_rental_payment_invoice_invoice (invoice_id)
        ) ENGINE=InnoDB
    ");
}

function rental_ensure_protocol_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS rental_contract_protocols (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            contract_id BIGINT NOT NULL,
            protocol_no VARCHAR(100) NOT NULL,
            protocol_date DATE NOT NULL,
            effective_date DATE NULL,
            subject VARCHAR(180) NOT NULL,
            amount_effect DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            status ENUM('taslak','aktif','iptal') NOT NULL DEFAULT 'aktif',
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rental_contract_protocols_contract (contract_id),
            UNIQUE KEY uq_rental_contract_protocol_no (protocol_no)
        ) ENGINE=InnoDB
    ");
}

function rental_ensure_return_checklist_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS rental_return_checklists (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            contract_id BIGINT NOT NULL,
            device_id BIGINT NOT NULL,
            return_date DATE NOT NULL,
            device_condition ENUM('iyi','bakim_gerekli','hasarli','eksik') NOT NULL DEFAULT 'iyi',
            accessories_ok TINYINT(1) NOT NULL DEFAULT 0,
            power_adapter_ok TINYINT(1) NOT NULL DEFAULT 0,
            documents_ok TINYINT(1) NOT NULL DEFAULT 0,
            photos_ok TINYINT(1) NOT NULL DEFAULT 0,
            cleaning_ok TINYINT(1) NOT NULL DEFAULT 0,
            damage_note TEXT NULL,
            missing_note TEXT NULL,
            deposit_deduction DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            received_by VARCHAR(150) NULL,
            next_device_status ENUM('aktif','pasif','bakimda','kirada') NOT NULL DEFAULT 'aktif',
            close_contract TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rental_return_checklists_contract (contract_id),
            INDEX idx_rental_return_checklists_device (device_id)
        ) ENGINE=InnoDB
    ");
}

function rental_due_date_for_period(int $year, int $month, int $billingDay): DateTimeImmutable
{
    $safeBillingDay = max(1, min(31, $billingDay));
    $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $lastDay = (int) $periodStart->format('t');
    $day = min($safeBillingDay, $lastDay);

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

rental_ensure_renewal_schema($db);
rental_ensure_accrual_schema($db);
rental_ensure_periodic_invoice_schema($db);
rental_ensure_protocol_schema($db);
rental_ensure_return_checklist_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_rental_category') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Kategori adi zorunludur.');
            }

            $stmt = $db->prepare('INSERT INTO rental_device_categories (name) VALUES (:name)');
            $stmt->execute(['name' => $name]);

            rental_post_redirect('category');
        }

        if ($action === 'create_rental_device') {
            $deviceName = trim((string) ($_POST['device_name'] ?? ''));
            $serialNo = trim((string) ($_POST['serial_no'] ?? ''));

            if ($deviceName === '' || $serialNo === '') {
                throw new RuntimeException('Cihaz adi ve seri no zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO rental_devices (
                    category_id, product_id, device_name, serial_no, status, location_text, purchase_date, purchase_cost, notes
                ) VALUES (
                    :category_id, :product_id, :device_name, :serial_no, :status, :location_text, :purchase_date, :purchase_cost, :notes
                )
            ');
            $stmt->execute([
                'category_id' => (int) ($_POST['category_id'] ?? 0) ?: null,
                'product_id' => (int) ($_POST['product_id'] ?? 0) ?: null,
                'device_name' => $deviceName,
                'serial_no' => $serialNo,
                'status' => $_POST['status'] ?? 'aktif',
                'location_text' => trim((string) ($_POST['location_text'] ?? '')) ?: null,
                'purchase_date' => trim((string) ($_POST['purchase_date'] ?? '')) ?: null,
                'purchase_cost' => (float) ($_POST['purchase_cost'] ?? 0),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            rental_post_redirect('device');
        }

        if ($action === 'create_rental_contract') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $deviceId = (int) ($_POST['device_id'] ?? 0);
            $startDate = trim((string) ($_POST['start_date'] ?? ''));

            if ($cariId <= 0 || $deviceId <= 0 || $startDate === '') {
                throw new RuntimeException('Cari, cihaz ve baslangic tarihi zorunludur.');
            }

            $contractNo = rental_next_contract_no($db);

            $stmt = $db->prepare('
                INSERT INTO rental_contracts (
                    branch_id, cari_id, device_id, contract_no, start_date, end_date, monthly_rent, deposit_amount, status, billing_day, notes
                ) VALUES (
                    :branch_id, :cari_id, :device_id, :contract_no, :start_date, :end_date, :monthly_rent, :deposit_amount, :status, :billing_day, :notes
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'cari_id' => $cariId,
                'device_id' => $deviceId,
                'contract_no' => $contractNo,
                'start_date' => $startDate,
                'end_date' => trim((string) ($_POST['end_date'] ?? '')) ?: null,
                'monthly_rent' => (float) ($_POST['monthly_rent'] ?? 0),
                'deposit_amount' => (float) ($_POST['deposit_amount'] ?? 0),
                'status' => $_POST['status'] ?? 'taslak',
                'billing_day' => (int) ($_POST['billing_day'] ?? 1) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            $contractId = (int) $db->lastInsertId();

            $stmt = $db->prepare('UPDATE rental_devices SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => ($_POST['status'] ?? 'taslak') === 'aktif' ? 'kirada' : 'aktif',
                'id' => $deviceId,
            ]);

            if ((float) ($_POST['monthly_rent'] ?? 0) > 0) {
                $stmt = $db->prepare('
                    INSERT INTO rental_payments (contract_id, due_date, amount, status, paid_at, accrual_period, accrual_source)
                    VALUES (:contract_id, :due_date, :amount, :status, NULL, :accrual_period, :accrual_source)
                ');
                $stmt->execute([
                    'contract_id' => $contractId,
                    'due_date' => $startDate,
                    'amount' => (float) ($_POST['monthly_rent'] ?? 0),
                    'status' => 'bekliyor',
                    'accrual_period' => substr($startDate, 0, 7),
                    'accrual_source' => 'ilk_kayit',
                ]);

                app_insert_cari_movement($db, [
                    'cari_id' => $cariId,
                    'movement_type' => 'borc',
                    'source_module' => 'kira',
                    'source_table' => 'rental_contracts',
                    'source_id' => $contractId,
                    'amount' => (float) ($_POST['monthly_rent'] ?? 0),
                    'currency_code' => 'TRY',
                    'description' => $contractNo . ' ilk kira tahakkuku',
                    'movement_date' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                ]);
            }

            if ((float) ($_POST['deposit_amount'] ?? 0) > 0) {
                app_insert_cari_movement($db, [
                    'cari_id' => $cariId,
                    'movement_type' => 'borc',
                    'source_module' => 'kira',
                    'source_table' => 'rental_contracts',
                    'source_id' => $contractId,
                    'amount' => (float) ($_POST['deposit_amount'] ?? 0),
                    'currency_code' => 'TRY',
                    'description' => $contractNo . ' depozito tahakkuku',
                    'movement_date' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                ]);
            }

            $stmt = $db->prepare('
                INSERT INTO rental_service_logs (device_id, event_type, event_date, description)
                VALUES (:device_id, :event_type, :event_date, :description)
            ');
            $stmt->execute([
                'device_id' => $deviceId,
                'event_type' => 'teslim',
                'event_date' => date('Y-m-d H:i:s'),
                'description' => $contractNo . ' sozlesmesi ile kira kaydi olusturuldu',
            ]);

            rental_post_redirect('contract');
        }

        if ($action === 'create_rental_log') {
            $deviceId = (int) ($_POST['device_id'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($deviceId <= 0 || $description === '') {
                throw new RuntimeException('Cihaz ve aciklama zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO rental_service_logs (device_id, event_type, event_date, description)
                VALUES (:device_id, :event_type, :event_date, :description)
            ');
            $stmt->execute([
                'device_id' => $deviceId,
                'event_type' => $_POST['event_type'] ?? 'bakim',
                'event_date' => date('Y-m-d H:i:s'),
                'description' => $description,
            ]);

            rental_post_redirect('log');
        }

        if ($action === 'update_rental_device_status') {
            $deviceId = (int) ($_POST['device_id'] ?? 0);

            if ($deviceId <= 0) {
                throw new RuntimeException('Gecerli bir cihaz secilmedi.');
            }

            $stmt = $db->prepare('UPDATE rental_devices SET status = :status, location_text = :location_text WHERE id = :id');
            $stmt->execute([
                'status' => trim((string) ($_POST['status'] ?? 'aktif')) ?: 'aktif',
                'location_text' => trim((string) ($_POST['location_text'] ?? '')) ?: null,
                'id' => $deviceId,
            ]);

            rental_post_redirect('device_updated');
        }

        if ($action === 'bulk_update_rental_devices') {
            $deviceIds = rental_selected_ids('device_ids');
            $status = trim((string) ($_POST['bulk_device_status'] ?? ''));
            $locationText = trim((string) ($_POST['bulk_location_text'] ?? ''));

            if ($deviceIds === [] || $status === '') {
                throw new RuntimeException('Cihaz secimi veya durum gecersiz.');
            }

            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $params = array_merge([$status, $locationText !== '' ? $locationText : null], $deviceIds);
            $stmt = $db->prepare("UPDATE rental_devices SET status = ?, location_text = COALESCE(?, location_text) WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            rental_post_redirect('device_bulk_updated');
        }

        if ($action === 'update_rental_contract_status') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);
            $deviceId = (int) ($_POST['device_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'taslak')) ?: 'taslak';

            if ($contractId <= 0) {
                throw new RuntimeException('Gecerli bir sozlesme secilmedi.');
            }
            app_assert_branch_access($db, 'rental_contracts', $contractId);

            $stmt = $db->prepare('UPDATE rental_contracts SET status = :status, billing_day = :billing_day WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'billing_day' => (int) ($_POST['billing_day'] ?? 1) ?: null,
                'id' => $contractId,
            ]);

            if ($deviceId > 0) {
                $deviceStatus = 'aktif';
                if ($status === 'aktif') {
                    $deviceStatus = 'kirada';
                } elseif ($status === 'iptal') {
                    $deviceStatus = 'pasif';
                }

                $stmt = $db->prepare('UPDATE rental_devices SET status = :status WHERE id = :id');
                $stmt->execute([
                    'status' => $deviceStatus,
                    'id' => $deviceId,
                ]);
            }

            rental_post_redirect('contract_updated');
        }

        if ($action === 'renew_rental_contract') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);
            $newEndDate = trim((string) ($_POST['new_end_date'] ?? ''));
            $newMonthlyRent = (float) ($_POST['new_monthly_rent'] ?? 0);
            $renewalDate = trim((string) ($_POST['renewal_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');

            if ($contractId <= 0 || $newEndDate === '' || $newMonthlyRent <= 0) {
                throw new RuntimeException('Sozlesme, yeni bitis tarihi ve yeni aylik kira zorunludur.');
            }

            $contractRows = app_fetch_all($db, '
                SELECT id, device_id, contract_no, end_date, monthly_rent
                FROM rental_contracts
                WHERE id = :id
                LIMIT 1
            ', ['id' => $contractId]);

            if (!$contractRows) {
                throw new RuntimeException('Yenilenecek sozlesme bulunamadi.');
            }

            $contract = $contractRows[0];
            $stmt = $db->prepare('
                INSERT INTO rental_contract_renewals (
                    contract_id, old_end_date, new_end_date, old_monthly_rent, new_monthly_rent, renewal_date, notes, created_by
                ) VALUES (
                    :contract_id, :old_end_date, :new_end_date, :old_monthly_rent, :new_monthly_rent, :renewal_date, :notes, :created_by
                )
            ');
            $stmt->execute([
                'contract_id' => $contractId,
                'old_end_date' => $contract['end_date'] ?: null,
                'new_end_date' => $newEndDate,
                'old_monthly_rent' => (float) $contract['monthly_rent'],
                'new_monthly_rent' => $newMonthlyRent,
                'renewal_date' => $renewalDate,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => 1,
            ]);

            $stmt = $db->prepare('UPDATE rental_contracts SET end_date = :end_date, monthly_rent = :monthly_rent, status = :status WHERE id = :id');
            $stmt->execute([
                'end_date' => $newEndDate,
                'monthly_rent' => $newMonthlyRent,
                'status' => 'aktif',
                'id' => $contractId,
            ]);

            $stmt = $db->prepare('UPDATE rental_devices SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => 'kirada',
                'id' => (int) $contract['device_id'],
            ]);

            $stmt = $db->prepare('
                INSERT INTO rental_service_logs (device_id, event_type, event_date, description)
                VALUES (:device_id, :event_type, :event_date, :description)
            ');
            $stmt->execute([
                'device_id' => (int) $contract['device_id'],
                'event_type' => 'teslim',
                'event_date' => date('Y-m-d H:i:s'),
                'description' => (string) $contract['contract_no'] . ' sozlesmesi ' . $newEndDate . ' tarihine kadar yenilendi',
            ]);

            rental_post_redirect('contract_renewed');
        }

        if ($action === 'generate_rental_accruals') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);
            $accrualUntil = trim((string) ($_POST['accrual_until'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $maxPeriods = max(1, min(36, (int) ($_POST['max_periods'] ?? 12)));

            $untilDate = DateTimeImmutable::createFromFormat('Y-m-d', $accrualUntil);
            if (!$untilDate) {
                throw new RuntimeException('Tahakkuk bitis tarihi gecersiz.');
            }

            $params = [];
            $contractFilter = '';
            if ($contractId > 0) {
                $contractFilter = 'AND r.id = :contract_id';
                $params['contract_id'] = $contractId;
            }

            $accrualContracts = app_fetch_all($db, '
                SELECT r.id, r.cari_id, r.contract_no, r.start_date, r.end_date, r.monthly_rent, r.billing_day
                FROM rental_contracts r
                WHERE r.status = \'aktif\' AND r.monthly_rent > 0 ' . $contractFilter . '
                ORDER BY r.start_date ASC
            ', $params);

            $createdCount = 0;
            $db->beginTransaction();

            foreach ($accrualContracts as $contract) {
                $startDate = new DateTimeImmutable((string) $contract['start_date']);
                $endDate = !empty($contract['end_date']) ? new DateTimeImmutable((string) $contract['end_date']) : $untilDate;
                $limitDate = $endDate < $untilDate ? $endDate : $untilDate;
                if ($limitDate < $startDate) {
                    continue;
                }

                $billingDay = (int) ($contract['billing_day'] ?: (int) $startDate->format('d'));
                $periodCursor = new DateTimeImmutable($startDate->format('Y-m-01'));
                $periodLimit = new DateTimeImmutable($limitDate->format('Y-m-01'));
                $generatedForContract = 0;

                while ($periodCursor <= $periodLimit && $generatedForContract < $maxPeriods) {
                    $dueDate = rental_due_date_for_period(
                        (int) $periodCursor->format('Y'),
                        (int) $periodCursor->format('m'),
                        $billingDay
                    );
                    if ($dueDate < $startDate && $periodCursor->format('Y-m') === $startDate->format('Y-m')) {
                        $dueDate = $startDate;
                    }

                    if ($dueDate <= $limitDate) {
                        $period = $periodCursor->format('Y-m');
                        $existingRows = app_fetch_all($db, '
                            SELECT id
                            FROM rental_payments
                            WHERE contract_id = :contract_id
                              AND (due_date = :due_date OR accrual_period = :accrual_period)
                            LIMIT 1
                        ', [
                            'contract_id' => (int) $contract['id'],
                            'due_date' => $dueDate->format('Y-m-d'),
                            'accrual_period' => $period,
                        ]);

                        if (!$existingRows) {
                            $stmt = $db->prepare('
                                INSERT INTO rental_payments (contract_id, due_date, amount, status, paid_at, accrual_period, accrual_source)
                                VALUES (:contract_id, :due_date, :amount, :status, NULL, :accrual_period, :accrual_source)
                            ');
                            $stmt->execute([
                                'contract_id' => (int) $contract['id'],
                                'due_date' => $dueDate->format('Y-m-d'),
                                'amount' => (float) $contract['monthly_rent'],
                                'status' => 'bekliyor',
                                'accrual_period' => $period,
                                'accrual_source' => 'otomatik',
                            ]);

                            $paymentId = (int) $db->lastInsertId();
                            app_insert_cari_movement($db, [
                                'cari_id' => (int) $contract['cari_id'],
                                'movement_type' => 'borc',
                                'source_module' => 'kira',
                                'source_table' => 'rental_payments',
                                'source_id' => $paymentId,
                                'amount' => (float) $contract['monthly_rent'],
                                'currency_code' => 'TRY',
                                'description' => (string) $contract['contract_no'] . ' ' . $period . ' kira tahakkuku',
                                'movement_date' => date('Y-m-d H:i:s'),
                                'created_by' => 1,
                            ]);

                            $createdCount++;
                            $generatedForContract++;
                        }
                    }

                    $periodCursor = $periodCursor->modify('first day of next month');
                }
            }

            $db->commit();
            rental_post_redirect('rental_accrual_' . $createdCount);
        }

        if ($action === 'create_periodic_rental_invoices') {
            $period = trim((string) ($_POST['invoice_period'] ?? date('Y-m')));
            $invoiceDate = trim((string) ($_POST['invoice_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $dueDate = trim((string) ($_POST['invoice_due_date'] ?? $invoiceDate)) ?: $invoiceDate;
            $vatRate = max(0, min(100, (float) ($_POST['vat_rate'] ?? 20)));
            $contractId = (int) ($_POST['contract_id'] ?? 0);

            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                throw new RuntimeException('Fatura donemi gecersiz.');
            }

            $params = ['period' => $period];
            $contractFilter = '';
            if ($contractId > 0) {
                $contractFilter = 'AND r.id = :contract_id';
                $params['contract_id'] = $contractId;
            }

            $invoiceGroups = app_fetch_all($db, '
                SELECT r.cari_id, c.company_name, c.full_name,
                       GROUP_CONCAT(p.id ORDER BY p.due_date ASC) AS payment_ids,
                       COUNT(*) AS payment_count,
                       SUM(p.amount) AS subtotal
                FROM rental_payments p
                INNER JOIN rental_contracts r ON r.id = p.contract_id
                INNER JOIN cari_cards c ON c.id = r.cari_id
                LEFT JOIN rental_payment_invoices link ON link.payment_id = p.id
                WHERE DATE_FORMAT(p.due_date, "%Y-%m") = :period
                  AND link.id IS NULL
                  ' . $contractFilter . '
                GROUP BY r.cari_id, c.company_name, c.full_name
                ORDER BY c.company_name ASC, c.full_name ASC
            ', $params);

            $createdCount = 0;
            $db->beginTransaction();

            foreach ($invoiceGroups as $group) {
                $paymentIds = array_values(array_filter(array_map('intval', explode(',', (string) $group['payment_ids']))));
                if ($paymentIds === []) {
                    continue;
                }

                $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
                $paymentRows = app_fetch_all($db, "
                    SELECT p.id, p.due_date, p.amount, p.accrual_period, r.contract_no, d.device_name, d.serial_no
                    FROM rental_payments p
                    INNER JOIN rental_contracts r ON r.id = p.contract_id
                    INNER JOIN rental_devices d ON d.id = r.device_id
                    WHERE p.id IN ({$placeholders})
                    ORDER BY p.due_date ASC, p.id ASC
                ", $paymentIds);

                if (!$paymentRows) {
                    continue;
                }

                $subtotal = 0.0;
                foreach ($paymentRows as $paymentRow) {
                    $subtotal += (float) $paymentRow['amount'];
                }
                $vatTotal = round($subtotal * $vatRate / 100, 2);
                $grandTotal = $subtotal + $vatTotal;
                $invoiceNo = app_document_series_number(
                    $db,
                    'docs.invoice_sales_series',
                    'docs.invoice_prefix',
                    'FAT',
                    'invoice_headers',
                    'invoice_date',
                    ['invoice_type = :series_invoice_type'],
                    ['series_invoice_type' => 'satis'],
                    new DateTimeImmutable($invoiceDate)
                );

                $stmt = $db->prepare('
                    INSERT INTO invoice_headers (
                        branch_id, cari_id, invoice_type, invoice_no, invoice_date, due_date, currency_code,
                        subtotal, vat_total, grand_total, edocument_type, edocument_status, notes
                    ) VALUES (
                        :branch_id, :cari_id, :invoice_type, :invoice_no, :invoice_date, :due_date, :currency_code,
                        :subtotal, :vat_total, :grand_total, :edocument_type, :edocument_status, :notes
                    )
                ');
                $stmt->execute([
                    'branch_id' => app_default_branch_id($db),
                    'cari_id' => (int) $group['cari_id'],
                    'invoice_type' => 'satis',
                    'invoice_no' => $invoiceNo,
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'currency_code' => 'TRY',
                    'subtotal' => $subtotal,
                    'vat_total' => $vatTotal,
                    'grand_total' => $grandTotal,
                    'edocument_type' => trim((string) ($_POST['edocument_type'] ?? '')) ?: null,
                    'edocument_status' => 'taslak',
                    'notes' => $period . ' donemsel kira faturasi. Cari borc kira tahakkukunda olusturuldu.',
                ]);

                $invoiceId = (int) $db->lastInsertId();
                $itemStmt = $db->prepare('
                    INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total)
                    VALUES (:invoice_id, NULL, :description, :quantity, :unit_price, :vat_rate, :line_total)
                ');
                foreach ($paymentRows as $paymentRow) {
                    $description = (string) $paymentRow['contract_no'] . ' / ' . (string) ($paymentRow['accrual_period'] ?: $period)
                        . ' kira bedeli / ' . (string) $paymentRow['device_name'] . ' / ' . (string) $paymentRow['serial_no'];
                    $itemStmt->execute([
                        'invoice_id' => $invoiceId,
                        'description' => $description,
                        'quantity' => 1,
                        'unit_price' => (float) $paymentRow['amount'],
                        'vat_rate' => $vatRate,
                        'line_total' => (float) $paymentRow['amount'],
                    ]);

                    $linkStmt = $db->prepare('
                        INSERT INTO rental_payment_invoices (payment_id, invoice_id)
                        VALUES (:payment_id, :invoice_id)
                    ');
                    $linkStmt->execute([
                        'payment_id' => (int) $paymentRow['id'],
                        'invoice_id' => $invoiceId,
                    ]);
                }

                app_audit_log('kira', 'create_periodic_invoice', 'invoice_headers', $invoiceId, $invoiceNo . ' donemsel kira faturasi olusturuldu.');
                $createdCount++;
            }

            $db->commit();
            rental_post_redirect('periodic_invoice_' . $createdCount);
        }

        if ($action === 'create_rental_protocol') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);
            $protocolNo = trim((string) ($_POST['protocol_no'] ?? ''));
            $protocolDate = trim((string) ($_POST['protocol_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $subject = trim((string) ($_POST['subject'] ?? ''));

            if ($contractId <= 0 || $protocolNo === '' || $subject === '') {
                throw new RuntimeException('Sozlesme, protokol no ve konu zorunludur.');
            }

            $contractRows = app_fetch_all($db, '
                SELECT r.id, r.contract_no, r.device_id
                FROM rental_contracts r
                WHERE r.id = :id
                LIMIT 1
            ', ['id' => $contractId]);

            if (!$contractRows) {
                throw new RuntimeException('Ek protokol icin sozlesme bulunamadi.');
            }

            $stmt = $db->prepare('
                INSERT INTO rental_contract_protocols (
                    contract_id, protocol_no, protocol_date, effective_date, subject, amount_effect, status, notes, created_by
                ) VALUES (
                    :contract_id, :protocol_no, :protocol_date, :effective_date, :subject, :amount_effect, :status, :notes, :created_by
                )
            ');
            $stmt->execute([
                'contract_id' => $contractId,
                'protocol_no' => $protocolNo,
                'protocol_date' => $protocolDate,
                'effective_date' => trim((string) ($_POST['effective_date'] ?? '')) ?: null,
                'subject' => $subject,
                'amount_effect' => (float) ($_POST['amount_effect'] ?? 0),
                'status' => trim((string) ($_POST['status'] ?? 'aktif')) ?: 'aktif',
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => 1,
            ]);

            $stmt = $db->prepare('
                INSERT INTO rental_service_logs (device_id, event_type, event_date, description)
                VALUES (:device_id, :event_type, :event_date, :description)
            ');
            $stmt->execute([
                'device_id' => (int) $contractRows[0]['device_id'],
                'event_type' => 'bakim',
                'event_date' => date('Y-m-d H:i:s'),
                'description' => (string) $contractRows[0]['contract_no'] . ' icin ' . $protocolNo . ' ek protokolu kaydedildi: ' . $subject,
            ]);

            rental_post_redirect('rental_protocol');
        }

        if ($action === 'queue_rental_end_alerts') {
            $alertDays = max(0, min(365, (int) ($_POST['alert_days'] ?? 30)));
            $plannedAt = trim((string) ($_POST['planned_at'] ?? date('Y-m-d\TH:i'))) ?: date('Y-m-d\TH:i');
            $plannedAtSql = str_replace('T', ' ', $plannedAt) . (strlen($plannedAt) === 16 ? ':00' : '');
            $sendEmail = isset($_POST['send_email']);
            $sendSms = isset($_POST['send_sms']);

            if (!$sendEmail && !$sendSms) {
                throw new RuntimeException('En az bir bildirim kanali secilmelidir.');
            }

            $rows = app_fetch_all($db, '
                SELECT r.id, r.contract_no, r.end_date, r.monthly_rent,
                       c.company_name, c.full_name, c.email, c.phone,
                       d.device_name, d.serial_no,
                       DATEDIFF(r.end_date, CURDATE()) AS days_left
                FROM rental_contracts r
                INNER JOIN cari_cards c ON c.id = r.cari_id
                INNER JOIN rental_devices d ON d.id = r.device_id
                WHERE r.status = \'aktif\'
                  AND r.end_date IS NOT NULL
                  AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL :alert_days DAY)
                ORDER BY r.end_date ASC
            ', ['alert_days' => $alertDays]);

            $emailSubjectTemplate = app_setting($db, 'notifications.rental_end_email_subject', 'Kira Sozlesmesi Bitis Uyarisi / {{contract_no}}');
            $emailBodyTemplate = app_setting($db, 'notifications.rental_end_email_body', "Merhaba,\n\n{{contract_no}} numarali kira sozlesmesi {{end_date}} tarihinde sona erecek.\nKalan gun: {{days_left}}\nCihaz: {{device_name}} / {{serial_no}}\n\nGalancy Bildirim Merkezi");
            $smsBodyTemplate = app_setting($db, 'notifications.rental_end_sms_body', '{{contract_no}} kira sozlesmesi {{end_date}} tarihinde sona erecek. Kalan gun: {{days_left}}.');
            $queuedCount = 0;
            $todayKey = date('Ymd');

            foreach ($rows as $row) {
                $vars = [
                    'contract_no' => (string) $row['contract_no'],
                    'company_name' => rental_cari_label($row),
                    'end_date' => (string) $row['end_date'],
                    'days_left' => (string) (int) $row['days_left'],
                    'device_name' => (string) $row['device_name'],
                    'serial_no' => (string) $row['serial_no'],
                    'monthly_rent' => number_format((float) $row['monthly_rent'], 2, ',', '.'),
                ];

                if ($sendEmail && trim((string) $row['email']) !== '') {
                    if (app_queue_notification($db, [
                        'module_name' => 'kira',
                        'notification_type' => 'rental_contract_end',
                        'source_table' => 'rental_contracts',
                        'source_id' => (int) $row['id'],
                        'channel' => 'email',
                        'recipient_name' => $vars['company_name'],
                        'recipient_contact' => trim((string) $row['email']),
                        'subject_line' => app_notification_render_template($emailSubjectTemplate, $vars),
                        'message_body' => app_notification_render_template($emailBodyTemplate, $vars),
                        'planned_at' => $plannedAtSql,
                        'unique_key' => 'rental-end-email-' . (int) $row['id'] . '-' . $todayKey,
                        'provider_name' => app_setting($db, 'notifications.email_mode', 'mock'),
                    ])) {
                        $queuedCount++;
                    }
                }

                if ($sendSms && trim((string) $row['phone']) !== '') {
                    if (app_queue_notification($db, [
                        'module_name' => 'kira',
                        'notification_type' => 'rental_contract_end',
                        'source_table' => 'rental_contracts',
                        'source_id' => (int) $row['id'],
                        'channel' => 'sms',
                        'recipient_name' => $vars['company_name'],
                        'recipient_contact' => trim((string) $row['phone']),
                        'subject_line' => null,
                        'message_body' => app_notification_render_template($smsBodyTemplate, $vars),
                        'planned_at' => $plannedAtSql,
                        'unique_key' => 'rental-end-sms-' . (int) $row['id'] . '-' . $todayKey,
                        'provider_name' => app_setting($db, 'notifications.sms_mode', 'mock'),
                    ])) {
                        $queuedCount++;
                    }
                }
            }

            app_audit_log('kira', 'queue_contract_end_alerts', 'notification_queue', null, 'Kira bitis uyarisi kuyruğa eklendi: ' . $queuedCount);
            rental_post_redirect('rental_end_alert_' . $queuedCount);
        }

        if ($action === 'create_return_checklist') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);
            $returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $deviceCondition = trim((string) ($_POST['device_condition'] ?? 'iyi')) ?: 'iyi';
            $nextDeviceStatus = trim((string) ($_POST['next_device_status'] ?? 'aktif')) ?: 'aktif';
            $allowedConditions = ['iyi', 'bakim_gerekli', 'hasarli', 'eksik'];
            $allowedStatuses = ['aktif', 'pasif', 'bakimda', 'kirada'];

            if ($contractId <= 0 || !in_array($deviceCondition, $allowedConditions, true) || !in_array($nextDeviceStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Geri alim kontrol bilgileri gecersiz.');
            }

            $contractRows = app_fetch_all($db, '
                SELECT r.id, r.contract_no, r.device_id, d.device_name, d.serial_no
                FROM rental_contracts r
                INNER JOIN rental_devices d ON d.id = r.device_id
                WHERE r.id = :id
                LIMIT 1
            ', ['id' => $contractId]);

            if (!$contractRows) {
                throw new RuntimeException('Geri alinacak kira sozlesmesi bulunamadi.');
            }

            $contract = $contractRows[0];
            $closeContract = isset($_POST['close_contract']) ? 1 : 0;
            $stmt = $db->prepare('
                INSERT INTO rental_return_checklists (
                    contract_id, device_id, return_date, device_condition,
                    accessories_ok, power_adapter_ok, documents_ok, photos_ok, cleaning_ok,
                    damage_note, missing_note, deposit_deduction, received_by, next_device_status, close_contract, created_by
                ) VALUES (
                    :contract_id, :device_id, :return_date, :device_condition,
                    :accessories_ok, :power_adapter_ok, :documents_ok, :photos_ok, :cleaning_ok,
                    :damage_note, :missing_note, :deposit_deduction, :received_by, :next_device_status, :close_contract, :created_by
                )
            ');
            $stmt->execute([
                'contract_id' => $contractId,
                'device_id' => (int) $contract['device_id'],
                'return_date' => $returnDate,
                'device_condition' => $deviceCondition,
                'accessories_ok' => isset($_POST['accessories_ok']) ? 1 : 0,
                'power_adapter_ok' => isset($_POST['power_adapter_ok']) ? 1 : 0,
                'documents_ok' => isset($_POST['documents_ok']) ? 1 : 0,
                'photos_ok' => isset($_POST['photos_ok']) ? 1 : 0,
                'cleaning_ok' => isset($_POST['cleaning_ok']) ? 1 : 0,
                'damage_note' => trim((string) ($_POST['damage_note'] ?? '')) ?: null,
                'missing_note' => trim((string) ($_POST['missing_note'] ?? '')) ?: null,
                'deposit_deduction' => (float) ($_POST['deposit_deduction'] ?? 0),
                'received_by' => trim((string) ($_POST['received_by'] ?? '')) ?: null,
                'next_device_status' => $nextDeviceStatus,
                'close_contract' => $closeContract,
                'created_by' => 1,
            ]);

            $stmt = $db->prepare('UPDATE rental_devices SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => $nextDeviceStatus,
                'id' => (int) $contract['device_id'],
            ]);

            if ($closeContract === 1) {
                $stmt = $db->prepare('UPDATE rental_contracts SET status = :status WHERE id = :id');
                $stmt->execute([
                    'status' => 'tamamlandi',
                    'id' => $contractId,
                ]);
            }

            $stmt = $db->prepare('
                INSERT INTO rental_service_logs (device_id, event_type, event_date, description)
                VALUES (:device_id, :event_type, :event_date, :description)
            ');
            $stmt->execute([
                'device_id' => (int) $contract['device_id'],
                'event_type' => 'iade',
                'event_date' => date('Y-m-d H:i:s'),
                'description' => (string) $contract['contract_no'] . ' geri alim kontrol listesi kaydedildi. Durum: ' . $deviceCondition,
            ]);

            rental_post_redirect('return_checklist');
        }

        if ($action === 'bulk_update_rental_contracts') {
            $contractIds = rental_selected_ids('contract_ids');
            $status = trim((string) ($_POST['bulk_contract_status'] ?? ''));
            $billingDay = (int) ($_POST['bulk_billing_day'] ?? 0) ?: null;

            if ($contractIds === [] || $status === '') {
                throw new RuntimeException('Sozlesme secimi veya durum gecersiz.');
            }
            foreach ($contractIds as $contractId) {
                app_assert_branch_access($db, 'rental_contracts', $contractId);
            }

            $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
            $params = array_merge([$status, $billingDay], $contractIds);
            $stmt = $db->prepare("UPDATE rental_contracts SET status = ?, billing_day = COALESCE(?, billing_day) WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            rental_post_redirect('contract_bulk_updated');
        }

        if ($action === 'update_rental_payment_status') {
            $paymentId = (int) ($_POST['payment_id'] ?? 0);

            if ($paymentId <= 0) {
                throw new RuntimeException('Gecerli bir odeme plani secilmedi.');
            }

            $paymentRows = app_fetch_all($db, '
                SELECT p.id, p.contract_id, p.status AS current_status, p.amount, r.contract_no, r.cari_id
                FROM rental_payments p
                INNER JOIN rental_contracts r ON r.id = p.contract_id
                WHERE p.id = :id
                LIMIT 1
            ', ['id' => $paymentId]);

            if (!$paymentRows) {
                throw new RuntimeException('Odeme plani bulunamadi.');
            }

            $paymentRow = $paymentRows[0];
            $status = trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor';

            if ($paymentRow['current_status'] === 'odendi' && $status !== 'odendi') {
                throw new RuntimeException('Muhasebelesmis tahsilat geri alinamaz. Yeni duzeltme fisi kullanin.');
            }

            $channel = trim((string) ($_POST['channel'] ?? 'kasa')) ?: 'kasa';
            $cashboxId = (int) ($_POST['cashbox_id'] ?? 0);
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? '')) ?: ($paymentRow['contract_no'] . ' kira tahsilati');

            if ($paymentRow['current_status'] !== 'odendi' && $status === 'odendi') {
                if ($channel === 'kasa' && $cashboxId <= 0) {
                    throw new RuntimeException('Tahsilatta kasa secimi zorunludur.');
                }

                if ($channel === 'banka' && $bankAccountId <= 0) {
                    throw new RuntimeException('Tahsilatta banka hesabi secimi zorunludur.');
                }
            }

            $paidAt = $status === 'odendi' ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare('UPDATE rental_payments SET status = :status, paid_at = :paid_at WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'paid_at' => $paidAt,
                'id' => $paymentId,
            ]);

            if ($paymentRow['current_status'] !== 'odendi' && $status === 'odendi') {
                if ($channel === 'kasa') {
                    $stmt = $db->prepare('
                        INSERT INTO finance_cash_movements (cashbox_id, cari_id, movement_type, amount, description, movement_date)
                        VALUES (:cashbox_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                    ');
                    $stmt->execute([
                        'cashbox_id' => $cashboxId,
                        'cari_id' => (int) $paymentRow['cari_id'],
                        'movement_type' => 'giris',
                        'amount' => (float) $paymentRow['amount'],
                        'description' => $description,
                        'movement_date' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $stmt = $db->prepare('
                        INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
                        VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                    ');
                    $stmt->execute([
                        'bank_account_id' => $bankAccountId,
                        'cari_id' => (int) $paymentRow['cari_id'],
                        'movement_type' => 'giris',
                        'amount' => (float) $paymentRow['amount'],
                        'description' => $description,
                        'movement_date' => date('Y-m-d H:i:s'),
                    ]);
                }

                app_insert_cari_movement($db, [
                    'cari_id' => (int) $paymentRow['cari_id'],
                    'movement_type' => 'alacak',
                    'source_module' => 'kira',
                    'source_table' => 'rental_payments',
                    'source_id' => $paymentId,
                    'amount' => (float) $paymentRow['amount'],
                    'currency_code' => 'TRY',
                    'description' => $description,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'created_by' => 1,
                ]);
            }

            rental_post_redirect('payment_updated');
        }

        if ($action === 'delete_rental_category') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($categoryId <= 0) {
                throw new RuntimeException('Gecerli bir kategori secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM rental_device_categories WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);

            rental_post_redirect('delete_category');
        }

        if ($action === 'delete_rental_device') {
            $deviceId = (int) ($_POST['device_id'] ?? 0);

            if ($deviceId <= 0) {
                throw new RuntimeException('Gecerli bir cihaz secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM rental_devices WHERE id = :id');
            $stmt->execute(['id' => $deviceId]);

            rental_post_redirect('delete_device');
        }

        if ($action === 'delete_rental_contract') {
            $contractId = (int) ($_POST['contract_id'] ?? 0);

            if ($contractId <= 0) {
                throw new RuntimeException('Gecerli bir sozlesme secilmedi.');
            }
            app_assert_branch_access($db, 'rental_contracts', $contractId);

            $stmt = $db->prepare('DELETE FROM rental_contracts WHERE id = :id');
            $stmt->execute(['id' => $contractId]);

            rental_post_redirect('delete_contract');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $feedback = 'error:Kira islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = rental_build_filters();
[$rentalCariScopeWhere, $rentalCariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$rentalCashboxScopeWhere, $rentalCashboxScopeParams] = app_branch_scope_filter($db, null);
[$rentalBankScopeWhere, $rentalBankScopeParams] = app_branch_scope_filter($db, null);
[$contractScopeWhere, $contractScopeParams] = app_branch_scope_filter($db, null, 'r');

$cariCards = app_fetch_all($db, 'SELECT c.id, c.company_name, c.full_name FROM cari_cards c ' . ($rentalCariScopeWhere !== '' ? 'WHERE ' . $rentalCariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 100', $rentalCariScopeParams);
$products = app_fetch_all($db, 'SELECT id, sku, name FROM stock_products ORDER BY id DESC LIMIT 100');
$cashboxes = app_fetch_all($db, 'SELECT id, name FROM finance_cashboxes ' . ($rentalCashboxScopeWhere !== '' ? 'WHERE ' . $rentalCashboxScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $rentalCashboxScopeParams);
$bankAccounts = app_fetch_all($db, 'SELECT id, bank_name, account_name FROM finance_bank_accounts ' . ($rentalBankScopeWhere !== '' ? 'WHERE ' . $rentalBankScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $rentalBankScopeParams);
$rentalCategories = app_fetch_all($db, 'SELECT id, name FROM rental_device_categories ORDER BY id DESC LIMIT 100');
$deviceWhere = [];
$deviceParams = [];

if ($filters['search'] !== '') {
    $deviceWhere[] = '(d.device_name LIKE :device_search OR d.serial_no LIKE :device_search OR c.name LIKE :device_search OR p.name LIKE :device_search)';
    $deviceParams['device_search'] = '%' . $filters['search'] . '%';
}

if ($filters['device_status'] !== '') {
    $deviceWhere[] = 'd.status = :device_status';
    $deviceParams['device_status'] = $filters['device_status'];
}

$deviceWhereSql = $deviceWhere ? 'WHERE ' . implode(' AND ', $deviceWhere) : '';

$devices = app_fetch_all($db, '
    SELECT d.id, d.device_name, d.serial_no, d.status, d.location_text, d.purchase_cost, c.name AS category_name, p.name AS product_name
    FROM rental_devices d
    LEFT JOIN rental_device_categories c ON c.id = d.category_id
    LEFT JOIN stock_products p ON p.id = d.product_id
    ' . $deviceWhereSql . '
    ORDER BY d.id DESC
    LIMIT 100
', $deviceParams);

$contractWhere = [];
$contractParams = [];

if ($filters['search'] !== '') {
    $contractWhere[] = '(r.contract_no LIKE :contract_search OR c.company_name LIKE :contract_search OR c.full_name LIKE :contract_search OR d.device_name LIKE :contract_search OR d.serial_no LIKE :contract_search)';
    $contractParams['contract_search'] = '%' . $filters['search'] . '%';
}

if ($filters['contract_status'] !== '') {
    $contractWhere[] = 'r.status = :contract_status';
    $contractParams['contract_status'] = $filters['contract_status'];
}
if ($contractScopeWhere !== '') {
    $contractWhere[] = $contractScopeWhere;
    $contractParams = array_merge($contractParams, $contractScopeParams);
}

$contractWhereSql = $contractWhere ? 'WHERE ' . implode(' AND ', $contractWhere) : '';

$contracts = app_fetch_all($db, '
    SELECT r.id, r.device_id, r.contract_no, r.start_date, r.end_date, r.monthly_rent, r.deposit_amount, r.status, r.billing_day,
           c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ' . $contractWhereSql . '
    ORDER BY r.id DESC
    LIMIT 50
', $contractParams);
$contractOptions = app_fetch_all($db, '
    SELECT r.id, r.contract_no, r.end_date, r.monthly_rent, c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ' . ($contractScopeWhere !== '' ? 'WHERE ' . $contractScopeWhere : '') . '
    ORDER BY r.id DESC
    LIMIT 100
', $contractScopeParams);
$renewals = app_fetch_all($db, '
    SELECT rn.id, rn.old_end_date, rn.new_end_date, rn.old_monthly_rent, rn.new_monthly_rent, rn.renewal_date, rn.notes,
           r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_contract_renewals rn
    INNER JOIN rental_contracts r ON r.id = rn.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ORDER BY rn.id DESC
    LIMIT 50
');
$accrualPreview = app_fetch_all($db, '
    SELECT r.id, r.contract_no, r.start_date, r.end_date, r.monthly_rent, r.billing_day,
           c.company_name, c.full_name, d.device_name, d.serial_no,
           MAX(p.due_date) AS last_due_date
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    LEFT JOIN rental_payments p ON p.contract_id = r.id
    WHERE r.status = \'aktif\'
    GROUP BY r.id, r.contract_no, r.start_date, r.end_date, r.monthly_rent, r.billing_day,
             c.company_name, c.full_name, d.device_name, d.serial_no
    ORDER BY COALESCE(MAX(p.due_date), r.start_date) ASC
    LIMIT 50
');
$periodicInvoicePreview = app_fetch_all($db, '
    SELECT DATE_FORMAT(p.due_date, "%Y-%m") AS invoice_period,
           r.cari_id, c.company_name, c.full_name,
           COUNT(*) AS payment_count,
           SUM(p.amount) AS subtotal,
           MIN(p.due_date) AS first_due_date,
           MAX(p.due_date) AS last_due_date
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    LEFT JOIN rental_payment_invoices link ON link.payment_id = p.id
    WHERE link.id IS NULL
    GROUP BY DATE_FORMAT(p.due_date, "%Y-%m"), r.cari_id, c.company_name, c.full_name
    ORDER BY invoice_period DESC, c.company_name ASC, c.full_name ASC
    LIMIT 50
');
$rentalInvoiceLinks = app_fetch_all($db, '
    SELECT link.created_at, h.id AS invoice_id, h.invoice_no, h.invoice_date, h.grand_total,
           p.accrual_period, r.contract_no, c.company_name, c.full_name
    FROM rental_payment_invoices link
    INNER JOIN rental_payments p ON p.id = link.payment_id
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN invoice_headers h ON h.id = link.invoice_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    ORDER BY link.id DESC
    LIMIT 50
');
$protocols = app_fetch_all($db, '
    SELECT pr.id, pr.protocol_no, pr.protocol_date, pr.effective_date, pr.subject, pr.amount_effect, pr.status, pr.notes,
           r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_contract_protocols pr
    INNER JOIN rental_contracts r ON r.id = pr.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ORDER BY pr.id DESC
    LIMIT 50
');
$rentalEndAlerts = app_fetch_all($db, '
    SELECT r.id, r.contract_no, r.end_date, r.monthly_rent,
           c.company_name, c.full_name, c.email, c.phone,
           d.device_name, d.serial_no,
           DATEDIFF(r.end_date, CURDATE()) AS days_left,
           COUNT(q.id) AS queued_count
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    LEFT JOIN notification_queue q ON q.source_table = \'rental_contracts\'
        AND q.source_id = r.id
        AND q.notification_type = \'rental_contract_end\'
        AND q.status IN (\'pending\',\'sent\')
    WHERE r.status = \'aktif\'
      AND r.end_date IS NOT NULL
      AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    GROUP BY r.id, r.contract_no, r.end_date, r.monthly_rent,
             c.company_name, c.full_name, c.email, c.phone,
             d.device_name, d.serial_no
    ORDER BY r.end_date ASC
    LIMIT 50
');
$returnChecklists = app_fetch_all($db, '
    SELECT rc.id, rc.return_date, rc.device_condition, rc.accessories_ok, rc.power_adapter_ok, rc.documents_ok,
           rc.photos_ok, rc.cleaning_ok, rc.damage_note, rc.missing_note, rc.deposit_deduction,
           rc.received_by, rc.next_device_status, rc.close_contract,
           r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_return_checklists rc
    INNER JOIN rental_contracts r ON r.id = rc.contract_id
    INNER JOIN rental_devices d ON d.id = rc.device_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    ORDER BY rc.id DESC
    LIMIT 50
');
$profitabilityRows = app_fetch_all($db, '
    SELECT r.id, r.contract_no, r.start_date, r.end_date, r.status, r.monthly_rent, r.deposit_amount,
           c.company_name, c.full_name,
           d.device_name, d.serial_no, d.purchase_cost,
           COALESCE(SUM(p.amount), 0) AS accrued_total,
           COALESCE(SUM(CASE WHEN p.status = \'odendi\' THEN p.amount ELSE 0 END), 0) AS paid_total,
           COALESCE(SUM(CASE WHEN p.status <> \'odendi\' THEN p.amount ELSE 0 END), 0) AS open_total,
           COALESCE(MAX(rc.deposit_deduction), 0) AS deposit_deduction,
           COUNT(DISTINCT p.id) AS payment_count,
           COUNT(DISTINCT CASE WHEN p.status = \'odendi\' THEN p.id END) AS paid_count
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    LEFT JOIN rental_payments p ON p.contract_id = r.id
    LEFT JOIN rental_return_checklists rc ON rc.contract_id = r.id
    GROUP BY r.id, r.contract_no, r.start_date, r.end_date, r.status, r.monthly_rent, r.deposit_amount,
             c.company_name, c.full_name, d.device_name, d.serial_no, d.purchase_cost
    ORDER BY (COALESCE(SUM(p.amount), 0) + COALESCE(MAX(rc.deposit_deduction), 0) - d.purchase_cost) DESC
    LIMIT 100
');
$profitabilityMonthlyRows = app_fetch_all($db, '
    SELECT DATE_FORMAT(p.due_date, "%Y-%m") AS report_period,
           COUNT(DISTINCT r.id) AS contract_count,
           COALESCE(SUM(p.amount), 0) AS accrued_total,
           COALESCE(SUM(CASE WHEN p.status = \'odendi\' THEN p.amount ELSE 0 END), 0) AS paid_total,
           COALESCE(SUM(CASE WHEN p.status <> \'odendi\' THEN p.amount ELSE 0 END), 0) AS open_total
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    GROUP BY DATE_FORMAT(p.due_date, "%Y-%m")
    ORDER BY report_period DESC
    LIMIT 12
');
$rentalCalendarRows = app_fetch_all($db, '
    SELECT r.id, r.contract_no, r.start_date, r.end_date, r.status, r.monthly_rent,
           c.company_name, c.full_name,
           d.device_name, d.serial_no, d.status AS device_status,
           GREATEST(0, DATEDIFF(COALESCE(r.end_date, CURDATE()), CURDATE())) AS days_left
    FROM rental_contracts r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    WHERE r.status = \'aktif\'
    ORDER BY COALESCE(r.end_date, DATE_ADD(CURDATE(), INTERVAL 999 DAY)) ASC, r.start_date ASC
    LIMIT 100
');
$rentalCalendarEvents = app_fetch_all($db, '
    SELECT event_date, event_type, contract_no, company_name, full_name, device_name, serial_no
    FROM (
        SELECT r.start_date AS event_date, \'baslangic\' AS event_type, r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
        FROM rental_contracts r
        INNER JOIN cari_cards c ON c.id = r.cari_id
        INNER JOIN rental_devices d ON d.id = r.device_id
        WHERE r.status = \'aktif\' AND r.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        UNION ALL
        SELECT r.end_date AS event_date, \'bitis\' AS event_type, r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
        FROM rental_contracts r
        INNER JOIN cari_cards c ON c.id = r.cari_id
        INNER JOIN rental_devices d ON d.id = r.device_id
        WHERE r.status = \'aktif\' AND r.end_date IS NOT NULL AND r.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ) x
    ORDER BY event_date ASC, event_type ASC
    LIMIT 80
');
$rentalDeviceCalendarSummary = app_fetch_all($db, '
    SELECT d.status, COUNT(*) AS device_count
    FROM rental_devices d
    GROUP BY d.status
    ORDER BY d.status ASC
');
$customerRentalHistory = app_fetch_all($db, '
    SELECT c.id AS cari_id, c.company_name, c.full_name, c.phone, c.email,
           COUNT(DISTINCT r.id) AS contract_count,
           COUNT(DISTINCT CASE WHEN r.status = \'aktif\' THEN r.id END) AS active_contract_count,
           COUNT(DISTINCT r.device_id) AS device_count,
           COALESCE(SUM(p.amount), 0) AS accrued_total,
           COALESCE(SUM(CASE WHEN p.status = \'odendi\' THEN p.amount ELSE 0 END), 0) AS paid_total,
           COALESCE(SUM(CASE WHEN p.status <> \'odendi\' THEN p.amount ELSE 0 END), 0) AS open_total,
           MAX(r.start_date) AS last_contract_date,
           GROUP_CONCAT(DISTINCT d.device_name ORDER BY d.device_name SEPARATOR ", ") AS device_names
    FROM cari_cards c
    INNER JOIN rental_contracts r ON r.cari_id = c.id
    INNER JOIN rental_devices d ON d.id = r.device_id
    LEFT JOIN rental_payments p ON p.contract_id = r.id
    GROUP BY c.id, c.company_name, c.full_name, c.phone, c.email
    ORDER BY MAX(r.start_date) DESC, accrued_total DESC
    LIMIT 100
');
$customerRentalMovements = app_fetch_all($db, '
    SELECT p.due_date, p.amount, p.status, p.accrual_period,
           r.contract_no, c.company_name, c.full_name, d.device_name, d.serial_no
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ORDER BY p.due_date DESC, p.id DESC
    LIMIT 80
');
$payments = app_fetch_all($db, '
    SELECT p.id, p.due_date, p.amount, p.status, p.paid_at, p.accrual_period, p.accrual_source, r.contract_no
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    INNER JOIN rental_devices d ON d.id = r.device_id
    ' . $contractWhereSql . '
    ORDER BY p.id DESC
    LIMIT 50
', $contractParams);
$logs = app_fetch_all($db, '
    SELECT l.event_type, l.event_date, l.description, d.device_name, d.serial_no
    FROM rental_service_logs l
    INNER JOIN rental_devices d ON d.id = l.device_id
    LEFT JOIN rental_device_categories c ON c.id = d.category_id
    LEFT JOIN stock_products p ON p.id = d.product_id
    ' . $deviceWhereSql . '
    ORDER BY l.id DESC
    LIMIT 50
', $deviceParams);
$contractDocCounts = app_related_doc_counts($db, 'kira', 'rental_contracts', array_column($contracts, 'id'));

$devices = app_sort_rows($devices, $filters['device_sort'], [
    'id_desc' => ['id', 'desc'],
    'name_asc' => ['device_name', 'asc'],
    'serial_asc' => ['serial_no', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$contracts = app_sort_rows($contracts, $filters['contract_sort'], [
    'id_desc' => ['id', 'desc'],
    'contract_asc' => ['contract_no', 'asc'],
    'rent_desc' => ['monthly_rent', 'desc'],
    'rent_asc' => ['monthly_rent', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$devicesPagination = app_paginate_rows($devices, $filters['device_page'], 10);
$contractsPagination = app_paginate_rows($contracts, $filters['contract_page'], 10);
$devices = $devicesPagination['items'];
$contracts = $contractsPagination['items'];

$summary = [
    'Kira Kategori' => app_table_count($db, 'rental_device_categories'),
    'Kiralik Cihaz' => app_table_count($db, 'rental_devices'),
    'Aktif Sozlesme' => app_metric($db, "SELECT COUNT(*) FROM rental_contracts WHERE status = 'aktif'"),
    'Yenilenen Sozlesme' => app_table_count($db, 'rental_contract_renewals'),
    'Ek Protokol' => app_table_count($db, 'rental_contract_protocols'),
    'Bitis Uyarisi' => app_metric($db, "SELECT COUNT(*) FROM rental_contracts WHERE status = 'aktif' AND end_date IS NOT NULL AND end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
    'Geri Alim' => app_table_count($db, 'rental_return_checklists'),
    'Kiradaki Cihaz' => app_metric($db, "SELECT COUNT(*) FROM rental_devices WHERE status = 'kirada'"),
    'Kira Musterisi' => app_metric($db, 'SELECT COUNT(DISTINCT cari_id) FROM rental_contracts'),
    'Tahmini Kira Kari' => number_format((float) app_metric($db, "
        SELECT COALESCE(SUM(x.accrued_total + x.deposit_deduction - x.purchase_cost),0)
        FROM (
            SELECT r.id, d.purchase_cost,
                   COALESCE(SUM(p.amount), 0) AS accrued_total,
                   COALESCE(MAX(rc.deposit_deduction), 0) AS deposit_deduction
            FROM rental_contracts r
            INNER JOIN rental_devices d ON d.id = r.device_id
            LEFT JOIN rental_payments p ON p.contract_id = r.id
            LEFT JOIN rental_return_checklists rc ON rc.contract_id = r.id
            GROUP BY r.id, d.purchase_cost
        ) x
    "), 2, ',', '.'),
    'Otomatik Tahakkuk' => app_metric($db, "SELECT COUNT(*) FROM rental_payments WHERE accrual_source = 'otomatik'"),
    'Kira Faturasi' => app_table_count($db, 'rental_payment_invoices'),
    'Bu Ay Tahakkuk' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM rental_payments WHERE DATE_FORMAT(due_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"), 2, ',', '.'),
    'Bekleyen Tahsilat' => app_metric($db, "SELECT COUNT(*) FROM rental_payments WHERE status = 'bekliyor'"),
    'Kira Toplami' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(monthly_rent),0) FROM rental_contracts WHERE status IN ('aktif','taslak')"), 2, ',', '.'),
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

<section class="card">
    <h3>Kira Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="kira">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Sozlesme, cihaz, seri no, cari">
        </div>
        <div>
            <label>Sozlesme Durumu</label>
            <select name="contract_status">
                <option value="">Tum durumlar</option>
                <option value="taslak" <?= $filters['contract_status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                <option value="aktif" <?= $filters['contract_status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="tamamlandi" <?= $filters['contract_status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                <option value="iptal" <?= $filters['contract_status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
            </select>
        </div>
        <div>
            <label>Cihaz Durumu</label>
            <select name="device_status">
                <option value="">Tum durumlar</option>
                <option value="aktif" <?= $filters['device_status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="pasif" <?= $filters['device_status'] === 'pasif' ? 'selected' : '' ?>>Pasif</option>
                <option value="bakimda" <?= $filters['device_status'] === 'bakimda' ? 'selected' : '' ?>>Bakimda</option>
                <option value="kirada" <?= $filters['device_status'] === 'kirada' ? 'selected' : '' ?>>Kirada</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=kira" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="kira">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="contract_status" value="<?= app_h($filters['contract_status']) ?>">
        <input type="hidden" name="device_status" value="<?= app_h($filters['device_status']) ?>">
        <div>
            <label>Cihaz Siralama</label>
            <select name="device_sort">
                <option value="id_desc" <?= $filters['device_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="name_asc" <?= $filters['device_sort'] === 'name_asc' ? 'selected' : '' ?>>Cihaz A-Z</option>
                <option value="serial_asc" <?= $filters['device_sort'] === 'serial_asc' ? 'selected' : '' ?>>Seri no A-Z</option>
                <option value="status_asc" <?= $filters['device_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>Sozlesme Siralama</label>
            <select name="contract_sort">
                <option value="id_desc" <?= $filters['contract_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="contract_asc" <?= $filters['contract_sort'] === 'contract_asc' ? 'selected' : '' ?>>Sozlesme no A-Z</option>
                <option value="rent_desc" <?= $filters['contract_sort'] === 'rent_desc' ? 'selected' : '' ?>>Aylik kira yuksek</option>
                <option value="rent_asc" <?= $filters['contract_sort'] === 'rent_asc' ? 'selected' : '' ?>>Aylik kira dusuk</option>
                <option value="status_asc" <?= $filters['contract_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
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
        <h3>Otomatik Kira Tahakkuku</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="generate_rental_accruals">
            <div>
                <label>Sozlesme</label>
                <select name="contract_id">
                    <option value="0">Tum aktif sozlesmeler</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <option value="<?= (int) $contract['id'] ?>"><?= app_h($contract['contract_no'] . ' / ' . ($contract['company_name'] ?: $contract['full_name'] ?: '-') . ' / ' . $contract['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tahakkuk Tarihine Kadar</label>
                <input type="date" name="accrual_until" value="<?= date('Y-m-t') ?>" required>
            </div>
            <div>
                <label>Sozlesme Basina En Fazla Ay</label>
                <input type="number" name="max_periods" min="1" max="36" value="12">
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit">Tahakkuklari Olustur</button>
            </div>
            <p class="full" style="margin:0;color:#64748b;">Ayni sozlesme icin ayni vade veya ayni donem daha once olusturulduysa tekrar kayit acilmaz.</p>
        </form>
    </div>

    <div class="card">
        <h3>Tahakkuk On Izleme</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Aylik</th><th>Son Vade</th><th>Siradaki Vade</th></tr></thead>
                <tbody>
                <?php foreach ($accrualPreview as $preview): ?>
                    <?php
                    $lastDue = !empty($preview['last_due_date']) ? new DateTimeImmutable((string) $preview['last_due_date']) : null;
                    if ($lastDue) {
                        $nextPeriod = $lastDue->modify('first day of next month');
                        $nextDue = rental_due_date_for_period((int) $nextPeriod->format('Y'), (int) $nextPeriod->format('m'), (int) ($preview['billing_day'] ?: 1));
                    } else {
                        $nextDue = new DateTimeImmutable((string) $preview['start_date']);
                    }
                    $endDate = !empty($preview['end_date']) ? new DateTimeImmutable((string) $preview['end_date']) : null;
                    $nextDueLabel = $endDate && $nextDue > $endDate ? 'Sozlesme bitmis' : $nextDue->format('Y-m-d');
                    ?>
                    <tr>
                        <td><?= app_h($preview['contract_no']) ?></td>
                        <td><?= app_h($preview['company_name'] ?: $preview['full_name'] ?: '-') ?></td>
                        <td><?= app_h($preview['device_name'] . ' / ' . $preview['serial_no']) ?></td>
                        <td><?= number_format((float) $preview['monthly_rent'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) ($preview['last_due_date'] ?: '-')) ?></td>
                        <td><?= app_h($nextDueLabel) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$accrualPreview): ?>
                    <tr><td colspan="6">Aktif kira sozlesmesi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Donemsel Fatura Olusturma</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_periodic_rental_invoices">
            <div>
                <label>Fatura Donemi</label>
                <input type="month" name="invoice_period" value="<?= date('Y-m') ?>" required>
            </div>
            <div>
                <label>Sozlesme</label>
                <select name="contract_id">
                    <option value="0">Tum sozlesmeler</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <option value="<?= (int) $contract['id'] ?>"><?= app_h($contract['contract_no'] . ' / ' . ($contract['company_name'] ?: $contract['full_name'] ?: '-') . ' / ' . $contract['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fatura Tarihi</label>
                <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Vade Tarihi</label>
                <input type="date" name="invoice_due_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>KDV Orani (%)</label>
                <input type="number" name="vat_rate" min="0" max="100" step="0.01" value="20">
            </div>
            <div>
                <label>e-Belge Tipi</label>
                <select name="edocument_type">
                    <option value="">Belirtilmedi</option>
                    <option value="e-fatura">e-Fatura</option>
                    <option value="e-arsiv">e-Arsiv</option>
                </select>
            </div>
            <div class="full">
                <button type="submit">Donemsel Faturalari Olustur</button>
            </div>
            <p class="full" style="margin:0;color:#64748b;">Faturasi olusturulmus kira tahakkuklari tekrar faturalanmaz. Cari borc hareketi tahakkukta olustugu icin burada ikinci borc kaydi acilmaz.</p>
        </form>
    </div>

    <div class="card">
        <h3>Faturalandirilacak Kira Donemleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Donem</th><th>Cari</th><th>Adet</th><th>Vade Araligi</th><th>Ara Toplam</th></tr></thead>
                <tbody>
                <?php foreach ($periodicInvoicePreview as $preview): ?>
                    <tr>
                        <td><?= app_h($preview['invoice_period']) ?></td>
                        <td><?= app_h($preview['company_name'] ?: $preview['full_name'] ?: '-') ?></td>
                        <td><?= (int) $preview['payment_count'] ?></td>
                        <td><?= app_h($preview['first_due_date'] . ' / ' . $preview['last_due_date']) ?></td>
                        <td><?= number_format((float) $preview['subtotal'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$periodicInvoicePreview): ?>
                    <tr><td colspan="5">Faturalandirilacak kira tahakkuku bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Kira Fatura Gecmisi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Fatura</th><th>Tarih</th><th>Cari</th><th>Sozlesme</th><th>Donem</th><th>Tutar</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($rentalInvoiceLinks as $link): ?>
                <tr>
                    <td><?= app_h($link['invoice_no']) ?></td>
                    <td><?= app_h($link['invoice_date']) ?></td>
                    <td><?= app_h($link['company_name'] ?: $link['full_name'] ?: '-') ?></td>
                    <td><?= app_h($link['contract_no']) ?></td>
                    <td><?= app_h((string) ($link['accrual_period'] ?: '-')) ?></td>
                    <td><?= number_format((float) $link['grand_total'], 2, ',', '.') ?></td>
                    <td><a href="invoice_detail.php?id=<?= (int) $link['invoice_id'] ?>">Faturaya Git</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rentalInvoiceLinks): ?>
                <tr><td colspan="7">Henuz kira faturasi olusturulmadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kira Karlilik Raporu</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Tahakkuk</th><th>Tahsil</th><th>Acik</th><th>Cihaz Maliyeti</th><th>Tahmini Kar</th></tr></thead>
                <tbody>
                <?php foreach ($profitabilityRows as $row): ?>
                    <?php
                    $totalRevenue = (float) $row['accrued_total'] + (float) $row['deposit_deduction'];
                    $estimatedProfit = $totalRevenue - (float) $row['purchase_cost'];
                    $collectionRate = (float) $row['accrued_total'] > 0 ? ((float) $row['paid_total'] / (float) $row['accrued_total']) * 100 : 0;
                    ?>
                    <tr>
                        <td><small><?= app_h($row['contract_no']) ?><br><?= app_h($row['status']) ?></small></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['device_name'] . ' / ' . $row['serial_no']) ?></td>
                        <td><?= number_format((float) $row['accrued_total'], 2, ',', '.') ?></td>
                        <td><small><?= number_format((float) $row['paid_total'], 2, ',', '.') ?><br>%<?= number_format($collectionRate, 1, ',', '.') ?></small></td>
                        <td><?= number_format((float) $row['open_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['purchase_cost'], 2, ',', '.') ?></td>
                        <td style="font-weight:700;color:<?= $estimatedProfit < 0 ? '#b91c1c' : '#166534' ?>;">
                            <?= number_format($estimatedProfit, 2, ',', '.') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$profitabilityRows): ?>
                    <tr><td colspan="8">Karlilik raporu icin kira sozlesmesi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p style="margin:12px 0 0;color:#64748b;">Tahmini kar = kira tahakkuklari + depozito kesintisi - cihaz alis maliyeti. Operasyonel servis giderleri ayrica girilirse sonraki adimlarda hesaba katilabilir.</p>
    </div>

    <div class="card">
        <h3>Aylik Kira Karlilik Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Donem</th><th>Sozlesme</th><th>Tahakkuk</th><th>Tahsil</th><th>Acik</th><th>Tahsil Orani</th></tr></thead>
                <tbody>
                <?php foreach ($profitabilityMonthlyRows as $row): ?>
                    <?php $monthlyRate = (float) $row['accrued_total'] > 0 ? ((float) $row['paid_total'] / (float) $row['accrued_total']) * 100 : 0; ?>
                    <tr>
                        <td><?= app_h($row['report_period']) ?></td>
                        <td><?= (int) $row['contract_count'] ?></td>
                        <td><?= number_format((float) $row['accrued_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['paid_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $row['open_total'], 2, ',', '.') ?></td>
                        <td>%<?= number_format($monthlyRate, 1, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$profitabilityMonthlyRows): ?>
                    <tr><td colspan="6">Aylik kira tahakkuku bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kiradaki Cihaz Takvimi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Baslangic</th><th>Bitis</th><th>Kalan</th><th>Aylik</th></tr></thead>
                <tbody>
                <?php foreach ($rentalCalendarRows as $row): ?>
                    <tr>
                        <td><?= app_h($row['contract_no']) ?></td>
                        <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                        <td><?= app_h($row['device_name'] . ' / ' . $row['serial_no']) ?></td>
                        <td><?= app_h($row['start_date']) ?></td>
                        <td><?= app_h((string) ($row['end_date'] ?: 'Suresiz')) ?></td>
                        <td><?= $row['end_date'] ? app_h((string) ((int) $row['days_left']) . ' gun') : 'Suresiz' ?></td>
                        <td><?= number_format((float) $row['monthly_rent'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rentalCalendarRows): ?>
                    <tr><td colspan="7">Aktif kiradaki cihaz bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>30 Gunluk Kira Ajandasi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Tip</th><th>Sozlesme</th><th>Cari</th><th>Cihaz</th></tr></thead>
                <tbody>
                <?php foreach ($rentalCalendarEvents as $event): ?>
                    <tr>
                        <td><?= app_h($event['event_date']) ?></td>
                        <td><?= app_h($event['event_type'] === 'baslangic' ? 'Baslangic' : 'Bitis') ?></td>
                        <td><?= app_h($event['contract_no']) ?></td>
                        <td><?= app_h($event['company_name'] ?: $event['full_name'] ?: '-') ?></td>
                        <td><?= app_h($event['device_name'] . ' / ' . $event['serial_no']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rentalCalendarEvents): ?>
                    <tr><td colspan="5">Onumuzdeki 30 gun icin kira baslangic/bitis hareketi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="stack" style="margin-top:12px;">
            <?php foreach ($rentalDeviceCalendarSummary as $statusRow): ?>
                <span style="display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:700;">
                    <?= app_h($statusRow['status']) ?>: <?= (int) $statusRow['device_count'] ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Musteri Bazli Kira Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Musteri</th><th>Sozlesme</th><th>Cihaz</th><th>Tahakkuk</th><th>Tahsil</th><th>Acik</th><th>Son Sozlesme</th></tr></thead>
                <tbody>
                <?php foreach ($customerRentalHistory as $history): ?>
                    <?php $historyRate = (float) $history['accrued_total'] > 0 ? ((float) $history['paid_total'] / (float) $history['accrued_total']) * 100 : 0; ?>
                    <tr>
                        <td>
                            <small>
                                <strong><?= app_h($history['company_name'] ?: $history['full_name'] ?: '-') ?></strong><br>
                                Tel: <?= app_h((string) ($history['phone'] ?: '-')) ?><br>
                                E: <?= app_h((string) ($history['email'] ?: '-')) ?>
                            </small>
                        </td>
                        <td><small>Toplam: <?= (int) $history['contract_count'] ?><br>Aktif: <?= (int) $history['active_contract_count'] ?></small></td>
                        <td><small><?= (int) $history['device_count'] ?> cihaz<br><?= app_h((string) ($history['device_names'] ?: '-')) ?></small></td>
                        <td><?= number_format((float) $history['accrued_total'], 2, ',', '.') ?></td>
                        <td><small><?= number_format((float) $history['paid_total'], 2, ',', '.') ?><br>%<?= number_format($historyRate, 1, ',', '.') ?></small></td>
                        <td><?= number_format((float) $history['open_total'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) ($history['last_contract_date'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customerRentalHistory): ?>
                    <tr><td colspan="7">Musteri bazli kira gecmisi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Son Musteri Kira Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Musteri</th><th>Sozlesme</th><th>Cihaz</th><th>Donem</th><th>Tutar</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($customerRentalMovements as $movement): ?>
                    <tr>
                        <td><?= app_h($movement['due_date']) ?></td>
                        <td><?= app_h($movement['company_name'] ?: $movement['full_name'] ?: '-') ?></td>
                        <td><?= app_h($movement['contract_no']) ?></td>
                        <td><?= app_h($movement['device_name'] . ' / ' . $movement['serial_no']) ?></td>
                        <td><?= app_h((string) ($movement['accrual_period'] ?: '-')) ?></td>
                        <td><?= number_format((float) $movement['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($movement['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$customerRentalMovements): ?>
                    <tr><td colspan="7">Kira hareketi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kira Bitis Uyarilari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="queue_rental_end_alerts">
            <div>
                <label>Uyari Araligi (gun)</label>
                <input type="number" name="alert_days" min="0" max="365" value="30">
            </div>
            <div>
                <label>Planlanan Gonderim</label>
                <input type="datetime-local" name="planned_at" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div>
                <label>Kanallar</label>
                <label style="display:block;"><input type="checkbox" name="send_email" checked> E-posta</label>
                <label style="display:block;"><input type="checkbox" name="send_sms"> SMS</label>
            </div>
            <div>
                <label>&nbsp;</label>
                <button type="submit">Bitis Uyarilarini Kuyruga Al</button>
            </div>
            <p class="full" style="margin:0;color:#64748b;">Aktif ve bitis tarihi belirli sozlesmeler taranir. Ayni sozlesme icin ayni gun ayni kanal tekrar kuyruğa eklenmez.</p>
        </form>
    </div>

    <div class="card">
        <h3>Yaklasan Kira Bitisleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Bitis</th><th>Kalan</th><th>Iletisim</th><th>Kuyruk</th></tr></thead>
                <tbody>
                <?php foreach ($rentalEndAlerts as $alert): ?>
                    <tr>
                        <td><?= app_h($alert['contract_no']) ?></td>
                        <td><?= app_h($alert['company_name'] ?: $alert['full_name'] ?: '-') ?></td>
                        <td><?= app_h($alert['device_name'] . ' / ' . $alert['serial_no']) ?></td>
                        <td><?= app_h($alert['end_date']) ?></td>
                        <td><?= (int) $alert['days_left'] < 0 ? app_h(abs((int) $alert['days_left']) . ' gun gecmis') : app_h((string) ((int) $alert['days_left']) . ' gun') ?></td>
                        <td><small>E: <?= app_h((string) ($alert['email'] ?: '-')) ?><br>SMS: <?= app_h((string) ($alert['phone'] ?: '-')) ?></small></td>
                        <td><?= (int) $alert['queued_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rentalEndAlerts): ?>
                    <tr><td colspan="7">Yaklasan kira bitisi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Cihaz Geri Alim Kontrol Listesi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_return_checklist">
            <div>
                <label>Sozlesme</label>
                <select name="contract_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <option value="<?= (int) $contract['id'] ?>"><?= app_h($contract['contract_no'] . ' / ' . ($contract['company_name'] ?: $contract['full_name'] ?: '-') . ' / ' . $contract['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Iade Tarihi</label>
                <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Cihaz Durumu</label>
                <select name="device_condition">
                    <option value="iyi">Iyi</option>
                    <option value="bakim_gerekli">Bakim gerekli</option>
                    <option value="hasarli">Hasarli</option>
                    <option value="eksik">Eksik parca</option>
                </select>
            </div>
            <div>
                <label>Iade Sonrasi Cihaz Durumu</label>
                <select name="next_device_status">
                    <option value="aktif">Aktif</option>
                    <option value="bakimda">Bakimda</option>
                    <option value="pasif">Pasif</option>
                    <option value="kirada">Kirada</option>
                </select>
            </div>
            <div>
                <label>Kontrol Maddeleri</label>
                <label style="display:block;"><input type="checkbox" name="accessories_ok"> Aksesuarlar tam</label>
                <label style="display:block;"><input type="checkbox" name="power_adapter_ok"> Adaptor/guc kablosu tam</label>
                <label style="display:block;"><input type="checkbox" name="documents_ok"> Evraklar alindi</label>
                <label style="display:block;"><input type="checkbox" name="photos_ok"> Fotograflar cekildi</label>
                <label style="display:block;"><input type="checkbox" name="cleaning_ok"> Temizlik/kontrol tamam</label>
            </div>
            <div>
                <label>Depozito Kesintisi</label>
                <input type="number" step="0.01" name="deposit_deduction" value="0">
            </div>
            <div>
                <label>Teslim Alan</label>
                <input name="received_by" placeholder="Personel adi">
            </div>
            <div>
                <label>Sozlesme Durumu</label>
                <label style="display:block;"><input type="checkbox" name="close_contract" checked> Sozlesmeyi tamamlandi yap</label>
            </div>
            <div class="full">
                <label>Hasar Notu</label>
                <textarea name="damage_note" rows="2" placeholder="Hasar, cizik, ariza veya bakim ihtiyaci"></textarea>
            </div>
            <div class="full">
                <label>Eksik Notu</label>
                <textarea name="missing_note" rows="2" placeholder="Eksik aksesuar, belge veya parça"></textarea>
            </div>
            <div class="full">
                <button type="submit">Geri Alim Kontrolunu Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Geri Alim Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Iade</th><th>Kontrol</th><th>Kesinti</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($returnChecklists as $checklist): ?>
                    <?php
                    $okCount = (int) $checklist['accessories_ok'] + (int) $checklist['power_adapter_ok'] + (int) $checklist['documents_ok'] + (int) $checklist['photos_ok'] + (int) $checklist['cleaning_ok'];
                    ?>
                    <tr>
                        <td><?= app_h($checklist['contract_no']) ?></td>
                        <td><?= app_h($checklist['company_name'] ?: $checklist['full_name'] ?: '-') ?></td>
                        <td><?= app_h($checklist['device_name'] . ' / ' . $checklist['serial_no']) ?></td>
                        <td><small><?= app_h($checklist['return_date']) ?><br><?= app_h($checklist['device_condition']) ?> / <?= app_h($checklist['next_device_status']) ?></small></td>
                        <td><?= $okCount ?>/5</td>
                        <td><?= number_format((float) $checklist['deposit_deduction'], 2, ',', '.') ?></td>
                        <td><small>Alan: <?= app_h((string) ($checklist['received_by'] ?: '-')) ?><br>Hasar: <?= app_h((string) ($checklist['damage_note'] ?: '-')) ?><br>Eksik: <?= app_h((string) ($checklist['missing_note'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$returnChecklists): ?>
                    <tr><td colspan="7">Henuz geri alim kontrol kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sozlesme Ek Protokol</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_rental_protocol">
            <div>
                <label>Sozlesme</label>
                <select name="contract_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <option value="<?= (int) $contract['id'] ?>"><?= app_h($contract['contract_no'] . ' / ' . ($contract['company_name'] ?: $contract['full_name'] ?: '-') . ' / ' . $contract['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Protokol No</label>
                <input name="protocol_no" placeholder="EKP-2026-001" required>
            </div>
            <div>
                <label>Protokol Tarihi</label>
                <input type="date" name="protocol_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Gecerlilik Tarihi</label>
                <input type="date" name="effective_date">
            </div>
            <div>
                <label>Konu</label>
                <input name="subject" placeholder="Sure uzatimi / fiyat farki / cihaz degisimi" required>
            </div>
            <div>
                <label>Tutar Etkisi</label>
                <input type="number" step="0.01" name="amount_effect" value="0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="aktif">Aktif</option>
                    <option value="taslak">Taslak</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div class="full">
                <label>Protokol Notu</label>
                <textarea name="notes" rows="3" placeholder="Ek sartlar, taraf mutabakati veya operasyon notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Ek Protokol Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Ek Protokol Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No</th><th>Sozlesme</th><th>Cari</th><th>Konu</th><th>Tarih</th><th>Tutar Etkisi</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($protocols as $protocol): ?>
                    <tr>
                        <td><?= app_h($protocol['protocol_no']) ?></td>
                        <td><?= app_h($protocol['contract_no'] . ' / ' . $protocol['device_name']) ?></td>
                        <td><?= app_h($protocol['company_name'] ?: $protocol['full_name'] ?: '-') ?></td>
                        <td><small><?= app_h($protocol['subject']) ?><br><?= app_h((string) ($protocol['notes'] ?: '-')) ?></small></td>
                        <td><small>Protokol: <?= app_h($protocol['protocol_date']) ?><br>Gecerli: <?= app_h((string) ($protocol['effective_date'] ?: '-')) ?></small></td>
                        <td><?= number_format((float) $protocol['amount_effect'], 2, ',', '.') ?></td>
                        <td><?= app_h($protocol['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$protocols): ?>
                    <tr><td colspan="7">Henuz ek protokol kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sozlesme Yenileme</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="renew_rental_contract">
            <div>
                <label>Sozlesme</label>
                <select name="contract_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <option value="<?= (int) $contract['id'] ?>"><?= app_h($contract['contract_no'] . ' / ' . ($contract['company_name'] ?: $contract['full_name'] ?: '-') . ' / ' . $contract['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Yenileme Tarihi</label>
                <input type="date" name="renewal_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>Yeni Bitis Tarihi</label>
                <input type="date" name="new_end_date" required>
            </div>
            <div>
                <label>Yeni Aylik Kira</label>
                <input type="number" step="0.01" name="new_monthly_rent" required>
            </div>
            <div class="full">
                <label>Yenileme Notu</label>
                <textarea name="notes" rows="3" placeholder="Yenileme sartlari, iskonto veya ek bilgi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Sozlesmeyi Yenile</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Yenileme Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Cihaz</th><th>Bitis</th><th>Aylik Kira</th><th>Tarih</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($renewals as $renewal): ?>
                    <tr>
                        <td><?= app_h($renewal['contract_no']) ?></td>
                        <td><?= app_h($renewal['company_name'] ?: $renewal['full_name'] ?: '-') ?></td>
                        <td><?= app_h($renewal['device_name'] . ' / ' . $renewal['serial_no']) ?></td>
                        <td><small>Eski: <?= app_h((string) ($renewal['old_end_date'] ?: '-')) ?><br>Yeni: <?= app_h((string) ($renewal['new_end_date'] ?: '-')) ?></small></td>
                        <td><small>Eski: <?= number_format((float) $renewal['old_monthly_rent'], 2, ',', '.') ?><br>Yeni: <?= number_format((float) $renewal['new_monthly_rent'], 2, ',', '.') ?></small></td>
                        <td><?= app_h((string) $renewal['renewal_date']) ?></td>
                        <td><?= app_h((string) ($renewal['notes'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$renewals): ?>
                    <tr><td colspan="7">Henuz sozlesme yenileme kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Cihaz Kategorisi ve Cihaz Karti</h3>
        <div class="stack">
            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_rental_category">
                <div>
                    <label>Kategori</label>
                    <input name="name" placeholder="Lazer / Cilt Bakim / Profesyonel Ekipman">
                </div>
                <div>
                    <button type="submit">Kategori Ekle</button>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kategori</th><th>Islem</th></tr></thead>
                    <tbody>
                    <?php foreach ($rentalCategories as $category): ?>
                        <tr>
                            <td><?= app_h($category['name']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu cihaz kategorisi silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_rental_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create_rental_device">
                <div>
                    <label>Kategori</label>
                    <select name="category_id">
                        <option value="">Seciniz</option>
                        <?php foreach ($rentalCategories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= app_h($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Bagli Urun</label>
                    <select name="product_id">
                        <option value="">Seciniz</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Cihaz Adi</label>
                    <input name="device_name" placeholder="Profesyonel Lazer Cihazi" required>
                </div>
                <div>
                    <label>Seri No</label>
                    <input name="serial_no" placeholder="KIRA-SN-001" required>
                </div>
                <div>
                    <label>Durum</label>
                    <select name="status">
                        <option value="aktif">Aktif</option>
                        <option value="pasif">Pasif</option>
                        <option value="bakimda">Bakimda</option>
                        <option value="kirada">Kirada</option>
                    </select>
                </div>
                <div>
                    <label>Lokasyon</label>
                    <input name="location_text" placeholder="Merkez / Sube / Depo">
                </div>
                <div>
                    <label>Alis Tarihi</label>
                    <input type="date" name="purchase_date">
                </div>
                <div>
                    <label>Alis Maliyeti</label>
                    <input type="number" step="0.01" name="purchase_cost" value="0">
                </div>
                <div class="full">
                    <label>Notlar</label>
                    <textarea name="notes" rows="3" placeholder="Cihaz ile ilgili notlar"></textarea>
                </div>
                <div class="full">
                    <button type="submit">Cihaz Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Kira Sozlesmesi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_rental_contract">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(rental_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Cihaz</label>
                <select name="device_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= (int) $device['id'] ?>"><?= app_h($device['device_name'] . ' / ' . $device['serial_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Baslangic</label>
                <input type="date" name="start_date" required>
            </div>
            <div>
                <label>Bitis</label>
                <input type="date" name="end_date">
            </div>
            <div>
                <label>Aylik Kira</label>
                <input type="number" step="0.01" name="monthly_rent" value="0">
            </div>
            <div>
                <label>Depozito</label>
                <input type="number" step="0.01" name="deposit_amount" value="0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="taslak">Taslak</option>
                    <option value="aktif">Aktif</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Faturalama Gunu</label>
                <input type="number" name="billing_day" min="1" max="31" value="1">
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Sozlesme notlari"></textarea>
            </div>
            <div class="full">
                <button type="submit">Sozlesme Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Cihaz Olay Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_rental_log">
            <div>
                <label>Cihaz</label>
                <select name="device_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= (int) $device['id'] ?>"><?= app_h($device['device_name'] . ' / ' . $device['serial_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Olay Tipi</label>
                <select name="event_type">
                    <option value="teslim">Teslim</option>
                    <option value="iade">Iade</option>
                    <option value="bakim">Bakim</option>
                    <option value="hasar">Hasar</option>
                </select>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <textarea name="description" rows="4" placeholder="Cihaz olay/aciklama bilgisi" required></textarea>
            </div>
            <div class="full">
                <button type="submit">Olay Ekle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktif Cihazlar</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_rental_devices">
            <select name="bulk_device_status">
                <option value="aktif">Aktif</option>
                <option value="pasif">Pasif</option>
                <option value="bakimda">Bakimda</option>
                <option value="kirada">Kirada</option>
            </select>
            <input name="bulk_location_text" placeholder="Lokasyon">
            <button type="submit">Secili Cihazlari Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.device-check').forEach((el)=>el.checked=this.checked)"></th><th>Cihaz</th><th>Seri</th><th>Kategori</th><th>Durum</th><th>Lokasyon</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr>
                        <td><input class="device-check" type="checkbox" name="device_ids[]" value="<?= (int) $device['id'] ?>"></td>
                        <td><?= app_h($device['device_name']) ?></td>
                        <td><?= app_h($device['serial_no']) ?></td>
                        <td><?= app_h($device['category_name'] ?: '-') ?></td>
                        <td><?= app_h($device['status']) ?></td>
                        <td><?= app_h($device['location_text'] ?: '-') ?></td>
                        <td>
                            <div class="stack">
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_rental_device_status">
                                    <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                    <select name="status">
                                        <option value="aktif" <?= $device['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                        <option value="pasif" <?= $device['status'] === 'pasif' ? 'selected' : '' ?>>Pasif</option>
                                        <option value="bakimda" <?= $device['status'] === 'bakimda' ? 'selected' : '' ?>>Bakimda</option>
                                        <option value="kirada" <?= $device['status'] === 'kirada' ? 'selected' : '' ?>>Kirada</option>
                                    </select>
                                    <input name="location_text" value="<?= app_h((string) ($device['location_text'] ?? '')) ?>" placeholder="Lokasyon">
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu cihaz silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_rental_device">
                                    <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($devicesPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $devicesPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'kira', 'search' => $filters['search'], 'contract_status' => $filters['contract_status'], 'device_status' => $filters['device_status'], 'device_sort' => $filters['device_sort'], 'contract_sort' => $filters['contract_sort'], 'device_page' => $page, 'contract_page' => $contractsPagination['page']])) ?>"><?= $page === $devicesPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kira Sozlesmeleri</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_rental_contracts">
            <select name="bulk_contract_status">
                <option value="taslak">Taslak</option>
                <option value="aktif">Aktif</option>
                <option value="tamamlandi">Tamamlandi</option>
                <option value="iptal">Iptal</option>
            </select>
            <input type="number" name="bulk_billing_day" min="1" max="31" placeholder="Faturalama">
            <button type="submit">Secili Sozlesmeleri Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.contract-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Cari</th><th>Cihaz</th><th>Durum</th><th>Aylik</th><th>Depozito</th><th>Evrak</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($contracts as $contract): ?>
                    <tr>
                        <td><input class="contract-check" type="checkbox" name="contract_ids[]" value="<?= (int) $contract['id'] ?>"></td>
                        <td><?= app_h($contract['contract_no']) ?></td>
                        <td><?= app_h($contract['company_name'] ?: $contract['full_name'] ?: '-') ?></td>
                        <td><?= app_h($contract['device_name'] . ' / ' . $contract['serial_no']) ?></td>
                        <td><?= app_h($contract['status']) ?></td>
                        <td><?= number_format((float) $contract['monthly_rent'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $contract['deposit_amount'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="index.php?module=evrak&filter_module=kira&filter_related_table=rental_contracts&filter_related_id=<?= (int) $contract['id'] ?>&prefill_module=kira&prefill_related_table=rental_contracts&prefill_related_id=<?= (int) $contract['id'] ?>">
                                    Evrak (<?= (int) ($contractDocCounts[(int) $contract['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('kira', 'rental_contracts', (int) $contract['id'], 'index.php?module=kira')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <div class="stack">
                                <a href="rental_detail.php?id=<?= (int) $contract['id'] ?>">Detay</a>
                                <a href="print.php?type=rental&id=<?= (int) $contract['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#fff1df;color:#7c2d12;font-weight:700;text-decoration:none;">PDF / Yazdir</a>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_rental_contract_status">
                                    <input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>">
                                    <input type="hidden" name="device_id" value="<?= (int) $contract['device_id'] ?>">
                                    <select name="status">
                                        <option value="taslak" <?= $contract['status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                                        <option value="aktif" <?= $contract['status'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                        <option value="tamamlandi" <?= $contract['status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                                        <option value="iptal" <?= $contract['status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
                                    </select>
                                    <input type="number" name="billing_day" min="1" max="31" value="<?= app_h((string) $contract['billing_day']) ?>">
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu kira sozlesmesi silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_rental_contract">
                                    <input type="hidden" name="contract_id" value="<?= (int) $contract['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($contractsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $contractsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'kira', 'search' => $filters['search'], 'contract_status' => $filters['contract_status'], 'device_status' => $filters['device_status'], 'device_sort' => $filters['device_sort'], 'contract_sort' => $filters['contract_sort'], 'device_page' => $devicesPagination['page'], 'contract_page' => $page])) ?>"><?= $page === $contractsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Odeme Plani</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Vade</th><th>Donem</th><th>Tutar</th><th>Durum</th><th>Odeme</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= app_h($payment['contract_no']) ?></td>
                        <td><?= app_h($payment['due_date']) ?></td>
                        <td><?= app_h(($payment['accrual_period'] ?: '-') . ($payment['accrual_source'] ? ' / ' . $payment['accrual_source'] : '')) ?></td>
                        <td><?= number_format((float) $payment['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($payment['status']) ?></td>
                        <td><?= app_h($payment['paid_at'] ?: '-') ?></td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="update_rental_payment_status">
                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                <select name="status">
                                    <option value="bekliyor" <?= $payment['status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                                    <option value="odendi" <?= $payment['status'] === 'odendi' ? 'selected' : '' ?>>Odendi</option>
                                    <option value="gecikmis" <?= $payment['status'] === 'gecikmis' ? 'selected' : '' ?>>Gecikmis</option>
                                </select>
                                <select name="channel">
                                    <option value="kasa">Kasa</option>
                                    <option value="banka">Banka</option>
                                </select>
                                <select name="cashbox_id">
                                    <option value="">Kasa Secin</option>
                                    <?php foreach ($cashboxes as $cashbox): ?>
                                        <option value="<?= (int) $cashbox['id'] ?>"><?= app_h($cashbox['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="bank_account_id">
                                    <option value="">Banka Secin</option>
                                    <?php foreach ($bankAccounts as $account): ?>
                                        <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="description" placeholder="Tahsilat aciklamasi">
                                <button type="submit">Guncelle</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Cihaz Olay Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cihaz</th><th>Olay</th><th>Tarih</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= app_h($log['device_name'] . ' / ' . $log['serial_no']) ?></td>
                        <td><?= app_h($log['event_type']) ?></td>
                        <td><?= app_h($log['event_date']) ?></td>
                        <td><?= app_h($log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
