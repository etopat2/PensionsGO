<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$actorRole = getSessionEffectiveRoleKey($conn);
if (!sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$targetUserId = trim((string)($payload['user_id'] ?? ''));
$useCustomPassword = filter_var($payload['use_custom_password'] ?? false, FILTER_VALIDATE_BOOLEAN);
$customPassword = (string)($payload['custom_password'] ?? '');
$defaultPassword = 'Pensioner123';

if ($targetUserId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Pensioner account is required']);
    exit;
}

$userStmt = $conn->prepare("
    SELECT userId, userName, userRole
    FROM tb_users
    WHERE userId = ?
    LIMIT 1
");
if (!$userStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify user account']);
    exit;
}
$userStmt->bind_param('s', $targetUserId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pensioner account not found']);
    exit;
}

$userRole = strtolower((string)($user['userRole'] ?? ''));
if ($userRole !== 'pensioner') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Selected account is not a pensioner']);
    exit;
}

$passwordToApply = $defaultPassword;
if ($useCustomPassword) {
    $passwordToApply = trim($customPassword);
    if ($passwordToApply === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enter a custom password']);
        exit;
    }

    $minLengthRaw = getAppSetting($conn, 'password_min_length');
    $requireUpperRaw = getAppSetting($conn, 'password_require_uppercase');
    $requireLowerRaw = getAppSetting($conn, 'password_require_lowercase');
    $requireNumberRaw = getAppSetting($conn, 'password_require_number');
    $requireSpecialRaw = getAppSetting($conn, 'password_require_special');

    $minLength = is_numeric($minLengthRaw) ? (int)$minLengthRaw : 8;
    $requireUpper = filter_var($requireUpperRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $requireUpper = ($requireUpper === null) ? ($requireUpperRaw === '1') : (bool)$requireUpper;
    $requireLower = filter_var($requireLowerRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $requireLower = ($requireLower === null) ? ($requireLowerRaw === '1') : (bool)$requireLower;
    $requireNumber = filter_var($requireNumberRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $requireNumber = ($requireNumber === null) ? ($requireNumberRaw === '1') : (bool)$requireNumber;
    $requireSpecial = filter_var($requireSpecialRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $requireSpecial = ($requireSpecial === null) ? ($requireSpecialRaw === '1') : (bool)$requireSpecial;

    $passwordErrors = [];
    if ($minLength > 0 && strlen($passwordToApply) < $minLength) {
        $passwordErrors[] = "minimum {$minLength} characters";
    }
    if ($requireUpper && !preg_match('/[A-Z]/', $passwordToApply)) {
        $passwordErrors[] = 'an uppercase letter';
    }
    if ($requireLower && !preg_match('/[a-z]/', $passwordToApply)) {
        $passwordErrors[] = 'a lowercase letter';
    }
    if ($requireNumber && !preg_match('/\d/', $passwordToApply)) {
        $passwordErrors[] = 'a number';
    }
    if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $passwordToApply)) {
        $passwordErrors[] = 'a special character';
    }

    if (!empty($passwordErrors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Password must include ' . implode(', ', $passwordErrors) . '.'
        ]);
        exit;
    }
}

$passwordHash = password_hash($passwordToApply, PASSWORD_DEFAULT);
$updateStmt = $conn->prepare("
    UPDATE tb_users
    SET userPassword = ?,
        password_updated_at = NOW()
    WHERE userId = ?
    LIMIT 1
");
if (!$updateStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update password']);
    exit;
}
$updateStmt->bind_param('ss', $passwordHash, $targetUserId);
$updated = $updateStmt->execute();
$updateStmt->close();

if (!$updated) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update password']);
    exit;
}

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'System',
        'actor_role' => $_SESSION['userRole'] ?? 'system',
        'action' => $useCustomPassword ? 'pensioner_password_changed' : 'pensioner_password_reset_default',
        'entity_type' => 'user',
        'entity_id' => $targetUserId,
        'details' => [
            'target_user_name' => $user['userName'] ?? '',
            'custom_password' => $useCustomPassword
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'message' => $useCustomPassword
        ? 'Pensioner password updated successfully.'
        : 'Pensioner password reset to default successfully.'
]);

$conn->close();
?>
