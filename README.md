# UPS PensionsGo

UPS PensionsGo is a pension workflow, registry, payroll, claims, messaging, live-chat, reporting, and pensioner self-service platform built for Uganda Prisons Service pension administration.

The application is a PHP/MySQL web app with a JavaScript frontend, role-based access control, audit/session governance, document handling, notification controls, import/export tooling, backup/restore support, and Progressive Web App support.

## Quick References

- Main app URL on XAMPP: `http://localhost/PROJECTS/PensionApp/frontend/login.html`
- Public landing page: `http://localhost/PROJECTS/PensionApp/frontend/index.html`
- Root redirect: `http://localhost/PROJECTS/PensionApp/`
- System documentation: `docs/PensionApp_System_Documentation.md`
- Innovator showcase description: `docs/innovator-showcase-system-description.md`
- User/admin manual: `docs/PensionApp_User_Admin_Manual.md`
- ERD reference: `docs/erd.md`
- DFD pack: `docs/DFDs/`
- Documentation build notes: `docs/README.md`
- Version manifest: `app_version.json`

## Technology Stack

- Backend: PHP with MySQL/MariaDB
- Frontend: HTML, CSS, JavaScript modules
- Database: MySQL/MariaDB schema in `database/schema.sql`
- Demo/full dump: `database/pension_db.sql`
- PWA assets: `frontend/manifest.webmanifest`, `frontend/service-worker.js`, `frontend/offline.html`
- Local development target: XAMPP on Windows

## Local Setup

1. Install and start XAMPP.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Place or clone the project at:

```text
C:\xampp\htdocs\PROJECTS\PensionApp
```

4. Create a local database named:

```sql
pension_db
```

5. Import one of the database files:

- Recommended for a full demo with sample data: `database/pension_db.sql`
- Recommended for schema-first setup: `database/schema.sql`, then `database/seed.sql`

6. Confirm local database overrides in `backend/config.local.php`:

```php
define('PENSIONAPP_DB_HOST', 'localhost');
define('PENSIONAPP_DB_USER', 'root');
define('PENSIONAPP_DB_PASSWORD', '');
define('PENSIONAPP_DB_NAME', 'pension_db');
```

7. Open the login page:

```text
http://localhost/PROJECTS/PensionApp/frontend/login.html
```

## Required Local Folders

The app writes uploads, logs, exports, backups, and temporary artifacts during normal use. Ensure these folders are writable by the web server:

- `backend/uploads/`
- `backend/backups/`
- `backend/logs/`
- `logs/`
- `temp/`

Some folders are created automatically, but creating them manually can make first-run setup smoother on Windows.

## Sample User Accounts

The full demo dump `database/pension_db.sql` includes the following sample users. Login accepts either email address or phone number, plus the account password.

Important: staff and administrator passwords in the SQL dump are stored only as bcrypt hashes. The plaintext passwords cannot be recovered from the repository. Pensioner accounts are different: auto-provisioned pensioner users use the code-defined default password `Pensioner123` unless later changed by an administrator.

New staff/user accounts created from the user-registration screens prefill the password field with `Prisons123`. Administrators can replace that value before submitting the account form.

| Role | Name | Email / Login ID | Phone | Default Password |
|---|---|---|---|---|
| Super Administrator | Demo Super Administrator | `etopat2@gmail.com` | `+256791170164` | `SuperAdmin123` |
| Administrator | Patrick Etomet | `etomet2patrick@gmail.com` | `+256773959039` | Set during account creation; reset locally if unknown |
| General User | Among Jacenta | `jacentamong@gmail.com` | `+256777900981` | Set during account creation; reset locally if unknown |
| OC/Pension | Ben Nyanzi | `nyanziben@gmail.com` | `+256772963518` | Set during account creation; reset locally if unknown |
| Deputy OC/Pension | William Patrick Awany | `awanypwilliam@gmail.com` | `+256782368014` | Set during account creation; reset locally if unknown |
| Clerk | Julius Onyango | `onyangojulius144@gmail.com` | `+256776133144` | Set during account creation; reset locally if unknown |
| Write-up Officer | Eron Asaba | `asabaeron50@gmail.com` | `+256785988966` | Set during account creation; reset locally if unknown |
| Write-up Officer | Julian Nakasinde | `juliannaka12@gmail.com` | `+256784553402` | Set during account creation; reset locally if unknown |
| File Creator | Mastula Ankunda | `mastulan440@gmail.com` | `+256771234567` | Set during account creation; reset locally if unknown |
| Data Entrant | Elvis Opondo | `lvcfreeman@gmail.com` | `+256788664893` | Set during account creation; reset locally if unknown |
| Data Entrant | George Niwagaba | `niwagabageo@gmail.com` | `+256784456639` | Set during account creation; reset locally if unknown |
| Assessor | Sarah Namwenjje | `sarahnamwenjje90@gmail.com` | `+256780477666` | Set during account creation; reset locally if unknown |
| Auditor | Audito | `auditor@gmail.com` | `+256712345678` | Set during account creation; reset locally if unknown |
| Approver | Stephen Baker Ojom | `ojomstebak@gmail.com` | `+256782446576` | Set during account creation; reset locally if unknown |
| Pensioner | Kayenga Godfrey | `pensioner.10299@pensionsgo.com` | `+256414502013` | `Pensioner123` |
| Pensioner | Kisambira Rebecca | `pensioner.601@pensionsgo.local` | `+256772506598` | `Pensioner123` |
| Pensioner | Waneroba Christopher | `pensioner.4887@pensionsgo.local` | `+256783446003` | `Pensioner123` |
| Pensioner | Gadaire Yekosofati | `pensioner.42@pensionsgo.local` | `+256782282291` | `Pensioner123` |

