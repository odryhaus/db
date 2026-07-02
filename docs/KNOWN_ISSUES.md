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
- Expenses are a foundation only; no recurring schedule expansion or payment audit trail exists yet.
- Additive finance tables are created with `CREATE TABLE IF NOT EXISTS` from application code.
- Sync is manual CEO-only; no cron/automatic sync exists yet.
- Sync scans bounded recent pages only; it does not scan all history.

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
