<?php
// backend/api/register_user.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('ensureUserPasswordUpdatedAtColumn')) {
    ensureUserPasswordUpdatedAtColumn($conn);
}
if (function_exists('ensureUserActiveColumn')) {
    ensureUserActiveColumn($conn);
}
$logFile = __DIR__ . '/../logs/register_errors.log';
$uploadDir = __DIR__ . '/../uploads/profiles/';

// Ensure upload & logs dirs exist
ensureUploadDirectoryGuard($uploadDir);
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0775, true);

function respond($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function logError($msg) {
    global $logFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    respond(false, 'Administrator access required to register users.');
}

/**
 * Optimizes and resizes image with multiple compression techniques
 */
function optimizeImage($sourcePath, $targetPath, $maxWidth = 400, $maxHeight = 400, $quality = 75) {
    if (!file_exists($sourcePath)) {
        throw new Exception('Source image not found');
    }

    // Get image info
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        throw new Exception('Invalid image file');
    }

    $mimeType = $imageInfo['mime'];
    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];

    // Create image resource based on mime type
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new Exception('Unsupported image format: ' . $mimeType);
    }

    if (!$sourceImage) {
        throw new Exception('Failed to create image resource');
    }

    // Calculate new dimensions maintaining aspect ratio
    $ratio = $originalWidth / $originalHeight;
    
    if ($maxWidth / $maxHeight > $ratio) {
        $newWidth = $maxHeight * $ratio;
        $newHeight = $maxHeight;
    } else {
        $newWidth = $maxWidth;
        $newHeight = $maxWidth / $ratio;
    }

    $newWidth = round($newWidth);
    $newHeight = round($newHeight);

    // Create new image
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG and GIF
    if ($mimeType == 'image/png' || $mimeType == 'image/gif') {
        imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
    }

    // Resize image with high-quality resampling
    imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

    // Save optimized image as JPEG (smallest file size)
    $result = imagejpeg($newImage, $targetPath, $quality);

    // Clean up memory
    imagedestroy($sourceImage);
    imagedestroy($newImage);

    if (!$result) {
        throw new Exception('Failed to save optimized image');
    }

    return true;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method. POST required.');
}

// Validate required fields presence
$required = ['userTitle', 'userName', 'userEmail', 'userPassword', 'phoneNo'];
foreach ($required as $r) {
    if (!isset($_POST[$r]) || trim($_POST[$r]) === '') {
        respond(false, "Missing required field: $r");
    }
}

// Sanitize inputs
$userTitle = substr(trim($_POST['userTitle']), 0, 20);
$userName  = substr(trim($_POST['userName']), 0, 100); // Matches your VARCHAR(100)
$userRoleInput = substr(trim((string)($_POST['userRole'] ?? '')), 0, 100);
$userRole  = $userRoleInput !== '' ? resolveRoleKeyFromInput($conn, $userRoleInput, true) : '';
$userEmail = strtolower(trim($_POST['userEmail']));
$passwordPlain = $_POST['userPassword'];
$phoneNo = trim($_POST['phoneNo'] ?? '');
$other = '';

// Validate userRole against active roles governed in settings.
$allowedRoles = getActiveRoleKeys($conn);
if (empty($allowedRoles)) {
    $allowedRoles = array_keys(getDefaultRoleCatalog());
}
if ($userRole === '') {
    $defaultRoleRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'default_user_role') : null;
    $defaultRole = is_string($defaultRoleRaw) ? strtolower(trim($defaultRoleRaw)) : 'user';
    if (in_array($defaultRole, $allowedRoles, true)) {
        $userRole = $defaultRole;
    } elseif (in_array('user', $allowedRoles, true)) {
        $userRole = 'user';
    } else {
        $userRole = (string)$allowedRoles[0];
    }
}
if (!in_array($userRole, $allowedRoles, true)) {
    respond(false, 'Invalid user role specified.');
}
if ($userRole === 'super_admin' && !canCurrentSessionManageAdminAccounts($conn)) {
    respond(false, 'Only the super administrator can register super administrator accounts.');
}
if ($userRole === 'admin' && !canCurrentSessionManageAdminAccounts($conn)) {
    respond(false, 'Only the super administrator can register administrator accounts.');
}

// Validate + normalize phone number
$normalizedPhoneNo = normalizePhoneNumber($phoneNo);
if ($normalizedPhoneNo === null) {
    respond(false, 'Invalid phone number format. Use international format or local Uganda format (e.g. +256700123456, 0770123456, 0312123456, 0800123456).');
}
$phoneNo = $normalizedPhoneNo;

// Server-side password validation based on app settings
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
if ($minLength > 0 && strlen($passwordPlain) < $minLength) {
    $passwordErrors[] = "minimum {$minLength} characters";
}
if ($requireUpper && !preg_match('/[A-Z]/', $passwordPlain)) {
    $passwordErrors[] = 'an uppercase letter';
}
if ($requireLower && !preg_match('/[a-z]/', $passwordPlain)) {
    $passwordErrors[] = 'a lowercase letter';
}
if ($requireNumber && !preg_match('/\d/', $passwordPlain)) {
    $passwordErrors[] = 'a number';
}
if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $passwordPlain)) {
    $passwordErrors[] = 'a special character';
}

