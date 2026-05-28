<?php
/**
 * export_workflow_performance.php
 * Export workflow performance analytics to XLSX or PDF.
 */

// Guard against stray output (e.g., included files with trailing whitespace)
// that would corrupt binary responses like XLSX/PDF.
$__baseBufferLevel = ob_get_level();
ob_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/pdf_library.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    echo 'Authentication required';
    exit;
}

$userRole = (string)($_SESSION['userRole'] ?? '');
$normalizedRole = normalizeWorkflowRoleKey($userRole);
if (!in_array($normalizedRole, ['admin', 'oc_pen'], true)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

ensureTasksTable($conn);
ensureTaskAlertsTable($conn);
syncTaskAlerts($conn);

function escCell($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatAlertTypeExport($value): string {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'due_soon') return 'Due Soon';
    if ($normalized === 'overdue') return 'Overdue';
    if ($normalized === 'stalled') return 'Stalled';
    return $normalized === '' ? 'Alert' : ucwords(str_replace('_', ' ', $normalized));
}

function formatRoleLabelExport(mysqli $conn, string $roleKey): string {
    $normalized = normalizeWorkflowRoleKey($roleKey);
    if ($normalized === '') {
        return 'Unassigned';
    }
    return getRoleLabel($conn, $normalized);
}

function formatMinutesCompactExport($minutesValue): string {
    $minutes = (float)$minutesValue;
    if (!is_finite($minutes) || $minutes <= 0) {
        return '0m';
    }
    if ($minutes >= 60) {
        $hours = floor($minutes / 60);
        $rem = (int)round($minutes % 60);
        return $hours . 'h ' . $rem . 'm';
    }
    return (string)((int)round($minutes)) . 'm';
}

function fetchWorkflowAlertExportDataset(mysqli $conn): array {
    $summary = [
        'total_open' => 0,
        'overdue_open' => 0,
        'completed_7d' => 0,
        'avg_completion_hours' => 0.0
    ];

    $summaryStmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN status IN ('pending','assigned','in_progress','deferred','returned') THEN 1 ELSE 0 END) AS total_open,
            SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS completed_7d,
            AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, timeStamp, completed_at) / 60 END) AS avg_completion_hours
        FROM tb_tasks
    ");
    if ($summaryStmt) {
        $summaryStmt->execute();
        $result = $summaryStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $summary['total_open'] = (int)($row['total_open'] ?? 0);
            $summary['completed_7d'] = (int)($row['completed_7d'] ?? 0);
            $summary['avg_completion_hours'] = round((float)($row['avg_completion_hours'] ?? 0), 2);
        }
        $summaryStmt->close();
    }

    $overdueSummaryResult = $conn->query("
        SELECT COUNT(*) AS overdue_total
        FROM tb_task_alerts
        WHERE alert_type = 'overdue'
          AND alert_status IN ('open', 'acknowledged')
    ");
    if ($overdueSummaryResult && ($overdueRow = $overdueSummaryResult->fetch_assoc())) {
        $summary['overdue_open'] = (int)($overdueRow['overdue_total'] ?? 0);
    }

    $roles = [];
    $roleStmt = $conn->query("
        SELECT DISTINCT LOWER(TRIM(COALESCE(role_key, ''))) AS role_key
        FROM tb_roles
        WHERE TRIM(COALESCE(role_key, '')) <> ''
          AND LOWER(TRIM(COALESCE(role_key, ''))) NOT IN ('admin', 'pensioner')
          AND is_active = 1
        ORDER BY role_key ASC
    ");
    if ($roleStmt) {
        while ($row = $roleStmt->fetch_assoc()) {
            $key = strtolower(trim((string)($row['role_key'] ?? '')));
            if ($key !== '') {
                $roles[$key] = true;
            }
        }
    }
    $taskRolesStmt = $conn->query("
        SELECT DISTINCT LOWER(TRIM(COALESCE(assigned_role, ''))) AS role_key
        FROM tb_tasks
        WHERE TRIM(COALESCE(assigned_role, '')) <> ''
          AND LOWER(TRIM(COALESCE(assigned_role, ''))) NOT IN ('admin', 'pensioner')
    ");
    if ($taskRolesStmt) {
        while ($row = $taskRolesStmt->fetch_assoc()) {
            $key = strtolower(trim((string)($row['role_key'] ?? '')));
            if ($key !== '') {
                $roles[$key] = true;
            }
        }
    }
    $roles = array_values(array_keys($roles));
    sort($roles);

    $staff = [];
    if (!empty($roles)) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $types = str_repeat('s', count($roles));
        $sql = "
            SELECT
                u.userName,
                u.userRole,
                COUNT(t.taskId) AS assigned_total,
                SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_total,
                SUM(CASE WHEN a.alert_id IS NOT NULL
                         AND a.alert_type = 'overdue'
                         AND a.alert_status IN ('open','acknowledged')
                         THEN 1 ELSE 0 END) AS overdue_open,
                SUM(CASE WHEN t.status IN ('pending','assigned','in_progress','deferred','returned') THEN 1 ELSE 0 END) AS active_open,
                AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, t.timeStamp, t.completed_at) / 60 END) AS avg_completion_hours
            FROM tb_users u
            LEFT JOIN tb_tasks t ON t.assigned_to = u.userId
            LEFT JOIN tb_task_alerts a
                   ON a.task_id = t.taskId
                  AND a.alert_type = 'overdue'
            WHERE LOWER(TRIM(COALESCE(u.userRole, ''))) IN ($placeholders)
            GROUP BY u.userId, u.userName, u.userRole
            ORDER BY u.userRole, u.userName
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$roles);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignedTotal = (int)($row['assigned_total'] ?? 0);
                $completedTotal = (int)($row['completed_total'] ?? 0);
                $responseRate = $assignedTotal > 0 ? round(($completedTotal / $assignedTotal) * 100, 1) : 0.0;
                $userRole = strtolower(trim((string)($row['userRole'] ?? '')));
                $staff[] = [
                    'user_name' => (string)($row['userName'] ?? ''),
                    'user_role_label' => formatRoleLabelExport($conn, $userRole),
                    'assigned_total' => $assignedTotal,
                    'completed_total' => $completedTotal,
                    'active_open' => (int)($row['active_open'] ?? 0),
                    'overdue_open' => (int)($row['overdue_open'] ?? 0),
                    'response_rate' => $responseRate,
                    'avg_completion_hours' => round((float)($row['avg_completion_hours'] ?? 0), 2)
                ];
            }
            $stmt->close();
        }
    }

    return [
        'summary' => $summary,
        'staff_performance' => $staff
    ];
}

