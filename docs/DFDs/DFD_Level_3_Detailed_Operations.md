# DFD Level 3 - Detailed Pension Operations and Governance

This Level 3 DFD expands the core pension case-management backbone that sits inside the broader UPS PensionsGo platform. It traces how intake, queue routing, registry control, payroll reconciliation, pensioner service, messaging, lifecycle reporting, and deletion governance interact at a detailed operational level.

```mermaid
flowchart LR
  classDef external fill:#eef2ff,stroke:#1d4ed8,color:#1e3a8a,stroke-width:1.2px;
  classDef process fill:#fff7ed,stroke:#c2410c,color:#7c2d12,stroke-width:1.5px;
  classDef store fill:#ecfeff,stroke:#0f766e,color:#134e4a,stroke-width:1.3px;

  Staff[Operational Staff]
  Supervisors[Supervisors and Approvers]
  Pensioners[Pensioners]
  Payroll[Payroll Source Files]
  Admin[Administrators and ICT]

  subgraph Core_Pension_Backbone[Process 3.0 Detailed Pension Operations and Governance]
    direction TB
    P31((3.1 Capture Staff Due and Retirement Data))
    P32((3.2 Validate Applications and Supporting Data))
    P33((3.3 Route Queue Tasks and Alerts))
    P34((3.4 Create and Update Pension File Registry))
    P35((3.5 Manage Documents File Movement and Life Certificates))
    P36((3.6 Assess Approve and Advance Case Status))
    P37((3.7 Reconcile Payroll and Monthly Status))
    P38((3.8 Record Arrears and Accountability))
    P39((3.9 Serve Pensioner Dashboard Contact Updates and Lifecycle Reports))
    P310((3.10 Govern Messaging Delete Requests Recovery and Audit))
  end

  D31[(D31 Users Roles Sessions)]
  D32[(D32 Staff Due Application Queue Claims)]
  D33[(D33 Tasks Alerts Comments Workflow Logs)]
  D34[(D34 Registry and Core Pensioner Records)]
  D35[(D35 Documents File Movement Life Certificate and Delete Queues)]
  D36[(D36 Payroll Cycles Entries Monthly Status Arrears Budget)]
  D37[(D37 Audit Logs Imports Exports Backups Restores Analytics Notifications and Versions)]

  Staff -->|Retirement intake data| P31
  P31 <--> D32
  P31 -->|New cases for validation| P32

  Staff -->|Application forms and attachments| P32
  P32 <--> D32
  P32 <--> D35
  P32 -->|Validated cases| P33
  P32 -->|Clean case package| P34

  Staff -->|Task actions comments escalations| P33
  Supervisors -->|Assignments approvals escalations| P33
  P33 <--> D33
  P33 -->|Queue outcomes| P36
  P33 -->|Operational follow-up| P35

  Staff -->|Registry creation and updates| P34
  P34 <--> D34
  P34 -->|File control actions| P35
  P34 -->|Authoritative records for payroll| P37
  P34 -->|Approved record views| P39

  Staff -->|Document uploads and movement updates| P35
  Pensioners -->|Life certificate and approved profile inputs| P35
  P35 <--> D35
  P35 -->|Evidence for assessment| P36
  P35 -->|Updated obligations and document state| P39
  P35 -->|Delete or recovery events| P310

  Supervisors -->|Assessment and approval decisions| P36
  P36 <--> D32
  P36 <--> D33
  P36 -->|Approved pension case| P34
  P36 -->|Approved payment state| P37

  Payroll -->|Payroll and suspension files| P37
  Staff -->|Matching and review actions| P37
  P37 <--> D36
  P37 -->|Arrears exceptions and retained items| P38
  P37 -->|Payroll visibility| P39
  P37 -->|Operational summaries| P310

  Staff -->|Arrears ledger and accountability actions| P38
  P38 <--> D36
  P38 -->|Payment status and accountability results| P39
  P38 -->|Financial audit outputs| P310

  Pensioners -->|Dashboard requests contact updates lookup consent and death reports| P39
  P39 <--> D34
  P39 <--> D36
  P39 <--> D31
  P39 -->|Feedback signals lifecycle alerts and service visibility| P310

  Staff -->|Delete requests messages live-chat and broadcast actions| P310
  Admin -->|Recovery purge export backup restore notification and governance actions| P310
  P310 <--> D35
  P310 <--> D37
  P310 <--> D31
  P310 -->|Governed restoration or purge outcomes| P34
  P310 -->|Audit and management reports| Supervisors
  P310 -->|System oversight outputs| Admin

  class Staff,Supervisors,Pensioners,Payroll,Admin external;
  class P31,P32,P33,P34,P35,P36,P37,P38,P39,P310 process;
  class D31,D32,D33,D34,D35,D36,D37 store;
```
