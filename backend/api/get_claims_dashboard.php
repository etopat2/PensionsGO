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

function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bindArgs = [];
    $bindArgs[] = &$types;
    foreach ($params as $idx => $value) {
        $bindArgs[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function isStrategicClaimsRole(string $role): bool {
    $normalized = strtolower(trim($role));
    return in_array($normalized, ['super_admin', 'admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension'], true);
}

try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(200, (int)($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;

    $claimType = normalizeArrearsClaimType((string)($_GET['claim_type'] ?? ''));
    if (trim((string)($_GET['claim_type'] ?? '')) === '') {
        $claimType = '';
    }
    $status = trim((string)($_GET['status'] ?? ''));
    $claimStatus = trim((string)($_GET['claim_status'] ?? ''));
    $year = (int)($_GET['year'] ?? 0);
    $quarter = trim((string)($_GET['quarter'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));

    $where = [
        "1=1",
        "LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'"
    ];
    $params = [];
    $types = '';

    if ($claimType !== '') {
        $where[] = "l.claim_type = ?";
        $params[] = $claimType;
        $types .= 's';
    }
    if ($status !== '') {
        $where[] = "l.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    if ($claimStatus !== '') {
        $where[] = "l.claim_status = ?";
        $params[] = $claimStatus;
        $types .= 's';
    }
    if ($year > 1900 && $year < 2200) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
    }
    if (in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
        $where[] = "l.quarter_label = ?";
        $params[] = $quarter;
        $types .= 's';
    }
    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $where[] = "(l.regNo LIKE ? OR CONCAT_WS(' ', fr.sName, fr.fName) LIKE ? OR COALESCE(fr.supplierNo, '') LIKE ?)";
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $types .= 'sss';
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total_rows
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
    ";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare arrears count query');
    }
    $countParams = $params;
    $countTypes = $types;
    bindDynamic($countStmt, $countTypes, $countParams);
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    $totalRows = (int)($countResult['total_rows'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $summarySql = "
        SELECT
            COALESCE(SUM(l.expected_amount), 0) AS expected_total,
            COALESCE(SUM(l.paid_amount), 0) AS paid_total,
            COALESCE(SUM(l.balance_amount), 0) AS balance_total,
            COUNT(*) AS entry_count,
            SUM(CASE WHEN l.status IN ('Pending', 'Partially Paid') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN COALESCE(l.accountability_status, '') = 'Pending Accountability' THEN 1 ELSE 0 END) AS pending_accountability_count,
            SUM(CASE WHEN COALESCE(l.accountability_status, '') = 'Accountability Submitted' THEN 1 ELSE 0 END) AS accountability_submitted_count
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
    ";
    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new RuntimeException('Unable to prepare arrears summary query');
    }
    $summaryParams = $params;
    $summaryTypes = $types;
    bindDynamic($summaryStmt, $summaryTypes, $summaryParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $groupSql = "
        SELECT
            l.claim_type,
            COUNT(*) AS entry_count,
            COALESCE(SUM(l.expected_amount), 0) AS expected_total,
            COALESCE(SUM(l.paid_amount), 0) AS paid_total,
            COALESCE(SUM(l.balance_amount), 0) AS balance_total
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        GROUP BY l.claim_type
        ORDER BY l.claim_type ASC
    ";
    $groupStmt = $conn->prepare($groupSql);
    if (!$groupStmt) {
        throw new RuntimeException('Unable to prepare arrears group query');
    }
    $groupParams = $params;
    $groupTypes = $types;
    bindDynamic($groupStmt, $groupTypes, $groupParams);
    $groupStmt->execute();
    $groupRes = $groupStmt->get_result();
    $byTypeMap = [];
    while ($row = $groupRes->fetch_assoc()) {
        $label = normalizeArrearsClaimType((string)($row['claim_type'] ?? ''));
        if (!isset($byTypeMap[$label])) {
            $byTypeMap[$label] = [
                'claimType' => $label,
                'entries' => 0,
                'expected' => 0,
                'paid' => 0,
                'balance' => 0
            ];
        }
        $byTypeMap[$label]['entries'] += (int)($row['entry_count'] ?? 0);
        $byTypeMap[$label]['expected'] += (float)($row['expected_total'] ?? 0);
        $byTypeMap[$label]['paid'] += (float)($row['paid_total'] ?? 0);
        $byTypeMap[$label]['balance'] += (float)($row['balance_total'] ?? 0);
    }
    $byType = array_values($byTypeMap);
    usort($byType, static fn(array $a, array $b): int => strcmp($a['claimType'], $b['claimType']));
    $groupStmt->close();

    $quarterSql = "
        SELECT
            CASE
                WHEN TRIM(COALESCE(l.financial_year_label, '')) <> '' THEN TRIM(l.financial_year_label)
                WHEN l.period_year IS NOT NULL AND l.period_month IS NOT NULL THEN CONCAT(
                    'FY ',
                    CASE WHEN l.period_month >= 7 THEN l.period_year ELSE l.period_year - 1 END,
                    '/',
                    CASE WHEN l.period_month >= 7 THEN l.period_year + 1 ELSE l.period_year END
                )
                ELSE ''
            END AS financial_year_label,
            CASE
                WHEN TRIM(COALESCE(l.quarter_label, '')) <> '' THEN TRIM(l.quarter_label)
                WHEN l.period_month BETWEEN 7 AND 9 THEN 'Q1'
                WHEN l.period_month BETWEEN 10 AND 12 THEN 'Q2'
                WHEN l.period_month BETWEEN 1 AND 3 THEN 'Q3'
                WHEN l.period_month BETWEEN 4 AND 6 THEN 'Q4'
                ELSE ''
            END AS quarter_label,
            COUNT(*) AS entry_count,
            COALESCE(SUM(l.expected_amount), 0) AS expected_total,
            COALESCE(SUM(l.paid_amount), 0) AS paid_total,
            COALESCE(SUM(l.balance_amount), 0) AS balance_total
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        GROUP BY financial_year_label, quarter_label
        HAVING financial_year_label <> '' AND quarter_label <> ''
        ORDER BY financial_year_label DESC, FIELD(quarter_label, 'Q4', 'Q3', 'Q2', 'Q1')
    ";
    $quarterStmt = $conn->prepare($quarterSql);
    if (!$quarterStmt) {
        throw new RuntimeException('Unable to prepare quarter summary query');
    }
    $quarterParams = $params;
    $quarterTypes = $types;
    bindDynamic($quarterStmt, $quarterTypes, $quarterParams);
    $quarterStmt->execute();
    $quarterRes = $quarterStmt->get_result();
    $quarterly = [];
    while ($row = $quarterRes->fetch_assoc()) {
        $quarterly[] = [
            'financialYear' => (string)($row['financial_year_label'] ?? ''),
            'quarter' => (string)($row['quarter_label'] ?? ''),
            'entries' => (int)($row['entry_count'] ?? 0),
            'expected' => (float)($row['expected_total'] ?? 0),
            'paid' => (float)($row['paid_total'] ?? 0),
            'balance' => (float)($row['balance_total'] ?? 0)
        ];
    }
    $quarterStmt->close();

    $yearSql = "
        SELECT
            l.period_year,
            COUNT(*) AS entry_count,
            COALESCE(SUM(l.expected_amount), 0) AS expected_total,
            COALESCE(SUM(l.paid_amount), 0) AS paid_total,
            COALESCE(SUM(l.balance_amount), 0) AS balance_total
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
          AND l.period_year IS NOT NULL
          AND l.period_year > 0
        GROUP BY l.period_year
        ORDER BY l.period_year DESC
    ";
    $yearStmt = $conn->prepare($yearSql);
    if (!$yearStmt) {
        throw new RuntimeException('Unable to prepare yearly summary query');
    }
    $yearParams = $params;
    $yearTypes = $types;
    bindDynamic($yearStmt, $yearTypes, $yearParams);
    $yearStmt->execute();
    $yearRes = $yearStmt->get_result();
    $yearly = [];
    while ($row = $yearRes->fetch_assoc()) {
        $yearly[] = [
            'year' => (int)($row['period_year'] ?? 0),
            'entries' => (int)($row['entry_count'] ?? 0),
            'expected' => (float)($row['expected_total'] ?? 0),
            'paid' => (float)($row['paid_total'] ?? 0),
            'balance' => (float)($row['balance_total'] ?? 0)
        ];
    }
    $yearStmt->close();

    $rowsSql = "
        SELECT
            l.ledger_id,
            l.regNo,
            l.claim_type,
            l.period_year,
            l.period_month,
            l.financial_year_label,
            l.quarter_label,
            l.expected_amount,
            l.paid_amount,
            l.balance_amount,
            l.status,
            l.claim_status,
            l.accountability_required,
            l.accountability_status,
            l.source_type,
            l.reference_cycle_id,
            l.reason,
            l.notes,
            l.recorded_at,
            l.updated_at,
            fr.title,
            fr.sName,
            fr.fName,
            fr.supplierNo,
            fr.payType,
            fr.livingStatus
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        ORDER BY l.period_year DESC, l.period_month DESC, l.updated_at DESC
        LIMIT ? OFFSET ?
    ";
    $rowsStmt = $conn->prepare($rowsSql);
    if (!$rowsStmt) {
        throw new RuntimeException('Unable to prepare arrears list query');
    }
    $rowsParams = $params;
    $rowsTypes = $types . 'ii';
    $rowsParams[] = $limit;
    $rowsParams[] = $offset;
    bindDynamic($rowsStmt, $rowsTypes, $rowsParams);
    $rowsStmt->execute();
    $rowsRes = $rowsStmt->get_result();
    $rows = [];
    while ($row = $rowsRes->fetch_assoc()) {
        $rows[] = [
            'ledgerId' => (int)($row['ledger_id'] ?? 0),
            'regNo' => (string)($row['regNo'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'displayName' => trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')),
            'name' => formatTitleName(
                (string)($row['title'] ?? ''),
                (string)($row['sName'] ?? ''),
                (string)($row['fName'] ?? '')
            ),
            'claimType' => (string)($row['claim_type'] ?? ''),
            'periodYear' => (int)($row['period_year'] ?? 0),
            'periodMonth' => (int)($row['period_month'] ?? 0),
            'financialYear' => (string)($row['financial_year_label'] ?? ''),
            'quarter' => (string)($row['quarter_label'] ?? ''),
            'expectedAmount' => (float)($row['expected_amount'] ?? 0),
            'paidAmount' => (float)($row['paid_amount'] ?? 0),
            'balanceAmount' => (float)($row['balance_amount'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'claimStatus' => (string)($row['claim_status'] ?? ''),
            'accountabilityRequired' => ((int)($row['accountability_required'] ?? 0)) === 1,
            'accountabilityStatus' => (string)($row['accountability_status'] ?? ''),
            'sourceType' => (string)($row['source_type'] ?? ''),
            'referenceCycleId' => (int)($row['reference_cycle_id'] ?? 0),
            'reason' => (string)($row['reason'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'recordedAt' => (string)($row['recorded_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'supplierNo' => (string)($row['supplierNo'] ?? ''),
            'payType' => (string)($row['payType'] ?? ''),
            'livingStatus' => (string)($row['livingStatus'] ?? '')
        ];
    }
    $rowsStmt->close();

    $recentPayments = [];
    $paymentStmt = $conn->prepare("
        SELECT
            p.payment_id,
            p.regNo,
            p.claim_type,
            p.amount,
            p.applied_amount,
            p.unapplied_amount,
            p.payment_date,
            p.payment_financial_year_label,
            p.reference_no,
            p.notes,
            p.accountability_required,
            p.accountability_status,
            p.latest_submission_id,
            p.created_at,
            fr.title,
            fr.sName,
            fr.fName,
            fr.supplierNo
        FROM tb_arrears_payments p
        LEFT JOIN tb_fileregistry fr ON fr.regNo = p.regNo
        ORDER BY p.payment_date DESC, p.payment_id DESC
        LIMIT 20
    ");
    if ($paymentStmt) {
        $paymentStmt->execute();
        $paymentRes = $paymentStmt->get_result();
        while ($paymentRow = $paymentRes->fetch_assoc()) {
            $recentPayments[] = [
                'paymentId' => (int)($paymentRow['payment_id'] ?? 0),
                'regNo' => (string)($paymentRow['regNo'] ?? ''),
                'title' => (string)($paymentRow['title'] ?? ''),
                'displayName' => trim((string)($paymentRow['sName'] ?? '') . ' ' . (string)($paymentRow['fName'] ?? '')),
                'name' => formatTitleName(
                    (string)($paymentRow['title'] ?? ''),
                    (string)($paymentRow['sName'] ?? ''),
                    (string)($paymentRow['fName'] ?? '')
                ),
                'supplierNo' => (string)($paymentRow['supplierNo'] ?? ''),
                'claimType' => (string)($paymentRow['claim_type'] ?? ''),
                'amount' => (float)($paymentRow['amount'] ?? 0),
                'appliedAmount' => (float)($paymentRow['applied_amount'] ?? 0),
                'unappliedAmount' => (float)($paymentRow['unapplied_amount'] ?? 0),
                'paymentDate' => (string)($paymentRow['payment_date'] ?? ''),
                'paymentFinancialYear' => (string)($paymentRow['payment_financial_year_label'] ?? ''),
                'referenceNo' => (string)($paymentRow['reference_no'] ?? ''),
                'notes' => (string)($paymentRow['notes'] ?? ''),
                'accountabilityRequired' => ((int)($paymentRow['accountability_required'] ?? 0)) === 1,
                'accountabilityStatus' => (string)($paymentRow['accountability_status'] ?? ''),
                'hasSubmittedAccountability' => (int)($paymentRow['latest_submission_id'] ?? 0) > 0,
                'createdAt' => (string)($paymentRow['created_at'] ?? '')
            ];
        }
        $paymentStmt->close();
    }

    $strategic = [
        'estateExpired' => ['total' => 0, 'male' => 0, 'female' => 0, 'rows' => []],
        'fullPensionDue' => ['total' => 0, 'male' => 0, 'female' => 0, 'rows' => []]
    ];
    if (isStrategicClaimsRole((string)($_SESSION['userRole'] ?? ''))) {
        $today = date('Y-m-d');
        $strategicRegistry = $conn->query("
            SELECT
                regNo,
                title,
                sName,
                fName,
                gender,
                enlistmentDate,
                retirementDate,
                retirementType,
                dateOn15yrs,
                dateOfDeath,
                estateExpiryDate,
                estateStatus,
                fullPension,
                telNo,
                address,
                payType,
                livingStatus
            FROM tb_fileregistry
            WHERE COALESCE(is_deleted, 0) = 0
        ");

        if ($strategicRegistry) {
            while ($row = $strategicRegistry->fetch_assoc()) {
                $retirementType = normalizeBenefitsRetirementTypeKey((string)($row['retirementType'] ?? ''));
                $retirementDate = (string)($row['retirementDate'] ?? '');
                $payType = deriveRegistryPayTypeFromProfile(
                    $retirementType,
                    (string)($row['enlistmentDate'] ?? ''),
                    $retirementDate,
                    (string)($row['payType'] ?? '')
                );
                if (normalizeRegistryPayType($payType) !== 'Pensioner') {
                    continue;
                }

                $livingStatus = normalizeRegistryLivingStatus((string)($row['livingStatus'] ?? ''));
                $gender = strtolower(trim((string)($row['gender'] ?? '')));
                $dateOn15 = trim((string)($row['dateOn15yrs'] ?? ''));
                if ($dateOn15 === '') {
                    $dateOn15 = (string)(computeDateOn15Years($retirementDate) ?? '');
                }

                $estate = evaluatePensionEstateLifecycle(
                    $retirementDate,
                    $payType,
                    $livingStatus,
                    (string)($row['dateOfDeath'] ?? ''),
                    $today
                );

                if ($livingStatus === 'Deceased' && (string)($estate['label'] ?? '') === '15 Years Elapsed') {
                    $strategic['estateExpired']['total']++;
                    if ($gender === 'male') {
                        $strategic['estateExpired']['male']++;
                    } elseif ($gender === 'female') {
                        $strategic['estateExpired']['female']++;
                    }
                    $strategic['estateExpired']['rows'][] = [
                        'regNo' => (string)($row['regNo'] ?? ''),
                        'name' => formatTitleName(
                            (string)($row['title'] ?? ''),
                            (string)($row['sName'] ?? ''),
                            (string)($row['fName'] ?? '')
                        ),
                        'gender' => (string)($row['gender'] ?? ''),
                        'retirementType' => $retirementType,
                        'retirementDate' => $retirementDate,
                        'dateOfDeath' => (string)($row['dateOfDeath'] ?? ''),
                        'estateExpiryDate' => (string)($estate['estateExpiryDate'] ?? ''),
                        'estateStatus' => (string)($estate['label'] ?? ''),
                        'contact' => (string)($row['telNo'] ?? ''),
                        'address' => (string)($row['address'] ?? '')
                    ];
                }

                if ($livingStatus === 'Alive' && $dateOn15 !== '' && $dateOn15 <= $today) {
                    $strategic['fullPensionDue']['total']++;
                    if ($gender === 'male') {
                        $strategic['fullPensionDue']['male']++;
                    } elseif ($gender === 'female') {
                        $strategic['fullPensionDue']['female']++;
                    }
                    $strategic['fullPensionDue']['rows'][] = [
                        'regNo' => (string)($row['regNo'] ?? ''),
                        'name' => formatTitleName(
                            (string)($row['title'] ?? ''),
                            (string)($row['sName'] ?? ''),
                            (string)($row['fName'] ?? '')
                        ),
                        'gender' => (string)($row['gender'] ?? ''),
                        'retirementType' => $retirementType,
                        'retirementDate' => $retirementDate,
                        'dateOn15yrs' => $dateOn15,
                        'fullPension' => (float)($row['fullPension'] ?? 0),
                        'contact' => (string)($row['telNo'] ?? ''),
                        'address' => (string)($row['address'] ?? '')
                    ];
                }
            }
            $strategicRegistry->free();
        }

        usort($strategic['estateExpired']['rows'], static function (array $left, array $right): int {
            return strcmp((string)($right['estateExpiryDate'] ?? ''), (string)($left['estateExpiryDate'] ?? ''));
        });
        usort($strategic['fullPensionDue']['rows'], static function (array $left, array $right): int {
            return strcmp((string)($right['dateOn15yrs'] ?? ''), (string)($left['dateOn15yrs'] ?? ''));
        });
        $strategic['estateExpired']['rows'] = array_slice($strategic['estateExpired']['rows'], 0, 50);
        $strategic['fullPensionDue']['rows'] = array_slice($strategic['fullPensionDue']['rows'], 0, 50);
    }

    $canManage = currentUserHasPermission($conn, 'claims.arrears.manage');
    $canUploadSuspension = currentUserHasPermission($conn, 'claims.suspension.upload');
    $canManageBudget = currentUserHasPermission($conn, 'budget.manage');

    echo json_encode([
        'success' => true,
        'summary' => [
            'expectedTotal' => (float)($summary['expected_total'] ?? 0),
            'paidTotal' => (float)($summary['paid_total'] ?? 0),
            'balanceTotal' => (float)($summary['balance_total'] ?? 0),
            'entryCount' => (int)($summary['entry_count'] ?? 0),
            'openCount' => (int)($summary['open_count'] ?? 0),
            'pendingAccountabilityCount' => (int)($summary['pending_accountability_count'] ?? 0),
            'accountabilitySubmittedCount' => (int)($summary['accountability_submitted_count'] ?? 0)
        ],
        'byType' => $byType,
        'quarterly' => $quarterly,
        'yearly' => $yearly,
        'rows' => $rows,
        'recentPayments' => $recentPayments,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages
        ],
        'strategic' => $strategic,
        'permissions' => [
            'canManage' => $canManage,
            'canUploadSuspension' => $canUploadSuspension,
            'canManageBudget' => $canManageBudget,
            'canViewStrategic' => isStrategicClaimsRole((string)($_SESSION['userRole'] ?? ''))
        ],
        'claimTypeOptions' => [
            'Pension Arrears',
            'Gratuity Arrears',
            'Full Pension',
            'Full Pension Arrears',
            'Pension and Gratuity Arrears',
            'Underpayment Claim'
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_claims_dashboard error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load claims dashboard']);
}

$conn->close();
?>
