<?php

ob_start();
require_once __DIR__ . '/../backend/api/import_common.php';
require_once __DIR__ . '/../backend/api/payroll_source_common.php';

$fixtures = [
    'June 2026' => ['C:/Users/Dell/Downloads/Pension Payroll for June, 2026.xlsx', 2089, 904624690.0],
    'October 2025' => ['C:/Users/Dell/Downloads/Pension Payroll for October, 2025.xlsx', 1983, 797513594.0],
];

foreach ($fixtures as $label => [$path, $expectedCount, $expectedAmount]) {
    if (!is_file($path)) {
        echo "SKIP {$label}: fixture unavailable\n";
        continue;
    }
    $parsed = normalizeGovernmentPayrollScheduleRows(importParseXlsxRows($path));
    if ($parsed === null) throw new RuntimeException("{$label}: government payroll layout was not detected");
    $rows = $parsed['rows'] ?? [];
    $amount = array_sum(array_column($rows, 'amount'));
    if (count($rows) !== $expectedCount) throw new RuntimeException("{$label}: expected {$expectedCount} rows; got " . count($rows));
    if (abs($amount - $expectedAmount) > 0.01) throw new RuntimeException("{$label}: expected {$expectedAmount}; got {$amount}");
    foreach ($rows as $row) {
        if (!preg_match('/^\d+$/', $row['supplierNo']) || !preg_match('/^\d+-\d{1,2}[A-Z]{3}\d{2}-\d+$/', $row['invoice_number'])) {
            throw new RuntimeException("{$label}: malformed supplier or invoice key at source row {$row['row_number']}");
        }
    }
    echo "PASS {$label}: " . count($rows) . " valid-payment rows, amount {$amount}\n";
}
