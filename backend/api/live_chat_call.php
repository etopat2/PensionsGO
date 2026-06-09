<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? liveChatJsonInput() : $_GET;
    $action = (string)($data['action'] ?? 'poll');

    if ($action === 'start') {
        $calleeId = trim((string)($data['callee_id'] ?? ''));
        $type = (string)($data['call_type'] ?? 'audio');
        if (!in_array($type, ['audio', 'video'], true)) $type = 'audio';
        if ($type === 'audio' && !liveChatFeatureEnabled($conn, 'live_chat_audio_calls_enabled', true)) {
            throw new RuntimeException('Audio calls are currently disabled.');
        }
        if ($type === 'video' && !liveChatFeatureEnabled($conn, 'live_chat_video_calls_enabled', true)) {
            throw new RuntimeException('Video calls are currently disabled.');
        }
        if ($calleeId === '' || $calleeId === $userId || !liveChatCanReachUser($conn, $calleeId)) {
            throw new RuntimeException('Select a valid staff member to call.');
        }
        $callId = 'call_' . bin2hex(random_bytes(16));
        if (!liveChatIsUserOnline($conn, $calleeId, 45)) {
            $status = 'missed';
            $stmt = $conn->prepare("
                INSERT INTO tb_live_chat_calls (call_id, caller_id, callee_id, call_type, status, ended_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('sssss', $callId, $userId, $calleeId, $type, $status);
            $stmt->execute();
            $stmt->close();
            liveChatCreateCallThreadRecord($conn, $callId);
            liveChatRespond([
                'success' => true,
                'offline' => true,
                'message' => 'The recipient is offline. A missed call record has been added.',
                'call' => [
                    'callId' => $callId,
                    'callerId' => $userId,
                    'calleeId' => $calleeId,
                    'callType' => $type,
                    'status' => 'missed'
                ]
            ]);
        }
        $stmt = $conn->prepare("INSERT INTO tb_live_chat_calls (call_id, caller_id, callee_id, call_type) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $callId, $userId, $calleeId, $type);
        $stmt->execute();
        $stmt->close();
        liveChatRespond(['success' => true, 'call' => ['callId' => $callId, 'callerId' => $userId, 'calleeId' => $calleeId, 'callType' => $type, 'status' => 'ringing']]);
    }

    if ($action === 'update') {
        $callId = trim((string)($data['call_id'] ?? ''));
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['accepted', 'rejected', 'ended', 'missed'], true)) {
            throw new RuntimeException('Invalid call status.');
        }
        $callStmt = $conn->prepare("SELECT caller_id, callee_id, status FROM tb_live_chat_calls WHERE call_id = ? LIMIT 1");
        $callStmt->bind_param('s', $callId);
        $callStmt->execute();
        $callRow = $callStmt->get_result()->fetch_assoc();
        $callStmt->close();
        if (!$callRow) {
            throw new RuntimeException('Call not found.');
        }
        $callerId = (string)($callRow['caller_id'] ?? '');
        $calleeId = (string)($callRow['callee_id'] ?? '');
        if (!in_array((string)$userId, [$callerId, $calleeId], true)) {
            throw new RuntimeException('Invalid call participant.');
        }
        if (in_array($status, ['accepted', 'rejected'], true) && (string)$userId !== $calleeId) {
            throw new RuntimeException('Only the recipient can answer or reject this call.');
        }
        if ($status === 'missed' && (string)$userId !== $callerId) {
            throw new RuntimeException('Only the caller can mark this call as missed.');
        }
        $stmt = $conn->prepare("
            UPDATE tb_live_chat_calls
            SET status = ?,
                answered_at = CASE WHEN ? = 'accepted' THEN COALESCE(answered_at, NOW()) ELSE answered_at END,
                ended_at = CASE WHEN ? IN ('rejected','ended','missed') THEN NOW() ELSE ended_at END
            WHERE call_id = ? AND (caller_id = ? OR callee_id = ?)
        ");
        $stmt->bind_param('ssssss', $status, $status, $status, $callId, $userId, $userId);
        $stmt->execute();
        $stmt->close();
        if (in_array($status, ['rejected', 'ended', 'missed'], true)) {
            liveChatCreateCallThreadRecord($conn, $callId);
        }
        liveChatRespond(['success' => true]);
    }

    if ($action === 'history') {
        $stmt = $conn->prepare("
            SELECT c.call_id, c.caller_id, c.callee_id, c.call_type, c.status, c.created_at, c.answered_at, c.ended_at, c.updated_at,
                   caller.userName AS caller_name, callee.userName AS callee_name
            FROM tb_live_chat_calls c
            LEFT JOIN tb_users caller ON caller.userId = c.caller_id
            LEFT JOIN tb_users callee ON callee.userId = c.callee_id
            WHERE c.caller_id = ? OR c.callee_id = ?
            ORDER BY c.created_at DESC
            LIMIT 80
        ");
        $stmt->bind_param('ss', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $isOutgoing = $row['caller_id'] === $userId;
            $started = strtotime((string)($row['answered_at'] ?: $row['created_at'])) ?: 0;
            $ended = strtotime((string)($row['ended_at'] ?: $row['updated_at'])) ?: 0;
            $history[] = [
                'callId' => $row['call_id'],
                'direction' => $isOutgoing ? 'outgoing' : 'incoming',
                'peerName' => $isOutgoing ? ($row['callee_name'] ?? 'Recipient') : ($row['caller_name'] ?? 'Caller'),
                'callerName' => $row['caller_name'] ?? 'Caller',
                'calleeName' => $row['callee_name'] ?? 'Recipient',
                'callType' => $row['call_type'],
                'status' => $row['status'],
                'createdAt' => $row['created_at'],
                'answeredAt' => $row['answered_at'],
                'endedAt' => $row['ended_at'],
                'durationSeconds' => ($started > 0 && $ended > $started && in_array($row['status'], ['accepted', 'ended'], true)) ? ($ended - $started) : 0
            ];
        }
        $stmt->close();
        liveChatRespond(['success' => true, 'history' => $history]);
    }

    if ($action === 'signal') {
        $callId = trim((string)($data['call_id'] ?? ''));
        $recipientId = trim((string)($data['recipient_id'] ?? ''));
        $signalType = (string)($data['signal_type'] ?? '');
        $payload = $data['payload'] ?? null;
        if ($callId === '' || $recipientId === '' || !in_array($signalType, ['offer', 'answer', 'ice', 'hangup', 'call_accept', 'video_request', 'video_accept', 'video_decline', 'mic_state', 'remote_mute_request', 'peer_connected', 'peer_disconnected'], true)) {
            throw new RuntimeException('Invalid call signal.');
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false || strlen($payloadJson) > 65535) {
            throw new RuntimeException('Call signal payload is invalid or too large.');
        }
        $callStmt = $conn->prepare("SELECT caller_id, callee_id FROM tb_live_chat_calls WHERE call_id = ? LIMIT 1");
        $callStmt->bind_param('s', $callId);
        $callStmt->execute();
        $callRow = $callStmt->get_result()->fetch_assoc();
        $callStmt->close();
        $callerId = (string)($callRow['caller_id'] ?? '');
        $calleeId = (string)($callRow['callee_id'] ?? '');
        if (!$callRow || !in_array((string)$userId, [$callerId, $calleeId], true) || !in_array((string)$recipientId, [$callerId, $calleeId], true) || (string)$recipientId === (string)$userId) {
            throw new RuntimeException('Invalid call participant.');
        }
        if ($signalType === 'remote_mute_request' && $callerId !== (string)$userId) {
            throw new RuntimeException('Only the call host can control a participant microphone.');
        }
        $stmt = $conn->prepare("INSERT INTO tb_live_chat_signals (call_id, sender_id, recipient_id, signal_type, payload_json) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssss', $callId, $userId, $recipientId, $signalType, $payloadJson);
        $stmt->execute();
        $signalId = (int)$conn->insert_id;
        $stmt->close();
        liveChatRespond(['success' => true, 'signal_id' => $signalId]);
    }

    if ($action === 'signals') {
        $callId = trim((string)($data['call_id'] ?? ''));
        $afterId = max(0, (int)($data['after_id'] ?? 0));
        $stmt = $conn->prepare("
            SELECT signal_id, call_id, sender_id, recipient_id, signal_type, payload_json, created_at
            FROM tb_live_chat_signals
            WHERE call_id = ? AND recipient_id = ? AND signal_id > ?
            ORDER BY signal_id ASC
            LIMIT 100
        ");
        $stmt->bind_param('ssi', $callId, $userId, $afterId);
        $stmt->execute();
        $result = $stmt->get_result();
        $signals = [];
        while ($row = $result->fetch_assoc()) {
            $signals[] = [
                'signalId' => (int)$row['signal_id'],
                'callId' => $row['call_id'],
                'senderId' => $row['sender_id'],
                'recipientId' => $row['recipient_id'],
                'signalType' => $row['signal_type'],
                'payload' => json_decode((string)$row['payload_json'], true),
                'createdAt' => $row['created_at']
            ];
        }
        $stmt->close();
        liveChatRespond(['success' => true, 'signals' => $signals]);
    }

    $stmt = $conn->prepare("
        SELECT c.call_id, c.caller_id, c.callee_id, c.call_type, c.status, c.created_at, c.answered_at, c.updated_at,
               caller.userName AS caller_name, callee.userName AS callee_name
        FROM tb_live_chat_calls c
        LEFT JOIN tb_users caller ON caller.userId = c.caller_id
        LEFT JOIN tb_users callee ON callee.userId = c.callee_id
        WHERE (c.caller_id = ? OR c.callee_id = ?)
          AND c.status IN ('ringing','accepted')
          AND (c.status = 'accepted' OR c.updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ORDER BY c.updated_at DESC
        LIMIT 10
    ");
    $stmt->bind_param('ss', $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $calls = [];
    while ($row = $result->fetch_assoc()) {
        $calls[] = [
            'callId' => $row['call_id'],
            'callerId' => $row['caller_id'],
            'calleeId' => $row['callee_id'],
            'callType' => $row['call_type'],
            'status' => $row['status'],
            'callerName' => $row['caller_name'] ?? 'Caller',
            'calleeName' => $row['callee_name'] ?? 'Recipient',
            'createdAt' => $row['created_at'],
            'answeredAt' => $row['answered_at'] ?? null,
            'updatedAt' => $row['updated_at']
        ];
    }
    $stmt->close();
    liveChatRespond(['success' => true, 'calls' => $calls]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}

function liveChatCreateCallThreadRecord(mysqli $conn, string $callId): void
{
    try {
        $conn->begin_transaction();
        $select = $conn->prepare("
            SELECT c.call_id, c.caller_id, c.callee_id, c.call_type, c.status, c.created_at, c.answered_at, c.ended_at, c.updated_at, c.call_message_id,
                   caller.userName AS caller_name, callee.userName AS callee_name
            FROM tb_live_chat_calls c
            LEFT JOIN tb_users caller ON caller.userId = c.caller_id
            LEFT JOIN tb_users callee ON callee.userId = c.callee_id
            WHERE c.call_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        if (!$select) {
            $conn->rollback();
            return;
        }
        $select->bind_param('s', $callId);
        $select->execute();
        $call = $select->get_result()->fetch_assoc();
        $select->close();
        if (!$call || !empty($call['call_message_id']) || !in_array((string)$call['status'], ['rejected', 'ended', 'missed'], true)) {
            $conn->rollback();
            return;
        }

        $started = strtotime((string)($call['answered_at'] ?: $call['created_at'])) ?: 0;
        $ended = strtotime((string)($call['ended_at'] ?: $call['updated_at'])) ?: 0;
        $durationSeconds = ($started > 0 && $ended > $started && in_array((string)$call['status'], ['ended'], true)) ? ($ended - $started) : 0;
        $payload = [
            'callId' => (string)$call['call_id'],
            'callType' => (string)$call['call_type'],
            'status' => (string)$call['status'],
            'callerId' => (string)$call['caller_id'],
            'calleeId' => (string)$call['callee_id'],
            'callerName' => (string)($call['caller_name'] ?: 'Caller'),
            'calleeName' => (string)($call['callee_name'] ?: 'Recipient'),
            'createdAt' => $call['created_at'],
            'answeredAt' => $call['answered_at'],
            'endedAt' => $call['ended_at'] ?: $call['updated_at'],
            'durationSeconds' => $durationSeconds
        ];
        $messageText = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $kind = 'call';

        $insert = $conn->prepare("
            INSERT INTO tb_live_chat_messages (sender_id, recipient_id, message_kind, message_text, delivered_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if (!$insert) {
            $conn->rollback();
            return;
        }
        $insert->bind_param('ssss', $call['caller_id'], $call['callee_id'], $kind, $messageText);
        $insert->execute();
        $messageId = (int)$conn->insert_id;
        $insert->close();
        if ($messageId <= 0) {
            $conn->rollback();
            return;
        }

        $update = $conn->prepare("UPDATE tb_live_chat_calls SET call_message_id = ? WHERE call_id = ? AND call_message_id IS NULL");
        if (!$update) {
            $conn->rollback();
            return;
        }
        $update->bind_param('is', $messageId, $callId);
        $update->execute();
        $update->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
    }
}
