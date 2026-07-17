<?php

/**
 * Recognises the multi-section Uganda Prisons pension payroll schedule and
 * extracts Section (E) VALID PAYMENTS. Returns null for ordinary templates.
 */
function normalizeGovernmentPayrollScheduleRows(array $rows): ?array
{
    $sectionStart = null;
    foreach ($rows as $index => $row) {
        foreach ((array)$row as $value) {
            $label = strtoupper(preg_replace('/\s+/', ' ', trim((string)$value)));
            if (strpos($label, 'VALID PAYMENT') !== false && preg_match('/\([A-Z]\)/', $label)) {
                $sectionStart = (int)$index + 1;
                break 2;
            }
        }
    }
    if ($sectionStart === null) return null;

    $normalized = [];
    $review = [];
    $headers = ['Serial Number', 'Supplier Number', 'Payroll Amount', 'Payroll Date', 'Invoice Number', 'Beneficiary Name', 'Description'];
    for ($index = $sectionStart; $index < count($rows); $index++) {
        $row = array_values((array)$rows[$index]);
        $supplier = trim((string)($row[1] ?? ''));
        $invoiceIndex = -1;
        $invoice = '';
        foreach ($row as $cellIndex => $cellValue) {
            $candidate = strtoupper(trim((string)$cellValue));
            if (preg_match('/^\d+-\d{1,2}[A-Z]{3}\d{2}-\d+$/', $candidate)) {
                $invoiceIndex = (int)$cellIndex;
                $invoice = $candidate;
                break;
            }
        }

        // A new labelled section or total row marks the end of valid payments.
        $joined = strtoupper(trim(implode(' ', array_map('strval', $row))));
        if ($normalized && ($supplier === 'TOTAL' || preg_match('/\([A-Z]\)\s+[A-Z]/', $joined))) break;
        if (!preg_match('/^\d+$/', $supplier) || $invoiceIndex < 0) continue;

        $amountText = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)($row[2] ?? '0')));
        $amount = is_numeric($amountText) ? (float)$amountText : 0.0;
        $beneficiary = trim((string)($row[5] ?? ''));
        if ($beneficiary === '') $beneficiary = trim((string)($row[$invoiceIndex + 1] ?? ''));
        $description = trim((string)($row[6] ?? ''));

        $normalized[] = [
            'row_number' => $index + 1,
            'supplierNo' => $supplier,
            'beneficiary' => $beneficiary,
            'amount' => $amount,
            'invoice_number' => $invoice,
            'source_section' => 'VALID_PAYMENTS',
            'source_row' => $row
        ];
    }

    if (!$normalized) {
        $review[] = [
            'Source Row' => $sectionStart + 1,
            'Review Status' => 'Invalid',
            'Review Reason' => 'Section (E) VALID PAYMENTS was found, but no rows with supplier and invoice numbers could be extracted.',
            'Review Fields' => 'Supplier Number, Invoice Number',
            'Matched Key' => ''
        ];
    }

    return [
        'rows' => $normalized,
        'review_rows' => $review,
        'review_columns' => array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $headers),
        'source_format' => 'government_multi_section',
        'source_section' => 'VALID_PAYMENTS'
    ];
}

