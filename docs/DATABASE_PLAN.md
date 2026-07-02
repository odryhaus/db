# Database Plan

## 1. Core Principle

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
- internal expenses
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

- Editable invoice/delivery note document header.
- Stores local document status and document closing status.

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
- `buyer_id`
- `buyer_company_id`
- `buyer_display_name`
- `buyer_edrpou`
- `buyer_address`
- `buyer_email`
- `buyer_phone`
- `total_amount_uah`
- `vat_mode`
- `vat_amount_uah`
- `total_with_vat_uah`
- `payment_purpose`
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

### db_invoices

Editable invoice/delivery document header.

Fields:

- `keycrm_order_id`
- `invoice_number`
- `invoice_date`
- `document_type`
- `seller_company_id`
- buyer/company data snapshot
- `total_amount_uah`
- VAT mode
- `status`
- `sent_at`
- `paid_at`
- `docs_type`
- `docs_status`
- `docs_closed_at`
- `pdf_file_path`
- `keycrm_file_id`
- `note`

### db_invoice_items

Editable invoice lines.

Fields:

- `invoice_id`
- `source_product_id`
- `title`
- `unit`
- `quantity`
- `price_uah`
- `amount_uah`
- `sort_order`

### db_files

Planned local file registry.

Fields:

- `id`
- `file_type`
- `local_path`
- `keycrm_file_id`
- `keycrm_order_id`
- `uploaded_to_keycrm_at`
- `created_at`

File types:

- `invoice_pdf`
- `delivery_note_pdf`
- `other`

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

- Source: `db_expenses`
- Rule: due in selected month and `is_strategic = 0`

Strategic debts:

- Source: `db_expenses`
- Rule: `is_strategic = 1`

Invoices sent / not sent:

- Source: `db_invoices`
- Rule: use `status`, `sent_at`, and document status fields

Documents closed:

- Source: `db_invoices`
- Rule: use `docs_status` and `docs_closed_at`

## 5. KeyCRM API Mapping

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

## 6. What Must NOT Be Stored Only In KeyCRM

These must live in `.BRAND DB`:

- sales targets
- manager plans
- strategic debts
- internal expense schedule
- invoice edited titles
- document workflow statuses
- CEO notes

Reason:

These are internal management/control records, not pure CRM order data. They must stay available for fast dashboard reads and should not depend on live KeyCRM API calls.

## 7. Open Decisions

- How to map `.BRAND DB` manager users to KeyCRM managers.
- Whether to sync all historical orders or keep current/previous month plus receivables-specific history.
- Whether to sync individual payments now or keep it as v2.
- Whether to attach generated invoice/delivery PDFs back to KeyCRM.
- How to handle VAT seller companies later.
- How to handle service invoices for FOP 3 group.
- Whether to create custom fields in KeyCRM for document control.

## 8. Final Checklist Before v1 Launch

- Targets work with `effective_from`.
- All unpaid logic includes `part_paid`.
- Receivables show unpaid orders across all months.
- Payments by `payment_date` are implemented or explicitly documented as v2.
- Invoice PDF works.
- Delivery note PDF works.
- No `services` wording for FOP 2 group seller companies.
- Document status works.
- Expenses are separated into operational and strategic.
- Dashboard loads fast.
- KeyCRM token remains server-side only.
