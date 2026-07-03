# CEO Money Cockpit — Database Plan

## 1. Core Principle

Core purpose:

Every day the CEO must see:

- monthly sales target
- manager target/fact
- client receivables
- unpaid and partially paid orders
- outgoing payment obligations
- operational cash pressure
- strategic debts
- invoice/document status

KeyCRM is the source for operational CRM data:

- orders
- payments
- buyers
- companies
- products
- files

`.BRAND DB` is the source for CEO control data:

- sales targets
- manager plans
- internal payment obligations
- strategic debts
- invoice drafts
- document workflow statuses
- internal notes

The dashboard must read from the local database only. The browser must not call KeyCRM directly. KeyCRM API calls must stay server-side, with the token stored only in `config/config.php`.

## 2. Existing Tables

### users

Purpose:

- Existing CRM-synced users table.
- Used for `.BRAND DB` authentication and roles.

Source of data:

- Existing production database / CRM user sync.
- `.BRAND DB` uses only DB-specific auth columns.

Main fields used:

- `id`
- `keycrm_id`
- `email`
- `first_name`
- `last_name`
- `db_role`
- `db_active`
- `db_password_hash`
- `db_last_login_at`

Pages:

- `login.php`
- `logout.php`
- `index.php`
- `users.php`
- protected pages through `auth.php`

### db_orders

Purpose:

- Local KeyCRM order cache for fast dashboard reads.
- Source for monthly sales, unpaid orders, receivables, manager summaries, and invoice draft creation.

Source of data:

- Server-side KeyCRM order sync.

Main fields:

- `keycrm_id`
- `order_number`
- `ordered_at`
- `order_month`
- `source_created_at`
- `source_updated_at`
- `status_id`
- `status_group_id`
- `status_name`
- `payment_status`
- `manager_id`
- `manager_name`
- `manager_email`
- `client_id`
- `client_name`
- `buyer_id`
- `buyer_name`
- `buyer_email`
- `buyer_phone`
- `company_id`
- `company_name`
- `total_amount_uah`
- `paid_amount_uah`
- `unpaid_amount_uah`
- `products_total_uah`
- `expenses_sum_uah`
- `margin_sum_uah`
- `raw_json`
- `synced_at`

Pages:

- `index.php`
- `targets.php`
- `sync_orders.php`
- `invoices.php`

### db_sync_runs

Purpose:

- Log manual server-side order sync runs.
- Shows whether local order cache is fresh.

Source of data:

- `.BRAND DB` sync process.

Main fields:

- `id`
- `sync_type`
- `started_at`
- `finished_at`
- `status`
- `month_from`
- `month_to`
- `orders_seen`
- `orders_upserted`
- `error_message`
- `created_by_user_id`

Pages:

- `sync_orders.php`
- `index.php` for last successful sync time

### db_sales_targets

Purpose:

- Effective-date sales targets for company and managers.

Source of data:

- CEO input in `.BRAND DB`.

Main fields:

- `id`
- `target_type`
- `manager_name`
- `amount_uah`
- `effective_from`
- `note`
- `created_by_user_id`
- `created_at`
- `updated_at`

Pages:

- `targets.php`
- `index.php`

### db_expenses

Purpose:

- Internal outgoing payments, subscriptions, operational debts, loan payments, and strategic debts.

Source of data:

- CEO/accountant input in `.BRAND DB`.

Main fields:

- `id`
- `title`
- `category`
- `amount_uah`
- `currency`
- `expense_type`
- `due_date`
- `repeat_day`
- `repeat_until`
- `total_debt_amount_uah`
- `paid_amount_uah`
- `status`
- `is_strategic`
- `note`
- `created_by_user_id`
- `created_at`
- `updated_at`

Pages:

- `expenses.php`
- `index.php`

### db_our_companies

Purpose:

- Our seller companies: FOP / TOV / other legal entities.
- Controls invoice seller details, tax mode, and whether service wording is allowed.

Source of data:

- `.BRAND DB` configuration/admin data.

Main fields:

