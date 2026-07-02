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
- Create only project-owned additive tables: `db_our_companies`, `db_invoices`, `db_invoice_items`.
- Invoice rows and item rows are editable local copies copied from `db_orders.raw_json`.
- Do not change KeyCRM orders.
- Do not write payment statuses to KeyCRM.
- Do not attach generated files to KeyCRM in v0.1.
- Use no-VAT text for current documents: `Без ПДВ. Платник єдиного податку.`
- Keep VAT support out of scope until a VAT seller company is explicitly configured.
- For seller companies with `allowed_item_type = 'products_only'`, default collapsed item wording is `Поліграфічна продукція`; service wording is not generated.
- Services remain disabled until a FOP 3 group seller company with `allowed_item_type = 'services_allowed'` is configured and reviewed.
- Invoice number defaults to the KeyCRM order number, not an internal `INV-YYYYMM-0001` sequence.
- PDF file names use `INV_<invoice_number>.pdf` for invoices and `DN_<invoice_number>.pdf` for delivery notes.
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