function pdfEscape($text): string {
    $value = (string)$text;
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('(', '\\(', $value);
    $value = str_replace(')', '\\)', $value);
    return preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $value) ?? '';
}

function pdfFixedWidth(string $value, int $maxChars): string {
    $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean, 'UTF-8') <= $maxChars) {
            return $clean;
        }
        return mb_substr($clean, 0, max(0, $maxChars - 1), 'UTF-8') . '...';
    }

    if (strlen($clean) <= $maxChars) {
        return $clean;
    }
    return substr($clean, 0, max(0, $maxChars - 1)) . '...';
}

function wrapPdfTextLines(string $text, int $maxChars, bool $breakLongWords = true): array {
    $clean = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($clean === '') {
        return [''];
    }

    $limit = max(1, $maxChars);
    $words = preg_split('/\s+/u', $clean) ?: [$clean];
    $lines = [];
    $current = '';

    $sliceWord = static function (string $word, int $length): array {
        $chunks = [];
        $offset = 0;
        $remaining = textLength($word);
        while ($remaining > 0) {
            if (function_exists('mb_substr')) {
                $chunk = mb_substr($word, $offset, $length, 'UTF-8');
            } else {
                $chunk = substr($word, $offset, $length);
            }
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $chunks[] = (string)$chunk;
            $offset += $length;
            $remaining -= textLength((string)$chunk);
        }
        return $chunks;
    };

    foreach ($words as $word) {
        if (textLength($word) > $limit && $breakLongWords) {
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            foreach ($sliceWord($word, $limit) as $chunk) {
                $lines[] = $chunk;
            }
            continue;
        }

        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        if (textLength($candidate) <= $limit) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
        }
        $current = $word;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return !empty($lines) ? $lines : [$clean];
}

