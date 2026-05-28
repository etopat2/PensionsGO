<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'staff_due.bulk_upload')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$definitions = getDataImportDatasetDefinitions($conn);
$dataset = $definitions['staff_due'] ?? null;
if (!$dataset) {
    echo json_encode(['success' => false, 'message' => 'Staff due import template is unavailable']);
    exit;
}

$timestamp = date('Ymd_His');
$filename = 'staff_due_template_' . $timestamp . '.csv';
$templateRows = $dataset['template_rows'];

$titleResult = $conn->query("SELECT title_name FROM tb_titles WHERE is_active = 1 ORDER BY title_name ASC LIMIT 1");
$title = $titleResult ? (string)(($titleResult->fetch_assoc()['title_name'] ?? '')) : '';
if (!empty($templateRows[0]) && $title !== '') {
    $templateRows[0][2] = $title;
}

header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}

fputcsv($out, array_map(static fn($column) => $column['field'], $dataset['columns']));
foreach ($templateRows as $row) {
    fputcsv($out, $row);
}
fclose($out);
$conn->close();
