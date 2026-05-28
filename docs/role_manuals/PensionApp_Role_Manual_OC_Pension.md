# UPS PensionsGo Role Manual: OC/Pension

**System:** UPS PensionsGo  
**Role Key:** `oc_pen`  
**Role Label:** OC/Pension  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_OC_Pension.docx`, `docs/role_manuals/PensionApp_Role_Manual_OC_Pension.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | OC/Pension officers, supervisors, governance reviewers, and trainers |
| Current role purpose | Workflow control, assignment authority, delete governance, bulk-import control, claims supervision, and budget oversight. |
| Default landing page | Dashboard |
| Role type | Supervisory workflow control role |
| Current access note | This role is dashboard-first and is expected to supervise exceptions, queues, and financial or governance-sensitive actions rather than do all detailed capture personally. |

# 1. Purpose and Role Position

The OC/Pension role acts as the operational controller for live pension workflow. The role monitors dashboard signals, approves or rejects deletion requests, governs bulk imports, supervises claims and budget handling, and keeps the work moving between detailed line roles.
Because the role is supervisory, its main value is decision quality and control discipline. The objective is not to replace clerks, data entrants, or assessors, but to resolve bottlenecks, authorize controlled actions, and maintain accountability across the process.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Staff Due for Retirement | Capture, review, filter, and progress staff-due records through governed intake and verification steps. |
| Add Staff | Create a new staff-due record with identity, service, and workflow information. |
| View Staff | Inspect full staff-due detail before routing, verification, or handoff. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| My Tasks | Work assigned items, add comments, update task status, and progress workflow handoffs. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Budget Forecast | Review or manage arrears and pension forecast views by financial period. |
| Messages | Exchange controlled operational messages, updates, and clarifications with other users. |
| Reports | Review operational summaries and role-relevant management output. |

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
| `staff_due.bulk_upload` | Bulk upload staff-due records | Import approved staff-due schedules from template-driven CSV or XLSX files. |
| `registry.bulk_upload` | Bulk upload registry files | Import approved pension registry schedules in bulk using the governed registry import flow. |
| `staff_due.delete_request` | Request staff-due deletion | Submit a staff-due record for delete review with justification and audit trace. |
| `staff_due.delete_queue.process` | Process staff-due delete queue | Approve or reject queued staff-due deletion requests. |
| `registry.delete_request` | Request registry deletion | Queue a registry record for governed deletion review instead of removing it directly. |
| `registry.delete_queue.process` | Process registry delete queue | Approve, reject, restore, export, and clear governed registry delete-queue items. |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `file_movement.return` | Mark files as returned | Close a movement entry by confirming the file has returned into custody. |
| `payroll.upload` | Upload payroll evidence | Upload payroll cycles and payment-register evidence where the interface exposes the payroll workspace. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |
| `claims.arrears.manage` | Manage arrears ledger | Create arrears entries, post payments, submit accountability, and reconcile balances. |
| `claims.suspension.upload` | Upload suspension records | Import suspension schedules and preserve row-level suspension reasons during reconciliation. |
| `feedback.view` | View feedback inbox | Open the dashboard feedback workspace and inspect submissions, summary metrics, and case detail. |
| `feedback.manage` | Manage feedback workflow | Assign, review, resolve, close, and export governed feedback submissions. |
| `budget.manage` | Manage budget forecast | Create and update budget-forecast figures and planning notes for arrears and pension obligations. |

## 2.4 Work Normally Outside This Role

- Direct line-by-line registry or staff-due editing is not the default working pattern for this role.
- Payroll cycle replacement and deletion remain administrator functions.
- System settings, role governance, and technical recovery remain administrator-led.

# 3. Standard Daily Operating Procedure

1. Start in the dashboard and review workflow pressure, claims exposure, life-certificate gaps, file-custody issues, and feedback workload.
2. Open the source module only for the specific exception, queue, or supervisory action that needs attention.
3. Use delegation, delete-queue processing, bulk-import governance, and claims/budget controls to keep work moving.
4. Return detailed capture or correction tasks to the responsible operational role after supervisory action is complete.
5. Record comments, approvals, or queue outcomes so that later reviewers can understand the decision path.

# 4. Module Guidance

## 4.1 Dashboard Supervision

The dashboard is the main control surface for OC/Pension-equivalent roles. It concentrates claims, registry, workflow, life-certificate, feedback, and delete-queue visibility in one place.