- `id`
- `short_name`
- `legal_name`
- `edrpou`
- `iban`
- `bank`
- `address`
- `email`
- `phone`
- `accountant_email`
- `accountant_phone`
- `tax_mode`
- `allowed_item_type`
- `is_active`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

### db_invoices

Purpose:

- Editable commercial invoice header.
- Main working row for `Реєстр рахунків`.
- Stores payment-control status and expected payment date.
- Does not need to store every generated document file directly; generated files belong in `db_invoice_documents`.

Source of data:

- Initial copy from `db_orders` / `db_orders.raw_json`.
- Later edits from CEO/accountant in `.BRAND DB`.

Main fields:

- `id`
- `keycrm_order_id`
- `invoice_number`
- `invoice_date`
- `document_type`
- `seller_company_id`
- `client_company_id`
- `client_legal_entity_id`
- `buyer_id`
- `buyer_company_id`
- `buyer_display_name`
- `buyer_contact_name`
- `buyer_edrpou`
- `buyer_address`
- `buyer_email`
- `buyer_phone`
- `total_amount_uah`
- `vat_mode`
- `vat_amount_uah`
- `total_with_vat_uah`
- `payment_purpose`
- `payment_status`
- `document_status`
- `payment_due_date`
- `document_due_date`
- `expected_payment_date`
- `status`
- `sent_at`
- `paid_at`
- `docs_type`
- `docs_status`
- `docs_sent_at`
- `docs_closed_at`
- `pdf_file_path`
- `keycrm_file_id`
- `note`
- `created_by_user_id`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

Important:

- `invoice_number` is the KeyCRM order number by default.
- Payment status and document status are separate in UI.
- `payment_due_date` is editable in the registry. If empty, UI may calculate a fallback from `sent_at + 3 days`.
- `status`, `docs_status`, `expected_payment_date`, and `pdf_file_path` are legacy/fallback compatibility fields while UI moves to `payment_status`, `document_status`, `payment_due_date`, and `db_invoice_documents`.

### db_invoice_documents

Purpose:

- Local registry of generated document files for one invoice.
- Allows one invoice/order to have multiple documents:
  - invoice PDF
  - delivery note PDF
  - act PDF
  - future corrected/reissued document versions
- Prevents delivery notes or acts from overwriting the original invoice PDF path.

Source of data:

- Server-side PDF generation from `invoices.php`.
- Future KeyCRM file attachment can store returned KeyCRM file IDs here.

Current/planned fields:

- `id`
- `invoice_id`
- `document_type`
- `document_date`
- `file_path`
- `status`
- `sent_at`
- `signed_at`
- `closed_at`
- `keycrm_file_id`
- `note`
- `created_by_user_id`
- `created_at`
- `updated_at`

Recommended additional fields:

- `document_number`
- `download_count`
- `last_downloaded_at`

Recommended `document_type` values:

- `invoice`
- `delivery_note`
- `act`
- `correction`
- `other`

Recommended `status` values:

- `draft`
- `generated`
- `sent`
- `signed`
- `closed`
- `canceled`
- `problem`

Rules:

- Invoice date and document date are not always the same.
- `invoice_date` is the invoice issue date.
- `document_date` for delivery note / act is the shipment or closing document date.
- One invoice may need both a delivery note and an act if the order contains both goods/products and services.
- For FOP 2 group sellers with `allowed_item_type = products_only`, generated item wording must remain product/goods wording only.
- Service wording must wait until a reviewed FOP 3 group seller with `allowed_item_type = services_allowed` is configured.

### db_invoice_items

Purpose:

- Editable invoice/delivery note lines.
- Stores final wording used in documents.

Source of data:

- Initial copy from KeyCRM product data in `db_orders.raw_json`.
- Later edits from CEO/accountant in `.BRAND DB`.

Main fields:

- `id`
- `invoice_id`
- `source_product_id`
- `item_type`
- `title`
- `unit`
- `quantity`
- `price_uah`
- `amount_uah`
- `sort_order`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

Recommended additional fields:

- `source_product_name`
- `source_product_sku`
- `source_offer_id`
- `source_product_json`
- `tax_mode`
- `vat_rate`
- `vat_amount_uah`

Recommended `item_type` values:

- `product`
- `service`
- `mixed`
- `other`