function extractGovernmentPayrollClassifiedData(array $rows): array
{
    $sectionAliases = [
        'SUSPENDED PAYMENT' => 'SUSPENDED',
        'RECOVERY' => 'RECOVERY',
        'PENSION ARREARS' => 'PENSION_ARREARS',
        'CAPTURED IN ERROR' => 'CAPTURED_ERROR',
        'VALID PAYMENT' => 'VALID_PAYMENTS'
    ];
    $current = null; $entries = [];
    foreach ($rows as $index => $sourceRow) {
        $row = array_values((array)$sourceRow);
        $joined = strtoupper(preg_replace('/\s+/', ' ', implode(' ', array_map('strval', $row))));
        if (preg_match('/\([A-Z]\)/', $joined)) {
            foreach ($sectionAliases as $needle => $code) if (strpos($joined, $needle) !== false) { $current = $code; continue 2; }
        }
        if ($current === null || $current === 'VALID_PAYMENTS') continue;
        if (preg_match('/^TOTAL\b/', trim($joined)) || in_array('TOTAL', array_map(static fn($v)=>strtoupper(trim((string)$v)), $row), true)) { $current = null; continue; }
        $supplier = trim((string)($row[1] ?? ''));
        if (!preg_match('/^\d+$/', $supplier)) continue;
        $number = static function ($value): float {
            $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string)$value));
            return is_numeric($clean) ? (float)$clean : 0.0;
        };
        if ($current === 'RECOVERY') {
            $entries[] = ['source_section'=>$current,'source_row_number'=>$index+1,'supplierNo'=>$supplier,'beneficiary'=>trim((string)($row[6]??'')),'invoice_number'=>'','appeared_amount'=>$number($row[2]??0),'recovery_amount'=>$number($row[3]??0),'payable_amount'=>$number($row[4]??0),'reason'=>trim((string)($row[5]??''))];
            continue;
        }
        $invoice = '';
        foreach ($row as $cell) if (preg_match('/^\d+-\d{1,2}[A-Z]{3}\d{2}-\d+$/', strtoupper(trim((string)$cell)))) { $invoice = strtoupper(trim((string)$cell)); break; }
        $entries[] = ['source_section'=>$current,'source_row_number'=>$index+1,'supplierNo'=>$supplier,'beneficiary'=>trim((string)($row[5]??'')),'invoice_number'=>$invoice,'appeared_amount'=>$number($row[2]??0),'recovery_amount'=>0.0,'payable_amount'=>0.0,'reason'=>trim((string)($row[6]??''))];
    }

    $summaries = [];
    $header = array_values((array)($rows[2] ?? []));
    $countIndex = $appearedIndex = $payableIndex = -1;
    foreach ($header as $i => $label) {
        $label = strtoupper(trim((string)$label));
        if (strpos($label, 'NUMBER') !== false) $countIndex = $i;
        elseif (strpos($label, 'APPEAR') !== false || $label === 'AMOUNT') $appearedIndex = $i;
        elseif (strpos($label, 'PAYABLE') !== false) $payableIndex = $i;
    }
    for ($i=3; $i<min(15,count($rows)); $i++) {
        $row=array_values((array)$rows[$i]);$description=strtoupper(trim((string)($row[2]??'')));$code=null;
        foreach($sectionAliases as $needle=>$candidate) if(strpos($description,$needle)!==false){$code=$candidate;break;}if(!$code&&strpos($description,'VALID RECORD')!==false)$code='VALID_PAYMENTS';
        if(!$code) continue;
        $num=static function($v){$c=preg_replace('/[^0-9.\-]/','',str_replace(',','',(string)$v));return is_numeric($c)?(float)$c:null;};
        $summaries[$code]=['source_section'=>$code,'reported_count'=>$countIndex>=0?(int)($num($row[$countIndex]??null)??0):null,'reported_appeared_amount'=>$appearedIndex>=0?$num($row[$appearedIndex]??null):null,'reported_payable_amount'=>$payableIndex>=0?$num($row[$payableIndex]??null):null];
    }
    foreach(array_slice($rows,0,15) as $row){$row=array_values((array)$row);$description=strtoupper(trim((string)($row[2]??'')));$code=strpos($description,'NEW ON PAYROLL')!==false?'NEW_ON_PAYROLL':(strpos($description,'REINSTATEMENT')!==false?'REINSTATEMENT':(strpos($description,'WENT OFF')!==false?'WENT_OFF':null));if($code)$summaries[$code]=['source_section'=>$code,'reported_count'=>(int)preg_replace('/\D/','',(string)($row[4]??0)),'reported_appeared_amount'=>null,'reported_payable_amount'=>null];}
    return ['entries'=>$entries,'summaries'=>$summaries];
}

