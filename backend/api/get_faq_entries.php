<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ensureFaqTables($conn);

$isAdmin = false;
if (isset($_SESSION['userId']) && sessionRoleIn($conn, ['admin'])) {
    $isAdmin = true;
}

$activeOnly = true;
if (isset($_GET['active_only'])) {
    $activeOnly = ((int)$_GET['active_only'] === 1);
}

if (!$isAdmin && !$activeOnly) {
    $activeOnly = true;
}

$sql = "
    SELECT faq_id, question, answer, bullets, category, audience_label, is_featured, sort_order, is_active, updated_at
    FROM tb_faq_entries
";
if ($activeOnly) {
    $sql .= " WHERE is_active = 1 ";
}
$sql .= " ORDER BY sort_order ASC, updated_at DESC, faq_id ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$entries = [];

while ($row = $result->fetch_assoc()) {
    $bullets = [];
    if (isset($row['bullets']) && trim((string)$row['bullets']) !== '') {
        $parts = preg_split('/\r\n|\r|\n/', (string)$row['bullets']);
        if ($parts) {
            foreach ($parts as $part) {
                $trimmed = trim((string)$part);
                if ($trimmed !== '') {
                    $bullets[] = $trimmed;
                }
            }
        }
    }

    $audienceLabel = trim((string)($row['audience_label'] ?? ''));
    if ($audienceLabel === '') {
        $audienceLabel = 'Public guidance';
    }

    $entries[] = [
        'faq_id' => (int)$row['faq_id'],
        'question' => (string)$row['question'],
        'answer' => (string)$row['answer'],
        'bullets' => $bullets,
        'category' => (string)$row['category'],
        'audience_label' => $audienceLabel,
        'is_featured' => (int)$row['is_featured'] === 1,
        'sort_order' => (int)$row['sort_order'],
        'is_active' => (int)$row['is_active'] === 1,
        'updated_at' => (string)($row['updated_at'] ?? '')
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'entries' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