function textLength(string $value): int {
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function chooseA4PdfOrientation(array $blocks): string {
    $maxLen = 0;
    $maxGridWidth = 0;
    foreach ($blocks as $block) {
        $len = textLength((string)($block['text'] ?? ''));
        if ($len > $maxLen) {
            $maxLen = $len;
        }

        $widths = is_array($block['widths'] ?? null) ? $block['widths'] : [];
        if (!empty($widths)) {
            $gridTotal = 0;
            foreach ($widths as $w) {
                $gridTotal += (int)$w;
            }
            if ($gridTotal > $maxGridWidth) {
                $maxGridWidth = $gridTotal;
            }
        }
    }

    // Wide rows (especially tabular headers) print better in landscape A4.
    return ($maxLen > 105 || $maxGridWidth > 95) ? 'landscape' : 'portrait';
}

function chooseA4XlsxOrientation(array $columnWidths, int $maxCols, array $rows = []): string {
    $totalWidth = 0.0;
    foreach ($columnWidths as $width) {
        $totalWidth += max(0.0, (float)$width);
    }

    $maxCellLength = 0;
    foreach ($rows as $row) {
        $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
        foreach ($cells as $cell) {
            $length = textLength((string)($cell['v'] ?? ''));
            if ($length > $maxCellLength) {
                $maxCellLength = $length;
            }
        }
    }

    // Practical thresholds for clean A4 print fit.
    if ($maxCols >= 8 || $totalWidth > 120 || $maxCellLength > 42) {
        return 'landscape';
    }
    return 'portrait';
}

function xlsxColIndex(string $name): int {
    $letters = strtoupper(trim($name));
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $char = ord($letters[$i]);
        if ($char < 65 || $char > 90) {
            continue;
        }
        $index = ($index * 26) + ($char - 64);
    }
    return max(0, $index - 1);
}

function computeXlsxAutoColumnWidths(array $rows, int $maxCols, array $merges = []): array {
    $widths = array_fill(0, max(1, $maxCols), 8.0);
    $mergedStarts = [];

    // Cells that start a multi-column merge should not drive single-column width.
    foreach ($merges as $mergeRef) {
        if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string)$mergeRef, $m)) {
            continue;
        }
        $startCol = xlsxColIndex($m[1]);
        $startRow = (int)$m[2];
        $endCol = xlsxColIndex($m[3]);
        $endRow = (int)$m[4];
        if ($startRow === $endRow && $endCol > $startCol) {
            $mergedStarts[$startRow . ':' . $startCol] = true;
        }
    }

    foreach ($rows as $row) {
        $rowNumber = (int)($row['r'] ?? 0);
        $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
        foreach ($cells as $colIndex => $cell) {
            if ($colIndex < 0 || $colIndex >= $maxCols) {
                continue;
            }
            if (isset($mergedStarts[$rowNumber . ':' . $colIndex])) {
                continue;
            }

            $raw = (string)($cell['v'] ?? '');
            if ($raw === '') {
                continue;
            }
            $len = textLength(trim($raw));
            if ($len <= 0) {
                continue;
            }

            // Excel column width units are roughly character counts.
            $candidate = min(60.0, max(8.0, $len + 2.0));
            if ($candidate > $widths[$colIndex]) {
                $widths[$colIndex] = $candidate;
            }
        }
    }

    return $widths;
}

function pdfPadCell(string $value, int $width, string $align = 'left'): string {
    $normalized = pdfFixedWidth($value, max(1, $width));
    $length = textLength($normalized);
    $padding = max(0, $width - $length);

    if ($align === 'right') {
        return str_repeat(' ', $padding) . $normalized;
    }
    if ($align === 'center') {
        $left = (int)floor($padding / 2);
        $right = $padding - $left;
        return str_repeat(' ', $left) . $normalized . str_repeat(' ', $right);
    }
    return $normalized . str_repeat(' ', $padding);
}