if (!empty($passwordErrors)) {
    respond(false, 'Password must include ' . implode(', ', $passwordErrors) . '.');
}

// Check email uniqueness
$emailCheckStmt = $conn->prepare("SELECT Id FROM tb_users WHERE userEmail = ?");
if (!$emailCheckStmt) {
    logError("Prepare failed (email check): " . $conn->error);
    respond(false, 'Server error (email check).');
}
$emailCheckStmt->bind_param('s', $userEmail);
$emailCheckStmt->execute();
$emailCheckStmt->store_result();
if ($emailCheckStmt->num_rows > 0) {
    $emailCheckStmt->close();
    respond(false, 'Email already registered.');
}
$emailCheckStmt->close();

// Check phone uniqueness against normalized and legacy variants
$phoneCandidates = buildPhoneLookupCandidates($phoneNo);
$phoneCheckStmt = $conn->prepare("SELECT Id FROM tb_users WHERE phoneNo = ? LIMIT 1");
if (!$phoneCheckStmt) {
    logError("Prepare failed (phone check): " . $conn->error);
    respond(false, 'Server error (phone check).');
}
$phoneExists = false;
foreach ($phoneCandidates as $candidate) {
    $phoneCheckStmt->bind_param('s', $candidate);
    $phoneCheckStmt->execute();
    $phoneCheckStmt->store_result();
    if ($phoneCheckStmt->num_rows > 0) {
        $phoneExists = true;
        break;
    }
    $phoneCheckStmt->free_result();
}
$phoneCheckStmt->close();
if ($phoneExists) {
    respond(false, 'Phone number already registered.');
}

// Generate short 4-char code (base36) and ensure uniqueness if storing later
$shortCode = strtoupper(substr(base_convert(random_int(100000, 999999), 10, 36), 0, 4));
$userIdHash = hash('sha256', $shortCode); // STORE THIS in DB as userId

// Hash password for storage
$userPasswordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);

// Handle optional file upload with optimization
$photoPath = ''; // relative path to save in DB (empty if none)

if (isset($_FILES['userPhoto']) && $_FILES['userPhoto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $fileErr = $_FILES['userPhoto']['error'];
    if ($fileErr !== UPLOAD_ERR_OK) {
        logError("Upload error code: $fileErr");
        respond(false, 'Error uploading file.');
    }

    $allowed = ['jpg','jpeg','png','webp'];
    try {
        $validatedPhoto = assertUploadedFileIsSafe($conn, $_FILES['userPhoto'], $allowed, ['image/'], 'Profile photo');
    } catch (Throwable $e) {
        respond(false, $e->getMessage() ?: 'Invalid image format. Only jpg, jpeg, png, webp are allowed.');
    }
    $tmpPath = (string)$validatedPhoto['tmp_name'];
    $ext = (string)$validatedPhoto['extension'];

    // Build new filename using the short code
    $baseFilename = $shortCode;
    $jpgPath = $uploadDir . $baseFilename . '.jpg';

    try {
        // Optimize and save as JPEG (primary format)
        optimizeImage($tmpPath, $jpgPath, 400, 400, 75);
        
        // Get file size for logging
        $jpgSize = filesize($jpgPath);
        logError("Image optimized - JPG: " . round($jpgSize/1024) . "KB");

        // Save relative path (from project root)
        $photoPath = 'backend/uploads/profiles/' . $baseFilename . '.jpg';

    } catch (Exception $e) {
        logError("Image optimization failed: " . $e->getMessage());
        // Fallback: move original file without optimization
        $fallbackPath = $uploadDir . $baseFilename . '.' . $ext;
        if (!move_uploaded_file($tmpPath, $fallbackPath)) {
            logError("Fallback move_uploaded_file failed to $fallbackPath");
            respond(false, 'Failed to store uploaded image.');
        }
        $photoPath = 'backend/uploads/profiles/' . $baseFilename . '.' . $ext;
    }
}

// Insert new user - using only existing columns from your schema
$insertSql = "INSERT INTO tb_users (userId, userTitle, userName, userRole, userEmail, phoneNo, userPassword, password_updated_at, userPhoto, other, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 1)";
$stmt = $conn->prepare($insertSql);
if (!$stmt) {
    logError("Prepare failed (insert): " . $conn->error);
    respond(false, 'Server error (prepare insert).');
}

$stmt->bind_param('sssssssss', $userIdHash, $userTitle, $userName, $userRole, $userEmail, $phoneNo, $userPasswordHash, $photoPath, $other);

if (!$stmt->execute()) {
    $err = $stmt->error;
    logError("Execute failed (insert): " . $err);
    
    // Clean up uploaded files if database insert failed
    if ($photoPath) {
        $fullPath = __DIR__ . '/../' . str_replace('backend/', '', $photoPath);
        if (file_exists($fullPath)) unlink($fullPath);
    }
    
    respond(false, 'Registration failed. Database error.');
}

$stmt->close();

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'System',
        'actor_role' => $_SESSION['userRole'] ?? 'system',
        'action' => 'user_created',
        'entity_type' => 'user',
        'entity_id' => $userIdHash,
        'details' => [
            'user_email' => $userEmail,
            'user_role' => $userRole
        ]
    ]);
}

$conn->close();

// Success: return short code so the UI can show it (human reference)
respond(true, 'Registered successfully.', ['referenceCode' => $shortCode]);
?>
