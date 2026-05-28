<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $data = $_SERVER['REQUEST_METHOD'] === 'POST' ? liveChatJsonInput() : $_GET;
    $action = trim((string)($data['action'] ?? 'members'));
    $groupId = trim((string)($data['group_id'] ?? ''));
    if ($groupId === '' || !liveChatCanAccessGroup($conn, $groupId, $userId)) {
        throw new RuntimeException('Select a valid group.');
    }

    $groupStmt = $conn->prepare("SELECT group_id, group_name, created_by FROM tb_live_chat_groups WHERE group_id = ? LIMIT 1");
    $groupStmt->bind_param('s', $groupId);
    $groupStmt->execute();
    $group = $groupStmt->get_result()->fetch_assoc();
    $groupStmt->close();
    if (!$group) {
        throw new RuntimeException('Group not found.');
    }
    $isAdmin = (string)$group['created_by'] === $userId;

    if ($action === 'leave') {
        if ($isAdmin) {
            throw new RuntimeException('The group creator cannot leave their own group. Transfer or remove the group through administration if needed.');
        }
        $leaveStmt = $conn->prepare("DELETE FROM tb_live_chat_group_members WHERE group_id = ? AND user_id = ?");
        $leaveStmt->bind_param('ss', $groupId, $userId);
        $leaveStmt->execute();
        $leaveStmt->close();
        liveChatRespond(['success' => true, 'left' => true]);
    }

    if ($action === 'update') {
        if (!$isAdmin) {
            throw new RuntimeException('Only the group creator can manage members.');
        }
        $memberIds = $data['member_ids'] ?? [];
        if (!is_array($memberIds)) {
            $memberIds = [];
        }
        $memberIds = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $memberIds))));
        $memberIds[] = $userId;
        $memberIds = array_values(array_unique($memberIds));

        $deleteStmt = $conn->prepare("DELETE FROM tb_live_chat_group_members WHERE group_id = ? AND user_id <> ?");
        $deleteStmt->bind_param('ss', $groupId, $userId);
        $deleteStmt->execute();
        $deleteStmt->close();

        $insert = $conn->prepare("INSERT IGNORE INTO tb_live_chat_group_members (group_id, user_id, added_by) VALUES (?, ?, ?)");
        foreach ($memberIds as $memberId) {
            if ($memberId === $userId || liveChatCanReachUser($conn, $memberId)) {
                $insert->bind_param('sss', $groupId, $memberId, $userId);
                $insert->execute();
            }
        }
        $insert->close();
    }

    $membersStmt = $conn->prepare("
        SELECT u.userId, u.userName, u.userRole, u.userEmail, u.userPhoto
        FROM tb_live_chat_group_members gm
        INNER JOIN tb_users u ON u.userId = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY u.userName ASC
    ");
    $membersStmt->bind_param('s', $groupId);
    $membersStmt->execute();
    $membersResult = $membersStmt->get_result();
    $members = [];
    while ($row = $membersResult->fetch_assoc()) {
        $members[] = [
            'userId' => $row['userId'],
            'userName' => $row['userName'],
            'userRole' => $row['userRole'],
            'userRoleLabel' => function_exists('formatRoleLabel') ? formatRoleLabel($conn, (string)$row['userRole']) : ucwords(str_replace('_', ' ', (string)$row['userRole'])),
            'userEmail' => $row['userEmail'],
            'userPhoto' => $row['userPhoto'] ?: 'images/default-user.png',
            'isAdmin' => $row['userId'] === $group['created_by']
        ];
    }
    $membersStmt->close();

    $availableUsers = [];
    if ($isAdmin) {
        $usersStmt = $conn->prepare("
            SELECT userId, userName, userRole, userEmail, userPhoto
            FROM tb_users
            WHERE userRole NOT IN ('pensioner', 'user')
            ORDER BY userName ASC
        ");
        $usersStmt->execute();
        $usersResult = $usersStmt->get_result();
        while ($row = $usersResult->fetch_assoc()) {
            $availableUsers[] = [
                'userId' => $row['userId'],
                'userName' => $row['userName'],
                'userRole' => $row['userRole'],
                'userRoleLabel' => function_exists('formatRoleLabel') ? formatRoleLabel($conn, (string)$row['userRole']) : ucwords(str_replace('_', ' ', (string)$row['userRole'])),
                'userEmail' => $row['userEmail'],
                'userPhoto' => $row['userPhoto'] ?: 'images/default-user.png',
                'isAdmin' => $row['userId'] === $group['created_by']
            ];
        }
        $usersStmt->close();
    }

    liveChatRespond([
        'success' => true,
        'group' => [
            'groupId' => $group['group_id'],
            'groupName' => $group['group_name'],
            'createdBy' => $group['created_by'],
            'isAdmin' => $isAdmin
        ],
        'members' => $members,
        'availableUsers' => $availableUsers
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
