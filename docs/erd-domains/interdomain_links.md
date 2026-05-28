# Inter-Domain ERD

Generated from `database/schema.sql`. This map shows how the grouped domains connect through foreign-key paths and shared bridge tables.

```mermaid
flowchart TB
  classDef domain fill:#f8fafc,stroke:#334155,stroke-width:1.5px,color:#0f172a;
  classDef connector fill:#fff7ed,stroke:#c2410c,stroke-width:2px,color:#7c2d12;
  WRK["Workflow<br/>8 tables<br/>application_queue<br/>appnstatus<br/>tasks<br/>task_alerts<br/>+4 more"]
  REG["Registry<br/>10 tables<br/>staffdue<br/>staff_documents<br/>staff_due_delete_requests<br/>fileregistry<br/>+6 more"]
  CLM["Claims<br/>2 tables<br/>appnsubmissions<br/>claimstatus"]
  PAY["Payroll<br/>12 tables<br/>payrolls<br/>payroll_pension<br/>payroll_gratuity<br/>payroll_arrears<br/>+8 more"]
  ARR["Arrears<br/>10 tables<br/>arrearstracking<br/>budgetforecast<br/>arrears_ledger<br/>arrears_payments<br/>+6 more"]
  MSG["Messaging<br/>8 tables<br/>messages<br/>message_attachments<br/>message_recipients<br/>message_storage_snapshots<br/>+4 more"]
  FBK["Feedback<br/>2 tables<br/>feedback_submissions<br/>feedback_activity"]
  UAC["Users & Access<br/>9 tables<br/>users<br/>roles<br/>role_permissions<br/>user_permissions<br/>+5 more"]
  OPS["Analytics & Ops<br/>11 tables<br/>analytics_digest_runs<br/>analytics_snapshots<br/>audit_logs<br/>backup_logs<br/>+7 more"]
  CNT["Content & Reference<br/>10 tables<br/>titles<br/>faq_entries<br/>terms_clauses<br/>poldistricts<br/>+6 more"]
  WRK ---|"2 FK paths<br/>application_queue<br/>appnstatus"| REG
  WRK ---|"5 FK paths<br/>tasks<br/>application_queue<br/>task_completion_queue"| UAC
  REG ---|"1 FK path<br/>appnsubmissions"| CLM
  REG ---|"1 FK path<br/>payroll_upload_entries"| PAY
  REG ---|"4 FK paths<br/>gratuity_schedule_entries<br/>arrears_accountability_submissions<br/>arrears_ledger<br/>+1 more"| ARR
  REG ---|"8 FK paths<br/>staff_documents<br/>staff_due_delete_requests<br/>file_registry_delete_requests<br/>+2 more"| UAC
  PAY ---|"6 FK paths<br/>payrolls<br/>retained_payments<br/>payroll_upload_cycles<br/>+2 more"| UAC
  ARR ---|"6 FK paths<br/>arrearstracking<br/>budgetforecast<br/>gratuity_schedule_cycles<br/>+3 more"| UAC
  MSG ---|"3 FK paths<br/>messages<br/>message_recipients<br/>user_broadcast_status"| UAC
  FBK ---|"6 FK paths<br/>feedback_activity<br/>feedback_submissions"| UAC
  UAC ---|"2 FK paths<br/>data_export_runs<br/>data_import_runs"| OPS
  UAC ---|"1 FK path<br/>podcast_views"| CNT
  class WRK,REG,CLM,PAY,ARR,MSG,FBK,UAC,OPS,CNT domain;
```

## Domain Coverage

| Code | Domain | Tables |
| --- | --- | ---: |
| `WRK` | Workflow | 8 |
| `REG` | Registry | 10 |
| `CLM` | Claims | 2 |
| `PAY` | Payroll | 12 |
| `ARR` | Arrears | 10 |
| `MSG` | Messaging | 8 |
| `FBK` | Feedback | 2 |
| `UAC` | Users & Access | 9 |
| `OPS` | Analytics & Ops | 11 |
| `CNT` | Content & Reference | 10 |

## Connector Details

