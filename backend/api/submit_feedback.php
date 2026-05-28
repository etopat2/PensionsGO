<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';

ensureFeedbackWorkflowTables($conn);
applyApiCorsPolicy($conn, ['POST', 'OPTIONS']);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

ensureFeedbackSubmissionsTable($conn);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$honeypot = trim((string)($input['website'] ?? ''));
if ($honeypot !== '') {
    echo json_encode(['success' => true, 'message' => 'Feedback received.']);
    exit;
}

$feedbackType = strtolower(trim((string)($input['feedback_type'] ?? 'general_feedback')));
$allowedTypes = [
    'general_feedback',
    'bug_report',
    'data_correction',
    'service_request',
    'suggestion',
    'complaint',
    'pensioner_support'
];
if (!in_array($feedbackType, $allowedTypes, true)) {
    $feedbackType = 'general_feedback';
}

$userRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
$defaultAudience = $userRole === 'pensioner' ? 'pensioner' : ($userRole !== '' ? 'staff' : 'public');
$audience = strtolower(trim((string)($input['audience'] ?? $defaultAudience)));
if (!in_array($audience, ['public', 'staff', 'pensioner'], true)) {
    $audience = $defaultAudience;
}

$fullName = trim((string)($input['full_name'] ?? $_SESSION['userName'] ?? ''));
$email = trim((string)($input['email_address'] ?? $_SESSION['userEmail'] ?? ''));
$phoneRaw = trim((string)($input['phone_number'] ?? $_SESSION['phoneNo'] ?? ''));
$subject = trim((string)($input['subject'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$pageContext = trim((string)($input['page_context'] ?? 'feedback.html'));

if ($fullName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Your name is required.']);
    exit;
}

if ($subject === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide a short subject for your feedback.']);
    exit;
}

if ($message === '' || mb_strlen($message) < 20) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide enough detail so the team can understand the issue or suggestion.']);
    exit;
}

if (mb_strlen($subject) > 220) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Subject is too long.']);
    exit;
}

if (mb_strlen($message) > 5000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Feedback message is too long.']);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

$phone = '';
if ($phoneRaw !== '') {
    $phone = normalizePhoneNumber($phoneRaw) ?? '';
    if ($phone === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid phone number in international or Uganda local format.']);
        exit;
    }
}

if ($email === '' && $phone === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide either an email address or phone number for follow-up.']);
    exit;
}

$reference = generateFeedbackReference();
$submittedByUserId = trim((string)($_SESSION['userId'] ?? '')) ?: null;
$submittedByRole = trim((string)($_SESSION['userRole'] ?? '')) ?: null;
$normalizedSubmitterRole = normalizeRoleKey((string)($submittedByRole ?? ''));

if ($submittedByUserId && $normalizedSubmitterRole === 'pensioner' && $audience !== 'pensioner') {
    $audience = 'pensioner';
}
if ($submittedByUserId && $normalizedSubmitterRole !== '' && $normalizedSubmitterRole !== 'pensioner' && $audience === 'public') {
    $audience = 'staff';
}

$audienceGateMap = [
    'public' => 'feedback_public_enabled',
    'staff' => 'feedback_staff_enabled',
    'pensioner' => 'feedback_pensioner_enabled'
];
$gateKey = $audienceGateMap[$audience] ?? 'feedback_public_enabled';
if (!getAppSettingBool($conn, $gateKey, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Feedback submission is currently disabled for this audience.']);
    exit;
}

$stmt = $conn->prepare('
    INSERT INTO tb_feedback_submissions (
        reference_no, feedback_type, audience, full_name, email_address, phone_number,
        subject, message, page_context, submitted_by_user_id, submitted_by_role
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save feedback right now.']);
    exit;
}

$stmt->bind_param(
    'sssssssssss',
    $reference,
    $feedbackType,
    $audience,
    $fullName,
    $email,
    $phone,
    $subject,
    $message,
    $pageContext,
    $submittedByUserId,
    $submittedByRole
);

$ok = $stmt->execute();
$submissionId = (int)$stmt->insert_id;
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save feedback right now.']);
    exit;
}

$supportEmail = trim(getAppSettingString($conn, 'support_email', ''));
if ($supportEmail !== '' && getAppSettingBool($conn, 'feedback_email_notifications_enabled', true)) {
    $queued = queueNotification(
        $conn,
        'email',
        $supportEmail,
        'New feedback received: ' . $subject,
        "Reference: {$reference}\nType: {$feedbackType}\nAudience: {$audience}\nFrom: {$fullName}\nEmail: {$email}\nPhone: {$phone}\nPage: {$pageContext}\n\n{$message}",
        [
            'reference_no' => $reference,
            'submission_id' => $submissionId,
            'feedback_type' => $feedbackType,
            'audience' => $audience,
            'html_body' => '<p>A new feedback submission has been received.</p>'
                . '<p><strong>Reference:</strong> ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Type:</strong> ' . htmlspecialchars($feedbackType, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Audience:</strong> ' . htmlspecialchars($audience, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>From:</strong> ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Phone:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Page:</strong> ' . htmlspecialchars($pageContext, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
        ]
    );
    if (!$queued && function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'warning',
            'log_category' => 'feedback_notification',
            'event_code' => 'feedback_support_email_queue_failed',
            'message' => 'Support email notification for a new feedback submission could not be queued.',
            'context' => [
                'submission_id' => $submissionId,
                'reference_no' => $reference,
                'recipient' => $supportEmail
            ],
            'actor_id' => $submittedByUserId ?: 'public_feedback',
            'actor_name' => $fullName,
            'actor_role' => $submittedByRole ?: 'public'
        ]);
    }
}

recordFeedbackActivity($conn, $submissionId, [
    'action' => 'feedback_submitted',
    'actor_id' => $submittedByUserId ?: 'public_feedback',
    'actor_name' => $fullName,
    'actor_role' => $submittedByRole ?: 'public',
    'to_status' => 'new',
    'note' => 'Feedback submitted from ' . $pageContext . '.',
    'field_changes' => [
        'feedback_type' => ['to' => $feedbackType],
        'audience' => ['to' => $audience]
    ]
]);

logAuditEvent($conn, [
    'actor_id' => $submittedByUserId ?: 'public_feedback',
    'actor_name' => $fullName,
    'actor_role' => $submittedByRole ?: 'public',
    'action' => 'feedback_submitted',
    'entity_type' => 'feedback_submission',
    'entity_id' => (string)$submissionId,
    'details' => [
        'reference_no' => $reference,
        'feedback_type' => $feedbackType,
        'audience' => $audience,
        'page_context' => $pageContext
    ]
]);

echo json_encode([
    'success' => true,
    'message' => 'Feedback submitted successfully.',
    'referenceNo' => $reference
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
