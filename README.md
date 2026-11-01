# COLM Registrar – Student Document Management System

Plain PHP + MySQL + JavaScript. No framework or npm; Composer installs PHPMailer for student account notification email.
Needs **PHP 8.0+** (`pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `gd`; `intl` optional) and **MySQL/MariaDB** (all in XAMPP; turn on `extension=gd` in `php.ini` if it is off).

## Roles

| Role | Can do |
|---|---|
| **Student** | Own profile, catalog, requests (stages 1-5), uploads with verification, QR code, payment information |
| **Registrar** | Students (add/edit/CSV import), catalog, review and approve/decline documents, process requests |
| **Admin** | Everything the registrar does **plus** creating Admin/Registrar accounts and the AI tools check |

The role is checked in PHP in every `api/` file, not only by hiding menu items. Student-submitted payment receipts are reviewed by an admin or registrar; no cashier role is used.

Students and alumni start requests from the public home page using the **Request** form; this does not use the staff sign-in. Admin and Registrar accounts sign in through `frontend/login.html`. A returning guest requester must use the same Student ID and email address as before.
Approval emails for staff-created student accounts require PHPMailer (`composer install`) and valid SMTP settings in `.env`. The Students list provides **Resend approval email** for accounts that were approved before SMTP was configured or whose first email failed.
In-app notifications link to their related request or student record when clicked.

A student can have only one active request per document type. The same document becomes available again after its request is completed or rejected; requests for other document types are unaffected.

## Folder guide

| Folder / file | What is inside |
|---|---|
| `frontend/` | `login.html`, `student.html`, `manage.html` (admin and registrar), `css/style.css`, `js/`. `admin.html`, `registrar.html`, and `index.html` redirect to the current portal |
| `api/` | One PHP file per feature: `login.php`, `requests.php`, `verify.php`, `payments.php`, `qr.php`, `students.php`, `users.php`, `documents.php`, `photo.php`, `file.php`, `notifications.php`, `ai_test.php` |
| `backend/` | Shared code: `database.php`, `auth.php`, `helpers.php`, `files.php`, `workflow.php`, `ai.php` (document check), `names.php`, `students.php` (school structure + CSV) |
| `database/` | `install.sql`, `install.php` (installs **and upgrades** an older database) |
| `tests/` | `smoke_test.php`, `ai_parse_test.php`, `names_test.php`, `students_test.php`, `softcopy_test.php` |

The document pre-check reads files with Tesseract (`OCR_PROVIDER=tesseract`, XAMPP/VPS) or with the free online OCR of ocr.space (`OCR_PROVIDER=ocrspace`, for Hostinger Premium). See `.env.example` and DEPLOY.md.

Requires **PHP 8.0 or newer** (with `pdo_mysql`, `mbstring`, `fileinfo`, `gd`) and MySQL/MariaDB. To put the site on a real server read **DEPLOY.md**.

## Install on XAMPP

1. Copy the folder to `C:\xampp\htdocs\new-ui-main`, start **Apache** and **MySQL**.
2. Copy `.env.example` to `.env`.
3. Run `composer install`, then configure the `MAIL_*` values in `.env` for account email and set `APP_URL` to the site's public base URL (for example `http://localhost/new-ui-main`).
4. Run `php database/install.php` – creates or upgrades the database, adds the new columns/tables, disables legacy cashier accounts, and adds the development demo student if it is missing.
5. Open `http://localhost/new-ui-main/` for the public student request and tracking site. Staff sign in at `frontend/login.html`. Development staff accounts use password `Password123!`: `admin` and `registrar`. `2026-00001` is a sample student record, not a staff sign-in account. **Never deploy with demo credentials:** set `APP_ENV=production` and `INITIAL_ADMIN_PASSWORD` in `.env` before running `php database/install.php` on a real server (then remove `INITIAL_ADMIN_PASSWORD` from `.env`).

Student accounts require an email address. Their temporary login credentials are sent through PHPMailer; if email delivery fails, the response reports the failure for secure follow-up.
The login page's **Forgot Password?** flow requires a matching username and registered email, and sends a one-time link that expires after 30 minutes. Password recovery will not send email until valid SMTP settings are configured.
5. Install the two free AI tools below, then log in as admin and press **Settings > Check AI tools**.

## Free AI tools

**Document checking – Tesseract OCR (free, runs on your computer).**
* Windows: install *Tesseract OCR* (the UB Mannheim installer). The standard `C:\Program Files\Tesseract-OCR\tesseract.exe` location is detected automatically; for a custom location, set `TESSERACT_PATH` in `.env`.
* PDF files also need *poppler* (`pdftotext`, `pdftoppm`). Download the Windows build, and put its `bin` folder in `.env`: `POPPLER_PATH=C:\poppler\Library\bin`. Pictures (JPG/PNG) work without poppler.
* Linux: `sudo apt install tesseract-ocr poppler-utils`.


No key, no internet and no account are needed after the downloads. If a tool is missing or stopped the website keeps working and shows a clear message.

## Notifications

The notification badge checks for updates every 15 seconds while the portal is open. New notifications appear in a bottom-of-screen popup that closes automatically after five seconds. The bell still shows the full notification list.

## Stage 3 – document pre-check

Each file is sent to `api/verify.php` for an initial automated pre-check as soon as it is chosen. The student sees **✓ File uploaded: name.pdf** and **Document pre-check: In progress...**, then one of:

| Result | Student sees | Can continue? |
|---|---|---|
| VERIFIED | "Document pre-check passed." | yes; the registrar still reviews the request |
| DECLINED | "Document pre-check: Not accepted" with a reason (too blurry, too dark, could not be read, not the required document, name does not match ...) | **no** – Upload Again |
| NEEDS CORRECTION | "Document pre-check: Needs correction" with a reason | **no** – Upload Again |
| PENDING | "Document pre-check incomplete" with a reason | **no** – Try Again |

How a file is checked (`backend/ai.php`): 1) picture quality with PHP (too small, too dark, blank, blurry; PDF damaged/locked); 2) Tesseract reads the document text; 3) enough clear text? 4) does it contain at least two configured requirement keywords and a document-specific keyword (for example, "transcript" for the TOR request or "clearance" for its clearance requirement)? Generic words such as "request" and "form" alone do not pass; 5) when the requirement asks for a name, does the name read from the document match the first and last name on the student's account? The uploaded filename alone is never used as proof of document type or identity.
Removing the file clears the status. The **Review Request** button is disabled while a file is missing, being checked, declined, needs correction or pending. This is an initial pre-check; the registrar makes the final review.

