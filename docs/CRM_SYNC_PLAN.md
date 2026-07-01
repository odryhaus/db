# CRM Sync Plan For Money Dashboard v0.2

## 1. What Old Brand Dashboard Does

The old `/brand-dashboard` is a production workflow dashboard. It is not a money dashboard.

Observed behavior from the old files:

- `index.html` is a browser-heavy single page.
- Browser JavaScript calls `proxy.php` directly.
- `proxy.php` forwards requests to KeyCRM.
- Orders are fetched from KeyCRM with `endpoint=order`.
- Includes used for order loading:

  ```text
  products,products.offer,status,shipping,manager
  ```

- The browser also fetches dictionaries:

  ```text
  order/product-status
  order/status
  order/statuses fallback
  ```

- The browser stores production planning dates locally through proxy actions:

  ```text
  action=saveDate
  action=getDates
  ```

- The proxy can update KeyCRM order/product statuses:

  ```text
  action=updateOrderStatus
  action=updateProductStatus
  ```

- `ordersDelta` attempts to use `updated_after`; if unsupported, it falls back to scanning the first few order pages and filtering by `updated_at`.
- `load.php` and `save.php` are older/local production-parameter helpers for `dates` and `production_params`.

Important: old files include production credentials/API access patterns. Do not copy those secrets into `.BRAND DB`.

## 2. Why It Is Slow

The old dashboard is slow because the browser is responsible for loading live CRM data.

Main causes:

- On load, browser fetches page 1 of KeyCRM orders, then continues loading more pages in the background.
- Each page can request up to `100` orders with nested includes.
- The old UI can keep many orders in browser memory.
- The browser also preloads SQL production dates for all loaded order products.
- Auto-refresh calls `ordersDelta` every 20-60 seconds.
- If `updated_after` is unsupported or unreliable, proxy fallback scans several full order pages.
- The UI waits on network/API behavior outside the local app's control.

This design is acceptable for a production task board, but it is too slow and fragile for a CEO money dashboard.

## 3. KeyCRM Endpoints And Includes Needed

Minimum endpoints inferred from old dashboard:

```text
GET /order
GET /order/status
GET /order/statuses
GET /order/product-status
```

Potential delta endpoint behavior:

```text
GET /order?updated_after=ISO_DATE
```

Current known include set:

```text
products,products.offer,status,shipping,manager
```

For Money Dashboard v0.2, confirm whether the order endpoint returns payment/money fields without extra includes. If not, identify the correct KeyCRM include or endpoint for:

- order total
- paid amount
- unpaid amount
- payment status
- client/customer
- manager
- order date
- updated date

Do not call KeyCRM from browser. Only server-side sync code should call KeyCRM.

## 4. Fields Needed For Money Dashboard

For each order in current and previous month:

- `crm_order_id`
- `order_number` or display number
- `order_date`
- `created_at`
- `updated_at`
- `closed_at` or completion date if relevant
- `status_id`
- `status_name`
- `payment_status` if available
- `manager_id`
- `manager_name`
- `client_id`
- `client_name`
- `total_amount_uah`
- `paid_amount_uah`
- `unpaid_amount_uah`
- `currency`
- `source_updated_at`
- raw CRM JSON for audit/debug

For dashboard calculations:

- selected month
- order count
- total revenue
- paid revenue
- unpaid revenue
- progress toward `4,000,000 UAH`
- unpaid orders list
- manager breakdown if manager data is available

Open question: KeyCRM field names for total/paid/unpaid must be verified from a real order JSON sample. Do not assume final field names from memory.

## Order JSON Inspection

Before finalizing `db_orders` / cache table design, inspect a real KeyCRM order with the CEO-only debug page:

```text
https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232
```

The debug page is read-only:

- It requires login.
- It is CEO-only.
- It calls KeyCRM server-side.
- It does not expose the API key to the browser.
- It does not write to the database.

Copy back these sections for architecture review:

- top-level JSON keys
- detected order id/number
- created/updated dates
- manager id/name
- client/customer/buyer id/name
- total amount candidates
- paid amount candidates
- unpaid amount candidates
- payment status candidates
- currency candidates
- raw JSON if it does not contain sensitive customer data beyond what is needed for schema design

Use this inspection result to decide final cache fields. Do not create `db_orders` until the real money/payment field names are confirmed.

## 5. Proposed Local Cache Table Structure

Do not use the existing outdated `orders` table for the new dashboard unless its structure is verified and intentionally migrated later.

Recommended additive cache tables:

### `crm_order_cache`

Purpose: local fast monthly order cache.

Suggested columns:

