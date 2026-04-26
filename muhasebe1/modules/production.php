<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Uretim modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function production_next_code(PDO $db, string $table, string $prefix): string
{
    $count = app_table_count($db, $table) + 1;
    return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
}

function production_redirect(string $result): void
{
    app_redirect('index.php?module=uretim&ok=' . urlencode($result));
}

function production_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'recipe_status' => trim((string) ($_GET['recipe_status'] ?? '')),
        'order_status' => trim((string) ($_GET['order_status'] ?? '')),
        'recipe_sort' => trim((string) ($_GET['recipe_sort'] ?? 'id_desc')),
        'order_sort' => trim((string) ($_GET['order_sort'] ?? 'id_desc')),
        'recipe_page' => max(1, (int) ($_GET['recipe_page'] ?? 1)),
        'order_page' => max(1, (int) ($_GET['order_page'] ?? 1)),
    ];
}

function production_selected_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function production_ensure_multilevel_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_recipe_subrecipes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            parent_recipe_id BIGINT NOT NULL,
            child_recipe_id BIGINT NOT NULL,
            level_no INT NOT NULL DEFAULT 1,
            quantity_multiplier DECIMAL(15,3) NOT NULL DEFAULT 1.000,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            operation_note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_recipe_subrecipes_parent (parent_recipe_id),
            INDEX idx_production_recipe_subrecipes_child (child_recipe_id),
            UNIQUE KEY uq_production_recipe_subrecipes_pair (parent_recipe_id, child_recipe_id)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_operation_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_order_operations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            sequence_no INT NOT NULL DEFAULT 1,
            operation_name VARCHAR(180) NOT NULL,
            planned_minutes INT NOT NULL DEFAULT 0,
            actual_minutes INT NOT NULL DEFAULT 0,
            status ENUM('bekliyor','basladi','tamamlandi','atlandi') NOT NULL DEFAULT 'bekliyor',
            responsible_name VARCHAR(150) NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_order_operations_order (production_order_id),
            INDEX idx_production_order_operations_status (status)
        ) ENGINE=InnoDB
    ");
}

function production_column_exists(PDO $db, string $table, string $column): bool
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

function production_ensure_workcenter_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_work_centers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(180) NOT NULL,
            station_type VARCHAR(80) NULL,
            responsible_name VARCHAR(150) NULL,
            hourly_capacity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
            hourly_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            status ENUM('aktif','bakimda','pasif') NOT NULL DEFAULT 'aktif',
            location_text VARCHAR(180) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_production_work_centers_code (code)
        ) ENGINE=InnoDB
    ");

    if (!production_column_exists($db, 'production_order_operations', 'work_center_id')) {
        $db->exec("ALTER TABLE production_order_operations ADD COLUMN work_center_id BIGINT NULL AFTER production_order_id");
        $db->exec("ALTER TABLE production_order_operations ADD INDEX idx_production_order_operations_work_center (work_center_id)");
    }
}

