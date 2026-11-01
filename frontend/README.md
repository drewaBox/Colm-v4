# Frontend

Static COLM registrar portal. Open `index.html` through XAMPP or a local browser.

## Structure

- `index.html`: backward-compatible redirect to the standalone login page.
- `login.html`: central sign-in page and role router.
- `student.html` and `manage.html`: student and admin/registrar portal pages using the shared dashboard UI.
- `css/style.css`: login and portal styles.
- `js/auth.js`: login page (calls `api/login.php`), Remember me (username only), and logout behavior.
- `js/navigation.js`: shared sidebar, page switching, search, theme, and profile behavior.
- `assets/images/`: portal logo, registrar seal, and page background.

After sign-in, each account is routed to its matching role page. Logout returns to `login.html`. The role pages share one UI and common CSS/JS; they are separate entry points, not separate feature sets. Sign-in, requests, uploaded payment receipts and releases are handled by the PHP backend in `api/` and the MySQL database; the browser only displays them.
