<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
publicChatJson([
    'success' => true,
    'settings' => publicChatSettings($conn),
    'availability' => publicChatAvailability($conn),
    'csrfToken' => publicChatCsrfToken()
]);
?>
