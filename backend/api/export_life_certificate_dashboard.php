<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_export_runtime.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if ($role === 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'pdf', 'csv', 'json'], true)) {
    $format = 'xlsx';
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$statusMap = [
    'submitted' => 'Submitted',
    'not_submitted' => 'Not Submitted',
    'exempt' => 'Exempt'
];
$statusValue = $statusMap[$statusFilter] ?? '';

$search = trim((string)($_GET['search'] ?? ''));

ensureFileMovementTables($conn);
ensureLifeCertificateTables($conn);
syncCurrentYearLifeCertificateStatus($conn);

$statusExpr = "
    CASE
        WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
          OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
            THEN 'Exempt'
        WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
        ELSE 'Not Submitted'
    END
";

$baseWhere = " WHERE fr.regNo IS NOT NULL AND TRIM(fr.regNo) <> '' AND COALESCE(fr.is_deleted, 0) = 0 ";
$types = "i";
$params = [$year];

if ($search !== '') {
    $baseWhere .= "
        AND (
            fr.regNo LIKE ?
            OR fr.supplierNo LIKE ?
            OR fr.computerNo LIKE ?
            OR fr.sName LIKE ?
            OR fr.fName LIKE ?
            OR COALESCE(fr.telNo, '') LIKE ?
            OR COALESCE(sd.prisonUnit, '') LIKE ?
        )
    ";
    $like = '%' . $search . '%';
    $types .= str_repeat('s', 7);
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
    }
}

$dataWhere = $baseWhere;
$dataTypes = $types;
$dataParams = $params;
if ($statusValue !== '') {
    $dataWhere .= " AND {$statusExpr} = ? ";
    $dataTypes .= "s";
    $dataParams[] = $statusValue;
}

$dataSql = "
    SELECT
        fr.regNo,
        fr.title,
        fr.sName,
        fr.fName,
        fr.supplierNo,
        COALESCE(fr.telNo, '') AS telNo,
        COALESCE(fr.payType, 'Pensioner') AS payType,
        COALESCE(fr.livingStatus, 'Alive') AS livingStatus,
        COALESCE(sd.prisonUnit, '') AS station,
        {$statusExpr} AS life_certificate_status,
        lcs.submitted_at,
        lcs.submitted_by
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    LEFT JOIN tb_life_certificate_submissions lcs
      ON lcs.regNo = fr.regNo
     AND lcs.submission_year = ?
    {$dataWhere}
    ORDER BY
      CASE {$statusExpr}
        WHEN 'Not Submitted' THEN 1
        WHEN 'Submitted' THEN 2
        ELSE 3
      END ASC,
      fr.sName ASC, fr.fName ASC
";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare export query']);
    exit;
}

$listBind = [$dataTypes];
foreach ($dataParams as $k => $v) {
    $listBind[] = &$dataParams[$k];
}
call_user_func_array([$dataStmt, 'bind_param'], $listBind);
$dataStmt->execute();
$res = $dataStmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $submittedAt = '';
    if (!empty($row['submitted_at'])) {
        try {
            $dt = new DateTime((string)$row['submitted_at']);
            $submittedAt = $dt->format('d-M-Y');
        } catch (Throwable $e) {
            $submittedAt = '';
        }
    }
    $rows[] = [
        'file_number' => $row['regNo'],
        'title' => $row['title'] ?? '',
        'pensioner_name' => trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')),
        'supplier_number' => $row['supplierNo'] ?? '',
        'phone_number' => $row['telNo'] ?? '',
        'life_certificate_status' => $row['life_certificate_status'] ?? 'Not Submitted',
        'submitted_at' => $submittedAt
    ];
}
$dataStmt->close();

$actorName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User');
$def = [
    'label' => "Life Certificate Submissions {$year}",
    'columns' => [
        'file_number' => 'File Number',
        'title' => 'Title/Rank',
        'pensioner_name' => 'Name',
        'supplier_number' => 'Supplier Number',
        'phone_number' => 'Phone Number',
        'life_certificate_status' => 'Status',
        'submitted_at' => 'Submission Date'
    ],
    'text_columns' => ['file_number', 'phone_number', 'supplier_number'],
    'pdf_mode' => 'table',
    'meta_lines' => array_values(array_filter([
        'Year: ' . $year,
        $statusValue !== '' ? ('Status: ' . $statusValue) : '',
        $search !== '' ? ('Search: ' . $search) : ''
    ]))
];

$export = dmPayload($conn, $def, $rows, $actorName);
$export['aligns'] = [
    'pensioner_name' => 'left'
];
$timestamp = date('Ymd_His');
$baseName = 'life_certificate_submissions_' . $year . '_' . $timestamp;
$dir = getDataExportStoragePath();
$filePath = $dir . DIRECTORY_SEPARATOR . $baseName . '.' . $format;
$fileName = basename($filePath);

dmWriteExportArtifact($export, $format, $filePath);

$size = is_file($filePath) ? (int)filesize($filePath) : 0;
recordDataExportRun($conn, [
    'dataset_key' => 'life_certificate_submissions',
    'dataset_label' => $def['label'],
    'export_format' => $format,
    'file_name' => $fileName,
    'file_path' => $filePath,
    'file_size_bytes' => $size,
    'filters_json' => [
        'filters' => [
            'year' => $year,
            'status' => $statusFilter,
            'search' => $search
        ]
    ],
    'status' => 'success',
    'notes' => 'Export generated from Life Certificate dashboard.',
    'created_by' => (string)($_SESSION['userId'] ?? ''),
    'created_by_name' => $actorName,
    'created_by_role' => (string)($_SESSION['userRole'] ?? '')
]);

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? ''),
    'actor_name' => $actorName,
    'actor_role' => (string)($_SESSION['userRole'] ?? ''),
    'action' => 'life_certificate_export_generated',
    'entity_type' => 'data_export',
    'entity_id' => 'life_certificate_submissions',
    'details' => [
        'format' => $format,
        'row_count' => count($export['rows']),
        'year' => $year,
        'status' => $statusFilter,
        'search' => $search
    ]
]);

echo json_encode([
    'success' => true,
    'message' => 'Export generated successfully.',
    'export' => [
        'row_count' => count($export['rows']),
        'file_name' => $fileName,
        'file_size_bytes' => $size,
        'download_url' => '../backend/api/download_data_artifact.php?type=export&file=' . rawurlencode($fileName)
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
