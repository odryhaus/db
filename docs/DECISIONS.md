# Decisions

## Plain PHP

Use simple PHP without Laravel, Node, Composer, or a build step.

Reason: the first milestone is authentication only, hosting appears to support PHP/LiteSpeed, and the project must stay fast and easy to deploy to `/db`.

## Existing Users Table

Use the existing `users` table and only the dashboard-specific auth fields:

- `email`
- `db_password_hash`
- `db_role`
- `db_active`
- `db_last_login_at`

Do not use:

- `username`
- `password`

Reason: those fields belong to another internal project/calculator.

## Safe Additive Schema Changes

Do not drop, rename, truncate, or overwrite existing tables.

Add only small project-owned tables when explicitly needed and create them with `CREATE TABLE IF NOT EXISTS`.

Reason: the production database already exists and must be handled conservatively.

## Session Authentication

Use PHP sessions with `password_hash()` / `password_verify()` and session ID regeneration after successful login.

Reason: this is the smallest safe authentication foundation for a protected internal PHP system.

## Roles

Use these roles:

- `none`
- `manager`
- `accountant`
- `ceo`

Only `ceo` can access user access management. `manager` and `accountant` can access the protected home page.

## Deployment

Deploy with GitHub Actions and FTP using repository secrets:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

Do not commit or deploy real credentials from Git.

Deploy target is fixed in the workflow:

```text
domains/bph.com.ua/public_html/public/db/
```

## Temporary CEO Setup Tool Removed

`setup-ceo.php` was a temporary one-time setup tool. It must not remain in production after the first CEO password is created.

Decision:

- Keep `setup-ceo.php` deleted from the repository.
- Keep `setup-ceo.php` excluded from deployment as a second guard.
- Do not ask production to restore it.
- Use the CEO users page for ongoing password and role management.

## Local Order Cache

CEO Money Cockpit must read from `db_orders`, not from the old `orders` table and not directly from KeyCRM.

Decision:

- Ignore the old `orders` table because it is outdated.
- Use `ordered_at` as the monthly turnover date.
- Store `order_month` as `YYYY-MM` from `ordered_at`.
- Keep KeyCRM calls server-side only.
- Start with CEO-only manual sync for current month and previous month.
- Do not build a full historical sync or ERP workflow.

## CEO Money Cockpit Metrics

Selected month controls monthly sales plan/fact metrics.

Decision:

- Monthly sales, paid, unpaid, order count, remaining target, progress, manager summary, and daily required sales use `order_month = YYYY-MM`.
- Total receivables / `Нам повинні` uses all unpaid orders across all months.
- Receivables are paginated at 25 rows per page.
- Old `orders` table remains ignored.
- No charts are added yet.
- Outgoing payment planning is additive and intentionally lightweight.
- Manager-specific filtering is deferred until KeyCRM manager mapping to local users is confirmed.

## CEO Money Cockpit

Decision:

- The main dashboard concept is named `CEO Money Cockpit`.
- The purpose is daily CEO control of monthly sales target, manager target/fact, receivables, unpaid/partially paid orders, outgoing obligations, operational cash pressure, strategic debts, and invoice/document status.
- The dashboard remains simple, fast, and money-focused.
- It is not an ERP and should not grow into one by default.

## Targets And Expenses Foundation

Use small additive tables for dashboard planning data.

Decision:

- Create `db_sales_targets` and `db_expenses` only if they do not already exist.
- Keep old `db_monthly_targets` and `db_manager_targets` if they exist, but do not use them as the source of truth.
- Use `db_sales_targets` for company and manager targets.
- Targets are effective from a date and remain active until a newer target row replaces them.
- For selected month `YYYY-MM`, use the latest target with `effective_from <= last day of selected month`.
- Use `4,000,000 UAH` company fallback if no company target exists.
- Use `0 UAH` manager fallback if no manager target exists.
- `db_expenses` was the first planned outgoing payments foundation.
- New outgoing-payment functionality should use `db_payment_obligations`.
- Keep `db_expenses` only as legacy/fallback if already created.
- Keep strategic debt separate from monthly operational cash pressure.
- CEO manages sales targets.
- CEO and accountant can manage expenses.
- Do not change KeyCRM sync logic.
- Do not call KeyCRM from the browser.

## Compact UI Design System

Redesign the interface into a calm, dense, executive dashboard (Linear/GitHub/Vercel/Notion-table style) without introducing a framework or build step.

Decision:

