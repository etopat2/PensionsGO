<?php

if (!function_exists('pgoPdfPath')) {
    function pgoPdfPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/') . '/';
    }
}

if (!function_exists('pgoPdfEnsureDirectory')) {
    function pgoPdfEnsureDirectory(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        return $path;
    }
}

if (!function_exists('pgoPdfBrandImagePath')) {
    function pgoPdfBrandImagePath(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $projectRoot = dirname(__DIR__, 2);
        $candidates = [
            $projectRoot . '/frontend/assets/logo.png',
            $projectRoot . '/favicon.png',
            $projectRoot . '/favicon.ico'
        ];

        $canRenderRaster = extension_loaded('gd') || extension_loaded('imagick');
        foreach ($candidates as $candidate) {
            if (!is_file($candidate) || filesize($candidate) <= 0) {
                continue;
            }
            $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true) && !$canRenderRaster) {
                continue;
            }
            if ($extension === 'ico') {
                continue;
            }
            if (is_file($candidate) && filesize($candidate) > 0) {
                return $resolved = $candidate;
            }
        }

        return $resolved = '';
    }
}

$pgoTcpdfMainPath = pgoPdfPath(__DIR__ . '/tcpdf');
$pgoTcpdfFontPath = pgoPdfPath(__DIR__ . '/tcpdf/fonts');
$pgoTcpdfCachePath = pgoPdfPath(pgoPdfEnsureDirectory(__DIR__ . '/../runtime/tcpdf-cache'));

if (!defined('K_PATH_MAIN')) {
    define('K_PATH_MAIN', $pgoTcpdfMainPath);
}
if (!defined('K_PATH_FONTS')) {
    define('K_PATH_FONTS', $pgoTcpdfFontPath);
}
if (!defined('K_PATH_CACHE')) {
    define('K_PATH_CACHE', $pgoTcpdfCachePath);
}
if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
}

require_once __DIR__ . '/tcpdf/tcpdf.php';

if (!function_exists('pgoPdfEscapeHtml')) {
    function pgoPdfEscapeHtml($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('pgoPdfNormalizeOrientation')) {
    function pgoPdfNormalizeOrientation(string $orientation): string
    {
        $orientation = strtolower(trim($orientation));
        return in_array($orientation, ['landscape', 'l'], true) ? 'L' : 'P';
    }
}

if (!function_exists('pgoPdfTextLength')) {
    function pgoPdfTextLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

if (!function_exists('pgoPdfLongestWordLength')) {
    function pgoPdfLongestWordLength(string $value): int
    {
        $longest = 0;
        $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $longest = max($longest, pgoPdfTextLength((string)$word));
        }
        return $longest;
    }
}

if (!function_exists('pgoPdfFontCandidates')) {
    function pgoPdfFontCandidates(bool $bold = false): array
    {
        $fileName = $bold ? 'tahomabd.ttf' : 'tahoma.ttf';
        $windir = getenv('WINDIR') ?: 'C:\\Windows';
        return array_values(array_unique(array_filter([
            __DIR__ . '/../assets/fonts/' . $fileName,
            $windir . DIRECTORY_SEPARATOR . 'Fonts' . DIRECTORY_SEPARATOR . $fileName,
            'C:\\Windows\\Fonts\\' . $fileName
        ], static function ($path) {
            return is_string($path) && $path !== '';
        })));
    }
}

if (!function_exists('pgoPdfBundledTahomaFontName')) {
    function pgoPdfBundledTahomaFontName(bool $bold = false): string
    {
        $fontName = $bold ? 'tahomabd' : 'tahoma';
        return is_file(K_PATH_FONTS . $fontName . '.php') ? $fontName : '';
    }
}

if (!function_exists('pgoPdfRegisterTahomaFont')) {
    function pgoPdfRegisterTahomaFont(string $variant = 'regular'): string
    {
        static $resolved = [];
        $key = strtolower(trim($variant)) === 'bold' ? 'bold' : 'regular';
        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        $isBold = $key === 'bold';
        $bundledFont = pgoPdfBundledTahomaFontName($isBold);
        if ($bundledFont !== '') {
            return $resolved[$key] = $bundledFont;
        }

        foreach (pgoPdfFontCandidates($isBold) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $added = TCPDF_FONTS::addTTFfont($candidate, 'TrueTypeUnicode', '', 96, K_PATH_FONTS);
            if (is_string($added) && trim($added) !== '') {
                return $resolved[$key] = trim($added);
            }

            $base = strtolower(pathinfo($candidate, PATHINFO_FILENAME));
            if (is_file(K_PATH_FONTS . $base . '.php')) {
                return $resolved[$key] = $base;
            }
        }

        return $resolved[$key] = $isBold ? 'helvetica' : 'helvetica';
    }
}

if (!function_exists('pgoPdfHtmlVSpaceMap')) {
    function pgoPdfHtmlVSpaceMap(): array
    {
        return [
            'div' => [
                0 => ['h' => 0, 'n' => 0],
                1 => ['h' => 0, 'n' => 0]
            ],
            'p' => [
                0 => ['h' => 0, 'n' => 0],
                1 => ['h' => 0, 'n' => 0]
            ],
            'table' => [
                0 => ['h' => 0, 'n' => 0],
                1 => ['h' => 0, 'n' => 0]
            ]
        ];
    }
}

if (!class_exists('PensionsGoTcpdf')) {
    class PensionsGoTcpdf extends TCPDF
    {
        public string $pgoFooterLabel = 'UPS PensionsGo Export';
        public string $pgoHeaderLabel = 'UPS PensionsGo Export';
        public string $pgoRegularFont = 'helvetica';
        public string $pgoBoldFont = 'helvetica';
        public string $pgoBrandLogoPath = '';

        public function Header(): void
        {
            $pageWidth = $this->getPageWidth();
            $left = $this->lMargin;
            $right = $this->rMargin;
            $usableWidth = max(40.0, $pageWidth - $left - $right);

            $this->SetAutoPageBreak(false, 0);
            $this->SetFillColor(116, 26, 45);
            $this->Rect(0, 0, $pageWidth, 16.5, 'F');
            $this->SetFillColor(214, 166, 74);
            $this->Rect(0, 16.5, $pageWidth, 1.2, 'F');

            $logoPath = $this->pgoBrandLogoPath;
            $textX = $left;
            if ($logoPath !== '' && is_file($logoPath)) {
                try {
                    $this->Image($logoPath, $left, 3.1, 9.6, 9.6, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    $textX += 12.0;
                } catch (Throwable $error) {
                    // Keep the header resilient even if the logo cannot be rendered.
                }
            }

            $this->SetTextColor(255, 255, 255);
            $this->SetFont($this->pgoBoldFont, '', 10.4);
            $this->SetXY($textX, 3.2);
            $this->Cell(max(30.0, $usableWidth - max(0.0, $textX - $left)), 4.6, 'UPS PensionsGo', 0, 1, 'L', false, '', 0, false, 'T', 'M');

            $this->SetTextColor(255, 245, 230);
            $this->SetFont($this->pgoRegularFont, '', 7.9);
            $this->SetXY($textX, 8.6);
            $this->Cell(max(20.0, $usableWidth * 0.68), 3.8, 'Pension administration analytics and records exports', 0, 0, 'L', false, '', 0, false, 'T', 'M');

            $this->SetFont($this->pgoBoldFont, '', 7.7);
            $this->SetXY($pageWidth - $right - 72, 4.0);
            $this->Cell(72, 3.8, $this->pgoHeaderLabel, 0, 1, 'R', false, '', 0, false, 'T', 'M');

            $this->SetFont($this->pgoRegularFont, '', 7.4);
            $this->SetXY($pageWidth - $right - 72, 8.7);
            $this->Cell(72, 3.5, 'Generated ' . date('d M Y H:i'), 0, 1, 'R', false, '', 0, false, 'T', 'M');
            $this->SetAutoPageBreak(true, 16);
        }

        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetDrawColor(174, 53, 69);
            $this->SetLineWidth(0.35);
            $lineY = $this->GetY();
            $this->Line($this->lMargin, $lineY, $this->w - $this->rMargin, $lineY);
            $this->SetY(-9.5);
            $this->SetTextColor(116, 26, 45);
            $this->SetFont($this->pgoRegularFont, '', 8.0);
            $this->Cell(0, 4, $this->pgoFooterLabel, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $this->SetFont($this->pgoBoldFont, '', 8.0);
            $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        }
    }
}

if (!function_exists('pgoPdfColumnWidths')) {
    function pgoPdfColumnWidths(array $widths): array
    {
        $minimum = 0.5;
        $sum = 0.0;
        foreach ($widths as $width) {
            $sum += max($minimum, (float)$width);
        }
        if ($sum <= 0) {
            $sum = 1.0;
        }
        $output = [];
        foreach ($widths as $width) {
            $output[] = number_format((max($minimum, (float)$width) / $sum) * 100, 4, '.', '');
        }
        return $output;
    }
}

if (!function_exists('pgoPdfLooksLikeSerialHeader')) {
    function pgoPdfLooksLikeSerialHeader(array $cells): bool
    {
        $firstHeader = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)($cells[0] ?? '')));
        return in_array($firstHeader, ['sn', 'sno', 'serial', 'serialno', 'serialnumber'], true);
    }
}

