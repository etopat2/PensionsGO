<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$settings = publicChatSettings($conn);
$role = publicChatCurrentRole($conn);
$isPensioner = isset($_SESSION['userId']) && $role === 'pensioner';

$prefill = [
    'name' => '',
    'phone_number' => '',
    'email' => '',
    'force_number' => '',
    'pensioner_number' => ''
];

if ($isPensioner) {
    $context = publicChatResolvePensionerContext($conn, ['user_id' => (string)$_SESSION['userId']]);
    $prefill['name'] = (string)($context['registry']['name'] ?? $_SESSION['userName'] ?? '');
    $prefill['phone_number'] = (string)($context['registry']['phone'] ?? $context['profile']['phone'] ?? '');
    $prefill['email'] = (string)($context['registry']['email'] ?? $context['profile']['email'] ?? '');
    $prefill['force_number'] = (string)($context['registry']['computerNo'] ?? '');
    $prefill['pensioner_number'] = (string)($context['registry']['regNo'] ?? '');
}

publicChatJson([
    'success' => true,
    'settings' => $settings,
    'csrfToken' => publicChatCsrfToken(),
    'availability' => publicChatAvailability($conn),
    'visitor' => [
        'isLoggedIn' => !empty($_SESSION['userId']),
        'isPensioner' => $isPensioner,
        'prefill' => $prefill
    ],
    'agent' => [
        'canManage' => publicChatCanManage($conn),
        'canSupervise' => publicChatCanSupervise($conn)
    ]
]);
?>
