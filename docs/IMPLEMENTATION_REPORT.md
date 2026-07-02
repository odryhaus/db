# Implementation Report

## Current Status

Production `/db` authentication is live:

- Auth works.
- CEO login works.
- CEO users page works.
- Logout works.
- Production `setup_key` was disabled manually.
- Production `setup-ceo.php` was manually deleted.

This update adds CEO-only manual KeyCRM sync into `db_orders` and switches Money Dashboard to read local cache metrics.

## Files Changed

- `assets/app.css`
- `docs/CRM_SYNC_PLAN.md`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/KNOWN_ISSUES.md`
- `docs/NEXT_STEPS.md`
- `index.php`
- `sync_orders.php`
- `users.php`

## Manual KeyCRM Sync

Created:

```text
sync_orders.php
```

Access:

- Requires login.
- CEO-only.
- GET shows sync page and last 10 sync runs.
- POST runs sync.
- Uses CSRF token for POST.

Config:

- Uses `keycrm.base_url` from `config/config.php`.
- Uses `keycrm.api_key` from `config/config.php`.
- Does not expose the API key to the browser.

Scope:

- Current month.
- Previous month.
- No historical full sync.
- Bounded scan: `50` orders per page, max `20` pages.

KeyCRM include:

```text
products,products.offer,status,shipping,manager,buyer,company
```

If buyer/company include fails, sync retries without unsupported buyer/company includes and stores a warning in `db_sync_runs.error_message`.

Local writes:

- Upserts into `db_orders` by `keycrm_id`.
- Creates/updates a `db_sync_runs` record.
- Does not create tables or modify schema.

Monthly turnover rule:

- Uses `ordered_at`.
- Saves `order_month` as `YYYY-MM` from `ordered_at`.

Money mapping:

- `total_amount_uah = grand_total`
- `paid_amount_uah = payments_total`
- `unpaid_amount_uah = max(grand_total - payments_total, 0)`
- `products_total_uah = products_total`
- `expenses_sum_uah = expenses_sum`
- `margin_sum_uah = margin_sum`

Buyer/company extraction is best-effort until KeyCRM include support is fully confirmed.

## Money Dashboard Cache Read

`index.php` now reads from `db_orders` for the selected month.

Default:

- current month

Optional simple filter:

```text
?month=YYYY-MM
```

Dashboard metrics:

- monthly target: `4,000,000 UAH`
- sales fact: `SUM(total_amount_uah)`
- paid: `SUM(paid_amount_uah)`
- unpaid: `SUM(unpaid_amount_uah)`
- order count: `COUNT(*)`
- remaining to target
- progress percent
- last successful sync time
- top unpaid orders, latest 10

Canceled/deleted handling:

- Dashboard excludes rows where `status_name` or `payment_status` text contains common canceled/deleted markers.
- This is a first-pass rule and should be reviewed against real statuses.

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

## Money Dashboard

`index.php` now shows a money-focused dashboard after login and reads real metrics from local `db_orders` when sync data exists.

Visible content:

- Project name: `.BRAND DB`
- Logged-in user name/email
- User role
- Selected month
- Monthly target: `4,000,000 UAH`
- Sales fact from `SUM(total_amount_uah)`
- Paid from `SUM(paid_amount_uah)`
- Unpaid from `SUM(unpaid_amount_uah)`
- Order count from `COUNT(*)`
- We owe: `0 UAH` placeholder
- Remaining to target
- Progress percent
- Last successful sync time
- Top 10 unpaid orders
- Main next action: `Review synced order data`

Access remains unchanged:

- `ceo`, `accountant`, and `manager` can view `index.php`.
- Only `ceo` sees the `users.php` link.

## What Remains Placeholder

- We owe.
- Planned outgoing payments.
- Debts/outgoing payments workflow.
- Charts.
- Automatic/cron sync.

No payments UI, debts UI, charts, automatic sync, or database schema changes were added.

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
- Old local `orders` table is outdated and ignored.
- Buyer/company include support may vary; sync retries without it.
- Canceled/deleted exclusion is text-based and should be verified.

## Recommendations

- Confirm GitHub Actions deploy completes after push.
- Confirm `https://bph.com.ua/db/sync_orders.php` opens only for CEO.
- Run manual sync and verify `db_sync_runs` summary.
- Confirm dashboard totals update from `db_orders`.
- Confirm browser never calls KeyCRM directly.
- Run `php -l` on the server if available.

## Technical Debt

- `We owe` is still placeholder.
- No payment/debt calculations exist.
- No dashboard audit or data freshness metadata exists.
- No login rate limiting exists.
- No automated PHP lint/test pipeline exists.
- Debug candidate detection is heuristic and exists only to guide schema design.
- Sync is manual only.

## Files To Review Manually

- `sync_orders.php`
- `index.php`
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
4. Open `https://bph.com.ua/db/sync_orders.php`.
5. Run manual sync.
6. Confirm sync summary shows status, month range, orders seen, and orders upserted.
7. Open dashboard.
8. Confirm current month metrics read from `db_orders`.
9. Confirm CEO sees `Sync Orders` link.
10. Confirm manager/accountant do not see `Sync Orders`.
11. Confirm no schema changes were made by code.

## Risks / Open Questions

- GitHub Actions deploy status must be checked after push.
- PHP lint has not been run locally.
- Need to verify KeyCRM pagination order is descending by recent orders.
- Need to verify canceled/deleted statuses against real data.
- Need to verify buyer/company fields from real sync results.
- Need to decide whether cron sync is needed after manual sync is trusted.
