<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';

function requireAdminDataManagementAccess(mysqli $conn): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    $role = getSessionEffectiveRoleKey($conn);
    if ($role !== 'admin') {
        throw new RuntimeException('Admin access required');
    }

    return [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Administrator'),
        'user_role' => $role
    ];
}

function ensureDataManagementInfrastructure(mysqli $conn): void
{
    ensureBackupLogsTable($conn);
    ensureDataExportRunsTable($conn);
    ensureNotificationQueueTable($conn);
    ensureAuditLogsTable($conn);
    ensureDataImportTables($conn);
}

function dmRelativePath(string $absolutePath): string
{
    $root = realpath(dirname(__DIR__));
    $real = realpath($absolutePath);
    if ($root && $real && str_starts_with(strtolower($real), strtolower($root))) {
        return ltrim(str_replace('\\', '/', substr($real, strlen($root))), '/');
    }
    return str_replace('\\', '/', $absolutePath);
}

function dmListBackupRuns(mysqli $conn, int $limit = 20): array
{
    ensureBackupLogsTable($conn);
    $limit = max(1, min($limit, 100));
    $sql = "
        SELECT backup_id, backup_label, backup_type, backup_scope, file_name, file_path, file_size_bytes,
               checksum_sha256, include_uploads, backup_time, status, notes, created_by_name, created_by_role,
               restored_at, restored_by
        FROM tb_backup_logs
        ORDER BY backup_time DESC
        LIMIT {$limit}
    ";
    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }
    return $rows;
}

function dmListExportRuns(mysqli $conn, int $limit = 20): array
{
    ensureDataExportRunsTable($conn);
    $limit = max(1, min($limit, 100));
    $sql = "
        SELECT export_id, dataset_key, dataset_label, export_format, file_name, file_path, file_size_bytes,
               filters_json, status, notes, created_at, created_by_name, created_by_role
        FROM tb_data_export_runs
        ORDER BY created_at DESC
        LIMIT {$limit}
    ";
    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }
    return $rows;
}

function dmGetCleanupStats(mysqli $conn): array
{
    ensureDataManagementInfrastructure($conn);

    $sessionDays = max(1, getAppSettingInt($conn, 'storage_cleanup_sessions_days', 30));
    $notificationDays = max(1, getAppSettingInt($conn, 'storage_cleanup_notification_days', 30));
    $importDays = max(1, getAppSettingInt($conn, 'storage_cleanup_imports_days', 90));
    $exportDays = max(1, getAppSettingInt($conn, 'storage_cleanup_exports_days', 90));
    $backupDays = max(1, getAppSettingInt($conn, 'storage_cleanup_backups_days', 180));
    $orphanDays = max(1, getAppSettingInt($conn, 'storage_cleanup_orphan_documents_days', 30));

    $inactiveSessions = 0;
    $inactiveResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_user_sessions
        WHERE is_active = 0
           OR last_activity < DATE_SUB(NOW(), INTERVAL {$sessionDays} DAY)
    ");
    if ($inactiveResult && ($row = $inactiveResult->fetch_assoc())) {
        $inactiveSessions = (int)($row['total'] ?? 0);
        $inactiveResult->close();
    }

    $notificationPurge = 0;
    $notificationResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_notification_queue
        WHERE status IN ('sent','failed')
          AND created_at < DATE_SUB(NOW(), INTERVAL {$notificationDays} DAY)
    ");
    if ($notificationResult && ($row = $notificationResult->fetch_assoc())) {
        $notificationPurge = (int)($row['total'] ?? 0);
        $notificationResult->close();
    }

    $importHistoryPurge = 0;
    $importResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_data_import_runs
        WHERE completed_at < DATE_SUB(NOW(), INTERVAL {$importDays} DAY)
    ");
    if ($importResult && ($row = $importResult->fetch_assoc())) {
        $importHistoryPurge = (int)($row['total'] ?? 0);
        $importResult->close();
    }

    $exportHistoryPurge = 0;
    $exportResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_data_export_runs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL {$exportDays} DAY)
    ");
    if ($exportResult && ($row = $exportResult->fetch_assoc())) {
        $exportHistoryPurge = (int)($row['total'] ?? 0);
        $exportResult->close();
    }

    $backupFilePurge = 0;
    $backupResult = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_backup_logs
        WHERE backup_time < DATE_SUB(NOW(), INTERVAL {$backupDays} DAY)
    ");
    if ($backupResult && ($row = $backupResult->fetch_assoc())) {
        $backupFilePurge = (int)($row['total'] ?? 0);
        $backupResult->close();
    }

    $orphanDocuments = 0;
    if (tableExists($conn, 'tb_staff_documents')) {
        $orphanResult = $conn->query("
            SELECT COUNT(*) AS total
            FROM tb_staff_documents d
            LEFT JOIN tb_fileregistry r ON r.regNo = d.regNo
            LEFT JOIN tb_staffdue s ON s.id = d.staffdue_id
            WHERE r.id IS NULL
              AND s.id IS NULL
              AND d.uploaded_at < DATE_SUB(NOW(), INTERVAL {$orphanDays} DAY)
        ");
        if ($orphanResult && ($row = $orphanResult->fetch_assoc())) {
            $orphanDocuments = (int)($row['total'] ?? 0);
            $orphanResult->close();
        }
    }

    return [
        'inactive_sessions' => $inactiveSessions,
        'notification_queue_purge' => $notificationPurge,
        'import_history_purge' => $importHistoryPurge,
        'export_history_purge' => $exportHistoryPurge,
        'backup_history_purge' => $backupFilePurge,
        'orphan_documents_purge' => $orphanDocuments
    ];
}

function dmDeleteFileIfExists(?string $path): void
{
    if (!$path) {
        return;
    }
    $real = realpath($path);
    if ($real && is_file($real)) {
        @unlink($real);
    }
}

function dmDistinctOptions(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $values = [];
    while ($row = $result->fetch_row()) {
        $value = trim((string)($row[0] ?? ''));
        if ($value !== '') {
            $values[$value] = $value;
        }
    }
    $result->close();

    return array_values($values);
}

function dmGetFileRegistryExportFieldGroups(): array
{
    return [
        'Identity & Indexing' => [
            'file_number', 'computer_number', 'supplier_number', 'box_number', 'title', 'surname', 'first_name', 'full_name',
            'gender', 'station'
        ],
        'Status & Lifecycle' => [
            'living_status', 'life_certificate_status', 'retirement_type', 'retirement_date', 'date_of_birth', 'date_of_enlistment',
            'pay_type', 'payroll_status', 'availability_status', 'availability_reason', 'date_on_15_years', 'period_to_15_years', 'period_from_15_years'
        ],
        'Contact & Banking' => [
            'phone_number', 'email_address', 'postal_address', 'next_of_kin', 'next_of_kin_contact', 'bank_name', 'bank_account', 'bank_branch'
        ],
        'Benefits Snapshot' => [
            'monthly_salary', 'length_of_service_months', 'annual_salary', 'reduced_pension', 'full_pension', 'commuted_gratuity'
        ],
        'Compliance & Documents' => [
            'tin_number', 'nin_number', 'document_count', 'uploaded_documents', 'recorded_at'
        ]
    ];
}

