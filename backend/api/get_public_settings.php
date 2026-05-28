<?php
/**
 * get_public_settings.php
 * Returns safe, public-facing app settings for UI rendering.
 */

header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../notification_sound_library.php';

    $defaults = [
        'app_name' => 'PensionsGo',
        'app_tagline' => 'Unified pension administration',
        'support_email' => '',
        'support_phone' => '',
        'public_footer_org_name' => 'Uganda Prisons Service Headquarters',
        'public_footer_address' => 'P.O. Box 7182, Kampala (U)',
        'public_footer_tech_support_email' => 'etopat2@gmail.com',
        'public_footer_social_facebook' => 'https://www.facebook.com/UPSRetirement',
        'public_footer_social_twitter' => 'https://www.twitter.com/UPSRetirement',
        'public_footer_social_instagram' => 'https://www.instagram.com/UPSRetirement',
        'public_footer_social_linkedin' => 'https://www.linkedin.com/company/UPSRetirement',
        'public_footer_developer_name' => 'Patrick',
        'public_footer_developer_email' => 'etomet2patrick@gmail.com',
        'public_footer_developer_phone' => '+256773959039',
        'login_banner' => '',
        'timezone' => 'Africa/Kampala',
        'date_format' => 'YYYY-MM-DD',
        'time_format' => '24h',
        'currency' => 'UGX',
        'enable_notifications' => '1',
        'notify_broadcast_enabled' => '1',
        'notify_push_enabled' => '1',
        'notify_broadcast_sound_enabled' => '1',
        'notify_broadcast_sound_path' => 'audio/notification.mp3',
        'notify_broadcast_sound_volume' => '85',
        'notify_broadcast_sound_repeat_count' => '1',
        'notify_broadcast_desktop_enabled' => '1',
        'notify_broadcast_desktop_hidden_only' => '1',
        'live_call_incoming_sound_enabled' => '1',
        'live_call_outgoing_sound_enabled' => '1',
        'live_call_desktop_alerts_enabled' => '1',
        'live_call_incoming_sound_path' => 'audio/notification.mp3',
        'live_call_outgoing_sound_path' => 'audio/notification.mp3',
        'live_call_incoming_sound_volume' => '85',
        'live_call_outgoing_sound_volume' => '55',
        'live_call_incoming_sound_repeat_count' => '0',
        'live_call_outgoing_sound_repeat_count' => '0',
        'live_call_ringing_timeout_seconds' => '45',
        'live_message_sound_enabled' => '1',
        'live_message_desktop_alerts_enabled' => '1',
        'live_message_sound_path' => 'audio/notification.mp3',
        'live_message_sound_volume' => '70',
        'live_message_sound_repeat_count' => '1',
        'security_block_developer_tools' => '0',
        'security_block_context_menu' => '0',
        'security_block_copy' => '0',
        'security_block_cut' => '0',
        'security_block_paste' => '0',
        'security_block_text_selection' => '0',
        'security_block_drag' => '0',
        'pensioner_login_enabled' => '1',
        'pensioner_dashboard_enable_claims' => '1',
        'pensioner_dashboard_enable_documents' => '1',
        'pensioner_dashboard_enable_status_explanations' => '1',
        'pensioner_lookup_enabled' => '1',
        'feedback_public_enabled' => '1',
        'feedback_staff_enabled' => '1',
        'feedback_pensioner_enabled' => '1',
        'podcast_enabled' => '1',
        'podcast_public_enabled' => '1',
        'podcast_show_public_about_button' => '1',
        'notify_quiet_hours_start' => '22:00',
        'notify_quiet_hours_end' => '06:00',
        'document_storage_enabled' => '1',
        'analytics_refresh_interval_minutes' => '15',
        'analytics_export_enabled' => '1',
        'analytics_show_predictive_cards' => '1',
        'analytics_include_financial_forecasts' => '1',
        'analytics_include_operational_kpis' => '1',
        'analytics_anomaly_detection_enabled' => '1'
    ];

    ensureAppSettingsTable($conn);
    $result = $conn->query("SELECT setting_key, setting_value FROM tb_app_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['setting_key'];
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $row['setting_value'];
            }
        }
    }

    $boolKeys = [
        'enable_notifications',
        'notify_broadcast_enabled',
        'notify_push_enabled',
        'notify_broadcast_sound_enabled',
        'notify_broadcast_desktop_enabled',
        'notify_broadcast_desktop_hidden_only',
        'live_call_incoming_sound_enabled',
        'live_call_outgoing_sound_enabled',
        'live_call_desktop_alerts_enabled',
        'live_message_sound_enabled',
        'live_message_desktop_alerts_enabled',
        'security_block_developer_tools',
        'security_block_context_menu',
        'security_block_copy',
        'security_block_cut',
        'security_block_paste',
        'security_block_text_selection',
        'security_block_drag',
        'pensioner_login_enabled',
        'pensioner_dashboard_enable_claims',
        'pensioner_dashboard_enable_documents',
        'pensioner_dashboard_enable_status_explanations',
        'pensioner_lookup_enabled',
        'feedback_public_enabled',
        'feedback_staff_enabled',
        'feedback_pensioner_enabled',
        'podcast_enabled',
        'podcast_public_enabled',
        'podcast_show_public_about_button',
        'document_storage_enabled',
        'analytics_export_enabled',
        'analytics_show_predictive_cards',
        'analytics_include_financial_forecasts',
        'analytics_include_operational_kpis',
        'analytics_anomaly_detection_enabled'
    ];

    $defaults['notify_broadcast_sound_path'] = notificationResolveSelectedSoundPath(
        $defaults['notify_broadcast_sound_path'] ?? 'audio/notification.mp3'
    );
    $defaults['live_call_incoming_sound_path'] = notificationResolveSelectedSoundPath(
        $defaults['live_call_incoming_sound_path'] ?? 'audio/notification.mp3'
    );
    $defaults['live_call_outgoing_sound_path'] = notificationResolveSelectedSoundPath(
        $defaults['live_call_outgoing_sound_path'] ?? 'audio/notification.mp3'
    );
    $defaults['live_message_sound_path'] = notificationResolveSelectedSoundPath(
        $defaults['live_message_sound_path'] ?? 'audio/notification.mp3'
    );

    $settings = [];
    foreach ($defaults as $key => $value) {
        if (in_array($key, $boolKeys, true)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $settings[$key] = ($normalized === null) ? ($value === '1') : (bool)$normalized;
        } else {
            $settings[$key] = (string)$value;
        }
    }

    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
