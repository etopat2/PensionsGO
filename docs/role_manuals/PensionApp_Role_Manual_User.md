# UPS PensionsGo Role Manual: User

**System:** UPS PensionsGo  
**Role Key:** `user`  
**Role Label:** User  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_User.docx`, `docs/role_manuals/PensionApp_Role_Manual_User.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | General internal users, enquiry staff, supervisors, trainers, and support teams |
| Current role purpose | General internal access for enquiry, status checking, claims visibility, and use of reference tools. |
| Default landing page | Dashboard |
| Role type | General internal access role |
| Current access note | This is a lighter internal role. It supports visibility, enquiry, and limited guided actions rather than full workflow ownership. |

# 1. Purpose and Role Position

The User role provides general internal access to shared visibility and reference workspaces. It is appropriate where a user needs to review dashboard information, inspect application status, view claims context, or use pension reference tools without taking ownership of the workflow queue.
Users should treat this role as an enquiry and support role. If a required action is not visible, that is usually expected and the task should be escalated to the role that owns the governed workflow step.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| Dashboard | Review live workload, analytics, workflow pressure, compliance signals, and governed management views. |
| Pension File Registry | Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized. |
| Application Status | Review where a case sits in the workflow and confirm current progress state. |
| Claims | Review arrears exposure, payments, accountability state, suspensions, and related analytics. |
| Benefits Calculator | Estimate service-based pension outputs using the configured retirement formulas. |
| FAQs | Read guided answers about the platform, records, claims, and pensioner support. |
| About | Read the current product overview and service positioning information. |
| My Profile | Review personal account information, role label, and current account details. |

## 2.2 Support and Reference Workspaces

| Menu / Workspace | Typical purpose |
| --- | --- |
| Budget Forecast | Review or manage arrears and pension forecast views by financial period. |
| Podcast | Watch guided pension information videos and official explanatory content. |
| Document Viewer | Open linked documents in a controlled preview workspace. |
| Edit Profile | Maintain permitted personal account fields and credentials. |

## 2.3 Default Governed Capabilities

| Permission / Control | Capability | Operational meaning |
| --- | --- | --- |
| `registry.benefits.monthly_salary.edit` | Maintain monthly salary input | Update the salary input that feeds current benefits calculations where the edit control is exposed. |
| `claims.arrears.view` | View claims and arrears | Use the claims workspace for visibility, analytics, exports, and case-level review. |

## 2.4 Work Normally Outside This Role

- This role does not own normal task workflow, delete queues, bulk imports, or supervisory approvals.
- Claims management, registry editing, payroll control, and budget maintenance are not default user responsibilities.
- Messages, workflow routing, and Settings actions are outside the standard role surface.

# 3. Standard Daily Operating Procedure

1. Sign in and review the dashboard or application-status view for the information you need.
2. Open the registry or claims workspace only for controlled enquiry and reference.
3. Use the Benefits Calculator, FAQs, About, or Podcast tools when you need guidance or context.
4. If the workflow requires an action you cannot perform, escalate it to the responsible role rather than searching for a workaround.
5. Keep any notes or escalations concise and specific so the owning role can act quickly.

# 4. Module Guidance

## 4.1 Dashboard and Status Enquiry

The shared dashboard and application-status pages are the primary work areas for general internal visibility.

### Standard Steps

1. Open the dashboard and use filters or search before drawing a conclusion from what you see.
2. Use application status to confirm the stage of a case and whether it appears active, pending, or completed.
3. Where you need more detail, open the registry or claims workspace for reference.
4. If the case needs action outside your role, document the issue and escalate it.
5. Avoid treating dashboard snapshots as the final record; confirm in the source workspace where needed.

### Control Points

- Visibility is not the same as authority to change data.
- Use filters carefully so you do not report the wrong scope.
- Escalate operational actions to the owning role.

## 4.2 Reference Tools and Guided Support

This role can use the benefits calculator and public-guidance pages to support internal enquiry and explanation.

### Standard Steps

1. Use the benefits calculator for indicative pension-output understanding only.
2. Use FAQs, About, and Podcast content when you need current guided explanations of platform behavior or pension topics.
3. Open documents through the document viewer only for approved enquiry or support work.
4. Use profile and account-update pages to keep your own account details current.
5. Escalate anything that requires a governed data or workflow change.

### Control Points

- Calculator results are guidance unless confirmed through the governed workflow.
- Reference pages do not override official decisions or workflow stages.
- Document access must remain tied to legitimate work purposes.

# 5. Governance and Control Rules

- Use the role for enquiry, visibility, and guided support, not for workflow ownership.
- Do not assume a missing action is an error; it is often a role boundary.
- Confirm source data before escalating dashboard observations.
- Treat viewed documents and claims data as sensitive records.
- Escalate to the correct workflow or supervisory role for governed changes.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Case needs workflow action | Route it to the role that owns the current workflow step. |
| Registry or staff data needs correction | Escalate to a registry-edit or staff-due edit role. |
| Financial or claims mutation is required | Escalate to a role with claims-management authority. |
| Access or visibility seems wrong | Escalate to an administrator or support contact. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Edit controls are absent | Treat that as expected unless an administrator confirmed you should have broader rights. |
| Dashboard numbers seem inconsistent | Recheck filters, date scope, and the source module before escalating. |
| Application stage is unclear | Use the application-status and registry views together, then escalate if still ambiguous. |
| Calculator output differs from expectation | Treat it as indicative and escalate to the assessment workflow for formal review. |

# 8. Working Checklist

- Use the dashboard for visibility and source modules for confirmation.
- Respect role boundaries when actions are not exposed.
- Use reference tools for guidance, not final decisions.
- Escalate governed changes to the correct owner.
- Handle viewed records as sensitive information.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
