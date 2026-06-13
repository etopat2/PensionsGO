<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = getSessionEffectiveRoleKey($conn);
if (!sessionRoleIn($conn, ['admin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}

$summary = [
    'total_records' => 0,
    'boxed_records' => 0,
    'unboxed_records' => 0,
    'total_boxes' => 0,
    'full_boxes' => 0,
    'mixed_classification_boxes' => 0,
    'mixed_status_boxes' => 0,
    'mixed_pay_type_boxes' => 0,
    'boxes_with_other_status' => 0,
    'issues' => [],
    'boxes' => []
];

$totalRes = $conn->query("SELECT COUNT(*) AS total_records, SUM(CASE WHEN boxNo IS NULL OR TRIM(boxNo) = '' THEN 1 ELSE 0 END) AS unboxed_records FROM tb_fileregistry WHERE COALESCE(is_deleted, 0) = 0");
if ($totalRes && ($row = $totalRes->fetch_assoc())) {
    $summary['total_records'] = (int)($row['total_records'] ?? 0);
    $summary['unboxed_records'] = (int)($row['unboxed_records'] ?? 0);
    $summary['boxed_records'] = max(0, $summary['total_records'] - $summary['unboxed_records']);
    $totalRes->close();
}

$boxStats = [];
$result = $conn->query("
    SELECT
        CAST(boxNo AS UNSIGNED) AS box_num,
        livingStatus,
        payType
    FROM tb_fileregistry
    WHERE boxNo IS NOT NULL
      AND COALESCE(is_deleted, 0) = 0
      AND TRIM(boxNo) <> ''
      AND boxNo REGEXP '^[0-9]+$'
    ORDER BY CAST(boxNo AS UNSIGNED) ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $boxNum = (int)($row['box_num'] ?? 0);
        if ($boxNum <= 0) {
            continue;
        }

        if (!isset($boxStats[$boxNum])) {
            $boxStats[$boxNum] = [
                'alive_count' => 0,
                'deceased_count' => 0,
                'death_count' => 0,
                'pensioner_count' => 0,
                'oneoff_count' => 0,
                'total_count' => 0,
            ];
        }

        $livingStatus = normalizeRegistryLivingStatus((string)($row['livingStatus'] ?? ''));
        if ($livingStatus === 'Deceased') {
            $boxStats[$boxNum]['deceased_count']++;
        } else {
            $boxStats[$boxNum]['alive_count']++;
        }

        $classification = getRegistryBoxAllocationClass(
            (string)($row['livingStatus'] ?? ''),
            $row['payType'] ?? null
        );
        if ($classification === 'Death') {
            $boxStats[$boxNum]['death_count']++;
        } elseif ($classification === 'One-off Payment') {
            $boxStats[$boxNum]['oneoff_count']++;
        } else {
            $boxStats[$boxNum]['pensioner_count']++;
        }

        $boxStats[$boxNum]['total_count']++;
    }
    $result->close();
}

ksort($boxStats);

foreach ($boxStats as $boxNum => $stats) {
    $alive = (int)($stats['alive_count'] ?? 0);
    $deceased = (int)($stats['deceased_count'] ?? 0);
    $death = (int)($stats['death_count'] ?? 0);
    $pensioner = (int)($stats['pensioner_count'] ?? 0);
    $oneoff = (int)($stats['oneoff_count'] ?? 0);
    $total = (int)($stats['total_count'] ?? 0);

    $classLabels = [];
    if ($death > 0) {
        $classLabels[] = 'Death';
    }
    if ($pensioner > 0) {
        $classLabels[] = 'Pensioner';
    }
    if ($oneoff > 0) {
        $classLabels[] = 'One-off Payment';
    }

    $mixedClassification = count($classLabels) > 1;
    if ($mixedClassification) {
        $summary['mixed_classification_boxes']++;
        $summary['issues'][] = "Box {$boxNum} mixes multiple allocation classes: " . implode(', ', $classLabels) . '.';
    }

    if ($total >= 70) {
        $summary['full_boxes']++;
    }

    $summary['boxes'][] = [
        'box_no' => (string)$boxNum,
        'total_count' => $total,
        'alive_count' => $alive,
        'deceased_count' => $deceased,
        'death_count' => $death,
        'pensioner_count' => $pensioner,
        'oneoff_count' => $oneoff,
        'mixed_classification' => $mixedClassification,
        'allocation_class' => count($classLabels) === 1 ? $classLabels[0] : 'Mixed',
        'is_full' => $total >= 70
    ];
}

$summary['total_boxes'] = count($summary['boxes']);
$summary['issues'] = array_values(array_unique($summary['issues']));

echo json_encode([
    'success' => true,
    'summary' => $summary
]);

$conn->close();
