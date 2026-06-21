<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$settings = publicChatSettings($conn);
if (!$settings['enabled']) {
    publicChatJson(['success' => false, 'message' => 'Public live chat is currently disabled.'], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

publicChatRequireCsrf((string)($input['csrf_token'] ?? ''));
publicChatRateLimit($conn, 'start', publicChatSettingInt($conn, 'public_chat_rate_limit_start_per_10min', 5, 1, 50), 600);

$name = publicChatClean($input['visitor_name'] ?? $input['name'] ?? '', 160);
$phone = publicChatClean($input['phone_number'] ?? '', 50);
$email = publicChatClean($input['email'] ?? '', 160);
$forceNo = publicChatClean($input['force_number'] ?? '', 80);
$pensionerNo = publicChatClean($input['pensioner_number'] ?? '', 80);
$derivedLocation = publicChatDerivedVisitorLocation();
$district = publicChatClean($input['district'] ?? $derivedLocation['district'], 120);
$location = publicChatClean($input['location'] ?? $derivedLocation['location'], 180);
$category = publicChatClean($input['inquiry_category'] ?? '', 80);
$subject = publicChatClean($input['subject'] ?? '', 220);
$sourcePage = publicChatClean($input['source_page'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 220);
$message = publicChatClean($input['message'] ?? '', (int)$settings['maxMessageLength']);
$consentAccepted = filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

if ($name === '' || $category === '' || $subject === '' || $message === '') {
    publicChatJson(['success' => false, 'message' => 'Name, inquiry category, subject, and message are required.'], 400);
}
if (!$consentAccepted) {
    publicChatJson(['success' => false, 'message' => 'Consent is required before starting a public support chat.'], 400);
}
if (!in_array($category, PUBLIC_CHAT_CATEGORIES, true)) {
    publicChatJson(['success' => false, 'message' => 'Invalid inquiry category.'], 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    publicChatJson(['success' => false, 'message' => 'Enter a valid email address.'], 400);
}

$role = publicChatCurrentRole($conn);
$pensionerUserId = (!empty($_SESSION['userId']) && $role === 'pensioner') ? (string)$_SESSION['userId'] : null;
$context = publicChatResolvePensionerContext($conn, [
    'user_id' => $pensionerUserId ?? '',
    'force_number' => $forceNo,
    'pensioner_number' => $pensionerNo,
    'phone_number' => $phone,
    'email' => $email
]);
$contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ip = publicChatClientIp();
$ua = publicChatClean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
$availability = publicChatAvailability($conn);
$initialStatus = $availability['online'] ? 'waiting' : 'closed';
$closedAtSql = $availability['online'] ? 'NULL' : 'NOW()';
$closeReason = $availability['online'] ? null : 'Offline message submitted for follow-up';

$conn->begin_transaction();
try {
    $reference = publicChatGenerateReference($conn);
    $stmt = $conn->prepare("
        INSERT INTO public_chat_sessions (
            chat_reference, visitor_name, phone_number, email, force_number, pensioner_number, district, location,
            inquiry_category, subject, consent_accepted, source_page, ip_address, user_agent, status, priority,
            pensioner_user_id, pensioner_context_json, started_at, closed_at, close_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'normal', ?, ?, NOW(), {$closedAtSql}, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to create chat session.');
    }
    $stmt->bind_param('ssssssssssisssssss', $reference, $name, $phone, $email, $forceNo, $pensionerNo, $district, $location, $category, $subject, $consentAccepted, $sourcePage, $ip, $ua, $initialStatus, $pensionerUserId, $contextJson, $closeReason);
    $stmt->execute();
    $sessionId = (int)$stmt->insert_id;
    $stmt->close();

    $msgStmt = $conn->prepare("
        INSERT INTO public_chat_messages (session_id, sender_type, sender_name, message_text)
        VALUES (?, 'visitor', ?, ?)
    ");
    if (!$msgStmt) {
        throw new RuntimeException('Unable to save opening message.');
    }
    $msgStmt->bind_param('iss', $sessionId, $name, $message);
    $msgStmt->execute();
    $msgStmt->close();

    publicChatAudit($conn, $sessionId, 'Chat created', ['chat_reference' => $reference, 'inquiry_category' => $category]);
    publicChatAudit($conn, $sessionId, 'Message sent', ['sender_type' => 'visitor']);
    if (!$availability['online']) {
        $ticketRef = 'PCT-' . date('YmdHis') . '-' . $sessionId;
        $ticketStmt = $conn->prepare("
            INSERT INTO public_chat_tickets (session_id, ticket_reference, subject, description, priority)
            VALUES (?, ?, ?, ?, 'normal')
        ");
        if ($ticketStmt) {
            $ticketStmt->bind_param('isss', $sessionId, $ticketRef, $subject, $message);
            $ticketStmt->execute();
            $ticketStmt->close();
        }
        publicChatAudit($conn, $sessionId, 'Ticket created', ['ticket_reference' => $ticketRef, 'offline' => true]);
        publicChatAudit($conn, $sessionId, 'Chat closed', ['reason' => $closeReason]);
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    publicChatJson(['success' => false, 'message' => $e->getMessage()], 500);
}

publicChatJson([
    'success' => true,
    'session' => [
        'session_id' => $sessionId,
        'chat_reference' => $reference,
        'token' => publicChatSessionToken($sessionId, $reference),
        'pensionerContextAvailable' => !empty($context['matched']),
        'offline' => !$availability['online']
    ],
    'availability' => $availability
]);
?>
