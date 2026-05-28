<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (normalizeRoleKey((string)($_SESSION['userRole'] ?? '')) !== 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensurePensionerLookupColumns($conn);
ensureFileMovementTables($conn);

$lookupEnabled = getAppSettingBool($conn, 'pensioner_lookup_enabled', true);
if (!$lookupEnabled) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pensioner lookup is currently disabled.']);
    exit;
}

$ownedRegistry = resolvePensionerOwnedRegistry($conn, (string)($_SESSION['userId'] ?? ''));
$ownRegNo = trim((string)($ownedRegistry['regNo'] ?? ''));
$requireConsent = getAppSettingBool($conn, 'pensioner_lookup_require_consent', true);
$query = trim((string)($_GET['q'] ?? ''));

if (!function_exists('lookupStrLen')) {
    function lookupStrLen(string $value): int {
        return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

if (!function_exists('lookupStrToLower')) {
    function lookupStrToLower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

if (lookupStrLen($query) < 2) {
    echo json_encode([
        'success' => true,
        'results' => [],
        'message' => 'Enter at least two characters to search the pensioner directory.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$queryLower = lookupStrToLower($query);
$like = '%' . $queryLower . '%';
$prefix = $queryLower . '%';
$exact = $queryLower;

$sql = "
    SELECT
        fr.regNo,
        COALESCE(NULLIF(fr.title, ''), NULLIF(sd.title, ''), '') AS rank_title,
        COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), '') AS s_name,
        COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '') AS f_name,
        TRIM(CONCAT_WS(' ',
            COALESCE(NULLIF(fr.title, ''), NULLIF(sd.title, ''), ''),
            COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),
            COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')
        )) AS full_name,
        COALESCE(NULLIF(sd.prisonUnit, ''), '') AS station,
        COALESCE(NULLIF(fr.telNo, ''), NULLIF(sd.telNo, ''), '') AS phone_number,
        COALESCE(NULLIF(fr.applicant_email, ''), NULLIF(sd.applicant_email, ''), '') AS email_address
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    WHERE COALESCE(fr.is_deleted, 0) = 0
      AND (
            COALESCE(NULLIF(fr.telNo, ''), NULLIF(sd.telNo, ''), '') <> ''
            OR COALESCE(NULLIF(fr.applicant_email, ''), NULLIF(sd.applicant_email, ''), '') <> ''
          )
";
if ($requireConsent) {
    $sql .= " AND COALESCE(fr.lookup_contact_opt_in, 0) = 1 ";
}
if ($ownRegNo !== '') {
    $sql .= " AND fr.regNo <> ? ";
}
    $sql .= "
      AND (
            LOWER(fr.regNo) LIKE ?
            OR LOWER(TRIM(CONCAT_WS(' ',
                COALESCE(NULLIF(fr.title, ''), NULLIF(sd.title, ''), ''),
                COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),
                COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')
            ))) LIKE ?
            OR LOWER(TRIM(CONCAT_WS(' ',
                COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),
                COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')
            ))) LIKE ?
          )
    ORDER BY
      CASE
        WHEN LOWER(fr.regNo) = ? THEN 0
        WHEN LOWER(TRIM(CONCAT_WS(' ',
            COALESCE(NULLIF(fr.title, ''), NULLIF(sd.title, ''), ''),
            COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),
            COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')
        ))) LIKE ? THEN 1
        ELSE 2
      END,
      full_name ASC,
      fr.regNo ASC
    LIMIT 30
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare pensioner lookup query.']);
    exit;
}

if ($ownRegNo !== '') {
    $stmt->bind_param('ssssss', $ownRegNo, $like, $like, $like, $exact, $prefix);
} else {
    $stmt->bind_param('sssss', $like, $like, $like, $exact, $prefix);
}

$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $displayName = formatTitleName(
        (string)($row['rank_title'] ?? ''),
        (string)($row['s_name'] ?? ''),
        (string)($row['f_name'] ?? '')
    );
    $rows[] = [
        'regNo' => (string)($row['regNo'] ?? ''),
        'rankTitle' => (string)($row['rank_title'] ?? ''),
        'name' => $displayName !== '' ? $displayName : (string)($row['full_name'] ?? ''),
        'station' => (string)($row['station'] ?? ''),
        'phoneNumber' => (string)($row['phone_number'] ?? ''),
        'emailAddress' => (string)($row['email_address'] ?? '')
    ];
}
$stmt->close();

if (getAppSettingBool($conn, 'pensioner_lookup_log_activity', true) && getAppSettingBool($conn, 'enable_activity_logs', true)) {
    logUserActivity($conn, [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? 'Pensioner'),
        'user_role' => 'pensioner',
        'activity_type' => 'pensioner_lookup_search',
        'details' => 'Searched the pensioner lookup directory.',
        'status' => 'success'
    ]);
}

echo json_encode([
    'success' => true,
    'results' => $rows,
    'message' => empty($rows) ? 'No pensioner matched the current search.' : ''
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
?>
