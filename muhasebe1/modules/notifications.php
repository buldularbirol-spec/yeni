<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Bildirim merkezi icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

app_notifications_ensure_schema($db);
$runnerToken = app_notification_runner_token($db);

function notifications_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'queue_status' => trim((string) ($_GET['queue_status'] ?? '')),
        'module_name' => trim((string) ($_GET['module_name'] ?? '')),
        'queue_sort' => trim((string) ($_GET['queue_sort'] ?? 'id_desc')),
        'queue_page' => max(1, (int) ($_GET['queue_page'] ?? 1)),
    ];
}

$feedback = $_GET['ok'] ?? '';
$action = $_POST['action'] ?? null;
$filters = notifications_build_filters();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'save_notification_settings') {
            app_set_setting($db, 'notifications.email_enabled', isset($_POST['email_enabled']) ? '1' : '0', 'bildirim');
            app_set_setting($db, 'notifications.sms_enabled', isset($_POST['sms_enabled']) ? '1' : '0', 'bildirim');
            app_set_setting($db, 'notifications.push_enabled', isset($_POST['push_enabled']) ? '1' : '0', 'bildirim');
            app_set_setting($db, 'notifications.email_mode', trim((string) ($_POST['email_mode'] ?? 'mock')) ?: 'mock', 'bildirim');
            app_set_setting($db, 'notifications.sms_mode', trim((string) ($_POST['sms_mode'] ?? 'mock')) ?: 'mock', 'bildirim');
            app_set_setting($db, 'notifications.push_mode', trim((string) ($_POST['push_mode'] ?? 'mock')) ?: 'mock', 'bildirim');
            app_set_setting($db, 'notifications.push_public_key', trim((string) ($_POST['push_public_key'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.push_private_key', trim((string) ($_POST['push_private_key'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.smtp_host', trim((string) ($_POST['smtp_host'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.smtp_port', trim((string) ($_POST['smtp_port'] ?? '587')) ?: '587', 'bildirim');
            app_set_setting($db, 'notifications.smtp_security', trim((string) ($_POST['smtp_security'] ?? 'tls')) ?: 'tls', 'bildirim');
            app_set_setting($db, 'notifications.smtp_username', trim((string) ($_POST['smtp_username'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.smtp_password', (string) ($_POST['smtp_password'] ?? ''), 'bildirim');
            app_set_setting($db, 'notifications.smtp_from_email', trim((string) ($_POST['smtp_from_email'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? 'Galancy Bildirim')) ?: 'Galancy Bildirim', 'bildirim');
            app_set_setting($db, 'notifications.smtp_timeout', trim((string) ($_POST['smtp_timeout'] ?? '15')) ?: '15', 'bildirim');
            app_set_setting($db, 'notifications.sms_api_url', trim((string) ($_POST['sms_api_url'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.sms_api_method', trim((string) ($_POST['sms_api_method'] ?? 'POST')) ?: 'POST', 'bildirim');
            app_set_setting($db, 'notifications.sms_api_content_type', trim((string) ($_POST['sms_api_content_type'] ?? 'application/json')) ?: 'application/json', 'bildirim');
            app_set_setting($db, 'notifications.sms_api_headers', trim((string) ($_POST['sms_api_headers'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.sms_api_body', trim((string) ($_POST['sms_api_body'] ?? '{"to":"{{phone}}","message":"{{message}}"}')) ?: '{"to":"{{phone}}","message":"{{message}}"}', 'bildirim');
            app_set_setting($db, 'notifications.sms_api_timeout', trim((string) ($_POST['sms_api_timeout'] ?? '15')) ?: '15', 'bildirim');
            app_set_setting($db, 'notifications.crm_email_subject', trim((string) ($_POST['crm_email_subject'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.crm_email_body', trim((string) ($_POST['crm_email_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.crm_sms_body', trim((string) ($_POST['crm_sms_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.rental_email_subject', trim((string) ($_POST['rental_email_subject'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.rental_email_body', trim((string) ($_POST['rental_email_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.rental_sms_body', trim((string) ($_POST['rental_sms_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.invoice_due_reminder_days', trim((string) ($_POST['invoice_due_reminder_days'] ?? '3')) ?: '3', 'bildirim');
            app_set_setting($db, 'notifications.invoice_email_subject', trim((string) ($_POST['invoice_email_subject'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.invoice_email_body', trim((string) ($_POST['invoice_email_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.invoice_sms_body', trim((string) ($_POST['invoice_sms_body'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.webhook_alert_email', trim((string) ($_POST['webhook_alert_email'] ?? '')), 'bildirim');
            app_set_setting($db, 'notifications.pos_report_enabled', isset($_POST['pos_report_enabled']) ? '1' : '0', 'bildirim');
            app_set_setting($db, 'notifications.pos_report_email', trim((string) ($_POST['pos_report_email'] ?? '')), 'bildirim');

            app_audit_log('bildirim', 'settings_saved', 'core_settings', null, 'Bildirim ayarlari guncellendi.');
            app_redirect('index.php?module=bildirim&ok=settings');
        }

        if ($action === 'save_notification_preference') {
            $preferenceUserId = (int) ($_POST['preference_user_id'] ?? 0);
            $moduleName = trim((string) ($_POST['preference_module_name'] ?? ''));
            $notificationType = trim((string) ($_POST['preference_notification_type'] ?? '*')) ?: '*';
            $status = trim((string) ($_POST['preference_status'] ?? 'active')) ?: 'active';
            $quietStart = trim((string) ($_POST['quiet_start'] ?? ''));
            $quietEnd = trim((string) ($_POST['quiet_end'] ?? ''));

            if ($moduleName === '') {
                throw new RuntimeException('Bildirim tercihi icin modul secimi zorunludur.');
            }

            $existing = app_fetch_all($db, '
                SELECT id
                FROM notification_preferences
                WHERE ' . ($preferenceUserId > 0 ? 'user_id = :user_id' : 'user_id IS NULL') . '
                  AND module_name = :module_name
                  AND notification_type = :notification_type
                LIMIT 1
            ', array_filter([
                'user_id' => $preferenceUserId > 0 ? $preferenceUserId : null,
                'module_name' => $moduleName,
                'notification_type' => $notificationType,
            ], static fn($value): bool => $value !== null));

            if ($existing) {
                $stmt = $db->prepare('
                    UPDATE notification_preferences
                    SET email_enabled = :email_enabled,
                        sms_enabled = :sms_enabled,
                        push_enabled = :push_enabled,
                        quiet_start = :quiet_start,
                        quiet_end = :quiet_end,
                        status = :status
                    WHERE id = :id
                ');
                $stmt->execute([
                    'email_enabled' => isset($_POST['preference_email_enabled']) ? 1 : 0,
                    'sms_enabled' => isset($_POST['preference_sms_enabled']) ? 1 : 0,
                    'push_enabled' => isset($_POST['preference_push_enabled']) ? 1 : 0,
                    'quiet_start' => $quietStart !== '' ? $quietStart : null,
                    'quiet_end' => $quietEnd !== '' ? $quietEnd : null,
                    'status' => $status === 'passive' ? 'passive' : 'active',
                    'id' => (int) $existing[0]['id'],
                ]);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO notification_preferences (
                        user_id, module_name, notification_type, email_enabled, sms_enabled, push_enabled,
                        quiet_start, quiet_end, status
                    ) VALUES (
                        :user_id, :module_name, :notification_type, :email_enabled, :sms_enabled, :push_enabled,
                        :quiet_start, :quiet_end, :status
                    )
                ');
                $stmt->execute([
                    'user_id' => $preferenceUserId > 0 ? $preferenceUserId : null,
                    'module_name' => $moduleName,
                    'notification_type' => $notificationType,
                    'email_enabled' => isset($_POST['preference_email_enabled']) ? 1 : 0,
                    'sms_enabled' => isset($_POST['preference_sms_enabled']) ? 1 : 0,
                    'push_enabled' => isset($_POST['preference_push_enabled']) ? 1 : 0,
                    'quiet_start' => $quietStart !== '' ? $quietStart : null,
                    'quiet_end' => $quietEnd !== '' ? $quietEnd : null,
                    'status' => $status === 'passive' ? 'passive' : 'active',
                ]);
            }

            app_audit_log('bildirim', 'preference_saved', 'notification_preferences', null, 'Bildirim tercihi kaydedildi: ' . ($preferenceUserId > 0 ? ('Kullanici #' . $preferenceUserId . ' / ') : 'Genel / ') . $moduleName . ' / ' . $notificationType);
            app_redirect('index.php?module=bildirim&ok=preference');
        }

        if ($action === 'send_test_email') {
            $recipient = trim((string) ($_POST['test_email'] ?? ''));
            if ($recipient === '') {
                throw new RuntimeException('Test e-posta adresi zorunludur.');
            }

            app_send_notification_email($db, [
                'recipient_contact' => $recipient,
                'recipient_name' => $authUser['full_name'] ?? 'Test Alicisi',
                'subject_line' => 'Galancy SMTP Test Mesaji',
                'message_body' => "Bu mesaj Galancy Bildirim Merkezi SMTP testi icin gonderildi.\nTarih: " . date('Y-m-d H:i:s'),
            ]);

            app_audit_log('bildirim', 'test_email', 'core_settings', null, 'SMTP test e-postasi gonderildi: ' . $recipient);
            app_redirect('index.php?module=bildirim&ok=test_email');
        }

        if ($action === 'send_test_sms') {
            $recipient = trim((string) ($_POST['test_sms'] ?? ''));
            if ($recipient === '') {
                throw new RuntimeException('Test telefon numarasi zorunludur.');
            }

            app_send_notification_sms($db, [
                'recipient_contact' => $recipient,
                'message_body' => 'Galancy Bildirim Merkezi SMS test mesaji / ' . date('Y-m-d H:i:s'),
            ]);

            app_audit_log('bildirim', 'test_sms', 'core_settings', null, 'SMS test mesaji gonderildi: ' . $recipient);
            app_redirect('index.php?module=bildirim&ok=test_sms');
        }

        if ($action === 'save_push_subscription') {
            $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
            if ($endpoint === '') {
                throw new RuntimeException('Push endpoint zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO notification_push_subscriptions (
                    user_id, endpoint, endpoint_hash, public_key, auth_token, user_agent, status, last_seen_at
                ) VALUES (
                    :user_id, :endpoint, :endpoint_hash, :public_key, :auth_token, :user_agent, :status, :last_seen_at
                )
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    public_key = VALUES(public_key),
                    auth_token = VALUES(auth_token),
                    user_agent = VALUES(user_agent),
                    status = "active",
                    last_seen_at = VALUES(last_seen_at)
            ');
            $stmt->execute([
                'user_id' => (int) ($authUser['id'] ?? 0) ?: null,
                'endpoint' => $endpoint,
                'endpoint_hash' => hash('sha256', $endpoint),
                'public_key' => trim((string) ($_POST['public_key'] ?? '')) ?: null,
                'auth_token' => trim((string) ($_POST['auth_token'] ?? '')) ?: null,
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'status' => 'active',
                'last_seen_at' => date('Y-m-d H:i:s'),
            ]);

            app_audit_log('bildirim', 'push_subscribe', 'notification_push_subscriptions', null, 'Push aboneligi kaydedildi.');
            app_redirect('index.php?module=bildirim&ok=push_subscribed');
        }

        if ($action === 'send_test_push') {
            $subscriptions = app_fetch_all($db, '
                SELECT id, endpoint, user_id
                FROM notification_push_subscriptions
                WHERE status = "active"
                ORDER BY last_seen_at DESC
                LIMIT 50
            ');
            if (!$subscriptions) {
                throw new RuntimeException('Test push icin aktif tarayici aboneligi yok.');
            }

            $queued = 0;
            foreach ($subscriptions as $subscription) {
                if (app_queue_notification($db, [
                    'module_name' => 'bildirim',
                    'notification_type' => 'test_push',
                    'source_table' => 'notification_push_subscriptions',
                    'source_id' => (int) $subscription['id'],
                    'channel' => 'push',
                    'recipient_name' => 'Tarayici #' . (int) $subscription['id'],
                    'recipient_contact' => (string) $subscription['endpoint'],
                    'subject_line' => 'Galancy Push Test',
                    'message_body' => 'Bu test bildirimi Galancy Bildirim Merkezi tarafindan hazirlandi. Tarih: ' . date('Y-m-d H:i:s'),
                    'unique_key' => 'test-push-' . (int) $subscription['id'] . '-' . date('YmdHis'),
                    'provider_name' => app_setting($db, 'notifications.push_mode', 'mock'),
                ])) {
                    $queued++;
                }
            }

            app_audit_log('bildirim', 'test_push', 'notification_queue', null, 'Push test kuyruğu: ' . $queued);
            app_redirect('index.php?module=bildirim&ok=test_push');
        }

        if ($action === 'send_bulk_contact') {
            $targetType = trim((string) ($_POST['bulk_target_type'] ?? 'users'));
            $channel = trim((string) ($_POST['bulk_channel'] ?? 'email'));
            $subject = trim((string) ($_POST['bulk_subject'] ?? ''));
            $body = trim((string) ($_POST['bulk_message'] ?? ''));
            $plannedAt = trim((string) ($_POST['bulk_planned_at'] ?? ''));
            $allowedTargets = ['users', 'customers', 'push_subscribers'];
            $allowedChannels = ['email', 'sms', 'push'];

            if (!in_array($targetType, $allowedTargets, true) || !in_array($channel, $allowedChannels, true) || $body === '') {
                throw new RuntimeException('Toplu iletisim icin hedef, kanal ve mesaj zorunludur.');
            }

            if ($channel === 'email' && $subject === '') {
                throw new RuntimeException('Toplu e-posta icin konu zorunludur.');
            }

            $plannedAtSql = $plannedAt !== '' ? str_replace('T', ' ', $plannedAt) . (strlen($plannedAt) === 16 ? ':00' : '') : date('Y-m-d H:i:s');
            $recipients = [];

            if ($targetType === 'users') {
                $contactColumn = $channel === 'sms' ? 'phone' : 'email';
                $recipients = app_fetch_all($db, "
                    SELECT id, full_name AS recipient_name, {$contactColumn} AS recipient_contact
                    FROM core_users
                    WHERE status = 1 AND {$contactColumn} IS NOT NULL AND {$contactColumn} <> ''
                    ORDER BY id ASC
                    LIMIT 500
                ");
            } elseif ($targetType === 'customers') {
                $contactColumn = $channel === 'sms' ? 'phone' : 'email';
                if ($channel === 'push') {
                    $recipients = [];
                } else {
                    $recipients = app_fetch_all($db, "
                        SELECT id, COALESCE(NULLIF(company_name, ''), NULLIF(full_name, ''), CONCAT('Cari #', id)) AS recipient_name, {$contactColumn} AS recipient_contact
                        FROM cari_cards
                        WHERE {$contactColumn} IS NOT NULL AND {$contactColumn} <> ''
                        ORDER BY id DESC
                        LIMIT 500
                    ");
                }
            } else {
                $recipients = app_fetch_all($db, '
                    SELECT id, CONCAT("Tarayici #", id) AS recipient_name, endpoint AS recipient_contact, user_id
                    FROM notification_push_subscriptions
                    WHERE status = "active"
                    ORDER BY last_seen_at DESC
                    LIMIT 500
                ');
                $channel = 'push';
            }

            $queued = 0;
            $skipped = 0;
            $batchKey = date('YmdHis') . '-' . substr(sha1($targetType . $channel . $body), 0, 8);
            foreach ($recipients as $recipient) {
                $contact = trim((string) ($recipient['recipient_contact'] ?? ''));
                if ($contact === '') {
                    $skipped++;
                    continue;
                }

                $queuedOk = app_queue_notification($db, [
                    'module_name' => 'bildirim',
                    'notification_type' => 'bulk_contact',
                    'source_table' => $targetType === 'customers' ? 'cari_cards' : ($targetType === 'push_subscribers' ? 'notification_push_subscriptions' : 'core_users'),
                    'source_id' => (int) ($recipient['id'] ?? 0),
                    'channel' => $channel,
                    'recipient_user_id' => isset($recipient['user_id']) ? (int) $recipient['user_id'] : null,
                    'recipient_name' => (string) ($recipient['recipient_name'] ?? '-'),
                    'recipient_contact' => $contact,
                    'subject_line' => $channel === 'email' ? $subject : null,
                    'message_body' => $body,
                    'status' => 'pending',
                    'planned_at' => $plannedAtSql,
                    'unique_key' => 'bulk-contact-' . $batchKey . '-' . $channel . '-' . (int) ($recipient['id'] ?? 0),
                    'provider_name' => app_setting($db, 'notifications.' . $channel . '_mode', 'mock'),
                ]);

                $queuedOk ? $queued++ : $skipped++;
            }

            app_audit_log('bildirim', 'bulk_contact', 'notification_queue', null, 'Toplu iletisim kuyrugu: ' . $queued . ', atlanan: ' . $skipped);
            app_redirect('index.php?module=bildirim&ok=bulk_contact:' . urlencode(json_encode(['queued' => $queued, 'skipped' => $skipped])));
        }

        if ($action === 'scan_notifications') {
            $result = app_generate_notification_queue($db);
            app_audit_log('bildirim', 'scan', 'notification_queue', null, 'CRM: ' . $result['crm'] . ', Kira: ' . $result['rental'] . ', Fatura: ' . $result['invoice']);
            app_redirect('index.php?module=bildirim&ok=scan:' . urlencode(json_encode($result)));
        }

        if ($action === 'process_notifications') {
            $result = app_process_notification_queue($db, 50);
            app_audit_log('bildirim', 'process', 'notification_queue', null, 'Islenen: ' . $result['processed'] . ', Basarili: ' . $result['sent']);
            app_redirect('index.php?module=bildirim&ok=process:' . urlencode(json_encode($result)));
        }

        if ($action === 'cancel_notification') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId <= 0) {
                throw new RuntimeException('Gecerli kuyruk kaydi secilmedi.');
            }

            $stmt = $db->prepare("UPDATE notification_queue SET status = 'cancelled', processed_at = :processed_at WHERE id = :id");
            $stmt->execute([
                'processed_at' => date('Y-m-d H:i:s'),
                'id' => $queueId,
            ]);

            app_audit_log('bildirim', 'cancel', 'notification_queue', $queueId, 'Bildirim iptal edildi.');
            app_redirect('index.php?module=bildirim&ok=cancel');
        }

        if ($action === 'retry_notification') {
            $queueId = (int) ($_POST['queue_id'] ?? 0);
            if ($queueId <= 0) {
                throw new RuntimeException('Tekrar deneme icin gecerli kuyruk kaydi secilmedi.');
            }

            $stmt = $db->prepare("
                UPDATE notification_queue
                SET status = 'pending',
                    planned_at = :planned_at,
                    processed_at = NULL,
                    last_error = NULL,
                    unique_key = CONCAT(COALESCE(unique_key, CONCAT('notification-', id)), '-retry-', :retry_token)
                WHERE id = :id
                  AND status IN ('failed','cancelled')
            ");
            $stmt->execute([
                'planned_at' => date('Y-m-d H:i:s'),
                'retry_token' => date('YmdHis'),
                'id' => $queueId,
            ]);

            app_audit_log('bildirim', 'retry', 'notification_queue', $queueId, 'Bildirim tekrar deneme icin kuyruga alindi.');
            app_redirect('index.php?module=bildirim&ok=retry');
        }

        if ($action === 'bulk_cancel_notifications') {
            $queueIds = $_POST['queue_ids'] ?? [];
            if (!is_array($queueIds)) {
                $queueIds = [];
            }

            $queueIds = array_values(array_filter(array_map('intval', $queueIds), static fn(int $id): bool => $id > 0));
            if ($queueIds === []) {
                throw new RuntimeException('Toplu iptal icin kuyruk kaydi secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
            $params = array_merge([date('Y-m-d H:i:s')], $queueIds);
            $stmt = $db->prepare("UPDATE notification_queue SET status = 'cancelled', processed_at = ? WHERE id IN ({$placeholders}) AND status = 'pending'");
            $stmt->execute($params);

            app_audit_log('bildirim', 'bulk_cancel', 'notification_queue', null, 'Toplu bildirim iptali yapildi.');
            app_redirect('index.php?module=bildirim&ok=cancel');
        }

        if ($action === 'bulk_retry_notifications') {
            $queueIds = $_POST['queue_ids'] ?? [];
            if (!is_array($queueIds)) {
                $queueIds = [];
            }

            $queueIds = array_values(array_filter(array_map('intval', $queueIds), static fn(int $id): bool => $id > 0));
            if ($queueIds === []) {
                throw new RuntimeException('Toplu tekrar deneme icin kuyruk kaydi secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
            $retryToken = date('YmdHis');
            $params = array_merge([date('Y-m-d H:i:s'), $retryToken], $queueIds);
            $stmt = $db->prepare("
                UPDATE notification_queue
                SET status = 'pending',
                    planned_at = ?,
                    processed_at = NULL,
                    last_error = NULL,
                    unique_key = CONCAT(COALESCE(unique_key, CONCAT('notification-', id)), '-retry-', ?)
                WHERE id IN ({$placeholders})
                  AND status IN ('failed','cancelled')
            ");
            $stmt->execute($params);

            app_audit_log('bildirim', 'bulk_retry', 'notification_queue', null, 'Toplu bildirim tekrar deneme yapildi.');
            app_redirect('index.php?module=bildirim&ok=retry');
        }

        if ($action === 'bulk_export_notifications_csv') {
            $queueIds = $_POST['queue_ids'] ?? [];
            if (!is_array($queueIds)) {
                $queueIds = [];
            }

            $queueIds = array_values(array_filter(array_map('intval', $queueIds), static fn(int $id): bool => $id > 0));
            if ($queueIds === []) {
                throw new RuntimeException('CSV icin kuyruk kaydi secilmedi.');
            }

            $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
            $rows = app_fetch_all($db, "
                SELECT module_name, notification_type, channel, recipient_name, recipient_contact, subject_line, status, planned_at, processed_at
                FROM notification_queue
                WHERE id IN ({$placeholders})
                ORDER BY id DESC
            ", $queueIds);

            $exportRows = [];
            foreach ($rows as $row) {
                $exportRows[] = [
                    $row['module_name'],
                    $row['notification_type'],
                    $row['channel'],
                    $row['recipient_name'] ?: '-',
                    $row['recipient_contact'],
                    $row['subject_line'] ?: '-',
                    $row['status'],
                    $row['planned_at'],
                    $row['processed_at'] ?: '-',
                ];
            }

            app_csv_download('secili-bildirimler.csv', ['Modul', 'Tip', 'Kanal', 'Alici', 'Iletisim', 'Konu', 'Durum', 'Plan', 'Islenen'], $exportRows);
        }
    } catch (Throwable $e) {
        $feedback = 'error:Bildirim islemi tamamlanamadi. Lutfen ayarlari kontrol edip tekrar deneyin.';
    }
}

$today = date('Y-m-d');
$userOptions = app_fetch_all($db, 'SELECT id, full_name, email FROM core_users WHERE status = 1 ORDER BY full_name ASC LIMIT 200');
$emailEnabled = app_notification_setting_bool($db, 'notifications.email_enabled', true);
$smsEnabled = app_notification_setting_bool($db, 'notifications.sms_enabled', false);
$pushEnabled = app_notification_setting_bool($db, 'notifications.push_enabled', false);
$emailMode = app_setting($db, 'notifications.email_mode', 'mock');
$smsMode = app_setting($db, 'notifications.sms_mode', 'mock');
$pushMode = app_setting($db, 'notifications.push_mode', 'mock');
$pushPublicKey = app_setting($db, 'notifications.push_public_key', '');
$pushPrivateKey = app_setting($db, 'notifications.push_private_key', '');
$smtpHost = app_setting($db, 'notifications.smtp_host', '');
$smtpPort = app_setting($db, 'notifications.smtp_port', '587');
$smtpSecurity = app_setting($db, 'notifications.smtp_security', 'tls');
$smtpUsername = app_setting($db, 'notifications.smtp_username', '');
$smtpPassword = app_setting($db, 'notifications.smtp_password', '');
$smtpFromEmail = app_setting($db, 'notifications.smtp_from_email', '');
$smtpFromName = app_setting($db, 'notifications.smtp_from_name', 'Galancy Bildirim');
$smtpTimeout = app_setting($db, 'notifications.smtp_timeout', '15');
$smsApiUrl = app_setting($db, 'notifications.sms_api_url', '');
$smsApiMethod = app_setting($db, 'notifications.sms_api_method', 'POST');
$smsApiContentType = app_setting($db, 'notifications.sms_api_content_type', 'application/json');
$smsApiHeaders = app_setting($db, 'notifications.sms_api_headers', '');
$smsApiBody = app_setting($db, 'notifications.sms_api_body', '{"to":"{{phone}}","message":"{{message}}"}');
$smsApiTimeout = app_setting($db, 'notifications.sms_api_timeout', '15');
$webhookAlertEmail = app_setting($db, 'notifications.webhook_alert_email', '');
$posReportEnabled = app_notification_setting_bool($db, 'notifications.pos_report_enabled', false);
$posReportEmail = app_setting($db, 'notifications.pos_report_email', '');
$lastScanAt = app_setting($db, 'notifications.last_scan_at', '-');
$lastProcessAt = app_setting($db, 'notifications.last_process_at', '-');
$invoiceReminderDays = app_setting($db, 'notifications.invoice_due_reminder_days', '3');
$invoiceDueUntil = date('Y-m-d', strtotime('+' . max(0, (int) $invoiceReminderDays) . ' days'));

$summary = [
    'Bugunku Hatirlatma' => app_metric($db, "SELECT COUNT(*) FROM crm_reminders WHERE DATE(remind_at) = :today", ['today' => $today]),
    'Gecikmis Hatirlatma' => app_metric($db, "SELECT COUNT(*) FROM crm_reminders WHERE DATE(remind_at) < :today AND status = 'bekliyor'", ['today' => $today]),
    'Gecikmis Kira' => app_metric($db, "SELECT COUNT(*) FROM rental_payments WHERE status = 'gecikmis' OR (status = 'bekliyor' AND due_date < :today)", ['today' => $today]),
    'Yaklasan Fatura' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE invoice_type = 'satis' AND payment_status <> 'odendi' AND due_date BETWEEN :today AND :invoice_due_until", ['today' => $today, 'invoice_due_until' => $invoiceDueUntil]),
    'Gecikmis Fatura' => app_metric($db, "SELECT COUNT(*) FROM invoice_headers WHERE invoice_type = 'satis' AND payment_status <> 'odendi' AND due_date < :today", ['today' => $today]),
    'Bekleyen Kuyruk' => app_metric($db, "SELECT COUNT(*) FROM notification_queue WHERE status = 'pending'"),
    'Gonderilen Kuyruk' => app_metric($db, "SELECT COUNT(*) FROM notification_queue WHERE status = 'sent'"),
    'Hatali Kuyruk' => app_metric($db, "SELECT COUNT(*) FROM notification_queue WHERE status = 'failed'"),
    'Push Abone' => app_metric($db, "SELECT COUNT(*) FROM notification_push_subscriptions WHERE status = 'active'"),
    'Tercih Kaydi' => app_table_count($db, 'notification_preferences'),
];

$notificationPreferences = app_fetch_all($db, '
    SELECT p.id, p.user_id, p.module_name, p.notification_type, p.email_enabled, p.sms_enabled, p.push_enabled,
           p.quiet_start, p.quiet_end, p.status, p.updated_at, p.created_at,
           u.full_name, u.email
    FROM notification_preferences p
    LEFT JOIN core_users u ON u.id = p.user_id
    ORDER BY module_name ASC, notification_type ASC
    LIMIT 80
');

$successReport = app_fetch_all($db, '
    SELECT
        COUNT(*) AS total_count,
        SUM(status = "sent") AS sent_count,
        SUM(status = "failed") AS failed_count,
        SUM(status = "pending") AS pending_count,
        SUM(status = "cancelled") AS cancelled_count
    FROM notification_queue
');
$successTotals = $successReport[0] ?? [
    'total_count' => 0,
    'sent_count' => 0,
    'failed_count' => 0,
    'pending_count' => 0,
    'cancelled_count' => 0,
];
$successTotal = max(0, (int) ($successTotals['total_count'] ?? 0));
$successSent = max(0, (int) ($successTotals['sent_count'] ?? 0));
$successFailed = max(0, (int) ($successTotals['failed_count'] ?? 0));
$successRate = $successTotal > 0 ? round(($successSent / $successTotal) * 100, 1) : 0;
$failureRate = $successTotal > 0 ? round(($successFailed / $successTotal) * 100, 1) : 0;
$channelSuccessRows = app_fetch_all($db, '
    SELECT channel, COUNT(*) AS total_count, SUM(status = "sent") AS sent_count, SUM(status = "failed") AS failed_count
    FROM notification_queue
    GROUP BY channel
    ORDER BY total_count DESC, channel ASC
');
$moduleSuccessRows = app_fetch_all($db, '
    SELECT module_name, COUNT(*) AS total_count, SUM(status = "sent") AS sent_count, SUM(status = "failed") AS failed_count
    FROM notification_queue
    GROUP BY module_name
    ORDER BY total_count DESC, module_name ASC
    LIMIT 10
');
$recentFailureRows = app_fetch_all($db, '
    SELECT id, module_name, notification_type, channel, recipient_name, recipient_contact, last_error, processed_at
    FROM notification_queue
    WHERE status = "failed"
    ORDER BY processed_at DESC, id DESC
    LIMIT 8
');
$deliverySummaryRows = app_fetch_all($db, '
    SELECT
        channel,
        COUNT(*) AS total_count,
        SUM(status = "sent") AS delivered_count,
        SUM(status = "failed") AS failed_count,
        SUM(status = "pending") AS pending_count,
        ROUND(AVG(CASE WHEN processed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, planned_at, processed_at) END), 1) AS avg_delivery_seconds
    FROM notification_queue
    GROUP BY channel
    ORDER BY total_count DESC, channel ASC
');
$deliveryRows = app_fetch_all($db, '
    SELECT id, module_name, notification_type, channel, recipient_name, recipient_contact,
           subject_line, status, provider_name, planned_at, processed_at,
           CASE WHEN processed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, planned_at, processed_at) ELSE NULL END AS delivery_seconds
    FROM notification_queue
    WHERE status IN ("sent","failed","cancelled")
    ORDER BY COALESCE(processed_at, planned_at) DESC, id DESC
    LIMIT 40
');
$deliveryTotals = app_fetch_all($db, '
    SELECT
        COUNT(*) AS tracked_count,
        SUM(status = "sent") AS delivered_count,
        SUM(status = "failed") AS failed_count,
        ROUND(AVG(CASE WHEN processed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, planned_at, processed_at) END), 1) AS avg_delivery_seconds
    FROM notification_queue
    WHERE status IN ("sent","failed","cancelled")
');
$deliveryTotal = $deliveryTotals[0] ?? [
    'tracked_count' => 0,
    'delivered_count' => 0,
    'failed_count' => 0,
    'avg_delivery_seconds' => null,
];

$crmAlerts = app_fetch_all($db, "
    SELECT r.id, r.reminder_text, r.remind_at, r.status, c.company_name, c.full_name, c.email, c.phone
    FROM crm_reminders r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    WHERE DATE(r.remind_at) <= :today
    " . ($filters['search'] !== '' ? "AND (r.reminder_text LIKE :search OR c.company_name LIKE :search OR c.full_name LIKE :search)" : '') . "
    ORDER BY r.remind_at ASC
    LIMIT 20
", $filters['search'] !== '' ? ['today' => $today, 'search' => '%' . $filters['search'] . '%'] : ['today' => $today]);

$rentalAlerts = app_fetch_all($db, "
    SELECT p.id, p.due_date, p.amount, p.status, r.contract_no, c.company_name, c.full_name, c.email, c.phone
    FROM rental_payments p
    INNER JOIN rental_contracts r ON r.id = p.contract_id
    INNER JOIN cari_cards c ON c.id = r.cari_id
    WHERE p.status = 'gecikmis' OR (p.status = 'bekliyor' AND p.due_date <= :today)
    " . ($filters['search'] !== '' ? "AND (r.contract_no LIKE :search OR c.company_name LIKE :search OR c.full_name LIKE :search)" : '') . "
    ORDER BY p.due_date ASC
    LIMIT 20
", $filters['search'] !== '' ? ['today' => $today, 'search' => '%' . $filters['search'] . '%'] : ['today' => $today]);

$invoiceAlerts = app_fetch_all($db, "
    SELECT h.id, h.invoice_no, h.invoice_date, h.due_date, h.grand_total, h.paid_total, h.payment_status, h.currency_code, c.company_name, c.full_name, c.email, c.phone
    FROM invoice_headers h
    INNER JOIN cari_cards c ON c.id = h.cari_id
    WHERE h.invoice_type = 'satis'
      AND h.payment_status <> 'odendi'
      AND h.due_date IS NOT NULL
      AND h.due_date <= :invoice_due_until
      " . ($filters['search'] !== '' ? "AND (h.invoice_no LIKE :search OR c.company_name LIKE :search OR c.full_name LIKE :search)" : '') . "
    ORDER BY h.due_date ASC
    LIMIT 20
", $filters['search'] !== '' ? ['invoice_due_until' => $invoiceDueUntil, 'search' => '%' . $filters['search'] . '%'] : ['invoice_due_until' => $invoiceDueUntil]);

$queueWhere = [];
$queueParams = [];
if ($filters['search'] !== '') {
    $queueWhere[] = '(recipient_name LIKE :search OR recipient_contact LIKE :search OR subject_line LIKE :search OR notification_type LIKE :search)';
    $queueParams['search'] = '%' . $filters['search'] . '%';
}
if ($filters['queue_status'] !== '') {
    $queueWhere[] = 'status = :queue_status';
    $queueParams['queue_status'] = $filters['queue_status'];
}
if ($filters['module_name'] !== '') {
    $queueWhere[] = 'module_name = :module_name';
    $queueParams['module_name'] = $filters['module_name'];
}
$queueWhereSql = $queueWhere ? 'WHERE ' . implode(' AND ', $queueWhere) : '';

$queueRows = app_fetch_all($db, "
    SELECT id, module_name, notification_type, channel, recipient_name, recipient_contact, subject_line, status, planned_at, processed_at, provider_name, last_error
    FROM notification_queue
    {$queueWhereSql}
    ORDER BY id DESC
    LIMIT 40
", $queueParams);

$crmAlerts = app_sort_rows($crmAlerts, $filters['queue_sort'], [
    'id_desc' => ['remind_at', 'desc'],
    'date_asc' => ['remind_at', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$rentalAlerts = app_sort_rows($rentalAlerts, $filters['queue_sort'], [
    'id_desc' => ['due_date', 'desc'],
    'date_asc' => ['due_date', 'asc'],
    'value_desc' => ['amount', 'desc'],
    'value_asc' => ['amount', 'asc'],
]);
$queueRows = app_sort_rows($queueRows, $filters['queue_sort'], [
    'id_desc' => ['planned_at', 'desc'],
    'date_asc' => ['planned_at', 'asc'],
    'status_asc' => ['status', 'asc'],
    'module_asc' => ['module_name', 'asc'],
]);
$crmAlertsPagination = app_paginate_rows($crmAlerts, $filters['queue_page'], 10);
$rentalAlertsPagination = app_paginate_rows($rentalAlerts, $filters['queue_page'], 10);
$queueRowsPagination = app_paginate_rows($queueRows, $filters['queue_page'], 10);
$crmAlerts = $crmAlertsPagination['items'];
$rentalAlerts = $rentalAlertsPagination['items'];
$queueRows = $queueRowsPagination['items'];
?>

<?php if ($feedback !== ''): ?>
    <div class="notice <?= strpos($feedback, 'error:') === 0 ? 'notice-error' : 'notice-ok' ?>">
        <?php
        if (strpos($feedback, 'error:') === 0) {
            echo app_h(substr($feedback, 6));
        } elseif (strpos($feedback, 'scan:') === 0) {
            $payload = json_decode(urldecode(substr($feedback, 5)), true) ?: [];
            echo app_h('Tarama tamamlandi. CRM: ' . (int) ($payload['crm'] ?? 0) . ', Kira: ' . (int) ($payload['rental'] ?? 0) . ', Fatura: ' . (int) ($payload['invoice'] ?? 0) . ', Atlanan: ' . (int) ($payload['skipped'] ?? 0));
        } elseif (strpos($feedback, 'process:') === 0) {
            $payload = json_decode(urldecode(substr($feedback, 8)), true) ?: [];
            echo app_h('Kuyruk islendi. Toplam: ' . (int) ($payload['processed'] ?? 0) . ', Basarili: ' . (int) ($payload['sent'] ?? 0) . ', Hatali: ' . (int) ($payload['failed'] ?? 0));
        } elseif (strpos($feedback, 'bulk_contact:') === 0) {
            $payload = json_decode(urldecode(substr($feedback, 13)), true) ?: [];
            echo app_h('Toplu iletisim kuyruga alindi. Eklenen: ' . (int) ($payload['queued'] ?? 0) . ', Atlanan: ' . (int) ($payload['skipped'] ?? 0));
        } elseif ($feedback === 'cancel') {
            echo 'Bildirim iptal edildi.';
        } elseif ($feedback === 'retry') {
            echo 'Bildirim tekrar deneme icin kuyruga alindi.';
        } elseif ($feedback === 'test_email') {
            echo 'Test e-postasi gonderildi.';
        } elseif ($feedback === 'test_sms') {
            echo 'Test SMS gonderildi.';
        } elseif ($feedback === 'push_subscribed') {
            echo 'Push aboneligi kaydedildi.';
        } elseif ($feedback === 'test_push') {
            echo 'Test push bildirimi kuyruga alindi.';
        } elseif ($feedback === 'preference') {
            echo 'Bildirim tercihi kaydedildi.';
        } else {
            echo 'Islem kaydedildi.';
        }
        ?>
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
        <h3>Bildirim Basari Raporu</h3>
        <div class="module-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
            <div>
                <small>Toplam Bildirim</small>
                <strong style="display:block;font-size:1.6rem;"><?= (int) $successTotal ?></strong>
            </div>
            <div>
                <small>Basari Orani</small>
                <strong style="display:block;font-size:1.6rem;color:#047857;">%<?= app_h((string) $successRate) ?></strong>
            </div>
            <div>
                <small>Hata Orani</small>
                <strong style="display:block;font-size:1.6rem;color:#b91c1c;">%<?= app_h((string) $failureRate) ?></strong>
            </div>
            <div>
                <small>Bekleyen</small>
                <strong style="display:block;font-size:1.6rem;"><?= (int) ($successTotals['pending_count'] ?? 0) ?></strong>
            </div>
        </div>
        <div class="list" style="margin-top:16px;">
            <div class="row"><div><strong>Gonderilen</strong><span>Basariyla islenen kuyruk kaydi</span></div><div class="ok"><?= (int) $successSent ?></div></div>
            <div class="row"><div><strong>Hatali</strong><span>Servis veya ayar hatasi alan kayit</span></div><div class="warn"><?= (int) $successFailed ?></div></div>
            <div class="row"><div><strong>Iptal</strong><span>Kullanici tarafindan iptal edilen kayit</span></div><div class="warn"><?= (int) ($successTotals['cancelled_count'] ?? 0) ?></div></div>
        </div>
    </div>

    <div class="card">
        <h3>Kanal Basari Kirilimi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kanal</th><th>Toplam</th><th>Basarili</th><th>Hatali</th><th>Oran</th></tr></thead>
                <tbody>
                <?php foreach ($channelSuccessRows as $row): ?>
                    <?php $rowTotal = max(0, (int) $row['total_count']); $rowSent = max(0, (int) $row['sent_count']); ?>
                    <tr>
                        <td><?= app_h($row['channel']) ?></td>
                        <td><?= $rowTotal ?></td>
                        <td><?= $rowSent ?></td>
                        <td><?= (int) $row['failed_count'] ?></td>
                        <td>%<?= app_h((string) ($rowTotal > 0 ? round(($rowSent / $rowTotal) * 100, 1) : 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$channelSuccessRows): ?>
                    <tr><td colspan="5">Henuz bildirim kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Modul Basari Raporu</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Modul</th><th>Toplam</th><th>Basarili</th><th>Hatali</th><th>Oran</th></tr></thead>
                <tbody>
                <?php foreach ($moduleSuccessRows as $row): ?>
                    <?php $rowTotal = max(0, (int) $row['total_count']); $rowSent = max(0, (int) $row['sent_count']); ?>
                    <tr>
                        <td><?= app_h($row['module_name']) ?></td>
                        <td><?= $rowTotal ?></td>
                        <td><?= $rowSent ?></td>
                        <td><?= (int) $row['failed_count'] ?></td>
                        <td>%<?= app_h((string) ($rowTotal > 0 ? round(($rowSent / $rowTotal) * 100, 1) : 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$moduleSuccessRows): ?>
                    <tr><td colspan="5">Henuz modul raporu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Son Hata Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kayit</th><th>Kanal</th><th>Alici</th><th>Hata</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($recentFailureRows as $row): ?>
                    <tr>
                        <td><small>#<?= (int) $row['id'] ?><br><?= app_h($row['module_name'] . ' / ' . $row['notification_type']) ?></small></td>
                        <td><?= app_h($row['channel']) ?></td>
                        <td><?= app_h((string) ($row['recipient_name'] ?: $row['recipient_contact'])) ?></td>
                        <td><?= app_h(substr((string) ($row['last_error'] ?: '-'), 0, 120)) ?></td>
                        <td><?= app_h((string) ($row['processed_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentFailureRows): ?>
                    <tr><td colspan="5">Son hata kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Bildirim Teslim Raporu</h3>
        <div class="module-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
            <div>
                <small>Izlenen Teslim</small>
                <strong style="display:block;font-size:1.6rem;"><?= (int) ($deliveryTotal['tracked_count'] ?? 0) ?></strong>
            </div>
            <div>
                <small>Teslim Edilen</small>
                <strong style="display:block;font-size:1.6rem;color:#047857;"><?= (int) ($deliveryTotal['delivered_count'] ?? 0) ?></strong>
            </div>
            <div>
                <small>Teslim Hatasi</small>
                <strong style="display:block;font-size:1.6rem;color:#b91c1c;"><?= (int) ($deliveryTotal['failed_count'] ?? 0) ?></strong>
            </div>
            <div>
                <small>Ort. Teslim Suresi</small>
                <strong style="display:block;font-size:1.6rem;"><?= $deliveryTotal['avg_delivery_seconds'] !== null ? app_h((string) $deliveryTotal['avg_delivery_seconds']) . ' sn' : '-' ?></strong>
            </div>
        </div>
        <div class="table-wrap" style="margin-top:16px;">
            <table>
                <thead><tr><th>Kanal</th><th>Toplam</th><th>Teslim</th><th>Hata</th><th>Bekleyen</th><th>Ort. Sure</th></tr></thead>
                <tbody>
                <?php foreach ($deliverySummaryRows as $row): ?>
                    <tr>
                        <td><?= app_h($row['channel']) ?></td>
                        <td><?= (int) $row['total_count'] ?></td>
                        <td><?= (int) $row['delivered_count'] ?></td>
                        <td><?= (int) $row['failed_count'] ?></td>
                        <td><?= (int) $row['pending_count'] ?></td>
                        <td><?= $row['avg_delivery_seconds'] !== null ? app_h((string) $row['avg_delivery_seconds']) . ' sn' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deliverySummaryRows): ?>
                    <tr><td colspan="6">Henuz teslim verisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Teslim Detaylari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Bildirim</th><th>Alici</th><th>Kanal</th><th>Durum</th><th>Saglayici</th><th>Sure</th></tr></thead>
                <tbody>
                <?php foreach ($deliveryRows as $row): ?>
                    <tr>
                        <td><small>#<?= (int) $row['id'] ?><br><?= app_h($row['module_name'] . ' / ' . $row['notification_type']) ?></small></td>
                        <td><small><?= app_h((string) ($row['recipient_name'] ?: '-')) ?><br><?= app_h($row['recipient_contact']) ?></small></td>
                        <td><?= app_h($row['channel']) ?></td>
                        <td><?= app_h($row['status']) ?></td>
                        <td><?= app_h((string) ($row['provider_name'] ?: '-')) ?></td>
                        <td>
                            <small>
                                <?= $row['delivery_seconds'] !== null ? app_h((string) $row['delivery_seconds']) . ' sn' : '-' ?><br>
                                <?= app_h((string) ($row['processed_at'] ?: $row['planned_at'])) ?>
                            </small>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deliveryRows): ?>
                    <tr><td colspan="6">Henuz teslim detayi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Toplu Iletisim Gonderimi</h3>
    <form method="post" class="form-grid">
        <input type="hidden" name="action" value="send_bulk_contact">
        <div>
            <label>Hedef Kitle</label>
            <select name="bulk_target_type">
                <option value="users">Aktif Kullanicilar</option>
                <option value="customers">Cariler / Musteriler</option>
                <option value="push_subscribers">Push Aboneleri</option>
            </select>
        </div>
        <div>
            <label>Kanal</label>
            <select name="bulk_channel">
                <option value="email">E-posta</option>
                <option value="sms">SMS</option>
                <option value="push">Push</option>
            </select>
        </div>
        <div>
            <label>Plan Zamani</label>
            <input type="datetime-local" name="bulk_planned_at">
        </div>
        <div class="full">
            <label>Konu</label>
            <input name="bulk_subject" placeholder="Toplu duyuru / kampanya / operasyon bildirimi">
        </div>
        <div class="full">
            <label>Mesaj</label>
            <textarea name="bulk_message" rows="5" placeholder="Toplu iletisim mesajinizi yazin." required></textarea>
        </div>
        <div class="full">
            <small class="muted">E-posta icin konu zorunludur. Push aboneleri secilirse kanal otomatik push olarak kuyruga alinir. Tercih kurallari kapali kanallari atlayabilir.</small>
        </div>
        <div class="full">
            <button type="submit">Toplu Iletisimi Kuyruga Al</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Bildirim Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="bildirim">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Alici, konu, sozlesme, hatirlatma">
        </div>
        <div>
            <label>Kuyruk Durumu</label>
            <select name="queue_status">
                <option value="">Tum durumlar</option>
                <option value="pending" <?= $filters['queue_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="sent" <?= $filters['queue_status'] === 'sent' ? 'selected' : '' ?>>Sent</option>
                <option value="failed" <?= $filters['queue_status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                <option value="cancelled" <?= $filters['queue_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div>
            <label>Modul</label>
            <select name="module_name">
                <option value="">Tum moduller</option>
                <option value="crm" <?= $filters['module_name'] === 'crm' ? 'selected' : '' ?>>CRM</option>
                <option value="kira" <?= $filters['module_name'] === 'kira' ? 'selected' : '' ?>>Kira</option>
                <option value="fatura" <?= $filters['module_name'] === 'fatura' ? 'selected' : '' ?>>Fatura</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=bildirim" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="bildirim">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="queue_status" value="<?= app_h($filters['queue_status']) ?>">
        <input type="hidden" name="module_name" value="<?= app_h($filters['module_name']) ?>">
        <div>
            <label>Siralama</label>
            <select name="queue_sort">
                <option value="id_desc" <?= $filters['queue_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="date_asc" <?= $filters['queue_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="status_asc" <?= $filters['queue_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
                <option value="module_asc" <?= $filters['queue_sort'] === 'module_asc' ? 'selected' : '' ?>>Modul A-Z</option>
                <option value="value_desc" <?= $filters['queue_sort'] === 'value_desc' ? 'selected' : '' ?>>Tutar yuksek</option>
                <option value="value_asc" <?= $filters['queue_sort'] === 'value_asc' ? 'selected' : '' ?>>Tutar dusuk</option>
            </select>
        </div>
        <div>
            <label>Sayfa</label>
            <input type="number" name="queue_page" min="1" value="<?= (int) $filters['queue_page'] ?>">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Bildirim Tercihleri</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_notification_preference">
            <div>
                <label>Kullanici</label>
                <select name="preference_user_id">
                    <option value="0">Genel kural - tum kullanicilar</option>
                    <?php foreach ($userOptions as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name'] . ($user['email'] ? ' / ' . $user['email'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Modul</label>
                <select name="preference_module_name" required>
                    <option value="">Seciniz</option>
                    <option value="crm">CRM</option>
                    <option value="kira">Kira</option>
                    <option value="fatura">Fatura</option>
                    <option value="rapor">Rapor</option>
                    <option value="bildirim">Bildirim</option>
                    <option value="*">Tum Moduller</option>
                </select>
            </div>
            <div>
                <label>Bildirim Tipi</label>
                <select name="preference_notification_type">
                    <option value="*">Tum tipler</option>
                    <option value="crm_reminder">CRM Hatirlatma</option>
                    <option value="rental_overdue">Kira Gecikme</option>
                    <option value="rental_contract_end">Kira Bitis</option>
                    <option value="invoice_due">Fatura Vade</option>
                    <option value="invoice_overdue">Fatura Gecikme</option>
                    <option value="bulk_campaign">Toplu Kampanya</option>
                    <option value="test_push">Test Push</option>
                    <option value="pos_reconciliation_report">POS Mutabakat</option>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="preference_status">
                    <option value="active">Aktif</option>
                    <option value="passive">Pasif</option>
                </select>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="preference_email_enabled" value="1" checked> E-posta</label>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="preference_sms_enabled" value="1" checked> SMS</label>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="preference_push_enabled" value="1" checked> Push</label>
            </div>
            <div>
                <label>Sessiz Baslangic</label>
                <input type="time" name="quiet_start">
            </div>
            <div>
                <label>Sessiz Bitis</label>
                <input type="time" name="quiet_end">
            </div>
            <div class="full">
                <small class="muted">Kullaniciya ozel kural varsa once o uygulanir; yoksa genel kural kullanilir. Kapatilan kanal kuyruga eklenmez.</small>
            </div>
            <div class="full">
                <button type="submit">Tercihi Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Tercih Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kapsam</th><th>Modul</th><th>Tip</th><th>E-posta</th><th>SMS</th><th>Push</th><th>Sessiz</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($notificationPreferences as $preference): ?>
                    <tr>
                        <td><?= $preference['user_id'] ? app_h(($preference['full_name'] ?: ('Kullanici #' . $preference['user_id'])) . ($preference['email'] ? ' / ' . $preference['email'] : '')) : 'Genel' ?></td>
                        <td><?= app_h($preference['module_name']) ?></td>
                        <td><?= app_h($preference['notification_type']) ?></td>
                        <td><?= (int) $preference['email_enabled'] === 1 ? 'Acik' : 'Kapali' ?></td>
                        <td><?= (int) $preference['sms_enabled'] === 1 ? 'Acik' : 'Kapali' ?></td>
                        <td><?= (int) $preference['push_enabled'] === 1 ? 'Acik' : 'Kapali' ?></td>
                        <td><?= app_h(($preference['quiet_start'] ?: '-') . ' / ' . ($preference['quiet_end'] ?: '-')) ?></td>
                        <td><?= app_h($preference['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$notificationPreferences): ?>
                    <tr><td colspan="8">Henuz bildirim tercihi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Otomasyon Kontrolu</h3>
        <p>Bu katman CRM hatirlatmalarini, geciken kira tahsilatlarini ve fatura vade uyarilarini tarar, bildirim kuyruguna yazar ve secilen moda gore isler.</p>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">E-posta</strong><span><?= $emailEnabled ? 'Acik' : 'Kapali' ?> / Mod: <?= app_h($emailMode) ?></span></div><div class="ok"><?= $emailEnabled ? 'Hazir' : 'Kapali' ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">SMS</strong><span><?= $smsEnabled ? 'Acik' : 'Kapali' ?> / Mod: <?= app_h($smsMode) ?></span></div><div class="warn"><?= $smsEnabled ? 'Hazir' : 'Kapali' ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Push</strong><span><?= $pushEnabled ? 'Acik' : 'Kapali' ?> / Mod: <?= app_h($pushMode) ?></span></div><div class="warn"><?= $pushEnabled ? 'Hazir' : 'Kapali' ?></div></div>
            <div class="row"><div><strong style="font-size:1rem;">Son Tarama</strong><span><?= app_h($lastScanAt) ?></span></div><div class="ok">Scan</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Son Isleme</strong><span><?= app_h($lastProcessAt) ?></span></div><div class="ok">Queue</div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
            <form method="post">
                <input type="hidden" name="action" value="scan_notifications">
                <button type="submit">Tarama Calistir</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="process_notifications">
                <button type="submit">Bekleyenleri Isle</button>
            </form>
        </div>
        <p style="margin-top:16px;color:#667085;">Runner URL: <code><?= app_h((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['PHP_SELF']) . '/notification_runner.php?token=' . $runnerToken) ?></code></p>
    </div>

    <div class="card">
        <h3>Bildirim Ayarlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_notification_settings">
            <div class="check-row">
                <label><input type="checkbox" name="email_enabled" value="1" <?= $emailEnabled ? 'checked' : '' ?>> E-posta aktif</label>
            </div>
            <div>
                <label>E-posta Modu</label>
                <select name="email_mode">
                    <option value="mock" <?= $emailMode === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="php_mail" <?= $emailMode === 'php_mail' ? 'selected' : '' ?>>PHP mail()</option>
                    <option value="smtp" <?= $emailMode === 'smtp' ? 'selected' : '' ?>>SMTP</option>
                </select>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="sms_enabled" value="1" <?= $smsEnabled ? 'checked' : '' ?>> SMS aktif</label>
            </div>
            <div>
                <label>SMS Modu</label>
                <select name="sms_mode">
                    <option value="mock" <?= $smsMode === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="http_api" <?= $smsMode === 'http_api' ? 'selected' : '' ?>>HTTP API</option>
                </select>
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="push_enabled" value="1" <?= $pushEnabled ? 'checked' : '' ?>> Push aktif</label>
            </div>
            <div>
                <label>Push Modu</label>
                <select name="push_mode">
                    <option value="mock" <?= $pushMode === 'mock' ? 'selected' : '' ?>>Mock</option>
                    <option value="webpush" <?= $pushMode === 'webpush' ? 'selected' : '' ?>>Web Push</option>
                </select>
            </div>
            <div>
                <label>SMTP Host</label>
                <input name="smtp_host" value="<?= app_h($smtpHost) ?>" placeholder="smtp.ornek.com">
            </div>
            <div>
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= app_h($smtpPort) ?>" min="1">
            </div>
            <div>
                <label>Guvenlik</label>
                <select name="smtp_security">
                    <option value="tls" <?= $smtpSecurity === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="ssl" <?= $smtpSecurity === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= $smtpSecurity === 'none' ? 'selected' : '' ?>>Yok</option>
                </select>
            </div>
            <div>
                <label>Zaman Asimi</label>
                <input type="number" name="smtp_timeout" value="<?= app_h($smtpTimeout) ?>" min="5" max="60">
            </div>
            <div>
                <label>Kullanici</label>
                <input name="smtp_username" value="<?= app_h($smtpUsername) ?>" placeholder="kullanici@alanadi.com">
            </div>
            <div>
                <label>Sifre</label>
                <input type="password" name="smtp_password" value="<?= app_h($smtpPassword) ?>" placeholder="SMTP sifresi">
            </div>
            <div>
                <label>Gonderen E-posta</label>
                <input name="smtp_from_email" value="<?= app_h($smtpFromEmail) ?>" placeholder="bildirim@alanadi.com">
            </div>
            <div>
                <label>Gonderen Adi</label>
                <input name="smtp_from_name" value="<?= app_h($smtpFromName) ?>" placeholder="Galancy Bildirim">
            </div>
            <div>
                <label>SMS API URL</label>
                <input name="sms_api_url" value="<?= app_h($smsApiUrl) ?>" placeholder="https://api.ornek.com/sms/send">
            </div>
            <div>
                <label>SMS HTTP Metodu</label>
                <select name="sms_api_method">
                    <option value="POST" <?= strtoupper($smsApiMethod) === 'POST' ? 'selected' : '' ?>>POST</option>
                    <option value="PUT" <?= strtoupper($smsApiMethod) === 'PUT' ? 'selected' : '' ?>>PUT</option>
                </select>
            </div>
            <div>
                <label>SMS Content-Type</label>
                <input name="sms_api_content_type" value="<?= app_h($smsApiContentType) ?>" placeholder="application/json">
            </div>
            <div>
                <label>SMS Timeout</label>
                <input type="number" name="sms_api_timeout" value="<?= app_h($smsApiTimeout) ?>" min="5" max="60">
            </div>
            <div class="full">
                <label>SMS Headerlari</label>
                <textarea name="sms_api_headers" rows="4" placeholder="Authorization: Bearer APIKEY&#10;X-Account: demo"><?= app_h($smsApiHeaders) ?></textarea>
            </div>
            <div class="full">
                <label>SMS Govde Sabonu</label>
                <textarea name="sms_api_body" rows="5" placeholder='{"to":"{{phone}}","message":"{{message}}"}'><?= app_h($smsApiBody) ?></textarea>
            </div>
            <div class="full">
                <label>Push Public Key</label>
                <textarea name="push_public_key" rows="2" placeholder="VAPID public key"><?= app_h($pushPublicKey) ?></textarea>
            </div>
            <div class="full">
                <label>Push Private Key</label>
                <textarea name="push_private_key" rows="2" placeholder="VAPID private key"><?= app_h($pushPrivateKey) ?></textarea>
            </div>
            <div class="full">
                <label>CRM E-posta Konusu</label>
                <input name="crm_email_subject" value="<?= app_h(app_setting($db, 'notifications.crm_email_subject', 'CRM Hatirlatma / {{company_name}}')) ?>">
            </div>
            <div class="full">
                <label>CRM E-posta Metni</label>
                <textarea name="crm_email_body" rows="5"><?= app_h(app_setting($db, 'notifications.crm_email_body', "Merhaba,\n\n{{reminder_text}}\nTarih: {{remind_at}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi")) ?></textarea>
            </div>
            <div class="full">
                <label>CRM SMS Metni</label>
                <textarea name="crm_sms_body" rows="3"><?= app_h(app_setting($db, 'notifications.crm_sms_body', '{{company_name}} icin hatirlatma: {{reminder_text}} / {{remind_at}}')) ?></textarea>
            </div>
            <div class="full">
                <label>Kira E-posta Konusu</label>
                <input name="rental_email_subject" value="<?= app_h(app_setting($db, 'notifications.rental_email_subject', 'Gecikmis Kira Tahsilati / {{contract_no}}')) ?>">
            </div>
            <div class="full">
                <label>Kira E-posta Metni</label>
                <textarea name="rental_email_body" rows="5"><?= app_h(app_setting($db, 'notifications.rental_email_body', "Merhaba,\n\n{{contract_no}} sozlesmesine ait {{amount}} tutarli kira tahsilati gecikmistir.\nVade: {{due_date}}\nCari: {{company_name}}\n\nGalancy Bildirim Merkezi")) ?></textarea>
            </div>
            <div class="full">
                <label>Kira SMS Metni</label>
                <textarea name="rental_sms_body" rows="3"><?= app_h(app_setting($db, 'notifications.rental_sms_body', '{{contract_no}} sozlesmesi icin {{due_date}} vadeli {{amount}} tutarli kira odemesi gecikmistir.')) ?></textarea>
            </div>
            <div>
                <label>Fatura Hatirlatma Gunu</label>
                <input type="number" min="0" name="invoice_due_reminder_days" value="<?= app_h($invoiceReminderDays) ?>">
            </div>
            <div class="full">
                <label>Fatura E-posta Konusu</label>
                <input name="invoice_email_subject" value="<?= app_h(app_setting($db, 'notifications.invoice_email_subject', 'Fatura Vade Hatirlatmasi / {{invoice_no}}')) ?>">
            </div>
            <div class="full">
                <label>Fatura E-posta Metni</label>
                <textarea name="invoice_email_body" rows="5"><?= app_h(app_setting($db, 'notifications.invoice_email_body', "Merhaba,\n\n{{invoice_no}} numarali {{remaining_total}} tutarli faturanin vadesi {{due_date}} tarihindedir.\nCari: {{company_name}}\nDurum: {{payment_status}}\n\nGalancy Bildirim Merkezi")) ?></textarea>
            </div>
            <div class="full">
                <label>Fatura SMS Metni</label>
                <textarea name="invoice_sms_body" rows="3"><?= app_h(app_setting($db, 'notifications.invoice_sms_body', '{{invoice_no}} numarali {{remaining_total}} tutarli fatura icin vade {{due_date}} / Durum: {{payment_status}}')) ?></textarea>
            </div>
            <div class="full">
                <label>Webhook Hata E-posta</label>
                <input type="email" name="webhook_alert_email" value="<?= app_h($webhookAlertEmail) ?>" placeholder="pos-uyari@alanadi.com">
            </div>
            <div class="check-row full">
                <label><input type="checkbox" name="pos_report_enabled" value="1" <?= $posReportEnabled ? 'checked' : '' ?>> Gunluk POS mutabakat raporu otomatik e-posta kuyruguna alinsin</label>
            </div>
            <div class="full">
                <label>POS Mutabakat Raporu E-posta</label>
                <input type="email" name="pos_report_email" value="<?= app_h($posReportEmail) ?>" placeholder="rapor@alanadi.com">
            </div>
            <div class="full">
                <button type="submit">Ayarlari Kaydet</button>
            </div>
        </form>
        <form method="post" class="form-grid" style="margin-top:14px;">
            <input type="hidden" name="action" value="send_test_email">
            <div class="full">
                <label>Test E-posta</label>
                <input type="email" name="test_email" value="<?= app_h($authUser['email'] ?? '') ?>" placeholder="test@alanadi.com">
            </div>
            <div class="full">
                <button type="submit">Test E-postasi Gonder</button>
            </div>
        </form>
        <form method="post" class="form-grid" style="margin-top:14px;">
            <input type="hidden" name="action" value="send_test_sms">
            <div class="full">
                <label>Test SMS Numarasi</label>
                <input name="test_sms" value="" placeholder="90555xxxxxxx">
            </div>
            <div class="full">
                <button type="submit">Test SMS Gonder</button>
            </div>
        </form>
        <form id="push-subscription-form" method="post" class="form-grid" style="margin-top:14px;">
            <input type="hidden" name="action" value="save_push_subscription">
            <input type="hidden" name="endpoint">
            <input type="hidden" name="public_key">
            <input type="hidden" name="auth_token">
            <div class="full">
                <label>Tarayici Push Aboneligi</label>
                <small class="muted">Bu tarayiciyi push bildirimlerine abone eder. Gercek Web Push icin VAPID public/private key girilmelidir.</small>
            </div>
            <div class="full" style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" id="push-subscribe-btn">Bu Tarayiciyi Abone Et</button>
                <span id="push-status" class="muted"></span>
            </div>
        </form>
        <form method="post" class="form-grid" style="margin-top:14px;">
            <input type="hidden" name="action" value="send_test_push">
            <div class="full">
                <button type="submit">Test Push Kuyruga Al</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Fatura Vade Hatirlatmalari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Fatura</th><th>Cari</th><th>Vade</th><th>Kalan</th><th>Durum</th><th>E-posta</th><th>SMS</th></tr></thead>
                <tbody>
                <?php foreach ($invoiceAlerts as $item): ?>
                    <?php $remainingAmount = max(0, (float) $item['grand_total'] - (float) $item['paid_total']); ?>
                    <tr>
                        <td><a href="invoice_detail.php?id=<?= (int) $item['id'] ?>"><?= app_h($item['invoice_no']) ?></a></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['due_date']) ?></td>
                        <td><?= app_h(number_format($remainingAmount, 2, ',', '.') . ' ' . ($item['currency_code'] ?: 'TRY')) ?></td>
                        <td><?= app_h(((string) $item['due_date'] < $today) ? 'Vadesi Gecmis' : (((string) $item['due_date'] === $today) ? 'Bugun Vadeli' : 'Yaklasan Vade')) ?></td>
                        <td><?= app_h($item['email'] ?: '-') ?></td>
                        <td><?= app_h($item['phone'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>CRM Hatirlatmalari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Hatirlatma</th><th>Cari</th><th>Tarih</th><th>E-posta</th><th>SMS</th></tr></thead>
                <tbody>
                <?php foreach ($crmAlerts as $item): ?>
                    <tr>
                        <td><?= app_h($item['reminder_text']) ?></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['remind_at']) ?></td>
                        <td><?= app_h($item['email'] ?: '-') ?></td>
                        <td><?= app_h($item['phone'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Geciken Kira Tahsilatlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sozlesme</th><th>Cari</th><th>Vade</th><th>Tutar</th><th>E-posta</th><th>SMS</th></tr></thead>
                <tbody>
                <?php foreach ($rentalAlerts as $item): ?>
                    <tr>
                        <td><?= app_h($item['contract_no']) ?></td>
                        <td><?= app_h($item['company_name'] ?: $item['full_name'] ?: '-') ?></td>
                        <td><?= app_h($item['due_date']) ?></td>
                        <td><?= number_format((float) $item['amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($item['email'] ?: '-') ?></td>
                        <td><?= app_h($item['phone'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Bildirim Kuyrugu</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_cancel_notifications">
            <button type="submit">Secili Bekleyenleri Iptal Et</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_retry_notifications'">Secili Hatalilari Tekrar Dene</button>
            <button type="submit" onclick="this.form.querySelector('input[name=action]').value='bulk_export_notifications_csv'">Secili Kayitlari CSV</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.queue-check').forEach((el)=>el.checked=this.checked)"></th><th>Modul</th><th>Tip</th><th>Kanal</th><th>Alici</th><th>Durum</th><th>Plan</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($queueRows as $item): ?>
                    <tr>
                        <td>
                            <?php if (in_array($item['status'], ['pending', 'failed', 'cancelled'], true)): ?>
                                <input class="queue-check" type="checkbox" name="queue_ids[]" value="<?= (int) $item['id'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><?= app_h($item['module_name']) ?></td>
                        <td><?= app_h($item['notification_type']) ?></td>
                        <td><?= app_h($item['channel']) ?></td>
                        <td><?= app_h(($item['recipient_name'] ?: '-') . ' / ' . $item['recipient_contact']) ?></td>
                        <td><?= app_h($item['status']) ?></td>
                        <td><?= app_h($item['planned_at']) ?></td>
                        <td>
                            <?php if ($item['status'] === 'pending'): ?>
                                <form method="post" onsubmit="return confirm('Bu bildirim iptal edilsin mi?');">
                                    <input type="hidden" name="action" value="cancel_notification">
                                    <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit">Iptal</button>
                                </form>
                            <?php elseif (in_array($item['status'], ['failed', 'cancelled'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="retry_notification">
                                    <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit">Tekrar Dene</button>
                                </form>
                            <?php else: ?>
                                <?= app_h($item['processed_at'] ?: '-') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($item['last_error'])): ?>
                        <tr>
                            <td colspan="8" style="color:#991b1b;"><?= app_h((string) $item['last_error']) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
    </div>

    <div class="card">
        <h3>Otomasyon Mantigi</h3>
        <div class="list">
            <div class="row"><div><strong style="font-size:1rem;">CRM</strong><span>Durumu `bekliyor` olan ve zamani gelen hatirlatmalar kuyruga yazilir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kira</strong><span>Vadesi gecmis `bekliyor / gecikmis` odemeler gunluk olarak kuyruklanir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Fatura</strong><span>Vadesi yaklasan veya gecmis ve `odendi` olmayan satis faturalari kuyruklanir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Mock Modu</strong><span>Bu modda bildirimler gercek servis yerine sistem icinde `sent` durumuna cekilir.</span></div><div class="warn">Guvenli</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Gercek E-posta</strong><span>`PHP mail()` veya `SMTP` secilebilir; SMTP icin host, port, guvenlik ve gonderen bilgileri girilir.</span></div><div class="warn">Opsiyonel</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Gercek SMS</strong><span>`HTTP API` modunda URL, header ve govde sabonu tanimlanir. `{{phone}}` ve `{{message}}` degiskenleri otomatik dolar.</span></div><div class="warn">Esnek</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Push Notification</strong><span>Tarayici abonelikleri kaydedilir; mock modda kuyruk test edilir, Web Push icin VAPID anahtarlari gerekir.</span></div><div class="warn">Yeni</div></div>
        </div>
    </div>
</section>

<script>
(function () {
    const button = document.getElementById('push-subscribe-btn');
    const status = document.getElementById('push-status');
    const form = document.getElementById('push-subscription-form');
    const publicKey = <?= json_encode($pushPublicKey, JSON_UNESCAPED_SLASHES) ?>;

    function setStatus(message) {
        if (status) {
            status.textContent = message;
        }
    }

    function base64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    if (!button || !form) {
        return;
    }

    button.addEventListener('click', async function () {
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                setStatus('Bu tarayici push bildirimini desteklemiyor.');
                return;
            }

            if (!publicKey) {
                setStatus('Once Push Public Key alanini kaydedin.');
                return;
            }

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setStatus('Bildirim izni verilmedi.');
                return;
            }

            const registration = await navigator.serviceWorker.register('push-worker.js');
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64ToUint8Array(publicKey)
            });
            const data = subscription.toJSON();

            form.endpoint.value = data.endpoint || '';
            form.public_key.value = data.keys && data.keys.p256dh ? data.keys.p256dh : '';
            form.auth_token.value = data.keys && data.keys.auth ? data.keys.auth : '';
            setStatus('Abonelik kaydediliyor...');
            form.submit();
        } catch (error) {
            setStatus('Push aboneligi basarisiz: ' + error.message);
        }
    });
})();
</script>
