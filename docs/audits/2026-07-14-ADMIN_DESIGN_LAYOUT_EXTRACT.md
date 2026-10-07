# Admin design pack — layout & element-visual extract (PRO-1369, analysis A1)

**Purpose.** Reconciliation input. This document captures ONLY what the design
pack (`docs/Magento Connect admin visual system.zip`) shows about **layout and
element visuals** — structure, hierarchy, element types/order, sizing/spacing,
design-token usage, shared-component usage and states. It does **not** assert
that any wording, option, or behaviour it implies is a feature we actually
build — see the "Design-implied features to validate" subsection on every
screen, and use it as the acceptance/rejection list for consolidation.

**Binding authority.** Design pack = authoritative for layout/visuals only.
NOT authoritative for copy or functionality. Where the design shows a
control/option that implies a feature (e.g. "single vs double opt-in"), it is
flagged, not accepted.

**Source.** Unpacked to a scratch dir (`/home/erkki/.claude/jobs/64b0d00d/tmp/design/`)
from `docs/Magento Connect admin visual system.zip`. All 14 `.dc.html` files
plus `support.js` and a `.thumbnail` were present and readable — **no files
were missing or unreadable**. These `.dc.html` files are "design component"
mockup documents: each is a live, data-driven HTML/JS preview (a `Component`
class returns inline-style objects consumed by a small templating runtime —
`sc-for`, `sc-if`, `dc-import`), not static screenshots. All visual values in
this extract are read directly from those inline style objects / literals, not
inferred.

**1:1 screen mapping to real templates** (for consolidation's reference; file
paths are current repo state, `view/adminhtml/templates/`):

| Design screen | Real template(s) |
|---|---|
| Dashboard | `dashboard/index.phtml` |
| Setup Wizard | `wizard/index.phtml`, `intro.phtml` |
| Settings (Connection/Subscribers/RSS tabs shown; Automations/Intelligence tabs exist but not detailed in this doc) | `settings/index.phtml` + `panel/{connection,subscribers,rss,automations,intelligence}.phtml`, `config/{rss-builder,assist}.phtml` |
| Engine Automations | `config/engine-automations.phtml`, `panel/automations.phtml` |
| Log | `log/details.phtml`, `log/failed-banner.phtml`, `log/url-filter-applier.phtml` |
| Backfill | embedded in `panel/subscribers.phtml` (+ `panel/panels-js.phtml` for JS); also referenced from the wizard per the design ("appears in wizard step 2 and in two Settings tabs") |

---

## 0. Assumptions

- The design pack's inline `:root` token block (repeated per file, values
  identical) is the single source of truth for tokens; `Design Tokens.dc.html`
  is the canonical, complete listing (includes roles not repeated in every
  screen file, e.g. `--s-parked`, `--s-bar-*`).
- `hint-size` / `hint-placeholder-count` attributes are mockup-tooling
  scaffolding (placeholder sizing hints for the preview renderer), not part of
  the visual spec — ignored below except where they reveal an intended
  component footprint (e.g. pill approx. width).
- Where the design shows literal sample data (e.g. "4,812 subscribers",
  "Acme Nordic", specific IDs/emails), that is placeholder content for the
  mockup, not a functional claim — flagged only where it implies a feature
  (e.g. a specific metric existing) not already known to exist.
- "Not redesigned" callouts in the pack (system.xml Configuration page, native
  `ui_component` grid chrome, admin global nav/header, storefront) are taken
  at face value — out of scope for this visual reconciliation.
- Estonian-longer-than-English (+20-30%) is stated repeatedly as a layout
  constraint (no fixed-width labels/buttons) — treated as a layout rule, not a
  feature.

---

## 1. Components/tokens vocabulary (read first — everything below composes from this)

### 1.1 Design tokens (`Design Tokens.dc.html`)

Paste-ready `:root` block (values as authored):

```css
:root {
  /* Surfaces & borders */
  --s-bg:#f4f4f4;      --s-surface:#ffffff;   --s-surface-2:#fbfbfb; --s-surface-3:#f0f0f0;
  --s-border:#e3e3e3;  --s-border-2:#c9c9c9;  --s-border-strong:#adadad;

  /* Text */
  --s-text:#303030;    --s-text-2:#5a5a5a;    --s-text-3:#8a8a8a;
  --s-link:#1979c3;    --s-link-hover:#006bb4;

  /* Brand accent (Smaily) + Action (Magento primary) */
  --s-accent:#e91e63;  --s-accent-hover:#c2185b; --s-accent-soft:#fce4ec; --s-accent-border:#f4a6c0;
  --s-action:#eb5202;  --s-action-hover:#ba4000;

  /* Status roles: fg / soft bg / border */
  --s-success:#1f7a34; --s-success-soft:#e5efe5; --s-success-border:#93c47d;
  --s-warning:#8a5a00; --s-warning-soft:#fdf0d5; --s-warning-border:#e0c56e;
  --s-danger:#bb2b0e;  --s-danger-soft:#fae5e5;  --s-danger-border:#e0a3a3;
  --s-info:#0f5a94;    --s-info-soft:#e6f0f8;    --s-info-border:#9cc3e0;
  --s-neutral:#5a5a5a; --s-neutral-soft:#eeeeee; --s-neutral-border:#cccccc;
  --s-parked:#6b4ea3;  --s-parked-soft:#efe9f7;  --s-parked-border:#c3b3e0;

  /* Banner left-bar (Magento message levels) */
  --s-bar-success:#4caf50; --s-bar-info:#1979c3; --s-bar-warning:#e0a800; --s-bar-error:#d72c0d;

  /* Spacing (4px base) */
  --sp-1:4px; --sp-2:8px; --sp-3:12px; --sp-4:16px; --sp-5:20px; --sp-6:24px; --sp-8:32px; --sp-10:40px;

  /* Radius */
  --r-1:2px;  /* inputs, pills-square */
  --r-2:3px;  /* banners, badges */
  --r-3:5px;  /* cards, panels */

  /* Type scale */
  --fs-11:11px; /* PILL / OVERLINE — 700 weight, uppercase, .4px tracking */
  --fs-12:12px; /* caption & meta text — --s-text-3 */
  --fs-13:13px; /* body — default admin size */
  --fs-14:14px; /* emphasis / control labels — 600 weight */
  --fs-16:16px; /* section heading — 700 */
  --fs-18:18px; /* panel title — 700 */
  --fs-22:22px; /* screen title — 700 */
  --fs-28:28px; /* metric value — 700 */

  /* Font stacks (system only) */
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;

  /* Elevation */
  --shadow-1:0 1px 2px rgba(0,0,0,.06);
  --shadow-2:0 2px 8px rgba(0,0,0,.12);
  --shadow-panel:-2px 0 14px rgba(0,0,0,.16); /* slide-out */
}
```

