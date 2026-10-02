# Admin UI target spec (PRO-1369 — consolidation of A1 + A2)

**Status:** authoritative reconciliation. Supersedes the design pack
(`Magento Connect admin visual system.zip`, exported 2026-07-12) and the two
standalone analyses below as the thing Phase B builds against. The pack is
not kept in the repository (it stays in git history, commit `db2fc53`); the
latest fidelity check against it is
`docs/audits/2026-10-02-ADMIN_DESIGN_PACK_FIDELITY.md`.

**Inputs:**
- `docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md` (A1) — design pack's
  layout + element visuals per screen.
- `docs/audits/2026-07-14-ADMIN_FUNCTIONALITY_TEXT_MAP.md` (A2) — our real
  functionality + sibling (Woo/Shopify) options and canonical EST+ENG texts.
- PRO-1357 findings (the visual/functional audit that triggered this work).

---

## 1. Purpose + authority model

Two independent, read-only analyses were run in parallel because the design
pack cannot be trusted for anything except pixels: it invents options and
copy that don't exist anywhere in this module or its siblings. This document
merges them into one spec so Phase B has a single target to verify the
running admin against, instead of two documents that might disagree.

**Binding authority — unchanged from the task that produced A1/A2:**

- **Design pack → layout and element visuals ONLY.** Structure, hierarchy,
  spacing, component anatomy/states, design tokens. Never its wording, never
  a feature it merely implies.
- **Functionality, decisions, texts → our real functionality + the sibling
  plugins (Woo `../connect`, Shopify `../shopify-connect`).** Sibling wording
  wins on conflict; where a control exists only in this module, our own
  already-shipped, already-bilingual (`i18n/en_US.csv`/`et_EE.csv`) wording is
  canonical.
- **Design-pack leaks get REMOVED, not restyled.** Two confirmed by A2:
  (1) the "single/double opt-in mode" selector + its reactive
  confirmation-email picker (Subscribers tab); (2) the RSS builder's
  "Store view" dropdown. (A2 reclassified the RSS "named category/collection
  picker" as a real feature both siblings have that we're simply missing —
  it is a **follow-up feature candidate**, not part of this spec's build
  scope, and not a leak.)

**How to use this document (Phase B):**

1. For each screen below, open the real running page in the sandbox
   (`docker compose up -d`, `localhost:8080/admin`) in both `en_US` and
   `et_EE`.
2. Compare the rendered layout against **(a) target layout** — fix visual
   drift.
3. Compare the rendered controls against **(b) exposed options/controls** —
   every control listed must exist and work; nothing else should be there.
4. Compare rendered copy against **(c) EST+ENG text** — fix wording drift;
   where a "harmonize" note is present, that is a deliberate, tracked
   wording change (not urgent, but in scope if you're already touching that
   control).
5. Confirm nothing in **(d) REMOVE** is present. If it is, delete it — do not
   restyle it.
6. Cross-check against **§3 (findings map)** — each of the ten PRO-1357
   findings has an explicit fix location; use it to confirm nothing was
   dropped.
7. **§4 is now RESOLVED** (Erkki, 2026-07-14) — implement its four decisions
   and their per-screen consequences (folded into 2.1, 2.3.B/C/D/E above) as
   part of Phase B, same as any other target-spec item. The exception is the
   handful of fields §4.2 explicitly flags for Erkki (the three native-only
   orphan fields, `multilingual_mode`'s website scope, `rss/enabled`'s
   store-view scope) — leave those as currently shipped until Erkki rules on
   them specifically.

### Page frame decisions (2026-10-02)

Erkki decided two things about the frame around every Smaily page (Settings,
Initial setup, Dashboard; the Log page is a native grid and has no Smaily
frame). They bind over the pack where the two differ.

1. **The pack's background.** A grey pane (`--s-bg`) spans the whole
   content area, the Settings tab strip is a white bar across the same
   width, and white cards sit on the grey. The frame has no width cap;
   cards, fields and inputs keep their own max-widths, so forms stay
   readable and left-aligned. Pack-compliant.
2. **Magento's Open Sans, not the pack's system stack.** Our content uses
   the font family of the admin around it, so a page shows one family. The
   pack's sizes, weights and colours stay. `--font` names Magento's stack.
   This is a deliberate deviation from the pack's "system fonts only".
   A style audit counts a font-family difference against the pack as
   accepted under this decision.

---

## 2. Per-screen target spec

### 2.1 Dashboard

**(a) Target layout** (source: A1 §2.1, `Dashboard.dc.html`):

1. Page title row: H3 "Smaily Connect" + subtitle "Newsletter & automation
   sync".
2. Optional top **Banner** (full width, only in non-healthy states).
3. **Verdict hero** — one card: status dot + kicker label + verdict sentence
   + optional trailing CTA button, 5px left bar in level color, soft tint
   background.
4. **Connection strip** — 3-column grid, one card per connection (Smaily /
   Campaign Intelligence / Browse tracking): dot + label + sub-line + a
   **Pill** (automation-mode family: active/off/failed).
5. **Metric tiles** — 4-column grid, `.smaily-tile` component (default +
   `--attention` variant), reflows 4→2→1.
6. Lower two-column area (`1.7fr 1fr`): "Recent activity" panel (title +
   "View full log →" link, then Pill + truncated text + relative time per
   row, or an empty state) + "Quick links" panel (label + "→" rows).

This layout is already implemented per STATUS.md's PRO-1281 Stage B entry —
this section exists as the acceptance baseline for Phase B's visual
verification pass, not a new build.

**(b) Exposed options/controls** (this is a read-only operational page, no
form fields — source: A2 §F, `ViewModel\Adminhtml\DashboardData`):

| Element | Real functionality |
|---|---|
| Verdict hero | priority-ordered from setup completeness, `isSmailyConnected()` (Smaily accepted the saved credentials, PRO-3560), `getFailedLast24h()`, `isEngineDown()` |
| Connection strip | live pills from `isSmailyConnected`, `isEngineConnected`/`isEngineDown`, `isBrowseTrackingEnabled` |
| Metric tiles | Contact syncs delivered 30d, Catalog items delivered 30d (only if engine connected), Queued today, Failed 24h — real local queue queries |
| Recent activity | last 10 queue rows: Source/Type/Entity/Status/Updated |
| Quick links | Settings, Log, Setup Wizard, Stores > Configuration — **the last link is a consequence of §4's now-resolved decision 3 (native config shrinks to advanced-only or disappears) and should be revisited once that lands**, not removed by this doc pass alone |

The design pack's specific dependency wording ("Intelligence/Browse tracking
→ Off with reason 'Requires Smaily connection'" when Smaily is disconnected)
and the exact metric-tile name set are **design-implied, not validated as
verbatim spec** — A2 confirms the tile set and connection-strip shape exist,
but the pack's literal state-by-state copy (e.g. "Not connected", specific
banner sentences) is not sourced from our shipped strings; use the (c) table
below, not the pack's prose, for copy.

**(c) EST+ENG text** (source: our own `i18n/en_US.csv`/`et_EE.csv`, real +
bilingual):

