<?php
/**
 * enrich_user_logs.php
 * Purpose: Enrich historical user logs with geolocation data
 * Access: Admin only
 */

header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../config.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 200);
    $limit = max(1, min(500, $limit));

    $unknownValues = [
        'Unknown',
        'Unknown Location',
        'Not recorded',
        'N/A',
        'Geolocation disabled'
    ];

    $placeholders = implode(',', array_fill(0, count($unknownValues), '?'));
    $query = "
        SELECT DISTINCT ip_address
        FROM tb_user_logs
        WHERE ip_address IS NOT NULL
          AND ip_address != ''
          AND (
                location IS NULL
                OR location = ''
                OR location IN ($placeholders)
          )
        LIMIT ?
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare IP query: ' . $conn->error);
    }

    $unknown1 = $unknownValues[0];
    $unknown2 = $unknownValues[1];
    $unknown3 = $unknownValues[2];
    $unknown4 = $unknownValues[3];
    $unknown5 = $unknownValues[4];
    $limitParam = $limit;

    $stmt->bind_param(
        "sssss" . "i",
        $unknown1,
        $unknown2,
        $unknown3,
        $unknown4,
        $unknown5,
        $limitParam
    );
    $stmt->execute();
    $result = $stmt->get_result();

    $updateQuery = "
        UPDATE tb_user_logs
        SET location = ?
        WHERE ip_address = ?
          AND (
                location IS NULL
                OR location = ''
                OR location IN ($placeholders)
          )
    ";
    $updateStmt = $conn->prepare($updateQuery);
    if (!$updateStmt) {
        $stmt->close();
        throw new Exception('Failed to prepare update query: ' . $conn->error);
    }

    $processedIps = 0;
    $updatedRows = 0;
    $skippedIps = 0;

    $updateLocation = '';
    $updateIp = '';
    $updateStmt->bind_param(
        "sssssss",
        $updateLocation,
        $updateIp,
        $unknown1,
        $unknown2,
        $unknown3,
        $unknown4,
        $unknown5
    );

    while ($row = $result->fetch_assoc()) {
        $ip = $row['ip_address'];
        $processedIps++;

        $locationLabel = getLocationFromIP($ip);
        if (empty($locationLabel) || $locationLabel === 'Unknown Location' || $locationLabel === 'Geolocation disabled') {
            $skippedIps++;
            continue;
        }

        $updateLocation = $locationLabel;
        $updateIp = $ip;
        $updateStmt->execute();
        $updatedRows += $updateStmt->affected_rows;
    }

    $stmt->close();
    $updateStmt->close();

    ob_clean();
    echo json_encode([
        'success' => true,
        'processed_ips' => $processedIps,
        'updated_rows' => $updatedRows,
        'skipped_ips' => $skippedIps,
        'geolocation_enabled' => isGeoipEnabled()
    ]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
}
?>
