<?php

ob_start();
require_once __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/api/import_common.php';
require_once __DIR__ . '/../backend/api/payroll_source_common.php';
require_once __DIR__ . '/../backend/api/payroll_payment_register_common.php';

$payrollPath = 'C:/Users/Dell/Downloads/Pension Payroll for June, 2026.xlsx';
$registerPath = 'C:/Users/Dell/Downloads/pension jun  2026 Payment Register.pdf';
if (!is_file($payrollPath) || !is_file($registerPath)) { echo "SKIP payroll fixtures unavailable\n"; exit(0); }

ensurePayrollManagementTables($conn);
$sheets = payrollReadXlsxSheets($payrollPath);
$valid = normalizeGovernmentPayrollScheduleRows($sheets['Payroll']);
$classified = extractGovernmentPayrollClassifiedData($sheets['Payroll']);
$classified['entries'] = array_merge($classified['entries'], extractGovernmentPayrollStatistics($sheets['Statistics'] ?? []));
$register = parsePayrollPaymentRegisterPdf($registerPath);

if (count($valid['rows']) !== 2089) throw new RuntimeException('Valid-payment fixture count changed.');
if (count($register['entries']) !== 2094) throw new RuntimeException('Payment-register fixture count changed.');

$conn->begin_transaction();
try {
    $conn->query("INSERT INTO tb_payroll_upload_cycles (payroll_year,payroll_month,financial_year_label,quarter_label,notes) VALUES (2099,12,'FY 2099/2100','Q2','Automated rollback test')");
    $cycleId=(int)$conn->insert_id;
    $insert=$conn->prepare("INSERT INTO tb_payroll_upload_entries (cycle_id,supplierNo,beneficiary_name,amount,invoice_number,source_section,source_row_number) VALUES (?,?,?,?,?,'VALID_PAYMENTS',?)");
    foreach($valid['rows'] as $row){$supplier=$row['supplierNo'];$name=$row['beneficiary'];$amount=$row['amount'];$invoice=$row['invoice_number'];$sourceRow=$row['row_number'];$insert->bind_param('issdsi',$cycleId,$supplier,$name,$amount,$invoice,$sourceRow);$insert->execute();}$insert->close();
    $sectionStats=storeGovernmentPayrollClassifiedData($conn,$cycleId,$classified);
    $paymentStats=reconcilePayrollPaymentRegister($conn,$cycleId,$register['entries']);
    $expected=['Paid in Full'=>2086,'Partially Paid'=>2,'Paid with Adjustment'=>0,'Not in Register'=>1,'Register Only'=>5,'Needs Review'=>1];
    foreach($expected as $status=>$count)if(($paymentStats[$status]??-1)!==$count)throw new RuntimeException("{$status}: expected {$count}, got ".($paymentStats[$status]??-1));
    if(($sectionStats['classified_count']??0)!==133)throw new RuntimeException('Expected 133 classified exception/statistics entries.');
    $pending=(int)$conn->query("SELECT COUNT(*) AS total FROM tb_payroll_classified_entries WHERE cycle_id={$cycleId} AND review_status='Pending Review'")->fetch_assoc()['total'];
    if($pending!==133)throw new RuntimeException('Classified entries must remain pending review after ingestion.');
    $badMasks=0;$maskResult=$conn->query("SELECT account_number_masked FROM tb_payroll_payment_register_entries WHERE cycle_id={$cycleId} AND amount_paid>0");while($maskRow=$maskResult->fetch_assoc())if(!preg_match('/^\*+\d{4}$/',(string)$maskRow['account_number_masked']))$badMasks++;
    if($badMasks!==0)throw new RuntimeException('Payment register account masking validation failed.');
    $variance=(int)$conn->query("SELECT COUNT(*) AS total FROM tb_payroll_section_summaries WHERE cycle_id={$cycleId} AND validation_status='Variance'")->fetch_assoc()['total'];
    if($variance!==0)throw new RuntimeException("Expected all reported section totals to validate; {$variance} variance(s) found.");
    echo 'PASS full payroll ingestion: '.json_encode(['valid'=>2089,'classified'=>133,'register'=>2094,'reconciliation'=>$paymentStats])."\n";
} finally {
    $conn->rollback();
    $conn->close();
}
