# PensionApp User and Admin Manual

**System:** UPS PensionsGo  
**Description:** Current operational guide for staff, supervisors, administrators, support users, and pensioner-facing operators.  
**Version Manifest:** `app_version.json` -> `1.0.0` / build `20260401.1` / schema `5.2.2`  
**Repository Snapshot Date:** 2026-05-28  
**Preferred Deliverables:** `docs/PensionApp_User_Admin_Manual.docx`, `docs/PensionApp_User_Admin_Manual.pdf`

## Manual Profile

| Field | Value |
| --- | --- |
| Intended audience | Operational staff, supervisors, administrators, support teams, and trainers |
| Current application scope | Pension intake, workflow, registry, payroll, arrears, messaging, live chat, content, pensioner services, lifecycle reporting, and administration |
| Recommended use | Onboarding, day-to-day operations, governance support, and issue triage |
| Related references | `docs/PensionApp_System_Documentation.md`, `docs/PensionApp_Technical_Documentation.md`, `docs/ERD.pdf`, `docs/role_manuals/README.md` |

# 1. Introduction

UPS PensionsGo is the working platform for pension administration activities across retirement intake, claims follow-through, pension-file registry management, payroll reconciliation, arrears accountability, messaging, live chat, public support content, lifecycle reporting, and pensioner self-service.

This manual explains how the implemented application should be used in practice. It is written against the current system, not an earlier proposal baseline. It focuses on what users do, how modules are meant to be used, where governance controls apply, and what support teams should check first when something goes wrong.

Where a team needs role-specific step-by-step guidance, use the dedicated role manual suite in `docs/role_manuals/` alongside this general operational manual.

# 2. Who This Manual Is For

| User group | What to focus on |
| --- | --- |
| Clerks and intake staff | Staff-due capture, application handling, status updates, and record quality checks |
| OC/Pension and deputy supervisors | Dashboard oversight, task routing, delete governance, system priorities, and exception handling |
| Workflow officers | Write-up, file creation, data entry, assessment, audit, approval, and task progression |
| Registry and records staff | Pension file registry, document uploads, file movement, life certificates, and recycle-bin governance |
| Payroll and finance staff | Payroll cycle uploads, payment-register handling, suspensions, gratuity schedules, claims payments, and budget support |
| Administrators | Users, roles, permissions, public settings, active sessions, imports, exports, backups, restores, notifications, cleanup, and system health diagnostics |
| Support staff | Login issues, access issues, live-chat/message issues, data-state checks, and role-aware troubleshooting |
| Pensioner-support operators | Pensioner dashboard behavior, lookup consent rules, restricted profile-edit cases, account resets, and death-report follow-up |

# 3. Access, Roles, and General Rules

## 3.1 Role Awareness

Every protected area of the system is role-aware. A user only sees the pages and actions allowed by the current role and any extra permission overrides.

Core roles used by the system include:

| Role | Typical responsibility |
| --- | --- |
| Administrator | Global governance, settings, users, roles, imports/exports, backups/restores, notifications, cleanup, diagnostics |
| Clerk | Staff-due intake and verification support |
| OC/Pension | Operational control, routing, and data-management oversight |
| Deputy OC/Pension variants | OC/Pension-equivalent access where governance permits delegated control |
| Write-up Officer | Write-up preparation |
| File Creator | File creation and registry establishment |
| Data Entrant | Structured data capture and updates |
| Assessor | Benefit assessment and calculations |
| Auditor | Audit-stage review |
| Approver | Final approval-stage control |
| Pensioner | Limited self-service access |

## 3.2 Access Rules All Users Should Know

- Protected pages require a valid login session.
- Pensioner login can be enabled or disabled centrally by settings.
- Maintenance mode allows administrator access while blocking ordinary users.
- Sensitive administrative actions may trigger re-authentication.
- Data Management is restricted to administrators and OC/Pension-equivalent leadership roles.
- Seeing a menu item is not the final authority. The server also checks role and permission rules before acting.

