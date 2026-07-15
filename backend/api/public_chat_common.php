<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/chat_shared_common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$publicChatScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
$publicChatReadAndCloseSession = in_array($publicChatScriptName, [
    'public_chat_agent.php',
    'public_chat_poll.php',
    'public_chat_send.php',
    'public_chat_typing.php',
    'public_chat_upload.php',
    'public_chat_feedback.php',
    'public_chat_end.php'
], true);

if (session_status() === PHP_SESSION_NONE) {
    if ($publicChatReadAndCloseSession) {
        session_start(['read_and_close' => true]);
    } else {
        session_start();
    }
}

const PUBLIC_CHAT_CATEGORIES = [
    'Pension application status',
    'Retirement benefits',
    'Gratuity',
    'Monthly pension',
    'Arrears',
    'Life certificate',
    'Date of birth correction',
    'Payroll/payment issue',
    'Document requirements',
    'General inquiry',
    'Complaint',
    'Technical support'
];

const PUBLIC_CHAT_SCHEMA_VERSION = '20260714c';

function publicChatJson(array $payload, int $status = 200): void
{
    chatSharedJson($payload, $status);
}

function publicChatClean(?string $value, int $max = 255): string
{
    return chatSharedClean($value, $max);
}

function publicChatCleanMessage(?string $value, int $max = 2000): string
{
    return chatSharedCleanMessage($value, $max);
}

