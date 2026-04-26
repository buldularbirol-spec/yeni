<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Servis modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function service_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function service_next_number(PDO $db): string
{
    return app_document_series_number($db, 'docs.service_series', 'docs.service_prefix', 'SRV', 'service_records', 'opened_at');
}

function service_safe_upload_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?: 'servis-fotografi';
    $base = trim((string) $base, '-_') ?: 'servis-fotografi';

    return $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
}

function service_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function service_ensure_acceptance_schema(PDO $db): void
{
    $columns = [
        'acceptance_type' => "ALTER TABLE service_records ADD COLUMN acceptance_type VARCHAR(80) NULL AFTER warranty_status",
        'received_by' => "ALTER TABLE service_records ADD COLUMN received_by VARCHAR(160) NULL AFTER acceptance_type",
        'received_accessories' => "ALTER TABLE service_records ADD COLUMN received_accessories TEXT NULL AFTER received_by",
        'device_condition' => "ALTER TABLE service_records ADD COLUMN device_condition TEXT NULL AFTER received_accessories",
        'customer_approval_note' => "ALTER TABLE service_records ADD COLUMN customer_approval_note TEXT NULL AFTER device_condition",
        'estimated_delivery_date' => "ALTER TABLE service_records ADD COLUMN estimated_delivery_date DATE NULL AFTER customer_approval_note",
        'acceptance_signed_by' => "ALTER TABLE service_records ADD COLUMN acceptance_signed_by VARCHAR(160) NULL AFTER estimated_delivery_date",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_appointment_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS service_appointments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_record_id BIGINT NOT NULL,
            assigned_user_id INT NULL,
            appointment_at DATETIME NOT NULL,
            appointment_type VARCHAR(80) NULL,
            location_text VARCHAR(255) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'planlandi',
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service_appointments_record (service_record_id),
            INDEX idx_service_appointments_date (appointment_at)
        ) ENGINE=InnoDB
    ");
}

function service_ensure_step_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS service_steps (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_record_id BIGINT NOT NULL,
            assigned_user_id INT NULL,
            step_name VARCHAR(160) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'bekliyor',
            sort_order INT NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service_steps_record (service_record_id),
            INDEX idx_service_steps_status (status)
        ) ENGINE=InnoDB
    ");
}

