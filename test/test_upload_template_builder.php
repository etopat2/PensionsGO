<?php

ob_start();

require_once __DIR__ . '/../backend/api/xlsx_upload_template.php';
require_once __DIR__ . '/../backend/api/import_common.php';
require_once __DIR__ . '/../backend/api/gratuity_schedule_common.php';

$cases = [
    'Registry' => [['Pension Number', 'First Name', 'Middle Name', 'Last Name'], ['PEN/A/1', 'Jane', 'Mary', 'Doe']],
    'Claims' => [['Pension Number', 'Claim Type', 'Expected Amount'], ['PEN/A/1', 'Pension Arrears', '200000']],
    'Arrears Payments' => [['Supplier Number', 'Claim Type', 'Amount'], ['SUP-1', 'Pension Arrears', '1450000']],
    'Suspension' => [['Pension Number', 'Supplier Number', 'Beneficiary Name', 'Amount'], ['PEN/A/1', 'SUP-1', 'Jane Doe', '850000']],
    'Gratuity' => [['Pension Number', 'Supplier Number', 'Beneficiary Name', 'Scheduled Amount'], ['PEN/A/1', 'SUP-1', 'Jane Doe', '1450000']],
    'Payroll' => [['Supplier Number', 'Beneficiary Name', 'Amount'], ['SUP-1', 'Jane Doe', '1450000']],
];

foreach ($cases as $name => [$headers, $sample]) {
    $path = buildUploadTemplateXlsx($headers, [$sample], $name . ' Upload');
    try {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException("{$name}: invalid XLSX ZIP package");
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $member) {
            $xml = $zip->getFromName($member);
            if ($xml === false || simplexml_load_string($xml) === false) throw new RuntimeException("{$name}: invalid {$member}");
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (strpos($sheet, 'state="frozen"') === false || strpos($sheet, '<autoFilter ') === false) {
            throw new RuntimeException("{$name}: missing frozen heading or filter formatting");
        }
        $zip->close();

        $parsed = importParseXlsxRows($path);
        if (($parsed[0] ?? []) !== $headers) throw new RuntimeException("{$name}: headings changed during XLSX parsing");
        if (($parsed[1] ?? []) !== $sample) throw new RuntimeException("{$name}: sample row changed during XLSX parsing");
        echo "PASS {$name}\n";
    } finally {
        @unlink($path);
    }
}

$gratuityPath = buildUploadTemplateXlsx($cases['Gratuity'][0], [$cases['Gratuity'][1]], 'Gratuity Upload');
try {
    $normalized = parseGratuityScheduleUploadRows($gratuityPath, 'xlsx');
    $imported = $normalized['rows'][0] ?? [];
    if (($imported['regNo'] ?? '') !== 'PEN/A/1' || ($imported['scheduled_amount'] ?? '') != '1450000') {
        throw new RuntimeException('Gratuity importer did not accept the formatted template headings: ' . json_encode($normalized));
    }
    echo "PASS Gratuity importer\n";
} finally {
    @unlink($gratuityPath);
}
