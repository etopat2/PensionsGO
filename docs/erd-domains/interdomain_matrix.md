# Inter-Domain Connector Matrix

Generated from `database/schema.sql`. Each cell lists the main source tables whose foreign keys connect one domain to another.

## Domain Codes

| Code | Domain |
| --- | --- |
| `WRK` | Workflow |
| `REG` | Registry |
| `CLM` | Claims |
| `PAY` | Payroll |
| `ARR` | Arrears |
| `MSG` | Messaging |
| `FBK` | Feedback |
| `UAC` | Users & Access |
| `OPS` | Analytics & Ops |
| `CNT` | Content & Reference |

## Matrix

| From \ To | `WRK` | `REG` | `CLM` | `PAY` | `ARR` | `MSG` | `FBK` | `UAC` | `OPS` | `CNT` |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `WRK` | -- | `application_queue`, `appnstatus` | . | . | . | . | . | `tasks`, `application_queue`, `+1` | . | . |
| `REG` | `application_queue`, `appnstatus` | -- | `appnsubmissions` | `payroll_upload_entries` | `gratuity_schedule_entries`, `arrears_accountability_submissions`, `+2` | . | . | `staff_documents`, `staff_due_delete_requests`, `+3` | . | . |
| `CLM` | . | `appnsubmissions` | -- | . | . | . | . | . | . | . |
| `PAY` | . | `payroll_upload_entries` | . | -- | . | . | . | `payrolls`, `retained_payments`, `+3` | . | . |
| `ARR` | . | `gratuity_schedule_entries`, `arrears_accountability_submissions`, `+2` | . | . | -- | . | . | `arrearstracking`, `budgetforecast`, `+4` | . | . |
| `MSG` | . | . | . | . | . | -- | . | `messages`, `message_recipients`, `+1` | . | . |
| `FBK` | . | . | . | . | . | . | -- | `feedback_activity`, `feedback_submissions` | . | . |
| `UAC` | `tasks`, `application_queue`, `+1` | `staff_documents`, `staff_due_delete_requests`, `+3` | . | `payrolls`, `retained_payments`, `+3` | `arrearstracking`, `budgetforecast`, `+4` | `messages`, `message_recipients`, `+1` | `feedback_activity`, `feedback_submissions` | -- | `data_export_runs`, `data_import_runs` | `podcast_views` |
| `OPS` | . | . | . | . | . | . | . | `data_export_runs`, `data_import_runs` | -- | . |
| `CNT` | . | . | . | . | . | . | . | `podcast_views` | . | -- |

## Detailed Connector Paths

| Domain Pair | Foreign-Key Paths |
| --- | --- |
| Workflow <-> Registry | `tb_application_queue.staffdue_id` -> `tb_staffdue.id`<br/>`tb_appnstatus.regNo` -> `tb_staffdue.regNo` |
| Workflow <-> Users & Access | `tb_tasks.createdBy` -> `tb_users.userId`<br/>`tb_tasks.sentTo` -> `tb_users.userId`<br/>`tb_application_queue.verified_by` -> `tb_users.userId`<br/>`tb_application_queue.submitted_by` -> `tb_users.userId`<br/>`tb_task_completion_queue.owner_user_id` -> `tb_users.userId` |
| Registry <-> Claims | `tb_appnsubmissions.regNo` -> `tb_staffdue.regNo` |
| Registry <-> Payroll | `tb_payroll_upload_entries.matched_registry_id` -> `tb_fileregistry.id` |
| Registry <-> Arrears | `tb_gratuity_schedule_entries.matched_registry_id` -> `tb_fileregistry.id`<br/>`tb_arrears_accountability_submissions.regNo` -> `tb_fileregistry.regNo`<br/>`tb_arrears_ledger.regNo` -> `tb_fileregistry.regNo`<br/>`tb_arrears_payments.regNo` -> `tb_fileregistry.regNo` |
| Registry <-> Users & Access | `tb_staff_documents.uploaded_by` -> `tb_users.userId`<br/>`tb_staff_due_delete_requests.requested_by` -> `tb_users.userId`<br/>`tb_staff_due_delete_requests.processed_by` -> `tb_users.userId`<br/>`tb_file_registry_delete_requests.requested_by` -> `tb_users.userId`<br/>`tb_file_registry_delete_requests.processed_by` -> `tb_users.userId`<br/>`tb_file_registry_recycle_bin.deleted_by` -> `tb_users.userId`<br/>`tb_file_registry_recycle_bin.restored_by` -> `tb_users.userId`<br/>`tb_life_certificate_submissions.submitted_by` -> `tb_users.userId` |
| Payroll <-> Users & Access | `tb_payrolls.uploaded_by` -> `tb_users.userId`<br/>`tb_retained_payments.recorded_by` -> `tb_users.userId`<br/>`tb_payroll_upload_cycles.uploaded_by` -> `tb_users.userId`<br/>`tb_payroll_upload_cycles.deleted_by` -> `tb_users.userId`<br/>`tb_payroll_audit_logs.actor_user_id` -> `tb_users.userId`<br/>`tb_suspension_upload_cycles.uploaded_by` -> `tb_users.userId` |
| Arrears <-> Users & Access | `tb_arrearstracking.recordedBy` -> `tb_users.userId`<br/>`tb_budgetforecast.createdBy` -> `tb_users.userId`<br/>`tb_gratuity_schedule_cycles.uploaded_by` -> `tb_users.userId`<br/>`tb_arrears_accountability_submissions.submitted_by` -> `tb_users.userId`<br/>`tb_arrears_ledger.recorded_by` -> `tb_users.userId`<br/>`tb_arrears_payments.recorded_by` -> `tb_users.userId` |
| Messaging <-> Users & Access | `tb_messages.sender_id` -> `tb_users.userId`<br/>`tb_message_recipients.recipient_user_id` -> `tb_users.userId`<br/>`tb_user_broadcast_status.user_id` -> `tb_users.userId` |
| Feedback <-> Users & Access | `tb_feedback_activity.actor_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.submitted_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.assigned_to_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.reviewed_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.resolved_by_user_id` -> `tb_users.userId`<br/>`tb_feedback_submissions.closed_by_user_id` -> `tb_users.userId` |
| Users & Access <-> Analytics & Ops | `tb_data_export_runs.created_by` -> `tb_users.userId`<br/>`tb_data_import_runs.created_by` -> `tb_users.userId` |
| Users & Access <-> Content & Reference | `tb_podcast_views.viewer_id` -> `tb_users.userId` |
