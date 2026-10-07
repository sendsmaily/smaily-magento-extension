# Contributing

Thanks for taking the time to contribute!

## Getting started

Requirements: PHP 8.1–8.4, Composer, Docker (for the sandbox).

```bash
git clone https://github.com/sendsmaily/smaily-magento-extension.git
cd smaily-magento-extension
composer install    # Magento packages resolve via the Mage-OS mirror
```

Dependencies install from the committed `composer.lock`. If your local PHP is
newer than 8.4, or you are missing one of the Magento PHP extensions, add
`--ignore-platform-reqs`.

`composer` reports `magento/module-catalog-inventory` as abandoned in favor of
`magento/inventory-metapackage`. The requirement stays as it is: the module
reads the legacy `is_in_stock` flag that package owns, and the metapackage
would pull all of Multi-Source Inventory in as a hard dependency when MSI is
removable. Revisit it when the Magento floor (2.4.4+) is raised, not before.
The MSI packages themselves are listed under `suggest` — they are wired only
through `etc/di.xml` plugin declarations, never named in PHP.

## Development environment

A Docker sandbox with Magento 2.4.8 + sample data and the module mounted at
`app/code/Smaily/Connect`:

```bash
docker compose up -d
# Storefront: http://localhost:8080/
# Admin:      http://localhost:8080/admin  (admin / smailydev1)

docker exec magento2 bash -c 'cd /var/www/html && bin/magento setup:upgrade'
```

The first start installs Magento and takes several minutes. Reset with
`docker compose down -v`.

## Quality gates

Every pull request must pass CI (the same commands work locally):

```bash
vendor/bin/phpunit --testsuite unit   # unit tests
vendor/bin/phpcs                      # Magento2 coding standard (errors fail)
vendor/bin/phpstan analyse            # level 6, with the Magento extension

# Integration tests need a throwaway MySQL (see TESTING.md for setup):
vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Additions to sync payloads or API clients must stay wire-compatible with
the Smaily Connect plugins for WooCommerce and Shopify — see
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#wire-contracts) before touching
a payload builder or client. End-to-end verification steps live in
[TESTING.md](TESTING.md).

## Pull requests

- Branch from `master`, keep changes focused, include tests for new logic.
- Pull requests are squash-merged: the PR title and description become the
  one commit on `master`, so write them as the record of the change.
- Describe the merchant-visible behavior change in the PR description and
  add a `CHANGELOG.md` entry under the unreleased version.
- New settings need `etc/adminhtml/system.xml` + `etc/config.xml` defaults
  and, when replacing a legacy 2.8.x option, a mapping in
  `Model/Migration/LegacyConfigMapper` (with a unit test).

## Releasing

A version cut is a pull request like any other: it sets the version in
`composer.json` and `Model/ModuleInfo.php` (keep them in sync) and names it
in `CHANGELOG.md`. After it is merged, publish a GitHub release on `master`
whose tag is the plain version (`3.0.0`, no `v` prefix); a release candidate
(`3.0.0-rc9`) is published as a pre-release. Publishing the release
triggers `.github/workflows/release.yaml`, which builds, verifies and
attaches the installable ZIP and its `.sha256`. The composer package is
`smaily/smailyformagento`: Packagist reads it from this repository, so every
tag is a published version.