It cannot be skipped: `api/requests.php` only accepts verification numbers that belong to the logged-in student, that are VERIFIED and were not used yet. Refreshing, editing the JavaScript or sending your own POST does not help. The registrar still approves or declines every document afterwards.

## AI verification of the whole request + routing

After the files pass their own check, `backend/verification.php` checks the **whole request** and routes it (it runs on submit and again on every re-submit of corrections):

| Check | What it looks at |
|---|---|
| Account information | name, student number, school level, course, year level are on the account |
| Request form | credential form (TOR) or Student Verification form is complete and matches the account |
| Selected documents | each document is active, available in the chosen format |
| Quantity | 1-5 copies per document, 1-15 documents |
| Requirements | every required file is present, not declined by the registrar and not failed by the document check |
| Eligibility | e.g. Certificate of Enrollment = currently enrolled, Certificate of Graduation = alumni (`eligibility_rules()`) |

| Document | Route | Who decides |
|---|---|---|
| Transcript of Records (and other academic-record documents) | `REGISTRAR` | Registrar verifies the academic records and approves |
| Certificate of Enrollment, Good Moral, Graduation, other certifications | `AUTO` | AI + account records; flagged for **manual review** by the Registrar when something is unclear |

* A failed check sends the request back to the student (**Needs Correction**) with the reason. The AI never rejects a request by itself; unclear items are flagged and the Registrar decides.
* Student portal words: **Pending**, **Under Verification**, **Approved** (Processing / Ready), **Rejected** (plus Needs Correction and Completed). Staff still see the detailed status plus the route and the AI result.
* The result is saved in `request_verifications` (created automatically on first use; also in `install.sql`) and shown in the request window as **AI Verification**.
* Payment is not skipped: **Approved** (Processing) still needs the Registrar to verify the payment receipt.
* Change who goes to the Registrar in `registrar_route_document()` and the eligibility rules in `eligibility_rules()`.
* Test: `php tests/verification_test.php`

