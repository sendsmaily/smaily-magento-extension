# Admin design pack — fidelity check against the rendered admin (PRO-2456)

**Date:** 2026-10-02
**Compared:** the design pack `Magento Connect admin visual system.zip`
(exported 2026-07-12; not kept in the repository — git history, commit
`db2fc53`) against the running sandbox admin (Magento 2.4.8-p4) in **en_US
and et_EE**, at a 1400 px viewport plus a 900 px spot check.
**Authority:** `docs/ADMIN_UI_TARGET_SPEC.md` §1 — the pack binds layout and
element visuals only. Where the pack's copy differs from the shared Connect
terminology, the terminology wins and the difference is noted, not changed.

## Method

Every artboard in the pack was rendered in a browser — Design Tokens,
Components, Dashboard (4 states), Setup Wizard (5 frames), Settings (3
frames), Engine Automations (4 frames), Log (2 frames), Backfill (6 states) and
the six standalone components (Pill, Tile, Banner, ChoiceCard, InlineStatus,
ProgressBar). Each screen was then opened on the sandbox and compared by
screenshot and by computed-style measurements of the same elements.
Sandbox state used: a fresh install walked through the initial setup with
placeholder credentials, five seeded queue rows (sent / pending / failed) for
the Dashboard's degraded state and the Log.

Not rendered live, compared from source only: the connected Campaign
Intelligence states (no engine on the sandbox; the trigger-card states were
verified live earlier with a mock engine) and the multilingual routing cards
(the sandbox has one store language).

## Tokens and components

- **Tokens:** identical. Every `--s-*`, `--sp-*`, `--r-*`, `--fs-*`,
  `--shadow-*` value in the pack's token sheet matches the `:root` sheet in
  `view/adminhtml/web/css/smaily-admin.css`.
- **Components:** Pill, Banner, InlineStatus, ChoiceCard, ProgressBar and Tile
  match in anatomy, variants and states. The remaining micro-differences are
  the pack's own inconsistencies already listed in the target spec §5 (Tile
  value 30 px vs the 28 px token, the `off` Pill's untokenized greys, Tile gap
  6 px vs `--sp-2`, Banner gap 10 px vs `--sp-3`); the tokens win.
- **Design-pack leaks:** neither is present — no opt-in-mode selector on
  Contacts, no "Store view" dropdown in the RSS builder.

## Deviations, ordered by visibility

Visibility: **high** = a merchant sees it on first install (initial setup,
Dashboard, Settings > Connection); **med** = visible on a page a merchant opens
regularly; **low** = detail or rare state.

