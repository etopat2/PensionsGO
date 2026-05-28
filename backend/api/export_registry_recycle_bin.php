<?php
require_once __DIR__ . '/registry_recycle_common.php';
require_once __DIR__ . '/../lib/pdf_library.php';

function rrCol(int $i): string { $n=''; $i++; while($i>0){$m=($i-1)%26; $n=chr(65+$m).$n; $i=(int)(($i-$m-1)/26);} return $n; }
function rrXml(string $v): string { if(function_exists('mb_convert_encoding')){$c=@mb_convert_encoding($v,'UTF-8','UTF-8'); if($c!==false){$v=$c;}} $v=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u','',$v) ?? ''; return htmlspecialchars($v, ENT_XML1|ENT_QUOTES, 'UTF-8'); }
function rrLen(string $v): int { return function_exists('mb_strlen') ? (int)mb_strlen($v,'UTF-8') : strlen($v); }
function rrPdfEsc(string $v): string { $v=str_replace('\\','\\\\',$v); $v=str_replace('(','\\(',$v); $v=str_replace(')','\\)',$v); return preg_replace('/[\x00-\x1F\x7F]/u','',$v) ?? ''; }
function rrWrap(string $text, int $max): array { $clean=preg_replace('/\s+/u',' ',trim($text)) ?? ''; if($clean===''){ return ['']; } $words=preg_split('/\s+/u',$clean) ?: [$clean]; $max=max(1,$max); $lines=[]; $current=''; foreach($words as $word){ $candidate=$current===''?$word:($current.' '.$word); if(rrLen($candidate)<=$max){ $current=$candidate; } else { if($current!==''){$lines[]=$current;} $current=$word; } } if($current!==''){$lines[]=$current;} return $lines ?: [$clean]; }
function rrTextW(string $t,float $size): float { return max(0,rrLen($t))*($size*0.52); }
function rrPdfText(string $font,float $size,float $x,float $y,string $txt,float $r,float $g,float $b): string { return "BT\n/$font ".number_format($size,2,'.','')." Tf\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n1 0 0 1 ".number_format($x,2,'.','').' '.number_format($y,2,'.','')." Tm\n(".rrPdfEsc($txt).") Tj\nET\n"; }
function rrFill(float $x,float $y,float $w,float $h,float $r,float $g,float $b): string { return "q\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re f\nQ\n"; }
function rrStroke(float $x,float $y,float $w,float $h,float $r,float $g,float $b,float $lw=0.5): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re S\nQ\n"; }
function rrLine(float $x1,float $y1,float $x2,float $y2,float $r,float $g,float $b,float $lw=0.35): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x1,2,'.','').' '.number_format($y1,2,'.','')." m\n".number_format($x2,2,'.','').' '.number_format($y2,2,'.','')." l S\nQ\n"; }

function rrFormatRole(mysqli $conn, string $role): string
{
    $roleKey = strtolower(trim($role));
    if ($roleKey === '') {
        return '';
    }
    return getRoleLabel($conn, $roleKey) ?: ucwords(str_replace('_', ' ', $roleKey));
}

