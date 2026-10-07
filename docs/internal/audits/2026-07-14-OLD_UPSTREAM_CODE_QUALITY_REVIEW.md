# Code-quality & architecture review — old upstream Magento plugin

**Date:** 2026-07-14
**Subject:** `Smaily_SmailyForMagento` v2.8.1 (`master` branch — upstream
`sendsmaily/smaily-magento-extension`).
**Purpose:** an honest, evidence-based assessment of the old plugin's code
quality and architecture, to **justify why a greenfield v3 rewrite was the right
call** (as opposed to incremental refactoring).
**Method:** read-only review of the module on `master` (git history + file
contents), contrasted with the v3 tree. Security is out of scope here — see the
companion `2026-07-14-OLD_UPSTREAM_SECURITY_AUDIT.md`.

## Verdict

**A greenfield rewrite was justified — but the honest reason is narrower than
"the old code is bad."** Most of the existing code is competent, unremarkable
Magento 2 code for the narrow job it does. The rewrite is justified on
**absence-of-safety-net** and **scope-mismatch** grounds, not on the existing
code being poor.

The public claims "the old code is bad" and "raw SQL in the code" do **not**
hold up (the security audit confirms all DB access is parameter-bound). The
defensible framing for the Smaily team: *"The existing base had no test coverage
and no architectural seam for the new scope — a rewrite was a smaller risk than
a blind refactor."*

## Scale (facts)

| | Old plugin (`master`) | v3 (`v3` branch) |
|---|---|---|
| Files | 69 | — |
| PHP LOC | ~3,056 across 33 PHP files | ~20,082 across 212 PHP files |
| Tests | **none** (0 test files) | 52 test files (unit + integration) |
| Static analysis | **none** | `phpcs.xml.dist` + `phpstan.neon.dist` (CI gates) |
| PHP/dep constraints | **none** — `composer.json` has no `require` section | `php ~8.1–8.4` + explicit `magento/*` ranges |
| History | 323 commits, 2018-04-04 → 2026-04-29 (patch-level, low velocity) | greenfield |

## The four strongest, evidence-backed reasons

1. **No safety net — the decisive reason.** `git ls-tree -r --name-only master
   | grep -iE 'test|phpunit|phpcs|phpstan'` returns only the release-ZIP
   workflow. No test suite, no static analysis, no CI test gate, and
   `composer.json` declares no PHP or `magento/framework` constraint — in 8
   years and 323 commits. Any structural change would have been unverifiable by
   construction; incremental refactoring *without first* bolting on a full test
   harness would have been strictly riskier than starting clean.

2. **No seam for the new scope.** No `Api/` service-contract layer and no
   queue/automation abstraction exist on master. Business logic, SQL, HTTP
   dispatch and payload shaping are inlined in cron classes —
   `Cron/AbandonedCart.php` (403 lines) and `Cron/SubscribersSync.php` (297
   lines) each do DB querying, entity loading, currency/tax math and API I/O in
   one method. Campaign Intelligence, browse tracking, multilingual admin and
   Hyvä have nowhere to attach — those layers would have to be built from
   scratch inside the old module anyway.

3. **Real (if modest) reliability risk in the shipped code.** N+1 product loads
   in the abandoned-cart cron (a full product entity loaded per cart item per
   quote inside a loop, ~`Cron/AbandonedCart.php:240`); a hardcoded
   `new \DateTimeZone('Europe/Tallinn')` assumption baked into business logic
   with no explanatory comment (`Cron/SubscribersSync.php`); synchronous,
   unbatched API calls with no retry/queue, where one bad quote re-throws and
   aborts the whole cron run. A rewrite fixes these rather than patching around
   them.

4. **The old plugin was already drifting toward better idioms.** Its newest
   code uses `DataPatchInterface` + `declare(strict_types=1)` in
   `Setup/Patch/Data/*` and declarative `etc/db_schema.xml`. The rewrite is the
   logical conclusion of that trajectory, not a repudiation of the original
   engineering.

## Dimension notes (with evidence)

- **Architecture** (Med-High): no `Api/` dir on master; DI otherwise used
  correctly (constructor injection throughout Cron/Plugin/Controller) — that
  part is sound.
- **Maintainability** (Medium): two isolated `ObjectManager::getInstance()`
  anti-pattern calls (`Model/Config/Backend/UTCTime.php:18`,
  `Model/ResourceModel/SubscribersSyncState/Collection.php:22`); duplicated
  enable-check blocks between the two crons; all method signatures untyped
  (PHP 7.0-era baseline, `@return` PHPDoc only, never `: bool`/`: int`);
  vestigial `@access public` tags.
- **Testability** (High): the single strongest finding — see reason #1.
- **Reliability** (Medium, mixed): to its credit, both crons have structured
  per-job Monolog logging (`etc/di.xml` wires `var/log/smly_cart_cron.log` /
  `smly_customer_cron.log`) and batch/paginate DB reads (`BATCH_SIZE` + offset
  loops). Failure handling is synchronous and inline; hand-rolled curl wrapper
  (`Model/HTTP/Client.php`) with no retry/backoff and no interface to mock.
- **Feature depth** (Low for "was the code bad", High as a rewrite driver):
  master implements exactly three integrations (RSS feed, newsletter opt-in +
  CAPTCHA, abandoned-cart + subscriber-sync crons) — nothing on recommendation
  engine / Campaign Intelligence, browse tracking, consent/GDPR beyond a basic
  opt-in flag, multilingual admin, or Hyvä.
- **Tooling / CI / standards** (High): no `require` in `composer.json`, no
  phpcs/phpstan, no test CI — only a release-ZIP workflow.

## What the old code got right (honest list)

- Consistent constructor-based DI in nearly all classes — no widespread
  ObjectManager abuse, just two isolated instances.
- Batched/paginated DB access in both crons (`BATCH_SIZE` + offset loop) — real
  awareness of scale, not naive full-collection loads.
- Per-job structured logging via dedicated Monolog handlers, not
  `error_log`/`echo`.
- Later `Setup/Patch/Data/*` patches use `DataPatchInterface` +
  `declare(strict_types=1)` — proper declarative-patch idiom.
- Config-save validation that live-checks Smaily API credentials before saving
  (`Plugin/SaveConfig.php`) — a genuinely good UX safeguard.
- `Model/Config/Backend/Subdomain.php` is clean, well-written code: a proper
  `Config\Value` backend model with `beforeSave()` normalization (URL / bare
  `*.sendsmaily.net` / raw forms all handled), input sanitized to alphanumerics
  (`preg_replace('/[^a-zA-Z0-9]+/', '', $subdomain)`), and a required-field
  `ValidatorException`. No ObjectManager, no validation gap — the follow-up
  concern about this file was checked and is a non-issue.
- The codebase is small and legible — no thousand-line god-classes, just
  under-decomposed ones.

## Follow-up

`Model/HTTP/Client.php`'s Basic-Auth-in-cURL credential handling and the
CAPTCHA/opt-in flow were flagged for architecture/testability here without
assessing their security posture — cross-reference the security audit if a
deeper look is wanted.
