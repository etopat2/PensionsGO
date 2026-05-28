from __future__ import annotations

from copy import deepcopy
from datetime import date
from pathlib import Path
import subprocess
import sys

from md_to_docx import render_markdown_to_docx


ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
ROLE_DOCS = DOCS / "role_manuals"
SNAPSHOT_DATE = date.today().isoformat()
PDF_EXPORTER = DOCS / "export_docx_to_pdf.ps1"
SHARED_LOGO = str(ROOT / "favicon.png")


PAGE_DETAILS = {
    "dashboard.html": (
        "Dashboard",
        "Review live workload, analytics, workflow pressure, compliance signals, and governed management views.",
    ),
    "admin_dashboard.html": (
        "Settings",
        "Maintain system settings, access control, roles, notifications, storage, audit views, backups, cleanup, and diagnostics.",
    ),
    "users.html": (
        "Users",
        "Search, review, and maintain user accounts and role assignments where governance permits.",
    ),
    "staff_due.html": (
        "Staff Due for Retirement",
        "Capture, review, filter, and progress staff-due records through governed intake and verification steps.",
    ),
    "add_staff.html": (
        "Add Staff",
        "Create a new staff-due record with identity, service, and workflow information.",
    ),
    "edit_staff.html": (
        "Edit Staff Record",
        "Correct or complete a staff-due record where the current role has edit rights.",
    ),
    "view_staff.html": (
        "View Staff",
        "Inspect full staff-due detail before routing, verification, or handoff.",
    ),
    "pension_file_registry.html": (
        "Pension File Registry",
        "Search pension files, inspect linked records, open registry details, and use life-certificate or delete-request tools where authorized.",
    ),
    "tasks.html": (
        "My Tasks",
        "Work assigned items, add comments, update task status, and progress workflow handoffs.",
    ),
    "file_tracking.html": (
        "File Tracking",
        "Track file custody, movement out of registry, receiving office, and return status.",
    ),
    "application_status.html": (
        "Application Status",
        "Review where a case sits in the workflow and confirm current progress state.",
    ),
    "messages.html": (
        "Messages",
        "Exchange controlled operational messages, updates, and clarifications with other users.",
    ),
    "reports.html": (
        "Reports",
        "Review operational summaries and role-relevant management output.",
    ),
    "claims.html": (
        "Claims",
        "Review arrears exposure, payments, accountability state, suspensions, and related analytics.",
    ),
    "claim_form.html": (
        "Claim Form",
        "Capture a new arrears-related record where claims management authority exists.",
    ),
    "budgeting.html": (
        "Budget Forecast",
        "Review or manage arrears and pension forecast views by financial period.",
    ),
    "benefits_calculator.html": (
        "Benefits Calculator",
        "Estimate service-based pension outputs using the configured retirement formulas.",
    ),
    "podcast.html": (
        "Podcast",
        "Watch guided pension information videos and official explanatory content.",
    ),
    "document_viewer.html": (
        "Document Viewer",
        "Open linked documents in a controlled preview workspace.",
    ),
    "profile.html": (
        "My Profile",
        "Review personal account information, role label, and current account details.",
    ),
    "edit_user.html": (
        "Edit Profile",
        "Maintain permitted personal account fields and credentials.",
    ),
    "pensioner_board.html": (
        "Pensioner Dashboard",
        "Review pension record, application stage, benefits summary, compliance state, claims, and indexed documents.",
    ),
    "pensioner_lookup.html": (
        "Find Pensioners",
        "Search the consent-based pensioner directory and review contact details that are allowed to be shown.",
    ),
    "faq.html": (
        "FAQs",
        "Read guided answers about the platform, records, claims, and pensioner support.",
    ),
    "about.html": (
        "About",
        "Read the current product overview and service positioning information.",
    ),
}


CAPABILITY_DETAILS = {
    "registry.edit": (
        "Create and update registry records",
        "Open the registry workspace in edit mode, maintain file data, and save governed changes.",
    ),
    "staff_due.edit": (
        "Create and update staff-due records",
        "Capture and correct staff-due records through the supported forms and workflow tools.",
    ),
    "staff_due.bulk_upload": (
        "Bulk upload staff-due records",
        "Import approved staff-due schedules from template-driven CSV or XLSX files.",
    ),
    "registry.bulk_upload": (
        "Bulk upload registry files",
        "Import approved pension registry schedules in bulk using the governed registry import flow.",
    ),
    "registry.life_certificate.submit": (
        "Submit life certificates",
        "Record life-certificate submissions and update the linked beneficiary contact profile.",
    ),
    "registry.delete_request": (
        "Request registry deletion",
        "Queue a registry record for governed deletion review instead of removing it directly.",
    ),
    "registry.delete_queue.process": (
        "Process registry delete queue",
        "Approve, reject, restore, export, and clear governed registry delete-queue items.",
    ),
    "staff_due.delete_request": (
        "Request staff-due deletion",
        "Submit a staff-due record for delete review with justification and audit trace.",
    ),
    "staff_due.delete_queue.process": (
        "Process staff-due delete queue",
        "Approve or reject queued staff-due deletion requests.",
    ),
    "registry.benefits.monthly_salary.edit": (
        "Maintain monthly salary input",
        "Update the salary input that feeds current benefits calculations where the edit control is exposed.",
    ),
    "registry.benefits.length_service.edit": (
        "Maintain length of service",
        "Adjust the service-duration value used in benefits assessment where the edit control is exposed.",
    ),
    "registry.benefits.amounts.edit": (
        "Maintain calculated benefit amounts",
        "Adjust annual salary, reduced pension, full pension, and gratuity fields where the edit control is exposed.",
    ),
    "file_movement.record": (
        "Record file movement",
        "Log file movement out of registry custody with destination, reason, and dates.",
    ),
    "file_movement.return": (
        "Mark files as returned",
        "Close a movement entry by confirming the file has returned into custody.",
    ),
    "payroll.upload": (
        "Upload payroll evidence",
        "Upload payroll cycles and payment-register evidence where the interface exposes the payroll workspace.",
    ),
    "payroll.manage": (
        "Replace or delete payroll cycles",
        "Manage payroll-cycle replacement, deletion, and active-period control.",
    ),
    "claims.arrears.view": (
        "View claims and arrears",
        "Use the claims workspace for visibility, analytics, exports, and case-level review.",
    ),
    "claims.arrears.manage": (
        "Manage arrears ledger",
        "Create arrears entries, post payments, submit accountability, and reconcile balances.",
    ),
    "claims.suspension.upload": (
        "Upload suspension records",
        "Import suspension schedules and preserve row-level suspension reasons during reconciliation.",
    ),
    "feedback.view": (
        "View feedback inbox",
        "Open the dashboard feedback workspace and inspect submissions, summary metrics, and case detail.",
    ),
    "feedback.manage": (
        "Manage feedback workflow",
        "Assign, review, resolve, close, and export governed feedback submissions.",
    ),
    "budget.manage": (
        "Manage budget forecast",
        "Create and update budget-forecast figures and planning notes for arrears and pension obligations.",
    ),
}


COMMON_REFERENCES = [
    "`docs/PensionApp_User_Admin_Manual.md`",
    "`docs/PensionApp_System_Documentation.md`",
    "`docs/ERD.pdf`",
    "`docs/role_manuals/README.md`",
]


def render_table(headers: list[str], rows: list[list[str]]) -> str:
    lines = [
        "| " + " | ".join(headers) + " |",
        "| " + " | ".join(["---"] * len(headers)) + " |",
    ]
    for row in rows:
        lines.append("| " + " | ".join(row) + " |")
    return "\n".join(lines)


def render_bullets(items: list[str]) -> str:
    return "\n".join(f"- {item}" for item in items)


def render_numbered(items: list[str]) -> str:
    return "\n".join(f"{idx}. {item}" for idx, item in enumerate(items, start=1))


def page_label(page_name: str) -> str:
    return PAGE_DETAILS[page_name][0]


def page_rows(page_names: list[str]) -> list[list[str]]:
    rows: list[list[str]] = []
    for page_name in page_names:
        label, purpose = PAGE_DETAILS[page_name]
        rows.append([label, purpose])
    return rows


def capability_rows(capabilities: list[str]) -> list[list[str]]:
    rows: list[list[str]] = []
    for capability in capabilities:
        label, meaning = CAPABILITY_DETAILS[capability]
        rows.append([f"`{capability}`", label, meaning])
    return rows


def revision_summary(role_label: str) -> list[dict]:
    return [
        {
            "version": "1.0",
            "date": SNAPSHOT_DATE,
            "summary": f"Initial current-state role manual generated for the {role_label} workflow profile.",
            "author": "OpenAI Codex",
            "approval": "Pending",
        }
    ]