| EN | ET |
|---|---|
| Recent activity | Viimane tegevus |
| Quick links | Kiirlingid |
| Settings / Log / Setup Wizard | Seaded / Logi / Seadistusviisard |
| Source / Type / Entity / Status | Allikas / Tüüp / Kirje / Olek |
| "Setup is not finished yet — complete the setup wizard to start syncing." | (shipped) |
| "Smaily Connect is running, but %1 delivery(ies) failed in the last 24 hours." | (shipped) |
| "Everything is running — deliveries to Smaily are flowing normally." | (shipped) |

**Wording-harmonization candidates (non-blocking, sibling wins per binding
rule — apply in a future copy pass, not urgent):** Shopify is the only
sibling with a real ongoing dashboard.

| Control | Current (ours) | Target (sibling wins) |
|---|---|---|
| Verdict — failures state | "Needs attention" | "Running — with failures to review" (Shopify) |
| Verdict — all-clear state | "All systems normal" | "Everything's running" (Shopify) |

**(d) REMOVE — design-pack leak:** none. Dashboard is read-only; nothing in
the pack invents a setting here.

---

### 2.2 Setup Wizard

**(a) Target layout** (source: A1 §2.2, `Setup Wizard.dc.html`):

- Two-column shell: left stepper rail (fixed width, `smaily-stepper`-style
  circular index markers — done=accent-filled+check, active=accent-ring,
  upcoming=gray) + right content pane (kicker "Step N of 5" → H3 title →
  intro paragraph → step body → footer with Back (ghost) left, primary/
  secondary buttons right-aligned).
- Saving state: fields collapse to a compact card; button disables (never
  swaps its own label) while an **InlineStatus** (`working`) plays beside it,
  `nowrap`, layout never reflows.
- Error state: top error **Banner** + field-level danger styling and message
  on the offending field — the two-layer error model (banner for the
  transaction, field styling for the specific input) is a real, already
  described pattern (Phase-1 fix, STATUS.md "UI/UX parity phase 1").
- Completed-revisit state: kicker carries a Pill(`active`, "Completed"); body
  becomes a read-only summary card; footer gets "Edit credentials" +
  InlineStatus(`saved`). Rail: **completed steps stay unlocked/clickable
  forward, no re-lock on Back** — this is a real, already-shipped behaviour
  (STATUS.md PRO-1270 "B1").
- Multilingual routing step: intro sentence templated with the store's
  detected language count; a stack of **Radio Choice-Card** instances (18px
  circular indicator, title + optional "Recommended"/"Most common" badge +
  description, selected = accent border + tint + ring); a **live-reactive
  region** (dashed accent-tinted border) appears only for modes that need
  per-language credential blocks, each block = language chip + Subdomain/
  Username fields + InlineStatus.

**(b) Exposed options/controls** (source: A2 §I, confirmed —
`wizard/index.phtml`):

The wizard is 5 steps (**Connect → Subscribers → Automations → Intelligence
→ Done**), each step reusing the exact same partials as the matching
Settings tab (2.3 below) — **not** the design pack's 5-step naming
("Consent & lawful basis", "Multilingual routing", "Review & finish"), which
does not match the real step set. Wizard-only chrome: stepper with done/
active states, Back/Continue/Finish footer, and a step-5 "Done" summary card
with links to Settings/Log/user guide.

Backfill (contacts) appears embedded in the Subscribers step, not as its own
wizard step — see 2.7.

**(c) EST+ENG text** (source: our own i18n, real + bilingual):

| EN | ET |
|---|---|
| Connect / Subscribers / Automations / Intelligence | Ühendus / Tellijad / Automaatikad / Intelligence (brand term, intentionally untranslated) |
| Done (step label) | **[i18n gap — see §5]** |
| You are all set! | Kõik on valmis! |
| Back | (shipped) |
| Continue / Finish | (shipped) |

Step-body controls (Connection fields, sync-mode radios, automation
mappings, Intelligence setup) reuse the Connection/Subscribers/Automations/
Intelligence text tables in 2.3 verbatim — the wizard has no separate copy
for these.

**(d) REMOVE — design-pack leak:**

- The multilingual step's design-pack framing ("3-option model: one account
  all languages / one account per-language automations / separate account
  per language" as if that were the full and only model) — **do not build
  a 3-option chooser.** The real model has **4 modes** (Single language /
  Per-language Smaily accounts / One account, per-language workflows / One
  workflow branching by language) — see Connection (2.3.A) for the real
  set and wording.
