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

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensurePayrollManagementTables')) {
    ensurePayrollManagementTables($conn);
}
if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $reconcileError) {
        error_log('get_file_registry_summary payroll reconcile failed: ' . $reconcileError->getMessage());
    }
}

try {
    // Current registry availability is the source of truth for in-shelf/out-of-shelf
    // counts. Open movement rows are only used to flag overdue returns.
    $totalFiles = 0;
    $openOutCount = 0;
    $inRegistry = 0;

    $totalRes = $conn->query("SELECT COUNT(*) AS total_files FROM tb_fileregistry WHERE COALESCE(is_deleted, 0) = 0");
    if (!$totalRes) {
        echo json_encode(['success' => false, 'message' => 'Failed to load registry total']);
        exit;
    }
    $totalRow = $totalRes->fetch_assoc() ?: [];
    $totalFiles = (int)($totalRow['total_files'] ?? 0);
    $totalRes->free();

    $availabilityRes = $conn->query("
        SELECT
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(availability_status, 'in_shelf'))) = 'out_of_shelf' THEN 1
                    ELSE 0
                END
            ) AS out_registry_count,
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(availability_status, 'in_shelf'))) <> 'out_of_shelf' THEN 1
                    ELSE 0
                END
            ) AS in_registry_count
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ");
    if ($availabilityRes) {
        $availabilityRow = $availabilityRes->fetch_assoc() ?: [];
        $openOutCount = (int)($availabilityRow['out_registry_count'] ?? 0);
        $inRegistry = (int)($availabilityRow['in_registry_count'] ?? 0);
        $availabilityRes->free();
    } else {
        $inRegistry = max(0, $totalFiles - $openOutCount);
    }

    $overdueOutCount = 0;
    $overdueRes = $conn->query("
        SELECT COUNT(DISTINCT fr.regNo) AS overdue_out_count
        FROM tb_fileregistry fr
        INNER JOIN tb_file_movements m
          ON m.regNo = fr.regNo
         AND m.returned_at IS NULL
        WHERE COALESCE(fr.is_deleted, 0) = 0
          AND LOWER(TRIM(COALESCE(fr.availability_status, 'in_shelf'))) = 'out_of_shelf'
          AND m.expected_return_at IS NOT NULL
          AND m.expected_return_at < NOW()
    ");
    if ($overdueRes) {
        $overdueRow = $overdueRes->fetch_assoc() ?: [];
        $overdueOutCount = (int)($overdueRow['overdue_out_count'] ?? 0);
        $overdueRes->free();
    }

    if ($openOutCount < 0) {
        $openOutCount = 0;
    }
    if ($openOutCount > $totalFiles) {
        $openOutCount = $totalFiles;
    }
    $inRegistry = max(0, min($totalFiles, $inRegistry));

    $makeStats = static function (): array {
        return [
            'total' => 0,
            'male' => 0,
            'female' => 0
        ];
    };
    $baseCategories = static function () use ($makeStats): array {
        return [
            'pensioner' => [
                'alive' => $makeStats(),
                'deceased' => $makeStats()
            ],
            'oneOff' => [
                'alive' => $makeStats(),
                'deceased' => $makeStats()
            ]
        ];
    };

    $categorizePayType = static function (string $payType): string {
        $normalized = strtolower(trim($payType));
        if (in_array($normalized, ['one-off payment', 'one off payment', 'one-off', 'one off', 'oneoff'], true)) {
            return 'oneOff';
        }
        return 'pensioner';
    };

    $categorizeLiving = static function (string $livingStatus): string {
        $normalized = strtolower(trim($livingStatus));
        return $normalized === 'deceased' ? 'deceased' : 'alive';
    };

    $registryCategories = $baseCategories();

    $registryDemoRes = $conn->query("
        SELECT
            COALESCE(payType, '') AS payType,
            COALESCE(livingStatus, '') AS livingStatus,
            LOWER(TRIM(COALESCE(gender, ''))) AS gender_norm,
            COUNT(*) AS records_count
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
        GROUP BY COALESCE(payType, ''), COALESCE(livingStatus, ''), LOWER(TRIM(COALESCE(gender, '')))
    ");
    if ($registryDemoRes) {
        while ($row = $registryDemoRes->fetch_assoc()) {
            $payBucket = $categorizePayType((string)($row['payType'] ?? ''));
            $livingBucket = $categorizeLiving((string)($row['livingStatus'] ?? ''));
            $gender = strtolower(trim((string)($row['gender_norm'] ?? '')));
            $count = (int)($row['records_count'] ?? 0);

            $registryCategories[$payBucket][$livingBucket]['total'] += $count;
            if ($gender === 'male') {
                $registryCategories[$payBucket][$livingBucket]['male'] += $count;
            } elseif ($gender === 'female') {
                $registryCategories[$payBucket][$livingBucket]['female'] += $count;
            }
        }
        $registryDemoRes->free();
    }

    $emptyLivingBuckets = static function () use ($makeStats): array {
        return [
            'alive' => $makeStats(),
            'deceased' => $makeStats()
        ];
    };

    $latestPayroll = [
        'available' => false,
        'cycleId' => null,
        'year' => null,
        'month' => null,
        'financialYear' => '',
        'quarter' => '',
        'onPayroll' => $emptyLivingBuckets(),
        'offPayroll' => $emptyLivingBuckets()
    ];

    $latestCycleRes = $conn->query("
        SELECT
            cycle_id,
            payroll_year,
            payroll_month,
            financial_year_label,
            quarter_label
        FROM tb_payroll_upload_cycles
        WHERE COALESCE(is_deleted, 0) = 0
        ORDER BY payroll_year DESC, payroll_month DESC, cycle_id DESC
        LIMIT 1
    ");
    $latestCycle = $latestCycleRes ? ($latestCycleRes->fetch_assoc() ?: null) : null;
    if ($latestCycleRes) {
        $latestCycleRes->free();
    }

    if ($latestCycle) {
        $latestYear = (int)($latestCycle['payroll_year'] ?? 0);
        $latestMonth = (int)($latestCycle['payroll_month'] ?? 0);
        if ($latestYear > 0 && $latestMonth > 0) {
            $latestPayroll['available'] = true;
            $latestPayroll['cycleId'] = (int)($latestCycle['cycle_id'] ?? 0);
            $latestPayroll['year'] = $latestYear;
            $latestPayroll['month'] = $latestMonth;
            $latestPayroll['financialYear'] = (string)($latestCycle['financial_year_label'] ?? '');
            $latestPayroll['quarter'] = (string)($latestCycle['quarter_label'] ?? '');

            $latestStatusStmt = $conn->prepare("
                SELECT
                    COALESCE(fr.livingStatus, '') AS livingStatus,
                    LOWER(TRIM(COALESCE(fr.gender, ''))) AS gender_norm,
                    pms.payroll_status,
                    COUNT(*) AS records_count
                FROM tb_registry_payroll_monthly_status pms
                INNER JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
                WHERE pms.payroll_year = ?
                  AND pms.payroll_month = ?
                  AND COALESCE(fr.is_deleted, 0) = 0
                  AND LOWER(TRIM(COALESCE(fr.payType, ''))) = 'pensioner'
                GROUP BY
                    COALESCE(fr.livingStatus, ''),
                    LOWER(TRIM(COALESCE(fr.gender, ''))),
                    pms.payroll_status
            ");
            if ($latestStatusStmt) {
                $latestStatusStmt->bind_param('ii', $latestYear, $latestMonth);
                $latestStatusStmt->execute();
                $latestStatusRes = $latestStatusStmt->get_result();
                while ($row = $latestStatusRes->fetch_assoc()) {
                    $statusNorm = strtolower(trim((string)($row['payroll_status'] ?? '')));
                    $statusBucket = $statusNorm === 'on payroll' ? 'onPayroll' : 'offPayroll';
                    $livingBucket = $categorizeLiving((string)($row['livingStatus'] ?? ''));
                    $gender = strtolower(trim((string)($row['gender_norm'] ?? '')));
                    $count = (int)($row['records_count'] ?? 0);

                    $latestPayroll[$statusBucket][$livingBucket]['total'] += $count;
                    if ($gender === 'male') {
                        $latestPayroll[$statusBucket][$livingBucket]['male'] += $count;
                    } elseif ($gender === 'female') {
                        $latestPayroll[$statusBucket][$livingBucket]['female'] += $count;
                    }
                }
                $latestStatusStmt->close();
            }
        }
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'totalFiles' => $totalFiles,
            'inRegistry' => $inRegistry,
            'outRegistry' => $openOutCount,
            'overdueOutRegistry' => $overdueOutCount,
            'categories' => $registryCategories,
            'latestPayroll' => $latestPayroll
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_file_registry_summary error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while loading file registry summary']);
}

$conn->close();
?>
