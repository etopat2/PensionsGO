<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if ($role === 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

function ar_clean(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function ar_key(string $value): string
{
    return strtolower(ar_clean($value));
}

function ar_label_year(string $label, ?int $fallback = null): int
{
    if (preg_match('/\b(20\d{2}|21\d{2})\b/', $label, $matches) === 1) {
        $year = (int)$matches[1];
        if ($year >= 2000 && $year <= 2100) {
            return $year;
        }
    }
    $fallbackYear = $fallback ?? (int)date('Y');
    return ($fallbackYear >= 2000 && $fallbackYear <= 2100) ? $fallbackYear : (int)date('Y');
}

function ar_label_without_year(string $label): string
{
    return ar_clean(preg_replace('/\s*\((?:20\d{2}|21\d{2})\)\s*/', ' ', $label) ?? $label);
}

function ar_label_payroll_period(mysqli $conn, string $label): array
{
    if (preg_match('/\b(20\d{2}|21\d{2})[-\/](0?[1-9]|1[0-2])\b/', $label, $matches) === 1) {
        return ['year' => (int)$matches[1], 'month' => (int)$matches[2]];
    }
    if (preg_match('/\b(0?[1-9]|1[0-2])[-\/](20\d{2}|21\d{2})\b/', $label, $matches) === 1) {
        return ['year' => (int)$matches[2], 'month' => (int)$matches[1]];
    }
    return ar_latest_payroll_cycle($conn);
}

function ar_like(mysqli $conn, string $value): string
{
    return "'%" . $conn->real_escape_string($value) . "%'";
}

function ar_eq(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function ar_column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    if ($table === '' || $column === '') {
        return false;
    }
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function ar_table_columns(mysqli $conn, string $table): array
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') {
        return [];
    }
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $field = trim((string)($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = ar_human_label($field);
            }
        }
        $result->free();
    }
    return $columns;
}

function ar_table_exists(mysqli $conn, string $table): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') {
        return false;
    }
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function ar_human_label(string $value): string
{
    $value = preg_replace('/(?<!^)([A-Z])/', ' $1', $value) ?? $value;
    return ucwords(str_replace(['_', '-'], ' ', trim($value)));
}

function ar_frontend_should_hide_field(string $field): bool
{
    $field = trim($field);
    if ($field === '') {
        return true;
    }
    $lower = strtolower($field);
    if (in_array($lower, ['id', 'pk', 'row_id', 'record_id', '__record_id'], true)) {
        return true;
    }
    if (preg_match('/(^|_)(id)$/i', $field) === 1 || preg_match('/id$/i', $field) === 1) {
        return !preg_match('/(regno|fileno|computerno|supplierno|phone)$/i', $field);
    }
    if (preg_match('/(password|token|hash|secret|otp|session|remember|csrf)/i', $field) === 1) {
        return true;
    }
    if (
        preg_match('/(^|_)(submission_by|appn_status_by|created_by|updated_by|recorded_by|resolved_by|closed_by|delivered_by|received_by|assigned_to|assigned_to_user_id|owner|actor|assignee)$/i', $field) === 1
        || preg_match('/(by|userid|user_id)$/i', $field) === 1
    ) {
        return true;
    }
    if (preg_match('/(^|_)(is_)?deleted($|_)|delete|deleted_/i', $field) === 1) {
        return true;
    }
    if (preg_match('/^(timestamp|timeStamp)$/i', $field) === 1) {
        return true;
    }
    if (preg_match('/(snapshot|metadata|json|raw|payload|file_path|relative_path)$/i', $field) === 1) {
        return true;
    }
    return false;
}

function ar_frontend_columns(array $columns): array
{
    $public = [];
    foreach ($columns as $field => $label) {
        if (ar_frontend_should_hide_field((string)$field)) {
            continue;
        }
        $public[(string)$field] = (string)$label;
    }
    return $public;
}

function ar_role_display(mysqli $conn, string $role): string
{
    $role = trim($role);
    if ($role === '') {
        return '';
    }
    if (function_exists('formatRoleLabel')) {
        $key = function_exists('normalizeRoleKey') ? normalizeRoleKey($role) : strtolower($role);
        $label = formatRoleLabel($conn, $key);
        return $key === 'oc_pen' && in_array(strtolower($label), ['oc/pen', 'oc-pen'], true) ? 'OC Pension' : $label;
    }
    return ar_human_label($role);
}

function ar_frontend_value(mysqli $conn, string $field, $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $lower = strtolower($field);
    $text = trim((string)$value);
    if (preg_match('/(^|_)(role|userrole|assigned_role|actor_role|submitted_by_role|created_by_role|updated_by_role)$/i', $field) === 1 || str_ends_with($lower, 'role')) {
        return ar_role_display($conn, $text);
    }
    $labels = [
        'in_shelf' => 'In Shelf',
        'out_shelf' => 'Out of Shelf',
        'out_of_shelf' => 'Out of Shelf',
        'in_process' => 'In Process',
        'not_submitted' => 'Not Submitted',
        'submitted_to_oc' => 'Submitted to OC',
        'due_soon' => 'Due Soon',
        'querried' => 'Queried',
        'queried' => 'Queried',
        'super_admin' => 'Super Administrator',
        'data_entry' => 'Data Entrant',
        'oc_pen' => 'OC/Pension',
        'dep_oc' => 'Deputy OC-PEN'
    ];
    $normalized = strtolower($text);
    if (isset($labels[$normalized])) {
        return $labels[$normalized];
    }
    if (preg_match('/^[a-z0-9]+([_-][a-z0-9]+)+$/i', $text) === 1) {
        return ar_human_label($text);
    }
    return $text;
}

function ar_frontend_rows(mysqli $conn, array $rows, array $columns): array
{
    $publicKeys = array_keys($columns);
    foreach ($rows as &$row) {
        foreach ($publicKeys as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = ar_frontend_value($conn, $field, $row[$field]);
            }
        }
    }
    unset($row);
    return $rows;
}

function ar_computed_column_label(string $column): string
{
    return match ($column) {
        'response_period' => 'Response Period',
        default => ar_human_label($column),
    };
}


function ar_user_display_label(mysqli $conn, array $user): string
{
    $name = trim((string)($user['userName'] ?? ''));
    $email = trim((string)($user['userEmail'] ?? ''));
    $role = trim((string)($user['userRole'] ?? ''));
    $roleLabel = $role !== '' && function_exists('formatRoleLabel')
        ? formatRoleLabel($conn, function_exists('normalizeRoleKey') ? normalizeRoleKey($role) : $role)
        : ar_human_label($role);
    $label = $name !== '' ? $name : ($email !== '' ? $email : trim((string)($user['userId'] ?? 'User')));
    return $roleLabel !== '' ? ($label . ' (' . $roleLabel . ')') : $label;
}

function ar_attach_user_labels(mysqli $conn, array &$row): void
{
    if ($row === [] || !ar_table_exists($conn, 'tb_users')) {
        return;
    }
    $candidateFields = [];
    foreach ($row as $key => $value) {
        $normalized = strtolower((string)$key);
        if ($value === null || $value === '') {
            continue;
        }
        if (
            preg_match('/(^|_)(by|user|owner|assignee|assigned_to|actor)$/', $normalized)
            || preg_match('/(^|_)(created_by|updated_by|submitted_by|submission_by|appn_status_by|recorded_by|resolved_by|closed_by|delivered_by|received_by|assigned_to_user_id)$/', $normalized)
            || substr($normalized, -2) === 'by'
            || strpos($normalized, 'userid') !== false
        ) {
            $candidateFields[$key] = (string)$value;
        }
    }
    if ($candidateFields === []) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_values($candidateFields), static fn($value) => trim($value) !== '')));
    if ($ids === []) {
        return;
    }
    $quoted = array_map(static fn($value) => ar_eq($conn, (string)$value), $ids);
    $sql = "
        SELECT Id, userId, userName, userEmail, userRole
        FROM tb_users
        WHERE userId IN (" . implode(',', $quoted) . ")
           OR CAST(Id AS CHAR) IN (" . implode(',', $quoted) . ")
    ";
    $map = [];
    if ($result = $conn->query($sql)) {
        while ($user = $result->fetch_assoc()) {
            $label = ar_user_display_label($conn, $user);
            if (!empty($user['userId'])) {
                $map[(string)$user['userId']] = $label;
            }
            if (!empty($user['Id'])) {
                $map[(string)$user['Id']] = $label;
            }
        }
        $result->free();
    }
    foreach ($candidateFields as $field => $value) {
        if (isset($map[$value])) {
            $row[$field . '_label'] = $map[$value];
        }
    }
}

