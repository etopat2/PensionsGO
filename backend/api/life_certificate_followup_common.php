<?php

function ensureLifeCertificateFollowupTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_life_certificate_followup_cases (
        case_id int(11) NOT NULL AUTO_INCREMENT,
        reg_no varchar(50) NOT NULL,
        compliance_year int(11) NOT NULL,
        status enum('Open','Complied','Suspension Submitted','Suspended','Closed') NOT NULL DEFAULT 'Open',
        suspension_status enum('Not Eligible','Eligible','Submitted','Suspended','Reinstated') NOT NULL DEFAULT 'Not Eligible',
        suspension_submitted_at datetime DEFAULT NULL,
        suspension_submitted_by varchar(100) DEFAULT NULL,
        suspension_reason text DEFAULT NULL,
        closed_at datetime DEFAULT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (case_id),
        UNIQUE KEY uniq_lcf_case (reg_no, compliance_year),
        KEY idx_lcf_case_year_status (compliance_year, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS tb_life_certificate_correspondence (
        correspondence_id int(11) NOT NULL AUTO_INCREMENT,
        case_id int(11) NOT NULL,
        channel enum('Phone Call','SMS','Email','Letter','Home Visit','Next of Kin','Other') NOT NULL,
        attempted_at datetime NOT NULL,
        contact_person varchar(160) DEFAULT NULL,
        contact_value varchar(160) DEFAULT NULL,
        outcome enum('Reached - Will Comply','Reached - Submitted','Reached - Unable to Comply','No Answer','Wrong Number','Phone Off','Message Left','Letter Delivered','Letter Returned','Reported Deceased','Other') NOT NULL,
        reach_status enum('Successful','Unsuccessful') NOT NULL,
        response_notes text NOT NULL,
        follow_up_date date DEFAULT NULL,
        recorded_by varchar(100) NOT NULL,
        recorded_by_name varchar(160) DEFAULT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (correspondence_id),
        KEY idx_lcf_correspondence_case (case_id, attempted_at),
        CONSTRAINT fk_lcf_correspondence_case FOREIGN KEY (case_id) REFERENCES tb_life_certificate_followup_cases (case_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function lifeCertificateFollowupPolicy(mysqli $conn, int $year): array {
    $month = max(1, min(12, getAppSettingInt($conn, 'life_certificate_submission_deadline_month', 5)));
    $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $day = max(1, min($maxDay, getAppSettingInt($conn, 'life_certificate_submission_deadline_day', 31)));
    $graceDays = max(0, min(366, getAppSettingInt($conn, 'life_certificate_grace_period_days', 61)));
    $minimumYears = max(0, min(100, getAppSettingInt($conn, 'life_certificate_suspension_min_retirement_years', 15)));
    $deadline = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $graceEnd = $deadline->modify('+' . $graceDays . ' days');
    return [
        'followupEnabled' => getAppSettingBool($conn, 'life_certificate_followup_enabled', true),
        'suspensionEnabled' => getAppSettingBool($conn, 'life_certificate_suspension_referrals_enabled', true),
        'deadline' => $deadline,
        'graceEnd' => $graceEnd,
        'graceDays' => $graceDays,
        'minimumRetirementYears' => $minimumYears,
        'minimumContactAttempts' => max(0, min(20, getAppSettingInt($conn, 'life_certificate_min_contact_attempts_before_suspension', 1)))
    ];
}

function lifeCertificateFollowupPhase(mysqli $conn, int $year): array {
    $policy = lifeCertificateFollowupPolicy($conn, $year);
    $today = new DateTimeImmutable('today');
    $base = [
        'deadline' => $policy['deadline']->format('Y-m-d'), 'graceEnd' => $policy['graceEnd']->format('Y-m-d'),
        'graceDays' => $policy['graceDays'], 'minimumRetirementYears' => $policy['minimumRetirementYears'],
        'minimumContactAttempts' => $policy['minimumContactAttempts'], 'followupEnabled' => $policy['followupEnabled'],
        'suspensionEnabled' => $policy['suspensionEnabled']
    ];
    if (!$policy['followupEnabled']) return $base + ['code' => 'disabled', 'label' => 'Follow-up disabled', 'actionable' => false];
    if ($today <= $policy['deadline']) return $base + ['code' => 'submission_window', 'label' => 'Submission window', 'actionable' => false];
    if ($today <= $policy['graceEnd']) return $base + ['code' => 'grace_period', 'label' => 'Grace-period outreach', 'actionable' => true];
    return $base + ['code' => 'post_grace', 'label' => 'Post-grace enforcement', 'actionable' => true];
}

function lifeCertificateFollowupActor(): array {
    return [
        'id' => (string)($_SESSION['userId'] ?? 'system'),
        'name' => (string)($_SESSION['fullName'] ?? $_SESSION['username'] ?? $_SESSION['userName'] ?? 'System User'),
        'role' => (string)($_SESSION['userRole'] ?? 'system')
    ];
}