function rrExportPayload(mysqli $conn, array $rows, array $filters, string $generatedBy): array
{
    $headers = [
        'sn' => 'S/N',
        'file_number' => 'File Number',
        'title' => 'Title',
        'name' => 'Name',
        'delete_mode' => 'Delete Mode',
        'status' => 'Delete Status',
        'reason' => 'Delete Reason',
        'deleted_by' => 'Deleted By',
        'deleted_role' => 'Deleted By Role',
        'deleted_at' => 'Deleted At',
        'restored_by' => 'Restored By',
        'restored_role' => 'Restored By Role',
        'restored_at' => 'Restored At'
    ];

    $dataRows = [];
    $sampleLengths = [];
    $serial = 1;
    foreach ($rows as $row) {
        $record = [
            'sn' => (string)$serial++,
            'file_number' => (string)($row['regNo'] ?? ''),
            'title' => (string)($row['staff_title'] ?? ''),
            'name' => (string)($row['staff_name'] ?? ''),
            'delete_mode' => (string)($row['delete_mode'] ?? ''),
            'status' => !empty($row['restored']) ? 'Restored' : 'Deleted',
            'reason' => (string)($row['delete_reason'] ?? ''),
            'deleted_by' => (string)($row['deleted_by_name'] ?? ''),
            'deleted_role' => rrFormatRole($conn, (string)($row['deleted_by_role'] ?? '')),
            'deleted_at' => (string)($row['deleted_at'] ?? ''),
            'restored_by' => (string)($row['restored_by_name'] ?? ''),
            'restored_role' => rrFormatRole($conn, (string)($row['restored_by_role'] ?? '')),
            'restored_at' => (string)($row['restored_at'] ?? '')
        ];
        foreach ($record as $key => $value) {
            $sampleLengths[$key] = max($sampleLengths[$key] ?? rrLen($headers[$key] ?? ''), rrLen((string)$value));
        }
        $dataRows[] = $record;
    }

    $metaLines = [];
    if (!empty($filters['search'])) {
        $metaLines[] = 'Search: ' . $filters['search'];
    }
    if (($filters['state'] ?? 'all') !== 'all') {
        $metaLines[] = 'State: ' . ucfirst((string)$filters['state']);
    }
    if (!empty($filters['actor_role'])) {
        $metaLines[] = 'Deleted By Role: ' . rrFormatRole($conn, (string)$filters['actor_role']);
    }
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $metaLines[] = 'Deleted Between: ' . (($filters['date_from'] ?: 'Start')) . ' to ' . (($filters['date_to'] ?: 'Today'));
    }

    return [
        'title' => 'UPS PensionsGo - Registry Recycle Bin Report',
        'sheet_name' => 'Registry Recycle Bin',
        'headers' => $headers,
        'rows' => $dataRows,
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $generatedBy,
        'sample_lengths' => $sampleLengths,
        'meta_lines' => $metaLines
    ];
}

