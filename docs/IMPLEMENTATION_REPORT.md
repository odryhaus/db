# Implementation Report

## 2026-07-18 — Historical Queue Cleanup And Targeted Backfill Tick

### Files Changed

- `sync_core.php`
- `history_sync.php`
- documentation files

### What Changed

- Added a targeted worker helper for one historical order backfill job only.
- `Імпорт історії → Обробити 1 історичний місяць` now processes only `orders_backfill_*` jobs.
- Added `Очистити queued історію`, which deletes only queued `orders_backfill_*` jobs.
- Running, success, failed and partial jobs are not deleted.
- Added half-year quick ranges so history can be re-queued in smaller parts.
- Added queue summary counts for historical jobs: queued, running, success, failed.

### Problem Found

The previous manual button used the general worker. If old `buyers`, `companies`, or other sync jobs were queued earlier, the button could process those first. That is why the page reported `buyers: success` instead of an historical order month.

### Health Formula

Client `Health` is a transparent rule-based score, not AI:

- recent order activity adds the largest part of the score;
- more active months add frequency points;
- all-time value segment adds monetary points;
- growth/new/returned signals add a small bonus;
- falling/sleeping signals subtract points;
- receivables subtract points based on debt pressure.

## 2026-07-18 — Client Health Guide And Backfill Queue Control

### Files Changed

- `client_balances.php`
- `history_sync.php`
- `assets/app.css`
- documentation files

### What Changed

- Moved the client work guide from the bottom of `Клієнти` to the top.
- Added a compact explanation of `Health`:
  - `75-100`: healthy
  - `50-74`: watch
  - `30-49`: risk
  - `0-29`: cold
- `VIP / ключові / основні / стартові` now always use all-time purchases.
- The `Цінність` switch still changes the displayed period revenue, but does not change the lifetime segment.
- Added a CEO-only `Обробити 1 історичний місяць` button to `Імпорт історії` so historical queued jobs can be manually tested from the page.

### Operational Note

If history jobs remain `queued`, the import has not downloaded those months yet. Cron should process `cron/sync_worker.php`; the manual button is only a diagnostic/fallback for one job at a time.

## 2026-07-18 — Historical Backfill Clarification

### Files Changed

- `history_sync.php`
- documentation files

### What Changed

- `Імпорт історії` now clearly explains that dashboard `Оновити все` is a delta/recent refresh, not a full historical import.
- The page shows the selected month count and configured backfill month limit.
- The page documents `.BRAND` full-history start as `2022-07`.
- The backfill success message now shows both queued months and requested months.

### Why January 2026 Was Confusing

Some older documentation mentioned loading missing 2026 data from `2026-01`. That was only for the first dashboard validation pass, not the full .BRAND database. Full historical order import should start from `2022-07`.

### Operational Note

If months stay in `queued`, the data was not downloaded yet. It means jobs were created and must be processed by `cron/sync_worker.php` or the web tick fallback while the cockpit is open.

## 2026-07-18 — Client Monthly Health Signals

### Files Changed

- `client_balances.php`
- `assets/app.css`
- documentation files

### What Changed

- `Клієнти` now uses selected month navigation instead of three-month quarter blocks.
- Old `quarter=YYYY-MM` links still work as compatibility input, but new links use `month=YYYY-MM`.
- Trend signals were split into clearer monthly rules:
  - `новий`: first known order is in the selected month.
  - `повернувся`: had older orders, skipped the previous month, and bought again in the selected month.
  - `росте`: three-month sales sequence grows month by month.
  - `падає`: previous month had sales, selected month has no sales.
  - `спить`: no sales for selected month and previous two months, but the client bought before.
  - `активний`: bought in the selected month but is not new/returned/growing.
  - `немає руху`: no useful movement in the available data.
- Added a `Цінність` switch: all time, last 12 months, selected month.
- Added segment filters based on all-time purchases:
  - `VIP`: 2,000,000 UAH+
  - `ключовий`: 1,000,000-1,999,999 UAH
  - `основний`: 250,000-999,999 UAH
  - `стартовий`: under 250,000 UAH
- Added a first transparent customer-health score:
  - recency of last order;
  - active months in the selected display period;
  - purchase value segment;
  - growth/return trend;
  - receivables penalty.
- Client names now open `sales.php` for the same selected month and client.
- Kept the compact row design from the latest Claude pass.

### Product Rationale

The client page now follows a simpler RFM/customer-health direction: recency, purchase trend, lifetime value, debt, and manager ownership. It is not a full CRM; it is a fast warning screen for who needs attention now.

### What Was Not Implemented

- No database schema changes.
- No client notes/follow-up dates yet.
- No automated churn prediction; health is a transparent rule-based score.
- No manager reassignment UI yet.

## 2026-07-18 — Client Command Center Quarter View

### Files Changed

