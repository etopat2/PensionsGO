<?php
/**
 * 
 * get_user_logs.php
 * Purpose: Fetch user activity logs for admin dashboard
 * Access: Admin only
 * 
 */

header('Content-Type: application/json');

// Prevent any output before JSON
ob_start();

try {
    require_once __DIR__ . '/../config.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (function_exists('ensureGeoipCacheTable')) {
        ensureGeoipCacheTable($conn);
    }

    $hasGeoTable = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tb_ip_geolocation'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $hasGeoTable = true;
    }
    
    // Verify admin access
    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    // Get pagination parameters with validation
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    // Get filter parameters with sanitization
    $activityType = isset($_GET['activity_type']) ? trim($_GET['activity_type']) : '';
    $userId = isset($_GET['user_id']) ? trim($_GET['user_id']) : '';
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

    // Build query with filters
    $geoSelect = $hasGeoTable ? "
            geo.city AS geo_city,
            geo.region AS geo_region,
            geo.country AS geo_country,
            geo.country_code AS geo_country_code,
            geo.location_label AS geo_location_label,
            UNIX_TIMESTAMP(geo.last_lookup) AS geo_last_lookup
        " : "
            NULL AS geo_city,
            NULL AS geo_region,
            NULL AS geo_country,
            NULL AS geo_country_code,
            NULL AS geo_location_label,
            NULL AS geo_last_lookup
        ";

    $geoJoin = $hasGeoTable
        ? "LEFT JOIN tb_ip_geolocation geo ON geo.ip_address COLLATE utf8mb4_unicode_ci = ul.ip_address COLLATE utf8mb4_unicode_ci"
        : "";

    $query = "
        SELECT 
            ul.log_id,
            ul.user_id,
            ul.user_name,
            ul.user_role,
            ul.activity_type,
            ul.ip_address,
            ul.device_type,
            ul.location,
            ul.session_id,
            ul.login_time,
            ul.logout_time,
            ul.duration_seconds,
            ul.details,
            ul.created_at,
            $geoSelect
        FROM tb_user_logs ul
        $geoJoin
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    // Apply filters
    if (!empty($activityType)) {
        $query .= " AND ul.activity_type = ?";
        $params[] = $activityType;
        $types .= 's';
    }

    if (!empty($userId)) {
        $query .= " AND ul.user_id = ?";
        $params[] = $userId;
        $types .= 's';
    }

    if (!empty($dateFrom)) {
        $query .= " AND DATE(ul.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if (!empty($dateTo)) {
        $query .= " AND DATE(ul.created_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    // Count total records
    $countQuery = "SELECT COUNT(*) as total FROM ($query) as filtered";
    
    if (!empty($params)) {
        $countStmt = $conn->prepare($countQuery);
        if ($countStmt === false) {
            throw new Exception('Failed to prepare count query: ' . $conn->error);
        }
        bindParams($countStmt, $types, $params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRecords = $countResult->fetch_assoc()['total'];
        $countStmt->close();
    } else {
        $countResult = $conn->query($countQuery);
        if ($countResult === false) {
            throw new Exception('Failed to execute count query: ' . $conn->error);
        }
        $totalRecords = $countResult->fetch_assoc()['total'];
    }

    // Get paginated results
    $query .= " ORDER BY ul.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception('Failed to prepare query: ' . $conn->error);
    }

    if (!empty($params)) {
        bindParams($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to execute query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $logs = [];

    $updateStmt = $conn->prepare("
        UPDATE tb_user_logs
        SET location = ?
        WHERE log_id = ?
    ");
    $updateLocation = '';
    $updateLogId = 0;
    if ($updateStmt) {
        $updateStmt->bind_param("si", $updateLocation, $updateLogId);
    }

    $unknownLocations = [
        '',
        'Unknown',
        'Unknown Location',
        'Not recorded',
        'N/A'
    ];

    while ($row = $result->fetch_assoc()) {
        $durationSeconds = $row['duration_seconds'];
        if ($durationSeconds === null || $durationSeconds === '' || (int)$durationSeconds < 0) {
            if (!empty($row['login_time']) && !empty($row['logout_time'])) {
                $calculated = strtotime($row['logout_time']) - strtotime($row['login_time']);
                $durationSeconds = max(0, $calculated);
            } else {
                $durationSeconds = null;
            }
        }

        $rawLocation = trim((string)($row['location'] ?? ''));
        $locationLabel = $rawLocation;
        if (in_array($rawLocation, $unknownLocations, true) && !empty($row['geo_location_label'])) {
            $locationLabel = $row['geo_location_label'];
            if ($updateStmt && !empty($locationLabel)) {
                $updateLocation = $locationLabel;
                $updateLogId = (int)$row['log_id'];
                $updateStmt->execute();
            }
        }
        if (empty($locationLabel)) {
            $locationLabel = 'Unknown Location';
        }

        $locationCity = $row['geo_city'] ?? '';
        $locationRegion = $row['geo_region'] ?? '';
        $locationCountry = $row['geo_country'] ?? '';

        $specialLocations = [
            'Local Development Environment',
            'Private Network',
            'Geolocation disabled',
            'Unknown Location'
        ];

        if (empty($locationCity) && !in_array($locationLabel, $specialLocations, true)) {
            $parts = array_map('trim', explode(',', $locationLabel));
            $locationCity = $parts[0] ?? '';
            if (empty($locationRegion)) {
                $locationRegion = $parts[1] ?? '';
            }
            if (empty($locationCountry)) {
                $locationCountry = $parts[2] ?? '';
            }
        }

        $locationDetailParts = array_filter([$locationRegion, $locationCountry]);
        $locationDetail = !empty($locationDetailParts) ? implode(', ', $locationDetailParts) : '';

        if (in_array($locationLabel, $specialLocations, true)) {
            $locationCity = $locationLabel;
            $locationDetail = '';
        }

        $displayUserName = $row['user_name'];
        $displayUserRole = $row['user_role'];

        if ($row['activity_type'] === 'session_cleanup') {
            $displayUserName = 'System';
            $displayUserRole = 'admin';
        }

        $detailsText = $row['details'];
        if ($row['activity_type'] === 'session_cleanup') {
            if (is_string($detailsText) && strlen($detailsText) > 0) {
                $trimmed = ltrim($detailsText);
                if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                    $detailsText = 'Cleanup on logout';
                }
            } else {
                $detailsText = 'Cleanup on logout';
            }
        }

        $logs[] = [
            'log_id' => $row['log_id'],
            'user_id' => $row['user_id'],
            'user_name' => $displayUserName,
            'user_role' => $displayUserRole,
            'activity_type' => $row['activity_type'],
            'ip_address' => $row['ip_address'],
            'device_type' => $row['device_type'],
            'location' => $locationLabel,
            'location_city' => $locationCity,
            'location_region' => $locationRegion,
            'location_country' => $locationCountry,
            'location_detail' => $locationDetail,
            'session_id' => $row['session_id'],
            'login_time' => $row['login_time'],
            'logout_time' => $row['logout_time'],
            'duration_seconds' => $durationSeconds,
            'duration_formatted' => formatDuration($durationSeconds),
            'details' => $detailsText,
            'created_at' => $row['created_at'],
            'created_date' => date('M j, Y g:i A', strtotime($row['created_at']))
        ];
    }

    $stmt->close();
    if ($updateStmt) {
        $updateStmt->close();
    }

    // Clear any unexpected output
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => ceil($totalRecords / $limit)
        ],
        'filters' => [
            'activity_type' => $activityType,
            'user_id' => $userId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]
    ]);

} catch (Throwable $e) {
    // Clear any output and send error
    ob_clean();
    error_log("Error fetching user logs: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching user logs: ' . $e->getMessage()
    ]);
} finally {
    // Ensure connection is closed
    if (isset($conn)) {
        $conn->close();
    }
    // End output buffering
    ob_end_flush();
}

// Helper function to format duration
function formatDuration($seconds) {
    if ($seconds === null || $seconds === '') {
        return 'N/A';
    }
    
    $seconds = (int)$seconds;
    if ($seconds < 60) {
        return "$seconds seconds";
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        return "$minutes minutes";
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "$hours hours, $minutes minutes";
    }
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
    if (empty($params)) {
        return;
    }

    $bind = [];
    $bind[] = $types;

    foreach ($params as $key => $value) {
        if (isset($types[$key]) && $types[$key] === 'i') {
            $params[$key] = (int)$value;
        }
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}
?>