def export_docx_to_pdf(docx_path: Path, pdf_path: Path) -> bool:
    if sys.platform != "win32" or not PDF_EXPORTER.exists():
        return False

    try:
        subprocess.run(
            [
                "powershell",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(PDF_EXPORTER),
                "-InputPath",
                str(docx_path),
                "-OutputPath",
                str(pdf_path),
            ],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        return True
    except subprocess.CalledProcessError as exc:
        stderr = (exc.stderr or "").strip()
        stdout = (exc.stdout or "").strip()
        message = stderr or stdout or str(exc)
        print(f"PDF export failed for {docx_path.name}: {message}", file=sys.stderr)
        return False


def output_base_name(spec: dict) -> str:
    return f"PensionApp_Role_Manual_{spec['slug']}"


def build_markdown(spec: dict) -> str:
    primary_pages_table = render_table(
        ["Menu / Workspace", "How this role uses it"],
        page_rows(spec["primary_pages"]),
    )

    support_pages_table = render_table(
        ["Menu / Workspace", "Typical purpose"],
        page_rows(spec["support_pages"]),
    )

    handoff_table = render_table(
        ["Situation", "Required next step"],
        [[left, right] for left, right in spec["handoffs"]],
    )

    issue_table = render_table(
        ["Issue", "Recommended first response"],
        [[left, right] for left, right in spec["issues"]],
    )

    lines = [
        f"# UPS PensionsGo Role Manual: {spec['role_label']}",
        "",
        f"**System:** UPS PensionsGo  ",
        f"**Role Key:** `{spec['role_key']}`  ",
        f"**Role Label:** {spec['role_label']}  ",
        f"**Repository Snapshot Date:** {SNAPSHOT_DATE}  ",
        f"**Generated Deliverables:** `docs/role_manuals/{output_base_name(spec)}.docx`, `docs/role_manuals/{output_base_name(spec)}.pdf`",
        "",
        "## Manual Profile",
        "",
        render_table(
            ["Field", "Value"],
            [
                ["Intended audience", spec["audience"]],
                ["Current role purpose", spec["role_purpose"]],
                ["Default landing page", page_label(spec["landing_page"])],
                ["Role type", spec["role_type"]],
                ["Current access note", spec["access_note"]],
            ],
        ),
        "",
        "# 1. Purpose and Role Position",
        "",
    ]
    lines.extend(spec["purpose_paragraphs"])
    lines.extend(
        [
            "",
            "# 2. Access Scope",
            "",
            "## 2.1 Primary Working Pages",
            "",
            primary_pages_table,
            "",
            "## 2.2 Support and Reference Workspaces",
            "",
            support_pages_table,
            "",
        ]
    )

    if spec.get("capabilities"):
        lines.extend(
            [
                "## 2.3 Default Governed Capabilities",
                "",
                render_table(
                    ["Permission / Control", "Capability", "Operational meaning"],
                    capability_rows(spec["capabilities"]),
                ),
                "",
            ]
        )
    if spec.get("portal_features"):
        lines.extend(
            [
                "## 2.3 Pensioner Portal Features",
                "",
                render_table(
                    ["Feature", "Current behavior", "What the user should expect"],
                    spec["portal_features"],
                ),
                "",
            ]
        )

    lines.extend(
        [
            "## 2.4 Work Normally Outside This Role",
            "",
            render_bullets(spec["not_in_scope"]),
            "",
            "# 3. Standard Daily Operating Procedure",
            "",
            render_numbered(spec["daily_flow"]),
            "",
            "# 4. Module Guidance",
            "",
        ]
    )

    for idx, module in enumerate(spec["modules"], start=1):
        lines.extend(
            [
                f"## 4.{idx} {module['title']}",
                "",
                module["overview"],
                "",
                "### Standard Steps",
                "",
                render_numbered(module["steps"]),
                "",
                "### Control Points",
                "",
                render_bullets(module["controls"]),
                "",
            ]
        )

    lines.extend(
        [
            "# 5. Governance and Control Rules",
            "",
            render_bullets(spec["governance"]),
            "",
            "# 6. Handoffs and Escalation",
            "",
            handoff_table,
            "",
            "# 7. Common Issues and First Responses",
            "",
            issue_table,
            "",
            "# 8. Working Checklist",
            "",
            render_bullets(spec["checklist"]),
            "",
            "# 9. Related References",
            "",
            render_bullets(spec.get("references", COMMON_REFERENCES)),
            "",
        ]
    )
    return "\n".join(lines)


def build_readme(specs: list[dict]) -> str:
    rows = []
    for spec in specs:
        base = output_base_name(spec)
        rows.append(
            [
                spec["role_label"],
                spec["role_key"],
                spec["role_purpose"],
                f"`{base}.md`",
                f"`{base}.docx`",
                f"`{base}.pdf`",
            ]
        )

    return "\n".join(
        [
            "# Role Manual Suite",
            "",
            "This folder contains the current role-by-role user manuals generated from the live UPS PensionsGo access model and workflow responsibilities.",
            "",
            render_table(
                ["Role", "Key", "Current purpose", "Source", "Word", "PDF"],
                rows,
            ),
            "",
            "## Notes",
            "",
            f"- Manuals are based on the repository snapshot dated {SNAPSHOT_DATE}.",
            "- Capabilities may still be narrowed or expanded by role overrides and user-specific permission overrides.",
            "- Some functions appear inside dashboard workspaces rather than standalone menu items, so manuals reference both UI labels and governed actions.",
            "",
        ]
    )


def build_specs() -> list[dict]:
    admin = {
        "slug": "Admin",
        "role_key": "admin",
        "role_label": "Administrator",
        "audience": "Administrators, support leads, governance reviewers, and onboarding trainers",
        "role_purpose": "Global governance, access administration, operational oversight, data management, and system assurance.",
        "role_type": "Governance and administration role",
        "landing_page": "dashboard.html",
        "access_note": "This role can reach all governed work areas, but actions must still follow the correct operational process and audit requirements.",
        "purpose_paragraphs": [
            "The Administrator role owns system-wide governance for UPS PensionsGo. Administrators maintain users, roles, settings, notifications, exports, backups, cleanup routines, and controlled oversight across operational modules.",
            "This manual is written for the current implemented platform. It reflects the live Settings workspace, role-governance model, dashboard diagnostics, and the broader obligation to use elevated access carefully and only for legitimate operational reasons.",
        ],
        "primary_pages": [
            "dashboard.html",
            "admin_dashboard.html",
            "users.html",
            "pension_file_registry.html",
            "staff_due.html",
            "claims.html",
            "budgeting.html",
            "messages.html",
            "reports.html",
        ],
        "support_pages": [
            "application_status.html",
            "file_tracking.html",
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": list(CAPABILITY_DETAILS.keys()),
        "not_in_scope": [
            "Do not bypass normal workflow simply because admin access exists.",
            "Do not run cleanup, delete, or restore routines without confirming retention, backup, and business justification.",
            "Do not make informal database-side corrections outside governed application flows unless the task is an approved technical recovery exercise.",
        ],
        "daily_flow": [
            "Sign in and review the shared dashboard, recent activity, and any urgent operational or support signals.",
            "Open Settings and confirm whether there are pending user, role, notification, storage, or diagnostic actions that need attention.",
            "Process governance work such as account maintenance, role updates, settings review, export requests, or operational escalations.",
            "Review delete queues, feedback workflow, and data-management actions before approving any destructive or high-impact operation.",
            "Record decisions through the governed interface so that the audit trail remains complete.",
        ],
        "modules": [
            {
                "title": "Settings",
                "overview": "Use Settings as the main governance entry point. It opens the administrative workspace for Dashboard Overview, User Management, System Settings, role governance, notification controls, data management, storage oversight, activity logs, audit trail, and system health diagnostics.",
                "steps": [
                    "Open Settings and confirm you are working in the correct section before changing settings or records.",
                    "When changing settings, review the section subtitle and field help text first so you understand the effect of the change.",
                    "Save only one coherent governance change at a time and wait for the success confirmation before navigating away.",
                    "For role or permission changes, confirm the target role or user, review the effective permission impact, and then save.",
                    "For diagnostics, use system health and activity-log sections to understand the problem before taking corrective action.",
                ],
                "controls": [
                    "Sensitive settings may require re-authentication or a fresh session.",
                    "Existing sessions may need re-login before some security changes fully apply.",
                    "Role governance should be coordinated with the operational owner to avoid unplanned access loss.",
                ],
            },
            {
                "title": "Data Management, Backup, and Cleanup",
                "overview": "Administrators control export, import, backup, restore, and cleanup tooling. These actions affect data integrity and must be treated as governed maintenance, not casual housekeeping.",
                "steps": [
                    "Confirm the business reason for the operation and verify the correct environment before starting a backup, export, restore, or cleanup.",
                    "For backup or export jobs, confirm the target scope, file naming, and destination path before execution.",
                    "For import or cleanup actions, prefer dry-run or preview modes first whenever the interface provides them.",
                    "Review generated reports, import-review files, or cleanup previews before allowing a destructive or finalizing step.",
                    "Retain evidence of completed data-management activity in the platform logs or approved operational records.",
                ],
                "controls": [
                    "Cleanup should remain backup-first and preview-first unless there is an approved emergency procedure.",
                    "Restore operations must be aligned with incident ownership and user communication.",
                    "Exports may contain sensitive records and must be handled according to operational privacy rules.",
                ],
            },
            {
                "title": "Operational Oversight",
                "overview": "Although administrators can access all modules, their operational use should focus on supervision, support, escalation, and corrective governance rather than replacing line users in routine work.",
                "steps": [
                    "Use the shared dashboard to identify pressure points in claims, workflow, life certificates, file custody, and registry delete queues.",
                    "Open source modules only after confirming the dashboard signal that needs action.",
                    "Support operational users by correcting access, settings, or queue bottlenecks instead of taking over normal routine activity where possible.",
                    "When an exception requires admin intervention inside a line module, complete the minimum safe action and leave a clear audit trace.",
                    "Return work to the correct operational owner after the exception has been stabilized.",
                ],
                "controls": [
                    "Admin access is not a substitute for business ownership.",
                    "High-impact changes should be coordinated with OC/Pension or the relevant role lead.",
                    "Use the role-aware workflow path whenever the system already provides one.",
                ],
            },
            {
                "title": "Security, Sessions, and Diagnostics",
                "overview": "Administrators maintain session policies, alert routing, active-session discipline, and diagnostic follow-up for system health or operational incidents.",
                "steps": [
                    "Use the security and session controls to review timeout windows, multi-session policy, and re-authentication behavior.",
                    "Check active sessions and logs when investigating login issues, policy changes, or unusual activity.",
                    "Use system-health diagnostics to isolate failed exports, backup issues, queue failures, or other operational warnings.",
                    "Apply the least disruptive corrective action that solves the issue, then monitor the platform for recurrence.",
                    "Escalate code defects or infrastructure faults to engineering support with the exact error evidence and date/time context.",
                ],
                "controls": [
                    "Never terminate sessions or relax security settings casually.",
                    "Notification, audit, and diagnostic evidence should be preserved during incident handling.",
                    "If a problem points to a code or schema defect, stop short of risky workarounds and escalate.",
                ],
            },
        ],
        "governance": [
            "Use elevated access strictly for legitimate administrative work.",
            "Keep changes small, traceable, and reversible where possible.",
            "Prefer governed application tools over manual database or filesystem intervention.",
            "Review storage, backup, export, and cleanup implications before destructive actions.",
            "Document exceptional interventions so operational teams understand what changed and why.",
        ],
        "handoffs": [
            ("Operational routing or case ownership questions", "Coordinate with OC/Pension or the designated workflow lead before reassigning responsibility."),
            ("Line-user data correction that does not require admin-only access", "Return the task to clerk, data-entry, registry, or workflow staff after removing the blocker."),
            ("Code defect, schema mismatch, or repeated API failure", "Escalate to technical support or engineering with logs, exact page, action, and timestamp."),
            ("Policy change affecting live users", "Communicate the change to supervisors and user-support contacts before or immediately after rollout."),
        ],
        "issues": [
            ("A user cannot log in after a policy change", "Check session state, maintenance mode, role assignment, and any recent access-control changes first."),
            ("A PDF or export job fails", "Review the queue, storage destination, Word/PDF tooling, and system-health diagnostics before retrying."),
            ("Cleanup action shows unexpected candidates", "Stop and review the preview output, retention settings, and backup status before proceeding."),
            ("A role no longer sees expected actions", "Inspect role governance and user-specific permission overrides before changing code or data."),
        ],
        "checklist": [
            "Review dashboard and diagnostics at the start of the session.",
            "Confirm the target role, user, or dataset before saving a governance change.",
            "Use preview or dry-run modes for imports and cleanup where available.",
            "Preserve logs and evidence for incidents, restores, or exceptional admin interventions.",
            "Close the day with unresolved admin actions clearly handed over or documented.",
        ],
    }

    oc_shared = {
        "primary_pages": [
            "dashboard.html",
            "staff_due.html",
            "add_staff.html",
            "view_staff.html",
            "pension_file_registry.html",
            "tasks.html",
            "file_tracking.html",
            "application_status.html",
            "claims.html",
            "budgeting.html",
            "messages.html",
            "reports.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "staff_due.bulk_upload",
            "registry.bulk_upload",
            "staff_due.delete_request",
            "staff_due.delete_queue.process",
            "registry.delete_request",
            "registry.delete_queue.process",
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "file_movement.return",
            "payroll.upload",
            "claims.arrears.view",
            "claims.arrears.manage",
            "claims.suspension.upload",
            "feedback.view",
            "feedback.manage",
            "budget.manage",
        ],
        "not_in_scope": [
            "Direct line-by-line registry or staff-due editing is not the default working pattern for this role.",
            "Payroll cycle replacement and deletion remain administrator functions.",
            "System settings, role governance, and technical recovery remain administrator-led.",
        ],
        "daily_flow": [
            "Start in the dashboard and review workflow pressure, claims exposure, life-certificate gaps, file-custody issues, and feedback workload.",
            "Open the source module only for the specific exception, queue, or supervisory action that needs attention.",
            "Use delegation, delete-queue processing, bulk-import governance, and claims/budget controls to keep work moving.",
            "Return detailed capture or correction tasks to the responsible operational role after supervisory action is complete.",
            "Record comments, approvals, or queue outcomes so that later reviewers can understand the decision path.",
        ],
        "modules": [
            {
                "title": "Dashboard Supervision",
                "overview": "The dashboard is the main control surface for OC/Pension-equivalent roles. It concentrates claims, registry, workflow, life-certificate, feedback, and delete-queue visibility in one place.",
                "steps": [
                    "Review summary cards and section data before acting on any exception.",
                    "Use filters to isolate the correct period, claim type, status, or operating scope.",
                    "Open the detailed module only after confirming the dashboard signal that needs action.",
                    "Export or report only from the current filtered view so the numbers remain defensible.",
                    "Use the dashboard as the management picture, not as a substitute for source-record verification.",
                ],
                "controls": [
                    "Dashboard data should be validated in the source module before final decisions.",
                    "Supervisory exports should be handled as sensitive operational material.",
                    "Feedback management and delete-queue actions must remain evidence-based and role-appropriate.",
                ],
            },
            {
                "title": "Bulk Governance and Delete Queues",
                "overview": "This role governs high-impact queue work such as bulk imports and deletion review. The focus is approval, exception handling, and control rather than routine data entry.",
                "steps": [
                    "Review staff-due or registry import inputs using the governed template and validation output first.",
                    "Do not accept bulk loads until the review file confirms that structure and critical fields are acceptable.",
                    "Open delete queues with the exact record, reason, and request context in view before taking action.",
                    "Approve, reject, or return queue items using consistent reasoning and clear comments where the interface allows it.",
                    "Where direct edit is needed, reassign the record to a role with line-edit responsibility instead of improvising around the control model.",
                ],
                "controls": [
                    "Queue actions are governance actions and should be reversible or explainable from the audit trail.",
                    "Delete approval is not a data-cleanup shortcut.",
                    "Bulk import authority does not remove the need for review, sign-off, and evidence retention.",
                ],
            },
            {
                "title": "Claims, Suspensions, and Budget Oversight",
                "overview": "OC/Pension-equivalent roles supervise the financial side of the workload by reviewing arrears exposure, posting or validating payment actions, importing suspensions, and maintaining budget outlooks.",
                "steps": [
                    "Use the claims workspace to confirm current exposure, outstanding balances, and payment-accountability status.",
                    "Where management rights are present, post or validate arrears actions carefully against the correct beneficiary and period.",
                    "Use suspension upload flows only with approved source files and review exports before accepting the import.",
                    "Update or review budget forecasts by the correct financial year and ensure assumptions are consistent with current claims visibility.",
                    "Escalate unexplained financial anomalies promptly instead of forcing records into a misleading state.",
                ],
                "controls": [
                    "Period, year, and claim type must be correct before any financial save or import.",
                    "Suspension uploads require source-file discipline and review of exceptions.",
                    "Budget figures are management artefacts and must not be entered casually.",
                ],
            },
            {
                "title": "Payroll, Feedback, and File Custody",
                "overview": "This role also participates in payroll evidence handling, dashboard feedback governance, and high-level control of file movements.",
                "steps": [
                    "Use payroll upload features only for the correct month, year, and supporting register evidence.",
                    "Review unmatched payroll or suspension results immediately after upload and direct corrections to the right role.",
                    "Use the feedback workspace to assign, resolve, or close service issues that need supervisory ownership.",
                    "Confirm file movement and return records where custody accountability matters to pending workflow.",
                    "Close the loop by sending clear instructions back to the handling officer or unit.",
                ],
                "controls": [
                    "Do not replace payroll cycles without administrator support.",
                    "Feedback closure should reflect a genuine reviewed outcome, not just inbox cleanup.",
                    "File-custody actions should match the physical or recorded movement of the file.",
                ],
            },
        ],
        "governance": [
            "Operate as a supervisory controller, not a substitute for every downstream role.",
            "Use bulk, delete, claims, and budget controls only with clear business justification.",
            "Delegate detailed data correction to the correct line role whenever possible.",
            "Leave clear comments and queue outcomes for auditability and continuity.",
            "Escalate configuration, access, or system failures to administrators instead of working around them informally.",
        ],
        "handoffs": [
            ("Record needs line-by-line correction", "Reassign to clerk, data-entry, or write-up staff as appropriate."),
            ("Assessment or calculation review is required", "Delegate to assessor and monitor completion through tasks."),
            ("System setting, role, or technical defect is blocking work", "Escalate to administrator with the exact failure context."),
            ("Final policy or approval decision is needed", "Move the case to the appropriate approver or governance authority."),
        ],
        "issues": [
            ("Bulk upload review shows validation errors", "Do not import; correct the source file and rerun the review."),
            ("Delete queue item lacks enough justification", "Reject or return it and request proper reason evidence."),
            ("Claims numbers look inconsistent", "Recheck filters, period scope, and beneficiary detail before changing any record."),
            ("Budget figures no longer match current claims exposure", "Refresh the source data and update the forecast with traceable assumptions."),
        ],
        "checklist": [
            "Start from the dashboard and confirm the current operating picture.",
            "Review queues and imports before approving high-impact actions.",
            "Use delegation to keep detailed operational work with the correct role.",
            "Confirm financial period and claimant identity before claims or budget actions.",
            "Leave an audit-friendly trail for every supervisory intervention.",
        ],
    }

    clerk = {
        "slug": "Clerk",
        "role_key": "clerk",
        "role_label": "Clerk",
        "audience": "Clerks, intake officers, supervisors, trainers, and support teams",
        "role_purpose": "Application intake, verification support, registry maintenance, life-certificate handling, and first-line workflow progression.",
        "role_type": "Operational intake and verification role",
        "landing_page": "pension_file_registry.html",
        "access_note": "The current build routes clerks into the registry area after login; intake work then continues across staff-due, registry, dashboard, and task views.",
        "purpose_paragraphs": [
            "The Clerk role is the first operational control point for pension-case capture and verification support. Clerks keep staff-due records clean, update governed registry details, record file movement, and support life-certificate or payroll evidence handling where the system allows it.",
            "Clerks are expected to keep the data trustworthy and the workflow clear. This means capturing complete records, using status tools correctly, and escalating delete approvals or exceptional governance actions instead of trying to resolve them informally.",
        ],
        "primary_pages": [
            "pension_file_registry.html",
            "staff_due.html",
            "add_staff.html",
            "edit_staff.html",
            "view_staff.html",
            "tasks.html",
            "file_tracking.html",
            "dashboard.html",
            "claims.html",
            "application_status.html",
            "messages.html",
            "reports.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "staff_due.edit",
            "staff_due.delete_request",
            "registry.edit",
            "registry.life_certificate.submit",
            "registry.delete_request",
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "file_movement.return",
            "payroll.upload",
            "claims.arrears.view",
            "feedback.view",
            "feedback.manage",
        ],
        "not_in_scope": [
            "Bulk import of staff-due or registry schedules is not a default clerk capability.",
            "Delete-queue processing remains a supervisory or administrator action.",
            "Claims payment posting, suspension uploads, and budget maintenance are not default clerk responsibilities.",
        ],
        "daily_flow": [
            "Sign in and review the dashboard or assigned tasks for intake, verification, or follow-up priorities.",
            "Open staff-due or registry workspaces and locate the target record using filters or search first.",
            "Capture or correct controlled fields carefully, then save through the governed form.",
            "Use workflow actions, comments, and file-movement records to move work forward with traceability.",
            "Queue delete requests or escalate exceptions instead of using shortcuts for sensitive changes.",
        ],
        "modules": [
            {
                "title": "Staff-Due Intake and Verification",
                "overview": "Clerks are expected to maintain the staff-due workspace carefully because it forms the early evidence base for later workflow stages.",
                "steps": [
                    "Create or open the staff-due record and verify identity, service, retirement, and contact fields before saving.",
                    "Use the guided workflow actions for submit, verify, or review rather than relying on informal notes.",
                    "Where a record should not remain active, submit a delete request with a clear reason instead of attempting removal outside the queue.",
                    "Check supporting details again after edits so later roles do not inherit avoidable errors.",
                    "If the queue or action controls are not visible, confirm role and permission state before escalating.",
                ],
                "controls": [
                    "Do not save guessed identifiers or retirement dates.",
                    "Verification activity should reflect the real record state, not a desire to clear the queue.",
                    "Delete requests must include defensible reasons.",
                ],
            },
            {
                "title": "Registry Maintenance and Life Certificates",
                "overview": "Clerks can maintain registry records and record life-certificate submissions. This work should be exact because the registry is the authoritative pension-file reference.",
                "steps": [
                    "Search for the correct pension file and open the detail or edit workspace before changing any field.",
                    "Update only the fields supported by the interface and confirm the correct file number, pensioner identity, and service context first.",
                    "Use the life-certificate tools for the correct year and confirm the contact profile before submitting.",
                    "Attach or review documents through the governed registry document path when that function is available.",
                    "Submit registry delete requests only when there is a valid operational reason and supporting explanation.",
                ],
                "controls": [
                    "Do not treat the registry as a scratchpad for unresolved information.",
                    "Life-certificate status must match actual evidence received.",
                    "Registry delete requests are not the same as correction requests.",
                ],
            },
            {
                "title": "File Movement and Payroll Evidence",
                "overview": "Clerks also support physical or recorded file custody and can upload payroll evidence where the platform exposes that workflow.",
                "steps": [
                    "Record file movement whenever custody changes and include destination, reason, and timing details.",
                    "Mark a file as returned only after confirming the return into custody.",
                    "For payroll evidence, confirm the correct period, supporting register, and source file before upload.",
                    "Review the resulting payroll view or exception summary immediately after upload.",
                    "Escalate period mismatches or unexplained payroll gaps instead of forcing a questionable upload.",
                ],
                "controls": [
                    "Custody entries should match the real or officially recorded file movement.",
                    "Payroll uploads require the correct month, year, and supporting file.",
                    "Replacement or deletion of payroll cycles remains outside the clerk role.",
                ],
            },
            {
                "title": "Claims Visibility and Feedback Handling",
                "overview": "Clerks can review claims exposure and participate in dashboard feedback management, but not in full claims posting or budget control by default.",
                "steps": [
                    "Use the claims workspace to understand the current arrears context before escalating or replying to enquiries.",
                    "Do not post payments or suspension uploads unless an explicit override has been granted.",
                    "Use the dashboard feedback workspace to review submissions, update workflow fields, and close the loop on issues within your responsibility.",
                    "Send controlled messages when another role needs clarification or evidence.",
                    "Escalate financial or policy-sensitive items to OC/Pension or administration.",
                ],
                "controls": [
                    "Claims visibility does not automatically permit claims mutation.",
                    "Feedback closure should reflect a real reviewed outcome.",
                    "Escalate anything that changes financial liability or governance state.",
                ],
            },
        ],
        "governance": [
            "Capture complete and accurate information before saving.",
            "Use workflow controls and delete-request queues instead of informal workarounds.",
            "Treat registry, payroll, and document records as sensitive operational information.",
            "Record file-custody changes every time the file moves.",
            "Escalate supervisory, financial, or queue-processing decisions to the correct role.",
        ],
        "handoffs": [
            ("Delete request needs approval", "Route it to OC/Pension-equivalent supervision or an administrator."),
            ("Case needs detailed write-up", "Move it to the Writeup Officer through the task workflow."),
            ("Calculation or assessment review is required", "Escalate to the Assessor after confirming the source record is complete."),
            ("Claims posting or budget action is needed", "Escalate to a role with claims-management or budget authority."),
        ],
        "issues": [
            ("Edit controls are missing", "Confirm your current role, effective role, and page-specific permission state first."),
            ("Registry delete cannot be completed immediately", "Use the delete-request path and wait for queue processing."),
            ("Payroll upload result shows unmatched rows", "Review the exception output and escalate corrections before retrying."),
            ("A record is incomplete but already in workflow", "Correct what the role is allowed to fix, then leave a clear note for the next handler."),
        ],
        "checklist": [
            "Check search filters before assuming a record is missing.",
            "Verify identity, retirement, and contact data before saving.",
            "Use life-certificate and delete-request tools only for the correct record.",
            "Record every file movement and return status accurately.",
            "Escalate financial, supervisory, and queue-processing work to the correct owner.",
        ],
    }

    oc_pen = {
        "slug": "OC_Pension",
        "role_key": "oc_pen",
        "role_label": "OC/Pension",
        "audience": "OC/Pension officers, supervisors, governance reviewers, and trainers",
        "role_purpose": "Workflow control, assignment authority, delete governance, bulk-import control, claims supervision, and budget oversight.",
        "role_type": "Supervisory workflow control role",
        "landing_page": "dashboard.html",
        "access_note": "This role is dashboard-first and is expected to supervise exceptions, queues, and financial or governance-sensitive actions rather than do all detailed capture personally.",
        "purpose_paragraphs": [
            "The OC/Pension role acts as the operational controller for live pension workflow. The role monitors dashboard signals, approves or rejects deletion requests, governs bulk imports, supervises claims and budget handling, and keeps the work moving between detailed line roles.",
            "Because the role is supervisory, its main value is decision quality and control discipline. The objective is not to replace clerks, data entrants, or assessors, but to resolve bottlenecks, authorize controlled actions, and maintain accountability across the process.",
        ],
        "primary_pages": oc_shared["primary_pages"],
        "support_pages": oc_shared["support_pages"],
        "capabilities": oc_shared["capabilities"],
        "not_in_scope": oc_shared["not_in_scope"],
        "daily_flow": oc_shared["daily_flow"],
        "modules": oc_shared["modules"],
        "governance": oc_shared["governance"],
        "handoffs": oc_shared["handoffs"],
        "issues": oc_shared["issues"],
        "checklist": oc_shared["checklist"],
    }

    dep_oc = deepcopy(oc_pen)
    dep_oc.update(
        {
            "slug": "Deputy_OC_Pension",
            "role_key": "dep_oc",
            "role_label": "Deputy OC/Pension",
            "audience": "Deputy OC/Pension users, delegated supervisors, governance reviewers, and trainers",
            "role_purpose": "Delegated OC/Pension-equivalent workflow control, delete governance, bulk-import control, claims supervision, and budget oversight.",
            "access_note": "The current seeded role mirrors OC/Pension-equivalent supervisory work. In the live access model, this role is treated as a delegated supervisory authority for the same workflow control areas.",
            "purpose_paragraphs": [
                "The Deputy OC/Pension role is a delegated supervisory role used where OC/Pension-equivalent control is needed without changing the overall governance pattern. In the current system, it is functionally aligned to the same supervisory work areas as OC/Pension.",
                "The role should therefore be used for controlled delegation, continuity of leadership, and queue or financial oversight, while still preserving a clear audit trail about who acted and why.",
            ],
        }
    )

    writeup_officer = {
        "slug": "Writeup_Officer",
        "role_key": "writeup_officer",
        "role_label": "Writeup Officer",
        "audience": "Writeup officers, supervisors, trainers, and support staff",
        "role_purpose": "Preparation of pension write-ups, case refinement, controlled record updates, and workflow handoff to downstream stages.",
        "role_type": "Workflow preparation role",
        "landing_page": "tasks.html",
        "access_note": "This role is task-driven. The current build expects write-up work to begin from the assigned queue and then move into staff-due or registry detail as required.",
        "purpose_paragraphs": [
            "The Writeup Officer role converts a raw or partially verified case into a well-prepared pension file ready for downstream creation, capture, and assessment stages. The role usually works from assigned tasks and uses registry or staff-due workspaces to polish the record and supporting narrative.",
            "This role should focus on completeness, coherence, and readiness for handoff. Where a record cannot be made ready because source data is weak or authority is missing, the correct action is escalation, not silent approximation.",
        ],
        "primary_pages": [
            "tasks.html",
            "staff_due.html",
            "add_staff.html",
            "edit_staff.html",
            "view_staff.html",
            "pension_file_registry.html",
            "file_tracking.html",
            "application_status.html",
            "claims.html",
            "dashboard.html",
            "messages.html",
            "reports.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "staff_due.edit",
            "registry.edit",
            "registry.life_certificate.submit",
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "Bulk uploads, delete-queue processing, and budget work are not default write-up tasks.",
            "Claims payment posting and suspension imports remain outside the default role scope.",
            "Supervisory approval decisions should be left to OC/Pension-equivalent or approval roles.",
        ],
        "daily_flow": [
            "Open the assigned task first and confirm the exact case objective, due date, and expected output.",
            "Review the staff-due record, registry record, supporting documents, and workflow comments before editing anything.",
            "Update the record only through the governed forms and leave factual comments that help the next role understand the case.",
            "Record any file movement that occurs while the write-up is being prepared.",
            "Hand off the case promptly once the write-up package is complete or clearly blocked.",
        ],
        "modules": [
            {
                "title": "Assigned Task Review",
                "overview": "The write-up role should begin from the task queue because that is where responsibility, due date, and current workflow context are made explicit.",
                "steps": [
                    "Open the assigned task and read any prior comments, alert flags, and status history.",
                    "Confirm what the next role needs from the write-up before making changes.",
                    "If the case lacks enough source material, leave a precise note and request clarification instead of guessing.",
                    "Update task status as real progress occurs, not in anticipation of progress.",
                    "Close or hand off the task only when the record and notes are genuinely ready for the next stage.",
                ],
                "controls": [
                    "Task comments should be factual and operationally useful.",
                    "Do not hide uncertainty; escalate it.",
                    "Status changes must reflect real case state.",
                ],
            },
            {
                "title": "Record Preparation",
                "overview": "Write-up officers use staff-due and registry workspaces to make the case coherent and ready for later stages.",
                "steps": [
                    "Review the current staff-due and registry information side by side where needed.",
                    "Correct governed fields only after confirming the underlying evidence.",
                    "Use life-certificate tools only when that evidence is part of the case and you have the correct profile in view.",
                    "Review linked documents through the document viewer so the write-up aligns to the evidence pack.",
                    "Leave a concise note for the downstream role explaining what was confirmed, corrected, or remains outstanding.",
                ],
                "controls": [
                    "Every correction should be traceable to evidence or instruction.",
                    "Do not convert a missing-data problem into a guessed-data problem.",
                    "Maintain document-to-record alignment at all times.",
                ],
            },
            {
                "title": "Claims and File Context Review",
                "overview": "Claims visibility and file-movement tools help the write-up role understand financial and custody context around the case.",
                "steps": [
                    "Review claims exposure or arrears context when the case history suggests payment impact.",
                    "Use file-movement logging whenever the physical or recorded file moves during write-up work.",
                    "Confirm application status and current workflow stage before handing the case forward.",
                    "Send messages when another role needs targeted clarification or additional evidence.",
                    "Escalate financial or supervisory questions instead of making unsupported decisions.",
                ],
                "controls": [
                    "Claims visibility is for context unless a separate management right exists.",
                    "Custody records must reflect the real movement of the file.",
                    "Handoffs should never be silent; they should explain the state of the case.",
                ],
            },
        ],
        "governance": [
            "Begin from the assigned task so ownership remains clear.",
            "Use governed edit forms only after confirming source evidence.",
            "Keep write-up notes concise, factual, and useful for the next role.",
            "Record file movement whenever custody changes.",
            "Escalate missing evidence, policy ambiguity, or supervisory decisions promptly.",
        ],
        "handoffs": [
            ("Case is ready for structured capture", "Hand off to File Creator or Data Entry according to the local workflow arrangement."),
            ("A calculation or benefits review is needed", "Send the case to Assessor with a clear summary of the evidence state."),
            ("Delete or supervisory exception is identified", "Escalate to OC/Pension-equivalent supervision."),
            ("A technical or access issue blocks the workspace", "Escalate to administrator or support with the exact failing action."),
        ],
        "issues": [
            ("Task instructions are vague", "Review record history and request clarification before editing the case."),
            ("Evidence conflicts across forms or documents", "Do not choose informally; flag the discrepancy and escalate."),
            ("Life-certificate controls are unavailable", "Confirm whether the role and record context actually allow that action."),
            ("A downstream role rejects the case as incomplete", "Reopen the task, correct the missing items, and document the new handoff clearly."),
        ],
        "checklist": [
            "Read the assigned task before opening source records.",
            "Confirm evidence before every correction.",
            "Use file-movement logging when custody changes.",
            "Leave a clean note for the downstream role.",
            "Escalate ambiguity instead of masking it.",
        ],
    }

    file_creator = {
        "slug": "File_Creator",
        "role_key": "file_creator",
        "role_label": "File Creator",
        "audience": "File creators, supervisors, trainers, and support teams",
        "role_purpose": "Task-based file establishment, file-custody handling, and preparation of complete case packs for downstream capture and review.",
        "role_type": "Workflow file-establishment role",
        "landing_page": "tasks.html",
        "access_note": "The current build gives this role strong task, file-tracking, claims-visibility, and registry-review access. Some registry mutation actions may still require an added override or handoff to a registry-edit role.",
        "purpose_paragraphs": [
            "The File Creator role is responsible for shaping the case into a usable file package for later capture and decision stages. In the current application, this role works mainly from tasks, registry review, file tracking, and status workspaces.",
            "Where the interface does not expose direct create or edit controls for registry data, that is an expected part of the live access model. The role should then complete the file-preparation work, log movement correctly, and hand off the case to a role with the required edit rights.",
        ],
        "primary_pages": [
            "tasks.html",
            "pension_file_registry.html",
            "file_tracking.html",
            "application_status.html",
            "claims.html",
            "dashboard.html",
            "messages.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "Direct registry create or edit actions are not guaranteed for this role in the current build.",
            "Delete requests, queue processing, claims posting, payroll uploads, and budget updates are not default file-creator work.",
            "Supervisory approval and role governance remain outside this role.",
        ],
        "daily_flow": [
            "Open assigned tasks first and confirm the file-establishment objective and expected downstream recipient.",
            "Review the registry, documents, claims context, and application status before moving the case.",
            "Use file-tracking tools whenever the case file moves between desks or offices.",
            "Where an edit control is not available, leave a clear task note and hand the case to the correct editing role.",
            "Close the task only after the file package is complete and the handoff is explicit.",
        ],
        "modules": [
            {
                "title": "Task-Led File Establishment",
                "overview": "The File Creator role should work from the task queue because that is where ownership and expected output are made clear.",
                "steps": [
                    "Open the assigned task and note the exact file-establishment requirement.",
                    "Review all existing comments and linked evidence so you understand what is already complete.",
                    "Confirm whether the case needs new file movement, document review, or registry clarification.",
                    "If the task depends on unavailable edit rights, record that clearly and route it to the correct role instead of stalling silently.",
                    "Update task status only when the file package has genuinely moved forward.",
                ],
                "controls": [
                    "Use tasks as the source of ownership truth.",
                    "Do not assume missing controls are a bug; first confirm whether the role is expected to hand off.",
                    "Keep downstream instructions explicit.",
                ],
            },
            {
                "title": "Registry Review and Evidence Check",
                "overview": "This role can use the registry and document viewer to confirm whether the case pack is internally consistent and ready for handoff.",
                "steps": [
                    "Search the pension file registry and open the correct record or details view.",
                    "Review linked documents and pension-profile context using the document viewer where necessary.",
                    "Check whether the record appears complete enough for the next workflow stage.",
                    "If the registry edit workspace is not available, note the required correction and route it to a registry-edit role.",
                    "Use the application-status view to confirm the correct next destination for the case.",
                ],
                "controls": [
                    "Do not force unsupported edits through unofficial means.",
                    "Use evidence review to improve handoff quality, not to improvise policy decisions.",
                    "Always confirm the correct record before opening supporting documents.",
                ],
            },
            {
                "title": "File Movement and Handoff",
                "overview": "File creators should preserve strong file-custody discipline because a well-prepared file still fails operationally if nobody can trace where it went.",
                "steps": [
                    "Record every movement out of custody with destination and reason.",
                    "Confirm receiving office or user details before saving the movement.",
                    "Send a concise task or message update when the file is handed over.",
                    "Use claims visibility only for context where the financial state affects the file package.",
                    "Keep the file location and task state synchronized.",
                ],
                "controls": [
                    "Movement logs must reflect the real location of the file.",
                    "Claims visibility does not authorize financial updates.",
                    "A handoff is incomplete if the next role cannot tell where the file is and what remains to be done.",
                ],
            },
        ],
        "governance": [
            "Let the task queue define ownership and expected output.",
            "Respect the current edit-control model and hand off where necessary.",
            "Keep file-custody data current and exact.",
            "Use registry and claims visibility to improve context, not to exceed role authority.",
            "Leave downstream roles a clean, understandable case package.",
        ],
        "handoffs": [
            ("Registry data needs correction", "Send the case to a role with registry-edit authority such as Clerk, Data Entry, or Writeup Officer."),
            ("Assessment is the next true step", "Hand off to Assessor with a clear note about document and file status."),
            ("Supervisory or delete decision is needed", "Escalate to OC/Pension-equivalent supervision."),
            ("Access mismatch appears to block expected work", "Confirm with administrator or support whether an override is intended."),
        ],
        "issues": [
            ("Edit button is not visible in registry", "Treat this as expected unless the task explicitly says edit rights were granted."),
            ("Supporting documents are missing", "Pause the handoff and request the missing evidence through tasks or messaging."),
            ("File location is unclear", "Reconcile the file-movement log before advancing the case."),
            ("Claims exposure seems relevant but unclear", "Review claims context and escalate the financial question rather than guessing."),
        ],
        "checklist": [
            "Start from the assigned task.",
            "Review the evidence pack before handing off the file.",
            "Record every custody change.",
            "Escalate missing edit rights instead of improvising workarounds.",
            "Leave the next role clear instructions and file location context.",
        ],
    }

    data_entry = {
        "slug": "Data_Entry",
        "role_key": "data_entry",
        "role_label": "Data Entrant",
        "audience": "Data-entry officers, supervisors, trainers, and support teams",
        "role_purpose": "Structured capture, bulk import, registry maintenance, claims posting, and controlled data-quality improvement.",
        "role_type": "Structured capture and reconciliation role",
        "landing_page": "tasks.html",
        "access_note": "This role combines form-based capture with governed import and claims-management capabilities. Accuracy and review discipline are therefore essential.",
        "purpose_paragraphs": [
            "The Data Entrant role is the main structured-capture role in the application. It can create and update staff-due records, maintain registry entries, run bulk imports, manage arrears entries, upload suspensions, and submit life-certificate updates.",
            "Because this role has strong data-changing authority, the standard expected behavior is careful preparation, template discipline, review of import outputs, and immediate escalation of anomalies instead of working around them.",
        ],
        "primary_pages": [
            "tasks.html",
            "staff_due.html",
            "add_staff.html",
            "edit_staff.html",
            "view_staff.html",
            "pension_file_registry.html",
            "file_tracking.html",
            "claims.html",
            "dashboard.html",
            "application_status.html",
            "messages.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "staff_due.edit",
            "staff_due.bulk_upload",
            "staff_due.delete_request",
            "registry.edit",
            "registry.bulk_upload",
            "registry.life_certificate.submit",
            "registry.delete_request",
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "claims.arrears.view",
            "claims.arrears.manage",
            "claims.suspension.upload",
        ],
        "not_in_scope": [
            "Delete-queue processing remains supervisory or administrative.",
            "Budget maintenance and broader governance settings are not default data-entry work.",
            "Payroll cycle management and system configuration are outside this role.",
        ],
        "daily_flow": [
            "Start from tasks or the relevant module and confirm the exact record or batch you are meant to process.",
            "Use the correct governed form or template rather than free-form data manipulation.",
            "Review validation or import-review output before finalizing a batch.",
            "Post claims and suspension data only after confirming beneficiary identity, period, and source evidence.",
            "Hand off exceptions promptly when supervisory or policy judgment is required.",
        ],
        "modules": [
            {
                "title": "Structured Record Capture",
                "overview": "Data entrants are expected to keep staff-due and registry records operationally clean through disciplined form use and evidence-backed editing.",
                "steps": [
                    "Open the exact target record using search, filters, or a task handoff.",
                    "Complete governed fields carefully and avoid partial saves with uncertain data.",
                    "Use life-certificate tools when the case requires beneficiary compliance updates.",
                    "Request deletion through the queue when a record should be removed from normal use.",
                    "Recheck calculated or dependent fields after major edits so the next role sees a coherent record.",
                ],
                "controls": [
                    "Do not enter guessed dates, identifiers, or salary inputs.",
                    "Life-certificate submissions must match real evidence.",
                    "Delete requests are for exceptional cleanup, not convenience.",
                ],
            },
            {
                "title": "Bulk Import Operations",
                "overview": "The live platform gives this role bulk-upload rights for staff-due and registry schedules. This is a high-impact capability and must be used with review discipline.",
                "steps": [
                    "Download and use the current template rather than a locally altered spreadsheet.",
                    "Complete required source fields and keep headers unchanged.",
                    "Run the review or dry-run path first and inspect the generated import-review file.",
                    "Correct validation problems in the source file and rerun review until the output is acceptable.",
                    "Only then execute the final import and preserve the resulting evidence or review output.",
                ],
                "controls": [
                    "Blank or misaligned columns can damage record quality if not caught in review.",
                    "Never bypass the review file when one is produced.",
                    "Bulk upload is not a shortcut for unapproved source data.",
                ],
            },
            {
                "title": "Claims Ledger and Suspension Work",
                "overview": "This role can both view and manage the claims workspace, including arrears posting and suspension imports.",
                "steps": [
                    "Open the claims workspace and verify the correct beneficiary and claim type before creating or editing a record.",
                    "Use the appropriate payment, bulk-payment, gratuity-schedule, or suspension-upload flow rather than forcing one tool to do another task.",
                    "Confirm period, amount, accountability context, and supporting evidence before saving.",
                    "Review import-review output for suspension or bulk-payment files before accepting the final upload.",
                    "Escalate unexplained discrepancies or policy-sensitive cases to supervisory roles promptly.",
                ],
                "controls": [
                    "Financial period and beneficiary identity must be correct before every save.",
                    "Accountability-related fields should reflect real supporting evidence.",
                    "Claims management rights do not remove the need for supervisory escalation where policy or liability is unclear.",
                ],
            },
        ],
        "governance": [
            "Use the correct form or import template for every data-changing action.",
            "Review import and validation output before finalizing batch work.",
            "Keep claims, registry, and staff-due data aligned to real evidence.",
            "Escalate supervisory, queue, or policy decisions instead of improvising them.",
            "Preserve a clean audit trail through comments, statuses, and governed save actions.",
        ],
        "handoffs": [
            ("Delete approval is required", "Send the queue item to OC/Pension-equivalent supervision or an administrator."),
            ("Assessment or approval review is needed", "Hand the case to Assessor, Auditor, or Approver through the task workflow."),
            ("Budget or financial-governance decision is required", "Escalate to OC/Pension-equivalent supervision."),
            ("Import error suggests technical failure rather than bad source data", "Escalate to administrator or support with the review output."),
        ],
        "issues": [
            ("Import review shows many row-level failures", "Correct the source template first and rerun the review; do not import anyway."),
            ("Claims save is blocked", "Recheck beneficiary, period, amount, and whether the role actually has the required current permission."),
            ("Registry action seems unavailable", "Confirm whether the record is in create or edit mode and whether the effective permission was overridden."),
            ("Data conflict appears between staff-due and registry records", "Pause the case, correct the authoritative source, and note the change clearly."),
        ],
        "checklist": [
            "Confirm the exact record or batch before starting work.",
            "Use templates without changing headers.",
            "Review every validation or import-review file.",
            "Verify financial period and beneficiary identity before claims actions.",
            "Escalate queue, policy, and technical failures promptly.",
        ],
    }

    assessor = {
        "slug": "Assessor",
        "role_key": "assessor",
        "role_label": "Assessor",
        "audience": "Assessors, supervisors, trainers, and support teams",
        "role_purpose": "Benefit assessment, pension calculation review, and recommendation of case outcomes for later audit and approval stages.",
        "role_type": "Calculation and assessment role",
        "landing_page": "tasks.html",
        "access_note": "This role is task-led and calculation-focused. The current permission model allows strong benefit-review authority, but some edit controls may still depend on whether the registry edit workspace is exposed in the live UI.",
        "purpose_paragraphs": [
            "The Assessor role is responsible for checking whether the pension case produces the correct benefits outcome based on service history, retirement context, salary inputs, and the current system rules.",
            "Assessors should treat the case as a controlled calculation exercise. The goal is not only to produce a number, but to leave a defendable assessment path that later audit and approval roles can follow without guesswork.",
        ],
        "primary_pages": [
            "tasks.html",
            "pension_file_registry.html",
            "claims.html",
            "file_tracking.html",
            "application_status.html",
            "dashboard.html",
            "messages.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "document_viewer.html",
            "podcast.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "registry.benefits.monthly_salary.edit",
            "registry.benefits.length_service.edit",
            "registry.benefits.amounts.edit",
            "file_movement.record",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "General registry editing is not the core purpose of the role and may not always be exposed in the UI.",
            "Claims posting, delete queues, payroll uploads, and budget maintenance are not default assessor functions.",
            "Final approval remains outside this role.",
        ],
        "daily_flow": [
            "Open the assigned task and confirm the assessment question or expected outcome.",
            "Review the registry record, supporting documents, retirement profile, and any prior workflow comments.",
            "Use the calculator and benefits fields to validate the correct service-based outcome.",
            "If the required edit control is not exposed, leave a clear task note and coordinate with the relevant registry-edit or admin role.",
            "Hand off the completed assessment with enough reasoning for audit and approval.",
        ],
        "modules": [
            {
                "title": "Assessment Queue Review",
                "overview": "Assessment begins with the task queue because the queue provides case ownership, due date, and the current stage context.",
                "steps": [
                    "Read the task instructions and previous workflow comments before reviewing the record.",
                    "Open the registry record and supporting evidence to understand the service and retirement context.",
                    "Identify the calculation inputs that matter most for the case, especially salary, service duration, and retirement type.",
                    "Record questions or discrepancies as formal task comments rather than private notes.",
                    "Update task state only when the assessment work has genuinely moved forward.",
                ],
                "controls": [
                    "Assessment comments should explain reasoning, not just conclusions.",
                    "Do not skip source-record review and rely only on dashboard summaries.",
                    "Escalate unresolved source-data conflicts promptly.",
                ],
            },
            {
                "title": "Benefits Calculation and Validation",
                "overview": "The key assessor responsibility is to validate whether the benefit outcome shown by the system is correct and defensible.",
                "steps": [
                    "Use the Benefits Calculator to model the expected result based on the case facts.",
                    "Review monthly salary, length of service, and benefit amount fields in the registry context where the edit controls are exposed.",
                    "If a field clearly requires correction, change it only through the supported UI and only when the evidence justifies it.",
                    "If the live UI does not expose the needed edit control, record the required correction and route it through the appropriate governed path.",
                    "Document the conclusion in the task or workflow notes so later reviewers can follow the logic.",
                ],
                "controls": [
                    "Never change calculated values to force a preferred outcome.",
                    "Every change should be tied to evidence or a validated calculation result.",
                    "Where control visibility is limited, use escalation instead of unsupported workarounds.",
                ],
            },
            {
                "title": "Claims and File Context",
                "overview": "Claims visibility and file movement provide supporting context for a complete assessment, especially where financial exposure or file location affects timing.",
                "steps": [
                    "Review the claims workspace to understand arrears exposure that may depend on the assessment outcome.",
                    "Use file movement logging when the file changes custody during assessment work.",
                    "Check application status so the case is handed to the right downstream role.",
                    "Use messages where targeted clarification is needed from the previous handler.",
                    "Keep the assessment package ready for audit without hiding unresolved issues.",
                ],
                "controls": [
                    "Claims visibility is contextual unless separate claims-management authority exists.",
                    "Movement logs must stay aligned with real custody.",
                    "Do not hand off a case with unresolved calculation uncertainty hidden in comments.",
                ],
            },
        ],
        "governance": [
            "Tie every assessment outcome to evidence and explicit reasoning.",
            "Use calculator and benefits tools carefully and consistently.",
            "Escalate missing or hidden edit controls instead of bypassing them.",
            "Keep file-custody and task state synchronized with the real case state.",
            "Prepare the case so audit can understand it without redoing the whole assessment from scratch.",
        ],
        "handoffs": [
            ("Assessment is complete", "Hand off to Auditor with a clear note on the logic, assumptions, and any residual issues."),
            ("Registry or source data needs correction outside exposed controls", "Route to a registry-edit role or ask an administrator to reconcile access if needed."),
            ("Policy or supervisory issue affects the outcome", "Escalate to OC/Pension-equivalent supervision."),
            ("Technical mismatch affects calculation behavior", "Escalate to administrator or engineering support with exact evidence."),
        ],
        "issues": [
            ("Benefit edit controls are not visible", "Confirm whether the live UI currently exposes them for your session, then escalate if the task depends on them."),
            ("Calculator result and registry snapshot disagree", "Recheck salary, service, retirement type, and date inputs before concluding there is a system defect."),
            ("Claims exposure suggests a high-impact change", "Record the implication and escalate it rather than forcing the case forward silently."),
            ("Source documents conflict", "Pause the assessment and request correction or clarification."),
        ],
        "checklist": [
            "Read the assigned task before opening the record.",
            "Validate inputs before validating outputs.",
            "Use evidence-backed reasoning for every conclusion.",
            "Escalate access or source-data problems promptly.",
            "Hand audit a case that is traceable and explainable.",
        ],
    }

    auditor = {
        "slug": "Auditor",
        "role_key": "auditor",
        "role_label": "Auditor",
        "audience": "Auditors, supervisors, trainers, and support teams",
        "role_purpose": "Audit-stage review of assessed pension cases, evidence reconciliation, and readiness confirmation before approval.",
        "role_type": "Audit and quality-assurance role",
        "landing_page": "tasks.html",
        "access_note": "This role is review-oriented. It should verify what earlier roles did, not repeat or replace every prior action unless an exception must be formally returned.",
        "purpose_paragraphs": [
            "The Auditor role is responsible for checking whether the assessed case is coherent, evidence-backed, and ready for approval. Auditors should look for control failures, unsupported conclusions, missing documents, and inconsistencies across workflow steps.",
            "The audit stage adds assurance. A good audit outcome should tell the approver that the case is not only complete, but also understandable and defensible from the available evidence and system trail.",
        ],
        "primary_pages": [
            "tasks.html",
            "pension_file_registry.html",
            "claims.html",
            "file_tracking.html",
            "application_status.html",
            "dashboard.html",
            "messages.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "document_viewer.html",
            "podcast.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "Audit is not the stage for normal claims posting or broad registry correction work.",
            "Delete queues, budget maintenance, and payroll control are not default auditor tasks.",
            "Final approval remains outside the audit role.",
        ],
        "daily_flow": [
            "Open the assigned audit task and confirm the expected review scope.",
            "Review the assessment notes, registry record, supporting documents, and claims context before deciding anything.",
            "Check whether the case can be defended from the recorded evidence and workflow history.",
            "Return the case with precise reasons if material gaps or contradictions remain.",
            "Advance the case only when the audit trail and evidence package support it.",
        ],
        "modules": [
            {
                "title": "Audit Task Review",
                "overview": "Audit work begins with the assigned task because the task history shows what previous roles believed they completed.",
                "steps": [
                    "Read the task history and prior role comments before reviewing source records.",
                    "Identify the key control points that should have been satisfied by earlier stages.",
                    "Use the registry and document viewer to verify that the evidence aligns with the assessment narrative.",
                    "Record exceptions clearly so the prior role can correct them without guesswork.",
                    "Update task state only after the audit conclusion is justified.",
                ],
                "controls": [
                    "Audit comments should identify specific gaps, not vague dissatisfaction.",
                    "Avoid reopening resolved issues unless the evidence truly contradicts the earlier outcome.",
                    "Keep the distinction between review and rework clear.",
                ],
            },
            {
                "title": "Evidence and Calculation Assurance",
                "overview": "Auditors are expected to test coherence across inputs, outputs, and supporting evidence, even when they are not the primary calculation owners.",
                "steps": [
                    "Review benefit-related fields and calculator context to confirm that the assessed outcome is plausible.",
                    "Where a simple supporting field is clearly wrong and the control is exposed, correct it through the governed UI or return it with explanation.",
                    "Check the document set, file location, and application status for consistency with the stage claimed by the task.",
                    "Use claims visibility to understand whether the case has financial implications that require extra caution.",
                    "Return the case if audit concerns remain material.",
                ],
                "controls": [
                    "Do not convert an audit into undocumented re-engineering of the whole case.",
                    "Where edit controls are limited, return the case with a precise correction request.",
                    "Financial implications should be noted explicitly for the approver.",
                ],
            },
        ],
        "governance": [
            "Use audit to confirm control quality, not to hide uncertainty.",
            "Return cases with exact actionable reasons.",
            "Use evidence, registry detail, and workflow history together when judging readiness.",
            "Record file movement accurately when custody changes during review.",
            "Prepare approval-stage reviewers to understand both the strengths and the residual risks of the case.",
        ],
        "handoffs": [
            ("Case passes audit", "Move it to the Approver with a clear note that audit checks were completed."),
            ("Assessment logic or source data is still weak", "Return it to the Assessor or the earlier handling role with specific corrections."),
            ("Supervisory or policy judgment is needed", "Escalate to OC/Pension-equivalent supervision."),
            ("Technical defect or access anomaly affects the review", "Escalate to administrator or support with the exact failing scenario."),
        ],
        "issues": [
            ("Documents do not support the assessment note", "Return the case and identify the missing or conflicting evidence."),
            ("Application status and task history disagree", "Reconcile the workflow trail before advancing the case."),
            ("Registry control needed but not exposed", "Document the required correction and route the case back through the governed path."),
            ("Claims exposure appears unusual", "Flag it clearly so the approver sees the financial context."),
        ],
        "checklist": [
            "Read task history before judging the case.",
            "Test the case against evidence, not assumption.",
            "Return cases with specific reasons and required actions.",
            "Keep approval-stage users informed about material risks or anomalies.",
            "Do not let audit comments become ambiguous.",
        ],
    }

    approver = {
        "slug": "Approver",
        "role_key": "approver",
        "role_label": "Approver",
        "audience": "Approvers, supervisors, trainers, and support teams",
        "role_purpose": "Final approval-stage control for pension workflow, with responsibility for acceptance, return, or escalation of cases.",
        "role_type": "Final decision role",
        "landing_page": "tasks.html",
        "access_note": "This role is decision-oriented. The platform expects approvers to review a complete workflow trail rather than perform routine upstream capture work.",
        "purpose_paragraphs": [
            "The Approver role gives final workflow authority over cases that have already been assessed and audited. Approvers should confirm that the case is ready for decision, that audit concerns were resolved properly, and that the supporting record is strong enough to defend the outcome.",
            "The live application exposes the same core review surfaces used by audit and assessment roles, but the approver's job is different: make the final decision, return the case with clear reasons, or escalate where policy or governance risk remains unresolved.",
        ],
        "primary_pages": [
            "tasks.html",
            "pension_file_registry.html",
            "claims.html",
            "file_tracking.html",
            "application_status.html",
            "dashboard.html",
            "messages.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "document_viewer.html",
            "podcast.html",
            "profile.html",
            "edit_user.html",
        ],
        "capabilities": [
            "registry.benefits.monthly_salary.edit",
            "file_movement.record",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "Routine line editing, claims posting, delete queues, and budget control are not default approver work.",
            "System governance and access configuration remain administrative responsibilities.",
            "Approval should not be used to hide unresolved upstream errors.",
        ],
        "daily_flow": [
            "Open the assigned approval task and read the full workflow trail first.",
            "Review audit outcome, registry record, supporting documents, and financial context before deciding.",
            "Approve only when the case is complete, coherent, and institutionally defensible.",
            "Return the case with precise required actions if correction is still needed.",
            "Record the final decision clearly so downstream users understand what happened.",
        ],
        "modules": [
            {
                "title": "Approval Queue Review",
                "overview": "Approvers should begin from the task queue so the final decision is tied to the correct case, comments, and prior role actions.",
                "steps": [
                    "Open the approval task and read the history from earlier roles.",
                    "Confirm that assessment and audit stages have both added enough evidence and explanation.",
                    "Review registry, application-status, and claims context to understand the whole case picture.",
                    "Where necessary, use messages to request precise clarification before deciding.",
                    "Complete the approval or return action through the governed workflow path.",
                ],
                "controls": [
                    "Final decisions should be based on the recorded case, not memory or side conversation.",
                    "Do not approve a case simply because it is overdue.",
                    "Returned cases should include specific next steps.",
                ],
            },
            {
                "title": "Decision Assurance",
                "overview": "Approvers should use registry details, documents, and the benefits context to make sure the decision can be defended later.",
                "steps": [
                    "Review the supporting documents and benefit context before accepting the case outcome.",
                    "Check whether any visible anomalies remain in salary, service, claims exposure, or application history.",
                    "Where a minor supporting correction is allowed and clearly justified, use the governed control; otherwise return the case.",
                    "Record why the case was approved, returned, or escalated if that reasoning is not already clear from the trail.",
                    "Confirm file movement or case location where custody matters to the next administrative step.",
                ],
                "controls": [
                    "Approval should never conceal unresolved contradictions.",
                    "Visible edit controls do not remove the need for explanation and traceability.",
                    "Custody state should remain consistent with the decision state.",
                ],
            },
        ],
        "governance": [
            "Read the full workflow trail before making a final decision.",
            "Approve only cases that are complete and defensible.",
            "Return cases with explicit instructions when gaps remain.",
            "Use claims and registry context to understand the implications of the decision.",
            "Leave a clear final decision trail for operations and audit.",
        ],
        "handoffs": [
            ("Case is acceptable", "Approve it and ensure the next administrative step is clear."),
            ("Case needs correction", "Return it to the correct earlier role with explicit required actions."),
            ("Policy or governance uncertainty remains", "Escalate to OC/Pension-equivalent leadership or administration."),
            ("Technical problem blocks final review", "Escalate to administrator or support with the precise failure evidence."),
        ],
        "issues": [
            ("Audit note and case record still disagree", "Do not approve; return the case for correction or clarification."),
            ("Financial context appears high-risk", "Review claims exposure carefully and escalate if supervisory input is needed."),
            ("Required document is missing", "Return the case rather than approving on assumption."),
            ("Application status looks stale", "Confirm the true workflow stage before finalizing the decision."),
        ],
        "checklist": [
            "Read the full workflow trail.",
            "Verify audit completeness before approval.",
            "Approve only defensible cases.",
            "Return cases with clear reasons and next steps.",
            "Leave a final decision trail that others can follow.",
        ],
    }

    user = {
        "slug": "User",
        "role_key": "user",
        "role_label": "User",
        "audience": "General internal users, enquiry staff, supervisors, trainers, and support teams",
        "role_purpose": "General internal access for enquiry, status checking, claims visibility, and use of reference tools.",
        "role_type": "General internal access role",
        "landing_page": "dashboard.html",
        "access_note": "This is a lighter internal role. It supports visibility, enquiry, and limited guided actions rather than full workflow ownership.",
        "purpose_paragraphs": [
            "The User role provides general internal access to shared visibility and reference workspaces. It is appropriate where a user needs to review dashboard information, inspect application status, view claims context, or use pension reference tools without taking ownership of the workflow queue.",
            "Users should treat this role as an enquiry and support role. If a required action is not visible, that is usually expected and the task should be escalated to the role that owns the governed workflow step.",
        ],
        "primary_pages": [
            "dashboard.html",
            "pension_file_registry.html",
            "application_status.html",
            "claims.html",
            "benefits_calculator.html",
            "faq.html",
            "about.html",
            "profile.html",
        ],
        "support_pages": [
            "budgeting.html",
            "podcast.html",
            "document_viewer.html",
            "edit_user.html",
        ],
        "capabilities": [
            "registry.benefits.monthly_salary.edit",
            "claims.arrears.view",
        ],
        "not_in_scope": [
            "This role does not own normal task workflow, delete queues, bulk imports, or supervisory approvals.",
            "Claims management, registry editing, payroll control, and budget maintenance are not default user responsibilities.",
            "Messages, workflow routing, and Settings actions are outside the standard role surface.",
        ],
        "daily_flow": [
            "Sign in and review the dashboard or application-status view for the information you need.",
            "Open the registry or claims workspace only for controlled enquiry and reference.",
            "Use the Benefits Calculator, FAQs, About, or Podcast tools when you need guidance or context.",
            "If the workflow requires an action you cannot perform, escalate it to the responsible role rather than searching for a workaround.",
            "Keep any notes or escalations concise and specific so the owning role can act quickly.",
        ],
        "modules": [
            {
                "title": "Dashboard and Status Enquiry",
                "overview": "The shared dashboard and application-status pages are the primary work areas for general internal visibility.",
                "steps": [
                    "Open the dashboard and use filters or search before drawing a conclusion from what you see.",
                    "Use application status to confirm the stage of a case and whether it appears active, pending, or completed.",
                    "Where you need more detail, open the registry or claims workspace for reference.",
                    "If the case needs action outside your role, document the issue and escalate it.",
                    "Avoid treating dashboard snapshots as the final record; confirm in the source workspace where needed.",
                ],
                "controls": [
                    "Visibility is not the same as authority to change data.",
                    "Use filters carefully so you do not report the wrong scope.",
                    "Escalate operational actions to the owning role.",
                ],
            },
            {
                "title": "Reference Tools and Guided Support",
                "overview": "This role can use the benefits calculator and public-guidance pages to support internal enquiry and explanation.",
                "steps": [
                    "Use the benefits calculator for indicative pension-output understanding only.",
                    "Use FAQs, About, and Podcast content when you need current guided explanations of platform behavior or pension topics.",
                    "Open documents through the document viewer only for approved enquiry or support work.",
                    "Use profile and account-update pages to keep your own account details current.",
                    "Escalate anything that requires a governed data or workflow change.",
                ],
                "controls": [
                    "Calculator results are guidance unless confirmed through the governed workflow.",
                    "Reference pages do not override official decisions or workflow stages.",
                    "Document access must remain tied to legitimate work purposes.",
                ],
            },
        ],
        "governance": [
            "Use the role for enquiry, visibility, and guided support, not for workflow ownership.",
            "Do not assume a missing action is an error; it is often a role boundary.",
            "Confirm source data before escalating dashboard observations.",
            "Treat viewed documents and claims data as sensitive records.",
            "Escalate to the correct workflow or supervisory role for governed changes.",
        ],
        "handoffs": [
            ("Case needs workflow action", "Route it to the role that owns the current workflow step."),
            ("Registry or staff data needs correction", "Escalate to a registry-edit or staff-due edit role."),
            ("Financial or claims mutation is required", "Escalate to a role with claims-management authority."),
            ("Access or visibility seems wrong", "Escalate to an administrator or support contact."),
        ],
        "issues": [
            ("Edit controls are absent", "Treat that as expected unless an administrator confirmed you should have broader rights."),
            ("Dashboard numbers seem inconsistent", "Recheck filters, date scope, and the source module before escalating."),
            ("Application stage is unclear", "Use the application-status and registry views together, then escalate if still ambiguous."),
            ("Calculator output differs from expectation", "Treat it as indicative and escalate to the assessment workflow for formal review."),
        ],
        "checklist": [
            "Use the dashboard for visibility and source modules for confirmation.",
            "Respect role boundaries when actions are not exposed.",
            "Use reference tools for guidance, not final decisions.",
            "Escalate governed changes to the correct owner.",
            "Handle viewed records as sensitive information.",
        ],
    }

    pensioner = {
        "slug": "Pensioner",
        "role_key": "pensioner",
        "role_label": "Pensioner",
        "audience": "Pensioners, pensioner-support staff, trainers, and service desk teams",
        "role_purpose": "Beneficiary self-service access for personal pension visibility, limited profile updates, and consent-based pensioner lookup.",
        "role_type": "Self-service beneficiary role",
        "landing_page": "pensioner_board.html",
        "access_note": "Portal features can be turned on or off by the pensions office. A pensioner may therefore see fewer sections than another user if claims, documents, or lookup access are currently disabled.",
        "purpose_paragraphs": [
            "The Pensioner role is a self-service role for beneficiaries. It provides a controlled dashboard showing pension profile information, application progress, benefits snapshot, compliance status, claims visibility, indexed documents, and lifecycle information when the linked record exists.",
            "The role is intentionally limited. Pensioners should use the dashboard for information and permitted updates, but any formal correction or administrative decision still belongs to the pensions office and governed staff workflow.",
        ],
        "primary_pages": [
            "pensioner_board.html",
            "pensioner_lookup.html",
            "profile.html",
            "edit_user.html",
        ],
        "support_pages": [
            "benefits_calculator.html",
            "podcast.html",
            "document_viewer.html",
        ],
        "portal_features": [
            ["Profile", "Always part of the dashboard shell", "Shows core personal and registry-linked identity details where available."],
            ["Application progress", "Shown from the pensioner dashboard", "Lets the pensioner follow case stages and progression steps."],
            ["Benefits summary", "Shown from the pensioner dashboard", "Displays calculated or recorded benefits context for the linked record."],
            ["Compliance", "Shown from the pensioner dashboard", "Displays payroll status, account standing, and life-certificate state."],
            ["Claims", "Shown only when the portal setting enables claims", "Displays outstanding arrears, claim entries, and recent balances where available."],
            ["Documents", "Shown only when the portal setting enables documents", "Displays indexed record documents for the linked pension file."],
            ["Lifecycle", "Shown from the pensioner dashboard", "Provides retirement-category and status context for the record."],
            ["Pensioner lookup", "Shown only when directory access is enabled", "Allows searching other pensioners who consented to be visible in the directory."],
            ["Consent controls", "Managed through dashboard-linked profile settings", "Controls whether the pensioner appears in the lookup directory."],
            ["Account update", "Available through profile and edit-user pages", "Supports limited personal account or contact changes."],
        ],
        "not_in_scope": [
            "The pensioner role does not access staff workflow, task queues, claims posting, registry editing, or internal governance pages.",
            "Some dashboard panels may remain hidden if the pensions office has disabled that feature.",
            "Formal corrections still require pension-office review even when profile updates are submitted.",
        ],
        "daily_flow": [
            "Sign in and open the pensioner dashboard.",
            "Review the dashboard summary cards and open the tab that matches the information you need.",
            "Use profile-related controls to update only the fields the portal allows you to change.",
            "If claims, documents, or lookup are hidden, treat that as a policy setting or account-state condition rather than a normal user error.",
            "Escalate unresolved problems to the pensions office or support route shown by the platform.",
        ],
        "modules": [
            {
                "title": "Pensioner Dashboard",
                "overview": "The pensioner dashboard is organized into clearly separated work areas such as profile, application, benefits, compliance, claims, and lifecycle so that the user can review information without entering staff-only modules.",
                "steps": [
                    "Open the dashboard and wait for the linked pension record to load fully.",
                    "Use the visible tabs to review profile details, application progress, benefits information, and compliance status.",
                    "Read any warning or information banners carefully because they often explain life-certificate, claims, or account issues.",
                    "If a section is not visible, check whether it may be disabled by the pensions office or unavailable because the record is not yet linked.",
                    "Use the dashboard as the main reference point instead of attempting to access staff-only pages.",
                ],
                "controls": [
                    "Dashboard information depends on a correctly linked pensioner record.",
                    "Missing tabs are often policy- or linkage-related, not necessarily a browser problem.",
                    "Do not share sensitive personal information from the dashboard casually.",
                ],
            },
            {
                "title": "Profile, Contact, and Lookup",
                "overview": "Pensioners can manage limited personal details and directory consent through the self-service tools.",
                "steps": [
                    "Open the profile or account-update page and review the existing details before changing anything.",
                    "Update only the fields the portal exposes, such as contact-focused items and approved profile fields.",
                    "If the dashboard restricts certain bereavement-related or contact fields, respect that restriction and contact support if a formal change is needed.",
                    "Use the Find Pensioners page only for legitimate contact lookup and only where the directory is enabled.",
                    "Set visibility or consent carefully because only pensioners who agree to be visible should appear in the directory.",
                ],
                "controls": [
                    "Directory results respect consent settings.",
                    "Some profile fields may be intentionally read-only in sensitive record states.",
                    "Account updates do not override formal pensions-office records automatically in every case.",
                ],
            },
            {
                "title": "Claims, Documents, and Compliance",
                "overview": "Where enabled, the pensioner dashboard shows claims, indexed documents, and compliance guidance such as life-certificate status or payroll standing.",
                "steps": [
                    "Review claims or balances only within the dashboard and use the displayed explanations to understand what the figures mean.",
                    "Open indexed documents through the viewer when available and keep them confidential.",
                    "Check compliance or life-certificate warnings promptly and follow the guidance given by the pensions office.",
                    "Use the benefits calculator and media-centre tools only for guidance and understanding, not as a substitute for official decisions.",
                    "If a document or claim entry seems wrong, report it through the official support channel rather than trying to change it yourself.",
                ],
                "controls": [
                    "Claims and documents may be hidden by current portal settings.",
                    "Dashboard figures are informational; formal corrections still require staff review.",
                    "Life-certificate reminders should be taken seriously and acted on promptly.",
                ],
            },
        ],
        "governance": [
            "Use the pensioner dashboard as the main self-service workspace.",
            "Keep credentials private and do not share account access.",
            "Treat dashboard documents and financial information as confidential.",
            "Respect directory consent and privacy expectations.",
            "Escalate formal corrections to the pensions office instead of trying to bypass portal limits.",
        ],
        "handoffs": [
            ("Portal login fails", "Contact the pensions office or support route to confirm account linkage, portal availability, or credentials."),
            ("A record item looks incorrect", "Report it for staff review through the governed support path."),
            ("Claims, documents, or lookup are missing", "Check whether the feature may be disabled, then escalate if needed."),
            ("Life-certificate reminder appears", "Follow the official submission process promptly and confirm with the pensions office if guidance is unclear."),
        ],
        "issues": [
            ("Dashboard says the record is unavailable", "The account may not yet be linked to a pensioner record; contact support for linkage review."),
            ("Claims or documents are missing", "Those sections may be disabled or no data may be linked yet."),
            ("Lookup directory shows no result", "Only pensioners who consented to be visible can appear in the directory."),
            ("Login is blocked", "Portal access may be disabled administratively or the account may need support review."),
        ],
        "checklist": [
            "Use the dashboard first for all pensioner self-service work.",
            "Review compliance and life-certificate messages promptly.",
            "Update only the fields the portal allows.",
            "Keep documents and financial information private.",
            "Contact the pensions office when formal correction is needed.",
        ],
    }

    specs = [
        admin,
        approver,
        assessor,
        auditor,
        clerk,
        data_entry,
        dep_oc,
        file_creator,
        oc_pen,
        pensioner,
        user,
        writeup_officer,
    ]
    return specs


def build_role_manuals() -> list[Path]:
    ROLE_DOCS.mkdir(parents=True, exist_ok=True)
    specs = build_specs()
    generated_paths: list[Path] = []

    for spec in specs:
        base = output_base_name(spec)
        md_path = ROLE_DOCS / f"{base}.md"
        docx_path = ROLE_DOCS / f"{base}.docx"
        pdf_path = ROLE_DOCS / f"{base}.pdf"

        md_path.write_text(build_markdown(spec), encoding="utf-8")
        render_markdown_to_docx(
            md_path,
            docx_path,
            {
                "title": f"UPS PensionsGo Role Manual: {spec['role_label']}",
                "description": f"Current-state operational manual for the {spec['role_label']} role.",
                "audience": spec["audience"],
                "classification": "Controlled Operational Copy",
                "version": "1.0",
                "status": "Current Repository Snapshot",
                "snapshot_date": SNAPSHOT_DATE,
                "document_owner": "Operations and User Support",
                "prepared_by": "OpenAI Codex",
                "logo_path": SHARED_LOGO,
                "revision_history": revision_summary(spec["role_label"]),
                "approvals": [
                    ("Prepared by", "Operations Documentation"),
                    ("Reviewed by", "Role Supervisor / Pensions Office"),
                    ("Approved by", "Head of Pensions / Project Sponsor"),
                ],
            },
        )
        export_docx_to_pdf(docx_path, pdf_path)
        generated_paths.extend([md_path, docx_path, pdf_path])

    readme_path = ROLE_DOCS / "README.md"
    readme_path.write_text(build_readme(specs), encoding="utf-8")
    generated_paths.append(readme_path)
    return generated_paths


def main() -> list[Path]:
    return build_role_manuals()


if __name__ == "__main__":
    main()
