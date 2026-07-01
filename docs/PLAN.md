# .BRAND DB Authentication MVP Plan

## Goal

Create the first working PHP MVP for `.BRAND DB` with authentication only. The app must stay small, fast, and deployable to `/db` on PHP/LiteSpeed hosting.

## Scope

- Plain PHP, no framework, no Composer, no Node.
- Session-based login/logout.
- Protected home page.
- CEO-only user access management page.
- Use existing `users` table only.
- No database schema changes.
- No CRM integration.
- No charts, payments, monthly dashboard, imports, or migrations.

## Database Fields Used

Only these columns from `users` are used:

- `id`
- `keycrm_id`
- `email`
- `first_name`
- `last_name`
- `status`
- `db_password_hash`
- `db_role`
- `db_active`
- `db_last_login_at`

Explicitly not used:

- `users.username`
- `users.password`

## File Structure

- `bootstrap.php`: shared setup, config loading, session initialization.
- `db.php`: PDO connection helper.
- `helpers.php`: escaping, redirects, request helpers.
- `auth.php`: login checks, role guards, login/logout helpers.
- `login.php`: public login form.
- `logout.php`: session destroy.
- `index.php`: protected dashboard foundation page.
- `users.php`: CEO-only user access management.
- `config/config.example.php`: safe config template.
- `assets/app.css`: minimal local CSS.
- `.gitignore`: excludes real config/secrets.
- `.htaccess`: basic protection and PHP hardening where supported.
- `README.md`, `docs/IMPLEMENTATION_REPORT.md`, `docs/NEXT_STEPS.md`: project docs.

## Authentication Flow

1. User submits email and password on `login.php`.
2. App finds user by `users.email` using a prepared statement.
3. Login succeeds only when:
   - `db_password_hash` is not empty.
   - `password_verify()` passes.
   - `db_active = 1`.
   - `db_role` is one of `manager`, `accountant`, `ceo`.
4. Session ID is regenerated after successful login.
5. Minimal user identity is stored in `$_SESSION`.
6. `db_last_login_at` is updated.

## Role Rules

- `ceo`: access to home page and user access management.
- `accountant`: access to protected home page.
- `manager`: access to protected home page.
- `none` or empty role: no login access.

## User Management

The CEO-only `users.php` page lists existing users and allows updating:

- `db_role`
- `db_active`
- `db_password_hash` by entering a new password

Existing password hashes are never displayed. Password update is optional.

## Verification

- Run PHP syntax checks on all PHP files.
- Optionally serve locally with PHP built-in server after creating local `config/config.php`.
- Manual DB-backed login testing requires real database credentials and at least one user with a `db_password_hash`.
