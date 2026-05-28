<?php
/**
 * 
 * Dashboard Data Api
 * 
 * Purpose: * Returns summarized dashboard statistics for the PensionsGo system.
 * 
 * Structure:
 * - Claims Summary
 * - Pensioners Summary
 * - Modes of Retirement
 * - Life Certificate Summary
 * - Payroll Movements
 * - Staff Due for Retirement
 * - File Registry
 * - System Users
 * 
 * Notes:
 * - Integrates with `config.php` for database access.
 * - Designed to be consumed by `dashboard.js` via fetch().
 * 
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

// Utility function for clean responses
function respond($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

try {
    /* 1. CLAIMS SUMMARY (Counts by Type) */
    $claims = [
        ['type' => 'Pension', 'count' => 0],
        ['type' => 'Gratuity', 'count' => 0],
        ['type' => 'Arrears', 'count' => 0],
        ['type' => 'Full Pension', 'count' => 0],
        ['type' => 'Underpayment', 'count' => 0]
    ];

    $claimQuery = "
        SELECT claimType, COUNT(*) AS total
        FROM tb_claims
        GROUP BY claimType
    ";

    if ($res = $conn->query($claimQuery)) {
        while ($row = $res->fetch_assoc()) {
            foreach ($claims as &$c) {
                if (strcasecmp($c['type'], $row['claimType']) == 0) {
                    $c['count'] = (int)$row['total'];
                }
            }
        }
        $res->free();
    }

    /* 2. PENSIONERS SUMMARY (Alive / Deceased / Total / Gender) */
    $pensioners = [
        'total' => ['total' => 0, 'male' => 0, 'female' => 0],
        'alive' => ['total' => 0, 'male' => 0, 'female' => 0],
        'deceased' => ['total' => 0, 'male' => 0, 'female' => 0]
    ];

    $pensionerQuery = "
        SELECT livingStatus, gender, COUNT(*) AS total
        FROM tb_pensioners
        GROUP BY livingStatus, gender
    ";

    if ($res = $conn->query($pensionerQuery)) {
        while ($row = $res->fetch_assoc()) {
            $status = strtolower($row['livingStatus']);
            $gender = strtolower($row['gender']);

            $pensioners['total']['total'] += (int)$row['total'];
            if ($gender === 'male') $pensioners['total']['male'] += $row['total'];
            else $pensioners['total']['female'] += $row['total'];

            if (isset($pensioners[$status])) {
                $pensioners[$status]['total'] += $row['total'];
                if ($gender === 'male') $pensioners[$status]['male'] += $row['total'];
                else $pensioners[$status]['female'] += $row['total'];
            }
        }
        $res->free();
    }

    /* 3. MODE OF RETIREMENT SUMMARY */
    $modes = [];
    $modeQuery = "
        SELECT retirementType,
               SUM(CASE WHEN livingStatus='Alive' THEN 1 ELSE 0 END) AS alive,
               SUM(CASE WHEN livingStatus='Deceased' THEN 1 ELSE 0 END) AS deceased,
               COUNT(*) AS total
        FROM tb_pensioners
        GROUP BY retirementType
    ";

    if ($res = $conn->query($modeQuery)) {
        while ($row = $res->fetch_assoc()) {
            $modes[] = [
                'name' => $row['retirementType'],
                'total' => (int)$row['total'],
                'alive' => (int)$row['alive'],
                'deceased' => (int)$row['deceased']
            ];
        }
        $res->free();
    }

    /* 4. LIFE CERTIFICATES SUMMARY (Current Year) */
    $year = date('Y');
    $lifeCert = ['submitted' => 0, 'notSubmitted' => 0];

    $certQuery = "
        SELECT
          SUM(CASE WHEN submittedLifeCertYear = $year THEN 1 ELSE 0 END) AS submitted,
          SUM(CASE WHEN submittedLifeCertYear IS NULL OR submittedLifeCertYear != $year THEN 1 ELSE 0 END) AS notSubmitted
        FROM tb_pensioners
    ";

    if ($res = $conn->query($certQuery)) {
        $lifeCert = $res->fetch_assoc();
        $res->free();
    }

    /* 5. PAYROLL MOVEMENTS (Current Month) */
    $month = date('m');
    $payroll = ['new' => 0, 'removed' => 0];

    $payrollQuery = "
        SELECT
          SUM(CASE WHEN action='Added' THEN 1 ELSE 0 END) AS newCount,
          SUM(CASE WHEN action='Removed' THEN 1 ELSE 0 END) AS removedCount
        FROM tb_payroll_movements
        WHERE MONTH(actionDate) = $month AND YEAR(actionDate) = $year
    ";

    if ($res = $conn->query($payrollQuery)) {
        $row = $res->fetch_assoc();
        $payroll['new'] = (int)$row['newCount'];
        $payroll['removed'] = (int)$row['removedCount'];
        $res->free();
    }

    /* 6. STAFF DUE FOR RETIREMENT */
    $staffDue = ['total' => 0, 'Male' => 0, 'Female' => 0, 'submitted' => 0, 'notSubmitted' => 0];

    $staffQuery = "
        SELECT
            gender,
            SUM(1) AS total,
            SUM(CASE WHEN submissionStatus='submitted' THEN 1 ELSE 0 END) AS submitted,
            SUM(CASE WHEN submissionStatus='pending' THEN 1 ELSE 0 END) AS notSubmitted
        FROM tb_staffdue
        GROUP BY gender
    ";

    if ($res = $conn->query($staffQuery)) {
        while ($row = $res->fetch_assoc()) {
            $staffDue['total'] += $row['total'];
            $staffDue['submitted'] += $row['submitted'];
            $staffDue['notSubmitted'] += $row['notSubmitted'];
            if (strtolower($row['gender']) === 'Male') $staffDue['Male'] += $row['total'];
            else $staffDue['Female'] += $row['total'];
        }
        $res->free();
    }

    /* 7. FILE REGISTRY SUMMARY */
    $files = ['inRegistry' => 0, 'outRegistry' => 0];
    $fileQuery = "
        SELECT
          SUM(CASE WHEN fileStatus='in' THEN 1 ELSE 0 END) AS inRegistry,
          SUM(CASE WHEN fileStatus='out' THEN 1 ELSE 0 END) AS outRegistry
        FROM tb_files_registry
    ";

    if ($res = $conn->query($fileQuery)) {
        $files = $res->fetch_assoc();
        $res->free();
    }

    /* 8. SYSTEM USERS SUMMARY */
    $users = [];
    $usersQuery = "
        SELECT userRole, COUNT(*) AS total
        FROM tb_users
        GROUP BY userRole
    ";

    if ($res = $conn->query($usersQuery)) {
        while ($row = $res->fetch_assoc()) {
            $users[] = [
                'role' => ucfirst($row['userRole']),
                'count' => (int)$row['total']
            ];
        }
        $res->free();
    }

    /* Success Response*/
    $summary = [
        'claims' => $claims,
        'pensioners' => $pensioners,
        'modes' => $modes,
        'lifeCert' => $lifeCert,
        'payroll' => $payroll,
        'staffDue' => $staffDue,
        'files' => $files,
        'users' => $users
    ];

    respond(true, 'Dashboard data retrieved successfully.', $summary);
} catch (Exception $e) {
    respond(false, 'Server error: ' . $e->getMessage());
}

$conn->close();

