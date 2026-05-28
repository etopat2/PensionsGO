# UPS PensionsGo Role Manual: Clerk

**System:** UPS PensionsGo  
**Role Key:** `clerk`  
**Role Label:** Clerk  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Clerk.docx`, `docs/role_manuals/PensionApp_Role_Manual_Clerk.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Clerks, intake officers, supervisors, trainers, and support teams |
| Current role purpose | Application intake, verification support, registry maintenance, life-certificate handling, and first-line workflow progression. |
| Default landing page | Pension File Registry |
| Role type | Operational intake and verification role |
| Current access note | The current build routes clerks into the registry area after login; intake work then continues across staff-due, registry, dashboard, and task views. |

# 1. Purpose and Role Position

The Clerk role is the first operational control point for pension-case capture and verification support. Clerks keep staff-due records clean, update governed registry details, record file movement, and support life-certificate or payroll evidence handling where the system allows it.
Clerks are expected to keep the data trustworthy and the workflow clear. This means capturing complete records, using status tools correctly, and escalating delete approvals or exceptional governance actions instead of trying to resolve them informally.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| Staff Due for Retirement | Capture, review, filter, and progress staff-due records through governed intake and verification steps. |
| Add Staff | Create a new staff-due record with identity, service, and workflow information. |
| Edit Staff Record | Correct or complete a staff-due record where the current role has edit rights. |
| View Staff | Inspect full staff-due detail before routing, verification, or handoff. |
| My Tasks | Work assigned items, add comments, update task status, and progress workflow handoffs. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
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
| `staff_due.edit` | Create and update staff-due records | Capture and correct staff-due records through the supported forms and workflow tools. |
| `staff_due.delete_request` | Request staff-due deletion | Submit a staff-due record for delete review with justification and audit trace. |
| `registry.edit` | Create and update registry records | Open the registry workspace in edit mode, maintain file data, and save governed changes. |
| `registry.life_certificate.submit` | Submit life certificates | Record life-certificate submissions and update the linked beneficiary contact profile. |
| `registry.delete_request` | Request registry deletion | Queue a registry record for governed deletion review instead of removing it directly. |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `file_movement.return` | Mark files as returned | Close a movement entry by confirming the file has returned into custody. |
| `payroll.upload` | Upload payroll evidence | Upload payroll cycles and payment-register evidence where the interface exposes the payroll workspace. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |
| `feedback.view` | View feedback inbox | Open the dashboard feedback workspace and inspect submissions, summary metrics, and case detail. |
| `feedback.manage` | Manage feedback workflow | Assign, review, resolve, close, and export governed feedback submissions. |

## 2.4 Work Normally Outside This Role

- Bulk import of staff-due or registry schedules is not a default clerk capability.
- Delete-queue processing remains a supervisory or administrator action.
- Claims payment posting, suspension uploads, and budget maintenance are not default clerk responsibilities.

# 3. Standard Daily Operating Procedure

1. Sign in and review the dashboard or assigned tasks for intake, verification, or follow-up priorities.
2. Open staff-due or registry workspaces and locate the target record using filters or search first.
3. Capture or correct controlled fields carefully, then save through the governed form.
4. Use workflow actions, comments, and file-movement records to move work forward with traceability.
5. Queue delete requests or escalate exceptions instead of using shortcuts for sensitive changes.

# 4. Module Guidance

## 4.1 Staff-Due Intake and Verification

Clerks are expected to maintain the staff-due workspace carefully because it forms the early evidence base for later workflow stages.

### Standard Steps

1. Create or open the staff-due record and verify identity, service, retirement, and contact fields before saving.
2. Use the guided workflow actions for submit, verify, or review rather than relying on informal notes.
3. Where a record should not remain active, submit a delete request with a clear reason instead of attempting removal outside the queue.
4. Check supporting details again after edits so later roles do not inherit avoidable errors.
5. If the queue or action controls are not visible, confirm role and permission state before escalating.

### Control Points

- Do not save guessed identifiers or retirement dates.
- Verification activity should reflect the real record state, not a desire to clear the queue.
- Delete requests must include defensible reasons.

## 4.2 Registry Maintenance and Life Certificates

Clerks can maintain registry records and record life-certificate submissions. This work should be exact because the registry is the authoritative pension-file reference.

### Standard Steps

1. Search for the correct pension file and open the detail or edit workspace before changing any field.
2. Update only the fields supported by the interface and confirm the correct file number, pensioner identity, and service context first.
3. Use the life-certificate tools for the correct year and confirm the contact profile before submitting.
4. Attach or review documents through the governed registry document path when that function is available.
5. Submit registry delete requests only when there is a valid operational reason and supporting explanation.

### Control Points

- Do not treat the registry as a scratchpad for unresolved information.
- Life-certificate status must match actual evidence received.
- Registry delete requests are not the same as correction requests.

## 4.3 File Movement and Payroll Evidence

Clerks also support physical or recorded file custody and can upload payroll evidence where the platform exposes that workflow.

### Standard Steps

1. Record file movement whenever custody changes and include destination, reason, and timing details.
2. Mark a file as returned only after confirming the return into custody.
3. For payroll evidence, confirm the correct period, supporting register, and source file before upload.
4. Review the resulting payroll view or exception summary immediately after upload.
5. Escalate period mismatches or unexplained payroll gaps instead of forcing a questionable upload.

### Control Points

- Custody entries should match the real or officially recorded file movement.
- Payroll uploads require the correct month, year, and supporting file.
- Replacement or deletion of payroll cycles remains outside the clerk role.

## 4.4 Claims Visibility and Feedback Handling

Clerks can review claims exposure and participate in dashboard feedback management, but not in full claims posting or budget control by default.

### Standard Steps

1. Use the claims workspace to understand the current arrears context before escalating or replying to enquiries.
2. Do not post payments or suspension uploads unless an explicit override has been granted.
3. Use the dashboard feedback workspace to review submissions, update workflow fields, and close the loop on issues within your responsibility.
4. Send controlled messages when another role needs clarification or evidence.
5. Escalate financial or policy-sensitive items to OC/Pension or administration.

### Control Points

- Claims visibility does not automatically permit claims mutation.
- Feedback closure should reflect a real reviewed outcome.
- Escalate anything that changes financial liability or governance state.

# 5. Governance and Control Rules

- Capture complete and accurate information before saving.
- Use workflow controls and delete-request queues instead of informal workarounds.
- Treat registry, payroll, and document records as sensitive operational information.
- Record file-custody changes every time the file moves.
- Escalate supervisory, financial, or queue-processing decisions to the correct role.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Delete request needs approval | Route it to OC/Pension-equivalent supervision or an administrator. |
| Case needs detailed write-up | Move it to the Writeup Officer through the task workflow. |
| Calculation or assessment review is required | Escalate to the Assessor after confirming the source record is complete. |
| Claims posting or budget action is needed | Escalate to a role with claims-management or budget authority. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Edit controls are missing | Confirm your current role, effective role, and page-specific permission state first. |
| Registry delete cannot be completed immediately | Use the delete-request path and wait for queue processing. |
| Payroll upload result shows unmatched rows | Review the exception output and escalate corrections before retrying. |
| A record is incomplete but already in workflow | Correct what the role is allowed to fix, then leave a clear note for the next handler. |

# 8. Working Checklist

- Check search filters before assuming a record is missing.
- Verify identity, retirement, and contact data before saving.
- Use life-certificate and delete-request tools only for the correct record.
- Record every file movement and return status accurately.
- Escalate financial, supervisory, and queue-processing work to the correct owner.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