## 3.3 Security Expectations

- Keep passwords private and never share accounts.
- Use approved devices and trusted browsers where possible.
- Respect timeout warnings and re-authentication prompts.
- Treat exports, uploads, documents, message attachments, and printed data as sensitive records.
- Use the governed workflow instead of informal shortcuts, especially for deletion, restoration, and payment-related actions.

# 4. Getting Started

## 4.1 Login

Users sign in through the `Login` page. The platform supports email or phone-based login, depending on the data stored for the account.

During sign-in, the system may apply:

- invalid credential checks
- lockout windows after repeated failures
- pensioner portal enable/disable rules
- maintenance mode restrictions
- password-expiry checks

If login fails:

1. Confirm the correct identifier and password.
2. Confirm whether the account is a pensioner account and whether pensioner login is enabled.
3. Confirm whether the system is in maintenance mode.
4. Escalate to an administrator or support lead if the account still cannot be used.

## 4.2 Session Behavior

The platform monitors inactivity and session state. Depending on settings and role, it may:

- limit concurrent sessions
- warn before inactivity timeout
- terminate older sessions after conflicts
- require password confirmation for sensitive admin actions

Users should save work promptly and avoid leaving sensitive pages open and unattended.

## 4.3 Navigation Pattern

Most secure pages follow the same pattern:

1. Open the page from the main navigation or dashboard shortcut.
2. Apply search and filter controls first.
3. Review cards, tables, or analytics.
4. Open a modal or detail panel for the selected record.
5. Submit changes through the provided form or workflow action.
6. Wait for confirmation before leaving the page.

# 5. Core Operational Modules

## 5.1 Dashboard

The `Dashboard` workspace is the shared operational cockpit. It provides quick insight into:

- claims exposure and period totals
- pensioner composition and demographics
- life-certificate compliance
- payroll coverage
- staff due pipeline
- file registry and file movement status
- workflow performance
- feedback management
- general statistics and controlled data management

Good practice:

- use dashboard figures as decision support, then confirm in the source module
- check filters before exporting or reporting numbers
- refresh a section before escalating unexpected totals

## 5.2 Staff Due for Retirement

`Staff Due for Retirement` is the entry point for retirement-bound staff records.

Typical tasks:

- add a new staff-due record
- review retirement and contact information
- update submission or application status
- attach supporting documents where the workflow permits
- request deletion through the governed staff-due delete flow

Important reminders:

- confirm identifiers such as `regNo`, supplier number, phone number, and retirement details before saving
- use workflow status tools instead of informal notes to move work forward
- do not bypass the delete-request process

## 5.3 Applications and Claims

Applications and claims are no longer a simple record list. The current claims workspace includes:

- arrears ledger review
- recent-payment logging
- bulk payment upload
- suspension upload history
- monthly gratuity schedule analysis
- strategic estate-expiry and full-pension-due views for privileged users
- export builders for custom arrears summaries

Users should:

- confirm claim type before recording a payment
- distinguish expected amount, paid amount, and remaining balance correctly
- submit accountability files promptly when required
- use bulk upload flows for structured batches instead of repeated manual entry

## 5.4 Pension File Registry

The pension file registry is the authoritative post-intake record.

Typical actions:

- search and open registry records
- update permitted record details
- review document index and linked metadata
- request registry deletion
- process approved deletion requests where authorized
- restore deleted items from the recycle bin where allowed

Good practice:

- confirm the correct file before editing
- use the governed edit forms rather than direct database-side changes
- keep linked documents properly classified and attached to the correct record

## 5.5 File Movement and Life Certificates

The records area includes two operationally sensitive flows:

### File movement

- record every file movement when custody changes
- include destination, reason, and expected return date where applicable
- close the movement when the file is returned

### Life certificates

- confirm the correct year before marking status
- treat `Submitted`, `Not Submitted`, and `Exempt` as distinct states
- verify file number, name, station, or phone before changing a record

