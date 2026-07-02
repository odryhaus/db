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
- Manager/accountant users cannot see or open `users.php`.
- Logout redirects back to login.

## Production Config Setup Reminder

`config/config.php` is ignored by Git and excluded from deploy. Do not commit or overwrite production config.

Required production app setting:

```php
'base_path' => '/db',
```

The old setup key was disabled manually in production and should remain disabled.

## Current Dashboard

Money Dashboard v0.1 reads current-month metrics from `db_orders` after CEO manual sync. It shows the 4,000,000 UAH monthly target, synced sales/paid/unpaid/order count, and latest unpaid orders.

## Next Milestone

Money Dashboard v0.2 — verify synced data quality and refine real monthly sales from `db_orders`.

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
- Add data freshness display and sync error handling improvements if needed.

The next dashboard should show:

- Selected month.
- Total order amount for the month.
- Paid amount.
- Unpaid amount.
- Number of orders.
- Progress toward 4,000,000 UAH.
- List of unpaid orders.
- Managers if available.

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
