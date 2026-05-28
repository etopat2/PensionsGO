-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 30, 2026 at 10:44 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pension_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tb_analytics_digest_runs`
--

CREATE TABLE `tb_analytics_digest_runs` (
  `digest_id` bigint(20) UNSIGNED NOT NULL,
  `digest_date` date NOT NULL,
  `run_type` enum('scheduled','manual','preview') NOT NULL DEFAULT 'scheduled',
  `digest_frequency` varchar(20) NOT NULL DEFAULT 'weekly',
  `recipient` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `summary_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_analytics_snapshots`
--

CREATE TABLE `tb_analytics_snapshots` (
  `snapshot_id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_type` varchar(80) NOT NULL,
  `snapshot_payload` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tb_analytics_snapshots`
--

INSERT INTO `tb_analytics_snapshots` (`snapshot_id`, `snapshot_type`, `snapshot_payload`, `created_at`) VALUES
(1, 'general_statistics', '{\"generated_at\":\"2026-03-30T09:37:26+02:00\",\"highlights\":{\"totalFiles\":0,\"staffDue\":0,\"staffDueEscalations\":0,\"openWorkflow\":0,\"claimsBalance\":0,\"offPayroll\":0,\"pendingLifeCertificates\":0},\"volumes\":[{\"label\":\"Registry Portfolio\",\"value\":0,\"meta\":\"0.0% alive | 0.0% deceased\",\"tone\":\"info\"},{\"label\":\"Staff Due Intake\",\"value\":0,\"meta\":\"0.0% submitted | 0 awaiting verification\",\"tone\":\"warning\"},{\"label\":\"Open Workflow Tasks\",\"value\":0,\"meta\":\"0 overdue tasks need escalation\",\"tone\":\"success\"},{\"label\":\"Claims Ledger Entries\",\"value\":0,\"meta\":\"0 open claims remain unsettled\",\"tone\":\"success\"},{\"label\":\"User Accounts\",\"value\":18,\"meta\":\"14 staff | 4 pensioner accounts\",\"tone\":\"info\"},{\"label\":\"Open File Movements\",\"value\":0,\"meta\":\"0 out movements are overdue\",\"tone\":\"warning\"}],\"risks\":[{\"label\":\"Latest Off Payroll Pensioners\",\"value\":0,\"meta\":\"Latest payroll cut: Latest cycle unavailable\",\"tone\":\"success\"},{\"label\":\"Verification Escalations\",\"value\":0,\"meta\":\"0 more submissions are approaching the 60-day initiation limit\",\"tone\":\"success\"},{\"label\":\"Pending Life Certificates\",\"value\":0,\"meta\":\"2026 compliance outstanding after exemptions are removed\",\"tone\":\"success\"},{\"label\":\"Workflow Overdue Tasks\",\"value\":0,\"meta\":\"0 critical task alerts are still open\",\"tone\":\"success\"},{\"label\":\"Claims Outstanding Balance\",\"value\":0,\"meta\":\"0 cross-FY accountability submissions are still pending\",\"tone\":\"success\",\"format\":\"currency\"},{\"label\":\"Files Out of Registry\",\"value\":0,\"meta\":\"0 open movements have crossed expected return dates\",\"tone\":\"success\"},{\"label\":\"One-off File Population\",\"value\":0,\"meta\":\"Separated from recurring pensioner files for storage and custody planning\",\"tone\":\"info\"}],\"insights\":[{\"label\":\"Staff Due Submission Rate\",\"value\":\"0.0%\",\"helper\":\"0 due records still need submission into workflow.\",\"tone\":\"success\"},{\"label\":\"Verification Start Rate\",\"value\":\"0.0%\",\"helper\":\"0 submitted files have crossed the 60-day verification-start rule; 0 are nearing it.\",\"tone\":\"success\"},{\"label\":\"Payroll Coverage\",\"value\":\"0.0%\",\"helper\":\"Latest cycle unavailable latest payroll window for pensioner pay-type records.\",\"tone\":\"success\"},{\"label\":\"Life Certificate Compliance\",\"value\":\"0.0%\",\"helper\":\"0 eligible alive pensioner files are expected this year; 0 records are exempt.\",\"tone\":\"success\"},{\"label\":\"Workflow Overdue Rate\",\"value\":\"0.0%\",\"helper\":\"0 tasks completed within the last seven days.\",\"tone\":\"success\"},{\"label\":\"Registry Circulation\",\"value\":\"0.0%\",\"helper\":\"0 files are currently available in registry.\",\"tone\":\"success\"},{\"label\":\"Claims Settlement Rate\",\"value\":\"0.0%\",\"helper\":\"UGX 0.00 expected versus UGX 0.00 already paid.\",\"tone\":\"success\"}],\"notes\":[{\"title\":\"Operational posture is stable\",\"body\":\"No critical cross-application warning signals are currently elevated. Keep monitoring the live sections for any new compliance, workflow, or payroll drift.\",\"tone\":\"success\"}],\"context\":{\"latest_payroll_label\":\"Latest cycle unavailable\",\"current_year\":2026,\"pensioner_files\":0,\"oneoff_files\":0}}', '2026-03-30 10:37:26');

-- --------------------------------------------------------

--
-- Table structure for table `tb_application_queue`
--

