# KeyCRM Realtime Audit

Date: 2026-07-17

## Source inspected

Local official OpenAPI file:

`/Users/alla/Downloads/open-api.yml`

## Webhooks / events

No documented webhook, callback, subscription, event, or hook endpoint was found in the inspected OpenAPI file.

Conclusion:
- true realtime webhooks are not confirmed from the available official spec
- current safest implementation is server-side polling/delta sync

## Delta support

The OpenAPI file shows `filter[updated_between]` examples for `/order`, buyers, companies and other resources.

Current `sync_core.php` uses:

`filter[updated_between]=from,to`

with a 120-second overlap from last successful sync.

## Recommended update model

Use:
- background worker for frequent delta polling
- manual CEO button "Оновити все" to queue all changes
- unpaid order refresh to recheck previously unpaid orders directly

Do not:
- call KeyCRM from browser
- load all historical CRM pages on dashboard open
- invent undocumented endpoints

## Open question

If KeyCRM has webhook support outside this OpenAPI file, it should be confirmed from official KeyCRM documentation/support before implementing.