## 5.6 Payroll, Suspensions, and Budgeting

Payroll and finance staff now work across several linked flows:

- upload a monthly payroll source file
- attach or replace a signed payment-register PDF
- replace a payroll cycle where authorized
- review matched and unmatched rows
- upload suspended payroll amounts
- upload gratuity schedules and review allocations
- review or export budget views

Operational rules:

- confirm month, year, quarter, and financial year before upload
- do not casually replace a payroll cycle
- review unmatched rows immediately after upload
- preserve source files and generated reports according to retention rules

## 5.7 My Tasks and Workflow Alerts

The `My Tasks` workspace is the day-to-day execution layer for routed work.

Users can:

- review assigned tasks
- add comments
- change task status
- inspect alerts
- process completion-queue items where permitted

Supervisory users can:

- assign and delegate work
- adjust due dates and priority
- review stalled or overdue work
- resolve alert pressure

Good practice:

- use workflow actions, not only comments, to move state
- keep comments factual and brief
- review alerts before they age into escalations

## 5.8 Messages and Broadcasts

The messages module supports:

- direct messages
- broadcast messages
- live chat and group chat
- presence/call support where enabled
- attachments
- unread tracking
- attachment viewing
- configurable notification sounds

Users should:

- keep message subjects clear and traceable
- use broadcasts only where role and purpose justify it
- attach only necessary files

Messages are part of the operational record and should be treated accordingly.

## 5.9 Feedback, FAQ, Terms, and Podcast

The support/content surface now includes:

- governed feedback intake and workflow handling
- FAQ management
- terms/policy clauses
- staff/public podcast video management

Feedback managers should:

- classify the issue correctly
- assign ownership when clear
- move the item through review, resolution, and closure with a useful summary

# 6. Pensioner Support Guidance

The pensioner portal currently exposes:

- profile summary
- application-progress view
- benefits snapshot
- payroll and compliance view
- claims and arrears summary
- lifecycle and indexed-document view
- controlled contact update flow
- lookup visibility and consent controls
- account administration and password resets by authorized staff
- pensioner death-report follow-up

Support staff should know:

- pensioner login may be disabled centrally
- pensioner lookup is consent-driven
- some fields are intentionally restricted in special lifecycle states
- restricted fields should be updated through the correct staff-side route, not forced through self-service

When supporting a pensioner:

1. Confirm the linked registry record.
2. Confirm current account status and portal visibility.
3. Check whether the requested field is under a restricted rule.
4. Use the registry or admin route if self-service should not apply.

# 7. Administrative Functions

## 7.1 Settings

The `Settings` menu opens the primary governance workspace.

Common functions:

- system status and diagnostics
- user and pensioner account management
- app settings and public settings
- role and permission governance
- active-session review
- backup and restore
- imports, exports, storage, and cleanup
- FAQ, terms, and podcast administration
- notification sound library and queue controls
- app-version and public-visibility settings

## 7.2 System Health Diagnostics

The `Settings` workspace includes a system health view driven by:

- `tb_system_logs`
- `tb_system_log_resolutions`
- notification queue status
- memory and disk pressure checks
- unresolved warning/error incident grouping

Administrators should use it to:

- inspect open incidents
- review notification delivery problems
- mark incidents acknowledged, resolved, or dismissed after real handling
- monitor whether the same subsystem keeps failing

## 7.3 Users, Roles, and Permissions

Good practice for account administration:

- assign the least-privileged role that still enables the user to work
- prefer role-level permissions over one-off user overrides
- review pensioner accounts separately from staff accounts
- use pensioner death reports as governed lifecycle notifications, not as informal registry edits
- use administrator accounts for governance, not routine case work

## 7.4 Data Management and Restore

The Data Management area includes controlled maintenance tools.

Key rules:

