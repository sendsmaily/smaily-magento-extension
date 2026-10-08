# STATUS — Smaily Connect for Magento 2

> **Rule (same as the sibling repos):** this file is updated in the SAME commit
> that changes reality — a finished task, a new blocker, a changed plan. Stale
> status is a defect. If this file and your memory disagree, trust this file
> and fix it.

_Last updated: 2026-10-07 night — **3.0.0-rc10 is released from the official
repository** (https://github.com/sendsmaily/smaily-magento-extension/releases/tag/3.0.0-rc10),
created with `gh release create` (the CLI worked this time): the release workflow
is green on 148cccd, the ZIP has 399 files, sha256 dd80633d6ece1b994971…, its
composer.json says 3.0.0-rc10, and Packagist lists 3.0.0-rc10 next to 3.0.0-rc9
and 2.8.1. It is the package a pilot store installs. **Queue:** PRO-3961 and
PRO-3962 in progress; the pilot when a store is confirmed (the HC PRO store may
be next week). **Open for Erkki:** HC PRO's Magento/PHP versions and storefront
type; the Estonian proofread and upload of `docs/site/index.html`. **Waiting on
the engine side:** PRO-3919, PRO-3941, PRO-3956._

_Earlier the same evening: the rc10 version cut merged (#136) —
CHANGELOG's "Changes since 3.0.0-rc9" lists PRO-3949 (#130), PRO-3957
(#131), PRO-3950 (#132), PRO-1970 (#134) and PRO-1964 (#135); no bullet
asks for a catalog import; all gates passed on the branch. **The move into
`sendsmaily/smaily-magento-extension` is complete (PRO-1198):** `master`
is the only line, every change reaches it through a squash-merged PR, and
the fork `erkkimarkus/magento-connect` is archived read-only (rc1–rc8
stay)._

_Earlier on 2026-10-07: **3.0.0-rc9 is released from the official
repository**
(https://github.com/sendsmaily/smaily-magento-extension/releases/tag/3.0.0-rc9):
the release workflow is green, the ZIP has 397 files, sha256
c09cdc065647fe95248f…, and Packagist lists 3.0.0-rc9 next to 2.8.1. Erkki
created the tag in the web UI after `gh release create` and `git push` of
the tag both returned GitHub HTTP 500 (CLAUDE.md "Release cut"). CLAUDE.md
now states: no attribution in commits, PRs or merges; Erkki squash-merges
every PR; no branch rule on `master`. **Docs tidy-up (owner decisions
2026-10-07):** the maintainers' working papers moved to `docs/internal/`
(`PILOT_CHECKLIST.md`, `BACKLOG.md`, `RFC_MULTI_WEBSITE.md`,
`ADMIN_UI_TARGET_SPEC.md`, `audits/`; its README says they are not merchant
documentation); `docs/UPSTREAM_PROPOSAL.md` and TESTING's dated rc-run log
are deleted (git history keeps them; the upstream decision record is
Linear PRO-1198). Older entries below keep the old paths.
Earlier the same day: **The move into
`sendsmaily/smaily-magento-extension` is done (PRO-1198):** PR #126 was
squash-merged into `master` as aa0c995, a tree identical to the fork's
last `v3` (0179e95); CI and Contract staleness are green on `master` (the
`ENGINE_CONTRACT_READ_TOKEN` secret is set there); local remotes are
switched (`origin` = sendsmaily, `fork` = erkkimarkus/magento-connect).
From now on every change reaches `master` through a squash-merged PR —
there is no direct push to `master`. **The 3.0.0-rc9 cut is ready on
branch `release/3.0.0-rc9` for a PR** (the first release candidate
tagged in the official repository): the version is `3.0.0-rc9`
(composer.json, `ModuleInfo::VERSION`, the ModuleVersion docblock, the
upstream proposal; composer.lock content-hash only), and CHANGELOG's
"Changes since 3.0.0-rc8" is the rc9 list in landed order — PRO-3927,
PRO-3930 and the User Guide links in the official repository (PRO-3948
and the docs moves have no bullet); the same branch drops `v3` from the
`ci.yaml` and `contract-staleness.yaml` triggers and brings CLAUDE.md to
`master`/PR wording. All gates pass on the branch (unit, phpcs, phpstan,
integration, browser harnesses, PHP 8.1 syntax, the release-version
check for `3.0.0-rc9`; the release ZIP verifies with 397 entries).
**Queue:** merge the rc9 PR → tag `3.0.0-rc9` on `master` (Erkki's yes;
a tag is a Packagist publish) → the release workflow and its checks →
the clean-install check of the rc9 ZIP → archive the fork. The pilot
installs the newest release candidate when a store is ready (the HC PRO
store may be next week; the furniture store after its upgrade).
Earlier the same day: **3.0.0-rc8 is released as a GitHub
pre-release on the fork**
(https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc8),
built by the release workflow (run 37615294088) from the version-cut commit
055276c; CI and Contract staleness are green on 055276c. Checked after
publishing: 397 entries, `shasum -a 256 -c` OK, sha256
fd3450434708f7cb076a…, composer.json inside says 3.0.0-rc8. **The rc8 ZIP
from the release installs on a clean store by docs/INSTALLING.md**
(PRO-3936, below; Magento 2.4.8-p4, PHP 8.3.35); one guide step was
corrected (step 2 and the update section now say to give `unzip` the ZIP's
full path when it is not in the Magento root). The version cut sets
`3.0.0-rc8` (composer.json, `ModuleInfo::VERSION`, the ModuleVersion
docblock, the upstream proposal; composer.lock content-hash only, no
dependency change), and CHANGELOG's "Changes since 3.0.0-rc7" list is the
rc8 list, in the order the changes landed; PRO-3913 is merged into
PRO-3802's bullet, PRO-3924 into PRO-3918's and PRO-3923 into PRO-3915's,
as rc4 merged PRO-3717 and PRO-3719. Earlier the same day: **the pilot is
postponed.** The first pilot
store runs Magento 2.4.3-p1 (PHP 7.3/7.4 only); the module needs Magento
2.4.4+ and PHP 8.1–8.4, so it cannot be installed there. Owner decision
(Erkki, 2026-10-07): the store upgrades first (Magento 2.4.7 or 2.4.8 on
PHP 8.2/8.3); Erkki sets the new date once the store's developer estimates
the upgrade; the "Pilot store live" milestone (2026-10-09) no longer holds.
Landed today, released in rc8: PRO-3911 (a recommendation link without a
context clears the context cookie), PRO-3912 (`vs_` visitor tokens reach
the order), PRO-3917 (a login through Magento's API —
GraphQL/REST customer token — links earlier browsing, as on Magento's own
pages, when the storefront forwards the cookies), PRO-3854 (the nightly catalog manifest, §3c, sent
at 03:30; and the contract copy 1.12.0), PRO-3798,
PRO-3802 (the pilot-day order needs it, so the pilot installs rc8),
PRO-3913 (a reopened setup asks for the catalog import again after a
Storefront URL change while Campaign Intelligence is connected; its summary
lists the Storefront URL), PRO-3918 (with a Storefront URL saved, the
Dashboard's browse tracking and the consent note describe the separate
storefront; no consent-source notice for it), PRO-3914 (the Dashboard
says when no nightly product list has gone out for three nights, and
why), PRO-3915 (an import nothing has moved for an hour shows *Stalled*;
Run again cancels it and starts a fresh one), PRO-3920 (an admin's
"Login as Customer" links no browsing to the customer), PRO-3923 (the command line
and connecting cancel a stalled import first too; an import waiting
behind a stalled one names it on its card), PRO-3924 (the consent note
under browse tracking follows a Storefront URL save without a reload), PRO-3925 (an
order placed with "Login as Customer" carries no browser markers); the
version check is now step one of INSTALLING and PILOT_CHECKLIST §0;
HEADLESS_STOREFRONTS opens with a setup checklist (admin / back end /
storefront developer), audited against the code.
Landed today after rc8 (CHANGELOG's "Changes since 3.0.0-rc8"): PRO-3927
(the tick sets aside a running import nothing has moved for an hour and
runs the imports queued behind it; the set-aside one stays *Stalled* on
its card and is tried again when none waits).
Landed after rc8: PRO-3930 (an order created in the admin's order screen
carries no browser markers; reproduced on the sandbox first);
PILOT_CHECKLIST's pilot-day order opens with switching off other Smaily
subscriber/opt-out syncs when contact sync goes on (and HEADLESS_STOREFRONTS A3).
The move was prepared on `v3` first (owner decisions 2026-10-07: one
squash-merged PR from the fork's `v3` into the official `master`,
squash-only PRs there afterwards, no staged review, 2.8.x ends at its
tags, release candidates tagged publicly, 3.0.0 after the pilot): the
admin's User Guide links, README, INSTALLING, CONTRIBUTING, the PR
template and UPSTREAM_PROPOSAL §5/§7 name the official repo.
PRO-3948: the release workflow fails, before attaching anything, on a
tag that is not composer.json's version (`bin/check-release-version.sh`).
Backlog: PRO-3790 (after the pilot), PRO-3746, PRO-3921 (waits on the
engine's answer, PRO-3941), PRO-3916 (waits on PRO-3919)._

_Today in detail: PRO-3802 (the initial setup's Connect step
takes the Storefront URL, saved before the Contacts step switches contact
sync on; PILOT_CHECKLIST's pilot-day order follows it and so needs a build
with PRO-3802), PRO-3798 (only a refunded credit memo marks order lines
returned) and PRO-3854 (the nightly catalog manifest; the engine contract copy synced
v1.8.3 → v1.12.0; Contract staleness green again); all in rc8,
CHANGELOG's "Changes since 3.0.0-rc7". New backlog Stories: PRO-3913 (a
reopened setup does not prompt a catalog re-import after a Storefront URL
change; its summary lacks the Storefront URL), PRO-3911 (the landing
capture never clears the context cookie), PRO-3912 (`vs_` visitor tokens
dropped). Closed: PRO-3805 (maintainer access on
sendsmaily/smaily-magento-extension granted; Packagist picks up release
tags from that repository automatically), PRO-3761. 2026-10-05 session handoff: after rc7, PRO-3768, PRO-1958,
PRO-3747, PRO-3753, PRO-3767, PRO-3780, PRO-1957, PRO-1955 and the
simplification passes landed on v3, unreleased (CHANGELOG's "Changes since
3.0.0-rc7"); they go into rc8, cut before the pilot (until 2026-10-07 the
plan was the next rc after the pilot). In detail: after rc7, PRO-1955 (a credit memo that moves
no money queues its order with the return; one refund, one order row;
unreleased); before it PRO-1957 (the abandoned-cart
reminder writes `over_10_products` on every send, empty for 10 products or
fewer; unreleased); before it a behaviour-neutral simplification
pass over PRO-3760, PRO-3765, PRO-3768, PRO-3753 and PRO-3767; before it
PRO-3767 (a contact sync or
automation row of an address longer than the 64-character entity column
stores the contact's keyed hash, not a cut address; unreleased); before it
PRO-3753 parts (b) and (c)
(Details on an engine-bound row waiting for a deactivated Campaign
Intelligence account says so; the five-attempt retry window is about 81
minutes, not six hours, in the docs); before it PRO-3753 part (a) (a group of
contact syncs, or a contacts-import page, that Smaily answers with 203 is
sent again one contact per request, so only the refused contact fails;
unreleased); before it PRO-3747 (the separate-storefront
hint on Settings > Intelligence follows a Connection save without a
reload; unreleased); before it PRO-1958 (the integration tests'
cart and cart-address tables are Magento's own, nothing merchant-visible);
before it PRO-3768 (products deleted by
Magento's product import with the Delete behaviour get the engine removal a
product delete gives them; unreleased, CHANGELOG's "Changes since
3.0.0-rc7" is open). Earlier the same day: 3.0.0-rc7 is released as a GitHub pre-release on
the fork (https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc7),
built by the release workflow (run 37284996851) from commit 8fcfe7f; the ZIP
and its .sha256 were checked after publishing (386 entries, `shasum -a 256 -c`
OK, sha256 b6aeeda1ca95206fb9cb…, composer.json inside says 3.0.0-rc7); the
CI and Contract staleness workflows are green on 8fcfe7f. The rc7 ZIP from
the release installs on a clean store by docs/INSTALLING.md as written
(PRO-3769, below). Until 2026-10-07 the plan was that the pilot installs
rc7 on pilot day 2026-10-09 (owner decision that day: the pilot store has a separate (headless) storefront, and
PRO-3760's consent fix is in rc7). The version is `3.0.0-rc7` (composer.json,
`ModuleInfo::VERSION`, the ModuleVersion docblock, the upstream proposal;
composer.lock content-hash refreshed, no dependency change), and
CHANGELOG's "Changes since 3.0.0-rc6" list is the rc7 list, its bullets
now in the order the changes landed, as in the earlier lists. Everything
merchant-visible since rc6 is in it: PRO-2509 (the Log's Last Error
filter matches the text the column shows), PRO-2511 (Details on a
delivered automation row reads as delivered), PRO-2510 (Retry over
**Select all** works 1,000 rows at a time), PRO-3749 (a Smaily HTTP error
shows Smaily's answer in the Log's error column), PRO-2465 (while the
account is refused, the Automations tab explains it and the GDPR command
handles the store's own data), PRO-1961 (a row that can never be
delivered fails at once), PRO-2466 (the links of browsing to accounts
wait while the account is refused), PRO-1962 (Smaily's "invalid data",
code 203, fails at once), PRO-3752 (a shopper's personalization choice
waits while the account is refused), PRO-3760 (an opt-out made before the
engine knew the shopper reaches it once it does) and PRO-3765 (a consent
row's entity is the shopper's keyed hash; the Log shows its first 12
characters), plus the engine contract 1.8.3 (PRO-3740, the catalog sync
lifecycle; a bullet as the rc3 list had for 1.8.2). PRO-2508, PRO-2475,
PRO-1469, PRO-2514, PRO-2462, the admin
browser-test renderer fix and the simplification pass change nothing a
merchant sees, so they have no bullet. The version cut is c9fb29f (on top
of cfa6544); the engine contract 1.8.3 sync commit 8fcfe7f sits on top of
it, and the release is built from that commit (the Contract staleness job
was red on c9fb29f, as engine main moved to v1.8.3 the same day).
Earlier 2026-10-05 — PRO-3765 (a profiling-consent queue row's
entity is the shopper's keyed hash, not the address); before it PRO-3760 (an opt-out the engine did not keep
is sent again once the engine confirms a customer or an order of the
shopper); earlier the same day PRO-3749 (a Smaily HTTP error shows Smaily's
answer in the Log's error column), PRO-2465 (the GDPR command and the
Campaign Intelligence automations form respect a refused account), PRO-1961
(one seam for terminal failures in the marketing queue), PRO-2466
(identity-merge rows wait while the account is refused), PRO-1962 (a
Smaily "invalid data" envelope fails on the first attempt), PRO-3752 (a
shopper's profiling choice waits while the account is refused; an engine
refusal of it fails on the first attempt), then a behaviour-neutral
simplification pass over them. 2026-10-04 — after rc6, the Event Log leftovers of PRO-2454
land on v3: PRO-2508 (the Log's error column describes rejected Smaily
credentials in the extension's own sentence, on purpose; docs only),
PRO-2509 (the Log's Last Error filter matches the text the column shows),
PRO-2511 (Details on a delivered, later-superseded automation row reads as
delivered) and PRO-2510 (mass Retry over "Select all" works in batches of
1,000). Then four tooling cleanups, nothing merchant-visible: PRO-2475
(CI drops the redundant mirror step; actions on Node.js 24), PRO-1469
(the hidden native config page's dead script is removed), PRO-2514
(module.xml sequences every Magento module composer requires, pinned by a
unit test), PRO-2462 (phpMyAdmin port via `PMA_PORT`, the crontab note;
the sample-data promise stays, with a note that the product images are
missing), and the admin browser-test renderer stops when it cannot write
its output.
Earlier the same day: 3.0.0-rc6 is released as a GitHub pre-release on
the fork (https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc6),
built by the release workflow (run 37228529206) from commit bb6831e; the ZIP
and its .sha256 were checked after publishing (380 entries, `shasum -a 256 -c`
OK, sha256 3c048a8b7e5eb9bddeb5…, composer.json inside says 3.0.0-rc6); the
CI and Contract staleness workflows are green on bb6831e. The rc6 ZIP from
the release installs on a clean store by docs/INSTALLING.md as written
(PRO-3748, below). The
version is
`3.0.0-rc6` (composer.json, `ModuleInfo::VERSION`, the ModuleVersion
docblock, the upstream proposal; composer.lock content-hash refreshed, no
dependency change), and CHANGELOG's "Changes since 3.0.0-rc5" list is the
rc6 list. Everything merchant-visible since rc5 is in it: PRO-3733 (a
command-line Campaign Intelligence connection sends the storefront's site
address), PRO-1968 (the nightly full catalog re-sync is removed: the full
catalog at connect, then changes; an import by hand after changes made
outside Magento), PRO-3741 (connecting starts the catalog import, with
**Hold back the import**), PRO-3745 (the separate-storefront note on the
setup's Intelligence step and on Settings > Intelligence) and PRO-1969 +
PRO-3742 (the catalog, customer and order imports do not start while
Campaign Intelligence is not connected). PRO-3735, PRO-3729, PRO-3737,
PRO-3736, PRO-3738, PRO-3739, PRO-1960 and the simplification passes
change nothing a merchant sees, so they have no bullet. The version cut
is commit bb6831e on top of 0dac0c3.
Earlier 2026-10-04 — after 3.0.0-rc5, PRO-3735 (CI parses every
PHP and PHTML file on PHP 8.1), PRO-3733 (a command-line Campaign
Intelligence connection sends the storefront's site address), PRO-3729 and
PRO-3737 (admin target spec wording), PRO-3736 (a browser harness for the
Automations tab's save result), PRO-3739 (the rc5 ZIP installed on a
clean store by docs/INSTALLING.md; one wording fix), PRO-3738 (CI runs
the browser harnesses), PRO-1960 (the abandoned-cart reminders are built
once per store), PRO-1968 (the nightly full catalog re-sync is removed) and
PRO-1969 (a catalog import does not start while Campaign Intelligence is
not connected), PRO-3742 (nor do the customers and orders imports) and
PRO-3741 (connecting Campaign Intelligence starts the catalog import, with
a Hold back) and PRO-3745 (the setup's Intelligence step tells a separate
storefront to set its Storefront URL first) landed on v3 (all in rc6),
followed by a behaviour-neutral simplification pass over PRO-1960 and
PRO-1969/3742/3741, and a PRO-3745 follow-up (Settings > Intelligence
shows the separate-storefront hint before Connect too);
CHANGELOG's
"Changes since 3.0.0-rc5" has PRO-3733, PRO-1968 (its bullet now points to
PRO-3741's), PRO-3741, PRO-3745 and PRO-1969 (its bullet widened by PRO-3742), the
others
change nothing a merchant sees. 3.0.0-rc5 is released as a GitHub pre-release on
the fork (https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc5),
built by the release workflow (run 37208243039) from commit 1911c41; the ZIP
and its .sha256 were checked after publishing (379 entries, `shasum -a 256 -c`
OK, sha256 8b616e83e7df09e0ad2d…, composer.json inside says 3.0.0-rc5); the
CI and Contract staleness workflows are green on 1911c41 (the contract check
for the first time since 2026-10-03, after PRO-3734 synced the copy).
The version is
`3.0.0-rc5` (composer.json, `ModuleInfo::VERSION`, the ModuleVersion
docblock, the upstream proposal; composer.lock content-hash refreshed, no
dependency change), and CHANGELOG has a "Changes since 3.0.0-rc4" list.
Everything since rc4 is in it: PRO-1967 (a stock change queues a marker,
the per-minute sync builds the catalog row; the Log shows a short-lived
*catalog_changed* row), PRO-3731 (the catalog's image and product links
open; broken in rc4 for the catalog import, the nightly re-sync and admin
saves), PRO-3732 (the abandoned-cart cart and store links open with web
server rewrites off), PRO-3730 (a busy store's abandoned-cart scan
reads only the carts changed in 24 h) and PRO-3734 (the Automations tab
shows the engine's stored trigger state after a save; the go-live step
says Smaily switches real sends on). PRO-3559, PRO-2477 and the PRO-1967
simplification pass change nothing a merchant sees, so they have no
bullet. The version cut is commit 4c1b8f9 on top of 51edd08; two commits
land on top of it before the release: a test-only fix (a unit-test helper
used a PHP 8.2 type, `(A&B)|null`, since 0b4c1ea, so CI's PHP 8.1 unit job
could not parse the suite; every PHP and PHTML file now lints on PHP 8.1,
no production file was affected, and the unit suite passes on PHP 8.1) and
PRO-3734 (its CHANGELOG rc5 bullet included).
2026-10-03 — 3.0.0-rc4 is released as a GitHub pre-release on
the fork (https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc4),
built by the release workflow from commit 5684a1c; the ZIP and its .sha256
were checked after publishing (377 entries, checksum OK, file contents
identical to a local build). The
version is `3.0.0-rc4` (composer.json, `ModuleInfo::VERSION`, the upstream
proposal; composer.lock content-hash refreshed), and CHANGELOG has a
"Changes since 3.0.0-rc3" list. Everything since rc3 is in it: PRO-3714
(a variant without a category of its own takes its parent's), PRO-3715
(an empty-SKU configurable order line names the variant bought),
PRO-3699 (leaving per-language accounts needs the password of an account
other than the website's), PRO-3717 + PRO-3719 (the connection status
after a save and after a reload describes the website account, merged
into one bullet; the fallback language is saved per website, its own
bullet), PRO-3718 (a new default fallback account needs its password on
the server; the form asks exactly when the server would refuse) and
PRO-3724 (browse tracking counts a cookie-notice acceptance only for the
current website; the bullet and the user guide's consent section now say
that on Luma Magento's notice is not shown again on a second website that
shares the cookie domain, on Hyvä it shows per website). PRO-3675 and the
simplification pass change no behaviour, so they have no bullet.
2026-10-02: 3.0.0-rc3 is released as a GitHub pre-release on
the fork (https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc3),
built by the release workflow from commit a1ff618; the ZIP and its .sha256
were checked after publishing (375 entries, checksum OK, sha256
0c500698…ab8b6; file contents byte-identical to a local build — the ZIP
bytes differ only by the zip tool, Linux in CI vs macOS locally). The
version is `3.0.0-rc3` (composer.json, `ModuleInfo::VERSION`, the upstream
proposal; composer.lock content-hash refreshed), and CHANGELOG has a
"Changes since 3.0.0-rc2" list (the bullets added since the rc2 cut moved
there from the rc1 list, plus PRO-3683, the engine contract 1.8.2, the
translated and corrected upgrade notices and the Mageplaza One Step
Checkout guide). Everything since rc2 is in it: PRO-3693 (guest email on
the standard checkout, its limits, one reminder per address per 24 h,
erasure stops pending reminders), PRO-3711, PRO-3690, PRO-3683, PRO-2506,
PRO-3692, PRO-3654, PRO-3694, PRO-3663, PRO-3680.
3.0.0-rc2 is released as a GitHub pre-release on the fork
(https://github.com/erkkimarkus/magento-connect/releases/tag/3.0.0-rc2),
built by the release workflow from commit 32059a2 (367 entries, checksum
OK, byte-identical to the local build, sha256 7322b213…3419). 3.0.0-rc1 is
released the same way from commit 9af1d9e (354 files, checksum OK).
Earlier: 2026-09-11, 2026-09-10._

## Where we are

- **PRO-3790 — connecting Campaign Intelligence also starts the
  customers import (2026-10-08, owner decision; branch
  `erkki/pro-3790-customers-import-on-connect` for a PR).** Storefront
  recommendations ask by the Magento customer id (`external_id`, §4),
  which live sync sends only on a customer save. `CatalogImportOnConnect`
  is now `Model/Backfill/ImportsOnConnect`: `start()` queues the catalog
  job, then the customers job, each through `JobManager::startIfIdle()`
  (engine target, website 0), and answers per type; both connect paths
  use it. After a connect the queue holds catalog (lower id, run first)
  then customers, both `pending`; no orders job (PRO-3742 unchanged; the
  not-connected guard is untouched). EngineExchange answers
  `customersImportStarted`; the panel shows "The customers import has
  started" (no Hold back — the card's Cancel import) and refreshes the
  Customers card; the config save adds a notice. Setup step copy says
  both imports start (EN + ET). Tests: unit (both paths), integration
  `ImportsOnConnectTest` (catalog then customers queued, no orders,
  `nextActive()` = catalog), `intelligence-connect.html`. Docs:
  ARCHITECTURE, USER_GUIDE, site (EN + ET), PILOT_CHECKLIST, CHANGELOG.
  Gates: unit, phpcs, phpstan, integration, test-js, PHP 8.1 syntax.
- **PRO-3963 — erasing a shopper's data keeps their queue rows on their
  retention schedule (2026-10-07; branch
  `erkki/pro-3963-erasure-keeps-retention` for a PR).** Found with
  PRO-3961: `Model\Privacy\LocalEraser` anonymised a kept (`sent` /
  `failed`) row of either queue with a direct UPDATE, which fired MySQL's
  `ON UPDATE CURRENT_TIMESTAMP` on `updated_at` — the time
  `Cron\QueueJanitor` counts retention from — so an erased shopper's rows
  stayed up to 30/90 days past their schedule. The UPDATE now writes
  `updated_at = updated_at`; confirmed on MySQL 8.4 by a new integration
  test (`GdprEraseTest::testAnAnonymisedRowKeepsItsRetentionSchedule`,
  fails without the fix): backdated rows keep their time through the
  erasure and the janitor removes them. Every other direct UPDATE on the
  queue tables (claim, unclaim/requeue, retry, cancel, pending-payload
  replace, outcomes) is a state change, not erasure — unchanged. What is
  erased and the retention periods are unchanged; no schema change.
  ARCHITECTURE (Art. 17 erasure), CHANGELOG "Changes since 3.0.0-rc10".
  Gates: unit, phpcs, phpstan, integration, PHP 8.1 syntax.

- **PRO-3961 — a queue row's failure, delivery or skip moves its
  last-changed time (2026-10-07; branch
  `erkki/pro-3961-queue-row-updated-at` for a PR).** The defect PRO-1964
  found: an outcome saved the row model, which holds `updated_at`,
  `claimed_at` and `claim_token` as the claim read them, so an explicit
  write of the old `updated_at` kept MySQL's ON UPDATE from firing and
  Details, the Log, the Dashboard's recent activity, the health check and
  the retention sweep all dated the outcome at the row's previous change.
  Now `EventQueue::markSent()/markSkipped()/markFailed()` and the batched
  `markFailedMany()` write `outcomeFields()`: claim cleared (both columns
  null) and `updated_at` = the outcome's time from the injected clock.
  `IngestQueue` had the same defect (its `markSent()`/`markFailed()`);
  fixed the same way. No schema change; rows already stored keep their
  time until their next outcome. Readers checked: `AttemptHistory`
  (Details), `log/details.phtml` "Updated", the Log grid's Updated column
  (sort/filter), `DashboardStats::recentActivity()`, `QueueHealth`
  (Dashboard/Log banners, `Cron\HealthCheck`), `Cron\QueueJanitor`
  retention (now counted from the outcome); `requeueStale()` reads
  `claimed_at` of `sending` rows only, untouched. New integration tests
  (fail without the fix): single and batched outcomes in both queues,
  Details entries, the health count. ARCHITECTURE (Claiming), CHANGELOG
  "Changes since 3.0.0-rc10". Gates: unit, phpcs, phpstan, integration,
  PHP 8.1 syntax.

- **PRO-3962 — a Campaign Intelligence batch that fails for one reason
  is recorded in one write and one log line (2026-10-07; branch
  `erkki/pro-3962-ingest-batch-failure-write` for a PR).** PRO-1964 for
  the ingest queue: `Cron\FlushIngestQueue` hands a transport failure, a
  whole-batch refusal, a refused catalog/remove wrapper, the keyless
  catalog/remove rows and each D6 per-item reason shared by several rows
  to the new `IngestQueue::markFailedMany()` — one UPDATE (CASE on the id;
  200 rows a statement, every ingest batch is ≤100) and one error line
  "Ingest events failed permanently" with count, domain and ids for the
  rows it parks; a group of one goes through `markFailed()`. The CASE
  write moved out of `EventQueue` into the shared `Model\Queue\RowWriter`
  (one implementation for both queues); each queue keeps its own
  `FAILURE_COLUMNS` (attempts, status, next_retry_at, last_error,
  sent_payload, last_response, claim_token, claimed_at, updated_at — the
  claim cleared and `updated_at` the outcome's time, PRO-3961's
  `outcomeFields()`, as a single row's save). A row parked alone now
  logs the group's wording with count 1 in both queues ("Queue events
  failed permanently" / "Ingest events failed permanently"); CHANGELOG
  bullet. The account-refusal release path (PRO-2451/PRO-3752) is
  unchanged. Gates: unit, phpcs, phpstan, integration, PHP 8.1 syntax.

- **PRO-1964 — a batch that fails for one reason is recorded in one
  write and one log line (2026-10-07; branch
  `erkki/pro-1964-batch-failure-write` for a PR).** `Cron\FlushEventQueue`
  groups a flush's failed rows by verdict (reason, Retry-After,
  permanent) and hands each group to the new
  `EventQueue::markFailedMany()`: one UPDATE (CASE on the id for values
  that differ; 200 rows a statement) and one error line "Queue events
  failed permanently" with count and ids for the rows it parks. Each row
  keeps its own ladder step and exchange; a single-row group goes through
  `markFailed()` unchanged, so mixed reasons and the PRO-3753 one-by-one
  resend keep a reason per row. The batch writes the claim and timestamp
  columns as the model holds them, as the model's own save does, so a
  batched row ends exactly like a single one. Found on the way, not
  changed: that save writes back `updated_at` (and `claimed_at`) as the
  claim read them, so `updated_at` never moves on markFailed/markSent/
  markSkipped and Details dates the latest failure or the delivery at the
  row's previous change. Marketing queue only — `IngestQueue` has its own
  `markFailed()` and is unchanged. CHANGELOG bullet for the log line.
  Gates: unit, phpcs, phpstan, integration, PHP 8.1 syntax.

- **PRO-3936 — the rc8 package installs on a clean store by the install
  guide (2026-10-07; docs only, no CHANGELOG bullet).** As PRO-3769 for
  rc7: the ZIP and its .sha256 were downloaded from the 3.0.0-rc8 GitHub
  release with `gh release download` (`shasum -a 256 -c` OK on macOS,
  `sha256sum -c` OK in the container; 397 entries, archive comment
  055276c, composer.json 3.0.0-rc8) and installed into a real `app/code`
  on a fresh Magento 2.4.8-p4 without sample data, in production mode, on
  a separate temporary compose project (own name, containers, volumes,
  port; sandbox image; no working-tree mount — the sandbox's `magento2`
  and `magento2_db` stayed Up with the same container IDs). The guide's
  new "Before you start" version check: `bin/magento --version` →
  "Magento CLI 2.4.8-p4", `php -v` → "PHP 8.3.35 (cli)" (the admin footer
  says "Magento ver. 2.4.8-p4"); the twelve required Magento modules are
  enabled. Then step by step: extract (the guide's `mkdir -p` creates
  `app/code`), the production sequence with `setup:static-content:deploy
  en_US et_EE` (every command exit 0), `crontab -l` / `cron:install` (the
  cron package installed in the temporary container first), module
  enabled, six `smaily_*` tables, the "ready to set up" notice under the
  bell, four menu entries. **Guide fix:** step 2's `unzip
  smaily-connect-magento2.zip …` runs from the Magento root, so with the
  ZIP downloaded into another folder (step 1 lets it be any folder) it
  stops with "cannot find or open"; step 2 and the update section now say
  to give `unzip` the ZIP's full path. All eight `smaily_*` cron jobs
  finished `success` without a Smaily or engine connection (the daily
  janitor, the nightly `smaily_catalog_manifest` and the 5- and 15-minute
  jobs queued by hand); `SendCatalogManifest::execute()` was also run
  directly with the log level at info: `smaily_connect.log` says "Nightly
  catalog manifest not sent" with the reason "Campaign Intelligence is not
  connected, or refuses this store", and no flag, queue or Log row is
  written (no unsent-nights flag, as no list is due). No `exception.log`,
  no `var/report`, no Smaily line in `system.log`. Initial setup opens
  from every module page; step 1 refuses Continue without a tested
  connection ("Fill in the credentials and press Test connection
  first."); "Using a separate storefront?" opens the Storefront URL field
  (placeholder https://shop.example.com) with its help text. Dashboard
  (Not connected everywhere), Settings (all five tabs) and Log (empty)
  were opened with `setup_completed` set in the database: no console
  errors. The update section (maintenance on, `rm -rf`, `mkdir -p`, fresh
  extraction, the step 3 commands, second `setup:upgrade`) passed;
  settings and tables kept, the Dashboard opens without the initial
  setup, the group runs again with no failure. TESTING.md records the
  run. Stack removed afterwards (`down -v`; no image pulled).

- **PRO-3854 — the nightly catalog manifest (2026-10-07; unreleased,
  after rc7).** `Cron/SendCatalogManifest` (`smaily_catalog_manifest`,
  03:30 admin time zone) → `Model/Engine/CatalogManifest`: every product
  the catalog import sends a row for, less the disabled ones (owner
  decision 2026-10-07), as `{sku, in_stock}` from
  `CatalogPayloadBuilder::manifestItem()` (the row's own key and stock;
  a variant not visible on its own is `false`, as its tombstone), paged
  1,000 at a time by `CatalogProductLoader::loadForManifest()` (status +
  visibility only), sent in one request (`Client::catalogManifest()`, map
  key `ingest_catalog_manifest`, fallback path for a pre-1.12.0 map, 60 s
  timeout), never queued; one Log row (domain `catalog_manifest`, written
  after the send, `failed` at once on an error, refused by ResendGuard's
  `nightly`). Skipped (info log only) while sending is not allowed, while
  the catalog import is queued or running and some active job moved in
  the last hour (`JobManager::isInProgress()`, the Woo PRO-3886 bound), or
  while `catalog` / `catalog_remove` / `catalog_changed` rows are pending
  or sending; over 50,000 enabled products: a `failed` row with
  `TOO_MANY_PRODUCTS` (EN + ET). Unit (CatalogManifestTest, builder,
  client, guard, translation) + integration on MySQL (CatalogManifestTest:
  waits on real queue/job rows, the Log row, 50,000 sent / 50,001 refused,
  the guard; JobManagerTest stall bound). Sandbox (worktree classes loaded
  over the mount, a Settings double, mock engine in the container; the
  sandbox's stored connection — a live tenant — untouched): 2,046 products,
  one request of 81 KB, items `sku` + `in_stock` only, 187 in stock;
  mapped key and fallback path both reached the mock; refused,
  disconnected and a waiting marker sent nothing; parity with
  CatalogIngest's own build over the whole catalog in a rolled-back
  transaction (one product out of stock, one disabled): 0 mismatches, the
  disabled one left out; peak ~7 MB above baseline (22 MB cold). Docs:
  ARCHITECTURE (new bullet, lifecycle, cron table, guard), USER_GUIDE (The
  nightly product check), CHANGELOG.
- **PRO-3854 — engine contract synced v1.8.3 → v1.12.0 (2026-10-07, doc
  only, no sender change).** Byte-identical with engine main 15785a0
  (`bin/check-contract-staleness.sh <engine checkout>/docs/RECENGINE_API_CONTRACT.md`:
  OK, md5 `d4a98e3d…`); the Contract staleness job was red on v3 since
  2026-10-05. Every 1.9.0–1.12.0 addition is optional for a connector and
  not built: §15 storefront recommendations (1.9.0, guests by visitor token
  1.10.0), a store-created `vs_` visitor token on the order (1.11.0), the
  nightly §3c catalog manifest (1.12.0). 1.9.1 retires
  `recommendations_preview` / `recommendations_issue`; the module never
  called either (no reference outside the contract). Checked against the
  connector: the order forwards `smaily_rec_ctx` whenever the context
  cookie is set (`OrderPayloadBuilder::attribution()`), new endpoint keys
  pass the setup-exchange URL check. **Two gaps, code (reported, not
  changed):** the landing capture (`attribution.js`, Hyvä
  `smaily-attribution.js`; HEADLESS_STOREFRONTS step 2 says the same) sets
  `smaily_rec_ctx` only when the URL has `smaily_ctx` and never clears it
  on a `smaily_rec` landing without one (the 1.9.0 context cookie rule);
  and `AttributionShape` and both capture scripts accept only `vt_`
  visitor tokens, so a `vs_` token in `smaily_rec_uid` (set by a headless
  storefront or another tool) is dropped from the order. Docs:
  ARCHITECTURE (the engine deletes by absence only through the §3c
  manifest, which this module does not send; cites v1.12.0),
  UPSTREAM_PROPOSAL cites v1.12.0, CHANGELOG (rc7-list bullet as for
  1.8.3; the 3.0.0 "Ships the … contract" line).
- **PRO-3798 — only a refunded credit memo marks lines returned
  (2026-10-07; unreleased, after rc7).** `OrderPayloadBuilder` read every
  credit memo of the order for `items[].returned_at`, whatever its state.
  It now reads the memo's `state` and counts only
  `Creditmemo::STATE_REFUNDED`: a canceled memo gave nothing back, and a
  pending (open) one has not refunded yet — §5 prefers a missed return to
  a wrong one. Vendor read: every core refund path stores the memo as
  refunded (`CreditmemoService::refund()`, `RefundOrder`, `RefundInvoice`,
  the gateway refund notification through the same service);
  `CreditmemoManagementInterface::cancel()` throws and nothing in core sets
  the open or canceled state, so both come only from an extension. Such a
  change saves an existing memo, which queues nothing (PRO-1955's plugin
  queues only a new memo; `OrderSaveAfter` only if the extension also moves
  the order's state or refunded total), so an order already sent keeps the
  return until its next queued save or an order import (CHANGELOG says
  so). **Evidence:** sandbox, real credit memo through
  `CreditmemoManagementInterface::refund()` offline (zero-value, whole
  line, order 000000001), then the memo's state saved through the
  repository as canceled and as open, all in one transaction rolled back
  (queue 0 before and after; nothing flushed or sent); the builder under
  test loaded from a copy in the container before the module's (the main
  checkout untouched). Old builder: returned_at in all three builds. New:
  returned_at with the memo refunded, none when canceled or open. Each
  state save queued 0 rows. Unit `OrderPayloadBuilderTest` (refunded,
  canceled, open; red without the change). No DI change. Docs:
  ARCHITECTURE, USER_GUIDE (what syncs, Orders), CHANGELOG.

- **PRO-1955 — a zero-value credit memo queues its order (2026-10-05;
  unreleased, after rc7).** `OrderSaveAfter` queued an order only when its
  state or its `total_refunded` moved, but `items[].returned_at` comes from
  the credit memos; a credit memo that moves quantity and no money moved
  neither, so the return reached the engine only with a later, unrelated
  save. Now `Plugin/Engine/CreditmemoSave` (after the credit memo
  resource's `save()`; the `sales_order_creditmemo_save_after` event fires
  before the memo's items are written — `VersionControl\AbstractDb::
  processAfterSaves()`, verified in vendor) queues the order for a credit
  memo created by that save (`getOrigData('entity_id') === null`).
  **Dedupe (one refund → one order row):** the money gate stays; both
  saves go through `Model\Engine\OrderIngest`, which collapses builds
  next to each other for one order, one of them a credit memo's: an
  identical build queues nothing, a different one replaces the payload of
  the row the first queued while it is pending with 0 attempts
  (`IngestQueue::replacePendingPayload()`), else (claimed meanwhile) a row
  of its own. Order saves without a refund queue as before. Found on the
  way (vendor read + sandbox): the REST refunds (`RefundOrder`,
  `RefundInvoice`) save the order BEFORE the credit memo, so a REST refund's
  order row had no `returned_at`; the credit memo's build now replaces it.
  **Evidence:** sandbox, real credit memos through Magento's services
  (`CreditmemoManagementInterface::refund()` offline,
  `RefundOrderInterface::execute()` without notification), each in a
  transaction rolled back (queue empty before and after; nothing flushed or
  sent), on a complete sandbox order with one line: old code (HEAD
  ecb8ae9, recompiled) → zero-value memo (grand total 0, `total_refunded`
  null → 0, state unchanged): 0 rows; REST zero-value: 0 rows; REST full
  refund: 1 row without `returned_at`. New code → zero-value: 1 row
  `completed` with `returned_at`; REST zero-value: 1 row with
  `returned_at`; money refund (state unchanged): 1 row; full refund
  closing the order (admin and REST): 1 row `refunded` with `returned_at`.
  Unit `OrderIngestTest` (both save orders, identical build, claimed row,
  no-refund saves, another order), `CreditmemoSaveTest`,
  `OrderSaveAfterTest`. DI changed (OrderSaveAfter, new plugin); sandbox
  `setup:upgrade && setup:di:compile` OK. Docs: ARCHITECTURE, CHANGELOG
  ("Changes since 3.0.0-rc7").

- **PRO-1957 — the abandoned-cart `over_10_products` field no longer
  lingers from a larger cart (2026-10-05; unreleased, after rc7;
  orchestrator decision 2026-10-05, reversible).** The field was written
  only for a cart of more than 10 lines and was not part of the
  always-written prefill PRO-1760 gave the product slots, so after a large
  cart the Smaily contact kept `true` and a later reminder for a small cart
  still carried it. `AbandonedCart\PayloadBuilder::productFields()` now
  prefills it with `''` beside the ten blank slots (the slots' own empty
  value) and sets `'true'` past the tenth line, as before. Smaily keeps an
  absent field and overwrites an empty one, so the next smaller cart's
  reminder clears it. **Evidence:** unit `PayloadBuilderTest`: an 11-line
  cart → `true`, then a 1-line cart → `''` (key present), a 10-line cart →
  `''`, an empty cart → `''`; red without the change (the small cart had no
  key). No DI change. Docs: USER_GUIDE (abandoned-cart template fields),
  CHANGELOG ("Changes since 3.0.0-rc7"). Woo and Shopify counterparts
  (same rule) are for the orchestrator to file.

- **Simplification pass, behaviour-neutral (2026-10-05)**, over PRO-3760,
  PRO-3765, PRO-3768, PRO-3753 (a) and (b) and PRO-3767. The keyed hash of
  an address is one class, `Model\Privacy\AddressKey` (`of()`, `keys()`,
  the crypt key parsed once per instance, the trim + lower case inside;
  `ProfilingOptOuts`, `ContactEntity`, `ProfilingConsent` and `LocalEraser`
  take it, `ProfilingOptOuts::addressKey()` is gone). Its output is
  byte-identical: unit `AddressKeyTest` pins the HMAC and sha1 keys of a
  placeholder address, with one and with two crypt keys, and of a long
  mixed-case address, computed with the code before the change.
  `ContactEntity::forms()` is the hash + address pair the erasure and the
  consent replay match, `ContactEntity::isHash()` the 64-hex test the Log's
  `EntityLabel` uses; `ProfilingOptOuts::moments()` returns each address's
  key with its moment, so the replay hashes an address once per batch. The
  replay's domain-to-shopper-field map left `Cron\FlushIngestQueue` for
  `Model\Privacy\OptOutReplay::afterConfirmed()` (the flusher hands over
  the confirmed items); Details' "waits for the Campaign Intelligence
  account" rule is `Model\Log\AccountWait::waits()`.
  `ApiException::isInvalidData()` is the 203 group-split test of the
  contact sync and the contacts import (two loops, kept apart).
  `CatalogIngest::enqueueRemovals()` builds the §3b `catalog_remove` row
  for the admin delete and the import's Delete; `buildChanged()` queues
  through `enqueueBuilt()`; `buildTombstones()` drops its unreachable
  connected check (its one caller asked it first). The import's Delete asks
  `importexport_importdata` for entity and behaviour once per bunch
  iteration instead of on every bunch read and delete
  (`ProductImportDelete::endOfBunches()` at the read past the last bunch),
  and finds a bunch's configurable children with one query
  (`ParentProductResolver::configurableChildIds()`: the super link joined
  to the parent, lowest parent per child, as the per-product lookup — whose
  memo it fills). Kept: `ProfilingConsent` still normalises the address at
  each public entry point (now `AddressKey::normalise()`): the normalised
  address is also what it sends to Smaily and the engine and what its
  empty-address guard checks, so dropping it would change the wire.
  Evidence: unit, phpcs, phpstan, integration (MySQL 8.4 on 3316; the
  replay, contact-entity, GDPR erasure and CSV-delete tests unchanged in
  their assertions), browser harnesses, PHP 8.1 lint; DI changed
  (ProfilingOptOuts, ContactEntity, ProfilingConsent, LocalEraser,
  ProductImportDelete, ProductDeleteBefore, FlushIngestQueue, Log Details);
  sandbox `setup:upgrade && setup:di:compile` OK. Docs: ARCHITECTURE.

- **PRO-3780 — the checkout email-field browser test always writes its
  result (2026-10-05; tooling, no CHANGELOG bullet).** `RESULT: FAIL (no
  result)` means Chrome printed nothing at all, not a page cut short (a
  cut-short page prints `RESULT: RUNNING`, as in PRO-3738). Repro before:
  macOS Google Chrome, `Test/Js/email-mixin.html` in 6–10 parallel loops,
  3 of 990 runs printed nothing, each Chrome killed (exit 137, no stderr;
  the one timed run died in under a second, i.e. at start-up, before the
  page ran); 30 of 30 sequential runs green; Linux (Debian Chromium 154,
  throwaway php:8.3-cli container) 0 of 190 (sequential, 3 parallel loops,
  CPU burners). Not the virtual-time cause of PRO-3738: a probe read
  virtual time at the start of the checks at 110–650 ms, never the end of
  the budget. Fix: `bin/test-js.sh` starts a browser that printed nothing
  again, up to three starts, and logs each restart; a page that printed
  anything is never run again (fake browser killed once per page → all 4
  pages green, exit 0; killed every start → 4 × `RESULT: FAIL (no
  result)`, exit 1). And the two pages that waited on script or frame
  loads with no timer pending (`email-mixin.html`, `automations-save.html`)
  now keep the 10 ms interval too, so every page does (TESTING.md rule).
  After: `bin/test-js.sh` 20 of 20 full runs green on macOS and on Linux
  (email field 23 checks each run, no restart needed); 10 parallel loops on
  macOS, 400 of 400 email-field runs green (no Chrome killed this time, so the restart
  was exercised by the fake browser only).
  Seen on the way, not fixed: with 8 Chromes at once on macOS,
  `tracker-consent.html` printed `RESULT: RUNNING` in 5 of 200 runs even
  with its interval (hypothesis: the budget runs out while frames load
  slowly);
  sequential runs and CI run one Chrome at a time. TESTING.md ("Browser JS
  harnesses"), CLAUDE.md gates.

- **PRO-3767 — a contact row's entity is never cut (2026-10-05;
  unreleased, after rc7; owner decision 2026-10-05: keep the address,
  stop it from being cut; no schema change).** `contact.sync` and
  `automation.trigger` rows stored the contact's plain address in the
  64-character `smaily_event_queue.entity_id`; a longer one was cut
  silently (`SET SQL_MODE=''` in Magento's adapter, verified in vendor).
  The checks that match a contact's rows by the FULL address then missed:
  a purchase did not withdraw the waiting abandoned-cart reminder
  (`cancelPendingAutomation()`), and the purchase marker's "a reminder went
  out" check (`hasDeliveredAutomation()`) found nothing, so no marker. Now
  `Model\Queue\ContactEntity::of()` keeps an address that fits as it is
  and stores a longer one as `ProfilingOptOuts::addressKey()` of the
  trimmed, lower-case address (64 hex, as consent rows, PRO-3765).
  `ContactSync\SyncDispatcher`, the only writer of these rows and the only
  caller of the two checks, passes every entity through it. **Every reader
  checked:** `ResendGuard` / `laterDeliveredOfSameTrigger()` and
  `Log\Resend` compare or copy stored values (consistent by
  construction); `LocalEraser` already matches `[address,
  addressKey(address)]` and the payload; `Log\EntityLabel` (grid column,
  Details, Dashboard) now shows a 64-hex entity of a contact-sync or
  automation row by its first 12 characters, as for consent rows (an
  address always has an "@", so it never reads as a hash); Details shows
  the address in the payload. The abandoned-cart "one reminder per address
  per 24 h" rule reads `smaily_abandoned_cart.email` (255 characters), not
  the queue. No reader needed a schema change. Rows queued before keep the
  cut address until retention (no data patch). **Evidence:** integration
  `Queue/ContactEntityTest` on MySQL (real dispatcher, queue, guard,
  eraser): an 89-character address → both rows' entity is its keyed hash,
  the payload holds the address; the purchase withdraws the waiting
  reminder; after a delivered reminder the marker is queued; a later
  delivered reminder refuses Send again on the failed one (superseded);
  the erasure removes the pending row and anonymises the sent one; an
  ordinary address is stored as it is and the Log's Entity filter finds
  its two rows. Without the dispatcher change the first three fail (the
  guard and erasure tests pass either way: cut values equal each other,
  and the erasure also matches the payload). Unit `ContactEntityTest`,
  `EntityLabelTest`. DI changed (SyncDispatcher); sandbox `setup:upgrade
  && setup:di:compile` OK. Docs: ARCHITECTURE, USER_GUIDE (Log), CHANGELOG.

- **PRO-3753 (b) + (c) — Details on a row waiting for the Campaign
  Intelligence account; the retry window is about 81 minutes (2026-10-05;
  unreleased, after rc7).** (b) While the account is refused, a pending
  engine ingest row (not claimed, `Cron\FlushIngestQueue`) and a pending
  row of a paused marketing handler (`engine.identity_merge`,
  `engine.profiling_consent`; PRO-2466/PRO-3752) read "Waiting for the
  next queue flush (runs every minute)." in Details — or the next retry's
  time, for a row that had failed before — although neither is sent until
  the account is active again. The line is built in
  `view/adminhtml/templates/log/details.phtml`; now
  `Controller\Adminhtml\Log\Details::waitsForAccount()` (pending, and
  `Engine\Settings::isRefused()` for an ingest row or the type in
  `HandlerPool::pausedEventTypes()` for a marketing row — the facts the
  flushers use) sets `waits_for_account`, and the template says "Waiting
  for your Campaign Intelligence account to be active again; this row is
  sent then." (EN + ET), ahead of the next-retry and next-flush lines. A
  contact sync or automation row is unchanged. Unit `DetailsTest` (refused:
  an ingest row and a consent row wait, a contact sync and a failed row do
  not; not refused: nothing waits); the template branch was checked by a
  scratch render (flag on: the new line; off: the flush line). DI changed
  (two constructor arguments); sandbox `setup:upgrade &&
  setup:di:compile` OK. (c) The ladder is `BACKOFF_SECONDS = [60, 300,
  900, 3600, 21600]` with `MAX_ATTEMPTS = 5`; the fifth failure parks the
  row before a fifth wait, so the attempts span 60 + 300 + 900 + 3600 s =
  4860 s, about 81 minutes; 21600 s only caps a Retry-After in the
  marketing queue. "Six hours" / "1 min → 6 h" are corrected in the
  CHANGELOG 3.0.0 list (the PRO-1800 bullet), USER_GUIDE (Log),
  PILOT_CHECKLIST §9, ARCHITECTURE (retry policy), the PRO-1800 entry
  below, and the `EventQueue` / `Failure` docblocks. Docs: USER_GUIDE
  (deactivated account; Details), ARCHITECTURE, CHANGELOG.

- **PRO-3753 (a) — a 203 on a group of contacts is sent again one contact
  per request (2026-10-05; unreleased, after rc7; owner decision
  2026-10-05).** Smaily answers one `{code, message}` per request, never
  per contact, so one invalid contact in a store view's `contact.sync`
  group (`Queue\Handler\ContactSyncHandler`, up to the flush's 200-row
  batch) failed every row of it with `permanent_envelope_203` (PRO-1962).
  Now `ContactSyncHandler::deliver()` posts the group, and on an
  `ApiException` with code 203 for more than one contact posts each
  contact again alone, in the same run, through the same client; each
  row's result, `sent_payload` and `last_response` come from its own
  request. A single contact's 203 stays permanent at once (not sent
  again); any other failure of the group (another envelope code, HTTP
  error, network) stays the group's, as before. **The contacts backfill had
  the same group-fails-together behaviour** (`Backfill\ContactsProcessor`,
  one post per store view of a 500-contact page, all counted failed on
  any exception): it gets the same fallback (`post()`), so only the
  refused contacts count as failed. Cost: 1 + group size requests, only on
  a 203. WooCommerce posts one contact per request, so the outcome is now
  the same. **Evidence:** integration (real handler and SmailyClient, HTTP
  faked): a group of 5 with one invalid → 6 requests (the group, then each
  alone), 4 rows sent, the refused row failed after 1 attempt with
  `permanent_envelope_203: Smaily API returned code 203: Invalid email`
  and its own payload and answer; a group answered with code 216 → 1
  request, all 5 pending on the ladder; a single-contact 203 → 1 request.
  Unit: a backfill page of 5 with one invalid → 6 posts, progress 4 sent,
  1 failed; another code → 1 post, 2 failed. Both new group tests fail
  without the change. No new string, no DI change. Docs: ARCHITECTURE
  (the request cost), USER_GUIDE (Log; Historical import), CHANGELOG.

- **PRO-3747 — the separate-storefront hint on Settings > Intelligence
  follows a Connection save (2026-10-05; unreleased, after rc7).** The hint
  of PRO-3745 was rendered server-side only when no Storefront URL was
  saved, so after saving one on the Connection tab the Intelligence tab
  (the tabs switch without a reload) still showed it, and a page loaded
  with a URL had no hint to show after clearing it. Now
  `panel/intelligence.phtml` always draws it in the disconnected block,
  `display:none` while a Storefront URL is saved, and
  `panels.saveStep('connect')` (`panel/panels-js.phtml`) — the save the
  Connection tab's **Save Connection** runs — shows or hides it by the
  `storefront_url` it posted, once the server accepted the save (a refused
  save changes nothing). The initial setup posts no Storefront URL, so its
  note stays as drawn. No new string, no server change. Browser
  `Test/Js/intelligence-connect.html` 41 → 47 checks (Settings: a saved
  URL hides the hint, a cleared one shows it again, a refused save leaves
  it; the "with a Storefront URL" checks now read "not visible", since the
  hint is drawn hidden); red without the panels-js change (4 checks) and
  without the always-drawn hint (2 checks). USER_GUIDE (Connecting: no
  reload), PILOT_CHECKLIST §2 (no reload), ARCHITECTURE, TESTING,
  CHANGELOG.

- **PRO-1958 (narrowed 2026-10-04) — the integration tests' cart tables
  carry Magento's real columns (2026-10-05; tests only, no CHANGELOG
  bullet).** `SchemaInstaller::createQuote()` / `createQuoteAddress()`
  now build `quote` (49 columns) and `quote_address` (62 columns) from
  `vendor/magento/module-quote/etc/db_schema.xml` — every column and
  index as shipped (`QUOTE_CUSTOMER_ID_STORE_ID_IS_ACTIVE`,
  `QUOTE_STORE_ID_UPDATED_AT`, `QUOTE_ADDRESS_QUOTE_ID`), no foreign keys
  (the `store` table is not in the harness); the translator learned
  `decimal` and `datetime`. Columns other modules add (GiftMessage's
  `gift_message_id`) are not mirrored. The 2.8.x `reminder_date` /
  `is_sent` columns moved out of the quote mirror into
  `addLegacyQuoteColumns()`, which only the legacy-columns patch test
  calls. **It bites:** with a temporary unqualified
  `addFieldToFilter('customer_id', …)` in the cron's quote select, all 7
  `AbandonedCartTest` tests fail with MySQL 1052 "Column 'customer_id' in
  where clause is ambiguous"; on the old minimal mirrors the same change
  passed 7/7 (reverted). The cron's own filters are all qualified
  (`main_table.…`); `updated_at`, `created_at` and `store_id` were
  already ambiguous through the cart-state join. Full integration suite
  green on the real mirrors (no test needed a change beyond the legacy
  columns).

- **PRO-3768 — products deleted by a product import reach the engine
  (2026-10-05; unreleased, after rc7).** Magento's product import with the
  Delete behaviour (`Import\Product::_deleteProducts()`, verified in
  2.4.8 vendor) deletes each bunch with one SQL DELETE and fires only
  `catalog_product_import_bunch_delete_commit_before` (after the DELETE,
  inside its transaction; `adapter`, `bunch`, `ids_to_delete`) and
  `…_bunch_delete_after` — no `catalog_product_delete_before`, so nothing
  was sent and the engine kept the products recommendable (the gap
  PRO-3740 reported). Now `Observer/Engine/ProductImportBunchDelete` on
  `commit_before` queues the removal `ProductDeleteBefore` queues: one
  `catalog_remove` row per deleted product (one insert), and for a
  configurable child the per-SKU tombstone instead. **Neither import event
  has the products any more** (the brief assumed the `_before` one would):
  the child's `catalog_product_super_link` row cascades away with the
  DELETE, so `Plugin/Engine/ProductImportBunch` (after-plugin on
  `ImportExport\Model\ResourceModel\Import\Data::getNextUniqueBunch()`,
  the bunch read right before the DELETE) finds the bunch's configurable
  children and builds their tombstones then
  (`CatalogIngest::buildTombstones()`: one collection, forced
  tombstones; `enqueueBuilt()` queues them in the delete's transaction).
  Both hooks act only on `catalog_product` + `delete` in the import data
  source — Replace runs the same delete and creates the products again
  (new entity ids; unchanged: nothing is sent, as before). No wire change.
  No ImportExport type named (optional dependency, as the MSI plugins).
  **Evidence:** integration test on MySQL (the real `Import\Data` resource
  model on an `importexport_importdata` mirror, a real DELETE in a
  transaction with a cascading super-link FK, the real ingest queue): the
  child gets its tombstone, the standalone product §3b, a rolled-back
  delete and a Replace queue nothing; with the plugin's `prepare()` call
  removed the test fails (the child gets §3b). **Sandbox** (engine
  connected, nothing sent): a real `Magento\ImportExport\Model\Import`
  Delete run of two throwaway products created by SQL inside one outer
  transaction that was rolled back (indexers all on schedule, so the
  import's reindex ran nothing): `deleted: 2`, queued `catalog`
  `SMAILY-IT-VARIANT` `in_stock: false`, `tags.product_id` 68 (its
  configurable parent) and `catalog_remove` 2052; after the rollback no
  throwaway product and no queue row are left. A direct database delete,
  or a third-party importer that writes the catalog tables itself, still
  sends nothing (out of scope); the user guide now says how to take such a
  product out of the recommendations: save a product with the same SKU
  with **Enable Product** off (the save sends it as out of stock), then
  delete it in the admin if wanted. No admin page or command removes a
  product from the engine by itself (follow-up candidate). Sandbox
  `setup:upgrade && setup:di:compile` OK. Docs: ARCHITECTURE (the import
  delete path), USER_GUIDE (What syncs; Historical import), CHANGELOG
  (opens "Changes since 3.0.0-rc7").

- **PRO-3769 — the rc7 package installs on a clean store by the install
  guide (2026-10-05; docs only, no CHANGELOG bullet).** As PRO-3748 for
  rc6: the ZIP and its .sha256 were downloaded from the 3.0.0-rc7 GitHub
  release (`shasum -a 256 -c` OK on macOS, `sha256sum -c` OK in the
  container; 386 entries, composer.json 3.0.0-rc7) and installed into a
  real `app/code` on a fresh Magento 2.4.8-p4 (PHP 8.3) without sample
  data, in production mode, on a separate temporary compose project (own
  name, containers, volumes, ports; sandbox image; no working-tree mount —
  the sandbox stayed untouched and Up with the same container IDs),
  following `docs/INSTALLING.md` step by step: extract (the guide's
  `mkdir -p` creates `app/code`), the production sequence with
  `setup:static-content:deploy en_US et_EE`, `crontab -l` / `cron:install`
  (the cron package installed in the temporary container first), module
  enabled, six `smaily_*` tables, the "ready to set up" notice under the
  bell, four menu entries. All seven `smaily_*` cron jobs finished
  `success` without a Smaily or engine connection (the daily janitor and
  the 5- and 15-minute jobs queued by hand); no `exception.log`, no
  `var/report`, no Smaily_Connect line in `system.log`. Initial setup
  opens from every module page; step 1 refuses Continue without a tested
  connection; the Campaign Intelligence step renders the
  separate-storefront hint. Dashboard, Settings (all five tabs;
  Intelligence with its hint) and Log (empty, so no Details to open) were
  opened with `setup_completed` set in the database: no console errors,
  Not connected everywhere, no browser request left the store.
  `smaily:gdpr` without a connection (placeholder address
  u1@example.invalid): `export` prints `engine: null` and the three empty
  local sets, exit 0; `erase` without `--force` asks for it, exit 1;
  `erase --force` prints the three local lines and "Campaign Intelligence
  is not connected; no engine data to erase.", exit 0 — rc7's eraser
  (it now takes `ProfilingOptOuts`, PRO-3765) resolves in the compiled DI.
  PRO-1969/PRO-3742 hold: `smaily:backfill:start catalog|customers|orders`
  prints the not-connected sentence and exits 1, the admin import
  endpoint answers a catalog start with the same message, and no job or
  queue row is written. The update section (fresh extraction, the step 3
  commands, second `setup:upgrade`) passed; settings and tables kept, the
  Dashboard opens, the group runs again with no failure. No guide step
  needed a change. TESTING.md records the run. Stack removed afterwards
  (`down -v`; no image pulled).

- **PRO-3740 — engine contract synced v1.8.2 → v1.8.3 (2026-10-05, doc
  only, no sender change).** Byte-identical with engine main 867af45
  (`ENGINE_CONTRACT_READ_TOKEN=… bin/check-contract-staleness.sh`, GitHub
  API path: OK, md5 `c565c604…`; engine main has no newer contract
  commit). PATCH bump: §3 now states the *Catalog sync lifecycle* — a full
  import at setup, then changes (product save, stock change, archive as
  `in_stock=false`, hard delete via §3b), each re-sending the whole row
  (the UPSERT clears an optional field a row omits; only `tags` merges), a
  full import by hand whenever the merchant starts one, no scheduled full
  re-sync; what the engine re-derives itself; when a plugin full import is
  required (a contract change that says so, a sender-side mapping fix,
  lost change events); and the known gap that a lost change or delete is
  not healed automatically. §3b is now "the only delete signal". This
  resolves the PRO-1968 note below: the contract no longer calls a
  periodic plugin re-sync the reconciler. Checked against the connector:
  it matches — the full catalog at connect (PRO-3741), product save, the
  stock markers and both delete paths send full rows
  (`CatalogPayloadBuilder::build()`; `buildTombstone()` is the full row
  with `in_stock: false`), the import by hand on the card or
  `smaily:backfill:start catalog`, no scheduled re-sync (PRO-1968),
  changes through the durable ingest queue with retries and failures in
  the Log. **One gap, code (reported, not changed):** a product deleted
  where `catalog_product_delete_before` does not fire — a CSV import's
  delete (`CatalogImportExport\Model\Import\Product::_deleteProducts()`
  deletes through `objectRelationProcessor`, with only the
  `catalog_product_import_bunch_delete_*` events) or a direct database
  delete — never reaches §3b and stays recommendable in the engine; a
  catalog import does not remove it. A Story candidate (an observer on the
  import's bunch delete). Docs: ARCHITECTURE (catalog lifecycle — the
  contract now states it; whole rows; an import does not remove; a
  release adding a catalog field tells the merchant to import), USER_GUIDE
  (Historical import: the import does not remove products; a release note
  may ask for an import), CHANGELOG (rc7 bullet, as rc3 had for 1.8.2; the
  3.0.0 "Ships the … contract" line; the 3.0.0 stock-changes bullet no
  longer promises the nightly re-sync PRO-1968 removed), UPSTREAM_PROPOSAL and ARCHITECTURE
  cite v1.8.3, CLAUDE (the import-on-new-catalog-field gotcha).

- **PRO-3765 — a profiling-consent queue row's entity is the shopper's
  keyed hash, not the address (2026-10-05; owner approved 2026-10-05, ships
  in rc7; no schema change; CHANGELOG bullet under "Changes since
  3.0.0-rc6").** Found in PRO-3760: `queueForEngine()` stored the plain
  address in the 64-character `entity_id`, so a longer address was cut,
  `waitingProfilingOptOuts()` never matched it and each confirmation queued
  one more opt-out (delivery was right — it reads the payload). The entity
  is now `ProfilingOptOuts::addressKey()` (the opt-out record's HMAC, 64
  hex). Readers checked: `resendOptOuts()`/`waitingProfilingOptOuts()`
  (asks for the hash and the plain address); `LocalEraser` (matches the
  entity against the address and its hash, besides the payload, as before;
  now takes `ProfilingOptOuts`); the Log grid, Details and the Dashboard's
  recent activity (`Log\EntityLabel`: a consent row's 64-hex entity shows
  as its first 12 characters + "…", which the grid's Entity filter finds;
  before, the address); the handler's newest-wins check and the PRO-3752
  pause read the payload / the type, unchanged; `Log\Resend` copies the
  entity as is. Transition: (a), no data patch — a legacy row drains within
  the retry window or goes with the janitor's retention, and both forms
  stay matched (also covers a Send again of an old failed row). Unit
  (`EntityLabelTest`, `LogEntityColumnTest`, Details, consent: hash entity,
  long address, waiting by hash and by plain) and integration (long
  address + three orders → one waiting row, entity = hash; a legacy
  plain-address row counts as waiting; erasure removes/anonymises consent
  rows by hash, by plain address and by the entity alone, the bystander's
  untouched; Dashboard shows the short form) — the long-address and
  erasure ones red before. DI: `LocalEraser` constructor; sandbox
  `setup:upgrade && setup:di:compile` green. ARCHITECTURE (profiling
  consent, erasure), USER_GUIDE (personalization consent).

- **PRO-3760 — an opt-out made before the engine knew the shopper reaches
  the engine once it does (2026-10-05; owner decision 2026-10-05, no
  contract change; CHANGELOG bullet under "Changes since 3.0.0-rc6").** The
  engine answers a §10 opt-out for a shopper it does not hold with 404 (a
  newsletter-only guest), the handler closes the row as delivered, and §4/§5
  carry no consent, so a later order, customer save or history import
  created the shopper at the engine without the opt-out.
  `Cron\FlushIngestQueue::applyD6Response()` now hands the addresses of the
  customer (`email`) and order (`customer_email`) rows the engine confirmed
  (no per-item error) to `ProfilingConsent::resendOptOuts()`, once per
  batch: one read of the record (`ProfilingOptOuts::moments()`), one query
  for opt-outs already waiting (`EventQueue::waitingProfilingOptOuts()`:
  pending incl. backoff, or sending), then the existing `queueForEngine()`
  with the stored moment (a mirror's 0 → now). Dedup = skip when an
  opt-out row already waits (no deterministic uuid: a delivered replay does
  not block a later one, which the engine takes as a no-op). The handler's
  404 → delivered is unchanged; newest-wins at delivery and the
  refused-account wait apply as they are. Unit (moments in one read; the
  selection: opted out → queued with its moment, not opted out → nothing,
  mirror → now, waiting → nothing, duplicates → one; the flusher passes
  only confirmed customer/order addresses, never catalog) and integration
  (`ProfilingOptOutReplayTest`, real DB, real flag record, both crons, a
  fake engine that keeps an opt-out only for a shopper it holds: order and
  customer confirmed → the engine holds the opt-out; not opted out →
  nothing; opted in again → nothing; three orders and a customer over two
  flushes → one waiting row) — the positive ones and the dedup red before.
  No DI change (constructor arg only; `setup:di:compile` green).
  ARCHITECTURE (profiling consent), USER_GUIDE (personalization consent).

- **Simplification pass, behaviour-neutral (2026-10-05)**, over PRO-2509,
  PRO-2510, PRO-3749, PRO-2465, PRO-1961, PRO-2466, PRO-1962 and PRO-3752.
  `Queue\Failure::CLASS_REGEX` is the one shape of the
  `permanent_<http|envelope>_<code>:` prefix (Failure writes it,
  `Log\FailureMessage` and the Log filter's SQL REGEXP read it);
  FailureMessage builds its translation patterns once per instance; the
  Smaily client reads and decodes an HTTP error's body once
  (`ExchangeResponse::ofDecoded()`); `SelectionRetry::retryBatch()` returns
  its own counts; `AutomationsForm::refusedMessage()` is the Automations
  tab's refused sentence for the tab and its save, and
  `engine-refused-banner.phtml` the refused-account banner's title and
  first line for Settings > Intelligence and the Automations tab; the
  identity-merge and profiling-consent handlers ask
  `sendingBlockedReason()` once per row; the Smaily client's HTTP
  exceptions read their status from the code (`HttpStatusCode` trait);
  `EventQueue::unclaim()` serves `release()` and `requeueStale()`. Left as
  they were: the Log filter still writes its shown-error expression once
  per LIKE (a derived-table column, measured on 200,000 rows, doubled every
  unfiltered grid read and would have carried the unredacted error to the
  browser); the mass retry still re-reads each batch through
  `QueueRowLoader::loadFailed()` (its integration test records those
  calls); the Dashboard keeps its verdict sentence (it has no banner).

- **PRO-3752 — a shopper's profiling choice waits while Campaign
  Intelligence refuses the account (2026-10-05; owner-approved design;
  CHANGELOG bullet under "Changes since 3.0.0-rc6").** Under the
  remembered `403 tenant_inactive` each `engine.profiling_consent` row
  failed with "Campaign Intelligence account is not active" and burned its
  five attempts, so the choice could be lost although the admin banner
  promises everything queued waits; and a 4xx refusal of a choice was
  retried five times. Same mechanism as PRO-2466, nothing new:
  `ProfilingConsentHandler` is a `PausableEventHandlerInterface`
  (`isPaused()` = `Settings::isRefused()`, so its rows are not claimed),
  answers `Queue\Pending` while refused and for the call whose 403 records
  the refusal; any other engine exception is handed on whole, so
  `Failure::of()` fails a 4xx (other than 404) on the first attempt as
  `permanent_http_<code>: <engine message>` and an outage keeps the ladder
  (the reason text is unchanged). 404 unchanged: contract §10 says the
  engine holds no such customer, so there is nothing to exclude and the
  row closes as delivered. The newest-wins rule (PRO-3578) is the existing
  match against `ProfilingOptOuts` on delivery, so it holds however many
  choices waited. Unit (`ProfilingConsentHandlerTest`: refused → Pending
  and paused, the call that meets the refusal → Pending, a 422 →
  permanent with the engine's reason, an outage → temporary) and
  integration (`ProfilingConsentDeliveryTest`, real DB and cron: refused →
  pending, unclaimed, attempts 0, no error, no call; active again → sent;
  opt-out then opt-in waiting → only the opt-in sent, the opt-out Skipped;
  a 422 → failed on attempt 1 with `permanent_http_422`) — red before. No
  DI change. USER_GUIDE (the deactivated-account section and the
  personalization-consent bullet), ARCHITECTURE (profiling consent
  delivery).

- **PRO-1962 — a Smaily "invalid data" envelope fails on the first
  attempt (2026-10-05; CHANGELOG bullet under "Changes since
  3.0.0-rc6").** HTTP 200 with body code 203 walked the five-attempt
  ladder although identical data is rejected again. Orchestrator decision
  (reversible): only 203 is permanent; every other non-success code keeps
  today's ladder. Recorded in ARCHITECTURE ("Smaily envelope codes") as
  the cross-platform canon proposal (Woo has the same gap; counterpart
  issues are the orchestrator's to file). Built on the PRO-1961 seam:
  `ApiException::PERMANENT_CODES` / `isPermanent()`, `Failure::of()` →
  `permanent_envelope_<code>: <message>`; `FailureMessage`'s class
  pattern and `Log\Collection`'s error filter (SQL REGEXP
  `^permanent_(http|envelope)_[0-9]+:`) strip the new class as they strip
  `permanent_http_`. Unit: `FailureTest` (203 permanent with its class,
  216 keeps the ladder), `FailureMessageTest` (shown without the class,
  class for the drawer) — red before. Integration with a faked transport
  and the real contact-sync handler and Smaily client
  (`FlushEventQueueTest`): `{"code":203}` → failed on attempt 1,
  `permanent_envelope_203: Smaily API returned code 203: Invalid data`,
  the answer kept; `{"code":216}` → pending on the ladder;
  `ErrorFilterTest`: found by its words, not by "envelope". Note: a 203 on
  a contact-sync batch fails every row of that batch at once (it did after
  five tries before) — FOLLOW-UP. USER_GUIDE (the Log's retry bullet).

- **PRO-2466 — identity-merge rows wait while Campaign Intelligence
  refuses the account (2026-10-05; CHANGELOG bullet under "Changes since
  3.0.0-rc6").** They travel through the marketing queue, whose handlers
  could only succeed or fail, so under the remembered `403
  tenant_inactive` (PRO-2451) each failed with "Campaign Intelligence
  account is not active" and burned its five attempts. Two parts, both
  needed: (1) a "leave pending" outcome — `Model\Queue\Pending` in the
  handler contract; `FlushEventQueue` gives such rows back with the new
  `EventQueue::release()` (pending, claim token cleared, attempts, error,
  retry time and exchange untouched — what the handler set on the model
  is not saved), the shape of `IngestQueue::release()`;
  `IdentityMergeHandler` answers it for every row while refused and for
  the merge whose 403 records the refusal. (2) The rows are not claimed
  while refused: the new `Api\Queue\PausableEventHandlerInterface`
  (`isPaused()`), `HandlerPool::pausedEventTypes()`, and
  `EventQueue::claimBatch($limit, $exceptTypes)`. Without (2), released
  rows are re-claimed every minute oldest-first, so a refusal's backlog
  of 200+ merges would fill every batch and stop the contact syncs.
  `Engine\Settings::isRefused()` is marked `@phpstan-impure` (it changes
  mid-request when an engine call records the refusal). Retry ladder
  unchanged (PRO-1800). Unit: `IdentityMergeHandlerTest` (refused → Pending
  and paused; the merge that meets the refusal → Pending),
  `FlushEventQueueTest` (Pending released, paused types not claimed) — red
  before. Integration (`FlushEventQueueTest`, real DB): while refused the
  merge row stays pending, unclaimed, attempts 0, no error, while the
  contact sync beside it is sent; a Pending row goes back as it was. The
  profiling-consent rows (PRO-3578) wait the same way since PRO-3752.
  ARCHITECTURE (identity merge).

- **PRO-1961 — one seam for terminal failures in the marketing event
  queue (2026-10-05; CHANGELOG bullet under "Changes since 3.0.0-rc6").**
  The issue's item 4 shape, judged proportionate: the clients type a
  failure where they throw it, one value object applies the verdict, and
  `Model\Queue\RetryPolicy` is gone. `SmailyClient` throws the new
  `Client\Exception\RequestRefusedException` for an HTTP 4xx other than
  429 (`AuthenticationException` and `PlanBlockedException` now extend it;
  `TransportException` is left for 429, 5xx, network and a malformed body;
  only RetryPolicy caught `TransportException`), as `Engine\Client`
  already types `EngineRequestException`/`EngineTransportException`.
  `Model\Queue\Failure` holds reason, permanent and Retry-After:
  `Failure::of(\Throwable)` is the classification (RetryPolicy's, plus an
  engine refusal → the same `permanent_http_<code>:` class),
  `Failure::permanent()` a handler's own verdict. `Cron\FlushEventQueue`
  applies a Failure, or a handed-on exception through `Failure::of()`,
  with `EventQueue::markFailed(…, terminal:)` (no new writer: that is the
  "markPermanentlyFailed()" the issue names). The handler contract
  (`Api\Queue\EventHandlerInterface`) reads
  `true|string|\Throwable|Failure|Skipped`. Item by item: (1)
  `IdentityMergeHandler` hands the engine exception on, so a 4xx fails on
  the first attempt as `permanent_http_<code>: Engine request failed with
  HTTP …` and an outage keeps the ladder; a 403 that is the remembered
  account refusal stays on the ladder for now (PRO-2466 next). (2) The
  no-handler path parks with `Failure::permanent('No handler registered
  for "<type>"')` and the true attempt count (1, not a faked 5). (3) The
  four malformed-payload verdicts (contact, automation, identity merge,
  profiling consent) are `Failure::permanent()`. RetryPolicy's per-batch
  memo is dropped: the batch path classifies once, a per-row result costs
  one sprintf. Log Details checked by rendering `log/details.phtml` for
  both row kinds in en_US and et_EE: "Stopped after 1 of 5 attempts —
  retrying could not change the outcome…" ("Peatus pärast 1 katset 5-st
  …"), the engine refusal with "Failure class: permanent_http_422", the
  no-handler and malformed rows without a class. Unit: `FailureTest`
  (replaces RetryPolicyTest, plus the engine refusal and the handler
  verdict), `SmailyClientTest::testAnHttpErrorIsTypedWhenItIsThrown`,
  `FlushEventQueueTest` (no-handler, handler verdict + engine refusal),
  `IdentityMergeHandlerTest` (refusal, outage, malformed),
  `AutomationHandlerTest`/`ProfilingConsentHandlerTest` (malformed) — red
  before. Integration (`FlushEventQueueTest`, real DB): no-handler row
  attempts 1; a malformed contact payload fails on attempt 1 with nothing
  posted; an identity merge refused with 422 fails on attempt 1 with the
  named class and the engine's answer kept. `FlushEventQueue` lost a
  constructor argument: sandbox `setup:upgrade` + `setup:di:compile`
  clean. ARCHITECTURE ("Queue semantics"), USER_GUIDE (the Log's retry
  bullet).

- **PRO-2465 — the GDPR command and the Campaign Intelligence automations
  form respect a refused account (2026-10-05; CHANGELOG bullet under
  "Changes since 3.0.0-rc6").** PRO-2451 remembers the engine's `403
  tenant_inactive`; these two merchant-started surfaces still asked the
  engine and showed its raw refusal. No new gate: both read
  `Engine\Settings::isRefused()`. `smaily:gdpr export` prints the local
  rows with `"engine": null` and "Campaign Intelligence data was not
  exported: the Campaign Intelligence account is not active. Ask Smaily to
  make the account active again, then run the command again." (exit 1);
  `erase` runs the local erasure (PRO-2452) first as before, then "Local
  data is erased, but Campaign Intelligence data was not: …" (exit 1), the
  engine not asked. The console text is English, like the rest of the
  command. The Automations tab's Campaign Intelligence block
  (`config/engine-automations.phtml`) shows Settings > Intelligence's
  banner title and message plus one new phrase (EN + ET) instead of the
  triggers and the save button, and `AutomationsForm` does not load the
  catalog or the stored config; `Automations\Save` answers that phrase
  and calls nothing for a page opened before the refusal. Unit:
  `GdprCommandTest` (new: refused erase and export, an active erase),
  `AutomationsFormTest::testARefusedAccountIsNotAskedForItsAutomations`,
  `SaveTest::testARefusedAccountSavesNothingAndSaysWhy` — red before the
  change. Browser harness: `automations-save.html` reads the new
  `automations-refused` page in both languages (banner, phrase, no
  triggers, no save button; 6 checks, red without the template change).
  `Save` gained a constructor argument: sandbox `setup:upgrade` +
  `setup:di:compile` clean. USER_GUIDE ("If your Campaign Intelligence
  account is deactivated"), TESTING ("Browser JS harnesses").

- **PRO-3749 — a Smaily HTTP error shows Smaily's answer in the Log
  (2026-10-05; CHANGELOG bullet under "Changes since 3.0.0-rc6").** Owner
  decision 2026-10-04. `SmailyClient` words an HTTP error that is neither
  a credential refusal (401/403) nor the package refusal (code 227) as
  "Smaily API request failed with HTTP %1: %2" (EN + ET, "Smaily API päring
  ebaõnnestus (HTTP %1): %2"), the engine client's format: %2 is the JSON
  envelope's `message`, else the body's text without markup and with its
  whitespace collapsed, cut to 500 characters plus "…". A JSON answer
  without a `message`, or an empty body, keeps the plain "…with HTTP %1"
  (the body stays whole in `last_response` for Details). The Log shows it
  through `FailureMessage` like every other answer (the PayloadRedactor
  pass, the admin-language translation; the new phrase sits before the
  plain one in `FailureMessage::TRANSLATED`, whose pattern would match it
  too), and the log file masks addresses in it as before. Rejected
  credentials keep "Smaily API credentials were rejected". Unit:
  `SmailyClientTest::testAnHttpErrorNamesSmailysAnswer` (JSON envelope,
  HTML page, JSON without message, a 600-character answer) and the
  Estonian-store cases, `FailureMessageTest` (stored refusal with an
  answer, the error filter's patterns) — red before the change.
  USER_GUIDE (the error column: two exceptions now — rejected credentials
  and the package refusal), ARCHITECTURE (the Log paragraph).

- **The admin browser-test renderer stops when it cannot write its output
  (2026-10-04; PRO-3738 follow-up, no issue; tooling, no CHANGELOG
  bullet).** `Test/Js/render-admin.php` only warned when `Test/Js/build/`
  or a page file in it could not be written and exited 0, so
  `bin/test-js.sh` went on and the harnesses read the older build. It now
  throws (exit 255) when the directory cannot be created or a file cannot
  be written. Shown in a temporary clone: a read-only build directory with
  a stale `automations.en_US.js` — before: a PHP warning, exit 0, the stale
  file kept; after: "Cannot write …/automations.en_US.js.", exit 255; a
  directory that cannot be created → exit 255; read-only page files →
  `bin/test-js.sh` exits 255 before any page runs; writable → exit 0, 10
  files. TESTING.md ("Browser JS harnesses").

- **PRO-2462 — sandbox: phpMyAdmin's port is configurable, the ZIP install
  check names the missing `crontab` (2026-10-04; tooling, no CHANGELOG
  bullet).**
  `docker-compose.yaml` publishes phpMyAdmin on `${PMA_PORT:-8888}`:
  `docker compose config` resolves 8888 without the variable and 8899
  with `PMA_PORT=8899`; the running phpMyAdmin container was not
  restarted. TESTING.md's sandbox section names the port and the
  variable, and the clean-install-from-the-ZIP step 4 says the sandbox
  image has no `crontab` binary (found in PRO-3739; confirmed: `command -v
  crontab` finds nothing, the exec user is www-data), so INSTALLING's step
  4 needs `apt-get install -y cron` as root first. The sample-data
  promise stays (decided 2026-10-05): the running sandbox, installed on
  fresh volumes on 2026-10-02 from the image built that morning, has the
  Luma sample data (2,046 products, 40 categories, the sample CMS pages,
  all 20 `*SampleData` modules enabled). Only the product image files are
  missing, because the image clones `magento2-sample-data`, which carries
  no media; TESTING.md's sandbox section says so. No create-a-product
  recipe is needed.

- **PRO-2514 — `etc/module.xml` sequences every Magento module composer
  requires (2026-10-04; no merchant-visible change, no CHANGELOG bullet).**
  Rule: the `<sequence>` mirrors composer's `magento/module-*`
  requirements exactly, the pattern PRO-2472 started. Three were missing,
  not only `Magento_Backend`: `Magento_Backend`, `Magento_Config` and
  `Magento_Cookie` are added (the existing nine untouched). The new unit
  test `ModuleDefinitionTest::testModuleSequenceMirrorsComposerModuleRequirements`
  maps `magento/module-foo-bar` → `Magento_FooBar` and requires the two
  lists to be equal, so a requirement cannot be added without its sequence
  entry (or the reverse); red before the fix (the three names). Sandbox:
  `setup:upgrade` + `setup:di:compile` clean, `module:status
  Smaily_Connect` enabled, `app/etc/config.php` loads Backend (16), Config
  (21), Cookie (71) and Ui (103) ahead of Smaily_Connect (384). The
  header of `bin/build-release-zip.sh` credited "PRO-2470 release train";
  PRO-2470 is the event-queue index. The script came from the 3.0.0-rc1
  release train of 2026-09-10 (commit ab5c355, no issue of its own), so
  the header names that train and PRO-2472, its packaging follow-ups.

- **PRO-1469 — the native config page's dead script is removed
  (2026-10-04; no merchant-visible change, no CHANGELOG bullet).**
  `view/adminhtml/templates/config/assist.phtml` (Test connection and
  workflow dropdowns for Stores > Configuration > Smaily Connect) and its
  layout `adminhtml_system_config_edit.xml` are deleted: the template ran
  only when `section=smaily_connect`, and Magento redirects that URL since
  PRO-1461 hid the section. Shown in the sandbox with an admin session:
  `…/system_config/edit/section/smaily_connect/` → 302 to
  `system_config/index`; `section/general` renders (200, no Smaily
  script); Settings and Log render (200). `setup:upgrade` +
  `setup:di:compile` clean. `Block\Adminhtml\Config\TestConnection` stays:
  `etc/adminhtml/system.xml` still names it as the hidden field's
  frontend model (that file keeps every field unchanged); its docblock and
  a `SmailyClient` comment no longer point at the template. The two phrases
  only the template used ("Connected! The workflow dropdowns below are now
  up to date.", "Fill in the subdomain and username first.") leave
  `en_US.csv` / `et_EE.csv`, and "Queued today" now sorts before "Queued —
  the import starts…" (the files are sorted case-insensitively).

- **PRO-2475 — CI hygiene (2026-10-04; tooling, no CHANGELOG bullet).**
  The four `composer config repositories.mage-os …` steps in `ci.yaml`
  (unit, integration, static, browser) are gone: `composer.json` declares
  the mirror itself, and the step would rewrite `composer.json` (and so
  invalidate the lock's content-hash) the day its URL or form differed.
  Shown locally: on a fresh clone of v3 the step leaves `composer.json`
  byte-identical today (the latent hazard, not a live one), and a clean
  `composer install --ignore-platform-reqs` there says "Installing
  dependencies from lock file", installs without a stale-lock warning and
  runs the unit suite. Node.js 20 deprecation: `actions/checkout` is
  pinned to v5.1.0 (fbc6f39, `node24`) in all four workflows;
  `actions/upload-artifact` (ci.yaml `package`) to v6.0.0 (b7c566a) —
  v5.0.0 still declares `runs.using: node20`, v6.0.0 is the first major
  that runs on Node.js 24 and changes nothing else. `shivammathur/setup-php`
  2.37.2 already runs on `node24`, unchanged. Confirmed by the next push,
  not from here: CI green on GitHub's runner and no Node.js 20 annotation.

- **PRO-2510 — the Log's mass Retry works through a large selection in
  batches (2026-10-04; CHANGELOG bullet).** Before, `MassRetry` took
  `Filter::getCollection()`, which loads every grid row of a "Select all"
  through the data provider and filters the grid again by all their log
  ids, then the controller listed every id and asked the guard and the
  queues with one IN (...) of the whole selection. Now the controller
  validates the selection as Filter does ("An item needs to be selected"),
  applies it to the listing's data provider and hands the unloaded
  `Log\Collection` (grid filters + ticked/excluded ids) to the new
  `Model\Log\SelectionRetry`, which streams the failed rows' log ids from
  one query and runs guard + retry per 1,000 ids of one queue. The guard
  still decides every row; a retried row turns pending, never delivered,
  so per-batch answers equal the whole-selection answer. Measured on
  20,500 failed rows (300-character errors): the old path peaked at
  +45.5 MB, the new at +1.4 MB, both about 0.35 s. Tests: integration
  `Log/SelectionRetryTest` (20,000 failed contact syncs + 500 failed
  ingest rows + a superseded, an erased, a pending, a delivered and a
  withdrawn row: 20,500 retried, 2 skipped — exactly the rows the guard
  refuses when asked about the whole selection at once — in 22 batches of
  at most 1,000; grid filters and exclusions narrow it; an empty
  selection asks nothing), unit `MassRetryTest` (the selection goes on
  unloaded, `getCollection()` is never called; nothing selected is
  refused). DI change: sandbox `setup:upgrade` + `setup:di:compile`
  clean; the controller's selection path was run read-only in the sandbox
  (real UI component, Last Error + status filters and an exclusion end up
  in the one query; the sandbox has no failed rows, nothing retried).
  ARCHITECTURE (Sending again, Unified log).

- **PRO-2511 — Details on a delivered automation row reads as delivered
  (2026-10-04; CHANGELOG bullet).** `ResendGuard` answers `superseded`
  for any automation row a later delivery of the same trigger reached,
  delivered rows included (its refusals outrank `not_failed`), and the
  Details drawer showed that refusal on a delivered row. The guard's rules
  are unchanged; `Controller\Adminhtml\Log\Details` now hands the
  template a refusal only for a failed row — where it stands in for the
  missing **Send again** — and for a withdrawn reminder, whose sentence is
  the drawer's only line for it (it never failed). A delivered row gets
  "Delivered — no retries needed." whatever the guard says, and so does an
  erased delivered row. Tests: unit `DetailsTest` +3 (delivered and
  superseded: no refusal, pill *sent*; failed and superseded: the
  sentence; withdrawn: its sentence) — the delivered case red before the
  fix. No DI change; USER_GUIDE already scopes the sentence to failed rows.

- **PRO-2509 — the Log's Last Error filter matches what the column shows
  (2026-10-04; CHANGELOG "Changes since 3.0.0-rc6" opened with its
  bullet).** The column shows `FailureMessage::forDisplay()` (RetryPolicy's
  `permanent_http_<code>:` prefix stripped, a client message translated
  into the admin's language, `PayloadRedactor` applied) while the filter
  searched the raw stored value. `Log\Collection::addFieldToFilter()` now
  takes the `last_error` LIKE itself: it matches the stored text with the
  prefix stripped in SQL, OR any client message whose translation holds
  the typed words (`FailureMessage::storedPatternsShowing()`, values filled
  into the message match anything), and never a row whose shown text is a
  JSON object/array. Redaction check: the redactor only changes an error
  that is whole JSON (secret keys become `[redacted]`); no queue path
  stores one today (errors are client/engine sentences, D6 `field:
  message`), but a raw-value filter would have found such a row by its
  hidden value, so those rows are excluded from the text filter. Known
  limit: an Estonian admin typing English words still finds an English
  stored message (a superset, not a miss). Tests: unit
  `FailureMessageTest` +2 (ET patterns, none in EN or for other words),
  integration `Log/ErrorFilterTest` (5 tests: server words found, prefix
  not; ET words found; `hunter2`/`api_key` in a JSON error not found;
  escaped `_`/`%` literal) — 4 of 5 red with the raw filter restored.
  DI change (a constructor argument on the collection): sandbox
  `setup:upgrade` + `setup:di:compile` clean; the filter queries run on
  the sandbox's MySQL 8.4 (its Log is empty, so nothing to compare there).
  Docs: USER_GUIDE (the log), ARCHITECTURE (Sending again), CHANGELOG.

- **PRO-2508 — rejected Smaily credentials keep the extension's sentence
  in the Log (2026-10-04; docs + a code comment, no behaviour change, no
  CHANGELOG bullet).** Owner decision 2026-10-04: a Smaily 401/403 keeps
  "Smaily API credentials were rejected" instead of Smaily's body, because
  it names what to fix; the body stays in the row's last API response for
  the Details drawer. `SmailyClient` says so where the sentence replaces
  the body; `FailureMessage`'s docblock, ARCHITECTURE (Sending again) and
  USER_GUIDE (The log and troubleshooting) now say the error column shows
  Smaily's or Campaign Intelligence's own words except for rejected
  credentials. Checking the premise found two more Smaily answers that the
  extension words itself, documented the same way: the package without API
  access (PRO-3579) and a Smaily HTTP error with no error envelope (e.g.
  404, shown as "Smaily API request failed with HTTP 404"; the body is
  only in the drawer).

- **PRO-3748 — the rc6 package installs on a clean store by the install
  guide (2026-10-04; docs only, no CHANGELOG bullet).** As PRO-3739 for
  rc5: the ZIP and its .sha256 were downloaded from the 3.0.0-rc6 GitHub
  release (`shasum -a 256 -c` OK on macOS, `sha256sum -c` OK in the
  container; 380 entries, composer.json 3.0.0-rc6) and installed into a
  real `app/code` on a fresh Magento 2.4.8-p4 (PHP 8.3) without sample
  data, in production mode, on a separate temporary compose project (own
  name, containers, volumes, ports; sandbox image; no working-tree mount —
  the sandbox stayed untouched and Up with the same container IDs),
  following `docs/INSTALLING.md` step by step: extract (the guide's
  `mkdir -p` creates `app/code`), the production sequence with
  `setup:static-content:deploy en_US et_EE`, `crontab -l` / `cron:install`
  (the cron package installed in the temporary container first), module
  enabled, six `smaily_*` tables, the "ready to set up" notice under the
  bell, four menu entries. All seven `smaily_*` cron jobs (the nightly
  catalog re-sync is gone since PRO-1968) finished `success` without a
  Smaily or engine connection (the daily janitor and the two 15-minute
  jobs queued by hand), plus one plain `cron:run`; no `exception.log`, no
  `var/report`, no Smaily_Connect line in `system.log`. Initial setup opens
  from every module page; step 1 refuses Continue without a tested
  connection; the Campaign Intelligence step renders the
  separate-storefront hint (no Storefront URL saved). Dashboard, Settings
  (all five tabs; Intelligence with its hint) and Log were opened with
  `setup_completed` set in the database: no console errors, Not connected
  everywhere, no browser request left the store. PRO-1969/PRO-3742 hold:
  `smaily:backfill:start catalog|customers|orders` prints the
  not-connected sentence and exits 1, the admin import endpoint answers a
  catalog start with the same message, and no job or queue row is
  written. The update section (fresh extraction, the step 3 commands,
  second `setup:upgrade`) passed; settings and tables kept, the Dashboard
  opens, the group runs again with no failure. No guide step needed a
  change. TESTING.md records the run. Stack removed afterwards (`down -v`).

- **PRO-3745 follow-up — Settings > Intelligence shows the
  separate-storefront hint before Connect too (2026-10-04).** Settings >
  Intelligence has its own Connect, which also starts the catalog import
  (PRO-3741), but showed no hint. `panel/intelligence.phtml` now renders
  the note on both screens while `WizardData::getSavedStorefrontUrl()` is
  empty for the selected website; the Settings wording drops "finish the
  setup without connecting": "Using a separate storefront? Set its
  Storefront URL under Settings > Connection > Using a separate
  storefront? before you connect, so that the catalog import sends the
  storefront's product links. Or connect now and press Hold back the
  import." (ET "Kas pood töötab eraldi veebilehel? Sisesta poe veebilehe
  aadress menüüs Seaded > Ühendus > Kas pood töötab eraldi veebilehel?
  enne ühendamist, et kataloogi import saadaks poe veebilehe tootelingid.
  Või ühenda kohe ja vajuta „Peata import“."). It sits in the disconnected
  block, so it goes once connected. Known limit: rendered server-side, so
  a Storefront URL saved on the Connection tab hides it only after a
  reload (USER_GUIDE and PILOT_CHECKLIST §0 say so). Test: browser
  `Test/Js/intelligence-connect.html` 36 → 41 checks (Settings: shown and
  translated without a Storefront URL, absent with one, hidden once
  connected; red with the setup-only condition restored and with the
  Storefront URL condition removed) — `render-admin.php` adds the
  `intelligence-settings-storefront` page. Docs: USER_GUIDE (Connecting),
  HEADLESS_STOREFRONTS, ARCHITECTURE, TESTING, PILOT_CHECKLIST §0,
  CHANGELOG (merged into PRO-3745's bullet). Template-only PHP change, no
  DI change.
- **Simplification pass, behaviour-neutral (2026-10-04; over PRO-1960,
  PRO-1969, PRO-3742 and PRO-3741).** `AbandonedCart\PayloadBuilder::
  buildAll()` takes one store (`buildAll($storeId, $quotes)`; its only
  caller, the cron's `buildAddresses()`, already passes one store's carts
  under that store's emulation): it loads each product once (de-duplicated
  ids, only the lines that get one of the ten slots), resolves the product
  details right after the load, and the store's fields (name, `store_url`,
  group, website) once per build — each cart keeps its own
  `abandoned_cart_url`, and a store that does not resolve still leaves
  those fields and the link out. The cron reads the quote id once per
  cart. New `JobManager::startIfIdle()` (null while an equivalent job is
  active; `start()` still throws) replaces the "catch RuntimeException =
  already active" pattern in `CatalogImportOnConnect`, `BackfillState` and
  `smaily:backfill:start` (which prints the same message, now
  `JobManager::alreadyActiveMessage()`). `EngineImportGuard::refusal()`
  takes the target its callers already resolved; `BackfillState` asks it
  inside its start branch. After **Hold back the import** the Settings
  tab's Catalog card renders the cancel's answer instead of a second
  status request. Payloads byte-identical: a cron-like CLI probe named
  `magento` on the sandbox (crontab area, store 1 frontend emulation, fixed
  clock, in a rolled-back transaction, nothing queued, marked or sent; the
  three sandbox carts incl. two configurable lines plus six unsaved carts
  of overlapping products, one with 12 lines) printed the same bytes before
  and after, rewrites off (md5 f61c9d11…) and on (md5 7249ec75…; the
  setting restored to its default afterwards). Tests: unit (the store
  resolves once and each cart keeps its link; product ids de-duplicated;
  the command's already-active path), integration `JobManagerTest`
  (`startIfIdle()`), browser `Test/Js/intelligence-connect.html` 34 → 36
  checks (the card shows the cancel's answer and no status read follows;
  red with the second request restored). No CHANGELOG bullet.
- **PRO-3745 — the initial setup's Intelligence step tells a separate
  storefront to set its Storefront URL before connecting (2026-10-04; pilot
  milestone: the pilot runs a headless storefront and installs rc6).**
  Since PRO-3741 connecting starts the catalog import, and the Storefront
  URL lives under Settings > Connection (not Settings > Intelligence),
  which redirects to the wizard until the setup is finished — so a
  headless store connecting in the setup sent back-end product links
  unless it held the import back. `panel/intelligence.phtml` now renders a
  note under the setup URL field, setup step only, while
  `WizardData::getSavedStorefrontUrl()` is empty for the selected website:
  "Using a separate storefront? Set its Storefront URL before you connect,
  …: finish the setup without connecting, enter the address under
  Marketing > Smaily Connect > Settings > Connection > Using a separate
  storefront? > Storefront URL, then connect under Settings > Intelligence.
  Or connect now and press Hold back the import." (EN + ET; ET uses the
  canon's "Poe veebilehe aadress", "Kas pood töötab eraldi veebilehel?",
  "Peata import"). Moving the field into the setup is out of scope. Test:
  browser `Test/Js/intelligence-connect.html` 27 → 34 checks (shown without
  a Storefront URL, translated, absent with one and on Settings; red with
  the condition removed) — `render-admin.php` adds the
  `intelligence-setup-storefront` page. Docs: PILOT_CHECKLIST §0 and §2
  (headless order: Finish without connecting, set the Storefront URL,
  connect under Settings > Intelligence; Hold back + Run again if connected
  first), HEADLESS_STOREFRONTS ("Before switching anything on" order; the
  note), USER_GUIDE (A separate storefront; Connecting), ARCHITECTURE,
  TESTING, CHANGELOG. Template-only PHP change, no DI change.
- **PRO-3741 — connecting Campaign Intelligence starts the catalog import
  (2026-10-04; owner decision: "the catalog import starts automatically
  when Campaign Intelligence is connected; the merchant is told at once
  and can hold it back at the start").** Supersedes PRO-1968's "setup does
  not start the import". `Model/Backfill/CatalogImportOnConnect::start()`
  queues the catalog job through `JobManager::start()` — the admin Start
  import's path and its one-active-job lock — and answers whether it did;
  both connect paths call it after a successful setup exchange:
  `Controller/Adminhtml/Api/EngineExchange` (initial setup step 4 and
  Settings > Intelligence; the answer adds `catalogImportStarted`) and
  `Model/Config/Backend/EngineSetupToken` (`bin/magento config:set
  smaily_connect/intelligence/setup_token`, the native form is hidden; it
  adds a notice message, which config:set does not print). A save without
  a token exchanges nothing and starts nothing. A reconnect while a catalog
  import is queued or running starts none (`catalogImportStarted: false`,
  no notice); a reconnect after it ended starts a fresh one (assumption:
  every successful exchange is a new connection — possibly a new tenant).
  **Hold back:** `panel/intelligence.phtml` has an info banner (the
  screen's existing banner pattern) "The catalog import has started" with
  **Hold back the import**; `panels-js.phtml` shows it when the Connect
  started the import and posts the existing BackfillState `cancel`
  (`JobManager::requestCancel()`). **Window:** the job stays `pending`
  until the next `smaily_backfill_tick` (every minute; its first page can
  be flushed in the same cron run) — so usually under a minute. Cancelled
  while pending, nothing is queued or sent (integration test). Once
  running, the cancel stops it at the next page boundary (≤100 products):
  rows already queued are still sent, the rest never queued — the banner
  then says how many were queued, the user guide says the same. Residual
  race: a cancel between the tick's `nextActive()` and `markRunning()` can
  still let the first page through (ARCHITECTURE). On Settings the Catalog
  card follows (Pending, then Canceled with Run again). The setup step's
  text now says connecting starts the catalog import and that customer and
  order history are imported, and the catalog import run again, under
  Settings > Intelligence. Customers/orders imports stay a merchant
  choice. Tests: unit `EngineExchangeTest` (starts; reconnect while active
  → false; failed exchange starts nothing), `EngineSetupTokenTest` (starts
  + notice; reconnect → no notice; no token / failed exchange start
  nothing; both red without the start calls); integration
  `CatalogImportOnConnectTest` (one job, a reconnect
  while queued and while running starts none; held back before the first
  tick: the tick runs no processor and the ingest queue stays empty, a
  later start works); browser `Test/Js/intelligence-connect.html` (27
  checks, EN + ET, setup step and Settings tab; red with the banner's show
  removed) — `Test/Js/render-admin.php` now renders several templates per
  page and block data, phpcs excludes the rendered `Test/Js/build/`.
  Sandbox setup:upgrade + di:compile green; nothing was connected or
  imported on the sandbox. Docs: USER_GUIDE (Connecting step 3 with the
  hold-back window and the CLI path, Historical import), ARCHITECTURE
  (catalog lifecycle), HEADLESS_STOREFRONTS (set the Storefront URL before
  connecting, or hold back), PILOT_CHECKLIST §2 (decide before connecting;
  hold back at once if the catalog should not go), TESTING (the new
  harness), CHANGELOG. **Pilot note:** PILOT_CHECKLIST §2 now describes
  v3 from this commit on; rc5 (what the pilot installs today) does not
  start the import on connect and has no Hold back — the checklist holds
  for the pilot only with a release cut after this commit.
- **PRO-3742 — the customers and orders imports do not start while
  Campaign Intelligence is not connected either (2026-10-04; owner
  decision: refuse them as the catalog import).** The PRO-1969 guard now
  lives in one place, `Model/Backfill/EngineImportGuard::refusal()`: every
  engine-target type of `Job::TYPE_TARGETS` (catalog, customers, orders)
  is refused while `Settings::isConnected()` is false, the contacts import
  to Smaily never. `Controller/Adminhtml/Api/BackfillState` and
  `smaily:backfill:start` both ask it (they no longer check
  `Settings` themselves); the admin answer's `message` is translated, the
  command prints the English text (`Phrase::getText()`), as before.
  Messages: "Campaign Intelligence is not connected, so there is nowhere
  to send the customer data." / "…the order data." (ET "…seega pole
  kliendiandmeid / tellimusandmeid kuhugi saata."). Before, such an
  import queued its rows (the ingest queue takes them; the flusher sends
  nothing while disconnected), to be sent after a later connection —
  possibly to another tenant. Not changed: an import already running when
  the connection is removed. Unit tests: `BackfillStateTest` and
  `BackfillStartCommandTest` each refuse catalog/customers/orders while
  disconnected (customers/orders red with a catalog-only guard), start
  them while connected, and start the contacts import while disconnected.
  Sandbox setup:upgrade + di:compile green. Docs: USER_GUIDE (Historical
  import), ARCHITECTURE (catalog lifecycle), CHANGELOG (the PRO-1969
  bullet widened).
- **PRO-1969 — catalog funnel edges (2026-10-04).** (1) **A catalog import
  does not start while Campaign Intelligence is not connected** (owner
  decision 2026-10-04: block). Started anyway it recorded every product as
  failed (`CatalogIngest::enqueue()` queues nothing while disconnected, so
  `EngineCatalogProcessor` counted each product failed and the card said
  "N failed"). `Controller/Adminhtml/Api/BackfillState` answers a catalog
  start with the current outcome plus `message` "Campaign Intelligence is
  not connected, so there is nowhere to send the catalog." (EN + ET,
  `panels-js.phtml` shows it beside the button) and starts nothing;
  `smaily:backfill:start catalog` prints the same sentence as an error and
  exits 1 (English, as the other commands). The import cards render only
  while connected, so in the admin this is reached from a page opened
  before a disconnect (or a direct POST). The customers and orders imports
  were unchanged: the decision named the catalog (PRO-3742 refuses them
  too). Unit tests: the
  controller and the command each refuse while disconnected and start
  while connected (both refusals red without the guard). No browser-harness
  page: the card lives in `panel/intelligence.phtml` + the 1 000-line
  `panels-js.phtml` with the whole boot model, not cheap to render.
  (2) **Product creation's double row is gone since PRO-1967, accepted
  with no code change.** Measured on the sandbox in a transaction rolled
  back afterwards (nothing committed, nothing sent; the sandbox cron does
  not run): a new simple product saved through the repository, through
  `$product->save()`, and through `$product->save()` plus the admin Save
  controller's `controller_action_catalog_product_save_entity_after` (MSI
  saves the source items) queues 2–3 `catalog_changed` markers and one
  `catalog` row; after `CatalogIngest::buildChanged()` it is still one
  `catalog` row — the markers build the same payload (product URL already
  `…/<url-key>.html`) and `IngestQueue::enqueueChangedPayloads()` leaves it
  out. The old pair came from a stock hook building before
  CatalogUrlRewrite wrote the rewrite; since PRO-1967 the stock hooks only
  mark, and Smaily_Connect's save observer runs after
  `process_url_rewrite_saving` (Magento loads Magento_* modules before
  Smaily_*). Covered by `IngestQueueTest::testEnqueueChangedPayloadsLeaves…`
  and `CatalogIngestTest`. Docs: USER_GUIDE (Historical import),
  ARCHITECTURE (catalog lifecycle), CHANGELOG.
- **PRO-1968 — the nightly full catalog re-sync is removed (2026-10-04;
  owner decision: as in WooCommerce, no new table).** `Cron/CatalogResync`
  (job `smaily_catalog_resync`, daily 03:40, PRO-1951), its unit test and its
  `etc/crontab.xml` entry are gone; nothing else started it or depended on
  it (no DI, config, UI string or admin state names it — BackfillState and
  the Dashboard never mentioned it). The catalog now reaches the engine as
  in Woo: in full through the catalog import, then only changes. **Setup
  did not start the import then**, and none was added (a separate owner
  decision; since PRO-3741 connecting starts it): the engine connection only stores the exchange
  (`Controller/Adminhtml/Api/EngineExchange.php:50-51`,
  `Model/Config/Backend/EngineSetupToken.php:60-61` →
  `Model/Engine/Settings.php:204-230`, no job start); the only catalog job
  starts are the admin card (`Controller/Adminhtml/Api/BackfillState.php:67`)
  and `smaily:backfill:start` (`Console/Command/BackfillStartCommand.php:81`).
  The setup step leads the merchant to it
  (`view/adminhtml/templates/panel/intelligence.phtml:79-81`: historical
  data is imported under Settings > Intelligence); USER_GUIDE's Connecting
  now has step 3 "Start the Catalog import". Until now the first night's
  re-sync sent the full catalog to a merchant who never pressed Start
  import; from now on such a store has only the products saved or stock-
  changed since connecting. Changes still flow unchanged — product save
  (`CatalogIngestTest` same-row/changed-row/tombstone cases), stock changes
  (`StockItemSaveAfterTest`, `SourceItemsSaveTest`, `SourceDeductionTest`,
  `CatalogIngestTest` marker + `buildChanged()` cases,
  `IngestQueueTest::testEnqueueChangedPayloadsLeaves…`), deletion
  (`ProductDeleteBeforeTest`, the §3b cases in both `FlushIngestQueueTest`s).
  **Upgrades:** Magento reads and prunes `cron_schedule` only for the job
  codes its configuration lists (`ProcessCronQueueObserver::
  getPendingSchedules()`/`cleanupJobs()`), so a leftover
  `smaily_catalog_resync` row is never run and never breaks anything; it
  just stays. Sandbox after `setup:upgrade` + `setup:di:compile`: Magento's
  cron config lists seven `smaily_*` jobs. The engine contract §3
  (lifecycle) and §3b then still said the plugin's periodic full re-sync
  is the reconciler; the engine was asked to align it (Linear PRO-3740) —
  **resolved 2026-10-05: contract v1.8.3 states the lifecycle** (full
  import at setup, changes after, full import by hand, no scheduled
  re-sync; see the PRO-3740 entry above). Docs: ARCHITECTURE (catalog lifecycle, cron
  table, the send-gate list), USER_GUIDE (Connecting step 3, What syncs,
  Historical import: start the catalog import by hand after ERP/CSV/
  direct-database changes), HEADLESS_STOREFRONTS, CHANGELOG; stale code
  comments that named the re-sync fixed.
- **PRO-1960 — the abandoned-cart reminders are built once per store
  (2026-10-04; behaviour-neutral, no CHANGELOG bullet).** `Cron\AbandonedCart`
  builds the page's payloads before the reminder rules weigh it, grouped by
  store: one frontend emulation per store (was one per cart), and the new
  `PayloadBuilder::buildAll()` (replaces `build()`) loads all that store's
  cart products in one collection (was one per cart) and resolves each
  product's description, image link and regular price once (was once per
  cart line; the price still through Magento's price model, so configurable
  and tax-inclusive prices are unchanged). The rules still weigh the carts
  oldest id first, whatever their store; a build that fails now leaves the
  whole page for the next run (before, the carts ahead of it were
  enqueued). **Measured** on the sandbox with a cron-like CLI probe named
  `magento` (crontab area, frontend emulation, nothing queued, marked or
  sent; 40 inactive guest carts at example.com, 129 cart lines, 12 products
  incl. four configurables, one cart over ten lines; Zend DB profiler): the
  builder's own queries 32/52/92 → 14/14/14 at 10/20/40 carts (2 per cart +
  12 → constant); the whole build 2 810 → 2 732 queries at 40 carts.
  Magento's own cart loading is the rest: ~21 queries per cart line, mostly
  MSI stock checks run by *Enable Inventory Check On Cart Load* (Magento's
  default, on); with it off the whole build is 723 → 645. **Payloads
  byte-identical** before/after for the same carts with a fixed clock
  (sha256 equal): rewrites off (the sandbox's state; links carry
  `index.php`), rewrites on (temporary config row, removed) and the
  inventory check off (temporary row, removed). Tests: a unit test (two
  carts of one store sharing a product: one product collection, one image
  helper, the price model read once; red with the per-line resolution), an
  integration scenario (carts of two stores interleaved: two emulations,
  one build per store, the older cart of a shared address reminded across
  stores; red with a build per cart). ARCHITECTURE ("One build per store").
  Sandbox restored (probe, fixture carts and config rows removed, config
  cache cleaned).

- **PRO-3738 — CI runs the browser harnesses (2026-10-04; tooling, no
  CHANGELOG bullet).** New `browser` job in `.github/workflows/ci.yaml`:
  PHP 8.3, the Mage-OS mirror, `composer install` from the lock (as the
  other jobs), then `bin/test-js.sh` with the runner's Google Chrome.
  `bin/test-js.sh` now ends a failed run with "Failed browser tests:", each
  failed page with its result and its `FAIL` lines; a browser that exits
  non-zero or prints nothing no longer stops the script silently (`set -e`),
  the page reads `RESULT: FAIL (no result)`. Found on the way: on Linux
  (Debian Chromium 154 in a throwaway php:8.3-cli container, the closest
  local stand-in for the runner) `Test/Js/tracker-consent.html` stopped
  part-way at random (4–26 of 40 checks, `RESULT: RUNNING`, even with a
  budget of 3 600 000): Chrome's virtual time jumps to the end of its
  budget when no timer is pending, even while a frame loads. The page now
  keeps a 10 ms interval running until its result is written; 20 of 20
  full runs green on Linux, green on macOS. Red evidence: the Automations
  template's go-live note hidden for every state → exit 1, "Failed browser
  tests: Test/Js/automations-save.html: RESULT: FAIL (4 of 39 checks
  failed)" and the four checks (macOS, reverted); the Hyvä tracker reading
  website 1's consent → exit 1, tracker-consent.html 4 of 40 named (Linux,
  in the container's copy only). Not verified: the job on GitHub's runner
  (Google Chrome on Ubuntu, not run from here). TESTING.md ("Browser JS
  harnesses"), CLAUDE.md gates.

- **PRO-3739 — the rc5 package installs on a clean store by the install
  guide (2026-10-04; docs only, no CHANGELOG bullet).** The ZIP and its
  .sha256 were downloaded from the 3.0.0-rc5 GitHub release (`shasum -a
  256 -c` OK on macOS, `sha256sum -c` OK in the container) and installed
  into a real `app/code` on a fresh Magento 2.4.8-p4 without sample data,
  in production mode, on a separate temporary compose project (own name,
  containers, volumes, ports; sandbox image; no working-tree mount — the
  sandbox stayed untouched and Up), following `docs/INSTALLING.md` step by
  step: extract (the guide's `mkdir -p` creates `app/code`), the production
  sequence with `setup:static-content:deploy en_US et_EE`, `crontab -l` /
  `cron:install` (the cron package installed in the temporary container
  first), module enabled, six `smaily_*` tables, the "ready to set up"
  notice, four menu entries. `cron:run --group smaily_connect` returns at
  once and runs the group in its own process: all eight `smaily_*` jobs
  finished `success` without a Smaily or engine connection (the two daily
  jobs queued by hand), plus one plain `cron:run`; no `exception.log`, no
  `var/report`, no Smaily_Connect line in `system.log`. Initial setup opens
  from every module page; step 1 refuses Continue without a tested
  connection (no request leaves the store). Dashboard, Settings (all five
  tabs) and Log were opened with `setup_completed` set in the database:
  no console errors, Not connected everywhere. The update section (fresh
  extraction, the step 3 commands, second `setup:upgrade`) passed; settings
  and tables kept, the Dashboard opens. One guide fix: the setup notice is
  in the admin notifications (the bell), not under System Messages, as
  step 5 implied. TESTING.md records the run. Stack removed afterwards
  (`down -v`).

- **PRO-3736 — a browser harness checks the Automations tab's save result
  (2026-10-04; tooling, no CHANGELOG bullet).** PRO-3734's save result had
  been checked only with one-off stubs. `Test/Js/render-admin.php` renders
  the real `config/engine-automations.phtml` in en_US and et_EE as Magento
  does (Magento's Escaper, `__()` from `i18n/<locale>.csv`, a fixed view
  model: three triggers, Campaign Intelligence connected) into
  `Test/Js/build/` (gitignored); `bin/test-js.sh` runs it before the pages.
  `Test/Js/automations-save.html` runs the template's own script with
  jQuery from `vendor/` (phpunit's code-coverage copy, 3.6.1; the module's
  vendor/ has no Magento lib/web), stubs only the save request with the
  save endpoint's recorded answer and checks in both languages: Replenishment
  due asked for real sends and was kept in test mode → Test mode pill, box
  ticked again, go-live note, result "Saved." + note; Post-purchase stored
  active → Active, note hidden; Win-back off → Off; stored state not
  readable → the reload message, cards keep the page's state (39 checks).
  With `showStoredStates()` reduced to the pre-PRO-3734 behaviour (the cards
  keep the request) it fails 10 of 39 (reverted). A view model is fixed data
  rather than AutomationsForm on stubbed engine calls — the harness tests the
  page's script; the view model has its unit tests. Adding the Connection
  screen means one `$pages` entry and one page. TESTING.md ("Browser JS
  harnesses"). CI runs the harnesses since PRO-3738.

- **PRO-3737 — the admin target spec names the initial setup as the admin
  does (2026-10-04; docs only, no CHANGELOG bullet).**
  `docs/ADMIN_UI_TARGET_SPEC.md` said "Setup Wizard" where it means the
  shipped page — the dashboard's Quick links row, the §2.2 heading and the
  PRO-1357 finding table's row 5; all three now say "Initial setup", as the
  admin shows. No link points at the §2.2 heading's anchor. The design-pack
  file name `Setup Wizard.dc.html` in §2.2's source line stays (it names a
  file, not the page); dated audits, the upstream proposal and the
  multi-website RFC stay as written.

- **PRO-3729 — the admin target spec and three code comments use the
  canon wording (2026-10-04; docs and comments only, no CHANGELOG
  bullet).** `docs/ADMIN_UI_TARGET_SPEC.md` quotes the shipped dashboard
  prompt ("Setup is not finished yet — complete the initial setup to start
  syncing.") and the shipped welcome automation text (EN "fires when a
  shopper subscribes to the newsletter in your store", ET "käivitub, kui
  ostja liitub poes uudiskirjaga"); the comments in
  `Block/Adminhtml/Config/TestConnection.php`, `config/assist.phtml` and
  `panel/panels-js.phtml` name the button "Test connection", as it reads
  since PRO-3644. No text a merchant sees changes (the Automations tab
  intro stays, owner decision 2026-10-04); dated audits, the upstream
  proposal and the multi-website RFC stay as written.

- **PRO-3733 — a command-line Campaign Intelligence connection with
  rewrites off sends the storefront's site address (2026-10-04; after
  rc5).** `Engine\Client::siteUrl()` (the `plugin_info.site_url` of the
  setup exchange, kept in the engine's audit log) now passes the default
  store view's base link through `Model\StorefrontScript` (PRO-3732): under
  bin/magento with rewrites off it was `…/magento/`, now `…/index.php/` —
  what an admin connection sends. New constructor dependency (appended).
  Tests: `ClientTest` rewrites off under bin/magento → `index.php` (red
  without the change), rewrites on unchanged. Sandbox: `setup:upgrade` +
  `setup:di:compile` OK; a CLI probe named `magento` built the address only
  (nothing sent): rewrites off (in-memory) link base
  `http://localhost:8080/magento/` → site address
  `http://localhost:8080/index.php/`; rewrites on `http://localhost:8080/`
  both. CHANGELOG opens "Changes since 3.0.0-rc5"; ARCHITECTURE.

- **PRO-3735 — CI parses every PHP and PHTML file on PHP 8.1 (2026-10-04;
  tooling, no bullet in CHANGELOG).** New CI job "PHP 8.1 syntax" runs
  `bin/lint-php.sh`: `php -l` over every `*.php` / `*.phtml` outside
  vendor/, .git and .claude, reporting every failure and ending with the
  list of files PHP 8.1 cannot parse (exit 1). Before, only the PHP 8.1 unit
  job noticed newer syntax, and only in files a test loads (aa07b2d: red
  for five commits). Checked locally under `php:8.1-cli`: green on the tree
  (370 files); with a DNF type in `Model/StorefrontScript.php` and an
  8.4-only `new X()->y()` in `config/assist.phtml` (temporary, reverted) it
  failed naming both files. CLAUDE.md's PHP 8.1 gotcha and TESTING.md now
  point at the script.

- **PRO-3734 — the Automations tab shows the engine's stored trigger
  state after a save; the go-live step says Smaily switches real sends on
  (2026-10-04; owner design via the orchestrator: as Woo PRO-3707, the
  go-live control stays; in rc5).** Contract copy synced byte-identically with
  engine main 32f6222 (`bin/check-contract-staleness.sh` against the
  fetched copy: OK, md5 `36ebd8b9…`; STALE before). Still v1.8.2, a
  clarification: §13 (PRO-3705) stores a row with `enabled: true` +
  `test_mode: false` with `test_mode: true` unless the trigger already
  sends to real customers — a Smaily operator switches real sends on in
  the engine admin after the merchant's written yes; the response stays
  `200 {ok, upserted}`, §12 returns the stored state, every `enabled` /
  `test_mode` change is recorded engine-side. No new endpoint, field or
  shape, and no pending-request field, so the tab shows none.
  **Change:** `Automations\Save` reads §12 after a successful PUT and
  answers `states` (each saved trigger's stored `enabled` / `test_mode`,
  fail-closed defaults for a row the read lacks; `null` when the read
  fails); the template redraws each card from it — pill (Off / Test mode /
  Active), border, both checkboxes — and the result says *Saved.* plus the
  go-live note when a trigger asked for real sends and was kept in test
  mode, or *Saved. Reload the page…* when the read failed. Every card that
  is not Active carries the note "Smaily switches real sends on after you
  confirm. Until then the trigger runs in test mode." (EN + ET; the same
  template serves Settings > Automations and the hidden system-config
  group). Tests: `SaveTest` (kept in test mode while live was asked →
  answers test; an operator-activated trigger stays active; read fails →
  `states: null`; refused save reads nothing) — red without the change.
  **Visual (sandbox, en_US + et_EE):** the real block rendered by Magento
  in the adminhtml area (probe, read-only catalog + config GETs on the
  live tenant; all four triggers Off) with the module CSS in headless
  Chrome; the save's fetch was stubbed with an engine answer (nothing sent
  to the engine): Replenishment due asked for real sends → pill TEST MODE,
  Test mode ticked again, note shown, result "Saved. Smaily switches real
  sends on…"; Post-purchase stored active → ACTIVE, note hidden; Off cards
  unchanged with the note. Probe removed. USER_GUIDE (Engine automations
  — going live), ARCHITECTURE, CHANGELOG (rc5 list).

- **PRO-1967 simplification pass, behaviour-neutral (2026-10-04).** One
  `Model\Engine\CatalogProductLoader` loads the backfill page and the
  stock-change batch (canonical scope, `PRODUCT_ATTRIBUTES`, URL rewrites;
  each caller adds its filter); the queue leaves out a row identical to the
  entity's newest unsent row (`IngestQueue::enqueueChangedPayloads()`, one
  newest row per entity read in SQL by `latestUndeliveredPayloads()`; new
  integration test); `CatalogIngest::markProductChanged()` /
  `markSkusChanged()` (renamed; sku dedupe there, the MSI plugins pass
  their skus as read); IngestQueue shares its row, id and table helpers.

- **PRO-3730 — the abandoned-cart scan reads only the window on a busy
  store that keeps old carts (2026-10-04; measured in spike PRO-3713; owner
  decision via the orchestrator: keep the id order; in rc5).** The scan's page is
  ordered by `main_table.entity_id + 0` instead of `entity_id`
  (`Cron\AbandonedCart`, one line): an expression cannot be read from an
  index, so MySQL and MariaDB no longer walk the primary key in id order,
  and the order — so the page of 100 — is the same. An index hint
  (`IGNORE INDEX FOR ORDER BY (PRIMARY)`) measures the same but
  `Zend_Db_Select` cannot place it after the table alias (Magento core
  str_replaces the SQL string for that). **Evidence** — the SQL the code
  emits, captured from the integration run (general log) with the window
  set to the dataset's 24 h, on the PRO-3713 synthetic 3M carts (throwaway
  MySQL 8.4 `pro3730-mysql`, removed): **60k window** (58,868 carts changed in
  the 24 h), one store view: before 3,068,044 rows examined, 1.80–1.88 s
  (Index scan on PRIMARY, 3.06M rows); after 59,435 rows, 51–54 ms (range
  on QUOTE_STORE_ID_UPDATED_AT, 32,422 rows, sort 16,969); a website of all
  four store views (the whole window): before 3,076,353 / 1.88 s, after
  108,204 rows examined / 96–99 ms — the cart table reads exactly the
  58,868 window rows, the rest are the address and tracker lookups per
  cart. **Normal store, ~3k window** (3,255): before and after the same plan
  and rows (4,832 one store, 8,881 four stores; 3.5–7 ms); one of five
  before-runs right after ANALYZE walked the table (3,002,124 rows,
  1.8 s), the after-runs never did. **MariaDB 10.6.28**, same data: the old
  SQL walks PRIMARY at 60k too (3,059,724 rows read, 1.88 s); the new reads
  the range (34,146 + 42,291 handler reads, 59 ms; four stores 108 ms); the
  3k window unchanged (6 ms). Reminder rules: the PRO-3711/PRO-3693
  integration scenarios pass unchanged, plus a new one — 101 idle carts
  whose last changes run opposite to their ids: the page holds carts 1–100
  (it would not under an updated_at order). ARCHITECTURE, CHANGELOG.

- **PRO-3732 — the abandoned-cart reminder's cart link opens with web
  server rewrites off (2026-10-04; found during PRO-3731; in rc5).** Reproduced on the sandbox (Luma, rewrites off) with
  a probe that builds the reminder as `Cron\AbandonedCart` does (crontab
  area, frontend emulation of the cart's store, script named `magento`;
  nothing queued or sent; a guest cart made through the storefront, deleted
  after). **Red:** `abandoned_cart_url`
  `http://localhost:8080/magento/smaily/cart/restore/id/12/ts/…/token/…/`
  (404) and `store_url` `http://localhost:8080/magento/` (404). **Fix:** the
  PRO-3731 script-name rule moved from `CatalogPayloadBuilder` into one
  shared class, `Model\StorefrontScript::apply()` (same code; the catalog
  product link uses it), and `AbandonedCart\PayloadBuilder` passes both
  links through it. **Green (same probe):** `…/index.php/smaily/cart/restore/…`
  302 to `/index.php/checkout/cart/`, and the cart page in a fresh session
  shows the cart's product (one cart line); a tampered token lands on an
  empty cart (signature check intact); `store_url` `…/index.php/` 200.
  Rewrites on: both links byte-identical to what Magento builds (the old
  code's value), restore link 302 to the cart with the product. **Every link
  a background job sends, checked with rewrites off under `magento`:** cart
  link and store link (fixed here), the reminder's product image links
  (media links, no script name, 200), the catalog's `product_url` and
  `image_url` (PRO-3731, `/index.php/…` and media). The RSS feed is built in
  a storefront request only; contact, order and event payloads carry no
  links. Not a background job: the engine connection's `site_url` (audit
  only, built in the admin, or under `bin/magento config:set` where it
  would carry `magento`) — left as a follow-up. Tests: abandoned-cart
  payload rewrites off under bin/magento → index.php, rewrites on unchanged;
  catalog tests unchanged and green through the shared class. ARCHITECTURE,
  CHANGELOG. Sandbox restored (`web/seo/use_rewrites` row removed again,
  config and FPC cleaned, test cart and probe removed).

- **PRO-3731 — catalog image and product links built in cron, CLI and the
  admin are the storefront's (2026-10-04; found during PRO-1967;
  in rc5).** Reproduced on the sandbox (Luma,
  et_EE, rewrites off), building through the backfill page loader (=
  nightly re-sync) and the PRO-1967 `buildChanged()` loader by reflection
  in the crontab area under a script named `magento`, nothing queued or
  sent (queue empty after). **Red:** a product WITH an image (24-MB01)
  and one without (24-MB04, image roles set to `no_selection`
  temporarily) both got
  `static/…/crontab/_view/et_EE/Magento_Catalog/images/product/placeholder/.jpg`
  (HTTP 404) — the crontab area has no theme, so the
  `product_page_image_large` id is unknown; the same old code under the
  admin theme gave `…/adminhtml/Magento/backend/…/placeholder/.jpg`, so
  admin product saves sent it too. `product_url` was
  `http://localhost:8080/magento/joust-duffle-bag.html` (404). Storefront
  reference (the product page): image
  `media/catalog/product/cache/74c1057f…/m/b/mb01-blue-0.jpg` (200
  image/jpeg), placeholder `…/frontend/Magento/luma/et_EE/…/placeholder/image.jpg`
  (200 image/jpeg), link `/index.php/joust-duffle-bag.html` (200).
  **Fix:** `CatalogPayloadBuilder::imageUrl()` runs under frontend emulation
  of the product's price/link store (its own start/stop after
  `productUrl()`'s, never nested; PRO-1458 pricing unchanged, it is read
  outside both); `productUrl()` puts `index.php` where Magento put another
  running script's name (`withStorefrontScript()`: only when the request's
  `SCRIPT_FILENAME` is not index.php and the store's direct-link base ends
  in that name, secure or not). **Green:** both loaders give the storefront
  reference exactly for both products, rewrites off and on (on:
  `/joust-duffle-bag.html`, as the storefront links), and the same payload
  sha per product from both loaders. A 100-product page builds in
  130–137 ms after vs 132–137 ms before. Sandbox restored (image rows,
  `web/seo/use_rewrites` row removed again, FPC cleaned). Tests: image link
  read inside its own emulation of the product's store; rewrites off under
  bin/magento → index.php; rewrites on and a web request unchanged; the
  three PRO-1458 emulation tests now expect two emulations. ARCHITECTURE,
  CHANGELOG. Not covered: a store whose storefront entry script is not
  index.php.

- **PRO-1967 — stock hooks no longer build catalog rows inside the stock
  write's transaction (2026-10-04; owner design 2026-10-04; in
  rc5).** The legacy stock observer and both MSI plugins now
  queue a `catalog_changed` marker per product (payload `[]`, product id in
  `entity_id`; existing `smaily_ingest_queue`, no schema change); the MSI
  plugins resolve all skus with one `getProductsIdsBySkus()` query and
  insert with one `IngestQueue::enqueueMany()`. `FlushIngestQueue` starts
  each run with `CatalogIngest::buildChanged()`: up to 100 markers, one
  product collection (the backfill page's scope and attributes, now shared
  as `CatalogPayloadBuilder::PRODUCT_ATTRIBUTES`), one insert, markers
  deleted; markers for one product collapse, and a row identical to the
  product's newest unsent row is not queued (keeps a product save at one
  row). Markers are not deduped at insert time (would race the flusher —
  a skipped marker could be built before its transaction commits). URL
  emulation stays per product (Magento allows one emulation level; a batch
  emulation would break PRO-1458 pricing on other websites). Product save,
  the delete tombstone and the backfill build at once, unchanged.
  **Sandbox evidence (MSI, 2,046 products, engine sending never run):**
  20-line shipment deduction inside a rolled-back transaction — before
  136 ms / 745 queries / 20 product EAV loads / 20 INSERTs; after 32 ms /
  259 queries / 0 EAV loads / 1 sku SELECT + 1 INSERT (MSI alone, engine
  disconnected: 27–34 ms / 257). 20-item source-items save: before 134 ms /
  680 / 20 INSERTs; after 32 ms / 211 / 1 SELECT + 1 INSERT (MSI alone 209).
  Payloads of 88 products (simple, variants, configurable, bundle, grouped,
  downloadable) byte-identical before/after when built in the same context
  (sha256 843a6378…3421); the batch builds them in 216 ms vs 501 ms one by
  one. **Caveat (found here, pre-existing in the cron build):** the rows are
  now built in cron, as the backfill and nightly re-sync rows already are,
  and three things the builder reads off the running context differ from an
  in-request build — a placeholder `image_url` carries the area
  (`static/…/crontab/_view/…` vs `adminhtml`/`frontend`/`webapi_rest`), a
  real image's cache path depends on the theme in effect (hypothesis, the
  sandbox has no image files), and with Use Web Server Rewrites off
  (sandbox: off) `product_url` carries the script name
  (`/magento/x.html` under `bin/magento`, vs `/index.php/x.html` in a web
  request) — the per-product frontend emulation fixes neither. Fixed by
  PRO-3731 (above; a real image got a broken placeholder too). PRO-1951 scenarios
  re-run through the legacy stock API: sell-out, restock = exactly one
  catalog row each with the right `in_stock`, disconnected = none; product
  rename = one row (before and after); shipping the last unit = one row,
  `in_stock: false` (before and after). Tests: CatalogIngest marker/batch
  unit tests (collapse, deleted product, unsent-duplicate, tombstone, failed
  build), the PRO-3692 parity test now also covers the stock-change path,
  plugin and flusher order tests, IngestQueue `enqueueMany` /
  `undeliveredPayloads` / `delete` integration tests. ARCHITECTURE, USER_GUIDE
  (Log), BACKLOG (pending-row dedupe now only for product saves), CHANGELOG
  (the Log shows a new transient row type, so a bullet, as PRO-3714 opened
  the rc3 list).

- **PRO-2477 — translation and doc leftovers after the terminology canon
  (2026-10-04; found during PRO-1748; nothing merchant-visible).** Both i18n
  CSVs lose six rows no php, phtml, js, xml or html file references, all
  with pre-canon wording: "1. Connect" … "5. Done" and "Welcome — fires
  when someone becomes a subscriber" (483 rows each, same key set). The
  target spec's dashboard text table reads "Settings / Log / Initial setup
  | Seaded / Logi / Algseadistus" (was Setup Wizard / Seadistusviisard;
  owner decision 2026-10-04). Dated audits, UPSTREAM_PROPOSAL and
  RFC_MULTI_WEBSITE are unedited. No "Test Connection" row is added: since
  PRO-3644 the button reads "Test connection", which both CSVs already
  translate ("Testi ühendust"), so a title-case row would be a new orphan
  (open with the owner). No CHANGELOG bullet: nothing a merchant sees
  changes.

- **PRO-3559 — the static-analysis gate runs on a default local PHP
  (2026-10-04; found during the lock refresh).** With PHP's default 128M,
  `vendor/bin/phpstan analyse` stopped with a worker out-of-memory error.
  `phpstan.neon.dist` now loads `Test/phpstan-bootstrap.php`
  (`bootstrapFiles`, main process and every worker), which raises a lower
  limit to 1G; `-1`, a higher php.ini limit or `--memory-limit` stay. The
  documented command is unchanged; what PHPStan checks is unchanged.
  Evidence, result cache cleared: `php -d memory_limit=128M
  vendor/bin/phpstan analyse` red on the old config, `[OK]` on the new.
  TESTING says why. CI (memory unlimited) is unaffected.

- **Simplification pass, behaviour-neutral (2026-10-03).** One
  `Model\RateLimit\FixedWindowCounter` holds the relay's and the guest
  cart email's cache counters (same keys, limits, TTLs);
  `WizardStepSaver::saveConnect()` reads each posted password and the
  fallback language once, takes each block's store view from
  `heldAccounts` (same first-in-order store view, new unit test) and
  shares `deleteStoreViewCredentials()`; the abandoned-cart cron reads the
  addresses reminded in 24 h once per page (`addressesRemindedSince()`,
  `trackedAddresses()`; `hasReminderSince()` removed); the skip path uses
  `SyncDispatcher::recordSkippedAutomation()` / `EventQueue::enqueueSkipped()`
  (no flag parameter); `StateManager::eraseForEmail()` serves `LocalEraser`,
  its active-cart tombstone leaves erased carts out with a LEFT JOIN.

- **PRO-3724 — browse tracking counts a cookie-notice acceptance only for
  the current website (2026-10-03; found during PRO-3675; owner design: as
  Magento itself does).** Both trackers took any `user_allowed_save_cookie`
  as consent, but the cookie is a JSON map of the website ids the shopper
  accepted on (`{"1":1}`), so on websites sharing a cookie domain an
  acceptance on one website started tracking on the other. Now (Luma
  `tracker.js`, Hyvä `smaily-tracker.js`) only the current website's entry
  counts, read as Magento's cookie helper (`isUserNotAllowSaveCookie()`:
  `!empty($accepted[$websiteId])`) and Hyvä's notice (`isAllowedSaveCookie()`,
  `!!` of the entry; 1.4.0, 1.5.2, csp 1.5.2) read it; the website id comes
  from the server (`websiteId` in the tracker config, `ViewModel\EngineState`,
  `StoreManager::getWebsite()`), since neither theme exposes it to other
  scripts. A value that does not parse is no consent: the cookie helper
  treats it as no website accepted (Hyvä's notice and Magento_GoogleAnalytics
  call `JSON.parse` unguarded and throw). Luma's core `notices.js` hides its
  banner on any cookie value (not per website), so on Luma a shopper who
  accepted on website 1 sees no notice on website 2 and stays untracked
  there — Magento's server-side check treats them as not accepted there
  too; Hyvä's notice shows per website. The consent override still decides
  first. New harness `Test/Js/tracker-consent.html`
  (real Luma and Hyvä scripts, 10 scenarios each, 40 checks; red on the old
  trackers: 12 checks — other website, `{"2":0}`, unparseable); unit test
  for `websiteId`. ARCHITECTURE, HYVA_SUPPORT (tracker bullet, consent row),
  TESTING, CHANGELOG. The user guide's consent section does not describe
  the cookie check, so it is unchanged. Not re-run on a store.

- **PRO-3675 — the Hyvä tracker starts on the event Hyvä's cookie notice
  really dispatches (2026-10-03; documentation only).** PRO-3664 took the
  window event `user-allowed-save-cookie` from memory. Checked against the
  public Hyvä sources: `Magento_Cookie/templates/notices.phtml`,
  `setAcceptCookies()`, sets `user_allowed_save_cookie` and then calls
  `window.dispatchEvent(new CustomEvent('user-allowed-save-cookie'))` in
  `hyva-themes/magento2-default-theme` 1.4.0 and 1.5.2 (the supported
  range is 1.4+) and in `magento2-default-theme-csp` 1.5.2 — the name
  `smaily-tracker.js` listens to on `window`, so the listener is unchanged
  (no other name exists in those versions). `docs/HYVA_SUPPORT.md`: the
  tracker bullet cites the source; the consent row of the verification
  matrix describes today's behaviour (no browse event, so no visitor
  token, and no session cookie without consent; accepting the notice
  starts tracking on the same page) and says it is not re-run on a store
  since the consent gate. The live Hyvä store check stays human
  acceptance. No CHANGELOG bullet (no behaviour change).

- **PRO-3719 — the connection status after a reload describes the account
  the save checked; each website keeps its own fallback language
  (2026-10-03; found during PRO-3717, reproduced in the integration
  harness).** (1) After a save the status describes the account saved for
  the website (PRO-3717), but on a reload `WizardData::isSmailyVerified()`,
  boot `verified` / `planBlocked`, the finished setup's `verified` and the
  Dashboard read `VerifiedCredentials::isVerified()` for the website's
  default store view: in mode A with an et default store view and the en
  fallback, *Connected* after the save, *Not connected* after a reload.
  Now `VerifiedCredentials::isWebsiteVerified()` / `isWebsitePlanBlocked()`
  (new; `isPlanBlocked(storeId)` removed, no other reader) read the
  website-scope account (`Config::getWebsite*`), and every status reader
  asks them: `WizardData` (status, `boot.verified`, `boot.planBlocked`, the
  account name and the setup summary's subdomain and username), `SaveStep`
  (finish) and `DashboardData` (card, verdict, subdomain). The fields
  (`boot.connection`) and each language block's own status (`isVerified`
  per store view) are unchanged. (2) `fallback_language` was written
  without a scope and read at default scope, so on a multi-website install
  the last mode-A save set every website's fallback radio. Now the save
  writes it at the target website's scope and `Config::getFallbackLanguage
  (?int $websiteId)` reads the website value, falling back to the
  default-scope row an earlier save wrote — no migration, old rows keep
  working (a website with no value of its own shows the old shared one,
  as before). Integration `PerLanguageAccountsSaveTest` (+2, red on the
  old code): after a mode-A save with an et default store view, a fresh
  `WizardData` (real `Config` + `VerifiedCredentials`, the fake Smaily
  client accepts through it as `SmailyClient` does) shows verified, the
  `en-shop` account and `boot.verified`; with a legacy default-scope `et`
  row, a website-7 save with fallback en leaves website 8 on et. Unit:
  `VerifiedCredentialsTest` (+1), `WizardDataTest` (+1, status test now
  website account), `WizardStepSaverTest` (fallback saved at website
  scope), `DashboardDataTest`, `SaveStepTest`. USER_GUIDE (Default
  fallback account), ARCHITECTURE, CHANGELOG. No new phrases. Not run in
  the sandbox.

- **PRO-3718 — a new default fallback account needs its password on the
  server too (2026-10-03; found by reading code during PRO-3699,
  reproduced in the integration harness).** In mode A the form asked for
  the new fallback account's password when the fallback language changed,
  but the server did not: a post that skipped the form check saved the new
  fallback account at website scope with the old website password (the
  integration test's refused case returned no error on the old code).
  Now `changedAccountPasswordErrors` compares the top-level credentials
  (the fallback account's) with the account saved at website scope in
  mode A as well — the PRO-3699 comparison, before any write — and refuses
  a mismatch without a password on `accounts.<fallback>.password` with the
  existing "Enter the password of the %1 account so it can take over as
  the default fallback." (EN + ET); when the fallback block already has
  its own PRO-3690 error, that one error stays. A mode-A post without a
  fallback language gets the single-account error on `password`. An
  unchanged fallback with an empty password keeps the saved password.
  Client side: the boot JSON gains `websiteAccount` (subdomain and username
  saved for the website, `WizardData::getBootJson`; `connection`, which the
  fields and the status use, is unchanged), and panels-js compares with it
  — the single account (instead of `boot.connection`, the PRO-3699 side
  effect) and the mode-A fallback account (instead of "the fallback
  language changed", whose baseline was the default-scope fallback
  language); a successful connect save makes the posted top-level account
  the new baseline. `mlFallbackSaved` is gone (no other reader). Unit
  `WizardStepSaverTest` (+1: a new fallback block without a password shows
  one error), `WizardDataTest` (+1: `websiteAccount` is the website
  account, not the store view's). Integration `PerLanguageAccountsSaveTest`
  (+2: fallback en→et without the et password → refused, website keeps en
  and the en password, fallback stays en — red on the old code; with the
  password → the website gets the et account, store views keep theirs).
  Verified with the real Settings templates and stub data in headless
  Chrome, en_US and et_EE, old and new JS (website account nordic-en, the
  store view and the drawn fallback nordic-et): mode A — the drawn et
  fallback without a password is now refused (old: posted, the server
  refuses it), en (the website account) without a password now posts (old:
  refused), et with its password posts and is the next baseline; single —
  the drawn store-view account without a password is now refused (old:
  posted), the website account (also in capitals) now posts (old:
  refused), another account with its password posts. USER_GUIDE (Default
  fallback account), CHANGELOG. Not run in the sandbox.

- **PRO-3717 — with per-language accounts, the post-save connection status
  checks the right password (2026-10-03; found by reading code during
  PRO-3699, reproduced in the integration harness).**
  `WizardStepSaver::checkCredentials()` checked the posted top-level
  (website-scope) subdomain and username, but an empty password came from
  the website's default store view (`resolvePassword(..., getStoreId())`).
  In mode A with a default store view whose language is not the fallback
  language, that paired the en fallback account with the et password
  (measured on the old code), Smaily refused it, and the status said "Not
  connected" although every saved account worked. Leaving mode A did the
  same: the store-view rows were deleted, but the scope config read in the
  request still held them. Now an empty or masked password is the
  website-scope one (`Config::getWebsitePassword`, new) — the scope the
  subdomain and username are written to; a kept password is not written in
  the request, so the request's config is not stale for it. Meaning of the
  status unchanged: after a save it describes the account saved for the
  website (the single account; in mode A the default fallback account).
  Integration `PerLanguageAccountsSaveTest`: the saver now reads through a
  new fake `RequestCachedScopeConfig` (core_config_data loaded once per
  request, as Magento does; cleared when the saver cleans the config cache
  type), and Smaily accepts only each language's own account; a mode-A save
  with an et default store view checks en-shop/en-user/en-secret and is
  accepted; leaving mode A with the fallback account and an empty password
  checks the en password — both red on the old code (they got et-secret).
  Unit `testAKeptPasswordIsCheckedAsStored` now reads the website's
  password. USER_GUIDE (Default fallback account), CHANGELOG. Not run in
  the sandbox. After a reload the status read the default store view's
  account (`WizardData::isSmailyVerified`), which in mode A can be another
  language's account than the one a save checks — fixed by PRO-3719.

- **PRO-3715 — a purchase line without a SKU names the same product as the
  bought item's catalog row (2026-10-03; found by a read-only review).** An
  empty-SKU order line fell back to `mag-<order item product_id>`; on a
  configurable parent item that is the PARENT's id, while the variant's
  catalog row is `mag-<child id>`, so the two never joined. Now
  `OrderPayloadBuilder` maps each parent line to its child item (by
  `parent_item_id`, the same signal that skips child lines), and an
  empty-SKU `configurable` line keys on the child — its SKU, or
  `mag-<child product id>`. Bundles and every other line keep today's key;
  a non-empty SKU is unchanged. Wire shape unchanged (values only). Unit
  tests: configurable → `mag-<child>`, bundle keeps `mag-<own id>`, the
  existing simple/whitespace/normal-SKU cases unchanged. Not run in the
  sandbox.

- **PRO-3714 — a variant without categories of its own is sent with its
  visible parent's category (2026-10-03; found by a read-only review).**
  `CatalogPayloadBuilder` read only the child's own `getCategoryIds()`, and
  a typical configurable child has none, so it went as `uncategorized` +
  `tags.category_defaulted: "true"`. Now, when the variant's own ids give
  no real category, the builder runs the same `categoryPath()` on the
  parent's category ids: `ParentProductResolver::parentCategoryIds()` — the
  parent `productIdOf()` resolves (lowest id when several), one
  `catalog_category_product` query per parent (the rows
  `Product::getCategoryIds()` reads), memoized, [] for a non-variant with
  no query. `category_defaulted` is set only when neither has a category.
  A variant with its own categories and a non-variant are unchanged. Wire
  shape unchanged (values only). Unit tests: parent's category used, own
  category kept, both empty → defaulted; resolver: one query per shared
  parent, none for a non-variant. Not run in the sandbox.

- **PRO-3699 — leaving per-language accounts cannot save one account with
  another's password (2026-10-03; found by reading code during PRO-3690,
  reproduced in the integration harness).** After mode A the single-account
  fields show the website's default store view's account (its store-view
  rows, `WebsiteContext::getStoreId()` / boot JSON). Saved unchanged with an
  empty password, that subdomain and username went to website scope with the
  website's own password — the fallback account's — and the store-view rows
  were then removed: with the default store view's language not the fallback
  language, every store view was left on e.g. `et-shop` / `et-user` with the
  en password (measured on the old code), so Smaily refused it. The PRO-3690
  rule did not catch it: it compared the single account with the default
  store view, which still held the per-language account. Now the single
  account (every mode but A) is compared with the account saved at website
  scope (`Config::getWebsiteSubdomain` / `getWebsiteUsername`, new) — the
  scope the save writes and whose password an empty one keeps; a mismatch
  without a password is refused with the existing PRO-3690 field error
  (`password`, EN + ET). The check runs before any write, so the mode change
  and the store-view teardown in the same request do not affect it. Picked
  over making the panel show the website account: one comparison target on
  the server closes the path whatever the form shows. Known client-side
  side effect: panels-js still compares with `boot.connection` (the store
  view's account), so after leaving mode A it lets the shown account through
  to the server refusal and asks for a password for the fallback account the
  server would accept (fixed by PRO-3718: the form compares with the
  website account). Integration `PerLanguageAccountsSaveTest` (+3; the
  harness's website mock gains `getStores` / `getDefaultStore`, store view 2
  et is website 7's default): unchanged et account without a password →
  refused, nothing saved, mode A stays; en fallback without a password and
  et with its password → every store view on that account. The first two are
  red on the old code. Unit `WizardStepSaverTest` stubs the new getters.
  USER_GUIDE (Default fallback account), CHANGELOG (new "Changes since
  3.0.0-rc3"). Not run in the sandbox.

- **PRO-3711 — a busy store keeps sending abandoned-cart reminders
  (2026-10-02; found by reading the code).** `Cron\AbandonedCart` loaded the
  first 100 idle carts of the 24-hour window (by quote id) and only then
  dropped the handled ones in PHP (`filterAlreadyHandled()`). Handled carts
  stay active and idle, so with more than 100 of them in the window a newer
  cart was never loaded. Now `StateManager::excludeHandled()` adds a LEFT
  JOIN on `smaily_abandoned_cart` (unique `quote_id`, terminal statuses —
  `skipped` included — in the ON clause) and `quote_id IS NULL` to the
  collection's select, before the LIMIT; page size, order and the other
  filters are unchanged. `filterAlreadyHandled()` is removed: after the SQL
  exclusion it only re-read rows the same SELECT had just checked (no guard
  against a concurrent run either), and the tests that used it as the
  cron's gate (`StateManagerTest`, `GdprEraseTest`) now run
  `excludeHandled()` on a select over the quote mirror. EXPLAIN on the
  throwaway integration DB (quote / quote_address with Magento's own
  indexes, 50,000 quotes): the side table is an `eq_ref` on
  `SMAILY_ABANDONED_CART_QUOTE_ID`, "Not exists" (antijoin); the quote
  access is the same as before (index scan on PRIMARY in id order, as the
  ORDER BY + LIMIT asks). Integration `Cron\AbandonedCartTest` now runs
  Magento's own quote collection on the quote + quote_address mirrors (the
  mirror gains `updated_at`); new case: 150 handled carts (each terminal
  status) + a new cart + an `open` opt-in cart → both reminded, no handled
  cart weighed again — red on the old code. Not run in the sandbox.

- **PRO-3693 follow-through — the security review's findings
  (2026-10-02, the owner's decisions).** (1) The guest-email endpoint's
  limits: 30 requests / 10 min per caller (IPv4 as is, IPv6 by its /64, an
  IPv4-mapped address as its IPv4), 5 writes per cart, and a new ceiling of
  2000 accepted writes per hour for the whole installation — all in the
  application cache (a flush resets them). Behind a proxy Magento does not
  read the forwarding header of, the per-caller limit is one limit for the
  whole store: the USER_GUIDE now says how to configure the real IP
  (INSTALLING has no proxy note, so nothing there). Integration
  `GuestCartEmailTest`: IPv4 limit, IPv6 /64 grouping, IPv4-mapped,
  store-wide ceiling (2000 real writes on 401 carts), per-cart cap.
  (2) The Art. 17 erase now also tombstones every ACTIVE quote whose
  `customer_email` or a `quote_address.email` is the address
  (`StateManager::tombstoneActiveQuotesForEmail()`, from `LocalEraser`;
  status `erased`, inserted or overwriting a non-erased row; core quote
  rows only read; counted under "Abandoned carts … anonymized"). Before,
  a cart under the cutoff or not yet scanned had no row and was mailed to
  the erased address on the next sweep. Integration `GdprEraseTest`
  (quote + new quote_address mirrors): cart email in another case, billing
  address only, an opt-in row under another address, inactive and
  bystander carts left alone, quotes unchanged, the cron's gate (now
  `excludeHandled()`, PRO-3711) skips the three, a second run is a no-op. The quote
  scan uses `LOWER()` on unindexed columns — a full scan of `quote`, as the
  queue scan already is; an admin one-off.
  (3) One abandoned-cart reminder per address per 24 h, across carts:
  `Cron\AbandonedCart` asks `StateManager::hasReminderSince()` (any row,
  `LOWER(email)`, `mail_sent_at` in the last 24 h — sent or queued) and
  marks such a cart with the new terminal side-table status `skipped`
  (no `mail_sent_at`; the column comment in db_schema.xml lists it — a
  comment-only diff on setup:upgrade) plus a queue row stored already
  closed as Skipped (`EventQueue::enqueue()` / `dispatchAutomation()` take
  an optional skip reason) with `SKIPPED_RECENTLY_REMINDED` (EN + ET). The
  side table has no "skipped" before this (the Log's Skipped is the queue's);
  `expired` was the only unused terminal value and means "aged out", so it
  was not reused. Integration `Cron\AbandonedCartTest` (new; real tracker +
  queue, Magento's quote collection and the payload builder stubbed, a
  quote collection factory stub as for products): skipped in another case
  with the Log reading Skipped and the flusher claiming only the other
  row; two carts in one run; a reminder older than 24 h; a skipped cart is
  not weighed again and does not start a new 24 h.
  (4) The checkout sends nothing while the automation is off:
  `AbandonedCart\GuestCartEmailConfigProvider` (frontend
  `CompositeConfigProvider`) puts `smailyGuestCartEmail` (the website's
  abandoned-cart switch) in `window.checkoutConfig`; the mixin posts only
  when it is true. Unit test for the provider; JS harness case (stored and
  typed address, off → no request, Magento's own check still runs; red
  without the guard). Not run in the sandbox (not touched).

- **PRO-3693 — a guest's email reaches the cart as soon as it is typed on
  Magento's own checkout (2026-10-02, owner-approved design, Woo parity).**
  Verified in the sandbox before the change: core checkout keeps the guest
  email in the browser until set-payment-information / place-order, so the
  scan never saw a guest who left at shipping, although the USER_GUIDE said
  it did. New: a mixin on `Magento_Checkout/js/view/form/element/email`
  posts the address when Magento runs `checkEmailAvailability()` (valid
  value, after the 2 s typing pause) and at `initialize()` for a field that
  opens with a validated address; one request per change, none when signed
  in, fire-and-forget. Anonymous REST `POST
  /V1/smaily-connect/guest-carts/:cartId/email` (`AbandonedCart\GuestCartEmail`)
  writes `quote.customer_email` only (one UPDATE — no totals collection, no
  quote save events), on an active guest cart with items whose website has
  the abandoned-cart automation on (data minimisation — an assumption beyond
  the approved design, see the report), after Magento's EmailAddress
  validator; a per-caller and a per-cart limit (application cache, as the
  relay's limiter; since retuned, see the follow-through entry above).
  Who is reminded is unchanged: `force_opt_in=false`, so in every
  contact-sync mode a guest who leaves at shipping is reminded exactly when
  Smaily already has the address as a contact that has not unsubscribed. Tests: integration
  `AbandonedCart\GuestCartEmailTest` (real `quote` + `quote_id_mask` mirrors:
  set, change, customer / inactive / empty / unknown cart, invalid emails,
  feature off, rate limit, per-cart cap); JS harness `Test/Js/email-mixin.html`
  (`bin/test-js.sh`, headless Chrome, Magento's own email component and
  url-builder from vendor; not in CI). Docs: USER_GUIDE (Abandoned cart,
  FAQ, Privacy), ARCHITECTURE, HYVA_SUPPORT, TESTING, CHANGELOG. Not yet
  run in the sandbox (orchestrator's human-acceptance check).

- **PRO-3692 — the catalog import sends the price and sale end date a
  product save sends (2026-10-02).** `EngineCatalogProcessor::loadPage()`
  now also selects `special_from_date`, `special_to_date` and `price_type`.
  Compared against what `CatalogPayloadBuilder` and Magento's price classes
  read off the product (vendor source: catalog `RegularPrice`,
  `SpecialPrice`, `BasePrice`, `FinalPrice`, `TierPrice`, CatalogRule
  `CatalogRulePrice`, the bundle price classes, the tax adjustment): before,
  an imported product on sale had no `on_sale_until`, a sale outside its
  from/to window went as on sale (SpecialPrice read empty dates as "always"),
  and a fixed-price bundle was priced as dynamic. Tier prices load
  themselves (backend `afterLoad`), catalog-rule prices are a DB lookup, the
  rest was already selected. New `EngineCatalogImportParityTest`: one
  product row through the save path and through one import page (the item
  holds only the static columns + the selected attributes), real
  `CatalogIngest` + `CatalogPayloadBuilder` + Magento's catalog price
  classes and Timezone; four cases (running / ended / not-started sale,
  fixed bundle) — all four red without the three attributes. Flat catalog:
  no code change — `Collection::isEnabledFlat()` asks
  `Flat\State::isAvailable()`, true only in the frontend area
  (Magento_Catalog `etc/frontend/di.xml`); the import runs only from cron
  (`BackfillTick`), so it reads EAV with the flat catalog on. Configurable
  and grouped price classes (not in the dev vendor) read their children
  through their own collections — not checked against source here. Not run
  on a real catalog (sandbox not touched).

- **PRO-3694 — the 2.x sync-frequency upgrade notice says what 3.0 does
  (2026-10-02).** `LegacyConfigMapper`'s notice no longer promises "a daily
  full sync" (3.0 has none); it now reads, in UPGRADING's words, "v3 syncs
  in near-real-time (observers + durable queue) with a 15-minute
  Smaily→Magento consent reconcile" (EN + ET; the old rows are replaced).
  `MigrateLegacyConfigTest` pins the full EN and ET texts.

- **PRO-3690 — a changed account needs its password (2026-10-02).** An
  empty (or masked) password keeps the saved one only for the account the
  form was drawn with. `WizardStepSaver::saveConnect` refuses, before
  anything is saved or checked: in mode A, a per-language block with a
  subdomain and username that differ from those of the store view it was
  drawn from (`AccountResolver::storeIdForAccountKey`, as
  `WizardData::getMultilingualAccounts`) — field
  `accounts.<language>.password`, "The subdomain or username of the %1
  account changed — enter its password."; in the other modes, the single
  account against the website's store view (`WebsiteContext::getStoreId`,
  as the boot JSON; since PRO-3699 against the website-scope account) — field `password`, "The subdomain or username changed
  — enter the password of this account." (EN + ET). The subdomain is
  compared in any case, the username exactly; a block without both values
  saves no account and is not checked. The single account did not require
  a password on change before, so it gets the rule too. `collect.connect`
  in panels-js checks the same against `data-saved-subdomain` /
  `data-saved-username` and the single account against the website account
  (`boot.websiteAccount` since PRO-3718, updated after a successful connect
  save, as the blocks already were). Consequence for
  PRO-3683: a block drawn with another account than the one typed now
  needs the password even when another store view holds that account; the
  borrow path still serves a store view that moves to a language whose
  block shows that language's account (integration test re-cut to it).
  Unit `WizardStepSaverTest` (+4, 6 data sets), integration
  `PerLanguageAccountsSaveTest` (+1: the reported case — a store view et→fi,
  the fi block drawn with the et account, fi account typed without a
  password → refused, nothing saved). Verified with the real Settings
  templates and stub data in headless Chrome, en_US and et_EE, mode A and
  single: unchanged / subdomain in capitals save; a changed subdomain or
  username without a password shows the banner and the message under the
  password field; with a password it saves, and the next empty-password
  save passes. USER_GUIDE (Connection, "When a store view's language
  changes"), CHANGELOG.

- **PRO-3683 — per-language accounts follow a store view's language change
  (2026-10-02).** A mode-A Connection save (`WizardStepSaver::saveConnect`,
  posted mode `a` with `accounts`) walks every store view of the website
  being saved (`AccountResolver::storeLanguages`) and gives each one the
  account of its current language at store-view scope. A store view whose
  language has no account in the post (no block, or subdomain and username
  both empty) loses its store-view `smaily_connect/connection/subdomain`,
  `username` and `password` rows and uses the website's account; a block
  with only one of the two is skipped, as before. An empty password keeps
  the saved one: a store view already on that account keeps its own, any
  other store view gets the password of a store view that uses it (read
  before the save). Only store views of the website being saved are
  touched. A posted `accounts` list outside mode A no longer writes
  store-view rows. Integration test `Adminhtml\PerLanguageAccountsSaveTest`
  (real save path, `Config` over `DatabaseScopeConfig`): et→fi with an fi
  account (typed or kept password), et→fi without one (no block / blank
  block → website account, rows gone), half-blank block, other website
  untouched. USER_GUIDE: "When a store view's language changes".

- **PRO-2506 — the catalog import and the nightly re-sync reach every
  website's products (2026-10-02).** `EngineCatalogProcessor::loadPage()`
  no longer calls `addPriceData()`, whose INNER JOIN on the price index at
  the default website kept out every product outside it — and every product
  the price index leaves out (disabled; out of stock while the store hides
  out-of-stock products; on no website). The page now holds what
  `countProducts()` counts (so "X of Y" reaches Y); each product is priced
  by `CatalogPayloadBuilder` at the store `storeIdForProduct()` picks
  (PRO-1458), as on the live path; `tax_class_id` is selected because the
  index used to supply it and the tax adjustment reads it. Side effect, on
  purpose: disabled/hidden products go as tombstones and out-of-stock ones
  as `in_stock: false` every night, so the re-sync now corrects them too.
  Queue `store_id` unchanged — ARCHITECTURE already says it names the
  canonical ingest scope, not the price scope. Pinned by
  `EngineCatalogProcessorTest::testBackfillPagesEveryWebsitesProductsWithoutAWebsiteRestriction`
  (no product-limitation method on page or count — the six that join one
  website, read from Magento's `Product\Collection`; a website-2 product is
  enqueued; `tax_class_id` selected); re-adding `addPriceData()` or dropping
  `tax_class_id` turns it red. Not run against a real catalog (the
  integration harness has no catalog/EAV schema; sandbox not touched).

- **PRO-3654 — engine contract synced v1.8.1 → v1.8.2 (2026-10-02, doc
  only, no sender change).** Byte-identical with engine main 333b05e
  (`bin/check-contract-staleness.sh` against the local engine checkout: OK,
  md5 `d91c1aa4…`). Two changes since 1.8.1, both engine-side: §6/§7 one
  customer per visitor token (PRO-3649 — a token bound to a customer never
  moves to another; the merge and browse responses keep their shape, a
  refused token binding counts 0); §5 customer `language` — when absent the
  engine leaves the Smaily contact's `language` field as it is (PRO-3640).
  `CustomerPayloadBuilder` already leaves `language` out when the store has
  none, so nothing changes in code or fixtures. ARCHITECTURE and
  UPSTREAM_PROPOSAL cite v1.8.2.

- **The earlier 2.x upgrade notices are translated (2026-10-02).** The
  sync-frequency and captcha notices (`LegacyConfigMapper`) and their title
  "Smaily Connect upgrade" (`MigrateLegacyConfig`) go through `__()`, as the
  PRO-3681 notice does; EN + ET rows in `i18n/`. New integration test
  `MigrateLegacyConfigTest::testTheDeliberateDropNoticesAreInEstonianInAnEstonianAdmin`.
  Texts unchanged then; the frequency notice's "a daily full sync" is
  corrected by PRO-3694 (above).

- **PRO-3680 — two docs name only today's code and releases (2026-10-02,
  docs only).** ADMIN_UI_TARGET_SPEC §4.2: the sources paragraph names
  `WizardStepSaver`, the panel templates and `WizardData` as today's ground
  truth and marks `ModuleConfigPaths`/`ConfigOverrides` as history (removed
  with PRO-1461); the `include_guests` row says "both" (system.xml + the
  Subscribers tab checkbox saved by `WizardStepSaver::saveSubscribers()`);
  the migration note keeps the path constraint and marks
  `OverrideDetector`/`OverrideClearer` as history. UPSTREAM_PROPOSAL's header
  no longer says "unreleased" / "no public GitHub release": rc1 and rc2 are
  GitHub pre-releases on the fork, upstream has none.

- **PRO-3663 — Mageplaza One Step Checkout, read from source (2026-10-02,
  docs only, no code change).** Read from public mirrors of the extension's
  source (2.8.2 and 4.0.10; the current release is 4.4.x) and its public
  guide; not run on a store. (a) Its page updates the `checkout_index_index`
  handle and adds its own processor to `Onepage`'s `layoutProcessors`, so the
  core `LayoutProcessor` (and `AddNewsletterOptinToLayout`) still runs; its
  payment template draws `afterMethods`, and its processor removes only
  `billing-address-form` there — the checkbox shows and the
  `smaily/checkout/optin` → `sales_order_place_after` path is unchanged.
  (b) Its email field calls its own `guest-carts/:cartId/isEmailAvailable`,
  which saves `quote.customer_email` at once, so `Cron\AbandonedCart` sees
  the guest's email. (c) Its own newsletter checkbox (on by default, can be
  pre-ticked) calls `Subscriber::subscribe($email)` on
  `sales_model_service_quote_submit_success`, inside the REST place-order
  request: synced under Subscribers only / All customers without Welcome;
  not synced under Checkout opt-in only; with Need to Confirm both
  checkboxes ticked send the confirmation request twice. New USER_GUIDE
  section "Third-party one-step checkouts" (recommend the module's checkbox,
  switch OSC's off; three live-store checks); FAQ links it. Found while
  reading Magento 2.4.7's checkout JS: the core Luma checkout keeps a
  guest's email in the browser (`quote.guestEmail`) until payment
  information is sent, so the guide's "a guest who abandons at the shipping
  step is still reminded" (PRO-1275) is unconfirmed for the core checkout —
  raised for a follow-up, guide text unchanged.

- **PRO-3681 — the owner's four upgrade-day decisions for 2.x → 3.0
  (2026-10-02, Questions item 14 decided; changes what the upgrade
  writes).** `LegacyConfigMapper`: a scope's `smaily/general/enable` = 0
  (default or website) becomes `sync_enabled`, `welcome_enabled` and
  `abandoned_enabled` = 0 at that scope (any carried-over value of the
  three replaced); 1 or absent writes nothing extra. `MigrateLegacyConfig`:
  at store-view scope the subdomain/username/password rows and Enable
  Module are not mapped (still deleted with every `smaily/*` row); every
  other store-view row is mapped at its store view as before, where v3
  does not read it. One admin inbox notice "Smaily Connect upgrade:
  store-view Smaily account not carried over" names each such store view
  as "Store view name (Website name)" (store manager names, never the
  values; EN + ET); a store view that no longer exists is skipped.
  `LegacyScopeUpgradeTest` reads it back through the real getters: website
  4's No → its three off; Default Config No → websites without their own
  value off; a store-view username is not active, every store view uses
  its website's account; notice text EN and ET. UPGRADING: the "do it by
  hand" steps are replaced by what the upgrade does; new "What changes on
  upgrade day" (checkout checkbox on, Magento's confirmation-success and
  unsubscribe emails suppressed, `name` → `first_name`/`last_name`,
  birthday `Y-m-d`, empty values left out, new `language`), each checked
  against `SubscriberPayloadBuilder` and 2.8.1's `Cron/SubscribersSync`.
  Under a default-scope No, a website with its own Yes (decided as
  Questions item 15) gets the three at its scope with the value they
  resolved to there in 2.8.x — its own carried-over value, else the
  default scope's, else the config.xml default — so it runs as in 2.8.x;
  the test covers own values, inherited values and no own value (off).

- **PRO-1398 — no merchant page shows the config-scope banner (2026-10-02,
  presentation only).** Re-checked against today's code: the "Overridden for
  X" banner, its `ConfigOverrides` field anchors (the Contacts tab's
  `include_guests`/`automation_force_opt_in` additions included) and the
  detector/clearer behind it were already removed with the website selector
  (PRO-1461, item 7 below); nothing references them. This pass removes the
  four phrases that outlived them (EN + ET: "Invalid override scope.",
  "Only website or store-view overrides can be cleared.", "Override cleared
  — the default now applies here.", "That setting is not managed by Smaily
  Connect, so it was not touched.") and corrects ADMIN_UI_TARGET_SPEC §2.3
  and finding #3, which still called the banner live. No stored config
  touched; save-time clearing stays PRO-1385. Verified with the real
  Settings and initial-setup templates and stub data in headless Chrome,
  en_US and et_EE: two websites, website 2 selected, per-language accounts
  with store-view credentials (the rows the old detector flagged) — all
  five tabs and the setup steps show no scope banner; the website selector
  beside the tab strip names the website being edited.

- **PRO-3665 — switching abandoned-cart reminders on says to switch off any other sender (2026-10-02).**
  A note under the trigger cards in `panel/automations.phtml` (so Settings > Automations and the
  setup's step 3): "If another tool also sends abandoned-cart emails (for example an extension such
  as Mageplaza SMTP or Avada Email Marketing, or Adobe Commerce's own email reminder rules), switch
  those emails off there, so a shopper does not get two reminders for one cart." (EN + ET). The
  hidden `system.xml` field is unchanged. USER_GUIDE "Abandoned cart" says to list today's senders
  before switching on; PILOT_CHECKLIST §0 has the matching line. `docs/UPGRADING.md` has no
  before-you-upgrade checklist, so it has no line. No detection of other modules. Rendered with the
  real template and stub data in headless Chrome, Settings EN and setup ET.

- **PRO-3661 — a multi-website 2.8.x store's upgrade, scope by scope
  (2026-10-02; test + docs, no behaviour change).** 2.8.x (every 2.x tag,
  2.0.0–2.8.1) shows each setting at Default Config and website only
  (`showInStore="0"`; Frequency default only, Last synchronized at website
  only) and reads each one at website scope, so a store-view `smaily/*` row
  exists only through `config:set` and 2.8.x never reads it. New
  integration test `Migration\LegacyScopeUpgradeTest` (4 websites, 11 store
  views et/en/ru/lv/lt/fi, rows at default, website and store-view scope)
  runs the migration and reads every scope through the real `Config`,
  `LanguageResolver` and `Router` over a new test-only
  `Support\Fake\DatabaseScopeConfig` (core_config_data with Magento's
  store → website → default → config.xml fallback). Confirmed: the
  default-scope account serves every store view of every website; website
  overrides win; every language resolves and, in the migrated single
  mode, routes to its website's workflow through that one account;
  store-view rows of non-account settings stay unread, as in 2.8.x. Two
  gaps, both pinned by the test: (1) **Enable Module** (`smaily/general/enable`,
  default + website) is not migrated — a website with *No* starts sync,
  welcome and abandoned cart in v3; (2) v3 reads the account per store
  view, so a store-view `smaily/general/*` row 2.8.x ignored replaces that
  store view's account. Documented in `docs/UPGRADING.md` (new "Before you
  upgrade: what to note down" checklist with the 2.8.x labels and scopes,
  "Set again after the upgrade", store-view query); the migration change is
  Questions item 14 (decided; built as PRO-3681, above).

- **Storefront URL and consent slice tightened after a simplification review (2026-10-02, behaviour-neutral).** `StorefrontUrl::apply()` reads and normalizes each store's value once per request; `ensureSession()` returns nothing; `WizardData::isStorefrontDisclosureOpen()`; `normalize()` drops the redundant `pass` check (`parse_url` sets `user`, `''` included, whenever a password is given).
- **PRO-3666 — the 3.0.0 changelog's admin-home entry once (2026-10-02).** Three merged copies on
  one line became one entry with every detail any copy carried; no other CHANGELOG bullet repeats.
- **PRO-3664 — the browse tracker's consent, as in the WooCommerce plugin
  (2026-10-02, owner design "exactly like Woo").** Consent category
  marketing; order: (1) the store's `window.smailyConnect.consentOverride()`
  when it is a function (`=== true` is consent), (2) under Magento cookie
  restriction mode `user_allowed_save_cookie`, (3) otherwise none. Without
  consent the tracker (Luma `tracker.js` and Hyvä `smaily-tracker.js`)
  sends no event and writes no `smaily_anon_sid` — the attribution scripts
  no longer write it; they expose `ensureSession()`, which the tracker
  calls on consent. Campaign-click cookies stay ungated. Later consent
  starts tracking: Luma's jQuery `user:allowed:save:cookie`, Hyvä's window
  `user-allowed-save-cookie` (checked against Hyvä's cookie-notice source by
  PRO-3675, not yet on a Hyvä store), and the documented `smaily:consent-changed` on
  `document`; each flush asks again and drops the queue without consent.
  The tracker config key `consentRequired` is now `cookieRestriction`.
  User Guide section "Connecting your cookie consent tool" (contract,
  Amasty and Cookiebot examples, documentation only). Checked with the
  real scripts in a browser harness with stubbed globals (7 scenarios ×
  Luma/Hyvä).
  Admin (owner addition): the note under the browse-tracking toggle
  (`panel/intelligence.phtml`, so Settings > Intelligence and the setup's
  step 4) reads, when cookie restriction mode is off in any store view
  (`Model\Engine\ConsentSource`), "Browse tracking sends nothing until a
  consent source is connected: switch on Magento's cookie restriction mode
  [Stores > Configuration > General > Web], or connect your own cookie
  consent tool [User Guide]"; when it is on everywhere, "Consent comes from
  Magento's cookie notice …". `Cron\HealthCheck` posts a minor notice
  (Read Details → the guide section) under the same condition while browse
  tracking is on, once per occurrence (flag
  `smaily_connect_consent_source_notified`, cleared when the condition
  clears). The server cannot see an override, so a store with one also
  gets the notice and the "sends nothing" note — the notice says to mark
  it read; the note stays. EN + ET. Rendered with the real template and
  stub data, both states × both contexts × EN/ET.

- **PRO-3660 — the Storefront URL field opens by itself on an API-only
  store (2026-10-02, owner decision: Questions item 13 option b).** New
  `Model\OrderOrigin` + `Observer\RecordOrderOrigin` on
  `sales_order_place_after` (failures logged, never stop the order): an
  order placed in the `graphql` area, or in `webapi_rest` without the
  `form_key` cookie, stamps `smaily_connect_last_api_order_at`; every other
  order (Luma's checkout REST call carries the cookie, admin and area-less
  orders too) stamps `smaily_connect_last_storefront_order_at` — FlagManager
  rows, no schema change; the uninstall's `smaily_connect\_%` flag delete
  removes them (`UninstallTest` now seeds both). `WizardData::isApiOnlyStore()`
  — an API order and no storefront order in the last 30 days — draws the
  disclosure open with "In the last 30 days your orders came only through
  Magento's API, as they do from a separate storefront." (EN + ET);
  collapsed otherwise and before any order. Unit: new `OrderOriginTest`
  (8 origins, 7 window cases), `RecordOrderOriginTest`, `WizardDataTest`
  (+1). Headless Chrome render with stub data, both languages: opened with
  the sentence. USER_GUIDE, HEADLESS_STOREFRONTS, ARCHITECTURE, CHANGELOG.

- **PRO-3660 — Storefront URL for a separate (headless) storefront
  (2026-10-02; the auto-open followed, see the entry above).** Owner
  design: one setting, `smaily_connect/connection/storefront_url`, website
  scope like the other connection settings (`system.xml` declares it for
  `config:set`). New `Model\StorefrontUrl`: `normalize()` accepts an https
  address of a host alone (port allowed; a path other than `/`, a query, a
  fragment or a user name refuses it; lowercased, trailing slash dropped),
  `apply()` replaces the scheme, host and port of a product link with it —
  path and query string kept — read at the store the link is built for, and
  normalizes the stored value again, so a bad `config:set` value rewrites
  nothing. Applied in `CatalogPayloadBuilder::productUrl()` (after the
  frontend emulation, every language's link) and `Rss\FeedBuilder` (item
  `<link>` / `<guid>`). Image links, the channel `<link>`, the abandoned-cart
  restore link and everything else are unchanged. Settings > Connection
  (not the initial setup): a "Using a separate storefront?" link under the
  account card opens a Storefront URL card (native `<details>`, drawn open
  when a value is saved). `WizardStepSaver::saveConnect()` checks the value
  before anything is saved and refuses it on the field
  (`field: storefront_url`, PRO-3562 banner + field mark); a post without
  the key leaves the value as is. `SaveStep` answers
  `storefrontUrlChanged`, and the tab's result then reads "Saved. Run the
  catalog import again under Intelligence > Historical imports, so that
  Campaign Intelligence gets the new product links." Five new phrases
  (EN + ET). The nightly catalog re-sync also carries the new links; the
  RSS feed shows them within its 15-minute cache. The field opening by
  itself waited for question 13 below: the first rule (frontend-area
  orders vs. API orders) would have opened it on every Luma store, because
  Luma's checkout places orders through REST (`webapi_rest`, as PRO-3580
  found). Unit: new `StorefrontUrlTest`, `Rss\FeedBuilderTest`;
  `CatalogPayloadBuilderTest` (+2, image links checked), `WizardStepSaverTest`
  (+5 incl. data sets), `SaveStepTest` (+1), `WizardDataTest` (+1).
  Verified with the real Settings templates and stub data in headless
  Chrome, en_US and et_EE: collapsed, saved value (open), an invalid value
  refused on the field, a changed value with the import message. Docs:
  USER_GUIDE "A separate storefront", HEADLESS_STOREFRONTS "Storefront URL",
  ARCHITECTURE, ADMIN_UI_TARGET_SPEC, CHANGELOG. Human acceptance still
  open: on the pilot store a recommendation and a back-in-stock link open
  the product page on the storefront — the storefront's `/<url_key>.html`
  redirect must keep the query string (PRO-3614 found it drops it).

- **PRO-3614 researched — the first pilot store runs a headless storefront
  (2026-10-02).** Magento is its back end only; shoppers buy on a separate
  storefront application, and the back-end host answers only `/graphql`,
  `/rest` and `/media` to the public (other paths 403). Findings in the new
  public `docs/HEADLESS_STOREFRONTS.md` (linked from README, INSTALLING,
  the USER_GUIDE FAQ; a pre-flight row in `PILOT_CHECKLIST.md`). Works
  as is: everything server-side (contact sync, import, reconcile,
  abandoned-cart detection, purchase marker, first order, catalog /
  customer / order sync, GDPR erase, admin). Needs storefront work:
  campaign-click capture, browse beacon to `smaily/relay`, attribution
  cookies carried to the place-order request, its own checkout newsletter
  checkbox, reachable `smaily/relay` and `smaily/rss/feed`. Not available
  headless: `{{abandoned_cart_url}}` restore, the welcome for API signups
  (Q8), **Checkout opt-in only** mode, My Account > Personalization,
  identity merge on login. **Product links:** `product_url` (catalog and
  RSS) is Magento's `getProductUrl()` — the store view's Base Link URL +
  URL key + suffix; images are the back end's public media URLs and work.
  The storefront's product page is `/p/<sku>/<url_key>.html`; it 301s
  Magento-style `/<url_key>.html` there but drops the query string (the
  engine's `smaily_rec` / `smaily_vt` / UTM params). No module setting
  produces the storefront pattern — design options are in "Questions /
  tasks for Erkki" item 12. Docs only, no code changed.

- **PRO-3625 — low-severity hardening after the rc1 review (2026-10-02).**
  (1) The RSS feed's cache key is built from the normalized limit, sort
  and order (`FeedBuilder::normalize()`, which `build()` uses too), so
  varied invalid values share one entry. (2) Array-typed request values
  no longer reach a string cast on the storefront: the RSS feed, the cart
  restore link (`id`, `ts`, `token`), the browse relay's event fields
  (`BrowseEventValidator`) and the checkout opt-in's `form_key` read a
  non-string as absent/invalid — Magento's error handler turned the
  "Array to string conversion" warning into a 500. (3) The Smaily
  password and the engine API key paths are declared sensitive
  (`etc/di.xml`, `TypePool`), so `app:config:dump` keeps their ciphertext
  out of `config.php`. The consent cache was already keyed by the opt-out
  record's HMAC (`ProfilingOptOuts::addressKey()`, PRO-3575) — confirmed,
  no change. (4) PRO-3573 + server error text: `Model\Logger\Logger`
  masks every address in the message and the context (any depth) through
  the new `Model\Logger\EmailMask`, which also finds URL-encoded (`%40`,
  `%2540`) and JSON-escaped (`\u0040`) addresses; an error text Smaily or
  the engine sends back reaches the file masked. The queue rows keep the
  text as received. (5) Packaging: `bin/build-release-zip.sh` is `git
  archive HEAD`, the exclusions live once as `export-ignore` in the new
  `.gitattributes` (composer dist installs honour them too), and
  `bin/verify-release-zip.sh` also refuses dot-files at any depth,
  `composer.lock` and archives. A local build therefore needs the change
  committed. composer.json requires `guzzlehttp/guzzle ^7.4`; the lock
  changed only its content-hash (`composer update --lock`, Guzzle stays
  7.15.5). (6) Every workflow `uses:` is pinned to the full commit SHA its
  major tag pointed to on 2026-10-02 (checkout v4.4.0, upload-artifact
  v4.6.2, setup-php 2.37.2 — dereferenced from the annotated tag), with the
  version as a comment. No Dependabot config exists, so a bump is manual. (7) Owner decision, WooCommerce
  parity: the admin Log shows contact data in full for debugging —
  `PayloadRedactor` hides only values under secret-looking keys, the
  "redacted" / "PII redacted" tags are gone and the Details footer reads
  "Passwords and API keys are never shown." (EN + ET). The server log file
  keeps masking addresses (item 4). PRO-3573 is resolved that way. (8)
  Owner decision, WooCommerce parity: the initial setup (page and menu
  entry) needs `Smaily_Connect::config`, as Settings does; the Dashboard
  stays on `::connect`. A role with only `::connect` on a store whose setup
  is unfinished is sent to the setup by the setup-first redirect and gets
  Magento's access-denied page there.

- **PRO-3644 done — copy consistency leftovers (2026-10-02).** (1) The
  failed-events banner on the Dashboard and the Log has a singular: "1
  event failed in the last 24 hours" / "Viimase 24 tunni jooksul
  ebaõnnestus 1 sündmus" (new phrase; the plural one stays). The
  Cron/HealthCheck admin notice is unchanged: it fires only at 25 or more
  failures (`FAILED_EVENTS_THRESHOLD`), so it is always plural — PHPStan
  flags a `=== 1` branch there as always false. (2) **Refresh workflows**
  shows a workflow list that cannot be loaded: beside Save Automations in
  Settings, beside the step's buttons (`#smaily-w-save-result`) in the
  setup; a stale message clears on each press. (3) Docs call the button
  **Test connection**, as the admin does (USER_GUIDE, CHANGELOG,
  ARCHITECTURE, ADMIN_UI_TARGET_SPEC, UPSTREAM_PROPOSAL); the unrendered
  `system.xml` button block uses the same phrase. (4) US spelling in
  English strings and their keys: organized, personalized, Canceled /
  Canceling… (the import pill and the canceled-import line; ET unchanged),
  the GDPR erase CLI's "anonymized"; public docs' prose likewise (colored,
  labeled, honored, gray, summarized, anonymized, favor). Code identifiers
  and stored values (`isCancelled`, `CANCELLED_RESPONSE`, `cancelled`
  status) are unchanged. Estonian uses *sünkroonimine* / *sünkrooni*
  everywhere (the more common form in our CSV — 12 noun forms against 4;
  the PRO-1748 canon strings used *sünkroniseerimine* but Woo mixes both):
  "Kontaktide sünkroonimine Smailysse", "Sünkroonimise seaded",
  "Sünkrooni kontaktid Smailysse", the contact-sync-off import line and
  the Log intro. Verified with the real templates and stub data in
  headless Chrome (banners at 1 and 12 in both languages; Refresh
  workflows with a failing list in Settings en_US and the setup et_EE).
  Gates: unit 639, phpcs 0 errors, phpstan `[OK]`, integration 135
  (`GdprEraseTest` reads the CLI's new spelling).

- **PRO-2456 — four copy fixes the owner approved (2026-10-02).**
  Settings > Automations, Campaign Intelligence not connected: the empty
  state reads "Connect Campaign Intelligence to set up these automations"
  (ET "Nende automaatikate seadistamiseks ühenda Campaign Intelligence"),
  not "Connect Smaily …". Settings > Contacts drops "— you can change it
  later in Settings > Contacts" from the lawful-basis note (new phrase
  without it; the initial setup keeps the long one). "Include Guest Order
  Emails" → "Include guest order emails" (checkbox and `system.xml`).
  Estonian: the stopped-import line names the button ("vajuta „Käivita
  uuesti“"), and the suppress-emails checkbox says what it does — Magento's
  newsletter subscription-confirmed and unsubscribed emails — "Lase
  Smailyl saata uudiskirjaga liitumise ja sellest loobumise kirjad"
  (was "tellimuse kinnituskirjad", which reads as order confirmations).
  Verified with the real templates and stub data in headless Chrome, both
  languages. No behaviour change.

- **PRO-2456 — initial setup and RSS builder follow the design pack
  (2026-10-02, owner decision: follow the pack).** The initial setup's
  step content sits directly on the grey pane as the pack's Setup Wizard
  frames draw it: the step's sections (the connection fields, the
  lawful-basis choice, the store-event triggers, the Campaign Intelligence
  connect, the overview links, the language routing and fallback account
  sections) have no card — no background, border, padding or corners; the
  import card and the finished-setup summary stay cards, as in the pack.
  The title is 6px above the intro, the intro reads 14px / 1.5 in the
  secondary text colour with 22px below it, and each section ends 26px
  above the next one and above the footer divider — also on a step
  shorter than the rail (the finished-setup summary), where the rail's
  spare height used to land between the content and the footer (36px). Settings tabs keep
  their cards (same partials, CSS scoped to `.smaily-wizard`). No
  behaviour change.
  The step rail shows the pack's 11px muted line under each step name
  (circle top-aligned with the name); the narrow strip across the top
  keeps the names only. Copy is the WooCommerce plugin's approved step
  descriptions, EN + ET from its `.pot` / `-et.po`: "Set up Smaily account
  connections", "Synchronization settings" (already in our catalogs),
  "Setting up triggers for automations", "Create a connection with Campaign
  Intelligence (optional)", "Summary and last check".
  Settings > RSS: the URL builder uses the pack's compact form — 12px
  secondary-colour labels, 13px controls with 6px 9px padding, selects
  with their own caret (6px 28px 6px 9px), 16px grid gaps — and the feed
  URL sits under the pack's small "Feed URL" label (ET "Voo-URL", the
  WooCommerce plugin's wording) as a 12.5px monospace chip with the pack's
  #d6d6d6 border and 9px 11px padding.
  No load jump left in the initial setup: `WizardData::getStartStep()`
  (Connect on a fresh install and on a finished setup, Contacts once the
  credentials are filled in) and `getReachedStep()` (5 after a finished
  setup) are read by the template, which draws that step open, the rail's
  active/done marks and the Back button before the script runs; the script
  starts from the same two values instead of deciding on its own. Before,
  a store with credentials but an unfinished setup was drawn on Connect and
  switched to Contacts when the script ran (content 532 → 1093 px tall at
  1440 px; 1248 → 1093 px in a two-language store); a finished setup's
  rail gained its done marks and Back disappeared only then. Now first
  paint and scripted state are the same in all four states (fresh,
  credentials only, two-language, finished). Unit: `WizardDataTest` (+3).
  Style coverage re-measured (`docs/audits/2026-10-02-ADMIN_STYLE_COVERAGE.md`
  "Re-measure (initial setup and RSS, 2026-10-02)"): 90.9 % → 94.9 %
  overall (strict 88.4 → 92.4 %); initial setup steps 1–5 91 / 90 / 87 /
  88 / 87 % → 99 / 96 / 92 / 98 / 98 %, Settings › RSS 82 → 99 %. Largest
  remaining gap: the trigger cards (M5–M8).

- **PRO-2456 evening walk-through — admin pages checked in en_US and
  et_EE at 1440, 1100 and 400 px (2026-10-02).** Every Smaily page and
  state, the states the store is not in drawn in the browser from the real
  templates with stub data (no request reached the store). Fixed: the
  initial setup draws step 1 open server-side, so the page no longer jumps
  by the step's height when the script runs (layout shift 0.12 → 0).
  Settings: the Campaign Intelligence automations' Daily cap and Test
  emails fields are wide enough for their placeholders (the inline widths
  were written for a 16 px rem; the admin's rem is 10 px), that card keeps
  a 24 px gap below Save Automations and its heading matches the
  store-events card heading (16 px / 700); in a multilingual store the
  Connection tab's default-account card, error banner and footer share the
  mode cards' 680 px measure (were 620 px under 680 px cards); radio
  buttons (the fallback account) use the accent colour like the
  checkboxes. Dashboard: below about 1110 px window width the recent-activity panel
  takes the full row and Quick links moves under it, so the table no
  longer scrolls sideways with its Updated column cut off (1100 px, both
  languages); the Failed tile's ATTENTION badge wraps under the label
  instead of sticking out of the tile (et_EE). Log > Details: the panel
  header (event id, type, status pill) no longer picks up Magento's
  `.modal-title span` rule — it was italic, every part 14 px and indented
  10 px; now 12 px mono id, 17 px type, 11 px pill, upright. No behaviour
  change.
  Style coverage re-measured on the morning's 2,656 rows (Open Sans
  accepted): 78.0 % morning → 85.0 % after the page frame → 90.9 % now
  (strict 88.4 %); largest remaining gaps: the step content card in the
  initial setup, the rail sub-labels (need copy), trigger-card details and
  the RSS builder controls — `docs/audits/2026-10-02-ADMIN_STYLE_COVERAGE.md`
  "Re-measure (evening 2026-10-02)".

- **PRO-3642 done — skipped and withdrawn rows are not deliveries for Send
  again, and the Dashboard reads them as the Log does (2026-10-02).**
  `EventQueue` has one private delivered rule, `deliveredCondition()`
  (sent, request on record, not `CANCELLED_RESPONSE`, empty `last_error`
  — the Log's withdrawn/skipped markers), used by both
  `hasDeliveredAutomation()` and `laterDeliveredOfSameTrigger()` (computed
  in SQL as a `delivered` column). Before, a later skipped row (stored
  `sent`) counted as "a later message already reached this contact" and
  hid Send again; and a skip that kept an earlier attempt's request counted
  as a delivered abandoned-cart reminder. The Log's status derivation is
  now `Collection::smailyStatusExpression()`, read by the grid's UNION and
  by `DashboardStats::recentActivity()`; `DashboardData::getRecentActivity()`
  adds `status_label` (the Log's `QueueStatusOptions`) and `status_pill`
  (`StatusPill`), and the template draws those — its own label map and
  pill map are gone. No new phrases. Tests RED first: integration
  `EventQueueTest` (+1), `ResendTest` (+1; the existing superseded fixture
  now carries the request a real delivery stores), `DashboardStatsTest`
  (+1); unit `DashboardDataTest` (+1). CHANGELOG near-duplicate bullets
  (durable queues, Details, historical import) merged into one each.
  Gates: unit 638, phpcs 0 errors in the changed files, phpstan `[OK]`,
  integration 135.

- **PRO-3634 done — rows closed without sending read Skipped and are not
  counted as delivered (2026-10-02).** Three handler paths answered `true`
  (stored as a plain `sent`) without a request; they now answer
  `Queue\Skipped` with a reason, so `markSkipped()` keeps it in
  `last_error` and the Log reads the row as Skipped:
  `AutomationHandler` (no Smaily workflow mapped),
  `ProfilingConsentHandler` (a choice the shopper has since replaced) and
  `IdentityMergeHandler` (shopper opted out of profiling). Kept as
  delivered: `ProfilingConsentHandler`'s §10 404 (the engine answered).
  The reasons are in `FailureMessage::TRANSLATED`, so they read in the
  admin's language. `DashboardStats::contactSyncsDelivered()` counts a
  `sent` row only with an empty `last_error` and no
  `CANCELLED_RESPONSE` (the Log's own skip/withdraw rule);
  `catalogItemsDelivered()` is unchanged — the ingest queue marks `sent`
  only on the engine's answer and never skips or withdraws. New phrases
  (EN → ET, own): "Skipped: no Smaily workflow is mapped to this
  automation trigger. Nothing was sent." → "Vahele jäetud: selle
  automaatika päästikuga pole seotud ühtegi Smaily töövoogu. Midagi ei
  saadetud."; "Skipped: the shopper has since changed their
  personalization preference, and the newer preference is sent in its own
  row. Nothing was sent." → "Vahele jäetud: ostja on vahepeal oma
  personaliseerimise eelistust muutnud ja uuem eelistus saadetakse eraldi
  real. Midagi ei saadetud."; "Skipped: the shopper opted out of
  personalized recommendations, so their browsing is not linked to their
  address. Nothing was sent." → "Vahele jäetud: ostja loobus
  personaalsetest soovitustest, seega tema sirvimist ei seota tema e-posti
  aadressiga. Midagi ei saadetud.". Tests RED first: unit handler tests
  (+1, 3 changed from `true` to `Skipped` — the behaviour changed),
  `FailureMessageTest` (+3); integration `DashboardStatsTest` (+2; the
  ingest-queue case passed before the change, as expected) and
  `FlushEventQueueTest` (+1, real AutomationHandler through the flusher,
  read back as Skipped). Gates: unit 637, phpcs 0 errors in the changed
  files (7 pre-existing errors in `panel/connection.phtml` from PRO-3566),
  phpstan `[OK]`, integration 132.

- **PRO-3641 done — admin layout leftovers (2026-10-02).** Choice cards
  (`.smaily-choice`, width 100 %) are `box-sizing: border-box`, so their
  padding and border stay inside the card on every page and width — the
  admin's own reset already made them border-box; without it they stuck
  out 34 px (measured in headless Chrome, Settings and the initial setup,
  1400 / 700 / 560 / 420 px). Each Settings tab footer now spans its tab's
  card: Automations and Intelligence 666 px, Contacts 680 px (it was
  620 px under a 680 px card too); Connection and RSS stay at 620 px.
  The workflow list's error on Settings > Automations shows beside **Save
  Automations** (`#smaily-w-automations-result`) instead of in the
  page-wide footer, which every tab had hidden since PRO-3567; that footer
  (`#smaily-settings-global-footer`, its Save, its CSS and the JS that
  toggled it) is gone. A failed **Test connection** follows the failed-save
  model of PRO-3562: banner above the form, *Connection failed.* beside the
  button, the field at fault marked with the message under it — on Settings
  > Connection, initial setup step 1 and in each per-language account block
  (its fields as `accounts.<language>.<field>`; "Enter the password for this
  account first." marks that block's password). `TestSmaily` now answers
  `errors: [{field, message}]` like the save endpoint: `subdomain` for a
  refused subdomain, and each posted field that is empty when there is
  nothing to test with; a refusal by Smaily or no answer names no field
  (banner only). No new phrases. Unit-tested (`TestSmailyTest`); verified
  by rendering the real templates with stubs in headless Chrome (Settings
  en_US, initial setup et_EE, per-language accounts; refused subdomain,
  empty fields, refused credentials, success; the Automations tab with a
  failing workflow list).

- **PRO-3569 done — admin copy leftovers (2026-10-02).** The Contacts
  lawful-basis paragraph points to where the choice is changed now
  ("… you can change it later in Settings > Contacts." / "… hiljem saab
  seda muuta jaotises Seaded > Kontaktid."), not to the hidden
  Configuration section. One spelling of *synchronization*: the house
  style is US English (Magento's `en_US`, and most of our strings), so the
  two "Contact synchronisation …" phrases became "Contact synchronization
  …" (step 2's title and the import note; ET unchanged; README and the
  user guide's section heading follow). The Automations tab's button is
  **Save Automations**, like **Save Connection** / **Save Contacts** (the
  object is the tab's name; ET unchanged). The Dashboard verdict has a
  real singular and plural ("%1 delivery failed …" / "%1 deliveries
  failed …"; ET "… ebaõnnestus %1 saadetis." / "… %1 saadetist.")
  instead of "delivery(ies)"; the unused "%1 failed delivery(ies) in the
  last 24 hours." is gone from both packs. Estonian page titles are
  capitalised like the menu ("Smaily Connect — Töölaud / Algseadistus /
  Seaded / Logi"). The CHANGELOG names the Feed URL Builder's current
  place (Settings > RSS) and the pilot checklist puts **Finish** on the
  Intelligence step, **Go to Dashboard** on Overview. Verified by
  rendering the real templates with stubs (Dashboard with 1 and 12
  failures, Settings, initial setup; en_US and et_EE, no untranslated
  phrase).

- **PRO-3566 done — per-language account blocks as the pack draws them
  (2026-10-02).** In "Per-language Smaily accounts" mode
  (`panel/connection.phtml`, initial setup step 1 and Settings >
  Connection) the account blocks sit in the pack's dashed reactive region
  (`.smaily-ml-region`: `--s-surface-2`, 1 px dashed #d6c3cb — the pack's
  value, no token — 6 px radius, headed by the mode name as an accent
  kicker), in a two-column grid that stacks below ~540 px. Each block is a
  white 5 px card with a language chip (code), the heading "Smaily account
  for <language name>", compact fields (12 px labels, 13 px inputs), and a
  footer with Test connection and its own status, rendered server-side:
  *Connected* when Smaily accepted the credentials saved for that
  language's store view at the last check, otherwise *Not connected*; Test
  connection replaces it with the answer for what is typed.
  `WizardData::getMultilingualAccounts()` now adds `languageName`
  (`\Locale::getDisplayLanguage` in the admin's interface locale via the
  new `Magento\Framework\Locale\ResolverInterface` dependency; the code in
  capitals when ICU has no name) and `verified`
  (`VerifiedCredentials::isVerified($storeId)`). On Settings the mode cards
  and the fallback card share the region's 680 px measure, as in the initial
  setup. No new phrases ("Per-language Smaily accounts", "Smaily account for
  %1", "Connected", "Not connected" are shipped). Unit-tested (name per admin
  locale, fallback to the code, status per store view); verified by
  rendering the real templates with stub data for two languages in headless
  Chrome (Settings en_US / et_EE, initial setup at 1400 / 700 / 560 px; Test
  connection, the field error on a block, switching mode hides the region).
  Fidelity audit row 20 marked fixed. The block attributes print the
  language code escaped once (`$code`) under `/* @noEscape */`, as
  `panel/automations.phtml` does, so phpcs ends with 0 errors again.

- **PRO-3568 done — the Dashboard's degraded and healthy states carry the
  pack's banner and buttons (2026-10-02).** With failed deliveries in the
  last 24 hours (and setup complete, Smaily connected) `dashboard/index.phtml`
  puts a warning banner above the verdict — "%1 events failed in the last 24
  hours" with a **Review failures** action, both phrases the Log's banner
  already ships — and the verdict's **Review failures** button is danger red
  (`.smaily-verdict-action--danger`: `--s-bar-error` fill, `--s-danger`
  border). The healthy verdict gets a secondary **View full log** (our term
  for the pack's "Open event log"; existing phrase). From the style coverage
  audit (H16, H17, H20–H22, H29): the verdict at 6 px radius, 18/20 padding,
  no shadow, its dot ringed in the level colour at 13 % (`color-mix`); the
  connection cards flat at 13/15 padding and 5 px radius with the 14 px
  ringed dot in its own column beside a 13 px / 600 label, the sub-line and
  the pill; the quick-link arrow grey (`--s-border-2`) and right-aligned in
  its row. No new phrases. Verified by rendering the real template with stubs
  in headless Chrome (ok, degraded with failures, engine unreachable, not
  connected, setup incomplete; en_US and et_EE). Fidelity audit row 22 marked
  fixed. Not taken: the pack's banners in the disconnected and incomplete
  states and the danger "Open Connection settings" button (not in this
  Story).

- **PRO-3567 done — Settings > Intelligence has the tab title, the
  description and a Save only when there is something to save
  (2026-10-02).** `settings/index.phtml` heads the tab like its siblings:
  "Campaign Intelligence" (existing phrase; the in-card "Campaign
  Intelligence (optional)" heading is gone from Settings, the setup step
  keeps it as its step title) and a one-line description. The page-wide
  Save no longer shows there: the tab has its own footer (Save +
  status, `#smaily-w-intelligence-save`, step `intelligence` = browse
  tracking), hidden until `engineConnected()` runs — at load with a
  connected engine, or right after Connect. Every Settings tab now owns its
  footer, so the page-wide footer is never shown. New phrase: "Your store’s
  connection to Campaign Intelligence, storefront browse tracking and
  historical imports." → "Sinu poe ühendus Campaign Intelligence'iga, poe
  sirvimise jälgimine ja ajaloolised impordid." (own; WooCommerce has no
  tab descriptions — the terms are our shipped ones: "Luba poe sirvimise
  jälgimine", "Ajaloolised impordid"). Verified by rendering the real
  templates with stubs in headless Chrome (engine not connected: no Save;
  Connect → Save appears; Save posts browse tracking and says "Saved.";
  en_US and et_EE). Fidelity audit row 21 marked fixed.

- **PRO-3564 done — the import cards show the pack's six states
  (2026-10-02).** The contact import (initial setup step 2, Settings >
  Contacts) and the three Settings > Intelligence imports (now one card
  each: Catalog / Customers / Orders, replacing the stacked button rows) are
  the pack's Backfill card: white card (18/20 padding, 6 px radius,
  `--shadow-1`), a status pill in the header (Pending / Running / Done /
  Stopped / Cancelled), the bar with "X of Y" and the percentage under it,
  the outcome line with its icon and colour (the shipped outcome copy), the
  failures link under it, and the controls under a divider with the pack's
  compact buttons. Idle: no pill, no bar, **Start import** primary; queued
  and running: only **Cancel import** (status "Importing…" while running);
  ended: **Run again** — secondary after Done, primary after Stopped or
  Cancelled (the pack's Resume / Start over are not built: a run always
  starts fresh, target spec §2.5 d). `panels-js.phtml` drives every card
  through one `backfillUi()` / `renderBackfill()`. New phrases: "Catalog" →
  "Kataloog" (own, from "Impordi kataloog"), "Customers" → "Kliendid" and
  "Orders" → "Tellimused" (Woo `smaily-connect-et.po`), "Running" →
  "Töötab" (Woo "Running…" → "Töötab…"), "Done" → "Valmis" (own "Done, %1
  of %2 synced." → "Valmis, …"), "Stopped" → "Peatunud" (own), "Cancelled"
  → "Katkestatud" (own "Cancelled — …" → "Katkestatud — …"), "Run again" →
  "Käivita uuesti" (Woo "Re-run when ready." → "Käivita uuesti, kui
  valmis."), "Importing…" → "Impordin…" (Woo, verbatim). Dropped as unused:
  "Import catalog / customers / orders", "Importing… %1 / %2". Verified by
  rendering the real templates with stubs in headless Chrome (all six
  states plus queued, en_US and et_EE, Start → queued → Cancel → cancelled,
  the sync-off case). Fidelity audit row 17 marked fixed.

- **PRO-3563 done — a finished initial setup reopens on a connection
  summary (2026-10-02).** The pack's completed-revisit frame: with
  `setupCompleted`, `wizard/index.phtml` renders step 1 as a read-only
  summary card (`.smaily-setup-summary`: Subdomain as
  `<sub>.sendsmaily.net`, API username, Status pill from
  `isSmailyVerified()`), a Completed pill beside the "Step 1 of 5" kicker
  and **Edit credentials** in the footer — server-side, so the form never
  flashes. Edit credentials hides the summary and shows the connection form
  (it stays for the rest of the visit); Continue from the summary moves to
  step 2 without saving (no needless credential check); the rail keeps all
  five steps clickable, the Overview included. Before, a revisit opened on
  the Overview step. Not taken from the pack: the resting "Saved" status
  beside Edit credentials (the Completed pill already says it). New
  phrases: "Completed" → "Lõpetatud" (own; Woo uses "lõpetatud" for
  "complete", e.g. "Initial import complete"), "Edit credentials" →
  "Muuda kasutajaandmeid" (Woo `smaily-connect-et.po`, verbatim). Verified
  by rendering the real templates with stubs in headless Chrome (en_US and
  et_EE; completed connected / not connected, an unfinished setup
  unchanged). Fidelity audit row 16 marked fixed.

- **PRO-3562 done — a failed save shows a banner and marks the field that
  caused it (2026-10-02).** The pack's two-layer error model on the initial
  setup and every Settings tab: `panels-js.phtml` `showFormErrors()` puts an
  error banner (`.smaily-form-banner`, the shared `.smaily-banner--error`)
  under the step heading / tab description, marks each field the error
  names (`.is-invalid`: `--s-bar-error` border, #fef8f7 fill, 2 px ring —
  the pack's `inputError`; the message under the first field it names,
  `.smaily-field-error`) and the status beside the button says "Saving
  failed."; editing a marked field removes its mark, and a banner that only
  summarised field errors goes with the last mark; the next save, a
  successful one or a step change clears the rest. Field keys: the save
  endpoint already answered `{field, message}`; `WizardStepSaver` now names
  the empty field (`subdomain` and/or `username`, one entry each) and a
  per-language account's refused subdomain as `accounts.<language>.subdomain`
  (checked before the top-level one, which repeats the fallback account's in
  mode A); the checks before the save (empty credentials, a per-language
  account's empty fields or password) name their fields the same way. An
  error without a field (e.g. `mappings`) shows the banner only. No new
  phrases. Verified by rendering the real templates with stubs in headless
  Chrome (Settings > Connection, Automations, mode-A account blocks; the
  initial setup in et_EE). Fidelity audit row 15 marked fixed.

- **PRO-3561 done — every initial-setup step shows its position and title
  as the pack draws them (2026-10-02).** `wizard/index.phtml` renders a
  "Step N of 5" kicker (11 px / 700 / uppercase, `--s-text-3`) and the step
  title as a 22 px / 700 heading above each step's card (`.smaily-step-head`
  in `smaily-admin.css`). The titles are the panels' existing ones
  (Connect your Smaily account / Contact synchronisation to Smaily / Map
  store events to Smaily automations / Campaign Intelligence (optional) /
  You are all set!); the panels no longer repeat them inside the card in
  the initial setup — Settings is unchanged (the automations panel gets
  `context=wizard` in the wizard layout). New phrase: "Step %1 of %2" →
  "%1. samm %2-st" (Woo `smaily-connect-et.po` "Step 1 of 6" → "1. samm
  6-st"). Verified by rendering the real templates with stubs in headless
  Chrome, en_US and et_EE: kicker and title on all five steps, no in-card
  duplicate, Settings headings unchanged. Fidelity audit row 14 marked
  fixed.

- **PRO-3570 done — the Connection status follows each check without a
  reload (2026-10-02).** Settings > Connection renders the status pill and
  the account from the server-known state (`WizardData::isSmailyVerified()`,
  `getSavedSubdomain()`), so the page no longer shows "Not connected" for a
  moment on every load. A connection save answers `verified` (the result of
  the check the save itself made — `WizardStepSaver::isConnectionAccepted()`,
  read from the check, not from the configuration cache, which can still
  hold the old credentials) and `accountName`; Test connection answers
  `checked` (Smaily was asked: accepted, refused, package or unreachable) and
  the pill follows only a test that asked — empty fields and a refused
  subdomain leave it as it was. User Guide: what a save with wrong
  credentials shows (*Saved.* + *Not connected*; the setup moves on and the
  Overview says syncing starts once Smaily accepts them). No new phrases.
  Verified by rendering the real templates with stubs in headless Chrome:
  before the change the pill read "Not connected" without JavaScript for a
  verified store and kept "Connected" after a refused test and a refused
  save; after it, the first paint is right and both answers show at once.

- **PRO-3565 done — Log status pills and the Details panel
  (2026-10-02).** Step 1: a row the queue closed without sending
  (PRO-3619 `markSkipped`: stored `sent` with the reason in `last_error`)
  reads as the derived status `skipped` (`Collection::STATUS_SKIPPED`) in
  the grid's UNION and in `QueueRowLoader`, by the same rule — withdrawn
  wins over skipped, because a withdrawn row may keep an earlier attempt's
  error. "Skipped" is in the status filter; Details says "Skipped —
  nothing was sent, …" instead of "Delivered — no retries needed.", heads
  the reason "Reason" (not "Last error") and drops the failure class for
  it. The skip reason is read in the admin's language
  (`FailureMessage::TRANSLATED`). Tests RED first: integration
  `Log/LogStatusTest` (+3), unit `FailureMessageTest` (+1).
  Step 2 done: the grid's Status column draws each status as the pack's
  pill (`Model\Log\StatusPill` maps status → `.smaily-pill--{variant}`:
  pending/sending amber, sent green, failed red, withdrawn/skipped grey;
  `Ui\Component\LogStatusColumn` puts it on each row as `status_pill`; JS
  `grid/columns/status-pill` = the stock select column with the template
  `grid/cells/status-pill.html`, so filter and sorting are unchanged).
  Tests RED first: unit `StatusPillTest` (+8), `LogStatusColumnTest` (+1).
  Step 3: Details is Magento's slide modal narrowed to the pack's 452 px
  panel (the closest Magento-native equivalent: Magento's scrim, focus on
  the close button, Escape, focus back to the row link). `log-actions.js`
  moves the loaded `[data-smaily-details-head]` (Event #id, type, status
  pill) into the modal title (the dialog's label) and pins
  `[data-smaily-details-foot]` below the scrolling body. Attempt history
  comes from `Model\Log\AttemptHistory` — built from the row's attempts,
  timestamps and latest outcome only (no new table: the row keeps no
  per-attempt record, so earlier failures have no time; the panel says so).
  Footer: **Send again** (the pack's "Retry now"; terminology wins) only when
  `Details` gets `resend_url`, i.e. `ResendGuard` cleared the row — same rule
  as the grid; it confirms first and disables itself, then posts to the
  existing `log/resend` route. **Copy payload** copies the text of the
  redacted `[data-smaily-payload]` block (clipboard API, execCommand
  fallback) with an InlineStatus. Dark code blocks, section heads, summary
  grid and timeline per the pack (Log rules only in `smaily-admin.css`).
  Tests RED first: unit `AttemptHistoryTest` (+8), `DetailsTest` (+4, with a
  `RawFactory` stub). Verified in headless Chrome from a scratch harness
  (the real template rendered with stubbed rows, the real column JS with
  Magento's modal and select stubbed): 45 checks in en_US + et_EE — pill
  colours, 452 px / right-docked, header + label, pinned footer, Send again
  only on a cleared row and confirmation first, clipboard = shown redacted
  text (no raw address or secret), Escape and Enter-on-close close the panel
  with focus returned, 400 px viewport fits, no console errors. Not yet
  seen on the real admin (the sandbox was not used).

- **PRO-3628 — PRO-3603 findings fixed (2026-10-02).** Test connection
  with empty fields asks "Please fill in the subdomain, username and
  password." (EN + ET) instead of "Smaily API credentials are not
  configured (store scope: 1)": `TestSmaily` keeps the saved password only
  when the subdomain and username are filled in (or when a per-language
  account block posts its store view alone) and saved credentials exist.
  The "ready to set up" admin notice is marked read when the initial
  setup is finished (`WizardStepSaver` finish step →
  `Model\Adminhtml\SetupNotice::markRead()`, found by its user-guide URL)
  and deleted on uninstall by both paths (`Setup\Uninstall` and the
  `RemoveSettingsOnUninstall` revert); without Magento_AdminNotification
  (no `adminnotification_inbox` table) both do nothing. Copy: the
  Overview step says "now syncing" only when Smaily accepted the saved
  credentials (`boot.verified` on load, then the `verified` field that
  `SaveStep` now answers for the finish step); otherwise "Syncing starts
  once Smaily accepts the credentials — check them in Settings >
  Connection …" (EN + ET). The setup notice says the earlier settings
  were migrated only when `MigrateLegacyConfig` moved 2.8.x settings in
  the same setup run (`Model\Migration\MigrationOutcome`, a shared
  in-process instance — nothing stored). Fixes PRO-3603 findings 1, 5
  and 6.

- **Session handoff 2026-10-05** (superseded by the header's 2026-10-07 opener: the pilot is postponed until the store has upgraded).
  - **Pilot 09.10 (the plan until 2026-10-07):** the pilot store installs 3.0.0-rc7 (released 2026-10-05, the newest GitHub pre-release; its clean install by the guide passed, PRO-3769), following PILOT_CHECKLIST.md. Since 2026-10-07: the pilot installs rc8, after the store's Magento/PHP upgrade.
  - Separate-storefront order: the Storefront URL on the initial setup's Connect step (PRO-3802; needs rc8 — on rc7 it is Settings > Connection after the setup), then connect Campaign Intelligence — connecting starts the catalog import (**Hold back the import** if needed).
  - Human acceptance left: PRO-2474 (pilot installed and connected), PRO-3660 (a recommendation and a back-in-stock link open on the storefront), the pilot-day checks in the checklist.
  - Pilot decisions made 2026-10-05 (PRO-3600): consent contact mode, connect Campaign Intelligence on 09.10, 25% holdout before activation (engine side), 12-week reading window, Estonian emails; Personalization stays hidden while refused.
  - **Erkki:** HC Pro (PRO-3661 2.x settings, PRO-3663 Mageplaza checks, PRO-3665 other abandoned-cart senders, spike PRO-3662); Estonian proofreading of the strings added 2026-10-02..05.
  - **On v3 after rc7, unreleased** (CHANGELOG "Changes since 3.0.0-rc7"; into rc8, cut before the pilot): PRO-3768 (CSV-import deletes reach the engine), PRO-1958, PRO-3747, PRO-3753 (a 203 group is sent one by one), PRO-3767, PRO-3780, PRO-1957, PRO-1955, the simplification passes, PRO-3798 (only a refunded credit memo marks lines returned), PRO-3802 (the setup's Storefront URL field), PRO-3854's contract sync (1.12.0).
  - **Open queue:** PRO-3854 nightly catalog list (in progress; rc8 waits for it), PRO-3913, PRO-3911, PRO-3912, PRO-3790 (storefront recommendations, after the pilot), PRO-3746 (after the pilot: one connect step and one import-start step), PRO-3774 (remove a product from the engine by SKU; Replace-import note), PRO-2461, PRO-1970, PRO-1964 (low), PRO-1464/PRO-1463 multi-website phases (one-way door; wait on the engine).
  - **Cross-repo asks open:** WooCommerce PRO-3743 (catalog import on connect), PRO-3750 (code 203, and contact sync ignores it), PRO-3796 (over_10_products); Shopify PRO-3744, PRO-3751, PRO-3797. Engine PRO-3740 is done (contract 1.8.3 synced).
  - Sandbox: remove finished agent worktrees under `.claude/worktrees` before any sandbox `setup:di:compile`.

- **PRO-3603 done — final clean-install pass of the release ZIP
  (2026-10-02).** On fresh sandbox volumes, without the working-tree
  mount: `bin/verify-release-zip.sh` (VERIFY OK, 352 entries, 3.0.0-rc1)
  and `sha256sum -c` OK; installed from the ZIP into a real `app/code` in
  production mode by `docs/INSTALLING.md` steps 1–5 — `setup:upgrade`,
  `setup:di:compile`, `setup:static-content:deploy en_US et_EE`,
  `cache:flush` all clean; module enabled; six `smaily_*` tables; the
  `smaily_connect` cron group ran with `success`. Admin walked in en_US
  and et_EE (Initial setup, Dashboard, Settings — all five tabs, Log and
  its Details panel), no console errors. Placeholder credentials:
  non-plain subdomain refused locally, an unknown subdomain gives "Could
  not reach Smaily … HTTP 404", the http engine setup URL is refused,
  Dashboard and Settings say Not connected. Synthetic data only: a
  registration with the newsletter box, a guest newsletter signup and one
  order queued `contact.sync` rows that closed as failed on the first
  attempt (permanent 404, no retry), with the email masked in Details; the
  order and a product save queued no Campaign Intelligence row (engine not
  connected) and raised no exception. Uninstall by the guide (disable,
  `module:uninstall --non-composer`, delete the directory): settings
  `smaily_connect/*` 20 → 0, `smaily/*` 1 → 0, flags `smaily_connect_*`
  2 → 0 (the legacy row and the flags were synthetic — a store without a
  connection writes none), tables 6 → 0, control rows untouched. Then
  back to the bind-mounted dev sandbox in default mode (`module:enable`
  needed — `config.php` keeps the module at 0 after an uninstall).
  INSTALLING and TESTING now say what Magento keeps after an uninstall
  and how the uninstall is checked. Findings, all low, not fixed here
  (verification-only task): (1) the "ready to set up" admin notice stays
  unread after the setup is completed and after a full uninstall
  (`Setup/Uninstall.php` does not remove it); (2) the Log's Last error
  text is translated in the store locale at cron time
  (`Model/Client/SmailyClient.php:203`), so an en_US admin reads Estonian
  on an et_EE store; (3) the Dashboard "Queued today" tile counts every
  row created today, but its caption says "events waiting to send"
  (`Model/Adminhtml/DashboardStats.php:57`); (4) a registration with the
  newsletter box queues two identical `contact.sync` rows (a guest signup
  queues one); (5) Test connection with all fields empty on a fresh
  install says "Smaily API credentials are not configured (store scope:
  1)" (`Controller/Adminhtml/Api/TestSmaily.php`); (6) copy: the Overview
  step says "now syncing" after a failed connection test, and the setup
  notice says earlier settings were migrated on a fresh install. Needs
  real credentials (pilot day): a Sent contact, workflow lists,
  automations, the engine exchange, catalog/customer/order ingest.

- **PRO-3628 — PRO-3603 findings 2–4 (2026-10-02).** (2) A Smaily
  delivery error is stored in English: `SmailyClientException` built from
  a Phrase keeps the source text (`getSourceMessage()`) beside the
  translated message; `RetryPolicy` stores that, the batch log lines
  record it, and `FailureMessage` translates it in the admin's language
  in the Log grid and Details (the messages it knows are listed in
  `FailureMessage::TRANSLATED`). A row stored in Estonian before the fix
  is shown as stored. Not covered: Campaign Intelligence errors
  (`Model/Engine/Client.php` translates at throw time the same way).
  (3) The Dashboard "Queued today" tile counts the rows queued today
  (UTC) on both queues that are still `pending` (first attempt or retry),
  so it matches its caption "events waiting to send". The target spec
  names the tile "Queued today" without defining it as "created today",
  and the pilot checklist reads the caption as the cron-health signal, so
  the count changed, not the caption. (4) Cause of the two `contact.sync`
  rows per registration: Magento's `createAccount()` saves the customer
  twice — the account, then `changeResetPasswordLinkToken()` saves the
  reset token through the repository — and the newsletter plugin
  subscribes between the two saves. `SubscriberSaveAfter` queues the
  subscription; the token save fires `customer_save_after_data_object`
  again with the subscriber now subscribed, and `CustomerSaveAfter`
  queued the same contact. `SyncDispatcher::dispatchContactSync()` now
  skips a sync that adds nothing (every field already in the last sync
  it queued for that store and address, same value; memory of the last
  100 addresses per process). Consent: one row. All customers: two (the
  account before the subscription, then the subscription with
  `is_unsubscribed=0`) — was three. The event_uuid idempotency was not
  used: it would also drop a subscribe → unsubscribe → subscribe
  sequence. The purchase marker path does not take part.

- **Automation docs corrected — a trigger for an unknown contact creates
  nothing (2026-10-02).** The `AutomationHandler` docblock, the user
  guide's automation section and the CHANGELOG's PRO-3577 bullet said a
  trigger enrols a contact Smaily has never seen. Owner's fact
  (2026-10-02): without force opt-in, a trigger for an address Smaily does
  not have creates no contact and sends nothing. All three now say so;
  ARCHITECTURE carried no such claim. Comment and docs only.

- **PRO-3575 (consent cache key) done — keyed hash (2026-10-02).** The
  second half of the opt-out-record hardening. `ProfilingConsent` keeps
  both of its cache entries — the preference (`smaily_profiling_…`) and
  whether Smaily has the contact (`smaily_profiling_contact_…`) — under
  `ProfilingOptOuts::addressKey()`, the HMAC with the newest crypt key the
  opt-out record writes under (the record's own `keys()`, now exposed; no
  second HMAC). Entries under the old `sha1(address)` keys are simply not
  found and expire within a day: no migration. Tests RED first:
  `ProfilingConsentTest` (cache keys carry the keyed hash, no plain hash),
  `ProfilingOptOutsTest` (the address key is the keyed form the record is
  written under, and differs per crypt key). Gates: unit 555, phpcs 0
  errors, phpstan `[OK]`, integration 120.

- **PRO-3619 remainder done — the purchase marker goes only to a contact
  Smaily has (2026-10-02).** Owner decision (Erkki 2026-10-02, question
  11): yes. `ContactSyncHandler` reads the contact (`GET contact.php`)
  before it posts a row that carries `abandoned_cart_purchased_at` — once
  per marker row, in the cron, never at checkout; other contact syncs are
  posted without a read. Smaily code 206 (no such contact): the handler
  answers the new `Queue\Skipped` and the flusher calls
  `EventQueue::markSkipped()`, which closes the row (`sent`, no
  `sent_payload`, nothing to retry) with the reason in `last_error`, so the
  Log row reads "Skipped: Smaily does not have this contact, …". A failed
  read is returned as the exception: the RetryPolicy classifies it and
  nothing is posted. `EventHandlerInterface` documents the new result. No
  schema change. Woo has the same gap (not changed here). Tests RED first:
  unit `FlushEventQueueTest` (+1), integration `FlushEventQueueTest` (+4:
  unknown address skipped with one GET and no POST, known contact GET then
  POST, failed read retried, a plain contact sync posts with no read).
  Gates: unit 553, phpcs 0 errors, phpstan `[OK]`, integration 120.

- **PRO-3619 done — a profiling choice or a purchase marker never creates
  a Smaily contact (2026-10-02).** Smaily creates a new contact sent
  without `is_unsubscribed` as subscribed, keeps an existing contact's
  status when the field is omitted, and an automation trigger for an
  unknown contact without force opt-in creates nothing (owner, 2026-10-02).
  (1) `ProfilingConsent::setAllowed()` writes `smaily_rec_profiling` only
  to a contact Smaily has. Chosen over sending `is_unsubscribed=1` for a
  shopper the store does not hold as subscribed, because that would
  unsubscribe an existing Smaily subscriber the store does not hold as one
  (any customer synced under "All customers", or a signup made outside
  the store). Whether Smaily has the contact comes from the read
  `isAllowed()` already makes for the My Account page: `readContact()`
  keeps the answer for a day under its own cache key; only when it is gone
  does the save read once (never per page view); when Smaily cannot be
  read, nothing is written. The store's record and the engine queue are
  unchanged (PRO-3578/3594), and the existing write-back in `isAllowed()`
  carries an opt-out to the contact once Smaily has one. (2)
  `SyncDispatcher::dispatchCartPurchase()` still withdraws a pending
  reminder, then sends the marker only when
  `EventQueue::hasDeliveredAutomation()` finds an abandoned-cart reminder
  to the contact that went out: status sent, a request on record
  (`sent_payload`), not withdrawn — a skip that POSTed nothing, a failed
  row and a waiting row do not count (WooCommerce parity, Woo PRO-1723).
  The tracker's `mailed` status means only "queued", so the queue decides.
  Bounded by the janitor's 30-day retention of sent rows. A reminder
  delivered to an address Smaily does not have creates nothing, so the
  marker could still create that contact — closed by the remainder above
  (a contact read in the queue). Tests RED first:
  `ProfilingConsentTest` (+5), `SyncDispatcherTest` (+1), integration
  `EventQueueTest` (+1). Gates: unit 496, phpcs 0 errors, phpstan `[OK]`,
  integration 113.

- **PRO-3575 (opt-out record) done — keyed hash (2026-10-02).**
  `ProfilingOptOuts` keys an entry by `hash_hmac('sha256',
  'smaily-profiling-optout|' . address, newest crypt key)` (read from
  `DeploymentConfig`, as `RestoreTokenManager` does — no new secret).
  A read tries that key, then each earlier crypt key (a rotation keeps the
  old line in `env.php`), then the plain `sha1(address)` of the record's
  first form; `record()` and `forget()` remove every form of the address
  and `record()` writes the newest, so no opt-out is lost. Callers
  unchanged (`ProfilingConsent` not touched). The consent cache keys
  follow in the "consent cache key" entry above. New `ProfilingOptOutsTest` cases (RED
  first): the key is not a plain hash and another crypt key cannot read
  it; a write moves a plain-hash entry and leaves others; forget removes a
  plain-hash entry; an opt-out survives a key rotation. Gates: unit 505,
  phpcs 0 errors, phpstan `[OK]`, integration 115.

- **PRO-3575 (cart restore link) done — the link expires after 30 days
  (2026-10-02).** `RestoreTokenManager::linkParams()` gives the link
  `id`, `ts` (UTC Unix time the payload is built = the reminder is created,
  the same cron pass that writes `mail_sent_at`) and an HMAC over
  `quoteId|ts`; `check()` answers valid / expired / invalid. Past 30 days
  `Controller/Cart/Restore` adds the notice "This cart link has expired."
  (EN + ET "See ostukorvi link on aegunud.") and redirects to the cart page
  without loading the quote; an invalid link redirects there silently, as
  before. Decision on links sent before this change (no `ts`, old
  signature): accepted for 30 days after the tracker row's `mail_sent_at`
  (`StateManager::mailSentAt()`), expired without one — so no link under
  30 days old breaks; only pre-release installs ever sent such links, and
  the branch can go 30 days after rc1. New `RestoreTokenManagerTest`,
  `RestoreTest` (unit, RED first) and a `StateManagerTest` case
  (integration). Gates: unit 501, phpcs 0 errors, phpstan `[OK]`,
  integration 115. Not run: `setup:di:compile` (new
  constructor dependencies) — sandbox not used.

- **PRO-3575 (2.8.x settings) done — the upgrade deletes the old settings
  (2026-10-02).** Owner decision (Erkki 2026-10-02, one-way door approved):
  `MigrateLegacyConfig::apply()` deletes every `smaily/*` row of
  `core_config_data` at every scope right after it has written every scope's
  v3 values; an error before that point leaves them (Magento's patch
  transaction rolls the writes back too). `LIKE 'smaily/%'` matches neither
  `smaily_connect/*` nor `smailyX/*`. A store with no legacy rows is
  unaffected. The change is inside the existing patch, so an install that
  already applied it (sandbox, pre-release test installs) keeps its legacy
  rows; no published store has applied it. `docs/UPGRADING.md` says
  plainly that going back to 2.8.x starts with empty settings; INSTALLING,
  ARCHITECTURE, TESTING, UPSTREAM_PROPOSAL and CHANGELOG follow. Integration
  tests (RED first): rows deleted at default, website and store scope;
  `smaily_connect/*`, `smailyX/*`, `smaily_other/*` stay; a failed migration
  keeps the legacy rows. Gates: unit 490, phpcs 0 errors, phpstan `[OK]`,
  integration 114.

- **PRO-3583 follow-up done — Settings hides the introduction once the
  engine is connected (2026-10-02).** Owner decision (Erkki 2026-10-02):
  Initial setup step 4 always shows the Campaign Intelligence introduction
  (agreed text, paid add-on note, price); Settings > Intelligence shows it
  only until the engine is connected, as WooCommerce does.
  `panel/intelligence.phtml` wraps the two paragraphs; only the Settings
  context (`$isSettings`) gives the wrapper `id="smaily-w-engine-intro"`,
  and `engineConnected()` in `panel/panels-js.phtml` hides it — on page
  load from `boot.intelligence.connected` and right after a successful
  Connect. A refused account counts as connected (the connected block
  shows its banner), so the introduction stays hidden there too. No new
  phrases. Verified with a DOM check without the sandbox: both templates
  rendered with stubs (synthetic data) and run in headless Chrome — setup
  disconnected/connected: intro shown; Settings disconnected: shown;
  Settings connected: hidden (before the change: shown). Gates: unit 484,
  phpcs 0 errors, phpstan `[OK]`, integration 112.

- **PRO-3616 done — a store unsubscribe always travels under "All
  customers" (2026-10-02).** Smaily creates a new contact sent without
  `is_unsubscribed` as subscribed (owner, 2026-10-02), so under the soft
  opt-in a send without a status for someone who unsubscribed in the store
  could make them a subscriber. Live paths checked: (1)
  `CustomerSaveAfter` — under all customers it now reads the customer's
  newsletter record and sends `is_unsubscribed=1` when it is unsubscribed;
  no record, a pending confirmation and a subscribed customer go without a
  status as before; subscribers only unchanged (only subscribed customers,
  `0`). (2) `OrderPlaced` guest branch (all customers, no opt-in, include
  guests on) — `Subscriber::loadBySubscriberEmail()` on the order's
  website; an unsubscribed record sends `1`, otherwise no status. (3)
  `SyncDispatcher::dispatchCartPurchase()` (every mode) — a withdrawn
  reminder never reached Smaily, so the marker can create the contact; it
  now takes `$unsubscribedInStore` and adds `is_unsubscribed=1`, which
  `OrderPlaced` reads the same way. Already correct: `SubscriberSaveAfter`
  (an unsubscribe goes as `1` in every mode) and the contact import
  (PRO-3610: subscriber rows carry their status). Outside this fix, not
  changed (reported for a Story): `ProfilingConsent::writeToContact()` (a
  profiling choice, every mode) and the automation triggers (welcome,
  first order, abandoned cart) post an address without a status. The native `sync_mode` comment in
  `etc/adminhtml/system.xml` (the section is hidden, PRO-1461; the text is
  a translation string and `config:set` help) now reuses the PRO-3610 mode
  card wording; new phrase (EN + ET, for Erkki's proofread) replaces "All
  customers (legitimate interest): sync every registered customer; Smaily
  manages suppression." inside the comment with "All customers (legitimate
  interest): every registered customer is synced; anyone who has not
  unsubscribed can receive your emails (soft opt-in). Soft opt-in allows
  marketing emails only about products similar to those the customer
  bought, and the customer must have had a clear way to refuse at
  purchase. Every email needs an unsubscribe link. You are responsible for
  the legal basis." / ET the same sentences as the PRO-3610 card ("Kõik
  kliendid (õigustatud huvi): sünkroonitakse iga registreeritud klient;
  igaüks, kes pole loobunud, …"). New `CustomerSaveAfterTest`, new
  `OrderPlacedTest` and `SyncDispatcherTest` cases (RED first). Gates:
  unit 484, phpcs 0 errors, phpstan `[OK]`, integration 112. Not run:
  `bin/magento setup:di:compile` (new `SubscriberFactory` dependency on
  `OrderPlaced`) — sandbox not used.

- **PRO-3575 setup address and subdomain validation done (2026-10-02).**
  `Engine\Client::setupExchange()` calls only an https address on
  `Client::ENGINE_HOSTS` (`intelligence.smaily.com` — the only engine host
  the contract, the Woo `Constants::SETUP_BASE_URL` and the Shopify user
  guide name; neither sibling pins a host in code) and returns a reply only
  when `engine_base_url` and every `endpoints` value are https on that host,
  read with Guzzle's own `Psr7\Uri`; both callers store nothing otherwise.
  Stored connections are not re-checked at call time, so an existing one
  keeps working. A setup URL on the mock engine (`http://172.20.0.1:9876`)
  is now refused, so the sandbox mock-engine walks need another way in.
  `SmailyUrl::isPlainSubdomain()` (one DNS label) gates the `Subdomain`
  backend model (save and `config:set`), the wizard/Settings connection save
  (per-language accounts included, before anything is written),
  `TestSmaily` and `Workflows`; `SmailyClient` refuses to build a request
  host from anything else (`InvalidSubdomainException`), so a legacy or
  earlier-stored value sends nothing either. New EN + ET phrases.

- **PRO-3575 browse relay hardening done (2026-10-02).**
  `Controller/Relay/Index` keys its per-minute limit on
  `RemoteAddress::getRemoteAddress()` (Magento's standard: forwarding headers
  only as the store's DI configuration names them, with its trusted
  proxies), not `getClientIp()`. It forwards through the new
  `Engine\Client::relayBrowse()`: one attempt, 3 s total and 2 s connect, no
  retry and no back-off wait — browse stays loss-tolerant and unqueued, as
  in Woo, and queueing anonymous traffic would only move the load into the
  database. Every other engine call now has a 10 s connect timeout, and a
  429 `retry_after_seconds` is honoured up to 60 s (cron included).
  `BrowseEventValidator` no longer forwards `external_id`; identity comes
  from the visitor token and the login identity merge. The rest of
  PRO-3575 is handled separately.

- **PRO-3610 done — "All customers" is the soft opt-in (2026-10-02).**
  Owner decision (Erkki 2026-10-02, answers Questions item 9): the
  legitimate-interest mode is the EU soft opt-in; Smaily (confirmed by
  Erkki) creates a new contact sent without `is_unsubscribed` as
  subscribed and keeps an existing contact's status. The import now
  matches the live sync and WooCommerce: `ContactAudience::customerPage()`
  rows carry `subscribed: null`, and `ContactsProcessor` passes null to
  the payload builder, which omits the field. Subscriber rows are
  unchanged — a store unsubscribe goes as `is_unsubscribed=1` under every
  mode, as `SubscriberSaveAfter` sends it live. A customer whose signup
  still waits for its confirmation email has no objection, so under "All
  customers" it goes without a status too (the live `CustomerSaveAfter`
  sends it the same way). The mode card (Initial setup step 2 and
  Settings > Contacts, one template) says what soft opt-in requires. New
  phrase for Erkki's proofread, replacing "Every registered customer is
  synced; Smaily manages suppression. Make sure your privacy policy
  covers this basis.": "Every registered customer is synced; anyone who
  has not unsubscribed can receive your emails (soft opt-in). Soft opt-in
  allows marketing emails only about products similar to those the
  customer bought, and the customer must have had a clear way to refuse
  at purchase. Every email needs an unsubscribe link. You are responsible
  for the legal basis." / "Sünkroonitakse iga registreeritud klient;
  igaüks, kes pole loobunud, võib saada sinu kirju (pehme nõusolek).
  Pehme nõusolek lubab saata turunduskirju ainult kliendi ostetuga
  sarnaste toodete kohta ning kliendil pidi ostu ajal olema selge
  võimalus sellest keelduda. Igas kirjas peab olema loobumislink.
  Õigusliku aluse eest vastutad sina." (ET term "pehme nõusolek" is new —
  please confirm.) The native Configuration field comment still read
  "Smaily manages suppression" (PRO-3616 changed it). Gates: unit 454, phpcs 0
  errors, phpstan `[OK]`, integration 103.

- **PRO-3594 done — subscribing again switches profiling back on
  (2026-10-02).** Owner decision (Erkki 2026-10-02): when the shopper
  subscribes again, profiling comes back on, unless the shopper opted out
  of profiling on its own (My Account > Personalization or in Smaily).
  `ProfilingOptOuts` keeps the origin in the same flag row (no schema
  change): an opt-out made by unsubscribing is `{"at": moment, "by":
  "unsubscribe"}`; a plain moment, every entry written before, stays a
  profiling opt-out of its own (the safe side — a shopper who unsubscribed
  before this change opts back in on My Account). `optOutOnUnsubscribe()`
  records by unsubscribing and leaves an existing own opt-out untouched (no
  record change, no engine row). New `Observer\Engine\SubscriberResubscribed`
  (`newsletter_subscriber_save_after`, status changed to subscribed, engine
  connected, outside the `ReconcileGuard` like its twin) calls
  `optInOnResubscribe()`: only a record by unsubscribing is lifted —
  forget, an engine opt-in row on the queue, cache `1` so a read before
  Smaily hears the subscription cannot record the unsubscribe again. A
  Smaily-side mirror keeps its origin (`is_unsubscribed = 1` without
  `smaily_rec_profiling = 0` is by unsubscribing), and a record by
  unsubscribing is never written to the contact's profiling field (it would
  outlast the resubscription). Known limits: a subscription made in Smaily
  reaches the store only through the consent-mode mirror; a profiling
  opt-out made in Smaily that the store has not read yet does not stop the
  lift — the engine hears the opt-in, then the opt-out at the shopper's next
  read. Gates: unit 449, phpcs 0 errors, phpstan `[OK]`, integration 97
  (`ProfilingConsentDeliveryTest`: both acceptance cases through the queue).
  Not run: `bin/magento setup:di:compile` and a storefront walk (sandbox
  reserved).

- **PRO-1965 + PRO-1963 done — the Log's Details show the real exchange
  (2026-10-02, parity audit R11, Woo F3-44 `last_exchange`).** Before: no
  production caller passed `sent_payload` / `last_response` to
  `markSent()` / `markFailed()`, and `markFailed()` nulled both on every
  attempt — so a real sent or failed Smaily row showed "Payload (queued,
  not sent yet)" and "No response recorded — this row has not been
  attempted yet"; D6 ingest rows the same (only §3b catalog_remove rows had
  a reply). Shown by RED integration tests through the real
  `ContactSyncHandler` + `SmailyClient` (`Test\Integration\Cron\
  FlushEventQueueTest`). Now: `SmailyClient` and `Engine\Client` keep
  `lastExchange()` (request body; HTTP status + decoded body, or the first
  2000 characters of a non-JSON body, via the new
  `Model\Client\ExchangeResponse`; no reply on a network failure; never a
  credential), the four marketing handlers and `Cron\FlushIngestQueue`
  pass it to the queues' new `recordExchange()`, which sets it on the row
  model for `markSent()` / `markFailed()` to store. A batched row keeps
  only its own part (`[contact]`, `{wrapper: [item]}`,
  `{product_ids: [id]}`) and a D6 row only its own `errors[]` entries. An
  outcome without an exchange keeps the stored one (PRO-1963), on both
  queues (`IngestQueue::markSent()` no longer nulls `last_response`
  either). The drawer now says "Payload (queued, nothing was sent for this
  row)" / "No response — nothing was sent for this row." for a row that
  never reached the wire (skip, withdrawal, refusal before any request) and
  "No response received — the server did not answer. The last error says
  why." after a network failure. New phrases (EN + ET, for Erkki's
  proofread): those three; ET "Andmesisu (järjekorras, selle rea jaoks ei
  saadetud midagi)", "Vastust pole — selle rea jaoks ei saadetud midagi.",
  "Vastust ei saabunud — server ei vastanud. Põhjuse ütleb viimane viga."
  No schema change, no log line added; the drawer redacts through
  `PayloadRedactor`, the Art. 17 eraser already covers both columns. Rows
  written before this change keep NULL columns and now read "nothing was
  sent" once attempted — only sandbox rows exist. Gates: 445 unit, phpcs
  0 errors, phpstan `[OK]`, integration 108. Not run: a sandbox walk of
  the drawer (sandbox reserved) — the template was rendered with stubs.

- **PRO-3606 done — the live contact sync follows the mode in two more
  places (2026-10-02, Woo `ContactAudience`).** (1) Checkout opt-in only:
  `SubscriberSaveAfter` (new `Mode` dependency) syncs a subscription only
  while the `StorefrontSubscription` mark is set — only `OrderPlaced`'s
  checkout opt-in sets it — or when the subscription was pending
  (`getOrigData('subscriber_status')` = not active), i.e. a "Need to
  Confirm" confirmation, which the store cannot tell from a confirmed
  newsletter-form signup; without that exception a checkout opt-in on a
  double-opt-in store would never reach Smaily. A newsletter-form, admin
  or API signup alone sends no contact and no welcome. An unsubscribe
  still syncs in every mode (assumption — see Questions item 10). (2)
  Subscribers only: `OrderPlaced`'s guest branch (no opt-in, "Include
  guest order emails" on) now runs only where `Mode::requiresOptin()` is
  false, i.e. under all customers; the checkout-only exclusion it had is
  the same condition. With the opt-in, a guest reaches Smaily through the
  subscriber path in every mode, so the setting means nothing outside all
  customers: kept, its note (Settings > Contacts, `system.xml`, EN + ET)
  now says it applies only under all customers. Payload unchanged — the
  all-customers guest send still omits `is_unsubscribed` (PRO-3608 is on
  hold). New phrase (EN + ET, for Erkki's proofread) replaces "Also sync
  emails from guest orders (always on in checkout opt-in mode).": "Also
  sync emails from guest orders without the checkout newsletter opt-in.
  Applies only in the All customers (legitimate interest) mode; in the
  other modes a guest is synced only after ticking the checkout newsletter
  checkbox." / "Sünkrooni ka nende külalistellimuste e-posti aadressid,
  kus kassas uudiskirja nõusolekut ei antud. Kehtib ainult režiimis „Kõik
  kliendid (õigustatud huvi)“; teistes režiimides sünkroonitakse külaline
  ainult siis, kui ta märgib kassas uudiskirja märkeruudu." New
  `SubscriberSaveAfterTest` and `OrderPlacedTest` cases (RED first).
  Gates: 446 unit, phpcs 0 errors, phpstan `[OK]`, integration 101. Not
  run: `bin/magento setup:di:compile` (new constructor dependency on
  `SubscriberSaveAfter`) and a storefront walk (sandbox reserved).

- **PRO-3579 done — a package without the API is not "credentials
  refused" (2026-10-02, parity audit R5, Woo PRO-1686 `RefusalReason`).**
  Smaily answers `HTTP 403 {"code":227}` ("A paid package is required",
  confirmed in Smaily's response-code docs) before it checks the
  credentials. `SmailyClient` now reads the error body: code 227 throws
  `Model\Client\Exception\PlanBlockedException` (a `TransportException`
  with status 403, so `RetryPolicy` still parks a queued row on the spot,
  and the Log shows the package sentence; deliberately not an
  `AuthenticationException`) and calls `VerifiedCredentials::planBlocked()`
  instead of `refuse()`. Decision: a 227 counts as **Not connected** —
  every request is refused, so "Connected" would hide that nothing syncs —
  but it is not recorded as refused credentials: the fingerprint moves to a
  second flag row, `smaily_connect_plan_blocked_credentials` (no schema
  change), which the next accept or refusal clears. Test connection (wizard,
  Settings, the per-language blocks), the Connection status
  (`boot.planBlocked`), the configuration-save message, the Dashboard
  verdict sentence and the Smaily card sub-line name the package. New
  phrases (EN + ET, for Erkki's proofread; ET adapted from the WooCommerce
  `.po` to this module's "kasutajaandmed" / "automaatikad" terms): the Test
  connection / status / Log sentence "Smaily refused the request because
  this account's package does not include API access. Upgrade the package
  in Smaily to connect — until then the credentials cannot be checked at
  all." / "Smaily keeldus päringust, sest selle konto pakett ei sisalda
  API-ligipääsu. Ühendamiseks uuenda Smailys paketti — enne seda ei saa
  kasutajaandmeid üldse kontrollida."; the configuration-save message "The
  configuration was saved, but Smaily refused the check because …" /
  "Seadistus salvestati, kuid Smaily keeldus kontrollist, sest …"; the
  Dashboard verdict "Smaily is refusing every request because …" /
  "Smaily keeldub igast päringust, sest …"; the card sub-line "Package does
  not include API access" / "Pakett ei sisalda API-ligipääsu".
  Slice 2 (parity audit R3): My Account > Personalization exists only where
  Campaign Intelligence is live. `Controller\Privacy\Index` and `Save`
  forward to `noroute` (Magento's 404) unless
  `Engine\Settings::isSendingAllowed()`; the nav link is the new
  `Block\Account\PersonalizationLink` (a `SortLink` that renders nothing
  otherwise). Assumption: the gate is "connected and not refused", as Woo
  chose under PRO-3189 (`sending_allowed()`), not merely "connected" — a
  refused account processes no data, and a choice queued meanwhile waits
  anyway. The R3 open question (does a profiling-only `POST contact` create
  a subscribed contact?) stays open; it now matters only on stores with
  live Campaign Intelligence.

- **PRO-3591 done — My Account > Personalization shows only a known
  preference (2026-10-02, Woo PRO-3189).** The page used `isAllowed()`,
  which fails open when Smaily cannot be read, so it ticked "Allow" for a
  shopper whose choice the store did not know. New
  `ProfilingConsent::knownPreference()`: the store's record or a successful
  Smaily read (a found contact, or Smaily saying it has none) is known;
  the fail-open answer is now cached as `?` (the gate still reads it as
  "profile", so `isAllowed()` and its callers are unchanged), and
  `knownPreference()` drops a cached `?` and asks Smaily again before it
  answers, so a recovered Smaily is seen on the next visit. Unknown →
  `PrivacyForm::getKnownPreference()` null → the page (Luma and the Hyvä
  twin) says the preference could not be loaded and offers one **Opt out of
  personalized recommendations** button: the same form without the tick
  box, which `Save` already reads as an opt-out. New phrases (EN + ET, for
  Erkki's proofread): "We could not load your personalization preference
  right now. Please try again later." / "Me ei saanud praegu sinu
  personaliseerimise eelistust laadida. Palun proovi hiljem uuesti."; "Opt
  out of personalized recommendations" / "Loobu personaalsetest
  soovitustest" (both after the WooCommerce `.po`).
  Gates after PRO-3579 + PRO-3591: 409 unit, phpcs 0 errors, phpstan
  `[OK]`, integration 89. Not run: `bin/magento setup:di:compile` (new
  constructor dependencies on `Controller\Privacy\Index`/`Save` and the new
  `Block\Account\PersonalizationLink`) and a storefront walk on the sandbox
  (reserved) — both for the next sandbox pass.

- **PRO-2456 page frame — the admin pages feel native (2026-10-02).**
  Owner decisions, recorded in `docs/ADMIN_UI_TARGET_SPEC.md` "Page frame
  decisions": the pack's background on every Smaily page, and Magento's
  Open Sans instead of the pack's system stack. Settings, Initial setup and
  the Dashboard have no width cap any more: the grey pane spans the content
  area at 1440 and 1920 px (no empty band on the right), Settings has a
  full-width white tab strip (13 px / 600 tabs), the wizard pane lost its
  bordered box, the Dashboard sits on the grey pane with the pack's 12–18 px
  spacing and panel headers with dividers. Cards keep their own measure
  (Automations and Intelligence 666 px, setup steps and their footer
  680 px). Text, headings, links (#1979c3) and the saved / error status
  (#1f7a34 / #bb2b0e) use our tokens; buttons are 33 px. CSS only. Overall
  style coverage 78.0 → 85.0 % (font difference counted as accepted). The
  Log page is a native grid and has no Smaily frame.

- **PRO-3578 done — profiling consent hardening (2026-10-02,
  parity audit P3 + R1 + R2, Woo PRO-3189/3191/3192/3434).** Slice 1: the
  engine opt-out (and opt-in) is no longer one direct, never-retried call.
  `ProfilingConsent::setAllowed()` writes the Smaily contact as before,
  keeps the choice in `Model\Privacy\ProfilingOptOuts` — one flag row,
  `smaily_connect_profiling_optouts`, a map of `sha1(address)` to the
  opt-out's Unix moment, opt-outs only, changed under a named lock; no table,
  no column — and queues an `engine.profiling_consent` row
  (`{email, opt_out, opted_out_at}`, entity = address) when the engine is
  connected. `Queue\Handler\ProfilingConsentHandler` sends §10 with reason
  `user_preference` behind the merge handler's gate; a row whose choice no
  longer matches the record (the shopper changed their mind while it waited,
  or a Send again of an old row) closes as sent without a call; a §10 404
  (the engine does not know the address) closes as sent. A failed Smaily
  write no longer loses the engine call. Gates: unit, phpcs 0 errors,
  phpstan `[OK]`, integration 89 (`ProfilingConsentDeliveryTest`: an
  opt-out survives an engine outage; an older choice on the ladder cannot
  undo a newer one).
  Slice 2: the login identity merge skips an opted-out shopper.
  `IdentityMergeHandler` asks `ProfilingConsent::isAllowed()` at the
  customer's store view (queued with the row as `store_id`, which the
  handler strips before the §7 call; a row queued before that looks the
  customer up by `customer_external_id`; default scope when the account is
  gone) and closes the row as sent without a call. On the cron, not in
  `Observer/Engine/CustomerLogin`, so login never waits on a Smaily read.
  Simplification pass (behaviour-neutral): both engine queue handlers share
  `Engine\Settings::sendingBlockedReason()`; `ProfilingConsent` builds its
  cache key and saves its cache entry in one place each; addresses are
  normalised once, in `ProfilingConsent` (`ProfilingOptOuts` takes them
  normalised).
  Slice 3: unsubscribing from marketing also stops profiling (Erkki
  2026-10-02, Woo F3-31). `isAllowed()` reads `is_unsubscribed = 1` as "do
  not profile"; new `Observer\Engine\SubscriberUnsubscribed`
  (`newsletter_subscriber_save_after`, engine connected, status changed to
  unsubscribed, NOT behind the `ReconcileGuard` so a Smaily-side unsubscribe
  mirrored in consent mode counts) calls `optOutOnUnsubscribe()`: the record
  at that moment + an engine row + cache `0`; no Smaily profiling write.
  Superseded by PRO-3594 (Erkki 2026-10-02): subscribing again turns
  profiling back on unless the shopper also opted out on their own.
  Slice 4: the newest choice wins. `isAllowed()` resolves the contact
  against `ProfilingOptOuts`: only a `smaily_rec_profiling = 1` whose `_ts`
  parses strictly as `Y-m-d\TH:i:s\Z` (round-trip), is at most 300 s in the
  future and is later than the record's moment lifts the opt-out (record
  cleared, opt-in queued for the engine). Otherwise the opt-out holds and is
  carried to a FOUND contact (`0` + now, record moment set to now); never to
  a not-found one. A Smaily read error lets the record decide (no entry =
  fail open, as before).
  Slice 5: an opt-out recorded in Smaily reaches the engine. A read that
  finds `smaily_rec_profiling = 0` or `is_unsubscribed = 1` on a contact the
  record has no entry for records a mirror (moment 0, Woo PRO-3192) and
  queues the engine opt-out. Invariant: every change to the record queues
  exactly one engine row; nothing else does (no re-send on every read as
  Woo does). Reads happen on My Account > Personalization and in the
  identity-merge handler at login, so a Smaily-side opt-out reaches the
  engine at the shopper's next visit, not immediately.
  Not done here (follow-ups): PRO-3189's "show only a known preference" on
  My Account; the erasure does not clear the record's hash entry; a §10 404
  closes the row, so an address the engine learns later is not re-sent its
  opt-out until a later record change; `bin/magento setup:di:compile` not
  run (sandbox reserved) — the new `LockManagerInterface` dependency relies
  on core `app/etc/di.xml`'s preference.

- **PRO-2456 follow-up — the owner's live-sandbox review (2026-10-02).**
  Marketing > Smaily Connect > Dashboard shows no missing-glyph box: the
  menu id is now `Smaily_Connect::connect_dashboard`, because Magento turns
  the id's tail into the menu class and its own `.item-dashboard` rule puts
  the Dashboard icon glyph before such a link. The "Initial contact
  import" block follows the pack's Backfill frames: Start import is the
  primary button (no text shadow on our buttons), and the buttons and the
  status ("Queued — …", in neutral grey while working) share one row under
  a divider instead of the status dropping below with an indent. The count
  sentence picks singular or plural per count ("1 customer" / "1 klient");
  new phrases "%1 customer(s)", "%1 order(s)", "%1 product(s)" and "Your
  store has %1, %2 and %3. …" replace the old sentence in EN + ET.
  Automations: the Enabled (and Test mode) checkbox sits on one line with
  its label. Intelligence: "Connected: … (engine …)" is the pack's success
  Banner instead of Magento's message box, and the body copy, the browse
  tracking checkbox and its muted, indented note keep the card's spacing.
  Overview ("You are all set!"): the link list wraps at body line height
  (was 2.2), and a footer under a divider offers **Go to Dashboard**
  (primary) and **Open Settings** — new phrases "Ava töölaud" / "Ava
  seaded". Checked by screenshot at 1440 and 1100 px in en_US and et_EE.

- **PRO-3602 done — the update deletes the retired force opt-in value
  (2026-10-02, as Woo 3.11.1).** Owner decision (2026-10-02). New data
  patch `Setup\Patch\Data\RemoveRetiredForceOptInSetting` deletes every
  `core_config_data` row whose path is exactly
  `smaily_connect/subscribers/automation_force_opt_in` (the PRO-3577 path),
  at every scope, on `setup:upgrade`. Not revertable; no schema change. New
  `Test\Integration\Setup\RemoveRetiredForceOptInSettingTest`: rows at
  default, website and store scope go; neighbours (same group, a
  shared-prefix path, a `_`-wildcard look-alike, a foreign path) stay.
  CHANGELOG, UPGRADING and ADMIN_UI_TARGET_SPEC say so; this supersedes
  "left in place" in the PRO-3577 entry below. Not run: `setup:upgrade` on
  the sandbox (sandbox reserved).

- **PRO-3583 done — the Campaign Intelligence introduction (2026-10-02,
  parity audit R4, GMS-11).** Owner decision (2026-10-02): the siblings'
  approved EN + ET text one-to-one. `panel/intelligence.phtml` (shared by
  Initial setup step 4 and Settings > Intelligence) replaces the one-line
  "Skip this step if you do not have an Intelligence subscription …" with
  Woo's two paragraphs (`Step4Recommendations.tsx` `IntroCopy`, Woo
  `languages/smaily-connect-et.po`): what it does with product, customer
  and order data, and that it is an optional paid add-on (€250/month)
  added to the Smaily monthly payment, activated by contacting Smaily. Shopify
  carries the same text (ASCII apostrophe in "store's"; Woo's typographic
  one is used). Two plain `<p>` in the existing `.smaily-card`, no new
  styles. Visibility unchanged: shown in both places, connected or not
  (Woo hides it on its Settings tab once connected; Shopify on both).
  USER_GUIDE and CHANGELOG say so. Not run: a screenshot check in et_EE
  (sandbox reserved).

- **PRO-1952 done — catalog rows flag a placeholder category (2026-10-02,
  parity audit R8, contract §3 v1.6.0).** `CatalogPayloadBuilder::
  categoryPath()` returns null when the product has no real category (none
  assigned, none loadable, or only the root, which has no slug segments);
  `build()` then sends `category_path: "uncategorized"` and
  `tags.category_defaulted: "true"`. A real path sends no flag
  (omit-on-false, as Woo and Shopify). The delete tombstone is built by
  `build()`, so it carries the flag on the same rule. A store category whose
  own slug is `uncategorized` is a real category and is not flagged. Three
  new `CatalogPayloadBuilderTest` cases build the payload with the real
  builder (RED first for the two flagged cases). Not run: a live catalog
  sync on the sandbox (sandbox reserved).

- **PRO-3581 done — uninstalling removes the module's settings and flag
  rows (2026-10-02, parity audit R9, Woo `uninstall.php`).** Owner decision
  (2026-10-02): uninstall removes the credentials, the keys and every other
  setting, as Woo does. New `Setup\Uninstall` (`UninstallInterface`)
  deletes `core_config_data` rows under `smaily_connect/` (every scope) and
  the 2.8.x `smaily/` rows (the plaintext password the migration kept for a
  downgrade), and every `flag` row whose code starts with `smaily_connect_`
  (`ProfilingOptOuts`, `VerifiedCredentials`, `HealthCheck`,
  `ContactReconcile` cursors). Magento calls it on a composer
  `module:uninstall` (with `--remove-data`, or on the prompt; without a
  terminal it runs anyway). `module:uninstall --non-composer` (app/code, the
  ZIP path) calls no Uninstall class — it only reverts data patches — so the
  new `Setup\Patch\Data\RemoveSettingsOnUninstall` (no-op `apply()`) runs
  the same removal from `revert()`; on the composer path both run. Disabling
  runs neither. No engine-side revoke (as Woo). Tables stay Magento's job
  (declarative schema drops them on disable + `setup:upgrade`), so
  INSTALLING.md says disable first, then uninstall. New
  `Test\Integration\Setup\UninstallTest` (RED first; `flag` mirror added to
  `SchemaInstaller`). Not run: `module:uninstall` on the sandbox (sandbox
  reserved) — the hook wiring is read from Magento's
  `ModuleUninstallCommand`/`ModuleUninstaller`/`UninstallCollector` source.

- **PRO-3582 done — the contact import follows the contact-sync mode
  (2026-10-02, parity audit R7).** Owner decision (2026-10-02): the import
  sends exactly the audience the live sync would send under the website's
  mode, as WooCommerce does; a non-subscriber goes with their real
  subscription status. New `Model\Backfill\ContactAudience` (plain SQL on
  `newsletter_subscriber` and `customer_entity`): consent = the website's
  subscribers with status subscribed or unsubscribed (the two
  `SubscriberSaveAfter` sends; a pending or unconfirmed signup is no longer
  imported as unsubscribed); legitimate interest = those, then every other
  customer of the website, matched out by customer id or by address for a
  never-linked guest subscription, sent as unsubscribed (PRO-3610 now sends
  them without a status; a customer without
  a store of the website goes through its default store); checkout opt-in
  only = nobody, the job ends at 0 like the switched-off case.
  `ContactsProcessor` walks subscribers, then (legitimate interest only)
  customers with the cursor `customer:{id}`; a cursor from a running job
  stays valid. `count()` reads the same queries, so the estimate equals the
  walk. The estimate's first count is now "N contacts to import" for the
  saved mode, swapped live when another mode is picked; orders and products
  stay. New phrases "%1 contact to import" / "%1 contacts to import" (ET
  "%1 kontakt importimiseks" / "%1 kontakti importimiseks") replace the
  unused "%1 customer(s)". Three orphaned collection-factory test stubs
  removed. New `Test\Integration\Backfill\ContactAudienceTest` (real
  SQL, each mode) and `ContactsProcessorTest` / `WizardDataTest` cases.
  Not covered: guest-order emails under "Include guest order emails" are
  synced live but not imported (WooCommerce does not import them either).
  USER_GUIDE and CHANGELOG say so; see Questions item 9.

- **PRO-3584 done — one bad attribution cookie no longer costs the order
  the other signals (2026-10-02).** Confirmed first with the new
  `Test\Integration\Engine\AttributionManagerTest` (RED): under
  `STRICT_ALL_TABLES` a 73-character visitor token failed the side-table
  insert with MySQL 1406, which `OrderSaveAfter` swallows, so the order
  lost all four signals; under Magento's own empty SQL mode (the adapter
  sets `SQL_MODE=''` on connect, so this is the stock case) an over-long
  session id was stored cut to 64 characters. New
  `Model\Engine\AttributionShape` holds WooCommerce's PRO-1942 shapes —
  visitor token `vt_` + alphanumerics, context and session id
  `[A-Za-z0-9._-]` — each capped at 64 characters (the visitor token
  `vt_` + 1–61, one difference from Woo's 1–64, so it always fits the
  column). Applied at three points: both storefront capture scripts write
  the visitor token and context cookies only in shape;
  `AttributionManager::readCookies()` reads every off-shape cookie as
  absent (the rec id via `RecId` too) instead of the old 255-character
  cap; `OrderPayloadBuilder` omits an off-shape stored value. Each signal
  drops on its own. `readCookies()` also feeds `CustomerLogin`'s identity
  merge, which now gets only in-shape values. No schema change, no
  logging added. New `OrderPayloadBuilderTest` cases (RED first). USER_GUIDE,
  ARCHITECTURE and CHANGELOG say so.

- **PRO-3580 done — only the shopper's own storefront subscription fires
  the welcome (2026-10-02).** Owner decision (2026-10-02): a storefront
  subscription fires the welcome, a storefront resubscription too; a
  subscription made in the admin, through the API or by an import does
  not (audit gap R6). `SubscriberSaveAfter` still syncs every subscription,
  but dispatches the welcome only in the `frontend` area or while the new
  shared `Model\ContactSync\StorefrontSubscription` mark is set.
  `OrderPlaced` sets the mark around the checkout opt-in's subscribe,
  because Luma's checkout places the order through the REST API
  (`webapi_rest`). Admin (`adminhtml`), REST/SOAP/GraphQL, `crontab` and a
  command line without an area fire no welcome. A headless storefront that
  subscribes through GraphQL counts as the API (see Questions). The
  once-per-contact welcome Shopify has (PRO-1755) is out of scope. New
  `Test\Unit\Observer\SubscriberSaveAfterTest` and `OrderPlacedTest`
  (RED first). The Automations card says "Fires when a shopper subscribes
  to the newsletter in your store." (EN + ET); USER_GUIDE and CHANGELOG
  say so.

- **PRO-3577 done — no setting can re-subscribe an unsubscribed contact
  (2026-10-02).** `AutomationHandler` sends `force_opt_in => false`
  outright and no longer takes `ContactSync\Mode`; WooCommerce retired the
  same setting under PRO-1716 (Erkki approved, 2026-08-04). Removed:
  `Mode::automationForceOptIn()`, `Config::automationForceOptIn()` and
  `XML_PATH_AUTOMATION_FORCE_OPT_IN`, the `config.xml` default, the hidden
  `system.xml` field, the `saveFlag()` line in
  `WizardStepSaver::saveSubscribers()`, the `forceOptIn` boot key, the
  Settings > Contacts row with its legitimate-interest show/hide script,
  and its two phrases (EN + ET). A stored
  `smaily_connect/subscribers/automation_force_opt_in` row is left in
  place and unread (no data patch, no schema change — as WooCommerce did
  first); a save from a cached admin page that still posts the key never
  writes it again. Without the `system.xml` field `config:set` refuses the
  path. New `Test\Unit\Model\Queue\Handler\AutomationHandlerTest` (it
  was RED with the setting stored on under legitimate interest) and a
  `WizardStepSaverTest` case pin it. USER_GUIDE, UPGRADING, CHANGELOG and
  ADMIN_UI_TARGET_SPEC say so.

- **PRO-3576 done — a malformed rec id no longer costs the engine the
  order (2026-10-02).** Contract §5: the orders route validates
  `smaily_rec_id` with zod v3 `z.string().uuid()` (8-4-4-4-12 hex, no
  version nibble — checked in the engine source) and rejects the whole
  order over a malformed value. New `Model\Engine\RecId::isValid()` (same
  pattern, `D` modifier so a trailing newline fails);
  `OrderPayloadBuilder::attribution()` omits a malformed stored rec id and
  keeps the other three signals; the storefront capture
  (`view/frontend/web/js/attribution.js` and the Hyvä twin) writes the
  cookie only for a well-formed id, from `smaily_rec` or the
  `utm_source=smaily` `utm_content` fallback. Browse events are unchanged:
  they never carried the rec id. Same check as WooCommerce
  (`Support/RecId.php`, PRO-1710). The visitor token, context and session
  id keep only their 255-character cap (WooCommerce PRO-1942 shape-checks
  them too) — done under PRO-3584.

- **PRO-3572 done — a network failure logs no contact address (2026-10-02).**
  Guzzle ends a network-failure message with the full request URL
  (`cURL error N: … for https://…`). The Smaily consent lookup
  (`GET contact?email=`) carries the address in the query; the engine's
  customer endpoints (`customer/{email}/…` export, delete, opt-out) carry
  it in the path. New `Model\Client\TransportErrorMessage::of()` keeps
  every URL's scheme, host, port and path, drops query and fragment, and
  writes `{email}` for a path segment holding an `@`. `SmailyClient` logs
  and throws only that text; `Engine\Client` throws only that text; neither
  chains the raw Guzzle exception any more (Monolog would print a chained
  message if the exception ever reached it). Fixed at the source, so every
  reader of the message is clean: the always-on error log, the
  ProfilingConsent / ContactSyncHandler / backfill / reconcile / health
  logs, `RetryPolicy` → `smaily_event_queue.last_error`, `FlushIngestQueue`
  → `smaily_ingest_queue.last_error`, the backfill job's error, the admin
  Log (whose email
  masker never matched the `%40` form), the admin AJAX replies and the
  GDPR / ping CLI output. Messages that Smaily or the engine send back
  (ApiException, engine 4xx) are not touched. Gates: 326 unit, phpcs 0
  errors, phpstan `[OK]`, integration 87.

- **PRO-3571 done — no one-click debug logging in the admin (2026-10-02).**
  The Log page's "Log Verbosity" strip let any admin with Log access switch
  `smaily_connect.log` to debug. Erkki decided (2026-10-02) to remove it, as
  the Woo plugin leaves detailed logging to the developer (`WP_DEBUG`).
  Removed: `log/verbosity.phtml`, its layout block, the
  `LogVerbositySettings` ViewModel, the `api/saveverbosity` controller,
  `Model\LogVerbositySaver`, the strip's CSS and the orphaned phrase
  "Invalid log verbosity value." (EN + ET). Kept: the `smaily_connect/api`
  route and `Smaily_Connect::event_log` ACL (other actions use them), the
  `logging/verbosity` path with default `error`, `Config::getLogVerbosity()`
  and the Logger unchanged, the source model, and the hidden `system.xml`
  field — `config:set` rejects a path `system.xml` does not declare
  (`Magento\Config\Model\Config\PathValidator`); `config:set` cleans the
  config cache itself, so no `cache:flush` is needed. New
  `Test\Unit\Model\Logger\LoggerTest` pins the gating, the path, the
  `error` default and the `system.xml` field. USER_GUIDE (troubleshooting),
  PILOT_CHECKLIST, CHANGELOG and ADMIN_UI_TARGET_SPEC (§2.4/§4.2 marked
  superseded) say so. Finding: debug does not write full contact data —
  Smaily request bodies are summarised (count + keys), the query `email` is
  masked, responses are summarised; the engine client logs no payloads.
  The old guide line "customer PII is not written to disk" was still too
  strong: a curl transport error message carries the request URL, so the
  profiling-consent lookup (`GET contact?email=`) can put an address in
  the always-on error log (fixed by PRO-3572). Gates: 323 unit, phpcs 0 errors, phpstan `[OK]`,
  integration 87.

- **PRO-3560 done — "Connected" means Smaily accepted the credentials
  (2026-10-02).** Before, the Dashboard and Settings > Connection said
  "Connected" whenever subdomain, username and password were filled in, and
  the Dashboard verdict ignored the Smaily connection — so placeholder
  credentials read "Connected with the saved credentials" and missing ones
  read "All systems normal" beside a "Not connected" Smaily card (fidelity
  audit row 13). Now `Model\Client\VerifiedCredentials` remembers which
  credentials Smaily accepted, as keyed hashes (`EncryptorInterface::hash`,
  never the password) in one flag row (`smaily_connect_verified_credentials`,
  last 20) — no schema change. `SmailyClient` records the answers at its one
  chokepoint: a passed `validateCredentials()` (Test connection, and now
  every connection save — `WizardStepSaver::saveConnect()` checks what it
  saved, never blocking the save) accepts; any 401/403, the queue's
  included, refuses. Changed credentials are not verified until checked.
  The Dashboard's Smaily card and the Connection status (`boot.verified`)
  read it at the same scope — the target website's default store view
  (the Dashboard used to read the admin's default scope); a new
  `disconnected` verdict ("Not connected", danger, "Open Connection
  settings") ranks right after "Setup incomplete". No Smaily call happens on
  a page load. `Config::isConnected()` keeps its meaning (fields filled in)
  as the gate for queueing and sending. New phrases (EN + ET, for Erkki's
  proofread): "Open Connection settings" / "Ava ühenduse seaded"; the
  verdict sentence "The Smaily credentials are missing or Smaily has not
  accepted them — check them in Settings > Connection and test the
  connection." / "Smaily kasutajaandmed puuduvad või Smaily pole neid vastu
  võtnud — kontrolli neid jaotises Seaded > Ühendus ja testi ühendust."
  Gates: 318 unit, phpcs 0 errors, phpstan `[OK]`, integration 87.
  Sandbox walk done on the ZIP-installed store (PRO-2474 run below), en_US
  + et_EE: missing credentials → "Not connected" verdict and card;
  placeholder credentials Smaily refuses (404, unknown subdomain) → neither
  the Dashboard nor Settings > Connection says Connected; an accepted check
  simulated with `VerifiedCredentials::accept()` on the saved values → both
  say Connected. Observation: Settings > Connection renders "Not connected"
  until the panel script fills in the real status, so a verified store
  shows it for a moment on load.

- **PRO-2474 progress (2026-10-02): the release ZIP installs on a clean
  Magento — only the rc1 tag on Erkki's go remains.** The 3.0.0-rc1 ZIP
  (VERIFY OK, 338 entries, `sha256sum -c` OK) was installed on fresh
  sandbox volumes with the module mount removed (scratch compose override,
  not committed), in production mode, running `docs/INSTALLING.md`
  verbatim: maintenance:enable → module:enable → setup:upgrade →
  setup:di:compile → setup:static-content:deploy en_US et_EE →
  maintenance:disable → cache:flush, all exit 0. Module enabled, six
  `smaily_*` tables, `cron:run --group smaily_connect` leaves `success`
  rows, the four admin entries open (Initial setup first), the "ready to
  set up" system message shows. Update path (fresh extraction + the same
  commands, production and default mode): settings byte-identical
  afterwards. Disable with `--safe-mode=1`: tables 6 → 0, settings kept,
  CSV only for tables with rows; re-enable with `--data-restore=1`: 6
  tables, the seeded row back. Runbook corrections: `app/code` must be a
  real directory (the sandbox's sample-data symlink made production static
  deploy fail — TESTING.md now has the clean-install procedure), and what
  `--safe-mode=1` / `--data-restore=1` really keep. Sandbox returned to the
  normal mounted dev setup, default mode, test data removed.
- **PRO-2474 earlier (2026-10-02): runbook + pilot-day checklist written.**
  `docs/INSTALLING.md` (public, linked from README and the User Guide's
  manual-install section) is the ZIP install runbook: verify with the
  `.sha256`, extract flat into `app/code/Smaily/Connect`, production vs
  developer command sequences, the `smaily_connect` cron group, admin checks,
  rc updates, disable/remove. Written from code and Magento source, not yet
  run: Magento's declarative schema drops a disabled module's tables on the
  next `setup:upgrade` (the whitelist is read from every registered module,
  the declaration only from enabled ones), so the rollback section says so
  and offers `--safe-mode=1` / `--data-restore=1`. `PILOT_CHECKLIST.md`
  (repo root, internal) is Erkki's pilot-day smoke list; it is excluded from
  the release ZIP and `bin/verify-release-zip.sh` now forbids it (local run:
  VERIFY OK, 338 entries, `absent: pilot checklist`). Remaining for PRO-2474
  then: run the runbook on a clean sandbox (done, above), then the rc1 tag
  on Erkki's go.
  Pre-tag packaging fixes: `TESTING.md` and a worktree's `.git` pointer file
  no longer ship (both forbidden by the verifier; worktree run: VERIFY OK,
  336 entries); the User Guide names the browse-tracking toggle by its admin label.
  The shipped README and CHANGELOG link `TESTING.md`, `CONTRIBUTING.md` and
  `BACKLOG.md` by GitHub URL — those files do not ship, so a relative link
  was dead inside the ZIP.

- **`composer.lock` refreshed (2026-10-02, GitHub issue #2).** `composer
  update --ignore-platform-req='ext-*'`, PHP 8.1 pin kept; `composer update
  --dry-run` now reports nothing to modify. Patch/minor moves only (symfony
  6.4.x patches, polyfills 1.43, nikic/php-parser 5.9, composer/semver 3.5,
  composer/class-map-generator 1.8, phpunit 10.5.65); PHPStan stays at the
  2.2.5 cap. One downgrade: `league/flysystem` 2.x now carries security
  advisory PKSA-w9tt-7782-78jx, so composer refuses it and resolves
  `magento/module-remote-storage` 100.4.2 instead of 100.4.5 — six packages
  leave the lock (flysystem, its S3 adapter, `aws/aws-sdk-php` and three of
  their helpers). 100.4.2 declares
  `php ~7.4||~8.1`; `composer install` checks against the pinned 8.1, so the
  PHP 8.3 CI jobs still install. No module code uses either. Gates on the new
  lock, identical to the old: 299 unit, phpcs 0 errors / 997 warnings,
  phpstan `[OK]`, integration 87 tests.

- **PRO-2456 — style coverage (2026-10-02):** the admin's computed styles against the design pack, 77.9 % of compared properties match; the page frame (width caps, background, mixed fonts) is the largest gap — `docs/audits/2026-10-02-ADMIN_STYLE_COVERAGE.md`.

- **PRO-2456 done — fidelity check of the admin against the design pack
  (2026-10-02).** Every artboard was rendered and compared with the sandbox
  admin in en_US and et_EE; the deviation list (24 rows, ordered by
  visibility) is `docs/audits/2026-10-02-ADMIN_DESIGN_PACK_FIDELITY.md`.
  Tokens are byte-identical and the six components match; the two
  design-pack leaks are absent. **Fixed (CSS + template only, no PHP, no
  copy):** the initial setup — the first screen a merchant sees — rendered
  the shared panels without the Settings polish: browser-default inputs and
  selects, Open Sans, Magento's dark secondary buttons, a left-aligned
  Continue on step 1, no subdomain suffix. The control, typography, button
  and checkbox rules that PRO-1391/PRO-1397 scoped to `.smaily-settings` now
  cover every page of ours (`.smaily-ui`), the Contacts rules also cover
  wizard step 2, and the wizard got the pack's shell: a 232 px left step
  rail beside a grey pane at ≥ 1024 px (the strip stays below that), a
  footer divider with the primary group pinned right, a ghost Back, long
  step errors that wrap, and no empty footer on Overview (one JS line).
  Settings > RSS got the pack's layout (two-column fields, monospace URL
  chip + Copy, tab title/description and its own footer Save — the same
  `rss` save step); the Dashboard's tiles span the row with three tiles,
  panel headers and quick links follow the pack, the verdict is
  `--fs-18`; the Settings tab underline is 2 px / 700. **Deferred** (larger:
  logic, behaviour, JS state rendering or new copy): no "Not connected"
  Dashboard verdict, the wizard's "Step N of 5" kicker, the two-layer error
  model, the completed-revisit summary, the Backfill card anatomy, Log grid
  status pills, the Log Details panel, multilingual credential blocks,
  Intelligence tab header. Gates: 299 unit, phpcs 0 errors / 997 warnings,
  phpstan `[OK]` (needs `--memory-limit=1G` on this host), sandbox
  `setup:upgrade` + `setup:di:compile` + `setup:static-content:deploy
  --area adminhtml en_US et_EE` green (the deployed adminhtml files were
  removed afterwards so default mode serves the live symlinks again).
  The pack (`Magento Connect admin visual system.zip`,
  exported 2026-07-12) had been tracked in `docs/` since `db2fc53` — swept
  in by an unrelated docs commit. It is now removed from the tree and
  `/docs/*.zip` is ignored, so a re-dropped export stays local; history
  keeps it (restore with
  `git show db2fc53:"docs/Magento Connect admin visual system.zip" > pack.zip`).
  The extracted source does NOT move into `docs/design/`: the pack invents
  options and copy (target spec §1), `docs/` is public on GitHub, and what
  binds is already written down (the A1 layout extract and the target
  spec). It never shipped either way — `bin/build-release-zip.sh` excludes
  `docs/*` and `*.zip`.

- **PRO-2472 done — the 3.0.0-rc1 release train's packaging leftovers are
  closed (2026-09-11).** Four small things, no behaviour change in the
  module itself:
  - **The checksum ships with the release.** `bin/verify-release-zip.sh`
    already wrote `<zip>.sha256` beside the archive it verified; nothing
    published it. `.github/workflows/release.yaml` now uploads
    `smaily-connect-magento2.zip.sha256` alongside the ZIP, so a pilot store
    installing manually can prove what it unpacked. Verified locally: the
    verifier run wrote the file under exactly that name (337 entries,
    `php -l` clean on 196 shipped PHP files) and `sha256sum -c` on it
    answered `OK`; `actionlint` is not installed on this host, so the
    workflow was checked by reading it plus `yaml.safe_load`.
  - **`etc/module.xml` sequences what composer requires.**
    `Magento_CatalogInventory` and `Magento_Ui` were composer requirements
    (declared in the rc1 train) but absent from `<sequence>`, so Magento had
    no reason to load the module after them. Both added, the existing seven
    untouched. Sandbox: `setup:upgrade` + `setup:di:compile` clean,
    `module:status Smaily_Connect` → "Module is enabled", and
    `module:status --enabled` lists `Magento_CatalogInventory` (42) and
    `Magento_Ui` (96) ahead of `Smaily_Connect` (364).
  - **MSI is documented as optional, not required.** A `suggest` block names
    `magento/module-inventory-api` and
    `magento/module-inventory-source-deduction-api` with one line each for
    the plugin seam it serves. They stay OUT of `require` on purpose — both
    are named only in `etc/di.xml`, so an install without MSI simply never
    wires them and the legacy stock observer covers everything.
    `composer validate --strict` is unchanged: the one accepted `version`
    warning, nothing new. `suggest` is not part of composer's lock
    content-hash, so `composer.lock` stays fresh.
  - **The abandoned-package note has a home.** `magento/module-catalog-inventory`
    is marked abandoned in favour of `magento/inventory-metapackage`; the
    requirement is deliberately unchanged (we read the legacy `is_in_stock`
    flag that package owns, and the metapackage would make all of MSI a hard
    dependency). The reasoning now sits in CONTRIBUTING's dependency
    paragraph, with "revisit when the Magento floor is raised" attached to it.
  - Merchant-facing: README and `docs/USER_GUIDE.md` manual-install steps
    name the `.sha256` and the `sha256sum -c` check; CHANGELOG's two rc1
    packaging entries were extended rather than duplicated. No PHP changed,
    so the integration suite was not run. Gates: 299 unit, phpcs 0 errors /
    997 warnings, phpstan `[OK]`.

- **PRO-2476 done — the sandbox admin has no second factor, as the docs have
  always claimed (2026-09-11).** `setup:install` enables
  `Magento_TwoFactorAuth` and `Magento_AdminAdobeImsTwoFactorAuth`, and the
  bootstrap never switched them off, so every admin login — browser or script —
  ended at `tfa/tfa/requestconfig`; the last two workers fell back to a CLI
  bootstrap inside the container to render admin pages, and PRO-2456 needs real
  screens over HTTP. `.sandbox/entrypoint.sh` now runs `module:disable` on both
  after the install branch, on **every** boot: it is a no-op once they are off
  ("No modules were changed."), which is what repairs a data volume installed
  before this existed. The script is mounted over the image's copy in
  `docker-compose.yaml`, so a bootstrap edit takes on the next
  `docker compose up -d` instead of needing an image rebuild.
  **Verified over HTTP, both before and after.** Curl with a cookie jar and the
  login page's real form key: before, the POST followed to
  `/admin/tfa/tfa/requestconfig/key/…`; after `module:disable` +
  `setup:upgrade` + `setup:di:compile` on the running sandbox it lands on
  `/admin/admin/dashboard/index/key/…`, `<title>Dashboard / Magento Admin</title>`,
  with the backend menu in the markup. The container was then recreated
  (`up -d --force-recreate magento2`, volumes untouched): the boot log shows the
  new step running as a no-op and the same curl check still reaches the
  dashboard. TESTING.md carries the check and the re-enable recipe. The
  fresh-install path is not separately demonstrated — proving it would mean
  dropping the sandbox volumes, which this task was told not to do; the disable
  runs unconditionally after the install branch, and the boot log proves the
  step executes on every start. Sandbox data untouched; PRO-2462 (sample data
  re-runs on every boot) stays open and out of scope. No PHP changed, so the
  integration suite was not affected; gates re-run anyway — 299 unit, phpcs 0
  errors, phpstan `[OK]`.

- **PRO-2454 done — the Log offers "Send again" where it is safe, says what
  the server said, and calls a withdrawn reminder withdrawn (2026-09-11).**
  One server-owned guard, `Model\Log\ResendGuard` (Woo's
  `TransactionalRetryGuard` shape): a reason code plus the merchant sentence
  beside it — `withdrawn` (the shopper bought), `superseded` (a later row of
  the same trigger reached the same contact), `erased` (Art. 17 placeholder)
  and the plain `not_failed`. It is the ONLY question the per-row **Send
  again** action, the route behind it (a stale page cannot double-send) and
  the mass **Retry** ask; the supersede lookup is one query for a whole page
  or selection and lives with the table's owner (`EventQueue`). Send again
  never touches the failed row: it queues a NEW row with the same event, and
  the decision rides in that row's payload as `_resend {of, by, at}` —
  stripped by both `decodePayload()` methods, so it never reaches Smaily or
  the engine, and no column was added. The Details drawer of the new row
  carries the one line "Re-sent from event #N by <user> on <date>".
  `Withdrawn` is a derived status: one rule over the stored `cancelled`
  marker, applied by the grid UNION and by `Model\Log\QueueRowLoader`, so
  the label, the status filter and the drawer all read one value while the
  flusher still sees its terminal `sent` row. Failure wording is the
  server's own (`Model\Log\FailureMessage` strips RetryPolicy's
  `permanent_http_<code>:` prefix and runs `PayloadRedactor`); the
  classification stays in the drawer as "Failure class". Rules in
  `docs/ARCHITECTURE.md` (Queue semantics), merchant wording in
  `docs/USER_GUIDE.md`, strings in both i18n files. Verified twice on the
  sandbox against real rows — first against rows the real flusher failed
  with an invalid Smaily credential, then after the simplification pass:
  the failed row offers Send again and shows Smaily's own wording, the
  superseded and withdrawn rows show their sentence instead of a button, a
  pending row is left with its ordinary retry line, Send again queued a new
  row carrying its `_resend` record, and a mixed mass retry answered "1
  queued, 1 skipped". Seeded rows removed afterwards. Gates: 299 unit,
  phpcs 0 errors, phpstan `[OK]`, 87 integration, `setup:upgrade` +
  `setup:di:compile` clean.

- **PRO-1458 done — a product outside the default website is priced and
  linked where it actually sells (2026-09-11).**
  `CatalogPayloadBuilder::storeIdForProduct()` picks the scope per product:
  the canonical store when the product belongs to its website (unchanged,
  and also for a product on no website at all — still built, never skipped),
  otherwise the default store view of the lowest-numbered website it IS
  assigned to. The URL is built from the product loaded at that same store —
  Magento's URL model reads the rewrite and base URL off the product's own
  store, so emulation alone was not enough. Still one payload per product;
  `currency` still names the canonical store (per-website tenants are RFC
  Phase 4, PRO-1762). Two-website sandbox: the queued row carried website
  2's price and `second.localhost` URL, a website-1 control was unchanged,
  sandbox restored to single-website. Rules in `docs/ARCHITECTURE.md`,
  merchant wording in `docs/USER_GUIDE.md`, RFC §5 current-state refreshed.
  Gates: 284 unit, phpcs 0 errors, phpstan `[OK]`, 82 integration.

- **PRO-2469 done — the abandoned-cart tracker is swept, and a tombstone
  survives both of its edges (2026-09-10).** `Cron\QueueJanitor` now prunes
  `smaily_abandoned_cart` on the same 30-day window as sent queue rows,
  through `Model\AbandonedCart\StateManager` (the table's only owner), and an
  `erased` row stays erased through both a conversion and a later checkout
  opt-in. The rules are in `docs/ARCHITECTURE.md` (retention + Art. 17), the
  merchant wording in `docs/USER_GUIDE.md`. Verified on the sandbox against
  the real `quote` table, sandbox restored. Gates: 281 unit, phpcs 0 errors,
  phpstan `[OK]`, 82 integration.

- **PRO-1748 — the admin speaks the shared Connect terminology canon, in both
  languages (2026-09-10).** Jane's approved copy review, already shipped by Woo
  and Shopify, now governs Magento too: the menu item and every reference to it
  are **Initial setup** (ET *Algseadistus*), the audience is **Contacts**
  everywhere it is named (wizard rail, Settings tab, headings, Save button,
  Dashboard quick links, the Log intro, the two admin notices — ET *tellijad →
  kontaktid*), step 2's heading is **Contact synchronisation to Smaily** (ET
  *Kontaktide sünkroniseerimine Smailysse*) with the Settings tab description
  **Synchronization settings** (ET *Sünkroniseerimise seaded*), the switch is
  **Sync contacts to Smaily**, the backfill pair became **Initial contact
  import / Start import**, and step 5 is **Overview** (ET *Ülevaade*). Two ET
  strings were aligned to Woo's exact wording (`Subscribers only (consent)`,
  `Checkout opt-in only`). **Copy only** — no class, route, config path, ACL
  id, DB column or JS identifier moved; the `subscribers` step/tab id and the
  `panel/subscribers.phtml` filename are unchanged on purpose. **Deliberate
  deviations**, recorded merchant-readably in `docs/USER_GUIDE.md`
  ("Terminology"): the lawful-basis mode **Subscribers only (consent)** keeps
  its noun (Woo keeps it too — it means precisely Magento's opted-in newsletter
  subscribers); *newsletter subscriber* stays where it names Magento's own
  record; and there is **no Forms & RSS section** — Magento ships its own
  signup block, so the module only has the product-feed **RSS** tab, and the
  canon's "Ava oma Smaily konto →" link has no Magento surface to live on.
  `etc/adminhtml/system.xml` keeps its old labels: PRO-1461 hides that section
  from Stores > Configuration entirely (`showInDefault/Website/Store="0"`), so
  no merchant ever reads them. Verified on the sandbox by rendering the real
  wizard and Settings blocks in **both locales** (step rail Connect/Contacts/
  Automations/Intelligence/Overview vs Ühenda/Kontaktid/Automaatikad/
  Intelligence/Ülevaade) plus the menu and both notices. Gates: 281 unit,
  phpcs 0 errors / 929 warnings, phpstan `[OK]`, 79 integration, `setup:upgrade`
  + `setup:di:compile` clean. **Erkki proofread the Estonian diff on
  2026-09-10 and closed the issue** (Woo's equivalent gate was PRO-1746).

- **The 3.0.0-rc1 release train ran (Erkki's four-part decision,
  2026-09-10).** Nothing was published — no tag, no GitHub release, no
  Packagist, no Marketplace; those stay Erkki's doors (PRO-1198). What
  changed:
  - **The version is `3.0.0-rc1`** (composer normalises it to `3.0.0.0-RC1`,
    stability RC, so no 2.8.x install can pick it up on a `composer update`),
    carried through composer.json, `ModuleInfo::VERSION`, this file and the
    upstream proposal. `SetupGuard` records `last_seen_version` by exact
    string and only notifies on a MAJOR jump, so an rc1 install records
    itself and the later 3.0.0 upgrade is seen without a spurious notice.
  - **The release ZIP has one owner.** `bin/build-release-zip.sh` assembles
    it (the release workflow calls it instead of carrying its own zip
    command); `bin/verify-release-zip.sh` builds and then proves it, and CI
    runs that on every push (`package` job, artifact kept 5 days). Newly
    excluded: `bin/`, `.claude/`, `CLAUDE.md`, STATUS, BACKLOG and
    `phpunit.integration.xml.dist`.
  - **`docs/` does not ship (Erkki, 2026-09-10)** — it vendors the engine
    contract from a private repo and carries internal audits; README, CHANGELOG
    and LICENSE ship, the documentation set lives on GitHub and the shipped
    README/CHANGELOG link to it by URL (verifier now asserts `docs/` absent).
  - **`composer validate --strict` stays yellow on the `version` field
    (Erkki, 2026-09-10)** — accepted item, not a defect: the Marketplace
    requires the field and `ModuleVersion` reads it, so CI keeps running plain
    `composer validate`.
  - **Marketplace pre-checks, recorded in `docs/UPSTREAM_PROPOSAL.md` §4.**
    `composer validate` passes; `--strict` flags only the "leave the version
    field out" recommendation, which we keep on purpose (the Marketplace
    requires `version`, and `ModuleVersion` reads it). `vendor/bin/phpcs` —
    the repo's config IS the Marketplace's Magento2 ruleset: **0 errors**, 929
    warnings (doc-block annotations); `PHPCompatibility` 8.1–8.4 clean.
    `magento/module-catalog-inventory` (PRO-1966) and `magento/module-ui`
    were used but undeclared — now declared. `Magento\InventoryApi` and
    `Magento\InventorySourceDeductionApi` stay undeclared on purpose: they
    are named only in `etc/di.xml` plugin declarations, and MSI is
    removable.
  - Gates: 281 unit, phpcs 0 errors, phpstan clean, 79 integration
    (throwaway MySQL), plus the packaging check green (330 entries once
    `docs/` came out, `php -l` clean on 189 shipped PHP files) and a
    deliberate negative test — dropping `Test/*` from the exclusion list made
    it fail, as it must.

- **CI green again — PHPStan is capped below 2.2.6 (2026-09-10).** The
  `static` job started failing with dozens of
  `Internal error: Failed opening required '<?php ...'` while local runs stayed
  green. Cause: `composer.lock` is not committed, so CI resolves fresh — it
  picked PHPStan 2.2.13, and from 2.2.6 the phar ships the `phpstan_turbo`
  extension whose shared-memory cache makes `Cache::load()` return the value
  just passed to `save()`. `bitexpert/phpstan-magento` v0.43.0 expects that
  call to return the *path* of the factory class it generated, so it `require`s
  PHP source as a filename. Fixed by capping `phpstan/phpstan` at `<2.2.6` in
  `composer.json` (the resolver backs `rector/rector` off to a version without
  the `^2.2.10` floor); reproduced and verified on a clean clone with a fresh
  `composer install`. TESTING.md records the cap and when to lift it.

- **PRO-2473 done — `composer.lock` is committed (Erkki's decision A,
  2026-09-10).** The root cause of the day's red CI was that the lock was
  gitignored, so every CI run resolved the toolchain fresh. It is now tracked,
  and `composer.json` carries `config.platform.php = 8.1.0` so the resolver
  targets the oldest PHP the package supports — the same lock installs on the
  PHP 8.1 unit job and on the 8.5 dev host. Verified: `composer install
  --dry-run` in a real `php:8.1-cli` container reports "Installing dependencies
  from lock file", "Verifying lock file contents can be installed on current
  platform", 193 installs / 0 updates. The lock pins `phpstan/phpstan` 2.2.5,
  under the `<2.2.6` cap. A new `Lock freshness` workflow (Mondays 05:41 UTC)
  runs `composer update --dry-run` and files one GitHub issue on drift,
  commenting on the open one instead of opening a second — issues had to be
  enabled on the `erkkimarkus/magento-connect` fork for that. The release ZIP
  still excludes `composer.lock` (`bin/build-release-zip.sh` already listed
  it); verifier green, 330 entries. Gates: 281 unit, phpcs 0 errors / 929
  warnings, phpstan clean.

- **PRO-2470 done — the marketing event queue is indexed for the per-contact
  lookup (one-way door approved by Erkki 2026-09-10).**
  `EventQueue::cancelPendingAutomation()` — the PRO-2453 withdrawal, run on
  every order placed — filters `event_type` + `entity_id` + `status`, a shape
  no index served: on a store whose queue holds a season's rows that is a full
  scan on the checkout path. `etc/db_schema.xml` grew
  `SMAILY_EVENT_QUEUE_ENTITY_ID_EVENT_TYPE_STATUS` (btree, `entity_id`,
  `event_type`, `status`); the whitelist was regenerated with
  `bin/magento setup:db-declaration:generate-whitelist`.
  - **The ingest queue does NOT get the twin.** Its only entity-scoped read is
    `domain` + `status` (`SMAILY_INGEST_QUEUE_DOMAIN_STATUS_NEXT_RETRY_AT`
    already covers it) and its retry takes an id list — no
    `(entity_id, domain, status)` lookup exists, so none was added.
  - **Verified on the sandbox**, not by reasoning: `setup:upgrade` applied the
    declarative change and `SHOW INDEX FROM smaily_event_queue` lists the three
    columns in order; with 500 seeded rows `EXPLAIN` on the real
    `cancelPendingAutomation()` query reports
    `key: SMAILY_EVENT_QUEUE_ENTITY_ID_EVENT_TYPE_STATUS`,
    `ref: const,const,const`, `rows: 1` (it had been a scan). Seeded rows
    deleted afterwards — the sandbox queue is back to 0 rows.
  - Gates: 281 unit, phpcs 0 errors, phpstan clean, 79 integration (throwaway
    MySQL). The whitelist generator also emitted a hashed
    `UNQ_3F9F0C28473A63DD141D6E798667BF25` entry for the automation-mapping
    unique constraint; `ModuleDefinitionTest` rejects whitelist entries that
    are not in `db_schema.xml`, so it was dropped again.

- **PRO-2467 done — a GDPR erasure leaves an email-less TOMBSTONE on the
  abandoned-cart tracker instead of deleting the row (PRO-2452 leftover).**
  PRO-2452 deleted the contact's cart rows, but that row is also the
  "already handled" marker and the module may not touch the core `quote`
  table — so a merchant who ran only `smaily:gdpr erase` (not Magento's own
  customer deletion) left the quote active and idle, and the next
  abandoned-cart sweep saw an untracked cart and mailed the address just
  erased.
  - **The decision.** `StateManager::deleteForEmail()` became
    `anonymizeForEmail()`: `email` NULL, status the new
    `StateManager::STATUS_ERASED`, counted under the CLI's existing
    `anonymised` line ("Abandoned carts: 0 removed, 1 anonymised") rather
    than a new vocabulary. What the tombstone means for the cron, the
    export and retention is written up in `docs/ARCHITECTURE.md` ("Queue
    semantics" → Art. 17 erasure); don't restate it here. The one open
    end: a tombstone got no special retention (the janitor swept only the
    two queue tables), flagged as a follow-up — closed by PRO-2469, which
    sweeps the tracker on the sent-row window.
  - **Verification — the REAL flow on the sandbox, only the Smaily
    transport faked; synthetic contact.** A guest quote built through the
    real cart services, backdated idle, the real `Cron\AbandonedCart` +
    real flusher sending one reminder; then the REAL `smaily:gdpr erase
    --force` through `CommandTester` ("Queued messages: 0 removed, 1
    anonymised / Abandoned carts: 0 removed, 1 anonymised"). The tracker row
    came back `status=erased email=NULL` while the quote stayed
    `active=1 items=1` and idle — and a second real cron + flush run
    enqueued **nothing** and sent **zero** requests. Placing a real order on
    that same tombstoned quote afterwards produced no purchase marker and no
    queue row at all.
  - Gates: 281 unit tests green, phpcs 0 errors, phpstan clean, 79
    integration tests green (throwaway MySQL — a new case runs the real
    command against the real tables, and the cron's own gate is asked in
    `Test/Integration/AbandonedCart/StateManagerTest.php`).
    No constructor changed and no new injectable class, so no
    `setup:di:compile`; `setup:upgrade` WAS run in the sandbox because
    `etc/db_schema.xml` changed (the status column's comment now lists
    `erased`) and the declarative pipeline applied it cleanly. Sandbox
    restored after both demonstrations — 0 quotes, 0 orders, 0 products, 0
    queue rows, 0 `smaily%` config rows; the one pre-existing
    `smaily_abandoned_cart` row (quote 2, from an earlier session) was left
    as found.
  - Simplified after review (2026-09-10, same gates re-run): the tombstone
    rationale now has one owner per layer (`anonymizeForEmail()` in code,
    ARCHITECTURE in docs) and the cron-gate case moved out of the privacy
    suite into `Test\Integration\AbandonedCart\StateManagerTest`. No
    behaviour change.

- **PRO-2453 done — a purchase now stops the abandoned-cart follow-ups (Woo
  PRO-1723 parity).** A shopper who bought mid-series was handled only on
  the cart side: the tracker row went `completed` so no LATER reminder could
  be enqueued, but a reminder already queued still went out, and nothing
  about the purchase reached the Smaily contact — so the merchant's
  follow-up letters ran to the end regardless. The contact now carries
  `abandoned_cart_purchased_at`, the workflow's exit condition. How it works
  is written up in `docs/ARCHITECTURE.md` ("The exit signal"); don't restate
  it here.
  - **Erkki's binding design (2026-09-10):** the marker is sent both when a
    reminder was already delivered AND when the cart was marked abandoned
    but the reminder has not gone out yet — in Magento both are tracker
    status `mailed`, so one tracker read (`StateManager::rowForQuote()`,
    read before `markCompleted()` overwrites it) answers both. A
    still-`pending` reminder is withdrawn in the same breath. A cart the
    extension never tracked sends nothing; the feature off sends nothing.
  - **Wire-shape deviation from the task brief, recorded honestly.** The
    brief called for a Z-suffixed datetime; the marker ships UTC
    `Y-m-d H:i:s` because Woo's format stands (decision PRO-1723). Why the
    format is load-bearing is stated once, at
    `Trigger::MARKER_STAMP_FORMAT`. Queued for Erkki below.
  - **Verification — the REAL flow on the sandbox, only the Smaily
    transport faked.** Guest quotes built and orders placed through the real
    `CartManagementInterface`, the real `Cron\AbandonedCart` and the real
    `Cron\FlushEventQueue` (handlers built by hand around a recording
    client, since the compiled object manager has no `addSharedInstance()`).
    Four cases, synthetic contacts only: (a) reminder delivered → order →
    `POST contact [{"email":…,"abandoned_cart_purchased_at":"2026-09-10
    17:08:44"}]`, nothing else in the payload; (b) reminder still `pending`
    → order → the reminder row went `sent` with `last_response` `cancelled`
    and was **never POSTed**, while the marker went out; (c) a quote never
    tracked as abandoned → order → no contact row at all; (d) the feature
    switched off between the reminder and the order → order → nothing sent.
  - Gates: 281 unit tests green (1 new), phpcs 0 errors, phpstan clean, 78
    integration tests green (throwaway MySQL — a new case pins the
    withdrawal against the real table). No constructor changed and no new
    injectable class, so no `setup:di:compile` was needed. Sandbox restored
    (see PRO-2467 below — both demonstrations were cleaned up together).
  - Simplified after review (2026-09-10, same gates re-run): one tracker
    read per order (`rowForQuote()` replaced `wasReminded()` +
    `isOptedIn()`), one owner for the marker stamp
    (`Trigger::MARKER_STAMP_FORMAT`), one owner for a queue row's terminal
    close (`markSent()` and the withdrawal share it, which also nulls
    `next_retry_at` on a delivered row — a column nothing reads once a row
    is not pending), and the format rationale de-duplicated down to that
    constant. No behaviour change on the wire or in the store.

- **PRO-2452 done — a GDPR erasure now reaches the store's OWN tables, not
  just the engine (Woo PRO-2383 parity, and it closes the sibling's open
  PRO-2448 rather than shipping it).** `smaily:gdpr erase` asked the engine
  to forget the customer and stopped there; the marketing queue, the ingest
  queue and the abandoned-cart tracker kept the address until the janitor
  pruned them by age (30 / 90 days). `Model\Privacy\LocalEraser` is the
  local half, `Console\Command\GdprCommand` its only caller. How it works —
  the two outcomes, the decoding matcher, the retry guard — is written up in
  `docs/ARCHITECTURE.md` ("Queue semantics"); don't restate it here.
  - **Erkki's binding decision (2026-09-10):** a row that could still send
    (`pending`, `sending`) is DELETED — not sending is the point — while a
    row that is over (`sent`, `failed`) is ANONYMISED in place and kept for
    audit; the contact's abandoned-cart row is ANONYMISED IN PLACE too
    (corrected by PRO-2467 below — it was deleted, which let the cron
    re-mail an erased address whose quote still existed). It deviates from
    Woo, which deletes `failed` because its Event Log Retry revives one:
    here both queues' `retry()` skip an anonymised row instead. `export`
    lists the same set, so the two can never disagree; local runs first, so
    an engine outage costs only the engine half (non-zero exit, re-runnable).
  - **Tables deliberately NOT touched:** `smaily_order_attribution` (order
    id + recommendation id + visitor/session tokens, no contact
    identifier), `smaily_automation_mapping` and `smaily_backfill_job` (no
    personal data).
  - **Simplify pass (follow-up commit).** The abandoned-cart table is the
    state manager's alone again, the queue-table list has one owner
    (`Cron\QueueJanitor::TABLES`), the scan reads a narrow column list in
    1000-row chunks and acts on each chunk in one transaction before reading
    the next, a blob is decoded once for both the match and the redaction,
    and the CLI summary prints merchant-facing labels instead of table
    names. No behaviour change: every acceptance criterion above still holds.
  - Gates: 280 unit tests green, phpcs 0 errors, phpstan clean, 77
    integration tests green (throwaway MySQL — the real command through
    `CommandTester` against the real tables). `setup:upgrade` +
    `setup:di:compile` run in the sandbox (`GdprCommand` and `LocalEraser`
    both gained a constructor argument). Demonstrated on the sandbox with
    synthetic contacts only: 1 removed + 1 anonymised in the marketing
    queue, 1 removed in the ingest queue, 1 abandoned-cart row removed
    (a tombstone since PRO-2467), the
    anonymised row's four blobs all reading `[erased]`. Sandbox restored —
    all seeded rows deleted, 0 smaily config rows, 0 queue rows, 0 jobs.

- **PRO-2451 done — a deactivated Campaign Intelligence account is
  remembered, every send path stops, and the admin says so plainly (Woo
  PRO-1893 / Shopify PRO-1931 parity).** Contract §2 answers `403
  tenant_inactive` when the tenant is deactivated; we had only the per-row
  half of the sender rule, and the health notice blamed an outage that would
  "recover" (the wording PRO-1953 recorded). How it works — the chokepoint
  that records it, the gate that reads it and every call site — is in
  `docs/ARCHITECTURE.md` ("A refused account stops every send path").
  - **Rows are kept, per Erkki's binding decision.** A batch met by a refusal
    is handed back with the new `IngestQueue::release()`: status pending,
    attempts, backoff, error and claim token untouched. The janitor's normal
    age rule still applies.
  - **What the merchant sees.** Settings > Intelligence: the account is not
    active, a link to their Smaily account, a "Check again" button (the
    existing `api/engineping` action) and, in place of the three engine
    imports, why they are gone — the Subscribers contact import is untouched.
    The Dashboard verdict and connection strip name the deactivated account
    ahead of the failure and outage verdicts, and `Cron\HealthCheck` posts a
    notice that promises no recovery and drops the engine-down clock. One
    title + one body sentence, shared by all three surfaces; 405 keys per
    pack, parity verified.
  - **Verification — the real flow on the sandbox with only the transport
    faked.** A stand-in engine answered contract §2's exact 403 body and
    counted every request. The real flusher's FIRST catalog POST met the
    refusal and both queued rows came back `pending / attempts 0 / no error /
    no claim token`; the other domains were never claimed. A second flush, a
    real `BackfillTick` (catalog job → `completed, total 0`) and a storefront
    `POST /smaily/relay` sent **zero** further requests. The real admin stack
    rendered the panel without import buttons and the dashboard strip pill
    "Not active", while a fresh (never-refused) render showed neither and made
    no engine call at all. Recovery was driven both ways — the `EnginePing`
    controller (et_EE string proving the pack) and the health cron unattended
    — clearing the state and letting the waiting rows go out in order.
  - Gates: 270 unit tests green (17 new), phpcs 0 errors, phpstan clean, 71
    integration tests green (throwaway MySQL — a new case asserts the
    released rows against the real table). `setup:upgrade` +
    `setup:di:compile` run in the sandbox (`Engine\Settings` and
    `Cron\BackfillTick` both gained a constructor argument). Sandbox
    restored: engine settings cleared, all queue/job rows, `smaily%` config
    rows, module flags and the Smaily admin notices deleted, capture server
    killed — back to 0 smaily config rows, 0 queue rows, 0 jobs, 0 flags.
  - **Simplify pass (this commit).** Post-review tightening, no behaviour
    change: one `isEngineRefused()` predicate on both view models, the
    dashboard's engine-state ternaries moved behind one view-model accessor,
    the panel's refusal block on the module's own banner pattern and one
    imports card instead of two, one release-on-refusal helper in the
    flusher, the decrypted API key and the dashboard's engine flags memoized
    per request, and the merchant's sentence pair collapsed from three
    near-identical strings to one (405 keys per pack).

- **PRO-1400 + PRO-1484 verified on a CLEAN sandbox install — both release
  gates hold on the evidence; one wording conflict between PRO-1484's ask and
  the engine's own contract needs the engine team, not a code change.**
  "Clean" meant literally fresh: `docker compose down -v` (all three named
  volumes dropped) then `up -d`, so Magento reinstalled from the image through
  `.sandbox/entrypoint.sh`'s `setup:install` against an empty database — no
  accumulated sandbox state, no `module:enable`, no manual `setup:upgrade`.
  - **PRO-1400 — the cron group.** `bin/magento module:status Smaily_Connect`
    → "Module is enabled" straight out of `setup:install`. `etc/cron_groups.xml`
    is demonstrably the file being read: the merged config under
    `system/cron/smaily_connect/*` returns exactly the shipped values
    (`schedule_generate_every=1`, `schedule_ahead_for=4`,
    `schedule_lifetime=15`, `history_cleanup_every=10`,
    `history_success_lifetime=60`, `history_failure_lifetime=4320`,
    `use_separate_process=1`), and `Magento\Cron\Model\Config::getJobs()`
    lists group `smaily_connect` with **8** jobs — `crontab.xml` gained
    `smaily_catalog_resync` in PRO-1951, so the July note's "7 jobs" is stale.
    `cron:run --group=smaily_connect` once a minute for ~20 minutes produced
    real rows and real `pending → success` transitions:
    `smaily_flush_event_queue` / `smaily_flush_ingest_queue` /
    `smaily_backfill_tick` 20 success each, `smaily_abandoned_cart` 4,
    `smaily_contact_reconcile` + `smaily_health_check` 1 each (both first
    appeared `pending` for 12:00 UTC and ran on the next tick). The two daily
    jobs cannot land inside a 4-minute lookahead by construction, so they were
    proved separately: with `schedule_ahead_for` temporarily at 1100 minutes
    the generator emitted `smaily_queue_janitor` for 23:20 and
    `smaily_catalog_resync` for 00:40 — 02:20 / 03:40 Europe/Tallinn, exactly
    the `crontab.xml` expressions. The override row was deleted afterwards and
    the merged value read back as 4. All 8 job codes schedule; none is
    orphaned.
  - **PRO-1484 — method.** The endpoints map was pointed at a capture server
    inside the container, so everything else on the path was real: real
    observers, real `smaily_ingest_queue`, real `Engine\Client`, real cron
    flush. Test product `TEST-XYZ-1` (entity_id **1** — merchant SKU and id
    deliberately unmistakable), its storefront page loaded in headless Chrome,
    and a real guest order placed through the REST quote/order services
    carrying the browser's own cookies.
  - **PRO-1484 (1) canonical identity — one key from all three paths.**
    Catalog ingest sent `"sku":"TEST-XYZ-1"` (with `external_id:"1"` and
    `tags.product_id:"1"` carrying the entity id); the order payload's line
    sent `items[0].sku = "TEST-XYZ-1"`; the browse event sent
    `"sku":"TEST-XYZ-1"`. Identical token from catalog, order line and browse —
    contract §3 "same key from every path" holds. **But the key is the
    merchant SKU, not `mag-<entity_id>`** — which is what
    `RECENGINE_API_CONTRACT.md` v1.8.1 §3 *requires* for Magento: its explicit
    carve-out says Magento's catalog SKU field IS the platform-canonical key
    (mandatory + store-unique), the "never the merchant SKU / never a
    fallback" rule is Shopify/Woo-specific, and `mag-<entity_id>` is the
    fallback **only** for the pathological empty-SKU product, applied
    identically on catalog and order lines (that symmetry IS PRO-1280; unit
    tests `OrderPayloadBuilderTest` assert `mag-42` / `mag-7`). So the code
    matches the contract, and PRO-1484's item as worded ("`sku` =
    `mag-<entity id>`, never a fallback") states the generic rule the contract
    itself exempts Magento from. Queued for Erkki below: the engine team has to
    reconcile its own ask with its own contract text before this item is
    ticked — no code change is warranted either way.
  - **PRO-1484 (2) click capture.** A product page opened with
    `?smaily_rec=<uuid>&smaily_ctx=cross_sell&smaily_vt=…` in headless Chrome
    left first-party cookies `smaily_rec_id` (the UUID verbatim),
    `smaily_rec_ctx`, `smaily_rec_uid`, plus a freshly generated
    `smaily_anon_sid` — all `SameSite=Lax`, path `/`. A guest order placed with
    those cookies wrote one `smaily_order_attribution` row for the order, and
    the order payload carried `smaily_rec_id`, `smaily_visitor_token`,
    `smaily_rec_ctx` and `session_id` alongside the server-derived
    `customer_email`. The contract has no separate click endpoint: the click
    reaches the engine on the order, and — where a visitor token exists — on
    every browse event (`smaily_visitor_token` was present on the third
    captured event).
  - **PRO-1484 (3) browse well-formedness.** Every relayed event carried
    `source: "plugin_magento"` and a Z-suffix `event_ts`. A hand-forged relay
    POST asserting `customer_email`, `source: "plugin_woo"`, `event_ts:
    "1999-01-01 00:00:00"` and `smaily_rec_id: "not-a-uuid"` was accepted
    (`{"ok":true,"accepted":1}`) but forwarded with **none** of them: source
    re-stamped `plugin_magento`, `event_ts` re-stamped server-side, email and
    rec id dropped — the relay accepts no client-asserted identity.
  - **No product code changed**, so no test gates were run. Sandbox restored:
    engine settings disconnected, test product / order / quote / attribution
    row / queue rows deleted, capture server killed (verified back to 0
    products, 0 orders, 0 queue rows, 0 `smaily_connect/%` config rows). The
    fresh install itself is left in place.

- **PRO-1765 done — the contact payload speaks the cross-platform field
  canon: gender ships as `user_gender`, and phone ships at all.** Two
  changes to one wire shape:
  - **`gender` → `user_gender`.** Verified byte-for-byte against the Woo
    plugin's shipped code (`includes/Smaily/SubscriberPayloadBuilder.php`
    `SUPPORTED_FIELDS`, `spec/FIELD_MAPPING.md` §2, decision F2-7): the
    standard is `user_gender` / `user_phone` (Shopify's app carries neither
    field yet, but F2-7 binds it to the same names), so one shopper syncing
    from two stores lands in ONE Smaily field instead of two. The values (`Male` / `Female`) are unchanged.
  - **`user_phone`, new.** The customer's DEFAULT BILLING address
    telephone — the same source the engine's `CustomerPayloadBuilder`
    already quotes, so both wires carry one phone number. Merchant-
    selectable like every sibling field (Woo ships it as a sync-field
    toggle too, not an always-on), on by default for fresh installs.
    Omitted entirely when the customer has none: absent keeps whatever
    Smaily holds, empty would wipe it.
  **The field id IS the wire key here** (`$payload[$field]`), so the rename
  moves the stored selection value too. The only writer of the old `gender`
  value in the wild is the 2.8.x migration, so it maps at the source —
  `Model\Migration\LegacyConfigMapper` now carries a legacy→v3 map and an
  upgraded store's Gender tick lands on `user_gender`. No read-time alias
  exists or is needed: v3 is unreleased, so no v3 store has ever stored a
  selection.
  **One-way-door check, recorded honestly.** v3 (3.0.0, unreleased) has no
  installed base, so nothing that has synced UNDER v3 depends on `gender`.
  But 2.8.x — the released line these stores upgrade FROM via a plain
  `composer update` — did send `gender` on the wire
  (`master:Cron/SubscribersSync.php:191`), so an upgraded store's existing
  Smaily segments and templates that reference `gender` keep matching the
  values last synced by 2.8.x and go stale rather than break. The trade is
  deliberate: cross-platform canon (F2-7's "the same contact from different
  sources must not produce duplicated fields") over one platform's legacy
  name, with the repoint spelled out for merchants in `docs/UPGRADING.md`
  and `CHANGELOG.md`. **Erkki's sign-off on that trade is queued below
  before 3.0.0 ships** — the code change itself is reversible until then.
  **Verification — the REAL import path on the sandbox with only the
  transport faked.** Two real customers created through the real
  `AccountManagementInterface` (one with a default billing telephone and
  gender Female, one with neither address nor phone and gender Male), both
  subscribed through `SubscriptionManagerInterface::subscribeCustomer()`,
  then the real `JobManager::start()` → real
  `Model\Backfill\ContactsProcessor` with a recording `SmailyClient` in
  place of sendsmaily.net. Job `completed 2/2`, one POST to `contact`
  carrying `"user_phone":"+372 555 12345","user_gender":"Female"` and the
  other carrying `"user_gender":"Male"` with **no `user_phone` key at all**
  — and neither payload carries a bare `gender`. The real Settings >
  Subscribers panel was rendered through the real admin block/layout stack:
  its nine checkboxes read `first_name, last_name, prefix, user_phone,
  user_gender, birthday, customer_id, customer_group, subscription_type` —
  the exact list `Config::getSyncFields()` hands the payload builder, so the
  merchant's tick and the sync reader cannot disagree. Gates: 254 unit tests
  green (9 new — the payload builder had NO unit test of its own until now),
  phpcs 0 errors, phpstan clean, 70 integration tests green (throwaway
  MySQL; the legacy-config migration test now seeds `gender` and proves it
  lands as `user_gender` through the real data patch). No
  `setup:di:compile` needed — no constructor changed, no new injectable
  class. Sandbox restored: both customers and their subscriber rows deleted,
  backfill table empty, the single pre-existing config row untouched.

- **PRO-1764 simplify done — the accepted review findings applied.** Three
  things, no new behaviour beyond the one honesty fix:
  - **One owner per context for the import control's off-state.** It was
    derived twice on Settings: server-rendered PHP ternaries in
    `panel/subscribers.phtml` AND `reactSubscribersEnabled()` in
    `panel/panels-js.phtml` (which already hydrates from the boot JSON's
    `subscribers.syncEnabled`). Settings carries the switch on the page, so
    the JS handler owns it there alone and the server render no longer
    second-guesses it. The **wizard** renders no switch — the handler
    early-returns there — so the wizard is the one context that still
    answers server-side, through `WizardData::isSyncEnabled()` (kept for
    exactly that, with a `WizardDataTest` case of its own).
  - **`WizardData::getStoreTotals()` memoised** — three unmemoised
    `getSize()` counts that every render asked for twice (the template and
    the boot JSON); now counted once per request.
  - **An in-flight import is stopped honestly, not rewritten.** The gate
    completed the job with `total_count = 0` unconditionally, so a job that
    had already discovered a real total and sent hundreds of contacts ended
    as "completed, 0" and had its total overwritten. Now only a job that
    never started (`total_count === null`) completes at 0; one switched off
    mid-import is stopped at the page boundary through a new
    `JobManager::cancel()` — the SAME terminal `cancelled` status, guard and
    outcome copy an admin cancel already produces — keeping the total and
    the processed count it really achieved. `docs/USER_GUIDE.md` says so.
  **Verification — both halves driven for real, not read.** The panel was
  rendered through the real admin block/layout stack in both contexts with
  the stored switch off and on: Settings emits the same neutral markup
  either way (no `disabled`, no inline `display` decision), the wizard emits
  `<button … id="smaily-w-backfill" disabled>` + the reason line when off and
  the estimate when on. The Settings half was then driven in jsdom with real
  jQuery over the REAL rendered panel + the REAL `panels-js.phtml`: after
  `initAll()` with `"syncEnabled":false` in the boot JSON the button is
  disabled, the estimate hidden and the reason line shown; ticking the switch
  live flips all three back, and untick flips them again — Cancel enabled
  throughout. The processor was driven through the real
  `JobManager::start()` → real `Cron\BackfillTick::execute()` → real
  `ContactsProcessor` on the sandbox: a never-started job ended
  `completed / total 0`, a job carrying `total 412, processed 150` ended
  `cancelled / total 412 / processed 150` — the overwrite is gone. Gates:
  245 unit tests green (2 new — the in-flight stop, and `isSyncEnabled()`'s
  website scope), phpcs 0 errors, phpstan clean, 70 integration tests green
  (throwaway MySQL). No `setup:di:compile` needed — no constructor changed
  and no new injectable class was added; the sandbox ran the new
  `JobManager::cancel()` through the real DI container instead. Sandbox
  restored: both job rows and the test `sync_enabled` config row deleted —
  confirmed back to the exact pre-test state (0 backfill rows, one
  `internal/last_seen_version` config row).

- **PRO-1764 done — the contact-sync switch now gates the historical
  contacts import too, so one stored answer owns every outbound contact
  path.** The switch gated every live path (`Observer\SubscriberSaveAfter`,
  `Observer\CustomerSaveAfter`, `Observer\OrderPlaced`, the checkout opt-in
  plugin) and the reconcile tick (`Cron\ContactReconcile`), but
  `Model\Backfill\ContactsProcessor` never read it: a store with subscriber
  synchronization switched off still sent its ENTIRE subscriber history the
  moment anyone pressed Import — and the panel quoted the store's totals
  above the button, which reads as a promise.
  **One gate, at the deepest shared seam.** `ContactsProcessor::process()`
  is where the admin start button, `bin/magento smaily:backfill:start
  contacts` and `Cron\BackfillTick` all converge — including a job that was
  already queued when the switch was turned off — so the check lives there
  and nowhere else. It reads the SAME stored answer through the SAME
  accessor the live paths use (`Model\Config::isSyncEnabled($websiteId)`),
  for the job's own website: on a multi-website install each website's job
  is judged by its own switch, which is what makes the per-website scope
  honest rather than a global veto. Switched off, a job that never started
  completes having sent nothing and its `total_count` is **0** rather than a
  subscriber count that promises a sync that will not happen; a job switched
  off mid-import is stopped at the page boundary instead (see the simplify
  pass above). An info line records why either way.
  **Honest copy above it, with ONE owner per context.** The Subscribers
  panel's import button is `disabled` and the store-totals line is replaced
  by "Subscriber synchronization is off for this website, so an import would
  send nothing. Turn it on and save to import." On **Settings** the switch is
  on the page, so the EXISTING `reactSubscribersEnabled()` handler that
  already mutes the panel body owns this state too — hydrated from the boot
  JSON's `subscribers.syncEnabled`, then on every change. The **wizard**
  renders no switch, so that handler early-returns there and the wizard is
  the one context that answers server-side, through
  `ViewModel\Adminhtml\WizardData::isSyncEnabled()` (website-scoped through
  `WebsiteContext`, like every other control on this panel).
  **Cancel deliberately stays enabled** so an import already running can
  still be stopped. One new translation string in both `i18n/en_US.csv` and
  `i18n/et_EE.csv` (393 keys each, parity re-verified).
  **Deliberate scope fences:** the panel's gate is judged against the
  website the page is rendered for, while the admin start button still
  starts one job per website (backfill URLs stay installation-wide until
  RFC Phase 4/5) — the processor gate is what makes that correct per
  website; and the CLI keeps queueing a job rather than refusing outright,
  because the processor gate makes that job send nothing and
  `smaily:backfill:status` then shows it as `completed 0 / 0`.
  **Verification — the REAL import flow on the sandbox with only the
  transport faked, driven both ways, not just green units.** Wrote fake
  connected credentials at website scope, created a real newsletter
  subscriber through `SubscriptionManagerInterface::subscribe()`, then ran
  the real `JobManager::start()` → real `Cron\BackfillTick::execute()` →
  real `ContactsProcessor` with a recording `SmailyClientProvider`/
  `SmailyClient` subclass in place of sendsmaily.net. **Off:** job
  `completed`, `processed=0`, `total_count=0`, **0 transport posts** (and
  the subscribe itself queued no event either — the live gate agreeing).
  **On:** job `completed`, `processed=1`, `total_count=1`, **1 post** to
  `contact` carrying the real subscriber payload
  (`{"email":"pro1764@example.com","is_unsubscribed":0,…}`). The panel was
  rendered through the real admin block/layout stack in the `settings`
  context both ways: off → `<button … id="smaily-w-backfill" disabled>`
  with the reason paragraph visible and the totals line hidden; on → the
  button enabled and the totals line back. Gates: 243 unit tests green (2
  new — a new `Test/Unit/Model/Backfill/ContactsProcessorTest` pinning both
  directions with the transport faked; plus a `SubscriberCollectionFactory`
  unit stub, since Magento factory classes are generated at runtime), phpcs
  0 errors, phpstan clean, 70 integration tests green (throwaway MySQL).
  Sandbox `setup:upgrade` + `setup:di:compile` both green
  (`ContactsProcessor` gained a constructor dependency). Sandbox restored:
  the test subscriber, every backfill job row and every `smaily_connect/*`
  config row except `internal/last_seen_version` deleted, cache flushed —
  confirmed back to the exact pre-test state (0 subscribers, 0 backfill
  rows, 0 queue rows, one config row).

- **PRO-1951 simplify done — the accepted review findings applied.
  `Model\Engine\CatalogIngest` is now the ONLY place a product becomes a
  catalog row**, and the three copies of build-log-enqueue that survived the
  first pass are gone. Behaviour is unchanged except for two deliberate
  strengthenings, both of which close real holes:
  - **The stock-memo drop moved into `CatalogPayloadBuilder::isInStock()`**,
    immediately before its own stock read, instead of living in one caller.
    The builder is the only reader, so every path is now correct by
    construction — including the backfill/nightly-resync pages, which
    previously read the stale per-request memo and could have re-published
    the very `in_stock` PRO-1951 fixed. `CatalogIngest` dropped its
    `StockRegistryStorage` dependency with it.
  - **`Model\Backfill\EngineCatalogProcessor` and
    `Observer\Engine\ProductDeleteBefore` now go through the funnel too.**
    The processor calls `CatalogIngest::enqueueProduct()` (which gained a
    `bool` return so its per-item processed/failed counting is unchanged);
    the delete observer calls a new explicit
    `CatalogIngest::enqueueTombstone()` — forced, because at
    `catalog_product_delete_before` the product is still perfectly
    ingestible, so the funnel cannot infer the tombstone. Both therefore
    gain the collapse and the fresh stock read they silently skipped. One
    visible consequence, accepted: a backfill/resync page now tombstones a
    disabled or hidden product instead of skipping it silently — which is
    what a reconciler is for (a product disabled by a CSV import was
    otherwise never tombstoned).
  Everything else is like-for-like: the engine-connected gate moved out of
  `ProductSaveAfter`/`StockItemSaveAfter`/the MSI plugins into
  `CatalogIngest` (one gate, one test, three fewer `Settings` dependencies);
  `Plugin\Engine\MsiStockWriteAfter` split into
  `Plugin\Engine\SourceItemsSave` and `Plugin\Engine\SourceDeduction`, one
  thin class per DI seam, so the array-vs-object payload sniffing disappears
  and duck typing survives only at `getSku()` (the no-MSI-types-named
  constraint is kept — `mixed` signatures, so an install without the
  Inventory modules still compiles); `Cron\CatalogResync` dropped its
  `findActive` pre-check in favour of the `start()` exception the
  one-active-job lock already throws; `Job::ENGINE_WEBSITE_ID` replaces the
  third literal copy of "engine jobs run as website 0"
  (`Cron\CatalogResync` + `Controller\Adminhtml\Api\BackfillState` +
  `Console\Command\BackfillStartCommand`); and the collapse memo is one
  `crc32(json_encode($item))` instead of a product id plus a resident
  full-payload deep compare.
  **Verification — the real REST flow on the sandbox again, not just green
  units.** Faked a connected engine tenant, created a real product through
  `ProductRepositoryInterface::save()`, then drove real
  `PUT /V1/products/PRO1951-REFACTOR/stockItems/9` calls over HTTP with a
  real admin token: selling out (`qty 0, is_in_stock false`) produced exactly
  **one** row with `in_stock: false` (the legacy observer and the MSI plugin
  still collapse into one), restocking produced exactly **one** row with
  `in_stock: true` — the back-in-stock signal itself, proving the memo drop
  still happens now that it lives in the builder — and with
  `intelligence/connected` flipped off the same PUT produced **zero** rows,
  proving the gate that moved into `CatalogIngest` still fires end-to-end
  through the real hooks. Gates: 241 unit tests green, phpcs 0 errors,
  phpstan clean, 70 integration tests green (throwaway MySQL). Sandbox
  `setup:upgrade` + `setup:di:compile` both green (new plugin classes, and
  `CatalogIngest`/`CatalogPayloadBuilder`/`ProductDeleteBefore`/
  `EngineCatalogProcessor` constructor changes). Sandbox restored: the
  fixture product, its stock/source rows, every ingest row and every
  `smaily_connect/intelligence/*` config row deleted, reindexed and
  cache-flushed — confirmed back to the exact pre-test state (0 products,
  0 ingest/backfill rows, one `internal/last_seen_version` config row).
  Deferred-build/batching (PRO-1967) and drift-proportional resync
  (PRO-1968) were explicitly out of scope and not started.

- **PRO-1951 done — catalog ingest hears every stock change, and a nightly
  re-sync reconciles what no event can see. The latency we can now claim is
  "~1–2 min for anything the store emits an event for, at most ~24 h for
  everything else" — the "otherwise unbounded" the engine team was told is
  gone.** Catalog ingest hooked exactly one event,
  `catalog_product_save_after`, so the one catalog field the store can move
  without a product save — `in_stock`, the very field the engine's
  back-in-stock detection reads — was invisible whenever it moved any other
  way.
  1. **Event coverage: two new hooks, both gated on `Settings::isConnected()`
     like the existing observers, both funnelling through one new
     `Model\Engine\CatalogIngest` (which `Observer\Engine\ProductSaveAfter`
     now delegates to as well, instead of keeping a third copy of
     build-and-queue).**
     - `Observer\Engine\StockItemSaveAfter` on
       `cataloginventory_stock_item_save_after` — the legacy path: Advanced
       Inventory, `PUT /V1/products/{sku}/stockItems/{id}`, and on an install
       WITHOUT MSI the order decrement and credit-memo restock.
     - Two MSI plugins, one per seam (since the simplify pass above:
       `Plugin\Engine\SourceItemsSave` and `Plugin\Engine\SourceDeduction`;
       originally one `MsiStockWriteAfter` on both):
       `InventoryApi\Api\SourceItemsSaveInterface` (Sources grid, product-form
       quantity, `POST /V1/inventory/source-items`) and
       `InventorySourceDeductionApi\Model\SourceDeductionServiceInterface`
       (the shipment deduction and the credit-memo return to stock).
       **MSI treated as an optional dependency, and deliberately so:** the
       plugins name no MSI type anywhere (`object`/`mixed` signatures, sku
       read by duck typing), because a plugin declared on a class that does
       not exist is simply never wired — an install with the Inventory
       modules removed still compiles and runs on the legacy observer alone.
       `sortOrder="100"` keeps it last in the after-chain, behind MSI's own
       legacy-stock sync.
  2. **Periodic reconciler: `Cron\CatalogResync`, daily `40 3 * * *` store
     time** (off-peak, just after the 02:20 queue janitor so the sweep never
     queues into a table being pruned; cadence + rationale documented in
     `docs/ARCHITECTURE.md`'s cron table and ingest section). It is **not a
     walker of its own** — it starts an ordinary catalog backfill job and lets
     `Cron\BackfillTick` page it in with the same cursor, time budget and
     flood guard a merchant-started import gets. It never runs disconnected,
     and the one-active-job lock refuses the start outright, so a merchant's own import is
     never trampled — the sweep skips that night instead (the explicit
     `findActive` pre-check was dropped in the simplify pass above; the lock
     alone carries it). It exists for the
     one class of change events cannot see: a CSV / `bin/magento import` run
     writes the catalog tables directly.
  3. **Decisions recorded (no behaviour changed), in `docs/ARCHITECTURE.md`
     and `BACKLOG.md`:** `in_stock` stays sourced from the **legacy
     `is_in_stock` flag**, which MSI keeps synced — MSI's salable-per-website
     quantity is deliberately deferred to the multi-website tenant work,
     because a catalog row is keyed on `sku` per tenant and one installation
     is one tenant, so a per-website answer has nowhere to go until RFC Phase
     4 (gated on PRO-1459) gives each website its own tenant. The on-exception
     `default true` in `CatalogPayloadBuilder::isInStock()` is **judged
     deliberate and left alone**: the engine excludes out-of-stock products
     from recommendations, so defaulting to false would silently pull a
     product out of every campaign over a transient read error — the worse
     failure — and the nightly reconciler now corrects a wrong `true` within a
     day.
  **Three things the sandbox caught that the unit tests could not, all fixed
  before the final commit:**
  - **On a default 2.4.x install, placing an order decrements nothing.** MSI
    writes a reservation; `is_in_stock` is untouched, so there is correctly no
    catalog row. The real "order decrement" is the shipment's source
    deduction, which goes through `SourceDeductionService`, **not**
    `SourceItemsSave` — as does the credit-memo return to stock. The first
    implementation hooked only `SourceItemsSave` and would have missed both.
  - **A stale `in_stock` on the wire.** The stock registry memoises the stock
    item per request and MSI mirrors onto the legacy row with direct SQL, so
    it never invalidates that memo: shipping the last unit queued a row saying
    `in_stock: true`. The memo is now dropped for the product before the
    stock is read (in `CatalogIngest` originally; moved into
    `CatalogPayloadBuilder::isInStock()` by the simplify pass above).
    Re-verified live in both directions.
  - **The "did the stock actually move?" gate was dead code.** Magento hands
    stock items to its writers through `StockRegistryProvider`, which loads
    through the resource model and never calls `setOrigData()`, so every save
    looks like a change — a plain product rename tripped it. Removed; and
    because removing it left one product save queueing three identical rows
    (product save, the legacy stock item it writes, the MSI source item that
    mirrors), `CatalogIngest` collapses a **byte-identical row queued twice in
    a row within one request**. That is NOT queue-wide dedupe (explicitly out
    of scope): two real stock moves still queue two rows.
  **Verification — the real flows on the sandbox, not just green units.**
  Faked a connected engine tenant, created a real product through
  `ProductRepositoryInterface::save()`, then drove genuinely real flows and
  read `smaily_ingest_queue` after each: a real guest order placed through
  quote → `CartManagementInterface::submit()` produced **no** catalog row
  (correct — reservation only, legacy stock untouched at 7/in-stock); a real
  offline invoice + `ShipmentFactory` shipment taking the source 7 → 0
  produced one row with **`in_stock: false`**; a real credit memo
  (`CreditmemoFactory::createByOrder()` + `CreditmemoManagementInterface::
  refund()`, back-to-stock) restoring 0 → 7 produced one row with
  **`in_stock: true`** — the back-in-stock signal itself; a real
  `PUT /V1/products/PRO1951-TENT/stockItems/8` over HTTP with a real admin
  token produced exactly **one** row (`in_stock: true`, the two overlapping
  hooks collapsed); a real `POST /V1/inventory/source-items` with
  `quantity: 0, status: 0` produced one row with `in_stock: false`. A plain
  product rename produced exactly **one** row (3 before the collapse). Then
  the reconciler: a direct-SQL stock write (what a CSV import is, at the table
  level) produced **zero** rows, `Cron\CatalogResync::execute()` queued exactly
  one `catalog:engine` job, a **second** run while that job was open queued
  **nothing** (the no-trample check), and `Cron\BackfillTick::execute()`
  completed it and emitted the corrected catalog row. Finally, with
  `intelligence/connected` flipped off, both REST stock calls and the resync
  cron produced zero rows and zero jobs. Gates: 242 unit tests green (22 new —
  `CatalogIngestTest` (8), `StockItemSaveAfterTest` (3), `MsiStockWriteAfterTest`
  (7), `CatalogResyncTest` (4)), phpcs 0 errors, phpstan clean, 70 integration
  tests green (throwaway MySQL). Sandbox `setup:upgrade` +
  `setup:di:compile` both green (new observer, plugin, cron job, and
  `ProductSaveAfter`/`CatalogIngest` constructor changes). Sandbox restored:
  the fixture product, its stock/source rows, every order/invoice/shipment/
  credit-memo/quote/reservation and every ingest, backfill and
  `smaily_connect/intelligence/*` row deleted, reindexed and cache-flushed —
  confirmed back to the exact pre-test state (0 products/orders/quotes/ingest/
  backfill rows, one `internal/last_seen_version` config row).

- **PRO-1800 / PRO-1763 done — the Smaily event queue adopts the
  cross-platform retry classification (Woo's PRO-1685 `RetryPolicy`), so a
  refusal that can never succeed stops on the first attempt.** Until now every
  `SmailyClientException` took the same road: `MAX_ATTEMPTS = 5` on the
  `[60,300,900,3600,21600]` ladder, whatever the HTTP status said. A revoked
  credential, a deleted workflow or a rejected address was therefore re-POSTed
  five times across about 81 minutes (60 + 300 + 900 + 3600 s; the fifth
  failure parks the row, so the 21600 s step is never waited — "six hours"
  here was wrong, corrected 2026-10-05, PRO-3753) before the merchant's
  failed count noticed it,
  and a 429 ignored the slow-down Smaily explicitly asked for.
  Now, in one place — the new `Model\Queue\RetryPolicy`, injected into
  `Cron\FlushEventQueue`:
  - **4xx except 429 → permanent.** The row is parked failed on the FIRST
    refusal via `EventQueue::markFailed(…, terminal: true)` — the same writer
    and the same columns as any other failure (the sibling
    `Engine\Queue\IngestQueue::markFailed()`'s shape) — with the reason
    `permanent_http_<code>: <message>` — the sibling's exact naming, verified
    against Woo's shipped `RetryPolicy::apply()`, not just the brief. The
    attempt that WAS refused is counted (attempts 1 of 5); the other four are
    never spent. Merchant-visible immediately: the Log grid, the
    failed-deliveries banner and the dashboard tile all read the failed state
    they already read.
  - **429 → parked for exactly the `Retry-After` the response named**, capped
    at the ladder's own 6 h ceiling so a wild header cannot park a row for
    days. Delta-seconds only, matching Woo: an HTTP-date is deliberately NOT
    parsed (Woo's own comment says Smaily sends the delta form) and falls back
    to the ladder step rather than being mis-read.
  - **5xx and transport errors → today's ladder and ceiling, untouched.** A
    Smaily error envelope on HTTP 200 (`ApiException`, e.g. code 203) carries
    no HTTP status and so also stays retryable exactly as before — the policy
    is biased toward retrying, like the sibling, because mis-classifying a
    recoverable failure drops genuine work.
  **The claim/lease model (`claimBatch` + `requeueStale`) is untouched** — it
  is ahead of the siblings and deliberately preserved.
  **Minimal plumbing, because the status was being thrown away twice.**
  `TransportException` gains a `retryAfter` next to the HTTP status it already
  carried, and `SmailyClient` parses the `Retry-After` header off the failed
  response (its `post()` `@throws` gained the `TransportException` it has
  always been able to raise). The two Smaily handlers
  (`ContactSyncHandler`/`AutomationHandler`) now hand the exception itself to
  the flusher instead of flattening it to `getMessage()` — the flusher is
  where the queue row is, so it is where the classification has to happen;
  `EventHandlerInterface`'s contract widens to
  `true|string|SmailyClientException` to say so.
  **Scope fences honoured:** the engine ingest queue was checked, not touched
  — `Engine\Client` already treats non-429 4xx as terminal AND already honours
  429 by raising its retry delay to the contract's own
  `retry_after_seconds` body field (§2), so there was nothing to align.
  `IdentityMergeHandler` left alone as instructed.
  **Log honesty fixed in the same pass, because this change made the old copy
  wrong:** the Details panel told every failed row "All 5 automatic attempts
  are used up", which a row stopped after one refusal makes untrue (and which
  the ingest queue's terminal rows already made untrue today). A failed row
  with attempts still on the clock now reads "Stopped after 1 of 5 attempts —
  retrying could not change the outcome…", and the reason itself is already
  surfaced by the panel's existing "Last error" block. One new translation
  string in both `i18n/en_US.csv` and `i18n/et_EE.csv` (392 keys each, parity
  re-verified). `docs/USER_GUIDE.md`'s Log section and
  `docs/ARCHITECTURE.md`'s "Queue semantics" both updated in the same commit.
  **Verification — the three failure classes driven through the REAL flush
  path with only the transport faked, not merely unit tests.** New integration
  cases in `Test/Integration/Cron/FlushEventQueueTest.php` run the real
  `Cron\FlushEventQueue` → real `ContactSyncHandler` → real `SmailyClient`
  against real MySQL, with a Guzzle `MockHandler` standing in for
  sendsmaily.net, so the status and the `Retry-After` header are parsed by the
  shipping code: a 404 lands `status=failed, attempts=1, next_retry_at=NULL,
  last_error LIKE 'permanent_http_404%'`; a 429 with `Retry-After: 90` lands
  pending at exactly now+90 s; a 429 without the header lands on the ladder's
  first step; two consecutive 503s climb 60 s → 5 min with attempts 2; a
  connect timeout (no status at all) stays on the ladder. Gates: 220 unit
  tests green (17 new — a new `Test/Unit/Model/Queue/RetryPolicyTest`, plus
  EventQueue's Retry-After/cap/permanent-park cases, two `FlushEventQueue`
  routing cases and two `SmailyClient` header cases), phpcs 0 errors, phpstan
  clean, 70 integration tests green (throwaway MySQL, 5 new). Sandbox
  `setup:upgrade` + `setup:di:compile` both green (`FlushEventQueue` gained a
  constructor dependency).

- **PRO-1761 done — the automation marker canon is adopted: each trigger
  writes its own per-trigger last-run timestamp.** `welcome_automation_at`,
  `first_order_automation_at`, `abandoned_cart_automation_at`, value = that
  run's own clock as `Y-m-d H:i:s` in **UTC** (NOT the engine wire's Z-suffix
  rule — this is the Smaily contact-field canon), written on every run,
  last-writer-wins. The names are **verified byte-for-byte against Woo's
  shipped implementation** (`../connect/includes/Smaily/AutomationMarker.php`),
  not just against the brief; the trigger slugs already matched
  (`Model\Automation\Trigger`'s vocabulary is the same `welcome` /
  `first_order` / `abandoned_cart`).
  The canon lives as `Trigger::MARKER_FIELDS` — next to the trigger vocabulary
  it belongs to, with the "merchant-visible and permanent, add a name, never
  repurpose one" rationale in its docblock — and the stamp is taken in
  `Model\ContactSync\SyncDispatcher::dispatchAutomation()`, the ONE funnel all
  three triggers (`Observer\SubscriberSaveAfter`, `Observer\OrderPlaced`,
  `Cron\AbandonedCart`) already dispatch through. No new class, no new
  dependency, no DI change: 1 const + 3 lines. Stamping there is also the
  correct MOMENT — it is where the trigger fires, so a queue retry resends the
  moment the store event happened rather than the moment it was delivered.
  Existing template fields are untouched: `is_abandoned_cart`, `is_first_order`
  and the 70-key `product_*` matrix keep their exact names and meanings — the
  markers ride alongside. A trigger writes only its own field; an automation
  that did not fire sends no marker at all (absent leaves whatever Smaily
  already holds intact, `''` would wipe it), and the contact-sync payload
  carries none.
  **Verification — the real trigger paths on the sandbox with the transport
  faked, not just green units.** Faked a connected Smaily account, enabled all
  three triggers with config-default workflows (101/102/103, multilingual mode
  `single`), then drove genuinely real flows: a real newsletter subscription
  through `SubscriptionManagerInterface::subscribe()` (firing the real
  `newsletter_subscriber_save_after`), a real first order for a brand-new
  customer through the quote → `CartManagementInterface::submit()` path, and a
  real idle guest quote picked up by `Cron\AbandonedCart::execute()`. The four
  resulting `smaily_event_queue` rows show exactly one marker each on the three
  automation rows and **NONE** on the `contact.sync` row. Then the last hop:
  the REAL `Model\Queue\Handler\AutomationHandler` was run over those events
  with only the transport faked (a `SmailyClientProvider`/`SmailyClient`
  subclass recording `post()` instead of reaching sendsmaily.net) — the three
  captured `/api/autoresponder.php` bodies carry
  `{"welcome_automation_at":"2026-08-10 19:02:59"}`,
  `{"first_order_automation_at":"2026-08-10 19:05:37"}` and
  `{"abandoned_cart_automation_at":"2026-08-10 19:05:37"}` respectively,
  alongside the untouched `is_first_order`/`order_id`/`order_total` and
  `is_abandoned_cart` + 70 `product_*` keys. Gates: 203 unit tests green (5 new
  — a new `Test/Unit/Model/ContactSync/SyncDispatcherTest`: a data-provided
  case per trigger asserting the name, the `Y-m-d H:i:s` shape, that the value
  falls between the clock readings either side of the call and that no OTHER
  trigger's marker appears; one that existing address fields survive; one that
  contact sync carries no marker), phpcs 0 errors, phpstan clean, 65
  integration tests green (throwaway MySQL). No `setup:di:compile` needed — no
  constructor signature changed and no new injectable class was added; the
  sandbox ran the real observers/cron/handler against the bind-mounted module
  instead, which is the stronger check. `docs/USER_GUIDE.md` gained a
  "Segmenting on when an automation last ran" section (the field table, the
  UTC format, the "an event that has not fired writes nothing" rule and the
  cross-platform promise) and `CHANGELOG.md` a matching 3.0.0 feature line.
  Sandbox restored: the fixture product, customer, subscriber, order, quotes,
  event-queue rows, the abandoned-cart state row the run created and every
  `smaily_connect/*` config row deleted — confirmed back to the exact pre-test
  state (0 products/customers/orders/quotes/subscribers/queue rows, the one
  pre-existing `smaily_abandoned_cart` row, and one
  `internal/last_seen_version` config row).

- **PRO-1760 done — abandoned-cart product details are no longer gated
  on a merchant field selection, and every slot is written on every send.**
  `Model\AbandonedCart\PayloadBuilder` no longer reads
  `automations/abandoned_fields`: all seven product fields ride every
  reminder, and `product_<field>_1..10` is prefilled `''` for all ten slots
  before the cart's own items overwrite theirs. Two real defects fixed at
  once (the same shape Woo fixed in PRO-1680): an empty stored selection —
  which is what a fresh install got the moment the merchant unticked every
  box — sent a reminder with NO product data at all; and, because slots were
  only written when non-empty, a second, smaller cart left the previous
  larger cart's products lingering on the Smaily contact (Smaily leaves an
  absent field intact and overwrites an empty one, so writing the full matrix
  IS the clearing mechanism). `build()` lost its now-unused `$websiteId`
  argument. `docs/USER_GUIDE.md`'s abandoned-cart section updated to say
  there is nothing to configure.
  **The selector itself is retired, not just ignored.** Its only UI (the
  7-checkbox block inside the Automations tab's abandoned-cart card) is gone
  (`panel/automations.phtml`, `panel/panels-js.phtml`'s collect, the
  `.smaily-store-trigger__fields` CSS); `WizardStepSaver::saveAutomations()`
  no longer writes it, `WizardData::getSelectedAbandonedFields()` is deleted,
  and so are `Model\Config::getAbandonedFields()`/`XML_PATH_ABANDONED_FIELDS`,
  the `system.xml` field, its `etc/config.xml` default and the whole
  `Model\Config\Source\AbandonedFields` source model (the product-field
  vocabulary now lives in one literal row map inside `PayloadBuilder`, its
  only consumer). The 2.8.x migration's `abandoned/productfields` mapping is
  removed too — migrating a value nothing reads is dead work.
  **Config path abandoned in place, per the 2.8.x constraint — NOT renamed or
  reused:** `automations/abandoned_fields` simply stops being read or written;
  any stored value is left orphaned in `core_config_data`, never migrated or
  cleaned up. Nine now-unused translation strings (the 7 field labels, the
  block heading, the native comment) removed from both `i18n/en_US.csv` and
  `i18n/et_EE.csv`, en↔et parity re-verified (391 keys each, same set).
  `docs/UPGRADING.md`'s migration table and behaviour-changes list updated.
  **Separate, pre-existing defect found while verifying live — the whole
  abandoned-cart cron was fataling on every run, so no reminder has ever been
  sent by v3.** `Cron\AbandonedCart`'s quote query filtered on an unqualified
  `updated_at` while `requireAnyEmail()` (PRO-1275) LEFT JOINs
  `quote_address`, which has an `updated_at` of its own — MySQL rejects the
  SELECT outright with "Column 'updated_at' in where clause is ambiguous",
  before any row is read, on every install. Fixed by qualifying every filter
  column with `main_table.` (not just `updated_at`, so the next filter on a
  shared column name can't reintroduce it); nothing else about the selection
  changed. Not
  caught by any test because the cron's collection query is only exercised
  against a real `quote` table, which neither the unit nor the integration
  suite has — flagged as a coverage gap.
  **Verification — the real cron flow on the sandbox, transport faked, not
  just green units.** Faked a connected Smaily account, enabled the
  abandoned-cart trigger (cutoff 10 min), created two real simple products
  through `ProductRepositoryInterface::save()`, then built two genuinely real
  guest quotes for ONE contact through `Quote::addProduct()` +
  `CartRepositoryInterface::save()` and ran `Cron\AbandonedCart::execute()`
  after each. The queue rows (the exact payload the flusher would POST —
  nothing was flushed, the account is fake) show: cart 1 (2 × Tent, 3 × Mug)
  → **70 `product_*` keys, 14 filled, 56 empty**; cart 2 (1 × Mug) → **70
  keys, 7 filled, 63 empty**, with `product_name_2`/`product_sku_2` etc.
  explicitly `''` — i.e. the second reminder carries ONLY the second cart and
  actively clears the first. A third run with a stale
  `automations/abandoned_fields = 'sku'` written into `core_config_data`
  produced the identical full 70-key matrix, proving a stored selection is
  ignored rather than honoured or migrated (the row was left in place, then
  removed with the rest of the test config). The Automations panel template
  was rendered through the real admin block/layout stack in the `settings`
  context: `smaily-w-abandoned-fields`, the "Abandoned Cart Product Fields"
  heading and `smaily-store-trigger__fields` are all absent while the rest of
  the abandoned-cart card (enable toggle, workflow select, cutoff input) still
  renders. Gates: 198 unit tests green (2 new pinning the always-full matrix
  and the empty unused slots; 4 selector-save tests deleted with the feature),
  phpcs 0 errors, phpstan clean, 65 integration tests green (throwaway MySQL),
  sandbox `setup:upgrade` + `setup:di:compile` both green (`PayloadBuilder`
  lost a constructor dependency). Sandbox restored: every fixture product,
  quote, ingest/event-queue row, abandoned-cart state row and
  `smaily_connect/*` config row created for the test deleted, reindexed and
  cache-flushed — confirmed back to the exact pre-test state (0 products, 0
  quotes, 0 event-queue rows, the one pre-existing `smaily_abandoned_cart`
  row, and one `internal/last_seen_version` config row).

- **PRO-1762 done — engine contract synced v1.5.0 → v1.8.1, and every wire
  change carried through code + tests in the same pass.**
  `docs/RECENGINE_API_CONTRACT.md` overwritten byte-identical from engine main
  (`bin/check-contract-staleness.sh` green against the local `../re`
  checkout, engine commit `bfebf94`). Three deltas needed code:
  1. **Catalog `currency`** (v1.7.0 §3, optional). Every catalog row now
     carries it; orders have carried `currency` since v1.4.0, so the two
     ingest paths disagreed on what a price was denominated in.
     **First implementation was wrong and the sandbox caught it, not the unit
     tests:** it sent the canonical store's BASE currency, on the reasoning
     that Magento authors catalog prices in base. Exercised live against a
     store with base USD / display EUR, the emitted price was 16.25 (Magento's
     own price readers — `RegularPrice`/`BasePrice`, which `final_price`
     resolves through — already convert into the display currency) while the
     label said USD: a price stated in a currency it was never charged in,
     strictly worse than sending no currency at all. Now sends the canonical
     store's **default display** currency, so both halves come from one place;
     re-verified live in all three configurations (EUR/EUR → EUR at 22.99,
     USD/EUR → EUR at 16.25, USD/USD → USD at 22.99). `'EUR'` fallback when no
     store resolves, matching the contract default. **EUR stores are unchanged
     engine-side** — the engine's column default is `EUR`, so an explicit
     `"EUR"` stores identically.
     **Scope-relaxation question answered: NO, the field does not unlock it,
     and the constraint was not relaxed.** The old code comment justified
     `canonicalStoreId()`'s single-store-scope pin with "there is no currency
     field in the wire contract" — that justification is now stale, but the
     constraint stands on independent grounds: §3 still states "one currency
     per tenant remains the assumed model", and a catalog row is keyed on
     `sku` **per tenant**, so a second store scope's rows would upsert onto
     the SAME row — they can only overwrite each other, never coexist.
     Per-scope catalog rows require a tenant per scope, which is the
     multi-website RFC's Phase 4 (gated on PRO-1459), not this field. Comment
     rewritten to say so; no behaviour change to the scope pin.
  2. **Deprecated browse hints** (v1.7.0 §6). `Engine\BrowseEventValidator`
     no longer accepts `smaily_rec_id` / `smaily_ctx` from the anonymous
     beacon: the engine stopped persisting and consulting both (dropping the
     4th-priority attribution fallback they fed), yet still UUID-validates
     `smaily_rec_id`, so a truncated cookie could reject an otherwise good
     event for a value nothing reads. Both trackers (`view/frontend/web/js/
     tracker.js` and the Hyvä `compat/` copy) stop echoing the cookies onto
     the beacon — the cookies themselves and `attribution.js`'s writes are
     untouched, because the order-level cookie→order attribution path (§5,
     `Engine\AttributionManager` → `smaily_order_attribution` →
     `OrderPayloadBuilder`) is the one that actually works and is unchanged.
     The validator's pre-existing refusal of client-asserted `customer_email`
     is kept as-is (it was already correct, and covers PRO-1502's browse
     deprecation too).
  3. **Order return signals** (v1.8.0 §5). `items[].returned_at` is derived
     from the order's own credit memos on every build, never from a one-shot
     event — the engine replaces an order's items wholesale on re-ingest, so a
     later sync that omitted the field would ERASE a return it already had;
     deriving at send time means the live observer, a flusher retry and the
     order backfill all re-send it for free. A line is marked returned only
     once its FULL quantity has been credited (§5: a partly credited line is
     still owned by the customer, so it stays KEPT — this is also PRO-1806's
     clarification, verified live); quantities accumulate across memos and the
     memo that COMPLETES the line dates the return. The memos are read with one
     joined select over `sales_creditmemo_item`, and only for orders whose
     `total_refunded` is non-NULL — a memo has then touched the order at least
     once, so the overwhelming majority of builds skip the query entirely.
     **Neither reason field is sent** (`return_reason_
     standardised` / `return_reason_raw`): Magento Open Source has no
     structured return taxonomy anywhere, and §5 is explicit that guessing one
     is worse than sending nothing. **A second, load-bearing gap this
     uncovered:** `Observer\Engine\OrderSaveAfter` only enqueued when the order
     STATE moved — and a Magento partial credit memo does not move the state at
     all, so the return signal would never have left the store. The gate now
     also fires when `total_refunded` changes.
  **Doc-only siblings verified, no code needed:** PRO-1845 (v1.8.1 —
  `403 tenant_inactive` now also covers purged tenants): `Engine\Client`
  treats every non-429 4xx as a non-retryable `EngineRequestException`, the
  ingest flusher marks such a batch terminally failed rather than rescheduling
  it, and the admin is notified through `Cron\HealthCheck` (ping fails the
  same way) plus the Log page's failed-deliveries banner — the §2 sender rule
  exactly; grep-confirmed that `tenant_status` appears nowhere in the codebase,
  so nothing branches on that fixed string. PRO-1536 (§7 identity/merge
  errata): `browse_events_already_bound` is referenced nowhere — our merge
  handler reads no response fields at all. PRO-1502 (v1.6.0): browse
  `customer_email` was already refused; `tags.category_defaulted` is NOT sent
  and our `categoryPath()` substitutes the literal `'uncategorized'` for a
  product with no categories — exactly the placeholder case the flag exists
  for — logged as a follow-up rather than folded into this sync's scope.
  **Verification — real payloads through the real builders/observers on the
  sandbox, not just green units.** Stood up a mock engine inside the container
  and pointed the stored endpoints map at it (via the real
  `Settings::storeExchange()` seam), then drove genuinely real flows: two
  products created through `ProductRepositoryInterface::save()` (catalog rows
  captured on the wire carrying `"currency": "EUR"`), a real guest order placed
  through the quote → `CartManagementInterface::placeOrder()` path (1 × line A,
  3 × line B), an offline invoice, then two real credit memos through
  `CreditmemoFactory::createByOrder()` + `CreditmemoManagementInterface::
  refund()`. Captured wire evidence: the invoice save produced NO queue row
  (the gate stays tight); credit memo #1 (line A 1/1, line B 1/3) DID produce
  one **while the order state never left `new`** — the exact case the old gate
  missed — and its payload carried `returned_at` on line A only, line B still
  kept; credit memo #2 (line B 2/3 more) produced a payload where line B
  carries the LATER memo's timestamp and line A's return was **re-sent, not
  erased**, demonstrating the re-send-on-resync rule on a real second sync. A
  real `POST /smaily/relay` beacon carrying `smaily_rec_id`, `smaily_ctx` and
  a spoofed `customer_email` was forwarded to the engine with all three
  stripped and `smaily_visitor_token` intact. Gates: 200 unit tests green (8
  new: 2 catalog currency, 3 order return signals, 1 browse hint, 3
  `OrderSaveAfterTest` — a new file, pinning the refund-aware gate), phpcs 0
  errors, phpstan clean, 65 integration tests green (throwaway MySQL), sandbox
  `setup:upgrade` + `setup:di:compile` both green (`OrderPayloadBuilder` gained
  two constructor dependencies). Sandbox restored: every fixture product,
  order, invoice, credit memo, quote and ingest-queue row deleted, the faked
  `smaily_connect/intelligence/*` config removed, currency config returned to
  EUR/EUR/EUR, reindexed — confirmed back to the exact pre-test 21-row
  `core_config_data` state with zero products/orders/memos/queue rows.

- **PRO-1468 (Intelligence sync toggles) done — the three per-entity
  Catalog/Customers/Orders sync toggles removed per target-spec §4.2
  decision 4: connecting the engine now syncs everything, no on/off
  switch.** These toggles' only UI (the wizard/Settings Intelligence panel's
  three checkboxes) is gone (`panel/intelligence.phtml`,
  `panel/panels-js.phtml`'s prefill + collect); `WizardStepSaver::
  saveIntelligence()` no longer writes them; `WizardData::getBootJson()` no
  longer exposes them. The observer gates that read them
  (`Observer\Engine\ProductSaveAfter`/`ProductDeleteBefore`/
  `CustomerSaveAfter`/`OrderSaveAfter`) now gate purely on
  `Settings::isConnected()` — ingest is always-on for a connected install,
  matching what an enabled install already had (`isCatalogSyncEnabled()`
  etc. were `isConnected() && isSetFlag(...)`, and the default was already
  `1`, so removing the toggle changes nothing for any install that hadn't
  actively disabled one). `isCatalogSyncEnabled()`/`isCustomerSyncEnabled()`/
  `isOrderSyncEnabled()` and their `XML_PATH_SYNC_*` constants are deleted
  from `Model\Engine\Settings` (dead once the observers no longer call
  them); the three `system.xml` fields and their `etc/config.xml` defaults
  are deleted too (the section itself has been fully hidden since PRO-1461,
  so this is cleanup of now-genuinely-dead declarations, not a UI change).
  **Config paths abandoned in place, per the 2.8.x constraint — NOT renamed
  or reused:** `intelligence/sync_catalog`/`sync_customers`/`sync_orders`
  simply stop being read or written anywhere; any pre-existing stored value
  in `core_config_data` is left orphaned, never migrated or cleaned up.
  Six now-unused translation strings (the three field labels + three
  checkbox descriptions) removed from both `i18n/en_US.csv` and
  `i18n/et_EE.csv`, en↔et parity re-verified (400 keys each, same set).
  `docs/USER_GUIDE.md`'s "What syncs" table updated to drop the per-row
  "Toggle" column (replaced with a one-line note that connecting the engine
  syncs everything) — Storefront Browse Tracking is unaffected, called out
  explicitly as staying a separate, real, consent-gated control per
  decision 4's own carve-out. **Verification:** 191 unit tests green (1
  updated — `ProductDeleteBeforeTest`'s two settings-mock cases now stub
  `isConnected()` instead of the deleted `isCatalogSyncEnabled()`, and the
  disabled-path test is renamed `testEngineNotConnectedIsANoOp` to match
  what it now actually asserts), phpcs 0 errors, phpstan clean, 65
  integration tests green (throwaway MySQL), sandbox `setup:upgrade` +
  `setup:di:compile` both green. **Real end-to-end check on the sandbox,
  not just tests — ingest behavior unchanged for a connected install:**
  faked a connected engine tenant (`intelligence/connected=1` + a real
  encrypted `intelligence/api_key`, the same "fake connected account"
  technique the PRO-1462 verification pass used), created one temporary
  real product through `ProductRepositoryInterface::save()` (the same path
  the admin grid uses, firing the real `catalog_product_save_after` event,
  not a direct call into the observer class), and confirmed a real row
  landed in `smaily_ingest_queue` (`domain=catalog, entity_id=<the new
  product>, status=pending`) — proving `ProductSaveAfter`'s `isConnected()`-
  only gate fires correctly with the toggle gone entirely. Cleanup: the
  temporary product, its ingest row, and the fake connection config were
  all removed afterward (the product needed a direct `catalog_product_
  entity` delete — Magento's own `RemoveAction` validator blocks
  `ProductRepository::delete()` from a bootstrap CLI context with "Delete
  operation is forbidden for current area", an unrelated Magento
  restriction, not a bug in this change); confirmed the sandbox's
  `smaily_ingest_queue` and `core_config_data` are back to their pre-test
  state (zero ingest rows, one `internal/last_seen_version` config row).
  Resolves STATUS.md "Questions / tasks for Erkki" item 4(c).
- **PRO-1468 (log verbosity) done — `smaily_connect/logging/verbosity` gets a
  home on the Log page (target-spec §2.4/§4.2), closing the "CLI/DB-only"
  gap PRO-1461's native-config removal flagged as a fast-follow.** A new
  small strip above the grid (`log/verbosity.phtml`, wired via a new
  `ViewModel\Adminhtml\LogVerbositySettings`) shows the current value and
  the same three options the native field had (Errors only / Info / Debug),
  with its own Save button and inline status — no design-pack mockup exists
  for this control, so placement follows the common shell idioms per the
  spec's own note. Saves through a new dedicated endpoint,
  `Controller\Adminhtml\Api\SaveVerbosity` (POST `{verbosity}` ->
  `{saved, error?}`, the same JSON contract shape every other AJAX config
  save in this module uses), gated on the Log page's own ACL resource
  (`Smaily_Connect::event_log`) rather than Settings' `::config` since the
  control lives on the Log page, not Settings. The field is genuinely
  installation-wide (`Config::getLogVerbosity()` already read default scope
  only, matching its native `system.xml` declaration's own
  `showInWebsite="0" showInStore="0"`), so the save writes at default scope,
  no website/store argument threaded through. **First implementation attempt
  had a real bug, caught before commit, not silently worked around:** the
  layout XML originally passed `Model\Config` and
  `Model\Config\Source\LogVerbosity` directly as `xsi:type="object"`
  arguments — Magento's layout argument resolution requires such objects to
  implement `Magento\Framework\View\Element\Block\ArgumentInterface`, which
  neither class does, so the block silently failed to attach (logged as a
  `main.CRITICAL` "Instance of ArgumentInterface is expected" line, no user-
  visible error) and the whole control vanished from the rendered page with
  no other symptom. Fixed by introducing the `LogVerbositySettings` ViewModel
  (implements `ArgumentInterface`, matching the existing `LogHealth`
  pattern already used for the failed-deliveries banner on this same page)
  instead of passing raw Model classes. **Verification:** 192 unit tests
  green (0 new — no dedicated controller/ViewModel unit tests exist anywhere
  in this repo; controllers are verified live, matching that precedent),
  phpcs 0 errors, phpstan clean, 65 integration tests green (throwaway
  MySQL), sandbox `setup:upgrade` + `setup:di:compile` both green. **Real
  end-to-end check on the sandbox, not just a code read:** logged into the
  live admin via a real HTTP session (cookie + the exact secret-key/form-key
  mechanism Magento's own rendered menu links carry — reproduced by
  replicating a real navigation, not by disabling `admin/security/use_form_key`),
  confirmed the control renders with "Errors only" pre-selected (the
  `etc/config.xml` default), POSTed a real `{"verbosity":"debug"}` to
  `smaily_connect/api/saveverbosity` and got `{"saved":true}`, confirmed via
  a raw `core_config_data` read that the row landed at `scope=default,
  scope_id=0, value=debug`, reloaded the page and confirmed "Debug" now
  renders pre-selected (proving the read-back path, not just the write),
  and confirmed an invalid value (`"bogus"`) is rejected with
  `{"saved":false,"error":"Invalid log verbosity value."}` and no config
  write. Saved the value back to `error` afterward and the sandbox's one
  test-only `internal/setup_completed=1` row (needed to get past this
  install's own wizard-first gate, since this sandbox was left in a
  pristine unconfigured state by the prior PRO-1461 session) was deleted
  again afterward — confirmed back to the exact pre-test single-row
  `core_config_data` state (`internal/last_seen_version` only).
- **PRO-1461 done — multi-website Phase 2 (RFC_MULTI_WEBSITE.md §2): wizard
  website-chooser step + Settings website selector, wired to the
  already-built `WebsiteContext` seam** — plus the native `Stores >
  Configuration > Smaily` surface removal this phase's own scope folded in
  (resolves PRO-1369 open question #2), and the PRO-1274/PRO-1398 override
  banner's retirement as dead weight the selector supersedes.
  1. **`Model\Adminhtml\WebsiteContext` gained the actual chooser mechanism**
     (previously just resolved the installation's default website, no UI
     existed yet): `getWebsiteId()` now reads a `website` request param,
     validated against real websites, falling back to the default website
     when absent/blank/stale — the RFC's own prescription ("a request param
     the context reads under admin scope"). New `isExplicit()` (did this
     request name a website, vs. fall back?), `getStoreId()` (the resolved
     website's own canonical default store, for store-scoped reads like
     subdomain/username/password), `hasMultipleWebsites()`/`getWebsiteOptions()`
     (drive both the selector and the chooser, one place, no duplicated
     `getWebsites()` loops).
  2. **Settings page**: an explicit `<select>` next to the tab strip (own
     chrome, not Magento's native store-switcher, exactly as the RFC
     recommends), shown only when `hasMultipleWebsites()`; picking a website
     reloads with `?website=<id>` (preserving the current `?tab=`) — every
     save/prefill path already reads `WebsiteContext`, so the reload is the
     entire mechanism, no new plumbing per field.
  3. **Wizard**: a website-chooser card renders before the normal step rail
     whenever `hasMultipleWebsites() && !hasSelectedWebsite()`; picking one
     reloads with `?website=<id>` the same way, after which the chooser
     never reappears for that page load and the normal 5-step flow proceeds
     scoped to that website. The Done step's Settings/Log links and the RSS
     builder deep link carry the same `?website=` forward so a multi-website
     merchant lands back on the website they just configured.
  4. **Prefill gap fixed, not just wiring added.** `WizardData::getBootJson()`
     previously called most `Config`/`Mode` getters with **no scope argument
     at all** (only `detectedLanguages`/`languageStoreIds`/`getSavedMappings`
     threaded `$websiteId`) — meaning the selector/chooser would have
     silently kept showing website 1's Connection/Subscribers/Automations
     values regardless of which website was selected. Every affected getter
     (`getSubdomain`/`getUsername`/`getPassword` via the new `getStoreId()`;
     `isSyncEnabled`/`getSyncMode`/`getSyncFields`/`includeGuests`/
     `automationForceOptIn`/`isCheckoutOptinEnabled`/`suppressOptinEmails`;
     `isWelcomeEnabled`/`getWelcomeWorkflow`/`isFirstOrderEnabled`/
     `getFirstOrderWorkflow`/`isAbandonedCartEnabled`/`getAbandonedCartWorkflow`/
     `getAbandonedCutoffMinutes`; `getMultilingualMode`) now threads the
     resolved website/store id through. `getSelectedSyncFields()`/
     `getSelectedAbandonedFields()` (server-rendered checkbox prefill) fixed
     the same way. Intelligence and RSS deliberately keep reading default
     scope (Phase 4/RFC-fenced, unchanged).
  5. **Setup-completed flag is now website-scoped**, not just the visible
     UI: `WizardStepSaver::saveFinish()` writes
     `smaily_connect/internal/setup_completed` at `SCOPE_WEBSITES` for the
     target website (was bare default-scope); `SetupGuard`/`WizardData`'s
     `isSetupCompleted()` read it via the normal website→default fallback
     chain. Without this, a second website would have inherited the FIRST
     website's completion via the pre-existing default-scope flag and the
     wizard would have jumped straight to the "Done" step for a website that
     was never actually configured — the chooser would have been
     structurally pointless. **Deliberate design choice, not fully
     migration-proofed to the strictest possible reading:** the flag uses
     the SAME website→default fallback every other field in this module
     uses, so a *brand-new* website on an *already-completed* install also
     inherits "completed" via that fallback (consistent with every other
     field silently inheriting the default website's value until the
     merchant saves its own) — it does not force a hard "never configured"
     state for a new website. This is flagged as an assumption in the PRO-1461
     report, not silently decided; a stricter alternative (explicit-row-only
     completion, ignoring fallback) was considered and rejected as
     inconsistent with how every other field in this phase behaves.
  6. **Native `Stores > Configuration > Smaily` section removed** (resolves
     PRO-1369 open question #2, Erkki 2026-07-20). Investigated exactly what
     system.xml's role actually is before touching it: our own AJAX save
     path (`WizardStepSaver`) already encrypts/writes directly via
     `EncryptorInterface`/`WriterInterface`, never through system.xml's
     `backend_model`; `core_config_data` storage and `ScopeConfigInterface`
     reads are keyed by path, not system.xml presence; only
     `bin/magento config:set`/`config:show` and the native admin FORM
     actually consult the structure tree. The fix is a single, minimal,
     provably-complete change: the `smaily_connect` `<section>`'s
     `showInDefault`/`showInWebsite`/`showInStore` all flip to `"0"` — traced
     the real Magento code path (`Structure\Element\Section::isVisible()` →
     `AbstractElement::isVisible()`) to confirm this hides the section (and,
     since it's the tab's only section, the "Smaily" tab too) from the left
     nav for **every** admin regardless of ACL — a role with "All" access
     bypasses missing/denied ACL resources entirely (confirmed via
     `Magento\Framework\Authorization\Policy\Acl`'s own doc comment: "If ACL
     doesn't contain provided resource, permission for all resources is
     checked"), so an ACL-only hide would NOT have worked for the sandbox's
     own admin user. Every group/field declaration (backend/source models,
     `type="obscure"`, `canRestore`) is otherwise untouched — `config:set`/
     `config:show` and encryption keep working, confirmed by direct code
     read of `Magento\Config\Model\Config::getElementByConfigPath()` (a raw
     path-parts walk, not gated by the same visibility flags). Direct URL
     access to the now-hidden section (`system_config/edit/section/
     smaily_connect`) safely redirects to the config index instead of
     erroring (`Edit::execute()`'s own `isVisible()` check) — confirmed live.
     No menu.xml entry pointed at the native page (checked); the ACL
     resource (`Smaily_Connect::config`) stays untouched since it's shared
     with our own Settings page's menu/ACL gate, unrelated to system.xml
     visibility. Removed the now-dead pointers to the native page: the RSS
     tab's "Advanced RSS options in Stores > Configuration" link
     (`panel/rss.phtml`), the Settings page's "Need per-website or
     per-store-view overrides..." footer note + link, and the Dashboard's
     "Stores > Configuration" quick link.
  7. **PRO-1274/PRO-1398 "Overridden for X" override-awareness chrome
     removed** (`ViewModel\Adminhtml\ConfigOverrides`,
     `Model\Config\OverrideDetector`/`OverrideClearer`/`ModuleConfigPaths`,
     `Controller\Adminhtml\Config\ClearOverride`, plus its JS/CSS in
     `settings/index.phtml`/`smaily-admin.css`) — not merely "dead because
     native is gone," genuinely **actively wrong and dangerous** once
     combined with this phase's own website selector. `OverrideDetector`'s
     premise ("the Settings page always saves at the default scope, a more
     specific row shadows it") stopped being true the moment Phase 1
     (PRO-1460) made Subscribers/Automations/Connection saves land at
     WEBSITE scope by default — meaning the very row a merchant's OWN save
     just created is exactly what the detector flags as a "shadowing
     override," and its "Use Default" affordance
     (`OverrideClearer::clear()`) would **delete that merchant's own
     just-saved website-scoped row** the moment they clicked it. Combined
     with this phase's selector making per-website editing an explicit,
     intentional, everyday action (not a rare 2.8.x-migration leftover),
     shipping this banner unchanged would have surfaced a real,
     first-contact data-loss footgun on every 2+-website install. The
     website selector is the correct, already-shipped replacement for
     "see/manage this website's own value" — no auto-clear-on-save
     follow-up is needed, closing that PRO-1274 open item as moot rather
     than deferred.
  **Assumptions stated, not silently decided (see the PRO-1461 report for
  full reasoning):** (a) the setup-completed-flag fallback behaviour above;
  (b) Settings selector → an unconfigured website redirects into the wizard
  (same "wizard-first" gating a single-website install already has) rather
  than rendering blank Settings fields — a deliberate extension of existing
  behaviour, not explicitly specified by the task; (c) Dashboard and Log
  stay default-website-scoped with no selector of their own (task named only
  Settings + wizard).
  **Verification:** 191 unit tests green (10 new: `WebsiteContextTest` (7),
  `SetupGuardTest` (2), `WizardStepSaverTest`'s new `saveFinish` case, plus a
  new `Test/Unit/ViewModel/Adminhtml/WizardDataTest` (5 cases) pinning the
  getBootJson prefill-scope fix), 65 integration tests green (throwaway
  MySQL, 1 new: `saveFinish` lands a real website-scoped row alongside a
  surviving pre-existing default-scope one), phpcs 0 errors, phpstan clean.
  Sandbox `setup:upgrade` + `setup:di:compile` both green. **Real end-to-end
  check on the sandbox, not just tests** — created a genuinely temporary
  second website (+store group "Second Website Store" +store view "Second
  Store View", codes `second_website`/`second_website_store`/
  `second_store_view`) via the real Stores > All Stores admin UI (Magento
  core has no delete button for these, so the temporary teardown at the end
  used direct SQL against `store`/`store_group`/`store_website`, matching how
  such installs are actually cleaned up): confirmed native Stores >
  Configuration shows zero "Smaily" mentions anywhere in its nav (General/Web
  section, real admin session, not a confounded test) and safely redirects
  away from a forged direct URL to the hidden section; ran the real wizard
  end-to-end for the new website (chooser → Connect → Subscribers →
  Automations → Intelligence → Done, all 5 steps green) and confirmed via
  raw `core_config_data` reads that `subdomain`/`username`/`password`/
  `sync_*`/`automations_*`/`setup_completed` all landed at
  `scope=websites, scope_id=2` with distinct values from website 1 (which
  had nothing saved); the Settings selector correctly showed website 2's
  real saved values and, separately, correctly bounced to the wizard when
  switched to website 1 (not yet onboarded); repeated the wizard-chooser +
  Settings-selector checks in **et_EE** (screenshots confirm full
  translation: "Millist veebisaiti seadistad?", "Veebisait", "Jätka") with
  zero console errors in any run. **Bug caught by this live pass, not by
  unit tests:** baking `?website=<id>` into `panels-js.phtml`'s AJAX URLs
  server-side collided with the shared `post()` helper's own
  `url + '?form_key=' + ...` string concatenation, producing a
  double-`?` query string that silently broke `form_key` parsing (Magento's
  `BackendValidator`/`_processUrlKeys()` rejected every website-2 AJAX save
  with "Invalid Form Key" until this was caught live) — fixed by having
  `post()` pick `&` vs `?` based on whether the URL already has a query
  string. After verification, the temporary website/store/store view were
  deleted, their `core_config_data` rows (`scope=websites, scope_id=2`,
  which Magento's own website deletion does NOT clean up — verified, then
  removed by hand) and an incidental default-scope `intelligence/
  browse_tracking` row from the Intelligence step were removed, and the
  sandbox was reindexed/cache-flushed — confirmed back to the exact
  pre-test row count (one `last_seen_version` row) and single-website
  behaviour (no selector, no chooser).
  **Not done, flagged for a fast follow, not silently left stale:**
  `smaily_connect/logging/verbosity` had no home anywhere on our own pages
  before this change (target-spec §4.2 flags it as needing one, "gets a home
  on the Log page" — not yet built) and was only ever reachable via the now-
  hidden native form; it is now CLI/DB-only
  (`bin/magento config:set smaily_connect/logging/verbosity debug`), a real,
  if narrow, capability loss this phase's native removal causes. Same shape
  for `intelligence/sync_catalog`/`sync_customers`/`sync_orders` — per
  target-spec decision 4 these three are slated for outright removal (engine
  connection already syncs everything), so losing their native toggle is
  arguably the intended end state rather than a gap, but the observer gates
  reading them are still live code, not yet deleted. `docs/USER_GUIDE.md`
  updated to point every native-config cross-reference at its real page and
  to document the new website selector/chooser; both of the above dead ends
  called out explicitly there too (CLI commands given) rather than left
  silently broken.

- **PRO-1462/PRO-1457 done — multi-website Phase 3 (RFC_MULTI_WEBSITE.md §6):
  automation mapping saves at the real website scope, and consent reconcile
  covers every resolved Smaily account, not just a website's default one.**
  Two independent fixes, both reusing schema/plumbing Phase 1 already built:
  1. **Automation mapping website routing.** `Model\Adminhtml\WizardStepSaver
     ::saveAutomations()` passed `Model\Automation\MappingSaver::save()` a
     hardcoded `websiteId=0`; it now threads through the real target website
     (`WebsiteContext::getWebsiteId()`, same idiom the rest of the wizard's
     writes already use). `Router` already preferred a website-specific row
     over a `website_id=0` one (`website_id IN [$websiteId, 0]`, ordered
     DESC) — no change needed there. The admin's own prefill
     (`ViewModel\Adminhtml\WizardData::getSavedMappings()`) was still
     hardcoded to `website_id=0` only, which would have gone stale the moment
     a website-scoped save landed; it now applies the same
     website-beats-global merge as the Router (`IN [$websiteId, 0]`, ordered
     ASC so the website-specific row wins the keyed array). **Migration:** no
     row is moved or renamed — a single-website install's pre-existing
     `website_id=0` rows (from the 2.8.x migration's default-scope seeding,
     or any pre-Phase-3 save) keep resolving exactly as before, both via the
     Router and via the admin's prefill, until that website's own row is
     saved.
  2. **Consent reconcile per-account coverage (closes PRO-1457).**
     `Cron\ContactReconcile` iterated websites but polled Smaily using only
     each website's default store's account — on multilingual mode A
     (per-language Smaily accounts), unsubscribes made directly in a
     non-default language's account were never pulled back. The cron now
     resolves every distinct account a website has (`Multilingual
     \AccountResolver::detectedLanguages()`/`storeIdForAccountKey()`, website
     x language) and polls each once, deduplicated by the account's actual
     resolved credentials (subdomain+username) so two account keys that
     happen to share the same underlying Smaily account are never polled
     twice. Each account keeps its own reconcile cursor — the action log's
     `seq_id` numbering is per Smaily account, so sharing one would skip or
     re-replay events; the website's own default account keeps the
     pre-existing flag key (`smaily_connect_reconcile_seq_w<websiteId>`)
     unchanged so an upgrade never replays its whole history, while an
     additional per-language account gets a new `_<accountKey>`-suffixed key.
  **Verification:** 6 new unit tests (`Test/Unit/Model/Adminhtml/
  WizardStepSaverTest.php` — mapping save routes through the target website;
  `Test/Unit/Cron/ContactReconcileTest.php`, 5 cases — polls every distinct
  per-language account, dedupes an account reached via two keys, the default
  account keeps its pre-existing flag key, a new per-language account gets
  its own suffixed key, an unconfigured account is skipped) plus 3 new
  integration tests against real MySQL (`Test/Integration/Adminhtml/
  WizardStepSaverTest.php` — a mapping save lands at the real website row,
  a legacy `website_id=0` row survives untouched, and the Router prefers the
  new row for this website while a different/unmigrated website still
  resolves the legacy fallback; `Test/Integration/Adminhtml/WizardDataTest.php`,
  2 cases — the admin prefill prefers a website row over a legacy global one
  for the same key, and falls back to the legacy row when the website hasn't
  saved its own) — 182 unit tests green, 64 integration tests green
  (throwaway MySQL), phpcs 0 errors, phpstan clean. Sandbox `setup:upgrade`
  + `setup:di:compile` both green (new `AccountResolver` constructor
  dependency on `ContactReconcile` resolved cleanly). **Real end-to-end
  check, not just tests:** on the sandbox (single website, single store
  view — Main Website, "default" store), ran the actual
  `WizardStepSaver::save('connect'/'automations', …)` path through a real
  bootstrap: a saved mapping landed as a real `website_id=1` row, `Router::
  resolve()` picked it, and `WizardData::getSavedMappings()` reflected it.
  Separately inserted a raw `website_id=0` row (simulating a pre-existing/
  2.8.x-migrated install), confirmed `Router` and the admin prefill both
  resolved it unchanged, then saved a different trigger for website 1 and
  confirmed the legacy row was left untouched. For the reconcile side, wrote
  real website-scoped consent config + a (fake) connected account, ran
  `Cron\ContactReconcile::execute()` directly, and confirmed a real
  `GET api/history.php` call was attempted with `var/log/smaily_connect.log`
  recording `Consent reconcile failed {"website_id":1,"account_key":"default",
  ...}` — proving the new `account_key` context and the real per-account
  code path both fire correctly for today's single-account shape. The
  sandbox has only one store view, so it cannot exercise a second genuinely
  distinct per-language account live; that shape (2+ distinct accounts,
  dedup, per-account flag keys) is covered by the `ContactReconcileTest`
  regression suite instead, run against mocked account resolution. All
  sandbox config/mapping/flag rows created for these checks were deleted
  afterwards (verified back to 0 rows).

- **PRO-1467 done — sandbox `entrypoint.sh` no longer reinstalls Magento on
  every container start.** `bin/magento setup:install` now runs only when
  `app/etc/env.php` doesn't yet exist, guarding the whole install block with
  `if [ ! -f app/etc/env.php ]; then ... fi`; fresh-volume first boot is
  unchanged. Fixes the defect flagged in "Questions / tasks for Erkki" item 3
  below (removed from that list) — a `docker compose down`/`up` against the
  persistent `db-data`/`data` volumes no longer fails with `Trigger already
  exists`. Verified with a real container rebuild + `docker compose down` /
  `up -d` against the existing volumes: containers came back healthy and the
  admin responded at `localhost:8080/admin`.
- **PRO-1358 done — `EngineCatalogProcessor::countProducts()` scoped to the
  same canonical store as `loadPage()`.** The progress-bar total's product
  collection previously had no explicit store scope (falling back to
  Magento's implicit current-store resolver), while `loadPage()` has set
  `canonicalStoreId()` explicitly since PRO-1353 — a cosmetic drift where the
  total could disagree with the scoped pages on multi-store installs.
  `countProducts()` now calls the same `CatalogPayloadBuilder::
  canonicalStoreId()` via `setStoreId()` before `getSize()`. New unit test
  (`EngineCatalogProcessorTest::
  testCountProductsAppliesTheSameCanonicalStoreScopeAsLoadPage`) drives a
  full `process()` pass with `total_count` unset and asserts `setStoreId()`
  is called with the canonical store id on both the count and page
  collections — 176 unit tests green, phpcs 0 errors, phpstan clean.
- **PRO-1460 done — multi-website Phase 1 (RFC_MULTI_WEBSITE.md §1–§2):
  website-scoped Wizard/Settings writes + a website x language
  `AccountResolver`, resolver-only, no UI.** Two coordinated changes, both
  reversible and both invisible to a single-website install:
  1. **Write scope.** `Model\Adminhtml\WizardStepSaver`'s `save()` resolves
     the installation's default website (`getDefaultStoreView()->getWebsiteId()`,
     the same "canonical default" idiom `CatalogPayloadBuilder::canonicalStoreId()`
     already uses) and threads it into `saveConnect()`/`saveSubscribers()`/
     `saveAutomations()` — every field in RFC §1's list (connection
     credentials, the multilingual mode, subscriber sync toggles, automation
     toggles/workflows/cutoff/fields) now writes via
     `WriterInterface::save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId)`
     instead of bare default-scope saves. Deliberately **untouched** (per the
     task's own scope fence): the Intelligence tab (`saveIntelligence()` —
     Phase 4, engine tenant scoping), RSS (`saveRss()` — outside §1's field
     list, its `Config::isRssEnabled()` getter is store-view-scoped, not the
     website-scope helper the others use), `saveFinish()`'s setup-completed
     flag (Phase 2, per-website `SetupGuard`), the mode-A fallback-language
     hint (`XML_PATH_FALLBACK_LANGUAGE` — its getter reads literal default
     scope with no scope argument at all, unlike its siblings; moving the
     write without fixing that getter would silently break it, flagged as a
     follow-up, not fixed here), and the automation-mapping table's own
     `MappingSaver::save(..., 0, ...)` website id (§6's "already-hardcoded
     0", explicitly Phase 3). **No config path renamed** — same paths, new
     scope argument only, so the 2.8.x migration patch is untouched and its
     tests stay green.
  2. **Resolver dimension.** `Model\Multilingual\AccountResolver::detectedLanguages()`
     and `storeIdsForAccountKey()` (plus `storeIdForAccountKey()`) gain a
     required `$websiteId` parameter and scope their store iteration to
     `$storeManager->getWebsite($websiteId)->getStoreIds()`/`getDefaultStore()`
     instead of every store in the installation — two websites sharing a
     language (e.g. both `en`) no longer collapse onto the same account key.
     Every call site now threads a website id: `WizardStepSaver`'s mode-A
     per-language write loop and its "leaving mode A" teardown (now scoped to
     the target website's own stores, not every store in the install),
     `WizardStepSaver::availableWorkflowIdsByAccount()` (the PRO-1286
     preserve-list check), `Model\Queue\Handler\AutomationHandler` (already
     had the event's `website_id` in hand, just wasn't passing it), and
     `ViewModel\Adminhtml\WizardData` (kept its own public methods'
     zero-arg signatures — no UI selector exists yet — but now resolves the
     installation's default website internally before calling the resolver,
     a forced but behavior-preserving knock-on of the resolver's new
     required parameter).
  **Deliberately out of scope, matching the task fence:** no website chooser
  UI (Phase 2), no per-website `SetupGuard` (Phase 2), no automation-mapping
  website id fix (Phase 3), no engine tenant scoping (Phase 4) — the RFC's
  phase boundaries, not partially anticipated here.
  **Verification:** 20 new unit tests (`Test/Unit/Model/Multilingual/AccountResolverTest.php`,
  6 cases: website-scoped `detectedLanguages`/`storeIdsForAccountKey`, no
  cross-website bleed on a shared language, `storeIdForAccountKey`
  default-account nulls, a non-`Website`-instance website resolving empty;
  `Test/Unit/Model/Adminhtml/WizardStepSaverTest.php`, 6 new cases: connect
  credentials + multilingual mode at website scope, the fallback-language
  exception staying at default scope, subscriber/automation fields at
  website scope, mode-A per-language writes resolving through the target
  website, Intelligence/RSS staying at default scope) — 174 unit tests
  green total, phpcs 0 errors, phpstan clean. New integration suite
  `Test/Integration/Adminhtml/WizardStepSaverTest.php` (5 cases) exercises
  the real `core_config_data` table end to end: connect/subscriber/
  automation writes land as real `scope='websites'` rows at the resolved
  website id; a **pre-existing default-scope row survives untouched
  alongside the new website row** (the RFC's "fallback, not migration"
  claim, checked against a real table, not a mock); saving twice updates
  the same row instead of duplicating — 61 integration tests green
  (throwaway MySQL, `SMAILY_IT_DB_PORT=3316`), including the pre-existing
  2.8.x `MigrateLegacyConfigTest` suite unchanged (no path renamed). Sandbox
  `setup:upgrade` + `setup:di:compile` green (a stale `db-data` volume from
  an earlier session needed a one-off `--cleanup-database` reinstall first —
  an environment hiccup unrelated to this change, noted under Questions
  below). **Real end-to-end read/write check** (not just units): logged into
  the live sandbox admin, POSTed the real `connect` step via
  `smaily_connect/api/savestep` — the resulting `core_config_data` row
  landed at `scope='websites', scope_id=1` (the sandbox's one real website).
  A one-off bootstrap script then called the exact getters
  `WizardData::getBootJson()` calls (`Config::isConnected()`/`getSubdomain()`/
  `getMultilingualMode()`, no scope argument, `adminhtml` area code) and
  confirmed they resolve the new website-scoped value correctly
  (`isConnected(): true`, `getSubdomain(): pro1460demo`) — settling the one
  open risk in this design (whether a no-arg read in the admin area walks
  the store→website→default chain through the *real* default website, not
  an admin pseudo-scope): `$storeManager->getStore()` resolves to store id 1,
  website id 1, in a plain adminhtml bootstrap, matching the website the
  write landed at. Deleting the website-scope row and reinserting the same
  value at default scope only (simulating an un-migrated pre-existing
  install) confirmed the getter still reads it correctly as a fallback — the
  "no migration needed" half of the acceptance criteria, live. Sandbox
  config rows cleared back to a pristine unconfigured state afterwards.

- **PRO-1449 done — engine contract synced v1.4.1 → v1.5.0, docs-only.**
  `docs/RECENGINE_API_CONTRACT.md` overwritten byte-identical from engine
  main (staleness check green, `bin/check-contract-staleness.sh` run against
  the local `../re` checkout). The v1.5.0 bump is additive only: new
  **§14 `POST /api/v1/notifications/ingest`** (Notifications 2.0 external
  ingest — same bearer auth + 100 req/sec tier as existing ingest endpoints,
  registry-gated fail-closed `type`, upsert-on-open-dedupe idempotency), plus
  the matching TOC entry, rate-limit table row, setup-exchange endpoints map
  gaining `notifications_ingest`, and the changelog/version-header lines. No
  existing wire shape changed. We are **not** building against §14 now (event
  types must first be registered engine-side; out of scope) — verified our
  `Model/Engine/Settings::getEndpoint()`/`Client::endpoint()` map lookup is a
  generic keyed fallback, so the new map key is inert for us; no code or test
  fixture changes needed. Gates: 162 unit tests green (unchanged from before
  the sync), phpcs/phpstan not implicated (docs-only).
- **PRO-1456 — multi-website support RFC drafted, docs only.** Erkki's
  binding direction (2026-07-20): one Magento website = one Campaign
  Intelligence tenant + its own Smaily binding; inside a website the
  existing multilingual mode choice is unchanged, but the account resolver
  becomes website × language instead of today's install-wide language
  keying; store groups are not a binding unit; single-website installs are
  unaffected and migration maps the existing single tenant onto the default
  website. `docs/RFC_MULTI_WEBSITE.md` works out the how, per subsystem
  (config write scope, account resolver, engine tenant, queue/backfill
  schema, ingest payload scoping, consent-reconcile completeness,
  migration) and a 5-phase LOW-effort rollout — Phase 4 (engine tenant per
  website) is explicitly gated on PRO-1459 (engine-side per-tenant
  provisioning/billing confirmation), and Phase 5 starts with a one-way-door
  schema migration (`smaily_ingest_queue` gains a `website_id` column) that
  needs its own sign-off before build. No code changed in this pass; the
  scope map that grounds the RFC's current-state claims (file:line
  citations per subsystem) was produced as investigation-only groundwork,
  not committed as a repo doc. Awaiting review before any phase starts.

- **PRO-1401 done — Settings > Automations tab rebuilt to target spec
  (§2.3.C), the finding-#9 fix.** The store-event triggers (Welcome / First
  order / Abandoned cart) move off the cramped checkbox+dropdown table onto
  the same `.smaily-engine-trigger` card-list vocabulary the
  engine-automations block already used: each trigger is a card with a
  run-mode pill (Active/Off) that follows its enable toggle live
  (`reactAutomationRow` in `panel/panels-js.phtml`), a muted description and a
  right-aligned control row (Workflow select disabled while off; the
  abandoned card adds the cutoff-minutes input). The tab gained the
  Connection/Subscribers shell — an H3 title + description above the blocks
  and a tab-scoped "Save automations" footer (reusing the existing
  `saveTab('automations')` path), which hides the generic global footer on
  this tab. **Orphan control wired (Erkki 2026-07-14 §4.2):** the
  abandoned-cart product fields (`automations/abandoned_fields` — native-config
  only until now, dead-`saveFlag()` shape) get a real 7-checkbox control
  inside the abandoned-cart card (Settings only). Persistence already existed
  in `WizardStepSaver::saveAutomations()`; this completes it end to end —
  `WizardData::getSelectedAbandonedFields()` exposes the saved selection for
  the server-rendered checked state, `panels-js` collects it into the
  automations save payload, and 4 new `WizardStepSaverTest` cases cover
  store/filter-to-supported/clear/absent. Config path unchanged (2.8.x
  migration constraint). **Engine-automations validation-error state:** a
  client-side rule (a workflow is required once a trigger is enabled) renders
  field-level danger on the offending row's Workflow select plus a top error
  banner with a count; valid rows untouched, clears on every attempt / a
  successful save. No save endpoint changed. The not-connected empty state
  and catalog-load-failed dimmed+banner fallbacks were already present.
  **Verification:** 162 unit tests green (4 new), phpcs 0 errors, phpstan
  clean, sandbox `setup:upgrade` + `setup:di:compile` green. Playwright drove
  Settings > Automations in both **en_US and et_EE** (card-list with pills,
  the abandoned-cart product-field checkboxes, the "Save automations" footer
  and the engine not-connected empty state, all fully translated), zero
  module JS console errors in either run. Screenshots under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/automations-shots/`
  (`automations-en-*`, `automations-et-*`). Sandbox restored: admin locale
  back to en_US. **Known/out-of-scope:** the PRO-1274 "Overridden for main
  website" banner renders inside the abandoned-cart card (a store-view
  override exists on the abandoned workflow in the sandbox) — that override
  chrome is the separate PRO-1398 sweep, untouched here.

- **PRO-1397 polish pass done — the four defects Erkki flagged after
  reviewing the rebuilt Subscribers tab live, plus a new defensive state
  for a stalled/queued import (#8, Erkki's "option 3").** (1) **Radio/label
  misalignment on the consent choice-cards, root-caused and fixed:** a dead
  legacy rule, `.smaily-ui .smaily-choice { display: block; ... }` (a relic
  of the pre-Phase-3 markup, keyed on a `.selected` class no template has
  used since the BEM `.is-selected` rebuild), was still in
  `smaily-admin.css` and — because every page using the component also
  carries the `.smaily-ui` class — its higher specificity (0,2,0 vs the
  real component's 0,1,0) was winning and collapsing the shared
  `.smaily-choice` component's `display:flex` back to `display:block`. This
  silently broke the radio/label alignment on **every** `.smaily-choice`
  usage, not just Subscribers — Connection's multilingual mode-cards had
  the same latent bug, just never exercised because the sandbox is
  single-language. Deleted the three dead rules; both usages now render
  per the design's `ChoiceCard.dc.html` spec (18px radio, `flex` layout,
  `gap: 12px`). (2) **"Extra fields" spacing rhythm fixed:** the heading-
  to-first-row gap used to collapse (via margin-collapsing with the legacy
  `.smaily-field` bottom margin) to ~8px, *smaller* than the 12px gap
  between checkbox rows, so the heading didn't read as a heading. New
  explicit rhythm in `smaily-admin.css`: `sp-5` (20px) above the first
  checkbox row, `sp-3` (12px) between rows (unchanged, already tight),
  `sp-6` (24px) below the extra-fields block (its own `margin-bottom`,
  overriding the base 1.6rem) so the standalone-toggles group reads as a
  separate cluster, `sp-3` between toggles (tightened from `sp-4`).
  (3) **Import-area breathing
  room:** the backfill card's description, button row and progress bar had
  zero margin between them; `subscribers.phtml`'s card now carries a
  `.smaily-backfill-card` hook (unscoped — the card is shared by the
  wizard step-2 and Settings > Subscribers) and two new CSS rules give the
  note and the progress bar `sp-2`–`sp-4` breathing room, matching
  `Backfill.dc.html`'s framing. **(4) New defensive "queued" state (#8):**
  `Model\Backfill\Job::STATUS_PENDING` (set the moment
  `smaily:backfill:start`/the Import button fires) used to be collapsed
  into the same `'running'` API status as `STATUS_RUNNING`, so a job that
  the cron tick (`Cron\BackfillTick`) hadn't picked up yet rendered the
  same bare "Importing… 0 / ?" as a job with real progress — indistinguishable
  from stuck. New `Model\Backfill\JobStatusAggregator::resolve()` (unit
  tested, `Test/Unit/Model/Backfill/JobStatusAggregatorTest.php`) keeps
  `running` only when a job has actually started; an all-`pending` set now
  resolves to a new `'pending'` status, used by `BackfillState::aggregate()`
  in place of the old inline if/elseif chain. `panels-js.phtml`'s
  `renderBackfill()` renders `pending` with a reassuring, honest line
  ("Queued — the import starts on the next scheduled run…", i18n'd both
  locales) instead of a progress bar (hidden, not shown at 0% — never claim
  progress that isn't happening), Cancel stays available. **Detection
  approach (assumption, stated per the task):** `STATUS_PENDING` cleanly
  and immediately distinguishes "queued, cron hasn't started it" from
  "running" — no time-based "stalled for N minutes" heuristic was needed,
  since the signal is already binary and honest from the moment the job is
  created. **Known limit:** a job that flips to `running` and then
  genuinely stalls mid-page (cron dies, a stuck HTTP call, etc.) is NOT
  detected by this change — it still shows "Importing… X / Y" indefinitely.
  Distinguishing that case would need a last-progress timestamp delta, not
  present in scope for this pass. **Incidental but significant bug, found +
  fixed in its own commit:** while verifying "the real cron-driven path
  still shows live progress" live in the sandbox, discovered the
  `smaily_connect` cron group was **never actually configured** — its group
  knobs (`schedule_generate_every`/`schedule_ahead_for`/…) had been declared
  in the WRONG file: `etc/config.xml`'s `<default><system><cron>` block.
  Magento reads cron-group cadence **only** from a `cron_groups.xml`, never
  from a module's `config.xml` `<system><cron>` subtree — **empirically
  confirmed:** with only the `config.xml` block present,
  `scopeConfig->getValue('system/cron/smaily_connect/schedule_ahead_for')`
  returns `NULL` (while a sibling non-cron default,
  `smaily_connect/subscribers/sync_enabled`, loads fine), so
  `ProcessCronQueueObserver` reads `schedule_ahead_for = 0`, computes a
  zero-length look-ahead window, and **silently generates zero
  `cron_schedule` rows for the group, forever.** That means **none of the
  module's 7 cron jobs** (`FlushEventQueue`, `QueueJanitor`,
  `ContactReconcile`, `AbandonedCart`, `BackfillTick`, `FlushIngestQueue`,
  `HealthCheck`) has ever run on ANY install — sandbox or production — no
  matter how often `bin/magento cron:run` fires (this supersedes the
  previous session's "sandbox has no cron daemon" note, which masked the
  real cause). Fix: added `etc/cron_groups.xml` (the canonical mechanism),
  carrying the exact cadence the original author had put in the wrong file
  — `schedule_generate_every: 1`, `schedule_ahead_for: 4`,
  `schedule_lifetime: 15`, `history_cleanup_every: 10`,
  `history_success_lifetime: 60`, `history_failure_lifetime: 4320`,
  `use_separate_process: 1` (suits the `* * * * *` jobs and keeps the long
  backfill/ingest ticks off the parent cron process) — and **removed the
  now-dead `<system><cron>` block from `config.xml`** so there is one
  working source of truth instead of one live and one silently-inert copy
  of the same values. Verified live and unconfounded: with `cron_groups.xml`
  alone, the group's scope-config values resolve to non-null
  (`schedule_ahead_for` `NULL → 4`), `cron:run --group smaily_connect`
  generates `smaily_backfill_tick` schedule rows, and a queued job flows
  `pending` → `running` → terminal exactly as designed (a real job driven
  end-to-end; its rows "failed" only because the sandbox has no live
  Smaily credentials — the lifecycle is the point).
  **Verification:** 158 unit tests green (8 new, all in
  `JobStatusAggregatorTest`), phpcs 0 errors, phpstan clean, sandbox
  `setup:upgrade` + `setup:di:compile` green. Playwright drove Settings >
  Subscribers in both **en_US and et_EE**: consent-card alignment,
  extra-fields spacing, the import area at rest and mid-defensive-state
  (a real job created via `smaily:backfill:start`, left genuinely pending —
  no cron invoked — screenshotted, then cancelled via the UI's real Cancel
  button to end in an honest terminal state), zero module JS console errors
  in any run. Screenshots under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/subscribers-shots/` (`polish2-*`).
  Sandbox restored: admin locale back to en_US; every test backfill job
  created during this pass is left in its real terminal state (completed-
  with-failures or cancelled) — same no-undo-for-a-terminal-state precedent
  as the previous session, nothing reverted by direct DB write.

- **PRO-1397 done — Settings > Subscribers tab rebuilt to target spec
  (§2.3.B), at the Connection tab's finished visual bar.** Rolled the exact
  PRO-1379/1391 pattern onto Subscribers: an H3 "Subscribers" + one-line
  description now sit above the panel (`settings/index.phtml`, matching
  Connection's shell — the CSS selector that used to be
  `.smaily-settings [data-tab="connection"] h3` is now the general
  `.smaily-settings .smaily-tab-panel > h3`, since a second tab now uses it);
  the panel's own in-card `<h2>Who should be synced to Smaily?</h2>` is gated
  to the wizard only (`panel/subscribers.phtml`, same `$isSettings` gate
  Connection's intro uses) since the outer heading now covers it in Settings;
  a tab-scoped footer ("Save Subscribers" + InlineStatus) replaces the
  generic global Save for this tab, reusing the exact `saveTab()` helper
  Connection's own Save button already established — no new save logic.
  **Finding #6 (design-pack "Opt-in mode: Double/Single opt-in" leak):**
  confirmed absent from our code before this task started (grepped the whole
  repo — the only "opt-in" hits are the real `checkout_optin` mode and
  `SuppressNewsletterEmails`'s already-correct comment about the double-opt-in
  *confirmation* email, a different, real, untouched Magento-core concept);
  nothing to delete. **Finding #7 (unstyled checkboxes, ugly spacing):**
  every checkbox on the Settings pages now gets `accent-color: var(--s-accent)`
  (`.smaily-settings input[type=checkbox]`, so this also fixes Automations'/
  Intelligence's/RSS's checkboxes for free); the Subscribers tab's card,
  field labels, notes, the 8-checkbox "extra fields" set (was a crude
  `display:inline-block;width:16rem` grid) and the toggle rows are all
  reworked onto the 4px spacing tokens and the Connection tab's measured
  type scale (13px labels, 12px muted notes). Card width is 680px (not
  Connection's 620px — measured from the design pack's own multilingual
  choice-card frame, `Setup Wizard.dc.html` "FRAME 4", the closest verified
  choice-card-group precedent, since the Subscribers frame in the pack IS
  the leak and carries no real width guidance). **Orphan-field UI
  (`include_guests`, `automation_force_opt_in`, Erkki's 2026-07-14 §4.2
  decision):** both already had working persistence
  (`WizardStepSaver::saveSubscribers()`'s `saveFlag()` calls and
  `WizardData::getBootJson()`'s `includeGuests`/`forceOptIn` keys were already
  wired and untested — genuinely half-dead, exactly as flagged) — this task
  only needed the template checkbox, prefill, collect and reactivity. Placed
  at native `system.xml`'s own sort position (between "extra fields" and
  "checkout newsletter checkbox"), Settings-only (`$isSettings`-gated, not
  shown in the wizard — the wizard's collect.subscribers() omits both keys
  entirely when the controls aren't in the DOM, rather than posting `false`,
  so a wizard save can never silently clear a value only the Settings tab
  edits). `include_guests` has no mode dependency in the UI — its "always on
  in checkout opt-in mode" behavior is a read-time override
  (`Model\ContactSync\Mode::includeGuests()`), not a save-time constraint, and
  native `system.xml` has no `depends` on it either, so forcing/disabling the
  checkbox in the UI would have invented a coupling the real field doesn't
  have. `automation_force_opt_in` **is** hidden outside legitimate-interest
  mode (a new `reactSyncMode()` in `panel/panels-js.phtml`, same family as
  the existing `reactAutomationRow`/`reactSubscribersEnabled`/
  `reactRssEnabled`), mirroring native's own `<depends><field id="sync_mode">
  legitimate_interest</field></depends>`. Both fields added to
  `ConfigOverrides::FIELD_ANCHORS` for consistency with their sibling fields
  on the same tab (the override-awareness banner still applies to every
  other Subscribers field; Connection-style removal of that banner is a
  later, tab-by-tab follow-up per §3 finding #3, not done here). Native
  `system.xml` fields are UNTOUCHED (matching the Connection tab precedent —
  full native-config removal is tracked separately, §4.2/§5, not yet done for
  ANY tab). **Import bug #8 investigated live, no code change needed:** the
  backfill card is confirmed embedded on this tab (§2.5); live-tested on the
  sandbox and initially reproduced the exact symptom ("Importing… 0 / ?"
  showing on a fresh page load with nothing started this session) — traced
  to a genuinely stale `pending` job row (id 8) left over from a 2026-07-12
  verification session, never advanced because this sandbox container runs
  no cron daemon at all (confirmed: no crontab, no cron process) — a sandbox
  characteristic, not an application defect. Recovered via the backfill
  card's own existing "Cancel import" affordance (the legitimate, already-
  built recovery path for exactly this state — no direct DB write used);
  after cancelling, the tab correctly shows the honest terminal state
  ("Cancelled — 0 of ? synced…") instead of a runaway spinner. Re-read
  `Controller\Adminhtml\Api\BackfillState::aggregate()` and
  `panels-js.phtml#renderBackfill()`: a true idle state (zero job rows ever)
  already renders no Pill and no ProgressBar — confirmed by both static
  reading and a clean live reload — so target-spec §2.5(a)'s explicit rule
  is already satisfied; no code fix was needed for this pass. i18n: only one
  new phrase needed — "Save Subscribers" ("Salvesta tellijad") — everything
  else the rebuild needed (`Subscribers`, `Who should be synced to Smaily?`,
  `Include Guest Order Emails`, its comment, `Automations May Re-Subscribe
  (Advanced)`, its comment) already existed in both packs from `system.xml`'s
  own translated strings (en↔et parity preserved, 409 lines each,
  canonical-sorted insertion). New unit tests in `WizardStepSaverTest`
  (2: both orphan flags persist when posted; both stay untouched when the
  key is absent, i.e. a wizard save never clobbers them). **Verification:**
  145→150 unit tests green (2 new), phpcs 0 errors, phpstan clean; sandbox
  `setup:upgrade` + `setup:di:compile` green. Playwright drove the real
  admin Settings > Subscribers tab in **en_US AND et_EE**: default (consent)
  state, legitimate-interest state (force-opt-in row appears, correct ET
  wording), checkout-optin state (include-guests stays a real, unforced
  toggle), a full save round-trip (`include_guests` verified written to
  `core_config_data` and back out again correctly on reload), and the live
  backfill-cancel recovery — zero module JS console errors in any run; the
  Connection tab was re-screenshotted to confirm the shared-selector
  generalization caused no regression there. Screenshots under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/subscribers-shots/`. Sandbox
  restored: admin locale back to en_US, the test `include_guests` toggle
  reset to its prior `0`, the stale job cancelled (a legitimate terminal
  state, not reverted — there is no undo for a cancel, matching real
  merchant recovery).

**All 6 v3 phases implemented** (~110 files) on branch `v3`, version
**3.0.0-rc8 — GitHub pre-release on the fork** (the newest). Current truth:

- **PRO-1391 final-polish done — four refinements on Settings > Connection
  after Erkki's side-by-side review of the PRO-1391 visual-fidelity pass.**
  (1) **In-card intro dropped in the Settings context.** The card's
  `Connect your Smaily account` h2 + long credentials paragraph — carried
  over unchanged from the PRO-1379 rebuild's note that removing it "would
  touch the shared markup, out of scope for a CSS-only pass" — is now gated
  behind the shared `panel/connection.phtml` partial's existing
  `$isSettings` flag (`$block->getData('context') === 'settings'`), the
  same mechanism already used for the subdomain suffix chip and the
  in-card status line. Settings now goes straight from its own outer
  "Connection" h3+description (`settings/index.phtml`, already Settings-
  only) to the fields, matching the design pack's return-visit frame and
  both sibling plugins' `inSettings`-gated `Step1Connect`/`CredentialBlock`
  (confirmed by reading Woo's actual TSX, not just the text-map summary).
  The wizard keeps the intro — untouched. (2) **Field/button wording
  aligned to sibling wording.** "API Username"/"API Password"/
  "Test Connection" → "API username"/"API password"/"Test connection"
  (lowercase second word) in `panel/connection.phtml` (both the default-
  account and mode-A per-language blocks) and `settings/index.phtml`'s tab
  footer, plus the one dependent JS-toast string in `panel/panels-js.phtml`
  ("press Test connection first."). Verified against BOTH siblings' actual
  source (Woo `CredentialBlock.tsx`, Shopify `SmailyConnectForm.tsx` —
  word-for-word identical: "Subdomain" / "API username" / "API password" /
  "Test connection") and the design pack's own `Settings.dc.html` button
  markup ("Test connection"). **Deliberate deviation from the design pack:**
  the pack's mockup literally labels the first field "API subdomain", but
  both siblings and our own existing text-map canonical say plain
  "Subdomain" — kept "Subdomain" per this doc's own tie-break rule
  (sibling wording wins on pack/sibling conflict). i18n: `i18n/en_US.csv` +
  `i18n/et_EE.csv` updated (case-only key renames — Estonian translations
  unchanged; case-insensitive sort order and en↔et parity verified
  unaffected by the rename). (3) **Helper text size — verified, not
  changed.** Live-measured the design pack's own field label/hint/
  description sizes (13px/12px/13px) against our rendered page: already an
  exact match (ported in the original PRO-1391 pass). No further reduction
  applied — going smaller would leave the design's own measured values,
  not approach them. The perceived "still bigger" read traces to (1) and
  (4), not to font-size. (4) **Font rendering ("hairier" than the design)
  — root-caused and fixed.** Playwright-measured the live sandbox's
  computed styles: Magento admin's actual body font is the "Open Sans"
  webfont at default (non-antialiased) smoothing — genuinely different
  from the design pack's system-font stack (`-apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, Helvetica, Arial, sans-serif`, i.e. this module's own
  `--font` token, which other components in `smaily-admin.css` already
  individually opt into). Fix: `.smaily-settings { font-family: var(--font);
  -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }`
  plus the same `font-family` on `input`/`select`/`button` (form controls
  don't inherit it from the UA stylesheet). No new font asset — this is the
  OS-installed system stack the design pack itself renders with, just
  correctly wired to our own scope. Scoped to `.smaily-settings` only per
  the task's explicit instruction, not `.smaily-wizard` — the wizard's own
  visual-fidelity pass is separate, still-pending work (same PRO-1391 scope
  note as before), so it keeps inheriting Magento's Open Sans for now;
  follow-up noted below. **Verification:** all four PHP gates green
  (unit/phpcs/phpstan; sandbox `setup:upgrade` + `setup:di:compile`);
  Playwright re-rendered the real Connection tab in BOTH en_US and et_EE
  plus the wizard's step 1 (confirming the intro survives there), zero
  module JS console errors in any of the three renders; side-by-side
  screenshots against the design reference under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/fidelity-shots/`
  (`polish-side-by-side-en.png`, `polish-side-by-side-et.png`,
  `polish-en-content.png`, `polish-et-content.png`,
  `polish-wizard-step1-en.png`). `simplify` skill run over the diff (4
  parallel review angles) — no fixes needed; the two borderline notes
  (a second form-control selector group for `font-family` alongside the
  existing width-focused one; the `.smaily-settings`-only smoothing scope
  vs. also covering `.smaily-wizard`) are both deliberate and already
  documented in-line, not oversights.

- **PRO-1391 done — visual-fidelity pass on Settings > Connection (CSS only,
  no markup/save-logic change).** The PRO-1379 rebuild matched the design's
  structure but rendered flatter than the pack; this closes the polish gap by
  porting the design's *measured* CSS (from the pack's `Settings.dc.html`
  "FRAME 1: CONNECTION TAB", rendered in Playwright as the reference) into the
  scoped admin sheet `view/adminhtml/web/css/smaily-admin.css`, all under
  `.smaily-settings` so nothing leaks into Magento admin or the shared wizard
  step-1 partial. Applied: **gray canvas** behind the tab (`.smaily-tab-panel`
  → `var(--s-bg)` + `24px 28px` padding) with the credential **card** now a
  white 6px-radius panel (`20px 22px` padding); **helper text** demoted to the
  muted `--s-text-3`/`--s-text-2` roles at `--fs-12`/`--fs-13` (was the
  admin-default prominent brown/olive); **field labels** to `--fs-13`/600;
  **inputs** to the design's `8px 11px` padding + `--s-border-strong` 1px
  border; **subdomain suffix chip**, **status pill** and the **Test / Save
  Connection** buttons matched to the pack's padding/radius/weight. One real
  fix surfaced en route: the new generic input-radius rule was overriding the
  subdomain input's left-only radius (chip join not flush) — resolved with a
  specificity bump (`input[type=text]`) so the chip stays flush. **Verified
  side-by-side** against the design reference in BOTH en_US and et_EE on the
  live sandbox (Estonian's longer strings wrap without breaking layout), zero
  module JS console errors; screenshots + references under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/fidelity-shots/`
  (`design-connection-content.png`, `ours-final-en.png`, `ours-final-et.png`,
  `side-by-side-final-en.png`, `side-by-side-final-et.png`). Gates green
  (unit/phpcs/phpstan; sandbox `setup:upgrade` + `setup:di:compile`). **Scope
  note:** the design pack only mocks the single-account frame; the
  multilingual mode-A per-language credential cards (`#smaily-w-accounts`)
  are not restyled here — tracked as a follow-up. The card's intro
  `h2`+description (shared with the wizard partial) is kept and styled to the
  system; the design's return-visit frame omits it, but removing it would
  touch the shared markup, out of scope for a CSS-only fidelity pass.

- **PRO-1379 done — Phase B pilot: Settings > Connection tab rebuilt to the
  target spec.** First screen of the admin-UI reconciliation (§2.3.A),
  scoped deliberately tight to the Connection tab + the shared Settings
  chrome it needs — the other four tabs and the full native-config removal
  (§4.2) are untouched, still tracked as later Phase B work. **Layout**
  (from the design pack, structure/sizing only): an H3 "Connection" title +
  one-line description now sit above the panel (new, Settings-only —
  `settings/index.phtml`); the credential card and its fields are scoped to
  the design's 620px/440px widths (`.smaily-settings #smaily-w-default-account`,
  CSS-only, so the wizard's own step-1 rendering of the same shared
  `panel/connection.phtml` partial is untouched); a "Status:" line
  (`.smaily-pill` + "as "&lt;subdomain&gt;"") now renders in-card under the
  fields (gated behind a new `context=settings` block argument so the wizard
  is unaffected), live-updated in `panel/panels-js.phtml#initAll`; a
  tab-scoped footer (Test Connection | Save Connection | one shared
  InlineStatus) is rendered by the owner template `settings/index.phtml`
  (chrome lives with the template that owns the tab seam, not the shared
  partial) and replaces the in-card Test Connection button for this tab. The
  page-wide footer (generic Save + native-config pointer) auto-hides on any
  tab that renders its own `.smaily-tab-footer` (presence-based, no tab-name
  list) — so migrating another tab later opts it out for free. Save
  Connection reuses the exact `collect.connect()`/`saveStep('connect', …)`
  path the wizard and the old global Save button already used (refactored
  into one `saveTab()` helper in `settings/index.phtml`, shared by both
  buttons) — no new save logic, just a second, tab-scoped entry point into
  it. **Config direction (§4 decision 2, Connection-scoped):** the raw
  PRO-1274 "Overridden for X" banner is removed from the Connection tab —
  `ViewModel\Adminhtml\ConfigOverrides::FIELD_ANCHORS` no longer lists the
  four Connection fields (subdomain/username/password/multilingual mode), so
  the awareness JS in `settings/index.phtml` simply never finds them to
  decorate; the "Need advanced fields? Stores > Configuration…" cross-link
  is now hidden while the Connection tab is active (shown for the other four
  tabs, which still have native-only orphan fields per §4.2, not yet built
  onto our own pages). **Not done, and deliberately not attempted here:**
  automatic "clear a shadowing override on save" for Connection's fields —
  investigation found the website/store-view `core_config_data` rows
  `OverrideDetector` would flag as "overrides" are, for subdomain/username/
  password, indistinguishable in storage from the per-language rows
  multilingual mode A *intentionally* writes (`WizardStepSaver::saveConnect`);
  a blind auto-clear-on-save would delete mode A's own working per-language
  credentials. Making that distinction safely is bigger than this pilot —
  presentation only ships now (no banner, single source), the save-through
  behavior is a tracked follow-up (see below). i18n: 3 new phrases in BOTH
  packs ("Save Connection", the tab description, `as "%1"` account
  attribution — en↔et parity, canonical sort, 408 each). **Verification:**
  unit/phpcs/phpstan gates green; sandbox `setup:upgrade` +
  `setup:di:compile` green; Playwright drove the real admin Settings >
  Connection tab in en_US AND et_EE, screenshots under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/pilot-shots/`, zero module JS
  console errors in either locale, rendered layout matches the design pack's
  `Settings.dc.html` Connection frame.
- **PRO-1369 tail done — admin UI target spec's config decisions resolved
  (doc only, no code changed).** Erkki decided all three open §4 calls: (1)
  one source of truth = our own pages, no duplication; (2) scope is handled
  by us and never shown to the merchant (the PRO-1274 "Overridden for X"
  banner is slated for removal in favour of auto-clear-on-save); (3) native
  `Stores > Configuration` shrinks to advanced-only or disappears; (4) the
  three per-entity Intelligence sync toggles (Catalog/Customers/Orders) are
  removed, matching both siblings. A new §4.2 config field inventory
  (`docs/ADMIN_UI_TARGET_SPEC.md`) walks every `system.xml` field against
  `ModuleConfigPaths`/`WizardStepSaver`/`ConfigOverrides` to fix a target home
  per field, folds the consequences into the per-screen sections (Intelligence,
  RSS's now-removed "Advanced RSS options" deep link, Subscribers/Automations
  native-only orphans). **The four fields that pass initially flagged as
  genuinely ambiguous are now also decided (Erkki, 2026-07-14):**
  `include_guests`/`automation_force_opt_in` get real controls on the
  Subscribers tab and `abandoned_fields` on the Automations tab (all three
  are real features, currently hidden with half-dead persistence code);
  `multilingual_mode`'s native per-website scope is dropped in favour of one
  mode per instance, owned by the Connection tab; `rss/enabled` drops its
  native per-store-view granularity for a single store-wide toggle on the
  RSS tab; `logging/verbosity` gets a home on the Log page. **Net outcome:
  native `Stores > Configuration > Smaily` disappears entirely** — no field
  keeps a native-only or native-advanced home; single source of truth is the
  module's own pages. None of this is implemented yet — it's the target for
  a future Phase B pass, which also needs to delete the live
  `ProductSaveAfter`/`CustomerSaveAfter`/`OrderSaveAfter` observer sync-gates
  behind the removed Intelligence toggles (not just hide the checkboxes),
  and must not rename/restructure any config path without an explicit
  value-migration step (2.8.x upgrades carry credentials at the existing
  `smaily_connect/…` paths).
- **PRO-1369 done — admin UI target spec consolidated.** The design pack's
  layout/visual extract (`docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md`)
  and the real-functionality + sibling text map
  (`docs/audits/2026-07-14-ADMIN_FUNCTIONALITY_TEXT_MAP.md`) are merged into
  `docs/ADMIN_UI_TARGET_SPEC.md` — the single source Phase B verifies the
  running admin against, per screen: target layout, exposed options mapped
  to real functionality, canonical EST+ENG text (sibling wording wins),
  and an explicit REMOVE list for design-pack leaks (opt-in-mode selector,
  RSS "Store view" dropdown). All ten PRO-1357 findings are mapped to a
  fix location. Three product-direction calls are queued for Erkki, not
  auto-decided: (i) keep vs. remove the native `Stores > Configuration`
  surface, (ii) config-scope override UX refinements, (iii) keep vs. drop
  the per-entity Intelligence sync toggles (both siblings dropped theirs).
- **PRO-1353 done — explicit, consistent store scope for catalog ingest
  (price/URL/language).** Investigation (PRO-1352/1353, 2026-07-14) found
  the backfill collection (`EngineCatalogProcessor::loadPage()`) never set a
  store scope at all (falling back to Magento's implicit current-store
  resolver, undocumented and CLI/cron-context-dependent), while the live
  save/delete path read price off whatever scope the admin's Save controller
  happened to resolve (store 0 unless the merchant picked a store view) —
  the two paths could disagree, and neither was website-aware. Per Erkki's
  binding PRO-1352 decision (no currency field is coming to the wire
  contract; one tenant = one base currency), both paths now resolve through
  ONE canonical store: `CatalogPayloadBuilder::canonicalStoreId()` (the
  default store view of the default website — matching the "default scope"
  concept `Engine\Client` and `Multilingual\AccountResolver` already use).
  The backfill collection calls `setStoreId()` with it explicitly before
  `addUrlRewrite()`/`addPriceData()` (both read the collection's store id at
  call time); the live path (`ProductSaveAfter`/`ProductDeleteBefore` via
  `CatalogPayloadBuilder::build()`) re-scopes the product to it via
  `ProductRepository::getById($id, false, $canonicalStoreId)` before reading
  price whenever the product wasn't already loaded at that scope — a
  deliberate single-pinned-scope simplification, not a per-website fan-out
  (a multi-website install with divergent prices/currencies still ingests
  only the canonical website's price). Every catalog `smaily_ingest_queue`
  row now also carries the resolved `store_id` for audit (the queue's
  existing but previously-unused column). Documented in
  `docs/ARCHITECTURE.md` under "Engine ingest". **Verification:** 2 new unit
  tests pin the price-scope behavior on both paths (`EngineCatalogProcessorTest`
  asserts the collection's `setStoreId()` call; `CatalogPayloadBuilderTest`
  asserts price comes from the canonical-scoped product, and that no reload
  happens when already at that scope) — 148 unit tests green, phpcs 0
  errors, phpstan clean; sandbox `setup:upgrade` + `setup:di:compile` green,
  and a live bootstrap check against the real sandbox product confirmed
  `canonicalStoreId()` resolves to the real default store and the backfill
  collection loads products at that exact scope.

- **PRO-1281 Stage B done — the visual system applied to the screens (PRO-1281
  complete).** Consumed the Stage A tokens + six component classes across the
  real admin templates (a Stage B application section was appended to
  `smaily-admin.css` — screen glue only, tokens/components untouched); no
  functional/behavioral changes. Per screen: **Dashboard** — verdict hero
  (dot + role kicker + text, healthy/degraded/incomplete states via the
  existing verdict logic), connection strip now carries `.smaily-pill`
  (active/off/failed/pending) + sub-lines, metric tiles rebuilt on the
  `.smaily-tile` BEM component (`__label`/`__value`/`__caption`/`__badge`,
  `--attention` on failures), activity status as pills; the legacy single-dash
  tile CSS was removed so the component wins. **Setup Wizard** — stepper gained
  accent circle indices (done = accent check, active = accent ring, via
  tokens); the lawful-basis + multilingual mode cards migrated to the
  `.smaily-choice`/`.is-selected` BEM component (native radio visually hidden
  but focusable, `__radio` indicator, Recommended/Most-common `__badge`); the
  JS `.selected`→`.is-selected` rename is scoped so the two card groups don't
  clear each other. **Settings** — tab strip active-underline recolored to
  accent; per-tab save result rendered through the shared inline-status. The
  shared `result()` helper now renders the Stage A **inline-status** vocabulary
  (CSS-only spinner/check/error glyphs) with state inferred from the message
  (ellipsis = working, ok = saved, else error) — one change covers wizard,
  settings, per-account tests and backfill with zero call-site edits.
  **Engine Automations** — the dense table became `.smaily-engine-trigger`
  cards with a computed run-mode pill (active/test/off from enabled+test_mode);
  the not-connected state is a centered empty-state, the catalog-load-failed
  state a `.smaily-banner--warning` over dimmed saved rows; all `[data-field]`
  hooks preserved (save JS selector updated to `.smaily-engine-trigger`).
  **Log** — native grid untouched; failed-24h banner rebuilt as
  `.smaily-banner--warning`; Details slide-out gained a status pill, an
  info/terminal retry line and PII-redaction tags. **Backfill** — the native
  `<progress>` replaced by the `.smaily-progress` component (track + fill),
  driven by a new `setProgress()` that maps running→striped-accent /
  done→success / done-with-failures & stopped→danger / cancelled→neutral.
  **Carry-over (item 7)** — a deep link to a Smaily config group
  (`#smaily_connect_rss`, reachable from a new "Advanced RSS options" link on
  the RSS tab) lands with the group open; the config assist now opens a
  genuinely-collapsed group defensively (works with either Magento collapsible
  pattern) — in this 2.4.8 build the groups render expanded, so it lands open
  natively. i18n +32 phrases in BOTH packs (en↔et parity, canonical casefold
  sort; 395 each). **Verification:** phpcs 0 errors, phpstan clean, 124 unit
  tests; sandbox `setup:upgrade` + `setup:di:compile` green on the merged
  tree; Playwright drove all six screens in **en_US AND et_EE** — 22/22 checks
  pass (stepper + choice-cards, tab switch + save inline-status, choice-card
  reactivity, backfill progress component, engine empty-state, log Details
  pill + redaction, dashboard tiles/pills/verdict, RSS deep-link opens the
  group, wizard step nav) with **zero module JS console errors** in both
  locales; sandbox admin locale restored to en_US. The engine
  trigger-card active/test/off + validation-error states are now driven live too
  — see the PRO-1288 entry below.
- **PRO-1274 done — Settings page surfaces + clears config-scope overrides
  (option c, Erkki-approved).** The Settings page and wizard always save at the
  DEFAULT scope, but a more-specific `core_config_data` row (the website-scope
  subdomain the 2.8.x migration seeds; the per-store-view credentials
  multilingual mode A writes) shadows it at runtime — so a merchant "saved" a
  value and saw different effective behaviour with no signal. A lightweight
  awareness+clear layer now mirrors Magento's native "Use Default" semantics on
  the module's OWN Settings surface (the system.xml scope switcher under Stores >
  Configuration is untouched). **Detection:** `Model\Config\OverrideDetector`
  reads the config-value collection directly (real stored rows, not merged/cached
  ScopeConfig) for the module's overridable paths and reports every website /
  store-view row that shadows the default, labelled with the website/store name
  (global website-0 rows are skipped — they don't shadow a specific scope).
  **Indicator:** the Settings page (`ViewModel\Adminhtml\ConfigOverrides` maps
  each field's DOM anchor → config path) injects, next to exactly the shadowed
  fields, a `.smaily-banner--warning` with a `.smaily-pill--neutral`
  "Overridden for <scope>" per shadowing scope — reusing the Stage-A component
  classes (only 3 lines of layout glue added, no new component CSS). **Clear
  (= Use Default):** a per-scope button → `window.confirm` →
  `Controller\Adminhtml\Config\ClearOverride` (ACL `Smaily_Connect::config`,
  admin form-key via the shared `AbstractJsonAction`/`?form_key=` contract) →
  `Model\Config\OverrideClearer`, which validates the path against the module
  allowlist (`Model\Config\ModuleConfigPaths`, built from the Config /
  EngineSettings constants so it can't drift) and the scope (websites/stores
  only, never the default the page owns) BEFORE calling
  `WriterInterface::delete($path, $scope, $scopeId)` on that one row, then
  flushes the config cache and returns an honest per-scope result rendered via
  the shared inline-status. Reversible (re-set the override at its scope);
  confirm is the guardrail. New tests: `OverrideDetectorTest` (3: absent /
  single website / two scopes + global-row skip) and `OverrideClearerTest`
  (5: rejects a non-module path with NO delete, deletes exactly the requested
  website + store-view path/scope, rejects default scope + invalid scope id) —
  plus a `ConfigDataCollectionFactory` unit stub. i18n +10 in both packs (en↔et
  parity, 405 each). Gates: 145 unit tests, phpcs 0 errors, phpstan clean;
  sandbox `setup:upgrade` + `setup:di:compile` green (the four new autowired
  classes resolve with no di.xml — and PRO-1292's `AutomationsForm`
  ResolverInterface DI compiled clean on the same tree). Playwright en_US AND
  et_EE against the sandbox: seeded website + store-view overrides render the
  "Overridden for X" markers on the right fields (subdomain showed both the
  website `demo2` and a store-view row; username its store-view row); Use
  default → confirm removed exactly that `core_config_data` row (verified gone
  in the DB, baseline website rows preserved) and the indicator; the Estonian
  pack rendered every string ("Siin salvestatud, kuid alistatud…", "Alistatud:
  …", "Kasuta vaikeväärtust"); zero module JS console errors in both locales.
  Sandbox restored (seeded overrides cleared, admin locale en_US).
- **PRO-1292 fixed — engine-automations trigger title/description are now
  admin-locale-aware.** `ViewModel\Adminhtml\AutomationsForm` read the engine
  catalog's `name_en`/`description_en` only, so trigger titles/descriptions
  stayed English under et_EE while the surrounding chrome localized (the latent
  i18n gap flagged at the end of the PRO-1288 entry below). A new `localized()`
  helper now picks `<field>_<lang>` where `<lang>` is the 2-letter code of the
  resolved admin locale (`et` for et_EE), falling back to the `_en` field when
  the localized field is absent or blank; the locale is resolved via the
  standard `Magento\Framework\Locale\ResolverInterface` (adminhtml-bound to the
  backend resolver — no other module block used a locale resolver before, so
  this introduces the canonical mechanism). Defensive: unknown/unparseable
  locale or a missing/whitespace-only localized field → `_en`. New unit test
  `Test/Unit/ViewModel/AutomationsFormTest.php` (5 cases: et→`_et`, et with
  `_et` missing→`_en`, et with `_et` blank→`_en`, en→`_en`, unknown→`_en`).
  Gates: 137 unit tests green, phpcs 0 errors, phpstan clean. (The `recipe`
  field already had an en/et fallback and is unchanged.)
- **PRO-1288 done — engine-automations connected-states validated live
  (verification only, no code change).** The four states PRO-1281 Stage B left
  un-driven (the sandbox engine was disconnected) were exercised against a
  CONNECTED engine (the shopify-connect `packages/mock-engine`, served on the
  docker bridge at `172.20.0.1:9876`; connected via the real Settings >
  Intelligence setup-exchange). Playwright drove all four in **en_US AND
  et_EE**: **off** — both triggers render `.smaily-pill--off` ("Off"/"Väljas")
  with no stored config (fail-closed default); **active** — a seeded
  enabled+non-test config renders `.smaily-pill--active` ("Active"/"Aktiivne")
  with the green card accent; **test** — enabled+test renders
  `.smaily-pill--test` ("Test mode"/"Testrežiim") with the blue accent;
  **per-field validation error** — an invalid Test Emails value posts to the
  engine, which 422s, and the card's inline-status shows the field-level
  message "winback_risk / test_emails.0: Invalid email …" (Estonian: "… Midagi
  ei salvestatud …") — a named trigger/field, not a raw exception fragment. The
  not-connected empty-state and the catalog-load-failed `.smaily-banner--warning`
  (+ `is-degraded` dimming) were spot-checked and still render (banner driven
  with a revoked-key 401 — a transient 500 is retried and would not surface it).
  **Zero module JS console errors** in either locale. Card markup, computed
  run-mode pill and inline-status all render as designed — no visual fix needed.
  Sandbox restored afterwards: engine disconnected (only
  `smaily_connect/intelligence/browse_tracking=0` remains, as before), admin
  locale back to en_US. Known follow-up (pre-existing, not a PRO-1288
  regression): the engine trigger **name/description** come from the catalog's
  `name_en`/`description_en` only, so they stay English under et_EE while all
  surrounding chrome localizes — a latent i18n gap in the catalog-driven
  content, out of scope here.
- **PRO-1281 Stage A done — Phase 3 design foundation (CSS only, no screens
  touched).** Landed the Design agent's visual system into
  `view/adminhtml/web/css/smaily-admin.css` (already loaded on all four admin
  pages): (1) the `:root` **token sheet** — surfaces/borders, text/link,
  Smaily accent `#e91e63` (selection/focus/progress) kept distinct from
  Magento action-orange `#eb5202` (primary buttons stay native), status role
  trios (success/warning/danger/info/neutral/parked = fg + soft-bg + border),
  banner left-bar colors, 4px-base spacing, radius, type scale, system font
  stacks, elevation — token names copied byte-for-byte from the spec so Stage
  B's per-screen annotations line up. (2) Six **reusable component classes**,
  BEM-ish, matching every documented variant/state: `.smaily-choice`
  (`.is-selected` accent border+ring+tint, radio/title-row/desc/Recommended
  badge — distinct from the legacy orange `.smaily-ui .smaily-choice.selected`,
  which is untouched so current screens don't change), `.smaily-pill`
  (`--active/test/off/sent/pending/failed/parked/neutral` + `__dot`),
  `.smaily-banner` (`--success/info/warning/error`, 4px left bar +
  icon/title/message/action slots), `.smaily-inline-status`
  (`.is-idle/working/saved/error` + CSS-only spinner/check/error glyphs),
  `.smaily-tile` (`--attention` + label-row/badge/value/caption),
  `.smaily-progress` (`.is-running` striped accent → `is-done/failed/stopped`
  recolor). Light adminhtml only (token sheet defines no dark mode). No
  external assets. **Nothing is wired into a template** — that is Stage B.
  Verification: CSS self-consistency checked (every `var(--x)` referenced is
  defined in `:root`; braces balanced); gates green (124 unit, phpcs 0 errors,
  phpstan clean — phpcs lints php/phtml only, and no PHP changed). Browser/
  Playwright visual proof is deferred to Stage B (screens applied), en+et.
- **PRO-1280 done — contract v1.4.1 synced + catalog/order-line identity
  fallback made symmetric.** (1) `docs/RECENGINE_API_CONTRACT.md` overwritten
  byte-identical from engine `945b7ad` (version header now **1.4.1**, Appendix E
  has the `v1.4.1` block; staleness check green). The v1.4.1 changes are
  documentation-only (no wire/schema change): §3 catalog `tags` example gains
  `"product_id": "7620134"`, the cross-variant-grouping bullet is now "live",
  and the §3 identity rule now states Magento's catalog `sku` field IS the
  platform-canonical key (mandatory + store-unique) with `mag-<entity_id>` as a
  fallback ONLY when the SKU field is empty — the "never the merchant SKU field"
  rule is Shopify/Woo-specific. No fixture drift: catalog already emits
  `tags.product_id` as a string (PRO-1231), matching the documented example
  shape. (2) Symmetric-fallback fix: `CatalogPayloadBuilder::sku()` keys an
  empty-SKU product on `mag-<entity_id>`, but `OrderPayloadBuilder` emitted the
  raw `getSku()` (i.e. `""`) with no equivalent — so a pathological empty-SKU
  product's catalog row and order line would key on `mag-<id>` vs `""`, never
  join (broken attribution + cadence), and `""` is a cross-product collision
  magnet. `OrderPayloadBuilder` now routes the line `sku` through a `sku()`
  helper that falls back to `mag-<product_id>` when `getSku()` is empty;
  `sales_order_item.product_id` IS the catalog `entity_id`, so the two paths
  now emit the identical key (contract §3 "Same key from every path"). Pure
  defensive plugin-side change, no engine change. 2 new unit tests
  (empty SKU + whitespace-only SKU → `mag-<product_id>`). Gates: 124 unit
  tests green, phpcs 0 errors, phpstan clean.
- **PRO-1269 fixed — catalog `product_url` is always the clean storefront
  URL, in any execution context.** `getProductUrl()` resolves against the
  *current* app environment, so when the catalog payload is built under
  CLI/cron (the `EngineCatalogProcessor` backfill job, cron flushers) the URL
  could embed the invoking PHP entry script path (observed during the
  2026-07-11 mock-engine walk: `.../run-job.php/smaily-walk-tee.html`) — a
  link that 404s in a recommendation email. `CatalogPayloadBuilder` now routes
  every `product_url` through a `productUrl()` helper that wraps the URL
  generation in forced frontend store emulation (`Store\Model\App\Emulation`,
  `Area::AREA_FRONTEND`, force=true) — the same idiom `Cron\AbandonedCart`
  already uses — with emulation always stopped in a `finally`. The
  multi-language branch emulates each language's representative store; the
  single-language branch emulates the product's own store view, falling back
  to the default store view when the product carries only the admin scope
  (`store_id` 0, the usual CLI/cron/backfill case — not a storefront). New
  unit test `testProductUrlIsBuiltUnderFrontendStoreEmulation` asserts the
  forced-frontend start + always-stop and the clean URL out; the constructor
  gained the `Emulation` collaborator (autowired, no di.xml). Gates: 122 unit
  tests green, phpcs 0 errors, phpstan clean.
- **PRO-1275 fixed — pre-payment guest abandoned carts are now reminded.**
  Magento fills `quote.customer_email` only once payment info is submitted,
  so a guest who typed an email and abandoned at/before the shipping step
  carried it only on the `quote_address` (billing, then shipping). The cron
  keyed the selection AND the recipient on `customer_email`, so those carts
  were never found. Two coordinated changes: (1) `Cron\AbandonedCart` no
  longer filters on `customer_email` alone — a new `requireAnyEmail()` LEFT
  JOINs the billing and shipping `quote_address` rows and widens the WHERE to
  any quote carrying an email in EITHER place (at most one billing + one
  shipping row per quote, so page-size batching is preserved); (2)
  `Model\AbandonedCart\PayloadBuilder` resolves the recipient with the same
  fallback order — `customer_email` → billing address email → shipping
  address email (lowercased/trimmed) — via a new `resolveEmail()`. Consent
  guardrails are untouched: this changes only WHO is found, not the opt-in
  logic (`force_opt_in` still follows the configured lawful basis; the
  already-mailed side table still dedupes; the `is_active`/`items_count`/idle/
  24 h-backlog guards are unchanged). New unit test
  `Test/Unit/Model/AbandonedCart/PayloadBuilderTest.php` (4 cases: customer
  email used when present; NULL customer email → billing address email; empty
  customer + empty billing → shipping address email; no source → empty). New
  unit-test stub `Test/Unit/Support/Stub/ProductCollectionFactory.php`
  (code-generated factory, mirrors the existing ImageFactory stub). Gates:
  121 unit tests green, phpcs 0 errors, phpstan clean. USER_GUIDE
  abandoned-cart section clarified (email can come from the checkout address,
  not just the payment step).
- **PRO-1286 fixed — the missing-workflow-id preserve rule now covers all
  four save surfaces (PRO-1268 follow-up).** The "keep a saved workflow id
  when the posted value is empty AND that id is not in the freshly loaded
  Smaily list (empty/unloadable list = every saved id counts as missing =
  kept)" decision was extracted to one shared method
  `ConfigRowNormalizer::isMissingFromList()` and reused — no copy-paste — on
  the three surfaces PRO-1268 left exposed: (1) **per_language mapping
  fallback** in `ConfigRowNormalizer::normalize` — the old
  `workflowId === fallback` check wiped a per-language map when a missing
  fallback posted empty; the per_language branch now also preserves the whole
  map when the empty post's saved fallback id is missing from the list (a
  present fallback cleared to "-- Not Selected --" still collapses to an empty
  single map — honest clear kept); (2) **Wizard/Settings single-mode selects**
  (`.smaily-w-workflow`) via `WizardStepSaver::saveAutomations` — it now reads
  each saved workflow id from `Config`, resolves the live list once
  (`SmailyClientProvider::forStore(null)`, lazily, only when a workflow key is
  posted), and skips the `configWriter->save` (preserving the stored binding)
  when the posted value is empty and the saved id is missing; a present id
  cleared still writes `0`; (3) **Per-language mapping editor**
  (`MappingSaver`, full-desired-state sync) — `save()` gained an optional
  `array<accountKey, string[]> $availableByAccount`; a stale existing row
  (absent from the desired state) whose workflow id is NOT confirmed in its
  account's live list — including an unresolved account key or an empty/failed
  list — is preserved instead of deleted; `WizardStepSaver` builds the
  per-account lists ('default' + each detected language, so mode A's
  per-language accounts and mode B's shared account are both covered). Callers
  that pass no lists (the 2.8.x migration, plain resaves, tests) keep the plain
  full-sync delete (the param is nullable/opt-in). UI parity: the shared panel
  selects (`fillWorkflows`/`fillMappingSelects` → new `fillOptions`) now render
  a retained-but-missing saved id as a labelled selected option (reusing the
  existing PRO-1268 phrase "Workflow #%1 (not in your Smaily list — kept)", no
  new i18n) so the binding stays visible and re-posts. New tests: 5 unit cases
  on `ConfigRowNormalizerTest` (per_language missing-fallback preserved,
  list-load-failure preserved, present-fallback clear honored, plus the shared
  `isMissingFromList` decision table), a new `Test/Unit/Model/Adminhtml/
  WizardStepSaverTest` (4 cases: missing preserved, list-load-failure
  preserved, present clear honored, new selection stored) and 3 new
  `MappingSaverTest` integration cases (missing-id preserved / present-id clear
  deleted in one full sync, empty-or-unresolved-account-list preserved, opt-in
  behaviour — no lists = plain full-sync delete). Gates: 132 unit + 56
  integration tests green, phpcs 0 errors, phpstan clean, sandbox
  `setup:di:compile` green (the two new autowired constructor args on
  `WizardStepSaver` — `SmailyClientProvider` + `ConfigRowNormalizer` — resolve
  with no new di.xml).
- **PRO-1268 fixed — engine-automations save preserves a binding whose
  workflow id is missing from the Smaily list.** The Campaign Intelligence
  automations block (`config/engine-automations.phtml` → `Automations\Save`)
  rendered its workflow `<select>` only from the freshly loaded Smaily
  workflow list; when a previously saved workflow id was absent (deleted in
  Smaily, or the list failed to load) the option was gone, so an empty post
  silently dropped the binding on save. The `per_language` branch already
  reconstructed its map from the `original_map` hidden field — the
  single-mode branch did not. The per-row normalization was extracted to a
  pure, unit-tested `Model\Automation\ConfigRowNormalizer`; its single-mode
  branch now keeps the saved id (from `original_map`) whenever the posted
  workflow id is empty and the saved id is NOT in the current Smaily list
  (`Automations\Save` fetches that list once via `SmailyClientProvider`; an
  unloadable list = every saved id treated as missing = kept). A saved id
  that IS in the list but was cleared to "-- Not Selected --" is still an
  honest clear. The template also renders the missing id as a visible
  selected option ("Workflow #123 (not in your Smaily list — kept)", new
  i18n phrase in both packs, 363 each). New unit test
  `Test/Unit/Model/Automation/ConfigRowNormalizerTest.php` (8 cases: missing
  id preserved, list-load-failure preserved, deliberate clear honored, new
  selection stored, per_language preserve/collapse, numeric clamps).

- **Real-Smaily-credentials walk done — the last unverified pre-release
  surface is green.** All Smaily campaign-API happy paths exercised against
  a live Smaily test account (Playwright + queue/DB checks + server-side
  API verification; engine stayed disconnected on purpose): Test Connection
  success path (typed creds + saved-credentials state render), workflow
  dropdowns populating with the account's real automation workflows
  (Settings > Automations, wizard step 3, refresh button), storefront
  newsletter subscribe → contact.sync + welcome automation.trigger queue
  rows flushed SENT by cron and the contact verified present/subscribed in
  Smaily via API, contacts backfill from wizard step 2 ("Done, 4 of 4
  synced.", live progress bar, outcome persisted across reload, contacts
  spot-checked server-side), guest checkout with the opt-in checkbox →
  subscriber → contact subscribed in Smaily end-to-end, abandoned-cart cron
  → autoresponder enroll accepted by the real workflow (cart product
  fields verified upserted onto the Smaily contact; force_opt_in=false in
  consent mode per the API guardrail), suppress-opt-in-emails toggle
  round-trip, and the Settings-tabs regression (Connection shows connected,
  Automations loads with no error line, dashboard verdict healthy, unified
  Log all-Sent). **Two real bugs found and fixed:** (1) the workflow list
  used `GET autoresponder.php?status=ACTIVE`, which returns ALL active
  automations — the dropdown offered workflows whose trigger type is not
  "form submitted", and POST autoresponder.php rejects those with the
  misleading 221 "Invalid autoresponder ID" (live-reproduced; the welcome
  trigger failed). Now `GET workflows.php?trigger_type=form_submitted`
  (Woo-client parity — NB: the Shopify repo's "workflows.php is not a real
  route" lesson is wrong, it responds fine on production Smaily) with
  disabled workflows filtered out (enrolling one returns 101 but silently
  sends nothing). (2) `store_group` was always empty in contact +
  abandoned-cart payloads (`getStoreGroup()` magic getter; the real method
  is `getGroup()`) — fix verified live in Smaily contact data. Real creds
  removed from the sandbox afterwards (fake-creds state restored); test
  contacts erased from the Smaily account via `POST contact/forget.php`.
  Hyvä 1.5.2 installed into the sandbox from the public GitHub sources (no
  portal key needed; the exact reproducible recipe — 10 VCS repos incl. the
  non-obvious `magento2-compat-module-fallback` + the Mollie chain,
  `"no-api": true` to dodge the GitHub API rate limit, in-repo module
  registered via an `app/code/Hyva/SmailyConnect` symlink, Node 20 Tailwind
  builds in the vendor theme dirs, `hyva_theme_fallback` config for the
  Luma-fallback checkout — is in `docs/HYVA_SUPPORT.md`). Default store
  view runs `Hyva/default`, the `et` store view stayed on Luma as the
  regression control. Every matrix cell passed (Playwright, mock engine as
  the receiving end): compat tracker/attribution on Hyvä (product_view /
  search / cart_add-via-form-submit with sku, campaign-click cookies,
  consent matrix off/on-without/on-with — identity hint dropped and
  restored), page-context blocks execute, Luma-fallback checkout renders
  Luma with the opt-in checkbox working (toggle persists, order placed,
  checkout_start + checkout_complete fire), personalization page fully
  Tailwind-styled (computed styles prove the `hyva:config:generate` content
  scan), Hyvä's own newsletter form feeds our observers, and the **strict
  CSP column is done for real**: `Hyva/default-csp` + enforced storefront
  CSP with `unsafe-inline` removed — zero CSP violations, context blocks
  hash-whitelisted via SecureHtmlRenderer, tracker/attribution need no
  whitelisting at all (static files only). Luma regression green (base
  tracker via `ajax:addToCart`, et_EE opt-in label). Zero module console
  errors everywhere; one third-party artifact documented (Hyvä's toast
  auto-dismiss throws a benign `Transition was skipped` pageerror — it
  reproduces with all Smaily assets blocked). **One real base-module bug
  found and fixed:** the personalization page was FPC-cacheable, and
  Magento's depersonalization made `PrivacyForm` always render the default
  checked state (a saved opt-out never showed; the cached page would be
  shared within an FPC vary group) — `cacheable="false"` on the form block
  now, the core My Account pattern. Both `TODO(hyva-store)` markers
  resolved (cart_add verified on stock Hyvä; precise note kept for
  third-party AJAX-cart modules that bypass the submit event). Remaining on
  the work pack: compat-package publication (name decided:
  `smaily/module-connect-hyva`) and the Hyvä Checkout boundary
  confirmation. Sandbox left with
  Hyvä on the default store view + Luma on `et`; engine restored to
  disconnected.
- **UI/UX parity phase 2b done (PRO-1272) — observability depth.**
  (1) Per-row **Details** drill-down in the unified Log: an actions column
  whose custom JS component (`js/grid/columns/log-actions`, overriding
  `isHandlerRequired` — the stock actions column attaches NO click handler
  to plain-href actions) loads `Controller\Adminhtml\Log\Details` into a
  native slide-out modal; the template shows attempt count, honest retry
  state (next retry time / "will NOT retry on its own"), last error,
  payload-as-sent and last response, all through the new
  `Model\Log\PayloadRedactor` (secret-looking keys → `[redacted]`, emails
  masked `e***@g***.com` — last_error is masked too, it routinely quotes
  the contact). Mass retry unaffected. (2) Failed-24h banner above the Log
  (reuses `QueueHealth`, hidden at 0) deep-linking to the grid pre-filtered
  via the core `Magento_Ui/js/grid/url-filter-applier` mechanism
  (`?filters[status]=failed`); the dashboard verdict action + failed tile
  link there too. (3) Backfill cancel + outcome honesty: `JobManager`
  transitions are now race-safe conditional UPDATEs (progress writes never
  touch status, terminal transitions only move active rows, so an admin
  cancel always wins; no schema change — `cancelled` already existed), all
  4 processors stop at the next page boundary via a fresh `isCancelled`
  read, `BackfillState` gained `action=cancel` + `finished_at`/`error` in
  the aggregate, and the shared panels render persisted outcomes on load:
  "Done, X of Y synced" / "Done … — N failed" with a pre-filtered Log link
  (parked/pending-retry rows are NOT counted failed) / "Stopped before an
  error" / "Cancelled" (+ timestamp); cancel = fresh start on restart.
  28 new phrases in both i18n packs (invariants held; the follow-up
  hygiene sweep dropped the 2 phrases this pass obsoleted and restored
  the canonical sort — both packs now at 362). Verified:
  99 unit + 46 integration tests (8 new `JobManagerTest` cases covering the
  cancel races), phpcs/phpstan clean, `setup:upgrade` in the sandbox, and
  Playwright en + et_EE (banner + pre-filtered grid, redacted modal, mass
  retry, live cancel of a pending job + fresh restart + completed-with-
  failures outcome + persistence across reload, wizard step 2 shares the
  same controls, zero module JS console errors). Container
  `setup:di:compile` was deferred past this pass (the concurrent phase-2c
  worktree broke the compiler) and has since PASSED on the merged 2b+2c
  tree. Sandbox restored (seeded rows removed, admin locale back to
  en_US).
- **Multilingual UX done (PRO-1273, phase 2c) — browser-validated.**
  Built in the shared-panel idiom (live re-render,
  no save round-trips): (1) routing-mode choice cards on the Connection
  panel (wizard step 1 + Settings > Connection via the shared partial;
  radio-card idiom from the step-2 lawful-basis cards; rendered only when
  `Multilingual\AccountResolver` detects >1 store-view language, else
  locked to `single`; destructive mode-switch guarded by a native
  confirm); (2) mode A UI — per-language credential blocks (per-block
  Test Connection; saved accounts re-test via a new `store_id` fallback
  on the testsmaily endpoint) + default-fallback account picker whose
  account's credentials double as the default scope (new config path
  `smaily_connect/connection/fallback_language`); feeds the existing
  `WizardStepSaver::saveConnect` `accounts[]` handler (extended only
  with `fallback_language` + store-view credential cleanup when leaving
  mode A); (3) mode B/A per-language workflow mapping editor on the
  Automations panel (per trigger: workflow select per language, loaded
  live per account in mode A, + default-fallback radio); writes
  `smaily_automation_mapping` through the new
  `Model\Automation\MappingSaver` (full-desired-state sync: unique-key
  upsert, cleared rows deleted, other websites' rows untouched) behind
  the existing savestep endpoint; (4) **account_key alignment with Woo**
  — `Automation\Router` now returns a `WorkflowMatch` (workflow +
  account_key) and `AutomationHandler` posts through the account the
  mapping row names (fallback rows included; config-default resolutions
  keep following the store view; single/c unchanged); Router lookups now
  also see global rows (`website_id 0` — the scope the editor writes and
  the default-scope migration seeds; website-specific rows win), fixing
  the latent "default-scope migration seeds never matched" gap.
  `Model\Adminhtml\AccountResolver` moved to
  `Model\Multilingual\AccountResolver` (it now serves runtime routing
  too). New tests: `RouterTest` (10 unit — all four modes, fallback,
  account_key, website preference, terminal skips) and
  `MappingSaverTest` (7 integration — upsert idempotency, cleared-row
  deletion, fallback normalization, scope discipline, Router reading
  real SQL back). i18n +32/-1 phrases in both packs (invariants held).
  Docs: USER_GUIDE multilingual section rewritten for the new UI,
  ARCHITECTURE routing + panel sections, CHANGELOG. Verified: 109 unit +
  45 integration tests, phpcs (0 errors) + phpstan clean,
  `setup:upgrade` + `setup:di:compile` in the sandbox on the merged
  2b+2c tree, and the post-merge Playwright pass in en_US AND et_EE:
  mode cards render on the 2-language sandbox with live section
  re-render (no save round-trips); mode-A save lands per-language
  credentials in the right store-view scopes (`stores/1` en, `stores/2`
  et) with the fallback account doubling as the default scope +
  `fallback_language`; per-block Test Connection posts typed creds or
  the saved-account `{store_id}` fallback (fake creds fail
  non-blocking); the client-side guards (incomplete block, missing
  fallback) fire; the destructive confirm appears when leaving a saved
  a/b mode, dismiss reverts, accept switches live, and leaving mode A
  on save removes the store-view credential overrides; the mode-B
  mapping editor renders 3 trigger tables × 2 languages + None row,
  save writes `smaily_automation_mapping` (full-desired-state: the
  migration-seeded `default` rows are replaced, re-save is id-stable
  idempotent, a cleared select deletes its row) and prefills on
  reload; wizard steps 1/3 share the same partials and step gating
  still works; regression sweep green (dashboard verdict, unified Log
  + Details modal + failed-24h banner deep link, backfill cancel
  button, zero module JS console errors); et_EE renders every new
  surface in Estonian. One small UX bug found and fixed in that pass:
  the destructive-mode-switch confirm fired when leaving an a/b mode
  that was merely selected, never saved — the confirm baseline now
  tracks the SAVED mode, so unsaved exploration between the cards
  never asks. Sandbox config/mapping table restored byte-identical to
  pre-test state afterwards.
- **UI/UX parity phase 2a done (PRO-1271) — IA consolidation + full
  dashboard.** The admin is now four pages under Marketing > Smaily
  Connect: **Dashboard** (new landing page: one-sentence health verdict
  reusing the HealthCheck cron's state/query via the extracted
  `Model\Health\QueueHealth`, connection strip, truthful queue-backed
  tiles — catalog tile omitted while the engine is disconnected —
  recent-activity feed, quick links + contextual CTAs), **Setup Wizard**
  (moved to `smaily_connect/wizard`), **Settings** (new tabbed page:
  Connection / Subscribers / Automations incl. the embedded
  engine-automations block / Intelligence incl. engine backfills /
  RSS incl. the URL builder; deep-linkable `?tab=`, per-tab AJAX save via
  the same WizardStepSaver + new `rss` step, live reactivity incl.
  credential-edit → workflow-dropdown refresh) and **Log** (ONE unified
  grid: `Model\ResourceModel\Log\Collection` UNION ALL over both queue
  tables keyed by synthetic `log_id`, source filter, cross-queue mass
  retry). Historical Import and the two old log pages/grids are gone
  (jobs/queues untouched); wizard step content was extracted into shared
  partials (`view/adminhtml/templates/panel/`, shared JS in
  `panel/panels-js.phtml`) that both wizard and Settings render — still
  one config source of truth. Wizard-first gating: setup-incomplete
  installs redirect every Smaily page to the wizard
  (`Model\Adminhtml\SetupGuard`); after a MAJOR version jump (tracked via
  `smaily_connect/internal/last_seen_version`, version read from
  composer.json) a one-time review notice is posted instead of a
  redirect. i18n regenerated (304 phrases, invariants held), docs
  (README/USER_GUIDE/ARCHITECTURE/TESTING/CHANGELOG) updated. Verified:
  93 unit + 38 integration tests, phpcs/phpstan clean, setup:upgrade +
  di:compile in the sandbox, and Playwright en + et_EE (menu = exactly 4
  items, dashboard in degraded/ok states + incomplete-redirect, per-tab
  saves land in `core_config_data` and restore, reactivity without saves,
  unified log filter + 3-row cross-queue mass retry, zero module JS
  console errors); sandbox config/queues restored to pre-test state.
- **2.8.x migration** — sandbox-verified end-to-end: a store on the legacy
  2.8.x extension upgrades via plain `composer update` (same package name) and
  its settings carry over seamlessly.
- **Native admin UX round 1 done** — setup wizard, AJAX config with instant
  feedback, engine automations embedded in the unified automations UI.
- **UI/UX parity phase 1 done (PRO-1270)** — six quick wins from the parity
  analysis: (B1) wizard stepper no longer re-locks completed steps on Back
  and supports forward-clicks through reached steps (session `maxStep`,
  seeded from saved connection/setup-completed state); (B2) all our own
  exception messages that surface in the admin (engine client, Smaily API
  client, provider) are wrapped in `__()`, and the raw passthroughs
  (EngineExchange, Workflows, EnginePing) got translated framing sentences —
  37 new phrases in both CSV packs (invariants held: unique sources, en↔et
  1:1, placeholder parity); (A4) live Feed URL Builder frontend_model on the
  Product RSS Feed config group (category/limit/sort/order → URL +
  copy-to-clipboard with execCommand fallback), linked from the wizard Done
  step; (A5) user-guide links on the wizard Done step, in the post-install
  admin notice (Read Details URL) and as a system.xml section-header comment
  (GitHub URL for now, marked to move to a hosted docs site); (B5) step-2
  sync-field checkboxes are server-rendered instead of jQuery string-built
  HTML; (B6) wizard inline `<style>` extracted to
  `view/adminhtml/web/css/wizard.css` loaded via layout XML. Browser
  re-validated with Playwright in en_US AND et_EE (36+15 checks green:
  stepper behavior, server-rendered labels, builder URL correctness + a live
  200 RSS response for the built URL, clipboard copy, docs links, no module
  JS console errors; before/after screenshot diff of wizard steps 1-2 shows
  only the intended stepper unlock). One pre-existing noise finding: the
  sandbox's bundled `paypal/module-braintree-core` `system.js` throws
  `locations.each is not a function` on the system-config page — third-party,
  not ours.
- **i18n done (PRO-1200)** — `i18n/en_US.csv` (canonical inventory, 242
  phrases) + `i18n/et_EE.csv` (full Estonian pack, Woo-plugin vocabulary);
  covers system.xml, menu/ACL, layout/ui_component XML, phtml `__()` and the
  KO `i18n:` binding. Two previously untranslatable user-facing strings
  wrapped (backfill "already running" notice, wizard unknown-step error).
  Estonian rendering eyeballed in the sandbox (admin wizard/config/grids/menu
  + storefront personalization page); two rendering bugs found and fixed —
  see the browser-validation entry below. The checkout opt-in checkbox is
  now also verified in-browser on the et_EE storefront ("Liitu meie
  uudiskirjaga") — see the engine happy-path entry.
- **Admin UX browser-validated end-to-end** (Playwright vs the sandbox):
  login, menu, wizard all 5 steps (per-step AJAX saves verified in
  `core_config_data`, step gating, non-blocking bad-credential Test
  Connection, backfill start + progress polling, graceful engine-exchange
  failure), config page (Test Connection without save, AJAX workflow
  dropdowns, embedded engine automations block), event/ingest/backfill grids
  with intro blocks, zero module JS console errors, and the et_EE locale
  pass. Two i18n rendering bugs fixed in that pass: (1) grid intro texts
  used layout-XML `translate="true"`, whose evaluation is frozen into the
  locale-agnostic admin layout cache — now translated at render time in
  `intro.phtml`; (2) all `$t()` strings in phtml inline scripts (wizard,
  config assist, engine automations) never reach `js-translation.json`
  (Magento only collects from `.js`/`.html`) so they always rendered
  English — now translated server-side with `__()` + `escapeJs`.
- **Engine happy paths sandbox-verified against the mock engine** (Playwright
  + the shopify-connect `packages/mock-engine` served on the docker bridge):
  wizard step-4 setup exchange (credentials/endpoints map/config stored,
  ping ok, health-check flags clear), embedded engine-automations form
  (catalog §11 loads, PUT §13 all-8-keys upsert lands with
  `configured_via=plugin`, state persists across reload), storefront
  browse tracking end-to-end (product_view / cart_add / checkout_start /
  checkout_complete through the `smaily/relay` proxy to engine browse
  ingest; a campaign-click landing carries `smaily_visitor_token` + rec id
  + ctx; cookie-restriction-ON sender-side anonymous mode verified both
  without and with the consent cookie), live catalog/order ingest + engine
  catalog/customers/orders backfills (queue rows drain to `sent`,
  `tags.product_id` present in catalog payloads), §3b hard-delete →
  `catalog_remove` row → `POST ingest/catalog/remove` (PRO-1231), and
  guest checkout opt-in checkbox rendering + toggle persistence
  (`smaily/checkout/optin` → `smaily_abandoned_cart.newsletter_optin`),
  incl. the et_EE label "Liitu meie uudiskirjaga". Four real bugs found
  and fixed in that pass: (1) `parseSetupInput` forced https and DROPPED
  the port from a pasted setup URL — any engine not on 443 was
  unreachable; now preserves the pasted scheme+port like Woo's
  `parse_setup_url`; (2) the tracker's `consentRequired` flag serialized
  as string `"0"` (truthy in JS), so with cookie restriction OFF the
  identity hint was dropped from every browse event — cast to bool
  (Magento's cookie helper lies about `@return bool`); (3) the
  page-context inline `<script>` was blocked by CSP on checkout (Magento
  enforces CSP there by default), silently losing every `checkout_start`
  event — now rendered via `SecureHtmlRenderer`; (4) all three admin
  grids rendered colliding/duplicated rows because Magento's client-side
  grid storage keys rows by `entity_id` (our PK is `id`, and the queue
  tables carry an unrelated `entity_id` payload column) — fixed with
  `storageConfig.indexField=id` in the three listing XMLs. Not covered by
  the mock: the Smaily campaign-API side (workflow lists, contact sync —
  the sandbox keeps deliberately fake Smaily credentials), so those flows
  still ended in their previously validated failure paths. Sandbox now
  has 2 seeded products (SMAILY-TEE/SMAILY-MUG), 2 guest test orders and
  completed backfill/queue history; engine config was restored to
  disconnected (`browse_tracking=0`) after the pass.
- **Multilingual routing sandbox-verified end-to-end (spike for the Phase 2
  wizard choice-cards)** — all four modes exercised against the sandbox with
  a second store view (`et`, locale et_EE, kept in the sandbox for future
  passes): store-view→language resolution (`Multilingual\LanguageResolver`),
  mode A per-store-view credential selection (`SmailyClientProvider`), and
  the `Automation\Router` matrix (single/c → config defaults; a/b →
  per-language `smaily_automation_mapping` row, then `is_default_fallback`
  row, then config default; unmapped trigger → terminal skip). Live event
  path proven: a real subscriber save on the et store view enqueued
  contact.sync (store_id-scoped credentials, `contact.language=et`) and an
  automation.trigger whose payload routed to the per-language workflow via
  the et account. Verdicts: single/C WORK, A works (credentials via
  store-view config scope; wizard `accounts` save path exists server-side
  but no UI feeds it), B routing works but per-language mapping rows have
  NO admin write path (only the 2.8.x migration seeds `default` fallback
  rows) — the mapping UI is the Phase 2 build. One user-facing defect fixed
  in this pass: the wizard step-3 note falsely claimed per-language routing
  is configured under Configuration > Automations — now points at the real
  Multilingual Mode field (phtml + both i18n packs). The `account_key`
  divergence from Woo found here (mapping rows' account ignored, mode-A
  fallback rows firing through the wrong account) is FIXED in phase 2c —
  see the PRO-1273 entry above.
- **Engine contract v1.4.1 adopted + verified** (engine commit `945b7ad`,
  byte-identical with the engine repo; PRO-1280 — documentation-only bump over
  v1.4.0/d35bb96); **contract staleness CI added** (commit 5bc3767,
  `.github/workflows/contract-staleness.yaml` + `bin/check-contract-staleness.sh`).
- **Integration test suite + CI MySQL done (PRO-1199)** — 35 tests against a
  real MySQL 8.4: 2.8.x settings/schema migration (config mapper on real
  `core_config_data`, password re-encryption, mapping seeding, quote-column
  cleanup), event/ingest queue semantics (idempotent enqueue, claim tokens,
  backoff, parking, stale recovery, janitor retention) and the two flush
  crons with stubbed transports. Harness = standalone `Magento\Framework`
  object graph (`Test/Integration/Support/TestEnvironment.php`), NOT the full
  Magento TestFramework (needs a whole app + search engine — trade-off
  documented in TESTING.md). New CI job `integration` with a MySQL 8.4
  service; unit/static jobs untouched.
- **Product delete → engine §3b done (PRO-1231)** — catalog payloads emit
  `tags.product_id` (parent entity id via a new `ParentProductResolver`;
  `sku` keying untouched per PRO-1267); a
  parent/standalone hard-delete enqueues a `catalog_remove` queue row that
  `FlushIngestQueue` drains through its own non-D6 path to
  `POST /api/v1/ingest/catalog/remove` (endpoints-map key
  `ingest_catalog_remove`, hardcoded-path fallback for pre-v1.4.0
  exchanges; `not_found` = success). Configurable-child delete keeps the
  per-SKU `in_stock=false` soft path; disabled products stay on the
  ProductSaveAfter soft path. Mirrors Woo PRO-1230 (commit 92768d5).
- **Gates green:** 132 unit tests, 56 integration tests, phpcs 0 errors,
  phpstan clean. `setup:di:compile` re-verified in the docker sandbox after
  the PRO-1286 change (two new DI-autowired constructor args on
  `WizardStepSaver`, no new di.xml).
- **Hyvä compat skeleton + work package done (PRO-1201)** — full storefront
  audit with file:line evidence in `docs/HYVA_SUPPORT.md`. Compat
  module `Hyva_SmailyConnect` under `compat/hyva/` (standard Hyvä pattern:
  `hyva_` layout handles, composer `smaily/module-connect-hyva`,
  Tailwind registration observer for `hyva:config:generate`): vanilla-JS
  ports of tracker + attribution delivered as static files + inert JSON
  config blocks (no inline executable script — strict-CSP-safe; no
  RequireJS/jQuery; `cart_add` captured from the `checkout/cart/add` form
  submit since Hyvä has no `ajax:addToCart`), plus a Tailwind-styled
  personalization form. Audit verdicts: tracker NEEDS-JS-PORT (done),
  attribution NEEDS-COMPAT-TEMPLATE (done), context blocks / checkout
  opt-in (Luma-fallback checkout) / newsletter / RSS / privacy form
  WORKS-AS-IS, Hyvä Checkout OUT-OF-SCOPE. The compat dir is inert in the
  main package (nothing loads its registration.php) and excluded from the
  release ZIP. Since verified on a real Hyvä store — see the matrix entry
  at the top of this list.
- **Upstream proposal package drafted (PRO-1198)** —
  `docs/UPSTREAM_PROPOSAL.md`: executive summary, 2.8.x compatibility story,
  staged review plan, Marketplace re-submission as "Smaily Connect",
  pipeline/secret hand-over, honest open items, and the one-way-door decision
  checklist. Awaiting Erkki's review; NOTHING sent or published — the release
  decision and all contact with Smaily are Erkki's alone. **Deleted
  2026-10-07** (finished; the text is in git history): the decision record
  is Linear PRO-1198.

## Open Linear issues

| Issue | What | Priority |
|---|---|---|
| PRO-1198 | Release coordination with Smaily (upstream/Marketplace path) | High — the move is complete (PR #126; the fork archived 2026-10-07), rc9 tagged 2026-10-07; 3.0.0 waits on the pilot |
| PRO-3854 | Nightly catalog list (§3c manifest); the contract sync part is done | High — landed 2026-10-07, in rc8 |
| PRO-1971 | `gender` → `user_gender` release-comms obligation — parked 2026-09-02, reopens when 3.0.0 has a date | High — Erkki's decision |
| PRO-1400 | Clean-install confirmation of the cron group (release gate) | Todo |
| PRO-1484 | Canonical `mag-<id>` keys, `smaily_rec` click capture, browse keys (release gate) | Todo |
| PRO-2451 | Deactivated/refused Campaign Intelligence account — remember it, gate every send path, say so in the merchant panel (Woo PRO-1893 parity) | High |
| PRO-2452 | GDPR erasure must also erase local queue rows and anonymise stored payloads (Woo PRO-2383 parity) | High |
| PRO-2453 | Abandoned-cart purchase marker `abandoned_cart_purchased_at` (Woo PRO-1723 parity) | Medium |
| PRO-2454 | Event Log "Send again", server-worded refusals, no double-send on retry (Woo PRO-2324/2368/1733 parity) | Medium — may slip to 3.1 |
| PRO-2455 | Smaily landing page as a Magento CMS widget — decided for 3.1 | Low |
| PRO-1748 | Terminology canon across admin copy and docs | Medium |
| PRO-1766 | Feature: transactional emails (parity with Woo v3.9/v3.10) | Low |
| PRO-2456 | Fidelity check of the July design pack against the rendered admin (UI/UX parity project) | — |

Closed 2026-07-11: PRO-1199 (integration suite), PRO-1200 (i18n), PRO-1202 /
PRO-1242 (contract v1.4.0), PRO-1231 (product-delete §3b), PRO-1252
(staleness CI). Cross-repo asks filed: PRO-1266 (Shopify contract sync),
PRO-1267 (engine: Magento product-identity contract note).

## Known gaps

- **Real-engine-tenant click-through still owed** — the Smaily
  campaign-API side is now verified against a live Smaily account (see the
  walk entry above) and the engine side against the mock engine; one pass
  with a real engine tenant remains a nice-to-have before release.
- **Hyvä third-party AJAX-add-to-cart modules unverified** — the compat
  `cart_add` capture is verified on stock Hyvä 1.5.2 (form POST), but
  modules that submit programmatically (`form.submit()` fires no submit
  event) would bypass it; the documented fallback is a
  `private-content-loaded` cart-diff listener, to be added if a real store
  shows gaps.

## Questions / tasks for Erkki

1. PRO-1198 — the move is done (PR #126 → aa0c995, secret set, master
   green, remotes switched) and 3.0.0-rc9 is tagged and released there.
   The fork `erkkimarkus/magento-connect` is archived read-only
   (2026-10-07); nothing of the move is left open.
2. PRO-1201 — Hyvä boundary decisions (see "Open release decisions" in
   `docs/HYVA_SUPPORT.md`; the verification matrix itself is now fully
   executed and green): (a) confirm Hyvä Checkout (commercial, Magewire)
   stays out of scope for the first Hyvä release — free Hyvä's
   Luma-fallback checkout is the supported path and is verified working.
   The compat package vendor/name is decided: `smaily/module-connect-hyva`
   (module PHP name stays `Hyva_SmailyConnect` per the Hyvä convention);
   actual publication remains part of the release train.
3. ~~Sandbox infra defect: `.sandbox/entrypoint.sh` reinstalled Magento
   unconditionally on every container start.~~ **Resolved (PRO-1467):**
   `setup:install` now only runs when `app/etc/env.php` doesn't already
   exist; verified with a container rebuild + `docker compose down` /
   `up -d` against the existing volumes.
4. ~~PRO-1461 follow-ups (Medium): (a) confirm the Settings-selector-switches-
   to-an-unconfigured-website → redirect-into-the-wizard behaviour is the
   intended UX (vs. showing blank Settings fields for that website without
   forcing the wizard) — currently mirrors the existing single-website
   "wizard-first" gating, extended per-website, but wasn't spelled out by
   the task; (b) `smaily_connect/logging/verbosity` now has no UI home at
   all (CLI/DB-only) since the native surface removal; (c) the three
   `intelligence/sync_catalog`/`sync_customers`/`sync_orders` toggles lost
   their only UI (native) the same way.~~ **Resolved (Erkki, 2026-07-20 +
   PRO-1468):** (a) the redirect-into-the-wizard behaviour for an
   unconfigured website STANDS as-is — no change needed, Erkki confirmed
   the extended single-website "wizard-first" gating is the intended UX;
   (b) a real verbosity control now lives on the Log page (§2.4/§4.2); (c)
   the three Intelligence sync toggles, their config paths' readers/writers
   and the observer gates are all deleted — ingest now gates purely on
   `Settings::isConnected()`. (b) and (c) are both covered by the STATUS
   entries above.
5. ~~PRO-1765 — sign off the `gender` → `user_gender` wire rename before
   3.0.0 ships (Medium; reversible until release). v3 has no installed
   base, but **2.8.x did send `gender`** and those stores upgrade in place,
   so an upgraded merchant's Smaily segments/templates on `gender` freeze
   at their last 2.8.x value until repointed.~~ **Resolved (Erkki,
   2026-08-26, recorded on PRO-1971):** option A — the rename STANDS, on
   the cross-platform-canon argument (Woo decision F2-7 — one shopper must
   not produce two fields), with the repoint documented in
   `docs/UPGRADING.md` and `CHANGELOG.md`. It carries a release-comms
   obligation towards upgrading merchants (PRO-1971), parked 2026-09-02
   until 3.0.0 has a date.
6. PRO-1484 item 1 — the engine team's release-gate wording contradicts the
   engine's own contract (Low urgency, blocks only the tick, not the code).
   The ask says the Magento `sku` must be `mag-<product entity id>`, "never
   the merchant SKU, never a fallback"; `RECENGINE_API_CONTRACT.md` v1.8.1 §3
   says the opposite for Magento specifically — its SKU field IS the canonical
   key, `mag-<entity_id>` is the empty-SKU fallback, and "never a fallback" is
   flagged as a Shopify/Woo-only rule. The shipped code follows the contract
   and emits one identical key on catalog, order lines and browse (verified
   above). Someone has to ask the engine team which text wins; if the ask
   wins, it is a contract change and a one-way door for every already-ingested
   Magento tenant's `(tenant_id, sku)` history.
7. PRO-2453 — confirm the purchase marker's datetime format (Low urgency;
   reversible until 3.0.0 ships, but merchant-visible and permanent
   afterwards). The task brief asked for a Z-suffixed
   `abandoned_cart_purchased_at`; the code ships UTC `Y-m-d H:i:s` instead,
   because Woo's format stands (decision PRO-1723) — the full rationale is
   at `Trigger::MARKER_STAMP_FORMAT`. Nothing to change unless you want all
   four marker fields moved to Z form on all three platforms at once.
8. PRO-3580 — a headless storefront's newsletter subscription (Low
   urgency; reversible). The welcome now fires only in Magento's storefront
   (`frontend` area) and for the checkout opt-in. A shopper who subscribes
   on a headless storefront (PWA Studio and similar) goes through GraphQL,
   which the rule counts as "the API", so no welcome. No supported theme is
   headless today. Say if a headless store must count as the storefront.
9. ~~PRO-3582 — the contact import under "All customers" (Medium urgency;
   reversible until a store runs the import). Following the decision, a
   customer who is not a newsletter subscriber is imported as
   unsubscribed (`is_unsubscribed=1`). Smaily takes the store's status, so
   a contact Smaily already holds as subscribed — for example from a Smaily
   signup form — is set to unsubscribed by the import, and the merchant
   cannot email the non-subscribers this mode exists for. The live sync
   under this mode omits `is_unsubscribed` instead (Smaily manages
   suppression), as WooCommerce's import does. Confirm the import should
   send unsubscribed, or say to omit the status for non-subscribers (then
   a brand-new contact may be created subscribed — unverified, WooCommerce
   PRO-3407).~~ **Resolved (Erkki, 2026-10-02, PRO-3610):** the mode is the
   soft opt-in; omit the status for non-subscribers. Smaily creates a new
   contact without the field as subscribed (confirmed by Erkki).
10. PRO-3606 — checkout opt-in only (Low urgency; reversible). Two
    choices made on the safe side, confirm or reverse: (a) an unsubscribe
    made in the store still reaches Smaily as `is_unsubscribed=1`, so a
    shopper who opted in at checkout and later unsubscribes in My Account
    does not stay subscribed in Smaily (this mode has no reconcile).
    WooCommerce sends no opt-out in this mode. The cost: an unsubscribe of
    a newsletter-form subscriber who never reached Smaily creates an
    unsubscribed contact there. (b) With "Need to Confirm" on, every
    confirmed signup syncs, a confirmed newsletter-form signup included,
    because Magento does not record where a pending signup came from;
    otherwise a checkout opt-in on such a store would never reach Smaily.
11. ~~PRO-3619 — the abandoned-cart purchase marker for an address Smaily
    does not have (Medium urgency; reversible). The marker now goes only
    after a reminder went out to Smaily. But a reminder to an address
    Smaily does not have creates nothing (your fact, 2026-10-02), so a
    guest who was never a Smaily contact and buys after the reminder still
    gets created by the marker — as a subscriber, unless the store holds
    them as unsubscribed. Closing it needs one Smaily read of the contact
    before the marker is sent (in the queue, not at checkout), and the
    marker skipped when Smaily does not have the contact. WooCommerce has
    the same gap. Say whether to add that read.~~ **Resolved (Erkki,
    2026-10-02): yes** — the queue reads the contact and skips the marker
    for an address Smaily does not have (PRO-3619 remainder in "Where we
    are").
12. PRO-3614 — product links for a headless storefront (High urgency for
    the pilot: recommendation emails stay off until decided; reversible).
    The storefront's product page is `/p/<sku>/<url_key>.html`; the module
    sends Magento's own product URL. Options: (A) no code — set the store
    view's Base Link URL to the storefront and have the storefront team
    keep the query string on its `/<url_key>.html` redirect; side effect:
    every Magento-built link of that store view moves (Magento's emails,
    `smaily/relay`, `smaily/rss/feed`, `smaily/cart/restore`), so the
    storefront host must pass `/smaily/` to the back end. (B) code — a
    store-view setting "Storefront product URL" with `{sku}` and
    `{url_key}` placeholders (str_replace, values URL-encoded; empty =
    today's behaviour), used for the catalog `product_url` and the RSS
    item links; no side effects, but the template must follow the
    storefront's routing, and existing engine rows change on the nightly
    re-sync. Recommended: B, plus the storefront team's hand-off. Say A or
    B. **Decided (Erkki, 2026-10-02, PRO-3660):** neither — a "Storefront
    URL" that replaces the host of every product link (path and query
    string kept); built, see "Where we are". The storefront still has to
    route `/<url_key>.html`, or redirect it keeping the query string.
13. ~~PRO-3660 — when the Storefront URL field opens by itself (Medium
    urgency; reversible, no schema). The approved rule — open it when the
    last 30 days' orders came only through the API and none through
    Magento's own checkout (frontend area) — does not hold: Luma's
    checkout places the order through REST (`webapi_rest`, PRO-3580), so
    every Luma store would look API-only and see the field open. Options:
    (a) count only GraphQL (`graphql` area) as the API — PWA Studio, Vue
    Storefront/Alokai and most headless storefronts place orders there; a
    REST-only headless store then keeps the field collapsed (the link
    stays); (b) also count a REST order as the API when the request carries
    no Magento storefront cookie (`form_key`, set by Luma and Hyvä pages) —
    closer, but a heuristic; (c) drop the auto-open. Recording stays as
    designed either way: two timestamps in Magento's flag table,
    `smaily_connect_*` (removed by the uninstall). Recommended: (a). Say
    a, b or c.~~ **Resolved (Erkki, 2026-10-02): option (b)** — GraphQL,
    or REST without the `form_key` cookie, is an API order; every other
    order is a storefront order. Built, see "Where we are".

14. PRO-3661 — the 2.8.x *Enable Module* switch and store-view account rows
    in the upgrade (Medium urgency: the next client upgrades a
    multi-website store; one-way door, store data). Today the guide tells
    the merchant to act (switch off sync, welcome and abandoned cart for a
    website where 2.8.x had *Enable Module = No*; delete store-view
    `smaily/general/*` rows before the upgrade). Proposed migration
    change, for your decision: (a) where `smaily/general/enable` resolves
    to 0 for a website (its own row, else the default), write
    `sync_enabled`, `welcome_enabled` and `abandoned_enabled` = 0 at that
    website (at the default scope when the default is 0); (b) do not carry
    store-view `smaily/general/*` rows over (2.8.x never read them) and
    post an admin notice naming the store views. Say yes, (a) only, or
    keep the documentation.
    **Decided (Erkki, 2026-10-02, PRO-3681):** (1) Enable Module = No —
    yes, the upgrade writes contact sync, welcome and abandoned cart = 0 at
    the scope where it is 0 (default or website); 1 or absent writes
    nothing extra. (2) Store-view account rows — not carried over; an admin
    notice names the store views. (3) 3.0's new defaults stay (checkout
    checkbox on, Magento's opt-in emails suppressed), stated in UPGRADING.
    (4) `name` is not sent again; UPGRADING names `first_name` /
    `last_name` and the smaller payload differences. Built, see "Where we
    are".

15. PRO-3681 — a website with its own *Enable Module = Yes* under a
    Default Config with *No* (Medium urgency: a multi-website 2.8.x store
    that uses Smaily on one website only has exactly this shape; one-way
    door, store data). Decision (1) writes the three off rows at Default
    Config and nothing for the website's Yes, so every one of the three
    settings that website inherited from Default Config is off after the
    upgrade — 2.8.x ran them for that website as Default Config set them.
    Built as decided, pinned by `LegacyScopeUpgradeTest`, and UPGRADING
    "Check after the upgrade" tells the merchant to switch them on again.
    Proposed: for such a website, also write at the website the value it
    resolved to before the default's off rows (its own row, else the default's migrated
    value, else config.xml). Say yes, or keep the guide step.
    **Decided (Erkki, 2026-10-02): yes** — built: such a website gets the
    three at its own scope with its own carried-over value, else the
    default scope's, else the config.xml default
    (`LegacyConfigMapper::MODULE_SWITCH_DEFAULTS`, unit-checked against
    `etc/config.xml`); the UPGRADING guide step is gone.