if (!function_exists('pgoPdfTableWidthPercents')) {
    function pgoPdfTableWidthPercents(array $headerBlock): array
    {
        $widthPercents = pgoPdfColumnWidths((array)($headerBlock['widths'] ?? []));
        $cells = array_values((array)($headerBlock['cells'] ?? []));
        if (!pgoPdfLooksLikeSerialHeader($cells) || count($widthPercents) < 2) {
            return $widthPercents;
        }

        $serialWidth = 4.0;
        $currentSerial = max(0.0, (float)($widthPercents[0] ?? 0));
        if ($currentSerial >= 3.4 && $currentSerial <= 5.2) {
            return $widthPercents;
        }

        $restTotal = 0.0;
        for ($i = 1, $count = count($widthPercents); $i < $count; $i += 1) {
            $restTotal += max(0.01, (float)$widthPercents[$i]);
        }
        $widthPercents[0] = number_format($serialWidth, 4, '.', '');
        for ($i = 1, $count = count($widthPercents); $i < $count; $i += 1) {
            $widthPercents[$i] = number_format((max(0.01, (float)$widthPercents[$i]) / $restTotal) * (100.0 - $serialWidth), 4, '.', '');
        }
        return $widthPercents;
    }
}

if (!function_exists('pgoPdfOrientationForTable')) {
    function pgoPdfOrientationForTable(array $headerBlock, array $rowBlocks): string
    {
        $sampleRows = array_slice($rowBlocks, 0, 50);
        pgoPdfEnsureSerialColumn($headerBlock, $sampleRows);
        $headerCells = array_values((array)($headerBlock['cells'] ?? []));
        $widthPercents = pgoPdfTableWidthPercents($headerBlock);
        $columnCount = count($headerCells);
        if ($columnCount <= 0) {
            return 'P';
        }

        $portraitUsable = 210.0 - 24.0;
        $desiredTotal = 0.0;
        $narrowColumnPressure = 0;
        foreach ($headerCells as $index => $label) {
            $label = (string)$label;
            $longestWord = pgoPdfLongestWordLength($label);
            $averageLength = pgoPdfTextLength($label);
            foreach ($sampleRows as $rowBlock) {
                $cells = array_values((array)($rowBlock['cells'] ?? []));
                $value = trim((string)($cells[$index] ?? ''));
                if ($value === '') {
                    continue;
                }
                $longestWord = max($longestWord, pgoPdfLongestWordLength($value));
                $averageLength = max($averageLength, min(80, pgoPdfTextLength($value)));
            }

            $isSerial = $index === 0 && pgoPdfLooksLikeSerialHeader($headerCells);
            $desiredWidth = $isSerial ? 8.0 : max(14.0, min(54.0, ($longestWord * 2.15) + min(14.0, $averageLength / 7)));
            $actualPortraitWidth = $portraitUsable * ((float)($widthPercents[$index] ?? 0) / 100);
            if (!$isSerial && $actualPortraitWidth < min($desiredWidth * 0.82, 26.0)) {
                $narrowColumnPressure++;
            }
            $desiredTotal += $desiredWidth;
        }

        if ($columnCount >= 8 || $desiredTotal > ($portraitUsable * 0.98) || $narrowColumnPressure >= 1) {
            return 'L';
        }
        return 'P';
    }
}

