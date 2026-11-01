# Deploying COLM Registrar

Two ways. **A) Hostinger Premium (shared hosting)** is described first. **B) Your own server / VPS** is at the end.
Do the steps in order. Do NOT skip the "check private files" step.

## A) Hostinger Premium (Web Premium)

What is different from your own computer: you cannot install programs (Tesseract) on shared hosting, so the document pre-check
reads documents with the **free online OCR of ocr.space**. Everything else (PHP, MySQL, .htaccess, SSH, curl) is available on Premium.

### 1. Free OCR key (2 minutes)
Request a free key at https://ocr.space/ocrapi/freekey (it is sent by email). Keep it for step 6.
Limits of the free plan: **1 MB per file**, **PDFs up to 3 pages**, **500 requests per day per server address** (25,000 per month).
The system shrinks big photos automatically; a PDF over 1 MB is declined with a clear message asking the student for a smaller file or a photo.
Because shared hosting may share its address with other websites, the daily limit can be reached sooner. If that happens the student sees
"temporarily unavailable" and cannot continue (see `AI_FALLBACK` in step 6).

### 2. Privacy (important)
With `OCR_PROVIDER=ocrspace` every uploaded document is sent over the internet to ocr.space to be read. Tell students in your privacy notice /
consent form (Data Privacy Act of 2012) and ask your school's Data Protection Officer. If documents must never leave your own server, use a VPS and Tesseract (part B).

### 3. Website, PHP and database (hPanel)
1. hPanel > Websites > add your domain. **Security > SSL**: turn on the free SSL and "Force HTTPS".
2. **Advanced > PHP Configuration**: PHP **8.1 or 8.2**. Extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl` (curl is on by default).
   PHP options: `upload_max_filesize` 12M, `post_max_size` 14M, `max_execution_time` 120, `display_errors` Off. Do NOT remove `exec` from disableFunctions (it is not needed).
3. **Databases > MySQL Databases**: create a database + user + password. Write them down. The host is usually `localhost`.

### 4. Upload the files
Upload everything inside the `new-ui-main` folder into `public_html` (File Manager or FTP). Then, in the **home folder** (one level ABOVE `public_html`),
create a folder `colm_uploads`. This is where student documents are stored, so nobody can open them from the web.
Its full path looks like `/home/u123456789/colm_uploads` (run `pwd` in SSH to see your real path).

### 5. SSH (needed once)
hPanel > Websites > Dashboard > **SSH Access** > Enable. Connect (the port is shown in the same page, normally 65002):
`ssh -p 65002 u123456789@your-server-ip`. Then `cd domains/yourdomain.com/public_html` and check `php -v` shows 8.0 or newer.

### 6. The .env file
Copy `.env.production.example` to `.env` in `public_html` (File Manager or SSH `cp`), then fill in:
`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `UPLOAD_DIR=/home/u123456789/colm_uploads`, `OCR_PROVIDER=ocrspace`, `OCRSPACE_API_KEY=<your key>`,
`APP_URL=https://your-domain.example`, the `MAIL_*` SMTP settings, and `INITIAL_ADMIN_PASSWORD=<10+ characters>`.
`APP_URL` must be the public site URL used to build password-reset links. Valid SMTP settings and Composer dependencies (`composer install`) are required to send password-reset and account emails.
Keep `APP_ENV=production`. Leave `TRUST_PROXY=0` (set 1 only if you put Cloudflare in front).
`AI_FALLBACK=strict` means a student cannot continue while the pre-check is down (nothing is skipped). `AI_FALLBACK=manual` accepts the file and the registrar checks it by hand.

### 7. Create the database tables and the admin
In SSH: `php database/install.php`. It creates the tables, the document catalog and ONE account, `admin`, with your `INITIAL_ADMIN_PASSWORD`.
Then DELETE the `INITIAL_ADMIN_PASSWORD` line from `.env`. (An old database is also upgraded automatically the first time someone logs in.)
Log in as `admin` and create registrar accounts (Settings > Staff accounts).

### 8. Check that private files are private (every address must give 404 or 403, never the file)
`/.env`  `/.env.production.example`  `/storage/error.log`  `/backend/helpers.php`  `/database/install.php`  `/tests/smoke_test.php`  `/deploy/nginx.conf.sample`

### 9. Check the pre-check
Log in as admin > Settings > **Check AI tools**. All rows must be green (it sends one tiny test picture to ocr.space).
Then log in as a test student and upload a real ID photo and a PDF under 1 MB.

### 10. Students, backups
* Students may register from the sign-in page and must provide the academic year attended or graduated. Review that year and registrations marked **Awaiting approval** in Students; use **Approve account** only after verifying the Student ID and student status.
* Approval email delivery requires Composer dependencies (`composer install`) and valid SMTP settings in `.env`. Use **Resend approval email** in Students to retry after fixing mail configuration.
* Import students (Students > Import CSV). Every new, imported or reset student account starts with the default password `Password123!` and must choose a new one at the first login.
  Give students their login (Student ID) and the default password only when they come to activate the account. The Students filter **Account: Default password** shows who has not changed it yet.
* hPanel > Backups: keep automatic backups on, and also export the database (phpMyAdmin > Export) before every update.
* Delete the `tests/` folder on the live server (the tests create fake students and requests; never run them on live data).

### Updating later
Replace the files, keep `.env`. If the database changed the system upgrades it by itself on the next login (or run `php database/install.php`).

## B) Your own server or VPS

1. PHP 8.0+ (`pdo_mysql`, `mbstring`, `fileinfo`, `gd`), MySQL/MariaDB, HTTPS. Install `tesseract-ocr` and `poppler-utils` and use `OCR_PROVIDER=tesseract` (documents stay on your server).
   Without any reader every student is blocked by the pre-check unless `AI_FALLBACK=manual`.
2. Create the database and an app-only MySQL user. Put the uploads folder OUTSIDE the web root and set `UPLOAD_DIR`.
3. Copy `.env.production.example` to `.env`; run `php database/install.php`; delete `INITIAL_ADMIN_PASSWORD`.
4. Apache: the included `.htaccess` files block private folders (needs `AllowOverride All`). nginx: use `deploy/nginx.conf.sample`.
5. php.ini: `display_errors=Off`, `upload_max_filesize=12M`, `post_max_size=14M`, `max_execution_time=120`.
6. Do the "check private files" step and "Check AI tools" step above, and schedule daily backups.
