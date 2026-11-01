# EMS Project Knowledge

This document summarizes the current EMS codebase in `C:\xampp\htdocs`. The project is a plain PHP/XAMPP school management system with role-based dashboards, admin CRUD pages, AJAX handlers, PDF export, uploaded photos, and a MySQL schema.

## Folder Structure

```text
/
  .htaccess
  index.php
  logout.php
  favicon.ico
  PROJECT_KNOWLEDGE.md

  includes/
    db.php
    functions.php
    admin-session.php
    student-session.php
    teacher-session.php
    ems_database.sql

  admin/
    dashboard.php
    activity-log.php
    admission.php
    students.php
    students-ajax.php
    students-pdf.php
    student-profile-pdf.php
    teachers.php
    teachers-ajax.php
    subjects.php
    subjects-ajax.php
    sessions.php
    sessions-ajax.php
    assign-teacher.php
    assign-teacher-ajax.php
    routine.php
    routine-ajax.php

    includes/
      admin-sidebar.php
      admin-footer.php

    assets/
      css/
        admin.css
        dashboard.css
      js/
        admin.js

  student/
    dashboard.php

  teacher/
    dashboard.php

  assets/
    images/
      ems-logo.jpg
      ems-school-logo.png
      admin-avatar.jpg
      student-avatar-m.jpg
      student-avatar-f.jpg
      teacher-avatar-m.jpg
      teacher-avatar-f.jpg
      favicon/*

  uploads/
    student/photos/
      STDNxyz001.jpg
    teacher/photos/       (used by code, may need to be created)

  extensions/
    composer.json
    vendor/
      matomo/device-detector
      mustangostang/spyc
    TCPDF/
```

### Entry Points

- `index.php` is the root login page for students, teachers, and admins.
- `logout.php` logs activity, clears session state, and redirects to login.
- `student/dashboard.php` and `teacher/dashboard.php` are simple role dashboards.
- `admin/*.php` pages are the main administrative screens.
- `admin/*-ajax.php` files are JSON endpoints used by matching admin pages.
- `admin/*-pdf.php` files generate downloadable PDFs through TCPDF.

## Database Usage

### Connection

- `includes/db.php` defines constants and exposes:
  - `db(): PDO`
  - `dbQuery(string $sql, array $params = []): PDOStatement`
- PDO uses:
  - `PDO::ERRMODE_EXCEPTION`
  - `PDO::FETCH_ASSOC`
  - native prepared statements via `PDO::ATTR_EMULATE_PREPARES => false`
- Code consistently uses prepared statements through `dbQuery()` for variable SQL values.

Important setup caveat:

- `includes/db.php` sets `DB_NAME` to `ems`.
- `includes/ems_database.sql` creates and uses database `ems_db`.
- A fresh install must either rename the database in `db.php` or change the SQL dump database name before import.

### Core Tables

Identity and people:

- `system_users`
  - Primary auth table.
  - Fields include `sys_uid`, `role`, `username`, `password_hash`, `is_active`, `last_login`.
  - `role` is `student`, `teacher`, or `admin`.
  - Unique login identity is `username + role`.
- `students`
  - One-to-one with `system_users` by `sys_uid`.
  - Stores student ID, profile data, family/contact data, gender, SSC batch, photo path.
- `teachers`
  - One-to-one with `system_users` by `sys_uid`.
  - Stores profile, designation, contact data, joining date, photo path.

Academic structure:

- `sessions`
  - Academic years; one can be marked `is_current`.
- `classes`
  - Class 6 through 10 in seed data.
- `groups`
  - Science, Arts, Commerce; applies from class 9.
- `subjects`
  - Linked to class and optional group.
- `enrollments`
  - Links student, class, session, optional group, status, roll, scholarship.

Exams and results:

- `exams`
- `exam_subjects`
- `student_marks`
- `exam_results_summary`

Seat plan:

- `exam_rooms`
- `seat_allocations`

Attendance and routine:

- `attendance`
- `class_routine`
  - Used for real timetable slots.
  - Also reused by teacher assignment features with special `day_of_week = 0` and `period_number = 0` rows.

Payments:

- `payment_dues`
- `payment_records`

Logs:

- `login_logs`
- `activity_logs`

### Seed Data

`includes/ems_database.sql` seeds:

- Classes 6 through 10.
- Groups: Science, Arts, Commerce.
- Session 2026 as current.
- Demo admin, teacher, and student users.
- Default demo password is documented as `Password@123`.

### Common Query Patterns

- Admin pages often define local wrappers:
  - `safeRows($sql, $params)` to return an empty array on DB failure.
  - `safeVal()` or `sv()` to return a scalar or `null`.
  - `safeCount()` in dashboard-style pages.
