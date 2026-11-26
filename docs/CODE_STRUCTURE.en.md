# OMPS Code Structure

[中文版](CODE_STRUCTURE.md)

For: developers who maintain or extend OMPS. Installation: [INSTALL.en.md](INSTALL.en.md); coding rules: [CODING_RULES.md](CODING_RULES.md); database: [DATABASE.en.md](DATABASE.en.md).

## 1. Technology

| Part | Approach |
|---|---|
| Runtime | The unit's existing XAMPP 7.2.3: PHP 7.2.3 (32-bit) + MariaDB 10.1.31 + Apache 2.4. The same code is tested on PHP 8.2 / 8.4 |
| Back end | Plain PHP, no Composer, no third-party framework. The kernel (routing, middleware, query builder, templates, Excel / Word reading and writing) is written in-house |
| Front end | Plain JavaScript (ES5 style, no build step) and one CSS file; no front-end libraries |
| Database | Syntax shared by MariaDB 10.1 and MySQL 8; no JSON columns, CTEs or window functions |
| Dependencies | Only PHP's bundled extensions (pdo_mysql, openssl, mbstring, zip, gd...; all on by default in XAMPP) |

## 2. Folders

```
OMPS/
├─ omps                  command-line entry (php omps <command>)
├─ app/
│  ├─ bootstrap.php      autoloader, .env, error handling, time zone
│  ├─ Core/              kernel: app, router, request / response, views, languages, encryption, Excel / Word
│  ├─ Database/          connection, query builder, migration runner, base data
│  ├─ Middleware/        security headers, session, language, sign-in, CSRF, permissions
│  ├─ Engines/           shared services: permissions, audit, notifications, files, backup, clean-up, custom fields, documents
│  ├─ Helpers/           global functions __(), e(), url(), now()...
│  ├─ Console/           commands, compatibility check, language check, environment check, test runner
│  └─ Modules/           business modules (one folder each, see section 6)
├─ config/               app.php, database.php, security.php (all read from .env)
├─ database/
│  ├─ migrations/        schema scripts, run in file-name order (never edit a released one)
│  └─ seeds/base.php     base data: languages, roles, permissions, menus, dictionaries (no accounts or passwords)
├─ public/               the only folder served by Apache (document root / alias)
│  ├─ index.php          the only web entry point
│  ├─ .htaccess          rewrite rules, upload size
│  └─ assets/            css/omps.css, js/omps.js, js/icons.js, js/pages/*.js, img/
├─ resources/
│  ├─ languages/         language packs zh-CN/*.json, en-US/*.json (one file per module)
│  └─ views/             page frame layouts/app.php, auth.php, error page
├─ storage/              run-time data, never reachable by URL
│  ├─ logs/  cache/  temp/  uploads/
│  ├─ files/             uploaded files (FILES_PATH in .env may move it)
│  └─ backups/           backups (BACKUP_PATH may move it)
├─ collector/            device collector client (PowerShell script + install / uninstall .cmd, downloaded from the page)
├─ tests/                automated tests (php omps test)
├─ scripts/              helper scripts
└─ docs/                 documentation
```

## 3. How a request is handled

```mermaid
sequenceDiagram
  participant B as Browser
  participant I as public/index.php
  participant A as Core\App
  participant M as Middleware
  participant C as Controller
  participant S as Service
  participant D as Database
  B->>I: GET /device/repair or /api/v1/...
  I->>A: handleHttp()
  A->>A: load every module's routes.php, Router finds the route
  A->>M: SecurityHeaders → Session → Locale → Authenticate → Csrf → PermissionCheck
  M->>C: controller method when every check passes
  C->>S: business logic lives in services (permissions and data scope checked again)
  S->>D: query builder / bound parameters
  S-->>C: arrays
  C-->>B: pages: renderPage() sends the frame + page script; API: JSON
```

