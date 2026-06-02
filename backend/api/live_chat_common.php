<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function liveChatRequireStaff(mysqli $conn): string
{
    if (!isset($_SESSION['userId'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }

    if (function_exists('currentUserCanAccessMessagingModule') && !currentUserCanAccessMessagingModule()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Messaging access denied']);
        exit;
    }

    if (function_exists('getAppSettingBool') && !getAppSettingBool($conn, 'live_chat_enabled', true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Live chat is currently disabled by the administrator.']);
        exit;
    }

    $userId = (string)$_SESSION['userId'];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return $userId;
}

function liveChatFeatureEnabled(mysqli $conn, string $key, bool $default = true): bool
{
    return function_exists('getAppSettingBool') ? getAppSettingBool($conn, $key, $default) : $default;
}

function liveChatSettingInt(mysqli $conn, string $key, int $default, int $min, int $max): int
{
    $value = function_exists('getAppSettingInt') ? getAppSettingInt($conn, $key, $default) : $default;
    return max($min, min($max, (int)$value));
}

function liveChatEnsureTables(mysqli $conn): void
{
    $schemaMarker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'live_chat_schema_ready_v9.json';
    if (is_file($schemaMarker)) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_messages (
            chat_message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_id VARCHAR(100) NOT NULL,
            recipient_id VARCHAR(100) NOT NULL,
            message_kind ENUM('text','voice','attachment','call') NOT NULL DEFAULT 'text',
            message_text TEXT DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            file_size INT DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            reply_to_message_id BIGINT UNSIGNED DEFAULT NULL,
            reaction_emoji VARCHAR(24) DEFAULT NULL,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            delivered_at TIMESTAMP NULL DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at TIMESTAMP NULL DEFAULT NULL,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            admin_deleted_at TIMESTAMP NULL DEFAULT NULL,
            admin_deleted_by VARCHAR(100) DEFAULT NULL,
            admin_delete_reason VARCHAR(255) DEFAULT NULL,
            client_nonce VARCHAR(80) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (chat_message_id),
            KEY idx_live_chat_pair (sender_id, recipient_id, chat_message_id),
            KEY idx_live_chat_recipient (recipient_id, is_read, chat_message_id),
            KEY idx_live_chat_nonce (client_nonce)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_groups (
            group_id VARCHAR(80) NOT NULL,
            group_name VARCHAR(150) NOT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id),
            KEY idx_live_chat_groups_creator (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_group_members (
            group_id VARCHAR(80) NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            added_by VARCHAR(100) DEFAULT NULL,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id),
            KEY idx_live_chat_group_members_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_presence (
            user_id VARCHAR(100) NOT NULL,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status VARCHAR(30) NOT NULL DEFAULT 'online',
            current_context VARCHAR(120) DEFAULT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_typing (
            user_id VARCHAR(100) NOT NULL,
            peer_type ENUM('user','group') NOT NULL DEFAULT 'user',
            peer_id VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, peer_type, peer_id),
            KEY idx_live_chat_typing_peer (peer_type, peer_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_calls (
            call_id VARCHAR(80) NOT NULL,
            caller_id VARCHAR(100) NOT NULL,
            callee_id VARCHAR(100) NOT NULL,
            call_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
            status ENUM('ringing','accepted','rejected','ended','missed') NOT NULL DEFAULT 'ringing',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (call_id),
            KEY idx_live_chat_calls_callee (callee_id, status, updated_at),
            KEY idx_live_chat_calls_caller (caller_id, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_message_reads (
            chat_message_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (chat_message_id, user_id),
            KEY idx_live_chat_message_reads_user (user_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_message_deletions (
            chat_message_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (chat_message_id, user_id),
            KEY idx_live_chat_message_deletions_user (user_id, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_message_audit_archive (
            archive_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_message_id BIGINT UNSIGNED NOT NULL,
            sender_id VARCHAR(100) NOT NULL,
            recipient_id VARCHAR(100) NOT NULL,
            message_kind VARCHAR(30) NOT NULL DEFAULT 'text',
            message_text LONGTEXT DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            file_size INT DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            reply_to_message_id BIGINT UNSIGNED DEFAULT NULL,
            reaction_emoji VARCHAR(24) DEFAULT NULL,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            delivered_at TIMESTAMP NULL DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at TIMESTAMP NULL DEFAULT NULL,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            admin_deleted_at TIMESTAMP NULL DEFAULT NULL,
            admin_deleted_by VARCHAR(100) DEFAULT NULL,
            client_nonce VARCHAR(80) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT NULL,
            archived_by VARCHAR(100) DEFAULT NULL,
            archive_reason VARCHAR(80) NOT NULL DEFAULT 'delete',
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (archive_id),
            UNIQUE KEY uniq_live_chat_archive_message (chat_message_id),
            KEY idx_live_chat_archive_sender (sender_id),
            KEY idx_live_chat_archive_recipient (recipient_id),
            KEY idx_live_chat_archive_archived (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_signals (
            signal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            call_id VARCHAR(80) NOT NULL,
            sender_id VARCHAR(100) NOT NULL,
            recipient_id VARCHAR(100) NOT NULL,
            signal_type ENUM('offer','answer','ice','hangup') NOT NULL,
            payload_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (signal_id),
            KEY idx_live_chat_signals_recipient (recipient_id, call_id, signal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_polls (
            poll_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_message_id BIGINT UNSIGNED NOT NULL,
            question VARCHAR(500) NOT NULL,
            allow_multiple TINYINT(1) NOT NULL DEFAULT 0,
            priority VARCHAR(30) NOT NULL DEFAULT 'normal',
            tag VARCHAR(80) DEFAULT NULL,
            closes_at DATETIME DEFAULT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (poll_id),
            UNIQUE KEY idx_live_chat_poll_message (chat_message_id),
            KEY idx_live_chat_poll_creator (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_poll_options (
            option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (option_id),
            KEY idx_live_chat_poll_options_poll (poll_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_poll_votes (
            vote_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (vote_id),
            UNIQUE KEY idx_live_chat_poll_vote_once (poll_id, option_id, user_id),
            KEY idx_live_chat_poll_votes_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'reply_to_message_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER mime_type');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'reaction_emoji', 'VARCHAR(24) DEFAULT NULL AFTER reply_to_message_id');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'is_pinned', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reaction_emoji');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'delivered_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_pinned');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'read_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL AFTER edited_at');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'admin_deleted_at', 'TIMESTAMP NULL DEFAULT NULL AFTER deleted_at');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'admin_deleted_by', 'VARCHAR(100) DEFAULT NULL AFTER admin_deleted_at');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'admin_delete_reason', 'VARCHAR(255) DEFAULT NULL AFTER admin_deleted_by');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'client_nonce', 'VARCHAR(80) DEFAULT NULL AFTER admin_delete_reason');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_calls', 'answered_at', 'TIMESTAMP NULL DEFAULT NULL AFTER created_at');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_calls', 'call_message_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER ended_at');
    $conn->query("ALTER TABLE tb_live_chat_messages MODIFY message_kind ENUM('text','voice','attachment','call') NOT NULL DEFAULT 'text'");
    liveChatAddIndexIfMissing($conn, 'tb_live_chat_messages', 'idx_live_chat_delivery_fast', 'sender_id, recipient_id, delivered_at, chat_message_id');
    liveChatAddIndexIfMissing($conn, 'tb_live_chat_messages', 'idx_live_chat_group_fast', 'recipient_id, admin_deleted_at, chat_message_id');
    liveChatAddIndexIfMissing($conn, 'tb_live_chat_signals', 'idx_live_chat_signals_fast', 'call_id, recipient_id, signal_id');
    $conn->query("
        ALTER TABLE tb_live_chat_signals
        MODIFY signal_type ENUM('offer','answer','ice','hangup','call_accept','video_request','video_accept','video_decline','mic_state','remote_mute_request','peer_connected','peer_disconnected') NOT NULL
    ");

    foreach (['tb_live_chat_messages', 'tb_live_chat_message_reads', 'tb_live_chat_message_deletions', 'tb_live_chat_message_audit_archive', 'tb_live_chat_presence', 'tb_live_chat_typing', 'tb_live_chat_calls', 'tb_live_chat_signals', 'tb_live_chat_groups', 'tb_live_chat_group_members', 'tb_live_chat_polls', 'tb_live_chat_poll_options', 'tb_live_chat_poll_votes'] as $tableName) {
        liveChatNormalizeTableCollation($conn, $tableName);
    }

    if ($conn->errno) {
        throw new RuntimeException('Unable to prepare live chat storage: ' . $conn->error);
    }

    $markerDir = dirname($schemaMarker);
    if (!is_dir($markerDir)) {
        @mkdir($markerDir, 0775, true);
    }
    @file_put_contents($schemaMarker, json_encode([
        'ready' => true,
        'schema' => 9,
        'checked_at' => date('c')
    ], JSON_PRETTY_PRINT), LOCK_EX);
}

function liveChatCacheDir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'live_chat';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function liveChatThreadCacheKey(string $peerType, string $a, string $b): string
{
    if ($peerType === 'group') {
        return 'group_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $b);
    }
    $ids = [$a, $b];
    sort($ids, SORT_STRING);
    return 'user_' . sha1($ids[0] . '|' . $ids[1]);
}

function liveChatAppendCacheMessage(string $peerType, string $senderId, string $recipientId, array $message): void
{
    $dir = liveChatCacheDir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $key = liveChatThreadCacheKey($peerType, $senderId, $recipientId);
    $path = $dir . DIRECTORY_SEPARATOR . $key . '.jsonl';
    $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    $maxBytes = 512000;
    clearstatcache(true, $path);
    if (@filesize($path) > $maxBytes) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -400);
        @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }
}

function liveChatRemoveCacheMessage(string $peerType, string $senderId, string $recipientId, int $messageId): void
{
    if ($messageId <= 0) {
        return;
    }
    $path = liveChatCacheDir() . DIRECTORY_SEPARATOR . liveChatThreadCacheKey($peerType, $senderId, $recipientId) . '.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $message = json_decode($line, true);
        if (is_array($message) && (int)($message['id'] ?? 0) === $messageId) {
            continue;
        }
        $kept[] = $line;
    }
    @file_put_contents($path, $kept ? implode(PHP_EOL, $kept) . PHP_EOL : '', LOCK_EX);
}

function liveChatArchiveMessage(mysqli $conn, int $messageId, string $archivedBy = '', string $reason = 'delete'): void
{
    if ($messageId <= 0) {
        return;
    }
    if (!liveChatFeatureEnabled($conn, 'live_chat_admin_archive_enabled', true)) {
        return;
    }
    $stmt = $conn->prepare("
        INSERT IGNORE INTO tb_live_chat_message_audit_archive (
            chat_message_id, sender_id, recipient_id, message_kind, message_text,
            file_name, file_path, file_size, mime_type, reply_to_message_id,
            reaction_emoji, is_pinned, delivered_at, is_read, read_at,
            edited_at, deleted_at, admin_deleted_at, admin_deleted_by,
            client_nonce, created_at, archived_by, archive_reason
        )
        SELECT
            chat_message_id, sender_id, recipient_id, message_kind, message_text,
            file_name, file_path, file_size, mime_type, reply_to_message_id,
            reaction_emoji, is_pinned, delivered_at, is_read, read_at,
            edited_at, deleted_at, admin_deleted_at, admin_deleted_by,
            client_nonce, created_at, ?, ?
        FROM tb_live_chat_messages
        WHERE chat_message_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return;
    }
    $safeReason = substr(trim($reason) ?: 'delete', 0, 80);
    $stmt->bind_param('ssi', $archivedBy, $safeReason, $messageId);
    $stmt->execute();
    $stmt->close();
}

function liveChatReadCacheMessages(string $peerType, string $userId, string $peerId, int $sinceId): array
{
    $path = liveChatCacheDir() . DIRECTORY_SEPARATOR . liveChatThreadCacheKey($peerType, $userId, $peerId) . '.jsonl';
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $messages = [];
    foreach (array_slice($lines, -250) as $line) {
        $message = json_decode($line, true);
        if (!is_array($message)) {
            continue;
        }
        $id = (int)($message['id'] ?? 0);
        if ($id <= $sinceId) {
            continue;
        }
        if ($peerType === 'group') {
            if ((string)($message['recipientId'] ?? '') !== $peerId) {
                continue;
            }
        } elseif (!(
            ((string)($message['senderId'] ?? '') === $userId && (string)($message['recipientId'] ?? '') === $peerId)
            || ((string)($message['senderId'] ?? '') === $peerId && (string)($message['recipientId'] ?? '') === $userId)
        )) {
            continue;
        }
        $message['isOwn'] = (string)($message['senderId'] ?? '') === $userId;
        $messages[] = $message;
    }
    return $messages;
}

function liveChatAddColumnIfMissing(mysqli $conn, string $tableName, string $columnName, string $definition): void
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['total'] ?? 0) === 0) {
        $conn->query("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $definition");
    }
}

function liveChatAddIndexIfMissing(mysqli $conn, string $tableName, string $indexName, string $columns): void
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $tableName, $indexName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['total'] ?? 0) === 0) {
        $conn->query("ALTER TABLE `$tableName` ADD INDEX `$indexName` ($columns)");
    }
}

function liveChatNormalizeTableCollation(mysqli $conn, string $tableName): void
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS mismatched_columns
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLLATION_NAME IS NOT NULL
          AND COLLATION_NAME <> 'utf8mb4_general_ci'
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['mismatched_columns'] ?? 0) > 0) {
        $conn->query("ALTER TABLE `$tableName` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
}

function liveChatJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function liveChatCanReachUser(mysqli $conn, string $userId): bool
{
    $stmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return function_exists('canRoleAccessMessagingModule')
        ? canRoleAccessMessagingModule((string)$row['userRole'])
        : !in_array((string)$row['userRole'], ['pensioner', 'user'], true);
}

function liveChatIsUserOnline(mysqli $conn, string $userId, int $freshSeconds = 45): bool
{
    $seconds = max(10, min(300, $freshSeconds));
    $stmt = $conn->prepare("
        SELECT 1
        FROM tb_live_chat_presence
        WHERE user_id = ?
          AND status = 'online'
          AND last_seen >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $online = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $online;
}

function liveChatCanAccessGroup(mysqli $conn, string $groupId, string $userId): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM tb_live_chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $groupId, $userId);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

function liveChatTouchPresence(mysqli $conn, string $userId, string $context = 'messages'): void
{
    $stmt = $conn->prepare("
        INSERT INTO tb_live_chat_presence (user_id, status, current_context)
        VALUES (?, 'online', ?)
        ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP, status = 'online', current_context = VALUES(current_context)
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $userId, $context);
        $stmt->execute();
        $stmt->close();
    }
}

function liveChatRespond(array $payload): void
{
    echo json_encode($payload);
    exit;
}
