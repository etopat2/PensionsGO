<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/life_certificate_followup_common.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['userId'], $_SESSION['userRole']) || strtolower((string)$_SESSION['userRole']) === 'pensioner') {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required']); exit;
}
ensureLifeCertificateTables($conn);
ensureLifeCertificateFollowupTables($conn);
$year = (int)($_REQUEST['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$phase = lifeCertificateFollowupPhase($conn, $year);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $regNo = trim((string)($_GET['regNo'] ?? ''));
    if ($regNo !== '') {
        $stmt = $conn->prepare("SELECT c.*, x.* FROM tb_life_certificate_followup_cases c LEFT JOIN tb_life_certificate_correspondence x ON x.case_id=c.case_id WHERE c.reg_no=? AND c.compliance_year=? ORDER BY x.attempted_at DESC, x.correspondence_id DESC");
        $stmt->bind_param('si', $regNo, $year); $stmt->execute(); $res = $stmt->get_result(); $case = null; $history = [];
        while ($row = $res->fetch_assoc()) { if ($case === null) $case = ['caseId'=>(int)$row['case_id'],'status'=>$row['status'],'suspensionStatus'=>$row['suspension_status'],'suspensionSubmittedAt'=>$row['suspension_submitted_at'],'suspensionReason'=>$row['suspension_reason']]; if ($row['correspondence_id']) $history[] = ['id'=>(int)$row['correspondence_id'],'channel'=>$row['channel'],'attemptedAt'=>$row['attempted_at'],'contactPerson'=>$row['contact_person'],'contactValue'=>$row['contact_value'],'outcome'=>$row['outcome'],'reachStatus'=>$row['reach_status'],'notes'=>$row['response_notes'],'followUpDate'=>$row['follow_up_date'],'recordedBy'=>$row['recorded_by_name']]; }
        echo json_encode(['success'=>true,'case'=>$case,'history'=>$history,'phase'=>$phase]); exit;
    }

    $statusExpr = "CASE WHEN LOWER(TRIM(COALESCE(fr.livingStatus,'')))='deceased' OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType,''),'-',''),' ',''),'_','')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly') THEN 'Exempt' WHEN lcs.submission_id IS NOT NULL THEN 'Submitted' ELSE 'Not Submitted' END";
    $sql = "SELECT
      SUM(CASE WHEN {$statusExpr}='Not Submitted' THEN 1 ELSE 0 END) defaulters,
      SUM(CASE WHEN {$statusExpr}='Not Submitted' AND COALESCE(cx.attempts,0)>0 THEN 1 ELSE 0 END) contacted,
      SUM(CASE WHEN {$statusExpr}='Not Submitted' AND COALESCE(cx.attempts,0)=0 THEN 1 ELSE 0 END) not_contacted,
      SUM(CASE WHEN {$statusExpr}='Not Submitted' AND cx.successes>0 THEN 1 ELSE 0 END) reached,
      SUM(CASE WHEN {$statusExpr}='Not Submitted' AND COALESCE(cx.successes,0)=0 AND COALESCE(cx.attempts,0)>0 THEN 1 ELSE 0 END) unreachable,
      SUM(CASE WHEN {$statusExpr}='Submitted' AND COALESCE(cx.attempts,0)>0 THEN 1 ELSE 0 END) complied_after_contact,
      SUM(CASE WHEN {$statusExpr}='Not Submitted' AND fr.retirementDate IS NOT NULL AND fr.retirementDate<=? THEN 1 ELSE 0 END) suspension_eligible,
      SUM(CASE WHEN c.suspension_status IN ('Submitted','Suspended') THEN 1 ELSE 0 END) suspension_submitted,
      COALESCE(SUM(cx.attempts),0) total_attempts,
      COALESCE(SUM(cx.successes),0) successful_attempts,
      COALESCE(SUM(cx.failures),0) unsuccessful_attempts
    FROM tb_fileregistry fr
    LEFT JOIN tb_life_certificate_submissions lcs ON lcs.regNo=fr.regNo AND lcs.submission_year=?
    LEFT JOIN tb_life_certificate_followup_cases c ON c.reg_no=fr.regNo AND c.compliance_year=?
    LEFT JOIN (SELECT case_id,COUNT(*) attempts,SUM(reach_status='Successful') successes,SUM(reach_status='Unsuccessful') failures FROM tb_life_certificate_correspondence GROUP BY case_id) cx ON cx.case_id=c.case_id
    WHERE COALESCE(fr.is_deleted,0)=0 AND fr.regNo IS NOT NULL AND TRIM(fr.regNo)<>''";
    $policy=lifeCertificateFollowupPolicy($conn,$year); $retirementCutoff=$policy['graceEnd']->modify('-'.$policy['minimumRetirementYears'].' years')->format('Y-m-d');
    $stmt=$conn->prepare($sql); $stmt->bind_param('sii',$retirementCutoff,$year,$year); $stmt->execute(); $summary=$stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
    foreach($summary as $key=>$value) $summary[$key]=(int)$value;
    echo json_encode(['success'=>true,'year'=>$year,'phase'=>$phase,'summary'=>$summary,'canManage'=>currentUserHasPermission($conn,'registry.life_certificate.followup'),'canSubmitSuspension'=>currentUserHasPermission($conn,'registry.life_certificate.suspension')]); exit;
}

$payload=json_decode(file_get_contents('php://input'),true);
if(!is_array($payload)){http_response_code(400);echo json_encode(['success'=>false,'message'=>'Invalid payload']);exit;}
$action=trim((string)($payload['action']??'')); $regNo=trim((string)($payload['regNo']??'')); $year=(int)($payload['year']??date('Y')); $actor=lifeCertificateFollowupActor();
if($regNo==='' || $year<2000 || $year>2100){http_response_code(422);echo json_encode(['success'=>false,'message'=>'A valid file number and year are required']);exit;}
$verify=$conn->prepare("SELECT regNo, retirementDate, livingStatus, payType FROM tb_fileregistry WHERE regNo=? AND COALESCE(is_deleted,0)=0 LIMIT 1"); $verify->bind_param('s',$regNo);$verify->execute();$record=$verify->get_result()->fetch_assoc();$verify->close();
if(!$record || isLifeCertificateExemptRecord($record['livingStatus']??'', $record['payType']??'')){http_response_code(422);echo json_encode(['success'=>false,'message'=>'This is not an eligible life-certificate follow-up record']);exit;}
$submitted=$conn->prepare("SELECT submission_id FROM tb_life_certificate_submissions WHERE regNo=? AND submission_year=? LIMIT 1");$submitted->bind_param('si',$regNo,$year);$submitted->execute();$hasSubmission=(bool)$submitted->get_result()->fetch_assoc();$submitted->close();
$conn->query('START TRANSACTION');
try {
    $case=$conn->prepare("INSERT INTO tb_life_certificate_followup_cases(reg_no,compliance_year,status) VALUES(?,?,'Open') ON DUPLICATE KEY UPDATE case_id=LAST_INSERT_ID(case_id)");$case->bind_param('si',$regNo,$year);if(!$case->execute())throw new RuntimeException('Unable to create follow-up case');$caseId=(int)$conn->insert_id;$case->close();
    if($action==='add_correspondence'){
        if(!currentUserHasPermission($conn,'registry.life_certificate.followup')) throw new RuntimeException('Access denied');
        if(!lifeCertificateFollowupPolicy($conn,$year)['followupEnabled']) throw new RuntimeException('Life certificate follow-up is disabled in application settings');
        $channel=trim((string)($payload['channel']??''));$outcome=trim((string)($payload['outcome']??''));$notes=trim((string)($payload['notes']??''));$attemptedAt=trim((string)($payload['attemptedAt']??date('Y-m-d H:i:s')));$person=trim((string)($payload['contactPerson']??''));$value=trim((string)($payload['contactValue']??''));$follow=trim((string)($payload['followUpDate']??''));
        $channels=['Phone Call','SMS','Email','Letter','Home Visit','Next of Kin','Other'];$outcomes=['Reached - Will Comply','Reached - Submitted','Reached - Unable to Comply','No Answer','Wrong Number','Phone Off','Message Left','Letter Delivered','Letter Returned','Reported Deceased','Other'];
        if(!in_array($channel,$channels,true)||!in_array($outcome,$outcomes,true)||$notes==='')throw new RuntimeException('Channel, outcome, and detailed notes are required');
        $reach=in_array($outcome,['Reached - Will Comply','Reached - Submitted','Reached - Unable to Comply','Letter Delivered','Reported Deceased'],true)?'Successful':'Unsuccessful';$follow=$follow!==''?$follow:null;
        $stmt=$conn->prepare("INSERT INTO tb_life_certificate_correspondence(case_id,channel,attempted_at,contact_person,contact_value,outcome,reach_status,response_notes,follow_up_date,recorded_by,recorded_by_name) VALUES(?,?,?,?,?,?,?,?,?,?,?)");$stmt->bind_param('issssssssss',$caseId,$channel,$attemptedAt,$person,$value,$outcome,$reach,$notes,$follow,$actor['id'],$actor['name']);if(!$stmt->execute())throw new RuntimeException('Unable to save correspondence');$correspondenceId=$conn->insert_id;$stmt->close();
        if($hasSubmission){$conn->query("UPDATE tb_life_certificate_followup_cases SET status='Complied',closed_at=NOW() WHERE case_id=".$caseId);}
        $auditAction='life_certificate_correspondence_added';$message='Correspondence attempt recorded.';
    } elseif($action==='submit_suspension'){
        if(!currentUserHasPermission($conn,'registry.life_certificate.suspension'))throw new RuntimeException('Access denied');
        $policy=lifeCertificateFollowupPolicy($conn,$year); $effectivePhase=lifeCertificateFollowupPhase($conn,$year);
        if(!$policy['suspensionEnabled'])throw new RuntimeException('Life certificate suspension referrals are disabled in application settings');
        if($effectivePhase['code']!=='post_grace')throw new RuntimeException('Suspension may only be submitted after the configured grace period ending '.$effectivePhase['graceEnd']);
        if($hasSubmission)throw new RuntimeException('This pensioner has already complied for the selected year');
        $retirement=!empty($record['retirementDate'])?new DateTimeImmutable($record['retirementDate']):null;if(!$retirement||$retirement>$policy['graceEnd']->modify('-'.$policy['minimumRetirementYears'].' years'))throw new RuntimeException('At least '.$policy['minimumRetirementYears'].' years since retirement is required');
        $attemptStmt=$conn->prepare('SELECT COUNT(*) attempts FROM tb_life_certificate_correspondence WHERE case_id=?');$attemptStmt->bind_param('i',$caseId);$attemptStmt->execute();$attempts=(int)($attemptStmt->get_result()->fetch_assoc()['attempts']??0);$attemptStmt->close();if($attempts<$policy['minimumContactAttempts'])throw new RuntimeException('At least '.$policy['minimumContactAttempts'].' correspondence attempt(s) must be recorded before suspension referral');
        $reason=trim((string)($payload['reason']??''));if($reason==='')throw new RuntimeException('A suspension recommendation reason is required');
        $stmt=$conn->prepare("UPDATE tb_life_certificate_followup_cases SET status='Suspension Submitted',suspension_status='Submitted',suspension_submitted_at=NOW(),suspension_submitted_by=?,suspension_reason=? WHERE case_id=?");$stmt->bind_param('ssi',$actor['id'],$reason,$caseId);if(!$stmt->execute())throw new RuntimeException('Unable to submit suspension');$stmt->close();$auditAction='life_certificate_suspension_submitted';$message='Case submitted for payroll suspension.';
    } else throw new RuntimeException('Unsupported action');
    $conn->commit();
    logAuditEvent($conn,['actor_id'=>$actor['id'],'actor_name'=>$actor['name'],'actor_role'=>$actor['role'],'action'=>$auditAction,'entity_type'=>'life_certificate_followup_case','entity_id'=>(string)$caseId,'details'=>['reg_no'=>$regNo,'year'=>$year,'action'=>$action]]);
    echo json_encode(['success'=>true,'message'=>$message,'caseId'=>$caseId]);
} catch(Throwable $e){$conn->rollback();http_response_code(strtolower($e->getMessage())==='access denied'?403:422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
