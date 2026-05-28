# Content & Engagement ERD

Generated from `database/schema.sql` on 2026-05-28.

Podcast publishing, views, broadcasts, and notification-facing engagement records.

- Tables: 6
- Relationships shown: 4

## Tables Covered

- `tb_podcast_videos`
- `tb_podcast_views`
- `tb_broadcast_messages`
- `tb_user_broadcast_status`
- `tb_notification_queue`
- `tb_messages`

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
  tb_podcast_videos {
    int podcast_id PK
    string title
    text description
    string audience
    string youtube_url
    string youtube_id
    text tags
    bool is_featured
    bool is_published
    int sort_order
    string created_by
    string updated_by
    timestamp created_at
    timestamp updated_at
  }
  tb_podcast_views {
    int view_id PK
    int podcast_id FK
    string viewer_id FK
    string viewer_role
    string session_id
    timestamp viewed_at
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
  tb_podcast_videos ||--o{ tb_podcast_views : podcast_id
```
