<?php

declare(strict_types=1);

function app_starts_with(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) === 0;
}

function app_ends_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    $length = strlen($needle);

    return substr($haystack, -$length) === $needle;
}

function app_config(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $base = require __DIR__ . '/config.php';
    $localPath = __DIR__ . '/config.local.php';
    $local = file_exists($localPath) ? require $localPath : [];

    $config = array_replace_recursive($base, $local);

    return $config;
}

function app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = rtrim(dirname($script), '/');

    return $scheme . '://' . $host . ($basePath === '' || $basePath === '.' ? '' : $basePath);
}

function app_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

function app_is_local_request(): bool
{
    $ip = app_client_ip();
    return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
}

function app_ip_matches_rule(string $ip, string $rule): bool
{
    $ip = trim($ip);
    $rule = trim($rule);

    if ($ip === '' || $rule === '') {
        return false;
    }

    if ($rule === '*') {
        return true;
    }

    if (strpos($rule, '/') !== false) {
        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
        $subnet = trim($subnet);
        $bits = (int) trim($bits);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);
        return (($ipLong & $mask) === ($subnetLong & $mask));
    }

    if (app_ends_with($rule, '*')) {
        $prefix = substr($rule, 0, -1);
        return $prefix !== '' && app_starts_with($ip, $prefix);
    }

    return strcasecmp($ip, $rule) === 0;
}

function app_security_ip_mode(PDO $db): string
{
    $mode = strtolower(trim(app_setting($db, 'security.ip_mode', 'off')));
    return in_array($mode, ['off', 'allowlist'], true) ? $mode : 'off';
}

function app_security_ip_rules(PDO $db): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", app_setting($db, 'security.ip_allowlist', ''));
    $lines = array_filter(array_map(static fn(string $line): string => trim($line), explode("\n", $raw)), static fn(string $line): bool => $line !== '');

    return array_values(array_unique($lines));
}

function app_security_ip_local_bypass(PDO $db): bool
{
    return app_setting($db, 'security.ip_local_bypass', '1') !== '0';
}

function app_is_request_ip_allowed(?PDO $db = null): bool
{
    $db = $db ?? app_db();
    if (!$db) {
        return true;
    }

    if (app_security_ip_mode($db) === 'off') {
        return true;
    }

    if (app_security_ip_local_bypass($db) && app_is_local_request()) {
        return true;
    }

    $ip = app_client_ip();
    $rules = app_security_ip_rules($db);
    if ($rules === []) {
        return false;
    }

    foreach ($rules as $rule) {
        if (app_ip_matches_rule($ip, $rule)) {
            return true;
        }
    }

    return false;
}

