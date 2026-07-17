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
$canEditServiceFiles = currentUserHasPermission($conn, 'service_files.edit');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stage = trim((string)($_GET['stage'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));
    $box = max(0, (int)($_GET['box'] ?? 0));
    $availability = strtolower(trim((string)($_GET['availability'] ?? '')));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(48, max(6, (int)($_GET['per_page'] ?? 12)));
    if (!in_array($availability, ['available', 'out', 'archived'], true)) $availability = '';
    $sql = "SELECT SQL_CALC_FOUND_ROWS sf.*, fr.id AS pension_registry_id, COALESCE(sd.firstName,fr.firstName) firstName, COALESCE(sd.middleName,fr.middleName) middleName, COALESCE(sd.lastName,fr.lastName) lastName, sd.rankName, sd.positionName, COALESCE(NULLIF(sd.rankName,''),NULLIF(sd.positionName,''),NULLIF(sd.rankPosition,''),NULLIF(sd.title,''),NULLIF(fr.title,'')) AS displayPosition, COALESCE(NULLIF(sd.rankPosition,''),NULLIF(sd.title,''),fr.title) rankPosition, sd.prisonUnit FROM tb_service_files sf LEFT JOIN tb_staffdue sd ON sd.id=sf.staffdue_id LEFT JOIN tb_fileregistry fr ON fr.id=sf.registry_id OR (sf.registry_id IS NULL AND COALESCE(NULLIF(fr.pensionNo,''),fr.regNo)=sf.pensionNo) WHERE sf.file_type='service' AND (sd.id IS NULL OR COALESCE(sd.is_deleted,0)=0) AND (fr.id IS NULL OR COALESCE(fr.is_deleted,0)=0)";
    $params=[]; $types='';
    if ($stage !== '') { $sql .= ' AND sf.registry_stage=?'; $params[]=$stage; $types.='s'; }
    if ($box > 0) { $sql .= ' AND sf.registry_box_no=?'; $params[]=$box; $types.='i'; }
    if ($availability !== '') { $sql .= ' AND sf.availability_status=?'; $params[]=$availability; $types.='s'; }
    if ($search !== '') { $sql .= " AND (sf.employeeNo LIKE ? OR CAST(sf.registry_box_no AS CHAR) LIKE ? OR COALESCE(sd.firstName,fr.firstName) LIKE ? OR COALESCE(sd.middleName,fr.middleName) LIKE ? OR COALESCE(sd.lastName,fr.lastName) LIKE ? OR sd.positionName LIKE ? OR sd.rankName LIKE ? OR sd.rankPosition LIKE ? OR sd.title LIKE ?)"; $term="%{$search}%"; $params=array_merge($params,array_fill(0,9,$term)); $types.='sssssssss'; }
    $offset = ($page - 1) * $perPage;
    $sql .= ' ORDER BY COALESCE(sf.registry_box_no,999999),sf.updated_at DESC,sf.service_file_id DESC LIMIT ? OFFSET ?';
    $params[]=$perPage; $params[]=$offset; $types.='ii';
    $stmt=$conn->prepare($sql); if($types!=='') $stmt->bind_param($types,...$params); $stmt->execute();
    $records=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $total=(int)($conn->query('SELECT FOUND_ROWS() total')->fetch_assoc()['total'] ?? 0);
    echo json_encode(['success'=>true,'records'=>$records,'permissions'=>['can_edit_box'=>$canEditServiceFiles],'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>max(1,(int)ceil($total/$perPage))]]); exit;
}

$payload=json_decode(file_get_contents('php://input'),true) ?: [];
$action=strtolower(trim((string)($payload['action'] ?? 'avail')));
if ($action === 'update_box') {
    if (!$canEditServiceFiles) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'You do not have permission to edit service-file box allocations.']); exit;
    }
    $serviceFileId=(int)($payload['service_file_id'] ?? 0);
    $boxNo=(int)($payload['registry_box_no'] ?? 0);
    if ($serviceFileId < 1 || $boxNo < 1) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Select a valid box number.']); exit;
    }
    try {
        $conn->begin_transaction();
        $fileStmt=$conn->prepare("SELECT service_file_id,employeeNo,registry_stage,registry_box_no FROM tb_service_files WHERE service_file_id=? AND file_type='service' LIMIT 1 FOR UPDATE");
        $fileStmt->bind_param('i',$serviceFileId); $fileStmt->execute(); $file=$fileStmt->get_result()->fetch_assoc(); $fileStmt->close();
        if (!$file) throw new RuntimeException('The selected service file no longer exists.');
        if ((string)$file['registry_stage'] === 'archives') throw new RuntimeException('Archive records use the separate archival boxing system.');
        $capacityStmt=$conn->prepare("SELECT COUNT(*) file_count FROM tb_service_files WHERE file_type='service' AND registry_stage=? AND registry_box_no=? AND service_file_id<>?");
        $capacityStmt->bind_param('sii',$file['registry_stage'],$boxNo,$serviceFileId); $capacityStmt->execute(); $fileCount=(int)($capacityStmt->get_result()->fetch_assoc()['file_count'] ?? 0); $capacityStmt->close();
        if ($fileCount >= 10) throw new RuntimeException("Box {$boxNo} already contains the maximum of 10 files.");
        $updateStmt=$conn->prepare('UPDATE tb_service_files SET registry_box_no=?,updated_by=?,updated_at=NOW() WHERE service_file_id=?');
        $userId=(string)$_SESSION['userId']; $updateStmt->bind_param('isi',$boxNo,$userId,$serviceFileId); $updateStmt->execute(); $updateStmt->close();
        $conn->commit();
        logAuditEvent($conn,['actor_id'=>$userId,'actor_name'=>(string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown'),'actor_role'=>(string)($_SESSION['userRole'] ?? ''),'action'=>'service_file_box_updated','entity_type'=>'tb_service_files','entity_id'=>(string)$serviceFileId,'details'=>['employee_number'=>(string)$file['employeeNo'],'previous_box'=>(int)($file['registry_box_no'] ?? 0),'new_box'=>$boxNo,'registry_stage'=>(string)$file['registry_stage']]]);
        echo json_encode(['success'=>true,'message'=>"Service file assigned to Box {$boxNo}.",'registry_box_no'=>$boxNo]); exit;
    } catch (Throwable $error) {
        $conn->rollback(); http_response_code(422);
        echo json_encode(['success'=>false,'message'=>$error->getMessage()]); exit;
    }
}
$staffId=(int)($payload['staffdue_id'] ?? 0);
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
$sql="INSERT INTO tb_service_files (staffdue_id,file_type,employeeNo,pensionNo,registry_stage,registry_box_no,shelf_reference,bunch_reference,availability_status,notes,updated_by,{$dateColumn},updated_at) VALUES (?,'service',?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE pensionNo=VALUES(pensionNo),registry_stage=VALUES(registry_stage),registry_box_no=VALUES(registry_box_no),shelf_reference=VALUES(shelf_reference),bunch_reference=VALUES(bunch_reference),availability_status=VALUES(availability_status),notes=VALUES(notes),updated_by=VALUES(updated_by),{$dateColumn}=NOW(),updated_at=NOW()";
$stmt=$conn->prepare($sql); $stmt->bind_param('isssisssss',$staffId,$staff['employeeNo'],$staff['pensionNo'],$stage,$registryBox,$shelf,$bunch,$availability,$notes,$_SESSION['userId']); $ok=$stmt->execute();
$sync=$conn->prepare('UPDATE tb_staffdue SET service_file_status=?, service_file_location=? WHERE id=?'); $location=trim($shelf . ($bunch!==''?' / '.$bunch:'')); $sync->bind_param('ssi',$stage,$location,$staffId); $sync->execute();
$messages=['avail'=>'Service file registered in Pending Processing.','create_pension_file'=>'Service file advanced to Still in Process.','archive'=>'Service file transferred to Archives.','retrieve'=>'Service file retrieved into Still in Process.'];
echo json_encode(['success'=>$ok,'message'=>$ok?$messages[$action]:'Unable to update service file.','registry_stage'=>$stage]);