- The design pack's "switching away from a per-language mode triggers a
  destructive-switch confirm" is **not a leak** — it's real, already
  shipped (STATUS.md PRO-1273: "the destructive confirm appears when
  leaving a saved a/b mode... baseline tracks the SAVED mode").
- The Subscribers-step opt-in-mode selector — see 2.3.B REMOVE.

---

### 2.3 Settings

**(a) Target layout — common shell** (source: A1 §2.3):

- Tab strip (horizontal, top of content): active tab = 2px accent
  bottom-border + 700 weight; inactive = 600 weight, transparent border.
  Scrolls horizontally on narrow widths / long Estonian labels, never wraps.
- **5 tabs, in this order: Connection, Subscribers, Automations,
  Intelligence, RSS** — the pack's tab set and this order line up 1:1 with
  the real panel files (`panel/{connection,subscribers,automations,
  intelligence,rss}.phtml`).
- Below the strip: H3 tab title + one-line description, a white content card
  (border, radius 6px, padding), then a **tab-scoped footer**: action
  button(s) + one InlineStatus per tab. **No global save bar — every tab
  owns its own save + status**, already the shipped idiom (per-tab AJAX
  save, STATUS.md "UI/UX parity phase 2a").
- **Target change (§4, decision 2, not yet implemented):** the PRO-1274
  "Overridden for X" banner + manual "Use Default" button, currently shown
  next to shadowed fields, is removed from this common shell. Save
  auto-clears any shadowing website/store-view override instead of
  surfacing it — scope becomes invisible on these pages by design ("what you
  see is what runs"). The `OverrideDetector`/`OverrideClearer` machinery is
  reused, just triggered automatically rather than manually. See §4.2 for
  which fields (if any) keep a scope switcher elsewhere.

#### 2.3.A Connection tab

**(a) Layout:** 3-field form (Subdomain + fixed `.sendsmaily.net` suffix
chip / API Username / API Password + hint), max-width 440px inside the
620px card; status line below ("Status:" + Pill(`active`,"Connected") + "as
'<account name>'"); when the store has >1 detected language, the
multilingual routing-mode choice cards render above/around the form (see
2.2 layout). Footer: Test Connection (secondary) | Save Connection
(primary) | InlineStatus.

**(b) Exposed options/controls** (source: A2 §A, confirmed):

| Control | Real functionality |
|---|---|
| Subdomain | normalizes a pasted full URL to bare subdomain |
| API Username / API Password | plain text / encrypted |
| Test Connection | AJAX, tests as-typed credentials, no save |
| Multilingual routing mode (4 cards, shown only when store views span >1 detected language) | drives which section renders below; saved as `multilingual_mode` |
| Per-language account blocks (subdomain/username/password/Test per language) | mode "Per-language Smaily accounts" only |
| Default fallback account picker | same mode only — which account's credentials serve unmatched scope + become the default-scope credentials |

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Subdomain | Alamdomeen |
| API Username | API kasutajanimi |
| API Password | API parool |
| Test Connection | Testi ühendust |
| Single language | Üks keel |
| Per-language Smaily accounts | Keelepõhised Smaily kontod |
| One account, per-language workflows | Üks konto, keelepõhised töövood |
| One workflow branching by language | Üks töövoog, mis hargneb keele järgi |
| Most common (badge) | Levinuim |
| "Connect your Smaily account" | "Ühenda oma Smaily konto" |

**Wording-harmonization candidates (non-blocking, sibling wins — both Woo
and Shopify converge word-for-word on all 4 mode names):**

| Mode | Current (ours) | Target (sibling wins, both identical) |
|---|---|---|
| A | Per-language Smaily accounts | Separate Smaily accounts |
| B | One account, per-language workflows | One account, per-language automations |
| C | One workflow branching by language | One account, one automation with branches |

**(d) REMOVE:** none specific to Connection.

#### 2.3.B Subscribers tab

**(a) Layout:** design pack shows one field ("Opt-in mode" select) plus a
reactive confirmation-email picker — **this entire control is a leak, see
(d).** The real layout target is the wizard-step-2 shape reused here: a
sync-mode radio-card group (Radio Choice-Card component per A1 §1.2) + an
"extra fields to sync" checkbox set + the backfill card (2.7) + the two
toggle checkboxes (checkout newsletter checkbox, suppress-Magento-opt-in-
emails). Footer: Save Subscribers | InlineStatus.

**(b) Exposed options/controls** (source: A2 §B, confirmed —
`panel/subscribers.phtml`):

| Control | Where | Real functionality |
|---|---|---|
| Enable subscriber synchronization | Settings tab only | master toggle, `sync_enabled` |
| Contact sync mode (3 lawful-basis radio cards) | wizard step 2 / Settings | consent / legitimate_interest / checkout_optin |
| Extra fields to sync (8 checkboxes) | same | `SyncFields`, empty values omitted |
| Show newsletter checkbox at checkout | wizard/Settings + native config | `Controller/Checkout/Optin.php` |
| Let Smaily send opt-in emails (suppress Magento's own) | wizard/Settings + native config | `Plugin/SuppressNewsletterEmails.php` — suppresses success/unsubscribe mail only, never the double-opt-in confirmation request |
| Import your existing subscribers (backfill) | wizard step 2 / Settings > Subscribers | see 2.7 |

**Resolved (Erkki, 2026-07-14, §4.2):** Include Guest Order Emails
(`include_guests`) and Automations May Re-Subscribe (Advanced)
(`automation_force_opt_in`) — currently native-config-only, with
`WizardStepSaver::saveSubscribers()` already carrying dead `saveFlag()` code
ready to accept them — are real features and get real controls added to
this tab. Native config for both is removed once built.
**Superseded for `automation_force_opt_in` (2026-10-02):** the setting is
retired — an automation never re-subscribes a contact who unsubscribed in
Smaily, in any mode. The control, the `system.xml` field and the config
path's reader are gone; the update deletes a stored row at every scope.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Who should be synced to Smaily? | Keda peaks Smailysse sünkroonima? |
| Enable subscriber synchronization | Luba tellijate sünkroonimine |
| Subscribers only (consent) | Ainult tellijad (nõusolek) |
| All customers (legitimate interest) | Kõik kliendid (õigustatud huvi) |
| Checkout opt-in only | Ainult kassas antud nõusolek |
| Recommended (badge) | Soovitatud |
| Extra fields to sync with each contact | Lisaväljad, mis sünkroonitakse iga kontaktiga |
| Show a newsletter checkbox at checkout | Näita kassas uudiskirja märkeruutu |
| Let Smaily send the opt-in confirmation emails (suppresses Magento's own) | Lase Smailyl saata tellimuse kinnituskirjad (Magento enda kirjad jäetakse ära) |

The three lawful-basis labels are **verbatim-identical across Woo, Shopify
and us already** — strongest positive confirmation in the whole audit, no
harmonization needed.

**(d) REMOVE — design-pack leak:** the "Opt-in mode: Double opt-in
(confirmation email) / Single opt-in" select and its reactive "Confirmation
email (Smaily automation)" picker. Confirmed absent from our code and from
BOTH siblings (A2, design-pack-leaks §1) — Magento's own double-opt-in
setting lives natively under *Stores > Configuration > Customers >
Newsletter*, is untouched by this module, and there is no
"confirmation-email-as-Smaily-automation" concept anywhere in the trigger
catalog. **Do not build. Delete if any trace exists in current templates.**

#### 2.3.C Automations tab

**(a) Layout** (source: A1 §2.4, `Engine Automations.dc.html` — applies to
the engine-automations sub-block; the store-event trigger block above it
follows the same section-header + card-list idiom):

- Section header: title + description on the left, "How triggers work →"
  link on the right (if this link is real/planned — see (d) validation
  note below; not confirmed as an existing feature, do not fabricate the
  target if it doesn't exist).
- **Trigger list as a vertical stack of cards**, not a dense checkbox+
  dropdown table (this is the direct fix for PRO-1357 finding #9). Each
  card: trigger name + a **Pill** (run-mode: active/test/off) + muted
  description, right-aligned control row (Workflow select, disabled when
  Off; per-trigger-appropriate extra fields — see (b)).
- Validation-error state: top error Banner with a count ("N automations
  couldn't be saved..."), field-level danger styling + message on the
  specific offending field, valid rows unaffected.
- Not-connected fallback: single centered empty-state card, "no dead
  controls."
- Catalog-load-failed fallback: warning Banner + saved cards rendered
  dimmed/non-interactive, "existing config is never wiped."
- Footer: one "Save automations" button (whole block, not per-row) +
  InlineStatus.

This card-list-with-pills shape (vs. the cramped checkbox+dropdown list
PRO-1357 flagged) is the layout target; the **field set inside each card
must be our real fields**, not the design pack's illustrative sample set —
see (b).

**(b) Exposed options/controls** (source: A2 §C, confirmed — two
sub-systems on this tab):

*Store-event automations* (welcome / first order / abandoned cart):

| Control | Real functionality |
|---|---|
| Welcome | enable toggle + workflow select |
| First order | enable toggle + workflow select |
| Abandoned cart | enable toggle + workflow select + cutoff minutes (10–1440, default 30) |
| Per-language workflow mapping table (modes A/B only) | language / workflow select / default-fallback radio, per trigger |

**Resolved (Erkki, 2026-07-14, §4.2) — retired in PRO-1760:** Abandoned Cart
Product Fields (7 checkboxes, `automations/abandoned_fields`) was resolved to
get a real control on this tab, and one was built. It is gone again: product
details always send, so there is nothing left to select. No control on this
tab, and the native field is removed.

*Engine-run (Campaign Intelligence) automations* — dynamic list from the
engine catalog, each row: Enabled + Test mode toggles, Smaily Workflow
select, Cooldown (days), Daily Cap (blank = no cap), Test Emails
(comma list). Every trigger starts disabled + test mode. Trigger
name/description are locale-aware per trigger (`name_et`/`name_en` from the
engine catalog, PRO-1292 — already shipped).

**Design-pack sample trigger names/defaults (Abandoned cart / Welcome /
Back in stock / Win-back with specific cooldown-hour and daily-cap values)
are illustrative mockup placeholders only — the real trigger catalog is
sourced dynamically from the engine, not a fixed list.** Do not hardcode
the pack's sample set.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Map store events to Smaily automations | Seo poe sündmused Smaily automaatikatega |
| Refresh workflows | Värskenda töövooge |
| Welcome — fires when someone becomes a subscriber | Tervitus — käivitub, kui keegi saab tellijaks |
| First order — fires on a customer's first purchase | Esimene tellimus — käivitub kliendi esimesel ostul |
| Abandoned cart — fires when a cart is left behind | Hüljatud ostukorv — käivitub, kui ostukorv jäetakse maha |
| Campaign Intelligence Automations | Campaign Intelligence'i automaatikad |
| Connect Smaily to set up automations | Automaatikate seadistamiseks ühenda Smaily |
| Smaily Workflow | Smaily töövoog |
| Cooldown (days) | Puhkeaeg (päevades) |
| Daily Cap | Päevalimiit |
| Test Emails | Test-aadressid |
| Save Intelligence Automations | Salvesta Intelligence'i automaatikad |

**Wording-harmonization candidate (non-blocking):** Shopify's "Test Emails"
equivalent is titled **"Test addresses"** with explicit help text
("Comma-separated, up to 50 addresses.") plus a proactive warning when
enabled + test mode + no addresses are set. The warning is a genuine UX
improvement worth considering (see §5), the rename is cosmetic.

**(d) REMOVE:** none design-pack-invented. The "How triggers work →" link
and per-trigger "cooldown hours"/"daily cap" fields shown in the pack are
**real** (A2 confirms cooldown+cap exist for engine automations), but the
pack's specific hour-based cooldown for store-event triggers vs day-based
for engine automations should not be conflated when implementing — verify
against `ViewModel\Adminhtml\AutomationsForm` field names, not the pack's
prose.

#### 2.3.D Intelligence tab

**(a) Layout:** **the design pack has zero visual detail for this tab** (A1
gap — named only in the tab strip, never mocked up). Use the common
Settings shell (2.3(a)) plus the Backfill card layout (2.7) for the three
historical-import buttons; no other layout guidance exists from the design
pack. Phase B should design this tab's control layout from the common shell
+ existing card/toggle idioms, not invent new visual language.

**(b) Exposed options/controls** (source: A2 §D, confirmed):

| Control | Where | Real functionality |
|---|---|---|
| Setup URL or token | wizard step 4 / Settings / native config | one-time exchange, never stored |
| Connect button | same | AJAX exchange + tenant/version status |
| Storefront browse tracking | wizard step 4 (post-connect) / Settings / native config | off by default, respects cookie restriction mode |
| Historical imports: catalog / customers / orders | **Settings tab only** | `smaily:backfill:start catalog\|customers\|orders`, live progress, Cancel |

**REMOVED (§4, decision 4):** the per-entity Sync catalog / Sync customers /
Sync orders toggles (Settings tab only, not in wizard) are gone — connecting
the engine syncs everything, matching both siblings. This is a code change,
not yet implemented: `Observer\Engine\ProductSaveAfter`/`ProductDeleteBefore`,
`CustomerSaveAfter` and `OrderSaveAfter` currently gate on
`Settings::isCatalogSyncEnabled()`/`isCustomerSyncEnabled()`/
`isOrderSyncEnabled()`, and the three `smaily_connect/intelligence/sync_*`
config paths default to `1` in `etc/config.xml`. Removing only the checkboxes
is behaviourally safe (nobody could turn sync off without them anyway, and
the default is already "on") but leaves dead toggle-config-paths and
observer gates that the implementation pass should delete outright, not just
hide.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Campaign Intelligence (optional) | Campaign Intelligence (valikuline) |
| Setup URL or token from Smaily | Smailylt saadud seadistus-URL või -võti |
| Connect | Ühenda |
| Enable storefront browse tracking (product views, searches, cart activity) | Luba poe sirvimise jälgimine (tootevaatamised, otsingud, ostukorvitegevus) |
| Historical imports to Campaign Intelligence | Ajaloolised impordid Campaign Intelligence'i |
| Import catalog / Import customers / Import orders | Impordi kataloog / Impordi kliendid / Impordi tellimused |

The three "Sync catalog/customer/order changes to the engine" phrases (both
packs) become dead i18n once decision 4 is implemented — drop them from
`i18n/en_US.csv`/`et_EE.csv` in that pass rather than leaving orphaned
entries.

**(d) REMOVE:** none — nothing design-pack-invented here since the pack
never mocked this tab up.

#### 2.3.E RSS tab

**(a) Layout** (source: A1 §2.3.3, `Settings.dc.html:106-107`): 4-field
grid (`1fr 1fr`, max-width 520px) + a read-only "Feed URL" row (monospace
code chip, horizontal-scroll, + Copy button) + an InlineStatus confirming
copy. Footer: Save RSS | InlineStatus.

**(b) Exposed options/controls** (source: A2 §E, confirmed —
`config/rss-builder.phtml`, `Controller/Rss/Feed.php`):

| Control | Real functionality |
|---|---|
| Enable the product RSS feed | store-wide (single toggle — per-store-view granularity dropped, resolved Erkki 2026-07-14) |
| Category ID (optional, numeric) | filters feed to one category |
| Number of products (1–250, default 50) | `limit` param |
| Sort by (created_at/updated_at/name/price) | `sort` param |
| Sort order (asc/desc) | `order` param |
| Copy button | clipboard API + `execCommand` fallback |

**REMOVED (§4, decisions 1+3):** the "Advanced RSS options in Stores >
Configuration" deep link (real, shipped, PRO-1281 carry-over) is removed —
not yet implemented. §4.2's investigation found native's RSS group has no
field beyond `enabled` and the URL-builder block, both already duplicated on
this tab; the link was pointing at pure duplication, not a genuine advanced
surface. **Resolved (Erkki, 2026-07-14):** `rss/enabled` had a confirmed live
per-store-view read (`Controller\Rss\Feed::execute()`), but the field drops
that granularity by product decision — one store-wide enable toggle lives on
this tab (the (a) layout's "Enable the product RSS feed" field), replacing
the native default+website+store scope entirely. No store-view switcher is
built; native config for this field is removed.

The design pack's **4-field grid** ("Store view / Source / Max items /
Sort") maps onto the real fields as: "Max items" → Number of products,
"Sort" → Sort by (+ our separate Sort order field, which the pack doesn't
show), "Source" → Category ID (see follow-up below) — "Store view" has no
real counterpart, see (d).

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Product RSS feed | Toodete RSS-voog |
| Enable the product RSS feed | Luba toodete RSS-voog |
| Category ID (optional) | Kategooria ID (valikuline) |
| Number of products (1-250) | Toodete arv (1-250) |
| Sort by | Sordi |
| Sort order | Sortimise suund |
| Copy | Kopeeri |
| Date created / Date updated / Product name / Price | Loomise kuupäev / Muutmise kuupäev / Toote nimi / Hind |
| Descending / Ascending | Kahanev / Kasvav |

**(d) REMOVE — design-pack leak:** the "Store view" dropdown inside the URL
builder widget. Confirmed pure design-pack invention — no sibling, and no
line of our own code, backs a store/language selector inside the builder;
in all three products the feed is scoped by which page/domain the builder
is rendered on, never an in-widget dropdown. **Do not build. Delete if any
trace exists.**

This is the direct fix for PRO-1357 finding #10's "unstyled" half. The
"punts merchant to the second config surface" half of finding #10 was
previously treated as intentional (see the old §4.i open decision) — §4.2's
investigation reverses that: the deep link pointed at pure duplication, not
a genuine advanced surface, so it is now REMOVED per decision 1 (see (b)
above), not kept and better-styled.

---

### 2.4 Log

**(a) Target layout** (source: A1 §2.5): native Magento `ui_component` grid
stays untouched. Three additions:

1. A warning **Banner** above the grid ("N events failed in the last 24
   hours" + reassurance message + "Show failed (24h)" action that applies
   the grid's own native filter preset, status=failed/parked AND
   created≥now-24h — **validate the exact filter combination against real
   code**, A1 flags this as design-implied and A2 confirms only a
   "pre-filtered to `status=failed`" deep link exists today, not
   confirmed to also include parked/24h-window — treat the pack's precise
   filter combination as aspirational until cross-checked against
   `Controller\Adminhtml\Log\*`).
2. A **Details slide-out panel** (right-docked, scrim behind it): header
   (event id + type + status Pill + close), scrollable body (summary
   grid, attempt-history timeline, redacted request payload, redacted
   response), footer (Retry now, Copy payload, InlineStatus).
3. A **Log verbosity** control (error/info/debug, `logging/verbosity`) —
   resolved (Erkki, 2026-07-14, §4.2): this field is native-only today and
   gets a home on this page instead, replacing native config entirely. No
   design-pack guidance exists for its placement (the pack never mocked this
   control); Phase B places it (e.g. a small settings strip above the grid)
   from the common shell idioms, not new visual language.
   **Superseded (Erkki, 2026-10-02):** the control was built and then
   removed again — the Log page has no logging-level control. The setting
   stays at `logging/verbosity` (default `error`) and a developer changes it
   with `bin/magento config:set`, as the WooCommerce plugin leaves detailed
   logging to the developer (`WP_DEBUG`).

**(b) Exposed options/controls** (source: A2 §G, confirmed —
`smaily_log_grid.xml`, `log/details.phtml`):

| Element | Real functionality |
|---|---|
| Grid columns | Source, Type, Entity, Status, Attempts, Last Error, Created, Updated, Actions |
| Mass action: Retry | re-queues selected failed rows |
| Failed-24h banner | zero-state hidden; links to grid pre-filtered on status |
| Details slide-out | status pill, attempts (N of MAX), honest retry line (5 states: sent/sending/failed-terminal/scheduled-retry/waiting-for-flush), last error (redacted), payload as-sent/queued (redacted), last response (redacted) |
| PII redaction | `Model\Log\PayloadRedactor` — secrets never shown, emails masked |
| Log verbosity (error/info/debug) | Removed (Erkki, 2026-10-02) — developer-only via `bin/magento config:set`, see item 3 above |

**Built (PRO-3565, 2026-10-02).** The grid's Status column is a Pill per
row (pending/sending amber, sent green, failed red, withdrawn and skipped
grey with their own labels). Details is Magento's slide modal narrowed to
the pack's 452 px right-docked panel — the closest Magento-native
equivalent, which keeps Magento's scrim, focus handling, Escape and close
button. Its header carries the event id, type and status Pill; the body
lists the attempts in order from what the queue row stores (attempt count,
timestamps, latest outcome — the row keeps no per-attempt record, so an
earlier failure is listed without a time or error, and the panel says so);
payload and responses are the pack's dark code blocks; the footer has the
pack's two actions and an InlineStatus. The pack's "Retry now" is labelled
**Send again**: it is the same guarded action as the grid's per-row Send
again (PRO-2454 — offered only where `ResendGuard` clears the row), and the
shared terminology wins over the pack's copy. "Copy payload" copies the
redacted payload the panel shows.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Source / Type / Entity / Status / Attempts / Created / Updated / Last Error / Actions | Allikas / Tüüp / Kirje / Olek / Katsed / Loodud / Uuendatud / Viimane viga / Tegevused |
| Retry (mass action) | Proovi uuesti |
| "All %1 automatic attempts are used up — this row will NOT retry on its own. Select it in the log and press Retry to queue it again." | (shipped) |
| "%1 events failed in the last 24 hours" | (shipped) |
| "Sensitive values (passwords, API keys) are never shown here, and email addresses are masked." | (shipped) |

**(d) REMOVE:** none.

---

### 2.5 Backfill (embedded, Subscribers + Intelligence tabs)

**(a) Target layout** (source: A1 §2.6): card shell — header (title +
sub-label + optional status Pill), optional **ProgressBar** block (bar +
"X of Y" count + percentage, shown in **every state except idle**), a
verdict/body line (icon + sentence, color-matched to state), an optional
deep-link to the Log, and a footer (1-2 buttons + optional InlineStatus).

**This is the direct fix for PRO-1357 findings #7 (progress bar stuck on
the import button) and #8 (stuck "Importing… 0/?" with no import
started):** the idle state must render **no Pill and no ProgressBar at
all** — a progress bar (even at 0) must never be visible unless a job is
actually running. If the current implementation shows a progress affordance
before Start is pressed, that is the bug to fix against this target.

**(b) Exposed options/controls, 5 real states** (source: A2 §H, confirmed —
`panels-js.phtml`, `smaily_connect/api/backfillstate`):

Two independent contexts share the same mechanics: (1) Subscribers →
Smaily (Settings > Subscribers / wizard step 2, one job: `contacts`);
(2) Catalog / Customers / Orders → Campaign Intelligence (Settings >
Intelligence tab only, three jobs). Import button, Cancel import button
(shown only while running), progress bar, one of the 5 terminal/live
states below.

**(c) EST+ENG text — the 5 states are the load-bearing copy** (source: our
own i18n):

| EN | ET |
|---|---|
| Importing… %1 / %2 | (shipped) |
| Done, %1 of %2 synced. | (shipped) |
| Done, %1 of %2 synced — %3 failed. | (shipped) |
| Stopped before an error — %1 of %2 synced so far. Press the import button to run it again. | (shipped) |
| Cancelled — %1 of %2 synced. Starting again begins a fresh import. | (shipped) |
| Import your existing subscribers | Impordi oma olemasolevad tellijad |
| Import subscribers to Smaily | Impordi tellijad Smailysse |
| Cancel import | Katkesta import |
| View them in the Log | (shipped — links to Log pre-filtered to failed rows) |

A1's 6-state model (idle/running/done-clean/done-with-failures/stopped/
cancelled) maps 1:1 onto these 5 copy states plus the idle state (which has
its own CTA copy, "Import your existing subscribers", and no progress
affordance per (a) above) — no gap between A1's states and A2's real copy.

**(d) REMOVE:** none. "Resume import" (continuing from the last committed
record) and "Discard & restart"/"Start over" as distinct destructive
actions from the pack are **not separately confirmed in A2's option
table** — our shipped copy's Stopped/Cancelled strings ("Press the import
button to run it again" / "Starting again begins a fresh import") describe
a **restart, not a resume** at the byte level of confirmed wording. Treat
"Resume import" as a design-implied feature not validated by this pass —
do not build a resume-from-last-record UI unless/until confirmed against
the real job model; the current "run again = fresh run" framing is what's
canonical per (c).

---

## 3. Findings #1–#10 → resolution map

| # | PRO-1357 finding | Screen/section | Target-spec item that fixes it |
|---|---|---|---|
| 1 | Stray glyph before Dashboard nav label | Admin menu (not a screen covered by A1/A2 — menu chrome is out of scope for both analyses) | Not covered by this spec — trivial standalone bug fix, listed in §5 Follow-ups. |
| 2 | Two config surfaces, partial/duplicated | Settings (all tabs) vs native `Stores > Configuration` | **RESOLVED** (§4, decisions 1+3, plus the four field-level resolutions in §4.2, Erkki 2026-07-14): one source of truth = our own pages; **native `Stores > Configuration > Smaily` disappears entirely** — every field, including the four that were native-only orphans, now has a target home on the module's own pages. The RSS tab's own duplication (finding #10) is folded into the same fix. |
| 3 | Opaque "overridden" scope banner | Settings (all tabs) | **RESOLVED** (§4, decision 2): scope is handled by us, never shown to the merchant. The PRO-1274 detection/clear machinery (`OverrideDetector`/`OverrideClearer`) is reused, but triggered automatically on save instead of surfaced as a visible banner + manual "Use Default" — that banner disappears from the merchant's normal view. Scope stays visible only on the advanced native page, for whatever fields survive there. Implementation (auto-clear-on-save) is a follow-up, not shipped by this doc pass. |
| 4 | Settings page diverges from design layout + wrong wording | Settings (all 5 tabs) | §2.3 (a) layout target + (c) EST+ENG text tables, per tab. |
| 5 | Setup Wizard choice-cards broken | Setup Wizard | §2.2 (a) Radio Choice-Card layout target (anatomy, states, reactive region) + §2.3.A (a)/(b) for the underlying 4-mode data it must render. |
| 6 | Single/double opt-in is a design-pack leak | Settings > Subscribers | §2.3.B (d) REMOVE — confirmed leak, delete rather than build. |
| 7 | Unstyled checkboxes, ugly spacing, progress bar stuck on import button | Settings > Subscribers (checkboxes) + Backfill (progress bar) | §2.3.B (a) layout (extra-fields checkbox set, Radio Choice-Card) + §2.5 (a)/(b) — idle state must show no progress affordance. |
| 8 | Stuck "Importing… 0/?" with no import started | Backfill (embedded, Subscribers + Intelligence) | §2.5 (a) — explicit rule: idle = no Pill, no ProgressBar at all. |
| 9 | Automations: cramped checkbox+dropdown vs design's per-automation controls + status pills | Settings > Automations | §2.3.C (a) card-list-with-Pill layout target + (b) real field set (do not adopt the pack's illustrative trigger names/defaults). |
| 10 | RSS view unstyled + punts to second config surface | Settings > RSS | §2.3.E (a) layout target + (d) REMOVE (Store view dropdown). The "punts to a second config surface" half is now **also a bug, not intentional** — §4.2's investigation found native's RSS group has no field beyond the two already duplicated on this tab, so the "Advanced RSS options" deep link points at pure duplication and is removed too (decision 1). **Resolved (Erkki, 2026-07-14):** `rss/enabled` drops its per-store-view granularity by product decision — one store-wide RSS enable toggle on this tab, no store-view switcher, no native residue. |

---

## 4. Config architecture — RESOLVED (Erkki, 2026-07-14)

The three product-direction calls this section used to carry as open
questions are now decided, binding, and folded into the per-screen sections
above (2.1, 2.3.B, 2.3.C, 2.3.D, 2.3.E) and the findings map (§3, rows #2/#3).
This section also adds the **config field inventory** — the field-by-field
investigation that determines what, if anything, must stay in native
`Stores > Configuration`.

### 4.0 The four resolved decisions

1. **One source of truth = the module's own admin pages. No duplication.**
   A given setting lives in exactly one place. The merchant configures
   Smaily only via the module's own Settings/Wizard pages.
2. **Scope is handled by us, never shown to the merchant.** Our pages save
   at the scope that actually takes effect; if a leftover per-site override
   exists (e.g. seeded by the 2.8.x→v3 upgrade) that would shadow the saved
   value, we clear it on save so "what you see is what runs." The PRO-1274
   "Overridden for X" banner + manual "Use Default" affordance disappears
   from the merchant's normal view — clearing becomes automatic, not
   surfaced. Scope stays visible ONLY on the advanced native page, for
   whatever fields survive there under decision 3.
3. **Native `Stores > Configuration` shrinks to advanced-only, or
   disappears.** Keep in native config ONLY fields that genuinely need
   Magento's per-website / per-store-view scoping — marked clearly as
   "advanced," never duplicated on the module's own pages. Erkki's lean:
   move as much as possible under our own Settings.
4. **Intelligence sync toggles removed.** The per-entity Catalog / Customers
   / Orders sync on/off switches (Settings > Intelligence) are removed —
   connecting the engine syncs everything, matching Woo (removed in its
   v3.9) and Shopify (never had them). `Storefront browse tracking` is a
   different control (consent-gated, not a sync-domain toggle) and is
   **not** part of this removal.

### 4.1 Resulting config architecture

Applying decision 3's rule field-by-field (§4.2 below) turns up a
surprising result worth stating plainly: **almost nothing in the current
field set has a demonstrated, exercised need for merchant-facing native
scope.** The one genuine per-website/per-store-view mechanism already in
use — multilingual mode A's per-language credentials — is scoped
*programmatically* by our own Connection tab (`WizardStepSaver::saveConnect`
writes store-view-scoped rows directly); the merchant never touches
Magento's native scope switcher to get it. Everywhere else, the
`showInWebsite`/`showInStore` flags in `system.xml` are either unused by any
deliberate multi-site workflow, or are the very "leftover override" pattern
PRO-1274 was built to paper over. The one technically-exercised exception was
the RSS `enabled` flag, whose read path (`Controller\Rss\Feed::execute()` →
`Config::isRssEnabled()`) resolves against the current storefront's store
scope on every request — a real, live per-store-view behaviour, resolved
below by a product decision to drop that granularity rather than preserve
it. See §4.2 for the full field-by-field detail and the resolution of each
of the four fields that were left open at the time of the first pass.

**Net effect — resolved (Erkki, 2026-07-14): native `Stores > Configuration >
Smaily` disappears entirely.** The four fields that were the only candidates
for a residual native/advanced surface — the three native-only orphans
(`include_guests`, `automation_force_opt_in`, `abandoned_fields`) and the
RSS `enabled` per-store-view scope — are all resolved to move fully onto the
module's own pages (§4.2; `abandoned_fields` has since been retired
altogether, PRO-1760). No field keeps a native-only or native-advanced
home; full sibling parity (Woo/Shopify both have no native config surface)
is the outcome, not a "shrinks to advanced" compromise.

### 4.2 Config field inventory

Source: `etc/adminhtml/system.xml` (native fields, backend models, scope
flags), `etc/config.xml` (defaults), `Model/Config.php` +
`Model/Engine/Settings.php` (typed path constants + scoped getters),
`Model/Config/ModuleConfigPaths.php` (the PRO-1274 allowlist — which paths
the module already treats as "its own"), `Model/Adminhtml/WizardStepSaver.php`
(what our own Settings/Wizard AJAX save actually writes, and at what scope),
`ViewModel/Adminhtml/ConfigOverrides.php` (which fields the Settings page
currently renders a control for — its `FIELD_ANCHORS` map is the ground
truth for "is this field really on our own page today").

Legend: **native** = declared in `system.xml`; **ours** = has a live control
on a wizard/Settings panel today; **both** = duplicated right now.

| Field (path) | Purpose | Current home | Target home |
|---|---|---|---|
| `connection/subdomain` | Smaily account subdomain | both (native: default+website+store scope; ours: Connection tab default scope, mode-A writes store-view scope programmatically) | **ours.** Native's scope switcher is redundant — mode A already manages store-view scope without exposing it to the merchant (decision 2). |
| `connection/username` | Smaily API username | both, same shape as subdomain | **ours**, same reasoning. |
| `connection/password` | Smaily API password (encrypted) | both, same shape as subdomain | **ours**, same reasoning. |
| `connection/test_connection` | Test-Connection button (no stored value) | both (native `frontend_model` button; ours: AJAX Test Connection) | **ours.** Pure UI duplication, not a config value. |
| `connection/multilingual_mode` | Routing mode (single/a/b/c) | both (native: default+website scope; ours: Connection tab mode cards, default scope only) | **ours** — resolved (Erkki, 2026-07-14): native's per-website differentiation is dropped, one mode per Magento instance, owned entirely by the Connection tab. |
| `subscribers/sync_enabled` | Master subscriber-sync toggle | both (native: website scope; ours: default scope) | **ours.** |
| `subscribers/sync_mode` | Lawful-basis preset | both, same shape | **ours.** |
| `subscribers/sync_fields` | Extra contact fields synced | both, same shape | **ours.** |
| `subscribers/include_guests` | Include guest-order emails | **native only** — no control in `subscribers.phtml`/`ConfigOverrides::FIELD_ANCHORS`, though `WizardStepSaver::saveSubscribers()` already has a dead `saveFlag()` call ready to accept it | **ours** — resolved (Erkki, 2026-07-14): real control built on the Subscribers tab; see §2.3.B. |
| `subscribers/automation_force_opt_in` | "Automations May Re-Subscribe (Advanced)" | **native only**, same shape as `include_guests` (dead `saveFlag()` call, no template control) | **retired** (2026-10-02): no control, no `system.xml` field, the update deletes a stored row — automations always send `force_opt_in=false`; see §2.3.B. |
| `subscribers/checkout_optin_enabled` | Checkout newsletter checkbox | both | **ours.** |
| `subscribers/suppress_optin_emails` | Suppress Magento's own opt-in emails | both | **ours.** |
| `automations/welcome_enabled` / `welcome_workflow` | Welcome automation | both | **ours.** |
| `automations/first_order_enabled` / `first_order_workflow` | First-order automation | both | **ours.** |
| `automations/abandoned_enabled` / `abandoned_workflow` / `abandoned_cutoff` | Abandoned-cart automation | both | **ours.** |
| `automations/abandoned_fields` | Abandoned-cart product fields (7 checkboxes) | **native only**, same dead-`saveFlag()` shape as `include_guests` | **RETIRED** (PRO-1760) — the control resolved here was built on the Automations tab and then removed: product details always send, so there is nothing to select. Native field and source model deleted; the stored path is abandoned in place, never migrated. |
| `automations/engine_automations` | Embedded engine-automations block (no stored value of its own) | both (native `frontend_model`; ours: Automations tab) | **ours.** Pure UI duplication. |
| `rss/enabled` | RSS feed on/off | both (native: default+website+store scope; ours: RSS tab, default scope only) | **ours** — resolved (Erkki, 2026-07-14): per-store-view granularity is dropped by product decision (the field had a confirmed, exercised per-store-view read via `Controller\Rss\Feed::execute()` → `isRssEnabled()`, but the resolution trades that granularity for a single store-wide toggle); see §2.3.E. |
| `rss/url_builder` | Feed URL builder (no stored value) | both (native `frontend_model`; ours: RSS tab) | **ours.** Pure UI duplication — and, per the investigation below, native's RSS group has *no* field beyond this and `enabled`, so there is nothing genuinely "advanced" left there to point at. |
| `intelligence/status` | Connection status display (no stored value) | both | **ours.** |
| `intelligence/setup_token` | One-time engine setup exchange (write-only, never stored) | both | **ours.** No scope need — default-scope-only tenancy. |
| `intelligence/sync_catalog` | Catalog sync toggle | both, real wired gate — `Observer\Engine\ProductSaveAfter`/`ProductDeleteBefore` call `Settings::isCatalogSyncEnabled()` | **REMOVED** (decision 4). Default is already `1` in `etc/config.xml`, so removing the UI alone doesn't break anything live — but the toggle, its config path, and the observer gate become dead surface that should be deleted in the implementation pass, not just hidden. |
| `intelligence/sync_customers` | Customer sync toggle | both, same shape (`CustomerSaveAfter`) | **REMOVED** (decision 4), same note. |
| `intelligence/sync_orders` | Order sync toggle | both, same shape (`OrderSaveAfter`) | **REMOVED** (decision 4), same note. |
| `intelligence/browse_tracking` | Storefront browse tracking (consent-gated) | both | **ours.** Not part of decision 4 — real, distinct capability. No scope need (default-scope-only tenancy). |
| `logging/verbosity` | Log verbosity (error/info/debug) | **native only**, default-scope-only (no `showInWebsite`/`showInStore` at all) | **CLI only** — superseded (Erkki, 2026-10-02): no admin control; `bin/magento config:set` changes it. The hidden `system.xml` field stays because `config:set` rejects a path `system.xml` does not declare. See §2.4. |

**Fields resolved by Erkki (2026-07-14) — binding, not yet implemented:**

- **`include_guests`, `automation_force_opt_in`, `automations/abandoned_fields`**
  — the three orphan fields that exist ONLY on native config today, with no
  UI anywhere on our own pages (though `WizardStepSaver` already has
  unreachable `saveFlag()`/array-handling code ready for the first two, as
  if a control was planned and never built). **Decision: they are real
  features, currently hidden — build proper UI for all three on the
  module's own pages** (`include_guests` and `automation_force_opt_in` on
  Subscribers, §2.3.B; `abandoned_fields` on Automations, §2.3.C). Native
  config for all three is removed once built. *`abandoned_fields` has since
  been retired (PRO-1760): the control was built, then removed — product
  details always send, so nothing is selectable. Only the two
  Subscribers-tab controls remain to build.*
- **`connection/multilingual_mode`'s website scope** — native config lets an
  admin set a *different* multilingual mode per website; nothing in our own
  UI offers or exercises that. **Decision: dropped.** One multilingual mode
  per Magento instance, owned entirely by the Connection tab (§2.3.A); the
  native per-website differentiation was a legacy affordance nobody
  exercises deliberately.
- **`rss/enabled`'s store-view scope** — the one field with a confirmed live
  per-store-view read (see above). **Decision: dropped in favour of
  simplicity.** One store-wide RSS enable toggle on the RSS tab (§2.3.E),
  replacing the native default+website+store scope entirely — no store-view
  switcher is built.
- **`logging/verbosity`** — no scope need at all (default-scope-only).
  **Decision: gets a home on the module's own pages** (the Log page, §2.4)
  rather than staying native/CLI-only; no native config needed.
  **Superseded (Erkki, 2026-10-02):** CLI only after all, see §2.4.

**Migration constraint (record only, not solved here):** old 2.8.x installs
have their Smaily credentials in native `core_config_data` — the 2.8.x→v3
migration (`Setup\Patch\Data\MigrateLegacyConfig` +
`Model\Migration\LegacyConfigMapper`) writes into the SAME `smaily_connect/…`
paths this inventory covers, including seeding website-scope rows 1:1 from
the legacy per-website config (the rows PRO-1274's `OverrideDetector` exists
to find). Moving a field's *editing surface* (which admin page renders the
control) does not touch stored `core_config_data` rows or the migration
patch — `ScopeConfig` resolves by path regardless of which `system.xml`
declares it, and `OverrideDetector`/`OverrideClearer` already key off the
typed `Model\Config\ModuleConfigPaths` allowlist, not `system.xml` presence.
The constraint that DOES apply: no implementation of this inventory may
rename or restructure a config *path* without an explicit value-migration
step — an upgrading merchant's stored credentials must resolve at the same
path before and after any admin-surface reshuffle.

---

## 5. Follow-ups / minor bugs (list, don't fix)

- **§4.2 config-field decisions — resolved (Erkki, 2026-07-14), not yet
  implemented.** All four: `include_guests`/`automation_force_opt_in` get
  built onto the Subscribers tab (`abandoned_fields` was built onto the
  Automations tab and then retired in PRO-1760 — product details always
  send); `multilingual_mode`'s native per-website scope is dropped (one mode
  per Magento instance); `rss/enabled` drops its per-store-view granularity
  for one store-wide toggle on the RSS tab; `logging/verbosity` gets a home
  on the Log page (superseded 2026-10-02: CLI only, see §2.4). Net effect: native `Stores > Configuration > Smaily`
  disappears entirely — no field keeps a native-only or native-advanced
  home. This is a Phase B implementation item (including deleting the
  Intelligence-sync observer gates per decision 4, and the migration
  constraint on config paths, §4.2) — see §4.2 for full reasoning on each.
- **`__('Done')` wizard step-5 label — i18n gap.** `wizard/index.phtml:32`
  has no entry in either `i18n/en_US.csv` or `i18n/et_EE.csv`; falls back to
  English "Done" under et_EE. Trivial one-line fix (A2 §I).
- **RSS named-category/collection picker** — both siblings (Woo: "Product
  category" live dropdown; Shopify: "Collection" live dropdown) have this;
  our builder only takes a raw numeric Category ID. Real feature gap, not a
  leak — candidate for a follow-up ticket, live AJAX category-tree dropdown
  replacing the numeric-ID input (A2, design-pack-leaks §2).
- **Shopify-style "nothing will send" safety warning** on engine-automation
  Test Emails/addresses when enabled + test mode + no addresses are set —
  UX nicety, not a functionality gap (A2 §C).
- **Wizard "What's active" live summary** (Shopify's richer final-step
  recap: connection status, sync/backfill counts, per-automation mapped-
  workflow counts) vs. our current static links list on the Done step — UX
  idea, not required (A2 §I).
- **Stray glyph before the Dashboard nav label** (PRO-1357 finding #1) —
  standalone menu-label bug, not covered by either analysis; fix directly
  against the admin menu XML/text, no design-spec dependency.
- ~~**Log slide-out "Retry now"/"Copy payload" buttons**~~ — built
  (PRO-3565; "Retry now" is labelled Send again, see §2.4). **The exact
  failed-24h filter combination** (status=failed/parked AND created≥
  now-24h) — flagged in §2.4(b) as design-implied but not independently
  confirmed present in current code; verify against `Controller\Adminhtml\
  Log\*` before assuming they're already shipped.
- **Backfill "Resume import" (resume-from-last-record)** — not confirmed by
  A2's option table; our shipped copy currently frames Stopped/Cancelled
  recovery as "run again = fresh start," not a resume. If a genuine resume
  capability is wanted, that's new engineering, not a copy fix (§2.5(d)).
- **Wording-harmonization candidates** (all non-blocking, sibling wins per
  binding rule, future copy pass — full list assembled from A2, repeated
  here for one place to track): multilingual mode A/B/C labels (§2.3.A),
  "Automations May Re-Subscribe (Advanced)" → "Force opt-in on automation
  triggers" (A2 design-pack-leaks §1), Dashboard verdict failure/all-clear
  wording (§2.1), "Test Emails" → "Test addresses" (§2.3.C).
- **Design-pack visual inconsistencies — resolve when CSS is finalized, not
  now** (A1 §3, purely cosmetic seams in the pack itself, not functional
  claims): (a) Metric Tile value font-size specified as `--fs-28` on the
  tokens/Components pages but rendered as a literal `30px` in the
  standalone `Tile.dc.html`; (b) the `off` Pill variant's neutral color
  triple (`#6b6b6b`/`#efefef`/`#cfcfcf`) doesn't exactly match the token
  sheet's `--s-neutral`/`--s-neutral-soft`/`--s-neutral-border`
  (`#5a5a5a`/`#eeeeee`/`#cccccc`); (c) ProgressBar's 4-state enum
  (`running/done/failed/stopped`) is overloaded by the Backfill screen to
  represent 6 conceptual states (done-with-failures and stopped-mid-run
  both render via the `failed` visual). Pick one canonical value for each
  when the token/component CSS is next touched.
