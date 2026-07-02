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

Money Dashboard must read from `db_orders`, not from the old `orders` table and not directly from KeyCRM.

Decision:

- Ignore the old `orders` table because it is outdated.
- Use `ordered_at` as the monthly turnover date.
- Store `order_month` as `YYYY-MM` from `ordered_at`.
- Keep KeyCRM calls server-side only.
- Start with CEO-only manual sync for current month and previous month.
- Do not build a full historical sync or ERP workflow.

## Money Dashboard Metrics

Selected month controls monthly sales plan/fact metrics.

Decision:

- Monthly sales, paid, unpaid, order count, remaining target, progress, manager summary, and daily required sales use `order_month = YYYY-MM`.
- Total receivables / `Нам повинні` uses all unpaid orders across all months.
- Receivables are paginated at 25 rows per page.
- Old `orders` table remains ignored.
- No charts are added yet.
- Expense planning is additive and intentionally lightweight.
- Manager-specific filtering is deferred until KeyCRM manager mapping to local users is confirmed.

## Targets And Expenses Foundation

Use small additive tables for dashboard planning data.

Decision:

- Create `db_monthly_targets`, `db_manager_targets`, and `db_expenses` only if they do not already exist.
- Use `db_monthly_targets` for the dashboard monthly target, with `4,000,000 UAH` fallback.
- Use `db_manager_targets` for manager plan/fact comparisons.
- Use `db_expenses` for planned outgoing payments and strategic debts.
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

## Out Of Scope

The following are intentionally excluded:

- Full CRM integration.
- Browser-side CRM loading.
- Full historical order sync.
- Charts.
- Full payments ledger.
- Full debt tracking workflow.
- Destructive or broad database migrations.