function publicChatClientIp(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        $raw = trim((string)($_SERVER[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $parts = explode(',', $raw);
        return publicChatClean($parts[0] ?? $raw, 64);
    }
    return '';
}

function publicChatDerivedVisitorLocation(): array
{
    $ip = publicChatClientIp();
    $country = publicChatClean((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_NAME'] ?? ''), 80);
    $city = publicChatClean((string)($_SERVER['HTTP_X_GEO_CITY'] ?? $_SERVER['GEOIP_CITY'] ?? ''), 80);
    $isLocal = $ip === '' || in_array($ip, ['::1', '127.0.0.1', 'localhost'], true) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0;
    $district = $city !== '' ? $city : ($country !== '' ? $country : ($isLocal ? 'Local network' : 'IP detected'));
    $parts = [];
    if ($city !== '') {
        $parts[] = $city;
    }
    if ($country !== '' && $country !== $city) {
        $parts[] = $country;
    }
    if ($ip !== '') {
        $parts[] = 'IP ' . $ip;
    }
    return [
        'district' => publicChatClean($district, 120),
        'location' => publicChatClean(implode(' / ', $parts) ?: 'Visitor IP not available', 180)
    ];
}

function publicChatTypingRows(mysqli $conn, int $sessionId, string $peerType): array
{
    $peerType = $peerType === 'agent' ? 'agent' : 'visitor';
    $stmt = $conn->prepare("
        SELECT actor_type, actor_id, actor_name
        FROM public_chat_typing
        WHERE session_id = ?
          AND actor_type = ?
          AND updated_at >= DATE_SUB(NOW(), INTERVAL 4 SECOND)
        ORDER BY updated_at DESC
        LIMIT 3
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('is', $sessionId, $peerType);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn($row) => [
        'type' => (string)($row['actor_type'] ?? ''),
        'id' => (string)($row['actor_id'] ?? ''),
        'name' => (string)($row['actor_name'] ?? 'Someone')
    ], $rows);
}

function publicChatSchemaReady(mysqli $conn): bool
{
    $version = $conn->real_escape_string(PUBLIC_CHAT_SCHEMA_VERSION);
    $result = $conn->query("SELECT setting_value FROM tb_app_settings WHERE setting_key = 'public_chat_schema_version' LIMIT 1");
    if (!$result) {
        return false;
    }
    $row = $result->fetch_assoc();
    $result->close();
    return hash_equals($version, (string)($row['setting_value'] ?? ''));
}

function publicChatMarkSchemaReady(mysqli $conn): void
{
    if (function_exists('setAppSetting')) {
        setAppSetting($conn, 'public_chat_schema_version', PUBLIC_CHAT_SCHEMA_VERSION);
        return;
    }
    $version = $conn->real_escape_string(PUBLIC_CHAT_SCHEMA_VERSION);
    $conn->query("
        INSERT INTO tb_app_settings (setting_key, setting_value)
        VALUES ('public_chat_schema_version', '{$version}')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
}

function publicChatEnsureTables(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (publicChatSchemaReady($conn)) {
        $ready = true;
        return;
    }

    $sqlFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'public_live_chat.sql';
    if (is_file($sqlFile)) {
        $sql = (string)file_get_contents($sqlFile);
        if ($sql !== '') {
            $queries = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($queries as $query) {
                if ($query !== '' && stripos($query, 'CREATE TABLE') !== false) {
                    $conn->query($query);
                }
            }
        }
    }

    $defaults = [
        'public_chat_enabled' => '1',
        'public_chat_public_pages_enabled' => '1',
        'public_chat_pensioner_portal_enabled' => '1',
        'public_chat_attachments_enabled' => '0',
        'public_chat_auto_assign_enabled' => '0',
        'public_chat_home_enabled' => '1',
        'public_chat_about_enabled' => '1',
        'public_chat_faq_enabled' => '1',
        'public_chat_podcast_enabled' => '1',
        'public_chat_feedback_page_enabled' => '1',
        'public_chat_terms_enabled' => '1',
        'public_chat_offline_message' => 'Public live support is currently unavailable. Please leave a message and the pensions team will follow up.',
        'public_chat_welcome_text' => 'Welcome to UPS PensionsGo public support. How can we help?',
        'public_chat_consent_text' => 'I consent to UPS PensionsGo using these details to respond to this support request.',
        'public_chat_working_hours' => '08:00-17:00',
        'public_chat_max_active_chats_per_agent' => '5',
        'public_chat_allowed_attachment_types' => 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,gif,webp,mp3,wav,ogg,m4a,webm,mp4,mov',
        'public_chat_max_attachment_size_mb' => '5',
        'public_chat_transcript_enabled' => '1',
        'public_chat_feedback_enabled' => '1',
        'public_chat_rate_limit_start_per_10min' => '5',
        'public_chat_rate_limit_messages_per_5min' => '20',
        'public_chat_max_message_length' => '2000',
        'public_chat_poll_interval_ms' => '1000',
        'public_chat_voice_scan_enabled' => '0'
    ];
    if (function_exists('ensureAppSettingsTable')) {
        ensureAppSettingsTable($conn);
    }
    $expandedAttachmentTypes = $defaults['public_chat_allowed_attachment_types'];
    $attachmentDefaultStmt = $conn->prepare("
        UPDATE tb_app_settings
        SET setting_value = ?, updated_at = NOW()
        WHERE setting_key = 'public_chat_allowed_attachment_types'
          AND setting_value IN ('pdf,jpg,jpeg,png,doc,docx', 'pdf,doc,docx,jpg,jpeg,png')
    ");
    if ($attachmentDefaultStmt) {
        $attachmentDefaultStmt->bind_param('s', $expandedAttachmentTypes);
        $attachmentDefaultStmt->execute();
        $attachmentDefaultStmt->close();
    }
    $pollDefaultStmt = $conn->prepare("UPDATE tb_app_settings SET setting_value = '1000', updated_at = NOW() WHERE setting_key = 'public_chat_poll_interval_ms' AND setting_value IN ('150', '250', '350', '900')");
    if ($pollDefaultStmt) {
        $pollDefaultStmt->execute();
        $pollDefaultStmt->close();
    }
    foreach ($defaults as $key => $value) {
        if (getAppSetting($conn, $key) === null) {
            setAppSetting($conn, $key, $value);
        }
    }

    publicChatAddColumnIfMissing($conn, 'public_chat_sessions', 'subject', "`subject` varchar(220) DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_sessions', 'consent_accepted', "`consent_accepted` tinyint(1) NOT NULL DEFAULT 0");
    publicChatAddColumnIfMissing($conn, 'public_chat_sessions', 'outcome', "`outcome` varchar(120) DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_sessions', 'first_response_at', "`first_response_at` datetime DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'message_kind', "`message_kind` enum('text','attachment','voice') NOT NULL DEFAULT 'text'");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'delivered_at', "`delivered_at` timestamp NULL DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'is_read', "`is_read` tinyint(1) NOT NULL DEFAULT 0");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'read_at', "`read_at` timestamp NULL DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'edited_at', "`edited_at` timestamp NULL DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'deleted_at', "`deleted_at` timestamp NULL DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'reaction_emoji', "`reaction_emoji` varchar(24) DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_messages', 'client_nonce', "`client_nonce` varchar(80) DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_agents', 'last_seen_at', "`last_seen_at` datetime DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_attachments', 'uploaded_by', "`uploaded_by` varchar(100) DEFAULT NULL");
    $conn->query("UPDATE public_chat_attachments SET mime_type = 'audio/webm' WHERE LOWER(file_name) LIKE '%.webm' AND (LOWER(COALESCE(mime_type, '')) LIKE 'audio/%' OR LOWER(COALESCE(mime_type, '')) = 'video/webm')");

    $conn->query("
        CREATE TABLE IF NOT EXISTS public_chat_typing (
            typing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            actor_type ENUM('visitor','agent') NOT NULL,
            actor_id VARCHAR(100) NOT NULL DEFAULT '',
            actor_name VARCHAR(160) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (typing_id),
            UNIQUE KEY uniq_public_chat_typing_actor (session_id, actor_type, actor_id),
            KEY idx_public_chat_typing_session (session_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    foreach ([
        'can_handle_public_chat' => "`can_handle_public_chat` tinyint(1) NOT NULL DEFAULT 1",
        'can_accept_chat' => "`can_accept_chat` tinyint(1) NOT NULL DEFAULT 1",
        'can_transfer_chat' => "`can_transfer_chat` tinyint(1) NOT NULL DEFAULT 0",
        'can_escalate_chat' => "`can_escalate_chat` tinyint(1) NOT NULL DEFAULT 0",
        'can_close_chat' => "`can_close_chat` tinyint(1) NOT NULL DEFAULT 1",
        'can_view_all_chats' => "`can_view_all_chats` tinyint(1) NOT NULL DEFAULT 0",
        'can_view_reports' => "`can_view_reports` tinyint(1) NOT NULL DEFAULT 0",
        'can_manage_canned_responses' => "`can_manage_canned_responses` tinyint(1) NOT NULL DEFAULT 0",
        'can_manage_chat_settings' => "`can_manage_chat_settings` tinyint(1) NOT NULL DEFAULT 0"
    ] as $column => $definition) {
        publicChatAddColumnIfMissing($conn, 'public_chat_agents', $column, $definition);
    }
    publicChatAddColumnIfMissing($conn, 'public_chat_agents', 'availability_status', "`availability_status` enum('online','busy','away','offline') NOT NULL DEFAULT 'offline'");

    $conn->query("ALTER TABLE public_chat_tickets MODIFY status enum('New','Assigned','In progress','Awaiting public user','Escalated','Resolved','Closed','Reopened') NOT NULL DEFAULT 'New'");
    publicChatAddColumnIfMissing($conn, 'public_chat_tickets', 'ticket_type', "`ticket_type` varchar(60) NOT NULL DEFAULT 'Follow-up required'");
    publicChatAddColumnIfMissing($conn, 'public_chat_tickets', 'resolution_notes', "`resolution_notes` text DEFAULT NULL");

    publicChatAddColumnIfMissing($conn, 'public_chat_escalations', 'priority', "`priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'high'");
    publicChatAddColumnIfMissing($conn, 'public_chat_escalations', 'escalation_time', "`escalation_time` datetime NOT NULL DEFAULT current_timestamp()");
    publicChatAddColumnIfMissing($conn, 'public_chat_escalations', 'resolution_deadline', "`resolution_deadline` datetime DEFAULT NULL");
    publicChatAddColumnIfMissing($conn, 'public_chat_escalations', 'outcome', "`outcome` text DEFAULT NULL");

    $conn->query("
        CREATE TABLE IF NOT EXISTS public_chat_rate_limits (
            rate_key VARCHAR(160) NOT NULL,
            action_name VARCHAR(60) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rate_key, action_name),
            KEY idx_public_chat_rate_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    publicChatAddIndexIfMissing($conn, 'public_chat_messages', 'idx_public_chat_messages_delivery', 'session_id, sender_type, delivered_at, message_id');
    publicChatAddIndexIfMissing($conn, 'public_chat_messages', 'idx_public_chat_messages_read', 'session_id, sender_type, is_read, message_id');
    publicChatAddIndexIfMissing($conn, 'public_chat_messages', 'idx_public_chat_messages_session_id', 'session_id, message_id, deleted_at');
    publicChatAddIndexIfMissing($conn, 'public_chat_sessions', 'idx_public_chat_live_status', 'status, closed_at, assigned_agent_id, created_at');
    publicChatAddIndexIfMissing($conn, 'public_chat_agents', 'idx_public_chat_agent_live', 'is_enabled, can_handle_public_chat, availability_status, last_seen_at');
    publicChatMarkSchemaReady($conn);

    $ready = true;
}

function publicChatAddColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void
{
    chatSharedAddColumnIfMissing($conn, $table, $column, $definition);
}

function publicChatAddIndexIfMissing(mysqli $conn, string $table, string $index, string $columns): void
{
    chatSharedAddIndexIfMissing($conn, $table, $index, $columns);
}

function publicChatJsonInput(): array
{
    return chatSharedJsonInput();
}

function publicChatLoadSession(mysqli $conn, int $sessionId): array
{
    if ($sessionId <= 0) {
        publicChatJson(['success' => false, 'message' => 'Chat session is required.'], 400);
    }
    $stmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load chat session.'], 500);
    }
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    return $session;
}

function publicChatResolveActor(mysqli $conn, int $sessionId, string $token, bool $asAgent, bool $allowUnassignedAgent = false, bool $allowLinkedRecordAccess = false): array
{
    if ($asAgent) {
        $agentId = publicChatRequireAgent($conn);
        $agentProfile = publicChatAgentProfile($conn, $agentId);
        $session = publicChatLoadSession($conn, $sessionId);
        if (!publicChatAgentCanAccessSession($session, $agentId, $agentProfile, $allowUnassignedAgent)
            && (!$allowLinkedRecordAccess || !publicChatAgentHasLinkedRecordAccess($conn, $sessionId, $agentId))) {
            publicChatJson(['success' => false, 'message' => 'You are not permitted to access this public chat.'], 403);
        }
        return [
            'session' => $session,
            'profile' => $agentProfile,
            'sender_type' => 'agent',
            'sender_id' => $agentId,
            'sender_name' => (string)($_SESSION['userName'] ?? 'Chat Agent')
        ];
    }

    $session = publicChatVerifyVisitorSession($conn, $sessionId, $token);
    return [
        'session' => $session,
        'profile' => [],
        'sender_type' => 'visitor',
        'sender_id' => null,
        'sender_name' => (string)($session['visitor_name'] ?? 'Visitor')
    ];
}

function publicChatNormalizeMessage(array $row, string $viewerType): array
{
    $senderType = (string)($row['sender_type'] ?? '');
    $isOwn = $senderType === $viewerType;
    $readAt = $row['read_at'] ?? null;
    $deliveredAt = $row['delivered_at'] ?? null;
    return [
        'message_id' => (int)($row['message_id'] ?? 0),
        'id' => (int)($row['message_id'] ?? 0),
        'sender_type' => $senderType,
        'sender_id' => $row['sender_id'] ?? null,
        'sender_name' => (string)($row['sender_name'] ?? ($senderType === 'agent' ? 'Chat Agent' : 'Visitor')),
        'message_text' => (string)($row['message_text'] ?? ''),
        'message_kind' => (string)($row['message_kind'] ?? 'text'),
        'is_internal' => (int)($row['is_internal'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'delivered_at' => $deliveredAt,
        'is_read' => (int)($row['is_read'] ?? 0) === 1,
        'read_at' => $readAt,
        'edited_at' => $row['edited_at'] ?? null,
        'deleted_at' => $row['deleted_at'] ?? null,
        'reaction_emoji' => (string)($row['reaction_emoji'] ?? ''),
        'client_nonce' => (string)($row['client_nonce'] ?? ''),
        'isOwn' => $isOwn,
        'receiptStatus' => $isOwn ? (!empty($readAt) ? 'read' : (!empty($deliveredAt) ? 'delivered' : 'sent')) : 'received'
    ];
}

function publicChatInsertMessage(mysqli $conn, int $sessionId, string $senderType, ?string $senderId, string $senderName, string $messageText, string $messageKind = 'text', string $clientNonce = ''): int
{
    $stmt = $conn->prepare("
        INSERT INTO public_chat_messages (session_id, sender_type, sender_id, sender_name, message_text, message_kind, client_nonce)
        VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''))
    ");
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to save message.'], 500);
    }
    $stmt->bind_param('issssss', $sessionId, $senderType, $senderId, $senderName, $messageText, $messageKind, $clientNonce);
    $stmt->execute();
    $messageId = (int)$stmt->insert_id;
    $stmt->close();
    return $messageId;
}

function publicChatFetchMessages(mysqli $conn, int $sessionId, int $lastId, string $viewerType): array
{
    $stmt = $conn->prepare("
        SELECT message_id, sender_type, sender_id, sender_name, message_text, message_kind, is_internal,
               delivered_at, is_read, read_at, edited_at, deleted_at, reaction_emoji, client_nonce, created_at
        FROM public_chat_messages
        WHERE session_id = ?
          AND message_id > ?
          AND is_internal = 0
          AND deleted_at IS NULL
        ORDER BY message_id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $sessionId, $lastId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn($row) => publicChatNormalizeMessage($row, $viewerType), $rows);
}

function publicChatMarkSeen(mysqli $conn, int $sessionId, string $viewerType): void
{
    $senderType = $viewerType === 'agent' ? 'visitor' : 'agent';
    $stmt = $conn->prepare("
        UPDATE public_chat_messages
        SET delivered_at = COALESCE(delivered_at, NOW()),
            is_read = 1,
            read_at = COALESCE(read_at, NOW())
        WHERE session_id = ?
          AND sender_type = ?
          AND is_internal = 0
          AND deleted_at IS NULL
          AND (is_read = 0 OR read_at IS NULL OR delivered_at IS NULL)
    ");
    if ($stmt) {
        $stmt->bind_param('is', $sessionId, $senderType);
        $stmt->execute();
        $stmt->close();
    }
}

function publicChatReceiptRows(mysqli $conn, int $sessionId, string $viewerType): array
{
    $senderType = $viewerType === 'agent' ? 'agent' : 'visitor';
    $stmt = $conn->prepare("
        SELECT message_id, delivered_at, is_read, read_at
        FROM public_chat_messages
        WHERE session_id = ?
          AND sender_type = ?
          AND is_internal = 0
          AND deleted_at IS NULL
        ORDER BY message_id DESC
        LIMIT 150
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('is', $sessionId, $senderType);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn($row) => [
        'id' => (int)($row['message_id'] ?? 0),
        'message_id' => (int)($row['message_id'] ?? 0),
        'delivered_at' => $row['delivered_at'] ?? null,
        'is_read' => (int)($row['is_read'] ?? 0) === 1,
        'read_at' => $row['read_at'] ?? null,
        'receiptStatus' => !empty($row['read_at']) ? 'read' : (!empty($row['delivered_at']) ? 'delivered' : 'sent')
    ], $rows);
}

function publicChatSettingBool(mysqli $conn, string $key, bool $default = true): bool
{
    publicChatEnsureTables($conn);
    return getAppSettingBool($conn, $key, $default);
}

function publicChatSettingInt(mysqli $conn, string $key, int $default, int $min, int $max): int
{
    publicChatEnsureTables($conn);
    return max($min, min($max, getAppSettingInt($conn, $key, $default)));
}

function publicChatSettings(mysqli $conn): array
{
    publicChatEnsureTables($conn);
    $defaults = [
        'public_chat_enabled' => '1',
        'public_chat_public_pages_enabled' => '1',
        'public_chat_home_enabled' => '1',
        'public_chat_about_enabled' => '1',
        'public_chat_faq_enabled' => '1',
        'public_chat_podcast_enabled' => '1',
        'public_chat_feedback_page_enabled' => '1',
        'public_chat_terms_enabled' => '1',
        'public_chat_pensioner_portal_enabled' => '1',
        'public_chat_attachments_enabled' => '0',
        'public_chat_poll_interval_ms' => '1000',
        'public_chat_max_message_length' => '2000',
        'public_chat_offline_message' => 'Public live support is currently unavailable. Please leave a message and the pensions team will follow up.',
        'public_chat_welcome_text' => 'Welcome to UPS PensionsGo public support. How can we help?',
        'public_chat_consent_text' => 'I consent to UPS PensionsGo using these details to respond to this support request.',
        'public_chat_working_hours' => '08:00-17:00',
        'public_chat_feedback_enabled' => '1',
        'public_chat_transcript_enabled' => '1',
        'public_chat_allowed_attachment_types' => 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,gif,webp,mp3,wav,ogg,m4a,webm,mp4,mov',
        'public_chat_max_attachment_size_mb' => '5'
    ];
    $values = $defaults;
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM tb_app_settings WHERE setting_key IN ($placeholders)");
    if ($stmt) {
        $types = str_repeat('s', count($keys));
        $refs = [$types];
        foreach ($keys as $i => $key) {
            $refs[] = &$keys[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $key = (string)($row['setting_key'] ?? '');
            if (array_key_exists($key, $values)) {
                $values[$key] = (string)($row['setting_value'] ?? '');
            }
        }
        $stmt->close();
    }
    $bool = static function (string $key, bool $default) use ($values): bool {
        $raw = $values[$key] ?? ($default ? '1' : '0');
        $flag = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $flag === null ? $raw === '1' : (bool)$flag;
    };
    $int = static function (string $key, int $default, int $min, int $max) use ($values): int {
        $raw = $values[$key] ?? (string)$default;
        $value = is_numeric($raw) ? (int)$raw : $default;
        return max($min, min($max, $value));
    };
    return [
        'enabled' => $bool('public_chat_enabled', true),
        'publicPagesEnabled' => $bool('public_chat_public_pages_enabled', true),
        'homeEnabled' => $bool('public_chat_home_enabled', true),
        'aboutEnabled' => $bool('public_chat_about_enabled', true),
        'faqEnabled' => $bool('public_chat_faq_enabled', true),
        'podcastEnabled' => $bool('public_chat_podcast_enabled', true),
        'feedbackPageEnabled' => $bool('public_chat_feedback_page_enabled', true),
        'termsEnabled' => $bool('public_chat_terms_enabled', true),
        'pensionerPortalEnabled' => $bool('public_chat_pensioner_portal_enabled', true),
        'attachmentsEnabled' => $bool('public_chat_attachments_enabled', false),
        'pollIntervalMs' => $int('public_chat_poll_interval_ms', 1000, 1000, 5000),
        'maxMessageLength' => $int('public_chat_max_message_length', 2000, 250, 5000),
        'offlineMessage' => $values['public_chat_offline_message'],
        'welcomeText' => $values['public_chat_welcome_text'],
        'consentText' => $values['public_chat_consent_text'],
        'workingHours' => $values['public_chat_working_hours'],
        'feedbackEnabled' => $bool('public_chat_feedback_enabled', true),
        'transcriptEnabled' => $bool('public_chat_transcript_enabled', true),
        'allowedAttachmentTypes' => $values['public_chat_allowed_attachment_types'],
        'maxAttachmentSizeMb' => $int('public_chat_max_attachment_size_mb', 5, 1, 25),
        'categories' => PUBLIC_CHAT_CATEGORIES
    ];
}

function publicChatCsrfToken(): string
{
    if (empty($_SESSION['public_chat_csrf_token'])) {
        $_SESSION['public_chat_csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['public_chat_csrf_token'];
}

function publicChatReleaseSessionLock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function publicChatRequireCsrf(?string $token): void
{
    $expected = publicChatCsrfToken();
    if ($expected === '' || !hash_equals($expected, (string)$token)) {
        publicChatJson(['success' => false, 'message' => 'Security token is invalid. Refresh the page and try again.'], 403);
    }
}

function publicChatRateLimit(mysqli $conn, string $action, int $maxAttempts, int $windowSeconds): void
{
    publicChatEnsureTables($conn);
    $ip = publicChatClientIp();
    $rateKey = hash('sha256', ($ip ?: 'unknown') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $windowSeconds = max(30, min(3600, $windowSeconds));

    $stmt = $conn->prepare("SELECT attempt_count, UNIX_TIMESTAMP(window_start) AS window_start_ts FROM public_chat_rate_limits WHERE rate_key = ? AND action_name = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $rateKey, $action);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = time();
    if (!$row || ($now - (int)($row['window_start_ts'] ?? 0)) >= $windowSeconds) {
        $count = 1;
        $reset = $conn->prepare("
            INSERT INTO public_chat_rate_limits (rate_key, action_name, attempt_count, window_start)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempt_count = 1, window_start = NOW(), updated_at = NOW()
        ");
        if ($reset) {
            $reset->bind_param('ss', $rateKey, $action);
            $reset->execute();
            $reset->close();
        }
        return;
    }

    $count = (int)($row['attempt_count'] ?? 0) + 1;
    if ($count > $maxAttempts) {
        publicChatJson(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.'], 429);
    }
    $update = $conn->prepare("UPDATE public_chat_rate_limits SET attempt_count = ?, updated_at = NOW() WHERE rate_key = ? AND action_name = ?");
    if ($update) {
        $update->bind_param('iss', $count, $rateKey, $action);
        $update->execute();
        $update->close();
    }
}

function publicChatAvailableAgents(mysqli $conn): int
{
    publicChatEnsureTables($conn);
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM public_chat_agents
        WHERE is_enabled = 1
          AND can_handle_public_chat = 1
          AND (agent_status = 'available' OR availability_status = 'online')
          AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
    ");
    return $result ? (int)($result->fetch_assoc()['total'] ?? 0) : 0;
}

function publicChatAgentPresenceIsFresh(mysqli $conn, string $userId): bool
{
    publicChatEnsureTables($conn);
    if ($userId === '') {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT 1
        FROM public_chat_agents
        WHERE user_id = ?
          AND is_enabled = 1
          AND can_handle_public_chat = 1
          AND (agent_status = 'available' OR availability_status = 'online')
          AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $fresh = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $fresh;
}

function publicChatAgentProfile(mysqli $conn, string $userId = ''): array
{
    publicChatEnsureTables($conn);
    $userId = $userId !== '' ? $userId : (string)($_SESSION['userId'] ?? '');
    $role = publicChatCurrentRole($conn);
    $isSystem = in_array($role, ['super_admin', 'admin', 'oc_pen'], true);
    $profile = [
        'user_id' => $userId,
        'is_system' => $isSystem,
        'can_handle_public_chat' => $isSystem,
        'can_accept_chat' => $isSystem,
        'can_transfer_chat' => $isSystem,
        'can_escalate_chat' => $isSystem,
        'can_close_chat' => $isSystem,
        'can_view_all_chats' => $isSystem,
        'can_view_reports' => $isSystem,
        'can_manage_canned_responses' => $isSystem,
        'can_manage_chat_settings' => $isSystem,
        'availability_status' => 'offline',
        'last_seen_at' => null,
        'max_active_chats' => 5,
        'is_enabled' => $isSystem ? 1 : 0
    ];
    if ($userId === '') {
        return $profile;
    }
    $stmt = $conn->prepare("SELECT * FROM public_chat_agents WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            foreach ($profile as $key => $value) {
                if (array_key_exists($key, $row)) {
                    $profile[$key] = in_array($key, ['user_id', 'availability_status', 'last_seen_at'], true) ? (string)$row[$key] : (int)$row[$key];
                }
            }
            $profile['is_enabled'] = (int)($row['is_enabled'] ?? $row['enabled'] ?? $profile['is_enabled']);
        }
    }
    if ($isSystem) {
        foreach (['can_handle_public_chat','can_accept_chat','can_transfer_chat','can_escalate_chat','can_close_chat','can_view_all_chats','can_view_reports','can_manage_canned_responses','can_manage_chat_settings'] as $key) {
            $profile[$key] = 1;
        }
        $profile['is_enabled'] = 1;
    }
    return $profile;
}

function publicChatAgentCan(mysqli $conn, string $capability): bool
{
    if (!publicChatCanManage($conn)) {
        return false;
    }
    $profile = publicChatAgentProfile($conn);
    return !empty($profile['is_system']) || (!empty($profile['is_enabled']) && !empty($profile['can_handle_public_chat']) && !empty($profile[$capability]));
}

function publicChatRequireCapability(mysqli $conn, string $capability, string $message = 'Public chat permission denied.'): void
{
    if (!publicChatAgentCan($conn, $capability)) {
        publicChatJson(['success' => false, 'message' => $message], 403);
    }
}

function publicChatAvailability(mysqli $conn, ?array $settings = null): array
{
    $settings = $settings ?? publicChatSettings($conn);
    $availableAgents = $settings['enabled'] ? publicChatAvailableAgents($conn) : 0;
    $payload = [
        'enabled' => (bool)$settings['enabled'],
        'online' => (bool)$settings['enabled'] && $availableAgents > 0,
        'availableAgents' => $availableAgents,
        'offlineMessage' => (string)$settings['offlineMessage']
    ];
    if (!empty($_SESSION['userId']) && publicChatCanManage($conn)) {
        $profile = publicChatAgentProfile($conn);
        $fresh = publicChatAgentPresenceIsFresh($conn, (string)$_SESSION['userId']);
        $payload['agent'] = [
            'status' => $fresh ? (string)($profile['availability_status'] ?? 'offline') : 'offline',
            'online' => $fresh,
            'enabled' => !empty($profile['is_enabled'])
        ];
    }
    return $payload;
}

function publicChatAttachmentIsPreviewable(string $mime, string $fileName): bool
{
    return chatSharedAttachmentIsPreviewable($mime, $fileName);
}

function publicChatPlaybackMime(string $mime, string $fileName): string
{
    return chatSharedPlaybackMime($mime, $fileName);
}

function publicChatStoreUpload(mysqli $conn, array $file, string $kind, int $sessionId, string $senderType, ?string $senderId, string $senderName): array
{
    $isVoice = $kind === 'voice';
    $staffAttachmentAllowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'wav', 'ogg', 'm4a', 'webm', 'mp4', 'mov'];
    $allowedRaw = getAppSettingString($conn, 'public_chat_allowed_attachment_types', implode(',', $staffAttachmentAllowed));
    $allowed = array_values(array_unique(array_merge(
        array_filter(array_map(static fn($item) => strtolower(trim($item)), explode(',', $allowedRaw))),
        $staffAttachmentAllowed
    )));
    $voiceAllowed = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'];
    if ($isVoice) {
        $allowed = array_values(array_unique(array_merge($allowed, $voiceAllowed)));
    }

    $allowedMimes = $isVoice
        ? ['audio/', 'video/', 'application/ogg', 'application/octet-stream']
        : ['image/', 'audio/', 'video/', 'application/ogg', 'application/pdf', 'application/msword', 'application/vnd.', 'application/zip', 'text/plain', 'text/csv', 'application/octet-stream'];
    $maxMb = publicChatSettingInt($conn, 'public_chat_max_attachment_size_mb', 5, 1, 25);

    $upload = chatSharedStoreUpload($conn, $file, [
        'storage_dir' => 'public_chat',
        'prefix' => ($isVoice ? 'public_chat_voice_' : 'public_chat_') . $sessionId,
        'allowed_extensions' => $allowed,
        'allowed_mimes' => $allowedMimes,
        'label' => $isVoice ? 'Voice note' : 'Attachment',
        'storage_context' => 'public_chat_' . ($isVoice ? 'voice' : 'attachment'),
        'max_bytes' => $maxMb * 1024 * 1024,
        'max_bytes_message' => 'Attachment must be ' . $maxMb . ' MB or smaller.',
        'scan_setting_key' => $isVoice ? 'public_chat_voice_scan_enabled' : 'attachment_scan_enabled',
        'scanned_by' => $senderId,
        'scanned_by_name' => $senderName,
        'scanned_by_role' => $senderType,
        'content_validator' => static function (array $validated) use ($isVoice): void {
            $extension = strtolower((string)($validated['extension'] ?? ''));
            $tmpPath = (string)($validated['tmp_name'] ?? '');
            $lowerMime = strtolower((string)($validated['mime_type'] ?? 'application/octet-stream'));

            if ($extension === 'docx') {
                if (!class_exists('ZipArchive')) {
                    throw new RuntimeException('DOCX preview support is not enabled on this server.');
                }
                $zip = new ZipArchive();
                $opened = $zip->open($tmpPath);
                if ($opened !== true || $zip->locateName('word/document.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
                    if ($opened === true) {
                        $zip->close();
                    }
                    throw new RuntimeException('DOCX file is not a valid Word document.');
                }
                $zip->close();
            } elseif ($extension === 'doc') {
                $handle = fopen($tmpPath, 'rb');
                $signature = $handle ? fread($handle, 8) : '';
                if ($handle) {
                    fclose($handle);
                }
                if ($signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
                    throw new RuntimeException('DOC file is not a valid Word document.');
                }
            } elseif (!$isVoice && in_array($extension, ['jpg', 'jpeg', 'png'], true) && !str_starts_with($lowerMime, 'image/')) {
                throw new RuntimeException('Image attachment content is not valid.');
            } elseif (!$isVoice && $extension === 'pdf' && $lowerMime !== 'application/pdf') {
                throw new RuntimeException('PDF attachment content is not valid.');
            } elseif ($isVoice && !str_starts_with($lowerMime, 'audio/') && !str_starts_with($lowerMime, 'video/') && $lowerMime !== 'application/octet-stream') {
                throw new RuntimeException('Voice note content is not valid.');
            }
        }
    ]);

    $upload['mime_type'] = publicChatPlaybackMime((string)($upload['mime_type'] ?? ''), (string)($upload['file_name'] ?? ''));
    return $upload;
}

function publicChatMediaTokenSecret(): string
{
    if (function_exists('getSignedSessionCookieSecret')) {
        return getSignedSessionCookieSecret();
    }
    return hash('sha256', __DIR__ . '|public-chat-media');
}

function publicChatAgentMediaToken(int $attachmentId, int $sessionId, string $agentSessionId, string $agentUserId): string
{
    return hash_hmac('sha256', $attachmentId . '|' . $sessionId . '|' . $agentSessionId . '|' . $agentUserId, publicChatMediaTokenSecret());
}

function publicChatUserCanManageById(mysqli $conn, string $userId): bool
{
    if ($userId === '') {
        return false;
    }
    $stmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    $role = function_exists('resolveRoleAccessKey') ? resolveRoleAccessKey($conn, (string)$row['userRole']) : normalizeRoleKey((string)$row['userRole']);
    if (in_array($role, ['super_admin', 'admin', 'oc_pen'], true)) {
        return true;
    }
    $agentStmt = $conn->prepare("SELECT can_handle_public_chat, is_enabled FROM public_chat_agents WHERE user_id = ? LIMIT 1");
    if ($agentStmt) {
        $agentStmt->bind_param('s', $userId);
        $agentStmt->execute();
        $agent = $agentStmt->get_result()->fetch_assoc();
        $agentStmt->close();
        if ($agent && (int)($agent['is_enabled'] ?? 0) === 1 && (int)($agent['can_handle_public_chat'] ?? 0) === 1) {
            return true;
        }
    }
    return function_exists('getEffectiveUserPermission')
        && getEffectiveUserPermission($conn, $userId, (string)$row['userRole'], 'public_chat.agent');
}

function publicChatVerifyAgentMediaToken(mysqli $conn, int $attachmentId, int $sessionId, string $agentSessionId, string $agentUserId, string $token): bool
{
    if ($attachmentId <= 0 || $sessionId <= 0 || $agentSessionId === '' || $agentUserId === '' || $token === '') {
        return false;
    }
    if (!hash_equals(publicChatAgentMediaToken($attachmentId, $sessionId, $agentSessionId, $agentUserId), $token)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM tb_user_sessions WHERE session_id = ? AND user_id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $agentSessionId, $agentUserId);
    $stmt->execute();
    $active = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $active && publicChatUserCanManageById($conn, $agentUserId);
}

function publicChatAttachMessageFiles(mysqli $conn, array $messages, bool $asAgent, ?string $visitorToken = null): array
{
    if (empty($messages)) {
        return $messages;
    }
    $ids = array_values(array_filter(array_map(static fn($row) => (int)($row['message_id'] ?? 0), $messages)));
    if (empty($ids)) {
        return $messages;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("
        SELECT attachment_id, session_id, message_id, uploaded_by_type, file_name, file_size, mime_type, created_at
        FROM public_chat_attachments
        WHERE message_id IN ($placeholders)
        ORDER BY attachment_id ASC
    ");
    if (!$stmt) {
        return $messages;
    }
    $refs = [$types];
    foreach ($ids as $i => $value) {
        $refs[] = &$ids[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byMessage = [];
    foreach ($rows as $row) {
        $attachmentId = (int)($row['attachment_id'] ?? 0);
        $sessionId = (int)($row['session_id'] ?? 0);
        $fileName = (string)($row['file_name'] ?? 'Attachment');
        $mime = publicChatPlaybackMime((string)($row['mime_type'] ?? ''), $fileName);
        $params = ['attachment_id' => $attachmentId];
        if (!$asAgent) {
            $params['session_id'] = $sessionId;
            $params['token'] = (string)$visitorToken;
        } else {
            $agentSessionId = (string)($_SESSION['session_id'] ?? '');
            $agentUserId = (string)($_SESSION['userId'] ?? '');
            if ($agentSessionId !== '' && $agentUserId !== '') {
                $params['session_id'] = $sessionId;
                $params['agent_session_id'] = $agentSessionId;
                $params['agent_user_id'] = $agentUserId;
                $params['agent_token'] = publicChatAgentMediaToken($attachmentId, $sessionId, $agentSessionId, $agentUserId);
            }
        }
        $viewUrl = 'public_chat_view_attachment.php?' . http_build_query($params);
        $previewUrl = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'docx'
            ? 'public_chat_preview_attachment.php?' . http_build_query($params)
            : $viewUrl;
        $downloadUrl = $viewUrl . '&download=1';
        $byMessage[(int)$row['message_id']][] = [
            'attachment_id' => $attachmentId,
            'file_name' => $fileName,
            'file_size' => (int)($row['file_size'] ?? 0),
            'mime_type' => $mime,
            'uploaded_by_type' => (string)($row['uploaded_by_type'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_voice' => str_starts_with(strtolower($mime), 'audio/') || in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['webm', 'ogg', 'mp3', 'wav', 'm4a'], true),
            'previewable' => publicChatAttachmentIsPreviewable($mime, $fileName),
            'view_url' => $viewUrl,
            'preview_url' => $previewUrl,
            'download_url' => $downloadUrl
        ];
    }
    foreach ($messages as &$message) {
        $message['attachments'] = $byMessage[(int)($message['message_id'] ?? 0)] ?? [];
        if (($message['message_kind'] ?? 'text') === 'voice') {
            foreach ($message['attachments'] as &$attachment) {
                $attachment['is_voice'] = true;
            }
            unset($attachment);
        }
        if (!empty($message['attachments']) && (($message['message_kind'] ?? 'text') === 'text')) {
            $message['message_kind'] = !empty($message['attachments'][0]['is_voice']) ? 'voice' : 'attachment';
        }
    }
    unset($message);
    return $messages;
}

function publicChatCurrentRole(mysqli $conn): string
{
    $role = (string)($_SESSION['userRole'] ?? '');
    return function_exists('resolveRoleAccessKey') ? resolveRoleAccessKey($conn, $role) : normalizeRoleKey($role);
}

function publicChatCanManage(mysqli $conn): bool
{
    if (empty($_SESSION['userId'])) {
        return false;
    }
    $role = publicChatCurrentRole($conn);
    if (in_array($role, ['super_admin', 'admin', 'oc_pen'], true)) {
        return true;
    }
    publicChatEnsureTables($conn);
    $userId = (string)$_SESSION['userId'];
    $stmt = $conn->prepare("SELECT can_handle_public_chat, is_enabled FROM public_chat_agents WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && (int)($row['is_enabled'] ?? 0) === 1 && (int)($row['can_handle_public_chat'] ?? 0) === 1) {
            return true;
        }
    }
    return function_exists('getEffectiveUserPermission')
        && getEffectiveUserPermission($conn, (string)$_SESSION['userId'], (string)($_SESSION['userRole'] ?? ''), 'public_chat.agent');
}

function publicChatCanSupervise(mysqli $conn): bool
{
    if (empty($_SESSION['userId'])) {
        return false;
    }
    $role = publicChatCurrentRole($conn);
    if (in_array($role, ['super_admin', 'admin', 'oc_pen'], true)) {
        return true;
    }
    return function_exists('getEffectiveUserPermission')
        && getEffectiveUserPermission($conn, (string)$_SESSION['userId'], (string)($_SESSION['userRole'] ?? ''), 'public_chat.supervise');
}

function publicChatRequireAgent(mysqli $conn): string
{
    publicChatEnsureTables($conn);
    if (!publicChatCanManage($conn)) {
        publicChatJson(['success' => false, 'message' => 'Public chat access denied.'], empty($_SESSION['userId']) ? 401 : 403);
    }
    return (string)$_SESSION['userId'];
}

function publicChatAgentCanAccessSession(array $session, string $agentId, array $agentProfile, bool $allowUnassigned = false): bool
{
    if (!empty($agentProfile['can_view_all_chats']) || !empty($agentProfile['is_system'])) {
        return true;
    }
    $assignedAgent = trim((string)($session['assigned_agent_id'] ?? ''));
    if ($assignedAgent !== '') {
        return hash_equals($assignedAgent, $agentId);
    }
    if (!$allowUnassigned) {
        return false;
    }
    $status = (string)($session['status'] ?? '');
    if (in_array($status, ['waiting', 'active', 'assigned'], true)) {
        return true;
    }
    return $status === 'closed' && stripos((string)($session['close_reason'] ?? ''), 'Offline message') === 0;
}

function publicChatAgentHasLinkedRecordAccess(mysqli $conn, int $sessionId, string $agentId): bool
{
    if ($sessionId <= 0 || $agentId === '') {
        return false;
    }
    $ticketStmt = $conn->prepare("
        SELECT 1
        FROM public_chat_tickets
        WHERE session_id = ?
          AND (created_by = ? OR assigned_to = ?)
        LIMIT 1
    ");
    if ($ticketStmt) {
        $ticketStmt->bind_param('iss', $sessionId, $agentId, $agentId);
        $ticketStmt->execute();
        $hasTicket = (bool)$ticketStmt->get_result()->fetch_assoc();
        $ticketStmt->close();
        if ($hasTicket) {
            return true;
        }
    }
    $escalationStmt = $conn->prepare("
        SELECT 1
        FROM public_chat_escalations
        WHERE session_id = ?
          AND (escalated_by = ? OR escalated_to = ?)
        LIMIT 1
    ");
    if (!$escalationStmt) {
        return false;
    }
    $escalationStmt->bind_param('iss', $sessionId, $agentId, $agentId);
    $escalationStmt->execute();
    $hasEscalation = (bool)$escalationStmt->get_result()->fetch_assoc();
    $escalationStmt->close();
    return $hasEscalation;
}

function publicChatRequireAgentSessionAccess(array $session, string $agentId, array $agentProfile, bool $allowUnassigned = false, string $message = 'This chat is assigned to another handler.'): void
{
    if (!publicChatAgentCanAccessSession($session, $agentId, $agentProfile, $allowUnassigned)) {
        publicChatJson(['success' => false, 'message' => $message], 403);
    }
}

function publicChatAudit(mysqli $conn, ?int $sessionId, string $action, array $details = []): void
{
    publicChatEnsureTables($conn);
    $actorId = (string)($_SESSION['userId'] ?? '');
    $actorName = (string)($_SESSION['userName'] ?? ($details['actor_name'] ?? 'Public Visitor'));
    $actorRole = (string)($_SESSION['userRole'] ?? ($details['actor_role'] ?? 'public'));
    $json = $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $ip = publicChatClientIp();
    $ua = publicChatClean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
    $stmt = $conn->prepare("
        INSERT INTO public_chat_audit_logs (session_id, actor_user_id, actor_name, actor_role, action, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $sid = $sessionId ?: null;
        $stmt->bind_param('isssssss', $sid, $actorId, $actorName, $actorRole, $action, $json, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }
}

function publicChatGenerateReference(mysqli $conn): string
{
    $prefix = 'CHAT-' . date('Y-md');
    $stmt = $conn->prepare("
        SELECT chat_reference
        FROM public_chat_sessions
        WHERE chat_reference LIKE CONCAT(?, '%')
        ORDER BY CAST(SUBSTRING(chat_reference, ?) AS UNSIGNED) DESC
        LIMIT 1
        FOR UPDATE
    ");
    $next = 1;
    if ($stmt) {
        $start = strlen($prefix) + 1;
        $stmt->bind_param('si', $prefix, $start);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $suffix = substr((string)$row['chat_reference'], strlen($prefix));
            $next = max(1, ((int)$suffix) + 1);
        }
    }
    return $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT);
}

function publicChatResolvePensionerContext(mysqli $conn, array $input = []): array
{
    $context = [
        'matched' => false,
        'profile' => [],
        'registry' => null,
        'claims' => [],
        'lifeCertificate' => null,
        'payroll' => null,
        'accountActivity' => null,
        'documents' => ['count' => 0]
    ];

    $userId = publicChatClean((string)($input['user_id'] ?? ($_SESSION['userId'] ?? '')), 100);
    $forceNo = publicChatClean((string)($input['force_number'] ?? ''), 80);
    $pensionerNo = publicChatClean((string)($input['pensioner_number'] ?? ''), 80);
    $phone = publicChatClean((string)($input['phone_number'] ?? ''), 50);
    $email = strtolower(publicChatClean((string)($input['email'] ?? ''), 160));
    $metaRegNo = '';
    $metaStaffId = 0;

    if ($userId !== '') {
        $userStmt = $conn->prepare("SELECT userId, userTitle, userName, userEmail, phoneNo, other FROM tb_users WHERE userId = ? LIMIT 1");
        if ($userStmt) {
            $userStmt->bind_param('s', $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();
            if ($user) {
                $context['profile'] = [
                    'name' => (string)$user['userName'],
                    'email' => (string)$user['userEmail'],
                    'phone' => (string)$user['phoneNo']
                ];
                $meta = json_decode((string)($user['other'] ?? ''), true);
                if (is_array($meta)) {
                    $metaRegNo = publicChatClean((string)($meta['regNo'] ?? ''), 80);
                    $metaStaffId = (int)($meta['staffdue_id'] ?? 0);
                }
            }
        }
    }

    $regNoCandidates = array_values(array_unique(array_filter([$pensionerNo, $forceNo, $metaRegNo])));
    $registry = null;
    if ($regNoCandidates) {
        $placeholders = implode(',', array_fill(0, count($regNoCandidates), '?'));
        $types = str_repeat('s', count($regNoCandidates));
        $sql = "
            SELECT fr.*, sd.prisonUnit, sd.submissionStatus, sd.appnStatus AS staff_appn_status
            FROM tb_fileregistry fr
            LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
            WHERE fr.regNo IN ($placeholders) OR fr.computerNo IN ($placeholders) OR fr.supplierNo IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $bindValues = array_merge($regNoCandidates, $regNoCandidates, $regNoCandidates);
            $bindTypes = str_repeat($types, 3);
            $refs = [$bindTypes];
            foreach ($bindValues as $i => $value) {
                $refs[] = &$bindValues[$i];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $registry = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }

    if (!$registry && ($email !== '' || $phone !== '' || $metaStaffId > 0)) {
        $sql = "
            SELECT fr.*, sd.prisonUnit, sd.submissionStatus, sd.appnStatus AS staff_appn_status
            FROM tb_fileregistry fr
            LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
            WHERE (? <> '' AND LOWER(COALESCE(fr.applicant_email, sd.applicant_email, '')) = ?)
               OR (? <> '' AND COALESCE(fr.telNo, sd.telNo, '') = ?)
               OR (? <> 0 AND sd.id = ?)
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssssii', $email, $email, $phone, $phone, $metaStaffId, $metaStaffId);
            $stmt->execute();
            $registry = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }

    if (!$registry) {
        return $context;
    }

    $regNo = (string)($registry['regNo'] ?? '');
    $context['matched'] = true;
    $context['registry'] = [
        'regNo' => $regNo,
        'computerNo' => (string)($registry['computerNo'] ?? ''),
        'supplierNo' => (string)($registry['supplierNo'] ?? ''),
        'name' => trim((string)($registry['title'] ?? '') . ' ' . (string)($registry['sName'] ?? '') . ' ' . (string)($registry['fName'] ?? '')),
        'phone' => (string)($registry['telNo'] ?? ''),
        'email' => (string)($registry['applicant_email'] ?? ''),
        'districtOrAddress' => (string)($registry['address'] ?? ''),
        'station' => (string)($registry['prisonUnit'] ?? ''),
        'payrollStatus' => (string)($registry['payrollStatus'] ?? ''),
        'lifeCertificate' => (string)($registry['lifeCertificate'] ?? ''),
        'livingStatus' => (string)($registry['livingStatus'] ?? ''),
        'payType' => (string)($registry['payType'] ?? ''),
        'retirementType' => (string)($registry['retirementType'] ?? ''),
        'retirementDate' => (string)($registry['retirementDate'] ?? ''),
        'birthDate' => (string)($registry['birthDate'] ?? ''),
        'monthlySalary' => (float)($registry['monthlySalary'] ?? 0),
        'reducedPension' => (float)($registry['reducedPension'] ?? 0),
        'fullPension' => (float)($registry['fullPension'] ?? 0),
        'gratuity' => (float)($registry['gratuity'] ?? 0),
        'bankName' => (string)($registry['bank_name'] ?? ''),
        'bankAccount' => (string)($registry['bank_account'] ?? '')
    ];

    $claimStmt = $conn->prepare("
        SELECT claim_type, COUNT(*) AS entries, SUM(expected_amount) AS expected_total, SUM(paid_amount) AS paid_total, SUM(balance_amount) AS balance_total
        FROM tb_arrears_ledger
        WHERE regNo = ?
        GROUP BY claim_type
        ORDER BY balance_total DESC
        LIMIT 10
    ");
    if ($claimStmt) {
        $claimStmt->bind_param('s', $regNo);
        $claimStmt->execute();
        $result = $claimStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $context['claims'][] = $row;
        }
        $claimStmt->close();
    }

    $lifeStmt = $conn->prepare("SELECT submission_year, status, submitted_at, submitted_by FROM tb_life_certificate_submissions WHERE regNo = ? ORDER BY submission_year DESC, submitted_at DESC LIMIT 3");
    if ($lifeStmt) {
        $lifeStmt->bind_param('s', $regNo);
        $lifeStmt->execute();
        $context['lifeCertificate'] = $lifeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lifeStmt->close();
    }

    $payrollStmt = $conn->prepare("SELECT payroll_status, amount, financial_year_label, quarter_label, payroll_year, payroll_month, updated_at FROM tb_registry_payroll_monthly_status WHERE regNo = ? ORDER BY payroll_year DESC, payroll_month DESC, updated_at DESC LIMIT 1");
    if ($payrollStmt) {
        $payrollStmt->bind_param('s', $regNo);
        $payrollStmt->execute();
        $context['payroll'] = $payrollStmt->get_result()->fetch_assoc() ?: null;
        $payrollStmt->close();
    }

    $docStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tb_staff_documents WHERE regNo = ?");
    if ($docStmt) {
        $docStmt->bind_param('s', $regNo);
        $docStmt->execute();
        $context['documents']['count'] = (int)(($docStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $docStmt->close();
    }

    $context['accountActivity'] = [
        'registryLinked' => true,
        'payrollStatus' => (string)($registry['payrollStatus'] ?? ''),
        'applicationStatus' => (string)($registry['staff_appn_status'] ?? ''),
        'submissionStatus' => (string)($registry['submissionStatus'] ?? '')
    ];

    $historyStmt = $conn->prepare("
        SELECT chat_reference, visitor_name, inquiry_category, subject, status, created_at, closed_at
        FROM public_chat_sessions
        WHERE (COALESCE(force_number, '') <> '' AND force_number = ?)
           OR (COALESCE(pensioner_number, '') <> '' AND pensioner_number = ?)
           OR (COALESCE(phone_number, '') <> '' AND phone_number = ?)
        ORDER BY created_at DESC
        LIMIT 5
    ");
    if ($historyStmt) {
        $historyStmt->bind_param('sss', $forceNo, $pensionerNo, $phone);
        $historyStmt->execute();
        $context['priorChats'] = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();
    }

    $context['life_certificate'] = $context['lifeCertificate'];
    $context['account_activity'] = $context['accountActivity'];
    $context['prior_chats'] = $context['priorChats'] ?? [];

    return $context;
}

function publicChatSessionToken(int $sessionId, string $reference): string
{
    return hash('sha256', $sessionId . '|' . $reference . '|' . session_id());
}

function publicChatVerifyVisitorSession(mysqli $conn, int $sessionId, string $token): array
{
    $stmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load chat session.'], 500);
    }
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session || !hash_equals(publicChatSessionToken($sessionId, (string)$session['chat_reference']), $token)) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    return $session;
}
