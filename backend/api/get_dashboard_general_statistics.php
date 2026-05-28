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

ensureTasksTable($conn);
ensureTaskAlertsTable($conn);
ensureArrearsAndBudgetTables($conn);
ensureLifeCertificateTables($conn);
ensurePayrollManagementTables($conn);
ensureFileMovementTables($conn);
ensureRoleGovernanceTables($conn);
ensureApplicationQueueTable($conn);
ensureStaffDueWorkflowColumns($conn);
ensureStaffDueSoftDeleteColumns($conn);
if (function_exists('ensureTaskPerformanceIndexes')) {
    ensureTaskPerformanceIndexes($conn);
}
if (function_exists('ensureStaffDuePerformanceIndexes')) {
    ensureStaffDuePerformanceIndexes($conn);
}
if (function_exists('ensureFileRegistryPerformanceIndexes')) {
    ensureFileRegistryPerformanceIndexes($conn);
}

try {
    if (function_exists('maybeSyncTaskAlerts')) {
        maybeSyncTaskAlerts($conn);
    } else {
        syncTaskAlerts($conn);
    }
    if (function_exists('maybeSyncCurrentYearLifeCertificateStatus')) {
        maybeSyncCurrentYearLifeCertificateStatus($conn);
    } else {
        syncCurrentYearLifeCertificateStatus($conn);
    }
    if (function_exists('maybeReconcileAllActivePayrollCycles')) {
        try {
            maybeReconcileAllActivePayrollCycles($conn);
        } catch (Throwable $reconcileError) {
            error_log('general_statistics payroll reconcile failed: ' . $reconcileError->getMessage());
        }
    }

    $currentYear = (int)date('Y');
    $verificationEscalationDays = getStaffDueVerificationEscalationDays($conn);
    $verificationDueSoonDays = getStaffDueVerificationDueSoonDays($conn, $verificationEscalationDays);

    $registryRow = $conn->query("
        SELECT
            COUNT(*) AS total_files,
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(payType, ''))) IN ('one-off payment', 'one off payment', 'one-off', 'one off', 'oneoff')
                    THEN 1 ELSE 0
                END
            ) AS oneoff_files,
            SUM(
                CASE
                    WHEN LOWER(TRIM(COALESCE(payType, ''))) IN ('one-off payment', 'one off payment', 'one-off', 'one off', 'oneoff')
                    THEN 0 ELSE 1
                END
            ) AS pensioner_files,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(livingStatus, ''))) = 'alive' THEN 1 ELSE 0 END) AS alive_files,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(livingStatus, ''))) = 'deceased' THEN 1 ELSE 0 END) AS deceased_files
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ")->fetch_assoc() ?: [];

    $movementRow = $conn->query("
        SELECT
            COUNT(m.movement_id) AS total_movements,
            COUNT(DISTINCT CASE
                WHEN LOWER(TRIM(COALESCE(fr.availability_status, 'in_shelf'))) = 'out_of_shelf'
                    THEN fr.regNo
                ELSE NULL
            END) AS out_registry_files,
            COUNT(DISTINCT CASE
                WHEN LOWER(TRIM(COALESCE(fr.availability_status, 'in_shelf'))) <> 'out_of_shelf'
                    THEN fr.regNo
                ELSE NULL
            END) AS in_registry_files,
            COUNT(DISTINCT CASE
                WHEN LOWER(TRIM(COALESCE(fr.availability_status, 'in_shelf'))) = 'out_of_shelf'
                 AND m.returned_at IS NULL
                 AND m.expected_return_at IS NOT NULL
                 AND m.expected_return_at < NOW()
                    THEN fr.regNo
                ELSE NULL
            END) AS overdue_open
        FROM tb_fileregistry fr
        LEFT JOIN tb_file_movements m
          ON m.regNo = fr.regNo
         AND m.returned_at IS NULL
        WHERE COALESCE(fr.is_deleted, 0) = 0
    ")->fetch_assoc() ?: [];

    $staffDueAppnExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('queried', 'querried') THEN 'queried'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'rejected' THEN 'rejected'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('in_process', 'in process', 'inprocess') THEN 'in_process'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('completed', 'approved', 'done') THEN 'completed'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'verified' THEN 'verified'
            ELSE 'pending'
        END
    ";
    $staffDueWorkflowExpr = "
        CASE
            WHEN {$staffDueAppnExpr} = 'completed' OR COALESCE(q.status, '') = 'completed' THEN 'completed'
            WHEN {$staffDueAppnExpr} = 'in_process' OR COALESCE(q.status, '') IN ('submitted_to_oc', 'in_progress') THEN 'in_process'
            ELSE ''
        END
    ";
    $staffDueInitiationExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) <> 'submitted' THEN 'not_submitted'
            WHEN {$staffDueAppnExpr} <> 'pending' THEN 'initiated'
            WHEN sd.submission_at IS NULL THEN 'pending'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationEscalationDays} DAY) THEN 'escalated'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationDueSoonDays} DAY) THEN 'due_soon'
            ELSE 'pending'
        END
    ";
    $staffDueRow = $conn->query("
        SELECT
            COUNT(*) AS total_due,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) = 'submitted' THEN 1 ELSE 0 END) AS submitted_due,
            SUM(CASE WHEN {$staffDueWorkflowExpr} = 'in_process' THEN 1 ELSE 0 END) AS in_process_due,
            SUM(CASE WHEN {$staffDueWorkflowExpr} = 'completed' THEN 1 ELSE 0 END) AS completed_due,
            SUM(CASE WHEN {$staffDueInitiationExpr} = 'initiated' THEN 1 ELSE 0 END) AS initiated_due,
            SUM(CASE WHEN {$staffDueInitiationExpr} = 'pending' THEN 1 ELSE 0 END) AS awaiting_verification_due,
            SUM(CASE WHEN {$staffDueInitiationExpr} = 'due_soon' THEN 1 ELSE 0 END) AS verification_due_soon,
            SUM(CASE WHEN {$staffDueInitiationExpr} = 'escalated' THEN 1 ELSE 0 END) AS verification_escalated
        FROM tb_staffdue sd
        LEFT JOIN tb_application_queue q
          ON q.staffdue_id = sd.id
        WHERE COALESCE(sd.is_deleted, 0) = 0
    ")->fetch_assoc() ?: [];

    $claimsRow = $conn->query("
        SELECT
            COUNT(*) AS entry_count,
            COALESCE(SUM(expected_amount), 0) AS expected_total,
            COALESCE(SUM(paid_amount), 0) AS paid_total,
            COALESCE(SUM(balance_amount), 0) AS balance_total,
            SUM(CASE WHEN status IN ('Pending', 'Partially Paid') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN COALESCE(accountability_status, '') = 'Pending Accountability' THEN 1 ELSE 0 END) AS pending_accountability_count
        FROM tb_arrears_ledger
        WHERE LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
    ")->fetch_assoc() ?: [];

    $workflowRow = $conn->query("
        SELECT
            SUM(CASE WHEN status IN ('pending','assigned','in_progress','deferred','returned') THEN 1 ELSE 0 END) AS open_tasks,
            SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS completed_7d
        FROM tb_tasks
    ")->fetch_assoc() ?: [];

    $workflowAlertRow = $conn->query("
        SELECT
            SUM(CASE WHEN alert_type = 'overdue' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS overdue_tasks,
            SUM(CASE WHEN severity = 'critical' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS critical_alerts
        FROM tb_task_alerts
    ")->fetch_assoc() ?: [];

    $usersRow = $conn->query("
        SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(userRole, ''))) = 'pensioner' THEN 1 ELSE 0 END) AS pensioner_users
        FROM tb_users
    ")->fetch_assoc() ?: [];

    $lifeCertStatusExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
              OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                THEN 'Exempt'
            WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
            ELSE 'Not Submitted'
        END
    ";
    $lifeCertSql = "
        SELECT
            COUNT(*) AS total_records,
            SUM(CASE WHEN {$lifeCertStatusExpr} <> 'Exempt' THEN 1 ELSE 0 END) AS eligible_count,
            SUM(CASE WHEN {$lifeCertStatusExpr} = 'Submitted' THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN {$lifeCertStatusExpr} = 'Not Submitted' THEN 1 ELSE 0 END) AS not_submitted_count,
            SUM(CASE WHEN {$lifeCertStatusExpr} = 'Exempt' THEN 1 ELSE 0 END) AS exempt_count
        FROM tb_fileregistry fr
        LEFT JOIN tb_life_certificate_submissions lcs
          ON lcs.regNo = fr.regNo
         AND lcs.submission_year = ?
        WHERE fr.regNo IS NOT NULL AND TRIM(fr.regNo) <> ''
          AND COALESCE(fr.is_deleted, 0) = 0
    ";
    $lifeCertStmt = $conn->prepare($lifeCertSql);
    if (!$lifeCertStmt) {
        throw new RuntimeException('Unable to prepare general life certificate summary query');
    }
    $lifeCertStmt->bind_param('i', $currentYear);
    $lifeCertStmt->execute();
    $lifeCertRow = $lifeCertStmt->get_result()->fetch_assoc() ?: [];
    $lifeCertStmt->close();

    $latestPayroll = [
        'label' => 'Latest cycle unavailable',
        'on_payroll' => 0,
        'off_payroll' => 0
    ];

    $latestCycleRes = $conn->query("
        SELECT cycle_id, payroll_year, payroll_month
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
        $payrollYear = (int)($latestCycle['payroll_year'] ?? 0);
        $payrollMonth = (int)($latestCycle['payroll_month'] ?? 0);
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        if ($payrollYear > 0 && $payrollMonth >= 1 && $payrollMonth <= 12) {
            $latestPayroll['label'] = $monthNames[$payrollMonth - 1] . '/' . $payrollYear;
            $payrollStmt = $conn->prepare("
                SELECT
                    SUM(CASE WHEN pms.payroll_status = 'On Payroll' THEN 1 ELSE 0 END) AS on_payroll_count,
                    SUM(CASE WHEN pms.payroll_status = 'Not on Payroll' THEN 1 ELSE 0 END) AS off_payroll_count
                FROM tb_registry_payroll_monthly_status pms
                INNER JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
                WHERE pms.payroll_year = ?
                  AND pms.payroll_month = ?
                  AND COALESCE(fr.is_deleted, 0) = 0
                  AND LOWER(TRIM(COALESCE(fr.payType, ''))) = 'pensioner'
            ");
            if ($payrollStmt) {
                $payrollStmt->bind_param('ii', $payrollYear, $payrollMonth);
                $payrollStmt->execute();
                $latestPayrollRow = $payrollStmt->get_result()->fetch_assoc() ?: [];
                $payrollStmt->close();
                $latestPayroll['on_payroll'] = (int)($latestPayrollRow['on_payroll_count'] ?? 0);
                $latestPayroll['off_payroll'] = (int)($latestPayrollRow['off_payroll_count'] ?? 0);
            }
        }
    }

    $totalFiles = (int)($registryRow['total_files'] ?? 0);
    $oneOffFiles = (int)($registryRow['oneoff_files'] ?? 0);
    $pensionerFiles = (int)($registryRow['pensioner_files'] ?? 0);
    $aliveFiles = (int)($registryRow['alive_files'] ?? 0);
    $deceasedFiles = (int)($registryRow['deceased_files'] ?? 0);
    $outOfRegistry = max(0, (int)($movementRow['out_registry_files'] ?? 0));
    $inRegistry = max(0, (int)($movementRow['in_registry_files'] ?? max(0, $totalFiles - $outOfRegistry)));
    $openWorkflow = max(0, (int)($workflowRow['open_tasks'] ?? 0));
    $overdueWorkflow = max(0, (int)($workflowAlertRow['overdue_tasks'] ?? 0));
    $criticalAlerts = max(0, (int)($workflowAlertRow['critical_alerts'] ?? 0));
    $staffDueTotal = max(0, (int)($staffDueRow['total_due'] ?? 0));
    $staffDueSubmitted = max(0, (int)($staffDueRow['submitted_due'] ?? 0));
    $staffDueInProcess = max(0, (int)($staffDueRow['in_process_due'] ?? 0));
    $staffDueCompleted = max(0, (int)($staffDueRow['completed_due'] ?? 0));
    $staffDueInitiated = max(0, (int)($staffDueRow['initiated_due'] ?? 0));
    $staffDueAwaitingVerification = max(0, (int)($staffDueRow['awaiting_verification_due'] ?? 0));
    $staffDueDueSoon = max(0, (int)($staffDueRow['verification_due_soon'] ?? 0));
    $staffDueEscalated = max(0, (int)($staffDueRow['verification_escalated'] ?? 0));
    $claimsEntries = max(0, (int)($claimsRow['entry_count'] ?? 0));
    $claimsExpected = (float)($claimsRow['expected_total'] ?? 0);
    $claimsPaid = (float)($claimsRow['paid_total'] ?? 0);
    $claimsBalance = (float)($claimsRow['balance_total'] ?? 0);
    $claimsOpen = max(0, (int)($claimsRow['open_count'] ?? 0));
    $pendingAccountability = max(0, (int)($claimsRow['pending_accountability_count'] ?? 0));
    $totalUsers = max(0, (int)($usersRow['total_users'] ?? 0));
    $pensionerUsers = max(0, (int)($usersRow['pensioner_users'] ?? 0));
    $staffUsers = max(0, $totalUsers - $pensionerUsers);
    $lifeCertTotal = max(0, (int)($lifeCertRow['total_records'] ?? 0));
    $lifeCertEligible = max(0, (int)($lifeCertRow['eligible_count'] ?? 0));
    $lifeCertSubmitted = max(0, (int)($lifeCertRow['submitted_count'] ?? 0));
    $lifeCertPending = max(0, (int)($lifeCertRow['not_submitted_count'] ?? 0));
    $lifeCertExempt = max(0, (int)($lifeCertRow['exempt_count'] ?? 0));
    $payrollOn = max(0, (int)($latestPayroll['on_payroll'] ?? 0));
    $payrollOff = max(0, (int)($latestPayroll['off_payroll'] ?? 0));

    $percent = static function (float $numerator, float $denominator): string {
        if ($denominator <= 0) {
            return '0.0%';
        }
        return number_format(($numerator / $denominator) * 100, 1) . '%';
    };

    $highlights = [
        'totalFiles' => $totalFiles,
        'staffDue' => $staffDueTotal,
        'staffDueEscalations' => $staffDueEscalated,
        'openWorkflow' => $openWorkflow,
        'claimsBalance' => round($claimsBalance, 2),
        'offPayroll' => $payrollOff,
        'pendingLifeCertificates' => $lifeCertPending
    ];

    $volumes = [
        ['label' => 'Registry Portfolio', 'value' => $totalFiles, 'meta' => $percent($aliveFiles, max(1, $totalFiles)) . ' alive | ' . $percent($deceasedFiles, max(1, $totalFiles)) . ' deceased', 'tone' => 'info'],
        ['label' => 'Staff Due Intake', 'value' => $staffDueTotal, 'meta' => $percent($staffDueSubmitted, max(1, $staffDueTotal)) . ' submitted | ' . number_format($staffDueAwaitingVerification) . ' awaiting verification', 'tone' => 'warning'],
        ['label' => 'Open Workflow Tasks', 'value' => $openWorkflow, 'meta' => number_format($overdueWorkflow) . ' overdue tasks need escalation', 'tone' => $overdueWorkflow > 0 ? 'danger' : 'success'],
        ['label' => 'Claims Ledger Entries', 'value' => $claimsEntries, 'meta' => number_format($claimsOpen) . ' open claims remain unsettled', 'tone' => $claimsOpen > 0 ? 'warning' : 'success'],
        ['label' => 'User Accounts', 'value' => $totalUsers, 'meta' => number_format($staffUsers) . ' staff | ' . number_format($pensionerUsers) . ' pensioner accounts', 'tone' => 'info'],
        ['label' => 'Open File Movements', 'value' => $outOfRegistry, 'meta' => number_format((int)($movementRow['overdue_open'] ?? 0)) . ' out movements are overdue', 'tone' => ((int)($movementRow['overdue_open'] ?? 0)) > 0 ? 'danger' : 'warning']
    ];

    $risks = [
        ['label' => 'Latest Off Payroll Pensioners', 'value' => $payrollOff, 'meta' => 'Latest payroll cut: ' . $latestPayroll['label'], 'tone' => $payrollOff > 0 ? 'danger' : 'success'],
        ['label' => 'Verification Escalations', 'value' => $staffDueEscalated, 'meta' => number_format($staffDueDueSoon) . ' more submissions are approaching the ' . $verificationEscalationDays . '-day initiation limit', 'tone' => $staffDueEscalated > 0 ? 'danger' : ($staffDueDueSoon > 0 ? 'warning' : 'success')],
        ['label' => 'Pending Life Certificates', 'value' => $lifeCertPending, 'meta' => $currentYear . ' compliance outstanding after exemptions are removed', 'tone' => $lifeCertPending > 0 ? 'warning' : 'success'],
        ['label' => 'Workflow Overdue Tasks', 'value' => $overdueWorkflow, 'meta' => number_format($criticalAlerts) . ' critical task alerts are still open', 'tone' => $overdueWorkflow > 0 ? 'danger' : 'success'],
        ['label' => 'Claims Outstanding Balance', 'value' => round($claimsBalance, 2), 'meta' => $pendingAccountability . ' cross-FY accountability submissions are still pending', 'tone' => $claimsBalance > 0 ? 'warning' : 'success', 'format' => 'currency'],
        ['label' => 'Files Out of Registry', 'value' => $outOfRegistry, 'meta' => number_format((int)($movementRow['overdue_open'] ?? 0)) . ' open movements have crossed expected return dates', 'tone' => $outOfRegistry > 0 ? 'warning' : 'success'],
        ['label' => 'One-off File Population', 'value' => $oneOffFiles, 'meta' => 'Separated from recurring pensioner files for storage and custody planning', 'tone' => 'info']
    ];

    $insights = [
        [
            'label' => 'Staff Due Submission Rate',
            'value' => $percent($staffDueSubmitted, max(1, $staffDueTotal)),
            'helper' => number_format(max(0, $staffDueTotal - $staffDueSubmitted)) . ' due records still need submission into workflow.',
            'tone' => $staffDueSubmitted < $staffDueTotal ? 'warning' : 'success'
        ],
        [
            'label' => 'Verification Start Rate',
            'value' => $percent($staffDueInitiated, max(1, $staffDueSubmitted)),
            'helper' => number_format($staffDueEscalated) . ' submitted files have crossed the ' . $verificationEscalationDays . '-day verification-start rule; ' . number_format($staffDueDueSoon) . ' are nearing it.',
            'tone' => $staffDueEscalated > 0 ? 'danger' : ($staffDueDueSoon > 0 ? 'warning' : 'success')
        ],
        [
            'label' => 'Payroll Coverage',
            'value' => $percent($payrollOn, max(1, $payrollOn + $payrollOff)),
            'helper' => $latestPayroll['label'] . ' latest payroll window for pensioner pay-type records.',
            'tone' => $payrollOff > 0 ? 'warning' : 'success'
        ],
        [
            'label' => 'Life Certificate Compliance',
            'value' => $percent($lifeCertSubmitted, max(1, $lifeCertEligible)),
            'helper' => number_format($lifeCertEligible) . ' eligible alive pensioner files are expected this year; ' . number_format($lifeCertExempt) . ' records are exempt.',
            'tone' => $lifeCertPending > 0 ? 'warning' : 'success'
        ],
        [
            'label' => 'Workflow Overdue Rate',
            'value' => $percent($overdueWorkflow, max(1, $openWorkflow)),
            'helper' => number_format((int)($workflowRow['completed_7d'] ?? 0)) . ' tasks completed within the last seven days.',
            'tone' => $overdueWorkflow > 0 ? 'danger' : 'success'
        ],
        [
            'label' => 'Registry Circulation',
            'value' => $percent($outOfRegistry, max(1, $totalFiles)),
            'helper' => number_format($inRegistry) . ' files are currently available in registry.',
            'tone' => $outOfRegistry > 0 ? 'info' : 'success'
        ],
        [
            'label' => 'Claims Settlement Rate',
            'value' => $percent($claimsPaid, max(1, $claimsExpected)),
            'helper' => 'UGX ' . number_format($claimsExpected, 2) . ' expected versus UGX ' . number_format($claimsPaid, 2) . ' already paid.',
            'tone' => $claimsBalance > 0 ? 'warning' : 'success'
        ]
    ];

    $notes = [];
    if ($overdueWorkflow > 0) {
        $notes[] = [
            'title' => 'Escalate workflow bottlenecks',
            'body' => number_format($overdueWorkflow) . ' workflow tasks are overdue. Use the workflow performance section to isolate slow roles and intervene before backlog compounds.',
            'tone' => 'danger'
        ];
    }
    if ($staffDueEscalated > 0) {
        $notes[] = [
            'title' => 'Escalate uninitiated submitted applications',
            'body' => number_format($staffDueEscalated) . ' submitted staff-due applications have not started verification within ' . $verificationEscalationDays . ' days. Review intake ownership and trigger verification immediately.',
            'tone' => 'danger'
        ];
    } elseif ($staffDueDueSoon > 0) {
        $notes[] = [
            'title' => 'Protect the verification-start window',
            'body' => number_format($staffDueDueSoon) . ' submitted staff-due applications are nearing the ' . $verificationEscalationDays . '-day verification-start threshold. Clear these before they escalate.',
            'tone' => 'warning'
        ];
    }
    if ($payrollOff > 0) {
        $notes[] = [
            'title' => 'Prioritize payroll reconciliation',
            'body' => number_format($payrollOff) . ' pensioner files are still off payroll in the latest cycle (' . $latestPayroll['label'] . '). Reconciliation should focus on supplier and registry mismatches first.',
            'tone' => 'warning'
        ];
    }
    if ($lifeCertPending > 0) {
        $notes[] = [
            'title' => 'Sustain annual compliance follow-up',
            'body' => number_format($lifeCertPending) . ' current-year life certificates remain outstanding. Use targeted beneficiary outreach before year-end reporting windows tighten.',
            'tone' => 'warning'
        ];
    }
    if ($claimsBalance > 0) {
        $notes[] = [
            'title' => 'Budget for unresolved arrears',
            'body' => 'Outstanding arrears currently total UGX ' . number_format($claimsBalance, 2) . '. This figure should inform short-term funding and accountability planning.',
            'tone' => 'info'
        ];
    }
    if ($notes === []) {
        $notes[] = [
            'title' => 'Operational posture is stable',
            'body' => 'No critical cross-application warning signals are currently elevated. Keep monitoring the live sections for any new compliance, workflow, or payroll drift.',
            'tone' => 'success'
        ];
    }

    if (function_exists('maybeRecordAnalyticsSnapshot')) {
        try {
            maybeRecordAnalyticsSnapshot($conn, 'general_statistics', [
                'generated_at' => date('c'),
                'highlights' => $highlights,
                'volumes' => $volumes,
                'risks' => $risks,
                'insights' => $insights,
                'notes' => $notes,
                'context' => [
                    'latest_payroll_label' => $latestPayroll['label'],
                    'current_year' => $currentYear,
                    'pensioner_files' => $pensionerFiles,
                    'oneoff_files' => $oneOffFiles
                ]
            ]);
        } catch (Throwable $snapshotError) {
            error_log('analytics snapshot failed: ' . $snapshotError->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'generatedAt' => date('c'),
        'highlights' => $highlights,
        'volumes' => $volumes,
        'risks' => $risks,
        'insights' => $insights,
        'notes' => $notes,
        'context' => [
            'latest_payroll_label' => $latestPayroll['label'],
            'current_year' => $currentYear,
            'pensioner_files' => $pensionerFiles,
            'oneoff_files' => $oneOffFiles
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_dashboard_general_statistics error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load general statistics.']);
}

$conn->close();
?>
