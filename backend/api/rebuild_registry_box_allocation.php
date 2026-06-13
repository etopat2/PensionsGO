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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['confirm'])) {
    echo json_encode(['success' => false, 'message' => 'Confirmation is required']);
    exit;
}
requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before rebuilding registry box allocation.');

try {
    $result = rebalanceRegistryBoxNumbers($conn);
    if (function_exists('clearRegistryBoxNumberOptionsCache')) {
        clearRegistryBoxNumberOptionsCache();
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => (string)($_SESSION['userId'] ?? ''),
            'actor_name' => (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown'),
            'actor_role' => $role,
            'action' => 'registry_box_rebalanced',
            'entity_type' => 'tb_fileregistry',
            'entity_id' => 'box_allocation',
            'details' => [
                'updated_records' => (int)($result['updated'] ?? 0),
                'box_count' => (int)($result['boxes'] ?? 0)
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Registry box allocation rebuilt successfully.',
        'result' => $result
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
