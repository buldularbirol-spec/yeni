USE galancy;

INSERT IGNORE INTO core_roles (name, code) VALUES
('Super Admin', 'super_admin'),
('Muhasebe', 'muhasebe'),
('Operasyon', 'operasyon');

INSERT INTO core_firms (company_name, trade_name, phone, email, city)
SELECT 'Galancy Demo Sirketi', 'Galancy', '05550000000', 'info@galancy.local', 'Istanbul'
WHERE NOT EXISTS (SELECT 1 FROM core_firms LIMIT 1);

INSERT INTO core_branches (firm_id, name, phone, city)
SELECT id, 'Merkez Sube', '05550000001', 'Istanbul'
FROM core_firms
WHERE NOT EXISTS (SELECT 1 FROM core_branches LIMIT 1)
LIMIT 1;

INSERT INTO core_users (role_id, branch_id, full_name, email, phone, password_hash)
SELECT
    (SELECT id FROM core_roles WHERE code = 'super_admin' LIMIT 1),
    (SELECT id FROM core_branches LIMIT 1),
    'Sistem Yoneticisi',
    'admin@galancy.local',
    '05550000002',
    '$2y$10$eWxpQj..dzZ8YNLLjsI.ve16WUiHuBSAkM6rznoUBPPCK3fFwk9LS'
WHERE NOT EXISTS (SELECT 1 FROM core_users LIMIT 1);

INSERT IGNORE INTO core_settings (setting_key, setting_value, setting_group) VALUES
('currency.default', 'TRY', 'genel'),
('tax.default_vat', '20', 'vergi'),
('theme.default', 'sunrise', 'arayuz'),
('notifications.email_enabled', '1', 'bildirim'),
('notifications.sms_enabled', '0', 'bildirim'),
('notifications.email_mode', 'mock', 'bildirim'),
('notifications.sms_mode', 'mock', 'bildirim'),
('notifications.sms_api_url', '', 'bildirim'),
('notifications.sms_api_method', 'POST', 'bildirim'),
('notifications.sms_api_content_type', 'application/json', 'bildirim'),
('notifications.sms_api_headers', '', 'bildirim'),
('notifications.sms_api_body', '{"to":"{{phone}}","message":"{{message}}"}', 'bildirim'),
('notifications.sms_api_timeout', '15', 'bildirim'),
('notifications.smtp_host', '', 'bildirim'),
('notifications.smtp_port', '587', 'bildirim'),
('notifications.smtp_security', 'tls', 'bildirim'),
('notifications.smtp_username', '', 'bildirim'),
('notifications.smtp_password', '', 'bildirim'),
('notifications.smtp_from_email', '', 'bildirim'),
('notifications.smtp_from_name', 'Galancy Bildirim', 'bildirim'),
('notifications.smtp_timeout', '15', 'bildirim'),
('notifications.crm_email_subject', 'CRM Hatirlatma / {{company_name}}', 'bildirim'),
('notifications.crm_email_body', 'Merhaba,\n\n{{reminder_text}}\nTarih: {{remind_at}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi', 'bildirim'),
('notifications.crm_sms_body', '{{company_name}} icin hatirlatma: {{reminder_text}} / {{remind_at}}', 'bildirim'),
('notifications.rental_email_subject', 'Gecikmis Kira Tahsilati / {{contract_no}}', 'bildirim'),
('notifications.rental_email_body', 'Merhaba,\n\n{{contract_no}} sozlesmesine ait {{amount}} tutarli kira tahsilati gecikmistir.\nVade: {{due_date}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi', 'bildirim'),
('notifications.rental_sms_body', '{{contract_no}} sozlesmesi icin {{due_date}} vadeli {{amount}} tutarli kira odemesi gecikmistir.', 'bildirim'),
('notifications.invoice_due_reminder_days', '3', 'bildirim'),
('notifications.invoice_email_subject', 'Fatura Vade Hatirlatmasi / {{invoice_no}}', 'bildirim'),
('notifications.invoice_email_body', 'Merhaba,\n\n{{invoice_no}} numarali {{remaining_total}} tutarli faturanin vadesi {{due_date}} tarihindedir.\nCari: {{company_name}}\nDurum: {{payment_status}}\n\nGalancy Bildirim Merkezi', 'bildirim'),
('notifications.invoice_sms_body', '{{invoice_no}} numarali {{remaining_total}} tutarli fatura icin vade {{due_date}} / Durum: {{payment_status}}', 'bildirim'),
('edocument.mode', 'mock', 'fatura'),
('edocument.api_url', '', 'fatura'),
('edocument.api_method', 'POST', 'fatura'),
('edocument.api_content_type', 'application/json', 'fatura'),
('edocument.api_headers', '', 'fatura'),
('edocument.api_body', '{"invoice_no":"{{invoice_no}}","invoice_type":"{{invoice_type}}","grand_total":"{{grand_total}}","cari":"{{cari_name}}"}', 'fatura'),
('edocument.status_url', '', 'fatura'),
('edocument.status_method', 'POST', 'fatura'),
('edocument.status_content_type', 'application/json', 'fatura'),
('edocument.status_headers', '', 'fatura'),
('edocument.status_body', '{"invoice_no":"{{invoice_no}}","uuid":"{{uuid}}"}', 'fatura'),
('earchive.mode', 'mock', 'fatura'),
('earchive.api_url', '', 'fatura'),
('earchive.api_method', 'POST', 'fatura'),
('earchive.api_content_type', 'application/json', 'fatura'),
('earchive.api_headers', '', 'fatura'),
('earchive.api_body', '{"invoice_no":"{{invoice_no}}","invoice_type":"{{invoice_type}}","grand_total":"{{grand_total}}","cari":"{{cari_name}}"}', 'fatura'),
('earchive.status_url', '', 'fatura'),
('earchive.status_method', 'POST', 'fatura'),
('earchive.status_content_type', 'application/json', 'fatura'),
('earchive.status_headers', '', 'fatura'),
('earchive.status_body', '{"invoice_no":"{{invoice_no}}","uuid":"{{uuid}}"}', 'fatura'),
('earchive.timeout', '15', 'fatura'),
('edispatch.mode', 'mock', 'satis'),
('edispatch.api_url', '', 'satis'),
('edispatch.api_method', 'POST', 'satis'),
('edispatch.api_content_type', 'application/json', 'satis'),
('edispatch.api_headers', '', 'satis'),
('edispatch.api_body', '{"shipment_no":"{{shipment_no}}","irsaliye_no":"{{irsaliye_no}}","order_no":"{{order_no}}","cari":"{{cari_name}}"}', 'satis'),
('edispatch.status_url', '', 'satis'),
('edispatch.status_method', 'POST', 'satis'),
('edispatch.status_content_type', 'application/json', 'satis'),
('edispatch.status_headers', '', 'satis'),
('edispatch.status_body', '{"irsaliye_no":"{{irsaliye_no}}","uuid":"{{uuid}}"}', 'satis'),
('edispatch.timeout', '15', 'satis'),
('edocument.timeout', '15', 'fatura');

