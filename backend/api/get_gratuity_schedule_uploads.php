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

if (!currentUserHasPermission($conn, 'claims.arrears.view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureGratuityScheduleTables($conn);

try {
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    $limit = max(5, min(24, (int)($_GET['limit'] ?? 10)));

    $cycles = [];
    $cycleStmt = $conn->prepare("
        SELECT
            cycle_id,
            schedule_year,
            schedule_month,
            financial_year_label,
            quarter_label,
            uploaded_by,
            source_file,
            source_file_original_name,
            created_at,
            total_rows,
            matched_rows,
            unmatched_rows,
            exact_gratuity_rows,
            partial_gratuity_rows,
            small_surplus_rows,
            pension_arrears_rows,
            review_rows,
            total_scheduled_amount,
            total_gratuity_component,
            total_small_surplus_amount,
            total_pension_surplus_amount,
            total_allocated_pension_amount,
            total_unallocated_amount,
            total_remaining_arrears_amount
        FROM tb_gratuity_schedule_cycles
        WHERE COALESCE(is_deleted, 0) = 0
        ORDER BY schedule_year DESC, schedule_month DESC, cycle_id DESC
        LIMIT ?
    ");
    if (!$cycleStmt) {
        throw new RuntimeException('Unable to prepare gratuity schedule cycle query');
    }
    $cycleStmt->bind_param("i", $limit);
    $cycleStmt->execute();
    $cycleRes = $cycleStmt->get_result();
    while ($row = $cycleRes->fetch_assoc()) {
        $cycles[] = [
            'cycleId' => (int)($row['cycle_id'] ?? 0),
            'year' => (int)($row['schedule_year'] ?? 0),
            'month' => (int)($row['schedule_month'] ?? 0),
            'financialYear' => (string)($row['financial_year_label'] ?? ''),
            'quarter' => (string)($row['quarter_label'] ?? ''),
            'uploadedBy' => (string)($row['uploaded_by'] ?? ''),
            'sourceFile' => (string)($row['source_file'] ?? ''),
            'sourceFileName' => (string)($row['source_file_original_name'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'totalRows' => (int)($row['total_rows'] ?? 0),
            'matchedRows' => (int)($row['matched_rows'] ?? 0),
            'unmatchedRows' => (int)($row['unmatched_rows'] ?? 0),
            'exactGratuityRows' => (int)($row['exact_gratuity_rows'] ?? 0),
            'partialGratuityRows' => (int)($row['partial_gratuity_rows'] ?? 0),
            'smallSurplusRows' => (int)($row['small_surplus_rows'] ?? 0),
            'pensionArrearsRows' => (int)($row['pension_arrears_rows'] ?? 0),
            'reviewRows' => (int)($row['review_rows'] ?? 0),
            'totalScheduledAmount' => (float)($row['total_scheduled_amount'] ?? 0),
            'totalGratuityComponent' => (float)($row['total_gratuity_component'] ?? 0),
            'totalSmallSurplusAmount' => (float)($row['total_small_surplus_amount'] ?? 0),
            'totalPensionSurplusAmount' => (float)($row['total_pension_surplus_amount'] ?? 0),
            'totalAllocatedPensionAmount' => (float)($row['total_allocated_pension_amount'] ?? 0),
            'totalUnallocatedAmount' => (float)($row['total_unallocated_amount'] ?? 0),
            'totalRemainingArrearsAmount' => (float)($row['total_remaining_arrears_amount'] ?? 0)
        ];
    }
    $cycleStmt->close();

    $cycleDetail = null;
    $entries = [];
    $allocations = [];

    if ($cycleId > 0) {
        $detailStmt = $conn->prepare("
            SELECT
                cycle_id,
                schedule_year,
                schedule_month,
                financial_year_label,
                quarter_label,
                uploaded_by,
                source_file,
                source_file_original_name,
                notes,
                created_at,
                total_rows,
                matched_rows,
                unmatched_rows,
                exact_gratuity_rows,
                partial_gratuity_rows,
                small_surplus_rows,
                pension_arrears_rows,
                review_rows,
                total_scheduled_amount,
                total_gratuity_component,
                total_small_surplus_amount,
                total_pension_surplus_amount,
                total_allocated_pension_amount,
                total_unallocated_amount,
                total_remaining_arrears_amount
            FROM tb_gratuity_schedule_cycles
            WHERE cycle_id = ?
            LIMIT 1
        ");
        if ($detailStmt) {
            $detailStmt->bind_param("i", $cycleId);
            $detailStmt->execute();
            $row = $detailStmt->get_result()->fetch_assoc() ?: null;
            $detailStmt->close();
            if ($row) {
                $cycleDetail = [
                    'cycleId' => (int)($row['cycle_id'] ?? 0),
                    'year' => (int)($row['schedule_year'] ?? 0),
                    'month' => (int)($row['schedule_month'] ?? 0),
                    'financialYear' => (string)($row['financial_year_label'] ?? ''),
                    'quarter' => (string)($row['quarter_label'] ?? ''),
                    'uploadedBy' => (string)($row['uploaded_by'] ?? ''),
                    'sourceFile' => (string)($row['source_file'] ?? ''),
                    'sourceFileName' => (string)($row['source_file_original_name'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                    'createdAt' => (string)($row['created_at'] ?? ''),
                    'totalRows' => (int)($row['total_rows'] ?? 0),
                    'matchedRows' => (int)($row['matched_rows'] ?? 0),
                    'unmatchedRows' => (int)($row['unmatched_rows'] ?? 0),
                    'exactGratuityRows' => (int)($row['exact_gratuity_rows'] ?? 0),
                    'partialGratuityRows' => (int)($row['partial_gratuity_rows'] ?? 0),
                    'smallSurplusRows' => (int)($row['small_surplus_rows'] ?? 0),
                    'pensionArrearsRows' => (int)($row['pension_arrears_rows'] ?? 0),
                    'reviewRows' => (int)($row['review_rows'] ?? 0),
                    'totalScheduledAmount' => (float)($row['total_scheduled_amount'] ?? 0),
                    'totalGratuityComponent' => (float)($row['total_gratuity_component'] ?? 0),
                    'totalSmallSurplusAmount' => (float)($row['total_small_surplus_amount'] ?? 0),
                    'totalPensionSurplusAmount' => (float)($row['total_pension_surplus_amount'] ?? 0),
                    'totalAllocatedPensionAmount' => (float)($row['total_allocated_pension_amount'] ?? 0),
                    'totalUnallocatedAmount' => (float)($row['total_unallocated_amount'] ?? 0),
                    'totalRemainingArrearsAmount' => (float)($row['total_remaining_arrears_amount'] ?? 0)
                ];
            }
        }

        $entryStmt = $conn->prepare("
            SELECT
                entry_id,
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
                is_matched,
                created_at
            FROM tb_gratuity_schedule_entries
            WHERE cycle_id = ?
            ORDER BY row_number ASC, entry_id ASC
            LIMIT 1000
        ");
        if ($entryStmt) {
            $entryStmt->bind_param("i", $cycleId);
            $entryStmt->execute();
            $entryRes = $entryStmt->get_result();
            while ($row = $entryRes->fetch_assoc()) {
                $entries[] = [
                    'entryId' => (int)($row['entry_id'] ?? 0),
                    'rowNumber' => (int)($row['row_number'] ?? 0),
                    'regNo' => (string)($row['regNo'] ?? ''),
                    'supplierNo' => (string)($row['supplierNo'] ?? ''),
                    'beneficiaryName' => (string)($row['beneficiary_name'] ?? ''),
                    'scheduledAmount' => (float)($row['scheduled_amount'] ?? 0),
                    'matchedRegNo' => (string)($row['matched_regNo'] ?? ''),
                    'matchedRegistryId' => (int)($row['matched_registry_id'] ?? 0),
                    'matchedName' => (string)($row['matched_name'] ?? ''),
                    'registryGratuityEstimate' => (float)($row['registry_gratuity_estimate'] ?? 0),
                    'latestMonthlyPension' => (float)($row['latest_monthly_pension'] ?? 0),
                    'monthlyPensionSource' => (string)($row['monthly_pension_source'] ?? ''),
                    'openPensionArrearsAmount' => (float)($row['open_pension_arrears_amount'] ?? 0),
                    'openPensionArrearsMonths' => (int)($row['open_pension_arrears_months'] ?? 0),
                    'gratuityComponentAmount' => (float)($row['gratuity_component_amount'] ?? 0),
                    'pensionSurplusAmount' => (float)($row['pension_surplus_amount'] ?? 0),
                    'smallSurplusAmount' => (float)($row['small_surplus_amount'] ?? 0),
                    'allocatedPensionAmount' => (float)($row['allocated_pension_amount'] ?? 0),
                    'scheduledFullMonths' => (int)($row['scheduled_full_months'] ?? 0),
                    'allocatedMonths' => (int)($row['allocated_months'] ?? 0),
                    'unallocatedScheduledMonths' => (int)($row['unallocated_scheduled_months'] ?? 0),
                    'unallocatedScheduledAmount' => (float)($row['unallocated_scheduled_amount'] ?? 0),
                    'remainingArrearsMonths' => (int)($row['remaining_arrears_months'] ?? 0),
                    'remainingArrearsAmount' => (float)($row['remaining_arrears_amount'] ?? 0),
                    'classification' => (string)($row['classification'] ?? ''),
                    'matchingBasis' => (string)($row['matching_basis'] ?? ''),
                    'analysisNote' => (string)($row['analysis_note'] ?? ''),
                    'isMatched' => ((int)($row['is_matched'] ?? 0)) === 1,
                    'createdAt' => (string)($row['created_at'] ?? '')
                ];
            }
            $entryStmt->close();
        }

        $allocationStmt = $conn->prepare("
            SELECT
                a.allocation_id,
                a.entry_id,
                a.matched_regNo,
                a.ledger_id,
                a.period_year,
                a.period_month,
                a.claim_type,
                a.allocated_amount,
                a.monthly_pension_amount,
                a.allocation_status,
                a.note,
                e.beneficiary_name,
                e.matched_name
            FROM tb_gratuity_schedule_allocations a
            LEFT JOIN tb_gratuity_schedule_entries e ON e.entry_id = a.entry_id
            WHERE a.cycle_id = ?
            ORDER BY a.period_year ASC, a.period_month ASC, a.allocation_id ASC
            LIMIT 2000
        ");
        if ($allocationStmt) {
            $allocationStmt->bind_param("i", $cycleId);
            $allocationStmt->execute();
            $allocationRes = $allocationStmt->get_result();
            while ($row = $allocationRes->fetch_assoc()) {
                $allocations[] = [
                    'allocationId' => (int)($row['allocation_id'] ?? 0),
                    'entryId' => (int)($row['entry_id'] ?? 0),
                    'matchedRegNo' => (string)($row['matched_regNo'] ?? ''),
                    'ledgerId' => (int)($row['ledger_id'] ?? 0),
                    'periodYear' => (int)($row['period_year'] ?? 0),
                    'periodMonth' => (int)($row['period_month'] ?? 0),
                    'claimType' => (string)($row['claim_type'] ?? ''),
                    'allocatedAmount' => (float)($row['allocated_amount'] ?? 0),
                    'monthlyPensionAmount' => (float)($row['monthly_pension_amount'] ?? 0),
                    'allocationStatus' => (string)($row['allocation_status'] ?? ''),
                    'note' => (string)($row['note'] ?? ''),
                    'beneficiaryName' => (string)($row['beneficiary_name'] ?? ''),
                    'matchedName' => (string)($row['matched_name'] ?? '')
                ];
            }
            $allocationStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'cycles' => $cycles,
        'cycle' => $cycleDetail,
        'entries' => $entries,
        'allocations' => $allocations
    ]);
} catch (Throwable $e) {
    error_log('get_gratuity_schedule_uploads error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load gratuity schedule uploads']);
}

$conn->close();
