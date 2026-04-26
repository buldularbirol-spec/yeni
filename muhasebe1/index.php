<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$authUser = app_require_auth();
$config = app_config();
$modules = app_filter_modules_for_user($authUser, app_modules());
$activeKey = $_GET['module'] ?? 'dashboard';
if (!isset($modules[$activeKey])) {
    $activeKey = 'dashboard';
}
$activeModule = $modules[$activeKey];
$activeView = preg_replace('/[^a-z0-9_-]/i', '', (string) ($_GET['view'] ?? ''));
$db = app_db();
$ready = $db && app_database_ready();
$csrfError = '';
$globalSearchQuery = trim((string) ($_GET['global_q'] ?? ''));
$globalSearchResults = [];
$globalSearchTotal = 0;
$globalSearchNotice = '';
$dashboardPreferenceNotice = '';

function dashboard_rate_cache_path(): string
{
    return __DIR__ . '/storage/exchange_rates.json';
}

function dashboard_fetch_url(string $url): false|string
{
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    if (ini_get('allow_url_fopen')) {
        $result = @file_get_contents($url, false, $context);
        if ($result !== false) {
            return $result;
        }
    }

    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $status >= 200 && $status < 300) {
            return $result;
        }
    }

    return false;
}

function dashboard_fetch_exchange_rates(bool $forceRefresh = false): array
{
    $cachePath = dashboard_rate_cache_path();
    $rates = ['USD' => null, 'EUR' => null];

    if (!$forceRefresh && file_exists($cachePath)) {
        $raw = file_get_contents($cachePath);
        $cached = json_decode($raw !== false ? $raw : '', true);

        if (is_array($cached)
            && isset($cached['timestamp'], $cached['rates'])
            && is_array($cached['rates'])
            && ((int) ($cached['timestamp'] ?? 0) + 900 > time())
        ) {
            return array_replace($rates, array_intersect_key($cached['rates'], $rates));
        }
    }

    foreach (['USD', 'EUR'] as $code) {
        $url = 'https://api.exchangerate.host/latest?base=' . urlencode($code) . '&symbols=TRY';
        $raw = dashboard_fetch_url($url);

        if ($raw !== false) {
            $response = json_decode($raw, true);
            if (is_array($response)
                && isset($response['rates']['TRY'])
                && is_numeric($response['rates']['TRY'])
            ) {
                $rates[$code] = number_format((float) $response['rates']['TRY'], 4, ',', '.');
            }
        }
    }

    @file_put_contents($cachePath, json_encode(['timestamp' => time(), 'rates' => $rates], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $rates;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (Throwable $e) {
        $csrfError = 'Guvenlik dogrulamasi basarisiz oldu. Sayfayi yenileyip tekrar deneyin.';
    }
}

function dashboard_activity_url(array $item): string
{
    $recordId = (int) ($item['record_id'] ?? 0);
    if ($recordId <= 0) {
        return '#';
    }

    return match ((string) ($item['activity_type'] ?? '')) {
        'Fatura' => 'invoice_detail.php?id=' . $recordId,
        'Siparis' => 'sales_detail.php?type=order&id=' . $recordId,
        'Kira' => 'rental_detail.php?id=' . $recordId,
        'Servis' => 'service_detail.php?id=' . $recordId,
        'Tahsilat' => 'collections_detail.php?type=transaction&id=' . $recordId,
        'Uretim' => 'production_detail.php?id=' . $recordId,
        default => '#',
    };
}

function dashboard_alert_url(array $item): string
{
    $recordId = (int) ($item['record_id'] ?? 0);
    $cariId = (int) ($item['related_cari_id'] ?? 0);

    return match ((string) ($item['alert_type'] ?? '')) {
        'Geciken Kira' => $recordId > 0 ? 'rental_detail.php?id=' . $recordId : '#',
        'Acik Link' => $recordId > 0 ? 'collections_detail.php?type=link&id=' . $recordId : '#',
        'Uretim' => $recordId > 0 ? 'production_detail.php?id=' . $recordId : '#',
        'Hatirlatma' => $cariId > 0 ? 'crm_detail.php?id=' . $cariId : '#',
        default => '#',
    };
}

function global_search_add_result(array &$results, string $group, string $title, string $subtitle, string $url): void
{
    if (!isset($results[$group])) {
        $results[$group] = [];
    }

    $results[$group][] = [
        'title' => $title,
        'subtitle' => $subtitle,
        'url' => $url,
    ];
}

function dashboard_preference_defaults(): array
{
    return [
        'stats' => true,
        'finance' => true,
        'operations' => true,
        'activity' => true,
        'alerts' => true,
        'quick_links' => true,
        'management_links' => true,
    ];
}

function dashboard_normalize_preferences($rawValue): array
{
    $defaults = dashboard_preference_defaults();

    if (!is_string($rawValue) || trim($rawValue) === '') {
        return $defaults;
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    foreach ($defaults as $key => $defaultValue) {
        if (array_key_exists($key, $decoded)) {
            $defaults[$key] = (bool) $decoded[$key];
        }
    }

    return $defaults;
}

function dashboard_enabled_sections(array $preferences): array
{
    $labels = [
        'stats' => 'Ust Gosterge Kartlari',
        'finance' => 'Finans Ozeti',
        'operations' => 'Operasyon Sinyalleri',
        'activity' => 'Son Hareket Akisi',
        'alerts' => 'Anlik Uyarilar',
        'quick_links' => 'Hizli Yonlendirme',
        'management_links' => 'Yonetim Kestirmeleri',
    ];

    $enabled = [];
    foreach ($labels as $key => $label) {
        if (!empty($preferences[$key])) {
            $enabled[] = $label;
        }
    }

    return $enabled;
}

$dashboardPreferenceKey = 'dashboard.preferences.user.' . (int) ($authUser['id'] ?? 0);
$dashboardPreferences = dashboard_preference_defaults();

if ($ready) {
    $dashboardPreferences = dashboard_normalize_preferences(app_setting($db, $dashboardPreferenceKey, ''));
}

$forceRateRefresh = isset($_GET['refresh_rates']);
$exchangeRates = dashboard_fetch_exchange_rates($forceRateRefresh);
$refreshRatesUrl = '?module=' . urlencode($activeKey) . ($activeView !== '' ? '&view=' . urlencode($activeView) : '') . '&refresh_rates=1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfError === '' && $ready && $activeKey === 'dashboard') {
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'save_dashboard_preferences') {
        $dashboardPreferences = dashboard_preference_defaults();

        foreach (array_keys($dashboardPreferences) as $preferenceKey) {
            $dashboardPreferences[$preferenceKey] = isset($_POST['dashboard_sections'][$preferenceKey]);
        }

        app_set_setting($db, $dashboardPreferenceKey, json_encode($dashboardPreferences, JSON_UNESCAPED_UNICODE), 'dashboard');

        $enabledSections = dashboard_enabled_sections($dashboardPreferences);
        app_audit_log(
            'core',
            'dashboard_preferences_updated',
            'core_settings',
            null,
            'Kullanici dashboard gorunumu guncellendi: ' . ($enabledSections ? implode(', ', $enabledSections) : 'Tum bolumler kapatildi')
        );

        $dashboardPreferenceNotice = $enabledSections
            ? 'Dashboard gorunumu kaydedildi. Acik bolumler: ' . implode(', ', $enabledSections) . '.'
            : 'Dashboard gorunumu kaydedildi. Tum bolumler gizlendi, dilediginizde yeniden acabilirsiniz.';
    }
}

if ($ready && $globalSearchQuery !== '') {
    $queryLength = function_exists('mb_strlen')
        ? mb_strlen($globalSearchQuery, 'UTF-8')
        : strlen($globalSearchQuery);

    if ($queryLength < 2) {
        $globalSearchNotice = 'Global arama icin en az 2 karakter yazin.';
    } else {
        $searchLike = '%' . $globalSearchQuery . '%';

        if (app_table_exists($db, 'cari_cards')) {
            $rows = app_fetch_all($db, "
                SELECT id, card_type, company_name, full_name, phone, email, city
                FROM cari_cards
                WHERE company_name LIKE :search
                   OR full_name LIKE :search
                   OR phone LIKE :search
                   OR email LIKE :search
                   OR tax_number LIKE :search
                ORDER BY id DESC
                LIMIT 8
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari Kart');
                $subtitle = trim(implode(' | ', array_filter([
                    ucfirst((string) ($row['card_type'] ?: 'cari')),
                    (string) ($row['phone'] ?: ''),
                    (string) ($row['email'] ?: ''),
                    (string) ($row['city'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Cari ve CRM', $title, $subtitle !== '' ? $subtitle : 'Cari kart kaydi', 'cari_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'sales_offers')) {
            $rows = app_fetch_all($db, "
                SELECT o.id, o.offer_no, o.status, o.offer_date, c.company_name, c.full_name
                FROM sales_offers o
                LEFT JOIN cari_cards c ON c.id = o.cari_id
                WHERE o.offer_no LIKE :search
                   OR o.notes LIKE :search
                   OR c.company_name LIKE :search
                   OR c.full_name LIKE :search
                ORDER BY o.id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Teklif / ' . (string) $row['offer_no'];
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari baglantisi'),
                    (string) ($row['offer_date'] ?: ''),
                    (string) ($row['status'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Satis', $title, $subtitle !== '' ? $subtitle : 'Teklif kaydi', 'sales_detail.php?type=offer&id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'sales_orders')) {
            $rows = app_fetch_all($db, "
                SELECT o.id, o.order_no, o.status, o.delivery_status, o.order_date, c.company_name, c.full_name
                FROM sales_orders o
                LEFT JOIN cari_cards c ON c.id = o.cari_id
                WHERE o.order_no LIKE :search
                   OR o.tracking_no LIKE :search
                   OR c.company_name LIKE :search
                   OR c.full_name LIKE :search
                ORDER BY o.id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Siparis / ' . (string) $row['order_no'];
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari baglantisi'),
                    (string) ($row['order_date'] ?: ''),
                    (string) ($row['status'] ?: ''),
                    (string) ($row['delivery_status'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Satis', $title, $subtitle !== '' ? $subtitle : 'Siparis kaydi', 'sales_detail.php?type=order&id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'invoice_headers')) {
            $rows = app_fetch_all($db, "
                SELECT i.id, i.invoice_no, i.invoice_type, i.invoice_date, i.payment_status, c.company_name, c.full_name
                FROM invoice_headers i
                LEFT JOIN cari_cards c ON c.id = i.cari_id
                WHERE i.invoice_no LIKE :search
                   OR i.notes LIKE :search
                   OR c.company_name LIKE :search
                   OR c.full_name LIKE :search
                ORDER BY i.id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Fatura / ' . (string) $row['invoice_no'];
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari baglantisi'),
                    (string) ($row['invoice_date'] ?: ''),
                    (string) ($row['invoice_type'] ?: ''),
                    (string) ($row['payment_status'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Fatura', $title, $subtitle !== '' ? $subtitle : 'Fatura kaydi', 'invoice_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'stock_products')) {
            $rows = app_fetch_all($db, "
                SELECT id, sku, barcode, name, product_type
                FROM stock_products
                WHERE sku LIKE :search
                   OR barcode LIKE :search
                   OR name LIKE :search
                ORDER BY id DESC
                LIMIT 8
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = (string) ($row['name'] ?: 'Stok urunu');
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['sku'] ?: ''),
                    (string) ($row['barcode'] ?: ''),
                    (string) ($row['product_type'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Stok', $title, $subtitle !== '' ? $subtitle : 'Stok kaydi', 'stock_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'service_records')) {
            $rows = app_fetch_all($db, "
                SELECT s.id, s.service_no, s.serial_no, s.complaint, s.opened_at, c.company_name, c.full_name
                FROM service_records s
                LEFT JOIN cari_cards c ON c.id = s.cari_id
                WHERE s.service_no LIKE :search
                   OR s.serial_no LIKE :search
                   OR s.complaint LIKE :search
                   OR c.company_name LIKE :search
                   OR c.full_name LIKE :search
                ORDER BY s.id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Servis / ' . (string) $row['service_no'];
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari baglantisi'),
                    (string) ($row['serial_no'] ?: ''),
                    (string) ($row['opened_at'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Servis', $title, $subtitle !== '' ? $subtitle : 'Servis kaydi', 'service_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'rental_contracts')) {
            $rows = app_fetch_all($db, "
                SELECT r.id, r.contract_no, r.status, r.start_date, r.end_date, c.company_name, c.full_name
                FROM rental_contracts r
                LEFT JOIN cari_cards c ON c.id = r.cari_id
                WHERE r.contract_no LIKE :search
                   OR r.notes LIKE :search
                   OR c.company_name LIKE :search
                   OR c.full_name LIKE :search
                ORDER BY r.id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Kira / ' . (string) $row['contract_no'];
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['company_name'] ?: $row['full_name'] ?: 'Cari baglantisi'),
                    (string) ($row['status'] ?: ''),
                    (string) ($row['start_date'] ?: ''),
                    (string) ($row['end_date'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'Kira', $title, $subtitle !== '' ? $subtitle : 'Kira sozlesmesi', 'rental_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'hr_employees')) {
            $rows = app_fetch_all($db, "
                SELECT id, full_name, title, phone, email, start_date
                FROM hr_employees
                WHERE full_name LIKE :search
                   OR title LIKE :search
                   OR phone LIKE :search
                   OR email LIKE :search
                ORDER BY id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = (string) ($row['full_name'] ?: 'Personel');
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['title'] ?: 'IK kaydi'),
                    (string) ($row['phone'] ?: ''),
                    (string) ($row['email'] ?: ''),
                ])));
                global_search_add_result($globalSearchResults, 'IK', $title, $subtitle !== '' ? $subtitle : 'Personel kaydi', 'hr_detail.php?id=' . (int) $row['id']);
            }
        }

        if (app_table_exists($db, 'core_users')) {
            $rows = app_fetch_all($db, "
                SELECT id, full_name, email, phone, last_login_at, status
                FROM core_users
                WHERE full_name LIKE :search
                   OR email LIKE :search
                   OR phone LIKE :search
                ORDER BY id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = (string) ($row['full_name'] ?: 'Kullanici');
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['email'] ?: ''),
                    (string) ($row['phone'] ?: ''),
                    ((int) ($row['status'] ?? 0) === 1 ? 'aktif' : 'pasif'),
                ])));
                global_search_add_result($globalSearchResults, 'Core', $title, $subtitle !== '' ? $subtitle : 'Kullanici kaydi', 'index.php?module=core');
            }
        }

        if (app_table_exists($db, 'docs_files')) {
            $rows = app_fetch_all($db, "
                SELECT id, module_name, related_table, related_id, file_name, notes
                FROM docs_files
                WHERE file_name LIKE :search
                   OR notes LIKE :search
                ORDER BY id DESC
                LIMIT 6
            ", ['search' => $searchLike]);

            foreach ($rows as $row) {
                $title = 'Evrak / ' . (string) ($row['file_name'] ?: ('DOC#' . (int) $row['id']));
                $subtitle = trim(implode(' | ', array_filter([
                    (string) ($row['module_name'] ?: 'evrak'),
                    (string) ($row['related_table'] ?: ''),
                    (int) ($row['related_id'] ?? 0) > 0 ? '#' . (int) $row['related_id'] : '',
                ])));
                $url = 'index.php?module=evrak&preview_id=' . (int) $row['id'];
                if ((string) ($row['module_name'] ?? '') !== '') {
                    $url .= '&filter_module=' . urlencode((string) $row['module_name']);
                }
                if ((string) ($row['related_table'] ?? '') !== '') {
                    $url .= '&filter_related_table=' . urlencode((string) $row['related_table']);
                }
                if ((int) ($row['related_id'] ?? 0) > 0) {
                    $url .= '&filter_related_id=' . (int) $row['related_id'];
                }

                global_search_add_result($globalSearchResults, 'Evrak', $title, $subtitle !== '' ? $subtitle : 'Arsiv kaydi', $url);
            }
        }

        foreach ($globalSearchResults as $groupRows) {
            $globalSearchTotal += count($groupRows);
        }
    }
}

$stats = [
    'Cari Kartlar' => $ready ? app_table_count($db, 'cari_cards') : 0,
    'Acik Servisler' => $ready ? app_metric($db, "SELECT COUNT(*) FROM service_records WHERE closed_at IS NULL") : '0',
    'Aktif Kiralar' => $ready ? app_metric($db, "SELECT COUNT(*) FROM rental_contracts WHERE status = 'aktif'") : '0',
    'Urunler' => $ready ? app_table_count($db, 'stock_products') : 0,
    'Personel' => $ready ? app_table_count($db, 'hr_employees') : 0,
    'Kullanicilar' => $ready ? app_table_count($db, 'core_users') : 0,
];

$dashboardFinance = [];
$dashboardOperations = [];
$dashboardActivity = [];
$dashboardAlerts = [];
$showDashboardStats = $activeKey !== 'dashboard' || !empty($dashboardPreferences['stats']);
$showDashboardFinance = !empty($dashboardPreferences['finance']);
$showDashboardOperations = !empty($dashboardPreferences['operations']);
$showDashboardActivity = !empty($dashboardPreferences['activity']);
$showDashboardAlerts = !empty($dashboardPreferences['alerts']);
$showDashboardQuickLinks = !empty($dashboardPreferences['quick_links']);
$showDashboardManagementLinks = !empty($dashboardPreferences['management_links']);
$dashboardVisiblePanelCount = count(array_filter($dashboardPreferences));

if ($ready) {
    $dashboardFinance = [
        'Kasa Toplami' => number_format((float) app_metric($db, "
            SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_cash_movements),0)
            FROM finance_cashboxes
        "), 2, ',', '.'),
        'Banka Toplami' => number_format((float) app_metric($db, "
            SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_bank_movements),0)
            FROM finance_bank_accounts
        "), 2, ',', '.'),
        'Aylik Fatura' => number_format((float) app_metric($db, "
            SELECT COALESCE(SUM(grand_total),0) FROM invoice_headers
            WHERE DATE_FORMAT(invoice_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        "), 2, ',', '.'),
        'Bekleyen Kira' => number_format((float) app_metric($db, "
            SELECT COALESCE(SUM(amount),0) FROM rental_payments WHERE status IN ('bekliyor','gecikmis')
        "), 2, ',', '.'),
        'POS Tahsilat' => number_format((float) app_metric($db, "
            SELECT COALESCE(SUM(amount),0) FROM collections_transactions WHERE status = 'basarili'
        "), 2, ',', '.'),
    ];

    $dashboardOperations = [
        'Kritik Stok' => app_metric($db, "
            SELECT COUNT(*) FROM (
                SELECT p.id,
                    COALESCE(SUM(CASE WHEN m.movement_type IN ('giris','transfer','sayim','uretim_giris') THEN m.quantity ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN m.movement_type IN ('cikis','uretim_cikis','servis_cikis','kira_cikis') THEN m.quantity ELSE 0 END), 0) AS current_stock,
                    p.critical_stock
                FROM stock_products p
                LEFT JOIN stock_movements m ON m.product_id = p.id
                GROUP BY p.id, p.critical_stock
            ) x WHERE x.current_stock <= x.critical_stock
        "),
        'Bekleyen Tahsilat' => app_metric($db, "SELECT COUNT(*) FROM rental_payments WHERE status IN ('bekliyor','gecikmis')"),
        'Acik Odeme Linki' => app_metric($db, "SELECT COUNT(*) FROM collections_links WHERE status IN ('taslak','gonderildi')"),
        'Onayli Teklif' => app_metric($db, "SELECT COUNT(*) FROM sales_offers WHERE status = 'onaylandi'"),
        'Bekleyen Siparis' => app_metric($db, "SELECT COUNT(*) FROM sales_orders WHERE status IN ('bekliyor','hazirlaniyor')"),
        'Uretimde Emir' => app_metric($db, "SELECT COUNT(*) FROM production_orders WHERE status = 'uretimde'"),
        'Aktif Personel' => app_metric($db, "SELECT COUNT(*) FROM hr_employees WHERE status = 1"),
    ];

    $dashboardActivity = app_fetch_all($db, "
        SELECT * FROM (
            SELECT 'Fatura' AS activity_type, id AS record_id, invoice_no AS ref_no, invoice_date AS activity_date, grand_total AS amount, invoice_type AS status_text
            FROM invoice_headers
            UNION ALL
            SELECT 'Siparis' AS activity_type, id AS record_id, order_no AS ref_no, order_date AS activity_date, grand_total AS amount, status AS status_text
            FROM sales_orders
            UNION ALL
            SELECT 'Kira' AS activity_type, id AS record_id, contract_no AS ref_no, start_date AS activity_date, monthly_rent AS amount, status AS status_text
            FROM rental_contracts
            UNION ALL
            SELECT 'Servis' AS activity_type, id AS record_id, service_no AS ref_no, DATE(opened_at) AS activity_date, cost_total AS amount, IF(closed_at IS NULL, 'acik', 'kapali') AS status_text
            FROM service_records
            UNION ALL
            SELECT 'Tahsilat' AS activity_type, id AS record_id, COALESCE(transaction_ref, CONCAT('POS#', id)) AS ref_no, DATE(processed_at) AS activity_date, amount, status AS status_text
            FROM collections_transactions
            WHERE processed_at IS NOT NULL
            UNION ALL
            SELECT 'Uretim' AS activity_type, id AS record_id, order_no AS ref_no, DATE(COALESCE(finished_at, started_at, created_at)) AS activity_date, COALESCE(actual_quantity, planned_quantity, 0) AS amount, status AS status_text
            FROM production_orders
        ) x
        ORDER BY x.activity_date DESC
        LIMIT 12
    ");

    $dashboardAlerts = app_fetch_all($db, "
        SELECT * FROM (
            SELECT 'Geciken Kira' AS alert_type, r.id AS record_id, c.id AS related_cari_id, r.contract_no AS ref_no, c.company_name AS owner_name, p.due_date AS ref_date, CONCAT(FORMAT(p.amount, 2), ' TRY') AS detail_text
            FROM rental_payments p
            INNER JOIN rental_contracts r ON r.id = p.contract_id
            INNER JOIN cari_cards c ON c.id = r.cari_id
            WHERE p.status IN ('bekliyor','gecikmis') AND p.due_date <= CURDATE()
            UNION ALL
            SELECT 'Acik Link' AS alert_type, l.id AS record_id, c.id AS related_cari_id, l.link_code AS ref_no, COALESCE(c.company_name, c.full_name, '-') AS owner_name, DATE(l.expires_at) AS ref_date, CONCAT(FORMAT(l.amount, 2), ' TRY') AS detail_text
            FROM collections_links l
            INNER JOIN cari_cards c ON c.id = l.cari_id
            WHERE l.status IN ('taslak','gonderildi')
            UNION ALL
            SELECT 'Uretim' AS alert_type, o.id AS record_id, 0 AS related_cari_id, o.order_no AS ref_no, p.name AS owner_name, DATE(COALESCE(o.started_at, o.created_at)) AS ref_date, o.status AS detail_text
            FROM production_orders o
            INNER JOIN production_recipes r ON r.id = o.recipe_id
            INNER JOIN stock_products p ON p.id = r.product_id
            WHERE o.status IN ('planlandi','uretimde')
            UNION ALL
            SELECT 'Hatirlatma' AS alert_type, r.id AS record_id, c.id AS related_cari_id, CONCAT('CRM#', r.id) AS ref_no, COALESCE(c.company_name, c.full_name, '-') AS owner_name, DATE(r.remind_at) AS ref_date, r.reminder_text AS detail_text
            FROM crm_reminders r
            INNER JOIN cari_cards c ON c.id = r.cari_id
            WHERE r.status = 'bekliyor' AND r.remind_at <= NOW()
        ) x
        ORDER BY x.ref_date ASC
        LIMIT 10
    ");
}

$moduleViews = [
    'bildirim' => __DIR__ . '/modules/notifications.php',
    'cari' => __DIR__ . '/modules/cari_finans.php',
    'core' => __DIR__ . '/modules/core.php',
    'crm' => __DIR__ . '/modules/crm.php',
    'evrak' => __DIR__ . '/modules/docs.php',
    'fatura' => __DIR__ . '/modules/invoices.php',
    'ik' => __DIR__ . '/modules/hr.php',
    'kira' => __DIR__ . '/modules/rental.php',
    'rapor' => __DIR__ . '/modules/reports.php',
    'satis' => __DIR__ . '/modules/sales.php',
    'stok' => __DIR__ . '/modules/stock.php',
    'servis' => __DIR__ . '/modules/service.php',
    'tahsilat' => __DIR__ . '/modules/collections.php',
    'uretim' => __DIR__ . '/modules/production.php',
];

function app_module_view_slug(string $title): string
{
    $map = [
        'Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U',
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
    ];
    $text = strtolower(strtr($title, $map));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string) $text, '-');

    return $text !== '' ? $text : 'bolum';
}

function app_module_view_sections(string $html): array
{
    preg_match_all('/<section\b[^>]*>.*?<\/section>/is', $html, $matches);
    $sections = [];
    $used = [];

    foreach ($matches[0] as $sectionHtml) {
        if (!preg_match('/<h3\b[^>]*>(.*?)<\/h3>/is', $sectionHtml, $titleMatch)) {
            continue;
        }

        $title = trim(strip_tags($titleMatch[1]));
        if ($title === '') {
            continue;
        }

        $baseSlug = app_module_view_slug($title);
        $slug = $baseSlug;
        $counter = 2;
        while (isset($used[$slug])) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        $used[$slug] = true;

        $sections[] = [
            'slug' => $slug,
            'title' => $title,
            'html' => $sectionHtml,
        ];
    }

    return $sections;
}

function app_render_module_content(string $html, string $activeView, string $moduleKey): string
{
    $sections = app_module_view_sections($html);
    if (!$sections) {
        return $html;
    }

    $sectionHtmlBySlug = [];
    foreach ($sections as $section) {
        $sectionHtmlBySlug[$section['slug']] = $section['html'];
    }

    if ($activeView !== '' && isset($sectionHtmlBySlug[$activeView])) {
        return $sectionHtmlBySlug[$activeView];
    }

    if ($activeView !== '') {
        return '<div class="notice notice-error">Secilen islem bulunamadi. Lutfen sol menuden tekrar secin.</div>';
    }

    $withoutTitledSections = str_replace(array_values($sectionHtmlBySlug), '', $html);
    $cards = [];
    foreach ($sections as $section) {
        $cards[] = '<a class="module-action-card" href="?module=' . urlencode($moduleKey) . '&view=' . urlencode($section['slug']) . '">'
            . '<strong>' . app_h($section['title']) . '</strong>'
            . '<span>Bu islemi ac</span>'
            . '</a>';
    }

    return $withoutTitledSections
        . '<section class="card module-action-dashboard">'
        . '<h3>Islem Secimi</h3>'
        . '<p>Bu moduldeki formlar ve raporlar artik ayri sayfalar halinde acilir. Sol menuden veya asagidaki kisayollardan devam edebilirsiniz.</p>'
        . '<div class="module-action-grid">' . implode('', $cards) . '</div>'
        . '</section>';
}

$activeModuleSections = [];
if (isset($moduleViews[$activeKey]) && file_exists($moduleViews[$activeKey])) {
    $activeModuleSource = (string) file_get_contents($moduleViews[$activeKey]);
    $activeModuleSections = app_module_view_sections($activeModuleSource);
}

if ($activeKey === 'rapor' && isset($_GET['export']) && isset($moduleViews['rapor']) && file_exists($moduleViews['rapor'])) {
    require $moduleViews['rapor'];
    exit;
}

if ($activeKey === 'core' && isset($_GET['download_backup']) && isset($moduleViews['core']) && file_exists($moduleViews['core'])) {
    require $moduleViews['core'];
    exit;
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['app_name']) ?></title>
    <style>
        :root {
            --bg: #f5f7fa;
            --surface: #ffffff;
            --surface-soft: #f8f9ff;
            --ink: #18233c;
            --muted: #667085;
            --border: #e6ecf8;
            --accent: #7c3aed;
            --accent-strong: #5b21b6;
            --accent-soft: #eef2ff;
            --ok: #16a34a;
            --warn: #dc2626;
            --shadow: 0 30px 80px rgba(15, 23, 42, .08);
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { font-family: 'Roboto', 'Helvetica Neue', Arial, sans-serif; background: var(--bg); color: var(--ink); }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(180deg, rgba(124, 58, 237, .06) 0%, rgba(124, 58, 237, 0) 28%), radial-gradient(circle at top left, rgba(124, 58, 237, .12), transparent 16rem);
            pointer-events: none;
            z-index: -1;
        }
        .layout { display: grid; grid-template-columns: minmax(260px, 280px) 1fr; min-height: 100vh; }
        .sidebar {
            padding: 30px 24px;
            border-right: 1px solid rgba(226, 232, 240, .95);
            background: #ffffff;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: inset -1px 0 0 rgba(226, 232, 240, .6);
            transition: width .25s ease, padding .25s ease;
        }
        .layout.collapsed { grid-template-columns: 80px 1fr; }
        .layout.collapsed .sidebar {
            width: 80px;
            padding: 22px 10px;
            overflow-x: hidden;
        }
        .layout.collapsed .sidebar .brand,
        .layout.collapsed .sidebar .brand p,
        .layout.collapsed .sidebar .userbox span,
        .layout.collapsed .sidebar .logout-link,
        .layout.collapsed .sidebar .submenu {
            display: none;
        }
        .layout.collapsed .sidebar .menu a {
            justify-content: center;
            padding-left: 0;
            padding-right: 0;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .layout.collapsed .topbar-toggle {
            background: #fff;
            color: #5b21b6;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 18px 24px;
            margin-bottom: 20px;
            border-radius: 0 0 26px 0;
            background: linear-gradient(135deg, #6d28d9 0%, #8b5cf6 40%, #9333ea 100%);
            color: #fff;
            box-shadow: 0 18px 50px rgba(32, 30, 71, .12);
        }
        .topbar-left,
        .topbar-right { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .topbar-toggle {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 14px;
            color: #fff;
            cursor: pointer;
            font-size: 1.1rem;
        }
        .topbar-logo {
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .topbar-logo small {
            display: block;
            margin-top: 4px;
            font-size: .72rem;
            font-weight: 400;
            color: rgba(255,255,255,.8);
            text-transform: none;
            letter-spacing: normal;
        }
        .currency-pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .75rem 1rem;
            border-radius: 999px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.18);
            font-size: .95rem;
            white-space: nowrap;
        }
        .currency-pill strong { font-weight: 700; color: #fff; }
        .btn-create {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border: 1px solid rgba(255,255,255,.24);
            background: rgba(255,255,255,.16);
            color: #fff;
            border-radius: 999px;
            padding: .85rem 1.3rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s ease, transform .2s ease;
        }
        .btn-create:hover { background: rgba(255,255,255,.24); transform: translateY(-1px); }
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: .75rem;
            padding: .6rem 1rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.2);
        }
        .user-badge .avatar {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #4c1d95;
            background: rgba(255,255,255,.35);
            font-weight: 700;
            font-size: .95rem;
        }
        .user-badge .user-name { color: #fff; font-size: .95rem; font-weight: 600; white-space: nowrap; }
        .brand {
            padding: 28px 26px;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(124, 58, 237, .12), rgba(124, 58, 237, .03));
            border: 1px solid rgba(124, 58, 237, .15);
            margin-bottom: 28px;
        }
        .brand h1 { margin: 0 0 10px; font-size: 1.4rem; letter-spacing: .02em; }
        .brand p { margin: 0; color: var(--muted); line-height: 1.8; font-size: .95rem; }
        .userbox {
            margin-top: 24px;
            padding: 20px;
            border-radius: 24px;
            background: #faf8ff;
            border: 1px solid rgba(226, 232, 240, .95);
        }
        .userbox strong { display: block; font-size: 1rem; color: var(--ink); }
        .userbox span { display: block; color: var(--muted); font-size: .9rem; margin-top: 4px; }
        .logout-link { display: inline-block; margin-top: 12px; color: var(--accent-strong); font-weight: 700; text-decoration: none; }
        .menu { display:flex; flex-direction:column; gap:10px; }
        .menu a {
            display: block;
            text-decoration: none;
            color: #475569;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(248, 250, 252, 1);
            transition: transform .2s ease, background .2s ease, color .2s ease;
        }
        .menu a.active, .menu a:hover {
            background: rgba(124, 58, 237, .1);
            color: #5b21b6;
            transform: translateX(1px);
        }
        .submenu {
            display:grid;
            gap:8px;
            margin:12px 0 0 18px;
            padding-left:14px;
            border-left:2px solid rgba(124, 58, 237, .18);
        }
        .submenu a {
            padding:10px 12px;
            border-radius:16px;
            font-size:.92rem;
            color: #4c1d95;
            background: rgba(124, 58, 237, .06);
        }
        .submenu a.active, .submenu a:hover { background: rgba(124, 58, 237, .14); }
        .content { padding: 32px 36px; }
        .hero {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, .95);
            border-radius: 32px;
            padding: 32px;
            box-shadow: 0 30px 70px rgba(15, 23, 42, .08);
        }
        .hero-top { display: flex; justify-content: space-between; gap: 24px; align-items:flex-start; flex-wrap: wrap; }
        .hero h2 { margin: 0 0 10px; font-size: 2rem; }
        .hero p { margin: 0; color: var(--muted); line-height: 1.8; max-width: 720px; }
        .badge { display:inline-flex; align-items:center; padding:12px 18px; border-radius:999px; background: <?= $ready ? 'rgba(124, 58, 237, .12)' : 'rgba(245, 158, 11, .15)' ?>; color: <?= $ready ? '#4f46e5' : '#b45309' ?>; font-weight:700; white-space:nowrap; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; margin-top:26px; }
        .card { background: #ffffff; border: 1px solid rgba(226, 232, 240, .95); border-radius:26px; padding:24px; box-shadow: 0 20px 40px rgba(15, 23, 42, .05); }
        .card small { display:block; color: var(--muted); margin-bottom:12px; text-transform:uppercase; letter-spacing:.08em; }
        .card strong { font-size:1.85rem; color:var(--accent-strong); }
        .section { margin-top:32px; display:grid; grid-template-columns:1.4fr 1fr; gap:22px; }
        .list { display:grid; gap:14px; margin-top:18px; }
        .row { padding:18px 20px; border-radius:22px; background:#ffffff; border:1px solid rgba(226, 232, 240, .95); display:flex; justify-content:space-between; gap:14px; box-shadow: 0 12px 30px rgba(15, 23, 42, .04); }
        .row span { color:var(--muted); }
        .ok { color:var(--ok); font-weight:700; }
        .warn { color:var(--warn); font-weight:700; }
        .cta { display:inline-block; margin-top:22px; text-decoration:none; background:var(--accent); color:#fff; padding:14px 20px; border-radius:18px; font-weight:700; box-shadow: 0 16px 32px rgba(124, 58, 237, .18); }
        .notice { margin-bottom:20px; padding:18px 20px; border-radius:18px; font-weight:700; }
        .notice-ok { background:#dcfce7; color:#166534; }
        .notice-error { background:#fee2e2; color:#991b1b; }
        .module-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:18px; margin-top:26px; }
        .module-grid-2 { grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); }
        .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .form-grid .full { grid-column:1 / -1; }
        .form-grid label { display:block; margin-bottom:8px; font-weight:700; color:#334155; }
        .form-grid input, .form-grid select, .form-grid textarea { width:100%; border:1px solid rgba(226, 232, 240, .95); border-radius:18px; padding:16px 18px; font:inherit; background:#f8f9ff; color:var(--ink); }
        .form-grid input:focus, .form-grid select:focus, .form-grid textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(124, 58, 237, .14); }
        .form-grid button { border:0; border-radius:18px; background:var(--accent); color:#fff; padding:14px 18px; font-weight:700; cursor:pointer; }
        .stack { display:grid; gap:18px; }
        .compact-form { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .check-row { display:flex; align-items:flex-end; gap:12px; }
        .table-wrap { overflow:auto; margin-top:16px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:16px 14px; border-bottom:1px solid rgba(226, 232, 240, .95); text-align:left; font-size:.95rem; }
        th { color:var(--accent-strong); font-size:.82rem; text-transform:uppercase; letter-spacing:.08em; }
        .split-tables { display:grid; gap:18px; }
        .dashboard-panels { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:20px; margin-top:32px; }
        .mini-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:18px; }
        .mini-card { padding:20px; border-radius:22px; background:#ffffff; border:1px solid rgba(226, 232, 240, .95); box-shadow: 0 16px 30px rgba(15, 23, 42, .04); }
        .mini-card small { display:block; color:var(--muted); margin-bottom:10px; text-transform:uppercase; letter-spacing:.08em; }
        .mini-card strong { font-size:1.3rem; color:var(--accent-strong); }
        .search-shell { margin-top:26px; padding:26px; border:1px solid rgba(226, 232, 240, .95); border-radius:28px; background:#faf9ff; }
        .search-form { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:14px; align-items:end; }
        .search-form label { display:block; margin-bottom:8px; font-weight:700; color:#334155; }
        .search-form input { width:100%; border:1px solid rgba(226, 232, 240, .95); border-radius:20px; padding:16px 18px; font:inherit; background:#ffffff; }
        .search-form button, .search-clear { display:inline-flex; align-items:center; justify-content:center; border-radius:18px; padding:14px 18px; font-weight:700; text-decoration:none; }
        .search-form button { border:0; background:var(--accent); color:#fff; cursor:pointer; }
        .search-clear { border:1px solid rgba(79, 70, 229, .2); color:var(--accent-strong); background:rgba(79, 70, 229, .08); }
        .search-meta { margin-top:12px; color:var(--muted); font-size:.93rem; }
        .search-results { margin-top:24px; display:grid; gap:16px; }
        .search-group { padding:18px; border:1px solid rgba(226, 232, 240, .95); border-radius:22px; background:#fff; }
        .search-group-header { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:14px; }
        .search-group-header h4 { margin:0; font-size:1rem; color:var(--accent-strong); }
        .search-pill { display:inline-flex; align-items:center; padding:7px 12px; border-radius:999px; background:var(--accent-soft); color:var(--accent-strong); font-size:.82rem; font-weight:700; }
        .search-item { display:flex; justify-content:space-between; gap:14px; padding:14px 0; border-top:1px solid rgba(226, 232, 240, .95); }
        .search-item:first-child { border-top:0; padding-top:0; }
        .search-item a { color:inherit; text-decoration:none; }
        .search-item strong { display:block; font-size:1rem; }
        .search-item span { display:block; margin-top:4px; color:var(--muted); line-height:1.55; }
        .dashboard-preferences { margin-top:24px; }
        .dashboard-pref-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-top:18px; }
        .dashboard-pref-option { display:flex; gap:12px; align-items:flex-start; padding:16px; border:1px solid rgba(226, 232, 240, .95); border-radius:20px; background:#fff; }
        .dashboard-pref-option input { margin-top:3px; }
        .dashboard-pref-option strong { display:block; color:var(--accent-strong); font-size:.97rem; }
        .dashboard-pref-option span { display:block; margin-top:4px; color:var(--muted); font-size:.89rem; line-height:1.5; }
        .dashboard-pref-actions { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-top:18px; flex-wrap:wrap; }
        .dashboard-pref-meta { color:var(--muted); font-size:.93rem; }
        .module-action-dashboard p { margin:0 0 18px; color:var(--muted); line-height:1.65; }
        .module-action-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-top:16px; }
        .module-action-card { display:block; padding:18px; border:1px solid rgba(226, 232, 240, .95); border-radius:20px; background:#fff; color:inherit; text-decoration:none; transition:transform .18s ease,box-shadow .18s ease; }
        .module-action-card strong { display:block; font-size:1rem; color:var(--accent-strong); }
        .module-action-card span { display:block; margin-top:8px; color:var(--muted); font-size:.92rem; }
        .module-action-card:hover { transform:translateY(-1px); box-shadow:0 16px 40px rgba(15,23,42,.08); }
        .footer {
            padding: 18px 36px;
            text-align: center;
            color: #fff;
            background: linear-gradient(90deg, #835c9e, #8253eb);
            border-top: 1px solid rgba(255,255,255,.08);
            font-size: .95rem;
        }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { position: relative; height: auto; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .topbar-right { justify-content: flex-start; width: 100%; }
            .currency-pill { width: 100%; justify-content: center; }
        }
        @media (max-width: 760px) { .form-grid, .compact-form, .search-form { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <?php if ($activeKey !== 'dashboard'): ?>
                <div class="brand">
                    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
                    <p>Belgelerden turetilmis muhasebe, operasyon, servis, kira ve uretim paneli.</p>
                    <div class="userbox">
                        <strong><?= app_h($authUser['full_name']) ?></strong>
                        <span><?= app_h($authUser['email']) ?><?= $authUser['role_name'] !== '' ? ' / ' . app_h($authUser['role_name']) : '' ?></span>
                        <a class="logout-link" href="logout.php">Cikis Yap</a>
                    </div>
                </div>
            <?php endif; ?>
            <nav class="menu">
                <?php foreach ($modules as $key => $module): ?>
                    <a href="?module=<?= urlencode($key) ?>" class="<?= $activeKey === $key ? 'active' : '' ?>"><?= htmlspecialchars($module['title']) ?></a>
                    <?php if ($activeKey === $key && $activeModuleSections): ?>
                        <div class="submenu">
                            <a href="?module=<?= urlencode($key) ?>" class="<?= $activeView === '' ? 'active' : '' ?>">Modul Dashboard</a>
                            <?php foreach ($activeModuleSections as $section): ?>
                                <a href="?module=<?= urlencode($key) ?>&view=<?= urlencode($section['slug']) ?>" class="<?= $activeView === $section['slug'] ? 'active' : '' ?>"><?= app_h($section['title']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </aside>

        <main class="content">
            <div class="topbar">
                <div class="topbar-left">
                    <button type="button" class="topbar-toggle" aria-label="Menu toggle">☰</button>
                    <div class="topbar-logo">
                        <?= htmlspecialchars($config['app_name']) ?>
                        <small>Panel</small>
                    </div>
                </div>
                <div class="topbar-right">
                    <div class="currency-pill"><strong>USD/TRY:</strong> <?= app_h($exchangeRates['USD'] ?? '—') ?></div>
                    <div class="currency-pill"><strong>EUR/TRY:</strong> <?= app_h($exchangeRates['EUR'] ?? '—') ?></div>
                    <a class="btn-create" href="<?= app_h($refreshRatesUrl) ?>">Kurlari Yenile</a>
                    <button type="button" class="btn-create">+ Oluştur</button>
                    <div class="user-badge" title="<?= app_h($authUser['full_name'] ?? 'Kullanici') ?>">
                        <span class="avatar"><?= app_h(strtoupper(substr(trim((string) ($authUser['full_name'] ?? 'U')), 0, 1))) ?></span>
                        <span class="user-name"><?= app_h($authUser['full_name'] ?? 'Kullanici') ?></span>
                    </div>
                </div>
            </div>
            <section class="hero">
                <div class="hero-top">
                    <div>
                        <h2><?= htmlspecialchars($activeModule['title']) ?></h2>
                        <p><?= htmlspecialchars($activeModule['summary']) ?></p>
                    </div>
                    <div class="badge"><?= $ready ? 'Veritabani bagli' : 'Kurulum bekleniyor' ?></div>
                </div>

                <?php if ($showDashboardStats): ?>
                    <div class="grid">
                        <?php foreach ($stats as $label => $value): ?>
                            <div class="card"><small><?= htmlspecialchars($label) ?></small><strong><?= htmlspecialchars((string) $value) ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="search-shell">
                    <form method="get" class="search-form">
                        <div>
                            <label for="global_q">Global Arama</label>
                            <input id="global_q" type="search" name="global_q" value="<?= app_h($globalSearchQuery) ?>" placeholder="Cari, teklif, siparis, fatura, stok, servis, kira, personel veya evrak ara">
                            <?php if ($activeKey !== 'dashboard'): ?>
                                <input type="hidden" name="module" value="<?= app_h($activeKey) ?>">
                            <?php endif; ?>
                            <?php if ($activeView !== ''): ?>
                                <input type="hidden" name="view" value="<?= app_h($activeView) ?>">
                            <?php endif; ?>
                        </div>
                        <button type="submit">Ara</button>
                        <?php if ($globalSearchQuery !== ''): ?>
                            <a class="search-clear" href="?module=<?= urlencode($activeKey) ?><?= $activeView !== '' ? '&view=' . urlencode($activeView) : '' ?>">Temizle</a>
                        <?php endif; ?>
                    </form>
                    <div class="search-meta">
                        <?php if ($globalSearchQuery !== '' && $globalSearchNotice === ''): ?>
                            "<?= app_h($globalSearchQuery) ?>" icin <?= (int) $globalSearchTotal ?> sonuc bulundu.
                        <?php else: ?>
                            Tek kutudan tum temel kayitlara ulasin ve ilgili detay ekranina dogrudan gecin.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$ready): ?><a class="cta" href="install.php">Kurulum ekranina git</a><?php endif; ?>
            </section>

            <?php if ($globalSearchNotice !== ''): ?>
                <div class="notice notice-error"><?= app_h($globalSearchNotice) ?></div>
            <?php elseif ($globalSearchQuery !== ''): ?>
                <section class="card search-results">
                    <h3>Global Arama Sonuclari</h3>
                    <?php if ($globalSearchTotal > 0): ?>
                        <?php foreach ($globalSearchResults as $group => $rows): ?>
                            <div class="search-group">
                                <div class="search-group-header">
                                    <h4><?= app_h($group) ?></h4>
                                    <span class="search-pill"><?= (int) count($rows) ?> kayit</span>
                                </div>
                                <?php foreach ($rows as $item): ?>
                                    <div class="search-item">
                                        <div>
                                            <strong><a href="<?= app_h($item['url']) ?>"><?= app_h($item['title']) ?></a></strong>
                                            <span><?= app_h($item['subtitle']) ?></span>
                                        </div>
                                        <div class="ok"><a href="<?= app_h($item['url']) ?>" style="color:inherit;text-decoration:none;">Ac</a></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="row">
                            <div>
                                <strong style="font-size:1rem;">Kayit bulunamadi</strong>
                                <span>Aradiginiz ifade icin uygun cari, satis, fatura, stok, servis, kira, IK veya evrak kaydi bulunamadi.</span>
                            </div>
                            <div class="warn">Bos</div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (isset($moduleViews[$activeKey]) && file_exists($moduleViews[$activeKey])): ?>
                <?php ob_start(); ?>
                <?php require $moduleViews[$activeKey]; ?>
                <?php $moduleContent = ob_get_clean(); ?>
                <?= app_render_module_content($moduleContent, $activeView, $activeKey) ?>
            <?php else: ?>
            <?php if ($csrfError !== ''): ?>
                <div class="notice notice-error"><?= app_h($csrfError) ?></div>
            <?php elseif ($activeKey === 'dashboard'): ?>
                    <?php if ($dashboardPreferenceNotice !== ''): ?>
                        <div class="notice notice-ok"><?= app_h($dashboardPreferenceNotice) ?></div>
                    <?php endif; ?>

                    <section class="card dashboard-preferences">
                        <h3>Dashboard Gorunumu</h3>
                        <p>Her kullanici kendi ana sayfasinda hangi kutularin gosterilecegini buradan belirleyebilir.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_dashboard_preferences">
                            <div class="dashboard-pref-grid">
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[stats]" value="1" <?= $dashboardPreferences['stats'] ? 'checked' : '' ?>>
                                    <span><strong>Ust Gosterge Kartlari</strong><span>Cari, servis, kira, urun ve kullanici sayilari.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[finance]" value="1" <?= $dashboardPreferences['finance'] ? 'checked' : '' ?>>
                                    <span><strong>Finans Ozeti</strong><span>Kasa, banka, acik alacak ve borc toplamlari.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[operations]" value="1" <?= $dashboardPreferences['operations'] ? 'checked' : '' ?>>
                                    <span><strong>Operasyon Sinyalleri</strong><span>Stok, servis, uretim ve personel gorunumu.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[activity]" value="1" <?= $dashboardPreferences['activity'] ? 'checked' : '' ?>>
                                    <span><strong>Son Hareket Akisi</strong><span>Fatura, siparis, servis, tahsilat ve uretim akisi.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[alerts]" value="1" <?= $dashboardPreferences['alerts'] ? 'checked' : '' ?>>
                                    <span><strong>Anlik Uyarilar</strong><span>Geciken kira, acik link ve CRM hatirlatmalari.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[quick_links]" value="1" <?= $dashboardPreferences['quick_links'] ? 'checked' : '' ?>>
                                    <span><strong>Hizli Yonlendirme</strong><span>Operasyonel modullere dogrudan gecis.</span></span>
                                </label>
                                <label class="dashboard-pref-option">
                                    <input type="checkbox" name="dashboard_sections[management_links]" value="1" <?= $dashboardPreferences['management_links'] ? 'checked' : '' ?>>
                                    <span><strong>Yonetim Kestirmeleri</strong><span>Satis, kira, bildirim ve rapor kisayollari.</span></span>
                                </label>
                            </div>
                            <div class="dashboard-pref-actions">
                                <div class="dashboard-pref-meta"><?= (int) $dashboardVisiblePanelCount ?> bolum aktif. Isterseniz tum panelleri kapatip sadece ihtiyaciniz olanlari acabilirsiniz.</div>
                                <button type="submit">Gorunumu Kaydet</button>
                            </div>
                        </form>
                    </section>

                    <?php if ($showDashboardFinance || $showDashboardOperations): ?>
                        <section class="dashboard-panels">
                            <?php if ($showDashboardFinance): ?>
                                <div class="card">
                                    <h3>Finans Ozeti</h3>
                                    <div class="mini-grid">
                                        <?php foreach ($dashboardFinance as $label => $value): ?>
                                            <div class="mini-card">
                                                <small><?= htmlspecialchars($label) ?></small>
                                                <strong><?= htmlspecialchars((string) $value) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($showDashboardOperations): ?>
                                <div class="card">
                                    <h3>Operasyon Sinyalleri</h3>
                                    <div class="mini-grid">
                                        <?php foreach ($dashboardOperations as $label => $value): ?>
                                            <div class="mini-card">
                                                <small><?= htmlspecialchars($label) ?></small>
                                                <strong><?= htmlspecialchars((string) $value) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($showDashboardActivity || $showDashboardAlerts): ?>
                        <section class="section">
                            <?php if ($showDashboardActivity): ?>
                                <div class="card">
                                    <h3>Son Hareket Akisi</h3>
                                    <div class="list">
                                        <?php foreach ($dashboardActivity as $item): ?>
                                            <?php $activityUrl = dashboard_activity_url($item); ?>
                                            <div class="row">
                                                <div>
                                                    <strong style="font-size:1rem;"><a href="<?= app_h($activityUrl) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($item['activity_type'] . ' / ' . $item['ref_no']) ?></a></strong>
                                                    <span><?= htmlspecialchars((string) $item['activity_date']) ?> - <?= htmlspecialchars((string) $item['status_text']) ?></span>
                                                </div>
                                                <div class="ok">
                                                    <a href="<?= app_h($activityUrl) ?>" style="color:inherit;text-decoration:none;"><?= number_format((float) $item['amount'], 2, ',', '.') ?></a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($showDashboardAlerts): ?>
                                <div class="card">
                                    <h3>Anlik Uyarilar</h3>
                                    <div class="list">
                                        <?php foreach ($dashboardAlerts as $item): ?>
                                            <?php $alertUrl = dashboard_alert_url($item); ?>
                                            <div class="row">
                                                <div>
                                                    <strong style="font-size:1rem;"><a href="<?= app_h($alertUrl) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($item['alert_type'] . ' / ' . $item['ref_no']) ?></a></strong>
                                                    <span><?= htmlspecialchars((string) $item['owner_name']) ?> - <?= htmlspecialchars((string) $item['detail_text']) ?></span>
                                                </div>
                                                <div class="warn"><a href="<?= app_h($alertUrl) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars((string) $item['ref_date']) ?></a></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!$dashboardAlerts): ?>
                                            <div class="row"><div><strong style="font-size:1rem;">Sistem Dengede</strong><span>Takip edilmesi gereken acil kayit bulunmuyor.</span></div><div class="ok">Temiz</div></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($showDashboardQuickLinks || $showDashboardManagementLinks): ?>
                        <section class="section">
                            <?php if ($showDashboardQuickLinks): ?>
                                <div class="card">
                                    <h3>Hizli Yonlendirme</h3>
                                    <div class="list">
                                        <div class="row"><div><strong style="font-size:1rem;">Cari ve Finans</strong><span>Tahsilat, bakiye ve kasa/banka takibi</span></div><div class="ok"><a href="?module=cari" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Tahsilat ve POS</strong><span>Odeme linkleri, musteri ekrani ve POS islemleri</span></div><div class="ok"><a href="?module=tahsilat" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Uretim ve Recete</strong><span>Recete, sarf ve cikti hareketlerini yonet</span></div><div class="ok"><a href="?module=uretim" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Personel ve IK</strong><span>Departman, vardiya ve zimmet surecleri</span></div><div class="ok"><a href="?module=ik" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($showDashboardManagementLinks): ?>
                                <div class="card">
                                    <h3>Yonetim Kestirmeleri</h3>
                                    <div class="list">
                                        <div class="row"><div><strong style="font-size:1rem;">Satis ve Fatura</strong><span>Teklif, siparis ve fatura otomasyonlari</span></div><div class="ok"><a href="?module=satis" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Kira Yonetimi</strong><span>Sozlesme, tahsilat ve cihaz operasyonlari</span></div><div class="ok"><a href="?module=kira" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Bildirim Merkezi</strong><span>CRM, kira ve mesaj kuyruklarini izle</span></div><div class="ok"><a href="?module=bildirim" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                        <div class="row"><div><strong style="font-size:1rem;">Rapor Merkezi</strong><span>CSV disa aktarma ve yonetim raporlari</span></div><div class="ok"><a href="?module=rapor" style="color:inherit;text-decoration:none;">Ac</a></div></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($dashboardVisiblePanelCount === 0): ?>
                        <section class="card">
                            <h3>Dashboard Gizli Modda</h3>
                            <p>Bu kullanici icin tum dashboard bolumleri kapatildi. Yukaridaki gorunum ayarindan istediginiz alanlari tekrar acabilirsiniz.</p>
                        </section>
                    <?php endif; ?>
                <?php else: ?>
                <section class="section">
                    <div class="card">
                        <h3>Modul kapsami</h3>
                        <p>Bu ekran, verdiginiz belge basliklarini sistematik modullere ayirir ve her birinin veritabani karsiligini gosterir.</p>
                        <div class="list">
                            <?php foreach ($activeModule['tables'] as $table): ?>
                                <?php $exists = $ready ? app_table_exists($db, $table) : false; ?>
                                <div class="row">
                                    <div>
                                        <strong style="font-size:1rem;"><?= htmlspecialchars($table) ?></strong>
                                        <span><?= $ready ? 'Tablo kontrol edildi' : 'Kurulumdan sonra olusacak' ?></span>
                                    </div>
                                    <div class="<?= $exists ? 'ok' : 'warn' ?>"><?= $exists ? 'Hazir' : 'Bekliyor' ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$activeModule['tables']): ?>
                                <div class="row">
                                    <div><strong style="font-size:1rem;">Yonetim ozeti</strong><span>Dashboard ekrani tum moduller icin tek merkez gorevi gorur.</span></div>
                                    <div class="ok">Aktif</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <h3>Belge uyumu</h3>
                        <div class="list">
                            <div class="row"><div><strong style="font-size:1rem;">Fonksiyonel kapsam</strong><span>17 ana basliktan sistem omurgasi olusturuldu.</span></div><div class="ok">Hazir</div></div>
                            <div class="row"><div><strong style="font-size:1rem;">SQL kurulum dosyalari</strong><span>`database/schema.sql` ve `database/seed.sql` eklendi.</span></div><div class="ok">Hazir</div></div>
                            <div class="row"><div><strong style="font-size:1rem;">Kurulum yardimcisi</strong><span>MySQL baglanti bilgileri ile tek tikta veritabani kurulumu.</span></div><div class="ok">Hazir</div></div>
                            <div class="row"><div><strong style="font-size:1rem;">Sonraki genisleme</strong><span>CRUD formlari, oturum yapisi ve entegrasyon katmani eklenebilir.</span></div><div class="warn">Sonraki faz</div></div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
    <footer class="footer">Mevcut sistemin ana ekranì bu alandan devam ediyor. Bu sürüm, referans tema kutuphanesindeki yeni amaciyla uyumlu, sade ve ergonomik bir kullanıcı arayüzü sunar.</footer>
    <script>
        (function () {
            var csrfToken = <?= json_encode(app_csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            var forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
            for (var i = 0; i < forms.length; i++) {
                var form = forms[i];
                if (form.querySelector('input[name="_csrf"]')) {
                    continue;
                }
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_csrf';
                input.value = csrfToken;
                form.appendChild(input);
            }

                    var toggle = document.querySelector('.topbar-toggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    var layout = document.querySelector('.layout');
                    if (layout) {
                        layout.classList.toggle('collapsed');
                    }
                    toggle.classList.toggle('active');
                    toggle.setAttribute('aria-pressed', toggle.classList.contains('active') ? 'true' : 'false');
                });
            }
        })();
    </script>
</body>
</html>
