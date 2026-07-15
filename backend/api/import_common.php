<?php

function ensureDataImportTables(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_data_import_runs (
            import_run_id int(11) NOT NULL AUTO_INCREMENT,
            dataset_key varchar(60) NOT NULL,
            dataset_label varchar(160) NOT NULL,
            source_file_name varchar(255) DEFAULT NULL,
            source_extension varchar(12) DEFAULT NULL,
            execution_mode enum('dry_run','import') NOT NULL DEFAULT 'import',
            run_status enum('success','partial','failed') NOT NULL DEFAULT 'success',
            total_rows int(11) NOT NULL DEFAULT 0,
            inserted_rows int(11) NOT NULL DEFAULT 0,
            merged_rows int(11) NOT NULL DEFAULT 0,
            skipped_exact_rows int(11) NOT NULL DEFAULT 0,
            conflict_rows int(11) NOT NULL DEFAULT 0,
            invalid_rows int(11) NOT NULL DEFAULT 0,
            failed_rows int(11) NOT NULL DEFAULT 0,
            report_json longtext DEFAULT NULL,
            created_by varchar(100) DEFAULT NULL,
            created_by_name varchar(150) DEFAULT NULL,
            created_by_role varchar(60) DEFAULT NULL,
            started_at timestamp NOT NULL DEFAULT current_timestamp(),
            completed_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (import_run_id),
            KEY idx_import_dataset (dataset_key),
            KEY idx_import_started_at (started_at),
            KEY idx_import_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ready = true;
}

function getDataImportDatasetDefinitions(mysqli $conn): array
{
    ensureTitlesTable($conn);
    ensureBanksTable($conn);
    ensureStaffDueBaseColumns($conn);
    ensureStaffDueExtendedColumns($conn);
    ensureFileMovementTables($conn);
    ensureLifeCertificateTables($conn);
    ensurePayrollManagementTables($conn);

    return [
        'staff_due' => [
            'key' => 'staff_due',
            'label' => 'Staff Due for Retirement',
            'description' => 'Bulk load upcoming retirement records and benefit snapshots into the due-for-retirement register.',
            'icon' => '&#128221;',
            'table' => 'tb_staffdue',
            'key_column' => 'employeeNo',
            'key_label' => 'Employee Number',
            'case_insensitive_key' => false,
            'accepted_formats' => ['csv', 'xlsx'],
            'requirements' => [
                'Employee Number must be unique and is used as the HRMIS match key. Pension Number is generated automatically.',
                'Title must already exist in Title Settings and Unit must already exist in Prison Units.',
                'Mode of Retirement should use the approved retirement label where possible; supported legacy labels are still normalized during import.',
                'Age and service retirement-policy checks are enforced during import whenever the supplied retirement profile requires them.',
                'Blank imported values never overwrite existing populated values. They are treated as no-change.'
            ],
            'columns' => [
                ['field' => 'employeeNo', 'label' => 'Employee Number', 'required' => true, 'aliases' => ['employeenumber', 'employee_no', 'employeeno', 'staffnumber', 'staffno', 'regno'], 'format' => 'HRMIS employee number', 'example' => 'P/A/123'],
                ['field' => 'ippsNo', 'label' => 'IPPS Number', 'required' => false, 'aliases' => ['ippsnumber', 'ippsno', 'ipps_no', 'computerno', 'computer_number'], 'format' => 'Text', 'example' => 'IPPS-00981'],
                ['field' => 'rankName', 'label' => 'Rank', 'required' => false, 'aliases' => ['rank', 'rankname'], 'format' => 'Uniformed rank', 'example' => 'Warder'],
                ['field' => 'positionName', 'label' => 'Position', 'required' => false, 'aliases' => ['position', 'positionname', 'title'], 'format' => 'Non-uniformed position', 'example' => 'Records Officer'],
                ['field' => 'firstName', 'label' => 'First Name', 'required' => true, 'aliases' => ['firstname', 'first_name', 'fname', 'givenname'], 'format' => 'Text', 'example' => 'John'],
                ['field' => 'middleName', 'label' => 'Middle Name', 'required' => false, 'aliases' => ['middlename', 'middle_name', 'othernames'], 'format' => 'Text', 'example' => 'Peter'],
                ['field' => 'lastName', 'label' => 'Last Name', 'required' => false, 'aliases' => ['lastname', 'last_name', 'surname', 'sname'], 'format' => 'Text', 'example' => 'Okello'],
                ['field' => 'gender', 'label' => 'Gender', 'required' => true, 'aliases' => ['gender', 'sex'], 'format' => 'Male or Female', 'example' => 'Male'],
                ['field' => 'prisonUnit', 'label' => 'Unit', 'required' => true, 'aliases' => ['unit', 'prisonunit', 'station'], 'format' => 'Existing prison unit', 'example' => 'Luzira Upper Prison'],
                ['field' => 'NIN', 'label' => 'NIN', 'required' => false, 'aliases' => ['nin', 'nationalid'], 'format' => 'Text', 'example' => 'CF80A123456789'],
                ['field' => 'next_of_kin_nin', 'label' => 'Next of Kin NIN', 'required' => false, 'aliases' => ['nextofkinnin', 'next_of_kin_nin', 'administratornin'], 'format' => 'Text', 'example' => 'CM80A123456789'],
                ['field' => 'salaryScale', 'label' => 'Salary Scale', 'required' => false, 'aliases' => ['salaryscale', 'salary_scale', 'scale'], 'format' => 'Text', 'example' => 'U8'],
                ['field' => 'employmentStatus', 'label' => 'Employment Status', 'required' => false, 'aliases' => ['employmentstatus', 'employment_status', 'employeestate', 'empstate', 'status'], 'format' => 'Text', 'example' => 'Active'],
                ['field' => 'tribe', 'label' => 'Tribe', 'required' => false, 'aliases' => ['tribe'], 'format' => 'Text', 'example' => 'Acholi'],
                ['field' => 'homeDistrict', 'label' => 'Home District', 'required' => false, 'aliases' => ['district', 'homedistrict'], 'format' => 'Text', 'example' => 'Gulu'],
                ['field' => 'homeRegion', 'label' => 'Home Region', 'required' => false, 'aliases' => ['region', 'homeregion'], 'format' => 'Text', 'example' => 'Northern'],
                ['field' => 'religion', 'label' => 'Religion', 'required' => false, 'aliases' => ['religion'], 'format' => 'Text', 'example' => 'Christian'],
                ['field' => 'country', 'label' => 'Country', 'required' => false, 'aliases' => ['country', 'nationality'], 'format' => 'Text', 'example' => 'Uganda'],
                ['field' => 'subCounty', 'label' => 'Sub County', 'required' => false, 'aliases' => ['subcounty', 'sub_county'], 'format' => 'Text', 'example' => 'Laroo'],
                ['field' => 'parish', 'label' => 'Parish', 'required' => false, 'aliases' => ['parish'], 'format' => 'Text', 'example' => 'Pece'],
                ['field' => 'village', 'label' => 'Village', 'required' => false, 'aliases' => ['village'], 'format' => 'Text', 'example' => 'Senior Quarters'],
                ['field' => 'alternateTelNo', 'label' => 'Alternative Phone Number', 'required' => false, 'aliases' => ['tel2', 'telephone2', 'alternatephone'], 'format' => 'Phone number', 'example' => '+256772000000'],
                ['field' => 'maritalStatus', 'label' => 'Marital Status', 'required' => false, 'aliases' => ['maritalstatus', 'marital_status'], 'format' => 'Text', 'example' => 'Married'],
                ['field' => 'applicant_email', 'label' => 'Email Address', 'required' => false, 'aliases' => ['email', 'emailaddress', 'applicantemail'], 'format' => 'Email', 'example' => 'officer@ugandaprisons.go.ug'],
                ['field' => 'telNo', 'label' => 'Phone Number', 'required' => false, 'aliases' => ['telno', 'tel1', 'telephone1', 'phone', 'phone_number', 'contact'], 'format' => 'International or Uganda local', 'example' => '+256701234567'],
                ['field' => 'birthDate', 'label' => 'Date of Birth', 'required' => false, 'aliases' => ['birthdate', 'dateofbirth', 'dob'], 'format' => 'YYYY-MM-DD', 'example' => '1970-05-20'],
                ['field' => 'enlistmentDate', 'label' => 'Date of Enlistment', 'required' => false, 'aliases' => ['enlistmentdate', 'dateofenlistment', 'enrollment', 'enrolment'], 'format' => 'YYYY-MM-DD', 'example' => '1990-01-10'],
                ['field' => 'retirementDate', 'label' => 'Date of Retirement', 'required' => false, 'aliases' => ['retirementdate', 'dateofretirement'], 'format' => 'YYYY-MM-DD', 'example' => '2026-06-30'],
                ['field' => 'financialYear', 'label' => 'Financial Year', 'required' => false, 'aliases' => ['financialyear', 'fy'], 'format' => 'FY YYYY/YYYY', 'example' => 'FY 2025/2026'],
                ['field' => 'retirementType', 'label' => 'Mode of Retirement', 'required' => false, 'aliases' => ['retirementtype', 'modeofretirement', 'retirement_mode'], 'format' => 'Defaults to Mandatory Retirement for raw HRMIS master reports', 'example' => 'Mandatory Retirement'],
                ['field' => 'monthlySalary', 'label' => 'Monthly Salary', 'required' => false, 'aliases' => ['monthlysalary', 'salary'], 'format' => 'Decimal', 'example' => '1250000'],
                ['field' => 'lengthOfService', 'label' => 'Length of Service (Months)', 'required' => false, 'aliases' => ['lengthofservice', 'service_months'], 'format' => 'Whole number', 'example' => '360'],
                ['field' => 'annualSalary', 'label' => 'Annual Salary', 'required' => false, 'aliases' => ['annualsalary'], 'format' => 'Decimal', 'example' => '15000000'],
                ['field' => 'reducedPension', 'label' => 'Expected Reduced Pension', 'required' => false, 'aliases' => ['reducedpension', 'expectedreducedmonthlypension'], 'format' => 'Decimal', 'example' => '650000'],
                ['field' => 'fullPension', 'label' => 'Expected Full Pension', 'required' => false, 'aliases' => ['fullpension', 'expectedfullpension'], 'format' => 'Decimal', 'example' => '820000'],
                ['field' => 'gratuity', 'label' => 'Expected Commuted Gratuity', 'required' => false, 'aliases' => ['gratuity', 'commutedpensiongratuity', 'expectedcommutedpensiongratuity'], 'format' => 'Decimal', 'example' => '25000000'],
                ['field' => 'submissionStatus', 'label' => 'Submission Status', 'required' => false, 'aliases' => ['submissionstatus'], 'format' => 'Pending or Submitted', 'example' => 'Pending'],
                ['field' => 'appnStatus', 'label' => 'Application Status', 'required' => false, 'aliases' => ['appnstatus', 'applicationstatus'], 'format' => 'Pending, Verified, Queried, Rejected, Completed', 'example' => 'Pending']
            ],
            'template_rows' => [
                ['P/A/123', 'IPPS-00981', 'Warder', '', 'John', 'Peter', 'Okello', 'Male', 'Luzira Upper Prison', 'CF1234567890AB', 'CM1234567890AB', 'U8', 'Active', 'Acholi', 'Gulu', 'Northern', 'Christian', 'Uganda', 'Laroo', 'Pece', 'Senior Quarters', '+256772000000', 'Married', 'officer@ugandaprisons.go.ug', '+256701234567', '1970-05-20', '1990-01-10', '2026-06-30', 'FY 2025/2026', 'Mandatory Retirement', '1250000', '360', '15000000', '650000', '820000', '25000000', 'Pending', 'Pending']
            ]
        ],
        'file_registry' => [
            'key' => 'file_registry',
            'label' => 'Pension File Registry',
            'description' => 'Import approved pension file records, contact profiles, banking data, and registry shelf information.',
            'icon' => '&#128452;',
            'table' => 'tb_fileregistry',
            'key_column' => 'regNo',
            'key_label' => 'File Number',
            'case_insensitive_key' => false,
            'accepted_formats' => ['csv', 'xlsx'],
            'requirements' => [
                'File Number is the primary match key for imports into the registry and must start with the PEN/ prefix.',
                'If Box Number is blank, the system allocates one automatically using the current boxing rules.',
                'Mode of Retirement should use the approved retirement label where possible; supported legacy labels are still normalized during import.',
                'Age and service retirement-policy checks are enforced during import whenever the supplied retirement profile requires them.',
                'Death records must include both Next of Kin and Next of Kin Contact before import can pass validation.',
                'Imported payroll, life certificate, and pay type values are normalized to the system vocabulary.'
            ],
            'columns' => [
                ['field' => 'regNo', 'label' => 'File Number', 'required' => true, 'aliases' => ['regno', 'filenumber', 'file_number'], 'format' => 'PEN/1 or PEN/A/1', 'example' => 'PEN/A/1'],
                ['field' => 'computerNo', 'label' => 'Computer Number', 'required' => false, 'aliases' => ['computerno', 'computer_number'], 'format' => 'Text', 'example' => 'PC-00981'],
                ['field' => 'supplierNo', 'label' => 'Supplier Number', 'required' => false, 'aliases' => ['supplierno', 'suppliernumber'], 'format' => 'Text', 'example' => 'SUP-001'],
                ['field' => 'title', 'label' => 'Title', 'required' => false, 'aliases' => ['title', 'rank'], 'format' => 'Existing title', 'example' => 'Warder'],
                ['field' => 'sName', 'label' => 'Surname', 'required' => true, 'aliases' => ['surname', 'sname', 'last_name'], 'format' => 'Text', 'example' => 'Okello'],
                ['field' => 'fName', 'label' => 'First Name', 'required' => true, 'aliases' => ['firstname', 'first_name', 'fname'], 'format' => 'Text', 'example' => 'John'],
                ['field' => 'gender', 'label' => 'Gender', 'required' => false, 'aliases' => ['gender', 'sex'], 'format' => 'Male or Female', 'example' => 'Male'],
                ['field' => 'livingStatus', 'label' => 'Living Status', 'required' => false, 'aliases' => ['livingstatus', 'status_of_life'], 'format' => 'Alive or Deceased', 'example' => 'Alive'],
                ['field' => 'lifeCertificate', 'label' => 'Life Certificate', 'required' => false, 'aliases' => ['lifecertificate'], 'format' => 'Submitted, Not Submitted or Exempt', 'example' => 'Not Submitted'],
                ['field' => 'boxNo', 'label' => 'Box Number', 'required' => false, 'aliases' => ['boxno', 'box_number'], 'format' => 'Numeric text', 'example' => '12'],
                ['field' => 'birthDate', 'label' => 'Date of Birth', 'required' => false, 'aliases' => ['birthdate', 'dateofbirth', 'dob'], 'format' => 'YYYY-MM-DD', 'example' => '1970-05-20'],
                ['field' => 'enlistmentDate', 'label' => 'Date of Enlistment', 'required' => false, 'aliases' => ['enlistmentdate', 'dateofenlistment'], 'format' => 'YYYY-MM-DD', 'example' => '1990-01-10'],
                ['field' => 'retirementDate', 'label' => 'Date of Retirement', 'required' => false, 'aliases' => ['retirementdate', 'dateofretirement'], 'format' => 'YYYY-MM-DD', 'example' => '2026-06-30'],
                ['field' => 'retirementType', 'label' => 'Mode of Retirement', 'required' => false, 'aliases' => ['retirementtype', 'modeofretirement'], 'format' => 'Approved retirement label or supported legacy alias', 'example' => 'Mandatory Retirement'],
                ['field' => 'TIN', 'label' => 'TIN', 'required' => false, 'aliases' => ['tin', 'taxidentificationnumber'], 'format' => 'Text', 'example' => '1002003004'],
                ['field' => 'NIN', 'label' => 'NIN', 'required' => false, 'aliases' => ['nin'], 'format' => 'Text', 'example' => 'CF80A123456789'],
                ['field' => 'address', 'label' => 'Address', 'required' => false, 'aliases' => ['address', 'postaladdress'], 'format' => 'Text', 'example' => 'Plot 1 Kampala Road'],
                ['field' => 'telNo', 'label' => 'Phone Number', 'required' => false, 'aliases' => ['telno', 'phone', 'phone_number'], 'format' => 'International or Uganda local', 'example' => '+256701234567'],
                ['field' => 'applicant_email', 'label' => 'Applicant Email', 'required' => false, 'aliases' => ['applicantemail', 'email'], 'format' => 'Email', 'example' => 'john.okello@example.com'],
                ['field' => 'next_of_kin', 'label' => 'Next of Kin', 'required' => false, 'aliases' => ['nextofkin', 'next_of_kin'], 'format' => 'Text', 'example' => 'Mary Okello'],
                ['field' => 'next_of_kin_contact', 'label' => 'Next of Kin Contact', 'required' => false, 'aliases' => ['nextofkincontact', 'next_of_kin_contact'], 'format' => 'Phone number', 'example' => '+256777000111'],
                ['field' => 'bank_name', 'label' => 'Bank Name', 'required' => false, 'aliases' => ['bankname', 'bank_name'], 'format' => 'Text', 'example' => 'Stanbic Bank'],
                ['field' => 'bank_account', 'label' => 'Bank Account', 'required' => false, 'aliases' => ['bankaccount', 'bank_account'], 'format' => 'Text', 'example' => '002345678901'],
                ['field' => 'bank_branch', 'label' => 'Bank Branch', 'required' => false, 'aliases' => ['bankbranch', 'bank_branch'], 'format' => 'Text', 'example' => 'Kampala Main'],
                ['field' => 'monthlySalary', 'label' => 'Monthly Salary', 'required' => false, 'aliases' => ['monthlysalary'], 'format' => 'Decimal', 'example' => '1250000'],
                ['field' => 'lengthOfService', 'label' => 'Length of Service (Months)', 'required' => false, 'aliases' => ['lengthofservice'], 'format' => 'Whole number', 'example' => '360'],
                ['field' => 'annualSalary', 'label' => 'Annual Salary', 'required' => false, 'aliases' => ['annualsalary'], 'format' => 'Decimal', 'example' => '15000000'],
                ['field' => 'reducedPension', 'label' => 'Reduced Pension', 'required' => false, 'aliases' => ['reducedpension'], 'format' => 'Decimal', 'example' => '650000'],
                ['field' => 'fullPension', 'label' => 'Full Pension', 'required' => false, 'aliases' => ['fullpension'], 'format' => 'Decimal', 'example' => '820000'],
                ['field' => 'gratuity', 'label' => 'Gratuity', 'required' => false, 'aliases' => ['gratuity'], 'format' => 'Decimal', 'example' => '25000000'],
                ['field' => 'payrollStatus', 'label' => 'Payroll Status', 'required' => false, 'aliases' => ['payrollstatus'], 'format' => 'On Payroll or Not on Payroll', 'example' => 'Not on Payroll'],
                ['field' => 'payType', 'label' => 'Pay Type', 'required' => false, 'aliases' => ['paytype', 'paymenttype'], 'format' => 'Optional. Auto-determined from the retirement label and, where required, enlistment and retirement dates; otherwise Pensioner or One-off Payment', 'example' => 'Pensioner'],
                ['field' => 'availability_status', 'label' => 'Availability', 'required' => false, 'aliases' => ['availability', 'availabilitystatus', 'availability_status'], 'format' => 'in_shelf or out_of_shelf', 'example' => 'in_shelf'],
                ['field' => 'availability_reason', 'label' => 'Availability Reason', 'required' => false, 'aliases' => ['availabilityreason', 'availability_reason'], 'format' => 'Text', 'example' => 'Still with Approver'],
                ['field' => 'other', 'label' => 'Other Notes', 'required' => false, 'aliases' => ['other', 'notes', 'remarks'], 'format' => 'Text', 'example' => 'Imported from legacy registry']
            ],
            'template_rows' => [
                ['PEN/A/1', 'PC-00981', 'SUP-001', 'Warder', 'Okello', 'John', 'Male', 'Alive', 'Not Submitted', '', '1970-05-20', '1990-01-10', '2026-06-30', 'Mandatory Retirement', '1002003004', 'CF1234567890AB', 'Plot 1 Kampala Road', '+256701234567', 'john.okello@example.com', 'Mary Okello', '+256777000111', 'Stanbic Bank', '002345678901', 'Kampala Main', '1250000', '360', '15000000', '650000', '820000', '25000000', 'Not on Payroll', 'Pensioner', 'in_shelf', '', 'Imported from legacy registry']
            ]
        ],
        'titles' => [
            'key' => 'titles',
            'label' => 'Titles Catalogue',
            'description' => 'Maintain the official titles catalogue used across staff due, registry, and workflow forms.',
            'icon' => '&#128203;',
            'table' => 'tb_titles',
            'key_column' => 'title_name',
            'key_label' => 'Title Name',
            'case_insensitive_key' => true,
            'accepted_formats' => ['csv', 'xlsx'],
            'requirements' => [
                'Title Name is matched case-insensitively.',
                'Category accepts uniformed or non_uniformed.',
                'Level accepts junior or senior, while Active accepts 1/0 or yes/no.'
            ],
            'columns' => [
                ['field' => 'title_name', 'label' => 'Title Name', 'required' => true, 'aliases' => ['title_name', 'titlename', 'title'], 'format' => 'Text', 'example' => 'Chief Warder II'],
                ['field' => 'category', 'label' => 'Category', 'required' => true, 'aliases' => ['category'], 'format' => 'uniformed or non_uniformed', 'example' => 'uniformed'],
                ['field' => 'level', 'label' => 'Level', 'required' => true, 'aliases' => ['level'], 'format' => 'junior or senior', 'example' => 'junior'],
                ['field' => 'is_active', 'label' => 'Active', 'required' => false, 'aliases' => ['is_active', 'active', 'status'], 'format' => '1/0 or yes/no', 'example' => '1']
            ],
            'template_rows' => [
                ['Chief Warder II', 'uniformed', 'junior', '1']
            ]
        ],
        'prison_units' => [
            'key' => 'prison_units',
            'label' => 'Prison Units',
            'description' => 'Import prison unit master data, prison districts, prison regions, and political geography references.',
            'icon' => '&#127970;',
            'table' => 'tb_priunits',
            'key_column' => 'priUnit',
            'key_label' => 'Unit Name',
            'case_insensitive_key' => true,
            'accepted_formats' => ['csv', 'xlsx'],
            'requirements' => [
                'Unit Name is the match key for existing unit records.',
                'Political District and Political Region should use the corrected political naming standard.',
                'Blank fields do not clear existing unit metadata during merges.'
            ],
            'columns' => [
                ['field' => 'priUnit', 'label' => 'Unit Name', 'required' => true, 'aliases' => ['priunit', 'unit', 'unitname'], 'format' => 'Text', 'example' => 'Luzira Upper Prison'],
                ['field' => 'priDistrict', 'label' => 'Prison District', 'required' => false, 'aliases' => ['pridistrict', 'prisondistrict'], 'format' => 'Text', 'example' => 'Kampala Metropolitan'],
                ['field' => 'priRegion', 'label' => 'Prison Region', 'required' => false, 'aliases' => ['priregion', 'prisonregion'], 'format' => 'Text', 'example' => 'Central'],
                ['field' => 'polDistrict', 'label' => 'Political District', 'required' => false, 'aliases' => ['poldistrict', 'politicaldistrict'], 'format' => 'Text', 'example' => 'Kampala'],
                ['field' => 'polRegion', 'label' => 'Political Region', 'required' => false, 'aliases' => ['polregion', 'politicalregion'], 'format' => 'Text', 'example' => 'Central']
            ],
            'template_rows' => [
                ['Luzira Upper Prison', 'Kampala Metropolitan', 'Central', 'Kampala', 'Central']
            ]
        ],
        'users' => [
            'key' => 'users',
            'label' => 'System Users',
            'description' => 'Import staff user accounts, governed roles, and contact details for operational onboarding.',
            'icon' => '&#128101;',
            'table' => 'tb_users',
            'key_column' => 'userEmail',
            'key_label' => 'Email Address',
            'case_insensitive_key' => true,
            'accepted_formats' => ['csv', 'xlsx'],
            'requirements' => [
                'Email Address is used as the primary account match key.',
                'Role can be supplied as either the role key or the visible role label.',
                'If Password is left blank for a new row, the system assigns the default import password Welcome123!.'
            ],
            'columns' => [
                ['field' => 'userTitle', 'label' => 'Title', 'required' => true, 'aliases' => ['usertitle', 'title'], 'format' => 'Mr., Ms., Dr., etc.', 'example' => 'Mr.'],
                ['field' => 'userName', 'label' => 'Full Name', 'required' => true, 'aliases' => ['username', 'fullname', 'full_name', 'name'], 'format' => 'Text', 'example' => 'Patrick Etomet'],
                ['field' => 'userEmail', 'label' => 'Email Address', 'required' => true, 'aliases' => ['useremail', 'email', 'emailaddress'], 'format' => 'Email', 'example' => 'patrick@example.com'],
                ['field' => 'phoneNo', 'label' => 'Phone Number', 'required' => true, 'aliases' => ['phoneno', 'phone', 'phone_number', 'contact'], 'format' => 'International or Uganda local', 'example' => '+256701234567'],
                ['field' => 'userRole', 'label' => 'Role', 'required' => true, 'aliases' => ['userrole', 'role'], 'format' => 'Active role key or label', 'example' => 'clerk'],
                ['field' => 'userPassword', 'label' => 'Password', 'required' => false, 'aliases' => ['userpassword', 'password'], 'format' => 'Text', 'example' => 'Welcome123!'],
                ['field' => 'userPhoto', 'label' => 'Photo Path', 'required' => false, 'aliases' => ['userphoto', 'photo', 'imagepath'], 'format' => 'Relative path', 'example' => 'images/default-user.png'],
                ['field' => 'other', 'label' => 'Notes', 'required' => false, 'aliases' => ['other', 'notes', 'remarks'], 'format' => 'Text', 'example' => 'Imported by admin']
            ],
            'template_rows' => [
                ['Mr.', 'Patrick Etomet', 'patrick@example.com', '+256701234567', 'clerk', 'Welcome123!', 'images/default-user.png', 'Imported by admin']
            ]
        ],
        'claims_ledger' => [
            'key' => 'claims_ledger',
            'label' => 'Claims Ledger',
            'description' => 'Import arrears and claim ledger rows into the budgeting and claims engine using period-based matching.',
            'icon' => '&#128184;',
            'table' => 'tb_arrears_ledger',
            'key_column' => 'regNo',
            'key_label' => 'File Number + Claim Type + Period',
            'case_insensitive_key' => false,
            'accepted_formats' => ['csv', 'xlsx'],
            'composite_key_fields' => ['regNo', 'claim_type', 'period_year', 'period_month', 'source_type', 'reference_cycle_id'],
            'requirements' => [
                'The matching key is File Number + Claim Type + Period Year + Period Month + Source Type + Reference Cycle ID.',
                'Financial Year and Quarter are auto-derived when left blank.',
                'Balance Amount is recalculated automatically from Expected Amount minus Paid Amount.'
            ],
            'columns' => [
                ['field' => 'regNo', 'label' => 'File Number', 'required' => true, 'aliases' => ['regno', 'filenumber', 'file_number'], 'format' => 'Existing file number', 'example' => 'UPS/RET/0001'],
                ['field' => 'claim_type', 'label' => 'Claim Type', 'required' => true, 'aliases' => ['claimtype', 'claim_type', 'type'], 'format' => 'Pension Arrears, Gratuity Arrears, Full Pension Arrears, Pension & Gratuity, Underpayment', 'example' => 'Pension Arrears'],
                ['field' => 'period_year', 'label' => 'Period Year', 'required' => true, 'aliases' => ['periodyear', 'period_year', 'year'], 'format' => 'YYYY', 'example' => '2025'],
                ['field' => 'period_month', 'label' => 'Period Month', 'required' => true, 'aliases' => ['periodmonth', 'period_month', 'month'], 'format' => '1-12', 'example' => '7'],
                ['field' => 'financial_year_label', 'label' => 'Financial Year', 'required' => false, 'aliases' => ['financialyearlabel', 'financial_year', 'financial_year_label'], 'format' => 'FY YYYY/YYYY', 'example' => 'FY 2025/2026'],
                ['field' => 'quarter_label', 'label' => 'Quarter', 'required' => false, 'aliases' => ['quarterlabel', 'quarter_label', 'quarter'], 'format' => 'Q1-Q4', 'example' => 'Q1'],
                ['field' => 'expected_amount', 'label' => 'Expected Amount', 'required' => true, 'aliases' => ['expectedamount', 'expected_amount', 'amount'], 'format' => 'Decimal', 'example' => '200000'],
                ['field' => 'paid_amount', 'label' => 'Paid Amount', 'required' => false, 'aliases' => ['paidamount', 'paid_amount'], 'format' => 'Decimal', 'example' => '0'],
                ['field' => 'status', 'label' => 'Status', 'required' => false, 'aliases' => ['status'], 'format' => 'Pending, Partially Paid, Paid, Waived', 'example' => 'Pending'],
                ['field' => 'claim_status', 'label' => 'Claim Status', 'required' => false, 'aliases' => ['claimstatus', 'claim_status', 'verificationstatus', 'verification_status'], 'format' => 'Complete, Incomplete, Invalid, Valid', 'example' => 'Incomplete'],
                ['field' => 'source_type', 'label' => 'Source Type', 'required' => false, 'aliases' => ['sourcetype', 'source_type', 'source'], 'format' => 'missed_payment, auto_delay, auto_full_pension', 'example' => 'missed_payment'],
                ['field' => 'reference_cycle_id', 'label' => 'Reference Cycle ID', 'required' => false, 'aliases' => ['referencecycleid', 'reference_cycle_id', 'cycleid'], 'format' => 'Whole number', 'example' => '0'],
                ['field' => 'reason', 'label' => 'Reason', 'required' => false, 'aliases' => ['reason'], 'format' => 'Text', 'example' => 'Imported legacy arrears'],
                ['field' => 'notes', 'label' => 'Notes', 'required' => false, 'aliases' => ['notes', 'remarks'], 'format' => 'Text', 'example' => 'Legacy migration batch 1']
            ],
            'template_rows' => [
                ['UPS/RET/0001', 'Pension Arrears', '2025', '7', 'FY 2025/2026', 'Q1', '200000', '0', 'Pending', 'Incomplete', 'missed_payment', '0', 'Imported legacy arrears', 'Legacy migration batch 1']
            ]
        ],
        'payroll_support' => [
            'key' => 'payroll_support',
            'label' => 'Payroll Support Snapshot',
            'description' => 'Import monthly payroll support rows into the registry-linked payroll support table for backfill or controlled migration.',
            'icon' => '&#128179;',
            'table' => 'tb_registry_payroll_monthly_status',
            'key_column' => 'regNo',
            'key_label' => 'File Number + Year + Month',
            'case_insensitive_key' => false,
            'accepted_formats' => ['csv', 'xlsx'],
            'composite_key_fields' => ['regNo', 'payroll_year', 'payroll_month'],
            'requirements' => [
                'The matching key is File Number + Payroll Year + Payroll Month.',
                'Financial Year and Quarter are auto-derived from the year and month when omitted.',
                'Only existing file numbers should be imported into payroll support snapshots.'
            ],
            'columns' => [
                ['field' => 'regNo', 'label' => 'File Number', 'required' => true, 'aliases' => ['regno', 'filenumber', 'file_number'], 'format' => 'Existing file number', 'example' => 'UPS/RET/0001'],
                ['field' => 'payroll_year', 'label' => 'Payroll Year', 'required' => true, 'aliases' => ['payrollyear', 'payroll_year', 'year'], 'format' => 'YYYY', 'example' => '2026'],
                ['field' => 'payroll_month', 'label' => 'Payroll Month', 'required' => true, 'aliases' => ['payrollmonth', 'payroll_month', 'month'], 'format' => '1-12', 'example' => '1'],
                ['field' => 'financial_year_label', 'label' => 'Financial Year', 'required' => false, 'aliases' => ['financialyearlabel', 'financial_year', 'financial_year_label'], 'format' => 'FY YYYY/YYYY', 'example' => 'FY 2025/2026'],
                ['field' => 'quarter_label', 'label' => 'Quarter', 'required' => false, 'aliases' => ['quarterlabel', 'quarter_label', 'quarter'], 'format' => 'Q1-Q4', 'example' => 'Q3'],
                ['field' => 'payroll_status', 'label' => 'Payroll Status', 'required' => false, 'aliases' => ['payrollstatus', 'payroll_status', 'status'], 'format' => 'On Payroll or Not on Payroll', 'example' => 'On Payroll'],
                ['field' => 'amount', 'label' => 'Amount', 'required' => false, 'aliases' => ['amount', 'monthlyamount'], 'format' => 'Decimal', 'example' => '820000'],
                ['field' => 'supplierNo', 'label' => 'Supplier Number', 'required' => false, 'aliases' => ['supplierno', 'suppliernumber'], 'format' => 'Text', 'example' => 'SUP-001'],
                ['field' => 'cycle_id', 'label' => 'Cycle ID', 'required' => false, 'aliases' => ['cycleid', 'cycle_id'], 'format' => 'Whole number', 'example' => '0']
            ],
            'template_rows' => [
                ['UPS/RET/0001', '2026', '1', 'FY 2025/2026', 'Q3', 'On Payroll', '820000', 'SUP-001', '0']
            ]
        ]
    ];
}

function getDataImportOverview(mysqli $conn): array
{
    ensureDataImportTables($conn);
    $datasets = getDataImportDatasetDefinitions($conn);
    $counts = [];

    foreach ($datasets as $key => $dataset) {
        $table = $dataset['table'];
        $result = $conn->query("SELECT COUNT(*) AS total FROM {$table}");
        $counts[$key] = $result ? (int)(($result->fetch_assoc()['total'] ?? 0)) : 0;
    }

    $runs = [];
    $result = $conn->query("
        SELECT *
        FROM tb_data_import_runs
        ORDER BY import_run_id DESC
        LIMIT 15
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $report = json_decode((string)($row['report_json'] ?? ''), true);
            $runs[] = [
                'import_run_id' => (int)($row['import_run_id'] ?? 0),
                'dataset_key' => (string)($row['dataset_key'] ?? ''),
                'dataset_label' => (string)($row['dataset_label'] ?? ''),
                'source_file_name' => (string)($row['source_file_name'] ?? ''),
                'source_extension' => (string)($row['source_extension'] ?? ''),
                'execution_mode' => (string)($row['execution_mode'] ?? 'import'),
                'run_status' => (string)($row['run_status'] ?? 'success'),
                'total_rows' => (int)($row['total_rows'] ?? 0),
                'inserted_rows' => (int)($row['inserted_rows'] ?? 0),
                'merged_rows' => (int)($row['merged_rows'] ?? 0),
                'skipped_exact_rows' => (int)($row['skipped_exact_rows'] ?? 0),
                'conflict_rows' => (int)($row['conflict_rows'] ?? 0),
                'invalid_rows' => (int)($row['invalid_rows'] ?? 0),
                'failed_rows' => (int)($row['failed_rows'] ?? 0),
                'created_by_name' => (string)($row['created_by_name'] ?? ''),
                'created_by_role' => (string)($row['created_by_role'] ?? ''),
                'started_at' => (string)($row['started_at'] ?? ''),
                'completed_at' => (string)($row['completed_at'] ?? ''),
                'report' => is_array($report) ? $report : null
            ];
        }
    }

    return [
        'datasets' => array_map(static function (array $dataset, string $key) use ($counts) {
            return [
                'key' => $dataset['key'],
                'label' => $dataset['label'],
                'description' => $dataset['description'],
                'icon' => $dataset['icon'],
                'accepted_formats' => $dataset['accepted_formats'],
                'requirements' => $dataset['requirements'],
                'key_label' => $dataset['key_label'],
                'row_count' => $counts[$key] ?? 0,
                'columns' => $dataset['columns']
            ];
        }, $datasets, array_keys($datasets)),
        'runs' => $runs
    ];
}

