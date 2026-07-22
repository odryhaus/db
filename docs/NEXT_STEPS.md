# Next Steps

## Deploy Test Checklist

Production auth is already live. Use this checklist after each deploy:

Checklist:

- GitHub repository secrets exist: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
- GitHub Actions workflow finishes successfully.
- `https://bph.com.ua/db/login.php` loads the login form.
- `https://bph.com.ua/db/config/config.php` is not publicly readable.
- `https://bph.com.ua/db/setup-ceo.php` is not available.
- Invalid login shows only a generic error.
- CEO user can log in.
- CEO can open `https://bph.com.ua/db/users.php`.
- CEO, accountant, and manager can open the protected money dashboard.
- Manager/accountant users cannot see `users.php` or `sync_orders.php` links.
- Dashboard selected-month metrics update when `?month=YYYY-MM` changes.
- `Нам повинні` shows unpaid client debt across all months.
- Receivables pagination keeps the selected month parameter.
- CEO can open `targets.php` and save monthly/manager targets.
- CEO/accountant can open `https://bph.com.ua/db/invoices.php`.
- CEO/accountant can create an invoice draft from an existing `db_orders.keycrm_id`.
- Invoice number defaults to the KeyCRM order number.
- Invoice item titles, quantities, prices, recipient, contact, number, and date can be edited before document generation.
- PDF download button is labeled `PDF` and downloads `INV_<number>.pdf` or `DN_<number>.pdf`.
- Buyer/contact is separate from company/legal recipient.
- Saved client legal entities can be selected on future invoices.
- Seller `FOP Darchenko A.B.` exists in `db_our_companies` after first invoice page load.
- Collapsed item title for products-only seller is `Поліграфічна продукція`.
- Generated invoice/delivery PDF downloads from the invoice page.
- CEO/accountant can open `expenses.php`.
- Dashboard shows operational monthly expenses and strategic debt separately.
- Logout redirects back to login.

## Production Config Setup Reminder

`config/config.php` is ignored by Git and excluded from deploy. Do not commit or overwrite production config.

Required production app setting:

```php
'base_path' => '/db',
```

The old setup key was disabled manually in production and should remain disabled.

## Current Dashboard / CEO Money Cockpit

CEO Money Cockpit reads sales only from `db_orders`.

Rules:

- Selected month controls sales plan/fact metrics.
- Total receivables / `Нам повинні` shows all unpaid orders across all months.
- Monthly target comes from `db_sales_targets`, with `4,000,000 UAH` fallback.
- Manager plan/fact comes from `db_sales_targets` plus `db_orders`.
- Targets are effective from date and remain active until changed.
- Outgoing payment KPIs should come from `db_payment_obligations`; `db_expenses` remains only legacy/fallback until the new table exists.
- Strategic debt is shown separately from monthly operational pressure.
- Old `orders` table is ignored.
- No browser KeyCRM calls are made.
- No charts exist yet.

## Next Milestone

CEO Money Cockpit v3.1 — reduce daily work to fewer clearer pages.

Product direction:

- Keep daily navigation focused on `Cockpit`, `Клієнти`, `Продажі`, `Гроші`, and `Документи`.
- Move technical pages to `Адмін` or direct drill-down links.
- Create one client profile page with orders, invoices, payments, buyers, legal entities, receivables, and notes.
- Turn `Потрібна дія` into a real workflow: call client, check payment, pay supplier, close documents, snooze, done.
- Make invoices a compact document workstation with registry first and focused edit/create panels.
- Continue removing technical labels from CEO pages: replace internal field names/statuses with business language.
- Add pagination and filters to `Продажі`, `Клієнти`, and `Документи` before these lists grow too large.
- Add a proper client drill-down instead of trying to fit all months, buyers, orders, and debts into one table row.
- Build Client Profile v0.1:
  - all orders for one company;
  - buyers/contacts under the company;
  - legal entities;
  - debt and invoices;
  - monthly trend;
  - manager owner;
  - next follow-up date and note.
- Build local manager reassignment only after the local ownership fields are verified:
  - company owner;
  - contact owner override;
  - inherit company manager;
  - bulk reassign from manager A to manager B.
- Turn current simple client trend badges into a real client health score:
  - add payment delay history;
  - add last contact/follow-up;
  - add manager ownership;
  - add manual reason for pause/lost client.