```text
id
crm_order_id
order_number
order_date
order_month
status_id
status_name
payment_status
manager_id
manager_name
client_id
client_name
total_amount_uah
paid_amount_uah
unpaid_amount_uah
currency
source_created_at
source_updated_at
synced_at
raw_json
created_at
updated_at
```

Indexes:

```text
UNIQUE crm_order_id
INDEX order_month
INDEX source_updated_at
INDEX manager_id
INDEX unpaid_amount_uah
```

### `crm_sync_runs`

Purpose: track sync attempts and failures.

Suggested columns:

```text
id
sync_type
started_at
finished_at
status
month_from
month_to
orders_seen
orders_upserted
error_message
created_by_user_id
```

### Optional later: `crm_status_cache`

Purpose: cache status dictionaries if they are needed for labels/filtering.

Suggested columns:

```text
id
dictionary_type
crm_status_id
name
raw_json
synced_at
```

Important: these are proposed tables only. Do not create them until approved.

## 6. Proposed Sync Strategy

### Phase 1: Manual Sync Button

First implementation after approval:

- Add a CEO-only or admin-only manual sync action.
- Sync only current month and previous month.
- Run sync server-side in PHP.
- Store results in local cache.
- Dashboard reads only from local cache.
- Show last sync time and sync status.

This keeps the first implementation controllable and avoids hidden background behavior.

### Phase 2: Delta Sync

If KeyCRM supports `updated_after` reliably:

- Store last successful sync timestamp.
- Request only orders updated after that timestamp.
- Upsert changed orders into `crm_order_cache`.
- Keep a fallback manual full sync for current + previous month.

If `updated_after` is not reliable:

- Keep bounded sync by month/date filters if KeyCRM supports them.
- Otherwise scan a limited number of recent pages server-side and stop early when orders are outside the sync window.

### Phase 3: Automatic Sync

After manual sync is verified:

- Add cron or hosting scheduler.
- Suggested interval: every 10-30 minutes during work hours.
- Keep manual sync button for emergency refresh.
- Log all automatic sync attempts in `crm_sync_runs`.

## 7. How To Avoid Loading All CRM Orders In Browser

New rule:

```text
Browser never calls KeyCRM.
```

Instead:

- Browser opens `.BRAND DB`.
- PHP reads local `crm_order_cache`.
- Dashboard query filters by selected month.
- Unpaid list comes from local cache.
- Manual sync button calls a local PHP endpoint.
- That PHP endpoint calls KeyCRM server-side and upserts local cache.

Benefits:

- Fast dashboard load.
- Lower KeyCRM API pressure.
- No long browser hangs.
- No CRM token exposure to browser.
- Predictable monthly data scope.
- Easier debugging through sync logs.

## 8. Security Changes

Required:

- KeyCRM token must live only in `config/config.php`.
- KeyCRM base URL should also live in config.
- Never hardcode KeyCRM token in `proxy.php`, docs, JS, or Git.
- Do not commit production DB credentials.
- Do not expose a generic passthrough proxy to authenticated browser users unless strictly needed.
- Protect sync actions behind auth and role checks.
- Prefer `ceo`-only sync at first.
- Use prepared statements for all cache writes.
- Store raw JSON for debugging, but never expose it directly in public UI.
- Log sync errors without leaking token/Authorization headers.

Recommended config shape later:

```php
'keycrm' => [
    'base_url' => 'https://openapi.keycrm.app/v1',
    'api_key' => 'SET_IN_REAL_CONFIG_ONLY',
],
```

Do not add this to real config until implementation is approved.

## 9. Recommended First Implementation Task After Approval

Recommended first task:

1. Ask user to inspect current database tables and confirm whether a new cache table can be added.
2. Add approved additive cache tables.
3. Add server-side KeyCRM config placeholders to `config/config.example.php`.
4. Build a CLI/admin-only sync script for current + previous month.
5. Save raw order JSON and extracted money fields into `crm_order_cache`.
6. Update dashboard to read placeholder metrics from cache.

Do not start with UI polish. Start with reliable local data.

## 10. Risks / Open Questions

- Current `orders` table appears outdated; exact purpose and safety are unknown.
- Need a real KeyCRM order JSON sample to confirm money field names.
- Need to confirm KeyCRM supports date filters for order month.
- Need to confirm whether `updated_after` is supported and reliable.
- Need to confirm how paid/unpaid amounts are represented.
- Need to confirm currency behavior and whether all dashboard values are UAH.
- Need to decide which order statuses count toward monthly turnover.
- Need to decide whether cancelled/refunded orders are excluded.
- Need to confirm manager ID mapping to local `users.keycrm_id`.
- Need to know whether current month is based on order creation date, acceptance date, payment date, or completion date.
- Need to avoid overloading KeyCRM during initial sync.
- Need to ensure sync cannot be triggered repeatedly by non-CEO users.
