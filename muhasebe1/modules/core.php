<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Core sistem modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function core_post_redirect(string $result): void
{
    app_redirect('index.php?module=core&ok=' . urlencode($result));
}

function core_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(
        ["\\", "'"],
        ["\\\\", "\\'"],
        (string) $value
    ) . "'";
}

function core_download_backup(PDO $db): void
{
    $databaseName = app_config()['db']['database'] ?? 'galancy';
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $filename = 'muhasebe1-backup-' . date('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- Muhasebe1 SQL Yedegi\n";
    echo "-- Tarih: " . date('Y-m-d H:i:s') . "\n";
    echo "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', $databaseName) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    echo "USE `" . str_replace('`', '``', $databaseName) . "`;\n\n";

    foreach ($tables as $table) {
        $tableName = (string) $table;
        $createStmt = $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch();

        if (!$createStmt || !isset($createStmt['Create Table'])) {
            continue;
        }

        echo "-- Tablo: " . $tableName . "\n";
        echo $createStmt['Create Table'] . ";\n\n";

        $rows = $db->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "\n";
            continue;
        }

        $columns = array_keys($rows[0]);
        $columnSql = '`' . implode('`, `', array_map(static function ($column): string {
            return str_replace('`', '``', (string) $column);
        }, $columns)) . '`';

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = core_sql_literal($row[$column] ?? null);
            }

            echo 'INSERT INTO `' . str_replace('`', '``', $tableName) . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ");\n";
        }

        echo "\n";
    }

    exit;
}

function core_safe_upload_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'dosya';

    return $base . '-' . date('YmdHis') . '-' . substr(sha1($originalName . microtime(true)), 0, 8) . ($extension !== '' ? '.' . $extension : '');
}

function core_import_sql(PDO $db, string $sql): void
{
    if (trim($sql) === '') {
        throw new RuntimeException('Yedek dosyasi bos gorunuyor.');
    }

    app_run_sql_batch($db, $sql);
}

function core_permission_templates(): array
{
    $modules = array_keys(app_modules());
    $allActions = array_keys(app_permission_actions());

    $fullAccessMatrix = [];
    foreach ($modules as $moduleKey) {
        $fullAccessMatrix[$moduleKey] = $allActions;
    }

    $financeModules = ['dashboard', 'core', 'cari', 'tahsilat', 'fatura', 'satis', 'evrak', 'crm', 'bildirim', 'rapor'];
    $financeMatrix = [];
    foreach ($financeModules as $moduleKey) {
        $financeMatrix[$moduleKey] = ['view', 'create', 'update', 'export'];
    }
    $financeMatrix['core'] = ['view'];
    $financeMatrix['dashboard'] = ['view'];
    $financeMatrix['bildirim'] = ['view', 'update'];

    $operationsModules = ['dashboard', 'stok', 'servis', 'kira', 'uretim', 'evrak', 'crm', 'bildirim', 'rapor'];
    $operationsMatrix = [];
    foreach ($operationsModules as $moduleKey) {
        $operationsMatrix[$moduleKey] = ['view', 'create', 'update'];
    }
    $operationsMatrix['dashboard'] = ['view'];
    $operationsMatrix['rapor'] = ['view', 'export'];
    $operationsMatrix['bildirim'] = ['view', 'update'];

    $salesModules = ['dashboard', 'cari', 'satis', 'fatura', 'crm', 'bildirim', 'rapor', 'evrak'];
    $salesMatrix = [];
    foreach ($salesModules as $moduleKey) {
        $salesMatrix[$moduleKey] = ['view', 'create', 'update'];
    }
    $salesMatrix['dashboard'] = ['view'];
    $salesMatrix['satis'] = ['view', 'create', 'update', 'approve', 'export'];
    $salesMatrix['fatura'] = ['view', 'create', 'export'];
    $salesMatrix['rapor'] = ['view', 'export'];

    $observerModules = ['dashboard', 'rapor', 'bildirim'];
    $observerMatrix = [
        'dashboard' => ['view'],
        'rapor' => ['view', 'export'],
        'bildirim' => ['view'],
    ];

    return [
        'tam_yetki' => [
            'label' => 'Tam Yetki',
            'summary' => 'Tum moduller ve tum temel islemler acilir.',
            'modules' => $modules,
            'matrix' => $fullAccessMatrix,
        ],
        'muhasebe_standart' => [
            'label' => 'Muhasebe Standart',
            'summary' => 'Finans, fatura, tahsilat ve rapor odakli rol dagilimi.',
            'modules' => $financeModules,
            'matrix' => $financeMatrix,
        ],
        'operasyon_standart' => [
            'label' => 'Operasyon Standart',
            'summary' => 'Stok, servis, kira ve uretim sureclerine odaklanir.',
            'modules' => $operationsModules,
            'matrix' => $operationsMatrix,
        ],
        'satis_crm' => [
            'label' => 'Satis ve CRM',
            'summary' => 'Teklif, siparis, cari ve musteri yonetimi agirliklidir.',
            'modules' => $salesModules,
            'matrix' => $salesMatrix,
        ],
        'gozlemci_rapor' => [
            'label' => 'Gozlemci / Rapor',
            'summary' => 'Sadece dashboard, bildirim ve rapor goruntuleme yetkisi verir.',
            'modules' => $observerModules,
            'matrix' => $observerMatrix,
        ],
    ];
}

function core_branch_close_checklist_items(): array
{
    return [
        'cashbox' => [
            'label' => 'Kasa sayimi',
            'detail' => 'Gun sonu kasa durumunun kontrol edilmesi.',
        ],
        'bank' => [
            'label' => 'Banka hareket kontrolu',
            'detail' => 'Banka hesaplari ve hareketlerinin gozden gecirilmesi.',
        ],
        'orders' => [
            'label' => 'Bekleyen siparis kontrolu',
            'detail' => 'Acik siparislerin teslim ve sevk acisindan incelenmesi.',
        ],
        'invoices' => [
            'label' => 'Gunluk fatura kontrolu',
            'detail' => 'Ayni gunde kesilen faturalarin teyit edilmesi.',
        ],
        'service' => [
            'label' => 'Acik servis kontrolu',
            'detail' => 'Kapanmayan servis kayitlarinin gozden gecirilmesi.',
        ],
        'rental' => [
            'label' => 'Kira tahsilat kontrolu',
            'detail' => 'Aktif kira ve tahsilat takibinin kontrol edilmesi.',
        ],
    ];
}

function core_branch_open_checklist_items(): array
{
    return [
        'staff' => [
            'label' => 'Personel hazirlik kontrolu',
            'detail' => 'Vardiya, sorumlu ve gorev dagiliminin teyidi.',
        ],
        'cashbox' => [
            'label' => 'Kasa acilis kontrolu',
            'detail' => 'Devir tutari ve acilis kasa durumunun kontrol edilmesi.',
        ],
        'bank' => [
            'label' => 'Banka ve POS hazirlik',
            'detail' => 'Banka, POS ve tahsilat kanallarinin kontrol edilmesi.',
        ],
        'stock' => [
            'label' => 'Kritik stok kontrolu',
            'detail' => 'Gunluk operasyon icin kritik urunlerin hazirligi.',
        ],
        'service' => [
            'label' => 'Servis plan kontrolu',
            'detail' => 'Acil servis/randevu kayitlarinin gozden gecirilmesi.',
        ],
        'rental' => [
            'label' => 'Kira teslim programi',
            'detail' => 'Bugunku kira teslim ve geri alim planinin teyidi.',
        ],
    ];
}

function core_branch_expense_allocation_bases(): array
{
    return [
        'equal' => [
            'label' => 'Esit Dagitim',
            'detail' => 'Tutar tum aktif subelere esit paylastirilir.',
        ],
        'active_user' => [
            'label' => 'Aktif Kullanici',
            'detail' => 'Aktif kullanici sayisina gore agirlik verilir.',
        ],
        'order_volume' => [
            'label' => 'Siparis Hacmi',
            'detail' => 'Siparis adetlerine gore paylastirilir.',
        ],
        'invoice_volume' => [
            'label' => 'Fatura Hacmi',
            'detail' => 'Fatura adetlerine gore paylastirilir.',
        ],
        'active_rental' => [
            'label' => 'Aktif Kira',
            'detail' => 'Aktif kira sozlesmesi adedine gore dagitilir.',
        ],
        'service_load' => [
            'label' => 'Servis Yuku',
            'detail' => 'Acik servis kayitlarina gore dagitilir.',
        ],
    ];
}

function core_branch_expense_weight_map(PDO $db, array $branches, string $basis): array
{
    $weights = [];
    foreach ($branches as $branchRow) {
        $branchId = (int) ($branchRow['id'] ?? 0);
        if ($branchId > 0 && (int) ($branchRow['status'] ?? 0) === 1) {
            $weights[$branchId] = 1.0;
        }
    }

    if ($weights === []) {
        return [];
    }

    $queries = [
        'active_user' => app_table_exists($db, 'core_users') && app_column_exists($db, 'core_users', 'branch_id')
            ? 'SELECT branch_id, COUNT(*) AS metric_count FROM core_users WHERE COALESCE(status, 0) = 1 GROUP BY branch_id'
            : '',
        'order_volume' => app_table_exists($db, 'sales_orders') && app_column_exists($db, 'sales_orders', 'branch_id')
            ? 'SELECT branch_id, COUNT(*) AS metric_count FROM sales_orders GROUP BY branch_id'
            : '',
        'invoice_volume' => app_table_exists($db, 'invoice_headers') && app_column_exists($db, 'invoice_headers', 'branch_id')
            ? 'SELECT branch_id, COUNT(*) AS metric_count FROM invoice_headers GROUP BY branch_id'
            : '',
        'active_rental' => app_table_exists($db, 'rental_contracts') && app_column_exists($db, 'rental_contracts', 'branch_id') && app_column_exists($db, 'rental_contracts', 'status')
            ? 'SELECT branch_id, COUNT(*) AS metric_count FROM rental_contracts WHERE status = "aktif" GROUP BY branch_id'
            : '',
        'service_load' => app_table_exists($db, 'service_records') && app_column_exists($db, 'service_records', 'branch_id') && app_column_exists($db, 'service_records', 'closed_at')
            ? 'SELECT branch_id, COUNT(*) AS metric_count FROM service_records WHERE closed_at IS NULL GROUP BY branch_id'
            : '',
    ];

    $query = $queries[$basis] ?? '';
    if ($basis === 'equal' || $query === '') {
        return $weights;
    }

    foreach ($weights as $branchId => $value) {
        $weights[$branchId] = 0.0;
    }

    foreach (app_fetch_all($db, $query) as $metricRow) {
        $branchId = (int) ($metricRow['branch_id'] ?? 0);
        if (isset($weights[$branchId])) {
            $weights[$branchId] = max(0.0, (float) ($metricRow['metric_count'] ?? 0));
        }
    }

    $totalWeight = array_sum($weights);
    if ($totalWeight <= 0) {
        foreach ($weights as $branchId => $value) {
            $weights[$branchId] = 1.0;
        }
    }

    return $weights;
}

function core_branch_target_goal_fields(): array
{
    return [
        'invoice_total' => 'Fatura Hedefi',
        'order_total' => 'Siparis Hedefi',
        'rental_monthly_total' => 'Kira Hedefi',
        'closed_service_count' => 'Kapanan Servis Hedefi',
        'total_score' => 'Performans Skor Hedefi',
    ];
}

