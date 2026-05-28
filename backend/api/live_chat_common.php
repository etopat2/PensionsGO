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

    return (string)$_SESSION['userId'];
}

function liveChatEnsureTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_live_chat_messages (
            chat_message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_id VARCHAR(100) NOT NULL,
            recipient_id VARCHAR(100) NOT NULL,
            message_kind ENUM('text','voice','attachment') NOT NULL DEFAULT 'text',
            message_text TEXT DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            file_size INT DEFAULT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            reply_to_message_id BIGINT UNSIGNED DEFAULT NULL,
            reaction_emoji VARCHAR(24) DEFAULT NULL,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at TIMESTAMP NULL DEFAULT NULL,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (chat_message_id),
            KEY idx_live_chat_pair (sender_id, recipient_id, chat_message_id),
            KEY idx_live_chat_recipient (recipient_id, is_read, chat_message_id)
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
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'read_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_messages', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL AFTER edited_at');
    liveChatAddColumnIfMissing($conn, 'tb_live_chat_calls', 'answered_at', 'TIMESTAMP NULL DEFAULT NULL AFTER created_at');
    $conn->query("
        ALTER TABLE tb_live_chat_signals
        MODIFY signal_type ENUM('offer','answer','ice','hangup','call_accept','video_request','video_accept','video_decline') NOT NULL
    ");

    foreach (['tb_live_chat_messages', 'tb_live_chat_message_reads', 'tb_live_chat_presence', 'tb_live_chat_calls', 'tb_live_chat_signals', 'tb_live_chat_groups', 'tb_live_chat_group_members', 'tb_live_chat_polls', 'tb_live_chat_poll_options', 'tb_live_chat_poll_votes'] as $tableName) {
        liveChatNormalizeTableCollation($conn, $tableName);
    }

    if ($conn->errno) {
        throw new RuntimeException('Unable to prepare live chat storage: ' . $conn->error);
    }
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
