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

if (!currentUserHasPermission($conn, 'claims.arrears.view')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureArrearsAndBudgetTables($conn);

try {
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    $limit = max(5, min(60, (int)($_GET['limit'] ?? 12)));

    $cycles = [];
    $cycleStmt = $conn->prepare("
        SELECT
            c.suspension_cycle_id,
            c.suspension_year,
            c.suspension_month,
            c.financial_year_label,
            c.quarter_label,
            c.reason_label,
            c.uploaded_by,
            c.source_file,
            c.source_file_original_name,
            c.created_at,
            COUNT(e.entry_id) AS total_rows,
            COALESCE(SUM(e.amount), 0) AS total_amount,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.reason), '') ORDER BY e.reason SEPARATOR ' || ') AS reason_list,
            COUNT(DISTINCT NULLIF(TRIM(e.reason), '')) AS reason_count,
            SUM(CASE WHEN e.is_matched = 1 THEN 1 ELSE 0 END) AS matched_rows,
            SUM(CASE WHEN e.is_matched = 0 THEN 1 ELSE 0 END) AS unmatched_rows
        FROM tb_suspension_upload_cycles c
        LEFT JOIN tb_suspension_upload_entries e ON e.suspension_cycle_id = c.suspension_cycle_id
        WHERE c.is_deleted = 0
        GROUP BY c.suspension_cycle_id
        ORDER BY c.suspension_year DESC, c.suspension_month DESC, c.suspension_cycle_id DESC
        LIMIT ?
    ");
    if (!$cycleStmt) {
        throw new RuntimeException('Unable to prepare suspension cycle query');
    }
    $cycleStmt->bind_param("i", $limit);
    $cycleStmt->execute();
    $res = $cycleStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $cycles[] = [
            'cycleId' => (int)($row['suspension_cycle_id'] ?? 0),
            'year' => (int)($row['suspension_year'] ?? 0),
            'month' => (int)($row['suspension_month'] ?? 0),
            'financialYear' => (string)($row['financial_year_label'] ?? ''),
            'quarter' => (string)($row['quarter_label'] ?? ''),
            'reasonLabel' => buildSuspensionCycleReasonLabel(
                (string)($row['reason_label'] ?? ''),
                (string)($row['reason_list'] ?? ''),
                (int)($row['reason_count'] ?? 0)
            ),
            'uploadedBy' => (string)($row['uploaded_by'] ?? ''),
            'sourceFile' => (string)($row['source_file'] ?? ''),
            'sourceFileName' => (string)($row['source_file_original_name'] ?? ''),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'totalRows' => (int)($row['total_rows'] ?? 0),
            'totalAmount' => (float)($row['total_amount'] ?? 0),
            'savedAmount' => (float)($row['total_amount'] ?? 0),
            'matchedRows' => (int)($row['matched_rows'] ?? 0),
            'unmatchedRows' => (int)($row['unmatched_rows'] ?? 0)
        ];
    }
    $cycleStmt->close();

    $entries = [];
    if ($cycleId > 0) {
        $entryStmt = $conn->prepare("
            SELECT
                e.entry_id,
                e.regNo,
                e.supplierNo,
                e.beneficiary_name,
                e.amount,
                e.reason,
                e.matched_regNo,
                e.is_matched,
                e.created_at,
                fr.title,
                fr.sName,
                fr.fName
            FROM tb_suspension_upload_entries e
            LEFT JOIN tb_fileregistry fr ON fr.regNo = e.matched_regNo
            WHERE e.suspension_cycle_id = ?
            ORDER BY e.entry_id ASC
            LIMIT 1000
        ");
        if ($entryStmt) {
            $entryStmt->bind_param("i", $cycleId);
            $entryStmt->execute();
            $entryRes = $entryStmt->get_result();
            while ($row = $entryRes->fetch_assoc()) {
                $entries[] = [
                    'entryId' => (int)($row['entry_id'] ?? 0),
                    'regNo' => (string)($row['regNo'] ?? ''),
                    'supplierNo' => (string)($row['supplierNo'] ?? ''),
                    'beneficiaryName' => (string)($row['beneficiary_name'] ?? ''),
                    'amount' => (float)($row['amount'] ?? 0),
                    'reason' => (string)($row['reason'] ?? ''),
                    'matchedRegNo' => (string)($row['matched_regNo'] ?? ''),
                    'isMatched' => ((int)($row['is_matched'] ?? 0)) === 1,
                    'matchedName' => formatTitleName(
                        (string)($row['title'] ?? ''),
                        (string)($row['sName'] ?? ''),
                        (string)($row['fName'] ?? '')
                    ),
                    'createdAt' => (string)($row['created_at'] ?? '')
                ];
            }
            $entryStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'cycles' => $cycles,
        'entries' => $entries
    ]);
} catch (Throwable $e) {
    error_log('get_suspension_uploads error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load suspension uploads']);
}

$conn->close();

function buildSuspensionCycleReasonLabel(string $storedLabel, string $reasonList, int $reasonCount): string {
    $storedLabel = trim($storedLabel);
    if ($reasonCount <= 1 && $storedLabel !== '') {
        return $storedLabel;
    }

    $reasons = array_values(array_filter(array_map('trim', explode('||', $reasonList)), static function ($value) {
        return $value !== '';
    }));
    if (empty($reasons)) {
        return $storedLabel !== '' ? $storedLabel : 'Row-level suspension reasons';
    }
    if (count($reasons) === 1) {
        return $reasons[0];
    }

    $preview = array_slice($reasons, 0, 2);
    $label = implode('; ', $preview);
    if (count($reasons) > 2) {
        $label .= ' +' . (count($reasons) - 2) . ' more';
    }
    return 'Mixed Reasons - ' . $label;
}
?>
