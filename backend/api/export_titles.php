<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_export_runtime.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['userId'], $_SESSION['userRole']) || !roleHasAdminAccess($conn, strtolower((string)$_SESSION['userRole']))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

$format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'pdf'], true)) $format = 'xlsx';
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$level = trim((string)($_GET['level'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

ensureTitlesTable($conn);
$where = ['1=1']; $types = ''; $params = [];
if ($search !== '') { $where[] = 'title_name LIKE ?'; $types .= 's'; $params[] = '%' . $search . '%'; }
if (in_array($category, ['uniformed', 'non_uniformed'], true)) { $where[] = 'category = ?'; $types .= 's'; $params[] = $category; }
if (in_array($level, ['junior', 'senior'], true)) { $where[] = 'level = ?'; $types .= 's'; $params[] = $level; }
if (in_array($status, ['active', 'inactive'], true)) { $where[] = 'is_active = ?'; $types .= 'i'; $params[] = $status === 'active' ? 1 : 0; }

$stmt = $conn->prepare('SELECT title_id,title_name,category,level,is_active FROM tb_titles WHERE ' . implode(' AND ', $where) . ' ORDER BY category,level,title_name');
if (!$stmt) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Unable to prepare titles export']); exit; }
if ($types !== '') { $bind = [$types]; foreach ($params as $i => $value) $bind[] = &$params[$i]; call_user_func_array([$stmt, 'bind_param'], $bind); }
$stmt->execute(); $result = $stmt->get_result(); $rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'title_name' => (string)$row['title_name'],
        'category' => $row['category'] === 'non_uniformed' ? 'Non-Uniformed' : 'Uniformed',
        'level' => ucfirst((string)$row['level']),
        'status' => (int)$row['is_active'] === 1 ? 'Active' : 'Inactive'
    ];
}
$stmt->close();
if (!$rows) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'No title records match the current filters']); exit; }

$actorName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User');
$definition = [
    'label' => 'Official Titles Report',
    'columns' => ['title_name'=>'Official Title','category'=>'Category','level'=>'Level','status'=>'Status'],
    'pdf_mode' => 'table',
    'meta_lines' => array_values(array_filter([
        'Generated: ' . date('d M Y H:i'),
        $search !== '' ? 'Search: ' . $search : '',
        $category !== '' ? 'Category: ' . ($category === 'non_uniformed' ? 'Non-Uniformed' : 'Uniformed') : '',
        $level !== '' ? 'Level: ' . ucfirst($level) : '',
        $status !== '' ? 'Status: ' . ucfirst($status) : ''
    ]))
];
$export = dmPayload($conn, $definition, $rows, $actorName);
$baseName = 'official_titles_' . date('Ymd_His');
$directory = getDataExportStoragePath(); $fileName = $baseName . '.' . $format; $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;
dmWriteExportArtifact($export, $format, $filePath);
$size = is_file($filePath) ? (int)filesize($filePath) : 0;
recordDataExportRun($conn, ['dataset_key'=>'official_titles','dataset_label'=>$definition['label'],'export_format'=>$format,'file_name'=>$fileName,'file_path'=>$filePath,'file_size_bytes'=>$size,'filters_json'=>['search'=>$search,'category'=>$category,'level'=>$level,'status'=>$status],'status'=>'success','notes'=>'Export generated from Title Settings.','created_by'=>(string)$_SESSION['userId'],'created_by_name'=>$actorName,'created_by_role'=>(string)$_SESSION['userRole']]);
logAuditEvent($conn, ['actor_id'=>(string)$_SESSION['userId'],'actor_name'=>$actorName,'actor_role'=>(string)$_SESSION['userRole'],'action'=>'title_settings_exported','entity_type'=>'data_export','entity_id'=>'official_titles','details'=>['format'=>$format,'row_count'=>count($rows),'filters'=>compact('search','category','level','status')]]);
echo json_encode(['success'=>true,'message'=>strtoupper($format).' titles report generated.','export'=>['row_count'=>count($rows),'file_name'=>$fileName,'file_size_bytes'=>$size,'download_url'=>'../backend/api/download_data_artifact.php?type=export&file='.rawurlencode($fileName)]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
