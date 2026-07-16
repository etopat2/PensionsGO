<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId']) || ($_SESSION['userRole'] ?? '') === 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
ensureStaffDueExtendedColumns($conn);
ensureFileMovementTables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stage = trim((string)($_GET['stage'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));
    $sql = "SELECT sf.*, COALESCE(sd.firstName,fr.firstName) firstName, COALESCE(sd.middleName,fr.middleName) middleName, COALESCE(sd.lastName,fr.lastName) lastName, sd.rankName, sd.positionName, COALESCE(sd.rankPosition,fr.title) rankPosition, sd.prisonUnit FROM tb_service_files sf LEFT JOIN tb_staffdue sd ON sd.id=sf.staffdue_id LEFT JOIN tb_fileregistry fr ON fr.id=sf.registry_id OR (sf.registry_id IS NULL AND COALESCE(NULLIF(fr.pensionNo,''),fr.regNo)=sf.pensionNo) WHERE sf.file_type='service' AND (sd.id IS NULL OR COALESCE(sd.is_deleted,0)=0) AND (fr.id IS NULL OR COALESCE(fr.is_deleted,0)=0)";
    $params=[]; $types='';
    if ($stage !== '') { $sql .= ' AND sf.registry_stage=?'; $params[]=$stage; $types.='s'; }
    if ($search !== '') { $sql .= ' AND (sf.employeeNo LIKE ? OR sf.registry_box_no LIKE ? OR COALESCE(sd.firstName,fr.firstName) LIKE ? OR COALESCE(sd.lastName,fr.lastName) LIKE ?)'; $term="%{$search}%"; $params=array_merge($params,array_fill(0,4,$term)); $types.='ssss'; }
    $sql .= ' ORDER BY COALESCE(sf.registry_box_no,999999),sf.updated_at DESC,sf.service_file_id DESC LIMIT 500';
    $stmt=$conn->prepare($sql); if($types!=='') $stmt->bind_param($types,...$params); $stmt->execute();
    echo json_encode(['success'=>true,'records'=>$stmt->get_result()->fetch_all(MYSQLI_ASSOC)]); exit;
}

$payload=json_decode(file_get_contents('php://input'),true) ?: [];
$staffId=(int)($payload['staffdue_id'] ?? 0);
$action=strtolower(trim((string)($payload['action'] ?? 'avail')));
$allowed=['avail','create_pension_file','archive','retrieve'];
if($staffId<1 || !in_array($action,$allowed,true)){ echo json_encode(['success'=>false,'message'=>'Invalid service-file action.']); exit; }
$staffStmt=$conn->prepare('SELECT id, employeeNo, COALESCE(NULLIF(pensionNo,\'\'),regNo) AS pensionNo FROM tb_staffdue WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
$staffStmt->bind_param('i',$staffId); $staffStmt->execute(); $staff=$staffStmt->get_result()->fetch_assoc(); $staffStmt->close();
if(!$staff || trim((string)$staff['employeeNo'])===''){ echo json_encode(['success'=>false,'message'=>'The staff record needs an Employee Number first.']); exit; }
$stage=['avail'=>'pending_processing','create_pension_file'=>'still_in_process','archive'=>'archives','retrieve'=>'still_in_process'][$action];
$availability=$action==='archive'?'archived':'available';
$existingFile=$conn->prepare('SELECT service_file_id FROM tb_service_files WHERE staffdue_id=? LIMIT 1');$existingFile->bind_param('i',$staffId);$existingFile->execute();$existingId=(int)($existingFile->get_result()->fetch_assoc()['service_file_id']??0);$existingFile->close();
$registryBox=allocateServiceRegistryBox($conn,$stage,$existingId);
$shelf=trim((string)($payload['shelf_reference'] ?? '')); $bunch=trim((string)($payload['bunch_reference'] ?? '')); $notes=trim((string)($payload['notes'] ?? ''));
$dateColumn=['avail'=>'availed_at','create_pension_file'=>'pension_file_created_at','archive'=>'archived_at','retrieve'=>'updated_at'][$action];
$sql="INSERT INTO tb_service_files (staffdue_id,file_type,employeeNo,pensionNo,registry_stage,registry_box_no,shelf_reference,bunch_reference,availability_status,notes,updated_by,{$dateColumn},updated_at) VALUES (?,'service',?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE pensionNo=VALUES(pensionNo),registry_stage=VALUES(registry_stage),registry_box_no=VALUES(registry_box_no),shelf_reference=VALUES(shelf_reference),bunch_reference=VALUES(bunch_reference),availability_status=VALUES(availability_status),notes=VALUES(notes),updated_by=VALUES(updated_by),{$dateColumn}=NOW(),updated_at=NOW()";
$stmt=$conn->prepare($sql); $stmt->bind_param('isssissssss',$staffId,$staff['employeeNo'],$staff['pensionNo'],$stage,$registryBox,$shelf,$bunch,$availability,$notes,$_SESSION['userId']); $ok=$stmt->execute();
$sync=$conn->prepare('UPDATE tb_staffdue SET service_file_status=?, service_file_location=? WHERE id=?'); $location=trim($shelf . ($bunch!==''?' / '.$bunch:'')); $sync->bind_param('ssi',$stage,$location,$staffId); $sync->execute();
echo json_encode(['success'=>$ok,'message'=>$ok?'Service file registry updated.':'Unable to update service file.','registry_stage'=>$stage]);
