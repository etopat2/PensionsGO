<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'payroll.upload')) {
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

if (!isset($_FILES['payroll_file']) || (int)($_FILES['payroll_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Payroll file is required']);
    exit;
}

// Only normalized tabular formats are accepted to keep parser behavior deterministic.
$payrollFile = $_FILES['payroll_file'];
enforceUploadedFileSizeLimit($conn, $payrollFile, 'Payroll upload file');
$payrollOriginalName = (string)($payrollFile['name'] ?? 'payroll');
$payrollExt = strtolower(pathinfo($payrollOriginalName, PATHINFO_EXTENSION));
$payrollSize = (int)($payrollFile['size'] ?? 0);
$payrollMime = (string)($payrollFile['type'] ?? '');

if (!in_array($payrollExt, ['csv', 'xlsx', 'xlxl', 'xls'], true)) {
    echo json_encode(['success' => false, 'message' => 'Payroll file must be .csv or .xlsx']);
    exit;
}
if ($payrollExt === 'xls') {
    echo json_encode(['success' => false, 'message' => 'Legacy .xls is not supported. Save and upload as .xlsx or .csv']);
    exit;
}
if ($payrollSize <= 0) {
    echo json_encode(['success' => false, 'message' => 'Payroll file must not be empty']);
    exit;
}

$paymentRegisterFile = $_FILES['payment_register_file'] ?? null;
$hasPaymentRegister = is_array($paymentRegisterFile)
    && isset($paymentRegisterFile['error'])
    && (int)$paymentRegisterFile['error'] !== UPLOAD_ERR_NO_FILE;

if ($hasPaymentRegister && (int)($paymentRegisterFile['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Failed to upload payment register PDF']);
    exit;
}

$paymentRegisterOriginalName = '';
$paymentRegisterMime = '';
if ($hasPaymentRegister) {
    enforceUploadedFileSizeLimit($conn, $paymentRegisterFile, 'Payment register PDF');
    $paymentRegisterOriginalName = (string)($paymentRegisterFile['name'] ?? 'payment_register.pdf');
    $paymentRegisterExt = strtolower(pathinfo($paymentRegisterOriginalName, PATHINFO_EXTENSION));
    $paymentRegisterMime = (string)($paymentRegisterFile['type'] ?? '');
    $paymentRegisterSize = (int)($paymentRegisterFile['size'] ?? 0);

    if ($paymentRegisterExt !== 'pdf') {
        echo json_encode(['success' => false, 'message' => 'Payment register must be a PDF file']);
        exit;
    }
    if ($paymentRegisterSize <= 0) {
        echo json_encode(['success' => false, 'message' => 'Payment register PDF must not be empty']);
        exit;
    }
}

$baseUploadDir = __DIR__ . '/../uploads/payroll';
$cyclesDir = $baseUploadDir . '/cycles';
$registersDir = $baseUploadDir . '/registers';

foreach ([$baseUploadDir, $cyclesDir, $registersDir] as $dirPath) {
    if (!is_dir($dirPath) && !mkdir($dirPath, 0775, true)) {
        echo json_encode(['success' => false, 'message' => 'Unable to create payroll upload directory']);
        exit;
    }
}

$safePayrollBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($payrollOriginalName, PATHINFO_FILENAME));
$payrollTimestamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$normalizedPayrollExt = ($payrollExt === 'xlxl') ? 'xlsx' : $payrollExt;
$storedPayrollName = $safePayrollBase . '_' . $payrollTimestamp . '.' . $normalizedPayrollExt;
$storedPayrollAbsolutePath = $cyclesDir . '/' . $storedPayrollName;
$storedPayrollRelativePath = 'uploads/payroll/cycles/' . $storedPayrollName;

if ($normalizedPayrollExt === 'xlsx') {
    enforceZipArchiveSafety($conn, (string)$payrollFile['tmp_name'], 'Payroll workbook');
}

