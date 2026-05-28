# UPS PensionsGo Project Proposal

**System:** UPS PensionsGo  
**Institution:** Uganda Prisons Service  
**Document Type:** Current-State Project Proposal  
**Version Manifest:** `app_version.json` -> `1.0.0` / build `20260401.1` / schema `5.2.2`  
**Prepared On:** 2026-05-28  
**Preferred Deliverables:** `docs/PensionApp_Project_Proposal.docx`, `docs/PensionApp_Project_Proposal.pdf`

## Proposal Snapshot

| Field | Value |
| --- | --- |
| Project title | UPS PensionsGo - Stabilization, Rollout, and Governance Hardening Proposal |
| Current platform baseline | Implemented web application with 41 HTML pages, 50 JavaScript files, 264 API endpoints, and 83 schema tables |
| Proposal purpose | Support continued rollout, stabilization, governance hardening, and maintainable long-term operation |
| Planning horizon | Immediate, near-term, and medium-term implementation windows from the current baseline |
| Audience | Institutional leadership, project sponsors, implementation leads, ICT operations, and the pensions function |
| Delivery model | Controlled improvements on top of the existing production-ready baseline |

## Executive Summary

This proposal is based on the current implemented UPS PensionsGo platform, not a greenfield concept. The system already provides pension intake, workflow progression, pension-file registry control, payroll uploads, claims and arrears handling, messaging and live chat, feedback workflows, public guidance, pensioner self-service, and administrative governance.

The project need now is different from the original build phase. The priority is to stabilize, document, test, govern, and operationalize the implemented platform so it remains dependable as adoption grows. The next phase should therefore focus on release discipline, user enablement, operational governance, testing, support readiness, and reduction of technical debt in high-risk shared components.

This proposal defines that next phase. It recommends a structured program of stabilization and institutional rollout rather than a new build-from-scratch effort.

## 1. Current Baseline

The repository already contains a broad functional platform with the following implemented areas:

- staff-due intake and application queueing
- workflow tasks, comments, alerts, and completion handling
- pension file registry and linked document control
- file movement and life-certificate management
- payroll uploads, cycle replacement, payment-register handling, suspensions, retained-payment review, and gratuity schedule analysis
- claims, arrears, accountability, and budget support
- direct messaging, broadcast messaging, live chat, notification queues, and configurable notification sounds
- feedback workflows, FAQ, terms, and podcast content
- pensioner dashboard, lookup-consent controls, pensioner account administration, and pensioner death reporting
- users, roles, public settings, geolocation/session settings, imports, exports, backups, restores, cleanup, app-version management, and system health diagnostics

The proposal therefore starts from an implemented baseline that already has institutional value.

## 2. Problem Statement for the Next Phase

The platform exists, but long-term value depends on how the next phase is handled. Without a structured stabilization and rollout phase, the institution faces the following risks:

| Risk area | Likely impact |
| --- | --- |
| Informal release handling | Schema drift, unclear deployment state, and harder recovery during change |
| Limited automated regression coverage | Higher risk of breaking critical workflows as the system evolves |
| Incomplete user enablement | Underuse of implemented features and persistence of manual workarounds |
| Documentation drift | Teams operating from outdated understanding of what the system actually does |
| Monolithic shared runtime areas | Slower maintenance and higher risk when modifying common logic |
| Weak support runbooks | Delayed response to production issues, diagnostics, and user access problems |

## 3. Proposal Goal

To transition UPS PensionsGo from an implemented application baseline into a fully institutionalized operational platform supported by current documentation, release discipline, user enablement, governance controls, and sustainable technical maintenance practices.

## 4. Proposed Objectives

| Objective | Expected result |
| --- | --- |
| Stabilize the release baseline | Schema, documentation, and generated deliverables stay aligned with the codebase |
| Strengthen support and governance | Admin and support teams can diagnose, restore, and govern the system consistently |
| Improve maintainability | High-risk shared modules are documented and progressively modularized |
| Improve delivery safety | Critical workflows gain regression coverage and clearer API understanding |
| Expand institutional adoption | Staff, supervisors, and support teams use the implemented system more consistently |
| Preserve implementation knowledge | Current-state docs, ERDs, manuals, and decks remain available and rebuildable from the repo |

## 5. Proposed Workstreams

### 5.1 Documentation and Knowledge Management

Scope:

- refresh system, technical, user, concept, and proposal documents
- regenerate Word and PDF deliverables from source markdown
- keep the ERD pack synchronized with the maintained schema
- refresh presentation decks and handouts used in onboarding and review

Expected result:

- one dependable documentation baseline tied to the current repo

### 5.2 Release and Schema Discipline

Scope:

- keep `database/schema.sql` aligned to runtime-created tables
- reduce hidden schema drift
- prepare for more formal migration handling over time

Expected result:

- clearer release confidence and easier environment reconciliation

### 5.3 Quality and Testing

Scope:

- prioritize automated tests around login/session handling, registry lifecycle, payroll upload flows, claims/accountability, and pensioner restrictions
- document critical workflow expectations for manual verification

Expected result:

- reduced regression risk in the highest-value operational paths

### 5.4 Support and Governance Readiness

Scope:

