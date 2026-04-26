<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Cari ve finans modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function cari_label(array $cari): string
{
    if (!empty($cari['company_name'])) {
        return (string) $cari['company_name'];
    }

    if (!empty($cari['full_name'])) {
        return (string) $cari['full_name'];
    }

    return 'Cari #' . (int) $cari['id'];
}

function finance_insert_cari_movement(PDO $db, array $data): void
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
        'currency_code' => $data['currency_code'],
        'description' => $data['description'],
        'movement_date' => $data['movement_date'],
        'created_by' => 1,
    ]);
}

function finance_post_redirect(string $result): void
{
    app_redirect('index.php?module=cari&ok=' . urlencode($result));
}

function finance_build_cari_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'card_type' => trim((string) ($_GET['card_type'] ?? '')),
        'balance_sort' => trim((string) ($_GET['balance_sort'] ?? 'id_desc')),
        'ledger_sort' => trim((string) ($_GET['ledger_sort'] ?? 'date_desc')),
        'balance_page' => max(1, (int) ($_GET['balance_page'] ?? 1)),
        'ledger_page' => max(1, (int) ($_GET['ledger_page'] ?? 1)),
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_cari') {
            $isCompany = isset($_POST['is_company']) ? 1 : 0;
            $companyName = trim((string) ($_POST['company_name'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));

            $stmt = $db->prepare('
                INSERT INTO cari_cards (
                    branch_id, card_type, is_company, company_name, full_name, phone, email, city, risk_limit, notes
                ) VALUES (
                    :branch_id, :card_type, :is_company, :company_name, :full_name, :phone, :email, :city, :risk_limit, :notes
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'card_type' => $_POST['card_type'] ?? 'musteri',
                'is_company' => $isCompany,
                'company_name' => $companyName !== '' ? $companyName : null,
                'full_name' => $fullName !== '' ? $fullName : null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'city' => trim((string) ($_POST['city'] ?? '')) ?: null,
                'risk_limit' => (float) ($_POST['risk_limit'] ?? 0),
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ]);
            finance_post_redirect('cari');
        }

        if ($action === 'create_cashbox') {
            $stmt = $db->prepare('
                INSERT INTO finance_cashboxes (branch_id, name, currency_code, opening_balance)
                VALUES (:branch_id, :name, :currency_code, :opening_balance)
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'currency_code' => trim((string) ($_POST['currency_code'] ?? 'TRY')) ?: 'TRY',
                'opening_balance' => (float) ($_POST['opening_balance'] ?? 0),
            ]);
            finance_post_redirect('cashbox');
        }

        if ($action === 'create_bank') {
            $stmt = $db->prepare('
                INSERT INTO finance_bank_accounts (branch_id, bank_name, account_name, iban, currency_code, opening_balance)
                VALUES (:branch_id, :bank_name, :account_name, :iban, :currency_code, :opening_balance)
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'bank_name' => trim((string) ($_POST['bank_name'] ?? '')),
                'account_name' => trim((string) ($_POST['account_name'] ?? '')) ?: null,
                'iban' => trim((string) ($_POST['iban'] ?? '')) ?: null,
                'currency_code' => trim((string) ($_POST['currency_code'] ?? 'TRY')) ?: 'TRY',
                'opening_balance' => (float) ($_POST['opening_balance'] ?? 0),
            ]);
            finance_post_redirect('bank');
        }

        if ($action === 'create_cari_slip') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);
            $slipType = (string) ($_POST['slip_type'] ?? 'tahsilat');
            $channel = (string) ($_POST['channel'] ?? 'kasa');
            $description = trim((string) ($_POST['description'] ?? '')) ?: null;
            $movementDate = date('Y-m-d H:i:s');

            if ($cariId <= 0 || $amount <= 0) {
                throw new RuntimeException('Cari ve tutar alani zorunludur.');
            }

            if ($channel === 'kasa') {
                $cashboxId = (int) ($_POST['cashbox_id'] ?? 0);

                if ($cashboxId <= 0) {
                    throw new RuntimeException('Kasa secimi zorunludur.');
                }

                $stmt = $db->prepare('
                    INSERT INTO finance_cash_movements (cashbox_id, cari_id, movement_type, amount, description, movement_date)
                    VALUES (:cashbox_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                ');
                $stmt->execute([
                    'cashbox_id' => $cashboxId,
                    'cari_id' => $cariId,
                    'movement_type' => $slipType === 'tahsilat' ? 'giris' : 'cikis',
                    'amount' => $amount,
                    'description' => $description,
                    'movement_date' => $movementDate,
                ]);

                finance_insert_cari_movement($db, [
                    'cari_id' => $cariId,
                    'movement_type' => $slipType === 'tahsilat' ? 'alacak' : 'borc',
                    'source_module' => 'finans',
                    'source_table' => 'finance_cash_movements',
                    'source_id' => (int) $db->lastInsertId(),
                    'amount' => $amount,
                    'currency_code' => 'TRY',
                    'description' => $description ?: ($slipType === 'tahsilat' ? 'Kasa tahsilati' : 'Kasa odemesi'),
                    'movement_date' => $movementDate,
                ]);
            } else {
                $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);

                if ($bankAccountId <= 0) {
                    throw new RuntimeException('Banka hesabi secimi zorunludur.');
                }

                $stmt = $db->prepare('
                    INSERT INTO finance_bank_movements (bank_account_id, cari_id, movement_type, amount, description, movement_date)
                    VALUES (:bank_account_id, :cari_id, :movement_type, :amount, :description, :movement_date)
                ');
                $stmt->execute([
                    'bank_account_id' => $bankAccountId,
                    'cari_id' => $cariId,
                    'movement_type' => $slipType === 'tahsilat' ? 'giris' : 'cikis',
                    'amount' => $amount,
                    'description' => $description,
                    'movement_date' => $movementDate,
                ]);

                finance_insert_cari_movement($db, [
                    'cari_id' => $cariId,
                    'movement_type' => $slipType === 'tahsilat' ? 'alacak' : 'borc',
                    'source_module' => 'finans',
                    'source_table' => 'finance_bank_movements',
                    'source_id' => (int) $db->lastInsertId(),
                    'amount' => $amount,
                    'currency_code' => 'TRY',
                    'description' => $description ?: ($slipType === 'tahsilat' ? 'Banka tahsilati' : 'Banka odemesi'),
                    'movement_date' => $movementDate,
                ]);
            }

            finance_post_redirect('slip');
        }

        if ($action === 'create_manual_cari_movement') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);

            if ($cariId <= 0 || $amount <= 0) {
                throw new RuntimeException('Cari ve tutar alani zorunludur.');
            }

            finance_insert_cari_movement($db, [
                'cari_id' => $cariId,
                'movement_type' => (string) ($_POST['movement_type'] ?? 'borc'),
                'source_module' => 'manuel',
                'source_table' => 'cari_movements',
                'source_id' => null,
                'amount' => $amount,
                'currency_code' => 'TRY',
                'description' => trim((string) ($_POST['description'] ?? '')) ?: 'Manuel cari fis kaydi',
                'movement_date' => date('Y-m-d H:i:s'),
            ]);

            finance_post_redirect('cari_movement');
        }

        if ($action === 'delete_cari') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);

            if ($cariId <= 0) {
                throw new RuntimeException('Gecerli bir cari secilmedi.');
            }
            app_assert_branch_access($db, 'cari_cards', $cariId);

            $stmt = $db->prepare('DELETE FROM cari_cards WHERE id = :id');
            $stmt->execute(['id' => $cariId]);

            finance_post_redirect('delete_cari');
        }

        if ($action === 'delete_cashbox') {
            $cashboxId = (int) ($_POST['cashbox_id'] ?? 0);

            if ($cashboxId <= 0) {
                throw new RuntimeException('Gecerli bir kasa secilmedi.');
            }
            app_assert_branch_access($db, 'finance_cashboxes', $cashboxId);

            $stmt = $db->prepare('DELETE FROM finance_cashboxes WHERE id = :id');
            $stmt->execute(['id' => $cashboxId]);

            finance_post_redirect('delete_cashbox');
        }

        if ($action === 'delete_bank_account') {
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);

            if ($bankAccountId <= 0) {
                throw new RuntimeException('Gecerli bir banka hesabi secilmedi.');
            }
            app_assert_branch_access($db, 'finance_bank_accounts', $bankAccountId);

            $stmt = $db->prepare('DELETE FROM finance_bank_accounts WHERE id = :id');
            $stmt->execute(['id' => $bankAccountId]);

            finance_post_redirect('delete_bank_account');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Cari/finans islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = finance_build_cari_filters();
