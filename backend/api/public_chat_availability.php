<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$settings = publicChatSettings($conn);
$csrfToken = publicChatCsrfToken();
$appCsrfToken = !empty($_SESSION['userId']) && function_exists('getSessionCsrfToken') ? getSessionCsrfToken() : '';
publicChatReleaseSessionLock();
publicChatJson([
    'success' => true,
    'settings' => $settings,
    'availability' => publicChatAvailability($conn, $settings),
    'csrfToken' => $csrfToken,
    'appCsrfToken' => $appCsrfToken
]);
?>
