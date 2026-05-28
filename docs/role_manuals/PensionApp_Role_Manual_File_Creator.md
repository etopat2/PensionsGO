# UPS PensionsGo Role Manual: File Creator

**System:** UPS PensionsGo  
**Role Key:** `file_creator`  
**Role Label:** File Creator  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_File_Creator.docx`, `docs/role_manuals/PensionApp_Role_Manual_File_Creator.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | File creators, supervisors, trainers, and support teams |
| Current role purpose | Task-based file establishment, file-custody handling, and preparation of complete case packs for downstream capture and review. |
| Default landing page | My Tasks |
| Role type | Workflow file-establishment role |
| Current access note | The current build gives this role strong task, file-tracking, claims-visibility, and registry-review access. Some registry mutation actions may still require an added override or handoff to a registry-edit role. |

# 1. Purpose and Role Position

The File Creator role is responsible for shaping the case into a usable file package for later capture and decision stages. In the current application, this role works mainly from tasks, registry review, file tracking, and status workspaces.
Where the interface does not expose direct create or edit controls for registry data, that is an expected part of the live access model. The role should then complete the file-preparation work, log movement correctly, and hand off the case to a role with the required edit rights.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| My Tasks | Work assigned items, add comments, update task status, and progress workflow handoffs. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
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
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |

## 2.4 Work Normally Outside This Role

- Direct registry create or edit actions are not guaranteed for this role in the current build.
- Delete requests, queue processing, claims posting, payroll uploads, and budget updates are not default file-creator work.
- Supervisory approval and role governance remain outside this role.

# 3. Standard Daily Operating Procedure

1. Open assigned tasks first and confirm the file-establishment objective and expected downstream recipient.
2. Review the registry, documents, claims context, and application status before moving the case.
3. Use file-tracking tools whenever the case file moves between desks or offices.
4. Where an edit control is not available, leave a clear task note and hand the case to the correct editing role.
5. Close the task only after the file package is complete and the handoff is explicit.

# 4. Module Guidance

## 4.1 Task-Led File Establishment

The File Creator role should work from the task queue because that is where ownership and expected output are made clear.

### Standard Steps

1. Open the assigned task and note the exact file-establishment requirement.
2. Review all existing comments and linked evidence so you understand what is already complete.
3. Confirm whether the case needs new file movement, document review, or registry clarification.
4. If the task depends on unavailable edit rights, record that clearly and route it to the correct role instead of stalling silently.
5. Update task status only when the file package has genuinely moved forward.

### Control Points

- Use tasks as the source of ownership truth.
- Do not assume missing controls are a bug; first confirm whether the role is expected to hand off.
- Keep downstream instructions explicit.

## 4.2 Registry Review and Evidence Check

This role can use the registry and document viewer to confirm whether the case pack is internally consistent and ready for handoff.

### Standard Steps

1. Search the pension file registry and open the correct record or details view.
2. Review linked documents and pension-profile context using the document viewer where necessary.
3. Check whether the record appears complete enough for the next workflow stage.
4. If the registry edit workspace is not available, note the required correction and route it to a registry-edit role.
5. Use the application-status view to confirm the correct next destination for the case.

### Control Points

- Do not force unsupported edits through unofficial means.
- Use evidence review to improve handoff quality, not to improvise policy decisions.
- Always confirm the correct record before opening supporting documents.

## 4.3 File Movement and Handoff

File creators should preserve strong file-custody discipline because a well-prepared file still fails operationally if nobody can trace where it went.

### Standard Steps

1. Record every movement out of custody with destination and reason.
2. Confirm receiving office or user details before saving the movement.
3. Send a concise task or message update when the file is handed over.
4. Use claims visibility only for context where the financial state affects the file package.
5. Keep the file location and task state synchronized.

### Control Points

- Movement logs must reflect the real location of the file.
- Claims visibility does not authorize financial updates.
- A handoff is incomplete if the next role cannot tell where the file is and what remains to be done.

# 5. Governance and Control Rules

- Let the task queue define ownership and expected output.
- Respect the current edit-control model and hand off where necessary.
- Keep file-custody data current and exact.
- Use registry and claims visibility to improve context, not to exceed role authority.
- Leave downstream roles a clean, understandable case package.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Registry data needs correction | Send the case to a role with registry-edit authority such as Clerk, Data Entry, or Writeup Officer. |
| Assessment is the next true step | Hand off to Assessor with a clear note about document and file status. |
| Supervisory or delete decision is needed | Escalate to OC/Pension-equivalent supervision. |
| Access mismatch appears to block expected work | Confirm with administrator or support whether an override is intended. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Edit button is not visible in registry | Treat this as expected unless the task explicitly says edit rights were granted. |
| Supporting documents are missing | Pause the handoff and request the missing evidence through tasks or messaging. |
| File location is unclear | Reconcile the file-movement log before advancing the case. |
| Claims exposure seems relevant but unclear | Review claims context and escalate the financial question rather than guessing. |

# 8. Working Checklist

- Start from the assigned task.
- Review the evidence pack before handing off the file.
- Record every custody change.
- Escalate missing edit rights instead of improvising workarounds.
- Leave the next role clear instructions and file location context.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