function ar_attach_document_preview(mysqli $conn, array &$row, string $detailTable): void
{
    if ($detailTable !== 'tb_staffdue' || $row === [] || !ar_table_exists($conn, 'tb_staff_documents')) {
        return;
    }
    $staffId = (int)($row['id'] ?? 0);
    $regNo = trim((string)($row['regNo'] ?? ''));
    if ($staffId <= 0 && $regNo === '') {
        return;
    }
    if ($staffId > 0) {
        $stmt = $conn->prepare("
            SELECT document_id, doc_type, file_name, uploaded_at
            FROM tb_staff_documents
            WHERE staffdue_id = ?
            ORDER BY uploaded_at DESC, document_id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $staffId);
        }
    } else {
        $stmt = $conn->prepare("
            SELECT document_id, doc_type, file_name, uploaded_at
            FROM tb_staff_documents
            WHERE regNo = ?
            ORDER BY uploaded_at DESC, document_id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $regNo);
        }
    }
    if (!$stmt) {
        return;
    }
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($doc) {
        $row['_document_preview_id'] = (string)$doc['document_id'];
        $row['_document_preview_label'] = trim((string)($doc['file_name'] ?? '')) ?: (trim((string)($doc['doc_type'] ?? 'Document')) ?: 'Document');
        $row['_document_preview_uploaded_at'] = (string)($doc['uploaded_at'] ?? '');
    }
}

function ar_pdf_column_widths(array $columns, array $labels, array $rows): array
{
    $weights = [];
    $textLength = static function (string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    };
    $wordStats = static function (string $value) use ($textLength): array {
        $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $longest = 0;
        foreach ($words as $word) {
            $longest = max($longest, $textLength((string)$word));
        }
        return [
            'words' => count($words),
            'longest' => $longest,
            'length' => $textLength($value)
        ];
    };
    foreach ($columns as $column) {
        $labelStats = $wordStats((string)($labels[$column] ?? ar_human_label((string)$column)));
        $maxLength = $labelStats['length'];
        $maxWord = $labelStats['longest'];
        $totalLength = $labelStats['length'];
        $totalWords = max(1, $labelStats['words']);
        $sampleCount = 1;
        foreach (array_slice($rows, 0, 60) as $row) {
            $stats = $wordStats((string)($row[$column] ?? ''));
            $maxLength = max($maxLength, $stats['length']);
            $maxWord = max($maxWord, $stats['longest']);
            $totalLength += min($stats['length'], 96);
            $totalWords += min($stats['words'], 18);
            $sampleCount++;
        }
        $averageLength = $totalLength / max(1, $sampleCount);
        $averageWords = $totalWords / max(1, $sampleCount);
        $weight = 0.7
            + (min($averageLength, 54) / 21)
            + (min($maxLength, 120) / 86)
            + (min($maxWord, 42) / 7.2)
            + (min($labelStats['longest'], 34) / 7.5)
            + (min($averageWords, 12) / 10);
        if (preg_match('/(amount|salary|pension|gratuity|balance|paid|expected|total|count|year|month|date|status|role|type)$/i', (string)$column)) {
            if ($maxWord <= 14 && $averageLength <= 28) {
                $weight *= 0.82;
            }
        }
        $weights[] = max(0.55, min(6.7, $weight));
    }
    return $weights;
}

function ar_detail_alias(string $table): string
{
    return [
        'tb_fileregistry' => 'fr',
        'tb_staffdue' => 'sd',
        'tb_arrears_ledger' => 'l',
        'tb_file_movements' => 'm',
        'tb_users' => 'u',
        'tb_tasks' => 't',
        'tb_feedback_submissions' => 'f',
        'tb_task_alerts' => 'a',
        'tb_task_delegation_logs' => 'd',
        'tb_workflow_logs' => 'w',
        'tb_appnstatus' => 'a',
        'tb_file_registry_recycle_bin' => 'rb'
    ][$table] ?? '';
}

function ar_date_filter(string $column, string $from, string $to, mysqli $conn): array
{
    $where = [];
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = "DATE({$column}) >= " . ar_eq($conn, $from);
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = "DATE({$column}) <= " . ar_eq($conn, $to);
    }
    return $where;
}

function ar_latest_payroll_cycle(mysqli $conn): array
{
    ensurePayrollManagementTables($conn);
    $row = null;
    $res = $conn->query("SELECT payroll_year, payroll_month FROM tb_payroll_upload_cycles WHERE COALESCE(is_deleted,0)=0 ORDER BY payroll_year DESC, payroll_month DESC, cycle_id DESC LIMIT 1");
    if ($res) {
        $row = $res->fetch_assoc() ?: null;
        $res->free();
    }
    return [
        'year' => (int)($row['payroll_year'] ?? date('Y')),
        'month' => (int)($row['payroll_month'] ?? date('n'))
    ];
}

function ar_staff_due_expressions(mysqli $conn): array
{
    ensureApplicationQueueTable($conn);
    ensureTasksTable($conn);
    $verificationEscalationDays = getStaffDueVerificationEscalationDays($conn);
    $verificationDueSoonDays = getStaffDueVerificationDueSoonDays($conn, $verificationEscalationDays);
    $appnStatusExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('queried', 'querried') THEN 'queried'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'rejected' THEN 'rejected'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('in_process', 'in process', 'inprocess') THEN 'in_process'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('completed', 'approved', 'done') THEN 'completed'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'verified' THEN 'verified'
            ELSE 'pending'
        END
    ";
    $registryCompletionExpr = "
        EXISTS (
            SELECT 1
            FROM tb_fileregistry fr_done
            WHERE fr_done.regNo = sd.regNo
              AND COALESCE(fr_done.is_deleted, 0) = 0
        )
    ";
    $workflowStateExpr = "
        CASE
            WHEN {$registryCompletionExpr}
                OR {$appnStatusExpr} = 'completed'
                OR COALESCE(q.status, '') = 'completed'
                OR (
                    q.queue_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM tb_tasks t_done
                        WHERE t_done.related_staff_id = sd.id
                          AND t_done.task_type = 'approval'
                          AND t_done.status = 'completed'
                    )
                )
                THEN 'completed'
            WHEN {$appnStatusExpr} = 'in_process'
                OR COALESCE(q.status, '') IN ('submitted_to_oc', 'in_progress')
                OR (
                    q.queue_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM tb_tasks t_live
                        WHERE t_live.related_staff_id = sd.id
                          AND t_live.status IN ('pending', 'assigned', 'in_progress', 'deferred', 'returned')
                    )
                )
                THEN 'in_process'
            ELSE ''
        END
    ";
    $workflowInitiationExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) <> 'submitted' THEN 'not_submitted'
            WHEN {$appnStatusExpr} <> 'pending' THEN 'initiated'
            WHEN sd.submission_at IS NULL THEN 'pending'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationEscalationDays} DAY) THEN 'escalated'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationDueSoonDays} DAY) THEN 'due_soon'
            ELSE 'pending'
        END
    ";

    return [
        'appn' => $appnStatusExpr,
        'workflow_state' => $workflowStateExpr,
        'workflow_initiation' => $workflowInitiationExpr
    ];
}

