# Testing

## Unit tests and static analysis

```bash
composer install            # Magento packages resolve via mirror.mage-os.org
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs            # Magento2 standard; errors fail, warnings don't
vendor/bin/phpstan analyse  # level 6 with the bitexpert/phpstan-magento extension
```

CI (GitHub Actions) runs the unit suite on PHP 8.1 and 8.3 plus the static
analysis job on every push and pull request.

**`composer.lock` is committed.** Every job installs from it, so an upstream
release can no longer turn a green build red without a change in this
repository — which is exactly what happened on 2026-09-10, when a freshly
resolved PHPStan 2.2.13 broke the static job. The lock is resolved against PHP
8.1 (`config.platform.php` in composer.json), the oldest PHP the package
supports, so the same lock installs on the PHP 8.1 job and on a newer local
host. Refresh it deliberately:

```bash
composer update --ignore-platform-req='ext-*'   # ext-* only if your host is
                                                # missing Magento extensions
vendor/bin/phpunit --testsuite unit && vendor/bin/phpcs && vendor/bin/phpstan analyse
git add composer.lock
```

Never pass a bare `--ignore-platform-reqs` to `composer update` — that drops
the PHP 8.1 target too and can lock packages the CI job cannot install. The
`Lock freshness` workflow runs `composer update --dry-run` every Monday and
files a GitHub issue when the lock has fallen behind; a second drift comments
on the open issue rather than opening another.

**PHPStan is capped below 2.2.6 on purpose.** From 2.2.6 the phar ships the
`phpstan_turbo` extension, which PHPStan loads by restarting itself; its
shared-memory cache makes `PHPStan\Cache\Cache::load()` hand back the value
that was just passed to `save()` instead of what the injected storage returns.
`bitexpert/phpstan-magento` v0.43.0 relies on that storage returning the *path*
of the factory class it generated, so with the extension active it `require`s
PHP source as a filename and every generated `*Factory` blows up with
`Internal error: Failed opening required '<?php ...'`. Local runs stayed green
only because their vendor tree predated 2.2.6. Lift the cap once
bitexpert/phpstan-magento releases the fix it carries on its unreleased
`bugfix/autoloader-requires-generated-file-not-source` branch.

## Integration tests (real MySQL)

The integration suite exercises the module against a real MySQL database:
queue persistence and claim/backoff/parking semantics (`smaily_event_queue`,
`smaily_ingest_queue`), the 2.8.x → v3 settings migration (real
`core_config_data` rows, password re-encryption, automation mapping seeding),
the legacy schema cleanup patch (real `quote` column drops with mailed-state
carry-over) and the queue cron jobs with the HTTP transports stubbed.

It needs a MySQL 8.x it can own a database on — any throwaway instance works:

```bash
docker run --rm -d --name smaily-it-mysql \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=smaily_connect_it \
  -p 3316:3306 mysql:8.4

SMAILY_IT_DB_PORT=3316 vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Connection parameters come from environment variables (defaults in
parentheses): `SMAILY_IT_DB_HOST` (127.0.0.1), `SMAILY_IT_DB_PORT` (3306),
`SMAILY_IT_DB_USER` (root), `SMAILY_IT_DB_PASSWORD` (root),
`SMAILY_IT_DB_NAME` (smaily_connect_it). The named database is dropped
table-by-table and recreated on every run — never point it at data you care
about. CI runs the suite in a dedicated job with a MySQL 8.4 service.

**Scope note:** the suite boots a standalone `Magento\Framework` object graph
(real DB adapter, model/resource/collection layer, encryptor, config writer)
rather than Magento's full integration TestFramework, which requires a
complete application install plus a search engine and is disproportionately
heavy for a single module's CI. Module tables are installed by translating
`etc/db_schema.xml` directly into DDL; the declarative-schema pipeline itself
(plus DI compilation) is still verified by `setup:upgrade` /
`setup:di:compile` in the sandbox below.

## Sandbox (manual / end-to-end)

A Docker Magento 2.4.8-p4 with sample data, the module mounted at
`app/code/Smaily/Connect`:

```bash
docker compose up -d
# Storefront http://localhost:8080/  Admin http://localhost:8080/admin (admin / smailydev1)

docker exec magento2 bash -c 'cd /var/www/html && bin/magento setup:upgrade && bin/magento setup:di:compile'
docker exec magento2 bash -c 'cd /var/www/html && bin/magento cron:run --group smaily_connect'
```

**The admin has no second factor.** `setup:install` enables
`Magento_TwoFactorAuth` and `Magento_AdminAdobeImsTwoFactorAuth`, which send
every login to `tfa/tfa/requestconfig` and make the admin unreachable to a
browser session or a script that only has the password. `.sandbox/entrypoint.sh`
therefore disables both on every boot — a no-op once they are off ("No modules
were changed."), so it also repairs a data volume installed before the module
list was set. The script is mounted into the container from the working tree,
so an edit to it takes effect on the next `docker compose up -d` without an
image rebuild. Confirm the login lands on the dashboard rather than the
two-factor screen:

```bash
FORM_KEY=$(curl -s -c /tmp/j -L http://localhost:8080/admin \
  | grep -o 'name="form_key" type="hidden" value="[^"]*"' | head -1 | cut -d'"' -f6)
curl -s -c /tmp/j -b /tmp/j -L http://localhost:8080/index.php/admin \
  --data-urlencode "login[username]=admin" \
  --data-urlencode "login[password]=smailydev1" \
  --data-urlencode "form_key=$FORM_KEY" \
  -o /tmp/admin.html -w '%{url_effective}\n'
