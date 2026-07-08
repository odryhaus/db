# Implementation Report

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
