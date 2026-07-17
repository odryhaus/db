# Implementation Report

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
