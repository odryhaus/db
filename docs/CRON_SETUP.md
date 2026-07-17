# Cron Setup

## Purpose

Near-real-time sync must run outside the browser. The CEO button only queues jobs; cron processes them.

## Recommended Commands

Run every minute to enqueue delta jobs:

```sh
/usr/bin/php /home/qkbbstge/domains/bph.com.ua/public_html/public/db/cron/enqueue_delta.php
```

Run every minute to process one queued job:

```sh
/usr/bin/php /home/qkbbstge/domains/bph.com.ua/public_html/public/db/cron/sync_worker.php
```

If hosting allows multiple cron lines, run the worker twice per minute or add a second identical worker command. The worker claims one job at a time and skips duplicate running jobs of the same type.

The dashboard also has a CEO-only web fallback that processes queued jobs while the page is open, but cron is still recommended for reliable near-real-time updates.

## Manual CEO Refresh

The CEO can click `Оновити все` on the dashboard. That creates one parent sync job with child jobs for orders, payments, companies, buyers, order expenses, and statuses.

## Safety

- Scripts are CLI-only.
- KeyCRM token stays in `config/config.php`.
- Browser never receives the KeyCRM token.
- Full historical backfill is not automatic.

## Backfill

Use explicit manual tools for initial imports and reviewed backfills. Do not run all-history imports automatically from cron.
