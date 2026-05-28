<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $data = liveChatJsonInput();
    $name = trim((string)($data['group_name'] ?? ''));
    $members = $data['member_ids'] ?? [];
    if (!is_array($members)) {
        $members = [];
    }

    $members = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $members))));
    if ($name === '') {
        throw new RuntimeException('Enter a group name.');
    }
    if (count($members) < 1) {
        throw new RuntimeException('Select at least one group member.');
    }

    $groupId = 'grp_' . bin2hex(random_bytes(12));
    $stmt = $conn->prepare("INSERT INTO tb_live_chat_groups (group_id, group_name, created_by) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Unable to create group.');
    }
    $stmt->bind_param('sss', $groupId, $name, $userId);
    $stmt->execute();
    $stmt->close();

    $insert = $conn->prepare("INSERT IGNORE INTO tb_live_chat_group_members (group_id, user_id, added_by) VALUES (?, ?, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to add group members.');
    }

    $allMembers = array_values(array_unique(array_merge([$userId], $members)));
    foreach ($allMembers as $memberId) {
        if ($memberId === $userId || liveChatCanReachUser($conn, $memberId)) {
            $insert->bind_param('sss', $groupId, $memberId, $userId);
            $insert->execute();
        }
    }
    $insert->close();

    liveChatRespond([
        'success' => true,
        'group' => [
            'groupId' => $groupId,
            'groupName' => $name,
            'createdBy' => $userId,
            'memberCount' => count($allMembers)
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
