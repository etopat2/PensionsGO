<?php
/**
 * budget_export_preset.php
 * Stores/retrieves per-user budget export builder presets.
 */

header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../config.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $userId = (string)($_SESSION['userId'] ?? '');
    $settingKey = 'budget_export_preset';

    if ($method === 'GET') {
        $raw = getUserSetting($conn, $userId, $settingKey);
        $preset = null;
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $preset = $decoded;
            }
        }
        echo json_encode(['success' => true, 'preset' => $preset], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $filtersRaw = $input['filters'] ?? $input['preset'] ?? null;
        if (!is_array($filtersRaw)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid preset payload.']);
            exit;
        }

        $allowedKeys = [
            'financial_year',
            'pensioner',
            'claim_types',
            'statuses',
            'source_types',
            'min_total',
            'max_total',
            'sort',
            'include_zero'
        ];
        $filters = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $filtersRaw)) {
                continue;
            }
            $value = $filtersRaw[$key];
            if (is_array($value)) {
                $filters[$key] = array_values(array_map('strval', $value));
            } elseif ($value !== null) {
                $filters[$key] = (string)$value;
            }
        }

        $payload = [
            'savedAt' => date('c'),
            'filters' => $filters
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 10000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Preset payload is too large.']);
            exit;
        }

        $saved = setUserSetting($conn, $userId, $settingKey, $encoded);
        if (!$saved) {
            throw new RuntimeException('Unable to save preset.');
        }

        echo json_encode(['success' => true, 'preset' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $ok = deleteUserSetting($conn, $userId, $settingKey);
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Preset cleared.' : 'Unable to clear preset.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