function service_ensure_part_schema(PDO $db): void
{
    $columns = [
        'used_at' => "ALTER TABLE service_parts ADD COLUMN used_at DATETIME NULL AFTER unit_cost",
        'notes' => "ALTER TABLE service_parts ADD COLUMN notes TEXT NULL AFTER used_at",
        'created_by' => "ALTER TABLE service_parts ADD COLUMN created_by INT NULL AFTER notes",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_parts', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_cost_schema(PDO $db): void
{
    $columns = [
        'labor_cost' => "ALTER TABLE service_records ADD COLUMN labor_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER cost_total",
        'external_cost' => "ALTER TABLE service_records ADD COLUMN external_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER labor_cost",
        'service_revenue' => "ALTER TABLE service_records ADD COLUMN service_revenue DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER external_cost",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_delivery_schema(PDO $db): void
{
    $columns = [
        'delivery_date' => "ALTER TABLE service_records ADD COLUMN delivery_date DATETIME NULL AFTER service_revenue",
        'delivered_by' => "ALTER TABLE service_records ADD COLUMN delivered_by VARCHAR(160) NULL AFTER delivery_date",
        'delivered_to' => "ALTER TABLE service_records ADD COLUMN delivered_to VARCHAR(160) NULL AFTER delivered_by",
        'delivery_status' => "ALTER TABLE service_records ADD COLUMN delivery_status VARCHAR(40) NULL AFTER delivered_to",
        'delivery_notes' => "ALTER TABLE service_records ADD COLUMN delivery_notes TEXT NULL AFTER delivery_status",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_customer_approval_schema(PDO $db): void
{
    $columns = [
        'customer_approval_status' => "ALTER TABLE service_records ADD COLUMN customer_approval_status VARCHAR(40) NULL AFTER delivery_notes",
        'customer_approval_at' => "ALTER TABLE service_records ADD COLUMN customer_approval_at DATETIME NULL AFTER customer_approval_status",
        'customer_approved_by' => "ALTER TABLE service_records ADD COLUMN customer_approved_by VARCHAR(160) NULL AFTER customer_approval_at",
        'customer_approval_channel' => "ALTER TABLE service_records ADD COLUMN customer_approval_channel VARCHAR(80) NULL AFTER customer_approved_by",
        'customer_approval_description' => "ALTER TABLE service_records ADD COLUMN customer_approval_description TEXT NULL AFTER customer_approval_channel",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_photo_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS service_photos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_record_id BIGINT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            caption VARCHAR(255) NULL,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service_photos_record (service_record_id)
        ) ENGINE=InnoDB
    ");
}

function service_ensure_warranty_schema(PDO $db): void
{
    $columns = [
        'warranty_start_date' => "ALTER TABLE service_records ADD COLUMN warranty_start_date DATE NULL AFTER warranty_status",
        'warranty_end_date' => "ALTER TABLE service_records ADD COLUMN warranty_end_date DATE NULL AFTER warranty_start_date",
        'warranty_provider' => "ALTER TABLE service_records ADD COLUMN warranty_provider VARCHAR(160) NULL AFTER warranty_end_date",
        'warranty_document_no' => "ALTER TABLE service_records ADD COLUMN warranty_document_no VARCHAR(120) NULL AFTER warranty_provider",
        'warranty_result' => "ALTER TABLE service_records ADD COLUMN warranty_result VARCHAR(80) NULL AFTER warranty_document_no",
        'warranty_notes' => "ALTER TABLE service_records ADD COLUMN warranty_notes TEXT NULL AFTER warranty_result",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_ensure_sla_schema(PDO $db): void
{
    $columns = [
        'sla_priority' => "ALTER TABLE service_records ADD COLUMN sla_priority VARCHAR(40) NULL AFTER warranty_notes",
        'sla_response_due_at' => "ALTER TABLE service_records ADD COLUMN sla_response_due_at DATETIME NULL AFTER sla_priority",
        'sla_resolution_due_at' => "ALTER TABLE service_records ADD COLUMN sla_resolution_due_at DATETIME NULL AFTER sla_response_due_at",
        'sla_responded_at' => "ALTER TABLE service_records ADD COLUMN sla_responded_at DATETIME NULL AFTER sla_resolution_due_at",
        'sla_resolved_at' => "ALTER TABLE service_records ADD COLUMN sla_resolved_at DATETIME NULL AFTER sla_responded_at",
        'sla_status' => "ALTER TABLE service_records ADD COLUMN sla_status VARCHAR(40) NULL AFTER sla_resolved_at",
        'sla_notes' => "ALTER TABLE service_records ADD COLUMN sla_notes TEXT NULL AFTER sla_status",
    ];

    foreach ($columns as $column => $sql) {
        if (!service_column_exists($db, 'service_records', $column)) {
            $db->exec($sql);
        }
    }
}

function service_store_photo(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Servis fotografi yuklenemedi.');
    }

    $extension = app_validate_uploaded_file($file, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $targetDir = dirname(__DIR__) . '/uploads/service_photos';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Servis fotograf klasoru olusturulamadi.');
    }
    app_ensure_upload_protection($targetDir);

    $safeName = service_safe_upload_name((string) ($file['name'] ?? ('service.' . $extension)));
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Servis fotografi kaydedilemedi.');
    }

    return [
        'file_name' => $safeName,
        'file_path' => '/muhasebe1/uploads/service_photos/' . $safeName,
    ];
}

function service_post_redirect(string $result): void
{
    app_redirect('index.php?module=servis&ok=' . urlencode($result));
}

function service_selected_ids(): array
{
    $values = $_POST['service_record_ids'] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function service_datetime_value(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
}

function service_minutes_label(?int $minutes): string
{
    if ($minutes === null) {
        return '-';
    }

    $prefix = $minutes < 0 ? 'Gecikti: ' : '';
    $minutes = abs($minutes);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;

    if ($days > 0) {
        return $prefix . $days . ' gun ' . $hours . ' saat';
    }

    if ($hours > 0) {
        return $prefix . $hours . ' saat ' . $mins . ' dk';
    }

    return $prefix . $mins . ' dk';
}

function service_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'status_id' => trim((string) ($_GET['status_id'] ?? '')),
        'assigned_user_id' => trim((string) ($_GET['assigned_user_id'] ?? '')),
        'calendar_start' => trim((string) ($_GET['calendar_start'] ?? date('Y-m-d'))),
        'calendar_user_id' => trim((string) ($_GET['calendar_user_id'] ?? '')),
        'record_sort' => trim((string) ($_GET['record_sort'] ?? 'id_desc')),
        'note_sort' => trim((string) ($_GET['note_sort'] ?? 'date_desc')),
        'record_page' => max(1, (int) ($_GET['record_page'] ?? 1)),
        'note_page' => max(1, (int) ($_GET['note_page'] ?? 1)),
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

service_ensure_acceptance_schema($db);
service_ensure_appointment_schema($db);
service_ensure_step_schema($db);
service_ensure_part_schema($db);
service_ensure_cost_schema($db);
service_ensure_delivery_schema($db);
service_ensure_customer_approval_schema($db);
service_ensure_photo_schema($db);
service_ensure_warranty_schema($db);
service_ensure_sla_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_service_record') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $complaint = trim((string) ($_POST['complaint'] ?? ''));

            if ($cariId <= 0 || $complaint === '') {
                throw new RuntimeException('Cari ve sikayet/aciklama alani zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO service_records (
                    branch_id, cari_id, product_id, fault_type_id, status_id, assigned_user_id, service_no, serial_no, complaint, diagnosis, warranty_status,
                    acceptance_type, received_by, received_accessories, device_condition, customer_approval_note, estimated_delivery_date, acceptance_signed_by,
                    cost_total, opened_at
                ) VALUES (
                    :branch_id, :cari_id, :product_id, :fault_type_id, :status_id, :assigned_user_id, :service_no, :serial_no, :complaint, :diagnosis, :warranty_status,
                    :acceptance_type, :received_by, :received_accessories, :device_condition, :customer_approval_note, :estimated_delivery_date, :acceptance_signed_by,
                    :cost_total, :opened_at
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'cari_id' => $cariId,
                'product_id' => (int) ($_POST['product_id'] ?? 0) ?: null,
                'fault_type_id' => (int) ($_POST['fault_type_id'] ?? 0) ?: null,
                'status_id' => (int) ($_POST['status_id'] ?? 0) ?: null,
                'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0) ?: null,
                'service_no' => service_next_number($db),
                'serial_no' => trim((string) ($_POST['serial_no'] ?? '')) ?: null,
                'complaint' => $complaint,
                'diagnosis' => trim((string) ($_POST['diagnosis'] ?? '')) ?: null,
                'warranty_status' => trim((string) ($_POST['warranty_status'] ?? '')) ?: null,
                'acceptance_type' => trim((string) ($_POST['acceptance_type'] ?? 'servis_kabul')) ?: 'servis_kabul',
                'received_by' => trim((string) ($_POST['received_by'] ?? '')) ?: null,
                'received_accessories' => trim((string) ($_POST['received_accessories'] ?? '')) ?: null,
                'device_condition' => trim((string) ($_POST['device_condition'] ?? '')) ?: null,
                'customer_approval_note' => trim((string) ($_POST['customer_approval_note'] ?? '')) ?: null,
                'estimated_delivery_date' => trim((string) ($_POST['estimated_delivery_date'] ?? '')) ?: null,
                'acceptance_signed_by' => trim((string) ($_POST['acceptance_signed_by'] ?? '')) ?: null,
                'cost_total' => (float) ($_POST['cost_total'] ?? 0),
                'opened_at' => date('Y-m-d H:i:s'),
            ]);

            service_post_redirect('service');
        }

        if ($action === 'create_service_note') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);
            $noteText = trim((string) ($_POST['note_text'] ?? ''));

            if ($serviceRecordId <= 0 || $noteText === '') {
                throw new RuntimeException('Servis kaydi ve not alani zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO service_notes (service_record_id, note_text, is_customer_visible, created_by)
                VALUES (:service_record_id, :note_text, :is_customer_visible, :created_by)
            ');
            $stmt->execute([
                'service_record_id' => $serviceRecordId,
                'note_text' => $noteText,
                'is_customer_visible' => isset($_POST['is_customer_visible']) ? 1 : 0,
                'created_by' => 1,
            ]);

            service_post_redirect('service_note');
        }

        if ($action === 'create_service_appointment') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);
            $appointmentAt = trim((string) ($_POST['appointment_at'] ?? ''));

            if ($serviceRecordId <= 0 || $appointmentAt === '') {
                throw new RuntimeException('Servis kaydi ve randevu tarihi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO service_appointments (
                    service_record_id, assigned_user_id, appointment_at, appointment_type, location_text, status, notes, created_by
                ) VALUES (
                    :service_record_id, :assigned_user_id, :appointment_at, :appointment_type, :location_text, :status, :notes, :created_by
                )
            ');
            $stmt->execute([
                'service_record_id' => $serviceRecordId,
                'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0) ?: null,
                'appointment_at' => service_datetime_value($appointmentAt),
                'appointment_type' => trim((string) ($_POST['appointment_type'] ?? 'servis')) ?: 'servis',
                'location_text' => trim((string) ($_POST['location_text'] ?? '')) ?: null,
                'status' => trim((string) ($_POST['status'] ?? 'planlandi')) ?: 'planlandi',
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => 1,
            ]);

            service_post_redirect('service_appointment');
        }

        if ($action === 'create_service_step') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);
            $stepName = trim((string) ($_POST['step_name'] ?? ''));

            if ($serviceRecordId <= 0 || $stepName === '') {
                throw new RuntimeException('Servis kaydi ve adim adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO service_steps (
                    service_record_id, assigned_user_id, step_name, status, sort_order, started_at, completed_at, notes, created_by
                ) VALUES (
                    :service_record_id, :assigned_user_id, :step_name, :status, :sort_order, :started_at, :completed_at, :notes, :created_by
                )
            ');
            $stmt->execute([
                'service_record_id' => $serviceRecordId,
                'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0) ?: null,
                'step_name' => $stepName,
                'status' => trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor',
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'started_at' => service_datetime_value((string) ($_POST['started_at'] ?? '')),
                'completed_at' => service_datetime_value((string) ($_POST['completed_at'] ?? '')),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => 1,
            ]);

            service_post_redirect('service_step');
        }

        if ($action === 'create_service_part') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $unitCost = (float) ($_POST['unit_cost'] ?? 0);

            if ($serviceRecordId <= 0 || $productId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Servis kaydi, parca ve miktar zorunludur.');
            }

            if ($unitCost <= 0) {
                $stmt = $db->prepare('SELECT purchase_price FROM stock_products WHERE id = :id');
                $stmt->execute(['id' => $productId]);
                $unitCost = (float) ($stmt->fetchColumn() ?: 0);
            }

            $stmt = $db->prepare('
                INSERT INTO service_parts (service_record_id, product_id, quantity, unit_cost, used_at, notes, created_by)
                VALUES (:service_record_id, :product_id, :quantity, :unit_cost, :used_at, :notes, :created_by)
            ');
            $stmt->execute([
                'service_record_id' => $serviceRecordId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'used_at' => service_datetime_value((string) ($_POST['used_at'] ?? '')) ?: date('Y-m-d H:i:s'),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => 1,
            ]);

            $stmt = $db->prepare('UPDATE service_records SET cost_total = cost_total + :part_total WHERE id = :id');
            $stmt->execute([
                'part_total' => $quantity * $unitCost,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_part');
        }

        if ($action === 'update_service_record') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }
            app_assert_branch_access($db, 'service_records', $serviceRecordId);

            $statusId = (int) ($_POST['status_id'] ?? 0) ?: null;
            $closedAt = null;
            if ($statusId !== null) {
                $stmt = $db->prepare('SELECT is_closed FROM service_statuses WHERE id = :id');
                $stmt->execute(['id' => $statusId]);
                if ((int) ($stmt->fetchColumn() ?: 0) === 1) {
                    $closedAt = date('Y-m-d H:i:s');
                }
            }

            $stmt = $db->prepare('
                UPDATE service_records
                SET status_id = :status_id, assigned_user_id = :assigned_user_id, cost_total = :cost_total, closed_at = :closed_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status_id' => $statusId,
                'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0) ?: null,
                'cost_total' => (float) ($_POST['cost_total'] ?? 0),
                'closed_at' => $closedAt,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_updated');
        }

        if ($action === 'update_service_cost_analysis') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }

            $partTotal = (float) app_metric($db, 'SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM service_parts WHERE service_record_id = ' . $serviceRecordId);
            $laborCost = max(0, (float) ($_POST['labor_cost'] ?? 0));
            $externalCost = max(0, (float) ($_POST['external_cost'] ?? 0));
            $serviceRevenue = max(0, (float) ($_POST['service_revenue'] ?? 0));

            $stmt = $db->prepare('
                UPDATE service_records
                SET labor_cost = :labor_cost,
                    external_cost = :external_cost,
                    service_revenue = :service_revenue,
                    cost_total = :cost_total
                WHERE id = :id
            ');
            $stmt->execute([
                'labor_cost' => $laborCost,
                'external_cost' => $externalCost,
                'service_revenue' => $serviceRevenue,
                'cost_total' => $partTotal + $laborCost + $externalCost,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_cost_analysis');
        }

        if ($action === 'update_service_delivery') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE service_records
                SET delivery_date = :delivery_date,
                    delivered_by = :delivered_by,
                    delivered_to = :delivered_to,
                    delivery_status = :delivery_status,
                    delivery_notes = :delivery_notes
                WHERE id = :id
            ');
            $stmt->execute([
                'delivery_date' => service_datetime_value((string) ($_POST['delivery_date'] ?? '')) ?: date('Y-m-d H:i:s'),
                'delivered_by' => trim((string) ($_POST['delivered_by'] ?? '')) ?: null,
                'delivered_to' => trim((string) ($_POST['delivered_to'] ?? '')) ?: null,
                'delivery_status' => trim((string) ($_POST['delivery_status'] ?? 'teslim_edildi')) ?: 'teslim_edildi',
                'delivery_notes' => trim((string) ($_POST['delivery_notes'] ?? '')) ?: null,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_delivery');
        }

        if ($action === 'update_customer_approval') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }

            $approvalStatus = trim((string) ($_POST['customer_approval_status'] ?? 'bekliyor')) ?: 'bekliyor';
            if (in_array($approvalStatus, ['onaylandi', 'reddedildi'], true)) {
                $rule = app_approval_rule('service.customer_approval_update');
                $serviceRows = app_fetch_all($db, '
                    SELECT service_revenue
                    FROM service_records
                    WHERE id = :id
                    LIMIT 1
                ', ['id' => $serviceRecordId]);
                $approvalNote = trim((string) ($_POST['customer_approval_description'] ?? ''));

                if (app_approval_rule_matches($rule, ['amount' => (float) ($serviceRows[0]['service_revenue'] ?? 0)])) {
                    if (!app_approval_rule_user_allowed($authUser, $rule)) {
                        $requiredRole = (string) ($rule['approver_role_code'] ?? '');
                        throw new RuntimeException('Bu servis onayi yalnizca ' . ($requiredRole !== '' ? $requiredRole : 'yetkili rol') . ' tarafindan verilebilir.');
                    }

                    if (!empty($rule['require_note']) && $approvalNote === '') {
                        throw new RuntimeException('Bu servis onayi icin aciklama/not zorunludur.');
                    }
                }
            }

            $stmt = $db->prepare('
                UPDATE service_records
                SET customer_approval_status = :customer_approval_status,
                    customer_approval_at = :customer_approval_at,
                    customer_approved_by = :customer_approved_by,
                    customer_approval_channel = :customer_approval_channel,
                    customer_approval_description = :customer_approval_description
                WHERE id = :id
            ');
            $stmt->execute([
                'customer_approval_status' => $approvalStatus,
                'customer_approval_at' => service_datetime_value((string) ($_POST['customer_approval_at'] ?? '')),
                'customer_approved_by' => trim((string) ($_POST['customer_approved_by'] ?? '')) ?: null,
                'customer_approval_channel' => trim((string) ($_POST['customer_approval_channel'] ?? '')) ?: null,
                'customer_approval_description' => trim((string) ($_POST['customer_approval_description'] ?? '')) ?: null,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('customer_approval');
        }

        if ($action === 'upload_service_photo') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0 || !isset($_FILES['photo_file']) || !is_array($_FILES['photo_file'])) {
                throw new RuntimeException('Fotograf icin gecerli servis kaydi ve dosya secilmelidir.');
            }

            $storedPhoto = service_store_photo($_FILES['photo_file']);
            $stmt = $db->prepare('
                INSERT INTO service_photos (service_record_id, file_name, file_path, caption, uploaded_by)
                VALUES (:service_record_id, :file_name, :file_path, :caption, :uploaded_by)
            ');
            $stmt->execute([
                'service_record_id' => $serviceRecordId,
                'file_name' => $storedPhoto['file_name'],
                'file_path' => $storedPhoto['file_path'],
                'caption' => trim((string) ($_POST['caption'] ?? '')) ?: null,
                'uploaded_by' => 1,
            ]);

            service_post_redirect('service_photo');
        }

        if ($action === 'update_service_warranty') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE service_records
                SET warranty_status = :warranty_status,
                    warranty_start_date = :warranty_start_date,
                    warranty_end_date = :warranty_end_date,
                    warranty_provider = :warranty_provider,
                    warranty_document_no = :warranty_document_no,
                    warranty_result = :warranty_result,
                    warranty_notes = :warranty_notes
                WHERE id = :id
            ');
            $stmt->execute([
                'warranty_status' => trim((string) ($_POST['warranty_status'] ?? '')) ?: null,
                'warranty_start_date' => trim((string) ($_POST['warranty_start_date'] ?? '')) ?: null,
                'warranty_end_date' => trim((string) ($_POST['warranty_end_date'] ?? '')) ?: null,
                'warranty_provider' => trim((string) ($_POST['warranty_provider'] ?? '')) ?: null,
                'warranty_document_no' => trim((string) ($_POST['warranty_document_no'] ?? '')) ?: null,
                'warranty_result' => trim((string) ($_POST['warranty_result'] ?? '')) ?: null,
                'warranty_notes' => trim((string) ($_POST['warranty_notes'] ?? '')) ?: null,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_warranty');
        }

        if ($action === 'update_service_sla') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE service_records
                SET sla_priority = :sla_priority,
                    sla_response_due_at = :sla_response_due_at,
                    sla_resolution_due_at = :sla_resolution_due_at,
                    sla_responded_at = :sla_responded_at,
                    sla_resolved_at = :sla_resolved_at,
                    sla_status = :sla_status,
                    sla_notes = :sla_notes
                WHERE id = :id
            ');
            $stmt->execute([
                'sla_priority' => trim((string) ($_POST['sla_priority'] ?? 'normal')) ?: 'normal',
                'sla_response_due_at' => service_datetime_value((string) ($_POST['sla_response_due_at'] ?? '')),
                'sla_resolution_due_at' => service_datetime_value((string) ($_POST['sla_resolution_due_at'] ?? '')),
                'sla_responded_at' => service_datetime_value((string) ($_POST['sla_responded_at'] ?? '')),
                'sla_resolved_at' => service_datetime_value((string) ($_POST['sla_resolved_at'] ?? '')),
                'sla_status' => trim((string) ($_POST['sla_status'] ?? 'takipte')) ?: 'takipte',
                'sla_notes' => trim((string) ($_POST['sla_notes'] ?? '')) ?: null,
                'id' => $serviceRecordId,
            ]);

            service_post_redirect('service_sla');
        }

        if ($action === 'bulk_update_service_record') {
            $serviceIds = service_selected_ids();

            if ($serviceIds === []) {
                throw new RuntimeException('Toplu guncelleme icin servis kaydi secilmedi.');
            }
            foreach ($serviceIds as $serviceId) {
                app_assert_branch_access($db, 'service_records', $serviceId);
            }

            $statusId = (int) ($_POST['bulk_status_id'] ?? 0) ?: null;
            $assignedUserId = (int) ($_POST['bulk_assigned_user_id'] ?? 0) ?: null;

            $closedAt = null;
            if ($statusId !== null) {
                $stmt = $db->prepare('SELECT is_closed FROM service_statuses WHERE id = :id');
                $stmt->execute(['id' => $statusId]);
                if ((int) ($stmt->fetchColumn() ?: 0) === 1) {
                    $closedAt = date('Y-m-d H:i:s');
                }
            }

            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $params = array_merge([$statusId, $assignedUserId, $closedAt], $serviceIds);
            $stmt = $db->prepare("UPDATE service_records SET status_id = ?, assigned_user_id = ?, closed_at = ? WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            service_post_redirect('service_bulk_update');
        }

        if ($action === 'update_service_appointment') {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
            $status = trim((string) ($_POST['appointment_status'] ?? ''));

            if ($appointmentId <= 0 || $status === '') {
                throw new RuntimeException('Gecerli bir randevu ve durum secilmedi.');
            }

            $stmt = $db->prepare('UPDATE service_appointments SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'id' => $appointmentId,
            ]);

            service_post_redirect('service_appointment_updated');
        }

        if ($action === 'update_service_step') {
            $stepId = (int) ($_POST['step_id'] ?? 0);
            $status = trim((string) ($_POST['step_status'] ?? ''));

            if ($stepId <= 0 || $status === '') {
                throw new RuntimeException('Gecerli bir servis adimi ve durum secilmedi.');
            }

            $completedAt = $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null;
            $stmt = $db->prepare('
                UPDATE service_steps
                SET status = :status,
                    started_at = COALESCE(started_at, :started_at),
                    completed_at = :completed_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => $status,
                'started_at' => $status === 'devam_ediyor' || $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null,
                'completed_at' => $completedAt,
                'id' => $stepId,
            ]);

            service_post_redirect('service_step_updated');
        }

        if ($action === 'bulk_export_service_record') {
            $serviceIds = service_selected_ids();
            if ($serviceIds === []) {
                throw new RuntimeException('CSV icin servis kaydi secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $rows = app_fetch_all($db, "
                SELECT s.service_no, c.company_name, c.full_name, p.name AS product_name, st.name AS status_name, u.full_name AS assigned_name, s.cost_total, s.opened_at
                FROM service_records s
                INNER JOIN cari_cards c ON c.id = s.cari_id
                LEFT JOIN stock_products p ON p.id = s.product_id
                LEFT JOIN service_statuses st ON st.id = s.status_id
                LEFT JOIN core_users u ON u.id = s.assigned_user_id
                WHERE s.id IN ({$placeholders})
                ORDER BY s.id DESC
            ", $serviceIds);

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['service_no'],
                    $row['company_name'] ?: $row['full_name'] ?: '-',
                    $row['product_name'] ?: '-',
                    $row['status_name'] ?: '-',
                    $row['assigned_name'] ?: '-',
                    number_format((float) $row['cost_total'], 2, '.', ''),
                    $row['opened_at'],
                ];
            }

            app_csv_download('secili-servisler.csv', ['Servis No', 'Cari', 'Urun', 'Durum', 'Personel', 'Maliyet', 'Acilis'], $exportRows);
        }

        if ($action === 'delete_service_record') {
            $serviceRecordId = (int) ($_POST['service_record_id'] ?? 0);

            if ($serviceRecordId <= 0) {
                throw new RuntimeException('Gecerli bir servis kaydi secilmedi.');
            }
            app_assert_branch_access($db, 'service_records', $serviceRecordId);

            $stmt = $db->prepare('DELETE FROM service_records WHERE id = :id');
            $stmt->execute(['id' => $serviceRecordId]);

            service_post_redirect('delete_service');
        }

        if ($action === 'delete_service_note') {
            $noteId = (int) ($_POST['note_id'] ?? 0);

            if ($noteId <= 0) {
                throw new RuntimeException('Gecerli bir servis notu secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM service_notes WHERE id = :id');
            $stmt->execute(['id' => $noteId]);

            service_post_redirect('delete_service_note');
        }

        if ($action === 'delete_service_appointment') {
            $appointmentId = (int) ($_POST['appointment_id'] ?? 0);

            if ($appointmentId <= 0) {
                throw new RuntimeException('Gecerli bir servis randevusu secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM service_appointments WHERE id = :id');
            $stmt->execute(['id' => $appointmentId]);

            service_post_redirect('delete_service_appointment');
        }

        if ($action === 'delete_service_step') {
            $stepId = (int) ($_POST['step_id'] ?? 0);

            if ($stepId <= 0) {
                throw new RuntimeException('Gecerli bir servis adimi secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM service_steps WHERE id = :id');
            $stmt->execute(['id' => $stepId]);

            service_post_redirect('delete_service_step');
        }

        if ($action === 'delete_service_part') {
            $partId = (int) ($_POST['part_id'] ?? 0);

            if ($partId <= 0) {
                throw new RuntimeException('Gecerli bir servis parcasi secilmedi.');
            }

            $rows = app_fetch_all($db, 'SELECT service_record_id, quantity, unit_cost FROM service_parts WHERE id = :id LIMIT 1', ['id' => $partId]);
            if (!$rows) {
                throw new RuntimeException('Servis parcasi bulunamadi.');
            }

            $part = $rows[0];
            $stmt = $db->prepare('DELETE FROM service_parts WHERE id = :id');
            $stmt->execute(['id' => $partId]);

            $stmt = $db->prepare('UPDATE service_records SET cost_total = GREATEST(0, cost_total - :part_total) WHERE id = :id');
            $stmt->execute([
                'part_total' => (float) $part['quantity'] * (float) $part['unit_cost'],
                'id' => (int) $part['service_record_id'],
            ]);

            service_post_redirect('delete_service_part');
        }

        if ($action === 'delete_service_photo') {
            $photoId = (int) ($_POST['photo_id'] ?? 0);

            if ($photoId <= 0) {
                throw new RuntimeException('Gecerli bir servis fotografi secilmedi.');
            }

            $rows = app_fetch_all($db, 'SELECT file_path FROM service_photos WHERE id = :id LIMIT 1', ['id' => $photoId]);
            if (!$rows) {
                throw new RuntimeException('Servis fotografi bulunamadi.');
            }

            $absolutePath = dirname(__DIR__) . str_replace('/muhasebe1', '', (string) $rows[0]['file_path']);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            $stmt = $db->prepare('DELETE FROM service_photos WHERE id = :id');
            $stmt->execute(['id' => $photoId]);

            service_post_redirect('delete_service_photo');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Servis islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = service_build_filters();
[$serviceCariScopeWhere, $serviceCariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$serviceScopeWhere, $serviceScopeParams] = app_branch_scope_filter($db, null, 's');

$cariCards = app_fetch_all($db, 'SELECT c.id, c.company_name, c.full_name FROM cari_cards c ' . ($serviceCariScopeWhere !== '' ? 'WHERE ' . $serviceCariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 100', $serviceCariScopeParams);
$products = app_fetch_all($db, 'SELECT id, sku, name FROM stock_products ORDER BY id DESC LIMIT 100');
$faultTypes = app_fetch_all($db, 'SELECT id, name FROM service_fault_types ORDER BY id ASC');
$statuses = app_fetch_all($db, 'SELECT id, name, color_code, is_closed FROM service_statuses ORDER BY id ASC');
$users = app_fetch_all($db, 'SELECT id, full_name FROM core_users ORDER BY id ASC');
$serviceFilterWhere = [];
$serviceFilterParams = [];

if ($filters['search'] !== '') {
    $serviceFilterWhere[] = '(s.service_no LIKE :search OR s.serial_no LIKE :search OR s.complaint LIKE :search OR c.company_name LIKE :search OR c.full_name LIKE :search OR p.name LIKE :search)';
    $serviceFilterParams['search'] = '%' . $filters['search'] . '%';
}

if ($filters['status_id'] !== '') {
    $serviceFilterWhere[] = 's.status_id = :status_id';
    $serviceFilterParams['status_id'] = (int) $filters['status_id'];
}

if ($filters['assigned_user_id'] !== '') {
    $serviceFilterWhere[] = 's.assigned_user_id = :assigned_user_id';
    $serviceFilterParams['assigned_user_id'] = (int) $filters['assigned_user_id'];
}
if ($serviceScopeWhere !== '') {
    $serviceFilterWhere[] = $serviceScopeWhere;
    $serviceFilterParams = array_merge($serviceFilterParams, $serviceScopeParams);
}

$serviceWhereSql = $serviceFilterWhere ? 'WHERE ' . implode(' AND ', $serviceFilterWhere) : '';

$serviceRecords = app_fetch_all($db, '
    SELECT
        s.id,
        s.service_no,
        s.serial_no,
        s.complaint,
        s.diagnosis,
        s.warranty_status,
        s.cost_total,
        s.delivery_date,
        s.delivered_by,
        s.delivered_to,
        s.delivery_status,
        s.delivery_notes,
        s.opened_at,
        s.closed_at,
        c.company_name,
        c.full_name,
        p.name AS product_name,
        f.name AS fault_name,
        st.name AS status_name,
        u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN stock_products p ON p.id = s.product_id
    LEFT JOIN service_fault_types f ON f.id = s.fault_type_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ' . $serviceWhereSql . '
    ORDER BY s.id DESC
    LIMIT 50
', $serviceFilterParams);
$serviceRecordOptions = app_fetch_all($db, '
    SELECT s.id, s.service_no, c.company_name, c.full_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    ' . ($serviceScopeWhere !== '' ? 'WHERE ' . $serviceScopeWhere : '') . '
    ORDER BY s.id DESC
    LIMIT 100
', $serviceScopeParams);
$serviceNotes = app_fetch_all($db, '
    SELECT n.id, n.note_text, n.is_customer_visible, n.created_at, s.service_no
    FROM service_notes n
    INNER JOIN service_records s ON s.id = n.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN stock_products p ON p.id = s.product_id
    ' . $serviceWhereSql . '
    ORDER BY n.id DESC
    LIMIT 50
', $serviceFilterParams);
$serviceAppointments = app_fetch_all($db, '
    SELECT a.id, a.appointment_at, a.appointment_type, a.location_text, a.status, a.notes,
           s.service_no, c.company_name, c.full_name, u.full_name AS assigned_name
    FROM service_appointments a
    INNER JOIN service_records s ON s.id = a.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN core_users u ON u.id = a.assigned_user_id
    ORDER BY a.appointment_at ASC, a.id ASC
    LIMIT 50
');
$serviceSteps = app_fetch_all($db, '
    SELECT stp.id, stp.step_name, stp.status, stp.sort_order, stp.started_at, stp.completed_at, stp.notes,
           s.service_no, c.company_name, c.full_name, u.full_name AS assigned_name
    FROM service_steps stp
    INNER JOIN service_records s ON s.id = stp.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN core_users u ON u.id = stp.assigned_user_id
    ORDER BY s.id DESC, stp.sort_order ASC, stp.id ASC
    LIMIT 80
');
$serviceParts = app_fetch_all($db, '
    SELECT sp.id, sp.quantity, sp.unit_cost, (sp.quantity * sp.unit_cost) AS line_total, sp.used_at, sp.notes,
           s.service_no, c.company_name, c.full_name, p.sku, p.name AS product_name, p.unit
    FROM service_parts sp
    INNER JOIN service_records s ON s.id = sp.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    INNER JOIN stock_products p ON p.id = sp.product_id
    ORDER BY sp.id DESC
    LIMIT 80
');
$servicePhotos = app_fetch_all($db, '
    SELECT ph.id, ph.file_name, ph.file_path, ph.caption, ph.created_at,
           s.service_no, c.company_name, c.full_name, u.full_name AS uploaded_name
    FROM service_photos ph
    INNER JOIN service_records s ON s.id = ph.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN core_users u ON u.id = ph.uploaded_by
    ORDER BY ph.id DESC
    LIMIT 80
');
$serviceWarrantyRecords = app_fetch_all($db, '
    SELECT s.id, s.service_no, s.warranty_status, s.warranty_start_date, s.warranty_end_date,
           s.warranty_provider, s.warranty_document_no, s.warranty_result, s.warranty_notes,
           DATEDIFF(s.warranty_end_date, CURDATE()) AS warranty_days_left,
           c.company_name, c.full_name, st.name AS status_name, u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ORDER BY COALESCE(s.warranty_end_date, "9999-12-31") ASC, s.id DESC
    LIMIT 80
');
$serviceSlaRecords = app_fetch_all($db, '
    SELECT s.id, s.service_no, s.sla_priority, s.sla_response_due_at, s.sla_resolution_due_at,
           s.sla_responded_at, s.sla_resolved_at, s.sla_status, s.sla_notes,
           TIMESTAMPDIFF(MINUTE, NOW(), s.sla_response_due_at) AS response_minutes_left,
           TIMESTAMPDIFF(MINUTE, NOW(), s.sla_resolution_due_at) AS resolution_minutes_left,
           c.company_name, c.full_name, st.name AS status_name, u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ORDER BY COALESCE(s.sla_resolution_due_at, "9999-12-31 23:59:59") ASC, s.id DESC
    LIMIT 80
');
$serviceCostAnalyses = app_fetch_all($db, '
    SELECT s.id, s.service_no, s.cost_total, s.labor_cost, s.external_cost, s.service_revenue,
           COALESCE(SUM(sp.quantity * sp.unit_cost), 0) AS part_total,
           c.company_name, c.full_name, st.name AS status_name, u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    LEFT JOIN service_parts sp ON sp.service_record_id = s.id
    GROUP BY s.id, s.service_no, s.cost_total, s.labor_cost, s.external_cost, s.service_revenue, c.company_name, c.full_name, st.name, u.full_name
    ORDER BY s.id DESC
    LIMIT 50
');
$serviceDeliveryRecords = app_fetch_all($db, '
    SELECT s.id, s.service_no, s.delivery_date, s.delivered_by, s.delivered_to, s.delivery_status, s.delivery_notes,
           c.company_name, c.full_name, st.name AS status_name, u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ORDER BY s.id DESC
    LIMIT 50
');
$customerApprovalRecords = app_fetch_all($db, '
    SELECT s.id, s.service_no, s.customer_approval_status, s.customer_approval_at, s.customer_approved_by,
           s.customer_approval_channel, s.customer_approval_description, s.service_revenue, s.cost_total,
           c.company_name, c.full_name, st.name AS status_name, u.full_name AS assigned_name
    FROM service_records s
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN service_statuses st ON st.id = s.status_id
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    ORDER BY s.id DESC
    LIMIT 50
');
$serviceCostTotals = [
    'part_total' => 0.0,
    'labor_cost' => 0.0,
    'external_cost' => 0.0,
    'service_revenue' => 0.0,
    'profit' => 0.0,
];
foreach ($serviceCostAnalyses as $analysisRow) {
    $rowCost = (float) $analysisRow['part_total'] + (float) $analysisRow['labor_cost'] + (float) $analysisRow['external_cost'];
    $serviceCostTotals['part_total'] += (float) $analysisRow['part_total'];
    $serviceCostTotals['labor_cost'] += (float) $analysisRow['labor_cost'];
    $serviceCostTotals['external_cost'] += (float) $analysisRow['external_cost'];
    $serviceCostTotals['service_revenue'] += (float) $analysisRow['service_revenue'];
    $serviceCostTotals['profit'] += (float) $analysisRow['service_revenue'] - $rowCost;
}

$servicePerformanceSummary = [
    'Toplam Servis' => app_table_count($db, 'service_records'),
    'Acik Servis' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE closed_at IS NULL'),
    'Kapanan Servis' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE closed_at IS NOT NULL'),
    'Aylik Servis' => app_metric($db, "SELECT COUNT(*) FROM service_records WHERE DATE_FORMAT(opened_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"),
    'Ortalama Cozum Saati' => number_format((float) app_metric($db, 'SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, opened_at, closed_at)), 0) FROM service_records WHERE closed_at IS NOT NULL'), 1, ',', '.'),
    'SLA Geciken' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE sla_resolution_due_at IS NOT NULL AND sla_resolved_at IS NULL AND sla_resolution_due_at < NOW()'),
    'Onaylanan Servis' => app_metric($db, "SELECT COUNT(*) FROM service_records WHERE customer_approval_status = 'onaylandi'"),
    'Teslim Edilen' => app_metric($db, "SELECT COUNT(*) FROM service_records WHERE delivery_status = 'teslim_edildi'"),
];
$technicianPerformanceRows = app_fetch_all($db, '
    SELECT
        COALESCE(u.full_name, "Atanmamis") AS assigned_name,
        COUNT(s.id) AS total_services,
        SUM(CASE WHEN s.closed_at IS NULL THEN 1 ELSE 0 END) AS open_services,
        SUM(CASE WHEN s.closed_at IS NOT NULL THEN 1 ELSE 0 END) AS closed_services,
        COALESCE(AVG(CASE WHEN s.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, s.opened_at, s.closed_at) END), 0) AS avg_resolution_hours,
        SUM(CASE WHEN s.sla_resolution_due_at IS NOT NULL AND s.sla_resolved_at IS NULL AND s.sla_resolution_due_at < NOW() THEN 1 ELSE 0 END) AS sla_late_count,
        COALESCE(SUM(s.service_revenue), 0) AS service_revenue,
        COALESCE(SUM(s.cost_total), 0) AS service_cost,
        COALESCE(SUM(s.service_revenue - s.cost_total), 0) AS service_profit
    FROM service_records s
    LEFT JOIN core_users u ON u.id = s.assigned_user_id
    GROUP BY s.assigned_user_id, u.full_name
    ORDER BY total_services DESC, assigned_name ASC
    LIMIT 20
');
$statusPerformanceRows = app_fetch_all($db, '
    SELECT COALESCE(st.name, "Durumsuz") AS status_name, COUNT(s.id) AS total_services, COALESCE(SUM(s.cost_total), 0) AS total_cost
    FROM service_records s
    LEFT JOIN service_statuses st ON st.id = s.status_id
    GROUP BY st.name
    ORDER BY total_services DESC
    LIMIT 12
');

$calendarStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['calendar_start']) ? $filters['calendar_start'] : date('Y-m-d');
$calendarEnd = date('Y-m-d', strtotime($calendarStart . ' +7 days'));
$calendarPrev = date('Y-m-d', strtotime($calendarStart . ' -7 days'));
$calendarNext = date('Y-m-d', strtotime($calendarStart . ' +7 days'));
$calendarDays = [];
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $dayDate = date('Y-m-d', strtotime($calendarStart . ' +' . $dayOffset . ' days'));
    $calendarDays[] = [
        'date' => $dayDate,
        'label' => date('d.m', strtotime($dayDate)),
    ];
}

$calendarWhere = 'WHERE a.appointment_at >= :calendar_start AND a.appointment_at < :calendar_end';
$calendarParams = [
    'calendar_start' => $calendarStart . ' 00:00:00',
    'calendar_end' => $calendarEnd . ' 00:00:00',
];
if ($filters['calendar_user_id'] !== '') {
    $calendarWhere .= ' AND a.assigned_user_id = :calendar_user_id';
    $calendarParams['calendar_user_id'] = (int) $filters['calendar_user_id'];
}

$technicianCalendarAppointments = app_fetch_all($db, '
    SELECT a.id, a.assigned_user_id, a.appointment_at, DATE(a.appointment_at) AS appointment_date,
           TIME_FORMAT(a.appointment_at, "%H:%i") AS appointment_time, a.appointment_type, a.location_text, a.status,
           s.service_no, c.company_name, c.full_name, u.full_name AS assigned_name
    FROM service_appointments a
    INNER JOIN service_records s ON s.id = a.service_record_id
    INNER JOIN cari_cards c ON c.id = s.cari_id
    LEFT JOIN core_users u ON u.id = a.assigned_user_id
    ' . $calendarWhere . '
    ORDER BY COALESCE(u.full_name, "Atanmamis"), a.appointment_at ASC
', $calendarParams);

$technicianCalendar = [];
foreach ($users as $user) {
    if ($filters['calendar_user_id'] !== '' && (int) $filters['calendar_user_id'] !== (int) $user['id']) {
        continue;
    }

    $key = 'user_' . (int) $user['id'];
    $technicianCalendar[$key] = [
        'name' => (string) $user['full_name'],
        'days' => [],
    ];
}

foreach ($technicianCalendarAppointments as $appointment) {
    $key = !empty($appointment['assigned_user_id']) ? 'user_' . (int) $appointment['assigned_user_id'] : 'unassigned';
    if (!isset($technicianCalendar[$key])) {
        $technicianCalendar[$key] = [
            'name' => (string) ($appointment['assigned_name'] ?: 'Atanmamis'),
            'days' => [],
        ];
    }

    $appointmentDate = (string) $appointment['appointment_date'];
    $technicianCalendar[$key]['days'][$appointmentDate][] = $appointment;
}
$serviceDocCounts = app_related_doc_counts($db, 'servis', 'service_records', array_column($serviceRecords, 'id'));

$serviceRecords = app_sort_rows($serviceRecords, $filters['record_sort'], [
    'id_desc' => ['id', 'desc'],
    'service_asc' => ['service_no', 'asc'],
    'cost_desc' => ['cost_total', 'desc'],
    'cost_asc' => ['cost_total', 'asc'],
    'status_asc' => ['status_name', 'asc'],
]);
$serviceNotes = app_sort_rows($serviceNotes, $filters['note_sort'], [
    'date_desc' => ['created_at', 'desc'],
    'date_asc' => ['created_at', 'asc'],
    'service_asc' => ['service_no', 'asc'],
]);
$serviceRecordsPagination = app_paginate_rows($serviceRecords, $filters['record_page'], 10);
$serviceNotesPagination = app_paginate_rows($serviceNotes, $filters['note_page'], 10);
$serviceRecords = $serviceRecordsPagination['items'];
$serviceNotes = $serviceNotesPagination['items'];

$summary = [
    'Servis Kaydi' => app_table_count($db, 'service_records'),
    'Acik Servis' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE closed_at IS NULL'),
    'Planli Randevu' => app_metric($db, "SELECT COUNT(*) FROM service_appointments WHERE status IN ('planlandi', 'onaylandi')"),
    'Takvim Randevusu' => count($technicianCalendarAppointments),
    'Acik Servis Adimi' => app_metric($db, "SELECT COUNT(*) FROM service_steps WHERE status IN ('bekliyor', 'devam_ediyor', 'beklemede')"),
    'Kullanilan Parca' => app_table_count($db, 'service_parts'),
    'Servis Kar/Zarar' => number_format($serviceCostTotals['profit'], 2, ',', '.'),
    'Teslim Formu' => app_metric($db, "SELECT COUNT(*) FROM service_records WHERE delivery_status = 'teslim_edildi'"),
    'Musteri Onayi' => app_metric($db, "SELECT COUNT(*) FROM service_records WHERE customer_approval_status = 'onaylandi'"),
    'Servis Fotograf' => app_table_count($db, 'service_photos'),
    'Aktif Garanti' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE warranty_end_date IS NOT NULL AND warranty_end_date >= CURDATE()'),
    'SLA Geciken' => app_metric($db, 'SELECT COUNT(*) FROM service_records WHERE sla_resolution_due_at IS NOT NULL AND sla_resolved_at IS NULL AND sla_resolution_due_at < NOW()'),
    'Ort. Cozum Saat' => $servicePerformanceSummary['Ortalama Cozum Saati'],
    'Not Kaydi' => app_table_count($db, 'service_notes'),
    'Ariza Tipi' => app_table_count($db, 'service_fault_types'),
    'Servis Durumu' => app_table_count($db, 'service_statuses'),
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
    <h3>Servis Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="servis">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Servis no, cari, urun, sikayet">
        </div>
        <div>
            <label>Durum</label>
            <select name="status_id">
                <option value="">Tum durumlar</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= (int) $status['id'] ?>" <?= $filters['status_id'] === (string) $status['id'] ? 'selected' : '' ?>><?= app_h($status['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Personel</label>
            <select name="assigned_user_id">
                <option value="">Tum personeller</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $filters['assigned_user_id'] === (string) $user['id'] ? 'selected' : '' ?>><?= app_h($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=servis" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="servis">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="status_id" value="<?= app_h($filters['status_id']) ?>">
        <input type="hidden" name="assigned_user_id" value="<?= app_h($filters['assigned_user_id']) ?>">
        <div>
            <label>Servis Siralama</label>
            <select name="record_sort">
                <option value="id_desc" <?= $filters['record_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="service_asc" <?= $filters['record_sort'] === 'service_asc' ? 'selected' : '' ?>>Servis no A-Z</option>
                <option value="cost_desc" <?= $filters['record_sort'] === 'cost_desc' ? 'selected' : '' ?>>Maliyet yuksek</option>
                <option value="cost_asc" <?= $filters['record_sort'] === 'cost_asc' ? 'selected' : '' ?>>Maliyet dusuk</option>
                <option value="status_asc" <?= $filters['record_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>Not Siralama</label>
            <select name="note_sort">
                <option value="date_desc" <?= $filters['note_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['note_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="service_asc" <?= $filters['note_sort'] === 'service_asc' ? 'selected' : '' ?>>Servis no A-Z</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Servis Performans Raporlari</h3>
    <section class="module-grid" style="margin-bottom:16px;">
        <?php foreach ($servicePerformanceSummary as $label => $value): ?>
            <div class="card">
                <small><?= app_h((string) $label) ?></small>
                <strong><?= app_h((string) $value) ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Teknisyen</th><th>Toplam</th><th>Acik</th><th>Kapanan</th><th>Ort. Cozum</th><th>SLA Geciken</th><th>Gelir</th><th>Maliyet</th><th>Kar/Zarar</th></tr></thead>
            <tbody>
            <?php foreach ($technicianPerformanceRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['assigned_name']) ?></td>
                    <td><?= (int) $row['total_services'] ?></td>
                    <td><?= (int) $row['open_services'] ?></td>
                    <td><?= (int) $row['closed_services'] ?></td>
                    <td><?= number_format((float) $row['avg_resolution_hours'], 1, ',', '.') ?> saat</td>
                    <td><?= (int) $row['sla_late_count'] ?></td>
                    <td><?= number_format((float) $row['service_revenue'], 2, ',', '.') ?></td>
                    <td><?= number_format((float) $row['service_cost'], 2, ',', '.') ?></td>
                    <td><?= number_format((float) $row['service_profit'], 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$technicianPerformanceRows): ?>
                <tr><td colspan="9">Performans raporu icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrap" style="margin-top:16px;">
        <table>
            <thead><tr><th>Servis Durumu</th><th>Kayit</th><th>Toplam Maliyet</th></tr></thead>
            <tbody>
            <?php foreach ($statusPerformanceRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['status_name']) ?></td>
                    <td><?= (int) $row['total_services'] ?></td>
                    <td><?= number_format((float) $row['total_cost'], 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$statusPerformanceRows): ?>
                <tr><td colspan="3">Durum performansi icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Teknisyen Takvimi</h3>
    <form method="get" class="form-grid compact-form" style="margin-bottom:14px;">
        <input type="hidden" name="module" value="servis">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="status_id" value="<?= app_h($filters['status_id']) ?>">
        <input type="hidden" name="assigned_user_id" value="<?= app_h($filters['assigned_user_id']) ?>">
        <input type="hidden" name="record_sort" value="<?= app_h($filters['record_sort']) ?>">
        <input type="hidden" name="note_sort" value="<?= app_h($filters['note_sort']) ?>">
        <div>
            <label>Baslangic Tarihi</label>
            <input name="calendar_start" type="date" value="<?= app_h($calendarStart) ?>">
        </div>
        <div>
            <label>Teknisyen</label>
            <select name="calendar_user_id">
                <option value="">Tum teknisyenler</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $filters['calendar_user_id'] === (string) $user['id'] ? 'selected' : '' ?>><?= app_h($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Takvimi Goster</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?<?= app_h(http_build_query(['module' => 'servis', 'calendar_start' => $calendarPrev, 'calendar_user_id' => $filters['calendar_user_id'], 'search' => $filters['search'], 'status_id' => $filters['status_id'], 'assigned_user_id' => $filters['assigned_user_id'], 'record_sort' => $filters['record_sort'], 'note_sort' => $filters['note_sort']])) ?>" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Onceki Hafta</a>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?<?= app_h(http_build_query(['module' => 'servis', 'calendar_start' => $calendarNext, 'calendar_user_id' => $filters['calendar_user_id'], 'search' => $filters['search'], 'status_id' => $filters['status_id'], 'assigned_user_id' => $filters['assigned_user_id'], 'record_sort' => $filters['record_sort'], 'note_sort' => $filters['note_sort']])) ?>" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#ecfeff;color:#155e75;font-weight:700;text-decoration:none;">Sonraki Hafta</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Teknisyen</th>
                    <?php foreach ($calendarDays as $day): ?>
                        <th><?= app_h($day['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($technicianCalendar as $technician): ?>
                <tr>
                    <td><strong><?= app_h($technician['name']) ?></strong></td>
                    <?php foreach ($calendarDays as $day): ?>
                        <td>
                            <?php foreach (($technician['days'][$day['date']] ?? []) as $appointment): ?>
                                <div style="margin-bottom:8px;padding:9px 10px;border-radius:12px;background:#fff7ed;border:1px solid #fed7aa;">
                                    <strong><?= app_h((string) $appointment['appointment_time']) ?> - <?= app_h((string) $appointment['service_no']) ?></strong><br>
                                    <span><?= app_h((string) ($appointment['company_name'] ?: $appointment['full_name'] ?: '-')) ?></span><br>
                                    <small><?= app_h((string) ($appointment['appointment_type'] ?: '-')) ?> / <?= app_h((string) $appointment['status']) ?></small>
                                    <?php if (($appointment['location_text'] ?? '') !== ''): ?>
                                        <br><small><?= app_h((string) $appointment['location_text']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($technician['days'][$day['date']])): ?>
                                <span style="color:#98a2b3;">-</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$technicianCalendar): ?>
                <tr><td colspan="8">Takvim icin teknisyen bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yeni Servis Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_service_record">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(service_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun</label>
                <select name="product_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Ariza Tipi</label>
                <select name="fault_type_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($faultTypes as $fault): ?>
                        <option value="<?= (int) $fault['id'] ?>"><?= app_h($fault['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= (int) $status['id'] ?>"><?= app_h($status['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Teknik Personel</label>
                <select name="assigned_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Seri No</label>
                <input name="serial_no" placeholder="SN-001">
            </div>
            <div>
                <label>Garanti</label>
                <input name="warranty_status" placeholder="Garanti ici / disi">
            </div>
            <div>
                <label>Tahmini Maliyet</label>
                <input name="cost_total" type="number" step="0.01" value="0">
            </div>
            <div>
                <label>Teslim Alma Tipi</label>
                <select name="acceptance_type">
                    <option value="servis_kabul">Servis kabul</option>
                    <option value="elden">Elden teslim</option>
                    <option value="kargo">Kargo ile teslim</option>
                    <option value="yerinde">Yerinde servis</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Teslim Alan</label>
                <input name="received_by" placeholder="Teslim alan personel">
            </div>
            <div>
                <label>Tahmini Teslim Tarihi</label>
                <input name="estimated_delivery_date" type="date">
            </div>
            <div>
                <label>Teslim Eden / Imza</label>
                <input name="acceptance_signed_by" placeholder="Musteri veya teslim eden">
            </div>
            <div class="full">
                <label>Sikayet / Ariza</label>
                <textarea name="complaint" rows="3" placeholder="Musteri sikayeti veya cihaz arizasi" required></textarea>
            </div>
            <div class="full">
                <label>Teslim Alinan Aksesuarlar</label>
                <textarea name="received_accessories" rows="2" placeholder="Sarj cihazi, canta, kablo, aparat vb."></textarea>
            </div>
            <div class="full">
                <label>Cihaz Fiziksel Durumu</label>
                <textarea name="device_condition" rows="2" placeholder="Cizik, kirik, ekran durumu, eksik parca vb."></textarea>
            </div>
            <div class="full">
                <label>Musteri Onay Notu</label>
                <textarea name="customer_approval_note" rows="2" placeholder="On onay, veri sorumlulugu veya teslim sartlari"></textarea>
            </div>
            <div class="full">
                <label>Teshis</label>
                <textarea name="diagnosis" rows="3" placeholder="Ilk teknik teshis"></textarea>
            </div>
            <div class="full">
                <button type="submit">Servis Kaydi Ac</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Servis Notu</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_service_note">
            <div>
                <label>Servis Kaydi</label>
                <select name="service_record_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($serviceRecordOptions as $record): ?>
                        <option value="<?= (int) $record['id'] ?>"><?= app_h($record['service_no'] . ' / ' . ($record['company_name'] ?: $record['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="is_customer_visible" value="1"> Musteri goruntuleyebilir</label>
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="note_text" rows="4" placeholder="Servis surecine dair not" required></textarea>
            </div>
            <div class="full">
                <button type="submit">Not Ekle</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Servis Randevusu</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_service_appointment">
            <div>
                <label>Servis Kaydi</label>
                <select name="service_record_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($serviceRecordOptions as $record): ?>
                        <option value="<?= (int) $record['id'] ?>"><?= app_h($record['service_no'] . ' / ' . ($record['company_name'] ?: $record['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Randevu Tarihi</label>
                <input name="appointment_at" type="datetime-local" required>
            </div>
            <div>
                <label>Teknik Personel</label>
                <select name="assigned_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Randevu Tipi</label>
                <select name="appointment_type">
                    <option value="servis">Servis</option>
                    <option value="yerinde">Yerinde servis</option>
                    <option value="teslim">Teslim</option>
                    <option value="kontrol">Kontrol</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="planlandi">Planlandi</option>
                    <option value="onaylandi">Onaylandi</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Konum</label>
                <input name="location_text" placeholder="Atolye / musteri adresi / sube">
            </div>
            <div class="full">
                <label>Randevu Notu</label>
                <textarea name="notes" rows="3" placeholder="Randevu hazirlik notu veya musteri talebi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Randevu Olustur</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Yaklasan Servis Randevulari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Servis</th><th>Cari</th><th>Tip</th><th>Personel</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($serviceAppointments as $appointment): ?>
                    <tr>
                        <td><?= app_h((string) $appointment['appointment_at']) ?></td>
                        <td><?= app_h((string) $appointment['service_no']) ?></td>
                        <td><?= app_h((string) ($appointment['company_name'] ?: $appointment['full_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($appointment['appointment_type'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($appointment['assigned_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) $appointment['status']) ?></td>
                        <td>
                            <div class="stack">
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_service_appointment">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                    <select name="appointment_status">
                                        <?php foreach (['planlandi' => 'Planlandi', 'onaylandi' => 'Onaylandi', 'tamamlandi' => 'Tamamlandi', 'iptal' => 'Iptal'] as $value => $label): ?>
                                            <option value="<?= app_h($value) ?>" <?= $appointment['status'] === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Durum</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu servis randevusu silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_service_appointment">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$serviceAppointments): ?>
                    <tr><td colspan="7">Henuz servis randevusu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Servis Adimi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_service_step">
            <div>
                <label>Servis Kaydi</label>
                <select name="service_record_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($serviceRecordOptions as $record): ?>
                        <option value="<?= (int) $record['id'] ?>"><?= app_h($record['service_no'] . ' / ' . ($record['company_name'] ?: $record['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Adim Adi</label>
                <input name="step_name" placeholder="On kontrol / ariza tespit / test" required>
            </div>
            <div>
                <label>Sorumlu Teknisyen</label>
                <select name="assigned_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="devam_ediyor">Devam ediyor</option>
                    <option value="beklemede">Beklemede</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Sira</label>
                <input name="sort_order" type="number" value="0">
            </div>
            <div>
                <label>Baslangic</label>
                <input name="started_at" type="datetime-local">
            </div>
            <div>
                <label>Bitis</label>
                <input name="completed_at" type="datetime-local">
            </div>
            <div class="full">
                <label>Adim Notu</label>
                <textarea name="notes" rows="3" placeholder="Yapilacak is, kontrol sonucu veya bekleme nedeni"></textarea>
            </div>
            <div class="full">
                <button type="submit">Servis Adimi Ekle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Servis Adimlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Servis</th><th>Adim</th><th>Teknisyen</th><th>Durum</th><th>Tarih</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($serviceSteps as $step): ?>
                    <tr>
                        <td><?= app_h((string) $step['service_no']) ?><br><small><?= app_h((string) ($step['company_name'] ?: $step['full_name'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) $step['step_name']) ?><br><small><?= app_h((string) ($step['notes'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) ($step['assigned_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) $step['status']) ?></td>
                        <td><small>Bas: <?= app_h((string) ($step['started_at'] ?: '-')) ?><br>Bit: <?= app_h((string) ($step['completed_at'] ?: '-')) ?></small></td>
                        <td>
                            <div class="stack">
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_service_step">
                                    <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                                    <select name="step_status">
                                        <?php foreach (['bekliyor' => 'Bekliyor', 'devam_ediyor' => 'Devam ediyor', 'beklemede' => 'Beklemede', 'tamamlandi' => 'Tamamlandi', 'iptal' => 'Iptal'] as $value => $label): ?>
                                            <option value="<?= app_h($value) ?>" <?= $step['status'] === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Durum</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu servis adimi silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_service_step">
                                    <input type="hidden" name="step_id" value="<?= (int) $step['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$serviceSteps): ?>
                    <tr><td colspan="6">Henuz servis adimi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Servis SLA</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Servis</th><th>Cari</th><th>Oncelik</th><th>Mudahale SLA</th><th>Cozum SLA</th><th>Durum</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($serviceSlaRecords as $sla): ?>
                <?php
                    $responseLabel = $sla['response_minutes_left'] !== null ? service_minutes_label((int) $sla['response_minutes_left']) : '-';
                    $resolutionLabel = $sla['resolution_minutes_left'] !== null ? service_minutes_label((int) $sla['resolution_minutes_left']) : '-';
                ?>
                <tr>
                    <td><?= app_h((string) $sla['service_no']) ?><br><small><?= app_h((string) ($sla['assigned_name'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($sla['company_name'] ?: $sla['full_name'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($sla['sla_priority'] ?: 'normal')) ?></td>
                    <td><small>Hedef: <?= app_h((string) ($sla['sla_response_due_at'] ?: '-')) ?><br>Gercek: <?= app_h((string) ($sla['sla_responded_at'] ?: '-')) ?><br><?= app_h($responseLabel) ?></small></td>
                    <td><small>Hedef: <?= app_h((string) ($sla['sla_resolution_due_at'] ?: '-')) ?><br>Gercek: <?= app_h((string) ($sla['sla_resolved_at'] ?: '-')) ?><br><?= app_h($resolutionLabel) ?></small></td>
                    <td><?= app_h((string) ($sla['sla_status'] ?: 'takipte')) ?><br><small><?= app_h((string) ($sla['status_name'] ?: '-')) ?></small></td>
                    <td>
                        <form method="post" class="compact-form">
                            <input type="hidden" name="action" value="update_service_sla">
                            <input type="hidden" name="service_record_id" value="<?= (int) $sla['id'] ?>">
                            <select name="sla_priority">
                                <?php foreach (['dusuk' => 'Dusuk', 'normal' => 'Normal', 'yuksek' => 'Yuksek', 'kritik' => 'Kritik'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($sla['sla_priority'] ?: 'normal') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="sla_response_due_at" type="datetime-local" value="<?= app_h($sla['sla_response_due_at'] ? date('Y-m-d\TH:i', strtotime((string) $sla['sla_response_due_at'])) : '') ?>">
                            <input name="sla_resolution_due_at" type="datetime-local" value="<?= app_h($sla['sla_resolution_due_at'] ? date('Y-m-d\TH:i', strtotime((string) $sla['sla_resolution_due_at'])) : '') ?>">
                            <input name="sla_responded_at" type="datetime-local" value="<?= app_h($sla['sla_responded_at'] ? date('Y-m-d\TH:i', strtotime((string) $sla['sla_responded_at'])) : '') ?>">
                            <input name="sla_resolved_at" type="datetime-local" value="<?= app_h($sla['sla_resolved_at'] ? date('Y-m-d\TH:i', strtotime((string) $sla['sla_resolved_at'])) : '') ?>">
                            <select name="sla_status">
                                <?php foreach (['takipte' => 'Takipte', 'suresinde' => 'Suresinde', 'gecikti' => 'Gecikti', 'askida' => 'Askida', 'iptal' => 'Iptal'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($sla['sla_status'] ?: 'takipte') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="sla_notes" value="<?= app_h((string) ($sla['sla_notes'] ?: '')) ?>" placeholder="SLA notu">
                            <button type="submit">SLA Kaydet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$serviceSlaRecords): ?>
                <tr><td colspan="7">SLA takibi icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Garanti Takibi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Servis</th><th>Cari</th><th>Garanti</th><th>Tarihler</th><th>Kalan</th><th>Belge</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($serviceWarrantyRecords as $warranty): ?>
                <?php
                    $daysLeft = $warranty['warranty_days_left'] !== null ? (int) $warranty['warranty_days_left'] : null;
                    $dayLabel = $daysLeft === null ? '-' : ($daysLeft < 0 ? 'Suresi doldu' : $daysLeft . ' gun');
                ?>
                <tr>
                    <td><?= app_h((string) $warranty['service_no']) ?><br><small><?= app_h((string) ($warranty['assigned_name'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($warranty['company_name'] ?: $warranty['full_name'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($warranty['warranty_status'] ?: '-')) ?><br><small><?= app_h((string) ($warranty['warranty_result'] ?: '-')) ?></small></td>
                    <td><small>Bas: <?= app_h((string) ($warranty['warranty_start_date'] ?: '-')) ?><br>Bit: <?= app_h((string) ($warranty['warranty_end_date'] ?: '-')) ?></small></td>
                    <td><?= app_h($dayLabel) ?></td>
                    <td><small><?= app_h((string) ($warranty['warranty_provider'] ?: '-')) ?><br><?= app_h((string) ($warranty['warranty_document_no'] ?: '-')) ?></small></td>
                    <td>
                        <form method="post" class="compact-form">
                            <input type="hidden" name="action" value="update_service_warranty">
                            <input type="hidden" name="service_record_id" value="<?= (int) $warranty['id'] ?>">
                            <select name="warranty_status">
                                <?php foreach (['' => 'Durum secin', 'garanti_ici' => 'Garanti ici', 'garanti_disi' => 'Garanti disi', 'ucretli' => 'Ucretli servis', 'iyi_niyet' => 'Iyi niyet garantisi'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($warranty['warranty_status'] ?? '') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="warranty_start_date" type="date" value="<?= app_h((string) ($warranty['warranty_start_date'] ?: '')) ?>">
                            <input name="warranty_end_date" type="date" value="<?= app_h((string) ($warranty['warranty_end_date'] ?: '')) ?>">
                            <input name="warranty_provider" value="<?= app_h((string) ($warranty['warranty_provider'] ?: '')) ?>" placeholder="Saglayici">
                            <input name="warranty_document_no" value="<?= app_h((string) ($warranty['warranty_document_no'] ?: '')) ?>" placeholder="Belge no">
                            <select name="warranty_result">
                                <?php foreach (['' => 'Sonuc secin', 'bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandi', 'reddedildi' => 'Reddedildi', 'degisim' => 'Degisim', 'iade' => 'Iade'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($warranty['warranty_result'] ?? '') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="warranty_notes" value="<?= app_h((string) ($warranty['warranty_notes'] ?: '')) ?>" placeholder="Garanti notu">
                            <button type="submit">Garanti Kaydet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$serviceWarrantyRecords): ?>
                <tr><td colspan="7">Garanti takibi icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Servis Fotografi Yukle</h3>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="upload_service_photo">
            <div>
                <label>Servis Kaydi</label>
                <select name="service_record_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($serviceRecordOptions as $record): ?>
                        <option value="<?= (int) $record['id'] ?>"><?= app_h($record['service_no'] . ' / ' . ($record['company_name'] ?: $record['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fotograf</label>
                <input type="file" name="photo_file" accept="image/*" required>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <input name="caption" placeholder="Oncesi / sonrasi / ariza noktasi / parca degisimi">
            </div>
            <div class="full">
                <button type="submit">Fotograf Yukle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Servis Fotograflari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Gorsel</th><th>Servis</th><th>Aciklama</th><th>Yukleyen</th><th>Tarih</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($servicePhotos as $photo): ?>
                    <tr>
                        <td><a href="<?= app_h((string) $photo['file_path']) ?>" target="_blank"><img src="<?= app_h((string) $photo['file_path']) ?>" alt="<?= app_h((string) ($photo['caption'] ?: $photo['file_name'])) ?>" style="width:76px;height:56px;object-fit:cover;border-radius:12px;border:1px solid #eadfce;"></a></td>
                        <td><?= app_h((string) $photo['service_no']) ?><br><small><?= app_h((string) ($photo['company_name'] ?: $photo['full_name'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) ($photo['caption'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($photo['uploaded_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) $photo['created_at']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu servis fotografi silinsin mi?');">
                                <input type="hidden" name="action" value="delete_service_photo">
                                <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$servicePhotos): ?>
                    <tr><td colspan="6">Henuz servis fotografi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Musteri Onayi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Servis</th><th>Cari</th><th>Onay Durumu</th><th>Onay Bilgisi</th><th>Maliyet/Gelir</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($customerApprovalRecords as $approval): ?>
                <tr>
                    <td><?= app_h((string) $approval['service_no']) ?><br><small><?= app_h((string) ($approval['assigned_name'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($approval['company_name'] ?: $approval['full_name'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($approval['customer_approval_status'] ?: 'bekliyor')) ?><br><small><?= app_h((string) ($approval['status_name'] ?: '-')) ?></small></td>
                    <td>
                        <small>
                            Tarih: <?= app_h((string) ($approval['customer_approval_at'] ?: '-')) ?><br>
                            Onaylayan: <?= app_h((string) ($approval['customer_approved_by'] ?: '-')) ?><br>
                            Kanal: <?= app_h((string) ($approval['customer_approval_channel'] ?: '-')) ?>
                        </small>
                    </td>
                    <td>
                        <small>
                            Maliyet: <?= number_format((float) $approval['cost_total'], 2, ',', '.') ?><br>
                            Gelir: <?= number_format((float) $approval['service_revenue'], 2, ',', '.') ?>
                        </small>
                    </td>
                    <td>
                        <form method="post" class="compact-form">
                            <input type="hidden" name="action" value="update_customer_approval">
                            <input type="hidden" name="service_record_id" value="<?= (int) $approval['id'] ?>">
                            <select name="customer_approval_status">
                                <?php foreach (['bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandi', 'reddedildi' => 'Reddedildi', 'revizyon' => 'Revizyon istendi', 'iptal' => 'Iptal'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($approval['customer_approval_status'] ?: 'bekliyor') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="customer_approval_at" type="datetime-local" value="<?= app_h($approval['customer_approval_at'] ? date('Y-m-d\TH:i', strtotime((string) $approval['customer_approval_at'])) : '') ?>">
                            <input name="customer_approved_by" value="<?= app_h((string) ($approval['customer_approved_by'] ?: '')) ?>" placeholder="Onaylayan kisi">
                            <select name="customer_approval_channel">
                                <?php foreach (['' => 'Kanal secin', 'telefon' => 'Telefon', 'eposta' => 'E-posta', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'imzali_form' => 'Imzali form', 'panel' => 'Panel'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($approval['customer_approval_channel'] ?? '') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="customer_approval_description" value="<?= app_h((string) ($approval['customer_approval_description'] ?: '')) ?>" placeholder="Onay notu">
                            <button type="submit">Onayi Kaydet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customerApprovalRecords): ?>
                <tr><td colspan="6">Musteri onayi icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Servis Teslim Formu</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Servis</th><th>Cari</th><th>Durum</th><th>Teslim Bilgisi</th><th>Form</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($serviceDeliveryRecords as $delivery): ?>
                <tr>
                    <td><?= app_h((string) $delivery['service_no']) ?><br><small><?= app_h((string) ($delivery['assigned_name'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($delivery['company_name'] ?: $delivery['full_name'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($delivery['delivery_status'] ?: 'bekliyor')) ?><br><small><?= app_h((string) ($delivery['status_name'] ?: '-')) ?></small></td>
                    <td>
                        <small>
                            Tarih: <?= app_h((string) ($delivery['delivery_date'] ?: '-')) ?><br>
                            Teslim Eden: <?= app_h((string) ($delivery['delivered_by'] ?: '-')) ?><br>
                            Teslim Alan: <?= app_h((string) ($delivery['delivered_to'] ?: '-')) ?>
                        </small>
                    </td>
                    <td>
                        <a href="print.php?type=service_delivery&id=<?= (int) $delivery['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f0fdf4;color:#166534;font-weight:700;text-decoration:none;">Teslim Formu</a>
                    </td>
                    <td>
                        <form method="post" class="compact-form">
                            <input type="hidden" name="action" value="update_service_delivery">
                            <input type="hidden" name="service_record_id" value="<?= (int) $delivery['id'] ?>">
                            <input name="delivery_date" type="datetime-local" value="<?= app_h($delivery['delivery_date'] ? date('Y-m-d\TH:i', strtotime((string) $delivery['delivery_date'])) : '') ?>">
                            <input name="delivered_by" value="<?= app_h((string) ($delivery['delivered_by'] ?: '')) ?>" placeholder="Teslim eden">
                            <input name="delivered_to" value="<?= app_h((string) ($delivery['delivered_to'] ?: '')) ?>" placeholder="Teslim alan">
                            <select name="delivery_status">
                                <?php foreach (['bekliyor' => 'Bekliyor', 'teslim_edildi' => 'Teslim edildi', 'kargo' => 'Kargo ile teslim', 'iptal' => 'Iptal'] as $value => $label): ?>
                                    <option value="<?= app_h($value) ?>" <?= ($delivery['delivery_status'] ?: 'bekliyor') === $value ? 'selected' : '' ?>><?= app_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="delivery_notes" value="<?= app_h((string) ($delivery['delivery_notes'] ?: '')) ?>" placeholder="Teslim notu">
                            <button type="submit">Teslimi Kaydet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$serviceDeliveryRecords): ?>
                <tr><td colspan="6">Teslim formu icin servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Servis Maliyet Analizi</h3>
    <section class="module-grid" style="margin-bottom:16px;">
        <div class="card">
            <small>Parca Maliyeti</small>
            <strong><?= number_format($serviceCostTotals['part_total'], 2, ',', '.') ?></strong>
        </div>
        <div class="card">
            <small>Iscilik Maliyeti</small>
            <strong><?= number_format($serviceCostTotals['labor_cost'], 2, ',', '.') ?></strong>
        </div>
        <div class="card">
            <small>Dis / Ek Maliyet</small>
            <strong><?= number_format($serviceCostTotals['external_cost'], 2, ',', '.') ?></strong>
        </div>
        <div class="card">
            <small>Gelir</small>
            <strong><?= number_format($serviceCostTotals['service_revenue'], 2, ',', '.') ?></strong>
        </div>
        <div class="card">
            <small>Kar / Zarar</small>
            <strong><?= number_format($serviceCostTotals['profit'], 2, ',', '.') ?></strong>
        </div>
    </section>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Servis</th><th>Cari</th><th>Parca</th><th>Iscilik</th><th>Dis Maliyet</th><th>Gelir</th><th>Kar/Zarar</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($serviceCostAnalyses as $analysis): ?>
                <?php
                    $analysisPartTotal = (float) $analysis['part_total'];
                    $analysisLaborCost = (float) $analysis['labor_cost'];
                    $analysisExternalCost = (float) $analysis['external_cost'];
                    $analysisRevenue = (float) $analysis['service_revenue'];
                    $analysisTotalCost = $analysisPartTotal + $analysisLaborCost + $analysisExternalCost;
                    $analysisProfit = $analysisRevenue - $analysisTotalCost;
                ?>
                <tr>
                    <td><?= app_h((string) $analysis['service_no']) ?><br><small><?= app_h((string) ($analysis['status_name'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($analysis['company_name'] ?: $analysis['full_name'] ?: '-')) ?><br><small><?= app_h((string) ($analysis['assigned_name'] ?: '-')) ?></small></td>
                    <td><?= number_format($analysisPartTotal, 2, ',', '.') ?></td>
                    <td><?= number_format($analysisLaborCost, 2, ',', '.') ?></td>
                    <td><?= number_format($analysisExternalCost, 2, ',', '.') ?></td>
                    <td><?= number_format($analysisRevenue, 2, ',', '.') ?></td>
                    <td><?= number_format($analysisProfit, 2, ',', '.') ?></td>
                    <td>
                        <form method="post" class="compact-form">
                            <input type="hidden" name="action" value="update_service_cost_analysis">
                            <input type="hidden" name="service_record_id" value="<?= (int) $analysis['id'] ?>">
                            <input type="number" name="labor_cost" step="0.01" value="<?= app_h((string) $analysisLaborCost) ?>" placeholder="Iscilik">
                            <input type="number" name="external_cost" step="0.01" value="<?= app_h((string) $analysisExternalCost) ?>" placeholder="Dis maliyet">
                            <input type="number" name="service_revenue" step="0.01" value="<?= app_h((string) $analysisRevenue) ?>" placeholder="Gelir">
                            <button type="submit">Analizi Kaydet</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$serviceCostAnalyses): ?>
                <tr><td colspan="8">Henuz maliyet analizi yapilacak servis kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Parca Kullanimi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_service_part">
            <div>
                <label>Servis Kaydi</label>
                <select name="service_record_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($serviceRecordOptions as $record): ?>
                        <option value="<?= (int) $record['id'] ?>"><?= app_h($record['service_no'] . ' / ' . ($record['company_name'] ?: $record['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Parca / Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input name="quantity" type="number" step="0.001" value="1" required>
            </div>
            <div>
                <label>Birim Maliyet</label>
                <input name="unit_cost" type="number" step="0.01" value="0">
            </div>
            <div>
                <label>Kullanim Tarihi</label>
                <input name="used_at" type="datetime-local">
            </div>
            <div class="full">
                <label>Parca Notu</label>
                <textarea name="notes" rows="3" placeholder="Degisen parca, seri no veya aciklama"></textarea>
            </div>
            <div class="full">
                <button type="submit">Parca Kullan</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Kullanilan Parcalar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Servis</th><th>Parca</th><th>Miktar</th><th>Birim</th><th>Tutar</th><th>Tarih</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($serviceParts as $part): ?>
                    <tr>
                        <td><?= app_h((string) $part['service_no']) ?><br><small><?= app_h((string) ($part['company_name'] ?: $part['full_name'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) $part['product_name']) ?><br><small><?= app_h((string) ($part['sku'] ?: '-')) ?><?= ($part['notes'] ?? '') !== '' ? ' / ' . app_h((string) $part['notes']) : '' ?></small></td>
                        <td><?= number_format((float) $part['quantity'], 3, ',', '.') ?> <?= app_h((string) ($part['unit'] ?: '')) ?></td>
                        <td><?= number_format((float) $part['unit_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $part['line_total'], 2, ',', '.') ?></td>
                        <td><?= app_h((string) ($part['used_at'] ?: '-')) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu servis parcasi silinsin mi?');">
                                <input type="hidden" name="action" value="delete_service_part">
                                <input type="hidden" name="part_id" value="<?= (int) $part['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$serviceParts): ?>
                    <tr><td colspan="7">Henuz parca kullanimi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Son Servis Kayitlari</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_service_record">
            <select name="bulk_status_id">
                <option value="">Durum Secin</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= (int) $status['id'] ?>"><?= app_h($status['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="bulk_assigned_user_id">
                <option value="">Personel Secin</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Secili Servisleri Guncelle</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_export_service_record'">Secili Servisleri CSV</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.service-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Cari</th><th>Urun</th><th>Durum</th><th>Personel</th><th>Maliyet</th><th>Evrak</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($serviceRecords as $record): ?>
                    <tr>
                        <td><input class="service-check" type="checkbox" name="service_record_ids[]" value="<?= (int) $record['id'] ?>"></td>
                        <td><?= app_h($record['service_no']) ?></td>
                        <td><?= app_h($record['company_name'] ?: $record['full_name'] ?: '-') ?></td>
                        <td><?= app_h($record['product_name'] ?: '-') ?></td>
                        <td><?= app_h($record['status_name'] ?: '-') ?></td>
                        <td><?= app_h($record['assigned_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $record['cost_total'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="index.php?module=evrak&filter_module=servis&filter_related_table=service_records&filter_related_id=<?= (int) $record['id'] ?>&prefill_module=servis&prefill_related_table=service_records&prefill_related_id=<?= (int) $record['id'] ?>">
                                    Evrak (<?= (int) ($serviceDocCounts[(int) $record['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('servis', 'service_records', (int) $record['id'], 'index.php?module=servis')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <div class="stack">
                                <a href="service_detail.php?id=<?= (int) $record['id'] ?>">Detay</a>
                                <a href="print.php?type=service&id=<?= (int) $record['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#fff1df;color:#7c2d12;font-weight:700;text-decoration:none;">PDF / Yazdir</a>
                                <a href="print.php?type=service_acceptance&id=<?= (int) $record['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#ecfeff;color:#155e75;font-weight:700;text-decoration:none;">Kabul Formu</a>
                                <a href="print.php?type=service_delivery&id=<?= (int) $record['id'] ?>" target="_blank" rel="noopener" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f0fdf4;color:#166534;font-weight:700;text-decoration:none;">Teslim Formu</a>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_service_record">
                                    <input type="hidden" name="service_record_id" value="<?= (int) $record['id'] ?>">
                                    <select name="status_id">
                                        <option value="">Durum</option>
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= (int) $status['id'] ?>" <?= $record['status_name'] === $status['name'] ? 'selected' : '' ?>><?= app_h($status['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="assigned_user_id">
                                        <option value="">Personel</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= (int) $user['id'] ?>" <?= $record['assigned_name'] === $user['full_name'] ? 'selected' : '' ?>><?= app_h($user['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" name="cost_total" step="0.01" value="<?= app_h((string) $record['cost_total']) ?>">
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu servis kaydi silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_service_record">
                                    <input type="hidden" name="service_record_id" value="<?= (int) $record['id'] ?>">
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
        <?php if ($serviceRecordsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $serviceRecordsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'servis', 'search' => $filters['search'], 'status_id' => $filters['status_id'], 'assigned_user_id' => $filters['assigned_user_id'], 'record_sort' => $filters['record_sort'], 'note_sort' => $filters['note_sort'], 'record_page' => $page, 'note_page' => $serviceNotesPagination['page']])) ?>"><?= $page === $serviceRecordsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Servis Notlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Servis</th><th>Not</th><th>Musteri</th><th>Tarih</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($serviceNotes as $note): ?>
                    <tr>
                        <td><?= app_h($note['service_no']) ?></td>
                        <td><?= app_h($note['note_text']) ?></td>
                        <td><?= $note['is_customer_visible'] ? 'Evet' : 'Hayir' ?></td>
                        <td><?= app_h($note['created_at']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu servis notu silinsin mi?');">
                                <input type="hidden" name="action" value="delete_service_note">
                                <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($serviceNotesPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $serviceNotesPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'servis', 'search' => $filters['search'], 'status_id' => $filters['status_id'], 'assigned_user_id' => $filters['assigned_user_id'], 'record_sort' => $filters['record_sort'], 'note_sort' => $filters['note_sort'], 'record_page' => $serviceRecordsPagination['page'], 'note_page' => $page])) ?>"><?= $page === $serviceNotesPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
