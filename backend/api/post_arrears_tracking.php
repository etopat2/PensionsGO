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

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = [];
}

$action = strtolower(trim((string)($payload['action'] ?? 'create_entry')));

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

function parsePeriodMonthValue(string $raw): ?DateTime {
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m', 'Y/m', 'M Y', 'F Y', 'm/Y', 'm-Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-\d{1,2}$/', $value, $m)) {
        $dt = DateTime::createFromFormat('!Y-n-j', $m[1] . '-' . $m[2] . '-1');
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }

    return null;
}

function buildMonthPeriods(string $start, string $end): array {
    $startDt = parsePeriodMonthValue($start);
    $endDt = parsePeriodMonthValue($end);
    if (!$startDt || !$endDt) {
        return [];
    }
    if ($startDt > $endDt) {
        [$startDt, $endDt] = [$endDt, $startDt];
    }

    $periods = [];
    $cursor = (clone $startDt)->setTime(0, 0, 0);
    $stop = (clone $endDt)->setTime(0, 0, 0);
    while ($cursor <= $stop) {
        $periods[] = [
            'year' => (int)$cursor->format('Y'),
            'month' => (int)$cursor->format('n')
        ];
        $cursor->modify('+1 month');
    }
    return $periods;
}

try {
    ensureArrearsAndBudgetTables($conn);
    if (function_exists('ensureArrearsLedgerTableExists')) {
        $ledgerReady = ensureArrearsLedgerTableExists($conn);
    } else {
        $ledgerReady = tableExists($conn, 'tb_arrears_ledger');
    }
    if (!$ledgerReady) {
        $dbError = trim((string)$conn->error);
        $suffix = $dbError !== '' ? ' (' . $dbError . ')' : '';
        throw new RuntimeException('Arrears ledger table is unavailable. Unable to create the ledger table automatically.' . $suffix);
    }
    if ($action === 'create_entry') {
        $regNo = trim((string)($payload['regNo'] ?? ''));
        if ($regNo === '') {
            echo json_encode(['success' => false, 'message' => 'Registry file number is required']);
            exit;
        }

        $record = upsertArrearsLedgerEntry($conn, [
            'regNo' => $regNo,
            'claim_type' => (string)($payload['claimType'] ?? 'Pension Arrears'),
            'claim_status' => trim((string)($payload['claimStatus'] ?? '')),
            'period_year' => (int)($payload['periodYear'] ?? date('Y')),
            'period_month' => (int)($payload['periodMonth'] ?? date('n')),
            'expected_amount' => (float)($payload['expectedAmount'] ?? 0),
            'source_type' => trim((string)($payload['sourceType'] ?? 'missed_payment')),
            'reference_cycle_id' => (int)($payload['referenceCycleId'] ?? 0),
            'reason' => trim((string)($payload['reason'] ?? '')),
            'notes' => trim((string)($payload['notes'] ?? '')),
            'recorded_by' => (string)($_SESSION['userId'] ?? '')
        ]);

        if (!$record) {
            echo json_encode(['success' => false, 'message' => 'Unable to save arrears entry']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Arrears entry saved successfully.',
            'record' => $record
        ]);
        exit;
    }

    if ($action === 'create_segmented_entry') {
        $regNo = trim((string)($payload['regNo'] ?? ''));
        if ($regNo === '') {
            echo json_encode(['success' => false, 'message' => 'Registry file number is required']);
            exit;
        }

        $claimType = (string)($payload['claimType'] ?? 'Pension Arrears');
        $sourceType = trim((string)($payload['sourceType'] ?? 'missed_payment'));
        $claimStatus = trim((string)($payload['claimStatus'] ?? ''));
        $segments = $payload['segments'] ?? [];
        if (!is_array($segments) || empty($segments)) {
            echo json_encode(['success' => false, 'message' => 'At least one arrears period segment is required']);
            exit;
        }

        $savedRows = 0;
        $failedSegments = [];
        foreach ($segments as $idx => $segment) {
            if (!is_array($segment)) {
                $failedSegments[] = $idx + 1;
                continue;
            }

            $start = trim((string)($segment['start'] ?? ''));
            $end = trim((string)($segment['end'] ?? ''));
            $monthlyAmount = round(max((float)($segment['monthlyAmount'] ?? 0), 0), 2);
            $reason = trim((string)($segment['reason'] ?? ($payload['reason'] ?? '')));
            $notes = trim((string)($segment['notes'] ?? ($payload['notes'] ?? '')));
            $periods = buildMonthPeriods($start, $end);
            if (empty($periods) || $monthlyAmount <= 0) {
                $failedSegments[] = $idx + 1;
                continue;
            }

            foreach ($periods as $period) {
                $record = upsertArrearsLedgerEntry($conn, [
                    'regNo' => $regNo,
                    'claim_type' => $claimType,
                    'claim_status' => $claimStatus,
                    'period_year' => (int)($period['year'] ?? 0),
                    'period_month' => (int)($period['month'] ?? 0),
                    'expected_amount' => $monthlyAmount,
                    'source_type' => $sourceType !== '' ? $sourceType : 'missed_payment',
                    'reference_cycle_id' => (int)($payload['referenceCycleId'] ?? 0),
                    'reason' => $reason,
                    'notes' => $notes,
                    'recorded_by' => (string)($_SESSION['userId'] ?? '')
                ]);
                if ($record) {
                    $savedRows++;
                }
            }
        }

        if ($savedRows <= 0) {
            echo json_encode(['success' => false, 'message' => 'No segment rows were saved. Check the entered periods and monthly amounts.']);
            exit;
        }

        $msg = "Saved {$savedRows} monthly arrears record(s).";
        if (!empty($failedSegments)) {
            $msg .= ' Some segments were skipped: #' . implode(', #', $failedSegments) . '.';
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'savedRows' => $savedRows,
            'failedSegments' => $failedSegments
        ]);
        exit;
    }

    if ($action === 'record_payment') {
        $result = recordArrearsPayment($conn, [
            'regNo' => trim((string)($payload['regNo'] ?? '')),
            'claim_type' => (string)($payload['claimType'] ?? 'Pension Arrears'),
            'amount' => (float)($payload['amount'] ?? 0),
            'payment_date' => trim((string)($payload['paymentDate'] ?? date('Y-m-d'))),
            'reference_no' => trim((string)($payload['referenceNo'] ?? '')),
            'notes' => trim((string)($payload['notes'] ?? '')),
            'recorded_by' => (string)($_SESSION['userId'] ?? '')
        ]);
        if (empty($result['success'])) {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Unable to record payment']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'payment' => $result
        ]);
        exit;
    }

    if ($action === 'update_payment') {
        $result = updateArrearsPaymentRecord($conn, [
            'payment_id' => (int)($payload['paymentId'] ?? 0),
            'regNo' => trim((string)($payload['regNo'] ?? '')),
            'claim_type' => (string)($payload['claimType'] ?? 'Pension Arrears'),
            'amount' => (float)($payload['amount'] ?? 0),
            'payment_date' => trim((string)($payload['paymentDate'] ?? date('Y-m-d'))),
            'reference_no' => trim((string)($payload['referenceNo'] ?? '')),
            'notes' => trim((string)($payload['notes'] ?? '')),
            'recorded_by' => (string)($_SESSION['userId'] ?? '')
        ]);
        if (empty($result['success'])) {
            echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Unable to update payment']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'message' => 'Payment updated successfully.',
            'payment' => $result
        ]);
        exit;
    }

    if ($action === 'delete_payment') {
        $result = reverseArrearsPayment($conn, (int)($payload['paymentId'] ?? 0));
        echo json_encode([
            'success' => !empty($result['success']),
            'message' => $result['message'] ?? 'Unable to deregister payment.'
        ]);
        exit;
    }

    if ($action === 'mark_waived') {
        $ledgerId = (int)($payload['ledgerId'] ?? 0);
        if ($ledgerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ledger record']);
            exit;
        }
        $stmt = $conn->prepare("
            UPDATE tb_arrears_ledger
            SET status = 'Waived',
                settled_at = NOW(),
                balance_amount = 0,
                notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, 'Waived by ', ?)
            WHERE ledger_id = ?
        ");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Unable to update ledger record']);
            exit;
        }
        $actor = (string)($_SESSION['userName'] ?? $_SESSION['userId'] ?? 'System');
        $stmt->bind_param("si", $actor, $ledgerId);
        $stmt->execute();
        $affected = (int)$stmt->affected_rows;
        $stmt->close();

        echo json_encode([
            'success' => $affected > 0,
            'message' => $affected > 0 ? 'Ledger record waived successfully.' : 'No changes made.'
        ]);
        exit;
    }

    if ($action === 'update_entry') {
        $ledgerId = (int)($payload['ledgerId'] ?? 0);
        if ($ledgerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ledger record']);
            exit;
        }

        $lookup = $conn->prepare("
            SELECT paid_amount
            FROM tb_arrears_ledger
            WHERE ledger_id = ?
            LIMIT 1
        ");
        if (!$lookup) {
            echo json_encode(['success' => false, 'message' => 'Unable to load ledger record']);
            exit;
        }
        $lookup->bind_param("i", $ledgerId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Ledger record not found']);
            exit;
        }

        $claimType = normalizeArrearsClaimType((string)($payload['claimType'] ?? 'Pension Arrears'));
        $periodYear = (int)($payload['periodYear'] ?? date('Y'));
        $periodMonth = (int)($payload['periodMonth'] ?? date('n'));
        $expectedAmount = round(max((float)($payload['expectedAmount'] ?? 0), 0), 2);
        $reason = trim((string)($payload['reason'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $claimStatusRaw = trim((string)($payload['claimStatus'] ?? ''));
        $claimStatus = $claimStatusRaw !== '' ? normalizeClaimVerificationStatus($claimStatusRaw) : '';
        $paidAmount = round(max((float)($row['paid_amount'] ?? 0), 0), 2);
        $statusBundle = computeArrearsStatus($expectedAmount, $paidAmount);
        $status = (string)($statusBundle['status'] ?? 'Pending');
        $balance = (float)($statusBundle['balance'] ?? max($expectedAmount - $paidAmount, 0));
        $settledAt = $status === 'Paid' ? date('Y-m-d H:i:s') : null;
        $fyLabel = getFinancialYearLabelForMonth($periodYear, $periodMonth);
        $quarterLabel = getQuarterLabelForMonth($periodMonth);

        $update = $conn->prepare("
            UPDATE tb_arrears_ledger
            SET claim_type = ?, period_year = ?, period_month = ?, financial_year_label = ?, quarter_label = ?,
                expected_amount = ?, balance_amount = ?, status = ?, claim_status = CASE WHEN ? = '' THEN claim_status ELSE ? END,
                reason = ?, notes = ?, settled_at = ?, updated_at = NOW()
            WHERE ledger_id = ?
        ");
        if (!$update) {
            echo json_encode(['success' => false, 'message' => 'Unable to update ledger record']);
            exit;
        }
        $update->bind_param(
            "siissddssssssi",
            $claimType,
            $periodYear,
            $periodMonth,
            $fyLabel,
            $quarterLabel,
            $expectedAmount,
            $balance,
            $status,
            $claimStatus,
            $claimStatus,
            $reason,
            $notes,
            $settledAt,
            $ledgerId
        );
        $ok = $update->execute();
        $affected = (int)$update->affected_rows;
        $update->close();

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Failed to update arrears record']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => $affected > 0 ? 'Arrears record updated successfully.' : 'No changes were detected.'
        ]);
        exit;
    }

    if ($action === 'delete_entry') {
        $ledgerId = (int)($payload['ledgerId'] ?? 0);
        if ($ledgerId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ledger record']);
            exit;
        }

        $check = $conn->prepare("SELECT paid_amount FROM tb_arrears_ledger WHERE ledger_id = ? LIMIT 1");
        if ($check) {
            $check->bind_param("i", $ledgerId);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if ($row && (float)($row['paid_amount'] ?? 0) > 0) {
                echo json_encode(['success' => false, 'message' => 'Paid arrears records cannot be deleted.']);
                exit;
            }
        }

        $delete = $conn->prepare("DELETE FROM tb_arrears_ledger WHERE ledger_id = ? LIMIT 1");
        if (!$delete) {
            echo json_encode(['success' => false, 'message' => 'Unable to delete ledger record']);
            exit;
        }
        $delete->bind_param("i", $ledgerId);
        $delete->execute();
        $affected = (int)$delete->affected_rows;
        $delete->close();

        echo json_encode([
            'success' => $affected > 0,
            'message' => $affected > 0 ? 'Arrears record deleted successfully.' : 'Record not found or already removed.'
        ]);
        exit;
    }

    if ($action === 'delete_entries') {
        $ledgerIds = array_values(array_filter(array_map('intval', (array)($payload['ledgerIds'] ?? [])), static fn($value) => $value > 0));
        if (empty($ledgerIds)) {
            echo json_encode(['success' => false, 'message' => 'No arrears records were selected for deletion.']);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ledgerIds), '?'));
        $typeString = str_repeat('i', count($ledgerIds));

        $checkSql = "SELECT ledger_id, paid_amount FROM tb_arrears_ledger WHERE ledger_id IN ($placeholders)";
        $check = $conn->prepare($checkSql);
        if (!$check) {
            echo json_encode(['success' => false, 'message' => 'Unable to validate arrears selection']);
            exit;
        }
        $check->bind_param($typeString, ...$ledgerIds);
        $check->execute();
        $checkRes = $check->get_result();
        $deletableIds = [];
        $skippedPaid = 0;
        while ($row = $checkRes->fetch_assoc()) {
            if ((float)($row['paid_amount'] ?? 0) > 0) {
                $skippedPaid++;
                continue;
            }
            $deletableIds[] = (int)($row['ledger_id'] ?? 0);
        }
        $check->close();

        if (empty($deletableIds)) {
            echo json_encode(['success' => false, 'message' => 'Selected arrears records cannot be deleted because they already have payments recorded.']);
            exit;
        }

        $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
        $deleteTypes = str_repeat('i', count($deletableIds));
        $delete = $conn->prepare("DELETE FROM tb_arrears_ledger WHERE ledger_id IN ($deletePlaceholders)");
        if (!$delete) {
            echo json_encode(['success' => false, 'message' => 'Unable to delete arrears records']);
            exit;
        }
        $delete->bind_param($deleteTypes, ...$deletableIds);
        $delete->execute();
        $affected = (int)$delete->affected_rows;
        $delete->close();

        $message = $affected > 0
            ? "{$affected} arrears record(s) deleted successfully."
            : 'No arrears records were deleted.';
        if ($skippedPaid > 0) {
            $message .= " {$skippedPaid} paid record(s) were skipped.";
        }

        echo json_encode([
            'success' => $affected > 0,
            'message' => $message,
            'deletedCount' => $affected,
            'skippedPaid' => $skippedPaid
        ]);
        exit;
    }

    if ($action === 'run_auto_reconcile') {
        $role = strtolower(trim((string)($_SESSION['userRole'] ?? '')));
        $isStrategic = in_array($role, ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension'], true);
        if (!$isStrategic) {
            echo json_encode(['success' => false, 'message' => 'Only Admin, OC/Pension, or Deputy OC/Pension can run full reconciliation.']);
            exit;
        }

        $regNo = trim((string)($payload['regNo'] ?? ''));
        $stats = runAutomaticArrearsReconciliation($conn, $regNo !== '' ? $regNo : null);
        if (empty($stats['processed_registries'])) {
            echo json_encode([
                'success' => true,
                'message' => 'No eligible workflow-approved or uploaded-claims records were available for auto reconciliation.',
                'stats' => $stats
            ]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'message' => 'Automatic arrears reconciliation completed.',
            'stats' => $stats
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unsupported arrears action']);
} catch (Throwable $e) {
    error_log('post_arrears_tracking error: ' . $e->getMessage());
    $role = strtolower(trim((string)($_SESSION['userRole'] ?? '')));
    $isPrivileged = in_array($role, ['admin', 'oc_pen', 'deputy_oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pension'], true);
    $payload = ['success' => false, 'message' => 'Failed to process arrears request'];
    if ($isPrivileged) {
        $debug = $e->getMessage();
        $payload['debug'] = $debug;
        $payload['message'] = $debug !== '' ? $debug : $payload['message'];
    }
    echo json_encode($payload);
}

$conn->close();
?>
