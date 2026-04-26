<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>CRM modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function crm_cari_label(array $row): string
{
    if (!empty($row['company_name'])) {
        return (string) $row['company_name'];
    }

    if (!empty($row['full_name'])) {
        return (string) $row['full_name'];
    }

    return 'Cari #' . (int) $row['id'];
}

function crm_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'reminder_status' => trim((string) ($_GET['reminder_status'] ?? '')),
        'opportunity_stage' => trim((string) ($_GET['opportunity_stage'] ?? '')),
        'customer_sort' => trim((string) ($_GET['customer_sort'] ?? 'id_desc')),
        'reminder_sort' => trim((string) ($_GET['reminder_sort'] ?? 'date_desc')),
        'customer_page' => max(1, (int) ($_GET['customer_page'] ?? 1)),
        'reminder_page' => max(1, (int) ($_GET['reminder_page'] ?? 1)),
        'timeline_cari_id' => max(0, (int) ($_GET['timeline_cari_id'] ?? 0)),
    ];
}

function crm_selected_ids(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

function crm_ensure_activity_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_activities_date (activity_at)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_call_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_call_logs_date (call_at)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_meeting_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_meeting_notes_followup (follow_up_at)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_offer_opportunity_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_opportunity_offer_opportunity (opportunity_id),
            INDEX idx_crm_opportunity_offer_offer (offer_id),
            INDEX idx_crm_opportunity_offer_status (relation_status)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_opportunity_score_schema(PDO $db): void
{
    $columns = app_fetch_all($db, "SHOW COLUMNS FROM crm_opportunities LIKE 'probability_score'");
    if (!$columns) {
        $db->exec("ALTER TABLE crm_opportunities ADD probability_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount");
    }

    $columns = app_fetch_all($db, "SHOW COLUMNS FROM crm_opportunities LIKE 'probability_note'");
    if (!$columns) {
        $db->exec("ALTER TABLE crm_opportunities ADD probability_note VARCHAR(255) NULL AFTER probability_score");
    }
}

function crm_ensure_opportunity_source_schema(PDO $db): void
{
    $columns = app_fetch_all($db, "SHOW COLUMNS FROM crm_opportunities LIKE 'source_channel'");
    if (!$columns) {
        $db->exec("ALTER TABLE crm_opportunities ADD source_channel VARCHAR(80) NULL AFTER probability_note");
    }

    $columns = app_fetch_all($db, "SHOW COLUMNS FROM crm_opportunities LIKE 'source_campaign'");
    if (!$columns) {
        $db->exec("ALTER TABLE crm_opportunities ADD source_campaign VARCHAR(150) NULL AFTER source_channel");
    }

    $columns = app_fetch_all($db, "SHOW COLUMNS FROM crm_opportunities LIKE 'source_referrer'");
    if (!$columns) {
        $db->exec("ALTER TABLE crm_opportunities ADD source_referrer VARCHAR(180) NULL AFTER source_campaign");
    }
}

function crm_ensure_customer_segment_schema(PDO $db): void
{
    $columns = app_fetch_all($db, "SHOW COLUMNS FROM cari_cards LIKE 'segment_code'");
    if (!$columns) {
        $db->exec("ALTER TABLE cari_cards ADD segment_code VARCHAR(80) NULL AFTER due_day");
    }

    $columns = app_fetch_all($db, "SHOW COLUMNS FROM cari_cards LIKE 'segment_note'");
    if (!$columns) {
        $db->exec("ALTER TABLE cari_cards ADD segment_note VARCHAR(255) NULL AFTER segment_code");
    }
}

function crm_ensure_tag_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS crm_tags (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tag_name VARCHAR(80) NOT NULL,
            tag_color VARCHAR(20) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_crm_tags_name (tag_name)
        ) ENGINE=InnoDB
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS crm_cari_tags (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cari_id BIGINT NOT NULL,
            tag_id BIGINT NOT NULL,
            tag_note VARCHAR(255) NULL,
            assigned_by VARCHAR(150) NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_crm_cari_tag (cari_id, tag_id),
            INDEX idx_crm_cari_tags_cari (cari_id),
            INDEX idx_crm_cari_tags_tag (tag_id)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_campaign_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_campaigns_channel (channel),
            INDEX idx_crm_campaigns_planned (planned_at)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_email_template_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_email_templates_status (status),
            INDEX idx_crm_email_templates_category (category_name)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_sms_template_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_sms_templates_status (status),
            INDEX idx_crm_sms_templates_category (category_name)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_whatsapp_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_whatsapp_cari (cari_id),
            INDEX idx_crm_whatsapp_status (status),
            INDEX idx_crm_whatsapp_created (created_at)
        ) ENGINE=InnoDB
    ");
}

function crm_ensure_task_schema(PDO $db): void
{
    $db->exec("
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
            INDEX idx_crm_tasks_cari (cari_id),
            INDEX idx_crm_tasks_opportunity (opportunity_id),
            INDEX idx_crm_tasks_assigned (assigned_user_id),
            INDEX idx_crm_tasks_status (status, due_at)
        ) ENGINE=InnoDB
    ");
}

function crm_campaign_render_message(string $template, array $row): string
{
    $name = (string) ($row['company_name'] ?: $row['full_name'] ?: '-');

    return str_replace(
        ['{{company_name}}', '{{full_name}}', '{{segment}}', '{{source}}'],
        [$name, (string) ($row['full_name'] ?: $name), (string) ($row['segment_code'] ?: '-'), (string) ($row['source_channel'] ?: '-')],
        $template
    );
}

function crm_normalize_whatsapp_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (substr($digits, 0, 2) === '00') {
        $digits = substr($digits, 2);
    }

    if (substr($digits, 0, 1) === '0' && strlen($digits) === 11) {
        return '90' . substr($digits, 1);
    }

    if (strlen($digits) === 10) {
        return '90' . $digits;
    }

    return $digits;
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

