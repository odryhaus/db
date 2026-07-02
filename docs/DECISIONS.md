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

## No Schema Changes

Do not add migrations or modify database structure in this milestone.

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

## Money Dashboard v0.3 Metrics

Selected month controls monthly sales plan/fact metrics.

Decision:

- Monthly sales, paid, unpaid, order count, remaining target, progress, manager summary, and daily required sales use `order_month = YYYY-MM`.
- Total receivables / `Нам повинні` uses all unpaid orders across all months.
- Receivables are paginated at 25 rows per page.
- Old `orders` table remains ignored.
- No charts are added yet.
- No outgoing payments are added yet.
- Manager-specific filtering is deferred until KeyCRM manager mapping to local users is confirmed.

## Out Of Scope

The following are intentionally excluded:

- Full CRM integration.
- Browser-side CRM loading.
- Full historical order sync.
- Charts.
- Payments.
- Debt tracking.
- Database migrations.