function ar_registry_spec(mysqli $conn, string $source, string $label): array
{
    $labelKey = ar_key($label);
    $where = ["COALESCE(fr.is_deleted,0)=0"];
    $title = $label;
    $order = 'fr.sName ASC, fr.fName ASC, fr.regNo ASC';
    $demographicsSource = in_array($source, ['demographicsOverallGrid', 'demographicsLifespanGrid', 'demographicsEstateGrid', 'demographicsGenderAnalytics', 'demographicsRegionAnalytics', 'demographicsRetirementAnalytics'], true);

    if ($demographicsSource) {
        $where[] = "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) NOT IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')";
    }

    if (str_contains($labelKey, 'alive')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
    } elseif (str_contains($labelKey, 'deceased') || str_contains($labelKey, 'estate')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
    }
    if (str_contains($labelKey, 'male')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.gender,'')))='male'";
    } elseif (str_contains($labelKey, 'female')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.gender,'')))='female'";
    }
    if (str_contains($labelKey, 'one-off') || str_contains($labelKey, 'one off')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.payType,''))) IN ('one-off payment','one off payment','one-off','one off','oneoff')";
    } elseif (str_contains($labelKey, 'pensioner')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.payType,''))) NOT IN ('one-off payment','one off payment','one-off','one off','oneoff')";
    }
    if ($source === 'demographicsGenderAnalytics' && !in_array($labelKey, ['male records', 'female records'], true)) {
        $where[] = "LOWER(TRIM(COALESCE(fr.gender,'')))=" . ar_eq($conn, $labelKey);
    }
    if ($source === 'demographicsRegionAnalytics') {
        if (ar_column_exists($conn, 'tb_fileregistry', 'region')) {
            $where[] = "COALESCE(NULLIF(TRIM(fr.region),''),'Unspecified')=" . ar_eq($conn, $label);
        } elseif (ar_column_exists($conn, 'tb_fileregistry', 'district')) {
            $where[] = "COALESCE(NULLIF(TRIM(fr.district),''),'Unspecified')=" . ar_eq($conn, $label);
        }
    }
    if ($source === 'demographicsRetirementAnalytics' || $source === 'retirementModeAnalytics') {
        $where[] = "(fr.retirementType=" . ar_eq($conn, $label) . " OR fr.retirementType=" . ar_eq($conn, function_exists('normalizeBenefitsRetirementTypeKey') ? normalizeBenefitsRetirementTypeKey($label) : $label) . ")";
    }
    if ($source === 'demographicsOverallGrid') {
        if (str_contains($labelKey, 'oldest')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $where[] = "fr.birthDate IS NOT NULL AND fr.birthDate <> ''";
            $order = 'fr.birthDate ASC, fr.sName ASC, fr.fName ASC';
            $title = 'Oldest Alive Pensioners';
        } elseif (str_contains($labelKey, 'youngest')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $where[] = "fr.birthDate IS NOT NULL AND fr.birthDate <> ''";
            $order = 'fr.birthDate DESC, fr.sName ASC, fr.fName ASC';
            $title = 'Youngest Alive Pensioners';
        } elseif (str_contains($labelKey, 'active estate')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
            if (ar_column_exists($conn, 'tb_fileregistry', 'estateExpiryDate')) {
                $where[] = "(fr.estateExpiryDate IS NULL OR fr.estateExpiryDate = '' OR fr.estateExpiryDate >= CURDATE())";
            }
            $title = 'Active Estate Records';
        } elseif (str_contains($labelKey, '15 years elapsed')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
            if (ar_column_exists($conn, 'tb_fileregistry', 'estateExpiryDate')) {
                $where[] = "fr.estateExpiryDate IS NOT NULL AND fr.estateExpiryDate <> '' AND fr.estateExpiryDate < CURDATE()";
            }
            $title = '15 Years Elapsed Estate Records';
        }
    }
    if ($source === 'demographicsLifespanGrid') {
        if (str_contains($labelKey, 'projected')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $where[] = "fr.birthDate IS NOT NULL AND fr.birthDate <> ''";
            $title = $label . ' Pensioners';
        } else {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
            $where[] = "LOWER(TRIM(COALESCE(fr.retirementType,''))) <> 'death'";
            $where[] = "fr.dateOfDeath IS NOT NULL AND fr.dateOfDeath <> ''";
            $where[] = "fr.retirementDate IS NOT NULL AND fr.retirementDate <> ''";
            if (str_contains($labelKey, 'longest')) {
                $order = 'TIMESTAMPDIFF(DAY, fr.retirementDate, fr.dateOfDeath) DESC, fr.dateOfDeath DESC';
            } else {
                $order = 'fr.dateOfDeath DESC, fr.sName ASC, fr.fName ASC';
            }
            $title = $label . ' Records';
        }
    }
    if ($source === 'demographicsEstateGrid') {
        if (str_contains($labelKey, 'within 15')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
            $where[] = "fr.dateOfDeath IS NOT NULL AND fr.dateOfDeath <> ''";
            $where[] = "fr.retirementDate IS NOT NULL AND fr.retirementDate <> ''";
            $where[] = "fr.dateOfDeath < DATE_ADD(fr.retirementDate, INTERVAL 15 YEAR)";
            $title = 'Within 15-Year Cap Records';
        } elseif (str_contains($labelKey, 'beyond 15')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased'";
            $where[] = "fr.dateOfDeath IS NOT NULL AND fr.dateOfDeath <> ''";
            $where[] = "fr.retirementDate IS NOT NULL AND fr.retirementDate <> ''";
            $where[] = "fr.dateOfDeath >= DATE_ADD(fr.retirementDate, INTERVAL 15 YEAR)";
            $title = 'Beyond 15-Year Cap Records';
        } elseif (str_contains($labelKey, 'alive pensioner')) {
            $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $title = 'Alive Pensioners';
        } elseif (str_contains($labelKey, 'explorer')) {
            $title = 'Pensioner Explorer Records';
        }
    }
    if (str_contains($labelKey, 'out of registry') || str_contains($labelKey, 'files out')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.availability_status,'in_shelf')))='out_of_shelf'";
    }
    if (str_contains($labelKey, 'pending life certificate')) {
        $year = (int)date('Y');
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
        $where[] = "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) NOT IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')";
        $where[] = "NOT EXISTS (SELECT 1 FROM tb_life_certificate_submissions lcs WHERE lcs.regNo=fr.regNo AND lcs.submission_year={$year})";
        $title = 'Pending Life Certificates';
    }
    if (str_contains($labelKey, 'life cert pending')) {
        $year = (int)date('Y');
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
        $where[] = "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) NOT IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')";
        $where[] = "NOT EXISTS (SELECT 1 FROM tb_life_certificate_submissions lcs WHERE lcs.regNo=fr.regNo AND lcs.submission_year={$year})";
        $title = 'Life Cert Pending';
    }
    if (str_contains($labelKey, 'in registry') || str_contains($labelKey, 'in shelf')) {
        $where[] = "LOWER(TRIM(COALESCE(fr.availability_status,'in_shelf'))) <> 'out_of_shelf'";
    }

    return [
        'title' => $title,
        'from' => 'tb_fileregistry fr',
        'pk' => 'fr.regNo',
        'detail_table' => 'tb_fileregistry',
        'detail_pk' => 'regNo',
        'date_column' => 'fr.timeStamp',
        'where' => $where,
        'search' => ['fr.regNo', 'fr.sName', 'fr.fName', 'fr.title', 'fr.computerNo', 'fr.supplierNo', 'fr.payType', 'fr.retirementType'],
        'columns' => [
            'regNo' => 'File No.',
            'registry_name' => 'Name',
            'title' => 'Title',
            'computerNo' => 'Computer No.',
            'payType' => 'Pay Type',
            'livingStatus' => 'Living',
            'gender' => 'Gender',
            'birthDate' => 'Birth Date',
            'retirementDate' => 'Retirement Date',
            'dateOfDeath' => 'Date of Death'
        ],
        'select' => "fr.regNo, TRIM(CONCAT(COALESCE(fr.sName,''),' ',COALESCE(fr.fName,''))) AS registry_name, fr.title, fr.computerNo, fr.supplierNo, fr.payType, fr.livingStatus, fr.gender, fr.retirementType, fr.birthDate, fr.retirementDate, fr.dateOfDeath, fr.availability_status",
        'order' => $order
    ];
}