if (!function_exists('pgoPdfAutoOrientation')) {
    function pgoPdfAutoOrientation(array $blocks, string $requested = 'portrait'): string
    {
        $requested = strtolower(trim($requested));
        if (!in_array($requested, ['auto', 'smart', ''], true)) {
            return pgoPdfNormalizeOrientation($requested);
        }

        $headerBlock = null;
        $rowBlocks = [];
        $wideTables = 0;
        $tableCount = 0;
        $flush = static function () use (&$headerBlock, &$rowBlocks, &$wideTables, &$tableCount): void {
            if (is_array($headerBlock)) {
                $tableCount++;
                if (pgoPdfOrientationForTable($headerBlock, $rowBlocks) === 'L') {
                    $wideTables++;
                }
            }
            $headerBlock = null;
            $rowBlocks = [];
        };

        foreach ($blocks as $block) {
            $block = (array)$block;
            $style = (string)($block['style'] ?? '');
            if ($style === 'grid_header') {
                $flush();
                $headerBlock = $block;
                continue;
            }
            if (in_array($style, ['grid_row', 'grid_total', 'grid_subtotal'], true) && is_array($headerBlock)) {
                if (count($rowBlocks) < 50) {
                    $rowBlocks[] = $block;
                }
                continue;
            }
            $flush();
        }
        $flush();

        return $wideTables > 0 ? 'L' : 'P';
    }
}

if (!function_exists('pgoPdfNormalizeMergeSpans')) {
    function pgoPdfNormalizeMergeSpans(array $block): array
    {
        $spans = [];
        if (isset($block['merge_lead']) && (int)$block['merge_lead'] > 1) {
            $spans[] = [0, ((int)$block['merge_lead']) - 1];
        }
        foreach ((array)($block['merge'] ?? []) as $merge) {
            if (!is_array($merge) || count($merge) < 2) {
                continue;
            }
            $start = (int)$merge[0];
            $end = (int)$merge[1];
            if ($end >= $start) {
                $spans[] = [$start, $end];
            }
        }
        return $spans;
    }
}

if (!function_exists('pgoPdfRenderTableRowHtml')) {
    function pgoPdfRenderTableRowHtml(array $block, array $widthPercents, bool $header = false, int $rowIndex = 0): string
    {
        $cells = array_values((array)($block['cells'] ?? []));
        $aligns = array_values((array)($block['aligns'] ?? []));
        $spans = pgoPdfNormalizeMergeSpans($block);
        $mergeStarts = [];
        $covered = [];
        foreach ($spans as [$start, $end]) {
            $mergeStarts[$start] = $end;
            for ($index = $start + 1; $index <= $end; $index += 1) {
                $covered[$index] = true;
            }
        }

        $tag = $header ? 'th' : 'td';
        $rowClasses = ['pgo-grid-row'];
        $style = (string)($block['style'] ?? '');
        if ($style === 'grid_total') {
            $rowClasses[] = 'is-total';
        } elseif ($style === 'grid_subtotal') {
            $rowClasses[] = 'is-subtotal';
        }
        if (!empty($block['bold'])) {
            $rowClasses[] = 'is-bold';
        }
        if (!$header) {
            $rowClasses[] = ($rowIndex % 2 === 0) ? 'is-even' : 'is-odd';
        }

        $rowAttributes = ' class="' . implode(' ', $rowClasses) . '"';
        if ($header) {
            $rowAttributes .= ' style="background-color:#741a2d;color:#ffffff;page-break-inside:avoid;" bgcolor="#741a2d"';
        } else {
            $rowAttributes .= ' nobr="true" style="page-break-inside:avoid;"';
        }
        $html = '<tr' . $rowAttributes . '>';
        $count = count($cells);
        for ($i = 0; $i < $count; $i += 1) {
            if (isset($covered[$i])) {
                continue;
            }
            $colspan = 1;
            if (isset($mergeStarts[$i])) {
                $colspan = ($mergeStarts[$i] - $i) + 1;
            }
            $width = 0.0;
            for ($w = $i; $w < min($count, $i + $colspan); $w += 1) {
                $width += (float)($widthPercents[$w] ?? 0);
            }
            $align = strtolower(trim((string)($aligns[$i] ?? ($header ? 'center' : 'left'))));
            if (!in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                $align = $header ? 'center' : 'left';
            }
            $cellClasses = ['align-' . $align];
            if (!empty($block['bold'])) {
                $cellClasses[] = 'is-bold';
            }
            $cellStyle = 'width:' . number_format($width, 4, '.', '') . '%; padding-left: 5px; padding-right: 5px; padding-top: 3px; padding-bottom: 3px; white-space: normal; overflow-wrap: normal; word-break: normal;';
            if ($header) {
                $cellStyle .= ' color:#ffffff; font-weight:bold; background-color:#741a2d;';
            }
            $attributes = ' class="' . implode(' ', $cellClasses) . '" style="' . $cellStyle . '"';
            if ($colspan > 1) {
                $attributes .= ' colspan="' . $colspan . '"';
            }
            $html .= '<' . $tag . $attributes . '>' . nl2br(pgoPdfEscapeHtml((string)($cells[$i] ?? ''))) . '</' . $tag . '>';
        }
        $html .= '</tr>';
        return $html;
    }
}