function dmGetFileRegistryFilterDefinition(mysqli $conn): array
{
    $boxNumberOptions = function_exists('getRegistryBoxNumberOptions') ? getRegistryBoxNumberOptions($conn) : [];
    return [
        'box_number' => ['label' => 'Box Number', 'match' => 'exact', 'options' => $boxNumberOptions],
        'gender' => ['label' => 'Gender', 'options' => ['Male', 'Female']],
        'living_status' => ['label' => 'Living Status', 'options' => ['Alive', 'Deceased']],
        'pay_type' => ['label' => 'Pay Type', 'options' => ['Pensioner', 'One-off Payment']],
        'payroll_status' => ['label' => 'Payroll Status', 'options' => ['On Payroll', 'Not on Payroll']],
        'availability_status' => ['label' => 'Availability', 'options' => ['in_shelf', 'out_of_shelf']],
        'life_certificate_status' => ['label' => 'Life Certificate', 'options' => ['Submitted', 'Not Submitted', 'Exempt']]
    ];
}

function dmFormatExportValue(string $field, $value): string
{
    if ($value === null) {
        return '';
    }

    $stringValue = is_scalar($value) ? (string)$value : json_encode($value);
    if ($stringValue === false) {
        $stringValue = '';
    }

    if ($field === 'retirement_type') {
        return getBenefitsRetirementTypeLabel($stringValue);
    }

    return $stringValue;
}

function dmFormatExportFilterValue(string $field, string $value): string
{
    if ($field === 'retirement_type') {
        return getBenefitsRetirementTypeLabel($value);
    }

    return $value;
}

function dmConfiguredFilterMatchesValue(string $field, string $rowValue, string $filterValue, array $definition = []): bool
{
    $formattedFilterValue = trim(dmFormatExportFilterValue($field, $filterValue));
    if ($formattedFilterValue === '') {
        return true;
    }

    $normalizedRowValue = trim($rowValue);
    $matchMode = strtolower(trim((string)($definition['match'] ?? 'exact')));
    if ($matchMode === 'contains') {
        return stripos($normalizedRowValue, $formattedFilterValue) !== false;
    }

    return strcasecmp($normalizedRowValue, $formattedFilterValue) === 0;
}

function dmRecursiveAddToZip(ZipArchive $zip, string $sourcePath, string $zipBase): void
{
    if (!is_dir($sourcePath)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $fullPath = $item->getPathname();
        $relativePath = trim(str_replace('\\', '/', substr($fullPath, strlen($sourcePath))), '/');
        if ($relativePath === '') {
            continue;
        }
        $zipPath = trim($zipBase . '/' . $relativePath, '/');
        if ($item->isDir()) {
            $zip->addEmptyDir($zipPath);
        } elseif ($item->isFile()) {
            $zip->addFile($fullPath, $zipPath);
        }
    }
}

