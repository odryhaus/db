# Implementation Report

## Files Created

- `.gitignore`
- `.htaccess`
- `.github/workflows/deploy.yml`
- `README.md`
- `assets/app.css`
- `auth.php`
- `bootstrap.php`
- `config/.htaccess`
- `config/config.example.php`
- `db.php`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/DECISIONS.md`
- `docs/KNOWN_ISSUES.md`
- `docs/NEXT_STEPS.md`
- `docs/PLAN.md`
- `helpers.php`
- `index.php`
- `login.php`
- `logout.php`
- `partials_forbidden.php`
- `setup-ceo.php`
- `users.php`

## Files Changed

This was an empty project folder, so all project files were newly created.

Recent update:

- Added GitHub Actions FTP deployment workflow.
- Added `docs/DECISIONS.md`.
- Added `docs/KNOWN_ISSUES.md`.
- Updated README deploy notes.
- Initialized the local Git repository and configured `origin`.
- Updated deploy workflow to use the fixed `/db` target directory and only the configured FTP secrets.
- Added `config/.htaccess` to help block public access to manually uploaded config.
- Added `setup-ceo.php` for one-time CEO password creation using a setup key stored only in `config/config.php`.

## How Authentication Works

Authentication uses PHP sessions and the existing `users` table.

Login checks:

- Submitted email matches `users.email`.
- `users.db_password_hash` is not empty.
- `password_verify(input_password, users.db_password_hash)` passes.
- `users.db_active = 1`.
- `users.db_role` is one of `manager`, `accountant`, or `ceo`.

After successful login:

- PHP regenerates the session ID.
- Basic user identity is stored in `$_SESSION['user']`.
- `users.db_last_login_at` is updated with `NOW()`.
- POST forms use a session-backed CSRF token.

Logout clears the session, removes the session cookie, destroys the session, and redirects to `login.php`.

## How Roles Work

- `ceo`: can access `index.php` and `users.php`.
- `accountant`: can access `index.php`.
- `manager`: can access `index.php`.
- `none`: cannot log in.

The `users.php` page is protected by `require_role('ceo')`.

## One-Time CEO Setup Page

Created:

```text
setup-ceo.php
```

Purpose:

- Set the first DB password for an existing CEO user without manually generating a password hash in terminal.

Access URL:

```text
https://bph.com.ua/db/setup-ceo.php?key=YOUR_SETUP_KEY
```

How it works:

- Requires real `config/config.php`; it will not run from `config/config.example.php`.
- Requires `app.setup_key` in `config/config.php`.
- Refuses access if `setup_key` is empty or still `CHANGE_ME_LONG_RANDOM_SECRET`.
- Requires the correct setup key in the `key` query parameter.
- Finds an existing user by `users.email`.
- Updates only `db_password_hash`, `db_role`, and `db_active`.
- Sets `db_role = 'ceo'`.
- Sets `db_active = 1`.
- Sets `db_password_hash = password_hash(input_password, PASSWORD_DEFAULT)`.
- Uses prepared statements.
- Does not display the generated password hash.

Disable/delete after use:

- Delete `setup-ceo.php` from the server after the first CEO password is created, or
- Set `setup_key` in `config/config.php` to an empty string or a new unknown value.

Security warning:

```text
Delete setup-ceo.php after first CEO password is created.
```

## Database Assumptions

The app assumes the existing database is `qkbbstge_dashboard` and the existing table is `users`.

Required columns:

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

The app intentionally does not use:

- `users.username`
- `users.password`

No schema changes, migrations, table creation, table deletion, or destructive queries were added.

## What Was Not Implemented

- CRM integration.
- Order import.
- Monthly Sales Dashboard.
- Payment tracking.
- Debt tracking.
- Planned outgoing payments.
- Charts.
- Password reset emails.
- Audit log.
- Database migrations.

## Problems Found

- Local PHP linting could not be executed because this environment has no `php` binary available.
- Production PHP version has not been verified.
- The real production `config/config.php` must be created and maintained manually outside Git.
- The first CEO user still needs to be seeded manually in the existing `users` table.
- `.htaccess` protection depends on hosting configuration; it should be manually checked after deploy.
- Earlier workflow draft referenced `FTP_TARGET_DIR`; it was replaced with the fixed requested target path.
- `setup-ceo.php` is intentionally sensitive and must be deleted from the server or disabled after first use.

## Recommendations

- Run `php -l` on all PHP files before deploy or directly on the hosting server.
- Confirm the hosting PHP version is PHP 7.4 or newer.
- Confirm `https://bph.com.ua/db/config/config.php` is not publicly readable after deploy.
- Confirm `config/.htaccess` was deployed.
- Use a long random `setup_key` and disable it immediately after CEO setup.
- Use a strong temporary CEO password, then change it through the CEO user access page.
- Add server-level basic request throttling or a lightweight login throttle before giving access to a wider team.

