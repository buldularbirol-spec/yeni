<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$authUser = app_require_auth();
$db = app_db();
$ready = $db && app_database_ready();

function print_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Belge Ciktisi</title>
        <style>
            body { font-family: "Segoe UI", sans-serif; margin: 0; background: #f5efe7; color: #1f2937; }
            .wrap { max-width: 760px; margin: 40px auto; background: #fff; border: 1px solid #e7d8c6; border-radius: 20px; padding: 32px; box-shadow: 0 20px 50px rgba(124,45,18,.08); }
            h1 { margin-top: 0; }
            a { color: #9a3412; font-weight: 700; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <h1>Belge hazirlanamadi</h1>
            <p><?= app_h($message) ?></p>
            <p><a href="index.php">Panele don</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function print_money($value, string $currency = 'TRY'): string
{
    $suffix = $currency === '' ? '' : ' ' . $currency;
    return number_format((float) $value, 2, ',', '.') . $suffix;
}

function print_qty($value): string
{
    return number_format((float) $value, 3, ',', '.');
}

function print_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return '-';
}

function print_meta(array $pairs): array
{
    $rows = [];
    foreach ($pairs as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $rows[] = [
            'label' => (string) $label,
            'value' => (string) $value,
        ];
    }

    return $rows;
}

function print_branch_setting(PDO $db, int $branchId, string $suffix, string $fallback): string
{
    if ($branchId > 0) {
        $value = app_setting($db, 'print.branch.' . $branchId . '.' . $suffix, '');
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

if (!$db || !$ready) {
    print_fail('Veritabani hazir degil.');
}

$type = trim((string) ($_GET['type'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);

if ($type === '' || ($id <= 0 && $type !== 'pos_reconciliation_report')) {
    print_fail('Belge tipi veya kayit bilgisi eksik.');
}

$firmRows = app_fetch_all($db, '
    SELECT company_name, trade_name, tax_office, tax_number, mersis_no, email, phone, website, address, city, district, country, logo_path
    FROM core_firms
    WHERE status = 1
    ORDER BY id ASC
    LIMIT 1
');
$firm = $firmRows[0] ?? [
    'company_name' => app_config()['app_name'],
    'trade_name' => null,
    'tax_office' => null,
    'tax_number' => null,
    'mersis_no' => null,
    'email' => null,
    'phone' => null,
    'website' => null,
    'address' => null,
    'city' => null,
    'district' => null,
    'country' => 'Turkiye',
    'logo_path' => null,
];

$document = [
    'title' => '',
    'subtitle' => '',
    'number' => '',
    'date' => '',
    'party_label' => '',
    'party_name' => '',
    'party_meta' => [],
    'meta' => [],
    'items_title' => '',
    'items' => [],
    'totals' => [],
    'notes' => '',
];

$printSettings = [
    'invoice_title' => app_setting($db, 'print.invoice_title', 'FATURA'),
    'invoice_subtitle' => app_setting($db, 'print.invoice_subtitle', 'PDF / yazdir cikti sablonu'),
    'invoice_accent' => app_setting($db, 'print.invoice_accent', '#9a3412'),
    'invoice_notes_title' => app_setting($db, 'print.invoice_notes_title', 'Notlar'),
    'invoice_footer' => app_setting($db, 'print.invoice_footer', 'Bu belge sistem tarafindan olusturulmustur.'),
    'document_logo_path' => '',
];

if ($type === 'invoice') {
    $rows = app_fetch_all($db, '
        SELECT h.*, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone
        FROM invoice_headers h
        INNER JOIN cari_cards c ON c.id = h.cari_id
        WHERE h.id = :id
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Fatura bulunamadi.', 404);
    }

    $header = $rows[0];
    $invoiceBranchId = (int) ($header['branch_id'] ?? 0);
    $printSettings['invoice_title'] = print_branch_setting($db, $invoiceBranchId, 'invoice_title', $printSettings['invoice_title']);
    $printSettings['invoice_subtitle'] = print_branch_setting($db, $invoiceBranchId, 'invoice_subtitle', $printSettings['invoice_subtitle']);
    $printSettings['invoice_accent'] = print_branch_setting($db, $invoiceBranchId, 'invoice_accent', $printSettings['invoice_accent']);
    $printSettings['invoice_notes_title'] = print_branch_setting($db, $invoiceBranchId, 'invoice_notes_title', $printSettings['invoice_notes_title']);
    $printSettings['invoice_footer'] = print_branch_setting($db, $invoiceBranchId, 'invoice_footer', $printSettings['invoice_footer']);
    $printSettings['document_logo_path'] = print_branch_setting($db, $invoiceBranchId, 'document_logo_path', '');
    $items = app_fetch_all($db, '
        SELECT description, quantity, unit_price, vat_rate, line_total
        FROM invoice_items
        WHERE invoice_id = :invoice_id
        ORDER BY id ASC
    ', ['invoice_id' => $id]);

    $document = [
        'title' => trim($printSettings['invoice_title']) !== '' ? $printSettings['invoice_title'] : (strtoupper((string) $header['invoice_type']) . ' FATURASI'),
        'subtitle' => $printSettings['invoice_subtitle'],
        'number' => (string) $header['invoice_no'],
        'date' => (string) $header['invoice_date'],
        'party_label' => 'Cari',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
        ]),
        'meta' => print_meta([
            'Vade' => $header['due_date'] ?? null,
            'Para Birimi' => $header['currency_code'] ?? 'TRY',
            'e-Belge' => $header['edocument_type'] ?? null,
            'e-Durum' => $header['edocument_status'] ?? null,
        ]),
        'items_title' => 'Fatura Kalemleri',
        'items' => array_map(static function (array $item): array {
            return [
                'Aciklama' => (string) $item['description'],
                'Miktar' => print_qty($item['quantity']),
                'Birim Fiyat' => print_money($item['unit_price'], ''),
                'KDV %' => number_format((float) $item['vat_rate'], 2, ',', '.'),
                'Tutar' => print_money($item['line_total'], ''),
            ];
        }, $items),
        'totals' => [
            'Ara Toplam' => print_money($header['subtotal'], (string) $header['currency_code']),
            'KDV' => print_money($header['vat_total'], (string) $header['currency_code']),
            'Genel Toplam' => print_money($header['grand_total'], (string) $header['currency_code']),
        ],
        'notes' => (string) ($header['notes'] ?? ''),
    ];
} elseif ($type === 'offer') {
    $rows = app_fetch_all($db, '
        SELECT o.*, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone
        FROM sales_offers o
        INNER JOIN cari_cards c ON c.id = o.cari_id
        WHERE o.id = :id
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Teklif bulunamadi.', 404);
    }

    $header = $rows[0];
    $items = app_fetch_all($db, '
        SELECT description, quantity, unit_price, line_total
        FROM sales_offer_items
        WHERE offer_id = :offer_id
        ORDER BY id ASC
    ', ['offer_id' => $id]);

    $document = [
        'title' => 'SATIS TEKLIFI',
        'subtitle' => 'PDF / yazdir cikti sablonu',
        'number' => (string) $header['offer_no'],
        'date' => (string) $header['offer_date'],
        'party_label' => 'Musteri',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
        ]),
        'meta' => print_meta([
            'Gecerlilik' => $header['valid_until'] ?? null,
            'Durum' => $header['status'] ?? null,
        ]),
        'items_title' => 'Teklif Kalemleri',
        'items' => array_map(static function (array $item): array {
            return [
                'Aciklama' => (string) $item['description'],
                'Miktar' => print_qty($item['quantity']),
                'Birim Fiyat' => print_money($item['unit_price'], ''),
                'Tutar' => print_money($item['line_total'], ''),
            ];
        }, $items),
        'totals' => [
            'Ara Toplam' => print_money($header['subtotal'], 'TRY'),
            'Genel Toplam' => print_money($header['grand_total'], 'TRY'),
        ],
        'notes' => (string) ($header['notes'] ?? ''),
    ];
} elseif ($type === 'order') {
    $rows = app_fetch_all($db, '
        SELECT o.*, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone, s.offer_no
        FROM sales_orders o
        INNER JOIN cari_cards c ON c.id = o.cari_id
        LEFT JOIN sales_offers s ON s.id = o.offer_id
        WHERE o.id = :id
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Siparis bulunamadi.', 404);
    }

    $header = $rows[0];
    $items = app_fetch_all($db, '
        SELECT description, quantity, unit_price, line_total
        FROM sales_order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ', ['order_id' => $id]);

    $document = [
        'title' => 'SATIS SIPARISI',
        'subtitle' => 'PDF / yazdir cikti sablonu',
        'number' => (string) $header['order_no'],
        'date' => (string) $header['order_date'],
        'party_label' => 'Musteri',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
        ]),
        'meta' => print_meta([
            'Durum' => $header['status'] ?? null,
            'Teklif Ref' => $header['offer_no'] ?? null,
            'Kargo' => $header['cargo_company'] ?? null,
            'Takip No' => $header['tracking_no'] ?? null,
        ]),
        'items_title' => 'Siparis Kalemleri',
        'items' => array_map(static function (array $item): array {
            return [
                'Aciklama' => (string) $item['description'],
                'Miktar' => print_qty($item['quantity']),
                'Birim Fiyat' => print_money($item['unit_price'], ''),
                'Tutar' => print_money($item['line_total'], ''),
            ];
        }, $items),
        'totals' => [
            'Genel Toplam' => print_money($header['grand_total'], 'TRY'),
        ],
        'notes' => '',
    ];
} elseif ($type === 'shipment') {
    $rows = app_fetch_all($db, '
        SELECT sh.*, o.order_no, o.order_date, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone, w.name AS warehouse_name
        FROM sales_shipments sh
        INNER JOIN sales_orders o ON o.id = sh.order_id
        INNER JOIN cari_cards c ON c.id = o.cari_id
        LEFT JOIN stock_warehouses w ON w.id = sh.warehouse_id
        WHERE o.id = :id
        ORDER BY sh.id DESC
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Irsaliye/sevk kaydi bulunamadi.', 404);
    }

    $header = $rows[0];
    $items = app_fetch_all($db, '
        SELECT description, quantity, unit_price, line_total
        FROM sales_order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ', ['order_id' => (int) $header['order_id']]);

    $document = [
        'title' => 'SEVK IRSALIYESI',
        'subtitle' => 'PDF / yazdir cikti sablonu',
        'number' => (string) $header['irsaliye_no'],
        'date' => (string) $header['shipment_date'],
        'party_label' => 'Musteri',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
        ]),
        'meta' => print_meta([
            'Siparis No' => $header['order_no'] ?? null,
            'Sevk No' => $header['shipment_no'] ?? null,
            'Depo' => $header['warehouse_name'] ?? null,
            'Durum' => $header['delivery_status'] ?? null,
            'Kargo' => $header['cargo_company'] ?? null,
            'Takip No' => $header['tracking_no'] ?? null,
        ]),
        'items_title' => 'Sevk Kalemleri',
        'items' => array_map(static function (array $item): array {
            return [
                'Aciklama' => (string) $item['description'],
                'Miktar' => print_qty($item['quantity']),
                'Birim Fiyat' => print_money($item['unit_price'], ''),
                'Tutar' => print_money($item['line_total'], ''),
            ];
        }, $items),
        'totals' => [
            'Siparis Toplami' => print_money($header['grand_total'] ?? 0, 'TRY'),
        ],
        'notes' => (string) ($header['notes'] ?? ''),
    ];
} elseif ($type === 'cargo_label') {
    $rows = app_fetch_all($db, '
        SELECT sh.*, o.order_no, o.order_date, c.company_name, c.full_name, c.address, c.city, c.district, c.phone AS cari_phone,
               cp.provider_name, cp.provider_code
        FROM sales_shipments sh
        INNER JOIN sales_orders o ON o.id = sh.order_id
        INNER JOIN cari_cards c ON c.id = o.cari_id
        LEFT JOIN sales_cargo_providers cp ON cp.id = sh.provider_id
        WHERE o.id = :id
        ORDER BY sh.id DESC
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Kargo etiketi icin sevk kaydi bulunamadi.', 404);
    }

    $header = $rows[0];
    $receiver = trim((string) print_label($header));
    $receiverAddress = trim((string) (($header['address'] ?? '') . ' ' . ($header['district'] ?? '') . ' / ' . ($header['city'] ?? '')));

    $document = [
        'title' => 'KARGO ETIKETI',
        'subtitle' => 'Paket uzerine basilmaya hazir etiket',
        'number' => (string) ($header['label_no'] ?: ('LBL-' . str_pad((string) $header['order_id'], 6, '0', STR_PAD_LEFT))),
        'date' => (string) $header['shipment_date'],
        'party_label' => 'Alici',
        'party_name' => $receiver !== '' ? $receiver : '-',
        'party_meta' => print_meta([
            'Telefon' => $header['cari_phone'] ?? null,
            'Adres' => $receiverAddress !== '' ? $receiverAddress : null,
        ]),
        'meta' => print_meta([
            'Siparis No' => $header['order_no'] ?? null,
            'Kargo' => $header['provider_name'] ?? $header['cargo_company'] ?? null,
            'Saglayici Kod' => $header['provider_code'] ?? null,
            'Takip No' => $header['tracking_no'] ?? null,
            'Irsaliye' => $header['irsaliye_no'] ?? null,
        ]),
        'items_title' => 'Etiket Bilgileri',
        'items' => [[
            'Takip No' => (string) ($header['tracking_no'] ?: '-'),
            'Etiket No' => (string) ($header['label_no'] ?: '-'),
            'Siparis' => (string) ($header['order_no'] ?: '-'),
            'Durum' => (string) ($header['delivery_status'] ?: '-'),
        ]],
        'totals' => [],
        'notes' => 'Takip No: ' . (string) ($header['tracking_no'] ?: '-') . "\n" . 'Etiket No: ' . (string) ($header['label_no'] ?: '-'),
    ];
} elseif ($type === 'service' || $type === 'service_acceptance' || $type === 'service_delivery') {
    $rows = app_fetch_all($db, '
        SELECT s.*, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone,
               p.name AS product_name, f.name AS fault_name, st.name AS status_name, u.full_name AS assigned_name
        FROM service_records s
        INNER JOIN cari_cards c ON c.id = s.cari_id
        LEFT JOIN stock_products p ON p.id = s.product_id
        LEFT JOIN service_fault_types f ON f.id = s.fault_type_id
        LEFT JOIN service_statuses st ON st.id = s.status_id
        LEFT JOIN core_users u ON u.id = s.assigned_user_id
        WHERE s.id = :id
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Servis kaydi bulunamadi.', 404);
    }

    $header = $rows[0];
    $notes = app_fetch_all($db, '
        SELECT note_text, is_customer_visible, created_at
        FROM service_notes
        WHERE service_record_id = :service_record_id
        ORDER BY id ASC
    ', ['service_record_id' => $id]);

    $isAcceptancePrint = $type === 'service_acceptance';
    $isDeliveryPrint = $type === 'service_delivery';
    $acceptanceItems = [[
        'Baslik' => 'Teslim Alinan Aksesuarlar',
        'Aciklama' => (string) (($header['received_accessories'] ?? '') ?: '-'),
    ], [
        'Baslik' => 'Cihaz Fiziksel Durumu',
        'Aciklama' => (string) (($header['device_condition'] ?? '') ?: '-'),
    ], [
        'Baslik' => 'Musteri Onay Notu',
        'Aciklama' => (string) (($header['customer_approval_note'] ?? '') ?: '-'),
    ]];
    $deliveryItems = [[
        'Baslik' => 'Teslim Durumu',
        'Aciklama' => (string) (($header['delivery_status'] ?? '') ?: 'bekliyor'),
    ], [
        'Baslik' => 'Teslim Eden',
        'Aciklama' => (string) (($header['delivered_by'] ?? '') ?: '-'),
    ], [
        'Baslik' => 'Teslim Alan',
        'Aciklama' => (string) (($header['delivered_to'] ?? '') ?: '-'),
    ], [
        'Baslik' => 'Teslim Notu',
        'Aciklama' => (string) (($header['delivery_notes'] ?? '') ?: '-'),
    ]];

    $document = [
        'title' => $isDeliveryPrint ? 'SERVIS TESLIM FORMU' : ($isAcceptancePrint ? 'SERVIS KABUL FORMU' : 'SERVIS FORMU'),
        'subtitle' => 'PDF / yazdir cikti sablonu',
        'number' => (string) $header['service_no'],
        'date' => $isDeliveryPrint ? (string) (($header['delivery_date'] ?? '') ?: $header['opened_at']) : (string) $header['opened_at'],
        'party_label' => 'Cari',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
            'Seri No' => $header['serial_no'] ?? null,
        ]),
        'meta' => print_meta([
            'Urun' => $header['product_name'] ?? null,
            'Ariza Tipi' => $header['fault_name'] ?? null,
            'Durum' => $header['status_name'] ?? null,
            'Personel' => $header['assigned_name'] ?? null,
            'Garanti' => $header['warranty_status'] ?? null,
            'Teslim Tipi' => $header['acceptance_type'] ?? null,
            'Teslim Alan' => $header['received_by'] ?? null,
            'Tahmini Teslim' => $header['estimated_delivery_date'] ?? null,
            'Teslim Eden / Imza' => $header['acceptance_signed_by'] ?? null,
            'Teslim Durumu' => $header['delivery_status'] ?? null,
            'Teslim Tarihi' => $header['delivery_date'] ?? null,
            'Teslim Eden' => $header['delivered_by'] ?? null,
            'Teslim Alan' => $header['delivered_to'] ?? null,
            'Kapanis' => $header['closed_at'] ?? null,
        ]),
        'items_title' => $isDeliveryPrint ? 'Teslim Bilgileri' : ($isAcceptancePrint ? 'Kabul Kalemleri' : 'Servis Notlari'),
        'items' => $isDeliveryPrint ? $deliveryItems : ($isAcceptancePrint ? $acceptanceItems : array_map(static function (array $item): array {
            return [
                'Tarih' => (string) $item['created_at'],
                'Musteri Gorur' => (int) $item['is_customer_visible'] === 1 ? 'Evet' : 'Hayir',
                'Aciklama' => (string) $item['note_text'],
            ];
        }, $notes)),
        'totals' => [
            'Toplam Maliyet' => print_money($header['cost_total'], 'TRY'),
        ],
        'notes' => $isDeliveryPrint
            ? trim('Sikayet / Ariza: ' . (string) ($header['complaint'] ?? '') . "\n\n" . 'Teslim Notu: ' . (string) (($header['delivery_notes'] ?? '') ?: '-') . "\n\n" . 'Urun/cihaz musterinin kontrolune teslim edilmistir.')
            : ($isAcceptancePrint
            ? trim('Sikayet / Ariza: ' . (string) ($header['complaint'] ?? '') . "\n\n" . 'Bu form urun/cihaz teslim alinirken duzenlenmistir. Musteri ve teslim alan tarafindan kontrol edilmesi onerilir.')
            : trim((string) ($header['complaint'] ?? '')) . ((string) ($header['diagnosis'] ?? '') !== '' ? "\n\nTeshis: " . (string) $header['diagnosis'] : '')),
    ];
} elseif ($type === 'rental') {
    $rows = app_fetch_all($db, '
        SELECT r.*, c.company_name, c.full_name, c.email AS cari_email, c.phone AS cari_phone,
               d.device_name, d.serial_no, d.location_text
        FROM rental_contracts r
        INNER JOIN cari_cards c ON c.id = r.cari_id
        INNER JOIN rental_devices d ON d.id = r.device_id
        WHERE r.id = :id
        LIMIT 1
    ', ['id' => $id]);

    if (!$rows) {
        print_fail('Kira sozlesmesi bulunamadi.', 404);
    }

    $header = $rows[0];
    $payments = app_fetch_all($db, '
        SELECT due_date, amount, status, paid_at
        FROM rental_payments
        WHERE contract_id = :contract_id
        ORDER BY due_date ASC, id ASC
    ', ['contract_id' => $id]);
    $logs = app_fetch_all($db, '
        SELECT event_type, event_date, description
        FROM rental_service_logs
        WHERE device_id = :device_id
        ORDER BY id ASC
    ', ['device_id' => $header['device_id']]);

    $items = [];
    foreach ($payments as $payment) {
        $items[] = [
            'Tip' => 'Odeme',
            'Tarih' => (string) $payment['due_date'],
            'Durum' => (string) $payment['status'],
            'Aciklama' => (string) $payment['paid_at'],
            'Tutar' => print_money($payment['amount'], 'TRY'),
        ];
    }
    foreach ($logs as $log) {
        $items[] = [
            'Tip' => 'Cihaz Olayi',
            'Tarih' => (string) $log['event_date'],
            'Durum' => (string) $log['event_type'],
            'Aciklama' => (string) ($log['description'] ?? ''),
            'Tutar' => '-',
        ];
    }

    $document = [
        'title' => 'KIRA SOZLESMESI',
        'subtitle' => 'PDF / yazdir cikti sablonu',
        'number' => (string) $header['contract_no'],
        'date' => (string) $header['start_date'],
        'party_label' => 'Kiraci',
        'party_name' => print_label($header),
        'party_meta' => print_meta([
            'E-posta' => $header['cari_email'] ?? null,
            'Telefon' => $header['cari_phone'] ?? null,
        ]),
        'meta' => print_meta([
            'Cihaz' => ($header['device_name'] ?? '-') . ' / ' . ($header['serial_no'] ?? '-'),
            'Lokasyon' => $header['location_text'] ?? null,
            'Baslangic' => $header['start_date'] ?? null,
            'Bitis' => $header['end_date'] ?? null,
            'Durum' => $header['status'] ?? null,
            'Fatura Gunu' => $header['billing_day'] ? ((string) $header['billing_day']) : null,
        ]),
        'items_title' => 'Odeme ve Olay Gecmisi',
        'items' => $items,
        'totals' => [
            'Aylik Kira' => print_money($header['monthly_rent'], 'TRY'),
            'Depozito' => print_money($header['deposit_amount'], 'TRY'),
        ],
        'notes' => (string) ($header['notes'] ?? ''),
    ];
} elseif ($type === 'pos_reconciliation_report') {
    $rows = app_fetch_all($db, "
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
                 t.amount, t.commission_amount, t.refunded_amount, t.reconciled_at
        ORDER BY t.id DESC
        LIMIT 50
    ");

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'Link' => $row['link_code'],
            'Cari' => print_label($row),
            'POS' => $row['provider_name'],
            'Ref' => $row['transaction_ref'] ?: '-',
            'Tutar' => print_money($row['amount'], 'TRY'),
            'Kom.' => print_money($row['commission_amount'], 'TRY'),
            'Iade' => print_money($row['refunded_amount'], 'TRY'),
            'Durum' => $row['status'],
            'Mutabakat' => $row['reconciled_at'] ?: '-',
            'Rapor Durumu' => $row['reconciliation_status'] . ((int) $row['open_webhook_errors'] > 0 ? ' / Alarm: ' . (int) $row['open_webhook_errors'] : ''),
        ];
    }

    $successfulCount = (int) app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'basarili'");
    $pendingCount = (int) app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'bekliyor'");
    $refundCount = (int) app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'iade'");
    $openWebhookCount = (int) app_metric($db, "SELECT COUNT(*) FROM collections_webhook_logs WHERE verification_status = 'hatali' AND resolved_at IS NULL");

    $document = [
        'title' => 'POS MUTABAKAT RAPORU',
        'subtitle' => 'POS tahsilat mutabakati yazdirma ve PDF ciktisi',
        'number' => 'POS-MUT-' . date('Ymd'),
        'date' => date('Y-m-d'),
        'party_label' => 'Rapor',
        'party_name' => 'Tahsilat ve POS Mutabakati',
        'party_meta' => print_meta([
            'Kapsam' => 'Son 50 POS islemi',
            'Olusturma' => date('Y-m-d H:i'),
        ]),
        'meta' => print_meta([
            'Basarili Islem' => (string) $successfulCount,
            'Bekleyen Islem' => (string) $pendingCount,
            'Iade Islem' => (string) $refundCount,
            'Acik Webhook Alarmi' => (string) $openWebhookCount,
        ]),
        'items_title' => 'POS Mutabakat Kalemleri',
        'items' => $items,
        'totals' => [
            'Toplam Tahsilat' => print_money(app_metric($db, "SELECT COALESCE(SUM(amount), 0) FROM collections_transactions WHERE status = 'basarili'"), 'TRY'),
            'Toplam Iade' => print_money(app_metric($db, "SELECT COALESCE(SUM(refunded_amount), 0) FROM collections_transactions"), 'TRY'),
            'Toplam Komisyon' => print_money(app_metric($db, "SELECT COALESCE(SUM(commission_amount), 0) FROM collections_transactions WHERE status IN ('basarili', 'iade')"), 'TRY'),
        ],
        'notes' => 'Bu rapor POS tahsilatlari, iade tutarlari, komisyonlar, saglayici durumlari ve acik webhook alarmlarini mutabakat kontrolu icin tek ciktida toplar.',
    ];
} else {
    print_fail('Desteklenmeyen belge tipi.');
}

$logoUrl = null;
$logoCandidate = (string) ($firm['logo_path'] ?? '');
if ($type === 'invoice' && !empty($printSettings['document_logo_path'])) {
    $logoCandidate = (string) $printSettings['document_logo_path'];
}
if ($logoCandidate !== '') {
    $relativeLogo = $logoCandidate;
    $absoluteLogo = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $relativeLogo);
    if (file_exists($absoluteLogo)) {
        $logoUrl = $relativeLogo;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_h($document['title'] . ' / ' . $document['number']) ?></title>
    <style>
        :root { --paper: #ffffff; --bg: #efe7db; --ink: #1f2937; --muted: #6b7280; --line: #e7d8c6; --accent: <?= app_h($type === 'invoice' ? $printSettings['invoice_accent'] : '#9a3412') ?>; --soft: #fdf7ee; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Segoe UI", sans-serif; }
        .toolbar { max-width: 980px; margin: 24px auto 0; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 0 18px; }
        .toolbar a, .toolbar button { border: 0; background: #fff; border: 1px solid var(--line); color: var(--accent); padding: 10px 14px; border-radius: 999px; text-decoration: none; font: inherit; font-weight: 700; cursor: pointer; }
        .page { max-width: 980px; margin: 18px auto 40px; background: var(--paper); border: 1px solid var(--line); border-radius: 28px; box-shadow: 0 24px 60px rgba(124,45,18,.08); overflow: hidden; }
        .sheet { padding: 40px 44px; }
        .head { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 24px; border-bottom: 2px solid var(--line); }
        .brand { display: flex; gap: 18px; align-items: flex-start; }
        .brand img { width: 88px; height: 88px; object-fit: contain; border-radius: 18px; border: 1px solid var(--line); padding: 8px; background: #fff; }
        .brand h1 { margin: 0; font-size: 1.5rem; }
        .brand p { margin: 6px 0 0; color: var(--muted); line-height: 1.55; }
        .docbox { min-width: 260px; padding: 18px; border: 1px solid var(--line); border-radius: 20px; background: var(--soft); }
        .docbox h2 { margin: 0 0 12px; font-size: 1.15rem; color: var(--accent); }
        .meta-line { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px dashed #eadfce; }
        .meta-line:last-child { border-bottom: 0; }
        .meta-line span { color: var(--muted); }
        .panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 24px; }
        .panel { border: 1px solid var(--line); border-radius: 22px; padding: 18px; background: #fff; }
        .panel h3 { margin: 0 0 12px; font-size: 1rem; color: var(--accent); text-transform: uppercase; letter-spacing: .04em; }
        .table-wrap { margin-top: 24px; border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; border-bottom: 1px solid #f0e5d8; text-align: left; vertical-align: top; }
        th { background: #fdf7ee; color: var(--accent); font-size: .82rem; text-transform: uppercase; letter-spacing: .04em; }
        tr:last-child td { border-bottom: 0; }
        .totals { margin-top: 24px; margin-left: auto; width: min(340px, 100%); border: 1px solid var(--line); border-radius: 20px; padding: 16px 18px; background: #fffaf4; }
        .totals .meta-line strong { color: var(--accent); }
        .notes { margin-top: 24px; border: 1px solid var(--line); border-radius: 20px; padding: 18px; background: #fff; }
        .notes h3 { margin: 0 0 12px; color: var(--accent); }
        .notes p { margin: 0; color: var(--ink); line-height: 1.7; white-space: pre-wrap; }
        .foot { margin-top: 28px; color: var(--muted); font-size: .9rem; display: flex; justify-content: space-between; gap: 16px; border-top: 1px solid var(--line); padding-top: 18px; }
        @media (max-width: 760px) { .head, .panel-grid, .foot, .toolbar { flex-direction: column; display: flex; } .sheet { padding: 22px; } .docbox { width: 100%; } .panel-grid { display: block; } .panel { margin-bottom: 14px; } }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { box-shadow: none; border: 0; margin: 0; max-width: none; border-radius: 0; }
            .sheet { padding: 20mm 16mm; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="index.php">Panele Don</a>
        <button type="button" onclick="window.print()">PDF / Yazdir</button>
    </div>

    <div class="page">
        <div class="sheet">
            <header class="head">
                <div class="brand">
                    <?php if ($logoUrl !== null): ?>
                        <img src="<?= app_h($logoUrl) ?>" alt="Firma Logosu">
                    <?php endif; ?>
                    <div>
                        <h1><?= app_h((string) ($firm['trade_name'] ?: $firm['company_name'])) ?></h1>
                        <p><?= app_h((string) ($firm['company_name'] ?? '')) ?></p>
                        <p><?= app_h(trim((string) (($firm['address'] ?? '') . ' ' . ($firm['district'] ?? '') . ' ' . ($firm['city'] ?? '') . ' ' . ($firm['country'] ?? '')))) ?></p>
                        <p><?= app_h(trim((string) (($firm['phone'] ?? '') . '  ' . ($firm['email'] ?? '') . '  ' . ($firm['website'] ?? '')))) ?></p>
                    </div>
                </div>

                <div class="docbox">
                    <h2><?= app_h($document['title']) ?></h2>
                    <?php foreach (print_meta([
                        'Belge No' => $document['number'],
                        'Tarih' => $document['date'],
                        'Hazirlayan' => $authUser['full_name'] ?? '',
                        'Vergi Dairesi' => $firm['tax_office'] ?? null,
                        'Vergi No' => $firm['tax_number'] ?? null,
                    ]) as $meta): ?>
                        <div class="meta-line"><span><?= app_h($meta['label']) ?></span><strong><?= app_h($meta['value']) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </header>

            <section class="panel-grid">
                <div class="panel">
                    <h3><?= app_h($document['party_label']) ?></h3>
                    <div class="meta-line"><span>Unvan</span><strong><?= app_h($document['party_name']) ?></strong></div>
                    <?php foreach ($document['party_meta'] as $meta): ?>
                        <div class="meta-line"><span><?= app_h($meta['label']) ?></span><strong><?= app_h($meta['value']) ?></strong></div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <h3>Belge Bilgileri</h3>
                    <?php foreach ($document['meta'] as $meta): ?>
                        <div class="meta-line"><span><?= app_h($meta['label']) ?></span><strong><?= app_h($meta['value']) ?></strong></div>
                    <?php endforeach; ?>
                    <?php if (!$document['meta']): ?>
                        <div class="meta-line"><span>Bilgi</span><strong>-</strong></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <?php if (!empty($document['items'])): ?>
                                <?php foreach (array_keys($document['items'][0]) as $column): ?>
                                    <th><?= app_h($column) ?></th>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <th><?= app_h($document['items_title']) ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($document['items'])): ?>
                            <?php foreach ($document['items'] as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= app_h((string) $value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td>Bu belge icin listelenecek kalem bulunmuyor.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <?php if ($document['totals']): ?>
                <section class="totals">
                    <?php foreach ($document['totals'] as $label => $value): ?>
                        <div class="meta-line"><span><?= app_h($label) ?></span><strong><?= app_h($value) ?></strong></div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (trim($document['notes']) !== ''): ?>
                <section class="notes">
                    <h3><?= app_h($type === 'invoice' ? $printSettings['invoice_notes_title'] : 'Notlar') ?></h3>
                    <p><?= app_h($document['notes']) ?></p>
                </section>
            <?php endif; ?>

            <footer class="foot">
                <div><?= app_h($type === 'invoice' ? $printSettings['invoice_footer'] : $document['subtitle']) ?></div>
                <div><?= app_h((string) date('Y-m-d H:i')) ?></div>
            </footer>
        </div>
    </div>
</body>
</html>