- Add follow-up workflow for falling/sleeping/VIP clients:
  - next contact date;
  - responsible manager;
  - reason for pause;
  - result of last call.
- Add manager playbooks from customer-health signals:
  - `VIP + борг`: CEO/accountant payment control;
  - `VIP + падає`: manager call this week;
  - `спить`: win-back reason and next offer;
  - `повернувся`: keep warm and propose next order;
  - `стартовий + росте`: nurture into core account.
- Review and approve additive client health fields:
  - company payment terms;
  - order/invoice payment due overrides;
  - next follow-up;
  - last contact;
  - pause reason;
  - seasonal/tender flag.

Planning file:

```text
docs/PAGE_AUDIT.md
```

Payment Obligations v0.1 remains the next money-control foundation task: create `db_payment_obligations` table and compact payment timeline UI.

Before that, verify Near Real-Time Sync v0.1 in production:

- Add cron commands from `docs/CRON_SETUP.md`.
- Click `Оновити все` as CEO.
- Confirm `db_sync_jobs` child jobs finish successfully.
- Confirm repeated clicks on `Оновити все` queue a fresh pass for already-finished parts such as `orders` and `payments`, without duplicating parts that are already `queued` or `running`.
- Confirm the `unpaid_orders` job clears orders that were paid after they first appeared in receivables.
- Confirm `db_order_payments` receives payment rows.
- Confirm `db_order_expenses` receives order expense rows.
- Confirm `filter[updated_between]` is accepted by production KeyCRM.
- Compare “Оплачено” against several KeyCRM orders before trusting payment KPIs.
- For full history from July 2022, use `Імпорт історії` with `from_month=2022-07` and current month as `to_month`.
- If many jobs remain `queued`, check cron for `cron/sync_worker.php`; queued means the app created jobs, not that KeyCRM import has finished.
- Do not use dashboard `Оновити все` for first full-history load. It is optimized for delta/recent updates after the base history already exists.
- If historical jobs remain `queued`, use `Імпорт історії → Обробити 1 історичний місяць` as a diagnostic, then configure cron for continuous processing.
- If the history queue is too large, use `Очистити queued історію`, then enqueue half-year ranges one by one with the range buttons.
- If cron is still not processing history, use `Обробити до 3 історичних місяців` as a temporary manual fallback.
- Review the new client drilldown in `Продажі`: confirm that sales by order month and cash by payment date match the CEO's expected interpretation for old-order payments.

The milestone should:

- Add the planned `db_payment_obligations` table.
- Keep `db_expenses` as legacy/fallback only if already created.
- Build a compact timeline grouped by overdue, today, tomorrow, this week, next week, later, and strategic debts.
- Add simple actions: Paid, Move, Edit.
- Add quick move options: `+1 day`, `+3 days`, `+7 days`, next Monday, custom date.
- Link obligations to `keycrm_order_id` when a payment belongs to an order.
- Show expected net cash for order-linked receivables.
- Do not add drag-and-drop.
- Do not call KeyCRM from the browser.

Invoices v0.2 / CEO Money Cockpit v0.5 — verify invoice extraction, targets, payment obligations, manager mapping, and payment status rules.

Planning document:

```text
docs/DATABASE_PLAN.md
docs/CRM_SYNC_PLAN.md
```

Inspection page:

```text
https://bph.com.ua/db/keycrm_debug_order.php?order_id=9232
```

The next milestone should:

- Review `docs/DATABASE_PLAN.md` section `Invoice / Document Data Gap Analysis`.
- Decide the minimum invoice data additions for v0.2:
  - `next_follow_up_at`
  - `last_reminder_at`
  - `reminder_count`
  - invoice event/audit table
- Run CEO manual sync for current and previous month.
- Test invoice creation from several real KeyCRM orders in `db_orders`.
- Compare extracted invoice products, buyer/company data, and totals against KeyCRM.
- Verify production Dompdf PDF generation for invoice, delivery note, and act.
- Verify `db_invoice_documents` stores multiple generated documents for one invoice without overwriting older files.
- Add a reviewed file-download policy for generated invoice files if more roles get access.
- Decide when KeyCRM file attachment should be added.
- Verify `ordered_at` month assignment.
- Verify totals against KeyCRM for sample orders.
- Verify buyer/company extraction.
- Decide final canceled/deleted status exclusion rules.
- Decide whether cron/automatic sync is needed.
- Confirm whether managers can be safely filtered by local `users.keycrm_id`.
- Review whether finance tables should be created by manual SQL instead of app bootstrap.
- Add expense recurrence expansion if monthly subscriptions need concrete due rows.
- Add data freshness display and sync error handling improvements if needed.