function importNormalizeHeader(string $value): string
{
    $normalized = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]+/', '', $normalized);
}

function importExcelColumnToIndex(string $letters): int
{
    $letters = strtoupper(trim($letters));
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function importParseCsvRows(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Unable to read CSV file.');
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($value) => trim((string)$value), $row);
    }
    fclose($handle);
    return $rows;
}

function importParseXlsxRows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled on this server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open XLSX file.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDoc = simplexml_load_string((string)$sharedXml);
        if ($sharedDoc !== false) {
            $namespaces = $sharedDoc->getNamespaces(true);
            $mainNs = $namespaces[''] ?? null;
            $root = $mainNs ? $sharedDoc->children($mainNs) : $sharedDoc;
            foreach ($root->si as $si) {
                $siNode = $mainNs ? $si->children($mainNs) : $si;
                $text = '';
                if (isset($siNode->t)) {
                    $text = (string)$siNode->t;
                } elseif (isset($siNode->r)) {
                    foreach ($siNode->r as $run) {
                        $runNode = $mainNs ? $run->children($mainNs) : $run;
                        $text .= (string)($runNode->t ?? '');
                    }
                }
                $sharedStrings[] = trim($text);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#i', $entry)) {
                $sheetXml = $zip->getFromName($entry);
                if ($sheetXml !== false) {
                    break;
                }
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('Worksheet not found in XLSX file.');
    }

    $doc = simplexml_load_string((string)$sheetXml);
    if ($doc === false) {
        throw new RuntimeException('Unable to parse worksheet XML.');
    }

    $namespaces = $doc->getNamespaces(true);
    $mainNs = $namespaces[''] ?? null;
    $root = $mainNs ? $doc->children($mainNs) : $doc;
    if (!isset($root->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($root->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = strtoupper((string)($attrs['r'] ?? ''));
            $type = strtolower((string)($attrs['t'] ?? ''));
            $cellNode = $mainNs ? $cell->children($mainNs) : $cell;
            $columnIndex = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $match)) {
                $columnIndex = importExcelColumnToIndex($match[1]);
            }

            $value = '';
            if ($type === 's') {
                $sharedIndex = (int)($cellNode->v ?? 0);
                $value = (string)($sharedStrings[$sharedIndex] ?? '');
            } elseif ($type === 'inlineStr') {
                if (isset($cellNode->is->t)) {
                    $value = (string)$cellNode->is->t;
                } elseif (isset($cellNode->is->r)) {
                    foreach ($cellNode->is->r as $run) {
                        $runNode = $mainNs ? $run->children($mainNs) : $run;
                        $value .= (string)($runNode->t ?? '');
                    }
                }
            } else {
                $value = (string)($cellNode->v ?? '');
            }

            $line[$columnIndex] = trim($value);
        }

        if (!empty($line)) {
            ksort($line);
            $rows[] = $line;
        }
    }

    return $rows;
}

