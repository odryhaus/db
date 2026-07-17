# KeyCRM Sync Audit

## Current Production Pattern

`sync_orders.php` is a CEO-only manual browser sync. It fetches KeyCRM orders for the current and previous month, writes `db_orders`, and logs the run in `db_sync_runs`.

`clients_sync.php` is a CEO-only manual browser sync for companies and buyers. It writes local `db_client_companies` and `db_client_contacts`, and stores sync timestamps in `db_sync_state`.

Both pages are useful, but both run inside the browser request. That is why large syncs can feel slow or hang.

## KeyCRM Endpoints Confirmed From `open-api.yml`

- `/order`
- `/buyer`
- `/companies`
- `/order/status`
- `/order/product-status`
- `/order/payment-method`
- `/order/expense-type`

For `/order`, the documented include values are:

`buyer, products.offer, manager, tags, status, marketing, payments, shipping.lastHistory, shipping.deliveryService, expenses, custom_fields, assigned`

For `/buyer`, `include=company` is documented.

For `/companies`, company listing is documented.

## Delta Filters

The uploaded `open-api.yml` shows `filter[updated_between]` examples for orders, buyers, and companies.

Decision:

- Use `filter[updated_between]=from,to`.
- Add a 120 second overlap to avoid missing records updated near the boundary.
- Keep bounded page limits as fallback protection.

Runtime verification is still required on production because this task did not call KeyCRM.

## Webhooks

The uploaded `open-api.yml` does not expose webhook, subscription, callback, or event endpoints.

Decision:

- Do not create a webhook endpoint yet.
- Use polling with cron.
- Revisit webhooks only if KeyCRM provides separate webhook documentation or account settings.

## Slow Points Found

- Old dashboard/browser sync loads too much CRM data in one request.
- Orders, payments, and expenses were not separated into dedicated local cache tables.
- Paid money for a selected month could not be reliably calculated from real payment rows.
- No background queue existed, so the CEO had to wait for sync in the browser.

## New Direction

Use one CEO button `Оновити все` to enqueue background jobs:

- orders
- unpaid orders recheck
- payments
- companies
- buyers
- order expenses
- statuses

`cron/sync_worker.php` processes one queued job at a time. The browser only polls status.

If cron is not configured yet, `api/sync_tick.php` can process one queued job while the CEO dashboard is open. This is a fallback so the button does not appear broken; cron remains the preferred production runner.

## Open Checks

- Confirm production KeyCRM accepts `filter[updated_between]` exactly as documented.
- Confirm `payments` and `expenses` arrays contain stable IDs.
- Confirm payment statuses that should count as real received money.
- Confirm whether `payment_date` or another field is the accounting date for “Грошей надійшло”.
