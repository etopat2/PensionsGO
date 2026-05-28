# Documentation Suite

This folder contains the maintained documentation sources and generated deliverables for UPS PensionsGo.

Current repository snapshot referenced by the system documents: `2026-05-28`. The maintained inventory is 41 frontend HTML pages, 50 JavaScript files, 264 PHP API endpoints, and 83 schema tables.

## Source Documents

- `PensionApp_System_Documentation.md`
- `PensionApp_User_Admin_Manual.md`
- `PensionApp_Concept_Paper.md`
- `PensionApp_Project_Proposal.md`
- `role_manuals/`
- `erd.md` and `erd-domains/`
- `versioning.md`
- `DFDs/`
- `retirement_type_formula_handout.md`
- `innovator-showcase-system-description.md`
- `innovator-showcase-system-description-5-page.md`
- `UPS-PensionsGo-Comprehensive-System-Architecture.md`

## Generated Deliverables

- Word: `*.docx`
- PDF: `*.pdf`
- Presentations: `*.pptx`
  - includes `PensionApp_Technical_Team_Presentation.pptx`
  - includes `PensionApp_Users_Presentation.pptx`
  - includes `PensionApp_Comprehensive_Training_Presentation.pptx`
- Architecture diagrams:
  - `UPS-PensionsGo-Architecture-Diagram.svg`
  - `UPS-PensionsGo-Comprehensive-System-Architecture.svg`

## Build Toolchain

- `generate_erd_docs.py`
  - regenerates the full ERD, domain ERDs, and inter-domain views from `database/schema.sql`
- `build_documentation_suite.py`
  - regenerates the ERD pack, role manuals, Word documents, PDF exports, handout, and presentation decks
- `build_role_manuals.py`
  - regenerates the role-by-role markdown manuals and exports them to Word/PDF in `docs/role_manuals/`
- `build_retirement_type_handout.py`
  - rebuilds the formula handout DOCX
- `build_presentation_decks.py`
  - rebuilds the technical, user, and comprehensive stakeholder training decks
- `export_docx_to_pdf.ps1`
  - exports DOCX files to PDF through Microsoft Word COM on Windows

## Recommended Rebuild Command

```powershell
python docs/build_documentation_suite.py
```

## Notes

- PDF generation currently depends on Microsoft Word being available on the Windows host.
- The technical documentation markdown is regenerated from the system documentation baseline during the build.
- The ERD outputs are generated directly from `database/schema.sql`, so schema changes should be made there first.
- Keep the system documentation, innovator showcase descriptions, and DFD pack aligned when adding major modules such as live chat, pensioner lifecycle reporting, imports/exports, notification controls, backup/restore, or public-service settings.