Reason:

- The system needs to know whether a line is product/goods or service before deciding whether to generate a delivery note, act, or both.
- FOP 2 group sellers must not generate service wording.
- FOP 3 group / service-allowed sellers can later use acts for service lines.

### db_client_companies

Purpose:

- Local cache/model for client companies from KeyCRM.
- Used to group one or more local legal entities for invoices.

Source of data:

- Best-effort copy from `db_orders.raw_json`.
- Later can be expanded by company sync from KeyCRM.

Main fields:

- `id`
- `keycrm_company_id`
- `display_name`
- `keycrm_name`
- `keycrm_title`
- `name`
- `title`
- `manager_id`
- `note`
- `raw_json`
- `synced_at`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

### db_client_contacts

Purpose:

- Local cache/model for buyer/contact person from KeyCRM.
- Buyer/contact is not the same as the invoice legal recipient.

Source of data:

- Best-effort copy from `db_orders.raw_json`.
- Later can be expanded by buyer/contact sync from KeyCRM.

Main fields:

- `id`
- `keycrm_buyer_id`
- `client_company_id`
- `full_name`
- `email`
- `phone`
- `position`
- `note`
- `raw_json`
- `synced_at`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

### db_client_legal_entities

Purpose:

- Local editable legal recipient/payer records for invoices.
- Supports multiple legal entities per client company.

Source of data:

- Created gradually from edited invoice recipient fields.
- KeyCRM remains the raw buyer/company source, but final invoice recipient is an editable local snapshot.

Main fields:

- `id`
- `client_company_id`
- `legal_name`
- `short_name`
- `edrpou`
- `tax_number`
- `iban`
- `bank`
- `legal_address`
- `email`
- `phone`
- `is_default`
- `note`
- `created_at`
- `updated_at`

Pages:

- `invoices.php`

## 3. Required Final Tables

### users

Existing user table.

DB access fields:

- `db_role`
- `db_active`
- `db_password_hash`
- `db_last_login_at`

### db_orders

Synced KeyCRM order cache.

Required fields:

- `keycrm_id`
- `ordered_at`
- `order_month`
- `manager_id`
- `manager_name`
- `manager_email`
- `buyer_id`
- `buyer_name`
- `buyer_email`
- `buyer_phone`
- `company_id`
- `company_name`
- `total_amount_uah`
- `paid_amount_uah`
- `unpaid_amount_uah`
- `payment_status`
- `status_id`
- `status_name`
- `raw_json`
- `synced_at`

### db_order_payments

Planned table for individual payments from KeyCRM.

Fields:

- `id`
- `keycrm_payment_id`
- `keycrm_order_id`
- `payment_method_id`
- `payment_method_name`
- `amount_uah`
- `currency`
- `status`
- `payment_date`
- `description`
- `raw_json`
- `synced_at`

Reason:

`payments_total` is not enough because CEO needs to know what was paid in a selected month. Correct paid-in-month reporting requires individual payment rows by `payment_date`.

### db_order_expenses

Planned table for KeyCRM order-level expenses.

Fields:

- `id`
- `keycrm_expense_id`
- `keycrm_order_id`
- `expense_type_id`
- `expense_type_name`
- `amount_uah`
- `currency`
- `status`
- `payment_date`
- `description`
- `raw_json`
- `synced_at`

### db_payment_obligations

Planned main table for outgoing payment obligations.

This is not just expenses. This is everything `.BRAND` must pay:

- order contractor
- supplier
- rent
- salary
- tax
- subscription
- loan payment
- operational debt
- strategic debt
- other

