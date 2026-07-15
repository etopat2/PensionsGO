<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$settings = publicChatSettings($conn);
$role = publicChatCurrentRole($conn);
$sessionUserId = (string)($_SESSION['userId'] ?? '');
$sessionUserName = (string)($_SESSION['userName'] ?? '');
$sessionUserRole = (string)($_SESSION['userRole'] ?? '');
$isPensioner = $sessionUserId !== '' && $role === 'pensioner';

$prefill = [
    'name' => '',
    'phone_number' => '',
    'email' => '',
    'force_number' => '',
    'pensioner_number' => ''
];

if ($isPensioner) {
    $context = publicChatResolvePensionerContext($conn, ['user_id' => $sessionUserId]);
    $prefill['name'] = (string)($context['registry']['name'] ?? $sessionUserName);
    $prefill['phone_number'] = (string)($context['registry']['phone'] ?? $context['profile']['phone'] ?? '');
    $prefill['email'] = (string)($context['registry']['email'] ?? $context['profile']['email'] ?? '');
    $prefill['force_number'] = (string)($context['registry']['computerNo'] ?? '');
    $prefill['pensioner_number'] = (string)($context['registry']['regNo'] ?? '');
}

$csrfToken = publicChatCsrfToken();
$appCsrfToken = $sessionUserId !== '' && function_exists('getSessionCsrfToken') ? getSessionCsrfToken() : '';
$canManage = publicChatCanManage($conn);
$canSupervise = publicChatCanSupervise($conn);
publicChatReleaseSessionLock();

publicChatJson([
    'success' => true,
    'settings' => $settings,
    'csrfToken' => $csrfToken,
    'appCsrfToken' => $appCsrfToken,
    'availability' => publicChatAvailability($conn, $settings),
    'visitor' => [
        'isLoggedIn' => $sessionUserId !== '',
        'isPensioner' => $isPensioner,
        'userId' => $sessionUserId,
        'userName' => $sessionUserName,
        'role' => $sessionUserRole,
        'prefill' => $prefill
    ],
    'agent' => [
        'canManage' => $canManage,
        'canSupervise' => $canSupervise
    ]
]);
?>