- `client_balances.php`
- `sales.php`
- `history_sync.php`
- `sync_core.php`
- `config/config.example.php`
- `assets/app.css`
- documentation files

### What Changed

- `Клієнти` now works as a client command center instead of a month-only table.
- Removed the selected-month input from client search; search now applies across the cached client/order base.
- Added quarter navigation in three-month blocks starting from January: Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec.
- Company names are now clickable and open `sales.php` filtered to that client for the selected quarter.
- Added manager filter to the client page.
- Added client trend signals:
  - `↑ росте`
  - `↓ падає`
  - `спить`
  - `новий/повернувся`
  - `стабільно`
- `sales.php` now supports `from_month`, `to_month`, `client_key`, and `q` filters.
- Historical backfill month limit changed from hardcoded 24 months to config value `keycrm.sync_backfill_month_limit`, default `72`.
- `history_sync.php` now defaults to `2022-07` and explains that queued monthly jobs require cron/worker processing.
- Fixed zero-sales clients: if current and previous quarter are both `0`, the client now shows `немає руху`, not `стабільно`.
- Simplified the client and sales layouts: fewer nested boxes, more text/number rows, black/yellow/gray accents, red reserved for debt.

### Product Rationale

Modern client work should focus on leading indicators, not only past totals: growth, decline, dormancy, debt and responsible manager. This follows the same direction as current B2B retention/customer-success practices: customer health signals, proactive account management, and next-best-action workflows.

References reviewed:

- Gartner notes growing investment in AI/customer-service technology by 2028.
- McKinsey describes AI-powered next-best-experience as a way to decide what a customer needs now.
- Current B2B retention guidance emphasizes leading indicators and health scores to act before churn.

### What Was Not Implemented

- No database schema changes.
- No local manager reassignment workflow yet.
- No client profile page yet.
- No automatic churn prediction model yet.

### Risks / Open Questions

- Manager reassignment should use local company/contact ownership fields, not overwrite KeyCRM manager data.
- If many historical jobs stay `queued`, production cron/worker must be checked.
- Client trend logic is a simple quarter-over-quarter signal; it should later become a real health score with notes and follow-up dates.

## 2026-07-17 — Professional Cockpit Layout Pass

### Files Changed