function app_handle_ip_access_denied(string $context = 'session', ?string $email = null): void
{
    $db = app_db();
    if ($db) {
        app_audit_log(
            'auth',
            'ip_access_denied',
            'core_settings',
            null,
            json_encode([
                'context' => $context,
                'email' => $email,
                'ip' => app_client_ip(),
                'mode' => app_security_ip_mode($db),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

function app_rate_limit_storage_path(): string
{
    return __DIR__ . '/storage/ratelimits.json';
}

function app_rate_limit_check(string $scope, int $maxAttempts, int $windowSeconds): array
{
    $path = app_rate_limit_storage_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (!file_exists($path)) {
        file_put_contents($path, '{}');
    }

    $now = time();
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        $raw = stream_get_contents($fp);
        $data = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $key => $entry) {
            $lastAttempt = (int) ($entry['last'] ?? 0);
            if (($now - $lastAttempt) > $windowSeconds) {
                unset($data[$key]);
            }
        }

        $entry = $data[$scope] ?? ['count' => 0, 'first' => $now, 'last' => $now];
        $first = (int) ($entry['first'] ?? $now);
        $last = (int) ($entry['last'] ?? $now);
        $count = (int) ($entry['count'] ?? 0);

        if (($now - $first) >= $windowSeconds) {
            $first = $now;
            $count = 0;
        }

        $count++;
        $last = $now;
        $data[$scope] = ['count' => $count, 'first' => $first, 'last' => $last];

        $allowed = $count <= $maxAttempts;
        $retryAfter = $allowed ? 0 : max(1, $windowSeconds - ($now - $first));
        $remaining = max(0, $maxAttempts - $count);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);

        return ['allowed' => $allowed, 'retry_after' => $retryAfter, 'remaining' => $remaining];
    } finally {
        fclose($fp);
    }
}

function app_modules(): array
{
    return [
        'dashboard' => [
            'title' => 'Dashboard',
            'summary' => 'Tahsilat, bakiye, stok, servis ve kira operasyonlarinin genel gorunumu.',
            'tables' => ['notification_queue', 'notification_push_subscriptions', 'notification_preferences'],
        ],
        'core' => [
            'title' => 'Core Sistem',
            'summary' => 'Rol, kullanici, firma, sube, ayarlar ve log altyapisi.',
            'tables' => ['core_roles', 'core_users', 'core_firms', 'core_branches', 'core_settings', 'core_audit_logs'],
        ],
        'cari' => [
            'title' => 'Cari ve Finans',
            'summary' => 'Musteri/tedarikci kartlari, cari hareketleri, kasa ve banka takibi.',
            'tables' => ['cari_cards', 'cari_addresses', 'cari_movements', 'finance_cashboxes', 'finance_bank_accounts', 'finance_cash_movements', 'finance_bank_movements', 'finance_transfers', 'finance_expenses'],
        ],
        'tahsilat' => [
            'title' => 'Tahsilat ve POS',
            'summary' => 'Odeme linkleri, POS hesaplari ve otomatik muhasebelestirme altyapisi.',
            'tables' => ['collections_pos_accounts', 'collections_links', 'collections_transactions'],
        ],
        'fatura' => [
            'title' => 'Fatura ve e-Donusum',
            'summary' => 'Satis, alis, iade faturasi ve e-donusum arsiv omurgasi.',
            'tables' => ['invoice_headers', 'invoice_items'],
        ],
        'satis' => [
            'title' => 'Satis ve Siparis',
            'summary' => 'Teklif, siparis, kalem ve teslimat surecleri.',
            'tables' => ['sales_offers', 'sales_offer_items', 'sales_orders', 'sales_order_items'],
        ],
        'stok' => [
            'title' => 'Stok ve Depo',
            'summary' => 'Urun, kategori, varyant, depo ve stok hareketleri.',
            'tables' => ['stock_categories', 'stock_warehouses', 'stock_locations', 'stock_products', 'stock_product_variants', 'stock_movements', 'stock_counts', 'stock_reservations', 'stock_wastages'],
        ],
        'servis' => [
            'title' => 'Servis Yonetimi',
            'summary' => 'Servis kayitlari, ariza tipleri, teknik atama ve parca takibi.',
            'tables' => ['service_fault_types', 'service_statuses', 'service_records', 'service_parts', 'service_notes', 'service_appointments', 'service_steps', 'service_photos'],
        ],
        'kira' => [
            'title' => 'Cihaz Kira Yonetimi',
            'summary' => 'Cihaz kartlari, kira sozlesmeleri, odeme planlari ve iade sureci.',
            'tables' => ['rental_device_categories', 'rental_devices', 'rental_contracts', 'rental_contract_renewals', 'rental_contract_protocols', 'rental_return_checklists', 'rental_payments', 'rental_payment_invoices', 'rental_service_logs'],
        ],
        'uretim' => [
            'title' => 'Uretim ve Recete',
            'summary' => 'Recete, uretim emri, sarf dusumu ve mamul stok ciktilari.',
            'tables' => ['production_recipes', 'production_recipe_items', 'production_recipe_subrecipes', 'production_recipe_routes', 'production_schedule', 'production_waste_records', 'production_cost_snapshots', 'production_semi_finished_flows', 'production_deadline_plans', 'production_order_approvals', 'production_orders', 'production_work_centers', 'production_order_operations', 'production_consumptions', 'production_outputs'],
        ],
        'ik' => [
            'title' => 'Personel ve IK',
            'summary' => 'Departman, personel, vardiya ve zimmet surecleri.',
            'tables' => ['hr_departments', 'hr_employees', 'hr_shifts', 'hr_assignments'],
        ],
        'evrak' => [
            'title' => 'Evrak ve Arsiv',
            'summary' => 'Sozlesme, servis evragi ve dijital arsiv kayitlari.',
            'tables' => ['docs_files'],
        ],
        'crm' => [
            'title' => 'CRM ve Musteri Yonetimi',
            'summary' => 'Musteri notlari, hatirlatmalar ve firsat takibi.',
            'tables' => ['crm_notes', 'crm_activities', 'crm_call_logs', 'crm_meeting_notes', 'crm_reminders', 'crm_opportunities', 'crm_opportunity_offer_links', 'crm_tags', 'crm_cari_tags', 'crm_campaigns', 'crm_email_templates', 'crm_sms_templates', 'crm_whatsapp_messages', 'crm_tasks'],
        ],
        'bildirim' => [
            'title' => 'Bildirim Merkezi',
            'summary' => 'Hatirlatmalar, geciken tahsilatlar, acik servisler ve operasyon uyarilari.',
            'tables' => [],
        ],
        'rapor' => [
            'title' => 'Rapor Merkezi',
            'summary' => 'Cari, finans, stok, satis, kira ve servis verilerinin yonetim ozetleri.',
            'tables' => [],
        ],
    ];
}

function app_default_role_modules(): array
{
    return [
        'super_admin' => array_keys(app_modules()),
        'muhasebe' => ['dashboard', 'core', 'cari', 'tahsilat', 'fatura', 'satis', 'evrak', 'crm', 'bildirim', 'rapor'],
        'operasyon' => ['dashboard', 'stok', 'servis', 'kira', 'uretim', 'evrak', 'crm', 'bildirim', 'rapor'],
    ];
}

function app_role_modules(): array
{
    $map = app_default_role_modules();
    $db = app_db();

    if (!$db) {
        return $map;
    }

    try {
        $rows = app_fetch_all($db, "
            SELECT setting_key, setting_value
            FROM core_settings
            WHERE setting_key LIKE 'permissions.%'
              AND setting_key NOT LIKE 'permissions.matrix.%'
              AND setting_key NOT LIKE 'permissions.department.%'
        ");

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $roleCode = substr($key, strlen('permissions.'));
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);

            if ($roleCode !== '' && is_array($decoded)) {
                $map[$roleCode] = array_values(array_unique(array_map('strval', $decoded)));
            }
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

function app_permission_actions(): array
{
    return [
        'view' => 'Goruntule',
        'create' => 'Ekle',
        'update' => 'Duzenle',
        'delete' => 'Sil',
        'approve' => 'Onayla',
        'export' => 'Disa Aktar',
        'settings' => 'Ayar',
    ];
}

function app_default_role_action_permissions(): array
{
    $modules = array_keys(app_modules());
    $actions = array_keys(app_permission_actions());
    $all = [];
    foreach ($modules as $moduleKey) {
        $all[$moduleKey] = $actions;
    }

    $limited = [];
    foreach (['dashboard', 'cari', 'tahsilat', 'fatura', 'satis', 'evrak', 'crm', 'bildirim', 'rapor'] as $moduleKey) {
        $limited[$moduleKey] = ['view', 'create', 'update', 'export'];
    }

    $operations = [];
    foreach (['dashboard', 'stok', 'servis', 'kira', 'uretim', 'evrak', 'crm', 'bildirim', 'rapor'] as $moduleKey) {
        $operations[$moduleKey] = ['view', 'create', 'update', 'approve', 'export'];
    }

    return [
        'super_admin' => $all,
        'muhasebe' => $limited,
        'operasyon' => $operations,
    ];
}

function app_role_action_permissions(): array
{
    $map = app_default_role_action_permissions();
    $db = app_db();

    if (!$db) {
        return $map;
    }

    try {
        $rows = app_fetch_all($db, "
            SELECT setting_key, setting_value
            FROM core_settings
            WHERE setting_key LIKE 'permissions.matrix.%'
        ");

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $roleCode = substr($key, strlen('permissions.matrix.'));
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);

            if ($roleCode !== '' && is_array($decoded)) {
                $clean = [];
                foreach ($decoded as $moduleKey => $actions) {
                    if (is_array($actions)) {
                        $clean[(string) $moduleKey] = array_values(array_unique(array_map('strval', $actions)));
                    }
                }
                $map[$roleCode] = $clean;
            }
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

function app_department_permission_modules(): array
{
    $map = [];
    $db = app_db();

    if (!$db) {
        return $map;
    }

    try {
        $rows = app_fetch_all($db, "
            SELECT setting_key, setting_value
            FROM core_settings
            WHERE setting_key LIKE 'permissions.department.modules.%'
        ");

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $departmentId = (int) substr($key, strlen('permissions.department.modules.'));
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);

            if ($departmentId > 0 && is_array($decoded)) {
                $map[$departmentId] = array_values(array_unique(array_map('strval', $decoded)));
            }
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

function app_department_action_permissions(): array
{
    $map = [];
    $db = app_db();

    if (!$db) {
        return $map;
    }

    try {
        $rows = app_fetch_all($db, "
            SELECT setting_key, setting_value
            FROM core_settings
            WHERE setting_key LIKE 'permissions.department.matrix.%'
        ");

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $departmentId = (int) substr($key, strlen('permissions.department.matrix.'));
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);

            if ($departmentId <= 0 || !is_array($decoded)) {
                continue;
            }

            $clean = [];
            foreach ($decoded as $moduleKey => $actions) {
                if (is_array($actions)) {
                    $clean[(string) $moduleKey] = array_values(array_unique(array_map('strval', $actions)));
                }
            }

            $map[$departmentId] = $clean;
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

function app_user_department_id(array $user): ?int
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $db = app_db();
    if (!$db || !app_table_exists($db, 'hr_employees') || !app_column_exists($db, 'hr_employees', 'user_id')) {
        return null;
    }

    try {
        $stmt = $db->prepare('
            SELECT department_id
            FROM hr_employees
            WHERE user_id = :user_id
            ORDER BY status DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId]);
        $departmentId = (int) ($stmt->fetchColumn() ?? 0);

        return $departmentId > 0 ? $departmentId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function app_approval_rule_catalog(): array
{
    return [
        'sales.offer_approve' => [
            'label' => 'Satis Teklifi Onayi',
            'summary' => 'Teklifin onaylanmasi sirasinda not, esik tutar ve onaylayici rol zorunlulugu tanimlar.',
            'module_key' => 'satis',
            'action_key' => 'approve',
            'target_table' => 'sales_offers',
        ],
        'production.order_decision' => [
            'label' => 'Uretim Emir Onay Karari',
            'summary' => 'Uretim emri onay adimlarinda karari verecek rol ve not zorunlulugunu belirler.',
            'module_key' => 'uretim',
            'action_key' => 'approve',
            'target_table' => 'production_order_approvals',
        ],
        'service.customer_approval_update' => [
            'label' => 'Servis Musteri Onay Karari',
            'summary' => 'Servis kaydinda musteri onay durumunun onaylandi/reddedildi olarak guncellenmesini denetler.',
            'module_key' => 'servis',
            'action_key' => 'approve',
            'target_table' => 'service_records',
        ],
        'rental.contract_activation' => [
            'label' => 'Kira Sozlesmesi Aktivasyonu',
            'summary' => 'Kira sozlesmesinin aktif duruma alinmasi icin onaylayici rol ve not kurali tutar.',
            'module_key' => 'kira',
            'action_key' => 'approve',
            'target_table' => 'rental_contracts',
        ],
    ];
}

function app_approval_rule_defaults(): array
{
    $defaults = [];
    foreach (app_approval_rule_catalog() as $ruleKey => $rule) {
        $defaults[$ruleKey] = [
            'enabled' => false,
            'approver_role_code' => '',
            'require_second_approval' => false,
            'second_approver_role_code' => '',
            'require_note' => false,
            'min_amount' => 0.0,
        ];
    }

    return $defaults;
}

function app_approval_rule_settings(): array
{
    $map = app_approval_rule_defaults();
    $db = app_db();

    if (!$db) {
        return $map;
    }

    try {
        $rows = app_fetch_all($db, "
            SELECT setting_key, setting_value
            FROM core_settings
            WHERE setting_key LIKE 'approvals.rule.%'
        ");

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $ruleKey = substr($key, strlen('approvals.rule.'));
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);

            if ($ruleKey === '' || !isset($map[$ruleKey]) || !is_array($decoded)) {
                continue;
            }

            $map[$ruleKey] = [
                'enabled' => !empty($decoded['enabled']),
                'approver_role_code' => trim((string) ($decoded['approver_role_code'] ?? '')),
                'require_second_approval' => !empty($decoded['require_second_approval']),
                'second_approver_role_code' => trim((string) ($decoded['second_approver_role_code'] ?? '')),
                'require_note' => !empty($decoded['require_note']),
                'min_amount' => max(0, (float) ($decoded['min_amount'] ?? 0)),
            ];
        }
    } catch (Throwable $e) {
        return $map;
    }

    return $map;
}

function app_approval_rule(string $ruleKey): array
{
    $catalog = app_approval_rule_catalog();
    $defaults = app_approval_rule_defaults();
    $settings = app_approval_rule_settings();

    return array_merge(
        $catalog[$ruleKey] ?? ['label' => $ruleKey, 'summary' => '', 'module_key' => '', 'action_key' => '', 'target_table' => ''],
        $defaults[$ruleKey] ?? ['enabled' => false, 'approver_role_code' => '', 'require_second_approval' => false, 'second_approver_role_code' => '', 'require_note' => false, 'min_amount' => 0.0],
        $settings[$ruleKey] ?? []
    );
}

function app_approval_rule_matches(array $rule, array $context = []): bool
{
    if (empty($rule['enabled'])) {
        return false;
    }

    $minAmount = max(0, (float) ($rule['min_amount'] ?? 0));
    $amount = max(0, (float) ($context['amount'] ?? 0));

    return $minAmount <= 0 || $amount >= $minAmount;
}

function app_approval_rule_user_allowed(array $user, array $rule): bool
{
    $requiredRoleCode = trim((string) ($rule['approver_role_code'] ?? ''));
    if ($requiredRoleCode === '') {
        return true;
    }

    return (string) ($user['role_code'] ?? '') === $requiredRoleCode;
}

function app_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE :column');
        $stmt->execute(['column' => $column]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function app_branch_scope_tables(): array
{
    return [
        'cari_cards' => 'Cari Kartlar',
        'finance_cashboxes' => 'Kasalar',
        'finance_bank_accounts' => 'Banka Hesaplari',
        'finance_expenses' => 'Giderler',
        'collections_pos_accounts' => 'POS Hesaplari',
        'invoice_headers' => 'Faturalar',
        'sales_offers' => 'Satis Teklifleri',
        'sales_orders' => 'Satis Siparisleri',
        'stock_warehouses' => 'Depolar',
        'service_records' => 'Servis Kayitlari',
        'rental_contracts' => 'Kira Sozlesmeleri',
        'production_orders' => 'Uretim Emirleri',
    ];
}

function app_branch_isolation_mode(PDO $db): string
{
    $mode = app_setting($db, 'branch.isolation_mode', 'reporting');

    return in_array($mode, ['off', 'reporting', 'strict'], true) ? $mode : 'reporting';
}

function app_user_branch_id(?array $user = null): ?int
{
    $user = $user ?? app_auth_user();
    $branchId = (int) ($user['branch_id'] ?? 0);

    return $branchId > 0 ? $branchId : null;
}

function app_default_branch_id(PDO $db, ?array $user = null): ?int
{
    $userBranchId = app_user_branch_id($user);
    if ($userBranchId !== null) {
        return $userBranchId;
    }

    $settingBranchId = (int) app_setting($db, 'branch.default_id', '0');
    if ($settingBranchId > 0) {
        return $settingBranchId;
    }

    try {
        $branchId = (int) app_metric($db, 'SELECT id FROM core_branches WHERE status = 1 ORDER BY id ASC LIMIT 1');
        return $branchId > 0 ? $branchId : null;
    } catch (Throwable $e) {
        return null;
    }
}

function app_user_is_global_scope(?array $user = null): bool
{
    $user = $user ?? app_auth_user();

    return !$user || (string) ($user['role_code'] ?? '') === 'super_admin' || app_user_branch_id($user) === null;
}

function app_branch_scope_filter(PDO $db, ?array $user = null, string $alias = ''): array
{
    $mode = app_branch_isolation_mode($db);
    $branchId = app_user_branch_id($user);

    if ($mode !== 'strict' || app_user_is_global_scope($user) || $branchId === null) {
        return ['', []];
    }

    $prefix = $alias !== '' ? (rtrim($alias, '.') . '.') : '';

    return [$prefix . 'branch_id = :scope_branch_id', ['scope_branch_id' => $branchId]];
}

function app_assert_branch_access(PDO $db, string $table, int $recordId, ?array $user = null): void
{
    if ($recordId <= 0 || app_branch_isolation_mode($db) !== 'strict' || app_user_is_global_scope($user)) {
        return;
    }

    $branchId = app_user_branch_id($user);
    if ($branchId === null || !app_table_exists($db, $table) || !app_column_exists($db, $table, 'branch_id')) {
        return;
    }

    $safeTable = str_replace('`', '``', $table);
    $allowed = (int) app_metric(
        $db,
        'SELECT COUNT(*) FROM `' . $safeTable . '` WHERE id = :id AND branch_id = :branch_id',
        ['id' => $recordId, 'branch_id' => $branchId]
    );

    if ($allowed <= 0) {
        $description = 'Sube kapsami disi kayit erisimi engellendi. Kullanici sube ID: ' . $branchId;
        app_audit_log(
            'core',
            'branch_access_denied',
            $table,
            $recordId,
            $description
        );
        app_notify_branch_access_denied($db, $table, $recordId, $branchId, $user, $description);
        throw new RuntimeException('Bu kayit atanmis sube kapsaminizin disinda.');
    }
}

function app_notify_branch_access_denied(PDO $db, string $table, int $recordId, int $branchId, ?array $user = null, string $description = ''): void
{
    try {
        $recipients = app_fetch_all($db, '
            SELECT u.id, u.full_name, u.email
            FROM core_users u
            INNER JOIN core_roles r ON r.id = u.role_id
            WHERE u.status = 1
              AND r.code = "super_admin"
              AND u.email IS NOT NULL
              AND u.email <> ""
            ORDER BY u.id ASC
            LIMIT 10
        ');

        if (!$recipients) {
            return;
        }

        $actor = trim((string) ($user['full_name'] ?? 'Bilinmeyen kullanici'));
        $actorEmail = trim((string) ($user['email'] ?? ''));
        $subject = 'Sube erisim ihlali engellendi';
        $body = "Sube kapsami disi bir kayda erisim denemesi engellendi.\n\n";
        $body .= 'Kullanici: ' . $actor . ($actorEmail !== '' ? ' <' . $actorEmail . '>' : '') . "\n";
        $body .= 'Kullanici sube ID: ' . $branchId . "\n";
        $body .= 'Kayit: ' . $table . ' #' . $recordId . "\n";
        $body .= 'Tarih: ' . date('Y-m-d H:i:s') . "\n";
        if ($description !== '') {
            $body .= 'Aciklama: ' . $description . "\n";
        }

        foreach ($recipients as $recipient) {
            app_queue_notification($db, [
                'module_name' => 'core',
                'notification_type' => 'branch_access_denied',
                'source_table' => $table,
                'source_id' => $recordId,
                'channel' => 'email',
                'recipient_user_id' => (int) $recipient['id'],
                'recipient_name' => (string) ($recipient['full_name'] ?: 'Yonetici'),
                'recipient_contact' => (string) $recipient['email'],
                'subject_line' => $subject,
                'message_body' => $body,
                'planned_at' => date('Y-m-d H:i:s'),
                'unique_key' => 'branch_denied_email_' . (int) $recipient['id'] . '_' . md5($table . ':' . $recordId . ':' . date('YmdHi')),
                'provider_name' => 'system',
            ]);
        }
    } catch (Throwable $e) {
    }
}

function app_db(): ?PDO
{
    static $pdo = false;

    if ($pdo !== false) {
        return $pdo;
    }

    $cfg = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}

function app_database_ready(): bool
{
    $db = app_db();

    if (!$db) {
        return false;
    }

    try {
        $db->query('SELECT 1 FROM core_users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function app_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SHOW TABLES LIKE :table');
    $stmt->execute(['table' => $table]);

    return (bool) $stmt->fetchColumn();
}

function app_table_count(PDO $db, string $table): int
{
    $sql = sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '``', $table));

    try {
        return (int) $db->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function app_metric(PDO $db, string $sql, array $params = []): string
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (string) ($stmt->fetchColumn() ?? '0');
    } catch (Throwable $e) {
        return '0';
    }
}

function app_write_local_config(array $dbConfig): void
{
    $export = var_export(['db' => $dbConfig], true);
    $content = "<?php\n\nreturn " . $export . ";\n";
    file_put_contents(__DIR__ . '/config.local.php', $content);
}

function app_run_sql_batch(PDO $pdo, string $sql): void
{
    $buffer = '';
    $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || substr($trimmed, 0, 2) === '--') {
            continue;
        }

        $buffer .= $line . "\n";

        if (app_ends_with($trimmed, ';')) {
            $pdo->exec($buffer);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $pdo->exec($buffer);
    }
}

function app_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

function app_fetch_all(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function app_sort_rows(array $rows, string $sortKey, array $sortMap): array
{
    if ($rows === [] || !isset($sortMap[$sortKey])) {
        return $rows;
    }

    [$field, $direction] = $sortMap[$sortKey];
    usort($rows, static function (array $left, array $right) use ($field, $direction): int {
        $leftValue = $left[$field] ?? null;
        $rightValue = $right[$field] ?? null;

        if (is_numeric($leftValue) && is_numeric($rightValue)) {
            $result = (float) $leftValue <=> (float) $rightValue;
        } else {
            $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
        }

        return $direction === 'desc' ? -$result : $result;
    });

    return $rows;
}

function app_paginate_rows(array $rows, int $page = 1, int $perPage = 10): array
{
    $total = count($rows);
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

function app_csv_download(string $filename, array $headers, array $rows): void
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

function app_related_doc_counts(PDO $db, string $moduleName, string $relatedTable, array $relatedIds): array
{
    $relatedIds = array_values(array_filter(array_map('intval', $relatedIds), static fn (int $id): bool => $id > 0));
    if ($relatedIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
    $sql = "
        SELECT related_id, COUNT(*) AS total_docs
        FROM docs_files
        WHERE module_name = ?
          AND related_table = ?
          AND related_id IN ($placeholders)
        GROUP BY related_id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$moduleName, $relatedTable], $relatedIds));

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['related_id']] = (int) $row['total_docs'];
    }

    return $counts;
}

function app_docs_safe_upload_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'evrak';

    return $base . '-' . date('YmdHis') . '-' . substr(sha1($originalName . microtime(true)), 0, 8) . ($extension !== '' ? '.' . $extension : '');
}

function app_store_document(PDO $db, array $meta, array $file): int
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Dosya yukleme islemi basarisiz oldu.');
    }

    $originalName = (string) ($file['name'] ?? 'evrak');
    $targetDir = __DIR__ . '/uploads/docs';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Evrak klasoru olusturulamadi.');
    }
    app_ensure_upload_protection($targetDir);

    $safeName = app_docs_safe_upload_name($originalName);
    $targetPath = $targetDir . '/' . $safeName;
    $publicPath = '/muhasebe1/uploads/docs/' . $safeName;
    $extension = app_validate_uploaded_file($file, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx']);

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Dosya kaydedilemedi.');
    }

    $stmt = $db->prepare('
        INSERT INTO docs_files (module_name, related_table, related_id, file_name, file_path, file_type, notes)
        VALUES (:module_name, :related_table, :related_id, :file_name, :file_path, :file_type, :notes)
    ');
    $stmt->execute([
        'module_name' => $meta['module_name'],
        'related_table' => $meta['related_table'] ?: null,
        'related_id' => $meta['related_id'] ?: null,
        'file_name' => $originalName,
        'file_path' => $publicPath,
        'file_type' => $extension !== '' ? $extension : null,
        'notes' => $meta['notes'] ?: null,
    ]);

    return (int) $db->lastInsertId();
}

function app_doc_upload_url(string $moduleName, string $relatedTable, int $relatedId, string $returnTo): string
{
    return 'doc_upload.php?' . http_build_query([
        'module_name' => $moduleName,
        'related_table' => $relatedTable,
        'related_id' => $relatedId,
        'return_to' => $returnTo,
    ]);
}

function app_doc_absolute_path(string $filePath): string
{
    return __DIR__ . str_replace('/muhasebe1', '', $filePath);
}

function app_doc_mime_type(string $absolutePath, ?string $fallbackExtension = null): string
{
    if (function_exists('mime_content_type') && is_file($absolutePath)) {
        $detected = mime_content_type($absolutePath);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }

    $extension = strtolower($fallbackExtension ?: pathinfo($absolutePath, PATHINFO_EXTENSION));
    $map = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=UTF-8',
        'csv' => 'text/csv; charset=UTF-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    return $map[$extension] ?? 'application/octet-stream';
}

function app_doc_view_url(int $docId, bool $download = false): string
{
    return 'doc_view.php?' . http_build_query([
        'id' => $docId,
        'download' => $download ? 1 : 0,
    ]);
}

function app_setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $stmt = $db->prepare('SELECT setting_value FROM core_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $key]);

        return (string) ($stmt->fetchColumn() ?? $default);
    } catch (Throwable $e) {
        return $default;
    }
}

function app_set_setting(PDO $db, string $key, string $value, string $group = 'genel'): void
{
    $stmt = $db->prepare('
        INSERT INTO core_settings (setting_key, setting_value, setting_group)
        VALUES (:setting_key, :setting_value, :setting_group)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            setting_group = VALUES(setting_group)
    ');
    $stmt->execute([
        'setting_key' => $key,
        'setting_value' => $value,
        'setting_group' => $group,
    ]);
}

function app_series_format(string $pattern, int $sequence, DateTimeInterface $date, array $context = []): string
{
    $replacements = [
        '{YYYY}' => $date->format('Y'),
        '{YY}' => $date->format('y'),
        '{MM}' => $date->format('m'),
        '{DD}' => $date->format('d'),
        '{SEQ}' => str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        '{FIRM}' => (string) ($context['firm_code'] ?? 'FIRMA'),
        '{BRANCH}' => (string) ($context['branch_code'] ?? 'SUBE'),
    ];

    $result = strtr($pattern, $replacements);
    $result = preg_replace_callback('/\{SEQ(\d+)\}/', static function (array $matches) use ($sequence): string {
        $width = max(1, (int) ($matches[1] ?? 5));
        return str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
    }, $result);

    return $result ?: ('DOC-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT));
}

function app_document_series_context(PDO $db, array $context = []): array
{
    $branchId = (int) ($context['branch_id'] ?? app_setting($db, 'branch.default_id', '0'));
    $firmId = (int) ($context['firm_id'] ?? 0);
    $branchCode = '';
    $firmCode = '';

    if ($branchId > 0) {
        try {
            $stmt = $db->prepare('
                SELECT b.id, b.name AS branch_name, f.id AS firm_id, f.company_name
                FROM core_branches b
                LEFT JOIN core_firms f ON f.id = b.firm_id
                WHERE b.id = :id
                LIMIT 1
            ');
            $stmt->execute(['id' => $branchId]);
            $row = $stmt->fetch();
            if ($row) {
                $firmId = $firmId > 0 ? $firmId : (int) ($row['firm_id'] ?? 0);
                $branchCode = app_series_slug((string) ($row['branch_name'] ?? ''), 'SUBE');
                $firmCode = app_series_slug((string) ($row['company_name'] ?? ''), 'FIRMA');
            }
        } catch (Throwable $e) {
        }
    }

    if ($firmId > 0 && $firmCode === '') {
        try {
            $stmt = $db->prepare('SELECT company_name FROM core_firms WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $firmId]);
            $firmCode = app_series_slug((string) ($stmt->fetchColumn() ?: ''), 'FIRMA');
        } catch (Throwable $e) {
        }
    }

    return [
        'firm_id' => $firmId > 0 ? $firmId : null,
        'branch_id' => $branchId > 0 ? $branchId : null,
        'firm_code' => $firmCode !== '' ? $firmCode : 'FIRMA',
        'branch_code' => $branchCode !== '' ? $branchCode : 'SUBE',
    ];
}

function app_series_slug(string $value, string $fallback): string
{
    $value = strtoupper(trim($value));
    $value = strtr($value, [
        'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'I' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
    ]);
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?: '';

    return substr($value !== '' ? $value : $fallback, 0, 8);
}

function app_next_series_sequence(PDO $db, string $seriesKey, string $seriesPattern, DateTimeInterface $date, array $context = []): int
{
    $periodParts = [];
    if (strpos($seriesPattern, '{YYYY}') !== false || strpos($seriesPattern, '{YY}') !== false) {
        $periodParts[] = $date->format('Y');
    }
    if (strpos($seriesPattern, '{MM}') !== false) {
        $periodParts[] = $date->format('m');
    }
    $period = $periodParts === [] ? 'global' : implode('-', $periodParts);
    $scopeMode = app_setting($db, 'docs.series_scope', 'global');
    $scopeParts = [];
    if (in_array($scopeMode, ['firm', 'firm_branch'], true) && !empty($context['firm_id'])) {
        $scopeParts[] = 'firm' . (int) $context['firm_id'];
    }
    if (in_array($scopeMode, ['branch', 'firm_branch'], true) && !empty($context['branch_id'])) {
        $scopeParts[] = 'branch' . (int) $context['branch_id'];
    }
    $scope = $scopeParts !== [] ? implode('.', $scopeParts) : 'global';
    $counterKey = 'series_counter.' . $seriesKey . '.' . $scope . '.' . $period;

    $startedTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTx = true;
    }

    try {
        $stmt = $db->prepare('SELECT setting_value FROM core_settings WHERE setting_key = :setting_key LIMIT 1 FOR UPDATE');
        $stmt->execute(['setting_key' => $counterKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);
        $next = $current + 1;
        app_set_setting($db, $counterKey, (string) $next, 'seri');

        if ($startedTx) {
            $db->commit();
        }

        return $next;
    } catch (Throwable $e) {
        if ($startedTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function app_document_series_number(PDO $db, string $seriesKey, string $legacyPrefixKey, string $defaultPrefix, string $table, string $dateColumn, array $extraConditions = [], array $params = [], ?DateTimeInterface $date = null, array $context = []): string
{
    $seriesPattern = trim(app_setting($db, $seriesKey, ''));
    if ($seriesPattern === '') {
        $seriesPattern = app_setting($db, $legacyPrefixKey, $defaultPrefix) . '-{SEQ}';
    }

    $date = $date ?? new DateTimeImmutable();
    $context = app_document_series_context($db, $context);
    $sequence = app_next_series_sequence($db, $seriesKey, $seriesPattern, $date, $context);

    return app_series_format($seriesPattern, $sequence, $date, $context);
}

function app_audit_log(string $moduleName, string $actionName, ?string $recordTable = null, ?int $recordId = null, ?string $description = null): void
{
    $db = app_db();

    if (!$db) {
        return;
    }

    try {
        $user = app_auth_user();
        $stmt = $db->prepare('
            INSERT INTO core_audit_logs (user_id, module_name, action_name, record_table, record_id, description, ip_address, user_agent)
            VALUES (:user_id, :module_name, :action_name, :record_table, :record_id, :description, :ip_address, :user_agent)
        ');
        $stmt->execute([
            'user_id' => $user['id'] ?? null,
            'module_name' => $moduleName,
            'action_name' => $actionName,
            'record_table' => $recordTable,
            'record_id' => $recordId,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
    }
}

function app_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function app_csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_require_csrf(): void
{
    app_session_start();
    $provided = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Guvenlik dogrulamasi basarisiz oldu. Lutfen sayfayi yenileyin.');
    }
}

function app_allowed_uploads(): array
{
    return [
        'max_bytes' => 5 * 1024 * 1024,
        'mime_map' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'application/csv', 'text/plain'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ],
    ];
}

function app_ensure_upload_protection(string $targetDir): void
{
    $htaccessPath = rtrim($targetDir, '/\\') . '/.htaccess';
    if (file_exists($htaccessPath)) {
        return;
    }

    $rules = "Options -ExecCGI\n";
    $rules .= "AddType text/plain .php .phtml .php3 .php4 .php5 .phar .pl .py .cgi .asp .aspx .js .sh\n";
    $rules .= "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|pl|py|cgi|asp|aspx|js|sh)$\">\n";
    $rules .= "    Deny from all\n";
    $rules .= "</FilesMatch>\n";
    file_put_contents($htaccessPath, $rules);
}

function app_validate_uploaded_file(array $file, array $allowedExtensions): string
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Dosya yukleme kaynagi gecersiz.');
    }

    if ($size <= 0) {
        throw new RuntimeException('Bos dosya yuklenemez.');
    }

    $uploadRules = app_allowed_uploads();
    if ($size > (int) $uploadRules['max_bytes']) {
        throw new RuntimeException('Dosya boyutu 5 MB sinirini asiyor.');
    }

    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Dosya tipi desteklenmiyor.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string) $finfo->file($tmpName));
    $allowedMimeMap = (array) ($uploadRules['mime_map'] ?? []);
    $allowedMimes = $allowedMimeMap[$extension] ?? [];
    if ($allowedMimes === [] || !in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Dosya MIME turu dogrulanamadi.');
    }

    return $extension;
}

function app_payment_signature(array $payload, string $secret): string
{
    $parts = [
        (string) ($payload['ref'] ?? ''),
        (string) ($payload['status'] ?? ''),
        (string) ($payload['timestamp'] ?? ''),
        (string) ($payload['nonce'] ?? ''),
    ];

    return hash_hmac('sha256', implode('|', $parts), $secret);
}

function app_verify_payment_signature(array $payload, string $secret, int $maxSkewSeconds = 300): bool
{
    $timestamp = (int) ($payload['timestamp'] ?? 0);
    $nonce = trim((string) ($payload['nonce'] ?? ''));
    $signature = strtolower(trim((string) ($payload['signature'] ?? '')));
    if ($timestamp <= 0 || $nonce === '' || $signature === '') {
        return false;
    }

    if (abs(time() - $timestamp) > $maxSkewSeconds) {
        return false;
    }

    return hash_equals(app_payment_signature($payload, $secret), $signature);
}

function app_auth_user(): ?array
{
    app_session_start();

    if (empty($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        return null;
    }

    $db = app_db();
    if ($db && app_session_timeout_enabled($db)) {
        $lastActivityAt = (int) ($_SESSION['auth_last_activity_at'] ?? 0);
        $timeoutSeconds = app_session_timeout_seconds($db);
        if ($lastActivityAt > 0 && (time() - $lastActivityAt) > $timeoutSeconds) {
            $timedOutUser = $_SESSION['auth_user'];
            app_audit_log(
                'auth',
                'session_timeout',
                'core_users',
                (int) ($timedOutUser['id'] ?? 0),
                'Oturum zaman asimi nedeniyle kapatildi.'
            );
            app_logout();
            app_session_start();
            $_SESSION['auth_timeout_notice'] = [
                'email' => (string) ($timedOutUser['email'] ?? ''),
                'at' => date('Y-m-d H:i:s'),
            ];
            return null;
        }

        $_SESSION['auth_last_activity_at'] = time();
    }

    return $_SESSION['auth_user'];
}

function app_auth_user_by_id(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $db = app_db();
    if (!$db) {
        return null;
    }

    $stmt = $db->prepare('
        SELECT u.id, u.branch_id, u.full_name, u.email, u.password_hash, r.name AS role_name, r.code AS role_code, b.name AS branch_name
        FROM core_users u
        LEFT JOIN core_roles r ON r.id = u.role_id
        LEFT JOIN core_branches b ON b.id = u.branch_id
        WHERE u.id = :id AND u.status = 1
        LIMIT 1
    ');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function app_auth_verify_credentials(string $email, string $password): ?array
{
    $db = app_db();

    if (!$db) {
        return null;
    }

    $stmt = $db->prepare('
        SELECT u.id, u.branch_id, u.full_name, u.email, u.password_hash, r.name AS role_name, r.code AS role_code, b.name AS branch_name
        FROM core_users u
        LEFT JOIN core_roles r ON r.id = u.role_id
        LEFT JOIN core_branches b ON b.id = u.branch_id
        WHERE u.email = :email AND u.status = 1
        LIMIT 1
    ');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        app_audit_log(
            'auth',
            'login_failed',
            'core_users',
            null,
            json_encode([
                'email' => $email,
                'reason' => 'user_not_found',
                'reason_label' => 'Kullanici bulunamadi',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return null;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        app_audit_log(
            'auth',
            'login_failed',
            'core_users',
            (int) $user['id'],
            json_encode([
                'email' => (string) $user['email'],
                'reason' => 'wrong_password',
                'reason_label' => 'Hatali sifre',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return null;
    }

    return is_array($user) ? $user : null;
}

function app_auth_login_user(array $user): void
{
    $db = app_db();

    app_session_start();
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'branch_id' => (int) ($user['branch_id'] ?? 0) ?: null,
        'branch_name' => (string) ($user['branch_name'] ?? ''),
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role_name' => (string) ($user['role_name'] ?? ''),
        'role_code' => (string) ($user['role_code'] ?? ''),
    ];
    $_SESSION['auth_last_activity_at'] = time();
    unset($_SESSION['auth_timeout_notice']);

    if ($db) {
        $stmt = $db->prepare('UPDATE core_users SET last_login_at = :last_login_at WHERE id = :id');
        try {
            $stmt->execute([
                'last_login_at' => date('Y-m-d H:i:s'),
                'id' => (int) $user['id'],
            ]);
        } catch (Throwable $e) {
        }
    }

    app_audit_log('auth', 'login_success', 'core_users', (int) $user['id'], 'Kullanici giris yapti: ' . (string) $user['email']);
}

function app_two_factor_mode(PDO $db): string
{
    $mode = strtolower(trim(app_setting($db, 'security.two_factor_mode', 'off')));
    return in_array($mode, ['off', 'email'], true) ? $mode : 'off';
}

function app_two_factor_ttl_minutes(PDO $db): int
{
    $ttl = (int) app_setting($db, 'security.two_factor_ttl', '10');
    return max(1, min(30, $ttl));
}

function app_two_factor_enabled_for_user(PDO $db, array $user): bool
{
    return app_two_factor_mode($db) === 'email' && trim((string) ($user['email'] ?? '')) !== '';
}

function app_two_factor_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function app_two_factor_pending(): ?array
{
    app_session_start();
    $challenge = $_SESSION['auth_two_factor'] ?? null;
    return is_array($challenge) ? $challenge : null;
}

function app_two_factor_store(array $challenge): void
{
    app_session_start();
    $_SESSION['auth_two_factor'] = $challenge;
}

function app_two_factor_clear(): void
{
    app_session_start();
    unset($_SESSION['auth_two_factor']);
}

function app_two_factor_dispatch_email(PDO $db, array $user, string $code, int $ttlMinutes): array
{
    $row = [
        'recipient_contact' => (string) ($user['email'] ?? ''),
        'recipient_name' => (string) ($user['full_name'] ?? ''),
        'subject_line' => 'Giris Dogrulama Kodu',
        'message_body' => "Merhaba " . (string) ($user['full_name'] ?? 'Kullanici') . ",\n\n"
            . "Galancy giris dogrulama kodunuz: " . $code . "\n"
            . "Bu kod " . $ttlMinutes . " dakika icinde gecerlidir.\n"
            . "Bu islemi siz yapmadiysaniz sifrenizi degistirin.\n",
    ];

    $mode = app_setting($db, 'notifications.email_mode', 'mock');
    app_send_notification_email($db, $row);

    return [
        'mode' => $mode,
        'preview_code' => $mode === 'mock' ? $code : '',
    ];
}

function app_session_timeout_enabled(PDO $db): bool
{
    return app_setting($db, 'security.session_timeout_enabled', '0') === '1';
}

function app_session_timeout_minutes(PDO $db): int
{
    $minutes = (int) app_setting($db, 'security.session_timeout_minutes', '30');
    return max(5, min(480, $minutes));
}

function app_session_timeout_seconds(PDO $db): int
{
    return app_session_timeout_minutes($db) * 60;
}

function app_session_timeout_notice(): ?array
{
    app_session_start();
    $notice = $_SESSION['auth_timeout_notice'] ?? null;
    return is_array($notice) ? $notice : null;
}

function app_session_timeout_clear_notice(): void
{
    app_session_start();
    unset($_SESSION['auth_timeout_notice']);
}

function app_auth_attempt(string $email, string $password): bool
{
    $user = app_auth_verify_credentials($email, $password);
    if (!$user) {
        return false;
    }

    app_auth_login_user($user);

    return true;
}

function app_require_auth(): array
{
    $user = app_auth_user();

    if ($user === null) {
        app_redirect('login.php');
    }

    $db = app_db();
    if ($db && !app_is_request_ip_allowed($db)) {
        app_handle_ip_access_denied('session', (string) ($user['email'] ?? ''));
        app_logout();
        app_redirect('login.php?ip_blocked=1');
    }

    return $user;
}

function app_user_can_access_module(array $user, string $moduleKey): bool
{
    if ($moduleKey === 'dashboard') {
        return true;
    }

    $roleCode = $user['role_code'] ?? '';
    $map = app_role_modules();

    if (!isset($map[$roleCode])) {
        return false;
    }

    if (!in_array($moduleKey, $map[$roleCode], true)) {
        return false;
    }

    $departmentId = app_user_department_id($user);
    if ($departmentId === null) {
        return true;
    }

    $departmentMap = app_department_permission_modules();
    if (!array_key_exists($departmentId, $departmentMap)) {
        return true;
    }

    return in_array($moduleKey, $departmentMap[$departmentId], true);
}

function app_filter_modules_for_user(array $user, array $modules): array
{
    $filtered = [];

    foreach ($modules as $key => $module) {
        if (app_user_can_access_module($user, $key)) {
            $filtered[$key] = $module;
        }
    }

    return $filtered;
}

function app_logout(): void
{
    app_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function app_insert_cari_movement(PDO $db, array $data): int
{
    $stmt = $db->prepare('
        INSERT INTO cari_movements (
            cari_id, movement_type, source_module, source_table, source_id, amount, currency_code, description, movement_date, created_by
        ) VALUES (
            :cari_id, :movement_type, :source_module, :source_table, :source_id, :amount, :currency_code, :description, :movement_date, :created_by
        )
    ');
    $stmt->execute([
        'cari_id' => $data['cari_id'],
        'movement_type' => $data['movement_type'],
        'source_module' => $data['source_module'],
        'source_table' => $data['source_table'],
        'source_id' => $data['source_id'],
        'amount' => $data['amount'],
        'currency_code' => $data['currency_code'] ?? 'TRY',
        'description' => $data['description'] ?? null,
        'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
        'created_by' => $data['created_by'] ?? 1,
    ]);

    return (int) $db->lastInsertId();
}

function app_create_invoice_from_source(PDO $db, array $payload): int
{
    $startedTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTx = true;
    }

    try {
        $invoiceDate = $payload['invoice_date'] ?? date('Y-m-d');
        $invoiceType = $payload['invoice_type'] ?? 'satis';
        $currencyCode = $payload['currency_code'] ?? 'TRY';
        $subtotal = 0.0;
        $vatTotal = 0.0;

        foreach ($payload['items'] as $item) {
            $lineTotal = (float) $item['line_total'];
            $vatRate = (float) ($item['vat_rate'] ?? 20);
            $subtotal += $lineTotal;
            $vatTotal += $lineTotal * ($vatRate / 100);
        }

        $grandTotal = $subtotal + $vatTotal;
        $seriesConfig = [
            'satis' => ['key' => 'docs.invoice_sales_series', 'legacy' => 'docs.invoice_prefix', 'default' => 'FAT'],
            'alis' => ['key' => 'docs.invoice_purchase_series', 'legacy' => 'docs.invoice_prefix', 'default' => 'ALI'],
            'iade' => ['key' => 'docs.invoice_return_series', 'legacy' => 'docs.invoice_prefix', 'default' => 'IAD'],
        ];
        $seriesMeta = $seriesConfig[$invoiceType] ?? $seriesConfig['satis'];
        $invoiceNo = app_document_series_number(
            $db,
            $seriesMeta['key'],
            $seriesMeta['legacy'],
            $seriesMeta['default'],
            'invoice_headers',
            'invoice_date',
            ['invoice_type = :series_invoice_type'],
            ['series_invoice_type' => $invoiceType],
            new DateTimeImmutable((string) $invoiceDate),
            ['branch_id' => (int) ($payload['branch_id'] ?? app_default_branch_id($db))]
        );

        $stmt = $db->prepare('
            INSERT INTO invoice_headers (
                branch_id, cari_id, invoice_type, invoice_no, invoice_date, due_date, currency_code, subtotal, vat_total, grand_total, edocument_type, edocument_status, notes
            ) VALUES (
                :branch_id, :cari_id, :invoice_type, :invoice_no, :invoice_date, :due_date, :currency_code, :subtotal, :vat_total, :grand_total, :edocument_type, :edocument_status, :notes
            )
        ');
        $stmt->execute([
            'branch_id' => $payload['branch_id'] ?? app_default_branch_id($db),
            'cari_id' => $payload['cari_id'],
            'invoice_type' => $invoiceType,
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'due_date' => $payload['due_date'] ?? null,
            'currency_code' => $currencyCode,
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'grand_total' => $grandTotal,
            'edocument_type' => $payload['edocument_type'] ?? null,
            'edocument_status' => $payload['edocument_status'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        $invoiceId = (int) $db->lastInsertId();

        $stmt = $db->prepare('
            INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, vat_rate, line_total)
            VALUES (:invoice_id, :product_id, :description, :quantity, :unit_price, :vat_rate, :line_total)
        ');

        foreach ($payload['items'] as $item) {
            $stmt->execute([
                'invoice_id' => $invoiceId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'] ?? 20,
                'line_total' => $item['line_total'],
            ]);
        }

        $movementType = ($invoiceType === 'alis' || $invoiceType === 'iade') ? 'alacak' : 'borc';
        app_insert_cari_movement($db, [
            'cari_id' => $payload['cari_id'],
            'movement_type' => $movementType,
            'source_module' => $payload['source_module'] ?? 'fatura',
            'source_table' => 'invoice_headers',
            'source_id' => $invoiceId,
            'amount' => $grandTotal,
            'currency_code' => $currencyCode,
            'description' => $payload['movement_description'] ?? ($invoiceNo . ' olusturuldu'),
            'movement_date' => date('Y-m-d H:i:s'),
            'created_by' => $payload['created_by'] ?? 1,
        ]);

        if ($startedTx) {
            $db->commit();
        }

        return $invoiceId;
    } catch (Throwable $e) {
        if ($startedTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function app_insert_stock_movement(PDO $db, array $data): int
{
    $stmt = $db->prepare('
        INSERT INTO stock_movements (
            warehouse_id, product_id, variant_id, movement_type, quantity, unit_cost, lot_no, serial_no, reference_type, reference_id, movement_date
        ) VALUES (
            :warehouse_id, :product_id, :variant_id, :movement_type, :quantity, :unit_cost, :lot_no, :serial_no, :reference_type, :reference_id, :movement_date
        )
    ');
    $stmt->execute([
        'warehouse_id' => $data['warehouse_id'],
        'product_id' => $data['product_id'],
        'variant_id' => $data['variant_id'] ?? null,
        'movement_type' => $data['movement_type'],
        'quantity' => $data['quantity'],
        'unit_cost' => $data['unit_cost'] ?? 0,
        'lot_no' => $data['lot_no'] ?? null,
        'serial_no' => $data['serial_no'] ?? null,
        'reference_type' => $data['reference_type'] ?? null,
        'reference_id' => $data['reference_id'] ?? null,
        'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
    ]);

    return (int) $db->lastInsertId();
}

function app_register_collection_payment(PDO $db, array $data): int
{
    $startedTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTx = true;
    }

    try {
        $cariId = (int) ($data['cari_id'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);
        $bankAccountId = (int) ($data['bank_account_id'] ?? 0);
        $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
        $commissionRate = max(0, (float) ($data['commission_rate'] ?? 0));
        $commissionAmount = round($amount * $commissionRate / 100, 2);
        $netAmount = max(0, round($amount - $commissionAmount, 2));

        if ($cariId <= 0 || $amount <= 0 || $bankAccountId <= 0) {
            throw new RuntimeException('Tahsilat kaydi icin cari, tutar ve banka hesabi zorunludur.');
        }

        $stmt = $db->prepare('
            INSERT INTO collections_transactions (
                link_id, cari_id, pos_account_id, amount, installment_count, commission_rate,
                commission_amount, net_amount, status, transaction_ref, processed_at
            ) VALUES (
                :link_id, :cari_id, :pos_account_id, :amount, :installment_count, :commission_rate,
                :commission_amount, :net_amount, :status, :transaction_ref, :processed_at
            )
        ');
        $stmt->execute([
            'link_id' => $data['link_id'] ?? null,
            'cari_id' => $cariId,
            'pos_account_id' => $data['pos_account_id'] ?? null,
            'amount' => $amount,
            'installment_count' => $installmentCount,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'net_amount' => $netAmount,
            'status' => 'basarili',
            'transaction_ref' => $data['transaction_ref'] ?? null,
            'processed_at' => $data['processed_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $transactionId = (int) $db->lastInsertId();
        $description = (string) ($data['description'] ?? ('POS tahsilati #' . $transactionId));

        $stmt = $db->prepare('
            INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
            VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
        ');
        $stmt->execute([
            'bank_account_id' => $bankAccountId,
            'cari_id' => $cariId,
            'movement_type' => 'giris',
            'amount' => $amount,
            'description' => $description,
            'movement_date' => date('Y-m-d H:i:s'),
        ]);

        app_record_collection_commission($db, $transactionId, $bankAccountId, $commissionAmount, (string) ($data['transaction_ref'] ?? ''), $description);

        app_insert_cari_movement($db, [
            'cari_id' => $cariId,
            'movement_type' => 'alacak',
            'source_module' => 'tahsilat',
            'source_table' => 'collections_transactions',
            'source_id' => $transactionId,
            'amount' => $amount,
            'currency_code' => $data['currency_code'] ?? 'TRY',
            'description' => $description,
            'movement_date' => date('Y-m-d H:i:s'),
            'created_by' => $data['created_by'] ?? 1,
        ]);

        $linkId = (int) ($data['link_id'] ?? 0);
        if ($linkId > 0) {
            $stmt = $db->prepare("UPDATE collections_links SET status = 'odendi' WHERE id = :id");
            $stmt->execute(['id' => $linkId]);
        }

        if ($startedTx) {
            $db->commit();
        }

        return $transactionId;
    } catch (Throwable $e) {
        if ($startedTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function app_record_collection_commission(PDO $db, int $transactionId, int $bankAccountId, float $commissionAmount, string $transactionRef = '', string $baseDescription = ''): void
{
    if ($transactionId <= 0 || $bankAccountId <= 0 || $commissionAmount <= 0) {
        return;
    }

    $alreadyRecorded = (int) app_metric($db, "
        SELECT COUNT(*) FROM finance_expenses
        WHERE source_module = 'tahsilat' AND source_table = 'collections_transactions' AND source_id = :source_id
    ", ['source_id' => $transactionId]) > 0;

    if ($alreadyRecorded) {
        return;
    }

    $description = 'POS komisyonu #' . $transactionId;
    if ($transactionRef !== '') {
        $description .= ' / Ref: ' . $transactionRef;
    }
    if ($baseDescription !== '') {
        $description .= ' / ' . $baseDescription;
    }

    $stmt = $db->prepare('
        INSERT INTO finance_expenses (
            branch_id, expense_type, payment_channel, payment_ref_id, amount, description,
            source_module, source_table, source_id, expense_date
        ) VALUES (
            :branch_id, :expense_type, :payment_channel, :payment_ref_id, :amount, :description,
            :source_module, :source_table, :source_id, :expense_date
        )
    ');
    $stmt->execute([
        'branch_id' => app_default_branch_id($db),
        'expense_type' => 'POS Komisyonu',
        'payment_channel' => 'banka',
        'payment_ref_id' => $bankAccountId,
        'amount' => $commissionAmount,
        'description' => $description,
        'source_module' => 'tahsilat',
        'source_table' => 'collections_transactions',
        'source_id' => $transactionId,
        'expense_date' => date('Y-m-d'),
    ]);

    $stmt = $db->prepare('
        INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
        VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
    ');
    $stmt->execute([
        'bank_account_id' => $bankAccountId,
        'cari_id' => null,
        'movement_type' => 'cikis',
        'amount' => $commissionAmount,
        'description' => $description,
        'movement_date' => date('Y-m-d H:i:s'),
    ]);
}

function app_complete_collection_transaction(PDO $db, int $transactionId, int $bankAccountId, string $description = ''): int
{
    $startedTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTx = true;
    }

    try {
        if ($transactionId <= 0 || $bankAccountId <= 0) {
            throw new RuntimeException('Tahsilat tamamlama icin islem ve banka hesabi zorunludur.');
        }

        $rows = app_fetch_all($db, '
            SELECT t.*, l.link_code
            FROM collections_transactions t
            LEFT JOIN collections_links l ON l.id = t.link_id
            WHERE t.id = :id
            LIMIT 1
        ', ['id' => $transactionId]);

        if (!$rows) {
            throw new RuntimeException('Tamamlanacak POS islemi bulunamadi.');
        }

        $row = $rows[0];
        if ((string) $row['status'] === 'basarili') {
            if ($startedTx) {
                $db->commit();
            }
            return $transactionId;
        }

        $cariId = (int) $row['cari_id'];
        $amount = (float) $row['amount'];
        $commissionAmount = (float) ($row['commission_amount'] ?? 0);
        if ($cariId <= 0 || $amount <= 0) {
            throw new RuntimeException('POS islemi cari veya tutar bilgisi eksik oldugu icin tamamlanamadi.');
        }

        $alreadyPosted = (int) app_metric($db, "
            SELECT COUNT(*) FROM cari_movements
            WHERE source_module = 'tahsilat' AND source_table = 'collections_transactions' AND source_id = :source_id
        ", ['source_id' => $transactionId]) > 0;

        $stmt = $db->prepare("
            UPDATE collections_transactions
            SET status = 'basarili', processed_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $transactionId]);

        if (!$alreadyPosted) {
            $movementDescription = $description !== '' ? $description : ('POS tahsilati #' . $transactionId);

            $stmt = $db->prepare('
                INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
                VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
            ');
            $stmt->execute([
                'bank_account_id' => $bankAccountId,
                'cari_id' => $cariId,
                'movement_type' => 'giris',
                'amount' => $amount,
                'description' => $movementDescription,
                'movement_date' => date('Y-m-d H:i:s'),
            ]);

            app_record_collection_commission($db, $transactionId, $bankAccountId, $commissionAmount, (string) ($row['transaction_ref'] ?? ''), $movementDescription);

            app_insert_cari_movement($db, [
                'cari_id' => $cariId,
                'movement_type' => 'alacak',
                'source_module' => 'tahsilat',
                'source_table' => 'collections_transactions',
                'source_id' => $transactionId,
                'amount' => $amount,
                'currency_code' => 'TRY',
                'description' => $movementDescription,
                'movement_date' => date('Y-m-d H:i:s'),
                'created_by' => 1,
            ]);
        }

        $linkId = (int) ($row['link_id'] ?? 0);
        if ($linkId > 0) {
            $stmt = $db->prepare("UPDATE collections_links SET status = 'odendi' WHERE id = :id");
            $stmt->execute(['id' => $linkId]);
        }

        if ($startedTx) {
            $db->commit();
        }

        return $transactionId;
    } catch (Throwable $e) {
        if ($startedTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function app_refund_collection_transaction(PDO $db, int $transactionId, int $bankAccountId, string $reason = ''): int
{
    $startedTx = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTx = true;
    }

    try {
        if ($transactionId <= 0 || $bankAccountId <= 0) {
            throw new RuntimeException('Iade icin islem ve banka hesabi zorunludur.');
        }

        $rows = app_fetch_all($db, '
            SELECT t.*, l.link_code
            FROM collections_transactions t
            LEFT JOIN collections_links l ON l.id = t.link_id
            WHERE t.id = :id
            LIMIT 1
        ', ['id' => $transactionId]);

        if (!$rows) {
            throw new RuntimeException('Iade edilecek POS islemi bulunamadi.');
        }

        $row = $rows[0];
        if ((string) $row['status'] !== 'basarili') {
            throw new RuntimeException('Sadece basarili tahsilatlar iade edilebilir.');
        }

        if (!empty($row['refunded_at']) || (float) ($row['refunded_amount'] ?? 0) > 0) {
            throw new RuntimeException('Bu POS islemi daha once iade edilmis.');
        }

        $cariId = (int) $row['cari_id'];
        $amount = (float) $row['amount'];
        if ($cariId <= 0 || $amount <= 0) {
            throw new RuntimeException('Iade icin cari ve tutar bilgisi zorunludur.');
        }

        $transactionRef = trim((string) ($row['transaction_ref'] ?? ''));
        $description = 'POS iadesi #' . $transactionId;
        if ($transactionRef !== '') {
            $description .= ' / Ref: ' . $transactionRef;
        }
        if ($reason !== '') {
            $description .= ' / ' . $reason;
        }

        $stmt = $db->prepare("
            UPDATE collections_transactions
            SET status = 'iade', refunded_amount = :refunded_amount, refund_reason = :refund_reason, refunded_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'refunded_amount' => $amount,
            'refund_reason' => $reason !== '' ? $reason : null,
            'id' => $transactionId,
        ]);

        $stmt = $db->prepare('
            INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
            VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
        ');
        $stmt->execute([
            'bank_account_id' => $bankAccountId,
            'cari_id' => $cariId,
            'movement_type' => 'cikis',
            'amount' => $amount,
            'description' => $description,
            'movement_date' => date('Y-m-d H:i:s'),
        ]);

        app_insert_cari_movement($db, [
            'cari_id' => $cariId,
            'movement_type' => 'borc',
            'source_module' => 'tahsilat_iade',
            'source_table' => 'collections_transactions',
            'source_id' => $transactionId,
            'amount' => $amount,
            'currency_code' => 'TRY',
            'description' => $description,
            'movement_date' => date('Y-m-d H:i:s'),
            'created_by' => 1,
        ]);

        $linkId = (int) ($row['link_id'] ?? 0);
        if ($linkId > 0) {
            $stmt = $db->prepare("UPDATE collections_links SET status = 'gonderildi' WHERE id = :id");
            $stmt->execute(['id' => $linkId]);
        }

        if ($startedTx) {
            $db->commit();
        }

        return $transactionId;
    } catch (Throwable $e) {
        if ($startedTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function app_virtual_pos_start_transaction(PDO $db, array $posAccount, array $linkRow, array $payload): array
{
    $mode = trim((string) ($posAccount['api_mode'] ?? 'mock')) ?: 'mock';
    $transactionRef = (string) ($payload['transaction_ref'] ?? ('POS-' . date('YmdHis')));
    $payerName = trim((string) ($payload['payer_name'] ?? 'Online Musteri')) ?: 'Online Musteri';
    $customerName = (string) (($linkRow['company_name'] ?? '') ?: ($linkRow['full_name'] ?? '') ?: $payerName);
    $successUrl = (string) ($payload['success_url'] ?? '');
    $failUrl = (string) ($payload['fail_url'] ?? '');
    $callbackUrl = (string) ($payload['callback_url'] ?? '');
    $threeDEnabled = (int) ($posAccount['three_d_enabled'] ?? 0) === 1;
    $requestPayload = [
        'provider_name' => (string) ($posAccount['provider_name'] ?? ''),
        'provider_code' => (string) ($posAccount['provider_code'] ?? ''),
        'merchant_code' => (string) ($posAccount['merchant_code'] ?? ''),
        'public_key' => (string) ($posAccount['public_key'] ?? ''),
        'secret_key' => (string) ($posAccount['secret_key'] ?? ''),
        'link_id' => (string) ($linkRow['id'] ?? ''),
        'link_code' => (string) ($linkRow['link_code'] ?? ''),
        'amount' => number_format((float) ($linkRow['amount'] ?? 0), 2, '.', ''),
        'installment_count' => (string) max(1, (int) ($payload['installment_count'] ?? ($linkRow['installment_count'] ?? 1))),
        'commission_rate' => number_format(max(0, (float) ($payload['commission_rate'] ?? ($posAccount['commission_rate'] ?? 0))), 2, '.', ''),
        'commission_amount' => number_format(max(0, (float) ($payload['commission_amount'] ?? 0)), 2, '.', ''),
        'net_amount' => number_format(max(0, (float) ($payload['net_amount'] ?? ($linkRow['amount'] ?? 0))), 2, '.', ''),
        'currency_code' => (string) ($payload['currency_code'] ?? 'TRY'),
        'payer_name' => $payerName,
        'customer_name' => $customerName,
        'customer_email' => (string) ($linkRow['email'] ?? ''),
        'customer_phone' => (string) ($linkRow['phone'] ?? ''),
        'success_url' => $successUrl,
        'fail_url' => $failUrl,
        'callback_url' => $callbackUrl,
        'three_d_enabled' => $threeDEnabled ? '1' : '0',
        'three_d_success_url' => $successUrl,
        'three_d_fail_url' => $failUrl,
        'transaction_ref' => $transactionRef,
    ];

    if ($mode === 'mock') {
        if ($threeDEnabled) {
            return [
                'status' => 'bekliyor',
                'transaction_ref' => $transactionRef,
                'redirect_url' => $successUrl,
                'three_d_status' => 'redirect',
                'three_d_redirect_url' => $successUrl,
                'response' => 'Mock 3D Secure yonlendirmesi olusturuldu.',
            ];
        }

        return [
            'status' => 'basarili',
            'transaction_ref' => $transactionRef,
            'redirect_url' => '',
            'three_d_status' => null,
            'three_d_redirect_url' => '',
            'response' => 'Mock sanal POS islemi basarili.',
        ];
    }

    if ($mode === 'manual') {
        return [
            'status' => 'bekliyor',
            'transaction_ref' => $transactionRef,
            'redirect_url' => '',
            'three_d_status' => $threeDEnabled ? 'manual' : null,
            'three_d_redirect_url' => '',
            'response' => 'Manuel POS modu: islem referansi olusturuldu, sonuc bekleniyor.',
        ];
    }

    if ($mode === 'http_api') {
        $isThreeDInit = $threeDEnabled && trim((string) ($posAccount['three_d_init_url'] ?? '')) !== '';
        $response = app_http_api_request([
            'url' => (string) ($isThreeDInit ? ($posAccount['three_d_init_url'] ?? '') : ($posAccount['api_url'] ?? '')),
            'method' => (string) ($isThreeDInit ? ($posAccount['three_d_method'] ?? 'POST') : ($posAccount['api_method'] ?? 'POST')),
            'content_type' => 'application/json',
            'headers' => (string) ($isThreeDInit ? ($posAccount['three_d_headers'] ?? '') : ($posAccount['api_headers'] ?? '')),
            'body_template' => (string) (($isThreeDInit ? ($posAccount['three_d_body'] ?? '') : ($posAccount['api_body'] ?? '')) ?: '{}'),
            'timeout' => 20,
        ], $requestPayload);

        $body = (string) ($response['body'] ?? '');
        $decoded = json_decode($body, true);
        $redirectUrl = '';
        $status = 'bekliyor';
        $returnedRef = $transactionRef;
        $threeDStatus = $threeDEnabled ? 'started' : null;

        if (is_array($decoded)) {
            $redirectUrl = trim((string) (($decoded['three_d_redirect_url'] ?? $decoded['redirect_url'] ?? $decoded['payment_url'] ?? '')));
            $returnedRef = trim((string) (($decoded['transaction_ref'] ?? $decoded['reference'] ?? $transactionRef))) ?: $transactionRef;
            $status = trim((string) ($decoded['status'] ?? 'bekliyor')) ?: 'bekliyor';
            $threeDStatus = trim((string) (($decoded['three_d_status'] ?? $decoded['md_status'] ?? $threeDStatus ?? ''))) ?: $threeDStatus;
            if ($threeDEnabled && $redirectUrl !== '' && $threeDStatus === 'started') {
                $threeDStatus = 'redirect';
            }
        }

        return [
            'status' => $status,
            'transaction_ref' => $returnedRef,
            'redirect_url' => $redirectUrl,
            'three_d_status' => $threeDStatus,
            'three_d_redirect_url' => $threeDEnabled ? $redirectUrl : '',
            'response' => $body !== '' ? $body : 'HTTP API POS istegi gonderildi.',
        ];
    }

    throw new RuntimeException('Desteklenmeyen POS modu: ' . $mode);
}

function app_virtual_pos_check_transaction_status(PDO $db, array $transactionRow, array $posAccount): array
{
    $mode = trim((string) ($posAccount['api_mode'] ?? 'mock')) ?: 'mock';
    $transactionRef = trim((string) ($transactionRow['transaction_ref'] ?? ''));
    $statusPayload = [
        'provider_name' => (string) ($posAccount['provider_name'] ?? ''),
        'provider_code' => (string) ($posAccount['provider_code'] ?? ''),
        'merchant_code' => (string) ($posAccount['merchant_code'] ?? ''),
        'public_key' => (string) ($posAccount['public_key'] ?? ''),
        'secret_key' => (string) ($posAccount['secret_key'] ?? ''),
        'transaction_id' => (string) ($transactionRow['id'] ?? ''),
        'transaction_ref' => $transactionRef,
        'amount' => number_format((float) ($transactionRow['amount'] ?? 0), 2, '.', ''),
        'installment_count' => (string) max(1, (int) ($transactionRow['installment_count'] ?? 1)),
    ];

    if ($mode === 'mock') {
        return [
            'status' => 'basarili',
            'provider_status' => 'mock_approved',
            'response' => 'Mock POS mutabakati basarili.',
        ];
    }

    if ($mode === 'manual') {
        return [
            'status' => 'bekliyor',
            'provider_status' => 'manual_review',
            'response' => 'Manuel POS modu icin durum kullanici tarafindan guncellenmelidir.',
        ];
    }

    if ($mode === 'http_api') {
        if (trim((string) ($posAccount['status_url'] ?? '')) === '') {
            throw new RuntimeException('POS durum sorgu URL tanimli degil.');
        }

        $response = app_http_api_request([
            'url' => (string) ($posAccount['status_url'] ?? ''),
            'method' => (string) ($posAccount['status_method'] ?? 'POST'),
            'content_type' => 'application/json',
            'headers' => (string) ($posAccount['status_headers'] ?? ''),
            'body_template' => (string) (($posAccount['status_body'] ?? '') ?: '{"transaction_ref":"{{transaction_ref}}"}'),
            'timeout' => 20,
        ], $statusPayload);

        $body = (string) ($response['body'] ?? '');
        $decoded = json_decode($body, true);
        $providerStatus = '';
        $status = 'bekliyor';

        if (is_array($decoded)) {
            $providerStatus = trim((string) (($decoded['status'] ?? $decoded['payment_status'] ?? $decoded['provider_status'] ?? $decoded['state'] ?? '')));
            $normalized = strtolower($providerStatus);
            if (in_array($normalized, ['success', 'basarili', 'approved', 'paid', 'ok', 'completed'], true)) {
                $status = 'basarili';
            } elseif (in_array($normalized, ['fail', 'failed', 'hatali', 'declined', 'cancel', 'cancelled', 'error'], true)) {
                $status = 'hatali';
            }
        }

        return [
            'status' => $status,
            'provider_status' => $providerStatus !== '' ? $providerStatus : 'unknown',
            'response' => $body !== '' ? $body : 'HTTP API POS durum sorgusu tamamlandi.',
        ];
    }

    throw new RuntimeException('Desteklenmeyen POS modu: ' . $mode);
}

function app_normalize_payment_status(string $providerStatus): string
{
    $normalized = strtolower(trim($providerStatus));
    if (in_array($normalized, ['success', 'basarili', 'approved', 'paid', 'ok', 'completed'], true)) {
        return 'basarili';
    }

    if (in_array($normalized, ['fail', 'failed', 'hatali', 'declined', 'cancel', 'cancelled', 'error'], true)) {
        return 'hatali';
    }

    return 'bekliyor';
}

function app_process_collection_callback(PDO $db, array $transaction, string $providerStatus, string $providerResponse, ?int $bankAccountId = null, string $descriptionPrefix = 'POS callback tahsilati'): string
{
    $newStatus = app_normalize_payment_status($providerStatus);
    $providerStatusValue = $providerStatus !== '' ? $providerStatus : ($newStatus === 'basarili' ? 'callback_success' : ($newStatus === 'hatali' ? 'callback_failed' : 'callback_pending'));
    $transactionId = (int) ($transaction['id'] ?? 0);

    if ($transactionId <= 0) {
        throw new RuntimeException('Callback icin gecerli POS islemi bulunamadi.');
    }

    if ($newStatus === 'basarili') {
        if ($bankAccountId === null || $bankAccountId <= 0) {
            $bankRows = app_fetch_all($db, '
                SELECT id
                FROM finance_bank_accounts
                ORDER BY id ASC
                LIMIT 1
            ');
            if (!$bankRows) {
                throw new RuntimeException('Tahsilat icin tanimli banka hesabi bulunamadi.');
            }
            $bankAccountId = (int) $bankRows[0]['id'];
        }

        $stmt = $db->prepare('
            UPDATE collections_transactions
            SET provider_status = :provider_status,
                provider_response = :provider_response,
                last_status_check_at = NOW(),
                reconciled_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            'provider_status' => $providerStatusValue,
            'provider_response' => $providerResponse,
            'id' => $transactionId,
        ]);

        app_complete_collection_transaction($db, $transactionId, $bankAccountId, $descriptionPrefix . ' / ' . (string) ($transaction['transaction_ref'] ?? $transactionId));
        return $newStatus;
    }

    if ($newStatus === 'hatali') {
        $stmt = $db->prepare("
            UPDATE collections_transactions
            SET status = 'hatali',
                provider_status = :provider_status,
                provider_response = :provider_response,
                last_status_check_at = NOW(),
                reconciled_at = NOW(),
                processed_at = NOW()
            WHERE id = :id AND status = 'bekliyor'
        ");
        $stmt->execute([
            'provider_status' => $providerStatusValue,
            'provider_response' => $providerResponse,
            'id' => $transactionId,
        ]);

        return $newStatus;
    }

    $stmt = $db->prepare('
        UPDATE collections_transactions
        SET provider_status = :provider_status,
            provider_response = :provider_response,
            last_status_check_at = NOW()
        WHERE id = :id
    ');
    $stmt->execute([
        'provider_status' => $providerStatusValue,
        'provider_response' => $providerResponse,
        'id' => $transactionId,
    ]);

    return $newStatus;
}

function app_queue_webhook_failure_notification(PDO $db, ?int $webhookLogId, string $message, string $transactionRef = '', string $providerStatus = '', int $httpStatus = 0): void
{
    $recipientEmail = trim(app_setting($db, 'notifications.webhook_alert_email', ''));
    $recipientName = 'Sistem Yoneticisi';

    if ($recipientEmail === '') {
        $rows = app_fetch_all($db, '
            SELECT full_name, email
            FROM core_users
            WHERE status = 1 AND email <> ""
            ORDER BY id ASC
            LIMIT 1
        ');
        if ($rows) {
            $recipientEmail = trim((string) ($rows[0]['email'] ?? ''));
            $recipientName = trim((string) ($rows[0]['full_name'] ?? '')) ?: $recipientName;
        }
    }

    if ($recipientEmail === '') {
        $recipientEmail = trim(app_setting($db, 'notifications.smtp_from_email', ''));
    }

    if ($recipientEmail === '') {
        return;
    }

    $subject = 'POS webhook uyarisi';
    $body = "POS webhook bildirimi dikkat gerektiriyor.\n";
    $body .= 'Mesaj: ' . $message . "\n";
    if ($transactionRef !== '') {
        $body .= 'Ref: ' . $transactionRef . "\n";
    }
    if ($providerStatus !== '') {
        $body .= 'Saglayici durumu: ' . $providerStatus . "\n";
    }
    if ($httpStatus > 0) {
        $body .= 'HTTP: ' . $httpStatus . "\n";
    }
    if ($webhookLogId !== null && $webhookLogId > 0) {
        $body .= 'Log: collections_detail.php?type=webhook&id=' . $webhookLogId . "\n";
    }
    $body .= 'Tarih: ' . date('Y-m-d H:i:s');

    app_queue_notification($db, [
        'module_name' => 'tahsilat',
        'notification_type' => 'webhook_error',
        'source_table' => 'collections_webhook_logs',
        'source_id' => $webhookLogId,
        'channel' => 'email',
        'recipient_name' => $recipientName,
        'recipient_contact' => $recipientEmail,
        'subject_line' => $subject,
        'message_body' => $body,
        'status' => 'pending',
        'planned_at' => date('Y-m-d H:i:s'),
        'unique_key' => 'webhook_error_' . ($webhookLogId ?: sha1($transactionRef . $message . date('YmdHi'))),
        'provider_name' => 'webhook_alert',
    ]);
}

function app_notifications_ensure_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS notification_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            module_name VARCHAR(50) NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            source_table VARCHAR(100) NULL,
            source_id BIGINT NULL,
            channel ENUM('email','sms','push') NOT NULL,
            recipient_name VARCHAR(200) NULL,
            recipient_contact VARCHAR(200) NOT NULL,
            subject_line VARCHAR(255) NULL,
            message_body TEXT NOT NULL,
            status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
            planned_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            unique_key VARCHAR(190) NULL,
            provider_name VARCHAR(100) NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notification_unique_key (unique_key),
            KEY idx_notification_status (status, planned_at),
            KEY idx_notification_source (source_table, source_id),
            KEY idx_notification_module (module_name, notification_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $db->exec("ALTER TABLE notification_queue MODIFY channel ENUM('email','sms','push') NOT NULL");
    } catch (Throwable $e) {
        // Older installs may already have the updated enum.
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS notification_push_subscriptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash VARCHAR(64) NOT NULL,
            public_key TEXT NULL,
            auth_token TEXT NULL,
            user_agent TEXT NULL,
            status ENUM('active','passive') NOT NULL DEFAULT 'active',
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
            KEY idx_push_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS notification_preferences (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            module_name VARCHAR(50) NOT NULL,
            notification_type VARCHAR(80) NOT NULL DEFAULT '*',
            email_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sms_enabled TINYINT(1) NOT NULL DEFAULT 1,
            push_enabled TINYINT(1) NOT NULL DEFAULT 1,
            quiet_start TIME NULL,
            quiet_end TIME NULL,
            status ENUM('active','passive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notification_preference_scope (user_id, module_name, notification_type),
            KEY idx_notification_preference_scope (module_name, notification_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function app_notification_setting_bool(PDO $db, string $key, bool $default = false): bool
{
    $fallback = $default ? '1' : '0';
    $value = strtolower(trim(app_setting($db, $key, $fallback)));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function app_notification_runner_token(PDO $db): string
{
    $existing = trim(app_setting($db, 'notifications.runner_token', ''));
    if ($existing !== '') {
        return $existing;
    }

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $token = sha1((string) microtime(true) . (string) mt_rand());
    }

    app_set_setting($db, 'notifications.runner_token', $token, 'bildirim');

    return $token;
}

function app_notification_render_template(string $template, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string) $value;
    }

    return strtr($template, $replacements);
}

function app_smtp_read_response($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $response .= $line;

        if (strlen($line) < 4) {
            break;
        }

        if (substr($line, 3, 1) === ' ') {
            break;
        }
    }

    return $response;
}

function app_smtp_expect(string $response, array $codes): void
{
    $prefix = substr(trim($response), 0, 3);
    if (!in_array($prefix, $codes, true)) {
        throw new RuntimeException('SMTP hatasi: ' . trim($response));
    }
}

function app_smtp_write($socket, string $command): void
{
    $written = fwrite($socket, $command . "\r\n");
    if ($written === false) {
        throw new RuntimeException('SMTP komutu yazilamadi.');
    }
}

function app_send_email_smtp(array $config, string $toEmail, string $toName, string $subject, string $message): void
{
    $host = trim((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 587);
    $security = trim((string) ($config['security'] ?? 'tls')) ?: 'tls';
    $username = trim((string) ($config['username'] ?? ''));
    $password = (string) ($config['password'] ?? '');
    $fromEmail = trim((string) ($config['from_email'] ?? ''));
    $fromName = trim((string) ($config['from_name'] ?? ''));
    $timeout = (int) ($config['timeout'] ?? 15);

    if ($host === '' || $fromEmail === '') {
        throw new RuntimeException('SMTP host ve gonderen e-posta zorunludur.');
    }

    $transport = $host . ':' . $port;
    if ($security === 'ssl') {
        $transport = 'ssl://' . $transport;
    }

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($transport, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP baglantisi kurulamadi: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, $timeout);

    try {
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['220']);

        app_smtp_write($socket, 'EHLO localhost');
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['250']);

        if ($security === 'tls') {
            app_smtp_write($socket, 'STARTTLS');
            $response = app_smtp_read_response($socket);
            app_smtp_expect($response, ['220']);

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('STARTTLS baslatilamadi.');
            }

            app_smtp_write($socket, 'EHLO localhost');
            $response = app_smtp_read_response($socket);
            app_smtp_expect($response, ['250']);
        }

        if ($username !== '') {
            app_smtp_write($socket, 'AUTH LOGIN');
            $response = app_smtp_read_response($socket);
            app_smtp_expect($response, ['334']);

            app_smtp_write($socket, base64_encode($username));
            $response = app_smtp_read_response($socket);
            app_smtp_expect($response, ['334']);

            app_smtp_write($socket, base64_encode($password));
            $response = app_smtp_read_response($socket);
            app_smtp_expect($response, ['235']);
        }

        app_smtp_write($socket, 'MAIL FROM:<' . $fromEmail . '>');
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['250']);

        app_smtp_write($socket, 'RCPT TO:<' . $toEmail . '>');
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['250', '251']);

        app_smtp_write($socket, 'DATA');
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['354']);

        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $fromHeader = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
        $toHeader = $toName !== '' ? $toName . ' <' . $toEmail . '>' : $toEmail;
        $body = str_replace(["\r\n", "\r"], "\n", $message);
        $body = preg_replace('/^\./m', '..', $body);

        $payload = '';
        $payload .= 'From: ' . $fromHeader . "\r\n";
        $payload .= 'To: ' . $toHeader . "\r\n";
        $payload .= 'Subject: ' . $safeSubject . "\r\n";
        $payload .= "MIME-Version: 1.0\r\n";
        $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $payload .= "Content-Transfer-Encoding: 8bit\r\n";
        $payload .= "\r\n";
        $payload .= str_replace("\n", "\r\n", $body) . "\r\n.";

        fwrite($socket, $payload . "\r\n");
        $response = app_smtp_read_response($socket);
        app_smtp_expect($response, ['250']);

        app_smtp_write($socket, 'QUIT');
    } finally {
        fclose($socket);
    }
}

function app_send_notification_email(PDO $db, array $row): void
{
    $mode = app_setting($db, 'notifications.email_mode', 'mock');

    if ($mode === 'mock') {
        return;
    }

    if ($mode === 'php_mail') {
        $subject = (string) ($row['subject_line'] ?? '');
        $message = (string) ($row['message_body'] ?? '');
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail((string) $row['recipient_contact'], $subject, $message, $headers);
        if (!$sent) {
            throw new RuntimeException('PHP mail() bildirimi gonderemedi.');
        }

        return;
    }

    if ($mode === 'smtp') {
        app_send_email_smtp([
            'host' => app_setting($db, 'notifications.smtp_host', ''),
            'port' => (int) app_setting($db, 'notifications.smtp_port', '587'),
            'security' => app_setting($db, 'notifications.smtp_security', 'tls'),
            'username' => app_setting($db, 'notifications.smtp_username', ''),
            'password' => app_setting($db, 'notifications.smtp_password', ''),
            'from_email' => app_setting($db, 'notifications.smtp_from_email', ''),
            'from_name' => app_setting($db, 'notifications.smtp_from_name', 'Galancy Bildirim'),
            'timeout' => (int) app_setting($db, 'notifications.smtp_timeout', '15'),
        ], (string) $row['recipient_contact'], (string) ($row['recipient_name'] ?? ''), (string) ($row['subject_line'] ?? ''), (string) ($row['message_body'] ?? ''));

        return;
    }

    throw new RuntimeException('Desteklenmeyen e-posta modu: ' . $mode);
}

function app_send_sms_http_api(array $config, string $phone, string $message): void
{
    $url = trim((string) ($config['url'] ?? ''));
    $method = strtoupper(trim((string) ($config['method'] ?? 'POST')) ?: 'POST');
    $contentType = trim((string) ($config['content_type'] ?? 'application/json')) ?: 'application/json';
    $headerLines = trim((string) ($config['headers'] ?? ''));
    $bodyTemplate = (string) ($config['body_template'] ?? '{"to":"{{phone}}","message":"{{message}}"}');
    $timeout = (int) ($config['timeout'] ?? 15);

    if ($url === '') {
        throw new RuntimeException('SMS API URL zorunludur.');
    }

    $body = app_notification_render_template($bodyTemplate, [
        'phone' => $phone,
        'message' => $message,
    ]);

    $headers = [
        'Content-Type: ' . $contentType,
        'Accept: application/json, text/plain, */*',
    ];

    if ($headerLines !== '') {
        $lines = preg_split("/\r\n|\n|\r/", $headerLines) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $headers[] = $line;
            }
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL baslatilamadi.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('SMS API istegi basarisiz: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('SMS API HTTP hatasi: ' . $statusCode);
        }

        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';

    if ($result === false) {
        throw new RuntimeException('SMS API istegi basarisiz oldu.');
    }

    if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('SMS API durum kodu okunamadi.');
    }

    $statusCode = (int) $matches[1];
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('SMS API HTTP hatasi: ' . $statusCode);
    }
}

function app_http_api_request(array $config, array $payload): array
{
    $url = trim((string) ($config['url'] ?? ''));
    $method = strtoupper(trim((string) ($config['method'] ?? 'POST')) ?: 'POST');
    $contentType = trim((string) ($config['content_type'] ?? 'application/json')) ?: 'application/json';
    $headerLines = trim((string) ($config['headers'] ?? ''));
    $bodyTemplate = (string) ($config['body_template'] ?? '{}');
    $timeout = (int) ($config['timeout'] ?? 15);

    if ($url === '') {
        throw new RuntimeException('HTTP API URL zorunludur.');
    }

    $body = app_notification_render_template($bodyTemplate, $payload);
    $headers = [
        'Content-Type: ' . $contentType,
        'Accept: application/json, text/plain, */*',
    ];

    if ($headerLines !== '') {
        $lines = preg_split("/\r\n|\n|\r/", $headerLines) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $headers[] = $line;
            }
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL baslatilamadi.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP API istegi basarisiz: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody = substr((string) $response, $headerSize);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('HTTP API hatasi: ' . $statusCode);
        }

        return [
            'status_code' => $statusCode,
            'headers' => $rawHeaders,
            'body' => $rawBody,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';

    if ($result === false) {
        throw new RuntimeException('HTTP API istegi basarisiz oldu.');
    }

    if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('HTTP API durum kodu okunamadi.');
    }

    $statusCode = (int) $matches[1];
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('HTTP API hatasi: ' . $statusCode);
    }

    return [
        'status_code' => $statusCode,
        'headers' => implode("\n", $http_response_header ?: []),
        'body' => (string) $result,
    ];
}

function app_edocument_send_invoice(PDO $db, array $invoiceRow): array
{
    $documentType = strtolower(trim((string) ($invoiceRow['edocument_type'] ?? '')));
    $settingsPrefix = $documentType === 'e-arsiv' ? 'earchive' : 'edocument';
    $label = $documentType === 'e-arsiv' ? 'e-Arsiv' : 'e-Fatura';
    $mode = app_setting($db, $settingsPrefix . '.mode', 'mock');
    $payload = [
        'invoice_id' => (string) ($invoiceRow['id'] ?? ''),
        'invoice_no' => (string) ($invoiceRow['invoice_no'] ?? ''),
        'invoice_type' => (string) ($invoiceRow['invoice_type'] ?? ''),
        'grand_total' => number_format((float) ($invoiceRow['grand_total'] ?? 0), 2, '.', ''),
        'currency_code' => (string) ($invoiceRow['currency_code'] ?? 'TRY'),
        'cari_name' => (string) (($invoiceRow['company_name'] ?? '') ?: ($invoiceRow['full_name'] ?? '')),
        'uuid' => (string) ($invoiceRow['edocument_uuid'] ?? ''),
    ];

    if ($mode === 'mock') {
        return [
            'uuid' => strtoupper($settingsPrefix) . '-' . date('YmdHis') . '-' . str_pad((string) ($invoiceRow['id'] ?? 0), 6, '0', STR_PAD_LEFT),
            'status' => 'gonderildi',
            'response' => 'Mock ' . $label . ' gonderimi basarili.',
        ];
    }

    if ($mode === 'http_api') {
        $response = app_http_api_request([
            'url' => app_setting($db, $settingsPrefix . '.api_url', ''),
            'method' => app_setting($db, $settingsPrefix . '.api_method', 'POST'),
            'content_type' => app_setting($db, $settingsPrefix . '.api_content_type', 'application/json'),
            'headers' => app_setting($db, $settingsPrefix . '.api_headers', ''),
            'body_template' => app_setting($db, $settingsPrefix . '.api_body', '{}'),
            'timeout' => (int) app_setting($db, $settingsPrefix . '.timeout', '15'),
        ], $payload);

        return [
            'uuid' => $payload['uuid'] !== '' ? $payload['uuid'] : (strtoupper($settingsPrefix) . '-' . (string) ($invoiceRow['id'] ?? 0)),
            'status' => 'gonderildi',
            'response' => (string) ($response['body'] ?? ''),
        ];
    }

    throw new RuntimeException('Desteklenmeyen e-donusum modu: ' . $mode);
}

function app_edocument_query_invoice(PDO $db, array $invoiceRow): array
{
    $documentType = strtolower(trim((string) ($invoiceRow['edocument_type'] ?? '')));
    $settingsPrefix = $documentType === 'e-arsiv' ? 'earchive' : 'edocument';
    $label = $documentType === 'e-arsiv' ? 'e-Arsiv' : 'e-Fatura';
    $mode = app_setting($db, $settingsPrefix . '.mode', 'mock');
    $payload = [
        'invoice_id' => (string) ($invoiceRow['id'] ?? ''),
        'invoice_no' => (string) ($invoiceRow['invoice_no'] ?? ''),
        'uuid' => (string) ($invoiceRow['edocument_uuid'] ?? ''),
    ];

    if ($mode === 'mock') {
        $currentStatus = strtolower(trim((string) ($invoiceRow['edocument_status'] ?? '')));
        $nextStatus = in_array($currentStatus, ['gonderildi', 'bekliyor', 'taslak'], true) ? 'kabul' : 'onaylandi';
        return [
            'status' => $nextStatus,
            'response' => 'Mock ' . $label . ' durum sorgusu: ' . $nextStatus,
        ];
    }

    if ($mode === 'http_api') {
        $response = app_http_api_request([
            'url' => app_setting($db, $settingsPrefix . '.status_url', ''),
            'method' => app_setting($db, $settingsPrefix . '.status_method', 'POST'),
            'content_type' => app_setting($db, $settingsPrefix . '.status_content_type', 'application/json'),
            'headers' => app_setting($db, $settingsPrefix . '.status_headers', ''),
            'body_template' => app_setting($db, $settingsPrefix . '.status_body', '{}'),
            'timeout' => (int) app_setting($db, $settingsPrefix . '.timeout', '15'),
        ], $payload);

        return [
            'status' => 'sorgulandi',
            'response' => (string) ($response['body'] ?? ''),
        ];
    }

    throw new RuntimeException('Desteklenmeyen e-donusum modu: ' . $mode);
}

function app_edispatch_send_shipment(PDO $db, array $shipmentRow): array
{
    $mode = app_setting($db, 'edispatch.mode', 'mock');
    $payload = [
        'shipment_id' => (string) ($shipmentRow['id'] ?? ''),
        'shipment_no' => (string) ($shipmentRow['shipment_no'] ?? ''),
        'irsaliye_no' => (string) ($shipmentRow['irsaliye_no'] ?? ''),
        'order_no' => (string) ($shipmentRow['order_no'] ?? ''),
        'cari_name' => (string) (($shipmentRow['company_name'] ?? '') ?: ($shipmentRow['full_name'] ?? '')),
        'uuid' => (string) ($shipmentRow['edispatch_uuid'] ?? ''),
    ];

    if ($mode === 'mock') {
        return [
            'uuid' => 'EDISP-' . date('YmdHis') . '-' . str_pad((string) ($shipmentRow['id'] ?? 0), 6, '0', STR_PAD_LEFT),
            'status' => 'gonderildi',
            'response' => 'Mock e-Irsaliye gonderimi basarili.',
        ];
    }

    if ($mode === 'http_api') {
        $response = app_http_api_request([
            'url' => app_setting($db, 'edispatch.api_url', ''),
            'method' => app_setting($db, 'edispatch.api_method', 'POST'),
            'content_type' => app_setting($db, 'edispatch.api_content_type', 'application/json'),
            'headers' => app_setting($db, 'edispatch.api_headers', ''),
            'body_template' => app_setting($db, 'edispatch.api_body', '{}'),
            'timeout' => (int) app_setting($db, 'edispatch.timeout', '15'),
        ], $payload);

        return [
            'uuid' => $payload['uuid'] !== '' ? $payload['uuid'] : ('EDISP-' . (string) ($shipmentRow['id'] ?? 0)),
            'status' => 'gonderildi',
            'response' => (string) ($response['body'] ?? ''),
        ];
    }

    throw new RuntimeException('Desteklenmeyen e-irsaliye modu: ' . $mode);
}

function app_edispatch_query_shipment(PDO $db, array $shipmentRow): array
{
    $mode = app_setting($db, 'edispatch.mode', 'mock');
    $payload = [
        'shipment_id' => (string) ($shipmentRow['id'] ?? ''),
        'shipment_no' => (string) ($shipmentRow['shipment_no'] ?? ''),
        'irsaliye_no' => (string) ($shipmentRow['irsaliye_no'] ?? ''),
        'uuid' => (string) ($shipmentRow['edispatch_uuid'] ?? ''),
    ];

    if ($mode === 'mock') {
        $currentStatus = strtolower(trim((string) ($shipmentRow['edispatch_status'] ?? '')));
        $nextStatus = in_array($currentStatus, ['gonderildi', 'bekliyor', 'taslak'], true) ? 'kabul' : 'onaylandi';
        return [
            'status' => $nextStatus,
            'response' => 'Mock e-Irsaliye durum sorgusu: ' . $nextStatus,
        ];
    }

    if ($mode === 'http_api') {
        $response = app_http_api_request([
            'url' => app_setting($db, 'edispatch.status_url', ''),
            'method' => app_setting($db, 'edispatch.status_method', 'POST'),
            'content_type' => app_setting($db, 'edispatch.status_content_type', 'application/json'),
            'headers' => app_setting($db, 'edispatch.status_headers', ''),
            'body_template' => app_setting($db, 'edispatch.status_body', '{}'),
            'timeout' => (int) app_setting($db, 'edispatch.timeout', '15'),
        ], $payload);

        return [
            'status' => 'sorgulandi',
            'response' => (string) ($response['body'] ?? ''),
        ];
    }

    throw new RuntimeException('Desteklenmeyen e-irsaliye modu: ' . $mode);
}

function app_send_notification_sms(PDO $db, array $row): void
{
    $mode = app_setting($db, 'notifications.sms_mode', 'mock');

    if ($mode === 'mock') {
        return;
    }

    if ($mode === 'http_api') {
        app_send_sms_http_api([
            'url' => app_setting($db, 'notifications.sms_api_url', ''),
            'method' => app_setting($db, 'notifications.sms_api_method', 'POST'),
            'content_type' => app_setting($db, 'notifications.sms_api_content_type', 'application/json'),
            'headers' => app_setting($db, 'notifications.sms_api_headers', ''),
            'body_template' => app_setting($db, 'notifications.sms_api_body', '{"to":"{{phone}}","message":"{{message}}"}'),
            'timeout' => (int) app_setting($db, 'notifications.sms_api_timeout', '15'),
        ], (string) $row['recipient_contact'], (string) ($row['message_body'] ?? ''));

        return;
    }

    throw new RuntimeException('Desteklenmeyen SMS modu: ' . $mode);
}

function app_send_notification_push(PDO $db, array $row): void
{
    $mode = app_setting($db, 'notifications.push_mode', 'mock');

    if ($mode === 'mock') {
        return;
    }

    throw new RuntimeException('Gercek Web Push icin VAPID anahtarlari ve push saglayici imzasi gerekir.');
}

function app_notification_channel_allowed(PDO $db, string $moduleName, string $notificationType, string $channel, ?int $userId = null): bool
{
    app_notifications_ensure_schema($db);

    $rows = app_fetch_all($db, '
        SELECT user_id, module_name, notification_type, email_enabled, sms_enabled, push_enabled, quiet_start, quiet_end
        FROM notification_preferences
        WHERE (user_id IS NULL OR user_id = :user_id)
          AND status = "active"
          AND module_name IN (:module_name, "*")
          AND notification_type IN (:notification_type, "*")
        ORDER BY
          CASE WHEN user_id = :user_id_exact THEN 0 ELSE 1 END,
          CASE WHEN module_name = :module_name_exact THEN 0 ELSE 1 END,
          CASE WHEN notification_type = :notification_type_exact THEN 0 ELSE 1 END
        LIMIT 1
    ', [
        'user_id' => $userId,
        'user_id_exact' => $userId,
        'module_name' => $moduleName,
        'module_name_exact' => $moduleName,
        'notification_type' => $notificationType,
        'notification_type_exact' => $notificationType,
    ]);

    if (!$rows) {
        return true;
    }

    $preference = $rows[0];
    $enabledMap = [
        'email' => (int) $preference['email_enabled'] === 1,
        'sms' => (int) $preference['sms_enabled'] === 1,
        'push' => (int) $preference['push_enabled'] === 1,
    ];

    if (!($enabledMap[$channel] ?? true)) {
        return false;
    }

    $quietStart = trim((string) ($preference['quiet_start'] ?? ''));
    $quietEnd = trim((string) ($preference['quiet_end'] ?? ''));
    if ($quietStart !== '' && $quietEnd !== '') {
        $now = date('H:i:s');
        if ($quietStart <= $quietEnd && $now >= $quietStart && $now <= $quietEnd) {
            return false;
        }

        if ($quietStart > $quietEnd && ($now >= $quietStart || $now <= $quietEnd)) {
            return false;
        }
    }

    return true;
}

function app_queue_notification(PDO $db, array $data): bool
{
    app_notifications_ensure_schema($db);

    $recipientUserId = isset($data['recipient_user_id']) ? (int) $data['recipient_user_id'] : null;
    if (!app_notification_channel_allowed($db, (string) $data['module_name'], (string) $data['notification_type'], (string) $data['channel'], $recipientUserId)) {
        return false;
    }

    $stmt = $db->prepare('
        INSERT INTO notification_queue (
            module_name, notification_type, source_table, source_id, channel, recipient_name, recipient_contact,
            subject_line, message_body, status, planned_at, unique_key, provider_name
        ) VALUES (
            :module_name, :notification_type, :source_table, :source_id, :channel, :recipient_name, :recipient_contact,
            :subject_line, :message_body, :status, :planned_at, :unique_key, :provider_name
        )
    ');

    try {
        $stmt->execute([
            'module_name' => $data['module_name'],
            'notification_type' => $data['notification_type'],
            'source_table' => $data['source_table'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'channel' => $data['channel'],
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_contact' => $data['recipient_contact'],
            'subject_line' => $data['subject_line'] ?? null,
            'message_body' => $data['message_body'],
            'status' => $data['status'] ?? 'pending',
            'planned_at' => $data['planned_at'] ?? date('Y-m-d H:i:s'),
            'unique_key' => $data['unique_key'] ?? null,
            'provider_name' => $data['provider_name'] ?? null,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function app_generate_notification_queue(PDO $db): array
{
    app_notifications_ensure_schema($db);

    $results = [
        'crm' => 0,
        'rental' => 0,
        'invoice' => 0,
        'pos_report' => 0,
        'skipped' => 0,
    ];

    $emailEnabled = app_notification_setting_bool($db, 'notifications.email_enabled', true);
    $smsEnabled = app_notification_setting_bool($db, 'notifications.sms_enabled', false);

    $crmEmailSubject = app_setting($db, 'notifications.crm_email_subject', 'CRM Hatirlatma / {{company_name}}');
    $crmEmailBody = app_setting($db, 'notifications.crm_email_body', "Merhaba,\n\n{{reminder_text}}\nTarih: {{remind_at}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi");
    $crmSmsBody = app_setting($db, 'notifications.crm_sms_body', '{{company_name}} icin hatirlatma: {{reminder_text}} / {{remind_at}}');

    $rentalEmailSubject = app_setting($db, 'notifications.rental_email_subject', 'Gecikmis Kira Tahsilati / {{contract_no}}');
    $rentalEmailBody = app_setting($db, 'notifications.rental_email_body', "Merhaba,\n\n{{contract_no}} sozlesmesine ait {{amount}} tutarli kira tahsilati gecikmistir.\nVade: {{due_date}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi");
    $rentalSmsBody = app_setting($db, 'notifications.rental_sms_body', '{{contract_no}} sozlesmesi icin {{due_date}} vadeli {{amount}} tutarli kira odemesi gecikmistir.');
    $invoiceReminderDays = max(0, (int) app_setting($db, 'notifications.invoice_due_reminder_days', '3'));
    $invoiceDueUntil = date('Y-m-d', strtotime('+' . $invoiceReminderDays . ' days'));
    $invoiceEmailSubject = app_setting($db, 'notifications.invoice_email_subject', 'Fatura Vade Hatirlatmasi / {{invoice_no}}');
    $invoiceEmailBody = app_setting($db, 'notifications.invoice_email_body', "Merhaba,\n\n{{invoice_no}} numarali {{remaining_total}} tutarli faturanin vadesi {{due_date}} tarihindedir.\nCari: {{company_name}}\nDurum: {{payment_status}}\n\nGalancy Bildirim Merkezi");
    $invoiceSmsBody = app_setting($db, 'notifications.invoice_sms_body', '{{invoice_no}} numarali {{remaining_total}} tutarli fatura icin vade {{due_date}} / Durum: {{payment_status}}');

    $crmRows = app_fetch_all($db, "
        SELECT r.id, r.reminder_text, r.remind_at, r.status, c.company_name, c.full_name, c.email, c.phone
        FROM crm_reminders r
        INNER JOIN cari_cards c ON c.id = r.cari_id
        WHERE r.status = 'bekliyor' AND r.remind_at <= NOW()
        ORDER BY r.remind_at ASC
    ");

    foreach ($crmRows as $row) {
        $vars = [
            'company_name' => (string) ($row['company_name'] ?: $row['full_name'] ?: '-'),
            'reminder_text' => (string) $row['reminder_text'],
            'remind_at' => (string) $row['remind_at'],
        ];

        if ($emailEnabled && trim((string) ($row['email'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'crm',
                'notification_type' => 'crm_reminder',
                'source_table' => 'crm_reminders',
                'source_id' => (int) $row['id'],
                'channel' => 'email',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['email']),
                'subject_line' => app_notification_render_template($crmEmailSubject, $vars),
                'message_body' => app_notification_render_template($crmEmailBody, $vars),
                'unique_key' => 'crm-email-' . (int) $row['id'],
                'provider_name' => app_setting($db, 'notifications.email_mode', 'mock'),
            ])) {
                $results['crm']++;
            } else {
                $results['skipped']++;
            }
        }

        if ($smsEnabled && trim((string) ($row['phone'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'crm',
                'notification_type' => 'crm_reminder',
                'source_table' => 'crm_reminders',
                'source_id' => (int) $row['id'],
                'channel' => 'sms',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['phone']),
                'subject_line' => null,
                'message_body' => app_notification_render_template($crmSmsBody, $vars),
                'unique_key' => 'crm-sms-' . (int) $row['id'],
                'provider_name' => app_setting($db, 'notifications.sms_mode', 'mock'),
            ])) {
                $results['crm']++;
            } else {
                $results['skipped']++;
            }
        }
    }

    $today = date('Y-m-d');
    $rentalRows = app_fetch_all($db, "
        SELECT p.id, p.due_date, p.amount, p.status, r.contract_no, c.company_name, c.full_name, c.email, c.phone
        FROM rental_payments p
        INNER JOIN rental_contracts r ON r.id = p.contract_id
        INNER JOIN cari_cards c ON c.id = r.cari_id
        WHERE p.status IN ('bekliyor', 'gecikmis') AND p.due_date < CURDATE()
        ORDER BY p.due_date ASC
    ");

    foreach ($rentalRows as $row) {
        $vars = [
            'company_name' => (string) ($row['company_name'] ?: $row['full_name'] ?: '-'),
            'contract_no' => (string) $row['contract_no'],
            'due_date' => (string) $row['due_date'],
            'amount' => number_format((float) $row['amount'], 2, ',', '.') . ' TRY',
        ];

        if ($emailEnabled && trim((string) ($row['email'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'kira',
                'notification_type' => 'rental_overdue',
                'source_table' => 'rental_payments',
                'source_id' => (int) $row['id'],
                'channel' => 'email',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['email']),
                'subject_line' => app_notification_render_template($rentalEmailSubject, $vars),
                'message_body' => app_notification_render_template($rentalEmailBody, $vars),
                'unique_key' => 'rental-email-' . (int) $row['id'] . '-' . $today,
                'provider_name' => app_setting($db, 'notifications.email_mode', 'mock'),
            ])) {
                $results['rental']++;
            } else {
                $results['skipped']++;
            }
        }

        if ($smsEnabled && trim((string) ($row['phone'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'kira',
                'notification_type' => 'rental_overdue',
                'source_table' => 'rental_payments',
                'source_id' => (int) $row['id'],
                'channel' => 'sms',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['phone']),
                'subject_line' => null,
                'message_body' => app_notification_render_template($rentalSmsBody, $vars),
                'unique_key' => 'rental-sms-' . (int) $row['id'] . '-' . $today,
                'provider_name' => app_setting($db, 'notifications.sms_mode', 'mock'),
            ])) {
                $results['rental']++;
            } else {
                $results['skipped']++;
            }
        }
    }

    $invoiceRows = app_fetch_all($db, "
        SELECT h.id, h.invoice_no, h.invoice_date, h.due_date, h.grand_total, h.paid_total, h.payment_status, h.currency_code, c.company_name, c.full_name, c.email, c.phone
        FROM invoice_headers h
        INNER JOIN cari_cards c ON c.id = h.cari_id
        WHERE h.invoice_type = 'satis'
          AND h.payment_status <> 'odendi'
          AND h.due_date IS NOT NULL
          AND h.due_date <= :invoice_due_until
        ORDER BY h.due_date ASC
    ", ['invoice_due_until' => $invoiceDueUntil]);

    foreach ($invoiceRows as $row) {
        $remaining = max(0, (float) $row['grand_total'] - (float) $row['paid_total']);
        if ($remaining <= 0) {
            continue;
        }

        $isOverdue = ((string) $row['due_date']) < $today;
        $daysToDue = (int) floor((strtotime((string) $row['due_date']) - strtotime($today)) / 86400);
        $vars = [
            'company_name' => (string) ($row['company_name'] ?: $row['full_name'] ?: '-'),
            'invoice_no' => (string) $row['invoice_no'],
            'invoice_date' => (string) $row['invoice_date'],
            'due_date' => (string) $row['due_date'],
            'grand_total' => number_format((float) $row['grand_total'], 2, ',', '.') . ' ' . ($row['currency_code'] ?: 'TRY'),
            'paid_total' => number_format((float) $row['paid_total'], 2, ',', '.') . ' ' . ($row['currency_code'] ?: 'TRY'),
            'remaining_total' => number_format($remaining, 2, ',', '.') . ' ' . ($row['currency_code'] ?: 'TRY'),
            'payment_status' => $isOverdue ? 'Vadesi Gecmis' : ($daysToDue === 0 ? 'Bugun Vadeli' : ($daysToDue . ' gun kaldi')),
            'days_to_due' => (string) $daysToDue,
        ];
        $typeKey = $isOverdue ? 'invoice_overdue' : 'invoice_due';
        $uniqueSuffix = (int) $row['id'] . '-' . $today;

        if ($emailEnabled && trim((string) ($row['email'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'fatura',
                'notification_type' => $typeKey,
                'source_table' => 'invoice_headers',
                'source_id' => (int) $row['id'],
                'channel' => 'email',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['email']),
                'subject_line' => app_notification_render_template($invoiceEmailSubject, $vars),
                'message_body' => app_notification_render_template($invoiceEmailBody, $vars),
                'unique_key' => 'invoice-email-' . $typeKey . '-' . $uniqueSuffix,
                'provider_name' => app_setting($db, 'notifications.email_mode', 'mock'),
            ])) {
                $results['invoice']++;
            } else {
                $results['skipped']++;
            }
        }

        if ($smsEnabled && trim((string) ($row['phone'] ?? '')) !== '') {
            if (app_queue_notification($db, [
                'module_name' => 'fatura',
                'notification_type' => $typeKey,
                'source_table' => 'invoice_headers',
                'source_id' => (int) $row['id'],
                'channel' => 'sms',
                'recipient_name' => $vars['company_name'],
                'recipient_contact' => trim((string) $row['phone']),
                'subject_line' => null,
                'message_body' => app_notification_render_template($invoiceSmsBody, $vars),
                'unique_key' => 'invoice-sms-' . $typeKey . '-' . $uniqueSuffix,
                'provider_name' => app_setting($db, 'notifications.sms_mode', 'mock'),
            ])) {
                $results['invoice']++;
            } else {
                $results['skipped']++;
            }
        }
    }

    $posReportEnabled = app_notification_setting_bool($db, 'notifications.pos_report_enabled', false);
    $posReportEmail = trim(app_setting($db, 'notifications.pos_report_email', ''));

    if ($emailEnabled && $posReportEnabled) {
        if ($posReportEmail === '') {
            $posReportEmail = trim(app_setting($db, 'notifications.webhook_alert_email', ''));
        }

        if ($posReportEmail === '') {
            $rows = app_fetch_all($db, 'SELECT email FROM core_users WHERE status = 1 AND email <> "" ORDER BY id ASC LIMIT 1');
            if ($rows) {
                $posReportEmail = trim((string) ($rows[0]['email'] ?? ''));
            }
        }

        if ($posReportEmail !== '') {
            $baseUrl = rtrim(app_base_url(), '/');
            $successfulTotal = app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'basarili'");
            $pendingTotal = app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'bekliyor'");
            $refundTotal = app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'iade'");
            $openWebhookTotal = app_metric($db, "SELECT COUNT(*) FROM collections_webhook_logs WHERE verification_status = 'hatali' AND resolved_at IS NULL");
            $collectedTotal = number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM collections_transactions WHERE status = 'basarili'"), 2, ',', '.');
            $refundAmount = number_format((float) app_metric($db, "SELECT COALESCE(SUM(refunded_amount),0) FROM collections_transactions"), 2, ',', '.');
            $commissionTotal = number_format((float) app_metric($db, "SELECT COALESCE(SUM(commission_amount),0) FROM collections_transactions WHERE status IN ('basarili','iade')"), 2, ',', '.');

            $body = "Gunluk POS mutabakat raporu hazirlandi.\n\n";
            $body .= 'Basarili islem: ' . $successfulTotal . "\n";
            $body .= 'Bekleyen islem: ' . $pendingTotal . "\n";
            $body .= 'Iade islem: ' . $refundTotal . "\n";
            $body .= 'Acik webhook alarmi: ' . $openWebhookTotal . "\n";
            $body .= 'Toplam tahsilat: ' . $collectedTotal . " TRY\n";
            $body .= 'Toplam iade: ' . $refundAmount . " TRY\n";
            $body .= 'Toplam komisyon: ' . $commissionTotal . " TRY\n\n";
            $body .= 'PDF / yazdir ciktisi: ' . $baseUrl . "/print.php?type=pos_reconciliation_report\n";
            $body .= 'Rapor tarihi: ' . date('Y-m-d H:i:s');

            if (app_queue_notification($db, [
                'module_name' => 'rapor',
                'notification_type' => 'pos_reconciliation_report',
                'source_table' => 'collections_transactions',
                'source_id' => null,
                'channel' => 'email',
                'recipient_name' => 'POS Mutabakat Alicisi',
                'recipient_contact' => $posReportEmail,
                'subject_line' => 'Gunluk POS Mutabakat Raporu / ' . date('Y-m-d'),
                'message_body' => $body,
                'unique_key' => 'pos-report-email-' . date('Y-m-d') . '-' . sha1($posReportEmail),
                'provider_name' => app_setting($db, 'notifications.email_mode', 'mock'),
            ])) {
                $results['pos_report']++;
            } else {
                $results['skipped']++;
            }
        } else {
            $results['skipped']++;
        }
    }

    app_set_setting($db, 'notifications.last_scan_at', date('Y-m-d H:i:s'), 'bildirim');

    return $results;
}

function app_process_notification_queue(PDO $db, int $limit = 25): array
{
    app_notifications_ensure_schema($db);

    $results = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
    ];

    $rows = app_fetch_all($db, '
        SELECT *
        FROM notification_queue
        WHERE status = :status AND planned_at <= :planned_at
        ORDER BY planned_at ASC, id ASC
        LIMIT ' . max(1, (int) $limit),
        [
            'status' => 'pending',
            'planned_at' => date('Y-m-d H:i:s'),
        ]
    );

    $update = $db->prepare('
        UPDATE notification_queue
        SET status = :status, processed_at = :processed_at, last_error = :last_error
        WHERE id = :id
    ');

    foreach ($rows as $row) {
        $results['processed']++;
        $status = 'sent';
        $lastError = null;

        try {
            if ($row['channel'] === 'email') {
                app_send_notification_email($db, $row);
            }

            if ($row['channel'] === 'sms') {
                app_send_notification_sms($db, $row);
            }

            if ($row['channel'] === 'push') {
                app_send_notification_push($db, $row);
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $lastError = $e->getMessage();
        }

        $update->execute([
            'status' => $status,
            'processed_at' => date('Y-m-d H:i:s'),
            'last_error' => $lastError,
            'id' => (int) $row['id'],
        ]);

        if ($status === 'sent') {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
    }

    if ($results['processed'] > 0) {
        app_set_setting($db, 'notifications.last_process_at', date('Y-m-d H:i:s'), 'bildirim');
    }

    return $results;
}
