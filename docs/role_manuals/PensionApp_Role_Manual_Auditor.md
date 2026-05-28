# UPS PensionsGo Role Manual: Auditor

**System:** UPS PensionsGo  
**Role Key:** `auditor`  
**Role Label:** Auditor  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Auditor.docx`, `docs/role_manuals/PensionApp_Role_Manual_Auditor.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Auditors, supervisors, trainers, and support teams |
| Current role purpose | Audit-stage review of assessed pension cases, evidence reconciliation, and readiness confirmation before approval. |
| Default landing page | My Tasks |
| Role type | Audit and quality-assurance role |
| Current access note | This role is review-oriented. It should verify what earlier roles did, not repeat or replace every prior action unless an exception must be formally returned. |

# 1. Purpose and Role Position

The Auditor role is responsible for checking whether the assessed case is coherent, evidence-backed, and ready for approval. Auditors should look for control failures, unsupported conclusions, missing documents, and inconsistencies across workflow steps.
The audit stage adds assurance. A good audit outcome should tell the approver that the case is not only complete, but also understandable and defensible from the available evidence and system trail.

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

- Audit is not the stage for normal claims posting or broad registry correction work.
- Delete queues, budget maintenance, and payroll control are not default auditor tasks.
- Final approval remains outside the audit role.

# 3. Standard Daily Operating Procedure

1. Open the assigned audit task and confirm the expected review scope.
2. Review the assessment notes, registry record, supporting documents, and claims context before deciding anything.
3. Check whether the case can be defended from the recorded evidence and workflow history.
4. Return the case with precise reasons if material gaps or contradictions remain.
5. Advance the case only when the audit trail and evidence package support it.

# 4. Module Guidance

## 4.1 Audit Task Review

Audit work begins with the assigned task because the task history shows what previous roles believed they completed.

### Standard Steps

1. Read the task history and prior role comments before reviewing source records.
2. Identify the key control points that should have been satisfied by earlier stages.
3. Use the registry and document viewer to verify that the evidence aligns with the assessment narrative.
4. Record exceptions clearly so the prior role can correct them without guesswork.
5. Update task state only after the audit conclusion is justified.

### Control Points

- Audit comments should identify specific gaps, not vague dissatisfaction.
- Avoid reopening resolved issues unless the evidence truly contradicts the earlier outcome.
- Keep the distinction between review and rework clear.

## 4.2 Evidence and Calculation Assurance

Auditors are expected to test coherence across inputs, outputs, and supporting evidence, even when they are not the primary calculation owners.

### Standard Steps

1. Review benefit-related fields and calculator context to confirm that the assessed outcome is plausible.
2. Where a simple supporting field is clearly wrong and the control is exposed, correct it through the governed UI or return it with explanation.
3. Check the document set, file location, and application status for consistency with the stage claimed by the task.
4. Use claims visibility to understand whether the case has financial implications that require extra caution.
5. Return the case if audit concerns remain material.

### Control Points

- Do not convert an audit into undocumented re-engineering of the whole case.
- Where edit controls are limited, return the case with a precise correction request.
- Financial implications should be noted explicitly for the approver.

# 5. Governance and Control Rules

- Use audit to confirm control quality, not to hide uncertainty.
- Return cases with exact actionable reasons.
- Use evidence, registry detail, and workflow history together when judging readiness.
- Record file movement accurately when custody changes during review.
- Prepare approval-stage reviewers to understand both the strengths and the residual risks of the case.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Case passes audit | Move it to the Approver with a clear note that audit checks were completed. |
| Assessment logic or source data is still weak | Return it to the Assessor or the earlier handling role with specific corrections. |
| Supervisory or policy judgment is needed | Escalate to OC/Pension-equivalent supervision. |
| Technical defect or access anomaly affects the review | Escalate to administrator or support with the exact failing scenario. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Documents do not support the assessment note | Return the case and identify the missing or conflicting evidence. |
| Application status and task history disagree | Reconcile the workflow trail before advancing the case. |
| Registry control needed but not exposed | Document the required correction and route the case back through the governed path. |
| Claims exposure appears unusual | Flag it clearly so the approver sees the financial context. |

# 8. Working Checklist

- Read task history before judging the case.
- Test the case against evidence, not assumption.
- Return cases with specific reasons and required actions.
- Keep approval-stage users informed about material risks or anomalies.
- Do not let audit comments become ambiguous.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
