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

function preview_bind_dynamic(mysqli_stmt $stmt, string $types, array &$params): void {
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

function preview_format_month_year(int $month, int $year): string {
    if ($month < 1 || $month > 12 || $year <= 0) {
        return '';
    }
    $dt = DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
    return $dt ? $dt->format('M Y') : '';
}

function preview_normalize_bool($value, bool $default): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value)) {
        $trim = strtolower(trim($value));
        if (in_array($trim, ['true', '1', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($trim, ['false', '0', 'no', 'n'], true)) {
            return false;
        }
    }
    if (is_int($value)) {
        return $value === 1;
    }
    return $default;
}

try {
    ensureArrearsAndBudgetTables($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : (is_array($payload) ? $payload : []);
    $page = max(1, (int)($payload['page'] ?? 1));
    $limit = max(5, min(100, (int)($payload['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $allowedTypes = [
        'Pension Arrears',
        'Gratuity Arrears',
        'Full Pension',
        'Full Pension Arrears',
        'Pension and Gratuity Arrears',
        'Underpayment Claim'
    ];
    $selectedTypesRaw = array_map('normalizeArrearsClaimType', (array)($filters['claim_types'] ?? []));
    $selectedTypes = array_values(array_intersect($allowedTypes, array_unique(array_filter($selectedTypesRaw))));
    if (empty($selectedTypes)) {
        $selectedTypes = $allowedTypes;
    }

    $aggregationMode = (string)($filters['aggregation_mode'] ?? 'by_pensioner');
    $typeMode = (string)($filters['type_mode'] ?? 'by_type');
    $periodScope = (string)($filters['period_scope'] ?? 'all');
    $financialYear = trim((string)($filters['financial_year'] ?? ''));
    $quarter = trim((string)($filters['quarter'] ?? ''));
    $year = (int)($filters['year'] ?? 0);
    $month = (int)($filters['month'] ?? 0);
    $fromYear = (int)($filters['from_year'] ?? 0);
    $fromMonth = (int)($filters['from_month'] ?? 0);
    $toYear = (int)($filters['to_year'] ?? 0);
    $toMonth = (int)($filters['to_month'] ?? 0);
    $search = trim((string)($filters['search'] ?? ''));
    $retirementType = trim((string)($filters['retirement_type'] ?? ''));
    $livingStatus = trim((string)($filters['living_status'] ?? ''));
    $statusFilters = array_values(array_filter((array)($filters['status'] ?? [])));
    $claimStatusFilters = array_values(array_filter((array)($filters['claim_status'] ?? [])));
    $outstandingOnly = preview_normalize_bool($filters['outstanding_only'] ?? null, true);
    $includeSubtotal = preview_normalize_bool($filters['include_subtotal'] ?? null, true);

    $where = [
        '1=1',
        "LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'"
    ];
    $params = [];
    $types = '';

    if (!empty($selectedTypes)) {
        $placeholders = implode(',', array_fill(0, count($selectedTypes), '?'));
        $where[] = "l.claim_type IN ({$placeholders})";
        foreach ($selectedTypes as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if (!empty($statusFilters)) {
        $placeholders = implode(',', array_fill(0, count($statusFilters), '?'));
        $where[] = "l.status IN ({$placeholders})";
        foreach ($statusFilters as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if (!empty($claimStatusFilters)) {
        $placeholders = implode(',', array_fill(0, count($claimStatusFilters), '?'));
        $where[] = "l.claim_status IN ({$placeholders})";
        foreach ($claimStatusFilters as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if ($retirementType !== '') {
        $retirementAliases = getBenefitsRetirementTypeAliasesForFilter($retirementType);
        if (!empty($retirementAliases)) {
            $placeholders = implode(',', array_fill(0, count($retirementAliases), '?'));
            $where[] = "LOWER(TRIM(COALESCE(fr.retirementType, ''))) IN ({$placeholders})";
            foreach ($retirementAliases as $alias) {
                $params[] = $alias;
                $types .= 's';
            }
        }
    }

    if ($livingStatus !== '') {
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = ?";
        $params[] = strtolower($livingStatus);
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

    if ($periodScope === 'financial_year' && $financialYear !== '') {
        $where[] = "l.financial_year_label = ?";
        $params[] = $financialYear;
        $types .= 's';
    }
    if ($periodScope === 'quarter' && $quarter !== '') {
        $where[] = "l.quarter_label = ?";
        $params[] = $quarter;
        $types .= 's';
        if ($financialYear !== '') {
            $where[] = "l.financial_year_label = ?";
            $params[] = $financialYear;
            $types .= 's';
        }
    }
    if ($periodScope === 'year' && $year > 0) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
    }
    if ($periodScope === 'month' && $year > 0 && $month > 0) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
        $where[] = "l.period_month = ?";
        $params[] = $month;
        $types .= 'i';
    }
    if ($periodScope === 'range' && $fromYear > 0 && $fromMonth > 0 && $toYear > 0 && $toMonth > 0) {
        $fromValue = ($fromYear * 100) + $fromMonth;
        $toValue = ($toYear * 100) + $toMonth;
        if ($toValue < $fromValue) {
            $temp = $fromValue;
            $fromValue = $toValue;
            $toValue = $temp;
        }
        $where[] = "((l.period_year * 100) + l.period_month) BETWEEN ? AND ?";
        $params[] = $fromValue;
        $params[] = $toValue;
        $types .= 'ii';
    }

    $extraColumns = array_values(array_filter((array)($filters['extra_columns'] ?? [])));
    $extraColumns = array_values(array_intersect(['supplierNo', 'retirementType', 'livingStatus'], $extraColumns));

    $byPeriod = $aggregationMode === 'by_pensioner_period';
    $groupColumns = $byPeriod ? 'l.regNo, l.period_year, l.period_month' : 'l.regNo';
    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT 1
            FROM tb_arrears_ledger l
            LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
            WHERE {$whereSql}
            GROUP BY {$groupColumns}
            " . ($outstandingOnly ? "HAVING SUM(l.balance_amount) > 0" : "") . "
        ) summary
    ";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare preview count query.');
    }
    $countParams = $params;
    $countTypes = $types;
    preview_bind_dynamic($countStmt, $countTypes, $countParams);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();

    $totalRows = (int)($countRow['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $groupSql = "
        SELECT
            l.regNo,
            " . ($byPeriod ? 'l.period_year, l.period_month,' : '') . "
            SUM(l.balance_amount) AS balance_total
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        GROUP BY {$groupColumns}
        " . ($outstandingOnly ? "HAVING SUM(l.balance_amount) > 0" : "") . "
        ORDER BY l.regNo ASC" . ($byPeriod ? ", l.period_year ASC, l.period_month ASC" : '') . "
        LIMIT ? OFFSET ?
    ";
    $groupStmt = $conn->prepare($groupSql);
    if (!$groupStmt) {
        throw new RuntimeException('Unable to prepare preview group query.');
    }
    $groupParams = $params;
    $groupTypes = $types . 'ii';
    $groupParams[] = $limit;
    $groupParams[] = $offset;
    preview_bind_dynamic($groupStmt, $groupTypes, $groupParams);
    $groupStmt->execute();
    $groupRes = $groupStmt->get_result();
    $groupRows = [];
    while ($row = $groupRes->fetch_assoc()) {
        $groupRows[] = $row;
    }
    $groupStmt->close();

    $maxNameSql = "
        SELECT MAX(name_len) AS max_name_len
        FROM (
            SELECT CHAR_LENGTH(TRIM(CONCAT_WS(' ', fr.sName, fr.fName))) AS name_len,
                   SUM(l.balance_amount) AS balance_total
            FROM tb_arrears_ledger l
            LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
            WHERE {$whereSql}
            GROUP BY {$groupColumns}
            " . ($outstandingOnly ? "HAVING SUM(l.balance_amount) > 0" : "") . "
        ) summary
    ";
    $maxStmt = $conn->prepare($maxNameSql);
    if (!$maxStmt) {
        throw new RuntimeException('Unable to prepare preview name length query.');
    }
    $maxParams = $params;
    $maxTypes = $types;
    preview_bind_dynamic($maxStmt, $maxTypes, $maxParams);
    $maxStmt->execute();
    $maxRow = $maxStmt->get_result()->fetch_assoc();
    $maxStmt->close();
    $maxNameLength = (int)($maxRow['max_name_len'] ?? 0);

    $rowsMap = [];
    $order = [];
    foreach ($groupRows as $groupRow) {
        $fileNo = trim((string)($groupRow['regNo'] ?? ''));
        if ($fileNo === '') {
            $fileNo = 'Unspecified File';
        }
        $periodLabel = $byPeriod ? preview_format_month_year((int)($groupRow['period_month'] ?? 0), (int)($groupRow['period_year'] ?? 0)) : '';
        $key = $byPeriod ? ($fileNo . '|' . $periodLabel) : $fileNo;
        if (!isset($rowsMap[$key])) {
            $rowsMap[$key] = [
                'fileNo' => $fileNo,
                'name' => '',
                'period' => $periodLabel,
                'supplierNo' => '',
                'retirementType' => '',
                'livingStatus' => '',
                'values' => [],
                'subtotal' => 0.0
            ];
            $order[] = $key;
        }
    }

    if (!empty($groupRows)) {
        $keyConditions = [];
        $keyParams = [];
        $keyTypes = '';
        foreach ($groupRows as $groupRow) {
            $regNo = trim((string)($groupRow['regNo'] ?? ''));
            if ($regNo === '') {
                continue;
            }
            if ($byPeriod) {
                $keyConditions[] = "(l.regNo = ? AND l.period_year = ? AND l.period_month = ?)";
                $keyParams[] = $regNo;
                $keyParams[] = (int)($groupRow['period_year'] ?? 0);
                $keyParams[] = (int)($groupRow['period_month'] ?? 0);
                $keyTypes .= 'sii';
            } else {
                $keyConditions[] = "l.regNo = ?";
                $keyParams[] = $regNo;
                $keyTypes .= 's';
            }
        }

        $detailSql = "
            SELECT
                l.regNo,
                l.claim_type,
                l.period_year,
                l.period_month,
                SUM(l.balance_amount) AS balance_total,
                fr.title,
                fr.sName,
                fr.fName,
                fr.supplierNo,
                fr.retirementType,
                fr.livingStatus
            FROM tb_arrears_ledger l
            LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
            WHERE {$whereSql}
              AND (" . implode(' OR ', $keyConditions) . ")
            GROUP BY l.regNo, l.period_year, l.period_month, l.claim_type
            ORDER BY l.regNo ASC, l.period_year ASC, l.period_month ASC, l.claim_type ASC
        ";
        $detailStmt = $conn->prepare($detailSql);
        if (!$detailStmt) {
            throw new RuntimeException('Unable to prepare preview details query.');
        }
        $detailParams = array_merge($params, $keyParams);
        $detailTypes = $types . $keyTypes;
        preview_bind_dynamic($detailStmt, $detailTypes, $detailParams);
        $detailStmt->execute();
        $result = $detailStmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $fileNo = trim((string)($row['regNo'] ?? ''));
            if ($fileNo === '') {
                $fileNo = 'Unspecified File';
            }
            $periodLabel = $byPeriod ? preview_format_month_year((int)($row['period_month'] ?? 0), (int)($row['period_year'] ?? 0)) : '';
            $key = $byPeriod ? ($fileNo . '|' . $periodLabel) : $fileNo;
            if (!isset($rowsMap[$key])) {
                continue;
            }
            if ($rowsMap[$key]['name'] === '') {
                $name = trim(trim((string)($row['sName'] ?? '')) . ' ' . trim((string)($row['fName'] ?? '')));
                $rowsMap[$key]['name'] = $name !== '' ? $name : 'Unnamed Pensioner';
            }
            $rowsMap[$key]['supplierNo'] = (string)($row['supplierNo'] ?? '');
            $rowsMap[$key]['retirementType'] = getBenefitsRetirementTypeLabel((string)($row['retirementType'] ?? ''));
            $rowsMap[$key]['livingStatus'] = (string)($row['livingStatus'] ?? '');

            $claimType = normalizeArrearsClaimType((string)($row['claim_type'] ?? ''));
            if (!in_array($claimType, $selectedTypes, true)) {
                continue;
            }
            $amount = round(max(0.0, (float)($row['balance_total'] ?? 0)), 2);
            if (!isset($rowsMap[$key]['values'][$claimType])) {
                $rowsMap[$key]['values'][$claimType] = 0.0;
            }
            $rowsMap[$key]['values'][$claimType] += $amount;
            $rowsMap[$key]['subtotal'] += $amount;
        }
        $detailStmt->close();
    }

    foreach ($rowsMap as $key => $row) {
        if (trim((string)$row['name']) === '') {
            $rowsMap[$key]['name'] = 'Unnamed Pensioner';
        }
    }

    $columns = [
        ['key' => 'fileNo', 'label' => 'File Number', 'type' => 'text'],
        ['key' => 'name', 'label' => 'Name', 'type' => 'text']
    ];
    foreach ($extraColumns as $col) {
        if ($col === 'supplierNo') {
            $columns[] = ['key' => 'supplierNo', 'label' => 'Supplier No', 'type' => 'text'];
        }
        if ($col === 'retirementType') {
            $columns[] = ['key' => 'retirementType', 'label' => 'Retirement Type', 'type' => 'text'];
        }
        if ($col === 'livingStatus') {
            $columns[] = ['key' => 'livingStatus', 'label' => 'Living Status', 'type' => 'text'];
        }
    }
    if ($byPeriod) {
        $columns[] = ['key' => 'period', 'label' => 'Period', 'type' => 'text'];
    }

    $typeKeys = [];
    if ($typeMode === 'by_type') {
        foreach ($selectedTypes as $type) {
            $key = 'type_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($type));
            $typeKeys[$type] = $key;
            $columns[] = ['key' => $key, 'label' => $type . ' (UGX)', 'type' => 'currency'];
        }
    }

    if ($includeSubtotal) {
        $columns[] = ['key' => 'subtotal', 'label' => 'Subtotal (UGX)', 'type' => 'currency'];
    }

    $rows = [];
    foreach ($order as $key) {
        $row = $rowsMap[$key];
        $entry = [
            'fileNo' => $row['fileNo'],
            'name' => $row['name']
        ];
        if (in_array('supplierNo', $extraColumns, true)) {
            $entry['supplierNo'] = $row['supplierNo'];
        }
        if (in_array('retirementType', $extraColumns, true)) {
            $entry['retirementType'] = getBenefitsRetirementTypeLabel((string)($row['retirementType'] ?? ''));
        }
        if (in_array('livingStatus', $extraColumns, true)) {
            $entry['livingStatus'] = $row['livingStatus'];
        }
        if ($byPeriod) {
            $entry['period'] = $row['period'];
        }
        if ($typeMode === 'by_type') {
            foreach ($selectedTypes as $type) {
                $entry[$typeKeys[$type]] = round((float)($row['values'][$type] ?? 0), 2);
            }
        }
        if ($includeSubtotal) {
            $entry['subtotal'] = round((float)$row['subtotal'], 2);
        }
        $rows[] = $entry;
    }

    echo json_encode([
        'success' => true,
        'columns' => $columns,
        'rows' => $rows,
        'maxNameLength' => $maxNameLength,
        'totalRows' => $totalRows,
        'truncated' => false,
        'pagination' => [
            'page' => $page,
            'totalPages' => $totalPages,
            'limit' => $limit
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_claims_aggregation_preview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to preview arrears summary.']);
}

$conn->close();
?>
