# Database

`install.sql` holds the table structure and the document catalog. Run `php database/install.php` to create or upgrade the database (it never deletes data). Accounts are stored in the `users` table with hashed passwords; nothing is defined in the frontend.
