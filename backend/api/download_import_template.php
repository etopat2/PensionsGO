<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';
require_once __DIR__ . '/xlsx_upload_template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$datasetKey = strtolower(trim((string)($_GET['dataset'] ?? '')));
$hasSession = isset($_SESSION['userId']);
$isAdmin = $hasSession && sessionRoleIn($conn, ['admin']);
$canClaimsManage = false;
if ($hasSession && $datasetKey === 'claims_ledger') {
    $canClaimsManage = currentUserHasPermission($conn, 'claims.arrears.manage');
}
if (!$isAdmin && !$canClaimsManage) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}
$definitions = getDataImportDatasetDefinitions($conn);
if (!isset($definitions[$datasetKey])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unknown import dataset']);
    exit;
}

$dataset = $definitions[$datasetKey];
$timestamp = date('Ymd_His');
$filename = $datasetKey . '_template_' . $timestamp . '.xlsx';

$templateRows = $dataset['template_rows'];
if ($datasetKey === 'staff_due' || $datasetKey === 'file_registry') {
    $titleResult = $conn->query("SELECT title_name FROM tb_titles WHERE is_active = 1 ORDER BY title_name ASC LIMIT 1");
    $unitResult = $conn->query("SELECT priUnit FROM tb_priunits ORDER BY priUnit ASC LIMIT 1");
    $title = $titleResult ? (string)(($titleResult->fetch_assoc()['title_name'] ?? '')) : '';
    $unit = $unitResult ? (string)(($unitResult->fetch_assoc()['priUnit'] ?? '')) : '';
    if (!empty($templateRows[0])) {
        if ($datasetKey === 'staff_due') {
            $templateRows[0][2] = $title !== '' ? $title : $templateRows[0][2];
            $templateRows[0][6] = $unit !== '' ? $unit : $templateRows[0][6];
        } else {
            $templateRows[0][3] = $title !== '' ? $title : $templateRows[0][3];
        }
    }
}
if ($datasetKey === 'claims_ledger' || $datasetKey === 'payroll_support') {
    $regResult = $conn->query("SELECT regNo, supplierNo FROM tb_fileregistry ORDER BY id ASC LIMIT 1");
    $regRow = $regResult ? $regResult->fetch_assoc() : null;
    if ($regRow && !empty($templateRows[0])) {
        $templateRows[0][0] = (string)($regRow['regNo'] ?? $templateRows[0][0]);
        if ($datasetKey === 'payroll_support') {
            $templateRows[0][7] = (string)($regRow['supplierNo'] ?? $templateRows[0][7]);
        }
    }
}
if ($datasetKey === 'users') {
    $roles = getActiveRoleKeys($conn);
    if (!empty($roles) && !empty($templateRows[0])) {
        $templateRows[0][4] = (string)$roles[0];
    }
}

sendUploadTemplateXlsx(array_column($dataset['columns'], 'label'), $templateRows, ucwords(str_replace('_', ' ', $datasetKey)) . ' Upload', $filename);
$conn->close();
