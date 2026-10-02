# Admin style coverage — the design pack measured against the rendered admin (PRO-2456)

**Date:** 2026-10-02
**Compared:** the design pack `Magento Connect admin visual system.zip`
(exported 2026-07-12; git history, commit `db2fc53`) against the running
sandbox admin (Magento 2.4.8-p4), en_US, 1440 px viewport (page frame also at
1920 px), et_EE spot check for overflow only.
**Scope:** styles only — computed CSS values of components and of the page
frame. Copy, features and behaviour are out of scope; where a pack element
needs new copy or behaviour, it is listed as *missing* and marked so.
**Authority:** `docs/ADMIN_UI_TARGET_SPEC.md` §1 (the pack binds layout and
element visuals) and §5 (accepted micro-value differences). Companion:
`docs/audits/2026-10-02-ADMIN_DESIGN_PACK_FIDELITY.md` (the eye comparison).

## Summary

*Evening re-measure: 90.9 % (Open Sans accepted) — see [Re-measure (evening 2026-10-02)](#re-measure-evening-2026-10-02).*

**Overall: 77.9 %** — 2,070 of 2,656 compared properties match.
Strict reading (pack-internal conflicts resolved by a token counted as
*not* matching): 75.6 %. Unweighted mean of the per-component figures: 70.0 %
(strict 63.1 %). The overall figure is property-weighted, so components that
repeat many times (pills, trigger cards, the step rail) weigh more; the
largest single gap — the page frame — is a handful of properties with a very
large visual effect (see [Page frame](#page-frame)).

Classes: **match** = equal (colours within 2 per channel, lengths within
0.5 px); **accepted** = a difference listed in target spec §5 or this
morning's fidelity audit; **resolved** = the pack contradicts its own token
sheet or annotation and ours uses the token (counted as match, excluded in the
strict figure); **equivalent** = different value, identical pixels;
**differs** = pack value ≠ actual value; **missing** = the pack places the
element and ours renders nothing (all its properties count as unmatched).

### Per screen

| Screen | Compared | Match | Accepted / equiv. | Resolved | Differs | Missing | Coverage | Strict |
|---|---|---|---|---|---|---|---|---|
| Initial setup › 1 Connect | 225 | 185 | 5 | 5 | 15 | 15 | 87 % | 84 % |
| Initial setup › 2 Contacts | 253 | 185 | 4 | 9 | 21 | 34 | 78 % | 75 % |
| Initial setup › 3 Automations | 332 | 274 | 4 | 1 | 38 | 15 | 84 % | 84 % |
| Initial setup › 4 Intelligence | 163 | 125 | 5 | 4 | 14 | 15 | 82 % | 80 % |
| Initial setup › 5 Overview | 151 | 115 | 4 | 1 | 16 | 15 | 79 % | 79 % |
| Dashboard | 258 | 161 | 8 | 10 | 61 | 18 | 69 % | 66 % |
| Settings › Connection | 171 | 131 | 1 | 7 | 32 | 0 | 81 % | 77 % |
| Settings › Contacts | 178 | 115 | 0 | 14 | 36 | 13 | 72 % | 65 % |
| Settings › Automations | 587 | 487 | 16 | 2 | 82 | 0 | 86 % | 86 % |
| Settings › Intelligence | 101 | 55 | 1 | 3 | 32 | 10 | 58 % | 55 % |
| Settings › RSS | 132 | 80 | 0 | 2 | 46 | 4 | 62 % | 61 % |
| Log (grid page) | 31 | 17 | 1 | 3 | 0 | 10 | 68 % | 58 % |
| Log › Details | 74 | 28 | 0 | 2 | 30 | 14 | 41 % | 38 % |
| *Backfill / import blocks (cross-cut: Contacts tab, step 2, Intelligence imports)* | 112 | 55 | 0 | 12 | 19 | 26 | 60 % | 49 % |

### Per component

Adoption = instances where the pack places the component on the measured
screens and states, against instances where ours renders it (composite
components count their parts: rail rows, circles and sub-labels separately).

| Component | Compared | Match | Acc./equiv. | Resolved | Differs | Missing | Coverage | Strict | Adoption |
|---|---|---|---|---|---|---|---|---|---|
| ChoiceCard | 96 | 88 | 0 | 8 | 0 | 0 | 100 % | 92 % | 24 / 24 |
| Banner | 47 | 35 | 3 | 9 | 0 | 0 | 100 % | 81 % | 5 / 5 |
| Tile | 82 | 68 | 8 | 4 | 2 | 0 | 98 % | 93 % | 17 / 17 |
| Pill | 344 | 318 | 16 | 0 | 0 | 10 | 97 % | 97 % | 26 / 27 |
| Step rail | 538 | 465 | 20 | 3 | 0 | 50 | 91 % | 90 % | 57 / 82 |
| Trigger card | 308 | 276 | 0 | 0 | 32 | 0 | 90 % | 90 % | 78 / 78 |
| ProgressBar | 15 | 5 | 0 | 8 | 2 | 0 | 87 % | 33 % | 6 / 6 |
| Button primary | 108 | 93 | 0 | 0 | 15 | 0 | 86 % | 86 % | 12 / 12 |
| Field label + note | 72 | 60 | 0 | 0 | 8 | 4 | 83 % | 83 % | 18 / 19 |
| Verdict hero | 24 | 14 | 0 | 6 | 4 | 0 | 83 % | 58 % | 4 / 4 |
| Input / select | 195 | 150 | 2 | 0 | 43 | 0 | 78 % | 78 % | 29 / 29 |
| InlineStatus | 53 | 33 | 0 | 8 | 12 | 0 | 77 % | 62 % | 8 / 8 |
| Button ghost / tertiary | 40 | 29 | 0 | 0 | 11 | 0 | 72 % | 72 % | 5 / 5 |
| Grey content pane | 43 | 30 | 0 | 0 | 13 | 0 | 70 % | 70 % | 16 / 16 |
| Footer divider | 44 | 21 | 0 | 9 | 10 | 4 | 68 % | 48 % | 9 / 10 |
| Button secondary | 81 | 55 | 0 | 0 | 8 | 18 | 68 % | 68 % | 7 / 9 |
| Tab / step heading | 121 | 49 | 0 | 4 | 26 | 42 | 44 % | 40 % | 19 / 28 |
| Tab strip | 200 | 85 | 0 | 0 | 115 | 0 | 42 % | 42 % | 30 / 30 |
| Dashboard panels + quick links | 52 | 22 | 0 | 0 | 26 | 4 | 42 % | 42 % | 16 / 17 |
| Connection card | 44 | 18 | 0 | 0 | 26 | 0 | 41 % | 41 % | 13 / 13 |
| Backfill card | 56 | 20 | 0 | 2 | 8 | 26 | 39 % | 36 % | 8 / 12 |
| Card / panel | 50 | 18 | 0 | 0 | 32 | 0 | 36 % | 36 % | 11 / 11 |
| Log details panel | 43 | 6 | 0 | 2 | 30 | 5 | 19 % | 14 % | 6 / 7 |

Not in the pack, so not scored: the checkbox + label row (the pack has no
checkbox), the native Log grid chrome (the pack marks it "native
ui_component — as-is"), the admin header and menu.

**Tokens:** all `--s-*`, `--sp-*`, `--r-*`, `--fs-*`, `--shadow-*` and font
stacks in `view/adminhtml/web/css/smaily-admin.css` `:root` equal the pack's
token sheet. The gaps are in how screens apply them, not in the tokens.

## Page frame

The page reads as a foreign body mainly because of the frame, not the
components: the Smaily content is a narrow box that stops far short of the
content area every native page fills, it sits on a different background
treatment on each of the three pages, and its text uses a second font family
under Magento's own title.

### Measurements

Content area = `.page-content` inside its 30 px side padding (starts at
x = 118 after the 88 px menu).

| Page | 1440 px: content area → Smaily box → empty band right | 1920 px: same | Background behind the forms |
|---|---|---|---|
| Native Stores › Configuration | 1292 → form column 448–1410, actions bar 1292 → **0** | 1772 → column 568–1890, bar 1772 → **0** | white page; grey only in the actions bar (#f8f8f8) and the section nav (#f1f1f1) |
| Native Sales › Orders, Dashboard | 1292 → grid / actions bar 1292 → **0** | 1772 → 1772 → **0** | white |
| Smaily Log | 1292 → native grid 1292 → **0** | 1772 → 1772 → **0** | white (native) |
| Smaily Settings | 1292 → **760** → **532 px (41 %)** | 1772 → **760** → **1012 px (57 %)** | a 760 px #f4f4f4 box (24 px 28 px padding) under a transparent tab strip, on white |
| Smaily Initial setup | 1292 → **1100** → **192 px (15 %)** | 1772 → **1100** → **672 px (38 %)** | a 1100 px #f4f4f4 box with a 1 px #e3e3e3 border and 6 px radius, on white |
| Smaily Dashboard | 1292 → **1100** → **192 px (15 %)** | 1772 → **1100** → **672 px (38 %)** | white, no pane |
| Pack (all screens) | the grey #f4f4f4 pane and the white tab strip span the whole content area under the admin chrome; cards keep their own max-width (620–680 px) left-aligned inside it | same | full-bleed grey pane, white cards |

Source: `.smaily-ui { max-width: 76rem; }` (`smaily-admin.css:67`),
`.smaily-dashboard { max-width: 110rem; }` (`:123`), `.smaily-wizard
{ max-width: 110rem; }` (`:510`), the wizard box border/radius (`:511–514`),
the Settings pane (`:667`). The pack's grey is meant to be page-wide (it
fills the artboard's content area edge to edge on Settings, Setup, Dashboard,
Automations and Log); ours turns it into a floating card of fixed width.

### Page title and header

- Every Smaily page keeps Magento's native `.page-title`: Open Sans 28 px /
  400, `#41362f`, at the native position (y = 81) — identical to native pages.
  The pack does not redesign the admin header ("Magento admin chrome — not
  redesigned"); only its Dashboard artboard adds an in-content title row
  (22 px / 700 "Smaily Connect" + 13 px `#8a8a8a` subtitle) on the grey pane.
- Native pages put the page's primary action in the `.page-main-actions` bar
  (#f8f8f8, 1 px #e3e3e3 top and bottom, 15 px padding, 1292 px wide, button
  right). Smaily pages have no actions bar; actions sit in per-tab / per-step
  footers inside the content (the pack's pattern).
- Settings tab strip: ours starts at the content's left edge, 760 px wide,
  transparent, 1 px #e3e3e3 underline, 30 px gap (`margin: 0 0 3rem`) before
  the grey box; the pack's strip is a white bar across the full content width
  with a 24 px inset and the grey pane directly below it.

### Typography base

| | Font family | Size / line height | Colour | Smoothing |
|---|---|---|---|---|
| Magento admin body, title, menu, Log grid | Open Sans | 14 px / 19.04 px (1.36) | #41362f | auto |
| Smaily content (`.smaily-ui`) | -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto… | 14 px base, 13 px in components | #303030 where a component sets it, otherwise inherited #41362f | antialiased |
| Pack | the same system stack ("System fonts only") | 13 px body | #303030 | antialiased |

Measured: 0 of the text elements inside `.smaily-ui` on Settings (19),
Dashboard (33) and Initial setup step 1 (17) use Open Sans, so each Smaily
screen shows two families stacked — Open Sans for the title, menu, header and
footer, the system face for everything Smaily renders. Exceptions inside our
content: the Overview footer buttons are anchors and fall back to Open Sans
(`smaily-admin.css:113` covers `input, select, button` only); text without its
own colour inherits Magento's #41362f (connection-card labels, code blocks);
links use Magento's #007bdb, not `--s-link` #1979c3.

### Buttons

| | Font | Size / weight | Padding → height | Colour | Radius |
|---|---|---|---|---|---|
| Native primary (Save Config, Create New Order, Reload Data) | Open Sans | 16 px / 600, letter-spacing .4 px | 11 px 16 px → 46 px | #eb5202 fill and border | 0 |
| Native default (grid Filters etc.) | Open Sans | 13 px / 600 | 7 px 21px 6 px 17 px → 33 px | #514943 on #e3e3e3, border #adadad | 0 |
| Pack primary | system | 13 px / 700, line-height 1 | 9 px 18 px → 33 px | #eb5202 fill, #d44f00 border | 3 px |
| Pack secondary | system | 13 px / 700, line-height 1 | 9 px 16 px → 33 px | #303030 on white, border #adadad | 3 px |
| Ours primary / secondary | system | 13 px / 700, **line-height 17.68 px** | 9 px 18 px / 9 px 16 px → **37.7 px** | as pack | 3 px |

The pack keeps Magento's orange as the action colour ("primary CTA stays
native") but its own compact geometry and a white secondary instead of
Magento's grey default. Ours follows the pack except the line height
(`smaily-admin.css:688–696` do not reset Magento's 1.36).

### How to make it feel native

Ranked by visual effect.

1. **Let the frame fill the content area.** Drop the width caps on
   `.smaily-ui` (`smaily-admin.css:67`), `.smaily-dashboard` (`:123`) and
   `.smaily-wizard` (`:510`); keep the cards' own max-widths
   (`:646`, `:712`, `:782`) so forms stay readable and left-aligned. Removes
   the 532 px (1440) / 1012 px (1920) empty band on Settings and the 192 /
   672 px band on Setup and Dashboard; the pages then line up with the actions
   bar and grids of native pages. *Pack-compliant.*
2. **One background treatment on all three pages.** Either (a) the pack's
   full-bleed grey: tab strip as a white bar across the content width
   (`.smaily-settings .smaily-tabs` `:114`: background #fff, padding 0 24 px,
   no bottom margin so the pane joins it), the grey pane under it full width
   (`:667`), the Dashboard on the same pane (`.smaily-dashboard`: background
   `--s-bg`, padding 22 px 24 px 26 px), the wizard rail + pane without the
   bordered, rounded box (`:511–514`) — *pack-compliant*; or (b) native white
   pages with bordered white cards and no grey pane — *deliberate deviation*,
   closest to Stores › Configuration. Today each page does something different
   (box / bordered box / nothing).
3. **One font family per page.** Remove the system-stack override on our
   content (`.smaily-ui { font-family: var(--font); -webkit-font-smoothing:
   antialiased }`, `:112–113`) so Smaily content renders in Open Sans with
   Magento's smoothing, like the title, menu and the Log grid; keep the pack's
   sizes, weights and colours. *Deliberate deviation* from the pack's "system
   fonts only" — the pack's other stated goal is to "sit inside native Magento
   2 adminhtml without clashing", and the mixed families are the clash.
   If the system stack stays, at least route the Overview anchors through it
   (`:113` add `.smaily-ui a.action-primary, .smaily-ui a.action-secondary`).
4. **Button metrics.** Add `line-height: 1` (pack `font: 700 13px/1`) to
   `.smaily-ui .action-primary` / `.action-secondary` (`:688–696`): 37.7 →
   33 px, the height of Magento's own default buttons. *Pack-compliant.* If
   recommendation 3 is taken, weight 600 instead of 700 matches Magento's
   Open Sans buttons — *deliberate deviation*.
5. **Our own text and link colours.** `.smaily-ui { color: var(--s-text) }`
   and `.smaily-ui a { color: var(--s-link) }` stop Magento's brown and blue
   leaking into Smaily components unevenly (connection labels, code blocks,
   quick links, "View full log"). *Pack-compliant.*
6. **Keep the native page title; do not add the pack's in-content Dashboard
   title row** (22 px "Smaily Connect" + subtitle) — it would duplicate the
   28 px native title. *Deliberate deviation* (low risk: the pack itself treats
   the header as native chrome).

## Differing and missing items, by visibility

Visibility as in the fidelity audit: **high** = seen on first install
(initial setup, Dashboard, Settings › Connection) or on every page (frame);
**med** = a page opened regularly (other Settings tabs, Log); **low** = a
detail or a rare state. File is `view/adminhtml/web/css/smaily-admin.css`
unless named.

### High

| # | Screen(s) | Element · property | Pack | Actual | Source |
|---|---|---|---|---|---|
| H1 | Settings (all tabs) | content frame width | full content area: 1292 px @1440, 1772 px @1920 | 760 px | `.smaily-ui { max-width: 76rem }` :67 |
| H2 | Initial setup, Dashboard | content frame width | 1292 / 1772 px | 1100 px | `.smaily-wizard` :510, `.smaily-dashboard` :123 |
| H3 | Settings (all tabs) | tab strip · background / padding / gap / width | #fff, 0 24 px, 4 px, full width | transparent, 0, 0, 760 px | `.smaily-settings .smaily-tabs` :114 |
| H4 | Settings (all tabs) | tab · size / weight (inactive) / line height / padding | 13 px, 600, 13 px, 13 px 16 px 11 px | 14 px, 400, 19.04 px, 10 px 20 px | `.smaily-settings .smaily-tabs li` :115, :536 |
| H5 | Dashboard | grey pane · background / padding | #f4f4f4, 22 px 24 px 26 px | none (white), 0 | `.smaily-dashboard` :123 |
| H6 | Initial setup 1–5 | step kicker "Step N of 5" (11 px / 700 / uppercase / .6 px / #8a8a8a) | present | missing — needs EN + ET copy | `wizard/index.phtml` |
| H7 | Initial setup 1–5 | step heading · size / bottom margin | 22 px / 700 on the pane, 6 px | 16 px / 700 inside a card, 8 px | `.smaily-ui .smaily-card > h2` :672 |
| H8 | Initial setup 1, 3, 4, 5 | step intro · size / line height / bottom margin | 14 px, 1.5 (19.5 px), 22 px | 13 px, 17.68 px, 16 px | `.smaily-ui .smaily-card > p` :673 |
| H9 | Initial setup 1–5 | fields container | fields directly on the grey pane | white card: 1 px #e3e3e3, radius 4 px (6 px on step 2), padding 20 px (20 px 22 px on step 2) | `.smaily-ui .smaily-card` :68, :712 |
| H10 | Initial setup 1–4 | footer · gap / top margin | 12 px / 26 px | 20 px / 0 | `.smaily-ui .smaily-footer` :73, :499 |
| H11 | Initial setup (rail) | step sub-label (11 px, #8a8a8a; #bcbcbc upcoming) under each step name | present | missing — needs EN + ET copy | `wizard/index.phtml:66–77` |
| H12 | all screens | primary + secondary buttons · line height → height | 13 px → 33 px | 17.68 px → 37.7 px | `.smaily-ui .action-primary/.action-secondary` :688–696 |
| H13 | Initial setup 5 | Overview footer buttons (anchors) · font family | system stack | Open Sans | :113 excludes `a` |
| H14 | Initial setup 1, Settings › Connection, Contacts, step 2 | InlineStatus *saved* · colour | #1f7a34 | #006400 | `.smaily-ui .smaily-result.ok` :76 outranks `.smaily-inline-status.is-saved` :311 |
| H15 | all screens with a status | InlineStatus *error* · colour (rendered state, probe) | #bb2b0e | #e22626 | `.smaily-ui .smaily-result.err` :77 outranks :312 |
| H16 | Dashboard | verdict · radius / padding / bottom margin / shadow | 6 px, 18 px 20 px, 18 px, none | 4 px, 16 px 20 px, 20 px, `--shadow-1` | :124, :540 |
| H17 | Dashboard | verdict dot · ring | 0 0 0 4 px level colour @13 % | none | :541 |
| H18 | Dashboard (healthy) | verdict secondary CTA "Open event log" | present | missing — behaviour (fidelity audit #22) | `dashboard/index.phtml` |
| H19 | Dashboard | connection strip · gap / bottom margin | 12 px / 18 px | 20 px / 20 px | :129, :559 |
| H20 | Dashboard | connection card · padding / radius / shadow | 13 px 15 px, 5 px, none | 12 px 16 px, 4 px, `--shadow-1` | :130, :560 |
| H21 | Dashboard | connection card · layout gap / dot size | dot in its own column, 11 px gap, 14 px dot | stacked, 4 px gap, 12 px dot | :560, :563 |
| H22 | Dashboard | connection card label · size / weight / colour | 13 px / 600 / #303030 | 14 px / 700 / #41362f (inherited) | `<strong>`, no rule |
| H23 | Dashboard | tile grid · gap / bottom margin | 12 px / 18 px | 20 px / 20 px | :135, :570 |
| H24 | Dashboard | lower grid gap | 14 px | 20 px | :137 |
| H25 | Dashboard | activity + quick-links panels · padding / radius | 0 (rows carry padding), 5 px | 20 px, 4 px | `.smaily-ui .smaily-card` :68 |
| H26 | Dashboard | panel header · padding / divider | 12 px 16 px, 1 px #eee bottom | 0, none | :572–576 |
| H27 | Dashboard | Quick links header · size | 14 px | 16 px — `.smaily-ui .smaily-card > h2` (:672) wins over :576 (same specificity, later rule) | :672 vs :576 |
| H28 | Dashboard | quick-link row · padding / divider | 12 px 16 px, 1 px #f2f2f2 bottom | 12 px 0, 1 px #e3e3e3 top | :580 |
| H29 | Dashboard | quick-link arrow · colour / placement | #c9c9c9, right-aligned | link blue #007bdb, inline after the text | :577–578 |
| H30 | Dashboard, Overview, Settings | link colour | #1979c3 (`--s-link`) | #007bdb (Magento) | no `.smaily-ui a` rule |
| H31 | Dashboard | activity empty state | 38 px grey circle + 13 px / 600 #5a5a5a title + 12 px #8a8a8a body, centred | one 13 px #5a5a5a paragraph | `.smaily-ui .smaily-card > p` :673 (wins over `.smaily-empty` :142) |
| H32 | Settings › Connection | card outer width | 666 px (620 px content box + 22 px padding + border) | 620 px (border-box) | :646 |

### Medium

| # | Screen(s) | Element · property | Pack | Actual | Source |
|---|---|---|---|---|---|
| M1 | Settings › Contacts, RSS | card outer width | 666 px / 706 px | 680 px / 620 px | :712, :782 |
| M2 | Settings › Automations, Intelligence | card · radius / padding / width | 6 px, 20 px 22 px, 666 px | 4 px, 20 px, 704 px | :68 (the 6 px / 20 px 22 px overrides exist only for Connection, Contacts, RSS) |
| M3 | Settings › Automations, Intelligence; Initial setup 3, 4 | section heading | 19 px tab title above the card (ours: `--fs-18`, resolved) | 16 px `h2` inside the card, 8 px margin | :672 |
| M4 | Settings › Intelligence | tab title + description + tab footer | present on every Settings frame | missing — needs copy (fidelity audit #21) | `settings/index.phtml` |
| M5 | Settings › Automations, Initial setup 3 | trigger card · shadow / radius | none, 6 px | `--shadow-1`, 5 px | `.smaily-engine-trigger` :587 |
| M6 | Settings › Automations, Initial setup 3 | trigger card *active* · left bar | none (plain card) | 3 px #93c47d | `.smaily-engine-trigger.is-active` :590 |
| M7 | Settings › Automations, Initial setup 3 | trigger select · size / padding / caret | 13 px, 7 px 26 px 7 px 10 px, custom ▾ (appearance none) | 14 px, 7 px 10 px, native arrow | `.smaily-ui select` :680–687 |
| M8 | Settings › Automations, Initial setup 3 | trigger number/text input · size / padding | 13 px, 7 px 9 px | 14 px, 8 px 11 px | `.smaily-ui input` :680–686 |
| M9 | Settings › Automations, Initial setup 3 | "Refresh workflows" (Magento `.action-tertiary`) · size / colour / padding | ghost: 13 px / 600, #1979c3, 9 px 14 px | 14 px / 600, #007bdb, 6 px 14 px | no rule |
| M10 | Settings › RSS | builder labels · size / colour | 12 px / 600 / #5a5a5a | 13 px / 600 / #303030 | :788 |
| M11 | Settings › RSS | builder inputs / selects · size / padding | 13 px; 6 px 9 px / 6 px 28 px 6 px 9 px | 14 px; 8 px 11 px / 7 px 10 px | :789–790 |
| M12 | Settings › RSS | feed URL chip · size / border / padding | 12.5 px mono, 1 px #d6d6d6, 9 px 11 px | 13 px mono, 1 px #adadad, 8 px 11 px | :792–795 |
| M13 | Settings › RSS | "Feed URL" small label above the chip | present | missing — needs copy | `config/rss-builder.phtml` |
| M14 | Settings › Contacts, Initial setup 2 | backfill card · background / padding / shadow | #fff, 18 px 20 px, 0 1 px 2 px rgba(0,0,0,.04) | #fbfbfb, 20 px 22 px, none | :441, :712 |
| M15 | Settings › Contacts, Initial setup 2 | backfill title · size | 15 px | 14 px | :442 |
| M16 | Settings › Contacts, Initial setup 2 | backfill header status pill (non-idle states) | present | missing — JS state rendering (fidelity audit #17) | `panel/subscribers.phtml`, `panels-js.phtml` |
| M17 | Settings › Contacts, Initial setup 2 | "X of Y" (12 px / 700) + "%" (11 px mono #a3a3a3) line under the bar | present | missing — JS state rendering | same |
| M18 | Settings › Contacts, Initial setup 2 | Start import · padding | 8 px 15 px (backfill buttons) | 9 px 18 px | :688 |
| M19 | Log | status column | Pill per row | plain text | `ui_component/smaily_log_grid.xml:90` (`Magento_Ui/js/grid/columns/select`) |
| M20 | Log › Details | panel · width / shadow | 452 px right-docked, -2 px 0 14 px rgba(0,0,0,.16) | Magento slide modal 1292 px @1440, 0 0 12 px 2 px rgba(0,0,0,.35) | `web/js/grid/columns/log-actions.js:32` |
| M21 | Log › Details | payload code block · colours / font / radius / padding | #1f2329 bg, #e6e6e6 text, 12 px / 1.6 ui-monospace, 5 px, 12 px 14 px, no border | #f8f8f8, #41362f, 14 px / 1.36 monospace, 4 px, 12 px, 1 px #e3e3e3 | `.smaily-log-details-pre` :153 |
| M22 | Log › Details | response code block on error | #2a1f1e bg, #f2c9c2 text, 1 px #6b3a33 | #fff5f5, #41362f, 1 px #e22626 | `.smaily-log-details-error` :154 |
| M23 | Log › Details | section heads · size / weight / case / colour / margin | 11 px / 700 / uppercase .5 px / #8a8a8a / 10 px | 17 px / 600 / none / #41362f / 8 px | `.smaily-log-details h3` :152 |
| M24 | Log › Details | attempt-history timeline; footer "Retry now" + "Copy payload" | present | missing — data and behaviour (fidelity audit #19) | `log/details.phtml` |

### Low

| # | Screen(s) | Element · property | Pack | Actual | Source |
|---|---|---|---|---|---|
| L1 | all statuses | InlineStatus spinner outer size | 17 px (13 px + 2 px border, content-box) | 13 px (border-box) | :313–321 |
| L2 | Contacts, step 2, Intelligence imports | ProgressBar track outer height | 10 px (8 px + border) | 8 px (border-box) | :407–414 |
| L3 | Contacts, step 2 | backfill status · wrapping | nowrap, line height 1 | wraps, line height 1.4 (deliberate, long errors wrap beside the buttons) | :449 |

## Accepted and resolved differences (counted as match)

**Accepted (target spec §5 / fidelity audit 2026-10-02):** Tile value 30 px →
28 px (`--fs-28`, §5 a); the `off` Pill greys #6b6b6b / #efefef / #cfcfcf →
the `--s-neutral` trio (§5 b); Tile gap 6 px → 8 px and Banner gap 10 px →
12 px (fidelity audit, "the tokens win").

**Resolved — the pack contradicts its own token sheet or annotation, ours
uses the token:** Tile radius 4 px → `--r-3` and shadow alpha .04 →
`--shadow-1`; ChoiceCard selected tint #fdf2f6 → `--s-accent-soft`
(annotation names the token) and padding 14 px → 16 px (14 is off the 4 px
scale); InlineStatus gap 6 px → `--sp-2` (annotation) and spinner track
#d0d0d0 → `--s-border-2`; Banner outer border (bar colour at 33 % alpha) →
`--s-*-border`; ProgressBar track #ececec / #dcdcdc → `--s-surface-3` /
`--s-border-2`, fills #2b9e46 / #d72c0d / #8a8a8a → `--s-success` /
`--s-danger` / `--s-neutral` (annotation), running stripe #e91e63cc →
`--s-accent-hover`; footer and backfill dividers #e6e6e6 / #f0f0f0 →
`--s-border`; Settings tab title and verdict sentence 19 px → `--fs-18`;
verdict tint, border and bar literals → the status soft / border / bar tokens;
upcoming rail step #a3a3a3 / #d6d6d6 → `--s-text-3` / `--s-border-2`; retry
line #eef4fa and gap 10 px → `--s-info-soft`, `--sp-2`.

**Equivalent:** completed rail circles carry a 1 px accent border on the
accent fill (pack: no border; same pixels); the subdomain input squares its
right corners where it joins the suffix chip (pack leaves 2 px corners under
the chip).

## et_EE spot check

Every server-rendered English string on Settings (all tabs), Initial setup
(all steps) and the Dashboard was swapped for its `i18n/et_EE.csv`
translation in the browser and every element checked for horizontal overflow
and for children extending past their parent at 1440 px. No overflow and no
page-level horizontal scroll. (The check covers server-rendered text; strings
that the JavaScript composes at run time were not swapped.)

## Method

The pack was restored from `db2fc53`, served locally and every artboard
rendered; reference values are the artboards' computed styles (they equal the
inline style objects in the pack source). Where the pack shows a component in
several variants, the screen artboards win over the Components sheet (the
Components sheet's secondary button — #f4f4f4, 6 px 13 px, 600 — appears
nowhere else). On the sandbox, each screen was opened read-only and every
instance of each component measured with `getComputedStyle`; Settings tabs
and setup steps were switched with their own client-side controls. States the
sandbox was not in were rendered client-side with the shipped class names and
removed again: InlineStatus *working / saved / error*, the four ProgressBar
states, an upcoming rail step, the Log failed-24 h banner and the Log Details
panel inside Magento's slide modal. Not measured live: Log grid rows and
Details (the sandbox log is empty; the grid status column is read from
`smaily_log_grid.xml`), non-healthy Dashboard verdicts (the sandbox is
healthy; one verdict instance measured), multilingual routing cards (one
store language).

## Re-measure (evening 2026-10-02)

**Overall: 90.9 %** — 2,415 of the same 2,656 compared properties match
(strict 88.4 %). Basis: Magento's Open Sans counted as accepted, per the
owner's page frame decision (target spec "Page frame decisions"); without
that, 90.4 % (strict 87.9 %).

| | Morning (above) | After the page frame (PRO-2456) | Evening |
|---|---|---|---|
| Overall, font accepted | 78.0 % | 85.0 % | **90.9 %** |
| Overall, font not accepted | 77.9 % | 77.0 % | 90.4 % |
| Strict, font accepted | 75.6 % | 82.5 % | 88.4 % |
| Missing properties (pack element not rendered) | 163 | 163 | 63 |

### Method

The same probe as the morning: the same 2,656 rows (screen, element,
property, pack value), the same comparison rules and tolerances, the same
accepted, resolved and equivalent classes. Each row was evaluated again
against the element the screen walkers resolve today. The walkers changed
only where the markup moved since the morning:

- An element that was missing in the morning and exists now is measured
  against the row's pack values: the "Step N of 5" kicker, the step title
  above the step content, the import card's header pill and "X of Y" line,
  the Log grid's status pill, the Details panel's attempt history and its
  footer button (Send again, measured as the pack's Retry now).
- The step 1 card is found inside its wrapper; the step intro is the first
  paragraph in the step card; the Dashboard connection label is
  `.smaily-connection-label`; the Intelligence tab title stands for the
  morning's in-card heading; the code blocks and section heads are matched
  by role (payload, response), not by position.
- A finished import shows the pack's secondary **Run again** (Backfill
  frames). That button, and the three Intelligence import cards' buttons
  that replace the morning's three secondary "Import …" buttons, are
  measured against the pack's backfill secondary button (8 px 15 px). The
  import card's outcome line is measured as the pack's body line in its
  done colour (#1f7a34) and as the morning's backfill status row.

The sandbox store had not finished its initial setup, so every page except
step 1 of the initial setup redirected there. Every screen was therefore
drawn in the browser: the live admin page (Magento's head, chrome and CSS)
with its content replaced by the real templates rendered with stub data
mirroring the morning state — setup finished, Smaily verified, the three
store triggers active, Campaign Intelligence connected with four engine
triggers off, the imports finished, the Dashboard healthy with no activity,
the Log with a failed row and its Details panel. Every request other than a
page or asset load was refused, and every `fetch()` answered locally. The
Details row is a failed row with its attempts used up (so Send again is
offered), where the morning drew a failed row with a retry scheduled; this
moves the retry line from the info colours to the neutral ones (two rows).

### Per screen (coverage, font accepted)

| Screen | Morning | Frame | Evening | Compared | Match | Acc./equiv. | Resolved | Differs | Missing | Strict |
|---|---|---|---|---|---|---|---|---|---|---|
| Initial setup › 1 Connect | 87 % | 88 % | **91 %** | 225 | 172 | 28 | 5 | 10 | 10 | 89 % |
| Initial setup › 2 Contacts | 78 % | 80 % | **91 %** | 253 | 198 | 22 | 9 | 14 | 10 | 87 % |
| Initial setup › 3 Automations | 84 % | 85 % | **87 %** | 332 | 254 | 33 | 1 | 34 | 10 | 86 % |
| Initial setup › 4 Intelligence | 82 % | 84 % | **88 %** | 163 | 120 | 20 | 4 | 9 | 10 | 86 % |
| Initial setup › 5 Overview | 81 % | 83 % | **87 %** | 151 | 112 | 18 | 1 | 10 | 10 | 86 % |
| Dashboard | 69 % | 81 % | **94 %** | 258 | 209 | 20 | 14 | 6 | 9 | 89 % |
| Settings › Connection | 81 % | 98 % | **98 %** | 171 | 142 | 19 | 7 | 3 | 0 | 94 % |
| Settings › Contacts | 72 % | 88 % | **97 %** | 178 | 148 | 10 | 14 | 6 | 0 | 89 % |
| Settings › Automations | 86 % | 91 % | **91 %** | 587 | 460 | 70 | 2 | 55 | 0 | 90 % |
| Settings › Intelligence | 58 % | 86 % | **95 %** | 101 | 83 | 10 | 3 | 5 | 0 | 92 % |
| Settings › RSS | 62 % | 82 % | **82 %** | 132 | 91 | 15 | 2 | 20 | 4 | 80 % |
| Log (grid page) | 68 % | 68 % | **100 %** | 31 | 23 | 5 | 3 | 0 | 0 | 90 % |
| Log › Details | 41 % | 41 % | **92 %** | 74 | 62 | 5 | 1 | 6 | 0 | 91 % |
| *Backfill / import blocks (cross-cut)* | 60 % | 64 % | **93 %** | 112 | 85 | 7 | 12 | 8 | 0 | 82 % |

### Per component (coverage, font accepted)

| Component | Morning | Frame | Evening | Strict | Adoption morning → evening |
|---|---|---|---|---|---|
| Tab strip | 42 % | 100 % | 100 % | 100 % | 30 / 30 → 30 / 30 |
| Pill | 97 % | 97 % | 100 % | 100 % | 26 / 27 → 27 / 27 |
| Button primary | 87 % | 98 % | 100 % | 100 % | 12 / 12 → 12 / 12 |
| ChoiceCard | 100 % | 100 % | 100 % | 92 % | 24 / 24 → 24 / 24 |
| Backfill card | 39 % | 39 % | 100 % | 96 % | 8 / 12 → 12 / 12 |
| Banner | 100 % | 100 % | 100 % | 81 % | 5 / 5 → 5 / 5 |
| Connection card | 41 % | 52 % | 100 % | 100 % | 13 / 13 → 13 / 13 |
| Tile | 98 % | 100 % | 100 % | 95 % | 17 / 17 → 17 / 17 |
| Button secondary | 69 % | 78 % | 99 % | 99 % | 7 / 9 → 9 / 9 |
| Verdict hero | 83 % | 88 % | 96 % | 71 % | 4 / 4 → 4 / 4 |
| Step rail | 91 % | 91 % | 91 % | 90 % | 57 / 82 → 57 / 82 |
| Trigger card | 90 % | 90 % | 90 % | 90 % | 78 / 78 → 78 / 78 |
| Grey content pane | 70 % | 88 % | 88 % | 88 % | 16 / 16 → 16 / 16 |
| Log details panel | 19 % | 19 % | 88 % | 86 % | 6 / 7 → 7 / 7 |
| ProgressBar | 87 % | 87 % | 87 % | 33 % | 6 / 6 → 6 / 6 |
| InlineStatus | 77 % | 89 % | 85 % | 70 % | 8 / 8 → 8 / 8 |
| Field label + note | 83 % | 83 % | 83 % | 83 % | 18 / 19 → 18 / 19 |
| Dashboard panels + quick links | 42 % | 77 % | 83 % | 75 % | 16 / 17 → 16 / 17 |
| Footer divider | 68 % | 80 % | 82 % | 61 % | 9 / 10 → 10 / 10 |
| Button ghost / tertiary | 72 % | 80 % | 80 % | 80 % | 5 / 5 → 5 / 5 |
| Input / select | 78 % | 78 % | 78 % | 78 % | 29 / 29 → 29 / 29 |
| Tab / step heading | 44 % | 44 % | 77 % | 74 % | 19 / 28 → 27 / 28 |
| Card / panel | 36 % | 42 % | 42 % | 42 % | 11 / 11 → 11 / 11 |

InlineStatus drops from 89 % (frame) to 85 %: the import card's outcome line
now stands where the morning measured a separate inline status, and it is
the pack's 13 px / 400 body line, not a 600-weight status (three rows).

### Remaining gaps, by size

1. **Initial setup — step content on a card, not on the pane** (H9; 5 steps,
   about 20 rows): the pack puts fields directly on the grey pane; ours keeps
   a white card (background, border, 20 px padding, 4 px radius). With it:
   the 13 px intro (pack 14 px / 1.5, 22 px below; H8), the step title's
   0 px bottom margin (pack 6 px) and the footer's 0 px top margin (pack
   26 px; H10).
2. **Initial setup — the rail's sub-labels** (H11; 50 rows, all missing):
   the pack's 11 px muted line under each step name needs EN + ET copy.
3. **Trigger cards** (M5–M8; 32 rows on step 3 and Settings › Automations):
   5 px radius and `--shadow-1` (pack 6 px, flat), the active card's 3 px
   green left bar, 14 px controls with the native select arrow (pack 13 px,
   7 px 26 px 7 px 10 px with a custom caret).
4. **Settings › RSS builder** (M10–M13; 20 rows): 13 px / #303030 labels
   (pack 12 px / #5a5a5a), 14 px controls with 8 px 11 px padding (pack
   13 px, 6 px 9 px), the feed URL chip's border and size, and the pack's
   small "Feed URL" label (needs copy).
5. **Card measures and corners** (H32, M1, M2): Settings cards are 620 /
   680 px border-box (pack 666 / 706 px outer); Automations and Intelligence
   cards keep 4 px radius and 20 px padding (pack 6 px, 20 px 22 px); tab
   footers follow their card's width (620 / 666 / 680 px) where the probe
   reads the pack's 620 px.
6. **"Refresh workflows"** (M9): Magento's tertiary button (14 px, #007bdb,
   6 px 14 px) instead of the pack's ghost (13 px, #1979c3, 9 px 14 px).
7. Small ones: the spinner (13 px outer, pack 17 px; L1), the progress track
   (8 px, pack 10 px; L2), the verdict dot ring (the bar green at 13 %, pack
   #2b9e46 at 13 %), the Dashboard's in-content title row (deliberate, not
   added), the wizard frame radius (the page frame decision removed the box).

### Walk-through fixes the same evening

Committed separately (PRO-2456), checked in en_US and et_EE at 1440, 1100
and 400 px: the initial setup draws step 1 open server-side (no jump on
load); the Campaign Intelligence automations' Daily cap and Test emails
fields fit their placeholders, that card keeps 24 px below Save Automations
and its heading matches the store-events card; a multilingual Connection tab
keeps one card width; radios use the accent colour; the Dashboard's
recent-activity table no longer scrolls sideways at 1100 px and the Failed
tile's badge stays in the tile; the Log Details header is no longer italic,
14 px and indented by Magento's `.modal-title span` rule (this last fix is
in the numbers above: the header pill's size and font style).
