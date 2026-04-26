CREATE DATABASE IF NOT EXISTS galancy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE galancy;

CREATE TABLE IF NOT EXISTS core_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(100) NULL UNIQUE,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS core_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NULL,
    branch_id INT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS core_firms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    trade_name VARCHAR(200) NULL,
    tax_office VARCHAR(150) NULL,
    tax_number VARCHAR(50) NULL,
    mersis_no VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    website VARCHAR(200) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    district VARCHAR(100) NULL,
    country VARCHAR(100) NULL DEFAULT 'Turkiye',
    logo_path VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS core_branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firm_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    district VARCHAR(100) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_core_branches_firm FOREIGN KEY (firm_id) REFERENCES core_firms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE core_users
    ADD CONSTRAINT fk_core_users_role FOREIGN KEY (role_id) REFERENCES core_roles(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_core_users_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS core_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(150) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,
    setting_group VARCHAR(100) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS core_audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    module_name VARCHAR(100) NOT NULL,
    action_name VARCHAR(100) NOT NULL,
    record_table VARCHAR(100) NULL,
    record_id BIGINT NULL,
    description TEXT NULL,
    ip_address VARCHAR(50) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_core_audit_logs_user (user_id),
    KEY idx_core_audit_logs_module (module_name),
    CONSTRAINT fk_core_audit_logs_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
    KEY idx_push_user_status (user_id, status),
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    KEY idx_notification_preference_scope (module_name, notification_type, status),
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cari_cards (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    card_type ENUM('musteri','tedarikci','bayi','personel','ortak','diger') NOT NULL DEFAULT 'musteri',
    is_company TINYINT(1) NOT NULL DEFAULT 0,
    company_name VARCHAR(200) NULL,
    full_name VARCHAR(150) NULL,
    tc_no VARCHAR(20) NULL,
    tax_office VARCHAR(100) NULL,
    tax_number VARCHAR(50) NULL,
    phone VARCHAR(50) NULL,
    phone2 VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    website VARCHAR(200) NULL,
    address TEXT NULL,
    city VARCHAR(100) NULL,
    district VARCHAR(100) NULL,
    country VARCHAR(100) NULL DEFAULT 'Turkiye',
    risk_limit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    due_day INT NULL,
    segment_code VARCHAR(80) NULL,
    segment_note VARCHAR(255) NULL,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cari_cards_type (card_type),
    KEY idx_cari_cards_phone (phone),
    CONSTRAINT fk_cari_cards_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cari_addresses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    address_title VARCHAR(100) NULL,
    contact_name VARCHAR(150) NULL,
    contact_phone VARCHAR(50) NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NULL,
    district VARCHAR(100) NULL,
    country VARCHAR(100) NULL DEFAULT 'Turkiye',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cari_addresses_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cari_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    movement_type ENUM('borc','alacak') NOT NULL,
    source_module VARCHAR(50) NOT NULL,
    source_table VARCHAR(100) NULL,
    source_id BIGINT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    description TEXT NULL,
    movement_date DATETIME NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cari_movements_cari (cari_id),
    KEY idx_cari_movements_source (source_module, source_id),
    CONSTRAINT fk_cari_movements_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_cari_movements_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_cashboxes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    name VARCHAR(150) NOT NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance_cashboxes_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_bank_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    bank_name VARCHAR(150) NOT NULL,
    account_name VARCHAR(150) NULL,
    iban VARCHAR(50) NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance_bank_accounts_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_cash_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cashbox_id BIGINT NOT NULL,
    cari_id BIGINT NULL,
    movement_type ENUM('giris','cikis') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT NULL,
    movement_date DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance_cash_movements_cashbox FOREIGN KEY (cashbox_id) REFERENCES finance_cashboxes(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_cash_movements_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_bank_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id BIGINT NOT NULL,
    cari_id BIGINT NULL,
    movement_type ENUM('giris','cikis') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT NULL,
    movement_date DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance_bank_movements_bank FOREIGN KEY (bank_account_id) REFERENCES finance_bank_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_bank_movements_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_transfers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_type ENUM('kasa','banka') NOT NULL,
    source_id BIGINT NOT NULL,
    target_type ENUM('kasa','banka') NOT NULL,
    target_id BIGINT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    transfer_date DATETIME NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance_expenses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    expense_type VARCHAR(100) NOT NULL,
    payment_channel ENUM('kasa','banka') NOT NULL,
    payment_ref_id BIGINT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description TEXT NULL,
    source_module VARCHAR(50) NULL,
    source_table VARCHAR(100) NULL,
    source_id BIGINT NULL,
    expense_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance_expenses_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS collections_pos_accounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    provider_name VARCHAR(150) NOT NULL,
    provider_code VARCHAR(100) NULL,
    merchant_code VARCHAR(100) NULL,
    api_mode ENUM('mock','manual','http_api') NOT NULL DEFAULT 'mock',
    public_key VARCHAR(255) NULL,
    secret_key VARCHAR(255) NULL,
    api_url VARCHAR(255) NULL,
    api_method VARCHAR(20) NOT NULL DEFAULT 'POST',
    api_headers TEXT NULL,
    api_body LONGTEXT NULL,
    status_url VARCHAR(255) NULL,
    status_method VARCHAR(20) NOT NULL DEFAULT 'POST',
    status_headers TEXT NULL,
    status_body LONGTEXT NULL,
    three_d_enabled TINYINT(1) NOT NULL DEFAULT 0,
    three_d_init_url VARCHAR(255) NULL,
    three_d_method VARCHAR(20) NOT NULL DEFAULT 'POST',
    three_d_headers TEXT NULL,
    three_d_body LONGTEXT NULL,
    three_d_success_status VARCHAR(50) NULL,
    three_d_fail_status VARCHAR(50) NULL,
    success_url VARCHAR(255) NULL,
    fail_url VARCHAR(255) NULL,
    callback_secret VARCHAR(255) NULL,
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_collections_pos_accounts_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS collections_links (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    pos_account_id BIGINT NULL,
    link_code VARCHAR(100) NOT NULL UNIQUE,
    amount DECIMAL(15,2) NOT NULL,
    installment_count INT NOT NULL DEFAULT 1,
    status ENUM('taslak','gonderildi','odendi','iptal') NOT NULL DEFAULT 'taslak',
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_collections_links_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_collections_links_pos FOREIGN KEY (pos_account_id) REFERENCES collections_pos_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS collections_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    link_id BIGINT NULL,
    cari_id BIGINT NOT NULL,
    pos_account_id BIGINT NULL,
    amount DECIMAL(15,2) NOT NULL,
    installment_count INT NOT NULL DEFAULT 1,
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    commission_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('bekliyor','basarili','hatali','iade') NOT NULL DEFAULT 'bekliyor',
    transaction_ref VARCHAR(150) NULL,
    three_d_status VARCHAR(50) NULL,
    three_d_redirect_url VARCHAR(255) NULL,
    three_d_response LONGTEXT NULL,
    three_d_completed_at DATETIME NULL,
    provider_status VARCHAR(50) NULL,
    provider_response LONGTEXT NULL,
    last_status_check_at DATETIME NULL,
    reconciled_at DATETIME NULL,
    refunded_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    refund_reason TEXT NULL,
    refunded_at DATETIME NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_collections_transactions_link FOREIGN KEY (link_id) REFERENCES collections_links(id) ON DELETE SET NULL,
    CONSTRAINT fk_collections_transactions_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_collections_transactions_pos FOREIGN KEY (pos_account_id) REFERENCES collections_pos_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS collections_webhook_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NULL,
    pos_account_id BIGINT NULL,
    transaction_ref VARCHAR(150) NULL,
    event_type VARCHAR(80) NOT NULL DEFAULT 'payment_callback',
    provider_status VARCHAR(80) NULL,
    verification_status ENUM('bekliyor','dogrulandi','hatali') NOT NULL DEFAULT 'bekliyor',
    http_status INT NULL,
    payload LONGTEXT NULL,
    raw_body LONGTEXT NULL,
    remote_ip VARCHAR(60) NULL,
    processed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolution_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_collections_webhook_logs_transaction FOREIGN KEY (transaction_id) REFERENCES collections_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_collections_webhook_logs_pos FOREIGN KEY (pos_account_id) REFERENCES collections_pos_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_headers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    cari_id BIGINT NOT NULL,
    invoice_type ENUM('satis','alis','iade') NOT NULL DEFAULT 'satis',
    invoice_no VARCHAR(100) NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NULL,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    vat_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    paid_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_status VARCHAR(30) NOT NULL DEFAULT 'odenmedi',
    paid_at DATETIME NULL,
    edocument_type VARCHAR(50) NULL,
    edocument_status VARCHAR(50) NULL,
    edocument_uuid VARCHAR(150) NULL,
    edocument_response LONGTEXT NULL,
    edocument_sent_at DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_headers_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_headers_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT NOT NULL,
    payment_channel ENUM('kasa','banka') NOT NULL,
    payment_ref_id BIGINT NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    transaction_ref VARCHAR(150) NULL,
    notes TEXT NULL,
    payment_date DATETIME NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_payments_invoice (invoice_id, payment_date),
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_relations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_invoice_id BIGINT NOT NULL,
    target_invoice_id BIGINT NOT NULL,
    relation_type ENUM('iade','duzeltme') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_relations_pair (source_invoice_id, target_invoice_id, relation_type),
    KEY idx_invoice_relations_source (source_invoice_id, relation_type),
    KEY idx_invoice_relations_target (target_invoice_id, relation_type),
    CONSTRAINT fk_invoice_relations_source FOREIGN KEY (source_invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_relations_target FOREIGN KEY (target_invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_offers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    cari_id BIGINT NOT NULL,
    sales_user_id INT NULL,
    offer_no VARCHAR(100) NOT NULL,
    offer_date DATE NOT NULL,
    valid_until DATE NULL,
    status ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak',
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_offers_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_offers_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_offers_user FOREIGN KEY (sales_user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_offer_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    offer_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_offer_items_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_offer_revisions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    offer_id BIGINT NOT NULL,
    revision_no INT NOT NULL,
    version_label VARCHAR(30) NOT NULL,
    status VARCHAR(50) NOT NULL,
    valid_until DATE NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    snapshot_json LONGTEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_offer_revision (offer_id, revision_no),
    KEY idx_sales_offer_revision_offer (offer_id, created_at),
    CONSTRAINT fk_sales_offer_revisions_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_offer_revisions_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_offer_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NULL,
    product_id BIGINT NULL,
    default_description VARCHAR(255) NOT NULL,
    default_quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    default_unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    default_status ENUM('taslak','gonderildi','onaylandi','reddedildi') NOT NULL DEFAULT 'taslak',
    valid_day_count INT NOT NULL DEFAULT 15,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sales_offer_templates_status (status),
    CONSTRAINT fk_sales_offer_templates_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_offer_approval_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    offer_id BIGINT NOT NULL,
    action_name VARCHAR(50) NOT NULL,
    previous_status VARCHAR(50) NULL,
    current_status VARCHAR(50) NULL,
    note_text TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sales_offer_approval_offer (offer_id, created_at),
    CONSTRAINT fk_sales_offer_approval_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_offer_approval_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    cari_id BIGINT NOT NULL,
    offer_id BIGINT NULL,
    sales_user_id INT NULL,
    cargo_provider_id BIGINT NULL,
    order_no VARCHAR(100) NOT NULL,
    order_date DATE NOT NULL,
    status ENUM('bekliyor','hazirlaniyor','sevk','tamamlandi','iptal') NOT NULL DEFAULT 'bekliyor',
    delivery_status ENUM('bekliyor','hazirlaniyor','yolda','teslim_edildi','iade') NOT NULL DEFAULT 'bekliyor',
    delivered_at DATETIME NULL,
    grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cargo_company VARCHAR(100) NULL,
    tracking_no VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_orders_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_orders_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_orders_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_orders_user FOREIGN KEY (sales_user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_order_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_order_items_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_shipments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    warehouse_id BIGINT NULL,
    provider_id BIGINT NULL,
    shipment_no VARCHAR(100) NOT NULL,
    irsaliye_no VARCHAR(100) NOT NULL,
    label_no VARCHAR(100) NULL,
    shipment_date DATETIME NOT NULL,
    shipment_status ENUM('hazirlaniyor','sevk_edildi','teslim_edildi','iptal') NOT NULL DEFAULT 'hazirlaniyor',
    delivery_status ENUM('bekliyor','yolda','teslim_edildi','iade') NOT NULL DEFAULT 'bekliyor',
    cargo_company VARCHAR(100) NULL,
    tracking_no VARCHAR(100) NULL,
    edispatch_status VARCHAR(50) NULL,
    edispatch_uuid VARCHAR(150) NULL,
    edispatch_response LONGTEXT NULL,
    edispatch_sent_at DATETIME NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_shipments_shipment_no (shipment_no),
    UNIQUE KEY uq_sales_shipments_irsaliye_no (irsaliye_no),
    KEY idx_sales_shipments_order (order_id, shipment_date),
    CONSTRAINT fk_sales_shipments_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_shipments_warehouse FOREIGN KEY (warehouse_id) REFERENCES stock_warehouses(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_shipments_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_order_invoice_links (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    invoice_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_order_invoice (order_id, invoice_id),
    KEY idx_sales_order_invoice_order (order_id),
    CONSTRAINT fk_sales_order_invoice_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_order_invoice_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_shipment_invoice_links (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    shipment_id BIGINT NOT NULL,
    invoice_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_shipment_invoice (shipment_id, invoice_id),
    KEY idx_sales_shipment_invoice_shipment (shipment_id),
    KEY idx_sales_shipment_invoice_invoice (invoice_id),
    CONSTRAINT fk_sales_shipment_invoice_shipment FOREIGN KEY (shipment_id) REFERENCES sales_shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_shipment_invoice_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_cargo_providers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider_name VARCHAR(150) NOT NULL,
    provider_code VARCHAR(100) NOT NULL UNIQUE,
    api_mode ENUM('mock','manual') NOT NULL DEFAULT 'mock',
    account_no VARCHAR(100) NULL,
    sender_city VARCHAR(100) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_cargo_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    shipment_id BIGINT NULL,
    provider_id BIGINT NULL,
    action_name VARCHAR(50) NOT NULL,
    tracking_no VARCHAR(100) NULL,
    label_no VARCHAR(100) NULL,
    request_payload TEXT NULL,
    response_payload TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sales_cargo_logs_order (order_id, created_at),
    CONSTRAINT fk_sales_cargo_logs_order FOREIGN KEY (order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_cargo_logs_shipment FOREIGN KEY (shipment_id) REFERENCES sales_shipments(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_cargo_logs_provider FOREIGN KEY (provider_id) REFERENCES sales_cargo_providers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_cargo_logs_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_commission_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    target_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_commission_rules_user (user_id),
    CONSTRAINT fk_sales_commission_rules_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_targets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_year INT NOT NULL,
    target_month INT NOT NULL,
    target_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_targets_period (user_id, target_year, target_month),
    CONSTRAINT fk_sales_targets_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_customer_prices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    price_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'TRY',
    min_quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    valid_from DATE NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_customer_prices_pair (cari_id, product_id, min_quantity),
    CONSTRAINT fk_sales_customer_prices_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_customer_prices_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_discount_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NULL,
    rule_name VARCHAR(150) NOT NULL,
    discount_type ENUM('oran','tutar') NOT NULL DEFAULT 'oran',
    discount_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    min_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    valid_from DATE NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sales_discount_rules_cari (cari_id),
    CONSTRAINT fk_sales_discount_rules_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_promotion_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NULL,
    product_id BIGINT NULL,
    promo_name VARCHAR(150) NOT NULL,
    promo_type ENUM('oran','tutar') NOT NULL DEFAULT 'oran',
    promo_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    min_quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    min_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    valid_from DATE NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sales_promotion_rules_cari (cari_id),
    KEY idx_sales_promotion_rules_product (product_id),
    CONSTRAINT fk_sales_promotion_rules_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_promotion_rules_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_categories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT NULL,
    name VARCHAR(150) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_categories_parent FOREIGN KEY (parent_id) REFERENCES stock_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_warehouses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    name VARCHAR(150) NOT NULL,
    location_text VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_warehouses_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_products (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT NULL,
    product_type ENUM('ticari','kiralik','hammadde','yari_mamul','mamul','yedek_parca') NOT NULL DEFAULT 'ticari',
    sku VARCHAR(100) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL,
    name VARCHAR(200) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'adet',
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    sale_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    critical_stock DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    track_lot TINYINT(1) NOT NULL DEFAULT 0,
    track_serial TINYINT(1) NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_products_category FOREIGN KEY (category_id) REFERENCES stock_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_product_variants (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT NOT NULL,
    variant_name VARCHAR(150) NOT NULL,
    sku VARCHAR(100) NULL,
    barcode VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_product_variants_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    variant_id BIGINT NULL,
    movement_type ENUM('giris','cikis','transfer','sayim','uretim_giris','uretim_cikis','servis_cikis','kira_cikis') NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    lot_no VARCHAR(100) NULL,
    serial_no VARCHAR(100) NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT NULL,
    movement_date DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_movements_warehouse FOREIGN KEY (warehouse_id) REFERENCES stock_warehouses(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_variant FOREIGN KEY (variant_id) REFERENCES stock_product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_counts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT NOT NULL,
    count_date DATE NOT NULL,
    status ENUM('taslak','tamamlandi') NOT NULL DEFAULT 'taslak',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_counts_warehouse FOREIGN KEY (warehouse_id) REFERENCES stock_warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_fault_types (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_statuses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    color_code VARCHAR(20) NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_records (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    cari_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    fault_type_id BIGINT NULL,
    status_id BIGINT NULL,
    assigned_user_id INT NULL,
    service_no VARCHAR(100) NOT NULL,
    serial_no VARCHAR(100) NULL,
    complaint TEXT NULL,
    diagnosis TEXT NULL,
    warranty_status VARCHAR(100) NULL,
    warranty_start_date DATE NULL,
    warranty_end_date DATE NULL,
    warranty_provider VARCHAR(160) NULL,
    warranty_document_no VARCHAR(120) NULL,
    warranty_result VARCHAR(80) NULL,
    warranty_notes TEXT NULL,
    sla_priority VARCHAR(40) NULL,
    sla_response_due_at DATETIME NULL,
    sla_resolution_due_at DATETIME NULL,
    sla_responded_at DATETIME NULL,
    sla_resolved_at DATETIME NULL,
    sla_status VARCHAR(40) NULL,
    sla_notes TEXT NULL,
    acceptance_type VARCHAR(80) NULL,
    received_by VARCHAR(160) NULL,
    received_accessories TEXT NULL,
    device_condition TEXT NULL,
    customer_approval_note TEXT NULL,
    estimated_delivery_date DATE NULL,
    acceptance_signed_by VARCHAR(160) NULL,
    cost_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    labor_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    external_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    service_revenue DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    delivery_date DATETIME NULL,
    delivered_by VARCHAR(160) NULL,
    delivered_to VARCHAR(160) NULL,
    delivery_status VARCHAR(40) NULL,
    delivery_notes TEXT NULL,
    customer_approval_status VARCHAR(40) NULL,
    customer_approval_at DATETIME NULL,
    customer_approved_by VARCHAR(160) NULL,
    customer_approval_channel VARCHAR(80) NULL,
    customer_approval_description TEXT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_records_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_records_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_records_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_records_fault FOREIGN KEY (fault_type_id) REFERENCES service_fault_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_records_status FOREIGN KEY (status_id) REFERENCES service_statuses(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_records_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_parts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    service_record_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    used_at DATETIME NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_parts_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_parts_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_parts_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    service_record_id BIGINT NOT NULL,
    note_text TEXT NOT NULL,
    is_customer_visible TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_notes_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_notes_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_service_appointments_date (appointment_at),
    CONSTRAINT fk_service_appointments_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_appointments_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_appointments_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_service_steps_status (status),
    CONSTRAINT fk_service_steps_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_steps_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_steps_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_photos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    service_record_id BIGINT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    uploaded_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service_photos_record (service_record_id),
    CONSTRAINT fk_service_photos_record FOREIGN KEY (service_record_id) REFERENCES service_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_photos_user FOREIGN KEY (uploaded_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_device_categories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_devices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT NULL,
    product_id BIGINT NULL,
    device_name VARCHAR(200) NOT NULL,
    serial_no VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('aktif','pasif','bakimda','kirada') NOT NULL DEFAULT 'aktif',
    location_text VARCHAR(200) NULL,
    purchase_date DATE NULL,
    purchase_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_devices_category FOREIGN KEY (category_id) REFERENCES rental_device_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_rental_devices_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_contracts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    cari_id BIGINT NOT NULL,
    device_id BIGINT NOT NULL,
    contract_no VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    monthly_rent DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    deposit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('taslak','aktif','tamamlandi','iptal') NOT NULL DEFAULT 'taslak',
    billing_day INT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_contracts_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_rental_contracts_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_contracts_device FOREIGN KEY (device_id) REFERENCES rental_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('bekliyor','odendi','gecikmis') NOT NULL DEFAULT 'bekliyor',
    paid_at DATETIME NULL,
    accrual_period VARCHAR(7) NULL,
    accrual_source VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_payments_contract FOREIGN KEY (contract_id) REFERENCES rental_contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    INDEX idx_rental_contract_renewals_contract (contract_id),
    CONSTRAINT fk_rental_contract_renewals_contract FOREIGN KEY (contract_id) REFERENCES rental_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_contract_renewals_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_payment_invoices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT NOT NULL,
    invoice_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rental_payment_invoice_payment (payment_id),
    INDEX idx_rental_payment_invoice_invoice (invoice_id),
    CONSTRAINT fk_rental_payment_invoices_payment FOREIGN KEY (payment_id) REFERENCES rental_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_payment_invoices_invoice FOREIGN KEY (invoice_id) REFERENCES invoice_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    UNIQUE KEY uq_rental_contract_protocol_no (protocol_no),
    CONSTRAINT fk_rental_contract_protocols_contract FOREIGN KEY (contract_id) REFERENCES rental_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_contract_protocols_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_rental_return_checklists_device (device_id),
    CONSTRAINT fk_rental_return_checklists_contract FOREIGN KEY (contract_id) REFERENCES rental_contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_return_checklists_device FOREIGN KEY (device_id) REFERENCES rental_devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_rental_return_checklists_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rental_service_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT NOT NULL,
    event_type ENUM('teslim','iade','bakim','hasar') NOT NULL,
    event_date DATETIME NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_service_logs_device FOREIGN KEY (device_id) REFERENCES rental_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_recipes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT NOT NULL,
    recipe_code VARCHAR(100) NOT NULL,
    version_no VARCHAR(30) NOT NULL DEFAULT '1.0',
    status ENUM('taslak','onayli','pasif') NOT NULL DEFAULT 'taslak',
    output_quantity DECIMAL(15,3) NOT NULL DEFAULT 1.000,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_production_recipes_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_recipe_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipe_id BIGINT NOT NULL,
    material_product_id BIGINT NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    wastage_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_production_recipe_items_recipe FOREIGN KEY (recipe_id) REFERENCES production_recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_recipe_items_product FOREIGN KEY (material_product_id) REFERENCES stock_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    UNIQUE KEY uq_production_recipe_subrecipes_pair (parent_recipe_id, child_recipe_id),
    CONSTRAINT fk_production_recipe_subrecipes_parent FOREIGN KEY (parent_recipe_id) REFERENCES production_recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_recipe_subrecipes_child FOREIGN KEY (child_recipe_id) REFERENCES production_recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NULL,
    recipe_id BIGINT NOT NULL,
    order_no VARCHAR(100) NOT NULL,
    planned_quantity DECIMAL(15,3) NOT NULL,
    actual_quantity DECIMAL(15,3) NULL,
    batch_no VARCHAR(100) NULL,
    status ENUM('planlandi','uretimde','tamamlandi','iptal') NOT NULL DEFAULT 'planlandi',
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_production_orders_branch FOREIGN KEY (branch_id) REFERENCES core_branches(id) ON DELETE SET NULL,
    CONSTRAINT fk_production_orders_recipe FOREIGN KEY (recipe_id) REFERENCES production_recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
    UNIQUE KEY uq_production_recipe_routes_step (recipe_id, sequence_no),
    CONSTRAINT fk_production_recipe_routes_recipe FOREIGN KEY (recipe_id) REFERENCES production_recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_recipe_routes_work_center FOREIGN KEY (work_center_id) REFERENCES production_work_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_production_schedule_dates (planned_start, planned_end),
    CONSTRAINT fk_production_schedule_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_schedule_work_center FOREIGN KEY (work_center_id) REFERENCES production_work_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_production_waste_records_date (record_date),
    CONSTRAINT fk_production_waste_records_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_waste_records_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_production_cost_snapshots_date (calculated_at),
    CONSTRAINT fk_production_cost_snapshots_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    INDEX idx_production_semi_flows_from_center (from_work_center_id),
    INDEX idx_production_semi_flows_to_center (to_work_center_id),
    INDEX idx_production_semi_flows_status (flow_status),
    INDEX idx_production_semi_flows_date (flow_date),
    CONSTRAINT fk_production_semi_flows_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_semi_flows_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_semi_flows_from_center FOREIGN KEY (from_work_center_id) REFERENCES production_work_centers(id) ON DELETE SET NULL,
    CONSTRAINT fk_production_semi_flows_to_center FOREIGN KEY (to_work_center_id) REFERENCES production_work_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    INDEX idx_production_deadline_priority (priority),
    CONSTRAINT fk_production_deadline_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    INDEX idx_production_approvals_requested (requested_at),
    CONSTRAINT fk_production_approvals_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_order_operations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    production_order_id BIGINT NOT NULL,
    work_center_id BIGINT NULL,
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
    INDEX idx_production_order_operations_work_center (work_center_id),
    INDEX idx_production_order_operations_status (status),
    CONSTRAINT fk_production_order_operations_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_order_operations_work_center FOREIGN KEY (work_center_id) REFERENCES production_work_centers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_consumptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    production_order_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    warehouse_id BIGINT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_production_consumptions_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_consumptions_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_consumptions_warehouse FOREIGN KEY (warehouse_id) REFERENCES stock_warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_outputs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    production_order_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    warehouse_id BIGINT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    barcode VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_production_outputs_order FOREIGN KEY (production_order_id) REFERENCES production_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_outputs_product FOREIGN KEY (product_id) REFERENCES stock_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_production_outputs_warehouse FOREIGN KEY (warehouse_id) REFERENCES stock_warehouses(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hr_departments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hr_employees (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT NULL,
    user_id INT NULL,
    full_name VARCHAR(150) NOT NULL,
    title VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    start_date DATE NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hr_employees_department FOREIGN KEY (department_id) REFERENCES hr_departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_hr_employees_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hr_shifts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    status VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hr_shifts_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hr_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT NOT NULL,
    assignment_type VARCHAR(100) NOT NULL,
    description TEXT NULL,
    assigned_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hr_assignments_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS docs_files (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(100) NOT NULL,
    related_table VARCHAR(100) NULL,
    related_id BIGINT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    note_text TEXT NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_notes_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_notes_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_activities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    activity_type ENUM('arama','toplanti','e-posta','ziyaret','gorev','diger') NOT NULL DEFAULT 'arama',
    activity_subject VARCHAR(180) NOT NULL,
    activity_result VARCHAR(120) NULL,
    activity_at DATETIME NOT NULL,
    next_action_at DATETIME NULL,
    responsible_name VARCHAR(150) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_activities_cari (cari_id),
    INDEX idx_crm_activities_type (activity_type),
    INDEX idx_crm_activities_date (activity_at),
    CONSTRAINT fk_crm_activities_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_activities_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_call_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    call_direction ENUM('gelen','giden') NOT NULL DEFAULT 'giden',
    call_subject VARCHAR(180) NOT NULL,
    call_result ENUM('ulasildi','ulasamadi','mesgul','geri_arayacak','olumsuz','diger') NOT NULL DEFAULT 'ulasildi',
    call_at DATETIME NOT NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    callback_at DATETIME NULL,
    phone_number VARCHAR(50) NULL,
    responsible_name VARCHAR(150) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_call_logs_cari (cari_id),
    INDEX idx_crm_call_logs_direction (call_direction),
    INDEX idx_crm_call_logs_result (call_result),
    INDEX idx_crm_call_logs_date (call_at),
    CONSTRAINT fk_crm_call_logs_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_call_logs_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_meeting_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    meeting_type ENUM('telefon','online','ofis','saha','diger') NOT NULL DEFAULT 'online',
    meeting_subject VARCHAR(180) NOT NULL,
    meeting_at DATETIME NOT NULL,
    participants TEXT NULL,
    decisions TEXT NULL,
    action_items TEXT NULL,
    follow_up_at DATETIME NULL,
    responsible_name VARCHAR(150) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crm_meeting_notes_cari (cari_id),
    INDEX idx_crm_meeting_notes_type (meeting_type),
    INDEX idx_crm_meeting_notes_date (meeting_at),
    INDEX idx_crm_meeting_notes_followup (follow_up_at),
    CONSTRAINT fk_crm_meeting_notes_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_meeting_notes_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_reminders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    reminder_text VARCHAR(255) NOT NULL,
    remind_at DATETIME NOT NULL,
    status ENUM('bekliyor','tamamlandi','iptal') NOT NULL DEFAULT 'bekliyor',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_reminders_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_reminders_user FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_opportunities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    title VARCHAR(200) NOT NULL,
    stage VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    probability_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    probability_note VARCHAR(255) NULL,
    source_channel VARCHAR(80) NULL,
    source_campaign VARCHAR(150) NULL,
    source_referrer VARCHAR(180) NULL,
    expected_close_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_opportunities_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_opportunity_offer_links (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT NOT NULL,
    offer_id BIGINT NOT NULL,
    relation_status ENUM('taslak','aktif','kazanildi','kaybedildi','iptal') NOT NULL DEFAULT 'aktif',
    relation_note TEXT NULL,
    linked_by VARCHAR(150) NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_opportunity_offer (opportunity_id, offer_id),
    KEY idx_crm_opportunity_offer_opportunity (opportunity_id),
    KEY idx_crm_opportunity_offer_offer (offer_id),
    KEY idx_crm_opportunity_offer_status (relation_status),
    CONSTRAINT fk_crm_opportunity_offer_opportunity FOREIGN KEY (opportunity_id) REFERENCES crm_opportunities(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_opportunity_offer_offer FOREIGN KEY (offer_id) REFERENCES sales_offers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_tags (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(80) NOT NULL,
    tag_color VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_tags_name (tag_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_cari_tags (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    tag_id BIGINT NOT NULL,
    tag_note VARCHAR(255) NULL,
    assigned_by VARCHAR(150) NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_cari_tag (cari_id, tag_id),
    KEY idx_crm_cari_tags_cari (cari_id),
    KEY idx_crm_cari_tags_tag (tag_id),
    CONSTRAINT fk_crm_cari_tags_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_cari_tags_tag FOREIGN KEY (tag_id) REFERENCES crm_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_campaigns (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_name VARCHAR(160) NOT NULL,
    channel ENUM('email','sms') NOT NULL DEFAULT 'email',
    subject_line VARCHAR(255) NULL,
    message_body TEXT NOT NULL,
    target_segment VARCHAR(80) NULL,
    target_tag_id BIGINT NULL,
    target_source VARCHAR(80) NULL,
    planned_at DATETIME NOT NULL,
    queued_count INT NOT NULL DEFAULT 0,
    skipped_count INT NOT NULL DEFAULT 0,
    created_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_crm_campaigns_channel (channel),
    KEY idx_crm_campaigns_planned (planned_at),
    CONSTRAINT fk_crm_campaigns_tag FOREIGN KEY (target_tag_id) REFERENCES crm_tags(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_email_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NULL,
    subject_line VARCHAR(255) NOT NULL,
    body_template TEXT NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_email_template_name (template_name),
    KEY idx_crm_email_templates_status (status),
    KEY idx_crm_email_templates_category (category_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_sms_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NULL,
    body_template VARCHAR(500) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_crm_sms_template_name (template_name),
    KEY idx_crm_sms_templates_status (status),
    KEY idx_crm_sms_templates_category (category_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_whatsapp_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NULL,
    phone_number VARCHAR(50) NOT NULL,
    normalized_phone VARCHAR(30) NOT NULL,
    message_body TEXT NOT NULL,
    whatsapp_url TEXT NOT NULL,
    status ENUM('hazirlandi','gonderildi','iptal') NOT NULL DEFAULT 'hazirlandi',
    created_by VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_crm_whatsapp_cari (cari_id),
    KEY idx_crm_whatsapp_status (status),
    KEY idx_crm_whatsapp_created (created_at),
    CONSTRAINT fk_crm_whatsapp_messages_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_tasks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cari_id BIGINT NOT NULL,
    opportunity_id BIGINT NULL,
    task_title VARCHAR(180) NOT NULL,
    task_description TEXT NULL,
    assigned_user_id INT NULL,
    assigned_name VARCHAR(150) NULL,
    priority ENUM('dusuk','normal','yuksek','kritik') NOT NULL DEFAULT 'normal',
    status ENUM('bekliyor','devam','tamamlandi','iptal') NOT NULL DEFAULT 'bekliyor',
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_crm_tasks_cari (cari_id),
    KEY idx_crm_tasks_opportunity (opportunity_id),
    KEY idx_crm_tasks_assigned (assigned_user_id),
    KEY idx_crm_tasks_status (status, due_at),
    CONSTRAINT fk_crm_tasks_cari FOREIGN KEY (cari_id) REFERENCES cari_cards(id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_tasks_opportunity FOREIGN KEY (opportunity_id) REFERENCES crm_opportunities(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_tasks_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES core_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_crm_tasks_created_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
