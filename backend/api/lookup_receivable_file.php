<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId']) || ($_SESSION['userRole'] ?? '') === 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$query = strtoupper(trim((string)($_GET['q'] ?? '')));
if ($query === '') {
    echo json_encode(['success' => true, 'records' => []]);
    exit;
}
$like = '%' . $query . '%';
$records = [];

$staff = $conn->prepare("SELECT id,employeeNo,COALESCE(NULLIF(pensionNo,''),regNo) pensionNo,firstName,middleName,lastName,COALESCE(NULLIF(rankName,''),NULLIF(positionName,''),rankPosition,title) title FROM tb_staffdue WHERE COALESCE(is_deleted,0)=0 AND (employeeNo=? OR employeeNo LIKE ? OR pensionNo=? OR regNo=?) ORDER BY (employeeNo=?) DESC LIMIT 8");
$staff->bind_param('sssss',$query,$like,$query,$query,$query);
$staff->execute();
foreach ($staff->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $records[] = ['source'=>'staff_due','file_type'=>'service','record_id'=>(int)$row['id'],'number'=>$row['employeeNo'],'employeeNo'=>$row['employeeNo'],'pensionNo'=>$row['pensionNo'],'name'=>trim(implode(' ',array_filter([$row['firstName'],$row['middleName'],$row['lastName']]))),'title'=>$row['title']];
}
$staff->close();

$registry = $conn->prepare("SELECT id,COALESCE(NULLIF(pensionNo,''),regNo) pensionNo,firstName,middleName,lastName,title FROM tb_fileregistry WHERE COALESCE(is_deleted,0)=0 AND (pensionNo=? OR regNo=? OR pensionNo LIKE ? OR regNo LIKE ?) ORDER BY (pensionNo=?) DESC LIMIT 8");
$registry->bind_param('sssss',$query,$query,$like,$like,$query);
$registry->execute();
foreach ($registry->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $records[] = ['source'=>'pension_registry','file_type'=>'pension','record_id'=>(int)$row['id'],'number'=>$row['pensionNo'],'pensionNo'=>$row['pensionNo'],'name'=>trim(implode(' ',array_filter([$row['firstName'],$row['middleName'],$row['lastName']]))),'title'=>$row['title']];
}
$registry->close();

echo json_encode(['success'=>true,'records'=>$records]);