function ar_build_spec(mysqli $conn, string $source, string $label): array
{
    $labelKey = ar_key($label);

    if ($source === 'payrollPaymentExceptions') {
        ensurePayrollManagementTables($conn);
        $period = ar_label_payroll_period($conn, $label);
        $year = (int)$period['year']; $month = (int)$period['month'];
        return [
            'title' => $label,
            'from' => 'tb_payroll_payment_register_entries r INNER JOIN tb_payroll_upload_cycles c ON c.cycle_id=r.cycle_id',
            'pk' => 'r.register_entry_id',
            'detail_table' => 'tb_payroll_payment_register_entries',
            'detail_pk' => 'register_entry_id',
            'date_column' => 'r.payment_date',
            'where' => ["c.payroll_year={$year}", "c.payroll_month={$month}", "COALESCE(c.is_deleted,0)=0", "r.reconciliation_status<>'Paid in Full'"],
            'search' => ['r.supplierNo','r.supplier_name','r.invoice_number','r.eft_number','r.bank_name','r.matched_regNo','r.reconciliation_status'],
            'columns' => ['register_entry_id'=>'ID','supplierNo'=>'Supplier No.','supplier_name'=>'Beneficiary','invoice_number'=>'Invoice','amount_paid'=>'Amount Paid','amount_variance'=>'Variance','reconciliation_status'=>'Result','match_confidence'=>'Confidence','review_status'=>'Review'],
            'select' => 'r.register_entry_id,r.supplierNo,r.supplier_name,r.invoice_number,r.payment_date,r.amount_paid,r.amount_variance,r.eft_number,r.bank_name,r.account_number_masked,r.matched_regNo,r.reconciliation_status,r.match_confidence,r.review_status',
            'order' => 'r.register_entry_id DESC'
        ];
    }

    if ($source === 'lifeCertCards') {
        return ar_build_spec($conn, 'lifeCertAnalyticsBars', ar_clean($label));
    }

    if ($source === 'payrollCards') {
        if (str_contains($labelKey, 'total beneficiar')) {
            return ar_build_spec($conn, 'payrollCoverageAnalytics', $label);
        }
        if (str_contains($labelKey, 'not on payroll') || str_contains($labelKey, 'off payroll')) {
            return ar_build_spec($conn, 'payrollCoverageAnalytics', $label);
        }
        if (str_contains($labelKey, 'on payroll') || str_contains($labelKey, 'beneficiaries') || str_contains($labelKey, 'amount')) {
            return ar_build_spec($conn, 'payrollCoverageAnalytics', $label);
        }
    }

    if ($source === 'staffDueCards') {
        return ar_build_spec($conn, 'staffDuePipelineAnalytics', $label);
    }

    if ($source === 'workflowSummaryCards') {
        if (str_contains($labelKey, 'verification')) {
            return ar_build_spec($conn, 'staffDuePipelineAnalytics', $label);
        }
        return ar_build_spec($conn, 'workflowResponseAnalytics', $label);
    }

    if ($source === 'generalSummaryCards') {
        if (str_contains($labelKey, 'registry')) return ar_registry_spec($conn, 'generalSummaryCards', 'Registry Files');
        if (str_contains($labelKey, 'staff due')) return ar_build_spec($conn, 'staffDuePipelineAnalytics', $label);
        if (str_contains($labelKey, 'workflow')) return ar_build_spec($conn, 'workflowResponseAnalytics', $label);
        if (str_contains($labelKey, 'claim')) return ar_build_spec($conn, 'claimsTypeAnalytics', 'Claims');
        if (str_contains($labelKey, 'payroll')) return ar_build_spec($conn, 'payrollCoverageAnalytics', 'Not on Payroll');
        if (str_contains($labelKey, 'life certificate')) return ar_registry_spec($conn, 'generalSummaryCards', 'Pending Life Certificates');
    }

    if ($source === 'dmRegistrySummaryCards') {
        if (str_contains($labelKey, 'on payroll')) {
            return ar_build_spec($conn, 'payrollCoverageAnalytics', 'On Payroll');
        }
        if (str_contains($labelKey, 'not on payroll') || str_contains($labelKey, 'off payroll')) {
            return ar_build_spec($conn, 'payrollCoverageAnalytics', 'Not on Payroll');
        }
        return ar_registry_spec($conn, $source, $label);
    }

    if ($source === 'dmStaffDueSummaryCards') {
        return ar_build_spec($conn, 'staffDuePipelineAnalytics', $label);
    }

    if ($source === 'dmClaimsSummaryCards') {
        ensureArrearsAndBudgetTables($conn);
        $where = ["LOWER(TRIM(COALESCE(l.source_type,''))) NOT LIKE 'suspension%'"];
        if (str_contains($labelKey, 'outstanding') || str_contains($labelKey, 'balance')) {
            $where[] = "COALESCE(l.balance_amount, 0) > 0";
        } elseif (str_contains($labelKey, 'open')) {
            $where[] = "l.status IN ('Pending','Partially Paid')";
        } elseif (str_contains($labelKey, 'pending accountability')) {
            $where[] = "COALESCE(l.accountability_status, '') = 'Pending Accountability'";
        } elseif (str_contains($labelKey, 'paid')) {
            $where[] = "COALESCE(l.paid_amount, 0) > 0";
        }
        return [
            'title' => $label . ' Claims',
            'from' => 'tb_arrears_ledger l LEFT JOIN tb_fileregistry fr ON fr.regNo=l.regNo',
            'pk' => 'l.ledger_id',
            'detail_table' => 'tb_arrears_ledger',
            'detail_pk' => 'ledger_id',
            'date_column' => 'l.recorded_at',
            'where' => $where,
            'search' => ['l.regNo', 'fr.sName', 'fr.fName', 'l.claim_type', 'l.status', 'l.source_type', 'l.reason'],
            'columns' => ['ledger_id' => 'ID', 'regNo' => 'File No.', 'claimant_name' => 'Claimant', 'claim_type' => 'Type', 'status' => 'Status', 'balance_amount' => 'Balance'],
            'select' => "l.ledger_id, l.regNo, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(fr.sName,''),' ',COALESCE(fr.fName,''))),''), l.regNo) AS claimant_name, l.claim_type, l.status, l.expected_amount, l.paid_amount, l.balance_amount, l.accountability_status, l.recorded_at",
            'order' => 'l.recorded_at DESC, l.ledger_id DESC'
        ];
    }

    if ($source === 'dmTasksSummaryCards') {
        ensureTasksTable($conn);
        $where = [];
        if (str_contains($labelKey, 'pending')) {
            $where[] = "t.status = 'pending'";
        } elseif (str_contains($labelKey, 'completed')) {
            $where[] = "t.status = 'completed'";
        } elseif (str_contains($labelKey, 'overdue')) {
            $where[] = "EXISTS (SELECT 1 FROM tb_task_alerts a WHERE a.task_id=t.taskId AND a.alert_type='overdue' AND a.alert_status IN ('open','acknowledged'))";
        } elseif (str_contains($labelKey, 'urgent')) {
            $where[] = "t.priority = 'urgent'";
        }
        return [
            'title' => $label . ' Tasks',
            'from' => 'tb_tasks t',
            'pk' => 't.taskId',
            'detail_table' => 'tb_tasks',
            'detail_pk' => 'taskId',
            'date_column' => 't.timeStamp',
            'where' => $where,
            'search' => ['t.task_title','t.task_description','t.assigned_role','t.status'],
            'columns' => ['taskId'=>'ID','task_title'=>'Task','assigned_role'=>'Role','status'=>'Status','priority'=>'Priority','due_at'=>'Due'],
            'select' => 't.taskId, t.task_title, t.task_description, t.assigned_role, t.assigned_to, t.status, t.priority, t.due_at, t.timeStamp, t.completed_at',
            'order' => 't.timeStamp DESC, t.taskId DESC'
        ];
    }

    if ($source === 'dmMovementsSummaryCards' || $source === 'fileMovementSummaryCards') {
        ensureFileMovementTables($conn);
        $where = [];
        if (str_contains($labelKey, 'open')) {
            $where[] = "m.returned_at IS NULL";
        }
        if (str_contains($labelKey, 'overdue')) {
            $where[] = "m.returned_at IS NULL";
            $where[] = "m.expected_return_at IS NOT NULL AND m.expected_return_at < NOW()";
        }
        if (str_contains($labelKey, 'due soon')) {
            $where[] = "m.returned_at IS NULL";
            $where[] = "m.expected_return_at IS NOT NULL AND m.expected_return_at >= NOW() AND m.expected_return_at < DATE_ADD(NOW(), INTERVAL 24 HOUR)";
        }
        if (str_contains($labelKey, 'moved today')) {
            $where[] = "DATE(m.moved_at) = CURDATE()";
        }
        if (str_contains($labelKey, 'returned')) {
            $where[] = "m.returned_at IS NOT NULL";
        }
        return [
            'title' => $label . ' Movements',
            'from' => 'tb_file_movements m',
            'pk' => 'm.movement_id',
            'detail_table' => 'tb_file_movements',
            'detail_pk' => 'movement_id',
            'date_column' => 'm.moved_at',
            'where' => $where,
            'search' => ['m.regNo','m.from_office','m.to_office','m.reason','m.delivered_by'],
            'columns' => ['movement_id'=>'ID','regNo'=>'File No.','from_office'=>'From','to_office'=>'To','moved_at'=>'Moved','returned_at'=>'Returned'],
            'select' => 'm.movement_id, m.regNo, m.from_office, m.to_office, m.reason, m.delivered_by, m.moved_at, m.expected_return_at, m.returned_at',
            'order' => 'm.moved_at DESC, m.movement_id DESC'
        ];
    }

    if ($source === 'workflowAlertSummaryCards') {
        ensureTaskAlertsTable($conn);
        $where = [];
        if (str_contains($labelKey, 'open')) {
            $where[] = "a.alert_status IN ('open','acknowledged')";
        }
        if (str_contains($labelKey, 'critical')) {
            $where[] = "a.severity = 'critical'";
            $where[] = "a.alert_status IN ('open','acknowledged')";
        }
        if (str_contains($labelKey, 'resolved')) {
            $where[] = "a.alert_status = 'resolved'";
            $where[] = "a.resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        }
        return [
            'title' => $label . ' Alerts',
            'from' => 'tb_task_alerts a LEFT JOIN tb_tasks t ON t.taskId=a.task_id',
            'pk' => 'a.alert_id',
            'detail_table' => 'tb_task_alerts',
            'detail_pk' => 'alert_id',
            'date_column' => 'a.created_at',
            'where' => $where,
            'search' => ['a.alert_type','a.severity','a.alert_status','t.task_title','t.assigned_role'],
            'columns' => ['alert_id'=>'ID','alert_type'=>'Type','severity'=>'Severity','alert_status'=>'Status','task_title'=>'Task','created_at'=>'Created'],
            'select' => 'a.alert_id, a.task_id, a.alert_type, a.severity, a.alert_status, t.task_title, t.assigned_role, a.created_at, a.resolved_at',
            'order' => 'a.created_at DESC, a.alert_id DESC'
        ];
    }

    if ($source === 'recycleBinSummaryCards') {
        ensureRegistryRecycleBinTable($conn);
        $where = [];
        if (str_contains($labelKey, 'deleted')) $where[] = "rb.restored = 0";
        if (str_contains($labelKey, 'restored')) $where[] = "rb.restored = 1";
        if (str_contains($labelKey, 'direct')) $where[] = "(rb.delete_request_id IS NULL OR rb.delete_request_id = 0)";
        if (str_contains($labelKey, 'queued')) $where[] = "rb.delete_request_id IS NOT NULL AND rb.delete_request_id > 0";
        return [
            'title' => $label,
            'from' => 'tb_file_registry_recycle_bin rb',
            'pk' => 'rb.recycle_id',
            'detail_table' => 'tb_file_registry_recycle_bin',
            'detail_pk' => 'recycle_id',
            'date_column' => 'rb.deleted_at',
            'where' => $where,
            'search' => ['rb.regNo','rb.staff_name','rb.delete_reason','rb.deleted_by_name','rb.deleted_by_role'],
            'columns' => ['recycle_id'=>'ID','regNo'=>'File No.','staff_name'=>'Name','recycle_source'=>'Source','deleted_by_name'=>'Deleted By','deleted_at'=>'Deleted'],
            'select' => "rb.recycle_id, rb.regNo, rb.staff_name, CASE WHEN rb.delete_request_id IS NULL OR rb.delete_request_id = 0 THEN 'Direct' ELSE 'Queued' END AS recycle_source, rb.deleted_by_name, rb.deleted_by_role, rb.deleted_at, rb.restored, rb.restored_at",
            'order' => 'rb.deleted_at DESC, rb.recycle_id DESC'
        ];
    }

    if (in_array($source, ['pensionerPopulationAnalytics','pensionerCompositionAnalytics','demographicsGenderAnalytics','demographicsRegionAnalytics','demographicsRetirementAnalytics','retirementModeAnalytics','fileRegistryCompositionAnalytics','fileRegistryPayrollAnalytics'], true)
        || str_contains($labelKey, 'registry') || str_contains($labelKey, 'life certificate') || str_contains($labelKey, 'file population')) {
        $spec = ar_registry_spec($conn, $source, $label);
        if ($source === 'fileRegistryPayrollAnalytics' || str_contains($labelKey, 'payroll pensioner') || str_contains($labelKey, 'off payroll') || str_contains($labelKey, 'on payroll')) {
            $cycle = ar_latest_payroll_cycle($conn);
            $status = str_contains($labelKey, 'off payroll') || str_contains($labelKey, 'not on payroll') ? 'Not on Payroll' : 'On Payroll';
            $spec['from'] .= " INNER JOIN tb_registry_payroll_monthly_status pms ON pms.regNo=fr.regNo AND pms.payroll_year={$cycle['year']} AND pms.payroll_month={$cycle['month']}";
            $spec['where'][] = "pms.payroll_status=" . ar_eq($conn, $status);
            if ($source === 'fileRegistryPayrollAnalytics') {
                $spec['where'][] = "LOWER(TRIM(COALESCE(fr.payType, ''))) = 'pensioner'";
            }
            $spec['select'] .= ", pms.payroll_status, pms.payroll_year, pms.payroll_month";
            $spec['columns']['payroll_status'] = 'Payroll';
            $spec['title'] = $label . ' Records';
        }
        return $spec;
    }

    if ($source === 'claimsTypeAnalytics' || $source === 'claimsQuarterAnalytics' || str_contains($labelKey, 'claim')) {
        ensureArrearsAndBudgetTables($conn);
        $where = ["LOWER(TRIM(COALESCE(l.source_type,''))) NOT LIKE 'suspension%'"];
        if ($source === 'claimsTypeAnalytics' && !in_array($labelKey, ['claims', 'claim', 'claims balance', 'claims ledger entries'], true)) {
            $where[] = "COALESCE(NULLIF(TRIM(l.claim_type),''), NULLIF(TRIM(l.source_type),''), 'Unspecified')=" . ar_eq($conn, $label);
        }
        if (str_contains($labelKey, 'balance') || str_contains($labelKey, 'outstanding')) {
            $where[] = "COALESCE(l.balance_amount, 0) > 0";
        }
        if (preg_match('/FY\s*([0-9]{4}\/[0-9]{2,4}|[0-9]{4})\s*(Q[1-4])?/i', $label, $m)) {
            $where[] = "l.financial_year_label=" . ar_eq($conn, $m[1]);
            if (!empty($m[2])) $where[] = "l.quarter_label=" . ar_eq($conn, strtoupper($m[2]));
        }
        if (str_contains($labelKey, 'open')) {
            $where[] = "l.status IN ('Pending','Partially Paid')";
        }
        return [
            'title' => $label . ' Claims',
            'from' => 'tb_arrears_ledger l LEFT JOIN tb_fileregistry fr ON fr.regNo=l.regNo',
            'pk' => 'l.ledger_id',
            'detail_table' => 'tb_arrears_ledger',
            'detail_pk' => 'ledger_id',
            'date_column' => 'l.recorded_at',
            'where' => $where,
            'search' => ['l.regNo', 'fr.sName', 'fr.fName', 'l.claim_type', 'l.status', 'l.source_type', 'l.reason'],
            'columns' => ['ledger_id' => 'ID', 'regNo' => 'File No.', 'claimant_name' => 'Claimant', 'claim_type' => 'Type', 'status' => 'Status', 'balance_amount' => 'Balance'],
            'select' => "l.ledger_id, l.regNo, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(fr.sName,''),' ',COALESCE(fr.fName,''))),''), l.regNo) AS claimant_name, l.claim_type, l.status, l.expected_amount, l.paid_amount, l.balance_amount, l.financial_year_label, l.quarter_label, l.recorded_at",
            'order' => 'l.recorded_at DESC, l.ledger_id DESC'
        ];
    }

    if ($source === 'staffDueTypeAnalytics' || $source === 'staffDuePipelineAnalytics' || str_contains($labelKey, 'staff due') || str_contains($labelKey, 'verification')) {
        ensureStaffDueWorkflowColumns($conn);
        ensureStaffDueSoftDeleteColumns($conn);
        $staffDueExpr = ar_staff_due_expressions($conn);
        $where = ["COALESCE(sd.is_deleted,0)=0"];
        if ($source === 'staffDueTypeAnalytics') {
            $where[] = "(sd.retirementType=" . ar_eq($conn, $label) . " OR sd.retirementType=" . ar_eq($conn, function_exists('normalizeBenefitsRetirementTypeKey') ? normalizeBenefitsRetirementTypeKey($label) : $label) . ")";
        } elseif (str_contains($labelKey, 'staff due for retirement') || $labelKey === 'staff due') {
            // Total active staff-due population.
        } elseif (str_contains($labelKey, 'pending submission')) {
            $where[] = "LOWER(TRIM(COALESCE(sd.submissionStatus,''))) <> 'submitted'";
        } elseif ($labelKey === 'submitted' || str_contains($labelKey, 'applications submitted')) {
            $where[] = "LOWER(TRIM(COALESCE(sd.submissionStatus,''))) = 'submitted'";
        } elseif (str_contains($labelKey, 'applications not submitted')) {
            $where[] = "LOWER(TRIM(COALESCE(sd.submissionStatus,''))) <> 'submitted'";
        } elseif (str_contains($labelKey, 'verification started')) {
            $where[] = "({$staffDueExpr['workflow_initiation']}) = 'initiated'";
        } elseif (str_contains($labelKey, 'awaiting verification')) {
            $where[] = "({$staffDueExpr['workflow_initiation']}) = 'pending'";
        } elseif (str_contains($labelKey, 'due soon')) {
            $where[] = "({$staffDueExpr['workflow_initiation']}) = 'due_soon'";
        } elseif (str_contains($labelKey, 'escalated') || str_contains($labelKey, 'escalation')) {
            $where[] = "({$staffDueExpr['workflow_initiation']}) = 'escalated'";
        } elseif (str_contains($labelKey, 'in process')) {
            $where[] = "({$staffDueExpr['workflow_state']}) = 'in_process'";
        } elseif (str_contains($labelKey, 'completed')) {
            $where[] = "({$staffDueExpr['workflow_state']}) = 'completed'";
        }
        return [
            'title' => $label . ' Staff Due Records',
            'from' => 'tb_staffdue sd LEFT JOIN tb_application_queue q ON q.staffdue_id = sd.id',
            'pk' => 'sd.id',
            'detail_table' => 'tb_staffdue',
            'detail_pk' => 'id',
            'date_column' => 'sd.timeStamp',
            'where' => $where,
            'search' => ['sd.regNo', 'sd.sName', 'sd.fName', 'sd.title', 'sd.prisonUnit', 'sd.retirementType', 'sd.submissionStatus', 'sd.appnStatus'],
            'columns' => ['id' => 'ID', 'regNo' => 'File No.', 'staff_name' => 'Name', 'retirementType' => 'Retirement', 'submissionStatus' => 'Submission', 'appnStatus' => 'Application'],
            'select' => "sd.id, sd.regNo, TRIM(CONCAT(COALESCE(sd.sName,''),' ',COALESCE(sd.fName,''))) AS staff_name, sd.title, sd.prisonUnit, sd.retirementType, sd.submissionStatus, sd.appnStatus, sd.gender, sd.submission_at",
            'order' => 'sd.timeStamp DESC, sd.id DESC'
        ];
    }

    if ($source === 'lifeCertAnalyticsBars') {
        $cleanLabel = ar_label_without_year($label);
        $cleanLabelKey = ar_key($cleanLabel);
        $spec = ar_registry_spec($conn, $source, $cleanLabel);
        $year = ar_label_year($label, (int)($_GET['year'] ?? date('Y')));
        if ($cleanLabelKey === 'submitted') {
            $spec['from'] .= " INNER JOIN tb_life_certificate_submissions lcs ON lcs.regNo=fr.regNo AND lcs.submission_year={$year}";
            $spec['select'] .= ", lcs.submitted_at AS life_certificate_submitted_at";
            $spec['columns']['life_certificate_submitted_at'] = 'Submitted';
        } elseif ($cleanLabelKey === 'not submitted') {
            $spec['where'][] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $spec['where'][] = "NOT EXISTS (SELECT 1 FROM tb_life_certificate_submissions lcs WHERE lcs.regNo=fr.regNo AND lcs.submission_year={$year})";
        } elseif ($cleanLabelKey === 'exempt') {
            $spec['where'][] = "(LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased' OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly'))";
        } elseif ($cleanLabelKey === 'expected') {
            $spec['where'][] = "LOWER(TRIM(COALESCE(fr.livingStatus,'')))='alive'";
            $spec['where'][] = "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) NOT IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')";
        }
        return $spec;
    }

    if ($source === 'payrollCoverageAnalytics') {
        $cycle = ar_label_payroll_period($conn, $label);
        $hasStatusFilter = !str_contains($labelKey, 'total beneficiar');
        $status = str_contains($labelKey, 'not') || str_contains($labelKey, 'off') ? 'Not on Payroll' : 'On Payroll';
        $where = ["pms.payroll_year={$cycle['year']}", "pms.payroll_month={$cycle['month']}", "(pms.cycle_id IS NULL OR COALESCE(pc.is_deleted, 0) = 0)", "COALESCE(fr.is_deleted,0)=0"];
        if ($hasStatusFilter) {
            $where[] = "pms.payroll_status=" . ar_eq($conn, $status);
        }
        return [
            'title' => $label . ' Beneficiaries',
            'from' => "tb_registry_payroll_monthly_status pms LEFT JOIN tb_payroll_upload_cycles pc ON pc.cycle_id=pms.cycle_id INNER JOIN tb_fileregistry fr ON fr.regNo=pms.regNo",
            'pk' => 'fr.regNo',
            'detail_table' => 'tb_fileregistry',
            'detail_pk' => 'regNo',
            'date_column' => 'fr.timeStamp',
            'where' => $where,
            'search' => ['fr.regNo','fr.sName','fr.fName','fr.title','fr.computerNo','pms.payroll_status'],
            'columns' => ['regNo'=>'File No.','registry_name'=>'Name','title'=>'Title','computerNo'=>'Computer No.','payroll_status'=>'Payroll','amount'=>'Amount'],
            'select' => "fr.regNo, TRIM(CONCAT(COALESCE(fr.sName,''),' ',COALESCE(fr.fName,''))) AS registry_name, fr.title, fr.computerNo, fr.payType, fr.livingStatus, pms.payroll_status, pms.amount",
            'order' => 'fr.sName ASC, fr.fName ASC'
        ];
    }

    if ($source === 'fileMovementOfficeAnalytics' || str_contains($labelKey, 'open file movement')) {
        ensureFileMovementTables($conn);
        $where = [];
        if ($source === 'fileMovementOfficeAnalytics') {
            $where[] = "COALESCE(NULLIF(TRIM(m.to_office),''),'Unspecified')=" . ar_eq($conn, $label);
            $where[] = "m.returned_at IS NULL";
        } else {
            $where[] = "m.returned_at IS NULL";
        }
        return [
            'title' => $label . ' Movements',
            'from' => 'tb_file_movements m',
            'pk' => 'm.movement_id',
            'detail_table' => 'tb_file_movements',
            'detail_pk' => 'movement_id',
            'date_column' => 'm.moved_at',
            'where' => $where,
            'search' => ['m.regNo','m.from_office','m.to_office','m.reason','m.delivered_by'],
            'columns' => ['movement_id'=>'ID','regNo'=>'File No.','from_office'=>'From','to_office'=>'To','moved_at'=>'Moved','expected_return_at'=>'Expected Return'],
            'select' => 'm.movement_id, m.regNo, m.from_office, m.to_office, m.reason, m.delivered_by, m.moved_at, m.expected_return_at, m.returned_at',
            'order' => 'm.moved_at DESC, m.movement_id DESC'
        ];
    }

    if ($source === 'userRoleAnalytics' || str_contains($labelKey, 'user account')) {
        ensureUserActiveColumn($conn);
        $where = [];
        if ($source === 'userRoleAnalytics') {
            $where[] = "(LOWER(TRIM(COALESCE(u.userRole,'')))=" . ar_eq($conn, strtolower(str_replace(' ', '_', $label))) . " OR LOWER(TRIM(COALESCE(r.role_label,'')))=" . ar_eq($conn, $labelKey) . ")";
        }
        return [
            'title' => $label . ' Accounts',
            'from' => 'tb_users u LEFT JOIN tb_roles r ON r.role_key=u.userRole',
            'pk' => 'u.Id',
            'detail_table' => 'tb_users',
            'detail_pk' => 'Id',
            'date_column' => 'u.timeStamp',
            'where' => $where,
            'search' => ['u.userName','u.userEmail','u.phoneNo','u.userRole'],
            'columns' => ['userName'=>'Name','userRole'=>'Role','userEmail'=>'Email','phoneNo'=>'Phone','is_active'=>'Active'],
            'select' => 'u.Id, u.userId, u.userName, u.userRole, u.userEmail, u.phoneNo, u.is_active, u.timeStamp',
            'order' => 'u.timeStamp DESC, u.Id DESC'
        ];
    }

    if ($source === 'delegatedTaskAnalytics') {
        ensureTasksTable($conn);
        if (function_exists('ensureTaskDelegationLogsTable')) {
            ensureTaskDelegationLogsTable($conn);
        }
        if (function_exists('ensureWorkflowLogsTable')) {
            ensureWorkflowLogsTable($conn);
        }
        $where = [];
        $stateSql = "
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM tb_workflow_logs w
                    WHERE w.task_id = d.task_id
                      AND w.action = 'task_delegation_declined'
                      AND w.created_at >= d.created_at
                ) THEN 'Declined'
                WHEN t.assigned_to = d.to_user_id AND t.assigned_at IS NOT NULL AND t.assigned_at >= d.created_at THEN 'Accepted'
                WHEN t.metadata LIKE '%\"pending_delegation\"%' THEN 'Pending'
                ELSE 'Requested'
            END
        ";
        if (str_contains($labelKey, 'pending')) {
            $where[] = "t.metadata LIKE '%\"pending_delegation\"%'";
        } elseif (str_contains($labelKey, 'accepted')) {
            $where[] = "t.assigned_to = d.to_user_id AND t.assigned_at IS NOT NULL AND t.assigned_at >= d.created_at";
        } elseif (str_contains($labelKey, 'declined')) {
            $where[] = "EXISTS (SELECT 1 FROM tb_workflow_logs w WHERE w.task_id = d.task_id AND w.action = 'task_delegation_declined' AND w.created_at >= d.created_at)";
        }
        return [
            'title' => $label . ' Delegations',
            'from' => 'tb_task_delegation_logs d LEFT JOIN tb_tasks t ON t.taskId=d.task_id',
            'pk' => 'd.log_id',
            'detail_table' => 'tb_task_delegation_logs',
            'detail_pk' => 'log_id',
            'date_column' => 'd.created_at',
            'where' => $where,
            'search' => ['t.task_title','d.from_user_name','d.to_user_name','d.to_user_role','d.priority','d.note'],
            'columns' => [
                'task_title' => 'Task',
                'from_user_name' => 'Delegated By',
                'to_user_name' => 'Delegated To',
                'to_user_role' => 'Role',
                'delegation_state' => 'State',
                'priority' => 'Priority',
                'created_at' => 'Requested'
            ],
            'select' => "
                d.log_id,
                d.task_id,
                COALESCE(t.task_title, CONCAT('Task ', d.task_id)) AS task_title,
                d.from_user_name,
                d.from_user_role,
                d.to_user_name,
                d.to_user_role,
                {$stateSql} AS delegation_state,
                d.priority,
                d.note,
                d.created_at
            ",
            'export_computed' => [
                'task_title' => "COALESCE(t.task_title, CONCAT('Task ', d.task_id))",
                'delegation_state' => $stateSql
            ],
            'order' => 'd.created_at DESC, d.log_id DESC'
        ];
    }

    if ($source === 'workflowProcessAnalytics') {
        ensureAppnStatusTrackingColumns($conn);
        $where = [
            "a.verification_at IS NOT NULL",
            "a.approval_at IS NOT NULL",
            "LOWER(TRIM(COALESCE(a.approval, ''))) IN ('approved','completed','done')"
        ];
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $label, $dateMatch) === 1) {
            $where[] = "DATE(a.approval_at)=" . ar_eq($conn, $dateMatch[1]);
        }
        $durationExpr = "GREATEST(0, TIMESTAMPDIFF(MINUTE, a.verification_at, a.approval_at))";
        $approvalStepExpr = "CASE WHEN a.audit_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.audit_at, a.approval_at)) WHEN a.assessment_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.assessment_at, a.approval_at)) ELSE NULL END";
        $processingDurationSql = "
            CASE
                WHEN {$durationExpr} < 1440 THEN CONCAT(
                    FLOOR({$durationExpr} / 60), ' hours ',
                    MOD({$durationExpr}, 60), ' minutes'
                )
                ELSE CONCAT(
                    FLOOR(FLOOR({$durationExpr} / 1440) / 30), ' months ',
                    FLOOR(MOD(FLOOR({$durationExpr} / 1440), 30) / 7), ' weeks ',
                    MOD(FLOOR({$durationExpr} / 1440), 7), ' days'
                )
            END
        ";
        $stepDurationSql = "
            CASE
                WHEN {$approvalStepExpr} IS NULL THEN 'N/A'
                WHEN {$approvalStepExpr} < 1440 THEN CONCAT(
                    FLOOR({$approvalStepExpr} / 60), ' hours ',
                    MOD({$approvalStepExpr}, 60), ' minutes'
                )
                ELSE CONCAT(
                    FLOOR(FLOOR({$approvalStepExpr} / 1440) / 30), ' months ',
                    FLOOR(MOD(FLOOR({$approvalStepExpr} / 1440), 30) / 7), ' weeks ',
                    MOD(FLOOR({$approvalStepExpr} / 1440), 7), ' days'
                )
            END
        ";
        return [
            'title' => $label . ' Processing Records',
            'from' => 'tb_appnstatus a INNER JOIN tb_staffdue sd ON sd.regNo=a.regNo',
            'pk' => 'a.id',
            'detail_table' => 'tb_appnstatus',
            'detail_pk' => 'id',
            'date_column' => 'a.approval_at',
            'where' => $where,
            'search' => ['a.regNo','sd.sName','sd.fName','sd.title','sd.prisonUnit','sd.retirementType','a.verification','a.approval'],
            'columns' => [
                'regNo' => 'File No.',
                'staff_name' => 'Name',
                'retirementType' => 'Retirement',
                'prisonUnit' => 'Station',
                'verification_at' => 'Verified',
                'approval_at' => 'Approved',
                'processing_duration' => 'Verification-Approval',
                'step_duration' => 'Final Step Duration'
            ],
            'select' => "
                a.id,
                a.regNo,
                TRIM(CONCAT(COALESCE(sd.sName,''),' ',COALESCE(sd.fName,''))) AS staff_name,
                sd.retirementType,
                sd.prisonUnit,
                a.verification,
                a.writeUp,
                a.fileCreation,
                a.dataCapture,
                a.assessment,
                a.audit,
                a.approval,
                a.verification_at,
                a.writeUp_at,
                a.fileCreation_at,
                a.dataCapture_at,
                a.assessment_at,
                a.audit_at,
                a.approval_at,
                {$processingDurationSql} AS processing_duration,
                {$stepDurationSql} AS step_duration
            ",
            'export_computed' => [
                'processing_duration' => $processingDurationSql,
                'step_duration' => $stepDurationSql,
                'staff_name' => "TRIM(CONCAT(COALESCE(sd.sName,''),' ',COALESCE(sd.fName,'')))"
            ],
            'order' => 'a.approval_at DESC, a.id DESC'
        ];
    }

    if ($source === 'workflowResponseAnalytics' || str_contains($labelKey, 'workflow')) {
        ensureTasksTable($conn);
        $hasAssignedAt = ar_column_exists($conn, 'tb_tasks', 'assigned_at');
        $responseStartExpr = $hasAssignedAt ? 'COALESCE(t.assigned_at, t.timeStamp)' : 't.timeStamp';
        $assignedAtSelect = $hasAssignedAt ? 't.assigned_at,' : '';
        $where = [];
        $staffPerformanceName = '';
        if (preg_match('/^staff performance:\s*(.+)$/i', $label, $staffMatches) === 1) {
            $staffPerformanceName = ar_clean((string)$staffMatches[1]);
        }
        if ($staffPerformanceName !== '') {
            $where[] = "(u.userName=" . ar_eq($conn, $staffPerformanceName) . " OR t.assigned_to=" . ar_eq($conn, $staffPerformanceName) . ")";
        }
        if (str_contains($labelKey, 'overdue')) {
            $where[] = "EXISTS (SELECT 1 FROM tb_task_alerts a WHERE a.task_id=t.taskId AND a.alert_type='overdue' AND a.alert_status IN ('open','acknowledged'))";
        } elseif (str_contains($labelKey, 'completed')) {
            $where[] = "t.status='completed'";
            if (str_contains($labelKey, '7d') || str_contains($labelKey, '7-day') || str_contains($labelKey, 'throughput')) {
                $where[] = "t.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            }
        } elseif ($staffPerformanceName !== '') {
            // Staff performance bars represent all tasks assigned to the selected staff member.
        } else {
            $where[] = "t.status IN ('pending','assigned','in_progress','deferred','returned')";
        }
        $responsePeriodSql = "
            CASE
                WHEN t.completed_at IS NOT NULL THEN CONCAT(
                    FLOOR(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, t.completed_at)) / 1440), 'd ',
                    FLOOR(MOD(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, t.completed_at)), 1440) / 60), 'h ',
                    MOD(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, t.completed_at)), 60), 'm'
                )
                WHEN {$responseStartExpr} IS NOT NULL THEN CONCAT(
                    'Open for ',
                    FLOOR(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, NOW())) / 1440), 'd ',
                    FLOOR(MOD(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, NOW())), 1440) / 60), 'h ',
                    MOD(GREATEST(0, TIMESTAMPDIFF(MINUTE, {$responseStartExpr}, NOW())), 60), 'm'
                )
                ELSE 'Not started'
            END
        ";
        return [
            'title' => $label . ' Tasks',
            'from' => 'tb_tasks t LEFT JOIN tb_users u ON u.userId=t.assigned_to',
            'pk' => 't.taskId',
            'detail_table' => 'tb_tasks',
            'detail_pk' => 'taskId',
            'date_column' => 't.timeStamp',
            'where' => $where,
            'search' => ['t.task_title','t.task_description','t.assigned_role','t.status','u.userName'],
            'columns' => [
                'task_title'=>'Task',
                'assigned_to_name'=>'Assigned To',
                'assigned_role'=>'Role',
                'status'=>'Status',
                'priority'=>'Priority',
                'due_at'=>'Due',
                'response_period'=>'Response Period'
            ],
            'select' => "
                t.taskId,
                t.task_title,
                t.task_description,
                t.assigned_role,
                t.assigned_to,
                u.userName AS assigned_to_name,
                t.status,
                t.priority,
                t.due_at,
                t.timeStamp,
                {$assignedAtSelect}
                t.completed_at,
                {$responsePeriodSql} AS response_period
            ",
            'export_computed' => [
                'response_period' => $responsePeriodSql
            ],
            'order' => 't.timeStamp DESC, t.taskId DESC'
        ];
    }

    if (str_starts_with($source, 'feedback')) {
        ensureFeedbackWorkflowTables($conn);
        if (!currentUserHasPermission($conn, 'feedback.view')) {
            throw new RuntimeException('Access denied');
        }
        $where = [];
        if ($source === 'feedbackTypeAnalytics') {
            $where[] = "REPLACE(LOWER(TRIM(f.feedback_type)),'_',' ')=" . ar_eq($conn, $labelKey);
        } elseif ($source === 'feedbackAudienceAnalytics') {
            $where[] = "LOWER(TRIM(f.audience))=" . ar_eq($conn, $labelKey);
        } elseif ($source === 'feedbackTrendAnalytics' && preg_match('/week\s+of\s+(\d{4}-\d{2}-\d{2})/i', $label, $trendMatch) === 1) {
            $bucketStart = $trendMatch[1];
            $where[] = "DATE(f.submitted_at) >= " . ar_eq($conn, $bucketStart);
            $where[] = "DATE(f.submitted_at) <= DATE_ADD(" . ar_eq($conn, $bucketStart) . ", INTERVAL 6 DAY)";
        }
        if ($source === 'feedbackSummaryCards') {
            if (str_contains($labelKey, 'open')) {
                $where[] = "f.status IN ('new','reviewed')";
            } elseif (str_contains($labelKey, 'new')) {
                $where[] = "f.status = 'new'";
            } elseif (str_contains($labelKey, 'assigned')) {
                $where[] = "f.status IN ('new','reviewed')";
                $where[] = "TRIM(COALESCE(f.assigned_to_user_id,'')) <> ''";
            } elseif (str_contains($labelKey, 'overdue')) {
                $slaDays = max(1, getAppSettingInt($conn, 'feedback_response_sla_days', 5));
                $where[] = "f.status IN ('new','reviewed')";
                $where[] = "f.submitted_at < DATE_SUB(NOW(), INTERVAL {$slaDays} DAY)";
            } elseif (str_contains($labelKey, 'completed')) {
                $where[] = "f.status IN ('resolved','closed')";
            } elseif (str_contains($labelKey, 'resolved')) {
                $where[] = "f.status = 'resolved'";
            } elseif (str_contains($labelKey, 'closed')) {
                $where[] = "f.status = 'closed'";
            }
        }
        return [
            'title' => $label . ' Feedback',
            'from' => 'tb_feedback_submissions f',
            'pk' => 'f.submission_id',
            'detail_table' => 'tb_feedback_submissions',
            'detail_pk' => 'submission_id',
            'date_column' => 'f.submitted_at',
            'where' => $where,
            'search' => ['f.reference_no','f.full_name','f.subject','f.status','f.priority','f.assigned_to_name'],
            'columns' => ['submission_id'=>'ID','reference_no'=>'Reference','full_name'=>'Sender','subject'=>'Subject','status'=>'Status','priority'=>'Priority','submitted_at'=>'Submitted'],
            'select' => 'f.submission_id, f.reference_no, f.feedback_type, f.audience, f.full_name, f.subject, f.status, f.priority, f.assigned_to_name, f.submitted_at',
            'order' => 'f.submitted_at DESC, f.submission_id DESC'
        ];
    }

    return ar_registry_spec($conn, $source, $label);
}