function importParseFileRows(string $path, string $extension): array
{
    $normalized = strtolower(trim($extension));
    if ($normalized === 'xlxl') {
        $normalized = 'xlsx';
    }

    if ($normalized === 'csv') {
        return importParseCsvRows($path);
    }
    if ($normalized === 'xlsx') {
        return importParseXlsxRows($path);
    }

    throw new RuntimeException('Unsupported import file format.');
}

function importParseDateValue(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
        $numeric = (float)$raw;
        if ($numeric > 0 && $numeric < 100000) {
            $days = (int)floor($numeric);
            $base = new DateTimeImmutable('1899-12-30');
            try {
                return $base->modify('+' . $days . ' days')->format('Y-m-d');
            } catch (Throwable $e) {
                // Fall through to the standard date parsers.
            }
        }
    }

    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y/m/d', 'd.m.Y', 'Y.m.d', 'd M Y', 'd M, Y', 'j M Y', 'j M, Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat('!' . $format, $raw);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function importParseNumberValue(?string $value, bool $integer = false): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return $integer ? (string)((int)round((float)$normalized)) : number_format((float)$normalized, 2, '.', '');
}

function importNormalizeBoolean(?string $value): string
{
    $raw = strtolower(trim((string)$value));
    if (in_array($raw, ['1', 'true', 'yes', 'y', 'active'], true)) {
        return '1';
    }
    return '0';
}