- One near-black `--accent` for primary actions/active nav state; color is reserved for semantic status badges (success/warning/danger), never for large fills or decoration.
- Reuse every existing CSS class name (`.panel`, `.kpi-grid`, `.section-heading`, `.table-panel`, `.plan-list`, etc.) rather than renaming them, so all PHP pages can share the same tokens and layout primitives.
- Add the specific reusable classes requested in the design brief (`.app-shell`, `.panel-header`, `.data-table`, `.status-badge`, `.toolbar`, `.form-control`, `.button-primary`, `.button-secondary`, `.split-grid`) as additive classes, not replacements.
- Sticky topbar and sticky table headers implemented with plain `position: sticky` inside the existing constrained-width container, avoiding a full-bleed header rework.
- Interface copy is Ukrainian throughout the pages touched in this pass (`index.php`, `users.php`, `sync_orders.php`).

Reason: the CEO needs a fast, scannable dashboard, not a new framework or a full-repo rewrite; reusing class names keeps the change low-risk and keeps untouched pages visually consistent for free.

## Two New Read-Only Expense Aggregates

Added `operationalDueThisWeek` and `overdueTotal`/`overdueCount` queries to `index.php`, scoped to `db_expenses` with `is_strategic = 0 AND expense_type <> 'strategic_debt'`, mirroring the existing `operationalDueThisMonth` query already in the file.

Decision:

- These are additive `SELECT` aggregates only — no new tables, no schema change, no write paths, no change to existing queries.
- Scoped to operational expenses only, so strategic debt still cannot leak into the weekly/overdue operational pressure view.

Reason: the design brief's "Ми повинні" block explicitly lists "платежі цього тижня" and "прострочені платежі" as required cards; the data was one read-only query away using the table that already exists (`db_expenses`), so it was safer to add the two aggregates than to ship a placeholder card wired to nothing.

## Targets / Expenses Use The Same UI System

Decision:

- `targets.php` and `expenses.php` should follow `index.php` as the design reference.
- Use the same sticky topbar, `.brand-block`, active `.nav` state, KPI cards, `.toolbar`, `.panel`, `.section-heading.padded`, `.table-panel`, `.table-scroll`, `.status-badge`, and `.progress-mini` patterns.
- Keep page-specific logic unchanged while making the presentation consistent.

Reason: the CEO-facing money system should feel like one compact internal product, not separate pages with separate layouts.

## Invoice Draft Foundation

Decision:

- Add `invoices.php` as a small finance document tool, not as a full accounting module.
- Create only project-owned additive tables: `db_our_companies`, `db_invoices`, `db_invoice_items`, `db_invoice_documents`, and local client snapshot tables.
- Invoice rows and item rows are editable local copies copied from `db_orders.raw_json`.
- Generated invoice, delivery note, and act files belong in `db_invoice_documents` so multiple documents can exist for one invoice/order.
- Payment workflow and document workflow are separate:
  - `payment_status` controls draft/waiting/paid/problem/canceled.
  - `document_status` controls not sent/sent/closed/problem.
- Do not change KeyCRM orders.
- Do not write payment statuses to KeyCRM.
- Do not attach generated files to KeyCRM in v0.1.
- Use no-VAT text for current documents: `Без ПДВ. Платник єдиного податку.`
- Keep VAT support out of scope until a VAT seller company is explicitly configured.
- For seller companies with `allowed_item_type = 'products_only'`, default collapsed item wording is `Поліграфічна продукція`; service wording is not generated.
- Services remain disabled until a FOP 3 group seller company with `allowed_item_type = 'services_allowed'` is configured and reviewed.
- Invoice number defaults to the KeyCRM order number, not an internal `INV-YYYYMM-0001` sequence.
- PDF file names use `INV_<invoice_number>.pdf` for invoices, `DN_<invoice_number>.pdf` for delivery notes, and `ACT_<invoice_number>.pdf` for acts.
- Buyer/contact is treated as the contact person.
- Company/legal entity is treated as the invoice recipient/payer.
- Multiple legal entities per client company are supported locally through `db_client_legal_entities`.
- Legal entities are built gradually from edited invoices.

Reason: the first invoice milestone should speed up document creation and payment control without turning .BRAND DB into ERP/accounting software.

## Payment Obligations

Decision:

- Outgoing payments are modeled as payment obligations, not just expenses.
- The planned source of truth for outgoing payments is `db_payment_obligations`.
- `db_expenses` can remain if already created, but new outgoing-payment functionality should use `db_payment_obligations`.
- KeyCRM order-level expenses are separate and should be cached in `db_order_expenses`.
- Use timeline groups instead of a classic month calendar: overdue, today, tomorrow, this week, next week, later, and strategic debts.
- No drag-and-drop in v1; use simple Paid, Move, and Edit actions.
- Order-linked obligations should support expected net cash calculation per order.

