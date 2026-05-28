# UPS PensionsGo Role Manual: Writeup Officer

**System:** UPS PensionsGo  
**Role Key:** `writeup_officer`  
**Role Label:** Writeup Officer  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Writeup_Officer.docx`, `docs/role_manuals/PensionApp_Role_Manual_Writeup_Officer.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Writeup officers, supervisors, trainers, and support staff |
| Current role purpose | Preparation of pension write-ups, case refinement, controlled record updates, and workflow handoff to downstream stages. |
| Default landing page | My Tasks |
| Role type | Workflow preparation role |
| Current access note | This role is task-driven. The current build expects write-up work to begin from the assigned queue and then move into staff-due or registry detail as required. |

# 1. Purpose and Role Position

The Writeup Officer role converts a raw or partially verified case into a well-prepared pension file ready for downstream creation, capture, and assessment stages. The role usually works from assigned tasks and uses registry or staff-due workspaces to polish the record and supporting narrative.
This role should focus on completeness, coherence, and readiness for handoff. Where a record cannot be made ready because source data is weak or authority is missing, the correct action is escalation, not silent approximation.

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
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
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
| `registry.edit` | Create and update registry records | Open the registry workspace in edit mode, maintain file data, and save governed changes. |
| `registry.life_certificate.submit` | Submit life certificates | Record life-certificate submissions and update the linked beneficiary contact profile. |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |

## 2.4 Work Normally Outside This Role

- Bulk uploads, delete-queue processing, and budget work are not default write-up tasks.
- Claims payment posting and suspension imports remain outside the default role scope.
- Supervisory approval decisions should be left to OC/Pension-equivalent or approval roles.

# 3. Standard Daily Operating Procedure

1. Open the assigned task first and confirm the exact case objective, due date, and expected output.
2. Review the staff-due record, registry record, supporting documents, and workflow comments before editing anything.
3. Update the record only through the governed forms and leave factual comments that help the next role understand the case.
4. Record any file movement that occurs while the write-up is being prepared.
5. Hand off the case promptly once the write-up package is complete or clearly blocked.

# 4. Module Guidance

## 4.1 Assigned Task Review

The write-up role should begin from the task queue because that is where responsibility, due date, and current workflow context are made explicit.

### Standard Steps

1. Open the assigned task and read any prior comments, alert flags, and status history.
2. Confirm what the next role needs from the write-up before making changes.
3. If the case lacks enough source material, leave a precise note and request clarification instead of guessing.
4. Update task status as real progress occurs, not in anticipation of progress.
5. Close or hand off the task only when the record and notes are genuinely ready for the next stage.

### Control Points

- Task comments should be factual and operationally useful.
- Do not hide uncertainty; escalate it.
- Status changes must reflect real case state.

## 4.2 Record Preparation

Write-up officers use staff-due and registry workspaces to make the case coherent and ready for later stages.

### Standard Steps

1. Review the current staff-due and registry information side by side where needed.
2. Correct governed fields only after confirming the underlying evidence.
3. Use life-certificate tools only when that evidence is part of the case and you have the correct profile in view.
4. Review linked documents through the document viewer so the write-up aligns to the evidence pack.
5. Leave a concise note for the downstream role explaining what was confirmed, corrected, or remains outstanding.

### Control Points

- Every correction should be traceable to evidence or instruction.
- Do not convert a missing-data problem into a guessed-data problem.
- Maintain document-to-record alignment at all times.

## 4.3 Claims and File Context Review

Claims visibility and file-movement tools help the write-up role understand financial and custody context around the case.

### Standard Steps

1. Review claims exposure or arrears context when the case history suggests payment impact.
2. Use file-movement logging whenever the physical or recorded file moves during write-up work.
3. Confirm application status and current workflow stage before handing the case forward.
4. Send messages when another role needs targeted clarification or additional evidence.
5. Escalate financial or supervisory questions instead of making unsupported decisions.

### Control Points

- Claims visibility is for context unless a separate management right exists.
- Custody records must reflect the real movement of the file.
- Handoffs should never be silent; they should explain the state of the case.

# 5. Governance and Control Rules

- Begin from the assigned task so ownership remains clear.
- Use governed edit forms only after confirming source evidence.
- Keep write-up notes concise, factual, and useful for the next role.
- Record file movement whenever custody changes.
- Escalate missing evidence, policy ambiguity, or supervisory decisions promptly.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Case is ready for structured capture | Hand off to File Creator or Data Entry according to the local workflow arrangement. |
| A calculation or benefits review is needed | Send the case to Assessor with a clear summary of the evidence state. |
| Delete or supervisory exception is identified | Escalate to OC/Pension-equivalent supervision. |
| A technical or access issue blocks the workspace | Escalate to administrator or support with the exact failing action. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Task instructions are vague | Review record history and request clarification before editing the case. |
| Evidence conflicts across forms or documents | Do not choose informally; flag the discrepancy and escalate. |
| Life-certificate controls are unavailable | Confirm whether the role and record context actually allow that action. |
| A downstream role rejects the case as incomplete | Reopen the task, correct the missing items, and document the new handoff clearly. |

# 8. Working Checklist

- Read the assigned task before opening source records.
- Confirm evidence before every correction.
- Use file-movement logging when custody changes.
- Leave a clean note for the downstream role.
- Escalate ambiguity instead of masking it.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
