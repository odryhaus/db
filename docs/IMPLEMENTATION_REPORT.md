# Implementation Report

## Current Status

Production `/db` authentication is live:

- Auth works.
- CEO login works.
- CEO users page works.
- Logout works.
- Production `setup_key` was disabled manually.
- Production `setup-ceo.php` was manually deleted.

This update adds sales targets, manager targets, receivables drilldown, and an expenses foundation while keeping KeyCRM sync unchanged.

## Files Changed

- `README.md`
- `assets/app.css`
- `bootstrap.php`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/KNOWN_ISSUES.md`
- `docs/NEXT_STEPS.md`
- `expenses.php`
- `finance.php`
- `index.php`
- `sync_orders.php`
- `targets.php`
- `users.php`

## UI Redesign — Compact Money Dashboard

This pass is UI/UX only. No database schema changed, no KeyCRM sync logic changed, no access rules changed.

### Design system (`assets/app.css`)

- Rewrote the token set: `--bg`, `--panel`, `--surface-muted`, `--line`/`--line-strong`, `--text`/`--muted`/`--faint`, one near-black `--accent`, and three semantic status colors (`--danger`, `--warning`, `--success`) each with a soft background + border variant for badges only — never large color fills.
- Added a spacing scale (`--space-1`…`--space-6`), radius scale, and a `--row-h: 40px` table density token.
- Added reusable component classes requested in the design brief: `.app-shell`, `.panel-header`, `.data-table`, `.status-badge` (+ `--success`/`--warning`/`--danger`/`--muted` modifiers), `.progress-bar`/`.progress-mini`, `.toolbar`, `.form-control`, `.button-primary`, `.button-secondary`, `.split-grid`.
- Kept every pre-existing class name (`.panel`, `.kpi-grid`, `.section-heading`, `.table-panel`, `.compact-table`, `.plan-list`, `.debug-*`, `.button.secondary`, etc.) so page-specific markup can reuse the same primitives safely.
- Made `.topbar`/`.dashboard-header` `position: sticky; top: 0` with a solid `--bg` background and bottom border, so the header stays visible while scrolling long tables without a full-bleed layout rework.
- Table `<thead>` is `position: sticky` inside `.table-wrap`; the receivables table additionally uses `.table-scroll` (max-height + overflow) so its own header stays pinned during a long scroll.

### `index.php` (full restructure, same PHP data logic)

- KPI strip now shows exactly the 7 cards from the brief: План, Факт, Оплачено, Не оплачено за місяць, Нам повинні всього, Ми повинні цього місяця, Прогрес. Strategic debt was moved out of the KPI strip into the "Ми повинні" block so it doesn't visually compete with monthly operational cash pressure.
- Manager performance table columns reordered to Менеджер / План / Факт / % (mini progress bar) / Оплачено / Борг / Залишилось / Замовлень.
- Receivables ("Нам повинні") is one panel: header summary (total, count, largest), manager drilldown table, then the paginated (25/page) order table with a `.table-scroll` pinned header. Rows use a payment status badge computed from `paid = total − unpaid` (Оплачено / Частково / Не оплачено) instead of raw KeyCRM `payment_status` strings, which are inconsistent; the raw `status_name` (order status) is shown as a plain muted badge since its values aren't reliably classifiable.
- Added two small read-only aggregates to the existing `db_expenses` try-block, mirroring the pattern already used for `operationalDueThisMonth`/`strategicDebtTotal`: `operationalDueThisWeek` (planned, operational, due this ISO week) and `overdueTotal`/`overdueCount` (planned, operational, `due_date < today`). These power the "Ми повинні" block's "платежі цього тижня" / "прострочені платежі" cards from the design brief. No new tables, no write logic — same scope and shape as the existing operational query.
- All interface copy switched to Ukrainian (headers, buttons, empty states, nav).
- `dashboard_manager_key()` now also normalizes the SQL-side `'No manager'` fallback label to `Без менеджера` for display, while the underlying filter URLs still use the raw value so `?debt_manager=` filtering keeps working unchanged.

### `users.php` (light cleanup)

- Compact single "Ім'я" column instead of separate first/last name columns, Ukrainian labels, `.toolbar` search input + role `<select>` filtered entirely client-side with ~15 lines of vanilla JS (no framework, no build step).
- Server-side default changed to show only `db_active = 1` users, with a `?all=1` link to show everyone — this is a `WHERE` clause addition, not a change to how access/roles are evaluated.

### `sync_orders.php` (light cleanup)

- Ukrainian labels, run status rendered as a `.status-badge` (success/failed/warning), numeric columns right-aligned. No change to the sync request/response handling.

### Follow-up UI pass

- `targets.php` now follows the dashboard design system: sticky topbar, active nav, KPI strip, month toolbar, compact monthly target panel, manager target table, numeric alignment, and mini progress bars.
- `expenses.php` now follows the dashboard design system: sticky topbar, active nav, KPI strip, filter toolbar, compact expense form, upcoming payments table, filter summary panel, expense register with status/type badges and scrollable table.
- `keycrm_debug_order.php` — internal debug tool, not part of the CEO-facing design brief; `.debug-*` classes were re-tokenized but not restructured.

### Testing limitation

No `php` binary or database credentials are available in this environment (see Problems Found), so this pass could not be exercised in a live browser. Everything was reviewed by re-reading the full diff of each changed file for tag balance, PHP syntax, and class-name consistency against the new stylesheet. Manual browser verification is required before treating this as done — see `docs/NEXT_STEPS.md`.

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

## Money Dashboard

`index.php` now reads only from `db_orders`.

Important business rule:

- selected month controls sales plan/fact metrics
- all unpaid client debt is shown across all months

Optional simple filter:

```text
?month=YYYY-MM
```

Dashboard metrics:

- monthly target from `db_monthly_targets`, fallback `4,000,000 UAH`
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
- receivables manager filter using `debt_manager`
- receivables summary by manager
- top 10 unpaid orders for selected month
- selected-month manager summary with target, fact, paid, unpaid, remaining, and progress
- operational expenses due this month
- strategic debt total, shown separately

Canceled/deleted handling:

- Dashboard excludes rows where `status_name` or `payment_status` text contains common canceled/deleted markers.
- This is a first-pass rule and should be reviewed against real statuses.

Unpaid logic:

- Unpaid lists use `unpaid_amount_uah > 0`.
- This includes both unpaid and partially paid orders if the cached unpaid amount is positive.
- Lists do not rely only on `payment_status`.

## Target Management

Created:

```text
targets.php
```

Access:

- CEO-only.

Tables created only if missing:

- `db_monthly_targets`
- `db_manager_targets`

Behavior:

- Choose month.
- Edit total monthly target.
- Show managers found in `db_orders` for selected month.
- Save manager targets.

## Expenses Foundation

Created:

```text
expenses.php
```

Access:

- CEO and accountant.

Table created only if missing:

- `db_expenses`

Behavior:

- Add expense.
- Edit expense.
- Mark expense as paid.
- Filter by status.
- Filter operational / strategic.
- Show upcoming payments.
- Show monthly planned operational expenses.
- Show strategic debt separately.

Dashboard expense KPIs:

- `Ми повинні цього місяця`: planned operational expenses due in selected month.
- `Стратегічні борги`: strategic debt total, not mixed into monthly cash pressure.

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
- Only `ceo` sees `Targets`, `Sync Orders`, and `Users` links.
- CEO and accountant can access `expenses.php`.
- Manager-only filtering is not enabled yet because reliable local user-to-KeyCRM manager mapping still needs verification.

## What Remains Placeholder

- Charts.
- Automatic/cron sync.
- Full expense recurrence automation.
- Expense payment history/audit log.

No charts, automatic sync, KeyCRM sync logic changes, browser KeyCRM calls, or destructive database changes were added.

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
- Additive tables are created by the app with `CREATE TABLE IF NOT EXISTS`.

## Recommendations

- Confirm GitHub Actions deploy completes after push.
- Confirm `https://bph.com.ua/db/sync_orders.php` opens only for CEO.
- Run manual sync and verify `db_sync_runs` summary.
- Confirm selected-month dashboard totals update from `db_orders`.
- Confirm total receivables include unpaid orders across all months.
- Confirm `targets.php` saves monthly and manager targets.
- Confirm `expenses.php` can add, edit, filter, and mark expenses paid.
- Confirm strategic debts are not included in monthly operational pressure.
- Confirm browser never calls KeyCRM directly.
- Run `php -l` on the server if available.

