# Pay on release, release dates, cancellation, e-signature, QR privacy, semester archive

**Status: written, NOT executed.** The build machine had no PHP or MySQL, so no PHP file was run and no SQL was executed.
Only JavaScript syntax (`node --check`) and PHP bracket balance were checked. Run the checklist at the bottom before using this.

## Install
1. Back up the database. 2. `php database/install.php` (or open the site once: `backend/database.php` upgrades itself; SQL version: `database/migrations/001_release_and_archive.sql`).
3. Cron every hour: `php tools/cancel_overdue.php` (Hostinger: hPanel > Cron Jobs). 4. `.env`: `APP_TIMEZONE=Asia/Manila` (default), optional `RELEASE_ALLOW_WEEKENDS=1`.
5. Delete old marker files in `storage/` only if you want the old auto-upgrades to run again; the new one uses `storage/.schema_release_v1`.

## Rules
* **Payment:** new requests are `UPON_RELEASE`. Staff press *Payment received* when the student claims (status must be Ready). One payment per request (UNIQUE + row lock), optional O.R. number must be unique. Refunds are not processed in the portal; previously recorded refund history is retained. Old requests keep `ADVANCE` (receipt upload) so nothing changes for them.
* **Complete (released)** needs payment recorded (or amount 0).
* **Release date:** typed by Registrar/Admin; weekday, not past, max 90 days ahead; the system no longer creates one. Changes go to `audit_log` and the request history; the student is notified.
* **14-day cancellation:** only `READY` + `Unpaid` requests. Clock starts at the later of the ready date and the expected release date; last day = that date + 14 days (23:59:59). Each request is locked and rechecked before it is changed. Cancelled = terminal; only an Admin can *Reinstate* (reason required, audited).
* **Email:** sent when the request becomes Ready; `email_log` UNIQUE(request, kind, ready time) prevents duplicates; failures are logged and retried up to 3 times; the status is never affected.
* **E-signature:** drawn on a canvas for TOR / Form 137 / Honorable Dismissal; server decodes, size-checks, rejects empty drawings, redraws with GD, stores privately, hash-checked when displayed. One per request, no update code (+ optional DB trigger).
* **QR:** new tokens are 128-bit. The scan page shows only reference, documents, copies, dates, status, payment status and a masked name. Full slip (address, birthdate, signature) only when logged in as the owner student or staff.
* **Double submit:** each form has a random token (`requests.submission_token`, UNIQUE); a repeat returns the first request.
* **Archive:** `Master Lists` page. Check file (dry run) shows what will be archived, second press imports. Same file twice, same semester twice, or an older semester than one already imported are refused. Everything in one transaction. Records are never copied or edited; "archived" is the status of their import. Read-only (no edit endpoints, optional DB triggers).

## Files
New: `backend/release.php, release_schema.php, signature.php, masterlist.php, audit.php`, `api/release.php, masterlist.php, signature.php`, `tools/cancel_overdue.php`, `frontend/js/signature.js, masterlist.js`, `database/migrations/001_release_and_archive.sql`, `tests/release_test.php`.
Changed: `backend/{helpers,database,workflow,mailer,students,verification}.php`, `api/{requests,payments,slip,qr,track,documents}.php`, `database/install.php`, `frontend/{student,manage}.html`, `frontend/js/{requests,newrequest,navigation}.js`, `frontend/css/style.css`.
New tables: `request_signatures, audit_log, email_log, master_list_imports, validation_records`. New columns on `requests`: `payment_term, expected_release_date, ready_at, cancelled_at, cancel_reason, submission_token`; `payments`: refund columns.

## Checklist to run (none done yet)
`php tests/release_test.php`; existing tests; then by hand: two copies of a TOR; "Others" without text; TOR without signature; double-click Submit; QR logged out vs logged in; pay twice; paid request at day 14; ready request at day 14 (`--dry-run` first); email with bad SMTP; change release date; import the same list twice; import 2nd semester after 1st; try to edit archived data.