- Complex filters build a `$where` array and `$params` array, then join conditions into a prepared SQL statement.
- Mutations call `log_activity()` after successful insert/update/delete actions.
- Multi-table inserts that must stay consistent use transactions, notably student admission.

## Security Architecture

### Sessions

Session setup lives in `includes/db.php` before `session_start()`:

- `session.cookie_httponly = 1`
- `session.cookie_samesite = Strict`
- `session.use_strict_mode = 1`
- `session.cookie_secure = 1`
- `session.gc_maxlifetime = 7200`

Local development warning:

- `session.cookie_secure = 1` means cookies require HTTPS. On plain `http://localhost`, login/session behavior may fail unless HTTPS is used or the setting is adjusted for local development.

### Role Guards

Role guards are required at the top of protected pages:

- `includes/admin-session.php`
- `includes/student-session.php`
- `includes/teacher-session.php`

They verify:

- `$_SESSION['ems_logged_in']`
- `$_SESSION['ems_role']`
- `$_SESSION['ems_uid']`
- active account status in `system_users`
- matching role in the database

They also refresh display session fields from the related `students` or `teachers` row and periodically call `session_regenerate_id(true)`.

### Authentication

`index.php` handles login:

- Accepts identifier, password, and selected role.
- Finds users by `username` or `sys_uid`.
- Students can also log in with `students.student_id`.
- Passwords use `password_hash()` and `password_verify()`.
- Successful login sets:
  - `ems_logged_in`
  - `ems_uid`
  - `ems_role`
  - `ems_login_time`
  - `ems_last_regen`
  - `ems_login_ip`
- Successful login updates `system_users.last_login`.
- Role-based redirect is handled by `redirect_by_role()`.

### CSRF

CSRF helpers live in `includes/functions.php`:

- `csrf_token()`
- `csrf_field()`
- `csrf_verify()`
- `csrf_rotate()`

Patterns:

- Login form uses `csrf_field()`.
- Admin AJAX pages use a hidden input with `id="csrfToken"`.
- Admin JS helpers append `csrf_token` to `FormData`.
- AJAX handlers rotate the token after verification and return the fresh token as `csrf`.
- Login AJAX returns the fresh token as `csrf_token`.

### Output Escaping

- `h()` wraps `htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8')`.
- PHP-rendered HTML generally uses `h()` for dynamic values.
- JavaScript-rendered HTML commonly defines local `esc()` or `escHtml()` functions.

### Rate Limiting

Login throttling is browser-cookie based in `includes/functions.php`:

- Cookie name: `ems_rl`
- Max attempts: 5
- Attempt window: 10 minutes
- Lockout: 15 minutes
- Cookie payload is HMAC-signed.

Important production note:

- `RL_SECRET` is currently a placeholder string. It should be replaced with a strong private random value before production use.

### Logging

- `log_login($sys_uid, $success, $ip)` writes to `login_logs`.
- `log_activity($actor_uid, $action_type, $target_type, $target_uid, $meta)` writes to `activity_logs`.
- `detect_device()` uses `matomo/device-detector` when installed.
- `get_ip_info()` calls `ip-api.com` for public IP geolocation, with local/private IPs marked as local/private.

### HTTP and Routing

`.htaccess`:

- Disables directory indexes with `Options -Indexes`.
- Sets `X-Robots-Tag: noindex, nofollow` when headers module is available.
- Defines custom error pages redirecting to `http://localhost`.
- Supports folder `index.php` routing.
- Supports extensionless `.php` URLs.
- Configures PHP 8.1 handler blocks for cPanel.

### File Uploads

Student and teacher photo uploads:

- Student admission uploads to `uploads/student/photos/`.
- Student edits also update `uploads/student/photos/`.
- Teacher uploads use `uploads/teacher/photos/`.
- MIME validation uses `getimagesize()` or MIME maps.
- Allowed image formats are JPG/JPEG, PNG, and WebP depending on endpoint.
- Common size limit is 3MB.
- Saved DB value is a relative path such as `uploads/student/photos/{filename}`.

Watchpoints:

- `uploads/teacher/photos/` is referenced but not present in the current file listing.
- Existing photos may be deleted with `@unlink()` when replaced.
- Uploaded directories need write permission under XAMPP/web server user.

## UI Patterns

### Admin Shell

Admin pages use a shared shell:

1. Require `includes/admin-session.php`.
2. Set page metadata:
   - `$pageTitle`
   - `$activeNav`
   - `$breadcrumb`
   - optional `$extraCss`
3. Require `admin/includes/admin-sidebar.php`.
4. Render page content.
5. Require `admin/includes/admin-footer.php`.

`admin-sidebar.php` outputs:

- HTML document start and `<head>`.
- favicon links.
- Font Awesome 7.
- universal admin stylesheet.
- fixed sidebar.
- topbar.
- breadcrumb area.
- current session label.
- admin user area.

