# .BRAND DB Page Audit

Date: 2026-07-17

## Product Principle

The daily product must feel like a CEO command center, not a collection of tables.

Daily navigation should stay small:

- Cockpit
- Клієнти
- Продажі
- Гроші
- Документи

Everything else belongs in `Адмін` or a drill-down.

## Daily Pages

### Cockpit — `dashboard_v2.php`

Goal: show what needs CEO attention today.

Status: closest to the right product direction after adding `Потрібна дія`.

Problems:

- Still has too many English/technical labels: Performance, Cash, Gross margin, allocation_status.
- Needs a stronger "today" layer: what to call, what to pay, what to check.
- Manager table is useful but should eventually become a drill-down if the page gets too long.

Recommendation:

- Keep this as the only daily start page.
- Convert `Потрібна дія` into real tasks later: owner, due date, done, snooze, note.

### Клієнти — `client_balances.php`

Goal: show company/client value, buyers inside company, current debt, cash, and month behavior.

Status: improved into a first client command center.

Problems:

- It still depends on cached order data; companies with no orders will not show.
- No client profile page yet.
- Manager ownership can only be filtered now; reassignment needs local ownership fields and UI.

Changes made:

- Company-first list.
- Buyers shown inside company.
- Search now uses a combined text haystack and token matching.
- SQL errors are no longer hidden as "Даних немає".
- Selected month search was removed; the page now uses quarter navigation.
- Company name opens filtered sales/orders for that client.
- Added manager filter.
- Added simple client trend badges: growing, falling, sleeping, new/returned, stable.

Recommendation:

- Next step: create one client profile page with orders, invoices, payments, buyers, legal entities, debt and notes.
- Add follow-up workflow and local manager reassignment after ownership fields are verified.

### Продажі — `sales.php`

Goal: inspect selected-month sales orders and product lines.

Status: improved after PDF review.

Problems:

- Needs filters: client, manager, payment status, order status.
- Long product rows made the old table visually heavy.
- No pagination.

Change made:

- Replaced the old mixed order/product table with order cards and compact product rows inside each order.

Recommendation:

- Keep as drill-down from Cockpit.
- Add compact filters and pagination.

### Гроші — `cash.php`

Goal: show real money received by payment date.

Status: useful as a cash receipt journal.

Problems:

- Needs filters by account, company, payment method, client.
- Needs clearer separation between money from current-month orders and money from old debts.

Recommendation:

- Make this the main cash reconciliation page, not another dashboard.

### Документи — `invoices.php`

Goal: create and control invoices, PDF packages and document closing.

Status: improved after PDF review, but still needs a focused invoice workstation pass.

Problems:

- Page is still too large internally: create, edit, registry, PDFs, statuses all in one file.
- UX must become more like a professional document workstation.
- Needs better client/legal-entity/contact selection flow.

Change made:

- Replaced the wide invoice registry table with compact working cards so payer names, files, statuses and actions do not get pushed off-screen.

Recommendation:

- Keep registry as the main first screen.
- Move creation/editing into compact focused panels.
- Add document package status clearly.

## Admin / Drill-Down Pages

### Дебіторка — `receivables.php`

Goal: all unpaid orders across all months.

Status: useful but should be a drill-down, not daily nav.

Problems:

- Needs aging filters, search, responsible manager/follow-up workflow.
- It shows debt but not enough action state.

Recommendation:

- Add follow-up fields later: next contact date, promise date, status, note.

### Менеджери — `managers.php`

Goal: manager target/fact/paid/unpaid.

Status: useful management view.

Problems:

- Should link to filtered orders and debts more consistently.
- Needs local manager ownership rules later.

Recommendation:

- Keep under Admin until it becomes a focused performance drill-down.

### Плани — `targets.php`

Goal: set company and manager targets.

Status: admin setup page.

Problems:

- Not daily work.
- Should stay simple.

Recommendation:

- Keep in Admin.

### Операції — `payments.php`

Goal: read-only financial transaction journal.

Status: technical but important for reconciliation.

Problems:

- Too technical for CEO daily use.
- Needs filters and allocation workflow.

Recommendation:

- Keep in Admin until manual classification/allocation exists.

### Баланси рахунків — `accounts.php`

Goal: financial account balances.

Status: useful but depends on transaction allocation quality.

Problems:

- Can confuse with client balances and invoice accounts.

Recommendation:

- Keep label explicit: `Баланси рахунків`.

### Витрати — `expenses.php`

Goal: planned/outgoing payments and strategic debts.

Status: needed, but should evolve into payment obligations timeline.

Problems:

- Still split between legacy expenses and future payment obligations model.
- Needs a compact timeline and direct connection to Cockpit action queue.

Recommendation:

- Next build should turn this into `Payment obligations`.

### Реквізити — `payment_requisites.php`

Goal: copy payment requisites quickly.

Status: useful utility.

Problems:

- Not daily CEO page.

Recommendation:

- Keep in Admin or link from invoice/payment flows.

### Наші компанії — `our_companies.php`

Goal: manage seller legal entities and accounts.

Status: important admin setup.

Problems:

- Needs careful review to avoid duplicate seller/account data.

Recommendation:

- Keep for CEO/accountant only.

### Імпорт історії — `history_sync.php`

Goal: explicitly backfill old order months.

Status: technical but necessary.

Problems:

- Should not be part of daily work.

Recommendation:

- Keep in Admin.

### Клієнти Sync — `clients_sync.php`

Goal: import KeyCRM companies and buyers.

Status: technical.

Problems:

- Too technical for daily product.

Recommendation:

- Keep in Admin only.

### Користувачі — `users.php`

Goal: manage dashboard access.

Status: admin-only.

Problems:

- No audit log yet.

Recommendation:

- Keep in Admin.

## Biggest Product Gaps

1. No client profile page.
2. No real task/follow-up workflow.
3. No unified payment obligations timeline.
4. No pagination/filter system across large tables.
5. Invoice UX is still too heavy.
6. Sync is safer now, but still visible to CEO.

## Next Recommended Milestone

CEO Money Cockpit v3.1:

- client profile page;
- action queue as real workflow;
- debt follow-up status;
- payment obligations timeline;
- cleaner invoice workstation.
