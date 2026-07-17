# CEO Money Cockpit Validation

Date: 2026-07-17

## Validation status

Code-level validation:
- formulas reviewed from actual PHP files
- OpenAPI file inspected locally
- `dashboard_v2.php` created as preview
- `payment_sync_check.php` created for CEO-only reconciliation

Runtime validation:
- not completed by Codex because production DB and KeyCRM credentials are not available locally

## What Alla should test after deploy

1. Open:
   `https://bph.com.ua/db/dashboard_v2.php`

2. Confirm the current `index.php` still works:
   `https://bph.com.ua/db/index.php`

3. Open payment check:
   `https://bph.com.ua/db/payment_sync_check.php?order=9124`

4. Validate at least these cases:
   - fully paid order
   - partially paid order
   - unpaid order
   - old order paid this month
   - recently changed KeyCRM order

5. Copy back any mismatch from the validation page.

## Approval required before

- replacing `index.php` with `dashboard_v2.php`
- removing old dashboard blocks
- making final chart design
- turning legacy `db_expenses` page into the new payments timeline
- changing sync frequency on production cron
