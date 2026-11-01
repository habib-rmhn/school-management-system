# KKHS EMS publication review

[Back to the README](../README.md)

Review date: 2026-11-01. Scope: source-based documentation accuracy, local file inventory, and preparation for a manual GitHub Web upload. Application workflows were not executed, a live database was not accessed, and no independent security audit is claimed.

The application source, configuration, libraries, database files, and existing uploads were left unchanged. No dependency installation, database export, publication, or deployment was performed. Exclusions describe what must stay out of the public selection; they do not mean those files were removed locally.

## Files to exclude from the public upload

| File or location | Finding | Required action |
| --- | --- | --- |
| `includes/ems_database.sql`, `includes/ems_v2-1.sql` | Local database exports exist. Database files are outside the intended public distribution, regardless of whether their records are fictional. | Omit both, every other `.sql` file, and all copies/archives. Provide a corresponding file privately through the maintainer. |
| `admin/seat-plan-migration.sql` | Local schema migration exists and falls under the same no-SQL rule. | Omit it; retain the PHP seat-plan source. |
| `extensions/TCPDF/` | Local third-party PDF library and fonts exist. | Omit the entire library directory. Users install it separately. |
| `extensions/vendor/` | Generated Composer dependencies, including Device Detector and its dependencies, exist locally. | Omit the directory; preserve `extensions/composer.json`. |
| `extensions/PHPMailer/` or any other PHPMailer copy | No PHPMailer directory or active application calls were found in this review. | Omit any such library added later; the README explains optional manual integration. |
| `uploads/student/photos/`, `uploads/teacher/photos/` | Existing profile image files may identify real people. Their content has not been approved for publication. | Upload only the new `.gitkeep` files. Keep existing photographs private. |
| `uploads/school/` | Contains an uploaded school logo. Publication permission has not been established. | Upload its `.gitkeep`; approve branding separately before sharing an image. |
| `.tmp.driveupload/` | Local upload-tool temporary directory is present. | Omit it and any future contents. |
| `PROJECT_KNOWLEDGE.md:3,177,198–203,662` | Internal notes include local environment details, historical demo-password guidance, and stale module/configuration descriptions. | Keep this file locally and omit it from the public upload. Use the reviewed README instead. Do not reproduce historical account credentials in public docs. |
| Any `.env*`, `auth.json`, private keys, logs, backup archives, or database-derived CSV/JSON files | Such files can contain credentials or personal records; this is a continuing upload rule, not a claim that every listed type currently exists. | Inspect each public selection. Keep data exports private even if their extension is not covered by `.gitignore`. |

`.gitignore` excludes database formats/backups, third-party directories, uploads, logs, secrets, and editor/temp files. It preserves application PHP/JS/CSS, shared images, documentation, `extensions/composer.json`, and the three upload-folder markers. Documentation is not blanket-ignored: the internal notes above require explicit exclusion from the manual public selection. The ignore file is not a content scanner or a filter for browser uploads.

## Configuration and security findings

Line numbers below refer to the source reviewed on the date above. No actual private secret values are reproduced here.

| Location | Observation and significance | Follow-up |
| --- | --- | --- |
| `includes/db.php:7–11` | Database credentials are configured directly in PHP. The reviewed values are local development defaults, with no nonempty database password. | Keep this required source file, but publish only reviewed development values. Never upload a production-configured copy. Configure the real host privately. |
| `includes/db.php:15–25`, `.htaccess:8–15,29–38` | URLs target localhost; rewrites assume the site root and PHP handler directives target cPanel. These are deployment assumptions, not discovered production secrets. | Document/adapt them for the host; test redirects, asset paths, and PHP execution. No configuration was changed here. |
| `includes/functions.php:161` | `RL_SECRET` is a placeholder, not a discovered private signing key. | Use a private random value on the deployed instance and keep it out of the public copy. |
| `includes/db.php:28–34`, `includes/functions.php:202–211,266–274` | Secure session cookies are commented out; rate-limit cookies are secure-only. | Review HTTPS/local-HTTP behavior and deployment cookie settings. |
| `includes/functions.php:178–199,220–257` | Login throttle state is held in a browser cookie. Missing/invalid state resets the counters. | Do not describe it as server-side brute-force protection; consider separate server-side controls in future work. |
| `includes/functions.php:45–58` | Forwarded IP headers are accepted without a trusted-proxy check. | Review proxy boundaries before treating logged client IPs as authoritative. |
| `includes/functions.php:111–143,348–383` | Public-IP lookup uses an external HTTP service, and login/activity logs include identity/connection metadata. | Review privacy, external-service configuration, and retention; exclude logs and database exports from GitHub. |
| `includes/admin-session.php:22–42`, `includes/teacher-session.php:21–42`, `includes/student-session.php:23–47` | Account revalidation query exceptions fall back to cached session data. | Review the intended behavior on query failures before deployment. This task does not change authorization logic. |
| `admin/activity-log-ajax.php:132–135,678` | Notification actions bypass CSRF; notification fetch marks selected records read. | Avoid claiming that all state changes have CSRF protection. Review this exception separately. |
| `includes/maintenance-status.php:6–8` | Maintenance is disabled, but the message and ETA refer to different dates. No credential was found here. | Review the message/ETA in Admin Settings before enabling maintenance. Keep this required file; do not publish private operational details in its text. |
| `.htaccess:1–5` | Directory listing and indexing controls do not deny requests for known filenames. | Configure host-level restrictions for internal files. Keep exports/backups outside the document root. |
| `assets/images/`, `docs/screenshots/` | Shared branding/avatar assets need a publication-rights check; no actual screenshots are present in the gallery. | Keep required assets available, but verify rights and replace any personal image in the public copy with an approved placeholder. Add only sanitized real screenshots. |

