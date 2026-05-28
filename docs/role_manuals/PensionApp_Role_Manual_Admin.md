# UPS PensionsGo Role Manual: Administrator

**System:** UPS PensionsGo  
**Role Key:** `admin`  
**Role Label:** Administrator  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Admin.docx`, `docs/role_manuals/PensionApp_Role_Manual_Admin.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Administrators, support leads, governance reviewers, and onboarding trainers |
| Current role purpose | Global governance, access administration, operational oversight, data management, and system assurance. |
| Default landing page | Dashboard |
| Role type | Governance and administration role |
| Current access note | This role can reach all governed work areas, but actions must still follow the correct operational process and audit requirements. |

# 1. Purpose and Role Position

The Administrator role owns system-wide governance for UPS PensionsGo. Administrators maintain users, roles, settings, notifications, exports, backups, cleanup routines, and controlled oversight across operational modules.
This manual is written for the current implemented platform. It reflects the live Settings workspace, role-governance model, dashboard diagnostics, and the broader obligation to use elevated access carefully and only for legitimate operational reasons.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Settings | Maintain system settings, access control, roles, notifications, storage, audit views, backups, cleanup, and diagnostics. |
| Users | Search, review, and maintain user accounts and role assignments where governance permits. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| Staff Due for Retirement | Capture, review, filter, and progress staff-due records through governed intake and verification steps. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Budget Forecast | Review or manage arrears and pension forecast views by financial period. |
| Messages | Exchange controlled operational messages, updates, and clarifications with other users. |
| Reports | Review operational summaries and role-relevant management output. |

## 2.2 Support and Reference Workspaces

| Menu / Workspace | Typical purpose |
| --- | --- |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Benefits Calculator | Estimate service-based pension outputs using the configured retirement formulas. |
| Podcast | Watch guided pension information videos and official explanatory content. |
| Document Viewer | Open linked documents in a controlled preview workspace. |
| My Profile | Review personal account information, role label, and current account details. |
| Edit Profile | Maintain permitted personal account fields and credentials. |

## 2.3 Default Governed Capabilities

| Permission / Control | Capability | Operational meaning |
| --- | --- | --- |
| `registry.edit` | Create and update registry records | Open the registry workspace in edit mode, maintain file data, and save governed changes. |
| `staff_due.edit` | Create and update staff-due records | Capture and correct staff-due records through the supported forms and workflow tools. |
| `staff_due.bulk_upload` | Bulk upload staff-due records | Import approved staff-due schedules from template-driven CSV or XLSX files. |
| `registry.bulk_upload` | Bulk upload registry files | Import approved pension registry schedules in bulk using the governed registry import flow. |
| `registry.life_certificate.submit` | Submit life certificates | Record life-certificate submissions and update the linked beneficiary contact profile. |
| `registry.delete_request` | Request registry deletion | Queue a registry record for governed deletion review instead of removing it directly. |
| `registry.delete_queue.process` | Process registry delete queue | Approve, reject, restore, export, and clear governed registry delete-queue items. |
| `staff_due.delete_request` | Request staff-due deletion | Submit a staff-due record for delete review with justification and audit trace. |
| `staff_due.delete_queue.process` | Process staff-due delete queue | Approve or reject queued staff-due deletion requests. |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `registry.benefits.length_service.edit` | Maintain length of service | Adjust the service-duration value used in benefits assessment where the edit control is exposed. |
| `registry.benefits.amounts.edit` | Maintain calculated benefit amounts | Adjust annual salary, reduced pension, full pension, and gratuity fields where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `file_movement.return` | Mark files as returned | Close a movement entry by confirming the file has returned into custody. |
| `payroll.upload` | Upload payroll evidence | Upload payroll cycles and payment-register evidence where the interface exposes the payroll workspace. |
| `payroll.manage` | Replace or delete payroll cycles | Manage payroll-cycle replacement, deletion, and active-period control. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |
| `claims.arrears.manage` | Manage arrears ledger | Create arrears entries, post payments, submit accountability, and reconcile balances. |
| `claims.suspension.upload` | Upload suspension records | Import suspension schedules and preserve row-level suspension reasons during reconciliation. |
| `feedback.view` | View feedback inbox | Open the dashboard feedback workspace and inspect submissions, summary metrics, and case detail. |
| `feedback.manage` | Manage feedback workflow | Assign, review, resolve, close, and export governed feedback submissions. |
| `budget.manage` | Manage budget forecast | Create and update budget-forecast figures and planning notes for arrears and pension obligations. |

## 2.4 Work Normally Outside This Role

- Do not bypass normal workflow simply because admin access exists.
- Do not run cleanup, delete, or restore routines without confirming retention, backup, and business justification.
- Do not make informal database-side corrections outside governed application flows unless the task is an approved technical recovery exercise.

# 3. Standard Daily Operating Procedure

1. Sign in and review the shared dashboard, recent activity, and any urgent operational or support signals.
2. Open Settings and confirm whether there are pending user, role, notification, storage, or diagnostic actions that need attention.
3. Process governance work such as account maintenance, role updates, settings review, export requests, or operational escalations.
4. Review delete queues, feedback workflow, and data-management actions before approving any destructive or high-impact operation.
5. Record decisions through the governed interface so that the audit trail remains complete.

