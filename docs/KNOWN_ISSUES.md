# Known Issues

## PHP Lint Not Run Locally

This Codex environment does not have a `php` binary installed, so `php -l` could not be run locally.

Run before deploy:

```sh
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

## Real Config Is Manual

`config/config.php` is intentionally ignored by Git and must be created manually on the server.

Required production setting:

```php
'base_path' => '/db',
```

The workflow deploys `config/.htaccess` but excludes `config/config.php`.

## Setup Tool Removed

`setup-ceo.php` was used only for one-time CEO setup and has been removed from the repository. It is also excluded from deployment.

If CEO access is lost later, recover access directly through the database or a reviewed temporary recovery tool.

## Dashboard Values Depend On Sync

Money Dashboard now reads from `db_orders`, but values depend on manual sync being run successfully and on the manually created cache tables matching the expected columns.

Known limitations:

- Month picker is simple `YYYY-MM` only.
- Canceled/deleted orders are excluded by simple status/payment-status text matching.
- Buyer/company data is best-effort and depends on KeyCRM include support.
- Manager users currently see the shared dashboard because reliable local manager mapping has not been confirmed.
- Old `db_monthly_targets` / `db_manager_targets` data is not migrated automatically; new target logic uses `db_sales_targets`.
- Expenses are a foundation only; no recurring schedule expansion or payment audit trail exists yet.
- Additive finance tables are created with `CREATE TABLE IF NOT EXISTS` from application code.
- New background sync orchestration exists, but production cron must still be configured.
- Dashboard has a CEO-only web tick fallback for queued jobs, but cron is still the preferred reliable sync runner.
- Without cron, refresh can still take longer because the browser processes queued jobs in small batches while the page is open.
- Sync jobs have auto-timeout recovery, but repeated failures should still be inspected in `db_sync_jobs.error_message`.
- Sync scans bounded delta/recent pages only; it does not scan all history.
- KeyCRM `filter[updated_between]` is confirmed from OpenAPI, but production runtime behavior still needs verification.
- Webhook support was not found in the uploaded OpenAPI file.

## Invoices v0.1 Limitations

Invoices are a draft/document foundation only.

Known limitations:

- Invoice data is copied from `db_orders.raw_json`; it is not a live KeyCRM document.
- Generated status changes stay local in `.BRAND DB` and are not written to KeyCRM.
- Generated files are not attached to KeyCRM yet.
- VAT is not implemented; current documents are no-VAT only.
- Primary PDF rendering uses `dompdf/dompdf`, installed during GitHub Actions deploy. `wkhtmltopdf` is only a fallback if available.
- `storage/invoices` contains generated document files and should be treated as sensitive business data; direct web access is blocked with `.htaccess`, but hosting behavior should be verified after deploy.
- Buyer/company extraction is best-effort until more real KeyCRM order JSON examples are reviewed.
- Product item extraction depends on KeyCRM product field names stored in `raw_json`.
- Services are intentionally not generated for seller companies marked `products_only`.

## No Audit Log Yet

Changes made on `users.php` are not written to a separate audit table.

## No Password Policy Yet

The CEO page can set a new password, but minimum length and complexity rules are not enforced yet.

## No Rate Limiting Yet

Login attempts are not rate-limited in the application layer.

Server-level protection or a later lightweight application throttle is recommended before broader access.

## PHP Version Not Confirmed

The code expects a reasonably modern PHP version. Confirm the production PHP version before launch.

Recommended minimum: PHP 7.4+.

## Invoice Recipient Extraction Still Needs Real Data Review

The invoice page now separates recipient snapshots from contact snapshots, but KeyCRM data can vary by order.

Known risks:

- Some old `db_orders.raw_json` rows may not contain `buyer.company`.
- The optional server-side fallback `/buyer/{id}?include=company` depends on KeyCRM support and production API configuration.
- Existing invoice rows may have old `buyer_*` fields only; UI uses them as fallback, but those rows should be reviewed gradually.
- Local legal entities must be curated by the CEO/accountant and should not be overwritten by sync.

## Client Manager Assignment UI Not Built Yet

The database plan includes local company/contact manager ownership, but the Clients page and bulk reassignment UI are not implemented yet.

## KeyCRM Client Delta Filters Need Production Verification

Client sync sends updated-after style parameters where possible and keeps bounded page limits as fallback. Confirm in production whether KeyCRM company/buyer endpoints honor those filters.

If they do not, delta sync will still be bounded, but it may rescan recent pages rather than true changed-only records.

## Seller Accounts Need Production Review

Seeded seller companies and accounts should be reviewed by CEO/accounting after deploy.

Known gaps:

- VAT 20% invoice PDF templates are not implemented.
- English EUR/USD invoice templates are not implemented.
- `ФОП Кубар Т.О.` USD IBAN is intentionally empty until confirmed.
- Invoice and payment-requisite selectors intentionally hide active accounts with empty IBAN; maintain them in `our_companies.php`.
- Duplicate account rows may exist from earlier/manual account labels. Selectors dedupe by `company_id + currency + IBAN`, and `our_companies.php` marks duplicates for review.
- Duplicate seller company rows may exist from the old English seed. Working selectors dedupe by tax code, and `our_companies.php` marks duplicates for review.
- `payment_requisites.php` creates copyable payment text only; it does not create invoices, payments, or KeyCRM records.

## CEO Money Cockpit v2 Requires Payment Validation

- `dashboard_v2.php` is a preview and should not replace `index.php` until approved.
- Individual payment sync reads payments from order payloads with `include=payments`; no standalone payment-list endpoint was confirmed in the OpenAPI file.
- Runtime validation needs production DB/API data through `payment_sync_check.php`.
- Final chart design should wait until payment reconciliation is confirmed.
- `db_monthly_costs` is a foundation table; operating cost entry UI is not built yet.
- `db_payment_obligations` is the future outgoing payment model; timeline UI is not built yet.