- `cockpit_layout.php`
- `sales.php`
- `invoices.php`
- `assets/app.css`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/NEXT_STEPS.md`
- `docs/PAGE_AUDIT.md`

### Problems Found From PDF Review

- Pages had too little top spacing because the shared shell had no top padding.
- The `Адмін` dropdown used native `details`, which felt awkward and did not behave like a modern app menu.
- `sales.php` mixed orders and product lines in one table, making the page visually noisy.
- `invoices.php` registry was still a wide table; long payer names and action buttons were pushed out of view.
- Some labels were too technical for CEO work, for example `Gross margin` on the sales page.

### What Changed

- Replaced the admin dropdown with a controlled `Ще` popover that closes on outside click or Escape.
- Added consistent top breathing room for all app/page shells.
- Reworked Sales into order cards with money metrics and compact product rows inside each order.
- Reworked Invoice Registry into professional working cards: number/date, payer/contact, amount/seller, payment status, deadline, files, document status, and actions.
- Kept the same database and POST behavior; this is a layout/UX pass only.

### Verification

- Rendered the provided PDF exports to PNG and reviewed the visual issues.
- `git diff --check` passed.
- Local `php -l` could not be run because this environment has no PHP binary.

## 2026-07-17 — Client Balance Search And Page Audit

### Files Changed

- `client_balances.php`
- `docs/PAGE_AUDIT.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/NEXT_STEPS.md`

### Problem Found

`client_balances.php` search depended on individual SQL columns and could return an empty page even when the company existed in cached order/client data. SQL errors were also hidden behind the same `Даних немає` empty state.

### What Changed

- Client balance search now builds one combined text haystack from company, buyer, contact, manager, order number, and raw order JSON where available.
- Search now matches all entered words, so partial company/contact phrases work better.
- SQL failures are logged and shown as a visible page error instead of looking like no data exists.
- Added `docs/PAGE_AUDIT.md` with page-by-page goals, current problems, and recommendations.

### Recommendation

Next product cleanup should create one client profile page and keep daily navigation focused on `Cockpit`, `Клієнти`, `Продажі`, `Гроші`, and `Документи`.

## 2026-07-17 — Expense Page Polish

### Files Changed

- `expenses.php`
- `assets/app.css`
- `docs/IMPLEMENTATION_REPORT.md`

### What Changed

- Centered the `+` control geometrically inside the circle.
- Made month/status/type filter controls the same width and height.
- Removed the redundant `Поточний фільтр` panel.
- Made the upcoming payments panel full-width and cleaner.
- Replaced the English `Edit` action with `Редагувати`.

## 2026-07-17 — Collapsible Expense Form

### Files Changed

- `expenses.php`
- `assets/app.css`
- `docs/IMPLEMENTATION_REPORT.md`

### What Changed

- Expense add/edit form is collapsed by default.
- Added a large modern `+` control to open the form.
- The form opens automatically when editing an existing expense or when a save error is shown.
- Expenses page now prioritizes KPIs, filters, and the journal instead of a large always-open form.

## 2026-07-17 — Paid Expenses Visibility And Balance

### Files Changed

- `expenses.php`
- `index.php`
- `docs/IMPLEMENTATION_REPORT.md`

### Problem Found

Paid expenses were saved, but the expenses page defaulted to the `planned` filter, so paid rows were hidden by default. Paid expenses also did not have a clear monthly total in the balance view.

### What Changed

- Expenses journal now defaults to `all` statuses.
- If an expense is saved with status `paid` and no paid amount, `paid_amount_uah` is set to the expense amount.
- Expenses page now shows `Оплачено за місяць`.
- Main dashboard `Ми повинні` block now shows `Вже оплачено цього місяця`.

### Note

Until a dedicated `paid_at` field exists, paid-month reporting uses `due_date`; if `due_date` is empty, it uses `updated_at`.

## 2026-07-17 — Expense Save Visibility Fix

### Files Changed

- `expenses.php`
- `docs/IMPLEMENTATION_REPORT.md`

### Problem Found

After saving an expense, the page could still show the default `planned` filter. If the saved expense had another status or scope, it looked like the record was not saved.

### What Changed

- After add/edit, the page redirects to `status=all&scope=all`.
- Added visible success messages after saving and marking paid.
- Added required amount field.
- If database save fails, the page now shows an explicit error instead of silently looking unchanged.

## 2026-07-17 — Sync UX Cleanup

### Files Changed

- `index.php`
- `invoices.php`
- `targets.php`
- `users.php`
- `expenses.php`
- `sync_core.php`
- `config/config.example.php`
- documentation files

### What Changed

- Daily UI now has one main refresh control: `Оновити все` on the dashboard.
- Removed old `Sync` / `Клієнти Sync` links from daily navigation.
- Technical sync pages remain available by direct URL for admin/debug use, but they are not part of the normal CEO workflow.
- Dashboard sync status now shows text like `Синхронізація: оновлюється, задач: N` instead of making the refresh button look stuck.
- Reduced web fallback unpaid-order batch size from 500 to 50 so the site stays responsive if cron is not configured.

### Recommendation

Configure cron for real near-online updates. The browser fallback is only a safety net.

## 2026-07-17 — Sync Button Stuck Active Fix

### Files Changed

- `sync_core.php`
- `index.php`
- `config/config.example.php`
- documentation files

### Problem Found

The dashboard button became disabled whenever any sync job stayed `queued` or `running`. If a worker request timed out or cron was not fully configured, the button could remain inactive even after visible data refreshed.

### What Changed

- The CEO button is no longer hard-disabled.
- While sync is active, the button text changes to `Оновлюється...`, but it remains clickable.
- Stale running sync jobs are auto-recovered after `keycrm.sync_job_timeout_minutes` minutes.
- Stuck parent `global_refresh` jobs are closed as `partial` if child jobs are no longer active.

## 2026-07-17 — Sync Debt Refresh Fix

### Files Changed

- `sync_core.php`
- `index.php`
- `api/sync_tick.php`
- `config/config.example.php`
- documentation files

### Problem Found

The CEO button `Оновити все` queued sync jobs, but if production cron was not configured or not running yet, queued jobs could stay unprocessed.

Also, payment rows could be cached in `db_order_payments` without recalculating `db_orders.paid_amount_uah` and `db_orders.unpaid_amount_uah`, so an order could still appear as debt after payment data arrived.

### What Changed

- Added `unpaid_orders` refresh job to recheck currently unpaid local orders directly in KeyCRM.
- Recalculate `db_orders.paid_amount_uah`, `unpaid_amount_uah`, and `payment_status` after payment rows are saved.
- Added CEO-only `api/sync_tick.php` as a web fallback that processes one queued job while the dashboard is open.
- Dashboard polling now nudges one sync job when active jobs exist, so `Оновити все` can work even before cron is configured.

### Open Check

Production should still configure cron from `docs/CRON_SETUP.md`; the web tick is a fallback, not the preferred permanent worker.

## 2026-07-13 — Near Real-Time Sync Orchestration

### Files Changed

- `finance.php`
- `sync_core.php`
- `index.php`
- `assets/app.css`
- `config/config.example.php`
- `api/sync_status.php`
- `api/dashboard_kpis.php`
- `cron/sync_worker.php`
- `cron/enqueue_delta.php`
- `docs/SYNC_AUDIT.md`
- `docs/CRON_SETUP.md`
- `README.md`
- documentation files

### What Changed

- Added background sync job tables and local payment/expense cache tables.
- Added CEO dashboard button `Оновити все` that queues sync work and returns immediately.
- Added CLI worker for queued KeyCRM sync jobs.
- Added polling APIs for sync status and dashboard KPI refresh.
- Added local caches for KeyCRM order payments and order expenses.
- Confirmed from uploaded `open-api.yml` that `filter[updated_between]` is the documented delta filter.
- Confirmed no webhook endpoints are present in the uploaded OpenAPI file, so polling/cron is the safe path for now.

### Database Tables / Columns Added

- `db_sync_jobs`
- `db_order_payments`
- `db_order_expenses`
- `db_keycrm_statuses`
- `db_sync_state.last_source_updated_at`
- `db_sync_state.last_cursor`
- `db_sync_state.last_page`
- `db_orders.source_hash`

### How It Works

CEO clicks `Оновити все` on `index.php`. The app creates one parent `global_refresh` job and child jobs for orders, payments, companies, buyers, order expenses, and statuses.

Cron runs `cron/sync_worker.php`, claims one queued job, calls KeyCRM server-side, writes local cache tables, and records counts/errors in `db_sync_jobs`.

### Cron Commands

```sh
/usr/bin/php /home/qkbbstge/domains/bph.com.ua/public_html/public/db/cron/enqueue_delta.php
/usr/bin/php /home/qkbbstge/domains/bph.com.ua/public_html/public/db/cron/sync_worker.php
```

### What Was Not Implemented

- No KeyCRM webhook endpoint, because webhook support was not found in `open-api.yml`.
- No browser-side KeyCRM calls.
- No automatic full historical backfill.
- No KeyCRM write-back.
- No charts.

### Risks / Open Questions

- Production must verify that KeyCRM honors `filter[updated_between]` for orders, buyers, and companies.
- Payment status values must be reviewed before `db_order_payments` becomes the only paid-money source.
- Payment/expense arrays must be checked for stable IDs; fallback hash IDs are used when KeyCRM omits IDs.
- Hosting PHP path may differ from `/usr/bin/php`.

## 2026-07-03 — Invoice Workflow And Legal Entities

### Files Changed

- `finance.php`
- `invoices.php`
- `docs/DATABASE_PLAN.md`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/NEXT_STEPS.md`

