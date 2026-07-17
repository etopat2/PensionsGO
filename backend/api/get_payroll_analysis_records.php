<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['userId']) || strtolower(trim((string)($_SESSION['userRole'] ?? ''))) === 'pensioner') {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}
ensurePayrollManagementTables($conn);
$cycleId=(int)($_GET['cycle_id']??0); $dataset=(string)($_GET['dataset']??'classified');
$section=trim((string)($_GET['section']??'')); $status=trim((string)($_GET['status']??''));
$review=trim((string)($_GET['review_status']??'')); $search=trim((string)($_GET['search']??''));
$page=max(1,(int)($_GET['page']??1)); $limit=min(100,max(10,(int)($_GET['limit']??20)));
if($cycleId<=0 || !in_array($dataset,['classified','payment','payroll'],true)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid analysis request']);exit;}

function bindDynamic(mysqli_stmt $stmt,string $types,array &$values):void { if($types==='')return; $refs=[]; foreach($values as $k=>&$v)$refs[$k]=&$v; $stmt->bind_param($types,...$refs); }
function runScalar(mysqli $conn,string $sql,string $types,array $values):array { $s=$conn->prepare($sql);bindDynamic($s,$types,$values);$s->execute();$r=$s->get_result()->fetch_assoc()?:[];$s->close();return $r; }

$params=[$cycleId];$types='i';$where=' WHERE cycle_id=?';
if($dataset==='classified' && $section!==''){$where.=' AND source_section=?';$types.='s';$params[]=$section;}
if($dataset==='payment'){
    if($status==='exceptions')$where.=" AND (reconciliation_status<>'Paid in Full' OR review_status='Needs Review')";
    elseif($status!==''){$where.=' AND reconciliation_status=?';$types.='s';$params[]=$status;}
}
if($dataset==='payroll' && in_array($status,['matched','unmatched'],true)){$where.=' AND is_matched=?';$types.='i';$params[]=$status==='matched'?1:0;}
if($search!==''){
    $like='%'.$search.'%';
    if($dataset==='classified')$cols=['supplierNo','beneficiary_name','invoice_number','reason','matched_regNo','source_sheet'];
    elseif($dataset==='payment')$cols=['supplierNo','supplier_name','invoice_number','eft_number','bank_name','matched_regNo'];
    else $cols=['supplierNo','beneficiary_name','invoice_number','matched_regNo'];
    $where.=' AND ('.implode(' OR ',array_map(fn($c)=>"$c LIKE ?",$cols)).')'; foreach($cols as $_){$types.='s';$params[]=$like;}
}
$table=$dataset==='classified'?'tb_payroll_classified_entries':($dataset==='payment'?'tb_payroll_payment_register_entries':'tb_payroll_upload_entries');
$availableReviewStatuses=[];
if($dataset!=='payroll'){
    $facetStmt=$conn->prepare("SELECT review_status,COUNT(*) AS total FROM $table$where AND review_status IS NOT NULL AND TRIM(review_status)<>'' GROUP BY review_status ORDER BY MIN(".($dataset==='classified'?'classified_entry_id':'register_entry_id').")");
    bindDynamic($facetStmt,$types,$params);$facetStmt->execute();$facetResult=$facetStmt->get_result();while($facet=$facetResult->fetch_assoc())$availableReviewStatuses[]=['status'=>(string)$facet['review_status'],'count'=>(int)$facet['total']];$facetStmt->close();
}
if($review!=='' && $dataset!=='payroll'){$where.=' AND review_status=?';$types.='s';$params[]=$review;}
$amount=$dataset==='classified'?'COALESCE(SUM(CASE WHEN source_section=\'RECOVERY\' THEN payable_amount ELSE appeared_amount END),0)':($dataset==='payment'?'COALESCE(SUM(amount_paid),0)':'COALESCE(SUM(amount),0)');
$tot=runScalar($conn,"SELECT COUNT(*) total,$amount total_amount FROM $table$where",$types,$params);$total=(int)($tot['total']??0);$pages=max(1,(int)ceil($total/$limit));$page=min($page,$pages);$offset=($page-1)*$limit;
if($dataset==='classified')$select='classified_entry_id,source_section,source_sheet,source_row_number,supplierNo,beneficiary_name,invoice_number,appeared_amount,payable_amount,recovery_amount,reason,matched_regNo,review_status';
elseif($dataset==='payment')$select='register_entry_id,supplierNo,supplier_name,invoice_number,payment_date,amount_paid,eft_number,bank_name,account_number_masked,matched_regNo,reconciliation_status,amount_variance,match_confidence,review_status,review_note,source_page';
else $select='entry_id,supplierNo,beneficiary_name,amount,invoice_number,source_section,source_row_number,matched_regNo,is_matched';
$sql="SELECT $select FROM $table$where ORDER BY ".($dataset==='classified'?'source_row_number,classified_entry_id':($dataset==='payment'?'register_entry_id':'entry_id')).' LIMIT ? OFFSET ?';$listParams=$params;$listParams[]=$limit;$listParams[]=$offset;$s=$conn->prepare($sql);$listTypes=$types.'ii';bindDynamic($s,$listTypes,$listParams);$s->execute();$rows=[];$res=$s->get_result();while($row=$res->fetch_assoc())$rows[]=$row;$s->close();
echo json_encode(['success'=>true,'dataset'=>$dataset,'section'=>$section,'status'=>$status,'available_review_statuses'=>$availableReviewStatuses,'rows'=>$rows,'total'=>$total,'total_amount'=>(float)($tot['total_amount']??0),'page'=>$page,'total_pages'=>$pages,'limit'=>$limit]);$conn->close();
