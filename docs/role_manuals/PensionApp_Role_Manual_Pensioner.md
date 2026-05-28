# UPS PensionsGo Role Manual: Pensioner

**System:** UPS PensionsGo  
**Role Key:** `pensioner`  
**Role Label:** Pensioner  
**Repository Snapshot Date:** 2026-05-28  
**Generated Deliverables:** `docs/role_manuals/PensionApp_Role_Manual_Pensioner.docx`, `docs/role_manuals/PensionApp_Role_Manual_Pensioner.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Pensioners, pensioner-support staff, trainers, and service desk teams |
| Current role purpose | Beneficiary self-service access for personal pension visibility, limited profile updates, and consent-based pensioner lookup. |
| Default landing page | Pensioner Dashboard |
| Role type | Self-service beneficiary role |
| Current access note | Portal features can be turned on or off by the pensions office. A pensioner may therefore see fewer sections than another user if claims, documents, or lookup access are currently disabled. |

# 1. Purpose and Role Position

The Pensioner role is a self-service role for beneficiaries. It provides a controlled dashboard showing pension profile information, application progress, benefits snapshot, compliance status, claims visibility, indexed documents, and lifecycle information when the linked record exists.
The role is intentionally limited. Pensioners should use the dashboard for information and permitted updates, but any formal correction or administrative decision still belongs to the pensions office and governed staff workflow.

# 2. Access Scope

## 2.1 Primary Working Pages

| Menu / Workspace | How this role uses it |
| --- | --- |
| Pensioner Dashboard | Review pension record, application stage, benefits summary, compliance state, claims, and indexed documents. |
| Find Pensioners | Search the consent-based pensioner directory and review contact details that are allowed to be shown. |
| My Profile | Review personal account information, role label, and current account details. |
| Edit Profile | Maintain permitted personal account fields and credentials. |

## 2.2 Support and Reference Workspaces

| Menu / Workspace | Typical purpose |
| --- | --- |
| Benefits Calculator | Estimate service-based pension outputs using the configured retirement formulas. |
| Podcast | Watch guided pension information videos and official explanatory content. |
| Document Viewer | Open linked documents in a controlled preview workspace. |

## 2.3 Pensioner Portal Features

| Feature | Current behavior | What the user should expect |
| --- | --- | --- |
| Profile | Always part of the dashboard shell | Shows core personal and registry-linked identity details where available. |
| Application progress | Shown from the pensioner dashboard | Lets the pensioner follow case stages and progression steps. |
| Benefits summary | Shown from the pensioner dashboard | Displays calculated or recorded benefits context for the linked record. |
| Compliance | Shown from the pensioner dashboard | Displays payroll status, account standing, and life-certificate state. |
| Claims | Shown only when the portal setting enables claims | Displays outstanding arrears, claim entries, and recent balances where available. |
| Documents | Shown only when the portal setting enables documents | Displays indexed record documents for the linked pension file. |
| Lifecycle | Shown from the pensioner dashboard | Provides retirement-category and status context for the record. |
| Pensioner lookup | Shown only when directory access is enabled | Allows searching other pensioners who consented to be visible in the directory. |
| Consent controls | Managed through dashboard-linked profile settings | Controls whether the pensioner appears in the lookup directory. |
| Account update | Available through profile and edit-user pages | Supports limited personal account or contact changes. |

## 2.4 Work Normally Outside This Role

- The pensioner role does not access staff workflow, task queues, claims posting, registry editing, or internal governance pages.
- Some dashboard panels may remain hidden if the pensions office has disabled that feature.
- Formal corrections still require pension-office review even when profile updates are submitted.

# 3. Standard Daily Operating Procedure

1. Sign in and open the pensioner dashboard.
2. Review the dashboard summary cards and open the tab that matches the information you need.
3. Use profile-related controls to update only the fields the portal allows you to change.
4. If claims, documents, or lookup are hidden, treat that as a policy setting or account-state condition rather than a normal user error.
5. Escalate unresolved problems to the pensions office or support route shown by the platform.

# 4. Module Guidance

## 4.1 Pensioner Dashboard

The pensioner dashboard is organized into clearly separated work areas such as profile, application, benefits, compliance, claims, and lifecycle so that the user can review information without entering staff-only modules.

### Standard Steps

1. Open the dashboard and wait for the linked pension record to load fully.
2. Use the visible tabs to review profile details, application progress, benefits information, and compliance status.
3. Read any warning or information banners carefully because they often explain life-certificate, claims, or account issues.
4. If a section is not visible, check whether it may be disabled by the pensions office or unavailable because the record is not yet linked.
5. Use the dashboard as the main reference point instead of attempting to access staff-only pages.

### Control Points

- Dashboard information depends on a correctly linked pensioner record.
- Missing tabs are often policy- or linkage-related, not necessarily a browser problem.
- Do not share sensitive personal information from the dashboard casually.

## 4.2 Profile, Contact, and Lookup

Pensioners can manage limited personal details and directory consent through the self-service tools.

### Standard Steps

1. Open the profile or account-update page and review the existing details before changing anything.
2. Update only the fields the portal exposes, such as contact-focused items and approved profile fields.
3. If the dashboard restricts certain bereavement-related or contact fields, respect that restriction and contact support if a formal change is needed.
4. Use the Find Pensioners page only for legitimate contact lookup and only where the directory is enabled.
5. Set visibility or consent carefully because only pensioners who agree to be visible should appear in the directory.

### Control Points

- Directory results respect consent settings.
- Some profile fields may be intentionally read-only in sensitive record states.
- Account updates do not override formal pensions-office records automatically in every case.

## 4.3 Claims, Documents, and Compliance

Where enabled, the pensioner dashboard shows claims, indexed documents, and compliance guidance such as life-certificate status or payroll standing.

### Standard Steps

1. Review claims or balances only within the dashboard and use the displayed explanations to understand what the figures mean.
2. Open indexed documents through the viewer when available and keep them confidential.
3. Check compliance or life-certificate warnings promptly and follow the guidance given by the pensions office.
4. Use the benefits calculator and media-centre tools only for guidance and understanding, not as a substitute for official decisions.
5. If a document or claim entry seems wrong, report it through the official support channel rather than trying to change it yourself.

### Control Points

- Claims and documents may be hidden by current portal settings.
- Dashboard figures are informational; formal corrections still require staff review.
- Life-certificate reminders should be taken seriously and acted on promptly.

# 5. Governance and Control Rules

- Use the pensioner dashboard as the main self-service workspace.
- Keep credentials private and do not share account access.
- Treat dashboard documents and financial information as confidential.
- Respect directory consent and privacy expectations.
- Escalate formal corrections to the pensions office instead of trying to bypass portal limits.

# 6. Handoffs and Escalation

| Situation | Required next step |
| --- | --- |
| Portal login fails | Contact the pensions office or support route to confirm account linkage, portal availability, or credentials. |
| A record item looks incorrect | Report it for staff review through the governed support path. |
| Claims, documents, or lookup are missing | Check whether the feature may be disabled, then escalate if needed. |
| Life-certificate reminder appears | Follow the official submission process promptly and confirm with the pensions office if guidance is unclear. |

# 7. Common Issues and First Responses

| Issue | Recommended first response |
| --- | --- |
| Dashboard says the record is unavailable | The account may not yet be linked to a pensioner record; contact support for linkage review. |
| Claims or documents are missing | Those sections may be disabled or no data may be linked yet. |
| Lookup directory shows no result | Only pensioners who consented to be visible can appear in the directory. |
| Login is blocked | Portal access may be disabled administratively or the account may need support review. |

# 8. Working Checklist

- Use the dashboard first for all pensioner self-service work.
- Review compliance and life-certificate messages promptly.
- Update only the fields the portal allows.
- Keep documents and financial information private.
- Contact the pensions office when formal correction is needed.

# 9. Related References

- `docs/PensionApp_User_Admin_Manual.md`
- `docs/PensionApp_System_Documentation.md`
- `docs/ERD.pdf`
- `docs/role_manuals/README.md`
