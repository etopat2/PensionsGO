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
        $callStmt = $conn->prepare("SELECT caller_id, callee_id FROM tb_live_chat_calls WHERE call_id = ? LIMIT 1");
        $callStmt->bind_param('s', $callId);
        $callStmt->execute();
        $callRow = $callStmt->get_result()->fetch_assoc();
        $callStmt->close();
        if (!$callRow || !in_array($userId, [$callRow['caller_id'], $callRow['callee_id']], true) || !in_array($recipientId, [$callRow['caller_id'], $callRow['callee_id']], true) || $recipientId === $userId) {
            throw new RuntimeException('Invalid call participant.');
        }
        if ($signalType === 'remote_mute_request' && $callRow['caller_id'] !== $userId) {
            throw new RuntimeException('Only the call host can control a participant microphone.');
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
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
          AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
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