| Domain Pair | Connector Tables | Foreign-Key Paths |
| --- | --- | --- |
| Workflow <-> Registry | `tb_application_queue`, `tb_appnstatus` | `tb_application_queue.staffdue_id` -> `tb_staffdue.id`<br/>`tb_appnstatus.regNo` -> `tb_staffdue.regNo` |
| Workflow <-> Users & Access | `tb_tasks`, `tb_application_queue`, `tb_task_completion_queue` | `tb_tasks.createdBy` -> `tb_users.userId`<br/>`tb_tasks.sentTo` -> `tb_users.userId`<br/>`tb_application_queue.verified_by` -> `tb_users.userId`<br/>`tb_application_queue.submitted_by` -> `tb_users.userId`<br/>`tb_task_completion_queue.owner_user_id` -> `tb_users.userId` |
| Registry <-> Claims | `tb_appnsubmissions` | `tb_appnsubmissions.regNo` -> `tb_staffdue.regNo` |
| Registry <-> Payroll | `tb_payroll_upload_entries` | `tb_payroll_upload_entries.matched_registry_id` -> `tb_fileregistry.id` |
| Registry <-> Arrears | `tb_gratuity_schedule_entries`, `tb_arrears_accountability_submissions`, `tb_arrears_ledger`, `tb_arrears_payments` | `tb_gratuity_schedule_entries.matched_registry_id` -> `tb_fileregistry.id`<br/>`tb_arrears_accountability_submissions.regNo` -> `tb_fileregistry.regNo`<br/>`tb_arrears_ledger.regNo` -> `tb_fileregistry.regNo`<br/>`tb_arrears_payments.regNo` -> `tb_fileregistry.regNo` |
| Registry <-> Users & Access | `tb_staff_documents`, `tb_staff_due_delete_requests`, `tb_file_registry_delete_requests`, `tb_file_registry_recycle_bin`, `tb_life_certificate_submissions` | `tb_staff_documents.uploaded_by` -> `tb_users.userId`<br/>`tb_staff_due_delete_requests.requested_by` -> `tb_users.userId`<br/>`tb_staff_due_delete_requests.processed_by` -> `tb_users.userId`<br/>`tb_file_registry_delete_requests.requested_by` -> `tb_users.userId`<br/>`tb_file_registry_delete_requests.processed_by` -> `tb_users.userId`<br/>`tb_file_registry_recycle_bin.deleted_by` -> `tb_users.userId`<br/>`tb_file_registry_recycle_bin.restored_by` -> `tb_users.userId`<br/>`tb_life_certificate_submissions.submitted_by` -> `tb_users.userId` |
| Payroll <-> Users & Access | `tb_payrolls`, `tb_retained_payments`, `tb_payroll_upload_cycles`, `tb_payroll_audit_logs`, `tb_suspension_upload_cycles` | `tb_payrolls.uploaded_by` -> `tb_users.userId`<br/>`tb_retained_payments.recorded_by` -> `tb_users.userId`<br/>`tb_payroll_upload_cycles.uploaded_by` -> `tb_users.userId`<br/>`tb_payroll_upload_cycles.deleted_by` -> `tb_users.userId`<br/>`tb_payroll_audit_logs.actor_user_id` -> `tb_users.userId`<br/>`tb_suspension_upload_cycles.uploaded_by` -> `tb_users.userId` |
| Arrears <-> Users & Access | `tb_arrearstracking`, `tb_budgetforecast`, `tb_gratuity_schedule_cycles`, `tb_arrears_accountability_submissions`, `tb_arrears_ledger`, `tb_arrears_payments` | `tb_arrearstracking.recordedBy` -> `tb_users.userId`<br/>`tb_budgetforecast.createdBy` -> `tb_users.userId`<br/>`tb_gratuity_schedule_cycles.uploaded_by` -> `tb_users.userId`<br/>`tb_arrears_accountability_submissions.submitted_by` -> `tb_users.userId`<br/>`tb_arrears_ledger.recorded_by` -> `tb_users.userId`<br/>`tb_arrears_payments.recorded_by` -> `tb_users.userId` |
| Messaging <-> Users & Access | `tb_messages`, `tb_message_recipients`, `tb_user_broadcast_status` | `tb_messages.sender_id` -> `tb_users.userId`<br/>`tb_message_recipients.recipient_user_id` -> `tb_users.userId`<br/>`tb_user_broadcast_status.user_id` -> `tb_users.userId` |
| Feedback <-> Users & Access | `tb_feedback_activity`, `tb_feedback_submissions` | `tb_feedback_activity.actor_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.submitted_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.assigned_to_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.reviewed_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.resolved_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.closed_by_user_id` -> `tb_users.userId` |
| Users & Access <-> Analytics & Ops | `tb_data_export_runs`, `tb_data_import_runs` | `tb_data_export_runs.created_by` -> `tb_users.userId`<br/>`tb_data_import_runs.created_by` -> `tb_users.userId` |
| Users & Access <-> Content & Reference | `tb_podcast_views` | `tb_podcast_views.viewer_id` -> `tb_users.userId` |
