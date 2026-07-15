<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$agentId = publicChatRequireAgent($conn);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = $method === 'GET' ? $_GET : json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = publicChatClean($input['action'] ?? 'list', 40);
$agentProfile = publicChatAgentProfile($conn, $agentId);
publicChatReleaseSessionLock();

function publicChatAgentSession(mysqli $conn, int $sessionId): array
{
    $stmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load chat.'], 500);
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

if ($action === 'list') {
    $status = publicChatClean($input['status'] ?? '', 30);
    $where = "WHERE 1=1";
    $types = '';
    $params = [];
    if ($status === 'offline') {
        $where .= " AND pcs.status = 'closed' AND pcs.close_reason LIKE 'Offline message%'";
    } elseif ($status === 'waiting') {
        $where .= " AND pcs.status = 'waiting' AND pcs.closed_at IS NULL AND (pcs.assigned_agent_id IS NULL OR pcs.assigned_agent_id = '')";
    } elseif ($status !== '' && in_array($status, ['waiting', 'active', 'assigned', 'escalated', 'closed'], true)) {
        $where .= " AND pcs.status = ?";
        $types .= 's';
        $params[] = $status;
        if ($status !== 'closed') {
            $where .= " AND pcs.closed_at IS NULL";
        }
    } else {
        $where .= " AND pcs.status <> 'closed' AND pcs.closed_at IS NULL";
    }
    if (empty($agentProfile['can_view_all_chats'])) {
        $where .= " AND (pcs.assigned_agent_id IS NULL OR pcs.assigned_agent_id = ?)";
        $types .= 's';
        $params[] = $agentId;
    }
    $sql = "
        SELECT pcs.session_id, pcs.chat_reference, pcs.visitor_name, pcs.phone_number, pcs.email, pcs.force_number,
               pcs.pensioner_number, pcs.district, pcs.location, pcs.inquiry_category, pcs.source_page, pcs.status,
               pcs.priority, pcs.assigned_agent_id, pcs.close_reason, pcs.subject, pcs.created_at, pcs.started_at, pcs.closed_at,
               tu.userName AS assigned_agent_name,
               (SELECT m.created_at FROM public_chat_messages m WHERE m.session_id = pcs.session_id ORDER BY m.message_id DESC LIMIT 1) AS last_message_at
        FROM public_chat_sessions pcs
        LEFT JOIN tb_users tu ON tu.userId = pcs.assigned_agent_id
        $where
        ORDER BY FIELD(pcs.status, 'waiting','escalated','assigned','active','closed'), pcs.created_at DESC
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load chats.'], 500);
    }
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $i => $value) {
            $refs[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    publicChatJson(['success' => true, 'sessions' => $sessions, 'agent' => $agentProfile, 'canSupervise' => publicChatCanSupervise($conn)]);
}

if ($action === 'detail') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $session = publicChatAgentSession($conn, $sessionId);
    if (!publicChatAgentCanAccessSession($session, $agentId, $agentProfile, true)
        && !publicChatAgentHasLinkedRecordAccess($conn, $sessionId, $agentId)) {
        publicChatJson(['success' => false, 'message' => 'You are not permitted to view this chat.'], 403);
    }
    publicChatMarkSeen($conn, $sessionId, 'agent');
    $messages = publicChatFetchMessages($conn, $sessionId, 0, 'agent');
    $messages = publicChatAttachMessageFiles($conn, $messages, true);

    $noteStmt = $conn->prepare("SELECT n.note_id, n.agent_user_id, u.userName AS agent_name, n.note_text, n.created_at FROM public_chat_notes n LEFT JOIN tb_users u ON u.userId = n.agent_user_id WHERE n.session_id = ? ORDER BY n.note_id DESC");
    $noteStmt->bind_param('i', $sessionId);
    $noteStmt->execute();
    $notes = $noteStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $noteStmt->close();

    $context = json_decode((string)($session['pensioner_context_json'] ?? ''), true);
    publicChatJson([
        'success' => true,
        'session' => $session,
        'messages' => $messages,
        'receipts' => publicChatReceiptRows($conn, $sessionId, 'agent'),
        'notes' => $notes,
        'pensionerContext' => is_array($context) ? $context : null,
        'canReply' => publicChatAgentCanAccessSession($session, $agentId, $agentProfile, false) && ($session['status'] ?? '') !== 'closed',
        'typing' => publicChatTypingRows($conn, $sessionId, 'visitor')
    ]);
}

