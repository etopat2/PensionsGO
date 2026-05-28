<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

$summary = [
    'total' => ['total' => 0, 'male' => 0, 'female' => 0],
    'alive' => ['total' => 0, 'male' => 0, 'female' => 0],
    'deceased' => ['total' => 0, 'male' => 0, 'female' => 0]
];
$modes = [];

try {
    $summarySql = "
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN LOWER(COALESCE(gender, '')) = 'male' THEN 1 ELSE 0 END) AS male_count,
            SUM(CASE WHEN LOWER(COALESCE(gender, '')) = 'female' THEN 1 ELSE 0 END) AS female_count,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'alive' THEN 1 ELSE 0 END) AS alive_total,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'alive' AND LOWER(COALESCE(gender, '')) = 'male' THEN 1 ELSE 0 END) AS alive_male,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'alive' AND LOWER(COALESCE(gender, '')) = 'female' THEN 1 ELSE 0 END) AS alive_female,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'deceased' THEN 1 ELSE 0 END) AS deceased_total,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'deceased' AND LOWER(COALESCE(gender, '')) = 'male' THEN 1 ELSE 0 END) AS deceased_male,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'deceased' AND LOWER(COALESCE(gender, '')) = 'female' THEN 1 ELSE 0 END) AS deceased_female
        FROM tb_fileregistry
    ";

    $summaryRes = $conn->query($summarySql);
    if ($summaryRes) {
        $row = $summaryRes->fetch_assoc() ?: [];
        $summary = [
            'total' => [
                'total' => (int)($row['total_count'] ?? 0),
                'male' => (int)($row['male_count'] ?? 0),
                'female' => (int)($row['female_count'] ?? 0)
            ],
            'alive' => [
                'total' => (int)($row['alive_total'] ?? 0),
                'male' => (int)($row['alive_male'] ?? 0),
                'female' => (int)($row['alive_female'] ?? 0)
            ],
            'deceased' => [
                'total' => (int)($row['deceased_total'] ?? 0),
                'male' => (int)($row['deceased_male'] ?? 0),
                'female' => (int)($row['deceased_female'] ?? 0)
            ]
        ];
        $summaryRes->free();
    }

    $modeSql = "
        SELECT
            COALESCE(NULLIF(TRIM(retirementType), ''), 'Unspecified') AS retirement_type,
            COUNT(*) AS total_count,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'alive' THEN 1 ELSE 0 END) AS alive_count,
            SUM(CASE WHEN LOWER(COALESCE(livingStatus, '')) = 'deceased' THEN 1 ELSE 0 END) AS deceased_count
        FROM tb_fileregistry
        GROUP BY COALESCE(NULLIF(TRIM(retirementType), ''), 'Unspecified')
        ORDER BY total_count DESC, retirement_type ASC
    ";

    $modeRes = $conn->query($modeSql);
    if ($modeRes) {
        $normalizedModes = [];
        while ($row = $modeRes->fetch_assoc()) {
            $rawType = (string)($row['retirement_type'] ?? 'Unspecified');
            $normalizedType = strcasecmp($rawType, 'Unspecified') === 0 ? '' : normalizeBenefitsRetirementTypeKey($rawType);
            $groupKey = $normalizedType !== '' ? $normalizedType : 'Unspecified';
            if (!isset($normalizedModes[$groupKey])) {
                $normalizedModes[$groupKey] = [
                    'name' => $normalizedType !== '' ? getBenefitsRetirementTypeLabel($normalizedType) : 'Unspecified',
                    'total' => 0,
                    'alive' => 0,
                    'deceased' => 0
                ];
            }
            $normalizedModes[$groupKey]['total'] += (int)($row['total_count'] ?? 0);
            $normalizedModes[$groupKey]['alive'] += (int)($row['alive_count'] ?? 0);
            $normalizedModes[$groupKey]['deceased'] += (int)($row['deceased_count'] ?? 0);
        }
        uasort($normalizedModes, static function (array $left, array $right): int {
            $countComparison = ($right['total'] ?? 0) <=> ($left['total'] ?? 0);
            if ($countComparison !== 0) {
                return $countComparison;
            }
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });
        $modes = array_values($normalizedModes);
        $modeRes->free();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Pensioners summary loaded successfully.',
        'summary' => $summary,
        'modes' => $modes
    ]);
} catch (Throwable $e) {
    error_log('get_pensioners_summary.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load pensioners summary.'
    ]);
}