### Standard Steps

1. Review summary cards and section data before acting on any exception.
2. Use filters to isolate the correct period, claim type, status, or operating scope.
3. Open the detailed module only after confirming the dashboard signal that needs action.
4. Export or report only from the current filtered view so the numbers remain defensible.
5. Use the dashboard as the management picture, not as a substitute for source-record verification.

### Control Points

- Dashboard data should be validated in the source module before final decisions.
- Supervisory exports should be handled as sensitive operational material.
- Feedback management and delete-queue actions must remain evidence-based and role-appropriate.

## 4.2 Bulk Governance and Delete Queues

This role governs high-impact queue work such as bulk imports and deletion review. The focus is approval, exception handling, and control rather than routine data entry.

### Standard Steps

1. Review staff-due or registry import inputs using the governed template and validation output first.
2. Do not accept bulk loads until the review file confirms that structure and critical fields are acceptable.
3. Open delete queues with the exact record, reason, and request context in view before taking action.
4. Approve, reject, or return queue items using consistent reasoning and clear comments where the interface allows it.
5. Where direct edit is needed, reassign the record to a role with line-edit responsibility instead of improvising around the control model.

### Control Points

- Queue actions are governance actions and should be reversible or explainable from the audit trail.
- Delete approval is not a data-cleanup shortcut.
- Bulk import authority does not remove the need for review, sign-off, and evidence retention.

## 4.3 Claims, Suspensions, and Budget Oversight

OC/Pension-equivalent roles supervise the financial side of the workload by reviewing arrears exposure, posting or validating payment actions, importing suspensions, and maintaining budget outlooks.

### Standard Steps

1. Use the claims workspace to confirm current exposure, outstanding balances, and payment-accountability status.
2. Where management rights are present, post or validate arrears actions carefully against the correct beneficiary and period.
3. Use suspension upload flows only with approved source files and review exports before accepting the import.
4. Update or review budget forecasts by the correct financial year and ensure assumptions are consistent with current claims visibility.
5. Escalate unexplained financial anomalies promptly instead of forcing records into a misleading state.

### Control Points

- Period, year, and claim type must be correct before any financial save or import.
- Suspension uploads require source-file discipline and review of exceptions.
- Budget figures are management artefacts and must not be entered casually.

## 4.4 Payroll, Feedback, and File Custody

This role also participates in payroll evidence handling, dashboard feedback governance, and high-level control of file movements.

### Standard Steps

1. Use payroll upload features only for the correct month, year, and supporting register evidence.
2. Review unmatched payroll or suspension results immediately after upload and direct corrections to the right role.
3. Use the feedback workspace to assign, resolve, or close service issues that need supervisory ownership.
4. Confirm file movement and return records where custody accountability matters to pending workflow.
5. Close the loop by sending clear instructions back to the handling officer or unit.

### Control Points

- Do not replace payroll cycles without administrator support.
- Feedback closure should reflect a genuine reviewed outcome, not just inbox cleanup.
- File-custody actions should match the physical or recorded movement of the file.

# 5. Governance and Control Rules

- Operate as a supervisory controller, not a substitute for every downstream role.
- Use bulk, delete, claims, and budget controls only with clear business justification.
- Delegate detailed data correction to the correct line role whenever possible.
- Leave clear comments and queue outcomes for auditability and continuity.
- Escalate configuration, access, or system failures to administrators instead of working around them informally.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Record needs line-by-line correction | Reassign to clerk, data-entry, or write-up staff as appropriate. |
| Assessment or calculation review is required | Delegate to assessor and monitor completion through tasks. |
| System setting, role, or technical defect is blocking work | Escalate to administrator with the exact failure context. |
| Final policy or approval decision is needed | Move the case to the appropriate approver or governance authority. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Bulk upload review shows validation errors | Do not import; correct the source file and rerun the review. |
| Delete queue item lacks enough justification | Reject or return it and request proper reason evidence. |
| Claims numbers look inconsistent | Recheck filters, period scope, and beneficiary detail before changing any record. |
| Budget figures no longer match current claims exposure | Refresh the source data and update the forecast with traceable assumptions. |

# 8. Working Checklist

- Start from the dashboard and confirm the current operating picture.
- Review queues and imports before approving high-impact actions.
- Use delegation to keep detailed operational work with the correct role.
- Confirm financial period and claimant identity before claims or budget actions.
- Leave an audit-friendly trail for every supervisory intervention.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
