# Implementation Report

## Current Status

Production `/db` authentication is live:

- Auth works.
- CEO login works.
- CEO users page works.
- Logout works.
- Production `setup_key` was disabled manually.
- Production `setup-ceo.php` was manually deleted.

This update adds a CEO-only KeyCRM order debug inspector for reading one real order JSON before designing `db_orders` / cache tables.

## Files Changed

- `assets/app.css`
- `config/config.example.php`
- `docs/CRM_SYNC_PLAN.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/NEXT_STEPS.md`
- `keycrm_debug_order.php`

## KeyCRM Debug Inspector

Created:

```text
keycrm_debug_order.php
```

Purpose:

- Inspect real KeyCRM order JSON before designing `db_orders` or final cache tables.
- Default order id: `9232`.
- Allows entering another order id manually.

Access:

- Requires login.
- CEO-only.

Behavior:

- Uses `config/config.php` server-side only.
- Reads `keycrm.base_url`.
- Reads `keycrm.api_key`.
- Calls KeyCRM server-side only.
- Does not expose the API key to the browser.
- Does not print the Authorization header.
- Does not write to the database.
- Does not create tables.

Endpoint behavior:

- First tries:

  ```text
  GET /order/{id}?include=products,products.offer,status,shipping,manager
  ```

- If direct lookup fails, falls back to:

  ```text
  GET /order?filter[order_id]={id}&include=products,products.offer,status,shipping,manager
  ```

The page shows:

- requested order id
- HTTP status
- endpoint used
- top-level JSON keys
- detected order id
- detected order number
- detected created date
- detected updated date
- detected manager id/name
- detected client/customer/buyer id/name
- total amount candidates
- paid amount candidates
- unpaid amount candidates
- payment status candidates
- currency candidates
- raw JSON in a collapsible block

## KeyCRM Config Setup

`config/config.example.php` now contains placeholders only:

```php
'keycrm' => [
    'base_url' => 'https://openapi.keycrm.app/v1',
    'api_key' => 'CHANGE_ME_IN_REAL_CONFIG',
],
```

Add the real API key only to production `config/config.php`.

Do not commit the real API key.

## How To Open Debug Page

Open:

```text
https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232
```

To inspect another order:

```text
https://bph.com.ua/db/keycrm_debug_order.php?order_id=9279
```

## Data To Copy Back For Architecture Review

From the debug page, copy back:

- top-level JSON keys
- detected order number
- detected created/updated dates
- manager id/name
- client/customer/buyer id/name
- total amount candidates
- paid amount candidates
- unpaid amount candidates
- payment status candidates
- currency candidates
- raw JSON if acceptable for review

## Previous Setup Page State

`setup-ceo.php` was removed from the local project and must not be deployed again.

Additional deployment guard:

```text
setup-ceo.php
```

was added to the GitHub Actions FTP exclude list.

Production already has `setup_key` disabled and `setup-ceo.php` deleted manually. Do not restore it.

## Money Dashboard v0.1

`index.php` now shows a money-focused placeholder dashboard after login.

Visible content:

- Project name: `.BRAND DB`
- Logged-in user name/email
- User role
- Current month
- Monthly target: `4,000,000 UAH`
- Sales fact: `0 UAH`
- Paid: `0 UAH`
- Unpaid: `0 UAH`
- We owe: `0 UAH`
- Remaining to target: `4,000,000 UAH`
- Progress: `0%`
- Main next action: `Connect orders data source`

Access remains unchanged:

- `ceo`, `accountant`, and `manager` can view `index.php`.
- Only `ceo` sees the `users.php` link.

## What Remains Placeholder

- Sales fact.
- Paid amount.
- Unpaid amount.
- We owe.
- Remaining to target.
- Progress percentage.
- Current month calculations beyond display label.

No CRM sync, payments, debts, charts, dashboard calculations, or database schema changes were added.

## Authentication Review

The current auth implementation still uses:

- `users.email` for login.
- `users.db_password_hash` for password verification.
- `users.db_role` for role checks.
- `users.db_active` for access status.
- PHP sessions.
- `password_verify()`.
- Prepared statements.
- Escaped HTML output.

It does not use:

- `users.username`
- `users.password`

## Problems Found

- `setup-ceo.php` still existed locally even though production setup was complete. It has now been removed.
- The deploy workflow did not explicitly exclude `setup-ceo.php`; it now does.
- PHP linting still cannot be run in this local Codex environment because no `php` binary is installed.
- Real KeyCRM money/payment field names are unknown until order `9232` is inspected.

## Recommendations

- Confirm GitHub Actions deploy completes after push.
- Confirm `https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232` opens only for CEO.
- Confirm non-CEO users cannot open the debug inspector.
- Confirm the debug page does not expose the API key.
- Run `php -l` on the server if available.

## Technical Debt

- Money values are static placeholders.
- No real order source is connected.
- No payment/debt calculations exist.
- No dashboard audit or data freshness metadata exists.
- No login rate limiting exists.
- No automated PHP lint/test pipeline exists.
- Debug candidate detection is heuristic and exists only to guide schema design.

## Files To Review Manually

- `keycrm_debug_order.php`
- `assets/app.css`
- `config/config.example.php`
- Production `config/config.php`, only to add `keycrm.api_key`.
- `docs/NEXT_STEPS.md`

## Deploy Workflow

Workflow:

```text
.github/workflows/deploy.yml
```

Deploy target:

```text
domains/bph.com.ua/public_html/public/db/
```

Secrets used:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

Files excluded from deploy:

- `**/.git*`
- `**/.git*/**`
- `**/.github/**`
- `**/.DS_Store`
- `docs/**`
- `README.md`
- `config/config.php`
- `setup-ceo.php`
- `*.log`
- `*.tmp`

## How To Test After Deploy

1. Add real KeyCRM API key to production `config/config.php`.
2. Open `https://bph.com.ua/db/login.php`.
3. Log in as CEO.
4. Open `https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232`.
5. Confirm HTTP status and endpoint used are visible.
6. Confirm detected fields and candidate money fields are visible.
7. Confirm raw JSON is visible in the collapsible block.
8. Confirm API key and Authorization header are not visible.
9. Confirm no database tables were created or changed.

## Risks / Open Questions

- GitHub Actions deploy status must be checked after push.
- PHP lint has not been run locally.
- Real dashboard calculations depend on inspecting existing local database tables.
- The next milestone should avoid CRM/API work until local order data is understood.
- KeyCRM direct `/order/{id}` may fail; fallback filter behavior must be verified from the debug page.
- Need copied debug output for order `9232` before designing final cache columns.