function dmFetchExportDatasetDefinition(mysqli $conn, string $datasetKey): array
{
    $dateOn15Expr = "COALESCE(fr.dateOn15yrs, CASE WHEN fr.retirementDate IS NOT NULL THEN DATE_ADD(fr.retirementDate, INTERVAL 15 YEAR) ELSE NULL END)";
    $dmDeathTypeExpr = buildBenefitsRetirementTypeMatchSql(
        $conn,
        "COALESCE(NULLIF(fr.retirementType, ''), NULLIF(sd.retirementType, ''))",
        'death'
    );
    $periodToExpr = "
        CASE
            WHEN {$dateOn15Expr} IS NULL THEN ''
            WHEN CURDATE() < {$dateOn15Expr} THEN CONCAT(
                TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}), ' Years, ',
                TIMESTAMPDIFF(MONTH, DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), {$dateOn15Expr}), ' Months and ',
                TIMESTAMPDIFF(DAY, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), INTERVAL TIMESTAMPDIFF(MONTH, DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), {$dateOn15Expr}) MONTH), {$dateOn15Expr}), ' Day(s)'
            )
            ELSE '15 Years Elapsed'
        END
    ";
    $periodFromExpr = "
        CASE
            WHEN {$dateOn15Expr} IS NULL THEN ''
            WHEN CURDATE() > {$dateOn15Expr} THEN CONCAT(
                TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()), ' Years, ',
                TIMESTAMPDIFF(MONTH, DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), CURDATE()), ' Months and ',
                TIMESTAMPDIFF(DAY, DATE_ADD(DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), INTERVAL TIMESTAMPDIFF(MONTH, DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), CURDATE()) MONTH), CURDATE()), ' Day(s)'
            )
            ELSE 'Still within 15 Years.'
        END
    ";

    $definitions = [
        'users' => [
            'label' => 'System Users',
            'query' => "
                SELECT
                    userTitle AS user_title,
                    userName AS user_name,
                    userEmail AS email,
                    phoneNo AS phone_number,
                    userRole AS role_key,
                    'Active' AS account_status,
                    timeStamp AS created_at
                FROM tb_users
                ORDER BY userName ASC
            ",
            'columns' => [
                'user_title' => 'Title',
                'user_name' => 'Name',
                'email' => 'Email',
                'phone_number' => 'Phone Number',
                'role_key' => 'Role',
                'account_status' => 'Status',
                'created_at' => 'Created At'
            ],
            'text_columns' => ['phone_number']
        ],
        'prison_units' => [
            'label' => 'Prison Units',
            'query' => "
                SELECT
                    priUnit AS unit_name,
                    priDistrict AS prison_district,
                    priRegion AS prison_region,
                    polDistrict AS political_district,
                    polRegion AS political_region
                FROM tb_priunits
                ORDER BY priUnit ASC
            ",
            'columns' => [
                'unit_name' => 'Unit Name',
                'prison_district' => 'Prison District',
                'prison_region' => 'Prison Region',
                'political_district' => 'Political District',
                'political_region' => 'Political Region'
            ],
            'text_columns' => ['unit_name', 'prison_district', 'prison_region', 'political_district', 'political_region']
        ],
        'staff_due' => [
            'label' => 'Staff Due for Retirement',
            'query' => "
                SELECT
                    regNo AS file_number,
                    title,
                    sName AS surname,
                    fName AS first_name,
                    prisonUnit AS unit_name,
                    gender,
                    NIN AS nin_number,
                    telNo AS phone_number,
                    birthDate AS date_of_birth,
                    enlistmentDate AS date_of_enlistment,
                    retirementDate AS retirement_date,
                    financialYear AS financial_year,
                    retirementType AS retirement_type,
                    monthlySalary AS monthly_salary,
                    lengthOfService AS length_of_service_months,
                    annualSalary AS annual_salary,
                    reducedPension AS reduced_pension,
                    fullPension AS full_pension,
                    gratuity AS commuted_gratuity,
                    submissionStatus AS submission_status,
                    appnStatus AS application_status
                FROM tb_staffdue
                ORDER BY retirementDate DESC, regNo ASC
            ",
            'columns' => [
                'file_number' => 'File Number',
                'title' => 'Title',
                'surname' => 'Surname',
                'first_name' => 'First Name',
                'unit_name' => 'Unit',
                'gender' => 'Gender',
                'nin_number' => 'NIN',
                'phone_number' => 'Phone Number',
                'date_of_birth' => 'Date of Birth',
                'date_of_enlistment' => 'Date of Enlistment',
                'retirement_date' => 'Retirement Date',
                'financial_year' => 'Financial Year',
                'retirement_type' => 'Retirement Label',
                'monthly_salary' => 'Monthly Salary',
                'length_of_service_months' => 'Length of Service (Months)',
                'annual_salary' => 'Annual Salary',
                'reduced_pension' => 'Reduced Pension',
                'full_pension' => 'Full Pension',
                'commuted_gratuity' => 'Commuted Gratuity',
                'submission_status' => 'Submission Status',
                'application_status' => 'Application Status'
            ],
            'text_columns' => ['file_number', 'phone_number', 'nin_number']
        ],
        'file_registry' => [
            'label' => 'Pension File Registry',
            'query' => "
                SELECT
                    fr.regNo AS file_number,
                    fr.computerNo AS computer_number,
                    fr.supplierNo AS supplier_number,
                    fr.title,
                    fr.sName AS surname,
                    fr.fName AS first_name,
                    TRIM(CONCAT_WS(' - ',
                        NULLIF(TRIM(fr.title), ''),
                        NULLIF(TRIM(CONCAT_WS(' ', fr.sName, fr.fName)), '')
                    )) AS full_name,
                    fr.gender,
                    COALESCE(fr.livingStatus, sd.livingStatus, CASE WHEN {$dmDeathTypeExpr} THEN 'Deceased' ELSE 'Alive' END) AS living_status,
                    CASE
                        WHEN LOWER(TRIM(COALESCE(fr.livingStatus, sd.livingStatus, CASE WHEN {$dmDeathTypeExpr} THEN 'Deceased' ELSE 'Alive' END))) = 'deceased'
                          OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                            THEN 'Exempt'
                        WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
                        ELSE 'Not Submitted'
                    END AS life_certificate_status,
                    fr.boxNo AS box_number,
                    fr.birthDate AS date_of_birth,
                    fr.enlistmentDate AS date_of_enlistment,
                    fr.retirementDate AS retirement_date,
                    fr.retirementType AS retirement_type,
                    fr.TIN AS tin_number,
                    fr.NIN AS nin_number,
                    COALESCE(fr.telNo, sd.telNo) AS phone_number,
                    COALESCE(fr.applicant_email, sd.applicant_email) AS email_address,
                    COALESCE(fr.address, sd.address) AS postal_address,
                    COALESCE(fr.next_of_kin, sd.next_of_kin) AS next_of_kin,
                    COALESCE(fr.next_of_kin_contact, sd.next_of_kin_contact) AS next_of_kin_contact,
                    COALESCE(fr.bank_name, sd.bank_name) AS bank_name,
                    COALESCE(fr.bank_account, sd.bank_account) AS bank_account,
                    COALESCE(fr.bank_branch, sd.bank_branch) AS bank_branch,
                    COALESCE(sd.prisonUnit, '') AS station,
                    COALESCE(fr.monthlySalary, sd.monthlySalary) AS monthly_salary,
                    COALESCE(fr.lengthOfService, sd.lengthOfService) AS length_of_service_months,
                    COALESCE(fr.annualSalary, sd.annualSalary) AS annual_salary,
                    COALESCE(fr.reducedPension, sd.reducedPension) AS reduced_pension,
                    COALESCE(fr.fullPension, sd.fullPension) AS full_pension,
                    COALESCE(fr.gratuity, sd.gratuity) AS commuted_gratuity,
                    COALESCE(NULLIF(fr.payrollStatus, ''), 'Not on Payroll') AS payroll_status,
                    CASE
                        WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                            THEN 'One-off Payment'
                        ELSE 'Pensioner'
                    END AS pay_type,
                    {$dateOn15Expr} AS date_on_15_years,
                    {$periodToExpr} AS period_to_15_years,
                    {$periodFromExpr} AS period_from_15_years,
                    COALESCE(fr.availability_status, 'in_shelf') AS availability_status,
                    COALESCE(fr.availability_reason, '') AS availability_reason,
                    COALESCE(fr.other, '') AS additional_metadata,
                    COALESCE(docs.document_count, 0) AS document_count,
                    COALESCE(docs.document_summary, '') AS uploaded_documents,
                    fr.timeStamp AS recorded_at
                FROM tb_fileregistry fr
                LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
                LEFT JOIN tb_life_certificate_submissions lcs
                  ON lcs.regNo = fr.regNo
                 AND lcs.submission_year = YEAR(CURDATE())
                LEFT JOIN (
                    SELECT
                        regNo,
                        COUNT(*) AS document_count,
                        GROUP_CONCAT(CONCAT(COALESCE(doc_type, 'Document'), ': ', COALESCE(file_name, '')) ORDER BY uploaded_at DESC SEPARATOR ' | ') AS document_summary
                    FROM tb_staff_documents
                    GROUP BY regNo
                ) docs ON docs.regNo = fr.regNo
                ORDER BY fr.regNo ASC
            ",
            'columns' => [
                'file_number' => 'File Number',
                'computer_number' => 'Computer Number',
                'supplier_number' => 'Supplier Number',
                'title' => 'Title',
                'surname' => 'Surname',
                'first_name' => 'First Name',
                'full_name' => 'Full Name',
                'gender' => 'Gender',
                'living_status' => 'Living Status',
                'life_certificate_status' => 'Life Certificate',
                'box_number' => 'Box Number',
                'date_of_birth' => 'Date of Birth',
                'date_of_enlistment' => 'Date of Enlistment',
                'retirement_date' => 'Date of Retirement',
                'retirement_type' => 'Retirement Label',
                'tin_number' => 'TIN',
                'nin_number' => 'NIN',
                'phone_number' => 'Phone Number',
                'email_address' => 'Email Address',
                'postal_address' => 'Address',
                'next_of_kin' => 'Next of Kin',
                'next_of_kin_contact' => 'Next of Kin Contact',
                'bank_name' => 'Bank Name',
                'bank_account' => 'Bank Account',
                'bank_branch' => 'Bank Branch',
                'station' => 'Station',
                'monthly_salary' => 'Monthly Salary',
                'length_of_service_months' => 'Length of Service (Months)',
                'annual_salary' => 'Annual Salary',
                'reduced_pension' => 'Reduced Pension',
                'full_pension' => 'Full Pension',
                'commuted_gratuity' => 'Commuted Gratuity',
                'payroll_status' => 'Payroll Status',
                'pay_type' => 'Pay Type',
                'date_on_15_years' => 'Date On 15 Years',
                'period_to_15_years' => 'Period To 15 Years',
                'period_from_15_years' => 'Period From 15 Years',
                'availability_status' => 'Availability',
                'availability_reason' => 'Availability Reason',
                'additional_metadata' => 'Additional Metadata',
                'document_count' => 'Document Count',
                'uploaded_documents' => 'Uploaded Documents',
                'recorded_at' => 'Recorded At'
            ],
            'text_columns' => ['file_number', 'computer_number', 'supplier_number', 'phone_number', 'box_number', 'tin_number', 'nin_number', 'bank_account'],
            'pdf_mode' => 'detail'
        ],
        'claims_ledger' => [
            'label' => 'Claims Ledger',
            'query' => "
                SELECT
                    l.regNo AS file_number,
                    COALESCE(fr.title, '') AS title,
                    COALESCE(fr.sName, '') AS surname,
                    COALESCE(fr.fName, '') AS first_name,
                    l.claim_type,
                    l.period_year,
                    l.period_month,
                    l.financial_year_label,
                    l.quarter_label,
                      l.expected_amount,
                      l.paid_amount,
                      l.balance_amount,
                      l.status,
                      l.claim_status,
                      l.source_type,
                      l.recorded_at AS created_at
                FROM tb_arrears_ledger l
                LEFT JOIN tb_fileregistry fr ON fr.regNo = l.regNo
                WHERE LOWER(TRIM(COALESCE(l.source_type, ''))) NOT LIKE 'suspension%'
                ORDER BY l.regNo ASC, l.period_year DESC, l.period_month DESC
            ",
            'columns' => [
                'file_number' => 'File Number',
                'title' => 'Title',
                'surname' => 'Surname',
                'first_name' => 'First Name',
                'claim_type' => 'Claim Type',
                'period_year' => 'Period Year',
                'period_month' => 'Period Month',
                'financial_year_label' => 'Financial Year',
                'quarter_label' => 'Quarter',
                'expected_amount' => 'Expected Amount',
                'paid_amount' => 'Paid Amount',
                  'balance_amount' => 'Balance Amount',
                  'status' => 'Status',
                  'claim_status' => 'Claim Status',
                  'source_type' => 'Source Type',
                  'created_at' => 'Recorded At'
              ],
            'text_columns' => ['file_number']
        ],
        'tasks' => [
            'label' => 'Workflow Tasks',
            'query' => "
                SELECT
                    t.taskId AS task_id,
                    t.related_reg_no AS file_number,
                    TRIM(CONCAT(COALESCE(sd.sName, ''), ' ', COALESCE(sd.fName, ''))) AS applicant_name,
                    t.task_title,
                    t.task_type,
                    COALESCE(u_created.userName, t.created_by, '') AS created_by_name,
                    COALESCE(u_assigned.userName, t.assigned_to, '') AS assigned_to_name,
                    t.assigned_role,
                    t.priority,
                    t.status,
                    t.due_at,
                    t.completed_at,
                    t.updated_at,
                    t.timeStamp AS created_at
                FROM tb_tasks t
                LEFT JOIN tb_users u_created ON u_created.userId = t.created_by
                LEFT JOIN tb_users u_assigned ON u_assigned.userId = t.assigned_to
                LEFT JOIN tb_staffdue sd
                  ON (t.related_staff_id IS NOT NULL AND sd.id = t.related_staff_id)
                  OR (t.related_staff_id IS NULL AND t.related_reg_no IS NOT NULL AND sd.regNo = t.related_reg_no)
                ORDER BY t.timeStamp DESC
            ",
            'columns' => [
                'task_id' => 'Task ID',
                'file_number' => 'File Number',
                'applicant_name' => 'Applicant',
                'task_title' => 'Task Title',
                'task_type' => 'Task Type',
                'created_by_name' => 'Created By',
                'assigned_to_name' => 'Assigned To',
                'assigned_role' => 'Assigned Role',
                'priority' => 'Priority',
                'status' => 'Status',
                'due_at' => 'Due At',
                'completed_at' => 'Completed At',
                'updated_at' => 'Updated At',
                'created_at' => 'Created At'
            ],
            'text_columns' => ['task_id', 'file_number']
        ],
        'workflow_logs' => [
            'label' => 'Workflow Logs',
            'query' => "
                SELECT
                    log_id,
                    task_id,
                    staffdue_id,
                    regNo,
                    action,
                    from_status,
                    to_status,
                    actor_name,
                    actor_role,
                    note,
                    created_at
                FROM tb_workflow_logs
                ORDER BY created_at DESC, log_id DESC
            ",
            'columns' => [
                'log_id' => 'Log ID',
                'task_id' => 'Task ID',
                'staffdue_id' => 'Staff Due ID',
                'regNo' => 'File Number',
                'action' => 'Action',
                'from_status' => 'From Status',
                'to_status' => 'To Status',
                'actor_name' => 'Actor',
                'actor_role' => 'Actor Role',
                'note' => 'Note',
                'created_at' => 'Logged At'
            ],
            'text_columns' => ['log_id', 'task_id', 'regNo']
        ],
        'task_delegation_logs' => [
            'label' => 'Task Delegation Logs',
            'query' => "
                SELECT
                    log_id,
                    task_id,
                    from_user_name,
                    from_user_role,
                    to_user_name,
                    to_user_role,
                    note,
                    priority,
                    created_at
                FROM tb_task_delegation_logs
                ORDER BY created_at DESC, log_id DESC
            ",
            'columns' => [
                'log_id' => 'Log ID',
                'task_id' => 'Task ID',
                'from_user_name' => 'Delegated By',
                'from_user_role' => 'From Role',
                'to_user_name' => 'Delegated To',
                'to_user_role' => 'To Role',
                'note' => 'Reason',
                'priority' => 'Priority',
                'created_at' => 'Delegated At'
            ],
            'text_columns' => ['log_id', 'task_id']
        ],
        'feedback_submissions' => [
            'label' => 'Feedback Submissions',
            'query' => "
                SELECT
                    reference_no,
                    feedback_type,
                    audience,
                    full_name,
                    email_address,
                    phone_number,
                    subject,
                    message,
                    page_context,
                    status,
                    priority,
                    assigned_to_name,
                    assigned_to_role,
                    submitted_at,
                    reviewed_at,
                    resolved_at,
                    closed_at,
                    resolution_summary,
                    updated_at
                FROM tb_feedback_submissions
                ORDER BY submitted_at DESC, submission_id DESC
            ",
            'columns' => [
                'reference_no' => 'Reference No',
                'feedback_type' => 'Feedback Type',
                'audience' => 'Audience',
                'full_name' => 'Submitted By',
                'email_address' => 'Email Address',
                'phone_number' => 'Phone Number',
                'subject' => 'Subject',
                'message' => 'Message',
                'page_context' => 'Page Context',
                'status' => 'Status',
                'priority' => 'Priority',
                'assigned_to_name' => 'Assigned To',
                'assigned_to_role' => 'Assigned Role',
                'submitted_at' => 'Submitted At',
                'reviewed_at' => 'Reviewed At',
                'resolved_at' => 'Resolved At',
                'closed_at' => 'Closed At',
                'resolution_summary' => 'Resolution Summary',
                'updated_at' => 'Updated At'
            ],
            'text_columns' => ['reference_no', 'phone_number']
        ],
        'file_movements' => [
            'label' => 'File Movements',
            'query' => "
                SELECT
                    m.regNo AS file_number,
                    CASE
                        WHEN m.returned_at IS NOT NULL THEN 'Returned'
                        ELSE 'Moved Out'
                    END AS movement_type,
                    m.from_office,
                    m.to_office,
                    COALESCE(u.userName, m.delivered_by, '') AS delivered_by,
                    m.received_by,
                    m.reason AS movement_reason,
                    m.moved_at AS movement_date,
                    m.returned_at
                FROM tb_file_movements m
                LEFT JOIN tb_users u ON u.userId = m.delivered_by
                ORDER BY m.moved_at DESC
            ",
            'columns' => [
                'file_number' => 'File Number',
                'movement_type' => 'Movement Type',
                'from_office' => 'From Office',
                'to_office' => 'To Office',
                'delivered_by' => 'Delivered By',
                'received_by' => 'Received By',
                'movement_reason' => 'Reason',
                'movement_date' => 'Movement Date',
                'returned_at' => 'Returned At'
            ],
            'text_columns' => ['file_number']
        ],
        'registry_recycle_bin' => [
            'label' => 'Registry Recycle Bin',
            'query' => "
                SELECT
                    regNo AS file_number,
                    staff_title AS title,
                    staff_name AS pensioner_name,
                    CASE
                        WHEN delete_request_id IS NOT NULL AND delete_request_id > 0 THEN 'Queued Request'
                        ELSE 'Direct Delete'
                    END AS delete_mode,
                    CASE
                        WHEN restored = 1 THEN 'Restored'
                        ELSE 'Deleted'
                    END AS recycle_status,
                    delete_reason,
                    deleted_by_name,
                    deleted_by_role,
                    deleted_at,
                    restored_by_name,
                    restored_by_role,
                    restored_at
                FROM tb_file_registry_recycle_bin
                ORDER BY deleted_at DESC, recycle_id DESC
            ",
            'columns' => [
                'file_number' => 'File Number',
                'title' => 'Title',
                'pensioner_name' => 'Name',
                'delete_mode' => 'Delete Mode',
                'recycle_status' => 'Recycle Status',
                'delete_reason' => 'Delete Reason',
                'deleted_by_name' => 'Deleted By',
                'deleted_by_role' => 'Deleted By Role',
                'deleted_at' => 'Deleted At',
                'restored_by_name' => 'Restored By',
                'restored_by_role' => 'Restored By Role',
                'restored_at' => 'Restored At'
            ],
            'text_columns' => ['file_number']
        ],
        'payroll_cycles' => [
            'label' => 'Payroll Cycles',
            'query' => "
                SELECT
                    c.cycle_id,
                    c.financial_year_label,
                    c.quarter_label,
                    c.payroll_month,
                    c.payroll_year,
                    CASE
                        WHEN c.is_deleted = 1 THEN 'Deleted'
                        ELSE 'Active'
                    END AS cycle_status,
                    COALESCE(stats.matched_count, 0) AS matched_count,
                    COALESCE(stats.unmatched_count, 0) AS unmatched_count,
                    COALESCE(stats.total_amount, 0) AS total_amount,
                    c.created_at AS uploaded_at
                FROM tb_payroll_upload_cycles c
                LEFT JOIN (
                    SELECT
                        cycle_id,
                        SUM(CASE WHEN is_matched = 1 THEN 1 ELSE 0 END) AS matched_count,
                        SUM(CASE WHEN is_matched = 0 THEN 1 ELSE 0 END) AS unmatched_count,
                        SUM(COALESCE(amount, 0)) AS total_amount
                    FROM tb_payroll_upload_entries
                    GROUP BY cycle_id
                ) stats ON stats.cycle_id = c.cycle_id
                ORDER BY c.payroll_year DESC, c.payroll_month DESC
            ",
            'columns' => [
                'cycle_id' => 'Cycle ID',
                'financial_year_label' => 'Financial Year',
                'quarter_label' => 'Quarter',
                'payroll_month' => 'Month',
                'payroll_year' => 'Year',
                'cycle_status' => 'Status',
                'matched_count' => 'Matched Rows',
                'unmatched_count' => 'Unmatched Rows',
                'total_amount' => 'Total Amount',
                'uploaded_at' => 'Uploaded At'
            ]
        ],
        'user_logs' => [
            'label' => 'User Activity Logs',
            'query' => "
                SELECT
                    user_name,
                    user_role,
                    activity_type,
                    details,
                    ip_address,
                    location,
                    created_at
                FROM tb_user_logs
                ORDER BY created_at DESC
            ",
            'columns' => [
                'user_name' => 'User Name',
                'user_role' => 'Role',
                'activity_type' => 'Activity',
                'details' => 'Details',
                'ip_address' => 'IP Address',
                'location' => 'Location',
                'created_at' => 'Created At'
            ],
            'text_columns' => ['ip_address']
        ],
        'audit_logs' => [
            'label' => 'Audit Trail',
            'query' => "
                SELECT
                    actor_name,
                    actor_role,
                    action,
                    entity_type,
                    entity_id,
                    details,
                    created_at
                FROM tb_audit_logs
                ORDER BY created_at DESC
            ",
            'columns' => [
                'actor_name' => 'Actor',
                'actor_role' => 'Role',
                'action' => 'Action',
                'entity_type' => 'Entity Type',
                'entity_id' => 'Entity ID',
                'details' => 'Details',
                'created_at' => 'Created At'
            ]
        ]
    ];

    if (!isset($definitions[$datasetKey])) {
        throw new RuntimeException('Unknown export dataset.');
    }

    if (!isset($definitions[$datasetKey]['columns'])) {
        $headers = $definitions[$datasetKey]['headers'] ?? [];
        $definitions[$datasetKey]['columns'] = [];
        foreach ($headers as $index => $header) {
            $definitions[$datasetKey]['columns']['col_' . $index] = $header;
        }
    }

    return $definitions[$datasetKey];
}

