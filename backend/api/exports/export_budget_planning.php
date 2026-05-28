<?php
$__baseBufferLevel = ob_get_level();
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/pdf_library.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    while (ob_get_level() > $__baseBufferLevel) {
        ob_end_clean();
    }
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Authentication required';
    exit;
}

$analyticsExportEnabled = getAppSettingBool($conn, 'analytics_export_enabled', true);
$includeForecasts = getAppSettingBool($conn, 'analytics_include_financial_forecasts', true);
if (!$analyticsExportEnabled || !$includeForecasts) {
    while (ob_get_level() > $__baseBufferLevel) {
        ob_end_clean();
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Budget export is currently disabled by system settings.';
    exit;
}

ob_start();
include __DIR__ . '/../get_budget_summary.php';
$rawJson = ob_get_clean();
$data = json_decode((string)$rawJson, true);
if (!is_array($data) || empty($data['success'])) {
    while (ob_get_level() > $__baseBufferLevel) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to export budget planning data.';
    exit;
}

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
    $format = 'xlsx';
}

$timestamp = date('Ymd_His');
$generatedBy = trim((string)($_SESSION['userName'] ?? $_SESSION['name'] ?? $_SESSION['userEmail'] ?? 'System'));
if ($generatedBy === '') {
    $generatedBy = 'System';
}

function txLen(string $value): int {
    return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
}

function wrapPdfLines(string $text, int $maxChars, bool $breakLongWords = true): array {
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
        $remaining = txLen($word);
        while ($remaining > 0) {
            $chunk = function_exists('mb_substr')
                ? mb_substr($word, $offset, $length, 'UTF-8')
                : substr($word, $offset, $length);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $chunks[] = (string)$chunk;
            $offset += $length;
            $remaining -= txLen((string)$chunk);
        }
        return $chunks;
    };

    foreach ($words as $word) {
        if (txLen($word) > $limit && $breakLongWords) {
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
        if (txLen($candidate) <= $limit) {
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

function choosePdfOrientation(array $blocks): string {
    $maxLen = 0;
    $maxGridWidth = 0;
    foreach ($blocks as $block) {
        $maxLen = max($maxLen, txLen((string)($block['text'] ?? '')));
        $widths = is_array($block['widths'] ?? null) ? $block['widths'] : [];
        if (!empty($widths)) {
            $gridTotal = 0;
            foreach ($widths as $w) {
                $gridTotal += (int)$w;
            }
            $maxGridWidth = max($maxGridWidth, $gridTotal);
        }
    }
    return ($maxLen > 105 || $maxGridWidth > 95) ? 'landscape' : 'portrait';
}

function chooseXlsxOrientation(array $columnWidths, int $maxCols, array $rows = []): string {
    $totalWidth = 0.0;
    foreach ($columnWidths as $width) {
        $totalWidth += max(0.0, (float)$width);
    }

    $maxCellLength = 0;
    foreach ($rows as $row) {
        foreach ((array)($row['cells'] ?? []) as $cell) {
            $maxCellLength = max($maxCellLength, txLen((string)($cell['v'] ?? '')));
        }
    }

    return ($maxCols >= 8 || $totalWidth > 120 || $maxCellLength > 42) ? 'landscape' : 'portrait';
}

function xlsxColNameLocal(int $index): string {
    $name = '';
    $index += 1;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - $mod - 1) / 26);
    }
    return $name;
}

function xlsxColIndexLocal(string $name): int {
    $letters = strtoupper(trim($name));
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $char = ord($letters[$i]);
        if ($char >= 65 && $char <= 90) {
            $index = ($index * 26) + ($char - 64);
        }
    }
    return max(0, $index - 1);
}