Reason: CEO needs to see who `.BRAND` must pay, when, whether it is linked to an order, and how it affects cash pressure without building full accounting software.

## Near Real-Time Sync

Decision:

- Use background sync jobs instead of long browser requests.
- CEO dashboard has one global button: `Оновити все`.
- A CEO click on `Оновити все` is the daily refresh control for changed orders, currently unpaid orders, payments, companies, buyers, order expenses, and statuses.
- Old manual sync pages are technical/admin fallback pages and should not appear in daily navigation.
- Jobs are stored in `db_sync_jobs` and processed by `cron/sync_worker.php`.
- Use KeyCRM `filter[updated_between]` with a 120 second overlap for delta sync.
- Keep bounded page limits as protection.
- Cache KeyCRM payments in `db_order_payments`.
- Cache KeyCRM order expenses in `db_order_expenses`.
- Do not create a webhook endpoint until KeyCRM webhook support is confirmed outside the uploaded OpenAPI file.
- Do not run all-history backfill automatically.

Reason: CEO needs fresh money data without waiting in the browser or risking a large uncontrolled CRM import.

## Client Balance Report

Decision:

- Add `client_balances.php` as a compact read-only cockpit page for company/buyer balances.
- Treat this as an operational client balance, not a formal бухгалтерський баланс.
- Allow switching between company grouping and buyer/contact grouping.
- Use selected month for sales fact and order paid amount.
- Use payment date for cash received in the selected month.
- Use all months for current receivables.
- Do not add schema changes or KeyCRM browser calls for this report.

## Historical Orders Backfill

Decision:

- Keep daily/global sync as fast delta refresh.
- Add explicit CEO-only historical order backfill by month.
- Use `orders_backfill_YYYY_MM` queue jobs instead of a long browser request.
- Use KeyCRM `filter[created_between]` for the selected month; KeyCRM documents that for orders this filter is applied to `ordered_at`.
- Do not automatically scan all history.
- Do not reset delta sync state when running historical backfill.

## Cockpit v3 Navigation And Action Queue

Decision:

- Treat `dashboard_v2.php` as the daily CEO command center.
- Keep the daily navigation to five product areas:
  - Cockpit
  - Клієнти
  - Продажі
  - Гроші
  - Документи
- Move technical and secondary pages into `Адмін`.
- Add `Потрібна дія` to the main Cockpit so CEO sees what needs action before drilling into tables.
- Action queue is read-only for now and links into the relevant existing page.
- Do not delete technical pages; hide them from the daily path.

## Dashboard Hero Dedup, Pacing, Aging, Client Debt (2026-07-03)

The CEO flagged that the KPI strip and the "План продажів" panel repeated the same План/Факт/Прогрес numbers twice, and asked for a graphical Факт → Оплачено/Не оплачено breakdown, debt aging, plan pacing, and a per-client negative-balance view she can turn into a statement to send.

Decision:

- Merge the KPI strip and the plan panel into one flow: the KPI strip keeps only the numbers that appear nowhere else (План, Факт, Оплачено, Не оплачено за місяць, Нам повинні всього, Ми повинні цього місяця); the panel below keeps only what the strip doesn't show (progress bar, a plain CSS stacked bar for paid/unpaid share of fact, remaining, daily-required, pacing).
- Pacing ("Темп") is `actual progress % − expected progress % (elapsed days / days in month)`, shown as a status badge on the company panel and per manager row. No chart library — a stacked bar is two `<span>`s with inline `width: N%`, same pattern as the existing `.progress-track`.
- Receivables aging uses `DATEDIFF(CURDATE(), ordered_at)` bucketed 0–7 / 8–30 / 30+ days, since `db_orders` has no separate payment-due date. This is a heuristic (age of the unpaid order, not a contractual due date) and should be revisited once `db_payment_obligations` exists.
- Added a "Клієнти з боргом" table grouped by `COALESCE(company_id, buyer_id, client_id, 0)` (falls back to a combined "Без клієнта" bucket when none of the three ids are set) — grouping by id instead of by display-name text to avoid merging different clients that happen to share a name.
- "Send to client" is implemented as a printable statement page (`index.php?client_statement=<id>`) with a Print button and a Copy-text button (`navigator.clipboard`). The app does not send anything on the CEO's behalf — no email/SMS sending was added, and none should be added without an explicit separate decision, since that touches client communication and delivery guarantees this app doesn't own.

Reason: keep "important numbers visible once, decisions actionable" without adding a charting dependency or an email-sending subsystem neither of which this task asked for.