function core_branch_transfer_request_store(PDO $db): array
{
    $raw = app_setting($db, 'branch.transfer.requests', '');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function core_save_branch_transfer_request_store(PDO $db, array $requests): void
{
    app_set_setting(
        $db,
        'branch.transfer.requests',
        json_encode(array_values($requests), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sube'
    );
}

function core_internal_service_cari(PDO $db, int $ownerBranchId, array $counterpartyBranch, array $firmNameMap, string $cardType): int
{
    $counterpartyFirmName = (string) ($firmNameMap[(int) ($counterpartyBranch['firm_id'] ?? 0)] ?? 'Firma');
    $counterpartyBranchName = (string) ($counterpartyBranch['name'] ?? 'Sube');
    $companyName = 'Ic Hizmet / ' . $counterpartyFirmName . ' / ' . $counterpartyBranchName;
    $taxNumber = 'IC-' . str_pad((string) ((int) ($counterpartyBranch['firm_id'] ?? 0)), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) ((int) ($counterpartyBranch['id'] ?? 0)), 4, '0', STR_PAD_LEFT);

    $existingId = (int) app_metric($db, '
        SELECT id
        FROM cari_cards
        WHERE branch_id = :branch_id
          AND company_name = :company_name
          AND card_type = :card_type
        ORDER BY id ASC
        LIMIT 1
    ', [
        'branch_id' => $ownerBranchId,
        'company_name' => $companyName,
        'card_type' => $cardType,
    ]);
    if ($existingId > 0) {
        return $existingId;
    }

    $stmt = $db->prepare('
        INSERT INTO cari_cards (
            branch_id, card_type, is_company, company_name, tax_office, tax_number, city, district, country, notes, status
        ) VALUES (
            :branch_id, :card_type, 1, :company_name, :tax_office, :tax_number, :city, :district, :country, :notes, 1
        )
    ');
    $stmt->execute([
        'branch_id' => $ownerBranchId,
        'card_type' => $cardType,
        'company_name' => $companyName,
        'tax_office' => 'Ic Hizmet',
        'tax_number' => $taxNumber,
        'city' => (string) ($counterpartyBranch['city'] ?? ''),
        'district' => (string) ($counterpartyBranch['district'] ?? ''),
        'country' => 'Turkiye',
        'notes' => 'Core tarafindan ic hizmet faturasi icin otomatik olusturuldu.',
    ]);

    return (int) $db->lastInsertId();
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';
$currentUser = app_auth_user();

if (isset($_GET['download_backup']) && $_GET['download_backup'] === 'sql') {
    app_audit_log('core', 'download_backup', null, null, 'SQL yedek indirildi.');
    core_download_backup($db);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($action === 'update_firm') {
            $firmId = (int) ($_POST['firm_id'] ?? 0);
            $companyName = trim((string) ($_POST['company_name'] ?? ''));

            if ($firmId <= 0 || $companyName === '') {
                throw new RuntimeException('Firma kaydi ve unvan zorunludur.');
            }

            $stmt = $db->prepare('
                UPDATE core_firms
                SET company_name = :company_name,
                    trade_name = :trade_name,
                    tax_office = :tax_office,
                    tax_number = :tax_number,
                    mersis_no = :mersis_no,
                    email = :email,
                    phone = :phone,
                    website = :website,
                    address = :address,
                    city = :city,
                    district = :district,
                    country = :country,
                    logo_path = :logo_path
                WHERE id = :id
            ');
            $stmt->execute([
                'company_name' => $companyName,
                'trade_name' => trim((string) ($_POST['trade_name'] ?? '')) ?: null,
                'tax_office' => trim((string) ($_POST['tax_office'] ?? '')) ?: null,
                'tax_number' => trim((string) ($_POST['tax_number'] ?? '')) ?: null,
                'mersis_no' => trim((string) ($_POST['mersis_no'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'website' => trim((string) ($_POST['website'] ?? '')) ?: null,
                'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
                'city' => trim((string) ($_POST['city'] ?? '')) ?: null,
                'district' => trim((string) ($_POST['district'] ?? '')) ?: null,
                'country' => trim((string) ($_POST['country'] ?? 'Turkiye')) ?: 'Turkiye',
                'logo_path' => trim((string) ($_POST['logo_path'] ?? '')) ?: null,
                'id' => $firmId,
            ]);

            app_audit_log('core', 'update_firm', 'core_firms', $firmId, 'Firma bilgileri guncellendi.');
            core_post_redirect('firm');
        }

        if ($action === 'create_firm') {
            $companyName = trim((string) ($_POST['company_name'] ?? ''));

            if ($companyName === '') {
                throw new RuntimeException('Firma unvani zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO core_firms (
                    company_name, trade_name, tax_office, tax_number, mersis_no, email, phone,
                    website, address, city, district, country, status
                ) VALUES (
                    :company_name, :trade_name, :tax_office, :tax_number, :mersis_no, :email, :phone,
                    :website, :address, :city, :district, :country, :status
                )
            ');
            $stmt->execute([
                'company_name' => $companyName,
                'trade_name' => trim((string) ($_POST['trade_name'] ?? '')) ?: null,
                'tax_office' => trim((string) ($_POST['tax_office'] ?? '')) ?: null,
                'tax_number' => trim((string) ($_POST['tax_number'] ?? '')) ?: null,
                'mersis_no' => trim((string) ($_POST['mersis_no'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'website' => trim((string) ($_POST['website'] ?? '')) ?: null,
                'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
                'city' => trim((string) ($_POST['city'] ?? '')) ?: null,
                'district' => trim((string) ($_POST['district'] ?? '')) ?: null,
                'country' => trim((string) ($_POST['country'] ?? 'Turkiye')) ?: 'Turkiye',
                'status' => isset($_POST['status']) ? 1 : 0,
            ]);

            app_audit_log('core', 'create_firm', 'core_firms', (int) $db->lastInsertId(), 'Yeni firma olusturuldu: ' . $companyName);
            core_post_redirect('firm_created');
        }

        if ($action === 'update_firm_status') {
            $firmId = (int) ($_POST['firm_id'] ?? 0);

            if ($firmId <= 0) {
                throw new RuntimeException('Gecerli bir firma secilmedi.');
            }

            $stmt = $db->prepare('UPDATE core_firms SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0,
                'id' => $firmId,
            ]);

            app_audit_log('core', 'update_firm_status', 'core_firms', $firmId, 'Firma durumu guncellendi.');
            core_post_redirect('firm_status');
        }

        if ($action === 'upload_logo') {
            $firmId = (int) ($_POST['firm_id'] ?? 0);

            if ($firmId <= 0) {
                throw new RuntimeException('Gecerli bir firma secilmedi.');
            }

            if (!isset($_FILES['logo_file']) || !is_array($_FILES['logo_file'])) {
                throw new RuntimeException('Yuklenecek logo dosyasi bulunamadi.');
            }

            $file = $_FILES['logo_file'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Logo yukleme islemi basarisiz oldu.');
            }

            $originalName = (string) ($file['name'] ?? 'logo');
            $extension = app_validate_uploaded_file($file, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

            $targetDir = dirname(__DIR__) . '/uploads/logos';
            if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Logo klasoru olusturulamadi.');
            }
            app_ensure_upload_protection($targetDir);

            $safeName = core_safe_upload_name($originalName);
            $targetPath = $targetDir . '/' . $safeName;
            $publicPath = '/muhasebe1/uploads/logos/' . $safeName;

            if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Logo dosyasi kaydedilemedi.');
            }

            $stmt = $db->prepare('UPDATE core_firms SET logo_path = :logo_path WHERE id = :id');
            $stmt->execute([
                'logo_path' => $publicPath,
                'id' => $firmId,
            ]);

            $stmt = $db->prepare('
                INSERT INTO docs_files (module_name, related_table, related_id, file_name, file_path, file_type, notes)
                VALUES (:module_name, :related_table, :related_id, :file_name, :file_path, :file_type, :notes)
            ');
            $stmt->execute([
                'module_name' => 'core',
                'related_table' => 'core_firms',
                'related_id' => $firmId,
                'file_name' => $originalName,
                'file_path' => $publicPath,
                'file_type' => $extension,
                'notes' => 'Firma logosu',
            ]);

            app_audit_log('core', 'upload_logo', 'core_firms', $firmId, 'Firma logosu yuklendi: ' . $originalName);
            core_post_redirect('logo');
        }

        if ($action === 'import_backup') {
            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                throw new RuntimeException('Iceri aktarilacak SQL dosyasi bulunamadi.');
            }

            $file = $_FILES['backup_file'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Yedek dosyasi yukleme islemi basarisiz oldu.');
            }

            $originalName = (string) ($file['name'] ?? 'backup.sql');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extension !== 'sql') {
                throw new RuntimeException('Sadece .sql uzantili yedek dosyalari ice aktarilabilir.');
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $sql = @file_get_contents($tmpName);
            if ($sql === false) {
                throw new RuntimeException('Yedek dosyasi okunamadi.');
            }

            core_import_sql($db, $sql);
            app_audit_log('core', 'import_backup', 'core_settings', null, 'SQL yedek ice aktarma calistirildi: ' . $originalName);
            core_post_redirect('import');
        }

        if ($action === 'update_general_settings') {
            app_set_setting($db, 'currency.default', trim((string) ($_POST['currency_default'] ?? 'TRY')) ?: 'TRY', 'genel');
            app_set_setting($db, 'tax.default_vat', trim((string) ($_POST['tax_default_vat'] ?? '20')) ?: '20', 'vergi');
            app_set_setting($db, 'theme.default', trim((string) ($_POST['theme_default'] ?? 'sunrise')) ?: 'sunrise', 'arayuz');
            app_set_setting($db, 'app.panel_title', trim((string) ($_POST['panel_title'] ?? '')) ?: 'Yonetim Paneli', 'genel');

            app_audit_log('core', 'update_settings', 'core_settings', null, 'Genel sistem ayarlari guncellendi.');
            core_post_redirect('settings');
        }

        if ($action === 'update_two_factor_settings') {
            $mode = strtolower(trim((string) ($_POST['security_two_factor_mode'] ?? 'off')));
            if (!in_array($mode, ['off', 'email'], true)) {
                $mode = 'off';
            }

            $ttl = max(1, min(30, (int) ($_POST['security_two_factor_ttl'] ?? 10)));

            app_set_setting($db, 'security.two_factor_mode', $mode, 'guvenlik');
            app_set_setting($db, 'security.two_factor_ttl', (string) $ttl, 'guvenlik');

            app_audit_log('core', 'update_two_factor_settings', 'core_settings', null, 'Iki adimli dogrulama ayarlari guncellendi: ' . json_encode([
                'mode' => $mode,
                'ttl' => $ttl,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('two_factor');
        }

        if ($action === 'update_session_timeout_settings') {
            $enabled = isset($_POST['security_session_timeout_enabled']) ? '1' : '0';
            $minutes = max(5, min(480, (int) ($_POST['security_session_timeout_minutes'] ?? 30)));

            app_set_setting($db, 'security.session_timeout_enabled', $enabled, 'guvenlik');
            app_set_setting($db, 'security.session_timeout_minutes', (string) $minutes, 'guvenlik');

            app_audit_log('core', 'update_session_timeout_settings', 'core_settings', null, 'Oturum zaman asimi ayarlari guncellendi: ' . json_encode([
                'enabled' => $enabled === '1',
                'minutes' => $minutes,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('session_timeout');
        }

        if ($action === 'update_ip_access_settings') {
            $mode = strtolower(trim((string) ($_POST['security_ip_mode'] ?? 'off')));
            if (!in_array($mode, ['off', 'allowlist'], true)) {
                $mode = 'off';
            }

            $rulesRaw = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['security_ip_allowlist'] ?? '')));
            $ruleLines = array_filter(array_map(static fn(string $line): string => trim($line), explode("\n", $rulesRaw)), static fn(string $line): bool => $line !== '');
            $rulesRaw = implode("\n", array_values(array_unique($ruleLines)));
            $localBypass = isset($_POST['security_ip_local_bypass']) ? '1' : '0';

            app_set_setting($db, 'security.ip_mode', $mode, 'guvenlik');
            app_set_setting($db, 'security.ip_allowlist', $rulesRaw, 'guvenlik');
            app_set_setting($db, 'security.ip_local_bypass', $localBypass, 'guvenlik');

            app_audit_log('core', 'update_ip_access_settings', 'core_settings', null, 'IP bazli erisim kurallari guncellendi: ' . json_encode([
                'mode' => $mode,
                'rule_count' => count($ruleLines),
                'local_bypass' => $localBypass === '1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('ip_security');
        }

        if ($action === 'update_branch_scope_settings') {
            $oldBranchScope = [
                'mode' => app_branch_isolation_mode($db),
                'default_branch_id' => app_setting($db, 'branch.default_id', '1'),
                'unassigned_policy' => app_setting($db, 'branch.unassigned_policy', 'show_to_admin'),
            ];
            $mode = trim((string) ($_POST['branch_isolation_mode'] ?? 'reporting'));
            if (!in_array($mode, ['off', 'reporting', 'strict'], true)) {
                $mode = 'reporting';
            }
            $unassignedPolicy = trim((string) ($_POST['branch_unassigned_policy'] ?? 'show_to_admin')) ?: 'show_to_admin';
            if (!in_array($unassignedPolicy, ['show_to_admin', 'show_to_all', 'hide'], true)) {
                $unassignedPolicy = 'show_to_admin';
            }
            $defaultBranchId = (string) max(0, (int) ($_POST['branch_default_id'] ?? 0));
            $newBranchScope = [
                'mode' => $mode,
                'default_branch_id' => $defaultBranchId,
                'unassigned_policy' => $unassignedPolicy,
            ];

            app_set_setting($db, 'branch.isolation_mode', $mode, 'sube');
            app_set_setting($db, 'branch.default_id', $defaultBranchId, 'sube');
            app_set_setting($db, 'branch.unassigned_policy', $unassignedPolicy, 'sube');

            app_audit_log('core', 'update_branch_scope_settings', 'core_settings', null, 'Firma/sube veri ayrimi ayarlari guncellendi: ' . json_encode(['old' => $oldBranchScope, 'new' => $newBranchScope], JSON_UNESCAPED_SLASHES));
            core_post_redirect('branch_scope');
        }

        if ($action === 'assign_unassigned_branch_records') {
            $targetBranchId = (int) ($_POST['target_branch_id'] ?? 0);
            $selectedTables = $_POST['scope_tables'] ?? [];
            $scopeTables = app_branch_scope_tables();

            if ($targetBranchId <= 0) {
                throw new RuntimeException('Gecerli bir hedef sube secilmedi.');
            }

            if (!is_array($selectedTables) || $selectedTables === []) {
                throw new RuntimeException('En az bir tablo secilmelidir.');
            }

            $branchExists = (int) app_metric($db, 'SELECT COUNT(*) FROM core_branches WHERE id = :id AND status = 1', ['id' => $targetBranchId]);
            if ($branchExists <= 0) {
                throw new RuntimeException('Hedef sube aktif degil veya bulunamadi.');
            }

            $updatedSummary = [];
            foreach ($selectedTables as $tableName) {
                $tableName = (string) $tableName;
                if (!isset($scopeTables[$tableName]) || !app_table_exists($db, $tableName) || !app_column_exists($db, $tableName, 'branch_id')) {
                    continue;
                }

                $safeTable = str_replace('`', '``', $tableName);
                $stmt = $db->prepare('UPDATE `' . $safeTable . '` SET branch_id = :branch_id WHERE branch_id IS NULL OR branch_id = 0');
                $stmt->execute(['branch_id' => $targetBranchId]);
                $updatedSummary[$tableName] = $stmt->rowCount();
            }

            app_audit_log('core', 'assign_unassigned_branch_records', 'core_branches', $targetBranchId, 'Subesiz kayitlar subeye baglandi: ' . json_encode($updatedSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('branch_assign_' . array_sum($updatedSummary));
        }

        if ($action === 'repair_branch_scope_risks') {
            $targetBranchId = (int) ($_POST['target_branch_id'] ?? 0);
            $selectedTables = $_POST['scope_tables'] ?? [];
            $scopeTables = app_branch_scope_tables();

            if ($targetBranchId <= 0) {
                throw new RuntimeException('Gecerli bir hedef sube secilmedi.');
            }

            if (!is_array($selectedTables) || $selectedTables === []) {
                throw new RuntimeException('En az bir tablo secilmelidir.');
            }

            $branchExists = (int) app_metric($db, 'SELECT COUNT(*) FROM core_branches WHERE id = :id AND status = 1', ['id' => $targetBranchId]);
            if ($branchExists <= 0) {
                throw new RuntimeException('Hedef sube aktif degil veya bulunamadi.');
            }

            $repairSummary = [];
            foreach ($selectedTables as $tableName) {
                $tableName = (string) $tableName;
                if (!isset($scopeTables[$tableName]) || !app_table_exists($db, $tableName) || !app_column_exists($db, $tableName, 'branch_id')) {
                    continue;
                }

                $safeTable = str_replace('`', '``', $tableName);
                $stmt = $db->prepare('
                    UPDATE `' . $safeTable . '` t
                    LEFT JOIN core_branches b ON b.id = t.branch_id
                    SET t.branch_id = :branch_id
                    WHERE t.branch_id IS NULL
                       OR t.branch_id = 0
                       OR b.id IS NULL
                       OR COALESCE(b.status, 0) = 0
                ');
                $stmt->execute(['branch_id' => $targetBranchId]);
                $repairSummary[$tableName] = $stmt->rowCount();
            }

            app_audit_log('core', 'repair_branch_scope_risks', 'core_branches', $targetBranchId, 'Sube kapsam riskleri hedef subeye tasindi: ' . json_encode($repairSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('branch_repair_' . array_sum($repairSummary));
        }

        if ($action === 'update_invoice_print_template') {
            app_set_setting($db, 'print.invoice_title', trim((string) ($_POST['invoice_print_title'] ?? 'FATURA')) ?: 'FATURA', 'tasarim');
            app_set_setting($db, 'print.invoice_subtitle', trim((string) ($_POST['invoice_print_subtitle'] ?? 'PDF / yazdir cikti sablonu')) ?: 'PDF / yazdir cikti sablonu', 'tasarim');
            app_set_setting($db, 'print.invoice_accent', trim((string) ($_POST['invoice_print_accent'] ?? '#9a3412')) ?: '#9a3412', 'tasarim');
            app_set_setting($db, 'print.invoice_notes_title', trim((string) ($_POST['invoice_print_notes_title'] ?? 'Notlar')) ?: 'Notlar', 'tasarim');
            app_set_setting($db, 'print.invoice_footer', trim((string) ($_POST['invoice_print_footer'] ?? 'Bu belge sistem tarafindan olusturulmustur.')) ?: 'Bu belge sistem tarafindan olusturulmustur.', 'tasarim');

            app_audit_log('core', 'update_invoice_print_template', 'core_settings', null, 'Fatura sablon editoru guncellendi.');
            core_post_redirect('invoice_template');
        }

        if ($action === 'update_branch_document_template') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            if ($branchId <= 0) {
                throw new RuntimeException('Belge tasarimi icin gecerli bir sube secilmedi.');
            }

            $prefix = 'print.branch.' . $branchId . '.';
            app_set_setting($db, $prefix . 'invoice_title', trim((string) ($_POST['invoice_print_title'] ?? '')) ?: app_setting($db, 'print.invoice_title', 'FATURA'), 'tasarim');
            app_set_setting($db, $prefix . 'invoice_subtitle', trim((string) ($_POST['invoice_print_subtitle'] ?? '')) ?: app_setting($db, 'print.invoice_subtitle', 'PDF / yazdir cikti sablonu'), 'tasarim');
            app_set_setting($db, $prefix . 'invoice_accent', trim((string) ($_POST['invoice_print_accent'] ?? '')) ?: app_setting($db, 'print.invoice_accent', '#9a3412'), 'tasarim');
            app_set_setting($db, $prefix . 'invoice_notes_title', trim((string) ($_POST['invoice_print_notes_title'] ?? '')) ?: app_setting($db, 'print.invoice_notes_title', 'Notlar'), 'tasarim');
            app_set_setting($db, $prefix . 'invoice_footer', trim((string) ($_POST['invoice_print_footer'] ?? '')) ?: app_setting($db, 'print.invoice_footer', 'Bu belge sistem tarafindan olusturulmustur.'), 'tasarim');
            app_set_setting($db, $prefix . 'document_logo_path', trim((string) ($_POST['document_logo_path'] ?? '')), 'tasarim');

            app_audit_log('core', 'update_branch_document_template', 'core_branches', $branchId, 'Sube bazli belge tasarimi guncellendi.');
            core_post_redirect('branch_document_template');
        }

        if ($action === 'update_document_prefixes') {
            app_set_setting($db, 'docs.offer_prefix', trim((string) ($_POST['offer_prefix'] ?? 'TKL')) ?: 'TKL', 'belgeler');
            app_set_setting($db, 'docs.order_prefix', trim((string) ($_POST['order_prefix'] ?? 'SIP')) ?: 'SIP', 'belgeler');
            app_set_setting($db, 'docs.invoice_prefix', trim((string) ($_POST['invoice_prefix'] ?? 'FAT')) ?: 'FAT', 'belgeler');
            app_set_setting($db, 'docs.offer_series', trim((string) ($_POST['offer_series'] ?? 'TKL-{YYYY}-{SEQ}')) ?: 'TKL-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.order_series', trim((string) ($_POST['order_series'] ?? 'SIP-{YYYY}-{SEQ}')) ?: 'SIP-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.invoice_sales_series', trim((string) ($_POST['invoice_sales_series'] ?? 'FAT-{YYYY}-{SEQ}')) ?: 'FAT-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.invoice_purchase_series', trim((string) ($_POST['invoice_purchase_series'] ?? 'ALI-{YYYY}-{SEQ}')) ?: 'ALI-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.invoice_return_series', trim((string) ($_POST['invoice_return_series'] ?? 'IAD-{YYYY}-{SEQ}')) ?: 'IAD-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.shipment_series', trim((string) ($_POST['shipment_series'] ?? 'SVK-{YYYY}-{SEQ}')) ?: 'SVK-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.dispatch_series', trim((string) ($_POST['dispatch_series'] ?? 'IRS-{YYYY}-{SEQ}')) ?: 'IRS-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.service_prefix', trim((string) ($_POST['service_prefix'] ?? 'SRV')) ?: 'SRV', 'belgeler');
            app_set_setting($db, 'docs.rental_prefix', trim((string) ($_POST['rental_prefix'] ?? 'KIR')) ?: 'KIR', 'belgeler');
            app_set_setting($db, 'docs.stock_prefix', trim((string) ($_POST['stock_prefix'] ?? 'STK')) ?: 'STK', 'belgeler');
            app_set_setting($db, 'docs.service_series', trim((string) ($_POST['service_series'] ?? 'SRV-{YYYY}-{SEQ}')) ?: 'SRV-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.rental_series', trim((string) ($_POST['rental_series'] ?? 'KIR-{YYYY}-{SEQ}')) ?: 'KIR-{YYYY}-{SEQ}', 'belgeler');
            app_set_setting($db, 'docs.stock_series', trim((string) ($_POST['stock_series'] ?? 'STK-{YYYY}-{SEQ}')) ?: 'STK-{YYYY}-{SEQ}', 'belgeler');
            $seriesScope = trim((string) ($_POST['series_scope'] ?? 'global'));
            if (!in_array($seriesScope, ['global', 'firm', 'branch', 'firm_branch'], true)) {
                $seriesScope = 'global';
            }
            app_set_setting($db, 'docs.series_scope', $seriesScope, 'belgeler');

            app_audit_log('core', 'update_document_prefixes', 'core_settings', null, 'Belge numara serileri guncellendi.');
            core_post_redirect('docs');
        }

        if ($action === 'create_branch') {
            $firmId = (int) ($_POST['firm_id'] ?? 0);
            $branchName = trim((string) ($_POST['name'] ?? ''));

            if ($firmId <= 0 || $branchName === '') {
                throw new RuntimeException('Sube icin firma ve ad zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO core_branches (firm_id, name, phone, email, address, city, district, status)
                VALUES (:firm_id, :name, :phone, :email, :address, :city, :district, :status)
            ');
            $stmt->execute([
                'firm_id' => $firmId,
                'name' => $branchName,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
                'city' => trim((string) ($_POST['city'] ?? '')) ?: null,
                'district' => trim((string) ($_POST['district'] ?? '')) ?: null,
                'status' => isset($_POST['status']) ? 1 : 0,
            ]);

            app_audit_log('core', 'create_branch', 'core_branches', (int) $db->lastInsertId(), 'Yeni sube olusturuldu: ' . $branchName);
            core_post_redirect('branch');
        }

        if ($action === 'update_branch_status') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);

            if ($branchId <= 0) {
                throw new RuntimeException('Gecerli bir sube secilmedi.');
            }

            $stmt = $db->prepare('UPDATE core_branches SET status = :status WHERE id = :id');
            $stmt->execute([
                'status' => (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0,
                'id' => $branchId,
            ]);

            app_audit_log('core', 'update_branch_status', 'core_branches', $branchId, 'Sube durumu guncellendi.');
            core_post_redirect('branch_status');
        }

        if ($action === 'save_branch_close_checklist') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $closeDate = trim((string) ($_POST['close_date'] ?? date('Y-m-d')));
            $closeNote = trim((string) ($_POST['close_note'] ?? ''));
            $selectedItems = $_POST['check_items'] ?? [];
            $itemCatalog = core_branch_close_checklist_items();
            $branchLookup = [];
            foreach ($branches ?? [] as $branchRow) {
                $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
            }
            if ($branchLookup === []) {
                foreach (app_fetch_all($db, 'SELECT id, firm_id, name, status FROM core_branches ORDER BY id ASC') as $branchRow) {
                    $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
                }
            }

            if ($branchId <= 0 || !isset($branchLookup[$branchId])) {
                throw new RuntimeException('Kapanis listesi icin gecerli bir sube secilmedi.');
            }

            $checkedMap = [];
            foreach ($itemCatalog as $itemKey => $itemMeta) {
                $checkedMap[$itemKey] = is_array($selectedItems) && in_array($itemKey, $selectedItems, true);
            }

            $payload = [
                'branch_id' => $branchId,
                'close_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $closeDate) ? $closeDate : date('Y-m-d'),
                'items' => $checkedMap,
                'checked_count' => count(array_filter($checkedMap, static fn(bool $checked): bool => $checked)),
                'total_count' => count($itemCatalog),
                'completion_ratio' => count($itemCatalog) > 0 ? (int) round((count(array_filter($checkedMap, static fn(bool $checked): bool => $checked)) / count($itemCatalog)) * 100) : 0,
                'note' => $closeNote,
                'closed_at' => date('Y-m-d H:i:s'),
                'closed_by' => (int) ($currentUser['id'] ?? 0),
                'closed_by_name' => (string) ($currentUser['full_name'] ?? 'Sistem'),
            ];

            app_set_setting(
                $db,
                'branch.close_checklist.' . $branchId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sube'
            );

            app_audit_log(
                'core',
                'save_branch_close_checklist',
                'core_branches',
                $branchId,
                'Sube kapanis kontrol listesi kaydedildi: ' . json_encode([
                    'branch_id' => $branchId,
                    'close_date' => $payload['close_date'],
                    'checked_count' => $payload['checked_count'],
                    'total_count' => $payload['total_count'],
                    'completion_ratio' => $payload['completion_ratio'],
                    'note' => $closeNote,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            core_post_redirect('branch_close_checklist');
        }

        if ($action === 'save_branch_open_checklist') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $openDate = trim((string) ($_POST['open_date'] ?? date('Y-m-d')));
            $openNote = trim((string) ($_POST['open_note'] ?? ''));
            $selectedItems = $_POST['check_items'] ?? [];
            $itemCatalog = core_branch_open_checklist_items();
            $branchLookup = [];
            foreach ($branches ?? [] as $branchRow) {
                $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
            }
            if ($branchLookup === []) {
                foreach (app_fetch_all($db, 'SELECT id, firm_id, name, status FROM core_branches ORDER BY id ASC') as $branchRow) {
                    $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
                }
            }

            if ($branchId <= 0 || !isset($branchLookup[$branchId])) {
                throw new RuntimeException('Acilis listesi icin gecerli bir sube secilmedi.');
            }

            $checkedMap = [];
            foreach ($itemCatalog as $itemKey => $itemMeta) {
                $checkedMap[$itemKey] = is_array($selectedItems) && in_array($itemKey, $selectedItems, true);
            }

            $checkedCount = count(array_filter($checkedMap, static fn(bool $checked): bool => $checked));
            $payload = [
                'branch_id' => $branchId,
                'open_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $openDate) ? $openDate : date('Y-m-d'),
                'items' => $checkedMap,
                'checked_count' => $checkedCount,
                'total_count' => count($itemCatalog),
                'completion_ratio' => count($itemCatalog) > 0 ? (int) round(($checkedCount / count($itemCatalog)) * 100) : 0,
                'note' => $openNote,
                'opened_at' => date('Y-m-d H:i:s'),
                'opened_by' => (int) ($currentUser['id'] ?? 0),
                'opened_by_name' => (string) ($currentUser['full_name'] ?? 'Sistem'),
            ];

            app_set_setting(
                $db,
                'branch.open_checklist.' . $branchId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sube'
            );

            app_audit_log(
                'core',
                'save_branch_open_checklist',
                'core_branches',
                $branchId,
                'Sube acilis kontrol listesi kaydedildi: ' . json_encode([
                    'branch_id' => $branchId,
                    'open_date' => $payload['open_date'],
                    'checked_count' => $payload['checked_count'],
                    'total_count' => $payload['total_count'],
                    'completion_ratio' => $payload['completion_ratio'],
                    'note' => $openNote,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            core_post_redirect('branch_open_checklist');
        }

        if ($action === 'save_branch_expense_allocation') {
            $expenseType = trim((string) ($_POST['expense_type'] ?? 'Genel Gider'));
            $allocationBasis = trim((string) ($_POST['allocation_basis'] ?? 'equal'));
            $allocationPeriod = trim((string) ($_POST['allocation_period'] ?? date('Y-m')));
            $allocationNote = trim((string) ($_POST['allocation_note'] ?? ''));
            $totalAmount = max(0, (float) ($_POST['total_amount'] ?? 0));
            $basisCatalog = core_branch_expense_allocation_bases();

            if ($expenseType === '' || $totalAmount <= 0) {
                throw new RuntimeException('Masraf dagitimi icin gider tipi ve tutar zorunludur.');
            }

            if (!isset($basisCatalog[$allocationBasis])) {
                $allocationBasis = 'equal';
            }

            $weights = core_branch_expense_weight_map($db, $branches ?? app_fetch_all($db, 'SELECT id, firm_id, name, status FROM core_branches ORDER BY id ASC'), $allocationBasis);
            if ($weights === []) {
                throw new RuntimeException('Dagitim icin aktif sube bulunamadi.');
            }

            $weightTotal = array_sum($weights);
            $distributionRows = [];
            $distributedTotal = 0.0;
            $branchNameMap = [];
            foreach ($branches ?? [] as $branchRow) {
                $branchNameMap[(int) ($branchRow['id'] ?? 0)] = (string) ($branchRow['name'] ?? '-');
            }
            $lastBranchId = (int) array_key_last($weights);
            foreach ($weights as $branchId => $weight) {
                $shareRatio = $weightTotal > 0 ? ((float) $weight / (float) $weightTotal) : 0;
                $allocatedAmount = $branchId === $lastBranchId ? round($totalAmount - $distributedTotal, 2) : round($totalAmount * $shareRatio, 2);
                $distributedTotal += $allocatedAmount;
                $distributionRows[] = [
                    'branch_id' => (int) $branchId,
                    'branch_name' => (string) ($branchNameMap[(int) $branchId] ?? ('Sube #' . $branchId)),
                    'weight' => (float) $weight,
                    'share_ratio' => round($shareRatio * 100, 2),
                    'allocated_amount' => round($allocatedAmount, 2),
                ];
            }

            $payload = [
                'expense_type' => $expenseType,
                'allocation_basis' => $allocationBasis,
                'allocation_period' => preg_match('/^\d{4}-\d{2}$/', $allocationPeriod) ? $allocationPeriod : date('Y-m'),
                'total_amount' => round($totalAmount, 2),
                'distribution_rows' => $distributionRows,
                'note' => $allocationNote,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => (int) ($currentUser['id'] ?? 0),
                'created_by_name' => (string) ($currentUser['full_name'] ?? 'Sistem'),
            ];

            app_set_setting(
                $db,
                'expense.allocation.last_plan',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'gider'
            );

            app_audit_log(
                'core',
                'save_branch_expense_allocation',
                'core_settings',
                null,
                'Sube bazli masraf dagitimi kaydedildi: ' . json_encode([
                    'expense_type' => $payload['expense_type'],
                    'allocation_basis' => $payload['allocation_basis'],
                    'allocation_period' => $payload['allocation_period'],
                    'total_amount' => $payload['total_amount'],
                    'branch_count' => count($distributionRows),
                    'note' => $allocationNote,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            core_post_redirect('branch_expense_allocation');
        }

        if ($action === 'save_branch_target_goal') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $targetPeriod = trim((string) ($_POST['target_period'] ?? date('Y-m')));
            $targetNote = trim((string) ($_POST['target_note'] ?? ''));
            $targetFields = core_branch_target_goal_fields();
            $branchLookup = [];
            foreach ($branches ?? [] as $branchRow) {
                $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
            }
            if ($branchLookup === []) {
                foreach (app_fetch_all($db, 'SELECT id, firm_id, name, status FROM core_branches ORDER BY id ASC') as $branchRow) {
                    $branchLookup[(int) ($branchRow['id'] ?? 0)] = $branchRow;
                }
            }

            if ($branchId <= 0 || !isset($branchLookup[$branchId])) {
                throw new RuntimeException('Hedef takibi icin gecerli bir sube secilmedi.');
            }

            $goals = [];
            foreach ($targetFields as $fieldKey => $fieldLabel) {
                $goals[$fieldKey] = max(0, (float) ($_POST['goal'][$fieldKey] ?? 0));
            }

            $payload = [
                'branch_id' => $branchId,
                'target_period' => preg_match('/^\d{4}-\d{2}$/', $targetPeriod) ? $targetPeriod : date('Y-m'),
                'goals' => $goals,
                'note' => $targetNote,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => (int) ($currentUser['id'] ?? 0),
                'updated_by_name' => (string) ($currentUser['full_name'] ?? 'Sistem'),
            ];

            app_set_setting(
                $db,
                'branch.target_goal.' . $branchId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sube'
            );

            app_audit_log(
                'core',
                'save_branch_target_goal',
                'core_branches',
                $branchId,
                'Sube hedef plani kaydedildi: ' . json_encode([
                    'branch_id' => $branchId,
                    'target_period' => $payload['target_period'],
                    'goals' => $goals,
                    'note' => $targetNote,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            core_post_redirect('branch_target_goal');
        }

        if ($action === 'create_branch_transfer_request') {
            $sourceBranchId = (int) ($_POST['source_branch_id'] ?? 0);
            $targetBranchId = (int) ($_POST['target_branch_id'] ?? 0);
            $transferType = trim((string) ($_POST['transfer_type'] ?? 'kasa'));
            $amount = max(0, (float) ($_POST['amount'] ?? 0));
            $resourceLabel = trim((string) ($_POST['resource_label'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $approverUserId = (int) ($_POST['approver_user_id'] ?? 0);

            if ($sourceBranchId <= 0 || $targetBranchId <= 0 || $sourceBranchId === $targetBranchId) {
                throw new RuntimeException('Transfer icin farkli kaynak ve hedef sube secilmelidir.');
            }
            if (!in_array($transferType, ['kasa', 'banka', 'depo', 'operasyon'], true)) {
                $transferType = 'operasyon';
            }
            if ($amount <= 0) {
                throw new RuntimeException('Transfer tutari sifirdan buyuk olmalidir.');
            }

            $requests = core_branch_transfer_request_store($db);
            $requests[] = [
                'request_id' => 'BTR-' . date('YmdHis') . '-' . substr(sha1((string) microtime(true)), 0, 6),
                'source_branch_id' => $sourceBranchId,
                'target_branch_id' => $targetBranchId,
                'transfer_type' => $transferType,
                'amount' => round($amount, 2),
                'resource_label' => $resourceLabel,
                'note' => $note,
                'approver_user_id' => $approverUserId > 0 ? $approverUserId : null,
                'status' => 'beklemede',
                'requested_at' => date('Y-m-d H:i:s'),
                'requested_by' => (int) ($currentUser['id'] ?? 0),
                'requested_by_name' => (string) ($currentUser['full_name'] ?? 'Sistem'),
                'decision_at' => null,
                'decision_by' => null,
                'decision_by_name' => null,
                'decision_note' => null,
            ];
            core_save_branch_transfer_request_store($db, $requests);

            app_audit_log('core', 'create_branch_transfer_request', 'core_branches', $sourceBranchId, 'Subeler arasi transfer onayi talebi acildi: ' . json_encode([
                'source_branch_id' => $sourceBranchId,
                'target_branch_id' => $targetBranchId,
                'transfer_type' => $transferType,
                'amount' => round($amount, 2),
                'approver_user_id' => $approverUserId > 0 ? $approverUserId : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('branch_transfer_request');
        }

        if ($action === 'decide_branch_transfer_request') {
            $requestId = trim((string) ($_POST['request_id'] ?? ''));
            $decision = trim((string) ($_POST['decision'] ?? ''));
            $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
            if ($requestId === '' || !in_array($decision, ['onaylandi', 'reddedildi'], true)) {
                throw new RuntimeException('Transfer onayi icin gecerli karar secilmedi.');
            }

            $requests = core_branch_transfer_request_store($db);
            $updated = false;
            foreach ($requests as &$requestRow) {
                if ((string) ($requestRow['request_id'] ?? '') !== $requestId) {
                    continue;
                }
                $requestRow['status'] = $decision;
                $requestRow['decision_at'] = date('Y-m-d H:i:s');
                $requestRow['decision_by'] = (int) ($currentUser['id'] ?? 0);
                $requestRow['decision_by_name'] = (string) ($currentUser['full_name'] ?? 'Sistem');
                $requestRow['decision_note'] = $decisionNote !== '' ? $decisionNote : null;
                $updated = true;
                break;
            }
            unset($requestRow);

            if (!$updated) {
                throw new RuntimeException('Transfer talebi bulunamadi.');
            }

            core_save_branch_transfer_request_store($db, $requests);
            app_audit_log('core', 'decide_branch_transfer_request', 'core_settings', null, 'Subeler arasi transfer onayi karari verildi: ' . json_encode([
                'request_id' => $requestId,
                'decision' => $decision,
                'decision_note' => $decisionNote,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('branch_transfer_decision');
        }

        if ($action === 'create_internal_service_invoice') {
            $sourceBranchId = (int) ($_POST['source_branch_id'] ?? 0);
            $targetBranchId = (int) ($_POST['target_branch_id'] ?? 0);
            $invoiceDate = trim((string) ($_POST['invoice_date'] ?? date('Y-m-d')));
            $dueDate = trim((string) ($_POST['due_date'] ?? $invoiceDate));
            $serviceTitle = trim((string) ($_POST['service_title'] ?? 'Ic Hizmet'));
            $description = trim((string) ($_POST['description'] ?? ''));
            $quantity = max(0.001, (float) ($_POST['quantity'] ?? 1));
            $unitPrice = max(0, (float) ($_POST['unit_price'] ?? 0));
            $vatRate = max(0, (float) ($_POST['vat_rate'] ?? 20));
            $currencyCode = trim((string) ($_POST['currency_code'] ?? 'TRY')) ?: 'TRY';

            if ($sourceBranchId <= 0 || $targetBranchId <= 0 || $sourceBranchId === $targetBranchId) {
                throw new RuntimeException('Ic hizmet faturasi icin farkli kaynak ve hedef sube secilmelidir.');
            }
            if ($unitPrice <= 0) {
                throw new RuntimeException('Birim fiyat sifirdan buyuk olmalidir.');
            }

            $sourceBranch = $branchMap[$sourceBranchId] ?? null;
            $targetBranch = $branchMap[$targetBranchId] ?? null;
            if (!$sourceBranch || !$targetBranch) {
                throw new RuntimeException('Kaynak veya hedef sube bulunamadi.');
            }

            $lineDescription = $serviceTitle !== '' ? $serviceTitle . ' - ' . ($description !== '' ? $description : 'Ic hizmet yansitmasi') : ($description !== '' ? $description : 'Ic hizmet yansitmasi');
            $lineTotal = round($quantity * $unitPrice, 2);
            $serviceRef = 'ICH-' . date('YmdHis') . '-' . substr(sha1((string) microtime(true)), 0, 6);

            $db->beginTransaction();
            try {
                $salesCariId = core_internal_service_cari($db, $sourceBranchId, $targetBranch, $firmNameMap, 'musteri');
                $purchaseCariId = core_internal_service_cari($db, $targetBranchId, $sourceBranch, $firmNameMap, 'tedarikci');

                $commonNote = '[IC-HIZMET:' . $serviceRef . '] ' . ($description !== '' ? $description : 'Subeler arasi ic hizmet faturasi');
                $salesInvoiceId = app_create_invoice_from_source($db, [
                    'branch_id' => $sourceBranchId,
                    'cari_id' => $salesCariId,
                    'invoice_type' => 'satis',
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'currency_code' => $currencyCode,
                    'notes' => $commonNote,
                    'source_module' => 'core',
                    'movement_description' => 'Ic hizmet satis faturasi olusturuldu',
                    'created_by' => (int) ($currentUser['id'] ?? 1),
                    'items' => [[
                        'description' => $lineDescription,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'vat_rate' => $vatRate,
                        'line_total' => $lineTotal,
                    ]],
                ]);

                $purchaseInvoiceId = app_create_invoice_from_source($db, [
                    'branch_id' => $targetBranchId,
                    'cari_id' => $purchaseCariId,
                    'invoice_type' => 'alis',
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'currency_code' => $currencyCode,
                    'notes' => $commonNote,
                    'source_module' => 'core',
                    'movement_description' => 'Ic hizmet alis faturasi olusturuldu',
                    'created_by' => (int) ($currentUser['id'] ?? 1),
                    'items' => [[
                        'description' => $lineDescription,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'vat_rate' => $vatRate,
                        'line_total' => $lineTotal,
                    ]],
                ]);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            app_audit_log('core', 'create_internal_service_invoice', 'invoice_headers', $salesInvoiceId, 'Firma ici ic hizmet faturasi olusturuldu: ' . json_encode([
                'reference' => $serviceRef,
                'source_branch_id' => $sourceBranchId,
                'target_branch_id' => $targetBranchId,
                'sales_invoice_id' => $salesInvoiceId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'line_total' => $lineTotal,
                'vat_rate' => $vatRate,
                'currency_code' => $currencyCode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('internal_service_invoice');
        }

        if ($action === 'create_user') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || $email === '' || $password === '') {
                throw new RuntimeException('Kullanici adi, e-posta ve sifre zorunludur.');
            }

            if ((int) app_metric($db, 'SELECT COUNT(*) FROM core_users WHERE email = :email', ['email' => $email]) > 0) {
                throw new RuntimeException('Bu e-posta ile kayitli kullanici zaten var.');
            }

            $stmt = $db->prepare('
                INSERT INTO core_users (role_id, branch_id, full_name, email, phone, password_hash, status)
                VALUES (:role_id, :branch_id, :full_name, :email, :phone, :password_hash, :status)
            ');
            $stmt->execute([
                'role_id' => (int) ($_POST['role_id'] ?? 0) ?: null,
                'branch_id' => (int) ($_POST['branch_id'] ?? 0) ?: null,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => isset($_POST['status']) ? 1 : 0,
            ]);

            app_audit_log('core', 'create_user', 'core_users', (int) $db->lastInsertId(), 'Yeni kullanici olusturuldu: ' . $email);
            core_post_redirect('user');
        }

        if ($action === 'update_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newStatus = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

            if ($userId <= 0) {
                throw new RuntimeException('Gecerli bir kullanici secilmedi.');
            }

            if ($currentUser && (int) $currentUser['id'] === $userId && $newStatus === 0) {
                throw new RuntimeException('Kendi hesabinizi pasife alamazsiniz.');
            }

            $stmt = $db->prepare('
                UPDATE core_users
                SET role_id = :role_id,
                    branch_id = :branch_id,
                    full_name = :full_name,
                    email = :email,
                    phone = :phone,
                    status = :status
                WHERE id = :id
            ');
            $stmt->execute([
                'role_id' => (int) ($_POST['role_id'] ?? 0) ?: null,
                'branch_id' => (int) ($_POST['branch_id'] ?? 0) ?: null,
                'full_name' => trim((string) ($_POST['full_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'status' => $newStatus,
                'id' => $userId,
            ]);

            app_audit_log('core', 'update_user', 'core_users', $userId, 'Kullanici bilgileri guncellendi.');
            core_post_redirect('user_updated');
        }

        if ($action === 'reset_user_password') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');

            if ($userId <= 0 || $newPassword === '') {
                throw new RuntimeException('Kullanici ve yeni sifre zorunludur.');
            }

            $stmt = $db->prepare('UPDATE core_users SET password_hash = :password_hash WHERE id = :id');
            $stmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);

            app_audit_log('core', 'reset_user_password', 'core_users', $userId, 'Kullanici sifresi sifirlandi.');
            core_post_redirect('password');
        }

        if ($action === 'update_role_permissions') {
            $rolesPayload = $_POST['permissions'] ?? [];
            $defaultMap = app_default_role_modules();
            $permissionRoles = app_fetch_all($db, 'SELECT id, name, code FROM core_roles ORDER BY id ASC');

            foreach ($permissionRoles as $role) {
                $roleCode = (string) ($role['code'] ?? '');
                if ($roleCode === '') {
                    continue;
                }

                if ($roleCode === 'super_admin') {
                    app_set_setting($db, 'permissions.' . $roleCode, json_encode(array_keys(app_modules())), 'yetki');
                    continue;
                }

                $allowed = $rolesPayload[$roleCode] ?? [];
                if (!is_array($allowed)) {
                    $allowed = $defaultMap[$roleCode] ?? [];
                }

                $clean = array_values(array_unique(array_map('strval', $allowed)));
                if (!in_array('dashboard', $clean, true)) {
                    $clean[] = 'dashboard';
                }

                app_set_setting($db, 'permissions.' . $roleCode, json_encode($clean), 'yetki');
            }

            app_audit_log('core', 'update_role_permissions', 'core_settings', null, 'Rol bazli modul yetkileri guncellendi.');
            core_post_redirect('permissions');
        }

        if ($action === 'update_permission_matrix') {
            $matrixPayload = $_POST['permission_matrix'] ?? [];
            $defaultMatrix = app_default_role_action_permissions();
            $permissionRoles = app_fetch_all($db, 'SELECT id, name, code FROM core_roles ORDER BY id ASC');
            $allowedActions = array_keys(app_permission_actions());
            $allowedModules = array_keys(app_modules());

            foreach ($permissionRoles as $role) {
                $roleCode = (string) ($role['code'] ?? '');
                if ($roleCode === '') {
                    continue;
                }

                if ($roleCode === 'super_admin') {
                    app_set_setting($db, 'permissions.matrix.' . $roleCode, json_encode($defaultMatrix['super_admin'] ?? []), 'yetki');
                    continue;
                }

                $roleMatrix = $matrixPayload[$roleCode] ?? [];
                if (!is_array($roleMatrix)) {
                    $roleMatrix = $defaultMatrix[$roleCode] ?? [];
                }

                $clean = [];
                foreach ($roleMatrix as $moduleKey => $actions) {
                    $moduleKey = (string) $moduleKey;
                    if (!in_array($moduleKey, $allowedModules, true) || !is_array($actions)) {
                        continue;
                    }

                    $cleanActions = array_values(array_intersect(array_unique(array_map('strval', $actions)), $allowedActions));
                    if ($cleanActions !== []) {
                        $clean[$moduleKey] = $cleanActions;
                    }
                }

                if (!isset($clean['dashboard'])) {
                    $clean['dashboard'] = ['view'];
                } elseif (!in_array('view', $clean['dashboard'], true)) {
                    $clean['dashboard'][] = 'view';
                }

                app_set_setting($db, 'permissions.matrix.' . $roleCode, json_encode($clean), 'yetki');
            }

            app_audit_log('core', 'update_permission_matrix', 'core_settings', null, 'Detayli yetki matrisi guncellendi.');
            core_post_redirect('permission_matrix');
        }

        if ($action === 'update_department_permissions') {
            $departmentPayload = $_POST['department_permissions'] ?? [];
            $allowedModules = array_keys(app_modules());
            $departmentsForPermissions = app_fetch_all($db, 'SELECT id, name FROM hr_departments ORDER BY id ASC');

            foreach ($departmentsForPermissions as $departmentRow) {
                $departmentId = (int) ($departmentRow['id'] ?? 0);
                if ($departmentId <= 0) {
                    continue;
                }

                $allowed = $departmentPayload[$departmentId] ?? [];
                if (!is_array($allowed)) {
                    $allowed = [];
                }

                $clean = [];
                foreach ($allowed as $moduleKey) {
                    $moduleKey = (string) $moduleKey;
                    if (in_array($moduleKey, $allowedModules, true) && !in_array($moduleKey, $clean, true)) {
                        $clean[] = $moduleKey;
                    }
                }

                app_set_setting($db, 'permissions.department.modules.' . $departmentId, json_encode($clean), 'yetki');
            }

            app_audit_log('core', 'update_department_permissions', 'core_settings', null, 'Departman bazli modul yetkileri guncellendi.');
            core_post_redirect('department_permissions');
        }

        if ($action === 'update_department_permission_matrix') {
            $matrixPayload = $_POST['department_permission_matrix'] ?? [];
            $allowedActions = array_keys(app_permission_actions());
            $allowedModules = array_keys(app_modules());
            $departmentsForMatrix = app_fetch_all($db, 'SELECT id, name FROM hr_departments ORDER BY id ASC');

            foreach ($departmentsForMatrix as $departmentRow) {
                $departmentId = (int) ($departmentRow['id'] ?? 0);
                if ($departmentId <= 0) {
                    continue;
                }

                $departmentMatrix = $matrixPayload[$departmentId] ?? [];
                if (!is_array($departmentMatrix)) {
                    $departmentMatrix = [];
                }

                $clean = [];
                foreach ($departmentMatrix as $moduleKey => $actions) {
                    $moduleKey = (string) $moduleKey;
                    if (!in_array($moduleKey, $allowedModules, true) || !is_array($actions)) {
                        continue;
                    }

                    $filteredActions = [];
                    foreach ($actions as $actionKey) {
                        $actionKey = (string) $actionKey;
                        if (in_array($actionKey, $allowedActions, true) && !in_array($actionKey, $filteredActions, true)) {
                            $filteredActions[] = $actionKey;
                        }
                    }

                    if ($filteredActions) {
                        $clean[$moduleKey] = $filteredActions;
                    }
                }

                app_set_setting($db, 'permissions.department.matrix.' . $departmentId, json_encode($clean), 'yetki');
            }

            app_audit_log('core', 'update_department_permission_matrix', 'core_settings', null, 'Departman bazli detayli yetki matrisi guncellendi.');
            core_post_redirect('department_permission_matrix');
        }

        if ($action === 'update_approval_rules') {
            $rulePayload = $_POST['approval_rules'] ?? [];
            $catalog = app_approval_rule_catalog();
            $allowedRoleCodes = array_map(static fn(array $roleRow): string => (string) ($roleRow['code'] ?? ''), app_fetch_all($db, 'SELECT code FROM core_roles ORDER BY id ASC'));

            foreach ($catalog as $ruleKey => $ruleMeta) {
                $ruleRow = $rulePayload[$ruleKey] ?? [];
                if (!is_array($ruleRow)) {
                    $ruleRow = [];
                }

                $approverRoleCode = trim((string) ($ruleRow['approver_role_code'] ?? ''));
                if ($approverRoleCode !== '' && !in_array($approverRoleCode, $allowedRoleCodes, true)) {
                    $approverRoleCode = '';
                }

                $secondApproverRoleCode = trim((string) ($ruleRow['second_approver_role_code'] ?? ''));
                if ($secondApproverRoleCode !== '' && !in_array($secondApproverRoleCode, $allowedRoleCodes, true)) {
                    $secondApproverRoleCode = '';
                }

                $ruleConfig = [
                    'enabled' => !empty($ruleRow['enabled']),
                    'approver_role_code' => $approverRoleCode,
                    'require_second_approval' => !empty($ruleRow['require_second_approval']),
                    'second_approver_role_code' => $secondApproverRoleCode,
                    'require_note' => !empty($ruleRow['require_note']),
                    'min_amount' => max(0, (float) ($ruleRow['min_amount'] ?? 0)),
                ];

                app_set_setting($db, 'approvals.rule.' . $ruleKey, json_encode($ruleConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'onay');
            }

            app_audit_log('core', 'update_approval_rules', 'core_settings', null, 'Islem bazli onay kurallari guncellendi.');
            core_post_redirect('approval_rules');
        }

        if ($action === 'apply_permission_template') {
            $roleCode = trim((string) ($_POST['role_code'] ?? ''));
            $templateKey = trim((string) ($_POST['template_key'] ?? ''));
            $templates = core_permission_templates();
            $rolesByCode = [];
            foreach (app_fetch_all($db, 'SELECT id, name, code FROM core_roles ORDER BY id ASC') as $roleRow) {
                $rolesByCode[(string) ($roleRow['code'] ?? '')] = $roleRow;
            }

            if ($roleCode === '' || !isset($rolesByCode[$roleCode])) {
                throw new RuntimeException('Yetki sablonu icin gecerli bir rol secilmedi.');
            }

            if (!isset($templates[$templateKey])) {
                throw new RuntimeException('Secilen yetki sablonu bulunamadi.');
            }

            if ($roleCode === 'super_admin') {
                throw new RuntimeException('Super Admin rolune sablon uygulanamaz.');
            }

            $template = $templates[$templateKey];
            $moduleList = array_values(array_unique(array_map('strval', $template['modules'] ?? [])));
            if (!in_array('dashboard', $moduleList, true)) {
                $moduleList[] = 'dashboard';
            }

            $matrix = is_array($template['matrix'] ?? null) ? $template['matrix'] : [];
            if (!isset($matrix['dashboard'])) {
                $matrix['dashboard'] = ['view'];
            } elseif (!in_array('view', $matrix['dashboard'], true)) {
                $matrix['dashboard'][] = 'view';
            }

            app_set_setting($db, 'permissions.' . $roleCode, json_encode($moduleList), 'yetki');
            app_set_setting($db, 'permissions.matrix.' . $roleCode, json_encode($matrix), 'yetki');

            app_audit_log('core', 'apply_permission_template', 'core_settings', (int) ($rolesByCode[$roleCode]['id'] ?? 0), 'Yetki sablonu uygulandi: ' . json_encode([
                'role_code' => $roleCode,
                'role_name' => (string) ($rolesByCode[$roleCode]['name'] ?? $roleCode),
                'template_key' => $templateKey,
                'template_label' => (string) ($template['label'] ?? $templateKey),
                'module_count' => count($moduleList),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            core_post_redirect('permission_template');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Sistem islemi tamamlayamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$firmRows = app_fetch_all($db, 'SELECT * FROM core_firms ORDER BY status DESC, id ASC');
$firm = $firmRows[0] ?? null;
$roles = app_fetch_all($db, 'SELECT id, name, code FROM core_roles ORDER BY id ASC');
$departments = app_fetch_all($db, 'SELECT id, name FROM hr_departments ORDER BY name ASC, id ASC');
$branches = app_fetch_all($db, 'SELECT id, firm_id, name, phone, email, city, district, status FROM core_branches ORDER BY id ASC');
$firmStats = [];
$firmNameMap = [];
$branchMap = [];
foreach ($firmRows as $row) {
    $firmNameMap[(int) $row['id']] = (string) $row['company_name'];
    $firmStats[(int) $row['id']] = [
        'branch_count' => 0,
        'user_count' => 0,
    ];
}
$branchMap = [];
foreach ($branches as $branchRow) {
    $branchMap[(int) ($branchRow['id'] ?? 0)] = $branchRow;
}
$branchCloseChecklistItems = core_branch_close_checklist_items();
$branchOpenChecklistItems = core_branch_open_checklist_items();
$branchCloseRows = [];
$branchCloseOverview = [
    'closed_today' => 0,
    'partial_count' => 0,
    'full_count' => 0,
];
$branchCloseMetrics = [];
$branchOpenRows = [];
$branchOpenOverview = [
    'opened_today' => 0,
    'partial_count' => 0,
    'full_count' => 0,
];
$branchOpenMetrics = [];
foreach ($branches as $branchRow) {
    $branchId = (int) ($branchRow['id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $branchCloseMetrics[$branchId] = [
        'cashbox_count' => 0,
        'bank_account_count' => 0,
        'open_order_count' => 0,
        'today_invoice_count' => 0,
        'open_service_count' => 0,
        'active_rental_count' => 0,
    ];
    $branchOpenMetrics[$branchId] = [
        'user_count' => 0,
        'cashbox_count' => 0,
        'bank_account_count' => 0,
        'low_stock_count' => 0,
        'open_service_count' => 0,
        'today_rental_count' => 0,
    ];
}

if ($branchCloseMetrics !== []) {
    if (app_table_exists($db, 'finance_cashboxes') && app_column_exists($db, 'finance_cashboxes', 'branch_id')) {
        foreach (app_fetch_all($db, 'SELECT branch_id, COUNT(*) AS item_count FROM finance_cashboxes GROUP BY branch_id') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['cashbox_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['cashbox_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'finance_bank_accounts') && app_column_exists($db, 'finance_bank_accounts', 'branch_id')) {
        foreach (app_fetch_all($db, 'SELECT branch_id, COUNT(*) AS item_count FROM finance_bank_accounts GROUP BY branch_id') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['bank_account_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['bank_account_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'sales_orders') && app_column_exists($db, 'sales_orders', 'branch_id') && app_column_exists($db, 'sales_orders', 'status')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM sales_orders
            WHERE COALESCE(status, "") NOT IN ("tamamlandi", "iptal", "teslim_edildi")
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['open_order_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'invoice_headers') && app_column_exists($db, 'invoice_headers', 'branch_id') && app_column_exists($db, 'invoice_headers', 'created_at')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM invoice_headers
            WHERE DATE(created_at) = CURDATE()
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['today_invoice_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'service_records') && app_column_exists($db, 'service_records', 'branch_id') && app_column_exists($db, 'service_records', 'closed_at')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM service_records
            WHERE closed_at IS NULL
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['open_service_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['open_service_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'rental_contracts') && app_column_exists($db, 'rental_contracts', 'branch_id') && app_column_exists($db, 'rental_contracts', 'status')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM rental_contracts
            WHERE status = "aktif"
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchCloseMetrics[$branchId])) {
                $branchCloseMetrics[$branchId]['active_rental_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'core_users') && app_column_exists($db, 'core_users', 'branch_id')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM core_users
            WHERE COALESCE(status, 0) = 1
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['user_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'stock_items') && app_column_exists($db, 'stock_items', 'branch_id') && app_column_exists($db, 'stock_items', 'current_stock') && app_column_exists($db, 'stock_items', 'minimum_stock')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM stock_items
            WHERE COALESCE(current_stock, 0) <= COALESCE(minimum_stock, 0)
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['low_stock_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'rental_contracts') && app_column_exists($db, 'rental_contracts', 'branch_id') && app_column_exists($db, 'rental_contracts', 'start_date')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id, COUNT(*) AS item_count
            FROM rental_contracts
            WHERE DATE(start_date) = CURDATE()
            GROUP BY branch_id
        ') as $metricRow) {
            $branchId = (int) ($metricRow['branch_id'] ?? 0);
            if (isset($branchOpenMetrics[$branchId])) {
                $branchOpenMetrics[$branchId]['today_rental_count'] = (int) ($metricRow['item_count'] ?? 0);
            }
        }
    }
}

foreach ($branches as $branchRow) {
    $branchId = (int) ($branchRow['id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $savedValue = app_setting($db, 'branch.close_checklist.' . $branchId, '');
    $savedRow = $savedValue !== '' ? json_decode($savedValue, true) : [];
    if (!is_array($savedRow)) {
        $savedRow = [];
    }

    $itemsState = [];
    $checkedCount = 0;
    foreach ($branchCloseChecklistItems as $itemKey => $itemMeta) {
        $isChecked = !empty($savedRow['items'][$itemKey]);
        $itemsState[$itemKey] = $isChecked;
        if ($isChecked) {
            $checkedCount++;
        }
    }

    $totalCount = count($branchCloseChecklistItems);
    $completionRatio = $totalCount > 0 ? (int) round(($checkedCount / $totalCount) * 100) : 0;
    $statusLabel = $completionRatio >= 100 ? 'Tamamlandi' : ($completionRatio > 0 ? 'Takipte' : 'Bekliyor');
    $statusTone = $completionRatio >= 100 ? 'ok' : ($completionRatio > 0 ? 'warn' : 'muted');
    $closeDate = (string) ($savedRow['close_date'] ?? '');
    if ($closeDate === date('Y-m-d') && $completionRatio > 0) {
        $branchCloseOverview['closed_today']++;
    }
    if ($completionRatio >= 100) {
        $branchCloseOverview['full_count']++;
    } elseif ($completionRatio > 0) {
        $branchCloseOverview['partial_count']++;
    }

    $branchCloseRows[] = [
        'branch_id' => $branchId,
        'firm_id' => (int) ($branchRow['firm_id'] ?? 0),
        'branch_name' => (string) ($branchRow['name'] ?? '-'),
        'firm_name' => (string) ($firmNameMap[(int) ($branchRow['firm_id'] ?? 0)] ?? '-'),
        'status' => $statusLabel,
        'status_tone' => $statusTone,
        'close_date' => $closeDate,
        'closed_at' => (string) ($savedRow['closed_at'] ?? ''),
        'closed_by_name' => (string) ($savedRow['closed_by_name'] ?? '-'),
        'checked_count' => $checkedCount,
        'total_count' => $totalCount,
        'completion_ratio' => $completionRatio,
        'note' => trim((string) ($savedRow['note'] ?? '')),
        'items' => $itemsState,
        'metrics' => $branchCloseMetrics[$branchId] ?? [
            'cashbox_count' => 0,
            'bank_account_count' => 0,
            'open_order_count' => 0,
            'today_invoice_count' => 0,
            'open_service_count' => 0,
            'active_rental_count' => 0,
        ],
    ];
}
usort($branchCloseRows, static function (array $a, array $b): int {
    $left = (string) ($a['closed_at'] ?: $a['close_date'] ?: '');
    $right = (string) ($b['closed_at'] ?: $b['close_date'] ?: '');
    return strcmp($right, $left);
});
$branchCloseHistory = app_fetch_all($db, '
    SELECT l.id, l.record_id AS branch_id, l.description, l.created_at,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "save_branch_close_checklist"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Bugun Kapanan Sube'] = (string) $branchCloseOverview['closed_today'];
$summary['Eksik Sube Kapanisi'] = (string) $branchCloseOverview['partial_count'];
$summary['Tam Sube Kapanisi'] = (string) $branchCloseOverview['full_count'];
foreach ($branches as $branchRow) {
    $branchId = (int) ($branchRow['id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $savedValue = app_setting($db, 'branch.open_checklist.' . $branchId, '');
    $savedRow = $savedValue !== '' ? json_decode($savedValue, true) : [];
    if (!is_array($savedRow)) {
        $savedRow = [];
    }

    $itemsState = [];
    $checkedCount = 0;
    foreach ($branchOpenChecklistItems as $itemKey => $itemMeta) {
        $isChecked = !empty($savedRow['items'][$itemKey]);
        $itemsState[$itemKey] = $isChecked;
        if ($isChecked) {
            $checkedCount++;
        }
    }

    $totalCount = count($branchOpenChecklistItems);
    $completionRatio = $totalCount > 0 ? (int) round(($checkedCount / $totalCount) * 100) : 0;
    $statusLabel = $completionRatio >= 100 ? 'Hazir' : ($completionRatio > 0 ? 'Hazirlaniyor' : 'Bekliyor');
    $statusTone = $completionRatio >= 100 ? 'ok' : ($completionRatio > 0 ? 'warn' : 'muted');
    $openDate = (string) ($savedRow['open_date'] ?? '');
    if ($openDate === date('Y-m-d') && $completionRatio > 0) {
        $branchOpenOverview['opened_today']++;
    }
    if ($completionRatio >= 100) {
        $branchOpenOverview['full_count']++;
    } elseif ($completionRatio > 0) {
        $branchOpenOverview['partial_count']++;
    }

    $branchOpenRows[] = [
        'branch_id' => $branchId,
        'firm_id' => (int) ($branchRow['firm_id'] ?? 0),
        'branch_name' => (string) ($branchRow['name'] ?? '-'),
        'firm_name' => (string) ($firmNameMap[(int) ($branchRow['firm_id'] ?? 0)] ?? '-'),
        'status' => $statusLabel,
        'status_tone' => $statusTone,
        'open_date' => $openDate,
        'opened_at' => (string) ($savedRow['opened_at'] ?? ''),
        'opened_by_name' => (string) ($savedRow['opened_by_name'] ?? '-'),
        'checked_count' => $checkedCount,
        'total_count' => $totalCount,
        'completion_ratio' => $completionRatio,
        'note' => trim((string) ($savedRow['note'] ?? '')),
        'items' => $itemsState,
        'metrics' => $branchOpenMetrics[$branchId] ?? [
            'user_count' => 0,
            'cashbox_count' => 0,
            'bank_account_count' => 0,
            'low_stock_count' => 0,
            'open_service_count' => 0,
            'today_rental_count' => 0,
        ],
    ];
}
usort($branchOpenRows, static function (array $a, array $b): int {
    $left = (string) ($a['opened_at'] ?: $a['open_date'] ?: '');
    $right = (string) ($b['opened_at'] ?: $b['open_date'] ?: '');
    return strcmp($right, $left);
});
$branchOpenHistory = app_fetch_all($db, '
    SELECT l.id, l.record_id AS branch_id, l.description, l.created_at,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "save_branch_open_checklist"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Bugun Acilan Sube'] = (string) $branchOpenOverview['opened_today'];
$summary['Eksik Sube Acilisi'] = (string) $branchOpenOverview['partial_count'];
$summary['Tam Sube Acilisi'] = (string) $branchOpenOverview['full_count'];
$expenseAllocationBases = core_branch_expense_allocation_bases();
$branchExpenseActualRows = [];
if (app_table_exists($db, 'finance_expenses') && app_column_exists($db, 'finance_expenses', 'branch_id') && app_column_exists($db, 'finance_expenses', 'amount')) {
    $branchExpenseActualRows = app_fetch_all($db, '
        SELECT e.branch_id,
               COALESCE(b.name, "Subesiz") AS branch_name,
               COALESCE(f.company_name, "-") AS firm_name,
               COUNT(*) AS expense_count,
               COALESCE(SUM(e.amount), 0) AS total_amount,
               MAX(e.expense_date) AS last_expense_date
        FROM finance_expenses e
        LEFT JOIN core_branches b ON b.id = e.branch_id
        LEFT JOIN core_firms f ON f.id = b.firm_id
        GROUP BY e.branch_id, b.name, f.company_name
        ORDER BY total_amount DESC, expense_count DESC
        LIMIT 12
    ');
}
$lastExpenseAllocationPlan = [];
$lastExpenseAllocationRaw = app_setting($db, 'expense.allocation.last_plan', '');
if ($lastExpenseAllocationRaw !== '') {
    $decoded = json_decode($lastExpenseAllocationRaw, true);
    if (is_array($decoded)) {
        $lastExpenseAllocationPlan = $decoded;
    }
}
$expenseAllocationHistory = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.description,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "save_branch_expense_allocation"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Masraf Dagitim Plani'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "save_branch_expense_allocation"');
$summary['Sube Gider Toplami'] = number_format((float) array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $branchExpenseActualRows)), 2, ',', '.');
foreach (app_fetch_all($db, '
    SELECT f.id AS firm_id, COUNT(b.id) AS branch_count
    FROM core_firms f
    LEFT JOIN core_branches b ON b.firm_id = f.id
    GROUP BY f.id
') as $row) {
    $firmId = (int) $row['firm_id'];
    $firmStats[$firmId]['branch_count'] = (int) $row['branch_count'];
}
foreach (app_fetch_all($db, '
    SELECT b.firm_id, COUNT(u.id) AS user_count
    FROM core_branches b
    LEFT JOIN core_users u ON u.branch_id = b.id
    GROUP BY b.firm_id
') as $row) {
    $firmId = (int) $row['firm_id'];
    if (!isset($firmStats[$firmId])) {
        $firmStats[$firmId] = ['branch_count' => 0, 'user_count' => 0];
    }
    $firmStats[$firmId]['user_count'] = (int) $row['user_count'];
}
$users = app_fetch_all($db, '
    SELECT u.id, u.role_id, u.branch_id, u.full_name, u.email, u.phone, u.status, r.name AS role_name, r.code AS role_code, b.name AS branch_name
    FROM core_users u
    LEFT JOIN core_roles r ON r.id = u.role_id
    LEFT JOIN core_branches b ON b.id = u.branch_id
    ORDER BY u.id ASC
');
$branchTransferRequests = core_branch_transfer_request_store($db);
$userNameMap = [];
foreach ($users as $userRow) {
    $userNameMap[(int) ($userRow['id'] ?? 0)] = (string) ($userRow['full_name'] ?? '-');
}
$cashboxBranchMap = [];
if (app_table_exists($db, 'finance_cashboxes') && app_column_exists($db, 'finance_cashboxes', 'branch_id')) {
    foreach (app_fetch_all($db, 'SELECT id, branch_id, name FROM finance_cashboxes ORDER BY name ASC, id ASC') as $row) {
        $cashboxBranchMap[(int) ($row['branch_id'] ?? 0)][] = (string) ($row['name'] ?? '-');
    }
}
$bankBranchMap = [];
if (app_table_exists($db, 'finance_bank_accounts') && app_column_exists($db, 'finance_bank_accounts', 'branch_id')) {
    foreach (app_fetch_all($db, 'SELECT id, branch_id, bank_name, account_name FROM finance_bank_accounts ORDER BY bank_name ASC, id ASC') as $row) {
        $label = trim((string) (($row['bank_name'] ?? '-') . ' ' . ($row['account_name'] ?? '')));
        $bankBranchMap[(int) ($row['branch_id'] ?? 0)][] = $label !== '' ? $label : '-';
    }
}
$warehouseBranchMap = [];
if (app_table_exists($db, 'stock_warehouses') && app_column_exists($db, 'stock_warehouses', 'branch_id')) {
    foreach (app_fetch_all($db, 'SELECT id, branch_id, name FROM stock_warehouses ORDER BY name ASC, id ASC') as $row) {
        $warehouseBranchMap[(int) ($row['branch_id'] ?? 0)][] = (string) ($row['name'] ?? '-');
    }
}
$branchTransferRows = [];
foreach ($branchTransferRequests as $requestRow) {
    $sourceBranchId = (int) ($requestRow['source_branch_id'] ?? 0);
    $targetBranchId = (int) ($requestRow['target_branch_id'] ?? 0);
    $approverUserId = (int) ($requestRow['approver_user_id'] ?? 0);
    $sourceBranchRow = $branchMap[$sourceBranchId] ?? [];
    $targetBranchRow = $branchMap[$targetBranchId] ?? [];
    $branchTransferRows[] = [
        'request_id' => (string) ($requestRow['request_id'] ?? ''),
        'source_branch_id' => $sourceBranchId,
        'target_branch_id' => $targetBranchId,
        'source_branch_name' => (string) ($sourceBranchRow['name'] ?? '-'),
        'target_branch_name' => (string) ($targetBranchRow['name'] ?? '-'),
        'source_label' => (string) (($firmNameMap[(int) ($sourceBranchRow['firm_id'] ?? 0)] ?? '-') . ' / ' . (($sourceBranchRow['name'] ?? '-') ?: '-')),
        'target_label' => (string) (($firmNameMap[(int) ($targetBranchRow['firm_id'] ?? 0)] ?? '-') . ' / ' . (($targetBranchRow['name'] ?? '-') ?: '-')),
        'transfer_type' => (string) ($requestRow['transfer_type'] ?? 'operasyon'),
        'amount' => (float) ($requestRow['amount'] ?? 0),
        'resource_label' => (string) ($requestRow['resource_label'] ?? ''),
        'note' => (string) ($requestRow['note'] ?? ''),
        'approver_user_id' => $approverUserId > 0 ? $approverUserId : null,
        'approver_name' => $approverUserId > 0 ? (string) ($userNameMap[$approverUserId] ?? ('Kullanici #' . $approverUserId)) : '-',
        'status' => (string) ($requestRow['status'] ?? 'beklemede'),
        'requested_at' => (string) ($requestRow['requested_at'] ?? ''),
        'requested_by_name' => (string) ($requestRow['requested_by_name'] ?? '-'),
        'decision_at' => (string) ($requestRow['decision_at'] ?? ''),
        'decision_by_name' => (string) ($requestRow['decision_by_name'] ?? '-'),
        'decision_note' => (string) ($requestRow['decision_note'] ?? ''),
    ];
}
usort($branchTransferRows, static fn(array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')));
$pendingBranchTransferRows = array_values(array_filter($branchTransferRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'beklemede'));
$approvedBranchTransferCount = count(array_filter($branchTransferRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'onaylandi'));
$rejectedBranchTransferCount = count(array_filter($branchTransferRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'reddedildi'));
$summary['Transfer Onay Bekleyen'] = (string) count($pendingBranchTransferRows);
$summary['Transfer Onaylanan'] = (string) $approvedBranchTransferCount;
$summary['Transfer Reddedilen'] = (string) $rejectedBranchTransferCount;
$internalServiceInvoiceRows = [];
if (app_table_exists($db, 'invoice_headers') && app_table_exists($db, 'cari_cards') && app_column_exists($db, 'invoice_headers', 'notes')) {
    $internalServiceInvoiceRows = app_fetch_all($db, '
        SELECT h.id, h.branch_id, h.cari_id, h.invoice_type, h.invoice_no, h.invoice_date, h.currency_code,
               h.subtotal, h.vat_total, h.grand_total, h.notes, h.created_at,
               COALESCE(c.company_name, c.full_name, "-") AS cari_name,
               COALESCE(b.name, "-") AS branch_name,
               COALESCE(f.company_name, "-") AS firm_name
        FROM invoice_headers h
        INNER JOIN cari_cards c ON c.id = h.cari_id
        LEFT JOIN core_branches b ON b.id = h.branch_id
        LEFT JOIN core_firms f ON f.id = b.firm_id
        WHERE h.notes LIKE "%[IC-HIZMET:%"
        ORDER BY h.id DESC
        LIMIT 20
    ');
}
$internalServiceAuditRows = app_fetch_all($db, '
    SELECT l.id, l.record_id, l.description, l.created_at,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "create_internal_service_invoice"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Ic Hizmet Faturasi'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "create_internal_service_invoice"');
$summary['Ic Hizmet Tutar'] = number_format((float) array_sum(array_map(static fn(array $row): float => (float) ($row['grand_total'] ?? 0), array_filter($internalServiceInvoiceRows, static fn(array $row): bool => (string) ($row['invoice_type'] ?? '') === 'satis'))), 2, ',', '.');
$branchPerformanceRows = [];
$branchPerformanceMap = [];
foreach ($branches as $branchRow) {
    $branchId = (int) ($branchRow['id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $branchPerformanceMap[$branchId] = [
        'branch_id' => $branchId,
        'firm_id' => (int) ($branchRow['firm_id'] ?? 0),
        'branch_name' => (string) ($branchRow['name'] ?? '-'),
        'firm_name' => (string) ($firmNameMap[(int) ($branchRow['firm_id'] ?? 0)] ?? '-'),
        'status' => (int) ($branchRow['status'] ?? 0),
        'user_count' => 0,
        'login_30d' => 0,
        'offer_count' => 0,
        'approved_offer_count' => 0,
        'offer_total' => 0.0,
        'order_count' => 0,
        'completed_order_count' => 0,
        'order_total' => 0.0,
        'invoice_count' => 0,
        'invoice_total' => 0.0,
        'open_service_count' => 0,
        'closed_service_count' => 0,
        'active_rental_count' => 0,
        'rental_monthly_total' => 0.0,
        'activity_score' => 0.0,
        'sales_score' => 0.0,
        'service_score' => 0.0,
        'rental_score' => 0.0,
        'total_score' => 0.0,
        'grade' => 'D',
    ];
}

if ($branchPerformanceMap !== []) {
    foreach ($users as $userRow) {
        $branchId = (int) ($userRow['branch_id'] ?? 0);
        if (isset($branchPerformanceMap[$branchId])) {
            $branchPerformanceMap[$branchId]['user_count']++;
        }
    }

    foreach (app_fetch_all($db, '
        SELECT u.branch_id, COUNT(*) AS login_count
        FROM core_audit_logs l
        INNER JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
        WHERE l.module_name = "auth"
          AND l.action_name = "login_success"
          AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY u.branch_id
    ') as $row) {
        $branchId = (int) ($row['branch_id'] ?? 0);
        if (isset($branchPerformanceMap[$branchId])) {
            $branchPerformanceMap[$branchId]['login_30d'] = (int) ($row['login_count'] ?? 0);
        }
    }

    if (app_table_exists($db, 'sales_offers')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id,
                   COUNT(*) AS offer_count,
                   SUM(CASE WHEN status = "onaylandi" THEN 1 ELSE 0 END) AS approved_offer_count,
                   COALESCE(SUM(grand_total), 0) AS offer_total
            FROM sales_offers
            GROUP BY branch_id
        ') as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (isset($branchPerformanceMap[$branchId])) {
                $branchPerformanceMap[$branchId]['offer_count'] = (int) ($row['offer_count'] ?? 0);
                $branchPerformanceMap[$branchId]['approved_offer_count'] = (int) ($row['approved_offer_count'] ?? 0);
                $branchPerformanceMap[$branchId]['offer_total'] = (float) ($row['offer_total'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'sales_orders')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id,
                   COUNT(*) AS order_count,
                   SUM(CASE WHEN status = "tamamlandi" THEN 1 ELSE 0 END) AS completed_order_count,
                   COALESCE(SUM(grand_total), 0) AS order_total
            FROM sales_orders
            GROUP BY branch_id
        ') as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (isset($branchPerformanceMap[$branchId])) {
                $branchPerformanceMap[$branchId]['order_count'] = (int) ($row['order_count'] ?? 0);
                $branchPerformanceMap[$branchId]['completed_order_count'] = (int) ($row['completed_order_count'] ?? 0);
                $branchPerformanceMap[$branchId]['order_total'] = (float) ($row['order_total'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'invoice_headers')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id,
                   COUNT(*) AS invoice_count,
                   COALESCE(SUM(CASE WHEN invoice_type = "satis" THEN grand_total ELSE 0 END), 0) AS invoice_total
            FROM invoice_headers
            GROUP BY branch_id
        ') as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (isset($branchPerformanceMap[$branchId])) {
                $branchPerformanceMap[$branchId]['invoice_count'] = (int) ($row['invoice_count'] ?? 0);
                $branchPerformanceMap[$branchId]['invoice_total'] = (float) ($row['invoice_total'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'service_records')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id,
                   SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) AS open_service_count,
                   SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS closed_service_count
            FROM service_records
            GROUP BY branch_id
        ') as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (isset($branchPerformanceMap[$branchId])) {
                $branchPerformanceMap[$branchId]['open_service_count'] = (int) ($row['open_service_count'] ?? 0);
                $branchPerformanceMap[$branchId]['closed_service_count'] = (int) ($row['closed_service_count'] ?? 0);
            }
        }
    }

    if (app_table_exists($db, 'rental_contracts')) {
        foreach (app_fetch_all($db, '
            SELECT branch_id,
                   SUM(CASE WHEN status = "aktif" THEN 1 ELSE 0 END) AS active_rental_count,
                   COALESCE(SUM(CASE WHEN status = "aktif" THEN monthly_rent ELSE 0 END), 0) AS rental_monthly_total
            FROM rental_contracts
            GROUP BY branch_id
        ') as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            if (isset($branchPerformanceMap[$branchId])) {
                $branchPerformanceMap[$branchId]['active_rental_count'] = (int) ($row['active_rental_count'] ?? 0);
                $branchPerformanceMap[$branchId]['rental_monthly_total'] = (float) ($row['rental_monthly_total'] ?? 0);
            }
        }
    }

    $maxInvoiceTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['invoice_total'], $branchPerformanceMap));
    $maxOrderTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['order_total'], $branchPerformanceMap));
    $maxRentalTotal = max(1.0, ...array_map(static fn(array $row): float => (float) $row['rental_monthly_total'], $branchPerformanceMap));
    $maxLoginCount = max(1, ...array_map(static fn(array $row): int => (int) $row['login_30d'], $branchPerformanceMap));

    foreach ($branchPerformanceMap as $branchId => $row) {
        $activityScore = ((float) $row['user_count'] * 6) + (((float) $row['login_30d'] / $maxLoginCount) * 14);
        $salesScore = ((float) $row['approved_offer_count'] * 8) + ((float) $row['completed_order_count'] * 10)
            + (((float) $row['invoice_total'] / $maxInvoiceTotal) * 24)
            + (((float) $row['order_total'] / $maxOrderTotal) * 10);
        $serviceScore = ((float) $row['closed_service_count'] * 7) - ((float) $row['open_service_count'] * 2);
        $rentalScore = ((float) $row['active_rental_count'] * 8) + (((float) $row['rental_monthly_total'] / $maxRentalTotal) * 11);
        $totalScore = max(0, $activityScore + $salesScore + $serviceScore + $rentalScore);

        $grade = 'D';
        if ($totalScore >= 90) {
            $grade = 'A';
        } elseif ($totalScore >= 60) {
            $grade = 'B';
        } elseif ($totalScore >= 30) {
            $grade = 'C';
        }

        $branchPerformanceMap[$branchId]['activity_score'] = round($activityScore, 1);
        $branchPerformanceMap[$branchId]['sales_score'] = round($salesScore, 1);
        $branchPerformanceMap[$branchId]['service_score'] = round($serviceScore, 1);
        $branchPerformanceMap[$branchId]['rental_score'] = round($rentalScore, 1);
        $branchPerformanceMap[$branchId]['total_score'] = round($totalScore, 1);
        $branchPerformanceMap[$branchId]['grade'] = $grade;
    }

    $branchPerformanceRows = array_values($branchPerformanceMap);
    usort($branchPerformanceRows, static fn(array $a, array $b): int => ((float) $b['total_score'] <=> (float) $a['total_score']) ?: strcmp((string) $a['branch_name'], (string) $b['branch_name']));
}
$branchTargetGoalFields = core_branch_target_goal_fields();
$branchTargetRows = [];
$branchTargetOverview = [
    'on_track_count' => 0,
    'watch_count' => 0,
    'risk_count' => 0,
];
foreach ($branchPerformanceRows as $performanceRow) {
    $branchId = (int) ($performanceRow['branch_id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $savedValue = app_setting($db, 'branch.target_goal.' . $branchId, '');
    $savedRow = $savedValue !== '' ? json_decode($savedValue, true) : [];
    if (!is_array($savedRow)) {
        $savedRow = [];
    }

    $goalDetails = [];
    $ratioValues = [];
    foreach ($branchTargetGoalFields as $fieldKey => $fieldLabel) {
        $goalValue = max(0, (float) ($savedRow['goals'][$fieldKey] ?? 0));
        $actualValue = (float) ($performanceRow[$fieldKey] ?? 0);
        $ratio = $goalValue > 0 ? round(($actualValue / $goalValue) * 100, 1) : null;
        if ($ratio !== null) {
            $ratioValues[] = $ratio;
        }
        $goalDetails[$fieldKey] = [
            'label' => $fieldLabel,
            'goal' => $goalValue,
            'actual' => $actualValue,
            'ratio' => $ratio,
        ];
    }

    $averageRatio = $ratioValues ? round(array_sum($ratioValues) / count($ratioValues), 1) : 0.0;
    $statusLabel = 'Hedef Yok';
    $statusTone = 'muted';
    if ($ratioValues) {
        if ($averageRatio >= 100) {
            $statusLabel = 'Hedefte';
            $statusTone = 'ok';
            $branchTargetOverview['on_track_count']++;
        } elseif ($averageRatio >= 75) {
            $statusLabel = 'Takipte';
            $statusTone = 'warn';
            $branchTargetOverview['watch_count']++;
        } else {
            $statusLabel = 'Riskte';
            $statusTone = 'warn';
            $branchTargetOverview['risk_count']++;
        }
    }

    $branchTargetRows[] = [
        'branch_id' => $branchId,
        'firm_id' => (int) ($performanceRow['firm_id'] ?? 0),
        'branch_name' => (string) ($performanceRow['branch_name'] ?? '-'),
        'firm_name' => (string) ($performanceRow['firm_name'] ?? '-'),
        'target_period' => (string) ($savedRow['target_period'] ?? date('Y-m')),
        'updated_at' => (string) ($savedRow['updated_at'] ?? ''),
        'updated_by_name' => (string) ($savedRow['updated_by_name'] ?? '-'),
        'note' => trim((string) ($savedRow['note'] ?? '')),
        'status' => $statusLabel,
        'status_tone' => $statusTone,
        'average_ratio' => $averageRatio,
        'goal_details' => $goalDetails,
    ];
}
usort($branchTargetRows, static fn(array $a, array $b): int => ((float) $b['average_ratio'] <=> (float) $a['average_ratio']) ?: strcmp((string) $a['branch_name'], (string) $b['branch_name']));
$branchTargetHistory = app_fetch_all($db, '
    SELECT l.id, l.record_id AS branch_id, l.description, l.created_at,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "save_branch_target_goal"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Hedefte Sube'] = (string) $branchTargetOverview['on_track_count'];
$summary['Takipte Sube'] = (string) $branchTargetOverview['watch_count'];
$summary['Riskli Sube Hedefi'] = (string) $branchTargetOverview['risk_count'];
$firmKpiRows = [];
if ($firmRows) {
    $firmKpiMap = [];
    foreach ($firmRows as $firmRow) {
        $firmId = (int) ($firmRow['id'] ?? 0);
        if ($firmId <= 0) {
            continue;
        }

        $firmKpiMap[$firmId] = [
            'firm_id' => $firmId,
            'firm_name' => (string) ($firmRow['company_name'] ?? '-'),
            'status' => (int) ($firmRow['status'] ?? 0),
            'branch_count' => (int) ($firmStats[$firmId]['branch_count'] ?? 0),
            'user_count' => (int) ($firmStats[$firmId]['user_count'] ?? 0),
            'active_branch_count' => 0,
            'invoice_total' => 0.0,
            'order_total' => 0.0,
            'rental_total' => 0.0,
            'open_service_count' => 0,
            'closed_service_count' => 0,
            'avg_branch_score' => 0.0,
            'total_score' => 0.0,
            'grade' => 'D',
        ];
    }

    foreach ($branchPerformanceRows as $branchRow) {
        $firmId = (int) ($branchRow['firm_id'] ?? 0);
        if (!isset($firmKpiMap[$firmId])) {
            continue;
        }

        $firmKpiMap[$firmId]['active_branch_count'] += (int) (($branchRow['status'] ?? 0) === 1 ? 1 : 0);
        $firmKpiMap[$firmId]['invoice_total'] += (float) ($branchRow['invoice_total'] ?? 0);
        $firmKpiMap[$firmId]['order_total'] += (float) ($branchRow['order_total'] ?? 0);
        $firmKpiMap[$firmId]['rental_total'] += (float) ($branchRow['rental_monthly_total'] ?? 0);
        $firmKpiMap[$firmId]['open_service_count'] += (int) ($branchRow['open_service_count'] ?? 0);
        $firmKpiMap[$firmId]['closed_service_count'] += (int) ($branchRow['closed_service_count'] ?? 0);
        $firmKpiMap[$firmId]['avg_branch_score'] += (float) ($branchRow['total_score'] ?? 0);
    }

    foreach ($firmKpiMap as $firmId => $firmRow) {
        $branchCount = max(1, (int) ($firmRow['branch_count'] ?? 0));
        $avgBranchScore = (float) ($firmRow['avg_branch_score'] ?? 0) / $branchCount;
        $serviceBalance = ((int) ($firmRow['closed_service_count'] ?? 0) * 3) - ((int) ($firmRow['open_service_count'] ?? 0) * 1.5);
        $totalScore = max(0, $avgBranchScore + ((int) ($firmRow['active_branch_count'] ?? 0) * 6) + ((int) ($firmRow['user_count'] ?? 0) * 2) + $serviceBalance);

        $grade = 'D';
        if ($totalScore >= 110) {
            $grade = 'A';
        } elseif ($totalScore >= 75) {
            $grade = 'B';
        } elseif ($totalScore >= 40) {
            $grade = 'C';
        }

        $firmKpiMap[$firmId]['avg_branch_score'] = round($avgBranchScore, 1);
        $firmKpiMap[$firmId]['total_score'] = round($totalScore, 1);
        $firmKpiMap[$firmId]['grade'] = $grade;
    }

    $firmKpiRows = array_values($firmKpiMap);
    usort($firmKpiRows, static fn(array $a, array $b): int => ((float) $b['total_score'] <=> (float) $a['total_score']) ?: strcmp((string) $a['firm_name'], (string) $b['firm_name']));
}
$logFilters = [
    'user_id' => (int) ($_GET['log_user_id'] ?? 0),
    'module_name' => trim((string) ($_GET['log_module_name'] ?? '')),
    'action_name' => trim((string) ($_GET['log_action_name'] ?? '')),
    'date_from' => trim((string) ($_GET['log_date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['log_date_to'] ?? '')),
];
$branchScopeFocus = trim((string) ($_GET['branch_scope_focus'] ?? 'tum'));
$branchScopeFocusOptions = [
    'tum' => 'Tum Hareketler',
    'atama' => 'Sube Atama',
    'onarim' => 'Risk Onarimi',
    'ayar' => 'Kapsam Ayari',
    'ihlal' => 'Erisim Ihlali',
];
if (!isset($branchScopeFocusOptions[$branchScopeFocus])) {
    $branchScopeFocus = 'tum';
}

$settings = [
    'currency_default' => app_setting($db, 'currency.default', 'TRY'),
    'tax_default_vat' => app_setting($db, 'tax.default_vat', '20'),
    'theme_default' => app_setting($db, 'theme.default', 'sunrise'),
    'panel_title' => app_setting($db, 'app.panel_title', 'Yonetim Paneli'),
    'offer_prefix' => app_setting($db, 'docs.offer_prefix', 'TKL'),
    'order_prefix' => app_setting($db, 'docs.order_prefix', 'SIP'),
    'invoice_prefix' => app_setting($db, 'docs.invoice_prefix', 'FAT'),
    'offer_series' => app_setting($db, 'docs.offer_series', 'TKL-{YYYY}-{SEQ}'),
    'order_series' => app_setting($db, 'docs.order_series', 'SIP-{YYYY}-{SEQ}'),
    'invoice_sales_series' => app_setting($db, 'docs.invoice_sales_series', 'FAT-{YYYY}-{SEQ}'),
    'invoice_purchase_series' => app_setting($db, 'docs.invoice_purchase_series', 'ALI-{YYYY}-{SEQ}'),
    'invoice_return_series' => app_setting($db, 'docs.invoice_return_series', 'IAD-{YYYY}-{SEQ}'),
    'shipment_series' => app_setting($db, 'docs.shipment_series', 'SVK-{YYYY}-{SEQ}'),
    'dispatch_series' => app_setting($db, 'docs.dispatch_series', 'IRS-{YYYY}-{SEQ}'),
    'service_prefix' => app_setting($db, 'docs.service_prefix', 'SRV'),
    'rental_prefix' => app_setting($db, 'docs.rental_prefix', 'KIR'),
    'stock_prefix' => app_setting($db, 'docs.stock_prefix', 'STK'),
    'service_series' => app_setting($db, 'docs.service_series', 'SRV-{YYYY}-{SEQ}'),
    'rental_series' => app_setting($db, 'docs.rental_series', 'KIR-{YYYY}-{SEQ}'),
    'stock_series' => app_setting($db, 'docs.stock_series', 'STK-{YYYY}-{SEQ}'),
    'series_scope' => app_setting($db, 'docs.series_scope', 'global'),
    'invoice_print_title' => app_setting($db, 'print.invoice_title', 'FATURA'),
    'invoice_print_subtitle' => app_setting($db, 'print.invoice_subtitle', 'PDF / yazdir cikti sablonu'),
    'invoice_print_accent' => app_setting($db, 'print.invoice_accent', '#9a3412'),
    'invoice_print_notes_title' => app_setting($db, 'print.invoice_notes_title', 'Notlar'),
    'invoice_print_footer' => app_setting($db, 'print.invoice_footer', 'Bu belge sistem tarafindan olusturulmustur.'),
    'security_two_factor_mode' => app_setting($db, 'security.two_factor_mode', 'off'),
    'security_two_factor_ttl' => app_setting($db, 'security.two_factor_ttl', '10'),
    'security_session_timeout_enabled' => app_setting($db, 'security.session_timeout_enabled', '0'),
    'security_session_timeout_minutes' => app_setting($db, 'security.session_timeout_minutes', '30'),
    'security_ip_mode' => app_setting($db, 'security.ip_mode', 'off'),
    'security_ip_allowlist' => app_setting($db, 'security.ip_allowlist', ''),
    'security_ip_local_bypass' => app_setting($db, 'security.ip_local_bypass', '1'),
    'branch_isolation_mode' => app_branch_isolation_mode($db),
    'branch_default_id' => app_setting($db, 'branch.default_id', '1'),
    'branch_unassigned_policy' => app_setting($db, 'branch.unassigned_policy', 'show_to_admin'),
];
$branchDocumentTemplateRows = [];
foreach ($branches as $branchRow) {
    $branchId = (int) ($branchRow['id'] ?? 0);
    if ($branchId <= 0) {
        continue;
    }

    $prefix = 'print.branch.' . $branchId . '.';
    $row = [
        'branch_id' => $branchId,
        'firm_id' => (int) ($branchRow['firm_id'] ?? 0),
        'branch_name' => (string) ($branchRow['name'] ?? '-'),
        'firm_name' => (string) ($firmNameMap[(int) ($branchRow['firm_id'] ?? 0)] ?? '-'),
        'invoice_title' => app_setting($db, $prefix . 'invoice_title', $settings['invoice_print_title']),
        'invoice_subtitle' => app_setting($db, $prefix . 'invoice_subtitle', $settings['invoice_print_subtitle']),
        'invoice_accent' => app_setting($db, $prefix . 'invoice_accent', $settings['invoice_print_accent']),
        'invoice_notes_title' => app_setting($db, $prefix . 'invoice_notes_title', $settings['invoice_print_notes_title']),
        'invoice_footer' => app_setting($db, $prefix . 'invoice_footer', $settings['invoice_print_footer']),
        'document_logo_path' => app_setting($db, $prefix . 'document_logo_path', ''),
    ];
    $row['is_customized'] =
        $row['invoice_title'] !== $settings['invoice_print_title']
        || $row['invoice_subtitle'] !== $settings['invoice_print_subtitle']
        || $row['invoice_accent'] !== $settings['invoice_print_accent']
        || $row['invoice_notes_title'] !== $settings['invoice_print_notes_title']
        || $row['invoice_footer'] !== $settings['invoice_print_footer']
        || $row['document_logo_path'] !== '';
    $branchDocumentTemplateRows[] = $row;
}

$summary = [
    'Firma' => count($firmRows),
    'Sube' => app_table_count($db, 'core_branches'),
    'Kullanici' => app_table_count($db, 'core_users'),
    'Rol' => app_table_count($db, 'core_roles'),
    'Islem Logu' => app_table_count($db, 'core_audit_logs'),
];
$summary['Ic Hizmet Faturasi'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "create_internal_service_invoice"');
$summary['Ic Hizmet Tutar'] = number_format((float) array_sum(array_map(static fn(array $row): float => (float) ($row['grand_total'] ?? 0), array_filter($internalServiceInvoiceRows, static fn(array $row): bool => (string) ($row['invoice_type'] ?? '') === 'satis'))), 2, ',', '.');
$summary['Sube Belge Tasarimi'] = (string) count(array_filter($branchDocumentTemplateRows, static fn(array $row): bool => !empty($row['is_customized'])));
if ($branchPerformanceRows) {
    $topBranchPerformance = $branchPerformanceRows[0];
    $summary['Sube Performans'] = number_format((float) $topBranchPerformance['total_score'], 1, ',', '.');
    $summary['Lider Sube'] = (string) $topBranchPerformance['branch_name'];
}
if ($firmKpiRows) {
    $topFirmKpi = $firmKpiRows[0];
    $summary['Firma KPI'] = number_format((float) $topFirmKpi['total_score'], 1, ',', '.');
    $summary['Lider Firma'] = (string) $topFirmKpi['firm_name'];
}
$loginAuditRows = app_fetch_all($db, '
    SELECT l.id, l.user_id, l.record_id, l.description, l.ip_address, l.user_agent, l.created_at,
           COALESCE(u.full_name, "Sistem") AS full_name,
           COALESCE(u.email, "-") AS email,
           COALESCE(u.last_login_at, NULL) AS last_login_at,
           COALESCE(b.name, "-") AS branch_name
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    LEFT JOIN core_branches b ON b.id = u.branch_id
    WHERE l.module_name = "auth"
      AND l.action_name = "login_success"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 25
');
$loginUserSummary = app_fetch_all($db, '
    SELECT COALESCE(u.full_name, "Sistem") AS full_name,
           COALESCE(u.email, "-") AS email,
           COALESCE(b.name, "-") AS branch_name,
           COUNT(*) AS login_count,
           COUNT(DISTINCT NULLIF(l.ip_address, "")) AS ip_count,
           MAX(l.created_at) AS last_at,
           MIN(l.created_at) AS first_at,
           MAX(COALESCE(NULLIF(l.ip_address, ""), "-")) AS sample_ip
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    LEFT JOIN core_branches b ON b.id = u.branch_id
    WHERE l.module_name = "auth"
      AND l.action_name = "login_success"
    GROUP BY COALESCE(l.user_id, l.record_id), u.full_name, u.email, b.name
    ORDER BY last_at DESC, login_count DESC
    LIMIT 12
');
$loginIpSummary = app_fetch_all($db, '
    SELECT COALESCE(NULLIF(l.ip_address, ""), "-") AS ip_address,
           COUNT(*) AS login_count,
           COUNT(DISTINCT COALESCE(l.user_id, l.record_id)) AS user_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    WHERE l.module_name = "auth"
      AND l.action_name = "login_success"
    GROUP BY COALESCE(NULLIF(l.ip_address, ""), "-")
    ORDER BY last_at DESC, login_count DESC
    LIMIT 12
');
$summary['Giris Logu'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "login_success"');
$summary['Bugun Giris'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "login_success" AND DATE(created_at) = CURDATE()');
$summary['Aktif Giris IP'] = (string) count($loginIpSummary);
$failedLoginAuditRows = app_fetch_all($db, '
    SELECT l.id, l.user_id, l.record_id, l.ip_address, l.user_agent, l.created_at, l.action_name,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS user_email,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.email")), "-") AS attempted_email,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.reason_label")), l.action_name) AS reason_label
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    WHERE l.module_name = "auth"
      AND l.action_name IN ("login_failed", "login_rate_limited")
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 25
');
$failedLoginReasonSummary = app_fetch_all($db, '
    SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.reason_label")), l.action_name) AS reason_label,
           COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.email")), ""), "-") AS attempted_email,
           COUNT(*) AS failed_count,
           COUNT(DISTINCT COALESCE(NULLIF(l.ip_address, ""), "-")) AS ip_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    WHERE l.module_name = "auth"
      AND l.action_name IN ("login_failed", "login_rate_limited")
    GROUP BY reason_label, attempted_email
    ORDER BY last_at DESC, failed_count DESC
    LIMIT 12
');
$failedLoginIpSummary = app_fetch_all($db, '
    SELECT COALESCE(NULLIF(l.ip_address, ""), "-") AS ip_address,
           COUNT(*) AS failed_count,
           COUNT(DISTINCT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.email")), ""), "-")) AS email_count,
           SUM(CASE WHEN l.action_name = "login_rate_limited" THEN 1 ELSE 0 END) AS blocked_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    WHERE l.module_name = "auth"
      AND l.action_name IN ("login_failed", "login_rate_limited")
    GROUP BY COALESCE(NULLIF(l.ip_address, ""), "-")
    ORDER BY last_at DESC, failed_count DESC
    LIMIT 12
');
$summary['Basarisiz Giris'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "login_failed"');
$summary['Bugun Basarisiz'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "login_failed" AND DATE(created_at) = CURDATE()');
$summary['Bloklu Deneme'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "login_rate_limited"');
$twoFactorAuditRows = app_fetch_all($db, '
    SELECT l.id, l.user_id, l.created_at, l.action_name, l.description, l.ip_address,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    WHERE l.module_name = "auth"
      AND l.action_name IN ("two_factor_challenge_created", "two_factor_resend", "two_factor_success", "two_factor_failed", "two_factor_expired")
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$twoFactorActionSummary = app_fetch_all($db, '
    SELECT action_name, COUNT(*) AS total_count, MAX(created_at) AS last_at
    FROM core_audit_logs
    WHERE module_name = "auth"
      AND action_name IN ("two_factor_challenge_created", "two_factor_resend", "two_factor_success", "two_factor_failed", "two_factor_expired")
    GROUP BY action_name
    ORDER BY last_at DESC, total_count DESC
');
$summary['2FA Mod'] = $settings['security_two_factor_mode'] === 'email' ? 'Email' : 'Kapali';
$summary['2FA Basari'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "two_factor_success"');
$summary['2FA Hata'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "two_factor_failed"');
$sessionTimeoutRows = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.description, l.ip_address,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    WHERE l.module_name = "auth"
      AND l.action_name = "session_timeout"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$sessionTimeoutUserSummary = app_fetch_all($db, '
    SELECT COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email,
           COUNT(*) AS timeout_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = COALESCE(l.user_id, l.record_id)
    WHERE l.module_name = "auth"
      AND l.action_name = "session_timeout"
    GROUP BY COALESCE(l.user_id, l.record_id), u.full_name, u.email
    ORDER BY last_at DESC, timeout_count DESC
    LIMIT 12
');
$summary['Oturum Zaman Asimi'] = $settings['security_session_timeout_enabled'] === '1' ? ((string) $settings['security_session_timeout_minutes'] . ' dk') : 'Kapali';
$summary['Timeout Logu'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "session_timeout"');
$latestLoginAudit = $loginAuditRows[0] ?? null;
$latestFailedLoginAudit = $failedLoginAuditRows[0] ?? null;
$latestTwoFactorAudit = $twoFactorAuditRows[0] ?? null;
$latestSessionTimeout = $sessionTimeoutRows[0] ?? null;
$ipAccessRules = array_values(array_filter(array_map(static fn(string $line): string => trim($line), explode("\n", str_replace("\r", '', (string) $settings['security_ip_allowlist']))), static fn(string $line): bool => $line !== ''));
$ipAccessDeniedRows = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.ip_address, l.user_agent,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.context")), "-") AS deny_context,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.description, "$.email")), "-") AS deny_email
    FROM core_audit_logs l
    WHERE l.module_name = "auth"
      AND l.action_name = "ip_access_denied"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$ipAccessDeniedSummary = app_fetch_all($db, '
    SELECT COALESCE(NULLIF(ip_address, ""), "-") AS ip_address,
           COUNT(*) AS deny_count,
           MAX(created_at) AS last_at
    FROM core_audit_logs
    WHERE module_name = "auth"
      AND action_name = "ip_access_denied"
    GROUP BY COALESCE(NULLIF(ip_address, ""), "-")
    ORDER BY last_at DESC, deny_count DESC
    LIMIT 12
');
$summary['IP Kurali'] = (string) count($ipAccessRules);
$summary['IP Engeli'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE module_name = "auth" AND action_name = "ip_access_denied"');
$latestIpAccessDenied = $ipAccessDeniedRows[0] ?? null;
$permissionTemplates = core_permission_templates();
$permissionTemplateSummaries = [];
foreach ($permissionTemplates as $templateKey => $template) {
    $moduleCount = count($template['modules'] ?? []);
    $actionCount = 0;
    foreach (($template['matrix'] ?? []) as $actions) {
        $actionCount += is_array($actions) ? count($actions) : 0;
    }
    $permissionTemplateSummaries[$templateKey] = [
        'label' => (string) ($template['label'] ?? $templateKey),
        'summary' => (string) ($template['summary'] ?? ''),
        'module_count' => $moduleCount,
        'action_count' => $actionCount,
    ];
}
$permissionTemplateHistory = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.description,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "apply_permission_template"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 15
');
$summary['Yetki Sablonu'] = (string) count($permissionTemplates);
$summary['Sablon Uygulama'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "apply_permission_template"');
$approvalRuleCatalog = app_approval_rule_catalog();
$approvalRuleSettings = app_approval_rule_settings();
$approvalRuleRows = [];
foreach ($approvalRuleCatalog as $ruleKey => $ruleMeta) {
    $ruleSettings = $approvalRuleSettings[$ruleKey] ?? [];
    $approvalRuleRows[] = array_merge($ruleMeta, [
        'rule_key' => $ruleKey,
        'enabled' => !empty($ruleSettings['enabled']),
        'approver_role_code' => (string) ($ruleSettings['approver_role_code'] ?? ''),
        'require_second_approval' => !empty($ruleSettings['require_second_approval']),
        'second_approver_role_code' => (string) ($ruleSettings['second_approver_role_code'] ?? ''),
        'require_note' => !empty($ruleSettings['require_note']),
        'min_amount' => (float) ($ruleSettings['min_amount'] ?? 0),
    ]);
}
$approvalRuleHistory = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.description,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "update_approval_rules"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$summary['Onay Kurali'] = (string) count($approvalRuleRows);
$summary['Aktif Onay Kurali'] = (string) count(array_filter($approvalRuleRows, static fn(array $row): bool => !empty($row['enabled'])));
$summary['Cift Onay Kurali'] = (string) count(array_filter($approvalRuleRows, static fn(array $row): bool => !empty($row['enabled']) && !empty($row['require_second_approval'])));

$moduleDefinitions = app_modules();
$roleModuleMap = app_role_modules();
$permissionActions = app_permission_actions();
$roleActionMatrix = app_role_action_permissions();
$departmentModuleMap = app_department_permission_modules();
$departmentActionMatrix = app_department_action_permissions();
$departmentUserRows = app_fetch_all($db, '
    SELECT d.id AS department_id,
           d.name AS department_name,
           COUNT(e.id) AS employee_count,
           SUM(CASE WHEN e.user_id IS NOT NULL AND e.user_id <> 0 THEN 1 ELSE 0 END) AS linked_user_count,
           GROUP_CONCAT(DISTINCT CASE WHEN e.user_id IS NOT NULL AND e.user_id <> 0 THEN u.full_name END ORDER BY u.full_name SEPARATOR ", ") AS linked_users
    FROM hr_departments d
    LEFT JOIN hr_employees e ON e.department_id = d.id
    LEFT JOIN core_users u ON u.id = e.user_id
    GROUP BY d.id, d.name
    ORDER BY d.name ASC, d.id ASC
');
$departmentPermissionSummaryRows = [];
foreach ($departments as $department) {
    $departmentId = (int) ($department['id'] ?? 0);
    $moduleCount = count($departmentModuleMap[$departmentId] ?? []);
    $actionCount = 0;
    foreach (($departmentActionMatrix[$departmentId] ?? []) as $actions) {
        $actionCount += is_array($actions) ? count($actions) : 0;
    }

    $assignmentInfo = [
        'employee_count' => 0,
        'linked_user_count' => 0,
        'linked_users' => '',
    ];
    foreach ($departmentUserRows as $departmentUserRow) {
        if ((int) ($departmentUserRow['department_id'] ?? 0) === $departmentId) {
            $assignmentInfo = $departmentUserRow;
            break;
        }
    }

    $departmentPermissionSummaryRows[] = [
        'id' => $departmentId,
        'name' => (string) ($department['name'] ?? ''),
        'module_count' => $moduleCount,
        'action_count' => $actionCount,
        'employee_count' => (int) ($assignmentInfo['employee_count'] ?? 0),
        'linked_user_count' => (int) ($assignmentInfo['linked_user_count'] ?? 0),
        'linked_users' => (string) ($assignmentInfo['linked_users'] ?? ''),
    ];
}
$departmentPermissionHistory = app_fetch_all($db, '
    SELECT l.id, l.created_at, l.action_name, l.description,
           COALESCE(u.full_name, "-") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name IN ("update_department_permissions", "update_department_permission_matrix")
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 15
');
$summary['Departman Yetkisi'] = (string) count($departments);
$summary['Departman Matris Kaydi'] = (string) count(array_filter($departmentPermissionSummaryRows, static fn(array $row): bool => (int) $row['module_count'] > 0 || (int) $row['action_count'] > 0));
$summary['Bagli Departman Kullanici'] = (string) array_sum(array_map(static fn(array $row): int => (int) $row['linked_user_count'], $departmentPermissionSummaryRows));
$branchScopeTables = app_branch_scope_tables();
$branchScopeStats = [];
$branchTotals = [];
$branchScopeHealthRows = [];
foreach ($branchScopeTables as $tableName => $tableLabel) {
    if (!app_table_exists($db, $tableName) || !app_column_exists($db, $tableName, 'branch_id')) {
        continue;
    }

    $safeTable = str_replace('`', '``', $tableName);
    $total = (int) app_metric($db, 'SELECT COUNT(*) FROM `' . $safeTable . '`');
    $unassigned = (int) app_metric($db, 'SELECT COUNT(*) FROM `' . $safeTable . '` WHERE branch_id IS NULL OR branch_id = 0');
    $invalidBranch = (int) app_metric($db, '
        SELECT COUNT(*)
        FROM `' . $safeTable . '` t
        LEFT JOIN core_branches b ON b.id = t.branch_id
        WHERE t.branch_id IS NOT NULL
          AND t.branch_id <> 0
          AND b.id IS NULL
    ');
    $passiveBranch = (int) app_metric($db, '
        SELECT COUNT(*)
        FROM `' . $safeTable . '` t
        INNER JOIN core_branches b ON b.id = t.branch_id
        WHERE COALESCE(b.status, 0) = 0
    ');
    $distribution = app_fetch_all($db, '
        SELECT COALESCE(b.name, "Subesiz") AS branch_name, t.branch_id, COUNT(*) AS record_count
        FROM `' . $safeTable . '` t
        LEFT JOIN core_branches b ON b.id = t.branch_id
        GROUP BY t.branch_id, b.name
        ORDER BY record_count DESC, branch_name ASC
    ');

    foreach ($distribution as $row) {
        $branchKey = (int) ($row['branch_id'] ?? 0);
        $branchName = $branchKey > 0 ? (string) $row['branch_name'] : 'Subesiz';
        if (!isset($branchTotals[$branchKey])) {
            $branchTotals[$branchKey] = ['branch_name' => $branchName, 'record_count' => 0];
        }
        $branchTotals[$branchKey]['record_count'] += (int) $row['record_count'];
    }

    $branchScopeStats[] = [
        'table' => $tableName,
        'label' => $tableLabel,
        'total' => $total,
        'unassigned' => $unassigned,
        'distribution' => $distribution,
    ];
    $branchScopeHealthRows[] = [
        'table' => $tableName,
        'label' => $tableLabel,
        'total' => $total,
        'unassigned' => $unassigned,
        'invalid_branch' => $invalidBranch,
        'passive_branch' => $passiveBranch,
        'risk_total' => $unassigned + $invalidBranch + $passiveBranch,
    ];
}
usort($branchTotals, static fn(array $a, array $b): int => ((int) $b['record_count'] <=> (int) $a['record_count']));
usort($branchScopeHealthRows, static fn(array $a, array $b): int => ((int) $b['risk_total'] <=> (int) $a['risk_total']) ?: strcmp((string) $a['label'], (string) $b['label']));
$summary['Sube Kapsam Tablosu'] = count($branchScopeStats);
$summary['Subesiz Kayit'] = array_sum(array_map(static fn(array $row): int => (int) $row['unassigned'], $branchScopeStats));
$summary['Sube Kapsam Riski'] = array_sum(array_map(static fn(array $row): int => (int) $row['risk_total'], $branchScopeHealthRows));
$branchScopeRepairRows = app_fetch_all($db, '
    SELECT l.id, l.record_id AS branch_id, l.description, l.created_at,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "repair_branch_scope_risks"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
');
$branchScopeRepairHistory = [];
foreach ($branchScopeRepairRows as $row) {
    $tableCounts = [];
    if (preg_match('/:\s*(\{.*\})\s*$/', (string) ($row['description'] ?? ''), $match)) {
        $decoded = json_decode($match[1], true);
        if (is_array($decoded)) {
            foreach ($decoded as $tableName => $count) {
                $tableCounts[(string) $tableName] = (int) $count;
            }
        }
    }

    $branchScopeRepairHistory[] = $row + [
        'table_counts' => $tableCounts,
        'total_repaired' => array_sum($tableCounts),
    ];
}
$summary['Sube Risk Onarim'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "repair_branch_scope_risks"');
$latestBranchRepair = $branchScopeRepairHistory[0] ?? null;
$branchAssignmentRows = app_fetch_all($db, '
    SELECT l.id, l.record_id AS branch_id, l.description, l.created_at,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COALESCE(u.full_name, "Sistem") AS full_name
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "assign_unassigned_branch_records"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$branchAssignmentHistory = [];
foreach ($branchAssignmentRows as $row) {
    $tableCounts = [];
    if (preg_match('/:\s*(\{.*\})\s*$/', (string) ($row['description'] ?? ''), $match)) {
        $decoded = json_decode($match[1], true);
        if (is_array($decoded)) {
            foreach ($decoded as $tableName => $count) {
                $tableCounts[(string) $tableName] = (int) $count;
            }
        }
    }

    $branchAssignmentHistory[] = $row + [
        'table_counts' => $tableCounts,
        'total_moved' => array_sum($tableCounts),
    ];
}
$latestBranchAssignment = $branchAssignmentHistory[0] ?? null;
$branchAssignmentSummary = app_fetch_all($db, '
    SELECT l.record_id AS branch_id,
           COALESCE(b.name, "-") AS branch_name,
           COALESCE(f.company_name, "-") AS firm_name,
           COUNT(*) AS operation_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    LEFT JOIN core_branches b ON b.id = l.record_id
    LEFT JOIN core_firms f ON f.id = b.firm_id
    WHERE l.action_name = "assign_unassigned_branch_records"
    GROUP BY l.record_id, b.name, f.company_name
    ORDER BY last_at DESC, operation_count DESC
    LIMIT 12
');
$summary['Sube Atama Islem'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "assign_unassigned_branch_records"');
$branchScopeChangeRows = app_fetch_all($db, '
    SELECT l.id, l.description, l.created_at,
           COALESCE(u.full_name, "Sistem") AS full_name,
           COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "update_branch_scope_settings"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$branchScopeChanges = [];
foreach ($branchScopeChangeRows as $row) {
    $oldScope = [];
    $newScope = [];
    if (preg_match('/:\s*(\{.*\})\s*$/', (string) ($row['description'] ?? ''), $match)) {
        $decoded = json_decode($match[1], true);
        if (is_array($decoded)) {
            $oldScope = is_array($decoded['old'] ?? null) ? $decoded['old'] : [];
            $newScope = is_array($decoded['new'] ?? null) ? $decoded['new'] : [];
        }
    }

    $branchScopeChanges[] = $row + [
        'old_scope' => $oldScope,
        'new_scope' => $newScope,
        'has_structured_change' => $newScope !== [],
    ];
}
$summary['Sube Kapsam Degisikligi'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "update_branch_scope_settings"');
$branchScopeViolationCount = (int) app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "branch_access_denied"');
$latestBranchScopeChange = $branchScopeChanges[0] ?? null;
$branchScopeFocusMeta = [
    'tum' => ['label' => 'Tum Hareketler', 'count' => (int) $summary['Sube Atama Islem'] + (int) $summary['Sube Risk Onarim'] + (int) $summary['Sube Kapsam Degisikligi'] + $branchScopeViolationCount],
    'atama' => ['label' => 'Sube Atama', 'count' => (int) $summary['Sube Atama Islem']],
    'onarim' => ['label' => 'Risk Onarimi', 'count' => (int) $summary['Sube Risk Onarim']],
    'ayar' => ['label' => 'Kapsam Ayari', 'count' => (int) $summary['Sube Kapsam Degisikligi']],
    'ihlal' => ['label' => 'Erisim Ihlali', 'count' => $branchScopeViolationCount],
];
$branchScopeFocusDetail = $branchScopeFocusMeta[$branchScopeFocus];
$branchScopeFocusSummaryText = $branchScopeFocus === 'tum'
    ? 'Tum sube kapsam hareketleri ayni panelde gosteriliyor.'
    : $branchScopeFocusDetail['label'] . ' odagi aktif; alt tablolar sadece bu islem tipine gore daraltiliyor.';
$branchScopeFocusSharePath = 'index.php?module=core&branch_scope_focus=' . urlencode($branchScopeFocus);
$branchScopeFocusLastAt = $branchScopeFocus === 'atama'
    ? (string) ($latestBranchAssignment['created_at'] ?? '-')
    : ($branchScopeFocus === 'onarim'
        ? (string) ($latestBranchRepair['created_at'] ?? '-')
        : ($branchScopeFocus === 'ayar'
            ? (string) ($latestBranchScopeChange['created_at'] ?? '-')
            : ($branchScopeFocus === 'ihlal'
                ? (string) ($branchAccessDeniedRows[0]['created_at'] ?? '-')
                : (string) ($branchScopeTimeline[0]['last_at'] ?? '-'))));
$branchScopeFocusLastSummary = $branchScopeFocus === 'atama'
    ? ($latestBranchAssignment ? ((string) $latestBranchAssignment['branch_name'] . ' / ' . (int) ($latestBranchAssignment['total_moved'] ?? 0) . ' kayit') : 'Atama kaydi bulunamadi.')
    : ($branchScopeFocus === 'onarim'
        ? ($latestBranchRepair ? ((string) $latestBranchRepair['branch_name'] . ' / ' . (int) ($latestBranchRepair['total_repaired'] ?? 0) . ' kayit') : 'Onarim kaydi bulunamadi.')
        : ($branchScopeFocus === 'ayar'
            ? ($latestBranchScopeChange && $latestBranchScopeChange['has_structured_change'] ? 'Yeni mod: ' . (string) ($latestBranchScopeChange['new_scope']['mode'] ?? '-') : 'Ayar degisikligi kaydi bulunamadi.')
            : ($branchScopeFocus === 'ihlal'
                ? ($branchScopeViolationCount > 0 ? (string) $branchScopeViolationCount . ' ihlal kaydi var.' : 'Ihlal kaydi bulunamadi.')
                : 'Tum odaklar tek panelde ozetleniyor.')));
$branchScopeFocusLastAtMap = [
    'tum' => (string) ($branchScopeTimeline[0]['last_at'] ?? '-'),
    'atama' => (string) ($latestBranchAssignment['created_at'] ?? '-'),
    'onarim' => (string) ($latestBranchRepair['created_at'] ?? '-'),
    'ayar' => (string) ($latestBranchScopeChange['created_at'] ?? '-'),
    'ihlal' => (string) ($branchAccessDeniedRows[0]['created_at'] ?? '-'),
];
$branchScopeFocusSummaryMap = [
    'tum' => 'Tum odaklar tek panelde ozetleniyor.',
    'atama' => $latestBranchAssignment ? ((string) $latestBranchAssignment['branch_name'] . ' / ' . (int) ($latestBranchAssignment['total_moved'] ?? 0) . ' kayit') : 'Atama kaydi bulunamadi.',
    'onarim' => $latestBranchRepair ? ((string) $latestBranchRepair['branch_name'] . ' / ' . (int) ($latestBranchRepair['total_repaired'] ?? 0) . ' kayit') : 'Onarim kaydi bulunamadi.',
    'ayar' => ($latestBranchScopeChange && $latestBranchScopeChange['has_structured_change']) ? ('Yeni mod: ' . (string) ($latestBranchScopeChange['new_scope']['mode'] ?? '-')) : 'Ayar degisikligi kaydi bulunamadi.',
    'ihlal' => $branchScopeViolationCount > 0 ? (string) $branchScopeViolationCount . ' ihlal kaydi var.' : 'Ihlal kaydi bulunamadi.',
];
$branchScopeFocusCardAccentMap = [
    'tum' => ((int) ($branchScopeFocusMeta['tum']['count'] ?? 0) > 0) ? '#2563eb' : '#9ca3af',
    'atama' => ((int) ($branchScopeFocusMeta['atama']['count'] ?? 0) > 0) ? '#0f766e' : '#9ca3af',
    'onarim' => ((int) ($branchScopeFocusMeta['onarim']['count'] ?? 0) > 0) ? '#ca8a04' : '#9ca3af',
    'ayar' => ((int) ($branchScopeFocusMeta['ayar']['count'] ?? 0) > 0) ? '#2563eb' : '#9ca3af',
    'ihlal' => ((int) ($branchScopeFocusMeta['ihlal']['count'] ?? 0) > 0) ? '#dc2626' : '#9ca3af',
];
$branchScopeFocusStatusLabel = $branchScopeFocus === 'ihlal' && $branchScopeViolationCount > 0
    ? 'Kritik'
    : (((int) ($branchScopeFocusDetail['count'] ?? 0) > 0) ? 'Takipte' : 'Sakin');
$branchScopeFocusStatusClass = $branchScopeFocusStatusLabel === 'Kritik'
    ? 'warn'
    : ($branchScopeFocusStatusLabel === 'Takipte' ? 'ok' : 'muted');
$branchScopeStatusCards = [
    [
        'title' => 'Odak',
        'value' => (string) $branchScopeFocusDetail['label'],
        'detail' => 'Secilen filtreye gore alt raporlar daraltilir.',
        'tone' => 'ok',
    ],
    [
        'title' => 'Odak Islem Adedi',
        'value' => (string) ((int) $branchScopeFocusDetail['count']),
        'detail' => $branchScopeFocus === 'tum' ? 'Tum kapsam hareketlerinin toplam adedi.' : 'Secilen islem tipindeki log adedi.',
        'tone' => (int) $branchScopeFocusDetail['count'] > 0 ? 'ok' : 'warn',
    ],
    [
        'title' => $branchScopeFocus === 'atama' ? 'Son Sube Atamasi' : ($branchScopeFocus === 'onarim' ? 'Son Risk Onarimi' : ($branchScopeFocus === 'ayar' ? 'Son Kapsam Ayari' : ($branchScopeFocus === 'ihlal' ? 'Toplam Ihlal' : 'Anlik Risk'))),
        'value' => $branchScopeFocus === 'atama'
            ? ($latestBranchAssignment ? (string) ((int) ($latestBranchAssignment['total_moved'] ?? 0)) . ' Kayit' : 'Yok')
            : ($branchScopeFocus === 'onarim'
                ? ($latestBranchRepair ? (string) ((int) ($latestBranchRepair['total_repaired'] ?? 0)) . ' Kayit' : 'Yok')
                : ($branchScopeFocus === 'ayar'
                    ? ($latestBranchScopeChange && $latestBranchScopeChange['has_structured_change'] ? (string) ($latestBranchScopeChange['new_scope']['mode'] ?? '-') : (string) $settings['branch_isolation_mode'])
                    : ($branchScopeFocus === 'ihlal' ? (string) ((int) ($summary['Sube Ihlali'] ?? 0)) : (string) ((int) $summary['Sube Kapsam Riski'])))),
        'detail' => $branchScopeFocus === 'atama'
            ? ($latestBranchAssignment ? ((string) $latestBranchAssignment['created_at'] . ' / ' . (string) $latestBranchAssignment['branch_name']) : 'Henuz subesiz kayit atamasi yapilmadi.')
            : ($branchScopeFocus === 'onarim'
                ? ($latestBranchRepair ? ((string) $latestBranchRepair['created_at'] . ' / ' . (string) $latestBranchRepair['branch_name']) : 'Henuz risk onarim islemi calistirilmadi.')
                : ($branchScopeFocus === 'ayar'
                    ? ($latestBranchScopeChange ? ((string) $latestBranchScopeChange['created_at'] . ' / ' . (string) $latestBranchScopeChange['full_name']) : 'Mevcut mod: ' . (string) $settings['branch_isolation_mode'])
                    : ($branchScopeFocus === 'ihlal' ? 'Kayit altina alinan sube disi erisim denemeleri.' : ((int) $summary['Sube Kapsam Riski'] > 0 ? 'Saglik kontrolunde aksiyon bekleyen kayit var.' : 'Kapsamda acik risk gorunmuyor.')))),
        'tone' => $branchScopeFocus === 'ihlal'
            ? ((int) ($summary['Sube Ihlali'] ?? 0) > 0 ? 'warn' : 'ok')
            : (($branchScopeFocus === 'tum' ? (int) $summary['Sube Kapsam Riski'] : (int) $branchScopeFocusDetail['count']) > 0 ? 'ok' : 'warn'),
    ],
    [
        'title' => 'Aktif Mod',
        'value' => (string) $settings['branch_isolation_mode'],
        'detail' => 'Kapsam izolasyonunun mevcut calisma modu.',
        'tone' => 'ok',
    ],
];
$branchScopeStatusCardPriority = [
    'tum' => ['Odak', 'Odak Islem Adedi', 'Anlik Risk', 'Aktif Mod'],
    'atama' => ['Son Sube Atamasi', 'Odak', 'Odak Islem Adedi', 'Aktif Mod'],
    'onarim' => ['Son Risk Onarimi', 'Odak', 'Odak Islem Adedi', 'Aktif Mod'],
    'ayar' => ['Son Kapsam Ayari', 'Odak', 'Odak Islem Adedi', 'Aktif Mod'],
    'ihlal' => ['Toplam Ihlal', 'Odak', 'Odak Islem Adedi', 'Aktif Mod'],
];
$branchScopeCardOrder = $branchScopeStatusCardPriority[$branchScopeFocus] ?? $branchScopeStatusCardPriority['tum'];
usort($branchScopeStatusCards, static function (array $a, array $b) use ($branchScopeCardOrder): int {
    $aIndex = array_search((string) ($a['title'] ?? ''), $branchScopeCardOrder, true);
    $bIndex = array_search((string) ($b['title'] ?? ''), $branchScopeCardOrder, true);
    $aScore = $aIndex === false ? 999 : (int) $aIndex;
    $bScore = $bIndex === false ? 999 : (int) $bIndex;

    return $aScore <=> $bScore;
});
$branchScopeTimelineRows = app_fetch_all($db, '
    SELECT DATE(created_at) AS event_day,
           SUM(CASE WHEN action_name = "assign_unassigned_branch_records" THEN 1 ELSE 0 END) AS assignment_count,
           SUM(CASE WHEN action_name = "repair_branch_scope_risks" THEN 1 ELSE 0 END) AS repair_count,
           SUM(CASE WHEN action_name = "update_branch_scope_settings" THEN 1 ELSE 0 END) AS settings_count,
           SUM(CASE WHEN action_name = "branch_access_denied" THEN 1 ELSE 0 END) AS violation_count,
           MAX(created_at) AS last_at
    FROM core_audit_logs
    WHERE action_name IN ("assign_unassigned_branch_records", "repair_branch_scope_risks", "update_branch_scope_settings", "branch_access_denied")
    GROUP BY DATE(created_at)
    ORDER BY event_day DESC
    LIMIT 10
');
$branchScopeTimeline = [];
foreach ($branchScopeTimelineRows as $row) {
    $branchScopeTimeline[] = [
        'event_day' => (string) ($row['event_day'] ?? '-'),
        'assignment_count' => (int) ($row['assignment_count'] ?? 0),
        'repair_count' => (int) ($row['repair_count'] ?? 0),
        'settings_count' => (int) ($row['settings_count'] ?? 0),
        'violation_count' => (int) ($row['violation_count'] ?? 0),
        'last_at' => (string) ($row['last_at'] ?? '-'),
    ];
}
$branchScopeTimelineFiltered = array_values(array_filter($branchScopeTimeline, static function (array $row) use ($branchScopeFocus): bool {
    if ($branchScopeFocus === 'tum') {
        return true;
    }

    if ($branchScopeFocus === 'atama') {
        return (int) $row['assignment_count'] > 0;
    }

    if ($branchScopeFocus === 'onarim') {
        return (int) $row['repair_count'] > 0;
    }

    if ($branchScopeFocus === 'ayar') {
        return (int) $row['settings_count'] > 0;
    }

    if ($branchScopeFocus === 'ihlal') {
        return (int) $row['violation_count'] > 0;
    }

    return true;
}));
$branchScopeUserRows = app_fetch_all($db, '
    SELECT COALESCE(u.full_name, "Sistem") AS full_name,
           COALESCE(u.email, "-") AS email,
           SUM(CASE WHEN l.action_name = "assign_unassigned_branch_records" THEN 1 ELSE 0 END) AS assignment_count,
           SUM(CASE WHEN l.action_name = "repair_branch_scope_risks" THEN 1 ELSE 0 END) AS repair_count,
           SUM(CASE WHEN l.action_name = "update_branch_scope_settings" THEN 1 ELSE 0 END) AS settings_count,
           SUM(CASE WHEN l.action_name = "branch_access_denied" THEN 1 ELSE 0 END) AS violation_count,
           COUNT(*) AS total_count,
           MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name IN ("assign_unassigned_branch_records", "repair_branch_scope_risks", "update_branch_scope_settings", "branch_access_denied")
    GROUP BY l.user_id, u.full_name, u.email
    ORDER BY total_count DESC, last_at DESC
    LIMIT 12
');
$branchScopeUserRowsFiltered = array_values(array_filter($branchScopeUserRows, static function (array $row) use ($branchScopeFocus): bool {
    if ($branchScopeFocus === 'tum') {
        return true;
    }

    if ($branchScopeFocus === 'atama') {
        return (int) $row['assignment_count'] > 0;
    }

    if ($branchScopeFocus === 'onarim') {
        return (int) $row['repair_count'] > 0;
    }

    if ($branchScopeFocus === 'ayar') {
        return (int) $row['settings_count'] > 0;
    }

    if ($branchScopeFocus === 'ihlal') {
        return (int) $row['violation_count'] > 0;
    }

    return true;
}));
$branchScopeTimelineColumns = [
    'atama' => ['label' => 'Sube Atama', 'key' => 'assignment_count'],
    'onarim' => ['label' => 'Risk Onarimi', 'key' => 'repair_count'],
    'ayar' => ['label' => 'Kapsam Ayari', 'key' => 'settings_count'],
    'ihlal' => ['label' => 'Erisim Ihlali', 'key' => 'violation_count'],
];
$branchScopeActiveTimelineColumns = $branchScopeFocus === 'tum' ? array_values($branchScopeTimelineColumns) : [$branchScopeTimelineColumns[$branchScopeFocus]];
$branchScopeUserColumns = [
    'atama' => ['label' => 'Sube Atama', 'key' => 'assignment_count'],
    'onarim' => ['label' => 'Risk Onarimi', 'key' => 'repair_count'],
    'ayar' => ['label' => 'Kapsam Ayari', 'key' => 'settings_count'],
    'ihlal' => ['label' => 'Erisim Ihlali', 'key' => 'violation_count'],
];
$branchScopeActiveUserColumns = $branchScopeFocus === 'tum' ? array_values($branchScopeUserColumns) : [$branchScopeUserColumns[$branchScopeFocus]];
$branchScopeFocusHeadingLabel = $branchScopeFocus === 'ayar' ? 'Kapsam Ayari' : $branchScopeFocusDetail['label'];
$branchScopeTimelineTitle = $branchScopeFocus === 'tum'
    ? 'Sube Kapsam Islem Zaman Cizelgesi'
    : 'Sube ' . $branchScopeFocusHeadingLabel . ' Zaman Cizelgesi';
$branchScopeTimelineEmptyText = $branchScopeFocus === 'tum'
    ? 'Zaman cizelgesine yansiyan sube kapsam hareketi bulunamadi.'
    : $branchScopeFocusDetail['label'] . ' odagina ait zaman cizelgesi kaydi bulunamadi.';
$branchScopeUserTitle = $branchScopeFocus === 'tum'
    ? 'Sube Kapsam Islem Sorumlulari'
    : 'Sube ' . $branchScopeFocusHeadingLabel . ' Sorumlulari';
$branchScopeUserEmptyText = $branchScopeFocus === 'tum'
    ? 'Kullanici bazli sube kapsam hareketi bulunamadi.'
    : $branchScopeFocusDetail['label'] . ' odagina ait kullanici hareketi bulunamadi.';
$branchScopeSummaryRows = [];
if ($branchScopeFocus === 'tum' || $branchScopeFocus === 'onarim') {
    $branchScopeSummaryRows[] = [
        'title' => 'Risk Onarimi',
        'last_at' => $latestBranchRepair ? (string) $latestBranchRepair['created_at'] : '-',
        'detail' => $latestBranchRepair ? ((string) $latestBranchRepair['branch_name'] . ' / ' . (int) $latestBranchRepair['total_repaired'] . ' kayit') : 'Kayit bulunamadi.',
    ];
}
if ($branchScopeFocus === 'tum' || $branchScopeFocus === 'atama') {
    $branchScopeSummaryRows[] = [
        'title' => 'Subesiz Kayit Atamasi',
        'last_at' => $latestBranchAssignment ? (string) $latestBranchAssignment['created_at'] : '-',
        'detail' => $latestBranchAssignment ? ((string) $latestBranchAssignment['branch_name'] . ' / ' . (int) $latestBranchAssignment['total_moved'] . ' kayit') : 'Kayit bulunamadi.',
    ];
}
if ($branchScopeFocus === 'tum' || $branchScopeFocus === 'ayar') {
    $branchScopeSummaryRows[] = [
        'title' => 'Kapsam Ayari Degisikligi',
        'last_at' => $latestBranchScopeChange ? (string) $latestBranchScopeChange['created_at'] : '-',
        'detail' => ($latestBranchScopeChange && $latestBranchScopeChange['has_structured_change'])
            ? ((string) ($latestBranchScopeChange['old_scope']['mode'] ?? '-') . ' -> ' . (string) ($latestBranchScopeChange['new_scope']['mode'] ?? '-'))
            : ($latestBranchScopeChange ? (string) ($latestBranchScopeChange['description'] ?? '-') : 'Kayit bulunamadi.'),
    ];
}
if ($branchScopeFocus === 'ihlal') {
    $branchScopeSummaryRows[] = [
        'title' => 'Erisim Ihlali Ozeti',
        'last_at' => (string) ($branchAccessDeniedRows[0]['created_at'] ?? '-'),
        'detail' => (int) ($summary['Sube Ihlali'] ?? 0) > 0 ? (string) ((int) ($summary['Sube Ihlali'] ?? 0)) . ' ihlal kaydi var.' : 'Kayit bulunamadi.',
    ];
}
$branchScopeSummaryTitle = $branchScopeFocus === 'tum'
    ? 'Son Kapsam Ozetleri'
    : 'Son ' . $branchScopeFocusHeadingLabel . ' Ozeti';
$branchScopeSummaryEmptyText = $branchScopeFocusDetail['label'] . ' odagina ait ozet kaydi bulunamadi.';
$pendingApprovals = [];
$offerApprovalLogMap = [];
if (app_table_exists($db, 'sales_offer_approval_logs')) {
    foreach (app_fetch_all($db, '
        SELECT l.offer_id, l.action_name, l.note_text, l.created_by, l.created_at,
               COALESCE(u.full_name, "-") AS full_name
        FROM sales_offer_approval_logs l
        LEFT JOIN core_users u ON u.id = l.created_by
        INNER JOIN (
            SELECT offer_id, MAX(id) AS max_id
            FROM sales_offer_approval_logs
            GROUP BY offer_id
        ) latest ON latest.offer_id = l.offer_id AND latest.max_id = l.id
    ') as $logRow) {
        $offerApprovalLogMap[(int) ($logRow['offer_id'] ?? 0)] = $logRow;
    }
}
$offerResponsibleSelect = 'NULL AS responsible_name';
$offerResponsibleJoin = '';
if (app_column_exists($db, 'sales_offers', 'sales_user_id')) {
    $offerResponsibleSelect = 'u.full_name AS responsible_name';
    $offerResponsibleJoin = 'LEFT JOIN core_users u ON u.id = o.sales_user_id';
}
foreach (app_fetch_all($db, '
    SELECT o.id, o.offer_no, o.grand_total, o.offer_date, o.created_at,
           c.company_name, c.full_name, ' . $offerResponsibleSelect . '
    FROM sales_offers o
    LEFT JOIN cari_cards c ON c.id = o.cari_id
    ' . $offerResponsibleJoin . '
    WHERE o.status = "gonderildi"
    ORDER BY o.created_at ASC
    LIMIT 40
') as $row) {
    $offerLog = $offerApprovalLogMap[(int) ($row['id'] ?? 0)] ?? null;
    $isWaitingSecondApproval = (string) ($offerLog['action_name'] ?? '') === 'ilk_onay';
    $pendingApprovals[] = [
        'source' => $isWaitingSecondApproval ? 'Satis Teklifi / 2. Onay' : 'Satis Teklifi',
        'module' => 'satis',
        'record_id' => (int) $row['id'],
        'title' => (string) $row['offer_no'],
        'detail' => trim((string) (($row['company_name'] ?: $row['full_name'] ?: '-') . ' / ' . number_format((float) $row['grand_total'], 2, ',', '.') . ($isWaitingSecondApproval ? ' / Ilk onay: ' . (string) ($offerLog['full_name'] ?? '-') : ''))),
        'responsible' => $isWaitingSecondApproval ? (string) ($offerLog['full_name'] ?? '-') : (string) ($row['responsible_name'] ?: '-'),
        'requested_at' => $isWaitingSecondApproval ? (string) ($offerLog['created_at'] ?? ($row['created_at'] ?: $row['offer_date'])) : (string) ($row['created_at'] ?: $row['offer_date']),
        'link' => 'index.php?module=satis&offer_status=gonderildi',
    ];
}
if (app_table_exists($db, 'production_order_approvals') && app_table_exists($db, 'production_orders')) {
    foreach (app_fetch_all($db, '
        SELECT a.id, a.production_order_id, a.approval_step, a.requested_by, a.requested_at,
               o.order_no, o.status
        FROM production_order_approvals a
        LEFT JOIN production_orders o ON o.id = a.production_order_id
        WHERE a.approval_status = "bekliyor"
        ORDER BY a.requested_at ASC
        LIMIT 40
    ') as $row) {
        $pendingApprovals[] = [
            'source' => 'Uretim Onayi',
            'module' => 'uretim',
            'record_id' => (int) $row['id'],
            'title' => (string) (($row['order_no'] ?: 'Uretim Emri') . ' / ' . $row['approval_step']),
            'detail' => 'Durum: ' . (string) ($row['status'] ?: '-'),
            'responsible' => (string) ($row['requested_by'] ?: '-'),
            'requested_at' => (string) $row['requested_at'],
            'link' => 'index.php?module=uretim',
        ];
    }
}
if (app_table_exists($db, 'rental_contracts') && app_table_exists($db, 'rental_devices')) {
    foreach (app_fetch_all($db, '
        SELECT r.id, r.contract_no, r.monthly_rent, r.created_at,
               c.company_name, c.full_name, d.device_name, d.serial_no
        FROM rental_contracts r
        LEFT JOIN cari_cards c ON c.id = r.cari_id
        LEFT JOIN rental_devices d ON d.id = r.device_id
        WHERE r.status = "taslak"
        ORDER BY r.created_at ASC
        LIMIT 40
    ') as $row) {
        $pendingApprovals[] = [
            'source' => 'Kira Sozlesmesi',
            'module' => 'kira',
            'record_id' => (int) $row['id'],
            'title' => (string) $row['contract_no'],
            'detail' => trim((string) (($row['company_name'] ?: $row['full_name'] ?: '-') . ' / ' . ($row['device_name'] ?: '-') . ' / ' . number_format((float) $row['monthly_rent'], 2, ',', '.'))),
            'responsible' => '-',
            'requested_at' => (string) $row['created_at'],
            'link' => 'index.php?module=kira&contract_status=taslak',
        ];
    }
}
if (app_table_exists($db, 'service_records') && app_column_exists($db, 'service_records', 'customer_approval_status')) {
    $serviceApprovalDescriptionSelect = app_column_exists($db, 'service_records', 'customer_approval_description')
        ? 's.customer_approval_description'
        : 'NULL AS customer_approval_description';
    foreach (app_fetch_all($db, '
        SELECT s.id, s.service_no, s.complaint, ' . $serviceApprovalDescriptionSelect . ', s.customer_approval_status, s.opened_at,
               c.company_name, c.full_name, u.full_name AS assigned_name
        FROM service_records s
        LEFT JOIN cari_cards c ON c.id = s.cari_id
        LEFT JOIN core_users u ON u.id = s.assigned_user_id
        WHERE s.customer_approval_status IN ("bekliyor","talep_edildi")
        ORDER BY s.opened_at ASC
        LIMIT 40
    ') as $row) {
        $pendingApprovals[] = [
            'source' => 'Servis Musteri Onayi',
            'module' => 'servis',
            'record_id' => (int) $row['id'],
            'title' => (string) (($row['service_no'] ?: 'Servis') . ' / ' . ($row['customer_approval_description'] ?: $row['complaint'] ?: '-')),
            'detail' => (string) ($row['company_name'] ?: $row['full_name'] ?: '-'),
            'responsible' => (string) ($row['assigned_name'] ?: '-'),
            'requested_at' => (string) $row['opened_at'],
            'link' => 'index.php?module=servis',
        ];
    }
}
usort($pendingApprovals, static fn(array $a, array $b): int => strcmp((string) $a['requested_at'], (string) $b['requested_at']));
$pendingApprovalSummary = [];
foreach ($pendingApprovals as $approval) {
    $pendingApprovalSummary[$approval['source']] = ($pendingApprovalSummary[$approval['source']] ?? 0) + 1;
}
$summary['Bekleyen Onay'] = count($pendingApprovals);
$mediaFiles = app_fetch_all($db, '
    SELECT id, module_name, related_table, related_id, file_name, file_path, file_type, notes, created_at
    FROM docs_files
    ORDER BY id DESC
    LIMIT 20
');
$auditLogs = app_fetch_all($db, '
    SELECT l.module_name, l.action_name, l.record_table, l.record_id, l.description, l.created_at, u.full_name
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    ORDER BY l.id DESC
    LIMIT 20
');
$auditWhere = [];
$auditParams = [];
if ($logFilters['user_id'] > 0) {
    $auditWhere[] = 'l.user_id = :log_user_id';
    $auditParams['log_user_id'] = $logFilters['user_id'];
}
if ($logFilters['module_name'] !== '') {
    $auditWhere[] = 'l.module_name = :log_module_name';
    $auditParams['log_module_name'] = $logFilters['module_name'];
}
if ($logFilters['action_name'] !== '') {
    $auditWhere[] = 'l.action_name LIKE :log_action_name';
    $auditParams['log_action_name'] = '%' . $logFilters['action_name'] . '%';
}
if ($logFilters['date_from'] !== '') {
    $auditWhere[] = 'l.created_at >= :log_date_from';
    $auditParams['log_date_from'] = $logFilters['date_from'] . ' 00:00:00';
}
if ($logFilters['date_to'] !== '') {
    $auditWhere[] = 'l.created_at <= :log_date_to';
    $auditParams['log_date_to'] = $logFilters['date_to'] . ' 23:59:59';
}
$auditWhereSql = $auditWhere ? 'WHERE ' . implode(' AND ', $auditWhere) : '';
$userAuditLogs = app_fetch_all($db, "
    SELECT l.id, l.user_id, l.module_name, l.action_name, l.record_table, l.record_id, l.description,
           l.ip_address, l.user_agent, l.created_at, u.full_name, u.email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    {$auditWhereSql}
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 120
", $auditParams);
$auditModuleRows = app_fetch_all($db, '
    SELECT module_name, COUNT(*) AS log_count, MAX(created_at) AS last_at
    FROM core_audit_logs
    GROUP BY module_name
    ORDER BY log_count DESC, module_name ASC
    LIMIT 12
');
$auditUserRows = app_fetch_all($db, '
    SELECT COALESCE(u.full_name, "Sistem") AS full_name, COALESCE(u.email, "-") AS email, COUNT(*) AS log_count, MAX(l.created_at) AS last_at
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    GROUP BY l.user_id, u.full_name, u.email
    ORDER BY log_count DESC, full_name ASC
    LIMIT 12
');
app_notifications_ensure_schema($db);
$branchAccessDeniedRows = app_fetch_all($db, '
    SELECT l.id, l.record_table, l.record_id, l.description, l.ip_address, l.created_at,
           COALESCE(u.full_name, "Sistem") AS full_name, COALESCE(u.email, "-") AS email
    FROM core_audit_logs l
    LEFT JOIN core_users u ON u.id = l.user_id
    WHERE l.action_name = "branch_access_denied"
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 20
');
$branchAccessDeniedSummary = app_fetch_all($db, '
    SELECT COALESCE(record_table, "-") AS record_table, COUNT(*) AS denied_count, MAX(created_at) AS last_at
    FROM core_audit_logs
    WHERE action_name = "branch_access_denied"
    GROUP BY record_table
    ORDER BY denied_count DESC, record_table ASC
    LIMIT 12
');
$branchAccessNotificationRows = app_fetch_all($db, '
    SELECT id, recipient_name, recipient_contact, status, planned_at, processed_at, last_error
    FROM notification_queue
    WHERE module_name = "core"
      AND notification_type = "branch_access_denied"
    ORDER BY id DESC
    LIMIT 12
');
$summary['Sube Ihlali'] = app_metric($db, 'SELECT COUNT(*) FROM core_audit_logs WHERE action_name = "branch_access_denied"');
$summary['Ihlal Bildirimi'] = app_metric($db, 'SELECT COUNT(*) FROM notification_queue WHERE module_name = "core" AND notification_type = "branch_access_denied"');
?>

<?php if ($feedback !== ''): ?>
    <div class="notice <?= strpos($feedback, 'error:') === 0 ? 'notice-error' : 'notice-ok' ?>">
        <?= app_h(strpos($feedback, 'error:') === 0 ? substr($feedback, 6) : 'Ayar kaydedildi.') ?>
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
        <h3>Sube Bazli Masraf Dagitimi</h3>
        <p class="muted">Merkezi veya ortak giderleri secilen kurala gore subelere dagitip son plani kaydedin.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_branch_expense_allocation">
            <div>
                <label>Gider Tipi</label>
                <input name="expense_type" value="<?= app_h((string) ($lastExpenseAllocationPlan['expense_type'] ?? 'Genel Gider')) ?>" placeholder="Kira, genel gider, merkez hizmeti">
            </div>
            <div>
                <label>Toplam Tutar</label>
                <input type="number" step="0.01" min="0" name="total_amount" value="<?= app_h((string) ($lastExpenseAllocationPlan['total_amount'] ?? '0')) ?>">
            </div>
            <div>
                <label>Dagitim Kurali</label>
                <select name="allocation_basis">
                    <?php $selectedBasis = (string) ($lastExpenseAllocationPlan['allocation_basis'] ?? 'equal'); ?>
                    <?php foreach ($expenseAllocationBases as $basisKey => $basisMeta): ?>
                        <option value="<?= app_h($basisKey) ?>" <?= $selectedBasis === $basisKey ? 'selected' : '' ?>><?= app_h($basisMeta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Donem</label>
                <input type="month" name="allocation_period" value="<?= app_h((string) ($lastExpenseAllocationPlan['allocation_period'] ?? date('Y-m'))) ?>">
            </div>
            <div class="full">
                <label>Dagitim Notu</label>
                <textarea name="allocation_note" rows="3" placeholder="Merkez kira, genel operasyon gideri veya departman ortak maliyeti aciklamasi"><?= app_h((string) ($lastExpenseAllocationPlan['note'] ?? '')) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">Masraf Dagitim Planini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Dagitim Kurallari</h3>
        <div class="list">
            <?php foreach ($expenseAllocationBases as $basisMeta): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h($basisMeta['label']) ?></strong>
                        <span><?= app_h($basisMeta['detail']) ?></span>
                    </div>
                    <div class="ok">Hazir</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h3>Son Dagitim Plani</h3>
        <?php if ($lastExpenseAllocationPlan): ?>
            <div class="list">
                <div class="row"><div><strong style="font-size:1rem;"><?= app_h((string) ($lastExpenseAllocationPlan['expense_type'] ?? '-')) ?></strong><span>Donem <?= app_h((string) ($lastExpenseAllocationPlan['allocation_period'] ?? '-')) ?> / Kural <?= app_h((string) ($expenseAllocationBases[(string) ($lastExpenseAllocationPlan['allocation_basis'] ?? '')]['label'] ?? ($lastExpenseAllocationPlan['allocation_basis'] ?? '-'))) ?></span></div><div class="ok"><?= app_h(number_format((float) ($lastExpenseAllocationPlan['total_amount'] ?? 0), 2, ',', '.')) ?></div></div>
                <div class="row"><div><strong style="font-size:1rem;">Olusturan</strong><span><?= app_h((string) ($lastExpenseAllocationPlan['created_by_name'] ?? '-')) ?></span></div><div class="muted"><?= app_h((string) ($lastExpenseAllocationPlan['created_at'] ?? '-')) ?></div></div>
                <div class="row"><div><strong style="font-size:1rem;">Not</strong><span><?= app_h((string) (($lastExpenseAllocationPlan['note'] ?? '') !== '' ? $lastExpenseAllocationPlan['note'] : '-')) ?></span></div><div class="ok"><?= app_h((string) count($lastExpenseAllocationPlan['distribution_rows'] ?? [])) ?> Sube</div></div>
            </div>
            <div class="table-wrap" style="margin-top:16px;">
                <table>
                    <thead><tr><th>Sube</th><th>Agirlik</th><th>Pay</th><th>Dagitilan Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach (($lastExpenseAllocationPlan['distribution_rows'] ?? []) as $distributionRow): ?>
                        <tr>
                            <td><?= app_h((string) ($distributionRow['branch_name'] ?? '-')) ?></td>
                            <td><?= app_h(number_format((float) ($distributionRow['weight'] ?? 0), 2, ',', '.')) ?></td>
                            <td>%<?= app_h(number_format((float) ($distributionRow['share_ratio'] ?? 0), 2, ',', '.')) ?></td>
                            <td><?= app_h(number_format((float) ($distributionRow['allocated_amount'] ?? 0), 2, ',', '.')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="row"><div><strong style="font-size:1rem;">Plan hazir degil</strong><span>Ilk masraf dagitim planini kaydettiginizde burada gorunecek.</span></div><div class="warn">Bos</div></div>
        <?php endif; ?>

        <h3 style="margin-top:22px;">Fiili Sube Gider Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sube</th><th>Kayit</th><th>Toplam</th><th>Son Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($branchExpenseActualRows as $expenseRow): ?>
                    <tr>
                        <td><small><?= app_h((string) ($expenseRow['firm_name'] ?? '-')) ?><br><?= app_h((string) ($expenseRow['branch_name'] ?? '-')) ?></small></td>
                        <td><?= (int) ($expenseRow['expense_count'] ?? 0) ?></td>
                        <td><?= app_h(number_format((float) ($expenseRow['total_amount'] ?? 0), 2, ',', '.')) ?></td>
                        <td><?= app_h((string) (($expenseRow['last_expense_date'] ?? '') !== '' ? $expenseRow['last_expense_date'] : '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchExpenseActualRows): ?>
                    <tr><td colspan="4">Fiili sube gider kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Dagitim Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Ozet</th></tr></thead>
                <tbody>
                <?php foreach ($expenseAllocationHistory as $historyRow): ?>
                    <tr>
                        <td><?= app_h((string) $historyRow['created_at']) ?></td>
                        <td><?= app_h((string) $historyRow['full_name']) ?></td>
                        <td><small><?= app_h((string) ($historyRow['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$expenseAllocationHistory): ?>
                    <tr><td colspan="3">Henuz masraf dagitimi kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Acilis Kontrol Listesi</h3>
        <p class="muted">Gun basinda operasyon hazirligini sube bazinda kaydedin ve eksikleri tek ekranda gorun.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_branch_open_checklist">
            <div>
                <label>Sube</label>
                <select name="branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Acilis Tarihi</label>
                <input type="date" name="open_date" value="<?= app_h(date('Y-m-d')) ?>">
            </div>
            <div class="full">
                <label>Kontrol Maddeleri</label>
                <div class="module-grid">
                    <?php foreach ($branchOpenChecklistItems as $itemKey => $itemMeta): ?>
                        <label class="check-row" style="justify-content:flex-start; gap:8px;">
                            <input type="checkbox" name="check_items[]" value="<?= app_h($itemKey) ?>">
                            <span><strong><?= app_h($itemMeta['label']) ?></strong> <small><?= app_h($itemMeta['detail']) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="full">
                <label>Acilis Notu</label>
                <textarea name="open_note" rows="3" placeholder="Gun basi notlari, eksik personel, kritik stok veya acil operasyon bilgisi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Acilis Listesini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Acilis Ozeti</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Bugun Acilan Sube</strong><span>Bugun acilis listesi kaydedilen subeler.</span></div><div class="ok"><?= (int) $branchOpenOverview['opened_today'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Tam Acilis</strong><span>Tum maddeleri tamamlanan subeler.</span></div><div class="ok"><?= (int) $branchOpenOverview['full_count'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Eksik Acilis</strong><span>Acilis maddelerinden bazilari eksik kalan subeler.</span></div><div class="warn"><?= (int) $branchOpenOverview['partial_count'] ?></div></div>
        </div>
    </div>

    <div class="card">
        <h3>Son Sube Acilis Durumlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sube</th><th>Acilis</th><th>Operasyon Ozet</th><th>Kaydeden</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($branchOpenRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['branch_name']) ?></small></td>
                        <td><span class="<?= app_h($row['status_tone']) ?>"><?= app_h($row['status']) ?></span><br><small><?= (int) $row['checked_count'] ?>/<?= (int) $row['total_count'] ?> madde / <?= (int) $row['completion_ratio'] ?>%</small><?php if ($row['opened_at'] !== ''): ?><br><small><?= app_h($row['opened_at']) ?></small><?php endif; ?></td>
                        <td><small>Aktif kullanici <?= (int) $row['metrics']['user_count'] ?> / Kasa <?= (int) $row['metrics']['cashbox_count'] ?><br>Banka <?= (int) $row['metrics']['bank_account_count'] ?> / Kritik stok <?= (int) $row['metrics']['low_stock_count'] ?><br>Acik servis <?= (int) $row['metrics']['open_service_count'] ?> / Bugun kira <?= (int) $row['metrics']['today_rental_count'] ?></small></td>
                        <td><small><?= app_h($row['opened_by_name']) ?><br><?= app_h($row['open_date'] !== '' ? $row['open_date'] : '-') ?></small></td>
                        <td><small><?= app_h($row['note'] !== '' ? $row['note'] : '-') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchOpenRows): ?>
                    <tr><td colspan="5">Sube acilis kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Son Acilis Loglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Sube</th><th>Kullanici</th><th>Ozet</th></tr></thead>
                <tbody>
                <?php foreach ($branchOpenHistory as $historyRow): ?>
                    <tr>
                        <td><?= app_h($historyRow['created_at']) ?></td>
                        <td><small><?= app_h($historyRow['firm_name']) ?><br><?= app_h($historyRow['branch_name']) ?></small></td>
                        <td><?= app_h($historyRow['full_name']) ?></td>
                        <td><small><?= app_h((string) ($historyRow['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchOpenHistory): ?>
                    <tr><td colspan="4">Henuz sube acilis logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Kapanis Kontrol Listesi</h3>
        <p class="muted">Gun sonu operasyonlarini sube bazinda tek listeden takip edip kaydedin.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_branch_close_checklist">
            <div>
                <label>Sube</label>
                <select name="branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kapanis Tarihi</label>
                <input type="date" name="close_date" value="<?= app_h(date('Y-m-d')) ?>">
            </div>
            <div class="full">
                <label>Kontrol Maddeleri</label>
                <div class="module-grid">
                    <?php foreach ($branchCloseChecklistItems as $itemKey => $itemMeta): ?>
                        <label class="check-row" style="justify-content:flex-start; gap:8px;">
                            <input type="checkbox" name="check_items[]" value="<?= app_h($itemKey) ?>">
                            <span><strong><?= app_h($itemMeta['label']) ?></strong> <small><?= app_h($itemMeta['detail']) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="full">
                <label>Kapanis Notu</label>
                <textarea name="close_note" rows="3" placeholder="Gun sonu notlari, eksik kalan kontroller veya vardiya aciklamasi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Kapanis Listesini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Kapanis Ozeti</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Bugun Kapanan Sube</strong><span>Bugun en az bir kez kapanis listesi kaydedilen subeler.</span></div><div class="ok"><?= (int) $branchCloseOverview['closed_today'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Tam Kapanis</strong><span>Tum maddeleri eksiksiz isaretlenmis subeler.</span></div><div class="ok"><?= (int) $branchCloseOverview['full_count'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Eksik Kapanis</strong><span>Bazi maddeleri acik kalan subeler.</span></div><div class="warn"><?= (int) $branchCloseOverview['partial_count'] ?></div></div>
        </div>
    </div>

    <div class="card">
        <h3>Son Sube Kapanis Durumlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sube</th><th>Kapanis</th><th>Operasyon Ozet</th><th>Kaydeden</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($branchCloseRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['branch_name']) ?></small></td>
                        <td><span class="<?= app_h($row['status_tone']) ?>"><?= app_h($row['status']) ?></span><br><small><?= (int) $row['checked_count'] ?>/<?= (int) $row['total_count'] ?> madde / <?= (int) $row['completion_ratio'] ?>%</small><?php if ($row['closed_at'] !== ''): ?><br><small><?= app_h($row['closed_at']) ?></small><?php endif; ?></td>
                        <td><small>Kasa <?= (int) $row['metrics']['cashbox_count'] ?> / Banka <?= (int) $row['metrics']['bank_account_count'] ?><br>Acik siparis <?= (int) $row['metrics']['open_order_count'] ?> / Bugun fatura <?= (int) $row['metrics']['today_invoice_count'] ?><br>Acik servis <?= (int) $row['metrics']['open_service_count'] ?> / Aktif kira <?= (int) $row['metrics']['active_rental_count'] ?></small></td>
                        <td><small><?= app_h($row['closed_by_name']) ?><br><?= app_h($row['close_date'] !== '' ? $row['close_date'] : '-') ?></small></td>
                        <td><small><?= app_h($row['note'] !== '' ? $row['note'] : '-') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchCloseRows): ?>
                    <tr><td colspan="5">Sube kapanis kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Son Kapanis Loglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Sube</th><th>Kullanici</th><th>Ozet</th></tr></thead>
                <tbody>
                <?php foreach ($branchCloseHistory as $historyRow): ?>
                    <tr>
                        <td><?= app_h($historyRow['created_at']) ?></td>
                        <td><small><?= app_h($historyRow['firm_name']) ?><br><?= app_h($historyRow['branch_name']) ?></small></td>
                        <td><?= app_h($historyRow['full_name']) ?></td>
                        <td><small><?= app_h((string) ($historyRow['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchCloseHistory): ?>
                    <tr><td colspan="4">Henuz sube kapanis logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Sube Ihlali Bildirim Kuyrugu</h3>
    <p class="muted">Sube erisim engeli olustugunda super admin kullanicilarina e-posta bildirimi kuyruğa eklenir.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Alici</th><th>Durum</th><th>Plan</th><th>Islenme</th><th>Hata</th></tr></thead>
            <tbody>
            <?php foreach ($branchAccessNotificationRows as $row): ?>
                <tr>
                    <td><small><?= app_h((string) ($row['recipient_name'] ?: '-')) ?><br><?= app_h((string) $row['recipient_contact']) ?></small></td>
                    <td><?= app_h((string) $row['status']) ?></td>
                    <td><?= app_h((string) $row['planned_at']) ?></td>
                    <td><?= app_h((string) ($row['processed_at'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($row['last_error'] ?: '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchAccessNotificationRows): ?>
                <tr><td colspan="5">Henuz sube ihlali bildirimi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Erisim Ihlali Ozeti</h3>
        <p class="muted">Strict modda kullanicinin atanmis subesi disindaki kayitlara erisim denemeleri burada izlenir.</p>
        <div class="list">
            <?php foreach ($branchAccessDeniedSummary as $row): ?>
                <div class="row">
                    <div><strong style="font-size:1rem;"><?= app_h((string) $row['record_table']) ?></strong><span>Son deneme: <?= app_h((string) ($row['last_at'] ?: '-')) ?></span></div>
                    <div class="warn"><?= (int) $row['denied_count'] ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$branchAccessDeniedSummary): ?>
                <div class="row"><div><strong style="font-size:1rem;">Ihlal yok</strong><span>Sube disi erisim denemesi kaydedilmedi.</span></div><div class="ok">0</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Son Sube Erisim Engelleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Kayit</th><th>IP</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($branchAccessDeniedRows as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['created_at']) ?></td>
                        <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                        <td><small><?= app_h((string) $row['record_table']) ?><br>#<?= (int) $row['record_id'] ?></small></td>
                        <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($row['description'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchAccessDeniedRows): ?>
                    <tr><td colspan="5">Henuz sube erisim engeli yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Firma/Sube Bazli Veri Ayrimi</h3>
        <p class="muted">Sube atanmis kullanicilar ve branch_id tasiyan operasyon kayitlari tek merkezden izlenir.</p>
        <form method="post" class="form-grid compact-form">
            <input type="hidden" name="action" value="update_branch_scope_settings">
            <div>
                <label>Veri Izolasyon Modu</label>
                <select name="branch_isolation_mode">
                    <option value="off" <?= $settings['branch_isolation_mode'] === 'off' ? 'selected' : '' ?>>Kapali</option>
                    <option value="reporting" <?= $settings['branch_isolation_mode'] === 'reporting' ? 'selected' : '' ?>>Raporlama</option>
                    <option value="strict" <?= $settings['branch_isolation_mode'] === 'strict' ? 'selected' : '' ?>>Siki Kapsam</option>
                </select>
            </div>
            <div>
                <label>Varsayilan Sube</label>
                <select name="branch_default_id">
                    <option value="0">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>" <?= (int) $settings['branch_default_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= app_h($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Subesiz Kayit Politikasi</label>
                <select name="branch_unassigned_policy">
                    <option value="show_to_admin" <?= $settings['branch_unassigned_policy'] === 'show_to_admin' ? 'selected' : '' ?>>Sadece admin gorsun</option>
                    <option value="show_to_all" <?= $settings['branch_unassigned_policy'] === 'show_to_all' ? 'selected' : '' ?>>Tum yetkililer gorsun</option>
                    <option value="hide" <?= $settings['branch_unassigned_policy'] === 'hide' ? 'selected' : '' ?>>Gizle</option>
                </select>
            </div>
            <div class="full">
                <button type="submit">Sube Kapsamini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Sube Kapsam Ozeti</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Aktif Mod</strong><span>Kayitlarin sube bazli ayrisma seviyesi</span></div><div class="warn"><?= app_h($settings['branch_isolation_mode']) ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kapsamdaki Tablo</strong><span>branch_id kolonu bulunan operasyon tablolari</span></div><div class="ok"><?= count($branchScopeStats) ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Subesiz Kayit</strong><span>Temizlenmesi veya varsayilan subeye alinmasi gereken kayitlar</span></div><div class="warn"><?= (int) $summary['Subesiz Kayit'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kapsam Riski</strong><span>Subesiz, gecersiz veya pasif subeye bagli kayitlar</span></div><div class="<?= (int) $summary['Sube Kapsam Riski'] > 0 ? 'warn' : 'ok' ?>"><?= (int) $summary['Sube Kapsam Riski'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Oturum Kapsami</strong><span><?= app_user_is_global_scope($currentUser) ? 'Tum subeler' : app_h((string) ($currentUser['branch_name'] ?? 'Atanan sube')) ?></span></div><div class="ok"><?= app_user_is_global_scope($currentUser) ? 'Global' : 'Sube' ?></div></div>
        </div>
    </div>
</section>

<section class="card">
    <h3>Sube Kapsam Trend ve Son Durum</h3>
    <p class="muted">Anlik risk, son onarim, son atama ve son kapsam ayari degisikligi tek bakista izlenir.</p>
    <div class="list" style="margin-bottom:16px;">
        <div class="row">
            <div>
                <strong style="font-size:1rem;">Aktif Odak</strong>
                <span><?= app_h($branchScopeFocusSummaryText) ?></span>
            </div>
            <div class="ok"><?= app_h($branchScopeFocusDetail['label']) ?></div>
        </div>
        <div class="row">
            <div>
                <strong style="font-size:1rem;">Gorunum Yolu</strong>
                <span>Ayni odagi tekrar acmak veya ekip icinde paylasmak icin kullanilabilir.</span>
            </div>
            <div><small><?= app_h($branchScopeFocusSharePath) ?></small></div>
        </div>
    </div>
    <div class="card" style="margin-bottom:16px; border:1px solid <?= app_h($branchScopeFocusCardAccentMap[$branchScopeFocus] ?? '#2563eb') ?>;">
        <small>Aktif Hizli Odak</small>
        <strong class="ok"><?= app_h($branchScopeFocusDetail['label']) ?></strong>
        <p class="<?= app_h($branchScopeFocusStatusClass) ?>" style="margin:8px 0 0;"><?= app_h($branchScopeFocusStatusLabel) ?></p>
        <p class="muted" style="margin:8px 0 0;"><?= (int) ($branchScopeFocusDetail['count'] ?? 0) ?> kayit</p>
        <p class="muted" style="margin:4px 0 0;">Son hareket: <?= app_h($branchScopeFocusLastAt) ?></p>
        <p class="muted" style="margin:4px 0 0;">Ozet: <?= app_h($branchScopeFocusLastSummary) ?></p>
    </div>
    <div class="module-grid" style="margin-bottom:16px;">
        <?php foreach ($branchScopeFocusOptions as $focusKey => $focusLabel): ?>
            <?php if ($focusKey === $branchScopeFocus) { continue; } ?>
            <a href="index.php?module=core&amp;branch_scope_focus=<?= urlencode($focusKey) ?>" class="card" style="margin:0; text-decoration:none; border:1px solid <?= app_h($branchScopeFocusCardAccentMap[$focusKey] ?? '#d1d5db') ?>;">
                <small>Hizli Odak</small>
                <strong><?= app_h($focusLabel) ?></strong>
                <span class="<?= (($branchScopeFocusMeta[$focusKey]['count'] ?? 0) > 0) ? 'ok' : 'warn' ?>" style="margin-top:6px; display:inline-block;"><?= (int) ($branchScopeFocusMeta[$focusKey]['count'] ?? 0) ?> kayit</span>
                <span class="muted" style="margin-top:4px; display:inline-block;">Son hareket: <?= app_h((string) ($branchScopeFocusLastAtMap[$focusKey] ?? '-')) ?></span>
                <span class="muted" style="margin-top:4px; display:inline-block;">Ozet: <?= app_h((string) ($branchScopeFocusSummaryMap[$focusKey] ?? '-')) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="form-grid compact-form" style="margin-bottom:16px;">
        <input type="hidden" name="module" value="core">
        <div>
            <label>Odak Gorunumu</label>
            <select name="branch_scope_focus">
                <?php foreach ($branchScopeFocusOptions as $focusKey => $focusLabel): ?>
                    <option value="<?= app_h($focusKey) ?>" <?= $branchScopeFocus === $focusKey ? 'selected' : '' ?>><?= app_h($focusLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; align-items:end;">
            <button type="submit">Odaga Gore Filtrele</button>
        </div>
    </form>
    <div class="module-grid module-grid-4">
        <?php foreach ($branchScopeStatusCards as $card): ?>
            <div class="card" style="margin:0;">
                <small><?= app_h((string) $card['title']) ?></small>
                <strong class="<?= app_h((string) $card['tone']) ?>"><?= app_h((string) $card['value']) ?></strong>
                <p class="muted" style="margin:8px 0 0;"><?= app_h((string) $card['detail']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
    <h3 style="margin-top:18px;"><?= app_h($branchScopeSummaryTitle) ?></h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Baslik</th><th>Son Islem</th><th>Detay</th></tr></thead>
            <tbody>
                <?php foreach ($branchScopeSummaryRows as $summaryRow): ?>
                    <tr>
                        <td><?= app_h($summaryRow['title']) ?></td>
                        <td><?= app_h($summaryRow['last_at']) ?></td>
                        <td><small><?= app_h($summaryRow['detail']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchScopeSummaryRows): ?>
                    <tr><td colspan="3"><?= app_h($branchScopeSummaryEmptyText) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h3 style="margin-top:22px;"><?= app_h($branchScopeTimelineTitle) ?></h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><?php foreach ($branchScopeActiveTimelineColumns as $column): ?><th><?= app_h($column['label']) ?></th><?php endforeach; ?><th>Son Hareket</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeTimelineFiltered as $row): ?>
                <tr>
                    <td><?= app_h($row['event_day']) ?></td>
                    <?php foreach ($branchScopeActiveTimelineColumns as $column): ?>
                        <td><?= (int) ($row[$column['key']] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td><?= app_h($row['last_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeTimelineFiltered): ?>
                <tr><td colspan="<?= 2 + count($branchScopeActiveTimelineColumns) ?>"><?= app_h($branchScopeTimelineEmptyText) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h3 style="margin-top:22px;"><?= app_h($branchScopeUserTitle) ?></h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Kullanici</th><?php foreach ($branchScopeActiveUserColumns as $column): ?><th><?= app_h($column['label']) ?></th><?php endforeach; ?><th>Toplam</th><th>Son Hareket</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeUserRowsFiltered as $row): ?>
                <tr>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <?php foreach ($branchScopeActiveUserColumns as $column): ?>
                        <td><?= (int) ($row[$column['key']] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td><?= (int) $row['total_count'] ?></td>
                    <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeUserRowsFiltered): ?>
                <tr><td colspan="<?= 3 + count($branchScopeActiveUserColumns) ?>"><?= app_h($branchScopeUserEmptyText) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Sube Kapsam Saglik Kontrolu</h3>
    <p class="muted">Branch baglantisi bulunan tablolarda subesiz, silinmis/gecersiz subeye bagli ve pasif subeye bagli kayitlar izlenir.</p>
    <form method="post" class="form-grid compact-form" style="margin-bottom:16px;">
        <input type="hidden" name="action" value="repair_branch_scope_risks">
        <div>
            <label>Riskleri Tasinacak Hedef Sube</label>
            <select name="target_branch_id" required>
                <option value="">Seciniz</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int) $branch['id'] ?>" <?= (int) $settings['branch_default_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="full">
            <label>Duzeltilecek Riskli Tablolar</label>
            <div class="module-grid">
                <?php foreach ($branchScopeHealthRows as $row): ?>
                    <?php if ((int) $row['risk_total'] <= 0) { continue; } ?>
                    <label class="check-row" style="justify-content:flex-start; gap:8px;">
                        <input type="checkbox" name="scope_tables[]" value="<?= app_h($row['table']) ?>" checked>
                        <span><?= app_h($row['label']) ?> (<?= (int) $row['risk_total'] ?> risk)</span>
                    </label>
                <?php endforeach; ?>
                <?php if ((int) $summary['Sube Kapsam Riski'] <= 0): ?>
                    <small class="muted">Duzeltilecek risk bulunmuyor.</small>
                <?php endif; ?>
            </div>
        </div>
        <div class="full">
            <button type="submit" <?= (int) $summary['Sube Kapsam Riski'] <= 0 ? 'disabled' : '' ?> onclick="return confirm('Secili tablolardaki sube kapsam riskleri hedef aktif subeye tasinacak. Devam edilsin mi?');">Riskleri Hedef Subeye Tasi</button>
        </div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tablo</th><th>Toplam</th><th>Subesiz</th><th>Gecersiz Sube</th><th>Pasif Sube</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeHealthRows as $row): ?>
                <tr>
                    <td><small><?= app_h($row['label']) ?><br><?= app_h($row['table']) ?></small></td>
                    <td><?= (int) $row['total'] ?></td>
                    <td><?= (int) $row['unassigned'] ?></td>
                    <td><?= (int) $row['invalid_branch'] ?></td>
                    <td><?= (int) $row['passive_branch'] ?></td>
                    <td><span class="<?= (int) $row['risk_total'] > 0 ? 'warn' : 'ok' ?>"><?= (int) $row['risk_total'] > 0 ? 'Kontrol Gerekli' : 'Temiz' ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeHealthRows): ?>
                <tr><td colspan="6">Kontrol edilecek sube kapsam tablosu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <h3 style="margin-top:22px;">Son Sube Risk Onarimlari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Hedef Sube</th><th>Duzeltilen Kayit</th><th>Tablo Dagilimi</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeRepairHistory as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><?= app_h((string) $row['full_name']) ?></td>
                    <td><small><?= app_h((string) $row['firm_name']) ?><br><?= app_h((string) $row['branch_name']) ?></small></td>
                    <td><?= (int) $row['total_repaired'] ?></td>
                    <td>
                        <?php foreach ($row['table_counts'] as $tableName => $count): ?>
                            <small><?= app_h($branchScopeTables[$tableName] ?? $tableName) ?>: <?= (int) $count ?></small><br>
                        <?php endforeach; ?>
                        <?php if (!$row['table_counts']): ?>
                            <small>Dagilim okunamadi</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeRepairHistory): ?>
                <tr><td colspan="5">Sube risk onarim kaydi bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Sube Kapsam Degisiklik Gecmisi</h3>
    <p class="muted">Sube izolasyon modu, varsayilan sube ve subesiz kayit politikasinda yapilan son degisiklikler denetlenir.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Mod Degisimi</th><th>Varsayilan Sube</th><th>Subesiz Politika</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeChanges as $row): ?>
                <?php
                    $oldScope = $row['old_scope'];
                    $newScope = $row['new_scope'];
                ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td>
                        <?php if ($row['has_structured_change']): ?>
                            <small><?= app_h((string) ($oldScope['mode'] ?? '-')) ?> -> <?= app_h((string) ($newScope['mode'] ?? '-')) ?></small>
                        <?php else: ?>
                            <small>Eski format log</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['has_structured_change']): ?>
                            <small><?= app_h((string) ($oldScope['default_branch_id'] ?? '-')) ?> -> <?= app_h((string) ($newScope['default_branch_id'] ?? '-')) ?></small>
                        <?php else: ?>
                            <small>-</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['has_structured_change']): ?>
                            <small><?= app_h((string) ($oldScope['unassigned_policy'] ?? '-')) ?> -> <?= app_h((string) ($newScope['unassigned_policy'] ?? '-')) ?></small>
                        <?php else: ?>
                            <small>-</small>
                        <?php endif; ?>
                    </td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeChanges): ?>
                <tr><td colspan="6">Sube kapsam ayari degisikligi bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Sube Veri Dagilimi</h3>
    <form method="post" class="form-grid compact-form" style="margin-bottom:16px;">
        <input type="hidden" name="action" value="assign_unassigned_branch_records">
        <div>
            <label>Hedef Sube</label>
            <select name="target_branch_id" required>
                <option value="">Seciniz</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int) $branch['id'] ?>" <?= (int) $settings['branch_default_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="full">
            <label>Subesiz Kayitlari Guncellenecek Tablolar</label>
            <div class="module-grid">
                <?php foreach ($branchScopeStats as $stat): ?>
                    <label class="check-row" style="justify-content:flex-start; gap:8px;">
                        <input type="checkbox" name="scope_tables[]" value="<?= app_h($stat['table']) ?>" <?= (int) $stat['unassigned'] > 0 ? 'checked' : '' ?>>
                        <span><?= app_h($stat['label']) ?> (<?= (int) $stat['unassigned'] ?> subesiz)</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="full">
            <button type="submit" onclick="return confirm('Secili tablolardaki subesiz kayitlar hedef subeye baglanacak. Devam edilsin mi?');">Subesiz Kayitlari Subeye Bagla</button>
        </div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tablo</th><th>Toplam</th><th>Subesiz</th><th>Dagilim</th></tr></thead>
            <tbody>
            <?php foreach ($branchScopeStats as $stat): ?>
                <tr>
                    <td><small><?= app_h($stat['label']) ?><br><?= app_h($stat['table']) ?></small></td>
                    <td><?= (int) $stat['total'] ?></td>
                    <td><?= (int) $stat['unassigned'] ?></td>
                    <td>
                        <?php foreach (array_slice($stat['distribution'], 0, 4) as $row): ?>
                            <small><?= app_h((string) $row['branch_name']) ?>: <?= (int) $row['record_count'] ?></small><br>
                        <?php endforeach; ?>
                        <?php if (!$stat['distribution']): ?>
                            <small>Kayit yok</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchScopeStats): ?>
                <tr><td colspan="4">Sube kapsaminda tablo bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Atama Gecmisi</h3>
        <p class="muted">Subesiz kayitlari subeye baglama islemlerinin son hedefleri ve tarihleri izlenir.</p>
        <div class="list">
            <?php foreach ($branchAssignmentSummary as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h((string) $row['branch_name']) ?></strong>
                        <span><?= app_h((string) $row['firm_name']) ?> / Son islem: <?= app_h((string) $row['last_at']) ?></span>
                    </div>
                    <div class="ok"><?= (int) $row['operation_count'] ?> Islem</div>
                </div>
            <?php endforeach; ?>
            <?php if (!$branchAssignmentSummary): ?>
                <div class="row"><div><strong style="font-size:1rem;">Atama gecmisi yok</strong><span>Subesiz kayitlar henuz toplu olarak subeye baglanmadi.</span></div><div class="warn">0</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Son Sube Atama Islemleri</h3>
        <p class="muted">Her islemde hangi tablodan kac kaydin hedef subeye tasindigi kayit altina alinir.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Hedef Sube</th><th>Tasinan Kayit</th><th>Tablo Dagilimi</th></tr></thead>
                <tbody>
                <?php foreach ($branchAssignmentHistory as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['created_at']) ?></td>
                        <td><?= app_h((string) $row['full_name']) ?></td>
                        <td><small><?= app_h((string) $row['firm_name']) ?><br><?= app_h((string) $row['branch_name']) ?></small></td>
                        <td><?= (int) $row['total_moved'] ?></td>
                        <td>
                            <?php foreach ($row['table_counts'] as $tableName => $count): ?>
                                <small><?= app_h($branchScopeTables[$tableName] ?? $tableName) ?>: <?= (int) $count ?></small><br>
                            <?php endforeach; ?>
                            <?php if (!$row['table_counts']): ?>
                                <small>Dagilim okunamadi</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchAssignmentHistory): ?>
                    <tr><td colspan="5">Sube atama islemi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Onay Bekleyen Islemler Merkezi</h3>
        <p class="muted">Satis, uretim, kira ve servis kaynakli bekleyen onaylar tek ekranda izlenir.</p>
        <div class="list">
            <?php foreach ($pendingApprovalSummary as $source => $count): ?>
                <div class="row">
                    <div><strong style="font-size:1rem;"><?= app_h($source) ?></strong><span>Bekleyen onay kaydi</span></div>
                    <div class="warn"><?= (int) $count ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$pendingApprovalSummary): ?>
                <div class="row"><div><strong style="font-size:1rem;">Bekleyen onay yok</strong><span>Tum kaynaklar temiz gorunuyor.</span></div><div class="ok">0</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Onay Merkezi Mantigi</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Satis</strong><span>`gonderildi` durumundaki teklifler onay bekler.</span></div><div class="warn">Teklif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Uretim</strong><span>`bekliyor` durumundaki uretim onay adimlari listelenir.</span></div><div class="warn">Emir</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kira</strong><span>`taslak` sozlesmeler aktivasyon/onay icin izlenir.</span></div><div class="warn">Sozlesme</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Servis</strong><span>Musteri onayi bekleyen servis kayitlari merkeze duser.</span></div><div class="warn">Onay</div></div>
        </div>
    </div>
</section>

<section class="card">
    <h3>Bekleyen Onay Detaylari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Kaynak</th><th>Baslik</th><th>Detay</th><th>Sorumlu</th><th>Talep Tarihi</th><th>Islem</th></tr></thead>
            <tbody>
            <?php foreach ($pendingApprovals as $approval): ?>
                <tr>
                    <td><small><?= app_h($approval['source']) ?><br><?= app_h($approval['module']) ?> #<?= (int) $approval['record_id'] ?></small></td>
                    <td><?= app_h($approval['title']) ?></td>
                    <td><?= app_h($approval['detail']) ?></td>
                    <td><?= app_h($approval['responsible']) ?></td>
                    <td><?= app_h($approval['requested_at']) ?></td>
                    <td><a href="<?= app_h($approval['link']) ?>">Modulde Ac</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pendingApprovals): ?>
                <tr><td colspan="6">Bekleyen onay kaydi bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Oturum Zaman Asimi Yonetimi</h3>
    <p class="muted">Belirlenen sure boyunca hareketsiz kalan kullanicilarin oturumu otomatik olarak kapatilabilir.</p>
    <form method="post" class="form-grid compact-form">
        <input type="hidden" name="action" value="update_session_timeout_settings">
        <div class="check-row">
            <label><input type="checkbox" name="security_session_timeout_enabled" value="1" <?= $settings['security_session_timeout_enabled'] === '1' ? 'checked' : '' ?>> Oturum zaman asimi aktif</label>
        </div>
        <div>
            <label>Sure (dakika)</label>
            <input type="number" name="security_session_timeout_minutes" min="5" max="480" value="<?= app_h((string) $settings['security_session_timeout_minutes']) ?>">
        </div>
        <div style="display:flex;align-items:end;">
            <button type="submit">Oturum Ayarlarini Kaydet</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Oturum Durumu</h3>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Zaman Asimi</strong>
                    <span><?= $settings['security_session_timeout_enabled'] === '1' ? 'Hareketsiz oturumlar otomatik kapatilir.' : 'Oturumlar sureye bagli kapatilmiyor.' ?></span>
                </div>
                <div class="<?= $settings['security_session_timeout_enabled'] === '1' ? 'warn' : 'ok' ?>"><?= $settings['security_session_timeout_enabled'] === '1' ? 'Aktif' : 'Kapali' ?></div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Süre</strong>
                    <span>Mevcut pasif kalma limiti <?= (int) $settings['security_session_timeout_minutes'] ?> dakikadir.</span>
                </div>
                <div class="ok"><?= (int) $settings['security_session_timeout_minutes'] ?> dk</div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son Timeout</strong>
                    <span>
                        <?php if ($latestSessionTimeout): ?>
                            <?= app_h((string) $latestSessionTimeout['full_name']) ?> / <?= app_h((string) $latestSessionTimeout['created_at']) ?>
                        <?php else: ?>
                            Henuz timeout kaydi yok
                        <?php endif; ?>
                    </span>
                </div>
                <div class="warn"><?= app_h((string) $summary['Timeout Logu']) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Kullanici Bazli Timeout Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kullanici</th><th>Timeout</th><th>Son Hareket</th></tr></thead>
                <tbody>
                <?php foreach ($sessionTimeoutUserSummary as $row): ?>
                    <tr>
                        <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                        <td><?= (int) $row['timeout_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sessionTimeoutUserSummary): ?>
                    <tr><td colspan="3">Henuz kullanici bazli timeout kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Son Oturum Timeout Loglari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>IP</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($sessionTimeoutRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sessionTimeoutRows): ?>
                <tr><td colspan="4">Oturum zaman asimi logu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Iki Adimli Dogrulama</h3>
    <p class="muted">Kullanici ve sifre sonrasinda e-posta ile tek kullanımlik kod dogrulama adimi calistirilir.</p>
    <form method="post" class="form-grid compact-form">
        <input type="hidden" name="action" value="update_two_factor_settings">
        <div>
            <label>2FA Modu</label>
            <select name="security_two_factor_mode">
                <option value="off" <?= $settings['security_two_factor_mode'] === 'off' ? 'selected' : '' ?>>Kapali</option>
                <option value="email" <?= $settings['security_two_factor_mode'] === 'email' ? 'selected' : '' ?>>E-posta OTP</option>
            </select>
        </div>
        <div>
            <label>Kod Gecerlilik Suresi (dk)</label>
            <input type="number" name="security_two_factor_ttl" min="1" max="30" value="<?= app_h((string) $settings['security_two_factor_ttl']) ?>">
        </div>
        <div style="display:flex;align-items:end;">
            <button type="submit">2FA Ayarlarini Kaydet</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>2FA Durumu</h3>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Aktif Mod</strong>
                    <span><?= $settings['security_two_factor_mode'] === 'email' ? 'Girislerde e-posta dogrulama kodu zorunlu.' : 'Ek dogrulama kapali.' ?></span>
                </div>
                <div class="<?= $settings['security_two_factor_mode'] === 'email' ? 'warn' : 'ok' ?>"><?= $settings['security_two_factor_mode'] === 'email' ? 'Email OTP' : 'Kapali' ?></div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Kod Suresi</strong>
                    <span>Gonderilen kodlar <?= (int) $settings['security_two_factor_ttl'] ?> dakika boyunca gecerlidir.</span>
                </div>
                <div class="ok"><?= (int) $settings['security_two_factor_ttl'] ?> dk</div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son 2FA Hareketi</strong>
                    <span>
                        <?php if ($latestTwoFactorAudit): ?>
                            <?= app_h((string) $latestTwoFactorAudit['full_name']) ?> / <?= app_h((string) $latestTwoFactorAudit['action_name']) ?> / <?= app_h((string) $latestTwoFactorAudit['created_at']) ?>
                        <?php else: ?>
                            Henuz 2FA hareketi yok
                        <?php endif; ?>
                    </span>
                </div>
                <div class="warn"><?= app_h((string) $summary['2FA Basari']) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>2FA Islem Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Islem</th><th>Adet</th><th>Son Hareket</th></tr></thead>
                <tbody>
                <?php foreach ($twoFactorActionSummary as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['action_name']) ?></td>
                        <td><?= (int) $row['total_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$twoFactorActionSummary): ?>
                    <tr><td colspan="3">Henuz 2FA ozeti olusmadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Son 2FA Loglari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Islem</th><th>IP</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($twoFactorAuditRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td><?= app_h((string) $row['action_name']) ?></td>
                    <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$twoFactorAuditRows): ?>
                <tr><td colspan="5">2FA logu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>IP Bazli Erisim Kisitlari</h3>
    <p class="muted">Belirli IP adresleri, jokerli prefix kurallari veya CIDR araliklari ile sisteme erisim sinirlandirilabilir.</p>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="update_ip_access_settings">
        <div>
            <label>IP Guvenlik Modu</label>
            <select name="security_ip_mode">
                <option value="off" <?= $settings['security_ip_mode'] === 'off' ? 'selected' : '' ?>>Kapali</option>
                <option value="allowlist" <?= $settings['security_ip_mode'] === 'allowlist' ? 'selected' : '' ?>>Sadece izinli IP</option>
            </select>
        </div>
        <div class="check-row">
            <label><input type="checkbox" name="security_ip_local_bypass" value="1" <?= $settings['security_ip_local_bypass'] !== '0' ? 'checked' : '' ?>> Localhost / 127.0.0.1 her zaman izinli kalsin</label>
        </div>
        <div class="full">
            <label>Izinli IP Listesi</label>
            <textarea name="security_ip_allowlist" rows="6" placeholder="127.0.0.1&#10;192.168.1.*&#10;10.0.0.0/24"><?= app_h((string) $settings['security_ip_allowlist']) ?></textarea>
        </div>
        <div class="full">
            <button type="submit">IP Kurallarini Kaydet</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>IP Erisim Durumu</h3>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Aktif Mod</strong>
                    <span><?= $settings['security_ip_mode'] === 'allowlist' ? 'Sadece izinli IP adresleri sisteme girebilir.' : 'IP bazli kisit kapali.' ?></span>
                </div>
                <div class="<?= $settings['security_ip_mode'] === 'allowlist' ? 'warn' : 'ok' ?>"><?= $settings['security_ip_mode'] === 'allowlist' ? 'Aktif' : 'Kapali' ?></div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Localhost Istisnasi</strong>
                    <span>Yerel gelistirme erisimi <?= $settings['security_ip_local_bypass'] !== '0' ? 'korunuyor' : 'ayni kurallara tabi' ?>.</span>
                </div>
                <div class="<?= $settings['security_ip_local_bypass'] !== '0' ? 'ok' : 'warn' ?>"><?= $settings['security_ip_local_bypass'] !== '0' ? 'Acik' : 'Kapali' ?></div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son Engellenen IP</strong>
                    <span>
                        <?php if ($latestIpAccessDenied): ?>
                            <?= app_h((string) $latestIpAccessDenied['ip_address']) ?> / <?= app_h((string) $latestIpAccessDenied['deny_context']) ?> / <?= app_h((string) $latestIpAccessDenied['created_at']) ?>
                        <?php else: ?>
                            Henuz engellenen IP kaydi yok
                        <?php endif; ?>
                    </span>
                </div>
                <div class="warn"><?= (int) $summary['IP Engeli'] ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Izinli IP Kurallari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kural</th><th>Tip</th></tr></thead>
                <tbody>
                <?php foreach ($ipAccessRules as $rule): ?>
                    <tr>
                        <td><?= app_h($rule) ?></td>
                        <td><?= strpos($rule, '/') !== false ? 'CIDR' : (strpos($rule, '*') !== false ? 'Prefix / Joker' : 'Tek IP') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ipAccessRules): ?>
                    <tr><td colspan="2">Tanimli izinli IP kurali yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Engellenen IP Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>IP</th><th>Engel</th><th>Son Deneme</th></tr></thead>
                <tbody>
                <?php foreach ($ipAccessDeniedSummary as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['ip_address']) ?></td>
                        <td><?= (int) $row['deny_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ipAccessDeniedSummary): ?>
                    <tr><td colspan="3">Engellenen IP kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Son IP Erisim Engelleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>IP</th><th>Baglam</th><th>E-posta</th><th>Cihaz</th></tr></thead>
                <tbody>
                <?php foreach ($ipAccessDeniedRows as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['created_at']) ?></td>
                        <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                        <td><?= app_h((string) $row['deny_context']) ?></td>
                        <td><?= app_h((string) $row['deny_email']) ?></td>
                        <td><small><?= app_h(substr((string) ($row['user_agent'] ?: '-'), 0, 90)) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ipAccessDeniedRows): ?>
                    <tr><td colspan="5">IP bazli erisim engeli logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kullanici Giris Gecmisi</h3>
        <p class="muted">Basarili oturum acma hareketleri kullanici, sube ve son gorulen IP bilgisi ile izlenir.</p>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son Giris</strong>
                    <span>
                        <?php if ($latestLoginAudit): ?>
                            <?= app_h((string) $latestLoginAudit['full_name']) ?> / <?= app_h((string) $latestLoginAudit['created_at']) ?>
                        <?php else: ?>
                            Henuz giris logu yok
                        <?php endif; ?>
                    </span>
                </div>
                <div class="ok"><?= app_h((string) ($latestLoginAudit['ip_address'] ?? '-')) ?></div>
            </div>
            <?php foreach ($loginUserSummary as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h((string) $row['full_name']) ?></strong>
                        <span><?= app_h((string) $row['email']) ?> / <?= app_h((string) $row['branch_name']) ?> / Son giris: <?= app_h((string) ($row['last_at'] ?: '-')) ?></span>
                    </div>
                    <div class="ok"><?= (int) $row['login_count'] ?> Giris</div>
                </div>
            <?php endforeach; ?>
            <?php if (!$loginUserSummary): ?>
                <div class="row"><div><strong style="font-size:1rem;">Giris kaydi yok</strong><span>Basarili kullanici girisleri loglandiginda bu alan dolacak.</span></div><div class="warn">0</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Giris IP Ozeti</h3>
        <p class="muted">En son gorulen IP adresleri ve ayni IP uzerinden oturum acan kullanici sayilari listelenir.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>IP</th><th>Giris</th><th>Kullanici</th><th>Son Hareket</th></tr></thead>
                <tbody>
                <?php foreach ($loginIpSummary as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['ip_address']) ?></td>
                        <td><?= (int) $row['login_count'] ?></td>
                        <td><?= (int) $row['user_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$loginIpSummary): ?>
                    <tr><td colspan="4">Henuz IP bazli giris kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Son Basarili Girisler</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Sube</th><th>IP</th><th>Cihaz</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($loginAuditRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td><?= app_h((string) $row['branch_name']) ?></td>
                    <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                    <td><small><?= app_h(substr((string) ($row['user_agent'] ?: '-'), 0, 90)) ?></small></td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?><br>Son login alanı: <?= app_h((string) ($row['last_login_at'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$loginAuditRows): ?>
                <tr><td colspan="6">Basarili kullanici girisi logu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Basarisiz Giris Denemeleri</h3>
        <p class="muted">Hatali sifre, bulunamayan kullanici ve limit asimi durumlari guvenlik takibi icin burada toplanir.</p>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son Riskli Deneme</strong>
                    <span>
                        <?php if ($latestFailedLoginAudit): ?>
                            <?= app_h((string) $latestFailedLoginAudit['attempted_email']) ?> / <?= app_h((string) $latestFailedLoginAudit['reason_label']) ?> / <?= app_h((string) $latestFailedLoginAudit['created_at']) ?>
                        <?php else: ?>
                            Henuz basarisiz giris denemesi logu yok
                        <?php endif; ?>
                    </span>
                </div>
                <div class="warn"><?= app_h((string) ($latestFailedLoginAudit['ip_address'] ?? '-')) ?></div>
            </div>
            <?php foreach ($failedLoginReasonSummary as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h((string) $row['attempted_email']) ?></strong>
                        <span><?= app_h((string) $row['reason_label']) ?> / Son deneme: <?= app_h((string) ($row['last_at'] ?: '-')) ?></span>
                    </div>
                    <div class="warn"><?= (int) $row['failed_count'] ?> Deneme</div>
                </div>
            <?php endforeach; ?>
            <?php if (!$failedLoginReasonSummary): ?>
                <div class="row"><div><strong style="font-size:1rem;">Riskli deneme yok</strong><span>Basarisiz veya bloklanan oturum denemesi kayitlari burada listelenecek.</span></div><div class="ok">0</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Riskli IP Ozeti</h3>
        <p class="muted">Ayni IP uzerinden gelen basarisiz deneme ve blok sayilari toplu gorunur.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>IP</th><th>Basarisiz</th><th>E-posta</th><th>Blok</th><th>Son Deneme</th></tr></thead>
                <tbody>
                <?php foreach ($failedLoginIpSummary as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['ip_address']) ?></td>
                        <td><?= (int) $row['failed_count'] ?></td>
                        <td><?= (int) $row['email_count'] ?></td>
                        <td><?= (int) $row['blocked_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$failedLoginIpSummary): ?>
                    <tr><td colspan="5">Henuz riskli IP kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Son Basarisiz Girisler</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>E-posta</th><th>Sebep</th><th>Kullanici</th><th>IP</th><th>Cihaz</th></tr></thead>
            <tbody>
            <?php foreach ($failedLoginAuditRows as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><?= app_h((string) $row['attempted_email']) ?></td>
                    <td><?= app_h((string) $row['reason_label']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['user_email']) ?></small></td>
                    <td><?= app_h((string) ($row['ip_address'] ?: '-')) ?></td>
                    <td><small><?= app_h(substr((string) ($row['user_agent'] ?: '-'), 0, 90)) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$failedLoginAuditRows): ?>
                <tr><td colspan="6">Basarisiz giris logu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>Kullanici Islem Loglari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="core">
        <div>
            <label>Kullanici</label>
            <select name="log_user_id">
                <option value="0">Tum kullanicilar</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= $logFilters['user_id'] === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= app_h($user['full_name'] . ' / ' . ($user['email'] ?: '-')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Modul</label>
            <select name="log_module_name">
                <option value="">Tum moduller</option>
                <?php foreach ($moduleDefinitions as $moduleKey => $module): ?>
                    <option value="<?= app_h($moduleKey) ?>" <?= $logFilters['module_name'] === $moduleKey ? 'selected' : '' ?>><?= app_h($module['title']) ?></option>
                <?php endforeach; ?>
                <option value="auth" <?= $logFilters['module_name'] === 'auth' ? 'selected' : '' ?>>Oturum</option>
                <option value="tahsilat_public" <?= $logFilters['module_name'] === 'tahsilat_public' ? 'selected' : '' ?>>Public Tahsilat</option>
            </select>
        </div>
        <div>
            <label>Islem</label>
            <input name="log_action_name" value="<?= app_h($logFilters['action_name']) ?>" placeholder="create_user, update...">
        </div>
        <div>
            <label>Baslangic</label>
            <input type="date" name="log_date_from" value="<?= app_h($logFilters['date_from']) ?>">
        </div>
        <div>
            <label>Bitis</label>
            <input type="date" name="log_date_to" value="<?= app_h($logFilters['date_to']) ?>">
        </div>
        <div style="display:flex;align-items:end;">
            <button type="submit">Loglari Filtrele</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Modul Bazli Log Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Modul</th><th>Kayit</th><th>Son Islem</th></tr></thead>
                <tbody>
                <?php foreach ($auditModuleRows as $row): ?>
                    <tr>
                        <td><?= app_h($row['module_name']) ?></td>
                        <td><?= (int) $row['log_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$auditModuleRows): ?>
                    <tr><td colspan="3">Henuz log kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Kullanici Bazli Log Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kullanici</th><th>Kayit</th><th>Son Islem</th></tr></thead>
                <tbody>
                <?php foreach ($auditUserRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['full_name']) ?><br><?= app_h($row['email']) ?></small></td>
                        <td><?= (int) $row['log_count'] ?></td>
                        <td><?= app_h((string) ($row['last_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$auditUserRows): ?>
                    <tr><td colspan="3">Henuz kullanici logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Islem Log Detaylari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Modul</th><th>Islem</th><th>Kayit</th><th>IP</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($userAuditLogs as $log): ?>
                <tr>
                    <td><?= app_h($log['created_at']) ?></td>
                    <td><small><?= app_h((string) ($log['full_name'] ?: 'Sistem')) ?><br><?= app_h((string) ($log['email'] ?: '-')) ?></small></td>
                    <td><?= app_h($log['module_name']) ?></td>
                    <td><?= app_h($log['action_name']) ?></td>
                    <td><?= app_h((string) (($log['record_table'] ?: '-') . ' #' . ($log['record_id'] ?: '-'))) ?></td>
                    <td><?= app_h((string) ($log['ip_address'] ?: '-')) ?></td>
                    <td><small><?= app_h((string) ($log['description'] ?: '-')) ?><br><?= app_h(substr((string) ($log['user_agent'] ?: '-'), 0, 100)) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$userAuditLogs): ?>
                <tr><td colspan="7">Filtreye uygun islem logu bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yetki Sablonlari</h3>
        <p class="muted">Hazir rol sablonlari ile modul ve islem izinleri tek adimda uygulanabilir.</p>
        <div class="list">
            <?php foreach ($permissionTemplateSummaries as $templateKey => $template): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h($template['label']) ?></strong>
                        <span><?= app_h($template['summary']) ?> / <?= (int) $template['module_count'] ?> modul / <?= (int) $template['action_count'] ?> islem</span>
                    </div>
                    <div class="ok"><?= app_h($templateKey) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h3>Role Sablon Uygula</h3>
        <form method="post" class="form-grid compact-form">
            <input type="hidden" name="action" value="apply_permission_template">
            <div>
                <label>Rol</label>
                <select name="role_code" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($roles as $role): ?>
                        <?php if ((string) $role['code'] === 'super_admin') { continue; } ?>
                        <option value="<?= app_h((string) $role['code']) ?>"><?= app_h($role['name']) ?> / <?= app_h((string) $role['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sablon</label>
                <select name="template_key" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($permissionTemplateSummaries as $templateKey => $template): ?>
                        <option value="<?= app_h($templateKey) ?>"><?= app_h($template['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;align-items:end;">
                <button type="submit" onclick="return confirm('Secilen rolun mevcut modul ve detayli izinleri sablon ile degistirilecek. Devam edilsin mi?');">Sablonu Uygula</button>
            </div>
        </form>
        <div class="list" style="margin-top:16px;">
            <div class="row"><div><strong style="font-size:1rem;">Not</strong><span>Sablon uygulandiginda hem modul yetkileri hem detayli islem matrisi birlikte guncellenir.</span></div><div class="warn">Toplu</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Dashboard</strong><span>Her sablonda minimum dashboard goruntuleme yetkisi korunur.</span></div><div class="ok">Sabit</div></div>
        </div>
    </div>
</section>

<section class="card">
    <h3>Son Yetki Sablonu Uygulamalari</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($permissionTemplateHistory as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$permissionTemplateHistory): ?>
                <tr><td colspan="3">Henuz yetki sablonu uygulanmadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Islem Bazli Onay Kurallari</h3>
        <p class="muted">Belirli operasyonlarda kimlerin onay verecegi, not zorunlulugu ve esik tutar kosulu buradan tanimlanir.</p>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="update_approval_rules">
            <?php foreach ($approvalRuleRows as $rule): ?>
                <div class="card">
                    <small><?= app_h($rule['label']) ?> / <?= app_h($rule['module_key']) ?></small>
                    <p class="muted" style="margin:10px 0 16px;"><?= app_h($rule['summary']) ?></p>
                    <div class="form-grid compact-form">
                        <div class="check-row">
                            <label><input type="checkbox" name="approval_rules[<?= app_h($rule['rule_key']) ?>][enabled]" value="1" <?= !empty($rule['enabled']) ? 'checked' : '' ?>> Kural aktif</label>
                        </div>
                        <div>
                            <label>Onaylayici Rol</label>
                            <select name="approval_rules[<?= app_h($rule['rule_key']) ?>][approver_role_code]">
                                <option value="">Rol kisiti yok</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= app_h((string) $role['code']) ?>" <?= (string) $rule['approver_role_code'] === (string) $role['code'] ? 'selected' : '' ?>><?= app_h($role['name']) ?> / <?= app_h((string) $role['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Esik Tutar</label>
                            <input type="number" step="0.01" min="0" name="approval_rules[<?= app_h($rule['rule_key']) ?>][min_amount]" value="<?= app_h(number_format((float) $rule['min_amount'], 2, '.', '')) ?>" placeholder="0.00">
                        </div>
                        <div class="check-row">
                            <label><input type="checkbox" name="approval_rules[<?= app_h($rule['rule_key']) ?>][require_note]" value="1" <?= !empty($rule['require_note']) ? 'checked' : '' ?>> Onay notu zorunlu</label>
                        </div>
                        <div class="check-row">
                            <label><input type="checkbox" name="approval_rules[<?= app_h($rule['rule_key']) ?>][require_second_approval]" value="1" <?= !empty($rule['require_second_approval']) ? 'checked' : '' ?>> Cift onay zorunlu</label>
                        </div>
                        <div>
                            <label>2. Onaylayici Rol</label>
                            <select name="approval_rules[<?= app_h($rule['rule_key']) ?>][second_approver_role_code]">
                                <option value="">Rol kisiti yok</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= app_h((string) $role['code']) ?>" <?= (string) $rule['second_approver_role_code'] === (string) $role['code'] ? 'selected' : '' ?>><?= app_h($role['name']) ?> / <?= app_h((string) $role['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div>
                <button type="submit">Onay Kurallarini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Onay Kural Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kural</th><th>Durum</th><th>1. Rol</th><th>Cift Onay</th><th>Esik</th><th>2. Rol / Not</th></tr></thead>
                <tbody>
                <?php foreach ($approvalRuleRows as $rule): ?>
                    <tr>
                        <td><small><?= app_h($rule['label']) ?><br><?= app_h($rule['rule_key']) ?></small></td>
                        <td><?= !empty($rule['enabled']) ? 'Aktif' : 'Pasif' ?></td>
                        <td><?= app_h((string) ($rule['approver_role_code'] !== '' ? $rule['approver_role_code'] : '-')) ?></td>
                        <td><?= !empty($rule['require_second_approval']) ? 'Evet' : 'Hayir' ?></td>
                        <td><?= (float) $rule['min_amount'] > 0 ? number_format((float) $rule['min_amount'], 2, ',', '.') . ' TRY' : '-' ?></td>
                        <td><small><?= app_h((string) ($rule['second_approver_role_code'] !== '' ? $rule['second_approver_role_code'] : '-')) ?><br><?= !empty($rule['require_note']) ? 'Not zorunlu' : 'Not opsiyonel' ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvalRuleRows): ?>
                    <tr><td colspan="6">Onay kurali tanimi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Onay Kural Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($approvalRuleHistory as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['created_at']) ?></td>
                        <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                        <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$approvalRuleHistory): ?>
                    <tr><td colspan="3">Henuz onay kurali degisikligi kaydedilmedi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yedekleme Merkezi</h3>
        <p>Sistemin mevcut tablo yapisini ve verilerini tek dosya SQL yedegi olarak indirebilirsiniz.</p>
        <div class="list">
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Tam SQL Yedegi</strong>
                    <span>Firma, kullanici, cari, stok, satis, fatura, servis ve kira verilerini kapsar.</span>
                </div>
                <div class="ok"><a href="?module=core&download_backup=sql" style="color:inherit;text-decoration:none;">Indir</a></div>
            </div>
            <div class="row">
                <div>
                    <strong style="font-size:1rem;">Son Audit Kayitlari</strong>
                    <span>Yedek alma islemleri dahil yonetim hareketleri loglanir.</span>
                </div>
                <div class="ok"><?= app_h((string) count($auditLogs)) ?> Kayit</div>
            </div>
        </div>

        <h3 style="margin-top:22px;">SQL Yedek Ice Aktarma</h3>
        <form method="post" enctype="multipart/form-data" class="form-grid compact-form">
            <input type="hidden" name="action" value="import_backup">
            <div>
                <label>SQL Dosyasi</label>
                <input type="file" name="backup_file" accept=".sql" required>
            </div>
            <div style="display:flex;align-items:end;">
                <button type="submit" onclick="return confirm('Bu islem mevcut veritabanini degistirebilir. Devam edilsin mi?');">SQL Iceri Aktar</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Yedek Icerigi</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Core</strong><span>Firma, sube, kullanici, ayar ve audit tablolari</span></div><div class="ok">Dahil</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Finans ve Cari</strong><span>Cari hareket, kasa, banka ve tahsilat kayitlari</span></div><div class="ok">Dahil</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Operasyon</strong><span>Stok, satis, fatura, servis, kira ve uretim tablolari</span></div><div class="ok">Dahil</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Medya Dosyalari</strong><span>Logo veya harici yuklemeler dosya sistemi tabanli oldugu icin SQL yedegine girmez.</span></div><div class="warn">Haric</div></div>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Rol Bazli Modul Yetkileri</h3>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="update_role_permissions">
            <?php foreach ($roles as $role): ?>
                <div class="card">
                    <small><?= app_h($role['name']) ?> / <?= app_h((string) $role['code']) ?></small>
                    <div class="module-grid">
                        <?php foreach ($moduleDefinitions as $moduleKey => $module): ?>
                            <label class="check-row" style="justify-content:flex-start; gap:8px;">
                                <input
                                    type="checkbox"
                                    name="permissions[<?= app_h((string) $role['code']) ?>][]"
                                    value="<?= app_h($moduleKey) ?>"
                                    <?= in_array($moduleKey, $roleModuleMap[(string) $role['code']] ?? [], true) ? 'checked' : '' ?>
                                    <?= (string) $role['code'] === 'super_admin' ? 'disabled' : '' ?>
                                >
                                <span><?= app_h($module['title']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div>
                <button type="submit">Yetkileri Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Audit Loglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Modul</th><th>Islem</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($auditLogs as $log): ?>
                    <tr>
                        <td><?= app_h($log['created_at']) ?></td>
                        <td><?= app_h((string) ($log['full_name'] ?: '-')) ?></td>
                        <td><?= app_h($log['module_name']) ?></td>
                        <td><?= app_h($log['action_name']) ?></td>
                        <td><?= app_h((string) ($log['description'] ?: (($log['record_table'] ?: '-') . ' #' . ($log['record_id'] ?: '-')))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Departman Bazli Modul Yetkileri</h3>
        <p class="muted">Departmana bagli kullanicilar icin rol yetkisine ek olarak ikinci bir modul filtresi uygulanir.</p>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="update_department_permissions">
            <?php foreach ($departments as $department): ?>
                <?php $departmentId = (int) ($department['id'] ?? 0); ?>
                <div class="card">
                    <small><?= app_h((string) $department['name']) ?> / Departman #<?= $departmentId ?></small>
                    <div class="module-grid">
                        <?php foreach ($moduleDefinitions as $moduleKey => $module): ?>
                            <?php if ($moduleKey === 'dashboard') { continue; } ?>
                            <label class="check-row" style="justify-content:flex-start; gap:8px;">
                                <input
                                    type="checkbox"
                                    name="department_permissions[<?= $departmentId ?>][]"
                                    value="<?= app_h($moduleKey) ?>"
                                    <?= in_array($moduleKey, $departmentModuleMap[$departmentId] ?? [], true) ? 'checked' : '' ?>
                                >
                                <span><?= app_h($module['title']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$departments): ?>
                <div class="row"><div><strong style="font-size:1rem;">Departman bulunamadi</strong><span>IK modulunden once departman tanimi yapin.</span></div><div class="warn">Bekliyor</div></div>
            <?php endif; ?>
            <div>
                <button type="submit">Departman Modul Yetkilerini Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Departman Yetki Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Departman</th><th>Modul</th><th>Islem</th><th>Bagli Kullanici</th><th>Kullanicilar</th></tr></thead>
                <tbody>
                <?php foreach ($departmentPermissionSummaryRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['name']) ?><br>#<?= (int) $row['id'] ?></small></td>
                        <td><?= (int) $row['module_count'] ?></td>
                        <td><?= (int) $row['action_count'] ?></td>
                        <td><small><?= (int) $row['linked_user_count'] ?> kullanici<br><?= (int) $row['employee_count'] ?> personel</small></td>
                        <td><small><?= app_h($row['linked_users'] !== '' ? $row['linked_users'] : '-') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$departmentPermissionSummaryRows): ?>
                    <tr><td colspan="5">Henuz departman yetki kaydi bulunmuyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Detayli Yetki Matrisi</h3>
    <p class="muted">Rol bazinda her modul icin goruntuleme, ekleme, duzenleme, silme, onay, disa aktarma ve ayar izinleri saklanir.</p>
    <form method="post">
        <input type="hidden" name="action" value="update_permission_matrix">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Rol</th>
                        <th>Modul</th>
                        <?php foreach ($permissionActions as $actionKey => $actionLabel): ?>
                            <th><?= app_h($actionLabel) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php $roleCode = (string) $role['code']; ?>
                    <?php foreach ($moduleDefinitions as $moduleKey => $module): ?>
                        <?php $enabledActions = $roleActionMatrix[$roleCode][$moduleKey] ?? []; ?>
                        <tr>
                            <td><small><?= app_h($role['name']) ?><br><?= app_h($roleCode) ?></small></td>
                            <td><small><?= app_h($module['title']) ?><br><?= app_h($moduleKey) ?></small></td>
                            <?php foreach ($permissionActions as $actionKey => $actionLabel): ?>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        name="permission_matrix[<?= app_h($roleCode) ?>][<?= app_h($moduleKey) ?>][]"
                                        value="<?= app_h($actionKey) ?>"
                                        <?= in_array($actionKey, $enabledActions, true) ? 'checked' : '' ?>
                                        <?= $roleCode === 'super_admin' ? 'disabled' : '' ?>
                                    >
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            <button type="submit">Detayli Yetki Matrisini Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Departman Bazli Detayli Yetki Matrisi</h3>
    <p class="muted">Departmana bagli kullanicilarin islem bazli izinleri burada tutulur. Rol matrisi ile birlikte degerlendirilir.</p>
    <form method="post">
        <input type="hidden" name="action" value="update_department_permission_matrix">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Departman</th>
                        <th>Modul</th>
                        <?php foreach ($permissionActions as $actionKey => $actionLabel): ?>
                            <th><?= app_h($actionLabel) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $department): ?>
                    <?php $departmentId = (int) ($department['id'] ?? 0); ?>
                    <?php foreach ($moduleDefinitions as $moduleKey => $module): ?>
                        <?php if ($moduleKey === 'dashboard') { continue; } ?>
                        <?php $enabledActions = $departmentActionMatrix[$departmentId][$moduleKey] ?? []; ?>
                        <tr>
                            <td><small><?= app_h((string) $department['name']) ?><br>#<?= $departmentId ?></small></td>
                            <td><small><?= app_h($module['title']) ?><br><?= app_h($moduleKey) ?></small></td>
                            <?php foreach ($permissionActions as $actionKey => $actionLabel): ?>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        name="department_permission_matrix[<?= $departmentId ?>][<?= app_h($moduleKey) ?>][]"
                                        value="<?= app_h($actionKey) ?>"
                                        <?= in_array($actionKey, $enabledActions, true) ? 'checked' : '' ?>
                                    >
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (!$departments): ?>
                    <tr><td colspan="<?= 2 + count($permissionActions) ?>">Departman kaydi olmadigi icin detayli departman matrisi olusturulamiyor.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px;">
            <button type="submit">Departman Detayli Yetki Matrisini Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Departman Yetki Gecmisi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kullanici</th><th>Islem</th><th>Aciklama</th></tr></thead>
            <tbody>
            <?php foreach ($departmentPermissionHistory as $row): ?>
                <tr>
                    <td><?= app_h((string) $row['created_at']) ?></td>
                    <td><small><?= app_h((string) $row['full_name']) ?><br><?= app_h((string) $row['email']) ?></small></td>
                    <td><?= app_h((string) $row['action_name']) ?></td>
                    <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$departmentPermissionHistory): ?>
                <tr><td colspan="4">Henuz departman yetki islemi kaydedilmedi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Bazli Hedef Takibi</h3>
        <p class="muted">Sube bazinda hedef tanimlayip gerceklesen fatura, siparis, servis, kira ve performans skoruyla kiyaslayin.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_branch_target_goal">
            <div>
                <label>Sube</label>
                <select name="branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Donem</label>
                <input type="month" name="target_period" value="<?= app_h(date('Y-m')) ?>">
            </div>
            <div><label>Fatura Hedefi</label><input type="number" step="0.01" min="0" name="goal[invoice_total]" value="0"></div>
            <div><label>Siparis Hedefi</label><input type="number" step="0.01" min="0" name="goal[order_total]" value="0"></div>
            <div><label>Kira Hedefi</label><input type="number" step="0.01" min="0" name="goal[rental_monthly_total]" value="0"></div>
            <div><label>Kapanan Servis</label><input type="number" step="1" min="0" name="goal[closed_service_count]" value="0"></div>
            <div><label>Performans Skoru</label><input type="number" step="0.1" min="0" name="goal[total_score]" value="0"></div>
            <div class="full">
                <label>Hedef Notu</label>
                <textarea name="target_note" rows="3" placeholder="Donem aciklamasi, sube kampanyasi veya yonetsel beklenti notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Sube Hedefini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Hedef Ozeti</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Hedefte Sube</strong><span>Ortalama hedef gerceklesme orani %100 ve uzeri olan subeler.</span></div><div class="ok"><?= (int) $branchTargetOverview['on_track_count'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Takipte Sube</strong><span>Ortalama hedef oraninda izleme gerektiren subeler.</span></div><div class="warn"><?= (int) $branchTargetOverview['watch_count'] ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Riskli Sube</strong><span>Hedeflerinden belirgin sapma gosteren subeler.</span></div><div class="warn"><?= (int) $branchTargetOverview['risk_count'] ?></div></div>
        </div>
    </div>

    <div class="card">
        <h3>Sube Hedef Skor Kartlari</h3>
        <div class="list">
            <?php foreach (array_slice($branchTargetRows, 0, 6) as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h($row['branch_name']) ?></strong>
                        <span><?= app_h($row['firm_name']) ?> / <?= app_h($row['target_period']) ?> / <?= app_h($row['status']) ?></span>
                    </div>
                    <div class="<?= app_h($row['status_tone']) ?>">%<?= app_h(number_format((float) $row['average_ratio'], 1, ',', '.')) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$branchTargetRows): ?>
                <div class="row"><div><strong style="font-size:1rem;">Hedef plani yok</strong><span>Hedef kayitlari olustukca burada skor kartlari gorunecek.</span></div><div class="warn">Bos</div></div>
            <?php endif; ?>
        </div>

        <h3 style="margin-top:22px;">Son Hedef Loglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Sube</th><th>Kullanici</th><th>Ozet</th></tr></thead>
                <tbody>
                <?php foreach ($branchTargetHistory as $historyRow): ?>
                    <tr>
                        <td><?= app_h($historyRow['created_at']) ?></td>
                        <td><small><?= app_h($historyRow['firm_name']) ?><br><?= app_h($historyRow['branch_name']) ?></small></td>
                        <td><?= app_h($historyRow['full_name']) ?></td>
                        <td><small><?= app_h((string) ($historyRow['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchTargetHistory): ?>
                    <tr><td colspan="4">Henuz sube hedef logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Sube Hedef Karsilastirma Tablosu</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Sube</th><th>Durum</th><th>Fatura</th><th>Siparis</th><th>Servis</th><th>Kira</th><th>Skor</th></tr></thead>
            <tbody>
            <?php foreach ($branchTargetRows as $row): ?>
                <?php $invoiceGoal = $row['goal_details']['invoice_total'] ?? ['goal' => 0, 'actual' => 0, 'ratio' => null]; ?>
                <?php $orderGoal = $row['goal_details']['order_total'] ?? ['goal' => 0, 'actual' => 0, 'ratio' => null]; ?>
                <?php $serviceGoal = $row['goal_details']['closed_service_count'] ?? ['goal' => 0, 'actual' => 0, 'ratio' => null]; ?>
                <?php $rentalGoal = $row['goal_details']['rental_monthly_total'] ?? ['goal' => 0, 'actual' => 0, 'ratio' => null]; ?>
                <?php $scoreGoal = $row['goal_details']['total_score'] ?? ['goal' => 0, 'actual' => 0, 'ratio' => null]; ?>
                <tr>
                    <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['branch_name']) ?> / <?= app_h($row['target_period']) ?></small></td>
                    <td><small><span class="<?= app_h($row['status_tone']) ?>"><?= app_h($row['status']) ?></span><br>Ort. %<?= app_h(number_format((float) $row['average_ratio'], 1, ',', '.')) ?><br><?= app_h($row['updated_by_name']) ?></small></td>
                    <td><small>G: <?= app_h(number_format((float) $invoiceGoal['goal'], 2, ',', '.')) ?><br>A: <?= app_h(number_format((float) $invoiceGoal['actual'], 2, ',', '.')) ?><br>%<?= app_h(number_format((float) ($invoiceGoal['ratio'] ?? 0), 1, ',', '.')) ?></small></td>
                    <td><small>G: <?= app_h(number_format((float) $orderGoal['goal'], 2, ',', '.')) ?><br>A: <?= app_h(number_format((float) $orderGoal['actual'], 2, ',', '.')) ?><br>%<?= app_h(number_format((float) ($orderGoal['ratio'] ?? 0), 1, ',', '.')) ?></small></td>
                    <td><small>G: <?= app_h(number_format((float) $serviceGoal['goal'], 0, ',', '.')) ?><br>A: <?= app_h(number_format((float) $serviceGoal['actual'], 0, ',', '.')) ?><br>%<?= app_h(number_format((float) ($serviceGoal['ratio'] ?? 0), 1, ',', '.')) ?></small></td>
                    <td><small>G: <?= app_h(number_format((float) $rentalGoal['goal'], 2, ',', '.')) ?><br>A: <?= app_h(number_format((float) $rentalGoal['actual'], 2, ',', '.')) ?><br>%<?= app_h(number_format((float) ($rentalGoal['ratio'] ?? 0), 1, ',', '.')) ?></small></td>
                    <td><small>G: <?= app_h(number_format((float) $scoreGoal['goal'], 1, ',', '.')) ?><br>A: <?= app_h(number_format((float) $scoreGoal['actual'], 1, ',', '.')) ?><br>%<?= app_h(number_format((float) ($scoreGoal['ratio'] ?? 0), 1, ',', '.')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branchTargetRows): ?>
                <tr><td colspan="7">Sube hedef karsilastirma verisi bulunamadi.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Subeler Arasi Transfer Onayi</h3>
        <p class="muted">Kasa, banka, depo veya operasyon transferlerini talep olarak acip onay durumunu merkezden yonetin.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_branch_transfer_request">
            <div>
                <label>Kaynak Sube</label>
                <select name="source_branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hedef Sube</label>
                <select name="target_branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Transfer Turu</label>
                <select name="transfer_type">
                    <option value="kasa">Kasa</option>
                    <option value="banka">Banka</option>
                    <option value="depo">Depo</option>
                    <option value="operasyon">Operasyon</option>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input type="number" step="0.01" min="0" name="amount" value="0" required>
            </div>
            <div>
                <label>Kaynak Varlik</label>
                <input name="resource_label" placeholder="Kasa adi, banka hesabi, depo veya operasyon kalemi">
            </div>
            <div>
                <label>Onaylayici</label>
                <select name="approver_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($users as $user): ?>
                        <?php if ((int) ($user['status'] ?? 0) !== 1) { continue; } ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name']) ?> / <?= app_h((string) ($user['role_name'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Transfer Notu</label>
                <textarea name="note" rows="3" placeholder="Transfer nedeni, aciliyet, referans numarasi veya operasyon aciklamasi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Transfer Onay Talebi Ac</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Sube Kaynak Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sube</th><th>Kasa</th><th>Banka</th><th>Depo</th></tr></thead>
                <tbody>
                <?php foreach ($branches as $branch): ?>
                    <?php $branchId = (int) ($branch['id'] ?? 0); ?>
                    <tr>
                        <td><?= app_h(($firmNameMap[(int) ($branch['firm_id'] ?? 0)] ?? '-') . ' / ' . ($branch['name'] ?? '-')) ?></td>
                        <td><small><?= app_h(implode(', ', array_slice($cashboxBranchMap[$branchId] ?? ['-'], 0, 2))) ?></small></td>
                        <td><small><?= app_h(implode(', ', array_slice($bankBranchMap[$branchId] ?? ['-'], 0, 2))) ?></small></td>
                        <td><small><?= app_h(implode(', ', array_slice($warehouseBranchMap[$branchId] ?? ['-'], 0, 2))) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Bekleyen Transfer Onaylari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Talep</th><th>Kaynak/Hedef</th><th>Detay</th><th>Onaylayici</th><th>Karar</th></tr></thead>
                <tbody>
                <?php foreach ($pendingBranchTransferRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['request_id']) ?><br><?= app_h($row['requested_at']) ?><br><?= app_h($row['requested_by_name']) ?></small></td>
                        <td><small><?= app_h($row['source_label']) ?><br><?= app_h($row['target_label']) ?></small></td>
                        <td><small><?= app_h(ucfirst($row['transfer_type'])) ?> / <?= app_h(number_format((float) $row['amount'], 2, ',', '.')) ?><br><?= app_h($row['resource_label'] !== '' ? $row['resource_label'] : '-') ?><br><?= app_h($row['note'] !== '' ? $row['note'] : '-') ?></small></td>
                        <td><?= app_h($row['approver_name']) ?></td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="decide_branch_transfer_request">
                                <input type="hidden" name="request_id" value="<?= app_h($row['request_id']) ?>">
                                <select name="decision">
                                    <option value="onaylandi">Onayla</option>
                                    <option value="reddedildi">Reddet</option>
                                </select>
                                <input type="text" name="decision_note" placeholder="Karar notu">
                                <button type="submit">Kaydet</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pendingBranchTransferRows): ?>
                    <tr><td colspan="5">Bekleyen subeler arasi transfer onayi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Transfer Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Talep</th><th>Kaynak/Hedef</th><th>Karar</th></tr></thead>
                <tbody>
                <?php foreach ($branchTransferRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['status']) ?><br><?= app_h(number_format((float) $row['amount'], 2, ',', '.')) ?></small></td>
                        <td><small><?= app_h($row['request_id']) ?><br><?= app_h($row['requested_at']) ?><br><?= app_h($row['requested_by_name']) ?></small></td>
                        <td><small><?= app_h($row['source_label']) ?><br><?= app_h($row['target_label']) ?><br><?= app_h(ucfirst($row['transfer_type'])) ?> / <?= app_h($row['resource_label'] !== '' ? $row['resource_label'] : '-') ?></small></td>
                        <td><small><?= app_h($row['decision_by_name'] !== '' ? $row['decision_by_name'] : '-') ?><br><?= app_h($row['decision_at'] !== '' ? $row['decision_at'] : '-') ?><br><?= app_h($row['decision_note'] !== '' ? $row['decision_note'] : '-') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchTransferRows): ?>
                    <tr><td colspan="4">Transfer onay gecmisi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Firma Ici Ic Hizmet Faturasi</h3>
        <p class="muted">Kaynak subeden hedef subeye ayni anda satis ve alis kaydi ureterek ic hizmet yansitmasi olusturun.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_internal_service_invoice">
            <div>
                <label>Kaynak Sube</label>
                <select name="source_branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hedef Sube</label>
                <select name="target_branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Fatura Tarihi</label>
                <input type="date" name="invoice_date" value="<?= app_h(date('Y-m-d')) ?>">
            </div>
            <div>
                <label>Vade Tarihi</label>
                <input type="date" name="due_date" value="<?= app_h(date('Y-m-d')) ?>">
            </div>
            <div>
                <label>Hizmet Basligi</label>
                <input name="service_title" value="Ic Hizmet" placeholder="Merkez destek, teknik hizmet, operasyon destegi">
            </div>
            <div>
                <label>Para Birimi</label>
                <input name="currency_code" value="TRY">
            </div>
            <div>
                <label>Miktar</label>
                <input type="number" step="0.001" min="0.001" name="quantity" value="1">
            </div>
            <div>
                <label>Birim Fiyat</label>
                <input type="number" step="0.01" min="0" name="unit_price" value="0">
            </div>
            <div>
                <label>KDV</label>
                <input type="number" step="0.01" min="0" name="vat_rate" value="20">
            </div>
            <div class="full">
                <label>Aciklama</label>
                <textarea name="description" rows="3" placeholder="Yansitmanin nedeni, hizmet kapsamı ve donem aciklamasi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Ic Hizmet Faturasini Olustur</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Ic Hizmet Ozeti</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Olusan Kayit</strong><span>Ic hizmet faturasi olusturma aksiyonu sayisi.</span></div><div class="ok"><?= app_h((string) $summary['Ic Hizmet Faturasi']) ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Toplam Satis Tutari</strong><span>Kaynak subelerden uretilen ic hizmet satis faturalarinin toplami.</span></div><div class="ok"><?= app_h((string) $summary['Ic Hizmet Tutar']) ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Cari Eslesme</strong><span>Hedef ve kaynak sube icin otomatik ic hizmet cari karti uretilir.</span></div><div class="ok">Hazir</div></div>
        </div>
    </div>

    <div class="card">
        <h3>Son Ic Hizmet Faturalari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fatura</th><th>Sube</th><th>Cari</th><th>Tutar</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($internalServiceInvoiceRows as $row): ?>
                    <tr>
                        <td><small><?= app_h((string) $row['invoice_no']) ?><br><?= app_h((string) $row['invoice_type']) ?> / <?= app_h((string) $row['invoice_date']) ?></small></td>
                        <td><small><?= app_h((string) $row['firm_name']) ?><br><?= app_h((string) $row['branch_name']) ?></small></td>
                        <td><?= app_h((string) $row['cari_name']) ?></td>
                        <td><small>Ara toplam <?= app_h(number_format((float) $row['subtotal'], 2, ',', '.')) ?><br>KDV <?= app_h(number_format((float) $row['vat_total'], 2, ',', '.')) ?><br>Genel toplam <?= app_h(number_format((float) $row['grand_total'], 2, ',', '.')) ?> <?= app_h((string) ($row['currency_code'] ?: 'TRY')) ?></small></td>
                        <td><small><?= app_h((string) ($row['notes'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$internalServiceInvoiceRows): ?>
                    <tr><td colspan="5">Ic hizmet faturasi kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 style="margin-top:22px;">Ic Hizmet Fatura Loglari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Kullanici</th><th>Ozet</th></tr></thead>
                <tbody>
                <?php foreach ($internalServiceAuditRows as $row): ?>
                    <tr>
                        <td><?= app_h((string) $row['created_at']) ?></td>
                        <td><?= app_h((string) $row['full_name']) ?></td>
                        <td><small><?= app_h((string) ($row['description'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$internalServiceAuditRows): ?>
                    <tr><td colspan="3">Henuz ic hizmet faturasi logu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Firma Bazli KPI Ekrani</h3>
        <p class="muted">Sube skorlarinin firma seviyesinde toplanmis gorunumu ile yonetim KPI ekranı olusur.</p>
        <div class="list">
            <?php foreach (array_slice($firmKpiRows, 0, 4) as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h($row['firm_name']) ?></strong>
                        <span>Not: <?= app_h($row['grade']) ?> / Ortalama sube skoru <?= app_h(number_format((float) $row['avg_branch_score'], 1, ',', '.')) ?> / Aktif sube <?= (int) $row['active_branch_count'] ?></span>
                    </div>
                    <div class="<?= (float) $row['total_score'] >= 75 ? 'ok' : 'warn' ?>"><?= app_h(number_format((float) $row['total_score'], 1, ',', '.')) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$firmKpiRows): ?>
                <div class="row"><div><strong style="font-size:1rem;">Firma KPI hazir degil</strong><span>KPI olusturmak icin firma ve sube verisi gerekli.</span></div><div class="warn">Bos</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Firma KPI Dagilimi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Firma</th><th>Toplam KPI</th><th>Sube / Kullanici</th><th>Fatura / Siparis</th><th>Servis / Kira</th></tr></thead>
                <tbody>
                <?php foreach ($firmKpiRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['grade']) ?> / Ortalama <?= app_h(number_format((float) $row['avg_branch_score'], 1, ',', '.')) ?></small></td>
                        <td><?= app_h(number_format((float) $row['total_score'], 1, ',', '.')) ?></td>
                        <td><small>Sube: <?= (int) $row['branch_count'] ?><br>Aktif: <?= (int) $row['active_branch_count'] ?> / Kullanici: <?= (int) $row['user_count'] ?></small></td>
                        <td><small>Fatura: <?= app_h(number_format((float) $row['invoice_total'], 2, ',', '.')) ?><br>Siparis: <?= app_h(number_format((float) $row['order_total'], 2, ',', '.')) ?></small></td>
                        <td><small>Acik/Kapanan servis: <?= (int) $row['open_service_count'] ?>/<?= (int) $row['closed_service_count'] ?><br>Kira: <?= app_h(number_format((float) $row['rental_total'], 2, ',', '.')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$firmKpiRows): ?>
                    <tr><td colspan="5">Firma KPI verisi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Performans Puan Karti</h3>
        <p class="muted">Satis, fatura, servis, kira ve kullanici aktivitesi birlikte degerlendirilerek sube bazli skor uretilir.</p>
        <div class="list">
            <?php foreach (array_slice($branchPerformanceRows, 0, 5) as $row): ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h($row['branch_name']) ?></strong>
                        <span><?= app_h($row['firm_name']) ?> / Not: <?= app_h($row['grade']) ?> / Satis <?= app_h(number_format((float) $row['sales_score'], 1, ',', '.')) ?> / Servis <?= app_h(number_format((float) $row['service_score'], 1, ',', '.')) ?></span>
                    </div>
                    <div class="<?= (float) $row['total_score'] >= 60 ? 'ok' : 'warn' ?>"><?= app_h(number_format((float) $row['total_score'], 1, ',', '.')) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$branchPerformanceRows): ?>
                <div class="row"><div><strong style="font-size:1rem;">Puan karti hazir degil</strong><span>Skor hesaplamak icin en az bir sube ve operasyon verisi gerekli.</span></div><div class="warn">Bos</div></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Performans Dagilimi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sube</th><th>Toplam Skor</th><th>Satis</th><th>Servis</th><th>Kira</th><th>Aktivite</th></tr></thead>
                <tbody>
                <?php foreach ($branchPerformanceRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['branch_name']) ?> / <?= app_h($row['grade']) ?></small></td>
                        <td><?= app_h(number_format((float) $row['total_score'], 1, ',', '.')) ?></td>
                        <td><small>Teklif: <?= (int) $row['approved_offer_count'] ?><br>Fatura: <?= app_h(number_format((float) $row['invoice_total'], 2, ',', '.')) ?></small></td>
                        <td><small>Acik: <?= (int) $row['open_service_count'] ?><br>Kapanan: <?= (int) $row['closed_service_count'] ?></small></td>
                        <td><small>Aktif: <?= (int) $row['active_rental_count'] ?><br>Aylik: <?= app_h(number_format((float) $row['rental_monthly_total'], 2, ',', '.')) ?></small></td>
                        <td><small>Kullanici: <?= (int) $row['user_count'] ?><br>30g giris: <?= (int) $row['login_30d'] ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchPerformanceRows): ?>
                    <tr><td colspan="6">Sube performans verisi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Coklu Firma Merkezi</h3>
        <p class="muted">Birden fazla firma kaydi, sube baglantilari ve aktiflik durumu buradan yonetilir.</p>
        <form method="post" class="form-grid compact-form">
            <input type="hidden" name="action" value="create_firm">
            <div><label>Firma Unvani</label><input name="company_name" placeholder="Yeni Firma A.S." required></div>
            <div><label>Ticari Ad</label><input name="trade_name" placeholder="Marka / kisa ad"></div>
            <div><label>Vergi Dairesi</label><input name="tax_office"></div>
            <div><label>Vergi No</label><input name="tax_number"></div>
            <div><label>Mersis No</label><input name="mersis_no"></div>
            <div><label>Telefon</label><input name="phone"></div>
            <div><label>E-posta</label><input name="email" type="email"></div>
            <div><label>Web Sitesi</label><input name="website"></div>
            <div><label>Sehir</label><input name="city"></div>
            <div><label>Ilce</label><input name="district"></div>
            <div><label>Ulke</label><input name="country" value="Turkiye"></div>
            <div class="check-row"><label><input type="checkbox" name="status" value="1" checked> Aktif</label></div>
            <div class="full"><label>Adres</label><textarea name="address" rows="2"></textarea></div>
            <div class="full"><button type="submit">Firma Ekle</button></div>
        </form>
    </div>

    <div class="card">
        <h3>Firma Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Firma</th><th>Iletisim</th><th>Sube</th><th>Kullanici</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($firmRows as $firmRow): ?>
                    <?php $stat = $firmStats[(int) $firmRow['id']] ?? ['branch_count' => 0, 'user_count' => 0]; ?>
                    <tr>
                        <td><small><?= app_h((string) $firmRow['company_name']) ?><br><?= app_h((string) ($firmRow['tax_number'] ?: $firmRow['trade_name'] ?: '-')) ?></small></td>
                        <td><small><?= app_h((string) ($firmRow['phone'] ?: '-')) ?><br><?= app_h((string) ($firmRow['email'] ?: '-')) ?></small></td>
                        <td><?= (int) $stat['branch_count'] ?></td>
                        <td><?= (int) $stat['user_count'] ?></td>
                        <td><?= (int) $firmRow['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="update_firm_status">
                                <input type="hidden" name="firm_id" value="<?= (int) $firmRow['id'] ?>">
                                <input type="hidden" name="status" value="<?= (int) $firmRow['status'] === 1 ? 0 : 1 ?>">
                                <button type="submit"><?= (int) $firmRow['status'] === 1 ? 'Pasife Al' : 'Aktif Et' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$firmRows): ?>
                    <tr><td colspan="6">Firma kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php if ($firm): ?>
<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Ana Firma Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_firm">
            <input type="hidden" name="firm_id" value="<?= (int) $firm['id'] ?>">
            <div>
                <label>Firma Unvani</label>
                <input name="company_name" value="<?= app_h((string) $firm['company_name']) ?>" required>
            </div>
            <div>
                <label>Ticari Ad</label>
                <input name="trade_name" value="<?= app_h((string) ($firm['trade_name'] ?? '')) ?>">
            </div>
            <div>
                <label>Vergi Dairesi</label>
                <input name="tax_office" value="<?= app_h((string) ($firm['tax_office'] ?? '')) ?>">
            </div>
            <div>
                <label>Vergi No</label>
                <input name="tax_number" value="<?= app_h((string) ($firm['tax_number'] ?? '')) ?>">
            </div>
            <div>
                <label>Mersis No</label>
                <input name="mersis_no" value="<?= app_h((string) ($firm['mersis_no'] ?? '')) ?>">
            </div>
            <div>
                <label>Telefon</label>
                <input name="phone" value="<?= app_h((string) ($firm['phone'] ?? '')) ?>">
            </div>
            <div>
                <label>E-posta</label>
                <input name="email" type="email" value="<?= app_h((string) ($firm['email'] ?? '')) ?>">
            </div>
            <div>
                <label>Web Sitesi</label>
                <input name="website" value="<?= app_h((string) ($firm['website'] ?? '')) ?>">
            </div>
            <div>
                <label>Sehir</label>
                <input name="city" value="<?= app_h((string) ($firm['city'] ?? '')) ?>">
            </div>
            <div>
                <label>Ilce</label>
                <input name="district" value="<?= app_h((string) ($firm['district'] ?? '')) ?>">
            </div>
            <div>
                <label>Ulke</label>
                <input name="country" value="<?= app_h((string) ($firm['country'] ?? 'Turkiye')) ?>">
            </div>
            <div>
                <label>Logo Yolu</label>
                <input name="logo_path" value="<?= app_h((string) ($firm['logo_path'] ?? '')) ?>" placeholder="/uploads/logo.png">
            </div>
            <div class="full">
                <?php if (!empty($firm['logo_path'])): ?>
                    <div class="row" style="align-items:center;">
                        <div>
                            <strong style="font-size:1rem;">Mevcut Logo</strong>
                            <span><?= app_h((string) $firm['logo_path']) ?></span>
                        </div>
                        <div class="ok"><a href="<?= app_h((string) $firm['logo_path']) ?>" target="_blank" style="color:inherit;text-decoration:none;">Gor</a></div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="full">
                <label>Adres</label>
                <textarea name="address" rows="3"><?= app_h((string) ($firm['address'] ?? '')) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">Firma Bilgilerini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Logo Yukle</h3>
        <form method="post" enctype="multipart/form-data" class="form-grid compact-form">
            <input type="hidden" name="action" value="upload_logo">
            <input type="hidden" name="firm_id" value="<?= (int) $firm['id'] ?>">
            <div>
                <label>Logo Dosyasi</label>
                <input type="file" name="logo_file" accept=".jpg,.jpeg,.png,.gif,.webp" required>
            </div>
            <div style="display:flex;align-items:end;">
                <button type="submit">Logo Yukle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Genel Sistem Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_general_settings">
            <div>
                <label>Varsayilan Para Birimi</label>
                <input name="currency_default" value="<?= app_h($settings['currency_default']) ?>">
            </div>
            <div>
                <label>Varsayilan KDV</label>
                <input name="tax_default_vat" value="<?= app_h($settings['tax_default_vat']) ?>">
            </div>
            <div>
                <label>Tema</label>
                <input name="theme_default" value="<?= app_h($settings['theme_default']) ?>">
            </div>
            <div>
                <label>Panel Basligi</label>
                <input name="panel_title" value="<?= app_h($settings['panel_title']) ?>">
            </div>
            <div class="full">
                <button type="submit">Genel Ayarlari Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Belge Numara Serileri</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_document_prefixes">
            <div>
                <label>Seri Sayaç Kapsami</label>
                <select name="series_scope">
                    <option value="global" <?= $settings['series_scope'] === 'global' ? 'selected' : '' ?>>Global</option>
                    <option value="firm" <?= $settings['series_scope'] === 'firm' ? 'selected' : '' ?>>Firma Bazli</option>
                    <option value="branch" <?= $settings['series_scope'] === 'branch' ? 'selected' : '' ?>>Sube Bazli</option>
                    <option value="firm_branch" <?= $settings['series_scope'] === 'firm_branch' ? 'selected' : '' ?>>Firma + Sube Bazli</option>
                </select>
            </div>
            <div><label>Teklif Seri</label><input name="offer_series" value="<?= app_h($settings['offer_series']) ?>"></div>
            <div><label>Siparis Seri</label><input name="order_series" value="<?= app_h($settings['order_series']) ?>"></div>
            <div><label>Satis Fatura</label><input name="invoice_sales_series" value="<?= app_h($settings['invoice_sales_series']) ?>"></div>
            <div><label>Alis Fatura</label><input name="invoice_purchase_series" value="<?= app_h($settings['invoice_purchase_series']) ?>"></div>
            <div><label>Iade Fatura</label><input name="invoice_return_series" value="<?= app_h($settings['invoice_return_series']) ?>"></div>
            <div><label>Sevk Seri</label><input name="shipment_series" value="<?= app_h($settings['shipment_series']) ?>"></div>
            <div><label>Irsaliye Seri</label><input name="dispatch_series" value="<?= app_h($settings['dispatch_series']) ?>"></div>
            <div><label>Servis Seri</label><input name="service_series" value="<?= app_h($settings['service_series']) ?>"></div>
            <div><label>Kira Seri</label><input name="rental_series" value="<?= app_h($settings['rental_series']) ?>"></div>
            <div><label>Stok SKU Seri</label><input name="stock_series" value="<?= app_h($settings['stock_series']) ?>"></div>
            <div><label>Teklif On Ek</label><input name="offer_prefix" value="<?= app_h($settings['offer_prefix']) ?>"></div>
            <div><label>Siparis On Ek</label><input name="order_prefix" value="<?= app_h($settings['order_prefix']) ?>"></div>
            <div><label>Fatura On Ek</label><input name="invoice_prefix" value="<?= app_h($settings['invoice_prefix']) ?>"></div>
            <div><label>Servis On Ek</label><input name="service_prefix" value="<?= app_h($settings['service_prefix']) ?>"></div>
            <div><label>Kira On Ek</label><input name="rental_prefix" value="<?= app_h($settings['rental_prefix']) ?>"></div>
            <div><label>Stok On Ek</label><input name="stock_prefix" value="<?= app_h($settings['stock_prefix']) ?>"></div>
            <div class="full"><small>Kullanilabilir kaliplar: {YYYY}, {YY}, {MM}, {DD}, {FIRM}, {BRANCH}, {SEQ}, {SEQ6}</small></div>
            <div class="full">
                <button type="submit">Belge Serilerini Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Firma/Sube Seri Onizleme</h3>
        <div class="list">
            <?php foreach (array_slice($branches, 0, 4) as $branch): ?>
                <?php $seriesContext = app_document_series_context($db, ['branch_id' => (int) $branch['id'], 'firm_id' => (int) $branch['firm_id']]); ?>
                <div class="row">
                    <div>
                        <strong style="font-size:1rem;"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></strong>
                        <span>Ornek: <?= app_h(app_series_format((string) $settings['offer_series'], 1, new DateTimeImmutable(), $seriesContext)) ?></span>
                    </div>
                    <div class="ok"><?= app_h($seriesContext['firm_code'] . '-' . $seriesContext['branch_code']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$branches): ?>
                <div class="row"><div><strong style="font-size:1rem;">Sube yok</strong><span>Onizleme icin once sube ekleyin.</span></div><div class="warn">Bos</div></div>
            <?php endif; ?>
        </div>

        <h3 style="margin-top:22px;">Fatura Sablon Editoru</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_invoice_print_template">
            <div>
                <label>Baslik</label>
                <input name="invoice_print_title" value="<?= app_h($settings['invoice_print_title']) ?>">
            </div>
            <div>
                <label>Alt Baslik</label>
                <input name="invoice_print_subtitle" value="<?= app_h($settings['invoice_print_subtitle']) ?>">
            </div>
            <div>
                <label>Vurgu Rengi</label>
                <input name="invoice_print_accent" value="<?= app_h($settings['invoice_print_accent']) ?>" placeholder="#9a3412">
            </div>
            <div>
                <label>Not Basligi</label>
                <input name="invoice_print_notes_title" value="<?= app_h($settings['invoice_print_notes_title']) ?>">
            </div>
            <div class="full">
                <label>Dipnot</label>
                <textarea name="invoice_print_footer" rows="3"><?= app_h($settings['invoice_print_footer']) ?></textarea>
            </div>
            <div class="full">
                <button type="submit">Fatura Sablonunu Kaydet</button>
            </div>
        </form>

        <h3 style="margin-top:22px;">Sube Bazli Belge Tasarimi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_branch_document_template">
            <div>
                <label>Sube</label>
                <select name="branch_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h(($firmNameMap[(int) $branch['firm_id']] ?? '-') . ' / ' . $branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Baslik</label>
                <input name="invoice_print_title" placeholder="<?= app_h($settings['invoice_print_title']) ?>">
            </div>
            <div>
                <label>Alt Baslik</label>
                <input name="invoice_print_subtitle" placeholder="<?= app_h($settings['invoice_print_subtitle']) ?>">
            </div>
            <div>
                <label>Vurgu Rengi</label>
                <input name="invoice_print_accent" placeholder="<?= app_h($settings['invoice_print_accent']) ?>">
            </div>
            <div>
                <label>Not Basligi</label>
                <input name="invoice_print_notes_title" placeholder="<?= app_h($settings['invoice_print_notes_title']) ?>">
            </div>
            <div>
                <label>Belge Logo Yolu</label>
                <input name="document_logo_path" placeholder="/uploads/logos/sube-logo.png">
            </div>
            <div class="full">
                <label>Dipnot</label>
                <textarea name="invoice_print_footer" rows="3" placeholder="<?= app_h($settings['invoice_print_footer']) ?>"></textarea>
            </div>
            <div class="full">
                <button type="submit">Sube Tasarimini Kaydet</button>
            </div>
        </form>

        <div class="table-wrap" style="margin-top:16px;">
            <table>
                <thead><tr><th>Sube</th><th>Baslik</th><th>Renk</th><th>Logo</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($branchDocumentTemplateRows as $row): ?>
                    <tr>
                        <td><small><?= app_h($row['firm_name']) ?><br><?= app_h($row['branch_name']) ?></small></td>
                        <td><small><?= app_h($row['invoice_title']) ?><br><?= app_h($row['invoice_subtitle']) ?></small></td>
                        <td><span style="display:inline-block;width:14px;height:14px;border-radius:999px;background:<?= app_h($row['invoice_accent']) ?>;margin-right:8px;border:1px solid #d1d5db;"></span><?= app_h($row['invoice_accent']) ?></td>
                        <td><small><?= app_h($row['document_logo_path'] !== '' ? $row['document_logo_path'] : '-') ?></small></td>
                        <td><span class="<?= !empty($row['is_customized']) ? 'ok' : 'muted' ?>"><?= !empty($row['is_customized']) ? 'Ozel Tasarim' : 'Global Tasarim' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$branchDocumentTemplateRows): ?>
                    <tr><td colspan="5">Sube belge tasarimi icin sube kaydi bulunamadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Medya Dosyalari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Dosya</th><th>Modul</th><th>Tur</th><th>Tarih</th><th>Baglanti</th></tr></thead>
                <tbody>
                <?php foreach ($mediaFiles as $file): ?>
                    <tr>
                        <td><?= app_h($file['file_name']) ?></td>
                        <td><?= app_h($file['module_name'] . ' / ' . ($file['related_table'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($file['file_type'] ?: '-')) ?></td>
                        <td><?= app_h($file['created_at']) ?></td>
                        <td><a href="<?= app_h($file['file_path']) ?>" target="_blank">Ac</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Medya Kurallari</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">Logo Yukleme</strong><span>JPG, PNG, GIF ve WEBP desteklenir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kayit Arsivi</strong><span>Yuklenen dosyalar docs_files tablosuna medya kaydi olarak eklenir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Fiziksel Konum</strong><span>Dosyalar /uploads/logos klasorune yazilir.</span></div><div class="ok">Hazir</div></div>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Sube Ekle</h3>
        <form method="post" class="form-grid compact-form">
            <input type="hidden" name="action" value="create_branch">
            <div>
                <label>Firma</label>
                <select name="firm_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($firmRows as $firmRow): ?>
                        <option value="<?= (int) $firmRow['id'] ?>" <?= (int) ($firm['id'] ?? 0) === (int) $firmRow['id'] ? 'selected' : '' ?>><?= app_h((string) $firmRow['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Sube Adi</label><input name="name" placeholder="Anadolu Sube"></div>
            <div><label>Telefon</label><input name="phone" placeholder="0212..."></div>
            <div><label>E-posta</label><input name="email" placeholder="sube@firma.com"></div>
            <div><label>Sehir</label><input name="city" placeholder="Istanbul"></div>
            <div><label>Ilce</label><input name="district" placeholder="Kadikoy"></div>
            <div class="check-row"><label><input type="checkbox" name="status" value="1" checked> Aktif</label></div>
            <div class="full"><label>Adres</label><textarea name="address" rows="2" placeholder="Sube adresi"></textarea></div>
            <div class="full"><button type="submit">Sube Kaydet</button></div>
        </form>

        <h3 style="margin-top:22px;">Subeler</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Firma</th><th>Sube</th><th>Sehir</th><th>Iletisim</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td><?= app_h($firmNameMap[(int) $branch['firm_id']] ?? '-') ?></td>
                        <td><?= app_h($branch['name']) ?></td>
                        <td><?= app_h(trim((string) (($branch['city'] ?? '') . ' ' . ($branch['district'] ?? '')))) ?></td>
                        <td><?= app_h((string) ($branch['phone'] ?: $branch['email'] ?: '-')) ?></td>
                        <td><?= (int) $branch['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="action" value="update_branch_status">
                                <input type="hidden" name="branch_id" value="<?= (int) $branch['id'] ?>">
                                <input type="hidden" name="status" value="<?= (int) $branch['status'] === 1 ? 0 : 1 ?>">
                                <button type="submit"><?= (int) $branch['status'] === 1 ? 'Pasife Al' : 'Aktif Et' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Kullanici Ekle</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_user">
            <div><label>Ad Soyad</label><input name="full_name" placeholder="Yeni Kullanici" required></div>
            <div><label>E-posta</label><input name="email" type="email" placeholder="kullanici@firma.com" required></div>
            <div><label>Telefon</label><input name="phone" placeholder="05xx..."></div>
            <div><label>Sifre</label><input name="password" type="password" placeholder="Gecici sifre" required></div>
            <div>
                <label>Rol</label>
                <select name="role_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= app_h($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Sube</label>
                <select name="branch_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch['id'] ?>"><?= app_h($branch['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="check-row"><label><input type="checkbox" name="status" value="1" checked> Aktif</label></div>
            <div class="full"><button type="submit">Kullanici Kaydet</button></div>
        </form>

        <h3 style="margin-top:22px;">Kullanicilar ve Roller</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kullanici</th><th>E-posta</th><th>Rol</th><th>Sube</th><th>Durum</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= app_h($user['full_name']) ?></td>
                        <td><?= app_h((string) ($user['email'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($user['role_name'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($user['branch_name'] ?: '-')) ?></td>
                        <td><?= (int) $user['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td>
                            <div class="stack">
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <input type="text" name="full_name" value="<?= app_h($user['full_name']) ?>" placeholder="Ad Soyad">
                                    <input type="email" name="email" value="<?= app_h((string) $user['email']) ?>" placeholder="E-posta">
                                    <input type="text" name="phone" value="<?= app_h((string) $user['phone']) ?>" placeholder="Telefon">
                                    <select name="role_id">
                                        <option value="">Rol</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= (int) $role['id'] ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= app_h($role['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="branch_id">
                                        <option value="">Sube</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?= (int) $branch['id'] ?>" <?= (int) $user['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= app_h($branch['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="status">
                                        <option value="1" <?= (int) $user['status'] === 1 ? 'selected' : '' ?>>Aktif</option>
                                        <option value="0" <?= (int) $user['status'] === 0 ? 'selected' : '' ?>>Pasif</option>
                                    </select>
                                    <button type="submit">Guncelle</button>
                                </form>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="reset_user_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <input type="password" name="new_password" placeholder="Yeni sifre" required>
                                    <button type="submit">Sifre Sifirla</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