function buildWorkflowAlertPdfBlocks(array $dataset, string $generatedBy = 'System'): array {
    $blocks = [];
    $blocks[] = ['style' => 'title', 'text' => 'UPS PensionsGo - Workflow Performance Report'];
    $blocks[] = ['style' => 'meta', 'text' => 'Generated: ' . date('Y-m-d H:i:s')];
    $blocks[] = ['style' => 'meta', 'text' => 'Generated By: ' . ($generatedBy !== '' ? $generatedBy : 'System')];
    $blocks[] = ['style' => 'spacer', 'text' => ''];

    $blocks[] = ['style' => 'section', 'text' => 'Summary Metrics'];
    $summaryWidths = [6, 40, 12];
    $summaryAligns = ['center', 'left', 'center'];
    $blocks[] = ['style' => 'grid_header', 'cells' => ['S/N', 'Metric', 'Value'], 'widths' => $summaryWidths, 'aligns' => $summaryAligns];

    $summaryRows = [
        ['Open Tasks', (int)($dataset['summary']['total_open'] ?? 0)],
        ['Overdue Tasks', (int)($dataset['summary']['overdue_open'] ?? 0)],
        ['Completed (7d)', (int)($dataset['summary']['completed_7d'] ?? 0)],
        ['Average Completion (Hours)', number_format((float)($dataset['summary']['avg_completion_hours'] ?? 0), 2)]
    ];

    foreach ($summaryRows as $index => $row) {
        $blocks[] = ['style' => 'grid_row', 'cells' => [(string)($index + 1), (string)$row[0], (string)$row[1]], 'widths' => $summaryWidths, 'aligns' => $summaryAligns];
    }

    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Officer Performance'];
    $perfWidths = [5, 16, 16, 8, 9, 8, 9, 10, 11];
    $perfAligns = ['center', 'left', 'left', 'center', 'center', 'center', 'center', 'center', 'center'];
    $blocks[] = ['style' => 'grid_header', 'cells' => ['S/N', 'Officer', 'Role', 'Assigned', 'Completed', 'Open', 'Overdue', 'Response %', 'Avg Hours'], 'widths' => $perfWidths, 'aligns' => $perfAligns];
    if (empty($dataset['staff_performance'])) {
        $blocks[] = ['style' => 'detail', 'text' => 'No workflow performance records found.'];
    } else {
        foreach ($dataset['staff_performance'] as $index => $row) {
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => [
                    (string)($index + 1),
                    (string)($row['user_name'] ?? ''),
                    (string)($row['user_role_label'] ?? ''),
                    (string)($row['assigned_total'] ?? 0),
                    (string)($row['completed_total'] ?? 0),
                    (string)($row['active_open'] ?? 0),
                    (string)($row['overdue_open'] ?? 0),
                    number_format((float)($row['response_rate'] ?? 0), 1),
                    number_format((float)($row['avg_completion_hours'] ?? 0), 2)
                ],
                'widths' => $perfWidths,
                'aligns' => $perfAligns
            ];
        }
    }

    return $blocks;
}

function pdfRectFill(float $x, float $y, float $width, float $height, float $r, float $g, float $b): string {
    return "q\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " rg\n"
        . number_format($x, 2, '.', '') . ' '
        . number_format($y, 2, '.', '') . ' '
        . number_format($width, 2, '.', '') . ' '
        . number_format($height, 2, '.', '') . " re f\nQ\n";
}

function pdfRectStroke(float $x, float $y, float $width, float $height, float $r, float $g, float $b, float $lineWidth = 0.6): string {
    return "q\n"
        . number_format($lineWidth, 3, '.', '') . " w\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " RG\n"
        . number_format($x, 2, '.', '') . ' '
        . number_format($y, 2, '.', '') . ' '
        . number_format($width, 2, '.', '') . ' '
        . number_format($height, 2, '.', '') . " re S\nQ\n";
}

function pdfLine(float $x1, float $y1, float $x2, float $y2, float $r, float $g, float $b, float $lineWidth = 0.5): string {
    return "q\n"
        . number_format($lineWidth, 3, '.', '') . " w\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " RG\n"
        . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . " m\n"
        . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . " l S\nQ\n";
}

