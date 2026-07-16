-- Pension Workflow System Database Schema (normalized)
-- Generated from pension_db.sql + normalize_db.sql + app runtime schema requirements

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 22, 2026 at 09:48 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pension_db`
--
-- Notes for maintainers:
-- 1) This file is intended to stand alone: it creates the database, base tables,
--    normalization-oriented indexes, and the foreign-key graph required by the app.
-- 2) Tables are grouped by functional domain so developers can map schema sections
--    back to the main modules in the PHP and JS codebase.
-- 3) Index comments focus on non-obvious lookup and reconciliation patterns that are
--    performance-sensitive in the current application.
-- 4) Relationship comments explain the ownership rules behind cascade / restrict /
--    set-null behavior so future changes do not break workflow or audit semantics.

CREATE DATABASE IF NOT EXISTS `pension_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `pension_db`;

-- --------------------------------------------------------

--
-- Domain Group: Analytics, digests, and operational telemetry.
-- These tables back administrative insight widgets, digest generation, and
-- platform-level operational snapshots rather than end-user transactions.
--
--
-- Table structure for table `tb_analytics_digest_runs`
--

CREATE TABLE IF NOT EXISTS `tb_analytics_digest_runs` (
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

CREATE TABLE IF NOT EXISTS `tb_analytics_snapshots` (
  `snapshot_id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_type` varchar(80) NOT NULL,
  `snapshot_payload` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Domain Group: Workflow intake and application progression.
-- These tables model the path from staff-due records into queue-driven claim
-- handling and preserve the current stage / actor trail used by dashboards.
--
--
-- Table structure for table `tb_application_queue`
--

CREATE TABLE IF NOT EXISTS `tb_application_queue` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_appnstatus`
--

CREATE TABLE IF NOT EXISTS `tb_appnstatus` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_appnsubmissions`
--

CREATE TABLE IF NOT EXISTS `tb_appnsubmissions` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_app_settings`
--

CREATE TABLE IF NOT EXISTS `tb_app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Domain Group: Arrears, accountability, and budget planning.
-- The arrears subsystem is intentionally normalized so ledger, payment,
-- allocation, and accountability evidence can evolve independently.
--
--
-- Table structure for table `tb_arrearstracking`
--

CREATE TABLE IF NOT EXISTS `tb_arrearstracking` (
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

CREATE TABLE IF NOT EXISTS `tb_arrears_accountability_files` (
  `file_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_accountability_submissions`
--

CREATE TABLE IF NOT EXISTS `tb_arrears_accountability_submissions` (
  `submission_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `claim_type` varchar(80) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `status` enum('Submitted') NOT NULL DEFAULT 'Submitted',
  `notes` text DEFAULT NULL,
  `submitted_by` varchar(100) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_ledger`
--

CREATE TABLE IF NOT EXISTS `tb_arrears_ledger` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_payments`
--

CREATE TABLE IF NOT EXISTS `tb_arrears_payments` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_arrears_payment_allocations`
--

CREATE TABLE IF NOT EXISTS `tb_arrears_payment_allocations` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_audit_logs`
--

CREATE TABLE IF NOT EXISTS `tb_audit_logs` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_backup_logs`
--

CREATE TABLE IF NOT EXISTS `tb_backup_logs` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_broadcast_messages`
--

CREATE TABLE IF NOT EXISTS `tb_broadcast_messages` (
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

CREATE TABLE IF NOT EXISTS `tb_budgetforecast` (
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
-- Domain Group: Registry, claimant feedback, and file custody.
-- These tables represent the pension file registry, deletion / recycle flows,
-- file movement tracking, and user feedback captured around service delivery.
--
--
-- Table structure for table `tb_claimstatus`
--

CREATE TABLE IF NOT EXISTS `tb_claimstatus` (
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

CREATE TABLE IF NOT EXISTS `tb_data_export_runs` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_data_import_runs`
--

CREATE TABLE IF NOT EXISTS `tb_data_import_runs` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_faq_entries`
--

CREATE TABLE IF NOT EXISTS `tb_faq_entries` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_feedback_activity`
--

CREATE TABLE IF NOT EXISTS `tb_feedback_activity` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_feedback_submissions`
--

CREATE TABLE IF NOT EXISTS `tb_feedback_submissions` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_fileregistry`
--

CREATE TABLE IF NOT EXISTS `tb_fileregistry` (
    `id` int(11) NOT NULL,
    `employeeNo` varchar(50) DEFAULT NULL,
    `pensionNo` varchar(50) DEFAULT NULL,
    `ippsNo` varchar(50) DEFAULT NULL,
    `firstName` varchar(100) DEFAULT NULL,
    `middleName` varchar(100) DEFAULT NULL,
    `lastName` varchar(100) DEFAULT NULL,
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
  `dateOfDeath` date DEFAULT NULL,
  `deathNotificationDate` date DEFAULT NULL,
  `deathNotifierName` varchar(160) DEFAULT NULL,
  `deathNotifierContact` varchar(80) DEFAULT NULL,
  `estateExpiryDate` date DEFAULT NULL,
  `estateStatus` varchar(50) DEFAULT NULL,
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_file_movements`
--

CREATE TABLE IF NOT EXISTS `tb_file_movements` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_file_registry_delete_requests`
--

CREATE TABLE IF NOT EXISTS `tb_file_registry_delete_requests` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_file_registry_recycle_bin`
--

CREATE TABLE IF NOT EXISTS `tb_file_registry_recycle_bin` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_file_scan_logs`
--

CREATE TABLE IF NOT EXISTS `tb_file_scan_logs` (
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

--
--


-- --------------------------------------------------------

--
-- Domain Group: Monthly gratuity schedule analysis bridge.
-- These tables are not simple imports; they store the comparison between the
-- monthly public-service gratuity schedule and registry / arrears expectations.
--
--
-- Table structure for table `tb_gratuity_schedule_allocations`
--

CREATE TABLE IF NOT EXISTS `tb_gratuity_schedule_allocations` (
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

CREATE TABLE IF NOT EXISTS `tb_gratuity_schedule_cycles` (
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

CREATE TABLE IF NOT EXISTS `tb_gratuity_schedule_entries` (
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

CREATE TABLE IF NOT EXISTS `tb_ip_geolocation` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_lifecertificates`
--

CREATE TABLE IF NOT EXISTS `tb_lifecertificates` (
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

CREATE TABLE IF NOT EXISTS `tb_life_certificate_submissions` (
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

--
--


-- --------------------------------------------------------

--
-- Domain Group: Messaging, attachments, notifications, and outreach.
-- These tables support internal messaging, podcast analytics, notification
-- queues, and user-facing information channels.
--
--
-- Table structure for table `tb_messages`
--

CREATE TABLE IF NOT EXISTS `tb_messages` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_message_attachments`
--

CREATE TABLE IF NOT EXISTS `tb_message_attachments` (
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

CREATE TABLE IF NOT EXISTS `tb_message_recipients` (
  `recipient_id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `recipient_user_id` varchar(100) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_message_storage_snapshots`
--

CREATE TABLE IF NOT EXISTS `tb_message_storage_snapshots` (
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

CREATE TABLE IF NOT EXISTS `tb_notification_digest_runs` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_notification_queue`
--

CREATE TABLE IF NOT EXISTS `tb_notification_queue` (
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
--


-- --------------------------------------------------------

--
-- Domain Group: Payroll ingestion and reconciliation.
-- These tables support raw payroll uploads, audit logs, and registry-to-payroll
-- monthly status snapshots used by claims and budgeting workflows.
--
--
-- Table structure for table `tb_payrolls`
--

CREATE TABLE IF NOT EXISTS `tb_payrolls` (
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
-- Table structure for table `tb_pensioner_death_reports`
--

CREATE TABLE IF NOT EXISTS `tb_pensioner_death_reports` (
  `report_id` int(11) NOT NULL,
  `registry_id` int(11) NOT NULL,
  `regNo` varchar(50) NOT NULL,
  `date_of_death` date NOT NULL,
  `notifier_name` varchar(160) NOT NULL,
  `notifier_contact` varchar(80) NOT NULL,
  `notification_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(100) NOT NULL,
  `recorded_by_name` varchar(120) DEFAULT NULL,
  `recorded_by_role` varchar(60) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_arrears`
--

CREATE TABLE IF NOT EXISTS `tb_payroll_arrears` (
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

CREATE TABLE IF NOT EXISTS `tb_payroll_audit_logs` (
  `audit_id` int(11) NOT NULL,
  `cycle_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `actor_user_id` varchar(100) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_gratuity`
--

CREATE TABLE IF NOT EXISTS `tb_payroll_gratuity` (
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

CREATE TABLE IF NOT EXISTS `tb_payroll_pension` (
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

CREATE TABLE IF NOT EXISTS `tb_payroll_suspended` (
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

CREATE TABLE IF NOT EXISTS `tb_payroll_upload_cycles` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_payroll_upload_entries`
--

CREATE TABLE IF NOT EXISTS `tb_payroll_upload_entries` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_podcast_videos`
--

CREATE TABLE IF NOT EXISTS `tb_podcast_videos` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_podcast_views`
--

CREATE TABLE IF NOT EXISTS `tb_podcast_views` (
  `view_id` int(11) NOT NULL,
  `podcast_id` int(11) NOT NULL,
  `viewer_id` varchar(100) DEFAULT NULL,
  `viewer_role` varchar(50) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Domain Group: Reference data and institutional master lists.
-- Lookup tables below are intentionally lightweight but indexed because they
-- drive select widgets, reporting filters, and standardization across forms.
--
--
-- Table structure for table `tb_poldistricts`
--

CREATE TABLE IF NOT EXISTS `tb_poldistricts` (
  `Id` int(3) NOT NULL,
  `polDistrict` text NOT NULL,
  `polRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_pridistricts`
--

CREATE TABLE IF NOT EXISTS `tb_pridistricts` (
  `Id` int(3) NOT NULL,
  `priDistrict` text NOT NULL,
  `priRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_priregions`
--

CREATE TABLE IF NOT EXISTS `tb_priregions` (
  `Id` int(3) NOT NULL,
  `priRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_priunits`
--

CREATE TABLE IF NOT EXISTS `tb_priunits` (
  `Id` int(3) NOT NULL,
  `priUnit` text NOT NULL,
  `polDistrict` text NOT NULL,
  `priDistrict` text NOT NULL,
  `priRegion` text NOT NULL,
  `polRegion` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_registry_payroll_monthly_status`
--

CREATE TABLE IF NOT EXISTS `tb_registry_payroll_monthly_status` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_retained_payments`
--

CREATE TABLE IF NOT EXISTS `tb_retained_payments` (
  `id` int(11) NOT NULL,
  `supplierNo` varchar(50) DEFAULT NULL,
  `month` date DEFAULT NULL,
  `retainedAmount` decimal(10,2) DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Domain Group: Roles, security, staff-due master records, and task execution.
-- This part of the schema holds the core subject record (`tb_staffdue`), the
-- tasking model around it, and the access-control structures that govern edits.
--
--
-- Table structure for table `tb_roles`
--

CREATE TABLE IF NOT EXISTS `tb_roles` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_role_permissions`
--

CREATE TABLE IF NOT EXISTS `tb_role_permissions` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_session_metrics`
--

CREATE TABLE IF NOT EXISTS `tb_session_metrics` (
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

CREATE TABLE IF NOT EXISTS `tb_session_settings` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_staffdue`
--

CREATE TABLE IF NOT EXISTS `tb_staffdue` (
    `id` int(11) NOT NULL,
    `employeeNo` varchar(50) DEFAULT NULL,
    `pensionNo` varchar(50) DEFAULT NULL,
    `ippsNo` varchar(50) DEFAULT NULL,
    `firstName` varchar(100) DEFAULT NULL,
    `middleName` varchar(100) DEFAULT NULL,
    `lastName` varchar(100) DEFAULT NULL,
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_staff_documents`
--

CREATE TABLE IF NOT EXISTS `tb_staff_documents` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_staff_due_delete_requests`
--

CREATE TABLE IF NOT EXISTS `tb_staff_due_delete_requests` (
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

CREATE TABLE IF NOT EXISTS `tb_suspension_upload_cycles` (
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

CREATE TABLE IF NOT EXISTS `tb_suspension_upload_entries` (
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

CREATE TABLE IF NOT EXISTS `tb_system_logs` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_system_log_resolutions`
--

CREATE TABLE IF NOT EXISTS `tb_system_log_resolutions` (
  `resolution_id` bigint(20) UNSIGNED NOT NULL,
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `resolution_status` enum('acknowledged','resolved','dismissed') NOT NULL DEFAULT 'resolved',
  `resolution_note` text DEFAULT NULL,
  `resolved_by_id` varchar(50) DEFAULT NULL,
  `resolved_by_name` varchar(150) DEFAULT NULL,
  `resolved_by_role` varchar(80) DEFAULT NULL,
  `resolved_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_tasks`
--

CREATE TABLE IF NOT EXISTS `tb_tasks` (
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
  `status` enum('pending','assigned','in_progress','delegated','completed','declined','cancelled','deferred','returned') NOT NULL DEFAULT 'pending',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `related_staff_id` int(11) DEFAULT NULL,
  `related_reg_no` varchar(50) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `declined_reason` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `parent_task_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_task_alerts`
--

CREATE TABLE IF NOT EXISTS `tb_task_alerts` (
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

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_task_comments`
--

CREATE TABLE IF NOT EXISTS `tb_task_comments` (
  `comment_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `author_id` varchar(100) DEFAULT NULL,
  `author_name` varchar(100) DEFAULT NULL,
  `author_role` varchar(50) DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_task_completion_queue`
--

CREATE TABLE IF NOT EXISTS `tb_task_completion_queue` (
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

--
--

-- --------------------------------------------------------

--
-- Table structure for table `tb_task_delegation_logs`
--

CREATE TABLE IF NOT EXISTS `tb_task_delegation_logs` (
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
-- Table structure for table `tb_workflow_logs`
--

CREATE TABLE IF NOT EXISTS `tb_workflow_logs` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_terms_clauses`
--

CREATE TABLE IF NOT EXISTS `tb_terms_clauses` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_titles`
--

CREATE TABLE IF NOT EXISTS `tb_titles` (
  `title_id` int(11) NOT NULL,
  `title_name` varchar(120) NOT NULL,
  `category` enum('uniformed','non_uniformed') NOT NULL DEFAULT 'uniformed',
  `level` enum('junior','senior') NOT NULL DEFAULT 'junior',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_banks`
--

CREATE TABLE IF NOT EXISTS `tb_banks` (
  `bank_id` int(11) NOT NULL,
  `bank_name` varchar(180) NOT NULL,
  `short_name` varchar(100) DEFAULT NULL,
  `bank_code` varchar(30) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_uganda_public_holidays`
--

CREATE TABLE IF NOT EXISTS `tb_uganda_public_holidays` (
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_users`
--

CREATE TABLE IF NOT EXISTS `tb_users` (
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
  `password_updated_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_user_broadcast_status`
--

CREATE TABLE IF NOT EXISTS `tb_user_broadcast_status` (
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

CREATE TABLE IF NOT EXISTS `tb_user_logs` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_user_permissions`
--

CREATE TABLE IF NOT EXISTS `tb_user_permissions` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_user_sessions`
--

CREATE TABLE IF NOT EXISTS `tb_user_sessions` (
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
--


-- --------------------------------------------------------

--
-- Table structure for table `tb_user_settings`
--

CREATE TABLE IF NOT EXISTS `tb_user_settings` (
  `user_id` varchar(100) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -------------------------------------------------------------------
-- Idempotent schema alignment helpers
-- These procedures allow this file to act as both bootstrap and rerunnable
-- migration script. Fresh databases get full table creation; existing ones get
-- additive column alignment, guarded key creation, and guarded FK creation.
-- -------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS schema_exec_ddl$$
CREATE PROCEDURE schema_exec_ddl(IN p_ddl LONGTEXT)
BEGIN
    SET @schema_sql = p_ddl;
    PREPARE schema_stmt FROM @schema_sql;
    EXECUTE schema_stmt;
    DEALLOCATE PREPARE schema_stmt;
END$$

DROP PROCEDURE IF EXISTS schema_add_column_if_missing$$
CREATE PROCEDURE schema_add_column_if_missing(
    IN p_table_name VARCHAR(128),
    IN p_column_name VARCHAR(128),
    IN p_column_definition LONGTEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = p_table_name
      AND column_name = p_column_name;

    IF v_exists = 0 THEN
        CALL schema_exec_ddl(CONCAT(
            'ALTER TABLE `', p_table_name, '` ADD COLUMN ', p_column_definition
        ));
    END IF;
END$$

DROP PROCEDURE IF EXISTS schema_add_key_if_missing$$
CREATE PROCEDURE schema_add_key_if_missing(
    IN p_table_name VARCHAR(128),
    IN p_index_name VARCHAR(128),
    IN p_add_clause LONGTEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = p_table_name
      AND index_name = p_index_name;

    IF v_exists = 0 THEN
        CALL schema_exec_ddl(CONCAT(
            'ALTER TABLE `', p_table_name, '` ', p_add_clause
        ));
    END IF;
END$$

DROP PROCEDURE IF EXISTS schema_add_fk_if_missing$$
CREATE PROCEDURE schema_add_fk_if_missing(
    IN p_table_name VARCHAR(128),
    IN p_constraint_name VARCHAR(128),
    IN p_add_clause LONGTEXT
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = p_table_name
      AND constraint_name = p_constraint_name;

    IF v_exists = 0 THEN
        CALL schema_exec_ddl(CONCAT(
            'ALTER TABLE `', p_table_name, '` ', p_add_clause
        ));
    END IF;
END$$

DELIMITER ;

-- -------------------------------------------------------------------
-- Column alignment (additive migration layer)
-- These statements backfill missing columns on existing databases so the
-- schema remains aligned with the app's current create/ensure logic.
-- -------------------------------------------------------------------
-- Table: `tb_analytics_digest_runs`
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'digest_id', '`digest_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'digest_date', '`digest_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'run_type', '`run_type` enum(''scheduled'',''manual'',''preview'') NOT NULL DEFAULT ''scheduled''');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'digest_frequency', '`digest_frequency` varchar(20) NOT NULL DEFAULT ''weekly''');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'recipient', '`recipient` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'subject', '`subject` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'status', '`status` varchar(20) NOT NULL DEFAULT ''queued''');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'summary_json', '`summary_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'created_by', '`created_by` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'created_by_role', '`created_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_analytics_digest_runs', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_analytics_snapshots`
CALL schema_add_column_if_missing('tb_analytics_snapshots', 'snapshot_id', '`snapshot_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_analytics_snapshots', 'snapshot_type', '`snapshot_type` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_analytics_snapshots', 'snapshot_payload', '`snapshot_payload` longtext NOT NULL');
CALL schema_add_column_if_missing('tb_analytics_snapshots', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_application_queue`
CALL schema_add_column_if_missing('tb_application_queue', 'queue_id', '`queue_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'staffdue_id', '`staffdue_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'current_stage', '`current_stage` varchar(50) NOT NULL DEFAULT ''verified''');
CALL schema_add_column_if_missing('tb_application_queue', 'status', '`status` enum(''verified'',''submitted_to_oc'',''in_progress'',''completed'',''dropped'') NOT NULL DEFAULT ''verified''');
CALL schema_add_column_if_missing('tb_application_queue', 'verified_by', '`verified_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'verified_at', '`verified_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'submitted_by', '`submitted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'submitted_at', '`submitted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_application_queue', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_appnstatus`
CALL schema_add_column_if_missing('tb_appnstatus', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'computerNo', '`computerNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'verification', '`verification` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'writeUp', '`writeUp` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'fileCreation', '`fileCreation` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'entrantAllocation', '`entrantAllocation` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'dataCapture', '`dataCapture` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'assessment', '`assessment` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'audit', '`audit` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'approval', '`approval` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'payrollAccess', '`payrollAccess` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'other', '`other` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_appnstatus', 'verification_at', '`verification_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'verification_by', '`verification_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'verification_comment', '`verification_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'writeUp_at', '`writeUp_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'writeUp_by', '`writeUp_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'writeUp_comment', '`writeUp_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'fileCreation_at', '`fileCreation_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'fileCreation_by', '`fileCreation_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'fileCreation_comment', '`fileCreation_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'entrantAllocation_at', '`entrantAllocation_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'entrantAllocation_by', '`entrantAllocation_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'entrantAllocation_comment', '`entrantAllocation_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'dataCapture_at', '`dataCapture_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'dataCapture_by', '`dataCapture_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'dataCapture_comment', '`dataCapture_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'assessment_at', '`assessment_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'assessment_by', '`assessment_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'assessment_comment', '`assessment_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'audit_at', '`audit_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'audit_by', '`audit_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'audit_comment', '`audit_comment` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'approval_at', '`approval_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'approval_by', '`approval_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnstatus', 'approval_comment', '`approval_comment` text DEFAULT NULL');

-- Table: `tb_appnsubmissions`
CALL schema_add_column_if_missing('tb_appnsubmissions', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'title', '`title` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'sName', '`sName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'fName', '`fName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'appnType', '`appnType` enum(''Pension'',''Gratuity'',''Arrears'',''Full Pension'',''Underpayment'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'contact', '`contact` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'address', '`address` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'retirementDate', '`retirementDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'retirementType', '`retirementType` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'submissionDate', '`submissionDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_appnsubmissions', 'comment', '`comment` text DEFAULT NULL');

-- Table: `tb_app_settings`
CALL schema_add_column_if_missing('tb_app_settings', 'setting_key', '`setting_key` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_app_settings', 'setting_value', '`setting_value` text NOT NULL');
CALL schema_add_column_if_missing('tb_app_settings', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_arrearstracking`
CALL schema_add_column_if_missing('tb_arrearstracking', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'arrearsType', '`arrearsType` enum(''Pension'',''Gratuity'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'amount', '`amount` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'periodStart', '`periodStart` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'periodEnd', '`periodEnd` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrearstracking', 'recordedAt', '`recordedAt` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_arrearstracking', 'recordedBy', '`recordedBy` varchar(100) DEFAULT NULL');

-- Table: `tb_arrears_accountability_files`
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'file_id', '`file_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'submission_id', '`submission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'file_name', '`file_name` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'file_path', '`file_path` varchar(500) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'mime_type', '`mime_type` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'file_size', '`file_size` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_files', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_arrears_accountability_submissions`
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'submission_id', '`submission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'claim_type', '`claim_type` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'payment_id', '`payment_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'status', '`status` enum(''Submitted'') NOT NULL DEFAULT ''Submitted''');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'submitted_by', '`submitted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_accountability_submissions', 'submitted_at', '`submitted_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_arrears_ledger`
CALL schema_add_column_if_missing('tb_arrears_ledger', 'ledger_id', '`ledger_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'claim_type', '`claim_type` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'period_year', '`period_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'period_month', '`period_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'financial_year_label', '`financial_year_label` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'quarter_label', '`quarter_label` varchar(6) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'expected_amount', '`expected_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'paid_amount', '`paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'balance_amount', '`balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'status', '`status` enum(''Pending'',''Partially Paid'',''Paid'',''Waived'') NOT NULL DEFAULT ''Pending''');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'source_type', '`source_type` varchar(40) NOT NULL DEFAULT ''missed_payment''');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'reference_cycle_id', '`reference_cycle_id` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'reason', '`reason` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'recorded_by', '`recorded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'recorded_at', '`recorded_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'settled_at', '`settled_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'accountability_required', '`accountability_required` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'accountability_status', '`accountability_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_ledger', 'claim_status', '`claim_status` varchar(20) NOT NULL DEFAULT ''Incomplete''');

-- Table: `tb_arrears_payments`
CALL schema_add_column_if_missing('tb_arrears_payments', 'payment_id', '`payment_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'claim_type', '`claim_type` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'amount', '`amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_payments', 'applied_amount', '`applied_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_payments', 'unapplied_amount', '`unapplied_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_payments', 'payment_date', '`payment_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'reference_no', '`reference_no` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'recorded_by', '`recorded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_arrears_payments', 'payment_financial_year_label', '`payment_financial_year_label` varchar(20) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'accountability_required', '`accountability_required` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_arrears_payments', 'accountability_status', '`accountability_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'accountability_submitted_at', '`accountability_submitted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payments', 'latest_submission_id', '`latest_submission_id` int(11) DEFAULT NULL');

-- Table: `tb_arrears_payment_allocations`
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'allocation_id', '`allocation_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'payment_id', '`payment_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'ledger_id', '`ledger_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'claim_type', '`claim_type` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'applied_amount', '`applied_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'accrual_financial_year_label', '`accrual_financial_year_label` varchar(20) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'payment_financial_year_label', '`payment_financial_year_label` varchar(20) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'requires_accountability', '`requires_accountability` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'accountability_status', '`accountability_status` enum(''Not Required'',''Pending Accountability'',''Accountability Submitted'') NOT NULL DEFAULT ''Not Required''');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'accountability_submission_id', '`accountability_submission_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'accountability_submitted_at', '`accountability_submitted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_arrears_payment_allocations', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_audit_logs`
CALL schema_add_column_if_missing('tb_audit_logs', 'audit_id', '`audit_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'actor_id', '`actor_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'actor_name', '`actor_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'actor_role', '`actor_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'action', '`action` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'entity_type', '`entity_type` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'entity_id', '`entity_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'details', '`details` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_audit_logs', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_backup_logs`
CALL schema_add_column_if_missing('tb_backup_logs', 'backup_id', '`backup_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'backup_label', '`backup_label` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'backup_type', '`backup_type` enum(''manual'',''auto'',''restore_point'') NOT NULL DEFAULT ''manual''');
CALL schema_add_column_if_missing('tb_backup_logs', 'backup_scope', '`backup_scope` enum(''full_system'',''database_only'',''uploads_only'') NOT NULL DEFAULT ''full_system''');
CALL schema_add_column_if_missing('tb_backup_logs', 'file_name', '`file_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'file_path', '`file_path` varchar(500) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'file_size_bytes', '`file_size_bytes` bigint(20) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_backup_logs', 'checksum_sha256', '`checksum_sha256` varchar(128) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'include_uploads', '`include_uploads` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_backup_logs', 'backup_time', '`backup_time` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_backup_logs', 'status', '`status` enum(''success'',''failed'',''restored'',''partial'') NOT NULL DEFAULT ''success''');
CALL schema_add_column_if_missing('tb_backup_logs', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'created_by', '`created_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'created_by_role', '`created_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'restored_at', '`restored_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_backup_logs', 'restored_by', '`restored_by` varchar(100) DEFAULT NULL');

-- Table: `tb_broadcast_messages`
CALL schema_add_column_if_missing('tb_broadcast_messages', 'broadcast_id', '`broadcast_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_broadcast_messages', 'message_id', '`message_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_broadcast_messages', 'target_roles', '`target_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_roles`))');
CALL schema_add_column_if_missing('tb_broadcast_messages', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL schema_add_column_if_missing('tb_broadcast_messages', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_budgetforecast`
CALL schema_add_column_if_missing('tb_budgetforecast', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'financialYear', '`financialYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedPensionAmount', '`estimatedPensionAmount` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedGratuityAmount', '`estimatedGratuityAmount` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'createdBy', '`createdBy` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'createdAt', '`createdAt` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedPensionArrears', '`estimatedPensionArrears` decimal(14,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedFullPensionArrears', '`estimatedFullPensionArrears` decimal(14,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedGratuityArrears', '`estimatedGratuityArrears` decimal(14,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedUnderpaymentClaims', '`estimatedUnderpaymentClaims` decimal(14,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'estimatedSuspensionArrears', '`estimatedSuspensionArrears` decimal(14,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_budgetforecast', 'notes', '`notes` text DEFAULT NULL');

-- Table: `tb_claimstatus`
CALL schema_add_column_if_missing('tb_claimstatus', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'computerNo', '`computerNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'appnType', '`appnType` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'verificationDate', '`verificationDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'appnStatus', '`appnStatus` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_claimstatus', 'comment', '`comment` text DEFAULT NULL');

-- Table: `tb_data_export_runs`
CALL schema_add_column_if_missing('tb_data_export_runs', 'export_id', '`export_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'dataset_key', '`dataset_key` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'dataset_label', '`dataset_label` varchar(180) NOT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'export_format', '`export_format` enum(''csv'',''xlsx'',''json'') NOT NULL DEFAULT ''xlsx''');
CALL schema_add_column_if_missing('tb_data_export_runs', 'file_name', '`file_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'file_path', '`file_path` varchar(500) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'file_size_bytes', '`file_size_bytes` bigint(20) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_export_runs', 'filters_json', '`filters_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'status', '`status` enum(''success'',''failed'') NOT NULL DEFAULT ''success''');
CALL schema_add_column_if_missing('tb_data_export_runs', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_data_export_runs', 'created_by', '`created_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_export_runs', 'created_by_role', '`created_by_role` varchar(80) DEFAULT NULL');

-- Table: `tb_data_import_runs`
CALL schema_add_column_if_missing('tb_data_import_runs', 'import_run_id', '`import_run_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'dataset_key', '`dataset_key` varchar(60) NOT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'dataset_label', '`dataset_label` varchar(160) NOT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'source_file_name', '`source_file_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'source_extension', '`source_extension` varchar(12) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'execution_mode', '`execution_mode` enum(''dry_run'',''import'') NOT NULL DEFAULT ''import''');
CALL schema_add_column_if_missing('tb_data_import_runs', 'run_status', '`run_status` enum(''success'',''partial'',''failed'') NOT NULL DEFAULT ''success''');
CALL schema_add_column_if_missing('tb_data_import_runs', 'total_rows', '`total_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'inserted_rows', '`inserted_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'merged_rows', '`merged_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'skipped_exact_rows', '`skipped_exact_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'conflict_rows', '`conflict_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'invalid_rows', '`invalid_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'failed_rows', '`failed_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_data_import_runs', 'report_json', '`report_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'created_by', '`created_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'created_by_role', '`created_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_data_import_runs', 'started_at', '`started_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_data_import_runs', 'completed_at', '`completed_at` timestamp NULL DEFAULT NULL');

-- Table: `tb_faq_entries`
CALL schema_add_column_if_missing('tb_faq_entries', 'faq_id', '`faq_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_faq_entries', 'question', '`question` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_faq_entries', 'answer', '`answer` text NOT NULL');
CALL schema_add_column_if_missing('tb_faq_entries', 'bullets', '`bullets` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_faq_entries', 'category', '`category` varchar(50) NOT NULL DEFAULT ''applications''');
CALL schema_add_column_if_missing('tb_faq_entries', 'audience_label', '`audience_label` varchar(120) NOT NULL DEFAULT ''Pensioners, staff, and supervisors''');
CALL schema_add_column_if_missing('tb_faq_entries', 'is_featured', '`is_featured` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_faq_entries', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_faq_entries', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_faq_entries', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_faq_entries', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_feedback_activity`
CALL schema_add_column_if_missing('tb_feedback_activity', 'activity_id', '`activity_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'submission_id', '`submission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'action', '`action` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'actor_id', '`actor_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'actor_name', '`actor_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'actor_role', '`actor_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'from_status', '`from_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'to_status', '`to_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'note', '`note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'field_changes', '`field_changes` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_activity', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_feedback_submissions`
CALL schema_add_column_if_missing('tb_feedback_submissions', 'submission_id', '`submission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'reference_no', '`reference_no` varchar(40) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'feedback_type', '`feedback_type` varchar(60) NOT NULL DEFAULT ''general_feedback''');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'audience', '`audience` enum(''public'',''staff'',''pensioner'') NOT NULL DEFAULT ''public''');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'full_name', '`full_name` varchar(180) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'email_address', '`email_address` varchar(190) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'phone_number', '`phone_number` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'subject', '`subject` varchar(220) NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'message', '`message` text NOT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'page_context', '`page_context` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'submitted_by_user_id', '`submitted_by_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'submitted_by_role', '`submitted_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'status', '`status` enum(''new'',''reviewed'',''resolved'',''closed'') NOT NULL DEFAULT ''new''');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'submitted_at', '`submitted_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'priority', '`priority` varchar(20) NOT NULL DEFAULT ''normal''');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'assigned_to_user_id', '`assigned_to_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'assigned_to_name', '`assigned_to_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'assigned_to_role', '`assigned_to_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'assigned_at', '`assigned_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'reviewed_at', '`reviewed_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'reviewed_by_user_id', '`reviewed_by_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'reviewed_by_name', '`reviewed_by_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'reviewed_by_role', '`reviewed_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'resolved_at', '`resolved_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'resolved_by_user_id', '`resolved_by_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'resolved_by_name', '`resolved_by_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'resolved_by_role', '`resolved_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'closed_at', '`closed_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'closed_by_user_id', '`closed_by_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'closed_by_name', '`closed_by_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'closed_by_role', '`closed_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_feedback_submissions', 'resolution_summary', '`resolution_summary` text DEFAULT NULL');

-- Table: `tb_fileregistry`
CALL schema_add_column_if_missing('tb_fileregistry', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'computerNo', '`computerNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'title', '`title` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'sName', '`sName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'fName', '`fName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'gender', '`gender` enum(''Male'',''Female'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'livingStatus', '`livingStatus` enum(''Alive'',''Deceased'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'lifeCertificate', '`lifeCertificate` enum(''Submitted'',''Not Submitted'',''Exempt'') DEFAULT ''Not Submitted''');
CALL schema_add_column_if_missing('tb_fileregistry', 'boxNo', '`boxNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'birthDate', '`birthDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'enlistmentDate', '`enlistmentDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'retirementDate', '`retirementDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'retirementType', '`retirementType` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'TIN', '`TIN` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'NIN', '`NIN` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'address', '`address` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'payrollStatus', '`payrollStatus` enum(''On Payroll'',''Not on Payroll'') DEFAULT ''Not on Payroll''');
CALL schema_add_column_if_missing('tb_fileregistry', 'payType', '`payType` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'dateOn15yrs', '`dateOn15yrs` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'periodTo15yrs', '`periodTo15yrs` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'periodFrom15yrs', '`periodFrom15yrs` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'dateOfDeath', '`dateOfDeath` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deathNotificationDate', '`deathNotificationDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deathNotifierName', '`deathNotifierName` varchar(160) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deathNotifierContact', '`deathNotifierContact` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'estateExpiryDate', '`estateExpiryDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'estateStatus', '`estateStatus` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_fileregistry', 'other', '`other` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'availability_status', '`availability_status` varchar(40) DEFAULT ''in_shelf''');
CALL schema_add_column_if_missing('tb_fileregistry', 'availability_reason', '`availability_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'telNo', '`telNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'applicant_email', '`applicant_email` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'next_of_kin', '`next_of_kin` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'next_of_kin_contact', '`next_of_kin_contact` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'bank_name', '`bank_name` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'bank_account', '`bank_account` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'bank_branch', '`bank_branch` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'lookup_contact_opt_in', '`lookup_contact_opt_in` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_fileregistry', 'lookup_contact_updated_at', '`lookup_contact_updated_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'monthlySalary', '`monthlySalary` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'lengthOfService', '`lengthOfService` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'annualSalary', '`annualSalary` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'reducedPension', '`reducedPension` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'fullPension', '`fullPension` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'gratuity', '`gratuity` decimal(12,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_fileregistry', 'deleted_at', '`deleted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deleted_by', '`deleted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deleted_by_name', '`deleted_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'deleted_by_role', '`deleted_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'delete_reason', '`delete_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'workflow_auto_arrears_enabled', '`workflow_auto_arrears_enabled` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_fileregistry', 'workflow_auto_arrears_enabled_at', '`workflow_auto_arrears_enabled_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_fileregistry', 'workflow_auto_arrears_source', '`workflow_auto_arrears_source` varchar(40) DEFAULT NULL');

-- Table: `tb_file_movements`
CALL schema_add_column_if_missing('tb_file_movements', 'movement_id', '`movement_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'file_id', '`file_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'from_office', '`from_office` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'to_office', '`to_office` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'reason', '`reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'delivered_by', '`delivered_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'received_by', '`received_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'moved_at', '`moved_at` datetime NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_file_movements', 'expected_return_at', '`expected_return_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_movements', 'returned_at', '`returned_at` datetime DEFAULT NULL');

-- Table: `tb_file_registry_delete_requests`
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'request_id', '`request_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'registry_id', '`registry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'requested_by', '`requested_by` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'requested_by_name', '`requested_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'requested_by_role', '`requested_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'reason', '`reason` text NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'status', '`status` enum(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending''');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'processed_by', '`processed_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'processed_by_name', '`processed_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'processed_by_role', '`processed_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'processed_note', '`processed_note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'processed_at', '`processed_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'staff_name', '`staff_name` varchar(160) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_delete_requests', 'staff_title', '`staff_title` varchar(120) DEFAULT NULL');

-- Table: `tb_file_registry_recycle_bin`
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'recycle_id', '`recycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'registry_id', '`registry_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'staff_name', '`staff_name` varchar(160) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'staff_title', '`staff_title` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'delete_request_id', '`delete_request_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'delete_reason', '`delete_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'deleted_by', '`deleted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'deleted_by_name', '`deleted_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'deleted_by_role', '`deleted_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'deleted_at', '`deleted_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'record_snapshot', '`record_snapshot` longtext NOT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'restored', '`restored` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'restored_by', '`restored_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'restored_by_name', '`restored_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'restored_by_role', '`restored_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_registry_recycle_bin', 'restored_at', '`restored_at` datetime DEFAULT NULL');

-- Table: `tb_file_scan_logs`
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scan_id', '`scan_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'storage_context', '`storage_context` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'file_name', '`file_name` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'file_path', '`file_path` varchar(500) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'file_hash', '`file_hash` varchar(64) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'mime_type', '`mime_type` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scan_engine', '`scan_engine` varchar(80) NOT NULL DEFAULT ''heuristic''');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scan_status', '`scan_status` enum(''clean'',''infected'',''suspicious'',''error'',''skipped'') NOT NULL DEFAULT ''clean''');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'findings', '`findings` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scanned_by', '`scanned_by` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scanned_by_name', '`scanned_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scanned_by_role', '`scanned_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_file_scan_logs', 'scanned_at', '`scanned_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_gratuity_schedule_allocations`
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'allocation_id', '`allocation_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'cycle_id', '`cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'entry_id', '`entry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'matched_regNo', '`matched_regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'ledger_id', '`ledger_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'period_year', '`period_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'period_month', '`period_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'claim_type', '`claim_type` varchar(80) NOT NULL DEFAULT ''Pension Arrears''');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'allocated_amount', '`allocated_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'monthly_pension_amount', '`monthly_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'allocation_status', '`allocation_status` varchar(30) NOT NULL DEFAULT ''scheduled''');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'note', '`note` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_allocations', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_gratuity_schedule_cycles`
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'cycle_id', '`cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'schedule_year', '`schedule_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'schedule_month', '`schedule_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'financial_year_label', '`financial_year_label` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'quarter_label', '`quarter_label` varchar(6) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'uploaded_by', '`uploaded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'source_file', '`source_file` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'source_file_original_name', '`source_file_original_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'source_file_mime', '`source_file_mime` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_rows', '`total_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'matched_rows', '`matched_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'unmatched_rows', '`unmatched_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'exact_gratuity_rows', '`exact_gratuity_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'partial_gratuity_rows', '`partial_gratuity_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'small_surplus_rows', '`small_surplus_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'pension_arrears_rows', '`pension_arrears_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'review_rows', '`review_rows` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_scheduled_amount', '`total_scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_gratuity_component', '`total_gratuity_component` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_small_surplus_amount', '`total_small_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_pension_surplus_amount', '`total_pension_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_allocated_pension_amount', '`total_allocated_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_unallocated_amount', '`total_unallocated_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'total_remaining_arrears_amount', '`total_remaining_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_gratuity_schedule_cycles', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');

-- Table: `tb_gratuity_schedule_entries`
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'entry_id', '`entry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'cycle_id', '`cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'row_number', '`row_number` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'beneficiary_name', '`beneficiary_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'scheduled_amount', '`scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'matched_regNo', '`matched_regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'matched_registry_id', '`matched_registry_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'matched_name', '`matched_name` varchar(180) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'registry_gratuity_estimate', '`registry_gratuity_estimate` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'latest_monthly_pension', '`latest_monthly_pension` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'monthly_pension_source', '`monthly_pension_source` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'open_pension_arrears_amount', '`open_pension_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'open_pension_arrears_months', '`open_pension_arrears_months` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'gratuity_component_amount', '`gratuity_component_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'pension_surplus_amount', '`pension_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'small_surplus_amount', '`small_surplus_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'allocated_pension_amount', '`allocated_pension_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'scheduled_full_months', '`scheduled_full_months` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'allocated_months', '`allocated_months` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'unallocated_scheduled_months', '`unallocated_scheduled_months` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'unallocated_scheduled_amount', '`unallocated_scheduled_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'remaining_arrears_months', '`remaining_arrears_months` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'remaining_arrears_amount', '`remaining_arrears_amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'classification', '`classification` varchar(80) NOT NULL DEFAULT ''review''');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'matching_basis', '`matching_basis` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'analysis_note', '`analysis_note` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'raw_payload', '`raw_payload` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'is_matched', '`is_matched` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_gratuity_schedule_entries', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_ip_geolocation`
CALL schema_add_column_if_missing('tb_ip_geolocation', 'ip_address', '`ip_address` varchar(45) NOT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'city', '`city` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'region', '`region` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'country', '`country` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'country_code', '`country_code` varchar(10) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'latitude', '`latitude` decimal(10,6) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'longitude', '`longitude` decimal(10,6) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'timezone', '`timezone` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'org', '`org` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'asn', '`asn` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'location_label', '`location_label` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'raw_json', '`raw_json` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_ip_geolocation', 'last_lookup', '`last_lookup` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_lifecertificates`
CALL schema_add_column_if_missing('tb_lifecertificates', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'computerNo', '`computerNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'sName', '`sName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'fName', '`fName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'nextOfKin', '`nextOfKin` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'nokContact', '`nokContact` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_lifecertificates', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_life_certificate_submissions`
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'submission_id', '`submission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'submission_year', '`submission_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'status', '`status` enum(''Submitted'') NOT NULL DEFAULT ''Submitted''');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'submitted_at', '`submitted_at` datetime NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'submitted_by', '`submitted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_life_certificate_submissions', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_messages`
CALL schema_add_column_if_missing('tb_messages', 'message_id', '`message_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_messages', 'sender_id', '`sender_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_messages', 'subject', '`subject` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_messages', 'message_text', '`message_text` text NOT NULL');
CALL schema_add_column_if_missing('tb_messages', 'message_type', '`message_type` enum(''direct'',''broadcast'',''group'') DEFAULT ''direct''');
CALL schema_add_column_if_missing('tb_messages', 'parent_message_id', '`parent_message_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_messages', 'is_urgent', '`is_urgent` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_messages', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_messages', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
CALL schema_add_column_if_missing('tb_messages', 'is_deleted', '`is_deleted` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_messages', 'is_deleted_by_sender', '`is_deleted_by_sender` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_messages', 'deleted_by_sender_at', '`deleted_by_sender_at` timestamp NULL DEFAULT NULL');

-- Table: `tb_message_attachments`
CALL schema_add_column_if_missing('tb_message_attachments', 'attachment_id', '`attachment_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'message_id', '`message_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'file_name', '`file_name` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'file_path', '`file_path` varchar(500) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'file_size', '`file_size` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'mime_type', '`mime_type` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'uploaded_at', '`uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_message_attachments', 'file_hash', '`file_hash` varchar(64) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_attachments', 'is_compressed', '`is_compressed` tinyint(1) NOT NULL DEFAULT 0');

-- Table: `tb_message_recipients`
CALL schema_add_column_if_missing('tb_message_recipients', 'recipient_id', '`recipient_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_message_recipients', 'message_id', '`message_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_message_recipients', 'recipient_user_id', '`recipient_user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_message_recipients', 'is_read', '`is_read` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_recipients', 'read_at', '`read_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_recipients', 'is_deleted', '`is_deleted` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_recipients', 'deleted_at', '`deleted_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_recipients', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_message_storage_snapshots`
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'snapshot_id', '`snapshot_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'snapshot_date', '`snapshot_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'snapshot_type', '`snapshot_type` enum(''auto'',''manual'') NOT NULL DEFAULT ''auto''');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'status', '`status` enum(''created'',''failed'') NOT NULL DEFAULT ''created''');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'file_name', '`file_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'file_path', '`file_path` varchar(500) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'file_size_bytes', '`file_size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'message_count', '`message_count` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'attachment_count', '`attachment_count` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'total_storage_bytes', '`total_storage_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'created_by', '`created_by` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'created_by_role', '`created_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_message_storage_snapshots', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_notification_digest_runs`
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'digest_id', '`digest_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'digest_date', '`digest_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'run_type', '`run_type` enum(''scheduled'',''manual'',''preview'') NOT NULL DEFAULT ''scheduled''');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'recipient', '`recipient` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'subject', '`subject` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'status', '`status` varchar(20) NOT NULL DEFAULT ''queued''');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'summary_json', '`summary_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'created_by', '`created_by` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'created_by_name', '`created_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'created_by_role', '`created_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_digest_runs', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_notification_queue`
CALL schema_add_column_if_missing('tb_notification_queue', 'notification_id', '`notification_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'channel', '`channel` enum(''email'',''sms'',''push'') NOT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'recipient', '`recipient` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'subject', '`subject` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'message', '`message` text NOT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'status', '`status` enum(''queued'',''sent'',''failed'') NOT NULL DEFAULT ''queued''');
CALL schema_add_column_if_missing('tb_notification_queue', 'meta', '`meta` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_notification_queue', 'attempts', '`attempts` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_notification_queue', 'processing_started_at', '`processing_started_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'last_attempted_at', '`last_attempted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'sent_at', '`sent_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'failed_at', '`failed_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'last_error', '`last_error` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_notification_queue', 'provider_reference', '`provider_reference` varchar(255) DEFAULT NULL');

-- Table: `tb_payrolls`
CALL schema_add_column_if_missing('tb_payrolls', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'payrollYear', '`payrollYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'payrollMonth', '`payrollMonth` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'record_type', '`record_type` enum(''Pension'',''Gratuity'',''Arrears'',''Suspended'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'file_path', '`file_path` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'uploaded_by', '`uploaded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payrolls', 'uploaded_at', '`uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_pensioner_death_reports`
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'report_id', '`report_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'registry_id', '`registry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'date_of_death', '`date_of_death` date NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'notifier_name', '`notifier_name` varchar(160) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'notifier_contact', '`notifier_contact` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'notification_date', '`notification_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'recorded_by', '`recorded_by` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'recorded_by_name', '`recorded_by_name` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'recorded_by_role', '`recorded_by_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_pensioner_death_reports', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_payroll_arrears`
CALL schema_add_column_if_missing('tb_payroll_arrears', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_arrears', 'payrollYear', '`payrollYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_arrears', 'payrollMonth', '`payrollMonth` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_arrears', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_arrears', 'amount', '`amount` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_arrears', 'status', '`status` enum(''Paid'',''Suspended'',''Pending'') DEFAULT NULL');

-- Table: `tb_payroll_audit_logs`
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'audit_id', '`audit_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'cycle_id', '`cycle_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'action', '`action` varchar(64) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'actor_user_id', '`actor_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'actor_role', '`actor_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'ip_address', '`ip_address` varchar(45) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'details', '`details` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_audit_logs', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_payroll_gratuity`
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'payrollYear', '`payrollYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'payrollMonth', '`payrollMonth` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'amount', '`amount` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_gratuity', 'status', '`status` enum(''Paid'',''Suspended'',''Pending'') DEFAULT NULL');

-- Table: `tb_payroll_pension`
CALL schema_add_column_if_missing('tb_payroll_pension', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_pension', 'payrollYear', '`payrollYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_pension', 'payrollMonth', '`payrollMonth` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_pension', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_pension', 'amount', '`amount` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_pension', 'status', '`status` enum(''Paid'',''Suspended'',''Pending'') DEFAULT NULL');

-- Table: `tb_payroll_suspended`
CALL schema_add_column_if_missing('tb_payroll_suspended', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_suspended', 'payrollYear', '`payrollYear` year(4) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_suspended', 'payrollMonth', '`payrollMonth` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_suspended', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_suspended', 'amount', '`amount` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_suspended', 'status', '`status` enum(''Paid'',''Suspended'',''Pending'') DEFAULT NULL');

-- Table: `tb_payroll_upload_cycles`
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'cycle_id', '`cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'payroll_year', '`payroll_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'payroll_month', '`payroll_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'financial_year_label', '`financial_year_label` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'quarter_label', '`quarter_label` varchar(6) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'uploaded_by', '`uploaded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'source_file', '`source_file` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'source_file_original_name', '`source_file_original_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'source_file_mime', '`source_file_mime` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'payment_register_file', '`payment_register_file` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'payment_register_original_name', '`payment_register_original_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'payment_register_mime', '`payment_register_mime` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'deleted_by', '`deleted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_cycles', 'deleted_at', '`deleted_at` datetime DEFAULT NULL');

-- Table: `tb_payroll_upload_entries`
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'entry_id', '`entry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'cycle_id', '`cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'supplierNo', '`supplierNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'beneficiary_name', '`beneficiary_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'amount', '`amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'matched_regNo', '`matched_regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'matched_registry_id', '`matched_registry_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'is_matched', '`is_matched` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_payroll_upload_entries', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_podcast_videos`
CALL schema_add_column_if_missing('tb_podcast_videos', 'podcast_id', '`podcast_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'title', '`title` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'description', '`description` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'audience', '`audience` enum(''public'',''staff'',''pensioner'') NOT NULL DEFAULT ''public''');
CALL schema_add_column_if_missing('tb_podcast_videos', 'youtube_url', '`youtube_url` varchar(500) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'youtube_id', '`youtube_id` varchar(32) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'tags', '`tags` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'is_featured', '`is_featured` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_podcast_videos', 'is_published', '`is_published` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_podcast_videos', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_podcast_videos', 'created_by', '`created_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'updated_by', '`updated_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_videos', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_podcast_videos', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_podcast_views`
CALL schema_add_column_if_missing('tb_podcast_views', 'view_id', '`view_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_views', 'podcast_id', '`podcast_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_podcast_views', 'viewer_id', '`viewer_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_views', 'viewer_role', '`viewer_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_views', 'session_id', '`session_id` varchar(128) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_podcast_views', 'viewed_at', '`viewed_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_poldistricts`
CALL schema_add_column_if_missing('tb_poldistricts', 'Id', '`Id` int(3) NOT NULL');
CALL schema_add_column_if_missing('tb_poldistricts', 'polDistrict', '`polDistrict` text NOT NULL');
CALL schema_add_column_if_missing('tb_poldistricts', 'polRegion', '`polRegion` text NOT NULL');

-- Table: `tb_pridistricts`
CALL schema_add_column_if_missing('tb_pridistricts', 'Id', '`Id` int(3) NOT NULL');
CALL schema_add_column_if_missing('tb_pridistricts', 'priDistrict', '`priDistrict` text NOT NULL');
CALL schema_add_column_if_missing('tb_pridistricts', 'priRegion', '`priRegion` text NOT NULL');

-- Table: `tb_priregions`
CALL schema_add_column_if_missing('tb_priregions', 'Id', '`Id` int(3) NOT NULL');
CALL schema_add_column_if_missing('tb_priregions', 'priRegion', '`priRegion` text NOT NULL');

-- Table: `tb_priunits`
CALL schema_add_column_if_missing('tb_priunits', 'Id', '`Id` int(3) NOT NULL');
CALL schema_add_column_if_missing('tb_priunits', 'priUnit', '`priUnit` text NOT NULL');
CALL schema_add_column_if_missing('tb_priunits', 'polDistrict', '`polDistrict` text NOT NULL');
CALL schema_add_column_if_missing('tb_priunits', 'priDistrict', '`priDistrict` text NOT NULL');
CALL schema_add_column_if_missing('tb_priunits', 'priRegion', '`priRegion` text NOT NULL');
CALL schema_add_column_if_missing('tb_priunits', 'polRegion', '`polRegion` text NOT NULL');

-- Table: `tb_registry_payroll_monthly_status`
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'status_id', '`status_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'regNo', '`regNo` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'payroll_year', '`payroll_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'payroll_month', '`payroll_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'financial_year_label', '`financial_year_label` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'quarter_label', '`quarter_label` varchar(6) NOT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'payroll_status', '`payroll_status` enum(''On Payroll'',''Not on Payroll'') NOT NULL DEFAULT ''Not on Payroll''');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'amount', '`amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'cycle_id', '`cycle_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_registry_payroll_monthly_status', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_retained_payments`
CALL schema_add_column_if_missing('tb_retained_payments', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_retained_payments', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_retained_payments', 'month', '`month` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_retained_payments', 'retainedAmount', '`retainedAmount` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_retained_payments', 'recorded_by', '`recorded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_retained_payments', 'recorded_at', '`recorded_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_roles`
CALL schema_add_column_if_missing('tb_roles', 'role_key', '`role_key` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_roles', 'role_label', '`role_label` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_roles', 'role_description', '`role_description` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_roles', 'clone_from_role', '`clone_from_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_roles', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_roles', 'is_system', '`is_system` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_roles', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_roles', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_role_permissions`
CALL schema_add_column_if_missing('tb_role_permissions', 'role_permission_id', '`role_permission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_role_permissions', 'role_key', '`role_key` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_role_permissions', 'permission_key', '`permission_key` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_role_permissions', 'is_allowed', '`is_allowed` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_role_permissions', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_role_permissions', 'updated_by', '`updated_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_role_permissions', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_role_permissions', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_session_metrics`
CALL schema_add_column_if_missing('tb_session_metrics', 'metric_id', '`metric_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_session_metrics', 'metric_time', '`metric_time` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_session_metrics', 'active_sessions', '`active_sessions` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'concurrent_conflicts', '`concurrent_conflicts` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'avg_session_duration', '`avg_session_duration` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'timeout_errors', '`timeout_errors` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'network_errors', '`network_errors` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'grace_period_uses', '`grace_period_uses` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'device_conflicts', '`device_conflicts` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'successful_logins', '`successful_logins` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_metrics', 'failed_logins', '`failed_logins` int(11) DEFAULT 0');

-- Table: `tb_session_settings`
CALL schema_add_column_if_missing('tb_session_settings', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_session_settings', 'max_concurrent_sessions', '`max_concurrent_sessions` int(11) DEFAULT 1');
CALL schema_add_column_if_missing('tb_session_settings', 'session_timeout', '`session_timeout` int(11) DEFAULT 1800');
CALL schema_add_column_if_missing('tb_session_settings', 'allow_multiple_devices', '`allow_multiple_devices` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_session_settings', 'auto_logout_on_conflict', '`auto_logout_on_conflict` tinyint(1) DEFAULT 1');
CALL schema_add_column_if_missing('tb_session_settings', 'inactivity_warning_minutes', '`inactivity_warning_minutes` int(11) DEFAULT 5');
CALL schema_add_column_if_missing('tb_session_settings', 'grace_period_minutes', '`grace_period_minutes` int(11) DEFAULT 5');
CALL schema_add_column_if_missing('tb_session_settings', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_session_settings', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_staffdue`
CALL schema_add_column_if_missing('tb_staffdue', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'computerNo', '`computerNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'title', '`title` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'sName', '`sName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'fName', '`fName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'gender', '`gender` enum(''Male'',''Female'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'prisonUnit', '`prisonUnit` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'NIN', '`NIN` text NOT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'telNo', '`telNo` varchar(15) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'birthDate', '`birthDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'enlistmentDate', '`enlistmentDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'retirementDate', '`retirementDate` date DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'financialYear', '`financialYear` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'retirementType', '`retirementType` varchar(60) DEFAULT NULL');

SET @staffdue_retirement_type_data_type = (
    SELECT LOWER(COALESCE(data_type, ''))
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tb_staffdue'
      AND column_name = 'retirementType'
    LIMIT 1
);
SET @staffdue_retirement_type_column_type = (
    SELECT LOWER(COALESCE(column_type, ''))
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tb_staffdue'
      AND column_name = 'retirementType'
    LIMIT 1
);
SET @schema_sql = IF(
    @staffdue_retirement_type_data_type = 'varchar' AND @staffdue_retirement_type_column_type LIKE 'varchar(60)%',
    'DO 0',
    'ALTER TABLE `tb_staffdue` MODIFY COLUMN `retirementType` varchar(60) DEFAULT NULL'
);
PREPARE schema_stmt FROM @schema_sql;
EXECUTE schema_stmt;
DEALLOCATE PREPARE schema_stmt;
CALL schema_add_column_if_missing('tb_staffdue', 'monthlySalary', '`monthlySalary` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'lengthOfService', '`lengthOfService` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'annualSalary', '`annualSalary` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'reducedPension', '`reducedPension` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'fullPension', '`fullPension` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'gratuity', '`gratuity` decimal(10,2) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'submissionStatus', '`submissionStatus` enum(''submitted'',''pending'') NOT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'appnStatus', '`appnStatus` enum(''pending'',''verified'',''querried'',''rejected'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'submission_at', '`submission_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'submission_by', '`submission_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'appn_status_at', '`appn_status_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'appn_status_by', '`appn_status_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'appn_status_reason', '`appn_status_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'address', '`address` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'TIN', '`TIN` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'next_of_kin', '`next_of_kin` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'next_of_kin_contact', '`next_of_kin_contact` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'bank_name', '`bank_name` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'bank_account', '`bank_account` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'bank_branch', '`bank_branch` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'applicant_email', '`applicant_email` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'documents_uploaded', '`documents_uploaded` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_staffdue', 'livingStatus', '`livingStatus` enum(''Alive'',''Deceased'') DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'payType', '`payType` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_staffdue', 'deleted_at', '`deleted_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'deleted_by', '`deleted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'deleted_by_name', '`deleted_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'deleted_by_role', '`deleted_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'delete_reason', '`delete_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staffdue', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_staff_documents`
CALL schema_add_column_if_missing('tb_staff_documents', 'document_id', '`document_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'staffdue_id', '`staffdue_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'doc_type', '`doc_type` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'file_name', '`file_name` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'file_path', '`file_path` varchar(500) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'file_size', '`file_size` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'mime_type', '`mime_type` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'uploaded_by', '`uploaded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'uploaded_at', '`uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_staff_documents', 'file_hash', '`file_hash` varchar(64) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_documents', 'is_archived', '`is_archived` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_staff_documents', 'archived_at', '`archived_at` datetime DEFAULT NULL');

-- Table: `tb_staff_due_delete_requests`
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'request_id', '`request_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'staffdue_id', '`staffdue_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'staff_name', '`staff_name` varchar(160) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'staff_title', '`staff_title` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'requested_by', '`requested_by` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'requested_by_name', '`requested_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'requested_by_role', '`requested_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'reason', '`reason` text NOT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'status', '`status` enum(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending''');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'processed_by', '`processed_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'processed_by_name', '`processed_by_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'processed_by_role', '`processed_by_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'processed_note', '`processed_note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_staff_due_delete_requests', 'processed_at', '`processed_at` datetime DEFAULT NULL');

-- Table: `tb_suspension_upload_cycles`
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'suspension_cycle_id', '`suspension_cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'suspension_year', '`suspension_year` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'suspension_month', '`suspension_month` tinyint(4) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'financial_year_label', '`financial_year_label` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'quarter_label', '`quarter_label` varchar(6) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'uploaded_by', '`uploaded_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'source_file', '`source_file` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'source_file_original_name', '`source_file_original_name` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'source_file_mime', '`source_file_mime` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'is_deleted', '`is_deleted` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_suspension_upload_cycles', 'reason_label', '`reason_label` varchar(120) DEFAULT NULL');

-- Table: `tb_suspension_upload_entries`
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'entry_id', '`entry_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'suspension_cycle_id', '`suspension_cycle_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'supplierNo', '`supplierNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'beneficiary_name', '`beneficiary_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'amount', '`amount` decimal(14,2) NOT NULL DEFAULT 0.00');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'reason', '`reason` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'matched_regNo', '`matched_regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'matched_registry_id', '`matched_registry_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'is_matched', '`is_matched` tinyint(1) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_suspension_upload_entries', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_system_logs`
CALL schema_add_column_if_missing('tb_system_logs', 'log_id', '`log_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'log_level', '`log_level` varchar(20) NOT NULL DEFAULT ''info''');
CALL schema_add_column_if_missing('tb_system_logs', 'log_category', '`log_category` varchar(80) NOT NULL DEFAULT ''general''');
CALL schema_add_column_if_missing('tb_system_logs', 'event_code', '`event_code` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'message', '`message` text NOT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'context_json', '`context_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'actor_id', '`actor_id` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'actor_name', '`actor_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'actor_role', '`actor_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'ip_address', '`ip_address` varchar(64) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_logs', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_system_log_resolutions`
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolution_id', '`resolution_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'log_id', '`log_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolution_status', '`resolution_status` enum(''acknowledged'',''resolved'',''dismissed'') NOT NULL DEFAULT ''resolved''');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolution_note', '`resolution_note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolved_by_id', '`resolved_by_id` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolved_by_name', '`resolved_by_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolved_by_role', '`resolved_by_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_system_log_resolutions', 'resolved_at', '`resolved_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_tasks`
CALL schema_add_column_if_missing('tb_tasks', 'taskId', '`taskId` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'createdBy', '`createdBy` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'sentTo', '`sentTo` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'details', '`details` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'other', '`other` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_tasks', 'created_by', '`created_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'assigned_to', '`assigned_to` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'assigned_role', '`assigned_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'task_type', '`task_type` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'task_title', '`task_title` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'task_description', '`task_description` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'status', '`status` enum(''pending'',''assigned'',''in_progress'',''delegated'',''completed'',''declined'',''cancelled'',''deferred'',''returned'') NOT NULL DEFAULT ''pending''');
CALL schema_add_column_if_missing('tb_tasks', 'priority', '`priority` enum(''low'',''normal'',''high'',''urgent'') NOT NULL DEFAULT ''normal''');
CALL schema_add_column_if_missing('tb_tasks', 'related_staff_id', '`related_staff_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'related_reg_no', '`related_reg_no` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'due_at', '`due_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'assigned_at', '`assigned_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'declined_reason', '`declined_reason` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'metadata', '`metadata` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'updated_at', '`updated_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'completed_at', '`completed_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_tasks', 'parent_task_id', '`parent_task_id` int(11) DEFAULT NULL');
SET @tb_tasks_status_column_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tb_tasks'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);
SET @tb_tasks_status_migration_sql := IF(
  @tb_tasks_status_column_type IS NOT NULL
    AND LOCATE('''delegated''', @tb_tasks_status_column_type) = 0,
  'ALTER TABLE `tb_tasks` MODIFY `status` enum(''pending'',''assigned'',''in_progress'',''delegated'',''completed'',''declined'',''cancelled'',''deferred'',''returned'') NOT NULL DEFAULT ''pending''',
  'SELECT 1'
);
CALL schema_exec_ddl(@tb_tasks_status_migration_sql);

-- Table: `tb_task_alerts`
CALL schema_add_column_if_missing('tb_task_alerts', 'alert_id', '`alert_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'task_id', '`task_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'alert_type', '`alert_type` enum(''due_soon'',''overdue'',''stalled'') NOT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'severity', '`severity` enum(''info'',''warning'',''critical'') NOT NULL DEFAULT ''warning''');
CALL schema_add_column_if_missing('tb_task_alerts', 'alert_status', '`alert_status` enum(''open'',''acknowledged'',''resolved'',''dismissed'') NOT NULL DEFAULT ''open''');
CALL schema_add_column_if_missing('tb_task_alerts', 'assigned_to', '`assigned_to` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'assigned_role', '`assigned_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'related_reg_no', '`related_reg_no` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'due_at', '`due_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'triggered_at', '`triggered_at` datetime NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_task_alerts', 'acknowledged_at', '`acknowledged_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'acknowledged_by', '`acknowledged_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'resolved_at', '`resolved_at` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'resolved_by', '`resolved_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'last_evaluated_at', '`last_evaluated_at` datetime NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_task_alerts', 'metadata', '`metadata` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_alerts', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_task_alerts', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_task_comments`
CALL schema_add_column_if_missing('tb_task_comments', 'comment_id', '`comment_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'task_id', '`task_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'author_id', '`author_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'author_name', '`author_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'author_role', '`author_role` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'comment', '`comment` text NOT NULL');
CALL schema_add_column_if_missing('tb_task_comments', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_task_completion_queue`
CALL schema_add_column_if_missing('tb_task_completion_queue', 'queue_id', '`queue_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'owner_user_id', '`owner_user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'owner_role', '`owner_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'task_id', '`task_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'task_type', '`task_type` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'task_title', '`task_title` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'related_reg_no', '`related_reg_no` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'required_assignment_role', '`required_assignment_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'next_assigned_to', '`next_assigned_to` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'next_assigned_role', '`next_assigned_role` varchar(60) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'next_priority', '`next_priority` varchar(20) NOT NULL DEFAULT ''normal''');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'action_note', '`action_note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'queue_status', '`queue_status` enum(''queued'',''processed'',''failed'',''removed'') NOT NULL DEFAULT ''queued''');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'processed_task_id', '`processed_task_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'last_error', '`last_error` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');
CALL schema_add_column_if_missing('tb_task_completion_queue', 'processed_at', '`processed_at` datetime DEFAULT NULL');

-- Table: `tb_task_delegation_logs`
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'log_id', '`log_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'task_id', '`task_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'from_user_id', '`from_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'from_user_name', '`from_user_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'from_user_role', '`from_user_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'to_user_id', '`to_user_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'to_user_name', '`to_user_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'to_user_role', '`to_user_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'note', '`note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'priority', '`priority` varchar(20) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_task_delegation_logs', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_workflow_logs`
CALL schema_add_column_if_missing('tb_workflow_logs', 'log_id', '`log_id` bigint(20) UNSIGNED NOT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'task_id', '`task_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'staffdue_id', '`staffdue_id` int(11) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'regNo', '`regNo` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'action', '`action` varchar(80) NOT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'from_status', '`from_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'to_status', '`to_status` varchar(40) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'actor_id', '`actor_id` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'actor_name', '`actor_name` varchar(150) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'actor_role', '`actor_role` varchar(80) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'note', '`note` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'metadata_json', '`metadata_json` longtext DEFAULT NULL');
CALL schema_add_column_if_missing('tb_workflow_logs', 'created_at', '`created_at` datetime NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_terms_clauses`
CALL schema_add_column_if_missing('tb_terms_clauses', 'clause_id', '`clause_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_terms_clauses', 'title', '`title` varchar(255) NOT NULL');
CALL schema_add_column_if_missing('tb_terms_clauses', 'body', '`body` text NOT NULL');
CALL schema_add_column_if_missing('tb_terms_clauses', 'topics', '`topics` varchar(120) NOT NULL DEFAULT ''operations''');
CALL schema_add_column_if_missing('tb_terms_clauses', 'section_key', '`section_key` varchar(50) NOT NULL DEFAULT ''operational''');
CALL schema_add_column_if_missing('tb_terms_clauses', 'sort_order', '`sort_order` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_terms_clauses', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_terms_clauses', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_terms_clauses', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_titles`
CALL schema_add_column_if_missing('tb_titles', 'title_id', '`title_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_titles', 'title_name', '`title_name` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_titles', 'category', '`category` enum(''uniformed'',''non_uniformed'') NOT NULL DEFAULT ''uniformed''');
CALL schema_add_column_if_missing('tb_titles', 'level', '`level` enum(''junior'',''senior'') NOT NULL DEFAULT ''junior''');
CALL schema_add_column_if_missing('tb_titles', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_titles', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_banks`
CALL schema_add_column_if_missing('tb_banks', 'bank_id', '`bank_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_banks', 'bank_name', '`bank_name` varchar(180) NOT NULL');
CALL schema_add_column_if_missing('tb_banks', 'short_name', '`short_name` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_banks', 'bank_code', '`bank_code` varchar(30) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_banks', 'display_order', '`display_order` int(11) NOT NULL DEFAULT 0');
CALL schema_add_column_if_missing('tb_banks', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_banks', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_banks', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_uganda_public_holidays`
CALL schema_add_column_if_missing('tb_uganda_public_holidays', 'holiday_date', '`holiday_date` date NOT NULL');
CALL schema_add_column_if_missing('tb_uganda_public_holidays', 'holiday_name', '`holiday_name` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_uganda_public_holidays', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_uganda_public_holidays', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_users`
CALL schema_add_column_if_missing('tb_users', 'Id', '`Id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_users', 'userId', '`userId` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_users', 'userTitle', '`userTitle` varchar(120) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'userName', '`userName` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'userRole', '`userRole` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'userEmail', '`userEmail` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'phoneNo', '`phoneNo` varchar(20) NOT NULL');
CALL schema_add_column_if_missing('tb_users', 'userPassword', '`userPassword` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'userPhoto', '`userPhoto` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'timeStamp', '`timeStamp` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_users', 'other', '`other` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'password_updated_at', '`password_updated_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_users', 'is_active', '`is_active` tinyint(1) NOT NULL DEFAULT 1');

-- Table: `tb_user_broadcast_status`
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'status_id', '`status_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'broadcast_id', '`broadcast_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'is_seen', '`is_seen` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'seen_at', '`seen_at` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'is_deleted', '`is_deleted` tinyint(1) DEFAULT 0');
CALL schema_add_column_if_missing('tb_user_broadcast_status', 'deleted_at', '`deleted_at` timestamp NULL DEFAULT NULL');

-- Table: `tb_user_logs`
CALL schema_add_column_if_missing('tb_user_logs', 'log_id', '`log_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'user_name', '`user_name` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'user_role', '`user_role` varchar(50) NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'activity_type', '`activity_type` enum(''login'',''logout'',''session_expiry'',''device_conflict'',''auto_logout'',''login_failed'',''device_conflict_detected'',''device_conflict_resolved'',''multiple_sessions_terminated'',''session_cleanup'',''session_termination_failed'',''session_started'') NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'ip_address', '`ip_address` varchar(45) NOT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'user_agent', '`user_agent` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'device_type', '`device_type` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'location', '`location` varchar(255) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'session_id', '`session_id` varchar(128) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'login_time', '`login_time` datetime DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_user_logs', 'logout_time', '`logout_time` datetime DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'duration_seconds', '`duration_seconds` int(11) DEFAULT 0');
CALL schema_add_column_if_missing('tb_user_logs', 'details', '`details` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_logs', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');

-- Table: `tb_user_permissions`
CALL schema_add_column_if_missing('tb_user_permissions', 'permission_id', '`permission_id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_user_permissions', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_permissions', 'permission_key', '`permission_key` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_user_permissions', 'is_allowed', '`is_allowed` tinyint(1) NOT NULL DEFAULT 1');
CALL schema_add_column_if_missing('tb_user_permissions', 'notes', '`notes` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_permissions', 'granted_by', '`granted_by` varchar(100) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_permissions', 'created_at', '`created_at` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_user_permissions', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- Table: `tb_user_sessions`
CALL schema_add_column_if_missing('tb_user_sessions', 'id', '`id` int(11) NOT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'session_id', '`session_id` varchar(128) NOT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'device_id', '`device_id` varchar(64) NOT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'session_type', '`session_type` enum(''web'',''mobile'',''api'') DEFAULT ''web''');
CALL schema_add_column_if_missing('tb_user_sessions', 'login_time', '`login_time` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_user_sessions', 'last_activity', '`last_activity` timestamp NOT NULL DEFAULT current_timestamp()');
CALL schema_add_column_if_missing('tb_user_sessions', 'grace_period_until', '`grace_period_until` timestamp NULL DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'is_active', '`is_active` tinyint(1) DEFAULT 1');
CALL schema_add_column_if_missing('tb_user_sessions', 'termination_reason', '`termination_reason` varchar(50) DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'user_agent', '`user_agent` text DEFAULT NULL');
CALL schema_add_column_if_missing('tb_user_sessions', 'ip_address', '`ip_address` varchar(45) DEFAULT NULL');

-- Table: `tb_user_settings`
CALL schema_add_column_if_missing('tb_user_settings', 'user_id', '`user_id` varchar(100) NOT NULL');
CALL schema_add_column_if_missing('tb_user_settings', 'setting_key', '`setting_key` varchar(120) NOT NULL');
CALL schema_add_column_if_missing('tb_user_settings', 'setting_value', '`setting_value` longtext NOT NULL');
CALL schema_add_column_if_missing('tb_user_settings', 'updated_at', '`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()');

-- -------------------------------------------------------------------
-- Primary keys, unique keys, and secondary indexes
-- Guarded by information_schema.statistics so reruns do not fail.
-- -------------------------------------------------------------------
-- Table: `tb_analytics_digest_runs`
CALL schema_add_key_if_missing('tb_analytics_digest_runs', 'PRIMARY', 'ADD PRIMARY KEY (`digest_id`)');
CALL schema_add_key_if_missing('tb_analytics_digest_runs', 'idx_analytics_digest_date', 'ADD KEY `idx_analytics_digest_date` (`digest_date`)');
CALL schema_add_key_if_missing('tb_analytics_digest_runs', 'idx_analytics_digest_created', 'ADD KEY `idx_analytics_digest_created` (`created_at`)');
CALL schema_add_key_if_missing('tb_analytics_digest_runs', 'idx_analytics_digest_frequency', 'ADD KEY `idx_analytics_digest_frequency` (`digest_frequency`,`created_at`)');

-- Table: `tb_analytics_snapshots`
CALL schema_add_key_if_missing('tb_analytics_snapshots', 'PRIMARY', 'ADD PRIMARY KEY (`snapshot_id`)');
CALL schema_add_key_if_missing('tb_analytics_snapshots', 'idx_analytics_snapshot_type', 'ADD KEY `idx_analytics_snapshot_type` (`snapshot_type`)');
CALL schema_add_key_if_missing('tb_analytics_snapshots', 'idx_analytics_snapshot_created', 'ADD KEY `idx_analytics_snapshot_created` (`created_at`)');

-- Table: `tb_application_queue`
CALL schema_add_key_if_missing('tb_application_queue', 'PRIMARY', 'ADD PRIMARY KEY (`queue_id`)');
CALL schema_add_key_if_missing('tb_application_queue', 'unique_staffdue', 'ADD UNIQUE KEY `unique_staffdue` (`staffdue_id`)');

-- Table: `tb_appnstatus`
CALL schema_add_key_if_missing('tb_appnstatus', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_appnstatus', 'regNo', 'ADD UNIQUE KEY `regNo` (`regNo`)');

-- Table: `tb_appnsubmissions`
CALL schema_add_key_if_missing('tb_appnsubmissions', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_appnsubmissions', 'regNo', 'ADD KEY `regNo` (`regNo`)');

-- Table: `tb_app_settings`
CALL schema_add_key_if_missing('tb_app_settings', 'PRIMARY', 'ADD PRIMARY KEY (`setting_key`)');

-- Table: `tb_arrearstracking`
CALL schema_add_key_if_missing('tb_arrearstracking', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_arrearstracking', 'recordedBy', 'ADD KEY `recordedBy` (`recordedBy`)');

-- Table: `tb_arrears_accountability_files`
CALL schema_add_key_if_missing('tb_arrears_accountability_files', 'PRIMARY', 'ADD PRIMARY KEY (`file_id`)');
CALL schema_add_key_if_missing('tb_arrears_accountability_files', 'idx_arr_accountability_files_submission', 'ADD KEY `idx_arr_accountability_files_submission` (`submission_id`)');

-- Table: `tb_arrears_accountability_submissions`
CALL schema_add_key_if_missing('tb_arrears_accountability_submissions', 'PRIMARY', 'ADD PRIMARY KEY (`submission_id`)');
CALL schema_add_key_if_missing('tb_arrears_accountability_submissions', 'idx_arr_accountability_reg_type', 'ADD KEY `idx_arr_accountability_reg_type` (`regNo`,`claim_type`)');
CALL schema_add_key_if_missing('tb_arrears_accountability_submissions', 'idx_arr_accountability_payment', 'ADD KEY `idx_arr_accountability_payment` (`payment_id`)');

-- Table: `tb_arrears_ledger`
CALL schema_add_key_if_missing('tb_arrears_ledger', 'PRIMARY', 'ADD PRIMARY KEY (`ledger_id`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'uniq_arrears_period', 'ADD UNIQUE KEY `uniq_arrears_period` (`regNo`,`claim_type`,`period_year`,`period_month`,`source_type`,`reference_cycle_id`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_reg', 'ADD KEY `idx_arrears_reg` (`regNo`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_type', 'ADD KEY `idx_arrears_type` (`claim_type`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_period', 'ADD KEY `idx_arrears_period` (`period_year`,`period_month`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_fy_q', 'ADD KEY `idx_arrears_fy_q` (`financial_year_label`,`quarter_label`)');
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_status', 'ADD KEY `idx_arrears_status` (`status`)');

-- Table: `tb_arrears_payments`
CALL schema_add_key_if_missing('tb_arrears_payments', 'PRIMARY', 'ADD PRIMARY KEY (`payment_id`)');
CALL schema_add_key_if_missing('tb_arrears_payments', 'idx_arrears_payment_reg', 'ADD KEY `idx_arrears_payment_reg` (`regNo`)');
CALL schema_add_key_if_missing('tb_arrears_payments', 'idx_arrears_payment_type', 'ADD KEY `idx_arrears_payment_type` (`claim_type`)');
CALL schema_add_key_if_missing('tb_arrears_payments', 'idx_arrears_payment_date', 'ADD KEY `idx_arrears_payment_date` (`payment_date`)');

-- Table: `tb_arrears_payment_allocations`
CALL schema_add_key_if_missing('tb_arrears_payment_allocations', 'PRIMARY', 'ADD PRIMARY KEY (`allocation_id`)');
CALL schema_add_key_if_missing('tb_arrears_payment_allocations', 'idx_arr_payment_alloc_payment', 'ADD KEY `idx_arr_payment_alloc_payment` (`payment_id`)');
CALL schema_add_key_if_missing('tb_arrears_payment_allocations', 'idx_arr_payment_alloc_ledger', 'ADD KEY `idx_arr_payment_alloc_ledger` (`ledger_id`)');
CALL schema_add_key_if_missing('tb_arrears_payment_allocations', 'idx_arr_payment_alloc_reg_type', 'ADD KEY `idx_arr_payment_alloc_reg_type` (`regNo`,`claim_type`)');
CALL schema_add_key_if_missing('tb_arrears_payment_allocations', 'idx_arr_payment_alloc_status', 'ADD KEY `idx_arr_payment_alloc_status` (`accountability_status`)');

-- Table: `tb_audit_logs`
CALL schema_add_key_if_missing('tb_audit_logs', 'PRIMARY', 'ADD PRIMARY KEY (`audit_id`)');

-- Table: `tb_backup_logs`
CALL schema_add_key_if_missing('tb_backup_logs', 'PRIMARY', 'ADD PRIMARY KEY (`backup_id`)');
CALL schema_add_key_if_missing('tb_backup_logs', 'idx_backup_time', 'ADD KEY `idx_backup_time` (`backup_time`)');
CALL schema_add_key_if_missing('tb_backup_logs', 'idx_backup_status', 'ADD KEY `idx_backup_status` (`status`)');

-- Table: `tb_broadcast_messages`
CALL schema_add_key_if_missing('tb_broadcast_messages', 'PRIMARY', 'ADD PRIMARY KEY (`broadcast_id`)');
CALL schema_add_key_if_missing('tb_broadcast_messages', 'message_id', 'ADD KEY `message_id` (`message_id`)');

-- Table: `tb_budgetforecast`
CALL schema_add_key_if_missing('tb_budgetforecast', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_budgetforecast', 'createdBy', 'ADD KEY `createdBy` (`createdBy`)');

-- Table: `tb_claimstatus`
CALL schema_add_key_if_missing('tb_claimstatus', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');

-- Table: `tb_data_export_runs`
CALL schema_add_key_if_missing('tb_data_export_runs', 'PRIMARY', 'ADD PRIMARY KEY (`export_id`)');
CALL schema_add_key_if_missing('tb_data_export_runs', 'idx_export_created', 'ADD KEY `idx_export_created` (`created_at`)');
CALL schema_add_key_if_missing('tb_data_export_runs', 'idx_export_dataset', 'ADD KEY `idx_export_dataset` (`dataset_key`)');

-- Table: `tb_data_import_runs`
CALL schema_add_key_if_missing('tb_data_import_runs', 'PRIMARY', 'ADD PRIMARY KEY (`import_run_id`)');
CALL schema_add_key_if_missing('tb_data_import_runs', 'idx_import_dataset', 'ADD KEY `idx_import_dataset` (`dataset_key`)');
CALL schema_add_key_if_missing('tb_data_import_runs', 'idx_import_started_at', 'ADD KEY `idx_import_started_at` (`started_at`)');
CALL schema_add_key_if_missing('tb_data_import_runs', 'idx_import_created_by', 'ADD KEY `idx_import_created_by` (`created_by`)');

-- Table: `tb_faq_entries`
CALL schema_add_key_if_missing('tb_faq_entries', 'PRIMARY', 'ADD PRIMARY KEY (`faq_id`)');
CALL schema_add_key_if_missing('tb_faq_entries', 'idx_faq_category', 'ADD KEY `idx_faq_category` (`category`)');
CALL schema_add_key_if_missing('tb_faq_entries', 'idx_faq_active', 'ADD KEY `idx_faq_active` (`is_active`)');
CALL schema_add_key_if_missing('tb_faq_entries', 'idx_faq_featured', 'ADD KEY `idx_faq_featured` (`is_featured`)');

-- Table: `tb_feedback_activity`
CALL schema_add_key_if_missing('tb_feedback_activity', 'PRIMARY', 'ADD PRIMARY KEY (`activity_id`)');
CALL schema_add_key_if_missing('tb_feedback_activity', 'idx_feedback_activity_submission', 'ADD KEY `idx_feedback_activity_submission` (`submission_id`)');
CALL schema_add_key_if_missing('tb_feedback_activity', 'idx_feedback_activity_action', 'ADD KEY `idx_feedback_activity_action` (`action`)');
CALL schema_add_key_if_missing('tb_feedback_activity', 'idx_feedback_activity_created', 'ADD KEY `idx_feedback_activity_created` (`created_at`)');

-- Table: `tb_feedback_submissions`
CALL schema_add_key_if_missing('tb_feedback_submissions', 'PRIMARY', 'ADD PRIMARY KEY (`submission_id`)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'uniq_feedback_reference', 'ADD UNIQUE KEY `uniq_feedback_reference` (`reference_no`)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_type', 'ADD KEY `idx_feedback_type` (`feedback_type`)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_audience', 'ADD KEY `idx_feedback_audience` (`audience`)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_status', 'ADD KEY `idx_feedback_status` (`status`)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_submitted', 'ADD KEY `idx_feedback_submitted` (`submitted_at`)');

-- Table: `tb_fileregistry`
CALL schema_add_key_if_missing('tb_fileregistry', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'computerNo', 'ADD UNIQUE KEY `computerNo` (`computerNo`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'regNo', 'ADD UNIQUE KEY `regNo` (`regNo`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_recent', 'ADD KEY `idx_fileregistry_recent` (`timeStamp`,`id`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_availability_recent', 'ADD KEY `idx_fileregistry_availability_recent` (`availability_status`,`timeStamp`,`id`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_name_sort', 'ADD KEY `idx_fileregistry_name_sort` (`sName`,`fName`,`id`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_is_deleted', 'ADD KEY `idx_fileregistry_is_deleted` (`is_deleted`)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_auto_arrears', 'ADD KEY `idx_fileregistry_auto_arrears` (`workflow_auto_arrears_enabled`,`is_deleted`,`regNo`)');

-- Table: `tb_file_movements`
CALL schema_add_key_if_missing('tb_file_movements', 'PRIMARY', 'ADD PRIMARY KEY (`movement_id`)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_regno', 'ADD KEY `idx_file_movements_regno` (`regNo`)');

-- Table: `tb_file_registry_delete_requests`
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'PRIMARY', 'ADD PRIMARY KEY (`request_id`)');
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'idx_registry_delete_registry', 'ADD KEY `idx_registry_delete_registry` (`registry_id`)');
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'idx_registry_delete_status', 'ADD KEY `idx_registry_delete_status` (`status`)');
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'idx_registry_delete_requested_by', 'ADD KEY `idx_registry_delete_requested_by` (`requested_by`)');

-- Table: `tb_file_registry_recycle_bin`
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'PRIMARY', 'ADD PRIMARY KEY (`recycle_id`)');
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_regno', 'ADD KEY `idx_registry_recycle_regno` (`regNo`)');
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_restored', 'ADD KEY `idx_registry_recycle_restored` (`restored`)');
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_deleted_at', 'ADD KEY `idx_registry_recycle_deleted_at` (`deleted_at`)');
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_request', 'ADD KEY `idx_registry_recycle_request` (`delete_request_id`)');

-- Table: `tb_file_scan_logs`
CALL schema_add_key_if_missing('tb_file_scan_logs', 'PRIMARY', 'ADD PRIMARY KEY (`scan_id`)');
CALL schema_add_key_if_missing('tb_file_scan_logs', 'idx_file_scan_context', 'ADD KEY `idx_file_scan_context` (`storage_context`,`scanned_at`)');
CALL schema_add_key_if_missing('tb_file_scan_logs', 'idx_file_scan_status', 'ADD KEY `idx_file_scan_status` (`scan_status`,`scanned_at`)');

-- Table: `tb_gratuity_schedule_allocations`
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'PRIMARY', 'ADD PRIMARY KEY (`allocation_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_cycle', 'ADD KEY `idx_gratuity_alloc_cycle` (`cycle_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_entry', 'ADD KEY `idx_gratuity_alloc_entry` (`entry_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_reg', 'ADD KEY `idx_gratuity_alloc_reg` (`matched_regNo`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_period', 'ADD KEY `idx_gratuity_alloc_period` (`period_year`,`period_month`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_cycle_period', 'ADD KEY `idx_gratuity_alloc_cycle_period` (`cycle_id`,`period_year`,`period_month`,`allocation_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_allocations', 'idx_gratuity_alloc_ledger', 'ADD KEY `idx_gratuity_alloc_ledger` (`ledger_id`)');

-- Table: `tb_gratuity_schedule_cycles`
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'PRIMARY', 'ADD PRIMARY KEY (`cycle_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'idx_gratuity_schedule_period', 'ADD KEY `idx_gratuity_schedule_period` (`schedule_year`,`schedule_month`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'idx_gratuity_schedule_fy_q', 'ADD KEY `idx_gratuity_schedule_fy_q` (`financial_year_label`,`quarter_label`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'idx_gratuity_schedule_created', 'ADD KEY `idx_gratuity_schedule_created` (`created_at`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'idx_gratuity_schedule_active_fy', 'ADD KEY `idx_gratuity_schedule_active_fy` (`is_deleted`,`financial_year_label`,`schedule_year`,`schedule_month`,`created_at`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_cycles', 'idx_gratuity_schedule_uploaded_by', 'ADD KEY `idx_gratuity_schedule_uploaded_by` (`uploaded_by`)');

-- Table: `tb_gratuity_schedule_entries`
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'PRIMARY', 'ADD PRIMARY KEY (`entry_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'idx_gratuity_entry_cycle', 'ADD KEY `idx_gratuity_entry_cycle` (`cycle_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'idx_gratuity_entry_reg', 'ADD KEY `idx_gratuity_entry_reg` (`matched_regNo`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'idx_gratuity_entry_classification', 'ADD KEY `idx_gratuity_entry_classification` (`classification`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'idx_gratuity_entry_cycle_row', 'ADD KEY `idx_gratuity_entry_cycle_row` (`cycle_id`,`row_number`,`entry_id`)');
CALL schema_add_key_if_missing('tb_gratuity_schedule_entries', 'idx_gratuity_entry_registry', 'ADD KEY `idx_gratuity_entry_registry` (`matched_registry_id`)');

-- Table: `tb_ip_geolocation`
CALL schema_add_key_if_missing('tb_ip_geolocation', 'PRIMARY', 'ADD PRIMARY KEY (`ip_address`)');
CALL schema_add_key_if_missing('tb_ip_geolocation', 'idx_last_lookup', 'ADD KEY `idx_last_lookup` (`last_lookup`)');

-- Table: `tb_lifecertificates`
CALL schema_add_key_if_missing('tb_lifecertificates', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');

-- Table: `tb_life_certificate_submissions`
CALL schema_add_key_if_missing('tb_life_certificate_submissions', 'PRIMARY', 'ADD PRIMARY KEY (`submission_id`)');
CALL schema_add_key_if_missing('tb_life_certificate_submissions', 'uniq_life_cert_reg_year', 'ADD UNIQUE KEY `uniq_life_cert_reg_year` (`regNo`,`submission_year`)');
CALL schema_add_key_if_missing('tb_life_certificate_submissions', 'idx_life_cert_year', 'ADD KEY `idx_life_cert_year` (`submission_year`)');
CALL schema_add_key_if_missing('tb_life_certificate_submissions', 'idx_life_cert_regno', 'ADD KEY `idx_life_cert_regno` (`regNo`)');

-- Table: `tb_messages`
CALL schema_add_key_if_missing('tb_messages', 'PRIMARY', 'ADD PRIMARY KEY (`message_id`)');
CALL schema_add_key_if_missing('tb_messages', 'sender_id', 'ADD KEY `sender_id` (`sender_id`)');
CALL schema_add_key_if_missing('tb_messages', 'parent_message_id', 'ADD KEY `parent_message_id` (`parent_message_id`)');

-- Table: `tb_message_attachments`
CALL schema_add_key_if_missing('tb_message_attachments', 'PRIMARY', 'ADD PRIMARY KEY (`attachment_id`)');
CALL schema_add_key_if_missing('tb_message_attachments', 'message_id', 'ADD KEY `message_id` (`message_id`)');
CALL schema_add_key_if_missing('tb_message_attachments', 'idx_message_attachments_hash', 'ADD KEY `idx_message_attachments_hash` (`file_hash`)');

-- Table: `tb_message_recipients`
CALL schema_add_key_if_missing('tb_message_recipients', 'PRIMARY', 'ADD PRIMARY KEY (`recipient_id`)');
CALL schema_add_key_if_missing('tb_message_recipients', 'unique_message_recipient', 'ADD UNIQUE KEY `unique_message_recipient` (`message_id`,`recipient_user_id`)');
CALL schema_add_key_if_missing('tb_message_recipients', 'recipient_user_id', 'ADD KEY `recipient_user_id` (`recipient_user_id`)');

-- Table: `tb_message_storage_snapshots`
CALL schema_add_key_if_missing('tb_message_storage_snapshots', 'PRIMARY', 'ADD PRIMARY KEY (`snapshot_id`)');
CALL schema_add_key_if_missing('tb_message_storage_snapshots', 'idx_message_snapshot_date', 'ADD KEY `idx_message_snapshot_date` (`snapshot_date`)');
CALL schema_add_key_if_missing('tb_message_storage_snapshots', 'idx_message_snapshot_created', 'ADD KEY `idx_message_snapshot_created` (`created_at`)');

-- Table: `tb_notification_digest_runs`
CALL schema_add_key_if_missing('tb_notification_digest_runs', 'PRIMARY', 'ADD PRIMARY KEY (`digest_id`)');
CALL schema_add_key_if_missing('tb_notification_digest_runs', 'idx_notification_digest_date', 'ADD KEY `idx_notification_digest_date` (`digest_date`)');
CALL schema_add_key_if_missing('tb_notification_digest_runs', 'idx_notification_digest_created', 'ADD KEY `idx_notification_digest_created` (`created_at`)');

-- Table: `tb_notification_queue`
CALL schema_add_key_if_missing('tb_notification_queue', 'PRIMARY', 'ADD PRIMARY KEY (`notification_id`)');

-- Table: `tb_payrolls`
CALL schema_add_key_if_missing('tb_payrolls', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_payrolls', 'uploaded_by', 'ADD KEY `uploaded_by` (`uploaded_by`)');

-- Table: `tb_pensioner_death_reports`
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'PRIMARY', 'ADD PRIMARY KEY (`report_id`)');
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'idx_pensioner_death_registry', 'ADD KEY `idx_pensioner_death_registry` (`registry_id`)');
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'idx_pensioner_death_regno', 'ADD KEY `idx_pensioner_death_regno` (`regNo`)');
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'idx_pensioner_death_date', 'ADD KEY `idx_pensioner_death_date` (`date_of_death`)');
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'idx_pensioner_death_notification_date', 'ADD KEY `idx_pensioner_death_notification_date` (`notification_date`)');
CALL schema_add_key_if_missing('tb_pensioner_death_reports', 'idx_pensioner_death_recorded_by', 'ADD KEY `idx_pensioner_death_recorded_by` (`recorded_by`)');

-- Table: `tb_payroll_arrears`
CALL schema_add_key_if_missing('tb_payroll_arrears', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_payroll_arrears', 'supplierNo', 'ADD UNIQUE KEY `supplierNo` (`supplierNo`)');

-- Table: `tb_payroll_audit_logs`
CALL schema_add_key_if_missing('tb_payroll_audit_logs', 'PRIMARY', 'ADD PRIMARY KEY (`audit_id`)');
CALL schema_add_key_if_missing('tb_payroll_audit_logs', 'idx_payroll_audit_cycle', 'ADD KEY `idx_payroll_audit_cycle` (`cycle_id`)');
CALL schema_add_key_if_missing('tb_payroll_audit_logs', 'idx_payroll_audit_actor', 'ADD KEY `idx_payroll_audit_actor` (`actor_user_id`)');
CALL schema_add_key_if_missing('tb_payroll_audit_logs', 'idx_payroll_audit_action', 'ADD KEY `idx_payroll_audit_action` (`action`)');
CALL schema_add_key_if_missing('tb_payroll_audit_logs', 'idx_payroll_audit_created', 'ADD KEY `idx_payroll_audit_created` (`created_at`)');

-- Table: `tb_payroll_gratuity`
CALL schema_add_key_if_missing('tb_payroll_gratuity', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_payroll_gratuity', 'supplierNo', 'ADD UNIQUE KEY `supplierNo` (`supplierNo`)');

-- Table: `tb_payroll_pension`
CALL schema_add_key_if_missing('tb_payroll_pension', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_payroll_pension', 'supplierNo', 'ADD UNIQUE KEY `supplierNo` (`supplierNo`)');

-- Table: `tb_payroll_suspended`
CALL schema_add_key_if_missing('tb_payroll_suspended', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_payroll_suspended', 'supplierNo', 'ADD UNIQUE KEY `supplierNo` (`supplierNo`)');

-- Table: `tb_payroll_upload_cycles`
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'PRIMARY', 'ADD PRIMARY KEY (`cycle_id`)');
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'idx_payroll_cycle_period', 'ADD KEY `idx_payroll_cycle_period` (`payroll_year`,`payroll_month`)');
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'idx_payroll_cycle_fy_q', 'ADD KEY `idx_payroll_cycle_fy_q` (`financial_year_label`,`quarter_label`)');
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'idx_payroll_cycle_active', 'ADD KEY `idx_payroll_cycle_active` (`is_deleted`)');

-- Table: `tb_payroll_upload_entries`
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'PRIMARY', 'ADD PRIMARY KEY (`entry_id`)');
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'idx_payroll_entries_cycle', 'ADD KEY `idx_payroll_entries_cycle` (`cycle_id`)');
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'idx_payroll_entries_supplier', 'ADD KEY `idx_payroll_entries_supplier` (`supplierNo`)');
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'idx_payroll_entries_match', 'ADD KEY `idx_payroll_entries_match` (`matched_regNo`)');

-- Table: `tb_podcast_videos`
CALL schema_add_key_if_missing('tb_podcast_videos', 'PRIMARY', 'ADD PRIMARY KEY (`podcast_id`)');
CALL schema_add_key_if_missing('tb_podcast_videos', 'idx_podcast_audience', 'ADD KEY `idx_podcast_audience` (`audience`)');
CALL schema_add_key_if_missing('tb_podcast_videos', 'idx_podcast_published', 'ADD KEY `idx_podcast_published` (`is_published`)');
CALL schema_add_key_if_missing('tb_podcast_videos', 'idx_podcast_featured', 'ADD KEY `idx_podcast_featured` (`is_featured`)');
CALL schema_add_key_if_missing('tb_podcast_videos', 'idx_podcast_created_at', 'ADD KEY `idx_podcast_created_at` (`created_at`)');

-- Table: `tb_podcast_views`
CALL schema_add_key_if_missing('tb_podcast_views', 'PRIMARY', 'ADD PRIMARY KEY (`view_id`)');
CALL schema_add_key_if_missing('tb_podcast_views', 'idx_podcast_view_podcast', 'ADD KEY `idx_podcast_view_podcast` (`podcast_id`)');
CALL schema_add_key_if_missing('tb_podcast_views', 'idx_podcast_view_viewer', 'ADD KEY `idx_podcast_view_viewer` (`viewer_id`)');
CALL schema_add_key_if_missing('tb_podcast_views', 'idx_podcast_viewed_at', 'ADD KEY `idx_podcast_viewed_at` (`viewed_at`)');

-- Table: `tb_poldistricts`
CALL schema_add_key_if_missing('tb_poldistricts', 'PRIMARY', 'ADD PRIMARY KEY (`Id`)');
CALL schema_add_key_if_missing('tb_poldistricts', 'polDistrict', 'ADD KEY `polDistrict` (`polDistrict`(768))');
CALL schema_add_key_if_missing('tb_poldistricts', 'polRegion', 'ADD KEY `polRegion` (`polRegion`(768))');

-- Table: `tb_pridistricts`
CALL schema_add_key_if_missing('tb_pridistricts', 'PRIMARY', 'ADD PRIMARY KEY (`Id`)');
CALL schema_add_key_if_missing('tb_pridistricts', 'priDistrict', 'ADD KEY `priDistrict` (`priDistrict`(768))');
CALL schema_add_key_if_missing('tb_pridistricts', 'priRegion', 'ADD KEY `priRegion` (`priRegion`(768))');

-- Table: `tb_priregions`
CALL schema_add_key_if_missing('tb_priregions', 'PRIMARY', 'ADD PRIMARY KEY (`Id`)');

-- Table: `tb_priunits`
CALL schema_add_key_if_missing('tb_priunits', 'PRIMARY', 'ADD PRIMARY KEY (`Id`)');
CALL schema_add_key_if_missing('tb_priunits', 'polDistrict', 'ADD KEY `polDistrict` (`polDistrict`(1024))');
CALL schema_add_key_if_missing('tb_priunits', 'priUnit', 'ADD KEY `priUnit` (`priUnit`(1024))');
CALL schema_add_key_if_missing('tb_priunits', 'priDistrict', 'ADD KEY `priDistrict` (`polDistrict`(1024)) USING BTREE');
CALL schema_add_key_if_missing('tb_priunits', 'priDistrict_2', 'ADD KEY `priDistrict_2` (`priDistrict`(1024))');
CALL schema_add_key_if_missing('tb_priunits', 'priRegion', 'ADD KEY `priRegion` (`priRegion`(1024))');
CALL schema_add_key_if_missing('tb_priunits', 'polRegion', 'ADD KEY `polRegion` (`polRegion`(1024))');

-- Table: `tb_registry_payroll_monthly_status`
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'PRIMARY', 'ADD PRIMARY KEY (`status_id`)');
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'uniq_registry_payroll_period', 'ADD UNIQUE KEY `uniq_registry_payroll_period` (`regNo`,`payroll_year`,`payroll_month`)');
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'idx_registry_payroll_period', 'ADD KEY `idx_registry_payroll_period` (`payroll_year`,`payroll_month`)');
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'idx_registry_payroll_fy_q', 'ADD KEY `idx_registry_payroll_fy_q` (`financial_year_label`,`quarter_label`)');
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'idx_registry_payroll_status', 'ADD KEY `idx_registry_payroll_status` (`payroll_status`)');

-- Table: `tb_retained_payments`
CALL schema_add_key_if_missing('tb_retained_payments', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_retained_payments', 'recorded_by', 'ADD KEY `recorded_by` (`recorded_by`)');

-- Table: `tb_roles`
CALL schema_add_key_if_missing('tb_roles', 'PRIMARY', 'ADD PRIMARY KEY (`role_key`)');
CALL schema_add_key_if_missing('tb_roles', 'idx_role_active', 'ADD KEY `idx_role_active` (`is_active`)');
CALL schema_add_key_if_missing('tb_roles', 'idx_role_clone', 'ADD KEY `idx_role_clone` (`clone_from_role`)');

-- Table: `tb_role_permissions`
CALL schema_add_key_if_missing('tb_role_permissions', 'PRIMARY', 'ADD PRIMARY KEY (`role_permission_id`)');
CALL schema_add_key_if_missing('tb_role_permissions', 'uniq_role_permission', 'ADD UNIQUE KEY `uniq_role_permission` (`role_key`,`permission_key`)');
CALL schema_add_key_if_missing('tb_role_permissions', 'idx_role_perm_role', 'ADD KEY `idx_role_perm_role` (`role_key`)');
CALL schema_add_key_if_missing('tb_role_permissions', 'idx_role_perm_permission', 'ADD KEY `idx_role_perm_permission` (`permission_key`)');

-- Table: `tb_session_metrics`
CALL schema_add_key_if_missing('tb_session_metrics', 'PRIMARY', 'ADD PRIMARY KEY (`metric_id`)');
CALL schema_add_key_if_missing('tb_session_metrics', 'idx_metric_time', 'ADD KEY `idx_metric_time` (`metric_time`)');

-- Table: `tb_session_settings`
CALL schema_add_key_if_missing('tb_session_settings', 'PRIMARY', 'ADD PRIMARY KEY (`user_id`)');

-- Table: `tb_staffdue`
CALL schema_add_key_if_missing('tb_staffdue', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_staffdue', 'regNo', 'ADD UNIQUE KEY `regNo` (`regNo`)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_is_deleted', 'ADD KEY `idx_staffdue_is_deleted` (`is_deleted`)');

-- Table: `tb_staff_documents`
CALL schema_add_key_if_missing('tb_staff_documents', 'PRIMARY', 'ADD PRIMARY KEY (`document_id`)');
CALL schema_add_key_if_missing('tb_staff_documents', 'idx_staff_documents_staff', 'ADD KEY `idx_staff_documents_staff` (`staffdue_id`)');
CALL schema_add_key_if_missing('tb_staff_documents', 'idx_staff_documents_regno', 'ADD KEY `idx_staff_documents_regno` (`regNo`)');
CALL schema_add_key_if_missing('tb_staff_documents', 'idx_staff_documents_hash', 'ADD KEY `idx_staff_documents_hash` (`file_hash`)');

-- Table: `tb_staff_due_delete_requests`
CALL schema_add_key_if_missing('tb_staff_due_delete_requests', 'PRIMARY', 'ADD PRIMARY KEY (`request_id`)');
CALL schema_add_key_if_missing('tb_staff_due_delete_requests', 'idx_staffdue_delete_staff', 'ADD KEY `idx_staffdue_delete_staff` (`staffdue_id`)');
CALL schema_add_key_if_missing('tb_staff_due_delete_requests', 'idx_staffdue_delete_status', 'ADD KEY `idx_staffdue_delete_status` (`status`)');
CALL schema_add_key_if_missing('tb_staff_due_delete_requests', 'idx_staffdue_delete_requested_by', 'ADD KEY `idx_staffdue_delete_requested_by` (`requested_by`)');

-- Table: `tb_suspension_upload_cycles`
CALL schema_add_key_if_missing('tb_suspension_upload_cycles', 'PRIMARY', 'ADD PRIMARY KEY (`suspension_cycle_id`)');
CALL schema_add_key_if_missing('tb_suspension_upload_cycles', 'idx_susp_cycle_period', 'ADD KEY `idx_susp_cycle_period` (`suspension_year`,`suspension_month`)');
CALL schema_add_key_if_missing('tb_suspension_upload_cycles', 'idx_susp_cycle_fy_q', 'ADD KEY `idx_susp_cycle_fy_q` (`financial_year_label`,`quarter_label`)');

-- Table: `tb_suspension_upload_entries`
CALL schema_add_key_if_missing('tb_suspension_upload_entries', 'PRIMARY', 'ADD PRIMARY KEY (`entry_id`)');
CALL schema_add_key_if_missing('tb_suspension_upload_entries', 'idx_susp_entries_cycle', 'ADD KEY `idx_susp_entries_cycle` (`suspension_cycle_id`)');
CALL schema_add_key_if_missing('tb_suspension_upload_entries', 'idx_susp_entries_supplier', 'ADD KEY `idx_susp_entries_supplier` (`supplierNo`)');
CALL schema_add_key_if_missing('tb_suspension_upload_entries', 'idx_susp_entries_match', 'ADD KEY `idx_susp_entries_match` (`matched_regNo`)');

-- Table: `tb_system_logs`
CALL schema_add_key_if_missing('tb_system_logs', 'PRIMARY', 'ADD PRIMARY KEY (`log_id`)');
CALL schema_add_key_if_missing('tb_system_logs', 'idx_system_logs_level_created', 'ADD KEY `idx_system_logs_level_created` (`log_level`,`created_at`)');
CALL schema_add_key_if_missing('tb_system_logs', 'idx_system_logs_category_created', 'ADD KEY `idx_system_logs_category_created` (`log_category`,`created_at`)');
CALL schema_add_key_if_missing('tb_system_logs', 'idx_system_logs_actor_created', 'ADD KEY `idx_system_logs_actor_created` (`actor_id`,`created_at`)');

-- Table: `tb_system_log_resolutions`
CALL schema_add_key_if_missing('tb_system_log_resolutions', 'PRIMARY', 'ADD PRIMARY KEY (`resolution_id`)');
CALL schema_add_key_if_missing('tb_system_log_resolutions', 'uniq_system_log_resolution', 'ADD UNIQUE KEY `uniq_system_log_resolution` (`log_id`)');
CALL schema_add_key_if_missing('tb_system_log_resolutions', 'idx_system_log_resolution_status', 'ADD KEY `idx_system_log_resolution_status` (`resolution_status`,`resolved_at`)');
CALL schema_add_key_if_missing('tb_system_log_resolutions', 'idx_system_log_resolution_actor', 'ADD KEY `idx_system_log_resolution_actor` (`resolved_by_id`,`resolved_at`)');

-- Table: `tb_tasks`
CALL schema_add_key_if_missing('tb_tasks', 'PRIMARY', 'ADD PRIMARY KEY (`taskId`)');
CALL schema_add_key_if_missing('tb_tasks', 'createdBy', 'ADD KEY `createdBy` (`createdBy`)');
CALL schema_add_key_if_missing('tb_tasks', 'sentTo', 'ADD KEY `sentTo` (`sentTo`)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_parent_task_id', 'ADD KEY `idx_parent_task_id` (`parent_task_id`)');

-- Table: `tb_task_alerts`
CALL schema_add_key_if_missing('tb_task_alerts', 'PRIMARY', 'ADD PRIMARY KEY (`alert_id`)');
CALL schema_add_key_if_missing('tb_task_alerts', 'uniq_task_alert_type', 'ADD UNIQUE KEY `uniq_task_alert_type` (`task_id`,`alert_type`)');
CALL schema_add_key_if_missing('tb_task_alerts', 'idx_alert_status', 'ADD KEY `idx_alert_status` (`alert_status`)');
CALL schema_add_key_if_missing('tb_task_alerts', 'idx_alert_assignee_status', 'ADD KEY `idx_alert_assignee_status` (`assigned_to`,`alert_status`)');
CALL schema_add_key_if_missing('tb_task_alerts', 'idx_alert_role_status', 'ADD KEY `idx_alert_role_status` (`assigned_role`,`alert_status`)');
CALL schema_add_key_if_missing('tb_task_alerts', 'idx_alert_triggered', 'ADD KEY `idx_alert_triggered` (`triggered_at`)');

-- Table: `tb_task_comments`
CALL schema_add_key_if_missing('tb_task_comments', 'PRIMARY', 'ADD PRIMARY KEY (`comment_id`)');

-- Table: `tb_task_completion_queue`
CALL schema_add_key_if_missing('tb_task_completion_queue', 'PRIMARY', 'ADD PRIMARY KEY (`queue_id`)');
CALL schema_add_key_if_missing('tb_task_completion_queue', 'idx_task_completion_queue_owner_status', 'ADD KEY `idx_task_completion_queue_owner_status` (`owner_user_id`,`queue_status`)');
CALL schema_add_key_if_missing('tb_task_completion_queue', 'idx_task_completion_queue_task_owner', 'ADD KEY `idx_task_completion_queue_task_owner` (`task_id`,`owner_user_id`)');

-- Table: `tb_task_delegation_logs`
CALL schema_add_key_if_missing('tb_task_delegation_logs', 'PRIMARY', 'ADD PRIMARY KEY (`log_id`)');
CALL schema_add_key_if_missing('tb_task_delegation_logs', 'idx_task_delegation_task', 'ADD KEY `idx_task_delegation_task` (`task_id`)');
CALL schema_add_key_if_missing('tb_task_delegation_logs', 'idx_task_delegation_from', 'ADD KEY `idx_task_delegation_from` (`from_user_id`)');
CALL schema_add_key_if_missing('tb_task_delegation_logs', 'idx_task_delegation_to', 'ADD KEY `idx_task_delegation_to` (`to_user_id`)');
CALL schema_add_key_if_missing('tb_task_delegation_logs', 'idx_task_delegation_created', 'ADD KEY `idx_task_delegation_created` (`created_at`)');

-- Table: `tb_workflow_logs`
CALL schema_add_key_if_missing('tb_workflow_logs', 'PRIMARY', 'ADD PRIMARY KEY (`log_id`)');
CALL schema_add_key_if_missing('tb_workflow_logs', 'idx_workflow_task', 'ADD KEY `idx_workflow_task` (`task_id`)');
CALL schema_add_key_if_missing('tb_workflow_logs', 'idx_workflow_staff', 'ADD KEY `idx_workflow_staff` (`staffdue_id`)');
CALL schema_add_key_if_missing('tb_workflow_logs', 'idx_workflow_regno', 'ADD KEY `idx_workflow_regno` (`regNo`)');
CALL schema_add_key_if_missing('tb_workflow_logs', 'idx_workflow_action', 'ADD KEY `idx_workflow_action` (`action`)');
CALL schema_add_key_if_missing('tb_workflow_logs', 'idx_workflow_created', 'ADD KEY `idx_workflow_created` (`created_at`)');

-- Table: `tb_terms_clauses`
CALL schema_add_key_if_missing('tb_terms_clauses', 'PRIMARY', 'ADD PRIMARY KEY (`clause_id`)');
CALL schema_add_key_if_missing('tb_terms_clauses', 'idx_terms_section', 'ADD KEY `idx_terms_section` (`section_key`)');
CALL schema_add_key_if_missing('tb_terms_clauses', 'idx_terms_active', 'ADD KEY `idx_terms_active` (`is_active`)');

-- Table: `tb_titles`
CALL schema_add_key_if_missing('tb_titles', 'PRIMARY', 'ADD PRIMARY KEY (`title_id`)');

-- Table: `tb_banks`
CALL schema_add_key_if_missing('tb_banks', 'PRIMARY', 'ADD PRIMARY KEY (`bank_id`)');
CALL schema_add_key_if_missing('tb_banks', 'uq_tb_banks_name', 'ADD UNIQUE KEY `uq_tb_banks_name` (`bank_name`)');
CALL schema_add_key_if_missing('tb_banks', 'idx_tb_banks_active_order', 'ADD KEY `idx_tb_banks_active_order` (`is_active`,`display_order`,`bank_name`)');

-- Table: `tb_uganda_public_holidays`
CALL schema_add_key_if_missing('tb_uganda_public_holidays', 'PRIMARY', 'ADD PRIMARY KEY (`holiday_date`)');

-- Table: `tb_users`
CALL schema_add_key_if_missing('tb_users', 'PRIMARY', 'ADD PRIMARY KEY (`Id`)');
CALL schema_add_key_if_missing('tb_users', 'phoneNo', 'ADD UNIQUE KEY `phoneNo` (`phoneNo`)');
CALL schema_add_key_if_missing('tb_users', 'idx_tb_users_phoneNo', 'ADD UNIQUE KEY `idx_tb_users_phoneNo` (`phoneNo`)');
CALL schema_add_key_if_missing('tb_users', 'userId', 'ADD UNIQUE KEY `userId` (`userId`)');
CALL schema_add_key_if_missing('tb_users', 'userEmail', 'ADD UNIQUE KEY `userEmail` (`userEmail`)');
CALL schema_add_key_if_missing('tb_users', 'idx_user_email', 'ADD KEY `idx_user_email` (`userEmail`)');
CALL schema_add_key_if_missing('tb_users', 'idx_user_userId', 'ADD KEY `idx_user_userId` (`userId`)');
CALL schema_add_key_if_missing('tb_users', 'idx_user_phone', 'ADD KEY `idx_user_phone` (`phoneNo`)');

-- Table: `tb_user_broadcast_status`
CALL schema_add_key_if_missing('tb_user_broadcast_status', 'PRIMARY', 'ADD PRIMARY KEY (`status_id`)');
CALL schema_add_key_if_missing('tb_user_broadcast_status', 'unique_user_broadcast', 'ADD UNIQUE KEY `unique_user_broadcast` (`user_id`,`broadcast_id`)');
CALL schema_add_key_if_missing('tb_user_broadcast_status', 'broadcast_id', 'ADD KEY `broadcast_id` (`broadcast_id`)');

-- Table: `tb_user_logs`
CALL schema_add_key_if_missing('tb_user_logs', 'PRIMARY', 'ADD PRIMARY KEY (`log_id`)');
CALL schema_add_key_if_missing('tb_user_logs', 'idx_user_id', 'ADD KEY `idx_user_id` (`user_id`)');
CALL schema_add_key_if_missing('tb_user_logs', 'idx_activity_type', 'ADD KEY `idx_activity_type` (`activity_type`)');
CALL schema_add_key_if_missing('tb_user_logs', 'idx_login_time', 'ADD KEY `idx_login_time` (`login_time`)');
CALL schema_add_key_if_missing('tb_user_logs', 'idx_user_role', 'ADD KEY `idx_user_role` (`user_role`)');
CALL schema_add_key_if_missing('tb_user_logs', 'idx_logs_userId', 'ADD KEY `idx_logs_userId` (`user_id`)');

-- Table: `tb_user_permissions`
CALL schema_add_key_if_missing('tb_user_permissions', 'PRIMARY', 'ADD PRIMARY KEY (`permission_id`)');
CALL schema_add_key_if_missing('tb_user_permissions', 'uniq_user_permission', 'ADD UNIQUE KEY `uniq_user_permission` (`user_id`,`permission_key`)');
CALL schema_add_key_if_missing('tb_user_permissions', 'idx_permission_key', 'ADD KEY `idx_permission_key` (`permission_key`)');
CALL schema_add_key_if_missing('tb_user_permissions', 'idx_permission_user', 'ADD KEY `idx_permission_user` (`user_id`)');

-- Table: `tb_user_sessions`
CALL schema_add_key_if_missing('tb_user_sessions', 'PRIMARY', 'ADD PRIMARY KEY (`id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'unique_session_id', 'ADD UNIQUE KEY `unique_session_id` (`session_id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_user_sessions_user_id', 'ADD KEY `idx_user_sessions_user_id` (`user_id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_user_sessions_device_id', 'ADD KEY `idx_user_sessions_device_id` (`device_id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_user_sessions_last_activity', 'ADD KEY `idx_user_sessions_last_activity` (`last_activity`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_user_active', 'ADD KEY `idx_user_active` (`user_id`,`is_active`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_last_activity', 'ADD KEY `idx_last_activity` (`last_activity`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_session_device', 'ADD KEY `idx_session_device` (`session_id`,`device_id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_active', 'ADD KEY `idx_active` (`is_active`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_last_activity2', 'ADD KEY `idx_last_activity2` (`last_activity`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_user_active2', 'ADD KEY `idx_user_active2` (`user_id`,`is_active`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_sessions_userId', 'ADD KEY `idx_sessions_userId` (`user_id`)');
CALL schema_add_key_if_missing('tb_user_sessions', 'idx_sessions_sessionId', 'ADD KEY `idx_sessions_sessionId` (`session_id`)');

-- Table: `tb_user_settings`
CALL schema_add_key_if_missing('tb_user_settings', 'PRIMARY', 'ADD PRIMARY KEY (`user_id`,`setting_key`)');
CALL schema_add_key_if_missing('tb_user_settings', 'idx_user_settings_key', 'ADD KEY `idx_user_settings_key` (`setting_key`)');

-- Table: `tb_application_queue`
CALL schema_add_key_if_missing('tb_application_queue', 'idx_application_queue_status', 'ADD KEY idx_application_queue_status (status)');
CALL schema_add_key_if_missing('tb_application_queue', 'idx_application_queue_stage', 'ADD KEY idx_application_queue_stage (current_stage)');
CALL schema_add_key_if_missing('tb_application_queue', 'idx_application_queue_updated', 'ADD KEY idx_application_queue_updated (updated_at)');
CALL schema_add_key_if_missing('tb_application_queue', 'idx_application_queue_regno', 'ADD KEY idx_application_queue_regno (regNo)');

-- Table: `tb_appnsubmissions`
CALL schema_add_key_if_missing('tb_appnsubmissions', 'idx_appnsubmissions_submission_date', 'ADD KEY idx_appnsubmissions_submission_date (submissionDate)');
CALL schema_add_key_if_missing('tb_appnsubmissions', 'idx_appnsubmissions_retirement_type', 'ADD KEY idx_appnsubmissions_retirement_type (retirementType)');

-- Table: `tb_claimstatus`
CALL schema_add_key_if_missing('tb_claimstatus', 'idx_claim_regno', 'ADD KEY idx_claim_regno (regNo)');
CALL schema_add_key_if_missing('tb_claimstatus', 'idx_claim_supplier', 'ADD KEY idx_claim_supplier (supplierNo)');
CALL schema_add_key_if_missing('tb_claimstatus', 'idx_claim_status', 'ADD KEY idx_claim_status (appnStatus)');
CALL schema_add_key_if_missing('tb_claimstatus', 'idx_claim_verification_date', 'ADD KEY idx_claim_verification_date (verificationDate)');

-- Table: `tb_staffdue`
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_computer', 'ADD KEY idx_staffdue_computer (computerNo)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_submission_status', 'ADD KEY idx_staffdue_submission_status (submissionStatus)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_appn_status', 'ADD KEY idx_staffdue_appn_status (appnStatus)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_retirement', 'ADD KEY idx_staffdue_retirement (retirementType, retirementDate)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_prison_unit', 'ADD KEY idx_staffdue_prison_unit (prisonUnit)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_submission_at', 'ADD KEY idx_staffdue_submission_at (submission_at)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_appn_status_at', 'ADD KEY idx_staffdue_appn_status_at (appn_status_at)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_living_status', 'ADD KEY idx_staffdue_living_status (livingStatus)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_pay_type', 'ADD KEY idx_staffdue_pay_type (payType)');
CALL schema_add_key_if_missing('tb_staffdue', 'idx_staffdue_contact', 'ADD KEY idx_staffdue_contact (telNo)');

-- Table: `tb_fileregistry`
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_supplier', 'ADD KEY idx_fileregistry_supplier (supplierNo)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_payroll', 'ADD KEY idx_fileregistry_payroll (payrollStatus, payType)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_life_certificate', 'ADD KEY idx_fileregistry_life_certificate (lifeCertificate)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_living_status', 'ADD KEY idx_fileregistry_living_status (livingStatus)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_retirement', 'ADD KEY idx_fileregistry_retirement (retirementType, retirementDate)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_contact', 'ADD KEY idx_fileregistry_contact (telNo)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_date_of_death', 'ADD KEY idx_fileregistry_date_of_death (dateOfDeath)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_estate_expiry', 'ADD KEY idx_fileregistry_estate_expiry (estateExpiryDate)');
CALL schema_add_key_if_missing('tb_fileregistry', 'idx_fileregistry_estate_status', 'ADD KEY idx_fileregistry_estate_status (estateStatus)');

-- Table: `tb_file_movements`
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_moved', 'ADD KEY idx_file_movements_moved (moved_at)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_expected', 'ADD KEY idx_file_movements_expected (expected_return_at)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_returned', 'ADD KEY idx_file_movements_returned (returned_at)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_from_office', 'ADD KEY idx_file_movements_from_office (from_office)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_to_office', 'ADD KEY idx_file_movements_to_office (to_office)');
CALL schema_add_key_if_missing('tb_file_movements', 'idx_file_movements_file_id', 'ADD KEY idx_file_movements_file_id (file_id)');

-- Table: `tb_staff_documents`
CALL schema_add_key_if_missing('tb_staff_documents', 'idx_staff_documents_uploaded_by', 'ADD KEY idx_staff_documents_uploaded_by (uploaded_by)');
CALL schema_add_key_if_missing('tb_staff_documents', 'idx_staff_documents_uploaded_at', 'ADD KEY idx_staff_documents_uploaded_at (uploaded_at)');

-- Table: `tb_file_registry_delete_requests`
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'idx_registry_delete_created', 'ADD KEY idx_registry_delete_created (created_at)');
CALL schema_add_key_if_missing('tb_file_registry_delete_requests', 'idx_registry_delete_processed', 'ADD KEY idx_registry_delete_processed (processed_at)');

-- Table: `tb_file_registry_recycle_bin`
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_registry', 'ADD KEY idx_registry_recycle_registry (registry_id)');
CALL schema_add_key_if_missing('tb_file_registry_recycle_bin', 'idx_registry_recycle_restored_by', 'ADD KEY idx_registry_recycle_restored_by (restored_by)');

-- Table: `tb_feedback_submissions`
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_assigned_to', 'ADD KEY idx_feedback_assigned_to (assigned_to_user_id)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_submitted_by', 'ADD KEY idx_feedback_submitted_by (submitted_by_user_id)');
CALL schema_add_key_if_missing('tb_feedback_submissions', 'idx_feedback_priority', 'ADD KEY idx_feedback_priority (priority)');

-- Table: `tb_data_export_runs`
CALL schema_add_key_if_missing('tb_data_export_runs', 'idx_export_created_by', 'ADD KEY idx_export_created_by (created_by)');

-- Table: `tb_notification_queue`
CALL schema_add_key_if_missing('tb_notification_queue', 'idx_notification_status', 'ADD KEY idx_notification_status (status, created_at)');
CALL schema_add_key_if_missing('tb_notification_queue', 'idx_notification_channel', 'ADD KEY idx_notification_channel (channel)');

-- Table: `tb_tasks`
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_status', 'ADD KEY idx_tasks_status (status)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_assigned_to', 'ADD KEY idx_tasks_assigned_to (assigned_to)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_created_by', 'ADD KEY idx_tasks_created_by (created_by)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_due_at', 'ADD KEY idx_tasks_due_at (due_at)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_related_staff', 'ADD KEY idx_tasks_related_staff (related_staff_id)');
CALL schema_add_key_if_missing('tb_tasks', 'idx_tasks_related_reg', 'ADD KEY idx_tasks_related_reg (related_reg_no)');

-- Table: `tb_task_comments`
CALL schema_add_key_if_missing('tb_task_comments', 'idx_task_comments_task', 'ADD KEY idx_task_comments_task (task_id)');

-- Table: `tb_payroll_upload_cycles`
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'idx_payroll_cycle_uploaded_by', 'ADD KEY idx_payroll_cycle_uploaded_by (uploaded_by)');
CALL schema_add_key_if_missing('tb_payroll_upload_cycles', 'idx_payroll_cycle_created_at', 'ADD KEY idx_payroll_cycle_created_at (created_at)');

-- Table: `tb_payroll_upload_entries`
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'idx_payroll_entries_matched_registry', 'ADD KEY idx_payroll_entries_matched_registry (matched_registry_id)');
CALL schema_add_key_if_missing('tb_payroll_upload_entries', 'idx_payroll_entries_is_matched', 'ADD KEY idx_payroll_entries_is_matched (is_matched)');

-- Table: `tb_registry_payroll_monthly_status`
CALL schema_add_key_if_missing('tb_registry_payroll_monthly_status', 'idx_registry_payroll_cycle', 'ADD KEY idx_registry_payroll_cycle (cycle_id)');

-- Table: `tb_arrears_ledger`
CALL schema_add_key_if_missing('tb_arrears_ledger', 'idx_arrears_recorded_by', 'ADD KEY idx_arrears_recorded_by (recorded_by)');

-- Table: `tb_arrears_payments`
CALL schema_add_key_if_missing('tb_arrears_payments', 'idx_arrears_payment_recorded_by', 'ADD KEY idx_arrears_payment_recorded_by (recorded_by)');

-- Table: `tb_arrears_accountability_submissions`
CALL schema_add_key_if_missing('tb_arrears_accountability_submissions', 'idx_arr_accountability_submitted_by', 'ADD KEY idx_arr_accountability_submitted_by (submitted_by)');

-- Table: `tb_podcast_videos`
CALL schema_add_key_if_missing('tb_podcast_videos', 'idx_podcast_created_by', 'ADD KEY idx_podcast_created_by (created_by)');

-- -------------------------------------------------------------------
-- AUTO_INCREMENT normalization
-- Reapplies current auto-increment definitions after keys are in place.
-- -------------------------------------------------------------------
-- Table: `tb_analytics_digest_runs`
ALTER TABLE `tb_analytics_digest_runs`
  MODIFY `digest_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_analytics_snapshots`
ALTER TABLE `tb_analytics_snapshots`
  MODIFY `snapshot_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_application_queue`
ALTER TABLE `tb_application_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_appnstatus`
ALTER TABLE `tb_appnstatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_appnsubmissions`
ALTER TABLE `tb_appnsubmissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrearstracking`
ALTER TABLE `tb_arrearstracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrears_accountability_files`
ALTER TABLE `tb_arrears_accountability_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrears_accountability_submissions`
ALTER TABLE `tb_arrears_accountability_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrears_ledger`
ALTER TABLE `tb_arrears_ledger`
  MODIFY `ledger_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrears_payments`
ALTER TABLE `tb_arrears_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_arrears_payment_allocations`
ALTER TABLE `tb_arrears_payment_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_audit_logs`
ALTER TABLE `tb_audit_logs`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_backup_logs`
ALTER TABLE `tb_backup_logs`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_broadcast_messages`
ALTER TABLE `tb_broadcast_messages`
  MODIFY `broadcast_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_budgetforecast`
ALTER TABLE `tb_budgetforecast`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_claimstatus`
ALTER TABLE `tb_claimstatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_data_export_runs`
ALTER TABLE `tb_data_export_runs`
  MODIFY `export_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_data_import_runs`
ALTER TABLE `tb_data_import_runs`
  MODIFY `import_run_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_faq_entries`
ALTER TABLE `tb_faq_entries`
  MODIFY `faq_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_feedback_activity`
ALTER TABLE `tb_feedback_activity`
  MODIFY `activity_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_feedback_submissions`
ALTER TABLE `tb_feedback_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_fileregistry`
ALTER TABLE `tb_fileregistry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_file_movements`
ALTER TABLE `tb_file_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_file_registry_delete_requests`
ALTER TABLE `tb_file_registry_delete_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_file_registry_recycle_bin`
ALTER TABLE `tb_file_registry_recycle_bin`
  MODIFY `recycle_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_file_scan_logs`
ALTER TABLE `tb_file_scan_logs`
  MODIFY `scan_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_gratuity_schedule_allocations`
ALTER TABLE `tb_gratuity_schedule_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_gratuity_schedule_cycles`
ALTER TABLE `tb_gratuity_schedule_cycles`
  MODIFY `cycle_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_gratuity_schedule_entries`
ALTER TABLE `tb_gratuity_schedule_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_lifecertificates`
ALTER TABLE `tb_lifecertificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_life_certificate_submissions`
ALTER TABLE `tb_life_certificate_submissions`
  MODIFY `submission_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_messages`
ALTER TABLE `tb_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_message_attachments`
ALTER TABLE `tb_message_attachments`
  MODIFY `attachment_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_message_recipients`
ALTER TABLE `tb_message_recipients`
  MODIFY `recipient_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_message_storage_snapshots`
ALTER TABLE `tb_message_storage_snapshots`
  MODIFY `snapshot_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_notification_digest_runs`
ALTER TABLE `tb_notification_digest_runs`
  MODIFY `digest_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_notification_queue`
ALTER TABLE `tb_notification_queue`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payrolls`
ALTER TABLE `tb_payrolls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_arrears`
ALTER TABLE `tb_payroll_arrears`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_audit_logs`
ALTER TABLE `tb_payroll_audit_logs`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_gratuity`
ALTER TABLE `tb_payroll_gratuity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_pension`
ALTER TABLE `tb_payroll_pension`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_suspended`
ALTER TABLE `tb_payroll_suspended`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_upload_cycles`
ALTER TABLE `tb_payroll_upload_cycles`
  MODIFY `cycle_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_payroll_upload_entries`
ALTER TABLE `tb_payroll_upload_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_pensioner_death_reports`
ALTER TABLE `tb_pensioner_death_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_podcast_videos`
ALTER TABLE `tb_podcast_videos`
  MODIFY `podcast_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_podcast_views`
ALTER TABLE `tb_podcast_views`
  MODIFY `view_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_poldistricts`
ALTER TABLE `tb_poldistricts`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT;

-- Table: `tb_pridistricts`
ALTER TABLE `tb_pridistricts`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT;

-- Table: `tb_priregions`
ALTER TABLE `tb_priregions`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT;

-- Table: `tb_priunits`
ALTER TABLE `tb_priunits`
  MODIFY `Id` int(3) NOT NULL AUTO_INCREMENT;

-- Table: `tb_registry_payroll_monthly_status`
ALTER TABLE `tb_registry_payroll_monthly_status`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_retained_payments`
ALTER TABLE `tb_retained_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_role_permissions`
ALTER TABLE `tb_role_permissions`
  MODIFY `role_permission_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_session_metrics`
ALTER TABLE `tb_session_metrics`
  MODIFY `metric_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_staffdue`
ALTER TABLE `tb_staffdue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_staff_documents`
ALTER TABLE `tb_staff_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_staff_due_delete_requests`
ALTER TABLE `tb_staff_due_delete_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_suspension_upload_cycles`
ALTER TABLE `tb_suspension_upload_cycles`
  MODIFY `suspension_cycle_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_suspension_upload_entries`
ALTER TABLE `tb_suspension_upload_entries`
  MODIFY `entry_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_system_logs`
ALTER TABLE `tb_system_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_system_log_resolutions`
ALTER TABLE `tb_system_log_resolutions`
  MODIFY `resolution_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_tasks`
ALTER TABLE `tb_tasks`
  MODIFY `taskId` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_task_alerts`
ALTER TABLE `tb_task_alerts`
  MODIFY `alert_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_task_comments`
ALTER TABLE `tb_task_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_task_completion_queue`
ALTER TABLE `tb_task_completion_queue`
  MODIFY `queue_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_task_delegation_logs`
ALTER TABLE `tb_task_delegation_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_workflow_logs`
ALTER TABLE `tb_workflow_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

-- Table: `tb_terms_clauses`
ALTER TABLE `tb_terms_clauses`
  MODIFY `clause_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_titles`
ALTER TABLE `tb_titles`
  MODIFY `title_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_banks`
ALTER TABLE `tb_banks`
  MODIFY `bank_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_users`
ALTER TABLE `tb_users`
  MODIFY `Id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_user_broadcast_status`
ALTER TABLE `tb_user_broadcast_status`
  MODIFY `status_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_user_logs`
ALTER TABLE `tb_user_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_user_permissions`
ALTER TABLE `tb_user_permissions`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT;

-- Table: `tb_user_sessions`
ALTER TABLE `tb_user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- -------------------------------------------------------------------
-- Foreign key alignment
-- Guarded by information_schema.referential_constraints so reruns do not fail.
-- -------------------------------------------------------------------
-- Table: `tb_appnsubmissions`
CALL schema_add_fk_if_missing('tb_appnsubmissions', 'tb_appnsubmissions_ibfk_1', 'ADD CONSTRAINT `tb_appnsubmissions_ibfk_1` FOREIGN KEY (`regNo`) REFERENCES `tb_staffdue` (`regNo`)');

-- Table: `tb_arrearstracking`
CALL schema_add_fk_if_missing('tb_arrearstracking', 'tb_arrearstracking_ibfk_1', 'ADD CONSTRAINT `tb_arrearstracking_ibfk_1` FOREIGN KEY (`recordedBy`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_broadcast_messages`
CALL schema_add_fk_if_missing('tb_broadcast_messages', 'tb_broadcast_messages_ibfk_1', 'ADD CONSTRAINT `tb_broadcast_messages_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`)');

-- Table: `tb_budgetforecast`
CALL schema_add_fk_if_missing('tb_budgetforecast', 'tb_budgetforecast_ibfk_1', 'ADD CONSTRAINT `tb_budgetforecast_ibfk_1` FOREIGN KEY (`createdBy`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_messages`
CALL schema_add_fk_if_missing('tb_messages', 'tb_messages_ibfk_1', 'ADD CONSTRAINT `tb_messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `tb_users` (`userId`)');
CALL schema_add_fk_if_missing('tb_messages', 'tb_messages_ibfk_2', 'ADD CONSTRAINT `tb_messages_ibfk_2` FOREIGN KEY (`parent_message_id`) REFERENCES `tb_messages` (`message_id`)');

-- Table: `tb_message_attachments`
CALL schema_add_fk_if_missing('tb_message_attachments', 'tb_message_attachments_ibfk_1', 'ADD CONSTRAINT `tb_message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`)');

-- Table: `tb_message_recipients`
CALL schema_add_fk_if_missing('tb_message_recipients', 'tb_message_recipients_ibfk_1', 'ADD CONSTRAINT `tb_message_recipients_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `tb_messages` (`message_id`)');
CALL schema_add_fk_if_missing('tb_message_recipients', 'tb_message_recipients_ibfk_2', 'ADD CONSTRAINT `tb_message_recipients_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_payrolls`
CALL schema_add_fk_if_missing('tb_payrolls', 'tb_payrolls_ibfk_1', 'ADD CONSTRAINT `tb_payrolls_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_retained_payments`
CALL schema_add_fk_if_missing('tb_retained_payments', 'tb_retained_payments_ibfk_1', 'ADD CONSTRAINT `tb_retained_payments_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_session_settings`
CALL schema_add_fk_if_missing('tb_session_settings', 'tb_session_settings_ibfk_1', 'ADD CONSTRAINT `tb_session_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE');

-- Table: `tb_tasks`
CALL schema_add_fk_if_missing('tb_tasks', 'tb_tasks_ibfk_1', 'ADD CONSTRAINT `tb_tasks_ibfk_1` FOREIGN KEY (`createdBy`) REFERENCES `tb_users` (`userId`)');
CALL schema_add_fk_if_missing('tb_tasks', 'tb_tasks_ibfk_2', 'ADD CONSTRAINT `tb_tasks_ibfk_2` FOREIGN KEY (`sentTo`) REFERENCES `tb_users` (`userId`)');

-- Table: `tb_user_broadcast_status`
CALL schema_add_fk_if_missing('tb_user_broadcast_status', 'tb_user_broadcast_status_ibfk_1', 'ADD CONSTRAINT `tb_user_broadcast_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`)');
CALL schema_add_fk_if_missing('tb_user_broadcast_status', 'tb_user_broadcast_status_ibfk_2', 'ADD CONSTRAINT `tb_user_broadcast_status_ibfk_2` FOREIGN KEY (`broadcast_id`) REFERENCES `tb_broadcast_messages` (`broadcast_id`)');

-- Table: `tb_user_sessions`
CALL schema_add_fk_if_missing('tb_user_sessions', 'fk_user_sessions_user', 'ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `tb_users` (`userId`) ON DELETE CASCADE');

-- Table: `tb_application_queue`
CALL schema_add_fk_if_missing('tb_application_queue', 'fk_application_queue_staffdue', 'ADD CONSTRAINT fk_application_queue_staffdue FOREIGN KEY (staffdue_id) REFERENCES tb_staffdue(id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_application_queue', 'fk_application_queue_verified_by', 'ADD CONSTRAINT fk_application_queue_verified_by FOREIGN KEY (verified_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_application_queue', 'fk_application_queue_submitted_by', 'ADD CONSTRAINT fk_application_queue_submitted_by FOREIGN KEY (submitted_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_appnstatus`
CALL schema_add_fk_if_missing('tb_appnstatus', 'fk_appnstatus_staffdue_regno', 'ADD CONSTRAINT fk_appnstatus_staffdue_regno FOREIGN KEY (regNo) REFERENCES tb_staffdue(regNo) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_staff_documents`
CALL schema_add_fk_if_missing('tb_staff_documents', 'fk_staff_documents_staffdue', 'ADD CONSTRAINT fk_staff_documents_staffdue FOREIGN KEY (staffdue_id) REFERENCES tb_staffdue(id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_staff_documents', 'fk_staff_documents_uploaded_by', 'ADD CONSTRAINT fk_staff_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_staff_due_delete_requests`
CALL schema_add_fk_if_missing('tb_staff_due_delete_requests', 'fk_staff_due_delete_requests_staffdue', 'ADD CONSTRAINT fk_staff_due_delete_requests_staffdue FOREIGN KEY (staffdue_id) REFERENCES tb_staffdue(id) ON UPDATE CASCADE ON DELETE RESTRICT');
CALL schema_add_fk_if_missing('tb_staff_due_delete_requests', 'fk_staff_due_delete_requests_requested_by', 'ADD CONSTRAINT fk_staff_due_delete_requests_requested_by FOREIGN KEY (requested_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE RESTRICT');
CALL schema_add_fk_if_missing('tb_staff_due_delete_requests', 'fk_staff_due_delete_requests_processed_by', 'ADD CONSTRAINT fk_staff_due_delete_requests_processed_by FOREIGN KEY (processed_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_file_movements`
CALL schema_add_fk_if_missing('tb_file_movements', 'fk_file_movements_registry_id', 'ADD CONSTRAINT fk_file_movements_registry_id FOREIGN KEY (file_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_file_registry_delete_requests`
CALL schema_add_fk_if_missing('tb_file_registry_delete_requests', 'fk_registry_delete_requests_registry', 'ADD CONSTRAINT fk_registry_delete_requests_registry FOREIGN KEY (registry_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE RESTRICT');
CALL schema_add_fk_if_missing('tb_file_registry_delete_requests', 'fk_registry_delete_requests_requested_by', 'ADD CONSTRAINT fk_registry_delete_requests_requested_by FOREIGN KEY (requested_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE RESTRICT');
CALL schema_add_fk_if_missing('tb_file_registry_delete_requests', 'fk_registry_delete_requests_processed_by', 'ADD CONSTRAINT fk_registry_delete_requests_processed_by FOREIGN KEY (processed_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_file_registry_recycle_bin`
CALL schema_add_fk_if_missing('tb_file_registry_recycle_bin', 'fk_registry_recycle_registry', 'ADD CONSTRAINT fk_registry_recycle_registry FOREIGN KEY (registry_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_file_registry_recycle_bin', 'fk_registry_recycle_request', 'ADD CONSTRAINT fk_registry_recycle_request FOREIGN KEY (delete_request_id) REFERENCES tb_file_registry_delete_requests(request_id) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_file_registry_recycle_bin', 'fk_registry_recycle_deleted_by', 'ADD CONSTRAINT fk_registry_recycle_deleted_by FOREIGN KEY (deleted_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_file_registry_recycle_bin', 'fk_registry_recycle_restored_by', 'ADD CONSTRAINT fk_registry_recycle_restored_by FOREIGN KEY (restored_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_pensioner_death_reports`
CALL schema_add_fk_if_missing('tb_pensioner_death_reports', 'fk_pensioner_death_registry', 'ADD CONSTRAINT fk_pensioner_death_registry FOREIGN KEY (registry_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_life_certificate_submissions`
CALL schema_add_fk_if_missing('tb_life_certificate_submissions', 'fk_life_cert_submissions_user', 'ADD CONSTRAINT fk_life_cert_submissions_user FOREIGN KEY (submitted_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_life_certificate_submissions', 'fk_life_cert_submissions_regno', 'ADD CONSTRAINT fk_life_cert_submissions_regno FOREIGN KEY (regNo) REFERENCES tb_fileregistry(regNo) ON UPDATE CASCADE ON DELETE RESTRICT');

-- Table: `tb_payroll_upload_cycles`
CALL schema_add_fk_if_missing('tb_payroll_upload_cycles', 'fk_payroll_cycles_uploaded_by', 'ADD CONSTRAINT fk_payroll_cycles_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_payroll_upload_cycles', 'fk_payroll_cycles_deleted_by', 'ADD CONSTRAINT fk_payroll_cycles_deleted_by FOREIGN KEY (deleted_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_gratuity_schedule_cycles`
CALL schema_add_fk_if_missing('tb_gratuity_schedule_cycles', 'fk_gratuity_schedule_cycles_uploaded_by', 'ADD CONSTRAINT fk_gratuity_schedule_cycles_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_gratuity_schedule_entries`
CALL schema_add_fk_if_missing('tb_gratuity_schedule_entries', 'fk_gratuity_schedule_entries_cycle', 'ADD CONSTRAINT fk_gratuity_schedule_entries_cycle FOREIGN KEY (cycle_id) REFERENCES tb_gratuity_schedule_cycles(cycle_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_gratuity_schedule_entries', 'fk_gratuity_schedule_entries_registry', 'ADD CONSTRAINT fk_gratuity_schedule_entries_registry FOREIGN KEY (matched_registry_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_gratuity_schedule_allocations`
CALL schema_add_fk_if_missing('tb_gratuity_schedule_allocations', 'fk_gratuity_schedule_allocations_cycle', 'ADD CONSTRAINT fk_gratuity_schedule_allocations_cycle FOREIGN KEY (cycle_id) REFERENCES tb_gratuity_schedule_cycles(cycle_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_gratuity_schedule_allocations', 'fk_gratuity_schedule_allocations_entry', 'ADD CONSTRAINT fk_gratuity_schedule_allocations_entry FOREIGN KEY (entry_id) REFERENCES tb_gratuity_schedule_entries(entry_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_gratuity_schedule_allocations', 'fk_gratuity_schedule_allocations_ledger', 'ADD CONSTRAINT fk_gratuity_schedule_allocations_ledger FOREIGN KEY (ledger_id) REFERENCES tb_arrears_ledger(ledger_id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_payroll_upload_entries`
CALL schema_add_fk_if_missing('tb_payroll_upload_entries', 'fk_payroll_entries_cycle', 'ADD CONSTRAINT fk_payroll_entries_cycle FOREIGN KEY (cycle_id) REFERENCES tb_payroll_upload_cycles(cycle_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_payroll_upload_entries', 'fk_payroll_entries_registry_id', 'ADD CONSTRAINT fk_payroll_entries_registry_id FOREIGN KEY (matched_registry_id) REFERENCES tb_fileregistry(id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_payroll_audit_logs`
CALL schema_add_fk_if_missing('tb_payroll_audit_logs', 'fk_payroll_audit_cycle', 'ADD CONSTRAINT fk_payroll_audit_cycle FOREIGN KEY (cycle_id) REFERENCES tb_payroll_upload_cycles(cycle_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_payroll_audit_logs', 'fk_payroll_audit_actor', 'ADD CONSTRAINT fk_payroll_audit_actor FOREIGN KEY (actor_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_registry_payroll_monthly_status`
CALL schema_add_fk_if_missing('tb_registry_payroll_monthly_status', 'fk_registry_payroll_cycle', 'ADD CONSTRAINT fk_registry_payroll_cycle FOREIGN KEY (cycle_id) REFERENCES tb_payroll_upload_cycles(cycle_id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_suspension_upload_cycles`
CALL schema_add_fk_if_missing('tb_suspension_upload_cycles', 'fk_suspension_cycles_uploaded_by', 'ADD CONSTRAINT fk_suspension_cycles_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_suspension_upload_entries`
CALL schema_add_fk_if_missing('tb_suspension_upload_entries', 'fk_suspension_entries_cycle', 'ADD CONSTRAINT fk_suspension_entries_cycle FOREIGN KEY (suspension_cycle_id) REFERENCES tb_suspension_upload_cycles(suspension_cycle_id) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_arrears_accountability_files`
CALL schema_add_fk_if_missing('tb_arrears_accountability_files', 'fk_arrears_accountability_files_submission', 'ADD CONSTRAINT fk_arrears_accountability_files_submission FOREIGN KEY (submission_id) REFERENCES tb_arrears_accountability_submissions(submission_id) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_arrears_accountability_submissions`
CALL schema_add_fk_if_missing('tb_arrears_accountability_submissions', 'fk_arrears_accountability_payment', 'ADD CONSTRAINT fk_arrears_accountability_payment FOREIGN KEY (payment_id) REFERENCES tb_arrears_payments(payment_id) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_arrears_accountability_submissions', 'fk_arrears_accountability_submitted_by', 'ADD CONSTRAINT fk_arrears_accountability_submitted_by FOREIGN KEY (submitted_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_arrears_accountability_submissions', 'fk_arrears_accountability_regno', 'ADD CONSTRAINT fk_arrears_accountability_regno FOREIGN KEY (regNo) REFERENCES tb_fileregistry(regNo) ON UPDATE CASCADE ON DELETE RESTRICT');

-- Table: `tb_arrears_ledger`
CALL schema_add_fk_if_missing('tb_arrears_ledger', 'fk_arrears_ledger_recorded_by', 'ADD CONSTRAINT fk_arrears_ledger_recorded_by FOREIGN KEY (recorded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_arrears_ledger', 'fk_arrears_ledger_regno', 'ADD CONSTRAINT fk_arrears_ledger_regno FOREIGN KEY (regNo) REFERENCES tb_fileregistry(regNo) ON UPDATE CASCADE ON DELETE RESTRICT');

-- Table: `tb_arrears_payments`
CALL schema_add_fk_if_missing('tb_arrears_payments', 'fk_arrears_payments_recorded_by', 'ADD CONSTRAINT fk_arrears_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_arrears_payments', 'fk_arrears_payments_regno', 'ADD CONSTRAINT fk_arrears_payments_regno FOREIGN KEY (regNo) REFERENCES tb_fileregistry(regNo) ON UPDATE CASCADE ON DELETE RESTRICT');
CALL schema_add_fk_if_missing('tb_arrears_payments', 'fk_arrears_payments_latest_submission', 'ADD CONSTRAINT fk_arrears_payments_latest_submission FOREIGN KEY (latest_submission_id) REFERENCES tb_arrears_accountability_submissions(submission_id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_arrears_payment_allocations`
CALL schema_add_fk_if_missing('tb_arrears_payment_allocations', 'fk_arrears_alloc_payment', 'ADD CONSTRAINT fk_arrears_alloc_payment FOREIGN KEY (payment_id) REFERENCES tb_arrears_payments(payment_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_arrears_payment_allocations', 'fk_arrears_alloc_ledger', 'ADD CONSTRAINT fk_arrears_alloc_ledger FOREIGN KEY (ledger_id) REFERENCES tb_arrears_ledger(ledger_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_arrears_payment_allocations', 'fk_arrears_alloc_accountability', 'ADD CONSTRAINT fk_arrears_alloc_accountability FOREIGN KEY (accountability_submission_id) REFERENCES tb_arrears_accountability_submissions(submission_id) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_feedback_activity`
CALL schema_add_fk_if_missing('tb_feedback_activity', 'fk_feedback_activity_submission', 'ADD CONSTRAINT fk_feedback_activity_submission FOREIGN KEY (submission_id) REFERENCES tb_feedback_submissions(submission_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_feedback_activity', 'fk_feedback_activity_actor', 'ADD CONSTRAINT fk_feedback_activity_actor FOREIGN KEY (actor_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_feedback_submissions`
CALL schema_add_fk_if_missing('tb_feedback_submissions', 'fk_feedback_submissions_submitted_by', 'ADD CONSTRAINT fk_feedback_submissions_submitted_by FOREIGN KEY (submitted_by_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_feedback_submissions', 'fk_feedback_submissions_assigned_to', 'ADD CONSTRAINT fk_feedback_submissions_assigned_to FOREIGN KEY (assigned_to_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_feedback_submissions', 'fk_feedback_submissions_reviewed_by', 'ADD CONSTRAINT fk_feedback_submissions_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_feedback_submissions', 'fk_feedback_submissions_resolved_by', 'ADD CONSTRAINT fk_feedback_submissions_resolved_by FOREIGN KEY (resolved_by_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_feedback_submissions', 'fk_feedback_submissions_closed_by', 'ADD CONSTRAINT fk_feedback_submissions_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_data_export_runs`
CALL schema_add_fk_if_missing('tb_data_export_runs', 'fk_data_export_runs_created_by', 'ADD CONSTRAINT fk_data_export_runs_created_by FOREIGN KEY (created_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_data_import_runs`
CALL schema_add_fk_if_missing('tb_data_import_runs', 'fk_data_import_runs_created_by', 'ADD CONSTRAINT fk_data_import_runs_created_by FOREIGN KEY (created_by) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_system_log_resolutions`
CALL schema_add_fk_if_missing('tb_system_log_resolutions', 'fk_system_log_resolutions_log', 'ADD CONSTRAINT fk_system_log_resolutions_log FOREIGN KEY (log_id) REFERENCES tb_system_logs(log_id) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_task_alerts`
CALL schema_add_fk_if_missing('tb_task_alerts', 'fk_task_alerts_task', 'ADD CONSTRAINT fk_task_alerts_task FOREIGN KEY (task_id) REFERENCES tb_tasks(taskId) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_task_comments`
CALL schema_add_fk_if_missing('tb_task_comments', 'fk_task_comments_task', 'ADD CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tb_tasks(taskId) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_task_completion_queue`
CALL schema_add_fk_if_missing('tb_task_completion_queue', 'fk_task_completion_queue_task', 'ADD CONSTRAINT fk_task_completion_queue_task FOREIGN KEY (task_id) REFERENCES tb_tasks(taskId) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_task_completion_queue', 'fk_task_completion_queue_processed_task', 'ADD CONSTRAINT fk_task_completion_queue_processed_task FOREIGN KEY (processed_task_id) REFERENCES tb_tasks(taskId) ON UPDATE CASCADE ON DELETE SET NULL');
CALL schema_add_fk_if_missing('tb_task_completion_queue', 'fk_task_completion_queue_owner', 'ADD CONSTRAINT fk_task_completion_queue_owner FOREIGN KEY (owner_user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE RESTRICT');

-- Table: `tb_user_permissions`
CALL schema_add_fk_if_missing('tb_user_permissions', 'fk_user_permissions_user', 'ADD CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_user_settings`
CALL schema_add_fk_if_missing('tb_user_settings', 'fk_user_settings_user', 'ADD CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_role_permissions`
CALL schema_add_fk_if_missing('tb_role_permissions', 'fk_role_permissions_role', 'ADD CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_key) REFERENCES tb_roles(role_key) ON UPDATE CASCADE ON DELETE CASCADE');

-- Table: `tb_roles`
CALL schema_add_fk_if_missing('tb_roles', 'fk_roles_clone_from', 'ADD CONSTRAINT fk_roles_clone_from FOREIGN KEY (clone_from_role) REFERENCES tb_roles(role_key) ON UPDATE CASCADE ON DELETE SET NULL');

-- Table: `tb_podcast_views`
CALL schema_add_fk_if_missing('tb_podcast_views', 'fk_podcast_views_podcast', 'ADD CONSTRAINT fk_podcast_views_podcast FOREIGN KEY (podcast_id) REFERENCES tb_podcast_videos(podcast_id) ON UPDATE CASCADE ON DELETE CASCADE');
CALL schema_add_fk_if_missing('tb_podcast_views', 'fk_podcast_views_viewer', 'ADD CONSTRAINT fk_podcast_views_viewer FOREIGN KEY (viewer_id) REFERENCES tb_users(userId) ON UPDATE CASCADE ON DELETE SET NULL');

-- -------------------------------------------------------------------
-- Cleanup helper procedures
-- -------------------------------------------------------------------
DROP PROCEDURE IF EXISTS schema_add_fk_if_missing;
DROP PROCEDURE IF EXISTS schema_add_key_if_missing;
DROP PROCEDURE IF EXISTS schema_add_column_if_missing;
DROP PROCEDURE IF EXISTS schema_exec_ddl;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
