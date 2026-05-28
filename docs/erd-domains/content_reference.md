# Content & Reference ERD

Generated from `database/schema.sql` on 2026-05-28.

Titles, public guidance content, lookup/reference catalogs, and podcast metadata.

- Tables: 10
- Relationships shown: 1

## Tables Covered

- `tb_titles`
- `tb_faq_entries`
- `tb_terms_clauses`
- `tb_poldistricts`
- `tb_pridistricts`
- `tb_priregions`
- `tb_priunits`
- `tb_uganda_public_holidays`
- `tb_podcast_videos`
- `tb_podcast_views`

## Mermaid ERD

```mermaid
erDiagram
  tb_faq_entries {
    int faq_id PK
    string question
    text answer
    text bullets
    string category
    string audience_label
    bool is_featured
    int sort_order
    bool is_active
    timestamp created_at
    timestamp updated_at
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
  tb_poldistricts {
    int Id PK
    text polDistrict
    text polRegion
  }
  tb_pridistricts {
    int Id PK
    text priDistrict
    text priRegion
  }
  tb_priregions {
    int Id PK
    text priRegion
  }
  tb_priunits {
    int Id PK
    text priUnit
    text polDistrict
    text priDistrict
    text priRegion
    text polRegion
  }
  tb_terms_clauses {
    int clause_id PK
    string title
    text body
    string topics
    string section_key
    int sort_order
    bool is_active
    timestamp created_at
    timestamp updated_at
  }
  tb_titles {
    int title_id PK
    string title_name
    string category
    string level
    bool is_active
    timestamp created_at
  }
  tb_uganda_public_holidays {
    date holiday_date PK
    string holiday_name
    bool is_active
    timestamp created_at
  }
  tb_podcast_videos ||--o{ tb_podcast_views : podcast_id
```
