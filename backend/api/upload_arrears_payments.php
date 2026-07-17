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

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureArrearsAndBudgetTables($conn);

$defaultClaimType = normalizeArrearsClaimType((string)($_POST['default_claim_type'] ?? 'Pension Arrears'));
$defaultPaymentDate = trim((string)($_POST['default_payment_date'] ?? date('Y-m-d')));

if (!isset($_FILES['payment_file']) || (int)($_FILES['payment_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Payment file is required']);
    exit;
}

$upload = $_FILES['payment_file'];
enforceUploadedFileSizeLimit($conn, $upload, 'Payment upload file');
$originalName = (string)($upload['name'] ?? 'payment_file');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$normalizedExt = $ext === 'xlxl' ? 'xlsx' : $ext;
if (!in_array($normalizedExt, ['csv', 'xlsx'], true)) {
    echo json_encode(['success' => false, 'message' => 'Payment file must be .csv or .xlsx']);
    exit;
}

$tmpPath = (string)($upload['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    echo json_encode(['success' => false, 'message' => 'Invalid uploaded payment file']);
    exit;
}

if ($normalizedExt === 'xlsx') {
    enforceZipArchiveSafety($conn, $tmpPath, 'Payment workbook');
}

try {
    $parsed = parseBulkArrearsPaymentRows($tmpPath, $normalizedExt);
    $rows = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
    enforceParsedRowLimit($conn, count($rows) + count($reviewRows), 'Payment upload');
    if (empty($rows)) {
        $reviewExport = buildImportReviewExportPayload('arrears_payments_review', $reviewRows, $reviewColumns);
        echo json_encode([
            'success' => !empty($reviewRows),
            'message' => !empty($reviewRows)
                ? 'No payment rows were saved. Review and correct the downloaded file, then upload again.'
                : 'No valid payment rows were found in the uploaded file',
            'stats' => [
                'rowsUploaded' => 0,
                'matchedRows' => 0,
                'unmatchedRows' => 0,
                'savedPayments' => 0,
                'failedRows' => count($reviewRows)
            ],
            'errors' => [],
            'review_export' => $reviewExport
        ]);
        exit;
    }
} catch (Throwable $e) {
    error_log('upload_arrears_payments parse error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to read payment file']);
    exit;
}

$stats = [
    'rowsUploaded' => count($rows),
    'matchedRows' => 0,
    'unmatchedRows' => 0,
    'savedPayments' => 0,
    'failedRows' => 0
];
$errors = [];

