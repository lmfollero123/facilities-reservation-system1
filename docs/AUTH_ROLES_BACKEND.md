# Authentication & Roles – Backend Readiness (PHP + MySQL)

This project is built with **PHP, CSS, and JS** and is ready to be wired to a MySQL database when you are.

## 1. Database Setup (XAMPP / MySQL)

1. Open **phpMyAdmin** (usually `http://localhost/phpmyadmin`).
2. Import `database/schema.sql`.
   - This will create the `facilities_reservation` database and a `users` table.
3. Create at least one Admin user:
   - In a temporary PHP script or REPL, run:
     ```php
     echo password_hash('MySecurePassword123', PASSWORD_DEFAULT);
     ```
   - Copy the output and use it as `password_hash` in:
     ```sql
     INSERT INTO users (name, email, password_hash, role, status)
     VALUES ('System Administrator', 'admin@lgu.gov.ph', 'PASTE_HASH_HERE', 'Admin', 'active');
     ```

## 2. PHP DB Config

- File: `config/database.php`
- Update `DB_USER` / `DB_PASS` if your MySQL credentials differ.
- Use `db()` wherever you later need a PDO connection (e.g., in a login handler).

## 3. Wiring Login to the DB (future step)

When you are ready to move from mock login to real authentication:

1. In `login.php`, instead of auto-setting `$_SESSION['user_authenticated']`, do:
   - On POST, `SELECT` user by email from `users`.
   - Verify the password using `password_verify($_POST['password'], $user['password_hash'])`.
   - Check `status` is `active`; if yes, set:
     - `$_SESSION['user_authenticated'] = true;`
     - `$_SESSION['user_name'], $_SESSION['user_email'], $_SESSION['user_mobile'], $_SESSION['user_org'], $_SESSION['role']`.
2. Use `$_SESSION['role']` to guard sensitive pages (User Management, Payments, Audit Trail, etc.).

The existing layout guard in `dashboard_layout.php` already checks `user_authenticated`, so once login is wired to the DB, the dashboards will automatically honor real sessions.

## 4. Deploying to Your Own Domain

When you buy a domain:

1. Point it to a hosting account that supports **PHP and MySQL**.
2. Upload this whole project so that its root folder is the **document root** of your domain (e.g., `/public_html` or `/var/www/yourdomain`).
   - Because we use root-relative paths like `/public/css/style.css`, ensure the project sits at the domain root (e.g., `https://your-lgu-domain.gov/`), not in a subfolder.
3. Create the same database on the server and import `database/schema.sql`.
4. Update `config/database.php` with your production DB host, name, user, and password.

After that, all your existing pages and modules will be accessible under your real domain with minimal changes.




