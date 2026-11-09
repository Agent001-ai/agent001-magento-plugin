# Agent001 Assistant for Magento 2

AI shopping assistant for Magento Open Source and Adobe Commerce. The module connects the store to Agent001, assigns one agent per store view, keeps each store view's catalog synchronized and adds the chat widget to the storefront.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.6 – 2.4.9
- PHP 8.1 or later, as supported by your Magento release, with curl, intl and mbstring
- Works with and without Multi-Source Inventory
- A running background worker (`bin/magento agent001:work`, recommended) or the Magento cron

## Installation

```sh
composer require agent001/module-assistant
bin/magento module:enable Agent001_Assistant
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:clean
```

Run the worker under systemd or Supervisor with automatic restart (`autorestart=true` / `Restart=always`). It exits after `--max-time` seconds (default 3600) or above 512 MB of memory and is started again by the process manager:

```sh
bin/magento agent001:work
```

Without a worker, the Magento cron job `agent001_work` (cron group `agent001`, separate process) processes the queue once a minute.

A nightly full synchronization runs automatically through the Magento cron (job `agent001_nightly`, hourly check, once per day between 01:00 and 04:59 store time). No system cron entry is needed beyond the standard Magento cron.

## Configuration

Open **Marketing → Agent001 Assistant** in the Magento Admin and follow the setup wizard: connect the store, choose agents, synchronize the catalog and check the chat. Access is controlled by the ACL resource `Agent001_Assistant::settings`.

## Console commands

| Command | Purpose |
| --- | --- |
| `agent001:work` | Process the synchronization queue continuously |
| `agent001:work --max-time=<seconds>` | Exit after the given time (default 3600) |
| `agent001:work --once` | Process up to 20 pending jobs and exit |
| `agent001:work --retry-failed --once` | Retry failed jobs |
| `agent001:sync [--store=<code\|id>]` | Queue a full catalog export |
| `agent001:sync --due` | Queue the nightly export when due (the `agent001_nightly` cron job does this) |

## Storefront and security

- The chat widget is not loaded on the payment pages of Magento checkout: one-page checkout (`checkout_index_index`) and multishipping billing (`multishipping_checkout_billing`). Third-party checkout modules with their own layout handle (for example `onestepcheckout_index_index`) are not covered; remove the widget there in your theme, otherwise add the loader to your payment page script inventory (PCI DSS 4.0, 6.4.3 and 11.6.1). Example `app/design/frontend/<Vendor>/<theme>/Magento_Theme/layout/onestepcheckout_index_index.xml`:

```xml
<page><body><referenceBlock name="agent001.widget" remove="true"/></body></page>
```

- Order lookups accept only POST with the order number and e-mail address in a JSON body, so the e-mail address does not appear in URLs or access logs. Chat lookups are limited to 20 per minute per visitor IP. After 5 failed attempts an e-mail address is blocked for one hour, and so is an order number for the visitor IP that tried it. Behind Varnish, a CDN or a load balancer make sure the real client IP reaches Magento (nginx `real_ip` or `RemoteAddress` arguments in `di.xml`).

## Limits

A full synchronization sends up to 50,000 products per store view and must finish within 2 hours.

## Support

Contact form: https://agent001.ai/contact/ · E-mail: biuro@okinet.pl

## License

Proprietary. © Okinet s.c. (Agent001).