if (!move_uploaded_file((string)$payrollFile['tmp_name'], $storedPayrollAbsolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save uploaded payroll file']);
    exit;
}

$storedRegisterName = null;
$storedRegisterAbsolutePath = null;
$storedRegisterRelativePath = null;
if ($hasPaymentRegister) {
    $safeRegisterBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($paymentRegisterOriginalName, PATHINFO_FILENAME));
    $registerTimestamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $storedRegisterName = $safeRegisterBase . '_' . $registerTimestamp . '.pdf';
    $storedRegisterAbsolutePath = $registersDir . '/' . $storedRegisterName;
    $storedRegisterRelativePath = 'uploads/payroll/registers/' . $storedRegisterName;

    if (!move_uploaded_file((string)$paymentRegisterFile['tmp_name'], $storedRegisterAbsolutePath)) {
        @unlink($storedPayrollAbsolutePath);
        echo json_encode(['success' => false, 'message' => 'Unable to save payment register PDF']);
        exit;
    }
}

try {
    $parsed = parsePayrollUploadRows($storedPayrollAbsolutePath, $normalizedPayrollExt);
    $rows = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
    enforceParsedRowLimit($conn, count($rows) + count($reviewRows), 'Payroll upload');
} catch (Throwable $parseError) {
    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Unable to read payroll file: ' . $parseError->getMessage()
    ]);
    exit;
}

if (empty($rows)) {
    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }
    $reviewExport = buildImportReviewExportPayload('payroll_upload_review', $reviewRows, $reviewColumns);
    echo json_encode([
        'success' => !empty($reviewRows),
        'message' => !empty($reviewRows)
            ? 'No payroll rows were imported. Review and correct the downloaded file, then upload again.'
            : 'No valid payroll records found in the uploaded file',
        'stats' => [
            'matched' => 0,
            'unmatched' => 0,
            'on_payroll' => 0,
            'off_payroll' => 0
        ],
        'review_export' => $reviewExport
    ]);
    exit;
}

$reviewRows = array_merge(
    $reviewRows,
    buildPayrollUploadUnmatchedReviewRows($conn, $rows, $reviewColumns)
);
$reviewExport = buildImportReviewExportPayload(
    'payroll_upload_review_' . $payrollYear . '_' . str_pad((string)$payrollMonth, 2, '0', STR_PAD_LEFT),
    $reviewRows,
    $reviewColumns
);

$financialYearLabel = getFinancialYearLabelForMonth($payrollYear, $payrollMonth);
$quarterLabel = getQuarterLabelForMonth($payrollMonth);