if ($action === 'accept' || $action === 'assign') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $targetAgent = $action === 'assign' ? publicChatClean($input['agent_user_id'] ?? $agentId, 100) : $agentId;
    if ($action === 'accept') {
        publicChatRequireCapability($conn, 'can_accept_chat', 'You are not permitted to accept public chats.');
    }
    if ($action === 'assign' && !publicChatCanSupervise($conn)) {
        publicChatJson(['success' => false, 'message' => 'Supervisor access required to assign chats.'], 403);
    }
    if ($targetAgent === '') {
        publicChatJson(['success' => false, 'message' => 'Agent is required.'], 400);
    }
    $session = publicChatAgentSession($conn, $sessionId);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            UPDATE public_chat_sessions
            SET assigned_agent_id = ?, status = 'assigned', started_at = COALESCE(started_at, NOW())
            WHERE session_id = ?
              AND status <> 'closed'
              AND (assigned_agent_id IS NULL OR assigned_agent_id = '' OR assigned_agent_id = ?)
        ");
        $currentAssigned = (string)($session['assigned_agent_id'] ?? '');
        $allowedCurrent = $action === 'accept' ? $agentId : $currentAssigned;
        $stmt->bind_param('sis', $targetAgent, $sessionId, $allowedCurrent);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed < 1) {
            throw new RuntimeException('This chat has already been assigned or closed.');
        }

        $endStmt = $conn->prepare("UPDATE public_chat_assignments SET assignment_status = 'ended', unassigned_at = NOW() WHERE session_id = ? AND assignment_status = 'active'");
        $endStmt->bind_param('i', $sessionId);
        $endStmt->execute();
        $endStmt->close();

        $assignStmt = $conn->prepare("INSERT INTO public_chat_assignments (session_id, agent_user_id, assigned_by) VALUES (?, ?, ?)");
        $assignStmt->bind_param('iss', $sessionId, $targetAgent, $agentId);
        $assignStmt->execute();
        $assignStmt->close();

        publicChatAudit($conn, $sessionId, $action === 'accept' ? 'Chat accepted' : 'Chat assigned', ['agent_user_id' => $targetAgent]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        publicChatJson(['success' => false, 'message' => $e->getMessage() ?: 'Unable to assign chat.'], 409);
    }
    publicChatJson(['success' => true]);
}

if ($action === 'transfer') {
    publicChatRequireCapability($conn, 'can_transfer_chat', 'You are not permitted to transfer public chats.');
    $sessionId = (int)($input['session_id'] ?? 0);
    $targetAgent = publicChatClean($input['agent_user_id'] ?? '', 100);
    if ($sessionId <= 0 || $targetAgent === '') {
        publicChatJson(['success' => false, 'message' => 'Chat and target agent are required.'], 400);
    }
    $session = publicChatAgentSession($conn, $sessionId);
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Only the assigned handler can transfer this chat.');
    $target = publicChatAgentProfile($conn, $targetAgent);
    if (empty($target['is_enabled']) || empty($target['can_handle_public_chat'])) {
        publicChatJson(['success' => false, 'message' => 'Selected user is not enabled for public chat.'], 400);
    }
    $stmt = $conn->prepare("UPDATE public_chat_sessions SET assigned_agent_id = ?, status = 'assigned' WHERE session_id = ?");
    $stmt->bind_param('si', $targetAgent, $sessionId);
    $stmt->execute();
    $stmt->close();
    $endStmt = $conn->prepare("UPDATE public_chat_assignments SET assignment_status = 'ended', unassigned_at = NOW() WHERE session_id = ? AND assignment_status = 'active'");
    $endStmt->bind_param('i', $sessionId);
    $endStmt->execute();
    $endStmt->close();
    $assignStmt = $conn->prepare("INSERT INTO public_chat_assignments (session_id, agent_user_id, assigned_by) VALUES (?, ?, ?)");
    $assignStmt->bind_param('iss', $sessionId, $targetAgent, $agentId);
    $assignStmt->execute();
    $assignStmt->close();
    publicChatAudit($conn, $sessionId, 'Chat assigned', ['transfer' => true, 'agent_user_id' => $targetAgent]);
    publicChatJson(['success' => true]);
}

