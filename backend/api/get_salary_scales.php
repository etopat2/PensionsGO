<?php
header('Content-Type: application/json; charset=utf-8');require_once __DIR__.'/../config.php';
if(!isset($_SESSION['userId'])){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Authentication required']);exit;}
ensureSalaryScalesTable($conn);$active=isset($_GET['active_only'])&&(int)$_GET['active_only']===1;
$sql="SELECT salary_scale_id,scale_code,description,sort_order,is_active FROM tb_salary_scales".($active?" WHERE is_active=1":"")." ORDER BY sort_order,scale_code";
$rows=[];$result=$conn->query($sql);while($row=$result->fetch_assoc()){$row['salary_scale_id']=(int)$row['salary_scale_id'];$row['sort_order']=(int)$row['sort_order'];$row['is_active']=(bool)$row['is_active'];$rows[]=$row;}echo json_encode(['success'=>true,'salary_scales'=>$rows]);