## Technical Debt

- Expense foundation exists, but no full payment history or recurrence expansion exists.
- No payment event logic exists beyond cached KeyCRM order amounts.
- No dashboard audit or data freshness metadata exists.
- No login rate limiting exists.
- No automated PHP lint/test pipeline exists.
- Debug candidate detection is heuristic and exists only to guide schema design.
- Sync is manual only.

## Files To Review Manually

- `index.php`
- `targets.php`
- `expenses.php`
- `finance.php`
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
5. Open `targets.php`, save a monthly target and manager targets, then confirm dashboard uses them.
6. Confirm `Нам повинні всього` and receivables table include unpaid orders across all months.
7. Click manager names in receivables drilldown and confirm `debt_manager` filters the receivables table.
8. Open `expenses.php`, add an operational expense and a strategic debt.
9. Confirm dashboard shows `Ми повинні цього місяця` and `Стратегічні борги` separately.
10. Confirm CEO sees `Targets`, `Expenses`, `Sync Orders`, and `Users`.
11. Confirm accountant can access `Expenses` but not CEO-only pages.
12. Confirm manager does not see CEO/accountant management links.

## Risks / Open Questions

- GitHub Actions deploy status must be checked after push.
- PHP lint has not been run locally.
- Need to verify canceled/deleted statuses against real data.
- Need to verify manager mapping before filtering manager dashboard data.
- Need to decide whether cron sync is needed after manual sync is trusted.
- Need to confirm whether additive table creation from web requests is acceptable long-term or should move to reviewed SQL setup.