function rrBuildXlsxReport(array $payload): array
{
    $headers = $payload['headers'];
    $rows = $payload['rows'];
    $sheetRows = [];
    $merges = [];
    $rowNumber = 1;
    $maxCols = count($headers);
    $add = function (array $cells, ?float $height = null) use (&$sheetRows, &$rowNumber): int {
        $current = $rowNumber;
        $row = ['r' => $rowNumber, 'cells' => $cells];
        if ($height !== null && $height > 0) {
            $row['h'] = $height;
        }
        $sheetRows[] = $row;
        $rowNumber += 1;
        return $current;
    };

    $titleRow = $add([['v' => $payload['title'], 's' => 1, 't' => 's']], 26.0);
    $merges[] = 'A' . $titleRow . ':' . rrCol($maxCols - 1) . $titleRow;
    $metaOne = $add([['v' => 'Generated On', 's' => 2, 't' => 's'], ['v' => $payload['generated_at'], 's' => 3, 't' => 's']], 18.0);
    $merges[] = 'B' . $metaOne . ':' . rrCol($maxCols - 1) . $metaOne;
    $metaTwo = $add([['v' => 'Generated By', 's' => 2, 't' => 's'], ['v' => $payload['generated_by'], 's' => 3, 't' => 's']], 18.0);
    $merges[] = 'B' . $metaTwo . ':' . rrCol($maxCols - 1) . $metaTwo;

    foreach ((array)($payload['meta_lines'] ?? []) as $metaLine) {
        $metaRow = $add([['v' => 'Filters', 's' => 2, 't' => 's'], ['v' => (string)$metaLine, 's' => 3, 't' => 's']], 18.0);
        $merges[] = 'B' . $metaRow . ':' . rrCol($maxCols - 1) . $metaRow;
    }

    $add([], 6.0);
    $headerCells = [];
    foreach ($headers as $header) {
        $headerCells[] = ['v' => $header, 's' => 5, 't' => 's'];
    }
    $add($headerCells, 21.0);

    foreach ($rows as $row) {
        $cells = [];
        foreach ($row as $key => $value) {
            if ($key === 'sn') {
                $cells[] = ['v' => (int)$value, 's' => 7, 't' => 'n'];
            } else {
                $cells[] = ['v' => (string)$value, 's' => 6, 't' => 's'];
            }
        }
        $add($cells, 19.0);
    }

    $widths = array_fill(0, $maxCols, 10.0);
    foreach ($sheetRows as $row) {
        $rowRef = (int)($row['r'] ?? 0);
        foreach ((array)($row['cells'] ?? []) as $index => $cell) {
            if (($rowRef <= 3 && $index > 1) || ($rowRef === 1 && $index > 0)) {
                continue;
            }
            $raw = trim((string)($cell['v'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $longest = 0;
            foreach (preg_split('/\R/u', $raw) ?: [$raw] as $line) {
                $longest = max($longest, rrLen(trim((string)$line)));
            }
            $widths[$index] = max($widths[$index], min(34.0, max(8.0, $longest + 2.0)));
        }
    }

    return [
        'rows' => $sheetRows,
        'merges' => $merges,
        'max_cols' => $maxCols,
        'column_widths' => $widths,
        'orientation' => ($maxCols > 8 || array_sum($widths) > 120) ? 'landscape' : 'portrait'
    ];
}

function rrXlsxBinary(array $report, string $sheetName): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required for XLSX export.');
    }

    $rows = (array)($report['rows'] ?? []);
    $merges = (array)($report['merges'] ?? []);
    $maxCols = max(1, (int)($report['max_cols'] ?? 1));
    $widths = (array)($report['column_widths'] ?? []);
    $orientation = strtolower((string)($report['orientation'] ?? 'portrait')) === 'landscape' ? 'landscape' : 'portrait';
    $lastCol = rrCol($maxCols - 1);
    $lastRow = 1;
    $rowsXml = '';

    foreach ($rows as $row) {
        $rowRef = (int)($row['r'] ?? ($lastRow + 1));
        $lastRow = max($lastRow, $rowRef);
        $height = (float)($row['h'] ?? 0);
        $attrs = ' r="' . $rowRef . '"';
        if ($height > 0) {
            $attrs .= ' ht="' . number_format($height, 2, '.', '') . '" customHeight="1"';
        }
        $rowsXml .= '<row' . $attrs . '>';
        foreach ((array)($row['cells'] ?? []) as $index => $cell) {
            $value = $cell['v'] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            $ref = rrCol($index) . $rowRef;
            $style = max(0, (int)($cell['s'] ?? 0));
            $type = (string)($cell['t'] ?? 's');
            if ($type === 'n' && is_numeric($value)) {
                $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . rrXml((string)$value) . '</v></c>';
            } else {
                $rowsXml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . rrXml((string)$value) . '</t></is></c>';
            }
        }
        $rowsXml .= '</row>';
    }

    $colsXml = '<cols>';
    foreach ($widths as $index => $width) {
        $col = $index + 1;
        $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . number_format(max(8.0, (float)$width), 2, '.', '') . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $mergeXml = '';
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $merge) {
            $mergeXml .= '<mergeCell ref="' . rrXml((string)$merge) . '"/>';
        }
        $mergeXml .= '</mergeCells>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:' . $lastCol . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/>' . $colsXml . '<sheetData>' . $rowsXml . '</sheetData>' . $mergeXml . '<printOptions horizontalCentered="0" verticalCentered="0" headings="0" gridLines="0"/><pageMargins left="0.30" right="0.30" top="0.35" bottom="0.35" header="0.30" footer="0.30"/><pageSetup paperSize="9" orientation="' . $orientation . '" fitToWidth="1" fitToHeight="1"/></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><color rgb="FF1F2937"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FF111827"/><name val="Tahoma"/><family val="2"/></font></fonts><fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF741A2D"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF4CC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFA32A3E"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8F4F5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB44556"/></left><right style="thin"><color rgb="FFB44556"/></right><top style="thin"><color rgb="FFB44556"/></top><bottom style="thin"><color rgb="FFB44556"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $safe = trim($sheetName) !== '' ? str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', trim($sheetName)) : 'Recycle Bin';
    $safe = trim((string)$safe);
    if ($safe === '') {
        $safe = 'Recycle Bin';
    }
    if (rrLen($safe) > 31) {
        $safe = function_exists('mb_substr') ? mb_substr($safe, 0, 31, 'UTF-8') : substr($safe, 0, 31);
    }

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . rrXml($safe) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $tmp = tempnam(sys_get_temp_dir(), 'registry_recycle_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Failed to allocate temp file for XLSX export.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Failed to create XLSX archive.');
    }

    $zip->addFromString('[Content_Types].xml', $types);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $binary = (string)file_get_contents($tmp);
    @unlink($tmp);
    if ($binary === '') {
        throw new RuntimeException('Generated XLSX file is empty.');
    }
    return $binary;
}

