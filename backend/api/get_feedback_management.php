<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';

function requireFeedbackViewAccess(mysqli $conn): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    ensureFeedbackWorkflowTables($conn);

    if (!currentUserHasPermission($conn, 'feedback.view')) {
        throw new RuntimeException('Access denied');
    }

    return [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User'),
        'user_role' => normalizeRoleKey((string)($_SESSION['userRole'] ?? ''))
    ];
}

function feedbackEsc(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function feedbackPreview(string $text, int $limit = 110): string
{
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($clean === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($clean, 'UTF-8') > $limit ? (mb_substr($clean, 0, $limit - 1, 'UTF-8') . '...') : $clean;
    }
    return strlen($clean) > $limit ? (substr($clean, 0, $limit - 1) . '...') : $clean;
}

function feedbackAnalyticsBucketLabel(int $timestamp): string
{
    return date('d M', $timestamp);
}

try {
    $actor = requireFeedbackViewAccess($conn);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(5, min(50, (int)($_GET['limit'] ?? 12)));
    $search = trim((string)($_GET['search'] ?? ''));
    $feedbackType = strtolower(trim((string)($_GET['feedback_type'] ?? '')));
    $audience = strtolower(trim((string)($_GET['audience'] ?? '')));
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $priority = strtolower(trim((string)($_GET['priority'] ?? '')));
    $assignedTo = trim((string)($_GET['assigned_to'] ?? ''));
    $overdueOnly = filter_var($_GET['overdue_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));

    $slaDays = max(1, getAppSettingInt($conn, 'feedback_response_sla_days', 5));
    $where = ['1=1'];

    if ($search !== '') {
        $like = '%' . $conn->real_escape_string($search) . '%';
        $where[] = "(reference_no LIKE '{$like}' OR full_name LIKE '{$like}' OR email_address LIKE '{$like}' OR phone_number LIKE '{$like}' OR subject LIKE '{$like}' OR message LIKE '{$like}' OR COALESCE(assigned_to_name, '') LIKE '{$like}')";
    }
    if ($feedbackType !== '') {
        $where[] = "feedback_type = " . feedbackEsc($conn, $feedbackType);
    }
    if ($audience !== '') {
        $where[] = "audience = " . feedbackEsc($conn, $audience);
    }
    if ($status !== '') {
        $where[] = "status = " . feedbackEsc($conn, $status);
    }
    if ($priority !== '') {
        $where[] = "priority = " . feedbackEsc($conn, $priority);
    }
    if ($assignedTo !== '') {
        $where[] = "assigned_to_user_id = " . feedbackEsc($conn, $assignedTo);
    }
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = "DATE(submitted_at) >= " . feedbackEsc($conn, $dateFrom);
    }
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = "DATE(submitted_at) <= " . feedbackEsc($conn, $dateTo);
    }
    if ($overdueOnly) {
        $where[] = "status IN ('new','reviewed')";
        $where[] = "submitted_at < DATE_SUB(NOW(), INTERVAL {$slaDays} DAY)";
    }

    $sql = "
        SELECT
            submission_id,
            reference_no,
            feedback_type,
            audience,
            full_name,
            email_address,
            phone_number,
            subject,
            message,
            page_context,
            submitted_by_user_id,
            submitted_by_role,
            status,
            priority,
            assigned_to_user_id,
            assigned_to_name,
            assigned_to_role,
            assigned_at,
            submitted_at,
            updated_at,
            reviewed_at,
            resolved_at,
            closed_at,
            resolution_summary
        FROM tb_feedback_submissions
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE WHEN status IN ('new','reviewed') THEN 0 ELSE 1 END,
            CASE priority
                WHEN 'critical' THEN 0
                WHEN 'high' THEN 1
                WHEN 'normal' THEN 2
                ELSE 3
            END,
            submitted_at DESC,
            submission_id DESC
    ";

    $rows = [];
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $submittedAt = strtotime((string)($row['submitted_at'] ?? '')) ?: time();
            $dueAt = strtotime('+' . $slaDays . ' days', $submittedAt);
            $isOverdue = feedbackSubmissionRequiresAttention($row, $slaDays);
            $statusKey = strtolower(trim((string)($row['status'] ?? 'new')));
            $resolvedAt = strtotime((string)($row['resolved_at'] ?? $row['closed_at'] ?? '')) ?: 0;
            $completionTs = $resolvedAt > 0 ? $resolvedAt : (strtotime((string)($row['closed_at'] ?? '')) ?: 0);
            $rows[] = [
                'submission_id' => (int)($row['submission_id'] ?? 0),
                'reference_no' => (string)($row['reference_no'] ?? ''),
                'feedback_type' => (string)($row['feedback_type'] ?? ''),
                'feedback_type_label' => ucwords(str_replace('_', ' ', (string)($row['feedback_type'] ?? ''))),
                'audience' => (string)($row['audience'] ?? ''),
                'audience_label' => getFeedbackAudienceLabel((string)($row['audience'] ?? '')),
                'full_name' => (string)($row['full_name'] ?? ''),
                'email_address' => (string)($row['email_address'] ?? ''),
                'phone_number' => (string)($row['phone_number'] ?? ''),
                'subject' => (string)($row['subject'] ?? ''),
                'message_preview' => feedbackPreview((string)($row['message'] ?? '')),
                'page_context' => (string)($row['page_context'] ?? ''),
                'submitted_by_role' => (string)($row['submitted_by_role'] ?? ''),
                'submitted_by_role_label' => formatRoleLabel($conn, normalizeRoleKey((string)($row['submitted_by_role'] ?? ''))),
                'status' => $statusKey,
                'status_label' => getFeedbackStatusLabel($statusKey),
                'priority' => strtolower(trim((string)($row['priority'] ?? 'normal'))),
                'priority_label' => ucwords(strtolower(trim((string)($row['priority'] ?? 'normal')))),
                'assigned_to_user_id' => (string)($row['assigned_to_user_id'] ?? ''),
                'assigned_to_name' => (string)($row['assigned_to_name'] ?? ''),
                'assigned_to_role' => (string)($row['assigned_to_role'] ?? ''),
                'assigned_to_role_label' => formatRoleLabel($conn, normalizeRoleKey((string)($row['assigned_to_role'] ?? ''))),
                'assigned_at' => (string)($row['assigned_at'] ?? ''),
                'submitted_at' => (string)($row['submitted_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
                'resolved_at' => (string)($row['resolved_at'] ?? ''),
                'closed_at' => (string)($row['closed_at'] ?? ''),
                'resolution_summary' => (string)($row['resolution_summary'] ?? ''),
                'due_at' => date('Y-m-d H:i:s', $dueAt),
                'age_hours' => max(0, (int)floor((time() - $submittedAt) / 3600)),
                'is_overdue' => $isOverdue,
                'completed_within_sla' => $completionTs > 0 ? ($completionTs <= $dueAt) : null
            ];
        }
    }

    $summary = [
        'total' => count($rows),
        'new_count' => 0,
        'open_count' => 0,
        'assigned_open' => 0,
        'overdue_count' => 0,
        'resolved_count' => 0,
        'closed_count' => 0,
        'resolved_30d' => 0,
        'closed_30d' => 0
    ];
    $typeCounts = [];
    $audienceCounts = [];
    $trendCounts = [];
    $resolvedWindow = 0;
    $slaMet = 0;
    $avgOpenAgeHoursTotal = 0;
    $avgOpenAgeCount = 0;
    $eightWeekStart = strtotime('monday this week -7 weeks');
    for ($i = 0; $i < 8; $i++) {
        $bucketStart = strtotime('+' . $i . ' week', $eightWeekStart);
        $bucketKey = date('Y-m-d', $bucketStart);
        $trendCounts[$bucketKey] = [
            'label' => feedbackAnalyticsBucketLabel($bucketStart),
            'count' => 0,
            'resolved' => 0
        ];
    }

    foreach ($rows as $row) {
        $statusKey = $row['status'];
        $typeKey = $row['feedback_type_label'] ?: 'General';
        $audienceKey = $row['audience_label'] ?: 'Public';
        $submittedTs = strtotime($row['submitted_at']) ?: time();
        $resolvedTs = strtotime($row['resolved_at'] ?: $row['closed_at']) ?: 0;
        $summary['new_count'] += $statusKey === 'new' ? 1 : 0;
        $summary['open_count'] += in_array($statusKey, ['new', 'reviewed'], true) ? 1 : 0;
        $summary['assigned_open'] += (in_array($statusKey, ['new', 'reviewed'], true) && $row['assigned_to_user_id'] !== '') ? 1 : 0;
        $summary['overdue_count'] += $row['is_overdue'] ? 1 : 0;
        $summary['resolved_count'] += $statusKey === 'resolved' ? 1 : 0;
        $summary['closed_count'] += $statusKey === 'closed' ? 1 : 0;
        $summary['resolved_30d'] += ($resolvedTs > 0 && $resolvedTs >= strtotime('-30 days') && $statusKey === 'resolved') ? 1 : 0;
        $summary['closed_30d'] += ($resolvedTs > 0 && $resolvedTs >= strtotime('-30 days') && $statusKey === 'closed') ? 1 : 0;

        if (!isset($typeCounts[$typeKey])) {
            $typeCounts[$typeKey] = ['count' => 0, 'open' => 0, 'overdue' => 0];
        }
        $typeCounts[$typeKey]['count']++;
        $typeCounts[$typeKey]['open'] += in_array($statusKey, ['new', 'reviewed'], true) ? 1 : 0;
        $typeCounts[$typeKey]['overdue'] += $row['is_overdue'] ? 1 : 0;

        if (!isset($audienceCounts[$audienceKey])) {
            $audienceCounts[$audienceKey] = ['count' => 0, 'resolved' => 0, 'overdue' => 0];
        }
        $audienceCounts[$audienceKey]['count']++;
        $audienceCounts[$audienceKey]['resolved'] += in_array($statusKey, ['resolved', 'closed'], true) ? 1 : 0;
        $audienceCounts[$audienceKey]['overdue'] += $row['is_overdue'] ? 1 : 0;

        if (in_array($statusKey, ['resolved', 'closed'], true) && $row['completed_within_sla'] !== null) {
            $resolvedWindow++;
            $slaMet += $row['completed_within_sla'] ? 1 : 0;
        }
        if (in_array($statusKey, ['new', 'reviewed'], true)) {
            $avgOpenAgeHoursTotal += (int)$row['age_hours'];
            $avgOpenAgeCount++;
        }

        foreach ($trendCounts as $bucketKey => &$bucket) {
            $bucketStart = strtotime($bucketKey . ' 00:00:00');
            $bucketEnd = strtotime('+6 days 23:59:59', $bucketStart);
            if ($submittedTs >= $bucketStart && $submittedTs <= $bucketEnd) {
                $bucket['count']++;
            }
            if ($resolvedTs > 0 && $resolvedTs >= $bucketStart && $resolvedTs <= $bucketEnd) {
                $bucket['resolved']++;
            }
        }
        unset($bucket);
    }

    $typeAnalytics = [];
    foreach ($typeCounts as $label => $data) {
        $typeAnalytics[] = [
            'label' => $label,
            'value' => $data['count'],
            'meta' => $data['open'] . ' open | ' . $data['overdue'] . ' overdue',
            'tone' => $data['overdue'] > 0 ? 'warning' : 'info'
        ];
    }
    usort($typeAnalytics, static fn($a, $b) => ($b['value'] <=> $a['value']));

    $audienceAnalytics = [];
    foreach ($audienceCounts as $label => $data) {
        $audienceAnalytics[] = [
            'label' => $label,
            'value' => $data['count'],
            'meta' => $data['resolved'] . ' completed | ' . $data['overdue'] . ' overdue',
            'tone' => $data['overdue'] > 0 ? 'warning' : 'success'
        ];
    }
    usort($audienceAnalytics, static fn($a, $b) => ($b['value'] <=> $a['value']));

    $trendAnalytics = [];
    foreach ($trendCounts as $bucket) {
        $trendAnalytics[] = [
            'label' => $bucket['label'],
            'value' => $bucket['count'],
            'meta' => $bucket['resolved'] . ' resolved',
            'tone' => $bucket['count'] > $bucket['resolved'] ? 'warning' : 'info'
        ];
    }

    $slaPercent = $resolvedWindow > 0 ? round(($slaMet / $resolvedWindow) * 100, 1) : 0;
    $assignmentCoverage = $summary['open_count'] > 0 ? round(($summary['assigned_open'] / $summary['open_count']) * 100, 1) : 0;
    $avgOpenDays = $avgOpenAgeCount > 0 ? round(($avgOpenAgeHoursTotal / $avgOpenAgeCount) / 24, 1) : 0;
    $resolutionRate = $summary['total'] > 0 ? round((($summary['resolved_count'] + $summary['closed_count']) / $summary['total']) * 100, 1) : 0;
    $insights = [
        [
            'label' => 'SLA Compliance',
            'value' => $slaPercent . '%',
            'helper' => $resolvedWindow > 0 ? ($slaMet . ' of ' . $resolvedWindow . ' completed within ' . $slaDays . ' day SLA.') : 'No completed submissions yet.',
            'tone' => $slaPercent >= 80 ? 'success' : ($slaPercent >= 60 ? 'warning' : 'muted')
        ],
        [
            'label' => 'Assignment Coverage',
            'value' => $assignmentCoverage . '%',
            'helper' => $summary['assigned_open'] . ' of ' . $summary['open_count'] . ' open submissions already owned.',
            'tone' => $assignmentCoverage >= 85 ? 'success' : ($assignmentCoverage >= 60 ? 'warning' : 'muted')
        ],
        [
            'label' => 'Average Open Age',
            'value' => number_format($avgOpenDays, 1) . ' days',
            'helper' => 'Average age of currently open feedback submissions.',
            'tone' => $avgOpenDays <= $slaDays ? 'success' : ($avgOpenDays <= ($slaDays * 1.5) ? 'warning' : 'muted')
        ],
        [
            'label' => 'Resolution Rate',
            'value' => $resolutionRate . '%',
            'helper' => ($summary['resolved_count'] + $summary['closed_count']) . ' of ' . $summary['total'] . ' submissions completed.',
            'tone' => $resolutionRate >= 70 ? 'success' : ($resolutionRate >= 45 ? 'warning' : 'muted')
        ]
    ];

    $notes = [];
    if ($summary['overdue_count'] > 0) {
        $notes[] = [
            'title' => 'Escalate overdue feedback',
            'body' => $summary['overdue_count'] . ' submissions have exceeded the configured SLA and should be triaged first.',
            'tone' => 'warning'
        ];
    }
    if ($assignmentCoverage < 70 && $summary['open_count'] > 0) {
        $notes[] = [
            'title' => 'Improve ownership coverage',
            'body' => 'A significant share of open feedback is still unassigned. Assign owners to reduce service lag.',
            'tone' => 'info'
        ];
    }
    if ($resolvedWindow === 0) {
        $notes[] = [
            'title' => 'No completed feedback yet',
            'body' => 'Start closing the oldest feedback submissions so service response metrics can stabilize.',
            'tone' => 'muted'
        ];
    }

    $offset = ($page - 1) * $limit;
    $pageRows = array_slice($rows, $offset, $limit);

    $typeOptions = ['general_feedback', 'bug_report', 'data_correction', 'service_request', 'suggestion', 'complaint', 'pensioner_support'];
    if ($typeResult = $conn->query("SELECT DISTINCT feedback_type FROM tb_feedback_submissions WHERE feedback_type IS NOT NULL AND TRIM(feedback_type) <> '' ORDER BY feedback_type ASC")) {
        while ($typeRow = $typeResult->fetch_assoc()) {
            $typeOptions[] = (string)($typeRow['feedback_type'] ?? '');
        }
    }
    $typeOptions = array_values(array_unique(array_filter(array_map('trim', $typeOptions))));

    echo json_encode([
        'success' => true,
        'permissions' => [
            'can_manage' => currentUserHasPermission($conn, 'feedback.manage')
        ],
        'config' => [
            'sla_days' => $slaDays,
            'allow_assignment' => getAppSettingBool($conn, 'feedback_allow_assignment', true),
            'allow_export' => getAppSettingBool($conn, 'feedback_allow_export', true)
        ],
        'summary' => $summary,
        'analytics' => [
            'by_type' => $typeAnalytics,
            'by_audience' => $audienceAnalytics,
            'trend' => $trendAnalytics,
            'insights' => $insights,
            'notes' => $notes
        ],
        'options' => [
            'feedback_types' => $typeOptions,
            'audiences' => ['public', 'staff', 'pensioner'],
            'statuses' => ['new', 'reviewed', 'resolved', 'closed'],
            'priorities' => ['low', 'normal', 'high', 'critical'],
            'assignees' => getFeedbackManagementAssignableUsers($conn)
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'totalRows' => count($rows),
            'totalPages' => max(1, (int)ceil(count($rows) / $limit))
        ],
        'submissions' => $pageRows,
        'actor' => $actor
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $statusCode = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
