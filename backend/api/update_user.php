<?php
/**
 * 
 * UPDATE USER API (Supports phone number & photo optimization)
 * 
 * Updates user details in tb_users table:
 * - userTitle, userName, userEmail, phoneNo, userRole, userPassword, userPhoto
 * - Handles profile image optimization and safe replacement
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (function_exists('ensureUserPasswordUpdatedAtColumn')) {
    ensureUserPasswordUpdatedAtColumn($conn);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// 
// 1ï¸âƒ£ Collect input
// 
$userId = $_POST['userId'] ?? '';
$userTitle = $_POST['userTitle'] ?? '';
$userName = $_POST['userName'] ?? '';
$userEmail = $_POST['userEmail'] ?? '';
$phoneNo = $_POST['phoneNo'] ?? '';
$userRoleInput = trim((string)($_POST['userRole'] ?? ''));
$userRole = $userRoleInput !== '' ? resolveRoleKeyFromInput($conn, $userRoleInput, true) : '';
$newPassword = $_POST['newPassword'] ?? '';

if (empty($userId)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

if ($userRole !== '') {
    $allowedRoles = getActiveRoleKeys($conn);
    if (empty($allowedRoles)) {
        $allowedRoles = array_keys(getDefaultRoleCatalog());
    }
    if (!in_array($userRole, $allowedRoles, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid user role selected']);
        exit;
    }
}

// 
// 2ï¸âƒ£ Validate and normalize phone format
// 
$phoneNo = trim($phoneNo);
if (!empty($phoneNo)) {
    $normalizedPhone = normalizePhoneNumber($phoneNo);
    if ($normalizedPhone === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid phone number. Use international or local Uganda format (e.g. +256700123456, 0770123456, 0312123456, 0800123456).'
        ]);
        exit;
    }
    $phoneNo = $normalizedPhone;

    // Check duplicates against normalized and legacy variants.
    $dupStmt = $conn->prepare("SELECT Id FROM tb_users WHERE userId <> ? AND phoneNo = ? LIMIT 1");
    if (!$dupStmt) {
        echo json_encode(['success' => false, 'message' => 'Server error during phone validation']);
        exit;
    }

    $phoneCandidates = buildPhoneLookupCandidates($phoneNo);
    $phoneTaken = false;
    foreach ($phoneCandidates as $candidate) {
        $dupStmt->bind_param("ss", $userId, $candidate);
        $dupStmt->execute();
        $dupStmt->store_result();
        if ($dupStmt->num_rows > 0) {
            $phoneTaken = true;
            break;
        }
        $dupStmt->free_result();
    }
    $dupStmt->close();

    if ($phoneTaken) {
        echo json_encode(['success' => false, 'message' => 'Phone number already in use by another user']);
        exit;
    }
}

// 
// 3ï¸âƒ£ Image utilities
// 
function optimizeImage($src, $dest, $maxW = 400, $maxH = 400, $quality = 75) {
    $info = getimagesize($src);
    if (!$info) throw new Exception('Invalid image file');
    [$width, $height] = $info;

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($src); break;
        case 'image/png':  $image = imagecreatefrompng($src); break;
        case 'image/gif':  $image = imagecreatefromgif($src); break;
        default: throw new Exception('Unsupported format');
    }

    $ratio = min($maxW / $width, $maxH / $height);
    $newW = (int)($width * $ratio);
    $newH = (int)($height * $ratio);

    $newImg = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($newImg, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagejpeg($newImg, $dest, $quality);
    imagedestroy($image);
    imagedestroy($newImg);
}

function deleteOldPhoto($photo) {
    if (!$photo || strpos($photo, 'default-user') !== false) return;
    $path = __DIR__ . '/../' . str_replace('../', '', $photo);
    if (file_exists($path)) unlink($path);
}

// 
// 4ï¸âƒ£ Fetch current data
// 
$stmt = $conn->prepare("SELECT userTitle, userName, userEmail, phoneNo, userPhoto, userRole FROM tb_users WHERE userId = ?");
$stmt->bind_param("s", $userId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$actorRole = getSessionEffectiveRoleKey($conn);
$currentUserRole = strtolower((string)($current['userRole'] ?? ''));
$actorCanManageAdminAccounts = canCurrentSessionManageAdminAccounts($conn);
if (isPrivilegedAdminAccountRole($currentUserRole) && !$actorCanManageAdminAccounts) {
    echo json_encode(['success' => false, 'message' => 'Only the super administrator can modify administrator accounts']);
    exit;
}
if ($userRole === 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'The super administrator role is reserved and cannot be assigned from user management']);
    exit;
}
if ($userRole === 'admin' && !$actorCanManageAdminAccounts) {
    echo json_encode(['success' => false, 'message' => 'Only the super administrator can promote users to administrator']);
    exit;
}
if ($currentUserRole === 'admin' && $userRole !== '' && $userRole !== 'admin' && !$actorCanManageAdminAccounts) {
    echo json_encode(['success' => false, 'message' => 'Only the super administrator can demote administrator accounts']);
    exit;
}
if ($userRole !== '' && $actorRole !== 'admin' && $userRole !== $currentUserRole) {
    echo json_encode(['success' => false, 'message' => 'Only administrators can change user role']);
    exit;
}

// 
// 5ï¸âƒ£ Build dynamic update query
// 
$fields = ["userTitle = ?", "userName = ?", "userEmail = ?", "phoneNo = ?"];
$params = [$userTitle, $userName, $userEmail, $phoneNo];
$types = "ssss";

if (!empty($userRole)) { $fields[] = "userRole = ?"; $params[] = $userRole; $types .= "s"; }
if (!empty($newPassword)) {
    $minLengthRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_min_length') : null;
    $requireUpperRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_require_uppercase') : null;
    $requireLowerRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_require_lowercase') : null;
    $requireNumberRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_require_number') : null;
    $requireSpecialRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_require_special') : null;

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
    if ($minLength > 0 && strlen($newPassword) < $minLength) {
        $passwordErrors[] = "minimum {$minLength} characters";
    }
    if ($requireUpper && !preg_match('/[A-Z]/', $newPassword)) {
        $passwordErrors[] = 'an uppercase letter';
    }
    if ($requireLower && !preg_match('/[a-z]/', $newPassword)) {
        $passwordErrors[] = 'a lowercase letter';
    }
    if ($requireNumber && !preg_match('/\d/', $newPassword)) {
        $passwordErrors[] = 'a number';
    }
    if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        $passwordErrors[] = 'a special character';
    }

    if (!empty($passwordErrors)) {
        echo json_encode(['success' => false, 'message' => 'Password must include ' . implode(', ', $passwordErrors) . '.']);
        exit;
    }

    $fields[] = "userPassword = ?";
    $fields[] = "password_updated_at = NOW()";
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
    $types .= "s";
}

// 
// 6ï¸âƒ£ Handle profile picture upload
// 
if (!empty($_FILES['profilePicture']['name'])) {
    $uploadDir = __DIR__ . '/../uploads/profiles/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    enforceUploadedFileSizeLimit($conn, $_FILES['profilePicture'], 'Profile photo');
    $ext = strtolower(pathinfo($_FILES['profilePicture']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed)) throw new Exception('Invalid image format');

    $filename = uniqid('profile_', true) . '.jpg';
    $targetPath = $uploadDir . $filename;

    deleteOldPhoto($current['userPhoto']);
    optimizeImage($_FILES['profilePicture']['tmp_name'], $targetPath);

    $photoPath = '../uploads/profiles/' . $filename;
    $fields[] = "userPhoto = ?";
    $params[] = $photoPath;
    $types .= "s";
}

// 
// 7ï¸âƒ£ Execute update
// 
$params[] = $userId;
$types .= "s";

$sql = "UPDATE tb_users SET " . implode(", ", $fields) . " WHERE userId = ?";
$stmt = $conn->prepare($sql);
$bindParams = [];
$bindParams[] = $types;
foreach ($params as $key => $value) {
    $bindParams[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bindParams);
$success = $stmt->execute();
$stmt->close();

if ($success && function_exists('logAuditEvent')) {
    $auditChanges = [];
    $formatValue = function(?string $value): string {
        $value = trim((string)($value ?? ''));
        return $value === '' ? '(empty)' : $value;
    };
    $addChange = function(string $label, ?string $oldValue, ?string $newValue) use (&$auditChanges, $formatValue): void {
        $oldValue = trim((string)($oldValue ?? ''));
        $newValue = trim((string)($newValue ?? ''));
        if ($oldValue === $newValue) {
            return;
        }
        $auditChanges[] = "Edited {$label} from '{$formatValue($oldValue)}' to '{$formatValue($newValue)}'";
    };

    $addChange('Title', (string)($current['userTitle'] ?? ''), $userTitle);
    $addChange('Name', (string)($current['userName'] ?? ''), $userName);
    $addChange('Email address', (string)($current['userEmail'] ?? ''), $userEmail);
    $addChange('Phone number', (string)($current['phoneNo'] ?? ''), $phoneNo);

    if (!empty($userRole) && strtolower((string)$userRole) !== strtolower((string)($current['userRole'] ?? ''))) {
        $oldRoleLabel = function_exists('formatRoleLabel') ? formatRoleLabel($conn, (string)($current['userRole'] ?? '')) : (string)($current['userRole'] ?? '');
        $newRoleLabel = function_exists('formatRoleLabel') ? formatRoleLabel($conn, (string)$userRole) : (string)$userRole;
        $auditChanges[] = "Changed role from '{$formatValue($oldRoleLabel)}' to '{$formatValue($newRoleLabel)}'";
    }
    if (!empty($newPassword)) {
        $auditChanges[] = 'Updated password';
    }
    if (!empty($_FILES['profilePicture']['name'])) {
        $auditChanges[] = 'Updated profile photo';
    }

    if (empty($auditChanges)) {
        $auditChanges[] = 'User profile saved with no field changes.';
    }

    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'System',
        'actor_role' => $_SESSION['userRole'] ?? 'system',
        'action' => 'user_updated',
        'entity_type' => 'user',
        'entity_id' => $userId,
        'details' => implode('; ', $auditChanges)
    ]);
}

$conn->close();

echo json_encode([
    'success' => $success,
    'message' => $success ? 'User updated successfully' : 'Failed to update user'
]);
?>

