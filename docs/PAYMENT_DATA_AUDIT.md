# Payment Data Audit

Date: 2026-07-17

## Current problem

Money received must be calculated by payment date, not by order date.

The selected month has two separate meanings:
- sales performance: `db_orders.order_month`
- cash receipts: `db_order_payments.payment_date`

These must not be mixed in the same KPI.

## Implemented payment cache direction

`db_order_payments` is the local cache for individual KeyCRM payments.

It now supports:
- payment identity: `keycrm_payment_id`
- order identity: `keycrm_order_id`, `order_number`
- amount/currency/status/date
- payment method
- future seller mapping placeholders: `seller_company_id`, `seller_account_id`
- source timestamps/hash/raw JSON
- soft deletion flags: `is_deleted`, `deleted_at`

## Active payment rule

A payment counts as received if:
- `is_deleted = 0`
- status does not contain canceled/deleted/скас/refund/returned/failed/error

The sync recalculates:
- `db_orders.paid_amount_uah`
- `db_orders.unpaid_amount_uah`
- `db_orders.payment_status`

## Validation tool

CEO-only page:

`/payment_sync_check.php`

It checks one order by order number or KeyCRM ID and displays:
- order amount
- order month
- db_orders paid/unpaid
- individual local payment rows
- active payment total
- calculated unpaid
- difference
- validation status

## Manual validation needed

After deploy, validate several orders:
- one fully paid order
- one partially paid order
- one unpaid order
- one order paid in a later month
- one order that was recently updated in KeyCRM

If discrepancies exist, copy the page result back before changing dashboard formulas further.