function importCanonicalUnit(mysqli $conn, ?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT priUnit
        FROM tb_priunits
        WHERE LOWER(priUnit) = LOWER(?)
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $raw);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row['priUnit'] ?? null;
}

function importNormalizeFinancialYear(?string $value, ?string $retirementDate = null): ?string
{
    $raw = trim((string)$value);
    if ($raw === '' && $retirementDate) {
        $parsed = importParseDateValue($retirementDate);
        if ($parsed) {
            return getFinancialYearLabelForMonth((int)date('Y', strtotime($parsed)), (int)date('n', strtotime($parsed)));
        }
        return null;
    }

    if ($raw === '') {
        return null;
    }

    if (preg_match('/^FY\s*\d{4}\/\d{4}$/i', $raw)) {
        return strtoupper(substr($raw, 0, 2)) . substr($raw, 2);
    }

    if (preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $raw, $match)) {
        return 'FY ' . $match[1] . '/' . $match[2];
    }

    return $raw;
}

function importNormalizeEnumValue(?string $value, array $allowed, array $map = []): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $normalized = strtolower($raw);
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    foreach ($allowed as $item) {
        if (strtolower($item) === $normalized) {
            return $item;
        }
    }

    return null;
}

function importNormalizeFieldValue(mysqli $conn, string $datasetKey, string $field, ?string $value, array $rowContext = []): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return ['value' => null, 'error' => null];
    }

    switch ($field) {
        case 'regNo':
            $regNo = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
            if ($datasetKey === 'file_registry' && !preg_match('/^PEN\/(?:[1-9][0-9]{0,4}|[A-Z]\/[1-9][0-9]{0,3})$/', $regNo)) {
                return ['value' => null, 'error' => 'File Number must use "PEN/1" or "PEN/A/1" format. Use only one capital letter when present; numbers must start from 1, have no leading zeroes, and must not exceed 99999 without a letter or 9999 with a letter.'];
            }
            if (in_array($datasetKey, ['claims_ledger', 'payroll_support'], true)) {
                $stmt = $conn->prepare("SELECT regNo FROM tb_fileregistry WHERE regNo = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $regNo);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result ? $result->fetch_assoc() : null;
                    $stmt->close();
                    if (!$row) {
                        return ['value' => null, 'error' => 'File Number does not exist in Pension File Registry.'];
                    }
                }
            }
            return ['value' => $regNo, 'error' => null];
        case 'supplierNo':
        case 'computerNo':
        case 'TIN':
        case 'boxNo':
            return ['value' => strtoupper($raw), 'error' => null];

        case 'NIN':
            $birthDateRaw = isset($rowContext['birthDate']) ? trim((string)$rowContext['birthDate']) : null;
            $genderRaw = isset($rowContext['gender']) ? trim((string)$rowContext['gender']) : null;
            $ninValidation = validateNationalIdNumber($raw, $birthDateRaw !== '' ? $birthDateRaw : null, $genderRaw !== '' ? $genderRaw : null);
            return [
                'value' => $ninValidation['normalized'] ?? strtoupper($raw),
                'error' => !empty($ninValidation['valid']) ? null : (string)($ninValidation['message'] ?? 'NIN is invalid.')
            ];

        case 'sName':
        case 'fName':
        case 'userTitle':
        case 'userName':
        case 'userPhoto':
        case 'title_name':
        case 'priUnit':
        case 'priDistrict':
        case 'priRegion':
        case 'polDistrict':
        case 'polRegion':
        case 'address':
        case 'next_of_kin':
        case 'bank_account':
        case 'bank_branch':
        case 'retirementType':
        case 'availability_reason':
        case 'claim_type':
        case 'quarter_label':
        case 'source_type':
            if ($datasetKey === 'claims_ledger') {
                $norm = strtolower(trim($raw));
                $norm = preg_replace('/\s+/', ' ', $norm);
                $norm = str_replace(' ', '_', $norm);
                if (in_array($norm, ['missed_payment', 'manual_entry', 'manualentry', 'manual', 'missedpayment'], true)) {
                    return ['value' => 'missed_payment', 'error' => null];
                }
                return ['value' => $norm, 'error' => null];
            }
            if ($field === 'retirementType') {
                $normalizedRetirementType = normalizeBenefitsRetirementTypeKey($raw);
                if ($normalizedRetirementType === '' || !isBenefitsRetirementTypeSupported($normalizedRetirementType)) {
                    return ['value' => $raw, 'error' => 'Retirement type is not recognised. Use the supported retirement-type values from the template.'];
                }
                return ['value' => $normalizedRetirementType, 'error' => null];
            }
            return ['value' => $raw, 'error' => null];

        case 'bank_name':
            $canonicalBank = normalizeBankCatalogName($conn, $raw, false);
            if ($canonicalBank === null) {
                return ['value' => null, 'error' => 'Bank Name does not exist in Bank Settings.'];
            }
            return ['value' => $canonicalBank, 'error' => null];

        case 'reason':
        case 'other':
        case 'notes':
            return ['value' => $raw, 'error' => null];

        case 'title':
            $canonical = normalizeRegistryTitle($conn, $raw);
            if ($canonical === null) {
                return ['value' => null, 'error' => 'Title does not exist in Title Settings.'];
            }
            return ['value' => $canonical, 'error' => null];

        case 'gender':
            $gender = importNormalizeEnumValue($raw, ['Male', 'Female'], [
                'm' => 'Male',
                'male' => 'Male',
                'f' => 'Female',
                'female' => 'Female'
            ]);
            return ['value' => $gender, 'error' => $gender ? null : 'Gender must be Male or Female.'];

        case 'telNo':
        case 'next_of_kin_contact':
            $phone = normalizePhoneNumber($raw);
            return ['value' => $phone, 'error' => $phone ? null : 'Phone number format is invalid.'];

        case 'birthDate':
        case 'enlistmentDate':
        case 'retirementDate':
            $dateValue = importParseDateValue($raw);
            return ['value' => $dateValue, 'error' => $dateValue ? null : 'Date format is invalid. Use YYYY-MM-DD or a recognizable date.'];

        case 'financialYear':
            $fy = importNormalizeFinancialYear($raw, $rowContext['retirementDate'] ?? null);
            return ['value' => $fy, 'error' => null];

        case 'monthlySalary':
        case 'annualSalary':
        case 'reducedPension':
        case 'fullPension':
        case 'gratuity':
            $amount = importParseNumberValue($raw, false);
            return ['value' => $amount, 'error' => $amount !== null ? null : 'Amount must be numeric.'];

        case 'lengthOfService':
            $whole = importParseNumberValue($raw, true);
            return ['value' => $whole, 'error' => $whole !== null ? null : 'Length of service must be a whole number.'];

        case 'submissionStatus':
            $status = importNormalizeEnumValue($raw, ['Pending', 'Submitted'], [
                'pending' => 'Pending',
                'submitted' => 'Submitted'
            ]);
            return ['value' => $status, 'error' => $status ? null : 'Submission Status must be Pending or Submitted.'];

        case 'appnStatus':
            $status = importNormalizeEnumValue($raw, ['Pending', 'Submitted', 'Verified', 'Queried', 'Rejected', 'In Process', 'Completed'], [
                'inprocess' => 'In Process',
                'in process' => 'In Process',
                'complete' => 'Completed',
                'completed' => 'Completed'
            ]);
            return ['value' => $status, 'error' => $status ? null : 'Application Status is invalid.'];

        case 'livingStatus':
            return ['value' => normalizeRegistryLivingStatus($raw), 'error' => null];

        case 'lifeCertificate':
            $life = importNormalizeEnumValue($raw, ['Submitted', 'Not Submitted', 'Exempt'], [
                'submitted' => 'Submitted',
                'notsubmitted' => 'Not Submitted',
                'not submitted' => 'Not Submitted',
                'exempt' => 'Exempt'
            ]);
            return ['value' => $life, 'error' => $life ? null : 'Life Certificate must be Submitted, Not Submitted or Exempt.'];

        case 'payrollStatus':
            $payroll = importNormalizeEnumValue($raw, ['On Payroll', 'Not on Payroll'], [
                'onpayroll' => 'On Payroll',
                'on payroll' => 'On Payroll',
                'notonpayroll' => 'Not on Payroll',
                'not on payroll' => 'Not on Payroll'
            ]);
            return ['value' => $payroll, 'error' => $payroll ? null : 'Payroll Status must be On Payroll or Not on Payroll.'];

        case 'payType':
            return ['value' => normalizeRegistryPayType($raw), 'error' => null];

        case 'applicant_email':
        case 'userEmail':
            $email = filter_var($raw, FILTER_VALIDATE_EMAIL);
            return ['value' => $email ?: null, 'error' => $email ? null : 'Email address is invalid.'];

        case 'userRole':
            $roleKey = resolveRoleKeyFromInput($conn, $raw, true);
            $allowedRoles = getActiveRoleKeys($conn);
            if ($roleKey === '' || !in_array($roleKey, $allowedRoles, true)) {
                return ['value' => null, 'error' => 'Role must match an active governed role.'];
            }
            return ['value' => $roleKey, 'error' => null];

        case 'userPassword':
            return ['value' => $raw, 'error' => null];

        case 'period_year':
        case 'payroll_year':
            $year = importParseNumberValue($raw, true);
            if ($year === null || (int)$year < 1900 || (int)$year > 3000) {
                return ['value' => null, 'error' => 'Year must be a valid four-digit year.'];
            }
            return ['value' => $year, 'error' => null];

        case 'period_month':
        case 'payroll_month':
            $month = importParseNumberValue($raw, true);
            if ($month === null || (int)$month < 1 || (int)$month > 12) {
                return ['value' => null, 'error' => 'Month must be between 1 and 12.'];
            }
            return ['value' => $month, 'error' => null];

        case 'expected_amount':
        case 'paid_amount':
        case 'balance_amount':
        case 'amount':
            $amount = importParseNumberValue($raw, false);
            return ['value' => $amount, 'error' => $amount !== null ? null : 'Amount must be numeric.'];

        case 'reference_cycle_id':
        case 'cycle_id':
            $whole = importParseNumberValue($raw, true);
            return ['value' => $whole ?? '0', 'error' => null];

        case 'status':
            if ($datasetKey === 'claims_ledger') {
                $status = importNormalizeEnumValue($raw, ['Pending', 'Partially Paid', 'Paid', 'Waived'], [
                    'partiallypaid' => 'Partially Paid',
                    'partial' => 'Partially Paid'
                ]);
                return ['value' => $status, 'error' => $status ? null : 'Status must be Pending, Partially Paid, Paid or Waived.'];
            }
            if ($datasetKey === 'payroll_support') {
                $status = importNormalizeEnumValue($raw, ['On Payroll', 'Not on Payroll'], [
                    'onpayroll' => 'On Payroll',
                    'on payroll' => 'On Payroll',
                    'notonpayroll' => 'Not on Payroll',
                    'not on payroll' => 'Not on Payroll'
                ]);
                return ['value' => $status, 'error' => $status ? null : 'Payroll Status must be On Payroll or Not on Payroll.'];
            }
            return ['value' => $raw, 'error' => null];

        case 'claim_status':
            if ($datasetKey === 'claims_ledger') {
                $status = importNormalizeEnumValue($raw, ['Complete', 'Incomplete', 'Invalid', 'Valid'], [
                    'completed' => 'Complete',
                    'complete' => 'Complete',
                    'incomplete' => 'Incomplete',
                    'invalid' => 'Invalid',
                    'valid' => 'Valid'
                ]);
                return ['value' => $status, 'error' => $status ? null : 'Claim Status must be Complete, Incomplete, Invalid or Valid.'];
            }
            return ['value' => $raw, 'error' => null];

        case 'financial_year_label':
            $fy = importNormalizeFinancialYear($raw);
            return ['value' => $fy, 'error' => null];

        case 'availability_status':
            $availability = importNormalizeEnumValue($raw, ['in_shelf', 'out_of_shelf'], [
                'inshelf' => 'in_shelf',
                'in shelf' => 'in_shelf',
                'outofshelf' => 'out_of_shelf',
                'out of shelf' => 'out_of_shelf'
            ]);
            return ['value' => $availability, 'error' => $availability ? null : 'Availability must be in_shelf or out_of_shelf.'];

        case 'category':
            $category = importNormalizeEnumValue($raw, ['uniformed', 'non_uniformed'], [
                'uniformed' => 'uniformed',
                'nonuniformed' => 'non_uniformed',
                'non uniformed' => 'non_uniformed'
            ]);
            return ['value' => $category, 'error' => $category ? null : 'Category must be uniformed or non_uniformed.'];

        case 'level':
            $level = importNormalizeEnumValue($raw, ['junior', 'senior']);
            return ['value' => $level, 'error' => $level ? null : 'Level must be junior or senior.'];

        case 'is_active':
            return ['value' => importNormalizeBoolean($raw), 'error' => null];

        case 'prisonUnit':
            $unit = importCanonicalUnit($conn, $raw);
            if ($unit === null) {
                return ['value' => null, 'error' => 'Unit does not exist in Prison Units.'];
            }
            return ['value' => $unit, 'error' => null];

        default:
            return ['value' => $raw, 'error' => null];
    }
}

