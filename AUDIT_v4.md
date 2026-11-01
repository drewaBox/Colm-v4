# v4 audit notes (data consistency, guest identity, release gate)

**Not executed.** No PHP/MySQL/Composer/network on the build machine. Only JavaScript syntax checks were run. Everything below is "written", not "tested".

## Problems found (from the source) and what was done
| # | Problem (root cause) | Risk | Change |
|---|---|---|---|
| 1 | `api/guest_start.php` opened an existing guest record when Student ID + email matched, and overwrote its name/course. Both are easy to know. | Anyone could read another person's requests, uploads and personal data | Email OTP (`guest_otps`): 6 digits, 10 min, one use, 5 tries, 60 s resend cooldown, 5 mails/hour/address, 10 codes/hour/IP. Nothing is created or opened before the code is verified. If mail fails in production the guest is refused (no bypass). In development the code is written to `storage/error.log`. |
| 2 | Alumni (no Student ID) got a NEW user every time they started | Duplicate guest accounts | Verified email now maps to the one guest record |
| 3 | `api/dbtest.php` was public and printed DB host, DB name, DB user and raw error messages | Information leak | Deleted (no references) |
| 4 | READY/COMPLETED conditions were copied in several places of `set_request_status` | Screens could disagree | `release_gate_problems()` in `backend/release.php` is the single gate: verified files, soft copies, receipt (old requests), recorded and non-refunded payment for COMPLETED, no READY from rejected/cancelled/needs-correction |
| 5 | Dashboard had one stats query (good) but no rejected/cancelled counts and no money totals | Admin could not reconcile | Same function now returns `rejected`, `cancelled`, `collected` (sum of non-refunded `payments` rows of Paid requests) and `unpaid_balance` (open requests). One formula, used by every page that calls `request_stats` |
| 6 | Release date, payment-on-release, cancellation, signature, archive (earlier versions) | see RELEASE_AND_ARCHIVE.md | already included |

## NOT done / needs your decision (honest list)
* **Temporary passwords are still emailed in plaintext** (`send_student_account_email`, used by staff-created students and CSV import). The reset flow only accepts role `admin`, so a one-time setup link needs a new student setup flow. Proposal: `password_setup_tokens` (3 days, one use), email the link, stop showing the password in the API answer. Not implemented because it changes how the registrar hands out accounts - confirm first.
* UPON_REQUEST payment policy: only `ADVANCE` (old) and `UPON_RELEASE` exist in code; there is no separate "UPON_REQUEST" value.
* Receipt amount/date OCR comparison, duplicate official receipt numbers: the existing `receipt_security.php` was not re-audited.
* Email outbox/queue for non-"ready" emails; holiday/weekend rules for the 14-day period (currently calendar days) - policy decision.
* Integration test "guest data == admin data" is NOT written. Needed: run the system, submit as a guest, compare DB rows with `requests.php?action=detail`.
* No full CSRF/IDOR/XSS review of every endpoint.

## Required checks before use
1. `composer install --no-dev`, fill `.env`, `php database/install.php` (creates `guest_otps`), `php tools/test_mail.php you@example.com`.
2. As a guest: submit the landing form -> code email arrives -> wrong code (5x) is refused -> right code opens the wizard. Try to repeat with someone else's Student ID + email without the code: must stay at step 1.
3. Pay-on-release flow, release gate (try COMPLETED without payment: must be refused), dashboard numbers vs `SELECT COUNT(*) ... GROUP BY status`.