# 4. Module Guidance

## 4.1 Settings

Use Settings as the main governance entry point. It opens the administrative workspace for Dashboard Overview, User Management, System Settings, role governance, notification controls, data management, storage oversight, activity logs, audit trail, and system health diagnostics.

### Standard Steps

1. Open Settings and confirm you are working in the correct section before changing settings or records.
2. When changing settings, review the section subtitle and field help text first so you understand the effect of the change.
3. Save only one coherent governance change at a time and wait for the success confirmation before navigating away.
4. For role or permission changes, confirm the target role or user, review the effective permission impact, and then save.
5. For diagnostics, use system health and activity-log sections to understand the problem before taking corrective action.

### Control Points

- Sensitive settings may require re-authentication or a fresh session.
- Existing sessions may need re-login before some security changes fully apply.
- Role governance should be coordinated with the operational owner to avoid unplanned access loss.

## 4.2 Data Management, Backup, and Cleanup

Administrators control export, import, backup, restore, and cleanup tooling. These actions affect data integrity and must be treated as governed maintenance, not casual housekeeping.

### Standard Steps

1. Confirm the business reason for the operation and verify the correct environment before starting a backup, export, restore, or cleanup.
2. For backup or export jobs, confirm the target scope, file naming, and destination path before execution.
3. For import or cleanup actions, prefer dry-run or preview modes first whenever the interface provides them.
4. Review generated reports, import-review files, or cleanup previews before allowing a destructive or finalizing step.
5. Retain evidence of completed data-management activity in the platform logs or approved operational records.

### Control Points

- Cleanup should remain backup-first and preview-first unless there is an approved emergency procedure.
- Restore operations must be aligned with incident ownership and user communication.
- Exports may contain sensitive records and must be handled according to operational privacy rules.

## 4.3 Operational Oversight

Although administrators can access all modules, their operational use should focus on supervision, support, escalation, and corrective governance rather than replacing line users in routine work.

### Standard Steps

1. Use the shared dashboard to identify pressure points in claims, workflow, life certificates, file custody, and registry delete queues.
2. Open source modules only after confirming the dashboard signal that needs action.
3. Support operational users by correcting access, settings, or queue bottlenecks instead of taking over normal routine activity where possible.
4. When an exception requires admin intervention inside a line module, complete the minimum safe action and leave a clear audit trace.
5. Return work to the correct operational owner after the exception has been stabilized.

### Control Points

- Admin access is not a substitute for business ownership.
- High-impact changes should be coordinated with OC/Pension or the relevant role lead.
- Use the role-aware workflow path whenever the system already provides one.

## 4.4 Security, Sessions, and Diagnostics

Administrators maintain session policies, alert routing, active-session discipline, and diagnostic follow-up for system health or operational incidents.

### Standard Steps

1. Use the security and session controls to review timeout windows, multi-session policy, and re-authentication behavior.
2. Check active sessions and logs when investigating login issues, policy changes, or unusual activity.
3. Use system-health diagnostics to isolate failed exports, backup issues, queue failures, or other operational warnings.
4. Apply the least disruptive corrective action that solves the issue, then monitor the platform for recurrence.
5. Escalate code defects or infrastructure faults to engineering support with the exact error evidence and date/time context.

### Control Points

- Never terminate sessions or relax security settings casually.
- Notification, audit, and diagnostic evidence should be preserved during incident handling.
- If a problem points to a code or schema defect, stop short of risky workarounds and escalate.

# 5. Governance and Control Rules

- Use elevated access strictly for legitimate administrative work.
- Keep changes small, traceable, and reversible where possible.
- Prefer governed application tools over manual database or filesystem intervention.
- Review storage, backup, export, and cleanup implications before destructive actions.
- Document exceptional interventions so operational teams understand what changed and why.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Operational routing or case ownership questions | Coordinate with OC/Pension or the designated workflow lead before reassigning responsibility. |
| Line-user data correction that does not require admin-only access | Return the task to clerk, data-entry, registry, or workflow staff after removing the blocker. |
| Code defect, schema mismatch, or repeated API failure | Escalate to technical support or engineering with logs, exact page, action, and timestamp. |
| Policy change affecting live users | Communicate the change to supervisors and user-support contacts before or immediately after rollout. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| A user cannot log in after a policy change | Check session state, maintenance mode, role assignment, and any recent access-control changes first. |
| A PDF or export job fails | Review the queue, storage destination, Word/PDF tooling, and system-health diagnostics before retrying. |
| Cleanup action shows unexpected candidates | Stop and review the preview output, retention settings, and backup status before proceeding. |
| A role no longer sees expected actions | Inspect role governance and user-specific permission overrides before changing code or data. |

# 8. Working Checklist

- Review dashboard and diagnostics at the start of the session.
- Confirm the target role, user, or dataset before saving a governance change.
- Use preview or dry-run modes for imports and cleanup where available.
- Preserve logs and evidence for incidents, restores, or exceptional admin interventions.
- Close the day with unresolved admin actions clearly handed over or documented.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