### What Changed

- Separated invoice payment workflow from document workflow.
- Added additive invoice fields for `payment_status`, `document_status`, `payment_due_date`, and `document_due_date`.
- Kept legacy `status`, `docs_status`, and `expected_payment_date` for compatibility while moving UI to the new fields.
- Added additive client/company/contact fields needed for clearer legal recipient logic.
- Added `item_type` to invoice items as the foundation for future product/service document splitting.
- Updated invoice creation to keep buyer/contact separate from recipient/payer.
- Updated invoice edit UX with compact seller, client company, contact, and legal entity controls.
- Updated invoice registry with separate payment and document status dropdowns.
- Simplified invoice editing by removing status controls and document-generation buttons from the edit form.
- Moved registry work into one compact table: document downloads, edit, payment status, payment deadline, and delete are handled from `Реєстр рахунків`.
- New invoices now enter `Очікуємо оплату` immediately with a default payment deadline instead of exposing `Чернетка` in the registry.
- Added row deletion through a CSRF-protected `×` button.

### Business Rules Reflected

- Seller is `.BRAND` legal entity from `db_our_companies`.
- Buyer/contact is a person, not the legal payer.
- Recipient/payer is the client legal entity.
- One client company can have many contacts and many legal entities.
- Invoice number remains the KeyCRM order number.
- FOP 2 group sellers remain products-only and no-VAT.

### What Was Not Implemented

- VAT invoices.
- Service seller workflow for FOP 3 group.
- KeyCRM file attachment.
- Full audit log for status changes.
- Full accounting ledger.

### Manual Test

1. Open `https://bph.com.ua/db/invoices.php`.
2. Create/open an invoice.
3. Confirm seller is shown as `Від кого рахунок`.
4. Confirm client company, contact, and legal recipient are separate.
5. Change payment status and document status independently in the registry.
6. Set payment deadline and confirm overdue behavior.
7. Confirm the edit form only saves/edits invoice data and closes back to the registry.
8. Confirm PDF download buttons are available in the registry `Docs` column and delete is available in the action column.

## 2026-07-08 — Invoice Recipient Snapshots

### Files Changed