function dmBuildFileRegistryExportDefinition(mysqli $conn, array $requestPayload = []): array
{
    return dmBuildConfiguredExportDefinition($conn, 'file_registry', $requestPayload);
}

function dmGetExportDatasetConfigs(mysqli $conn): array
{
    $commonSingleGroup = static function (array $columns): array {
        return ['Available Fields' => array_keys($columns)];
    };
    $roleKeys = array_keys(getRoleLabelMap($conn, true));
    $staffFinancialYears = dmDistinctOptions($conn, "SELECT DISTINCT financialYear FROM tb_staffdue WHERE financialYear IS NOT NULL AND TRIM(financialYear) <> '' ORDER BY financialYear DESC");
    $fileMovementOffices = dmDistinctOptions($conn, "SELECT office_name FROM (SELECT DISTINCT from_office AS office_name FROM tb_file_movements UNION SELECT DISTINCT to_office AS office_name FROM tb_file_movements) offices WHERE office_name IS NOT NULL AND TRIM(office_name) <> '' ORDER BY office_name ASC");
    $auditActions = dmDistinctOptions($conn, "SELECT DISTINCT action FROM tb_audit_logs WHERE action IS NOT NULL AND TRIM(action) <> '' ORDER BY action ASC");
    $auditEntities = dmDistinctOptions($conn, "SELECT DISTINCT entity_type FROM tb_audit_logs WHERE entity_type IS NOT NULL AND TRIM(entity_type) <> '' ORDER BY entity_type ASC");
    $workflowActions = tableExists($conn, 'tb_workflow_logs')
        ? dmDistinctOptions($conn, "SELECT DISTINCT action FROM tb_workflow_logs WHERE action IS NOT NULL AND TRIM(action) <> '' ORDER BY action ASC")
        : [];
    $delegationPriorities = tableExists($conn, 'tb_task_delegation_logs')
        ? dmDistinctOptions($conn, "SELECT DISTINCT priority FROM tb_task_delegation_logs WHERE priority IS NOT NULL AND TRIM(priority) <> '' ORDER BY priority ASC")
        : [];
    if (empty($delegationPriorities)) {
        $delegationPriorities = ['low', 'medium', 'high', 'urgent'];
    }
    $claimsFinancialYears = dmDistinctOptions($conn, "SELECT DISTINCT financial_year_label FROM tb_arrears_ledger WHERE financial_year_label IS NOT NULL AND TRIM(financial_year_label) <> '' AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%' ORDER BY financial_year_label DESC");
    $claimsYears = dmDistinctOptions($conn, "SELECT DISTINCT CAST(period_year AS CHAR) FROM tb_arrears_ledger WHERE period_year IS NOT NULL AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%' ORDER BY period_year DESC");
    $payrollFinancialYears = dmDistinctOptions($conn, "SELECT DISTINCT financial_year_label FROM tb_payroll_upload_cycles WHERE financial_year_label IS NOT NULL AND TRIM(financial_year_label) <> '' ORDER BY financial_year_label DESC");
    $prisonDistricts = dmDistinctOptions($conn, "SELECT DISTINCT priDistrict FROM tb_priunits WHERE priDistrict IS NOT NULL AND TRIM(priDistrict) <> '' ORDER BY priDistrict ASC");
    $prisonRegions = dmDistinctOptions($conn, "SELECT DISTINCT priRegion FROM tb_priunits WHERE priRegion IS NOT NULL AND TRIM(priRegion) <> '' ORDER BY priRegion ASC");
    $politicalDistricts = dmDistinctOptions($conn, "SELECT DISTINCT polDistrict FROM tb_priunits WHERE polDistrict IS NOT NULL AND TRIM(polDistrict) <> '' ORDER BY polDistrict ASC");
    $politicalRegions = dmDistinctOptions($conn, "SELECT DISTINCT polRegion FROM tb_priunits WHERE polRegion IS NOT NULL AND TRIM(polRegion) <> '' ORDER BY polRegion ASC");

    $workflowExportEnabled = getAppSettingBool($conn, 'workflow_logs_export_enabled', true);
    $delegationExportEnabled = getAppSettingBool($conn, 'task_delegation_export_enabled', true);

    $definitions = [
        'users' => [
            'label' => 'System Users',
            'description' => 'Export staff user accounts and account governance data.',
            'filters' => [
                'role_key' => ['label' => 'Role', 'options' => $roleKeys],
                'account_status' => ['label' => 'Account Status', 'options' => ['Active']]
            ],
            'search_fields' => ['user_name', 'email', 'phone_number', 'role_key']
        ],
        'prison_units' => [
            'label' => 'Prison Units',
            'description' => 'Export prison unit reference data, prison geography, and political mapping used throughout the application.',
            'filters' => [
                'prison_district' => ['label' => 'Prison District', 'options' => $prisonDistricts],
                'prison_region' => ['label' => 'Prison Region', 'options' => $prisonRegions],
                'political_district' => ['label' => 'Political District', 'options' => $politicalDistricts],
                'political_region' => ['label' => 'Political Region', 'options' => $politicalRegions]
            ],
            'default_pdf_fields' => [
                'unit_name', 'prison_district', 'prison_region', 'political_district', 'political_region'
            ],
            'search_fields' => ['unit_name', 'prison_district', 'prison_region', 'political_district', 'political_region'],
            'field_groups' => [
                'Unit Identity' => ['unit_name'],
                'Prison Administration' => ['prison_district', 'prison_region'],
                'Political Mapping' => ['political_district', 'political_region']
            ]
        ],
        'staff_due' => [
            'label' => 'Staff Due for Retirement',
            'description' => 'Export retirement pipeline source records and submission status.',
            'filters' => [
                'gender' => ['label' => 'Gender', 'options' => ['Male', 'Female']],
                'submission_status' => ['label' => 'Submission Status', 'options' => ['Pending', 'Submitted']],
                'application_status' => ['label' => 'Application Status', 'options' => ['Pending', 'Verified', 'Queried', 'Rejected', 'Approved']],
                'retirement_type' => ['label' => 'Retirement Label', 'options' => array_values(getBenefitsRetirementTypeSelectOptions())],
                'financial_year' => ['label' => 'Financial Year', 'options' => $staffFinancialYears]
            ],
            'default_pdf_fields' => [
                'file_number', 'title', 'surname', 'first_name', 'unit_name', 'retirement_date',
                'retirement_type', 'financial_year', 'submission_status', 'application_status'
            ],
            'search_fields' => ['file_number', 'surname', 'first_name', 'unit_name', 'phone_number', 'nin_number']
        ],
        'file_registry' => [
            'label' => 'Pension File Registry',
            'description' => 'Export pension file registry records in row-based tabular format with optional filters and field selection.',
            'filters' => dmGetFileRegistryFilterDefinition($conn),
            'field_groups' => dmGetFileRegistryExportFieldGroups(),
            'default_pdf_fields' => [
                'file_number', 'box_number', 'full_name', 'gender', 'living_status', 'pay_type',
                'payroll_status', 'availability_status', 'life_certificate_status', 'retirement_type',
                'retirement_date', 'station', 'phone_number'
            ],
            'search_fields' => ['file_number', 'computer_number', 'supplier_number', 'box_number', 'full_name', 'surname', 'first_name', 'phone_number', 'nin_number', 'station']
        ],
        'claims_ledger' => [
            'label' => 'Claims Ledger',
            'description' => 'Export arrears, claims balances, and budgeting support data.',
            'filters' => [
                'claim_type' => ['label' => 'Claim Type', 'options' => ['Pension Arrears', 'Gratuity Arrears', 'Full Pension Arrears', 'Pension and Gratuity Arrears', 'Underpayment Claim']],
                'status' => ['label' => 'Status', 'options' => ['pending', 'partial', 'paid', 'waived']],
                'period_year' => ['label' => 'Period Year', 'options' => $claimsYears],
                'quarter_label' => ['label' => 'Quarter', 'options' => ['Q1', 'Q2', 'Q3', 'Q4']],
                'financial_year_label' => ['label' => 'Financial Year', 'options' => $claimsFinancialYears]
            ],
            'default_pdf_fields' => [
                'file_number', 'title', 'surname', 'first_name', 'claim_type', 'financial_year_label',
                'quarter_label', 'expected_amount', 'paid_amount', 'balance_amount', 'status', 'created_at'
            ],
            'search_fields' => ['file_number', 'title', 'surname', 'first_name', 'claim_type', 'financial_year_label', 'quarter_label']
        ],
        'tasks' => [
            'label' => 'Workflow Tasks',
            'description' => 'Export workflow task assignments, due dates, and status tracking.',
            'filters' => [
                'status' => ['label' => 'Task Status', 'options' => ['pending', 'in_progress', 'completed', 'declined', 'delegated', 'scheduled']],
                'priority' => ['label' => 'Priority', 'options' => ['low', 'medium', 'high', 'urgent']],
                'assigned_role' => ['label' => 'Assigned Role', 'options' => $roleKeys]
            ],
            'default_pdf_fields' => [
                'task_id', 'file_number', 'applicant_name', 'task_title', 'task_type', 'assigned_to_name',
                'assigned_role', 'priority', 'status', 'due_at', 'updated_at'
            ],
            'search_fields' => ['task_id', 'file_number', 'applicant_name', 'task_title', 'task_type', 'created_by_name', 'assigned_to_name']
        ],
        'workflow_logs' => [
            'label' => 'Workflow Logs',
            'description' => 'Export workflow event logs and status transitions.',
            'filters' => [
                'action' => ['label' => 'Action', 'options' => !empty($workflowActions) ? $workflowActions : ['task_created', 'task_delegated', 'task_reassigned', 'task_completed', 'task_declined', 'task_deferred', 'task_returned', 'task_resumed']],
                'actor_role' => ['label' => 'Actor Role', 'options' => $roleKeys]
            ],
            'search_fields' => ['task_id', 'regNo', 'actor_name', 'action', 'note']
        ],
        'task_delegation_logs' => [
            'label' => 'Task Delegation Logs',
            'description' => 'Export task delegation handoffs, routing notes, and priority changes.',
            'filters' => [
                'from_user_role' => ['label' => 'Delegated By Role', 'options' => $roleKeys],
                'to_user_role' => ['label' => 'Delegated To Role', 'options' => $roleKeys],
                'priority' => ['label' => 'Priority', 'options' => $delegationPriorities]
            ],
            'search_fields' => ['task_id', 'from_user_name', 'to_user_name', 'note']
        ],
        'feedback_submissions' => [
            'label' => 'Feedback Submissions',
            'description' => 'Export feedback inbox records, service triage workflow, and response outcomes.',
            'filters' => [
                'feedback_type' => ['label' => 'Feedback Type', 'options' => ['general_feedback', 'bug_report', 'data_correction', 'service_request', 'suggestion', 'complaint', 'pensioner_support']],
                'audience' => ['label' => 'Audience', 'options' => ['public', 'staff', 'pensioner']],
                'status' => ['label' => 'Status', 'options' => ['new', 'reviewed', 'resolved', 'closed']],
                'priority' => ['label' => 'Priority', 'options' => ['low', 'normal', 'high', 'critical']],
                'assigned_to_role' => ['label' => 'Assigned Role', 'options' => $roleKeys]
            ],
            'search_fields' => ['reference_no', 'full_name', 'email_address', 'phone_number', 'subject', 'message', 'assigned_to_name'],
            'field_groups' => [
                'Submission Identity' => ['reference_no', 'feedback_type', 'audience', 'full_name', 'email_address', 'phone_number'],
                'Workflow' => ['status', 'priority', 'assigned_to_name', 'assigned_to_role', 'submitted_at', 'reviewed_at', 'resolved_at', 'closed_at', 'updated_at'],
                'Narrative' => ['subject', 'message', 'page_context', 'resolution_summary']
            ]
        ],
        'file_movements' => [
            'label' => 'File Movements',
            'description' => 'Export file movement history for registry circulation control.',
            'filters' => [
                'movement_type' => ['label' => 'Movement Type', 'options' => ['Moved Out', 'Returned']],
                'from_office' => ['label' => 'From Office', 'options' => $fileMovementOffices],
                'to_office' => ['label' => 'To Office', 'options' => $fileMovementOffices]
            ],
            'default_pdf_fields' => [
                'file_number', 'movement_type', 'from_office', 'to_office', 'delivered_by',
                'received_by', 'movement_reason', 'movement_date', 'returned_at'
            ],
            'search_fields' => ['file_number', 'from_office', 'to_office', 'delivered_by', 'received_by', 'movement_reason']
        ],
        'registry_recycle_bin' => [
            'label' => 'Registry Recycle Bin',
            'description' => 'Export deleted registry records currently retained in the recycle bin.',
            'filters' => [
                'delete_mode' => ['label' => 'Delete Mode', 'options' => ['Direct Delete', 'Queued Request']],
                'recycle_status' => ['label' => 'Recycle Status', 'options' => ['Deleted', 'Restored']],
                'deleted_by_role' => ['label' => 'Deleted By Role', 'options' => $roleKeys]
            ],
            'default_pdf_fields' => [
                'file_number', 'title', 'pensioner_name', 'delete_mode', 'recycle_status',
                'delete_reason', 'deleted_by_name', 'deleted_at', 'restored_by_name', 'restored_at'
            ],
            'search_fields' => ['file_number', 'title', 'pensioner_name', 'delete_reason', 'deleted_by_name', 'restored_by_name']
        ],
        'payroll_cycles' => [
            'label' => 'Payroll Cycles',
            'description' => 'Export payroll upload cycles and reconciliation results.',
            'filters' => [
                'cycle_status' => ['label' => 'Status', 'options' => ['Active', 'Deleted']],
                'quarter_label' => ['label' => 'Quarter', 'options' => ['Q1', 'Q2', 'Q3', 'Q4']],
                'financial_year_label' => ['label' => 'Financial Year', 'options' => $payrollFinancialYears]
            ],
            'search_fields' => ['financial_year_label', 'quarter_label', 'payroll_month', 'payroll_year']
        ],
        'user_logs' => [
            'label' => 'User Activity Logs',
            'description' => 'Export activity monitoring records and operational traces.',
            'filters' => [
                'user_role' => ['label' => 'Role', 'options' => $roleKeys],
                'activity_type' => ['label' => 'Activity', 'options' => ['login', 'logout', 'session_started', 'session_expiry', 'login_failed', 'device_conflict', 'device_conflict_detected', 'device_conflict_resolved']]
            ],
            'search_fields' => ['user_name', 'details', 'ip_address', 'location']
        ],
        'audit_logs' => [
            'label' => 'Audit Trail',
            'description' => 'Export system audit records for governance and accountability.',
            'filters' => [
                'actor_role' => ['label' => 'Role', 'options' => $roleKeys],
                'entity_type' => ['label' => 'Entity Type', 'options' => !empty($auditEntities) ? $auditEntities : ['data_export', 'data_import', 'backup', 'registry', 'task', 'claim', 'payroll', 'settings']],
                'action' => ['label' => 'Action', 'options' => $auditActions]
            ],
            'search_fields' => ['actor_name', 'action', 'entity_type', 'entity_id', 'details']
        ]
    ];

    if (!$workflowExportEnabled) {
        unset($definitions['workflow_logs'], $definitions['tasks']);
    }
    if (!$delegationExportEnabled) {
        unset($definitions['task_delegation_logs']);
    }

    foreach ($definitions as $key => &$config) {
        $datasetDef = dmFetchExportDatasetDefinition($conn, $key);
        $config['key'] = $key;
        $config['columns'] = $datasetDef['columns'] ?? [];
        if (empty($config['field_groups'])) {
            $config['field_groups'] = $commonSingleGroup($config['columns']);
        }
    }
    unset($config);

    return $definitions;
}

