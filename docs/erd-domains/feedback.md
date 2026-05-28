# Feedback ERD

Generated from `database/schema.sql` on 2026-05-28.

Feedback submissions and activity history.

- Tables: 2
- Relationships shown: 1

## Tables Covered

- `tb_feedback_submissions`
- `tb_feedback_activity`

## Mermaid ERD

```mermaid
erDiagram
  tb_feedback_activity {
    int activity_id PK
    int submission_id FK
    string action
    string actor_id FK
    string actor_name
    string actor_role
    string from_status
    string to_status
    text note
    text field_changes
    timestamp created_at
  }
  tb_feedback_submissions {
    int submission_id PK
    string reference_no
    string feedback_type
    string audience
    string full_name
    string email_address
    string phone_number
    string subject
    text message
    string page_context
    string submitted_by_user_id FK
    string submitted_by_role
    string status
    timestamp submitted_at
    timestamp updated_at
    string priority
    string assigned_to_user_id FK
    string assigned_to_name
    string assigned_to_role
    timestamp assigned_at
    timestamp reviewed_at
    string reviewed_by_user_id FK
    string reviewed_by_name
    string reviewed_by_role
    timestamp resolved_at
    string resolved_by_user_id FK
    string resolved_by_name
    string resolved_by_role
    timestamp closed_at
    string closed_by_user_id FK
    string closed_by_name
    string closed_by_role
    text resolution_summary
  }
  tb_feedback_submissions ||--o{ tb_feedback_activity : submission_id
```
