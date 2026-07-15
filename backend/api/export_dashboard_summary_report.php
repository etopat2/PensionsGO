<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';
require_once __DIR__ . '/data_export_runtime.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower(trim((string)($_SESSION['userRole'] ?? '')));
if ($role === 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

function summaryReportText($value, string $fallback = ''): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function summaryReportNormalizeNarratives($items): array
{
    $rows = [];
    if (!is_array($items)) {
        return $rows;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = summaryReportText($item['title'] ?? '');
        $body = summaryReportText($item['body'] ?? '');
        $tone = summaryReportText($item['tone'] ?? 'info', 'info');
        if ($title === '' && $body === '') {
            continue;
        }
        $rows[] = [
            'title' => $title !== '' ? $title : 'Note',
            'body' => $body,
            'tone' => $tone
        ];
    }

    return $rows;
}

function summaryReportNormalizeMetrics($items): array
{
    $rows = [];
    if (!is_array($items)) {
        return $rows;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $metric = summaryReportText($item['metric'] ?? '');
        if ($metric === '') {
            continue;
        }
        $rows[] = [
            'metric' => $metric,
            'value' => summaryReportText($item['value'] ?? '', 'N/A'),
            'note' => summaryReportText($item['note'] ?? '')
        ];
    }

    return $rows;
}

function summaryReportNormalizeChartGroups($items): array
{
    $groups = [];
    if (!is_array($items)) {
        return $groups;
    }

    foreach ($items as $group) {
        if (!is_array($group)) {
            continue;
        }
        $normalizedItems = [];
        foreach (($group['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = summaryReportText($row['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $normalizedItems[] = [
                'label' => $label,
                'displayValue' => summaryReportText($row['displayValue'] ?? $row['value'] ?? '', '0'),
                'meta' => summaryReportText($row['meta'] ?? '')
            ];
        }

        $groups[] = [
            'title' => summaryReportText($group['title'] ?? '', 'Chart Group'),
            'description' => summaryReportText($group['description'] ?? ''),
            'items' => $normalizedItems
        ];
    }

    return $groups;
}

function summaryReportNormalizePayload(array $payload): array
{
    $preparedBy = summaryReportText($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User', 'System User');
    $executiveSummary = [];
    foreach (($payload['executiveSummary'] ?? []) as $paragraph) {
        $text = summaryReportText($paragraph);
        if ($text !== '') {
            $executiveSummary[] = $text;
        }
    }

    $cards = [];
    foreach (($payload['cards'] ?? []) as $card) {
        if (!is_array($card)) {
            continue;
        }
        $label = summaryReportText($card['label'] ?? '');
        if ($label === '') {
            continue;
        }
        $cards[] = [
            'label' => $label,
            'value' => summaryReportText($card['value'] ?? '', 'N/A'),
            'helper' => summaryReportText($card['helper'] ?? '')
        ];
    }

    return [
        'title' => summaryReportText($payload['title'] ?? '', 'Pension Performance Summary Report'),
        'subtitle' => summaryReportText($payload['subtitle'] ?? ''),
        'generatedAt' => summaryReportText($payload['generatedAt'] ?? '', date('c')),
        'generatedBy' => $preparedBy,
        'executiveSummary' => $executiveSummary,
        'cards' => $cards,
        'chartGroups' => summaryReportNormalizeChartGroups($payload['chartGroups'] ?? []),
        'priorityAreas' => summaryReportNormalizeNarratives($payload['priorityAreas'] ?? []),
        'recommendations' => summaryReportNormalizeNarratives($payload['recommendations'] ?? []),
        'commendations' => summaryReportNormalizeNarratives($payload['commendations'] ?? []),
        'managementSignals' => summaryReportNormalizeNarratives($payload['managementSignals'] ?? []),
        'metricsTable' => summaryReportNormalizeMetrics($payload['metricsTable'] ?? [])
    ];
}

function summaryReportPdfBlocks(array $report): array
{
    $blocks = [
        ['style' => 'title', 'text' => $report['title']],
        ['style' => 'meta', 'text' => 'Generated: ' . $report['generatedAt']],
        ['style' => 'meta', 'text' => 'Prepared by: ' . $report['generatedBy']]
    ];

    if ($report['subtitle'] !== '') {
        $blocks[] = ['style' => 'meta', 'text' => $report['subtitle']];
    }

    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Executive Overview'];
    foreach ($report['executiveSummary'] as $paragraph) {
        $blocks[] = ['style' => 'detail', 'text' => $paragraph];
    }

    if (!empty($report['cards'])) {
        $blocks[] = ['style' => 'spacer', 'text' => ''];
        $blocks[] = ['style' => 'section', 'text' => 'Performance Snapshot'];
        $blocks[] = [
            'style' => 'grid_header',
            'cells' => ['Metric', 'Value & Context', 'Metric', 'Value & Context'],
            'widths' => [18, 32, 18, 32],
            'aligns' => ['left', 'left', 'left', 'left']
        ];
        for ($index = 0; $index < count($report['cards']); $index += 2) {
            $left = $report['cards'][$index];
            $right = $report['cards'][$index + 1] ?? ['label' => '', 'value' => '', 'helper' => ''];
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => [
                    $left['label'],
                    $left['value'] . ($left['helper'] !== '' ? ' | ' . $left['helper'] : ''),
                    $right['label'],
                    $right['value'] . ($right['helper'] !== '' ? ' | ' . $right['helper'] : '')
                ],
                'widths' => [18, 32, 18, 32],
                'aligns' => ['left', 'left', 'left', 'left']
            ];
        }
    }

    foreach ($report['chartGroups'] as $group) {
        $blocks[] = ['style' => 'spacer', 'text' => ''];
        $blocks[] = ['style' => 'section', 'text' => $group['title']];
        if ($group['description'] !== '') {
            $blocks[] = ['style' => 'detail', 'text' => $group['description']];
        }
        $blocks[] = [
            'style' => 'grid_header',
            'cells' => ['Signal', 'Value', 'Context'],
            'widths' => [24, 14, 42],
            'aligns' => ['left', 'right', 'left']
        ];
        foreach ($group['items'] as $item) {
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => [$item['label'], $item['displayValue'], $item['meta']],
                'widths' => [24, 14, 42],
                'aligns' => ['left', 'right', 'left']
            ];
        }
    }

    $narrativeSections = [
        'Priority Areas to Manage' => $report['priorityAreas'],
        'Recommended Actions' => $report['recommendations'],
        'Commendations' => $report['commendations'],
        'Management Signals' => $report['managementSignals']
    ];

    foreach ($narrativeSections as $title => $items) {
        $blocks[] = ['style' => 'spacer', 'text' => ''];
        $blocks[] = ['style' => 'section', 'text' => $title];
        foreach ($items as $item) {
            $line = $item['title'];
            if ($item['body'] !== '') {
                $line .= ': ' . $item['body'];
            }
            $blocks[] = ['style' => 'detail', 'text' => '- ' . $line];
        }
    }

    if (!empty($report['metricsTable'])) {
        $blocks[] = ['style' => 'spacer', 'text' => ''];
        $blocks[] = ['style' => 'section', 'text' => 'Decision Support Metrics'];
        $blocks[] = [
            'style' => 'grid_header',
            'cells' => ['S/N', 'Metric', 'Current Value', 'Operational Meaning'],
            'widths' => [8, 22, 16, 34],
            'aligns' => ['center', 'left', 'right', 'left']
        ];
        foreach ($report['metricsTable'] as $index => $item) {
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => [(string)($index + 1), $item['metric'], $item['value'], $item['note']],
                'widths' => [8, 22, 16, 34],
                'aligns' => ['center', 'left', 'right', 'left']
            ];
        }
    }

    return $blocks;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid export request.');
    }

    $format = strtolower(trim((string)($payload['format'] ?? 'pdf')));
    if ($format !== 'pdf') {
        throw new RuntimeException('Only PDF export is supported by this endpoint.');
    }

    $report = summaryReportNormalizePayload((array)($payload['report'] ?? []));
    if ($report['executiveSummary'] === [] && $report['cards'] === [] && $report['metricsTable'] === []) {
        throw new RuntimeException('The summary report payload is empty.');
    }

    ensureDataManagementInfrastructure($conn);

    $actor = [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => summaryReportText($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User', 'System User'),
        'user_role' => normalizeRoleKey((string)($_SESSION['userRole'] ?? ''))
    ];

    $timestamp = date('Ymd_His');
    $fileName = 'dashboard_summary_report_' . $timestamp . '.pdf';
    $filePath = getDataExportStoragePath() . DIRECTORY_SEPARATOR . $fileName;
    $binary = dmPdfBinary(summaryReportPdfBlocks($report), 'portrait', $report['title']);
    if (file_put_contents($filePath, $binary) === false) {
        throw new RuntimeException('Unable to write the summary report PDF.');
    }

    $size = is_file($filePath) ? (int)filesize($filePath) : 0;
    recordDataExportRun($conn, [
        'dataset_key' => 'dashboard_summary_report',
        'dataset_label' => 'Dashboard Summary Report',
        'export_format' => 'pdf',
        'file_name' => $fileName,
        'file_path' => $filePath,
        'file_size_bytes' => $size,
        'filters_json' => ['title' => $report['title']],
        'status' => 'success',
        'notes' => 'Executive summary report generated from the dashboard general statistics workspace.',
        'created_by' => $actor['user_id'],
        'created_by_name' => $actor['user_name'],
        'created_by_role' => $actor['user_role']
    ]);

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'dashboard_summary_report_exported',
        'entity_type' => 'dashboard_summary_report',
        'entity_id' => $fileName,
        'details' => [
            'format' => 'pdf',
            'title' => $report['title'],
            'file_name' => $fileName
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Summary report PDF generated successfully.',
        'export' => [
            'dataset_label' => 'Dashboard Summary Report',
            'row_count' => count($report['metricsTable']),
            'file_name' => $fileName,
            'file_size_bytes' => $size,
            'download_url' => '../backend/api/download_data_artifact.php?type=export&file=' . rawurlencode($fileName)
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
