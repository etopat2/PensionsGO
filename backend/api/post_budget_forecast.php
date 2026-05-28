<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'budget.manage')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureArrearsAndBudgetTables($conn);

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '[]', true);
if (!is_array($payload)) {
    $payload = [];
}

$fyInput = trim((string)($payload['financialYear'] ?? ''));
$startYear = 0;
if (preg_match('/FY\s*(\d{4})/i', $fyInput, $m)) {
    $startYear = (int)$m[1];
} elseif (preg_match('/^(\d{4})$/', $fyInput, $m)) {
    $startYear = (int)$m[1];
}
if ($startYear < 2000 || $startYear > 2200) {
    echo json_encode(['success' => false, 'message' => 'Invalid financial year']);
    exit;
}

function budgetAmount(array $payload, string $key): float {
    return round(max((float)($payload[$key] ?? 0), 0), 2);
}

$estimatedPensionAmount = budgetAmount($payload, 'estimatedPensionAmount');
$estimatedGratuityAmount = budgetAmount($payload, 'estimatedGratuityAmount');
$estimatedPensionArrears = budgetAmount($payload, 'estimatedPensionArrears');
$estimatedFullPensionArrears = budgetAmount($payload, 'estimatedFullPensionArrears');
$estimatedGratuityArrears = budgetAmount($payload, 'estimatedGratuityArrears');
$estimatedUnderpaymentClaims = budgetAmount($payload, 'estimatedUnderpaymentClaims');
$estimatedSuspensionArrears = budgetAmount($payload, 'estimatedSuspensionArrears');
$notes = trim((string)($payload['notes'] ?? ''));
$createdBy = (string)($_SESSION['userId'] ?? '');

try {
    $stmt = $conn->prepare("
        INSERT INTO tb_budgetforecast
        (
            financialYear,
            estimatedPensionAmount,
            estimatedGratuityAmount,
            estimatedPensionArrears,
            estimatedFullPensionArrears,
            estimatedGratuityArrears,
            estimatedUnderpaymentClaims,
            estimatedSuspensionArrears,
            notes,
            createdBy
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare budget forecast insert');
    }
    $stmt->bind_param(
        "idddddddss",
        $startYear,
        $estimatedPensionAmount,
        $estimatedGratuityAmount,
        $estimatedPensionArrears,
        $estimatedFullPensionArrears,
        $estimatedGratuityArrears,
        $estimatedUnderpaymentClaims,
        $estimatedSuspensionArrears,
        $notes,
        $createdBy
    );
    $stmt->execute();
    $insertId = (int)$stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Budget forecast saved successfully.',
        'forecastId' => $insertId,
        'financialYear' => "FY {$startYear}/" . ($startYear + 1)
    ]);
} catch (Throwable $e) {
    error_log('post_budget_forecast error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to save budget forecast']);
}

$conn->close();
?>
