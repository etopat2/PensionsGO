<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config.php';
if(!isset($_SESSION['userId'])){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Authentication required']);exit;}
ensureStaffReferenceTables($conn);
$definitions=['employment_status'=>['tb_employment_statuses','employment_status_id','status_name'],'religion'=>['tb_religions','religion_id','religion_name'],'tribe'=>['tb_tribes','tribe_id','tribe_name'],'political_region'=>['tb_polregions','political_region_id','region_name']];
$type=strtolower(trim((string)($_GET['type']??'')));$types=$type!==''&&isset($definitions[$type])?[$type=>$definitions[$type]]:$definitions;$out=[];
foreach($types as $key=>[$table,$id,$value]){$active=isset($_GET['active_only'])&&(int)$_GET['active_only']===1;$result=$conn->query("SELECT {$id} id,{$value} value,sort_order,is_active FROM {$table}".($active?' WHERE is_active=1':'')." ORDER BY sort_order,{$value}");$out[$key]=[];while($row=$result->fetch_assoc()){$row['id']=(int)$row['id'];$row['sort_order']=(int)$row['sort_order'];$row['is_active']=(bool)$row['is_active'];$out[$key][]=$row;}}
$regions=array_map(static fn($row)=>$row['value'],$out['political_region']??[]);
echo json_encode(['success'=>true,'data'=>$out,'political_regions'=>$regions]);