foreach ($rows as $index => $row) {
    $rowNumber = (int)($row['row_number'] ?? ($index + 2));
    $supplierNo = trim((string)($row['supplierNo'] ?? ''));
    $claimType = normalizeArrearsClaimType((string)($row['claimType'] ?? $defaultClaimType));
    $amount = round(max((float)($row['amount'] ?? 0), 0), 2);
    $paymentDate = normalizeBulkPaymentDate((string)($row['paymentDate'] ?? ''), $defaultPaymentDate);
    $referenceNo = trim((string)($row['referenceNo'] ?? ''));
    $notes = trim((string)($row['notes'] ?? ''));

    if ($supplierNo === '' || $amount <= 0 || $paymentDate === '') {
        $stats['failedRows']++;
        $reason = 'Supplier Number, Amount, and Payment Date are required.';
        $errors[] = 'Row ' . $rowNumber . ' is missing supplier number, amount, or payment date.';
        $reviewRows[] = buildImportReviewRowFromSource($reviewColumns ? array_slice($reviewColumns, 5) : [], (array)($row['source_row'] ?? []), [
            'Source Row' => $rowNumber,
            'Review Status' => 'Invalid',
            'Review Reason' => $reason,
            'Review Fields' => ['supplierNo', 'amount', 'paymentDate'],
            'Matched Key' => $supplierNo
        ]);
        continue;
    }

    $matchStmt = $conn->prepare("SELECT regNo FROM tb_fileregistry WHERE supplierNo = ? LIMIT 1");
    $regNo = '';
    if ($matchStmt) {
        $matchStmt->bind_param("s", $supplierNo);
        $matchStmt->execute();
        $regNo = (string)(($matchStmt->get_result()->fetch_assoc() ?: [])['regNo'] ?? '');
        $matchStmt->close();
    }
    if ($regNo === '') {
        $stats['unmatchedRows']++;
        $reason = 'No registry record matched supplier number ' . $supplierNo . '.';
        $errors[] = 'Row ' . $rowNumber . ' did not match any registry record for supplier number ' . $supplierNo . '.';
        $reviewRows[] = buildImportReviewRowFromSource($reviewColumns ? array_slice($reviewColumns, 5) : [], (array)($row['source_row'] ?? []), [
            'Source Row' => $rowNumber,
            'Review Status' => 'Unmatched',
            'Review Reason' => $reason,
            'Review Fields' => ['supplierNo'],
            'Matched Key' => $supplierNo
        ]);
        continue;
    }

    $stats['matchedRows']++;
    $result = recordArrearsPayment($conn, [
        'regNo' => $regNo,
        'claim_type' => $claimType,
        'amount' => $amount,
        'payment_date' => $paymentDate,
        'reference_no' => $referenceNo,
        'notes' => $notes,
        'recorded_by' => (string)($_SESSION['userId'] ?? '')
    ]);
    if (!empty($result['success'])) {
        $stats['savedPayments']++;
    } else {
        $stats['failedRows']++;
        $reason = (string)($result['message'] ?? 'payment was not recorded');
        $errors[] = 'Row ' . $rowNumber . ': ' . $reason;
        $reviewRows[] = buildImportReviewRowFromSource($reviewColumns ? array_slice($reviewColumns, 5) : [], (array)($row['source_row'] ?? []), [
            'Source Row' => $rowNumber,
            'Review Status' => 'Failed',
            'Review Reason' => $reason,
            'Review Fields' => ['amount', 'paymentDate', 'claimType'],
            'Matched Key' => $regNo !== '' ? $regNo : $supplierNo
        ]);
    }
}

$reviewExport = buildImportReviewExportPayload('arrears_payments_review', $reviewRows, $reviewColumns);

echo json_encode([
    'success' => true,
    'message' => 'Bulk payment upload processed.',
    'stats' => $stats,
    'errors' => array_slice($errors, 0, 20),
    'review_export' => $reviewExport
]);

$conn->close();

function parseBulkArrearsPaymentRows(string $absolutePath, string $extension): array {
    if ($extension === 'csv') {
        $rows = parseBulkPaymentCsvRows($absolutePath);
    } else {
        $rows = parseBulkPaymentXlsxRows($absolutePath);
    }
    return normalizeBulkPaymentRows($rows);
}

function parseBulkPaymentCsvRows(string $absolutePath): array {
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

function parseBulkPaymentXlsxRows(string $absolutePath): array {
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
        $sharedStrings = parseBulkPaymentSharedStrings((string)$sharedXml);
    }

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
        throw new RuntimeException('No worksheet found in XLSX file');
    }

    return parseBulkPaymentWorksheet((string)$sheetXml, $sharedStrings);
}

function parseBulkPaymentSharedStrings(string $xmlText): array {
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        return [];
    }
    $strings = [];
    foreach ($xml->si as $si) {
        $value = '';
        if (isset($si->t)) {
            $value = (string)$si->t;
        } elseif (isset($si->r)) {
            foreach ($si->r as $run) {
                $value .= (string)($run->t ?? '');
            }
        }
        $strings[] = trim($value);
    }
    return $strings;
}

function parseBulkPaymentWorksheet(string $xmlText, array $sharedStrings): array {
    $xml = simplexml_load_string($xmlText);
    if ($xml === false || !isset($xml->sheetData)) {
        return [];
    }
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = strtoupper((string)($attrs['r'] ?? ''));
            $type = strtolower((string)($attrs['t'] ?? ''));
            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $matches)) {
                $colIndex = bulkPaymentColumnIndex($matches[1]);
            }
            $value = '';
            if ($type === 's') {
                $value = (string)($sharedStrings[(int)($cell->v ?? 0)] ?? '');
            } elseif ($type === 'inlinestr') {
                if (isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                } elseif (isset($cell->is->r)) {
                    foreach ($cell->is->r as $run) $value .= (string)($run->t ?? '');
                }
            } else {
                $value = trim((string)($cell->v ?? ''));
            }
            $rowData[$colIndex] = $value;
        }
        if (!empty($rowData)) {
            ksort($rowData);
            $rows[] = $rowData;
        }
    }
    return $rows;
}