function estimatePdfTextWidth(string $text, float $fontSize): float {
    // Average glyph width approximation for positioning.
    $chars = max(0, textLength($text));
    return $chars * ($fontSize * 0.52);
}

function pdfTextCommand(string $fontAlias, float $fontSize, float $x, float $y, string $text, float $r, float $g, float $b): string {
    return "BT\n"
        . '/' . $fontAlias . ' ' . number_format($fontSize, 2, '.', '') . " Tf\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " rg\n"
        . "1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . " Tm\n"
        . '(' . pdfEscape($text) . ") Tj\nET\n";
}

function generateStyledPdf(array $blocks, string $orientation = 'portrait'): string {
    return pgoRenderBlocksPdf($blocks, $orientation, [
        'title' => 'UPS PensionsGo - Workflow Performance Report',
        'footer' => 'UPS PensionsGo Workflow Performance Export',
    ]);
}

function xlsxEscape($value): string {
    $text = (string)$value;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($converted !== false) {
            $text = $converted;
        }
    }

    // XML 1.0 valid chars: tab, LF, CR, and #x20-#xD7FF, #xE000-#xFFFD
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxColName(int $index): string {
    $name = '';
    $index += 1;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - $mod - 1) / 26);
    }
    return $name;
}

function xlsxCell($value, int $style, ?string $type = null): array {
    if ($type === null) {
        if (is_int($value) || is_float($value)) {
            $type = 'n';
        } elseif (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', (string)$value)) {
            $type = 'n';
        } else {
            $type = 's';
        }
    }

    return [
        'v' => $value,
        's' => $style,
        't' => $type
    ];
}

function xlsxSeverityStyle(string $severity): int {
    $normalized = strtolower(trim($severity));
    if ($normalized === 'critical') {
        return 11;
    }
    if ($normalized === 'warning') {
        return 10;
    }
    return 9;
}

