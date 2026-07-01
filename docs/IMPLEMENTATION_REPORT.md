# Implementation Report

## Current Status

Production `/db` authentication is live:

- Auth works.
- CEO login works.
- CEO users page works.
- Logout works.
- Production `setup_key` was disabled manually.
- Production `setup-ceo.php` was manually deleted.

This update adds Money Dashboard v0.1 as a placeholder protected home page.

## Files Changed

- `.github/workflows/deploy.yml`
- `README.md`
- `assets/app.css`
- `config/config.example.php`
- `docs/DECISIONS.md`
- `docs/IMPLEMENTATION_REPORT.md`
- `docs/KNOWN_ISSUES.md`
- `docs/NEXT_STEPS.md`
- `index.php`
- `setup-ceo.php` removed

## Setup Page Removed

`setup-ceo.php` was removed from the local project and must not be deployed again.

Additional deployment guard:

```text
setup-ceo.php
```

was added to the GitHub Actions FTP exclude list.

Production already has `setup_key` disabled and `setup-ceo.php` deleted manually. Do not restore it.

## Money Dashboard v0.1

`index.php` now shows a money-focused placeholder dashboard after login.

Visible content:

- Project name: `.BRAND DB`
- Logged-in user name/email
- User role
- Current month
- Monthly target: `4,000,000 UAH`
- Sales fact: `0 UAH`
- Paid: `0 UAH`
- Unpaid: `0 UAH`
- We owe: `0 UAH`
- Remaining to target: `4,000,000 UAH`
- Progress: `0%`
- Main next action: `Connect orders data source`

Access remains unchanged:

- `ceo`, `accountant`, and `manager` can view `index.php`.
- Only `ceo` sees the `users.php` link.

## What Remains Placeholder

- Sales fact.
- Paid amount.
- Unpaid amount.
- We owe.
- Remaining to target.
- Progress percentage.
- Current month calculations beyond display label.

No CRM, payments, debts, charts, API calls, or database schema changes were added.

## Authentication Review

The current auth implementation still uses:

- `users.email` for login.
- `users.db_password_hash` for password verification.
- `users.db_role` for role checks.
- `users.db_active` for access status.
- PHP sessions.
- `password_verify()`.
- Prepared statements.
- Escaped HTML output.

It does not use:

- `users.username`
- `users.password`

## Problems Found

- `setup-ceo.php` still existed locally even though production setup was complete. It has now been removed.
- The deploy workflow did not explicitly exclude `setup-ceo.php`; it now does.
- PHP linting still cannot be run in this local Codex environment because no `php` binary is installed.

## Recommendations

- Confirm GitHub Actions deploy completes after push.
- Confirm `https://bph.com.ua/db/setup-ceo.php` is unavailable after deploy.
- Confirm `https://bph.com.ua/db/login.php` still loads.
- Confirm CEO, accountant, and manager can open the money dashboard.
- Confirm only CEO sees and can access `users.php`.
- Run `php -l` on the server if available.

## Technical Debt

- Money values are static placeholders.
- No real order source is connected.
- No payment/debt calculations exist.
- No dashboard audit or data freshness metadata exists.
- No login rate limiting exists.
- No automated PHP lint/test pipeline exists.

## Files To Review Manually

- `index.php`
- `assets/app.css`
- `.github/workflows/deploy.yml`
- `docs/NEXT_STEPS.md`
- Production `config/config.php`, only to confirm it was not overwritten.

## Deploy Workflow

Workflow:

```text
.github/workflows/deploy.yml
```

Deploy target:

```text
domains/bph.com.ua/public_html/public/db/
```

Secrets used:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

Files excluded from deploy:

- `**/.git*`
- `**/.git*/**`
- `**/.github/**`
- `**/.DS_Store`
- `docs/**`
- `README.md`
- `config/config.php`
- `setup-ceo.php`
- `*.log`
- `*.tmp`

## How To Test After Deploy

1. Open `https://bph.com.ua/db/login.php`.
2. Log in as CEO.
3. Confirm `index.php` shows Money Dashboard v0.1.
4. Confirm the first screen shows `4,000,000 UAH`.
5. Confirm placeholder values are `0 UAH` and progress is `0%`.
6. Confirm CEO sees the `Users` link.
7. Log out and log in as manager/accountant if available.
8. Confirm manager/accountant can view dashboard but do not see the `Users` link.
9. Confirm `https://bph.com.ua/db/setup-ceo.php` is unavailable.

## Risks / Open Questions

- GitHub Actions deploy status must be checked after push.
- PHP lint has not been run locally.
- Real dashboard calculations depend on inspecting existing local database tables.
- The next milestone should avoid CRM/API work until local order data is understood.
