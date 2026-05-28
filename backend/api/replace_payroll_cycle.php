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

if (!currentUserHasPermission($conn, 'payroll.manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

ensurePayrollManagementTables($conn);

$cycleId = (int)($_POST['cycle_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
if ($cycleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid payroll cycle is required']);
    exit;
}

if (!isset($_FILES['payroll_file']) || (int)($_FILES['payroll_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Replacement payroll file is required']);
    exit;
}

$cycleStmt = $conn->prepare("
    SELECT
        cycle_id,
        payroll_year,
        payroll_month,
        source_file,
        payment_register_file
    FROM tb_payroll_upload_cycles
    WHERE cycle_id = ?
      AND COALESCE(is_deleted, 0) = 0
    LIMIT 1
");
if (!$cycleStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll cycle lookup']);
    exit;
}
$cycleStmt->bind_param("i", $cycleId);
$cycleStmt->execute();
$cycle = $cycleStmt->get_result()->fetch_assoc();
$cycleStmt->close();

if (!$cycle) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Payroll cycle not found']);
    exit;
}

$payrollYear = (int)$cycle['payroll_year'];
$payrollMonth = (int)$cycle['payroll_month'];
$oldSourceFile = trim((string)($cycle['source_file'] ?? ''));
$oldRegisterFile = trim((string)($cycle['payment_register_file'] ?? ''));

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
$replaceRegister = is_array($paymentRegisterFile)
    && isset($paymentRegisterFile['error'])
    && (int)$paymentRegisterFile['error'] !== UPLOAD_ERR_NO_FILE;

$paymentRegisterOriginalName = '';
$paymentRegisterMime = '';
if ($replaceRegister) {
    if ((int)($paymentRegisterFile['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload replacement payment register']);
        exit;
    }
    $paymentRegisterOriginalName = (string)($paymentRegisterFile['name'] ?? 'payment_register.pdf');
    $paymentRegisterExt = strtolower(pathinfo($paymentRegisterOriginalName, PATHINFO_EXTENSION));
    $paymentRegisterMime = (string)($paymentRegisterFile['type'] ?? '');
    $paymentRegisterSize = (int)($paymentRegisterFile['size'] ?? 0);
    if ($paymentRegisterExt !== 'pdf') {
        echo json_encode(['success' => false, 'message' => 'Payment register must be a PDF']);
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
        echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll upload directories']);
        exit;
    }
}

$safePayrollBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($payrollOriginalName, PATHINFO_FILENAME));
$fileToken = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$normalizedPayrollExt = ($payrollExt === 'xlxl') ? 'xlsx' : $payrollExt;
$storedPayrollName = $safePayrollBase . '_replace_' . $fileToken . '.' . $normalizedPayrollExt;
$storedPayrollAbsolutePath = $cyclesDir . '/' . $storedPayrollName;
$storedPayrollRelativePath = 'uploads/payroll/cycles/' . $storedPayrollName;

if ($normalizedPayrollExt === 'xlsx') {
    enforceZipArchiveSafety($conn, (string)$payrollFile['tmp_name'], 'Payroll workbook');
}

if (!move_uploaded_file((string)$payrollFile['tmp_name'], $storedPayrollAbsolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to store replacement payroll file']);
    exit;
}

$storedRegisterAbsolutePath = null;
$storedRegisterRelativePath = null;
if ($replaceRegister) {
    $safeRegisterBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($paymentRegisterOriginalName, PATHINFO_FILENAME));
    $storedRegisterName = $safeRegisterBase . '_replace_' . $fileToken . '.pdf';
    $storedRegisterAbsolutePath = $registersDir . '/' . $storedRegisterName;
    $storedRegisterRelativePath = 'uploads/payroll/registers/' . $storedRegisterName;
    if (!move_uploaded_file((string)$paymentRegisterFile['tmp_name'], $storedRegisterAbsolutePath)) {
        @unlink($storedPayrollAbsolutePath);
        echo json_encode(['success' => false, 'message' => 'Unable to store replacement payment register']);
        exit;
    }
}

try {
    $parsed = replaceParsePayrollRows($storedPayrollAbsolutePath, $normalizedPayrollExt);
    $rows = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
} catch (Throwable $parseError) {
    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }
    echo json_encode(['success' => false, 'message' => 'Unable to parse replacement payroll file: ' . $parseError->getMessage()]);
    exit;
}

if (empty($rows)) {
    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }
    $reviewExport = buildImportReviewExportPayload('payroll_replacement_review', $reviewRows, $reviewColumns);
    echo json_encode([
        'success' => !empty($reviewRows),
        'message' => !empty($reviewRows)
            ? 'No payroll rows were imported. Review and correct the downloaded file, then upload again.'
            : 'No valid payroll rows found in replacement file',
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
    replaceBuildPayrollUnmatchedReviewRows($conn, $rows, $reviewColumns)
);
$reviewExport = buildImportReviewExportPayload(
    'payroll_replacement_review_' . $payrollYear . '_' . str_pad((string)$payrollMonth, 2, '0', STR_PAD_LEFT),
    $reviewRows,
    $reviewColumns
);

$financialYearLabel = getFinancialYearLabelForMonth($payrollYear, $payrollMonth);
$quarterLabel = getQuarterLabelForMonth($payrollMonth);
$newRegisterRelativePath = $replaceRegister ? $storedRegisterRelativePath : $oldRegisterFile;
$newRegisterName = $replaceRegister ? $paymentRegisterOriginalName : null;
$newRegisterMime = $replaceRegister ? $paymentRegisterMime : null;

$conn->begin_transaction();
try {
    $updateCycleStmt = $conn->prepare("
        UPDATE tb_payroll_upload_cycles
        SET
            financial_year_label = ?,
            quarter_label = ?,
            uploaded_by = ?,
            source_file = ?,
            source_file_original_name = ?,
            source_file_mime = ?,
            payment_register_file = ?,
            payment_register_original_name = COALESCE(?, payment_register_original_name),
            payment_register_mime = COALESCE(?, payment_register_mime),
            notes = ?
        WHERE cycle_id = ?
          AND COALESCE(is_deleted, 0) = 0
        LIMIT 1
    ");
    if (!$updateCycleStmt) {
        throw new RuntimeException('Unable to prepare replacement cycle update');
    }
    $uploadedBy = (string)($_SESSION['userId'] ?? '');
    $notesValue = $notes !== '' ? $notes : ('Replaced on ' . date('Y-m-d H:i:s'));
    $updateCycleStmt->bind_param(
        "ssssssssssi",
        $financialYearLabel,
        $quarterLabel,
        $uploadedBy,
        $storedPayrollRelativePath,
        $payrollOriginalName,
        $payrollMime,
        $newRegisterRelativePath,
        $newRegisterName,
        $newRegisterMime,
        $notesValue,
        $cycleId
    );
    $updateCycleStmt->execute();
    $updateCycleStmt->close();

    $deleteEntriesStmt = $conn->prepare("DELETE FROM tb_payroll_upload_entries WHERE cycle_id = ?");
    if (!$deleteEntriesStmt) {
        throw new RuntimeException('Unable to clear previous payroll entries');
    }
    $deleteEntriesStmt->bind_param("i", $cycleId);
    $deleteEntriesStmt->execute();
    $deleteEntriesStmt->close();

    $insertEntryStmt = $conn->prepare("
        INSERT INTO tb_payroll_upload_entries
        (cycle_id, supplierNo, beneficiary_name, amount, matched_regNo, matched_registry_id, is_matched)
        VALUES (?, ?, ?, ?, NULL, NULL, 0)
    ");
    if (!$insertEntryStmt) {
        throw new RuntimeException('Unable to insert replacement payroll entries');
    }
    foreach ($rows as $row) {
        $supplierNo = $row['supplierNo'];
        $beneficiaryName = $row['beneficiary'];
        $amount = $row['amount'];
        $insertEntryStmt->bind_param("issd", $cycleId, $supplierNo, $beneficiaryName, $amount);
        $insertEntryStmt->execute();
    }
    $insertEntryStmt->close();

    $stats = applyPayrollCycleToRegistry($conn, $cycleId, $payrollYear, $payrollMonth);
    $conn->commit();

    replaceDeletePayrollFileIfSafe($oldSourceFile, $storedPayrollRelativePath);
    if ($replaceRegister) {
        replaceDeletePayrollFileIfSafe($oldRegisterFile, $storedRegisterRelativePath);
    }

    logPayrollAudit($conn, [
        'cycle_id' => $cycleId,
        'action' => 'replace_cycle',
        'actor_user_id' => $_SESSION['userId'] ?? '',
        'actor_role' => $_SESSION['userRole'] ?? '',
        'details' => [
            'replacement_rows' => count($rows),
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'source_file' => $storedPayrollRelativePath,
            'payment_register_file' => $newRegisterRelativePath,
            'matched_rows' => $stats['matched'] ?? 0,
            'unmatched_rows' => $stats['unmatched'] ?? 0
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Payroll cycle replaced successfully.',
        'cycle' => [
            'cycleId' => $cycleId,
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'financialYear' => $financialYearLabel,
            'quarter' => $quarterLabel,
            'sourceFile' => $storedPayrollRelativePath,
            'sourceFileName' => $payrollOriginalName,
            'paymentRegisterFile' => $newRegisterRelativePath,
            'paymentRegisterFileName' => $replaceRegister ? $paymentRegisterOriginalName : null
        ],
        'stats' => $stats,
        'review_export' => $reviewExport
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('replace_payroll_cycle error: ' . $e->getMessage());
    @unlink($storedPayrollAbsolutePath);
    if ($storedRegisterAbsolutePath) {
        @unlink($storedRegisterAbsolutePath);
    }
    echo json_encode(['success' => false, 'message' => 'Unable to replace payroll cycle.']);
}

$conn->close();

function replaceDeletePayrollFileIfSafe(?string $oldRelativePath, ?string $newRelativePath): void {
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

function replaceParsePayrollRows(string $absolutePath, string $extension): array {
    $ext = strtolower(trim($extension));
    if ($ext === 'csv') {
        $rows = replaceParseCsvRows($absolutePath);
    } elseif ($ext === 'xlsx' || $ext === 'xlxl') {
        $rows = replaceParseXlsxRows($absolutePath);
    } else {
        throw new RuntimeException('Unsupported file format');
    }
    return replaceNormalizeRows($rows);
}

function replaceParseCsvRows(string $absolutePath): array {
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

function replaceParseXlsxRows(string $absolutePath): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled');
    }
    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Unable to open XLSX file');
    }

    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#i', $name)) {
                $sheetXml = $zip->getFromName($name);
                if ($sheetXml !== false) {
                    break;
                }
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Worksheet not found in XLSX');
    }

    $sharedStrings = [];
    if ($sharedStringsXml !== false) {
        $sharedRoot = simplexml_load_string((string)$sharedStringsXml);
        if ($sharedRoot !== false) {
            $sharedNs = $sharedRoot->getNamespaces(true);
            $sharedMainNs = $sharedNs[''] ?? null;
            $sharedBase = $sharedMainNs ? $sharedRoot->children($sharedMainNs) : $sharedRoot;
            foreach ($sharedBase->si as $item) {
                $itemNode = $sharedMainNs ? $item->children($sharedMainNs) : $item;
                $text = '';
                if (isset($itemNode->t)) {
                    $text = (string)$itemNode->t;
                } elseif (isset($itemNode->r)) {
                    foreach ($itemNode->r as $run) {
                        $runNode = $sharedMainNs ? $run->children($sharedMainNs) : $run;
                        $text .= (string)($runNode->t ?? '');
                    }
                }
                $sharedStrings[] = trim($text);
            }
        }
    }

    $sheetRoot = simplexml_load_string((string)$sheetXml);
    if ($sheetRoot === false) {
        throw new RuntimeException('Unable to parse worksheet');
    }
    $sheetNs = $sheetRoot->getNamespaces(true);
    $sheetMainNs = $sheetNs[''] ?? null;
    $sheetBase = $sheetMainNs ? $sheetRoot->children($sheetMainNs) : $sheetRoot;
    if (!isset($sheetBase->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($sheetBase->sheetData->row as $rowNode) {
        $rowData = [];
        foreach ($rowNode->c as $cellNode) {
            $attrs = $cellNode->attributes();
            $cellRef = strtoupper((string)($attrs['r'] ?? ''));
            $cellType = strtolower((string)($attrs['t'] ?? ''));

            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/', $cellRef, $matches)) {
                $letters = $matches[1];
                $idx = 0;
                for ($i = 0; $i < strlen($letters); $i++) {
                    $idx = ($idx * 26) + (ord($letters[$i]) - 64);
                }
                $colIndex = max(0, $idx - 1);
            }

            $value = '';
            $valueNode = $sheetMainNs ? $cellNode->children($sheetMainNs) : $cellNode;
            if ($cellType === 's') {
                $sharedIndex = (int)($valueNode->v ?? 0);
                $value = (string)($sharedStrings[$sharedIndex] ?? '');
            } elseif ($cellType === 'inlineStr') {
                if (isset($valueNode->is->t)) {
                    $value = (string)$valueNode->is->t;
                }
            } else {
                $value = (string)($valueNode->v ?? '');
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

function replaceNormalizeRows(array $rows): array {
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

    $header = array_map(static function ($val) {
        return strtolower(trim((string)$val));
    }, $rows[0] ?? []);

    $finder = static function (array $headers, array $candidates): int {
        foreach ($headers as $idx => $name) {
            $norm = preg_replace('/[^a-z0-9]/', '', (string)$name);
            if (in_array($norm, $candidates, true)) {
                return (int)$idx;
            }
        }
        return -1;
    };

    $sIdx = $finder($header, ['supplierno', 'suppliernumber', 'supplier', 'suppno']);
    $nIdx = $finder($header, ['name', 'beneficiaryname', 'beneficiary', 'fullname']);
    $aIdx = $finder($header, ['amount', 'netpay', 'pay', 'value']);
    $hasHeader = ($sIdx >= 0 || $nIdx >= 0 || $aIdx >= 0);
    if ($sIdx >= 0) $indexSupplier = $sIdx;
    if ($nIdx >= 0) $indexName = $nIdx;
    if ($aIdx >= 0) $indexAmount = $aIdx;
    if ($hasHeader) {
        $rows = array_slice($rows, 1);
    }

    $output = [];
    $reviewRows = [];
    foreach ($rows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $rowNumber = $hasHeader ? ($offset + 2) : ($offset + 1);
        $supplierNo = trim((string)($row[$indexSupplier] ?? ''));
        if ($supplierNo === '') {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Supplier Number is required to match payroll rows to the registry.',
                'Review Fields' => ['supplierNo']
            ]);
            continue;
        }
        $beneficiary = trim((string)($row[$indexName] ?? ''));
        $amountRaw = trim((string)($row[$indexAmount] ?? '0'));
        $amountRaw = str_replace(',', '', $amountRaw);
        $amountRaw = preg_replace('/[^0-9.\-]/', '', $amountRaw);
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
        $output[] = [
            'row_number' => $rowNumber,
            'supplierNo' => $supplierNo,
            'beneficiary' => $beneficiary,
            'amount' => $amount,
            'source_row' => $row
        ];
    }
    return [
        'rows' => $output,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

function replaceBuildPayrollUnmatchedReviewRows(mysqli $conn, array $rows, array $reviewColumns): array {
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

    $headers = $reviewColumns ? array_slice($reviewColumns, 5) : [];
    $reviewRows = [];
    foreach ($rows as $row) {
        $supplierNo = strtolower(trim((string)($row['supplierNo'] ?? '')));
        if ($supplierNo !== '' && isset($supplierMap[$supplierNo])) {
            continue;
        }
        $reviewRows[] = buildImportReviewRowFromSource($headers, (array)($row['source_row'] ?? []), [
            'Source Row' => (int)($row['row_number'] ?? 0),
            'Review Status' => 'Unmatched',
            'Review Reason' => 'No pension file registry record matched this supplier number.',
            'Review Fields' => ['supplierNo'],
            'Matched Key' => (string)($row['supplierNo'] ?? '')
        ]);
    }

    return $reviewRows;
}
?>