## Out Of Scope

The following are intentionally excluded:

- Full CRM integration.
- Browser-side CRM loading.
- Full historical order sync.
- Charts.
- Full payments ledger.
- Full debt tracking workflow.
- VAT invoice logic.
- KeyCRM file attachment.
- Destructive or broad database migrations.

## Invoice Recipient Snapshot Model

Decision:

- `db_invoices` stores immutable recipient/contact snapshot fields for each invoice.
- `recipient_*` fields are the legal payer used in PDF documents.
- `contact_*` fields are the buyer/contact person and are shown separately.
- `buyer_*` fields remain only for backward compatibility with older invoice rows.
- Do not write fake `Покупець` values. If no payer is known, show a warning and let the user fill it.
- `db_client_legal_entities` is local `.BRAND DB` data and must not be overwritten by KeyCRM sync.
- KeyCRM company full/legal name may be used only as the first draft candidate for a local legal entity.

Reason: old PDF/document history must remain stable even if the client legal entity changes later, and KeyCRM cannot model multiple payers under one client group well enough for `.BRAND` invoicing.

## Local Client Manager Ownership

Decision:

- `.BRAND DB` needs local manager assignment on both client company and contact levels.
- Contact manager can override company manager.
- Contact can inherit company manager.
- KeyCRM manager values remain source/raw data, but local assignment is the source of truth for CEO cockpit reporting once implemented.
- Local manager assignment must not be overwritten by KeyCRM sync.
- Bulk reassignment is required for manager changes or employee offboarding.

Reason: one client group may have many contacts and those contacts may belong to different `.BRAND` managers.

## Minimal Invoice Editing

Decision:

- The invoice edit page shows only daily editing fields.
- Legal/tax detail fields remain stored in the database but are hidden from the main form.
- The registry `Docs` column is the main place to generate/download invoice PDF, delivery note, and act.
- Do not drop hidden invoice columns yet; they may be needed for document history or future automated legal-data enrichment.

Reason: invoice work should be fast and compact; rare legal details should not make every invoice edit screen heavy.

## Client Search Is Local-Only

Decision:

- Sync KeyCRM companies and buyers server-side into `.BRAND DB`.
- Invoice autocomplete searches local DB only through `ajax_client_search.php`.
- Do not render all clients into HTML dropdowns.
- Do not call KeyCRM from the browser.
- Do not overwrite local legal entities or local manager assignments during sync.

Reason: invoice editing must stay fast even when the client base grows, and KeyCRM credentials must never reach the browser.

## Our Seller Requisites Are Structured

Decision:

- Our seller legal entities live in `db_our_companies`.
- Bank/card/payment requisites live in `db_our_company_accounts`.
- Invoice PDF seller data must use those tables, not hardcoded template values.
- `payment_requisites.php` is a manager copy tool, not an invoice generator.
- FOP group 2 remains products-only; service wording is reserved for reviewed FOP group 3 / service-allowed sellers.
- VAT and currency invoice templates are stored/planned but not implemented in PDFs yet.

Reason: `.BRAND DB` needs many seller entities and accounts without turning invoice work into accounting ERP.

## Seller Account Selection Requires IBAN

Decision:

- Invoice and payment-requisite account selectors show only active seller accounts with a non-empty IBAN.
- `our_companies.php` still shows inactive/empty-IBAN accounts for review and maintenance.
- CEO and accountant can edit seller companies/accounts; managers can only generate copyable payment text.
- `payment_requisites.php` remains a copy tool and does not create invoices, payments, or KeyCRM records.

Reason: production has intentional inactive/empty accounts, and they must not accidentally appear in invoice/payment text selection.

## CEO Money Cockpit v2 Is Preview First

Decision:

- `dashboard_v2.php` is the preview executive dashboard.
- `index.php` remains the current production dashboard until explicit CEO approval.
- The main page separates Performance, Cash, and Financial Health.
- Sales are measured by `db_orders.order_month`.
- Cash receipts are measured by `db_order_payments.payment_date`.
- Receivables are measured across all months by `db_orders.unpaid_amount_uah > 0`.

Reason: the CEO needs a fast decision screen, and old/new formulas must be validated before replacing production.

## Outgoing Payments Are Obligations

Decision:

- New outgoing payment work should use `db_payment_obligations`.
- `db_expenses` remains legacy for now and should not be expanded into a large ERP model.
- Strategic debt is shown separately and does not automatically reduce operating profit or current operational pressure.

Reason: `.BRAND` needs to control who must be paid, when, priority, and order links; this is broader than simple expenses.

