<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Tahsilat ve POS modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function collections_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function collections_next_link_code(PDO $db): string
{
    $count = app_table_count($db, 'collections_links') + 1;
    return 'LNK-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
}

function collections_redirect(string $result): void
{
    app_redirect('index.php?module=tahsilat&ok=' . urlencode($result));
}

function collections_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'link_status' => trim((string) ($_GET['link_status'] ?? '')),
        'transaction_status' => trim((string) ($_GET['transaction_status'] ?? '')),
        'link_sort' => trim((string) ($_GET['link_sort'] ?? 'id_desc')),
        'transaction_sort' => trim((string) ($_GET['transaction_sort'] ?? 'id_desc')),
        'link_page' => max(1, (int) ($_GET['link_page'] ?? 1)),
        'transaction_page' => max(1, (int) ($_GET['transaction_page'] ?? 1)),
        'webhook_search' => trim((string) ($_GET['webhook_search'] ?? '')),
        'webhook_status' => trim((string) ($_GET['webhook_status'] ?? '')),
        'webhook_http_status' => trim((string) ($_GET['webhook_http_status'] ?? '')),
    ];
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_pos_account') {
            $providerName = trim((string) ($_POST['provider_name'] ?? ''));

            if ($providerName === '') {
                throw new RuntimeException('Saglayici adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO collections_pos_accounts (
                    branch_id, provider_name, provider_code, merchant_code, api_mode, public_key, secret_key,
                    api_url, api_method, api_headers, api_body, status_url, status_method, status_headers, status_body,
                    three_d_enabled, three_d_init_url, three_d_method, three_d_headers, three_d_body,
                    three_d_success_status, three_d_fail_status, success_url, fail_url, callback_secret, commission_rate, status
                ) VALUES (
                    :branch_id, :provider_name, :provider_code, :merchant_code, :api_mode, :public_key, :secret_key,
                    :api_url, :api_method, :api_headers, :api_body, :status_url, :status_method, :status_headers, :status_body,
                    :three_d_enabled, :three_d_init_url, :three_d_method, :three_d_headers, :three_d_body,
                    :three_d_success_status, :three_d_fail_status, :success_url, :fail_url, :callback_secret, :commission_rate, :status
                )
            ');
            $stmt->execute([
                'branch_id' => app_default_branch_id($db),
                'provider_name' => $providerName,
                'provider_code' => trim((string) ($_POST['provider_code'] ?? '')) ?: null,
                'merchant_code' => trim((string) ($_POST['merchant_code'] ?? '')) ?: null,
                'api_mode' => trim((string) ($_POST['api_mode'] ?? 'mock')) ?: 'mock',
                'public_key' => trim((string) ($_POST['public_key'] ?? '')) ?: null,
                'secret_key' => trim((string) ($_POST['secret_key'] ?? '')) ?: null,
                'api_url' => trim((string) ($_POST['api_url'] ?? '')) ?: null,
                'api_method' => trim((string) ($_POST['api_method'] ?? 'POST')) ?: 'POST',
                'api_headers' => trim((string) ($_POST['api_headers'] ?? '')) ?: null,
                'api_body' => trim((string) ($_POST['api_body'] ?? '')) ?: null,
                'status_url' => trim((string) ($_POST['status_url'] ?? '')) ?: null,
                'status_method' => trim((string) ($_POST['status_method'] ?? 'POST')) ?: 'POST',
                'status_headers' => trim((string) ($_POST['status_headers'] ?? '')) ?: null,
                'status_body' => trim((string) ($_POST['status_body'] ?? '')) ?: null,
                'three_d_enabled' => isset($_POST['three_d_enabled']) ? 1 : 0,
                'three_d_init_url' => trim((string) ($_POST['three_d_init_url'] ?? '')) ?: null,
                'three_d_method' => trim((string) ($_POST['three_d_method'] ?? 'POST')) ?: 'POST',
                'three_d_headers' => trim((string) ($_POST['three_d_headers'] ?? '')) ?: null,
                'three_d_body' => trim((string) ($_POST['three_d_body'] ?? '')) ?: null,
                'three_d_success_status' => trim((string) ($_POST['three_d_success_status'] ?? 'basarili')) ?: 'basarili',
                'three_d_fail_status' => trim((string) ($_POST['three_d_fail_status'] ?? 'hatali')) ?: 'hatali',
                'success_url' => trim((string) ($_POST['success_url'] ?? '')) ?: null,
                'fail_url' => trim((string) ($_POST['fail_url'] ?? '')) ?: null,
                'callback_secret' => trim((string) ($_POST['callback_secret'] ?? '')) ?: null,
                'commission_rate' => (float) ($_POST['commission_rate'] ?? 0),
                'status' => isset($_POST['status']) ? 1 : 0,
            ]);

            app_audit_log('tahsilat', 'create_pos_account', 'collections_pos_accounts', (int) $db->lastInsertId(), $providerName . ' POS hesabi olusturuldu.');
            collections_redirect('pos_account');
        }

        if ($action === 'create_payment_link') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);

            if ($cariId <= 0 || $amount <= 0) {
                throw new RuntimeException('Cari ve tutar zorunludur.');
            }

            $linkCode = collections_next_link_code($db);
            $stmt = $db->prepare('
                INSERT INTO collections_links (cari_id, pos_account_id, link_code, amount, installment_count, status, expires_at)
                VALUES (:cari_id, :pos_account_id, :link_code, :amount, :installment_count, :status, :expires_at)
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'pos_account_id' => (int) ($_POST['pos_account_id'] ?? 0) ?: null,
                'link_code' => $linkCode,
                'amount' => $amount,
                'installment_count' => (int) ($_POST['installment_count'] ?? 1) ?: 1,
                'status' => trim((string) ($_POST['status'] ?? 'taslak')) ?: 'taslak',
                'expires_at' => trim((string) ($_POST['expires_at'] ?? '')) ?: null,
            ]);

            app_audit_log('tahsilat', 'create_payment_link', 'collections_links', (int) $db->lastInsertId(), $linkCode . ' odeme linki olusturuldu.');
            collections_redirect('payment_link');
        }

        if ($action === 'create_transaction') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $amount = (float) ($_POST['amount'] ?? 0);

            if ($cariId <= 0 || $amount <= 0) {
                throw new RuntimeException('Cari ve tutar zorunludur.');
            }

            $status = trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor';
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
            $installmentCount = max(1, (int) ($_POST['installment_count'] ?? 1));
            $commissionRate = max(0, (float) ($_POST['commission_rate'] ?? 0));
            $commissionAmount = round($amount * $commissionRate / 100, 2);
            $netAmount = max(0, round($amount - $commissionAmount, 2));
            if ($status === 'basarili' && $bankAccountId <= 0) {
                throw new RuntimeException('Basarili tahsilatta banka hesabi zorunludur.');
            }
            if ($status === 'basarili') {
                $transactionId = app_register_collection_payment($db, [
                    'link_id' => (int) ($_POST['link_id'] ?? 0) ?: null,
                    'cari_id' => $cariId,
                    'pos_account_id' => (int) ($_POST['pos_account_id'] ?? 0) ?: null,
                    'bank_account_id' => $bankAccountId,
                    'amount' => $amount,
                    'installment_count' => $installmentCount,
                    'commission_rate' => $commissionRate,
                    'transaction_ref' => trim((string) ($_POST['transaction_ref'] ?? '')) ?: null,
                    'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                    'currency_code' => 'TRY',
                    'created_by' => 1,
                ]);
            } else {
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
                    'link_id' => (int) ($_POST['link_id'] ?? 0) ?: null,
                    'cari_id' => $cariId,
                    'pos_account_id' => (int) ($_POST['pos_account_id'] ?? 0) ?: null,
                    'amount' => $amount,
                    'installment_count' => $installmentCount,
                    'commission_rate' => $commissionRate,
                    'commission_amount' => $commissionAmount,
                    'net_amount' => $netAmount,
                    'status' => $status,
                    'transaction_ref' => trim((string) ($_POST['transaction_ref'] ?? '')) ?: null,
                    'processed_at' => null,
                ]);
                $transactionId = (int) $db->lastInsertId();
            }

            app_audit_log('tahsilat', 'create_transaction', 'collections_transactions', $transactionId, 'POS islemi kaydedildi.');
            collections_redirect('transaction');
        }

        if ($action === 'update_transaction_status') {
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor';

            if ($transactionId <= 0) {
                throw new RuntimeException('Gecerli islem secilmedi.');
            }

            $rows = app_fetch_all($db, '
                SELECT id, link_id, cari_id, amount, status, transaction_ref
                FROM collections_transactions
                WHERE id = :id
                LIMIT 1
            ', ['id' => $transactionId]);

            if (!$rows) {
                throw new RuntimeException('POS islemi bulunamadi.');
            }

            $row = $rows[0];
            $alreadyPosted = (int) app_metric($db, "
                SELECT COUNT(*) FROM cari_movements
                WHERE source_module = 'tahsilat' AND source_table = 'collections_transactions' AND source_id = :source_id
            ", ['source_id' => $transactionId]) > 0;

            if ($alreadyPosted && $status !== 'basarili') {
                throw new RuntimeException('Muhasebelesmis tahsilat geri durumlara cekilemez.');
            }

            if (!$alreadyPosted && $status === 'basarili') {
                $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
                if ($bankAccountId <= 0) {
                    throw new RuntimeException('Basarili tahsilatta banka hesabi zorunludur.');
                }

                app_complete_collection_transaction($db, $transactionId, $bankAccountId, trim((string) ($_POST['description'] ?? '')) ?: 'POS tahsilati #' . $transactionId);
            }

            $stmt = $db->prepare('
                UPDATE collections_transactions
                SET status = :status, transaction_ref = :transaction_ref, processed_at = :processed_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => $status,
                'transaction_ref' => trim((string) ($_POST['transaction_ref'] ?? '')) ?: ($row['transaction_ref'] ?: null),
                'processed_at' => $status === 'basarili' ? date('Y-m-d H:i:s') : null,
                'id' => $transactionId,
            ]);

            app_audit_log('tahsilat', 'update_transaction_status', 'collections_transactions', $transactionId, 'POS durum guncellendi: ' . $status);
            collections_redirect('transaction_status');
        }

        if ($action === 'reconcile_transaction') {
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);

            if ($transactionId <= 0) {
                throw new RuntimeException('Mutabakat icin gecerli POS islemi secilmedi.');
            }

            $rows = app_fetch_all($db, '
                SELECT t.*, p.provider_name, p.provider_code, p.merchant_code, p.api_mode, p.public_key, p.secret_key,
                       p.status_url, p.status_method, p.status_headers, p.status_body
                FROM collections_transactions t
                LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
                WHERE t.id = :id
                LIMIT 1
            ', ['id' => $transactionId]);

            if (!$rows) {
                throw new RuntimeException('POS islemi bulunamadi.');
            }

            $row = $rows[0];
            if ((string) $row['status'] === 'basarili') {
                throw new RuntimeException('Basarili islem zaten mutabik.');
            }

            $statusResult = app_virtual_pos_check_transaction_status($db, $row, $row);
            $newStatus = (string) ($statusResult['status'] ?? 'bekliyor');
            if ($newStatus === 'basarili' && $bankAccountId <= 0) {
                throw new RuntimeException('Basarili mutabakatta banka hesabi zorunludur.');
            }

            $stmt = $db->prepare('
                UPDATE collections_transactions
                SET provider_status = :provider_status,
                    provider_response = :provider_response,
                    last_status_check_at = NOW(),
                    reconciled_at = CASE WHEN :is_reconciled = 1 THEN NOW() ELSE reconciled_at END,
                    status = CASE WHEN :failed_status = "hatali" THEN "hatali" ELSE status END,
                    processed_at = CASE WHEN :failed_processed_status = "hatali" THEN NOW() ELSE processed_at END
                WHERE id = :id
            ');
            $stmt->execute([
                'provider_status' => (string) ($statusResult['provider_status'] ?? ''),
                'provider_response' => (string) ($statusResult['response'] ?? ''),
                'is_reconciled' => in_array($newStatus, ['basarili', 'hatali'], true) ? 1 : 0,
                'failed_status' => $newStatus,
                'failed_processed_status' => $newStatus,
                'id' => $transactionId,
            ]);

            if ($newStatus === 'basarili') {
                app_complete_collection_transaction($db, $transactionId, $bankAccountId, 'POS mutabakat tahsilati #' . $transactionId);
            }

            app_audit_log('tahsilat', 'reconcile_transaction', 'collections_transactions', $transactionId, 'POS mutabakat sonucu: ' . $newStatus);
            collections_redirect('transaction_reconcile');
        }

        if ($action === 'refund_transaction') {
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
            $reason = trim((string) ($_POST['refund_reason'] ?? ''));

            if ($transactionId <= 0) {
                throw new RuntimeException('Iade icin gecerli POS islemi secilmedi.');
            }

            app_refund_collection_transaction($db, $transactionId, $bankAccountId, $reason);
            app_audit_log('tahsilat', 'refund_transaction', 'collections_transactions', $transactionId, 'POS tahsilati iade edildi.');
            collections_redirect('transaction_refund');
        }

        if ($action === 'retry_webhook_log') {
            $webhookLogId = (int) ($_POST['webhook_log_id'] ?? 0);
            $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);

            if ($webhookLogId <= 0) {
                throw new RuntimeException('Tekrar isleme icin gecerli webhook logu secilmedi.');
            }

            $rows = app_fetch_all($db, '
                SELECT w.*, t.id AS transaction_id, t.link_id, t.cari_id, t.pos_account_id, t.amount, t.installment_count,
                       t.commission_rate, t.commission_amount, t.net_amount, t.status, t.transaction_ref
                FROM collections_webhook_logs w
                LEFT JOIN collections_transactions t ON t.id = w.transaction_id
                WHERE w.id = :id
                LIMIT 1
            ', ['id' => $webhookLogId]);

            if (!$rows || empty($rows[0]['transaction_id'])) {
                throw new RuntimeException('Webhook loguna bagli POS islemi bulunamadi.');
            }

            $row = $rows[0];
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $providerStatus = trim((string) ($payload['provider_status'] ?? $payload['payment_status'] ?? $payload['status'] ?? $payload['result'] ?? $row['provider_status'] ?? ''));
            $transaction = [
                'id' => (int) $row['transaction_id'],
                'link_id' => $row['link_id'],
                'cari_id' => $row['cari_id'],
                'pos_account_id' => $row['pos_account_id'],
                'amount' => $row['amount'],
                'installment_count' => $row['installment_count'],
                'commission_rate' => $row['commission_rate'],
                'commission_amount' => $row['commission_amount'],
                'net_amount' => $row['net_amount'],
                'status' => $row['status'],
                'transaction_ref' => $row['transaction_ref'],
            ];
            $providerResponse = json_encode([
                'retry_from_webhook_log_id' => $webhookLogId,
                'original_payload' => $payload,
                'retried_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $newStatus = app_process_collection_callback($db, $transaction, $providerStatus, $providerResponse, $bankAccountId > 0 ? $bankAccountId : null, 'POS webhook tekrar isleme');

            $stmt = $db->prepare('
                UPDATE collections_webhook_logs
                SET verification_status = :verification_status,
                    http_status = :http_status,
                    processed_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                'verification_status' => in_array($newStatus, ['basarili', 'hatali'], true) ? 'dogrulandi' : 'bekliyor',
                'http_status' => 200,
                'id' => $webhookLogId,
            ]);

            app_audit_log('tahsilat', 'retry_webhook_log', 'collections_webhook_logs', $webhookLogId, 'Webhook log tekrar islendi: ' . $newStatus);
            app_redirect('collections_detail.php?type=webhook&id=' . $webhookLogId);
        }

        if ($action === 'resolve_webhook_log') {
            $webhookLogId = (int) ($_POST['webhook_log_id'] ?? 0);
            $resolutionNote = trim((string) ($_POST['resolution_note'] ?? ''));

            if ($webhookLogId <= 0) {
                throw new RuntimeException('Kapatilacak webhook logu secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE collections_webhook_logs
                SET resolved_at = NOW(), resolution_note = :resolution_note
                WHERE id = :id
            ');
            $stmt->execute([
                'resolution_note' => $resolutionNote !== '' ? $resolutionNote : 'Manuel olarak cozuldu.',
                'id' => $webhookLogId,
            ]);

            $stmt = $db->prepare("
                UPDATE notification_queue
                SET status = 'cancelled', processed_at = NOW()
                WHERE source_table = 'collections_webhook_logs'
                  AND source_id = :source_id
                  AND notification_type = 'webhook_error'
                  AND status IN ('pending', 'processing', 'failed')
            ");
            $stmt->execute(['source_id' => $webhookLogId]);

            app_audit_log('tahsilat', 'resolve_webhook_log', 'collections_webhook_logs', $webhookLogId, 'Webhook log cozuldu.');
            app_redirect('collections_detail.php?type=webhook&id=' . $webhookLogId);
        }
    } catch (Throwable $e) {
        $feedback = 'error:Tahsilat islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = collections_build_filters();
[$collectionsCariScopeWhere, $collectionsCariScopeParams] = app_branch_scope_filter($db, null, 'c');
[$collectionsPosScopeWhere, $collectionsPosScopeParams] = app_branch_scope_filter($db, null);
[$collectionsBankScopeWhere, $collectionsBankScopeParams] = app_branch_scope_filter($db, null);

$cariCards = app_fetch_all($db, 'SELECT c.id, c.company_name, c.full_name, c.email, c.phone FROM cari_cards c ' . ($collectionsCariScopeWhere !== '' ? 'WHERE ' . $collectionsCariScopeWhere : '') . ' ORDER BY c.id DESC LIMIT 100', $collectionsCariScopeParams);
$posAccounts = app_fetch_all($db, 'SELECT id, provider_name, provider_code, merchant_code, api_mode, api_url, status_url, three_d_enabled, three_d_init_url, commission_rate, status FROM collections_pos_accounts ' . ($collectionsPosScopeWhere !== '' ? 'WHERE ' . $collectionsPosScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $collectionsPosScopeParams);
$bankAccounts = app_fetch_all($db, 'SELECT id, bank_name, account_name FROM finance_bank_accounts ' . ($collectionsBankScopeWhere !== '' ? 'WHERE ' . $collectionsBankScopeWhere : '') . ' ORDER BY id DESC LIMIT 50', $collectionsBankScopeParams);
$callbackUrl = app_base_url() . '/payment_callback.php';
$linkWhere = [];
$linkParams = [];

if ($filters['search'] !== '') {
    $linkWhere[] = '(l.link_code LIKE :link_search OR c.company_name LIKE :link_search OR c.full_name LIKE :link_search OR p.provider_name LIKE :link_search)';
    $linkParams['link_search'] = '%' . $filters['search'] . '%';
}

if ($filters['link_status'] !== '') {
    $linkWhere[] = 'l.status = :link_status';
    $linkParams['link_status'] = $filters['link_status'];
}

$linkWhereSql = $linkWhere ? 'WHERE ' . implode(' AND ', $linkWhere) : '';

$links = app_fetch_all($db, '
    SELECT l.id, l.link_code, l.amount, l.installment_count, l.status, l.expires_at, c.company_name, c.full_name, p.provider_name
    FROM collections_links l
    INNER JOIN cari_cards c ON c.id = l.cari_id
    LEFT JOIN collections_pos_accounts p ON p.id = l.pos_account_id
    ' . $linkWhereSql . '
    ORDER BY l.id DESC
    LIMIT 50
', $linkParams);

$transactionWhere = [];
$transactionParams = [];

if ($filters['search'] !== '') {
    $transactionWhere[] = '(t.transaction_ref LIKE :transaction_search OR l.link_code LIKE :transaction_search OR c.company_name LIKE :transaction_search OR c.full_name LIKE :transaction_search OR p.provider_name LIKE :transaction_search)';
    $transactionParams['transaction_search'] = '%' . $filters['search'] . '%';
}

if ($filters['transaction_status'] !== '') {
    $transactionWhere[] = 't.status = :transaction_status';
    $transactionParams['transaction_status'] = $filters['transaction_status'];
}

$transactionWhereSql = $transactionWhere ? 'WHERE ' . implode(' AND ', $transactionWhere) : '';

$transactions = app_fetch_all($db, '
    SELECT t.id, t.link_id, t.amount, t.installment_count, t.commission_rate, t.commission_amount, t.net_amount, t.status, t.transaction_ref,
           t.three_d_status, t.three_d_redirect_url, t.provider_status, t.last_status_check_at, t.reconciled_at, t.processed_at,
           t.refunded_amount, t.refunded_at, t.refund_reason,
           c.company_name, c.full_name, p.provider_name, l.link_code
    FROM collections_transactions t
    INNER JOIN cari_cards c ON c.id = t.cari_id
    LEFT JOIN collections_pos_accounts p ON p.id = t.pos_account_id
    LEFT JOIN collections_links l ON l.id = t.link_id
    ' . $transactionWhereSql . '
    ORDER BY t.id DESC
    LIMIT 50
', $transactionParams);
$webhookWhere = [];
$webhookParams = [];

if ($filters['webhook_search'] !== '') {
    $webhookWhere[] = '(w.transaction_ref LIKE :webhook_search OR w.provider_status LIKE :webhook_search OR p.provider_name LIKE :webhook_search OR w.remote_ip LIKE :webhook_search)';
    $webhookParams['webhook_search'] = '%' . $filters['webhook_search'] . '%';
}

if (in_array($filters['webhook_status'], ['bekliyor', 'dogrulandi', 'hatali'], true)) {
    $webhookWhere[] = 'w.verification_status = :webhook_status';
    $webhookParams['webhook_status'] = $filters['webhook_status'];
}

if ($filters['webhook_http_status'] !== '') {
    $webhookWhere[] = 'w.http_status = :webhook_http_status';
    $webhookParams['webhook_http_status'] = (int) $filters['webhook_http_status'];
}

$webhookWhereSql = $webhookWhere ? 'WHERE ' . implode(' AND ', $webhookWhere) : '';

$webhookLogs = app_fetch_all($db, '
    SELECT w.id, w.transaction_ref, w.provider_status, w.verification_status, w.http_status, w.remote_ip, w.processed_at, w.resolved_at, w.created_at,
           t.status AS transaction_status, p.provider_name,
           q.id AS notification_id, q.status AS notification_status, q.processed_at AS notification_processed_at
    FROM collections_webhook_logs w
    LEFT JOIN collections_transactions t ON t.id = w.transaction_id
    LEFT JOIN collections_pos_accounts p ON p.id = w.pos_account_id
    LEFT JOIN notification_queue q ON q.source_table = "collections_webhook_logs" AND q.source_id = w.id AND q.notification_type = "webhook_error"
    ' . $webhookWhereSql . '
    ORDER BY w.id DESC
    LIMIT 20
', $webhookParams);
$linkDocCounts = app_related_doc_counts($db, 'tahsilat', 'collections_links', array_column($links, 'id'));
$transactionDocCounts = app_related_doc_counts($db, 'tahsilat', 'collections_transactions', array_column($transactions, 'id'));

$links = app_sort_rows($links, $filters['link_sort'], [
    'id_desc' => ['id', 'desc'],
    'code_asc' => ['link_code', 'asc'],
    'amount_desc' => ['amount', 'desc'],
    'amount_asc' => ['amount', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$transactions = app_sort_rows($transactions, $filters['transaction_sort'], [
    'id_desc' => ['id', 'desc'],
    'amount_desc' => ['amount', 'desc'],
    'amount_asc' => ['amount', 'asc'],
    'status_asc' => ['status', 'asc'],
    'ref_asc' => ['transaction_ref', 'asc'],
]);
$linksPagination = app_paginate_rows($links, $filters['link_page'], 10);
$transactionsPagination = app_paginate_rows($transactions, $filters['transaction_page'], 10);
$links = $linksPagination['items'];
$transactions = $transactionsPagination['items'];

$summary = [
    'POS Hesabi' => app_table_count($db, 'collections_pos_accounts'),
    'Aktif Link' => app_metric($db, "SELECT COUNT(*) FROM collections_links WHERE status IN ('taslak','gonderildi')"),
    'Basarili Islem' => app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'basarili'"),
    'Tahsilat Toplami' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM collections_transactions WHERE status = 'basarili'"), 2, ',', '.'),
    'Iade Toplami' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(refunded_amount),0) FROM collections_transactions WHERE status = 'iade'"), 2, ',', '.'),
    'POS Komisyonu' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE source_module = 'tahsilat' AND source_table = 'collections_transactions'"), 2, ',', '.'),
    'Bekleyen Islem' => app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'bekliyor'"),
    'Mutabakat Bekleyen' => app_metric($db, "SELECT COUNT(*) FROM collections_transactions WHERE status = 'bekliyor' AND reconciled_at IS NULL"),
    'Webhook Hatasi' => app_metric($db, "SELECT COUNT(*) FROM collections_webhook_logs WHERE verification_status = 'hatali'"),
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
    <h3>Tahsilat Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="tahsilat">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Link kodu, cari, ref no, POS">
        </div>
        <div>
            <label>Link Durumu</label>
            <select name="link_status">
                <option value="">Tum durumlar</option>
                <option value="taslak" <?= $filters['link_status'] === 'taslak' ? 'selected' : '' ?>>Taslak</option>
                <option value="gonderildi" <?= $filters['link_status'] === 'gonderildi' ? 'selected' : '' ?>>Gonderildi</option>
                <option value="odendi" <?= $filters['link_status'] === 'odendi' ? 'selected' : '' ?>>Odendi</option>
                <option value="iptal" <?= $filters['link_status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
            </select>
        </div>
        <div>
            <label>Islem Durumu</label>
            <select name="transaction_status">
                <option value="">Tum durumlar</option>
                <option value="bekliyor" <?= $filters['transaction_status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                <option value="basarili" <?= $filters['transaction_status'] === 'basarili' ? 'selected' : '' ?>>Basarili</option>
                <option value="hatali" <?= $filters['transaction_status'] === 'hatali' ? 'selected' : '' ?>>Hatali</option>
                <option value="iade" <?= $filters['transaction_status'] === 'iade' ? 'selected' : '' ?>>Iade</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=tahsilat" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="tahsilat">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="link_status" value="<?= app_h($filters['link_status']) ?>">
        <input type="hidden" name="transaction_status" value="<?= app_h($filters['transaction_status']) ?>">
        <div>
            <label>Link Siralama</label>
            <select name="link_sort">
                <option value="id_desc" <?= $filters['link_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="code_asc" <?= $filters['link_sort'] === 'code_asc' ? 'selected' : '' ?>>Kod A-Z</option>
                <option value="amount_desc" <?= $filters['link_sort'] === 'amount_desc' ? 'selected' : '' ?>>Tutar yuksek</option>
                <option value="amount_asc" <?= $filters['link_sort'] === 'amount_asc' ? 'selected' : '' ?>>Tutar dusuk</option>
                <option value="status_asc" <?= $filters['link_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>Islem Siralama</label>
            <select name="transaction_sort">
                <option value="id_desc" <?= $filters['transaction_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="amount_desc" <?= $filters['transaction_sort'] === 'amount_desc' ? 'selected' : '' ?>>Tutar yuksek</option>
                <option value="amount_asc" <?= $filters['transaction_sort'] === 'amount_asc' ? 'selected' : '' ?>>Tutar dusuk</option>
                <option value="status_asc" <?= $filters['transaction_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
                <option value="ref_asc" <?= $filters['transaction_sort'] === 'ref_asc' ? 'selected' : '' ?>>Ref A-Z</option>
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
        <h3>POS Hesabi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_pos_account">
            <div>
                <label>Saglayici</label>
                <input name="provider_name" placeholder="Iyzico / PayTR / Sanal POS" required>
            </div>
            <div>
                <label>Merchant Kodu</label>
                <input name="merchant_code" placeholder="MRC-001">
            </div>
            <div>
                <label>Provider Kodu</label>
                <input name="provider_code" placeholder="iyzico / paytr / nestpay">
            </div>
            <div>
                <label>POS Modu</label>
                <select name="api_mode">
                    <option value="mock">Mock</option>
                    <option value="manual">Manual</option>
                    <option value="http_api">HTTP API</option>
                </select>
            </div>
            <div>
                <label>Komisyon %</label>
                <input type="number" step="0.01" name="commission_rate" value="0">
            </div>
            <div>
                <label>Public Key</label>
                <input name="public_key" placeholder="API / public key">
            </div>
            <div>
                <label>Secret Key</label>
                <input name="secret_key" placeholder="Secret key">
            </div>
            <div class="full">
                <label>API URL</label>
                <input name="api_url" placeholder="https://provider.example.com/payments/init">
            </div>
            <div>
                <label>API Metodu</label>
                <select name="api_method">
                    <option value="POST">POST</option>
                    <option value="PUT">PUT</option>
                </select>
            </div>
            <div class="full">
                <label>API Headerlari</label>
                <textarea name="api_headers" rows="3" placeholder="Authorization: Bearer {{secret_key}}&#10;X-Merchant: {{merchant_code}}"></textarea>
            </div>
            <div class="full">
                <label>API Govdesi</label>
                <textarea name="api_body" rows="5" placeholder='{"merchant":"{{merchant_code}}","amount":"{{amount}}","reference":"{{transaction_ref}}","success_url":"{{success_url}}","fail_url":"{{fail_url}}"}'></textarea>
            </div>
            <div class="full">
                <label>Durum Sorgu URL</label>
                <input name="status_url" placeholder="https://provider.example.com/payments/status">
            </div>
            <div>
                <label>Durum Metodu</label>
                <select name="status_method">
                    <option value="POST">POST</option>
                    <option value="GET">GET</option>
                </select>
            </div>
            <div class="full">
                <label>Durum Headerlari</label>
                <textarea name="status_headers" rows="3" placeholder="Authorization: Bearer {{secret_key}}"></textarea>
            </div>
            <div class="full">
                <label>Durum Govdesi</label>
                <textarea name="status_body" rows="4" placeholder='{"reference":"{{transaction_ref}}"}'></textarea>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="three_d_enabled" value="1"> 3D Secure aktif</label>
            </div>
            <div class="full">
                <label>3D Baslatma URL</label>
                <input name="three_d_init_url" placeholder="https://provider.example.com/3d/init">
            </div>
            <div>
                <label>3D Metodu</label>
                <select name="three_d_method">
                    <option value="POST">POST</option>
                    <option value="GET">GET</option>
                </select>
            </div>
            <div>
                <label>3D Basarili Status</label>
                <input name="three_d_success_status" value="basarili" placeholder="basarili / approved">
            </div>
            <div>
                <label>3D Hatali Status</label>
                <input name="three_d_fail_status" value="hatali" placeholder="hatali / declined">
            </div>
            <div class="full">
                <label>3D Headerlari</label>
                <textarea name="three_d_headers" rows="3" placeholder="Authorization: Bearer {{secret_key}}"></textarea>
            </div>
            <div class="full">
                <label>3D Govdesi</label>
                <textarea name="three_d_body" rows="5" placeholder='{"merchant":"{{merchant_code}}","amount":"{{amount}}","reference":"{{transaction_ref}}","success_url":"{{three_d_success_url}}","fail_url":"{{three_d_fail_url}}"}'></textarea>
            </div>
            <div>
                <label>Success URL</label>
                <input name="success_url" placeholder="https://site.com/payment-success">
            </div>
            <div>
                <label>Fail URL</label>
                <input name="fail_url" placeholder="https://site.com/payment-fail">
            </div>
            <div>
                <label>Callback Secret</label>
                <input name="callback_secret" placeholder="Webhook secret">
            </div>
            <div class="full">
                <label>Callback URL</label>
                <input value="<?= app_h($callbackUrl) ?>" readonly>
                <small>Bu adresi POS saglayici panelindeki webhook/callback alanina girin.</small>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="status" value="1" checked> Aktif</label>
            </div>
            <div class="full">
                <button type="submit">POS Hesabi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Odeme Linki</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_payment_link">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(collections_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>POS Hesabi</label>
                <select name="pos_account_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($posAccounts as $pos): ?>
                        <option value="<?= (int) $pos['id'] ?>"><?= app_h($pos['provider_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input type="number" step="0.01" min="0.01" name="amount" value="0" required>
            </div>
            <div>
                <label>Taksit</label>
                <input type="number" min="1" name="installment_count" value="1">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="taslak">Taslak</option>
                    <option value="gonderildi">Gonderildi</option>
                    <option value="odendi">Odendi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Son Gecerlilik</label>
                <input type="datetime-local" name="expires_at">
            </div>
            <div class="full">
                <button type="submit">Odeme Linki Olustur</button>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <h3>Webhook / Callback Loglari</h3>
    <form method="get" class="form-grid compact-form" style="margin-bottom:14px;">
        <input type="hidden" name="module" value="tahsilat">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="link_status" value="<?= app_h($filters['link_status']) ?>">
        <input type="hidden" name="transaction_status" value="<?= app_h($filters['transaction_status']) ?>">
        <input type="hidden" name="link_sort" value="<?= app_h($filters['link_sort']) ?>">
        <input type="hidden" name="transaction_sort" value="<?= app_h($filters['transaction_sort']) ?>">
        <div>
            <label>Webhook Arama</label>
            <input name="webhook_search" value="<?= app_h($filters['webhook_search']) ?>" placeholder="Ref, POS, status, IP">
        </div>
        <div>
            <label>Dogrulama</label>
            <select name="webhook_status">
                <option value="">Tum durumlar</option>
                <option value="bekliyor" <?= $filters['webhook_status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                <option value="dogrulandi" <?= $filters['webhook_status'] === 'dogrulandi' ? 'selected' : '' ?>>Dogrulandi</option>
                <option value="hatali" <?= $filters['webhook_status'] === 'hatali' ? 'selected' : '' ?>>Hatali</option>
            </select>
        </div>
        <div>
            <label>HTTP Kodu</label>
            <input name="webhook_http_status" value="<?= app_h($filters['webhook_http_status']) ?>" placeholder="200 / 400 / 403">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Webhook Filtrele</button>
        </div>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Ref</th><th>POS</th><th>Saglayici Durumu</th><th>Dogrulama</th><th>HTTP</th><th>Alarm</th><th>Cozum</th><th>IP</th><th>Islem Durumu</th><th>Tarih</th><th>Detay</th></tr></thead>
            <tbody>
            <?php foreach ($webhookLogs as $log): ?>
                <tr>
                    <td><?= app_h($log['transaction_ref'] ?: '-') ?></td>
                    <td><?= app_h($log['provider_name'] ?: '-') ?></td>
                    <td><?= app_h($log['provider_status'] ?: '-') ?></td>
                    <td><?= app_h($log['verification_status']) ?></td>
                    <td><?= app_h((string) ($log['http_status'] ?: '-')) ?></td>
                    <td>
                        <?= app_h($log['notification_status'] ?: '-') ?>
                        <?php if (!empty($log['notification_id'])): ?>
                            <br><a href="index.php?module=bildirim&search=<?= urlencode((string) $log['notification_id']) ?>">Bildirim #<?= (int) $log['notification_id'] ?></a>
                        <?php endif; ?>
                    </td>
                    <td><?= app_h($log['resolved_at'] ?: '-') ?></td>
                    <td><?= app_h($log['remote_ip'] ?: '-') ?></td>
                    <td><?= app_h($log['transaction_status'] ?: '-') ?></td>
                    <td><?= app_h((string) ($log['processed_at'] ?: $log['created_at'])) ?></td>
                    <td><a href="collections_detail.php?type=webhook&id=<?= (int) $log['id'] ?>">Ac</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>POS Islemi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_transaction">
            <div>
                <label>Odeme Linki</label>
                <select name="link_id">
                    <option value="">Bagimsiz islem</option>
                    <?php foreach ($links as $link): ?>
                        <option value="<?= (int) $link['id'] ?>"><?= app_h($link['link_code'] . ' / ' . ($link['company_name'] ?: $link['full_name'] ?: '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(collections_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>POS Hesabi</label>
                <select name="pos_account_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($posAccounts as $pos): ?>
                        <option value="<?= (int) $pos['id'] ?>"><?= app_h($pos['provider_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Banka Hesabi</label>
                <select name="bank_account_id">
                    <option value="">Basarili tahsilatta secin</option>
                    <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tutar</label>
                <input type="number" step="0.01" min="0.01" name="amount" value="0" required>
            </div>
            <div>
                <label>Taksit</label>
                <input type="number" min="1" name="installment_count" value="1">
            </div>
            <div>
                <label>Komisyon %</label>
                <input type="number" step="0.01" min="0" name="commission_rate" value="0">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="basarili">Basarili</option>
                    <option value="hatali">Hatali</option>
                    <option value="iade">Iade</option>
                </select>
            </div>
            <div>
                <label>Ref No</label>
                <input name="transaction_ref" placeholder="TXN-001">
            </div>
            <div>
                <label>Aciklama</label>
                <input name="description" placeholder="POS tahsilat aciklamasi">
            </div>
            <div class="full">
                <button type="submit">POS Islemi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>POS Hesaplari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Saglayici</th><th>Kod</th><th>Mod</th><th>3D</th><th>Merchant</th><th>Komisyon</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($posAccounts as $account): ?>
                    <tr>
                        <td><?= app_h($account['provider_name']) ?></td>
                        <td><?= app_h($account['provider_code'] ?: '-') ?></td>
                        <td><?= app_h($account['api_mode'] ?: 'mock') ?></td>
                        <td><?= (int) ($account['three_d_enabled'] ?? 0) === 1 ? 'Aktif' : 'Kapali' ?></td>
                        <td><?= app_h($account['merchant_code'] ?: '-') ?></td>
                        <td><?= number_format((float) $account['commission_rate'], 2, ',', '.') ?></td>
                        <td><?= (int) $account['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($linksPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $linksPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'tahsilat', 'search' => $filters['search'], 'link_status' => $filters['link_status'], 'transaction_status' => $filters['transaction_status'], 'link_sort' => $filters['link_sort'], 'transaction_sort' => $filters['transaction_sort'], 'link_page' => $page, 'transaction_page' => $transactionsPagination['page']])) ?>"><?= $page === $linksPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Odeme Linkleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kod</th><th>Cari</th><th>POS</th><th>Tutar</th><th>Taksit</th><th>Durum</th><th>Evrak</th></tr></thead>
                <tbody>
                <?php foreach ($links as $link): ?>
                    <tr>
                        <td>
                            <?= app_h($link['link_code']) ?><br>
                            <a href="payment.php?code=<?= urlencode((string) $link['link_code']) ?>" target="_blank" rel="noopener" style="color:#7c2d12;font-weight:700;text-decoration:none;">Musteri Ekrani</a>
                        </td>
                        <td><?= app_h($link['company_name'] ?: $link['full_name'] ?: '-') ?></td>
                        <td><?= app_h($link['provider_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $link['amount'], 2, ',', '.') ?></td>
                        <td><?= (int) $link['installment_count'] ?></td>
                        <td><?= app_h($link['status']) ?></td>
                        <td>
                            <div class="stack">
                                <a href="collections_detail.php?type=link&id=<?= (int) $link['id'] ?>">Detay</a>
                                <a href="index.php?module=evrak&filter_module=tahsilat&filter_related_table=collections_links&filter_related_id=<?= (int) $link['id'] ?>&prefill_module=tahsilat&prefill_related_table=collections_links&prefill_related_id=<?= (int) $link['id'] ?>">
                                    Evrak (<?= (int) ($linkDocCounts[(int) $link['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('tahsilat', 'collections_links', (int) $link['id'], 'index.php?module=tahsilat')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>POS Islem Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Link</th><th>Cari</th><th>Tutar</th><th>Taksit</th><th>Komisyon</th><th>Durum</th><th>3D</th><th>Mutabakat</th><th>Iade</th><th>Ref</th><th>Evrak</th><th>Guncelle</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td><?= app_h($transaction['link_code'] ?: '-') ?></td>
                        <td><?= app_h($transaction['company_name'] ?: $transaction['full_name'] ?: '-') ?></td>
                        <td><?= number_format((float) $transaction['amount'], 2, ',', '.') ?></td>
                        <td><?= (int) ($transaction['installment_count'] ?? 1) ?></td>
                        <td>
                            %<?= number_format((float) ($transaction['commission_rate'] ?? 0), 2, ',', '.') ?><br>
                            <?= number_format((float) ($transaction['commission_amount'] ?? 0), 2, ',', '.') ?> / Net <?= number_format((float) ($transaction['net_amount'] ?? $transaction['amount']), 2, ',', '.') ?>
                        </td>
                        <td><?= app_h($transaction['status']) ?></td>
                        <td><?= app_h($transaction['three_d_status'] ?: '-') ?></td>
                        <td>
                            <?= app_h($transaction['provider_status'] ?: '-') ?><br>
                            <small><?= app_h($transaction['last_status_check_at'] ?: 'Sorgulanmadi') ?></small>
                        </td>
                        <td>
                            <?= number_format((float) ($transaction['refunded_amount'] ?? 0), 2, ',', '.') ?><br>
                            <small><?= app_h($transaction['refunded_at'] ?: '-') ?></small>
                        </td>
                        <td><?= app_h($transaction['transaction_ref'] ?: '-') ?></td>
                        <td>
                            <div class="stack">
                                <a href="collections_detail.php?type=transaction&id=<?= (int) $transaction['id'] ?>">Detay</a>
                                <a href="index.php?module=evrak&filter_module=tahsilat&filter_related_table=collections_transactions&filter_related_id=<?= (int) $transaction['id'] ?>&prefill_module=tahsilat&prefill_related_table=collections_transactions&prefill_related_id=<?= (int) $transaction['id'] ?>">
                                    Evrak (<?= (int) ($transactionDocCounts[(int) $transaction['id']] ?? 0) ?>)
                                </a>
                                <a href="<?= app_h(app_doc_upload_url('tahsilat', 'collections_transactions', (int) $transaction['id'], 'index.php?module=tahsilat')) ?>">Hizli Yukle</a>
                            </div>
                        </td>
                        <td>
                            <form method="post" class="compact-form">
                                <input type="hidden" name="action" value="update_transaction_status">
                                <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id'] ?>">
                                <select name="status">
                                    <option value="bekliyor" <?= $transaction['status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                                    <option value="basarili" <?= $transaction['status'] === 'basarili' ? 'selected' : '' ?>>Basarili</option>
                                    <option value="hatali" <?= $transaction['status'] === 'hatali' ? 'selected' : '' ?>>Hatali</option>
                                    <option value="iade" <?= $transaction['status'] === 'iade' ? 'selected' : '' ?>>Iade</option>
                                </select>
                                <select name="bank_account_id">
                                    <option value="">Banka Secin</option>
                                    <?php foreach ($bankAccounts as $account): ?>
                                        <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="transaction_ref" value="<?= app_h((string) ($transaction['transaction_ref'] ?? '')) ?>" placeholder="Ref">
                                <input name="description" placeholder="Muhasebe aciklamasi">
                                <button type="submit">Guncelle</button>
                            </form>
                            <?php if ($transaction['status'] === 'bekliyor'): ?>
                                <form method="post" class="compact-form" style="margin-top:8px;">
                                    <input type="hidden" name="action" value="reconcile_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id'] ?>">
                                    <select name="bank_account_id">
                                        <option value="">Mutabakatta banka secin</option>
                                        <?php foreach ($bankAccounts as $account): ?>
                                            <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Durum Sorgula</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($transaction['status'] === 'basarili' && empty($transaction['refunded_at'])): ?>
                                <form method="post" class="compact-form" style="margin-top:8px;">
                                    <input type="hidden" name="action" value="refund_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id'] ?>">
                                    <select name="bank_account_id" required>
                                        <option value="">Iade banka hesabi</option>
                                        <?php foreach ($bankAccounts as $account): ?>
                                            <option value="<?= (int) $account['id'] ?>"><?= app_h($account['bank_name'] . ' / ' . ($account['account_name'] ?: 'Hesap')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="refund_reason" placeholder="Iade nedeni">
                                    <button type="submit">Iade Et</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($transactionsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $transactionsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'tahsilat', 'search' => $filters['search'], 'link_status' => $filters['link_status'], 'transaction_status' => $filters['transaction_status'], 'link_sort' => $filters['link_sort'], 'transaction_sort' => $filters['transaction_sort'], 'link_page' => $linksPagination['page'], 'transaction_page' => $page])) ?>"><?= $page === $transactionsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