function payrollReadXlsxSheets(string $path): array
{
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Unable to open payroll workbook.');
    $shared=[];$sharedXml=$zip->getFromName('xl/sharedStrings.xml');
    if($sharedXml!==false){$xml=simplexml_load_string($sharedXml);if($xml){$ns=$xml->getNamespaces(true);$root=isset($ns[''])?$xml->children($ns['']):$xml;foreach($root->si as $si){$node=isset($ns[''])?$si->children($ns['']):$si;$value='';if(isset($node->t))$value=(string)$node->t;else foreach($node->r as $run){$rn=isset($ns[''])?$run->children($ns['']):$run;$value.=(string)($rn->t??'');}$shared[]=trim($value);}}}
    $sheets=[];for($n=1;;$n++){$sheetXml=$zip->getFromName("xl/worksheets/sheet{$n}.xml");if($sheetXml===false)break;$xml=simplexml_load_string($sheetXml);$rows=[];if($xml){$ns=$xml->getNamespaces(true);$root=isset($ns[''])?$xml->children($ns['']):$xml;foreach($root->sheetData->row as $row){$data=[];foreach($row->c as $cell){$attrs=$cell->attributes();$ref=strtoupper((string)($attrs['r']??''));preg_match('/^([A-Z]+)/',$ref,$m);$col=0;foreach(str_split($m[1]??'A') as $letter)$col=$col*26+ord($letter)-64;$col--;$type=strtolower((string)($attrs['t']??''));$node=isset($ns[''])?$cell->children($ns['']):$cell;$value='';if($type==='s')$value=$shared[(int)($node->v??0)]??'';elseif($type==='inlinestr')$value=(string)($node->is->t??'');else $value=(string)($node->v??'');$data[$col]=trim($value);}if($data){ksort($data);$rows[]=$data;}}}$sheets[$n===1?'Payroll':($n===2?'Statistics':"Sheet {$n}")]=$rows;}
    $zip->close();return $sheets;
}

function extractGovernmentPayrollStatistics(array $rows): array
{
    $entries=[];$current=null;
    foreach($rows as $index=>$sourceRow){$row=array_values((array)$sourceRow);$joined=strtoupper(trim(preg_replace('/\s+/',' ',implode(' ',array_map('strval',$row)))));
        if(preg_match('/^NEW\b/',$joined)){$current='NEW_ON_PAYROLL';if(count(array_filter($row))<=2)continue;}
        if(strpos($joined,'REINSTATEMENT')!==false){$current='REINSTATEMENT';if(count(array_filter($row))<=2)continue;}
        if(strpos($joined,'WENT OFF')!==false){$current='WENT_OFF';continue;}
        $supplier=trim((string)($row[1]??''));if(!$current||!preg_match('/^\d+$/',$supplier))continue;
        $category=strtoupper(trim((string)end($row)));$rowSection=$category==='NEW'?'NEW_ON_PAYROLL':($category==='REINSTATEMENT'?'REINSTATEMENT':$current);
        $amountText=preg_replace('/[^0-9.\-]/','',str_replace(',','',(string)($row[2]??0)));$amount=is_numeric($amountText)?(float)$amountText:0.0;$invoice='';foreach($row as $cell)if(preg_match('/^\d+-\d{1,2}[A-Z]{3}\d{2}-\d+$/',strtoupper(trim((string)$cell)))){$invoice=strtoupper(trim((string)$cell));break;}
        $name='';for($i=count($row)-1;$i>=3;$i--){$candidate=trim((string)$row[$i]);if($candidate!==''&&!preg_match('/^(NEW|REINSTATEMENT|WENT OFF)$/i',$candidate)&&$candidate!==$invoice){$name=$candidate;break;}}
        $entries[]=['source_section'=>$rowSection,'source_sheet'=>'Statistics','source_row_number'=>$index+1,'supplierNo'=>$supplier,'beneficiary'=>$name,'invoice_number'=>$invoice,'appeared_amount'=>$amount,'payable_amount'=>0.0,'recovery_amount'=>0.0,'reason'=>'Imported from payroll Statistics sheet for officer review.'];
    }return $entries;
}

