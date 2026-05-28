<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.arrears.view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    ensureArrearsAndBudgetTables($conn);

    $sql = "
        SELECT DISTINCT
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
            l.period_year,
            l.period_month
        FROM tb_arrears_ledger l
        WHERE l.period_year IS NOT NULL
          AND l.period_year > 0
          AND l.period_month IS NOT NULL
          AND l.period_month BETWEEN 1 AND 12
          AND LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'
        ORDER BY l.period_year DESC, l.period_month DESC
    ";

    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException('Unable to load period options.');
    }

    $financialYears = [];
    $years = [];
    $monthsByYear = [];
    $quartersByFinancialYear = [];
    $monthYearOptions = [];
    $monthYearIndex = [];

    while ($row = $res->fetch_assoc()) {
        $fy = trim((string)($row['financial_year_label'] ?? ''));
        $quarter = trim((string)($row['quarter_label'] ?? ''));
        $year = (int)($row['period_year'] ?? 0);
        $month = (int)($row['period_month'] ?? 0);

        if ($fy !== '' && !isset($financialYears[$fy])) {
            $financialYears[$fy] = true;
        }

        if ($fy !== '' && $quarter !== '') {
            if (!isset($quartersByFinancialYear[$fy])) {
                $quartersByFinancialYear[$fy] = [];
            }
            if (!isset($quartersByFinancialYear[$fy][$quarter])) {
                $quartersByFinancialYear[$fy][$quarter] = true;
            }
        }

        if ($year > 0) {
            $years[$year] = true;
            if (!isset($monthsByYear[$year])) {
                $monthsByYear[$year] = [];
            }
            if ($month >= 1 && $month <= 12) {
                $monthsByYear[$year][$month] = true;
            }

            if ($month >= 1 && $month <= 12) {
                $key = sprintf('%04d-%02d', $year, $month);
                if (!isset($monthYearIndex[$key])) {
                    $label = DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
                    $monthYearOptions[] = [
                        'value' => $key,
                        'label' => $label ? $label->format('M Y') : $key
                    ];
                    $monthYearIndex[$key] = true;
                }
            }
        }
    }
    $res->free();

    $financialYears = array_keys($financialYears);
    $years = array_map('intval', array_keys($years));
    rsort($years);

    foreach ($monthsByYear as $yearKey => $months) {
        $monthValues = array_map('intval', array_keys($months));
        sort($monthValues);
        $monthsByYear[$yearKey] = $monthValues;
    }

    $quarterOrder = ['Q1' => 1, 'Q2' => 2, 'Q3' => 3, 'Q4' => 4];
    foreach ($quartersByFinancialYear as $fy => $quarters) {
        $quarterValues = array_keys($quarters);
        usort($quarterValues, static function ($a, $b) use ($quarterOrder) {
            return ($quarterOrder[$a] ?? 99) <=> ($quarterOrder[$b] ?? 99);
        });
        $quartersByFinancialYear[$fy] = $quarterValues;
    }

    usort($monthYearOptions, static function ($a, $b) {
        return strcmp((string)($a['value'] ?? ''), (string)($b['value'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'financialYears' => $financialYears,
        'years' => $years,
        'monthsByYear' => $monthsByYear,
        'quartersByFinancialYear' => $quartersByFinancialYear,
        'monthYearOptions' => $monthYearOptions
    ]);
} catch (Throwable $e) {
    error_log('get_claims_period_options error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load period options.']);
}

$conn->close();
