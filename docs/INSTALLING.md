# Installing from the release ZIP

A step-by-step guide for installing Smaily Connect by hand, without
composer: download the release ZIP, put it in `app/code`, run the Magento
setup commands. It also covers updating to a newer release and taking the
module out again. For a composer install, see the
[User Guide](USER_GUIDE.md#installation).

## Before you start

Check the Magento and PHP versions first:

1. **Magento version.** The admin footer shows it on every admin page
   ("Magento ver. 2.4.x"); on the server, `bin/magento --version` prints
   it. It must be **2.4.4 or newer**. Magento 2.4.3 and older cannot run
   Smaily Connect: they run only on PHP 7.x.
2. **PHP version.** On the server, run `php -v` with the same PHP binary
   that runs `bin/magento` (on a server with several PHP versions, the one
   the cron line and the web server use too). It must be **8.1 – 8.4**.

If either is below the minimum, stop and upgrade the store first (Magento
2.4.7 or 2.4.8 on PHP 8.2 or 8.3 is a good target). A composer install
refuses an unsupported store; the release ZIP does not check, and on an
unsupported store `bin/magento setup:upgrade` and `setup:di:compile` fail
once its files are in place — remove `app/code/Smaily/Connect` again if
that happened.

Then check the rest:

- Magento Open Source / Adobe Commerce **2.4.4+** or Mage-OS.
- PHP **8.1 – 8.4**.
- These Magento modules enabled (they are on a standard install):
  `Magento_Store`, `Magento_Customer`, `Magento_Newsletter`,
  `Magento_Catalog`, `Magento_CatalogInventory`, `Magento_Quote`,
  `Magento_Sales`, `Magento_Checkout`, `Magento_Ui`, `Magento_Backend`,
  `Magento_Config`, `Magento_Cookie`.
- Magento cron installed and running every minute (see [Cron](#cron)).
- No Smaily for Magento 2.8.x present. If it is, follow
  [UPGRADING.md](UPGRADING.md) instead.
- Storefront theme: Luma-based themes work as they are; a Hyvä storefront
  also needs the separate compatibility module — see
  [HYVA_SUPPORT.md](HYVA_SUPPORT.md). A headless storefront (a separate
  storefront application on top of Magento) needs work from its own team
  before the storefront features work — see
  [HEADLESS_STOREFRONTS.md](HEADLESS_STOREFRONTS.md).

Run every `bin/magento` command below from the Magento root, as the user
that owns the Magento files.

## 1. Download and verify

Every release on the
[releases page](https://github.com/erkkimarkus/magento-connect/releases)
carries two files: `smaily-connect-magento2.zip` and
`smaily-connect-magento2.zip.sha256`. Download both into the same folder
and check the archive:

```bash
sha256sum -c smaily-connect-magento2.zip.sha256      # Linux
shasum -a 256 -c smaily-connect-magento2.zip.sha256  # macOS
```

The answer must be `smaily-connect-magento2.zip: OK`. Anything else means
the download is incomplete or not the archive the release built — download
it again before going further.

## 2. Extract into `app/code/Smaily/Connect`

The ZIP has no top-level folder: `registration.php`, `composer.json`,
`etc/`, `view/` and the rest sit at the root of the archive. Extract it
straight into the module directory:

```bash
mkdir -p app/code/Smaily/Connect
unzip smaily-connect-magento2.zip -d app/code/Smaily/Connect
```

Check the result: `app/code/Smaily/Connect/registration.php` and
`app/code/Smaily/Connect/etc/module.xml` must exist. If you see
`app/code/Smaily/Connect/<something>/registration.php` instead, the files
landed one folder too deep — move them up.

`app/code` must be a real directory inside the Magento root. If it is a
symlink to a folder outside the root, production-mode
`setup:static-content:deploy` cannot read the module's files and stops
with "The contents from the … file can't be read".

## 3. Enable and set up

**Production mode** (check with `bin/magento deploy:mode:show`):

```bash
bin/magento maintenance:enable
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy en_US   # list every locale your admin and storefront use, e.g. en_US et_EE
bin/magento maintenance:disable
bin/magento cache:flush
```

**Developer or default mode:** compilation and static deployment happen on
demand, so these are enough:

```bash
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` creates the module's own tables (all named `smaily_*`) and
adds no columns to Magento's core tables.

## 4. Cron

Smaily Connect does its work in cron jobs — delivering contacts and
automation triggers, abandoned-cart detection, the two-way consent sync,
Campaign Intelligence sync, historical imports. They run in a dedicated
cron group, **`smaily_connect`**, in a separate process. Nothing in the
group needs its own crontab line: the standard Magento cron entry runs it.

```bash
crontab -l | grep -A4 'MAGENTO START'   # Magento's cron block is installed
bin/magento cron:install                 # only if the block above is missing
```

For near-real-time delivery the Magento cron must run **every minute**.
To run the group once by hand — useful right after installing:

```bash
bin/magento cron:run --group smaily_connect
```

## 5. Check the installation

- `bin/magento module:status Smaily_Connect` answers
  `Module is enabled`.
- In the admin, **Marketing > Smaily Connect** has four entries:
  **Dashboard**, **Initial setup**, **Settings**, **Log**. Log out and in
  again if the menu does not show yet.
- A notice "Smaily Connect is ready to set up" appears in the admin
  notifications (the bell at the top of every admin page), not under
  System Messages. Finishing the initial setup marks it as read.
- On a fresh install every Smaily Connect page opens **Initial setup**
  until it is completed once. Continue with
  [Connecting your Smaily account](USER_GUIDE.md#connecting-your-smaily-account).

## Updating to a newer release

Settings, queues and the log are kept in the database, so an update only
replaces the code. Download and verify the new ZIP (step 1), then replace
the whole directory — removing the old files first, so a file the new
release no longer ships does not linger:

```bash
bin/magento maintenance:enable            # production mode
rm -rf app/code/Smaily/Connect
mkdir -p app/code/Smaily/Connect
unzip smaily-connect-magento2.zip -d app/code/Smaily/Connect
```

Then, straight away — Magento cron keeps running in maintenance mode —
run the step 3 commands for your mode again (`module:enable` is a
no-op on an enabled module and can be left out). `bin/magento module:status
Smaily_Connect` still answers `Module is enabled`, and the admin's
**Marketing > Smaily Connect > Dashboard** opens as before — an update does
not re-run the initial setup.

## Disabling or removing the module

Disabling stops every Smaily Connect job, storefront script and admin page
at once. Contacts, automations and Campaign Intelligence data that already
reached Smaily stay there.

**Disabling also removes the module's tables.** Magento's declarative
schema drops the tables of a disabled module on the next
`setup:upgrade`: the delivery queues (including anything still waiting to
be sent), the Log, the abandoned-cart tracker, the automation workflow
mapping, historical-import progress and order attribution. Your settings
(connection, contact sync, automations) live in Magento's configuration
and are kept, and so is the store's record of shoppers who opted out of
personalization. Only uninstalling removes them (see below).

To keep a copy of the table contents, add `--safe-mode=1`: Magento still
drops the tables, but first writes every table that has rows to CSV under
`var/declarative_dumps_csv/` (empty tables get no file — they come back
empty). `--data-restore=1` on the `setup:upgrade` after re-enabling loads
the rows back and empties that folder, so copy it elsewhere first if you
also want a backup that outlives the restore.

```bash
bin/magento maintenance:enable                 # production mode
bin/magento module:disable Smaily_Connect
bin/magento setup:upgrade --safe-mode=1        # or plain setup:upgrade to drop the data
bin/magento setup:di:compile                   # production mode
bin/magento setup:static-content:deploy en_US  # production mode, your locales
bin/magento maintenance:disable                # production mode
bin/magento cache:flush
```

**To bring it back**, run `module:enable Smaily_Connect` and the step 3
commands, with `bin/magento setup:upgrade --data-restore=1` if you kept a
copy. The saved settings are picked up as they were, so the initial setup
does not open again.

**To remove it completely**, disable it as above first (that drops the
tables), then uninstall it. Uninstalling removes the rest of the module's
data from the store database:

- every setting at every scope (`core_config_data` paths starting with
  `smaily_connect/`), including the encrypted Smaily API password and the
  Campaign Intelligence API key;
- any settings of Smaily for Magento 2.8.x still in the database (paths
  starting with `smaily/`; the upgrade already deletes them once it has
  migrated them);
- the module's flag rows (`flag` codes starting with `smaily_connect_`):
  the record of shoppers who opted out of personalization, the record of
  checked credentials, and the health-check and consent-sync state;
- the admin notice "Smaily Connect is ready to set up".

Magento's own records of the module stay after an uninstall: the list of
the module's applied setup patches (so installing again does not add the
"ready to set up" notice again), and the line `'Smaily_Connect' => 0` in
`app/etc/config.php`. None of them holds a setting or shopper data; a
later install enables the module again with the step 3 `module:enable`.

Nothing is sent to Smaily or Campaign Intelligence. Contacts and data
already there stay there, and the Campaign Intelligence API key stays
valid on the engine side until it is revoked there; a new install connects
with a new setup token. Install the module again later and it starts from
the initial setup.

For a ZIP install in `app/code`, `module:uninstall` needs
`--non-composer`. In that mode Magento only reverts the module's data
patches; it does not delete the code, so delete the directory yourself:

```bash
bin/magento module:uninstall --non-composer Smaily_Connect
rm -rf app/code/Smaily/Connect
bin/magento cache:flush
```

For a composer install, Magento removes the settings, the module
registration and the composer package in one step. It switches
maintenance mode on and off by itself and clears the cache and the
generated code, so production mode needs a compile afterwards:

```bash
bin/magento module:uninstall --remove-data Smaily_Connect
bin/magento setup:di:compile                   # production mode
```

Deleting the code without running `module:uninstall` first leaves the
settings and flag rows in the database.