Notes from the token page:
- Light theme only, system fonts only, no external assets — "tuned to sit
  inside native Magento 2 adminhtml without clashing."
- Note also present but not in every screen's local `:root` echo:
  `--s-neutral-border:#cccccc` (Design Tokens page) vs some component files'
  local echoes omit it — treat the Design Tokens page as canonical for the
  full set.
- Radius use-cases as labelled: `--r-1` inputs/square pills, `--r-2`
  banners/badges, `--r-3` cards/panels. (Pill itself actually renders fully
  round — `border-radius:11px` — not `--r-1`/`--r-2`; treat the "pills-square"
  radius label as referring to a different, non-rounded pill-shaped affordance
  if one exists, or as a labelling artifact — flag for consolidation.)

### 1.2 Shared components (`Components.dc.html` + individual `*.dc.html`)

**InlineStatus** (`<span class="smaily-inline-status">`)
- Anatomy: 13px spinner/glyph + `--fs-13` 600-weight text, gap `--sp-2`.
- States: `idle` (muted, `--s-text-3`, empty text by default), `working`
  (spinning ring, border `#d0d0d0`/top `#5a5a5a`, `.7s linear infinite`,
  color `--s-text-2`, default text "Saving…"), `saved` (14px SVG checkmark,
  color `--s-success`, default text "Saved"), `error` (14px filled circle
  with "!" glyph, color `--s-danger`, default text "Couldn't save").
- Placement rule: sits immediately right of the button that triggered the
  action; button itself does not change its own label — it disables instead.
  `saved` auto-clears after ~2.5s (stated in Components page annotation).
  `white-space:nowrap`, never fixed-width (i18n: Estonian "Salvestatakse…" is
  longer).

**Pill** (`<span class="smaily-pill smaily-pill--{variant}">`)
- Anatomy: 6px dot + `--fs-11` 600-weight uppercase label, padding `2px 9px`,
  border-radius `11px` (fully round), gap 5px, `white-space:nowrap`.
