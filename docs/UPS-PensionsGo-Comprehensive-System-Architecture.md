# UPS PensionsGo Comprehensive System Architecture

**Diagram:** `docs/UPS-PensionsGo-Comprehensive-System-Architecture.svg`  
**Snapshot:** 2026-05-28  
**Inventory basis:** 41 frontend HTML pages, 50 JavaScript files, 264 PHP API endpoints, and 83 database tables.

## Architecture Summary

UPS PensionsGo is a layered PHP/MySQL web application with a static-page JavaScript frontend, a PWA shell, endpoint-oriented PHP APIs, shared security/runtime services, a normalized relational schema, controlled filesystem storage, background processing, and a rebuildable documentation suite.

The comprehensive diagram groups the system into six layers:

1. **Access Channels and Users**  
   Public users, pensioners, operational staff, supervisors, administrators, security administrators, imported source files, and future external systems.

2. **Browser, Frontend, and PWA Runtime**  
   Public pages, operational workspaces, registry and pensioner pages, admin/identity pages, shared JavaScript modules, and PWA assets.

3. **Server Bootstrap, Security Boundary, and Shared Runtime**  
   `backend/config.php`, session managers, request hardening, runtime admin tools, health/versioning support, and shared domain helpers.

4. **PHP API Surface by Business Domain**  
   Auth/users/roles, intake and applications, workflow/tasks, registry/documents, file movement/life certificates, payroll/finance, claims/arrears, pensioner services, messaging/live chat, content/feedback, data management, administration/settings, diagnostics/backups, notifications, reports, and file responses.

5. **Persistence, Filesystem Storage, and Background Execution**  
   MySQL/MariaDB domain tables, governance tables, uploaded documents and attachments, generated exports/backups/cache/logs, and worker/admin-triggered maintenance jobs.

6. **Documentation, Governance Outputs, and Extension Points**  
   System docs, ERDs, DFDs, architecture diagrams, manuals, decks, generated deliverables, and candidate future integrations such as National ID, HR systems, payment platforms, SMS/email gateways, document repositories, and GovHub/API exchange.

## Key Data Anchors

The main cross-domain anchors are `userId`, `regNo`, file/registry identifiers, task IDs, payroll cycle IDs, arrears ledger/payment IDs, message IDs, and audit/session/log IDs.

## Main Diagram File

Open the SVG directly:

```text
docs/UPS-PensionsGo-Comprehensive-System-Architecture.svg
```

