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
            mkdir($path, 0777, true);
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
        return strtolower(trim($orientation)) === 'landscape' ? 'L' : 'P';
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
        $sum = 0.0;
        foreach ($widths as $width) {
            $sum += max(1.0, (float)$width);
        }
        if ($sum <= 0) {
            $sum = 1.0;
        }
        $output = [];
        foreach ($widths as $width) {
            $output[] = number_format((max(1.0, (float)$width) / $sum) * 100, 4, '.', '');
        }
        return $output;
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

        $html = '<tr class="' . implode(' ', $rowClasses) . '">';
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
            $attributes = ' class="' . implode(' ', $cellClasses) . '" style="width:' . number_format($width, 4, '.', '') . '%; padding-left: 10px; padding-right: 10px; padding-top: 6px; padding-bottom: 6px;"';
            if ($colspan > 1) {
                $attributes .= ' colspan="' . $colspan . '"';
            }
            $html .= '<' . $tag . $attributes . '>' . nl2br(pgoPdfEscapeHtml((string)($cells[$i] ?? ''))) . '</' . $tag . '>';
        }
        $html .= '</tr>';
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

        $widthPercents = pgoPdfColumnWidths((array)($headerBlock['widths'] ?? []));
        $html = '<table class="pgo-grid" cellspacing="0" cellpadding="6"><colgroup>';
        foreach ($widthPercents as $width) {
            $html .= '<col style="width:' . $width . '%;" />';
        }
        $html .= '</colgroup><thead>';
        $html .= pgoPdfRenderTableRowHtml($headerBlock, $widthPercents, true, 0);
        $html .= '</thead><tbody>';
        foreach ($rowBlocks as $rowIndex => $rowBlock) {
            $html .= pgoPdfRenderTableRowHtml((array)$rowBlock, $widthPercents, false, (int)$rowIndex);
        }
        $html .= '</tbody></table>';

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
            body { font-family: ' . $regular . '; color: #1f2937; font-size: 9.6pt; line-height: 1.5; }
            .pgo-report { display: block; }
            .pgo-title {
                font-family: ' . $bold . ';
                font-size: 16pt;
                color: #ffffff;
                background-color: #741a2d;
                padding: 10px 12px;
                margin: 0;
                border-radius: 8px;
            }
            .pgo-meta {
                font-family: ' . $regular . ';
                font-size: 9pt;
                color: #374151;
                margin: 0;
                padding: 0;
                line-height: 1.5;
            }
            .pgo-section {
                font-family: ' . $bold . ';
                font-size: 12pt;
                color: #741a2d;
                margin: 0;
                padding: 2px 0 4px 0;
                padding-bottom: 4px;
                border-bottom: 1px solid #d7dce5;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-subsection {
                font-family: ' . $bold . ';
                font-size: 10pt;
                color: #152033;
                margin: 0;
                padding: 1px 0 2px 0;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-group-header {
                font-family: ' . $bold . ';
                font-size: 9.3pt;
                color: #741a2d;
                background-color: #fff4cc;
                border: 1px solid #b44556;
                padding: 6px 8px;
                margin: 0;
                page-break-inside: avoid;
                page-break-after: avoid;
            }
            .pgo-detail, .pgo-row {
                display: block;
                font-family: ' . $regular . ';
                font-size: 9.4pt;
                color: #1f2937;
                margin: 0;
                padding: 0;
                text-align: justify;
                line-height: 1.5;
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
            .pgo-spacer { height: 4px; }
            table.pgo-grid {
                width: 100%;
                border-collapse: collapse;
                margin-top: 4px;
                margin-bottom: 6px;
                page-break-inside: auto;
            }
            .pgo-grid th, .pgo-grid td {
                border: 1px solid #b44556;
                padding: 5px 9px;
                vertical-align: top;
                font-size: 8.8pt;
                overflow-wrap: anywhere;
                word-break: break-word;
                line-height: 1.5;
                page-break-inside: avoid;
            }
            .pgo-grid th {
                font-family: ' . $bold . ';
                font-weight: bold;
                background-color: #fff4cc;
                color: #741a2d;
                border-top: 1.2px solid #8f2338;
                border-bottom: 1.2px solid #8f2338;
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
            $tableHtml = pgoPdfFlushBufferedTableHtml($headerBlock, $rowBlocks, $preserveHeader);
            if ($tableHtml !== '') {
                pgoPdfWriteHtmlFragment($pdf, $tableHtml, $regularFont, $boldFont);
            }
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
                if (count($rowBlocks) >= max(8, $tableChunkSize)) {
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

        $pdf = new PensionsGoTcpdf(pgoPdfNormalizeOrientation($orientation), 'mm', 'A4', true, 'UTF-8', false);
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
        $pdf->setFontSubsetting(true);
        $pdf->setCellHeightRatio(1.0);
        $pdf->setHtmlVSpace(pgoPdfHtmlVSpaceMap());
        $pdf->SetPrintHeader(true);
        $pdf->SetPrintFooter(true);
        $pdf->AddPage();
        $pdf->SetFont($regularFont, '', 9.6);
        pgoPdfWriteBlocksToDocument($pdf, $blocks, $regularFont, $boldFont);
        return $pdf->Output('', 'S');
    }
}