function dmBuildDefaultFieldGroups(array $columns): array
{
    $fieldKeys = array_values(array_filter(array_map('strval', array_keys($columns))));
    if (empty($fieldKeys)) {
        return [];
    }

    if (count($fieldKeys) <= 12) {
        return ['Available Fields' => $fieldKeys];
    }

    $groups = [];
    foreach (array_chunk($fieldKeys, 10) as $index => $chunk) {
        $groups['Available Fields ' . ($index + 1)] = array_values($chunk);
    }

    return $groups;
}

function dmResolveExportFieldGroups(array $columns, array $fieldGroups = []): array
{
    $validKeys = array_fill_keys(array_keys($columns), true);
    $normalized = [];
    $used = [];

    foreach ($fieldGroups as $groupLabel => $fields) {
        $groupKeys = [];
        foreach ((array)$fields as $field) {
            $fieldKey = (string)$field;
            if ($fieldKey === '' || !isset($validKeys[$fieldKey]) || isset($used[$fieldKey])) {
                continue;
            }
            $groupKeys[] = $fieldKey;
            $used[$fieldKey] = true;
        }

        if (!empty($groupKeys)) {
            $normalized[(string)$groupLabel] = $groupKeys;
        }
    }

    $remaining = [];
    foreach (array_keys($columns) as $fieldKey) {
        if (!isset($used[$fieldKey])) {
            $remaining[] = (string)$fieldKey;
        }
    }

    if (!empty($remaining)) {
        if (empty($normalized)) {
            return dmBuildDefaultFieldGroups(array_intersect_key($columns, array_fill_keys($remaining, true)));
        }
        $normalized['Additional Fields'] = $remaining;
    }

    return !empty($normalized) ? $normalized : dmBuildDefaultFieldGroups($columns);
}

