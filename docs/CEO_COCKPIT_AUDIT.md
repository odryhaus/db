# CEO Money Cockpit — Code Audit

Date: 2026-07-17

## Current dashboard source

The current production dashboard is `index.php`. It reads from local database tables only. It does not call KeyCRM from the browser.

## Current KPI formulas in `index.php`

Sales month:
- `db_orders.order_month = selected month`
- `order_month` is derived from `db_orders.ordered_at`

Sales fact:
- `SUM(db_orders.total_amount_uah)`
- canceled/deleted statuses are excluded by text filters on `status_name` and `payment_status`

Order count:
- `COUNT(*)` from `db_orders` for selected month

Paid:
- First calculated as `SUM(db_orders.paid_amount_uah)` for selected month orders.
- Then, if `db_order_payments` exists and selected-month payment sum is greater than zero, `index.php` replaces paid with `SUM(db_order_payments.amount)` by `payment_date`.
- This mixes two concepts: paid part of selected-month sales and cash received during selected month.

Unpaid for selected month:
- First calculated as `SUM(db_orders.unpaid_amount_uah)`.
- If payment-month sum replaces paid, unpaid is recalculated as `sales_fact - paid`.
- This can be misleading when old orders are paid in the selected month.

Receivables:
- `SUM(db_orders.unpaid_amount_uah)` where `unpaid_amount_uah > 0` across all months.
- This is the correct business direction for "Нам повинні".

Manager summary:
- grouped by `db_orders.manager_name`
- selected month only
- sales = `SUM(total_amount_uah)`
- paid = `SUM(paid_amount_uah)`
- unpaid = `SUM(unpaid_amount_uah)`
- manager targets come from `db_sales_targets`

Expenses / outgoing payments:
- current pages use legacy `db_expenses`
- operational due this month = planned, non-strategic rows due this month, plus monthly subscriptions
- strategic debt = strategic rows in `db_expenses`
- this is being kept as legacy data; new outgoing payment logic should use `db_payment_obligations`

Net cash:
- current `index.php` uses `receivables_total - operational_due_this_month`
- this is a useful pressure indicator, but not true cashflow because it does not include current bank cash or probability/timing of receipts.

Margin:
- sync stores `db_orders.expenses_sum_uah` and `db_orders.margin_sum_uah`
- current main dashboard does not clearly separate gross margin and operating profit.

## Existing sync logic

`sync_core.php` is the current professional sync foundation:
- queues jobs in `db_sync_jobs`
- stores state in `db_sync_state`
- worker is `cron/sync_worker.php`
- "Оновити все" queues one parent global job and child jobs
- order sync endpoint is `order` with includes `buyer,products.offer,status,manager,payments,expenses`
- delta filter uses `filter[updated_between]`
- unpaid orders refresh rechecks local unpaid orders by direct order detail endpoint

`sync_orders.php` is the older manual sync page. It still exists and should not be the main future path.

## KeyCRM payment source

The official local OpenAPI file shows:
- `/order` supports `include=payments`
- `/order/{orderId}` supports `include=payments`
- `/order/{orderId}/payment` exists for payment mutation, not as the main payment list source
- `/order/payment-method` exists

Therefore individual payments should be cached from order payloads with `include=payments`.

## Performance risks

- Loading many CRM pages directly is slow; browser must never call KeyCRM.
- Dashboard queries should read local aggregate/cache tables only.
- Old paid formula in `index.php` mixes order-month and payment-month logic.
- Large receivables tables on the main dashboard should become drill-down pages.
- `db_expenses` is legacy and should not be expanded into a full ERP model.

## Components to keep

- Auth and roles
- Users management
- Sales targets
- Seller companies and payment requisites
- Invoice drafts/PDFs
- Local client companies/contacts/legal entities
- Background sync queue
- Current production `index.php` until CEO explicitly approves replacing it