function rrPdfBlocks(array $payload): array
{
    $blocks = [
        ['style' => 'title', 'text' => $payload['title']],
        ['style' => 'meta', 'text' => 'Generated: ' . $payload['generated_at']],
        ['style' => 'meta', 'text' => 'Generated By: ' . $payload['generated_by']],
        ['style' => 'meta', 'text' => 'Records: ' . count($payload['rows'])]
    ];
    foreach ((array)($payload['meta_lines'] ?? []) as $metaLine) {
        $blocks[] = ['style' => 'meta', 'text' => (string)$metaLine];
    }
    $blocks[] = ['style' => 'spacer', 'text' => ''];
    $blocks[] = ['style' => 'section', 'text' => 'Recycle Bin Records'];

    $keys = array_keys($payload['headers']);
    $labels = array_values($payload['headers']);
    $widths = [4, 10, 8, 14, 10, 9, 16, 12, 10, 12, 12, 10, 12];
    $aligns = ['center', 'left', 'left', 'left', 'center', 'center', 'left', 'left', 'center', 'center', 'left', 'center', 'center'];
    $blocks[] = ['style' => 'grid_header', 'cells' => $labels, 'widths' => $widths, 'aligns' => $aligns];

    if (empty($payload['rows'])) {
        $blocks[] = ['style' => 'detail', 'text' => 'No recycle bin records matched the selected filters.'];
        return $blocks;
    }

    foreach ($payload['rows'] as $row) {
        $cells = [];
        foreach ($keys as $key) {
            $cells[] = (string)($row[$key] ?? '');
        }
        $blocks[] = ['style' => 'grid_row', 'cells' => $cells, 'widths' => $widths, 'aligns' => $aligns];
    }
    return $blocks;
}

function rrPdfBinary(array $blocks, string $orientation = 'landscape', string $footer = 'UPS PensionsGo Registry Recycle Bin Report'): string
{
    return pgoRenderBlocksPdf($blocks, $orientation, [
        'title' => $footer,
        'footer' => $footer,
    ]);
}

function rrSend(string $binary, string $name, string $contentType): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $download = strtolower(trim((string)($_GET['download'] ?? $_POST['download'] ?? '')));
    $disposition = in_array($download, ['1', 'true', 'yes', 'download'], true) ? 'attachment' : 'inline';
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($binary));
    header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Transfer-Encoding: binary');
    echo $binary;
}

try {
    $actor = registryRecycleActorContext($conn, false);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_GET;
    }

    $format = strtolower(trim((string)($payload['format'] ?? 'xlsx')));
    if (!in_array($format, ['xlsx', 'pdf', 'csv'], true)) {
        $format = 'xlsx';
    }

    $filters = registryRecycleNormalizeFilters($payload);
    $rows = registryRecycleFetchAllRows($conn, $filters);
    $export = rrExportPayload($conn, $rows, $filters, $actor['user_name']);
    $stamp = date('Ymd_His');
    $base = 'registry_recycle_bin_' . $stamp;

    if ($format === 'csv') {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, [$export['title']]);
        fputcsv($handle, ['Generated On', $export['generated_at']]);
        fputcsv($handle, ['Generated By', $export['generated_by']]);
        foreach ((array)($export['meta_lines'] ?? []) as $metaLine) {
            fputcsv($handle, ['Filters', (string)$metaLine]);
        }
        fputcsv($handle, []);
        fputcsv($handle, array_values($export['headers']));
        foreach ($export['rows'] as $row) {
            fputcsv($handle, array_values($row));
        }
        rewind($handle);
        $binary = (string)stream_get_contents($handle);
        fclose($handle);

        logAuditEvent($conn, [
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role'],
            'action' => 'registry_recycle_bin_exported',
            'entity_type' => 'tb_file_registry_recycle_bin',
            'entity_id' => 'recycle_bin',
            'details' => ['format' => 'csv', 'row_count' => count($export['rows'])]
        ]);

        rrSend($binary, $base . '.csv', 'text/csv; charset=utf-8');
        exit;
    }

    if ($format === 'pdf') {
        $binary = rrPdfBinary(rrPdfBlocks($export), 'landscape', $export['title']);
        logAuditEvent($conn, [
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role'],
            'action' => 'registry_recycle_bin_exported',
            'entity_type' => 'tb_file_registry_recycle_bin',
            'entity_id' => 'recycle_bin',
            'details' => ['format' => 'pdf', 'row_count' => count($export['rows'])]
        ]);
        rrSend($binary, $base . '.pdf', 'application/pdf');
        exit;
    }

    $binary = rrXlsxBinary(rrBuildXlsxReport($export), $export['sheet_name']);
    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'registry_recycle_bin_exported',
        'entity_type' => 'tb_file_registry_recycle_bin',
        'entity_id' => 'recycle_bin',
        'details' => ['format' => 'xlsx', 'row_count' => count($export['rows'])]
    ]);
    rrSend($binary, $base . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