## Technical Debt

- No audit log for CEO user access changes.
- No login attempt rate limiting.
- No password complexity or minimum length validation.
- `setup-ceo.php` has a minimum password length only; no full password policy or rate limiting.
- No friendly error page for database connection/config failures.
- Session identity is loaded from the session only; role changes for an already logged-in user apply after the next login.

## Files To Review Manually

- `config/config.php` on the server, because it is not committed.
- `.github/workflows/deploy.yml`, especially the fixed target directory.
- `.htaccess`, after deployment, to confirm config and internal PHP files are blocked.
- `config/.htaccess`, after deployment, to confirm the config directory is blocked.
- `users.php`, for CEO-only access behavior.
- `auth.php`, for login rules.
- `setup-ceo.php`, before deploying or deleting from the server after first use.

## Manual Setup Steps

1. Copy config:

   ```sh
   cp config/config.example.php config/config.php
   ```

2. Edit `config/config.php` with real database credentials.

3. Set `app.base_path`:

   - Local root server: `''`
   - Production `/db`: `'/db'`

4. Set a long random setup key in `config/config.php`:

   ```php
   'setup_key' => 'LONG_RANDOM_SECRET_HERE',
   ```

5. Ensure at least one CEO user has an existing row with:

   - `email` set.

6. Open:

   ```text
   https://bph.com.ua/db/setup-ceo.php?key=YOUR_SETUP_KEY
   ```

7. Enter CEO email and new password.

8. Delete `setup-ceo.php` from the server or disable `setup_key` in `config/config.php`.

## Local Test Steps

PHP syntax checks could not be run in this local Codex environment because no `php` binary is installed on PATH or in the bundled runtime. Run this on the server or any machine with PHP installed:

```sh
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

Then:

1. In `config/config.php`, set `app.base_path` to `''` when serving from the project root.

2. Start local server:

   ```sh
   php -S localhost:8000
   ```

3. Open:

   ```text
   http://localhost:8000/login.php
   ```

4. Test:

   - Invalid login shows a generic error.
   - Valid CEO login redirects to `index.php`.
   - CEO sees the `Users` link.
   - CEO can update role, active status, and optional new password.
   - Manager/accountant can open `index.php` but not `users.php`.
   - Logout redirects to `login.php`.

## Deployment Steps

1. Configure GitHub repository secrets:

   - `FTP_SERVER`
   - `FTP_USERNAME`
   - `FTP_PASSWORD`

2. Confirm the workflow target maps to `/db`:

   ```text
   domains/bph.com.ua/public_html/public/db/
   ```

3. Push to `main` or manually run the GitHub Actions workflow.

4. Upload real `config/config.php` separately.

5. Set `setup_key` in `config/config.php` to a long random secret for first setup.

6. Confirm `config/config.php` is not publicly accessible.

7. Open setup page once:

   ```text
   https://bph.com.ua/db/setup-ceo.php?key=YOUR_SETUP_KEY
   ```

8. Create the first CEO password.

9. Delete `setup-ceo.php` from the server or disable `setup_key`.

10. Open:

   ```text
   https://bph.com.ua/db/login.php
   ```

11. Log in with a CEO account and verify `users.php`.

## Deploy Workflow

Created:

```text
.github/workflows/deploy.yml
```

The workflow uses `SamKirkland/FTP-Deploy-Action@v4.3.5`.

It deploys from the repository root to:

```text
domains/bph.com.ua/public_html/public/db/
```

It excludes:

- `.git`
- `.github`
- `.DS_Store`
- `docs`
- `README.md`
- `config/config.php`
- local temp/log files

It includes `setup-ceo.php` for first setup. Delete it from the server after use.

The workflow does not contain FTP credentials or production config.

Exact secrets used:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

## Test After Deploy

1. Open `https://bph.com.ua/db/login.php`.
2. Confirm the login form loads.
3. Try an invalid login and confirm it shows only a generic error.
4. If CEO is not prepared yet, open `https://bph.com.ua/db/setup-ceo.php?key=YOUR_SETUP_KEY`.
5. Create the CEO password and then delete/disable setup.
6. Log in as the prepared CEO user.
7. Confirm `index.php` loads and shows role/user info.
8. Open `users.php` as CEO.
9. Confirm password hashes are not displayed.
10. Log out and confirm protected pages redirect to login.
11. Confirm `https://bph.com.ua/db/config/config.php` is blocked.

## Risks / Open Questions

- Exact PHP version on hosting was not verified.
- Exact web root path for `/db` should be confirmed during deployment.
- `.htaccess` behavior depends on LiteSpeed/Apache configuration.
- Initial CEO password must be seeded manually in the existing database.
- User access changes are not yet audited in a separate log table.
- Login attempt rate limiting is not implemented yet.
- `setup-ceo.php` must not remain enabled after first setup.
