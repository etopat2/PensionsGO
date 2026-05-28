# Users & Access ERD

Generated from `database/schema.sql` on 2026-05-28.

Users, roles, permissions, sessions, and app-level access settings.

- Tables: 9
- Relationships shown: 6

## Tables Covered

- `tb_users`
- `tb_roles`
- `tb_role_permissions`
- `tb_user_permissions`
- `tb_user_settings`
- `tb_user_sessions`
- `tb_session_settings`
- `tb_session_metrics`
- `tb_app_settings`

## Mermaid ERD

```mermaid
erDiagram
  tb_app_settings {
    string setting_key PK
    text setting_value
    timestamp updated_at
  }
  tb_roles {
    string role_key PK
    string role_label
    text role_description
    string clone_from_role FK
    bool is_active
    bool is_system
    timestamp created_at
    timestamp updated_at
  }
  tb_role_permissions {
    int role_permission_id PK
    string role_key FK
    string permission_key
    bool is_allowed
    text notes
    string updated_by
    timestamp created_at
    timestamp updated_at
  }
  tb_session_metrics {
    int metric_id PK
    timestamp metric_time
    int active_sessions
    int concurrent_conflicts
    int avg_session_duration
    int timeout_errors
    int network_errors
    int grace_period_uses
    int device_conflicts
    int successful_logins
    int failed_logins
  }
  tb_session_settings {
    string user_id PK, FK
    int max_concurrent_sessions
    int session_timeout
    bool allow_multiple_devices
    bool auto_logout_on_conflict
    int inactivity_warning_minutes
    int grace_period_minutes
    timestamp created_at
    timestamp updated_at
  }
  tb_users {
    int Id PK
    string userId
    string userTitle
    string userName
    string userRole
    string userEmail
    string phoneNo
    string userPassword
    string userPhoto
    timestamp timeStamp
    text other
    timestamp password_updated_at
  }
  tb_user_permissions {
    int permission_id PK
    string user_id FK
    string permission_key
    bool is_allowed
    text notes
    string granted_by
    timestamp created_at
    timestamp updated_at
  }
  tb_user_sessions {
    int id PK
    string session_id
    string user_id FK
    string device_id
    string session_type
    timestamp login_time
    timestamp last_activity
    timestamp grace_period_until
    bool is_active
    string termination_reason
    text user_agent
    string ip_address
  }
  tb_user_settings {
    string user_id PK, FK
    string setting_key PK
    text setting_value
    timestamp updated_at
  }
  tb_roles ||--o{ tb_roles : clone_from_role
  tb_roles ||--o{ tb_role_permissions : role_key
  tb_users ||--o{ tb_session_settings : user_id
  tb_users ||--o{ tb_user_permissions : user_id
  tb_users ||--o{ tb_user_sessions : user_id
  tb_users ||--o{ tb_user_settings : user_id
```
