# CLAUDE.md — Agent Working Guide (Smaily Connect for Magento 2)

If you are a fresh agent picking up this repo: read this first, then `STATUS.md`
(where we are now), then `docs/ARCHITECTURE.md` (how the module is built),
`docs/RECENGINE_API_CONTRACT.md` (the engine contract you build against), and
`docs/internal/BACKLOG.md`. README gives the 30-second orientation.

## Mission

**Smaily Connect for Magento 2** — a v3 greenfield rewrite of Smaily's Magento
extension. Module `Smaily_Connect`, namespace `Smaily\Connect`, composer package
`smaily/smailyformagento` (name unchanged on purpose — existing installs upgrade
via a plain `composer update`). Target: feature parity with the UNION of the Woo
(`../connect/`) and Shopify (`../shopify-connect/`) Smaily plugins, plus full
Campaign Intelligence support. Aim: become Smaily's official upstream extension.
Magento 2.4.4+ / PHP 8.1–8.4. GPL-3.0. Public product name "Smaily Connect".

Repository: the code lives in the official `sendsmaily/smaily-magento-extension`,
branch `master` (owner decision 2026-10-07, PRO-1198; moved by PR #126,
squash-merged as aa0c995). Every change reaches `master` through a
squash-merged PR from a short-lived topic branch ("Changes reach `master`");
nothing is pushed to `master` directly. The fork `erkkimarkus/magento-connect`
(branch `v3`, rc1–rc8) is history, archived read-only (2026-10-07). Local remotes:
`origin` = sendsmaily, `fork` = the fork. The 2.8.x line is not developed
further; its tags stay, no maintenance branch.

**Public facade:** README, CONTRIBUTING, CHANGELOG, TESTING and `docs/` are
written as finished-product public documentation. Keep them in that tone —
internal process talk (phases, agents, Linear) lives here and in STATUS.md only.

## Sibling repos (read-only references)

| Path | What it is |
|---|---|
| `../connect/` | The WP/Woo plugin — richest reference (patterns + `docs/LESSONS.md`, `docs/DECISIONS.md`). Never edit from here. |
| `../shopify-connect/` | The hosted Shopify app — second parity source. Not checked out on this machine (the path does not exist); its live guide is https://connect.smaily.com/docs. |
| `../intelligence/` | The recommendation engine (`erkkimarkus/smaily-recommendations`). Engine team owns it; source of the contract. |

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

