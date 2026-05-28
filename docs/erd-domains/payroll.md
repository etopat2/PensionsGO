# Payroll ERD

Generated from `database/schema.sql` on 2026-05-28.

Payroll uploads, cycle reconciliation, suspension loads, and monthly registry status.

- Tables: 15
- Relationships shown: 7

## Tables Covered

- `tb_payrolls`
- `tb_payroll_pension`
- `tb_payroll_gratuity`
- `tb_payroll_arrears`
- `tb_payroll_suspended`
- `tb_payroll_upload_cycles`
- `tb_payroll_upload_entries`
- `tb_payroll_audit_logs`
- `tb_registry_payroll_monthly_status`
- `tb_suspension_upload_cycles`
- `tb_suspension_upload_entries`
- `tb_retained_payments`
- `tb_gratuity_schedule_cycles`
- `tb_gratuity_schedule_entries`
- `tb_gratuity_schedule_allocations`

## Mermaid ERD

```mermaid
erDiagram
  tb_gratuity_schedule_allocations {
    int allocation_id PK
    int cycle_id FK
    int entry_id FK
    string matched_regNo
    int ledger_id FK
    int period_year
    int period_month
    string claim_type
    decimal allocated_amount
    decimal monthly_pension_amount
    string allocation_status
    string note
    timestamp created_at
  }
  tb_gratuity_schedule_cycles {
    int cycle_id PK
    int schedule_year
    int schedule_month
    string financial_year_label
    string quarter_label
    string uploaded_by FK
    string source_file
    string source_file_original_name
    string source_file_mime
    text notes
    int total_rows
    int matched_rows
    int unmatched_rows
    int exact_gratuity_rows
    int partial_gratuity_rows
    int small_surplus_rows
    int pension_arrears_rows
    int review_rows
    decimal total_scheduled_amount
    decimal total_gratuity_component
    decimal total_small_surplus_amount
    decimal total_pension_surplus_amount
    decimal total_allocated_pension_amount
    decimal total_unallocated_amount
    decimal total_remaining_arrears_amount
    timestamp created_at
    bool is_deleted
  }
  tb_gratuity_schedule_entries {
    int entry_id PK
    int cycle_id FK
    int row_number
    string regNo
    string supplierNo
    string beneficiary_name
    decimal scheduled_amount
    string matched_regNo
    int matched_registry_id FK
    string matched_name
    decimal registry_gratuity_estimate
    decimal latest_monthly_pension
    string monthly_pension_source
    decimal open_pension_arrears_amount
    int open_pension_arrears_months
    decimal gratuity_component_amount
    decimal pension_surplus_amount
    decimal small_surplus_amount
    decimal allocated_pension_amount
    int scheduled_full_months
    int allocated_months
    int unallocated_scheduled_months
    decimal unallocated_scheduled_amount
    int remaining_arrears_months
    decimal remaining_arrears_amount
    string classification
    string matching_basis
    string analysis_note
    text raw_payload
    bool is_matched
    timestamp created_at
  }
  tb_payrolls {
    int id PK
    int payrollYear
    int payrollMonth
    string record_type
    text file_path
    string uploaded_by FK
    timestamp uploaded_at
  }
  tb_payroll_arrears {
    int id PK
    int payrollYear
    int payrollMonth
    string supplierNo
    decimal amount
    string status
  }
  tb_payroll_audit_logs {
    int audit_id PK
    int cycle_id FK
    string action
    string actor_user_id FK
    string actor_role
    string ip_address
    text details
    timestamp created_at
  }
  tb_payroll_gratuity {
    int id PK
    int payrollYear
    int payrollMonth
    string supplierNo
    decimal amount
    string status
  }
  tb_payroll_pension {
    int id PK
    int payrollYear
    int payrollMonth
    string supplierNo
    decimal amount
    string status
  }
  tb_payroll_suspended {
    int id PK
    int payrollYear
    int payrollMonth
    string supplierNo
    decimal amount
    string status
  }
  tb_payroll_upload_cycles {
    int cycle_id PK
    int payroll_year
    int payroll_month
    string financial_year_label
    string quarter_label
    string uploaded_by FK
    string source_file
    text notes
    timestamp created_at
    string source_file_original_name
    string source_file_mime
    string payment_register_file
    string payment_register_original_name
    string payment_register_mime
    bool is_deleted
    string deleted_by FK
    datetime deleted_at
  }
  tb_payroll_upload_entries {
    int entry_id PK
    int cycle_id FK
    string supplierNo
    string beneficiary_name
    decimal amount
    string matched_regNo
    int matched_registry_id FK
    bool is_matched
    timestamp created_at
  }
  tb_registry_payroll_monthly_status {
    int status_id PK
    string regNo
    int payroll_year
    int payroll_month
    string financial_year_label
    string quarter_label
    string payroll_status
    decimal amount
    string supplierNo
    int cycle_id FK
    timestamp updated_at
  }
  tb_retained_payments {
    int id PK
    string supplierNo
    date month
    decimal retainedAmount
    string recorded_by FK
    timestamp recorded_at
  }
  tb_suspension_upload_cycles {
    int suspension_cycle_id PK
    int suspension_year
    int suspension_month
    string financial_year_label
    string quarter_label
    string uploaded_by FK
    string source_file
    string source_file_original_name
    string source_file_mime
    text notes
    bool is_deleted
    timestamp created_at
    string reason_label
  }
  tb_suspension_upload_entries {
    int entry_id PK
    int suspension_cycle_id FK
    string regNo
    string supplierNo
    string beneficiary_name
    decimal amount
    string reason
    string matched_regNo
    int matched_registry_id
    bool is_matched
    timestamp created_at
  }
  tb_gratuity_schedule_cycles ||--o{ tb_gratuity_schedule_allocations : cycle_id
  tb_gratuity_schedule_cycles ||--o{ tb_gratuity_schedule_entries : cycle_id
  tb_gratuity_schedule_entries ||--o{ tb_gratuity_schedule_allocations : entry_id
  tb_payroll_upload_cycles ||--o{ tb_payroll_audit_logs : cycle_id
  tb_payroll_upload_cycles ||--o{ tb_payroll_upload_entries : cycle_id
  tb_payroll_upload_cycles ||--o{ tb_registry_payroll_monthly_status : cycle_id
  tb_suspension_upload_cycles ||--o{ tb_suspension_upload_entries : suspension_cycle_id
```
