<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Public tracker support:
// The index page exposes application tracking for non-authenticated users.
// Keep this endpoint available without requiring an active login session.
$isAuthenticated = isset($_SESSION['userId']);

ensureAppnStatusTrackingColumns($conn);

$query = trim($_GET['q'] ?? '');
if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Search term is required']);
    exit;
}

$like = '%' . $query . '%';
$stmt = $conn->prepare("
    SELECT s.id, s.regNo, s.sName, s.fName, s.telNo, s.retirementType, s.submissionStatus, s.appnStatus,
           a.verification, a.writeUp, a.fileCreation, a.entrantAllocation, a.dataCapture, a.assessment, a.audit, a.approval,
           a.verification_at, a.writeUp_at, a.fileCreation_at, a.entrantAllocation_at, a.dataCapture_at, a.assessment_at, a.audit_at, a.approval_at,
           a.verification_comment, a.writeUp_comment, a.fileCreation_comment, a.entrantAllocation_comment, a.dataCapture_comment, a.assessment_comment, a.audit_comment, a.approval_comment
    FROM tb_staffdue s
    LEFT JOIN tb_appnstatus a ON a.regNo = s.regNo
    WHERE s.regNo LIKE ? OR s.sName LIKE ? OR s.fName LIKE ? OR s.telNo LIKE ?
    ORDER BY s.id DESC
    LIMIT 10
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}
$stmt->bind_param("ssss", $like, $like, $like, $like);
$stmt->execute();
$result = $stmt->get_result();
$records = [];

while ($row = $result->fetch_assoc()) {
    $records[] = [
        'id' => $row['id'],
        'regNo' => $row['regNo'],
        'name' => trim(($row['sName'] ?? '') . ' ' . ($row['fName'] ?? '')),
        'telNo' => $row['telNo'],
        'retirementType' => getBenefitsRetirementTypeLabel((string)($row['retirementType'] ?? '')),
        'submissionStatus' => $row['submissionStatus'],
        'appnStatus' => $row['appnStatus'],
        'steps' => [
            [
                'label' => 'Verification',
                'status' => $row['verification'],
                'time' => $row['verification_at'],
                'comment' => $row['verification_comment']
            ],
            [
                'label' => 'Write Up',
                'status' => $row['writeUp'],
                'time' => $row['writeUp_at'],
                'comment' => $row['writeUp_comment']
            ],
            [
                'label' => 'File Creation',
                'status' => $row['fileCreation'],
                'time' => $row['fileCreation_at'],
                'comment' => $row['fileCreation_comment']
            ],
            [
                'label' => 'Data Allocation',
                'status' => $row['entrantAllocation'],
                'time' => $row['entrantAllocation_at'],
                'comment' => $row['entrantAllocation_comment']
            ],
            [
                'label' => 'Data Capture',
                'status' => $row['dataCapture'],
                'time' => $row['dataCapture_at'],
                'comment' => $row['dataCapture_comment']
            ],
            [
                'label' => 'Assessment',
                'status' => $row['assessment'],
                'time' => $row['assessment_at'],
                'comment' => $row['assessment_comment']
            ],
            [
                'label' => 'Audit',
                'status' => $row['audit'],
                'time' => $row['audit_at'],
                'comment' => $row['audit_comment']
            ],
            [
                'label' => 'Approval',
                'status' => $row['approval'],
                'time' => $row['approval_at'],
                'comment' => $row['approval_comment']
            ]
        ]
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'records' => $records]);
$conn->close();