function bulkPaymentColumnIndex(string $letters): int {
    $index = 0;
    $letters = strtoupper(trim($letters));
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function normalizeBulkPaymentRows(array $rows): array {
    if (empty($rows)) {
        return ['rows' => [], 'review_rows' => [], 'review_columns' => []];
    }

    $displayHeaders = array_map(static function ($value, int $index): string {
        $label = trim((string)$value);
        return $label !== '' ? $label : ('Column ' . ($index + 1));
    }, $rows[0], array_keys($rows[0]));

    $header = array_map(static function ($value) {
        return strtolower(preg_replace('/[^a-z0-9]/', '', (string)$value));
    }, $rows[0]);

    $idxSupplier = bulkPaymentHeaderIndex($header, ['suppliernumber', 'supplierno', 'supplier']);
    $idxClaimType = bulkPaymentHeaderIndex($header, ['claimtype', 'arrearstype', 'type']);
    $idxAmount = bulkPaymentHeaderIndex($header, ['amount', 'paymentamount', 'value']);
    $idxPaymentDate = bulkPaymentHeaderIndex($header, ['paymentdate', 'date']);
    $idxReference = bulkPaymentHeaderIndex($header, ['referencenumber', 'reference', 'refno']);
    $idxNotes = bulkPaymentHeaderIndex($header, ['notes', 'note', 'remarks', 'comment']);

    $hasHeader = $idxSupplier >= 0 || $idxClaimType >= 0 || $idxAmount >= 0;
    if ($idxSupplier < 0) $idxSupplier = 0;
    if ($idxClaimType < 0) $idxClaimType = 1;
    if ($idxAmount < 0) $idxAmount = 2;
    if ($idxPaymentDate < 0) $idxPaymentDate = 3;
    if ($idxReference < 0) $idxReference = 4;
    if ($idxNotes < 0) $idxNotes = 5;

    $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
    $normalized = [];
    $reviewRows = [];
    foreach ($dataRows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $rowNumber = $hasHeader ? ($offset + 2) : ($offset + 1);
        $supplierNo = trim((string)($row[$idxSupplier] ?? ''));
        $claimType = trim((string)($row[$idxClaimType] ?? ''));
        $amountRaw = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)($row[$idxAmount] ?? '0')));
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0;
        if ($supplierNo === '' && trim((string)($row[$idxAmount] ?? '')) === '' && trim((string)($row[$idxPaymentDate] ?? '')) === '') {
            continue;
        }
        if ($supplierNo === '' || $amount <= 0) {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Supplier Number and Amount are required.',
                'Review Fields' => ['supplierNo', 'amount']
            ]);
            continue;
        }
        $normalized[] = [
            'row_number' => $rowNumber,
            'supplierNo' => $supplierNo,
            'claimType' => $claimType,
            'amount' => $amount,
            'paymentDate' => trim((string)($row[$idxPaymentDate] ?? '')),
            'referenceNo' => trim((string)($row[$idxReference] ?? '')),
            'notes' => trim((string)($row[$idxNotes] ?? '')),
            'source_row' => $row
        ];
    }
    return [
        'rows' => $normalized,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

function bulkPaymentHeaderIndex(array $headers, array $aliases): int {
    foreach ($headers as $idx => $label) {
        if (in_array((string)$label, $aliases, true)) {
            return (int)$idx;
        }
    }
    return -1;
}

function normalizeBulkPaymentDate(string $rawDate, string $fallback): string {
    $value = trim($rawDate);
    if ($value === '') {
        return $fallback;
    }
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    return $fallback;
}
