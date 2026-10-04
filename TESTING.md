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

A newer local PHP accepts syntax PHP 8.1 rejects, and neither PHPStan nor
phpcs flags it, so CI also parses every PHP and PHTML file outside `vendor/`
on PHP 8.1, whether or not a test loads it, and fails naming each file it
cannot parse. Run the same check locally:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.1-cli bin/lint-php.sh
```

PHPStan needs more memory than PHP's default 128M. `phpstan.neon.dist` loads
`Test/phpstan-bootstrap.php`, which raises the limit to 1G in the main process
and in every worker, so the command above runs as written; a higher limit
from php.ini or `--memory-limit` is left as it is.

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

## Browser JS harnesses

```bash
bin/test-js.sh              # headless Chrome; CHROME=/path/to/chrome to pick one
```

Each page under `Test/Js/` ends with `RESULT: PASS` or `RESULT: FAIL`; the
script exits non-zero on a failure and ends with a list of each failed page
and its failed checks. `Test/Js/email-mixin.html` loads
Magento's own checkout JS from `vendor/` (so `composer install` first),
applies the module's mixin as `view/frontend/requirejs-config.js` declares
it and stubs the rest of the checkout. `Test/Js/email-mixin.html` drives Magento's checkout
email component through typing, validation and its typing pause and checks
when the guest's email goes to the cart (one request per change of a valid
address, none for an invalid value, a signed-in customer or a website with the
abandoned-cart automation off). Chrome's virtual
time runs the pauses without waiting for them. `Test/Js/tracker-consent.html`
runs the real Luma and Hyvä browse trackers, one fresh frame per scenario
(document.cookie, sendBeacon and the Luma tracker's jQuery stubbed), and
checks Magento's cookie notice consent: accepted on another website, on this
website, not parseable, accepted later on the page, and the consent override
first.

The admin screens that save through the browser are checked the same way,
from their real templates. `Test/Js/render-admin.php` (run by
`bin/test-js.sh` first) renders each template in en_US and et_EE as Magento
does — Magento's Escaper, `__()` translated with `i18n/<locale>.csv`, a view
model with fixed data — into `Test/Js/build/` (not committed), and the page
loads the result with jQuery from `vendor/` (the copy PHPUnit's coverage
report ships; the module's dev install has no Magento `lib/web`).
`Test/Js/automations-save.html` saves the Automations tab with the save
request answered by a recorded answer of the save endpoint and checks each Campaign Intelligence trigger's
card in both languages: a trigger that asked for real sends and was kept in
test mode shows Test mode with the box ticked again and the go-live note,
and the result adds the note; a trigger the engine stored active shows
Active without the note; a trigger left off stays Off; when the stored
state could not be read, the result asks for a reload and the cards keep
the page's state. `Test/Js/intelligence-connect.html` renders the
Campaign Intelligence panel with its behaviour (`panel/intelligence.phtml`
and `panel/panels-js.phtml`) as the initial setup's step and as the
Settings tab, presses Connect with the requests answered by recorded
answers of the connect and import endpoints, and checks in both languages
that a Connect that started the catalog import shows the notice with
**Hold back the import** (and one that started none shows no notice), that
Hold back cancels the catalog import and says what happened — canceled
before it began, after products were queued (how many), or already
finished — and that on Settings the Catalog card shows the queued import,
then the canceled one. To check another admin screen, add its template(s)
and view model to `$pages` in `Test/Js/render-admin.php` and a page under
`Test/Js/`. phpcs leaves the rendered `Test/Js/build/` out.

CI runs `bin/test-js.sh` on every push and pull request (the `browser` job:
PHP 8.3, an install from `composer.lock`, the Google Chrome the runner image
ships); a failed check fails the job, and the end of its log names the page
and the check. The pages run on Chrome's virtual time, which jumps to the end
of its budget whenever no timer is pending — even while a frame loads — so a
page that waits on frames or promises keeps a short interval running until
its result is written (see `Test/Js/tracker-consent.html`).

## Integration tests (real MySQL)

The integration suite exercises the module against a real MySQL database:
queue persistence and claim/backoff/parking semantics (`smaily_event_queue`,
`smaily_ingest_queue`), the 2.8.x → v3 settings migration (real
`core_config_data` rows, password re-encryption, automation mapping seeding),
the legacy schema cleanup patch (real `quote` column drops with mailed-state
carry-over), the uninstall removal (real `core_config_data`, `flag` and
`adminnotification_inbox` rows) and the queue cron jobs with the HTTP
transports stubbed.

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
- No `smaily/*` row is left at any scope; `smaily_connect/*` rows stay.

## Release package

The ZIP a GitHub release publishes is assembled by one script,
`bin/build-release-zip.sh` — the release workflow calls it, so what CI ships
and what you build locally are the same artifact. It is `git archive` of the
committed tree (`HEAD`), so a file that is not committed (a local `.env`, an
IDE folder, a tool's local configuration) never ships, and neither does an
uncommitted change — commit before you build. What stays out is listed once,
as `export-ignore` in `.gitattributes`, which a composer dist install from
GitHub honours too. `bin/verify-release-zip.sh` builds it and then checks it:

```bash
bin/verify-release-zip.sh            # writes ./smaily-connect-magento2.zip
```

It asserts that the archive carries what a Magento module needs to install
(`registration.php`, `composer.json`, `etc/module.xml`, `etc/db_schema.xml`,
the `i18n` catalogs, `view/`) plus `README.md`, `CHANGELOG.md` and
`LICENSE.txt`, that it carries none of the development apparatus (tests, CI
config, sandbox, tooling, static-analysis and phpunit config, `vendor/`,
`composer.lock`, archives, git metadata — the `.git` directory, or the `.git`
file a git worktree has in its place — any dot-file or dot-folder such as
`.env` or `.idea/`, and the developer and working documents, this `TESTING.md` and
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
the repository URL, never a relative path to a file the package does not ship
(`docs/`, `TESTING.md`, `CONTRIBUTING.md`, `BACKLOG.md`).

**`composer validate --strict` stays yellow, on purpose.** Its one warning —
"the version field is present, it is recommended to leave it out" — is an
accepted item, not a defect: the Marketplace's packaging guide requires
`version` and `Model\ModuleVersion` reads it for the admin's post-upgrade
notice. CI therefore runs plain `composer validate`; do not "fix" the warning
by dropping the field.

### Clean install from the ZIP

The install runbook, [docs/INSTALLING.md](docs/INSTALLING.md), is checked
against the built ZIP on a fresh sandbox — not against the bind-mounted
working tree, which is not what a merchant installs.

1. **Start the sandbox without the module mount.** Keep a compose override
   outside the repository (it is not committed) that replaces the
   `magento2` volume list without the working-tree mount:

   ```yaml
   # e.g. /tmp/compose.zip-install.yaml
   services:
     magento2:
       volumes: !override
         - data:/var/www/html
         - ./.sandbox/entrypoint.sh:/entrypoint.sh:ro
   ```

   ```bash
   docker compose -f docker-compose.yaml -f /tmp/compose.zip-install.yaml up -d
   docker exec magento2 bash -c 'cd /var/www/html && bin/magento module:status Smaily_Connect'
   # Smaily_Connect : Module does not exist
   ```

   On fresh volumes this installs Magento without the module. On a used
   data volume, remove the module from it first.

2. **Give `app/code` a real directory.** The sample-data step links the
   sandbox's `app/code` to `/sample-data/app/code`, outside the Magento
   root, and production-mode static deployment cannot read a module there.
   A real store keeps `app/code` inside the root, so do the same:

   ```bash
   docker exec magento2 bash -c 'cd /var/www/html && rm app/code && cp -a /sample-data/app/code app/code'
   ```

3. **Production mode**, as a live store runs:
   `bin/magento deploy:mode:set production --skip-compilation`.

4. **Run the runbook as written:** copy the ZIP and its `.sha256` into the
   container (`docker cp`), then follow steps 1–5 of
   [docs/INSTALLING.md](docs/INSTALLING.md) as `www-data` from
   `/var/www/html` — `sha256sum -c`, extract, the production command
   sequence with `setup:static-content:deploy en_US et_EE`,
   `cron:run --group smaily_connect`, the admin checks. Then the update
   section (a fresh extraction of the ZIP and the step 3 commands again)
   and the disable section with `--safe-mode=1`, followed by re-enabling
   with `--data-restore=1`. To check removal, follow the guide's
   "remove it completely" path (disable, then `module:uninstall
   --non-composer`, then delete the directory) and count the
   `smaily_connect/%` and `smaily/%` rows in `core_config_data` and the
   `smaily_connect_%` rows in `flag` before and after. A store without a
   Smaily connection writes no flag rows, so insert one synthetic row to
   have something to count.

What a passing run shows: `module:status` answers `Module is enabled`;
six `smaily_*` tables exist; `cron_schedule` has `success` rows for the
`smaily_*` jobs; **Marketing > Smaily Connect** lists Dashboard, Initial
setup, Settings and Log, and each opens Initial setup until the setup is
completed; after an update the settings are unchanged; disabling drops the
six tables, and re-enabling with `--data-restore=1` brings them back with
their rows; finishing the initial setup marks the "Smaily Connect is
ready to set up" notice as read; uninstalling leaves no `smaily_connect/%`
or `smaily/%` setting, no `smaily_connect_%` flag row and no "ready to set
up" notice.

Afterwards return to the normal sandbox: remove `app/code/Smaily` from the
container, then `docker compose up -d` without the override recreates the
container with the working-tree mount (the real `app/code` directory works
with it). Run `bin/magento deploy:mode:set default` first if the sandbox
should not stay in production mode. After an uninstall, `app/etc/config.php`
lists the module as disabled, so run `bin/magento module:enable
Smaily_Connect` before `setup:upgrade` and `setup:di:compile`.

Last full run: 3.0.0-rc1 on Magento 2.4.8-p4, 2026-10-02 — every step
passed in production mode; the developer/default-mode update sequence
was run as well. A second run the same day on fresh volumes added the
admin walk in en_US and et_EE, a contact sync, an order and a product
save without Smaily or Campaign Intelligence credentials, and the
uninstall.

Latest run: 3.0.0-rc5 (the ZIP downloaded from the GitHub release) on
Magento 2.4.8-p4 without sample data, 2026-10-04, in production mode —
steps 1–5 and the update section passed as written. It ran on a separate,
temporary compose project (its own name, containers, volumes and ports,
the sandbox image, no working-tree mount) so the sandbox stayed untouched;
without sample data `app/code` does not exist yet and the guide's
`mkdir -p` creates it as a real directory. All eight `smaily_*` cron jobs
finished with `success` (the two daily jobs queued by hand), and nothing
was written to `exception.log` or `var/report`. The Dashboard, Settings
and Log were opened by setting `smaily_connect/internal/setup_completed`
for the website directly in the database, since step 1 of the initial
setup needs a working Smaily connection.