function buildWorkflowAlertXlsxReport(array $dataset, string $generatedBy = 'System'): array {
    $rows = [];
    $merges = [];
    $rowNumber = 1;
    $maxCols = 9;

    $addRow = static function (array $cells, ?float $height = null) use (&$rows, &$rowNumber): int {
        $current = $rowNumber;
        $row = [
            'r' => $rowNumber,
            'cells' => $cells
        ];
        if ($height !== null && $height > 0) {
            $row['h'] = $height;
        }
        $rows[] = $row;
        $rowNumber++;
        return $current;
    };

    $addMergedTitleRow = static function (string $text, int $style, ?float $height = 20.0) use (&$addRow, &$merges, $maxCols): void {
        $rowRef = $addRow([xlsxCell($text, $style, 's')], $height);
        $merges[] = 'A' . $rowRef . ':' . xlsxColName($maxCols - 1) . $rowRef;
    };

    $addMergedTitleRow('UPS PensionsGo - Workflow Performance Report', 1, 28.0);

    $generatedRow = $addRow([
        xlsxCell('Generated On', 2, 's'),
        xlsxCell(date('Y-m-d H:i:s'), 3, 's')
    ], 18.0);
    $merges[] = 'B' . $generatedRow . ':' . xlsxColName($maxCols - 1) . $generatedRow;

    $generatedByRow = $addRow([
        xlsxCell('Generated By', 2, 's'),
        xlsxCell($generatedBy !== '' ? $generatedBy : 'System', 3, 's')
    ], 18.0);
    $merges[] = 'B' . $generatedByRow . ':' . xlsxColName($maxCols - 1) . $generatedByRow;

    $addRow([], 8.0);

    $addMergedTitleRow('Summary Metrics', 4, 20.0);
    $addRow([
        xlsxCell('S/N', 5, 's'),
        xlsxCell('Metric', 5, 's'),
        xlsxCell('Value', 5, 's')
    ], 18.0);

    $summaryRows = [
        ['Open Tasks', (int)($dataset['summary']['total_open'] ?? 0)],
        ['Overdue Tasks', (int)($dataset['summary']['overdue_open'] ?? 0)],
        ['Completed (7d)', (int)($dataset['summary']['completed_7d'] ?? 0)],
        ['Average Completion (Hours)', (float)($dataset['summary']['avg_completion_hours'] ?? 0)]
    ];
    foreach ($summaryRows as $index => $metric) {
        $addRow([
            xlsxCell((int)($index + 1), 7, 'n'),
            xlsxCell((string)$metric[0], 6, 's'),
            xlsxCell($metric[1], 8, 'n')
        ], 17.0);
    }

    $addRow([], 8.0);

    $addMergedTitleRow('Officer Performance', 4, 20.0);
    $addRow([
        xlsxCell('S/N', 5, 's'),
        xlsxCell('Officer', 5, 's'),
        xlsxCell('Role', 5, 's'),
        xlsxCell('Assigned', 5, 's'),
        xlsxCell('Completed', 5, 's'),
        xlsxCell('Open', 5, 's'),
        xlsxCell('Overdue', 5, 's'),
        xlsxCell('Response Rate (%)', 5, 's'),
        xlsxCell('Avg Completion (Hours)', 5, 's')
    ], 18.0);

    if (empty($dataset['staff_performance'])) {
        $emptyRow = $addRow([xlsxCell('No workflow performance records found.', 6, 's')], 17.0);
        $merges[] = 'A' . $emptyRow . ':' . xlsxColName($maxCols - 1) . $emptyRow;
    } else {
        foreach ($dataset['staff_performance'] as $index => $row) {
            $addRow([
                xlsxCell((int)($index + 1), 7, 'n'),
                xlsxCell((string)($row['user_name'] ?? ''), 6, 's'),
                xlsxCell((string)($row['user_role_label'] ?? ''), 6, 's'),
                xlsxCell((int)($row['assigned_total'] ?? 0), 7, 'n'),
                xlsxCell((int)($row['completed_total'] ?? 0), 7, 'n'),
                xlsxCell((int)($row['active_open'] ?? 0), 7, 'n'),
                xlsxCell((int)($row['overdue_open'] ?? 0), 7, 'n'),
                xlsxCell((float)($row['response_rate'] ?? 0), 8, 'n'),
                xlsxCell((float)($row['avg_completion_hours'] ?? 0), 8, 'n')
            ], 17.0);
        }
    }

    $columnWidths = computeXlsxAutoColumnWidths($rows, $maxCols, $merges);
    $orientation = chooseA4XlsxOrientation($columnWidths, $maxCols, $rows);

    return [
        'rows' => $rows,
        'merges' => $merges,
        'max_cols' => $maxCols,
        'column_widths' => $columnWidths,
        'orientation' => $orientation
    ];
}

