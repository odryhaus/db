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
- Moved registry work into one compact table: edit, PDF download, payment status, payment deadline, and delete are handled from `Реєстр рахунків`.
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
8. Confirm PDF download and delete actions are available in the registry action column.
