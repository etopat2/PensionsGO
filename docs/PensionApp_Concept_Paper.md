# UPS PensionsGo Concept Paper

**System:** UPS PensionsGo  
**Institution:** Uganda Prisons Service  
**Document Type:** Current-State Concept Paper  
**Version Manifest:** `app_version.json` -> `1.0.0` / build `20260401.1` / schema `5.2.2`  
**Prepared On:** 2026-05-28  
**Preferred Deliverables:** `docs/PensionApp_Concept_Paper.docx`, `docs/PensionApp_Concept_Paper.pdf`

## Document Profile

| Field | Value |
| --- | --- |
| System | UPS PensionsGo |
| Institutional owner | Uganda Prisons Service |
| Business domain | Pension administration, registry control, workflow, payroll visibility, and pensioner service |
| Current delivery model | Implemented secure web platform with controlled public and pensioner-facing surfaces |
| Technology direction | PHP, MySQL/MariaDB, HTML, CSS, JavaScript, PWA shell, document/PDF generation tooling |
| Current implementation footprint | 41 HTML pages, 50 JavaScript files, 264 API endpoints, and 83 maintained schema tables |
| Primary beneficiaries | Pension administrators, supervisors, registry staff, finance staff, pensioners, leadership, and support teams |

## Executive Summary

UPS PensionsGo began as a modernization response to fragmented pension processing, limited file visibility, spreadsheet-heavy follow-up, and weak institutional traceability. It has now matured into an implemented platform that centralizes retirement intake, pension workflow progression, file registry governance, claims and arrears handling, payroll reconciliation, communications, live chat, public guidance, lifecycle reporting, and pensioner self-service in one role-aware application.

This concept paper is therefore no longer framed as a greenfield software idea. It is a current-state concept note that explains why the platform matters, what has already been implemented, the institutional value it now provides, and the operational priorities that should guide its continued rollout and hardening.

The central concept remains unchanged: pension administration is not a single transaction. It is a chain of connected processes that require a common source of truth, governed handoffs, role-based controls, and dependable reporting. The implemented platform gives Uganda Prisons Service a strong foundation for that operating model.

## 1. Institutional Context

Pension administration within Uganda Prisons Service is a sensitive, document-heavy function. It affects retired officers, next of kin, finance teams, records officers, supervisors, and senior leadership. The work touches retirement readiness, application processing, benefits calculations, file creation, registry tracking, payroll visibility, arrears accountability, and pensioner support.

In a manual or fragmented environment, the following weaknesses typically appear:

- records are duplicated across paper files, spreadsheets, and user-maintained notes
- the exact stage or owner of a case is unclear
- movement of physical or controlled files is hard to reconstruct
- payroll mismatches are discovered late
- deletion and correction actions are not consistently governed
- pensioners rely on informal channels for updates
- reporting depends on manually prepared summaries

UPS PensionsGo addresses that context by making the pension process visible, structured, and attributable across its major stages.

## 2. Concept Statement

The core concept behind UPS PensionsGo is simple:

**A pension administration platform should connect intake, workflow, registry, payroll, claims, communications, and governance inside one controlled operating environment.**

That concept is now implemented through:

- a shared pension workflow from `tb_staffdue` into registry and financial follow-through
- a role-aware web application for staff, supervisors, and administrators
- a controlled pensioner portal for limited self-service
- public guidance, feedback, FAQ, terms, and podcast surfaces
- direct messaging, broadcasts, live chat, notification queues, and configurable notification sounds
- audit, logging, deletion, restore, backup, import, export, app-version, public-settings, and diagnostic controls

## 3. Current Implemented Solution

The implemented platform now covers the following solution areas.

| Solution area | Current implemented capability |
| --- | --- |
| Retirement intake | Staff due capture, status progression, application queueing, and controlled delete requests |
| Workflow control | Tasks, alerts, comments, delegation, completion queue, and workflow logs |
| Registry governance | Pension file registry, linked documents, file movement, delete queue, recycle bin, and box allocation summary |
| Payroll visibility | Payroll uploads, cycle replacement, payment-register attachment, matched/unmatched review, monthly status, suspension uploads, retained-payment review, downloadable templates, and gratuity schedule analysis |
| Claims and arrears | Arrears ledger, payments, allocations, accountability submissions, recent-payment review, and export builders |
| Service channels | Messaging, broadcasts, live chat, notification sound controls, feedback workflows, public content, and podcast publishing |
| Pensioner services | Pensioner dashboard, lookup-consent controls, profile updates, compliance and claims visibility, indexed documents, pensioner account administration, and death reporting |
| Operations and governance | Settings, public settings, users, roles, active sessions, geolocation/session settings, analytics digests, backups, restores, imports, exports, storage, cleanup, app-version management, and system health diagnostics |

## 4. Why the Concept Still Matters

Even though the application is already implemented, the concept remains strategically important because it provides the institutional logic for keeping the platform coherent.

### 4.1 Operational Value

The platform reduces the need for disconnected manual coordination by:

- giving teams a common record set
- making handoffs visible
- improving search and retrieval
- linking payroll and arrears data back to authoritative records
- reducing ambiguity around task ownership