function generateStyledXlsxBinary(array $report): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required for XLSX export.');
    }

    $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
    $merges = is_array($report['merges'] ?? null) ? $report['merges'] : [];
    $maxCols = max(1, (int)($report['max_cols'] ?? 9));
    $columnWidths = is_array($report['column_widths'] ?? null) ? $report['column_widths'] : [];
    $sheetOrientation = strtolower(trim((string)($report['orientation'] ?? 'portrait'))) === 'landscape' ? 'landscape' : 'portrait';

    $worksheetRowsXml = '';
    $lastRow = 1;

    foreach ($rows as $row) {
        $rowRef = (int)($row['r'] ?? ($lastRow + 1));
        $lastRow = max($lastRow, $rowRef);
        $height = (float)($row['h'] ?? 0);
        $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];

        $rowAttrs = ' r="' . $rowRef . '"';
        if ($height > 0) {
            $rowAttrs .= ' ht="' . number_format($height, 2, '.', '') . '" customHeight="1"';
        }

        $worksheetRowsXml .= '<row' . $rowAttrs . '>';

        $colIndex = 0;
        foreach ($cells as $cell) {
            $value = $cell['v'] ?? '';
            if ($value === null || $value === '') {
                $colIndex++;
                continue;
            }

            $styleId = max(0, (int)($cell['s'] ?? 0));
            $type = (string)($cell['t'] ?? 's');
            $ref = xlsxColName($colIndex) . $rowRef;
            $styleAttr = ' s="' . $styleId . '"';

            if ($type === 'n' && is_numeric($value)) {
                $worksheetRowsXml .= '<c r="' . $ref . '"' . $styleAttr . '><v>' . xlsxEscape((string)$value) . '</v></c>';
            } else {
                $worksheetRowsXml .= '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t>' . xlsxEscape((string)$value) . '</t></is></c>';
            }
            $colIndex++;
        }

        $worksheetRowsXml .= '</row>';
    }

    $colsXml = '';
    if (!empty($columnWidths)) {
        $colsXml = '<cols>';
        foreach ($columnWidths as $index => $width) {
            $colPos = $index + 1;
            $numericWidth = max(8.0, (float)$width);
            $colsXml .= '<col min="' . $colPos . '" max="' . $colPos . '" width="' . number_format($numericWidth, 2, '.', '') . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    $mergeXml = '';
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $mergeRef) {
            $mergeXml .= '<mergeCell ref="' . xlsxEscape((string)$mergeRef) . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $lastColRef = xlsxColName($maxCols - 1);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $lastColRef . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="16"/>'
        . $colsXml
        . '<sheetData>' . $worksheetRowsXml . '</sheetData>'
        . $mergeXml
        . '<printOptions horizontalCentered="0" verticalCentered="0" headings="0" gridLines="0"/>'
        . '<pageMargins left="0.30" right="0.30" top="0.35" bottom="0.35" header="0.30" footer="0.30"/>'
        . '<pageSetup paperSize="9" orientation="' . $sheetOrientation . '" fitToWidth="1" fitToHeight="1"/>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Workflow Performance" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><color rgb="FF1F2937"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF111827"/><name val="Tahoma"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="9">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF741A2D"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4CC"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFA32A3E"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF9A2337"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF9"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE6A8"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF7D7DD"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFB44556"/></left>'
        . '<right style="thin"><color rgb="FFB44556"/></right>'
        . '<top style="thin"><color rgb="FFB44556"/></top>'
        . '<bottom style="thin"><color rgb="FFB44556"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="12">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'wfa_xlsx_');
    if ($tmpFile === false) {
        throw new RuntimeException('Failed to allocate temp file for XLSX export.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpFile);
        throw new RuntimeException('Failed to create XLSX archive.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $binary = (string)file_get_contents($tmpFile);
    @unlink($tmpFile);

    if ($binary === '') {
        throw new RuntimeException('Generated XLSX file is empty.');
    }

    return $binary;
}

$dataset = fetchWorkflowAlertExportDataset($conn);
$timestamp = date('Ymd_His');
$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
$generatedBy = trim((string)($_SESSION['userName'] ?? $_SESSION['name'] ?? $_SESSION['userEmail'] ?? 'System'));
if ($generatedBy === '') {
    $generatedBy = 'System';
}

if ($format === 'xlsx') {
    try {
        $report = buildWorkflowAlertXlsxReport($dataset, $generatedBy);
        $xlsxBinary = generateStyledXlsxBinary($report);
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="workflow_performance_' . $timestamp . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxBinary));
        echo $xlsxBinary;
        $conn->close();
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'XLSX export failed: ' . $e->getMessage();
        $conn->close();
        exit;
    }
}

if ($format === 'pdf') {
    try {
        $blocks = buildWorkflowAlertPdfBlocks($dataset, $generatedBy);
        $pdfOrientation = chooseA4PdfOrientation($blocks);
        $pdf = generateStyledPdf($blocks, $pdfOrientation);
        $downloadRequested = in_array(strtolower(trim((string)($_GET['download'] ?? ''))), ['1', 'true', 'yes'], true);
        $disposition = $downloadRequested ? 'attachment' : 'inline';

        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="workflow_performance_' . $timestamp . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        $conn->close();
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF export failed: ' . $e->getMessage();
        $conn->close();
        exit;
    }
}

http_response_code(400);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Unsupported format. Use format=xlsx or format=pdf.';
$conn->close();
exit;
