<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $runtime = getNotificationDigestRuntime($conn);
        echo json_encode([
            'success' => true,
            'runtime' => $runtime
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method'
        ]);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    $action = trim((string)($payload['action'] ?? 'queue_now'));

    if ($action === 'preview') {
        ensureNotificationDigestRunsTable($conn);
        $preview = buildAdminDailyDigest($conn);
        $recipient = resolveNotificationDigestRecipient($conn);
        $stmt = $conn->prepare("
            INSERT INTO tb_notification_digest_runs (
                digest_date, run_type, recipient, subject, status, summary_json, notes,
                created_by, created_by_name, created_by_role
            ) VALUES (CURDATE(), 'preview', ?, ?, 'previewed', ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $summaryJson = json_encode($preview['summary'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $notes = 'Digest preview requested from Notification Settings.';
            $createdBy = $_SESSION['userId'] ?? null;
            $createdByName = $_SESSION['userName'] ?? null;
            $createdByRole = $_SESSION['userRole'] ?? null;
            $subject = $preview['subject'] ?? 'UPS PensionsGo - Daily Operations Digest';
            $stmt->bind_param('sssssss', $recipient, $subject, $summaryJson, $notes, $createdBy, $createdByName, $createdByRole);
            $stmt->execute();
            $preview['digest_id'] = (int)$stmt->insert_id;
            $stmt->close();
        }
        echo json_encode([
            'success' => true,
            'message' => 'Digest preview generated.',
            'runtime' => getNotificationDigestRuntime($conn),
            'digest' => $preview
        ]);
        exit;
    }

    $result = queueAdminDailyDigest($conn, [
        'run_type' => 'manual',
        'notes' => 'Digest queued manually from Notification Settings.',
        'created_by' => $_SESSION['userId'] ?? null,
        'created_by_name' => $_SESSION['userName'] ?? null,
        'created_by_role' => $_SESSION['userRole'] ?? null
    ]);

    echo json_encode([
        'success' => ($result['status'] ?? '') !== 'failed',
        'message' => $result['message'] ?? 'Daily digest processed.',
        'runtime' => getNotificationDigestRuntime($conn),
        'digest' => $result
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process daily digest.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