- `finance.php`
- `invoices.php`
- `assets/app.css`
- `docs/DATABASE_PLAN.md`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/NEXT_STEPS.md`
- `docs/KNOWN_ISSUES.md`

### What Changed

- `db_invoices` now uses recipient/contact snapshot fields as the primary invoice data.
- Invoice creation no longer writes fake `Покупець` values when KeyCRM does not provide a legal payer.
- Invoice edit UI now separates seller, client group, contact person, legal payer, and document data.
- Legal entities can be saved/updated locally and optionally set as default for a client company.
- Invoice items keep source product name, SKU, offer id, and original product JSON for audit.
- Generated documents store `document_number`; downloads increment `download_count` and `last_downloaded_at`.
- Registry shows document buttons, recipient/contact, payment deadline, document status, and action controls.

### Business Rules Reflected

- Seller is our legal entity.
- Recipient/payer is the client legal entity.
- Buyer/contact is a person, not the payer.
- Local legal entities are `.BRAND DB` data and must not be overwritten by KeyCRM sync.
- FOP 2 group sellers stay products-only, no VAT, with collapsed title `Поліграфічна продукція`.

### Manual Test

1. Create an invoice from a real `db_orders.keycrm_id`.
2. Confirm empty payer shows `немає платника`, not `Покупець`.
3. Edit payer/contact fields and save.
4. Save/update legal entity and set it as default.
5. Create another invoice for the same client and confirm default legal entity is offered.
6. Generate/download invoice, delivery note, and act PDFs and confirm names `INV_`, `DN_`, `ACT_`.

## 2026-07-08 — Minimal Invoice Edit Form

### What Changed

- Simplified invoice edit form to the daily fields only:
  - seller company
  - client company
  - recipient legal name
  - contact person
  - email
  - phone
  - document type
  - document number
  - invoice date
- Removed visible edit fields for short name, EDRPOU, tax number, payer email, payer phone, legal address, payment due date, document due date, docs type, and separate document date.
- Kept those removed fields as hidden compatibility values so saving a simple edit does not accidentally erase existing invoice data.
- Added working `Docs` buttons in the invoice registry for invoice PDF, delivery note, and act. If a PDF does not exist yet, the button generates it and downloads it, so `Docs` no longer stops at `немає`.

### Database Cleanup Notes

Do not drop columns yet. These fields are hidden from the main UI but may still be useful for PDF history, future automation, or imported legal details:

- `recipient_short_name`
- `recipient_edrpou`
- `recipient_tax_number`
- `recipient_legal_address`
- `recipient_email`
- `recipient_phone`
- `payment_due_date`
- `document_due_date`
- `docs_type`

Recommended later approach: keep them in the database, but move them to an optional advanced/details panel only if the CEO/accountant needs them.

## 2026-07-08 — Client Sync And Fast Local Search

### Files Changed

- `clients_sync.php`
- `ajax_client_search.php`
- `finance.php`
- `invoices.php`
- `assets/app.css`
- `config/config.example.php`
- `index.php`
- `sync_orders.php`
- documentation files

### What Changed

- Added `db_sync_state` and `db_client_sync_runs`.
- Added CEO-only `clients_sync.php` for manual companies/buyers sync.
- Added bounded delta sync buttons and explicit initial import buttons.
- Added local-only `ajax_client_search.php` endpoint for company, contact, and legal entity autocomplete.
- Replaced invoice company dropdown with fast local autocomplete; no large client list is rendered into the browser.
- Added indexes for local company/contact/legal entity search.

### Safety Rules

- KeyCRM token stays server-side.
- Browser never searches KeyCRM directly.
- Local legal entities are not overwritten by KeyCRM sync.
- Local manager assignment fields are not overwritten by KeyCRM sync.
- Delta sync tries updated-after parameters and still uses bounded page limits as fallback.

### Hotfix

- Fixed production MySQL error `1089 Incorrect prefix key` caused by creating `idx_email` as `email(191)` while the column is `VARCHAR(190)`.
- Made safe index creation best-effort: if a non-critical index cannot be added on a legacy production column, the error is logged instead of breaking `invoices.php`.

## 2026-07-08 — Our Companies And Payment Requisites

### Files Changed

- `finance.php`
- `invoices.php`
- `index.php`
- `our_companies.php`
- `payment_requisites.php`
- documentation files

### What Changed

- Expanded `db_our_companies` for seller legal entity structure.
- Added `db_our_company_accounts` for bank/card/payment requisites.
- Seeded initial .BRAND FOP/TOV/PP seller entities and UAH/EUR/USD/Mono accounts.
- Added CEO/accountant view page `our_companies.php`; CEO can edit companies/accounts.
- Added `payment_requisites.php` for copyable payment text by order/amount/company/account.
- Added `db_invoices.seller_account_id` and invoice account selector.
- Invoice PDFs now read seller bank details from selected/default company account with legacy fallback.

### Not Implemented

- VAT 20% PDF templates.
- English EUR/USD invoice templates.
- KeyCRM payment writes.
- Payment creation/accounting ledger.

## 2026-07-09 — Seller Companies / Payment Requisites Verification

### Pages Checked

- `our_companies.php` existed before this task.
- `payment_requisites.php` existed before this task.
- `invoices.php`, `finance.php`, and `assets/app.css` existed before this task.

### Files Changed

- `finance.php`
- `invoices.php`
- `our_companies.php`
- `payment_requisites.php`
- documentation files

### What Changed

- Allowed CEO and accountant roles to edit `our_companies.php`.
- Kept managers on `payment_requisites.php` only; managers cannot edit seller company/account data.
- Added visible `language` and `note` fields to seller account rows.
- `payment_requisites.php` now filters accounts by selected company and currency.
- Active account lists used by invoices/requisites now exclude accounts with empty IBAN.
- Invoice selected/default account lookup now requires active account + matching currency + non-empty IBAN.
- Invoice edit shows explicit warnings for VAT sellers and English/non-UAH account templates.

### Database Changes

- No new table was created.
- No destructive schema change was made.
- Existing safe column `db_invoices.seller_account_id` remains the invoice/account link.

### Still Not Implemented

- VAT 20% PDF templates.
- English EUR/USD invoice PDF templates.
- KeyCRM payment writes or file attachments.

## 2026-07-10 — Seller Account Duplicate Cleanup Guard

### Problem Found

- Production can contain duplicate seller account rows with the same `company_id + currency + IBAN` but different labels, for example `EUR ПриватБанк` and `ПриватБанк EUR`.
- This made invoice account dropdowns look duplicated.

### What Changed

- Seed logic now matches seller accounts by `company_id + currency + IBAN`, not by `account_label`.
- Invoice/payment account dropdowns deduplicate active non-empty-IBAN accounts before rendering.
- Account labels no longer repeat currency twice in dropdown text.
- `our_companies.php` still shows all raw account rows and marks duplicate IBAN rows with `дубль IBAN` for manual review.

### Manual Duplicate Check SQL

```sql
SELECT
    c.short_name,
    a.company_id,
    a.currency,
    REPLACE(UPPER(TRIM(a.iban)), ' ', '') AS normalized_iban,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(a.id ORDER BY a.id) AS account_ids,
    GROUP_CONCAT(a.account_label ORDER BY a.id SEPARATOR ' | ') AS labels