## Stage 5 – QR code

`api/qr.php` returns the QR text of a request; if the request has none yet it creates one (`COLM|tracking number|token`), **saves it in `requests.qr_code`** and returns it. Asking again always returns the same QR, so there are no duplicates and it is still there after a refresh. The picture is drawn in the browser by our own small encoder `frontend/js/qr.js` (no internet / CDN needed). If it fails: "QR code generation failed. Please try again." with a Try again button. Students also see the QR when they open a request in My Requests.

## School Level / Course / Year / Section

The list lives in `backend/students.php` (`school_structure()`). The Add/Edit Student form gets it from `api/students.php?action=structure`, so the dropdowns and the PHP check use the same list. Every College course has the same setup as BSTM: First Year has 3 sections, Second Year has 2, Third Year has 4, and Fourth Year has 2. The installer aligns existing College courses to this setup, retaining extra year/section entries as inactive when existing students use them; newly added College courses copy BSTM's year and section setup. Senior High: Grade 11 STEM 2, HUMSS 3; Grade 12 STEM 1, HUMSS 2. Junior High has been removed from active student enrollment; existing student and academic records are retained as inactive.

The system is **college only**. Senior High and Junior High levels are deactivated (never deleted) by `backend/college_only.php`, and the non-college students found are copied to `students_non_college_backup`.



## Soft copy (PDF) release

1. **Request:** step 2 of the request form has **Document Format**: Physical / Hard Copy, Soft Copy / PDF or Both. Soft copy is only offered when every chosen document allows it (Catalog > Release methods). The payment and the registrar's verification work exactly as before (the value is saved in `requests.release_method`).
2. **Registrar** (request window, after the uploaded payment receipt is verified and the request is *Processing*): **Upload Soft Copy** (or **Replace PDF**), then **Mark ready for release**. The request cannot become *Ready for Release* until every soft copy is marked ready. The registrar sees the file name, who uploaded it and when, the downloads used, and can **View PDF**. Only PDF files are accepted (the content is checked, not only the name), maximum 10 MB, damaged or password-protected PDFs are refused, and the file is saved under a random name (the uploaded name is never used on the disk). The request list shows **Soft Copy Available** / **Waiting for Registrar**.
3. **Student** (dashboard > **My Document Requests**): Request No., Document, Format, Status, Payment Status, Soft Copy, Actions. *Download PDF* appears only when the soft copy is ready, paid and released.
4. **One-time download:** each document has `download_limit = 1`. The download is a secure `POST` to `api/softcopy.php?action=download`; the server checks the login, that the document belongs to this student, the payment, the status and the limit, then sends the PDF. Every download is written to `softcopy_downloads` (student, user, date/time, download number, IP, and the approval that allowed it). The PDF is **never deleted** and has no public address (`file.php` refuses soft copies; the upload folder is blocked by `.htaccess`; for the best protection put `UPLOAD_DIR` outside the web folder).
5. **Additional download:** after the first download the student sees *Download Used* and **Request Additional Download**. The allowed reasons are **PDF file was corrupted**, **System error - unable to download**, and **Wrong name or data**; the student provides details for the registrar. Admin / Registrar open **Download Requests**: student, document, request no., original download date, previous downloads, reason, request status, then **Approve Additional Download** (limit +1, who and when are saved) or **Reject Request** (optional reason that the student sees). Everything is kept in `softcopy_download_requests` and in the request history.
6. **Download history:** *Download history* (request window or Download Requests page) lists every download with date, reason, approval status and approver, including staff views (not counted).
7. A replaced PDF starts again (not ready, one download); the old PDF stays in the database and on the disk.

