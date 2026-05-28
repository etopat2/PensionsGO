# Claims ERD

Generated from `database/schema.sql` on 2026-05-28.

Claim submissions and claim-status tracking.

- Tables: 2
- Relationships shown: 0

## Tables Covered

- `tb_appnsubmissions`
- `tb_claimstatus`

## Mermaid ERD

```mermaid
erDiagram
  tb_appnsubmissions {
    int id PK
    string regNo FK
    string title
    string sName
    string fName
    string appnType
    string contact
    text address
    date retirementDate
    string retirementType
    date submissionDate
    text comment
  }
  tb_claimstatus {
    int id PK
    string regNo
    string computerNo
    string supplierNo
    string appnType
    date verificationDate
    string appnStatus
    text comment
  }
```