CREATE TABLE `tb_application_queue` (
  `queue_id` int(11) NOT NULL,
  `staffdue_id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `current_stage` varchar(50) NOT NULL DEFAULT 'verified',
  `status` enum('verified','submitted_to_oc','in_progress','completed','dropped') NOT NULL DEFAULT 'verified',
  `verified_by` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `submitted_by` varchar(100) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_appnstatus`
--

CREATE TABLE `tb_appnstatus` (
  `id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `computerNo` varchar(50) DEFAULT NULL,
  `verification` varchar(50) DEFAULT NULL,
  `writeUp` varchar(50) DEFAULT NULL,
  `fileCreation` varchar(50) DEFAULT NULL,
  `entrantAllocation` varchar(50) DEFAULT NULL,
  `dataCapture` varchar(50) DEFAULT NULL,
  `assessment` varchar(50) DEFAULT NULL,
  `audit` varchar(50) DEFAULT NULL,
  `approval` varchar(50) DEFAULT NULL,
  `payrollAccess` varchar(50) DEFAULT NULL,
  `other` text DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `verification_at` datetime DEFAULT NULL,
  `verification_by` varchar(100) DEFAULT NULL,
  `verification_comment` text DEFAULT NULL,
  `writeUp_at` datetime DEFAULT NULL,
  `writeUp_by` varchar(100) DEFAULT NULL,
  `writeUp_comment` text DEFAULT NULL,
  `fileCreation_at` datetime DEFAULT NULL,
  `fileCreation_by` varchar(100) DEFAULT NULL,
  `fileCreation_comment` text DEFAULT NULL,
  `entrantAllocation_at` datetime DEFAULT NULL,
  `entrantAllocation_by` varchar(100) DEFAULT NULL,
  `entrantAllocation_comment` text DEFAULT NULL,
  `dataCapture_at` datetime DEFAULT NULL,
  `dataCapture_by` varchar(100) DEFAULT NULL,
  `dataCapture_comment` text DEFAULT NULL,
  `assessment_at` datetime DEFAULT NULL,
  `assessment_by` varchar(100) DEFAULT NULL,
  `assessment_comment` text DEFAULT NULL,
  `audit_at` datetime DEFAULT NULL,
  `audit_by` varchar(100) DEFAULT NULL,
  `audit_comment` text DEFAULT NULL,
  `approval_at` datetime DEFAULT NULL,
  `approval_by` varchar(100) DEFAULT NULL,
  `approval_comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_appnsubmissions`
--

CREATE TABLE `tb_appnsubmissions` (
  `id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `sName` varchar(100) DEFAULT NULL,
  `fName` varchar(100) DEFAULT NULL,
  `appnType` enum('Pension','Gratuity','Arrears','Full Pension','Underpayment') DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `retirementDate` date DEFAULT NULL,
  `retirementType` varchar(50) DEFAULT NULL,
  `submissionDate` date DEFAULT NULL,
  `comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_app_settings`
--

CREATE TABLE `tb_app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_app_settings`
--

INSERT INTO `tb_app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('allow_multiple_devices', '1', '2026-03-28 12:14:10'),
('analytics_anomaly_detection_enabled', '1', '2026-03-21 15:15:06'),
('analytics_auto_digest_enabled', '1', '2026-03-21 15:15:06'),
('analytics_dashboard_snapshots_enabled', '1', '2026-03-21 15:15:06'),
('analytics_digest_frequency', 'weekly', '2026-03-21 15:15:06'),
('analytics_digest_recipient', 'etopat2@gmail.com', '2026-03-21 15:15:06'),
('analytics_digest_time', '21:14', '2026-03-21 15:15:06'),
('analytics_export_enabled', '1', '2026-03-21 15:15:06'),
('analytics_include_financial_forecasts', '1', '2026-03-21 15:15:06'),
('analytics_include_operational_kpis', '1', '2026-03-21 15:15:06'),
('analytics_refresh_interval_minutes', '15', '2026-03-21 15:15:06'),
('analytics_show_predictive_cards', '1', '2026-03-21 15:15:06'),
('analytics_snapshot_retention_days', '365', '2026-03-21 15:15:06'),
('app_name', 'PensionsGo', '2026-03-28 12:18:15'),
('app_tagline', 'Unified pension administration', '2026-03-28 12:18:15'),
('attachment_allowed_types', 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx', '2026-03-13 17:56:05'),
('attachment_compress_enabled', '1', '2026-03-13 17:56:05'),
('attachment_dedupe_enabled', '1', '2026-03-13 17:56:05'),
('attachment_max_size_mb', '25', '2026-03-13 17:56:05'),
('attachment_retention_days', '365', '2026-03-13 17:56:05'),
('attachment_scan_enabled', '1', '2026-03-13 17:56:05'),
('auto_logout_on_conflict', '1', '2026-03-28 12:14:10'),
('currency', 'UGX', '2026-03-28 12:18:15'),
('date_format', 'DD/MM/YYYY', '2026-03-28 12:18:15'),
('default_user_role', 'user', '2026-03-28 12:18:15'),
('enable_activity_logs', '1', '2026-03-28 12:14:10'),
('enable_audit_logs', '1', '2026-03-28 12:14:10'),
('enable_notifications', '1', '2026-03-28 12:18:15'),
('enforce_mfa_admin', '1', '2026-02-11 11:00:38'),
('geolocation_enabled', '1', '2026-02-10 10:23:10'),
('grace_period_minutes', '5', '2026-03-28 12:14:09'),
('lockout_minutes', '15', '2026-03-28 12:14:10'),
('login_attempt_limit', '5', '2026-03-28 12:14:10'),
('login_banner', '', '2026-03-28 12:18:15'),
('log_retention_days', '90', '2026-03-28 12:18:15'),
('maintenance_mode', '0', '2026-03-28 12:18:15'),
('max_concurrent_sessions', '2', '2026-03-28 12:14:10'),
('notify_admin_digest_enabled', '1', '2026-03-14 04:31:40'),
('notify_broadcast_enabled', '1', '2026-03-14 04:31:40'),
('notify_digest_time', '07:30', '2026-03-14 04:31:40'),
('notify_email_enabled', '1', '2026-03-14 04:31:40'),
('notify_push_enabled', '1', '2026-03-14 04:31:40'),
('notify_queue_batch_size', '10', '2026-03-14 04:31:40'),
('notify_queue_last_run_at', '2026-03-29 15:39:14', '2026-03-29 10:39:14'),
('notify_queue_min_interval_seconds', '60', '2026-03-14 04:31:40'),
('notify_queue_process_on_request', '0', '2026-03-14 04:31:40'),
('notify_queue_retry_delay_minutes', '10', '2026-03-14 04:31:40'),
('notify_queue_retry_limit', '3', '2026-03-14 04:31:40'),
('notify_queue_worker_enabled', '1', '2026-03-14 04:31:40'),
('notify_quiet_hours_end', '06:00', '2026-03-14 04:31:40'),
('notify_quiet_hours_start', '22:00', '2026-03-14 04:31:40'),
('notify_sender_email', '', '2026-03-14 04:31:40'),
('notify_sender_name', 'PensionsGo Notifications', '2026-03-14 04:31:40'),
('notify_sms_enabled', '0', '2026-03-14 04:31:40'),
('notify_system_alerts_enabled', '1', '2026-03-14 04:31:40'),
('notify_task_alerts_enabled', '1', '2026-03-28 12:14:10'),
('notify_test_recipient', 'etopat2@gmail.com', '2026-03-14 04:31:40'),
('notify_user_activity_enabled', '0', '2026-03-14 04:31:40'),
('password_expiry_days', '0', '2026-03-28 12:14:10'),
('password_min_length', '8', '2026-03-28 12:14:10'),
('password_require_lowercase', '1', '2026-03-28 12:14:10'),
('password_require_number', '1', '2026-03-28 12:14:10'),
('password_require_special', '0', '2026-03-28 12:14:10'),
('password_require_uppercase', '1', '2026-03-28 12:14:10'),
('payroll_reconcile_debounce_seconds', '60', '2026-03-28 12:14:10'),
('pensioner_dashboard_enable_activity_log', '1', '2026-03-28 12:18:15'),
('pensioner_dashboard_enable_claims', '1', '2026-03-28 12:18:15'),
('pensioner_dashboard_enable_documents', '1', '2026-03-28 12:18:15'),
('pensioner_dashboard_enable_status_explanations', '1', '2026-03-28 12:18:15'),
('pensioner_login_enabled', '1', '2026-03-28 12:18:15'),
('pensioner_lookup_enabled', '1', '2026-03-28 12:18:15'),
('pensioner_lookup_log_activity', '1', '2026-03-28 12:18:15'),
('pensioner_lookup_require_consent', '1', '2026-03-28 12:18:15'),
('public_footer_address', 'Luzira - Nakawa, Kampala (U)', '2026-03-28 12:18:15'),
('public_footer_developer_email', 'etomet2patrick@gmail.com', '2026-03-28 12:18:15'),
('public_footer_developer_name', 'Patrick', '2026-03-28 12:18:15'),
('public_footer_developer_phone', '+256773959039', '2026-03-28 12:18:15'),
('public_footer_org_name', 'Uganda Prisons Service Headquarters', '2026-03-28 12:18:15'),
('public_footer_social_facebook', 'https://www.facebook.com/UPSRetirement', '2026-03-28 12:18:15'),
('public_footer_social_instagram', 'https://www.instagram.com/UPSRetirement', '2026-03-28 12:18:15'),
('public_footer_social_linkedin', 'https://www.linkedin.com/company/UPSRetirement', '2026-03-28 12:18:15'),
('public_footer_social_twitter', 'https://www.twitter.com/UPSRetirement', '2026-03-28 12:18:15'),
('public_footer_tech_support_email', 'etopat2@gmail.com', '2026-03-28 12:18:15'),
('require_mfa', '0', '2026-02-11 11:00:38'),
('security_admin_reauth_window_minutes', '10', '2026-03-28 12:14:10'),
('security_alert_email', 'etomet2patrick@gmail.com', '2026-03-28 12:14:10'),
('security_alert_sms', '', '2026-03-28 12:14:10'),
('security_allowed_origins', '', '2026-03-28 12:14:10'),
('security_block_context_menu', '0', '2026-03-28 12:14:10'),
('security_block_copy', '0', '2026-03-28 12:14:10'),
('security_block_cut', '1', '2026-03-28 12:14:10'),
('security_block_developer_tools', '0', '2026-03-28 12:14:10'),
('security_block_drag', '1', '2026-03-28 12:14:10'),
('security_block_paste', '0', '2026-03-28 12:14:10'),
('security_block_text_selection', '0', '2026-03-28 12:14:10'),
('security_enforce_csrf', '1', '2026-03-28 12:14:10'),
('security_max_import_rows', '5000', '2026-03-28 12:14:10'),
('security_max_upload_size_mb', '25', '2026-03-28 12:14:10'),
('security_max_zip_entries', '2000', '2026-03-28 12:14:10'),
('security_max_zip_uncompressed_mb', '64', '2026-03-28 12:14:10'),
('security_validate_origin', '1', '2026-03-28 12:14:10'),
('session_idle_warning_minutes', '5', '2026-03-28 12:14:10'),
('session_timeout_minutes', '30', '2026-03-28 12:14:09'),
('support_email', 'etomet2patrick@gmail.com', '2026-03-28 12:18:15'),
('support_phone', '+256773959039', '2026-03-28 12:18:15'),
('task_alerts_enabled', '1', '2026-03-28 12:14:10'),
('task_alert_due_soon_hours', '24', '2026-03-28 12:14:10'),
('task_alert_escalation_hours', '24', '2026-03-28 12:14:10'),
('task_alert_stalled_hours', '72', '2026-03-28 12:14:10'),
('task_due_business_days', '3', '2026-03-28 12:14:09'),
('task_grace_business_days', '0', '2026-03-28 12:14:09'),
('task_skip_ug_holidays', '1', '2026-03-28 12:14:10'),
('task_skip_weekends', '1', '2026-03-28 12:14:10'),
('timezone', 'Africa/Kampala', '2026-03-28 12:18:15'),
('time_format', '24h', '2026-03-28 12:18:15'),
('user_role_data_normalized_v2', '1', '2026-02-17 04:12:29');

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrearstracking`
--

CREATE TABLE `tb_arrearstracking` (
  `id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `arrearsType` enum('Pension','Gratuity') DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `periodStart` date DEFAULT NULL,
  `periodEnd` date DEFAULT NULL,
  `recordedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `recordedBy` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_accountability_files`
--

CREATE TABLE `tb_arrears_accountability_files` (
  `file_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_accountability_submissions`
--

CREATE TABLE `tb_arrears_accountability_submissions` (
  `submission_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `claim_type` varchar(80) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `status` enum('Submitted') NOT NULL DEFAULT 'Submitted',
  `notes` text DEFAULT NULL,
  `submitted_by` varchar(100) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_ledger`
--

CREATE TABLE `tb_arrears_ledger` (
  `ledger_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `claim_type` varchar(80) NOT NULL,
  `period_year` int(11) NOT NULL,
  `period_month` tinyint(4) NOT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `quarter_label` varchar(6) NOT NULL,
  `expected_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Partially Paid','Paid','Waived') NOT NULL DEFAULT 'Pending',
  `source_type` varchar(40) NOT NULL DEFAULT 'missed_payment',
  `reference_cycle_id` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `settled_at` datetime DEFAULT NULL,
  `accountability_required` tinyint(1) NOT NULL DEFAULT 0,
  `accountability_status` varchar(40) DEFAULT NULL,
  `claim_status` varchar(20) NOT NULL DEFAULT 'Incomplete'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_payments`
--

CREATE TABLE `tb_arrears_payments` (
  `payment_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `claim_type` varchar(80) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `applied_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unapplied_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `reference_no` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_financial_year_label` varchar(20) DEFAULT NULL,
  `accountability_required` tinyint(1) NOT NULL DEFAULT 0,
  `accountability_status` varchar(40) DEFAULT NULL,
  `accountability_submitted_at` datetime DEFAULT NULL,
  `latest_submission_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_payment_allocations`
--

CREATE TABLE `tb_arrears_payment_allocations` (
  `allocation_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `ledger_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `claim_type` varchar(80) NOT NULL,
  `applied_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `accrual_financial_year_label` varchar(20) DEFAULT NULL,
  `payment_financial_year_label` varchar(20) DEFAULT NULL,
  `requires_accountability` tinyint(1) NOT NULL DEFAULT 0,
  `accountability_status` enum('Not Required','Pending Accountability','Accountability Submitted') NOT NULL DEFAULT 'Not Required',
  `accountability_submission_id` int(11) DEFAULT NULL,
  `accountability_submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_audit_logs`
--

CREATE TABLE `tb_audit_logs` (
  `audit_id` int(11) NOT NULL,
  `actor_id` varchar(100) NOT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_backup_logs`
--

CREATE TABLE `tb_backup_logs` (
  `backup_id` int(11) NOT NULL,
  `backup_label` varchar(180) DEFAULT NULL,
  `backup_type` enum('manual','auto','restore_point') NOT NULL DEFAULT 'manual',
  `backup_scope` enum('full_system','database_only','uploads_only') NOT NULL DEFAULT 'full_system',
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `checksum_sha256` varchar(128) DEFAULT NULL,
  `include_uploads` tinyint(1) NOT NULL DEFAULT 1,
  `backup_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('success','failed','restored','partial') NOT NULL DEFAULT 'success',
  `notes` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(80) DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `restored_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_broadcast_messages`
--

CREATE TABLE `tb_broadcast_messages` (
  `broadcast_id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `target_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_roles`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_budgetforecast`
--

CREATE TABLE `tb_budgetforecast` (
  `id` int(11) NOT NULL,
  `financialYear` year(4) DEFAULT NULL,
  `estimatedPensionAmount` decimal(12,2) DEFAULT NULL,
  `estimatedGratuityAmount` decimal(12,2) DEFAULT NULL,
  `createdBy` varchar(100) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `estimatedPensionArrears` decimal(14,2) DEFAULT NULL,
  `estimatedFullPensionArrears` decimal(14,2) DEFAULT NULL,
  `estimatedGratuityArrears` decimal(14,2) DEFAULT NULL,
  `estimatedUnderpaymentClaims` decimal(14,2) DEFAULT NULL,
  `estimatedSuspensionArrears` decimal(14,2) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_claimstatus`
--

CREATE TABLE `tb_claimstatus` (
  `id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `computerNo` varchar(50) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `appnType` varchar(50) DEFAULT NULL,
  `verificationDate` date DEFAULT NULL,
  `appnStatus` varchar(50) DEFAULT NULL,
  `comment` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_data_export_runs`
--

CREATE TABLE `tb_data_export_runs` (
  `export_id` int(11) NOT NULL,
  `dataset_key` varchar(80) NOT NULL,
  `dataset_label` varchar(180) NOT NULL,
  `export_format` enum('csv','xlsx','json') NOT NULL DEFAULT 'xlsx',
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size_bytes` bigint(20) NOT NULL DEFAULT 0,
  `filters_json` longtext DEFAULT NULL,
  `status` enum('success','failed') NOT NULL DEFAULT 'success',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(100) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_data_import_runs`
--

CREATE TABLE `tb_data_import_runs` (
  `import_run_id` int(11) NOT NULL,
  `dataset_key` varchar(60) NOT NULL,
  `dataset_label` varchar(160) NOT NULL,
  `source_file_name` varchar(255) DEFAULT NULL,
  `source_extension` varchar(12) DEFAULT NULL,
  `execution_mode` enum('dry_run','import') NOT NULL DEFAULT 'import',
  `run_status` enum('success','partial','failed') NOT NULL DEFAULT 'success',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `inserted_rows` int(11) NOT NULL DEFAULT 0,
  `merged_rows` int(11) NOT NULL DEFAULT 0,
  `skipped_exact_rows` int(11) NOT NULL DEFAULT 0,
  `conflict_rows` int(11) NOT NULL DEFAULT 0,
  `invalid_rows` int(11) NOT NULL DEFAULT 0,
  `failed_rows` int(11) NOT NULL DEFAULT 0,
  `report_json` longtext DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(60) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_faq_entries`
--

CREATE TABLE `tb_faq_entries` (
  `faq_id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `bullets` text DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'applications',
  `audience_label` varchar(120) NOT NULL DEFAULT 'Pensioners, staff, and supervisors',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_faq_entries`
--

INSERT INTO `tb_faq_entries` (`faq_id`, `question`, `answer`, `bullets`, `category`, `audience_label`, `is_featured`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'What does UPS PensionsGo do?', 'UPS PensionsGo is a workflow and records platform for pension administration. It supports application handling, benefits estimation, claims tracking, registry control, payroll visibility, reporting, and pensioner-facing access.', '', 'applications', 'Pensioners, staff, and supervisors', 1, 1, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(2, 'Who can use the platform?', 'The platform serves operational staff, supervisors, administrators, and pensioners. Each user sees only the modules and actions permitted for the assigned role or explicit permissions.', '', 'applications', 'Pensioners, staff, and supervisors', 0, 2, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(3, 'How does an application move through the system?', 'The workflow begins with submission and verification, then moves through authorization, write-up, file creation, data capture, assessment, audit, and approval. Each stage is delegated, monitored, and logged.', '', 'applications', 'Public, pensioners, and staff', 1, 3, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(4, 'Can application status be tracked after submission?', 'The platform records status progression and exposes application tracking so authorized users and pensioners follow the stage of a case with comments or messages where applicable.', '', 'applications', 'Pensioners and staff', 0, 4, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(5, 'Can the system estimate pension benefits before approval?', 'The benefits calculator estimates service-related outputs such as reduced monthly pension, full pension reference, gratuity, annual salary, and length of service using the configured pension logic.', '', 'benefits', 'Public, pensioners, and staff', 1, 5, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(6, 'Are imported registry records able to auto-compute benefits?', 'During pension file registry import, the platform computes benefit snapshot values when required source data is present and target fields are missing.', '', 'benefits', 'Pensioners and staff', 0, 6, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(7, 'What types of arrears can be tracked?', 'The claims area supports pension arrears, full pension arrears, gratuity arrears, combined pension and gratuity arrears, underpayment claims, and related payment-accountability handling.', '', 'claims', 'Pensioners and staff', 1, 7, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(8, 'How does the system treat accountability for arrears paid in a different financial year?', 'When arrears are paid after the financial year of accrual, the system marks the payment as pending accountability and records supporting accountability forms.', '', 'claims', 'Pensioners and staff', 0, 8, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(9, 'Can payroll data be uploaded and matched to pension records?', 'Uploaded payroll files are matched against registry data using supplier numbers and related identifiers so the platform shows payroll status and monthly linkage results.', '', 'claims', 'Operational staff and supervisors', 0, 9, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(10, 'What is the pension file registry used for?', 'The registry is the controlled record of approved pension files. It stores identity, benefits snapshot, compliance data, payroll state, file custody information, and linked documents.', '', 'registry', 'Pensioners and staff', 1, 10, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(11, 'Can file movement be tracked across offices?', 'The file tracking tools record movement in and out of custody, receiving or destination office, movement reasons, timestamps, and return actions for visibility and accountability.', '', 'registry', 'Pensioners and staff', 0, 11, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(12, 'How is a pensioner account created?', 'The system creates or synchronizes a pensioner account when a pension record is created or imported into the pension file registry, using registry-linked pensioner identity data.', '', 'pensioners', 'Pensioners', 0, 12, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(13, 'How does the system protect sensitive pension data?', 'The platform uses authenticated sessions, role-based access, audit trails, controlled exports, configurable security settings, activity logging, and data governance tools to reduce operational risk.', '', 'security', 'Pensioners, staff, and supervisors', 1, 13, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(14, 'Why might access to some actions or pages be denied?', 'Some tools are restricted to certain roles or permission overrides. When a page or action is denied, the current account lacks the required authorization for that operation.', '', 'security', 'Pensioners, staff, and supervisors', 0, 14, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(15, 'Which documents commonly support a retirement application?', 'Supporting documents differ by case, but common evidence includes service-record verification, retirement authority, identity details, and payment details required by the pensions office.', 'Death-related cases include stronger next-of-kin or beneficiary evidence.\nExtra evidence is submitted through the governed workflow path.', 'applications', 'Public, pensioners, and staff', 0, 15, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(16, 'What does verification mean before a task is forwarded?', 'Verification confirms that the current handler has checked the record and that the required checkpoint data for the next workflow stage is present.', 'Verification is distinct from approval.\nThe exact checkpoint changes by workflow stage, such as write-up, data capture, or assessment.', 'applications', 'Operational staff and supervisors', 0, 16, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(17, 'What is the difference between reduced pension and full pension?', 'Reduced pension reflects a lower service-based entitlement than the full benchmark amount. The exact result depends on service history, applicable rules, and retirement context.', 'Reduced pension does not automatically signal an error.\nWhere the approved benefit differs, the approved administrative decision takes precedence.', 'benefits', 'Pensioners and staff', 0, 17, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(18, 'What factors affect gratuity?', 'Gratuity depends on the service and retirement data available to the pension authority, including the retirement context and the inputs used in the applicable pension formula.', 'Service length and validated records matter.\nIncomplete or inconsistent records distort an estimate until corrected.', 'benefits', 'Pensioners and staff', 0, 18, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(19, 'Why might an estimate differ from the approved pension award?', 'An estimate uses the information currently available, while an approved award relies on verified records, policy interpretation, and additional documentation reviewed later in the process.', 'Estimates support planning, not final entitlement determination.\nWhen a result looks unusual, the right step is review and correction, not assumption.', 'benefits', 'Pensioners and staff', 0, 19, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(20, 'Why does the mode of retirement matter?', 'Mode of retirement explains the type of case being handled and changes the supporting evidence, follow-up steps, and interpretation of the record.', 'Mandatory, medical, and death, and discharge-related cases need different handling.\nIt affects actions like which formula to use in computation, which contact or next-of-kin information must be present, e.t.c.', 'benefits', 'Operational staff and supervisors', 0, 20, 1, '2026-03-19 08:09:41', '2026-03-19 09:48:02'),
(21, 'Why can one file have several claims records for different months?', 'A single pensioner has several arrears or payment events across multiple months. The system stores the monthly detail and also exports it by file with subtotals and a grand total.', 'Month-level rows preserve auditability.\nGrouped export improves readability for financial review.', 'claims', 'Operational staff and supervisors', 0, 21, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(22, 'What does accountability mean for arrears?', 'Accountability refers to the evidence or reconciliation needed when a payment must still be justified or traced within the required reporting framework, especially when timing crosses financial periods.', 'It is especially relevant when payment is made later than the accrual period.\nThe system tracks supporting accountability forms and their status.', 'claims', 'Operational staff and supervisors', 0, 22, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(23, 'What do unpaid, partial, or settled claim statuses mean?', 'These statuses describe how much of the expected claim amount has been paid and whether any balance remains to be addressed.', 'Unpaid means the expected amount is still outstanding.\nPartial means some money has been paid but a balance remains.', 'claims', 'Pensioners and staff', 0, 23, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(24, 'What does it mean when a file is marked returned?', 'Marking a file returned means the file has been received back into pension file registry custody. The system records it as a movement from the last holder to registry, not just as a status flag on an older movement row.', 'The receiver is the user who performs the return action.\nThis keeps movement history accurate up to final registry receipt.', 'registry', 'Operational staff and supervisors', 0, 24, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(25, 'What is the recycle bin for deleted registry records?', 'The recycle bin keeps a recoverable reference of deleted registry records so administrators review what was removed, restore a record where appropriate, or purge it according to retention rules.', 'It avoids losing context immediately after deletion.\nRestore and clear actions remain governed.', 'registry', 'Supervisors and administrators', 0, 25, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(26, 'How are indexed documents viewed or downloaded?', 'Common file types are previewed through protected in-app viewers, while non-previewable formats are downloaded through governed endpoints.', 'Protected preview avoids exposing raw storage paths directly.\nThe same approach runs inside the installed PWA without leaving the app window.', 'registry', 'Pensioners and staff', 0, 26, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(27, 'Why is a life certificate requested each year?', 'A life certificate confirms that the pension record remains current and that payment-related compliance continues under the required administrative controls.', 'It is a yearly compliance expectation rather than a casual optional request.\nThe urgency increases through the year if it remains outstanding.', 'pensioners', 'Pensioners', 0, 27, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(28, 'Which personal details can a pensioner update directly?', 'A pensioner updates limited contact-focused fields such as district of residence, phone number, email address, station or retirement location, and next-of-kin details through the governed profile update flow.', 'The update form is prefilled with the current record values.\nDistrict and station fields use searchable governed lists.', 'pensioners', 'Pensioners', 0, 28, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(29, 'How does the pensioner search directory protect privacy?', 'The pensioner directory is governed by consent settings. A pensioner decides whether fellow pensioners see the contact details through the lookup tool.', 'The pensioner switches visibility on or off from profile settings.\nOnly pensioners who agree to be visible appear in results.', 'pensioners', 'Pensioners', 0, 29, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(30, 'What if a pensioner cannot log in?', 'A pensioner is blocked from logging in if the portal is disabled administratively, if the account is not properly linked, or if credentials or session paths are invalid.', 'The login flow explains clearly when pensioner login is disabled.\nLinked registry and pensioner user records are checked where an account appears missing.', 'pensioners', 'Pensioners', 0, 30, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(31, 'Why does the system sometimes require re-authentication?', 'Re-authentication confirms that the current user still intends to access a high-sensitivity page or continue from an active session after navigating into a public route or reopening a tab.', 'It protects against accidental access from stale sessions.\nAfter successful login again, the user is returned to the relevant page.', 'security', 'Pensioners, staff, and supervisors', 0, 31, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(32, 'What does virus scanning do for uploaded files?', 'Virus scanning checks uploaded files for suspicious or infected content before the files are accepted into the platform\'s document or message storage paths.', 'The app uses native ClamAV where available.\nA heuristic fallback still flags suspicious content when a native scanner is unavailable.', 'security', 'Operational staff and supervisors', 0, 32, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41'),
(33, 'How does messaging protect recipient privacy?', 'Recipient lists and read receipts stay visible only to the sender where privacy requires it, so co-recipients do not see each other unless the message design explicitly allows that.', 'Broadcast and group-message recipients do not automatically see co-recipients.\nProfile photos and attachments are served through controlled endpoints.', 'security', 'Pensioners, staff, and supervisors', 0, 33, 1, '2026-03-19 08:09:41', '2026-03-19 08:09:41');

-- --------------------------------------------------------

--
-- Table structure for table `tb_feedback_activity`
--

CREATE TABLE `tb_feedback_activity` (
  `activity_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `action` varchar(80) NOT NULL,
  `actor_id` varchar(100) DEFAULT NULL,
  `actor_name` varchar(180) DEFAULT NULL,
  `actor_role` varchar(60) DEFAULT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `field_changes` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_feedback_submissions`
--

CREATE TABLE `tb_feedback_submissions` (
  `submission_id` int(11) NOT NULL,
  `reference_no` varchar(40) NOT NULL,
  `feedback_type` varchar(60) NOT NULL DEFAULT 'general_feedback',
  `audience` enum('public','staff','pensioner') NOT NULL DEFAULT 'public',
  `full_name` varchar(180) NOT NULL,
  `email_address` varchar(190) DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `subject` varchar(220) NOT NULL,
  `message` text NOT NULL,
  `page_context` varchar(255) DEFAULT NULL,
  `submitted_by_user_id` varchar(100) DEFAULT NULL,
  `submitted_by_role` varchar(60) DEFAULT NULL,
  `status` enum('new','reviewed','resolved','closed') NOT NULL DEFAULT 'new',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `assigned_to_user_id` varchar(100) DEFAULT NULL,
  `assigned_to_name` varchar(180) DEFAULT NULL,
  `assigned_to_role` varchar(60) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` varchar(100) DEFAULT NULL,
  `reviewed_by_name` varchar(180) DEFAULT NULL,
  `reviewed_by_role` varchar(60) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` varchar(100) DEFAULT NULL,
  `resolved_by_name` varchar(180) DEFAULT NULL,
  `resolved_by_role` varchar(60) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by_user_id` varchar(100) DEFAULT NULL,
  `closed_by_name` varchar(180) DEFAULT NULL,
  `closed_by_role` varchar(60) DEFAULT NULL,
  `resolution_summary` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_fileregistry`
--

CREATE TABLE `tb_fileregistry` (
  `id` int(11) NOT NULL,
  `computerNo` varchar(50) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `sName` varchar(100) DEFAULT NULL,
  `fName` varchar(100) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `livingStatus` enum('Alive','Deceased') DEFAULT NULL,
  `lifeCertificate` enum('Submitted','Not Submitted','Exempt') DEFAULT 'Not Submitted',
  `boxNo` varchar(50) DEFAULT NULL,
  `birthDate` date DEFAULT NULL,
  `enlistmentDate` date DEFAULT NULL,
  `retirementDate` date DEFAULT NULL,
  `retirementType` varchar(50) DEFAULT NULL,
  `TIN` varchar(50) DEFAULT NULL,
  `NIN` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `payrollStatus` enum('On Payroll','Not on Payroll') DEFAULT 'Not on Payroll',
  `payType` varchar(50) DEFAULT NULL,
  `dateOn15yrs` date DEFAULT NULL,
  `periodTo15yrs` varchar(120) DEFAULT NULL,
  `periodFrom15yrs` varchar(120) DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `other` text DEFAULT NULL,
  `availability_status` varchar(40) DEFAULT 'in_shelf',
  `availability_reason` text DEFAULT NULL,
  `telNo` varchar(50) DEFAULT NULL,
  `applicant_email` varchar(120) DEFAULT NULL,
  `next_of_kin` varchar(120) DEFAULT NULL,
  `next_of_kin_contact` varchar(50) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(80) DEFAULT NULL,
  `bank_branch` varchar(120) DEFAULT NULL,
  `lookup_contact_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `lookup_contact_updated_at` datetime DEFAULT NULL,
  `monthlySalary` decimal(12,2) DEFAULT NULL,
  `lengthOfService` int(11) DEFAULT NULL,
  `annualSalary` decimal(12,2) DEFAULT NULL,
  `reducedPension` decimal(12,2) DEFAULT NULL,
  `fullPension` decimal(12,2) DEFAULT NULL,
  `gratuity` decimal(12,2) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_by_name` varchar(100) DEFAULT NULL,
  `deleted_by_role` varchar(50) DEFAULT NULL,
  `delete_reason` text DEFAULT NULL,
  `workflow_auto_arrears_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `workflow_auto_arrears_enabled_at` datetime DEFAULT NULL,
  `workflow_auto_arrears_source` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_file_movements`
--

CREATE TABLE `tb_file_movements` (
  `movement_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `file_id` int(11) DEFAULT NULL,
  `from_office` varchar(120) DEFAULT NULL,
  `to_office` varchar(120) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `delivered_by` varchar(100) DEFAULT NULL,
  `received_by` varchar(100) DEFAULT NULL,
  `moved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expected_return_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_file_registry_delete_requests`
--

CREATE TABLE `tb_file_registry_delete_requests` (
  `request_id` int(11) NOT NULL,
  `registry_id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `requested_by` varchar(100) NOT NULL,
  `requested_by_name` varchar(100) DEFAULT NULL,
  `requested_by_role` varchar(50) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `processed_by` varchar(100) DEFAULT NULL,
  `processed_by_name` varchar(100) DEFAULT NULL,
  `processed_by_role` varchar(50) DEFAULT NULL,
  `processed_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `staff_name` varchar(160) DEFAULT NULL,
  `staff_title` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_file_registry_recycle_bin`
--

CREATE TABLE `tb_file_registry_recycle_bin` (
  `recycle_id` int(11) NOT NULL,
  `registry_id` int(11) DEFAULT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `staff_name` varchar(160) DEFAULT NULL,
  `staff_title` varchar(120) DEFAULT NULL,
  `delete_request_id` int(11) DEFAULT NULL,
  `delete_reason` text DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_by_name` varchar(100) DEFAULT NULL,
  `deleted_by_role` varchar(50) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `record_snapshot` longtext NOT NULL,
  `restored` tinyint(1) NOT NULL DEFAULT 0,
  `restored_by` varchar(100) DEFAULT NULL,
  `restored_by_name` varchar(100) DEFAULT NULL,
  `restored_by_role` varchar(50) DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_file_scan_logs`
--

CREATE TABLE `tb_file_scan_logs` (
  `scan_id` bigint(20) UNSIGNED NOT NULL,
  `storage_context` varchar(80) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `scan_engine` varchar(80) NOT NULL DEFAULT 'heuristic',
  `scan_status` enum('clean','infected','suspicious','error','skipped') NOT NULL DEFAULT 'clean',
  `findings` text DEFAULT NULL,
  `scanned_by` varchar(50) DEFAULT NULL,
  `scanned_by_name` varchar(150) DEFAULT NULL,
  `scanned_by_role` varchar(80) DEFAULT NULL,
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_gratuity_schedule_allocations`
--

CREATE TABLE `tb_gratuity_schedule_allocations` (
  `allocation_id` int(11) NOT NULL,
  `cycle_id` int(11) NOT NULL,
  `entry_id` int(11) NOT NULL,
  `matched_regNo` varchar(50) NOT NULL,
  `ledger_id` int(11) DEFAULT NULL,
  `period_year` int(11) NOT NULL,
  `period_month` tinyint(4) NOT NULL,
  `claim_type` varchar(80) NOT NULL DEFAULT 'Pension Arrears',
  `allocated_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `monthly_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `allocation_status` varchar(30) NOT NULL DEFAULT 'scheduled',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_gratuity_schedule_cycles`
--

CREATE TABLE `tb_gratuity_schedule_cycles` (
  `cycle_id` int(11) NOT NULL,
  `schedule_year` int(11) NOT NULL,
  `schedule_month` tinyint(4) NOT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `quarter_label` varchar(6) NOT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `source_file` varchar(255) DEFAULT NULL,
  `source_file_original_name` varchar(255) DEFAULT NULL,
  `source_file_mime` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `matched_rows` int(11) NOT NULL DEFAULT 0,
  `unmatched_rows` int(11) NOT NULL DEFAULT 0,
  `exact_gratuity_rows` int(11) NOT NULL DEFAULT 0,
  `partial_gratuity_rows` int(11) NOT NULL DEFAULT 0,
  `small_surplus_rows` int(11) NOT NULL DEFAULT 0,
  `pension_arrears_rows` int(11) NOT NULL DEFAULT 0,
  `review_rows` int(11) NOT NULL DEFAULT 0,
  `total_scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_gratuity_component` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_small_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_pension_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_allocated_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_unallocated_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_remaining_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_gratuity_schedule_entries`
--

CREATE TABLE `tb_gratuity_schedule_entries` (
  `entry_id` int(11) NOT NULL,
  `cycle_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL DEFAULT 0,
  `regNo` varchar(50) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `beneficiary_name` varchar(180) DEFAULT NULL,
  `scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `matched_regNo` varchar(50) DEFAULT NULL,
  `matched_registry_id` int(11) DEFAULT NULL,
  `matched_name` varchar(180) DEFAULT NULL,
  `registry_gratuity_estimate` decimal(14,2) NOT NULL DEFAULT 0.00,
  `latest_monthly_pension` decimal(14,2) NOT NULL DEFAULT 0.00,
  `monthly_pension_source` varchar(40) DEFAULT NULL,
  `open_pension_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `open_pension_arrears_months` int(11) NOT NULL DEFAULT 0,
  `gratuity_component_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `pension_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `small_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `allocated_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `scheduled_full_months` int(11) NOT NULL DEFAULT 0,
  `allocated_months` int(11) NOT NULL DEFAULT 0,
  `unallocated_scheduled_months` int(11) NOT NULL DEFAULT 0,
  `unallocated_scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remaining_arrears_months` int(11) NOT NULL DEFAULT 0,
  `remaining_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `classification` varchar(80) NOT NULL DEFAULT 'review',
  `matching_basis` varchar(40) DEFAULT NULL,
  `analysis_note` varchar(255) DEFAULT NULL,
  `raw_payload` text DEFAULT NULL,
  `is_matched` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_ip_geolocation`
--

CREATE TABLE `tb_ip_geolocation` (
  `ip_address` varchar(45) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `latitude` decimal(10,6) DEFAULT NULL,
  `longitude` decimal(10,6) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `org` varchar(150) DEFAULT NULL,
  `asn` varchar(50) DEFAULT NULL,
  `location_label` varchar(255) DEFAULT NULL,
  `raw_json` text DEFAULT NULL,
  `last_lookup` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tb_ip_geolocation`
--

INSERT INTO `tb_ip_geolocation` (`ip_address`, `city`, `region`, `country`, `country_code`, `latitude`, `longitude`, `timezone`, `org`, `asn`, `location_label`, `raw_json`, `last_lookup`) VALUES
('196.0.5.114', 'Kampala', 'Central Region', 'Uganda', 'UG', 0.321000, 32.571400, 'Africa/Kampala', 'UGANDA-TELECOM Uganda Telecom', 'AS21491', 'Kampala, Central Region, Uganda', '{\"ip\":\"196.0.5.114\",\"city\":\"Kampala\",\"region\":\"Central Region\",\"country\":\"Uganda\",\"country_code\":\"UG\",\"latitude\":0.321,\"longitude\":32.5714,\"timezone\":\"Africa/Kampala\",\"org\":\"UGANDA-TELECOM Uganda Telecom\",\"asn\":\"AS21491\"}', '2026-03-30 06:36:32');

-- --------------------------------------------------------

--
-- Table structure for table `tb_lifecertificates`
--

CREATE TABLE `tb_lifecertificates` (
  `id` int(11) NOT NULL,
  `computerNo` varchar(50) DEFAULT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `sName` varchar(100) DEFAULT NULL,
  `fName` varchar(100) DEFAULT NULL,
  `nextOfKin` varchar(100) DEFAULT NULL,
  `nokContact` varchar(50) DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_life_certificate_submissions`
--

CREATE TABLE `tb_life_certificate_submissions` (
  `submission_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `submission_year` int(11) NOT NULL,
  `status` enum('Submitted') NOT NULL DEFAULT 'Submitted',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_by` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_messages`
--

CREATE TABLE `tb_messages` (
  `message_id` int(11) NOT NULL,
  `sender_id` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message_text` text NOT NULL,
  `message_type` enum('direct','broadcast','group') DEFAULT 'direct',
  `parent_message_id` int(11) DEFAULT NULL,
  `is_urgent` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `is_deleted_by_sender` tinyint(1) DEFAULT 0,
  `deleted_by_sender_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_message_attachments`
--

CREATE TABLE `tb_message_attachments` (
  `attachment_id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_hash` varchar(64) DEFAULT NULL,
  `is_compressed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_message_recipients`
--

CREATE TABLE `tb_message_recipients` (
  `recipient_id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `recipient_user_id` varchar(100) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_message_storage_snapshots`
--

CREATE TABLE `tb_message_storage_snapshots` (
  `snapshot_id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_date` date NOT NULL,
  `snapshot_type` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `status` enum('created','failed') NOT NULL DEFAULT 'created',
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `message_count` int(11) NOT NULL DEFAULT 0,
  `attachment_count` int(11) NOT NULL DEFAULT 0,
  `total_storage_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_notification_digest_runs`
--

CREATE TABLE `tb_notification_digest_runs` (
  `digest_id` bigint(20) UNSIGNED NOT NULL,
  `digest_date` date NOT NULL,
  `run_type` enum('scheduled','manual','preview') NOT NULL DEFAULT 'scheduled',
  `recipient` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `summary_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_by_role` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tb_notification_digest_runs`
--

INSERT INTO `tb_notification_digest_runs` (`digest_id`, `digest_date`, `run_type`, `recipient`, `subject`, `status`, `summary_json`, `notes`, `created_by`, `created_by_name`, `created_by_role`, `created_at`) VALUES
(1, '2026-03-30', 'scheduled', 'etopat2@gmail.com', 'UPS PensionsGo Daily Digest - 30 Mar 2026', 'failed', '{\"active_users\":1,\"queued_notifications\":0,\"workflow_tasks_open\":0,\"workflow_tasks_overdue\":0,\"feedback_open\":0,\"claims_open\":0}', 'Delivery worker update: Mail transport failed to accept the message.', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe8', 'Patrick Etomet', 'admin', '2026-03-30 09:36:55');

-- --------------------------------------------------------

--
-- Table structure for table `tb_notification_queue`
--

CREATE TABLE `tb_notification_queue` (
  `notification_id` int(11) NOT NULL,
  `channel` enum('email','sms','push') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `meta` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attempts` int(11) NOT NULL DEFAULT 0,
  `processing_started_at` datetime DEFAULT NULL,
  `last_attempted_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `provider_reference` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_notification_queue`
--

INSERT INTO `tb_notification_queue` (`notification_id`, `channel`, `recipient`, `subject`, `message`, `status`, `meta`, `created_at`, `attempts`, `processing_started_at`, `last_attempted_at`, `sent_at`, `failed_at`, `last_error`, `provider_reference`) VALUES
(1, 'email', 'etopat2@gmail.com', 'UPS PensionsGo Daily Digest - 30 Mar 2026', 'Daily operational digest generated on 30 Mar 2026 08:36\n\nActive users: 1\nQueued notifications: 0\nOpen workflow tasks: 0\nOverdue workflow tasks: 0\nOpen feedback items: 0\nOpen claims items: 0\n\nReview the Admin Dashboard for detailed analysis and follow-up actions.', 'failed', '{\"source\":\"daily_digest\",\"digest_id\":1,\"summary\":{\"active_users\":1,\"queued_notifications\":0,\"workflow_tasks_open\":0,\"workflow_tasks_overdue\":0,\"feedback_open\":0,\"claims_open\":0},\"html_body\":\"<p>Daily operational digest generated on 30 Mar 2026 08:36.</p><ul><li><strong>Active users:</strong> 1</li><li><strong>Queued notifications:</strong> 0</li><li><strong>Open workflow tasks:</strong> 0</li><li><strong>Overdue workflow tasks:</strong> 0</li><li><strong>Open feedback items:</strong> 0</li><li><strong>Open claims items:</strong> 0</li></ul><p>Review the Admin Dashboard for detailed analysis and follow-up actions.</p>\"}', '2026-03-30 06:36:55', 1, NULL, '2026-03-30 09:36:57', NULL, '2026-03-30 09:36:57', 'Mail transport failed to accept the message.', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tb_payrolls`
--

CREATE TABLE `tb_payrolls` (
  `id` int(11) NOT NULL,
  `payrollYear` year(4) DEFAULT NULL,
  `payrollMonth` int(11) DEFAULT NULL,
  `record_type` enum('Pension','Gratuity','Arrears','Suspended') DEFAULT NULL,
  `file_path` text DEFAULT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_arrears`
--

CREATE TABLE `tb_payroll_arrears` (
  `id` int(11) NOT NULL,
  `payrollYear` year(4) DEFAULT NULL,
  `payrollMonth` int(11) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('Paid','Suspended','Pending') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_audit_logs`
--

CREATE TABLE `tb_payroll_audit_logs` (
  `audit_id` int(11) NOT NULL,
  `cycle_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `actor_user_id` varchar(100) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_gratuity`
--

CREATE TABLE `tb_payroll_gratuity` (
  `id` int(11) NOT NULL,
  `payrollYear` year(4) DEFAULT NULL,
  `payrollMonth` int(11) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('Paid','Suspended','Pending') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_pension`
--

CREATE TABLE `tb_payroll_pension` (
  `id` int(11) NOT NULL,
  `payrollYear` year(4) DEFAULT NULL,
  `payrollMonth` int(11) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('Paid','Suspended','Pending') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_suspended`
--

CREATE TABLE `tb_payroll_suspended` (
  `id` int(11) NOT NULL,
  `payrollYear` year(4) DEFAULT NULL,
  `payrollMonth` int(11) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('Paid','Suspended','Pending') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_upload_cycles`
--

CREATE TABLE `tb_payroll_upload_cycles` (
  `cycle_id` int(11) NOT NULL,
  `payroll_year` int(11) NOT NULL,
  `payroll_month` tinyint(4) NOT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `quarter_label` varchar(6) NOT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `source_file` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `source_file_original_name` varchar(255) DEFAULT NULL,
  `source_file_mime` varchar(120) DEFAULT NULL,
  `payment_register_file` varchar(255) DEFAULT NULL,
  `payment_register_original_name` varchar(255) DEFAULT NULL,
  `payment_register_mime` varchar(120) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_upload_entries`
--

CREATE TABLE `tb_payroll_upload_entries` (
  `entry_id` int(11) NOT NULL,
  `cycle_id` int(11) NOT NULL,
  `supplierNo` varchar(50) NOT NULL,
  `beneficiary_name` varchar(150) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `matched_regNo` varchar(50) DEFAULT NULL,
  `matched_registry_id` int(11) DEFAULT NULL,
  `is_matched` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_podcast_videos`
--

CREATE TABLE `tb_podcast_videos` (
  `podcast_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `audience` enum('public','staff','pensioner') NOT NULL DEFAULT 'public',
  `youtube_url` varchar(500) NOT NULL,
  `youtube_id` varchar(32) NOT NULL,
  `tags` text DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` varchar(100) DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_podcast_views`
--

CREATE TABLE `tb_podcast_views` (
  `view_id` int(11) NOT NULL,
  `podcast_id` int(11) NOT NULL,
  `viewer_id` varchar(100) DEFAULT NULL,
  `viewer_role` varchar(50) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_poldistricts`
--

CREATE TABLE `tb_poldistricts` (
  `Id` int(3) NOT NULL,
  `polDistrict` text NOT NULL,
  `polRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_poldistricts`
--

INSERT INTO `tb_poldistricts` (`Id`, `polDistrict`, `polRegion`) VALUES
(1, 'Abim', 'Northern'),
(2, 'Adjumani', 'Northern'),
(3, 'Agago', 'Northern'),
(4, 'Alebtong', 'Northern'),
(5, 'Amolatar', 'Northern'),
(6, 'Amudat', 'Northern'),
(7, 'Amuria', 'Eastern'),
(8, 'Amuru', 'Northern'),
(9, 'Apac', 'Northern'),
(10, 'Arua', 'Northern'),
(11, 'Budaka', 'Eastern'),
(12, 'Bududa', 'Eastern'),
(13, 'Bugiri', 'Eastern'),
(14, 'Bugweri', 'Eastern'),
(15, 'Buhweju', 'Western'),
(16, 'Buikwe', 'Central'),
(17, 'Bukedea', 'Eastern'),
(18, 'Bukomansimbi', 'Central'),
(19, 'Bukwo', 'Eastern'),
(20, 'Bulambuli', 'Eastern'),
(21, 'Buliisa', 'Western'),
(22, 'Bundibugyo', 'Western'),
(23, 'Bunyangabu', 'Western'),
(24, 'Bushenyi', 'Western'),
(25, 'Busia', 'Eastern'),
(26, 'Butaleja', 'Eastern'),
(27, 'Butambala', 'Central'),
(28, 'Butebo', 'Eastern'),
(29, 'Buvuma', 'Central'),
(30, 'Buyende', 'Eastern'),
(31, 'Dokolo', 'Northern'),
(32, 'Gomba', 'Central'),
(33, 'Gulu', 'Northern'),
(34, 'Hoima', 'Western'),
(35, 'Ibanda', 'Western'),
(36, 'Iganga', 'Eastern'),
(37, 'Isingiro', 'Western'),
(38, 'Jinja', 'Eastern'),
(39, 'Kaabong', 'Northern'),
(40, 'Kabale', 'Western'),
(41, 'Kabarole', 'Western'),
(42, 'Kaberamaido', 'Eastern'),
(43, 'Kagadi', 'Western'),
(44, 'Kakumiro', 'Western'),
(45, 'Kalaki', 'Eastern'),
(46, 'Kalangala', 'Central'),
(47, 'Kaliro', 'Eastern'),
(48, 'Kalungu', 'Central'),
(49, 'Kampala', 'Central'),
(50, 'Kamuli', 'Eastern'),
(51, 'Kamwenge', 'Western'),
(52, 'Kanungu', 'Western'),
(53, 'Kapchorwa', 'Eastern'),
(54, 'Kapelebyong', 'Eastern'),
(55, 'Karenga', 'Northern'),
(56, 'Kasanda', 'Central'),
(57, 'Kasese', 'Western'),
(58, 'Katakwi', 'Eastern'),
(59, 'Kayunga', 'Central'),
(60, 'Kazo', 'Western'),
(61, 'Kibaale', 'Western'),
(62, 'Kiboga', 'Central'),
(63, 'Kibuku', 'Eastern'),
(64, 'Kikuube', 'Western'),
(65, 'Kiruhura', 'Western'),
(66, 'Kiryandongo', 'Western'),
(67, 'Kisoro', 'Western'),
(68, 'Kitagwenda', 'Western'),
(69, 'Kitgum', 'Northern'),
(70, 'Koboko', 'Northern'),
(71, 'Kole', 'Northern'),
(72, 'Kotido', 'Northern'),
(73, 'Kumi', 'Eastern'),
(74, 'Kwania', 'Northern'),
(75, 'Kween', 'Eastern'),
(76, 'Kyankwanzi', 'Central'),
(77, 'Kyegegwa', 'Western'),
(78, 'Kyenjojo', 'Western'),
(79, 'Kyotera', 'Central'),
(80, 'Lamwo', 'Northern'),
(81, 'Lira', 'Northern'),
(82, 'Luuka', 'Eastern'),
(83, 'Luweero', 'Central'),
(84, 'Lwengo', 'Central'),
(85, 'Lyantonde', 'Central'),
(86, 'Madi-Okollo', 'Northern'),
(87, 'Manafwa', 'Eastern'),
(88, 'Maracha', 'Northern'),
(89, 'Masaka', 'Central'),
(90, 'Masindi', 'Western'),
(91, 'Mayuge', 'Eastern'),
(92, 'Mbale', 'Eastern'),
(93, 'Mbarara', 'Western'),
(94, 'Mitooma', 'Western'),
(95, 'Mityana', 'Central'),
(96, 'Moroto', 'Northern'),
(97, 'Moyo', 'Northern'),
(98, 'Mpigi', 'Central'),
(99, 'Mubende', 'Central'),
(100, 'Mukono', 'Central'),
(101, 'Nabilatuk', 'Northern'),
(102, 'Nakapiripirit', 'Northern'),
(103, 'Nakaseke', 'Central'),
(104, 'Nakasongola', 'Central'),
(105, 'Namayingo', 'Eastern'),
(106, 'Namisindwa', 'Eastern'),
(107, 'Namutumba', 'Eastern'),
(108, 'Napak', 'Northern'),
(109, 'Nebbi', 'Northern'),
(110, 'Ngora', 'Eastern'),
(111, 'Ntoroko', 'Western'),
(112, 'Ntungamo', 'Western'),
(113, 'Nwoya', 'Northern'),
(114, 'Obongi', 'Northern'),
(115, 'Omoro', 'Northern'),
(116, 'Otuke', 'Northern'),
(117, 'Oyam', 'Northern'),
(118, 'Pader', 'Northern'),
(119, 'Pakwach', 'Northern'),
(120, 'Pallisa', 'Eastern'),
(121, 'Rakai', 'Central'),
(122, 'Rubanda', 'Western'),
(123, 'Rubirizi', 'Western'),
(124, 'Rukiga', 'Western'),
(125, 'Rukungiri', 'Western'),
(126, 'Rwampara', 'Western'),
(127, 'Sembabule', 'Central'),
(128, 'Serere', 'Eastern'),
(129, 'Sheema', 'Western'),
(130, 'Sironko', 'Eastern'),
(131, 'Soroti', 'Eastern'),
(132, 'Terego', 'Northern'),
(133, 'Tororo', 'Eastern'),
(134, 'Wakiso', 'Central'),
(135, 'Yumbe', 'Northern'),
(136, 'Zombo', 'Northern');

-- --------------------------------------------------------

--
-- Table structure for table `tb_pridistricts`
--

CREATE TABLE `tb_pridistricts` (
  `Id` int(3) NOT NULL,
  `priDistrict` text NOT NULL,
  `priRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_pridistricts`
--

INSERT INTO `tb_pridistricts` (`Id`, `priDistrict`, `priRegion`) VALUES
(1, 'Adjumani', 'North Western'),
(2, 'Amuru', 'Northern'),
(3, 'Apac', 'Mid Northern'),
(4, 'Arua', 'North Western'),
(5, 'Budaka', 'Eastern'),
(6, 'Bugiri', 'Iganga'),
(7, 'Buikwe', 'East Central'),
(8, 'Bushenyi', 'South Western'),
(9, 'Gulu', 'Northern'),
(10, 'Hoima', 'Mid Western'),
(11, 'Ibanda', 'South Western'),
(12, 'Iganga', 'Iganga'),
(13, 'Jinja', 'South Eastern'),
(14, 'Kabale', 'Kigezi'),
(15, 'Kabarole', 'Western'),
(16, 'Kaberamaido', 'Mid Eastern'),
(17, 'Kalangala', 'Southern'),
(18, 'Kaliro', 'Iganga'),
(19, 'Kalungu', 'Southern'),
(20, 'Kampala', 'Kampala Extra'),
(21, 'Kamuli', 'South Eastern'),
(22, 'Kapchorwa', 'Eastern'),
(23, 'Kasese', 'Western'),
(24, 'Kayunga', 'East Central'),
(25, 'Kibaale', 'Mid Central'),
(26, 'Kiboga', 'Mid Western'),
(27, 'Kitgum', 'Northern'),
(28, 'Kotido', 'North Eastern'),
(29, 'Kumi', 'Mid Eastern'),
(30, 'Kyenjojo', 'Western'),
(31, 'Lira', 'Mid Northern'),
(32, 'Luweero', 'North Central'),
(33, 'Lwengo', 'Kooki'),
(34, 'Masaka', 'Southern'),
(35, 'Masindi', 'Mid Western'),
(36, 'Mayuge', 'Iganga'),
(37, 'Mbale', 'Eastern'),
(38, 'Mbarara', 'South Western'),
(39, 'Mityana', 'Mid Central'),
(40, 'Moroto', 'North Eastern'),
(41, 'Mpigi', 'Central'),
(42, 'Mubende', 'Mid Central'),
(43, 'Mukono', 'East Central'),
(44, 'Nakaseke', 'North Central'),
(45, 'Nakasongola', 'North Central'),
(46, 'Namutumba', 'Iganga'),
(47, 'Nebbi', 'North Western'),
(48, 'Oyam', 'Mid Northern'),
(49, 'Pallisa', 'Eastern'),
(50, 'Rakai', 'Kooki'),
(51, 'Rukungiri', 'Kigezi'),
(52, 'Sembabule', 'Kooki'),
(53, 'Soroti', 'Mid Eastern'),
(54, 'Tororo', 'Eastern'),
(55, 'Wakiso', 'Central');

-- --------------------------------------------------------

--
-- Table structure for table `tb_priregions`
--

CREATE TABLE `tb_priregions` (
  `Id` int(3) NOT NULL,
  `priRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_priregions`
--

INSERT INTO `tb_priregions` (`Id`, `priRegion`) VALUES
(1, 'Central'),
(2, 'East Central'),
(3, 'Eastern'),
(4, 'Iganga'),
(5, 'Kampala Extra'),
(6, 'Kigezi'),
(7, 'Kooki'),
(8, 'Mid Central'),
(9, 'Mid Eastern'),
(10, 'Mid Northern'),
(11, 'Mid Western'),
(12, 'North Central'),
(13, 'North Eastern'),
(14, 'North Western'),
(15, 'Northern'),
(16, 'South Eastern'),
(17, 'South Western'),
(18, 'Southern'),
(19, 'Western');

-- --------------------------------------------------------

--
-- Table structure for table `tb_priunits`
--

CREATE TABLE `tb_priunits` (
  `Id` int(3) NOT NULL,
  `priUnit` text NOT NULL,
  `polDistrict` text NOT NULL,
  `priDistrict` text NOT NULL,
  `priRegion` text NOT NULL,
  `polRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `tb_priunits`
--

INSERT INTO `tb_priunits` (`Id`, `priUnit`, `polDistrict`, `priDistrict`, `priRegion`, `polRegion`) VALUES
(1, 'Kampala (R)', 'Kampala', 'Kampala', 'Kampala Extra', 'Central'),
(2, 'Luzira (W)', 'Kampala', 'Kampala', 'Kampala Extra', 'Central'),
(3, 'Murchison Bay', 'Kampala', 'Kampala', 'Kampala Extra', 'Central'),
(4, 'Upper', 'Kampala', 'Kampala', 'Kampala Extra', 'Central'),
(5, 'Kigo (M)', 'Wakiso', 'Kampala', 'Kampala Extra', 'Central'),
(6, 'Kigo (W)', 'Wakiso', 'Kampala', 'Kampala Extra', 'Central'),
(7, 'Bamunanika', 'Luweero', 'Luweero', 'North Central', 'Central'),
(8, 'Butuntumura', 'Luweero', 'Luweero', 'North Central', 'Central'),
(9, 'Makulubita', 'Luweero', 'Luweero', 'North Central', 'Central'),
(10, 'Nyimbwa', 'Luweero', 'Luweero', 'North Central', 'Central'),
(11, 'Wabusaana', 'Luweero', 'Luweero', 'North Central', 'Central'),
(12, 'Buwambo', 'Wakiso', 'Luweero', 'North Central', 'Central'),
(13, 'Kapeeka', 'Nakaseke', 'Nakaseke', 'North Central', 'Central'),
(14, 'Ngoma', 'Nakaseke', 'Nakaseke', 'North Central', 'Central'),
(15, 'Wakyato', 'Nakaseke', 'Nakaseke', 'North Central', 'Central'),
(16, 'Nakasongola (M)', 'Nakasongola', 'Nakasongola', 'North Central', 'Central'),
(17, 'Nakasongola (W)', 'Nakasongola', 'Nakasongola', 'North Central', 'Central'),
(18, 'Butoolo', 'Mpigi', 'Mpigi', 'Central', 'Central'),
(19, 'Buwama', 'Mpigi', 'Mpigi', 'Central', 'Central'),
(20, 'Muduuma', 'Mpigi', 'Mpigi', 'Central', 'Central'),
(21, 'Mpigi', 'Mpigi', 'Mpigi', 'Central', 'Central'),
(22, 'Nkozi', 'Mpigi', 'Mpigi', 'Central', 'Central'),
(23, 'Kabasanda', 'Butambala', 'Mpigi', 'Central', 'Central'),
(24, 'Kanoni', 'Gomba', 'Mpigi', 'Central', 'Central'),
(25, 'Kasangati', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(26, 'Kasanje', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(27, 'Kitala', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(28, 'Kitalya', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(29, 'Kitalya Mini M', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(30, 'Sentema', 'Wakiso', 'Wakiso', 'Central', 'Central'),
(31, 'Kauga', 'Mukono', 'Mukono', 'East Central', 'Central'),
(32, 'Koome', 'Mukono', 'Mukono', 'East Central', 'Central'),
(33, 'Nagoje', 'Mukono', 'Mukono', 'East Central', 'Central'),
(34, 'Nakifuma', 'Mukono', 'Mukono', 'East Central', 'Central'),
(35, 'Nakisunga', 'Mukono', 'Mukono', 'East Central', 'Central'),
(36, 'Bugungu YO', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(37, 'Bugungu YP', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(38, 'Buikwe', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(39, 'Lugazi', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(40, 'Ngogwe', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(41, 'Nyenga', 'Buikwe', 'Buikwe', 'East Central', 'Central'),
(42, 'Buvuma', 'Buvuma', 'Buikwe', 'East Central', 'Central'),
(43, 'Bulaula', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(44, 'Busaana', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(45, 'Galilaya', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(46, 'Kangulumira', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(47, 'Kayonza', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(48, 'Ntenjeru', 'Kayunga', 'Kayunga', 'East Central', 'Central'),
(49, 'Kaweeri', 'Mubende', 'Mubende', 'Mid Central', 'Central'),
(50, 'Kijumba', 'Mubende', 'Mubende', 'Mid Central', 'Central'),
(51, 'Muinaina', 'Mubende', 'Mubende', 'Mid Central', 'Central'),
(52, 'Kassanda', 'Kassanda', 'Mubende', 'Mid Central', 'Central'),
(53, 'Myanzi', 'Kassanda', 'Mubende', 'Mid Central', 'Central'),
(54, 'Kitwe', 'Gomba', 'Mubende', 'Mid Central', 'Central'),
(55, 'Kibaale', 'Kibaale', 'Kibaale', 'Mid Central', 'Western'),
(56, 'Kyakasengura', 'Kibaale', 'Kibaale', 'Mid Central', 'Western'),
(57, 'Kagadi', 'Kagadi', 'Kibaale', 'Mid Central', 'Western'),
(58, 'Kakumiro', 'Kakumiro', 'Kibaale', 'Mid Central', 'Western'),
(59, 'Magala', 'Mityana', 'Mityana', 'Mid Central', 'Central'),
(60, 'Mityana (M)', 'Mityana', 'Mityana', 'Mid Central', 'Central'),
(61, 'Mityana (W)', 'Mityana', 'Mityana', 'Mid Central', 'Central'),
(62, 'Mwera', 'Mityana', 'Mityana', 'Mid Central', 'Central'),
(63, 'Buwunga', 'Masaka', 'Masaka', 'Southern', 'Central'),
(64, 'Kabonera', 'Masaka', 'Masaka', 'Southern', 'Central'),
(65, 'Kyanamukaka', 'Masaka', 'Masaka', 'Southern', 'Central'),
(66, 'Masaka (M)', 'Masaka', 'Masaka', 'Southern', 'Central'),
(67, 'Masaka (W)', 'Masaka', 'Masaka', 'Southern', 'Central'),
(68, 'Mukungwe', 'Masaka', 'Masaka', 'Southern', 'Central'),
(69, 'Ssaza', 'Masaka', 'Masaka', 'Southern', 'Central'),
(70, 'Bigasa', 'Bukomansimbi', 'Kalungu', 'Southern', 'Central'),
(71, 'Butenga', 'Bukomansimbi', 'Kalungu', 'Southern', 'Central'),
(72, 'Kitanda', 'Bukomansimbi', 'Kalungu', 'Southern', 'Central'),
(73, 'Bukulula', 'Kalungu', 'Kalungu', 'Southern', 'Central'),
(74, 'Kalungu', 'Kalungu', 'Kalungu', 'Southern', 'Central'),
(75, 'Kyamulibwa', 'Kalungu', 'Kalungu', 'Southern', 'Central'),
(76, 'Lukaya', 'Kalungu', 'Kalungu', 'Southern', 'Central'),
(77, 'Lwabenge', 'Kalungu', 'Kalungu', 'Southern', 'Central'),
(78, 'Kalangala', 'Kalangala', 'Kalangala', 'Southern', 'Central'),
(79, 'Mugoye', 'Kalangala', 'Kalangala', 'Southern', 'Central'),
(80, 'Lwemiyaga', 'Sembabule', 'Sembabule', 'Kooki', 'Central'),
(81, 'Mateete', 'Sembabule', 'Sembabule', 'Kooki', 'Central'),
(82, 'Ntuusi', 'Sembabule', 'Sembabule', 'Kooki', 'Central'),
(83, 'Lwebitakuli', 'Sembabule', 'Sembabule', 'Kooki', 'Central'),
(84, 'Sembabule', 'Sembabule', 'Sembabule', 'Kooki', 'Central'),
(85, 'Kacheera', 'Rakai', 'Rakai', 'Kooki', 'Central'),
(86, 'Kayanja', 'Rakai', 'Rakai', 'Kooki', 'Central'),
(87, 'Lwamaggwa', 'Rakai', 'Rakai', 'Kooki', 'Central'),
(88, 'Rakai', 'Rakai', 'Rakai', 'Kooki', 'Central'),
(89, 'Kabira', 'Kyotera', 'Rakai', 'Kooki', 'Central'),
(90, 'Kakuuto', 'Kyotera', 'Rakai', 'Kooki', 'Central'),
(91, 'Kaliisizo', 'Kyotera', 'Rakai', 'Kooki', 'Central'),
(92, 'Kasaali', 'Kyotera', 'Rakai', 'Kooki', 'Central'),
(93, 'Mutukula', 'Kyotera', 'Rakai', 'Kooki', 'Central'),
(94, 'Kabula', 'Lyantonde', 'Rakai', 'Kooki', 'Central'),
(95, 'Kiseka', 'Lwengo', 'Lwengo', 'Kooki', 'Central'),
(96, 'Kyazanga', 'Lwengo', 'Lwengo', 'Kooki', 'Central'),
(97, 'Lwengo', 'Lwengo', 'Lwengo', 'Kooki', 'Central'),
(98, 'Ndaggwe', 'Lwengo', 'Lwengo', 'Kooki', 'Central'),
(99, 'Ivukula', 'Namutumba', 'Namutumba', 'Iganga', 'Eastern'),
(100, 'Kaiti', 'Namutumba', 'Namutumba', 'Iganga', 'Eastern'),
(101, 'Iganga', 'Iganga', 'Iganga', 'Iganga', 'Eastern'),
(102, 'Namalemba', 'Iganga', 'Iganga', 'Iganga', 'Eastern'),
(103, 'Namungalwe', 'Iganga', 'Iganga', 'Iganga', 'Eastern'),
(104, 'Busesa', 'Bugweri', 'Iganga', 'Iganga', 'Eastern'),
(105, 'Kiyunga', 'Luuka', 'Iganga', 'Iganga', 'Eastern'),
(106, 'Bufulubi', 'Mayuge', 'Mayuge', 'Iganga', 'Eastern'),
(107, 'Ikulwe', 'Mayuge', 'Mayuge', 'Iganga', 'Eastern'),
(108, 'Imanyiro', 'Mayuge', 'Mayuge', 'Iganga', 'Eastern'),
(109, 'Kigandalo', 'Mayuge', 'Mayuge', 'Iganga', 'Eastern'),
(110, 'Kityerera', 'Mayuge', 'Mayuge', 'Iganga', 'Eastern'),
(111, 'Bugiri', 'Bugiri', 'Bugiri', 'Iganga', 'Eastern'),
(112, 'Buyinja', 'Namayingo', 'Bugiri', 'Iganga', 'Eastern'),
(113, 'Kaliro', 'Kaliro', 'Kaliro', 'Iganga', 'Eastern'),
(114, 'Bugembe', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(115, 'Busede', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(116, 'Butagaya', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(117, 'Jinja (M)', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(118, 'Jinja (R)', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(119, 'Jinja (W)', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(120, 'Kagoma', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(121, 'Kakira', 'Jinja', 'Jinja', 'South Eastern', 'Eastern'),
(122, 'Kamuli', 'Kamuli', 'Kamuli', 'South Eastern', 'Eastern'),
(123, 'Nabwigulu', 'Kamuli', 'Kamuli', 'South Eastern', 'Eastern'),
(124, 'Nawanyago', 'Kamuli', 'Kamuli', 'South Eastern', 'Eastern'),
(125, 'Buyende', 'Buyende', 'Kamuli', 'South Eastern', 'Eastern'),
(126, 'Kidera', 'Buyende', 'Kamuli', 'South Eastern', 'Eastern'),
(127, 'Gulu (M)', 'Gulu', 'Gulu', 'Northern', 'Northern'),
(128, 'Gulu (W)', 'Gulu', 'Gulu', 'Northern', 'Northern'),
(129, 'Lugore', 'Gulu', 'Gulu', 'Northern', 'Northern'),
(130, 'Pece', 'Gulu', 'Gulu', 'Northern', 'Northern'),
(131, 'Kaladima', 'Amuru', 'Amuru', 'Northern', 'Northern'),
(132, 'Amuru', 'Amuru', 'Amuru', 'Northern', 'Northern'),
(133, 'Nwoya', 'Nwoya', 'Amuru', 'Northern', 'Northern'),
(134, 'Kitgum', 'Kitgum', 'Kitgum', 'Northern', 'Northern'),
(135, 'Orom-Tikau', 'Kitgum', 'Kitgum', 'Northern', 'Northern'),
(136, 'Lamwo', 'Lamwo', 'Kitgum', 'Northern', 'Northern'),
(137, 'Lotuturu', 'Lamwo', 'Kitgum', 'Northern', 'Northern'),
(138, 'Pader', 'Pader', 'Kitgum', 'Northern', 'Northern'),
(139, 'Patongo (M)', 'Agago', 'Kitgum', 'Northern', 'Northern'),
(140, 'Patongo (W)', 'Agago', 'Kitgum', 'Northern', 'Northern'),
(141, 'Erute', 'Lira', 'Lira', 'Mid Northern', 'Northern'),
(142, 'Lira (M)', 'Lira', 'Lira', 'Mid Northern', 'Northern'),
(143, 'Lira (W)', 'Lira', 'Lira', 'Mid Northern', 'Northern'),
(144, 'Otuke', 'Otuke', 'Lira', 'Mid Northern', 'Northern'),
(145, 'Alebtong', 'Alebtong', 'Lira', 'Mid Northern', 'Northern'),
(146, 'Aloi-Ongom', 'Alebtong', 'Lira', 'Mid Northern', 'Northern'),
(147, 'Awei', 'Alebtong', 'Lira', 'Mid Northern', 'Northern'),
(148, 'Dokolo', 'Dokolo', 'Lira', 'Mid Northern', 'Northern'),
(149, 'Amolatar', 'Amolatar', 'Lira', 'Mid Northern', 'Northern'),
(150, 'Kole', 'Kole', 'Oyam', 'Mid Northern', 'Northern'),
(151, 'Aber', 'Oyam', 'Oyam', 'Mid Northern', 'Northern'),
(152, 'Loro', 'Oyam', 'Oyam', 'Mid Northern', 'Northern'),
(153, 'Oyam (M)', 'Oyam', 'Oyam', 'Mid Northern', 'Northern'),
(154, 'Oyam (W)', 'Oyam', 'Oyam', 'Mid Northern', 'Northern'),
(155, 'Apac', 'Apac', 'Apac', 'Mid Northern', 'Northern'),
(156, 'Arocha', 'Apac', 'Apac', 'Mid Northern', 'Northern'),
(157, 'Maruzi', 'Apac', 'Apac', 'Mid Northern', 'Northern'),
(158, 'Kwania', 'Kwania', 'Apac', 'Mid Northern', 'Northern'),
(159, 'Ibuga', 'Kasese', 'Kasese', 'Western', 'Western'),
(160, 'Bwera', 'Kasese', 'Kasese', 'Western', 'Western'),
(161, 'Mubuku', 'Kasese', 'Kasese', 'Western', 'Western'),
(162, 'Nyabirongo', 'Kasese', 'Kasese', 'Western', 'Western'),
(163, 'Rukooki', 'Kasese', 'Kasese', 'Western', 'Western'),
(164, 'Maliba', 'Kasese', 'Kasese', 'Western', 'Western'),
(165, 'Lake Katwe', 'Kasese', 'Kasese', 'Western', 'Western'),
(166, 'Fort Portal (M)', 'Kabarole', 'Kabarole', 'Western', 'Western'),
(167, 'Fort Portal (W)', 'Kabarole', 'Kabarole', 'Western', 'Western'),
(168, 'Kibiito', 'Bunyangabo', 'Kabarole', 'Western', 'Western'),
(169, 'Ruimi', 'Bunyangabo', 'Kabarole', 'Western', 'Western'),
(170, 'Bubukwanga', 'Bundibugyo', 'Kabarole', 'Western', 'Western'),
(171, 'Butiiti', 'Kyenjojo', 'Kyenjojo', 'Western', 'Western'),
(172, 'New Kyenjojo', 'Kyenjojo', 'Kyenjojo', 'Western', 'Western'),
(173, 'Kyenjojo', 'Kyenjojo', 'Kyenjojo', 'Western', 'Western'),
(174, 'Kyegegwa', 'Kyegegwa', 'Kyenjojo', 'Western', 'Western'),
(175, 'Nakatunya', 'Soroti', 'Soroti', 'Mid Eastern', 'Eastern'),
(176, 'Soroti (M)', 'Soroti', 'Soroti', 'Mid Eastern', 'Eastern'),
(177, 'Soroti (W)', 'Soroti', 'Soroti', 'Mid Eastern', 'Eastern'),
(178, 'Odina', 'Soroti', 'Soroti', 'Mid Eastern', 'Eastern'),
(179, 'Serere', 'Serere', 'Soroti', 'Mid Eastern', 'Eastern'),
(180, 'Pingire', 'Serere', 'Soroti', 'Mid Eastern', 'Eastern'),
(181, 'Amuria', 'Amuria', 'Soroti', 'Mid Eastern', 'Eastern'),
(182, 'Katakwi', 'Katakwi', 'Soroti', 'Mid Eastern', 'Eastern'),
(183, 'Kaberamaido', 'Kaberamaido', 'Kaberamaido', 'Mid Eastern', 'Eastern'),
(184, 'Kumi', 'Kumi', 'Kumi', 'Mid Eastern', 'Eastern'),
(185, 'Bukedea', 'Bukedea', 'Kumi', 'Mid Eastern', 'Eastern'),
(186, 'Ngora', 'Ngora', 'Kumi', 'Mid Eastern', 'Eastern'),
(187, 'Mbale (M)', 'Mbale', 'Mbale', 'Eastern', 'Eastern'),
(188, 'Mbale (W)', 'Mbale', 'Mbale', 'Eastern', 'Eastern'),
(189, 'Bubulo', 'Manafwa', 'Mbale', 'Eastern', 'Eastern'),
(190, 'Mutufu', 'Sironko', 'Mbale', 'Eastern', 'Eastern'),
(191, 'Kisoko', 'Tororo', 'Tororo', 'Eastern', 'Eastern'),
(192, 'Mukuju', 'Tororo', 'Tororo', 'Eastern', 'Eastern'),
(193, 'Tororo (M)', 'Tororo', 'Tororo', 'Eastern', 'Eastern'),
(194, 'Tororo (W)', 'Tororo', 'Tororo', 'Eastern', 'Eastern'),
(195, 'Butaleja', 'Butaleja', 'Tororo', 'Eastern', 'Eastern'),
(196, 'Masafu', 'Busia', 'Tororo', 'Eastern', 'Eastern'),
(197, 'Agule', 'Pallisa', 'Pallisa', 'Eastern', 'Eastern'),
(198, 'Kamuge', 'Pallisa', 'Pallisa', 'Eastern', 'Eastern'),
(199, 'Budaka', 'Budaka', 'Budaka', 'Eastern', 'Eastern'),
(200, 'Kakoro', 'Butebo', 'Budaka', 'Eastern', 'Eastern'),
(201, 'Kibuku', 'Kibuku', 'Budaka', 'Eastern', 'Eastern'),
(202, 'Kapchorwa', 'Kapchorwa', 'Kapchorwa', 'Eastern', 'Eastern'),
(203, 'Ngenge', 'Kween', 'Kapchorwa', 'Eastern', 'Eastern'),
(204, 'Bukwo', 'Bukwo', 'Kapchorwa', 'Eastern', 'Eastern'),
(205, 'Moroto', 'Moroto', 'Moroto', 'North Eastern', 'Northern'),
(206, 'Amudat', 'Amudat', 'Moroto', 'North Eastern', 'Northern'),
(207, 'Nakapiripirit', 'Nakapiripirit', 'Moroto', 'North Eastern', 'Northern'),
(208, 'Namalu', 'Nakapiripirit', 'Moroto', 'North Eastern', 'Northern'),
(209, 'Kotido', 'Kotido', 'Kotido', 'North Eastern', 'Northern'),
(210, 'Kaabong', 'Kaabong', 'Kotido', 'North Eastern', 'Northern'),
(211, 'Amita', 'Abim', 'Kotido', 'North Eastern', 'Northern'),
(212, 'Arua (M)', 'Arua', 'Arua', 'North Western', 'Northern'),
(213, 'Arua (W)', 'Arua', 'Arua', 'North Western', 'Northern'),
(214, 'Giligili', 'Arua', 'Arua', 'North Western', 'Northern'),
(215, 'Lobule', 'Koboko', 'Arua', 'North Western', 'Northern'),
(216, 'Koboko', 'Koboko', 'Arua', 'North Western', 'Northern'),
(217, 'Bidi Bidi', 'Yumbe', 'Arua', 'North Western', 'Northern'),
(218, 'Yumbe', 'Yumbe', 'Arua', 'North Western', 'Northern'),
(219, 'Onigo', 'Moyo', 'Adjumani', 'North Western', 'Northern'),
(220, 'Adjumani', 'Adjumani', 'Adjumani', 'North Western', 'Northern'),
(221, 'Olia', 'Adjumani', 'Adjumani', 'North Western', 'Northern'),
(222, 'Nebbi', 'Nebbi', 'Nebbi', 'North Western', 'Northern'),
(223, 'Ragem', 'Nebbi', 'Nebbi', 'North Western', 'Northern'),
(224, 'Paidha', 'Zombo', 'Nebbi', 'North Western', 'Northern'),
(225, 'Mbarara (M)', 'Mbarara', 'Mbarara', 'South Western', 'Western'),
(226, 'Mbarara (W)', 'Mbarara', 'Mbarara', 'South Western', 'Western'),
(227, 'Kakiika', 'Mbarara', 'Mbarara', 'South Western', 'Western'),
(228, 'Ntungamo', 'Ntungamo', 'Mbarara', 'South Western', 'Western'),
(229, 'Isingiro', 'Isingiro', 'Mbarara', 'South Western', 'Western'),
(230, 'Sanga', 'Kiruhura', 'Mbarara', 'South Western', 'Western'),
(231, 'Bushenyi (M)', 'Bushenyi', 'Bushenyi', 'South Western', 'Western'),
(232, 'Bushenyi (W)', 'Bushenyi', 'Bushenyi', 'South Western', 'Western'),
(233, 'Mitooma', 'Mitooma', 'Bushenyi', 'South Western', 'Western'),
(234, 'Sheema', 'Sheema', 'Bushenyi', 'South Western', 'Western'),
(235, 'Buhweju', 'Buhweju', 'Bushenyi', 'South Western', 'Western'),
(236, 'Kiburara', 'Ibanda', 'Ibanda', 'South Western', 'Western'),
(237, 'Nyabuhikye', 'Ibanda', 'Ibanda', 'South Western', 'Western'),
(238, 'Kicheche', 'Kitagwenda', 'Ibanda', 'South Western', 'Western'),
(239, 'Kiruhura', 'Kiruhura', 'Ibanda', 'South Western', 'Western'),
(240, 'Kamwenge', 'Kamwenge', 'Ibanda', 'South Western', 'Western'),
(241, 'Rukungiri', 'Rukungiri', 'Rukungiri', 'Kigezi', 'Western'),
(242, 'Nyarushanje', 'Rukungiri', 'Rukungiri', 'Kigezi', 'Western'),
(243, 'Kanungu', 'Kanungu', 'Rukungiri', 'Kigezi', 'Western'),
(244, 'Kihiihi', 'Kanungu', 'Rukungiri', 'Kigezi', 'Western'),
(245, 'Ndorwa (M)', 'Kabale', 'Kabale', 'Kigezi', 'Western'),
(246, 'Ndorwa (W)', 'Kabale', 'Kabale', 'Kigezi', 'Western'),
(247, 'Mparo', 'Rukiga', 'Kabale', 'Kigezi', 'Western'),
(248, 'Rubanda', 'Rubanda', 'Kabale', 'Kigezi', 'Western'),
(249, 'Kisoro', 'Kisoro', 'Kabale', 'Kigezi', 'Western'),
(250, 'Bugambe', 'Kikuube', 'Hoima', 'Mid Western', 'Western'),
(251, 'Kyangwali', 'Kikuube', 'Hoima', 'Mid Western', 'Western'),
(252, 'Buseruka', 'Hoima', 'Hoima', 'Mid Western', 'Western'),
(253, 'Hoima', 'Hoima', 'Hoima', 'Mid Western', 'Western'),
(254, 'Isimba', 'Masindi', 'Masindi', 'Mid Western', 'Western'),
(255, 'Maiha', 'Masindi', 'Masindi', 'Mid Western', 'Western'),
(256, 'Masindi (M)', 'Masindi', 'Masindi', 'Mid Western', 'Western'),
(257, 'Masindi (W)', 'Masindi', 'Masindi', 'Mid Western', 'Western'),
(258, 'Kigumba', 'Kiryandongo', 'Masindi', 'Mid Western', 'Western'),
(259, 'Kiryandongo', 'Kiryandongo', 'Masindi', 'Mid Western', 'Western'),
(260, 'Bisso', 'Buliisa', 'Masindi', 'Mid Western', 'Western'),
(261, 'Butyaba', 'Buliisa', 'Masindi', 'Mid Western', 'Western'),
(262, 'Buliisa', 'Buliisa', 'Masindi', 'Mid Western', 'Western'),
(263, 'Bukomero', 'Kiboga', 'Kiboga', 'Mid Western', 'Central'),
(264, 'Kiboga', 'Kiboga', 'Kiboga', 'Mid Western', 'Central'),
(265, 'Ntwetwe', 'Kyankwanzi', 'Kiboga', 'Mid Western', 'Central');

-- --------------------------------------------------------

--
-- Table structure for table `tb_registry_payroll_monthly_status`
--

CREATE TABLE `tb_registry_payroll_monthly_status` (
  `status_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `payroll_year` int(11) NOT NULL,
  `payroll_month` tinyint(4) NOT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `quarter_label` varchar(6) NOT NULL,
  `payroll_status` enum('On Payroll','Not on Payroll') NOT NULL DEFAULT 'Not on Payroll',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `supplierNo` varchar(50) DEFAULT NULL,
  `cycle_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_retained_payments`
--

CREATE TABLE `tb_retained_payments` (
  `id` int(11) NOT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `month` date DEFAULT NULL,
  `retainedAmount` decimal(10,2) DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_roles`
--

CREATE TABLE `tb_roles` (
  `role_key` varchar(50) NOT NULL,
  `role_label` varchar(100) NOT NULL,
  `role_description` text DEFAULT NULL,
  `clone_from_role` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_roles`
--

INSERT INTO `tb_roles` (`role_key`, `role_label`, `role_description`, `clone_from_role`, `is_active`, `is_system`, `created_at`, `updated_at`) VALUES
('super_admin', 'Super Administrator', 'Highest platform governance role with unrestricted administration, security, audit, backup, restore, data, and role-management authority', 'admin', 1, 1, '2026-05-26 00:00:00', '2026-05-26 00:00:00'),
('admin', 'Administrator', 'Full administration privileges', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('approver', 'Approver', 'Final approval authority for pension workflow', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('assessor', 'Assessor', 'Assesses pension benefits and calculations', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('auditor', 'Auditor', 'Audits pension assessment workflow', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('clerk', 'Clerk', 'Application intake and verification support', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('data_entry', 'Data Entrant', 'Captures and updates pensioner data', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('dep_oc', 'Deputy OC-Pension', 'Same as OC/Pen', NULL, 1, 0, '2026-02-17 03:30:15', '2026-02-17 10:36:40'),
('file_creator', 'File Creator', 'Creates and updates pension files', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('oc_pen', 'OC/Pension', 'Workflow control and assignment authority', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('pensioner', 'Pensioner', 'Beneficiary user with limited access', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('user', 'User', 'General internal user access', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56'),
('writeup_officer', 'Writeup Officer', 'Handles pension write-up preparation', NULL, 1, 1, '2026-02-17 02:49:56', '2026-02-17 02:49:56');

-- --------------------------------------------------------

--
-- Table structure for table `tb_role_permissions`
--

CREATE TABLE `tb_role_permissions` (
  `role_permission_id` int(11) NOT NULL,
  `role_key` varchar(50) NOT NULL,
  `permission_key` varchar(120) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_role_permissions`
--

INSERT INTO `tb_role_permissions` (`role_permission_id`, `role_key`, `permission_key`, `is_allowed`, `notes`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'dep_oc', 'registry.life_certificate.submit', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:57', '2026-02-17 10:36:46'),
(2, 'dep_oc', 'registry.delete_queue.process', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:57', '2026-02-17 10:36:46'),
(3, 'dep_oc', 'registry.benefits.monthly_salary.edit', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:57', '2026-02-17 10:36:46'),
(4, 'dep_oc', 'file_movement.record', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:57', '2026-02-17 10:36:46'),
(5, 'dep_oc', 'file_movement.return', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:58', '2026-02-17 10:36:46'),
(6, 'dep_oc', 'payroll.upload', 1, 'Cloned from role \"oc_pen\"', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 03:49:58', '2026-02-17 10:36:46');

-- --------------------------------------------------------

--
-- Table structure for table `tb_session_metrics`
--

CREATE TABLE `tb_session_metrics` (
  `metric_id` int(11) NOT NULL,
  `metric_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_sessions` int(11) DEFAULT 0,
  `concurrent_conflicts` int(11) DEFAULT 0,
  `avg_session_duration` int(11) DEFAULT 0,
  `timeout_errors` int(11) DEFAULT 0,
  `network_errors` int(11) DEFAULT 0,
  `grace_period_uses` int(11) DEFAULT 0,
  `device_conflicts` int(11) DEFAULT 0,
  `successful_logins` int(11) DEFAULT 0,
  `failed_logins` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_session_settings`
--

CREATE TABLE `tb_session_settings` (
  `user_id` varchar(100) NOT NULL,
  `max_concurrent_sessions` int(11) DEFAULT 1,
  `session_timeout` int(11) DEFAULT 1800,
  `allow_multiple_devices` tinyint(1) DEFAULT 0,
  `auto_logout_on_conflict` tinyint(1) DEFAULT 1,
  `inactivity_warning_minutes` int(11) DEFAULT 5,
  `grace_period_minutes` int(11) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_staffdue`
--

CREATE TABLE `tb_staffdue` (
  `id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `computerNo` varchar(50) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `sName` varchar(100) DEFAULT NULL,
  `fName` varchar(100) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `prisonUnit` varchar(100) DEFAULT NULL,
  `NIN` text NOT NULL,
  `telNo` varchar(15) DEFAULT NULL,
  `birthDate` date DEFAULT NULL,
  `enlistmentDate` date DEFAULT NULL,
  `retirementDate` date DEFAULT NULL,
  `financialYear` text DEFAULT NULL,
  `retirementType` varchar(60) DEFAULT NULL,
  `monthlySalary` decimal(10,2) DEFAULT NULL,
  `lengthOfService` int(11) DEFAULT NULL,
  `annualSalary` decimal(10,2) DEFAULT NULL,
  `reducedPension` decimal(10,2) DEFAULT NULL,
  `fullPension` decimal(10,2) DEFAULT NULL,
  `gratuity` decimal(10,2) DEFAULT NULL,
  `submissionStatus` enum('submitted','pending') NOT NULL,
  `appnStatus` enum('pending','verified','querried','rejected') DEFAULT NULL,
  `submission_at` datetime DEFAULT NULL,
  `submission_by` varchar(100) DEFAULT NULL,
  `appn_status_at` datetime DEFAULT NULL,
  `appn_status_by` varchar(100) DEFAULT NULL,
  `appn_status_reason` text DEFAULT NULL,
  `address` text DEFAULT NULL,
  `TIN` varchar(50) DEFAULT NULL,
  `next_of_kin` varchar(120) DEFAULT NULL,
  `next_of_kin_contact` varchar(50) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(80) DEFAULT NULL,
  `bank_branch` varchar(120) DEFAULT NULL,
  `applicant_email` varchar(120) DEFAULT NULL,
  `documents_uploaded` tinyint(1) DEFAULT 0,
  `livingStatus` enum('Alive','Deceased') DEFAULT NULL,
  `payType` varchar(80) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) DEFAULT NULL,
  `deleted_by_name` varchar(100) DEFAULT NULL,
  `deleted_by_role` varchar(50) DEFAULT NULL,
  `delete_reason` text DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_staff_documents`
--

CREATE TABLE `tb_staff_documents` (
  `document_id` int(11) NOT NULL,
  `staffdue_id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `doc_type` varchar(120) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_hash` varchar(64) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_staff_due_delete_requests`
--

CREATE TABLE `tb_staff_due_delete_requests` (
  `request_id` int(11) NOT NULL,
  `staffdue_id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `staff_name` varchar(160) DEFAULT NULL,
  `staff_title` varchar(120) DEFAULT NULL,
  `requested_by` varchar(100) NOT NULL,
  `requested_by_name` varchar(100) DEFAULT NULL,
  `requested_by_role` varchar(50) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `processed_by` varchar(100) DEFAULT NULL,
  `processed_by_name` varchar(100) DEFAULT NULL,
  `processed_by_role` varchar(50) DEFAULT NULL,
  `processed_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_suspension_upload_cycles`
--

CREATE TABLE `tb_suspension_upload_cycles` (
  `suspension_cycle_id` int(11) NOT NULL,
  `suspension_year` int(11) NOT NULL,
  `suspension_month` tinyint(4) NOT NULL,
  `financial_year_label` varchar(20) NOT NULL,
  `quarter_label` varchar(6) NOT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `source_file` varchar(255) DEFAULT NULL,
  `source_file_original_name` varchar(255) DEFAULT NULL,
  `source_file_mime` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason_label` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_suspension_upload_entries`
--

CREATE TABLE `tb_suspension_upload_entries` (
  `entry_id` int(11) NOT NULL,
  `suspension_cycle_id` int(11) NOT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `beneficiary_name` varchar(150) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `matched_regNo` varchar(50) DEFAULT NULL,
  `matched_registry_id` int(11) DEFAULT NULL,
  `is_matched` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_system_logs`
--

CREATE TABLE `tb_system_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `log_level` varchar(20) NOT NULL DEFAULT 'info',
  `log_category` varchar(80) NOT NULL DEFAULT 'general',
  `event_code` varchar(120) DEFAULT NULL,
  `message` text NOT NULL,
  `context_json` longtext DEFAULT NULL,
  `actor_id` varchar(50) DEFAULT NULL,
  `actor_name` varchar(150) DEFAULT NULL,
  `actor_role` varchar(80) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tb_system_logs`
--

INSERT INTO `tb_system_logs` (`log_id`, `log_level`, `log_category`, `event_code`, `message`, `context_json`, `actor_id`, `actor_name`, `actor_role`, `ip_address`, `created_at`) VALUES
(1, 'warning', 'notification_queue', 'notification_queue_processed', 'Notification queue worker processed outbound notifications.', '{\"reason\":\"enqueue\",\"processed\":1,\"sent\":0,\"failed\":1,\"skipped\":0}', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe8', 'Patrick Etomet', 'admin', '196.0.5.114', '2026-03-30 09:36:57'),
(2, 'info', 'notification_digest', 'digest_queued', 'Notification digest queued.', '{\"digest_id\":1,\"recipient\":\"etopat2@gmail.com\",\"summary\":{\"active_users\":1,\"queued_notifications\":0,\"workflow_tasks_open\":0,\"workflow_tasks_overdue\":0,\"feedback_open\":0,\"claims_open\":0}}', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe8', 'Patrick Etomet', 'admin', '196.0.5.114', '2026-03-30 09:36:57');

-- --------------------------------------------------------

--
-- Table structure for table `tb_tasks`
--

CREATE TABLE `tb_tasks` (
  `taskId` int(11) NOT NULL,
  `createdBy` varchar(100) DEFAULT NULL,
  `sentTo` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `other` text DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(100) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `assigned_role` varchar(50) DEFAULT NULL,
  `task_type` varchar(100) DEFAULT NULL,
  `task_title` varchar(255) DEFAULT NULL,
  `task_description` text DEFAULT NULL,
  `status` enum('pending','assigned','in_progress','completed','declined','cancelled','deferred','returned') NOT NULL DEFAULT 'pending',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `related_staff_id` int(11) DEFAULT NULL,
  `related_reg_no` varchar(50) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `declined_reason` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `parent_task_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_task_alerts`
--

CREATE TABLE `tb_task_alerts` (
  `alert_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `alert_type` enum('due_soon','overdue','stalled') NOT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `alert_status` enum('open','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'open',
  `assigned_to` varchar(100) DEFAULT NULL,
  `assigned_role` varchar(50) DEFAULT NULL,
  `related_reg_no` varchar(50) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `triggered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledged_by` varchar(100) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` varchar(100) DEFAULT NULL,
  `last_evaluated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `metadata` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_task_comments`
--

CREATE TABLE `tb_task_comments` (
  `comment_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `author_id` varchar(100) DEFAULT NULL,
  `author_name` varchar(100) DEFAULT NULL,
  `author_role` varchar(50) DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_task_completion_queue`
--

CREATE TABLE `tb_task_completion_queue` (
  `queue_id` int(11) NOT NULL,
  `owner_user_id` varchar(100) NOT NULL,
  `owner_role` varchar(60) DEFAULT NULL,
  `task_id` int(11) NOT NULL,
  `task_type` varchar(80) DEFAULT NULL,
  `task_title` varchar(255) DEFAULT NULL,
  `related_reg_no` varchar(100) DEFAULT NULL,
  `required_assignment_role` varchar(60) DEFAULT NULL,
  `next_assigned_to` varchar(100) DEFAULT NULL,
  `next_assigned_role` varchar(60) DEFAULT NULL,
  `next_priority` varchar(20) NOT NULL DEFAULT 'normal',
  `action_note` text DEFAULT NULL,
  `queue_status` enum('queued','processed','failed','removed') NOT NULL DEFAULT 'queued',
  `processed_task_id` int(11) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_task_delegation_logs`
--

CREATE TABLE `tb_task_delegation_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` int(11) NOT NULL,
  `from_user_id` varchar(100) DEFAULT NULL,
  `from_user_name` varchar(150) DEFAULT NULL,
  `from_user_role` varchar(80) DEFAULT NULL,
  `to_user_id` varchar(100) DEFAULT NULL,
  `to_user_name` varchar(150) DEFAULT NULL,
  `to_user_role` varchar(80) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `priority` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_terms_clauses`
--

CREATE TABLE `tb_terms_clauses` (
  `clause_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `topics` varchar(120) NOT NULL DEFAULT 'operations',
  `section_key` varchar(50) NOT NULL DEFAULT 'operational',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_terms_clauses`
--

INSERT INTO `tb_terms_clauses` (`clause_id`, `title`, `body`, `topics`, `section_key`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Platform scope and purpose', 'UPS PensionsGo is a controlled pension administration platform used for workflow management, pension file registry, claims, payroll visibility, pensioner self-service, and guided public information. The service exists to improve timeliness, control, accountability, and traceability in pension administration.\n\nThe platform may be accessed by public users, pensioners, staff, supervisors, and administrators, but each user is limited to the role, modules, and actions authorized for that account.', 'operations', 'operational', 1, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(2, 'Accounts, roles, and credentials', 'Each user is responsible for protecting login credentials, device access, and session activity tied to the account. Users must not share passwords, bypass session controls, or attempt to impersonate another user.\n\nRole-based access applies throughout the platform. A user may see different menus, reports, records, or actions depending on assigned role, explicit permissions, and current account state. Pensioner accounts may be enabled or disabled according to operational settings.\n\nThe service may suspend, restrict, or terminate access where misuse, inactivity, policy breach, or governance action requires it.', 'accounts security', 'operational', 2, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(3, 'Workflow and operational use', 'Staff users must use workflow actions only for legitimate operational purposes. That includes accurate task handling, appropriate delegation, timely completion, truthful status updates, and correct use of application, claims, registry, payroll, and file tracking modules.\n\nUsers must not create false records, suppress valid workflow activity, manipulate analytics, or circumvent approval paths. Where a tool allows edit, delete, restore, or bulk action, the user remains responsible for ensuring the action is lawful, necessary, and properly documented.', 'operations accounts', 'operational', 3, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(4, 'Data quality, records, and documents', 'Users must ensure that data entered or imported into the platform is accurate, current, and relevant to the intended process. If a user identifies incomplete or incorrect pension information, the issue should be corrected through the appropriate workflow or support route rather than concealed.\n\nUploaded documents, payroll files, registry records, claims entries, life certificate records, and exported data remain sensitive operational information. They must be handled only for authorized work and should not be disclosed or redistributed without approval.\n\nImports, merges, deletes, recycle-bin restores, and cleanup operations may be logged and may require additional confirmation based on system settings and user role.', 'data operations', 'operational', 4, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(5, 'Security, privacy, and monitoring', 'The platform may enforce security controls such as session monitoring, device binding, export governance, audit logging, action confirmations, and settings-based restrictions on copy, paste, or developer shortcuts. These controls support operational protection but do not remove the user\'s own duty of care.\n\nCritical actions may be recorded in audit logs for governance, investigations, and service review. By using the platform, users accept that authorized operational activity may be logged together with timestamps, identity, role, and action context.\n\nUsers must not probe for vulnerabilities, attempt unauthorized access, tamper with application state, or exploit configuration weaknesses. Such conduct may lead to suspension, internal disciplinary action, or other lawful response.', 'security data', 'operational', 5, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(6, 'Podcast, public guidance, and content use', 'The podcast and public guidance modules are intended for education and operational clarity. Video content and public guidance do not override approved workflow, policy, or formal pension decisions. Users should treat content as guidance and use the relevant governed process where a formal action is required.\n\nPublic-facing content may be viewed without authentication where the system permits, but private or role-targeted content must not be redistributed outside authorized audiences.', 'content', 'operational', 6, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(7, 'Service availability and change management', 'The platform may be updated, maintained, or temporarily limited to protect data quality, security, or operational continuity. Features, permissions, workflows, or settings may change as administrative requirements evolve.\n\nBackups, exports, restore tooling, and cleanup operations are provided for governance and recovery, but they must be used only by authorized personnel and only for valid operational reasons.', 'operations security', 'operational', 7, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26'),
(8, 'Questions, support, and updates', 'If a user is unsure how these terms apply, the correct next step is to use the feedback page, read the FAQs, or contact the designated support or pensions office. Continued use of the platform after terms updates may be treated as acceptance of the revised conditions where that is operationally appropriate.\n\nThese terms should be read together with the platform\'s operational processes, access rules, and any official administrative instructions governing pension records and workflow conduct.', 'accounts content', 'operational', 8, 1, '2026-03-19 08:11:26', '2026-03-19 08:11:26');

-- --------------------------------------------------------

--
-- Table structure for table `tb_titles`
--

CREATE TABLE `tb_titles` (
  `title_id` int(11) NOT NULL,
  `title_name` varchar(120) NOT NULL,
  `category` enum('uniformed','non_uniformed') NOT NULL DEFAULT 'uniformed',
  `level` enum('junior','senior') NOT NULL DEFAULT 'junior',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_titles`
--

INSERT INTO `tb_titles` (`title_id`, `title_name`, `category`, `level`, `is_active`, `created_at`) VALUES
(1, 'Warder', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(2, 'Wardress', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(3, 'Lance Corporal', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(4, 'Corporal Warder', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(5, 'Corporal Wardress', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(6, 'Sergeant Warder', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(7, 'Sergeant Wardress', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(8, 'Chief Warder III', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(9, 'Chief Wardress III', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(10, 'Chief Warder II', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(11, 'Chief Wardress II', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(12, 'Chief Warder I', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(13, 'Chief Wardress I', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(14, 'Cadet Principal Officer', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(15, 'Principal Officer II', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(16, 'Principal Officer I', 'uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(17, 'Cadet Assistant Superintendent of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(18, 'Assistant Superintendent of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(19, 'Senior Assistant Superintendent of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(20, 'Superintendent of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(21, 'Senior Superintendent of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(22, 'Assistant Commissioner of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(23, 'Commissioner of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(24, 'Senior Commissioner of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(25, 'Assistant Commissioner General of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(26, 'Deputy Commissioner General of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(27, 'Commissioner General of Prisons', 'uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(28, 'Office Attendant', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(29, 'Human Resource Officer', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(30, 'Senior Human Resource Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(31, 'Principal Human Resource Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(32, 'Assistant Commissioner', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(33, 'Commissioner', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(35, 'Medical Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(36, 'Rehabilitation and Reintegration Officer', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(37, 'Senior Rehabilitation and Reintegration Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(38, 'Principal Rehabilitation and Reintegration Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(39, 'Medical Superintendent', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(40, 'Nursing Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(41, 'Senior Nursing Officer', 'non_uniformed', 'senior', 1, '2026-02-13 07:30:45'),
(42, 'Instructor I', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(43, 'Instructor II', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(44, 'Artisan', 'non_uniformed', 'junior', 1, '2026-02-13 07:30:45'),
(365, 'Accounts Assistant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(366, 'Agricultural Assistant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(367, 'Agricultural Mechanic', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(368, 'Agricultural Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(369, 'Animal Husbandry Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(370, 'Artisan - Carpenter', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(371, 'Artisan - Mechanic', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(372, 'Artisan - Mechanical', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(373, 'Artisan - Plumber', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(374, 'Assistant Agricultural Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(375, 'Assistant Commissioner - Agriculture', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(376, 'Assistant Commissioner - HRM', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(377, 'Assistant Commissioner - Internal Audit', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(378, 'Assistant Commissioner - Welfare & Rehabilitation', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(379, 'Assistant Engineering Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(380, 'Assistant Farm Manager', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(381, 'Assistant Nursing Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(382, 'Assistant Records Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(383, 'Asssistant Nursing Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(384, 'Clerical Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(385, 'Commissioner - HRM', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(386, 'Copy Typist', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(387, 'Enrolled Midwife', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(388, 'Enrolled Nurse', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(389, 'Industrial manager', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(390, 'Inspector of works', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(391, 'Laboratory Assistant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(392, 'Machine Operator', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(393, 'Nurse II', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(394, 'Nursing Aide', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(395, 'Nursing Assistant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(396, 'Nutritionist', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(397, 'Office Supervisor', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(398, 'Office Typist', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(399, 'Opthlamic Clinical Officer', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(400, 'Personal Secretary', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(401, 'Poultry Attendant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(402, 'Principal Accounts Assistant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(403, 'Principal Agricultural Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(404, 'Principal Assistant Agricultural Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(405, 'Principal Executive Engineer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(406, 'Principal Health Inspector', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(407, 'Principal Industrial Manager', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(408, 'Principal Medical Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(409, 'Principal Nursing Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(410, 'Principal Personal Assistant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(411, 'Principal Personal Secretary', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(412, 'Principal Procurement Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(413, 'Principal Veterinary Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(414, 'Pump attendant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(415, 'Senior Accountant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(416, 'Senior Accounts Assistant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(417, 'Senior Agricultural Assistant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(418, 'Senior Agricultural Engineer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(419, 'Senior Agricultural Mechanic', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(420, 'Senior Agricultural Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(421, 'Senior Assistant Agricultural Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(422, 'Senior Assistant Engineering Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(423, 'Senior Assistant Records Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(424, 'Senior Clerical Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(425, 'Senior Clinical Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(426, 'Senior Dispenser', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(427, 'Senior Health Educator', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(428, 'Senior Industrial Manager', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(429, 'Senior Instructor I', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(430, 'Senior Instructor II', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(431, 'Senior Orthopaedic Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(432, 'Senior Pharmacist', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(433, 'Senior Principal Accountant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(434, 'Senior Principal Nursing Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(435, 'Senior Principal Stores Assistant', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(436, 'Senior Psychiatric Clinical Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(437, 'Senior Public Dental Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(438, 'Senior Rehabilitation Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(439, 'Senior Supplies Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(440, 'Senior Welfare Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(441, 'Under Secretary', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(442, 'Welfare and Rehabilitation Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56'),
(443, 'Welfare Assistant', 'non_uniformed', 'junior', 1, '2026-03-27 02:47:56'),
(444, 'Welfare Officer', 'non_uniformed', 'senior', 1, '2026-03-27 02:47:56');

-- --------------------------------------------------------

--
-- Table structure for table `tb_uganda_public_holidays`
--

CREATE TABLE `tb_uganda_public_holidays` (
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_uganda_public_holidays`
--

INSERT INTO `tb_uganda_public_holidays` (`holiday_date`, `holiday_name`, `is_active`, `created_at`) VALUES
('2025-01-01', 'New Year\'s Day', 1, '2026-02-17 04:51:51'),
('2025-01-26', 'NRM Liberation Day', 1, '2026-02-17 04:51:51'),
('2025-03-08', 'International Women\'s Day', 1, '2026-02-17 04:51:51'),
('2025-04-17', 'Good Friday', 1, '2026-02-17 04:51:51'),
('2025-04-20', 'Easter Monday', 1, '2026-02-17 04:51:51'),
('2025-05-01', 'Labour Day', 1, '2026-02-17 04:51:51'),
('2025-06-03', 'Uganda Martyrs Day', 1, '2026-02-17 04:51:51'),
('2025-06-09', 'National Heroes Day', 1, '2026-02-17 04:51:51'),
('2025-10-09', 'Independence Day', 1, '2026-02-17 04:51:51'),
('2025-12-25', 'Christmas Day', 1, '2026-02-17 04:51:51'),
('2025-12-26', 'Boxing Day', 1, '2026-02-17 04:51:51'),
('2026-01-01', 'New Year\'s Day', 1, '2026-02-17 04:51:51'),
('2026-01-26', 'NRM Liberation Day', 1, '2026-02-17 04:51:51'),
('2026-03-08', 'International Women\'s Day', 1, '2026-02-17 04:51:51'),
('2026-04-02', 'Good Friday', 1, '2026-02-17 04:51:51'),
('2026-04-05', 'Easter Monday', 1, '2026-02-17 04:51:51'),
('2026-05-01', 'Labour Day', 1, '2026-02-17 04:51:51'),
('2026-06-03', 'Uganda Martyrs Day', 1, '2026-02-17 04:51:51'),
('2026-06-09', 'National Heroes Day', 1, '2026-02-17 04:51:51'),
('2026-10-09', 'Independence Day', 1, '2026-02-17 04:51:51'),
('2026-12-25', 'Christmas Day', 1, '2026-02-17 04:51:51'),
('2026-12-26', 'Boxing Day', 1, '2026-02-17 04:51:51'),
('2027-01-01', 'New Year\'s Day', 1, '2026-02-17 04:51:51'),
('2027-01-26', 'NRM Liberation Day', 1, '2026-02-17 04:51:51'),
('2027-03-08', 'International Women\'s Day', 1, '2026-02-17 04:51:51'),
('2027-03-25', 'Good Friday', 1, '2026-02-17 04:51:51'),
('2027-03-28', 'Easter Monday', 1, '2026-02-17 04:51:51'),
('2027-05-01', 'Labour Day', 1, '2026-02-17 04:51:51'),
('2027-06-03', 'Uganda Martyrs Day', 1, '2026-02-17 04:51:51'),
('2027-06-09', 'National Heroes Day', 1, '2026-02-17 04:51:51'),
('2027-10-09', 'Independence Day', 1, '2026-02-17 04:51:51'),
('2027-12-25', 'Christmas Day', 1, '2026-02-17 04:51:51'),
('2027-12-26', 'Boxing Day', 1, '2026-02-17 04:51:51');

-- --------------------------------------------------------

--
-- Table structure for table `tb_users`
--

CREATE TABLE `tb_users` (
  `Id` int(11) NOT NULL,
  `userId` varchar(100) NOT NULL,
  `userTitle` varchar(120) DEFAULT NULL,
  `userName` varchar(100) DEFAULT NULL,
  `userRole` varchar(100) DEFAULT NULL,
  `userEmail` varchar(100) DEFAULT NULL,
  `phoneNo` varchar(20) NOT NULL,
  `userPassword` varchar(100) DEFAULT NULL,
  `userPhoto` varchar(255) DEFAULT NULL,
  `timeStamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `other` text DEFAULT NULL,
  `password_updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_users`
--

INSERT INTO `tb_users` (`Id`, `userId`, `userTitle`, `userName`, `userRole`, `userEmail`, `phoneNo`, `userPassword`, `userPhoto`, `timeStamp`, `other`, `password_updated_at`) VALUES
(100, '2bacd5362e228a627c43332040cc2540034338e49a3161c2b87746cdfae556ce', 'Mr.', 'Demo Super Administrator', 'super_admin', 'etopat2@gmail.com', '+256791170164', '$2y$10$NkjVB.I2EHLHZXgjlvjHSOVhGUPvlJazaLhlEAfPT8ihmpna9V8bq', 'images/default-user.png', '2026-05-26 00:00:00', 'Seeded demo super administrator account for controlled system governance and exhibition/testing access.', '2026-05-26 00:00:00'),
(4, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Mr.', 'Patrick Etomet', 'admin', 'etomet2patrick@gmail.com', '+256773959039', '$2y$10$7k2X8IC4znxAVESvgbHuCeV2ks3dYzGCLuw/UX2QjAusvpe93abWi', '../uploads/profiles/68fa805a6c744.jpg', '2025-06-24 07:53:12', '', NULL),
(5, '6d6ebecfd2b5da97052bcdb220bd3b9dad808b66789cbe7f8c9f2ac1881acf99', 'Ms.', 'Among Jacenta', 'user', 'jacentamong@gmail.com', '+256777900981', '$2y$10$fpbNa/6k7XTVTnNXJqGBgeRQqj/FTPFjSSPZMSilwBStt2X3xPHm6', '../uploads/profiles/68fe0329f3ef40.jpg', '2025-06-30 09:59:07', '', NULL),
(9, 'c9187f7d817d01ba94da89af2fa28e137dbc689f987a6345a4190262b4613ebe', 'Mr.', 'Ben Nyanzi', 'oc_pen', 'nyanziben@gmail.com', '+256772963518', '$2y$10$ayhpZPdhg4E5ROFQ8LsvguXaGn7S7i/6J70IQxyD7aEwVh7szPPCu', 'backend/uploads/profiles/ECQK.jpg', '2025-10-26 09:40:13', '', NULL),
(10, 'e7e772782a275a301bab7fe490d414f3dd978baa68d2ad6f6b07b678ee7c7bcd', 'Mr.', 'Elvis Opondo', 'data_entry', 'lvcfreeman@gmail.com', '+256788664893', '$2y$10$RD81bwZYQnvKyrrDAr0qlOoKPTk9CnkUJTO/E9LMQkyo/xd5fJypO', '', '2026-02-14 10:11:06', '', '2026-02-14 10:11:06'),
(11, '1eff7c032dcfcf66637c308c884c00953232dd39585a1de522ed781a4751fe27', 'Ms.', 'Eron Asaba', 'writeup_officer', 'asabaeron50@gmail.com', '+256785988966', '$2y$10$Phy435k0GXwZoX4TKepjIODy70UXoTqznMyfm706NIpRVF4WVTaV6', '', '2026-02-14 10:12:53', '', '2026-02-14 10:12:53'),
(12, '81c65b1e0ce3ae0955d9cac657880c2dd1116e37749c02ae1601ae594c5c72e3', 'Mr.', 'Julius Onyango', 'clerk', 'onyangojulius144@gmail.com', '+256776133144', '$2y$10$O16wInSNqXNzm2W7EvWDveRfsXgT/WVeqITsb9MuVPVQYijxohrb6', '', '2026-02-14 10:13:59', '', '2026-02-14 10:13:59'),
(13, 'd2b3733de198e228047671ed3e0868408c3fd5bf822c6b7ddbd9b254592967ee', 'Ms.', 'Julian Nakasinde', 'writeup_officer', 'juliannaka12@gmail.com', '+256784553402', '$2y$10$oBtxlqOwtujDW2Nfn51J2ue3wMl9xa7GcRYyKaBTiMi0pkw0aIGY6', '', '2026-02-14 10:14:44', '', '2026-02-14 10:14:44'),
(14, 'b1f25357f4b4a3a1b08f7872483148f036143401419fd6790a264af3436cc2e3', 'Mr.', 'George Niwagaba', 'data_entry', 'niwagabageo@gmail.com', '+256784456639', '$2y$10$VRVM9QiPdnllUpn5DuwKj.1LsJe0KfWnxnynTxl5tYdpMjS6Zri/G', '', '2026-02-14 10:19:17', '', '2026-02-14 10:19:17'),
(15, '931fc9c50bb519e781c18bd9e7ee07dc3949c98aa2a648ab30ed3ae3bea92cb6', 'Ms.', 'Sarah Namwenjje', 'assessor', 'sarahnamwenjje90@gmail.com', '+256780477666', '$2y$10$nPR04h90444tOzj3Nq6w6uyZtx244ZpQ4uKwG5zuEFadbfzLicedK', '', '2026-02-14 10:20:12', '', '2026-02-14 10:20:12'),
(16, 'ddddebdea0a7f5d517a9bd569e042ee932b409f85532f1263a2a9588fff0fcb2', 'Mr.', 'Stephen Baker Ojom', 'approver', 'ojomstebak@gmail.com', '+256782446576', '$2y$10$xxa9kKKWAqFm9XSNK3LXNOBvfO4TX/PSxKRGGALR1W2w5oHSHKF82', '', '2026-02-14 10:21:02', '', '2026-02-14 10:21:02'),
(17, 'bbed689ac019bb56c1f60e4781e065dc29c9276cc49959a68d3d23b9e8ce9d1c', 'Mr.', 'William Patrick Awany', 'dep_oc', 'awanypwilliam@gmail.com', '+256782368014', '$2y$10$qHO9IcGxU2OpTndH6zIHruGhL1bqN.lms6qIAFOZirDYySBiW/IQK', '', '2026-02-14 10:22:58', '', '2026-02-14 10:22:58'),
(18, '4db43623f3b3ad981ed1895c6c8ecdd0634ea80024c52e259d0d8554ae56e518', 'Ms.', 'Agnes Nahabwe', 'user', 'nahabweagnes3@gmail.com', '+256757063453', '$2y$10$8TFDe9h490NzipHHrZW20eNb0S2TqPmDNAUcCoeaFticE5qSlxQnO', '', '2026-02-14 10:24:36', '', '2026-02-14 10:24:36'),
(20, '2c686ef8db40db5ccc33ed8a9eeff7cfaf26baaa44c90ccb61a8cb28fa535ba7', 'Ms.', 'Mastula Ankunda', 'file_creator', 'mastulan440@gmail.com', '+256771234567', '$2y$10$bx/LhHFzmF2780mQ5zcpH.MuUoNwFbFfKc/i3qQfjBY3Cfl0T/Ybm', '', '2026-02-15 12:20:30', '', '2026-02-15 12:20:30'),
(21, '2958f3ffc33a70fa481dc43e8cddafa9cac0c6e2b94eead6b86763ea7711c74b', 'Mr.', 'Audito', 'auditor', 'auditor@gmail.com', '+256712345678', '$2y$10$ZX760M61bKf6lRtN5f6ETuEvlZUsKQcxtODkoJf3rr5LEXQ7jWqpe', '', '2026-02-15 12:34:46', '', '2026-02-15 12:34:46'),
(22, '351afb3f90264b5084da53303fd4e95cedb2066281ca9571fdcc890c860308d5', 'Warder', 'Kayenga Godfrey', 'pensioner', 'pensioner.10299@pensionsgo.com', '+256414502013', '$2y$10$FxlJfgweRKRH1g9VBp1m5O8zWqqRcF16VwXr7hORwcMruW0IhOJdC', 'images/default-user.png', '2026-02-18 16:00:18', '{\"source\":\"tb_staffdue\",\"staffdue_id\":23,\"regNo\":\"10299\",\"auto_provisioned\":true}', '2026-03-15 03:52:42'),
(24, 'd8882fff68643e395e9b59fba221d31823ad0e4036c65e3604fee1b0b15cce8c', 'Principal Officer II', 'Kisambira Rebecca', 'pensioner', 'pensioner.601@pensionsgo.local', '+256772506598', '$2y$10$WSnLXK4/a9KqOkZlCkY73utx20mpEr6RIOf.IeIy.4DtbRtUI0kPO', 'images/default-user.png', '2026-02-18 16:48:01', '{\"source\":\"tb_staffdue\",\"staffdue_id\":44,\"regNo\":\"P/K/601\",\"auto_provisioned\":true}', '2026-02-18 16:48:01'),
(25, '4a88f5a222fda4b8766fe2da423c3f58f6481b678a2125e19db4cee8155fa70e', 'Sergeant Warder', 'Waneroba Christopher', 'pensioner', 'pensioner.4887@pensionsgo.local', '+256783446003', '$2y$10$9C0almrFGVoKRRXiB.HzX.mXJUyso.K0jsukpEyfZaMbMaH2XEj4e', 'images/default-user.png', '2026-02-18 16:48:01', '{\"source\":\"tb_staffdue\",\"staffdue_id\":45,\"regNo\":\"4887\",\"auto_provisioned\":true}', '2026-02-18 16:48:01'),
(31, '120e27a042c3e57a23e8f912216a1301ac8383aa0f0d9e6b3b2c2358f0e3753e', 'Principal Officer II', 'Gadaire Yekosofati', 'pensioner', 'pensioner.42@pensionsgo.local', '+256782282291', '$2y$10$Gz944S8e95W8G6ako1QkCes4YL322K/2DkFcGScC2GJT/LBHLYBPW', 'images/default-user.png', '2026-03-11 12:38:41', '{\"source\":\"tb_fileregistry\",\"registry_id\":17,\"regNo\":\"P/G/42\",\"auto_provisioned\":true}', '2026-03-11 12:38:41');

-- --------------------------------------------------------

--
-- Table structure for table `tb_user_broadcast_status`
--

CREATE TABLE `tb_user_broadcast_status` (
  `status_id` int(11) NOT NULL,
  `user_id` varchar(100) NOT NULL,
  `broadcast_id` int(11) NOT NULL,
  `is_seen` tinyint(1) DEFAULT 0,
  `seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_user_logs`
--

CREATE TABLE `tb_user_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` varchar(100) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `user_role` varchar(50) NOT NULL,
  `activity_type` enum('login','logout','session_expiry','device_conflict','auto_logout','login_failed','device_conflict_detected','device_conflict_resolved','multiple_sessions_terminated','session_cleanup','session_termination_failed','session_started') NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `device_type` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `logout_time` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT 0,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tb_user_logs`
--

INSERT INTO `tb_user_logs` (`log_id`, `user_id`, `user_name`, `user_role`, `activity_type`, `ip_address`, `user_agent`, `device_type`, `location`, `session_id`, `login_time`, `logout_time`, `duration_seconds`, `details`, `created_at`) VALUES
(1, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'session_started', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '1a183b1126f6b14c5ea24931e4c80a666697853a5b28816698da81981d6e266d', '2026-03-30 09:36:32', NULL, 0, 'Session Started', '2026-03-30 06:36:32'),
(2, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'login', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '1a183b1126f6b14c5ea24931e4c80a666697853a5b28816698da81981d6e266d', '2026-03-30 09:36:32', NULL, 0, 'Successful login via email', '2026-03-30 06:36:32'),
(3, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'logout', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '1a183b1126f6b14c5ea24931e4c80a666697853a5b28816698da81981d6e266d', '2026-03-30 09:38:51', NULL, 0, 'User requested logout', '2026-03-30 06:38:51'),
(4, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'session_started', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '21c32db823bbac349bdf72ec9d0fafce0193f8c9331d9fe55f42489ec0cc74bf', '2026-03-30 10:37:13', NULL, 0, 'Session Started', '2026-03-30 07:37:13'),
(5, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'login', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '21c32db823bbac349bdf72ec9d0fafce0193f8c9331d9fe55f42489ec0cc74bf', '2026-03-30 10:37:13', NULL, 0, 'Successful login via email', '2026-03-30 07:37:13'),
(6, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'logout', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '21c32db823bbac349bdf72ec9d0fafce0193f8c9331d9fe55f42489ec0cc74bf', '2026-03-30 10:51:27', NULL, 0, 'User requested logout', '2026-03-30 07:51:27'),
(7, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'session_started', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '086a3e045913aadb440a2e6deee4c9db597edb7a95cc7eb566c44b7df7072198', '2026-03-30 10:56:38', NULL, 0, 'Session Started', '2026-03-30 07:56:38'),
(8, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'login', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '086a3e045913aadb440a2e6deee4c9db597edb7a95cc7eb566c44b7df7072198', '2026-03-30 10:56:38', NULL, 0, 'Successful login via email', '2026-03-30 07:56:38'),
(9, 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', 'Patrick Etomet', 'admin', 'logout', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '086a3e045913aadb440a2e6deee4c9db597edb7a95cc7eb566c44b7df7072198', '2026-03-30 11:01:20', NULL, 0, 'User requested logout', '2026-03-30 08:01:20'),
(10, '351afb3f90264b5084da53303fd4e95cedb2066281ca9571fdcc890c860308d5', 'Kayenga Godfrey', 'pensioner', 'session_started', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '2df1e58c552e56cc98abee6a63b4554636632cb9fbdd81bcc2ca8f329cb823cd', '2026-03-30 11:01:35', NULL, 0, 'Session Started', '2026-03-30 08:01:35'),
(11, '351afb3f90264b5084da53303fd4e95cedb2066281ca9571fdcc890c860308d5', 'Kayenga Godfrey', 'pensioner', 'login', '196.0.5.114', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', 'Windows PC', 'Kampala, Central Region, Uganda', '2df1e58c552e56cc98abee6a63b4554636632cb9fbdd81bcc2ca8f329cb823cd', '2026-03-30 11:01:35', NULL, 0, 'Successful login via email', '2026-03-30 08:01:35');

-- --------------------------------------------------------

--
-- Table structure for table `tb_user_permissions`
--

CREATE TABLE `tb_user_permissions` (
  `permission_id` int(11) NOT NULL,
  `user_id` varchar(100) NOT NULL,
  `permission_key` varchar(120) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `granted_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_user_permissions`
--

INSERT INTO `tb_user_permissions` (`permission_id`, `user_id`, `permission_key`, `is_allowed`, `notes`, `granted_by`, `created_at`, `updated_at`) VALUES
(1, 'b1f25357f4b4a3a1b08f7872483148f036143401419fd6790a264af3436cc2e3', 'payroll.upload', 1, '', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 02:28:55', '2026-02-17 02:28:55'),
(2, 'b1f25357f4b4a3a1b08f7872483148f036143401419fd6790a264af3436cc2e3', 'payroll.manage', 1, '', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '2026-02-17 02:28:55', '2026-02-17 02:28:55');

-- --------------------------------------------------------

--
-- Table structure for table `tb_user_sessions`
--

CREATE TABLE `tb_user_sessions` (
  `id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_id` varchar(100) NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `session_type` enum('web','mobile','api') DEFAULT 'web',
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp(),
  `grace_period_until` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `termination_reason` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tb_user_sessions`
--

INSERT INTO `tb_user_sessions` (`id`, `session_id`, `user_id`, `device_id`, `session_type`, `login_time`, `last_activity`, `grace_period_until`, `is_active`, `termination_reason`, `user_agent`, `ip_address`) VALUES
(1, '1a183b1126f6b14c5ea24931e4c80a666697853a5b28816698da81981d6e266d', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '4428f078a378e6a653c295fb904546fd20580afc73e66a3f9b32dab1928632bc', 'web', '2026-03-30 06:36:31', '2026-03-30 06:38:50', '2026-03-30 05:41:31', 0, 'user_initiated', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '196.0.5.114'),
(2, '21c32db823bbac349bdf72ec9d0fafce0193f8c9331d9fe55f42489ec0cc74bf', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '4428f078a378e6a653c295fb904546fd20580afc73e66a3f9b32dab1928632bc', 'web', '2026-03-30 07:37:13', '2026-03-30 07:51:26', '2026-03-30 06:42:13', 0, 'user_initiated', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '196.0.5.114'),
(3, '086a3e045913aadb440a2e6deee4c9db597edb7a95cc7eb566c44b7df7072198', 'd5babc6bd2089bcab5496aaf7dcba60d7315c2dec0f3da1fe85262f9654acac7', '4428f078a378e6a653c295fb904546fd20580afc73e66a3f9b32dab1928632bc', 'web', '2026-03-30 07:56:38', '2026-03-30 08:01:19', '2026-03-30 07:01:38', 0, 'user_initiated', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '196.0.5.114'),
(4, '2df1e58c552e56cc98abee6a63b4554636632cb9fbdd81bcc2ca8f329cb823cd', '351afb3f90264b5084da53303fd4e95cedb2066281ca9571fdcc890c860308d5', '4428f078a378e6a653c295fb904546fd20580afc73e66a3f9b32dab1928632bc', 'web', '2026-03-30 08:01:35', '2026-03-30 08:44:28', '2026-03-30 07:06:35', 1, NULL, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '196.0.5.114');

-- --------------------------------------------------------

--
-- Table structure for table `tb_user_settings`
--

CREATE TABLE `tb_user_settings` (
  `user_id` varchar(100) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_workflow_logs`
--

CREATE TABLE `tb_workflow_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `staffdue_id` int(11) DEFAULT NULL,
  `regNo` varchar(50) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `actor_id` varchar(100) DEFAULT NULL,
  `actor_name` varchar(150) DEFAULT NULL,
  `actor_role` varchar(80) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tb_analytics_digest_runs`
--
ALTER TABLE `tb_analytics_digest_runs`
  ADD PRIMARY KEY (`digest_id`),
  ADD KEY `idx_analytics_digest_date` (`digest_date`),
  ADD KEY `idx_analytics_digest_created` (`created_at`),
  ADD KEY `idx_analytics_digest_frequency` (`digest_frequency`,`created_at`);

--
-- Indexes for table `tb_analytics_snapshots`
--
ALTER TABLE `tb_analytics_snapshots`
  ADD PRIMARY KEY (`snapshot_id`),
  ADD KEY `idx_analytics_snapshot_type` (`snapshot_type`),
  ADD KEY `idx_analytics_snapshot_created` (`created_at`);

--
-- Indexes for table `tb_application_queue`
--
ALTER TABLE `tb_application_queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD UNIQUE KEY `unique_staffdue` (`staffdue_id`),
  ADD KEY `idx_application_queue_status` (`status`),
  ADD KEY `idx_application_queue_stage` (`current_stage`),
  ADD KEY `idx_application_queue_updated` (`updated_at`),
  ADD KEY `idx_application_queue_regno` (`regNo`),
  ADD KEY `fk_application_queue_verified_by` (`verified_by`),
  ADD KEY `fk_application_queue_submitted_by` (`submitted_by`);

--
-- Indexes for table `tb_appnstatus`
--
ALTER TABLE `tb_appnstatus`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `regNo` (`regNo`);

--
-- Indexes for table `tb_appnsubmissions`
--
ALTER TABLE `tb_appnsubmissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `regNo` (`regNo`),
  ADD KEY `idx_appnsubmissions_submission_date` (`submissionDate`),
  ADD KEY `idx_appnsubmissions_retirement_type` (`retirementType`);

--
-- Indexes for table `tb_app_settings`
--
ALTER TABLE `tb_app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tb_arrearstracking`
--
ALTER TABLE `tb_arrearstracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recordedBy` (`recordedBy`);

--
-- Indexes for table `tb_arrears_accountability_files`
--
ALTER TABLE `tb_arrears_accountability_files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `idx_arr_accountability_files_submission` (`submission_id`);

--
-- Indexes for table `tb_arrears_accountability_submissions`
--
ALTER TABLE `tb_arrears_accountability_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD KEY `idx_arr_accountability_reg_type` (`regNo`,`claim_type`),
  ADD KEY `idx_arr_accountability_payment` (`payment_id`),
  ADD KEY `idx_arr_accountability_submitted_by` (`submitted_by`);

--
-- Indexes for table `tb_arrears_ledger`
--
ALTER TABLE `tb_arrears_ledger`
  ADD PRIMARY KEY (`ledger_id`),
  ADD UNIQUE KEY `uniq_arrears_period` (`regNo`,`claim_type`,`period_year`,`period_month`,`source_type`,`reference_cycle_id`),
  ADD KEY `idx_arrears_reg` (`regNo`),
  ADD KEY `idx_arrears_type` (`claim_type`),
  ADD KEY `idx_arrears_period` (`period_year`,`period_month`),
  ADD KEY `idx_arrears_fy_q` (`financial_year_label`,`quarter_label`),
  ADD KEY `idx_arrears_status` (`status`),
  ADD KEY `idx_arrears_recorded_by` (`recorded_by`);

--
-- Indexes for table `tb_arrears_payments`
--
ALTER TABLE `tb_arrears_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_arrears_payment_reg` (`regNo`),
  ADD KEY `idx_arrears_payment_type` (`claim_type`),
  ADD KEY `idx_arrears_payment_date` (`payment_date`),
  ADD KEY `idx_arrears_payment_recorded_by` (`recorded_by`),
  ADD KEY `fk_arrears_payments_latest_submission` (`latest_submission_id`);

--
-- Indexes for table `tb_arrears_payment_allocations`
--
ALTER TABLE `tb_arrears_payment_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `idx_arr_payment_alloc_payment` (`payment_id`),
  ADD KEY `idx_arr_payment_alloc_ledger` (`ledger_id`),
  ADD KEY `idx_arr_payment_alloc_reg_type` (`regNo`,`claim_type`),
  ADD KEY `idx_arr_payment_alloc_status` (`accountability_status`),
  ADD KEY `fk_arrears_alloc_accountability` (`accountability_submission_id`);

--
-- Indexes for table `tb_audit_logs`
--
ALTER TABLE `tb_audit_logs`
  ADD PRIMARY KEY (`audit_id`);

--
-- Indexes for table `tb_backup_logs`
--
ALTER TABLE `tb_backup_logs`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_backup_time` (`backup_time`),
  ADD KEY `idx_backup_status` (`status`);

--
-- Indexes for table `tb_broadcast_messages`
--
ALTER TABLE `tb_broadcast_messages`
  ADD PRIMARY KEY (`broadcast_id`),
  ADD KEY `message_id` (`message_id`);

--
-- Indexes for table `tb_budgetforecast`
--
ALTER TABLE `tb_budgetforecast`
  ADD PRIMARY KEY (`id`),
  ADD KEY `createdBy` (`createdBy`);

--
-- Indexes for table `tb_claimstatus`
--
ALTER TABLE `tb_claimstatus`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_claim_regno` (`regNo`),
  ADD KEY `idx_claim_supplier` (`supplierNo`),
  ADD KEY `idx_claim_status` (`appnStatus`),
  ADD KEY `idx_claim_verification_date` (`verificationDate`);

--
-- Indexes for table `tb_data_export_runs`
--
ALTER TABLE `tb_data_export_runs`
  ADD PRIMARY KEY (`export_id`),
  ADD KEY `idx_export_created` (`created_at`),
  ADD KEY `idx_export_dataset` (`dataset_key`),
  ADD KEY `idx_export_created_by` (`created_by`);

--
-- Indexes for table `tb_data_import_runs`
--
ALTER TABLE `tb_data_import_runs`
  ADD PRIMARY KEY (`import_run_id`),
  ADD KEY `idx_import_dataset` (`dataset_key`),
  ADD KEY `idx_import_started_at` (`started_at`),
  ADD KEY `idx_import_created_by` (`created_by`);

--
-- Indexes for table `tb_faq_entries`
--
ALTER TABLE `tb_faq_entries`
  ADD PRIMARY KEY (`faq_id`),
  ADD KEY `idx_faq_category` (`category`),
  ADD KEY `idx_faq_active` (`is_active`),
  ADD KEY `idx_faq_featured` (`is_featured`);

--
-- Indexes for table `tb_feedback_activity`
--
ALTER TABLE `tb_feedback_activity`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `idx_feedback_activity_submission` (`submission_id`),
  ADD KEY `idx_feedback_activity_action` (`action`),
  ADD KEY `idx_feedback_activity_created` (`created_at`),
  ADD KEY `fk_feedback_activity_actor` (`actor_id`);

--
-- Indexes for table `tb_feedback_submissions`
--
ALTER TABLE `tb_feedback_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD UNIQUE KEY `uniq_feedback_reference` (`reference_no`),
  ADD KEY `idx_feedback_type` (`feedback_type`),
  ADD KEY `idx_feedback_audience` (`audience`),
  ADD KEY `idx_feedback_status` (`status`),
  ADD KEY `idx_feedback_submitted` (`submitted_at`),
  ADD KEY `idx_feedback_assigned_to` (`assigned_to_user_id`),
  ADD KEY `idx_feedback_submitted_by` (`submitted_by_user_id`),
  ADD KEY `idx_feedback_priority` (`priority`),
  ADD KEY `fk_feedback_submissions_reviewed_by` (`reviewed_by_user_id`),
  ADD KEY `fk_feedback_submissions_resolved_by` (`resolved_by_user_id`),
  ADD KEY `fk_feedback_submissions_closed_by` (`closed_by_user_id`);

--
-- Indexes for table `tb_fileregistry`
--
ALTER TABLE `tb_fileregistry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `computerNo` (`computerNo`),
  ADD UNIQUE KEY `regNo` (`regNo`),
  ADD KEY `idx_fileregistry_recent` (`timeStamp`,`id`),
  ADD KEY `idx_fileregistry_availability_recent` (`availability_status`,`timeStamp`,`id`),
  ADD KEY `idx_fileregistry_name_sort` (`sName`,`fName`,`id`),
  ADD KEY `idx_fileregistry_is_deleted` (`is_deleted`),
  ADD KEY `idx_fileregistry_auto_arrears` (`workflow_auto_arrears_enabled`,`is_deleted`,`regNo`),
  ADD KEY `idx_fileregistry_supplier` (`supplierNo`),
  ADD KEY `idx_fileregistry_payroll` (`payrollStatus`,`payType`),
  ADD KEY `idx_fileregistry_life_certificate` (`lifeCertificate`),
  ADD KEY `idx_fileregistry_living_status` (`livingStatus`),
  ADD KEY `idx_fileregistry_retirement` (`retirementType`,`retirementDate`),
  ADD KEY `idx_fileregistry_contact` (`telNo`);

--
-- Indexes for table `tb_file_movements`
--
ALTER TABLE `tb_file_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `idx_file_movements_regno` (`regNo`),
  ADD KEY `idx_file_movements_moved` (`moved_at`),
  ADD KEY `idx_file_movements_expected` (`expected_return_at`),
  ADD KEY `idx_file_movements_returned` (`returned_at`),
  ADD KEY `idx_file_movements_from_office` (`from_office`),
  ADD KEY `idx_file_movements_to_office` (`to_office`),
  ADD KEY `idx_file_movements_file_id` (`file_id`);

--
-- Indexes for table `tb_file_registry_delete_requests`
--
ALTER TABLE `tb_file_registry_delete_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_registry_delete_registry` (`registry_id`),
  ADD KEY `idx_registry_delete_status` (`status`),
  ADD KEY `idx_registry_delete_requested_by` (`requested_by`),
  ADD KEY `idx_registry_delete_created` (`created_at`),
  ADD KEY `idx_registry_delete_processed` (`processed_at`),
  ADD KEY `fk_registry_delete_requests_processed_by` (`processed_by`);

--
-- Indexes for table `tb_file_registry_recycle_bin`
--
ALTER TABLE `tb_file_registry_recycle_bin`
  ADD PRIMARY KEY (`recycle_id`),
  ADD KEY `idx_registry_recycle_regno` (`regNo`),
  ADD KEY `idx_registry_recycle_restored` (`restored`),
  ADD KEY `idx_registry_recycle_deleted_at` (`deleted_at`),
  ADD KEY `idx_registry_recycle_request` (`delete_request_id`),
  ADD KEY `idx_registry_recycle_registry` (`registry_id`),
  ADD KEY `idx_registry_recycle_restored_by` (`restored_by`),
  ADD KEY `fk_registry_recycle_deleted_by` (`deleted_by`);

--
-- Indexes for table `tb_file_scan_logs`
--
ALTER TABLE `tb_file_scan_logs`
  ADD PRIMARY KEY (`scan_id`),
  ADD KEY `idx_file_scan_context` (`storage_context`,`scanned_at`),
  ADD KEY `idx_file_scan_status` (`scan_status`,`scanned_at`);

--
-- Indexes for table `tb_gratuity_schedule_allocations`
--
ALTER TABLE `tb_gratuity_schedule_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `idx_gratuity_alloc_cycle` (`cycle_id`),
  ADD KEY `idx_gratuity_alloc_entry` (`entry_id`),
  ADD KEY `idx_gratuity_alloc_reg` (`matched_regNo`),
  ADD KEY `idx_gratuity_alloc_period` (`period_year`,`period_month`),
  ADD KEY `idx_gratuity_alloc_cycle_period` (`cycle_id`,`period_year`,`period_month`,`allocation_id`),
  ADD KEY `idx_gratuity_alloc_ledger` (`ledger_id`);

--
-- Indexes for table `tb_gratuity_schedule_cycles`
--
ALTER TABLE `tb_gratuity_schedule_cycles`
  ADD PRIMARY KEY (`cycle_id`),
  ADD KEY `idx_gratuity_schedule_period` (`schedule_year`,`schedule_month`),
  ADD KEY `idx_gratuity_schedule_fy_q` (`financial_year_label`,`quarter_label`),
  ADD KEY `idx_gratuity_schedule_created` (`created_at`),
  ADD KEY `idx_gratuity_schedule_active_fy` (`is_deleted`,`financial_year_label`,`schedule_year`,`schedule_month`,`created_at`),
  ADD KEY `idx_gratuity_schedule_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `tb_gratuity_schedule_entries`
--
ALTER TABLE `tb_gratuity_schedule_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `idx_gratuity_entry_cycle` (`cycle_id`),
  ADD KEY `idx_gratuity_entry_reg` (`matched_regNo`),
  ADD KEY `idx_gratuity_entry_classification` (`classification`),
  ADD KEY `idx_gratuity_entry_cycle_row` (`cycle_id`,`row_number`,`entry_id`),
  ADD KEY `idx_gratuity_entry_registry` (`matched_registry_id`);

--
-- Indexes for table `tb_ip_geolocation`
--
ALTER TABLE `tb_ip_geolocation`
  ADD PRIMARY KEY (`ip_address`),
  ADD KEY `idx_last_lookup` (`last_lookup`);

--
-- Indexes for table `tb_lifecertificates`
--
ALTER TABLE `tb_lifecertificates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tb_life_certificate_submissions`
--
ALTER TABLE `tb_life_certificate_submissions`
  ADD PRIMARY KEY (`submission_id`),
  ADD UNIQUE KEY `uniq_life_cert_reg_year` (`regNo`,`submission_year`),
  ADD KEY `idx_life_cert_year` (`submission_year`),
  ADD KEY `idx_life_cert_regno` (`regNo`),
  ADD KEY `fk_life_cert_submissions_user` (`submitted_by`);

--
-- Indexes for table `tb_messages`
--
ALTER TABLE `tb_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `parent_message_id` (`parent_message_id`);

--
-- Indexes for table `tb_message_attachments`
--
ALTER TABLE `tb_message_attachments`
  ADD PRIMARY KEY (`attachment_id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `idx_message_attachments_hash` (`file_hash`);

--
-- Indexes for table `tb_message_recipients`
--
ALTER TABLE `tb_message_recipients`
  ADD PRIMARY KEY (`recipient_id`),
  ADD UNIQUE KEY `unique_message_recipient` (`message_id`,`recipient_user_id`),
  ADD KEY `recipient_user_id` (`recipient_user_id`);

--
-- Indexes for table `tb_message_storage_snapshots`
--
ALTER TABLE `tb_message_storage_snapshots`
  ADD PRIMARY KEY (`snapshot_id`),
  ADD KEY `idx_message_snapshot_date` (`snapshot_date`),
  ADD KEY `idx_message_snapshot_created` (`created_at`);

--
-- Indexes for table `tb_notification_digest_runs`
--
ALTER TABLE `tb_notification_digest_runs`
  ADD PRIMARY KEY (`digest_id`),
  ADD KEY `idx_notification_digest_date` (`digest_date`),
  ADD KEY `idx_notification_digest_created` (`created_at`);

--
-- Indexes for table `tb_notification_queue`
--
ALTER TABLE `tb_notification_queue`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notification_status` (`status`,`created_at`),
  ADD KEY `idx_notification_channel` (`channel`);

--
-- Indexes for table `tb_payrolls`
--
ALTER TABLE `tb_payrolls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `tb_payroll_arrears`
--
ALTER TABLE `tb_payroll_arrears`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplierNo` (`supplierNo`);

--
-- Indexes for table `tb_payroll_audit_logs`
--
ALTER TABLE `tb_payroll_audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_payroll_audit_cycle` (`cycle_id`),
  ADD KEY `idx_payroll_audit_actor` (`actor_user_id`),
  ADD KEY `idx_payroll_audit_action` (`action`),
  ADD KEY `idx_payroll_audit_created` (`created_at`);

--
-- Indexes for table `tb_payroll_gratuity`
--
ALTER TABLE `tb_payroll_gratuity`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplierNo` (`supplierNo`);

--
-- Indexes for table `tb_payroll_pension`
--
ALTER TABLE `tb_payroll_pension`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplierNo` (`supplierNo`);

--
-- Indexes for table `tb_payroll_suspended`
--
ALTER TABLE `tb_payroll_suspended`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplierNo` (`supplierNo`);

--
-- Indexes for table `tb_payroll_upload_cycles`
--
ALTER TABLE `tb_payroll_upload_cycles`
  ADD PRIMARY KEY (`cycle_id`),
  ADD KEY `idx_payroll_cycle_period` (`payroll_year`,`payroll_month`),
  ADD KEY `idx_payroll_cycle_fy_q` (`financial_year_label`,`quarter_label`),
  ADD KEY `idx_payroll_cycle_active` (`is_deleted`),
  ADD KEY `idx_payroll_cycle_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_payroll_cycle_created_at` (`created_at`),
  ADD KEY `fk_payroll_cycles_deleted_by` (`deleted_by`);

--
-- Indexes for table `tb_payroll_upload_entries`
--
ALTER TABLE `tb_payroll_upload_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `idx_payroll_entries_cycle` (`cycle_id`),
  ADD KEY `idx_payroll_entries_supplier` (`supplierNo`),
  ADD KEY `idx_payroll_entries_match` (`matched_regNo`),
  ADD KEY `idx_payroll_entries_matched_registry` (`matched_registry_id`),
  ADD KEY `idx_payroll_entries_is_matched` (`is_matched`);

--
-- Indexes for table `tb_podcast_videos`
--
ALTER TABLE `tb_podcast_videos`
  ADD PRIMARY KEY (`podcast_id`),
  ADD KEY `idx_podcast_audience` (`audience`),
  ADD KEY `idx_podcast_published` (`is_published`),
  ADD KEY `idx_podcast_featured` (`is_featured`),
  ADD KEY `idx_podcast_created_at` (`created_at`),
  ADD KEY `idx_podcast_created_by` (`created_by`);

--
-- Indexes for table `tb_podcast_views`
--
ALTER TABLE `tb_podcast_views`
  ADD PRIMARY KEY (`view_id`),
  ADD KEY `idx_podcast_view_podcast` (`podcast_id`),
  ADD KEY `idx_podcast_view_viewer` (`viewer_id`),
  ADD KEY `idx_podcast_viewed_at` (`viewed_at`);

--
-- Indexes for table `tb_poldistricts`
--
ALTER TABLE `tb_poldistricts`
  ADD PRIMARY KEY (`Id`),
  ADD KEY `polDistrict` (`polDistrict`(768)),
  ADD KEY `polRegion` (`polRegion`(768));

--
-- Indexes for table `tb_pridistricts`
--
ALTER TABLE `tb_pridistricts`
  ADD PRIMARY KEY (`Id`),
  ADD KEY `priDistrict` (`priDistrict`(768)),
  ADD KEY `priRegion` (`priRegion`(768));

--
-- Indexes for table `tb_priregions`
--
ALTER TABLE `tb_priregions`
  ADD PRIMARY KEY (`Id`);

--
-- Indexes for table `tb_priunits`
--
ALTER TABLE `tb_priunits`
  ADD PRIMARY KEY (`Id`),
  ADD KEY `polDistrict` (`polDistrict`(1024)),
  ADD KEY `priUnit` (`priUnit`(1024)),
  ADD KEY `priDistrict` (`polDistrict`(1024)) USING BTREE,
  ADD KEY `priDistrict_2` (`priDistrict`(1024)),
  ADD KEY `priRegion` (`priRegion`(1024)),
  ADD KEY `polRegion` (`polRegion`(1024));

--
-- Indexes for table `tb_registry_payroll_monthly_status`
--
ALTER TABLE `tb_registry_payroll_monthly_status`
  ADD PRIMARY KEY (`status_id`),
  ADD UNIQUE KEY `uniq_registry_payroll_period` (`regNo`,`payroll_year`,`payroll_month`),
  ADD KEY `idx_registry_payroll_period` (`payroll_year`,`payroll_month`),
  ADD KEY `idx_registry_payroll_fy_q` (`financial_year_label`,`quarter_label`),
  ADD KEY `idx_registry_payroll_status` (`payroll_status`),
  ADD KEY `idx_registry_payroll_cycle` (`cycle_id`);

--
-- Indexes for table `tb_retained_payments`
--
ALTER TABLE `tb_retained_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `tb_roles`
--
ALTER TABLE `tb_roles`
  ADD PRIMARY KEY (`role_key`),
  ADD KEY `idx_role_active` (`is_active`),
  ADD KEY `idx_role_clone` (`clone_from_role`);

--
-- Indexes for table `tb_role_permissions`
--
ALTER TABLE `tb_role_permissions`
  ADD PRIMARY KEY (`role_permission_id`),
  ADD UNIQUE KEY `uniq_role_permission` (`role_key`,`permission_key`),
  ADD KEY `idx_role_perm_role` (`role_key`),
  ADD KEY `idx_role_perm_permission` (`permission_key`);

--
-- Indexes for table `tb_session_metrics`
--
ALTER TABLE `tb_session_metrics`
  ADD PRIMARY KEY (`metric_id`),
  ADD KEY `idx_metric_time` (`metric_time`);

--
-- Indexes for table `tb_session_settings`
--
ALTER TABLE `tb_session_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `tb_staffdue`
--
ALTER TABLE `tb_staffdue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `regNo` (`regNo`),
  ADD KEY `idx_staffdue_is_deleted` (`is_deleted`),
  ADD KEY `idx_staffdue_computer` (`computerNo`),
  ADD KEY `idx_staffdue_submission_status` (`submissionStatus`),
  ADD KEY `idx_staffdue_appn_status` (`appnStatus`),
  ADD KEY `idx_staffdue_retirement` (`retirementType`,`retirementDate`),
  ADD KEY `idx_staffdue_prison_unit` (`prisonUnit`),
  ADD KEY `idx_staffdue_submission_at` (`submission_at`),
  ADD KEY `idx_staffdue_appn_status_at` (`appn_status_at`),
  ADD KEY `idx_staffdue_living_status` (`livingStatus`),
  ADD KEY `idx_staffdue_pay_type` (`payType`),
  ADD KEY `idx_staffdue_contact` (`telNo`);

--
-- Indexes for table `tb_staff_documents`
--
ALTER TABLE `tb_staff_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `idx_staff_documents_staff` (`staffdue_id`),
  ADD KEY `idx_staff_documents_regno` (`regNo`),
  ADD KEY `idx_staff_documents_hash` (`file_hash`),
  ADD KEY `idx_staff_documents_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_staff_documents_uploaded_at` (`uploaded_at`);

--
-- Indexes for table `tb_staff_due_delete_requests`
--
ALTER TABLE `tb_staff_due_delete_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_staffdue_delete_staff` (`staffdue_id`),
  ADD KEY `idx_staffdue_delete_status` (`status`),
  ADD KEY `idx_staffdue_delete_requested_by` (`requested_by`),
  ADD KEY `fk_staff_due_delete_requests_processed_by` (`processed_by`);

--
-- Indexes for table `tb_suspension_upload_cycles`
--
ALTER TABLE `tb_suspension_upload_cycles`
  ADD PRIMARY KEY (`suspension_cycle_id`),
  ADD KEY `idx_susp_cycle_period` (`suspension_year`,`suspension_month`),
  ADD KEY `idx_susp_cycle_fy_q` (`financial_year_label`,`quarter_label`),
  ADD KEY `fk_suspension_cycles_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `tb_suspension_upload_entries`
--
ALTER TABLE `tb_suspension_upload_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD KEY `idx_susp_entries_cycle` (`suspension_cycle_id`),
  ADD KEY `idx_susp_entries_supplier` (`supplierNo`),
  ADD KEY `idx_susp_entries_match` (`matched_regNo`);

--
-- Indexes for table `tb_system_logs`
--
ALTER TABLE `tb_system_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_system_logs_level_created` (`log_level`,`created_at`),
  ADD KEY `idx_system_logs_category_created` (`log_category`,`created_at`),
  ADD KEY `idx_system_logs_actor_created` (`actor_id`,`created_at`);

--
-- Indexes for table `tb_tasks`
--
ALTER TABLE `tb_tasks`
  ADD PRIMARY KEY (`taskId`),
  ADD KEY `createdBy` (`createdBy`),
  ADD KEY `sentTo` (`sentTo`),
  ADD KEY `idx_parent_task_id` (`parent_task_id`),
  ADD KEY `idx_tasks_status` (`status`),
  ADD KEY `idx_tasks_assigned_to` (`assigned_to`),
  ADD KEY `idx_tasks_created_by` (`created_by`),
  ADD KEY `idx_tasks_due_at` (`due_at`),
  ADD KEY `idx_tasks_related_staff` (`related_staff_id`),
  ADD KEY `idx_tasks_related_reg` (`related_reg_no`);

--
-- Indexes for table `tb_task_alerts`
--
ALTER TABLE `tb_task_alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD UNIQUE KEY `uniq_task_alert_type` (`task_id`,`alert_type`),
  ADD KEY `idx_alert_status` (`alert_status`),
  ADD KEY `idx_alert_assignee_status` (`assigned_to`,`alert_status`),
  ADD KEY `idx_alert_role_status` (`assigned_role`,`alert_status`),
  ADD KEY `idx_alert_triggered` (`triggered_at`);

--
-- Indexes for table `tb_task_comments`
--
ALTER TABLE `tb_task_comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `idx_task_comments_task` (`task_id`);

--
-- Indexes for table `tb_task_completion_queue`
--
ALTER TABLE `tb_task_completion_queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `idx_task_completion_queue_owner_status` (`owner_user_id`,`queue_status`),
  ADD KEY `idx_task_completion_queue_task_owner` (`task_id`,`owner_user_id`),
  ADD KEY `fk_task_completion_queue_processed_task` (`processed_task_id`);

--
-- Indexes for table `tb_task_delegation_logs`
--
ALTER TABLE `tb_task_delegation_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_task_delegation_task` (`task_id`),
  ADD KEY `idx_task_delegation_from` (`from_user_id`),
  ADD KEY `idx_task_delegation_to` (`to_user_id`),
  ADD KEY `idx_task_delegation_created` (`created_at`);

--
-- Indexes for table `tb_terms_clauses`
--
ALTER TABLE `tb_terms_clauses`
  ADD PRIMARY KEY (`clause_id`),
  ADD KEY `idx_terms_section` (`section_key`),
  ADD KEY `idx_terms_active` (`is_active`);

--
-- Indexes for table `tb_titles`
--
ALTER TABLE `tb_titles`
  ADD PRIMARY KEY (`title_id`);

--
-- Indexes for table `tb_uganda_public_holidays`
--
ALTER TABLE `tb_uganda_public_holidays`
  ADD PRIMARY KEY (`holiday_date`);

--
-- Indexes for table `tb_users`
--
ALTER TABLE `tb_users`
  ADD PRIMARY KEY (`Id`),
  ADD UNIQUE KEY `phoneNo` (`phoneNo`),
  ADD UNIQUE KEY `idx_tb_users_phoneNo` (`phoneNo`),
  ADD UNIQUE KEY `userId` (`userId`),
  ADD UNIQUE KEY `userEmail` (`userEmail`),
  ADD KEY `idx_user_email` (`userEmail`),
  ADD KEY `idx_user_userId` (`userId`),
  ADD KEY `idx_user_phone` (`phoneNo`);

--
-- Indexes for table `tb_user_broadcast_status`
--
ALTER TABLE `tb_user_broadcast_status`
  ADD PRIMARY KEY (`status_id`),
  ADD UNIQUE KEY `unique_user_broadcast` (`user_id`,`broadcast_id`),
  ADD KEY `broadcast_id` (`broadcast_id`);

--
-- Indexes for table `tb_user_logs`
--
ALTER TABLE `tb_user_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_login_time` (`login_time`),
  ADD KEY `idx_user_role` (`user_role`),
  ADD KEY `idx_logs_userId` (`user_id`);

--
-- Indexes for table `tb_user_permissions`
--
ALTER TABLE `tb_user_permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `uniq_user_permission` (`user_id`,`permission_key`),
  ADD KEY `idx_permission_key` (`permission_key`),
  ADD KEY `idx_permission_user` (`user_id`);

--
-- Indexes for table `tb_user_sessions`
--
ALTER TABLE `tb_user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_session_id` (`session_id`),
  ADD KEY `idx_user_sessions_user_id` (`user_id`),
  ADD KEY `idx_user_sessions_device_id` (`device_id`),
  ADD KEY `idx_user_sessions_last_activity` (`last_activity`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`),
  ADD KEY `idx_last_activity` (`last_activity`),
  ADD KEY `idx_session_device` (`session_id`,`device_id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_last_activity2` (`last_activity`),
  ADD KEY `idx_user_active2` (`user_id`,`is_active`),
  ADD KEY `idx_sessions_userId` (`user_id`),
  ADD KEY `idx_sessions_sessionId` (`session_id`);

--
-- Indexes for table `tb_user_settings`
--
ALTER TABLE `tb_user_settings`
  ADD PRIMARY KEY (`user_id`,`setting_key`),
  ADD KEY `idx_user_settings_key` (`setting_key`);

--
-- Indexes for table `tb_workflow_logs`
--
ALTER TABLE `tb_workflow_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_workflow_task` (`task_id`),
  ADD KEY `idx_workflow_staff` (`staffdue_id`),
  ADD KEY `idx_workflow_regno` (`regNo`),
  ADD KEY `idx_workflow_action` (`action`),
  ADD KEY `idx_workflow_created` (`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tb_analytics_digest_runs`
--
ALTER TABLE `tb_analytics_digest_runs`
  MODIFY `digest_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_analytics_snapshots`
--
ALTER TABLE `tb_analytics_snapshots`
  MODIFY `snapshot_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tb_application_queue`
--
ALTER TABLE `tb_application_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_appnstatus`
--
ALTER TABLE `tb_appnstatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_appnsubmissions`
--
ALTER TABLE `tb_appnsubmissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrearstracking`
--
ALTER TABLE `tb_arrearstracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrears_accountability_files`
--
ALTER TABLE `tb_arrears_accountability_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrears_accountability_submissions`
--
ALTER TABLE `tb_arrears_accountability_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrears_ledger`
--
ALTER TABLE `tb_arrears_ledger`
  MODIFY `ledger_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrears_payments`
--
ALTER TABLE `tb_arrears_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_arrears_payment_allocations`
--
ALTER TABLE `tb_arrears_payment_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_audit_logs`
--
ALTER TABLE `tb_audit_logs`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_backup_logs`
--
ALTER TABLE `tb_backup_logs`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_broadcast_messages`
--
ALTER TABLE `tb_broadcast_messages`
  MODIFY `broadcast_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_budgetforecast`
--
ALTER TABLE `tb_budgetforecast`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_claimstatus`
--
ALTER TABLE `tb_claimstatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_data_export_runs`
--
ALTER TABLE `tb_data_export_runs`
  MODIFY `export_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_data_import_runs`
--
ALTER TABLE `tb_data_import_runs`
  MODIFY `import_run_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_faq_entries`
--
ALTER TABLE `tb_faq_entries`
  MODIFY `faq_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `tb_feedback_activity`
--
ALTER TABLE `tb_feedback_activity`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_feedback_submissions`
--
ALTER TABLE `tb_feedback_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_fileregistry`
--
ALTER TABLE `tb_fileregistry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_file_movements`
--
ALTER TABLE `tb_file_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_file_registry_delete_requests`
--
ALTER TABLE `tb_file_registry_delete_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_file_registry_recycle_bin`
--
ALTER TABLE `tb_file_registry_recycle_bin`
  MODIFY `recycle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_file_scan_logs`
--
ALTER TABLE `tb_file_scan_logs`
  MODIFY `scan_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_gratuity_schedule_allocations`
--
ALTER TABLE `tb_gratuity_schedule_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_gratuity_schedule_cycles`
--
ALTER TABLE `tb_gratuity_schedule_cycles`
  MODIFY `cycle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_gratuity_schedule_entries`
--
ALTER TABLE `tb_gratuity_schedule_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_lifecertificates`
--
ALTER TABLE `tb_lifecertificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_life_certificate_submissions`
--
ALTER TABLE `tb_life_certificate_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_messages`
--
ALTER TABLE `tb_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_message_attachments`
--
ALTER TABLE `tb_message_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_message_recipients`
--
ALTER TABLE `tb_message_recipients`
  MODIFY `recipient_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_message_storage_snapshots`
--
ALTER TABLE `tb_message_storage_snapshots`
  MODIFY `snapshot_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_notification_digest_runs`
--
ALTER TABLE `tb_notification_digest_runs`
  MODIFY `digest_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tb_notification_queue`
--
ALTER TABLE `tb_notification_queue`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tb_payrolls`
--
ALTER TABLE `tb_payrolls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_arrears`
--
ALTER TABLE `tb_payroll_arrears`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_audit_logs`
--
ALTER TABLE `tb_payroll_audit_logs`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_gratuity`
--
ALTER TABLE `tb_payroll_gratuity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_pension`
--
ALTER TABLE `tb_payroll_pension`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_suspended`
--
ALTER TABLE `tb_payroll_suspended`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_upload_cycles`
--
ALTER TABLE `tb_payroll_upload_cycles`
  MODIFY `cycle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_payroll_upload_entries`
--
ALTER TABLE `tb_payroll_upload_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_podcast_videos`
--
ALTER TABLE `tb_podcast_videos`
  MODIFY `podcast_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_podcast_views`
--
ALTER TABLE `tb_podcast_views`
  MODIFY `view_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_poldistricts`
--
ALTER TABLE `tb_poldistricts`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `tb_pridistricts`
--
ALTER TABLE `tb_pridistricts`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `tb_priregions`
--
ALTER TABLE `tb_priregions`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `tb_priunits`
--
ALTER TABLE `tb_priunits`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=266;

--
-- AUTO_INCREMENT for table `tb_registry_payroll_monthly_status`
--
ALTER TABLE `tb_registry_payroll_monthly_status`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_retained_payments`
--
ALTER TABLE `tb_retained_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_role_permissions`
--
ALTER TABLE `tb_role_permissions`
  MODIFY `role_permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tb_session_metrics`
--
ALTER TABLE `tb_session_metrics`
  MODIFY `metric_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_staffdue`
--
ALTER TABLE `tb_staffdue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_staff_documents`
--
ALTER TABLE `tb_staff_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_staff_due_delete_requests`
--
ALTER TABLE `tb_staff_due_delete_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_suspension_upload_cycles`
--
ALTER TABLE `tb_suspension_upload_cycles`
  MODIFY `suspension_cycle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_suspension_upload_entries`
--
ALTER TABLE `tb_suspension_upload_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_system_logs`
--
ALTER TABLE `tb_system_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tb_tasks`
--
ALTER TABLE `tb_tasks`
  MODIFY `taskId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_task_alerts`
--
ALTER TABLE `tb_task_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_task_comments`
--
ALTER TABLE `tb_task_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_task_completion_queue`
--
ALTER TABLE `tb_task_completion_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_task_delegation_logs`
--
ALTER TABLE `tb_task_delegation_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_terms_clauses`
--
ALTER TABLE `tb_terms_clauses`
  MODIFY `clause_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tb_titles`
--
ALTER TABLE `tb_titles`
  MODIFY `title_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=445;

--
-- AUTO_INCREMENT for table `tb_users`
--
ALTER TABLE `tb_users`
  MODIFY `Id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `tb_user_broadcast_status`
--
ALTER TABLE `tb_user_broadcast_status`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tb_user_logs`
--
ALTER TABLE `tb_user_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tb_user_permissions`
--
ALTER TABLE `tb_user_permissions`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tb_user_sessions`
--
ALTER TABLE `tb_user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tb_workflow_logs`
--
ALTER TABLE `tb_workflow_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tb_application_queue`
--
ALTER TABLE `tb_application_queue`
  ADD CONSTRAINT `fk_application_queue_staffdue` FOREIGN KEY (`staffdue_id`) REFERENCES `tb_staffdue` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_application_queue_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_application_queue_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_appnstatus`
--
ALTER TABLE `tb_appnstatus`
  ADD CONSTRAINT `fk_appnstatus_staffdue_regno` FOREIGN KEY (`regNo`) REFERENCES `tb_staffdue` (`regNo`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_appnsubmissions`
--
ALTER TABLE `tb_appnsubmissions`
  ADD CONSTRAINT `tb_appnsubmissions_ibfk_1` FOREIGN KEY (`regNo`) REFERENCES `tb_staffdue` (`regNo`);

--
-- Constraints for table `tb_arrearstracking`
--
ALTER TABLE `tb_arrearstracking`
  ADD CONSTRAINT `tb_arrearstracking_ibfk_1` FOREIGN KEY (`recordedBy`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_arrears_accountability_files`
--
ALTER TABLE `tb_arrears_accountability_files`
  ADD CONSTRAINT `fk_arrears_accountability_files_submission` FOREIGN KEY (`submission_id`) REFERENCES `tb_arrears_accountability_submissions` (`submission_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_arrears_accountability_submissions`
--
ALTER TABLE `tb_arrears_accountability_submissions`
  ADD CONSTRAINT `fk_arrears_accountability_payment` FOREIGN KEY (`payment_id`) REFERENCES `tb_arrears_payments` (`payment_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_accountability_regno` FOREIGN KEY (`regNo`) REFERENCES `tb_fileregistry` (`regNo`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_accountability_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_arrears_ledger`
--
ALTER TABLE `tb_arrears_ledger`
  ADD CONSTRAINT `fk_arrears_ledger_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_ledger_regno` FOREIGN KEY (`regNo`) REFERENCES `tb_fileregistry` (`regNo`) ON UPDATE CASCADE;

--
-- Constraints for table `tb_arrears_payments`
--
ALTER TABLE `tb_arrears_payments`
  ADD CONSTRAINT `fk_arrears_payments_latest_submission` FOREIGN KEY (`latest_submission_id`) REFERENCES `tb_arrears_accountability_submissions` (`submission_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_payments_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_payments_regno` FOREIGN KEY (`regNo`) REFERENCES `tb_fileregistry` (`regNo`) ON UPDATE CASCADE;

--
-- Constraints for table `tb_arrears_payment_allocations`
--
ALTER TABLE `tb_arrears_payment_allocations`
  ADD CONSTRAINT `fk_arrears_alloc_accountability` FOREIGN KEY (`accountability_submission_id`) REFERENCES `tb_arrears_accountability_submissions` (`submission_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_alloc_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `tb_arrears_ledger` (`ledger_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_arrears_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `tb_arrears_payments` (`payment_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_broadcast_messages`
--
ALTER TABLE `tb_broadcast_messages`
  ADD CONSTRAINT `tb_broadcast_messages_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`);

--
-- Constraints for table `tb_budgetforecast`
--
ALTER TABLE `tb_budgetforecast`
  ADD CONSTRAINT `tb_budgetforecast_ibfk_1` FOREIGN KEY (`createdBy`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_data_export_runs`
--
ALTER TABLE `tb_data_export_runs`
  ADD CONSTRAINT `fk_data_export_runs_created_by` FOREIGN KEY (`created_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_data_import_runs`
--
ALTER TABLE `tb_data_import_runs`
  ADD CONSTRAINT `fk_data_import_runs_created_by` FOREIGN KEY (`created_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_feedback_activity`
--
ALTER TABLE `tb_feedback_activity`
  ADD CONSTRAINT `fk_feedback_activity_actor` FOREIGN KEY (`actor_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_activity_submission` FOREIGN KEY (`submission_id`) REFERENCES `tb_feedback_submissions` (`submission_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_feedback_submissions`
--
ALTER TABLE `tb_feedback_submissions`
  ADD CONSTRAINT `fk_feedback_submissions_assigned_to` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_submissions_closed_by` FOREIGN KEY (`closed_by_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_submissions_resolved_by` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_submissions_reviewed_by` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_submissions_submitted_by` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_file_movements`
--
ALTER TABLE `tb_file_movements`
  ADD CONSTRAINT `fk_file_movements_registry_id` FOREIGN KEY (`file_id`) REFERENCES `tb_fileregistry` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_file_registry_delete_requests`
--
ALTER TABLE `tb_file_registry_delete_requests`
  ADD CONSTRAINT `fk_registry_delete_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registry_delete_requests_registry` FOREIGN KEY (`registry_id`) REFERENCES `tb_fileregistry` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registry_delete_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `tb_users` (`userId`) ON UPDATE CASCADE;

--
-- Constraints for table `tb_file_registry_recycle_bin`
--
ALTER TABLE `tb_file_registry_recycle_bin`
  ADD CONSTRAINT `fk_registry_recycle_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registry_recycle_registry` FOREIGN KEY (`registry_id`) REFERENCES `tb_fileregistry` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registry_recycle_request` FOREIGN KEY (`delete_request_id`) REFERENCES `tb_file_registry_delete_requests` (`request_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_registry_recycle_restored_by` FOREIGN KEY (`restored_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_gratuity_schedule_allocations`
--
ALTER TABLE `tb_gratuity_schedule_allocations`
  ADD CONSTRAINT `fk_gratuity_schedule_allocations_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `tb_gratuity_schedule_cycles` (`cycle_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gratuity_schedule_allocations_entry` FOREIGN KEY (`entry_id`) REFERENCES `tb_gratuity_schedule_entries` (`entry_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gratuity_schedule_allocations_ledger` FOREIGN KEY (`ledger_id`) REFERENCES `tb_arrears_ledger` (`ledger_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_gratuity_schedule_cycles`
--
ALTER TABLE `tb_gratuity_schedule_cycles`
  ADD CONSTRAINT `fk_gratuity_schedule_cycles_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_gratuity_schedule_entries`
--
ALTER TABLE `tb_gratuity_schedule_entries`
  ADD CONSTRAINT `fk_gratuity_schedule_entries_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `tb_gratuity_schedule_cycles` (`cycle_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_gratuity_schedule_entries_registry` FOREIGN KEY (`matched_registry_id`) REFERENCES `tb_fileregistry` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_life_certificate_submissions`
--
ALTER TABLE `tb_life_certificate_submissions`
  ADD CONSTRAINT `fk_life_cert_submissions_regno` FOREIGN KEY (`regNo`) REFERENCES `tb_fileregistry` (`regNo`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_life_cert_submissions_user` FOREIGN KEY (`submitted_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_messages`
--
ALTER TABLE `tb_messages`
  ADD CONSTRAINT `tb_messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `tb_users` (`userId`),
  ADD CONSTRAINT `tb_messages_ibfk_2` FOREIGN KEY (`parent_message_id`) REFERENCES `tb_messages` (`message_id`);

--
-- Constraints for table `tb_message_attachments`
--
ALTER TABLE `tb_message_attachments`
  ADD CONSTRAINT `tb_message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`);

--
-- Constraints for table `tb_message_recipients`
--
ALTER TABLE `tb_message_recipients`
  ADD CONSTRAINT `tb_message_recipients_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`),
  ADD CONSTRAINT `tb_message_recipients_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_payrolls`
--
ALTER TABLE `tb_payrolls`
  ADD CONSTRAINT `tb_payrolls_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_payroll_audit_logs`
--
ALTER TABLE `tb_payroll_audit_logs`
  ADD CONSTRAINT `fk_payroll_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payroll_audit_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `tb_payroll_upload_cycles` (`cycle_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_payroll_upload_cycles`
--
ALTER TABLE `tb_payroll_upload_cycles`
  ADD CONSTRAINT `fk_payroll_cycles_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payroll_cycles_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_payroll_upload_entries`
--
ALTER TABLE `tb_payroll_upload_entries`
  ADD CONSTRAINT `fk_payroll_entries_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `tb_payroll_upload_cycles` (`cycle_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payroll_entries_registry_id` FOREIGN KEY (`matched_registry_id`) REFERENCES `tb_fileregistry` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_podcast_views`
--
ALTER TABLE `tb_podcast_views`
  ADD CONSTRAINT `fk_podcast_views_podcast` FOREIGN KEY (`podcast_id`) REFERENCES `tb_podcast_videos` (`podcast_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_podcast_views_viewer` FOREIGN KEY (`viewer_id`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_registry_payroll_monthly_status`
--
ALTER TABLE `tb_registry_payroll_monthly_status`
  ADD CONSTRAINT `fk_registry_payroll_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `tb_payroll_upload_cycles` (`cycle_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_retained_payments`
--
ALTER TABLE `tb_retained_payments`
  ADD CONSTRAINT `tb_retained_payments_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_roles`
--
ALTER TABLE `tb_roles`
  ADD CONSTRAINT `fk_roles_clone_from` FOREIGN KEY (`clone_from_role`) REFERENCES `tb_roles` (`role_key`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_role_permissions`
--
ALTER TABLE `tb_role_permissions`
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_key`) REFERENCES `tb_roles` (`role_key`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_session_settings`
--
ALTER TABLE `tb_session_settings`
  ADD CONSTRAINT `tb_session_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `tb_staff_documents`
--
ALTER TABLE `tb_staff_documents`
  ADD CONSTRAINT `fk_staff_documents_staffdue` FOREIGN KEY (`staffdue_id`) REFERENCES `tb_staffdue` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_staff_due_delete_requests`
--
ALTER TABLE `tb_staff_due_delete_requests`
  ADD CONSTRAINT `fk_staff_due_delete_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_due_delete_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `tb_users` (`userId`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_staff_due_delete_requests_staffdue` FOREIGN KEY (`staffdue_id`) REFERENCES `tb_staffdue` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `tb_suspension_upload_cycles`
--
ALTER TABLE `tb_suspension_upload_cycles`
  ADD CONSTRAINT `fk_suspension_cycles_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `tb_suspension_upload_entries`
--
ALTER TABLE `tb_suspension_upload_entries`
  ADD CONSTRAINT `fk_suspension_entries_cycle` FOREIGN KEY (`suspension_cycle_id`) REFERENCES `tb_suspension_upload_cycles` (`suspension_cycle_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_tasks`
--
ALTER TABLE `tb_tasks`
  ADD CONSTRAINT `tb_tasks_ibfk_1` FOREIGN KEY (`createdBy`) REFERENCES `tb_users` (`userId`),
  ADD CONSTRAINT `tb_tasks_ibfk_2` FOREIGN KEY (`sentTo`) REFERENCES `tb_users` (`userId`);

--
-- Constraints for table `tb_task_alerts`
--
ALTER TABLE `tb_task_alerts`
  ADD CONSTRAINT `fk_task_alerts_task` FOREIGN KEY (`task_id`) REFERENCES `tb_tasks` (`taskId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_task_comments`
--
ALTER TABLE `tb_task_comments`
  ADD CONSTRAINT `fk_task_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tb_tasks` (`taskId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_task_completion_queue`
--
ALTER TABLE `tb_task_completion_queue`
  ADD CONSTRAINT `fk_task_completion_queue_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `tb_users` (`userId`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_task_completion_queue_processed_task` FOREIGN KEY (`processed_task_id`) REFERENCES `tb_tasks` (`taskId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_task_completion_queue_task` FOREIGN KEY (`task_id`) REFERENCES `tb_tasks` (`taskId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_user_broadcast_status`
--
ALTER TABLE `tb_user_broadcast_status`
  ADD CONSTRAINT `tb_user_broadcast_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`),
  ADD CONSTRAINT `tb_user_broadcast_status_ibfk_2` FOREIGN KEY (`broadcast_id`) REFERENCES `tb_broadcast_messages` (`broadcast_id`);

--
-- Constraints for table `tb_user_permissions`
--
ALTER TABLE `tb_user_permissions`
  ADD CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tb_user_sessions`
--
ALTER TABLE `tb_user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `tb_user_settings`
--
ALTER TABLE `tb_user_settings`
  ADD CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