## Cockpit v2 Pages Are Read-Only First

Decision:

- Financial drill-down pages show local DB data only.
- `payments.php` is a read-only financial journal for now.
- `accounts.php` calculates balances from completed transactions.
- Payment method allocation uses `db_keycrm_payment_method_accounts`; no hardcoded account IDs.
- Unmapped payments are not lost; they are marked `needs_review`.

Reason: the first goal is reliable visibility and reconciliation before adding editing workflows.

## Client Base Is A Retention Command Center

Decision:

- `client_balances.php` should be treated as the client command center, not only a balance table.
- The page uses month navigation for daily CEO control. Older `quarter=YYYY-MM` URLs are accepted only as compatibility input.
- Company names link to filtered sales/orders for that client.
- Manager filtering is allowed from existing order manager data.
- Trend badges are simple leading indicators: new, returned, growing, falling, sleeping, active, no movement.
- Client value badges use all-time purchases: VIP from 2,000,000 UAH, key from 1,000,000 UAH, core from 250,000 UAH, starter below 250,000 UAH.
- The value period switch can show all-time, last 12 months, or selected-month revenue, but it does not change the lifetime segment.
- Customer Health measures relationship condition, not value. It starts from 100 and subtracts transparent risk penalties.
- Lifetime value is never added to Health. VIP clients can still have poor Health.
- Receivables affect Health only after a known payment due date is overdue.
- If payment due date is unknown, show `Строк не визначено` and do not treat it as overdue automatically.
- Manager reassignment must wait for local company/contact ownership fields and should not rewrite historical `db_orders.manager_name`.

Reason: the CEO needs to notice falling or sleeping clients early and assign action before the client disappears.

## Local Analytics Exclusions

Decision:

- CEO can mark a client company as excluded from analytics.
- CEO can mark a buyer/contact as excluded from analytics.
- CEO can mark an individual order as excluded from analytics.
- Excluded records stay in the database and can be restored.
- Exclusions are local `.BRAND DB` control data and must not be written back to KeyCRM.
- Active sales, receivables, cash KPIs, client health, and manager summaries must ignore excluded orders, excluded client companies, and excluded buyers/contacts.
- `Дебіторка` is no longer a separate daily page; receivables work belongs inside `Продажі` with the `Дебіторка` filter.

Reason: some rows are real CRM records but should not affect CEO turnover, debt, or manager performance. The system needs a reversible business-control flag, not deletion.

## Sales Targets Are Effective-Dated

Decision:

- Company and manager targets are not overwritten in place.
- Each target save creates a new `db_sales_targets` row with `effective_from`.
- The active target for a month is the newest row whose `effective_from` is on or before the last day of that month.
- Manager target history must remain visible to CEO.
- Managers on the `Плани` page come from all-time orders and target history, not only from the selected month.

Reason: `.BRAND` needs to set historical plans from 2022, change plans in future months, and still understand what plan was active at any point in time.

## Client Manager Source

Decision:

- `db_orders.manager_name` is the manager of a specific order.
- The clients page must not use order manager as client manager.
- Client manager display uses local assigned manager first, then KeyCRM company/contact manager source fields, then `Без менеджера`.
- If KeyCRM company or buyer has no manager, `.BRAND DB` must show `Без менеджера` until CEO assigns a local manager.

Reason: order ownership and client ownership are different business concepts. Mixing them makes the client base look assigned when it is not.

## Sales Page Debt Mode

Decision:

- `Продажі → Дебіторка` is a receivables workspace, not a payment journal.
- In debt mode, hide incoming payments and prioritize unpaid orders, manager debt totals, and largest debts.
- Use `db_orders.unpaid_amount_uah > 100` as the debt workspace rule.
- Ignore unpaid differences up to 100 UAH because small rounding/payment discrepancies are not actionable receivables.
- Product lines are collapsed by default so the CEO can scan debt before opening details.
- Debt PDF must show the active scope explicitly: manager, client, or all clients.

Reason: receivables work starts with who owes money and who must act. Payments belong to normal sales/cash views, not the first screen of debt control.

## Clients Page Period Search

Decision:

- The clients page uses a period (`from_month` → `to_month`) instead of a single month.
- Company search must not depend on the company having orders in the final month of the period.
- Company search should use local `db_client_companies` first, then enrich metrics from `db_orders`.
- Client rows with the same normalized company name may be merged for CEO readability.
- Health/trend calculations use the final month of the selected period as the reference point.
- Sales and cash KPIs use the whole selected period.

Reason: the CEO often searches for an existing client to inspect history, even if that client did not order in the currently selected month.
