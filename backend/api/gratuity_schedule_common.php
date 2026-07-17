<?php
require_once __DIR__ . '/../config.php';

function ensureGratuityScheduleTables(mysqli $conn): void
{
    static $created = false;
    if ($created) {
        return;
    }

    ensureArrearsAndBudgetTables($conn);
    ensurePayrollManagementTables($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_gratuity_schedule_cycles (
            cycle_id int(11) NOT NULL AUTO_INCREMENT,
            schedule_year int(11) NOT NULL,
            schedule_month tinyint(4) NOT NULL,
            financial_year_label varchar(20) NOT NULL,
            quarter_label varchar(6) NOT NULL,
            uploaded_by varchar(100) DEFAULT NULL,
            source_file varchar(255) DEFAULT NULL,
            source_file_original_name varchar(255) DEFAULT NULL,
            source_file_mime varchar(120) DEFAULT NULL,
            notes text DEFAULT NULL,
            total_rows int(11) NOT NULL DEFAULT 0,
            matched_rows int(11) NOT NULL DEFAULT 0,
            unmatched_rows int(11) NOT NULL DEFAULT 0,
            exact_gratuity_rows int(11) NOT NULL DEFAULT 0,
            partial_gratuity_rows int(11) NOT NULL DEFAULT 0,
            small_surplus_rows int(11) NOT NULL DEFAULT 0,
            pension_arrears_rows int(11) NOT NULL DEFAULT 0,
            review_rows int(11) NOT NULL DEFAULT 0,
            total_scheduled_amount decimal(14,2) NOT NULL DEFAULT 0,
            total_gratuity_component decimal(14,2) NOT NULL DEFAULT 0,
            total_small_surplus_amount decimal(14,2) NOT NULL DEFAULT 0,
            total_pension_surplus_amount decimal(14,2) NOT NULL DEFAULT 0,
            total_allocated_pension_amount decimal(14,2) NOT NULL DEFAULT 0,
            total_unallocated_amount decimal(14,2) NOT NULL DEFAULT 0,
            total_remaining_arrears_amount decimal(14,2) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            is_deleted tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (cycle_id),
            KEY idx_gratuity_schedule_period (schedule_year, schedule_month),
            KEY idx_gratuity_schedule_fy_q (financial_year_label, quarter_label),
            KEY idx_gratuity_schedule_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_gratuity_schedule_entries (
            entry_id int(11) NOT NULL AUTO_INCREMENT,
            cycle_id int(11) NOT NULL,
            row_number int(11) NOT NULL DEFAULT 0,
            regNo varchar(50) DEFAULT NULL,
            supplierNo varchar(50) DEFAULT NULL,
            beneficiary_name varchar(180) DEFAULT NULL,
            scheduled_amount decimal(14,2) NOT NULL DEFAULT 0,
            matched_regNo varchar(50) DEFAULT NULL,
            matched_registry_id int(11) DEFAULT NULL,
            matched_name varchar(180) DEFAULT NULL,
            registry_gratuity_estimate decimal(14,2) NOT NULL DEFAULT 0,
            latest_monthly_pension decimal(14,2) NOT NULL DEFAULT 0,
            monthly_pension_source varchar(40) DEFAULT NULL,
            open_pension_arrears_amount decimal(14,2) NOT NULL DEFAULT 0,
            open_pension_arrears_months int(11) NOT NULL DEFAULT 0,
            gratuity_component_amount decimal(14,2) NOT NULL DEFAULT 0,
            pension_surplus_amount decimal(14,2) NOT NULL DEFAULT 0,
            small_surplus_amount decimal(14,2) NOT NULL DEFAULT 0,
            allocated_pension_amount decimal(14,2) NOT NULL DEFAULT 0,
            scheduled_full_months int(11) NOT NULL DEFAULT 0,
            allocated_months int(11) NOT NULL DEFAULT 0,
            unallocated_scheduled_months int(11) NOT NULL DEFAULT 0,
            unallocated_scheduled_amount decimal(14,2) NOT NULL DEFAULT 0,
            remaining_arrears_months int(11) NOT NULL DEFAULT 0,
            remaining_arrears_amount decimal(14,2) NOT NULL DEFAULT 0,
            classification varchar(80) NOT NULL DEFAULT 'review',
            matching_basis varchar(40) DEFAULT NULL,
            analysis_note varchar(255) DEFAULT NULL,
            raw_payload text DEFAULT NULL,
            is_matched tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (entry_id),
            KEY idx_gratuity_entry_cycle (cycle_id),
            KEY idx_gratuity_entry_reg (matched_regNo),
            KEY idx_gratuity_entry_classification (classification)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_gratuity_schedule_allocations (
            allocation_id int(11) NOT NULL AUTO_INCREMENT,
            cycle_id int(11) NOT NULL,
            entry_id int(11) NOT NULL,
            matched_regNo varchar(50) NOT NULL,
            ledger_id int(11) DEFAULT NULL,
            period_year int(11) NOT NULL,
            period_month tinyint(4) NOT NULL,
            claim_type varchar(80) NOT NULL DEFAULT 'Pension Arrears',
            allocated_amount decimal(14,2) NOT NULL DEFAULT 0,
            monthly_pension_amount decimal(14,2) NOT NULL DEFAULT 0,
            allocation_status varchar(30) NOT NULL DEFAULT 'scheduled',
            note varchar(255) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (allocation_id),
            KEY idx_gratuity_alloc_cycle (cycle_id),
            KEY idx_gratuity_alloc_entry (entry_id),
            KEY idx_gratuity_alloc_reg (matched_regNo),
            KEY idx_gratuity_alloc_period (period_year, period_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function parseGratuityScheduleUploadRows(string $absolutePath, string $extension): array
{
    $ext = strtolower(trim($extension));
    if ($ext === 'csv') {
        $rows = parseGratuityScheduleCsvRows($absolutePath);
    } else {
        $rows = parseGratuityScheduleXlsxRows($absolutePath);
    }
    return normalizeGratuityScheduleRows($rows);
}

function parseGratuityScheduleCsvRows(string $absolutePath): array
{
    $handle = fopen($absolutePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static function ($value) {
            return trim((string)$value);
        }, $row);
    }
    fclose($handle);
    return $rows;
}

function parseGratuityScheduleXlsxRows(string $absolutePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled');
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Unable to open XLSX archive');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedStrings = parseGratuityScheduleSharedStrings((string)$sharedXml);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $sheetXml = getGratuityScheduleFirstWorksheet($zip);
    }
    $zip->close();

    if ($sheetXml === false || $sheetXml === null) {
        throw new RuntimeException('No worksheet found in XLSX file');
    }

    return parseGratuityScheduleWorksheet((string)$sheetXml, $sharedStrings);
}

function parseGratuityScheduleSharedStrings(string $xmlText): array
{
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        return [];
    }

    $strings = [];
    foreach ($xml->si as $si) {
        $value = '';
        if (isset($si->t)) {
            $value = (string)$si->t;
        } elseif (isset($si->r)) {
            foreach ($si->r as $run) {
                $value .= (string)($run->t ?? '');
            }
        }
        $strings[] = trim($value);
    }
    return $strings;
}

function getGratuityScheduleFirstWorksheet(ZipArchive $zip)
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#i', $name)) {
            $sheetXml = $zip->getFromName($name);
            if ($sheetXml !== false) {
                return $sheetXml;
            }
        }
    }
    return false;
}