function importNormalizeExistingValue(string $field, $value): ?string
{
    if ($value === null) {
        return null;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    switch ($field) {
        case 'monthlySalary':
        case 'annualSalary':
        case 'reducedPension':
        case 'fullPension':
        case 'gratuity':
            return number_format((float)$raw, 2, '.', '');
        case 'lengthOfService':
        case 'is_active':
            return (string)((int)$raw);
        case 'regNo':
        case 'supplierNo':
        case 'computerNo':
        case 'NIN':
        case 'TIN':
        case 'boxNo':
            return strtoupper($raw);
        case 'claim_status':
            $status = importNormalizeEnumValue($raw, ['Complete', 'Incomplete', 'Invalid', 'Valid'], [
                'completed' => 'Complete',
                'complete' => 'Complete',
                'incomplete' => 'Incomplete',
                'invalid' => 'Invalid',
                'valid' => 'Valid'
            ]);
            return $status ?? $raw;
        default:
            return $raw;
    }
}

function importGetHeaderMap(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $index => $label) {
        $map[importNormalizeHeader((string)$label)] = (int)$index;
    }
    return $map;
}

function importBuildMappedRow(mysqli $conn, array $dataset, array $row, array $headerMap): array
{
    $mapped = [];
    $errors = [];

    foreach ($dataset['columns'] as $column) {
        $index = null;
        $matchCandidates = array_values(array_unique(array_filter(array_merge(
            $column['aliases'] ?? [],
            [$column['field'] ?? '', $column['label'] ?? '']
        ))));
        foreach ($matchCandidates as $alias) {
            $normalizedAlias = importNormalizeHeader((string)$alias);
            if (array_key_exists($normalizedAlias, $headerMap)) {
                $index = $headerMap[$normalizedAlias];
                break;
            }
        }

        if ($index === null) {
            if (!empty($column['required'])) {
                $errors[] = $column['label'] . ' column is missing from the file.';
            }
            continue;
        }

        $mapped[$column['field']] = trim((string)($row[$index] ?? ''));
    }

    if (!empty($mapped['retirementDate']) && empty($mapped['financialYear']) && $dataset['key'] === 'staff_due') {
        $mapped['financialYear'] = importNormalizeFinancialYear('', $mapped['retirementDate']);
    }
    if (($dataset['key'] === 'claims_ledger' || $dataset['key'] === 'payroll_support')
        && !empty($mapped['period_year'] ?? $mapped['payroll_year'] ?? '')
        && !empty($mapped['period_month'] ?? $mapped['payroll_month'] ?? '')
    ) {
        $year = (int)($mapped['period_year'] ?? $mapped['payroll_year'] ?? 0);
        $month = (int)($mapped['period_month'] ?? $mapped['payroll_month'] ?? 0);
        if ($year > 0 && $month >= 1 && $month <= 12) {
            if (empty($mapped['financial_year_label'])) {
                $mapped['financial_year_label'] = getFinancialYearLabelForMonth($year, $month);
            }
            if (empty($mapped['quarter_label'])) {
                $mapped['quarter_label'] = getQuarterLabelForMonth($month);
            }
        }
    }

    $normalized = [];
    foreach ($mapped as $field => $value) {
        $result = importNormalizeFieldValue($conn, $dataset['key'], $field, $value, $mapped);
        if ($result['error']) {
            $errors[] = $result['error'] . ' [' . $field . ']';
        }
        $normalized[$field] = $result['value'];
    }

    $normalized = importApplyCalculatedDatasetFields($dataset['key'], $normalized);

    if (in_array($dataset['key'], ['staff_due', 'file_registry'], true)) {
        $policyAssessment = validateRetirementPolicyProfile(
            (string)($normalized['retirementType'] ?? ''),
            !empty($normalized['birthDate']) ? (string)$normalized['birthDate'] : null,
            !empty($normalized['enlistmentDate']) ? (string)$normalized['enlistmentDate'] : null,
            !empty($normalized['retirementDate']) ? (string)$normalized['retirementDate'] : null
        );
        if (!empty($policyAssessment['errors'])) {
            $errors[] = (string)($policyAssessment['primary_message'] ?? 'The retirement profile does not satisfy the configured policy checks.');
        }

    }

    foreach ($dataset['columns'] as $column) {
        $field = $column['field'];
        $required = !empty($column['required']);
        if ($required && (!array_key_exists($field, $normalized) || $normalized[$field] === null || $normalized[$field] === '')) {
            $errors[] = $column['label'] . ' is required.';
        }
    }

    return [
        'values' => $normalized,
        'errors' => array_values(array_unique($errors))
    ];
}

