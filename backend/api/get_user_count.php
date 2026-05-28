<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Verify admin access
if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) as user_count FROM tb_users WHERE status = 'active'");
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['user_count'];
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'count' => (int)$count
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching user count: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching user count'
    ]);
}

$conn->close();
?>
