<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
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

ensureArrearsAndBudgetTables($conn);
ensureGratuityScheduleTables($conn);

function bindBudgetDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
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

function periodMonthCount(DateTime $from, DateTime $to): int {
    if ($from > $to) {
        return 0;
    }
    $start = (clone $from)->modify('first day of this month')->setTime(0, 0, 0);
    $end = (clone $to)->modify('first day of this month')->setTime(0, 0, 0);
    $months = 0;
    while ($start <= $end) {
        $months++;
        $start->modify('+1 month');
    }
    return $months;
}

function pensionMonthsFromRetirementInRange(?string $retirementDate, DateTime $rangeStart, DateTime $rangeEnd): int {
    $retirementDate = trim((string)$retirementDate);
    if ($retirementDate === '') {
        return 0;
    }
    $retireDt = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$retireDt) {
        return 0;
    }
    $isPayableRetirementMonth = isRetirementMonthPayable($retirementDate);
    $firstPayMonth = $isPayableRetirementMonth
        ? (clone $retireDt)->modify('first day of this month')->setTime(0, 0, 0)
        : (clone $retireDt)->modify('first day of next month')->setTime(0, 0, 0);

    $effectiveStart = $firstPayMonth > $rangeStart ? $firstPayMonth : (clone $rangeStart);
    if ($effectiveStart > $rangeEnd) {
        return 0;
    }
    return periodMonthCount($effectiveStart, $rangeEnd);
}

function parseFyStartYearFromLabel(string $label): int {
    $clean = trim($label);
    if ($clean === '') {
        return 0;
    }
    if (preg_match('/FY\s*(\d{4})/i', $clean, $match)) {
        return (int)$match[1];
    }
    if (preg_match('/^(\d{4})/', $clean, $match)) {
        return (int)$match[1];
    }
    return 0;
}

function buildFyLabelFromStartYear(int $startYear): string {
    if ($startYear <= 0) {
        return '';
    }
    return "FY {$startYear}/" . ($startYear + 1);
}

function getCurrentFyLabel(): string {
    $year = (int)date('Y');
    $month = (int)date('n');
    if (function_exists('getFinancialYearLabelForMonth')) {
        return getFinancialYearLabelForMonth($year, $month);
    }
    $startYear = $month >= 7 ? $year : ($year - 1);
    return buildFyLabelFromStartYear($startYear);
}