Fields:

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
title VARCHAR(255) NOT NULL
payee_name VARCHAR(255) NULL
amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0
currency VARCHAR(10) DEFAULT 'UAH'
due_date DATE NULL
status ENUM('planned','paid','moved','canceled','problem') NOT NULL DEFAULT 'planned'
priority ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal'
obligation_type ENUM('order_contractor','supplier','rent','salary','tax','subscription','loan_payment','operational_debt','strategic_debt','other') NOT NULL DEFAULT 'other'
category VARCHAR(100) NULL
keycrm_order_id INT UNSIGNED NULL
order_number VARCHAR(50) NULL
invoice_id INT UNSIGNED NULL
is_recurring TINYINT(1) NOT NULL DEFAULT 0
recurrence_type ENUM('none','monthly','weekly','custom') NOT NULL DEFAULT 'none'
repeat_day TINYINT UNSIGNED NULL
repeat_until DATE NULL
is_strategic TINYINT(1) NOT NULL DEFAULT 0
total_debt_amount_uah DECIMAL(14,2) NULL
paid_amount_uah DECIMAL(14,2) NOT NULL DEFAULT 0
note TEXT NULL
created_by_user_id INT UNSIGNED NULL
paid_at DATETIME NULL
moved_from_due_date DATE NULL
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

Indexes:

- `due_date`
- `status`
- `obligation_type`
- `is_strategic`
- `keycrm_order_id`
- `invoice_id`

### db_buyers

Planned buyer cache from KeyCRM.

Fields:

- `keycrm_buyer_id`
- `full_name`
- `phone`
- `email`
- `company_id`
- `manager_id`
- `raw_json`
- `synced_at`

### db_companies

Planned company cache from KeyCRM.

Fields:

- `keycrm_company_id`
- `name`
- `title`
- `bank_account`
- `manager_id`
- `raw_json`
- `synced_at`

### db_client_companies

Local company model used by invoices.

Fields:

- `keycrm_company_id`
- `name`
- `title`
- `manager_id`
- `raw_json`
- `synced_at`

### db_client_contacts

Local contact person model used by invoices.

Fields:

- `keycrm_buyer_id`
- `client_company_id`
- `full_name`
- `email`
- `phone`
- `position`
- `raw_json`
- `synced_at`

### db_client_legal_entities

Local invoice recipient/payer model.

Fields:

- `client_company_id`
- `legal_name`
- `short_name`
- `edrpou`
- `tax_number`
- `iban`
- `bank`
- `legal_address`
- `email`
- `phone`
- `is_default`
- `note`

Rules:

- Several legal entities can belong to one client company.
- Legal entities are built gradually from edited invoices.
- KeyCRM company/buyer data is raw source data; invoice recipient is an editable snapshot.

### db_sales_targets

Effective-date targets.

Fields:

- `target_type`
- `manager_name`
- `amount_uah`
- `effective_from`
- `note`
- `created_by_user_id`

### db_expenses

Internal `.BRAND DB` expenses.

Decision:

- `db_payment_obligations` is the main future table for outgoing payments.
- `db_expenses` can remain if it already exists.
- New outgoing payment functionality should use `db_payment_obligations`.
- KeyCRM order expenses are separate and should be cached in `db_order_expenses`.

Stores:

- operational payments
- subscriptions
- loan payments
- operational debts
- strategic debts

Fields:

- `status`
- `due_date`
- `repeat_day`
- `repeat_until`
- `is_strategic`

Status values:

- `planned`
- `paid`
- `canceled`

### db_our_companies

Our seller companies / FOP / TOV.

Fields:

- `legal_name`
- `short_name`
- `edrpou`
- `iban`
- `bank`
- `address`
- contacts
- `tax_mode`
- `allowed_item_type`
- `is_active`

Important:

- `allowed_item_type = 'products_only'` means invoice item titles must use product/goods wording only.
- Service wording is allowed later only for reviewed seller companies with `allowed_item_type = 'services_allowed'`.

## 3A. Invoice / Document Data Gap Analysis

Current invoice work is already beyond a single `pdf_file_path`.

CEO/accountant now need to control:

- whether invoice was sent
- when payment is expected
- whether payment is overdue
- whether invoice is paid
- whether closing documents were sent
- whether closing documents are signed/closed
- which files exist for the order:
  - invoice
  - delivery note
  - act
  - future corrected documents

### Data that should remain in `db_invoices`

`db_invoices` should stay the main commercial invoice / registry row.

Keep or add:

- `expected_payment_date`
- `payment_terms_days`
- `sent_channel`
- `sent_to_email`
- `last_reminder_at`
- `reminder_count`
- `next_follow_up_at`
- `assigned_user_id`
- `status_changed_at`
- `status_changed_by_user_id`