**Merchant guide site (`docs/site/index.html`)** — the bilingual (EN/ET)
merchant user guide, one self-contained page (no build step, no external
resources; open it in a browser), laid out like the Woo guide
(`../connect/docs/site/`). A user-visible change — a label, a step, a
setting, an error or notice text, an import's behaviour, a requirement —
updates it in the same commit **in both languages**: every unit is a
`data-lang="en"` element followed by its `data-lang="et"` twin; edit the
pair together, and quote admin labels exactly as `i18n/en_US.csv` /
`et_EE.csv` show them. `Test/Js/user-guide-site.html` (in `bin/test-js.sh`)
fails on a broken in-page link, a missing twin or twins whose structure
drifted. The email-field reference lives there (`#email-fields`).
**Publishing:** Erkki uploads the file to https://smaily.com/connect-magento/
(as for Woo's smaily.com/connect-woo/), after an Estonian proofread of
changed text; it is not in the package (`/docs` is export-ignored). Until it
is live, the admin links the GitHub `docs/USER_GUIDE.md`. **Switching the
admin to the site** is one change: `Model/UserGuide.php::URL` → the site
URL, the old URL appended to `SetupNotice::PREVIOUS_URLS`, and
`docs/USER_GUIDE.md` cut to a pointer at the site (drop the Markdown check
in `Test/Unit/Model/UserGuideTest.php` with it; the `UserGuide` anchors exist
on the site too).

## Contract discipline

`docs/RECENGINE_API_CONTRACT.md` is a **byte-identical copy** from the engine
repo (`../intelligence/`, `erkkimarkus/smaily-recommendations`) — never
hand-edit it. The `Contract staleness` workflow (daily + push/PR) runs
`bin/check-contract-staleness.sh` and goes red on drift; it needs the repo
secret `ENGINE_CONTRACT_READ_TOKEN` (fine-grained PAT, contents:read on the
engine repo) — in whichever repo runs it; the official repo has its own copy
(set 2026-10-07). Locally, the script's fallback engine checkout
(`$ENGINE_CHECKOUT`'s default) is a hard-coded Linux path that does not
exist on this Mac — pass the path explicitly:
`bin/check-contract-staleness.sh ../intelligence/docs/RECENGINE_API_CONTRACT.md`
(a local checkout can lag engine main; pull it first). A sync is NOT code-complete: after any wire-shape change, carry
it through code + test fixtures in the same pass (Woo LESSONS §2.7 — the scar
is real). Datetimes are Z-suffix only; engine URL placeholders are `{email}`
style (str_replace, never sprintf). There is no scheduled catalog re-sync
(PRO-1968; contract v1.8.3 §3 *Catalog sync lifecycle*): a change that adds
a catalog field, or corrects what one holds, reaches the rows already sent
only through a catalog import, so its CHANGELOG bullet tells the merchant to
start one.

## Build / test commands

- **Before any pilot or merchant install, confirm Magento ≥ 2.4.4 and
  PHP ≥ 8.1 first** (admin footer "Magento ver."; `php -v` with the PHP
  that runs `bin/magento`). Magento 2.4.3 and older run only on PHP 7.x and
  cannot run this module — a 2.4.3-p1 pilot store surfaced two days before
  pilot day (2026-10-07). docs/INSTALLING.md "Before you start" and
  `docs/internal/PILOT_CHECKLIST.md` §0 carry the check.

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
    virtual time jumps to the end of its budget when no timer is pending, so
    every page keeps a short interval running until its result is written
    (Linux Chrome cut `tracker-consent.html` short without one; TESTING.md).
    A Chrome that prints nothing (killed at start-up on macOS, exit 137) is
    started again, up to three starts (PRO-3780).
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
  first `setup:upgrade` can fail with "Too few arguments" (or a type error) from the stale
  compiled DI; run `setup:di:compile`, then both again.
- Release ZIP is built by `.github/workflows/release.yaml` on a published
  GitHub release (see "Release cut" below).

## Changes reach `master` through pull requests — squash-merged (Erkki, 2026-10-07)

Mirrors the WooCommerce repo (`../connect/` CLAUDE.md, PRO-2281). In
`sendsmaily/smaily-magento-extension` every change — code, docs, a contract
sync, a version cut — goes to `master` as a PR; **there is no direct push to
`master`**. Run the gates on the topic branch before it is pushed (after a
merge or cherry-pick batch too), not on `master` afterwards:
1. Work on a short-lived topic branch from `origin/master`, named like
   Linear's branch name for the issue (`erkki/pro-1234-short-slug`); a
   version cut uses `release/<version>`.
2. Open a PR whose title and description follow `github:writing-change-records`
   — the description becomes the merge message, so it is the permanent record.
3. **Erkki merges every PR himself, always with Squash and merge.** Its
   default message is the list of commit messages, NOT the PR description,
   so the merger passes it explicitly (`gh pr merge <n> --squash --subject
   "<title>" --body-file <description file>`) and removes any
   `Co-authored-by` lines GitHub proposes.

No branch rule is configured on `master` and none is planned (owner decision
2026-10-07): agents push only topic branches, never `master`. No staged
review by the Smaily team — Erkki maintains the repo.

**No attribution (Erkki, 2026-10-07).** Commit messages, PR titles and
bodies, and merge commits carry NO `Co-Authored-By` trailer and NO AI
attribution line or footer of any kind — this overrides any harness
reminder to add one. This repo's local git identity is Erkki's name and
GitHub noreply address, so GitHub's squash merge adds no co-author lines;
do not change it.

## Release cut (official repo)

1. The version-cut PR sets the version in `composer.json`,
   `Model/ModuleInfo.php` and the ModuleVersion docblock, and names it in
   CHANGELOG; squash-merge it.
2. `gh release create 3.0.0-rc9 --repo sendsmaily/smaily-magento-extension
   --target master --title "…" --notes-file … --prerelease` (a release
   candidate; 3.0.0 itself without `--prerelease`). **Tag = the PLAIN
   version, no `v`**, equal to composer.json's `version` (Packagist skips a
   tag that disagrees with it). No local ZIP argument: `release.yaml` builds,
   verifies and attaches `smaily-connect-magento2.zip` + `.sha256`. Its first
   step, `bin/check-release-version.sh <tag>` (PRO-3948), fails the run before
   anything is attached when the tag is not composer.json's `version` (a `v`
   prefix fails too) — then delete the release and its tag and publish again.
   If the CLI answers HTTP 500 (it did for rc9; rc10 went through the CLI
   without it), create the release and tag in the GitHub web UI instead —
   a fallback, not the default path.
