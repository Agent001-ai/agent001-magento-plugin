# Agent001 Assistant for Magento 2

AI shopping assistant for Magento Open Source and Adobe Commerce. The module connects the store to Agent001, assigns one agent per store view, keeps each store view's catalog synchronized and adds the chat widget to the storefront.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.7 – 2.4.8
- PHP 8.2 or later, as supported by your Magento release, with curl, intl and mbstring
- Works with and without Multi-Source Inventory
- A running background worker: `bin/magento agent001:work`

## Installation

```sh
composer require agent001/module-assistant
bin/magento module:enable Agent001_Assistant
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:clean
```

Run the worker under systemd or Supervisor:

```sh
bin/magento agent001:work
```

Optional nightly full synchronization (hourly cron, runs once per day between 01:00 and 04:59 store time):

```cron
0 * * * * cd /var/www/magento && php bin/magento agent001:sync --due
```

## Configuration

Open **Marketing → Agent001 Assistant** in the Magento Admin and follow the setup wizard: connect the store, choose agents, synchronize the catalog and check the chat. Access is controlled by the ACL resource `Agent001_Assistant::settings`.

## Console commands

| Command | Purpose |
| --- | --- |
| `agent001:work` | Process the synchronization queue continuously |
| `agent001:work --once` | Process up to 20 pending jobs and exit |
| `agent001:work --retry-failed --once` | Retry failed jobs |
| `agent001:sync [--store=<code\|id>]` | Queue a full catalog export |
| `agent001:sync --due` | Queue the nightly export when due |

## License

Proprietary. © Agent001.