function parseGratuityScheduleWorksheet(string $xmlText, array $sharedStrings): array
{
    $xml = simplexml_load_string($xmlText);
    if ($xml === false || !isset($xml->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = strtoupper((string)($attrs['r'] ?? ''));
            $type = strtolower((string)($attrs['t'] ?? ''));
            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $matches)) {
                $colIndex = gratuityScheduleColumnIndex($matches[1]);
            }
            $value = '';
            if ($type === 's') {
                $value = (string)($sharedStrings[(int)($cell->v ?? 0)] ?? '');
            } elseif ($type === 'inlinestr') {
                if (isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                } elseif (isset($cell->is->r)) {
                    foreach ($cell->is->r as $run) $value .= (string)($run->t ?? '');
                }
            } else {
                $value = trim((string)($cell->v ?? ''));
            }
            $rowData[$colIndex] = $value;
        }
        if (!empty($rowData)) {
            ksort($rowData);
            $rows[] = $rowData;
        }
    }

    return $rows;
}

function gratuityScheduleColumnIndex(string $letters): int
{
    $index = 0;
    $letters = strtoupper(trim($letters));
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function normalizeGratuityScheduleRows(array $rows): array
{
    if (empty($rows)) {
        return ['rows' => [], 'review_rows' => [], 'review_columns' => []];
    }

    $displayHeaders = array_map(static function ($value, int $index): string {
        $label = trim((string)$value);
        return $label !== '' ? $label : ('Column ' . ($index + 1));
    }, $rows[0], array_keys($rows[0]));

    $header = array_map(static function ($value) {
        return strtolower(preg_replace('/[^a-z0-9]/', '', (string)$value));
    }, $rows[0]);

    $idxRegNo = gratuityScheduleHeaderIndex($header, ['regno', 'fileno', 'filenumber', 'pensionno', 'pensionnumber']);
    $idxSupplier = gratuityScheduleHeaderIndex($header, ['suppliernumber', 'supplierno', 'supplier']);
    $idxName = gratuityScheduleHeaderIndex($header, ['beneficiaryname', 'name', 'pensionername', 'fullname']);
    $idxAmount = gratuityScheduleHeaderIndex($header, ['scheduledamount', 'amount', 'gratuityamount', 'scheduleamount', 'value']);
    $idxNotes = gratuityScheduleHeaderIndex($header, ['notes', 'note', 'remarks', 'comment']);

    $hasHeader = $idxRegNo >= 0 || $idxSupplier >= 0 || $idxName >= 0 || $idxAmount >= 0;
    if ($idxRegNo < 0) {
        $idxRegNo = 0;
    }
    if ($idxSupplier < 0) {
        $idxSupplier = 1;
    }
    if ($idxName < 0) {
        $idxName = 2;
    }
    if ($idxAmount < 0) {
        $idxAmount = 3;
    }
    if ($idxNotes < 0) {
        $idxNotes = 4;
    }

    $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
    $normalized = [];
    $reviewRows = [];
    foreach ($dataRows as $offset => $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }

        $rowNumber = $hasHeader ? ($offset + 2) : ($offset + 1);
        $regNo = trim((string)($row[$idxRegNo] ?? ''));
        $supplierNo = trim((string)($row[$idxSupplier] ?? ''));
        $beneficiaryName = trim((string)($row[$idxName] ?? ''));
        $scheduledAmount = gratuityScheduleNormalizeNumericAmount((string)($row[$idxAmount] ?? '0'));
        $notes = trim((string)($row[$idxNotes] ?? ''));

        if ($regNo === '' && $supplierNo === '' && $beneficiaryName === '') {
            continue;
        }
        if ($scheduledAmount <= 0) {
            $reviewRows[] = buildImportReviewRowFromSource($displayHeaders, $row, [
                'Source Row' => $rowNumber,
                'Review Status' => 'Invalid',
                'Review Reason' => 'Scheduled Amount must be greater than zero.',
                'Review Fields' => ['scheduledAmount']
            ]);
            continue;
        }

        $normalized[] = [
            'row_number' => $rowNumber,
            'regNo' => $regNo,
            'supplierNo' => $supplierNo,
            'beneficiary_name' => $beneficiaryName,
            'scheduled_amount' => $scheduledAmount,
            'notes' => $notes,
            'source_row' => $row
        ];
    }

    return [
        'rows' => $normalized,
        'review_rows' => $reviewRows,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $displayHeaders)
    ];
}

