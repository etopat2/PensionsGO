<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.suspension.upload')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureArrearsAndBudgetTables($conn);

$suspensionYear = (int)($_POST['suspension_year'] ?? date('Y'));
$suspensionMonth = (int)($_POST['suspension_month'] ?? date('n'));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($suspensionYear < 2000 || $suspensionYear > 2200) {
    echo json_encode(['success' => false, 'message' => 'Invalid suspension year']);
    exit;
}
if ($suspensionMonth < 1 || $suspensionMonth > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid suspension month']);
    exit;
}

if (!isset($_FILES['suspension_file']) || (int)($_FILES['suspension_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Suspension file is required']);
    exit;
}

$upload = $_FILES['suspension_file'];
enforceUploadedFileSizeLimit($conn, $upload, 'Suspension upload file');
$originalName = (string)($upload['name'] ?? 'suspension_file');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$size = (int)($upload['size'] ?? 0);
$mime = (string)($upload['type'] ?? '');

if (!in_array($ext, ['csv', 'xlsx', 'xlxl'], true)) {
    echo json_encode(['success' => false, 'message' => 'Suspension file must be .csv or .xlsx']);
    exit;
}
if ($size <= 0) {
    echo json_encode(['success' => false, 'message' => 'Suspension file must not be empty']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/suspensions/cycles';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    echo json_encode(['success' => false, 'message' => 'Unable to create suspension upload directory']);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$normalizedExt = ($ext === 'xlxl') ? 'xlsx' : $ext;
$storedName = $safeBase . '_' . $stamp . '.' . $normalizedExt;
$absolutePath = $uploadDir . '/' . $storedName;
$relativePath = 'uploads/suspensions/cycles/' . $storedName;

if ($normalizedExt === 'xlsx') {
    enforceZipArchiveSafety($conn, (string)$upload['tmp_name'], 'Suspension workbook');
}

if (!move_uploaded_file((string)$upload['tmp_name'], $absolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save uploaded suspension file']);
    exit;
}

try {
    $parsed = parseSuspensionUploadRows($absolutePath, $normalizedExt);
    $rows = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
    enforceParsedRowLimit($conn, count($rows) + count($reviewRows), 'Suspension upload');
    if (empty($rows)) {
        @unlink($absolutePath);
        $reviewExport = buildImportReviewExportPayload('suspension_upload_review', $reviewRows, $reviewColumns);
        echo json_encode([
            'success' => !empty($reviewRows),
            'message' => !empty($reviewRows)
                ? 'No suspension rows were imported. Review and correct the downloaded file, then upload again.'
                : 'No valid suspension records found in the uploaded file',
            'stats' => [
                'rows_uploaded' => 0,
                'matched_rows' => 0,
                'unmatched_rows' => 0,
                'ledger_entries_created' => 0
            ],
            'review_export' => $reviewExport
        ]);
        exit;
    }
} catch (Throwable $parseError) {
    @unlink($absolutePath);
    error_log('upload_suspension_arrears parse error: ' . $parseError->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to read suspension file']);
    exit;
}

$financialYear = getFinancialYearLabelForMonth($suspensionYear, $suspensionMonth);
$quarter = getQuarterLabelForMonth($suspensionMonth);
$uploadedBy = (string)($_SESSION['userId'] ?? '');

$stats = [
    'rows_uploaded' => count($rows),
    'matched_rows' => 0,
    'unmatched_rows' => 0,
    'saved_rows' => 0,
    'saved_amount' => 0.0,
    'matched_saved_amount' => 0.0
];
$analysisReviewRows = [];
$cycleReasonLabel = buildSuspensionReasonSummary($rows);

$conn->begin_transaction();
try {
    $cycleStmt = $conn->prepare("
        INSERT INTO tb_suspension_upload_cycles
        (
            suspension_year,
            suspension_month,
            financial_year_label,
            quarter_label,
            reason_label,
            uploaded_by,
            source_file,
            source_file_original_name,
            source_file_mime,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$cycleStmt) {
        throw new RuntimeException('Unable to create suspension upload cycle');
    }
    $cycleStmt->bind_param(
        "iissssssss",
        $suspensionYear,
        $suspensionMonth,
        $financialYear,
        $quarter,
        $cycleReasonLabel,
        $uploadedBy,
        $relativePath,
        $originalName,
        $mime,
        $notes
    );
    $cycleStmt->execute();
    $cycleId = (int)$cycleStmt->insert_id;
    $cycleStmt->close();

    $entryStmt = $conn->prepare("
        INSERT INTO tb_suspension_upload_entries
        (
            suspension_cycle_id,
            regNo,
            supplierNo,
            beneficiary_name,
            amount,
            reason,
            matched_regNo,
            matched_registry_id,
            is_matched
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$entryStmt) {
        throw new RuntimeException('Unable to insert suspension upload entries');
    }

    foreach ($rows as $row) {
        $rowNumber = (int)($row['row_number'] ?? 0);
        $rowRegNo = trim((string)($row['regNo'] ?? ''));
        $rowSupplier = trim((string)($row['supplierNo'] ?? ''));
        $rowName = trim((string)($row['beneficiary_name'] ?? ''));
        $rowReason = trim((string)($row['reason'] ?? ''));
        $rowAmount = round(max((float)($row['amount'] ?? 0), 0), 2);

        $match = matchSuspensionRowToRegistry($conn, $rowRegNo, $rowSupplier, $rowName);
        $matchedRegNo = (string)($match['regNo'] ?? '');
        $matchedRegistryId = (int)($match['id'] ?? 0);
        $isMatched = ($matchedRegNo !== '' && $matchedRegistryId > 0) ? 1 : 0;

        if ($isMatched) {
            $stats['matched_rows']++;
        } else {
            $stats['unmatched_rows']++;
            $analysisReviewRows[] = buildImportReviewRowFromSource($reviewColumns ? array_slice($reviewColumns, 5) : [], (array)($row['source_row'] ?? []), [
                'Source Row' => $rowNumber,
                'Review Status' => 'Unmatched',
                'Review Reason' => 'No pension file registry record matched this suspension row. The saved amount was preserved in the suspension register but not linked to a pensioner file.',
                'Review Fields' => ['regNo', 'supplierNo', 'beneficiary_name'],
                'Matched Key' => $rowRegNo !== '' ? $rowRegNo : $rowSupplier
            ]);
        }

        $entryStmt->bind_param(
            "isssdssii",
            $cycleId,
            $rowRegNo,
            $rowSupplier,
            $rowName,
            $rowAmount,
            $rowReason,
            $matchedRegNo,
            $matchedRegistryId,
            $isMatched
        );
        $entryStmt->execute();
        $stats['saved_rows']++;
        $stats['saved_amount'] += $rowAmount;

        if ($isMatched) {
            $stats['matched_saved_amount'] += $rowAmount;
        }
    }
    $entryStmt->close();

    $conn->commit();
    $reviewRows = array_merge($reviewRows, $analysisReviewRows);
    $reviewExport = buildImportReviewExportPayload(
        'suspension_upload_review_' . $suspensionYear . '_' . str_pad((string)$suspensionMonth, 2, '0', STR_PAD_LEFT),
        $reviewRows,
        $reviewColumns
    );

    if (function_exists('logPayrollAudit')) {
        logPayrollAudit($conn, [
            'cycle_id' => $cycleId,
            'action' => 'upload_suspension_cycle',
            'actor_user_id' => $_SESSION['userId'] ?? '',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'details' => [
                'year' => $suspensionYear,
                'month' => $suspensionMonth,
                'financial_year' => $financialYear,
                'quarter' => $quarter,
                'reason_label' => $cycleReasonLabel,
                'source_file' => $relativePath,
                'rows_uploaded' => $stats['rows_uploaded'],
                'matched_rows' => $stats['matched_rows'],
                'unmatched_rows' => $stats['unmatched_rows'],
                'saved_rows' => $stats['saved_rows'],
                'saved_amount' => round((float)$stats['saved_amount'], 2),
                'matched_saved_amount' => round((float)$stats['matched_saved_amount'], 2)
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Suspension upload processed successfully. Saved amounts were recorded without auto-creating arrears claims.',
        'cycle' => [
            'suspension_cycle_id' => $cycleId,
            'suspension_year' => $suspensionYear,
            'suspension_month' => $suspensionMonth,
            'financial_year' => $financialYear,
            'quarter' => $quarter,
            'reason_label' => $cycleReasonLabel,
            'source_file' => $relativePath
        ],
        'stats' => $stats,
        'review_export' => $reviewExport
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($absolutePath);
    error_log('upload_suspension_arrears error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process suspension upload']);
}

$conn->close();

function parseSuspensionUploadRows(string $absolutePath, string $extension): array {
    $rows = [];
    $ext = strtolower(trim($extension));
    if ($ext === 'csv') {
        $rows = parseSuspensionCsvRows($absolutePath);
    } elseif ($ext === 'xlsx' || $ext === 'xlxl') {
        $rows = parseSuspensionXlsxRows($absolutePath);
    }
    return normalizeSuspensionRows($rows);
}

function parseSuspensionCsvRows(string $absolutePath): array {
    $handle = fopen($absolutePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static function ($value) {
            return trim((string)$value);
        }, $row);
    }
    fclose($handle);

    return $rows;
}

function parseSuspensionXlsxRows(string $absolutePath): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled');
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Unable to open XLSX archive');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedStrings = parseSuspensionXlsxSharedStrings((string)$sharedXml);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $sheetXml = getSuspensionFirstWorksheet($zip);
    }
    $zip->close();

    if ($sheetXml === false || $sheetXml === null) {
        throw new RuntimeException('No worksheet found in XLSX file');
    }

    return parseSuspensionXlsxWorksheet((string)$sheetXml, $sharedStrings);
}

function parseSuspensionXlsxSharedStrings(string $xmlText): array {
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        return [];
    }

    $namespaces = $xml->getNamespaces(true);
    $mainNs = $namespaces[''] ?? null;
    $root = $mainNs ? $xml->children($mainNs) : $xml;
    $strings = [];

    foreach ($root->si as $si) {
        $siNode = $mainNs ? $si->children($mainNs) : $si;
        $value = '';
        if (isset($siNode->t)) {
            $value = (string)$siNode->t;
        } elseif (isset($siNode->r)) {
            foreach ($siNode->r as $run) {
                $runNode = $mainNs ? $run->children($mainNs) : $run;
                $value .= (string)($runNode->t ?? '');
            }
        }
        $strings[] = trim($value);
    }

    return $strings;
}

function parseSuspensionXlsxWorksheet(string $xmlText, array $sharedStrings): array {
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        return [];
    }

    $namespaces = $xml->getNamespaces(true);
    $mainNs = $namespaces[''] ?? null;
    $root = $mainNs ? $xml->children($mainNs) : $xml;
    if (!isset($root->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($root->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $cellRef = strtoupper((string)($attrs['r'] ?? ''));
            $cellType = strtolower((string)($attrs['t'] ?? ''));
            $cellNode = $mainNs ? $cell->children($mainNs) : $cell;

            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/', $cellRef, $matches)) {
                $colIndex = suspensionExcelColumnToIndex($matches[1]);
            }

            $value = '';
            if ($cellType === 's') {
                $sharedIndex = (int)($cellNode->v ?? 0);
                $value = (string)($sharedStrings[$sharedIndex] ?? '');
            } elseif ($cellType === 'inlinestr') {
                if (isset($cellNode->is->t)) {
                    $value = (string)$cellNode->is->t;
                } elseif (isset($cellNode->is->r)) {
                    foreach ($cellNode->is->r as $run) {
                        $runNode = $mainNs ? $run->children($mainNs) : $run;
                        $value .= (string)($runNode->t ?? '');
                    }
                }
            } else {
                $value = (string)($cellNode->v ?? '');
            }
            $rowData[$colIndex] = trim($value);
        }

        if (!empty($rowData)) {
            ksort($rowData);
            $rows[] = $rowData;
        }
    }

    return $rows;
}

function suspensionExcelColumnToIndex(string $columnLetters): int {
    $letters = strtoupper(trim($columnLetters));
    if ($letters === '') {
        return 0;
    }
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function getSuspensionFirstWorksheet(ZipArchive $zip): ?string {
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#i', $name)) {
            $xml = $zip->getFromName($name);
            if ($xml !== false) {
                return (string)$xml;
            }
        }
    }
    return null;
}

function normalizeSuspensionRows(array $rows): array {
    if (empty($rows)) {
        return ['rows' => [], 'review_rows' => [], 'review_columns' => []];
    }

    $displayHeaders = array_map(static function ($value, int $index): string {
        $label = trim((string)$value);
        return $label !== '' ? $label : ('Column ' . ($index + 1));
    }, $rows[0], array_keys($rows[0]));

    $header = array_map(static function ($value) {
        return strtolower(trim((string)$value));
    }, $rows[0]);

    $idxRegNo = findSuspensionHeaderIndex($header, ['regno', 'filenumber', 'file', 'registrynumber', 'registryno', 'pensionno', 'pensionnumber']);
    $idxSupplier = findSuspensionHeaderIndex($header, ['supplierno', 'supplier', 'suppliernumber', 'suppno']);
    $idxName = findSuspensionHeaderIndex($header, ['name', 'beneficiary', 'beneficiaryname', 'pensionername']);
    $idxAmount = findSuspensionHeaderIndex($header, ['amount', 'arrears', 'value', 'missedamount', 'suspendedamount']);
    $idxReason = findSuspensionHeaderIndex($header, ['reason', 'reasonforsuspension', 'suspensionreason', 'remarks', 'comment', 'note']);

    $hasHeader = ($idxRegNo >= 0 || $idxSupplier >= 0 || $idxName >= 0 || $idxAmount >= 0 || $idxReason >= 0);
    if ($idxRegNo < 0) $idxRegNo = 0;
    if ($idxSupplier < 0) $idxSupplier = 1;
    if ($idxName < 0) $idxName = 2;
    if ($idxAmount < 0) $idxAmount = 3;
    if ($idxReason < 0) $idxReason = 4;

    $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
    $normalized = [];
    $reviewRows = [];

    foreach ($dataRows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }

        $rowNumber = $hasHeader ? ($offset + 2) : ($offset + 1);

        $regNo = trim((string)($row[$idxRegNo] ?? ''));
        $supplierNo = trim((string)($row[$idxSupplier] ?? ''));
        $beneficiary = trim((string)($row[$idxName] ?? ''));
        $reason = trim((string)($row[$idxReason] ?? ''));
        $amountRaw = trim((string)($row[$idxAmount] ?? '0'));
        $amountRaw = str_replace(',', '', $amountRaw);
        $amountRaw = preg_replace('/[^0-9.\-]/', '', $amountRaw);
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;

        if ($regNo === '' && $supplierNo === '' && $beneficiary === '') {
            continue;
        }
        if ($amount <= 0) {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Amount must be greater than zero.',
                'Review Fields' => ['amount']
            ]);
            continue;
        }
        if ($reason === '') {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Reason for suspension is required for every uploaded row.',
                'Review Fields' => ['reason']
            ]);
            continue;
        }

        $normalized[] = [
            'row_number' => $rowNumber,
            'regNo' => $regNo,
            'supplierNo' => $supplierNo,
            'beneficiary_name' => $beneficiary,
            'amount' => round(max($amount, 0), 2),
            'reason' => $reason,
            'source_row' => $row
        ];
    }

    return [
        'rows' => $normalized,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

function buildSuspensionReasonSummary(array $rows): string {
    $reasons = [];
    foreach ($rows as $row) {
        $reason = trim((string)($row['reason'] ?? ''));
        if ($reason !== '') {
            $reasons[$reason] = true;
        }
    }
    $reasonList = array_keys($reasons);
    $count = count($reasonList);
    if ($count === 0) {
        return 'Row-level suspension reasons';
    }
    if ($count === 1) {
        return $reasonList[0];
    }
    $preview = array_slice($reasonList, 0, 2);
    $label = implode('; ', $preview);
    if ($count > 2) {
        $label .= ' +' . ($count - 2) . ' more';
    }
    return 'Mixed Reasons - ' . $label;
}

function findSuspensionHeaderIndex(array $headers, array $aliases): int {
    foreach ($headers as $idx => $label) {
        $normalized = preg_replace('/[^a-z0-9]/', '', (string)$label);
        if (in_array($normalized, $aliases, true)) {
            return (int)$idx;
        }
    }
    return -1;
}

function matchSuspensionRowToRegistry(mysqli $conn, string $regNo, string $supplierNo, string $beneficiaryName): array {
    if ($regNo !== '') {
        $stmt = $conn->prepare("SELECT id, regNo FROM tb_fileregistry WHERE regNo = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $regNo);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return ['id' => (int)($row['id'] ?? 0), 'regNo' => (string)($row['regNo'] ?? '')];
            }
        }
    }

    if ($supplierNo !== '') {
        $stmt = $conn->prepare("SELECT id, regNo FROM tb_fileregistry WHERE supplierNo = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $supplierNo);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return ['id' => (int)($row['id'] ?? 0), 'regNo' => (string)($row['regNo'] ?? '')];
            }
        }
    }

    $name = trim($beneficiaryName);
    if ($name !== '') {
        $pattern = '%' . $name . '%';
        $stmt = $conn->prepare("
            SELECT id, regNo
            FROM tb_fileregistry
            WHERE CONCAT_WS(' ', COALESCE(sName, ''), COALESCE(fName, '')) LIKE ?
               OR CONCAT_WS(' ', COALESCE(fName, ''), COALESCE(sName, '')) LIKE ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $pattern, $pattern);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return ['id' => (int)($row['id'] ?? 0), 'regNo' => (string)($row['regNo'] ?? '')];
            }
        }
    }

    return ['id' => 0, 'regNo' => ''];
}
?>