if (!function_exists('pgoPdfEnsureSerialColumn')) {
    function pgoPdfEnsureSerialColumn(array &$headerBlock, array &$rowBlocks): void
    {
        $headerCells = array_values((array)($headerBlock['cells'] ?? []));
        if (pgoPdfLooksLikeSerialHeader($headerCells)) {
            foreach ($rowBlocks as $index => $rowBlock) {
                $row = (array)$rowBlock;
                $cells = array_values((array)($row['cells'] ?? []));
                if (isset($cells[0]) && preg_match('/^\d+$/', trim((string)$cells[0])) === 1) {
                    continue;
                }
                array_unshift($cells, (string)($index + 1));
                $row['cells'] = $cells;
                $rowWidths = array_values((array)($row['widths'] ?? []));
                array_unshift($rowWidths, 0.55);
                $row['widths'] = $rowWidths;
                $rowAligns = array_values((array)($row['aligns'] ?? []));
                array_unshift($rowAligns, 'center');
                $row['aligns'] = $rowAligns;
                $rowBlocks[$index] = $row;
            }
            return;
        }

        array_unshift($headerCells, 'S/N');
        $headerBlock['cells'] = $headerCells;
        $headerBlock['_pgo_serial_added'] = true;

        $widths = array_values((array)($headerBlock['widths'] ?? []));
        array_unshift($widths, 0.55);
        $headerBlock['widths'] = $widths;

        $aligns = array_values((array)($headerBlock['aligns'] ?? []));
        array_unshift($aligns, 'center');
        $headerBlock['aligns'] = $aligns;

        foreach ($rowBlocks as $index => $rowBlock) {
            $row = (array)$rowBlock;
            $cells = array_values((array)($row['cells'] ?? []));
            array_unshift($cells, (string)($index + 1));
            $row['cells'] = $cells;

            $rowWidths = array_values((array)($row['widths'] ?? []));
            array_unshift($rowWidths, 0.55);
            $row['widths'] = $rowWidths;

            $rowAligns = array_values((array)($row['aligns'] ?? []));
            array_unshift($rowAligns, 'center');
            $row['aligns'] = $rowAligns;

            $rowBlocks[$index] = $row;
        }
    }
}

if (!function_exists('pgoPdfBuildTableHtml')) {
    function pgoPdfBuildTableHtml(array $headerBlock, array $rowBlocks, array $widthPercents, int $rowOffset = 0): string
    {
        $html = '<table class="pgo-grid" cellspacing="0" cellpadding="0"><colgroup>';
        foreach ($widthPercents as $width) {
            $html .= '<col style="width:' . $width . '%;" />';
        }
        $html .= '</colgroup><thead>';
        $html .= pgoPdfRenderTableRowHtml($headerBlock, $widthPercents, true, 0);
        $html .= '</thead><tbody>';
        foreach ($rowBlocks as $rowIndex => $rowBlock) {
            $html .= pgoPdfRenderTableRowHtml((array)$rowBlock, $widthPercents, false, $rowOffset + (int)$rowIndex);
        }
        $html .= '</tbody></table>';
        return $html;
    }
}

if (!function_exists('pgoPdfFlushBufferedTableHtml')) {
    function pgoPdfFlushBufferedTableHtml(?array &$headerBlock, array &$rowBlocks, bool $preserveHeader = false): string
    {
        if (!is_array($headerBlock)) {
            $rowBlocks = [];
            return '';
        }

        pgoPdfEnsureSerialColumn($headerBlock, $rowBlocks);
        $widthPercents = pgoPdfTableWidthPercents($headerBlock);
        $html = pgoPdfBuildTableHtml($headerBlock, $rowBlocks, $widthPercents);

        if (!$preserveHeader) {
            $headerBlock = null;
        }
        $rowBlocks = [];
        return $html;
    }
}