INSERT IGNORE INTO core_settings (setting_key, setting_value, setting_group) VALUES
('print.invoice_title', 'FATURA', 'tasarim'),
('print.invoice_subtitle', 'PDF / yazdir cikti sablonu', 'tasarim'),
('print.invoice_accent', '#9a3412', 'tasarim'),
('print.invoice_notes_title', 'Notlar', 'tasarim'),
('print.invoice_footer', 'Bu belge sistem tarafindan olusturulmustur.', 'tasarim');

INSERT INTO finance_cashboxes (branch_id, name, currency_code, opening_balance)
SELECT 1, 'Merkez Kasa', 'TRY', 0
WHERE NOT EXISTS (SELECT 1 FROM finance_cashboxes LIMIT 1);

INSERT INTO finance_bank_accounts (branch_id, bank_name, account_name, iban, currency_code, opening_balance)
SELECT 1, 'Demo Banka', 'Ana Hesap', 'TR000000000000000000000000', 'TRY', 0
WHERE NOT EXISTS (SELECT 1 FROM finance_bank_accounts LIMIT 1);

INSERT INTO collections_pos_accounts (branch_id, provider_name, provider_code, merchant_code, api_mode, api_url, api_method, api_body, status_url, status_method, status_body, three_d_enabled, three_d_init_url, three_d_method, three_d_body, three_d_success_status, three_d_fail_status, success_url, fail_url, commission_rate, status)
SELECT 1, 'Demo POS', 'demo_pos', 'POS-001', 'mock', '', 'POST', '{"amount":"{{amount}}","link_code":"{{link_code}}"}', '', 'POST', '{"transaction_ref":"{{transaction_ref}}"}', 0, '', 'POST', '{"amount":"{{amount}}","reference":"{{transaction_ref}}","success_url":"{{three_d_success_url}}","fail_url":"{{three_d_fail_url}}"}', 'basarili', 'hatali', '', '', 2.50, 1
WHERE NOT EXISTS (SELECT 1 FROM collections_pos_accounts LIMIT 1);

INSERT INTO stock_products (category_id, product_type, sku, barcode, name, unit, vat_rate, purchase_price, sale_price, critical_stock, track_lot, track_serial, status)
SELECT NULL, 'ticari', 'URUN-0001', '8690000000001', 'Demo Satis Urunu', 'adet', 20, 0, 1500, 1, 0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM stock_products LIMIT 1);

INSERT INTO stock_categories (parent_id, name, status)
SELECT NULL, 'Genel Urunler', 1
WHERE NOT EXISTS (SELECT 1 FROM stock_categories LIMIT 1);

