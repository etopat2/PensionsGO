<?php
/**
 * update_app_settings.php
 * Update application-wide settings (admin only).
 */

header('Content-Type: application/json; charset=UTF-8');
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../notification_sound_library.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

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
        'default_user_role' => 'user',
        'login_banner' => '',
        'maintenance_mode' => '0',
        'timezone' => 'Africa/Kampala',
        'date_format' => 'YYYY-MM-DD',
        'time_format' => '24h',
        'currency' => 'UGX',
        'session_timeout_minutes' => '30',
        'grace_period_minutes' => '5',
        'task_due_business_days' => '3',
        'task_grace_business_days' => '0',
        'task_alerts_enabled' => '1',
        'task_alert_due_soon_hours' => '24',
        'task_alert_stalled_hours' => '72',
        'task_alert_escalation_hours' => '24',
        'task_skip_weekends' => '1',
        'task_skip_ug_holidays' => '1',
        'payroll_reconcile_debounce_seconds' => '60',
        'max_concurrent_sessions' => '1',
        'allow_multiple_devices' => '0',
        'auto_logout_on_conflict' => '1',
        'login_attempt_limit' => '5',
        'lockout_minutes' => '15',
        'password_min_length' => '8',
        'password_expiry_days' => '0',
        'password_require_uppercase' => '1',
        'password_require_lowercase' => '1',
        'password_require_number' => '1',
        'password_require_special' => '0',
        'session_idle_warning_minutes' => '5',
        'security_alert_email' => '',
        'security_alert_sms' => '',
        'security_block_developer_tools' => '0',
        'security_block_context_menu' => '0',
        'security_block_copy' => '0',
        'security_block_cut' => '0',
        'security_block_paste' => '0',
        'security_block_text_selection' => '0',
        'security_block_drag' => '0',
        'security_enforce_csrf' => '1',
        'security_validate_origin' => '1',
        'security_allowed_origins' => '',
        'security_admin_reauth_window_minutes' => '10',
        'security_max_upload_size_mb' => '25',
        'security_max_zip_uncompressed_mb' => '64',
        'security_max_import_rows' => '5000',
        'security_max_zip_entries' => '2000',
        'log_retention_days' => '90',
        'enable_activity_logs' => '1',
        'enable_audit_logs' => '1',
        'enable_notifications' => '1',
        'notify_email_enabled' => '1',
        'notify_sms_enabled' => '0',
        'notify_push_enabled' => '1',
        'notify_sender_name' => 'PensionsGo Notifications',
        'notify_sender_email' => '',
        'notify_test_recipient' => '',
        'notify_system_alerts_enabled' => '1',
        'notify_task_alerts_enabled' => '1',
        'notify_user_activity_enabled' => '1',
        'notify_broadcast_enabled' => '1',
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
        'live_chat_enabled' => '1',
        'live_chat_group_chats_enabled' => '1',
        'live_chat_audio_calls_enabled' => '1',
        'live_chat_video_calls_enabled' => '1',
        'live_chat_add_participants_enabled' => '1',
        'live_chat_attachments_enabled' => '1',
        'live_chat_voice_notes_enabled' => '1',
        'live_chat_polls_enabled' => '1',
        'live_chat_typing_presence_enabled' => '1',
        'live_chat_read_receipts_enabled' => '1',
        'live_chat_drafts_enabled' => '1',
        'live_chat_admin_archive_enabled' => '1',
        'live_chat_admin_delete_enabled' => '1',
        'live_chat_edit_window_minutes' => '5',
        'live_chat_typing_idle_seconds' => '5',
        'live_chat_message_poll_ms' => '350',
        'live_chat_receipt_poll_ms' => '250',
        'live_chat_call_poll_ms' => '900',
        'live_chat_signal_poll_ms' => '350',
        'notify_quiet_hours_start' => '22:00',
        'notify_quiet_hours_end' => '06:00',
        'notify_admin_digest_enabled' => '1',
        'notify_digest_time' => '07:30',
        'notify_queue_worker_enabled' => '1',
        'notify_queue_process_on_request' => '0',
        'notify_queue_batch_size' => '10',
        'notify_queue_retry_limit' => '3',
        'notify_queue_retry_delay_minutes' => '10',
        'notify_queue_min_interval_seconds' => '60',
        'message_retention_days' => '365',
        'message_archive_after_days' => '90',
        'message_allow_soft_delete' => '1',
        'message_storage_quota_mb' => '2048',
        'message_compress_enabled' => '1',
        'message_backup_enabled' => '1',
        'attachment_max_size_mb' => '25',
        'attachment_allowed_types' => 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
        'attachment_scan_enabled' => '0',
        'attachment_retention_days' => '365',
        'attachment_dedupe_enabled' => '1',
        'attachment_compress_enabled' => '1',

        'storage_warning_threshold_mb' => '5120',
        'storage_critical_threshold_mb' => '10240',
        'storage_cleanup_backup_before_delete' => '1',
        'storage_cleanup_dry_run_default' => '1',
        'storage_cleanup_sessions_days' => '30',
        'storage_cleanup_notification_days' => '30',
        'storage_cleanup_imports_days' => '90',
        'storage_cleanup_exports_days' => '90',
        'storage_cleanup_backups_days' => '180',
        'storage_cleanup_orphan_documents_days' => '30',
        'backup_retention_days' => '90',
        'export_retention_days' => '90',
        'backup_include_uploads_default' => '1',
        'document_storage_enabled' => '1',
        'document_max_size_mb' => '25',
        'document_allowed_types' => 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
        'document_retention_days' => '3650',
        'document_archive_after_days' => '730',
        'document_classification_required' => '1',
        'document_dedupe_enabled' => '1',
        'document_preview_enabled' => '1',
        'document_access_audit_enabled' => '1',
        'document_link_registry_required' => '1',
        'document_naming_scheme' => 'regno_doc_type_timestamp',
        'workflow_logs_enabled' => '1',
        'workflow_logs_retention_days' => '1825',
        'staff_due_verification_escalation_days' => '60',
        'workflow_logs_include_comments' => '1',
        'workflow_logs_capture_assignment' => '1',
        'workflow_logs_export_enabled' => '1',
        'task_delegation_logs_enabled' => '1',
        'task_delegation_retention_days' => '1095',
        'task_delegation_require_reason' => '1',
        'task_delegation_escalation_enabled' => '1',
        'task_delegation_export_enabled' => '1',
        'system_logs_enabled' => '1',
        'system_logs_retention_days' => '365',
        'system_logs_capture_warnings' => '1',
        'system_logs_capture_errors' => '1',
        'system_logs_capture_security_events' => '1',
        'system_logs_capture_integrations' => '1',
        'system_logs_min_level' => 'info',
        'analytics_refresh_interval_minutes' => '15',
        'analytics_dashboard_snapshots_enabled' => '1',
        'analytics_snapshot_retention_days' => '365',
        'analytics_export_enabled' => '1',
        'analytics_auto_digest_enabled' => '0',
        'analytics_digest_frequency' => 'weekly',
        'analytics_digest_time' => '08:00',
        'analytics_digest_recipient' => '',
        'analytics_show_predictive_cards' => '1',
        'analytics_include_financial_forecasts' => '1',
        'analytics_include_operational_kpis' => '1',
        'analytics_anomaly_detection_enabled' => '1',
        'feedback_public_enabled' => '1',
        'feedback_staff_enabled' => '1',
        'feedback_pensioner_enabled' => '1',
        'feedback_email_notifications_enabled' => '1',
        'feedback_allow_assignment' => '1',
        'feedback_allow_export' => '1',
        'feedback_response_sla_days' => '5',
        'pensioner_login_enabled' => '1',
        'pensioner_dashboard_enable_claims' => '1',
        'pensioner_dashboard_enable_documents' => '1',
        'pensioner_dashboard_enable_status_explanations' => '1',
        'pensioner_dashboard_enable_activity_log' => '1',
        'pensioner_lookup_enabled' => '1',
        'pensioner_lookup_require_consent' => '1',
        'pensioner_lookup_log_activity' => '1',
        'podcast_enabled' => '1',
        'podcast_public_enabled' => '1',
        'podcast_staff_enabled' => '1',
        'podcast_pensioner_enabled' => '1',
        'podcast_show_public_about_button' => '1',
        'podcast_log_views' => '1',
        'podcast_allow_metadata_edit' => '1',
        'podcast_allow_video_replace' => '1',
        'podcast_allow_delete' => '1'
    ];

    $boolKeys = [
        'maintenance_mode',
        'allow_multiple_devices',
        'auto_logout_on_conflict',
        'task_skip_weekends',
        'task_skip_ug_holidays',
        'task_alerts_enabled',
        'password_require_uppercase',
        'password_require_lowercase',
        'password_require_number',
        'password_require_special',
        'security_block_developer_tools',
        'security_block_context_menu',
        'security_block_copy',
        'security_block_cut',
        'security_block_paste',
        'security_block_text_selection',
        'security_block_drag',
        'security_enforce_csrf',
        'security_validate_origin',
        'enable_activity_logs',
        'enable_audit_logs',
        'enable_notifications',
        'notify_email_enabled',
        'notify_sms_enabled',
        'notify_push_enabled',
        'notify_system_alerts_enabled',
        'notify_task_alerts_enabled',
        'notify_user_activity_enabled',
        'notify_broadcast_enabled',
        'notify_broadcast_sound_enabled',
        'notify_broadcast_desktop_enabled',
        'notify_broadcast_desktop_hidden_only',
        'live_call_incoming_sound_enabled',
        'live_call_outgoing_sound_enabled',
        'live_call_desktop_alerts_enabled',
        'live_message_sound_enabled',
        'live_message_desktop_alerts_enabled',
        'live_chat_enabled',
        'live_chat_group_chats_enabled',
        'live_chat_audio_calls_enabled',
        'live_chat_video_calls_enabled',
        'live_chat_add_participants_enabled',
        'live_chat_attachments_enabled',
        'live_chat_voice_notes_enabled',
        'live_chat_polls_enabled',
        'live_chat_typing_presence_enabled',
        'live_chat_read_receipts_enabled',
        'live_chat_drafts_enabled',
        'live_chat_admin_archive_enabled',
        'live_chat_admin_delete_enabled',
        'notify_admin_digest_enabled',
        'notify_queue_worker_enabled',
        'notify_queue_process_on_request',
        'message_allow_soft_delete',
        'message_compress_enabled',
        'message_backup_enabled',
        'backup_include_uploads_default',
        'document_storage_enabled',
        'document_classification_required',
        'document_dedupe_enabled',
        'document_preview_enabled',
        'document_access_audit_enabled',
        'document_link_registry_required',
        'attachment_scan_enabled',
        'attachment_dedupe_enabled',
        'attachment_compress_enabled',
        'workflow_logs_enabled',
        'workflow_logs_include_comments',
        'workflow_logs_capture_assignment',
        'workflow_logs_export_enabled',
        'task_delegation_logs_enabled',
        'task_delegation_require_reason',
        'task_delegation_escalation_enabled',
        'task_delegation_export_enabled',
        'system_logs_enabled',
        'system_logs_capture_warnings',
        'system_logs_capture_errors',
        'system_logs_capture_security_events',
        'system_logs_capture_integrations',
        'analytics_dashboard_snapshots_enabled',
        'analytics_export_enabled',
        'analytics_auto_digest_enabled',
        'analytics_show_predictive_cards',
        'analytics_include_financial_forecasts',
        'analytics_include_operational_kpis',
        'analytics_anomaly_detection_enabled',
        'feedback_public_enabled',
        'feedback_staff_enabled',
        'feedback_pensioner_enabled',
        'feedback_email_notifications_enabled',
        'feedback_allow_assignment',
        'feedback_allow_export',
        'pensioner_login_enabled',
        'pensioner_dashboard_enable_claims',
        'pensioner_dashboard_enable_documents',
        'pensioner_dashboard_enable_status_explanations',
        'pensioner_dashboard_enable_activity_log',
        'pensioner_lookup_enabled',
        'pensioner_lookup_require_consent',
        'pensioner_lookup_log_activity',
        'podcast_enabled',
        'podcast_public_enabled',
        'podcast_staff_enabled',
        'podcast_pensioner_enabled',
        'podcast_show_public_about_button',
        'podcast_log_views',
        'podcast_allow_metadata_edit',
        'podcast_allow_video_replace',
        'podcast_allow_delete'
    ];

    $intKeys = [
        'session_timeout_minutes',
        'grace_period_minutes',
        'task_due_business_days',
        'task_grace_business_days',
        'task_alert_due_soon_hours',
        'task_alert_stalled_hours',
        'task_alert_escalation_hours',
        'payroll_reconcile_debounce_seconds',
        'notify_broadcast_sound_volume',
        'notify_broadcast_sound_repeat_count',
        'live_call_incoming_sound_volume',
        'live_call_outgoing_sound_volume',
        'live_call_incoming_sound_repeat_count',
        'live_call_outgoing_sound_repeat_count',
        'live_call_ringing_timeout_seconds',
        'live_message_sound_volume',
        'live_message_sound_repeat_count',
        'live_chat_edit_window_minutes',
        'live_chat_typing_idle_seconds',
        'live_chat_message_poll_ms',
        'live_chat_receipt_poll_ms',
        'live_chat_call_poll_ms',
        'live_chat_signal_poll_ms',
        'notify_queue_batch_size',
        'notify_queue_retry_limit',
        'notify_queue_retry_delay_minutes',
        'notify_queue_min_interval_seconds',
        'max_concurrent_sessions',
        'login_attempt_limit',
        'lockout_minutes',
        'password_min_length',
        'password_expiry_days',
        'session_idle_warning_minutes',
        'security_admin_reauth_window_minutes',
        'security_max_upload_size_mb',
        'security_max_zip_uncompressed_mb',
        'security_max_import_rows',
        'security_max_zip_entries',
        'log_retention_days',
        'message_retention_days',
        'message_archive_after_days',
        'message_storage_quota_mb',
        'attachment_max_size_mb',
        'attachment_retention_days',
        'feedback_response_sla_days',
        'storage_warning_threshold_mb',
        'storage_critical_threshold_mb',
        'storage_cleanup_sessions_days',
        'storage_cleanup_notification_days',
        'storage_cleanup_imports_days',
        'storage_cleanup_exports_days',
        'storage_cleanup_backups_days',
        'storage_cleanup_orphan_documents_days',
        'backup_retention_days',
        'export_retention_days',
        'document_max_size_mb',
        'document_retention_days',
        'document_archive_after_days',
        'workflow_logs_retention_days',
        'staff_due_verification_escalation_days',
        'task_delegation_retention_days',
        'system_logs_retention_days',
        'analytics_refresh_interval_minutes',
        'analytics_snapshot_retention_days',
    ];

    $settingLabels = [
        'app_name' => 'Application name',
        'app_tagline' => 'Application tagline',
        'support_email' => 'Support email',
        'support_phone' => 'Support phone',
        'public_footer_org_name' => 'Footer organisation name',
        'public_footer_address' => 'Footer organisation address',
        'public_footer_tech_support_email' => 'Footer technical support email',
        'public_footer_social_facebook' => 'Footer Facebook link',
        'public_footer_social_twitter' => 'Footer Twitter link',
        'public_footer_social_instagram' => 'Footer Instagram link',
        'public_footer_social_linkedin' => 'Footer LinkedIn link',
        'public_footer_developer_name' => 'Footer developer name',
        'public_footer_developer_email' => 'Footer developer email',
        'public_footer_developer_phone' => 'Footer developer phone',
        'default_user_role' => 'Default user role',
        'login_banner' => 'Login banner message',
        'maintenance_mode' => 'Maintenance mode',
        'timezone' => 'Timezone',
        'date_format' => 'Date format',
        'time_format' => 'Time format',
        'currency' => 'Currency',
        'session_timeout_minutes' => 'Session timeout (minutes)',
        'grace_period_minutes' => 'Session grace period (minutes)',
        'task_due_business_days' => 'Task due window (business days)',
        'task_grace_business_days' => 'Task grace window (business days)',
        'task_alerts_enabled' => 'Task alerts',
        'task_alert_due_soon_hours' => 'Task due-soon window (hours)',
        'task_alert_stalled_hours' => 'Task stalled window (hours)',
        'task_alert_escalation_hours' => 'Task escalation window (hours)',
        'task_skip_weekends' => 'Skip weekends for tasks',
        'task_skip_ug_holidays' => 'Skip Uganda holidays for tasks',
        'payroll_reconcile_debounce_seconds' => 'Payroll reconcile debounce (seconds)',
        'max_concurrent_sessions' => 'Max concurrent sessions',
        'allow_multiple_devices' => 'Allow multiple device sessions',
        'auto_logout_on_conflict' => 'Auto logout on session conflict',
        'login_attempt_limit' => 'Login attempt limit',
        'lockout_minutes' => 'Lockout duration (minutes)',
        'password_min_length' => 'Password minimum length',
        'password_expiry_days' => 'Password expiry (days)',
        'password_require_uppercase' => 'Password requires uppercase',
        'password_require_lowercase' => 'Password requires lowercase',
        'password_require_number' => 'Password requires number',
        'password_require_special' => 'Password requires special character',
        'session_idle_warning_minutes' => 'Idle warning (minutes)',
        'security_alert_email' => 'Security alert email',
        'security_alert_sms' => 'Security alert SMS',
        'security_block_developer_tools' => 'Block developer tools',
        'security_block_context_menu' => 'Block right-click menu',
        'security_block_copy' => 'Block copy',
        'security_block_cut' => 'Block cut',
        'security_block_paste' => 'Block paste',
        'security_block_text_selection' => 'Block text selection',
        'security_block_drag' => 'Block drag',
        'security_enforce_csrf' => 'Enforce CSRF',
        'security_validate_origin' => 'Validate origin header',
        'security_allowed_origins' => 'Allowed origins',
        'security_admin_reauth_window_minutes' => 'Admin re-auth window (minutes)',
        'security_max_upload_size_mb' => 'Max upload size (MB)',
        'security_max_zip_uncompressed_mb' => 'Max zip uncompressed size (MB)',
        'security_max_import_rows' => 'Max import rows',
        'security_max_zip_entries' => 'Max zip entries',
        'log_retention_days' => 'Log retention (days)',
        'enable_activity_logs' => 'Activity logs',
        'enable_audit_logs' => 'Audit logs',
        'enable_notifications' => 'Notifications',
        'notify_email_enabled' => 'Email notifications',
        'notify_sms_enabled' => 'SMS notifications',
        'notify_push_enabled' => 'Push notifications',
        'notify_sender_name' => 'Notification sender name',
        'notify_sender_email' => 'Notification sender email',
        'notify_test_recipient' => 'Notification test recipient',
        'notify_system_alerts_enabled' => 'System alert notifications',
        'notify_task_alerts_enabled' => 'Task alert notifications',
        'notify_user_activity_enabled' => 'User activity notifications',
        'notify_broadcast_enabled' => 'Broadcast notifications',
        'notify_broadcast_sound_enabled' => 'Broadcast notification sound',
        'notify_broadcast_sound_path' => 'Broadcast notification sound file',
        'notify_broadcast_sound_volume' => 'Broadcast notification sound volume',
        'notify_broadcast_sound_repeat_count' => 'Broadcast notification repeat count',
        'notify_broadcast_desktop_enabled' => 'Broadcast browser alerts',
        'notify_broadcast_desktop_hidden_only' => 'Broadcast browser alerts when hidden only',
        'live_call_incoming_sound_enabled' => 'Incoming live call sound',
        'live_call_outgoing_sound_enabled' => 'Outgoing live call sound',
        'live_call_desktop_alerts_enabled' => 'Live call browser alerts',
        'live_call_incoming_sound_path' => 'Incoming live call sound file',
        'live_call_outgoing_sound_path' => 'Outgoing live call sound file',
        'live_call_incoming_sound_volume' => 'Incoming live call sound volume',
        'live_call_outgoing_sound_volume' => 'Outgoing live call sound volume',
        'live_call_incoming_sound_repeat_count' => 'Incoming live call repeat count',
        'live_call_outgoing_sound_repeat_count' => 'Outgoing live call repeat count',
        'live_call_ringing_timeout_seconds' => 'Live call ringing timeout',
        'live_message_sound_enabled' => 'Live chat message sound',
        'live_message_desktop_alerts_enabled' => 'Live chat in-app message alerts',
        'live_message_sound_path' => 'Live chat message sound file',
        'live_message_sound_volume' => 'Live chat message sound volume',
        'live_message_sound_repeat_count' => 'Live chat message repeat count',
        'live_chat_enabled' => 'Live chat enabled',
        'live_chat_group_chats_enabled' => 'Live chat group chats',
        'live_chat_audio_calls_enabled' => 'Live chat audio calls',
        'live_chat_video_calls_enabled' => 'Live chat video calls',
        'live_chat_add_participants_enabled' => 'Live chat add participants',
        'live_chat_attachments_enabled' => 'Live chat attachments',
        'live_chat_voice_notes_enabled' => 'Live chat voice notes',
        'live_chat_polls_enabled' => 'Live chat polls',
        'live_chat_typing_presence_enabled' => 'Live chat typing presence',
        'live_chat_read_receipts_enabled' => 'Live chat read receipts',
        'live_chat_drafts_enabled' => 'Live chat drafts',
        'live_chat_admin_archive_enabled' => 'Live chat admin archive',
        'live_chat_admin_delete_enabled' => 'Live chat admin delete',
        'live_chat_edit_window_minutes' => 'Live chat edit window',
        'live_chat_typing_idle_seconds' => 'Live chat typing idle timeout',
        'live_chat_message_poll_ms' => 'Live chat message poll interval',
        'live_chat_receipt_poll_ms' => 'Live chat receipt poll interval',
        'live_chat_call_poll_ms' => 'Live chat call poll interval',
        'live_chat_signal_poll_ms' => 'Live chat signal poll interval',
        'notify_quiet_hours_start' => 'Quiet hours start',
        'notify_quiet_hours_end' => 'Quiet hours end',
        'notify_admin_digest_enabled' => 'Admin digest notifications',
        'notify_digest_time' => 'Admin digest time',
        'notify_queue_worker_enabled' => 'Notification queue worker',
        'notify_queue_process_on_request' => 'Process queue on request',
        'notify_queue_batch_size' => 'Queue batch size',
        'notify_queue_retry_limit' => 'Queue retry limit',
        'notify_queue_retry_delay_minutes' => 'Queue retry delay (minutes)',
        'notify_queue_min_interval_seconds' => 'Queue min interval (seconds)',
        'message_retention_days' => 'Message retention (days)',
        'message_archive_after_days' => 'Message archive after (days)',
        'message_allow_soft_delete' => 'Message soft delete',
        'message_storage_quota_mb' => 'Message storage quota (MB)',
        'message_compress_enabled' => 'Message compression',
        'message_backup_enabled' => 'Message backup',
        'attachment_max_size_mb' => 'Attachment max size (MB)',
        'attachment_allowed_types' => 'Attachment allowed types',
        'attachment_scan_enabled' => 'Attachment virus scan',
        'attachment_retention_days' => 'Attachment retention (days)',
        'attachment_dedupe_enabled' => 'Attachment deduplication',
        'attachment_compress_enabled' => 'Attachment compression',
        'storage_warning_threshold_mb' => 'Storage warning threshold (MB)',
        'storage_critical_threshold_mb' => 'Storage critical threshold (MB)',
        'storage_cleanup_backup_before_delete' => 'Backup before cleanup',
        'storage_cleanup_dry_run_default' => 'Cleanup dry run default',
        'storage_cleanup_sessions_days' => 'Cleanup sessions (days)',
        'storage_cleanup_notification_days' => 'Cleanup notifications (days)',
        'storage_cleanup_imports_days' => 'Cleanup imports (days)',
        'storage_cleanup_exports_days' => 'Cleanup exports (days)',
        'storage_cleanup_backups_days' => 'Cleanup backups (days)',
        'storage_cleanup_orphan_documents_days' => 'Cleanup orphan documents (days)',
        'backup_retention_days' => 'Backup retention (days)',
        'export_retention_days' => 'Export retention (days)',
        'backup_include_uploads_default' => 'Backup include uploads by default',
        'document_storage_enabled' => 'Document storage',
        'document_max_size_mb' => 'Document max size (MB)',
        'document_allowed_types' => 'Document allowed types',
        'document_retention_days' => 'Document retention (days)',
        'document_archive_after_days' => 'Document archive after (days)',
        'document_classification_required' => 'Document classification required',
        'document_dedupe_enabled' => 'Document deduplication',
        'document_preview_enabled' => 'Document preview enabled',
        'document_access_audit_enabled' => 'Document access audit',
        'document_link_registry_required' => 'Document registry link required',
        'document_naming_scheme' => 'Document naming scheme',
        'workflow_logs_enabled' => 'Workflow logs enabled',
        'workflow_logs_retention_days' => 'Workflow logs retention (days)',
        'staff_due_verification_escalation_days' => 'Verification escalation window (days)',
        'workflow_logs_include_comments' => 'Workflow logs include comments',
        'workflow_logs_capture_assignment' => 'Workflow logs capture assignment',
        'workflow_logs_export_enabled' => 'Workflow logs export enabled',
        'task_delegation_logs_enabled' => 'Task delegation logs enabled',
        'task_delegation_retention_days' => 'Task delegation logs retention (days)',
        'task_delegation_require_reason' => 'Task delegation require reason',
        'task_delegation_escalation_enabled' => 'Task delegation escalation enabled',
        'task_delegation_export_enabled' => 'Task delegation export enabled',
        'system_logs_enabled' => 'System logs enabled',
        'system_logs_retention_days' => 'System logs retention (days)',
        'system_logs_capture_warnings' => 'System logs capture warnings',
        'system_logs_capture_errors' => 'System logs capture errors',
        'system_logs_capture_security_events' => 'System logs capture security events',
        'system_logs_capture_integrations' => 'System logs capture integrations',
        'system_logs_min_level' => 'System logs minimum level',
        'analytics_refresh_interval_minutes' => 'Analytics refresh interval (minutes)',
        'analytics_dashboard_snapshots_enabled' => 'Analytics snapshots enabled',
        'analytics_snapshot_retention_days' => 'Analytics snapshot retention (days)',
        'analytics_export_enabled' => 'Analytics export enabled',
        'analytics_auto_digest_enabled' => 'Analytics digest enabled',
        'analytics_digest_frequency' => 'Analytics digest frequency',
        'analytics_digest_time' => 'Analytics digest time',
        'analytics_digest_recipient' => 'Analytics digest recipient',
        'analytics_show_predictive_cards' => 'Predictive analytics cards',
        'analytics_include_financial_forecasts' => 'Include financial forecasts',
        'analytics_include_operational_kpis' => 'Include operational KPIs',
        'analytics_anomaly_detection_enabled' => 'Analytics anomaly detection',
        'feedback_public_enabled' => 'Public feedback enabled',
        'feedback_staff_enabled' => 'Staff feedback enabled',
        'feedback_pensioner_enabled' => 'Pensioner feedback enabled',
        'feedback_email_notifications_enabled' => 'Feedback email notifications',
        'feedback_allow_assignment' => 'Feedback assignment enabled',
        'feedback_allow_export' => 'Feedback export enabled',
        'feedback_response_sla_days' => 'Feedback response SLA (days)',
        'pensioner_login_enabled' => 'Pensioner login enabled',
        'pensioner_dashboard_enable_claims' => 'Pensioner claims dashboard',
        'pensioner_dashboard_enable_documents' => 'Pensioner documents dashboard',
        'pensioner_dashboard_enable_status_explanations' => 'Pensioner status explanations',
        'pensioner_dashboard_enable_activity_log' => 'Pensioner activity log',
        'pensioner_lookup_enabled' => 'Pensioner lookup enabled',
        'pensioner_lookup_require_consent' => 'Pensioner lookup consent required',
        'pensioner_lookup_log_activity' => 'Pensioner lookup activity log',
        'podcast_enabled' => 'Podcast enabled',
        'podcast_public_enabled' => 'Podcast public access',
        'podcast_staff_enabled' => 'Podcast staff access',
        'podcast_pensioner_enabled' => 'Podcast pensioner access',
        'podcast_show_public_about_button' => 'Show public about button',
        'podcast_log_views' => 'Podcast view logging',
        'podcast_allow_metadata_edit' => 'Podcast metadata editing',
        'podcast_allow_video_replace' => 'Podcast video replace',
        'podcast_allow_delete' => 'Podcast delete allowed'
    ];

    $formatSettingLabel = function(string $key) use ($settingLabels): string {
        if (isset($settingLabels[$key])) {
            return $settingLabels[$key];
        }
        return ucwords(str_replace('_', ' ', $key));
    };

    $normalizeSettingValue = function(string $key, $value) use ($boolKeys, $intKeys, $defaults): string {
        $defaultValue = $defaults[$key] ?? '';
        if ($value === null) {
            $value = $defaultValue;
        }

        if (in_array($key, $boolKeys, true)) {
            $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return ($boolValue === null) ? (($value === '1' || $value === 1 || $value === true) ? '1' : '0') : ($boolValue ? '1' : '0');
        }

        if (in_array($key, $intKeys, true)) {
            $value = is_numeric($value) ? (int)$value : (int)$defaultValue;

            if ($key === 'payroll_reconcile_debounce_seconds') {
                $value = max(15, min(900, $value));
            } elseif ($key === 'task_alert_due_soon_hours') {
                $value = max(1, min(168, $value));
            } elseif ($key === 'task_alert_stalled_hours') {
                $value = max(6, min(720, $value));
            } elseif ($key === 'task_alert_escalation_hours') {
                $value = max(1, min(720, $value));
            } elseif (in_array($key, ['notify_broadcast_sound_volume', 'live_call_incoming_sound_volume', 'live_call_outgoing_sound_volume', 'live_message_sound_volume'], true)) {
                $value = max(0, min(100, $value));
            } elseif (in_array($key, ['notify_broadcast_sound_repeat_count', 'live_message_sound_repeat_count'], true)) {
                $value = max(1, min(5, $value));
            } elseif (in_array($key, ['live_call_incoming_sound_repeat_count', 'live_call_outgoing_sound_repeat_count'], true)) {
                $value = max(0, min(10, $value));
            } elseif ($key === 'live_call_ringing_timeout_seconds') {
                $value = max(10, min(300, $value));
            } elseif ($key === 'live_chat_edit_window_minutes') {
                $value = max(1, min(60, $value));
            } elseif ($key === 'live_chat_typing_idle_seconds') {
                $value = max(2, min(30, $value));
            } elseif (in_array($key, ['live_chat_message_poll_ms', 'live_chat_receipt_poll_ms', 'live_chat_signal_poll_ms'], true)) {
                $value = max(150, min(5000, $value));
            } elseif ($key === 'live_chat_call_poll_ms') {
                $value = max(300, min(10000, $value));
            } else {
                $value = max(0, $value);
            }

            return (string)$value;
        }

        if (in_array($key, ['notify_broadcast_sound_path', 'live_call_incoming_sound_path', 'live_call_outgoing_sound_path', 'live_message_sound_path'], true)) {
            return notificationResolveSelectedSoundPath((string)$value);
        }

        return is_string($value) ? trim($value) : '';
    };

    $formatSettingValue = function(string $key, ?string $value) use ($boolKeys): string {
        $value = $value ?? '';
        if (in_array($key, $boolKeys, true)) {
            return $value === '1' ? 'Enabled' : 'Disabled';
        }
        if (trim($value) === '') {
            return '(empty)';
        }
        return (string)$value;
    };

    $updated = [];
    foreach ($defaults as $key => $defaultValue) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $updated[$key] = $normalizeSettingValue($key, $input[$key]);
    }

    if (empty($updated)) {
        http_response_code(400);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'No settings provided.'
        ]);
        exit;
    }

    $changeNotes = [];
    foreach ($updated as $key => $newValue) {
        $previousRaw = function_exists('getAppSetting') ? getAppSetting($conn, $key) : null;
        $oldValue = $normalizeSettingValue($key, $previousRaw);
        if ($oldValue === $newValue) {
            continue;
        }
        $label = $formatSettingLabel($key);
        $oldDisplay = $formatSettingValue($key, $oldValue);
        $newDisplay = $formatSettingValue($key, $newValue);
        $changeNotes[] = "Edited {$label} from '{$oldDisplay}' to '{$newDisplay}'";
    }
    if (empty($changeNotes)) {
        $changeNotes[] = 'Settings saved with no value changes.';
    }
    $auditDetails = implode('; ', $changeNotes);

    $ok = true;
    foreach ($updated as $key => $value) {
        if (!setAppSetting($conn, $key, $value)) {
            $ok = false;
        }
    }

    if (!$ok) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save all settings.'
        ]);
        exit;
    }

    // Return updated settings for immediate UI sync
    foreach ($updated as $key => $value) {
        $defaults[$key] = $value;
    }

    $settings = [];
    foreach ($defaults as $key => $value) {
        if (in_array($key, $boolKeys, true)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $settings[$key] = ($normalized === null) ? ($value === '1') : (bool)$normalized;
        } elseif (in_array($key, $intKeys, true)) {
            $settings[$key] = (int)$value;
        } else {
            $settings[$key] = (string)$value;
        }
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $_SESSION['userId'] ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? 'system',
            'action' => 'settings_updated',
            'entity_type' => 'app_settings',
            'entity_id' => 'global',
            'details' => $auditDetails
        ]);
    }

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'info',
            'log_category' => 'settings',
            'event_code' => 'settings_updated',
            'message' => 'Application settings were updated from the Admin Console.',
            'context' => [
                'updated_keys' => array_keys($updated),
                'changes' => $changeNotes
            ]
        ]);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
}
?>