`admin-footer.php` closes shell containers and loads `admin/assets/js/admin.js`.

### Admin CSS Design System

`admin/assets/css/admin.css` defines:

- CSS reset.
- Inter font.
- design tokens in `:root`.
- fixed/collapsible sidebar.
- responsive breakpoints.
- cards, buttons, grids, tables, forms, stats, modals, alerts, and toast styling.
- semantic colors: green, amber, red, sky, purple, teal, rose.

Typical classes:

- Layout: `.ems-shell`, `.ems-sidebar`, `.ems-main`, `.main-inner`
- Cards: `.card`, `.stat-card`, `.quick-card`
- Buttons: `.btn`, `.btn-primary`, `.btn-outline`, `.btn-danger`, `.btn-success`, `.btn-ghost`, `.btn-sm`
- Tables: `.ems-table`, `.table-wrap`
- Forms: `.form-control`, `.form-row`

### Admin JavaScript

`admin/assets/js/admin.js` provides:

- BST live clock.
- responsive sidebar toggle.
- mobile sidebar overlay.
- collapsible nav groups.
- `[data-confirm]` confirm handling.
- auto-dismiss alerts.
- stat count-up animation.
- fallback active nav highlighting.
- `window.showToast(type, title, message, duration)`.
- `window.openModal(opts)` confirm/modal utility.

Page-level JS usually:

- Defines `AJAX_URL` or `EMS_AJAX`.
- Defines `getToken()` and `setToken()`.
- Defines an `ajax(data)` helper that posts `FormData`.
- Updates the hidden CSRF token from `res.csrf`.
- Renders cards/tables client-side.
- Uses `showToast()` for feedback and `openModal()` for confirmations.

### Admin Page Patterns

List-management pages commonly support:

- filter panel
- grid/list view toggle
- cards on grid view
- table on list view
- profile/detail modal
- add/edit modal
- active/inactive toggle
- password reset modal where relevant
- AJAX-backed actions

Examples:

- `students.php` + `students-ajax.php`
- `teachers.php` + `teachers-ajax.php`
- `subjects.php` + `subjects-ajax.php`
- `sessions.php` + `sessions-ajax.php`
- `routine.php` + `routine-ajax.php`
- `assign-teacher.php` + `assign-teacher-ajax.php`

### Student and Teacher Dashboards

`student/dashboard.php` and `teacher/dashboard.php` are standalone pages:

- Use role-specific session guards.
- Load Inter and Font Awesome from CDNs.
- Use inline CSS.
- Show simple header, welcome banner, stat cards, and nav cards.
- Many feature nav links are placeholders (`href="#"`) at this stage.

### Login UI

`index.php` is a self-contained login page:

- Inline CSS.
- Inter and Font Awesome 6.5.
- Role selector.
- AJAX login with loading state.
- Alert area.
- Lockout countdown behavior.
- Hidden CSRF field updated after each AJAX response.

## Reusable Functions

### `includes/db.php`

- `db(): PDO`
  - Lazy singleton PDO connection.
- `dbQuery(string $sql, array $params = []): PDOStatement`
  - Prepares, executes, and returns statement.

### `includes/functions.php`

CSRF:

- `csrf_token(): string`
- `csrf_field(): string`
- `csrf_verify(): bool`
- `csrf_rotate(): void`

Security/utilities:

- `h(string $str): string`
- `generate_uid(string $prefix = ''): string`
- `get_client_ip(): string`

Device/IP:

- `detect_device(): array`
- `get_ip_info(string $ip): array`

Login rate limiting:

- `_rl_make_cookie(int $attempts, int $lockout_until, int $window_start): string`
- `_rl_parse_cookie(): array`
- `_rl_set_cookie(int $attempts, int $lockout_until, int $window_start): void`
- `check_login_rate_limit(string $ip = ''): bool`
- `record_failed_attempt(): void`
- `get_lockout_seconds_remaining(): int`
- `clear_rate_limit_cookie(): void`

Logging:

- `log_login(string $sys_uid, bool $success, string $ip): void`
- `log_activity(string $actor_uid, string $action_type, string $target_type, string $target_uid, array $meta = []): void`

Flash and redirect:

- `flash_set(string $type, string $message): void`
- `flash_get(): array`
- `redirect(string $url): void`
- `redirect_by_role(string $role): void`

### Local Per-Page Helpers

Several admin pages define local helpers rather than sharing them globally:

- `safeRows()`
- `safeVal()`
- `safeCount()`
- `sv()`
- `timeAgo()`
- page-specific metadata helpers for activity logs, dashboards, and PDF formatting.

When adding new pages, follow nearby page patterns first. If duplication becomes painful across many pages, move a helper into `includes/functions.php`.

## AJAX Endpoint Map

All current admin AJAX endpoints:

```text
admin/assign-teacher-ajax.php
  fetch_teachers
  fetch_assignments
  save_assignments
  remove_assignment
  fetch_summary

admin/routine-ajax.php
  fetch_routine
  fetch_subjects
  fetch_teachers
  check_conflict
  save_slot
  delete_slot
  add_period_row
  delete_period_row
  fetch_routine_data
  fetch_subject_assignments

admin/sessions-ajax.php
  create
  set_current
  edit
  delete

admin/students-ajax.php
  fetch
  toggle
  reset_password
  get_student
  full_edit
  count_serial
  quick_edit

admin/subjects-ajax.php
  create
  edit
  toggle
  delete
  fetch

admin/teachers-ajax.php
  fetch
  get_teacher
  add
  edit
  toggle
  reset_password
  delete
  upload_photo
  full_edit_teacher
```

Endpoint conventions:

- Require `../includes/admin-session.php`.
- Send `Content-Type: application/json; charset=utf-8`.
- Only accept `POST`.
- Verify CSRF.
- Rotate CSRF after verification.
- Return `ok: true/false`.
- Return fresh token as `csrf`.
- Use local `ok()` and `fail()` functions.
- Log successful mutations through `log_activity()`.

## PDF Generation

TCPDF is vendored in `extensions/TCPDF`.

PDF files:

- `admin/students-pdf.php`
  - Exports filtered student lists.
- `admin/student-profile-pdf.php`
  - Exports one student profile.

Patterns:

- Require admin session.
- Require `extensions/TCPDF/tcpdf.php`.
- Query DB with filters.
- Create custom TCPDF subclasses for headers/footers.
- Output with `$pdf->Output(..., 'D')` for download.

## Dependencies

Composer manifest:

```json
{
  "require": {
    "matomo/device-detector": "^6.4"
  }
}
```

Installed Composer packages:

- `matomo/device-detector` 6.4.8
- `mustangostang/spyc` 0.6.3

Other vendored library:

- TCPDF under `extensions/TCPDF`.

Frontend dependencies are loaded by CDN:

- Google Fonts, primarily Inter.
- Font Awesome 6.5 on login/student/teacher pages.
- Font Awesome 7.0.1 in admin shell.

## Coding Conventions

### PHP Style

- Plain PHP, no framework.
- Procedural structure with page-level scripts.
- `require_once` for shared includes.
- Strict typing is not enabled globally, but many helper functions use parameter and return types.
- Variables use camelCase (`$pageTitle`, `$activeNav`, `$classId`) and snake-style session keys (`ems_logged_in`, `ems_uid`).
- Constants use uppercase snake case (`BASE_URL`, `EMS_NAME`, `RL_COOKIE_NAME`).
- Admin page metadata is set before including the admin sidebar.
- PHP files often contain HTML, CSS, and JS together.

### Database Style

- Prefer `dbQuery()` and placeholders over raw interpolated SQL.
- Use transactions when writing multiple related rows.
- Foreign keys use cascades for identity-owned records where appropriate.
- `sys_uid` is the project-wide user identifier.
- Admin action logs should include enough `meta_json` to explain what changed.

### Frontend Style

- Admin screens rely on shared CSS tokens and component classes.
- Page-specific CSS is usually inline after sidebar include, though `admin.css` supports `$extraCss`.
- JavaScript is mostly vanilla JS.
- Dynamic HTML is assembled with string templates.
- Page JS includes local escape helpers for HTML output.
- AJAX posts use `fetch()` and `FormData`.
- User feedback uses `showToast()` and `openModal()`.

### Naming Patterns

- Rendered admin page: `feature.php`
- Matching JSON endpoint: `feature-ajax.php`
- PDF export: `feature-pdf.php`
- Shared admin shell: `admin/includes/admin-sidebar.php` and `admin/includes/admin-footer.php`
- Uploaded image paths are saved relative to web root.

## Development Watchpoints

- Database name mismatch: `db.php` uses `ems`, SQL uses `ems_db`.
- HTTPS/session mismatch: `session.cookie_secure = 1` can break sessions on plain local HTTP.
- `RL_SECRET` is a placeholder and must be changed before production.
- `BASE_URL` is hardcoded to `http://localhost`.
- School metadata and logo URLs are hardcoded in `includes/db.php`.
- `session.cookie_secure` and rate-limit cookies are both set as secure cookies, so local HTTP behavior may differ from production HTTPS.
- `uploads/teacher/photos/` may need to be created.
- `extensions/vendor` and `extensions/TCPDF` are committed/vendor-included rather than installed at runtime.
- Some admin sidebar links point to pages not currently present in the file listing, such as exams, marks, attendance, payments, results, promote, reset-password, login-logs, and settings.
- Some comments contain mojibake in PowerShell output, suggesting source encoding should be handled carefully when editing.

