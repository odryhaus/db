# .BRAND DB

Simple internal PHP system for `.BRAND` money control. The current MVP contains authentication, CEO user management, a money dashboard, CEO-only manual order sync, sales targets, expenses planning, and invoice draft generation.

## Purpose

The project is the foundation for a fast internal dashboard at `/db`. It will later support monthly sales control, unpaid orders, debts, and planned outgoing payments.

Current milestone:

- Login and logout.
- Protected home page.
- CEO-only user access management.
- CEO-only manual KeyCRM order sync into local `db_orders`.
- Money dashboard metrics from local `db_orders`.
- CEO-only sales target management.
- CEO/accountant expenses foundation.
- CEO/accountant editable invoice and delivery note drafts from local `db_orders`.

Not included yet:

- Automatic CRM sync.
- Charts.
- Full payments ledger.
- Full debt workflow.
- VAT invoices.
- KeyCRM file attachment.

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

Real KeyCRM credentials also belong only in `config/config.php`:

```php
'keycrm' => [
    'base_url' => 'https://openapi.keycrm.app/v1',
    'api_key' => 'REAL_KEYCRM_API_KEY',
],
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

Money dashboard sales source:

```text
db_orders
```

The old `orders` table is outdated and ignored.

Planning tables are additive and created only if missing:

```text
db_sales_targets
db_expenses
db_our_companies
db_invoices
db_invoice_items
```

`db_sales_targets` is the active source of truth for company and manager targets. The old monthly target tables are kept if they exist but are not used for new dashboard target logic.

Invoice tables store editable local document copies. They do not modify KeyCRM orders.

## Dashboard

Money Dashboard is the protected home page. It reads from local `db_orders` after CEO manual sync. It does not call KeyCRM from the browser.

Dashboard rule:

- Selected month controls monthly sales plan/fact.
- `Нам повинні` shows all unpaid client debt across all months.
- Monthly target comes from `db_sales_targets`, with `4,000,000 UAH` fallback.
- Manager targets come from `db_sales_targets`.
- Targets are effective from a date and remain active until changed.
- Operational expenses and strategic debts come from `db_expenses`.
- Old `orders` table is ignored.

Current monthly target:

```text
4,000,000 UAH
```

CEO sync page:

```text
https://bph.com.ua/db/sync_orders.php
```

Sync scope is current month and previous month only.

## Invoices

Invoice drafts are managed at:

```text
https://bph.com.ua/db/invoices.php
```

Rules:

- Source data comes from local `db_orders` and `db_orders.raw_json`.
- Invoice items are editable local copies, not live CRM products.
- FOP 2 group seller companies marked `products_only` use product wording only.
- Default collapsed title is `Поліграфічна продукція`.
- Current documents are no-VAT only and include `Без ПДВ. Платник єдиного податку.`
- Generated files are saved locally under `storage/invoices`.
- Direct web access to `storage/invoices` is blocked; open files through the authenticated invoice page.
- KeyCRM file attachment is not implemented yet.

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

The workflow deploys to:

```text
domains/bph.com.ua/public_html/public/db/
```

The deploy workflow excludes `.git`, `.github`, `docs`, `README.md`, local temp files, and `config/config.php`. It does deploy `config/.htaccess` to help block public access to manually uploaded config.

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
- `setup-ceo.php` was a temporary setup tool and must not remain deployed.