function dmGetDashboardExportMetadata(mysqli $conn, string $datasetKey): array
{
    $base = dmFetchExportDatasetDefinition($conn, $datasetKey);
    $configs = dmGetExportDatasetConfigs($conn);
    $config = $configs[$datasetKey] ?? [];
    $columns = (array)($base['columns'] ?? []);
    $defaultFields = array_values(array_filter(
        array_map('strval', (array)($config['default_pdf_fields'] ?? [])),
        static function (string $field) use ($columns): bool {
            return $field !== '' && array_key_exists($field, $columns);
        }
    ));
    if (empty($defaultFields)) {
        $defaultFields = array_keys($columns);
    }

    return [
        'dataset_key' => $datasetKey,
        'label' => (string)($base['label'] ?? ($config['label'] ?? 'Data Export')),
        'description' => (string)($config['description'] ?? ''),
        'columns' => $columns,
        'field_groups' => dmResolveExportFieldGroups($columns, (array)($config['field_groups'] ?? [])),
        'default_fields' => $defaultFields,
        'filter_labels' => array_map(
            static function ($definition, $key): string {
                return (string)(is_array($definition) ? ($definition['label'] ?? $key) : $key);
            },
            (array)($config['filters'] ?? []),
            array_keys((array)($config['filters'] ?? []))
        )
    ];
}

