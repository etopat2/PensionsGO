# UPS PensionsGo Role Manual: Assessor

**System:** UPS PensionsGo  
**Role Key:** `assessor`  
**Role Label:** Assessor  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Assessor.docx`, `docs/role_manuals/PensionApp_Role_Manual_Assessor.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Assessors, supervisors, trainers, and support teams |
| Current role purpose | Benefit assessment, pension calculation review, and recommendation of case outcomes for later audit and approval stages. |
| Default landing page | My Tasks |
| Role type | Calculation and assessment role |
| Current access note | This role is task-led and calculation-focused. The current permission model allows strong benefit-review authority, but some edit controls may still depend on whether the registry edit workspace is exposed in the live UI. |

# 1. Purpose and Role Position

The Assessor role is responsible for checking whether the pension case produces the correct benefits outcome based on service history, retirement context, salary inputs, and the current system rules.
Assessors should treat the case as a controlled calculation exercise. The goal is not only to produce a number, but to leave a defendable assessment path that later audit and approval roles can follow without guesswork.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| My Tasks | Work assigned items, add comments, update task status, and progress workflow handoffs. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| File Tracking | Track file custody, movement out of registry, receiving office, and return status. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Messages | Exchange controlled operational messages, updates, and clarifications with other users. |

## 2.2 Support and Reference Workspaces

| Menu / Workspace | Typical purpose |
| --- | --- |
| Benefits Calculator | Estimate service-based pension outputs using the configured retirement formulas. |
| Document Viewer | Open linked documents in a controlled preview workspace. |
| Podcast | Watch guided pension information videos and official explanatory content. |
| My Profile | Review personal account information, role label, and current account details. |
| Edit Profile | Maintain permitted personal account fields and credentials. |

## 2.3 Default Governed Capabilities

| Permission / Control | Capability | Operational meaning |
| --- | --- | --- |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `registry.benefits.length_service.edit` | Maintain length of service | Adjust the service-duration value used in benefits assessment where the edit control is exposed. |
| `registry.benefits.amounts.edit` | Maintain calculated benefit amounts | Adjust annual salary, reduced pension, full pension, and gratuity fields where the edit control is exposed. |
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |

## 2.4 Work Normally Outside This Role

- General registry editing is not the core purpose of the role and may not always be exposed in the UI.
- Claims posting, delete queues, payroll uploads, and budget maintenance are not default assessor functions.
- Final approval remains outside this role.

# 3. Standard Daily Operating Procedure

1. Open the assigned task and confirm the assessment question or expected outcome.
2. Review the registry record, supporting documents, retirement profile, and any prior workflow comments.
3. Use the calculator and benefits fields to validate the correct service-based outcome.
4. If the required edit control is not exposed, leave a clear task note and coordinate with the relevant registry-edit or admin role.
5. Hand off the completed assessment with enough reasoning for audit and approval.

# 4. Module Guidance

## 4.1 Assessment Queue Review

Assessment begins with the task queue because the queue provides case ownership, due date, and the current stage context.

### Standard Steps

1. Read the task instructions and previous workflow comments before reviewing the record.
2. Open the registry record and supporting evidence to understand the service and retirement context.
3. Identify the calculation inputs that matter most for the case, especially salary, service duration, and retirement type.
4. Record questions or discrepancies as formal task comments rather than private notes.
5. Update task state only when the assessment work has genuinely moved forward.

### Control Points

- Assessment comments should explain reasoning, not just conclusions.
- Do not skip source-record review and rely only on dashboard summaries.
- Escalate unresolved source-data conflicts promptly.

## 4.2 Benefits Calculation and Validation

The key assessor responsibility is to validate whether the benefit outcome shown by the system is correct and defensible.

### Standard Steps

1. Use the Benefits Calculator to model the expected result based on the case facts.
2. Review monthly salary, length of service, and benefit amount fields in the registry context where the edit controls are exposed.
3. If a field clearly requires correction, change it only through the supported UI and only when the evidence justifies it.
4. If the live UI does not expose the needed edit control, record the required correction and route it through the appropriate governed path.
5. Document the conclusion in the task or workflow notes so later reviewers can follow the logic.

### Control Points

- Never change calculated values to force a preferred outcome.
- Every change should be tied to evidence or a validated calculation result.
- Where control visibility is limited, use escalation instead of unsupported workarounds.

## 4.3 Claims and File Context

Claims visibility and file movement provide supporting context for a complete assessment, especially where financial exposure or file location affects timing.

### Standard Steps

1. Review the claims workspace to understand arrears exposure that may depend on the assessment outcome.
2. Use file movement logging when the file changes custody during assessment work.
3. Check application status so the case is handed to the right downstream role.
4. Use messages where targeted clarification is needed from the previous handler.
5. Keep the assessment package ready for audit without hiding unresolved issues.

### Control Points

- Claims visibility is contextual unless separate claims-management authority exists.
- Movement logs must stay aligned with real custody.
- Do not hand off a case with unresolved calculation uncertainty hidden in comments.

# 5. Governance and Control Rules

- Tie every assessment outcome to evidence and explicit reasoning.
- Use calculator and benefits tools carefully and consistently.
- Escalate missing or hidden edit controls instead of bypassing them.
- Keep file-custody and task state synchronized with the real case state.
- Prepare the case so audit can understand it without redoing the whole assessment from scratch.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Assessment is complete | Hand off to Auditor with a clear note on the logic, assumptions, and any residual issues. |
| Registry or source data needs correction outside exposed controls | Route to a registry-edit role or ask an administrator to reconcile access if needed. |
| Policy or supervisory issue affects the outcome | Escalate to OC/Pension-equivalent supervision. |
| Technical mismatch affects calculation behavior | Escalate to administrator or engineering support with exact evidence. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Benefit edit controls are not visible | Confirm whether the live UI currently exposes them for your session, then escalate if the task depends on them. |
| Calculator result and registry snapshot disagree | Recheck salary, service, retirement type, and date inputs before concluding there is a system defect. |
| Claims exposure suggests a high-impact change | Record the implication and escalate it rather than forcing the case forward silently. |
| Source documents conflict | Pause the assessment and request correction or clarification. |

# 8. Working Checklist

- Read the assigned task before opening the record.
- Validate inputs before validating outputs.
- Use evidence-backed reasoning for every conclusion.
- Escalate access or source-data problems promptly.
- Hand audit a case that is traceable and explainable.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
