<?php

/** Shared, dependency-free XLSX writer for every upload template. */
function uploadTemplateColumnName(int $number): string
{
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function uploadTemplateXml($value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function buildUploadTemplateXlsx(array $headers, array $rows, string $sheetName = 'Upload Template'): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel templates.');
    }
    if (!$headers) {
        throw new RuntimeException('The upload template requires at least one heading.');
    }

    $sheetName = preg_replace('~[\\\\/?*\[\]:]~', ' ', trim($sheetName)) ?: 'Upload Template';
    $sheetName = function_exists('mb_substr') ? mb_substr($sheetName, 0, 31) : substr($sheetName, 0, 31);
    $lastColumn = uploadTemplateColumnName(count($headers));
    $sheetRows = '';
    $headerCells = '';
    foreach (array_values($headers) as $index => $heading) {
        $ref = uploadTemplateColumnName($index + 1) . '1';
        $headerCells .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . uploadTemplateXml($heading) . '</t></is></c>';
    }
    $sheetRows .= '<row r="1" ht="30" customHeight="1">' . $headerCells . '</row>';

    foreach (array_values($rows) as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        $cells = '';
        foreach (array_values($row) as $columnIndex => $value) {
            if ($columnIndex >= count($headers) || $value === '' || $value === null) continue;
            $ref = uploadTemplateColumnName($columnIndex + 1) . $excelRow;
            if (is_int($value) || is_float($value)) {
                $cells .= '<c r="' . $ref . '"><v>' . uploadTemplateXml($value) . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . uploadTemplateXml($value) . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . $excelRow . '">' . $cells . '</row>';
    }

    $columnsXml = '';
    foreach (array_values($headers) as $index => $heading) {
        $width = min(34, max(14, strlen((string)$heading) + 4));
        $number = $index + 1;
        $columnsXml .= '<col min="' . $number . '" max="' . $number . '" width="' . $width . '" customWidth="1"/>';
    }
    $lastRow = max(2, count($rows) + 1);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:' . $lastColumn . $lastRow . '"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' . $columnsXml . '</cols><sheetData>' . $sheetRows . '</sheetData><autoFilter ref="A1:' . $lastColumn . $lastRow . '"/></worksheet>';

    $path = tempnam(sys_get_temp_dir(), 'upload_template_');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($path);
        throw new RuntimeException('Unable to create the Excel template.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . uploadTemplateXml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF244764"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0" fillId="0" borderId="0" xfId="0"/><xf fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $path;
}

function sendUploadTemplateXlsx(array $headers, array $rows, string $sheetName, string $filename): void
{
    $path = buildUploadTemplateXlsx($headers, $rows, $sheetName);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    @unlink($path);
}
