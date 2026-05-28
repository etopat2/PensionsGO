# Registry ERD

Generated from `database/schema.sql` on 2026-05-28.

Staff due intake, canonical pension registry, documents, file custody, and life-certificate records.

- Tables: 10
- Relationships shown: 8

## Tables Covered

- `tb_staffdue`
- `tb_staff_documents`
- `tb_staff_due_delete_requests`
- `tb_fileregistry`
- `tb_file_movements`
- `tb_file_registry_delete_requests`
- `tb_file_registry_recycle_bin`
- `tb_lifecertificates`
- `tb_life_certificate_submissions`
- `tb_pensioner_death_reports`

## Mermaid ERD

```mermaid
erDiagram
  tb_fileregistry {
    int id PK
    string computerNo
    string supplierNo
    string regNo
    string title
    string sName
    string fName
    string gender
    string livingStatus
    string lifeCertificate
    string boxNo
    date birthDate
    date enlistmentDate
    date retirementDate
    string retirementType
    string TIN
    string NIN
    text address
    string payrollStatus
    string payType
    date dateOn15yrs
    string periodTo15yrs
    string periodFrom15yrs
    date dateOfDeath
    date deathNotificationDate
    string deathNotifierName
    string deathNotifierContact
    date estateExpiryDate
    string estateStatus
    timestamp timeStamp
    text other
    string availability_status
    text availability_reason
    string telNo
    string applicant_email
    string next_of_kin
    string next_of_kin_contact
    string bank_name
    string bank_account
    string bank_branch
    bool lookup_contact_opt_in
    datetime lookup_contact_updated_at
    decimal monthlySalary
    int lengthOfService
    decimal annualSalary
    decimal reducedPension
    decimal fullPension
    decimal gratuity
    bool is_deleted
    datetime deleted_at
    string deleted_by
    string deleted_by_name
    string deleted_by_role
    text delete_reason
    bool workflow_auto_arrears_enabled
    datetime workflow_auto_arrears_enabled_at
    string workflow_auto_arrears_source
  }
  tb_file_movements {
    int movement_id PK
    string regNo
    int file_id FK
    string from_office
    string to_office
    text reason
    string delivered_by
    string received_by
    datetime moved_at
    datetime expected_return_at
    datetime returned_at
  }
  tb_file_registry_delete_requests {
    int request_id PK
    int registry_id FK
    string regNo
    string requested_by FK
    string requested_by_name
    string requested_by_role
    text reason
    string status
    string processed_by FK
    string processed_by_name
    string processed_by_role
    text processed_note
    timestamp created_at
    datetime processed_at
    string staff_name
    string staff_title
  }
  tb_file_registry_recycle_bin {
    int recycle_id PK
    int registry_id FK
    string regNo
    string staff_name
    string staff_title
    int delete_request_id FK
    text delete_reason
    string deleted_by FK
    string deleted_by_name
    string deleted_by_role
    timestamp deleted_at
    text record_snapshot
    bool restored
    string restored_by FK
    string restored_by_name
    string restored_by_role
    datetime restored_at
  }
  tb_lifecertificates {
    int id PK
    string computerNo
    string regNo
    string sName
    string fName
    string nextOfKin
    string nokContact
    timestamp timeStamp
  }
  tb_life_certificate_submissions {
    int submission_id PK
    string regNo FK
    int submission_year
    string status
    datetime submitted_at
    string submitted_by FK
    text notes
    timestamp created_at
    timestamp updated_at
  }
  tb_pensioner_death_reports {
    int report_id PK
    int registry_id FK
    string regNo
    date date_of_death
    string notifier_name
    string notifier_contact
    date notification_date
    text notes
    string recorded_by
    string recorded_by_name
    string recorded_by_role
    timestamp created_at
    timestamp updated_at
  }
  tb_staffdue {
    int id PK
    string regNo
    string computerNo
    string title
    string sName
    string fName
    string gender
    string prisonUnit
    text NIN
    string telNo
    date birthDate
    date enlistmentDate
    date retirementDate
    text financialYear
    string retirementType
    decimal monthlySalary
    int lengthOfService
    decimal annualSalary
    decimal reducedPension
    decimal fullPension
    decimal gratuity
    string submissionStatus
    string appnStatus
    datetime submission_at
    string submission_by
    datetime appn_status_at
    string appn_status_by
    text appn_status_reason
    text address
    string TIN
    string next_of_kin
    string next_of_kin_contact
    string bank_name
    string bank_account
    string bank_branch
    string applicant_email
    bool documents_uploaded
    string livingStatus
    string payType
    bool is_deleted
    datetime deleted_at
    string deleted_by
    string deleted_by_name
    string deleted_by_role
    text delete_reason
    timestamp timeStamp
  }
  tb_staff_documents {
    int document_id PK
    int staffdue_id FK
    string regNo
    string doc_type
    string file_name
    string file_path
    int file_size
    string mime_type
    string uploaded_by FK
    timestamp uploaded_at
    string file_hash
    bool is_archived
    datetime archived_at
  }
  tb_staff_due_delete_requests {
    int request_id PK
    int staffdue_id FK
    string regNo
    string staff_name
    string staff_title
    string requested_by FK
    string requested_by_name
    string requested_by_role
    text reason
    string status
    string processed_by FK
    string processed_by_name
    string processed_by_role
    text processed_note
    timestamp created_at
    datetime processed_at
  }
  tb_fileregistry ||--o{ tb_file_movements : file_id
  tb_fileregistry ||--o{ tb_file_registry_delete_requests : registry_id
  tb_fileregistry ||--o{ tb_file_registry_recycle_bin : registry_id
  tb_fileregistry ||--o{ tb_life_certificate_submissions : regNo
  tb_fileregistry ||--o{ tb_pensioner_death_reports : registry_id
  tb_file_registry_delete_requests ||--o{ tb_file_registry_recycle_bin : delete_request_id
  tb_staffdue ||--o{ tb_staff_documents : staffdue_id
  tb_staffdue ||--o{ tb_staff_due_delete_requests : staffdue_id
```
