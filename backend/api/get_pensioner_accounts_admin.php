<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = getSessionEffectiveRoleKey($conn);
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$sql = "
    SELECT userId, userName, userEmail, phoneNo, userRole
    FROM tb_users
    WHERE LOWER(COALESCE(userRole, '')) = 'pensioner'
";

$types = '';
$like = '';
if ($query !== '') {
    $sql .= " AND (userName LIKE ? OR userEmail LIKE ? OR phoneNo LIKE ?)";
    $like = '%' . $query . '%';
    $types = 'sss';
}
$sql .= " ORDER BY userName ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare pensioner query']);
    exit;
}

if ($types !== '') {
    $stmt->bind_param($types, $like, $like, $like);
}

$stmt->execute();
$result = $stmt->get_result();
$accounts = [];
while ($row = $result->fetch_assoc()) {
    $accounts[] = [
        'userId' => (string)($row['userId'] ?? ''),
        'userName' => (string)($row['userName'] ?? ''),
        'userEmail' => (string)($row['userEmail'] ?? ''),
        'phoneNo' => (string)($row['phoneNo'] ?? ''),
        'userRole' => strtolower((string)($row['userRole'] ?? 'pensioner')),
        'roleLabel' => getRoleLabel($conn, 'pensioner')
    ];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'accounts' => $accounts
]);

$conn->close();
?>
