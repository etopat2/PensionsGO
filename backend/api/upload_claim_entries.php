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

function parseClaimsPeriodMonth(string $raw): ?DateTime {
    $value = trim($raw);
    if ($value === '') {
        return null;
    }
    $formats = ['Y-m', 'Y/m', 'm/Y', 'm-Y', 'M Y', 'F Y', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }
    return null;
}

function claimsBuildPeriods(string $start, string $end): array {
    $from = parseClaimsPeriodMonth($start);
    $to = parseClaimsPeriodMonth($end);
    if (!$from || !$to) {
        return [];
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $periods = [];
    $cursor = clone $from;
    while ($cursor <= $to) {
        $periods[] = ['year' => (int)$cursor->format('Y'), 'month' => (int)$cursor->format('n')];
        $cursor->modify('+1 month');
    }
    return $periods;
}

function claimsParseCsvRows(string $path): array {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to read CSV file');
    }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($v) => trim((string)$v), $row);
    }
    fclose($handle);
    return $rows;
}

function claimsXlsxColIndex(string $columnLetters): int {
    $letters = strtoupper(trim($columnLetters));
    if ($letters === '') {
        return 0;
    }
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function claimsParseXlsxRows(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is not enabled');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open XLSX file');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string((string)$sharedXml);
        if ($shared !== false) {
            $ns = $shared->getNamespaces(true);
            $mainNs = $ns[''] ?? null;
            $root = $mainNs ? $shared->children($mainNs) : $shared;
            foreach ($root->si as $si) {
                $siNode = $mainNs ? $si->children($mainNs) : $si;
                $text = '';
                if (isset($siNode->t)) {
                    $text = (string)$siNode->t;
                } elseif (isset($siNode->r)) {
                    foreach ($siNode->r as $run) {
                        $runNode = $mainNs ? $run->children($mainNs) : $run;
                        $text .= (string)($runNode->t ?? '');
                    }
                }
                $sharedStrings[] = trim($text);
            }
        }
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
        throw new RuntimeException('Worksheet not found in XLSX file');
    }

    $xml = simplexml_load_string((string)$sheetXml);
    if ($xml === false) {
        throw new RuntimeException('Unable to parse worksheet XML');
    }
    $ns = $xml->getNamespaces(true);
    $mainNs = $ns[''] ?? null;
    $root = $mainNs ? $xml->children($mainNs) : $xml;
    if (!isset($root->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($root->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = strtoupper((string)($attrs['r'] ?? ''));
            $type = strtolower((string)($attrs['t'] ?? ''));
            $cellNode = $mainNs ? $cell->children($mainNs) : $cell;
            $col = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                $col = claimsXlsxColIndex($m[1]);
            }
            $value = '';
            if ($type === 's') {
                $idx = (int)($cellNode->v ?? 0);
                $value = (string)($sharedStrings[$idx] ?? '');
            } elseif ($type === 'inlineStr') {
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
            $line[$col] = trim($value);
        }
        if (!empty($line)) {
            ksort($line);
            $rows[] = $line;
        }
    }
    return $rows;
}

