<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
if (!isset($_SESSION['userId']) || ($_SESSION['userRole'] ?? '') === 'pensioner') {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
}
ensureFileMovementTables($conn);
$number=trim((string)($_GET['number']??''));
$type=strtolower(trim((string)($_GET['file_type']??'service')))==='pension'?'pension':'service';
if($number===''){echo json_encode(['success'=>true,'exists'=>false,'is_out'=>false]);exit;}
if($type==='service'){
    $stmt=$conn->prepare("SELECT service_file_id,employeeNo AS number,registry_stage,availability_status FROM tb_service_files WHERE file_type='service' AND employeeNo=? LIMIT 1");
    $stmt->bind_param('s',$number);
}else{
    $stmt=$conn->prepare("SELECT id AS service_file_id,COALESCE(NULLIF(pensionNo,''),regNo) AS number,'pension_file_registry' registry_stage,availability_status FROM tb_fileregistry WHERE (pensionNo=? OR regNo=?) AND COALESCE(is_deleted,0)=0 LIMIT 1");
    $stmt->bind_param('ss',$number,$number);
}
$stmt->execute();$file=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$file){echo json_encode(['success'=>true,'exists'=>false,'is_out'=>false]);exit;}
$open=getLatestOpenFileMovement($conn,(string)$file['number'],true);
$isOut=(bool)$open || in_array((string)($file['availability_status']??''),['out','out_of_shelf'],true);
echo json_encode(['success'=>true,'exists'=>true,'is_out'=>$isOut,'file'=>$file,'open_movement'=>$open?:null]);