function production_ensure_route_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_recipe_routes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            recipe_id BIGINT NOT NULL,
            work_center_id BIGINT NULL,
            sequence_no INT NOT NULL DEFAULT 1,
            operation_name VARCHAR(180) NOT NULL,
            setup_minutes INT NOT NULL DEFAULT 0,
            run_minutes INT NOT NULL DEFAULT 0,
            transfer_minutes INT NOT NULL DEFAULT 0,
            quality_check_required TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_recipe_routes_recipe (recipe_id),
            INDEX idx_production_recipe_routes_work_center (work_center_id),
            UNIQUE KEY uq_production_recipe_routes_step (recipe_id, sequence_no)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_schedule_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_schedule (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            work_center_id BIGINT NULL,
            planned_start DATETIME NOT NULL,
            planned_end DATETIME NOT NULL,
            priority ENUM('dusuk','normal','yuksek','kritik') NOT NULL DEFAULT 'normal',
            status ENUM('planlandi','onaylandi','ertelendi','tamamlandi','iptal') NOT NULL DEFAULT 'planlandi',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_schedule_order (production_order_id),
            INDEX idx_production_schedule_work_center (work_center_id),
            INDEX idx_production_schedule_dates (planned_start, planned_end)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_waste_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_waste_records (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            product_id BIGINT NULL,
            expected_quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
            actual_waste_quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
            waste_reason VARCHAR(150) NOT NULL,
            unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            waste_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            record_date DATE NOT NULL,
            responsible_name VARCHAR(150) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_waste_records_order (production_order_id),
            INDEX idx_production_waste_records_product (product_id),
            INDEX idx_production_waste_records_date (record_date)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_cost_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_cost_snapshots (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            material_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            operation_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            waste_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            overhead_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            output_quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
            unit_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            calculated_at DATETIME NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_cost_snapshots_order (production_order_id),
            INDEX idx_production_cost_snapshots_date (calculated_at)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_semi_finished_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_semi_finished_flows (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            from_work_center_id BIGINT NULL,
            to_work_center_id BIGINT NULL,
            quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
            flow_status ENUM('bekliyor','transferde','tamamlandi','blokeli') NOT NULL DEFAULT 'bekliyor',
            flow_date DATETIME NOT NULL,
            lot_no VARCHAR(120) NULL,
            responsible_name VARCHAR(150) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_semi_flows_order (production_order_id),
            INDEX idx_production_semi_flows_product (product_id),
            INDEX idx_production_semi_flows_status (flow_status),
            INDEX idx_production_semi_flows_date (flow_date)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_deadline_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_deadline_plans (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            target_start DATETIME NULL,
            target_finish DATETIME NOT NULL,
            promised_date DATE NULL,
            priority ENUM('dusuk','normal','yuksek','kritik') NOT NULL DEFAULT 'normal',
            risk_status ENUM('normal','riskli','gecikmede','tamamlandi') NOT NULL DEFAULT 'normal',
            buffer_days INT NOT NULL DEFAULT 0,
            responsible_name VARCHAR(150) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_deadline_order (production_order_id),
            INDEX idx_production_deadline_finish (target_finish),
            INDEX idx_production_deadline_risk (risk_status),
            INDEX idx_production_deadline_priority (priority)
        ) ENGINE=InnoDB
    ");
}

function production_ensure_approval_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS production_order_approvals (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            production_order_id BIGINT NOT NULL,
            approval_step VARCHAR(120) NOT NULL,
            approval_status ENUM('bekliyor','onaylandi','reddedildi','iptal') NOT NULL DEFAULT 'bekliyor',
            requested_by VARCHAR(150) NULL,
            approver_name VARCHAR(150) NULL,
            decision_note TEXT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_production_approvals_order (production_order_id),
            INDEX idx_production_approvals_status (approval_status),
            INDEX idx_production_approvals_step (approval_step),
            INDEX idx_production_approvals_requested (requested_at)
        ) ENGINE=InnoDB
    ");
}

function production_calculate_order_cost(PDO $db, int $orderId, float $overheadCost = 0.0): array
{
    $orderRows = app_fetch_all($db, '
        SELECT planned_quantity, actual_quantity
        FROM production_orders
        WHERE id = :id
        LIMIT 1
    ', ['id' => $orderId]);

    if (!$orderRows) {
        throw new RuntimeException('Maliyet hesabi icin gecerli uretim emri secilmedi.');
    }

    $materialCost = (float) app_metric($db, '
        SELECT COALESCE(SUM(c.quantity * COALESCE(p.purchase_price, 0)), 0)
        FROM production_consumptions c
        INNER JOIN stock_products p ON p.id = c.product_id
        WHERE c.production_order_id = :id
    ', ['id' => $orderId]);

    $operationCost = (float) app_metric($db, '
        SELECT COALESCE(SUM((CASE WHEN op.actual_minutes > 0 THEN op.actual_minutes ELSE op.planned_minutes END) / 60 * COALESCE(wc.hourly_cost, 0)), 0)
        FROM production_order_operations op
        LEFT JOIN production_work_centers wc ON wc.id = op.work_center_id
        WHERE op.production_order_id = :id
    ', ['id' => $orderId]);

    $wasteCost = (float) app_metric($db, '
        SELECT COALESCE(SUM(waste_cost), 0)
        FROM production_waste_records
        WHERE production_order_id = :id
    ', ['id' => $orderId]);

    $outputQuantity = (float) app_metric($db, '
        SELECT COALESCE(SUM(quantity), 0)
        FROM production_outputs
        WHERE production_order_id = :id
    ', ['id' => $orderId]);

    if ($outputQuantity <= 0) {
        $outputQuantity = (float) ($orderRows[0]['actual_quantity'] ?: $orderRows[0]['planned_quantity'] ?: 0);
    }

    $totalCost = round($materialCost + $operationCost + $wasteCost + $overheadCost, 2);
    $unitCost = $outputQuantity > 0 ? round($totalCost / $outputQuantity, 4) : 0.0;

    return [
        'material_cost' => round($materialCost, 2),
        'operation_cost' => round($operationCost, 2),
        'waste_cost' => round($wasteCost, 2),
        'overhead_cost' => round($overheadCost, 2),
        'total_cost' => $totalCost,
        'output_quantity' => round($outputQuantity, 3),
        'unit_cost' => $unitCost,
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

production_ensure_multilevel_schema($db);
production_ensure_operation_schema($db);
production_ensure_workcenter_schema($db);
production_ensure_route_schema($db);
production_ensure_schedule_schema($db);
production_ensure_waste_schema($db);
production_ensure_cost_schema($db);
production_ensure_semi_finished_schema($db);
production_ensure_deadline_schema($db);
production_ensure_approval_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_recipe') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $materialId = (int) ($_POST['material_product_id'] ?? 0);
            $materialQty = (float) ($_POST['material_quantity'] ?? 0);

            if ($productId <= 0 || $materialId <= 0 || $materialQty <= 0) {
                throw new RuntimeException('Mamul, hammadde ve miktar alanlari zorunludur.');
            }

            $recipeCode = production_next_code($db, 'production_recipes', 'REC');

            $stmt = $db->prepare('
                INSERT INTO production_recipes (product_id, recipe_code, version_no, status, output_quantity, notes)
                VALUES (:product_id, :recipe_code, :version_no, :status, :output_quantity, :notes)
            ');
            $stmt->execute([
                'product_id' => $productId,
                'recipe_code' => $recipeCode,
                'version_no' => trim((string) ($_POST['version_no'] ?? '1.0')) ?: '1.0',
                'status' => trim((string) ($_POST['status'] ?? 'taslak')) ?: 'taslak',
                'output_quantity' => (float) ($_POST['output_quantity'] ?? 1),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            $recipeId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO production_recipe_items (recipe_id, material_product_id, quantity, unit, wastage_rate)
                VALUES (:recipe_id, :material_product_id, :quantity, :unit, :wastage_rate)
            ');
            $stmt->execute([
                'recipe_id' => $recipeId,
                'material_product_id' => $materialId,
                'quantity' => $materialQty,
                'unit' => trim((string) ($_POST['unit'] ?? 'adet')) ?: 'adet',
                'wastage_rate' => (float) ($_POST['wastage_rate'] ?? 0),
            ]);

            app_audit_log('uretim', 'create_recipe', 'production_recipes', $recipeId, $recipeCode . ' recetesi olusturuldu.');
            production_redirect('recipe');
        }

        if ($action === 'add_subrecipe') {
            $parentRecipeId = (int) ($_POST['parent_recipe_id'] ?? 0);
            $childRecipeId = (int) ($_POST['child_recipe_id'] ?? 0);
            $levelNo = max(1, min(10, (int) ($_POST['level_no'] ?? 1)));
            $quantityMultiplier = (float) ($_POST['quantity_multiplier'] ?? 1);

            if ($parentRecipeId <= 0 || $childRecipeId <= 0 || $quantityMultiplier <= 0) {
                throw new RuntimeException('Ana recete, alt recete ve miktar carpani zorunludur.');
            }

            if ($parentRecipeId === $childRecipeId) {
                throw new RuntimeException('Recete kendi alt recetesi olamaz.');
            }

            $reverseExists = (int) app_metric($db, '
                SELECT COUNT(*)
                FROM production_recipe_subrecipes
                WHERE parent_recipe_id = :child_recipe_id AND child_recipe_id = :parent_recipe_id
            ', [
                'child_recipe_id' => $childRecipeId,
                'parent_recipe_id' => $parentRecipeId,
            ]);

            if ($reverseExists > 0) {
                throw new RuntimeException('Ters iliski mevcut oldugu icin recete dongusu olusabilir.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_recipe_subrecipes (
                    parent_recipe_id, child_recipe_id, level_no, quantity_multiplier, is_required, operation_note
                ) VALUES (
                    :parent_recipe_id, :child_recipe_id, :level_no, :quantity_multiplier, :is_required, :operation_note
                )
                ON DUPLICATE KEY UPDATE
                    level_no = VALUES(level_no),
                    quantity_multiplier = VALUES(quantity_multiplier),
                    is_required = VALUES(is_required),
                    operation_note = VALUES(operation_note)
            ');
            $stmt->execute([
                'parent_recipe_id' => $parentRecipeId,
                'child_recipe_id' => $childRecipeId,
                'level_no' => $levelNo,
                'quantity_multiplier' => $quantityMultiplier,
                'is_required' => isset($_POST['is_required']) ? 1 : 0,
                'operation_note' => trim((string) ($_POST['operation_note'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'add_subrecipe', 'production_recipe_subrecipes', (int) $db->lastInsertId(), 'Cok seviyeli recete baglantisi kaydedildi.');
            production_redirect('subrecipe');
        }

        if ($action === 'create_order') {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $plannedQty = (float) ($_POST['planned_quantity'] ?? 0);

            if ($recipeId <= 0 || $plannedQty <= 0) {
                throw new RuntimeException('Recete ve planlanan miktar zorunludur.');
            }

            $orderNo = production_next_code($db, 'production_orders', 'URE');
            $status = trim((string) ($_POST['status'] ?? 'planlandi')) ?: 'planlandi';

            $stmt = $db->prepare('
                INSERT INTO production_orders (
                    branch_id, recipe_id, order_no, planned_quantity, actual_quantity, batch_no, status, started_at, finished_at
                ) VALUES (
                    :branch_id, :recipe_id, :order_no, :planned_quantity, :actual_quantity, :batch_no, :status, :started_at, :finished_at
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'recipe_id' => $recipeId,
                'order_no' => $orderNo,
                'planned_quantity' => $plannedQty,
                'actual_quantity' => null,
                'batch_no' => trim((string) ($_POST['batch_no'] ?? '')) ?: null,
                'status' => $status,
                'started_at' => $status === 'uretimde' ? date('Y-m-d H:i:s') : null,
                'finished_at' => $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null,
            ]);

            app_audit_log('uretim', 'create_order', 'production_orders', (int) $db->lastInsertId(), $orderNo . ' uretim emri olusturuldu.');
            production_redirect('order');
        }

        if ($action === 'create_order_operation') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $workCenterId = (int) ($_POST['work_center_id'] ?? 0);
            $operationName = trim((string) ($_POST['operation_name'] ?? ''));
            $sequenceNo = max(1, (int) ($_POST['sequence_no'] ?? 1));
            $status = trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor';
            $allowed = ['bekliyor', 'basladi', 'tamamlandi', 'atlandi'];

            if ($orderId <= 0 || $operationName === '' || !in_array($status, $allowed, true)) {
                throw new RuntimeException('Operasyon icin uretim emri, ad ve durum zorunludur.');
            }

            $startedAt = trim((string) ($_POST['started_at'] ?? ''));
            $finishedAt = trim((string) ($_POST['finished_at'] ?? ''));
            if ($status === 'basladi' && $startedAt === '') {
                $startedAt = date('Y-m-d\TH:i');
            }
            if ($status === 'tamamlandi') {
                if ($startedAt === '') {
                    $startedAt = date('Y-m-d\TH:i');
                }
                if ($finishedAt === '') {
                    $finishedAt = date('Y-m-d\TH:i');
                }
            }

            $stmt = $db->prepare('
                INSERT INTO production_order_operations (
                    production_order_id, work_center_id, sequence_no, operation_name, planned_minutes, actual_minutes, status,
                    responsible_name, started_at, finished_at, notes
                ) VALUES (
                    :production_order_id, :work_center_id, :sequence_no, :operation_name, :planned_minutes, :actual_minutes, :status,
                    :responsible_name, :started_at, :finished_at, :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'work_center_id' => $workCenterId > 0 ? $workCenterId : null,
                'sequence_no' => $sequenceNo,
                'operation_name' => $operationName,
                'planned_minutes' => max(0, (int) ($_POST['planned_minutes'] ?? 0)),
                'actual_minutes' => max(0, (int) ($_POST['actual_minutes'] ?? 0)),
                'status' => $status,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'started_at' => $startedAt !== '' ? str_replace('T', ' ', $startedAt) . (strlen($startedAt) === 16 ? ':00' : '') : null,
                'finished_at' => $finishedAt !== '' ? str_replace('T', ' ', $finishedAt) . (strlen($finishedAt) === 16 ? ':00' : '') : null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            if ($status === 'basladi') {
                $stmt = $db->prepare('UPDATE production_orders SET status = :status, started_at = COALESCE(started_at, :started_at) WHERE id = :id');
                $stmt->execute([
                    'status' => 'uretimde',
                    'started_at' => date('Y-m-d H:i:s'),
                    'id' => $orderId,
                ]);
            }

            app_audit_log('uretim', 'create_order_operation', 'production_order_operations', (int) $db->lastInsertId(), 'Uretim operasyonu kaydedildi: ' . $operationName);
            production_redirect('operation');
        }

        if ($action === 'create_work_center') {
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'aktif')) ?: 'aktif';
            $allowed = ['aktif', 'bakimda', 'pasif'];

            if ($code === '' || $name === '' || !in_array($status, $allowed, true)) {
                throw new RuntimeException('Is merkezi kodu, adi ve durumu zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_work_centers (
                    code, name, station_type, responsible_name, hourly_capacity, hourly_cost, status, location_text, notes
                ) VALUES (
                    :code, :name, :station_type, :responsible_name, :hourly_capacity, :hourly_cost, :status, :location_text, :notes
                )
            ');
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'station_type' => trim((string) ($_POST['station_type'] ?? '')) ?: null,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'hourly_capacity' => (float) ($_POST['hourly_capacity'] ?? 0),
                'hourly_cost' => (float) ($_POST['hourly_cost'] ?? 0),
                'status' => $status,
                'location_text' => trim((string) ($_POST['location_text'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_work_center', 'production_work_centers', (int) $db->lastInsertId(), $code . ' is merkezi kaydedildi.');
            production_redirect('work_center');
        }

        if ($action === 'create_recipe_route') {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $workCenterId = (int) ($_POST['work_center_id'] ?? 0);
            $sequenceNo = max(1, (int) ($_POST['sequence_no'] ?? 1));
            $operationName = trim((string) ($_POST['operation_name'] ?? ''));

            if ($recipeId <= 0 || $operationName === '') {
                throw new RuntimeException('Rota icin recete ve operasyon adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_recipe_routes (
                    recipe_id, work_center_id, sequence_no, operation_name, setup_minutes, run_minutes,
                    transfer_minutes, quality_check_required, notes
                ) VALUES (
                    :recipe_id, :work_center_id, :sequence_no, :operation_name, :setup_minutes, :run_minutes,
                    :transfer_minutes, :quality_check_required, :notes
                )
                ON DUPLICATE KEY UPDATE
                    work_center_id = VALUES(work_center_id),
                    operation_name = VALUES(operation_name),
                    setup_minutes = VALUES(setup_minutes),
                    run_minutes = VALUES(run_minutes),
                    transfer_minutes = VALUES(transfer_minutes),
                    quality_check_required = VALUES(quality_check_required),
                    notes = VALUES(notes)
            ');
            $stmt->execute([
                'recipe_id' => $recipeId,
                'work_center_id' => $workCenterId > 0 ? $workCenterId : null,
                'sequence_no' => $sequenceNo,
                'operation_name' => $operationName,
                'setup_minutes' => max(0, (int) ($_POST['setup_minutes'] ?? 0)),
                'run_minutes' => max(0, (int) ($_POST['run_minutes'] ?? 0)),
                'transfer_minutes' => max(0, (int) ($_POST['transfer_minutes'] ?? 0)),
                'quality_check_required' => isset($_POST['quality_check_required']) ? 1 : 0,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_recipe_route', 'production_recipe_routes', (int) $db->lastInsertId(), 'Uretim rotasi kaydedildi: ' . $operationName);
            production_redirect('route');
        }

        if ($action === 'create_production_schedule') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $workCenterId = (int) ($_POST['work_center_id'] ?? 0);
            $plannedStart = trim((string) ($_POST['planned_start'] ?? ''));
            $plannedEnd = trim((string) ($_POST['planned_end'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'normal')) ?: 'normal';
            $status = trim((string) ($_POST['status'] ?? 'planlandi')) ?: 'planlandi';
            $allowedPriority = ['dusuk', 'normal', 'yuksek', 'kritik'];
            $allowedStatus = ['planlandi', 'onaylandi', 'ertelendi', 'tamamlandi', 'iptal'];

            if ($orderId <= 0 || $plannedStart === '' || $plannedEnd === '' || !in_array($priority, $allowedPriority, true) || !in_array($status, $allowedStatus, true)) {
                throw new RuntimeException('Planlama icin emir, tarih ve durum alanlari zorunludur.');
            }

            $startSql = str_replace('T', ' ', $plannedStart) . (strlen($plannedStart) === 16 ? ':00' : '');
            $endSql = str_replace('T', ' ', $plannedEnd) . (strlen($plannedEnd) === 16 ? ':00' : '');
            if (strtotime($endSql) <= strtotime($startSql)) {
                throw new RuntimeException('Plan bitisi baslangictan sonra olmalidir.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_schedule (
                    production_order_id, work_center_id, planned_start, planned_end, priority, status, notes
                ) VALUES (
                    :production_order_id, :work_center_id, :planned_start, :planned_end, :priority, :status, :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'work_center_id' => $workCenterId > 0 ? $workCenterId : null,
                'planned_start' => $startSql,
                'planned_end' => $endSql,
                'priority' => $priority,
                'status' => $status,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_schedule', 'production_schedule', (int) $db->lastInsertId(), 'Uretim planlama takvimi kaydi olusturuldu.');
            production_redirect('schedule');
        }

        if ($action === 'create_waste_record') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $expectedQuantity = (float) ($_POST['expected_quantity'] ?? 0);
            $actualWasteQuantity = (float) ($_POST['actual_waste_quantity'] ?? 0);
            $wasteReason = trim((string) ($_POST['waste_reason'] ?? ''));
            $unitCost = (float) ($_POST['unit_cost'] ?? 0);

            if ($orderId <= 0 || $actualWasteQuantity <= 0 || $wasteReason === '') {
                throw new RuntimeException('Fire icin uretim emri, fire miktari ve sebep zorunludur.');
            }

            if ($unitCost <= 0 && $productId > 0) {
                $productRows = app_fetch_all($db, 'SELECT purchase_price FROM stock_products WHERE id = :id LIMIT 1', ['id' => $productId]);
                $unitCost = $productRows ? (float) ($productRows[0]['purchase_price'] ?? 0) : 0.0;
            }

            $wasteCost = round($actualWasteQuantity * $unitCost, 2);
            $stmt = $db->prepare('
                INSERT INTO production_waste_records (
                    production_order_id, product_id, expected_quantity, actual_waste_quantity, waste_reason,
                    unit_cost, waste_cost, record_date, responsible_name, notes
                ) VALUES (
                    :production_order_id, :product_id, :expected_quantity, :actual_waste_quantity, :waste_reason,
                    :unit_cost, :waste_cost, :record_date, :responsible_name, :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'product_id' => $productId > 0 ? $productId : null,
                'expected_quantity' => $expectedQuantity,
                'actual_waste_quantity' => $actualWasteQuantity,
                'waste_reason' => $wasteReason,
                'unit_cost' => $unitCost,
                'waste_cost' => $wasteCost,
                'record_date' => trim((string) ($_POST['record_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_waste_record', 'production_waste_records', (int) $db->lastInsertId(), 'Uretim fire kaydi olusturuldu: ' . $wasteReason);
            production_redirect('waste');
        }

        if ($action === 'calculate_production_cost') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $overheadCost = (float) ($_POST['overhead_cost'] ?? 0);

            if ($orderId <= 0) {
                throw new RuntimeException('Maliyet hesabi icin uretim emri secilmelidir.');
            }

            $cost = production_calculate_order_cost($db, $orderId, $overheadCost);
            $stmt = $db->prepare('
                INSERT INTO production_cost_snapshots (
                    production_order_id, material_cost, operation_cost, waste_cost, overhead_cost,
                    total_cost, output_quantity, unit_cost, calculated_at, notes
                ) VALUES (
                    :production_order_id, :material_cost, :operation_cost, :waste_cost, :overhead_cost,
                    :total_cost, :output_quantity, :unit_cost, NOW(), :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'material_cost' => $cost['material_cost'],
                'operation_cost' => $cost['operation_cost'],
                'waste_cost' => $cost['waste_cost'],
                'overhead_cost' => $cost['overhead_cost'],
                'total_cost' => $cost['total_cost'],
                'output_quantity' => $cost['output_quantity'],
                'unit_cost' => $cost['unit_cost'],
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'calculate_production_cost', 'production_cost_snapshots', (int) $db->lastInsertId(), 'Uretim maliyeti hesaplandi.');
            production_redirect('cost');
        }

        if ($action === 'create_semi_finished_flow') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $fromWorkCenterId = (int) ($_POST['from_work_center_id'] ?? 0);
            $toWorkCenterId = (int) ($_POST['to_work_center_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $flowStatus = trim((string) ($_POST['flow_status'] ?? 'bekliyor')) ?: 'bekliyor';
            $allowedStatus = ['bekliyor', 'transferde', 'tamamlandi', 'blokeli'];

            if ($orderId <= 0 || $productId <= 0 || $quantity <= 0 || !in_array($flowStatus, $allowedStatus, true)) {
                throw new RuntimeException('Yari mamul akisi icin emir, urun, miktar ve durum zorunludur.');
            }

            $flowDate = trim((string) ($_POST['flow_date'] ?? date('Y-m-d\TH:i')));
            $flowDateSql = str_replace('T', ' ', $flowDate) . (strlen($flowDate) === 16 ? ':00' : '');

            $stmt = $db->prepare('
                INSERT INTO production_semi_finished_flows (
                    production_order_id, product_id, from_work_center_id, to_work_center_id, quantity,
                    flow_status, flow_date, lot_no, responsible_name, notes
                ) VALUES (
                    :production_order_id, :product_id, :from_work_center_id, :to_work_center_id, :quantity,
                    :flow_status, :flow_date, :lot_no, :responsible_name, :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'product_id' => $productId,
                'from_work_center_id' => $fromWorkCenterId > 0 ? $fromWorkCenterId : null,
                'to_work_center_id' => $toWorkCenterId > 0 ? $toWorkCenterId : null,
                'quantity' => $quantity,
                'flow_status' => $flowStatus,
                'flow_date' => $flowDateSql,
                'lot_no' => trim((string) ($_POST['lot_no'] ?? '')) ?: null,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_semi_finished_flow', 'production_semi_finished_flows', (int) $db->lastInsertId(), 'Yari mamul akisi kaydedildi.');
            production_redirect('semi_finished');
        }

        if ($action === 'create_deadline_plan') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $targetFinish = trim((string) ($_POST['target_finish'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'normal')) ?: 'normal';
            $riskStatus = trim((string) ($_POST['risk_status'] ?? 'normal')) ?: 'normal';
            $allowedPriority = ['dusuk', 'normal', 'yuksek', 'kritik'];
            $allowedRisk = ['normal', 'riskli', 'gecikmede', 'tamamlandi'];

            if ($orderId <= 0 || $targetFinish === '' || !in_array($priority, $allowedPriority, true) || !in_array($riskStatus, $allowedRisk, true)) {
                throw new RuntimeException('Termin planlama icin emir, hedef bitis, oncelik ve risk durumu zorunludur.');
            }

            $targetStart = trim((string) ($_POST['target_start'] ?? ''));
            $targetStartSql = $targetStart !== '' ? str_replace('T', ' ', $targetStart) . (strlen($targetStart) === 16 ? ':00' : '') : null;
            $targetFinishSql = str_replace('T', ' ', $targetFinish) . (strlen($targetFinish) === 16 ? ':00' : '');

            if ($targetStartSql !== null && strtotime($targetFinishSql) <= strtotime($targetStartSql)) {
                throw new RuntimeException('Termin bitisi baslangictan sonra olmalidir.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_deadline_plans (
                    production_order_id, target_start, target_finish, promised_date, priority,
                    risk_status, buffer_days, responsible_name, notes
                ) VALUES (
                    :production_order_id, :target_start, :target_finish, :promised_date, :priority,
                    :risk_status, :buffer_days, :responsible_name, :notes
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'target_start' => $targetStartSql,
                'target_finish' => $targetFinishSql,
                'promised_date' => trim((string) ($_POST['promised_date'] ?? '')) ?: null,
                'priority' => $priority,
                'risk_status' => $riskStatus,
                'buffer_days' => max(0, (int) ($_POST['buffer_days'] ?? 0)),
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_deadline_plan', 'production_deadline_plans', (int) $db->lastInsertId(), 'Uretim termin plani kaydedildi.');
            production_redirect('deadline');
        }

        if ($action === 'create_order_approval') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $approvalStep = trim((string) ($_POST['approval_step'] ?? ''));

            if ($orderId <= 0 || $approvalStep === '') {
                throw new RuntimeException('Onay akisi icin uretim emri ve onay adimi zorunludur.');
            }

            $openApproval = (int) app_metric($db, "
                SELECT COUNT(*)
                FROM production_order_approvals
                WHERE production_order_id = :production_order_id AND approval_step = :approval_step AND approval_status = 'bekliyor'
            ", [
                'production_order_id' => $orderId,
                'approval_step' => $approvalStep,
            ]);

            if ($openApproval > 0) {
                throw new RuntimeException('Bu emir icin ayni adimda bekleyen onay talebi zaten var.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_order_approvals (
                    production_order_id, approval_step, approval_status, requested_by, approver_name, decision_note
                ) VALUES (
                    :production_order_id, :approval_step, :approval_status, :requested_by, :approver_name, :decision_note
                )
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'approval_step' => $approvalStep,
                'approval_status' => 'bekliyor',
                'requested_by' => trim((string) ($_POST['requested_by'] ?? ($authUser['full_name'] ?? ''))) ?: null,
                'approver_name' => trim((string) ($_POST['approver_name'] ?? '')) ?: null,
                'decision_note' => trim((string) ($_POST['decision_note'] ?? '')) ?: null,
            ]);

            app_audit_log('uretim', 'create_order_approval', 'production_order_approvals', (int) $db->lastInsertId(), 'Uretim emri onay talebi olusturuldu.');
            production_redirect('approval');
        }

        if ($action === 'decide_order_approval') {
            $approvalId = (int) ($_POST['approval_id'] ?? 0);
            $decision = trim((string) ($_POST['approval_status'] ?? ''));
            $allowedDecision = ['onaylandi', 'reddedildi', 'iptal'];

            if ($approvalId <= 0 || !in_array($decision, $allowedDecision, true)) {
                throw new RuntimeException('Onay karari icin gecerli kayit ve karar secilmelidir.');
            }

            $approvalRows = app_fetch_all($db, '
                SELECT production_order_id, approval_status
                FROM production_order_approvals
                WHERE id = :id
                LIMIT 1
            ', ['id' => $approvalId]);

            if (!$approvalRows || $approvalRows[0]['approval_status'] !== 'bekliyor') {
                throw new RuntimeException('Sadece bekleyen onay talepleri karara baglanabilir.');
            }

            if ($decision === 'onaylandi') {
                $rule = app_approval_rule('production.order_decision');
                $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));

                if (app_approval_rule_matches($rule)) {
                    if (!app_approval_rule_user_allowed($authUser, $rule)) {
                        $requiredRole = (string) ($rule['approver_role_code'] ?? '');
                        throw new RuntimeException('Bu uretim onayi yalnizca ' . ($requiredRole !== '' ? $requiredRole : 'yetkili rol') . ' tarafindan verilebilir.');
                    }

                    if (!empty($rule['require_note']) && $decisionNote === '') {
                        throw new RuntimeException('Bu uretim onayi icin karar notu zorunludur.');
                    }
                }
            }

            $stmt = $db->prepare('
                UPDATE production_order_approvals
                SET approval_status = :approval_status,
                    approver_name = :approver_name,
                    decision_note = :decision_note,
                    decided_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                'approval_status' => $decision,
                'approver_name' => trim((string) ($_POST['approver_name'] ?? ($authUser['full_name'] ?? ''))) ?: null,
                'decision_note' => trim((string) ($_POST['decision_note'] ?? '')) ?: null,
                'id' => $approvalId,
            ]);

            if ($decision === 'onaylandi') {
                $stmt = $db->prepare("
                    UPDATE production_orders
                    SET status = CASE WHEN status = 'planlandi' THEN 'uretimde' ELSE status END,
                        started_at = CASE WHEN status = 'planlandi' THEN COALESCE(started_at, NOW()) ELSE started_at END
                    WHERE id = :id
                ");
                $stmt->execute(['id' => (int) $approvalRows[0]['production_order_id']]);
            }

            app_audit_log('uretim', 'decide_order_approval', 'production_order_approvals', $approvalId, 'Uretim emri onay karari: ' . $decision);
            production_redirect('approval_decision');
        }

        if ($action === 'update_recipe_status') {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'taslak')) ?: 'taslak';

            if ($recipeId <= 0) {
                throw new RuntimeException('Gecerli recete secilmedi.');
            }

            $allowed = ['taslak', 'onayli', 'pasif'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Recete durumu gecersiz.');
            }

            $stmt = $db->prepare('UPDATE production_recipes SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'id' => $recipeId,
            ]);

            app_audit_log('uretim', 'update_recipe_status', 'production_recipes', $recipeId, 'Recete durumu guncellendi: ' . $status);
            production_redirect('recipe_status');
        }

        if ($action === 'bulk_update_recipe_status') {
            $recipeIds = production_selected_ids('recipe_ids');
            $status = trim((string) ($_POST['bulk_recipe_status'] ?? ''));
            $allowed = ['taslak', 'onayli', 'pasif'];

            if ($recipeIds === [] || !in_array($status, $allowed, true)) {
                throw new RuntimeException('Recete secimi veya durum gecersiz.');
            }

            $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
            $params = array_merge([$status], $recipeIds);
            $stmt = $db->prepare("UPDATE production_recipes SET status = ? WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            production_redirect('recipe_bulk_status');
        }

        if ($action === 'update_order_status') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'planlandi')) ?: 'planlandi';
            $actualQuantity = (float) ($_POST['actual_quantity'] ?? 0);

            if ($orderId <= 0) {
                throw new RuntimeException('Gecerli uretim emri secilmedi.');
            }
            app_assert_branch_access($db, 'production_orders', $orderId);

            $allowed = ['planlandi', 'uretimde', 'tamamlandi', 'iptal'];
            if (!in_array($status, $allowed, true)) {
                throw new RuntimeException('Uretim emri durumu gecersiz.');
            }

            $postedOutputs = (int) app_metric($db, "
                SELECT COUNT(*) FROM stock_movements
                WHERE reference_type = 'uretim_cikti' AND reference_id = :reference_id
            ", ['reference_id' => $orderId]) > 0;

            if ($postedOutputs && $status !== 'tamamlandi') {
                throw new RuntimeException('Stok ciktilari islenmis emir geri durumlara cekilemez.');
            }

            $startedAt = null;
            $finishedAt = null;
            if ($status === 'uretimde') {
                $startedAt = date('Y-m-d H:i:s');
            }
            if ($status === 'tamamlandi') {
                $finishedAt = date('Y-m-d H:i:s');
            }

            $stmt = $db->prepare('
                UPDATE production_orders
                SET status = :status,
                    actual_quantity = :actual_quantity,
                    started_at = COALESCE(started_at, :started_at),
                    finished_at = :finished_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => $status,
                'actual_quantity' => $actualQuantity > 0 ? $actualQuantity : null,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'id' => $orderId,
            ]);

            app_audit_log('uretim', 'update_order_status', 'production_orders', $orderId, 'Uretim emri durumu guncellendi: ' . $status);
            production_redirect('order_status');
        }

        if ($action === 'bulk_update_order_status') {
            $orderIds = production_selected_ids('order_ids');
            $status = trim((string) ($_POST['bulk_order_status'] ?? ''));
            $allowed = ['planlandi', 'uretimde', 'tamamlandi', 'iptal'];

            if ($orderIds === [] || !in_array($status, $allowed, true)) {
                throw new RuntimeException('Uretim emri secimi veya durum gecersiz.');
            }

            foreach ($orderIds as $orderId) {
                app_assert_branch_access($db, 'production_orders', $orderId);
                $postedOutputs = (int) app_metric($db, "
                    SELECT COUNT(*) FROM stock_movements
                    WHERE reference_type = 'uretim_cikti' AND reference_id = :reference_id
                ", ['reference_id' => $orderId]) > 0;

                if ($postedOutputs && $status !== 'tamamlandi') {
                    continue;
                }

                $startedAt = $status === 'uretimde' ? date('Y-m-d H:i:s') : null;
                $finishedAt = $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null;

                $stmt = $db->prepare('
                    UPDATE production_orders
                    SET status = :status,
                        started_at = COALESCE(started_at, :started_at),
                        finished_at = CASE WHEN :finished_at IS NULL THEN finished_at ELSE :finished_at END
                    WHERE id = :id
                ');
                $stmt->execute([
                    'status' => $status,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'id' => $orderId,
                ]);
            }

            production_redirect('order_bulk_status');
        }

        if ($action === 'create_consumption') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);

            if ($orderId <= 0 || $productId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Uretim emri, urun, depo ve miktar zorunludur.');
            }

            $existing = (int) app_metric($db, "
                SELECT COUNT(*) FROM production_consumptions
                WHERE production_order_id = :production_order_id AND product_id = :product_id AND warehouse_id = :warehouse_id AND quantity = :quantity
            ", [
                'production_order_id' => $orderId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
            ]);

            if ($existing > 0) {
                throw new RuntimeException('Ayni sarf kaydi zaten mevcut.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_consumptions (production_order_id, product_id, warehouse_id, quantity)
                VALUES (:production_order_id, :product_id, :warehouse_id, :quantity)
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
            ]);

            $costRows = app_fetch_all($db, 'SELECT purchase_price FROM stock_products WHERE id = :id LIMIT 1', ['id' => $productId]);
            $unitCost = $costRows ? (float) ($costRows[0]['purchase_price'] ?? 0) : 0.0;

            app_insert_stock_movement($db, [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => 'uretim_cikis',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => 'uretim_sarf',
                'reference_id' => $orderId,
                'movement_date' => date('Y-m-d H:i:s'),
            ]);

            app_audit_log('uretim', 'create_consumption', 'production_consumptions', (int) $db->lastInsertId(), 'Uretim sarf kaydi olusturuldu.');
            production_redirect('consumption');
        }

        if ($action === 'create_output') {
            $orderId = (int) ($_POST['production_order_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);

            if ($orderId <= 0 || $productId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Uretim emri, urun, depo ve miktar zorunludur.');
            }

            $existing = (int) app_metric($db, "
                SELECT COUNT(*) FROM production_outputs
                WHERE production_order_id = :production_order_id AND product_id = :product_id AND warehouse_id = :warehouse_id AND quantity = :quantity
            ", [
                'production_order_id' => $orderId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
            ]);

            if ($existing > 0) {
                throw new RuntimeException('Ayni uretim ciktisi zaten mevcut.');
            }

            $stmt = $db->prepare('
                INSERT INTO production_outputs (production_order_id, product_id, warehouse_id, quantity, barcode)
                VALUES (:production_order_id, :product_id, :warehouse_id, :quantity, :barcode)
            ');
            $stmt->execute([
                'production_order_id' => $orderId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'barcode' => trim((string) ($_POST['barcode'] ?? '')) ?: null,
            ]);

            app_insert_stock_movement($db, [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'movement_type' => 'uretim_giris',
                'quantity' => $quantity,
                'unit_cost' => 0,
                'reference_type' => 'uretim_cikti',
                'reference_id' => $orderId,
                'movement_date' => date('Y-m-d H:i:s'),
            ]);

            $stmt = $db->prepare('
                UPDATE production_orders
                SET actual_quantity = :actual_quantity,
                    status = :status,
                    finished_at = COALESCE(finished_at, :finished_at),
                    started_at = COALESCE(started_at, :started_at)
                WHERE id = :id
            ');
            $stmt->execute([
                'actual_quantity' => $quantity,
                'status' => 'tamamlandi',
                'finished_at' => date('Y-m-d H:i:s'),
                'started_at' => date('Y-m-d H:i:s'),
                'id' => $orderId,
            ]);

            app_audit_log('uretim', 'create_output', 'production_outputs', (int) $db->lastInsertId(), 'Uretim ciktisi olusturuldu.');
            production_redirect('output');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Uretim islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = production_build_filters();

$products = app_fetch_all($db, 'SELECT id, sku, name, product_type, purchase_price FROM stock_products ORDER BY id DESC LIMIT 200');
[$productionWarehouseScopeWhere, $productionWarehouseScopeParams] = app_branch_scope_filter($db, null);
[$productionOrderScopeWhere, $productionOrderScopeParams] = app_branch_scope_filter($db, null, 'o');
$warehouses = app_fetch_all($db, 'SELECT id, name FROM stock_warehouses ' . ($productionWarehouseScopeWhere !== '' ? 'WHERE ' . $productionWarehouseScopeWhere : '') . ' ORDER BY id DESC LIMIT 100', $productionWarehouseScopeParams);
$workCenters = app_fetch_all($db, '
    SELECT id, code, name, station_type, responsible_name, hourly_capacity, hourly_cost, status, location_text, notes
    FROM production_work_centers
    ORDER BY id DESC
    LIMIT 100
');
$recipeWhere = [];
$recipeParams = [];

if ($filters['search'] !== '') {
    $recipeWhere[] = '(r.recipe_code LIKE :recipe_search OR p.name LIKE :recipe_search OR p.sku LIKE :recipe_search)';
    $recipeParams['recipe_search'] = '%' . $filters['search'] . '%';
}

if ($filters['recipe_status'] !== '') {
    $recipeWhere[] = 'r.status = :recipe_status';
    $recipeParams['recipe_status'] = $filters['recipe_status'];
}

$recipeWhereSql = $recipeWhere ? 'WHERE ' . implode(' AND ', $recipeWhere) : '';

$recipes = app_fetch_all($db, '
    SELECT r.id, r.recipe_code, r.version_no, r.status, r.output_quantity, r.notes, p.name AS product_name, p.sku
    FROM production_recipes r
    INNER JOIN stock_products p ON p.id = r.product_id
    ' . $recipeWhereSql . '
    ORDER BY r.id DESC
    LIMIT 50
', $recipeParams);
$recipeItems = app_fetch_all($db, '
    SELECT i.quantity, i.unit, i.wastage_rate, r.recipe_code, p.name AS material_name
    FROM production_recipe_items i
    INNER JOIN production_recipes r ON r.id = i.recipe_id
    INNER JOIN stock_products rp ON rp.id = r.product_id
    INNER JOIN stock_products p ON p.id = i.material_product_id
    ' . ($recipeWhere ? $recipeWhereSql : '') . ($filters['search'] !== '' ? ($recipeWhere ? ' AND ' : ' WHERE ') . 'p.name LIKE :recipe_item_search' : '') . '
    ORDER BY i.id DESC
    LIMIT 50
', $filters['search'] !== '' ? array_merge($recipeParams, ['recipe_item_search' => '%' . $filters['search'] . '%']) : $recipeParams);
$subrecipes = app_fetch_all($db, '
    SELECT s.id, s.level_no, s.quantity_multiplier, s.is_required, s.operation_note,
           parent.recipe_code AS parent_recipe_code, parent_product.name AS parent_product_name,
           child.recipe_code AS child_recipe_code, child_product.name AS child_product_name
    FROM production_recipe_subrecipes s
    INNER JOIN production_recipes parent ON parent.id = s.parent_recipe_id
    INNER JOIN stock_products parent_product ON parent_product.id = parent.product_id
    INNER JOIN production_recipes child ON child.id = s.child_recipe_id
    INNER JOIN stock_products child_product ON child_product.id = child.product_id
    ORDER BY parent.recipe_code ASC, s.level_no ASC, child.recipe_code ASC
    LIMIT 80
');
$multilevelSummary = app_fetch_all($db, '
    SELECT parent.recipe_code, parent_product.name AS product_name,
           COUNT(s.id) AS subrecipe_count,
           MAX(s.level_no) AS max_level
    FROM production_recipes parent
    INNER JOIN stock_products parent_product ON parent_product.id = parent.product_id
    INNER JOIN production_recipe_subrecipes s ON s.parent_recipe_id = parent.id
    GROUP BY parent.id, parent.recipe_code, parent_product.name
    ORDER BY subrecipe_count DESC, parent.recipe_code ASC
    LIMIT 50
');
$recipeRoutes = app_fetch_all($db, '
    SELECT rr.id, rr.sequence_no, rr.operation_name, rr.setup_minutes, rr.run_minutes, rr.transfer_minutes,
           rr.quality_check_required, rr.notes,
           r.recipe_code, p.name AS product_name,
           wc.code AS work_center_code, wc.name AS work_center_name
    FROM production_recipe_routes rr
    INNER JOIN production_recipes r ON r.id = rr.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN production_work_centers wc ON wc.id = rr.work_center_id
    ORDER BY r.recipe_code ASC, rr.sequence_no ASC
    LIMIT 100
');
$routeSummary = app_fetch_all($db, '
    SELECT r.recipe_code, p.name AS product_name,
           COUNT(rr.id) AS route_step_count,
           COALESCE(SUM(rr.setup_minutes + rr.run_minutes + rr.transfer_minutes), 0) AS total_minutes
    FROM production_recipe_routes rr
    INNER JOIN production_recipes r ON r.id = rr.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    GROUP BY r.id, r.recipe_code, p.name
    ORDER BY total_minutes DESC, r.recipe_code ASC
    LIMIT 50
');

$orderWhere = [];
$orderParams = [];

if ($filters['search'] !== '') {
    $orderWhere[] = '(o.order_no LIKE :order_search OR o.batch_no LIKE :order_search OR p.name LIKE :order_search OR r.recipe_code LIKE :order_search)';
    $orderParams['order_search'] = '%' . $filters['search'] . '%';
}

if ($filters['order_status'] !== '') {
    $orderWhere[] = 'o.status = :order_status';
    $orderParams['order_status'] = $filters['order_status'];
}
if ($productionOrderScopeWhere !== '') {
    $orderWhere[] = $productionOrderScopeWhere;
    $orderParams = array_merge($orderParams, $productionOrderScopeParams);
}

$orderWhereSql = $orderWhere ? 'WHERE ' . implode(' AND ', $orderWhere) : '';

$orders = app_fetch_all($db, '
    SELECT o.id, o.order_no, o.planned_quantity, o.actual_quantity, o.batch_no, o.status, o.started_at, o.finished_at, r.recipe_code, p.name AS product_name
    FROM production_orders o
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    ' . $orderWhereSql . '
    ORDER BY o.id DESC
    LIMIT 50
', $orderParams);
$operations = app_fetch_all($db, '
    SELECT op.id, op.sequence_no, op.operation_name, op.planned_minutes, op.actual_minutes, op.status,
           op.responsible_name, op.started_at, op.finished_at, op.notes,
           o.order_no, p.name AS product_name,
           wc.code AS work_center_code, wc.name AS work_center_name
    FROM production_order_operations op
    INNER JOIN production_orders o ON o.id = op.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN production_work_centers wc ON wc.id = op.work_center_id
    ORDER BY op.id DESC
    LIMIT 80
');
$operationSummary = app_fetch_all($db, '
    SELECT status, COUNT(*) AS operation_count, COALESCE(SUM(planned_minutes), 0) AS planned_minutes, COALESCE(SUM(actual_minutes), 0) AS actual_minutes
    FROM production_order_operations
    GROUP BY status
    ORDER BY status ASC
');
$workCenterLoad = app_fetch_all($db, '
    SELECT wc.code, wc.name, wc.status, wc.hourly_capacity, wc.hourly_cost,
           COUNT(op.id) AS operation_count,
           COALESCE(SUM(op.planned_minutes), 0) AS planned_minutes,
           COALESCE(SUM(op.actual_minutes), 0) AS actual_minutes
    FROM production_work_centers wc
    LEFT JOIN production_order_operations op ON op.work_center_id = wc.id
    GROUP BY wc.id, wc.code, wc.name, wc.status, wc.hourly_capacity, wc.hourly_cost
    ORDER BY operation_count DESC, wc.code ASC
    LIMIT 100
');
$scheduleRows = app_fetch_all($db, '
    SELECT s.id, s.planned_start, s.planned_end, s.priority, s.status, s.notes,
           o.order_no, o.batch_no, p.name AS product_name,
           wc.code AS work_center_code, wc.name AS work_center_name,
           TIMESTAMPDIFF(MINUTE, s.planned_start, s.planned_end) AS planned_minutes
    FROM production_schedule s
    INNER JOIN production_orders o ON o.id = s.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN production_work_centers wc ON wc.id = s.work_center_id
    ORDER BY s.planned_start ASC
    LIMIT 100
');
$scheduleDailySummary = app_fetch_all($db, '
    SELECT DATE(planned_start) AS plan_date,
           COUNT(*) AS plan_count,
           COALESCE(SUM(TIMESTAMPDIFF(MINUTE, planned_start, planned_end)), 0) AS planned_minutes
    FROM production_schedule
    WHERE status IN (\'planlandi\',\'onaylandi\')
    GROUP BY DATE(planned_start)
    ORDER BY plan_date ASC
    LIMIT 30
');
$wasteRecords = app_fetch_all($db, '
    SELECT w.id, w.expected_quantity, w.actual_waste_quantity, w.waste_reason, w.unit_cost, w.waste_cost,
           w.record_date, w.responsible_name, w.notes,
           o.order_no, p.name AS product_name, wp.name AS waste_product_name
    FROM production_waste_records w
    INNER JOIN production_orders o ON o.id = w.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN stock_products wp ON wp.id = w.product_id
    ORDER BY w.id DESC
    LIMIT 80
');
$wasteReasonSummary = app_fetch_all($db, '
    SELECT waste_reason,
           COUNT(*) AS record_count,
           COALESCE(SUM(actual_waste_quantity), 0) AS waste_quantity,
           COALESCE(SUM(waste_cost), 0) AS waste_cost
    FROM production_waste_records
    GROUP BY waste_reason
    ORDER BY waste_cost DESC, waste_quantity DESC
    LIMIT 50
');
$wasteOrderSummary = app_fetch_all($db, '
    SELECT o.order_no, p.name AS product_name,
           COALESCE(SUM(w.expected_quantity), 0) AS expected_quantity,
           COALESCE(SUM(w.actual_waste_quantity), 0) AS waste_quantity,
           COALESCE(SUM(w.waste_cost), 0) AS waste_cost
    FROM production_waste_records w
    INNER JOIN production_orders o ON o.id = w.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    GROUP BY o.id, o.order_no, p.name
    ORDER BY waste_cost DESC
    LIMIT 50
');
$costPreviewRows = app_fetch_all($db, '
    SELECT o.id, o.order_no, p.name AS product_name, o.planned_quantity, o.actual_quantity,
           COALESCE(mat.material_cost, 0) AS material_cost,
           COALESCE(ops.operation_cost, 0) AS operation_cost,
           COALESCE(wst.waste_cost, 0) AS waste_cost,
           COALESCE(outp.output_quantity, NULLIF(o.actual_quantity, 0), o.planned_quantity, 0) AS output_quantity
    FROM production_orders o
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN (
        SELECT c.production_order_id, COALESCE(SUM(c.quantity * COALESCE(sp.purchase_price, 0)), 0) AS material_cost
        FROM production_consumptions c
        INNER JOIN stock_products sp ON sp.id = c.product_id
        GROUP BY c.production_order_id
    ) mat ON mat.production_order_id = o.id
    LEFT JOIN (
        SELECT op.production_order_id,
               COALESCE(SUM((CASE WHEN op.actual_minutes > 0 THEN op.actual_minutes ELSE op.planned_minutes END) / 60 * COALESCE(wc.hourly_cost, 0)), 0) AS operation_cost
        FROM production_order_operations op
        LEFT JOIN production_work_centers wc ON wc.id = op.work_center_id
        GROUP BY op.production_order_id
    ) ops ON ops.production_order_id = o.id
    LEFT JOIN (
        SELECT production_order_id, COALESCE(SUM(waste_cost), 0) AS waste_cost
        FROM production_waste_records
        GROUP BY production_order_id
    ) wst ON wst.production_order_id = o.id
    LEFT JOIN (
        SELECT production_order_id, COALESCE(SUM(quantity), 0) AS output_quantity
        FROM production_outputs
        GROUP BY production_order_id
    ) outp ON outp.production_order_id = o.id
    ORDER BY o.id DESC
    LIMIT 80
');
$costRows = app_fetch_all($db, '
    SELECT cs.*, o.order_no, p.name AS product_name
    FROM production_cost_snapshots cs
    INNER JOIN production_orders o ON o.id = cs.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    ORDER BY cs.id DESC
    LIMIT 80
');
$semiFinishedFlows = app_fetch_all($db, '
    SELECT sf.id, sf.quantity, sf.flow_status, sf.flow_date, sf.lot_no, sf.responsible_name, sf.notes,
           o.order_no, p.name AS order_product_name, sp.name AS semi_product_name,
           from_wc.code AS from_work_center_code, from_wc.name AS from_work_center_name,
           to_wc.code AS to_work_center_code, to_wc.name AS to_work_center_name
    FROM production_semi_finished_flows sf
    INNER JOIN production_orders o ON o.id = sf.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    INNER JOIN stock_products sp ON sp.id = sf.product_id
    LEFT JOIN production_work_centers from_wc ON from_wc.id = sf.from_work_center_id
    LEFT JOIN production_work_centers to_wc ON to_wc.id = sf.to_work_center_id
    ORDER BY sf.flow_date DESC, sf.id DESC
    LIMIT 80
');
$semiFinishedStatusSummary = app_fetch_all($db, '
    SELECT flow_status, COUNT(*) AS flow_count, COALESCE(SUM(quantity), 0) AS total_quantity
    FROM production_semi_finished_flows
    GROUP BY flow_status
    ORDER BY flow_status ASC
');
$semiFinishedStationSummary = app_fetch_all($db, '
    SELECT COALESCE(to_wc.code, from_wc.code, \'Atanmadi\') AS station_code,
           COALESCE(to_wc.name, from_wc.name, \'Atanmadi\') AS station_name,
           COUNT(sf.id) AS flow_count,
           COALESCE(SUM(sf.quantity), 0) AS total_quantity
    FROM production_semi_finished_flows sf
    LEFT JOIN production_work_centers from_wc ON from_wc.id = sf.from_work_center_id
    LEFT JOIN production_work_centers to_wc ON to_wc.id = sf.to_work_center_id
    GROUP BY station_code, station_name
    ORDER BY flow_count DESC, station_code ASC
    LIMIT 50
');
$deadlinePlans = app_fetch_all($db, '
    SELECT dp.id, dp.target_start, dp.target_finish, dp.promised_date, dp.priority, dp.risk_status,
           dp.buffer_days, dp.responsible_name, dp.notes,
           o.order_no, o.status AS order_status, o.planned_quantity, o.actual_quantity, p.name AS product_name,
           DATEDIFF(dp.target_finish, NOW()) AS remaining_days,
           CASE
               WHEN dp.risk_status <> \'tamamlandi\' AND dp.target_finish < NOW() THEN \'gecikti\'
               WHEN dp.risk_status IN (\'riskli\',\'gecikmede\') THEN \'riskli\'
               ELSE \'normal\'
           END AS deadline_state
    FROM production_deadline_plans dp
    INNER JOIN production_orders o ON o.id = dp.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    ORDER BY dp.target_finish ASC, dp.id DESC
    LIMIT 100
');
$deadlineRiskSummary = app_fetch_all($db, '
    SELECT risk_status, COUNT(*) AS plan_count,
           SUM(CASE WHEN target_finish < NOW() AND risk_status <> \'tamamlandi\' THEN 1 ELSE 0 END) AS overdue_count
    FROM production_deadline_plans
    GROUP BY risk_status
    ORDER BY risk_status ASC
');
$deadlinePrioritySummary = app_fetch_all($db, '
    SELECT priority, COUNT(*) AS plan_count,
           MIN(target_finish) AS nearest_finish
    FROM production_deadline_plans
    WHERE risk_status <> \'tamamlandi\'
    GROUP BY priority
    ORDER BY FIELD(priority, \'kritik\', \'yuksek\', \'normal\', \'dusuk\')
');
$approvalRows = app_fetch_all($db, '
    SELECT a.id, a.approval_step, a.approval_status, a.requested_by, a.approver_name,
           a.decision_note, a.requested_at, a.decided_at,
           o.order_no, o.status AS order_status, p.name AS product_name
    FROM production_order_approvals a
    INNER JOIN production_orders o ON o.id = a.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products p ON p.id = r.product_id
    ORDER BY FIELD(a.approval_status, \'bekliyor\', \'onaylandi\', \'reddedildi\', \'iptal\'), a.requested_at DESC
    LIMIT 100
');
$approvalStatusSummary = app_fetch_all($db, '
    SELECT approval_status, COUNT(*) AS approval_count
    FROM production_order_approvals
    GROUP BY approval_status
    ORDER BY approval_status ASC
');
$approvalStepSummary = app_fetch_all($db, '
    SELECT approval_step,
           COUNT(*) AS approval_count,
           SUM(CASE WHEN approval_status = \'bekliyor\' THEN 1 ELSE 0 END) AS pending_count
    FROM production_order_approvals
    GROUP BY approval_step
    ORDER BY pending_count DESC, approval_count DESC, approval_step ASC
    LIMIT 50
');
$consumptions = app_fetch_all($db, '
    SELECT c.quantity, p.name AS product_name, w.name AS warehouse_name, o.order_no
    FROM production_consumptions c
    INNER JOIN production_orders o ON o.id = c.production_order_id
    INNER JOIN production_recipes r ON r.id = o.recipe_id
    INNER JOIN stock_products rp ON rp.id = r.product_id
    INNER JOIN stock_products p ON p.id = c.product_id
    LEFT JOIN stock_warehouses w ON w.id = c.warehouse_id
    ' . ($orderWhere ? $orderWhereSql : '') . ($filters['search'] !== '' ? ($orderWhere ? ' AND ' : ' WHERE ') . 'p.name LIKE :consumption_search' : '') . '
    ORDER BY c.id DESC
    LIMIT 50
', $filters['search'] !== '' ? array_merge($orderParams, ['consumption_search' => '%' . $filters['search'] . '%']) : $orderParams);
$outputs = app_fetch_all($db, '
    SELECT o.quantity, o.barcode, p.name AS product_name, w.name AS warehouse_name, po.order_no
    FROM production_outputs o
    INNER JOIN production_orders po ON po.id = o.production_order_id
    INNER JOIN production_recipes r ON r.id = po.recipe_id
    INNER JOIN stock_products rp ON rp.id = r.product_id
    INNER JOIN stock_products p ON p.id = o.product_id
    LEFT JOIN stock_warehouses w ON w.id = o.warehouse_id
    ' . ($orderWhere ? str_replace('o.', 'po.', $orderWhereSql) : '') . ($filters['search'] !== '' ? (($orderWhere ? str_replace('o.', 'po.', $orderWhereSql) : '') ? ' AND ' : ' WHERE ') . '(p.name LIKE :output_search OR o.barcode LIKE :output_search)' : '') . '
    ORDER BY o.id DESC
    LIMIT 50
', $filters['search'] !== '' ? array_merge($orderParams, ['output_search' => '%' . $filters['search'] . '%']) : $orderParams);
$recipeDocCounts = app_related_doc_counts($db, 'uretim', 'production_recipes', array_column($recipes, 'id'));
$orderDocCounts = app_related_doc_counts($db, 'uretim', 'production_orders', array_column($orders, 'id'));

$recipes = app_sort_rows($recipes, $filters['recipe_sort'], [
    'id_desc' => ['id', 'desc'],
    'code_asc' => ['recipe_code', 'asc'],
    'output_desc' => ['output_quantity', 'desc'],
    'output_asc' => ['output_quantity', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$orders = app_sort_rows($orders, $filters['order_sort'], [
    'id_desc' => ['id', 'desc'],
    'code_asc' => ['order_no', 'asc'],
    'planned_desc' => ['planned_quantity', 'desc'],
    'planned_asc' => ['planned_quantity', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$recipesPagination = app_paginate_rows($recipes, $filters['recipe_page'], 10);
$ordersPagination = app_paginate_rows($orders, $filters['order_page'], 10);
$recipes = $recipesPagination['items'];
$orders = $ordersPagination['items'];

$summary = [
    'Recete' => app_table_count($db, 'production_recipes'),
    'Cok Seviyeli Bag' => app_table_count($db, 'production_recipe_subrecipes'),
    'Uretim Emri' => app_table_count($db, 'production_orders'),
    'Operasyon' => app_table_count($db, 'production_order_operations'),
    'Is Merkezi' => app_table_count($db, 'production_work_centers'),
    'Uretim Rotasi' => app_table_count($db, 'production_recipe_routes'),
    'Planlama Kaydi' => app_table_count($db, 'production_schedule'),
    'Fire Kaydi' => app_table_count($db, 'production_waste_records'),
    'Fire Maliyeti' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(waste_cost),0) FROM production_waste_records'), 2, ',', '.'),
    'Maliyet Hesabi' => app_table_count($db, 'production_cost_snapshots'),
    'Uretim Maliyeti' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(total_cost),0) FROM production_cost_snapshots'), 2, ',', '.'),
    'Yari Mamul Akisi' => app_table_count($db, 'production_semi_finished_flows'),
    'Transferde Yari Mamul' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(quantity),0) FROM production_semi_finished_flows WHERE flow_status = 'transferde'"), 3, ',', '.'),
    'Termin Plani' => app_table_count($db, 'production_deadline_plans'),
    'Geciken Termin' => app_metric($db, "SELECT COUNT(*) FROM production_deadline_plans WHERE target_finish < NOW() AND risk_status <> 'tamamlandi'"),
    'Emir Onay Akisi' => app_table_count($db, 'production_order_approvals'),
    'Bekleyen Onay' => app_metric($db, "SELECT COUNT(*) FROM production_order_approvals WHERE approval_status = 'bekliyor'"),
    'Planli Emir' => app_metric($db, "SELECT COUNT(*) FROM production_orders WHERE status = 'planlandi'"),
    'Uretimde' => app_metric($db, "SELECT COUNT(*) FROM production_orders WHERE status = 'uretimde'"),
    'Sarf Kaydi' => app_table_count($db, 'production_consumptions'),
    'Uretim Ciktisi' => app_table_count($db, 'production_outputs'),
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
    <h3>Uretim Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="uretim">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Recete, emir, urun, batch, barkod">
        </div>
        <div>
            <label>Recete Durumu</label>
            <select name="recipe_status">
                <option value="">Tum durumlar</option>
                <option value="taslak" <?= $filters['recipe_status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                <option value="onayli" <?= $filters['recipe_status'] === 'onayli' ? 'selected' : '' ?>>Onayli</option>
                <option value="pasif" <?= $filters['recipe_status'] === 'pasif' ? 'selected' : '' ?>>Pasif</option>
            </select>
        </div>
        <div>
            <label>Emir Durumu</label>
            <select name="order_status">
                <option value="">Tum durumlar</option>
                <option value="planlandi" <?= $filters['order_status'] === 'planlandi' ? 'selected' : '' ?>>Planlandi</option>
                <option value="uretimde" <?= $filters['order_status'] === 'uretimde' ? 'selected' : '' ?>>Uretimde</option>
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
            <a href="index.php?module=uretim" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="uretim">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="recipe_status" value="<?= app_h($filters['recipe_status']) ?>">
        <input type="hidden" name="order_status" value="<?= app_h($filters['order_status']) ?>">
        <div>
            <label>Recete Siralama</label>
            <select name="recipe_sort">
                <option value="id_desc" <?= $filters['recipe_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="code_asc" <?= $filters['recipe_sort'] === 'code_asc' ? 'selected' : '' ?>>Kod A-Z</option>
                <option value="output_desc" <?= $filters['recipe_sort'] === 'output_desc' ? 'selected' : '' ?>>Cikti yuksek</option>
                <option value="output_asc" <?= $filters['recipe_sort'] === 'output_asc' ? 'selected' : '' ?>>Cikti dusuk</option>
                <option value="status_asc" <?= $filters['recipe_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>Emir Siralama</label>
            <select name="order_sort">
                <option value="id_desc" <?= $filters['order_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="code_asc" <?= $filters['order_sort'] === 'code_asc' ? 'selected' : '' ?>>Kod A-Z</option>
                <option value="planned_desc" <?= $filters['order_sort'] === 'planned_desc' ? 'selected' : '' ?>>Plan yuksek</option>
                <option value="planned_asc" <?= $filters['order_sort'] === 'planned_asc' ? 'selected' : '' ?>>Plan dusuk</option>
                <option value="status_asc" <?= $filters['order_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
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
        <h3>Is Merkezi / Istasyon</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_work_center">
            <div>
                <label>Kod</label>
                <input name="code" placeholder="IST-001" required>
            </div>
            <div>
                <label>Ad</label>
                <input name="name" placeholder="Karisim hatti / Dolum istasyonu" required>
            </div>
            <div>
                <label>Tip</label>
                <input name="station_type" placeholder="Hat / Makine / Manuel istasyon">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" placeholder="Ekip veya operator">
            </div>
            <div>
                <label>Saatlik Kapasite</label>
                <input type="number" step="0.001" name="hourly_capacity" value="0">
            </div>
            <div>
                <label>Saatlik Maliyet</label>
                <input type="number" step="0.01" name="hourly_cost" value="0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="aktif">Aktif</option>
                    <option value="bakimda">Bakimda</option>
                    <option value="pasif">Pasif</option>
                </select>
            </div>
            <div>
                <label>Lokasyon</label>
                <input name="location_text" placeholder="Uretim alani / Kat / Hat">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Bakim notu, vardiya kapasitesi veya kullanim kisiti"></textarea>
            </div>
            <div class="full">
                <button type="submit">Is Merkezi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Is Merkezi Yuk Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kod</th><th>Istasyon</th><th>Durum</th><th>Kapasite</th><th>Operasyon</th><th>Plan/Gercek Dk</th></tr></thead>
                <tbody>
                <?php foreach ($workCenterLoad as $center): ?>
                    <tr>
                        <td><?= app_h($center['code']) ?></td>
                        <td><?= app_h($center['name']) ?></td>
                        <td><?= app_h($center['status']) ?></td>
                        <td><?= number_format((float) $center['hourly_capacity'], 3, ',', '.') ?></td>
                        <td><?= (int) $center['operation_count'] ?></td>
                        <td><?= (int) $center['planned_minutes'] ?> / <?= (int) $center['actual_minutes'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$workCenterLoad): ?>
                    <tr><td colspan="6">Henuz is merkezi kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Emir Onay Akisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_order_approval">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Onay Adimi</label>
                <select name="approval_step" required>
                    <option value="Planlama Onayi">Planlama Onayi</option>
                    <option value="Uretim Muduru Onayi">Uretim Muduru Onayi</option>
                    <option value="Kalite Onayi">Kalite Onayi</option>
                    <option value="Maliyet Onayi">Maliyet Onayi</option>
                    <option value="Sevk Onayi">Sevk Onayi</option>
                </select>
            </div>
            <div>
                <label>Talep Eden</label>
                <input name="requested_by" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div>
                <label>Onaylayacak Kisi</label>
                <input name="approver_name" placeholder="Yetkili kisi veya ekip">
            </div>
            <div class="full">
                <label>Talep Notu</label>
                <textarea name="decision_note" rows="2" placeholder="Onaya gonderme sebebi veya dikkat notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Onay Talebi Olustur</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Onay Akisi Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Kayit</th></tr></thead>
                <tbody>
                <?php foreach ($approvalStatusSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['approval_status']) ?></td>
                        <td><?= (int) $item['approval_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvalStatusSummary): ?>
                    <tr><td colspan="2">Henuz onay akisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Adim Bazli Onay</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Adim</th><th>Toplam</th><th>Bekleyen</th></tr></thead>
                <tbody>
                <?php foreach ($approvalStepSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['approval_step']) ?></td>
                        <td><?= (int) $item['approval_count'] ?></td>
                        <td><?= (int) $item['pending_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvalStepSummary): ?>
                    <tr><td colspan="3">Adim bazli onay verisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Termin Planlama</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_deadline_plan">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hedef Baslangic</label>
                <input type="datetime-local" name="target_start" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div>
                <label>Hedef Bitis</label>
                <input type="datetime-local" name="target_finish" value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>" required>
            </div>
            <div>
                <label>Soz Verilen Tarih</label>
                <input type="date" name="promised_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
            </div>
            <div>
                <label>Oncelik</label>
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="dusuk">Dusuk</option>
                    <option value="yuksek">Yuksek</option>
                    <option value="kritik">Kritik</option>
                </select>
            </div>
            <div>
                <label>Risk Durumu</label>
                <select name="risk_status">
                    <option value="normal">Normal</option>
                    <option value="riskli">Riskli</option>
                    <option value="gecikmede">Gecikmede</option>
                    <option value="tamamlandi">Tamamlandi</option>
                </select>
            </div>
            <div>
                <label>Tampon Gun</label>
                <input type="number" name="buffer_days" min="0" value="0">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" placeholder="Planlama sorumlusu">
            </div>
            <div class="full">
                <label>Termin Notu</label>
                <textarea name="notes" rows="2" placeholder="Musteri teslimi, kritik malzeme veya kapasite notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Termin Plani Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Termin Risk Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Risk</th><th>Plan</th><th>Geciken</th></tr></thead>
                <tbody>
                <?php foreach ($deadlineRiskSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['risk_status']) ?></td>
                        <td><?= (int) $item['plan_count'] ?></td>
                        <td><?= (int) $item['overdue_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deadlineRiskSummary): ?>
                    <tr><td colspan="3">Henuz termin plani yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Oncelik Bazli Termin</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Oncelik</th><th>Plan</th><th>En Yakin Bitis</th></tr></thead>
                <tbody>
                <?php foreach ($deadlinePrioritySummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['priority']) ?></td>
                        <td><?= (int) $item['plan_count'] ?></td>
                        <td><?= app_h((string) ($item['nearest_finish'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deadlinePrioritySummary): ?>
                    <tr><td colspan="3">Aktif termin onceligi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yari Mamul Akisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_semi_finished_flow">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Yari Mamul</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kaynak Istasyon</label>
                <select name="from_work_center_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($workCenters as $center): ?>
                        <option value="<?= (int) $center['id'] ?>"><?= app_h($center['code'] . ' / ' . $center['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hedef Istasyon</label>
                <select name="to_work_center_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($workCenters as $center): ?>
                        <option value="<?= (int) $center['id'] ?>"><?= app_h($center['code'] . ' / ' . $center['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" step="0.001" name="quantity" value="0" required>
            </div>
            <div>
                <label>Durum</label>
                <select name="flow_status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="transferde">Transferde</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="blokeli">Blokeli</option>
                </select>
            </div>
            <div>
                <label>Akis Tarihi</label>
                <input type="datetime-local" name="flow_date" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div>
                <label>Lot No</label>
                <input name="lot_no" placeholder="LOT-001">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" placeholder="Operator / ekip">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Ara stok, kalite bekleme veya transfer notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Yari Mamul Akisi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Yari Mamul Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Kayit</th><th>Miktar</th></tr></thead>
                <tbody>
                <?php foreach ($semiFinishedStatusSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['flow_status']) ?></td>
                        <td><?= (int) $item['flow_count'] ?></td>
                        <td><?= number_format((float) $item['total_quantity'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$semiFinishedStatusSummary): ?>
                    <tr><td colspan="3">Henuz yari mamul akisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Istasyon Bazli Yari Mamul</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Istasyon</th><th>Kayit</th><th>Miktar</th></tr></thead>
                <tbody>
                <?php foreach ($semiFinishedStationSummary as $item): ?>
                    <tr>
                        <td><small><?= app_h($item['station_code']) ?><br><?= app_h($item['station_name']) ?></small></td>
                        <td><?= (int) $item['flow_count'] ?></td>
                        <td><?= number_format((float) $item['total_quantity'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$semiFinishedStationSummary): ?>
                    <tr><td colspan="3">Istasyon bazli yari mamul verisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Maliyet Hesaplama</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="calculate_production_cost">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Genel Gider</label>
                <input type="number" step="0.01" name="overhead_cost" value="0">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Enerji, fason, amortisman veya ek maliyet notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Maliyeti Hesapla</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Maliyet On Izleme</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Malzeme</th><th>Operasyon</th><th>Fire</th><th>Cikti</th><th>Tahmini Birim</th></tr></thead>
                <tbody>
                <?php foreach ($costPreviewRows as $cost): ?>
                    <?php
                        $previewTotal = (float) $cost['material_cost'] + (float) $cost['operation_cost'] + (float) $cost['waste_cost'];
                        $previewOutput = (float) $cost['output_quantity'];
                        $previewUnit = $previewOutput > 0 ? $previewTotal / $previewOutput : 0;
                    ?>
                    <tr>
                        <td><small><?= app_h($cost['order_no']) ?><br><?= app_h($cost['product_name']) ?></small></td>
                        <td><?= number_format((float) $cost['material_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['operation_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['waste_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format($previewOutput, 3, ',', '.') ?></td>
                        <td><?= number_format($previewUnit, 4, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$costPreviewRows): ?>
                    <tr><td colspan="6">Henuz maliyet on izleme verisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Uretim Fire Analizi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_waste_record">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fire Urunu</label>
                <select name="product_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Beklenen Fire</label>
                <input type="number" step="0.001" name="expected_quantity" value="0">
            </div>
            <div>
                <label>Gerceklesen Fire</label>
                <input type="number" step="0.001" name="actual_waste_quantity" value="0" required>
            </div>
            <div>
                <label>Fire Sebebi</label>
                <input name="waste_reason" placeholder="Ayar fire / kalite red / makine durusu" required>
            </div>
            <div>
                <label>Birim Maliyet</label>
                <input type="number" step="0.01" name="unit_cost" value="0">
            </div>
            <div>
                <label>Tarih</label>
                <input type="date" name="record_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" placeholder="Operator / ekip">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Fire aciklamasi veya duzeltici faaliyet"></textarea>
            </div>
            <div class="full">
                <button type="submit">Fire Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Fire Sebep Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sebep</th><th>Kayit</th><th>Miktar</th><th>Maliyet</th></tr></thead>
                <tbody>
                <?php foreach ($wasteReasonSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['waste_reason']) ?></td>
                        <td><?= (int) $item['record_count'] ?></td>
                        <td><?= number_format((float) $item['waste_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['waste_cost'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$wasteReasonSummary): ?>
                    <tr><td colspan="4">Henuz fire kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Planlama Takvimi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_production_schedule">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Is Merkezi</label>
                <select name="work_center_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($workCenters as $center): ?>
                        <option value="<?= (int) $center['id'] ?>"><?= app_h($center['code'] . ' / ' . $center['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Plan Baslangic</label>
                <input type="datetime-local" name="planned_start" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div>
                <label>Plan Bitis</label>
                <input type="datetime-local" name="planned_end" value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>" required>
            </div>
            <div>
                <label>Oncelik</label>
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="dusuk">Dusuk</option>
                    <option value="yuksek">Yuksek</option>
                    <option value="kritik">Kritik</option>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="planlandi">Planlandi</option>
                    <option value="onaylandi">Onaylandi</option>
                    <option value="ertelendi">Ertelendi</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div class="full">
                <label>Plan Notu</label>
                <textarea name="notes" rows="2" placeholder="Vardiya, kapasite veya planlama notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Plan Takvime Ekle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Gunluk Plan Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Plan</th><th>Toplam Dk</th><th>Toplam Saat</th></tr></thead>
                <tbody>
                <?php foreach ($scheduleDailySummary as $day): ?>
                    <tr>
                        <td><?= app_h($day['plan_date']) ?></td>
                        <td><?= (int) $day['plan_count'] ?></td>
                        <td><?= (int) $day['planned_minutes'] ?></td>
                        <td><?= number_format(((int) $day['planned_minutes']) / 60, 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$scheduleDailySummary): ?>
                    <tr><td colspan="4">Henuz aktif planlama kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Uretim Rotasi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_recipe_route">
            <div>
                <label>Recete</label>
                <select name="recipe_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($recipes as $recipe): ?>
                        <option value="<?= (int) $recipe['id'] ?>"><?= app_h($recipe['recipe_code'] . ' / ' . $recipe['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Is Merkezi</label>
                <select name="work_center_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($workCenters as $center): ?>
                        <option value="<?= (int) $center['id'] ?>"><?= app_h($center['code'] . ' / ' . $center['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sira No</label>
                <input type="number" name="sequence_no" min="1" value="1">
            </div>
            <div>
                <label>Operasyon Adi</label>
                <input name="operation_name" placeholder="Hazirlik / Karisim / Dolum / Paketleme" required>
            </div>
            <div>
                <label>Hazirlik Dk</label>
                <input type="number" name="setup_minutes" min="0" value="0">
            </div>
            <div>
                <label>Calisma Dk</label>
                <input type="number" name="run_minutes" min="0" value="0">
            </div>
            <div>
                <label>Transfer Dk</label>
                <input type="number" name="transfer_minutes" min="0" value="0">
            </div>
            <div>
                <label>Kalite Kontrol</label>
                <label style="display:block;"><input type="checkbox" name="quality_check_required"> Zorunlu</label>
            </div>
            <div class="full">
                <label>Rota Notu</label>
                <textarea name="notes" rows="2" placeholder="Istasyon gecisi, kalite veya setup notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Rota Adimi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Rota Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Recete</th><th>Urun</th><th>Adim</th><th>Toplam Dk</th></tr></thead>
                <tbody>
                <?php foreach ($routeSummary as $route): ?>
                    <tr>
                        <td><?= app_h($route['recipe_code']) ?></td>
                        <td><?= app_h($route['product_name']) ?></td>
                        <td><?= (int) $route['route_step_count'] ?></td>
                        <td><?= (int) $route['total_minutes'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$routeSummary): ?>
                    <tr><td colspan="4">Henuz uretim rotasi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Cok Seviyeli Recete</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="add_subrecipe">
            <div>
                <label>Ana Recete</label>
                <select name="parent_recipe_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($recipes as $recipe): ?>
                        <option value="<?= (int) $recipe['id'] ?>"><?= app_h($recipe['recipe_code'] . ' / ' . $recipe['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Alt Recete / Yari Mamul</label>
                <select name="child_recipe_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($recipes as $recipe): ?>
                        <option value="<?= (int) $recipe['id'] ?>"><?= app_h($recipe['recipe_code'] . ' / ' . $recipe['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Seviye</label>
                <input type="number" name="level_no" min="1" max="10" value="1">
            </div>
            <div>
                <label>Miktar Carpani</label>
                <input type="number" step="0.001" name="quantity_multiplier" value="1" required>
            </div>
            <div>
                <label>Zorunlu mu?</label>
                <label style="display:block;"><input type="checkbox" name="is_required" checked> Uretimde zorunlu alt recete</label>
            </div>
            <div class="full">
                <label>Operasyon Notu</label>
                <textarea name="operation_note" rows="2" placeholder="Alt montaj, yari mamul hazirligi veya ozel proses notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Alt Recete Bagla</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Recete Agaci Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ana Recete</th><th>Urun</th><th>Alt Recete</th><th>Derinlik</th></tr></thead>
                <tbody>
                <?php foreach ($multilevelSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['recipe_code']) ?></td>
                        <td><?= app_h($item['product_name']) ?></td>
                        <td><?= (int) $item['subrecipe_count'] ?></td>
                        <td><?= (int) $item['max_level'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$multilevelSummary): ?>
                    <tr><td colspan="4">Henuz cok seviyeli recete baglantisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yeni Recete</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_recipe">
            <div>
                <label>Mamul Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hammadde</label>
                <select name="material_product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Versiyon</label>
                <input name="version_no" value="1.0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="taslak">Taslak</option>
                    <option value="onayli">Onayli</option>
                    <option value="pasif">Pasif</option>
                </select>
            </div>
            <div>
                <label>Cikti Miktari</label>
                <input type="number" step="0.001" name="output_quantity" value="1">
            </div>
            <div>
                <label>Hammadde Miktari</label>
                <input type="number" step="0.001" name="material_quantity" value="1" required>
            </div>
            <div>
                <label>Birim</label>
                <input name="unit" value="adet">
            </div>
            <div>
                <label>Fire %</label>
                <input type="number" step="0.01" name="wastage_rate" value="0">
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Recete notlari"></textarea>
            </div>
            <div class="full">
                <button type="submit">Recete Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Uretim Emri</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_order">
            <div>
                <label>Recete</label>
                <select name="recipe_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($recipes as $recipe): ?>
                        <option value="<?= (int) $recipe['id'] ?>"><?= app_h($recipe['recipe_code'] . ' / ' . $recipe['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Planlanan Miktar</label>
                <input type="number" step="0.001" name="planned_quantity" value="1" required>
            </div>
            <div>
                <label>Batch No</label>
                <input name="batch_no" placeholder="BATCH-001">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="planlandi">Planlandi</option>
                    <option value="uretimde">Uretimde</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div class="full">
                <button type="submit">Uretim Emri Ac</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Operasyon Bazli Uretim</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_order_operation">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sira No</label>
                <input type="number" name="sequence_no" min="1" value="1">
            </div>
            <div>
                <label>Is Merkezi</label>
                <select name="work_center_id">
                    <option value="0">Secilmedi</option>
                    <?php foreach ($workCenters as $center): ?>
                        <option value="<?= (int) $center['id'] ?>"><?= app_h($center['code'] . ' / ' . $center['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Operasyon Adi</label>
                <input name="operation_name" placeholder="Hazirlik / Karisim / Dolum / Paketleme" required>
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" placeholder="Operator veya ekip">
            </div>
            <div>
                <label>Plan Sure (dk)</label>
                <input type="number" name="planned_minutes" min="0" value="0">
            </div>
            <div>
                <label>Gercek Sure (dk)</label>
                <input type="number" name="actual_minutes" min="0" value="0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="basladi">Basladi</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="atlandi">Atlandi</option>
                </select>
            </div>
            <div>
                <label>Baslangic</label>
                <input type="datetime-local" name="started_at">
            </div>
            <div>
                <label>Bitis</label>
                <input type="datetime-local" name="finished_at">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Operasyon aciklamasi, kalite notu veya durus sebebi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Operasyon Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Operasyon Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Adet</th><th>Plan Dk</th><th>Gercek Dk</th><th>Fark</th></tr></thead>
                <tbody>
                <?php foreach ($operationSummary as $item): ?>
                    <?php $minuteDiff = (int) $item['actual_minutes'] - (int) $item['planned_minutes']; ?>
                    <tr>
                        <td><?= app_h($item['status']) ?></td>
                        <td><?= (int) $item['operation_count'] ?></td>
                        <td><?= (int) $item['planned_minutes'] ?></td>
                        <td><?= (int) $item['actual_minutes'] ?></td>
                        <td style="font-weight:700;color:<?= $minuteDiff > 0 ? '#b91c1c' : '#166534' ?>;"><?= $minuteDiff ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$operationSummary): ?>
                    <tr><td colspan="5">Henuz operasyon kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sarf Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_consumption">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sarf Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Depo</label>
                <select name="warehouse_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" step="0.001" name="quantity" value="1" required>
            </div>
            <div class="full">
                <button type="submit">Sarf Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Uretim Ciktisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_output">
            <div>
                <label>Uretim Emri</label>
                <select name="production_order_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($orders as $order): ?>
                        <option value="<?= (int) $order['id'] ?>"><?= app_h($order['order_no'] . ' / ' . $order['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Cikti Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Depo</label>
                <select name="warehouse_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?= (int) $warehouse['id'] ?>"><?= app_h($warehouse['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" step="0.001" name="quantity" value="1" required>
            </div>
            <div>
                <label>Barkod</label>
                <input name="barcode" placeholder="Opsiyonel barkod">
            </div>
            <div class="full">
                <button type="submit">Cikti Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Receteler</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_recipe_status">
            <select name="bulk_recipe_status">
                <option value="taslak">Taslak</option>
                <option value="onayli">Onayli</option>
                <option value="pasif">Pasif</option>
            </select>
            <button type="submit">Secili Receteleri Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.recipe-check').forEach((el)=>el.checked=this.checked)"></th><th>Kod</th><th>Urun</th><th>Versiyon</th><th>Durum</th><th>Cikti</th><th>Evrak</th><th>Guncelle</th></tr></thead>
                <tbody>
                <?php foreach ($recipes as $recipe): ?>
                    <tr>
                        <td><input class="recipe-check" type="checkbox" name="recipe_ids[]" value="<?= (int) $recipe['id'] ?>"></td>
                        <td><?= app_h($recipe['recipe_code']) ?></td>
                        <td><?= app_h($recipe['product_name']) ?></td>
                        <td><?= app_h($recipe['version_no']) ?></td>
                        <td><?= app_h($recipe['status']) ?></td>
                        <td><?= number_format((float) $recipe['output_quantity'], 3, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="index.php?module=evrak&filter_module=uretim&filter_related_table=production_recipes&filter_related_id=<?= (int) $recipe['id'] ?>&prefill_module=uretim&prefill_related_table=production_recipes&prefill_related_id=<?= (int) $recipe['id'] ?>">
                                    Evrak (<?= (int) ($recipeDocCounts[(int) $recipe['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('uretim', 'production_recipes', (int) $recipe['id'], 'index.php?module=uretim')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="update_recipe_status">
                                <input type="hidden" name="recipe_id" value="<?= (int) $recipe['id'] ?>">
                                <select name="status">
                                    <option value="taslak" <?= $recipe['status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                                    <option value="onayli" <?= $recipe['status'] === 'onayli' ? 'selected' : '' ?>>Onayli</option>
                                    <option value="pasif" <?= $recipe['status'] === 'pasif' ? 'selected' : '' ?>>Pasif</option>
                                </select>
                                <button type="submit">Kaydet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($recipesPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $recipesPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'uretim', 'search' => $filters['search'], 'recipe_status' => $filters['recipe_status'], 'order_status' => $filters['order_status'], 'recipe_sort' => $filters['recipe_sort'], 'order_sort' => $filters['order_sort'], 'recipe_page' => $page, 'order_page' => $ordersPagination['page']])) ?>"><?= $page === $recipesPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Recete Kalemleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Recete</th><th>Malzeme</th><th>Miktar</th><th>Birim</th><th>Fire %</th></tr></thead>
                <tbody>
                <?php foreach ($recipeItems as $item): ?>
                    <tr>
                        <td><?= app_h($item['recipe_code']) ?></td>
                        <td><?= app_h($item['material_name']) ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                        <td><?= app_h($item['unit']) ?></td>
                        <td><?= number_format((float) $item['wastage_rate'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Cok Seviyeli Recete Baglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Ana</th><th>Alt Recete</th><th>Seviye</th><th>Carpan</th><th>Zorunlu</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($subrecipes as $subrecipe): ?>
                    <tr>
                        <td><?= app_h($subrecipe['parent_recipe_code'] . ' / ' . $subrecipe['parent_product_name']) ?></td>
                        <td><?= app_h($subrecipe['child_recipe_code'] . ' / ' . $subrecipe['child_product_name']) ?></td>
                        <td><?= (int) $subrecipe['level_no'] ?></td>
                        <td><?= number_format((float) $subrecipe['quantity_multiplier'], 3, ',', '.') ?></td>
                        <td><?= (int) $subrecipe['is_required'] === 1 ? 'Evet' : 'Hayir' ?></td>
                        <td><?= app_h((string) ($subrecipe['operation_note'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$subrecipes): ?>
                    <tr><td colspan="6">Henuz alt recete baglantisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Uretim Rota Adimlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Recete</th><th>Sira</th><th>Is Merkezi</th><th>Operasyon</th><th>Sure</th><th>KK</th></tr></thead>
                <tbody>
                <?php foreach ($recipeRoutes as $route): ?>
                    <tr>
                        <td><?= app_h($route['recipe_code'] . ' / ' . $route['product_name']) ?></td>
                        <td><?= (int) $route['sequence_no'] ?></td>
                        <td><?= app_h($route['work_center_code'] ? ($route['work_center_code'] . ' / ' . $route['work_center_name']) : '-') ?></td>
                        <td><small><?= app_h($route['operation_name']) ?><br><?= app_h((string) ($route['notes'] ?: '-')) ?></small></td>
                        <td><?= (int) $route['setup_minutes'] ?> + <?= (int) $route['run_minutes'] ?> + <?= (int) $route['transfer_minutes'] ?> dk</td>
                        <td><?= (int) $route['quality_check_required'] === 1 ? 'Evet' : 'Hayir' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recipeRoutes): ?>
                    <tr><td colspan="6">Henuz rota adimi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($ordersPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $ordersPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'uretim', 'search' => $filters['search'], 'recipe_status' => $filters['recipe_status'], 'order_status' => $filters['order_status'], 'recipe_sort' => $filters['recipe_sort'], 'order_sort' => $filters['order_sort'], 'recipe_page' => $recipesPagination['page'], 'order_page' => $page])) ?>"><?= $page === $ordersPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Uretim Emirleri</h3>
        <h3 style="margin-top:0;">Emir Onay Akis Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Talep</th><th>Emir</th><th>Adim</th><th>Durum</th><th>Karar</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($approvalRows as $approval): ?>
                    <tr>
                        <td><small><?= app_h($approval['requested_at']) ?><br><?= app_h((string) ($approval['requested_by'] ?: '-')) ?></small></td>
                        <td><small><?= app_h($approval['order_no']) ?><br><?= app_h($approval['product_name']) ?></small></td>
                        <td><?= app_h($approval['approval_step']) ?></td>
                        <td><?= app_h($approval['approval_status']) ?></td>
                        <td><small><?= app_h((string) ($approval['approver_name'] ?: '-')) ?><br><?= app_h((string) ($approval['decision_note'] ?: '-')) ?></small></td>
                        <td>
                            <?php if ($approval['approval_status'] === 'bekliyor'): ?>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="decide_order_approval">
                                    <input type="hidden" name="approval_id" value="<?= (int) $approval['id'] ?>">
                                    <select name="approval_status">
                                        <option value="onaylandi">Onayla</option>
                                        <option value="reddedildi">Reddet</option>
                                        <option value="iptal">Iptal</option>
                                    </select>
                                    <input name="approver_name" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>" placeholder="Onaylayan">
                                    <input name="decision_note" placeholder="Karar notu">
                                    <button type="submit">Karar Ver</button>
                                </form>
                            <?php else: ?>
                                <small><?= app_h((string) ($approval['decided_at'] ?: '-')) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvalRows): ?>
                    <tr><td colspan="6">Henuz emir onay akisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Termin Takip Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Hedef Bitis</th><th>Emir</th><th>Soz Tarihi</th><th>Oncelik</th><th>Risk</th><th>Kalan</th><th>Sorumlu</th></tr></thead>
                <tbody>
                <?php foreach ($deadlinePlans as $deadline): ?>
                    <tr>
                        <td><small><?= app_h($deadline['target_finish']) ?><br>Bas: <?= app_h((string) ($deadline['target_start'] ?: '-')) ?></small></td>
                        <td><small><?= app_h($deadline['order_no']) ?><br><?= app_h($deadline['product_name']) ?></small></td>
                        <td><?= app_h((string) ($deadline['promised_date'] ?: '-')) ?></td>
                        <td><?= app_h($deadline['priority']) ?></td>
                        <td><small><?= app_h($deadline['risk_status']) ?><br><?= app_h($deadline['deadline_state']) ?></small></td>
                        <td><?= (int) $deadline['remaining_days'] ?> gun</td>
                        <td><small><?= app_h((string) ($deadline['responsible_name'] ?: '-')) ?><br><?= app_h((string) ($deadline['notes'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deadlinePlans): ?>
                    <tr><td colspan="7">Henuz termin planlama kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Yari Mamul Akis Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Emir</th><th>Yari Mamul</th><th>Akis</th><th>Miktar</th><th>Durum</th><th>Sorumlu</th></tr></thead>
                <tbody>
                <?php foreach ($semiFinishedFlows as $flow): ?>
                    <tr>
                        <td><?= app_h($flow['flow_date']) ?></td>
                        <td><small><?= app_h($flow['order_no']) ?><br><?= app_h($flow['order_product_name']) ?></small></td>
                        <td><small><?= app_h($flow['semi_product_name']) ?><br><?= app_h((string) ($flow['lot_no'] ?: '-')) ?></small></td>
                        <td><small><?= app_h($flow['from_work_center_code'] ? ($flow['from_work_center_code'] . ' / ' . $flow['from_work_center_name']) : '-') ?><br><?= app_h($flow['to_work_center_code'] ? ($flow['to_work_center_code'] . ' / ' . $flow['to_work_center_name']) : '-') ?></small></td>
                        <td><?= number_format((float) $flow['quantity'], 3, ',', '.') ?></td>
                        <td><?= app_h($flow['flow_status']) ?></td>
                        <td><small><?= app_h((string) ($flow['responsible_name'] ?: '-')) ?><br><?= app_h((string) ($flow['notes'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$semiFinishedFlows): ?>
                    <tr><td colspan="7">Henuz yari mamul akis kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Maliyet Hesap Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Emir</th><th>Malzeme</th><th>Operasyon</th><th>Fire</th><th>Genel</th><th>Toplam</th><th>Birim</th></tr></thead>
                <tbody>
                <?php foreach ($costRows as $cost): ?>
                    <tr>
                        <td><?= app_h($cost['calculated_at']) ?></td>
                        <td><small><?= app_h($cost['order_no']) ?><br><?= app_h($cost['product_name']) ?></small></td>
                        <td><?= number_format((float) $cost['material_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['operation_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['waste_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['overhead_cost'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cost['total_cost'], 2, ',', '.') ?></td>
                        <td><small><?= number_format((float) $cost['unit_cost'], 4, ',', '.') ?><br><?= number_format((float) $cost['output_quantity'], 3, ',', '.') ?> cikti</small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$costRows): ?>
                    <tr><td colspan="8">Henuz maliyet hesap gecmisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Fire Analiz Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Emir</th><th>Fire Urunu</th><th>Sebep</th><th>Beklenen</th><th>Gercek</th><th>Oran</th><th>Maliyet</th></tr></thead>
                <tbody>
                <?php foreach ($wasteRecords as $waste): ?>
                    <?php $wasteRate = (float) $waste['expected_quantity'] > 0 ? ((float) $waste['actual_waste_quantity'] / (float) $waste['expected_quantity']) * 100 : 0; ?>
                    <tr>
                        <td><?= app_h($waste['record_date']) ?></td>
                        <td><?= app_h($waste['order_no'] . ' / ' . $waste['product_name']) ?></td>
                        <td><?= app_h((string) ($waste['waste_product_name'] ?: '-')) ?></td>
                        <td><small><?= app_h($waste['waste_reason']) ?><br><?= app_h((string) ($waste['notes'] ?: '-')) ?></small></td>
                        <td><?= number_format((float) $waste['expected_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $waste['actual_waste_quantity'], 3, ',', '.') ?></td>
                        <td>%<?= number_format($wasteRate, 1, ',', '.') ?></td>
                        <td><?= number_format((float) $waste['waste_cost'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$wasteRecords): ?>
                    <tr><td colspan="8">Henuz fire analiz kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Emir Bazli Fire Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Urun</th><th>Beklenen</th><th>Gercek Fire</th><th>Oran</th><th>Maliyet</th></tr></thead>
                <tbody>
                <?php foreach ($wasteOrderSummary as $waste): ?>
                    <?php $orderWasteRate = (float) $waste['expected_quantity'] > 0 ? ((float) $waste['waste_quantity'] / (float) $waste['expected_quantity']) * 100 : 0; ?>
                    <tr>
                        <td><?= app_h($waste['order_no']) ?></td>
                        <td><?= app_h($waste['product_name']) ?></td>
                        <td><?= number_format((float) $waste['expected_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $waste['waste_quantity'], 3, ',', '.') ?></td>
                        <td>%<?= number_format($orderWasteRate, 1, ',', '.') ?></td>
                        <td><?= number_format((float) $waste['waste_cost'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$wasteOrderSummary): ?>
                    <tr><td colspan="6">Emir bazli fire ozeti yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Planlama Takvimi Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Baslangic</th><th>Bitis</th><th>Emir</th><th>Is Merkezi</th><th>Sure</th><th>Oncelik</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($scheduleRows as $schedule): ?>
                    <tr>
                        <td><?= app_h($schedule['planned_start']) ?></td>
                        <td><?= app_h($schedule['planned_end']) ?></td>
                        <td><small><?= app_h($schedule['order_no'] . ' / ' . $schedule['product_name']) ?><br><?= app_h((string) ($schedule['batch_no'] ?: '-')) ?></small></td>
                        <td><?= app_h($schedule['work_center_code'] ? ($schedule['work_center_code'] . ' / ' . $schedule['work_center_name']) : '-') ?></td>
                        <td><?= (int) $schedule['planned_minutes'] ?> dk</td>
                        <td><?= app_h($schedule['priority']) ?></td>
                        <td><?= app_h($schedule['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$scheduleRows): ?>
                    <tr><td colspan="7">Henuz planlama takvimi kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Uretim Emirleri</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_order_status">
            <select name="bulk_order_status">
                <option value="planlandi">Planlandi</option>
                <option value="uretimde">Uretimde</option>
                <option value="tamamlandi">Tamamlandi</option>
                <option value="iptal">Iptal</option>
            </select>
            <button type="submit">Secili Emirleri Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.production-order-check').forEach((el)=>el.checked=this.checked)"></th><th>No</th><th>Urun</th><th>Plan</th><th>Gerceklesen</th><th>Durum</th><th>Evrak</th><th>Guncelle</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><input class="production-order-check" type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>"></td>
                        <td><?= app_h($order['order_no']) ?></td>
                        <td><?= app_h($order['product_name']) ?></td>
                        <td><?= number_format((float) $order['planned_quantity'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) ($order['actual_quantity'] ?? 0), 3, ',', '.') ?></td>
                        <td><?= app_h($order['status']) ?></td>
                        <td>
                            <div class="stack">
                                <a href="production_detail.php?id=<?= (int) $order['id'] ?>">Detay</a>
                                <a href="index.php?module=evrak&filter_module=uretim&filter_related_table=production_orders&filter_related_id=<?= (int) $order['id'] ?>&prefill_module=uretim&prefill_related_table=production_orders&prefill_related_id=<?= (int) $order['id'] ?>">
                                    Evrak (<?= (int) ($orderDocCounts[(int) $order['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('uretim', 'production_orders', (int) $order['id'], 'index.php?module=uretim')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="production_order_id" value="<?= (int) $order['id'] ?>">
                                <select name="status">
                                    <option value="planlandi" <?= $order['status'] === 'planlandi' ? 'selected' : '' ?>>Planlandi</option>
                                    <option value="uretimde" <?= $order['status'] === 'uretimde' ? 'selected' : '' ?>>Uretimde</option>
                                    <option value="tamamlandi" <?= $order['status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                                    <option value="iptal" <?= $order['status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
                                </select>
                                <input type="number" step="0.001" name="actual_quantity" value="<?= app_h((string) ($order['actual_quantity'] ?? '')) ?>" placeholder="Gerceklesen">
                                <button type="submit">Kaydet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
    </div>

    <div class="card">
        <h3>Sarf Hareketleri</h3>
        <h3 style="margin-top:0;">Operasyon Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Sira</th><th>Is Merkezi</th><th>Operasyon</th><th>Durum</th><th>Sorumlu</th><th>Sure</th><th>Zaman</th></tr></thead>
                <tbody>
                <?php foreach ($operations as $operation): ?>
                    <tr>
                        <td><?= app_h($operation['order_no'] . ' / ' . $operation['product_name']) ?></td>
                        <td><?= (int) $operation['sequence_no'] ?></td>
                        <td><?= app_h($operation['work_center_code'] ? ($operation['work_center_code'] . ' / ' . $operation['work_center_name']) : '-') ?></td>
                        <td><small><?= app_h($operation['operation_name']) ?><br><?= app_h((string) ($operation['notes'] ?: '-')) ?></small></td>
                        <td><?= app_h($operation['status']) ?></td>
                        <td><?= app_h((string) ($operation['responsible_name'] ?: '-')) ?></td>
                        <td><small>Plan: <?= (int) $operation['planned_minutes'] ?> dk<br>Gercek: <?= (int) $operation['actual_minutes'] ?> dk</small></td>
                        <td><small>Bas: <?= app_h((string) ($operation['started_at'] ?: '-')) ?><br>Bit: <?= app_h((string) ($operation['finished_at'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$operations): ?>
                    <tr><td colspan="8">Henuz operasyon hareketi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3 style="margin-top:18px;">Sarf Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Urun</th><th>Depo</th><th>Miktar</th></tr></thead>
                <tbody>
                <?php foreach ($consumptions as $item): ?>
                    <tr>
                        <td><?= app_h($item['order_no']) ?></td>
                        <td><?= app_h($item['product_name']) ?></td>
                        <td><?= app_h($item['warehouse_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Uretim Ciktilari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Emir</th><th>Urun</th><th>Depo</th><th>Miktar</th><th>Barkod</th></tr></thead>
                <tbody>
                <?php foreach ($outputs as $item): ?>
                    <tr>
                        <td><?= app_h($item['order_no']) ?></td>
                        <td><?= app_h($item['product_name']) ?></td>
                        <td><?= app_h($item['warehouse_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?></td>
                        <td><?= app_h($item['barcode'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Uretim Kurallari</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Recete Onayi</strong><span>Receteler taslak, onayli ve pasif durumlariyla izlenir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Sarf Entegrasyonu</strong><span>Sarf kaydi girdiginde depodan otomatik `uretim_cikis` stok hareketi olusur.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Cikti Entegrasyonu</strong><span>Uretim ciktisi kaydedildiginde depoya `uretim_giris` hareketi islenir ve emir tamamlanir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Koruma</strong><span>Stoga islenmis ciktisi olan emir eski durumlara geri cekilemez.</span></div><div class="warn">Koruma</div></div>
        </div>
    </div>
</section>
