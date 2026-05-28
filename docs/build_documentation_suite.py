from __future__ import annotations

from datetime import date
from pathlib import Path
import shutil
import subprocess
import sys

from build_role_manuals import main as build_role_manuals
from build_presentation_decks import build_technical_deck, build_training_deck, build_user_deck, save_deck
from build_retirement_type_handout import main as build_retirement_type_handout
from generate_erd_docs import main as build_erd_docs
from md_to_docx import render_markdown_to_docx


ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
SNAPSHOT_DATE = date.today().isoformat()
PDF_EXPORTER = DOCS / "export_docx_to_pdf.ps1"


def ensure_technical_markdown() -> Path:
    source = DOCS / "PensionApp_System_Documentation.md"
    target = DOCS / "PensionApp_Technical_Documentation.md"
    shutil.copyfile(source, target)
    return target


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


def render_document(md_path: Path, docx_name: str, metadata: dict, pdf_name: str | None = None) -> Path:
    docx_path = DOCS / docx_name
    render_markdown_to_docx(md_path, docx_path, metadata)
    if pdf_name:
        export_docx_to_pdf(docx_path, DOCS / pdf_name)
    return docx_path


def shared_revision(summary: str) -> list[dict]:
    return [
        {
            "version": "1.1",
            "date": SNAPSHOT_DATE,
            "summary": summary,
            "author": "OpenAI Codex",
            "approval": "Pending",
        }
    ]


def build_markdown_documents() -> None:
    technical_md = ensure_technical_markdown()
    shared_logo = str(ROOT / "favicon.png")

    render_document(
        DOCS / "PensionApp_System_Documentation.md",
        "PensionApp_System_Documentation.docx",
        {
            "title": "PensionApp System Documentation",
            "description": "Current-state master reference covering architecture, operations, data, security, and governance.",
            "audience": "Project stakeholders, engineers, administrators, and support teams",
            "document_owner": "UPS PensionsGo Project Team",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "Refreshed for the current repository snapshot, schema baseline, and May 2026 documentation rebuild."
            ),
        },
        pdf_name="PensionApp_System_Documentation.pdf",
    )

    render_document(
        DOCS / "PensionApp_System_Documentation.md",
        "PensionApp_System_Documentation_Branded.docx",
        {
            "title": "PensionApp System Documentation",
            "description": "Current-state master reference covering architecture, operations, data, security, and governance.",
            "audience": "Project stakeholders, engineers, administrators, and support teams",
            "document_owner": "UPS PensionsGo Project Team",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "Branded system documentation refreshed for the current repository snapshot and generated deliverable suite."
            ),
            "approvals": [
                ("Prepared by", "Project Documentation / Engineering"),
                ("Reviewed by", "Technical Lead / Operations Lead"),
                ("Approved by", "Project Sponsor / Head of Pensions"),
            ],
        },
        pdf_name="PensionApp_System_Documentation_Branded.pdf",
    )

    render_document(
        technical_md,
        "PensionApp_Technical_Documentation.docx",
        {
            "title": "PensionApp Technical Documentation",
            "description": "Architecture, code-structure, data-model, runtime controls, and engineering reference.",
            "audience": "Developers, solution architects, support engineers, and database administrators",
            "document_owner": "Engineering and Solution Design",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "Technical reference regenerated from the updated system documentation baseline and current repository inventory."
            ),
            "approvals": [
                ("Prepared by", "Engineering Documentation"),
                ("Reviewed by", "Technical Lead / DBA"),
                ("Approved by", "Solution Architect / Project Sponsor"),
            ],
        },
        pdf_name="PensionApp_Technical_Documentation.pdf",
    )

    render_document(
        DOCS / "PensionApp_User_Admin_Manual.md",
        "PensionApp_User_Admin_Manual.docx",
        {
            "title": "PensionApp User and Admin Manual",
            "description": "Operational guide for day-to-day users, supervisors, administrators, and support teams.",
            "audience": "Operational staff, supervisors, administrators, and support teams",
            "document_owner": "Operations and User Support",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "User and admin manual refreshed for the current application modules, governance flows, and support procedures."
            ),
            "approvals": [
                ("Prepared by", "Operations Documentation"),
                ("Reviewed by", "Admin Lead / Pensions Office"),
                ("Approved by", "Head of Pensions / Project Sponsor"),
            ],
        },
        pdf_name="PensionApp_User_Admin_Manual.pdf",
    )

    render_document(
        DOCS / "PensionApp_Concept_Paper.md",
        "PensionApp_Concept_Paper.docx",
        {
            "title": "UPS PensionsGo Concept Paper",
            "description": "Current-state concept note describing the implemented platform, its institutional value, and next-phase priorities.",
            "audience": "Institutional stakeholders, project sponsors, and governance reviewers",
            "document_owner": "Uganda Prisons Service / Pensions Function",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "Concept paper updated from a pre-implementation narrative to the current implemented platform baseline."
            ),
        },
        pdf_name="PensionApp_Concept_Paper.pdf",
    )

    render_document(
        DOCS / "PensionApp_Project_Proposal.md",
        "PensionApp_Project_Proposal.docx",
        {
            "title": "UPS PensionsGo Project Proposal",
            "description": "Current-state proposal for stabilization, rollout, governance hardening, and next-phase execution.",
            "audience": "Institutional leadership, project sponsors, and implementation stakeholders",
            "document_owner": "Uganda Prisons Service / Project Steering Team",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "Project proposal refreshed to reflect the live platform baseline and current next-phase delivery priorities."
            ),
        },
        pdf_name="PensionApp_Project_Proposal.pdf",
    )

    render_document(
        DOCS / "erd.md",
        "ERD.docx",
        {
            "title": "PensionApp ERD Reference",
            "description": "Current full-schema ERD reference generated from database/schema.sql.",
            "audience": "Developers, analysts, DBAs, and support engineers",
            "document_owner": "Engineering and Data Architecture",
            "prepared_by": "OpenAI Codex",
            "logo_path": shared_logo,
            "snapshot_date": SNAPSHOT_DATE,
            "revision_history": shared_revision(
                "ERD reference regenerated from the maintained schema and exported to Word/PDF."
            ),
        },
        pdf_name="ERD.pdf",
    )


def build_handout_and_presentations() -> None:
    build_retirement_type_handout()
    export_docx_to_pdf(
        DOCS / "Retirement_Type_Formula_Handout.docx",
        DOCS / "Retirement_Type_Formula_Handout.pdf",
    )
    save_deck(build_technical_deck(), DOCS / "PensionApp_Technical_Team_Presentation.pptx")
    save_deck(build_user_deck(), DOCS / "PensionApp_Users_Presentation.pptx")
    save_deck(build_training_deck(), DOCS / "PensionApp_Comprehensive_Training_Presentation.pptx")


def build_suite() -> None:
    build_erd_docs()
    build_markdown_documents()
    build_role_manuals()
    build_handout_and_presentations()


if __name__ == "__main__":
    build_suite()
