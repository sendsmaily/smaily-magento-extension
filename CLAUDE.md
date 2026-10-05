# CLAUDE.md — Agent Working Guide (Smaily Connect for Magento 2)

If you are a fresh agent picking up this repo: read this first, then `STATUS.md`
(where we are now), then `docs/ARCHITECTURE.md` (how the module is built),
`docs/RECENGINE_API_CONTRACT.md` (the engine contract you build against), and
`BACKLOG.md`. README gives the 30-second orientation.

## Mission

**Smaily Connect for Magento 2** — a v3 greenfield rewrite of Smaily's Magento
extension. Module `Smaily_Connect`, namespace `Smaily\Connect`, composer package
`smaily/smailyformagento` (name unchanged on purpose — existing installs upgrade
via a plain `composer update`). Target: feature parity with the UNION of the Woo
(`../connect/`) and Shopify (`../shopify-connect/`) Smaily plugins, plus full
Campaign Intelligence support. Aim: become Smaily's official upstream extension.
Magento 2.4.4+ / PHP 8.1–8.4. GPL-3.0. Public product name "Smaily Connect".

Fork: `erkkimarkus/magento-connect` (upstream
`sendsmaily/smaily-magento-extension`). Work happens on branch `v3` (default).

**Public facade:** README, CONTRIBUTING, CHANGELOG, TESTING and `docs/` are
written as finished-product public documentation. Keep them in that tone —
internal process talk (phases, agents, Linear) lives here and in STATUS.md only.

## Sibling repos (read-only references)

| Path | What it is |
|---|---|
| `../connect/` | The WP/Woo plugin — richest reference (patterns + `docs/LESSONS.md`, `docs/DECISIONS.md`). Never edit from here. |
| `../shopify-connect/` | The hosted Shopify app — second parity source. |
| `../re/` | The recommendation engine (`smaily-recommendations`). Engine team owns it; source of the contract. |

## Working mode — autonomous with checkpoints

- **Proceed without asking:** implementation, tests, refactors, doc upkeep, and
  reversible technical decisions (record significant ones as you go).
- **Queue for Erkki, don't block:** non-urgent strategic questions and
  human-only tasks — collect them in STATUS.md under "Questions / tasks for
  Erkki"; batch them, don't drip.
- **Stop and ask first:** spending money; anything published externally
  (Marketplace submission, a public release, contact with the Smaily or engine
  team); proposing breaking changes to the engine contract; deleting store
  data; security trade-offs.

## Keeping the docs current — part of every change

Same rule as the sibling repos, same wording on purpose: if your change makes a
doc wrong, your change isn't finished until the doc is fixed in the same
commit. **STATUS.md updates in the same commit that changes reality.** New
operational gotchas go into this file. `docs/USER_GUIDE.md` is a doc too — a
user-visible behaviour change updates it in the same commit.

## Contract discipline

`docs/RECENGINE_API_CONTRACT.md` is a **byte-identical copy** from the engine
repo (`../re/`, `erkkimarkus/smaily-recommendations`) — never hand-edit it.
The `Contract staleness` workflow (daily + push/PR) runs
`bin/check-contract-staleness.sh` and goes red on drift; it needs the repo
secret `ENGINE_CONTRACT_READ_TOKEN` (fine-grained PAT, contents:read on the
engine repo). A sync is NOT code-complete: after any wire-shape change, carry
it through code + test fixtures in the same pass (Woo LESSONS §2.7 — the scar
is real). Datetimes are Z-suffix only; engine URL placeholders are `{email}`
style (str_replace, never sprintf).

## Build / test commands

- Local PHP is 8.5 and this host is missing a few Magento PHP extensions, so:
  `composer install --ignore-platform-reqs`. Magento packages come from
  `mirror.mage-os.org` (repo configured in composer.json).
- **`composer.lock` is committed** (PRO-2473) — CI installs from it, so an
  upstream release cannot turn a green build red on its own. `config.platform.php`
  pins the resolver to 8.1, the oldest PHP the package supports, so the lock
  stays installable on the PHP 8.1 CI job. Refresh it deliberately with
  `composer update --ignore-platform-req='ext-*'`, run the gates, and commit the
  lock in that same pass. The weekly `Lock freshness` workflow files a GitHub
  issue when the lock falls behind.
- Gates — ALL must pass before anything is "done" (CI runs the same):
  - `vendor/bin/phpunit --testsuite unit`
  - `vendor/bin/phpcs` (Magento2 standard; errors fail)
  - `vendor/bin/phpstan analyse`
  - `vendor/bin/phpunit -c phpunit.integration.xml.dist` — needs a throwaway
    MySQL (`docker run --rm -d -e MYSQL_ROOT_PASSWORD=root -p 3316:3306
    mysql:8.4` + `SMAILY_IT_DB_PORT=3316`, see TESTING.md); NEVER point
    `SMAILY_IT_DB_*` at the sandbox DB — the suite drops/recreates tables.
  - `bin/test-js.sh` — the browser harnesses in headless Chrome. Chrome's
    virtual time jumps to the end of its budget when no timer is pending, so a
    page that waits on frames or promises keeps a short interval running
    (Linux Chrome cut `tracker-consent.html` short without one; TESTING.md).