FROM db_our_company_accounts a
LEFT JOIN db_our_companies c ON c.id = a.company_id
WHERE a.iban IS NOT NULL
  AND TRIM(a.iban) <> ''
GROUP BY a.company_id, a.currency, normalized_iban
HAVING COUNT(*) > 1
ORDER BY c.short_name, a.currency;
```

Do not delete duplicates blindly. First verify which row is used by existing invoices through `db_invoices.seller_account_id`.

## 2026-07-10 — Invoice Client/Contact Selection Fix

### Problem Found

- Invoice edit field `Компанія` could show the payer legal name instead of the short client company/group name.
- Contact autocomplete was too narrow when an invoice already had a selected company, so existing buyers could be hard to find.
- Selecting a contact did not fill email/phone automatically.
- The UI had backend support for saving a contact, but no visible compact button near contact fields.

### What Changed

- Client company labels now prefer KeyCRM/local short company name over legal title.
- New invoice snapshots store local company `display_name` from short company name when available.
- Contact autocomplete searches all matching contacts and ranks contacts from the selected company first.
- Contact autocomplete returns and fills contact email/phone.
- Added `Зберегти контакт` button on invoice edit.
- Saving a selected contact updates/links it to the current client company; manually typed contacts create a new local contact.

### Business Rule

- `Компанія` = client company/group short name.
- `Повна назва юрособи-платника` = legal payer name for invoice/PDF.
- `Контактна особа` = buyer/contact person; many contacts can belong to one company.

## 2026-07-13 — Seller Company Duplicate Guard

### Problem Found

- The old auth/invoice seed could create a legacy seller row `FOP Darchenko A.B.` while the newer seller structure also has `ФОП Дарченко А.Б.` with the same tax code.
- This made invoice seller dropdowns show the same seller twice in Ukrainian/English variants.

### What Changed

- Legacy seed no longer inserts `FOP Darchenko A.B.` if any seller with tax code `3032919108` already exists.
- Active seller lists used by invoices and payment requisites deduplicate companies by `tax_code`/`edrpou`.
- If duplicate active sellers exist, the Ukrainian/default/current row is preferred for working dropdowns.
- `our_companies.php` still shows all raw seller rows and marks same-tax-code rows with `дубль компанії` for manual review.

### Safety

- No seller company rows were deleted.
- No invoice seller references were rewritten.

## 2026-07-13 — Seller Rule Completion

### What Changed

- Invoice account selectors now show only active UAH accounts with non-empty IBAN, because only UAH no-VAT PDF templates are implemented.
- `Акт` is hidden from invoice edit and registry generation for sellers with `allowed_item_type = products_only`.
- Server-side generation also rejects `act` for products-only sellers, so the UI rule cannot be bypassed with POST.

### Still Future Scope

- English EUR/USD invoice PDF templates.
- VAT 20% invoice PDF templates.
- Mixed product/service document routing for service-enabled sellers.

## 2026-07-17 — CEO Money Cockpit v2 Preview

### What Changed

- Added `dashboard_v2.php` as a preview page. The current production `index.php` was not replaced.
- Added shared Cockpit formulas in `cockpit.php`.
- Added local dashboard JSON endpoints under `api/`.
- Expanded `db_order_payments` safely with individual payment snapshot fields.
- Added CEO-only `payment_sync_check.php` for order/payment reconciliation.
- Added `db_payment_obligations` as the future outgoing payment model.
- Added `db_monthly_costs` as the profitability foundation.
- Created audit/plan/validation docs for Cockpit v2.

### Problems Found

- Current `index.php` mixes selected-month sales paid amount with cash received by payment date when `db_order_payments` has rows.
- `db_expenses` works as a legacy expense page, but it is too narrow for full outgoing payment obligations.
- True KeyCRM webhook support was not found in the uploaded OpenAPI file.

### Recommendations

- Validate individual payment rows on production before final chart design.
- Keep `dashboard_v2.php` as preview until CEO explicitly approves replacing `index.php`.
- Use `db_payment_obligations` for new outgoing payment work; keep `db_expenses` only as legacy data.

### Manual Review

- Open `/payment_sync_check.php?order=9124` and compare active payment totals.
- Review `docs/CEO_COCKPIT_AUDIT.md`.
- Review `docs/PAYMENT_DATA_AUDIT.md`.
- Review `docs/CEO_COCKPIT_VALIDATION.md`.

## 2026-07-17 — CEO Money Cockpit v2 Financial Pages

### What Changed

- Added read-only drill-down pages:
  - `sales.php`
  - `cash.php`
  - `receivables.php`
  - `managers.php`
  - `payments.php`
  - `accounts.php`
- Added shared helpers:
  - `financial.php`
  - `cockpit_layout.php`
- Extended `sync_core.php` to cache order products into `db_order_items` from KeyCRM order payloads.
- Extended payment sync to mirror active paid KeyCRM payments into `db_financial_transactions`.
- Added account allocation lookup through `db_keycrm_payment_method_accounts` -> `db_financial_accounts`.
- Updated Cockpit v2 formulas to use strict active payments: `status='paid' AND is_deleted=0`.
- Removed `ensure_finance_tables()` / `ensure_sync_tables()` calls from Cockpit v2 pages and API paths so this stage does not execute schema creation or alteration from the new UI.

### What Now Syncs

- Orders: existing `db_orders` logic remains.
- Products: `db_order_items`, with soft delete when a product disappears from the fresh order payload.
- Payments: `db_order_payments`, with strict active paid logic.
- Financial income operations: `db_financial_transactions` with `source_type='keycrm_payment'`.

### Current Formulas

- Sales: `db_orders.order_month = selected month`.
- Cash received: `db_order_payments.payment_date` in selected month, `status='paid'`, `is_deleted=0`.
- Receivables: all `db_orders.unpaid_amount_uah > 0` across all months.
- Operating profit: `gross_margin - completed operating expense transactions`; if categorization is not available, Cockpit shows diagnostic status instead of pretending the number is final.
- Cash forecast: `current financial account balance + receivables - operational obligations this month`.

### Needs Manual Production Validation

- Order products are saved without duplicates and removed products become `is_deleted=1`.
- One KeyCRM payment creates exactly one `db_financial_transactions` row by `source_type + source_id`.
- Unmapped payment methods appear as `allocation_status='needs_review'`.
- Canceled payments become canceled financial operations and do not affect balances.
- `index.php` still works and remains the production dashboard.

## 2026-07-17 — Cockpit UX And Invoice Registry Polish

### What Changed

- `receivables.php` now has compact segmented filters for payment status and manager.
- Cockpit v2 manager progress now shows a black/yellow bar:
  - black = sales fact progress toward plan
  - yellow = paid share inside that fact
- Dashboard performance hero now also shows how much of selected-month sales is paid.
- Manager fact/count/unpaid values link to filtered sales/receivables pages.
- `Payment Sync Check` was removed from main navigation and remains only as a technical CEO URL.
- `invoices.php` creation form is collapsed behind a compact “+ Додати рахунок” panel.
- Invoice registry now shows `Пакет` when PDF documents exist for an invoice; it downloads a ZIP package of available PDFs.
- Payment sync now backfills `db_financial_transactions` even when an existing KeyCRM payment row is unchanged.

### How To Refresh Empty Operations

Open Cockpit v2 and click `Оновити все`, or let cron run `cron/sync_worker.php`.

Reason: old payments already saved in `db_order_payments` need one more sync pass to backfill `db_financial_transactions`.

## 2026-07-17 — Navigation And Sales Item Detail Polish

### What Changed

- Unified Cockpit v2 navigation across:
  - `dashboard_v2.php`
  - `sales.php`
  - `cash.php`
  - `receivables.php`
  - `managers.php`
  - `payments.php`
  - `accounts.php`
  - `invoices.php`
  - `expenses.php`
- Renamed financial accounts page label from `Рахунки` to `Баланси` to avoid confusion with invoice documents.
- Kept invoice documents page as `Рахунки`.
- Added `Витрати` to the shared navigation, pointing to the existing `expenses.php`.
- Shortened manager table label from `Оплачено в замовленнях` to `Оплачено`.
- Added more visual spacing around black/yellow progress bars.
- Added order item details under sales rows from `db_order_items`:
  - name
  - properties
  - comment
  - quantity/unit
  - sale price
  - discount
  - total
  - purchase price only for CEO/accountant

### Still Not Fully Done From The Large Task

- Full pagination for all large detail pages is still not implemented.
- `sales.php` has partial filters; full manager/client/payment-status/order-status filter set is not complete.
- `cash.php` does not yet have all requested filters.
- `receivables.php` has status and manager filters, but not all aging/sum/search filters yet.
- `payments.php` is a read-only journal; manual expense transaction editing is not implemented.
- Expenses remain on `expenses.php`; they are not yet fully migrated into `db_financial_transactions`.

## 2026-07-17 — Client Balance Report

### What Changed

- Added `client_balances.php` as a read-only CEO Money Cockpit detail page.
- Added shared navigation item `Клієнти`.
- The page can group balances by client company or buyer/contact.
- The page supports selected month, search, and a six-month mini history per row.
- The report shows selected-month sales fact, paid amount from order totals, cash received by payment date, and current receivable across all months.

### Data Rules

- Sales use `db_orders.order_month`.
- Cash received uses `db_order_payments.payment_date`.
- Receivables use all `db_orders.unpaid_amount_uah > 0` across all months.
- Data is read only from local cached tables.
- No KeyCRM browser calls were added.
- No database schema changes were added.

### Open Validation

- Confirm company grouping with real rows where KeyCRM has company id.
- Confirm buyer grouping with real rows where KeyCRM has buyer id.
- Confirm cash received matches the `Гроші` page for the same selected month.

## 2026-07-17 — Historical Orders Backfill

### What Changed

- Added CEO-only `history_sync.php`.
- Added shared navigation item `Імпорт історії` for CEO.
- Added queued historical order jobs named `orders_backfill_YYYY_MM`.
- Historical jobs import orders month-by-month using KeyCRM `filter[created_between]`, which KeyCRM documents as filtering orders by `ordered_at`.
- Backfill jobs cache:
  - orders
  - payments
  - order products/items
  - order expenses

### How To Load Full .BRAND History

1. Open `history_sync.php`.
2. Set `З місяця = 2022-07`.
3. Set `По місяць = current month`.
4. Click `Дозавантажити`.
5. Let cron run `cron/sync_worker.php`, or keep Cockpit open so web ticks process queued jobs.

### Why This Was Needed

- Daily `Оновити все` is a delta refresh, optimized for recent changes.
- It does not automatically scan old months if old orders were not recently updated.
- Historical backfill is intentionally explicit and month-bounded so the dashboard stays fast.

## 2026-07-17 — Client Balance Search And Grouping Polish

### What Changed

- Reworked `client_balances.php` into one company-first list.
- Removed the separate company/buyer mode from the working UI.
- Buyers/contacts now appear inside the company row.
- Company totals aggregate all buyers under the company.
- Search now checks available local order, company, contact, email, phone, order number, and manager fields.
- Rows sort by total purchases descending, then selected-month sales.

### Data Rules

- Company is the primary balance owner.
- Buyer/contact is detail inside the company.
- `Закупки всього` uses all cached non-canceled orders.
- `Факт` uses the selected month.
- `Гроші` uses selected-month payment dates.

## 2026-07-17 — Cockpit v3 Navigation And Action Queue

### What Changed

- Simplified the shared Cockpit navigation to five daily sections:
  - Cockpit
  - Клієнти
  - Продажі
  - Гроші
  - Документи
- Moved secondary/technical pages under `Адмін`.
- Added an action-first `Потрібна дія` section to `dashboard_v2.php`.
- Action queue currently surfaces:
  - largest client receivables
  - overdue/upcoming outgoing obligations or expenses
  - invoice payment deadlines
  - unallocated financial operations
  - failed sync jobs

### Product Direction

- The system should guide CEO attention instead of exposing every table in the main menu.
- Technical sync pages remain available but are not part of the daily workflow.
- This is the first step toward CEO Money Cockpit v3: fewer entry points, more decision support.
