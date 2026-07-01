# Next Steps

## Next Milestone

Monthly Sales Dashboard.

The next milestone should show:

- Selected month.
- Total order amount for the month.
- Paid amount.
- Unpaid amount.
- Number of orders.
- Progress toward 4,000,000 UAH.
- List of unpaid orders.

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