function dmBuildConfiguredExportDefinition(mysqli $conn, string $datasetKey, array $requestPayload = []): array
{
    $base = dmFetchExportDatasetDefinition($conn, $datasetKey);
    $configs = dmGetExportDatasetConfigs($conn);
    $config = $configs[$datasetKey] ?? ['filters' => [], 'search_fields' => array_keys((array)($base['columns'] ?? []))];

    $result = $conn->query($base['query']);
    if (!$result) {
        throw new RuntimeException($conn->error ?: 'Unable to query export dataset.');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $normalizedRow = [];
        foreach ($row as $field => $value) {
            $normalizedRow[$field] = dmFormatExportValue((string)$field, $value);
        }
        $rows[] = $normalizedRow;
    }
    $result->close();

    $filters = is_array($requestPayload['filters'] ?? null) ? $requestPayload['filters'] : [];
    $search = strtolower(trim((string)($filters['search'] ?? '')));
    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    $dateTo = trim((string)($filters['date_to'] ?? ''));
    $filterDefs = is_array($config['filters'] ?? null) ? $config['filters'] : [];
    $searchFields = array_values(array_filter(array_map('strval', (array)($config['search_fields'] ?? []))));
    $dateFields = ['deleted_at', 'movement_date', 'created_at', 'recorded_at', 'updated_at', 'uploaded_at', 'due_at', 'completed_at'];

    $rows = array_values(array_filter($rows, static function (array $row) use ($filters, $search, $dateFrom, $dateTo, $filterDefs, $searchFields, $dateFields): bool {
        foreach ($filterDefs as $key => $definition) {
            $filterValue = trim((string)($filters[$key] ?? ''));
            if ($filterValue === '') {
                continue;
            }
            $rowValue = trim((string)($row[$key] ?? ''));
            if (!dmConfiguredFilterMatchesValue((string)$key, $rowValue, $filterValue, (array)$definition)) {
                return false;
            }
        }

        if ($dateFrom !== '' || $dateTo !== '') {
            $candidateDate = '';
            foreach ($dateFields as $field) {
                $value = trim((string)($row[$field] ?? ''));
                if ($value !== '') {
                    $candidateDate = substr($value, 0, 10);
                    break;
                }
            }

            if ($candidateDate !== '') {
                if ($dateFrom !== '' && $candidateDate < $dateFrom) {
                    return false;
                }
                if ($dateTo !== '' && $candidateDate > $dateTo) {
                    return false;
                }
            }
        }

        if ($search !== '') {
            $haystackParts = [];
            foreach ($searchFields as $field) {
                $haystackParts[] = (string)($row[$field] ?? '');
            }
            $haystack = strtolower(implode(' ', $haystackParts));
            if ($haystack === '' || strpos($haystack, $search) === false) {
                return false;
            }
        }

        return true;
    }));

    $allColumns = (array)($base['columns'] ?? []);
    $selectedFields = array_values(array_filter(array_map('strval', (array)($requestPayload['selected_fields'] ?? [])), static function (string $field) use ($allColumns): bool {
        return $field !== '' && array_key_exists($field, $allColumns);
    }));
    if (empty($selectedFields)) {
        $selectedFields = array_keys($allColumns);
    }

    $selectedColumns = [];
    foreach ($selectedFields as $field) {
        $selectedColumns[$field] = $allColumns[$field];
    }

    $projectedRows = [];
    foreach ($rows as $row) {
        $projected = [];
        foreach ($selectedFields as $field) {
            $projected[$field] = $row[$field] ?? '';
        }
        $projectedRows[] = $projected;
    }

    $metaLines = [];
    foreach ($filterDefs as $key => $definition) {
        $filterValue = trim((string)($filters[$key] ?? ''));
        if ($filterValue !== '') {
            $metaLines[] = ($definition['label'] ?? $key) . ': ' . dmFormatExportFilterValue((string)$key, $filterValue);
        }
    }
    if ($search !== '') {
        $metaLines[] = 'Search: ' . $search;
    }
    if ($dateFrom !== '') {
        $metaLines[] = 'Date From: ' . $dateFrom;
    }
    if ($dateTo !== '') {
        $metaLines[] = 'Date To: ' . $dateTo;
    }
    $metaLines[] = 'Included Fields: ' . (count($selectedFields) === count($allColumns) ? 'All' : (count($selectedFields) . ' of ' . count($allColumns)));

    return [
        'label' => $base['label'] ?? ($config['label'] ?? 'Data Export'),
        'rows' => $projectedRows,
        'columns' => $selectedColumns,
        'text_columns' => array_values(array_intersect((array)($base['text_columns'] ?? []), $selectedFields)),
        'pdf_mode' => 'table',
        'meta_lines' => $metaLines
    ];
}