function buildBudgetScheduleBridge(mysqli $conn, string $financialYearLabel, array $actuals): array
{
    $selectedFinancialYear = trim($financialYearLabel);
    $openPensionArrears = round(max((float)($actuals['pension_arrears'] ?? 0), 0), 2);
    $openGratuityArrears = round(max((float)($actuals['gratuity_arrears'] ?? 0), 0), 2);
    $openCombinedArrears = round($openPensionArrears + $openGratuityArrears, 2);

    $bridge = [
        'selectedFinancialYear' => $selectedFinancialYear,
        'hasData' => false,
        'cyclesCount' => 0,
        'rowsUploaded' => 0,
        'matchedRows' => 0,
        'unmatchedRows' => 0,
        'exactGratuityRows' => 0,
        'partialGratuityRows' => 0,
        'smallSurplusRows' => 0,
        'pensionArrearsRows' => 0,
        'reviewRows' => 0,
        'attentionRows' => 0,
        'matchRate' => 0.0,
        'totalScheduledAmount' => 0.0,
        'gratuityComponentAmount' => 0.0,
        'pensionSurplusAmount' => 0.0,
        'smallSurplusAmount' => 0.0,
        'allocatedPensionAmount' => 0.0,
        'scheduledFullMonths' => 0,
        'allocatedMonths' => 0,
        'unallocatedScheduledMonths' => 0,
        'unallocatedScheduledAmount' => 0.0,
        'rawCombinedScheduled' => 0.0,
        'openPensionArrears' => $openPensionArrears,
        'openGratuityArrears' => $openGratuityArrears,
        'openCombinedArrears' => $openCombinedArrears,
        'effectivePensionCoverage' => 0.0,
        'effectiveGratuityCoverage' => 0.0,
        'combinedCoverage' => 0.0,
        'remainingPensionGap' => $openPensionArrears,
        'remainingGratuityGap' => $openGratuityArrears,
        'combinedGap' => $openCombinedArrears,
        'coverageRatio' => null,
        'latestCycle' => null
    ];

    if ($selectedFinancialYear === '') {
        return $bridge;
    }

    $aggregateSql = "
        SELECT
            COUNT(*) AS rows_uploaded,
            COUNT(DISTINCT c.cycle_id) AS cycles_count,
            SUM(CASE WHEN e.is_matched = 1 THEN 1 ELSE 0 END) AS matched_rows,
            SUM(CASE WHEN e.is_matched = 0 THEN 1 ELSE 0 END) AS unmatched_rows,
            SUM(CASE WHEN e.classification = 'exact_gratuity_match' THEN 1 ELSE 0 END) AS exact_gratuity_rows,
            SUM(CASE WHEN e.classification = 'partial_gratuity_schedule' THEN 1 ELSE 0 END) AS partial_gratuity_rows,
            SUM(CASE WHEN e.classification IN ('gratuity_plus_small_surplus', 'small_surplus_review') THEN 1 ELSE 0 END) AS small_surplus_rows,
            SUM(CASE WHEN e.classification IN ('gratuity_plus_pension_arrears', 'pension_only_schedule', 'scheduled_without_open_arrears') THEN 1 ELSE 0 END) AS pension_arrears_rows,
            SUM(CASE WHEN e.classification IN ('unmatched_registry', 'review_missing_gratuity_estimate', 'review_missing_monthly_pension', 'pension_review_missing_monthly_pension') THEN 1 ELSE 0 END) AS review_rows,
            COALESCE(SUM(e.scheduled_amount), 0) AS total_scheduled_amount,
            COALESCE(SUM(e.gratuity_component_amount), 0) AS total_gratuity_component,
            COALESCE(SUM(e.pension_surplus_amount), 0) AS total_pension_surplus_amount,
            COALESCE(SUM(e.small_surplus_amount), 0) AS total_small_surplus_amount,
            COALESCE(SUM(e.allocated_pension_amount), 0) AS total_allocated_pension_amount,
            COALESCE(SUM(e.unallocated_scheduled_amount), 0) AS total_unallocated_amount,
            COALESCE(SUM(e.scheduled_full_months), 0) AS total_scheduled_full_months,
            COALESCE(SUM(e.allocated_months), 0) AS total_allocated_months,
            COALESCE(SUM(e.unallocated_scheduled_months), 0) AS total_unallocated_scheduled_months
        FROM tb_gratuity_schedule_entries e
        INNER JOIN tb_gratuity_schedule_cycles c ON c.cycle_id = e.cycle_id
        WHERE COALESCE(c.is_deleted, 0) = 0
          AND c.financial_year_label = ?
    ";
    $aggregateStmt = $conn->prepare($aggregateSql);
    if ($aggregateStmt) {
        $aggregateParams = [$selectedFinancialYear];
        bindBudgetDynamic($aggregateStmt, 's', $aggregateParams);
        $aggregateStmt->execute();
        $aggregateRow = $aggregateStmt->get_result()->fetch_assoc() ?: [];
        $aggregateStmt->close();

        $bridge['cyclesCount'] = (int)($aggregateRow['cycles_count'] ?? 0);
        $bridge['rowsUploaded'] = (int)($aggregateRow['rows_uploaded'] ?? 0);
        $bridge['matchedRows'] = (int)($aggregateRow['matched_rows'] ?? 0);
        $bridge['unmatchedRows'] = (int)($aggregateRow['unmatched_rows'] ?? 0);
        $bridge['exactGratuityRows'] = (int)($aggregateRow['exact_gratuity_rows'] ?? 0);
        $bridge['partialGratuityRows'] = (int)($aggregateRow['partial_gratuity_rows'] ?? 0);
        $bridge['smallSurplusRows'] = (int)($aggregateRow['small_surplus_rows'] ?? 0);
        $bridge['pensionArrearsRows'] = (int)($aggregateRow['pension_arrears_rows'] ?? 0);
        $bridge['reviewRows'] = (int)($aggregateRow['review_rows'] ?? 0);
        $bridge['attentionRows'] = $bridge['unmatchedRows'] + $bridge['reviewRows'] + $bridge['smallSurplusRows'];
        $bridge['matchRate'] = $bridge['rowsUploaded'] > 0
            ? round($bridge['matchedRows'] / $bridge['rowsUploaded'], 4)
            : 0.0;

        $bridge['totalScheduledAmount'] = round((float)($aggregateRow['total_scheduled_amount'] ?? 0), 2);
        $bridge['gratuityComponentAmount'] = round((float)($aggregateRow['total_gratuity_component'] ?? 0), 2);
        $bridge['pensionSurplusAmount'] = round((float)($aggregateRow['total_pension_surplus_amount'] ?? 0), 2);
        $bridge['smallSurplusAmount'] = round((float)($aggregateRow['total_small_surplus_amount'] ?? 0), 2);
        $bridge['allocatedPensionAmount'] = round((float)($aggregateRow['total_allocated_pension_amount'] ?? 0), 2);
        $bridge['scheduledFullMonths'] = (int)($aggregateRow['total_scheduled_full_months'] ?? 0);
        $bridge['allocatedMonths'] = (int)($aggregateRow['total_allocated_months'] ?? 0);
        $bridge['unallocatedScheduledMonths'] = (int)($aggregateRow['total_unallocated_scheduled_months'] ?? 0);
        $bridge['unallocatedScheduledAmount'] = round((float)($aggregateRow['total_unallocated_amount'] ?? 0), 2);
        $bridge['rawCombinedScheduled'] = round($bridge['gratuityComponentAmount'] + $bridge['allocatedPensionAmount'], 2);
        $bridge['effectivePensionCoverage'] = round(min($bridge['openPensionArrears'], $bridge['allocatedPensionAmount']), 2);
        $bridge['effectiveGratuityCoverage'] = round(min($bridge['openGratuityArrears'], $bridge['gratuityComponentAmount']), 2);
        $bridge['combinedCoverage'] = round($bridge['effectivePensionCoverage'] + $bridge['effectiveGratuityCoverage'], 2);
        $bridge['remainingPensionGap'] = round(max($bridge['openPensionArrears'] - $bridge['effectivePensionCoverage'], 0), 2);
        $bridge['remainingGratuityGap'] = round(max($bridge['openGratuityArrears'] - $bridge['effectiveGratuityCoverage'], 0), 2);
        $bridge['combinedGap'] = round(max($bridge['openCombinedArrears'] - $bridge['combinedCoverage'], 0), 2);
        $bridge['coverageRatio'] = $bridge['openCombinedArrears'] > 0
            ? round($bridge['combinedCoverage'] / $bridge['openCombinedArrears'], 4)
            : null;
        $bridge['hasData'] = $bridge['cyclesCount'] > 0 || $bridge['rowsUploaded'] > 0;
    }

    $latestCycleStmt = $conn->prepare("
        SELECT
            cycle_id,
            schedule_year,
            schedule_month,
            financial_year_label,
            quarter_label,
            source_file_original_name,
            created_at,
            total_rows,
            matched_rows,
            total_scheduled_amount
        FROM tb_gratuity_schedule_cycles
        WHERE COALESCE(is_deleted, 0) = 0
          AND financial_year_label = ?
        ORDER BY schedule_year DESC, schedule_month DESC, created_at DESC, cycle_id DESC
        LIMIT 1
    ");
    if ($latestCycleStmt) {
        $latestParams = [$selectedFinancialYear];
        bindBudgetDynamic($latestCycleStmt, 's', $latestParams);
        $latestCycleStmt->execute();
        $latestRow = $latestCycleStmt->get_result()->fetch_assoc() ?: null;
        $latestCycleStmt->close();

        if ($latestRow) {
            $scheduleMonth = (int)($latestRow['schedule_month'] ?? 0);
            $scheduleYear = (int)($latestRow['schedule_year'] ?? 0);
            $bridge['latestCycle'] = [
                'cycleId' => (int)($latestRow['cycle_id'] ?? 0),
                'scheduleYear' => $scheduleYear,
                'scheduleMonth' => $scheduleMonth,
                'scheduleLabel' => formatMonthYearValue($scheduleMonth, $scheduleYear),
                'financialYear' => (string)($latestRow['financial_year_label'] ?? ''),
                'quarterLabel' => (string)($latestRow['quarter_label'] ?? ''),
                'sourceFileName' => (string)($latestRow['source_file_original_name'] ?? ''),
                'createdAt' => (string)($latestRow['created_at'] ?? ''),
                'totalRows' => (int)($latestRow['total_rows'] ?? 0),
                'matchedRows' => (int)($latestRow['matched_rows'] ?? 0),
                'totalScheduledAmount' => round((float)($latestRow['total_scheduled_amount'] ?? 0), 2)
            ];
        }
    }

    return $bridge;
}

function isSuspensionBudgetSourceType(string $sourceType): bool
{
    $normalized = strtolower(trim($sourceType));
    return $normalized !== '' && strpos($normalized, 'suspension') === 0;
}

function sumSuspensionSavedAmountsForFinancialYear(mysqli $conn, string $financialYearLabel): float
{
    $label = trim($financialYearLabel);
    if ($label === '') {
        return 0.0;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(e.amount), 0) AS total_amount
        FROM tb_suspension_upload_entries e
        INNER JOIN tb_suspension_upload_cycles c ON c.suspension_cycle_id = e.suspension_cycle_id
        WHERE COALESCE(c.is_deleted, 0) = 0
          AND TRIM(COALESCE(c.financial_year_label, '')) = ?
    ");
    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param('s', $label);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return round((float)($row['total_amount'] ?? 0), 2);
}

try {
    $financialYear = trim((string)($_GET['financial_year'] ?? ''));
    $pensionerFilter = trim((string)($_GET['pensioner'] ?? ''));
    $rawClaimTypes = trim((string)($_GET['claim_types'] ?? ''));
    $rawStatuses = trim((string)($_GET['statuses'] ?? ''));
    $rawSourceTypes = trim((string)($_GET['source_types'] ?? ''));
    $minTotal = isset($_GET['min_total']) ? (float)$_GET['min_total'] : null;
    $maxTotal = isset($_GET['max_total']) ? (float)$_GET['max_total'] : null;
    $includeZero = isset($_GET['include_zero']) ? (int)$_GET['include_zero'] === 1 : false;
    $sortMode = trim((string)($_GET['sort'] ?? ''));

    $claimTypeFilters = [];
    if ($rawClaimTypes !== '') {
        foreach (explode(',', $rawClaimTypes) as $rawType) {
            $clean = trim($rawType);
            if ($clean === '') {
                continue;
            }
            $normalized = normalizeArrearsClaimType($clean);
            $claimTypeFilters[] = $normalized;
            $claimTypeFilters[] = $clean;
        }
    }
    $claimTypeFilters = array_values(array_unique(array_filter($claimTypeFilters)));

    $statusFilters = [];
    if ($rawStatuses !== '') {
        foreach (explode(',', $rawStatuses) as $rawStatus) {
            $clean = trim($rawStatus);
            if ($clean !== '') {
                $statusFilters[] = $clean;
            }
        }
    }
    $statusFilters = array_values(array_unique($statusFilters));

    $sourceTypeFilters = [];
    if ($rawSourceTypes !== '') {
        foreach (explode(',', $rawSourceTypes) as $rawSource) {
            $clean = trim($rawSource);
            if ($clean === '') {
                continue;
            }
            $sourceTypeFilters[] = normalizeArrearsSourceType($clean);
        }
    }
    $sourceTypeFilters = array_values(array_unique(array_filter($sourceTypeFilters)));
    $sourceTypeFilters = array_values(array_filter(
        $sourceTypeFilters,
        static fn(string $value): bool => !isSuspensionBudgetSourceType($value)
    ));

    $fyMap = [];
    $addFyLabel = static function (string $label) use (&$fyMap): void {
        $clean = trim($label);
        if ($clean === '') {
            return;
        }
        if (preg_match('/^(\d{4})$/', $clean, $m)) {
            $startYear = (int)$m[1];
            $clean = buildFyLabelFromStartYear($startYear);
        } else {
            $startYear = parseFyStartYearFromLabel($clean);
        }
        $fyMap[$clean] = $startYear > 0 ? $startYear : ($fyMap[$clean] ?? 0);
    };

    $ledgerFyResult = $conn->query("
        SELECT DISTINCT financial_year_label AS fy
        FROM tb_arrears_ledger
        WHERE financial_year_label IS NOT NULL AND financial_year_label <> ''
        ORDER BY financial_year_label DESC
    ");
    if ($ledgerFyResult) {
        while ($row = $ledgerFyResult->fetch_assoc()) {
            $addFyLabel((string)($row['fy'] ?? ''));
        }
        $ledgerFyResult->free();
    }

    $scheduleFyResult = $conn->query("
        SELECT DISTINCT financial_year_label AS fy
        FROM tb_gratuity_schedule_cycles
        WHERE COALESCE(is_deleted, 0) = 0
          AND financial_year_label IS NOT NULL
          AND financial_year_label <> ''
        ORDER BY schedule_year DESC, schedule_month DESC
    ");
    if ($scheduleFyResult) {
        while ($row = $scheduleFyResult->fetch_assoc()) {
            $addFyLabel((string)($row['fy'] ?? ''));
        }
        $scheduleFyResult->free();
    }

    $fyResult = $conn->query("
        SELECT DISTINCT financialYear AS fy
        FROM tb_budgetforecast
        WHERE financialYear IS NOT NULL
        ORDER BY financialYear DESC
    ");
    if ($fyResult) {
        while ($row = $fyResult->fetch_assoc()) {
            $y = (int)($row['fy'] ?? 0);
            if ($y > 0) {
                $addFyLabel(buildFyLabelFromStartYear($y));
            }
        }
        $fyResult->free();
    }

    $currentFyLabel = getCurrentFyLabel();
    if ($currentFyLabel !== '') {
        $addFyLabel($currentFyLabel);
    }
    if ($financialYear !== '') {
        $addFyLabel($financialYear);
    }

    $fyRows = [];
    foreach ($fyMap as $label => $startYear) {
        $fyRows[] = ['label' => $label, 'startYear' => (int)$startYear];
    }
    usort($fyRows, static function (array $a, array $b): int {
        $yearCompare = $b['startYear'] <=> $a['startYear'];
        if ($yearCompare !== 0) {
            return $yearCompare;
        }
        return strcmp($a['label'], $b['label']);
    });
    $fyOptions = array_map(static function (array $row): string {
        return (string)$row['label'];
    }, $fyRows);

    $targetStartYear = 0;
    if ($financialYear !== '') {
        $targetStartYear = parseFyStartYearFromLabel($financialYear);
        if ($targetStartYear <= 0 && isset($fyMap[$financialYear])) {
            $targetStartYear = (int)$fyMap[$financialYear];
        }
    }
    if ($targetStartYear <= 0 && !empty($fyRows)) {
        $targetStartYear = (int)$fyRows[0]['startYear'];
    }
    if ($targetStartYear <= 0 && $currentFyLabel !== '') {
        $targetStartYear = parseFyStartYearFromLabel($currentFyLabel);
    }

    $selectedFinancialYearLabel = '';
    if ($targetStartYear > 0) {
        foreach ($fyMap as $label => $startYear) {
            if ((int)$startYear === $targetStartYear) {
                $selectedFinancialYearLabel = $label;
                break;
            }
        }
        if ($selectedFinancialYearLabel === '') {
            $selectedFinancialYearLabel = buildFyLabelFromStartYear($targetStartYear);
        }
    } elseif ($financialYear !== '') {
        $selectedFinancialYearLabel = $financialYear;
    }
    if ($selectedFinancialYearLabel === '' && !empty($fyRows)) {
        $selectedFinancialYearLabel = (string)$fyRows[0]['label'];
        if ($targetStartYear <= 0) {
            $targetStartYear = parseFyStartYearFromLabel($selectedFinancialYearLabel);
        }
    }
    if ($selectedFinancialYearLabel !== '' && !in_array($selectedFinancialYearLabel, $fyOptions, true)) {
        array_unshift($fyOptions, $selectedFinancialYearLabel);
    }

    $forecast = null;
    if ($targetStartYear > 0) {
        $forecastStmt = $conn->prepare("
            SELECT
                id,
                financialYear,
                estimatedPensionAmount,
                estimatedGratuityAmount,
                estimatedPensionArrears,
                estimatedFullPensionArrears,
                estimatedGratuityArrears,
                estimatedUnderpaymentClaims,
                estimatedSuspensionArrears,
                notes,
                createdBy,
                createdAt
            FROM tb_budgetforecast
            WHERE financialYear = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($forecastStmt) {
            $forecastStmt->bind_param("i", $targetStartYear);
            $forecastStmt->execute();
            $forecast = $forecastStmt->get_result()->fetch_assoc();
            $forecastStmt->close();
        }
    }

    $actuals = [
        'pension_arrears' => 0.0,
        'gratuity_arrears' => 0.0,
        'full_pension_arrears' => 0.0,
        'underpayment_claim' => 0.0,
        'suspension_arrears' => 0.0,
        'total_balance' => 0.0
    ];

    if ($selectedFinancialYearLabel !== '') {
        $actualWhere = [
            "financial_year_label = ?",
            "LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'"
        ];
        $actualParams = [$selectedFinancialYearLabel];
        $actualTypes = 's';
        if (!empty($claimTypeFilters)) {
            $placeholders = implode(',', array_fill(0, count($claimTypeFilters), '?'));
            $actualWhere[] = "claim_type IN ({$placeholders})";
            $actualParams = array_merge($actualParams, $claimTypeFilters);
            $actualTypes .= str_repeat('s', count($claimTypeFilters));
        }
        if (!empty($statusFilters)) {
            $placeholders = implode(',', array_fill(0, count($statusFilters), '?'));
            $actualWhere[] = "status IN ({$placeholders})";
            $actualParams = array_merge($actualParams, $statusFilters);
            $actualTypes .= str_repeat('s', count($statusFilters));
        }
        if (!empty($sourceTypeFilters)) {
            $placeholders = implode(',', array_fill(0, count($sourceTypeFilters), '?'));
            $actualWhere[] = "source_type IN ({$placeholders})";
            $actualParams = array_merge($actualParams, $sourceTypeFilters);
            $actualTypes .= str_repeat('s', count($sourceTypeFilters));
        }

        $actualStmt = $conn->prepare("
            SELECT
                LOWER(TRIM(claim_type)) AS claim_key,
                LOWER(TRIM(COALESCE(source_type, ''))) AS source_key,
                COALESCE(SUM(balance_amount), 0) AS total_balance
            FROM tb_arrears_ledger
            WHERE " . implode(' AND ', $actualWhere) . "
            GROUP BY LOWER(TRIM(claim_type)), LOWER(TRIM(COALESCE(source_type, '')))
        ");
        if ($actualStmt) {
            $bindArgs = [];
            $bindArgs[] = &$actualTypes;
            foreach ($actualParams as $i => $param) {
                $bindArgs[] = &$actualParams[$i];
            }
            call_user_func_array([$actualStmt, 'bind_param'], $bindArgs);
            $actualStmt->execute();
            $res = $actualStmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $key = (string)($row['claim_key'] ?? '');
                $amount = (float)($row['total_balance'] ?? 0);
                $normalizedType = strtolower(normalizeArrearsClaimType($key));

                if (strpos($normalizedType, 'pension') !== false && strpos($normalizedType, 'gratuity') !== false && strpos($normalizedType, 'full') === false) {
                    $actuals['pension_arrears'] += $amount;
                    $actuals['gratuity_arrears'] += $amount;
                    continue;
                }

                if ($normalizedType === 'pension arrears') {
                    $actuals['pension_arrears'] += $amount;
                    continue;
                }

                if ($normalizedType === 'gratuity arrears') {
                    $actuals['gratuity_arrears'] += $amount;
                    continue;
                }

                if ($normalizedType === 'full pension arrears' || $normalizedType === 'full pension') {
                    $actuals['full_pension_arrears'] += $amount;
                    continue;
                }

                if (strpos($normalizedType, 'underpayment') !== false) {
                    $actuals['underpayment_claim'] += $amount;
                }
            }
            $actualStmt->close();
        }

        $actuals['suspension_arrears'] = sumSuspensionSavedAmountsForFinancialYear($conn, $selectedFinancialYearLabel);
    }
    $actuals['total_balance'] = round(
        $actuals['pension_arrears']
        + $actuals['gratuity_arrears']
        + $actuals['full_pension_arrears']
        + $actuals['underpayment_claim']
        + $actuals['suspension_arrears'],
        2
    );

    $scheduleBridge = buildBudgetScheduleBridge($conn, $selectedFinancialYearLabel, $actuals);

    $historyRows = [];
    $historyResult = $conn->query("
        SELECT
            id,
            financialYear,
            estimatedPensionAmount,
            estimatedGratuityAmount,
            estimatedPensionArrears,
            estimatedFullPensionArrears,
            estimatedGratuityArrears,
            estimatedUnderpaymentClaims,
            estimatedSuspensionArrears,
            notes,
            createdBy,
            createdAt
        FROM tb_budgetforecast
        ORDER BY financialYear DESC, id DESC
        LIMIT 40
    ");
    if ($historyResult) {
        while ($row = $historyResult->fetch_assoc()) {
            $start = (int)($row['financialYear'] ?? 0);
            $historyRows[] = [
                'id' => (int)($row['id'] ?? 0),
                'financialYear' => $start > 0 ? "FY {$start}/" . ($start + 1) : '',
                'estimatedPensionAmount' => (float)($row['estimatedPensionAmount'] ?? 0),
                'estimatedGratuityAmount' => (float)($row['estimatedGratuityAmount'] ?? 0),
                'estimatedPensionArrears' => (float)($row['estimatedPensionArrears'] ?? 0),
                'estimatedFullPensionArrears' => (float)($row['estimatedFullPensionArrears'] ?? 0),
                'estimatedGratuityArrears' => (float)($row['estimatedGratuityArrears'] ?? 0),
                'estimatedUnderpaymentClaims' => (float)($row['estimatedUnderpaymentClaims'] ?? 0),
                'estimatedSuspensionArrears' => (float)($row['estimatedSuspensionArrears'] ?? 0),
                'notes' => (string)($row['notes'] ?? ''),
                'createdBy' => (string)($row['createdBy'] ?? ''),
                'createdAt' => (string)($row['createdAt'] ?? '')
            ];
        }
        $historyResult->free();
    }

    // Build dynamic arrears matrix by pensioner for planning and aggregation.
    $matrixRows = [];
    $matrixTotals = [
        'pension_arrears' => 0.0,
        'gratuity_arrears' => 0.0,
        'full_pension_arrears' => 0.0,
        'pension_gratuity' => 0.0,
        'underpayment' => 0.0,
        'total' => 0.0
    ];
    $matrixWhere = [
        "1=1",
        "LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'"
    ];
    $matrixParams = [];
    $matrixTypes = '';
    if ($selectedFinancialYearLabel !== '') {
        $matrixWhere[] = "l.financial_year_label = ?";
        $matrixParams[] = $selectedFinancialYearLabel;
        $matrixTypes .= 's';
    }
    if ($pensionerFilter !== '') {
        $pattern = '%' . $pensionerFilter . '%';
        $matrixWhere[] = "(l.regNo LIKE ? OR CONCAT_WS(' ', fr.sName, fr.fName) LIKE ?)";
        $matrixParams[] = $pattern;
        $matrixParams[] = $pattern;
        $matrixTypes .= 'ss';
    }
    if (!empty($claimTypeFilters)) {
        $placeholders = implode(',', array_fill(0, count($claimTypeFilters), '?'));
        $matrixWhere[] = "l.claim_type IN ({$placeholders})";
        $matrixParams = array_merge($matrixParams, $claimTypeFilters);
        $matrixTypes .= str_repeat('s', count($claimTypeFilters));
    }
    if (!empty($statusFilters)) {
        $placeholders = implode(',', array_fill(0, count($statusFilters), '?'));
        $matrixWhere[] = "l.status IN ({$placeholders})";
        $matrixParams = array_merge($matrixParams, $statusFilters);
        $matrixTypes .= str_repeat('s', count($statusFilters));
    }
    if (!empty($sourceTypeFilters)) {
        $placeholders = implode(',', array_fill(0, count($sourceTypeFilters), '?'));
        $matrixWhere[] = "l.source_type IN ({$placeholders})";
        $matrixParams = array_merge($matrixParams, $sourceTypeFilters);
        $matrixTypes .= str_repeat('s', count($sourceTypeFilters));
    }
    $totalExpr = "
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'pension arrears' THEN l.balance_amount ELSE 0 END)
            + SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'gratuity arrears' THEN l.balance_amount ELSE 0 END)
            + SUM(CASE WHEN LOWER(TRIM(l.claim_type)) IN ('full pension', 'full pension arrears') THEN l.balance_amount ELSE 0 END)
            + SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'pension and gratuity arrears' THEN l.balance_amount ELSE 0 END)
            + SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'underpayment claim' THEN l.balance_amount ELSE 0 END)
    ";

    $havingParts = [];
    if (!$includeZero) {
        $havingParts[] = "({$totalExpr}) > 0";
    }
    if ($minTotal !== null && is_numeric($minTotal)) {
        $havingParts[] = "({$totalExpr}) >= ?";
        $matrixParams[] = (float)$minTotal;
        $matrixTypes .= 'd';
    }
    if ($maxTotal !== null && is_numeric($maxTotal) && (float)$maxTotal > 0) {
        $havingParts[] = "({$totalExpr}) <= ?";
        $matrixParams[] = (float)$maxTotal;
        $matrixTypes .= 'd';
    }
    $havingSql = '';
    if (!empty($havingParts)) {
        $havingSql = 'HAVING ' . implode(' AND ', $havingParts);
    }

    $orderBy = "ORDER BY COALESCE(fr.sName, ''), COALESCE(fr.fName, ''), l.regNo";
    if ($sortMode === 'file') {
        $orderBy = "ORDER BY l.regNo ASC, COALESCE(fr.sName, ''), COALESCE(fr.fName, '')";
    } elseif ($sortMode === 'total_desc') {
        $orderBy = "ORDER BY ({$totalExpr}) DESC, l.regNo ASC";
    }

    $matrixSql = "
        SELECT
            l.regNo,
            COALESCE(fr.title, '') AS title,
            COALESCE(fr.sName, '') AS sName,
            COALESCE(fr.fName, '') AS fName,
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'pension arrears' THEN l.balance_amount ELSE 0 END) AS pension_arrears,
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'gratuity arrears' THEN l.balance_amount ELSE 0 END) AS gratuity_arrears,
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) IN ('full pension', 'full pension arrears') THEN l.balance_amount ELSE 0 END) AS full_pension_arrears,
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'pension and gratuity arrears' THEN l.balance_amount ELSE 0 END) AS pension_gratuity,
            SUM(CASE WHEN LOWER(TRIM(l.claim_type)) = 'underpayment claim' THEN l.balance_amount ELSE 0 END) AS underpayment
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE " . implode(' AND ', $matrixWhere) . "
        GROUP BY l.regNo, fr.title, fr.sName, fr.fName
        {$havingSql}
        {$orderBy}
        LIMIT 2000
    ";
    $matrixStmt = $conn->prepare($matrixSql);
    if ($matrixStmt) {
        if ($matrixTypes !== '') {
            $bindArgs = [];
            $bindArgs[] = &$matrixTypes;
            foreach ($matrixParams as $i => $param) {
                $bindArgs[] = &$matrixParams[$i];
            }
            call_user_func_array([$matrixStmt, 'bind_param'], $bindArgs);
        }
        $matrixStmt->execute();
        $matrixRes = $matrixStmt->get_result();
        while ($row = $matrixRes->fetch_assoc()) {
            $rowTotal = round(
                (float)($row['pension_arrears'] ?? 0)
                + (float)($row['gratuity_arrears'] ?? 0)
                + (float)($row['full_pension_arrears'] ?? 0)
                + (float)($row['pension_gratuity'] ?? 0)
                + (float)($row['underpayment'] ?? 0),
                2
            );
            $matrixRows[] = [
                'regNo' => (string)($row['regNo'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'displayName' => trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')),
                'name' => formatTitleName(
                    (string)($row['title'] ?? ''),
                    (string)($row['sName'] ?? ''),
                    (string)($row['fName'] ?? '')
                ),
                'pension_arrears' => (float)($row['pension_arrears'] ?? 0),
                'gratuity_arrears' => (float)($row['gratuity_arrears'] ?? 0),
                'full_pension_arrears' => (float)($row['full_pension_arrears'] ?? 0),
                'pension_gratuity' => (float)($row['pension_gratuity'] ?? 0),
                'underpayment' => (float)($row['underpayment'] ?? 0),
                'total' => $rowTotal
            ];
            $matrixTotals['pension_arrears'] += (float)($row['pension_arrears'] ?? 0);
            $matrixTotals['gratuity_arrears'] += (float)($row['gratuity_arrears'] ?? 0);
            $matrixTotals['full_pension_arrears'] += (float)($row['full_pension_arrears'] ?? 0);
            $matrixTotals['pension_gratuity'] += (float)($row['pension_gratuity'] ?? 0);
            $matrixTotals['underpayment'] += (float)($row['underpayment'] ?? 0);
            $matrixTotals['total'] += $rowTotal;
        }
        $matrixStmt->close();
    }
    foreach ($matrixTotals as $k => $v) {
        $matrixTotals[$k] = round((float)$v, 2);
    }

    $pensionerOptions = [];
    $optRes = $conn->query("
        SELECT DISTINCT l.regNo, COALESCE(fr.title, '') AS title, COALESCE(fr.sName, '') AS sName, COALESCE(fr.fName, '') AS fName
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'
        ORDER BY COALESCE(fr.sName, ''), COALESCE(fr.fName, ''), l.regNo
        LIMIT 500
    ");
    if ($optRes) {
        while ($opt = $optRes->fetch_assoc()) {
            $name = formatTitleName(
                (string)($opt['title'] ?? ''),
                (string)($opt['sName'] ?? ''),
                (string)($opt['fName'] ?? '')
            );
            $pensionerOptions[] = [
                'regNo' => (string)($opt['regNo'] ?? ''),
                'name' => $name
            ];
        }
        $optRes->free();
    }

    // Projection engine (current FY + subsequent FY)
    $projection = [
        'current' => [
            'active_monthly' => 0.0,
            'active_count' => 0,
            'active_months' => 0,
            'retirees_gratuity' => 0.0,
            'retirees_monthly' => 0.0,
            'retirees_count' => 0,
            'retirees_months_total' => 0,
            'total' => 0.0
        ],
        'next' => [
            'active_monthly' => 0.0,
            'active_count' => 0,
            'active_months' => 0,
            'current_retirees_monthly' => 0.0,
            'current_retirees_count' => 0,
            'current_retirees_months_total' => 0,
            'next_retirees_gratuity' => 0.0,
            'next_retirees_monthly' => 0.0,
            'next_retirees_count' => 0,
            'next_retirees_months_total' => 0,
            'total' => 0.0
        ],
        'meta' => [
            'current_fy_label' => $selectedFinancialYearLabel,
            'next_fy_label' => '',
            'current_fy_months' => 0,
            'next_fy_months' => 0,
            'current_fy_is_active' => false
        ]
    ];
    if ($targetStartYear > 0) {
        $today = new DateTime('today');
        $currentStart = DateTime::createFromFormat('!Y-m-d', $targetStartYear . '-07-01');
        $currentEnd = DateTime::createFromFormat('!Y-m-d', ($targetStartYear + 1) . '-06-30');
        $nextStart = DateTime::createFromFormat('!Y-m-d', ($targetStartYear + 1) . '-07-01');
        $nextEnd = DateTime::createFromFormat('!Y-m-d', ($targetStartYear + 2) . '-06-30');
        if ($currentStart && $currentEnd && $nextStart && $nextEnd) {
            $nextFyLabel = buildFyLabelFromStartYear($targetStartYear + 1);
            $projection['meta']['current_fy_label'] = $selectedFinancialYearLabel;
            $projection['meta']['next_fy_label'] = $nextFyLabel;
            $projection['meta']['current_fy_is_active'] = ($today >= $currentStart && $today <= $currentEnd);

            $effectiveCurrentStart = $projection['meta']['current_fy_is_active']
                ? (clone $today)
                : (clone $currentStart);
            $activeCurrentMonths = periodMonthCount($effectiveCurrentStart, $currentEnd);
            $activeNextMonths = periodMonthCount($nextStart, $nextEnd);

            $projection['current']['active_months'] = $activeCurrentMonths;
            $projection['next']['active_months'] = $activeNextMonths;
            $projection['meta']['current_fy_months'] = $activeCurrentMonths;
            $projection['meta']['next_fy_months'] = $activeNextMonths;

            $activeRes = $conn->query("
                SELECT reducedPension, fullPension
                FROM tb_fileregistry
                WHERE LOWER(TRIM(COALESCE(livingStatus, ''))) = 'alive'
                  AND LOWER(REPLACE(REPLACE(REPLACE(COALESCE(payType, ''), '-', ''), ' ', ''), '_', '')) = 'pensioner'
            ");
            if ($activeRes) {
                while ($ar = $activeRes->fetch_assoc()) {
                    $monthly = (float)($ar['reducedPension'] ?? 0);
                    if ($monthly <= 0) {
                        $monthly = (float)($ar['fullPension'] ?? 0);
                    }
                    $monthly = round(max($monthly, 0), 2);
                    $projection['current']['active_count'] += 1;
                    $projection['next']['active_count'] += 1;
                    $projection['current']['active_monthly'] += ($monthly * $activeCurrentMonths);
                    $projection['next']['active_monthly'] += ($monthly * $activeNextMonths);
                }
                $activeRes->free();
            }

            $retCurrentStmt = $conn->prepare("
                SELECT retirementDate, reducedPension, fullPension, gratuity
                FROM tb_staffdue
                WHERE retirementDate >= ? AND retirementDate <= ?
            ");
            if ($retCurrentStmt) {
                $startStr = $currentStart->format('Y-m-d');
                $endStr = $currentEnd->format('Y-m-d');
                $retCurrentStmt->bind_param("ss", $startStr, $endStr);
                $retCurrentStmt->execute();
                $retRes = $retCurrentStmt->get_result();
                while ($rr = $retRes->fetch_assoc()) {
                    $monthly = (float)($rr['reducedPension'] ?? 0);
                    if ($monthly <= 0) {
                        $monthly = (float)($rr['fullPension'] ?? 0);
                    }
                    $monthly = round(max($monthly, 0), 2);
                    $monthsCurrent = pensionMonthsFromRetirementInRange((string)($rr['retirementDate'] ?? ''), $effectiveCurrentStart, $currentEnd);
                    $monthsNext = pensionMonthsFromRetirementInRange((string)($rr['retirementDate'] ?? ''), $nextStart, $nextEnd);
                    $projection['current']['retirees_count'] += 1;
                    $projection['next']['current_retirees_count'] += 1;
                    $projection['current']['retirees_months_total'] += $monthsCurrent;
                    $projection['next']['current_retirees_months_total'] += $monthsNext;
                    $projection['current']['retirees_monthly'] += ($monthly * $monthsCurrent);
                    $projection['next']['current_retirees_monthly'] += ($monthly * $monthsNext);
                    $projection['current']['retirees_gratuity'] += round(max((float)($rr['gratuity'] ?? 0), 0), 2);
                }
                $retCurrentStmt->close();
            }

            $retNextStmt = $conn->prepare("
                SELECT retirementDate, reducedPension, fullPension, gratuity
                FROM tb_staffdue
                WHERE retirementDate >= ? AND retirementDate <= ?
            ");
            if ($retNextStmt) {
                $nextStartStr = $nextStart->format('Y-m-d');
                $nextEndStr = $nextEnd->format('Y-m-d');
                $retNextStmt->bind_param("ss", $nextStartStr, $nextEndStr);
                $retNextStmt->execute();
                $retNextRes = $retNextStmt->get_result();
                while ($rn = $retNextRes->fetch_assoc()) {
                    $monthly = (float)($rn['reducedPension'] ?? 0);
                    if ($monthly <= 0) {
                        $monthly = (float)($rn['fullPension'] ?? 0);
                    }
                    $monthly = round(max($monthly, 0), 2);
                    $monthsNext = pensionMonthsFromRetirementInRange((string)($rn['retirementDate'] ?? ''), $nextStart, $nextEnd);
                    $projection['next']['next_retirees_count'] += 1;
                    $projection['next']['next_retirees_months_total'] += $monthsNext;
                    $projection['next']['next_retirees_monthly'] += ($monthly * $monthsNext);
                    $projection['next']['next_retirees_gratuity'] += round(max((float)($rn['gratuity'] ?? 0), 0), 2);
                }
                $retNextStmt->close();
            }
        }
    }
    $projection['current']['active_monthly'] = round($projection['current']['active_monthly'], 2);
    $projection['current']['retirees_monthly'] = round($projection['current']['retirees_monthly'], 2);
    $projection['current']['retirees_gratuity'] = round($projection['current']['retirees_gratuity'], 2);
    $projection['current']['total'] = round(
        $projection['current']['active_monthly']
        + $projection['current']['retirees_monthly']
        + $projection['current']['retirees_gratuity'],
        2
    );
    $projection['next']['active_monthly'] = round($projection['next']['active_monthly'], 2);
    $projection['next']['current_retirees_monthly'] = round($projection['next']['current_retirees_monthly'], 2);
    $projection['next']['next_retirees_monthly'] = round($projection['next']['next_retirees_monthly'], 2);
    $projection['next']['next_retirees_gratuity'] = round($projection['next']['next_retirees_gratuity'], 2);
    $projection['next']['total'] = round(
        $projection['next']['active_monthly']
        + $projection['next']['current_retirees_monthly']
        + $projection['next']['next_retirees_monthly']
        + $projection['next']['next_retirees_gratuity'],
        2
    );
    $projection['current']['active_count'] = (int)$projection['current']['active_count'];
    $projection['current']['active_months'] = (int)$projection['current']['active_months'];
    $projection['current']['retirees_count'] = (int)$projection['current']['retirees_count'];
    $projection['current']['retirees_months_total'] = (int)$projection['current']['retirees_months_total'];
    $projection['next']['active_count'] = (int)$projection['next']['active_count'];
    $projection['next']['active_months'] = (int)$projection['next']['active_months'];
    $projection['next']['current_retirees_count'] = (int)$projection['next']['current_retirees_count'];
    $projection['next']['current_retirees_months_total'] = (int)$projection['next']['current_retirees_months_total'];
    $projection['next']['next_retirees_count'] = (int)$projection['next']['next_retirees_count'];
    $projection['next']['next_retirees_months_total'] = (int)$projection['next']['next_retirees_months_total'];
    $projection['meta']['current_fy_months'] = (int)$projection['meta']['current_fy_months'];
    $projection['meta']['next_fy_months'] = (int)$projection['meta']['next_fy_months'];

    $forecastSeed = [
        'estimatedPensionAmount' => round($projection['current']['active_monthly'] + $projection['current']['retirees_monthly'], 2),
        'estimatedGratuityAmount' => round($projection['current']['retirees_gratuity'], 2),
        'estimatedPensionArrears' => round($actuals['pension_arrears'], 2),
        'estimatedFullPensionArrears' => round($actuals['full_pension_arrears'], 2),
        'estimatedGratuityArrears' => round($actuals['gratuity_arrears'], 2),
        'estimatedUnderpaymentClaims' => round($actuals['underpayment_claim'], 2),
        'estimatedSuspensionArrears' => round($actuals['suspension_arrears'], 2),
        'notes' => ''
    ];

    $filterOptions = [
        'claimTypes' => [],
        'statuses' => ['Pending', 'Partially Paid', 'Paid', 'Waived'],
        'sourceTypes' => []
    ];
    $claimTypeRes = $conn->query("
        SELECT DISTINCT claim_type AS claim_type
        FROM tb_arrears_ledger
        WHERE claim_type IS NOT NULL AND claim_type <> ''
        ORDER BY claim_type ASC
    ");
    if ($claimTypeRes) {
        while ($row = $claimTypeRes->fetch_assoc()) {
            $filterOptions['claimTypes'][] = (string)($row['claim_type'] ?? '');
        }
        $claimTypeRes->free();
    }
    $sourceTypeRes = $conn->query("
        SELECT DISTINCT source_type AS source_type
        FROM tb_arrears_ledger
        WHERE source_type IS NOT NULL
          AND source_type <> ''
          AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
        ORDER BY source_type ASC
    ");
    if ($sourceTypeRes) {
        while ($row = $sourceTypeRes->fetch_assoc()) {
            $filterOptions['sourceTypes'][] = (string)($row['source_type'] ?? '');
        }
        $sourceTypeRes->free();
    }

    echo json_encode([
        'success' => true,
        'selectedFinancialYear' => $selectedFinancialYearLabel,
        'financialYearOptions' => $fyOptions,
        'forecast' => $forecast ? [
            'id' => (int)($forecast['id'] ?? 0),
            'financialYear' => "FY " . (int)($forecast['financialYear'] ?? 0) . "/" . ((int)($forecast['financialYear'] ?? 0) + 1),
            'estimatedPensionAmount' => (float)($forecast['estimatedPensionAmount'] ?? 0),
            'estimatedGratuityAmount' => (float)($forecast['estimatedGratuityAmount'] ?? 0),
            'estimatedPensionArrears' => (float)($forecast['estimatedPensionArrears'] ?? 0),
            'estimatedFullPensionArrears' => (float)($forecast['estimatedFullPensionArrears'] ?? 0),
            'estimatedGratuityArrears' => (float)($forecast['estimatedGratuityArrears'] ?? 0),
            'estimatedUnderpaymentClaims' => (float)($forecast['estimatedUnderpaymentClaims'] ?? 0),
            'estimatedSuspensionArrears' => (float)($forecast['estimatedSuspensionArrears'] ?? 0),
            'notes' => (string)($forecast['notes'] ?? ''),
            'createdBy' => (string)($forecast['createdBy'] ?? ''),
            'createdAt' => (string)($forecast['createdAt'] ?? '')
        ] : null,
        'forecastSeed' => $forecastSeed,
        'actuals' => $actuals,
        'scheduleBridge' => $scheduleBridge,
        'history' => $historyRows,
        'matrix' => [
            'rows' => $matrixRows,
            'totals' => $matrixTotals,
            'pensionerOptions' => $pensionerOptions
        ],
        'projection' => $projection,
        'filterOptions' => $filterOptions,
        'permissions' => [
            'canManageBudget' => currentUserHasPermission($conn, 'budget.manage')
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_budget_summary error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load budget summary']);
}

$conn->close();
?>
