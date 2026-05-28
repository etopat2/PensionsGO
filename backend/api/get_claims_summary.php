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

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if ($role === 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $claimsMap = [
        'Pension' => 0,
        'Gratuity' => 0,
        'Arrears' => 0,
        'Full Pension' => 0,
        'Underpayment Claim' => 0
    ];

    // Base claims submitted through application submissions.
    $submissionSql = "
        SELECT LOWER(TRIM(COALESCE(appnType, ''))) AS appn_type_key, COUNT(*) AS total
        FROM tb_appnsubmissions
        WHERE appnType IS NOT NULL AND TRIM(appnType) <> ''
        GROUP BY LOWER(TRIM(appnType))
    ";
    $submissionResult = $conn->query($submissionSql);
    if ($submissionResult) {
        while ($row = $submissionResult->fetch_assoc()) {
            $key = (string)($row['appn_type_key'] ?? '');
            $count = (int)($row['total'] ?? 0);
            if ($key === 'pension') {
                $claimsMap['Pension'] += $count;
            } elseif ($key === 'gratuity') {
                $claimsMap['Gratuity'] += $count;
            } elseif ($key === 'full pension') {
                $claimsMap['Full Pension'] += $count;
            } elseif ($key === 'underpayment') {
                $claimsMap['Underpayment Claim'] += $count;
            } elseif ($key === 'arrears') {
                $claimsMap['Arrears'] += $count;
            }
        }
        $submissionResult->free();
    }

    // Operational arrears from the live arrears ledger (open + resolved).
    ensureArrearsAndBudgetTables($conn);
    $ledgerSql = "
        SELECT LOWER(TRIM(claim_type)) AS claim_key, COUNT(*) AS total
        FROM tb_arrears_ledger
        WHERE LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
        GROUP BY LOWER(TRIM(claim_type))
    ";
    $ledgerResult = $conn->query($ledgerSql);
    if ($ledgerResult) {
        while ($row = $ledgerResult->fetch_assoc()) {
            $key = (string)($row['claim_key'] ?? '');
            $count = (int)($row['total'] ?? 0);
            $claimsMap['Arrears'] += $count;

            if (in_array($key, ['pension arrears', 'delayed payroll arrears', 'pension and gratuity arrears'], true)) {
                $claimsMap['Pension'] += $count;
            }
            if (in_array($key, ['gratuity arrears', 'pension and gratuity arrears'], true)) {
                $claimsMap['Gratuity'] += $count;
            }
            if (in_array($key, ['full pension', 'full pension arrears'], true)) {
                $claimsMap['Full Pension'] += $count;
            }
            if ($key === 'underpayment claim') {
                $claimsMap['Underpayment Claim'] += $count;
            }
        }
        $ledgerResult->free();
    }

    $claims = [];
    foreach ($claimsMap as $type => $count) {
        $claims[] = ['type' => $type, 'count' => $count];
    }

    echo json_encode([
        'success' => true,
        'claims' => $claims,
        'generatedAt' => date('c')
    ]);
} catch (Throwable $e) {
    error_log('get_claims_summary error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while loading claims summary']);
}

$conn->close();
?>