Why:

- CEO needs to see who must be reminded and when.
- A static status is not enough; follow-up date and reminder history are operational control data.
- `payment_terms_days` allows different payment periods without hardcoding `+3 days`.

Recommended safe additive columns:

```sql
ALTER TABLE db_invoices
  ADD COLUMN payment_terms_days SMALLINT UNSIGNED NULL,
  ADD COLUMN sent_channel ENUM('none','email','viber','telegram','phone','other') NOT NULL DEFAULT 'none',
  ADD COLUMN sent_to_email VARCHAR(190) NULL,
  ADD COLUMN last_reminder_at DATETIME NULL,
  ADD COLUMN reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN next_follow_up_at DATE NULL,
  ADD COLUMN assigned_user_id INT UNSIGNED NULL,
  ADD COLUMN status_changed_at DATETIME NULL,
  ADD COLUMN status_changed_by_user_id INT UNSIGNED NULL;
```

Do not add all of these immediately unless the UI is ready to use them.

Minimum recommended next addition:

- `payment_terms_days`
- `next_follow_up_at`
- `last_reminder_at`
- `reminder_count`

### Data that should move to / be stored in `db_invoice_documents`

One invoice can have many generated documents.

The document table should become the source for the PDF column in `Реєстр рахунків`.

Recommended safe additive columns:

```sql
ALTER TABLE db_invoice_documents
  ADD COLUMN document_number VARCHAR(80) NULL,
  ADD COLUMN status ENUM('draft','generated','sent','signed','closed','canceled','problem') NOT NULL DEFAULT 'generated',
  ADD COLUMN sent_at DATETIME NULL,
  ADD COLUMN signed_at DATETIME NULL,
  ADD COLUMN closed_at DATETIME NULL,
  ADD COLUMN keycrm_file_id VARCHAR(80) NULL,
  ADD COLUMN download_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN last_downloaded_at DATETIME NULL,
  ADD COLUMN note TEXT NULL;
```

Why:

- Invoice PDF, delivery note PDF, and act PDF should not overwrite each other.
- Delivery note / act date may differ from invoice date.
- Later KeyCRM file attachment needs a per-file `keycrm_file_id`.
- CEO/accountant may need to know whether a specific act/delivery note is sent or closed.

### Data that should be added to invoice items

The system needs to decide whether an order should generate:

- only delivery note
- only act
- both delivery note and act

This cannot be done reliably from title text alone.

Recommended safe additive columns:

```sql
ALTER TABLE db_invoice_items
  ADD COLUMN item_type ENUM('product','service','mixed','other') NOT NULL DEFAULT 'product',
  ADD COLUMN source_product_name VARCHAR(255) NULL,
  ADD COLUMN source_product_sku VARCHAR(120) NULL,
  ADD COLUMN source_offer_id INT UNSIGNED NULL,
  ADD COLUMN source_product_json LONGTEXT NULL;
```

Rules:

- `product` lines can generate delivery notes.
- `service` lines can generate acts only when seller company allows services.
- FOP 2 group / `products_only` seller must force product wording and should not generate service lines.
- If order contains both product and service lines, generate both documents later:
  - delivery note for products
  - act for services

### Data that should go into a small audit table

For finance operations, silent status changes are risky.

Recommended planned table:

```sql
db_invoice_events
- id
- invoice_id
- document_id NULL
- event_type
- old_value
- new_value
- note
- created_by_user_id
- created_at
```

Recommended event types:

- `status_changed`
- `expected_payment_date_changed`
- `reminder_sent`
- `document_generated`
- `document_sent`
- `document_closed`
- `payment_marked_paid`
- `problem_marked`

Why:

- CEO can see who changed status and when.
- Accountant can track reminders and document closing.
- Bugs or accidental status changes are easier to diagnose.

### What not to add yet

Do not add a full accounting ledger yet.

Out of scope for the next invoice step:

- VAT tax invoices
- official accounting journal
- bank statement import
- automated KeyCRM payment writes
- electronic signature integration
- automatic file attachment to KeyCRM

