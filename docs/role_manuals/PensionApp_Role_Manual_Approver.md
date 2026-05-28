# UPS PensionsGo Role Manual: Approver

**System:** UPS PensionsGo  
**Role Key:** `approver`  
**Role Label:** Approver  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Approver.docx`, `docs/role_manuals/PensionApp_Role_Manual_Approver.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Approvers, supervisors, trainers, and support teams |
| Current role purpose | Final approval-stage control for pension workflow, with responsibility for acceptance, return, or escalation of cases. |
| Default landing page | My Tasks |
| Role type | Final decision role |
| Current access note | This role is decision-oriented. The platform expects approvers to review a complete workflow trail rather than perform routine upstream capture work. |

# 1. Purpose and Role Position

The Approver role gives final workflow authority over cases that have already been assessed and audited. Approvers should confirm that the case is ready for decision, that audit concerns were resolved properly, and that the supporting record is strong enough to defend the outcome.
The live application exposes the same core review surfaces used by audit and assessment roles, but the approver's job is different: make the final decision, return the case with clear reasons, or escalate where policy or governance risk remains unresolved.

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
| `file_movement.record` | Record file movement | Log file movement out of registry custody with destination, reason, and dates. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |

## 2.4 Work Normally Outside This Role

- Routine line editing, claims posting, delete queues, and budget control are not default approver work.
- System governance and access configuration remain administrative responsibilities.
- Approval should not be used to hide unresolved upstream errors.

# 3. Standard Daily Operating Procedure

1. Open the assigned approval task and read the full workflow trail first.
2. Review audit outcome, registry record, supporting documents, and financial context before deciding.
3. Approve only when the case is complete, coherent, and institutionally defensible.
4. Return the case with precise required actions if correction is still needed.
5. Record the final decision clearly so downstream users understand what happened.

# 4. Module Guidance

## 4.1 Approval Queue Review

Approvers should begin from the task queue so the final decision is tied to the correct case, comments, and prior role actions.

### Standard Steps

1. Open the approval task and read the history from earlier roles.
2. Confirm that assessment and audit stages have both added enough evidence and explanation.
3. Review registry, application-status, and claims context to understand the whole case picture.
4. Where necessary, use messages to request precise clarification before deciding.
5. Complete the approval or return action through the governed workflow path.

### Control Points

- Final decisions should be based on the recorded case, not memory or side conversation.
- Do not approve a case simply because it is overdue.
- Returned cases should include specific next steps.

## 4.2 Decision Assurance

Approvers should use registry details, documents, and the benefits context to make sure the decision can be defended later.

### Standard Steps

1. Review the supporting documents and benefit context before accepting the case outcome.
2. Check whether any visible anomalies remain in salary, service, claims exposure, or application history.
3. Where a minor supporting correction is allowed and clearly justified, use the governed control; otherwise return the case.
4. Record why the case was approved, returned, or escalated if that reasoning is not already clear from the trail.
5. Confirm file movement or case location where custody matters to the next administrative step.

### Control Points

- Approval should never conceal unresolved contradictions.
- Visible edit controls do not remove the need for explanation and traceability.
- Custody state should remain consistent with the decision state.

# 5. Governance and Control Rules

- Read the full workflow trail before making a final decision.
- Approve only cases that are complete and defensible.
- Return cases with explicit instructions when gaps remain.
- Use claims and registry context to understand the implications of the decision.
- Leave a clear final decision trail for operations and audit.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Case is acceptable | Approve it and ensure the next administrative step is clear. |
| Case needs correction | Return it to the correct earlier role with explicit required actions. |
| Policy or governance uncertainty remains | Escalate to OC/Pension-equivalent leadership or administration. |
| Technical problem blocks final review | Escalate to administrator or support with the precise failure evidence. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Audit note and case record still disagree | Do not approve; return the case for correction or clarification. |
| Financial context appears high-risk | Review claims exposure carefully and escalate if supervisory input is needed. |
| Required document is missing | Return the case rather than approving on assumption. |
| Application status looks stale | Confirm the true workflow stage before finalizing the decision. |

# 8. Working Checklist

- Read the full workflow trail.
- Verify audit completeness before approval.
- Approve only defensible cases.
- Return cases with clear reasons and next steps.
- Leave a final decision trail that others can follow.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
