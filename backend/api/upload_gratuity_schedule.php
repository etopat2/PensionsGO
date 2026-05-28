<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/gratuity_schedule_common.php';

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

ensureGratuityScheduleTables($conn);

$scheduleYear = (int)($_POST['schedule_year'] ?? date('Y'));
$scheduleMonth = (int)($_POST['schedule_month'] ?? date('n'));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($scheduleYear < 2000 || $scheduleYear > 2200) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule year']);
    exit;
}
if ($scheduleMonth < 1 || $scheduleMonth > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid schedule month']);
    exit;
}

if (!isset($_FILES['schedule_file']) || (int)($_FILES['schedule_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Gratuity schedule file is required']);
    exit;
}

$upload = $_FILES['schedule_file'];
enforceUploadedFileSizeLimit($conn, $upload, 'Monthly gratuity schedule');
$originalName = (string)($upload['name'] ?? 'gratuity_schedule');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$normalizedExt = $ext === 'xlxl' ? 'xlsx' : $ext;
$mime = (string)($upload['type'] ?? '');

if (!in_array($normalizedExt, ['csv', 'xlsx'], true)) {
    echo json_encode(['success' => false, 'message' => 'Schedule file must be .csv or .xlsx']);
    exit;
}

$tmpPath = (string)($upload['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    echo json_encode(['success' => false, 'message' => 'Invalid uploaded schedule file']);
    exit;
}

if ($normalizedExt === 'xlsx') {
    enforceZipArchiveSafety($conn, $tmpPath, 'Monthly gratuity workbook');
}

$uploadDir = __DIR__ . '/../uploads/gratuity_schedules/cycles';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    echo json_encode(['success' => false, 'message' => 'Unable to create gratuity schedule upload directory']);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$stamp = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$storedName = $safeBase . '_' . $stamp . '.' . $normalizedExt;
$absolutePath = $uploadDir . '/' . $storedName;
$relativePath = 'uploads/gratuity_schedules/cycles/' . $storedName;

if (!move_uploaded_file($tmpPath, $absolutePath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save the uploaded schedule file']);
    exit;
}

try {
    $parsed = parseGratuityScheduleUploadRows($absolutePath, $normalizedExt);
    $rows = $parsed['rows'] ?? [];
    $reviewRows = $parsed['review_rows'] ?? [];
    $reviewColumns = $parsed['review_columns'] ?? [];
    enforceParsedRowLimit($conn, count($rows) + count($reviewRows), 'Monthly gratuity schedule');
    if (empty($rows)) {
        @unlink($absolutePath);
        $reviewExport = buildImportReviewExportPayload('gratuity_schedule_review', $reviewRows, $reviewColumns);
        echo json_encode([
            'success' => !empty($reviewRows),
            'message' => !empty($reviewRows)
                ? 'No schedule rows were imported. Review and correct the downloaded file, then upload again.'
                : 'No valid gratuity schedule rows were found in the uploaded file',
            'stats' => [
                'rowsUploaded' => 0,
                'matchedRows' => 0,
                'unmatchedRows' => 0,
                'exactGratuityRows' => 0,
                'partialGratuityRows' => 0,
                'smallSurplusRows' => 0,
                'pensionArrearsRows' => 0,
                'reviewRows' => count($reviewRows),
                'totalScheduledAmount' => 0,
                'totalGratuityComponent' => 0,
                'totalSmallSurplusAmount' => 0,
                'totalPensionSurplusAmount' => 0,
                'totalAllocatedPensionAmount' => 0,
                'totalUnallocatedAmount' => 0,
                'totalRemainingArrearsAmount' => 0
            ],
            'review_export' => $reviewExport
        ]);
        exit;
    }
} catch (Throwable $parseError) {
    @unlink($absolutePath);
    error_log('upload_gratuity_schedule parse error: ' . $parseError->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to read the uploaded gratuity schedule']);
    exit;
}

$financialYear = getFinancialYearLabelForMonth($scheduleYear, $scheduleMonth);
$quarter = getQuarterLabelForMonth($scheduleMonth);
$uploadedBy = (string)($_SESSION['userId'] ?? '');
$registryIndex = buildGratuityScheduleRegistryIndex($conn);
$monthlyPensionCache = [];
$arrearsCache = [];

$stats = [
    'rowsUploaded' => count($rows),
    'matchedRows' => 0,
    'unmatchedRows' => 0,
    'exactGratuityRows' => 0,
    'partialGratuityRows' => 0,
    'smallSurplusRows' => 0,
    'pensionArrearsRows' => 0,
    'reviewRows' => 0,
    'totalScheduledAmount' => 0.0,
    'totalGratuityComponent' => 0.0,
    'totalSmallSurplusAmount' => 0.0,
    'totalPensionSurplusAmount' => 0.0,
    'totalAllocatedPensionAmount' => 0.0,
    'totalUnallocatedAmount' => 0.0,
    'totalRemainingArrearsAmount' => 0.0
];
$analysisReviewRows = [];

$conn->begin_transaction();
try {
    $cycleStmt = $conn->prepare("
        INSERT INTO tb_gratuity_schedule_cycles
        (
            schedule_year,
            schedule_month,
            financial_year_label,
            quarter_label,
            uploaded_by,
            source_file,
            source_file_original_name,
            source_file_mime,
            notes
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$cycleStmt) {
        throw new RuntimeException('Unable to create gratuity schedule cycle');
    }
    $cycleStmt->bind_param(
        "iisssssss",
        $scheduleYear,
        $scheduleMonth,
        $financialYear,
        $quarter,
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
        INSERT INTO tb_gratuity_schedule_entries
        (
            cycle_id,
            row_number,
            regNo,
            supplierNo,
            beneficiary_name,
            scheduled_amount,
            matched_regNo,
            matched_registry_id,
            matched_name,
            registry_gratuity_estimate,
            latest_monthly_pension,
            monthly_pension_source,
            open_pension_arrears_amount,
            open_pension_arrears_months,
            gratuity_component_amount,
            pension_surplus_amount,
            small_surplus_amount,
            allocated_pension_amount,
            scheduled_full_months,
            allocated_months,
            unallocated_scheduled_months,
            unallocated_scheduled_amount,
            remaining_arrears_months,
            remaining_arrears_amount,
            classification,
            matching_basis,
            analysis_note,
            raw_payload,
            is_matched
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$entryStmt) {
        throw new RuntimeException('Unable to create gratuity schedule entries');
    }

    $allocationStmt = $conn->prepare("
        INSERT INTO tb_gratuity_schedule_allocations
        (
            cycle_id,
            entry_id,
            matched_regNo,
            ledger_id,
            period_year,
            period_month,
            claim_type,
            allocated_amount,
            monthly_pension_amount,
            allocation_status,
            note
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to create gratuity schedule allocations');
    }

    foreach ($rows as $row) {
        $stats['totalScheduledAmount'] += round(max((float)($row['scheduled_amount'] ?? 0), 0), 2);

        $match = matchGratuityScheduleRowToRegistry(
            $registryIndex,
            (string)($row['regNo'] ?? ''),
            (string)($row['supplierNo'] ?? ''),
            (string)($row['beneficiary_name'] ?? '')
        );

        $monthlyPension = ['amount' => 0.0, 'source' => '', 'periodLabel' => ''];
        $arrearsSnapshot = ['rows' => [], 'months' => 0, 'amount' => 0.0];
        if (!empty($match['matched']) && !empty($match['record'])) {
            $stats['matchedRows']++;
            $monthlyPension = getGratuityScheduleMonthlyPension($conn, (array)$match['record'], $monthlyPensionCache);
            $arrearsSnapshot = getOpenPensionArrearsSnapshot($conn, (string)($match['record']['regNo'] ?? ''), $arrearsCache);
        } else {
            $stats['unmatchedRows']++;
        }

        $analysis = analyzeGratuityScheduleEntry($row, $match, $monthlyPension, $arrearsSnapshot);
        $bucket = classifyGratuityScheduleRowBucket((string)($analysis['classification'] ?? 'review'));
        if ($bucket === 'exact_gratuity') {
            $stats['exactGratuityRows']++;
        } elseif ($bucket === 'partial_gratuity') {
            $stats['partialGratuityRows']++;
        } elseif ($bucket === 'small_surplus') {
            $stats['smallSurplusRows']++;
        } elseif ($bucket === 'pension_arrears') {
            $stats['pensionArrearsRows']++;
        } else {
            $stats['reviewRows']++;
        }

        $stats['totalGratuityComponent'] += (float)($analysis['gratuityComponentAmount'] ?? 0);
        $stats['totalSmallSurplusAmount'] += (float)($analysis['smallSurplusAmount'] ?? 0);
        $stats['totalPensionSurplusAmount'] += (float)($analysis['pensionSurplusAmount'] ?? 0);
        $stats['totalAllocatedPensionAmount'] += (float)($analysis['allocatedPensionAmount'] ?? 0);
        $stats['totalUnallocatedAmount'] += (float)($analysis['unallocatedScheduledAmount'] ?? 0) + (float)($analysis['smallSurplusAmount'] ?? 0);
        $stats['totalRemainingArrearsAmount'] += (float)($analysis['remainingArrearsAmount'] ?? 0);

        $rowNumber = (int)($row['row_number'] ?? 0);
        $regNo = trim((string)($row['regNo'] ?? ''));
        $supplierNo = trim((string)($row['supplierNo'] ?? ''));
        $beneficiaryName = trim((string)($row['beneficiary_name'] ?? ''));
        $scheduledAmount = round(max((float)($row['scheduled_amount'] ?? 0), 0), 2);
        $matchedRegNo = (string)($analysis['matchedRegNo'] ?? '');
        $matchedRegistryId = (int)($analysis['matchedRegistryId'] ?? 0);
        $matchedName = (string)($analysis['matchedName'] ?? '');
        $registryGratuityEstimate = round((float)($analysis['registryGratuityEstimate'] ?? 0), 2);
        $latestMonthlyPension = round((float)($analysis['latestMonthlyPension'] ?? 0), 2);
        $monthlyPensionSource = (string)($analysis['monthlyPensionSource'] ?? '');
        $openPensionArrearsAmount = round((float)($analysis['openPensionArrearsAmount'] ?? 0), 2);
        $openPensionArrearsMonths = (int)($analysis['openPensionArrearsMonths'] ?? 0);
        $gratuityComponentAmount = round((float)($analysis['gratuityComponentAmount'] ?? 0), 2);
        $pensionSurplusAmount = round((float)($analysis['pensionSurplusAmount'] ?? 0), 2);
        $smallSurplusAmount = round((float)($analysis['smallSurplusAmount'] ?? 0), 2);
        $allocatedPensionAmount = round((float)($analysis['allocatedPensionAmount'] ?? 0), 2);
        $scheduledFullMonths = (int)($analysis['scheduledFullMonths'] ?? 0);
        $allocatedMonths = (int)($analysis['allocatedMonths'] ?? 0);
        $unallocatedScheduledMonths = (int)($analysis['unallocatedScheduledMonths'] ?? 0);
        $unallocatedScheduledAmount = round((float)($analysis['unallocatedScheduledAmount'] ?? 0), 2);
        $remainingArrearsMonths = (int)($analysis['remainingArrearsMonths'] ?? 0);
        $remainingArrearsAmount = round((float)($analysis['remainingArrearsAmount'] ?? 0), 2);
        $classification = (string)($analysis['classification'] ?? 'review');
        $matchingBasis = (string)($analysis['matchingBasis'] ?? 'none');
        $analysisNote = (string)($analysis['analysisNote'] ?? '');
        $rawPayload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $isMatched = !empty($analysis['matched']) ? 1 : 0;

        if (
            !$isMatched
            || $bucket === 'review'
            || (float)($analysis['unallocatedScheduledAmount'] ?? 0) > 0
            || (float)($analysis['smallSurplusAmount'] ?? 0) > 0
        ) {
            $reviewFields = [];
            if (!$isMatched) {
                $reviewFields[] = 'regNo';
                $reviewFields[] = 'supplierNo';
                $reviewFields[] = 'beneficiary_name';
            }
            if ($bucket === 'review') {
                $reviewFields[] = 'scheduled_amount';
            }
            if ((float)($analysis['smallSurplusAmount'] ?? 0) > 0) {
                $reviewFields[] = 'scheduled_amount';
            }
            if ((float)($analysis['unallocatedScheduledAmount'] ?? 0) > 0) {
                $reviewFields[] = 'scheduled_amount';
            }
            $analysisReviewRows[] = buildImportReviewRowFromSource($reviewColumns ? array_slice($reviewColumns, 5) : [], (array)($row['source_row'] ?? []), [
                'Source Row' => $rowNumber,
                'Review Status' => !$isMatched ? 'Unmatched' : 'Review',
                'Review Reason' => $analysisNote !== '' ? $analysisNote : 'This schedule row needs operator review before it can be fully reconciled.',
                'Review Fields' => array_values(array_unique(array_filter($reviewFields))),
                'Matched Key' => $matchedRegNo !== '' ? $matchedRegNo : ($regNo !== '' ? $regNo : $supplierNo)
            ]);
        }

        $entryStmt->bind_param(
            "iisssdsisddsdiddddiiididssssi",
            $cycleId,
            $rowNumber,
            $regNo,
            $supplierNo,
            $beneficiaryName,
            $scheduledAmount,
            $matchedRegNo,
            $matchedRegistryId,
            $matchedName,
            $registryGratuityEstimate,
            $latestMonthlyPension,
            $monthlyPensionSource,
            $openPensionArrearsAmount,
            $openPensionArrearsMonths,
            $gratuityComponentAmount,
            $pensionSurplusAmount,
            $smallSurplusAmount,
            $allocatedPensionAmount,
            $scheduledFullMonths,
            $allocatedMonths,
            $unallocatedScheduledMonths,
            $unallocatedScheduledAmount,
            $remainingArrearsMonths,
            $remainingArrearsAmount,
            $classification,
            $matchingBasis,
            $analysisNote,
            $rawPayload,
            $isMatched
        );
        $entryStmt->execute();
        $entryId = (int)$entryStmt->insert_id;

        foreach ((array)($analysis['allocations'] ?? []) as $allocation) {
            $allocationMatchedRegNo = $matchedRegNo;
            $ledgerId = (int)($allocation['ledgerId'] ?? 0);
            $periodYear = (int)($allocation['periodYear'] ?? 0);
            $periodMonth = (int)($allocation['periodMonth'] ?? 0);
            $claimType = (string)($allocation['claimType'] ?? 'Pension Arrears');
            $allocatedAmount = round((float)($allocation['allocatedAmount'] ?? 0), 2);
            $monthlyPensionAmount = round((float)($allocation['monthlyPensionAmount'] ?? 0), 2);
            $allocationStatus = (string)($allocation['allocationStatus'] ?? 'scheduled');
            $allocationNote = (string)($allocation['note'] ?? '');

            $allocationStmt->bind_param(
                "iisiiisddss",
                $cycleId,
                $entryId,
                $allocationMatchedRegNo,
                $ledgerId,
                $periodYear,
                $periodMonth,
                $claimType,
                $allocatedAmount,
                $monthlyPensionAmount,
                $allocationStatus,
                $allocationNote
            );
            $allocationStmt->execute();
        }
    }

    $entryStmt->close();
    $allocationStmt->close();

    $reviewRows = array_merge($reviewRows, $analysisReviewRows);
    $stats['reviewRows'] = count($reviewRows);
    $reviewExport = buildImportReviewExportPayload(
        'gratuity_schedule_review_' . $scheduleYear . '_' . str_pad((string)$scheduleMonth, 2, '0', STR_PAD_LEFT),
        $reviewRows,
        $reviewColumns
    );

    foreach ($stats as $key => $value) {
        if (is_float($value)) {
            $stats[$key] = round($value, 2);
        }
    }

    $updateCycleStmt = $conn->prepare("
        UPDATE tb_gratuity_schedule_cycles
        SET total_rows = ?,
            matched_rows = ?,
            unmatched_rows = ?,
            exact_gratuity_rows = ?,
            partial_gratuity_rows = ?,
            small_surplus_rows = ?,
            pension_arrears_rows = ?,
            review_rows = ?,
            total_scheduled_amount = ?,
            total_gratuity_component = ?,
            total_small_surplus_amount = ?,
            total_pension_surplus_amount = ?,
            total_allocated_pension_amount = ?,
            total_unallocated_amount = ?,
            total_remaining_arrears_amount = ?
        WHERE cycle_id = ?
        LIMIT 1
    ");
    if (!$updateCycleStmt) {
        throw new RuntimeException('Unable to finalize gratuity schedule cycle');
    }
    $updateCycleStmt->bind_param(
        "iiiiiiiidddddddi",
        $stats['rowsUploaded'],
        $stats['matchedRows'],
        $stats['unmatchedRows'],
        $stats['exactGratuityRows'],
        $stats['partialGratuityRows'],
        $stats['smallSurplusRows'],
        $stats['pensionArrearsRows'],
        $stats['reviewRows'],
        $stats['totalScheduledAmount'],
        $stats['totalGratuityComponent'],
        $stats['totalSmallSurplusAmount'],
        $stats['totalPensionSurplusAmount'],
        $stats['totalAllocatedPensionAmount'],
        $stats['totalUnallocatedAmount'],
        $stats['totalRemainingArrearsAmount'],
        $cycleId
    );
    $updateCycleStmt->execute();
    $updateCycleStmt->close();

    $conn->commit();

    if (function_exists('logPayrollAudit')) {
        logPayrollAudit($conn, [
            'cycle_id' => $cycleId,
            'action' => 'upload_gratuity_schedule_cycle',
            'actor_user_id' => $_SESSION['userId'] ?? '',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'details' => [
                'year' => $scheduleYear,
                'month' => $scheduleMonth,
                'financial_year' => $financialYear,
                'quarter' => $quarter,
                'source_file' => $relativePath,
                'rows_uploaded' => $stats['rowsUploaded'],
                'matched_rows' => $stats['matchedRows'],
                'unmatched_rows' => $stats['unmatchedRows'],
                'exact_gratuity_rows' => $stats['exactGratuityRows'],
                'partial_gratuity_rows' => $stats['partialGratuityRows'],
                'pension_arrears_rows' => $stats['pensionArrearsRows'],
                'review_rows' => $stats['reviewRows']
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Monthly gratuity schedule uploaded and analysed successfully.',
        'cycle' => [
            'cycleId' => $cycleId,
            'year' => $scheduleYear,
            'month' => $scheduleMonth,
            'financialYear' => $financialYear,
            'quarter' => $quarter,
            'sourceFile' => $relativePath
        ],
        'stats' => $stats,
        'review_export' => $reviewExport
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($absolutePath);
    error_log('upload_gratuity_schedule error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process the monthly gratuity schedule']);
}

$conn->close();