function gratuityScheduleHeaderIndex(array $headers, array $aliases): int
{
    foreach ($headers as $idx => $label) {
        if (in_array((string)$label, $aliases, true)) {
            return (int)$idx;
        }
    }
    return -1;
}

function gratuityScheduleNormalizeNumericAmount(string $raw): float
{
    $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', trim($raw)));
    return is_numeric($clean) ? round(max((float)$clean, 0), 2) : 0.0;
}

function buildGratuityScheduleRegistryIndex(mysqli $conn): array
{
    ensureFileMovementTables($conn);

    $index = [
        'records' => [],
        'byRegNo' => [],
        'bySupplierNo' => [],
        'byName' => []
    ];

    $result = $conn->query("
        SELECT
            id,
            regNo,
            supplierNo,
            title,
            sName,
            fName,
            gratuity,
            monthlySalary,
            reducedPension,
            fullPension,
            payType,
            livingStatus,
            payrollStatus
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ");

    if (!$result) {
        return $index;
    }

    while ($row = $result->fetch_assoc()) {
        $regNo = trim((string)($row['regNo'] ?? ''));
        $supplierNo = trim((string)($row['supplierNo'] ?? ''));
        $recordId = (int)($row['id'] ?? 0);
        if ($recordId <= 0 && $regNo === '' && $supplierNo === '') {
            continue;
        }

        $record = [
            'id' => $recordId,
            'regNo' => $regNo,
            'supplierNo' => $supplierNo,
            'title' => (string)($row['title'] ?? ''),
            'sName' => (string)($row['sName'] ?? ''),
            'fName' => (string)($row['fName'] ?? ''),
            'name' => formatTitleName(
                (string)($row['title'] ?? ''),
                (string)($row['sName'] ?? ''),
                (string)($row['fName'] ?? '')
            ),
            'gratuity' => (float)($row['gratuity'] ?? 0),
            'monthlySalary' => (float)($row['monthlySalary'] ?? 0),
            'reducedPension' => (float)($row['reducedPension'] ?? 0),
            'fullPension' => (float)($row['fullPension'] ?? 0),
            'payType' => (string)($row['payType'] ?? ''),
            'livingStatus' => (string)($row['livingStatus'] ?? ''),
            'payrollStatus' => (string)($row['payrollStatus'] ?? '')
        ];

        $index['records'][] = $record;

        $regKey = strtolower($regNo);
        if ($regKey !== '') {
            $index['byRegNo'][$regKey] = $record;
        }

        $supplierKey = strtolower($supplierNo);
        if ($supplierKey !== '') {
            if (!isset($index['bySupplierNo'][$supplierKey])) {
                $index['bySupplierNo'][$supplierKey] = [];
            }
            $index['bySupplierNo'][$supplierKey][] = $record;
        }

        gratuityScheduleRegisterNameKey($index['byName'], gratuityScheduleNormalizeNameKey($row['title'] ?? '', $row['sName'] ?? '', $row['fName'] ?? ''), $record);
        gratuityScheduleRegisterNameKey($index['byName'], gratuityScheduleNormalizeNameKey('', $row['sName'] ?? '', $row['fName'] ?? ''), $record);
        gratuityScheduleRegisterNameKey($index['byName'], gratuityScheduleNormalizeSimpleName((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')), $record);
        gratuityScheduleRegisterNameKey($index['byName'], gratuityScheduleNormalizeSimpleName((string)($row['fName'] ?? '') . ' ' . (string)($row['sName'] ?? '')), $record);
    }

    $result->free();
    return $index;
}

function gratuityScheduleRegisterNameKey(array &$map, string $key, array $record): void
{
    if ($key === '') {
        return;
    }
    if (!isset($map[$key])) {
        $map[$key] = [];
    }
    $map[$key][] = $record;
}

function gratuityScheduleNormalizeNameKey(string $title, string $sName, string $fName): string
{
    return gratuityScheduleNormalizeSimpleName(trim(trim($title) . ' ' . trim($sName) . ' ' . trim($fName)));
}

function gratuityScheduleNormalizeSimpleName(string $value): string
{
    $clean = strtolower(trim($value));
    if ($clean === '') {
        return '';
    }
    $clean = preg_replace('/[^a-z0-9 ]+/', ' ', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

function matchGratuityScheduleRowToRegistry(array $registryIndex, string $regNo, string $supplierNo, string $beneficiaryName): array
{
    $regKey = strtolower(trim($regNo));
    if ($regKey !== '' && isset($registryIndex['byRegNo'][$regKey])) {
        return [
            'matched' => true,
            'basis' => 'reg_no',
            'record' => $registryIndex['byRegNo'][$regKey],
            'note' => ''
        ];
    }

    $supplierKey = strtolower(trim($supplierNo));
    if ($supplierKey !== '' && !empty($registryIndex['bySupplierNo'][$supplierKey])) {
        $supplierMatches = $registryIndex['bySupplierNo'][$supplierKey];
        if (count($supplierMatches) === 1) {
            return [
                'matched' => true,
                'basis' => 'supplier_no',
                'record' => $supplierMatches[0],
                'note' => ''
            ];
        }
        return [
            'matched' => false,
            'basis' => 'supplier_no_ambiguous',
            'record' => null,
            'note' => 'Supplier number matched more than one registry record.'
        ];
    }

    $nameKey = gratuityScheduleNormalizeSimpleName($beneficiaryName);
    if ($nameKey !== '' && !empty($registryIndex['byName'][$nameKey])) {
        $nameMatches = $registryIndex['byName'][$nameKey];
        $unique = [];
        foreach ($nameMatches as $match) {
            $unique[(int)($match['id'] ?? 0)] = $match;
        }
        if (count($unique) === 1) {
            return [
                'matched' => true,
                'basis' => 'unique_name',
                'record' => array_values($unique)[0],
                'note' => ''
            ];
        }
        return [
            'matched' => false,
            'basis' => 'name_ambiguous',
            'record' => null,
            'note' => 'Beneficiary name matched more than one registry record.'
        ];
    }

    return [
        'matched' => false,
        'basis' => 'none',
        'record' => null,
        'note' => 'No registry match was found.'
    ];
}

function getGratuityScheduleMonthlyPension(mysqli $conn, array $registryRecord, array &$cache): array
{
    $regNo = trim((string)($registryRecord['regNo'] ?? ''));
    if ($regNo === '') {
        return ['amount' => 0.0, 'source' => '', 'periodLabel' => ''];
    }
    if (isset($cache[$regNo])) {
        return $cache[$regNo];
    }

    $resolved = [
        'amount' => 0.0,
        'source' => '',
        'periodLabel' => ''
    ];

    $stmt = $conn->prepare("
        SELECT payroll_year, payroll_month, amount
        FROM tb_registry_payroll_monthly_status
        WHERE regNo = ?
        ORDER BY payroll_year DESC, payroll_month DESC, updated_at DESC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $regNo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($row && (float)($row['amount'] ?? 0) > 0) {
            $resolved = [
                'amount' => round((float)$row['amount'], 2),
                'source' => 'latest_payroll',
                'periodLabel' => formatMonthYearValue((int)($row['payroll_month'] ?? 0), (int)($row['payroll_year'] ?? 0))
            ];
            $cache[$regNo] = $resolved;
            return $resolved;
        }
    }

    $monthlySalary = round(max((float)($registryRecord['monthlySalary'] ?? 0), 0), 2);
    if ($monthlySalary > 0) {
        $resolved = [
            'amount' => $monthlySalary,
            'source' => 'registry_monthly_salary',
            'periodLabel' => ''
        ];
        $cache[$regNo] = $resolved;
        return $resolved;
    }

    $reduced = round(max((float)($registryRecord['reducedPension'] ?? 0), 0), 2);
    $full = round(max((float)($registryRecord['fullPension'] ?? 0), 0), 2);
    $payType = strtolower(str_replace(['-', '_', ' '], '', trim((string)($registryRecord['payType'] ?? ''))));

    if ($reduced > 0 || $full > 0) {
        $resolved = [
            'amount' => ($payType === 'fullpension' && $full > 0) ? $full : ($reduced > 0 ? $reduced : $full),
            'source' => ($payType === 'fullpension' && $full > 0) ? 'registry_full_pension' : ($reduced > 0 ? 'registry_reduced_pension' : 'registry_full_pension'),
            'periodLabel' => ''
        ];
    }

    $cache[$regNo] = $resolved;
    return $resolved;
}

function getOpenPensionArrearsSnapshot(mysqli $conn, string $regNo, array &$cache): array
{
    $regNo = trim($regNo);
    if ($regNo === '') {
        return ['rows' => [], 'months' => 0, 'amount' => 0.0];
    }
    if (isset($cache[$regNo])) {
        return $cache[$regNo];
    }

    $snapshot = [
        'rows' => [],
        'months' => 0,
        'amount' => 0.0
    ];

    $stmt = $conn->prepare("
        SELECT
            ledger_id,
            period_year,
            period_month,
            financial_year_label,
            quarter_label,
            balance_amount,
            status
        FROM tb_arrears_ledger
        WHERE regNo = ?
          AND claim_type = 'Pension Arrears'
          AND COALESCE(balance_amount, 0) > 0
          AND status IN ('Pending', 'Partially Paid')
        ORDER BY period_year ASC, period_month ASC, ledger_id ASC
    ");
    if ($stmt) {
        $stmt->bind_param("s", $regNo);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $balance = round(max((float)($row['balance_amount'] ?? 0), 0), 2);
            if ($balance <= 0) {
                continue;
            }
            $snapshot['rows'][] = [
                'ledgerId' => (int)($row['ledger_id'] ?? 0),
                'periodYear' => (int)($row['period_year'] ?? 0),
                'periodMonth' => (int)($row['period_month'] ?? 0),
                'financialYear' => (string)($row['financial_year_label'] ?? ''),
                'quarter' => (string)($row['quarter_label'] ?? ''),
                'balanceAmount' => $balance,
                'status' => (string)($row['status'] ?? '')
            ];
            $snapshot['amount'] += $balance;
        }
        $stmt->close();
    }

    $snapshot['amount'] = round($snapshot['amount'], 2);
    $snapshot['months'] = count($snapshot['rows']);
    $cache[$regNo] = $snapshot;
    return $snapshot;
}

function analyzeGratuityScheduleEntry(array $row, array $match, array $monthlyPension, array $arrearsSnapshot): array
{
    $scheduledAmount = round(max((float)($row['scheduled_amount'] ?? 0), 0), 2);
    $matched = !empty($match['matched']) && !empty($match['record']);
    $record = $matched ? (array)$match['record'] : [];
    $registryGratuity = round(max((float)($record['gratuity'] ?? 0), 0), 2);
    $monthlyAmount = round(max((float)($monthlyPension['amount'] ?? 0), 0), 2);
    $openArrearsAmount = round(max((float)($arrearsSnapshot['amount'] ?? 0), 0), 2);
    $openArrearsMonths = (int)($arrearsSnapshot['months'] ?? 0);

    $analysis = [
        'matched' => $matched,
        'matchingBasis' => (string)($match['basis'] ?? 'none'),
        'matchedRegNo' => $matched ? (string)($record['regNo'] ?? '') : '',
        'matchedRegistryId' => $matched ? (int)($record['id'] ?? 0) : 0,
        'matchedName' => $matched ? (string)($record['name'] ?? '') : '',
        'registryGratuityEstimate' => $registryGratuity,
        'latestMonthlyPension' => $monthlyAmount,
        'monthlyPensionSource' => (string)($monthlyPension['source'] ?? ''),
        'openPensionArrearsAmount' => $openArrearsAmount,
        'openPensionArrearsMonths' => $openArrearsMonths,
        'gratuityComponentAmount' => 0.0,
        'pensionSurplusAmount' => 0.0,
        'smallSurplusAmount' => 0.0,
        'allocatedPensionAmount' => 0.0,
        'scheduledFullMonths' => 0,
        'allocatedMonths' => 0,
        'unallocatedScheduledMonths' => 0,
        'unallocatedScheduledAmount' => 0.0,
        'remainingArrearsMonths' => $openArrearsMonths,
        'remainingArrearsAmount' => $openArrearsAmount,
        'classification' => 'review',
        'analysisNote' => '',
        'allocations' => []
    ];

    if (!$matched) {
        $analysis['classification'] = 'unmatched_registry';
        $analysis['analysisNote'] = (string)($match['note'] ?? 'No registry match was found.');
        $analysis['unallocatedScheduledAmount'] = $scheduledAmount;
        return $analysis;
    }

    $gratuityComponent = $registryGratuity > 0 ? min($scheduledAmount, $registryGratuity) : 0.0;
    $pensionSurplus = round(max($scheduledAmount - $gratuityComponent, 0), 2);
    $analysis['gratuityComponentAmount'] = round($gratuityComponent, 2);
    $analysis['pensionSurplusAmount'] = $pensionSurplus;

    if ($gratuityComponent > 0 && abs($scheduledAmount - $registryGratuity) < 0.01) {
        $analysis['classification'] = 'exact_gratuity_match';
        $analysis['analysisNote'] = 'Uploaded amount matches the registry gratuity estimate for this pensioner.';
    } elseif ($gratuityComponent > 0 && $scheduledAmount < $registryGratuity) {
        $analysis['classification'] = 'partial_gratuity_schedule';
        $analysis['analysisNote'] = 'Uploaded amount captures part of the registry gratuity estimate only.';
    } elseif ($gratuityComponent <= 0 && $pensionSurplus <= 0) {
        $analysis['classification'] = 'review_missing_gratuity_estimate';
        $analysis['analysisNote'] = 'The registry record has no gratuity estimate, so this schedule row needs review.';
    }

    if ($pensionSurplus <= 0) {
        $analysis['remainingArrearsMonths'] = $openArrearsMonths;
        $analysis['remainingArrearsAmount'] = $openArrearsAmount;
        return $analysis;
    }

    if ($monthlyAmount <= 0) {
        $analysis['classification'] = $gratuityComponent > 0 ? 'review_missing_monthly_pension' : 'pension_review_missing_monthly_pension';
        $analysis['analysisNote'] = 'The row has surplus beyond gratuity, but no recent monthly pension amount was found for month-based allocation.';
        $analysis['unallocatedScheduledAmount'] = $pensionSurplus;
        return $analysis;
    }

    if ($pensionSurplus < $monthlyAmount) {
        $analysis['smallSurplusAmount'] = $pensionSurplus;
        $analysis['unallocatedScheduledAmount'] = $pensionSurplus;
        $analysis['classification'] = $gratuityComponent > 0 ? 'gratuity_plus_small_surplus' : 'small_surplus_review';
        $analysis['analysisNote'] = 'The surplus is below one monthly pension amount, so it has been recorded for review instead of month allocation.';
        return $analysis;
    }

    $scheduledFullMonths = (int)floor($pensionSurplus / $monthlyAmount);
    $fullMonthBudget = round($scheduledFullMonths * $monthlyAmount, 2);
    $smallRemainder = round(max($pensionSurplus - $fullMonthBudget, 0), 2);
    $remainingBudget = $fullMonthBudget;
    $allocatedAmount = 0.0;

    foreach ((array)($arrearsSnapshot['rows'] ?? []) as $openRow) {
        if ($remainingBudget <= 0) {
            break;
        }
        $rowBalance = round(max((float)($openRow['balanceAmount'] ?? 0), 0), 2);
        if ($rowBalance <= 0) {
            continue;
        }
        $allocated = round(min($rowBalance, $remainingBudget), 2);
        if ($allocated <= 0) {
            continue;
        }
        $analysis['allocations'][] = [
            'ledgerId' => (int)($openRow['ledgerId'] ?? 0),
            'periodYear' => (int)($openRow['periodYear'] ?? 0),
            'periodMonth' => (int)($openRow['periodMonth'] ?? 0),
            'financialYear' => (string)($openRow['financialYear'] ?? ''),
            'quarter' => (string)($openRow['quarter'] ?? ''),
            'allocatedAmount' => $allocated,
            'monthlyPensionAmount' => $monthlyAmount,
            'allocationStatus' => 'scheduled',
            'note' => 'Scheduled from monthly gratuity upload.'
        ];
        $allocatedAmount += $allocated;
        $remainingBudget = round(max($remainingBudget - $allocated, 0), 2);
    }

    $analysis['scheduledFullMonths'] = $scheduledFullMonths;
    $analysis['allocatedPensionAmount'] = round($allocatedAmount, 2);
    $analysis['smallSurplusAmount'] = $smallRemainder;
    $analysis['allocatedMonths'] = min($scheduledFullMonths, $openArrearsMonths);
    $analysis['unallocatedScheduledMonths'] = max($scheduledFullMonths - $analysis['allocatedMonths'], 0);
    $analysis['unallocatedScheduledAmount'] = round($remainingBudget, 2);
    $analysis['remainingArrearsAmount'] = round(max($openArrearsAmount - $allocatedAmount, 0), 2);
    $analysis['remainingArrearsMonths'] = max($openArrearsMonths - $analysis['allocatedMonths'], 0);
    $analysis['classification'] = $gratuityComponent > 0 ? 'gratuity_plus_pension_arrears' : 'pension_only_schedule';

    if ($analysis['allocatedMonths'] > 0) {
        $analysis['analysisNote'] = 'The surplus covers ' . number_format($scheduledFullMonths) . ' month(s) at the latest pension rate and has been mapped from the oldest open pension-arrears months.';
        if ($analysis['unallocatedScheduledMonths'] > 0) {
            $analysis['analysisNote'] .= ' Some scheduled months exceed the arrears currently tracked in the ledger.';
        }
    } else {
        $analysis['classification'] = 'scheduled_without_open_arrears';
        $analysis['analysisNote'] = 'The schedule contains pension-sized surplus, but no open pension-arrears ledger months were available for allocation.';
    }

    return $analysis;
}

function classifyGratuityScheduleRowBucket(string $classification): string
{
    return match ($classification) {
        'exact_gratuity_match' => 'exact_gratuity',
        'partial_gratuity_schedule' => 'partial_gratuity',
        'gratuity_plus_small_surplus',
        'small_surplus_review' => 'small_surplus',
        'gratuity_plus_pension_arrears',
        'pension_only_schedule' => 'pension_arrears',
        default => 'review',
    };
}

function formatMonthYearValue(int $month, int $year): string
{
    if ($month < 1 || $month > 12 || $year < 1900) {
        return '';
    }
    return date('M Y', mktime(0, 0, 0, $month, 1, $year));
}
