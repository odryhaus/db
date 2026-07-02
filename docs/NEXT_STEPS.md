# Next Steps

## Deploy Test Checklist

Production auth is already live. Use this checklist after each deploy:

Checklist:

- GitHub repository secrets exist: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
- GitHub Actions workflow finishes successfully.
- `https://bph.com.ua/db/login.php` loads the login form.
- `https://bph.com.ua/db/config/config.php` is not publicly readable.
- `https://bph.com.ua/db/setup-ceo.php` is not available.
- Invalid login shows only a generic error.
- CEO user can log in.
- CEO can open `https://bph.com.ua/db/users.php`.
- CEO, accountant, and manager can open the protected money dashboard.
- Manager/accountant users cannot see `users.php` or `sync_orders.php` links.
- Dashboard selected-month metrics update when `?month=YYYY-MM` changes.
- `Нам повинні` shows unpaid client debt across all months.
- Receivables pagination keeps the selected month parameter.
- CEO can open `targets.php` and save monthly/manager targets.
- CEO/accountant can open `expenses.php`.
- Dashboard shows operational monthly expenses and strategic debt separately.
- Logout redirects back to login.

## Production Config Setup Reminder

`config/config.php` is ignored by Git and excluded from deploy. Do not commit or overwrite production config.

Required production app setting:

```php
'base_path' => '/db',
```

The old setup key was disabled manually in production and should remain disabled.

## Current Dashboard

Money Dashboard reads sales only from `db_orders`.

Rules:

- Selected month controls sales plan/fact metrics.
- Total receivables / `Нам повинні` shows all unpaid orders across all months.
- Monthly target comes from `db_sales_targets`, with `4,000,000 UAH` fallback.
- Manager plan/fact comes from `db_sales_targets` plus `db_orders`.
- Targets are effective from date and remain active until changed.
- Expense KPIs come from `db_expenses`.
- Strategic debt is shown separately from monthly operational pressure.
- Old `orders` table is ignored.
- No browser KeyCRM calls are made.
- No charts exist yet.

## Next Milestone

Money Dashboard v0.5 — verify targets, expenses, manager mapping, and payment status rules.

Planning document:

```text
docs/CRM_SYNC_PLAN.md
```

Inspection page:

```text
https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232
```

The next milestone should:

- Run CEO manual sync for current and previous month.
- Verify `ordered_at` month assignment.
- Verify totals against KeyCRM for sample orders.
- Verify buyer/company extraction.
- Decide final canceled/deleted status exclusion rules.
- Decide whether cron/automatic sync is needed.
- Confirm whether managers can be safely filtered by local `users.keycrm_id`.
- Review whether finance tables should be created by manual SQL instead of app bootstrap.
- Add expense recurrence expansion if monthly subscriptions need concrete due rows.
- Add data freshness display and sync error handling improvements if needed.

The next dashboard should add or improve:

- Reliable manager-only data filtering.
- Clear canceled/deleted/refunded status rules.
- Better payment status labels if KeyCRM values are too technical.
- Expense payment audit/history if needed.

## UI Follow-ups (After Compact Dashboard Redesign)

This environment has no `php` binary or database access, so the redesign was reviewed by careful re-reading only, not exercised in a browser. Before treating the redesign as final:

- Run it locally (`php -S localhost:8000`) or on staging and click through: month switch, receivables pagination, manager debt drilldown filter, targets save, expenses add/edit/filter/paid, users search/role filter, users `?all=1` toggle, sync run button.
- Check the sticky topbar and sticky receivables-table header on an actual long scroll — `position: sticky` behavior can differ slightly across browsers when nested inside `overflow` containers.
- Verify the payment status badge logic (`Оплачено` / `Частково` / `Не оплачено`, computed from `paid = total − unpaid`) against a few real KeyCRM orders — it intentionally ignores the raw `payment_status` string because those values were inconsistent, but that should be confirmed against production data.
- Have a Ukrainian-speaking reviewer sanity-check the interface copy (labels, empty states, nav) added across `index.php`, `targets.php`, `expenses.php`, `users.php`, `sync_orders.php`.
- Consider adding client-side search/filter to the receivables table (like the one added to `users.php`) if the CEO wants to scan it without paging.
- Charts are still explicitly out of scope; revisit only if the CEO asks for trend visuals beyond the compact KPI/progress-bar view.

## Database Tables To Inspect Next

Inspect existing tables related to:

- CRM users/managers.
- Orders.
- Clients.
- Payments.
- Order payment statuses.
- Any existing local CRM sync/cache tables.

Suggested manual SQL:

```sql
SHOW TABLES;

SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'qkbbstge_dashboard'
  AND (
    TABLE_NAME LIKE '%order%'
    OR TABLE_NAME LIKE '%payment%'
    OR TABLE_NAME LIKE '%client%'
    OR TABLE_NAME LIKE '%invoice%'
    OR TABLE_NAME LIKE '%debt%'
  );
```

For each relevant table:

```sql
SHOW CREATE TABLE table_name;
```

## CRM / Order Logic Needed Later

Later CRM work should identify:

- KeyCRM endpoint for orders.
- Fields for order number, client, manager, total amount, paid amount, unpaid amount, and status.
- How CRM manager IDs map to local `users.keycrm_id`.
- Which order date defines the selected month.
- Which statuses count toward turnover.
- How refunds, cancelled orders, partial payments, and currency are represented.

The first import should cover only current and previous month, not all history.