if ($action === 'note') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $note = publicChatClean($input['note'] ?? '', 4000);
    if ($note === '') {
        publicChatJson(['success' => false, 'message' => 'Note text is required.'], 400);
    }
    $session = publicChatAgentSession($conn, $sessionId);
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Only the assigned handler can add notes to this chat.');
    $stmt = $conn->prepare("INSERT INTO public_chat_notes (session_id, agent_user_id, note_text) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $sessionId, $agentId, $note);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, $sessionId, 'Internal note added');
    publicChatJson(['success' => true]);
}

if ($action === 'escalate') {
    publicChatRequireCapability($conn, 'can_escalate_chat', 'You are not permitted to escalate public chats.');
    $sessionId = (int)($input['session_id'] ?? 0);
    $reason = publicChatClean($input['reason'] ?? '', 4000);
    $to = publicChatClean($input['escalated_to'] ?? '', 100);
    $priority = publicChatClean($input['priority'] ?? 'high', 20);
    $deadline = publicChatClean($input['resolution_deadline'] ?? '', 30);
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        $priority = 'high';
    }
    if ($reason === '') {
        publicChatJson(['success' => false, 'message' => 'Escalation reason is required.'], 400);
    }
    $session = publicChatAgentSession($conn, $sessionId);
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Only the assigned handler can escalate this chat.');
    $stmt = $conn->prepare("INSERT INTO public_chat_escalations (session_id, escalated_by, escalated_to, reason, priority, resolution_deadline) VALUES (?, ?, ?, ?, ?, NULLIF(?, ''))");
    $stmt->bind_param('isssss', $sessionId, $agentId, $to, $reason, $priority, $deadline);
    $stmt->execute();
    $stmt->close();
    $up = $conn->prepare("UPDATE public_chat_sessions SET status = 'escalated', priority = 'high' WHERE session_id = ?");
    $up->bind_param('i', $sessionId);
    $up->execute();
    $up->close();
    publicChatAudit($conn, $sessionId, 'Chat escalated', ['reason' => $reason, 'escalated_to' => $to, 'priority' => $priority]);
    publicChatJson(['success' => true]);
}

if ($action === 'ticket') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $subject = publicChatClean($input['subject'] ?? '', 220);
    $description = publicChatClean($input['description'] ?? '', 4000);
    if ($subject === '') {
        publicChatJson(['success' => false, 'message' => 'Ticket subject is required.'], 400);
    }
    $session = publicChatAgentSession($conn, $sessionId);
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Only the assigned handler can create a ticket for this chat.');
    $ticketRef = 'PCT-' . date('YmdHis') . '-' . $sessionId;
    $stmt = $conn->prepare("INSERT INTO public_chat_tickets (session_id, ticket_reference, subject, description, created_by, assigned_to) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssss', $sessionId, $ticketRef, $subject, $description, $agentId, $agentId);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, $sessionId, 'Ticket created', ['ticket_reference' => $ticketRef, 'subject' => $subject]);
    publicChatJson(['success' => true, 'ticket_reference' => $ticketRef]);
}

