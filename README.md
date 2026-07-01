# .BRAND DB

Simple internal PHP system for `.BRAND` money control. The first MVP contains authentication only.

## Purpose

The project is the foundation for a fast internal dashboard at `/db`. It will later support monthly sales control, unpaid orders, debts, and planned outgoing payments.

Current milestone:

- Login and logout.
- Protected home page.
- CEO-only user access management.

Not included yet:

- CRM integration.
- Monthly sales dashboard.
- Charts.
- Payments or debt screens.
- Database migrations.

## Local Setup

1. Copy the config template:

   ```sh
   cp config/config.example.php config/config.php
   ```

2. Edit `config/config.php` with local or production database credentials.

3. For local root testing at `http://localhost:8000/login.php`, set:

   ```php
   'base_path' => '',
   ```

4. Run a local PHP server:

   ```sh
   php -S localhost:8000
   ```

5. Open:

   ```text
   http://localhost:8000/login.php
   ```

For production deployment at `https://bph.com.ua/db`, set `app.base_path` to `/db`.

## Config Setup

Real credentials belong only in:

```text
config/config.php
```

That file is ignored by Git. Commit only:

```text
config/config.example.php
```

## Database

The app uses the existing `qkbbstge_dashboard.users` table. It does not modify schema.

Used fields:

- `email`
- `db_password_hash`
- `db_role`
- `db_active`
- `db_last_login_at`

Do not use `users.username` or `users.password` for this project.

## Deploy Notes

Deploy the project files to:

```text
https://bph.com.ua/db
```

Expected server directory is likely similar to:

```text
domains/bph.com.ua/public_html/public/db/
```

Upload `config/config.php` separately on the server. Do not commit it.

GitHub Actions deploy uses FTP and requires these repository secrets:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_TARGET_DIR`

`FTP_TARGET_DIR` should point to the server directory that maps to `https://bph.com.ua/db`, for example:

```text
domains/bph.com.ua/public_html/public/db/
```

The deploy workflow excludes `.git`, `.github`, `docs`, `README.md`, local temp files, and `config/config.php`.

After deploy, test:

```text
https://bph.com.ua/db/login.php
```

## Security Notes

- Uses PHP sessions.
- Regenerates session ID after successful login.
- Uses `password_hash()` and `password_verify()`.
- Uses prepared statements for all DB reads/writes.
- Escapes HTML output.
- Does not show password hashes.
- Uses CSRF tokens for POST forms.
- Keeps real DB credentials out of Git.
