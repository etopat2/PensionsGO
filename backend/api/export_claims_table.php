<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/pdf_library.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (!currentUserHasPermission($conn, 'claims.arrears.view')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function ce_col(int $i): string { $n=''; $i++; while($i>0){$m=($i-1)%26; $n=chr(65+$m).$n; $i=(int)(($i-$m-1)/26);} return $n; }
function ce_xml(string $v): string { if(function_exists('mb_convert_encoding')){$c=@mb_convert_encoding($v,'UTF-8','UTF-8'); if($c!==false){$v=$c;}} $v=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u','',$v) ?? ''; return htmlspecialchars($v, ENT_XML1|ENT_QUOTES, 'UTF-8'); }
function ce_len(string $v): int { return function_exists('mb_strlen') ? (int)mb_strlen($v,'UTF-8') : strlen($v); }
function ce_pdf_esc(string $v): string { $v=str_replace('\\','\\\\',$v); $v=str_replace('(','\\(',$v); $v=str_replace(')','\\)',$v); return preg_replace('/[\x00-\x1F\x7F]/u','',$v) ?? ''; }
function ce_wrap(string $t,int $max): array {
    $t=preg_replace('/\s+/u',' ',trim($t)) ?? ''; if($t===''){return [''];} $max=max(1,$max); $words=preg_split('/\s+/u',$t) ?: [$t]; $lines=[]; $cur='';
    foreach($words as $word){
        if(ce_len($word)>$max){ if($cur!==''){$lines[]=$cur; $cur='';} $chunk=''; foreach((preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY) ?: [$word]) as $ch){ if(ce_len($chunk.$ch)>$max){ if($chunk!==''){$lines[]=$chunk;} $chunk=$ch; } else { $chunk.=$ch; } } if($chunk!==''){$lines[]=$chunk;} continue; }
        $cand=$cur===''?$word:($cur.' '.$word); if(ce_len($cand)<=$max){ $cur=$cand; } else { if($cur!==''){$lines[]=$cur;} $cur=$word; }
    }
    if($cur!==''){$lines[]=$cur;} return $lines ?: [$t];
}
function ce_txt_w(string $t,float $size): float { return max(0,ce_len($t))*($size*0.52); }
function ce_text(string $font,float $size,float $x,float $y,string $txt,float $r,float $g,float $b): string { return "BT\n/$font ".number_format($size,2,'.','')." Tf\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n1 0 0 1 ".number_format($x,2,'.','').' '.number_format($y,2,'.','')." Tm\n(".ce_pdf_esc($txt).") Tj\nET\n"; }
function ce_fill(float $x,float $y,float $w,float $h,float $r,float $g,float $b): string { return "q\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." rg\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re f\nQ\n"; }
function ce_stroke(float $x,float $y,float $w,float $h,float $r,float $g,float $b,float $lw=0.5): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x,2,'.','').' '.number_format($y,2,'.','').' '.number_format($w,2,'.','').' '.number_format($h,2,'.','')." re S\nQ\n"; }
function ce_line(float $x1,float $y1,float $x2,float $y2,float $r,float $g,float $b,float $lw=0.35): string { return "q\n".number_format($lw,3,'.','')." w\n".number_format($r,3,'.','').' '.number_format($g,3,'.','').' '.number_format($b,3,'.','')." RG\n".number_format($x1,2,'.','').' '.number_format($y1,2,'.','')." m\n".number_format($x2,2,'.','').' '.number_format($y2,2,'.','')." l S\nQ\n"; }
function ce_money(float $value): string { return number_format($value, 2, '.', ','); }
function ce_payload(array $p,string $by): array {
    $kind = strtolower(trim((string)($p['exportKind'] ?? '')));
    if ($kind === 'claims_ledger_grouped') {
        return ce_grouped_claims_payload($p, $by);
    }

    $title=trim((string)($p['title'] ?? 'Claims Table Export')); if($title===''){$title='Claims Table Export';}
    $headers=[]; foreach((array)($p['headers'] ?? []) as $h){ $h=trim((string)$h); if($h!==''){$headers[]=$h;} }
    if(empty($headers)){ throw new RuntimeException('The selected claims table has no exportable headers.'); }
    $rows=[]; $samples=[0=>4]; foreach((array)($p['rows'] ?? []) as $i=>$row){ if(!is_array($row))continue; $isTotalRow=isset($row[0]) && trim((string)$row[0])==='Total'; $line=[ $isTotalRow ? 'Total' : (string)($i+1) ]; foreach($headers as $j=>$hdr){ $val=trim((string)($row[$j] ?? '')); if($isTotalRow && $j < 2){ $val=''; } $line[]=$val; $samples[$j+1]=max($samples[$j+1] ?? ce_len($hdr), ce_len($val)); } $rows[]=$line; }
    return ['kind'=>'generic','title'=>'UPS PensionsGo - '.$title,'sheet_name'=>$title,'headers'=>array_merge(['S/N'],$headers),'rows'=>$rows,'generated_at'=>date('Y-m-d H:i:s'),'generated_by'=>$by,'sample_lengths'=>$samples,'record_count'=>count($rows)];
}