### 4.2 Governance Value

The platform strengthens accountability through:

- role-based access
- delete-request and restore flows
- audit and system logs
- workflow logs and task comments
- active-session review
- incident-resolution tracking in system health diagnostics

### 4.3 Service Value

The platform improves service delivery by:

- giving pensioners a controlled self-service view
- providing public guidance through FAQ, terms, and content pages
- structuring feedback into a governed workflow
- reducing dependence on informal, non-traceable channels

### 4.4 Management Value

The dashboard and reporting surfaces now support:

- operational visibility into claims, payroll, life certificates, and workflow
- follow-up on backlogs and delays
- export-ready data for reporting and review
- clearer visibility into system health and notification problems

## 5. Conceptual Architecture

The implemented concept is best understood through its operating spine:

1. **Identify and capture** staff due for retirement.
2. **Route and track** work through statuses, tasks, comments, and alerts.
3. **Establish and govern** the pension file registry as the authoritative record layer.
4. **Link financial state** through payroll, suspensions, claims, arrears, and accountability.
5. **Support users and pensioners** through messaging, live chat, content, feedback, notifications, and self-service.
6. **Govern the platform** through settings, roles, diagnostics, backups, restores, imports, exports, versions, and logs.

This architecture matters because it keeps business, records, and governance concerns connected instead of allowing each to drift into a separate tool.

## 6. Current Design Principles

The implemented platform expresses the following principles:

- one governed source of truth for core pension records
- role-aware access instead of blanket visibility
- workflow visibility by default
- recoverability for sensitive delete actions
- operational analytics close to the work itself
- support for both staff-facing and pensioner-facing service channels
- documentation and ERDs maintained alongside the codebase

## 7. Current Gaps and Conceptual Risks

The platform is broad and already useful, but some conceptual risks remain.

| Risk area | Why it matters |
| --- | --- |
| Large shared bootstrap in `backend/config.php` | Too much logic is concentrated in one file, increasing complexity and release risk |
| Runtime schema hardening in PHP | Structural changes can be hidden inside request paths instead of formal migrations |
| Large API surface | Without a published API catalogue, change safety depends heavily on team familiarity |
| Minimal automated tests | Broad functional coverage without regression depth is a long-term maintenance risk |
| Legacy compatibility traces | Older table names and compatibility logic can confuse new maintainers and documentation consumers |

## 8. Next-Phase Concept Priorities

The concept now shifts from “build the platform” to “stabilize and institutionalize the platform.”

Recommended next-phase priorities:

- formalize schema migration discipline
- strengthen automated regression coverage
- document the most important APIs and permission rules
- modularize shared runtime concerns
- continue aligning user guidance and technical documentation with the implemented system
- expand training and change-support materials for operational teams

## 9. Sustainability Implications

UPS PensionsGo should now be treated as an institutional asset rather than a one-off implementation.

Sustainability requires:

- named business ownership within the pensions function
- named technical ownership for releases, access, and support
- repeatable backup and restore practice
- ongoing permission review
- maintenance of documentation, ERDs, and onboarding materials
- controlled release planning when schema or workflow logic changes

## 10. Recommendation

The concept remains valid and should continue to guide the platform:

- as the statement of why UPS PensionsGo exists
- as the institutional rationale for keeping domains integrated
- as the basis for future rollout, hardening, and governance decisions

The platform has already crossed the threshold from concept to implementation. The recommended action is therefore not to re-approve the idea as a greenfield system, but to continue institutionalizing it as the authoritative pension operations environment.

## 11. Conclusion

UPS PensionsGo now demonstrates the practical value of its original concept. It has moved pension administration away from fragmented coordination and toward a governed digital operating model that joins workflow, registry, finance, communications, and service channels.

The work ahead is not to invent a new concept, but to strengthen the one already implemented: improve release discipline, testing, documentation, training, and long-term governance so the platform remains dependable as usage grows.

## Appendix A. Current Module Footprint

| Module | Current focus |
| --- | --- |
| Workflow and Tasks | Assignment, routing, comments, alerts, completion queue |
| Claims and Intake | Staff due records, applications, status progression, arrears analytics |
| Registry and Documents | File registry, document uploads, movement tracking, delete governance |
| Payroll and Suspensions | Payroll uploads, payment registers, suspensions, gratuity schedules |
| Arrears and Accountability | Payments, ledger, allocations, accountability files |
| Messaging and Notifications | Direct messages, broadcasts, queue processing, digests |
| Feedback and Content | Feedback workflows, FAQ, terms, podcast/video content |
| Pensioner Self-Service | Dashboard, compliance, claims visibility, profile update flow, lookup consent, account administration, death reporting |
| Administration and Operations | Users, roles, settings, public settings, storage, imports, exports, backups, restores, notifications, diagnostics |

## Appendix B. Conceptual Success Indicators

- stronger case visibility across the pension workflow
- higher confidence in authoritative registry data
- faster detection of payroll and arrears exceptions
- better governance over deletion, restore, and sensitive admin actions
- better pensioner communication and support quality
- lower dependence on informal, non-traceable coordination channels
