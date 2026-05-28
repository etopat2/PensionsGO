<?php
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

if (!currentUserHasPermission($conn, 'payroll.upload')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensurePayrollManagementTables($conn);

$payrollYear = (int)($_POST['payroll_year'] ?? date('Y'));
$payrollMonth = (int)($_POST['payroll_month'] ?? date('n'));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($payrollYear < 2000 || $payrollYear > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll year']);
    exit;
}
if ($payrollMonth < 1 || $payrollMonth > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll month']);
    exit;
}

if (!isset($_FILES['payment_register_file']) || (int)($_FILES['payment_register_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Payment register PDF is required']);
    exit;
}

$registerFile = $_FILES['payment_register_file'];
enforceUploadedFileSizeLimit($conn, $registerFile, 'Payment register PDF');
$registerOriginalName = (string)($registerFile['name'] ?? 'payment_register.pdf');
$registerExt = strtolower(pathinfo($registerOriginalName, PATHINFO_EXTENSION));
$registerMime = (string)($registerFile['type'] ?? '');
$registerSize = (int)($registerFile['size'] ?? 0);

if ($registerExt !== 'pdf') {
    echo json_encode(['success' => false, 'message' => 'Payment register must be a PDF file']);
    exit;
}
if ($registerSize <= 0) {
    echo json_encode(['success' => false, 'message' => 'Payment register PDF must not be empty']);
    exit;
}

$cycleStmt = $conn->prepare("
    SELECT
        cycle_id,
        payroll_year,
        payroll_month,
        payment_register_file,
        notes
    FROM tb_payroll_upload_cycles
    WHERE payroll_year = ?
      AND payroll_month = ?
      AND COALESCE(is_deleted, 0) = 0
    ORDER BY cycle_id DESC
    LIMIT 1
");
if (!$cycleStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll cycle lookup']);
    exit;
}
$cycleStmt->bind_param("ii", $payrollYear, $payrollMonth);
$cycleStmt->execute();
$cycle = $cycleStmt->get_result()->fetch_assoc();
$cycleStmt->close();

if (!$cycle) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Upload the payroll source file first for the selected payroll month before attaching a payment register.'
    ]);
    exit;
}

$baseUploadDir = __DIR__ . '/../uploads/payroll';
$registersDir = $baseUploadDir . '/registers';
foreach ([$baseUploadDir, $registersDir] as $dirPath) {
    if (!is_dir($dirPath) && !mkdir($dirPath, 0775, true) && !is_dir($dirPath)) {
        echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll register upload directory']);
        exit;
    }
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($registerOriginalName, PATHINFO_FILENAME));
$fileToken = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$storedRegisterName = $safeBase . '_' . $fileToken . '.pdf';
$storedRegisterAbsolutePath = $registersDir . '/' . $storedRegisterName;
$storedRegisterRelativePath = 'uploads/payroll/registers/' . $storedRegisterName;

if (!move_uploaded_file((string)$registerFile['tmp_name'], $storedRegisterAbsolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save payment register PDF']);
    exit;
}

$cycleId = (int)($cycle['cycle_id'] ?? 0);
$oldRegisterRelativePath = trim((string)($cycle['payment_register_file'] ?? ''));
$existingNotes = trim((string)($cycle['notes'] ?? ''));
$noteLine = $notes !== '' ? 'Register upload note: ' . $notes : '';
$mergedNotes = $existingNotes;
if ($noteLine !== '') {
    $mergedNotes = $existingNotes !== '' ? ($existingNotes . PHP_EOL . $noteLine) : $noteLine;
}

$conn->begin_transaction();
try {
    $updateStmt = $conn->prepare("
        UPDATE tb_payroll_upload_cycles
        SET
            payment_register_file = ?,
            payment_register_original_name = ?,
            payment_register_mime = ?,
            notes = ?,
            uploaded_by = ?
        WHERE cycle_id = ?
          AND COALESCE(is_deleted, 0) = 0
        LIMIT 1
    ");
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare payment register update.');
    }

    $uploadedBy = (string)($_SESSION['userId'] ?? '');
    $updateStmt->bind_param(
        "sssssi",
        $storedRegisterRelativePath,
        $registerOriginalName,
        $registerMime,
        $mergedNotes,
        $uploadedBy,
        $cycleId
    );
    $updateStmt->execute();
    $affected = (int)$updateStmt->affected_rows;
    $updateStmt->close();

    if ($affected <= 0) {
        throw new RuntimeException('Payment register was not updated.');
    }

    $conn->commit();

    uploadPayrollRegisterDeleteFileIfSafe($oldRegisterRelativePath, $storedRegisterRelativePath);

    logPayrollAudit($conn, [
        'cycle_id' => $cycleId,
        'action' => $oldRegisterRelativePath !== '' ? 'replace_register' : 'upload_register',
        'actor_user_id' => $_SESSION['userId'] ?? '',
        'actor_role' => $_SESSION['userRole'] ?? '',
        'details' => [
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'payment_register_file' => $storedRegisterRelativePath,
            'replaced_existing_register' => $oldRegisterRelativePath !== '',
            'notes_supplied' => $notes !== ''
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Payment register uploaded successfully.',
        'cycle' => [
            'cycleId' => $cycleId,
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'paymentRegisterFile' => $storedRegisterRelativePath,
            'paymentRegisterFileName' => $registerOriginalName
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($storedRegisterAbsolutePath);
    error_log('upload_payroll_register error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to upload payment register.'
    ]);
}

$conn->close();

function uploadPayrollRegisterDeleteFileIfSafe(?string $oldRelativePath, ?string $newRelativePath): void
{
    $old = trim((string)$oldRelativePath);
    $new = trim((string)$newRelativePath);
    if ($old === '' || $old === $new) {
        return;
    }

    $base = realpath(__DIR__ . '/../uploads/payroll');
    $target = realpath(__DIR__ . '/../' . ltrim($old, '/\\'));
    if ($base && $target && strpos($target, $base) === 0 && is_file($target)) {
        @unlink($target);
    }
}
