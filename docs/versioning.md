# App Versioning

This project now uses a single app-version manifest at `app_version.json`.

Current repository snapshot:

- `name`: `UPS PensionsGo`
- `version`: `1.0.0`
- `display_version`: `1.0.0`
- `channel`: `stable`
- `build`: `20260401.1`
- `release_date`: `2026-04-01`
- `schema_version`: `5.2.2`

## Source Of Truth

The canonical version metadata lives in:

- `app_version.json`

Current fields:

- `name`: product name shown in version-aware surfaces
- `version`: semantic app version, for example `1.0.0`
- `channel`: release channel such as `stable`, `rc`, or `dev`
- `build`: deployment/build identifier such as `20260401.1`
- `release_date`: release date in `YYYY-MM-DD`
- `schema_version`: database schema baseline for the release

## Runtime Flow

Backend version helper:

- `backend/versioning.php`

This helper:

- loads and sanitizes `app_version.json`
- allows an optional deployment override through `PENSIONAPP_APP_VERSION`
- computes a frontend build fingerprint from the latest frontend asset modification time
- produces a cache-safe version string used by the service worker

Version endpoints:

- `backend/api/get_app_version.php`
  - JSON for frontend modules and diagnostics
- `backend/api/pwa_version.php`
  - JavaScript globals for the service worker

## Frontend Consumers

- `frontend/service-worker.js`
  - uses `PWA_CACHE_VERSION` as the cache namespace key
- `frontend/js/modules/pwa.js`
  - checks for version changes and refreshes caches when a new version is detected
- `frontend/js/modules/footer.js`
  - renders the visible version badge in the footer

## How To Bump A Release

Update `app_version.json` before deployment:

1. Set `version`
2. Set `build`
3. Set `release_date`
4. Update `schema_version` if the database baseline changed

Example:

```json
{
  "name": "UPS PensionsGo",
  "version": "1.1.0",
  "channel": "stable",
  "build": "20260415.1",
  "release_date": "2026-04-15",
  "schema_version": "5.2.2"
}
```

## Deployment Note

`backend/config.local.php` may define:

```php
define('PENSIONAPP_APP_VERSION', '1.1.0');
```

That override is intended only for deployment-specific needs. In normal use, keep the deployment override aligned with `app_version.json`.

## Why This Setup

This gives us:

- one version source for the app
- predictable PWA cache invalidation
- a visible footer version for support and QA
- a clean path for future release automation
- a documented place to keep the release schema baseline aligned with `database/schema.sql`
