<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';
require_once __DIR__ . '/xlsx_upload_template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (
    !sessionRoleIn($conn, ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry'])
    || !currentUserHasPermission($conn, 'registry.bulk_upload')
) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$definitions = getDataImportDatasetDefinitions($conn);
$dataset = $definitions['file_registry'] ?? null;
if (!$dataset) {
    echo json_encode(['success' => false, 'message' => 'Registry import template is unavailable']);
    exit;
}

$timestamp = date('Ymd_His');
$filename = 'file_registry_template_' . $timestamp . '.xlsx';
$templateRows = $dataset['template_rows'];

$titleResult = $conn->query("SELECT title_name FROM tb_titles WHERE is_active = 1 ORDER BY title_name ASC LIMIT 1");
$title = $titleResult ? (string)(($titleResult->fetch_assoc()['title_name'] ?? '')) : '';
if (!empty($templateRows[0]) && $title !== '') {
    $templateRows[0][3] = $title;
}

header_remove('Content-Type');
sendUploadTemplateXlsx(array_column($dataset['columns'], 'label'), $templateRows, 'Pension File Registry Upload', $filename);
$conn->close();
