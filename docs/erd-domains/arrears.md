# Arrears ERD

Generated from `database/schema.sql` on 2026-05-28.

Arrears ledgers, payments, allocations, accountability, and gratuity-schedule analysis.

- Tables: 10
- Relationships shown: 10

## Tables Covered

- `tb_arrearstracking`
- `tb_budgetforecast`
- `tb_arrears_ledger`
- `tb_arrears_payments`
- `tb_arrears_payment_allocations`
- `tb_arrears_accountability_submissions`
- `tb_arrears_accountability_files`
- `tb_gratuity_schedule_cycles`
- `tb_gratuity_schedule_entries`
- `tb_gratuity_schedule_allocations`

## Mermaid ERD

```mermaid
erDiagram
  tb_arrearstracking {
    int id PK
    string regNo
    string arrearsType
    decimal amount
    date periodStart
    date periodEnd
    timestamp recordedAt
    string recordedBy FK
  }
  tb_arrears_accountability_files {
    int file_id PK
    int submission_id FK
    string file_name
    string file_path
    string mime_type
    int file_size
    timestamp created_at
  }
  tb_arrears_accountability_submissions {
    int submission_id PK
    string regNo FK
    string claim_type
    int payment_id FK
    string status
    text notes
    string submitted_by FK
    timestamp submitted_at
  }
  tb_arrears_ledger {
    int ledger_id PK
    string regNo FK
    string claim_type
    int period_year
    int period_month
    string financial_year_label
    string quarter_label
    decimal expected_amount
    decimal paid_amount
    decimal balance_amount
    string status
    string source_type
    int reference_cycle_id
    string reason
    text notes
    string recorded_by FK
    timestamp recorded_at
    timestamp updated_at
    datetime settled_at
    bool accountability_required
    string accountability_status
    string claim_status
  }
  tb_arrears_payments {
    int payment_id PK
    string regNo FK
    string claim_type
    decimal amount
    decimal applied_amount
    decimal unapplied_amount
    date payment_date
    string reference_no
    text notes
    string recorded_by FK
    timestamp created_at
    string payment_financial_year_label
    bool accountability_required
    string accountability_status
    datetime accountability_submitted_at
    int latest_submission_id FK
  }
  tb_arrears_payment_allocations {
    int allocation_id PK
    int payment_id FK
    int ledger_id FK
    string regNo
    string claim_type
    decimal applied_amount
    string accrual_financial_year_label
    string payment_financial_year_label
    bool requires_accountability
    string accountability_status
    int accountability_submission_id FK
    datetime accountability_submitted_at
    timestamp created_at
  }
  tb_budgetforecast {
    int id PK
    int financialYear
    decimal estimatedPensionAmount
    decimal estimatedGratuityAmount
    string createdBy FK
    timestamp createdAt
    decimal estimatedPensionArrears
    decimal estimatedFullPensionArrears
    decimal estimatedGratuityArrears
    decimal estimatedUnderpaymentClaims
    decimal estimatedSuspensionArrears
    text notes
  }
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
  tb_arrears_accountability_submissions ||--o{ tb_arrears_accountability_files : submission_id
  tb_arrears_accountability_submissions ||--o{ tb_arrears_payments : latest_submission_id
  tb_arrears_accountability_submissions ||--o{ tb_arrears_payment_allocations : accountability_submission_id
  tb_arrears_ledger ||--o{ tb_arrears_payment_allocations : ledger_id
  tb_arrears_ledger ||--o{ tb_gratuity_schedule_allocations : ledger_id
  tb_arrears_payments ||--o{ tb_arrears_accountability_submissions : payment_id
  tb_arrears_payments ||--o{ tb_arrears_payment_allocations : payment_id
  tb_gratuity_schedule_cycles ||--o{ tb_gratuity_schedule_allocations : cycle_id
  tb_gratuity_schedule_cycles ||--o{ tb_gratuity_schedule_entries : cycle_id
  tb_gratuity_schedule_entries ||--o{ tb_gratuity_schedule_allocations : entry_id
```