grep -o '<title>[^<]*' /tmp/admin.html   # <title>Dashboard / Magento Admin
```

To exercise the two-factor flow itself, re-enable them for that run and put
them back afterwards:

```bash
docker exec magento2 bash -c 'cd /var/www/html && bin/magento module:enable \
  Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth && bin/magento setup:upgrade'
```

The next container start disables them again, so a restart is all the cleanup
that run needs.

Manual smoke checklist:

1. Admin > Marketing > Smaily Connect > Dashboard renders the health verdict,
   connection strip, counters and recent activity (fresh installs redirect to
   the Initial setup page instead until it is completed).
2. Settings: each tab saves via AJAX ("Saved." feedback) and the values land
   in `core_config_data`; invalid Smaily credentials must not block saving.
3. Subscribe on the storefront newsletter form -> a `contact.sync` row appears
   in the Log (source "Smaily") and is delivered on the next cron run.
4. Place an order with the checkout newsletter checkbox ticked -> the email
   becomes a Magento subscriber and syncs to Smaily.
5. Abandon a cart (add items as a logged-in customer, wait past the cutoff,
   run the cron) -> `automation.trigger` event fires once, never twice.
6. `curl http://localhost:8080/smaily/rss/feed?limit=5` returns valid RSS with
   `smly:price` fields.
7. Campaign Intelligence: paste a setup token, run backfills from the
   Settings > Intelligence tab, watch the Log (source "Campaign
   Intelligence") drain.

## Upgrade migration test (2.8.x -> v3)

The migration is covered automatically by the integration suite
(`Test/Integration/Migration/`), which asserts the same outcomes as this
procedure against a real database. The sandbox walkthrough below remains the
full end-to-end check through Magento's real `setup:upgrade` patch pipeline.

Scripted legacy-state simulation inside the sandbox (what the data patch must
handle). Seed the legacy state, then upgrade:

```bash
docker exec magento2_db mysql -uroot -proot magento2 -e "
INSERT INTO core_config_data (scope, scope_id, path, value) VALUES
('default',0,'smaily/general/subdomain','https://demo.sendsmaily.net'),
('default',0,'smaily/general/password','plain-secret'),
('default',0,'smaily/subscribe/workflowId','55'),
('default',0,'smaily/abandoned/autoresponderId','77'),
('default',0,'smaily/abandoned/syncTime','2:hour');
ALTER TABLE quote ADD COLUMN reminder_date TIMESTAMP NULL, ADD COLUMN is_sent SMALLINT NULL;
CREATE TABLE IF NOT EXISTS smaily_customer_sync (id INT PRIMARY KEY);
DELETE FROM patch_list WHERE patch_name LIKE '%Smaily%';
DELETE FROM core_config_data WHERE path LIKE 'smaily_connect/%';"

docker exec magento2 bash -c 'cd /var/www/html && bin/magento setup:upgrade'
```

Assert afterwards:

- `smaily_connect/*` rows exist for every legacy scope; the password value is
  encrypted (starts with a key-version prefix like `0:3:`), the subdomain is
  normalized, the abandoned cutoff is in minutes, `qty` became `quantity`.
- `smaily_automation_mapping` contains fallback rows for the configured
  welcome/abandoned workflow IDs.
- `quote.reminder_date` / `quote.is_sent` and `smaily_customer_sync` are gone.
- The orphaned `crontab/default/jobs/smaily_subscriber_sync/...` row is gone.

## Release package

The ZIP a GitHub release publishes is assembled by one script,
`bin/build-release-zip.sh` — the release workflow calls it, so what CI ships
and what you build locally are the same artifact. `bin/verify-release-zip.sh`
builds it and then checks it:

```bash
bin/verify-release-zip.sh            # writes ./smaily-connect-magento2.zip
```

It asserts that the archive carries what a Magento module needs to install
(`registration.php`, `composer.json`, `etc/module.xml`, `etc/db_schema.xml`,
the `i18n` catalogs, `view/`) plus `README.md`, `CHANGELOG.md` and
`LICENSE.txt`, that it carries none of the development apparatus (tests, CI
config, sandbox, tooling, static-analysis and phpunit config, `vendor/`, git
metadata — the `.git` directory, or the `.git` file a git worktree has in its
place — and the developer and working documents, this `TESTING.md` and
`CONTRIBUTING.md` among them), none of the Hyvä companion (`compat/` — a
separately published package) and no `docs/` at all, that the version in the
archived `composer.json` is the repo's, and that every shipped PHP file parses
under `php -l`. It ends by printing a SHA-256 build hash and writing it to
`<zip>.sha256`, so a package handed to a reviewer or a pilot store can be
identified later.

CI runs it on every push (the `package` job) and keeps the ZIP plus its hash
as a build artifact for 5 days.

**`docs/` does not ship** (Erkki's decision, 2026-09-10). The folder vendors
the engine contract from a private repository and carries internal audits, so
the package would leak both to anyone who unzips it. The documentation set
lives on GitHub instead and the shipped README links to it there by URL — when
you add a documentation link to README, CHANGELOG or an admin template, make it
the repository URL, never a relative `docs/` path.

**`composer validate --strict` stays yellow, on purpose.** Its one warning —
"the version field is present, it is recommended to leave it out" — is an
accepted item, not a defect: the Marketplace's packaging guide requires
`version` and `Model\ModuleVersion` reads it for the admin's post-upgrade
notice. CI therefore runs plain `composer validate`; do not "fix" the warning
by dropping the field.