- strengthen admin runbooks around settings, public visibility, notification controls, backup/restore, imports/exports, recycle-bin operations, pensioner lifecycle reporting, and system health diagnostics
- standardize first-response checks for user-facing problems

Expected result:

- faster, more consistent issue handling and governance action

### 5.5 Technical Debt Reduction

Scope:

- progressively modularize high-risk shared runtime areas
- reduce confusion from stale aliases, legacy compatibility traces, and duplicated intent

Expected result:

- lower maintenance overhead and safer future enhancement work

## 6. Proposed Deliverables

| Deliverable | Description |
| --- | --- |
| Refreshed documentation suite | Current markdown, DOCX, and PDF references aligned to the implemented system |
| Refreshed ERD pack | Full and domain ERDs generated from the maintained schema |
| Support and governance runbooks | Admin/support-facing guidance for recurring operational tasks |
| Regression-priority matrix | Agreed list of workflows to cover first with automated or structured manual tests |
| Release-alignment checklist | Practical checklist covering schema, docs, versioning, and generated outputs |
| Refreshed onboarding decks | Technical and user briefing materials aligned to the current system |

## 7. Delivery Approach

The recommended approach is incremental and practical:

1. Start from the current implemented baseline.
2. Close obvious schema/documentation drift first.
3. Refresh user/admin guidance and operational runbooks.
4. Prioritize regression coverage and release-safety improvements.
5. Continue modularization and cleanup without disrupting active operations.

This should be managed as a controlled improvement program rather than a large redesign exercise.

## 8. Proposed Delivery Windows

The table below uses relative windows instead of fictional greenfield dates.

| Window | Focus | Indicative outputs |
| --- | --- | --- |
| Immediate: 0-30 days | Documentation, ERD alignment, schema completeness, generated deliverables | Refreshed docs, updated schema baseline, regenerated Word/PDF/PPTX artifacts |
| Near-term: 30-90 days | Support runbooks, release checklist, regression priorities, user enablement | Admin/support guides, priority test matrix, onboarding refresh |
| Medium-term: 90-180 days | Modularization, selective automation, cleanup of stale compatibility traces | Safer shared runtime structure and lower maintenance risk |

## 9. Governance and Ownership

| Role | Responsibility |
| --- | --- |
| Project sponsor | Endorse the stabilization and institutionalization direction |
| Pensions business owner | Confirm workflow priorities, policy handling, and operational acceptance |
| Technical lead | Own implementation sequencing, release safety, and technical quality |
| ICT operations lead | Own hosting, access administration, backups, restore readiness, and support escalation |
| Support/admin leads | Own runbooks, user enablement, and first-response issue handling |
| Data stewards | Help validate imports, registry quality, and operational data expectations |

## 10. Resource Categories

This proposal intentionally avoids outdated or speculative budget figures. The required resource categories are:

- documentation and knowledge-management effort
- software engineering time for stabilization and modularization
- QA and testing effort
- ICT operations effort for release, backup, and support readiness
- training and change-management support
- governance time for review and sign-off

Detailed budgeting should be produced through the institution's current planning process, using the implemented baseline rather than the earlier proposal assumptions.

## 11. Success Measures

| Result area | Illustrative measure |
| --- | --- |
| Documentation quality | Generated outputs match the current repo and schema baseline |
| Release confidence | Fewer surprises from schema drift or missing generated artifacts |
| Support readiness | Faster triage for login, access, registry, payroll, and diagnostics issues |
| Technical maintainability | Reduced dependency on large shared files for unrelated changes |
| Adoption quality | More consistent use of implemented governed workflows instead of manual workarounds |

## 12. Key Assumptions

- Uganda Prisons Service will continue to treat UPS PensionsGo as the strategic pension operations platform.
- The next phase will build on the existing codebase rather than replace it.
- Documentation, release discipline, and user enablement will be treated as delivery work, not optional afterthoughts.
- Technical debt reduction will be sequenced to protect live operations.

## 13. Recommendation

Approval is recommended for a current-state improvement phase focused on:

- documentation refresh and rebuildability
- schema and release alignment
- support and governance readiness
- regression-risk reduction
- progressive modularization of high-risk shared logic

This is the most practical next step because it protects the value already created in the implemented platform while reducing avoidable operational and maintenance risk.

## 14. Conclusion

UPS PensionsGo has already moved beyond concept. The institution now has a real platform with meaningful operational reach. The correct project response is therefore to strengthen and institutionalize that platform, not to restart the conversation as if implementation has not happened.

This proposal provides that direction. It treats the existing system as the baseline and focuses the next phase on the disciplines that make an implemented system dependable: alignment, governance, testing, documentation, support readiness, and maintainable evolution.

## Appendix A. Summary Improvement Themes

- keep schema and runtime behavior aligned
- keep documentation rebuildable from the repo
- make support and governance actions easier to execute correctly
- reduce regression risk in critical workflows
- gradually reduce coupling in shared runtime code

## Appendix B. Suggested Review Questions

- Which live workflows are most exposed if regression coverage is not improved soon?
- Which shared runtime responsibilities should be modularized first?
- Which support actions most need step-by-step runbooks?
- Which documentation artifacts are essential for institutional continuity and onboarding?