These should be planned separately so `.BRAND DB` remains a fast CEO Money Cockpit, not an ERP.

## 4. Dashboard Data Needs

Monthly plan:

- Source: `db_sales_targets`
- Rule: active company target where `effective_from <= last day of selected month`

Manager plan:

- Source: `db_sales_targets`
- Rule: active manager target where `effective_from <= last day of selected month`

Sales fact:

- Source: `db_orders`
- Rule: `order_month = selected month`

Paid:

- v1 source: `db_orders.paid_amount_uah`
- Future correct source: `db_order_payments` grouped by `payment_date`

Unpaid:

- Source: `db_orders`
- Rule: `unpaid_amount_uah > 0`

Receivables:

- Source: `db_orders`
- Rule: `unpaid_amount_uah > 0` across all months

Manager receivables:

- Source: `db_orders`
- Rule: group unpaid rows by `manager_name`

We owe this month:

- Main source: `db_payment_obligations`
- Legacy fallback if payment obligations are not implemented yet: `db_expenses`
- Rule: due in selected month, unpaid amount remains, and `is_strategic = 0`

Strategic debts:

- Main source: `db_payment_obligations`
- Legacy fallback if payment obligations are not implemented yet: `db_expenses`
- Rule: `is_strategic = 1` or `obligation_type = 'strategic_debt'`

Invoices sent / not sent:

- Source: `db_invoices`
- Rule: use `status`, `sent_at`, and document status fields

Documents closed:

- Source: `db_invoices`
- Rule: use `docs_status` and `docs_closed_at`

Overdue obligations:

- Source: `db_payment_obligations`
- Rule: `status IN ('planned','moved','problem')` and `due_date < today` and `is_strategic = 0`

This week obligations:

- Source: `db_payment_obligations`
- Rule: `status IN ('planned','moved','problem')` and `due_date` is inside the current week and `is_strategic = 0`

Strategic debt total:

- Source: `db_payment_obligations`
- Rule: `is_strategic = 1` or `obligation_type = 'strategic_debt'`

Order-linked obligations:

- Source: `db_payment_obligations`
- Rule: `keycrm_order_id IS NOT NULL`

Expected net receivables:

- Source: `db_orders` plus `db_payment_obligations`
- Formula:

```text
expected_net_cash = unpaid_amount_uah - SUM(planned unpaid obligations linked to the same keycrm_order_id)
```

## 5. Outgoing Payment Obligations

Outgoing payment obligations are everything `.BRAND` must pay, not only classic expenses.

Examples:

- order contractor
- supplier
- rent
- salary
- tax
- subscription
- loan payment
- operational debt
- strategic debt
- other

Main table:

```text
db_payment_obligations
```

Purpose:

- Give the CEO a daily view of who `.BRAND` must pay, when, how much, and why.
- Separate strategic debt from operational cash pressure.
- Link outgoing obligations to KeyCRM orders when the payment belongs to a specific order.
- Keep internal payment planning in `.BRAND DB`, not only in KeyCRM.

Status model:

- `planned`: payment is expected and unpaid.
- `paid`: payment is complete.
- `moved`: due date was moved.
- `canceled`: obligation no longer applies.
- `problem`: requires CEO/accountant attention.

Priority model:

- `low`
- `normal`
- `high`
- `critical`

## 6. Cash Calendar UI

Recommended UI:

Use a Payment Timeline, not a classic month calendar.

Reason:

- CEO needs to scan cash pressure fast.
- A classic calendar hides money inside date cells.
- Timeline groups make overdue, today, and this week obvious.

Groups:

- Overdue / `Прострочено`
- Today / `Сьогодні`
- Tomorrow / `Завтра`
- This week / `Цей тиждень`
- Next week / `Наступний тиждень`
- Later / `Пізніше`
- Strategic debts / `Стратегічні борги`

Each row should show:

- due date
- amount
- payee
- obligation type
- linked order number if any
- status
- actions: mark paid, move date, edit

No drag-and-drop in v1.

Use simple buttons:

- Paid
- Move
- Edit

Quick move options:

- `+1 day`
- `+3 days`
- `+7 days`
- next Monday
- custom date

