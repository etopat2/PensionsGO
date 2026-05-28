# Messaging ERD

Generated from `database/schema.sql` on 2026-05-28.

Messages, attachments, recipients, notifications, and broadcast delivery state.

- Tables: 8
- Relationships shown: 5

## Tables Covered

- `tb_messages`
- `tb_message_attachments`
- `tb_message_recipients`
- `tb_message_storage_snapshots`
- `tb_notification_queue`
- `tb_notification_digest_runs`
- `tb_broadcast_messages`
- `tb_user_broadcast_status`

## Mermaid ERD

```mermaid
erDiagram
  tb_broadcast_messages {
    int broadcast_id PK
    int message_id FK
    text target_roles
    bool is_active
    timestamp created_at
  }
  tb_messages {
    int message_id PK
    string sender_id FK
    string subject
    text message_text
    string message_type
    int parent_message_id FK
    bool is_urgent
    timestamp created_at
    timestamp updated_at
    bool is_deleted
    bool is_deleted_by_sender
    timestamp deleted_by_sender_at
  }
  tb_message_attachments {
    int attachment_id PK
    int message_id FK
    string file_name
    string file_path
    int file_size
    string mime_type
    timestamp uploaded_at
    string file_hash
    bool is_compressed
  }
  tb_message_recipients {
    int recipient_id PK
    int message_id FK
    string recipient_user_id FK
    bool is_read
    timestamp read_at
    bool is_deleted
    timestamp deleted_at
    timestamp created_at
  }
  tb_message_storage_snapshots {
    bigint snapshot_id PK
    date snapshot_date
    string snapshot_type
    string status
    string file_name
    string file_path
    bigint file_size_bytes
    int message_count
    int attachment_count
    bigint total_storage_bytes
    text notes
    string created_by
    string created_by_name
    string created_by_role
    datetime created_at
  }
  tb_notification_digest_runs {
    bigint digest_id PK
    date digest_date
    string run_type
    string recipient
    string subject
    string status
    text summary_json
    text notes
    string created_by
    string created_by_name
    string created_by_role
    datetime created_at
  }
  tb_notification_queue {
    int notification_id PK
    string channel
    string recipient
    string subject
    text message
    string status
    text meta
    timestamp created_at
    int attempts
    datetime processing_started_at
    datetime last_attempted_at
    datetime sent_at
    datetime failed_at
    text last_error
    string provider_reference
  }
  tb_user_broadcast_status {
    int status_id PK
    string user_id FK
    int broadcast_id FK
    bool is_seen
    timestamp seen_at
    timestamp created_at
    bool is_deleted
    timestamp deleted_at
  }
  tb_broadcast_messages ||--o{ tb_user_broadcast_status : broadcast_id
  tb_messages ||--o{ tb_broadcast_messages : message_id
  tb_messages ||--o{ tb_messages : parent_message_id
  tb_messages ||--o{ tb_message_attachments : message_id
  tb_messages ||--o{ tb_message_recipients : message_id
```