- **Pages**:
  - The controller calls `renderPage()`, which outputs the frame (`resources/views/layouts/app.php`).
  - The frame carries the BOOT data (current user, permission codes, language pack, CSRF token) as JSON, then loads `assets/js/pages/<page>.js`.
  - The page script builds the content by calling the API.
- **API** (`/api/v1/...`):
  - On success it returns `{"ok": true, "data": ...}`.
  - On error it returns `{"ok": false, "error": {"code", "message", "fields"}}`, where `fields` holds per-field form errors.
- **Route options** (`app/Core/Router.php`):
  - `auth`: sign-in required (default).
  - `perm`: the permission code required.
  - `csrf`: checked by default on state-changing requests.
- **Without mod_rewrite**: URLs fall back to `index.php?r=/path` (`APP_REWRITE=auto`).

## 4. Kernel `app/Core`

| File | Purpose |
|---|---|
| `App.php` | Application: loads module routes, runs the middleware, turns exceptions into responses |
| `Router.php` | Route table, `{id}` parameters, route options, groups |
| `Request.php` / `Response.php` | Request (input, uploads, IP) and response (HTML, JSON, file download) |
| `Controller.php` | Base controller: `renderPage()`, `pageArgs()` (paging, 30 a page by default), `filterArgs()` (list filters) |
| `View.php` | PHP templates; all output goes through `e()` |
| `Auth.php` / `Cookie.php` | Sign-in, password check, session cookie |
| `Lang.php` | Translations: language files + overrides from the database; a missing key shows `⟦key⟧` and is recorded |
| `Settings.php` | System settings (`system_settings` table) |
| `Crypto.php` | AES-256-GCM + HMAC (ID numbers, family phone and address); the key is `APP_KEY` in `.env` |
| `Validator.php` / `ValidationException.php` / `HttpException.php` | Input validation and uniform errors |
| `Xlsx.php` / `Docx.php` / `DocxBuilder.php` / `DocxFill.php` | In-house Excel read / write and Word generation / template filling (PHP's bundled zip) |
| `Env.php` / `Config.php` / `Url.php` / `Logger.php` / `ErrorHandler.php` / `Modules.php` | .env, configuration, URLs (sub-path and no-rewrite), logging, error handling, module discovery |

## 5. Database layer and shared engines

**`app/Database`**

| File | Purpose |
|---|---|
| `DB.php` | PDO connection (fixed charset and strict sql_mode), `select / value / statement / transaction` |
| `QueryBuilder.php` | Query builder: `DB::table('t a')->join()->where()->whereFilters()->paginate()`; refuses UPDATE / DELETE without WHERE |
| `Migrator.php` | Runs `database/migrations/`, backs up before upgrading |
| `Seeder.php` | Syncs base data and every module's permissions and menus |

**`app/Engines`** (used by several modules)

| File | Purpose |
|---|---|
| `Permission.php` | Permission codes, data scope (self / dept / unit / all), scope conditions for queries |
| `Audit.php` | Operation log `op()`, audit log `log()`, refused-access records |
| `Notify.php` | In-app notifications (type + parameters, de-duplicated); finds device admins, unit heads and other recipients |
| `Files.php` | Upload checks, storage outside the web root, one copy of identical files, recycle bin, download rights |
| `Backup.php` | Backups (database zip + folder), restore, daily automatic backup, removal of old backups |
| `Retention.php` | Automatic clean-up: deletes old logs and audit logs after their periods (business data is never deleted) |
| `CustomFields.php` | Reading, writing, searching and exporting custom fields |
| `Documents.php` / `DocLayouts.php` | Word documents for reports and personnel forms (built-in layouts or uploaded templates) |
| `WorkCalendar.php` | Work days and hours (reminders are sent in work hours only) |

## 6. Business modules `app/Modules`

Every module folder has the same layout:

```
app/Modules/Device/
├─ module.json        code, version, load order, modules it depends on
├─ routes.php         page and API routes (with perm codes)
├─ permissions.php    the module's permission codes (synced to the permissions table)
├─ menus.php          side-menu items (synced to the menus table)
├─ Controllers/       controllers: read the input, call a service
├─ Services/          business logic, permission and scope checks, database work
└─ views/             page frame fragments (usually one <div id="view">)
```

| Module | Covers | Main services | Page scripts |
|---|---|---|---|
| System | Workbench, users, roles, menus, languages, logs, backup and clean-up, settings | `UserService`, `RoleService`, `MenuService`, `LanguageService`, `I18nService`, `BackupService`, `WorkbenchService` | `home.js`, `users.js`, `roles.js`, `menus.js`, `i18n.js`, `logs.js`, `backup.js`, `settings.js` |
| Organization | Departments, staff list, transfers, profile changes to review | `DeptService`, `StaffService` | `depts.js`, `staff.js` |
| Personnel | Personnel record, own profile, family, import / export | `PersonnelService`, `ChangeRequests`, `FamilyService`, `ImportExport`, `Fields` | `person.js`, `profile.js`, `family.js` |
| Plan | Plan tree, my tasks, time labels | `PlanService`, `PlanLabel` | `plans.js`, `plan.js` |
| Performance | Writing results, review, completion roll-up, execution statistics | `PerformanceService`, `PerfStatsService` | `perf.js`, `review.js`, `perfstats.js` |
| Report | Comprehensive reports | `ReportService` | `reports.js` |
| Affairs | To-dos (and handled items), issued items, notebook, notifications, reminder rules and the scheduler | `TodoService`, `IssueService`, `NoteService`, `NotificationService`, `RuleService`, `Scheduler`, `ReminderCalc` | `todo.js`, `issues.js`, `notes.js`, `notify.js`, `rules.js` |
| Device | Device records, usage and monitoring, faults and repairs, collector API | `DeviceService`, `DeviceImport`, `CollectorService`, `RepairService` | `devices.js`, `device.js` (shared), `monitor.js`, `repair.js` |
| Custom | Custom fields, theme and logo, Word templates | `FieldService`, `ThemeService`, `DocTemplateService` | `custom.js` |
| Stats | Personnel and department statistics, custom statistics pages | `StatService`, `StatEngine` | `stats.js`, `charts.js` |
| Files | File manager, recycle bin | `FileManagerService` | `files.js` |

## 7. Front end

- **Frame**: `resources/views/layouts/app.php` renders the side menu, the top bar and the BOOT data. BOOT holds the current user, permission codes, the whole language pack and the CSRF token.
- **`public/assets/js/omps.js`**: shared helpers on `window.OMPS` (written `O` in pages).

  | Helper | Purpose |
  |---|---|
  | `O.api(method, path, body, query)` | Calls the API with the CSRF header; requests with `page` get the chosen page size |
  | `O.t(key, params)` | Translate |
  | `O.esc()` | HTML escaping (required whenever HTML is built) |
  | `O.table()` / `O.pager()` | Tables (icon buttons per row) and the pager |
  | `O.filters()` / `O.filterQuery()` | The list search box and Filter panel (period, search field, the page's own drop-downs) |
  | `O.form()` / `O.submit()` | Forms, submitting with per-field errors |
  | `O.modal()` / `O.confirm()` / `O.drawer()` / `O.dropdown()` | Dialogs, confirmations, side drawer, drop-down menus |
  | `O.upload()` / `O.pickFiles()` | Uploads |
  | `O.dateInput()` | The built-in date picker (no browser-language text) |
  | `O.go()` / `O.qs()` / `O.focusBar()` / `O.flash()` | Navigation, URL parameters, highlighting the target after a jump |
  | `O.has(perm)` | Whether the user has a permission (display only; the server checks again) |

- **`assets/js/pages/*.js`**: one file per page, each wrapped in `(function () { ... })()`, using shared helpers only through `O`.
- **`assets/js/icons.js`**: the 16 px line icon set (`O.icon(name)`).
- **`assets/css/omps.css`**: colours and sizes are CSS variables; dark mode and theme colours only swap variables.
- **Caching**: asset URLs carry `?v=<file time>`, so a normal reload picks up new files after an update.
- **Security**: the Content-Security-Policy forbids inline scripts; page data goes in `<script type="application/json">`.

## 8. Languages

- Every program text is a key in `resources/languages/<language>/<module>.json`, e.g. `device.fault.reported_at`. PHP uses `__('key', params)` and JS uses `t('key', params)`.
- All languages must have exactly the same keys, and the UI must have no hard-coded Chinese. `php omps i18n:check` checks both.
- Texts changed by the super admin in Language management are stored in `translations` (namespace `ui`) and override the files.
- Notifications and logs store codes and parameters and are shown in the viewer's language.

## 9. Permissions and data scope

- **Roles**: four built-in roles (scope in brackets), plus custom roles.
  - Super admin (all).
  - Unit head (unit).
  - Department head (dept).
  - Member (self).
- **Device administrator**: a flag on the account. It grants every device permission.
- **Two checks**:
  - The route's `perm` is checked by the `PermissionCheck` middleware.
  - Services check the data scope again, with `Permission::scopePersonnel()`, `DeviceService::scopeQuery()` and similar.
- **Refused access**: a record the user may not see is answered as missing (404), and the refusal is logged.

## 10. Commands and scheduled tasks

`php omps <command>` (`app/Console/Console.php`):

| Command | Purpose |
|---|---|
| `migrate [--seed]` / `migrate:status` / `seed` | Create / upgrade the schema (backs up first), sync base data |
| `admin:create` / `key:generate` | Create a super admin (password typed at the prompt), create APP_KEY |
| `schedule:run` | Every 5 minutes: reminders, the daily automatic backup, the daily clean-up (when no scheduled task exists, open pages trigger it too) |
| `cleanup` | Daily: temporary files, recycle bin after 30 days, expired logs |
| `backup:create` / `backup:restore <file>` | Back up now, restore |
| `env:check` / `compat:check` / `i18n:check` / `test` | Environment check, PHP compatibility check, language check, automated tests |

## 11. Tests and checks

- **Runner**: `tests/*Test.php` run with `app/Console/TestRunner.php`. They use the test database named in `.env.testing`, which is rebuilt on every run.
- **How tests work**: most tests call the API through the full middleware stack, so permissions and data scope are tested as well.
- **Before every commit**, run all three on PHP 7.2.3, 8.2 and 8.4:
  - `php omps compat:check`
  - `php omps i18n:check`
  - `php omps test`

## 12. Device collector `collector/`

- **Files**: `omps-collector.ps1` (a plain PowerShell script that runs on PowerShell 2.0 and later), `install.cmd`, `uninstall.cmd` and `readme.txt`.
- **Registration**: a one-time code created on the device page is exchanged for a device token.
- **Reporting**: the client reports at start-up and daily at 10:00, collecting only the items in the collection scope. The endpoints are `/api/v1/collector/register` and `/report`.
- **Updating**: copy the new `omps-collector.ps1` over the old one. No new registration is needed.

## 13. Adding a feature

1. **Database**: for new tables or columns, add a script in `database/migrations/` (next date in the file name) and update [DATABASE.en.md](DATABASE.en.md).
2. **Permissions and menus**: put permission codes in the module's `permissions.php` and menus in `menus.php`, then run `php omps migrate --seed` to sync them.
3. **Service and API**:
   - Business logic goes in `Services/`, which also checks permissions and data scope.
   - Controllers only pass input through.
   - Routes go in `routes.php`, each with a `perm`.
4. **Page**: add or change a script in `public/assets/js/pages/`. Lists use `O.table` + `O.filters` + `O.pager`.
5. **Texts**: add every UI text to both the zh-CN and en-US packs.
6. **Tests**: add tests in `tests/`, then run the checks and tests on all three PHP versions.