## 7. Order-Linked Cash Control

If an outgoing obligation is linked to a KeyCRM order, the CEO must see:

- client owes us
- we owe for this order
- expected net cash from order

Formula:

```text
expected_net_cash = unpaid_amount_uah - SUM(planned unpaid obligations linked to the same keycrm_order_id)
```

Dashboard usage in CEO Money Cockpit:

- We owe this month
- Overdue obligations
- This week obligations
- Strategic debt total
- Order-linked obligations
- Expected net receivables

## 8. What Replaces db_expenses?

Decision:

- Use `db_payment_obligations` as the main table for outgoing payments.
- `db_expenses` can remain only if already created.
- New functionality should use `db_payment_obligations`.
- KeyCRM order expenses are separate and should be cached in `db_order_expenses`.

Migration approach later:

- Do not delete `db_expenses`.
- Add `db_payment_obligations` safely.
- Keep dashboard fallback reads from `db_expenses` only until payment obligations are implemented.
- Move UI from `expenses.php` conceptually toward payment obligations timeline.

## 9. What Belongs Where

KeyCRM:

- orders
- payments
- order expenses
- buyers
- companies
- products
- files

`.BRAND DB`:

- sales targets
- manager targets
- internal payment obligations
- strategic debts
- invoice drafts
- edited invoice lines
- document statuses
- CEO notes
- our seller companies

## 10. KeyCRM API Mapping

Orders:

- Read from KeyCRM.
- Update only carefully and only after explicit approval.
- Never delete from KeyCRM from `.BRAND DB`.

Payments:

- Read from KeyCRM.
- Create/update later only with explicit confirmation and a reviewed workflow.
- Needed for correct paid-in-selected-month reporting.

Expenses:

- Read order-level expenses from KeyCRM.
- Create/update later only if order-related and explicitly approved.

Files:

- Read/upload/attach/detach later.
- Current invoice version does not attach files to KeyCRM.

Products:

- Read for invoice item drafts and order inspection.
- Update/delete may be possible in KeyCRM, but is not used for the money dashboard now.

Buyers:

- Read for dashboard and invoice snapshots.
- Update may be possible later, but is not part of current money dashboard scope.

Companies:

- Read for dashboard and invoice snapshots.
- Update may be possible later, but is not part of current money dashboard scope.

Custom fields:

- Useful later for control fields such as document status, internal processing flags, or manager notes.
- Must be planned carefully so `.BRAND DB` remains the source for CEO control data unless there is a clear reason to mirror specific fields to KeyCRM.

## 11. What Must NOT Be Stored Only In KeyCRM

These must live in `.BRAND DB`:

- sales targets
- manager plans
- payment obligations
- strategic debts
- internal expense schedule / cash timeline
- invoice edited titles
- document workflow statuses
- CEO notes

Reason:

These are internal management/control records, not pure CRM order data. They must stay available for fast dashboard reads and should not depend on live KeyCRM API calls.

## 12. Open Decisions

- How to map `.BRAND DB` manager users to KeyCRM managers.
- Whether to sync all historical orders or keep current/previous month plus receivables-specific history.
- Whether to sync individual payments now or keep it as v2.
- Whether to attach generated invoice/delivery PDFs back to KeyCRM.
- How to handle VAT seller companies later.
- How to handle service invoices for FOP 3 group.
- Whether to create custom fields in KeyCRM for document control.
- Whether old `db_expenses` rows should be migrated into `db_payment_obligations`.
- Whether order contractors should be created manually or synced from KeyCRM/order production data.

## 13. Final Checklist Before v1 Launch

- Targets work with `effective_from`.
- All unpaid logic includes `part_paid`.
- Receivables show unpaid orders across all months.
- Payments by `payment_date` are implemented or explicitly documented as v2.
- Invoice PDF works.
- Delivery note PDF works.
- No `services` wording for FOP 2 group seller companies.
- Document status works.
- Outgoing obligations are separated into operational and strategic.
- Payment timeline groups overdue/today/tomorrow/this week/next week/later/strategic debts.
- Order-linked obligations show expected net cash.
- Dashboard loads fast.
- KeyCRM token remains server-side only.