The next dashboard should add or improve:

- Reliable manager-only data filtering.
- Clear canceled/deleted/refunded status rules.
- Better payment status labels if KeyCRM values are too technical.
- Expense payment audit/history if needed.

## UI Follow-ups (After Compact Dashboard Redesign)

This environment has no `php` binary or database access, so the redesign was reviewed by careful re-reading only, not exercised in a browser. Before treating the redesign as final:

- Run it locally (`php -S localhost:8000`) or on staging and click through: month switch, receivables pagination, manager debt drilldown filter, targets save, expenses add/edit/filter/paid, users search/role filter, users `?all=1` toggle, sync run button.
- Check the sticky topbar and sticky receivables-table header on an actual long scroll — `position: sticky` behavior can differ slightly across browsers when nested inside `overflow` containers.
- Verify the payment status badge logic (`Оплачено` / `Частково` / `Не оплачено`, computed from `paid = total − unpaid`) against a few real KeyCRM orders — it intentionally ignores the raw `payment_status` string because those values were inconsistent, but that should be confirmed against production data.
- Have a Ukrainian-speaking reviewer sanity-check the interface copy (labels, empty states, nav) added across `index.php`, `targets.php`, `expenses.php`, `users.php`, `sync_orders.php`.
- Consider adding client-side search/filter to the receivables table (like the one added to `users.php`) if the CEO wants to scan it without paging.
- Charts are still explicitly out of scope beyond the plain CSS stacked bar added 2026-07-03; revisit only if the CEO asks for real trend visuals (e.g. month-over-month).

## Dashboard Hero/Pacing/Aging/Client-Debt (2026-07-03) — verify after deploy

- Confirm the merged "Прогрес плану" panel shows one set of numbers (no more repeated План/Факт between the KPI strip and the panel).
- Confirm the paid/unpaid stacked bar under the progress bar renders and its two widths sum to 100% at a few different fact/paid values, including `Факт = 0`.
- Confirm "Темп" pacing badges (company panel + manager table) look right early/mid/late in a month, and for a manager with no target (`has_target = false`, should show `—`, not a badge).
- Confirm the 0–7/8–30/30+ day aging chips above the receivables table match manual counts against `db_orders`.
- Confirm "Клієнти з боргом" groups by client id (not by name) and the "Без клієнта" bucket only appears when `company_id`, `buyer_id`, and `client_id` are all empty.
- Open a "Зведення" link (`index.php?client_statement=<id>`) as each role (ceo/accountant/manager) and confirm: Print and Copy-text buttons work, the copied text matches the visible table, and the page 404s cleanly for an id with no unpaid orders.
- This statement page only prepares text/print output — it does not email or message the client. If the CEO wants actual sending, that is a new, separate feature decision (see `docs/DECISIONS.md`).

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

## Invoice Recipient Snapshot Follow-up

Verify on production with real orders:

- KeyCRM `order.buyer` contains `id`, `full_name`, `email`, and `phone`.
- If `buyer.company` is absent, server-side buyer fetch with `include=company` succeeds or fails harmlessly.
- Default local legal entity is reused for the next invoice from the same client company.
- Edited recipient fields are saved to invoice snapshots and do not change old documents later.
- Downloading generated files increments `db_invoice_documents.download_count`.

## Clients / Managers Planned Milestone

Create a compact Clients page later:

- List client companies.
- Show contacts under each company.
- Show company assigned manager.
- Show contact assigned/effective manager.
- Allow changing manager for one company.
- Allow changing manager for one contact.
- Allow setting contact to inherit company manager.
- Allow bulk reassignment when a manager leaves.

Do not write local manager assignments back to KeyCRM.

## Optional Legal Data Enrichment

Later, evaluate whether `.BRAND DB` should auto-fill legal entity data by EDRPOU/tax number.

Requirements before implementation:

- Choose a reliable public registry/API source.
- Confirm usage limits and legal/commercial terms.
- Never overwrite invoice snapshots automatically.
- Fill hidden legal fields only as suggestions for user review.

## Client Sync Follow-up

After deploy:

