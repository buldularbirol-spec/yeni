<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Stok modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function stock_next_sku(PDO $db): string
{
    return app_document_series_number($db, 'docs.stock_series', 'docs.stock_prefix', 'STK', 'stock_products', 'created_at');
}

function stock_post_redirect(string $result): void
{
    app_redirect('index.php?module=stok&ok=' . urlencode($result));
}

function stock_csv_download(string $filename, array $headers, array $rows): void
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

function stock_safe_upload_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?: 'urun-gorseli';
    $base = trim((string) $base, '-_') ?: 'urun-gorseli';

    return strtolower($base) . '-' . date('YmdHis') . '-' . substr(sha1((string) microtime(true)), 0, 8) . '.' . $extension;
}

function stock_ensure_reservation_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_reservations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            warehouse_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            quantity DECIMAL(18,3) NOT NULL,
            reserved_for VARCHAR(200) NULL,
            reference_type VARCHAR(80) NULL,
            reference_no VARCHAR(120) NULL,
            status ENUM('aktif','tamamlandi','iptal') NOT NULL DEFAULT 'aktif',
            reserved_until DATE NULL,
            notes TEXT NULL,
            created_by BIGINT NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_stock_reservation_status (status, reserved_until),
            KEY idx_stock_reservation_product (product_id, warehouse_id),
            KEY idx_stock_reservation_reference (reference_type, reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function stock_ensure_wastage_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_wastages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            warehouse_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            movement_id BIGINT NULL,
            quantity DECIMAL(18,3) NOT NULL,
            unit_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
            total_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
            reason_code VARCHAR(80) NOT NULL,
            reason_text VARCHAR(200) NULL,
            lot_no VARCHAR(100) NULL,
            serial_no VARCHAR(100) NULL,
            wastage_date DATETIME NOT NULL,
            notes TEXT NULL,
            created_by BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_stock_wastage_product (product_id, warehouse_id),
            KEY idx_stock_wastage_date (wastage_date),
            KEY idx_stock_wastage_reason (reason_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function stock_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE :column_name');
    $stmt->execute(['column_name' => $column]);

    return (bool) $stmt->fetch();
}

function stock_ensure_location_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_locations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            warehouse_id BIGINT NOT NULL,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            aisle VARCHAR(80) NULL,
            rack VARCHAR(80) NULL,
            shelf VARCHAR(80) NULL,
            bin_code VARCHAR(80) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stock_location_code (warehouse_id, code),
            KEY idx_stock_location_status (status),
            KEY idx_stock_location_warehouse (warehouse_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach (['stock_movements', 'stock_reservations', 'stock_wastages'] as $table) {
        if (!stock_column_exists($db, $table, 'location_id')) {
            $db->exec('ALTER TABLE `' . $table . '` ADD location_id BIGINT NULL AFTER warehouse_id');
        }
    }
}

function stock_ensure_variant_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS stock_product_variants (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            variant_code VARCHAR(120) NOT NULL,
            variant_name VARCHAR(200) NOT NULL,
            barcode VARCHAR(120) NULL,
            color VARCHAR(80) NULL,
            size_label VARCHAR(80) NULL,
            model_label VARCHAR(120) NULL,
            purchase_price DECIMAL(18,4) NULL,
            sale_price DECIMAL(18,4) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stock_variant_code (product_id, variant_code),
            KEY idx_stock_variant_product (product_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach (['stock_reservations', 'stock_wastages'] as $table) {
        if (!stock_column_exists($db, $table, 'variant_id')) {
            $db->exec('ALTER TABLE `' . $table . '` ADD variant_id BIGINT NULL AFTER product_id');
        }
    }

    $variantColumns = [
        'variant_code' => 'VARCHAR(120) NOT NULL DEFAULT ""',
        'variant_name' => 'VARCHAR(200) NOT NULL DEFAULT ""',
        'barcode' => 'VARCHAR(120) NULL',
        'color' => 'VARCHAR(80) NULL',
        'size_label' => 'VARCHAR(80) NULL',
        'model_label' => 'VARCHAR(120) NULL',
        'purchase_price' => 'DECIMAL(18,4) NULL',
        'sale_price' => 'DECIMAL(18,4) NULL',
        'status' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($variantColumns as $column => $definition) {
        if (!stock_column_exists($db, 'stock_product_variants', $column)) {
            $db->exec('ALTER TABLE stock_product_variants ADD `' . $column . '` ' . $definition);
        }
    }

    $db->exec('UPDATE stock_product_variants SET variant_code = CONCAT("VAR-", id) WHERE variant_code = ""');
    $db->exec('UPDATE stock_product_variants SET variant_name = variant_code WHERE variant_name = ""');
}

function stock_ensure_product_image_schema(PDO $db): void
{
    if (!stock_column_exists($db, 'stock_products', 'image_path')) {
        $db->exec('ALTER TABLE stock_products ADD image_path VARCHAR(255) NULL AFTER barcode');
    }
}

function stock_store_product_image(array $file): string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Urun gorseli yuklenemedi.');
    }

    $extension = app_validate_uploaded_file($file, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $targetDir = dirname(__DIR__) . '/uploads/products';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Urun gorseli klasoru olusturulamadi.');
    }
    app_ensure_upload_protection($targetDir);

    $safeName = stock_safe_upload_name((string) ($file['name'] ?? ('product.' . $extension)));
    $targetPath = $targetDir . '/' . $safeName;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Urun gorseli kaydedilemedi.');
    }

    return '/muhasebe1/uploads/products/' . $safeName;
}

function stock_current_quantity(PDO $db, int $warehouseId, int $productId): float
{
    return (float) app_metric($db, '
        SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ("giris","transfer","sayim","uretim_giris") THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN quantity ELSE 0 END), 0)
        FROM stock_movements
        WHERE warehouse_id = :warehouse_id AND product_id = :product_id
    ', [
        'warehouse_id' => $warehouseId,
        'product_id' => $productId,
    ]);
}

function stock_variant_current_quantity(PDO $db, int $warehouseId, int $productId, int $variantId): float
{
    return (float) app_metric($db, '
        SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ("giris","transfer","sayim","uretim_giris") THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN quantity ELSE 0 END), 0)
        FROM stock_movements
        WHERE warehouse_id = :warehouse_id AND product_id = :product_id AND variant_id = :variant_id
    ', [
        'warehouse_id' => $warehouseId,
        'product_id' => $productId,
        'variant_id' => $variantId,
    ]);
}

function stock_reserved_quantity(PDO $db, int $warehouseId, int $productId): float
{
    return (float) app_metric($db, '
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_reservations
        WHERE warehouse_id = :warehouse_id AND product_id = :product_id AND status = "aktif"
    ', [
        'warehouse_id' => $warehouseId,
        'product_id' => $productId,
    ]);
}

function stock_variant_reserved_quantity(PDO $db, int $warehouseId, int $productId, int $variantId): float
{
    return (float) app_metric($db, '
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_reservations
        WHERE warehouse_id = :warehouse_id AND product_id = :product_id AND variant_id = :variant_id AND status = "aktif"
    ', [
        'warehouse_id' => $warehouseId,
        'product_id' => $productId,
        'variant_id' => $variantId,
    ]);
}

function stock_location_current_quantity(PDO $db, int $locationId, int $productId): float
{
    return (float) app_metric($db, '
        SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ("giris","transfer","sayim","uretim_giris") THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN quantity ELSE 0 END), 0)
        FROM stock_movements
        WHERE location_id = :location_id AND product_id = :product_id
    ', [
        'location_id' => $locationId,
        'product_id' => $productId,
    ]);
}

function stock_location_variant_current_quantity(PDO $db, int $locationId, int $productId, int $variantId): float
{
    return (float) app_metric($db, '
        SELECT
            COALESCE(SUM(CASE WHEN movement_type IN ("giris","transfer","sayim","uretim_giris") THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN quantity ELSE 0 END), 0)
        FROM stock_movements
        WHERE location_id = :location_id AND product_id = :product_id AND variant_id = :variant_id
    ', [
        'location_id' => $locationId,
        'product_id' => $productId,
        'variant_id' => $variantId,
    ]);
}

function stock_location_reserved_quantity(PDO $db, int $locationId, int $productId): float
{
    return (float) app_metric($db, '
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_reservations
        WHERE location_id = :location_id AND product_id = :product_id AND status = "aktif"
    ', [
        'location_id' => $locationId,
        'product_id' => $productId,
    ]);
}

