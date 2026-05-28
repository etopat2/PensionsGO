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

$payload = json_decode(file_get_contents('php://input'), true);
$action = strtolower(trim((string)($payload['action'] ?? '')));
$cycleId = (int)($payload['cycle_id'] ?? 0);

if ($cycleId <= 0 || !in_array($action, ['delete', 'edit_period'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid management request']);
    exit;
}

$cycleStmt = $conn->prepare("
    SELECT cycle_id, payroll_year, payroll_month
        , source_file, payment_register_file
    FROM tb_payroll_upload_cycles
    WHERE cycle_id = ?
      AND COALESCE(is_deleted, 0) = 0
    LIMIT 1
");
if (!$cycleStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare cycle lookup query']);
    exit;
}
$cycleStmt->bind_param("i", $cycleId);
$cycleStmt->execute();
$cycleRow = $cycleStmt->get_result()->fetch_assoc();
$cycleStmt->close();

if (!$cycleRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Payroll cycle not found or already deleted']);
    exit;
}

$payrollYear = (int)$cycleRow['payroll_year'];
$payrollMonth = (int)$cycleRow['payroll_month'];
$sourceFile = trim((string)($cycleRow['source_file'] ?? ''));
$paymentRegisterFile = trim((string)($cycleRow['payment_register_file'] ?? ''));

if ($action === 'delete') {
    $conn->begin_transaction();
    try {
        $replacementStmt = $conn->prepare("
            SELECT cycle_id
            FROM tb_payroll_upload_cycles
            WHERE payroll_year = ?
              AND payroll_month = ?
              AND cycle_id <> ?
              AND COALESCE(is_deleted, 0) = 0
            ORDER BY cycle_id DESC
            LIMIT 1
        ");
        if (!$replacementStmt) {
            throw new RuntimeException('Unable to prepare replacement cycle query');
        }
        $replacementStmt->bind_param("iii", $payrollYear, $payrollMonth, $cycleId);
        $replacementStmt->execute();
        $replacementCycleId = (int)(($replacementStmt->get_result()->fetch_assoc()['cycle_id'] ?? 0));
        $replacementStmt->close();

        $deleteStatusStmt = $conn->prepare("
            DELETE FROM tb_registry_payroll_monthly_status
            WHERE cycle_id = ?
        ");
        if (!$deleteStatusStmt) {
            throw new RuntimeException('Unable to prepare payroll monthly status cleanup.');
        }
        $deleteStatusStmt->bind_param("i", $cycleId);
        $deleteStatusStmt->execute();
        $deleteStatusStmt->close();

        $deleteEntriesStmt = $conn->prepare("
            DELETE FROM tb_payroll_upload_entries
            WHERE cycle_id = ?
        ");
        if (!$deleteEntriesStmt) {
            throw new RuntimeException('Unable to prepare payroll entry cleanup.');
        }
        $deleteEntriesStmt->bind_param("i", $cycleId);
        $deleteEntriesStmt->execute();
        $deleteEntriesStmt->close();

        $deleteAuditStmt = $conn->prepare("
            DELETE FROM tb_payroll_audit_logs
            WHERE cycle_id = ?
        ");
        if ($deleteAuditStmt) {
            $deleteAuditStmt->bind_param("i", $cycleId);
            $deleteAuditStmt->execute();
            $deleteAuditStmt->close();
        }

        $deleteCycleStmt = $conn->prepare("
            DELETE FROM tb_payroll_upload_cycles
            WHERE cycle_id = ?
              AND COALESCE(is_deleted, 0) = 0
            LIMIT 1
        ");
        if (!$deleteCycleStmt) {
            throw new RuntimeException('Unable to prepare payroll cycle deletion.');
        }
        $deleteCycleStmt->bind_param("i", $cycleId);
        $deleteCycleStmt->execute();
        $affected = (int)$deleteCycleStmt->affected_rows;
        $deleteCycleStmt->close();

        if ($affected <= 0) {
            throw new RuntimeException('Cycle was not deleted');
        }

        if ($replacementCycleId > 0) {
            applyPayrollCycleToRegistry($conn, $replacementCycleId, $payrollYear, $payrollMonth);
        }

        $latestActiveCycle = getLatestActivePayrollCycleInfo($conn);
        if ($latestActiveCycle) {
            applyPayrollCycleToRegistry(
                $conn,
                (int)($latestActiveCycle['cycle_id'] ?? 0),
                (int)($latestActiveCycle['payroll_year'] ?? 0),
                (int)($latestActiveCycle['payroll_month'] ?? 0)
            );
        } else {
            $conn->query("UPDATE tb_fileregistry SET payrollStatus = 'Not on Payroll'");
        }

        $conn->commit();

        deletePayrollManagedFileIfSafe($sourceFile);
        deletePayrollManagedFileIfSafe($paymentRegisterFile);

        logPayrollAudit($conn, [
            'cycle_id' => null,
            'action' => 'delete_cycle',
            'actor_user_id' => $_SESSION['userId'] ?? '',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'details' => [
                'deleted_cycle_id' => $cycleId,
                'payroll_year' => $payrollYear,
                'payroll_month' => $payrollMonth,
                'replacement_cycle_id' => $replacementCycleId,
                'source_file_deleted' => $sourceFile !== '',
                'payment_register_deleted' => $paymentRegisterFile !== ''
            ]
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Payroll cycle and all related payroll rows were deleted successfully.',
            'replacementCycleId' => $replacementCycleId
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('manage_payroll_cycle delete error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Unable to delete payroll cycle.'
        ]);
    }

    $conn->close();
    exit;
}

$newPayrollYear = (int)($payload['payroll_year'] ?? 0);
$newPayrollMonth = (int)($payload['payroll_month'] ?? 0);
if ($newPayrollYear < 2000 || $newPayrollYear > 2100 || $newPayrollMonth < 1 || $newPayrollMonth > 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provide a valid payroll year and month']);
    $conn->close();
    exit;
}

if ($newPayrollYear === $payrollYear && $newPayrollMonth === $payrollMonth) {
    echo json_encode([
        'success' => true,
        'message' => 'Payroll period unchanged.',
        'cycle' => [
            'cycleId' => $cycleId,
            'year' => $payrollYear,
            'month' => $payrollMonth,
            'financialYear' => getFinancialYearLabelForMonth($payrollYear, $payrollMonth),
            'quarter' => getQuarterLabelForMonth($payrollMonth)
        ]
    ]);
    $conn->close();
    exit;
}

$newFinancialYearLabel = getFinancialYearLabelForMonth($newPayrollYear, $newPayrollMonth);
$newQuarterLabel = getQuarterLabelForMonth($newPayrollMonth);

$conn->begin_transaction();
try {
    $updateCycleStmt = $conn->prepare("
        UPDATE tb_payroll_upload_cycles
        SET payroll_year = ?,
            payroll_month = ?,
            financial_year_label = ?,
            quarter_label = ?
        WHERE cycle_id = ?
          AND COALESCE(is_deleted, 0) = 0
        LIMIT 1
    ");
    if (!$updateCycleStmt) {
        throw new RuntimeException('Unable to prepare cycle period update query');
    }
    $updateCycleStmt->bind_param("iissi", $newPayrollYear, $newPayrollMonth, $newFinancialYearLabel, $newQuarterLabel, $cycleId);
    $updateCycleStmt->execute();
    $updatedRows = (int)$updateCycleStmt->affected_rows;
    $updateCycleStmt->close();

    if ($updatedRows <= 0) {
        throw new RuntimeException('No payroll cycle rows were updated');
    }

    $replacementOldStmt = $conn->prepare("
        SELECT cycle_id
        FROM tb_payroll_upload_cycles
        WHERE payroll_year = ?
          AND payroll_month = ?
          AND cycle_id <> ?
          AND COALESCE(is_deleted, 0) = 0
        ORDER BY cycle_id DESC
        LIMIT 1
    ");
    if (!$replacementOldStmt) {
        throw new RuntimeException('Unable to prepare old-period replacement query');
    }
    $replacementOldStmt->bind_param("iii", $payrollYear, $payrollMonth, $cycleId);
    $replacementOldStmt->execute();
    $replacementOldCycleId = (int)(($replacementOldStmt->get_result()->fetch_assoc()['cycle_id'] ?? 0));
    $replacementOldStmt->close();

    if ($replacementOldCycleId > 0) {
        applyPayrollCycleToRegistry($conn, $replacementOldCycleId, $payrollYear, $payrollMonth);
    } else {
        $clearOldStatusStmt = $conn->prepare("
            DELETE FROM tb_registry_payroll_monthly_status
            WHERE payroll_year = ?
              AND payroll_month = ?
              AND cycle_id = ?
        ");
        if ($clearOldStatusStmt) {
            $clearOldStatusStmt->bind_param("iii", $payrollYear, $payrollMonth, $cycleId);
            $clearOldStatusStmt->execute();
            $clearOldStatusStmt->close();
        }
    }

    applyPayrollCycleToRegistry($conn, $cycleId, $newPayrollYear, $newPayrollMonth);
    $conn->commit();

    logPayrollAudit($conn, [
        'cycle_id' => $cycleId,
        'action' => 'edit_cycle_period',
        'actor_user_id' => $_SESSION['userId'] ?? '',
        'actor_role' => $_SESSION['userRole'] ?? '',
        'details' => [
            'cycle_id' => $cycleId,
            'from_year' => $payrollYear,
            'from_month' => $payrollMonth,
            'to_year' => $newPayrollYear,
            'to_month' => $newPayrollMonth,
            'replacement_old_cycle_id' => $replacementOldCycleId
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Payroll period updated successfully.',
        'cycle' => [
            'cycleId' => $cycleId,
            'year' => $newPayrollYear,
            'month' => $newPayrollMonth,
            'financialYear' => $newFinancialYearLabel,
            'quarter' => $newQuarterLabel
        ]
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('manage_payroll_cycle edit_period error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to update payroll period.'
    ]);
}

$conn->close();

function deletePayrollManagedFileIfSafe(?string $relativePath): void
{
    $path = trim((string)$relativePath);
    if ($path === '') {
        return;
    }

    $base = realpath(__DIR__ . '/../uploads/payroll');
    $target = realpath(__DIR__ . '/../' . ltrim($path, '/\\'));
    if ($base && $target && strpos($target, $base) === 0 && is_file($target)) {
        @unlink($target);
    }
}
?>