- Open `clients_sync.php` as CEO.
- Run small delta sync for companies and buyers first.
- If results look correct, run explicit initial imports.
- Verify invoice autocomplete returns companies, legal entities, and contacts from local DB only.
- Confirm KeyCRM company/buyer endpoint filters support or ignore `updated_after`; bounded page limits remain the fallback.
- Later add a Clients page for manager assignment and bulk reassignment.

## Our Companies Follow-up

After deploy:

- Open `our_companies.php` as CEO/accountant and verify seeded FOP/TOV/PP companies.
- Confirm each active seller has a default UAH account.
- Fill missing USD IBAN for `ФОП Кубар Т.О.` when available.
- Open `payment_requisites.php` as manager and copy UAH/EUR/USD payment texts.
- Confirm account selector hides inactive accounts and accounts without IBAN.
- Confirm `payment_requisites.php` filters accounts by selected company and currency.
- Later add VAT 20% PDF templates for VAT sellers.
- Later add English EUR/USD invoice templates.

## CEO Money Cockpit v2 Follow-up

Next milestone:

Payment validation and preview review.

Checklist:

- Open `dashboard_v2.php`.
- Confirm `index.php` still works.
- Run `payment_sync_check.php` for fully paid, partially paid, unpaid, and old-order-paid-this-month examples.
- Copy back any mismatches before finalizing charts.
- Confirm cron runs `cron/sync_worker.php` frequently enough for near-online updates.
- After CEO approval, decide whether `dashboard_v2.php` should replace `index.php`.

Planned next build:

- Payment Obligations v0.1 timeline UI from `db_payment_obligations`.
- Cash drill-down page.
- Receivables drill-down page.
- Manager drill-down page using validated local manager ownership.

## Financial Journal Follow-up

After deploy:

- Open `payments.php` and check imported KeyCRM client payments.
- Open `accounts.php` and verify balances by account/company/currency.
- Configure missing rows in `db_keycrm_payment_method_accounts` for payment methods that appear as `needs_review`.
- Validate `db_order_items` for a real order with changed/deleted products.
- Decide later whether to add safe editing forms for manual financial transactions.
- After this deploy, run `Оновити все` once to backfill financial operations from already cached payments.
- Review invoice package ZIP on a row that already has invoice/delivery-note/act PDFs.

## Cockpit v2 Remaining Detail Work

- Validate Cockpit v3 navigation with CEO: daily pages should be enough without opening `Адмін`.
- Turn `Потрібна дія` into a real workflow later: owner, due date, follow-up status, note, done/snooze.
- Add pagination to `sales.php`, `cash.php`, and `payments.php`.
- Complete filters on `sales.php`: manager, client, payment status, order status, receivables aging, and debt amount.
- Complete filters on `cash.php`: company, account, payment method, current/old order, allocation status.
- Validate `client_balances.php` against real companies and buyers, then decide whether it should get drill-down links to filtered sales/cash rows.
- Run `history_sync.php` from `2022-07` to the current month once, then validate old and current months in dashboards.
- Decide how legacy `db_expenses` should be migrated or mirrored into `db_financial_transactions` so expenses appear in `Операції`.

## Analytics Exclusion Follow-up

After deploy:

- Verify that excluded client companies no longer affect Cockpit sales, total receivables, client health, manager summaries, and cash KPIs.
- Verify that excluded individual orders no longer affect the same totals.
- Decide whether exclusion notes are needed in the UI; the columns exist but the first UI keeps the action one-click.
- Decide whether non-CEO roles should be allowed to request exclusion without applying it.
- Review name-only excluded clients after full company sync; replace them with KeyCRM company-id exclusions when possible.
- Decide whether target history needs delete/cancel controls or whether new effective-dated rows are enough.
- After the client manager source fix, run `Клієнти Sync` for companies and buyers, then review `Клієнти → Менеджер → Без менеджера`.
- In `Продажі → Дебіторка`, validate several real paid/part-paid/unpaid orders after clicking `Оновити все`; debt mode should show unpaid orders first and no payments table.
- Continue reducing duplicate pages:
  - keep `Cockpit`, `Клієнти`, `Продажі`, `Гроші`, and `Документи` as daily pages;
  - keep `Менеджери`, `Плани`, `Витрати`, `Операції`, `Реквізити`, `Наші компанії`, sync pages, and users under Admin/direct links;
  - leave `receivables.php` only as a compatibility redirect to `sales.php?status=debt`.
