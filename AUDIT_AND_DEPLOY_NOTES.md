# Audit notes, college-only cleanup, email, and hosting (v3)

**Not executed.** There was no PHP, MySQL, Composer or network on the build machine. No PHP file, SQL or email was run.
Only JavaScript syntax (`node --check`) and a bracket-balance check were done. Nothing here is "tested and passing".

## Found and fixed
| Problem | Fix |
|---|---|
| `.htaccess` files and `.env.production.example` are promised by DEPLOY.md but were **missing** from the zip, so on Apache `.env`, `storage/error.log`, `database/*.sql` and `backend/*.php` were downloadable | Recreated: root `.htaccess` (blocks dotfiles, private folders, PHP outside `/api/`, listing, adds headers) + deny files in backend, database, storage, tests, tools, deploy; `.env.production.example` |
| `vendor/` (PHPMailer) is **not in the zip** - the most likely reason emails fail ("Composer dependencies are missing" is in the shipped error log) | Run `composer install --no-dev` on the server. `php tools/test_mail.php` now says exactly what is missing |
| SMTP had no timeout (a dead mail server could hang a page for minutes); 4 senders each repeated the same code; recipients were not validated | One `mail_send()` in `backend/mailer.php`: validates the address, 10 s timeout (`MAIL_TIMEOUT`), masked error log, admin-friendly error text. All senders use it |
| Storage marker files copied from the developer machine would make a NEW database skip its upgrades | Removed from the package; they are recreated automatically |
| Senior High / Grade 11-12 logic in requests, documents, verification, students, academic pages, CSV sample, tests | Removed. `backend/college_only.php` deactivates non-college levels, copies non-college students to `students_non_college_backup`, deactivates "Form 138" - **nothing is deleted** |
| Year numbers 1-12 allowed; levels could be added | Now 1-6; new levels refused; courses only under COLLEGE |

## Decisions kept, please confirm
* The credential request form still asks for *previous schools* ("Senior high school / year graduated", "Strand"). These describe a college student's earlier education, not a system function, so they were kept.
* Form 137 stays in the catalog (colleges sometimes issue it). Form 138 was deactivated.
* Student accounts are not deleted. Any student whose level was not COLLEGE keeps their record, listed in `students_non_college_backup`. Review it, then fix those students by hand.

## Email: how to verify
1. `composer install --no-dev`  2. fill `MAIL_*` in `.env` (see `.env.production.example`)  3. `php tools/test_mail.php` (config + connection check)
4. `php tools/test_mail.php your@address` sends a real test. "Accepted by the server" is not the same as "arrived": check the inbox and spam.
Admin website test: `POST api/mail_test.php` (admin, CSRF, 5 per 10 minutes).
Common causes: wrong port/encryption pair (465=ssl, 587=tls), `MAIL_FROM` different from `MAIL_USERNAME`, Gmail without an App Password, host blocks outgoing SMTP.

## Deployment checklist
1. Back up: `mysqldump -u USER -p DBNAME > backup_YYYYMMDD.sql` and copy `UPLOAD_DIR`.  2. Upload files, keep your own `.env`.
3. `composer install --no-dev`  4. `php database/install.php`  5. Cron hourly: `php tools/cancel_overdue.php`
6. Open `https://your-domain/.env`, `/storage/error.log`, `/backend/helpers.php`, `/database/install.sql` in a browser: **all must show 403/404**.
7. `APP_ENV=production`; remove `INITIAL_ADMIN_PASSWORD` after first login; force HTTPS in hPanel.
8. Run `php tests/release_test.php`, `php tests/students_test.php`, the smoke test, then the manual checklist.

## NOT done in this pass (honest list)
Full page-by-page button audit; removal of unused files (needs dependency tracing with the app running); query/index optimisation from real data; Admin/Registrar role merge (both still share `STAFF_ROLES`, so they already have the same access except refunds/reinstating/audit which are admin-only); CSRF/IDOR/XSS review beyond the files touched; password-reset and notification end-to-end tests; responsive checks in a browser.