- registry deletion uses a request queue plus recycle-bin recovery model
- staff-due deletion uses a separate approval queue
- restore should only be used after confirming the record is legitimate and safe to reintroduce
- purge should follow policy, not convenience

## 7.5 Settings, Imports, Exports, and Backups

Administrators can control:

- branding and footer/public information
- session and password policy
- workflow timing
- notification behavior
- document and attachment rules
- storage cleanup thresholds
- pensioner portal visibility
- analytics and digest behavior
- geolocation/session behavior
- app version and public-service behavior

Operational guidance:

- back up before large imports, cleanup, or destructive actions
- review filter scope before export
- document important settings changes affecting access, retention, or security

# 8. Troubleshooting Guide

| Symptom | First checks | Escalation path |
| --- | --- | --- |
| User cannot log in | Credentials, lockout state, maintenance mode, pensioner-login setting, account role | Administrator or support lead |
| Menu is visible but action fails | Session, effective role, permission override, server-side policy | Administrator or OC/Pension lead |
| Dashboard numbers look wrong | Filters, refresh state, latest upload cycle, source module totals | Module owner or supervisor |
| Payroll totals do not match | Active cycle, month/year, unmatched rows, register upload, replacement history | Payroll lead |
| Registry item seems missing after deletion | Delete request state, recycle bin, restore eligibility | Authorized data-management user |
| Attachment or document issue | Allowed type, size, storage setting, viewer availability | Administrator or support |
| Pensioner cannot update contact details | Restricted lifecycle rule, consent setting, linked registry data | Registry support or administrator |
| Notification, live-chat, or digest issue | Queue state, email transport, sound setting, attachment storage, system health diagnostics | Administrator |

# 9. Operating Discipline and Handover

Users should apply the following habits consistently:

- update the correct record, not a similar-looking one
- use governed forms and workflow actions instead of workarounds
- process delete and restore work only through the correct queue
- keep uploads relevant, properly named, and policy-compliant
- confirm figures in the source module before external reporting

Support and leadership teams should:

- record major access changes
- document why sensitive settings were changed
- log import, restore, cleanup, and purge actions through normal governance flows
- hand over unresolved issues with enough context for the next operator

# Appendix A. Quick Page Reference

| Function | Menu / workspace |
| --- | --- |
| Main operational dashboard | `Dashboard` |
| Admin console | `Settings` |
| Staff due intake and review | `Staff Due for Retirement` |
| Claims and arrears workspace | `Claims` |
| Pension file registry | `Pension File Registry` |
| Payroll upload history | `Payroll Upload History` |
| Messages | `Messages` |
| Live chat | `Messages` / shared chat widget |
| Tasks | `My Tasks` |
| Pensioner dashboard | `Pensioner Dashboard` |
| Users management | `Users` |
| Feedback page | `Feedback Centre` |

# Appendix B. High-Value Admin APIs

| Purpose | Representative APIs |
| --- | --- |
| Settings | `get_app_settings.php`, `update_app_settings.php` |
| Users and roles | `get_users.php`, `register_user.php`, `get_roles.php`, `update_user_permissions_admin.php` |
| Active sessions | `get_active_sessions.php`, `terminate_other_sessions.php` |
| Diagnostics | `get_system_status.php`, `manage_system_health_diagnostics.php` |
| Notifications and live chat | `get_notification_queue.php`, `get_notification_sound_library.php`, `live_chat_poll.php`, `live_chat_send.php` |
| Backup and restore | `create_system_backup.php`, `restore_system_backup.php` |
| Imports and exports | `process_data_import.php`, `run_data_export.php`, `download_data_artifact.php` |
| Cleanup and storage | `run_data_cleanup.php`, `get_storage_usage.php` |

# Appendix C. Related Technical References

- `docs/PensionApp_System_Documentation.md`
- `docs/PensionApp_Technical_Documentation.md`
- `docs/ERD.pdf`
- `docs/erd.md`
- `docs/erd-domains/interdomain_links.md`
- `docs/versioning.md`