$conn->begin_transaction();
try {
    // Transaction boundary ensures cycle header, uploaded rows, and registry matching
    // either commit together or roll back together.
    $cycleStmt = $conn->prepare("
        INSERT INTO tb_payroll_upload_cycles
        (
            payroll_year,
            payroll_month,
            financial_year_label,
            quarter_label,
            uploaded_by,
            source_file,
            source_file_original_name,
            source_file_mime,
            payment_register_file,
            payment_register_original_name,
            payment_register_mime,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$cycleStmt) {
        throw new RuntimeException('Unable to create payroll upload cycle');
    }

    $uploadedBy = (string)$_SESSION['userId'];
    $cycleStmt->bind_param(
        "iissssssssss",
        $payrollYear,
        $payrollMonth,
        $financialYearLabel,
        $quarterLabel,
        $uploadedBy,
        $storedPayrollRelativePath,
        $payrollOriginalName,
        $payrollMime,
        $storedRegisterRelativePath,
        $paymentRegisterOriginalName,
        $paymentRegisterMime,
        $notes
    );
    $cycleStmt->execute();
    $cycleId = (int)$cycleStmt->insert_id;
    $cycleStmt->close();

    $entryStmt = $conn->prepare("
        INSERT INTO tb_payroll_upload_entries
        (cycle_id, supplierNo, beneficiary_name, amount, matched_regNo, matched_registry_id, is_matched)
        VALUES (?, ?, ?, ?, NULL, NULL, 0)
    ");
    if (!$entryStmt) {
        throw new RuntimeException('Unable to save payroll upload entries');
    }

    foreach ($rows as $row) {
        $supplierNo = $row['supplierNo'];
        $beneficiaryName = $row['beneficiary'];
        $amount = $row['amount'];
        $entryStmt->bind_param("issd", $cycleId, $supplierNo, $beneficiaryName, $amount);
        $entryStmt->execute();
    }
    $entryStmt->close();

    // Reconciliation marks matched/unmatched rows and updates registry payroll flags.
    $stats = applyPayrollCycleToRegistry($conn, $cycleId, $payrollYear, $payrollMonth);
    $conn->commit();

    logPayrollAudit($conn, [
        'cycle_id' => $cycleId,
        'action' => 'upload_cycle',
        'actor_user_id' => $_SESSION['userId'] ?? '',
        'actor_role' => $_SESSION['userRole'] ?? '',
        'details' => [
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'financial_year' => $financialYearLabel,
            'quarter' => $quarterLabel,
            'source_file' => $storedPayrollRelativePath,
            'payment_register_file' => $storedRegisterRelativePath,
            'rows_uploaded' => count($rows),
            'matched_rows' => $stats['matched'] ?? 0,
            'unmatched_rows' => $stats['unmatched'] ?? 0
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Payroll uploaded and processed successfully.',
        'cycle' => [
            'cycle_id' => $cycleId,
            'payroll_year' => $payrollYear,
            'payroll_month' => $payrollMonth,
            'financial_year' => $financialYearLabel,
            'quarter' => $quarterLabel,
            'source_file' => $storedPayrollRelativePath,
            'source_file_name' => $payrollOriginalName,
            'payment_register_file' => $storedRegisterRelativePath,
            'payment_register_file_name' => $paymentRegisterOriginalName
        ],
        'stats' => $stats,
        'review_export' => $reviewExport
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('upload_payroll_cycle error: ' . $e->getMessage());

    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }

    logPayrollAudit($conn, [
        'action' => 'upload_cycle_failed',
        'actor_user_id' => $_SESSION['userId'] ?? '',
        'actor_role' => $_SESSION['userRole'] ?? '',
        'details' => [
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'source_file_name' => $payrollOriginalName,
            'payment_register_file_name' => $paymentRegisterOriginalName,
            'error' => $e->getMessage()
        ]
    ]);

    echo json_encode([
        'success' => false,
        'message' => 'Failed to process payroll upload.'
    ]);
}

$conn->close();

function parsePayrollUploadRows(string $absolutePath, string $extension): array {
    $normalizedExt = strtolower(trim($extension));
    if ($normalizedExt === 'csv') {
        $rows = parseCsvRows($absolutePath);
    } elseif ($normalizedExt === 'xlsx' || $normalizedExt === 'xlxl') {
        $rows = parseXlsxRows($absolutePath);
    } else {
        throw new RuntimeException('Unsupported payroll file format');
    }

    if (empty($rows)) {
        return [];
    }

    return normalizePayrollRows($rows);
}

function parseCsvRows(string $absolutePath): array {
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

function parseXlsxRows(string $absolutePath): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled on this server');
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Unable to open XLSX archive');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedStrings = parseXlsxSharedStrings($sharedXml);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $sheetXml = getFirstWorksheetXml($zip);
    }
    $zip->close();

    if ($sheetXml === false || $sheetXml === null) {
        throw new RuntimeException('No worksheet found in XLSX file');
    }

    return parseXlsxWorksheetRows((string)$sheetXml, $sharedStrings);
}

function parseXlsxSharedStrings(string $xmlText): array {
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

function parseXlsxWorksheetRows(string $xmlText, array $sharedStrings): array {
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        throw new RuntimeException('Unable to parse worksheet XML');
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
            $cellAttrs = $cell->attributes();
            $cellRef = strtoupper((string)($cellAttrs['r'] ?? ''));
            $cellType = strtolower((string)($cellAttrs['t'] ?? ''));
            $cellNode = $mainNs ? $cell->children($mainNs) : $cell;

            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/', $cellRef, $matches)) {
                $colIndex = excelColumnToIndex($matches[1]);
            }

            $value = '';
            if ($cellType === 's') {
                $sharedIndex = (int)($cellNode->v ?? 0);
                $value = (string)($sharedStrings[$sharedIndex] ?? '');
            } elseif ($cellType === 'inlineStr') {
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

function excelColumnToIndex(string $columnLetters): int {
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

function getFirstWorksheetXml(ZipArchive $zip): ?string {
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

function normalizePayrollRows(array $rows): array {
    if (empty($rows)) {
        return ['rows' => [], 'review_rows' => [], 'review_columns' => []];
    }

    $displayHeaders = array_map(static function ($value, int $index): string {
        $label = trim((string)$value);
        return $label !== '' ? $label : ('Column ' . ($index + 1));
    }, $rows[0], array_keys($rows[0]));

    $indexSupplier = 0;
    $indexName = 1;
    $indexAmount = 2;

    $headerRow = $rows[0];
    $normalizedHeader = [];
    foreach ($headerRow as $value) {
        $normalizedHeader[] = strtolower(trim((string)$value));
    }

    $supplierIdx = findHeaderIndex($normalizedHeader, ['supplierno', 'suppliernumber', 'supplier', 'suppno']);
    $nameIdx = findHeaderIndex($normalizedHeader, ['name', 'beneficiaryname', 'beneficiary', 'fullname']);
    $amountIdx = findHeaderIndex($normalizedHeader, ['amount', 'netpay', 'pay', 'value']);

    $dataRows = $rows;
    $looksLikeHeader = ($supplierIdx >= 0 || $nameIdx >= 0 || $amountIdx >= 0);
    if ($looksLikeHeader) {
        if ($supplierIdx >= 0) $indexSupplier = $supplierIdx;
        if ($nameIdx >= 0) $indexName = $nameIdx;
        if ($amountIdx >= 0) $indexAmount = $amountIdx;
        $dataRows = array_slice($rows, 1);
    }

    $normalizedRows = [];
    $reviewRows = [];
    foreach ($dataRows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }

        $rowNumber = $looksLikeHeader ? ($offset + 2) : ($offset + 1);

        $supplierNo = trim((string)($row[$indexSupplier] ?? ''));
        $beneficiary = trim((string)($row[$indexName] ?? ''));
        $amountRaw = trim((string)($row[$indexAmount] ?? '0'));
        $amountRaw = str_replace(',', '', $amountRaw);
        $amountRaw = preg_replace('/[^0-9.\-]/', '', $amountRaw);
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;

        if ($supplierNo === '') {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Supplier Number is required to match payroll rows to the registry.',
                'Review Fields' => ['supplierNo']
            ]);
            continue;
        }

        $normalizedRows[] = [
            'row_number' => $rowNumber,
            'supplierNo' => $supplierNo,
            'beneficiary' => $beneficiary,
            'amount' => $amount,
            'source_row' => $row
        ];
    }

    return [
        'rows' => $normalizedRows,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

function findHeaderIndex(array $headers, array $candidates): int {
    foreach ($headers as $idx => $name) {
        $normalized = preg_replace('/[^a-z0-9]/', '', (string)$name);
        if (in_array($normalized, $candidates, true)) {
            return (int)$idx;
        }
    }
    return -1;
}

function buildPayrollUploadUnmatchedReviewRows(mysqli $conn, array $rows, array $reviewColumns): array {
    if (empty($rows)) {
        return [];
    }

    $supplierMap = [];
    $result = $conn->query("SELECT supplierNo FROM tb_fileregistry WHERE supplierNo IS NOT NULL AND TRIM(supplierNo) <> ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = strtolower(trim((string)($row['supplierNo'] ?? '')));
            if ($key !== '') {
                $supplierMap[$key] = true;
            }
        }
        $result->free();
    }

    $rowsNeedingReview = [];
    $headers = $reviewColumns ? array_slice($reviewColumns, 5) : [];
    foreach ($rows as $row) {
        $supplierNo = strtolower(trim((string)($row['supplierNo'] ?? '')));
        if ($supplierNo !== '' && isset($supplierMap[$supplierNo])) {
            continue;
        }
        $rowsNeedingReview[] = buildImportReviewRowFromSource($headers, (array)($row['source_row'] ?? []), [
            'Source Row' => (int)($row['row_number'] ?? 0),
            'Review Status' => 'Unmatched',
            'Review Reason' => 'No pension file registry record matched this supplier number.',
            'Review Fields' => ['supplierNo'],
            'Matched Key' => (string)($row['supplierNo'] ?? '')
        ]);
    }

    return $rowsNeedingReview;
}
?>