if ($action === 'close') {
    publicChatRequireCapability($conn, 'can_close_chat', 'You are not permitted to close public chats.');
    $sessionId = (int)($input['session_id'] ?? 0);
    $reason = publicChatClean($input['reason'] ?? 'Resolved', 255);
    $outcome = publicChatClean($input['outcome'] ?? $reason, 120);
    $session = publicChatAgentSession($conn, $sessionId);
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Only the assigned handler can close this chat.');
    $stmt = $conn->prepare("UPDATE public_chat_sessions SET status = 'closed', closed_at = NOW(), closed_by = ?, close_reason = ?, outcome = ? WHERE session_id = ?");
    $stmt->bind_param('sssi', $agentId, $reason, $outcome, $sessionId);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, $sessionId, 'Chat closed', ['reason' => $reason]);
    publicChatJson(['success' => true]);
}

if ($action === 'status') {
    $status = publicChatClean($input['agent_status'] ?? $input['availability_status'] ?? 'online', 30);
    if ($status === 'available') {
        $status = 'online';
    }
    if (!in_array($status, ['offline', 'online', 'busy', 'away'], true)) {
        publicChatJson(['success' => false, 'message' => 'Invalid agent status.'], 400);
    }
    $agentStatus = $status === 'online' ? 'available' : $status;
    $stmt = $conn->prepare("
        INSERT INTO public_chat_agents (user_id, agent_status, availability_status, last_seen_at, is_enabled, can_handle_public_chat, appointed_by)
        VALUES (?, ?, ?, IF(? = 'online', NOW(), NULL), 1, 1, ?)
        ON DUPLICATE KEY UPDATE agent_status = VALUES(agent_status), availability_status = VALUES(availability_status), last_seen_at = IF(VALUES(availability_status) = 'online', NOW(), NULL), updated_at = NOW()
    ");
    $stmt->bind_param('sssss', $agentId, $agentStatus, $status, $status, $agentId);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, null, 'Agent status changed', ['agent_status' => $status]);
    publicChatJson(['success' => true, 'availability' => publicChatAvailability($conn)]);
}