- Two families of variants, sharing shape:
  - **Automation-mode family:** `active` (success role), `test` (info role),
    `off` (neutral, fg `#6b6b6b`/bg `#efefef`/bd `#cfcfcf` — a slightly
    different neutral than the shared `--s-neutral*` triple, note
    discrepancy).
  - **Queue-status family:** `sent` (success role, same colors as `active`),
    `pending` (warning role), `failed` (danger role), `parked` (a dedicated
    violet/"parked" role — fg `#6b4ea3`/bg `#efe9f7`/bd `#c3b3e0` — deliberately
    distinct from `failed` so "held" never reads as "hard failure").
  - Also declared as a variant option (in the component's enum) but not shown
    in any screen instance: `neutral`.
- Each variant = one status role's fg/soft-bg/border trio, dot color = fg.

**Banner** (`<div class="message message-{level}">`)
- Maps 1:1 to Magento's four message levels: `success`, `info`, `warning`,
  `error`.
- Anatomy: 4px left bar (`--s-bar-*`), soft background (`--s-*-soft`), 16px
  circular icon (checkmark / "i" / "!" / "x", stroke = level fg), optional
  bold title line, message line, optional right-aligned action link
  (underlined, bold, same fg color, `nowrap` but the whole row is flex so it
  wraps under the message on narrow widths — not fixed-width).
- Border: 1px solid bar-color at 55% opacity, overridden left side to 4px full
  bar color. Padding `12px 14px`, radius `--r-2` (3px, per Components
  annotation, though sample renders `3px` inline literal).

**Metric Tile** (`<div class="smaily-tile smaily-tile--attention">`)
- Anatomy: 3px top bar, uppercase `--fs-12` 600-weight label (with optional
  attention badge top-right), `--fs-28`-ish (rendered 30px in the standalone
  Tile mockup, `--fs-28` in the token/Components pages — minor inconsistency,
  flag) tabular-figure value, optional caption line in `--s-text-3`.
- Two variants only: default (border `--s-border`, top bar `--s-border-2`,
  value `--s-text`) and `--attention` (border `--s-danger-border`, top bar
  `--s-danger` full, value `--s-danger`, adds an uppercase badge e.g. "Needs
  attention" in the danger soft/border trio).
- Grid: `repeat(auto-fill,minmax(210px,1fr))` — reflows 4→2→1, no fixed track.
- Explicit design rule: "Never invent trend arrows the data can't back" — i.e.
  no sparkline/trend affordance in this component, by design.

**Radio Choice-Card** (`<label class="smaily-choice is-selected">`)
- Anatomy: 18px circular radio indicator (2px border, filled 9px accent dot
  when selected) + title row (title + optional "Recommended" badge, success
  role, uppercase) + description line below.
- States: unselected (white bg, `--s-border-2` border) vs selected (tinted bg
  ~`#fdf2f6`, `--s-accent` border, `box-shadow 0 0 0 1px var(--s-accent)` ring).
- Whole `<label>` is the click target; title row wraps (flex-wrap) so long
  Estonian titles don't clip.

**ProgressBar** (`<progress>` / `.smaily-progress`)
- Anatomy: 8px track (radius 6px, border `#dcdcdc`, bg `#ececec`) + fill.
- States: `running` (repeating 45°-diagonal accent stripe, `.8s linear
  infinite` animation, 28px stripe period), `done` (solid success green, no
  animation), `failed` (solid danger red, no animation), `stopped` (solid
  neutral gray, no animation). Width transitions `0.3s ease`.
- Note: component enum only defines 4 states (`running/done/failed/stopped`);
  Backfill screen additionally uses a `progressState` value of `'failed'` for
  its own "done with failures" state and `'stopped'` for its own "cancelled"
  state — i.e. the Backfill screen maps 6 conceptual backfill states onto
  these 4 progress-bar colors (done→success, done-with-failures→danger via
  `failed`, stopped(mid-run error)→danger via `failed`, cancelled→neutral via
  `stopped`). Naming across screen and component doesn't line up 1:1 — flag
  for consolidation, not a new fact, just a vocabulary seam.

---

## 2. Per-screen extract

### 2.1 Dashboard (`Dashboard.dc.html` → `dashboard/index.phtml`)

**Layout tree (top to bottom, inside the Magento content area):**
1. Page title row: H3 "Smaily Connect" (`--fs-22`/700) + subtitle span "Newsletter & automation sync" (13px, `--s-text-3`).
2. Optional top **Banner** (full width, only in non-healthy states).
3. **Verdict hero** — single card, flex row: 14px status dot (level color + soft glow ring `box-shadow 0 0 0 4px <color>22`) + text block (uppercase kicker label, `--fs-11`/700, level-fg color + verdict sentence, `--fs-16`-ish/19px/700, max-width 66ch) + optional trailing CTA button (primary orange or danger-red, or secondary depending on state). Card: 5px left bar in level color, 1px border level-color@55%, soft background tint, radius 6px, padding `18px 20px`.
4. **Connection strip** — 3-column grid (`repeat(3,1fr)`, gap 12px), one card per connection: dot (14px, level color) + label (13px/600) + sub-line (12px, `--s-text-3`) + a **Pill** (automation-mode family: active/off/failed).
5. **Metric tiles** — 4-column grid (`repeat(4,1fr)`, gap 12px), each a **Tile** component instance.
6. **Lower two-column area** (`1.7fr 1fr`, gap 14px):
   - Left: "Recent activity" panel — header row (title + "View full log →" link) then either an empty-state (38px circle placeholder + title + body, centered) or a list of rows: **Pill** (queue-status family) + truncated text line (ellipsis overflow) + right-aligned monospace relative-time.
   - Right: "Quick links" panel — header row, then a list of link rows (label + "→" chevron), each full-width, bottom-bordered.

**Sizing/spacing/tokens:** page max-width 1180px; card radius 5-6px throughout; borders `--s-border`; gaps 12-18px; hero uses 5px left-bar + soft tint per level (success/warning/danger/info — reuses banner-bar palette conceptually but hero has its own `heroFor()`/`dot()`/`kicker()` mapping, not literally banner tokens); activity row text truncates with `text-overflow:ellipsis`, `white-space:nowrap`.

**Components used:** Banner (top, conditional), Pill (connection strip: automation-mode family; activity list: queue-status family), Tile (metric grid, default + attention variants).

**States shown (4, all annotated):**
1. **Healthy** — no banner; hero = success; all 3 connections active; tiles all normal (Failed=0, no attention); activity populated (sent/pending mix); hero CTA = secondary "Open event log".
2. **Degraded** — warning banner ("Attention needed… Review failures") above hero; hero = warning; connections still show Smaily/Intelligence/Browse-tracking all active; Failed tile flips to `attention` variant with badge; activity shows failed entries; hero CTA = danger-styled "Review failures".
3. **Disconnected** — error banner (no title, Magento error level) "Couldn't reach the Smaily API (401)…"; hero = danger "Not connected"; connection strip shows a dependency chain — Smaily = failed/error pill with sub "Credentials invalid (401)", Campaign Intelligence and Browse tracking both fall to `off` pill with sub "Requires Smaily connection"; Pending tile flips to attention ("blocked"); activity shows a blocked-queue pending row + a failed connection-test row; hero CTA = danger "Open Connection settings".
4. **Fresh install** — info banner "Finish setup… step 3 of 5"; hero = info "Setup incomplete"; connections: Smaily active, Intelligence/Browse tracking both `off` with sub "Not configured yet"; tiles for Subscribers-synced and Sent(24h) show an em-dash "—" instead of 0 (explicit "not-yet-collected ≠ zero" rule); Pending/Failed show 0; activity panel shows the empty-state (icon + "No activity yet" + body copy); hero CTA = primary orange "Resume setup".

**Design-implied features to validate (NOT authoritative):**
- A specific "Campaign Intelligence" connection tile distinct from "Smaily" and "Browse tracking" as three separate dependency-chain rows, with the specific dependency rule "Intelligence/Browse-tracking → Off with reason 'Requires Smaily connection'" when Smaily is disconnected — needs functionality validation (does the module actually gate these two independently, and word-for-word this way?).
- "View full log →" and "Resume setup wizard / Open Settings / View event log / Documentation" quick-links list (4 fixed links, Index.dc.html) — validate these are the real, current quick-link set and target routes.
- The "Review failures" action deep-linking the log "pre-filtered to failed-24h" (per Dashboard annotation) — validate a filtered deep-link actually exists/is planned (relates to Log screen's own "Show failed (24h)" control, see 2.5).
- Metric tiles named exactly "Subscribers synced" / "Sent (24h)" / "Pending" / "Failed" — validate this is the real metric set (vs. more/fewer/different metrics currently computed).
- Em-dash-for-not-yet-collected-data behavior on tiles — validate this is implemented (vs. showing 0).
- "in queue" / "blocked" as tile captions changing meaning by state — validate the Pending tile's caption is state-aware.

---

### 2.2 Setup Wizard (`Setup Wizard.dc.html` → `wizard/index.phtml`, `intro.phtml`)

**Layout tree — common wizard shell (all 5 frames share it):**
- Two-column layout inside the content area: **left stepper rail** (fixed 232px, background `#fafafa`, right border `--s-border`, padding `14px 0`) + **right content pane** (flex:1, padding `26px 30px`).
- Rail: 5 step rows, each = circular index marker (26px, done=accent-filled+✓, active=white+accent 2px ring+number, upcoming=gray fill+gray border+number) + step name (13px, 700/500 by state) + sub-label (11px). Active row gets a 3px left accent bar and white background; others transparent.
- Content pane: uppercase kicker "Step N of 5" (11px/700) → H3 step title (22px/700) → intro paragraph (14px, max 60-62ch) → step body (varies) → footer (border-top divider, "Back" ghost-link button left, primary/secondary buttons right-aligned, `margin-left:auto`).

**5 named steps (from rail data, `buildRail()`):**
1. Connect Smaily — "API credentials"
2. Consent & lawful basis — "GDPR presets"
3. Multilingual routing — "Language → account"
4. Automations — "Triggers & workflows"
5. Review & finish — "RSS + go live"

**Frame-by-frame (4 states × representative steps):**

1. **Default / resting (Step 1)** — form: 3 stacked fields (max-width 440px): "API subdomain" (input + fixed suffix chip ".sendsmaily.net"), "API username" (plain input), "API password" (password input + hint line "Leave unchanged to keep the saved password"). Footer: Back (ghost) | Test connection (secondary) | Save & continue (primary/orange). Rail circle states per spec: done=accent-fill+check, active=accent-ring, upcoming=gray — and an explicit "Phase-1 fix baked in" annotation: **completed steps stay unlocked/clickable-forward, no re-lock on Back.**

2. **Saving (Step 1)** — same fields collapsed to a compact card; two rows demonstrated: "Test connection" (secondary btn) + InlineStatus(`working`, "Testing connection…"); divider; "Save & continue" (primary, disabled 55% opacity/not-allowed) + InlineStatus(`working`, "Saving…"). Explicit rule: button never swaps its own label; it disables while InlineStatus animates beside it; layout never reflows (`nowrap`, grows rightward).

3. **Error (Step 1)** — top error Banner ("Couldn't verify credentials — 401 Unauthorized"); fields: "API username" (unchanged) + "API password" (danger-bordered input, tinted bg, box-shadow ring + field-level error message below in danger color/600-weight); footer row: Test connection (secondary) + InlineStatus(`error`, "Connection failed"). Explicit two-layer error model: (1) top banner for the transaction result, (2) per-field error styling+message. Annotated "Phase-1 fix": exception fragments translated before interpolation so et_EE never shows raw English API text.

4. **Multilingual routing (Step 3) — choice-cards + reactive credential blocks** — intro sentence templated with store language count ("Your store has 2 languages (English, Estonian)"); 3 stacked **ChoiceCard** instances (max-width 680px): "One account for all languages" (Recommended badge, not selected in this render), "One account, per-language automations" (not selected), "Separate account per language" (selected in this render). Below the cards, a **live-reactive region** (dashed accent-tinted border `1px dashed #d6c3cb`, bg `#fbfbfb`) appears only because "separate account" is selected: uppercase accent-colored label "Live-reactive · appears for 'separate account'" (explicitly flagged in the mockup as a dev-spec label, not shipped copy) + a 2-column grid of per-language credential cards, each = 26×18px flag/language-code chip + language name + "Subdomain"/"Username" small fields + an InlineStatus (`saved`/"Verified" for EN, `idle`/"Not tested yet" for ET). Grid stacks 2→1 on narrow widths. Footer: Back | Save & continue.

5. **Completed-revisit (Step 1, after full setup)** — kicker row now also carries a Pill(`active`,"Completed"); body replaced by a read-only 3-row summary card (Account / Subdomain / Status rows, each a label+value pair, Status row uses Pill(`active`,"Connected")); footer: Back | Edit credentials (secondary) | InlineStatus(`saved`,"Saved", persists as resting confirmation). Explicit rule: after completion every rail step shows a green check and stays clickable; revisiting shows the read-only summary, entering edit mode returns the default form — "no re-lock" restated as the core fix this design closes.

**Sizing/spacing/tokens:** shell border `#d6d6d6`/radius 6px/shadow `0 1px 3px rgba(0,0,0,.08)`; ghosted Magento chrome bar `#514943` bg (explicitly "not redesigned", shown only for orientation); inputs `padding:8px 11px`, border `--s-border-strong`, radius 2px (`--r-1`); primary button = Magento action-orange `#eb5202`/border `#d44f00`; field labels always stack above inputs, "never fixed-width" (i18n).

**Components used:** Banner (error frame), InlineStatus (saving/error/multilingual credential blocks/completed-revisit), Pill (completed-revisit — automation-mode `active` variant reused for "Completed"/"Connected" labels, not its documented automation/queue semantic — flag), ChoiceCard (multilingual frame, 3 instances).

**Design-implied features to validate (NOT authoritative):**
- The exact 5-step sequence and names ("Consent & lawful basis / GDPR presets", "Automations / Triggers & workflows", "Review & finish / RSS + go live") — validate against the real wizard's actual step set/order/names.
- "completed steps stay unlocked and clickable-forward — no re-lock on Back" — a specific navigational behavior claim; validate current wizard implements (or should implement) this.
- Three-option multilingual routing model verbatim: (a) one account all languages, (b) one account/per-language automations, (c) separate account per language, with (a) marked "Recommended" — this is the flagged "single vs double opt-in"-style case explicitly called out in the task: **validate which of these three modes actually exist/are supported**, and whether "Recommended" badge placement on (a) is accurate.
- "Switching away from a per-language mode triggers a destructive-switch confirm" (stated in the Frame-4 annotation) — validate this confirm dialog exists/is intended.
- Per-language credential block test states "Verified" / "Not tested yet" — validate the underlying per-store credential test feature.
- Step 1 "revisit" read-only summary + "Edit credentials" toggle pattern — validate this interaction (vs. always-editable form) is intended.
- Test-connection button/flow as a distinct action from Save — validate it exists at every step where shown (only step 1 shown).

---

### 2.3 Settings (`Settings.dc.html` → `settings/index.phtml` + `panel/*.phtml`, `config/rss-builder.phtml`)

**Layout tree — common shell:**
- Tab strip (horizontal, top of content, `padding:0 24px`, gap 4px between tab labels): active tab = 2px accent bottom-border + 700 weight + `--s-text`; inactive = 600 weight + `--s-text-2`, transparent border. 5 tabs named (from `tabs()` helper): **Connection, Subscribers, Automations, Intelligence, RSS**. Strip scrolls horizontally on narrow widths / long Estonian labels (no wrap).
- Below strip: H3 tab title + one-line description, then a white content card (border `--s-border`, radius 6px, padding `20px 22px`), then a **tab-scoped footer** (border-top divider, `max-width:620px`): action button(s) + one InlineStatus per tab — explicitly "tabs never share a global save; status is scoped to the tab."

**3 tabs detailed in the pack (Automations tab specced separately — see 2.4; Intelligence tab not detailed at all in this pack):**

1. **Connection tab (default state)** — 3-field form identical in shape to wizard step 1 (subdomain+suffix, username, password+hint), max-width 440px inside a 620px card; below the fields a status line: "Status:" + Pill(`active`,"Connected") + "as 'Acme Nordic'" (13px muted). Footer: Test connection (secondary) | Save Connection (primary) | InlineStatus(`idle`,"No changes to save").

2. **Subscribers tab (live-reactive + saving)** — one field: "Opt-in mode" `<select>` (max-width 360px) with 2 listed options ("Double opt-in (confirmation email)", "Single opt-in"); below it a **dashed reactive region** (same visual language as the wizard's reactive block) that appears "because 'Double opt-in' is selected", containing one more select: "Confirmation email (Smaily automation)" with 2 sample options (EN/ET automation names). Explicit interaction rule: switching to single opt-in **collapses the region with a height transition**. Footer state shown mid-save: Save Subscribers button disabled + InlineStatus(`working`,"Saving…"); annotated resting-state sequence after save: "Saved ✓" for ~2.5s then reverts to "No changes to save".

3. **RSS tab (new · "A4" — URL builder)** — 4-field grid (`1fr 1fr`, max-width 520px): Store view (select, EN/ET sample), Source (select, "Category: New Arrivals"/"Best sellers" sample), Max items (numeric input, sample "12"), Sort (select, "Newest first"/"Price" sample). Below: a labelled read-only "Feed URL" row — monospace code chip (horizontal-scroll, no-wrap, bg `--s-surface-3`) showing a composed URL (sample: `https://acme.com/smaily/rss/feed/store/1/category/12/limit/12`) + a "Copy" secondary button; under that, an InlineStatus(`saved`,"Copied to clipboard") confirming the copy action. Footer: Save RSS | InlineStatus(`idle`, empty). Explicitly stated to **replace today's static system.xml comment**, and that "the wizard's final step deep-links here."

**Sizing/spacing/tokens:** shell/chrome identical pattern to wizard (ghosted Magento header bar, 6px shell radius); inputs/selects share the wizard's input styling (border `--s-border-strong`, radius 2px, custom-styled select caret "▾" absolutely positioned); reactive-region dashed border color is a desaturated accent-tint (`#d6c3cb`) distinct from the solid accent used for selection elsewhere.

**Components used:** Pill (Connection tab status line — automation-mode `active`), InlineStatus (all 3 tabs' footers + RSS copy confirmation).

**Design-implied features to validate (NOT authoritative):**
- 5-tab structure and exact names (Connection/Subscribers/Automations/Intelligence/RSS) — validate against real settings tab set (repo has `panel/{connection,subscribers,automations,intelligence,rss}.phtml`, so tab *names* line up 1:1 with existing panels — low risk, but confirm ordering and that no tab is missing/extra).
- **"Opt-in mode: Double opt-in vs Single opt-in" select, with a reactive confirmation-email-automation picker shown only for double opt-in** — this is exactly the kind of design-invented option flagged by the task brief as a red flag; **must be validated against real functionality** (does the module support single vs. double opt-in at all, and is there a Smaily-automation-driven confirmation email selection?).
- RSS builder's 4 named controls (Store view / Source / Max items / Sort) and their sample option sets ("Category: New Arrivals", "Best sellers", "Newest first", "Price") — validate the actual RSS builder's real parameter set (repo has `config/rss-builder.phtml` — cross-check field names 1:1).
- "Copy to clipboard" button + confirmation via InlineStatus — validate this exact interaction is implemented for the feed URL.
- Per-tab save scoping (no shared/global save state) — validate as an intended behavior, not just a visual choice.

---

### 2.4 Engine Automations (`Engine Automations.dc.html` → `config/engine-automations.phtml`, `panel/automations.phtml`)

**Layout tree — lives inside the Settings "Automations" tab:**
- Section header row (wraps on narrow): title "Engine-run automations" + description ("Store events trigger Smaily workflows. Test mode logs without sending.") on the left; "How triggers work →" link on the right.
- **Trigger list** — vertical stack of **cards** (not a dense table), gap 12px. Each card: left block (min-width 220px) = trigger name (14px/700) + a **Pill** (run-mode: active/test/off) inline beside it, then a muted description line (12px) below; right block = a row of controls, right-aligned, wrapping under on narrow widths:
  - "Workflow" — labelled select (200px wide), disabled when the row's mode is Off.
  - "Cooldown" — labelled numeric input (54px) + fixed unit suffix chip "h", disabled when Off.
  - "Daily cap" — labelled numeric input (70px), disabled when Off.
  - "Mode" — labelled select (120px) driving the Pill (uppercase control-label style, `--fs-11`/700).
- Footer: single "Save automations" button (one save for the whole block, not per-row) + InlineStatus.

**4 sample trigger rows shown** (labels are placeholder-plausible, not to be taken as the real trigger catalog — see flag below): Abandoned cart (active, cooldown 4h, cap 500), Welcome (active, cooldown 0, cap "—"), Back in stock (test mode, cooldown 24h, cap 200, card gets a distinct info-blue left-bar accent), Win-back (off, all controls disabled/dimmed, card bg `#fafafa`).

**Validation-error state (Frame 2):** top error Banner ("2 automations couldn't be saved. Fix the highlighted fields below."); one card shown with **field-level errors**: "Workflow" select gets danger border/bg + placeholder "— Select workflow —" + error message "Required when a trigger is Active" below it; "Daily cap" input gets danger border/bg (sample invalid value "abc") + error message "Must be a whole number." below it; card itself gets a danger accent (left-bar + border), rest of the row unaffected. A second, valid row is shown plainly below for contrast. Footer InlineStatus(`error`, "Couldn't save — 2 fields need attention") states the error count. Explicit validation rule stated: **"a workflow is required once a row is Active."**

**Fallback states (Frame 3+4, side by side):**
- **Not connected** — the entire trigger block is replaced by a single centered empty-state card: 44px circular info-badge ("i", info role) + bold title "Connect Smaily to set up automations" + body copy + one primary CTA "Open Connection tab". Explicit rule: "no dead controls."
- **Catalog load failed** — a warning Banner with title "Couldn't load workflows", message reassuring saved automations are unchanged, and a "Retry" action; below it the saved trigger cards are still rendered but at 55% opacity and `pointer-events:none` (non-interactive), each showing a "· workflow list unavailable" note where relevant. Explicit rule: "the failure is honest and non-destructive, existing config is never wiped."

**Sizing/spacing/tokens:** card border `--s-border` (error card: `--s-danger-border` + 3px danger left-bar); control label style = uppercase `--fs-11`/700/`--s-text-3` (error variant recolors to `--s-danger`); numeric inputs use a suffix-chip pattern identical to the subdomain field elsewhere (input + attached suffix box, no border between).

**Components used:** Pill (run-mode family: active/test/off, on every row), Banner (error + warning fallback), InlineStatus (footer save states).

**Design-implied features to validate (NOT authoritative):**
- **The entire trigger catalog sample set** (Abandoned cart / Welcome / Back in stock / Win-back) with specific cooldown-hour and daily-cap defaults — these are mockup placeholders; validate against the real engine-automations trigger catalog (per STATUS.md, this is dynamically sourced from the engine's workflow catalog, localized — the pack's static names are illustrative only, not a spec for which triggers exist).
- Per-trigger controls: Workflow (select), Cooldown (hours, numeric), Daily cap (numeric), Mode (select: off/test/active) — validate this is the real control set per trigger (vs. more/fewer fields) and that "cooldown" and "daily cap" concepts exist as such in the real automation model.
- Validation rule "workflow required once Active" — validate as real business logic, not just a mockup illustration.
- "How triggers work →" help link — validate target/existence.
- Retry action on catalog-load failure, and the specific copy "Your saved automations are unchanged" — validate the retry mechanism and the non-destructive guarantee are actually implemented as described.

---

### 2.5 Event Log (`Log.dc.html` → `log/details.phtml`, `log/failed-banner.phtml`, `log/url-filter-applier.phtml`)

**Explicit framing:** "The Magento `ui_component` grid stays native — not redesigned." Phase 3 adds exactly two things around it.

**Addition 1 — Failed-24h banner + native grid (Frame 1):**
- A warning **Banner** above the grid: title "12 events failed in the last 24 hours", message "Most were rate-limited and will retry automatically. 2 are parked and need attention.", action "Show failed (24h)".
- Below it, the grid is rendered as a labelled ghost ("native ui_component — as-is" tag, top-right, dashed border) with a toolbar (Filters/Search/count), header row (ID/Type/Recipient/Status/Last attempt/Action, uppercase 11px/700), and sample rows each showing: checkbox, monospace ID, type, monospace-truncated recipient, a **Pill** (queue-status family), muted last-attempt text, and a right-aligned "Details" action link (new — added to the existing Action column).
- Explicit rule: "Show failed (24h)" applies the grid's **own native filter preset** (status=failed/parked, created ≥ now-24h) — "drives native filtering, doesn't replace it."

**Addition 2 — Details slide-out panel (Frame 2, "A2"):**
- Layout: dimmed/desaturated grid remains visible behind a semi-opaque scrim (`rgba(20,18,16,.28)`); a **452px-wide right-docked panel** (`box-shadow: -2px 0 14px rgba(0,0,0,.16)` = `--shadow-panel` token) slides over it, full height, flex column: header / scrollable body / footer.
  - **Header:** monospace "Event #4471" id line + event type (17px/700) + status Pill, and a 30×30px "×" close button top-right.
  - **Body (scrollable):**
    1. Summary grid (`auto 1fr` label/value pairs): Recipient (monospace, partially masked, with a "redacted" tag), List, Store view, Created (timestamp).
    2. "Attempt history" section — a vertical timeline: each attempt = a dot+connecting-line rail (dot color = result: danger for failed, info-blue for scheduled/pending) beside a text block (Attempt N + a small colored result badge + timestamp (monospace) + one-line detail, e.g. "HTTP 500 · Smaily internal error").
    3. A distinct **"honest retry line"** callout (info-tinted box, ↻ icon): "Retry 3 of 5 · next automatic attempt ~14:26 (in ~12 min). No manual action needed." — annotated: for a parked item this instead reads "No further retries — parked."
    4. "Request payload" section header with a "PII redacted" tag, then a dark monospace code block showing a JSON payload with masked email/name fields.
    5. "Response" section header, then a dark monospace code block (recolors — darker red-tinted background/border — when the response represents an error, e.g. HTTP 429).
  - **Footer:** "Retry now" (secondary button), "Copy payload" (ghost/link-style button), an InlineStatus placeholder for feedback.

**Sizing/spacing/tokens:** grid/table typography matches the token type-scale (uppercase 11px headers, 12-13px body); redaction tag = small uppercase badge using the "parked" violet role (`#6b4ea3`/`#efe9f7`/`#c3b3e0`) — reused here for "redacted"/"PII redacted" labelling, not for parked-queue status; code blocks: dark theme (`#1f2329`/`#e6e6e6` normal, `#2a1f1e`/`#f2c9c2` error variant), monospace, `white-space:pre`, horizontal scroll.

**Components used:** Banner (failed-24h warning), Pill (grid rows: queue-status family; slide-out header: failed variant; attempt-history badges use ad hoc mini-badges, not the Pill component per se — flag: these are a bespoke smaller badge, not an import of `Pill`), InlineStatus (footer, idle placeholder in the mockup).

**Design-implied features to validate (NOT authoritative):**
- The "Show failed (24h)" banner action driving a **native grid filter preset** (status=failed/parked AND created≥now-24h) — validate this exact filter combination is/will be implemented (vs. e.g. just failed, or just 24h).
- The **Details slide-out** as a whole new surface (currently, per repo, `log/details.phtml` exists — cross-check whether it's rendered as a slide-out panel today or some other UI, e.g. a modal or separate page).
- "Attempt history" timeline with per-attempt HTTP-status detail lines, and the specific retry-scheduling copy ("Retry 3 of 5 · next attempt ~14:26") — validate the retry/backoff model surfaces this level of detail (attempt count, next-attempt ETA) today.
- "Copy payload" and "Retry now" actions in the slide-out footer — validate both exist/are planned.
- PII redaction display (masked email/name with a "redacted"/"PII redacted" tag) shown in both the grid recipient column and the payload block — validate the real redaction behavior and where it applies (grid vs. detail vs. both).
- Parked-vs-failed distinct queue statuses (violet "parked" pill family) — validate "parked" is a real distinct state in the queue model (STATUS.md/backfill docs suggest yes — cross-check wording).

---

### 2.6 Backfill (`Backfill.dc.html` → embedded in `panel/subscribers.phtml`, referenced from the wizard)

**Framing:** "One component, three contexts" — appears in wizard step 2 (per this doc's step numbering, but note Setup Wizard's own rail lists step 2 as "Consent & lawful basis", not backfill — see flag below) and "two Settings tabs." Cancel is always available while running; stopping/cancelling never loses already-imported records; failures deep-link to the log.

**Card layout (shared shell across all 6 states):**
- Header row: title "Historical import" (15px/700) + sub-label "Sync existing Magento subscribers to Smaily" (12px, muted), with an optional status **Pill** top-right (not shown in idle state).
- Optional **ProgressBar** block (shown in every state except idle): the bar itself + a line below with a bold "X of Y" count (tabular figures) left and a percentage (monospace, muted) right.
- **Verdict/body line**: optional small circular icon (✓/!/×/— depending on state, colored per state) + a body sentence, color-matched to the state's role.
- Optional deep-link line ("View N failures in the event log →" / "See the error in the event log →").
- Footer (border-top divider): 1-2 buttons (varies by state: primary/secondary/danger) + optional InlineStatus.

**6 states (each independently annotated):**
1. **Idle** — no pill, no progress bar. Body: "About 4,812 existing subscribers can be imported. This runs in the background — you can leave the page." One button: "Start import" (primary).
2. **Running** — Pill(`pending`,"Running"); progress bar `running` state at 62%; count "2,984 of 4,812"; body "Importing… roughly 4 minutes remaining."; one button "Cancel" (secondary) + InlineStatus(`working`,"Syncing…").
3. **Done (clean)** — Pill(`sent`,"Done"); progress `done` at 100%; count "4,812 of 4,812"; success-colored body with ✓ icon: "Done — 4,812 of 4,812 subscribers synced. No failures."; one button "Run again" (secondary).
4. **Done with failures** — Pill(`sent`,"Done"); progress recolored to the `failed`(danger) visual at 100%; count line explicitly separates "4,769 synced · 40 parked · 3 failed"; warning-colored body with "!" icon; a deep-link "View 3 failures in the event log →"; one button "Retry failures" (secondary). Explicitly the flagship "honesty" state: **parked ≠ failed, counted and shown distinctly.**
5. **Stopped (error mid-run)** — Pill(`failed`,"Stopped"); progress `failed`(danger) at 25%; count "1,204 of 4,812 before stopping"; danger-colored body with "×" icon: "Stopped after a Smaily API error. The 1,204 already imported were kept — nothing was lost."; deep-link "See the error in the event log →"; two buttons: "Resume import" (primary) + "Start over" (styled as the danger/destructive choice).
6. **Cancelled (by user)** — Pill(`off`,"Cancelled"); progress `stopped`(neutral gray) at 45%; count "2,150 of 4,812 imported"; neutral-colored body with "—" icon: "You cancelled at 45%. The 2,150 imported records were kept. Resume any time."; two buttons: "Resume import" (primary) + "Discard & restart" (secondary).

**Sizing/spacing/tokens:** card border `--s-border`, radius 6px, shadow `0 1px 2px rgba(0,0,0,.04)`; 2-column responsive grid for the 6 state cards in the mockup itself (a doc-layout choice, not part of the real single-instance component); icon badges are 20px circles, state-colored fg-on-bg; count numbers "use tabular figures so digits don't jitter as they climb" (explicit rule).

**Components used:** Pill (per-state status: pending/sent/failed/off variants), ProgressBar (all non-idle states), InlineStatus (running state only, in this mockup).

**Design-implied features to validate (NOT authoritative):**
- The claim "appears in wizard step 2 and in two Settings tabs" — the wizard's own step list (from the Setup Wizard doc) names step 2 "Consent & lawful basis," not backfill/import — this is an internal inconsistency in the design pack itself; **flag explicitly for consolidation to resolve against the real wizard step order** (repo has a single `wizard/index.phtml`; backfill markup was found embedded in `panel/subscribers.phtml`, i.e. a Settings surface, matching "two Settings tabs" but not obviously "step 2").
- Distinct "parked" vs "failed" vs "synced" three-way count breakdown in the done-with-failures state — validate the backfill counter model actually tracks these three buckets (STATUS.md/backlog suggests parked is a real concept elsewhere in the module; confirm it's produced for backfill specifically).
- "Resume import" continuing from the last committed record (both in the Stopped and Cancelled states) — validate resumability is implemented (vs. a full restart).
- "Start over" / "Discard & restart" as distinct, explicitly destructive actions separate from "Resume" — validate both actions exist.
- Estimated-time-remaining text ("roughly 4 minutes remaining") during Running — validate an ETA is actually computed/shown, or if this is a design placeholder for "some progress text."

---

## 3. Cross-screen consistency notes (visual only, not functional)

- The **ghosted Magento chrome bar** (`#514943` background, "Stores › Smaily › ‹Screen›" breadcrumb-style label, "Magento admin chrome — not redesigned" tag) appears identically atop every screen frame — purely a mockup-orientation device, not part of the shipped visual system.
- **Reactive region** visual language (dashed `#d6c3cb`-ish border, `#fbfbfb` background, uppercase accent-colored label naming the trigger condition) is reused identically in the Wizard's multilingual step and the Settings Subscribers tab — a single recognizable pattern for "this section appeared because you picked X," worth keeping as one CSS class if not already.
- **Tab-scoped / row-scoped save-with-InlineStatus** is the dominant save idiom everywhere a save exists (Wizard steps, Settings tabs, Engine Automations block, Backfill card) — no screen shows a single global "unsaved changes" bar; every save surface owns its own button + status.
- Minor internal inconsistencies noticed (not feature claims, just visual-spec seams to flag): (a) Tile value font-size is specified as `--fs-28` on the tokens/Components pages but rendered as a literal `30px` in the standalone `Tile.dc.html`; (b) the `off` Pill variant's neutral color triple (`#6b6b6b`/`#efefef`/`#cfcfcf`) doesn't exactly match the token sheet's `--s-neutral`/`--s-neutral-soft`/`--s-neutral-border` (`#5a5a5a`/`#eeeeee`/`#cccccc`); (c) ProgressBar's 4-state enum (`running/done/failed/stopped`) is overloaded by the Backfill screen to represent 6 conceptual states, as noted in §1.2.

---

## Appendix — full component/token quick reference

See §1 above for the complete tokens block and the six shared components
(InlineStatus, Pill, Banner, Tile, ChoiceCard, ProgressBar) with their anatomy,
states, and token bindings. All six are demonstrated live in
`Components.dc.html`; `Index.dc.html` is the pack's own table of contents
(links + one-line description + state list per screen, matching what's
extracted above).

---

RESULT: done
SUMMARY: All 6 screens + Index + Components + Design Tokens + the 6 individual component files read in full (14/14 .dc.html files, no missing/unreadable content). Wrote the layout/visual extract to docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md: per-screen layout trees, sizing/spacing/token bindings, component usage+states, and a clearly separated "Design-implied features to validate" list per screen (multilingual routing 3-option model, single/double opt-in + reactive confirmation-email picker, engine-automation trigger catalog specifics, backfill parked/failed/synced 3-way counts, log retry/attempt-history detail, etc.). Also flagged 3 internal design-pack inconsistencies (Tile font-size, off-pill color mismatch vs token sheet, ProgressBar 4-state enum overloaded to 6 Backfill states) and one direct contradiction (Backfill doc claims "wizard step 2" but the Wizard's own step list names step 2 "Consent & lawful basis").
ACCEPTANCE: All 6 screens covered (Dashboard, Setup Wizard, Settings, Engine Automations, Log, Backfill) plus the components/tokens appendix. No screen HTML was missing; zip unpacked cleanly. Settings tab detail is limited to the 3 tabs the pack actually shows (Connection/Subscribers/RSS) — Automations tab is covered separately in its own dedicated doc (2.4) per the pack's own structure, and Intelligence tab has NO visual detail anywhere in the pack (only named in the tab strip) — flagged as a gap, not fabricated.
LINEAR: Draft note for PRO-1369 (A1 done): "Layout/visual extract of the admin design pack committed to docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md. Covers all 6 screens + shared components/tokens with a clean split between authoritative layout/visual detail and non-authoritative design-implied features flagged for functionality validation (notably: 3-mode multilingual routing, single/double opt-in toggle, engine-automation trigger catalog specifics, backfill parked/failed/synced breakdown). One gap found: the pack has zero visual detail for the Settings 'Intelligence' tab (named only, never mocked up). One direct contradiction found: Backfill doc says it appears in 'wizard step 2,' but the Wizard doc's own step list names step 2 'Consent & lawful basis' — needs resolving against the real wizard. Ready for the parallel A2 (current-implementation) analysis to merge against."
FOLLOW-UPS: (1) Intelligence tab has no design coverage at all — consolidation needs to decide whether to design it fresh, defer, or leave current implementation untouched. (2) Resolve the Backfill "wizard step 2" placement contradiction against the actual wizard step order. (3) Every "Design-implied features to validate" list across the 6 sections needs a functionality-validation pass (likely the parallel A2 analysis or a dedicated follow-up) before any gets built — many are expected to be rejected per the task brief's own framing. (4) Minor visual-spec inconsistencies noted in §3 (Tile font-size, off-pill neutral color, ProgressBar state-enum overload) should be resolved to one canonical value each when the token/component CSS is finalized, not carried forward as ambiguity.