try {
    $body = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    $input = array_merge($_GET, $body);
    $source = ar_clean((string)($input['source'] ?? ''));
    $label = ar_clean((string)($input['label'] ?? 'Records'));
    $action = strtolower(ar_clean((string)($input['action'] ?? 'list')));
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(5, min(500, (int)($input['limit'] ?? 12)));
    $search = ar_clean((string)($input['search'] ?? ''));
    $dateFrom = ar_clean((string)($input['date_from'] ?? ''));
    $dateTo = ar_clean((string)($input['date_to'] ?? ''));
    $recordId = ar_clean((string)($input['record_id'] ?? ''));
    $fastList = !empty($input['fast']);

    $spec = ar_build_spec($conn, $source, $label);

    if ($action === 'detail') {
        if ($recordId === '') {
            throw new RuntimeException('Record identifier is required.');
        }
        $detailTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$spec['detail_table']);
        $detailPk = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$spec['detail_pk']);
        $sql = "SELECT * FROM `{$detailTable}` WHERE `{$detailPk}`=" . ar_eq($conn, $recordId) . " LIMIT 1";
        $result = $conn->query($sql);
        $row = $result ? ($result->fetch_assoc() ?: null) : null;
        if (is_array($row)) {
            ar_attach_user_labels($conn, $row);
            ar_attach_document_preview($conn, $row, $detailTable);
        }
        echo json_encode(['success' => true, 'record' => $row, 'title' => $spec['title']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $where = $spec['where'];
    $where = array_merge($where, ar_date_filter((string)$spec['date_column'], $dateFrom, $dateTo, $conn));
    if ($search !== '' && !empty($spec['search'])) {
        $likeParts = [];
        foreach ($spec['search'] as $column) {
            $likeParts[] = "{$column} LIKE " . ar_like($conn, $search);
        }
        $where[] = '(' . implode(' OR ', $likeParts) . ')';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    if ($action === 'columns') {
        $availableColumns = ar_frontend_columns(ar_table_columns($conn, (string)$spec['detail_table']));
        if (!empty($spec['export_computed']) && is_array($spec['export_computed'])) {
            foreach (array_keys($spec['export_computed']) as $computedColumn) {
                if (!ar_frontend_should_hide_field((string)$computedColumn)) {
                    $availableColumns[(string)$computedColumn] = ar_computed_column_label((string)$computedColumn);
                }
            }
        }
        echo json_encode([
            'success' => true,
            'title' => $spec['title'],
            'columns' => $availableColumns
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'count') {
        $countSql = "SELECT COUNT(*) AS total_rows FROM {$spec['from']} {$whereSql}";
        $countRes = $conn->query($countSql);
        $totalRows = $countRes ? (int)(($countRes->fetch_assoc()['total_rows'] ?? 0)) : 0;
        echo json_encode([
            'success' => true,
            'title' => $spec['title'],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalRows' => $totalRows,
                'totalPages' => max(1, (int)ceil($totalRows / $limit)),
                'exact' => true
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'pdf' || $action === 'rows') {
        $availableColumns = ar_frontend_columns(ar_table_columns($conn, (string)$spec['detail_table']));
        $computedColumns = !empty($spec['export_computed']) && is_array($spec['export_computed']) ? $spec['export_computed'] : [];
        foreach (array_keys($computedColumns) as $computedColumn) {
            if (!ar_frontend_should_hide_field((string)$computedColumn)) {
                $availableColumns[(string)$computedColumn] = ar_computed_column_label((string)$computedColumn);
            }
        }
        $selected = array_values(array_filter(array_map('strval', (array)($input['columns'] ?? []))));
        $selected = array_values(array_intersect($selected, array_keys($availableColumns)));
        if ($selected === []) {
            $selected = array_slice(array_keys($availableColumns), 0, 8);
        }
        $detailAlias = ar_detail_alias((string)$spec['detail_table']);
        if ($detailAlias === '') {
            throw new RuntimeException('PDF export is not available for this analytics bucket.');
        }
        $selectParts = array_map(static function (string $column) use ($detailAlias, $computedColumns): string {
            $safe = str_replace('`', '', $column);
            if (isset($computedColumns[$safe])) {
                return "{$computedColumns[$safe]} AS `{$safe}`";
            }
            return "{$detailAlias}.`{$safe}` AS `{$safe}`";
        }, $selected);
        $exportLimit = max(1, min(2000, (int)($input['export_limit'] ?? 2000)));
        $exportSql = "SELECT " . implode(', ', $selectParts) . " FROM {$spec['from']} {$whereSql} ORDER BY {$spec['order']} LIMIT {$exportLimit}";
        $exportRows = [];
        if ($exportRes = $conn->query($exportSql)) {
            while ($row = $exportRes->fetch_assoc()) {
                $exportRows[] = $row;
            }
            $exportRes->free();
        } else {
            throw new RuntimeException('Unable to prepare analytics export rows.');
        }
        $exportRows = ar_frontend_rows($conn, $exportRows, array_intersect_key($availableColumns, array_flip($selected)));

        if ($action === 'rows') {
            echo json_encode([
                'success' => true,
                'title' => $spec['title'],
                'columns' => array_intersect_key($availableColumns, array_flip($selected)),
                'rows' => $exportRows,
                'totalRows' => count($exportRows)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        require_once __DIR__ . '/../lib/pdf_library.php';
        $columnWidths = ar_pdf_column_widths($selected, $availableColumns, $exportRows);

        $blocks = [
            ['style' => 'title', 'text' => (string)$spec['title']],
            ['style' => 'meta', 'text' => 'Generated: ' . date('d M Y H:i') . ' | Rows: ' . count($exportRows) . ' | Source: ' . $source],
            [
                'style' => 'grid_header',
                'cells' => array_map(static fn($column) => $availableColumns[$column] ?? ar_human_label($column), $selected),
                'widths' => $columnWidths,
                'aligns' => array_fill(0, count($selected), 'left')
            ]
        ];
        foreach ($exportRows as $row) {
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => array_map(static fn($column) => (string)($row[$column] ?? ''), $selected),
                'widths' => $columnWidths,
                'aligns' => array_fill(0, count($selected), 'left')
            ];
        }
        $fileName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower((string)$spec['title'])) ?: 'analytics_export';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fileName . '.pdf"');
        echo pgoRenderBlocksPdf($blocks, 'auto', [
            'title' => (string)$spec['title'],
            'footer' => 'UPS PensionsGo Analytics Export'
        ]);
        exit;
    }

    $offset = ($page - 1) * $limit;
    $rows = [];
    $fetchLimit = $fastList ? ($limit + 1) : $limit;
    $listSql = "SELECT {$spec['pk']} AS __record_id, {$spec['select']} FROM {$spec['from']} {$whereSql} ORDER BY {$spec['order']} LIMIT {$fetchLimit} OFFSET {$offset}";
    $res = $conn->query($listSql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    } else {
        throw new RuntimeException('Unable to load analytics records.');
    }
    $publicColumns = ar_frontend_columns((array)$spec['columns']);
    $rows = ar_frontend_rows($conn, $rows, $publicColumns);
    $hasMore = false;
    if ($fastList && count($rows) > $limit) {
        $hasMore = true;
        $rows = array_slice($rows, 0, $limit);
    }
    if ($fastList) {
        $visibleCount = count($rows);
        $totalRows = $offset + $visibleCount + ($hasMore ? 1 : 0);
        $totalPages = $hasMore ? ($page + 1) : max(1, $page);
        $exactTotal = false;
    } else {
        $countSql = "SELECT COUNT(*) AS total_rows FROM {$spec['from']} {$whereSql}";
        $countRes = $conn->query($countSql);
        $totalRows = $countRes ? (int)(($countRes->fetch_assoc()['total_rows'] ?? 0)) : 0;
        $totalPages = max(1, (int)ceil($totalRows / max(1, $limit)));
        $exactTotal = true;
        $hasMore = $page < $totalPages;
    }

    echo json_encode([
        'success' => true,
        'title' => $spec['title'],
        'source' => $source,
        'label' => $label,
        'columns' => $publicColumns,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,
            'hasMore' => $hasMore,
            'exact' => $exactTotal
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('get_analytics_records error: ' . $e->getMessage());
    http_response_code(stripos($e->getMessage(), 'access denied') !== false ? 403 : 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Unable to load analytics records.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
