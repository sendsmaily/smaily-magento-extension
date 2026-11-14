# Smaily Connect for Magento 2

[![CI](https://github.com/sendsmaily/smaily-magento-extension/actions/workflows/ci.yaml/badge.svg?branch=master)](https://github.com/sendsmaily/smaily-magento-extension/actions/workflows/ci.yaml)

[Smaily](https://smaily.com) email marketing, automations and Campaign
Intelligence for Magento 2, Adobe Commerce and Mage-OS. Feature-aligned
with the Smaily Connect plugins for WooCommerce and Shopify.

## Features

- **Contact synchronization** — near-real-time, two-way: new contacts
  flow to Smaily instantly; unsubscribes in Smaily mirror back to Magento.
- **Contact sync modes** — lawful-basis presets: subscribers only (consent,
  default), all customers (legitimate interest), or checkout opt-in only.
- **Marketing automations** — welcome, first order and abandoned cart
  events trigger your Smaily workflows, with per-language routing for
  multilingual stores.
- **Abandoned cart** — configurable cutoff, rich product payloads and a
  secure recovery link that restores the exact cart.
- **Checkout opt-in** — a newsletter checkbox in the checkout payment step
  (guests and customers, double opt-in respected).
- **Product RSS feed** — for the Smaily template editor, with category,
  limit and sort parameters and storefront-accurate pricing.
- **Campaign Intelligence** *(optional)* — catalog, customer, order and
  browse data power personalized recommendations, attribution and
  engine-run automations (replenishment, win-back, …); a CMS widget shows
  each shopper their recommendations on the store's pages.
- **Operational visibility** — durable delivery queues with automatic
  retries, admin event logs with one-click retry, health notices, and
  chunked historical imports that never block live traffic.
- **Privacy-first** — encrypted credentials, GDPR export/erase tooling and
  a shopper personalization opt-out page.
- **Installation report** — when a Smaily account is connected, the
  extension sends Smaily an installation report when you save the settings,
  once a day, and when you remove a connection or remove the module,
  authenticated with the connected Smaily account's API credentials, which
  Smaily only checks and never stores: the store addresses, the Magento,
  PHP and extension versions, the connected Smaily accounts, the
  switched-on features, whether each connection works (error codes only)
  and the time of the last successful sync — never customer, subscriber or
  order data.
- **Translated** — ships with English and Estonian (`et_EE`) translation
  packs for the admin and the storefront, and storefront texts in Russian,
  Finnish, Latvian and Lithuanian (machine translations awaiting a native
  review).

## Requirements

- Magento Open Source / Adobe Commerce **2.4.4+** or Mage-OS
- PHP **8.1 – 8.5**
- A working Magento cron (ideally every minute)

## Installation

```bash
composer require smaily/smailyformagento
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
```

A store in production mode also needs maintenance mode, `setup:di:compile` and
`setup:static-content:deploy` — the full sequence is in the
[User Guide](https://smaily.com/connect-magento/#install).

Manual install: extract the release ZIP to `app/code/Smaily/Connect` and
run the same `bin/magento` commands. Each release also carries a `.sha256`
file next to the ZIP — `sha256sum -c smaily-connect-magento2.zip.sha256`
confirms you downloaded the archive we built.

**Upgrading from 2.8.x?** Settings migrate automatically, but
`composer update` alone stays on 2.8.x — follow the commands in
[UPGRADING.md](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/UPGRADING.md).

## Documentation

| | |
|---|---|
| [User Guide](https://smaily.com/connect-magento/) | Setup, every setting explained, CLI reference, FAQ — in English and Estonian |
| [Installing from the ZIP](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/INSTALLING.md) | Manual install without composer: verify, extract, set up, update, remove |
| [Upgrading](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/UPGRADING.md) | Migrating from Smaily for Magento 2.8.x |
| [Architecture](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/ARCHITECTURE.md) | How the module works inside (for developers) |
| [Hyvä Support](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/HYVA_SUPPORT.md) | Hyvä theme compatibility: audit, compat module, verification results |
| [Headless Storefronts](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/HEADLESS_STOREFRONTS.md) | What works with a separate storefront application, and what its team must add |
| [Testing](https://github.com/sendsmaily/smaily-magento-extension/blob/master/TESTING.md) | Test suites, sandbox, upgrade verification |
| [Contributing](https://github.com/sendsmaily/smaily-magento-extension/blob/master/CONTRIBUTING.md) | Development environment and quality gates |

## Quick start

1. **Run the wizard:** Marketing > Smaily Connect opens the guided setup
   on a fresh install — connect your Smaily account, choose your audience,
   map automations and (optionally) Campaign Intelligence in five steps.
2. **Everything after that:** Marketing > Smaily Connect > **Dashboard**
   (health and activity at a glance), **Settings** (the same options as
   always-available tabs, including historical imports) and **Log** (every
   delivery, with retry).

Everything is configured on the module's own pages — there is no separate
Stores > Configuration entry. On a store with more than one website, the
initial setup first asks how the websites are run: as **one store in
several versions**, set up once for all websites, where a website can be
given its own Smaily account, Contacts settings and automations on
Settings; or as **separate stores**, each website set up on its own with
its own Smaily account. The
[User Guide](https://smaily.com/connect-magento/#setup-website) has the
details.

## Development

```bash
composer install          # Magento packages via the Mage-OS mirror
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit -c phpunit.integration.xml.dist  # needs MySQL, see TESTING.md

docker compose up -d      # Magento 2.4.8 sandbox on http://localhost:8080
```

## Support

Questions about Smaily Connect or your Smaily account: support@smaily.com.
Bugs and feature requests:
[GitHub issues](https://github.com/sendsmaily/smaily-magento-extension/issues).

## License

GPL-3.0 — see [LICENSE.txt](LICENSE.txt).

Legacy note: the 2.8.x extension (`Smaily_SmailyForMagento`) is no longer
developed. Its releases stay available under their tags, the last one
[`2.8.1`](https://github.com/sendsmaily/smaily-magento-extension/tree/2.8.1).