function stock_location_variant_reserved_quantity(PDO $db, int $locationId, int $productId, int $variantId): float
{
    return (float) app_metric($db, '
        SELECT COALESCE(SUM(quantity), 0)
        FROM stock_reservations
        WHERE location_id = :location_id AND product_id = :product_id AND variant_id = :variant_id AND status = "aktif"
    ', [
        'location_id' => $locationId,
        'product_id' => $productId,
        'variant_id' => $variantId,
    ]);
}

function stock_age_band(?int $ageDays): string
{
    if ($ageDays === null) {
        return 'Giris yok';
    }

    if ($ageDays <= 30) {
        return '0-30 gun';
    }

    if ($ageDays <= 90) {
        return '31-90 gun';
    }

    if ($ageDays <= 180) {
        return '91-180 gun';
    }

    if ($ageDays <= 365) {
        return '181-365 gun';
    }

    return '365+ gun';
}

function stock_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'product_type' => trim((string) ($_GET['product_type'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'product_sort' => trim((string) ($_GET['product_sort'] ?? 'id_desc')),
        'level_sort' => trim((string) ($_GET['level_sort'] ?? 'stock_asc')),
        'product_page' => max(1, (int) ($_GET['product_page'] ?? 1)),
        'level_page' => max(1, (int) ($_GET['level_page'] ?? 1)),
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';
stock_ensure_reservation_schema($db);
stock_ensure_wastage_schema($db);
stock_ensure_location_schema($db);
stock_ensure_variant_schema($db);
stock_ensure_product_image_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_category') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Kategori adi zorunludur.');
            }

            $stmt = $db->prepare('INSERT INTO stock_categories (parent_id, name, status) VALUES (:parent_id, :name, 1)');
            $stmt->execute([
                'parent_id' => (int) ($_POST['parent_id'] ?? 0) ?: null,
                'name' => $name,
            ]);

            stock_post_redirect('category');
        }

        if ($action === 'create_warehouse') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Depo adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO stock_warehouses (branch_id, name, location_text, status)
                VALUES (:branch_id, :name, :location_text, 1)
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'name' => $name,
                'location_text' => trim((string) ($_POST['location_text'] ?? '')) ?: null,
            ]);

            stock_post_redirect('warehouse');
        }

        if ($action === 'create_location') {
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($warehouseId <= 0 || $code === '' || $name === '') {
                throw new RuntimeException('Depo, lokasyon kodu ve lokasyon adi zorunludur.');
            }
            app_assert_branch_access($db, 'stock_warehouses', $warehouseId);

            $stmt = $db->prepare('
                INSERT INTO stock_locations (warehouse_id, code, name, aisle, rack, shelf, bin_code, notes, status)
                VALUES (:warehouse_id, :code, :name, :aisle, :rack, :shelf, :bin_code, :notes, 1)
            ');
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => $name,
                'aisle' => trim((string) ($_POST['aisle'] ?? '')) ?: null,
                'rack' => trim((string) ($_POST['rack'] ?? '')) ?: null,
                'shelf' => trim((string) ($_POST['shelf'] ?? '')) ?: null,
                'bin_code' => trim((string) ($_POST['bin_code'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);

            stock_post_redirect('location');
        }

        if ($action === 'create_product') {
            $name = trim((string) ($_POST['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Urun adi zorunludur.');
            }

            $sku = trim((string) ($_POST['sku'] ?? ''));
            if ($sku === '') {
                $sku = stock_next_sku($db);
            }

            $imagePath = null;
            if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int) ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imagePath = stock_store_product_image($_FILES['image_file']);
            }

            $stmt = $db->prepare('
                INSERT INTO stock_products (
                    category_id, product_type, sku, barcode, image_path, name, unit, vat_rate, purchase_price, sale_price, critical_stock, track_lot, track_serial, status
                ) VALUES (
                    :category_id, :product_type, :sku, :barcode, :image_path, :name, :unit, :vat_rate, :purchase_price, :sale_price, :critical_stock, :track_lot, :track_serial, 1
                )
            ');
            $stmt->execute([
                'category_id' => (int) ($_POST['category_id'] ?? 0) ?: null,
                'product_type' => $_POST['product_type'] ?? 'ticari',
                'sku' => $sku,
                'barcode' => trim((string) ($_POST['barcode'] ?? '')) ?: null,
                'image_path' => $imagePath,
                'name' => $name,
                'unit' => trim((string) ($_POST['unit'] ?? 'adet')) ?: 'adet',
                'vat_rate' => (float) ($_POST['vat_rate'] ?? 20),
                'purchase_price' => (float) ($_POST['purchase_price'] ?? 0),
                'sale_price' => (float) ($_POST['sale_price'] ?? 0),
                'critical_stock' => (float) ($_POST['critical_stock'] ?? 0),
                'track_lot' => isset($_POST['track_lot']) ? 1 : 0,
                'track_serial' => isset($_POST['track_serial']) ? 1 : 0,
            ]);

            stock_post_redirect('product');
        }

        if ($action === 'upload_product_image') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0 || !isset($_FILES['image_file']) || !is_array($_FILES['image_file'])) {
                throw new RuntimeException('Gorsel icin gecerli urun ve dosya secilmelidir.');
            }

            $imagePath = stock_store_product_image($_FILES['image_file']);
            $stmt = $db->prepare('UPDATE stock_products SET image_path = :image_path WHERE id = :id');
            $stmt->execute([
                'image_path' => $imagePath,
                'id' => $productId,
            ]);

            stock_post_redirect('image');
        }

        if ($action === 'bulk_update_products') {
            $productIds = $_POST['product_ids'] ?? [];
            if (!is_array($productIds)) {
                $productIds = [];
            }

            $productIds = array_values(array_filter(array_unique(array_map('intval', $productIds)), static fn(int $id): bool => $id > 0));
            if ($productIds === []) {
                throw new RuntimeException('Toplu guncelleme icin en az bir urun secilmelidir.');
            }

            $updates = [];
            $params = [];

            if (trim((string) ($_POST['category_id'] ?? '')) !== '') {
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $updates[] = 'category_id = :category_id';
                $params['category_id'] = $categoryId > 0 ? $categoryId : null;
            }

            if (trim((string) ($_POST['product_type'] ?? '')) !== '') {
                $updates[] = 'product_type = :product_type';
                $params['product_type'] = trim((string) $_POST['product_type']);
            }

            if (trim((string) ($_POST['status'] ?? '')) !== '') {
                $updates[] = 'status = :status';
                $params['status'] = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;
            }

            if (trim((string) ($_POST['vat_rate'] ?? '')) !== '') {
                $updates[] = 'vat_rate = :vat_rate';
                $params['vat_rate'] = (float) $_POST['vat_rate'];
            }

            if (trim((string) ($_POST['critical_stock'] ?? '')) !== '') {
                $updates[] = 'critical_stock = :critical_stock';
                $params['critical_stock'] = (float) $_POST['critical_stock'];
            }

            if (trim((string) ($_POST['unit'] ?? '')) !== '') {
                $updates[] = 'unit = :unit';
                $params['unit'] = trim((string) $_POST['unit']);
            }

            if ($updates === []) {
                throw new RuntimeException('Toplu guncelleme icin en az bir alan doldurulmalidir.');
            }

            $placeholders = [];
            foreach ($productIds as $index => $productId) {
                $key = 'id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $productId;
            }

            $stmt = $db->prepare('UPDATE stock_products SET ' . implode(', ', $updates) . ' WHERE id IN (' . implode(',', $placeholders) . ')');
            $stmt->execute($params);

            stock_post_redirect('bulk_update:' . $stmt->rowCount());
        }

        if ($action === 'bulk_update_prices') {
            $productIds = $_POST['product_ids'] ?? [];
            if (!is_array($productIds)) {
                $productIds = [];
            }

            $productIds = array_values(array_filter(array_unique(array_map('intval', $productIds)), static fn(int $id): bool => $id > 0));
            $priceMode = trim((string) ($_POST['price_mode'] ?? ''));
            $priceTarget = trim((string) ($_POST['price_target'] ?? ''));
            $priceValue = (float) ($_POST['price_value'] ?? 0);

            if ($productIds === [] || !in_array($priceMode, ['set', 'percent', 'amount'], true) || !in_array($priceTarget, ['purchase', 'sale', 'both'], true)) {
                throw new RuntimeException('Toplu fiyat guncelleme icin urun, hedef ve yontem secilmelidir.');
            }

            if ($priceMode === 'set' && $priceValue < 0) {
                throw new RuntimeException('Sabit fiyat negatif olamaz.');
            }

            $setParts = [];
            $params = ['price_value' => $priceValue];

            $targets = [];
            if ($priceTarget === 'purchase' || $priceTarget === 'both') {
                $targets[] = 'purchase_price';
            }
            if ($priceTarget === 'sale' || $priceTarget === 'both') {
                $targets[] = 'sale_price';
            }

            foreach ($targets as $column) {
                if ($priceMode === 'set') {
                    $setParts[] = $column . ' = :price_value';
                } elseif ($priceMode === 'percent') {
                    $setParts[] = $column . ' = GREATEST(0, ROUND(' . $column . ' * (1 + (:price_value / 100)), 4))';
                } else {
                    $setParts[] = $column . ' = GREATEST(0, ROUND(' . $column . ' + :price_value, 4))';
                }
            }

            $placeholders = [];
            foreach ($productIds as $index => $productId) {
                $key = 'id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $productId;
            }

            $stmt = $db->prepare('UPDATE stock_products SET ' . implode(', ', $setParts) . ' WHERE id IN (' . implode(',', $placeholders) . ')');
            $stmt->execute($params);

            stock_post_redirect('bulk_price:' . $stmt->rowCount());
        }

        if ($action === 'create_product_variant') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variantCode = trim((string) ($_POST['variant_code'] ?? ''));
            $variantName = trim((string) ($_POST['variant_name'] ?? ''));

            if ($productId <= 0 || $variantCode === '' || $variantName === '') {
                throw new RuntimeException('Urun, varyant kodu ve varyant adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO stock_product_variants (
                    product_id, variant_code, variant_name, barcode, color, size_label, model_label, purchase_price, sale_price, status
                ) VALUES (
                    :product_id, :variant_code, :variant_name, :barcode, :color, :size_label, :model_label, :purchase_price, :sale_price, 1
                )
            ');
            $stmt->execute([
                'product_id' => $productId,
                'variant_code' => $variantCode,
                'variant_name' => $variantName,
                'barcode' => trim((string) ($_POST['barcode'] ?? '')) ?: null,
                'color' => trim((string) ($_POST['color'] ?? '')) ?: null,
                'size_label' => trim((string) ($_POST['size_label'] ?? '')) ?: null,
                'model_label' => trim((string) ($_POST['model_label'] ?? '')) ?: null,
                'purchase_price' => trim((string) ($_POST['purchase_price'] ?? '')) === '' ? null : (float) $_POST['purchase_price'],
                'sale_price' => trim((string) ($_POST['sale_price'] ?? '')) === '' ? null : (float) $_POST['sale_price'],
            ]);

            stock_post_redirect('variant');
        }

        if ($action === 'create_stock_movement') {
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);

            if ($warehouseId <= 0 || $productId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Depo, urun ve miktar alanlari zorunludur.');
            }
            app_assert_branch_access($db, 'stock_warehouses', $warehouseId);

            $stmt = $db->prepare('
                INSERT INTO stock_movements (
                    warehouse_id, location_id, product_id, variant_id, movement_type, quantity, unit_cost, lot_no, serial_no, reference_type, reference_id, movement_date
                ) VALUES (
                    :warehouse_id, :location_id, :product_id, :variant_id, :movement_type, :quantity, :unit_cost, :lot_no, :serial_no, :reference_type, NULL, :movement_date
                )
            ');
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'location_id' => (int) ($_POST['location_id'] ?? 0) ?: null,
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'movement_type' => $_POST['movement_type'] ?? 'giris',
                'quantity' => $quantity,
                'unit_cost' => (float) ($_POST['unit_cost'] ?? 0),
                'lot_no' => trim((string) ($_POST['lot_no'] ?? '')) ?: null,
                'serial_no' => trim((string) ($_POST['serial_no'] ?? '')) ?: null,
                'reference_type' => trim((string) ($_POST['reference_type'] ?? 'manuel')) ?: 'manuel',
                'movement_date' => date('Y-m-d H:i:s'),
            ]);

            stock_post_redirect('movement');
        }

        if ($action === 'create_stock_reservation') {
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);

            if ($warehouseId <= 0 || $productId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Depo, urun ve rezerv miktari zorunludur.');
            }
            app_assert_branch_access($db, 'stock_warehouses', $warehouseId);

            $available = stock_current_quantity($db, $warehouseId, $productId) - stock_reserved_quantity($db, $warehouseId, $productId);
            if ($locationId > 0) {
                $available = stock_location_current_quantity($db, $locationId, $productId) - stock_location_reserved_quantity($db, $locationId, $productId);
            }
            if ($variantId > 0) {
                $available = $locationId > 0
                    ? stock_location_variant_current_quantity($db, $locationId, $productId, $variantId) - stock_location_variant_reserved_quantity($db, $locationId, $productId, $variantId)
                    : stock_variant_current_quantity($db, $warehouseId, $productId, $variantId) - stock_variant_reserved_quantity($db, $warehouseId, $productId, $variantId);
            }
            if ($quantity > $available) {
                throw new RuntimeException('Rezerv miktari kullanilabilir stoktan fazla olamaz.');
            }

            $stmt = $db->prepare('
                INSERT INTO stock_reservations (
                    warehouse_id, location_id, product_id, variant_id, quantity, reserved_for, reference_type, reference_no, reserved_until, notes, created_by
                ) VALUES (
                    :warehouse_id, :location_id, :product_id, :variant_id, :quantity, :reserved_for, :reference_type, :reference_no, :reserved_until, :notes, :created_by
                )
            ');
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId ?: null,
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'quantity' => $quantity,
                'reserved_for' => trim((string) ($_POST['reserved_for'] ?? '')) ?: null,
                'reference_type' => trim((string) ($_POST['reference_type'] ?? 'manuel')) ?: 'manuel',
                'reference_no' => trim((string) ($_POST['reference_no'] ?? '')) ?: null,
                'reserved_until' => trim((string) ($_POST['reserved_until'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => (int) ($authUser['id'] ?? 0) ?: null,
            ]);

            stock_post_redirect('reservation');
        }

        if ($action === 'update_stock_reservation_status') {
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? ''));

            if ($reservationId <= 0 || !in_array($status, ['tamamlandi', 'iptal'], true)) {
                throw new RuntimeException('Gecerli bir rezervasyon ve durum secilmedi.');
            }

            $dateField = $status === 'tamamlandi' ? 'completed_at' : 'cancelled_at';
            $stmt = $db->prepare("
                UPDATE stock_reservations
                SET status = :status, {$dateField} = :status_date
                WHERE id = :id AND status = 'aktif'
            ");
            $stmt->execute([
                'status' => $status,
                'status_date' => date('Y-m-d H:i:s'),
                'id' => $reservationId,
            ]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Sadece aktif rezervasyonlar guncellenebilir.');
            }

            stock_post_redirect('reservation_' . $status);
        }

        if ($action === 'create_stock_wastage') {
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $unitCost = (float) ($_POST['unit_cost'] ?? 0);
            $reasonCode = trim((string) ($_POST['reason_code'] ?? ''));

            if ($warehouseId <= 0 || $productId <= 0 || $quantity <= 0 || $reasonCode === '') {
                throw new RuntimeException('Depo, urun, miktar ve fire nedeni zorunludur.');
            }
            app_assert_branch_access($db, 'stock_warehouses', $warehouseId);

            $available = stock_current_quantity($db, $warehouseId, $productId) - stock_reserved_quantity($db, $warehouseId, $productId);
            if ($locationId > 0) {
                $available = stock_location_current_quantity($db, $locationId, $productId) - stock_location_reserved_quantity($db, $locationId, $productId);
            }
            if ($variantId > 0) {
                $available = $locationId > 0
                    ? stock_location_variant_current_quantity($db, $locationId, $productId, $variantId) - stock_location_variant_reserved_quantity($db, $locationId, $productId, $variantId)
                    : stock_variant_current_quantity($db, $warehouseId, $productId, $variantId) - stock_variant_reserved_quantity($db, $warehouseId, $productId, $variantId);
            }
            if ($quantity > $available) {
                throw new RuntimeException('Fire miktari kullanilabilir stoktan fazla olamaz.');
            }

            if ($unitCost <= 0) {
                $unitCost = (float) app_metric($db, '
                    SELECT COALESCE(AVG(NULLIF(unit_cost, 0)), 0)
                    FROM stock_movements
                    WHERE product_id = :product_id
                      AND warehouse_id = :warehouse_id
                      AND movement_type IN ("giris","transfer","sayim","uretim_giris")
                ', [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                ]);
            }

            $totalCost = $quantity * $unitCost;
            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO stock_movements (
                    warehouse_id, location_id, product_id, variant_id, movement_type, quantity, unit_cost, lot_no, serial_no, reference_type, reference_id, movement_date
                ) VALUES (
                    :warehouse_id, :location_id, :product_id, :variant_id, "fire_zayiat", :quantity, :unit_cost, :lot_no, :serial_no, "fire_zayiat", NULL, :movement_date
                )
            ');
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId ?: null,
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'lot_no' => trim((string) ($_POST['lot_no'] ?? '')) ?: null,
                'serial_no' => trim((string) ($_POST['serial_no'] ?? '')) ?: null,
                'movement_date' => date('Y-m-d H:i:s'),
            ]);
            $movementId = (int) $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO stock_wastages (
                    warehouse_id, location_id, product_id, variant_id, movement_id, quantity, unit_cost, total_cost, reason_code, reason_text,
                    lot_no, serial_no, wastage_date, notes, created_by
                ) VALUES (
                    :warehouse_id, :location_id, :product_id, :variant_id, :movement_id, :quantity, :unit_cost, :total_cost, :reason_code, :reason_text,
                    :lot_no, :serial_no, :wastage_date, :notes, :created_by
                )
            ');
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId ?: null,
                'product_id' => $productId,
                'variant_id' => $variantId ?: null,
                'movement_id' => $movementId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'reason_code' => $reasonCode,
                'reason_text' => trim((string) ($_POST['reason_text'] ?? '')) ?: null,
                'lot_no' => trim((string) ($_POST['lot_no'] ?? '')) ?: null,
                'serial_no' => trim((string) ($_POST['serial_no'] ?? '')) ?: null,
                'wastage_date' => date('Y-m-d H:i:s'),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => (int) ($authUser['id'] ?? 0) ?: null,
            ]);

            $db->commit();
            stock_post_redirect('wastage');
        }

        if ($action === 'update_product_status') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

            if ($productId <= 0) {
                throw new RuntimeException('Gecerli bir urun secilmedi.');
            }

            $stmt = $db->prepare('UPDATE stock_products SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => $status,
                'id' => $productId,
            ]);

            stock_post_redirect($status === 1 ? 'product_enabled' : 'product_disabled');
        }

        if ($action === 'delete_category') {
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($categoryId <= 0) {
                throw new RuntimeException('Gecerli bir kategori secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM stock_categories WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);

            stock_post_redirect('delete_category');
        }

        if ($action === 'delete_warehouse') {
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);

            if ($warehouseId <= 0) {
                throw new RuntimeException('Gecerli bir depo secilmedi.');
            }
            app_assert_branch_access($db, 'stock_warehouses', $warehouseId);

            $stmt = $db->prepare('DELETE FROM stock_warehouses WHERE id = :id');
            $stmt->execute(['id' => $warehouseId]);

            stock_post_redirect('delete_warehouse');
        }

        if ($action === 'delete_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);

            if ($productId <= 0) {
                throw new RuntimeException('Gecerli bir urun secilmedi.');
            }

            $stmt = $db->prepare('DELETE FROM stock_products WHERE id = :id');
            $stmt->execute(['id' => $productId]);

            stock_post_redirect('delete_product');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $feedback = 'error:Stok islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = stock_build_filters();
[$stockWarehouseScopeWhere, $stockWarehouseScopeParams] = app_branch_scope_filter($db, null, 'w');
[$stockWarehousePlainScopeWhere, $stockWarehousePlainScopeParams] = app_branch_scope_filter($db, null);

$categories = app_fetch_all($db, 'SELECT id, parent_id, name FROM stock_categories ORDER BY id DESC LIMIT 100');
$warehouses = app_fetch_all($db, 'SELECT id, name, location_text, created_at FROM stock_warehouses ' . ($stockWarehousePlainScopeWhere !== '' ? 'WHERE ' . $stockWarehousePlainScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $stockWarehousePlainScopeParams);
$locations = app_fetch_all($db, '
    SELECT l.*, w.name AS warehouse_name
    FROM stock_locations l
    INNER JOIN stock_warehouses w ON w.id = l.warehouse_id
    WHERE l.status = 1 ' . ($stockWarehouseScopeWhere !== '' ? 'AND ' . $stockWarehouseScopeWhere : '') . '
    ORDER BY w.name ASC, l.code ASC
    LIMIT 200
', $stockWarehouseScopeParams);
$productOptions = app_fetch_all($db, 'SELECT id, sku, name, unit FROM stock_products WHERE status = 1 ORDER BY name ASC LIMIT 300');
$bulkProductOptions = app_fetch_all($db, 'SELECT id, sku, name, status FROM stock_products ORDER BY name ASC LIMIT 500');
$variantOptions = app_fetch_all($db, '
    SELECT v.id, v.product_id, v.variant_code, v.variant_name, v.color, v.size_label, v.model_label, p.name AS product_name, p.sku
    FROM stock_product_variants v
    INNER JOIN stock_products p ON p.id = v.product_id
    WHERE v.status = 1
    ORDER BY p.name ASC, v.variant_code ASC
    LIMIT 300
');
$productWhere = [];
$productParams = [];

if ($filters['search'] !== '') {
    $productWhere[] = '(p.sku LIKE :search OR p.name LIKE :search OR c.name LIKE :search)';
    $productParams['search'] = '%' . $filters['search'] . '%';
}

if ($filters['product_type'] !== '') {
    $productWhere[] = 'p.product_type = :product_type';
    $productParams['product_type'] = $filters['product_type'];
}

if ($filters['status'] !== '') {
    $productWhere[] = 'p.status = :status';
    $productParams['status'] = (int) $filters['status'];
}

$productWhereSql = $productWhere ? 'WHERE ' . implode(' AND ', $productWhere) : '';

$products = app_fetch_all($db, 'SELECT p.id, p.sku, p.name, p.product_type, p.unit, p.sale_price, p.purchase_price, p.critical_stock, p.status, p.image_path, c.name AS category_name FROM stock_products p LEFT JOIN stock_categories c ON c.id = p.category_id ' . $productWhereSql . ' ORDER BY p.id DESC LIMIT 100', $productParams);
$variants = app_fetch_all($db, '
    SELECT v.*, p.name AS product_name, p.sku
    FROM stock_product_variants v
    INNER JOIN stock_products p ON p.id = v.product_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    ORDER BY v.id DESC
    LIMIT 50
', $productParams);
$movements = app_fetch_all($db, '
    SELECT m.id, w.name AS warehouse_name, COALESCE(l.code, "-") AS location_code, COALESCE(l.name, "-") AS location_name,
           p.name AS product_name, p.sku, COALESCE(v.variant_code, "-") AS variant_code, COALESCE(v.variant_name, "-") AS variant_name,
           m.movement_type, m.quantity, m.unit_cost, m.lot_no, m.serial_no, m.reference_type, m.movement_date
    FROM stock_movements m
    INNER JOIN stock_warehouses w ON w.id = m.warehouse_id
    INNER JOIN stock_products p ON p.id = m.product_id
    LEFT JOIN stock_locations l ON l.id = m.location_id
    LEFT JOIN stock_product_variants v ON v.id = m.variant_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    ORDER BY m.id DESC
    LIMIT 50
', $productParams);
$stockLevels = app_fetch_all($db, '
    SELECT
        p.id,
        p.sku,
        p.name,
        p.unit,
        p.critical_stock,
        COALESCE(SUM(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.quantity ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN m.movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN m.quantity ELSE 0 END), 0) AS current_stock,
        COALESCE((
            SELECT SUM(r.quantity)
            FROM stock_reservations r
            WHERE r.product_id = p.id AND r.status = "aktif"
        ), 0) AS reserved_stock
    FROM stock_products p
    LEFT JOIN stock_movements m ON m.product_id = p.id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    GROUP BY p.id, p.sku, p.name, p.unit, p.critical_stock
    ORDER BY p.id DESC
    LIMIT 100
', $productParams);
$reservations = app_fetch_all($db, '
    SELECT r.id, r.quantity, r.reserved_for, r.reference_type, r.reference_no, r.status, r.reserved_until, r.created_at,
           w.name AS warehouse_name, COALESCE(l.code, "-") AS location_code, COALESCE(l.name, "-") AS location_name,
           p.name AS product_name, p.sku, p.unit, COALESCE(v.variant_code, "-") AS variant_code, COALESCE(v.variant_name, "-") AS variant_name
    FROM stock_reservations r
    INNER JOIN stock_warehouses w ON w.id = r.warehouse_id
    INNER JOIN stock_products p ON p.id = r.product_id
    LEFT JOIN stock_locations l ON l.id = r.location_id
    LEFT JOIN stock_product_variants v ON v.id = r.variant_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    ORDER BY FIELD(r.status, "aktif", "tamamlandi", "iptal"), r.id DESC
    LIMIT 50
', $productParams);
$wastages = app_fetch_all($db, '
    SELECT sw.id, sw.quantity, sw.unit_cost, sw.total_cost, sw.reason_code, sw.reason_text, sw.lot_no, sw.serial_no, sw.wastage_date,
           w.name AS warehouse_name, COALESCE(l.code, "-") AS location_code, COALESCE(l.name, "-") AS location_name,
           p.name AS product_name, p.sku, p.unit, COALESCE(v.variant_code, "-") AS variant_code, COALESCE(v.variant_name, "-") AS variant_name
    FROM stock_wastages sw
    INNER JOIN stock_warehouses w ON w.id = sw.warehouse_id
    INNER JOIN stock_products p ON p.id = sw.product_id
    LEFT JOIN stock_locations l ON l.id = sw.location_id
    LEFT JOIN stock_product_variants v ON v.id = sw.variant_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    ORDER BY sw.id DESC
    LIMIT 50
', $productParams);
$stockAgeRows = app_fetch_all($db, '
    SELECT
        p.id,
        p.sku,
        p.name,
        p.unit,
        w.name AS warehouse_name,
        COALESCE(SUM(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.quantity ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN m.movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN m.quantity ELSE 0 END), 0) AS current_stock,
        MIN(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.movement_date ELSE NULL END) AS first_in_date,
        MAX(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.movement_date ELSE NULL END) AS last_in_date,
        DATEDIFF(CURDATE(), DATE(MAX(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.movement_date ELSE NULL END))) AS age_days,
        COALESCE(AVG(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") AND m.unit_cost > 0 THEN m.unit_cost ELSE NULL END), p.purchase_price, 0) AS avg_unit_cost
    FROM stock_products p
    INNER JOIN stock_movements m ON m.product_id = p.id
    INNER JOIN stock_warehouses w ON w.id = m.warehouse_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    GROUP BY p.id, p.sku, p.name, p.unit, p.purchase_price, w.id, w.name
    HAVING current_stock > 0
    ORDER BY age_days DESC, current_stock DESC
    LIMIT 100
', $productParams);
$locationLevels = app_fetch_all($db, '
    SELECT
        w.name AS warehouse_name,
        COALESCE(l.code, "-") AS location_code,
        COALESCE(l.name, "Lokasyon Yok") AS location_name,
        p.sku,
        p.name AS product_name,
        COALESCE(v.variant_code, "-") AS variant_code,
        COALESCE(v.variant_name, "-") AS variant_name,
        p.unit,
        COALESCE(SUM(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.quantity ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN m.movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN m.quantity ELSE 0 END), 0) AS current_stock
    FROM stock_movements m
    INNER JOIN stock_warehouses w ON w.id = m.warehouse_id
    INNER JOIN stock_products p ON p.id = m.product_id
    LEFT JOIN stock_locations l ON l.id = m.location_id
    LEFT JOIN stock_product_variants v ON v.id = m.variant_id
    LEFT JOIN stock_categories c ON c.id = p.category_id
    ' . $productWhereSql . '
    GROUP BY w.id, w.name, l.id, l.code, l.name, p.id, p.sku, p.name, v.id, v.variant_code, v.variant_name, p.unit
    HAVING current_stock <> 0
    ORDER BY w.name ASC, location_code ASC, p.name ASC
    LIMIT 100
', $productParams);

$summary = [
    'Kategori' => app_table_count($db, 'stock_categories'),
    'Depo' => app_table_count($db, 'stock_warehouses'),
    'Raf/Lokasyon' => app_metric($db, 'SELECT COUNT(*) FROM stock_locations WHERE status = 1'),
    'Urun' => app_table_count($db, 'stock_products'),
    'Varyant' => app_metric($db, 'SELECT COUNT(*) FROM stock_product_variants WHERE status = 1'),
    'Stok Hareketi' => app_table_count($db, 'stock_movements'),
    'Aktif Rezervasyon' => app_metric($db, 'SELECT COUNT(*) FROM stock_reservations WHERE status = "aktif"'),
    'Rezerve Stok' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(quantity), 0) FROM stock_reservations WHERE status = "aktif"'), 3, ',', '.'),
    'Aylik Fire' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(quantity), 0) FROM stock_wastages WHERE DATE_FORMAT(wastage_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'), 3, ',', '.'),
    'Fire Maliyeti' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(total_cost), 0) FROM stock_wastages WHERE DATE_FORMAT(wastage_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")'), 2, ',', '.'),
    'Kritik Stok' => app_metric($db, '
        SELECT COUNT(*) FROM (
            SELECT p.id,
                COALESCE(SUM(CASE WHEN m.movement_type IN ("giris","transfer","sayim","uretim_giris") THEN m.quantity ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN m.movement_type IN ("cikis","uretim_cikis","servis_cikis","kira_cikis","fire_zayiat") THEN m.quantity ELSE 0 END), 0) AS current_stock,
                p.critical_stock
            FROM stock_products p
            LEFT JOIN stock_movements m ON m.product_id = p.id
            GROUP BY p.id, p.critical_stock
        ) x WHERE x.current_stock <= x.critical_stock
    '),
];

foreach ($stockLevels as &$stockLevel) {
    $stockLevel['available_stock'] = (float) $stockLevel['current_stock'] - (float) $stockLevel['reserved_stock'];
}
unset($stockLevel);

foreach ($stockAgeRows as &$ageRow) {
    $ageDays = $ageRow['age_days'] === null ? null : (int) $ageRow['age_days'];
    $ageRow['age_band'] = stock_age_band($ageDays);
    $ageRow['stock_value'] = (float) $ageRow['current_stock'] * (float) $ageRow['avg_unit_cost'];
}
unset($ageRow);

$export = trim((string) ($_GET['export'] ?? ''));
if ($export === 'stock_age') {
    $rows = [];
    foreach ($stockAgeRows as $row) {
        $rows[] = [
            $row['sku'],
            $row['name'],
            $row['warehouse_name'],
            number_format((float) $row['current_stock'], 3, '.', ''),
            $row['unit'],
            $row['first_in_date'] ?: '-',
            $row['last_in_date'] ?: '-',
            $row['age_days'] === null ? '-' : (string) (int) $row['age_days'],
            $row['age_band'],
            number_format((float) $row['stock_value'], 2, '.', ''),
        ];
    }

    stock_csv_download('stok-yas-raporu.csv', ['SKU', 'Urun', 'Depo', 'Mevcut', 'Birim', 'Ilk Giris', 'Son Giris', 'Yas Gun', 'Yas Bandi', 'Tahmini Deger'], $rows);
}

if ($export === 'stock_wastage') {
    $rows = [];
    foreach ($wastages as $row) {
        $rows[] = [
            $row['wastage_date'],
            $row['sku'],
            $row['product_name'],
            $row['variant_code'] . ' / ' . $row['variant_name'],
            $row['warehouse_name'],
            $row['location_code'] . ' / ' . $row['location_name'],
            number_format((float) $row['quantity'], 3, '.', ''),
            $row['unit'],
            number_format((float) $row['unit_cost'], 4, '.', ''),
            number_format((float) $row['total_cost'], 2, '.', ''),
            $row['reason_code'],
            $row['reason_text'] ?: '-',
            $row['lot_no'] ?: '-',
            $row['serial_no'] ?: '-',
        ];
    }

    stock_csv_download('fire-zayiat-raporu.csv', ['Tarih', 'SKU', 'Urun', 'Varyant', 'Depo', 'Lokasyon', 'Miktar', 'Birim', 'Birim Maliyet', 'Toplam Maliyet', 'Neden', 'Aciklama', 'Lot', 'Seri'], $rows);
}

$products = app_sort_rows($products, $filters['product_sort'], [
    'id_desc' => ['id', 'desc'],
    'name_asc' => ['name', 'asc'],
    'sku_asc' => ['sku', 'asc'],
    'sale_desc' => ['sale_price', 'desc'],
    'sale_asc' => ['sale_price', 'asc'],
]);
$stockLevels = app_sort_rows($stockLevels, $filters['level_sort'], [
    'stock_asc' => ['current_stock', 'asc'],
    'stock_desc' => ['current_stock', 'desc'],
    'critical_desc' => ['critical_stock', 'desc'],
    'name_asc' => ['name', 'asc'],
]);
$productsPagination = app_paginate_rows($products, $filters['product_page'], 10);
$stockLevelsPagination = app_paginate_rows($stockLevels, $filters['level_page'], 10);
$products = $productsPagination['items'];
$stockLevels = $stockLevelsPagination['items'];
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
    <h3>Stok Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="stok">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="SKU, urun veya kategori">
        </div>
        <div>
            <label>Urun Tipi</label>
            <select name="product_type">
                <option value="">Tum tipler</option>
                <option value="ticari" <?= $filters['product_type'] === 'ticari' ? 'selected' : '' ?>>Ticari</option>
                <option value="kiralik" <?= $filters['product_type'] === 'kiralik' ? 'selected' : '' ?>>Kiralik</option>
                <option value="hammadde" <?= $filters['product_type'] === 'hammadde' ? 'selected' : '' ?>>Hammadde</option>
                <option value="yari_mamul" <?= $filters['product_type'] === 'yari_mamul' ? 'selected' : '' ?>>Yari mamul</option>
                <option value="mamul" <?= $filters['product_type'] === 'mamul' ? 'selected' : '' ?>>Mamul</option>
                <option value="yedek_parca" <?= $filters['product_type'] === 'yedek_parca' ? 'selected' : '' ?>>Yedek parca</option>
            </select>
        </div>
        <div>
            <label>Durum</label>
            <select name="status">
                <option value="">Tum durumlar</option>
                <option value="1" <?= $filters['status'] === '1' ? 'selected' : '' ?>>Aktif</option>
                <option value="0" <?= $filters['status'] === '0' ? 'selected' : '' ?>>Pasif</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=stok" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="stok">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="product_type" value="<?= app_h($filters['product_type']) ?>">
        <input type="hidden" name="status" value="<?= app_h($filters['status']) ?>">
        <div>
            <label>Urun Siralama</label>
            <select name="product_sort">
                <option value="id_desc" <?= $filters['product_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="name_asc" <?= $filters['product_sort'] === 'name_asc' ? 'selected' : '' ?>>Urun A-Z</option>
                <option value="sku_asc" <?= $filters['product_sort'] === 'sku_asc' ? 'selected' : '' ?>>SKU A-Z</option>
                <option value="sale_desc" <?= $filters['product_sort'] === 'sale_desc' ? 'selected' : '' ?>>Satis yuksek</option>
                <option value="sale_asc" <?= $filters['product_sort'] === 'sale_asc' ? 'selected' : '' ?>>Satis dusuk</option>
            </select>
        </div>
        <div>
            <label>Stok Siralama</label>
            <select name="level_sort">
                <option value="stock_asc" <?= $filters['level_sort'] === 'stock_asc' ? 'selected' : '' ?>>Mevcut dusuk</option>
                <option value="stock_desc" <?= $filters['level_sort'] === 'stock_desc' ? 'selected' : '' ?>>Mevcut yuksek</option>
                <option value="critical_desc" <?= $filters['level_sort'] === 'critical_desc' ? 'selected' : '' ?>>Kritik yuksek</option>
                <option value="name_asc" <?= $filters['level_sort'] === 'name_asc' ? 'selected' : '' ?>>Urun A-Z</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Stok Rapor Ciktilari</h3>
    <div class="stack">
        <a href="index.php?<?= app_h(http_build_query(['module' => 'stok', 'export' => 'stock_age', 'search' => $filters['search'], 'product_type' => $filters['product_type'], 'status' => $filters['status']])) ?>">Stok Yas Raporu CSV</a>
        <a href="index.php?<?= app_h(http_build_query(['module' => 'stok', 'export' => 'stock_wastage', 'search' => $filters['search'], 'product_type' => $filters['product_type'], 'status' => $filters['status']])) ?>">Fire / Zayiat CSV</a>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kategori ve Depo Tanimlari</h3>
        <div class="stack">
            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_category">
                <div>
                    <label>Ust Kategori</label>
                    <select name="parent_id">
                        <option value="">Ana kategori</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['id'] ?>"><?= app_h($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Kategori Adi</label>
                    <input name="name" placeholder="Cihazlar / Sarf / Kozmetik">
                </div>
                <div>
                    <button type="submit">Kategori Ekle</button>
                </div>
            </form>

            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_warehouse">
                <div>
                    <label>Depo Adi</label>
                    <input name="name" placeholder="Merkez Depo">
                </div>
                <div>
                    <label>Lokasyon</label>
                    <input name="location_text" placeholder="Kat / oda / raf bilgisi">
                </div>
                <div>
                    <button type="submit">Depo Ekle</button>
                </div>
            </form>

            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_location">
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
                    <label>Lokasyon Kodu</label>
                    <input name="code" placeholder="A-01-R03" required>
                </div>
                <div>
                    <label>Lokasyon Adi</label>
                    <input name="name" placeholder="Koridor A Raf 03" required>
                </div>
                <div>
                    <label>Koridor</label>
                    <input name="aisle" placeholder="A">
                </div>
                <div>
                    <label>Raf</label>
                    <input name="rack" placeholder="R03">
                </div>
                <div>
                    <label>Goz</label>
                    <input name="bin_code" placeholder="G-12">
                </div>
                <div>
                    <button type="submit">Lokasyon Ekle</button>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kategori</th><th>Islem</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><?= app_h($category['name']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu kategori silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Depo</th><th>Lokasyon</th><th>Detay</th></tr></thead>
                    <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td><?= app_h($location['warehouse_name']) ?></td>
                            <td><?= app_h($location['code'] . ' / ' . $location['name']) ?></td>
                            <td><?= app_h(trim((string) (($location['aisle'] ?: '') . ' ' . ($location['rack'] ?: '') . ' ' . ($location['shelf'] ?: '') . ' ' . ($location['bin_code'] ?: ''))) ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$locations): ?>
                        <tr><td colspan="3">Henuz raf/lokasyon tanimi yok.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Yeni Urun Karti</h3>
        <form method="post" class="form-grid" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_product">
            <div>
                <label>Kategori</label>
                <select name="category_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"><?= app_h($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun Tipi</label>
                <select name="product_type">
                    <option value="ticari">Ticari</option>
                    <option value="kiralik">Kiralik</option>
                    <option value="hammadde">Hammadde</option>
                    <option value="yari_mamul">Yari mamul</option>
                    <option value="mamul">Mamul</option>
                    <option value="yedek_parca">Yedek parca</option>
                </select>
            </div>
            <div>
                <label>SKU</label>
                <input name="sku" placeholder="Bos birakilirsa otomatik uretilecek">
            </div>
            <div>
                <label>Barkod</label>
                <input name="barcode" placeholder="869...">
            </div>
            <div>
                <label>Urun Gorseli</label>
                <input type="file" name="image_file" accept="image/*">
            </div>
            <div class="full">
                <label>Urun Adi</label>
                <input name="name" placeholder="Profesyonel cihaz / sarf urun / kozmetik" required>
            </div>
            <div>
                <label>Birim</label>
                <input name="unit" value="adet">
            </div>
            <div>
                <label>KDV</label>
                <input name="vat_rate" type="number" step="0.01" value="20">
            </div>
            <div>
                <label>Alis Fiyati</label>
                <input name="purchase_price" type="number" step="0.01" value="0">
            </div>
            <div>
                <label>Satis Fiyati</label>
                <input name="sale_price" type="number" step="0.01" value="0">
            </div>
            <div>
                <label>Kritik Stok</label>
                <input name="critical_stock" type="number" step="0.001" value="0">
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="track_lot" value="1"> Lot takibi</label>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="track_serial" value="1"> Seri takibi</label>
            </div>
            <div class="full">
                <button type="submit">Urun Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Urun Varyantlari</h3>
        <form method="post" class="form-grid compact-form">
            <input type="hidden" name="action" value="create_product_variant">
            <div>
                <label>Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($productOptions as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Varyant Kodu</label>
                <input name="variant_code" placeholder="RENK-BEDEN" required>
            </div>
            <div>
                <label>Varyant Adi</label>
                <input name="variant_name" placeholder="Kirmizi / Large" required>
            </div>
            <div>
                <label>Barkod</label>
                <input name="barcode" placeholder="869...">
            </div>
            <div>
                <label>Renk</label>
                <input name="color" placeholder="Kirmizi">
            </div>
            <div>
                <label>Beden/Olcu</label>
                <input name="size_label" placeholder="L / 50ml">
            </div>
            <div>
                <label>Model</label>
                <input name="model_label" placeholder="Model A">
            </div>
            <div>
                <label>Satis Fiyati</label>
                <input name="sale_price" type="number" step="0.01" placeholder="Opsiyonel">
            </div>
            <div>
                <button type="submit">Varyant Ekle</button>
            </div>
        </form>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Urun</th><th>Varyant</th><th>Ozellik</th><th>Fiyat</th></tr></thead>
                <tbody>
                <?php foreach ($variants as $variant): ?>
                    <tr>
                        <td><?= app_h($variant['product_name'] . ' / ' . $variant['sku']) ?></td>
                        <td><?= app_h($variant['variant_code'] . ' / ' . $variant['variant_name']) ?></td>
                        <td><?= app_h(trim((string) (($variant['color'] ?: '') . ' ' . ($variant['size_label'] ?: '') . ' ' . ($variant['model_label'] ?: ''))) ?: '-') ?></td>
                        <td><?= $variant['sale_price'] === null ? '-' : number_format((float) $variant['sale_price'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$variants): ?>
                    <tr><td colspan="4">Henuz urun varyanti yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Toplu Urun Guncelleme</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="bulk_update_products">
            <div class="full">
                <label>Guncellenecek Urunler</label>
                <select name="product_ids[]" multiple size="8" required>
                    <?php foreach ($bulkProductOptions as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku'] . ' / ' . ((int) $product['status'] === 1 ? 'Aktif' : 'Pasif')) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Birden fazla urun secmek icin Ctrl tusunu kullanabilirsiniz.</small>
            </div>
            <div>
                <label>Kategori</label>
                <select name="category_id">
                    <option value="">Degistirme</option>
                    <option value="0">Kategoriyi temizle</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"><?= app_h($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Urun Tipi</label>
                <select name="product_type">
                    <option value="">Degistirme</option>
                    <option value="ticari">Ticari</option>
                    <option value="kiralik">Kiralik</option>
                    <option value="hammadde">Hammadde</option>
                    <option value="yari_mamul">Yari mamul</option>
                    <option value="mamul">Mamul</option>
                    <option value="yedek_parca">Yedek parca</option>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="">Degistirme</option>
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                </select>
            </div>
            <div>
                <label>Birim</label>
                <input name="unit" placeholder="Degistirme">
            </div>
            <div>
                <label>KDV</label>
                <input name="vat_rate" type="number" step="0.01" placeholder="Degistirme">
            </div>
            <div>
                <label>Kritik Stok</label>
                <input name="critical_stock" type="number" step="0.001" placeholder="Degistirme">
            </div>
            <div class="full">
                <button type="submit" onclick="return confirm('Secili urunlere toplu guncelleme uygulansin mi?');">Toplu Guncelle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Toplu Fiyat Guncelleme</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="bulk_update_prices">
            <div class="full">
                <label>Fiyati Guncellenecek Urunler</label>
                <select name="product_ids[]" multiple size="8" required>
                    <?php foreach ($bulkProductOptions as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku'] . ' / ' . ((int) $product['status'] === 1 ? 'Aktif' : 'Pasif')) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Negatif yuzde veya tutar indirim olarak uygulanir. Sonuc fiyat 0 altina dusmez.</small>
            </div>
            <div>
                <label>Hedef Fiyat</label>
                <select name="price_target" required>
                    <option value="sale">Satis fiyati</option>
                    <option value="purchase">Alis fiyati</option>
                    <option value="both">Alis ve satis</option>
                </select>
            </div>
            <div>
                <label>Yontem</label>
                <select name="price_mode" required>
                    <option value="set">Sabit fiyata ayarla</option>
                    <option value="percent">Yuzde uygula</option>
                    <option value="amount">Tutar ekle/cikar</option>
                </select>
            </div>
            <div>
                <label>Deger</label>
                <input name="price_value" type="number" step="0.0001" required placeholder="Orn: 10 veya -5">
            </div>
            <div class="full">
                <button type="submit" onclick="return confirm('Secili urunlere toplu fiyat guncelleme uygulansin mi?');">Toplu Fiyat Guncelle</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Stok Giris / Cikis</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_stock_movement">
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
                <label>Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Raf / Lokasyon</label>
                <select name="location_id">
                    <option value="">Lokasyon yok</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"><?= app_h($location['warehouse_name'] . ' / ' . $location['code'] . ' - ' . $location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Varyant</label>
                <select name="variant_id">
                    <option value="">Varyant yok</option>
                    <?php foreach ($variantOptions as $variant): ?>
                        <option value="<?= (int) $variant['id'] ?>"><?= app_h($variant['product_name'] . ' / ' . $variant['variant_code'] . ' - ' . $variant['variant_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hareket Tipi</label>
                <select name="movement_type">
                    <option value="giris">Giris</option>
                    <option value="cikis">Cikis</option>
                    <option value="sayim">Sayim</option>
                    <option value="transfer">Transfer</option>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input name="quantity" type="number" step="0.001" min="0.001" required>
            </div>
            <div>
                <label>Birim Maliyet</label>
                <input name="unit_cost" type="number" step="0.01" value="0">
            </div>
            <div>
                <label>Referans</label>
                <input name="reference_type" value="manuel">
            </div>
            <div>
                <label>Lot No</label>
                <input name="lot_no" placeholder="LOT-001">
            </div>
            <div>
                <label>Seri No</label>
                <input name="serial_no" placeholder="SR-001">
            </div>
            <div class="full">
                <button type="submit">Stok Hareketi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Stok Rezervasyon</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_stock_reservation">
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
                <label>Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($productOptions as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Raf / Lokasyon</label>
                <select name="location_id">
                    <option value="">Lokasyon yok</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"><?= app_h($location['warehouse_name'] . ' / ' . $location['code'] . ' - ' . $location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Varyant</label>
                <select name="variant_id">
                    <option value="">Varyant yok</option>
                    <?php foreach ($variantOptions as $variant): ?>
                        <option value="<?= (int) $variant['id'] ?>"><?= app_h($variant['product_name'] . ' / ' . $variant['variant_code'] . ' - ' . $variant['variant_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input name="quantity" type="number" step="0.001" min="0.001" required>
            </div>
            <div>
                <label>Rezerv Eden / Musteri</label>
                <input name="reserved_for" placeholder="Musteri, siparis veya departman">
            </div>
            <div>
                <label>Referans Tipi</label>
                <input name="reference_type" value="manuel">
            </div>
            <div>
                <label>Referans No</label>
                <input name="reference_no" placeholder="SIP-001">
            </div>
            <div>
                <label>Gecerlilik</label>
                <input name="reserved_until" type="date">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Rezervasyon aciklamasi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Rezervasyon Olustur</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Fire / Zayiat Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_stock_wastage">
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
                <label>Urun</label>
                <select name="product_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($productOptions as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= app_h($product['name'] . ' / ' . $product['sku']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Raf / Lokasyon</label>
                <select name="location_id">
                    <option value="">Lokasyon yok</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"><?= app_h($location['warehouse_name'] . ' / ' . $location['code'] . ' - ' . $location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Varyant</label>
                <select name="variant_id">
                    <option value="">Varyant yok</option>
                    <?php foreach ($variantOptions as $variant): ?>
                        <option value="<?= (int) $variant['id'] ?>"><?= app_h($variant['product_name'] . ' / ' . $variant['variant_code'] . ' - ' . $variant['variant_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Miktar</label>
                <input name="quantity" type="number" step="0.001" min="0.001" required>
            </div>
            <div>
                <label>Birim Maliyet</label>
                <input name="unit_cost" type="number" step="0.0001" value="0">
            </div>
            <div>
                <label>Fire Nedeni</label>
                <select name="reason_code" required>
                    <option value="">Seciniz</option>
                    <option value="hasar">Hasar</option>
                    <option value="son_kullanma">Son kullanma</option>
                    <option value="kayip">Kayip</option>
                    <option value="sayim_farki">Sayim farki</option>
                    <option value="uretim_firesi">Uretim firesi</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Neden Aciklamasi</label>
                <input name="reason_text" placeholder="Kirilma, SKT, sayim farki...">
            </div>
            <div>
                <label>Lot No</label>
                <input name="lot_no" placeholder="LOT-001">
            </div>
            <div>
                <label>Seri No</label>
                <input name="serial_no" placeholder="SR-001">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Fire / zayiat aciklamasi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Fire Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Mevcut Stok Seviyesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>SKU</th><th>Urun</th><th>Mevcut</th><th>Rezerve</th><th>Kullanilabilir</th><th>Birim</th><th>Kritik</th></tr></thead>
                <tbody>
                <?php foreach ($stockLevels as $item): ?>
                    <tr>
                        <td><?= app_h($item['sku']) ?></td>
                        <td><?= app_h($item['name']) ?></td>
                        <td><?= number_format((float) $item['current_stock'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['reserved_stock'], 3, ',', '.') ?></td>
                        <td><?= number_format((float) $item['available_stock'], 3, ',', '.') ?></td>
                        <td><?= app_h($item['unit']) ?></td>
                        <td><?= number_format((float) $item['critical_stock'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($stockLevelsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $stockLevelsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'stok', 'search' => $filters['search'], 'product_type' => $filters['product_type'], 'status' => $filters['status'], 'product_sort' => $filters['product_sort'], 'level_sort' => $filters['level_sort'], 'product_page' => $productsPagination['page'], 'level_page' => $page])) ?>"><?= $page === $stockLevelsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Raf / Lokasyon Stoklari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Depo</th><th>Lokasyon</th><th>SKU</th><th>Urun</th><th>Varyant</th><th>Mevcut</th></tr></thead>
                <tbody>
                <?php foreach ($locationLevels as $row): ?>
                    <tr>
                        <td><?= app_h($row['warehouse_name']) ?></td>
                        <td><?= app_h($row['location_code'] . ' / ' . $row['location_name']) ?></td>
                        <td><?= app_h($row['sku']) ?></td>
                        <td><?= app_h($row['product_name']) ?></td>
                        <td><?= app_h($row['variant_code'] . ' / ' . $row['variant_name']) ?></td>
                        <td><?= number_format((float) $row['current_stock'], 3, ',', '.') ?> <?= app_h($row['unit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$locationLevels): ?>
                    <tr><td colspan="6">Lokasyon bazli stok hareketi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Stok Yas Raporu</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>SKU</th><th>Urun</th><th>Depo</th><th>Mevcut</th><th>Son Giris</th><th>Yas</th><th>Band</th><th>Tahmini Deger</th></tr></thead>
                <tbody>
                <?php foreach ($stockAgeRows as $row): ?>
                    <tr>
                        <td><?= app_h($row['sku']) ?></td>
                        <td><?= app_h($row['name']) ?></td>
                        <td><?= app_h($row['warehouse_name']) ?></td>
                        <td><?= number_format((float) $row['current_stock'], 3, ',', '.') ?> <?= app_h($row['unit']) ?></td>
                        <td>
                            <?= app_h($row['last_in_date'] ?: '-') ?><br>
                            <small>Ilk: <?= app_h($row['first_in_date'] ?: '-') ?></small>
                        </td>
                        <td><?= $row['age_days'] === null ? '-' : (int) $row['age_days'] . ' gun' ?></td>
                        <td><?= app_h($row['age_band']) ?></td>
                        <td><?= number_format((float) $row['stock_value'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stockAgeRows): ?>
                    <tr><td colspan="8">Stok yas raporu icin elde mevcut urun bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Urun Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Gorsel</th><th>SKU</th><th>Urun</th><th>Kategori</th><th>Tip</th><th>Satis</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <?php if (!empty($product['image_path'])): ?>
                                <img src="<?= app_h((string) $product['image_path']) ?>" alt="<?= app_h($product['name']) ?>" style="width:54px;height:54px;object-fit:cover;border-radius:12px;border:1px solid #eadfce;">
                            <?php else: ?>
                                <span style="display:inline-flex;width:54px;height:54px;align-items:center;justify-content:center;border-radius:12px;background:#f3f4f6;color:#667085;font-size:.75rem;">Yok</span>
                            <?php endif; ?>
                        </td>
                        <td><?= app_h($product['sku']) ?></td>
                        <td><?= app_h($product['name']) ?></td>
                        <td><?= app_h($product['category_name'] ?: '-') ?></td>
                        <td><?= app_h($product['product_type']) ?></td>
                        <td><?= number_format((float) $product['sale_price'], 2, ',', '.') ?></td>
                        <td><?= (int) $product['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td>
                            <div class="stack">
                                <a href="stock_detail.php?id=<?= (int) $product['id'] ?>">Detay</a>
                                <form method="post">
                                    <input type="hidden" name="action" value="update_product_status">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="hidden" name="status" value="<?= (int) $product['status'] === 1 ? 0 : 1 ?>">
                                    <button type="submit"><?= (int) $product['status'] === 1 ? 'Pasife Al' : 'Aktif Et' ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu urun ve bagli stok hareketleri silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="upload_product_image">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="file" name="image_file" accept="image/*" required>
                                    <button type="submit">Gorsel Yukle</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($productsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $productsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'stok', 'search' => $filters['search'], 'product_type' => $filters['product_type'], 'status' => $filters['status'], 'product_sort' => $filters['product_sort'], 'level_sort' => $filters['level_sort'], 'product_page' => $page, 'level_page' => $stockLevelsPagination['page']])) ?>"><?= $page === $productsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Depolar ve Son Hareketler</h3>
        <div class="table-wrap split-tables">
            <table>
                <thead><tr><th>Depo</th><th>Lokasyon</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($warehouses as $warehouse): ?>
                    <tr>
                        <td><?= app_h($warehouse['name']) ?></td>
                        <td><?= app_h($warehouse['location_text'] ?: '-') ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu depo ve bagli hareketler silinsin mi?');">
                                <input type="hidden" name="action" value="delete_warehouse">
                                <input type="hidden" name="warehouse_id" value="<?= (int) $warehouse['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <table>
                <thead><tr><th>Depo</th><th>Raf</th><th>Urun</th><th>Varyant</th><th>Tur</th><th>Miktar</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $movement): ?>
                    <tr>
                        <td><?= app_h($movement['warehouse_name']) ?></td>
                        <td><?= app_h($movement['location_code'] . ' / ' . $movement['location_name']) ?></td>
                        <td><?= app_h($movement['product_name']) ?></td>
                        <td><?= app_h($movement['variant_code'] . ' / ' . $movement['variant_name']) ?></td>
                        <td><?= app_h($movement['movement_type']) ?></td>
                        <td><?= number_format((float) $movement['quantity'], 3, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Rezervasyon Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Depo</th><th>Raf</th><th>Urun</th><th>Varyant</th><th>Miktar</th><th>Ayrilan</th><th>Referans</th><th>Gecerlilik</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td><?= app_h($reservation['warehouse_name']) ?></td>
                        <td><?= app_h($reservation['location_code'] . ' / ' . $reservation['location_name']) ?></td>
                        <td>
                            <?= app_h($reservation['product_name']) ?><br>
                            <small><?= app_h($reservation['sku']) ?></small>
                        </td>
                        <td><?= app_h($reservation['variant_code'] . ' / ' . $reservation['variant_name']) ?></td>
                        <td><?= number_format((float) $reservation['quantity'], 3, ',', '.') ?> <?= app_h($reservation['unit']) ?></td>
                        <td><?= app_h($reservation['reserved_for'] ?: '-') ?></td>
                        <td><?= app_h(trim((string) (($reservation['reference_type'] ?: '-') . ' ' . ($reservation['reference_no'] ?: '')))) ?></td>
                        <td><?= app_h($reservation['reserved_until'] ?: '-') ?></td>
                        <td><?= app_h($reservation['status']) ?></td>
                        <td>
                            <?php if ($reservation['status'] === 'aktif'): ?>
                                <div class="stack">
                                    <form method="post">
                                        <input type="hidden" name="action" value="update_stock_reservation_status">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                        <input type="hidden" name="status" value="tamamlandi">
                                        <button type="submit">Tamamla</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Bu rezervasyon iptal edilsin mi?');">
                                        <input type="hidden" name="action" value="update_stock_reservation_status">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                        <input type="hidden" name="status" value="iptal">
                                        <button type="submit">Iptal</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reservations): ?>
                    <tr><td colspan="10">Henuz stok rezervasyonu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Fire / Zayiat Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Depo</th><th>Raf</th><th>Urun</th><th>Varyant</th><th>Miktar</th><th>Neden</th><th>Maliyet</th><th>Lot/Seri</th></tr></thead>
                <tbody>
                <?php foreach ($wastages as $wastage): ?>
                    <tr>
                        <td><?= app_h($wastage['wastage_date']) ?></td>
                        <td><?= app_h($wastage['warehouse_name']) ?></td>
                        <td><?= app_h($wastage['location_code'] . ' / ' . $wastage['location_name']) ?></td>
                        <td>
                            <?= app_h($wastage['product_name']) ?><br>
                            <small><?= app_h($wastage['sku']) ?></small>
                        </td>
                        <td><?= app_h($wastage['variant_code'] . ' / ' . $wastage['variant_name']) ?></td>
                        <td><?= number_format((float) $wastage['quantity'], 3, ',', '.') ?> <?= app_h($wastage['unit']) ?></td>
                        <td>
                            <?= app_h($wastage['reason_code']) ?><br>
                            <small><?= app_h($wastage['reason_text'] ?: '-') ?></small>
                        </td>
                        <td>
                            <?= number_format((float) $wastage['total_cost'], 2, ',', '.') ?><br>
                            <small>Birim: <?= number_format((float) $wastage['unit_cost'], 4, ',', '.') ?></small>
                        </td>
                        <td><?= app_h(($wastage['lot_no'] ?: '-') . ' / ' . ($wastage['serial_no'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$wastages): ?>
                    <tr><td colspan="9">Henuz fire / zayiat kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
