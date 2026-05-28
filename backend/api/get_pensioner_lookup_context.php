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
$requireConsent = getAppSettingBool($conn, 'pensioner_lookup_require_consent', true);
$logLookupActivity = getAppSettingBool($conn, 'pensioner_lookup_log_activity', true);
$ownedRegistry = resolvePensionerOwnedRegistry($conn, (string)($_SESSION['userId'] ?? ''));

$visibilityEnabled = false;
$profile = [
    'regNo' => '',
    'name' => '',
    'station' => ''
];

if ($ownedRegistry && trim((string)($ownedRegistry['regNo'] ?? '')) !== '') {
    $profileStmt = $conn->prepare("
        SELECT
            fr.regNo,
            COALESCE(fr.lookup_contact_opt_in, 0) AS lookup_contact_opt_in,
            TRIM(CONCAT_WS(' - ',
                NULLIF(TRIM(COALESCE(NULLIF(fr.title, ''), NULLIF(sd.title, ''), '')), ''),
                NULLIF(TRIM(CONCAT_WS(' ',
                    COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),
                    COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')
                )), '')
            )) AS full_name,
            COALESCE(NULLIF(sd.prisonUnit, ''), '') AS station
        FROM tb_fileregistry fr
        LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
        WHERE fr.regNo = ?
        LIMIT 1
    ");
    if ($profileStmt) {
        $regNo = (string)$ownedRegistry['regNo'];
        $profileStmt->bind_param('s', $regNo);
        $profileStmt->execute();
        $row = $profileStmt->get_result()->fetch_assoc() ?: null;
        $profileStmt->close();
        if ($row) {
            $visibilityEnabled = ((int)($row['lookup_contact_opt_in'] ?? 0)) === 1;
            $profile = [
                'regNo' => (string)($row['regNo'] ?? ''),
                'name' => (string)($row['full_name'] ?? ''),
                'station' => (string)($row['station'] ?? '')
            ];
        }
    }
}

$directoryCount = 0;
$countSql = "
    SELECT COUNT(*) AS total
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    WHERE COALESCE(fr.is_deleted, 0) = 0
      AND (
            COALESCE(NULLIF(fr.telNo, ''), NULLIF(sd.telNo, ''), '') <> ''
            OR COALESCE(NULLIF(fr.applicant_email, ''), NULLIF(sd.applicant_email, ''), '') <> ''
          )
";
if ($requireConsent) {
    $countSql .= " AND COALESCE(fr.lookup_contact_opt_in, 0) = 1";
}
if ($ownedRegistry && trim((string)($ownedRegistry['regNo'] ?? '')) !== '') {
    $countSql .= " AND fr.regNo <> '" . $conn->real_escape_string((string)$ownedRegistry['regNo']) . "'";
}
$countResult = $conn->query($countSql);
if ($countResult) {
    $directoryCount = (int)($countResult->fetch_assoc()['total'] ?? 0);
}

echo json_encode([
    'success' => true,
    'enabled' => $lookupEnabled,
    'requireConsent' => $requireConsent,
    'logActivity' => $logLookupActivity,
    'visibilityEnabled' => $visibilityEnabled,
    'directoryCount' => $directoryCount,
    'profile' => $profile
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
?>
