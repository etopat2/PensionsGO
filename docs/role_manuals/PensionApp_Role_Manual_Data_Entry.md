# UPS PensionsGo Role Manual: Data Entrant

**System:** UPS PensionsGo  
**Role Key:** `data_entry`  
**Role Label:** Data Entrant  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Data_Entry.docx`, `docs/role_manuals/PensionApp_Role_Manual_Data_Entry.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Data-entry officers, supervisors, trainers, and support teams |
| Current role purpose | Structured capture, bulk import, registry maintenance, claims posting, and controlled data-quality improvement. |
| Default landing page | My Tasks |
| Role type | Structured capture and reconciliation role |
| Current access note | This role combines form-based capture with governed import and claims-management capabilities. Accuracy and review discipline are therefore essential. |

# 1. Purpose and Role Position

The Data Entrant role is the main structured-capture role in the application. It can create and update staff-due records, maintain registry entries, run bulk imports, manage arrears entries, upload suspensions, and submit life-certificate updates.
Because this role has strong data-changing authority, the standard expected behavior is careful preparation, template discipline, review of import outputs, and immediate escalation of anomalies instead of working around them.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| My Tasks | Work assigned items, add comments, update task status, and progress workflow handoffs. |
| Staff Due for Retirement | Capture, review, filter, and progress staff-due records through governed intake and verification steps. |
| Add Staff | Create a new staff-due record with identity, service, and workflow information. |
| Edit Staff Record | Correct or complete a staff-due record where the current role has edit rights. |
| View Staff | Inspect full staff-due detail before routing, verification, or handoff. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Messages | Exchange controlled operational messages, updates, and clarifications with other users. |

## 2.2 Support and Reference Workspaces

| Menu / Workspace | Typical purpose |
| --- | --- |
| Benefits Calculator | Estimate service-based pension outputs using the configured retirement formulas. |
| Podcast | Watch guided pension information videos and official explanatory content. |
| Document Viewer | Open linked documents in a controlled preview workspace. |
| My Profile | Review personal account information, role label, and current account details. |
| Edit Profile | Maintain permitted personal account fields and credentials. |

## 2.3 Default Governed Capabilities

| Permission / Control | Capability | Operational meaning |
| --- | --- | --- |
| `staff_due.edit` | Create and update staff-due records | Capture and correct staff-due records through the supported forms and workflow tools. |
| `staff_due.bulk_upload` | Bulk upload staff-due records | Import approved staff-due schedules from template-driven CSV or XLSX files. |
| `staff_due.delete_request` | Request staff-due deletion | Submit a staff-due record for delete review with justification and audit trace. |
| `registry.edit` | Create and update registry records | Open the registry workspace in edit mode, maintain file data, and save governed changes. |
| `registry.bulk_upload` | Bulk upload registry files | Import approved pension registry schedules in bulk using the governed registry import flow. |
| `registry.life_certificate.submit` | Submit life certificates | Record life-certificate submissions and update the linked beneficiary contact profile. |
| `registry.delete_request` | Request registry deletion | Queue a registry record for governed deletion review instead of removing it directly. |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |
| `claims.arrears.manage` | Manage arrears ledger | Create arrears entries, post payments, submit accountability, and reconcile balances. |
| `claims.suspension.upload` | Upload suspension records | Import suspension schedules and preserve row-level suspension reasons during reconciliation. |

## 2.4 Work Normally Outside This Role

- Delete-queue processing remains supervisory or administrative.
- Budget maintenance and broader governance settings are not default data-entry work.
- Payroll cycle management and system configuration are outside this role.

# 3. Standard Daily Operating Procedure

1. Start from tasks or the relevant module and confirm the exact record or batch you are meant to process.
2. Use the correct governed form or template rather than free-form data manipulation.
3. Review validation or import-review output before finalizing a batch.
4. Post claims and suspension data only after confirming beneficiary identity, period, and source evidence.
5. Hand off exceptions promptly when supervisory or policy judgment is required.

# 4. Module Guidance

## 4.1 Structured Record Capture

Data entrants are expected to keep staff-due and registry records operationally clean through disciplined form use and evidence-backed editing.

### Standard Steps

1. Open the exact target record using search, filters, or a task handoff.
2. Complete governed fields carefully and avoid partial saves with uncertain data.
3. Use life-certificate tools when the case requires beneficiary compliance updates.
4. Request deletion through the queue when a record should be removed from normal use.
5. Recheck calculated or dependent fields after major edits so the next role sees a coherent record.

### Control Points

- Do not enter guessed dates, identifiers, or salary inputs.
- Life-certificate submissions must match real evidence.
- Delete requests are for exceptional cleanup, not convenience.

## 4.2 Bulk Import Operations

The live platform gives this role bulk-upload rights for staff-due and registry schedules. This is a high-impact capability and must be used with review discipline.

### Standard Steps

1. Download and use the current template rather than a locally altered spreadsheet.
2. Complete required source fields and keep headers unchanged.
3. Run the review or dry-run path first and inspect the generated import-review file.
4. Correct validation problems in the source file and rerun review until the output is acceptable.
5. Only then execute the final import and preserve the resulting evidence or review output.

### Control Points

- Blank or misaligned columns can damage record quality if not caught in review.
- Never bypass the review file when one is produced.
- Bulk upload is not a shortcut for unapproved source data.

## 4.3 Claims Ledger and Suspension Work

This role can both view and manage the claims workspace, including arrears posting and suspension imports.

### Standard Steps

1. Open the claims workspace and verify the correct beneficiary and claim type before creating or editing a record.
2. Use the appropriate payment, bulk-payment, gratuity-schedule, or suspension-upload flow rather than forcing one tool to do another task.
3. Confirm period, amount, accountability context, and supporting evidence before saving.
4. Review import-review output for suspension or bulk-payment files before accepting the final upload.
5. Escalate unexplained discrepancies or policy-sensitive cases to supervisory roles promptly.

### Control Points

- Financial period and beneficiary identity must be correct before every save.
- Accountability-related fields should reflect real supporting evidence.
- Claims management rights do not remove the need for supervisory escalation where policy or liability is unclear.

# 5. Governance and Control Rules

- Use the correct form or import template for every data-changing action.
- Review import and validation output before finalizing batch work.
- Keep claims, registry, and staff-due data aligned to real evidence.
- Escalate supervisory, queue, or policy decisions instead of improvising them.
- Preserve a clean audit trail through comments, statuses, and governed save actions.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Delete approval is required | Send the queue item to OC/Pension-equivalent supervision or an administrator. |
| Assessment or approval review is needed | Hand the case to Assessor, Auditor, or Approver through the task workflow. |
| Budget or financial-governance decision is required | Escalate to OC/Pension-equivalent supervision. |
| Import error suggests technical failure rather than bad source data | Escalate to administrator or support with the review output. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Import review shows many row-level failures | Correct the source template first and rerun the review; do not import anyway. |
| Claims save is blocked | Recheck beneficiary, period, amount, and whether the role actually has the required current permission. |
| Registry action seems unavailable | Confirm whether the record is in create or edit mode and whether the effective permission was overridden. |
| Data conflict appears between staff-due and registry records | Pause the case, correct the authoritative source, and note the change clearly. |

# 8. Working Checklist

- Confirm the exact record or batch before starting work.
- Use templates without changing headers.
- Review every validation or import-review file.
- Verify financial period and beneficiary identity before claims actions.
- Escalate queue, policy, and technical failures promptly.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
