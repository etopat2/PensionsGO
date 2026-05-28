<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    $query = normalizePoliticalDistrictName((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit <= 0) {
        $limit = 200;
    }
    $limit = min($limit, 500);

    $districts = getPoliticalDistricts($conn);
    if ($query !== '') {
        $queryLower = strtolower($query);
        $districts = array_values(array_filter($districts, static function (array $row) use ($queryLower): bool {
            $district = strtolower((string)($row['district'] ?? ''));
            $region = strtolower((string)($row['region'] ?? ''));
            return str_contains($district, $queryLower) || str_contains($region, $queryLower);
        }));
    }

    if ($limit > 0 && count($districts) > $limit) {
        $districts = array_slice($districts, 0, $limit);
    }

    echo json_encode([
        'success' => true,
        'districts' => $districts
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load districts.'
    ]);
}
