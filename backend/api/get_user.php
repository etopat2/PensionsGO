<?php
/**
 * 
 * Get User Api
 * 
 * Fetches full details for a given user (used in profile page).
 * Includes userTitle and phoneNo for dynamic display.
 * 
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$userId = $_GET['userId'] ?? '';

if (empty($userId)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            userId,
            userTitle,
            userName,
            userEmail,
            phoneNo,
            userRole,
            userPhoto
        FROM tb_users
        WHERE userId = ?
    ");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $resolvedRole = resolveRoleKeyFromInput($conn, (string)($row['userRole'] ?? ''), false);
        if ($resolvedRole === '') {
            $resolvedRole = 'user';
        }
        echo json_encode([
            'success' => true,
            'user' => [
                'userId' => $row['userId'],
                'userTitle' => $row['userTitle'],
                'userName' => $row['userName'],
                'userEmail' => $row['userEmail'],
                'phoneNo' => $row['phoneNo'],
                'userRole' => $resolvedRole,
                'userPhoto' => $row['userPhoto'] ?: 'images/default-user.png'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching user: ' . $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    $conn->close();
}
?>