function storeGovernmentPayrollClassifiedData(mysqli $conn, int $cycleId, array $data): array
{
    $conn->query('DELETE FROM tb_payroll_classified_entries WHERE cycle_id='.(int)$cycleId);
    $conn->query('DELETE FROM tb_payroll_section_summaries WHERE cycle_id='.(int)$cycleId);
    $registry=[];$result=$conn->query("SELECT id,regNo,supplierNo FROM tb_fileregistry WHERE supplierNo IS NOT NULL AND TRIM(supplierNo)<>''");
    while($result&&$row=$result->fetch_assoc())$registry[strtolower(trim($row['supplierNo']))]=$row;
    $insert=$conn->prepare("INSERT INTO tb_payroll_classified_entries (cycle_id,source_section,source_sheet,source_row_number,supplierNo,beneficiary_name,invoice_number,appeared_amount,payable_amount,recovery_amount,reason,matched_regNo,matched_registry_id,review_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $counts=[];$amounts=[];$payables=[];
    foreach((array)($data['entries']??[]) as $entry){$section=$entry['source_section'];$sourceSheet=$entry['source_sheet']??'Payroll';$rowNo=$entry['source_row_number'];$supplier=$entry['supplierNo'];$name=$entry['beneficiary'];$invoice=$entry['invoice_number']?:null;$appeared=$entry['appeared_amount'];$payable=$entry['payable_amount'];$recovery=$entry['recovery_amount'];$reason=$entry['reason'];$match=$registry[strtolower($supplier)]??null;$regNo=$match['regNo']??null;$registryId=$match?(int)$match['id']:null;if(in_array($section,['NEW_ON_PAYROLL','REINSTATEMENT','WENT_OFF'],true))$reason.= $match?' Corresponding pension registry record matched by supplier number.':' No corresponding pension registry supplier number was found; identity review is required.';$review='Pending Review';$insert->bind_param('ississsdddssis',$cycleId,$section,$sourceSheet,$rowNo,$supplier,$name,$invoice,$appeared,$payable,$recovery,$reason,$regNo,$registryId,$review);$insert->execute();$counts[$section]=($counts[$section]??0)+1;$amounts[$section]=($amounts[$section]??0)+$appeared;$payables[$section]=($payables[$section]??0)+$payable;}
    $insert->close();$classifiedTotal=array_sum($counts);
    $validStmt=$conn->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(amount),0) AS amount FROM tb_payroll_upload_entries WHERE cycle_id=?');$validStmt->bind_param('i',$cycleId);$validStmt->execute();$valid=$validStmt->get_result()->fetch_assoc();$validStmt->close();$counts['VALID_PAYMENTS']=(int)($valid['total']??0);$amounts['VALID_PAYMENTS']=(float)($valid['amount']??0);$payables['VALID_PAYMENTS']=$amounts['VALID_PAYMENTS'];
    $summaryInsert=$conn->prepare("INSERT INTO tb_payroll_section_summaries (cycle_id,source_section,reported_count,extracted_count,reported_appeared_amount,extracted_appeared_amount,reported_payable_amount,extracted_payable_amount,validation_status,validation_note) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $sections=array_unique(array_merge(array_keys((array)($data['summaries']??[])),array_keys($counts)));
    foreach($sections as $section){$reported=$data['summaries'][$section]??[];$reportedCount=$reported['reported_count']??null;$extractedCount=$counts[$section]??0;$reportedAmount=$reported['reported_appeared_amount']??null;$extractedAmount=$amounts[$section]??0.0;$reportedPayable=$reported['reported_payable_amount']??null;$extractedPayable=$payables[$section]??0.0;$countOk=$reportedCount===null||$reportedCount===$extractedCount;$amountOk=$reportedAmount===null||abs($reportedAmount-$extractedAmount)<1.0;$hasReported=$reportedCount!==null||$reportedAmount!==null||$reportedPayable!==null;$status=!$hasReported?'Not Reported':(($countOk&&$amountOk)?'Matched':'Variance');$note=$status==='Matched'?'Reported section totals match extracted classified rows.':($status==='Not Reported'?'The workbook did not report a summary total for this section; extracted rows are retained for review.':'Reported totals differ from extracted rows and require review.');$summaryInsert->bind_param('isiiddddss',$cycleId,$section,$reportedCount,$extractedCount,$reportedAmount,$extractedAmount,$reportedPayable,$extractedPayable,$status,$note);$summaryInsert->execute();}
    $summaryInsert->close();return ['classified_count'=>$classifiedTotal,'section_counts'=>$counts];
}