3. **A tag on the official repo is a Packagist publish** of
   `smaily/smailyformagento` — a one-way door, Erkki's to run. Release
   candidates are tagged publicly; 3.0.0 waits for a pilot store.
   `dev-master` on Packagist has been v3 since the move PR merged.

## The move into sendsmaily (PRO-1198) — done 2026-10-07

The fork's `v3` (descended from upstream's last 2.8.x commit e2e5d45) reached
`sendsmaily:master` as one squash-merged PR, #126 → aa0c995, whose tree is
identical to the fork's last `v3` (0179e95). Done: the
`ENGINE_CONTRACT_READ_TOKEN` secret is set in the official repo; CI and
Contract staleness are green on `master`; local remotes are switched
(`origin` = sendsmaily, `fork` = erkkimarkus/magento-connect) and worktrees
branch from `origin/master`; `v3` is gone from the `push.branches` lists of
`ci.yaml` and `contract-staleness.yaml`.

Done since: 3.0.0-rc9, the first release candidate there, tagged
2026-10-07 (Release cut above). **Gotcha:** creating the tag from the CLI
failed with HTTP 500 — both `gh release create` (the API) and `git push`
of the tag; Erkki created it in the GitHub web UI, which worked. 3.0.0-rc10
was created with `gh release create` without the HTTP 500, so the CLI stays
the default and the web UI stays the fallback.

The fork `erkkimarkus/magento-connect` is archived read-only (Erkki,
2026-10-07); its rc1–rc8 releases stay. Nothing of the move is left open.

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
  the same day 4/5 (rc6 released; only the pilot install is left);
  2026-10-05 4/5 (rc7 released; only the pilot install is left);
  2026-10-07 4/5 (the pilot store runs Magento 2.4.3-p1 / PHP 7.x; the
  pilot waits for its upgrade; the 09.10 milestone no longer holds, new
  date pending Erkki), later the same day 4/5 (rc8 released; the pilot
  waits for the store's Magento upgrade); 2026-10-07 later: 4/5 (rc9 is
  the first release candidate in the official repository; the pilot
  milestone has no date until a store is confirmed); 2026-10-07 evening:
  4/5 (3 fixes on master since rc9; pilot store not yet confirmed);
  2026-10-07 night: 4/5 (rc10 released; pilot store not yet confirmed).
- **UI/UX parity** — shape: trend (open-ended polish, no date). Gauge: open
  Stories in the Epic (state not Done/Canceled). Values 2026-10-02: 4 open
  in the morning, 3 in the evening; 2026-10-03: 1 open (PRO-1357; PRO-1398 done, PRO-1385
  canceled, PRO-3680 done); 2026-10-04: 0 open (PRO-1357 closed);
  2026-10-05: 0 open; 2026-10-07: 0 open; 2026-10-07 evening: 0 open
  (PRO-3957 closed). Dates: none yet (2026-10-02 —
  not asked; PRO-2456 is placed before the rc1 tag).