## Reset Demo Passwords Locally

For local demos, you may reset selected accounts to a known password. Do this only in a development or demonstration database.

Example: reset one account to `Password123`:

```powershell
C:\xampp\php\php.exe -r "echo password_hash('Password123', PASSWORD_DEFAULT);"
```

Copy the generated hash, then run:

```sql
UPDATE tb_users
SET userPassword = '<PASTE_GENERATED_HASH_HERE>', password_updated_at = NOW()
WHERE userEmail = 'etomet2patrick@gmail.com';
```

Example: reset all non-pensioner demo accounts to `Password123`:

```powershell
C:\xampp\php\php.exe -r "echo password_hash('Password123', PASSWORD_DEFAULT);"
```

```sql
UPDATE tb_users
SET userPassword = '<PASTE_GENERATED_HASH_HERE>', password_updated_at = NOW()
WHERE LOWER(userRole) <> 'pensioner';
```

Pensioner passwords can also be reset from the Admin Dashboard using the pensioner password management tool. The default reset value is:

```text
Pensioner123
```

## Password Rules

The seeded settings currently require:

- Minimum length: `8`
- Uppercase letter: required
- Lowercase letter: required
- Number: required
- Special character: not required by default
- Password expiry: disabled by default (`0` days)

These settings are stored in `tb_app_settings` and can be changed from the Admin settings area or directly in the database for development.

## Role Notes

Core role keys include:

- `admin`
- `oc_pen`
- `dep_oc`
- `clerk`
- `writeup_officer`
- `file_creator`
- `data_entry`
- `assessor`
- `auditor`
- `approver`
- `user`
- `pensioner`

Role behavior is governed by:

- `tb_roles`
- `tb_role_permissions`
- `tb_user_permissions`
- role and permission helpers in `backend/config.php`

## PWA Notes

The app includes PWA support:

- Manifest: `frontend/manifest.webmanifest`
- Service worker: `frontend/service-worker.js`
- Offline page: `frontend/offline.html`
- App icons: `frontend/assets/pwa/`

For reliable installation testing, use localhost or a stable HTTPS host. Temporary tunnels such as free ngrok URLs may inject browser-warning pages that interfere with manifest or service-worker requests.

## Useful Maintenance Commands

Rebuild the documentation suite:

```powershell
python docs/build_documentation_suite.py
```

Check JavaScript syntax:

```powershell
node --check frontend/js/main.js
node --check frontend/js/modules/pwa.js
node --check frontend/service-worker.js
```

Validate the manifest JSON:

```powershell
node -e "JSON.parse(require('fs').readFileSync('frontend/manifest.webmanifest','utf8')); console.log('manifest ok')"
```

## Troubleshooting

- If login fails, confirm the database was imported and the account exists in `tb_users`.
- If a staff/admin password is unknown, reset it locally using the password reset instructions above.
- If pensioner login fails, confirm `pensioner_login_enabled` is enabled in `tb_app_settings`.
- If pages load without header/footer, hard-refresh the browser and confirm `frontend/header1.html`, `frontend/header2.html`, `frontend/footer.html`, and `frontend/footer1.html` are served by Apache.
- If PWA install does not appear, check DevTools Application > Manifest and Service Workers. Browser install UI is controlled by the browser and requires a valid manifest, service worker, secure context, and no installability errors.
