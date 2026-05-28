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
    $query = trim((string)($_GET['q'] ?? ''));
    $regNo = trim((string)($_GET['regNo'] ?? ''));
    $limit = max(5, min(40, (int)($_GET['limit'] ?? 15)));

    $suggestions = [];
    if ($query !== '') {
        $pattern = '%' . $query . '%';
        $stmt = $conn->prepare("
            SELECT
                l.regNo,
                COALESCE(fr.title, '') AS title,
                COALESCE(fr.sName, '') AS sName,
                COALESCE(fr.fName, '') AS fName,
                COALESCE(fr.supplierNo, '') AS supplierNo,
                COALESCE(SUM(l.balance_amount), 0) AS outstanding
            FROM tb_arrears_ledger l
            LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
            WHERE (l.regNo LIKE ? OR CONCAT_WS(' ', fr.sName, fr.fName) LIKE ? OR COALESCE(fr.supplierNo, '') LIKE ?)
              AND LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'
            GROUP BY l.regNo, fr.title, fr.sName, fr.fName, fr.supplierNo
            HAVING COALESCE(SUM(l.balance_amount), 0) > 0
            ORDER BY outstanding DESC, l.regNo ASC
            LIMIT ?
        ");
        if ($stmt) {
            $stmt->bind_param("sssi", $pattern, $pattern, $pattern, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $name = formatTitleName(
                    (string)($row['title'] ?? ''),
                    (string)($row['sName'] ?? ''),
                    (string)($row['fName'] ?? '')
                );
                $suggestions[] = [
                    'regNo' => (string)($row['regNo'] ?? ''),
                    'name' => $name,
                    'supplierNo' => (string)($row['supplierNo'] ?? ''),
                    'outstanding' => (float)($row['outstanding'] ?? 0)
                ];
            }
            $stmt->close();
        }
    }

    $beneficiary = null;
    if ($regNo !== '') {
        $infoStmt = $conn->prepare("
            SELECT
                fr.regNo,
                fr.title,
                fr.sName,
                fr.fName,
                fr.supplierNo,
                fr.telNo,
                fr.address,
                fr.livingStatus,
                fr.payType,
                fr.retirementDate
            FROM tb_fileregistry fr
            WHERE fr.regNo = ?
            LIMIT 1
        ");
        if ($infoStmt) {
            $infoStmt->bind_param("s", $regNo);
            $infoStmt->execute();
            $info = $infoStmt->get_result()->fetch_assoc();
            $infoStmt->close();
            if ($info) {
                $sumStmt = $conn->prepare("
                    SELECT
                        COUNT(*) AS entries,
                        SUM(CASE WHEN status IN ('Pending','Partially Paid') THEN 1 ELSE 0 END) AS open_entries,
                        COALESCE(SUM(expected_amount), 0) AS expected_total,
                        COALESCE(SUM(paid_amount), 0) AS paid_total,
                        COALESCE(SUM(balance_amount), 0) AS balance_total
                    FROM tb_arrears_ledger
                    WHERE regNo = ?
                      AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
                ");
                $summary = ['entries' => 0, 'open_entries' => 0, 'expected_total' => 0, 'paid_total' => 0, 'balance_total' => 0];
                if ($sumStmt) {
                    $sumStmt->bind_param("s", $regNo);
                    $sumStmt->execute();
                    $summary = $sumStmt->get_result()->fetch_assoc() ?: $summary;
                    $sumStmt->close();
                }

                $beneficiary = [
                    'regNo' => (string)($info['regNo'] ?? ''),
                    'name' => formatTitleName(
                        (string)($info['title'] ?? ''),
                        (string)($info['sName'] ?? ''),
                        (string)($info['fName'] ?? '')
                    ),
                    'supplierNo' => (string)($info['supplierNo'] ?? ''),
                    'contact' => (string)($info['telNo'] ?? ''),
                    'address' => (string)($info['address'] ?? ''),
                    'livingStatus' => (string)($info['livingStatus'] ?? ''),
                    'payType' => (string)($info['payType'] ?? ''),
                    'retirementDate' => (string)($info['retirementDate'] ?? ''),
                    'entries' => (int)($summary['entries'] ?? 0),
                    'openEntries' => (int)($summary['open_entries'] ?? 0),
                    'expectedTotal' => (float)($summary['expected_total'] ?? 0),
                    'paidTotal' => (float)($summary['paid_total'] ?? 0),
                    'balanceTotal' => (float)($summary['balance_total'] ?? 0),
                    'claimBreakdown' => []
                ];

                $breakdownStmt = $conn->prepare("
                    SELECT claim_type, COALESCE(SUM(balance_amount), 0) AS balance_total
                    FROM tb_arrears_ledger
                    WHERE regNo = ?
                      AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
                    GROUP BY claim_type
                    ORDER BY claim_type ASC
                ");
                if ($breakdownStmt) {
                    $breakdownStmt->bind_param("s", $regNo);
                    $breakdownStmt->execute();
                    $breakdownRes = $breakdownStmt->get_result();
                    while ($breakdown = $breakdownRes->fetch_assoc()) {
                        $beneficiary['claimBreakdown'][] = [
                            'claimType' => (string)($breakdown['claim_type'] ?? ''),
                            'balanceTotal' => (float)($breakdown['balance_total'] ?? 0)
                        ];
                    }
                    $breakdownStmt->close();
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions,
        'beneficiary' => $beneficiary
    ]);
} catch (Throwable $e) {
    error_log('get_arrears_beneficiaries error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load arrears beneficiaries']);
}

$conn->close();
?>