[$cariScopeWhere, $cariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$cashboxScopeWhere, $cashboxScopeParams] = app_branch_scope_filter($db, null);
[$bankScopeWhere, $bankScopeParams] = app_branch_scope_filter($db, null);

$cariCards = app_fetch_all($db, 'SELECT c.id, c.card_type, c.is_company, c.company_name, c.full_name, c.phone, c.email, c.city, c.risk_limit, c.created_at FROM cari_cards c ' . ($cariScopeWhere !== '' ? 'WHERE ' . $cariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 200', $cariScopeParams);
$cashboxes = app_fetch_all($db, 'SELECT id, name, currency_code, opening_balance, created_at FROM finance_cashboxes ' . ($cashboxScopeWhere !== '' ? 'WHERE ' . $cashboxScopeWhere : '') . ' ORDER BY id DESC LIMIT 20', $cashboxScopeParams);
$bankAccounts = app_fetch_all($db, 'SELECT id, bank_name, account_name, iban, currency_code, opening_balance, created_at FROM finance_bank_accounts ' . ($bankScopeWhere !== '' ? 'WHERE ' . $bankScopeWhere : '') . ' ORDER BY id DESC LIMIT 20', $bankScopeParams);

$cariFilterWhere = [];
$cariFilterParams = [];

if ($filters['search'] !== '') {
    $cariFilterWhere[] = '(c.company_name LIKE :search OR c.full_name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search OR c.city LIKE :search)';
    $cariFilterParams['search'] = '%' . $filters['search'] . '%';
}

if ($filters['card_type'] !== '') {
    $cariFilterWhere[] = 'c.card_type = :card_type';
    $cariFilterParams['card_type'] = $filters['card_type'];
}
if ($cariScopeWhere !== '') {
    $cariFilterWhere[] = $cariScopeWhere;
    $cariFilterParams = array_merge($cariFilterParams, $cariScopeParams);
}

$cariWhereSql = $cariFilterWhere ? 'WHERE ' . implode(' AND ', $cariFilterWhere) : '';

$cashMovements = app_fetch_all($db, 'SELECT m.id, c.name AS cashbox_name, cc.company_name, cc.full_name, m.movement_type, m.amount, m.description, m.movement_date FROM finance_cash_movements m INNER JOIN finance_cashboxes c ON c.id = m.cashbox_id LEFT JOIN cari_cards cc ON cc.id = m.cari_id ORDER BY m.id DESC LIMIT 10');
$bankMovements = app_fetch_all($db, 'SELECT m.id, b.bank_name, b.account_name, cc.company_name, cc.full_name, m.movement_type, m.amount, m.description, m.movement_date FROM finance_bank_movements m INNER JOIN finance_bank_accounts b ON b.id = m.bank_account_id LEFT JOIN cari_cards cc ON cc.id = m.cari_id ORDER BY m.id DESC LIMIT 10');

$cariBalances = app_fetch_all($db, "
    SELECT
        c.id,
        c.card_type,
        c.company_name,
        c.full_name,
        c.phone,
        c.city,
        c.risk_limit,
        COALESCE(SUM(CASE WHEN m.movement_type = 'borc' THEN m.amount ELSE 0 END), 0) AS borc_total,
        COALESCE(SUM(CASE WHEN m.movement_type = 'alacak' THEN m.amount ELSE 0 END), 0) AS alacak_total,
        COALESCE(SUM(CASE WHEN m.movement_type = 'borc' THEN m.amount ELSE -m.amount END), 0) AS bakiye
    FROM cari_cards c
    LEFT JOIN cari_movements m ON m.cari_id = c.id
    {$cariWhereSql}
    GROUP BY c.id, c.card_type, c.company_name, c.full_name, c.phone, c.city, c.risk_limit
    ORDER BY c.id DESC
    LIMIT 50
", $cariFilterParams);

$ledgerWhere = [];
$ledgerParams = [];

if ($filters['search'] !== '') {
    $ledgerWhere[] = '(c.company_name LIKE :search OR c.full_name LIKE :search OR m.description LIKE :search OR m.source_module LIKE :search OR m.source_table LIKE :search)';
    $ledgerParams['search'] = '%' . $filters['search'] . '%';
}

if ($filters['card_type'] !== '') {
    $ledgerWhere[] = 'c.card_type = :card_type';
    $ledgerParams['card_type'] = $filters['card_type'];
}
if ($cariScopeWhere !== '') {
    $ledgerWhere[] = $cariScopeWhere;
    $ledgerParams = array_merge($ledgerParams, $cariScopeParams);
}

$ledgerWhereSql = $ledgerWhere ? 'WHERE ' . implode(' AND ', $ledgerWhere) : '';

$cariLedger = app_fetch_all($db, "
    SELECT
        m.id,
        m.cari_id,
        c.company_name,
        c.full_name,
        m.movement_type,
        m.source_module,
        m.source_table,
        m.amount,
        m.description,
        m.movement_date
    FROM cari_movements m
    INNER JOIN cari_cards c ON c.id = m.cari_id
    {$ledgerWhereSql}
    ORDER BY m.id DESC
    LIMIT 50
", $ledgerParams);

$cashboxBalances = app_fetch_all($db, "
    SELECT
        c.id,
        c.name,
        c.currency_code,
        c.opening_balance,
        c.opening_balance
            + COALESCE(SUM(CASE WHEN m.movement_type = 'giris' THEN m.amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN m.movement_type = 'cikis' THEN m.amount ELSE 0 END), 0) AS current_balance
    FROM finance_cashboxes c
    LEFT JOIN finance_cash_movements m ON m.cashbox_id = c.id
    " . ($cashboxScopeWhere !== '' ? 'WHERE c.' . $cashboxScopeWhere : '') . "
    GROUP BY c.id, c.name, c.currency_code, c.opening_balance
    ORDER BY c.id DESC
", $cashboxScopeParams);

$bankBalances = app_fetch_all($db, "
    SELECT
        b.id,
        b.bank_name,
        b.account_name,
        b.currency_code,
        b.opening_balance,
        b.opening_balance
            + COALESCE(SUM(CASE WHEN m.movement_type = 'giris' THEN m.amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN m.movement_type = 'cikis' THEN m.amount ELSE 0 END), 0) AS current_balance
    FROM finance_bank_accounts b
    LEFT JOIN finance_bank_movements m ON m.bank_account_id = b.id
    " . ($bankScopeWhere !== '' ? 'WHERE b.' . $bankScopeWhere : '') . "
    GROUP BY b.id, b.bank_name, b.account_name, b.currency_code, b.opening_balance
    ORDER BY b.id DESC
", $bankScopeParams);
$cariDocCounts = app_related_doc_counts($db, 'cari', 'cari_cards', array_column($cariBalances, 'id'));

$cariBalances = app_sort_rows($cariBalances, $filters['balance_sort'], [
    'id_desc' => ['id', 'desc'],
    'name_asc' => ['company_name', 'asc'],
    'name_desc' => ['company_name', 'desc'],
    'balance_desc' => ['bakiye', 'desc'],
    'balance_asc' => ['bakiye', 'asc'],
]);
$cariLedger = app_sort_rows($cariLedger, $filters['ledger_sort'], [
    'date_desc' => ['movement_date', 'desc'],
    'date_asc' => ['movement_date', 'asc'],
    'amount_desc' => ['amount', 'desc'],
    'amount_asc' => ['amount', 'asc'],
]);

$cariBalancesPagination = app_paginate_rows($cariBalances, $filters['balance_page'], 10);
$cariLedgerPagination = app_paginate_rows($cariLedger, $filters['ledger_page'], 10);
$cariBalances = $cariBalancesPagination['items'];
$cariLedger = $cariLedgerPagination['items'];

$summary = [
    'Toplam Cari' => app_table_count($db, 'cari_cards'),
    'Kasa Sayisi' => app_table_count($db, 'finance_cashboxes'),
    'Banka Hesabi' => app_table_count($db, 'finance_bank_accounts'),
    'Cari Fisi' => app_table_count($db, 'cari_movements'),
    'Kasa Bakiyesi' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_cash_movements),0) FROM finance_cashboxes"), 2, ',', '.'),
    'Banka Bakiyesi' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(opening_balance),0) + COALESCE((SELECT SUM(CASE WHEN movement_type='giris' THEN amount ELSE -amount END) FROM finance_bank_movements),0) FROM finance_bank_accounts"), 2, ',', '.'),
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
    <h3>Cari Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="cari">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Cari, telefon, e-posta, aciklama">
        </div>
        <div>
            <label>Kart Tipi</label>
            <select name="card_type">
                <option value="">Tum tipler</option>
                <option value="musteri" <?= $filters['card_type'] === 'musteri' ? 'selected' : '' ?>>Musteri</option>
                <option value="tedarikci" <?= $filters['card_type'] === 'tedarikci' ? 'selected' : '' ?>>Tedarikci</option>
                <option value="bayi" <?= $filters['card_type'] === 'bayi' ? 'selected' : '' ?>>Bayi</option>
                <option value="personel" <?= $filters['card_type'] === 'personel' ? 'selected' : '' ?>>Personel</option>
                <option value="ortak" <?= $filters['card_type'] === 'ortak' ? 'selected' : '' ?>>Ortak</option>
                <option value="diger" <?= $filters['card_type'] === 'diger' ? 'selected' : '' ?>>Diger</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=cari" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="cari">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="card_type" value="<?= app_h($filters['card_type']) ?>">
        <div>
            <label>Cari Listesi Siralama</label>
            <select name="balance_sort">
                <option value="id_desc" <?= $filters['balance_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="name_asc" <?= $filters['balance_sort'] === 'name_asc' ? 'selected' : '' ?>>Cari A-Z</option>
                <option value="name_desc" <?= $filters['balance_sort'] === 'name_desc' ? 'selected' : '' ?>>Cari Z-A</option>
                <option value="balance_desc" <?= $filters['balance_sort'] === 'balance_desc' ? 'selected' : '' ?>>Bakiye yuksek</option>
                <option value="balance_asc" <?= $filters['balance_sort'] === 'balance_asc' ? 'selected' : '' ?>>Bakiye dusuk</option>
            </select>
        </div>
        <div>
            <label>Ekstre Siralama</label>
            <select name="ledger_sort">
                <option value="date_desc" <?= $filters['ledger_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['ledger_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="amount_desc" <?= $filters['ledger_sort'] === 'amount_desc' ? 'selected' : '' ?>>Tutar yuksek</option>
                <option value="amount_asc" <?= $filters['ledger_sort'] === 'amount_asc' ? 'selected' : '' ?>>Tutar dusuk</option>
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
        <h3>Yeni Cari Kart</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_cari">
            <div>
                <label>Kart Tipi</label>
                <select name="card_type">
                    <option value="musteri">Musteri</option>
                    <option value="tedarikci">Tedarikci</option>
                    <option value="bayi">Bayi</option>
                    <option value="personel">Personel</option>
                    <option value="ortak">Ortak</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Telefon</label>
                <input name="phone" placeholder="05xx xxx xx xx">
            </div>
            <div>
                <label>Firma Adi</label>
                <input name="company_name" placeholder="Firma unvani">
            </div>
            <div>
                <label>Kisi Adi</label>
                <input name="full_name" placeholder="Yetkili veya bireysel musteri">
            </div>
            <div>
                <label>E-posta</label>
                <input name="email" type="email" placeholder="ornek@firma.com">
            </div>
            <div>
                <label>Sehir</label>
                <input name="city" placeholder="Istanbul">
            </div>
            <div>
                <label>Risk Limiti</label>
                <input name="risk_limit" type="number" step="0.01" value="0">
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="is_company" value="1"> Kurumsal cari</label>
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Cari ile ilgili kisa notlar"></textarea>
            </div>
            <div class="full">
                <button type="submit">Cari Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Kasa ve Banka Tanimlari</h3>
        <div class="stack">
            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_cashbox">
                <div><label>Kasa Adi</label><input name="name" placeholder="Merkez Kasa"></div>
                <div><label>Para Birimi</label><input name="currency_code" value="TRY"></div>
                <div><label>Acilis Bakiye</label><input name="opening_balance" type="number" step="0.01" value="0"></div>
                <div><button type="submit">Kasa Ekle</button></div>
            </form>

            <form method="post" class="form-grid compact-form">
                <input type="hidden" name="action" value="create_bank">
                <div><label>Banka</label><input name="bank_name" placeholder="Ziraat / Garanti / Enpara"></div>
                <div><label>Hesap Adi</label><input name="account_name" placeholder="TL Hesabi"></div>
                <div><label>IBAN</label><input name="iban" placeholder="TR..."></div>
                <div><label>Para Birimi</label><input name="currency_code" value="TRY"></div>
                <div><label>Acilis Bakiye</label><input name="opening_balance" type="number" step="0.01" value="0"></div>
                <div><button type="submit">Banka Hesabi Ekle</button></div>
            </form>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Tahsilat / Odeme Fisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_cari_slip">
            <div>
                <label>Fis Tipi</label>
                <select name="slip_type">
                    <option value="tahsilat">Tahsilat</option>
                    <option value="odeme">Odeme</option>
                </select>
            </div>
            <div>
                <label>Kanal</label>
                <select name="channel">
                    <option value="kasa">Kasa</option>
                    <option value="banka">Banka</option>
                </select>
            </div>
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input name="amount" type="number" step="0.01" min="0.01" required>
            </div>
            <div>
                <label>Kasa</label>
                <select name="cashbox_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($cashboxes as $cashbox): ?>
                        <option value="<?= (int) $cashbox['id'] ?>"><?= app_h($cashbox['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Banka Hesabi</label>
                <select name="bank_account_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <input name="description" placeholder="Tahsilat/odeme aciklamasi">
            </div>
            <div class="full">
                <button type="submit">Fis Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Manuel Cari Hareketi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_manual_cari_movement">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Hareket Tipi</label>
                <select name="movement_type">
                    <option value="borc">Borc</option>
                    <option value="alacak">Alacak</option>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input name="amount" type="number" step="0.01" min="0.01" required>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <input name="description" placeholder="Acilis bakiyesi, duzeltme, virman aciklamasi">
            </div>
            <div class="full">
                <button type="submit">Cari Hareketi Ekle</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kasa Bakiyeleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kasa</th><th>PB</th><th>Acilis</th><th>Guncel</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($cashboxBalances as $cashbox): ?>
                    <tr>
                        <td><?= app_h($cashbox['name']) ?></td>
                        <td><?= app_h($cashbox['currency_code']) ?></td>
                        <td><?= number_format((float) $cashbox['opening_balance'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cashbox['current_balance'], 2, ',', '.') ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu kasa ve hareketleri silinsin mi?');">
                                <input type="hidden" name="action" value="delete_cashbox">
                                <input type="hidden" name="cashbox_id" value="<?= (int) $cashbox['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Banka Bakiyeleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Banka</th><th>Hesap</th><th>Acilis</th><th>Guncel</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($bankBalances as $account): ?>
                    <tr>
                        <td><?= app_h($account['bank_name']) ?></td>
                        <td><?= app_h($account['account_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $account['opening_balance'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $account['current_balance'], 2, ',', '.') ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu banka hesabi ve hareketleri silinsin mi?');">
                                <input type="hidden" name="action" value="delete_bank_account">
                                <input type="hidden" name="bank_account_id" value="<?= (int) $account['id'] ?>">
                                <button type="submit">Sil</button>
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
        <h3>Cari Bakiye Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Cari</th>
                    <th>Tip</th>
                    <th>Borc</th>
                    <th>Alacak</th>
                    <th>Bakiye</th>
                    <th>Risk</th>
                    <th>Evrak</th>
                    <th>Islem</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cariBalances as $cari): ?>
                    <tr>
                        <td><?= app_h(cari_label($cari)) ?></td>
                        <td><?= app_h($cari['card_type']) ?></td>
                        <td><?= number_format((float) $cari['borc_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cari['alacak_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cari['bakiye'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $cari['risk_limit'], 2, ',', '.') ?></td>
                        <td>
                            <div class="stack">
                                <a href="cari_detail.php?id=<?= (int) $cari['id'] ?>">Detay</a>
                                <a href="index.php?module=evrak&filter_module=cari&filter_related_table=cari_cards&filter_related_id=<?= (int) $cari['id'] ?>&prefill_module=cari&prefill_related_table=cari_cards&prefill_related_id=<?= (int) $cari['id'] ?>">
                                    Evrak (<?= (int) ($cariDocCounts[(int) $cari['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('cari', 'cari_cards', (int) $cari['id'], 'index.php?module=cari')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('Bu cari ve bagli hareketler silinsin mi?');">
                                <input type="hidden" name="action" value="delete_cari">
                                <input type="hidden" name="cari_id" value="<?= (int) $cari['id'] ?>">
                                <button type="submit">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($cariBalancesPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $cariBalancesPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'cari', 'search' => $filters['search'], 'card_type' => $filters['card_type'], 'balance_sort' => $filters['balance_sort'], 'ledger_sort' => $filters['ledger_sort'], 'balance_page' => $page, 'ledger_page' => $cariLedgerPagination['page']])) ?>"><?= $page === $cariBalancesPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Cari Ekstresi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Cari</th><th>Tur</th><th>Kaynak</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($cariLedger as $item): ?>
                    <tr>
                        <td><?= app_h($item['movement_date']) ?></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['movement_type']) ?></td>
                        <td><?= app_h($item['source_module'] . ' / ' . $item['source_table']) ?></td>
                        <td><?= number_format((float) $item['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($item['description'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($cariLedgerPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $cariLedgerPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'cari', 'search' => $filters['search'], 'card_type' => $filters['card_type'], 'balance_sort' => $filters['balance_sort'], 'ledger_sort' => $filters['ledger_sort'], 'balance_page' => $cariBalancesPagination['page'], 'ledger_page' => $page])) ?>"><?= $page === $cariLedgerPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Son Kasa Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kasa</th><th>Cari</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($cashMovements as $item): ?>
                    <tr>
                        <td><?= app_h($item['cashbox_name']) ?></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['movement_type']) ?></td>
                        <td><?= number_format((float) $item['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($item['description'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Son Banka Hareketleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Banka</th><th>Cari</th><th>Tur</th><th>Tutar</th><th>Aciklama</th></tr></thead>
                <tbody>
                <?php foreach ($bankMovements as $item): ?>
                    <tr>
                        <td><?= app_h($item['bank_name'] . ' / ' . ($item['account_name'] ?: 'Hesap')) ?></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['movement_type']) ?></td>
                        <td><?= number_format((float) $item['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($item['description'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
