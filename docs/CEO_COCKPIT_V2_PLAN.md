# CEO Money Cockpit v2 Plan

## Principle

The main page is an executive decision screen, not a register.

Use overview -> drill-down.

## Sections

### A. Performance

Shows:
- sales fact
- sales plan
- progress
- gross margin
- operating profit
- manager performance

Sales source:
- `db_orders.order_month`

### B. Cash

Shows:
- cash received by payment date
- payments from current-month orders
- payments from previous orders
- total receivables across all months
- operational obligations
- cash forecast

Cash source:
- `db_order_payments.payment_date`

### C. Financial Health

Shows:
- strategic debts
- long-term obligations
- repayment pressure separated from operational payments

Strategic debt must not automatically reduce current-month operating profit.

## Preview page

`dashboard_v2.php`

The current production `index.php` remains unchanged until explicit CEO approval.

## Detail pages later

Planned:
- `sales.php`
- `cash.php`
- `receivables.php`
- `managers.php`
- `payments.php`
- `profit.php`
- `financial_health.php`

## Data APIs

Created API endpoints:
- `api/dashboard_summary.php`
- `api/dashboard_sales_cash.php`
- `api/dashboard_payment_cohort.php`
- `api/dashboard_profit.php`
- `api/dashboard_cash_forecast.php`
- `api/dashboard_aging.php`
- `api/dashboard_managers.php`
- `api/dashboard_attention.php`

These read local DB only.
