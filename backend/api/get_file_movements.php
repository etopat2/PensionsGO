<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureFileMovementTables($conn);

$canReturnFiles = currentUserHasPermission($conn, 'file_movement.return');

$regNo = trim($_GET['regNo'] ?? '');
if ($regNo === '') {
    echo json_encode(['success' => false, 'message' => 'Reg No is required']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        m.movement_id,
        m.regNo,
        m.from_office,
        m.to_office,
        m.reason,
        m.delivered_by,
        m.received_by,
        m.moved_at,
        m.expected_return_at,
        m.returned_at,
        u.userName AS delivered_by_name
    FROM tb_file_movements m
    LEFT JOIN tb_users u
      ON u.userId = m.delivered_by
    WHERE m.regNo = ?
    ORDER BY m.moved_at DESC, m.movement_id DESC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load movements']);
    exit;
}
$stmt->bind_param("s", $regNo);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$latestOpenMovementId = null;
$currentHolderOffice = resolveCurrentFileHolderOffice($conn, $regNo);
$totalMovements = 0;
$openMovements = 0;
$returnedMovements = 0;
$returnedDurations = [];
$longestOpenSeconds = 0;

while ($row = $result->fetch_assoc()) {
    $totalMovements++;
    $movedAt = $row['moved_at'];
    $returnedAt = $row['returned_at'];
    $durationSeconds = null;
    if ($movedAt) {
        $start = strtotime($movedAt);
        $end = $returnedAt ? strtotime($returnedAt) : time();
        if ($start !== false && $end !== false) {
            $durationSeconds = max(0, $end - $start);
        }
    }

    if ($returnedAt === null) {
        $openMovements++;
        if ($latestOpenMovementId === null) {
            $latestOpenMovementId = (int)$row['movement_id'];
            $holderOffice = trim((string)($row['to_office'] ?? ''));
            if ($holderOffice !== '') {
                $currentHolderOffice = $holderOffice;
            }
        }
        if ($durationSeconds !== null) {
            $longestOpenSeconds = max($longestOpenSeconds, $durationSeconds);
        }
    } else {
        $returnedMovements++;
        if ($durationSeconds !== null) {
            $returnedDurations[] = $durationSeconds;
        }
    }

    $row['duration_seconds'] = $durationSeconds;
    $row['can_return'] = false;
    $deliveredByRaw = trim((string)($row['delivered_by'] ?? ''));
    $deliveredByName = trim((string)($row['delivered_by_name'] ?? ''));
    $toOffice = trim((string)($row['to_office'] ?? ''));
    $reasonText = strtolower(trim((string)($row['reason'] ?? '')));
    $isApproverCustody = strpos($reasonText, 'still with approver') !== false;

    if ($isApproverCustody) {
        $row['delivered_by_display'] = 'Auditor';
        $row['to_office_display'] = 'Approver';
    } else {
        $row['delivered_by_display'] = $deliveredByName !== '' ? $deliveredByName : ($deliveredByRaw !== '' ? $deliveredByRaw : 'N/A');
        $row['to_office_display'] = $toOffice !== '' ? $toOffice : 'N/A';
    }

    $rows[] = $row;
}

$stmt->close();

foreach ($rows as &$movement) {
    $movement['can_return'] = $canReturnFiles
        && $latestOpenMovementId !== null
        && (int)$movement['movement_id'] === $latestOpenMovementId;
}
unset($movement);

$avgTurnaroundSeconds = 0;
if (!empty($returnedDurations)) {
    $avgTurnaroundSeconds = (int)round(array_sum($returnedDurations) / count($returnedDurations));
}

echo json_encode([
    'success' => true,
    'movements' => $rows,
    'summary' => [
        'total_movements' => $totalMovements,
        'open_movements' => $openMovements,
        'returned_movements' => $returnedMovements,
        'avg_turnaround_seconds' => $avgTurnaroundSeconds,
        'longest_open_seconds' => $longestOpenSeconds,
        'current_holder_office' => $currentHolderOffice,
        'can_return' => $canReturnFiles
    ]
]);
$conn->close();