| # | Screen | What differs from the pack | Vis. | Status |
|---|---|---|---|---|
| 1 | Initial setup (all steps); Settings > RSS, Intelligence, Automations | Text, number and select controls rendered as browser defaults (2 px inset border, 1–2 px padding); the pack's control is 8×11 px padding, 1 px `--s-border-strong`, 2 px radius. Only the Settings > Connection fields had it. | high | fixed |
| 2 | Initial setup | Steps in a horizontal strip above the content; the pack has a 232 px left rail (26 px circles, active row white with a 3 px accent bar, upcoming steps grey) beside a grey content pane. | high | fixed (≥ 1024 px; narrower screens keep the strip) |
| 3 | Initial setup, Dashboard | Magento's Open Sans without antialiasing; the pack uses the system stack (`--font`). Settings already had it. | high | fixed |
| 4 | Initial setup | Secondary buttons (Test connection, Connect, Start import) in Magento's dark fill and Back as a dark button; the pack's secondary is white with a `--s-border-strong` border and Back is a ghost link. | high | fixed |
| 5 | Initial setup | Footer: on step 1 the primary button sat on the left (Back hidden) with no divider; the pack pins the primary group right under a divider. A long step error now wraps beside the button instead of pushing it out; the Overview step drops the empty footer. | high | fixed |
| 6 | Initial setup step 1 | No fixed `.sendsmaily.net` suffix on the subdomain field (Settings had it); the pack shows it. | high | fixed |
| 7 | Initial setup | Card headings 20 px / 400, labels and notes 14 px brown, default checkboxes; the pack has 16 px / 700 headings, 13 px / 600 labels, 12 px muted notes, accent checkboxes and the Contacts spacing rhythm Settings already used. | med | fixed |
| 8 | Settings > RSS | Four fields squeezed into one row (Sort by truncated), the feed URL in a plain input, no tab title or description, and a page-wide Save below the canvas; the pack has a two-column field grid, a monospace read-only URL chip beside Copy, and a tab-scoped footer. | med | fixed |
| 9 | Dashboard | With Campaign Intelligence disconnected, the three tiles left an empty fourth column; the pack's tile row spans the width. | med | fixed |
| 10 | Dashboard | Quick links as a bullet list with inline em-dash descriptions and 20 px / 400 panel headings; the pack has 14 px / 700 panel headers and link rows with an arrow. Our descriptions are kept as a muted second line. | med | fixed |
| 11 | Dashboard | Verdict sentence 16 px with a top-aligned dot; the pack has 19 px, centred. Now `--fs-18`, centred. | low | fixed |
| 12 | Settings tab strip | Active tab 3 px underline / 600; the pack has 2 px / 700. | low | fixed |
| 13 | Dashboard | No "Not connected" verdict: the verdict looks only at setup completion, failures and the engine, so with the Smaily credentials missing after setup it says "All systems normal" while the Smaily card says "Not connected" (read from the verdict logic, not driven live). The pack has a danger verdict with "Open Connection settings". | high | fixed — a "Not connected" danger verdict with "Open Connection settings"; "Connected" now means Smaily accepted the saved credentials at the last check (PRO-3560) |
| 14 | Initial setup | No "Step N of 5" kicker; the step title sits inside the card at 16 px instead of a 22 px heading above it. | med | fixed — "Step N of 5" kicker (EN + ET) and a 22 px step title above the card; the panels drop their in-card title in the initial setup (PRO-3561) |
| 15 | Initial setup, Settings | Error state: the pack has a transaction-level error banner plus danger styling and a message on the offending field; ours shows the error inline beside the button only. | med | fixed — a failed save shows an error banner above the form, marks the field the error names (danger border + message under it) and says "Saving failed." beside the button; editing the field removes the mark (PRO-3562) |
| 16 | Initial setup | Completed revisit: the pack shows a read-only summary card with a Completed pill and "Edit credentials"; ours reopens on the Overview step and shows the editable form. | med | fixed — a finished setup reopens on step 1 as a read-only summary (Subdomain, API username, Status pill) with a Completed pill beside the kicker and "Edit credentials" in the footer, which returns the form; Continue and the rail still lead through every step; no resting "Saved" status (PRO-3563) |
| 17 | Backfill (Contacts, Intelligence) | No status pill in the card header, no "X of Y · %" line under the bar, the idle Start import is secondary rather than primary, no footer divider. | med | deferred — the state rendering is JavaScript |
| 18 | Log | Status column is plain text; the pack shows status pills in the grid. | med | deferred — needs a grid column renderer |
| 19 | Log > Details | Magento's wide modal slide instead of a 452 px panel; header without event id and status pill; no attempt-history timeline; no "Retry now" / "Copy payload" footer; light payload block instead of a dark code block. | med | deferred — data and behaviour |
| 20 | Settings > Connection (multilingual stores) | Per-language credential blocks lack the pack's dashed reactive region, language chip, two-column grid and per-block status. | med | deferred — multilingual stores only; not rendered live |
| 21 | Settings > Intelligence | No tab title and description above the card; the page-wide Save stays below the canvas (also before the engine is connected, when there is nothing to save). The pack has no Intelligence frame. | low | deferred — needs new copy |
| 22 | Dashboard | Degraded state: no warning banner above the verdict, the "Review failures" button is orange rather than danger red; healthy state: no secondary "Open event log" button. | low | deferred — optional in the target spec |
| 23 | Dashboard | Recent activity is a table (Source / Type / Entity / Status / Updated); the pack shows compact rows (pill · summary · relative time). | low | accepted — the target spec §2.1(b) fixes these columns |
| 24 | Settings > Automations (engine triggers) | Cooldown and daily cap without unit suffix chips; enabled + test mode are checkboxes, not the pack's Mode select (these are the real fields). | low | accepted |

### Copy differences left as they are (terminology wins)

| Pack | Ours |
|---|---|
| Subscribers (tab) | Contacts |
| Connect Smaily / Consent & lawful basis / Multilingual routing / Automations / Review & finish | Connect / Contacts / Automations / Intelligence / Overview |
| API subdomain | Subdomain |
| Save & continue | Continue |
| Event log | Log |
| Resume setup wizard / Open Settings / View event log / Documentation | Settings / Log / Initial setup (the user guide is linked from the Overview step) |

## Verification of the fixes

Re-rendered on the sandbox in en_US and et_EE: every initial-setup step,
every Settings tab, the Dashboard (degraded state) and the Log. Computed-style
checks confirmed the control, button, typography and rail values above; the
RSS tab saved through its own footer ("Saved.") and the URL builder still
updates and copies; no JavaScript console errors. At 900 px the initial setup
falls back to the horizontal strip.