INSERT INTO stock_warehouses (branch_id, name, location_text, status)
SELECT 1, 'Merkez Depo', 'Ana stok alani', 1
WHERE NOT EXISTS (SELECT 1 FROM stock_warehouses LIMIT 1);

INSERT INTO service_fault_types (name, status)
SELECT 'Elektrik Arizasi', 1
WHERE NOT EXISTS (SELECT 1 FROM service_fault_types LIMIT 1);

INSERT INTO service_fault_types (name, status)
SELECT 'Mekanik Ariza', 1
WHERE NOT EXISTS (SELECT 1 FROM service_fault_types WHERE name = 'Mekanik Ariza');

INSERT INTO service_statuses (name, color_code, is_closed)
SELECT 'Kayit Acildi', '#f59e0b', 0
WHERE NOT EXISTS (SELECT 1 FROM service_statuses LIMIT 1);

INSERT INTO service_statuses (name, color_code, is_closed)
SELECT 'Incelemede', '#3b82f6', 0
WHERE NOT EXISTS (SELECT 1 FROM service_statuses WHERE name = 'Incelemede');

INSERT INTO service_statuses (name, color_code, is_closed)
SELECT 'Tamamlandi', '#16a34a', 1
WHERE NOT EXISTS (SELECT 1 FROM service_statuses WHERE name = 'Tamamlandi');

INSERT INTO rental_device_categories (name)
SELECT 'Profesyonel Cihazlar'
WHERE NOT EXISTS (SELECT 1 FROM rental_device_categories LIMIT 1);

INSERT INTO hr_departments (name)
SELECT 'Muhasebe'
WHERE NOT EXISTS (SELECT 1 FROM hr_departments LIMIT 1);

INSERT INTO hr_departments (name)
SELECT 'Operasyon'
WHERE NOT EXISTS (SELECT 1 FROM hr_departments WHERE name = 'Operasyon');

INSERT INTO hr_employees (department_id, user_id, full_name, title, phone, email, start_date, status)
SELECT
    (SELECT id FROM hr_departments WHERE name = 'Muhasebe' LIMIT 1),
    (SELECT id FROM core_users WHERE email = 'admin@galancy.local' LIMIT 1),
    'Demo Personel',
    'Muhasebe Sorumlusu',
    '05550000003',
    'personel@galancy.local',
    CURDATE(),
    1
WHERE NOT EXISTS (SELECT 1 FROM hr_employees LIMIT 1);

INSERT INTO stock_products (category_id, product_type, sku, barcode, name, unit, vat_rate, purchase_price, sale_price, critical_stock, track_lot, track_serial, status)
SELECT 1, 'hammadde', 'HAM-0001', '8690000001001', 'Demo Hammadde', 'adet', 20, 100, 0, 5, 0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM stock_products WHERE sku = 'HAM-0001');

INSERT INTO sales_discount_rules (cari_id, rule_name, discount_type, discount_value, min_amount, valid_from, valid_until, notes, status)
SELECT
    NULL,
    'Genel Bahar Iskontosu',
    'oran',
    7.50,
    1000.00,
    CURDATE(),
    NULL,
    '1000 TRY ve uzeri siparisler icin genel satis indirimi',
    1
WHERE NOT EXISTS (SELECT 1 FROM sales_discount_rules WHERE rule_name = 'Genel Bahar Iskontosu');

INSERT INTO sales_promotion_rules (cari_id, product_id, promo_name, promo_type, promo_value, min_quantity, min_amount, valid_from, valid_until, notes, status)
SELECT
    NULL,
    (SELECT id FROM stock_products WHERE sku = 'URUN-0001' LIMIT 1),
    '2 Adet ve Uzeri Urun Kampanyasi',
    'oran',
    5.00,
    2.000,
    2000.00,
    CURDATE(),
    NULL,
    'Demo satis urununde 2 adet ve uzeri alima ek kampanya',
    1
WHERE NOT EXISTS (SELECT 1 FROM sales_promotion_rules WHERE promo_name = '2 Adet ve Uzeri Urun Kampanyasi');

INSERT INTO sales_offer_templates (template_name, category_name, product_id, default_description, default_quantity, default_unit_price, default_status, valid_day_count, notes, status)
SELECT
    'Standart Urun Teklifi',
    'Genel',
    (SELECT id FROM stock_products WHERE sku = 'URUN-0001' LIMIT 1),
    'Demo satis urunu icin standart teklif sablonu',
    1.000,
    1500.00,
    'taslak',
    15,
    'Sablon notu: fiyat ve kosullar teklif aninda guncellenebilir.',
    1
WHERE NOT EXISTS (SELECT 1 FROM sales_offer_templates WHERE template_name = 'Standart Urun Teklifi');