Searches of application PHP and configuration did not identify active SMTP settings, PHPMailer calls, API-key settings, or private-key blocks. This is limited source-review evidence, not a guarantee that every local file is safe. Uploaded images were inventoried rather than visually cleared, and dependency internals were not security-audited.

## Documentation corrections and application limits

| Area | Source evidence | Documentation outcome |
| --- | --- | --- |
| Teacher marks | `teacher/marks-ajax.php:192,236,327` | Entry is restricted to draft exams; do not imply teachers can edit ongoing exams. |
| Administrator marks | `admin/marks-ajax.php:482–483,539` | Admin edits are allowed after publication and clear the saved record's lock. |
| Result calculation | `admin/marks-ajax.php:111–194`; `admin/results-ajax.php:34–100` | Marks summaries use fourth-subject bonus logic; publication rebuilds use an average and any-fail rule. Publishing can change results. Confirm intended grading policy before relying on outputs. |
| Publication actions | `admin/results-ajax.php:335–466`; `admin/exams-ajax.php:572–622` | Results publication rebuilds/locks; exam publication updates status. The README distinguishes these paths. |
| AJAX conventions | `admin/attendance-summary-ajax.php:22–29`; `admin/activity-log-ajax.php:132–135`; `index.php:24–34` | Documented GET/POST summary reads, notification CSRF exception, and different login token field. |
| Dependency behavior | `includes/functions.php:64–108`; administrator PDF scripts; `extensions/composer.json` | Device Detector fallback exists; TCPDF is required for server PDFs; PHPMailer remains optional integration guidance. |
| Font setup | `includes/pdf-helper.php:3–4` | A stock TCPDF package may not provide the custom Bengali font. README explains registration/name configuration. |
| License and identity | Maintainer instructions | Habibur Rahman and the supplied contact are recorded; application license remains unspecified. No LICENSE file was created. |

The official dependency documentation was checked during this review. TCPDF's current upstream documentation describes version 7 changes; the local package metadata identifies 6.7.5. The README now distinguishes the classic integration from a major-version upgrade. No newly selected dependency version has been installed or tested.

## Manual GitHub Web upload checklist

- [ ] Use a clean, separately reviewed upload selection; do not drag the entire working directory into GitHub.
- [ ] Exclude all SQL, database backups, data exports, and archives containing them, including schema migrations.
- [ ] Exclude TCPDF, PHPMailer, Device Detector, and generated `vendor/` files. Keep `extensions/composer.json`.
- [ ] Exclude uploaded photos/documents, local logs, temp directories, IDE/agent metadata, and `PROJECT_KNOWLEDGE.md`. Include only `.gitkeep` under the three runtime upload folders.
- [ ] Inspect `includes/db.php` and `includes/functions.php` immediately before upload; retain only public development configuration and placeholder secrets.
- [ ] Review `includes/maintenance-status.php`, `.htaccess`, and other configuration for private host details or operational messages.
- [ ] Review shared avatars/logos and screenshot pixels for personal information and publication permission. Never include passwords or real student records in screenshots.
- [ ] Keep all required application source, shared assets, `.htaccess`, README, documentation, and `.gitignore`. Confirm the website's file list includes intended dotfiles.
- [ ] Confirm the README points database requests to `habibur@eidhl.com` and makes no claim that the database or libraries ship with the repository.
- [ ] Preview Markdown headings, tables, diagram, and links on GitHub. Image lines remain commented until the matching screenshots are uploaded.
- [ ] Inspect the complete pending file list before submitting the website upload. `.gitignore` does not enforce this checklist.
- [ ] Review the uploaded tree afterward. If sensitive files were accidentally published, removing the visible file alone may leave history or copies; arrange removal and replace any exposed credentials.

## Remaining maintainer decisions and validation

1. Choose the application's distribution license when ready; this review grants no license and creates no LICENSE file.
2. Supply a repository URL, optional demo URL, release notes, and approved real screenshots. No values were invented.
3. Confirm the database version and initial administrator setup instructions supplied privately to users.
4. Confirm intended GPA policy and the differences between publication paths and teacher/admin edit rules. These behaviors were documented, not fixed.
5. Verify supported PHP/database/dependency versions and the Bengali font setup with a fresh private installation. No Composer lockfile is currently present.
6. Complete the README's manual role, workflow, PDF, and responsive-layout checks. Review deployment security separately before exposing a live instance.

Documentation checks cover relative links, local anchors, documented source paths, code-fence balance, and the ignore patterns against representative included/excluded files. They cannot confirm what a future manual Web upload contains or certify application security.

Validation results: Markdown link/anchor and fence checks passed. A custom check of this ignore file's rule set passed 145 path cases, including all 107 application PHP/JS/CSS files outside `extensions/`. Git was not available in the environment, so this was not a `git check-ignore` run. Before/after SHA-256 comparison matched for 461 existing PHP, JavaScript, CSS, SQL, JSON, and image files, including local dependency files. Three SQL files and the local TCPDF/vendor directories still exist and must be excluded manually; nothing was uploaded or removed.