crm_ensure_activity_schema($db);
crm_ensure_call_schema($db);
crm_ensure_meeting_schema($db);
crm_ensure_offer_opportunity_schema($db);
crm_ensure_opportunity_score_schema($db);
crm_ensure_opportunity_source_schema($db);
crm_ensure_customer_segment_schema($db);
crm_ensure_tag_schema($db);
crm_ensure_campaign_schema($db);
crm_ensure_email_template_schema($db);
crm_ensure_sms_template_schema($db);
crm_ensure_whatsapp_schema($db);
crm_ensure_task_schema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'link_offer_opportunity') {
            $opportunityId = (int) ($_POST['opportunity_id'] ?? 0);
            $offerId = (int) ($_POST['offer_id'] ?? 0);
            $status = trim((string) ($_POST['relation_status'] ?? 'aktif')) ?: 'aktif';
            $allowedStatuses = ['taslak', 'aktif', 'kazanildi', 'kaybedildi', 'iptal'];

            if ($opportunityId <= 0 || $offerId <= 0 || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Firsat, teklif ve durum secimi zorunludur.');
            }

            $pairRows = app_fetch_all($db, '
                SELECT o.cari_id AS opportunity_cari_id, so.cari_id AS offer_cari_id
                FROM crm_opportunities o
                INNER JOIN sales_offers so ON so.id = :offer_id
                WHERE o.id = :opportunity_id
                LIMIT 1
            ', [
                'offer_id' => $offerId,
                'opportunity_id' => $opportunityId,
            ]);

            if (!$pairRows || (int) $pairRows[0]['opportunity_cari_id'] !== (int) $pairRows[0]['offer_cari_id']) {
                throw new RuntimeException('Firsat ve teklif ayni cariye ait olmalidir.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_opportunity_offer_links (
                    opportunity_id, offer_id, relation_status, relation_note, linked_by
                ) VALUES (
                    :opportunity_id, :offer_id, :relation_status, :relation_note, :linked_by
                )
                ON DUPLICATE KEY UPDATE
                    relation_status = VALUES(relation_status),
                    relation_note = VALUES(relation_note),
                    linked_by = VALUES(linked_by),
                    linked_at = NOW()
            ');
            $stmt->execute([
                'opportunity_id' => $opportunityId,
                'offer_id' => $offerId,
                'relation_status' => $status,
                'relation_note' => trim((string) ($_POST['relation_note'] ?? '')) ?: null,
                'linked_by' => trim((string) ($_POST['linked_by'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&ok=offer_opportunity');
        }

        if ($action === 'create_meeting_note') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $subject = trim((string) ($_POST['meeting_subject'] ?? ''));
            $meetingAt = trim((string) ($_POST['meeting_at'] ?? ''));
            $meetingType = trim((string) ($_POST['meeting_type'] ?? 'online')) ?: 'online';
            $allowedTypes = ['telefon', 'online', 'ofis', 'saha', 'diger'];

            if ($cariId <= 0 || $subject === '' || $meetingAt === '' || !in_array($meetingType, $allowedTypes, true)) {
                throw new RuntimeException('Gorusme notu icin cari, konu, tip ve tarih zorunludur.');
            }

            $meetingAtSql = str_replace('T', ' ', $meetingAt) . (strlen($meetingAt) === 16 ? ':00' : '');
            $followUpAt = trim((string) ($_POST['follow_up_at'] ?? ''));
            $followUpAtSql = $followUpAt !== '' ? str_replace('T', ' ', $followUpAt) . (strlen($followUpAt) === 16 ? ':00' : '') : null;

            $stmt = $db->prepare('
                INSERT INTO crm_meeting_notes (
                    cari_id, meeting_type, meeting_subject, meeting_at, participants,
                    decisions, action_items, follow_up_at, responsible_name, notes, created_by
                ) VALUES (
                    :cari_id, :meeting_type, :meeting_subject, :meeting_at, :participants,
                    :decisions, :action_items, :follow_up_at, :responsible_name, :notes, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'meeting_type' => $meetingType,
                'meeting_subject' => $subject,
                'meeting_at' => $meetingAtSql,
                'participants' => trim((string) ($_POST['participants'] ?? '')) ?: null,
                'decisions' => trim((string) ($_POST['decisions'] ?? '')) ?: null,
                'action_items' => trim((string) ($_POST['action_items'] ?? '')) ?: null,
                'follow_up_at' => $followUpAtSql,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => (int) ($authUser['id'] ?? 1),
            ]);

            app_redirect('index.php?module=crm&ok=meeting');
        }

        if ($action === 'create_call_log') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $subject = trim((string) ($_POST['call_subject'] ?? ''));
            $callAt = trim((string) ($_POST['call_at'] ?? ''));
            $direction = trim((string) ($_POST['call_direction'] ?? 'giden')) ?: 'giden';
            $result = trim((string) ($_POST['call_result'] ?? 'ulasildi')) ?: 'ulasildi';
            $allowedDirections = ['gelen', 'giden'];
            $allowedResults = ['ulasildi', 'ulasamadi', 'mesgul', 'geri_arayacak', 'olumsuz', 'diger'];

            if ($cariId <= 0 || $subject === '' || $callAt === '' || !in_array($direction, $allowedDirections, true) || !in_array($result, $allowedResults, true)) {
                throw new RuntimeException('Arama kaydi icin cari, konu, yon, sonuc ve tarih zorunludur.');
            }

            $callAtSql = str_replace('T', ' ', $callAt) . (strlen($callAt) === 16 ? ':00' : '');
            $callbackAt = trim((string) ($_POST['callback_at'] ?? ''));
            $callbackAtSql = $callbackAt !== '' ? str_replace('T', ' ', $callbackAt) . (strlen($callbackAt) === 16 ? ':00' : '') : null;

            $stmt = $db->prepare('
                INSERT INTO crm_call_logs (
                    cari_id, call_direction, call_subject, call_result, call_at,
                    duration_seconds, callback_at, phone_number, responsible_name, notes, created_by
                ) VALUES (
                    :cari_id, :call_direction, :call_subject, :call_result, :call_at,
                    :duration_seconds, :callback_at, :phone_number, :responsible_name, :notes, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'call_direction' => $direction,
                'call_subject' => $subject,
                'call_result' => $result,
                'call_at' => $callAtSql,
                'duration_seconds' => max(0, (int) ($_POST['duration_seconds'] ?? 0)),
                'callback_at' => $callbackAtSql,
                'phone_number' => trim((string) ($_POST['phone_number'] ?? '')) ?: null,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => (int) ($authUser['id'] ?? 1),
            ]);

            app_redirect('index.php?module=crm&ok=call');
        }

        if ($action === 'create_activity') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $subject = trim((string) ($_POST['activity_subject'] ?? ''));
            $activityAt = trim((string) ($_POST['activity_at'] ?? ''));
            $activityType = trim((string) ($_POST['activity_type'] ?? 'arama')) ?: 'arama';
            $allowedTypes = ['arama', 'toplanti', 'e-posta', 'ziyaret', 'gorev', 'diger'];

            if ($cariId <= 0 || $subject === '' || $activityAt === '' || !in_array($activityType, $allowedTypes, true)) {
                throw new RuntimeException('Aktivite icin cari, konu, tip ve tarih zorunludur.');
            }

            $activityAtSql = str_replace('T', ' ', $activityAt) . (strlen($activityAt) === 16 ? ':00' : '');
            $nextActionAt = trim((string) ($_POST['next_action_at'] ?? ''));
            $nextActionAtSql = $nextActionAt !== '' ? str_replace('T', ' ', $nextActionAt) . (strlen($nextActionAt) === 16 ? ':00' : '') : null;

            $stmt = $db->prepare('
                INSERT INTO crm_activities (
                    cari_id, activity_type, activity_subject, activity_result, activity_at,
                    next_action_at, responsible_name, notes, created_by
                ) VALUES (
                    :cari_id, :activity_type, :activity_subject, :activity_result, :activity_at,
                    :next_action_at, :responsible_name, :notes, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'activity_type' => $activityType,
                'activity_subject' => $subject,
                'activity_result' => trim((string) ($_POST['activity_result'] ?? '')) ?: null,
                'activity_at' => $activityAtSql,
                'next_action_at' => $nextActionAtSql,
                'responsible_name' => trim((string) ($_POST['responsible_name'] ?? '')) ?: null,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'created_by' => (int) ($authUser['id'] ?? 1),
            ]);

            app_redirect('index.php?module=crm&ok=activity');
        }

        if ($action === 'create_note') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $noteText = trim((string) ($_POST['note_text'] ?? ''));

            if ($cariId <= 0 || $noteText === '') {
                throw new RuntimeException('Cari ve not alani zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_notes (cari_id, note_text, created_by)
                VALUES (:cari_id, :note_text, :created_by)
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'note_text' => $noteText,
                'created_by' => 1,
            ]);

            app_redirect('index.php?module=crm&ok=note');
        }

        if ($action === 'create_reminder') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $reminderText = trim((string) ($_POST['reminder_text'] ?? ''));
            $remindAt = trim((string) ($_POST['remind_at'] ?? ''));

            if ($cariId <= 0 || $reminderText === '' || $remindAt === '') {
                throw new RuntimeException('Cari, hatirlatma ve tarih alanlari zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_reminders (cari_id, reminder_text, remind_at, status, created_by)
                VALUES (:cari_id, :reminder_text, :remind_at, :status, :created_by)
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'reminder_text' => $reminderText,
                'remind_at' => $remindAt,
                'status' => $_POST['status'] ?? 'bekliyor',
                'created_by' => 1,
            ]);

            app_redirect('index.php?module=crm&ok=reminder');
        }

        if ($action === 'create_opportunity') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $probabilityScore = max(0, min(100, (int) ($_POST['probability_score'] ?? 0)));

            if ($cariId <= 0 || $title === '') {
                throw new RuntimeException('Cari ve firsat basligi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_opportunities (
                    cari_id, title, stage, amount, probability_score, probability_note,
                    source_channel, source_campaign, source_referrer, expected_close_date
                ) VALUES (
                    :cari_id, :title, :stage, :amount, :probability_score, :probability_note,
                    :source_channel, :source_campaign, :source_referrer, :expected_close_date
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'title' => $title,
                'stage' => trim((string) ($_POST['stage'] ?? 'Yeni')) ?: 'Yeni',
                'amount' => (float) ($_POST['amount'] ?? 0),
                'probability_score' => $probabilityScore,
                'probability_note' => trim((string) ($_POST['probability_note'] ?? '')) ?: null,
                'source_channel' => trim((string) ($_POST['source_channel'] ?? '')) ?: null,
                'source_campaign' => trim((string) ($_POST['source_campaign'] ?? '')) ?: null,
                'source_referrer' => trim((string) ($_POST['source_referrer'] ?? '')) ?: null,
                'expected_close_date' => trim((string) ($_POST['expected_close_date'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&ok=opportunity');
        }

        if ($action === 'update_opportunity_probability') {
            $opportunityId = (int) ($_POST['opportunity_id'] ?? 0);
            $probabilityScore = max(0, min(100, (int) ($_POST['probability_score'] ?? 0)));

            if ($opportunityId <= 0) {
                throw new RuntimeException('Firsat secimi zorunludur.');
            }

            $stmt = $db->prepare('
                UPDATE crm_opportunities
                SET probability_score = :probability_score,
                    probability_note = :probability_note
                WHERE id = :id
            ');
            $stmt->execute([
                'probability_score' => $probabilityScore,
                'probability_note' => trim((string) ($_POST['probability_note'] ?? '')) ?: null,
                'id' => $opportunityId,
            ]);

            app_redirect('index.php?module=crm&ok=probability');
        }

        if ($action === 'update_opportunity_source') {
            $opportunityId = (int) ($_POST['opportunity_id'] ?? 0);

            if ($opportunityId <= 0) {
                throw new RuntimeException('Firsat secimi zorunludur.');
            }

            $stmt = $db->prepare('
                UPDATE crm_opportunities
                SET source_channel = :source_channel,
                    source_campaign = :source_campaign,
                    source_referrer = :source_referrer
                WHERE id = :id
            ');
            $stmt->execute([
                'source_channel' => trim((string) ($_POST['source_channel'] ?? '')) ?: null,
                'source_campaign' => trim((string) ($_POST['source_campaign'] ?? '')) ?: null,
                'source_referrer' => trim((string) ($_POST['source_referrer'] ?? '')) ?: null,
                'id' => $opportunityId,
            ]);

            app_redirect('index.php?module=crm&ok=source');
        }

        if ($action === 'update_customer_segment') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $segmentCode = trim((string) ($_POST['segment_code'] ?? ''));

            if ($cariId <= 0 || $segmentCode === '') {
                throw new RuntimeException('Cari ve segment secimi zorunludur.');
            }

            $stmt = $db->prepare('
                UPDATE cari_cards
                SET segment_code = :segment_code,
                    segment_note = :segment_note
                WHERE id = :id
            ');
            $stmt->execute([
                'segment_code' => $segmentCode,
                'segment_note' => trim((string) ($_POST['segment_note'] ?? '')) ?: null,
                'id' => $cariId,
            ]);

            app_redirect('index.php?module=crm&ok=segment');
        }

        if ($action === 'assign_customer_tag') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $tagName = trim((string) ($_POST['tag_name'] ?? ''));
            $tagColor = trim((string) ($_POST['tag_color'] ?? '')) ?: null;

            if ($cariId <= 0 || $tagName === '') {
                throw new RuntimeException('Cari ve etiket adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_tags (tag_name, tag_color)
                VALUES (:tag_name, :tag_color)
                ON DUPLICATE KEY UPDATE
                    tag_color = COALESCE(VALUES(tag_color), tag_color)
            ');
            $stmt->execute([
                'tag_name' => $tagName,
                'tag_color' => $tagColor,
            ]);

            $tagRows = app_fetch_all($db, 'SELECT id FROM crm_tags WHERE tag_name = :tag_name LIMIT 1', ['tag_name' => $tagName]);
            $tagId = (int) ($tagRows[0]['id'] ?? 0);

            if ($tagId <= 0) {
                throw new RuntimeException('Etiket kaydi olusturulamadi.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_cari_tags (cari_id, tag_id, tag_note, assigned_by)
                VALUES (:cari_id, :tag_id, :tag_note, :assigned_by)
                ON DUPLICATE KEY UPDATE
                    tag_note = VALUES(tag_note),
                    assigned_by = VALUES(assigned_by),
                    assigned_at = NOW()
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'tag_id' => $tagId,
                'tag_note' => trim((string) ($_POST['tag_note'] ?? '')) ?: null,
                'assigned_by' => trim((string) ($_POST['assigned_by'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&ok=tag');
        }

        if ($action === 'send_bulk_campaign') {
            $campaignName = trim((string) ($_POST['campaign_name'] ?? ''));
            $channel = trim((string) ($_POST['channel'] ?? 'email'));
            $subject = trim((string) ($_POST['subject_line'] ?? ''));
            $body = trim((string) ($_POST['message_body'] ?? ''));
            $targetSegment = trim((string) ($_POST['target_segment'] ?? ''));
            $targetTagId = (int) ($_POST['target_tag_id'] ?? 0);
            $targetSource = trim((string) ($_POST['target_source'] ?? ''));
            $plannedAt = trim((string) ($_POST['planned_at'] ?? ''));

            if ($campaignName === '' || !in_array($channel, ['email', 'sms'], true) || $body === '' || ($channel === 'email' && $subject === '')) {
                throw new RuntimeException('Kampanya adi, kanal, konu ve mesaj alanlari zorunludur.');
            }

            $plannedAtSql = $plannedAt !== '' ? str_replace('T', ' ', $plannedAt) . (strlen($plannedAt) === 16 ? ':00' : '') : date('Y-m-d H:i:s');

            $recipientSql = '
                SELECT DISTINCT c.id, c.company_name, c.full_name, c.email, c.phone, c.segment_code,
                       COALESCE(MAX(o.source_channel), "") AS source_channel
                FROM cari_cards c
                LEFT JOIN crm_opportunities o ON o.cari_id = c.id
                LEFT JOIN crm_cari_tags ct ON ct.cari_id = c.id
                WHERE c.status = 1
            ';
            $recipientParams = [];

            if ($targetSegment !== '') {
                $recipientSql .= ' AND c.segment_code = :target_segment';
                $recipientParams['target_segment'] = $targetSegment;
            }

            if ($targetTagId > 0) {
                $recipientSql .= ' AND ct.tag_id = :target_tag_id';
                $recipientParams['target_tag_id'] = $targetTagId;
            }

            if ($targetSource !== '') {
                $recipientSql .= ' AND o.source_channel = :target_source';
                $recipientParams['target_source'] = $targetSource;
            }

            $recipientSql .= $channel === 'email' ? ' AND c.email IS NOT NULL AND c.email <> ""' : ' AND c.phone IS NOT NULL AND c.phone <> ""';
            $recipientSql .= ' GROUP BY c.id, c.company_name, c.full_name, c.email, c.phone, c.segment_code ORDER BY c.id DESC LIMIT 500';
            $recipients = app_fetch_all($db, $recipientSql, $recipientParams);

            $stmt = $db->prepare('
                INSERT INTO crm_campaigns (
                    campaign_name, channel, subject_line, message_body, target_segment,
                    target_tag_id, target_source, planned_at, created_by
                ) VALUES (
                    :campaign_name, :channel, :subject_line, :message_body, :target_segment,
                    :target_tag_id, :target_source, :planned_at, :created_by
                )
            ');
            $stmt->execute([
                'campaign_name' => $campaignName,
                'channel' => $channel,
                'subject_line' => $subject ?: null,
                'message_body' => $body,
                'target_segment' => $targetSegment ?: null,
                'target_tag_id' => $targetTagId > 0 ? $targetTagId : null,
                'target_source' => $targetSource ?: null,
                'planned_at' => $plannedAtSql,
                'created_by' => trim((string) ($authUser['full_name'] ?? '')) ?: null,
            ]);
            $campaignId = (int) $db->lastInsertId();
            $queued = 0;
            $skipped = 0;

            foreach ($recipients as $recipient) {
                $contact = trim((string) ($channel === 'email' ? $recipient['email'] : $recipient['phone']));
                if ($contact === '') {
                    $skipped++;
                    continue;
                }

                $queuedOk = app_queue_notification($db, [
                    'module_name' => 'crm',
                    'notification_type' => 'bulk_campaign',
                    'source_table' => 'crm_campaigns',
                    'source_id' => $campaignId,
                    'channel' => $channel,
                    'recipient_name' => (string) ($recipient['company_name'] ?: $recipient['full_name'] ?: '-'),
                    'recipient_contact' => $contact,
                    'subject_line' => $channel === 'email' ? crm_campaign_render_message($subject, $recipient) : null,
                    'message_body' => crm_campaign_render_message($body, $recipient),
                    'status' => 'pending',
                    'planned_at' => $plannedAtSql,
                    'unique_key' => 'crm-campaign-' . $campaignId . '-' . $channel . '-' . (int) $recipient['id'],
                    'provider_name' => app_setting($db, 'notifications.' . $channel . '_mode', 'mock'),
                ]);

                $queuedOk ? $queued++ : $skipped++;
            }

            $stmt = $db->prepare('UPDATE crm_campaigns SET queued_count = :queued_count, skipped_count = :skipped_count WHERE id = :id');
            $stmt->execute([
                'queued_count' => $queued,
                'skipped_count' => $skipped,
                'id' => $campaignId,
            ]);

            app_redirect('index.php?module=crm&ok=campaign');
        }

        if ($action === 'save_email_template') {
            $templateName = trim((string) ($_POST['template_name'] ?? ''));
            $subject = trim((string) ($_POST['subject_line'] ?? ''));
            $body = trim((string) ($_POST['body_template'] ?? ''));

            if ($templateName === '' || $subject === '' || $body === '') {
                throw new RuntimeException('E-posta sablonu icin ad, konu ve govde zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_email_templates (
                    template_name, category_name, subject_line, body_template, status, created_by
                ) VALUES (
                    :template_name, :category_name, :subject_line, :body_template, :status, :created_by
                )
                ON DUPLICATE KEY UPDATE
                    category_name = VALUES(category_name),
                    subject_line = VALUES(subject_line),
                    body_template = VALUES(body_template),
                    status = VALUES(status),
                    created_by = VALUES(created_by)
            ');
            $stmt->execute([
                'template_name' => $templateName,
                'category_name' => trim((string) ($_POST['category_name'] ?? '')) ?: null,
                'subject_line' => $subject,
                'body_template' => $body,
                'status' => (int) ($_POST['status'] ?? 1),
                'created_by' => trim((string) ($authUser['full_name'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&ok=email_template');
        }

        if ($action === 'save_sms_template') {
            $templateName = trim((string) ($_POST['template_name'] ?? ''));
            $body = trim((string) ($_POST['body_template'] ?? ''));

            if ($templateName === '' || $body === '') {
                throw new RuntimeException('SMS sablonu icin ad ve mesaj zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO crm_sms_templates (
                    template_name, category_name, body_template, status, created_by
                ) VALUES (
                    :template_name, :category_name, :body_template, :status, :created_by
                )
                ON DUPLICATE KEY UPDATE
                    category_name = VALUES(category_name),
                    body_template = VALUES(body_template),
                    status = VALUES(status),
                    created_by = VALUES(created_by)
            ');
            $stmt->execute([
                'template_name' => $templateName,
                'category_name' => trim((string) ($_POST['category_name'] ?? '')) ?: null,
                'body_template' => $body,
                'status' => (int) ($_POST['status'] ?? 1),
                'created_by' => trim((string) ($authUser['full_name'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&ok=sms_template');
        }

        if ($action === 'prepare_whatsapp_message') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $manualPhone = trim((string) ($_POST['phone_number'] ?? ''));
            $body = trim((string) ($_POST['message_body'] ?? ''));

            if ($body === '') {
                throw new RuntimeException('WhatsApp mesaji zorunludur.');
            }

            $targetRows = $cariId > 0 ? app_fetch_all($db, '
                SELECT id, company_name, full_name, phone, email, segment_code
                FROM cari_cards
                WHERE id = :id
                LIMIT 1
            ', ['id' => $cariId]) : [];
            $target = $targetRows[0] ?? [
                'id' => null,
                'company_name' => '',
                'full_name' => '',
                'phone' => '',
                'email' => '',
                'segment_code' => '',
            ];

            $phone = $manualPhone !== '' ? $manualPhone : (string) ($target['phone'] ?? '');
            $normalizedPhone = crm_normalize_whatsapp_phone($phone);
            if ($normalizedPhone === '' || strlen($normalizedPhone) < 10) {
                throw new RuntimeException('WhatsApp icin gecerli telefon numarasi gerekli.');
            }

            $target['source_channel'] = '';
            $renderedBody = crm_campaign_render_message($body, $target);
            $whatsappUrl = 'https://wa.me/' . $normalizedPhone . '?text=' . rawurlencode($renderedBody);

            $stmt = $db->prepare('
                INSERT INTO crm_whatsapp_messages (
                    cari_id, phone_number, normalized_phone, message_body, whatsapp_url, status, created_by
                ) VALUES (
                    :cari_id, :phone_number, :normalized_phone, :message_body, :whatsapp_url, :status, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId > 0 ? $cariId : null,
                'phone_number' => $phone,
                'normalized_phone' => $normalizedPhone,
                'message_body' => $renderedBody,
                'whatsapp_url' => $whatsappUrl,
                'status' => 'hazirlandi',
                'created_by' => trim((string) ($authUser['full_name'] ?? '')) ?: null,
            ]);

            app_redirect('index.php?module=crm&view=whatsapp-entegrasyonu&ok=whatsapp_ready&wa=' . (int) $db->lastInsertId());
        }

        if ($action === 'create_crm_task') {
            $cariId = (int) ($_POST['cari_id'] ?? 0);
            $opportunityId = (int) ($_POST['opportunity_id'] ?? 0);
            $title = trim((string) ($_POST['task_title'] ?? ''));
            $priority = trim((string) ($_POST['priority'] ?? 'normal')) ?: 'normal';
            $status = trim((string) ($_POST['status'] ?? 'bekliyor')) ?: 'bekliyor';
            $allowedPriorities = ['dusuk', 'normal', 'yuksek', 'kritik'];
            $allowedStatuses = ['bekliyor', 'devam', 'tamamlandi', 'iptal'];

            if ($cariId <= 0 || $title === '' || !in_array($priority, $allowedPriorities, true) || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Gorev icin cari, baslik, oncelik ve durum zorunludur.');
            }

            if ($opportunityId > 0) {
                $rows = app_fetch_all($db, 'SELECT cari_id FROM crm_opportunities WHERE id = :id LIMIT 1', ['id' => $opportunityId]);
                if (!$rows || (int) $rows[0]['cari_id'] !== $cariId) {
                    throw new RuntimeException('Secilen firsat bu cariye ait degil.');
                }
            }

            $dueAt = trim((string) ($_POST['due_at'] ?? ''));
            $dueAtSql = $dueAt !== '' ? str_replace('T', ' ', $dueAt) . (strlen($dueAt) === 16 ? ':00' : '') : null;
            $assignedUserId = (int) ($_POST['assigned_user_id'] ?? 0);

            $stmt = $db->prepare('
                INSERT INTO crm_tasks (
                    cari_id, opportunity_id, task_title, task_description, assigned_user_id,
                    assigned_name, priority, status, due_at, completed_at, created_by
                ) VALUES (
                    :cari_id, :opportunity_id, :task_title, :task_description, :assigned_user_id,
                    :assigned_name, :priority, :status, :due_at, :completed_at, :created_by
                )
            ');
            $stmt->execute([
                'cari_id' => $cariId,
                'opportunity_id' => $opportunityId > 0 ? $opportunityId : null,
                'task_title' => $title,
                'task_description' => trim((string) ($_POST['task_description'] ?? '')) ?: null,
                'assigned_user_id' => $assignedUserId > 0 ? $assignedUserId : null,
                'assigned_name' => trim((string) ($_POST['assigned_name'] ?? '')) ?: null,
                'priority' => $priority,
                'status' => $status,
                'due_at' => $dueAtSql,
                'completed_at' => $status === 'tamamlandi' ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) ($authUser['id'] ?? 1),
            ]);

            app_redirect('index.php?module=crm&ok=task');
        }

        if ($action === 'bulk_update_tasks') {
            $taskIds = crm_selected_ids('task_ids');
            $status = trim((string) ($_POST['bulk_task_status'] ?? ''));
            $allowedStatuses = ['bekliyor', 'devam', 'tamamlandi', 'iptal'];

            if ($taskIds === [] || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Gorev secimi veya durum gecersiz.');
            }

            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $params = $status === 'tamamlandi' ? array_merge([$status, date('Y-m-d H:i:s')], $taskIds) : array_merge([$status], $taskIds);
            $sql = $status === 'tamamlandi'
                ? "UPDATE crm_tasks SET status = ?, completed_at = ? WHERE id IN ({$placeholders})"
                : "UPDATE crm_tasks SET status = ?, completed_at = NULL WHERE id IN ({$placeholders})";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            app_redirect('index.php?module=crm&ok=task_bulk');
        }

        if ($action === 'bulk_update_reminders') {
            $reminderIds = crm_selected_ids('reminder_ids');
            $status = trim((string) ($_POST['bulk_reminder_status'] ?? ''));

            if ($reminderIds === [] || $status === '') {
                throw new RuntimeException('Hatirlatma secimi veya durum gecersiz.');
            }

            $placeholders = implode(',', array_fill(0, count($reminderIds), '?'));
            $params = array_merge([$status], $reminderIds);
            $stmt = $db->prepare("UPDATE crm_reminders SET status = ? WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            app_redirect('index.php?module=crm&ok=reminder_bulk');
        }

        if ($action === 'bulk_update_opportunities') {
            $opportunityIds = crm_selected_ids('opportunity_ids');
            $stage = trim((string) ($_POST['bulk_opportunity_stage'] ?? ''));

            if ($opportunityIds === [] || $stage === '') {
                throw new RuntimeException('Firsat secimi veya asama gecersiz.');
            }

            $placeholders = implode(',', array_fill(0, count($opportunityIds), '?'));
            $params = array_merge([$stage], $opportunityIds);
            $stmt = $db->prepare("UPDATE crm_opportunities SET stage = ? WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            app_redirect('index.php?module=crm&ok=opportunity_bulk');
        }
    } catch (Throwable $e) {
        $feedback = 'error:CRM islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = crm_build_filters();

$cariCards = app_fetch_all($db, 'SELECT id, company_name, full_name, phone FROM cari_cards ORDER BY id DESC LIMIT 100');
$userOptions = app_fetch_all($db, 'SELECT id, full_name, email FROM core_users WHERE status = 1 ORDER BY full_name ASC LIMIT 100');
$baseSearchSql = $filters['search'] !== '' ? ' AND (c.company_name LIKE :search OR c.full_name LIKE :search)' : '';
$timelineCariId = (int) $filters['timeline_cari_id'];
$timelineCariRows = $timelineCariId > 0 ? app_fetch_all($db, '
    SELECT id, company_name, full_name, phone, email
    FROM cari_cards
    WHERE id = :id
    LIMIT 1
', ['id' => $timelineCariId]) : [];
$timelineCari = $timelineCariRows[0] ?? null;
$timelineRows = [];

if ($timelineCariId > 0) {
    $timelineRows = app_fetch_all($db, "
        SELECT * FROM (
            SELECT 'CRM Notu' AS event_type, n.id AS record_id, n.created_at AS event_date,
                   'Not' AS status_text, n.note_text AS detail_text, 0.00 AS amount, 'crm_notes' AS source_table
            FROM crm_notes n
            WHERE n.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Aktivite' AS event_type, a.id AS record_id, a.activity_at AS event_date,
                   a.activity_type AS status_text, CONCAT(a.activity_subject, ' / ', COALESCE(a.activity_result, '-')) AS detail_text, 0.00 AS amount, 'crm_activities' AS source_table
            FROM crm_activities a
            WHERE a.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Arama Kaydi' AS event_type, cl.id AS record_id, cl.call_at AS event_date,
                   CONCAT(cl.call_direction, ' / ', cl.call_result) AS status_text,
                   CONCAT(cl.call_subject, ' / ', COALESCE(cl.phone_number, '-')) AS detail_text,
                   0.00 AS amount, 'crm_call_logs' AS source_table
            FROM crm_call_logs cl
            WHERE cl.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Gorusme Notu' AS event_type, mn.id AS record_id, mn.meeting_at AS event_date,
                   mn.meeting_type AS status_text,
                   CONCAT(mn.meeting_subject, ' / ', COALESCE(mn.decisions, '-')) AS detail_text,
                   0.00 AS amount, 'crm_meeting_notes' AS source_table
            FROM crm_meeting_notes mn
            WHERE mn.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Gorev' AS event_type, t.id AS record_id, COALESCE(t.due_at, t.created_at) AS event_date,
                   CONCAT(t.priority, ' / ', t.status) AS status_text,
                   CONCAT(t.task_title, ' / ', COALESCE(t.assigned_name, '-')) AS detail_text,
                   0.00 AS amount, 'crm_tasks' AS source_table
            FROM crm_tasks t
            WHERE t.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Hatirlatma' AS event_type, r.id AS record_id, r.remind_at AS event_date,
                   r.status AS status_text, r.reminder_text AS detail_text, 0.00 AS amount, 'crm_reminders' AS source_table
            FROM crm_reminders r
            WHERE r.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Firsat' AS event_type, o.id AS record_id, o.created_at AS event_date,
                   o.stage AS status_text, o.title AS detail_text, o.amount AS amount, 'crm_opportunities' AS source_table
            FROM crm_opportunities o
            WHERE o.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Teklif-Firsat' AS event_type, l.id AS record_id, l.linked_at AS event_date,
                   l.relation_status AS status_text,
                   CONCAT(o.title, ' / ', so.offer_no) AS detail_text,
                   so.grand_total AS amount, 'crm_opportunity_offer_links' AS source_table
            FROM crm_opportunity_offer_links l
            INNER JOIN crm_opportunities o ON o.id = l.opportunity_id
            INNER JOIN sales_offers so ON so.id = l.offer_id
            WHERE o.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Teklif' AS event_type, so.id AS record_id, so.offer_date AS event_date,
                   so.status AS status_text, so.offer_no AS detail_text, so.grand_total AS amount, 'sales_offers' AS source_table
            FROM sales_offers so
            WHERE so.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Siparis' AS event_type, ord.id AS record_id, ord.order_date AS event_date,
                   ord.status AS status_text, ord.order_no AS detail_text, ord.grand_total AS amount, 'sales_orders' AS source_table
            FROM sales_orders ord
            WHERE ord.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Fatura' AS event_type, ih.id AS record_id, ih.invoice_date AS event_date,
                   ih.payment_status AS status_text, ih.invoice_no AS detail_text, ih.grand_total AS amount, 'invoice_headers' AS source_table
            FROM invoice_headers ih
            WHERE ih.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Tahsilat' AS event_type, ct.id AS record_id, COALESCE(ct.processed_at, ct.created_at) AS event_date,
                   ct.status AS status_text, COALESCE(ct.transaction_ref, CONCAT('POS#', ct.id)) AS detail_text, ct.amount AS amount, 'collections_transactions' AS source_table
            FROM collections_transactions ct
            WHERE ct.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Kira' AS event_type, rc.id AS record_id, rc.start_date AS event_date,
                   rc.status AS status_text, rc.contract_no AS detail_text, rc.monthly_rent AS amount, 'rental_contracts' AS source_table
            FROM rental_contracts rc
            WHERE rc.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Servis' AS event_type, sr.id AS record_id, sr.opened_at AS event_date,
                   COALESCE(sr.sla_status, 'servis') AS status_text, sr.service_no AS detail_text, sr.cost_total AS amount, 'service_records' AS source_table
            FROM service_records sr
            WHERE sr.cari_id = {$timelineCariId}
            UNION ALL
            SELECT 'Cari Hareket' AS event_type, cm.id AS record_id, cm.movement_date AS event_date,
                   cm.movement_type AS status_text, COALESCE(cm.description, cm.source_module) AS detail_text, cm.amount AS amount, 'cari_movements' AS source_table
            FROM cari_movements cm
            WHERE cm.cari_id = {$timelineCariId}
        ) timeline
        ORDER BY event_date DESC
        LIMIT 150
    ");
}

$notes = app_fetch_all($db, '
    SELECT n.note_text, n.created_at, c.company_name, c.full_name, u.full_name AS created_by_name
    FROM crm_notes n
    INNER JOIN cari_cards c ON c.id = n.cari_id
    LEFT JOIN core_users u ON u.id = n.created_by
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND n.note_text LIKE :note_search' : '') . '
    ORDER BY n.id DESC
    LIMIT 50
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'note_search' => '%' . $filters['search'] . '%'] : []);
$activities = app_fetch_all($db, '
    SELECT a.id, a.activity_type, a.activity_subject, a.activity_result, a.activity_at,
           a.next_action_at, a.responsible_name, a.notes, c.company_name, c.full_name
    FROM crm_activities a
    INNER JOIN cari_cards c ON c.id = a.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (a.activity_subject LIKE :activity_search OR a.activity_result LIKE :activity_search OR a.notes LIKE :activity_search)' : '') . '
    ORDER BY a.activity_at DESC, a.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'activity_search' => '%' . $filters['search'] . '%'] : []);
$activityTypeSummary = app_fetch_all($db, '
    SELECT activity_type, COUNT(*) AS activity_count, MAX(activity_at) AS last_activity
    FROM crm_activities
    GROUP BY activity_type
    ORDER BY activity_count DESC, activity_type ASC
');
$callLogs = app_fetch_all($db, '
    SELECT cl.id, cl.call_direction, cl.call_subject, cl.call_result, cl.call_at,
           cl.duration_seconds, cl.callback_at, cl.phone_number, cl.responsible_name, cl.notes,
           c.company_name, c.full_name
    FROM crm_call_logs cl
    INNER JOIN cari_cards c ON c.id = cl.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (cl.call_subject LIKE :call_search OR cl.phone_number LIKE :call_search OR cl.notes LIKE :call_search)' : '') . '
    ORDER BY cl.call_at DESC, cl.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'call_search' => '%' . $filters['search'] . '%'] : []);
$callResultSummary = app_fetch_all($db, '
    SELECT call_result, COUNT(*) AS call_count, COALESCE(SUM(duration_seconds), 0) AS total_seconds
    FROM crm_call_logs
    GROUP BY call_result
    ORDER BY call_count DESC, call_result ASC
');
$meetingNotes = app_fetch_all($db, '
    SELECT mn.id, mn.meeting_type, mn.meeting_subject, mn.meeting_at, mn.participants,
           mn.decisions, mn.action_items, mn.follow_up_at, mn.responsible_name, mn.notes,
           c.company_name, c.full_name
    FROM crm_meeting_notes mn
    INNER JOIN cari_cards c ON c.id = mn.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (mn.meeting_subject LIKE :meeting_search OR mn.decisions LIKE :meeting_search OR mn.action_items LIKE :meeting_search OR mn.notes LIKE :meeting_search)' : '') . '
    ORDER BY mn.meeting_at DESC, mn.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'meeting_search' => '%' . $filters['search'] . '%'] : []);
$meetingTypeSummary = app_fetch_all($db, '
    SELECT meeting_type, COUNT(*) AS meeting_count, MAX(meeting_at) AS last_meeting
    FROM crm_meeting_notes
    GROUP BY meeting_type
    ORDER BY meeting_count DESC, meeting_type ASC
');
$reminders = app_fetch_all($db, '
    SELECT r.id, r.reminder_text, r.remind_at, r.status, r.created_at, c.company_name, c.full_name, u.full_name AS created_by_name
    FROM crm_reminders r
    INNER JOIN cari_cards c ON c.id = r.cari_id
    LEFT JOIN core_users u ON u.id = r.created_by
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND r.reminder_text LIKE :reminder_search' : '') . ($filters['reminder_status'] !== '' ? ' AND r.status = :reminder_status' : '') . '
    ORDER BY r.id DESC
    LIMIT 50
', array_filter([
    'search' => $filters['search'] !== '' ? '%' . $filters['search'] . '%' : null,
    'reminder_search' => $filters['search'] !== '' ? '%' . $filters['search'] . '%' : null,
    'reminder_status' => $filters['reminder_status'] !== '' ? $filters['reminder_status'] : null,
], static fn($value) => $value !== null));
$opportunities = app_fetch_all($db, '
    SELECT o.id, o.title, o.stage, o.amount, o.probability_score, o.probability_note,
           o.source_channel, o.source_campaign, o.source_referrer,
           ROUND(o.amount * o.probability_score / 100, 2) AS weighted_amount,
           o.expected_close_date, o.created_at, c.company_name, c.full_name
    FROM crm_opportunities o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND o.title LIKE :opportunity_search' : '') . ($filters['opportunity_stage'] !== '' ? ' AND o.stage = :opportunity_stage' : '') . '
    ORDER BY o.id DESC
    LIMIT 50
', array_filter([
    'search' => $filters['search'] !== '' ? '%' . $filters['search'] . '%' : null,
    'opportunity_search' => $filters['search'] !== '' ? '%' . $filters['search'] . '%' : null,
    'opportunity_stage' => $filters['opportunity_stage'] !== '' ? $filters['opportunity_stage'] : null,
], static fn($value) => $value !== null));
$pipelineStages = ['Yeni', 'Gorusme', 'Teklif Verildi', 'Kazanildi', 'Kaybedildi'];
$pipelineRows = app_fetch_all($db, '
    SELECT o.id, o.title, o.stage, o.amount, o.probability_score, o.probability_note,
           o.source_channel, o.source_campaign, o.source_referrer,
           ROUND(o.amount * o.probability_score / 100, 2) AS weighted_amount,
           o.expected_close_date, o.created_at,
           c.company_name, c.full_name,
           COUNT(l.id) AS linked_offer_count,
           COALESCE(SUM(so.grand_total), 0) AS linked_offer_total
    FROM crm_opportunities o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    LEFT JOIN crm_opportunity_offer_links l ON l.opportunity_id = o.id
    LEFT JOIN sales_offers so ON so.id = l.offer_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND o.title LIKE :pipeline_search' : '') . '
    GROUP BY o.id, o.title, o.stage, o.amount, o.probability_score, o.probability_note, o.source_channel, o.source_campaign, o.source_referrer, o.expected_close_date, o.created_at, c.company_name, c.full_name
    ORDER BY FIELD(o.stage, "Yeni", "Gorusme", "Teklif Verildi", "Kazanildi", "Kaybedildi"), o.expected_close_date IS NULL, o.expected_close_date ASC, o.id DESC
    LIMIT 300
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'pipeline_search' => '%' . $filters['search'] . '%'] : []);
$pipeline = [];

foreach ($pipelineStages as $stage) {
    $pipeline[$stage] = [
        'rows' => [],
        'count' => 0,
        'amount' => 0.0,
        'weighted_amount' => 0.0,
        'offer_total' => 0.0,
    ];
}

foreach ($pipelineRows as $row) {
    $stage = (string) ($row['stage'] ?: 'Diger');

    if (!isset($pipeline[$stage])) {
        $pipeline[$stage] = [
            'rows' => [],
            'count' => 0,
            'amount' => 0.0,
            'weighted_amount' => 0.0,
            'offer_total' => 0.0,
        ];
    }

    $pipeline[$stage]['rows'][] = $row;
    $pipeline[$stage]['count']++;
    $pipeline[$stage]['amount'] += (float) $row['amount'];
    $pipeline[$stage]['weighted_amount'] += (float) $row['weighted_amount'];
    $pipeline[$stage]['offer_total'] += (float) $row['linked_offer_total'];
}

$opportunityOptions = app_fetch_all($db, '
    SELECT o.id, o.title, o.stage, o.amount, o.probability_score, o.source_channel, c.company_name, c.full_name
    FROM crm_opportunities o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    ORDER BY o.id DESC
    LIMIT 200
');
$offerOptions = app_fetch_all($db, '
    SELECT so.id, so.offer_no, so.status, so.grand_total, c.company_name, c.full_name
    FROM sales_offers so
    INNER JOIN cari_cards c ON c.id = so.cari_id
    ORDER BY so.id DESC
    LIMIT 200
');
$offerOpportunityLinks = app_fetch_all($db, '
    SELECT l.id, l.relation_status, l.relation_note, l.linked_by, l.linked_at,
           o.title AS opportunity_title, o.stage AS opportunity_stage,
           so.offer_no, so.status AS offer_status, so.grand_total,
           c.company_name, c.full_name
    FROM crm_opportunity_offer_links l
    INNER JOIN crm_opportunities o ON o.id = l.opportunity_id
    INNER JOIN sales_offers so ON so.id = l.offer_id
    INNER JOIN cari_cards c ON c.id = o.cari_id
    WHERE 1=1' . ($filters['search'] !== '' ? ' AND (c.company_name LIKE :link_search OR c.full_name LIKE :link_search OR o.title LIKE :link_search OR so.offer_no LIKE :link_search OR l.relation_note LIKE :link_search)' : '') . '
    ORDER BY l.linked_at DESC, l.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['link_search' => '%' . $filters['search'] . '%'] : []);
$offerOpportunitySummary = app_fetch_all($db, '
    SELECT l.relation_status, COUNT(*) AS link_count, COALESCE(SUM(so.grand_total), 0) AS offer_total
    FROM crm_opportunity_offer_links l
    INNER JOIN sales_offers so ON so.id = l.offer_id
    GROUP BY l.relation_status
    ORDER BY link_count DESC, l.relation_status ASC
');
$probabilityRows = app_fetch_all($db, '
    SELECT o.id, o.title, o.stage, o.amount, o.probability_score, o.probability_note,
           ROUND(o.amount * o.probability_score / 100, 2) AS weighted_amount,
           o.expected_close_date, c.company_name, c.full_name
    FROM crm_opportunities o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (o.title LIKE :probability_search OR o.probability_note LIKE :probability_search)' : '') . '
    ORDER BY o.probability_score DESC, weighted_amount DESC, o.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'probability_search' => '%' . $filters['search'] . '%'] : []);
$sourceRows = app_fetch_all($db, '
    SELECT o.id, o.title, o.stage, o.amount, o.probability_score,
           o.source_channel, o.source_campaign, o.source_referrer,
           ROUND(o.amount * o.probability_score / 100, 2) AS weighted_amount,
           o.expected_close_date, c.company_name, c.full_name
    FROM crm_opportunities o
    INNER JOIN cari_cards c ON c.id = o.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (o.title LIKE :source_search OR o.source_channel LIKE :source_search OR o.source_campaign LIKE :source_search OR o.source_referrer LIKE :source_search)' : '') . '
    ORDER BY COALESCE(o.source_channel, "Kaynak Yok") ASC, weighted_amount DESC, o.id DESC
    LIMIT 100
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'source_search' => '%' . $filters['search'] . '%'] : []);
$sourceSummary = app_fetch_all($db, '
    SELECT COALESCE(NULLIF(source_channel, ""), "Kaynak Yok") AS source_channel,
           COUNT(*) AS opportunity_count,
           COALESCE(SUM(amount), 0) AS amount_total,
           COALESCE(SUM(amount * probability_score / 100), 0) AS weighted_total,
           AVG(probability_score) AS avg_probability
    FROM crm_opportunities
    GROUP BY COALESCE(NULLIF(source_channel, ""), "Kaynak Yok")
    ORDER BY weighted_total DESC, opportunity_count DESC, source_channel ASC
');
$segmentRows = app_fetch_all($db, '
    SELECT c.id, c.company_name, c.full_name, c.phone, c.email, c.segment_code, c.segment_note,
           (SELECT COUNT(*) FROM crm_opportunities o WHERE o.cari_id = c.id) AS opportunity_count,
           (SELECT COALESCE(SUM(o.amount), 0) FROM crm_opportunities o WHERE o.cari_id = c.id) AS opportunity_total,
           (SELECT COALESCE(SUM(o.amount * o.probability_score / 100), 0) FROM crm_opportunities o WHERE o.cari_id = c.id) AS weighted_total,
           (SELECT COUNT(*) FROM crm_reminders r WHERE r.cari_id = c.id) AS reminder_count,
           (SELECT COUNT(*) FROM crm_notes n WHERE n.cari_id = c.id) AS note_count
    FROM cari_cards c
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (c.segment_code LIKE :segment_search OR c.segment_note LIKE :segment_search)' : '') . '
    ORDER BY COALESCE(c.segment_code, "Segmentsiz") ASC, weighted_total DESC, c.id DESC
    LIMIT 100
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'segment_search' => '%' . $filters['search'] . '%'] : []);
$segmentSummary = app_fetch_all($db, '
    SELECT COALESCE(NULLIF(c.segment_code, ""), "Segmentsiz") AS segment_code,
           COUNT(DISTINCT c.id) AS customer_count,
           COUNT(DISTINCT o.id) AS opportunity_count,
           COALESCE(SUM(o.amount), 0) AS opportunity_total,
           COALESCE(SUM(o.amount * o.probability_score / 100), 0) AS weighted_total
    FROM cari_cards c
    LEFT JOIN crm_opportunities o ON o.cari_id = c.id
    GROUP BY COALESCE(NULLIF(c.segment_code, ""), "Segmentsiz")
    ORDER BY weighted_total DESC, customer_count DESC, segment_code ASC
');
$tagOptions = app_fetch_all($db, '
    SELECT id, tag_name, tag_color
    FROM crm_tags
    ORDER BY tag_name ASC
    LIMIT 200
');
$tagRows = app_fetch_all($db, '
    SELECT ct.id, ct.tag_note, ct.assigned_by, ct.assigned_at,
           t.tag_name, t.tag_color,
           c.id AS cari_id, c.company_name, c.full_name, c.phone, c.email, c.segment_code,
           (SELECT COUNT(*) FROM crm_opportunities o WHERE o.cari_id = c.id) AS opportunity_count,
           (SELECT COALESCE(SUM(o.amount * o.probability_score / 100), 0) FROM crm_opportunities o WHERE o.cari_id = c.id) AS weighted_total
    FROM crm_cari_tags ct
    INNER JOIN crm_tags t ON t.id = ct.tag_id
    INNER JOIN cari_cards c ON c.id = ct.cari_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (t.tag_name LIKE :tag_search OR ct.tag_note LIKE :tag_search OR c.segment_code LIKE :tag_search)' : '') . '
    ORDER BY t.tag_name ASC, ct.assigned_at DESC, ct.id DESC
    LIMIT 120
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'tag_search' => '%' . $filters['search'] . '%'] : []);
$tagSummary = app_fetch_all($db, '
    SELECT t.tag_name, t.tag_color,
           COUNT(DISTINCT ct.cari_id) AS customer_count,
           COUNT(DISTINCT o.id) AS opportunity_count,
           COALESCE(SUM(o.amount * o.probability_score / 100), 0) AS weighted_total
    FROM crm_tags t
    LEFT JOIN crm_cari_tags ct ON ct.tag_id = t.id
    LEFT JOIN crm_opportunities o ON o.cari_id = ct.cari_id
    GROUP BY t.id, t.tag_name, t.tag_color
    ORDER BY customer_count DESC, weighted_total DESC, t.tag_name ASC
');
$taskRows = app_fetch_all($db, '
    SELECT t.id, t.task_title, t.task_description, t.assigned_name, t.priority, t.status,
           t.due_at, t.completed_at, t.created_at,
           c.company_name, c.full_name,
           o.title AS opportunity_title,
           u.full_name AS assigned_user_name
    FROM crm_tasks t
    INNER JOIN cari_cards c ON c.id = t.cari_id
    LEFT JOIN crm_opportunities o ON o.id = t.opportunity_id
    LEFT JOIN core_users u ON u.id = t.assigned_user_id
    WHERE 1=1' . $baseSearchSql . ($filters['search'] !== '' ? ' AND (t.task_title LIKE :task_search OR t.task_description LIKE :task_search OR t.assigned_name LIKE :task_search OR o.title LIKE :task_search)' : '') . '
    ORDER BY FIELD(t.status, "bekliyor", "devam", "tamamlandi", "iptal"), t.due_at IS NULL, t.due_at ASC, FIELD(t.priority, "kritik", "yuksek", "normal", "dusuk"), t.id DESC
    LIMIT 120
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%', 'task_search' => '%' . $filters['search'] . '%'] : []);
$taskStatusSummary = app_fetch_all($db, '
    SELECT status, COUNT(*) AS task_count, MIN(due_at) AS nearest_due
    FROM crm_tasks
    GROUP BY status
    ORDER BY FIELD(status, "bekliyor", "devam", "tamamlandi", "iptal")
');
$taskPrioritySummary = app_fetch_all($db, '
    SELECT priority, COUNT(*) AS task_count,
           SUM(CASE WHEN status <> "tamamlandi" AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS overdue_count
    FROM crm_tasks
    GROUP BY priority
    ORDER BY FIELD(priority, "kritik", "yuksek", "normal", "dusuk")
');
$calendarRows = app_fetch_all($db, '
    SELECT * FROM (
        SELECT "Gorev" AS calendar_type, t.id AS record_id, t.due_at AS event_at,
               t.status AS status_text, t.priority AS priority_text,
               t.task_title AS title_text, t.task_description AS detail_text,
               c.company_name, c.full_name
        FROM crm_tasks t
        INNER JOIN cari_cards c ON c.id = t.cari_id
        WHERE t.due_at IS NOT NULL
        UNION ALL
        SELECT "Hatirlatma" AS calendar_type, r.id AS record_id, r.remind_at AS event_at,
               r.status AS status_text, "normal" AS priority_text,
               r.reminder_text AS title_text, NULL AS detail_text,
               c.company_name, c.full_name
        FROM crm_reminders r
        INNER JOIN cari_cards c ON c.id = r.cari_id
        UNION ALL
        SELECT "Geri Arama" AS calendar_type, cl.id AS record_id, cl.callback_at AS event_at,
               cl.call_result AS status_text, "normal" AS priority_text,
               cl.call_subject AS title_text, cl.notes AS detail_text,
               c.company_name, c.full_name
        FROM crm_call_logs cl
        INNER JOIN cari_cards c ON c.id = cl.cari_id
        WHERE cl.callback_at IS NOT NULL
        UNION ALL
        SELECT "Gorusme Takip" AS calendar_type, mn.id AS record_id, mn.follow_up_at AS event_at,
               mn.meeting_type AS status_text, "normal" AS priority_text,
               mn.meeting_subject AS title_text, mn.action_items AS detail_text,
               c.company_name, c.full_name
        FROM crm_meeting_notes mn
        INNER JOIN cari_cards c ON c.id = mn.cari_id
        WHERE mn.follow_up_at IS NOT NULL
        UNION ALL
        SELECT "Firsat Kapanis" AS calendar_type, o.id AS record_id, CONCAT(o.expected_close_date, " 09:00:00") AS event_at,
               o.stage AS status_text, "normal" AS priority_text,
               o.title AS title_text, o.probability_note AS detail_text,
               c.company_name, c.full_name
        FROM crm_opportunities o
        INNER JOIN cari_cards c ON c.id = o.cari_id
        WHERE o.expected_close_date IS NOT NULL
    ) calendar_items
    WHERE event_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND DATE_ADD(NOW(), INTERVAL 90 DAY)
    ORDER BY DATE(event_at) ASC, event_at ASC, FIELD(priority_text, "kritik", "yuksek", "normal", "dusuk")
    LIMIT 300
');
$calendarSummary = app_fetch_all($db, '
    SELECT calendar_type, COUNT(*) AS event_count, MIN(event_at) AS nearest_event
    FROM (
        SELECT "Gorev" AS calendar_type, due_at AS event_at FROM crm_tasks WHERE due_at IS NOT NULL
        UNION ALL
        SELECT "Hatirlatma" AS calendar_type, remind_at AS event_at FROM crm_reminders
        UNION ALL
        SELECT "Geri Arama" AS calendar_type, callback_at AS event_at FROM crm_call_logs WHERE callback_at IS NOT NULL
        UNION ALL
        SELECT "Gorusme Takip" AS calendar_type, follow_up_at AS event_at FROM crm_meeting_notes WHERE follow_up_at IS NOT NULL
        UNION ALL
        SELECT "Firsat Kapanis" AS calendar_type, CONCAT(expected_close_date, " 09:00:00") AS event_at FROM crm_opportunities WHERE expected_close_date IS NOT NULL
    ) calendar_items
    WHERE event_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND DATE_ADD(NOW(), INTERVAL 90 DAY)
    GROUP BY calendar_type
    ORDER BY nearest_event ASC
');
$campaignRows = app_fetch_all($db, '
    SELECT c.id, c.campaign_name, c.channel, c.subject_line, c.target_segment, c.target_source,
           c.planned_at, c.queued_count, c.skipped_count, c.created_by, c.created_at,
           t.tag_name
    FROM crm_campaigns c
    LEFT JOIN crm_tags t ON t.id = c.target_tag_id
    WHERE 1=1' . ($filters['search'] !== '' ? ' AND (c.campaign_name LIKE :campaign_search OR c.subject_line LIKE :campaign_search OR c.message_body LIKE :campaign_search)' : '') . '
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 80
', $filters['search'] !== '' ? ['campaign_search' => '%' . $filters['search'] . '%'] : []);
$campaignSummary = app_fetch_all($db, '
    SELECT channel, COUNT(*) AS campaign_count, COALESCE(SUM(queued_count), 0) AS queued_total, COALESCE(SUM(skipped_count), 0) AS skipped_total
    FROM crm_campaigns
    GROUP BY channel
    ORDER BY campaign_count DESC, channel ASC
');
$emailTemplates = app_fetch_all($db, '
    SELECT id, template_name, category_name, subject_line, body_template, status, created_by, created_at, updated_at
    FROM crm_email_templates
    ORDER BY status DESC, category_name ASC, template_name ASC
    LIMIT 120
');
$activeEmailTemplates = array_values(array_filter($emailTemplates, static fn(array $template): bool => (int) $template['status'] === 1));
$smsTemplates = app_fetch_all($db, '
    SELECT id, template_name, category_name, body_template, status, created_by, created_at, updated_at
    FROM crm_sms_templates
    ORDER BY status DESC, category_name ASC, template_name ASC
    LIMIT 120
');
$activeSmsTemplates = array_values(array_filter($smsTemplates, static fn(array $template): bool => (int) $template['status'] === 1));
$preparedWhatsappId = (int) ($_GET['wa'] ?? 0);
$preparedWhatsappRows = $preparedWhatsappId > 0 ? app_fetch_all($db, '
    SELECT wm.id, wm.phone_number, wm.normalized_phone, wm.message_body, wm.whatsapp_url, wm.status, wm.created_at,
           c.company_name, c.full_name
    FROM crm_whatsapp_messages wm
    LEFT JOIN cari_cards c ON c.id = wm.cari_id
    WHERE wm.id = :id
    LIMIT 1
', ['id' => $preparedWhatsappId]) : [];
$preparedWhatsapp = $preparedWhatsappRows[0] ?? null;
$whatsappMessages = app_fetch_all($db, '
    SELECT wm.id, wm.phone_number, wm.normalized_phone, wm.message_body, wm.whatsapp_url, wm.status, wm.created_by, wm.created_at,
           c.company_name, c.full_name
    FROM crm_whatsapp_messages wm
    LEFT JOIN cari_cards c ON c.id = wm.cari_id
    ORDER BY wm.created_at DESC, wm.id DESC
    LIMIT 80
');
$customerTagMap = [];

foreach ($tagRows as $tagRow) {
    $customerTagMap[(int) $tagRow['cari_id']][] = $tagRow;
}

$probabilitySummary = app_fetch_all($db, '
    SELECT
        CASE
            WHEN probability_score >= 80 THEN "Yuksek"
            WHEN probability_score >= 50 THEN "Orta"
            WHEN probability_score >= 20 THEN "Dusuk"
            ELSE "Belirsiz"
        END AS probability_group,
        COUNT(*) AS opportunity_count,
        COALESCE(SUM(amount), 0) AS amount_total,
        COALESCE(SUM(amount * probability_score / 100), 0) AS weighted_total
    FROM crm_opportunities
    GROUP BY probability_group
    ORDER BY FIELD(probability_group, "Yuksek", "Orta", "Dusuk", "Belirsiz")
');
$crmCustomers = app_fetch_all($db, '
    SELECT
        c.id,
        c.company_name,
        c.full_name,
        c.phone,
        c.email,
        c.segment_code,
        c.segment_note,
        (SELECT COUNT(*) FROM crm_notes n WHERE n.cari_id = c.id) AS note_count,
        (SELECT COUNT(*) FROM crm_reminders r WHERE r.cari_id = c.id) AS reminder_count,
        (SELECT COUNT(*) FROM crm_opportunities o WHERE o.cari_id = c.id) AS opportunity_count
    FROM cari_cards c
    WHERE 1=1' . $baseSearchSql . '
    ORDER BY c.id DESC
    LIMIT 50
', $filters['search'] !== '' ? ['search' => '%' . $filters['search'] . '%'] : []);

$crmCustomers = app_sort_rows($crmCustomers, $filters['customer_sort'], [
    'id_desc' => ['id', 'desc'],
    'name_asc' => ['company_name', 'asc'],
    'note_desc' => ['note_count', 'desc'],
    'reminder_desc' => ['reminder_count', 'desc'],
    'opportunity_desc' => ['opportunity_count', 'desc'],
]);
$reminders = app_sort_rows($reminders, $filters['reminder_sort'], [
    'date_desc' => ['remind_at', 'desc'],
    'date_asc' => ['remind_at', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$crmCustomersPagination = app_paginate_rows($crmCustomers, $filters['customer_page'], 10);
$crmRemindersPagination = app_paginate_rows($reminders, $filters['reminder_page'], 10);
$crmCustomers = $crmCustomersPagination['items'];
$reminders = $crmRemindersPagination['items'];

$summary = [
    'Aktivite' => app_table_count($db, 'crm_activities'),
    'Arama Kaydi' => app_table_count($db, 'crm_call_logs'),
    'Gorusme Notu' => app_table_count($db, 'crm_meeting_notes'),
    'CRM Notu' => app_table_count($db, 'crm_notes'),
    'Hatirlatma' => app_table_count($db, 'crm_reminders'),
    'Bekleyen Hatirlatma' => app_metric($db, "SELECT COUNT(*) FROM crm_reminders WHERE status = 'bekliyor'"),
    'Firsat' => app_table_count($db, 'crm_opportunities'),
    'Firsat Toplami' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(amount),0) FROM crm_opportunities'), 2, ',', '.'),
    'Acik Pipeline' => number_format((float) app_metric($db, "SELECT COALESCE(SUM(amount),0) FROM crm_opportunities WHERE stage NOT IN ('Kazanildi','Kaybedildi')"), 2, ',', '.'),
    'Agirlikli Firsat' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(amount * probability_score / 100),0) FROM crm_opportunities'), 2, ',', '.'),
    'Kaynakli Firsat' => app_metric($db, 'SELECT COUNT(*) FROM crm_opportunities WHERE source_channel IS NOT NULL AND source_channel <> ""'),
    'Segmentli Cari' => app_metric($db, 'SELECT COUNT(*) FROM cari_cards WHERE segment_code IS NOT NULL AND segment_code <> ""'),
    'Etiketli Cari' => app_metric($db, 'SELECT COUNT(DISTINCT cari_id) FROM crm_cari_tags'),
    'Kampanya Kuyruk' => app_metric($db, "SELECT COUNT(*) FROM notification_queue WHERE module_name = 'crm' AND notification_type = 'bulk_campaign'"),
    'E-posta Sablonu' => app_table_count($db, 'crm_email_templates'),
    'SMS Sablonu' => app_table_count($db, 'crm_sms_templates'),
    'WhatsApp Hazir' => app_table_count($db, 'crm_whatsapp_messages'),
    'Acik Gorev' => app_metric($db, "SELECT COUNT(*) FROM crm_tasks WHERE status IN ('bekliyor','devam')"),
    'Geciken Gorev' => app_metric($db, "SELECT COUNT(*) FROM crm_tasks WHERE status <> 'tamamlandi' AND due_at IS NOT NULL AND due_at < NOW()"),
    'Takvim Olayi' => count($calendarRows),
    'Teklif-Firsat Bag' => app_table_count($db, 'crm_opportunity_offer_links'),
    'Bagli Teklif Tutar' => number_format((float) app_metric($db, 'SELECT COALESCE(SUM(so.grand_total),0) FROM crm_opportunity_offer_links l INNER JOIN sales_offers so ON so.id = l.offer_id'), 2, ',', '.'),
    'Timeline Kaydi' => count($timelineRows),
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
    <h3>Cari Bazli Tam Timeline</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="crm">
        <input type="hidden" name="view" value="cari-bazli-tam-timeline">
        <div>
            <label>Cari Secimi</label>
            <select name="timeline_cari_id" required>
                <option value="">Seciniz</option>
                <?php foreach ($cariCards as $cari): ?>
                    <option value="<?= (int) $cari['id'] ?>" <?= $timelineCariId === (int) $cari['id'] ? 'selected' : '' ?>><?= app_h(crm_cari_label($cari)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Timeline Getir</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=crm&view=cari-bazli-tam-timeline" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>

    <?php if ($timelineCari): ?>
        <div class="notice notice-ok" style="margin-top:16px;">
            <?= app_h(crm_cari_label($timelineCari)) ?> icin <?= count($timelineRows) ?> hareket listeleniyor.
            <?= app_h((string) (($timelineCari['phone'] ?: '-') . ' / ' . ($timelineCari['email'] ?: '-'))) ?>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Tip</th><th>Durum</th><th>Detay</th><th>Tutar</th><th>Kaynak</th></tr></thead>
            <tbody>
            <?php foreach ($timelineRows as $item): ?>
                <tr>
                    <td><?= app_h((string) $item['event_date']) ?></td>
                    <td><?= app_h($item['event_type']) ?></td>
                    <td><?= app_h((string) ($item['status_text'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($item['detail_text'] ?: '-')) ?></td>
                    <td><?= number_format((float) $item['amount'], 2, ',', '.') ?></td>
                    <td><small><?= app_h($item['source_table'] . '#' . $item['record_id']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$timelineRows): ?>
                <tr><td colspan="6">Timeline icin cari secin veya bu cariye ait hareket bulunmuyor.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h3>CRM Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="crm">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Cari, not, hatirlatma, firsat">
        </div>
        <div>
            <label>Hatirlatma Durumu</label>
            <select name="reminder_status">
                <option value="">Tum durumlar</option>
                <option value="bekliyor" <?= $filters['reminder_status'] === 'bekliyor' ? 'selected' : '' ?>>Bekliyor</option>
                <option value="tamamlandi" <?= $filters['reminder_status'] === 'tamamlandi' ? 'selected' : '' ?>>Tamamlandi</option>
                <option value="iptal" <?= $filters['reminder_status'] === 'iptal' ? 'selected' : '' ?>>Iptal</option>
            </select>
        </div>
        <div>
            <label>Firsat Asamasi</label>
            <select name="opportunity_stage">
                <option value="">Tum asamalar</option>
                <option value="Yeni" <?= $filters['opportunity_stage'] === 'Yeni' ? 'selected' : '' ?>>Yeni</option>
                <option value="Gorusme" <?= $filters['opportunity_stage'] === 'Gorusme' ? 'selected' : '' ?>>Gorusme</option>
                <option value="Teklif Verildi" <?= $filters['opportunity_stage'] === 'Teklif Verildi' ? 'selected' : '' ?>>Teklif Verildi</option>
                <option value="Kazanildi" <?= $filters['opportunity_stage'] === 'Kazanildi' ? 'selected' : '' ?>>Kazanildi</option>
                <option value="Kaybedildi" <?= $filters['opportunity_stage'] === 'Kaybedildi' ? 'selected' : '' ?>>Kaybedildi</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=crm" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="crm">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="reminder_status" value="<?= app_h($filters['reminder_status']) ?>">
        <input type="hidden" name="opportunity_stage" value="<?= app_h($filters['opportunity_stage']) ?>">
        <div>
            <label>Cari Siralama</label>
            <select name="customer_sort">
                <option value="id_desc" <?= $filters['customer_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="name_asc" <?= $filters['customer_sort'] === 'name_asc' ? 'selected' : '' ?>>Cari A-Z</option>
                <option value="note_desc" <?= $filters['customer_sort'] === 'note_desc' ? 'selected' : '' ?>>Not sayisi yuksek</option>
                <option value="reminder_desc" <?= $filters['customer_sort'] === 'reminder_desc' ? 'selected' : '' ?>>Hatirlatma yuksek</option>
                <option value="opportunity_desc" <?= $filters['customer_sort'] === 'opportunity_desc' ? 'selected' : '' ?>>Firsat yuksek</option>
            </select>
        </div>
        <div>
            <label>Hatirlatma Siralama</label>
            <select name="reminder_sort">
                <option value="date_desc" <?= $filters['reminder_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['reminder_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="status_asc" <?= $filters['reminder_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Pipeline Gorunumu</h3>
    <p class="muted">Firsatlar asamalarina gore kolonlara ayrilir; teklif baglantisi olan kayitlarda bagli teklif sayisi ve tutari da gorunur.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;align-items:start;">
        <?php foreach ($pipeline as $stage => $stageData): ?>
            <div style="border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#f8fafc;">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px;">
                    <div>
                        <strong><?= app_h($stage) ?></strong>
                        <br><small><?= (int) $stageData['count'] ?> firsat</small>
                    </div>
                    <small><?= number_format((float) $stageData['weighted_amount'], 2, ',', '.') ?></small>
                </div>
                <div class="stack">
                    <?php foreach ($stageData['rows'] as $row): ?>
                        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px;">
                            <strong><?= app_h($row['title']) ?></strong>
                            <br><small><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></small>
                            <div style="display:flex;justify-content:space-between;gap:10px;margin-top:8px;">
                                <small>Firsat</small>
                                <small><?= number_format((float) $row['amount'], 2, ',', '.') ?></small>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:10px;">
                                <small>Olasilik</small>
                                <small>%<?= (int) $row['probability_score'] ?> / <?= number_format((float) $row['weighted_amount'], 2, ',', '.') ?></small>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:10px;">
                                <small>Kaynak</small>
                                <small><?= app_h((string) ($row['source_channel'] ?: '-')) ?></small>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:10px;">
                                <small>Teklif</small>
                                <small><?= (int) $row['linked_offer_count'] ?> / <?= number_format((float) $row['linked_offer_total'], 2, ',', '.') ?></small>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:10px;">
                                <small>Kapanis</small>
                                <small><?= app_h((string) ($row['expected_close_date'] ?: '-')) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$stageData['rows']): ?>
                        <small>Bu asamada firsat yok.</small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Hazir SMS Sablonlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_sms_template">
            <div>
                <label>Sablon Adi</label>
                <input name="template_name" placeholder="Kisa kampanya / randevu hatirlatma / tahsilat SMS" required>
            </div>
            <div>
                <label>Kategori</label>
                <input name="category_name" placeholder="Kampanya / Hatirlatma / Bilgilendirme">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                </select>
            </div>
            <div class="full">
                <label>SMS Mesaji</label>
                <textarea name="body_template" rows="5" maxlength="500" placeholder="{{company_name}} icin kampanyamiz basladi. Detay icin bizi arayabilirsiniz." required></textarea>
            </div>
            <div class="full">
                <small class="muted">Kullanilabilir alanlar: {{company_name}}, {{full_name}}, {{segment}}, {{source}}. SMS icin 160 karakter civari onerilir.</small>
            </div>
            <div class="full">
                <button type="submit">SMS Sablonu Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>SMS Sablon Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sablon</th><th>Kategori</th><th>Mesaj</th><th>Karakter</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($smsTemplates as $template): ?>
                    <tr>
                        <td><?= app_h($template['template_name']) ?></td>
                        <td><?= app_h((string) ($template['category_name'] ?: '-')) ?></td>
                        <td><?= app_h($template['body_template']) ?></td>
                        <td><?= strlen((string) $template['body_template']) ?></td>
                        <td><?= (int) $template['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$smsTemplates): ?>
                    <tr><td colspan="5">Henuz SMS sablonu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>WhatsApp Entegrasyonu</h3>
        <?php if ($preparedWhatsapp): ?>
            <div class="notice notice-ok" style="margin-bottom:16px;">
                WhatsApp mesaji hazirlandi:
                <strong><?= app_h($preparedWhatsapp['company_name'] ?: $preparedWhatsapp['full_name'] ?: $preparedWhatsapp['phone_number']) ?></strong>
                <br>
                <a href="<?= app_h($preparedWhatsapp['whatsapp_url']) ?>" target="_blank" rel="noopener">WhatsApp'ta Ac</a>
            </div>
        <?php endif; ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="prepare_whatsapp_message">
            <div>
                <label>Cari</label>
                <select name="cari_id" onchange="const opt=this.options[this.selectedIndex];if(opt.dataset.phone){this.form.phone_number.value=opt.dataset.phone;}">
                    <option value="">Cari sec veya manuel telefon yaz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>" data-phone="<?= app_h((string) ($cari['phone'] ?? '')) ?>">
                            <?= app_h(crm_cari_label($cari)) ?><?= !empty($cari['phone']) ? ' / ' . app_h((string) $cari['phone']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Telefon</label>
                <input name="phone_number" placeholder="05xx xxx xx xx">
            </div>
            <div class="full">
                <label>Hazir SMS/WhatsApp Sablonu</label>
                <select onchange="if(this.value){const data=JSON.parse(this.value);this.form.message_body.value=data.body;}">
                    <option value="">Sablon sec ve mesaji doldur</option>
                    <?php foreach ($activeSmsTemplates as $template): ?>
                        <option value="<?= app_h(json_encode(['body' => $template['body_template']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <?= app_h(($template['category_name'] ? $template['category_name'] . ' / ' : '') . $template['template_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>WhatsApp Mesaji</label>
                <textarea name="message_body" rows="6" placeholder="Merhaba {{company_name}}, size bilgi vermek isteriz." required></textarea>
            </div>
            <div class="full">
                <small class="muted">Telefon 05xx formatinda girilirse otomatik 90 ulke koduna cevrilir. Kullanilabilir alanlar: {{company_name}}, {{full_name}}, {{segment}}, {{source}}.</small>
            </div>
            <div class="full">
                <button type="submit">WhatsApp Mesaji Hazirla</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>WhatsApp Hazir Mesajlar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cari</th><th>Telefon</th><th>Mesaj</th><th>Durum</th><th>Link</th></tr></thead>
                <tbody>
                <?php foreach ($whatsappMessages as $message): ?>
                    <tr>
                        <td><?= app_h($message['company_name'] ?: $message['full_name'] ?: '-') ?></td>
                        <td><small><?= app_h($message['phone_number']) ?><br><?= app_h($message['normalized_phone']) ?></small></td>
                        <td><?= app_h(substr((string) $message['message_body'], 0, 120)) ?><?= strlen((string) $message['message_body']) > 120 ? '...' : '' ?></td>
                        <td><?= app_h($message['status']) ?></td>
                        <td><a href="<?= app_h($message['whatsapp_url']) ?>" target="_blank" rel="noopener">Ac</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$whatsappMessages): ?>
                    <tr><td colspan="5">Henuz WhatsApp mesaji hazirlanmadi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Hazir E-posta Sablonlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_email_template">
            <div>
                <label>Sablon Adi</label>
                <input name="template_name" placeholder="Bakim kampanyasi / teklif takip / memnuniyet" required>
            </div>
            <div>
                <label>Kategori</label>
                <input name="category_name" placeholder="Kampanya / Takip / Bilgilendirme">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                </select>
            </div>
            <div class="full">
                <label>Konu</label>
                <input name="subject_line" placeholder="{{company_name}} icin ozel teklif" required>
            </div>
            <div class="full">
                <label>E-posta Govdesi</label>
                <textarea name="body_template" rows="6" placeholder="Merhaba {{company_name}},&#10;&#10;Size ozel kampanyamiz hakkinda bilgi vermek isteriz." required></textarea>
            </div>
            <div class="full">
                <small class="muted">Kullanilabilir alanlar: {{company_name}}, {{full_name}}, {{segment}}, {{source}}</small>
            </div>
            <div class="full">
                <button type="submit">Sablon Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>E-posta Sablon Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sablon</th><th>Kategori</th><th>Konu</th><th>Durum</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($emailTemplates as $template): ?>
                    <tr>
                        <td><?= app_h($template['template_name']) ?></td>
                        <td><?= app_h((string) ($template['category_name'] ?: '-')) ?></td>
                        <td><?= app_h($template['subject_line']) ?></td>
                        <td><?= (int) $template['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td><small><?= app_h($template['created_at']) ?><br><?= app_h((string) ($template['updated_at'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$emailTemplates): ?>
                    <tr><td colspan="5">Henuz e-posta sablonu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>CRM Takvim Gorunumu</h3>
        <p class="muted">Gorev terminleri, hatirlatmalar, geri aramalar, gorusme takipleri ve firsat kapanis tarihleri tek akista listelenir.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Tip</th><th>Cari</th><th>Baslik</th><th>Durum</th><th>Detay</th></tr></thead>
                <tbody>
                <?php foreach ($calendarRows as $event): ?>
                    <tr>
                        <td><?= app_h($event['event_at']) ?></td>
                        <td><?= app_h($event['calendar_type']) ?></td>
                        <td><?= app_h($event['company_name'] ?: $event['full_name'] ?: '-') ?></td>
                        <td><?= app_h($event['title_text']) ?></td>
                        <td><small><?= app_h($event['status_text']) ?><br><?= app_h($event['priority_text']) ?></small></td>
                        <td><?= app_h((string) ($event['detail_text'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$calendarRows): ?>
                    <tr><td colspan="6">Takvim araliginda CRM olayi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Takvim Olay Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tip</th><th>Olay</th><th>En Yakin Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($calendarSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['calendar_type']) ?></td>
                        <td><?= (int) $item['event_count'] ?></td>
                        <td><?= app_h((string) ($item['nearest_event'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$calendarSummary): ?>
                    <tr><td colspan="3">Takvim olayi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Gorev Atama</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_crm_task">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Firsat</label>
                <select name="opportunity_id">
                    <option value="">Baglantisiz</option>
                    <?php foreach ($opportunityOptions as $opportunity): ?>
                        <option value="<?= (int) $opportunity['id'] ?>">
                            <?= app_h(($opportunity['company_name'] ?: $opportunity['full_name'] ?: '-') . ' / ' . $opportunity['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Gorev Basligi</label>
                <input name="task_title" placeholder="Teklif geri donusu al / demo randevusu planla / tahsilat hatirlat" required>
            </div>
            <div>
                <label>Kullaniciya Ata</label>
                <select name="assigned_user_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($userOptions as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name'] . ($user['email'] ? ' / ' . $user['email'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Manuel Sorumlu</label>
                <input name="assigned_name" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div>
                <label>Oncelik</label>
                <select name="priority">
                    <option value="normal">Normal</option>
                    <option value="dusuk">Dusuk</option>
                    <option value="yuksek">Yuksek</option>
                    <option value="kritik">Kritik</option>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="devam">Devam</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Termin</label>
                <input type="datetime-local" name="due_at">
            </div>
            <div class="full">
                <label>Aciklama</label>
                <textarea name="task_description" rows="3" placeholder="Gorevin detayi, beklenen cikti veya dikkat edilmesi gereken not"></textarea>
            </div>
            <div class="full">
                <button type="submit">Gorev Ata</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Gorev Durum Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Gorev</th><th>En Yakin Termin</th></tr></thead>
                <tbody>
                <?php foreach ($taskStatusSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['status']) ?></td>
                        <td><?= (int) $item['task_count'] ?></td>
                        <td><?= app_h((string) ($item['nearest_due'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$taskStatusSummary): ?>
                    <tr><td colspan="3">Henuz gorev yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Gorev Oncelik Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Oncelik</th><th>Gorev</th><th>Geciken</th></tr></thead>
                <tbody>
                <?php foreach ($taskPrioritySummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['priority']) ?></td>
                        <td><?= (int) $item['task_count'] ?></td>
                        <td><?= (int) $item['overdue_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$taskPrioritySummary): ?>
                    <tr><td colspan="3">Henuz gorev yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Gorev Takibi</h3>
    <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <input type="hidden" name="action" value="bulk_update_tasks">
        <select name="bulk_task_status">
            <option value="bekliyor">Bekliyor</option>
            <option value="devam">Devam</option>
            <option value="tamamlandi">Tamamlandi</option>
            <option value="iptal">Iptal</option>
        </select>
        <button type="submit">Secili Gorevleri Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.crm-task-check').forEach((el)=>el.checked=this.checked)"></th><th>Termin</th><th>Cari</th><th>Gorev</th><th>Firsat</th><th>Sorumlu</th><th>Oncelik</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($taskRows as $task): ?>
                    <tr>
                        <td><input class="crm-task-check" type="checkbox" name="task_ids[]" value="<?= (int) $task['id'] ?>"></td>
                        <td><?= app_h((string) ($task['due_at'] ?: '-')) ?></td>
                        <td><?= app_h($task['company_name'] ?: $task['full_name'] ?: '-') ?></td>
                        <td><small><?= app_h($task['task_title']) ?><br><?= app_h((string) ($task['task_description'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) ($task['opportunity_title'] ?: '-')) ?></td>
                        <td><small><?= app_h((string) ($task['assigned_user_name'] ?: $task['assigned_name'] ?: '-')) ?><br><?= app_h($task['created_at']) ?></small></td>
                        <td><?= app_h($task['priority']) ?></td>
                        <td><small><?= app_h($task['status']) ?><br><?= app_h((string) ($task['completed_at'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$taskRows): ?>
                    <tr><td colspan="8">Henuz gorev yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Toplu Kampanya Gonderimi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="send_bulk_campaign">
            <div>
                <label>Kampanya Adi</label>
                <input name="campaign_name" placeholder="Bahar bakim kampanyasi / yeni urun duyurusu" required>
            </div>
            <div>
                <label>Kanal</label>
                <select name="channel">
                    <option value="email">E-posta</option>
                    <option value="sms">SMS</option>
                </select>
            </div>
            <div class="full">
                <label>Hazir E-posta Sablonu</label>
                <select onchange="if(this.value){const data=JSON.parse(this.value);this.form.subject_line.value=data.subject;this.form.message_body.value=data.body;}">
                    <option value="">Sablon sec ve konu/mesaji doldur</option>
                    <?php foreach ($activeEmailTemplates as $template): ?>
                        <option value="<?= app_h(json_encode(['subject' => $template['subject_line'], 'body' => $template['body_template']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <?= app_h(($template['category_name'] ? $template['category_name'] . ' / ' : '') . $template['template_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Hazir SMS Sablonu</label>
                <select onchange="if(this.value){const data=JSON.parse(this.value);this.form.subject_line.value='';this.form.message_body.value=data.body;}">
                    <option value="">SMS sablonu sec ve mesaji doldur</option>
                    <?php foreach ($activeSmsTemplates as $template): ?>
                        <option value="<?= app_h(json_encode(['body' => $template['body_template']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <?= app_h(($template['category_name'] ? $template['category_name'] . ' / ' : '') . $template['template_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Segment Filtresi</label>
                <select name="target_segment">
                    <option value="">Tum segmentler</option>
                    <option value="VIP">VIP</option>
                    <option value="Aktif Musteri">Aktif Musteri</option>
                    <option value="Potansiyel">Potansiyel</option>
                    <option value="Riskli">Riskli</option>
                    <option value="Uyuyan">Uyuyan</option>
                    <option value="Stratejik">Stratejik</option>
                    <option value="Yeni Lead">Yeni Lead</option>
                    <option value="Diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Etiket Filtresi</label>
                <select name="target_tag_id">
                    <option value="">Tum etiketler</option>
                    <?php foreach ($tagOptions as $tag): ?>
                        <option value="<?= (int) $tag['id'] ?>"><?= app_h($tag['tag_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kaynak Filtresi</label>
                <select name="target_source">
                    <option value="">Tum kaynaklar</option>
                    <?php foreach ($sourceSummary as $source): ?>
                        <?php if ($source['source_channel'] !== 'Kaynak Yok'): ?>
                            <option value="<?= app_h($source['source_channel']) ?>"><?= app_h($source['source_channel']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Planlanan Zaman</label>
                <input type="datetime-local" name="planned_at" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="full">
                <label>Konu (e-posta icin)</label>
                <input name="subject_line" placeholder="{{company_name}} icin ozel kampanya">
            </div>
            <div class="full">
                <label>Mesaj</label>
                <textarea name="message_body" rows="5" placeholder="Merhaba {{company_name}}, size ozel kampanyamiz basladi. Segment: {{segment}}" required></textarea>
            </div>
            <div class="full">
                <small class="muted">Kullanilabilir alanlar: {{company_name}}, {{full_name}}, {{segment}}, {{source}}</small>
            </div>
            <div class="full">
                <button type="submit">Kampanyayi Kuyruga Al</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Kampanya Ozetleri</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kanal</th><th>Kampanya</th><th>Kuyruk</th><th>Atlanan</th></tr></thead>
                <tbody>
                <?php foreach ($campaignSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['channel']) ?></td>
                        <td><?= (int) $item['campaign_count'] ?></td>
                        <td><?= (int) $item['queued_total'] ?></td>
                        <td><?= (int) $item['skipped_total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$campaignSummary): ?>
                    <tr><td colspan="4">Henuz kampanya yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Kampanya Gecmisi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tarih</th><th>Kampanya</th><th>Kanal</th><th>Hedef</th><th>Kuyruk</th><th>Plan</th><th>Olusturan</th></tr></thead>
            <tbody>
            <?php foreach ($campaignRows as $campaign): ?>
                <tr>
                    <td><?= app_h($campaign['created_at']) ?></td>
                    <td><small><?= app_h($campaign['campaign_name']) ?><br><?= app_h((string) ($campaign['subject_line'] ?: '-')) ?></small></td>
                    <td><?= app_h($campaign['channel']) ?></td>
                    <td><small>Segment: <?= app_h((string) ($campaign['target_segment'] ?: 'Tum')) ?><br>Etiket: <?= app_h((string) ($campaign['tag_name'] ?: 'Tum')) ?><br>Kaynak: <?= app_h((string) ($campaign['target_source'] ?: 'Tum')) ?></small></td>
                    <td><?= (int) $campaign['queued_count'] ?> / <?= (int) $campaign['skipped_count'] ?></td>
                    <td><?= app_h($campaign['planned_at']) ?></td>
                    <td><?= app_h((string) ($campaign['created_by'] ?: '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$campaignRows): ?>
                <tr><td colspan="7">Henuz kampanya gonderimi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Etiketleme</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="assign_customer_tag">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Etiket</label>
                <input name="tag_name" list="crm-tag-options" placeholder="Acil takip / demo istedi / VIP aday" required>
                <datalist id="crm-tag-options">
                    <?php foreach ($tagOptions as $tag): ?>
                        <option value="<?= app_h($tag['tag_name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>Renk</label>
                <select name="tag_color">
                    <option value="">Seciniz</option>
                    <option value="#2563eb">Mavi</option>
                    <option value="#16a34a">Yesil</option>
                    <option value="#dc2626">Kirmizi</option>
                    <option value="#f97316">Turuncu</option>
                    <option value="#7c3aed">Mor</option>
                    <option value="#475569">Gri</option>
                </select>
            </div>
            <div>
                <label>Atayan</label>
                <input name="assigned_by" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div class="full">
                <label>Etiket Notu</label>
                <textarea name="tag_note" rows="3" placeholder="Bu etiket neden verildi? Takip stratejisi veya kisa aciklama"></textarea>
            </div>
            <div class="full">
                <button type="submit">Etiket Ata</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Etiket Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Etiket</th><th>Cari</th><th>Firsat</th><th>Agirlikli</th></tr></thead>
                <tbody>
                <?php foreach ($tagSummary as $item): ?>
                    <tr>
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= app_h((string) ($item['tag_color'] ?: '#64748b')) ?>;margin-right:6px;"></span>
                            <?= app_h($item['tag_name']) ?>
                        </td>
                        <td><?= (int) $item['customer_count'] ?></td>
                        <td><?= (int) $item['opportunity_count'] ?></td>
                        <td><?= number_format((float) $item['weighted_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tagSummary): ?>
                    <tr><td colspan="4">Henuz etiket yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Etiketli Cariler</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Etiket</th><th>Cari</th><th>Segment</th><th>Iletisim</th><th>Firsat</th><th>Agirlikli</th><th>Not</th></tr></thead>
            <tbody>
            <?php foreach ($tagRows as $row): ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= app_h((string) ($row['tag_color'] ?: '#64748b')) ?>;margin-right:6px;"></span>
                        <?= app_h($row['tag_name']) ?>
                    </td>
                    <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                    <td><?= app_h((string) ($row['segment_code'] ?: 'Segmentsiz')) ?></td>
                    <td><small><?= app_h((string) ($row['phone'] ?: '-')) ?><br><?= app_h((string) ($row['email'] ?: '-')) ?></small></td>
                    <td><?= (int) $row['opportunity_count'] ?></td>
                    <td><?= number_format((float) $row['weighted_total'], 2, ',', '.') ?></td>
                    <td><small><?= app_h((string) ($row['tag_note'] ?: '-')) ?><br><?= app_h((string) ($row['assigned_by'] ?: '-')) ?> / <?= app_h($row['assigned_at']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tagRows): ?>
                <tr><td colspan="7">Henuz etiketli cari yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Musteri Segmentasyonu</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_customer_segment">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Segment</label>
                <select name="segment_code" required>
                    <option value="">Seciniz</option>
                    <option value="VIP">VIP</option>
                    <option value="Aktif Musteri">Aktif Musteri</option>
                    <option value="Potansiyel">Potansiyel</option>
                    <option value="Riskli">Riskli</option>
                    <option value="Uyuyan">Uyuyan</option>
                    <option value="Stratejik">Stratejik</option>
                    <option value="Yeni Lead">Yeni Lead</option>
                    <option value="Diger">Diger</option>
                </select>
            </div>
            <div class="full">
                <label>Segment Notu</label>
                <textarea name="segment_note" rows="3" placeholder="Neden bu segmentte? Alim potansiyeli, risk, iliski durumu veya takip stratejisi"></textarea>
            </div>
            <div class="full">
                <button type="submit">Segment Guncelle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Segment Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Segment</th><th>Cari</th><th>Firsat</th><th>Toplam</th><th>Agirlikli</th></tr></thead>
                <tbody>
                <?php foreach ($segmentSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['segment_code']) ?></td>
                        <td><?= (int) $item['customer_count'] ?></td>
                        <td><?= (int) $item['opportunity_count'] ?></td>
                        <td><?= number_format((float) $item['opportunity_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['weighted_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$segmentSummary): ?>
                    <tr><td colspan="5">Henuz segment kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Segmentli Musteriler</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cari</th><th>Iletisim</th><th>Segment</th><th>Not</th><th>Firsat</th><th>Agirlikli</th><th>CRM</th></tr></thead>
            <tbody>
            <?php foreach ($segmentRows as $row): ?>
                <tr>
                    <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                    <td><small><?= app_h((string) ($row['phone'] ?: '-')) ?><br><?= app_h((string) ($row['email'] ?: '-')) ?></small></td>
                    <td><?= app_h((string) ($row['segment_code'] ?: 'Segmentsiz')) ?></td>
                    <td><?= app_h((string) ($row['segment_note'] ?: '-')) ?></td>
                    <td><?= (int) $row['opportunity_count'] ?> / <?= number_format((float) $row['opportunity_total'], 2, ',', '.') ?></td>
                    <td><?= number_format((float) $row['weighted_total'], 2, ',', '.') ?></td>
                    <td><small>Not: <?= (int) $row['note_count'] ?><br>Hatirlatma: <?= (int) $row['reminder_count'] ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$segmentRows): ?>
                <tr><td colspan="7">Henuz segmentli cari yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Kaynak Takibi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_opportunity_source">
            <div>
                <label>Firsat</label>
                <select name="opportunity_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($opportunityOptions as $opportunity): ?>
                        <option value="<?= (int) $opportunity['id'] ?>">
                            <?= app_h(($opportunity['company_name'] ?: $opportunity['full_name'] ?: '-') . ' / ' . $opportunity['title'] . ' / ' . ($opportunity['source_channel'] ?: 'Kaynak Yok')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kaynak Kanali</label>
                <select name="source_channel">
                    <option value="">Seciniz</option>
                    <option value="Web">Web</option>
                    <option value="Telefon">Telefon</option>
                    <option value="E-posta">E-posta</option>
                    <option value="Referans">Referans</option>
                    <option value="Saha">Saha</option>
                    <option value="Sosyal Medya">Sosyal Medya</option>
                    <option value="Fuar">Fuar</option>
                    <option value="Mevcut Musteri">Mevcut Musteri</option>
                    <option value="Diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Kampanya</label>
                <input name="source_campaign" placeholder="Google kampanyasi / fuar adi / donemsel kampanya">
            </div>
            <div>
                <label>Referans / Lead Notu</label>
                <input name="source_referrer" placeholder="Yonlendiren kisi, firma veya kanal detayi">
            </div>
            <div class="full">
                <button type="submit">Kaynak Guncelle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Kaynak Performans Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Kaynak</th><th>Firsat</th><th>Toplam</th><th>Agirlikli</th><th>Ort. Puan</th></tr></thead>
                <tbody>
                <?php foreach ($sourceSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['source_channel']) ?></td>
                        <td><?= (int) $item['opportunity_count'] ?></td>
                        <td><?= number_format((float) $item['amount_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['weighted_total'], 2, ',', '.') ?></td>
                        <td>%<?= number_format((float) $item['avg_probability'], 1, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sourceSummary): ?>
                    <tr><td colspan="5">Henuz kaynak bilgisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Kaynak Gecmisi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cari</th><th>Firsat</th><th>Kaynak</th><th>Kampanya</th><th>Referans</th><th>Puan</th><th>Agirlikli</th><th>Kapanis</th></tr></thead>
            <tbody>
            <?php foreach ($sourceRows as $row): ?>
                <tr>
                    <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                    <td><?= app_h($row['title']) ?></td>
                    <td><?= app_h((string) ($row['source_channel'] ?: 'Kaynak Yok')) ?></td>
                    <td><?= app_h((string) ($row['source_campaign'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($row['source_referrer'] ?: '-')) ?></td>
                    <td>%<?= (int) $row['probability_score'] ?></td>
                    <td><?= number_format((float) $row['weighted_amount'], 2, ',', '.') ?></td>
                    <td><?= app_h((string) ($row['expected_close_date'] ?: '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sourceRows): ?>
                <tr><td colspan="8">Henuz kaynak kaydi yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Firsat Olasilik Puani</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="update_opportunity_probability">
            <div>
                <label>Firsat</label>
                <select name="opportunity_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($opportunityOptions as $opportunity): ?>
                        <option value="<?= (int) $opportunity['id'] ?>">
                            <?= app_h(($opportunity['company_name'] ?: $opportunity['full_name'] ?: '-') . ' / ' . $opportunity['title'] . ' / %' . (int) $opportunity['probability_score']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Olasilik Puani (%)</label>
                <input type="number" name="probability_score" min="0" max="100" value="50" required>
            </div>
            <div class="full">
                <label>Puan Notu</label>
                <textarea name="probability_note" rows="3" placeholder="Karar verici net mi, butce onayli mi, teklif asamasi nedir?"></textarea>
            </div>
            <div class="full">
                <button type="submit">Olasilik Guncelle</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Olasilik Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Grup</th><th>Firsat</th><th>Toplam</th><th>Agirlikli</th></tr></thead>
                <tbody>
                <?php foreach ($probabilitySummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['probability_group']) ?></td>
                        <td><?= (int) $item['opportunity_count'] ?></td>
                        <td><?= number_format((float) $item['amount_total'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $item['weighted_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$probabilitySummary): ?>
                    <tr><td colspan="4">Henuz firsat olasilik puani yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card">
    <h3>Olasilik Puan Listesi</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cari</th><th>Firsat</th><th>Asama</th><th>Puan</th><th>Tutar</th><th>Agirlikli</th><th>Not</th><th>Kapanis</th></tr></thead>
            <tbody>
            <?php foreach ($probabilityRows as $row): ?>
                <tr>
                    <td><?= app_h($row['company_name'] ?: $row['full_name'] ?: '-') ?></td>
                    <td><?= app_h($row['title']) ?></td>
                    <td><?= app_h($row['stage']) ?></td>
                    <td>%<?= (int) $row['probability_score'] ?></td>
                    <td><?= number_format((float) $row['amount'], 2, ',', '.') ?></td>
                    <td><?= number_format((float) $row['weighted_amount'], 2, ',', '.') ?></td>
                    <td><?= app_h((string) ($row['probability_note'] ?: '-')) ?></td>
                    <td><?= app_h((string) ($row['expected_close_date'] ?: '-')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$probabilityRows): ?>
                <tr><td colspan="8">Henuz firsat olasilik puani yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Teklif-Firsat Baglantisi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="link_offer_opportunity">
            <div>
                <label>Firsat</label>
                <select name="opportunity_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($opportunityOptions as $opportunity): ?>
                        <option value="<?= (int) $opportunity['id'] ?>">
                            <?= app_h(($opportunity['company_name'] ?: $opportunity['full_name'] ?: '-') . ' / ' . $opportunity['title'] . ' / ' . $opportunity['stage']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Teklif</label>
                <select name="offer_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($offerOptions as $offer): ?>
                        <option value="<?= (int) $offer['id'] ?>">
                            <?= app_h(($offer['company_name'] ?: $offer['full_name'] ?: '-') . ' / ' . $offer['offer_no'] . ' / ' . number_format((float) $offer['grand_total'], 2, ',', '.')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Baglanti Durumu</label>
                <select name="relation_status">
                    <option value="aktif">Aktif</option>
                    <option value="taslak">Taslak</option>
                    <option value="kazanildi">Kazanildi</option>
                    <option value="kaybedildi">Kaybedildi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div>
                <label>Baglayan</label>
                <input name="linked_by" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="relation_note" rows="3" placeholder="Bu teklif hangi firsat kapsaminda degerlendiriliyor?"></textarea>
            </div>
            <div class="full">
                <button type="submit">Baglanti Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Baglanti Durum Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Durum</th><th>Baglanti</th><th>Teklif Tutari</th></tr></thead>
                <tbody>
                <?php foreach ($offerOpportunitySummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['relation_status']) ?></td>
                        <td><?= (int) $item['link_count'] ?></td>
                        <td><?= number_format((float) $item['offer_total'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$offerOpportunitySummary): ?>
                    <tr><td colspan="3">Henuz teklif-firsat baglantisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Gorusme Notlari</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_meeting_note">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Gorusme Tipi</label>
                <select name="meeting_type">
                    <option value="online">Online</option>
                    <option value="telefon">Telefon</option>
                    <option value="ofis">Ofis</option>
                    <option value="saha">Saha</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div class="full">
                <label>Konu</label>
                <input name="meeting_subject" placeholder="Teklif degerlendirme / proje toplanti / servis gorusmesi" required>
            </div>
            <div>
                <label>Gorusme Tarihi</label>
                <input type="datetime-local" name="meeting_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div>
                <label>Takip Tarihi</label>
                <input type="datetime-local" name="follow_up_at">
            </div>
            <div class="full">
                <label>Katilimcilar</label>
                <input name="participants" placeholder="Musteri, satis temsilcisi, teknik ekip">
            </div>
            <div class="full">
                <label>Kararlar</label>
                <textarea name="decisions" rows="2" placeholder="Alinan kararlar"></textarea>
            </div>
            <div class="full">
                <label>Aksiyon Maddeleri</label>
                <textarea name="action_items" rows="2" placeholder="Kim, neyi, ne zamana kadar yapacak?"></textarea>
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div class="full">
                <label>Ek Not</label>
                <textarea name="notes" rows="2" placeholder="Gorusme detaylari veya riskler"></textarea>
            </div>
            <div class="full">
                <button type="submit">Gorusme Notu Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Gorusme Tip Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tip</th><th>Kayit</th><th>Son Gorusme</th></tr></thead>
                <tbody>
                <?php foreach ($meetingTypeSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['meeting_type']) ?></td>
                        <td><?= (int) $item['meeting_count'] ?></td>
                        <td><?= app_h((string) ($item['last_meeting'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$meetingTypeSummary): ?>
                    <tr><td colspan="3">Henuz gorusme notu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Arama Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_call_log">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Arama Yonu</label>
                <select name="call_direction">
                    <option value="giden">Giden</option>
                    <option value="gelen">Gelen</option>
                </select>
            </div>
            <div class="full">
                <label>Konu</label>
                <input name="call_subject" placeholder="Tahsilat aramasi / teklif takibi / servis geri donusu" required>
            </div>
            <div>
                <label>Arama Sonucu</label>
                <select name="call_result">
                    <option value="ulasildi">Ulasildi</option>
                    <option value="ulasamadi">Ulasamadi</option>
                    <option value="mesgul">Mesgul</option>
                    <option value="geri_arayacak">Geri Arayacak</option>
                    <option value="olumsuz">Olumsuz</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Telefon</label>
                <input name="phone_number" placeholder="05xx xxx xx xx">
            </div>
            <div>
                <label>Arama Tarihi</label>
                <input type="datetime-local" name="call_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div>
                <label>Sure (saniye)</label>
                <input type="number" name="duration_seconds" min="0" value="0">
            </div>
            <div>
                <label>Geri Arama</label>
                <input type="datetime-local" name="callback_at">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Arama detaylari, musteri talebi veya sonraki adim"></textarea>
            </div>
            <div class="full">
                <button type="submit">Arama Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Arama Sonuc Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Sonuc</th><th>Kayit</th><th>Toplam Dk</th></tr></thead>
                <tbody>
                <?php foreach ($callResultSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['call_result']) ?></td>
                        <td><?= (int) $item['call_count'] ?></td>
                        <td><?= number_format(((int) $item['total_seconds']) / 60, 1, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$callResultSummary): ?>
                    <tr><td colspan="3">Henuz arama kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Aktivite Kaydi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_activity">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Aktivite Tipi</label>
                <select name="activity_type">
                    <option value="arama">Arama</option>
                    <option value="toplanti">Toplanti</option>
                    <option value="e-posta">E-posta</option>
                    <option value="ziyaret">Ziyaret</option>
                    <option value="gorev">Gorev</option>
                    <option value="diger">Diger</option>
                </select>
            </div>
            <div class="full">
                <label>Konu</label>
                <input name="activity_subject" placeholder="Teklif gorusmesi / servis memnuniyeti / tahsilat aramasi" required>
            </div>
            <div>
                <label>Aktivite Tarihi</label>
                <input type="datetime-local" name="activity_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div>
                <label>Sonraki Aksiyon</label>
                <input type="datetime-local" name="next_action_at">
            </div>
            <div>
                <label>Sonuc</label>
                <input name="activity_result" placeholder="Olumlu / tekrar aranacak / teklif istendi">
            </div>
            <div>
                <label>Sorumlu</label>
                <input name="responsible_name" value="<?= app_h((string) ($authUser['full_name'] ?? '')) ?>">
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="notes" rows="2" placeholder="Gorusme detaylari ve takip notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Aktivite Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Aktivite Tip Ozeti</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tip</th><th>Kayit</th><th>Son Aktivite</th></tr></thead>
                <tbody>
                <?php foreach ($activityTypeSummary as $item): ?>
                    <tr>
                        <td><?= app_h($item['activity_type']) ?></td>
                        <td><?= (int) $item['activity_count'] ?></td>
                        <td><?= app_h((string) ($item['last_activity'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$activityTypeSummary): ?>
                    <tr><td colspan="3">Henuz aktivite kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Musteri Notu</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_note">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full">
                <label>Not</label>
                <textarea name="note_text" rows="4" placeholder="Musteri gorusme notu, teklif gecmisi, ozel bilgi" required></textarea>
            </div>
            <div class="full">
                <button type="submit">Not Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Hatirlatma</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_reminder">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="bekliyor">Bekliyor</option>
                    <option value="tamamlandi">Tamamlandi</option>
                    <option value="iptal">Iptal</option>
                </select>
            </div>
            <div class="full">
                <label>Hatirlatma</label>
                <input name="reminder_text" placeholder="Arama, geri donus, teklif sunumu" required>
            </div>
            <div>
                <label>Tarih Saat</label>
                <input type="datetime-local" name="remind_at" required>
            </div>
            <div class="full">
                <button type="submit">Hatirlatma Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Firsat Takibi</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_opportunity">
            <div>
                <label>Cari</label>
                <select name="cari_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($cariCards as $cari): ?>
                        <option value="<?= (int) $cari['id'] ?>"><?= app_h(crm_cari_label($cari)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Asama</label>
                <select name="stage">
                    <option value="Yeni">Yeni</option>
                    <option value="Gorusme">Gorusme</option>
                    <option value="Teklif Verildi">Teklif Verildi</option>
                    <option value="Kazanildi">Kazanildi</option>
                    <option value="Kaybedildi">Kaybedildi</option>
                </select>
            </div>
            <div class="full">
                <label>Firsat Basligi</label>
                <input name="title" placeholder="Yeni cihaz satisi / servis anlasmasi / kira yenileme" required>
            </div>
            <div>
                <label>Tutar</label>
                <input type="number" step="0.01" name="amount" value="0">
            </div>
            <div>
                <label>Olasilik Puani (%)</label>
                <input type="number" name="probability_score" min="0" max="100" value="0">
            </div>
            <div>
                <label>Tahmini Kapanis</label>
                <input type="date" name="expected_close_date">
            </div>
            <div class="full">
                <label>Olasilik Notu</label>
                <input name="probability_note" placeholder="Satin alma niyeti, butce, karar sureci veya risk notu">
            </div>
            <div>
                <label>Kaynak Kanali</label>
                <select name="source_channel">
                    <option value="">Seciniz</option>
                    <option value="Web">Web</option>
                    <option value="Telefon">Telefon</option>
                    <option value="E-posta">E-posta</option>
                    <option value="Referans">Referans</option>
                    <option value="Saha">Saha</option>
                    <option value="Sosyal Medya">Sosyal Medya</option>
                    <option value="Fuar">Fuar</option>
                    <option value="Mevcut Musteri">Mevcut Musteri</option>
                    <option value="Diger">Diger</option>
                </select>
            </div>
            <div>
                <label>Kampanya</label>
                <input name="source_campaign" placeholder="Kampanya veya etkinlik adi">
            </div>
            <div class="full">
                <label>Referans / Lead Detayi</label>
                <input name="source_referrer" placeholder="Yonlendiren kisi, firma veya kaynak detayi">
            </div>
            <div class="full">
                <button type="submit">Firsat Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>CRM Notlari</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cari</th><th>Not</th><th>Olusturan</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($notes as $note): ?>
                    <tr>
                        <td><?= app_h($note['company_name'] ?: $note['full_name'] ?: '-') ?></td>
                        <td><?= app_h($note['note_text']) ?></td>
                        <td><?= app_h($note['created_by_name'] ?: '-') ?></td>
                        <td><?= app_h($note['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Teklif-Firsat Baglanti Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Cari</th><th>Firsat</th><th>Teklif</th><th>Durum</th><th>Tutar</th><th>Not</th></tr></thead>
                <tbody>
                <?php foreach ($offerOpportunityLinks as $link): ?>
                    <tr>
                        <td><?= app_h($link['linked_at']) ?></td>
                        <td><?= app_h($link['company_name'] ?: $link['full_name'] ?: '-') ?></td>
                        <td><small><?= app_h($link['opportunity_title']) ?><br><?= app_h($link['opportunity_stage']) ?></small></td>
                        <td><small><?= app_h($link['offer_no']) ?><br><?= app_h($link['offer_status']) ?></small></td>
                        <td><?= app_h($link['relation_status']) ?></td>
                        <td><?= number_format((float) $link['grand_total'], 2, ',', '.') ?></td>
                        <td><small><?= app_h((string) ($link['relation_note'] ?: '-')) ?><br><?= app_h((string) ($link['linked_by'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$offerOpportunityLinks): ?>
                    <tr><td colspan="7">Henuz teklif-firsat baglantisi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Gorusme Notlari Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Cari</th><th>Tip</th><th>Konu</th><th>Kararlar</th><th>Aksiyon</th><th>Takip</th></tr></thead>
                <tbody>
                <?php foreach ($meetingNotes as $meeting): ?>
                    <tr>
                        <td><?= app_h($meeting['meeting_at']) ?></td>
                        <td><?= app_h($meeting['company_name'] ?: $meeting['full_name'] ?: '-') ?></td>
                        <td><?= app_h($meeting['meeting_type']) ?></td>
                        <td><small><?= app_h($meeting['meeting_subject']) ?><br><?= app_h((string) ($meeting['participants'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) ($meeting['decisions'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($meeting['action_items'] ?: '-')) ?></td>
                        <td><small><?= app_h((string) ($meeting['follow_up_at'] ?: '-')) ?><br><?= app_h((string) ($meeting['responsible_name'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$meetingNotes): ?>
                    <tr><td colspan="7">Henuz gorusme notu yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Arama Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Cari</th><th>Yon</th><th>Konu</th><th>Sonuc</th><th>Sure</th><th>Geri Arama</th><th>Sorumlu</th></tr></thead>
                <tbody>
                <?php foreach ($callLogs as $call): ?>
                    <tr>
                        <td><?= app_h($call['call_at']) ?></td>
                        <td><?= app_h($call['company_name'] ?: $call['full_name'] ?: '-') ?></td>
                        <td><?= app_h($call['call_direction']) ?></td>
                        <td><small><?= app_h($call['call_subject']) ?><br><?= app_h((string) ($call['phone_number'] ?: '-')) ?></small></td>
                        <td><?= app_h($call['call_result']) ?></td>
                        <td><?= number_format(((int) $call['duration_seconds']) / 60, 1, ',', '.') ?> dk</td>
                        <td><?= app_h((string) ($call['callback_at'] ?: '-')) ?></td>
                        <td><small><?= app_h((string) ($call['responsible_name'] ?: '-')) ?><br><?= app_h((string) ($call['notes'] ?: '-')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$callLogs): ?>
                    <tr><td colspan="8">Henuz arama kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Aktivite Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tarih</th><th>Cari</th><th>Tip</th><th>Konu</th><th>Sonuc</th><th>Sonraki</th><th>Sorumlu</th></tr></thead>
                <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><?= app_h($activity['activity_at']) ?></td>
                        <td><?= app_h($activity['company_name'] ?: $activity['full_name'] ?: '-') ?></td>
                        <td><?= app_h($activity['activity_type']) ?></td>
                        <td><small><?= app_h($activity['activity_subject']) ?><br><?= app_h((string) ($activity['notes'] ?: '-')) ?></small></td>
                        <td><?= app_h((string) ($activity['activity_result'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($activity['next_action_at'] ?: '-')) ?></td>
                        <td><?= app_h((string) ($activity['responsible_name'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$activities): ?>
                    <tr><td colspan="7">Henuz aktivite kaydi yok.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>CRM Cari Listesi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Cari</th><th>Iletisim</th><th>Segment</th><th>Etiket</th><th>Not</th><th>Hatirlatma</th><th>Firsat</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($crmCustomers as $customer): ?>
                    <tr>
                        <td><?= app_h($customer['company_name'] ?: $customer['full_name'] ?: '-') ?></td>
                        <td><?= app_h((string) (($customer['phone'] ?: '-') . ' / ' . ($customer['email'] ?: '-'))) ?></td>
                        <td><small><?= app_h((string) ($customer['segment_code'] ?: 'Segmentsiz')) ?><br><?= app_h((string) ($customer['segment_note'] ?: '-')) ?></small></td>
                        <td>
                            <?php foreach (($customerTagMap[(int) $customer['id']] ?? []) as $tag): ?>
                                <small style="display:inline-block;margin:0 4px 4px 0;padding:3px 7px;border-radius:999px;background:#f1f5f9;">
                                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= app_h((string) ($tag['tag_color'] ?: '#64748b')) ?>;margin-right:4px;"></span><?= app_h($tag['tag_name']) ?>
                                </small>
                            <?php endforeach; ?>
                            <?php if (empty($customerTagMap[(int) $customer['id']])): ?>
                                <small>Etiketsiz</small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $customer['note_count'] ?></td>
                        <td><?= (int) $customer['reminder_count'] ?></td>
                        <td><?= (int) $customer['opportunity_count'] ?></td>
                        <td>
                            <div class="stack">
                                <a href="crm_detail.php?id=<?= (int) $customer['id'] ?>">Detay</a>
                                <a href="index.php?module=crm&view=cari-bazli-tam-timeline&timeline_cari_id=<?= (int) $customer['id'] ?>">Timeline</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($crmCustomersPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $crmCustomersPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'crm', 'search' => $filters['search'], 'reminder_status' => $filters['reminder_status'], 'opportunity_stage' => $filters['opportunity_stage'], 'customer_sort' => $filters['customer_sort'], 'reminder_sort' => $filters['reminder_sort'], 'customer_page' => $page, 'reminder_page' => $crmRemindersPagination['page']])) ?>"><?= $page === $crmCustomersPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Hatirlatmalar</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_reminders">
            <select name="bulk_reminder_status">
                <option value="bekliyor">Bekliyor</option>
                <option value="tamamlandi">Tamamlandi</option>
                <option value="iptal">Iptal</option>
            </select>
            <button type="submit">Secili Hatirlatmalari Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.crm-reminder-check').forEach((el)=>el.checked=this.checked)"></th><th>Cari</th><th>Hatirlatma</th><th>Zaman</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($reminders as $reminder): ?>
                    <tr>
                        <td><input class="crm-reminder-check" type="checkbox" name="reminder_ids[]" value="<?= (int) $reminder['id'] ?>"></td>
                        <td><?= app_h($reminder['company_name'] ?: $reminder['full_name'] ?: '-') ?></td>
                        <td><?= app_h($reminder['reminder_text']) ?></td>
                        <td><?= app_h($reminder['remind_at']) ?></td>
                        <td><?= app_h($reminder['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($crmRemindersPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $crmRemindersPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'crm', 'search' => $filters['search'], 'reminder_status' => $filters['reminder_status'], 'opportunity_stage' => $filters['opportunity_stage'], 'customer_sort' => $filters['customer_sort'], 'reminder_sort' => $filters['reminder_sort'], 'customer_page' => $crmCustomersPagination['page'], 'reminder_page' => $page])) ?>"><?= $page === $crmRemindersPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Firsatlar</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_opportunities">
            <select name="bulk_opportunity_stage">
                <option value="Yeni">Yeni</option>
                <option value="Gorusme">Gorusme</option>
                <option value="Teklif Verildi">Teklif Verildi</option>
                <option value="Kazanildi">Kazanildi</option>
                <option value="Kaybedildi">Kaybedildi</option>
            </select>
            <button type="submit">Secili Firsatlari Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.crm-opportunity-check').forEach((el)=>el.checked=this.checked)"></th><th>Cari</th><th>Baslik</th><th>Asama</th><th>Kaynak</th><th>Olasilik</th><th>Tutar</th><th>Agirlikli</th><th>Kapanis</th></tr></thead>
                <tbody>
                <?php foreach ($opportunities as $opportunity): ?>
                    <tr>
                        <td><input class="crm-opportunity-check" type="checkbox" name="opportunity_ids[]" value="<?= (int) $opportunity['id'] ?>"></td>
                        <td><?= app_h($opportunity['company_name'] ?: $opportunity['full_name'] ?: '-') ?></td>
                        <td><?= app_h($opportunity['title']) ?></td>
                        <td><?= app_h($opportunity['stage']) ?></td>
                        <td><small><?= app_h((string) ($opportunity['source_channel'] ?: '-')) ?><br><?= app_h((string) ($opportunity['source_campaign'] ?: '-')) ?></small></td>
                        <td><small>%<?= (int) $opportunity['probability_score'] ?><br><?= app_h((string) ($opportunity['probability_note'] ?: '-')) ?></small></td>
                        <td><?= number_format((float) $opportunity['amount'], 2, ',', '.') ?></td>
                        <td><?= number_format((float) $opportunity['weighted_amount'], 2, ',', '.') ?></td>
                        <td><?= app_h($opportunity['expected_close_date'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
    </div>
</section>
