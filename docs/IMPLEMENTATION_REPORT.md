# Implementation Report

## Current Status

Production `/db` authentication is live:

- Auth works.
- CEO login works.
- CEO users page works.
- Logout works.
- Production `setup_key` was disabled manually.
- Production `setup-ceo.php` was manually deleted.

This update redesigns Money Dashboard v0.3 so selected-month sales metrics and all-month unpaid receivables are visible on the first dashboard screen.

## Files Changed

- `README.md`
- `assets/app.css`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/KNOWN_ISSUES.md`
- `docs/NEXT_STEPS.md`
- `index.php`

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

## Money Dashboard v0.3

`index.php` now reads only from `db_orders`.

Important business rule:

- selected month controls sales plan/fact metrics
- all unpaid client debt is shown across all months

Optional simple filter:

```text
?month=YYYY-MM
```

Dashboard metrics:

- monthly target: `4,000,000 UAH`
- selected-month sales fact: `SUM(total_amount_uah)`
- selected-month paid: `SUM(paid_amount_uah)`
- selected-month unpaid: `SUM(unpaid_amount_uah)`
- selected-month order count: `COUNT(*)`
- total receivables / `Нам повинні всього` across all months
- unpaid receivables count across all months
- largest unpaid order across all months
- remaining to target
- progress percent
- daily required sales until selected month end, or `month closed` for past months
- last successful sync time
- all-month receivables table, 25 rows per page using `debt_page`
- top 10 unpaid orders for selected month
- selected-month manager summary

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

## Dashboard Access

Access remains unchanged for this milestone:

- `ceo`, `accountant`, and `manager` can view `index.php`.
- Only `ceo` sees `Sync Orders` and `Users` links.
- Manager-only filtering is not enabled yet because reliable local user-to-KeyCRM manager mapping still needs verification.

## What Remains Placeholder

- Planned outgoing payments.
- Debts/outgoing payments workflow.
- Charts.
- Automatic/cron sync.

No payments UI, outgoing payments UI, charts, automatic sync, sync logic changes, or database schema changes were added.

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
- Canceled/deleted exclusion is text-based and should be verified.
- Manager role currently sees the same dashboard data as accountant/CEO except CEO-only links are hidden; manager-specific filtering is open until mapping is confirmed.

## Recommendations

- Confirm GitHub Actions deploy completes after push.
- Confirm `https://bph.com.ua/db/sync_orders.php` opens only for CEO.
- Run manual sync and verify `db_sync_runs` summary.
- Confirm selected-month dashboard totals update from `db_orders`.
- Confirm total receivables include unpaid orders across all months.
- Confirm browser never calls KeyCRM directly.
- Run `php -l` on the server if available.

## Technical Debt

- No outgoing payment calculations exist.
- No payment event logic exists beyond cached KeyCRM order amounts.
- No dashboard audit or data freshness metadata exists.
- No login rate limiting exists.
- No automated PHP lint/test pipeline exists.
- Debug candidate detection is heuristic and exists only to guide schema design.
- Sync is manual only.

## Files To Review Manually

- `index.php`
- `assets/app.css`
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

1. Open `https://bph.com.ua/db/login.php`.
2. Log in as CEO.
3. Open dashboard.
4. Change `month=YYYY-MM` and confirm monthly sales/paid/unpaid/order count changes.
5. Confirm `Нам повинні всього` and receivables table include unpaid orders across all months.
6. Use `debt_page` pagination if more than 25 unpaid orders exist.
7. Confirm selected-month manager summary renders.
8. Confirm CEO sees `Sync Orders` and `Users`.
9. Confirm accountant/manager do not see `Sync Orders` or `Users`.
10. Confirm no schema changes were made by code.

## Risks / Open Questions

- GitHub Actions deploy status must be checked after push.
- PHP lint has not been run locally.
- Need to verify canceled/deleted statuses against real data.
- Need to verify manager mapping before filtering manager dashboard data.
- Need to decide whether cron sync is needed after manual sync is trusted.
