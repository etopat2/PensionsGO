<?php

function payrollPdfAutoload(): void
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('The deployable PDF parser dependency is not installed.');
    require_once $autoload;
    if (!class_exists('Smalot\\PdfParser\\Parser')) throw new RuntimeException('The PDF parser dependency could not be loaded.');
}

function payrollMaskAccount(string $account): string
{
    $digits = preg_replace('/\D+/', '', $account);
    if ($digits === '') return '';
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function payrollNormalizeBankName(string $bank): string
{
    $value=strtoupper(trim(preg_replace('/\s+/',' ',$bank)));
    $prefixes=['CENTENARY RURAL'=>'CENTENARY RURAL DEVELOPMENT BANK LIMITED','ABSA BANK UGANDA'=>'ABSA BANK UGANDA LIMITED','BANK OF AFRICA'=>'BANK OF AFRICA UGANDA LIMITED','EQUITY BANK UGANDA'=>'EQUITY BANK UGANDA LIMITED','FINANCE TRUST BANK UGANDA'=>'FINANCE TRUST BANK UGANDA LIMITED','HOUSING FINANCE BANK'=>'HOUSING FINANCE BANK UGANDA LIMITED','STANBIC BANK UGANDA'=>'STANBIC BANK UGANDA LIMITED'];
    foreach($prefixes as $prefix=>$canonical)if(str_starts_with($value,$prefix))return $canonical;
    return $value;
}

function payrollParseRegisterDate(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!d-M-y', strtoupper(trim($value)));
    return $date ? $date->format('Y-m-d') : null;
}

function parsePayrollPaymentRegisterPdf(string $path): array
{
    payrollPdfAutoload();
    $parser = new Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($path);
    $pages = $pdf->getPages();
    if (!$pages) throw new RuntimeException('The payment register PDF contains no readable pages.');

    $entries = [];
    $duplicates = [];
    foreach ($pages as $pageIndex => $page) {
        $text = (string)$page->getText();
        preg_match_all(
            '~(?:^|\R)\s*(\d+)\s+(.+?)\s+(\1-\d{1,2}[A-Z]{3}\d{2}-\d+)\s+(\d{1,2}-[A-Z]{3}-\d{2})\s+([\d,]+)\s+(\d+)\s+(.+?)(?=(?:\R\s*\d+\s+.+?\s+\d+-\d{1,2}[A-Z]{3}\d{2}-\d+)|\z)~is',
            $text,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $invoice = strtoupper(trim($match[3]));
            if (isset($entries[$invoice])) { $duplicates[$invoice] = true; continue; }
            $tail = trim(preg_replace('/\s+/', ' ', $match[7]));
            $account = '';
            $detailPattern = '~' . preg_quote($invoice, '~') . '\s+' . preg_quote($match[4], '~') . '\s+' . preg_quote($match[5], '~') . '\s+' . preg_quote($match[6], '~') . '\s+(.+?)\s+(\d{6,20})(?:\s|$)~is';
            if (preg_match($detailPattern, $text, $detailMatch)) {
                $tail = trim(preg_replace('/\s+/', ' ', $detailMatch[1]));
                $account = $detailMatch[2];
            } elseif (preg_match('/(?:^|\s)(\d{6,20})\s*$/', $tail, $accountMatch)) {
                $account = $accountMatch[1];
                $tail = trim(substr($tail, 0, -strlen($accountMatch[0])));
            }
            // Page-boundary bank/account continuations cannot be guessed; they remain reviewable.
            $entries[$invoice] = [
                'supplierNo' => trim($match[1]),
                'supplier_name' => trim(preg_replace('/\s+/', ' ', $match[2])),
                'invoice_number' => $invoice,
                'payment_date' => payrollParseRegisterDate($match[4]),
                'amount_paid' => (float)str_replace(',', '', $match[5]),
                'eft_number' => trim($match[6]),
                'bank_name' => payrollNormalizeBankName($tail),
                'account_number_masked' => payrollMaskAccount($account),
                'source_page' => $pageIndex + 1,
                'parse_needs_review' => $account === '' || $tail === ''
            ];
        }
        preg_match_all('~(?:^|\R)\s*(\d+)\s+(.+?)\s+(IN\s+\d{1,2}/\d{1,2}/\d{2})\s+(\d{1,2}-[A-Z]{3}-\d{2})\s+([\d,]+)\s+(\d+)\s+(.+?)\s+(\d{6,20})(?:\s|$)~is', $text, $specialMatches, PREG_SET_ORDER);
        foreach ($specialMatches as $match) {
            $key = 'NONSTANDARD:' . trim($match[1]) . ':' . trim($match[6]);
            $entries[$key] = [
                'supplierNo'=>trim($match[1]), 'supplier_name'=>trim(preg_replace('/\s+/',' ',$match[2])),
                'invoice_number'=>strtoupper(trim(preg_replace('/\s+/',' ',$match[3]))), 'payment_date'=>payrollParseRegisterDate($match[4]),
                'amount_paid'=>(float)str_replace(',','',$match[5]), 'eft_number'=>trim($match[6]),
                'bank_name'=>payrollNormalizeBankName($match[7]), 'account_number_masked'=>payrollMaskAccount($match[8]),
                'source_page'=>$pageIndex+1, 'parse_needs_review'=>true
            ];
        }
    }
    if (!$entries) throw new RuntimeException('No payment-register rows could be extracted from the PDF.');
    if ($duplicates) throw new RuntimeException('Duplicate payment-register invoice numbers were found: ' . implode(', ', array_slice(array_keys($duplicates), 0, 10)));
    return [
        'entries' => array_values($entries),
        'page_count' => count($pages),
        'duplicate_invoices' => array_keys($duplicates),
        'text_length' => strlen((string)$pdf->getText())
    ];
}

function reconcilePayrollPaymentRegister(mysqli $conn, int $cycleId, array $registerEntries): array
{
    $conn->query('DELETE FROM tb_payroll_payment_register_entries WHERE cycle_id = ' . (int)$cycleId);
    $payroll = []; $allPayroll = []; $supplierAmountMap = [];
    $stmt = $conn->prepare('SELECT entry_id, supplierNo, invoice_number, amount, matched_regNo FROM tb_payroll_upload_entries WHERE cycle_id = ?');
    $stmt->bind_param('i', $cycleId); $stmt->execute(); $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $invoice = strtoupper(trim((string)($row['invoice_number'] ?? '')));
        $entryId=(int)$row['entry_id'];$allPayroll[$entryId]=$row;
        if ($invoice !== '') $payroll[$invoice] = $row;
        $fallbackKey=strtolower(trim((string)$row['supplierNo'])).'|'.number_format((float)$row['amount'],2,'.','');$supplierAmountMap[$fallbackKey][]=$row;
    }
    $stmt->close();

    $insert = $conn->prepare("INSERT INTO tb_payroll_payment_register_entries
        (cycle_id,supplierNo,supplier_name,invoice_number,payment_date,amount_paid,eft_number,bank_name,account_number_masked,matched_payroll_entry_id,matched_regNo,reconciliation_status,amount_variance,match_confidence,review_status,review_note,source_page)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$insert) throw new RuntimeException('Unable to prepare payment register reconciliation storage.');
    $seen = []; $seenPayrollEntries=[]; $stats = array_fill_keys(['Paid in Full','Partially Paid','Paid with Adjustment','Not in Register','Register Only','Needs Review'], 0);
    foreach ($registerEntries as $entry) {
        $invoice = strtoupper(trim((string)$entry['invoice_number'])); $seen[$invoice] = true;
        $match = $payroll[$invoice] ?? null;$fallbackMatched=false;
        if(!$match){$fallbackKey=strtolower(trim((string)$entry['supplierNo'])).'|'.number_format((float)$entry['amount_paid'],2,'.','');$candidates=$supplierAmountMap[$fallbackKey]??[];if(count($candidates)===1){$match=$candidates[0];$fallbackMatched=true;}}
        $status = 'Register Only'; $confidence = 0.0; $review = 'Needs Review'; $note = 'No payroll invoice matched this successful payment.';
        $payrollEntryId = null; $regNo = null; $variance = (float)$entry['amount_paid'];
        if ($match) {
            $payrollEntryId = (int)$match['entry_id']; $seenPayrollEntries[$payrollEntryId]=true; $regNo = $match['matched_regNo'] ?: null;
            $variance = round((float)$entry['amount_paid'] - (float)$match['amount'], 2);
            $supplierMatches = trim((string)$match['supplierNo']) === trim((string)$entry['supplierNo']);
            $confidence = $fallbackMatched ? 85.0 : ($supplierMatches ? 100.0 : 80.0);
            if (abs($variance) < 0.01) $status = 'Paid in Full';
            elseif ((float)$entry['amount_paid'] > 0 && (float)$entry['amount_paid'] < (float)$match['amount']) $status = 'Partially Paid';
            else $status = 'Paid with Adjustment';
            $review = (!$fallbackMatched && $supplierMatches && $status === 'Paid in Full' && empty($entry['parse_needs_review'])) ? 'Auto Matched' : 'Needs Review';
            $note = $fallbackMatched?'Matched by unique supplier number and exact amount because payroll invoice metadata was unavailable.':(!$supplierMatches ? 'Invoice matched but supplier number differs.' : ($status === 'Paid in Full' ? (empty($entry['parse_needs_review']) ? 'Exact invoice, supplier and amount match.' : 'Payment matched; bank/account text needs review.') : 'Invoice matched with an amount variance.'));
        }
        if (!empty($entry['parse_needs_review']) && !$match) $status = 'Needs Review';
        $stats[$status]++;
        $supplier=$entry['supplierNo'];$name=$entry['supplier_name'];$date=$entry['payment_date'];$paid=$entry['amount_paid'];$eft=$entry['eft_number'];$bank=$entry['bank_name'];$masked=$entry['account_number_masked'];$page=$entry['source_page'];
        $insert->bind_param('issssdsssissddssi',$cycleId,$supplier,$name,$invoice,$date,$paid,$eft,$bank,$masked,$payrollEntryId,$regNo,$status,$variance,$confidence,$review,$note,$page);$insert->execute();
    }
    foreach ($allPayroll as $entryId => $match) {
        $invoice=strtoupper(trim((string)($match['invoice_number']??'')));if(isset($seenPayrollEntries[$entryId])||($invoice!==''&&isset($seen[$invoice]))) continue;if($invoice==='')$invoice='PAYROLL-ENTRY-'.$entryId;
        $status='Not in Register';$review='Needs Review';$note='Payroll invoice was not found in the successful-payment register.';$supplier=$match['supplierNo'];$name='';$date=null;$paid=0.0;$eft='';$bank='';$masked='';$payrollEntryId=(int)$match['entry_id'];$regNo=$match['matched_regNo']?:null;$variance=-(float)$match['amount'];$confidence=100.0;$page=null;
        $insert->bind_param('issssdsssissddssi',$cycleId,$supplier,$name,$invoice,$date,$paid,$eft,$bank,$masked,$payrollEntryId,$regNo,$status,$variance,$confidence,$review,$note,$page);$insert->execute();$stats[$status]++;
    }
    $insert->close();
    return $stats;
}