function computeXlsxWidths(array $rows, int $maxCols, array $merges = []): array {
    $widths = array_fill(0, max(1, $maxCols), 8.0);
    $mergedStarts = [];

    foreach ($merges as $mergeRef) {
        if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string)$mergeRef, $m)) {
            continue;
        }
        $startCol = xlsxColIndexLocal($m[1]);
        $startRow = (int)$m[2];
        $endCol = xlsxColIndexLocal($m[3]);
        $endRow = (int)$m[4];
        if ($startRow === $endRow && $endCol > $startCol) {
            $mergedStarts[$startRow . ':' . $startCol] = true;
        }
    }

    foreach ($rows as $row) {
        $rowNumber = (int)($row['r'] ?? 0);
        foreach ((array)($row['cells'] ?? []) as $colIndex => $cell) {
            if ($colIndex < 0 || $colIndex >= $maxCols || isset($mergedStarts[$rowNumber . ':' . $colIndex])) {
                continue;
            }
            $raw = trim((string)($cell['v'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $longest = 0;
            foreach (preg_split('/\R/u', $raw) ?: [$raw] as $line) {
                $longest = max($longest, txLen(trim((string)$line)));
            }
            if ($longest > 0) {
                $widths[$colIndex] = max($widths[$colIndex], min(60.0, max(8.0, $longest + 2.0)));
            }
        }
    }

    return $widths;
}

function xlsxEscapeLocal($value): string {
    $text = (string)$value;
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxCellLocal($value, int $style, ?string $type = null): array {
    if ($type === null) {
        $type = ((is_int($value) || is_float($value)) || (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', (string)$value)))
            ? 'n'
            : 's';
    }
    return ['v' => $value, 's' => $style, 't' => $type];
}

function pdfEscapeLocal(string $text): string {
    $value = str_replace('\\', '\\\\', $text);
    $value = str_replace('(', '\\(', $value);
    $value = str_replace(')', '\\)', $value);
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
}

function pdfFixedWidthLocal(string $value, int $maxChars): string {
    $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    if ($clean === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($clean, 'UTF-8') <= $maxChars
            ? $clean
            : mb_substr($clean, 0, max(0, $maxChars - 1), 'UTF-8') . '...';
    }
    return strlen($clean) <= $maxChars ? $clean : substr($clean, 0, max(0, $maxChars - 1)) . '...';
}

function pdfRectFillLocal(float $x, float $y, float $width, float $height, float $r, float $g, float $b): string {
    return "q\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " rg\n"
        . number_format($x, 2, '.', '') . ' '
        . number_format($y, 2, '.', '') . ' '
        . number_format($width, 2, '.', '') . ' '
        . number_format($height, 2, '.', '') . " re f\nQ\n";
}

function pdfRectStrokeLocal(float $x, float $y, float $width, float $height, float $r, float $g, float $b, float $lineWidth = 0.6): string {
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

function pdfLineLocal(float $x1, float $y1, float $x2, float $y2, float $r, float $g, float $b, float $lineWidth = 0.5): string {
    return "q\n"
        . number_format($lineWidth, 3, '.', '') . " w\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " RG\n"
        . number_format($x1, 2, '.', '') . ' ' . number_format($y1, 2, '.', '') . " m\n"
        . number_format($x2, 2, '.', '') . ' ' . number_format($y2, 2, '.', '') . " l S\nQ\n";
}

function pdfTextWidthLocal(string $text, float $fontSize): float {
    return max(0, txLen($text)) * ($fontSize * 0.52);
}

function pdfTextCmdLocal(string $fontAlias, float $fontSize, float $x, float $y, string $text, float $r, float $g, float $b): string {
    return "BT\n"
        . '/' . $fontAlias . ' ' . number_format($fontSize, 2, '.', '') . " Tf\n"
        . number_format($r, 3, '.', '') . ' '
        . number_format($g, 3, '.', '') . ' '
        . number_format($b, 3, '.', '') . " rg\n"
        . "1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . " Tm\n"
        . '(' . pdfEscapeLocal($text) . ") Tj\nET\n";
}

function buildBudgetPayload(array $data, string $generatedBy): array {
    $selectedFy = trim((string)($data['selectedFinancialYear'] ?? ''));
    $nextFy = '';
    if (preg_match('/^FY\s+(\d{4})\/(\d{4})$/i', $selectedFy, $fyMatch)) {
        $nextFy = 'FY ' . ((int)$fyMatch[1] + 1) . '/' . ((int)$fyMatch[2] + 1);
    }
    $selectedPensioner = trim((string)($_GET['pensioner'] ?? ''));
    $totals = is_array($data['matrix']['totals'] ?? null) ? $data['matrix']['totals'] : [];
    $rows = [];
    $sn = 1;
    foreach ((array)($data['matrix']['rows'] ?? []) as $row) {
        $rows[] = [
            'sn' => $sn++,
            'file_no' => (string)($row['regNo'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'name' => (string)($row['displayName'] ?? $row['name'] ?? ''),
            'pension_arrears' => (float)($row['pension_arrears'] ?? 0),
            'gratuity_arrears' => (float)($row['gratuity_arrears'] ?? 0),
            'full_pension_arrears' => (float)($row['full_pension_arrears'] ?? 0),
            'pension_gratuity' => (float)($row['pension_gratuity'] ?? 0),
            'underpayment' => (float)($row['underpayment'] ?? 0),
            'total' => (float)($row['total'] ?? 0)
        ];
    }

    $projection = is_array($data['projection'] ?? null) ? $data['projection'] : [];
    $projectionRows = [
        [($selectedFy !== '' ? 'Current ' . $selectedFy : 'Current FY') . ' Active Pensioners (Monthly)', (float)($projection['current']['active_monthly'] ?? 0)],
        [($selectedFy !== '' ? 'Current ' . $selectedFy : 'Current FY') . ' Retirees (Monthly)', (float)($projection['current']['retirees_monthly'] ?? 0)],
        [($selectedFy !== '' ? 'Current ' . $selectedFy : 'Current FY') . ' Retirees (Gratuity)', (float)($projection['current']['retirees_gratuity'] ?? 0)],
        [($selectedFy !== '' ? 'Current ' . $selectedFy : 'Current FY') . ' Total', (float)($projection['current']['total'] ?? 0)],
        [($nextFy !== '' ? 'Subsequent ' . $nextFy : 'Subsequent FY') . ' Active Pensioners (Monthly)', (float)($projection['next']['active_monthly'] ?? 0)],
        [($selectedFy !== '' ? 'Current ' . $selectedFy : 'Current FY') . ' Retirees (Monthly)', (float)($projection['next']['current_retirees_monthly'] ?? 0)],
        [($nextFy !== '' ? 'Subsequent ' . $nextFy : 'Subsequent FY') . ' Retirees (Monthly)', (float)($projection['next']['next_retirees_monthly'] ?? 0)],
        [($nextFy !== '' ? 'Subsequent ' . $nextFy : 'Subsequent FY') . ' Retirees (Gratuity)', (float)($projection['next']['next_retirees_gratuity'] ?? 0)],
        [($nextFy !== '' ? 'Subsequent ' . $nextFy : 'Subsequent FY') . ' Total', (float)($projection['next']['total'] ?? 0)]
    ];

    return [
        'selected_fy' => $selectedFy,
        'next_fy' => $nextFy,
        'selected_pensioner' => $selectedPensioner,
        'generated_by' => $generatedBy,
        'generated_at' => date('Y-m-d H:i:s'),
        'rows' => $rows,
        'totals' => [
            'pension_arrears' => (float)($totals['pension_arrears'] ?? 0),
            'gratuity_arrears' => (float)($totals['gratuity_arrears'] ?? 0),
            'full_pension_arrears' => (float)($totals['full_pension_arrears'] ?? 0),
            'pension_gratuity' => (float)($totals['pension_gratuity'] ?? 0),
            'underpayment' => (float)($totals['underpayment'] ?? 0),
            'total' => (float)($totals['total'] ?? 0)
        ],
        'projection_rows' => $projectionRows
    ];
}

function buildBudgetPdfBlocks(array $payload): array {
    $blocks = [];
    $blocks[] = ['style' => 'title', 'text' => 'UPS PensionsGo - Budget Forecast & Arrears Planning Report'];
    if (!empty($payload['selected_fy'])) {
        $blocks[] = ['style' => 'meta', 'text' => 'Financial Year: ' . (string)$payload['selected_fy']];
    }
    $blocks[] = ['style' => 'meta', 'text' => 'Generated: ' . ($payload['generated_at'] ?? date('Y-m-d H:i:s'))];
    $blocks[] = ['style' => 'meta', 'text' => 'Generated By: ' . ($payload['generated_by'] ?? 'System')];
    if (!empty($payload['selected_pensioner'])) {
        $blocks[] = ['style' => 'meta', 'text' => 'Filter: ' . (string)$payload['selected_pensioner']];
    }
    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Arrears Summary'];
    $summaryWidths = [6, 40, 18];
    $summaryAligns = ['center', 'left', 'center'];
    $summaryHeaderAligns = array_fill(0, count($summaryWidths), 'center');
    $blocks[] = ['style' => 'grid_header', 'cells' => ['S/N', 'Metric', 'Value (UGX)'], 'widths' => $summaryWidths, 'aligns' => $summaryHeaderAligns];

    $summaryRows = [
        ['Pension Arrears', (float)($payload['totals']['pension_arrears'] ?? 0)],
        ['Gratuity Arrears', (float)($payload['totals']['gratuity_arrears'] ?? 0)],
        ['Full Pension Arrears', (float)($payload['totals']['full_pension_arrears'] ?? 0)],
        ['Pension & Gratuity', (float)($payload['totals']['pension_gratuity'] ?? 0)],
        ['Underpayment', (float)($payload['totals']['underpayment'] ?? 0)],
        ['Grand Total', (float)($payload['totals']['total'] ?? 0)]
    ];
    foreach ($summaryRows as $index => $row) {
        $isGrandTotal = strtolower(trim((string)$row[0])) === 'grand total';
        $blocks[] = [
            'style' => 'grid_row',
            'bold' => $isGrandTotal,
            'merge' => $isGrandTotal ? [[0, 1]] : [],
            'cells' => $isGrandTotal
                ? [(string)$row[0], '', number_format((float)$row[1], 2)]
                : [(string)($index + 1), (string)$row[0], number_format((float)$row[1], 2)],
            'widths' => $summaryWidths,
            'aligns' => $isGrandTotal ? ['left', 'left', 'right'] : $summaryAligns
        ];
    }

    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Arrears Matrix'];
    $matrixWidths = [4, 9, 7, 22, 14, 14, 14, 14, 12, 12];
    $matrixAligns = ['center', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right', 'right'];
    $matrixHeaderAligns = array_fill(0, count($matrixWidths), 'center');
    $blocks[] = [
        'style' => 'grid_header',
        'cells' => ['S/N', 'File No.', 'Title', 'Name', 'Pension Arrears', 'Gratuity Arrears', 'Full Pension Arrears', 'Pension & Gratuity', 'Underpayment', 'Total'],
        'widths' => $matrixWidths,
        'aligns' => $matrixHeaderAligns
    ];
    if (empty($payload['rows'])) {
        $blocks[] = ['style' => 'detail', 'text' => 'No arrears rows matched the current selection.'];
    } else {
        foreach ($payload['rows'] as $row) {
            $blocks[] = [
                'style' => 'grid_row',
                'cells' => [
                    (string)$row['sn'],
                    (string)$row['file_no'],
                    (string)$row['title'],
                    (string)$row['name'],
                    number_format((float)$row['pension_arrears'], 2),
                    number_format((float)$row['gratuity_arrears'], 2),
                    number_format((float)$row['full_pension_arrears'], 2),
                    number_format((float)$row['pension_gratuity'], 2),
                    number_format((float)$row['underpayment'], 2),
                    number_format((float)$row['total'], 2)
                ],
                'widths' => $matrixWidths,
                'aligns' => $matrixAligns
            ];
        }
        $blocks[] = [
            'style' => 'grid_row',
            'bold' => true,
            'merge' => [[0, 3]],
            'cells' => [
                'TOTAL', '', '', '',
                number_format((float)$payload['totals']['pension_arrears'], 2),
                number_format((float)$payload['totals']['gratuity_arrears'], 2),
                number_format((float)$payload['totals']['full_pension_arrears'], 2),
                number_format((float)$payload['totals']['pension_gratuity'], 2),
                number_format((float)$payload['totals']['underpayment'], 2),
                number_format((float)$payload['totals']['total'], 2)
            ],
            'widths' => $matrixWidths,
            'aligns' => ['left', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right', 'right']
        ];
    }

    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Projection Summary'];
    $projectionWidths = [6, 52, 24];
    $projectionAligns = ['center', 'left', 'right'];
    $projectionHeaderAligns = array_fill(0, count($projectionWidths), 'center');
    $blocks[] = ['style' => 'grid_header', 'cells' => ['S/N', 'Projection Item', 'Amount (UGX)'], 'widths' => $projectionWidths, 'aligns' => $projectionHeaderAligns];
    foreach ($payload['projection_rows'] as $index => $row) {
        $label = (string)$row[0];
        $isTotalRow = preg_match('/\\btotal\\b/i', $label) === 1;
        $blocks[] = [
            'style' => 'grid_row',
            'bold' => $isTotalRow,
            'merge' => $isTotalRow ? [[0, 1]] : [],
            'cells' => $isTotalRow
                ? [$label, '', number_format((float)$row[1], 2)]
                : [(string)($index + 1), $label, number_format((float)$row[1], 2)],
            'widths' => $projectionWidths,
            'aligns' => $isTotalRow ? ['left', 'left', 'right'] : $projectionAligns
        ];
    }
    return $blocks;
}

function buildBudgetXlsxReport(array $payload): array {
    $rows = [];
    $merges = [];
    $rowNumber = 1;
    $maxCols = 10;

    $addRow = static function (array $cells, ?float $height = null) use (&$rows, &$rowNumber): int {
        $current = $rowNumber;
        $row = ['r' => $rowNumber, 'cells' => $cells];
        if ($height !== null && $height > 0) {
            $row['h'] = $height;
        }
        $rows[] = $row;
        $rowNumber++;
        return $current;
    };

    $addMergedTitleRow = static function (string $text, int $style, ?float $height = 20.0) use (&$addRow, &$merges, $maxCols): void {
        $rowRef = $addRow([xlsxCellLocal($text, $style, 's')], $height);
        $merges[] = 'A' . $rowRef . ':' . xlsxColNameLocal($maxCols - 1) . $rowRef;
    };

    $addMergedTitleRow('UPS PensionsGo - Budget Forecast & Arrears Planning Report', 1, 28.0);
    $fyRow = $addRow([xlsxCellLocal('Financial Year', 2, 's'), xlsxCellLocal((string)$payload['selected_fy'], 3, 's')], 18.0);
    $merges[] = 'B' . $fyRow . ':' . xlsxColNameLocal($maxCols - 1) . $fyRow;
    if (!empty($payload['selected_pensioner'])) {
        $filterRow = $addRow([xlsxCellLocal('Filter', 2, 's'), xlsxCellLocal((string)$payload['selected_pensioner'], 3, 's')], 18.0);
        $merges[] = 'B' . $filterRow . ':' . xlsxColNameLocal($maxCols - 1) . $filterRow;
    }
    $generatedRow = $addRow([xlsxCellLocal('Generated On', 2, 's'), xlsxCellLocal((string)$payload['generated_at'], 3, 's')], 18.0);
    $merges[] = 'B' . $generatedRow . ':' . xlsxColNameLocal($maxCols - 1) . $generatedRow;
    $generatedByRow = $addRow([xlsxCellLocal('Generated By', 2, 's'), xlsxCellLocal((string)$payload['generated_by'], 3, 's')], 18.0);
    $merges[] = 'B' . $generatedByRow . ':' . xlsxColNameLocal($maxCols - 1) . $generatedByRow;

    $addRow([], 8.0);
    $addMergedTitleRow('Arrears Summary', 4, 20.0);
    $addRow([xlsxCellLocal('S/N', 5, 's'), xlsxCellLocal('Metric', 5, 's'), xlsxCellLocal('Value (UGX)', 5, 's')], 18.0);
    $summaryRows = [
        ['Pension Arrears', (float)$payload['totals']['pension_arrears']],
        ['Gratuity Arrears', (float)$payload['totals']['gratuity_arrears']],
        ['Full Pension Arrears', (float)$payload['totals']['full_pension_arrears']],
        ['Pension & Gratuity', (float)$payload['totals']['pension_gratuity']],
        ['Underpayment', (float)$payload['totals']['underpayment']],
        ['Grand Total', (float)$payload['totals']['total']]
    ];
    foreach ($summaryRows as $index => $metric) {
        $isGrandTotal = strtolower(trim((string)$metric[0])) === 'grand total';
        if ($isGrandTotal) {
            $rowRef = $addRow([
                xlsxCellLocal('Grand Total', 9, 's'),
                xlsxCellLocal('', 9, 's'),
                xlsxCellLocal((float)$metric[1], 10, 'n')
            ], 17.0);
            $merges[] = 'A' . $rowRef . ':B' . $rowRef;
        } else {
            $addRow([
                xlsxCellLocal($index + 1, 7, 'n'),
                xlsxCellLocal((string)$metric[0], 6, 's'),
                xlsxCellLocal((float)$metric[1], 8, 'n')
            ], 17.0);
        }
    }

    $addRow([], 8.0);
    $addMergedTitleRow('Arrears Matrix', 4, 20.0);
    $addRow([
        xlsxCellLocal('S/N', 5, 's'),
        xlsxCellLocal('File No.', 5, 's'),
        xlsxCellLocal('Title', 5, 's'),
        xlsxCellLocal('Name', 5, 's'),
        xlsxCellLocal('Pension Arrears', 5, 's'),
        xlsxCellLocal('Gratuity Arrears', 5, 's'),
        xlsxCellLocal('Full Pension Arrears', 5, 's'),
        xlsxCellLocal('Pension & Gratuity', 5, 's'),
        xlsxCellLocal('Underpayment', 5, 's'),
        xlsxCellLocal('Total', 5, 's')
    ], 24.0);

    if (empty($payload['rows'])) {
        $emptyRow = $addRow([xlsxCellLocal('No arrears rows matched the current selection.', 6, 's')], 17.0);
        $merges[] = 'A' . $emptyRow . ':' . xlsxColNameLocal($maxCols - 1) . $emptyRow;
    } else {
        foreach ($payload['rows'] as $row) {
            $addRow([
                xlsxCellLocal((int)$row['sn'], 7, 'n'),
                xlsxCellLocal((string)$row['file_no'], 6, 's'),
                xlsxCellLocal((string)$row['title'], 3, 's'),
                xlsxCellLocal((string)$row['name'], 6, 's'),
                xlsxCellLocal((float)$row['pension_arrears'], 8, 'n'),
                xlsxCellLocal((float)$row['gratuity_arrears'], 8, 'n'),
                xlsxCellLocal((float)$row['full_pension_arrears'], 8, 'n'),
                xlsxCellLocal((float)$row['pension_gratuity'], 8, 'n'),
                xlsxCellLocal((float)$row['underpayment'], 8, 'n'),
                xlsxCellLocal((float)$row['total'], 8, 'n')
            ], 18.0);
        }
        $totalsRowRef = $addRow([
            xlsxCellLocal('TOTAL', 9, 's'),
            xlsxCellLocal('', 9, 's'),
            xlsxCellLocal('', 9, 's'),
            xlsxCellLocal('', 9, 's'),
            xlsxCellLocal((float)$payload['totals']['pension_arrears'], 10, 'n'),
            xlsxCellLocal((float)$payload['totals']['gratuity_arrears'], 10, 'n'),
            xlsxCellLocal((float)$payload['totals']['full_pension_arrears'], 10, 'n'),
            xlsxCellLocal((float)$payload['totals']['pension_gratuity'], 10, 'n'),
            xlsxCellLocal((float)$payload['totals']['underpayment'], 10, 'n'),
            xlsxCellLocal((float)$payload['totals']['total'], 10, 'n')
        ], 18.0);
        $merges[] = 'A' . $totalsRowRef . ':D' . $totalsRowRef;
    }

    $addRow([], 8.0);
    $addMergedTitleRow('Projection Summary', 4, 20.0);
    $addRow([xlsxCellLocal('S/N', 5, 's'), xlsxCellLocal('Projection Item', 5, 's'), xlsxCellLocal('Amount (UGX)', 5, 's')], 18.0);
    foreach ($payload['projection_rows'] as $index => $row) {
        $label = (string)$row[0];
        $isTotalRow = preg_match('/\\btotal\\b/i', $label) === 1;
        if ($isTotalRow) {
            $rowRef = $addRow([
                xlsxCellLocal($label, 9, 's'),
                xlsxCellLocal('', 9, 's'),
                xlsxCellLocal((float)$row[1], 10, 'n')
            ], 18.0);
            $merges[] = 'A' . $rowRef . ':B' . $rowRef;
        } else {
            $addRow([
                xlsxCellLocal($index + 1, 7, 'n'),
                xlsxCellLocal($label, 6, 's'),
                xlsxCellLocal((float)$row[1], 8, 'n')
            ], 18.0);
        }
    }

    $columnWidths = computeXlsxWidths($rows, $maxCols, $merges);
    return [
        'rows' => $rows,
        'merges' => $merges,
        'max_cols' => $maxCols,
        'column_widths' => $columnWidths,
        'orientation' => chooseXlsxOrientation($columnWidths, $maxCols, $rows)
    ];
}

function generateStyledXlsxBinaryLocal(array $report): string {
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
        $rowAttrs = ' r="' . $rowRef . '"';
        if ($height > 0) {
            $rowAttrs .= ' ht="' . number_format($height, 2, '.', '') . '" customHeight="1"';
        }
        $worksheetRowsXml .= '<row' . $rowAttrs . '>';
        $colIndex = 0;
        foreach ((array)($row['cells'] ?? []) as $cell) {
            $value = $cell['v'] ?? '';
            if ($value === null || $value === '') {
                $colIndex++;
                continue;
            }
            $styleId = max(0, (int)($cell['s'] ?? 0));
            $type = (string)($cell['t'] ?? 's');
            $ref = xlsxColNameLocal($colIndex) . $rowRef;
            $styleAttr = ' s="' . $styleId . '"';
            if ($type === 'n' && is_numeric($value)) {
                $worksheetRowsXml .= '<c r="' . $ref . '"' . $styleAttr . '><v>' . xlsxEscapeLocal((string)$value) . '</v></c>';
            } else {
                $worksheetRowsXml .= '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t>' . xlsxEscapeLocal((string)$value) . '</t></is></c>';
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
            $colsXml .= '<col min="' . $colPos . '" max="' . $colPos . '" width="' . number_format(max(8.0, (float)$width), 2, '.', '') . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    $mergeXml = '';
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $mergeRef) {
            $mergeXml .= '<mergeCell ref="' . xlsxEscapeLocal((string)$mergeRef) . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $lastColRef = xlsxColNameLocal($maxCols - 1);
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
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Budget Planning" sheetId="1" r:id="rId1"/></sheets>'
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
        . '<border><left style="thin"><color rgb="FFB44556"/></left><right style="thin"><color rgb="FFB44556"/></right><top style="thin"><color rgb="FFB44556"/></top><bottom style="thin"><color rgb="FFB44556"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="11">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
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

    $tmpFile = tempnam(sys_get_temp_dir(), 'budget_xlsx_');
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

function generateStyledPdfLocal(array $blocks, string $orientation = 'portrait', string $footerLabel = 'UPS PensionsGo Budget Planning Export'): string {
    return pgoRenderBlocksPdf($blocks, $orientation, [
        'title' => 'UPS PensionsGo - Budget Forecast & Arrears Planning Report',
        'footer' => $footerLabel,
    ]);
}

$payload = buildBudgetPayload($data, $generatedBy);

if ($format === 'csv') {
    while (ob_get_level() > $__baseBufferLevel) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="budget_planning_' . $timestamp . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['UPS PensionsGo - Budget Forecast & Arrears Planning Report']);
    fputcsv($out, ['Financial Year', $payload['selected_fy']]);
    fputcsv($out, ['Generated On', $payload['generated_at']]);
    fputcsv($out, ['Generated By', $payload['generated_by']]);
    if ($payload['selected_pensioner'] !== '') {
        fputcsv($out, ['Filter', $payload['selected_pensioner']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['S/N', 'Metric', 'Value (UGX)']);
    $summaryRows = [
        ['Pension Arrears', (float)($payload['totals']['pension_arrears'] ?? 0)],
        ['Gratuity Arrears', (float)($payload['totals']['gratuity_arrears'] ?? 0)],
        ['Full Pension Arrears', (float)($payload['totals']['full_pension_arrears'] ?? 0)],
        ['Pension & Gratuity', (float)($payload['totals']['pension_gratuity'] ?? 0)],
        ['Underpayment', (float)($payload['totals']['underpayment'] ?? 0)],
        ['Grand Total', (float)($payload['totals']['total'] ?? 0)]
    ];
    foreach ($summaryRows as $index => $row) {
        $isGrandTotal = strtolower(trim((string)$row[0])) === 'grand total';
        if ($isGrandTotal) {
            fputcsv($out, ['Grand Total', '', $row[1]]);
        } else {
            fputcsv($out, [$index + 1, $row[0], $row[1]]);
        }
    }
    fputcsv($out, []);
    fputcsv($out, ['S/N', 'File No.', 'Title', 'Name', 'Pension Arrears', 'Gratuity Arrears', 'Full Pension Arrears', 'Pension & Gratuity', 'Underpayment', 'Total']);
    foreach ($payload['rows'] as $row) {
        fputcsv($out, [$row['sn'], $row['file_no'], $row['title'], $row['name'], $row['pension_arrears'], $row['gratuity_arrears'], $row['full_pension_arrears'], $row['pension_gratuity'], $row['underpayment'], $row['total']]);
    }
    fputcsv($out, ['TOTAL', '', '', '', $payload['totals']['pension_arrears'], $payload['totals']['gratuity_arrears'], $payload['totals']['full_pension_arrears'], $payload['totals']['pension_gratuity'], $payload['totals']['underpayment'], $payload['totals']['total']]);
    fputcsv($out, []);
    fputcsv($out, ['S/N', 'Projection Item', 'Amount']);
    foreach ($payload['projection_rows'] as $index => $row) {
        $label = (string)$row[0];
        if (preg_match('/\\btotal\\b/i', $label) === 1) {
            fputcsv($out, [$label, '', $row[1]]);
        } else {
            fputcsv($out, [$index + 1, $label, $row[1]]);
        }
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    try {
        $xlsxBinary = generateStyledXlsxBinaryLocal(buildBudgetXlsxReport($payload));
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="budget_planning_' . $timestamp . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxBinary));
        echo $xlsxBinary;
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'XLSX export failed: ' . $e->getMessage();
        exit;
    }
}

if ($format === 'pdf') {
    try {
        $blocks = buildBudgetPdfBlocks($payload);
        $pdf = generateStyledPdfLocal($blocks, choosePdfOrientation($blocks));
        $download = strtolower(trim((string)($_GET['download'] ?? '')));
        $disposition = in_array($download, ['1', 'true', 'yes', 'download'], true) ? 'attachment' : 'inline';
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="budget_planning_' . $timestamp . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > $__baseBufferLevel) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'PDF export failed: ' . $e->getMessage();
        exit;
    }
}

while (ob_get_level() > $__baseBufferLevel) {
    ob_end_clean();
}
http_response_code(400);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Unsupported format. Use format=xlsx, format=csv, or format=pdf.';
exit;