if ($action === 'heartbeat') {
    $_SESSION['last_activity'] = time();
    $stmt = $conn->prepare("
        UPDATE public_chat_agents
        SET last_seen_at = NOW(), updated_at = NOW()
        WHERE user_id = ?
          AND is_enabled = 1
          AND can_handle_public_chat = 1
          AND availability_status = 'online'
    ");
    if ($stmt) {
        $stmt->bind_param('s', $agentId);
        $stmt->execute();
        $stmt->close();
    }
    if (!empty($_SESSION['session_id'])) {
        $stmt = $conn->prepare("UPDATE tb_user_sessions SET last_activity = NOW() WHERE session_id = ? AND user_id = ? AND is_active = 1");
        if ($stmt) {
            $sessionId = (string)$_SESSION['session_id'];
            $stmt->bind_param('ss', $sessionId, $agentId);
            $stmt->execute();
            $stmt->close();
        }
    }
    publicChatJson(['success' => true, 'active' => true]);
}

if ($action === 'stats') {
    publicChatRequireCapability($conn, 'can_view_reports', 'You are not permitted to view public chat reports.');
    $today = $conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total'] ?? 0;
    $week = $conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc()['total'] ?? 0;
    $month = $conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())")->fetch_assoc()['total'] ?? 0;
    $counts = [];
    $result = $conn->query("SELECT status, COUNT(*) AS total FROM public_chat_sessions GROUP BY status");
    while ($result && ($row = $result->fetch_assoc())) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
    $tickets = (int)(($conn->query("SELECT COUNT(*) AS total FROM public_chat_tickets")->fetch_assoc()['total'] ?? 0));
    $offline = (int)(($conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE close_reason LIKE 'Offline message%'")->fetch_assoc()['total'] ?? 0));
    $complaints = (int)(($conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE inquiry_category = 'Complaint'")->fetch_assoc()['total'] ?? 0));
    $pensionerRelated = (int)(($conn->query("SELECT COUNT(*) AS total FROM public_chat_sessions WHERE COALESCE(pensioner_number, '') <> '' OR COALESCE(force_number, '') <> '' OR pensioner_user_id IS NOT NULL")->fetch_assoc()['total'] ?? 0));
    $feedbackAvg = (float)(($conn->query("SELECT AVG(rating) AS avg_rating FROM public_chat_feedback")->fetch_assoc()['avg_rating'] ?? 0));
    $firstResponse = (float)(($conn->query("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) AS avg_seconds FROM public_chat_sessions WHERE first_response_at IS NOT NULL")->fetch_assoc()['avg_seconds'] ?? 0));
    $resolution = (float)(($conn->query("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, closed_at)) AS avg_seconds FROM public_chat_sessions WHERE closed_at IS NOT NULL")->fetch_assoc()['avg_seconds'] ?? 0));
    $grouped = [];
    foreach ([
        'byCategory' => 'SELECT inquiry_category AS label, COUNT(*) AS total FROM public_chat_sessions GROUP BY inquiry_category ORDER BY total DESC',
        'byDistrict' => 'SELECT district AS label, COUNT(*) AS total FROM public_chat_sessions GROUP BY district ORDER BY total DESC LIMIT 15',
        'byStatus' => 'SELECT status AS label, COUNT(*) AS total FROM public_chat_sessions GROUP BY status ORDER BY total DESC',
        'byAgent' => "SELECT COALESCE(u.userName, s.assigned_agent_id, 'Unassigned') AS label, COUNT(*) AS total FROM public_chat_sessions s LEFT JOIN tb_users u ON u.userId = s.assigned_agent_id GROUP BY label ORDER BY total DESC LIMIT 15",
        'peakHours' => "SELECT HOUR(created_at) AS label, COUNT(*) AS total FROM public_chat_sessions GROUP BY HOUR(created_at) ORDER BY total DESC LIMIT 8"
    ] as $key => $sql) {
        $grouped[$key] = [];
        $res = $conn->query($sql);
        while ($res && ($row = $res->fetch_assoc())) {
            $grouped[$key][] = $row;
        }
    }
    publicChatJson(['success' => true, 'stats' => [
        'totalToday' => (int)$today,
        'totalWeek' => (int)$week,
        'totalMonth' => (int)$month,
        'waiting' => $counts['waiting'] ?? 0,
        'active' => ($counts['active'] ?? 0) + ($counts['assigned'] ?? 0),
        'closed' => $counts['closed'] ?? 0,
        'unresolved' => ($counts['waiting'] ?? 0) + ($counts['active'] ?? 0) + ($counts['assigned'] ?? 0) + ($counts['escalated'] ?? 0),
        'escalated' => $counts['escalated'] ?? 0,
        'offlineMessages' => $offline,
        'ticketsCreated' => $tickets,
        'avgFirstResponseSeconds' => round($firstResponse, 1),
        'avgResolutionSeconds' => round($resolution, 1),
        'complaints' => $complaints,
        'pensionerRelated' => $pensionerRelated,
        'publicGeneral' => max(0, ((int)$today + (int)$week + (int)$month) - $pensionerRelated),
        'feedbackAverageRating' => round($feedbackAvg, 2),
        'groups' => $grouped
    ]]);
}

if ($action === 'transfer_agents') {
    publicChatRequireCapability($conn, 'can_transfer_chat', 'You are not permitted to transfer public chats.');
    $sql = "
        SELECT u.userId, u.userName, u.userRole
        FROM tb_users u
        LEFT JOIN public_chat_agents a ON a.user_id = u.userId
        WHERE (
            LOWER(COALESCE(u.userRole, '')) IN ('super admin','super_admin','admin','oc','oc_pen')
            OR (a.is_enabled = 1 AND a.can_handle_public_chat = 1 AND COALESCE(a.can_accept_chat, 0) = 1)
        )
          AND LOWER(COALESCE(u.userRole, '')) <> 'pensioner'
        ORDER BY u.userName ASC
    ";
    $result = $conn->query($sql);
    $agents = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($agents as &$agent) {
        $agent['agentLabel'] = trim((string)($agent['userName'] ?? 'Public chat handler'));
        $agent['roleLabel'] = ucwords(str_replace('_', ' ', strtolower((string)($agent['userRole'] ?? 'User'))));
    }
    unset($agent);
    publicChatJson(['success' => true, 'agents' => $agents]);
}

if ($action === 'agents') {
    publicChatRequireCapability($conn, 'can_manage_chat_settings', 'You are not permitted to manage public chat agents.');
    $sql = "
        SELECT u.userId, u.userName, u.userEmail, u.phoneNo, u.userRole,
               COALESCE(a.can_handle_public_chat, 0) AS can_handle_public_chat,
               COALESCE(a.can_accept_chat, 0) AS can_accept_chat,
               COALESCE(a.can_transfer_chat, 0) AS can_transfer_chat,
               COALESCE(a.can_escalate_chat, 0) AS can_escalate_chat,
               COALESCE(a.can_close_chat, 0) AS can_close_chat,
               COALESCE(a.can_view_all_chats, 0) AS can_view_all_chats,
               COALESCE(a.can_view_reports, 0) AS can_view_reports,
               COALESCE(a.can_manage_canned_responses, 0) AS can_manage_canned_responses,
               COALESCE(a.can_manage_chat_settings, 0) AS can_manage_chat_settings,
               COALESCE(a.availability_status, a.agent_status, 'offline') AS availability_status,
               COALESCE(a.max_active_chats, 5) AS max_active_chats,
               COALESCE(a.is_enabled, 0) AS is_enabled
        FROM tb_users u
        LEFT JOIN public_chat_agents a ON a.user_id = u.userId
        WHERE LOWER(COALESCE(u.userRole, '')) <> 'pensioner'
        ORDER BY u.userName ASC
    ";
    $agentResult = $conn->query($sql);
    $agents = $agentResult ? $agentResult->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($agents as &$agent) {
        $agent['userLabel'] = trim((string)($agent['userName'] ?? 'Registered user'));
        $agent['roleLabel'] = ucwords(str_replace('_', ' ', strtolower((string)($agent['userRole'] ?? 'User'))));
    }
    unset($agent);
    publicChatJson(['success' => true, 'agents' => $agents]);
}

if ($action === 'save_agent') {
    publicChatRequireCapability($conn, 'can_manage_chat_settings', 'You are not permitted to manage public chat agents.');
    $targetUser = publicChatClean($input['user_id'] ?? '', 100);
    if ($targetUser === '') {
        publicChatJson(['success' => false, 'message' => 'User is required.'], 400);
    }
    $bool = static fn($key) => filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $availability = publicChatClean($input['availability_status'] ?? 'offline', 20);
    if (!in_array($availability, ['online', 'busy', 'away', 'offline'], true)) {
        $availability = 'offline';
    }
    $maxChats = max(1, min(50, (int)($input['max_active_chats'] ?? 5)));
    $enabled = $bool('enabled');
    $stmt = $conn->prepare("
        INSERT INTO public_chat_agents (
            user_id, can_handle_public_chat, can_accept_chat, can_transfer_chat, can_escalate_chat, can_close_chat,
            can_view_all_chats, can_view_reports, can_manage_canned_responses, can_manage_chat_settings,
            availability_status, agent_status, max_active_chats, is_enabled, appointed_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            can_handle_public_chat = VALUES(can_handle_public_chat),
            can_accept_chat = VALUES(can_accept_chat),
            can_transfer_chat = VALUES(can_transfer_chat),
            can_escalate_chat = VALUES(can_escalate_chat),
            can_close_chat = VALUES(can_close_chat),
            can_view_all_chats = VALUES(can_view_all_chats),
            can_view_reports = VALUES(can_view_reports),
            can_manage_canned_responses = VALUES(can_manage_canned_responses),
            can_manage_chat_settings = VALUES(can_manage_chat_settings),
            availability_status = VALUES(availability_status),
            agent_status = VALUES(agent_status),
            max_active_chats = VALUES(max_active_chats),
            is_enabled = VALUES(is_enabled),
            updated_at = NOW()
    ");
    $canHandle = $bool('can_handle_public_chat');
    $canAccept = $bool('can_accept_chat');
    $canTransfer = $bool('can_transfer_chat');
    $canEscalate = $bool('can_escalate_chat');
    $canClose = $bool('can_close_chat');
    $canAll = $bool('can_view_all_chats');
    $canReports = $bool('can_view_reports');
    $canCanned = $bool('can_manage_canned_responses');
    $canSettings = $bool('can_manage_chat_settings');
    $agentStatus = $availability === 'online' ? 'available' : $availability;
    $stmt->bind_param('siiiiiiiiissiis', $targetUser, $canHandle, $canAccept, $canTransfer, $canEscalate, $canClose, $canAll, $canReports, $canCanned, $canSettings, $availability, $agentStatus, $maxChats, $enabled, $agentId);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, null, 'Agent status changed', ['managed_user_id' => $targetUser, 'enabled' => $enabled]);
    publicChatJson(['success' => true]);
}

if ($action === 'canned') {
    $result = $conn->query("SELECT * FROM public_chat_canned_responses ORDER BY is_active DESC, inquiry_category ASC, title ASC");
    publicChatJson(['success' => true, 'responses' => $result ? $result->fetch_all(MYSQLI_ASSOC) : []]);
}

if ($action === 'save_canned') {
    publicChatRequireCapability($conn, 'can_manage_canned_responses', 'You are not permitted to manage canned responses.');
    $id = (int)($input['response_id'] ?? 0);
    $title = publicChatClean($input['title'] ?? '', 160);
    $body = trim((string)($input['body'] ?? ''));
    $category = publicChatClean($input['inquiry_category'] ?? '', 80);
    $active = filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if ($title === '' || $body === '') {
        publicChatJson(['success' => false, 'message' => 'Title and response body are required.'], 400);
    }
    if ($category !== '' && !in_array($category, PUBLIC_CHAT_CATEGORIES, true)) {
        publicChatJson(['success' => false, 'message' => 'Invalid inquiry category.'], 400);
    }
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE public_chat_canned_responses SET title = ?, body = ?, inquiry_category = ?, is_active = ? WHERE response_id = ?");
        $stmt->bind_param('sssii', $title, $body, $category, $active, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO public_chat_canned_responses (title, body, inquiry_category, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssis', $title, $body, $category, $active, $agentId);
    }
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, null, 'Settings changed', ['canned_response' => $title]);
    publicChatJson(['success' => true]);
}

if ($action === 'delete_canned') {
    publicChatRequireCapability($conn, 'can_manage_canned_responses', 'You are not permitted to manage canned responses.');
    $id = (int)($input['response_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE public_chat_canned_responses SET is_active = 0 WHERE response_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    publicChatJson(['success' => true]);
}

if ($action === 'tickets') {
    $where = '';
    $types = '';
    $params = [];
    if (empty($agentProfile['can_view_all_chats']) && empty($agentProfile['is_system'])) {
        $where = "WHERE s.assigned_agent_id = ? OR t.assigned_to = ? OR t.created_by = ?";
        $types = 'sss';
        $params = [$agentId, $agentId, $agentId];
    }
    $sql = "
        SELECT t.ticket_id, t.session_id, t.ticket_reference, t.ticket_type, t.status, t.priority, t.subject, t.description,
               t.resolution_notes, t.created_by, t.assigned_to, t.created_at, t.closed_at,
               s.chat_reference, s.visitor_name, s.inquiry_category,
               COALESCE(u.userName, 'Unassigned') AS assigned_name
        FROM public_chat_tickets t
        JOIN public_chat_sessions s ON s.session_id = t.session_id
        LEFT JOIN tb_users u ON u.userId = t.assigned_to
        $where
        ORDER BY t.created_at DESC
        LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load tickets.'], 500);
    }
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $i => $value) {
            $refs[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $tickets = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    publicChatJson(['success' => true, 'tickets' => $tickets]);
}

if ($action === 'update_ticket') {
    publicChatRequireCapability($conn, 'can_view_all_chats', 'You are not permitted to update public chat tickets.');
    $ticketId = (int)($input['ticket_id'] ?? 0);
    $status = publicChatClean($input['status'] ?? 'New', 40);
    $assignedTo = publicChatClean($input['assigned_to'] ?? '', 100);
    $notes = publicChatClean($input['resolution_notes'] ?? '', 4000);
    $allowedStatuses = ['New','Assigned','In progress','Awaiting public user','Escalated','Resolved','Closed','Reopened'];
    if (!in_array($status, $allowedStatuses, true)) {
        publicChatJson(['success' => false, 'message' => 'Invalid ticket status.'], 400);
    }
    $stmt = $conn->prepare("UPDATE public_chat_tickets SET status = ?, assigned_to = NULLIF(?, ''), resolution_notes = NULLIF(?, ''), closed_at = IF(? IN ('Resolved','Closed'), NOW(), closed_at) WHERE ticket_id = ?");
    $stmt->bind_param('ssssi', $status, $assignedTo, $notes, $status, $ticketId);
    $stmt->execute();
    $stmt->close();
    publicChatAudit($conn, null, 'Ticket created', ['ticket_updated' => $ticketId, 'status' => $status]);
    publicChatJson(['success' => true]);
}

if ($action === 'escalations') {
    $where = '';
    $types = '';
    $params = [];
    if (empty($agentProfile['can_view_all_chats']) && empty($agentProfile['is_system'])) {
        $where = "WHERE s.assigned_agent_id = ? OR e.escalated_by = ? OR e.escalated_to = ?";
        $types = 'sss';
        $params = [$agentId, $agentId, $agentId];
    }
    $sql = "
        SELECT e.escalation_id, e.session_id, e.reason, e.priority, e.status, e.resolution_deadline, e.escalation_time, e.created_at, e.outcome,
               s.chat_reference, s.visitor_name, s.inquiry_category,
               COALESCE(u.userName, 'Staff') AS escalated_by_name,
               COALESCE(tu.userName, 'Supervisor') AS escalated_to_name
        FROM public_chat_escalations e
        JOIN public_chat_sessions s ON s.session_id = e.session_id
        LEFT JOIN tb_users u ON u.userId = e.escalated_by
        LEFT JOIN tb_users tu ON tu.userId = e.escalated_to
        $where
        ORDER BY e.created_at DESC
        LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        publicChatJson(['success' => false, 'message' => 'Unable to load escalations.'], 500);
    }
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $i => $value) {
            $refs[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $escalations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    publicChatJson(['success' => true, 'escalations' => $escalations]);
}

if ($action === 'audit') {
    publicChatRequireCapability($conn, 'can_view_reports', 'You are not permitted to view audit logs.');
    $result = $conn->query("
        SELECT COALESCE(s.chat_reference, '') AS chat_reference,
               l.actor_name, l.actor_role, l.action, l.details, l.ip_address, l.user_agent, l.created_at
        FROM public_chat_audit_logs l
        LEFT JOIN public_chat_sessions s ON s.session_id = l.session_id
        ORDER BY l.created_at DESC
        LIMIT 300
    ");
    publicChatJson(['success' => true, 'logs' => $result ? $result->fetch_all(MYSQLI_ASSOC) : []]);
}

if ($action === 'history') {
    $phone = publicChatClean($input['phone_number'] ?? '', 50);
    $force = publicChatClean($input['force_number'] ?? '', 80);
    $pensioner = publicChatClean($input['pensioner_number'] ?? '', 80);
    if ($phone === '' && $force === '' && $pensioner === '') {
        publicChatJson(['success' => true, 'history' => []]);
    }
    $stmt = $conn->prepare("
        SELECT session_id, chat_reference, visitor_name, phone_number, force_number, pensioner_number, district, inquiry_category, subject, status, created_at, closed_at
        FROM public_chat_sessions
        WHERE (? <> '' AND phone_number = ?) OR (? <> '' AND force_number = ?) OR (? <> '' AND pensioner_number = ?)
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('ssssss', $phone, $phone, $force, $force, $pensioner, $pensioner);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    publicChatJson(['success' => true, 'history' => $history]);
}

publicChatJson(['success' => false, 'message' => 'Unsupported action.'], 400);
?>