function importValidateFileRegistryDeathContacts(array $incomingValues, ?array $existingRow = null): array
{
    $effective = is_array($existingRow) ? $existingRow : [];
    foreach ($incomingValues as $field => $value) {
        if ($value !== null && $value !== '') {
            $effective[$field] = $value;
        }
    }

    $retirementType = normalizeBenefitsRetirementTypeKey((string)($effective['retirementType'] ?? ''));
    $derivedLivingStatus = deriveLivingStatusFromRetirementType(
        $retirementType,
        (string)($effective['livingStatus'] ?? 'Alive')
    );
    $livingStatus = strtolower(trim((string)$derivedLivingStatus));

    if ($retirementType !== 'death' && $livingStatus !== 'deceased') {
        return [];
    }

    $errors = [];
    if (trim((string)($effective['next_of_kin'] ?? '')) === '') {
        $errors[] = 'Next of Kin is required for death registry records.';
    }
    if (trim((string)($effective['next_of_kin_contact'] ?? '')) === '') {
        $errors[] = 'Next of Kin Contact is required for death registry records.';
    }

    return $errors;
}

function importApplyCalculatedDatasetFields(string $datasetKey, array $values, array $fallback = []): array
{
    if (!in_array($datasetKey, ['file_registry', 'staff_due'], true)) {
        return $values;
    }

    $source = $fallback;
    foreach ($values as $field => $value) {
        if ($value !== null && $value !== '') {
            $source[$field] = $value;
        }
    }

    $monthlySalary = $source['monthlySalary'] ?? null;
    $snapshot = calculateBenefitSnapshotFromInputs(
        $source['retirementType'] ?? null,
        $source['enlistmentDate'] ?? null,
        $source['retirementDate'] ?? null,
        $monthlySalary,
        $source['birthDate'] ?? null,
        $datasetKey === 'file_registry'
    );

    if (($values['lengthOfService'] ?? '') === '' && $snapshot['lengthOfService'] !== null) {
        $values['lengthOfService'] = (string)((int)$snapshot['lengthOfService']);
    }

    if (($values['annualSalary'] ?? '') === '' && $snapshot['annualSalary'] !== null) {
        $values['annualSalary'] = number_format((float)$snapshot['annualSalary'], 2, '.', '');
    }

    if (($values['reducedPension'] ?? '') === '' && $snapshot['reducedPension'] !== null) {
        $values['reducedPension'] = number_format((float)$snapshot['reducedPension'], 2, '.', '');
    }

    if (($values['fullPension'] ?? '') === '' && $snapshot['fullPension'] !== null) {
        $values['fullPension'] = number_format((float)$snapshot['fullPension'], 2, '.', '');
    }

    if (($values['gratuity'] ?? '') === '' && $snapshot['gratuity'] !== null) {
        $values['gratuity'] = number_format((float)$snapshot['gratuity'], 2, '.', '');
    }

    if ($datasetKey === 'staff_due') {
        if (trim((string)($source['retirementType'] ?? '')) === '') {
            $source['retirementType'] = 'mandatory';
            $values['retirementType'] = 'mandatory';
        }
        if (trim((string)($source['retirementDate'] ?? '')) === '' && !empty($source['birthDate'])) {
            try {
                $mandatoryDate = (new DateTimeImmutable((string)$source['birthDate']))->modify('+60 years')->format('Y-m-d');
                $source['retirementDate'] = $mandatoryDate;
                $values['retirementDate'] = $mandatoryDate;
                $values['financialYear'] = importNormalizeFinancialYear('', $mandatoryDate);
            } catch (Throwable $ignored) {}
        }
        $employeeNo = trim((string)($source['employeeNo'] ?? ''));
        $values['regNo'] = pensionNumberFromEmployeeNumber($employeeNo);
        $values['computerNo'] = trim((string)($source['ippsNo'] ?? ''));
        $rankPosition = trim((string)($source['rankName'] ?? '')) ?: trim((string)($source['positionName'] ?? ''));
        $values['rankPosition'] = $rankPosition;
        $values['title'] = $rankPosition;
        $values['fName'] = trim(implode(' ', array_filter([$source['firstName'] ?? '', $source['middleName'] ?? ''], static fn($part) => trim((string)$part) !== '')));
        $values['sName'] = trim((string)($source['lastName'] ?? ''));
        $values['payType'] = deriveRegistryPayTypeFromProfile(
            $source['retirementType'] ?? null,
            $source['enlistmentDate'] ?? null,
            $source['retirementDate'] ?? null,
            $source['payType'] ?? null
        );
        $values['livingStatus'] = deriveLivingStatusFromRetirementType(
            $source['retirementType'] ?? null,
            $source['livingStatus'] ?? 'Alive'
        );
    }

    if ($datasetKey === 'file_registry') {
        $values['livingStatus'] = deriveLivingStatusFromRetirementType(
            $source['retirementType'] ?? null,
            $source['livingStatus'] ?? 'Alive'
        );
        $values['payType'] = deriveRegistryPayTypeFromProfile(
            $source['retirementType'] ?? null,
            $source['enlistmentDate'] ?? null,
            $source['retirementDate'] ?? null,
            $source['payType'] ?? null
        );

        if (($values['dateOn15yrs'] ?? '') === '') {
            $dateOn15 = computeDateOn15Years($source['retirementDate'] ?? null);
            if ($dateOn15 !== null) {
                $values['dateOn15yrs'] = $dateOn15;
            }
        }

        $estateLifecycle = evaluatePensionEstateLifecycle(
            $source['retirementDate'] ?? null,
            $values['payType'] ?? null,
            $values['livingStatus'] ?? null,
            $source['dateOfDeath'] ?? null
        );
        if (($values['estateExpiryDate'] ?? '') === '' && !empty($estateLifecycle['estateExpiryDate'])) {
            $values['estateExpiryDate'] = $estateLifecycle['estateExpiryDate'];
        }
        if (($values['estateStatus'] ?? '') === '' && !empty($estateLifecycle['label'])) {
            $values['estateStatus'] = $estateLifecycle['label'];
        }
    }

    return $values;
}