- **Local PHP 8.5 accepts syntax PHP 8.1 rejects** (DNF types such as
  `(A&B)|null` are 8.2+, `new Foo()->bar()` without wrapping parens is
  8.4+), and neither phpstan (even with `phpVersion: 80100`) nor phpcs
  (`magento/php-compatibility-fork`) flags it. CI's "PHP 8.1 syntax" job
  runs `bin/lint-php.sh` (PRO-3735): `php -l` over every PHP and PHTML file
  outside vendor/, failing with the name of each file 8.1 cannot parse.
  Before calling work done, run the same script under PHP 8.1 locally (the
  whole repo takes seconds; it reports every failure, not only the first):
  ```sh
  docker run --rm -v "$PWD":/app -w /app php:8.1-cli bin/lint-php.sh
  ```
  The unit suite runs on 8.1 the same way after `apt-get install -y
  libicu-dev && docker-php-ext-install intl` in the container.
- Sandbox: `docker compose up -d` — real Magento 2 at
  `localhost:8080/admin` (admin / smailydev1, 2FA modules disabled). The repo
  is bind-mounted as the module; `vendor/` inside the container is shadowed by
  an anonymous volume (dev deps never leak into the Magento autoloader).
  `bin/magento setup:upgrade && setup:di:compile` in the container is the
  DI-correctness gate (see TESTING.md). After a constructor change the
  first `setup:upgrade` can fail with "Too few arguments" from the stale
  compiled DI; run `setup:di:compile`, then both again.
- Release ZIP is built by `.github/workflows/release.yaml` on a published
  GitHub release.

## Language conventions

Repo docs, code, commits, Linear content: English. Conversations with Erkki:
Estonian.

## Linear discipline (Smaily process bridge)

This repository is engineering truth — STATUS/docs stay canonical here. Linear
is the coordination and visibility layer. Full process: Outline → Processes →
"Agent-Driven Development"; compact guide: Linear document "Linear workflow
guide for AI agents" (attached to MGMT-5). Three rules for every agent working
here:

1. **Anchor before work.** Work anchors on the initiative *Smaily E-Commerce
   native integrations* (v3 also serves *Campaign Intelligence*) and on two
   Epics (Linear projects, team "Product Development"):
   - [Smaily Connect for Magento 2 — v3
     rewrite](https://linear.app/smaily/project/smaily-connect-for-magento-2-v3-rewrite-34e9fd6ca889)
     — outcome: 3.0.0 reaches real stores (pilot first, then the Smaily
     upstream hand-over PRO-1198).
   - [Magento Connect UI/UX
     parity](https://linear.app/smaily/project/magento-connect-uiux-parity-2121de63e959)
     — outcome: the admin matches its Woo/Shopify siblings and the design pack.

   Work found in a session is filed as a Story (job story, problem Story or
   spike) in the Epic whose outcome it serves (Story-first intake, PRO-3118).
   Cross-repo asks are filed as issues in the sibling projects (Woo: "Smaily
   Connect for WooCommerce — v3 rewrite", Shopify: "Smaily Connect for
   Shopify — hosted app", engine: "Campaign Intelligence — recommendation
   engine").

2. **One-way doors interrupt.** Before any irreversible or expensive-to-undo
   commitment — persistent data schema, public/integration API contracts (e.g.
   `RECENGINE_API_CONTRACT.md`), releases reaching real users or stores,
   anything touching deliverability/reputation, pricing, or legal/consent —
   halt, file a Linear issue in the project with the evidence and proposed
   action, and wait for Erkki's approval. Reversible work proceeds at full
   speed without asking.

3. **Scribe pass at session end.** Before finishing a working session, distill
   it into Linear: post an honest project status update (onTrack/atRisk/
   offTrack), promote new backlog items to Linear issues, close completed
   issues. Use the `/linear-project` skill if available, otherwise the Linear
   MCP tools directly.

Never duplicate repo documents into Linear — summarize and link. Linear content
is written in English.

## Outcome gauges

- **v3 rewrite** — shape: deadline. Gauge: PRO-2474 pilot-readiness
  checklist, items ticked out of 5 (read from the Linear issue). Milestones
  (set 2026-10-02): "rc1 tagged + pilot runbook" 2026-10-03, "Pilot store
  live" 2026-10-09. Values: 2026-10-02 morning 0/5, evening 4/5;
  2026-10-03 4/5 (rc4 released; only the pilot install is left);
  2026-10-04 4/5 (rc5 released; only the pilot install is left), later
  the same day 4/5 (rc6 released; only the pilot install is left).
- **UI/UX parity** — shape: trend (open-ended polish, no date). Gauge: open
  Stories in the Epic (state not Done/Canceled). Values 2026-10-02: 4 open
  in the morning, 3 in the evening; 2026-10-03: 1 open (PRO-1357; PRO-1398 done, PRO-1385
  canceled, PRO-3680 done); 2026-10-04: 0 open (PRO-1357 closed). Dates: none yet (2026-10-02 —
  not asked; PRO-2456 is placed before the rc1 tag).