function ce_bind_dynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bindArgs = [];
    $bindArgs[] = &$types;
    foreach ($params as $idx => $value) {
        $bindArgs[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function ce_format_month_year(int $month, int $year): string {
    if ($month < 1 || $month > 12 || $year <= 0) {
        return '';
    }
    $dt = DateTime::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
    return $dt ? $dt->format('M Y') : '';
}

function ce_build_claims_ledger_groups(mysqli $conn, array $filters): array {
    ensureArrearsAndBudgetTables($conn);

    $where = [
        '1=1',
        "LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'"
    ];
    $params = [];
    $types = '';

    $claimType = trim((string)($filters['claim_type'] ?? ''));
    if ($claimType !== '') {
        $claimType = normalizeArrearsClaimType($claimType);
        $where[] = "l.claim_type = ?";
        $params[] = $claimType;
        $types .= 's';
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = "l.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $claimStatus = trim((string)($filters['claim_status'] ?? ''));
    if ($claimStatus !== '') {
        $where[] = "l.claim_status = ?";
        $params[] = $claimStatus;
        $types .= 's';
    }

    $year = (int)($filters['year'] ?? 0);
    if ($year > 1900 && $year < 2200) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
    }

    $quarter = trim((string)($filters['quarter'] ?? ''));
    if (in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
        $where[] = "l.quarter_label = ?";
        $params[] = $quarter;
        $types .= 's';
    }

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $where[] = "(l.regNo LIKE ? OR CONCAT_WS(' ', fr.sName, fr.fName) LIKE ? OR COALESCE(fr.supplierNo, '') LIKE ?)";
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $types .= 'sss';
    }

    $whereSql = implode(' AND ', $where);
    $sql = "
        SELECT
            l.regNo,
            l.claim_type,
            l.period_year,
            l.period_month,
            l.expected_amount,
            l.paid_amount,
            l.balance_amount,
            l.status,
            fr.title,
            fr.sName,
            fr.fName
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        ORDER BY l.regNo ASC, l.period_year ASC, l.period_month ASC, l.claim_type ASC, l.ledger_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare claims ledger export query.');
    }
    $bindParams = $params;
    $bindTypes = $types;
    ce_bind_dynamic($stmt, $bindTypes, $bindParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    $index = [];
    while ($row = $result->fetch_assoc()) {
        $fileNo = trim((string)($row['regNo'] ?? ''));
        if ($fileNo === '') {
            $fileNo = 'Unspecified File';
        }
        if (!isset($index[$fileNo])) {
            $pensionerName = formatTitleName(
                (string)($row['title'] ?? ''),
                (string)($row['sName'] ?? ''),
                (string)($row['fName'] ?? '')
            );
            if ($pensionerName === '') {
                $pensionerName = 'Unnamed Pensioner';
            }
            $groups[] = [
                'fileNo' => $fileNo,
                'pensionerName' => $pensionerName,
                'rows' => [],
                'totals' => [
                    'expectedAmount' => 0.0,
                    'paidAmount' => 0.0,
                    'balanceAmount' => 0.0
                ]
            ];
            $index[$fileNo] = count($groups) - 1;
        }

        $groupIndex = $index[$fileNo];
        $expected = (float)($row['expected_amount'] ?? 0);
        $paid = (float)($row['paid_amount'] ?? 0);
        $balance = (float)($row['balance_amount'] ?? 0);
        $groups[$groupIndex]['rows'][] = [
            'entry' => count($groups[$groupIndex]['rows']) + 1,
            'claimType' => trim((string)($row['claim_type'] ?? '')),
            'period' => ce_format_month_year((int)($row['period_month'] ?? 0), (int)($row['period_year'] ?? 0)),
            'status' => trim((string)($row['status'] ?? '')),
            'expectedAmount' => $expected,
            'paidAmount' => $paid,
            'balanceAmount' => $balance
        ];
        $groups[$groupIndex]['totals']['expectedAmount'] += $expected;
        $groups[$groupIndex]['totals']['paidAmount'] += $paid;
        $groups[$groupIndex]['totals']['balanceAmount'] += $balance;
    }
    $stmt->close();

    foreach ($groups as &$group) {
        $group['recordCount'] = count($group['rows']);
    }
    unset($group);

    return $groups;
}
function ce_grouped_claims_payload(array $p,string $by): array {
    $title = trim((string)($p['title'] ?? 'Claims Arrears Ledger'));
    if ($title === '') { $title = 'Claims Arrears Ledger'; }

    $headers = [];
    foreach ((array)($p['headers'] ?? []) as $header) {
        $header = trim((string)$header);
        if ($header !== '') { $headers[] = $header; }
    }
    if (empty($headers)) {
        $headers = ['Entry', 'Claim Type', 'Period', 'Status', 'Expected (UGX)', 'Paid (UGX)', 'Balance (UGX)'];
    }

    $groups = [];
    $grandExpected = 0.0;
    $grandPaid = 0.0;
    $grandBalance = 0.0;
    $recordCount = 0;

    foreach ((array)($p['groups'] ?? []) as $group) {
        if (!is_array($group)) { continue; }
        $fileNo = trim((string)($group['fileNo'] ?? ''));
        if ($fileNo === '') { $fileNo = 'Unspecified File'; }
        $pensionerName = trim((string)($group['pensionerName'] ?? ''));
        if ($pensionerName === '') {
            $pensionerName = trim(trim((string)($group['title'] ?? '')) . ' ' . trim((string)($group['name'] ?? '')));
        }
        if ($pensionerName === '') { $pensionerName = 'Unnamed Pensioner'; }

        $rows = [];
        $subExpected = 0.0;
        $subPaid = 0.0;
        $subBalance = 0.0;

        foreach ((array)($group['rows'] ?? []) as $rowIndex => $row) {
            if (!is_array($row)) { continue; }
            $expected = (float)($row['expectedAmount'] ?? 0);
            $paid = (float)($row['paidAmount'] ?? 0);
            $balance = (float)($row['balanceAmount'] ?? 0);
            $rows[] = [
                'entry' => (int)($row['entry'] ?? ($rowIndex + 1)),
                'claimType' => trim((string)($row['claimType'] ?? '')),
                'period' => trim((string)($row['period'] ?? '')),
                'status' => trim((string)($row['status'] ?? '')),
                'expectedAmount' => $expected,
                'paidAmount' => $paid,
                'balanceAmount' => $balance
            ];
            $subExpected += $expected;
            $subPaid += $paid;
            $subBalance += $balance;
        }

        if (empty($rows)) { continue; }

        $recordCount += count($rows);
        $grandExpected += $subExpected;
        $grandPaid += $subPaid;
        $grandBalance += $subBalance;
        $groups[] = [
            'fileNo' => $fileNo,
            'pensionerName' => $pensionerName,
            'recordCount' => count($rows),
            'rows' => $rows,
            'totals' => [
                'expectedAmount' => $subExpected,
                'paidAmount' => $subPaid,
                'balanceAmount' => $subBalance
            ]
        ];
    }

    return [
        'kind' => 'grouped_claims',
        'title' => 'UPS PensionsGo - ' . $title,
        'sheet_name' => $title,
        'headers' => $headers,
        'groups' => $groups,
        'totals' => [
            'expectedAmount' => $grandExpected,
            'paidAmount' => $grandPaid,
            'balanceAmount' => $grandBalance
        ],
        'file_count' => max(0, (int)($p['fileCount'] ?? count($groups))),
        'record_count' => max($recordCount, (int)($p['recordCount'] ?? 0)),
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $by
    ];
}

function ce_build_claims_aggregation_dataset(mysqli $conn, array $filters): array {
    ensureArrearsAndBudgetTables($conn);

    $allowedTypes = [
        'Pension Arrears',
        'Gratuity Arrears',
        'Full Pension',
        'Full Pension Arrears',
        'Pension and Gratuity Arrears',
        'Underpayment Claim'
    ];
    $selectedTypesRaw = array_map('normalizeArrearsClaimType', (array)($filters['claim_types'] ?? []));
    $selectedTypes = array_values(array_intersect($allowedTypes, array_unique(array_filter($selectedTypesRaw))));
    if (empty($selectedTypes)) {
        $selectedTypes = $allowedTypes;
    }

    $aggregationMode = (string)($filters['aggregation_mode'] ?? 'by_pensioner');
    $typeMode = (string)($filters['type_mode'] ?? 'by_type');
    $periodScope = (string)($filters['period_scope'] ?? 'all');
    $financialYear = trim((string)($filters['financial_year'] ?? ''));
    $quarter = trim((string)($filters['quarter'] ?? ''));
    $year = (int)($filters['year'] ?? 0);
    $month = (int)($filters['month'] ?? 0);
    $fromYear = (int)($filters['from_year'] ?? 0);
    $fromMonth = (int)($filters['from_month'] ?? 0);
    $toYear = (int)($filters['to_year'] ?? 0);
    $toMonth = (int)($filters['to_month'] ?? 0);
    $search = trim((string)($filters['search'] ?? ''));
    $retirementType = trim((string)($filters['retirement_type'] ?? ''));
    $livingStatus = trim((string)($filters['living_status'] ?? ''));
    $statusFilters = array_values(array_filter((array)($filters['status'] ?? [])));
    $claimStatusFilters = array_values(array_filter((array)($filters['claim_status'] ?? [])));
    $outstandingOnly = isset($filters['outstanding_only']) ? (bool)$filters['outstanding_only'] : true;
    $includeSubtotal = isset($filters['include_subtotal']) ? (bool)$filters['include_subtotal'] : true;
    $extraColumns = array_values(array_filter((array)($filters['extra_columns'] ?? [])));
    $extraColumns = array_values(array_intersect(['supplierNo', 'retirementType', 'livingStatus'], $extraColumns));

    $where = [
        '1=1',
        "LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'"
    ];
    $params = [];
    $types = '';

    if (!empty($selectedTypes)) {
        $placeholders = implode(',', array_fill(0, count($selectedTypes), '?'));
        $where[] = "l.claim_type IN ({$placeholders})";
        foreach ($selectedTypes as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if (!empty($statusFilters)) {
        $placeholders = implode(',', array_fill(0, count($statusFilters), '?'));
        $where[] = "l.status IN ({$placeholders})";
        foreach ($statusFilters as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if (!empty($claimStatusFilters)) {
        $placeholders = implode(',', array_fill(0, count($claimStatusFilters), '?'));
        $where[] = "l.claim_status IN ({$placeholders})";
        foreach ($claimStatusFilters as $value) {
            $params[] = $value;
            $types .= 's';
        }
    }

    if ($retirementType !== '') {
        $retirementAliases = getBenefitsRetirementTypeAliasesForFilter($retirementType);
        if (!empty($retirementAliases)) {
            $placeholders = implode(',', array_fill(0, count($retirementAliases), '?'));
            $where[] = "LOWER(TRIM(COALESCE(fr.retirementType, ''))) IN ({$placeholders})";
            foreach ($retirementAliases as $alias) {
                $params[] = $alias;
                $types .= 's';
            }
        }
    }

    if ($livingStatus !== '') {
        $where[] = "LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = ?";
        $params[] = strtolower($livingStatus);
        $types .= 's';
    }

    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $where[] = "(l.regNo LIKE ? OR CONCAT_WS(' ', fr.sName, fr.fName) LIKE ? OR COALESCE(fr.supplierNo, '') LIKE ?)";
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
        $types .= 'sss';
    }

    if ($periodScope === 'financial_year' && $financialYear !== '') {
        $where[] = "l.financial_year_label = ?";
        $params[] = $financialYear;
        $types .= 's';
    }
    if ($periodScope === 'quarter' && $quarter !== '') {
        $where[] = "l.quarter_label = ?";
        $params[] = $quarter;
        $types .= 's';
        if ($financialYear !== '') {
            $where[] = "l.financial_year_label = ?";
            $params[] = $financialYear;
            $types .= 's';
        }
    }
    if ($periodScope === 'year' && $year > 0) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
    }
    if ($periodScope === 'month' && $year > 0 && $month > 0) {
        $where[] = "l.period_year = ?";
        $params[] = $year;
        $types .= 'i';
        $where[] = "l.period_month = ?";
        $params[] = $month;
        $types .= 'i';
    }
    if ($periodScope === 'range' && $fromYear > 0 && $fromMonth > 0 && $toYear > 0 && $toMonth > 0) {
        $fromValue = ($fromYear * 100) + $fromMonth;
        $toValue = ($toYear * 100) + $toMonth;
        if ($toValue < $fromValue) {
            $temp = $fromValue;
            $fromValue = $toValue;
            $toValue = $temp;
        }
        $where[] = "((l.period_year * 100) + l.period_month) BETWEEN ? AND ?";
        $params[] = $fromValue;
        $params[] = $toValue;
        $types .= 'ii';
    }

    $byPeriod = $aggregationMode === 'by_pensioner_period';
    $groupColumns = $byPeriod ? 'l.regNo, l.period_year, l.period_month, l.claim_type' : 'l.regNo, l.claim_type';
    $selectPeriod = $byPeriod
        ? 'l.period_year, l.period_month'
        : 'MIN(l.period_year) AS period_year, MIN(l.period_month) AS period_month';

    $whereSql = implode(' AND ', $where);
    $sql = "
        SELECT
            l.regNo,
            l.claim_type,
            {$selectPeriod},
            SUM(l.balance_amount) AS balance_total,
            fr.title,
            fr.sName,
            fr.fName,
            fr.supplierNo,
            fr.retirementType,
            fr.livingStatus
        FROM tb_arrears_ledger l
        LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
        WHERE {$whereSql}
        GROUP BY {$groupColumns}
        " . ($outstandingOnly ? "HAVING SUM(l.balance_amount) > 0" : "") . "
        ORDER BY l.regNo ASC, l.period_year ASC, l.period_month ASC, l.claim_type ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare arrears summary export query.');
    }
    $bindParams = $params;
    $bindTypes = $types;
    ce_bind_dynamic($stmt, $bindTypes, $bindParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $rowsMap = [];
    $order = [];
    while ($row = $result->fetch_assoc()) {
        $fileNo = trim((string)($row['regNo'] ?? ''));
        if ($fileNo === '') {
            $fileNo = 'Unspecified File';
        }
        $periodLabel = $byPeriod ? ce_format_month_year((int)($row['period_month'] ?? 0), (int)($row['period_year'] ?? 0)) : '';
        $key = $byPeriod ? ($fileNo . '|' . $periodLabel) : $fileNo;

        if (!isset($rowsMap[$key])) {
            $name = trim(trim((string)($row['sName'] ?? '')) . ' ' . trim((string)($row['fName'] ?? '')));
            if ($name === '') {
                $name = 'Unnamed Pensioner';
            }
            $rowsMap[$key] = [
                'fileNo' => $fileNo,
                'name' => $name,
                'period' => $periodLabel,
                'supplierNo' => (string)($row['supplierNo'] ?? ''),
                'retirementType' => getBenefitsRetirementTypeLabel((string)($row['retirementType'] ?? '')),
                'livingStatus' => (string)($row['livingStatus'] ?? ''),
                'values' => [],
                'subtotal' => 0.0
            ];
            $order[] = $key;
        }

        $claimType = normalizeArrearsClaimType((string)($row['claim_type'] ?? ''));
        if (!in_array($claimType, $selectedTypes, true)) {
            continue;
        }
        $amount = round(max(0.0, (float)($row['balance_total'] ?? 0)), 2);
        if (!isset($rowsMap[$key]['values'][$claimType])) {
            $rowsMap[$key]['values'][$claimType] = 0.0;
        }
        $rowsMap[$key]['values'][$claimType] += $amount;
        $rowsMap[$key]['subtotal'] += $amount;
    }
    $stmt->close();

    $headers = ['File Number', 'Name'];
    foreach ($extraColumns as $col) {
        if ($col === 'supplierNo') {
            $headers[] = 'Supplier No';
        }
        if ($col === 'retirementType') {
            $headers[] = 'Retirement Type';
        }
        if ($col === 'livingStatus') {
            $headers[] = 'Living Status';
        }
    }
    if ($byPeriod) {
        $headers[] = 'Period';
    }

    $typeKeys = [];
    if ($typeMode === 'by_type') {
        foreach ($selectedTypes as $type) {
            $typeKeys[$type] = $type;
            $headers[] = $type . ' (UGX)';
        }
    }
    if ($includeSubtotal) {
        $headers[] = 'Subtotal (UGX)';
    }

    $rows = [];
    $totalsByType = [];
    foreach ($selectedTypes as $type) {
        $totalsByType[$type] = 0.0;
    }
    $grandSubtotal = 0.0;
    foreach ($order as $key) {
        $row = $rowsMap[$key];
        $line = [
            $row['fileNo'],
            $row['name']
        ];
        foreach ($extraColumns as $col) {
            if ($col === 'supplierNo') {
                $line[] = $row['supplierNo'];
            }
            if ($col === 'retirementType') {
                $line[] = $row['retirementType'];
            }
            if ($col === 'livingStatus') {
                $line[] = $row['livingStatus'];
            }
        }
        if ($byPeriod) {
            $line[] = $row['period'];
        }
        if ($typeMode === 'by_type') {
            foreach ($selectedTypes as $type) {
                $amount = (float)($row['values'][$type] ?? 0);
                $totalsByType[$type] += $amount;
                $line[] = number_format($amount, 2, '.', '');
            }
        }
        if ($includeSubtotal) {
            $grandSubtotal += (float)$row['subtotal'];
            $line[] = number_format((float)$row['subtotal'], 2, '.', '');
        }
        $rows[] = $line;
    }

    $hasTotals = ($typeMode === 'by_type' || $includeSubtotal);
    if ($hasTotals && !empty($rows)) {
        $prefixColumns = 2 + count($extraColumns) + ($byPeriod ? 1 : 0);
        $totalLine = array_fill(0, $prefixColumns, '');
        $totalLine[0] = 'Total';
        if ($typeMode === 'by_type') {
            foreach ($selectedTypes as $type) {
                $totalLine[] = number_format((float)($totalsByType[$type] ?? 0), 2, '.', '');
            }
        }
        if ($includeSubtotal) {
            $totalLine[] = number_format((float)$grandSubtotal, 2, '.', '');
        }
        $rows[] = $totalLine;
    }

    return [
        'headers' => $headers,
        'rows' => $rows,
        'record_count' => count($rows)
    ];
}

function ce_xlsx_report(array $e): array {
    if (($e['kind'] ?? '') === 'grouped_claims') {
        return ce_xlsx_grouped_claims_report($e);
    }

    $rows=[]; $merges=[]; $r=1; $max=count($e['headers']);
    $add=function(array $cells, ?float $h=null) use (&$rows,&$r): int { $cur=$r; $row=['r'=>$r,'cells'=>$cells]; if($h!==null&&$h>0){$row['h']=$h;} $rows[]=$row; $r++; return $cur; };
    $t=$add([['v'=>$e['title'],'s'=>1,'t'=>'s']],26.0); $merges[]='A'.$t.':'.ce_col($max-1).$t;
    $g1=$add([['v'=>'Generated On','s'=>2,'t'=>'s'],['v'=>$e['generated_at'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g1.':'.ce_col($max-1).$g1;
    $g2=$add([['v'=>'Generated By','s'=>2,'t'=>'s'],['v'=>$e['generated_by'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g2.':'.ce_col($max-1).$g2;
    $add([],6.0); $head=[]; foreach($e['headers'] as $h){ $head[]=['v'=>$h,'s'=>5,'t'=>'s']; } $add($head,22.0);
    $lastIndex=count($e['rows'])-1;
    foreach($e['rows'] as $rowIndex=>$row){ $hasTotalMarker=((isset($row[0]) && trim((string)$row[0])==='Total') || (isset($row[1]) && trim((string)$row[1])==='Total')); $isTotalRow=$lastIndex>=0 && $rowIndex===$lastIndex && $hasTotalMarker; $cells=[]; foreach($row as $i=>$v){ $isNum=$i!==0 && is_numeric($v) && !preg_match('/^0\d+/',(string)$v); if($i===0){$cells[]=['v'=>$isTotalRow?(string)$v:(int)$v,'s'=>$isTotalRow?9:7,'t'=>$isTotalRow?'s':'n'];} elseif($isNum){$cells[]=['v'=>(float)$v,'s'=>$isTotalRow?10:8,'t'=>'n'];} else {$cells[]=['v'=>(string)$v,'s'=>$isTotalRow?9:6,'t'=>'s'];} } $rowId=$add($cells,$isTotalRow?22.0:20.0); if($isTotalRow && $max>=3){ $merges[]='A'.$rowId.':C'.$rowId; } }
    $widths=array_fill(0,$max,10.0); foreach($rows as $row){ $rr=(int)($row['r'] ?? 0); foreach((array)($row['cells'] ?? []) as $i=>$cell){ if(($rr<=3 && $i>1) || ($rr===1 && $i>0))continue; $raw=trim((string)($cell['v'] ?? '')); if($raw==='')continue; $long=0; foreach(preg_split('/\R/u',$raw) ?: [$raw] as $line){ $long=max($long, ce_len(trim((string)$line))); } $widths[$i]=max($widths[$i], min(40.0, max(8.0, $long+2.0))); } }
    return ['rows'=>$rows,'merges'=>$merges,'max_cols'=>$max,'column_widths'=>$widths,'orientation'=>($max>7 || array_sum($widths)>100)?'landscape':'portrait'];
}
function ce_xlsx_grouped_claims_report(array $e): array {
    $rows=[]; $merges=[]; $r=1; $max=max(1,count((array)($e['headers'] ?? [])));
    $add=function(array $cells, ?float $h=null) use (&$rows,&$r): int { $cur=$r; $row=['r'=>$r,'cells'=>$cells]; if($h!==null&&$h>0){$row['h']=$h;} $rows[]=$row; $r++; return $cur; };
    $t=$add([['v'=>$e['title'],'s'=>1,'t'=>'s']],26.0); $merges[]='A'.$t.':'.ce_col($max-1).$t;
    $g1=$add([['v'=>'Generated On','s'=>2,'t'=>'s'],['v'=>$e['generated_at'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g1.':'.ce_col($max-1).$g1;
    $g2=$add([['v'=>'Generated By','s'=>2,'t'=>'s'],['v'=>$e['generated_by'],'s'=>3,'t'=>'s']],18.0); $merges[]='B'.$g2.':'.ce_col($max-1).$g2;
    $g3=$add([['v'=>'Files Represented','s'=>2,'t'=>'s'],['v'=>(int)($e['file_count'] ?? 0),'s'=>3,'t'=>'n']],18.0); $merges[]='B'.$g3.':'.ce_col($max-1).$g3;
    $g4=$add([['v'=>'Ledger Records','s'=>2,'t'=>'s'],['v'=>(int)($e['record_count'] ?? 0),'s'=>3,'t'=>'n']],18.0); $merges[]='B'.$g4.':'.ce_col($max-1).$g4;
    $add([],8.0);
    $head=[]; foreach((array)$e['headers'] as $h){ $head[]=['v'=>$h,'s'=>5,'t'=>'s']; }

    foreach((array)($e['groups'] ?? []) as $group){
        $label='File No: '.(string)$group['fileNo'].' | Pensioner: '.(string)$group['pensionerName'].' | Monthly Records: '.(int)($group['recordCount'] ?? count((array)($group['rows'] ?? [])));
        $groupRow=$add([['v'=>$label,'s'=>11,'t'=>'s']],22.0);
        $merges[]='A'.$groupRow.':'.ce_col($max-1).$groupRow;
        $add($head,22.0);
        foreach((array)($group['rows'] ?? []) as $row){
            $add([
                ['v'=>(int)($row['entry'] ?? 0),'s'=>7,'t'=>'n'],
                ['v'=>(string)($row['claimType'] ?? ''),'s'=>6,'t'=>'s'],
                ['v'=>(string)($row['period'] ?? ''),'s'=>6,'t'=>'s'],
                ['v'=>(string)($row['status'] ?? ''),'s'=>7,'t'=>'s'],
                ['v'=>(float)($row['expectedAmount'] ?? 0),'s'=>8,'t'=>'n'],
                ['v'=>(float)($row['paidAmount'] ?? 0),'s'=>8,'t'=>'n'],
                ['v'=>(float)($row['balanceAmount'] ?? 0),'s'=>8,'t'=>'n']
            ],20.0);
        }
        $subtotalRow=$add([
            0=>['v'=>'Subtotal for '.(string)($group['pensionerName'] ?? $group['fileNo'] ?? 'Unnamed Pensioner'),'s'=>12,'t'=>'s'],
            1=>['v'=>'','s'=>12,'t'=>'s'],
            2=>['v'=>'','s'=>12,'t'=>'s'],
            3=>['v'=>'','s'=>12,'t'=>'s'],
            4=>['v'=>(float)($group['totals']['expectedAmount'] ?? 0),'s'=>13,'t'=>'n'],
            5=>['v'=>(float)($group['totals']['paidAmount'] ?? 0),'s'=>13,'t'=>'n'],
            6=>['v'=>(float)($group['totals']['balanceAmount'] ?? 0),'s'=>13,'t'=>'n']
        ],22.0);
        $merges[]='A'.$subtotalRow.':D'.$subtotalRow;
        $add([],6.0);
    }

    $grandRow=$add([
        0=>['v'=>'Grand Total of Claim Arrears','s'=>9,'t'=>'s'],
        1=>['v'=>'','s'=>9,'t'=>'s'],
        2=>['v'=>'','s'=>9,'t'=>'s'],
        3=>['v'=>'','s'=>9,'t'=>'s'],
        4=>['v'=>(float)($e['totals']['expectedAmount'] ?? 0),'s'=>10,'t'=>'n'],
        5=>['v'=>(float)($e['totals']['paidAmount'] ?? 0),'s'=>10,'t'=>'n'],
        6=>['v'=>(float)($e['totals']['balanceAmount'] ?? 0),'s'=>10,'t'=>'n']
    ],24.0);
    $merges[]='A'.$grandRow.':D'.$grandRow;

    return ['rows'=>$rows,'merges'=>$merges,'max_cols'=>$max,'column_widths'=>[8.0,24.0,15.0,13.0,15.0,15.0,15.0],'orientation'=>'landscape'];
}
function ce_xlsx_binary(array $report,string $sheetName): string {
    if(!class_exists('ZipArchive')){ throw new RuntimeException('ZipArchive is required for XLSX export.'); }
    $rows=(array)($report['rows'] ?? []); $merges=(array)($report['merges'] ?? []); $max=max(1,(int)($report['max_cols'] ?? 1)); $widths=(array)($report['column_widths'] ?? []); $orientation=strtolower((string)($report['orientation'] ?? 'portrait'))==='landscape'?'landscape':'portrait';
    $lastCol=ce_col($max-1); $lastRow=1; $rowsXml='';
    foreach($rows as $row){ $rr=(int)($row['r'] ?? ($lastRow+1)); $lastRow=max($lastRow,$rr); $h=(float)($row['h'] ?? 0); $attrs=' r="'.$rr.'"'; if($h>0){$attrs.=' ht="'.number_format($h,2,'.','').'" customHeight="1"';} $rowsXml.='<row'.$attrs.'>';
        foreach((array)($row['cells'] ?? []) as $i=>$cell){ $v=$cell['v'] ?? ''; if($v===''||$v===null)continue; $ref=ce_col($i).$rr; $s=max(0,(int)($cell['s'] ?? 0)); $t=(string)($cell['t'] ?? 's'); if($t==='n' && is_numeric($v)){ $rowsXml.='<c r="'.$ref.'" s="'.$s.'"><v>'.ce_xml((string)$v).'</v></c>'; } else { $rowsXml.='<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t>'.ce_xml((string)$v).'</t></is></c>'; } }
        $rowsXml.='</row>'; }
    $colsXml='<cols>'; foreach($widths as $i=>$w){ $col=$i+1; $colsXml.='<col min="'.$col.'" max="'.$col.'" width="'.number_format(max(8.0,(float)$w),2,'.','').'" customWidth="1"/>'; } $colsXml.='</cols>';
    $mergeXml=''; if(!empty($merges)){ $mergeXml='<mergeCells count="'.count($merges).'">'; foreach($merges as $m){ $mergeXml.='<mergeCell ref="'.ce_xml((string)$m).'"/>'; } $mergeXml.='</mergeCells>'; }
    $sheetXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$lastCol.$lastRow.'"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/>'.$colsXml.'<sheetData>'.$rowsXml.'</sheetData>'.$mergeXml.'<printOptions horizontalCentered="0" verticalCentered="0" headings="0" gridLines="0"/><pageMargins left="0.30" right="0.30" top="0.35" bottom="0.35" header="0.30" footer="0.30"/><pageSetup paperSize="9" orientation="'.$orientation.'" fitToWidth="1" fitToHeight="1"/></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><color rgb="FF1F2937"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font><font><b/><sz val="11"/><color rgb="FF111827"/><name val="Tahoma"/><family val="2"/></font></fonts><fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF741A2D"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF4CC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFA32A3E"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF8F4F5"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFE4A8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFB44556"/></left><right style="thin"><color rgb="FFB44556"/></right><top style="thin"><color rgb="FFB44556"/></top><bottom style="thin"><color rgb="FFB44556"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="14"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="4" fontId="0" fillId="5" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="4" fontId="2" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="4" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $safe=trim($sheetName)!=='' ? preg_replace('/[\\\/\?\*\[\]:]/','-',trim($sheetName)) : 'Claims Export'; $safe=trim((string)$safe); if($safe===''){$safe='Claims Export';} if(ce_len($safe)>31){ $safe=function_exists('mb_substr')?mb_substr($safe,0,31,'UTF-8'):substr($safe,0,31); }
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.ce_xml($safe).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $tmp=tempnam(sys_get_temp_dir(),'claims_xlsx_'); if($tmp===false){ throw new RuntimeException('Failed to allocate temp file for XLSX export.'); }
    $zip=new ZipArchive(); if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true){ @unlink($tmp); throw new RuntimeException('Failed to create XLSX archive.'); }
    $zip->addFromString('[Content_Types].xml',$types); $zip->addFromString('_rels/.rels',$rootRels); $zip->addFromString('xl/workbook.xml',$workbook); $zip->addFromString('xl/_rels/workbook.xml.rels',$wbRels); $zip->addFromString('xl/styles.xml',$styles); $zip->addFromString('xl/worksheets/sheet1.xml',$sheetXml); $zip->close();
    $bin=(string)file_get_contents($tmp); @unlink($tmp); if($bin===''){ throw new RuntimeException('Generated XLSX file is empty.'); } return $bin;
}
function ce_pdf_blocks(array $e): array {
    if (($e['kind'] ?? '') === 'grouped_claims') {
        $headers = (array)($e['headers'] ?? []);
        $widths = [8, 24, 15, 13, 15, 15, 15];
        $aligns = ['center', 'left', 'center', 'center', 'right', 'right', 'right'];
        $blocks = [
            ['style' => 'title', 'text' => $e['title']],
            ['style' => 'meta', 'text' => 'Generated: ' . $e['generated_at']],
            ['style' => 'meta', 'text' => 'Generated By: ' . $e['generated_by']],
            ['style' => 'meta', 'text' => 'Files Represented: ' . (int)($e['file_count'] ?? 0)],
            ['style' => 'meta', 'text' => 'Monthly Ledger Records: ' . (int)($e['record_count'] ?? 0)],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'section', 'text' => 'Grouped Claims Arrears by File']
        ];
        if (empty($e['groups'])) {
            $blocks[] = ['style' => 'detail', 'text' => 'No claims rows were available for export.'];
            return $blocks;
        }

        foreach ((array)($e['groups'] ?? []) as $group) {
            $blocks[] = ['style' => 'group_header', 'text' => 'File No: ' . (string)$group['fileNo'] . ' | Pensioner: ' . (string)$group['pensionerName'] . ' | Monthly Records: ' . (int)($group['recordCount'] ?? count((array)($group['rows'] ?? [])))];
            $blocks[] = ['style' => 'grid_header', 'cells' => $headers, 'widths' => $widths, 'aligns' => $aligns];
            foreach ((array)($group['rows'] ?? []) as $row) {
                $blocks[] = [
                    'style' => 'grid_row',
                    'cells' => [
                        (string)($row['entry'] ?? ''),
                        (string)($row['claimType'] ?? ''),
                        (string)($row['period'] ?? ''),
                        (string)($row['status'] ?? ''),
                        ce_money((float)($row['expectedAmount'] ?? 0)),
                        ce_money((float)($row['paidAmount'] ?? 0)),
                        ce_money((float)($row['balanceAmount'] ?? 0))
                    ],
                    'widths' => $widths,
                    'aligns' => $aligns
                ];
            }
            $blocks[] = [
                'style' => 'grid_subtotal',
                'merge_lead' => 4,
                'cells' => [
                    'Subtotal for ' . (string)($group['pensionerName'] ?? $group['fileNo'] ?? 'Unnamed Pensioner'),
                    '', '', '',
                    ce_money((float)($group['totals']['expectedAmount'] ?? 0)),
                    ce_money((float)($group['totals']['paidAmount'] ?? 0)),
                    ce_money((float)($group['totals']['balanceAmount'] ?? 0))
                ],
                'widths' => $widths,
                'aligns' => $aligns
            ];
            $blocks[] = ['style' => 'spacer', 'text' => ''];
        }

        $blocks[] = [
            'style' => 'grid_total',
            'merge_lead' => 4,
            'cells' => [
                'Grand Total of Claim Arrears',
                '', '', '',
                ce_money((float)($e['totals']['expectedAmount'] ?? 0)),
                ce_money((float)($e['totals']['paidAmount'] ?? 0)),
                ce_money((float)($e['totals']['balanceAmount'] ?? 0))
            ],
            'widths' => $widths,
            'aligns' => $aligns
        ];
        return $blocks;
    }

    $blocks=[['style'=>'title','text'=>$e['title']],['style'=>'meta','text'=>'Generated: '.$e['generated_at']],['style'=>'meta','text'=>'Generated By: '.$e['generated_by']],['style'=>'meta','text'=>'Records: '.count($e['rows'])],['style'=>'spacer','text'=>''],['style'=>'section','text'=>'Claims Table Data']];
    $widths=[]; $aligns=[];
    foreach($e['headers'] as $i=>$header){ $base=ce_len($header); $sample=(int)($e['sample_lengths'][$i] ?? $base); if($i===0){$widths[]=4; $aligns[]='center';} elseif(preg_match('/\\bname\\b/i',$header)){ $widths[]=min(40,max(18,$base,(int)ceil($sample/1.15))); $aligns[]='left'; } elseif(preg_match('/(amount|balance|paid|expected|total|entries|rows|matched|unmatched)/i',$header)){ $widths[]=min(18,max(10,$base,(int)ceil($sample/1.7))); $aligns[]='right'; } elseif(preg_match('/(period|date|status|type|reason)/i',$header)){ $widths[]=min(18,max(9,$base,(int)ceil($sample/1.9))); $aligns[]='center'; } else { $widths[]=min(22,max(10,$base,(int)ceil($sample/1.8))); $aligns[]='left'; } }
    $blocks[]=['style'=>'grid_header','cells'=>$e['headers'],'widths'=>$widths,'aligns'=>$aligns];
    if(empty($e['rows'])){ $blocks[]=['style'=>'detail','text'=>'No claims rows were available for export.']; return $blocks; }
    $lastIndex=count($e['rows'])-1;
    foreach($e['rows'] as $rowIndex=>$row){ $hasTotalMarker=((isset($row[0]) && trim((string)$row[0])==='Total') || (isset($row[1]) && trim((string)$row[1])==='Total')); $style=($lastIndex>=0 && $rowIndex===$lastIndex && $hasTotalMarker) ? 'grid_total' : 'grid_row'; $rowAligns=$aligns; $block=['style'=>$style,'cells'=>$row,'widths'=>$widths,'aligns'=>$rowAligns]; if($style==='grid_total' && count($row)>=3){ $block['merge_lead']=3; $rowAligns[0]='left'; $block['aligns']=$rowAligns; } $blocks[]=$block; }
    return $blocks;
}
function ce_pdf_binary(array $blocks,string $orientation,string $footer): string {
    return pgoRenderBlocksPdf($blocks, $orientation, [
        'title' => $footer,
        'footer' => $footer,
    ]);
}
function ce_send(string $binary,string $name,string $type): void {
    while(ob_get_level()>0){ ob_end_clean(); }
    $download = strtolower(trim((string)($_GET['download'] ?? $_POST['download'] ?? '')));
    $disposition = in_array($download, ['1','true','yes','download'], true) ? 'attachment' : 'inline';
    header('Content-Type: '.$type);
    header('Content-Length: '.strlen($binary));
    header('Content-Disposition: '.$disposition.'; filename="'.$name.'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Transfer-Encoding: binary');
    echo $binary;
}
try {
    $payload=json_decode(file_get_contents('php://input'), true);
    if(!is_array($payload)){ throw new RuntimeException('Invalid export request.'); }
    $format=strtolower(trim((string)($payload['format'] ?? 'xlsx'))); if(!in_array($format,['xlsx','pdf'],true)){$format='xlsx';}
    $actorName=trim((string)($_SESSION['userName'] ?? 'System User'));
    $kind = strtolower(trim((string)($payload['exportKind'] ?? '')));
    if (!empty($payload['serverSide']) && $kind === 'claims_ledger_grouped') {
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $payload['groups'] = ce_build_claims_ledger_groups($conn, $filters);
    }
    if (!empty($payload['serverSide']) && $kind === 'claims_aggregation_summary') {
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $dataset = ce_build_claims_aggregation_dataset($conn, $filters);
        $payload['headers'] = $dataset['headers'] ?? [];
        $payload['rows'] = $dataset['rows'] ?? [];
        $payload['title'] = trim((string)($payload['title'] ?? 'Arrears Summary'));
    }
    $export=ce_payload($payload,$actorName);
    $stamp=date('Ymd_His'); $base=preg_replace('/[^a-z0-9]+/i','_',strtolower($export['sheet_name'])) ?: 'claims_table'; $fileName=$base.'_'.$stamp.'.'.$format;
    $rowCount = ($export['kind'] ?? '') === 'grouped_claims' ? (int)($export['record_count'] ?? 0) : count((array)($export['rows'] ?? []));
    if($format==='pdf'){
        $blocks=ce_pdf_blocks($export); $orientation=(($export['kind'] ?? '') === 'grouped_claims') ? 'landscape' : ((count($export['headers'])>8 || array_sum((array)($export['sample_lengths'] ?? []))>120)?'landscape':'portrait'); $binary=ce_pdf_binary($blocks,$orientation,$export['title']);
        logAuditEvent($conn,['actor_id'=>(string)($_SESSION['userId'] ?? ''),'actor_name'=>$actorName,'actor_role'=>(string)($_SESSION['userRole'] ?? ''),'action'=>'claims_table_exported','entity_type'=>'claims_table_export','entity_id'=>$base,'details'=>['format'=>'pdf','title'=>$export['title'],'row_count'=>$rowCount]]);
        ce_send($binary,$fileName,'application/pdf'); exit;
    }
    $binary=ce_xlsx_binary(ce_xlsx_report($export),$export['sheet_name']);
    logAuditEvent($conn,['actor_id'=>(string)($_SESSION['userId'] ?? ''),'actor_name'=>$actorName,'actor_role'=>(string)($_SESSION['userRole'] ?? ''),'action'=>'claims_table_exported','entity_type'=>'claims_table_export','entity_id'=>$base,'details'=>['format'=>'xlsx','title'=>$export['title'],'row_count'=>$rowCount]]);
    ce_send($binary,$fileName,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>$error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if(isset($conn) && $conn instanceof mysqli){ $conn->close(); }
}
