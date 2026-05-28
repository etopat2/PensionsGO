<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (normalizeRoleKey((string)($_SESSION['userRole'] ?? '')) !== 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensurePensionerLookupColumns($conn);

$lookupEnabled = getAppSettingBool($conn, 'pensioner_lookup_enabled', true);
if (!$lookupEnabled) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pensioner lookup is currently disabled.']);
    exit;
}

$ownedRegistry = resolvePensionerOwnedRegistry($conn, (string)($_SESSION['userId'] ?? ''));
if (!$ownedRegistry || trim((string)($ownedRegistry['regNo'] ?? '')) === '') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Your registry record could not be linked to this account.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$visible = filter_var($payload['visible'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($visible === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid visibility preference.']);
    exit;
}

$regNo = trim((string)$ownedRegistry['regNo']);
$stmt = $conn->prepare("
    UPDATE tb_fileregistry
    SET lookup_contact_opt_in = ?, lookup_contact_updated_at = NOW()
    WHERE regNo = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare visibility update.']);
    exit;
}

$flag = $visible ? 1 : 0;
$stmt->bind_param('is', $flag, $regNo);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error ?: 'Unable to update visibility preference.']);
    exit;
}
$stmt->close();

if (getAppSettingBool($conn, 'pensioner_lookup_log_activity', true) && getAppSettingBool($conn, 'enable_activity_logs', true)) {
    logUserActivity($conn, [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? 'Pensioner'),
        'user_role' => 'pensioner',
        'activity_type' => 'pensioner_lookup_visibility_updated',
        'details' => $visible ? 'Enabled pensioner lookup visibility.' : 'Disabled pensioner lookup visibility.',
        'status' => 'success'
    ]);
}

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
        'actor_name' => (string)($_SESSION['userName'] ?? 'Pensioner'),
        'actor_role' => 'pensioner',
        'action' => 'pensioner_lookup_visibility_updated',
        'entity_type' => 'pensioner_lookup',
        'entity_id' => $regNo,
        'details' => [
            'regNo' => $regNo,
            'visible' => $visible
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'visible' => $visible,
    'message' => $visible
        ? 'Your contact record is now visible to fellow pensioners in the lookup directory.'
        : 'Your contact record is now hidden from the pensioner lookup directory.'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
?>