function importFetchExistingRow(mysqli $conn, array $dataset, $keyValue): ?array
{
    $table = $dataset['table'];
    $params = [];
    $types = '';
    $conditions = [];

    if (!empty($dataset['composite_key_fields'])) {
        foreach ($dataset['composite_key_fields'] as $field) {
            $params[] = $keyValue[$field] ?? '';
            $types .= 's';
            $conditions[] = "{$field} = ?";
        }
    } else {
        $keyColumn = $dataset['key_column'];
        $params[] = $keyValue;
        $types .= 's';
        if (!empty($dataset['case_insensitive_key'])) {
            $conditions[] = "LOWER({$keyColumn}) = LOWER(?)";
        } else {
            $conditions[] = "{$keyColumn} = ?";
        }
    }

    $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $references = [];
    $references[] = &$types;
    foreach ($params as $index => &$param) {
        $references[] = &$param;
    }
    call_user_func_array([$stmt, 'bind_param'], $references);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function importInsertRow(mysqli $conn, array $dataset, array $values): bool
{
    $values = importApplyCalculatedDatasetFields($dataset['key'], $values);

    $columns = [];
    $params = [];

    foreach ($dataset['columns'] as $column) {
        $field = $column['field'];
        if (array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '') {
            $columns[] = $field;
            $params[] = $values[$field];
        }
    }

    if ($dataset['key'] === 'staff_due') {
        foreach (['regNo', 'computerNo', 'title', 'rankPosition', 'fName', 'sName'] as $compatibilityField) {
            if (!in_array($compatibilityField, $columns, true) && !empty($values[$compatibilityField])) {
                $columns[] = $compatibilityField;
                $params[] = $values[$compatibilityField];
            }
        }
        if (empty($values['submissionStatus'])) {
            $columns[] = 'submissionStatus';
            $params[] = 'Pending';
        }
        if (empty($values['appnStatus'])) {
            $columns[] = 'appnStatus';
            $params[] = 'Pending';
        }
        if (!in_array('payType', $columns, true) && !empty($values['payType'])) {
            $columns[] = 'payType';
            $params[] = $values['payType'];
        }
        if (!in_array('livingStatus', $columns, true) && !empty($values['livingStatus'])) {
            $columns[] = 'livingStatus';
            $params[] = $values['livingStatus'];
        }
    }

    if ($dataset['key'] === 'file_registry') {
        if (!in_array('dateOn15yrs', $columns, true)) {
            $columns[] = 'dateOn15yrs';
            $params[] = computeDateOn15Years($values['retirementDate'] ?? null);
        }
        if (!in_array('estateExpiryDate', $columns, true) && !empty($values['estateExpiryDate'])) {
            $columns[] = 'estateExpiryDate';
            $params[] = $values['estateExpiryDate'];
        }
        if (!in_array('estateStatus', $columns, true) && !empty($values['estateStatus'])) {
            $columns[] = 'estateStatus';
            $params[] = $values['estateStatus'];
        }
        if (empty($values['boxNo'])) {
            $columns[] = 'boxNo';
            $params[] = allocateRegistryBoxNumber($conn, (string)($values['livingStatus'] ?? 'Alive'), $values['payType'] ?? null);
        }
        if (empty($values['payrollStatus'])) {
            $columns[] = 'payrollStatus';
            $params[] = 'Not on Payroll';
        }
        if (empty($values['lifeCertificate'])) {
            $columns[] = 'lifeCertificate';
            $params[] = isLifeCertificateExemptRecord((string)($values['livingStatus'] ?? ''), (string)($values['payType'] ?? '')) ? 'Exempt' : 'Not Submitted';
        }
    } elseif ($dataset['key'] === 'users') {
        if (!in_array('userId', $columns, true)) {
            $columns[] = 'userId';
            $params[] = hash('sha256', strtoupper(substr(base_convert(random_int(100000, 999999), 10, 36), 0, 4)) . '|' . strtolower((string)($values['userEmail'] ?? '')) . '|' . microtime(true));
        }
        if (!in_array('userPassword', $columns, true)) {
            $columns[] = 'userPassword';
            $params[] = password_hash('Welcome123!', PASSWORD_DEFAULT);
        } else {
            $passwordIndex = array_search('userPassword', $columns, true);
            if ($passwordIndex !== false) {
                $rawPassword = trim((string)($params[$passwordIndex] ?? ''));
                $params[$passwordIndex] = password_hash($rawPassword !== '' ? $rawPassword : 'Welcome123!', PASSWORD_DEFAULT);
            }
        }
        if (!in_array('userPhoto', $columns, true)) {
            $columns[] = 'userPhoto';
            $params[] = 'images/default-user.png';
        }
    } elseif ($dataset['key'] === 'claims_ledger') {
        $periodYear = (int)($values['period_year'] ?? 0);
        $periodMonth = (int)($values['period_month'] ?? 0);
        if (!in_array('financial_year_label', $columns, true)) {
            $columns[] = 'financial_year_label';
            $params[] = getFinancialYearLabelForMonth($periodYear, $periodMonth);
        }
        if (!in_array('quarter_label', $columns, true)) {
            $columns[] = 'quarter_label';
            $params[] = getQuarterLabelForMonth($periodMonth);
        }
        if (!in_array('claim_status', $columns, true)) {
            $columns[] = 'claim_status';
            $params[] = 'Incomplete';
        }
        if (!in_array('paid_amount', $columns, true)) {
            $columns[] = 'paid_amount';
            $params[] = '0.00';
        }
        if (!in_array('balance_amount', $columns, true)) {
            $columns[] = 'balance_amount';
            $params[] = number_format(max(0, (float)($values['expected_amount'] ?? 0) - (float)($values['paid_amount'] ?? 0)), 2, '.', '');
        }
        if (!in_array('recorded_by', $columns, true)) {
            $columns[] = 'recorded_by';
            $params[] = (string)($_SESSION['userId'] ?? '');
        }
    } elseif ($dataset['key'] === 'payroll_support') {
        $year = (int)($values['payroll_year'] ?? 0);
        $month = (int)($values['payroll_month'] ?? 0);
        if (!in_array('financial_year_label', $columns, true)) {
            $columns[] = 'financial_year_label';
            $params[] = getFinancialYearLabelForMonth($year, $month);
        }
        if (!in_array('quarter_label', $columns, true)) {
            $columns[] = 'quarter_label';
            $params[] = getQuarterLabelForMonth($month);
        }
        if (!in_array('payroll_status', $columns, true)) {
            $columns[] = 'payroll_status';
            $params[] = ((float)($values['amount'] ?? 0) > 0) ? 'On Payroll' : 'Not on Payroll';
        }
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO {$dataset['table']} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($params));
    $references = [];
    $references[] = &$types;
    foreach ($params as $index => &$param) {
        $references[] = &$param;
    }
    call_user_func_array([$stmt, 'bind_param'], $references);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function importMergeExistingRow(mysqli $conn, array $dataset, array $existingRow, array $incomingValues, array &$mergedFields, array &$conflictFields): bool
{
    $incomingValues = importApplyCalculatedDatasetFields($dataset['key'], $incomingValues, $existingRow);
    $updates = [];

    foreach ($dataset['columns'] as $column) {
        $field = $column['field'];
        if (!array_key_exists($field, $incomingValues)) {
            continue;
        }

        if ($dataset['key'] === 'users' && $field === 'userPassword') {
            continue;
        }

        $incoming = $incomingValues[$field];
        if ($incoming === null || $incoming === '') {
            continue;
        }

        $existing = importNormalizeExistingValue($field, $existingRow[$field] ?? null);
        $incomingNormalized = importNormalizeExistingValue($field, $incoming);

        if ($existing === null || $existing === '') {
            $updates[$field] = $incoming;
            $mergedFields[] = $field;
            continue;
        }

        if ($existing !== $incomingNormalized) {
            $conflictFields[] = $field;
        }
    }

    if ($dataset['key'] === 'file_registry') {
        $existingBox = trim((string)($existingRow['boxNo'] ?? ''));
        if ($existingBox === '' && !array_key_exists('boxNo', $updates)) {
            $updates['boxNo'] = allocateRegistryBoxNumber($conn, (string)($incomingValues['livingStatus'] ?? $existingRow['livingStatus'] ?? 'Alive'), $incomingValues['payType'] ?? $existingRow['payType'] ?? null);
            $mergedFields[] = 'boxNo';
        }

        $retirementDate = $incomingValues['retirementDate'] ?? ($existingRow['retirementDate'] ?? null);
        $dateOn15 = computeDateOn15Years($retirementDate);
        if ($dateOn15 && trim((string)($existingRow['dateOn15yrs'] ?? '')) === '') {
            $updates['dateOn15yrs'] = $dateOn15;
            $mergedFields[] = 'dateOn15yrs';
        }

        $effectiveRetirementDate = $incomingValues['retirementDate'] ?? ($existingRow['retirementDate'] ?? null);
        $effectivePayType = $incomingValues['payType'] ?? ($existingRow['payType'] ?? null);
        $effectiveLivingStatus = $incomingValues['livingStatus'] ?? ($existingRow['livingStatus'] ?? null);
        $effectiveDateOfDeath = $incomingValues['dateOfDeath'] ?? ($existingRow['dateOfDeath'] ?? null);
        $estateLifecycle = evaluatePensionEstateLifecycle(
            $effectiveRetirementDate,
            $effectivePayType,
            $effectiveLivingStatus,
            $effectiveDateOfDeath
        );
        $derivedEstateExpiryDate = trim((string)($estateLifecycle['estateExpiryDate'] ?? ''));
        $derivedEstateStatus = trim((string)($estateLifecycle['label'] ?? ''));
        if ($derivedEstateExpiryDate !== '' && trim((string)($existingRow['estateExpiryDate'] ?? '')) === '') {
            $updates['estateExpiryDate'] = $derivedEstateExpiryDate;
            $mergedFields[] = 'estateExpiryDate';
        }
        if ($derivedEstateStatus !== '' && trim((string)($existingRow['estateStatus'] ?? '')) === '') {
            $updates['estateStatus'] = $derivedEstateStatus;
            $mergedFields[] = 'estateStatus';
        }

        $existingLife = trim((string)($existingRow['lifeCertificate'] ?? ''));
        if ($existingLife === '' || strtolower($existingLife) === 'not submitted') {
            if (isLifeCertificateExemptRecord((string)($incomingValues['livingStatus'] ?? $existingRow['livingStatus'] ?? ''), (string)($incomingValues['payType'] ?? $existingRow['payType'] ?? ''))) {
                $updates['lifeCertificate'] = 'Exempt';
                $mergedFields[] = 'lifeCertificate';
            }
        }
    } elseif ($dataset['key'] === 'staff_due') {
        $derivedPayType = trim((string)($incomingValues['payType'] ?? ''));
        if ($derivedPayType !== '' && trim((string)($existingRow['payType'] ?? '')) === '') {
            $updates['payType'] = $derivedPayType;
            $mergedFields[] = 'payType';
        }

        $derivedLivingStatus = trim((string)($incomingValues['livingStatus'] ?? ''));
        $existingLivingStatus = trim((string)($existingRow['livingStatus'] ?? ''));
        if (
            $derivedLivingStatus !== ''
            && (
                $existingLivingStatus === ''
                || ($derivedLivingStatus === 'Deceased' && strcasecmp($existingLivingStatus, 'Deceased') !== 0)
            )
        ) {
            $updates['livingStatus'] = $derivedLivingStatus;
            $mergedFields[] = 'livingStatus';
        }
    }

    if (empty($updates)) {
        return true;
    }

    $setParts = [];
    $params = [];
    foreach ($updates as $field => $value) {
        $setParts[] = "{$field} = ?";
        $params[] = $value;
    }
    $whereParts = [];
    if (!empty($dataset['composite_key_fields'])) {
        foreach ($dataset['composite_key_fields'] as $field) {
            $whereParts[] = "{$field} = ?";
            $params[] = $existingRow[$field];
        }
    } else {
        $whereParts[] = "{$dataset['key_column']} = ?";
        $params[] = $existingRow[$dataset['key_column']];
    }

    $sql = "UPDATE {$dataset['table']} SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($params));
    $references = [];
    $references[] = &$types;
    foreach ($params as $index => &$param) {
        $references[] = &$param;
    }
    call_user_func_array([$stmt, 'bind_param'], $references);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function importPersistRun(mysqli $conn, array $dataset, string $fileName, string $extension, string $mode, string $status, array $summary, array $report, string $actorId, string $actorName, string $actorRole): void
{
    ensureDataImportTables($conn);

    $stmt = $conn->prepare("
        INSERT INTO tb_data_import_runs (
            dataset_key, dataset_label, source_file_name, source_extension, execution_mode, run_status,
            total_rows, inserted_rows, merged_rows, skipped_exact_rows, conflict_rows, invalid_rows, failed_rows,
            report_json, created_by, created_by_name, created_by_role, completed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        return;
    }

    $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt->bind_param(
        'ssssssiiiiiiissss',
        $dataset['key'],
        $dataset['label'],
        $fileName,
        $extension,
        $mode,
        $status,
        $summary['total_rows'],
        $summary['inserted_rows'],
        $summary['merged_rows'],
        $summary['skipped_exact_rows'],
        $summary['conflict_rows'],
        $summary['invalid_rows'],
        $summary['failed_rows'],
        $reportJson,
        $actorId,
        $actorName,
        $actorRole
    );
    $stmt->execute();
    $stmt->close();
}

function resolveImportRunStatus(array $summary): string
{
    $inserted = (int)($summary['inserted_rows'] ?? 0);
    $merged = (int)($summary['merged_rows'] ?? 0);
    $skippedExact = (int)($summary['skipped_exact_rows'] ?? 0);
    $conflicts = (int)($summary['conflict_rows'] ?? 0);
    $invalid = (int)($summary['invalid_rows'] ?? 0);
    $failed = (int)($summary['failed_rows'] ?? 0);

    $hasSuccessfulProgress = ($inserted + $merged + $skippedExact) > 0;
    $hasReviewItems = ($conflicts + $invalid + $failed) > 0;

    if ($hasSuccessfulProgress && $hasReviewItems) {
        return 'partial';
    }

    if ($failed > 0) {
        return 'failed';
    }

    if (($conflicts + $invalid) > 0) {
        return 'partial';
    }

    return 'success';
}

function buildImportCompletionMessage(array $dataset, string $mode, string $status, array $summary): string
{
    $datasetLabel = trim((string)($dataset['label'] ?? 'Import'));
    $noun = $mode === 'dry_run' ? 'Dry check' : $datasetLabel . ' import';
    $inserted = (int)($summary['inserted_rows'] ?? 0);
    $merged = (int)($summary['merged_rows'] ?? 0);
    $skippedExact = (int)($summary['skipped_exact_rows'] ?? 0);
    $conflicts = (int)($summary['conflict_rows'] ?? 0);
    $invalid = (int)($summary['invalid_rows'] ?? 0);
    $failed = (int)($summary['failed_rows'] ?? 0);

    if ($status === 'success') {
        return $mode === 'dry_run'
            ? "{$noun} completed successfully. No review issues were found."
            : "{$noun} completed successfully. Review the report for inserted and merged rows.";
    }

    if ($status === 'partial') {
        return $mode === 'dry_run'
            ? "{$noun} completed with review items. {$inserted} row(s) are ready, {$merged} merge(s), {$skippedExact} exact match(es), {$conflicts} conflict row(s), {$invalid} invalid row(s), and {$failed} failed row(s)."
            : "{$noun} completed with review items. {$inserted} row(s) were inserted, {$merged} merged, {$skippedExact} already matched, {$conflicts} conflict row(s), {$invalid} invalid row(s), and {$failed} failed row(s).";
    }

    return $mode === 'dry_run'
        ? "{$noun} could not be completed. Review the reported errors and try again."
        : "{$noun} failed before any rows could be applied. Review the reported errors and try again.";
}

function processDataImport(mysqli $conn, string $datasetKey, string $path, string $fileName, string $extension, string $mode, string $actorId, string $actorName, string $actorRole): array
{
    $definitions = getDataImportDatasetDefinitions($conn);
    if (!isset($definitions[$datasetKey])) {
        throw new RuntimeException('Unknown import dataset.');
    }

    $dataset = $definitions[$datasetKey];
    $rows = importParseFileRows($path, $extension);
    // The official UPS HRMIS master report contains roughly 17.7k staff rows.
    // Other datasets retain the configured ceiling; this bounded exception still
    // passes the file-size and ZIP archive safety controls.
    enforceParsedRowLimit($conn, max(0, count($rows) - 1), $dataset['label'], $datasetKey === 'staff_due' ? 25000 : 0);
    if (count($rows) < 2) {
        throw new RuntimeException('The import file must contain a header row and at least one data row.');
    }

    $inputHeaders = array_map(static fn($value) => trim((string)$value), $rows[0]);
    $headerMap = importGetHeaderMap($rows[0]);
    $dataRows = array_slice($rows, 1);
    $reportRows = [];
    $reviewRows = [];
    $summary = [
        'total_rows' => 0,
        'inserted_rows' => 0,
        'merged_rows' => 0,
        'skipped_exact_rows' => 0,
        'conflict_rows' => 0,
        'invalid_rows' => 0,
        'failed_rows' => 0
    ];
    $registryRegNosToSync = [];

    $dryRun = $mode === 'dry_run';
    $conn->begin_transaction();

    try {
        foreach ($dataRows as $offset => $row) {
            $rowNumber = $offset + 2;
            $nonEmpty = array_filter($row, static fn($value) => trim((string)$value) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $summary['total_rows']++;
            $built = importBuildMappedRow($conn, $dataset, $row, $headerMap);
            $values = $built['values'];
            $errors = $built['errors'];
            $keyField = $dataset['key_column'];
            $displayKey = '';

            if ($datasetKey === 'staff_due') {
                $employeeState = strtolower(trim((string)($values['employmentStatus'] ?? '')));
                $disqualifiedStates = ['absconded', 'deserted', 'dismissed', 'dismisses', 'terminated'];
                if (in_array($employeeState, $disqualifiedStates, true)) {
                    $summary['skipped_exact_rows']++;
                    $reportRows[] = ['row_number'=>$rowNumber, 'key_value'=>(string)($values['employeeNo'] ?? ''), 'status'=>'skipped', 'message'=>'Employee state is not benefit-eligible: ' . $employeeState, 'merged_fields'=>[], 'conflict_fields'=>[]];
                    continue;
                }
            }

            if (!empty($dataset['composite_key_fields'])) {
                $keyValue = [];
                foreach ($dataset['composite_key_fields'] as $field) {
                    $keyValue[$field] = trim((string)($values[$field] ?? ''));
                }
                $displayKey = implode(' | ', array_values(array_filter($keyValue, static fn($item) => $item !== '')));
                foreach ($keyValue as $part) {
                    if ($part === '') {
                        $errors[] = $dataset['key_label'] . ' is required.';
                        break;
                    }
                }
            } else {
                $keyValue = trim((string)($values[$keyField] ?? ''));
                $displayKey = $keyValue;
                if ($keyValue === '') {
                    $errors[] = $dataset['key_label'] . ' is required.';
                }
            }

            if (!empty($errors)) {
                $summary['invalid_rows']++;
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => 'invalid',
                    'message' => implode(' ', $errors),
                    'merged_fields' => [],
                    'conflict_fields' => []
                ];
                $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                    'Source Row' => $rowNumber,
                    'Review Status' => 'Invalid',
                    'Review Reason' => implode(' ', $errors),
                    'Matched Key' => $displayKey
                ]);
                continue;
            }

            $existing = importFetchExistingRow($conn, $dataset, $keyValue);
            if ($datasetKey === 'file_registry') {
                $deathContactErrors = importValidateFileRegistryDeathContacts($values, $existing);
                if (!empty($deathContactErrors)) {
                    $summary['invalid_rows']++;
                    $reportRows[] = [
                        'row_number' => $rowNumber,
                        'key_value' => $displayKey,
                        'status' => 'invalid',
                        'message' => implode(' ', $deathContactErrors),
                        'merged_fields' => [],
                        'conflict_fields' => []
                    ];
                    $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                        'Source Row' => $rowNumber,
                        'Review Status' => 'Invalid',
                        'Review Reason' => implode(' ', $deathContactErrors),
                        'Matched Key' => $displayKey
                    ]);
                    continue;
                }
            }
            if ($existing === null) {
                if (!importInsertRow($conn, $dataset, $values)) {
                    $summary['failed_rows']++;
                    $reportRows[] = [
                        'row_number' => $rowNumber,
                        'key_value' => $displayKey,
                        'status' => 'failed',
                        'message' => 'Unable to insert record.',
                        'merged_fields' => [],
                        'conflict_fields' => []
                    ];
                    $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                        'Source Row' => $rowNumber,
                        'Review Status' => 'Failed',
                        'Review Reason' => 'Unable to insert record.',
                        'Matched Key' => $displayKey
                    ]);
                    continue;
                }

                $summary['inserted_rows']++;
                if ($datasetKey === 'file_registry') {
                    $registryRegNo = trim((string)($values['regNo'] ?? ''));
                    if ($registryRegNo !== '') {
                        $registryRegNosToSync[] = $registryRegNo;
                    }
                }
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => 'inserted',
                    'message' => 'New record staged for import.',
                    'merged_fields' => [],
                    'conflict_fields' => []
                ];
                continue;
            }

            $mergedFields = [];
            $conflictFields = [];
            $matchingFields = 0;
            $populatedFields = 0;

            foreach ($dataset['columns'] as $column) {
                $field = $column['field'];
                if (!array_key_exists($field, $values)) {
                    continue;
                }
                if ($dataset['key'] === 'users' && $field === 'userPassword') {
                    continue;
                }
                $incoming = $values[$field];
                if ($incoming === null || $incoming === '') {
                    continue;
                }
                $populatedFields++;
                $existingNormalized = importNormalizeExistingValue($field, $existing[$field] ?? null);
                $incomingNormalized = importNormalizeExistingValue($field, $incoming);
                if ($existingNormalized === $incomingNormalized) {
                    $matchingFields++;
                }
            }

            if ($populatedFields > 0 && $matchingFields === $populatedFields) {
                $summary['skipped_exact_rows']++;
                if ($datasetKey === 'file_registry') {
                    $registryRegNo = trim((string)($values['regNo'] ?? ''));
                    if ($registryRegNo !== '') {
                        $registryRegNosToSync[] = $registryRegNo;
                    }
                }
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => 'skipped_exact',
                    'message' => 'Existing record already matches the imported data.',
                    'merged_fields' => [],
                    'conflict_fields' => []
                ];
                continue;
            }

            $mergedOk = importMergeExistingRow($conn, $dataset, $existing, $values, $mergedFields, $conflictFields);
            if (!$mergedOk) {
                $summary['failed_rows']++;
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => 'failed',
                    'message' => 'Unable to merge existing record.',
                    'merged_fields' => [],
                    'conflict_fields' => []
                ];
                $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                    'Source Row' => $rowNumber,
                    'Review Status' => 'Failed',
                    'Review Reason' => 'Unable to merge existing record.',
                    'Matched Key' => $displayKey
                ]);
                continue;
            }

            if (!empty($mergedFields)) {
                $summary['merged_rows']++;
                if ($datasetKey === 'file_registry') {
                    $registryRegNo = trim((string)($values['regNo'] ?? ''));
                    if ($registryRegNo !== '') {
                        $registryRegNosToSync[] = $registryRegNo;
                    }
                }
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => empty($conflictFields) ? 'merged' : 'merged_with_conflict',
                    'message' => empty($conflictFields)
                        ? 'Existing record will be enriched using blank-field merge.'
                        : 'Blank fields will be merged, but conflicting populated fields need manual review.',
                    'merged_fields' => $mergedFields,
                    'conflict_fields' => $conflictFields
                ];
                if (!empty($conflictFields)) {
                    $summary['conflict_rows']++;
                    $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                        'Source Row' => $rowNumber,
                        'Review Status' => 'Conflict',
                        'Review Reason' => 'Blank fields were merged, but existing populated fields differ and need manual review.',
                        'Review Fields' => $conflictFields,
                        'Matched Key' => $displayKey
                    ]);
                }
                continue;
            }

            if (!empty($conflictFields)) {
                $summary['conflict_rows']++;
                if ($datasetKey === 'file_registry') {
                    $registryRegNo = trim((string)($values['regNo'] ?? ''));
                    if ($registryRegNo !== '') {
                        $registryRegNosToSync[] = $registryRegNo;
                    }
                }
                $reportRows[] = [
                    'row_number' => $rowNumber,
                    'key_value' => $displayKey,
                    'status' => 'conflict',
                    'message' => 'Existing populated fields differ from the imported row and require manual review.',
                    'merged_fields' => [],
                    'conflict_fields' => $conflictFields
                ];
                $reviewRows[] = buildImportReviewRowFromSource($inputHeaders, $row, [
                    'Source Row' => $rowNumber,
                    'Review Status' => 'Conflict',
                    'Review Reason' => 'Existing populated fields differ from the imported row and require manual review.',
                    'Review Fields' => $conflictFields,
                    'Matched Key' => $displayKey
                ]);
                continue;
            }

            $summary['skipped_exact_rows']++;
            $reportRows[] = [
                'row_number' => $rowNumber,
                'key_value' => $displayKey,
                'status' => 'skipped_exact',
                'message' => 'No new data was supplied for this record.',
                'merged_fields' => [],
                'conflict_fields' => []
            ];
        }

        if ($dryRun) {
            $conn->rollback();
        } else {
            if ($datasetKey === 'file_registry' && function_exists('upsertPensionerUserFromRegistry')) {
                foreach (array_values(array_unique($registryRegNosToSync)) as $registryRegNo) {
                    $syncResult = upsertPensionerUserFromRegistry(
                        $conn,
                        (string)$registryRegNo,
                        'Pensioner123',
                        $actorId
                    );
                    if (empty($syncResult['success'])) {
                        throw new RuntimeException($syncResult['message'] ?? ('Failed to synchronize pensioner account for ' . $registryRegNo));
                    }
                }
            }
            $conn->commit();
            if ($datasetKey === 'file_registry' && function_exists('maybeReconcileAllActivePayrollCycles')) {
                try {
                    maybeReconcileAllActivePayrollCycles($conn);
                } catch (Throwable $syncError) {
                    error_log('data import payroll reconciliation failed: ' . $syncError->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $status = resolveImportRunStatus($summary);

    $report = [
        'dataset_key' => $dataset['key'],
        'dataset_label' => $dataset['label'],
        'execution_mode' => $mode,
        'summary' => $summary,
        'rows' => $reportRows,
        'review_row_count' => count($reviewRows),
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $actorName !== '' ? $actorName : 'System'
    ];

    $reviewExport = buildImportReviewExportPayload(
        $dataset['key'] . '_' . $mode,
        $reviewRows,
        array_merge(['Source Row', 'Review Status', 'Review Reason', 'Review Fields', 'Matched Key'], $inputHeaders)
    );

    importPersistRun($conn, $dataset, $fileName, $extension, $mode, $status, $summary, $report, $actorId, $actorName, $actorRole);

    logAuditEvent($conn, [
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'actor_role' => $actorRole,
        'action' => $dryRun ? 'data_import_preview' : 'data_import_executed',
        'entity_type' => 'data_import',
        'entity_id' => $dataset['key'],
        'details' => [
            'dataset' => $dataset['label'],
            'mode' => $mode,
            'file' => $fileName,
            'summary' => $summary
        ]
    ]);

    return [
        'status' => $status,
        'summary' => $summary,
        'report' => $report,
        'review_export' => $reviewExport,
        'message' => buildImportCompletionMessage($dataset, $mode, $status, $summary)
    ];
}