## Academic Management (Admin)

**Academic Management** (menu) has the tabs **Levels, Courses, Year Levels, Sections**, each with **Add**, **Edit** (rename), **Deactivate / Activate** and **Remove**, a status filter and a search box. The Courses tab has a category dropdown to filter **College Course** or **Senior High Strand**. "Level" means the school level (College, Senior High School); a course belongs to a level, a year level to a course and a section to a year level (tables `school_levels`, `school_courses`, `school_year_levels`, `school_sections`, filled with the starting list the first time by `database/install.php`). New College courses automatically copy BSTM's Year 1–4 and section counts (3/2/4/2); admins can still edit or deactivate these entries in Academic Management.
* Students keep the names as text, so **an item that students use is never deleted**: Remove only deactivates it (with the message "This item is currently being used by existing records. It will be deactivated instead of permanently deleted.") and old student records stay valid. An unused item is deleted after the confirmation.
* Renaming an item that students use renames it in their records too.
* Deactivated items disappear from the Add / Edit Student lists and from CSV import, but still appear in the filters. A student who keeps a deactivated value can still be edited if the value is not changed.

## Filters, dark mode and small screens

* **Filters** (server side, every change reloads from page 1, "Reset filters" clears them and the search box, and they all work together):
  * All Requests / My Requests: Status, Payment, Document (request type), From / To date.
  * Students: School level > Course > Year level > Section (each list only offers real values) + Account (active / disabled / default password).
  * Accounts: Role + Status. Catalog: Category + Status.
  * The search box works together with the filters. Pages of 20-25 rows have Previous / Next buttons.
* **Dark mode** uses the colour variables at the top of `frontend/css/style.css` (`--text`, `--white`, `--muted`, `--placeholder`, `--danger-text`, `--success-text`, `--warn-text` ...). Never write a fixed colour for text: use a variable, or the text can disappear in one of the two modes. Text on a primary-coloured button uses `var(--white)` (the primary colour is white in dark mode).
* **Small screens**: the polish layer at the end of `style.css` keeps everything inside the screen. Tables scroll inside their own box, windows (modals) scroll inside and always show the close button, buttons wrap or become full width on phones.

## Deploy checklist

* `.env`: `APP_ENV=production`, real `DB_*`, `INITIAL_ADMIN_PASSWORD` (10+ characters). Never commit `.env`.
* Run `php database/install.php` once on the server. Change the demo passwords.
* `UPLOAD_DIR` outside the web folder. Use HTTPS. Apache needs `AllowOverride All` for the `.htaccess` files.
* The server needs Tesseract (and poppler for PDF) installed.
* If `api/` is not next to `frontend/`, change `API_URL` in `frontend/js/api.js`.

## Tests

```
php tests/names_test.php
php tests/students_test.php
php tests/dates_test.php
php tests/softcopy_test.php
php tests/ai_parse_test.php
php tests/smoke_test.php http://localhost/new-ui-main/api/
```
`smoke_test.php` needs the database installed, Apache running, GD and Tesseract installed. It creates test students, requests, payments and uploaded files; run it only against a disposable database, never a populated or production database. It covers login and role blocking, Stage 3 (blurry, dark, other person's document, tampered requests), the QR code, payments and receipts, student and staff accounts, the school structure and CSV import, and the chatbot's friendly error.

Estimated release dates are calculated from the request submission date using the catalog's processing range in working days; Saturdays and Sundays are excluded. The latest date in the range is the latest estimate. School holidays and registrar delays are not included.