if (!function_exists('pgoPdfBlocksCss')) {
    function pgoPdfBlocksCss(string $regularFont, string $boldFont): string
    {
        $regular = preg_replace('/[^a-z0-9_\-]/i', '', $regularFont) ?: 'helvetica';
        $bold = preg_replace('/[^a-z0-9_\-]/i', '', $boldFont) ?: $regular;
        return '
            body { font-family: ' . $regular . '; color: #1f2937; font-size: 12pt; line-height: 1.25; }
            .pgo-report { display: block; }
            .pgo-title {
                font-family: ' . $bold . ';
                font-size: 12pt;
                color: #741a2d;
                padding: 0 0 2px 0;
                margin: 0;
            }
            .pgo-meta {
                font-family: ' . $regular . ';
                font-size: 12pt;
                color: #374151;
                margin: 0;
                padding: 0 0 1px 0;
                line-height: 1.2;
            }
            .pgo-section {
                font-family: ' . $bold . ';
                font-size: 12pt;
                color: #741a2d;
                margin: 0;
                padding: 1px 0 2px 0;
                padding-bottom: 2px;
                border-bottom: 1px solid #d7dce5;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-subsection {
                font-family: ' . $bold . ';
                font-size: 12pt;
                color: #152033;
                margin: 0;
                padding: 0 0 1px 0;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-group-header {
                font-family: ' . $bold . ';
                font-size: 12pt;
                color: #741a2d;
                background-color: #fff4cc;
                border: 1px solid #b44556;
                padding: 3px 6px;
                margin: 0;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-detail, .pgo-row {
                display: block;
                font-family: ' . $regular . ';
                font-size: 12pt;
                color: #1f2937;
                margin: 0;
                padding: 0;
                text-align: justify;
                line-height: 1.25;
            }
            .pgo-detail + .pgo-detail,
            .pgo-row + .pgo-row {
                margin-top: 0;
            }
            .pgo-detail.is-bullet { margin-left: 8px; }
            .pgo-section + .pgo-detail,
            .pgo-section + .pgo-row,
            .pgo-section + .pgo-grid,
            .pgo-subsection + .pgo-detail,
            .pgo-subsection + .pgo-row,
            .pgo-subsection + .pgo-grid,
            .pgo-group-header + .pgo-detail,
            .pgo-group-header + .pgo-row,
            .pgo-group-header + .pgo-grid {
                page-break-before: avoid;
            }
            .pgo-spacer { height: 1px; }
            table.pgo-grid {
                width: 100%;
                border-collapse: collapse;
                margin-top: 1px;
                margin-bottom: 4px;
                page-break-inside: auto;
            }
            .pgo-grid th, .pgo-grid td {
                border: 1px solid #b44556;
                padding: 3px 5px;
                vertical-align: top;
                font-size: 12pt;
                overflow-wrap: normal;
                word-break: normal;
                white-space: normal;
                line-height: 1.2;
                page-break-inside: avoid;
            }
            .pgo-grid thead tr {
                background-color: #741a2d;
                color: #ffffff;
                page-break-inside: avoid;
            }
            .pgo-grid tbody tr {
                page-break-inside: avoid;
            }
            .pgo-grid th {
                font-family: ' . $bold . ';
                font-weight: bold;
                color: inherit;
                border-top: 1.2px solid #d6a64a;
                border-bottom: 1.2px solid #d6a64a;
            }
            .pgo-grid .align-left { text-align: left; }
            .pgo-grid .align-center { text-align: center; }
            .pgo-grid .align-right { text-align: right; }
            .pgo-grid .align-justify { text-align: justify; }
            .pgo-grid tr.is-even td { background-color: #fffdf8; }
            .pgo-grid tr.is-odd td { background-color: #fff8eb; }
            .pgo-grid tr.is-total td {
                background-color: #ffe4a8;
                font-family: ' . $bold . ';
            }
            .pgo-grid tr.is-subtotal td {
                background-color: #fff4cc;
                font-family: ' . $bold . ';
            }
            .pgo-grid tr.is-bold td {
                font-family: ' . $bold . ';
            }
            .pgo-bullet {
                font-family: ' . $bold . ';
                font-weight: bold;
                color: #741a2d;
            }
        ';
    }
}

if (!function_exists('pgoPdfFormatParagraphHtml')) {
    function pgoPdfFormatParagraphHtml(string $text): string
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^\s*(?:[-*]|\x{2022})\s*(.+)$/us', $normalized, $matches) === 1) {
            $body = nl2br(pgoPdfEscapeHtml(trim((string)($matches[1] ?? ''))));
            return '<span class="pgo-bullet">&#8226;</span>&nbsp;&nbsp;' . $body;
        }

        return nl2br(pgoPdfEscapeHtml($normalized));
    }
}

if (!function_exists('pgoPdfShouldSkipBlock')) {
    function pgoPdfShouldSkipBlock(array $block): bool
    {
        $style = strtolower(trim((string)($block['style'] ?? '')));
        if ($style !== 'meta') {
            return false;
        }

        $text = trim((string)($block['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        return preg_match('/^generated(?:\\s+on)?\\s*:/i', $text) === 1;
    }
}

if (!function_exists('pgoPdfBlocksToHtml')) {
    function pgoPdfBlocksToHtml(array $blocks, string $regularFont, string $boldFont): string
    {
        $html = '<style>' . pgoPdfBlocksCss($regularFont, $boldFont) . '</style><div class="pgo-report">';
        $headerBlock = null;
        $rowBlocks = [];

        $flush = static function () use (&$headerBlock, &$rowBlocks): string {
            return pgoPdfFlushBufferedTableHtml($headerBlock, $rowBlocks);
        };

        foreach ($blocks as $block) {
            if (pgoPdfShouldSkipBlock((array)$block)) {
                continue;
            }
            $style = (string)($block['style'] ?? 'detail');
            if ($style === 'grid_header') {
                $html .= $flush();
                $headerBlock = $block;
                $rowBlocks = [];
                continue;
            }
            if (in_array($style, ['grid_row', 'grid_total', 'grid_subtotal'], true) && is_array($headerBlock)) {
                $rowBlocks[] = $block;
                continue;
            }

            $html .= $flush();
            $text = nl2br(pgoPdfEscapeHtml((string)($block['text'] ?? '')));
            switch ($style) {
                case 'title':
                    $html .= '<div class="pgo-title">' . $text . '</div>';
                    break;
                case 'meta':
                    $html .= '<div class="pgo-meta">' . $text . '</div>';
                    break;
                case 'section':
                    $html .= '<div class="pgo-section">' . $text . '</div>';
                    break;
                case 'subsection':
                    $html .= '<div class="pgo-subsection">' . $text . '</div>';
                    break;
                case 'group_header':
                    $html .= '<div class="pgo-group-header">' . $text . '</div>';
                    break;
                case 'spacer':
                    $html .= '<div class="pgo-spacer"></div>';
                    break;
                case 'detail':
                    $detailClass = preg_match('/^\s*(?:[-*]|\x{2022})/u', (string)($block['text'] ?? '')) ? 'pgo-detail is-bullet' : 'pgo-detail';
                    $html .= '<div class="' . $detailClass . '">' . pgoPdfFormatParagraphHtml((string)($block['text'] ?? '')) . '</div>';
                    break;
                default:
                    $html .= '<div class="pgo-row">' . pgoPdfFormatParagraphHtml((string)($block['text'] ?? '')) . '</div>';
                    break;
            }
        }

        $html .= $flush();
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('pgoPdfWriteHtmlFragment')) {
    function pgoPdfWriteHtmlFragment(TCPDF $pdf, string $fragmentHtml, string $regularFont, string $boldFont): void
    {
        $fragmentHtml = trim($fragmentHtml);
        if ($fragmentHtml === '') {
            return;
        }

        $pdf->writeHTML(
            '<style>' . pgoPdfBlocksCss($regularFont, $boldFont) . '</style><div class="pgo-report">' . $fragmentHtml . '</div>',
            true,
            false,
            true,
            false,
            ''
        );
    }
}

if (!function_exists('pgoPdfEstimateTextLines')) {
    function pgoPdfEstimateTextLines(string $text, float $cellWidthMm): int
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return 1;
        }
        $capacity = max(5, (int)floor($cellWidthMm / 1.15));
        $lines = 1;
        $lineLength = 0;
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            $length = function_exists('mb_strlen') ? mb_strlen((string)$word) : strlen((string)$word);
            $nextLength = $lineLength === 0 ? $length : ($lineLength + 1 + $length);
            if ($lineLength > 0 && $nextLength > $capacity) {
                $lines++;
                $lineLength = $length;
            } else {
                $lineLength = $nextLength;
            }
        }
        return max(1, $lines);
    }
}

if (!function_exists('pgoPdfEstimateTableRowHeight')) {
    function pgoPdfEstimateTableRowHeight(array $block, array $widthPercents, float $usableWidthMm, bool $header = false): float
    {
        $cells = array_values((array)($block['cells'] ?? []));
        $maxLines = 1;
        foreach ($cells as $index => $cell) {
            $cellWidth = max(8.0, $usableWidthMm * ((float)($widthPercents[$index] ?? 0) / 100));
            $maxLines = max($maxLines, pgoPdfEstimateTextLines((string)$cell, $cellWidth - 2.0));
        }
        $lineHeight = $header ? 5.15 : 5.1;
        return max($header ? 8.2 : 7.8, ($maxLines * $lineHeight) + 3.8);
    }
}

if (!function_exists('pgoPdfDirectTableAlign')) {
    function pgoPdfDirectTableAlign(string $align): string
    {
        $align = strtolower(trim($align));
        if ($align === 'right') {
            return 'R';
        }
        if ($align === 'center') {
            return 'C';
        }
        if ($align === 'justify') {
            return 'J';
        }
        return 'L';
    }
}

if (!function_exists('pgoPdfDirectRowCells')) {
    function pgoPdfDirectRowCells(array $block, array $widthPercents, float $usableWidth): array
    {
        $cells = array_values((array)($block['cells'] ?? []));
        $aligns = array_values((array)($block['aligns'] ?? []));
        $spans = pgoPdfNormalizeMergeSpans($block);
        $mergeStarts = [];
        $covered = [];
        foreach ($spans as [$start, $end]) {
            $mergeStarts[$start] = $end;
            for ($index = $start + 1; $index <= $end; $index += 1) {
                $covered[$index] = true;
            }
        }

        $rowCells = [];
        $count = count($cells);
        for ($i = 0; $i < $count; $i += 1) {
            if (isset($covered[$i])) {
                continue;
            }
            $colspan = isset($mergeStarts[$i]) ? (($mergeStarts[$i] - $i) + 1) : 1;
            $width = 0.0;
            for ($w = $i; $w < min($count, $i + $colspan); $w += 1) {
                $width += $usableWidth * ((float)($widthPercents[$w] ?? 0) / 100);
            }
            $rowCells[] = [
                'text' => (string)($cells[$i] ?? ''),
                'width' => max(5.0, $width),
                'align' => pgoPdfDirectTableAlign((string)($aligns[$i] ?? 'left'))
            ];
        }
        return $rowCells;
    }
}

if (!function_exists('pgoPdfUnbrokenTokens')) {
    function pgoPdfUnbrokenTokens(string $value): array
    {
        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

if (!function_exists('pgoPdfMeasureTextWidth')) {
    function pgoPdfMeasureTextWidth(TCPDF $pdf, string $text, string $font, bool $bold = false): float
    {
        $pdf->SetFont($font, $bold ? 'B' : '', 12);
        return (float)$pdf->GetStringWidth($text);
    }
}

if (!function_exists('pgoPdfOptimizedTableWidthPercents')) {
    function pgoPdfOptimizedTableWidthPercents(TCPDF $pdf, array $headerBlock, array $rowBlocks, float $usableWidth, string $regularFont, string $boldFont): array
    {
        $basePercents = array_map('floatval', pgoPdfTableWidthPercents($headerBlock));
        $headers = array_values((array)($headerBlock['cells'] ?? []));
        $columnCount = count($headers);
        if ($columnCount <= 0) {
            return $basePercents;
        }

        $samples = array_slice($rowBlocks, 0, 1000);
        $minimums = [];
        $desired = [];
        $pressure = [];
        $isSerialTable = pgoPdfLooksLikeSerialHeader($headers);

        for ($column = 0; $column < $columnCount; $column += 1) {
            $label = trim((string)($headers[$column] ?? ''));
            $isSerial = $isSerialTable && $column === 0;
            $longestTokenWidth = $isSerial ? pgoPdfMeasureTextWidth($pdf, '9999', $regularFont, false) : 0.0;
            $headerWidth = pgoPdfMeasureTextWidth($pdf, $label, $boldFont, true);
            $maxFullWidth = $headerWidth;
            $totalFullWidth = $headerWidth;
            $sampleCount = 1;

            foreach (pgoPdfUnbrokenTokens($label) as $token) {
                $longestTokenWidth = max($longestTokenWidth, pgoPdfMeasureTextWidth($pdf, (string)$token, $boldFont, true));
            }

            foreach ($samples as $rowBlock) {
                $cells = array_values((array)(((array)$rowBlock)['cells'] ?? []));
                $value = trim((string)($cells[$column] ?? ''));
                if ($value === '') {
                    continue;
                }
                $fullWidth = pgoPdfMeasureTextWidth($pdf, $value, $regularFont, false);
                $maxFullWidth = max($maxFullWidth, $fullWidth);
                $totalFullWidth += min($fullWidth, 70.0);
                $sampleCount++;
                foreach (pgoPdfUnbrokenTokens($value) as $token) {
                    $longestTokenWidth = max($longestTokenWidth, pgoPdfMeasureTextWidth($pdf, (string)$token, $regularFont, false));
                }
            }

            $averageWidth = $totalFullWidth / max(1, $sampleCount);
            $baseWidth = $usableWidth * (($basePercents[$column] ?? 0.0) / 100.0);
            $minimum = $isSerial ? 10.5 : min(54.0, max(10.0, $longestTokenWidth + 5.0));
            $target = $isSerial
                ? 11.0
                : max($minimum, min(58.0, max($baseWidth * 0.74, $headerWidth + 5.5, $averageWidth + 4.5)));

            $minimums[$column] = $minimum;
            $desired[$column] = $target;
            $pressure[$column] = $isSerial ? 0.1 : max(0.1, ($maxFullWidth + $headerWidth) / max(1.0, $target));
        }

        $minimumTotal = array_sum($minimums);
        if ($minimumTotal >= $usableWidth) {
            $scale = $usableWidth / max(1.0, $minimumTotal);
            return array_map(static function (float $width) use ($scale, $usableWidth): string {
                return number_format((($width * $scale) / $usableWidth) * 100.0, 4, '.', '');
            }, $minimums);
        }

        $desiredTotal = array_sum($desired);
        $widths = $desired;
        if ($desiredTotal > $usableWidth) {
            $shrinkableTotal = 0.0;
            foreach ($widths as $column => $width) {
                $shrinkableTotal += max(0.0, $width - $minimums[$column]);
            }
            $excess = $desiredTotal - $usableWidth;
            foreach ($widths as $column => $width) {
                $shrinkable = max(0.0, $width - $minimums[$column]);
                $widths[$column] = $width - ($shrinkableTotal > 0 ? ($excess * ($shrinkable / $shrinkableTotal)) : 0.0);
            }
        } else {
            $remaining = $usableWidth - $desiredTotal;
            $pressureTotal = array_sum($pressure);
            foreach ($widths as $column => $width) {
                $widths[$column] = $width + ($pressureTotal > 0 ? ($remaining * ($pressure[$column] / $pressureTotal)) : 0.0);
            }
        }

        $sum = array_sum($widths) ?: 1.0;
        return array_map(static function (float $width) use ($sum): string {
            return number_format(($width / $sum) * 100.0, 4, '.', '');
        }, $widths);
    }
}

if (!function_exists('pgoPdfDirectRowHeight')) {
    function pgoPdfDirectRowHeight(TCPDF $pdf, array $rowCells, bool $header = false): float
    {
        $maxLines = 1;
        foreach ($rowCells as $cell) {
            $maxLines = max($maxLines, (int)$pdf->getNumLines((string)$cell['text'], max(5.0, (float)$cell['width'] - 3.0)));
        }
        $lineHeight = $header ? 4.8 : 4.7;
        return max($header ? 8.0 : 7.2, ($maxLines * $lineHeight) + 2.4);
    }
}

if (!function_exists('pgoPdfDrawDirectTableRow')) {
    function pgoPdfDrawDirectTableRow(TCPDF $pdf, array $rowCells, float $height, bool $header = false, bool $fill = false, bool $bold = false): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        if ($header) {
            $pdf->SetFillColor(116, 26, 45);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetDrawColor(180, 69, 86);
        } else {
            $pdf->SetFillColor($fill ? 255 : 255, $fill ? 248 : 253, $fill ? 235 : 248);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->SetDrawColor(180, 69, 86);
        }
        $pdf->SetLineWidth(0.12);
        foreach ($rowCells as $cell) {
            $pdf->MultiCell(
                (float)$cell['width'],
                $height,
                (string)$cell['text'],
                1,
                (string)$cell['align'],
                true,
                0,
                $x,
                $y,
                true,
                0,
                false,
                true,
                $height,
                'M',
                false
            );
            $x += (float)$cell['width'];
        }
        $pdf->SetXY($pdf->getMargins()['left'], $y + $height);
        if ($header) {
            $pdf->SetTextColor(31, 41, 55);
        }
    }
}

if (!function_exists('pgoPdfWriteBufferedTableToDocument')) {
    function pgoPdfWriteBufferedTableToDocument(TCPDF $pdf, ?array &$headerBlock, array &$rowBlocks, string $regularFont, string $boldFont, bool $preserveHeader = false): void
    {
        if (!is_array($headerBlock)) {
            $rowBlocks = [];
            return;
        }

        pgoPdfEnsureSerialColumn($headerBlock, $rowBlocks);
        if ($rowBlocks === []) {
            $tableHtml = pgoPdfFlushBufferedTableHtml($headerBlock, $rowBlocks, $preserveHeader);
            if ($tableHtml !== '') {
                pgoPdfWriteHtmlFragment($pdf, $tableHtml, $regularFont, $boldFont);
            }
            return;
        }

        $margins = $pdf->getMargins();
        $usableWidth = max(40.0, $pdf->getPageWidth() - (float)($margins['left'] ?? 12) - (float)($margins['right'] ?? 12));
        $widthPercents = pgoPdfOptimizedTableWidthPercents($pdf, $headerBlock, $rowBlocks, $usableWidth, $regularFont, $boldFont);
        $pageBottom = $pdf->getPageHeight() - $pdf->getBreakMargin();
        $headerCells = pgoPdfDirectRowCells($headerBlock, $widthPercents, $usableWidth);
        $pdf->SetFont($boldFont, 'B', 12);
        $headerHeight = pgoPdfDirectRowHeight($pdf, $headerCells, true);

        $writeHeader = static function () use ($pdf, $headerCells, $headerHeight, $boldFont): void {
            $pdf->SetFont($boldFont, 'B', 12);
            pgoPdfDrawDirectTableRow($pdf, $headerCells, $headerHeight, true, true, true);
        };

        if (($pageBottom - $pdf->GetY()) < ($headerHeight + 10.0)) {
            $pdf->AddPage();
        }
        $writeHeader();

        foreach ($rowBlocks as $index => $rowBlock) {
            $rowBlock = (array)$rowBlock;
            $rowCells = pgoPdfDirectRowCells($rowBlock, $widthPercents, $usableWidth);
            $pdf->SetFont(!empty($rowBlock['bold']) ? $boldFont : $regularFont, !empty($rowBlock['bold']) ? 'B' : '', 12);
            $rowHeight = pgoPdfDirectRowHeight($pdf, $rowCells, false);
            $remaining = $pageBottom - $pdf->GetY();
            if ($remaining < ($rowHeight + 1.5)) {
                $pdf->AddPage();
                $writeHeader();
            }
            $pdf->SetFont(!empty($rowBlock['bold']) ? $boldFont : $regularFont, !empty($rowBlock['bold']) ? 'B' : '', 12);
            pgoPdfDrawDirectTableRow($pdf, $rowCells, $rowHeight, false, ((int)$index % 2) === 0, !empty($rowBlock['bold']));
        }

        if (!$preserveHeader) {
            $headerBlock = null;
        }
        $rowBlocks = [];
    }
}

if (!function_exists('pgoPdfWriteBlocksToDocument')) {
    function pgoPdfWriteBlocksToDocument(TCPDF $pdf, array $blocks, string $regularFont, string $boldFont, int $tableChunkSize = 24): void
    {
        $headerBlock = null;
        $rowBlocks = [];
        $fragmentBuffer = '';

        $flushFragments = static function () use (&$fragmentBuffer, $pdf, $regularFont, $boldFont): void {
            $html = trim($fragmentBuffer);
            if ($html === '') {
                $fragmentBuffer = '';
                return;
            }

            pgoPdfWriteHtmlFragment($pdf, $html, $regularFont, $boldFont);
            $fragmentBuffer = '';
        };

        $flushTable = static function (bool $preserveHeader = false) use (&$headerBlock, &$rowBlocks, $flushFragments, $pdf, $regularFont, $boldFont): void {
            $flushFragments();
            pgoPdfWriteBufferedTableToDocument($pdf, $headerBlock, $rowBlocks, $regularFont, $boldFont, $preserveHeader);
        };

        foreach ($blocks as $block) {
            $block = (array)$block;
            if (pgoPdfShouldSkipBlock($block)) {
                continue;
            }

            $style = (string)($block['style'] ?? 'detail');
            if ($style === 'grid_header') {
                $flushTable(false);
                $headerBlock = $block;
                $rowBlocks = [];
                continue;
            }

            if (in_array($style, ['grid_row', 'grid_total', 'grid_subtotal'], true) && is_array($headerBlock)) {
                $rowBlocks[] = $block;
                if (!empty($headerBlock['allow_chunking']) && $tableChunkSize > 0 && count($rowBlocks) >= max(8, $tableChunkSize)) {
                    $flushTable(true);
                }
                continue;
            }

            $flushTable(false);
            $text = nl2br(pgoPdfEscapeHtml((string)($block['text'] ?? '')));
            $fragmentHtml = '';
            switch ($style) {
                case 'title':
                    $fragmentHtml = '<div class="pgo-title">' . $text . '</div>';
                    break;
                case 'meta':
                    $fragmentHtml = '<div class="pgo-meta">' . $text . '</div>';
                    break;
                case 'section':
                    $fragmentHtml = '<div class="pgo-section">' . $text . '</div>';
                    break;
                case 'subsection':
                    $fragmentHtml = '<div class="pgo-subsection">' . $text . '</div>';
                    break;
                case 'group_header':
                    $fragmentHtml = '<div class="pgo-group-header">' . $text . '</div>';
                    break;
                case 'spacer':
                    $fragmentHtml = '<div class="pgo-spacer"></div>';
                    break;
                case 'detail':
                    $detailClass = preg_match('/^\s*(?:[-*]|\x{2022})/u', (string)($block['text'] ?? '')) ? 'pgo-detail is-bullet' : 'pgo-detail';
                    $fragmentHtml = '<div class="' . $detailClass . '">' . pgoPdfFormatParagraphHtml((string)($block['text'] ?? '')) . '</div>';
                    break;
                default:
                    $fragmentHtml = '<div class="pgo-row">' . pgoPdfFormatParagraphHtml((string)($block['text'] ?? '')) . '</div>';
                    break;
            }
            $fragmentBuffer .= $fragmentHtml;
        }

        $flushTable(false);
        $flushFragments();
    }
}

if (!function_exists('pgoRenderBlocksPdf')) {
    function pgoRenderBlocksPdf(array $blocks, string $orientation = 'portrait', array $options = []): string
    {
        $regularFont = pgoPdfRegisterTahomaFont('regular');
        $boldFont = pgoPdfRegisterTahomaFont('bold');
        $title = trim((string)($options['title'] ?? 'UPS PensionsGo Report'));
        $footer = trim((string)($options['footer'] ?? $title));
        $brandLogo = trim((string)($options['logo'] ?? pgoPdfBrandImagePath()));

        $resolvedOrientation = pgoPdfAutoOrientation($blocks, $orientation);
        $pdf = new PensionsGoTcpdf($resolvedOrientation, 'mm', 'A4', true, 'UTF-8', false);
        $pdf->pgoFooterLabel = $footer !== '' ? $footer : 'UPS PensionsGo Export';
        $pdf->pgoHeaderLabel = $title !== '' ? $title : 'UPS PensionsGo Report';
        $pdf->pgoRegularFont = $regularFont;
        $pdf->pgoBoldFont = $boldFont;
        $pdf->pgoBrandLogoPath = $brandLogo;
        $pdf->SetCreator('UPS PensionsGo');
        $pdf->SetAuthor('UPS PensionsGo');
        $pdf->SetTitle($title !== '' ? $title : 'UPS PensionsGo Report');
        $pdf->SetSubject($title !== '' ? $title : 'UPS PensionsGo Report');
        $pdf->SetMargins(12, 22, 12);
        $pdf->SetHeaderMargin(4);
        $pdf->SetFooterMargin(14);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->SetCompression(true);
        $pdf->setFontSubsetting(false);
        $pdf->setCellHeightRatio(0.95);
        $pdf->setHtmlVSpace(pgoPdfHtmlVSpaceMap());
        $pdf->SetPrintHeader(true);
        $pdf->SetPrintFooter(true);
        $pdf->AddPage();
        $pdf->SetFont($regularFont, '', 12);
        pgoPdfWriteBlocksToDocument($pdf, $blocks, $regularFont, $boldFont);
        return $pdf->Output('', 'S');
    }
}