function normalizeClaimFileRows(array $rows): array {
    if (empty($rows)) {
        return ['rows' => [], 'review_rows' => [], 'review_columns' => []];
    }

    $displayHeaders = array_map(static function ($value, int $index): string {
        $label = trim((string)$value);
        return $label !== '' ? $label : ('Column ' . ($index + 1));
    }, $rows[0], array_keys($rows[0]));

    $header = array_map(static fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $getIdx = static function(array $aliases) use ($header): int {
        foreach ($header as $idx => $label) {
            $norm = preg_replace('/[^a-z0-9]/', '', (string)$label);
            if (in_array($norm, $aliases, true)) {
                return (int)$idx;
            }
        }
        return -1;
    };

    $idxReg = $getIdx(['regno', 'fileno', 'filenumber', 'file', 'registryno', 'registryno']);
    $idxClaim = $getIdx(['claimtype', 'type']);
    $idxYear = $getIdx(['periodyear', 'year']);
    $idxMonth = $getIdx(['periodmonth', 'month']);
    $idxAmount = $getIdx(['expectedamount', 'amount', 'monthlyamount']);
    $idxStart = $getIdx(['startperiod', 'fromperiod', 'startmonth', 'from']);
    $idxEnd = $getIdx(['endperiod', 'toperiod', 'endmonth', 'to']);
    $idxReason = $getIdx(['reason', 'remarks', 'remark', 'comment']);
    $idxNotes = $getIdx(['notes', 'note']);
    $idxSource = $getIdx(['sourcetype', 'source']);
    $idxClaimStatus = $getIdx(['claimstatus', 'claim_status', 'verificationstatus', 'verification_status', 'verification', 'verificationfinding', 'verification_finding']);

    $hasHeader = ($idxReg >= 0 || $idxClaim >= 0 || $idxAmount >= 0 || $idxStart >= 0 || $idxEnd >= 0);
    if ($idxReg < 0) $idxReg = 0;
    if ($idxClaim < 0) $idxClaim = 1;
    if ($idxYear < 0) $idxYear = 2;
    if ($idxMonth < 0) $idxMonth = 3;
    if ($idxAmount < 0) $idxAmount = 4;
    if ($idxStart < 0) $idxStart = 5;
    if ($idxEnd < 0) $idxEnd = 6;
    if ($idxReason < 0) $idxReason = 7;
    if ($idxNotes < 0) $idxNotes = 8;
    if ($idxSource < 0) $idxSource = 9;

    $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
    $normalized = [];
    $reviewRows = [];
    foreach ($dataRows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $rowNumber = $hasHeader ? ($offset + 2) : ($offset + 1);
        $regNo = trim((string)($row[$idxReg] ?? ''));
        if ($regNo === '') {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'File Number / Reg No is required.',
                'Review Fields' => ['regNo']
            ]);
            continue;
        }
        $amountRaw = trim((string)($row[$idxAmount] ?? '0'));
        $amountRaw = str_replace(',', '', $amountRaw);
        $amountRaw = preg_replace('/[^0-9.\-]/', '', $amountRaw);
        $normalized[] = [
            'row_number' => $rowNumber,
            'regNo' => $regNo,
            'claimType' => trim((string)($row[$idxClaim] ?? '')),
            'periodYear' => (int)($row[$idxYear] ?? 0),
            'periodMonth' => (int)($row[$idxMonth] ?? 0),
            'expectedAmount' => is_numeric($amountRaw) ? (float)$amountRaw : 0.0,
            'start' => trim((string)($row[$idxStart] ?? '')),
            'end' => trim((string)($row[$idxEnd] ?? '')),
            'reason' => trim((string)($row[$idxReason] ?? '')),
            'notes' => trim((string)($row[$idxNotes] ?? '')),
            'sourceType' => trim((string)($row[$idxSource] ?? '')),
            'claimStatus' => $idxClaimStatus >= 0 ? trim((string)($row[$idxClaimStatus] ?? '')) : ''
        ];
    }

    return [
        'rows' => $normalized,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

try {
    if (!isset($_FILES['claims_file']) || (int)($_FILES['claims_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Select a claims file to upload']);
        exit;
    }

    $upload = $_FILES['claims_file'];
    enforceUploadedFileSizeLimit($conn, $upload, 'Claims upload file');
    $originalName = (string)($upload['name'] ?? 'claims_file');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx', 'xlxl'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only .csv, .xlsx or .xlxl files are supported']);
        exit;
    }

    $tempPath = (string)($upload['tmp_name'] ?? '');
    if ($tempPath === '' || !is_uploaded_file($tempPath)) {
        echo json_encode(['success' => false, 'message' => 'Invalid upload request']);
        exit;
    }

    if (in_array($extension, ['xlsx', 'xlxl'], true)) {
        enforceZipArchiveSafety($conn, $tempPath, 'Claims workbook');
    }

    $rows = $extension === 'csv'
        ? claimsParseCsvRows($tempPath)
        : claimsParseXlsxRows($tempPath);
    enforceParsedRowLimit($conn, max(0, count($rows) - 1), 'Claims upload');
    $parsed = normalizeClaimFileRows($rows);
    $records = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
    if (empty($records)) {
        $reviewExport = buildImportReviewExportPayload('claims_upload', $reviewRows, $reviewColumns);
        echo json_encode([
            'success' => !empty($reviewRows),
            'message' => !empty($reviewRows)
                ? 'No claim rows were saved. Review and correct the downloaded file, then upload again.'
                : 'No valid records found in uploaded file',
            'savedRows' => 0,
            'skippedRows' => count($reviewRows),
            'review_export' => $reviewExport
        ]);
        exit;
    }

    $defaultClaimType = trim((string)($_POST['claim_type'] ?? 'Pension Arrears'));
    $defaultClaimStatus = trim((string)($_POST['claim_status'] ?? 'Incomplete'));
    $defaultSourceType = trim((string)($_POST['source_type'] ?? 'uploaded_claims'));
    $defaultReason = trim((string)($_POST['reason'] ?? 'Bulk claims upload'));
    $defaultNotes = trim((string)($_POST['notes'] ?? 'Imported from claims upload file'));

    $saved = 0;
    $skipped = 0;
    foreach ($records as $record) {
        $rowNumber = (int)($record['row_number'] ?? 0);
        $claimType = $record['claimType'] !== '' ? $record['claimType'] : $defaultClaimType;
        $sourceType = $record['sourceType'] !== '' ? $record['sourceType'] : $defaultSourceType;
        $sourceType = normalizeArrearsSourceType($sourceType);
        $reason = $record['reason'] !== '' ? $record['reason'] : $defaultReason;
        $notes = $record['notes'] !== '' ? $record['notes'] : $defaultNotes;
        $claimStatusRaw = $record['claimStatus'] !== '' ? $record['claimStatus'] : $defaultClaimStatus;
        $claimStatus = normalizeClaimVerificationStatus($claimStatusRaw);

        $start = (string)($record['start'] ?? '');
        $end = (string)($record['end'] ?? '');
        $monthlyAmount = round(max((float)($record['expectedAmount'] ?? 0), 0), 2);
        if ($start !== '' && $end !== '' && $monthlyAmount > 0) {
            $periods = claimsBuildPeriods($start, $end);
            if (empty($periods)) {
                $skipped++;
                $reviewRows[] = [
                    'Source Row' => (string)$rowNumber,
                    'Review Status' => 'Invalid',
                    'Review Reason' => 'Start Period and End Period could not be expanded into valid monthly periods.',
                    'Review Fields' => 'start, end',
                    'Matched Key' => (string)($record['regNo'] ?? ''),
                    'regNo' => (string)($record['regNo'] ?? ''),
                    'claimType' => $claimType,
                    'start' => $start,
                    'end' => $end,
                    'expectedAmount' => (string)$monthlyAmount,
                    'reason' => $reason,
                    'notes' => $notes
                ];
                continue;
            }
            foreach ($periods as $period) {
                $upsert = upsertArrearsLedgerEntry($conn, [
                    'regNo' => (string)$record['regNo'],
                    'claim_type' => $claimType,
                    'claim_status' => $claimStatus,
                    'period_year' => (int)($period['year'] ?? 0),
                    'period_month' => (int)($period['month'] ?? 0),
                    'expected_amount' => $monthlyAmount,
                    'source_type' => $sourceType,
                    'reason' => $reason,
                    'notes' => $notes,
                    'recorded_by' => (string)($_SESSION['userId'] ?? '')
                ]);
                if ($upsert) {
                    $saved++;
                }
            }
            continue;
        }

        $year = (int)($record['periodYear'] ?? 0);
        $month = (int)($record['periodMonth'] ?? 0);
        $amount = round(max((float)($record['expectedAmount'] ?? 0), 0), 2);
        if ($year <= 0 || $month < 1 || $month > 12 || $amount <= 0) {
            $skipped++;
            $reviewRows[] = [
                'Source Row' => (string)$rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Period Year, Period Month, and Expected Amount are required for single-period rows.',
                'Review Fields' => 'periodYear, periodMonth, expectedAmount',
                'Matched Key' => (string)($record['regNo'] ?? ''),
                'regNo' => (string)($record['regNo'] ?? ''),
                'claimType' => $claimType,
                'periodYear' => (string)$year,
                'periodMonth' => (string)$month,
                'expectedAmount' => (string)$amount,
                'reason' => $reason,
                'notes' => $notes
            ];
            continue;
        }
        $upsert = upsertArrearsLedgerEntry($conn, [
            'regNo' => (string)$record['regNo'],
            'claim_type' => $claimType,
            'claim_status' => $claimStatus,
            'period_year' => $year,
            'period_month' => $month,
            'expected_amount' => $amount,
            'source_type' => $sourceType,
            'reason' => $reason,
            'notes' => $notes,
            'recorded_by' => (string)($_SESSION['userId'] ?? '')
        ]);
        if ($upsert) {
            $saved++;
        } else {
            $skipped++;
            $reviewRows[] = [
                'Source Row' => (string)$rowNumber,
                'Review Status' => 'Failed',
                'Review Reason' => 'The claim row could not be saved to the arrears ledger.',
                'Review Fields' => '',
                'Matched Key' => (string)($record['regNo'] ?? ''),
                'regNo' => (string)($record['regNo'] ?? ''),
                'claimType' => $claimType,
                'periodYear' => (string)$year,
                'periodMonth' => (string)$month,
                'expectedAmount' => (string)$amount,
                'reason' => $reason,
                'notes' => $notes
            ];
        }
    }

    $reviewExport = buildImportReviewExportPayload('claims_upload', $reviewRows);

    echo json_encode([
        'success' => true,
        'message' => $saved > 0
            ? ("Claims upload completed. Saved {$saved} row(s)." . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : ''))
            : 'No claim rows were saved. Review the downloaded file, correct the highlighted rows, then upload again.',
        'savedRows' => $saved,
        'skippedRows' => $skipped,
        'review_export' => $reviewExport
    ]);
} catch (Throwable $e) {
    error_log('upload_claim_entries error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process claims upload']);
}

$conn->close();
?>
