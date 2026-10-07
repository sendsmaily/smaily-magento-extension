# Admin functionality + text map (PRO-1369, analysis A2)

**Purpose.** Reconciling the module's admin UI needs a source of truth for
*what we should actually expose* and *what it should say*, independent of the
design pack (`docs/Magento Connect admin visual system.zip`), which invents UI
for features we may not have. This document is that source of truth: per
screen, the option set backed by real code in this repo, cross-checked against
the sibling plugins (Woo `../connect`, Shopify `../shopify-connect`), with
canonical EST+ENG wording. **Sibling wording wins on conflict; where a control
only exists in this module, our own (already-shipped, already-bilingual)
wording is canonical.**

**Method.** Read every admin template under `view/adminhtml/templates/` and
the controllers/models/observers/config it invokes; cross-checked the design
pack for options NOT backed by that code (leaks); cross-checked the Woo and
Shopify plugins for equivalent options and their exact strings. Our own
`i18n/en_US.csv` / `i18n/et_EE.csv` are already a genuine EST translation (398
of 405 lines differ from English — this is real bilingual product text, not
placeholder copy), so for controls that already exist in our code, our own
shipped strings are the primary EST+ENG source; sibling strings are quoted
where they differ meaningfully or where a control is new to us.

**Status marker legend:** `[confirmed]` = read directly in our code.
`[inferred]` = reasoned from adjacent confirmed code, not directly observed.
`[Woo]` / `[Shopify]` = sourced from the named sibling with a file:line
citation. `[gap]` = sibling text could not be located; do not guess wording,
listed under "screenshot would help" instead.

---

## Cross-cutting question #2 — two configuration surfaces

**What exists today (confirmed from `etc/adminhtml/system.xml` +
`view/adminhtml/templates/settings/index.phtml` + panel templates):**

The module has **two surfaces that write the same `core_config_data` paths**:

1. **Native Magento `Stores > Configuration > Smaily > Smaily Connect`**
   (`etc/adminhtml/system.xml`) — six groups: API Connection, Subscriber
   Synchronization, Automations, Product RSS Feed, Campaign Intelligence,
   Logging. This is the **complete** field set, including several fields
   that exist ONLY here, not on the module's own pages:
   - `include_guests` — Include Guest Order Emails
   - `automation_force_opt_in` — Automations May Re-Subscribe (Advanced)
   - `abandoned_fields` — Abandoned Cart Product Fields (which of the 7
     product fields ride along with an abandoned-cart trigger)
   - `logging/verbosity` — Log Verbosity
   - the `EngineStatus` connection-status label field (in the module's own
     Intelligence panel this is rendered as connected/disconnected state
     instead, not a config-page label)
   - true native per-website / per-store-view scope switching (`canRestore`,
     "Use Default") for every restorable field
2. **The module's own admin pages** — Setup Wizard (5 steps) and Settings
   (5 tabs, same content as the wizard steps as always-available tabs) under
   `Marketing > Smaily Connect`. These cover the day-to-day fields (identical
   labels/help text to the config groups above, reworded for a guided flow)
   plus things the native config page does **not** have: live Test
   Connection, live workflow-dropdown population, the multilingual
   routing-mode choice cards + per-language account/workflow editors, the
   contacts backfill button + live progress, the engine historical-import
   buttons + live progress, the RSS feed URL builder with instant preview,
   and (PRO-1274) the config-scope "Overridden for X / Use default"
   indicator. **Both save into the exact same config paths** — the wizard
   and Settings page always write at the **default (global) scope**; native
   config lets an admin write any scope.

**Siblings — do they have one surface or two? Confirmed: both have exactly
ONE.**
- **Woo** [confirmed]: one custom React SPA (`admin/wizard.php` mounts the
  same bundle for both the wizard and the "Settings" page). Code comment,
  `admin/wizard.php:11-14`: *"The legacy Smaily admin settings PAGE was
  removed (F3-45) — this is now the ONLY admin UI... but all configuration
  lives here."* There is no native WordPress Settings API page anywhere
  (`register_setting()`/`add_settings_field()` do not appear in the repo) —
  Woo deliberately **removed** a second surface it used to have.
- **Shopify** [confirmed]: one surface by platform necessity — Shopify apps
  have no analogue of Magento's native scoped config tree at all, so there
  was never a second surface to begin with (`app.settings.tsx` is it).

Both siblings converge on "one surface" — but for different reasons than
would apply to us: Woo actively *chose* to delete a second surface it had;
Shopify's platform never offered one. Neither sibling's platform has
Magento's per-website/per-store-view config-scope tree, which is real,
useful functionality for multi-site Magento installs and something Magento
admins/agencies already expect to find under Stores > Configuration.

**Recommendation:** keep both, but tighten the division of labour that
already exists rather than merge them:
- **The module's own pages (wizard + Settings) are the primary, recommended
  surface** for 95% of merchants — day-to-day fields, always at default
  scope, with the live affordances (test connection, workflow lists,
  backfill/import progress, URL builder) that native config framework
  cannot render.
- **Native `Stores > Configuration`** stays the **advanced / scope-override**
  surface: the handful of fields that only make sense there (guest emails,
  force-resubscribe, abandoned-cart field selection, log verbosity) and any
  per-website/per-store-view override an admin explicitly wants. The
  Settings footer link ("Need per-website or per-store-view overrides, or
  the advanced fields? They live in Stores > Configuration…") and the RSS
  tab's "Advanced RSS options" deep link already encode this division —
  it should be made explicit and complete: every module page that has a
  native-config-only counterpart field should carry the same kind of
  "…advanced options in Stores > Configuration" pointer (Automations tab
  currently has no such pointer to `include_guests` /
  `automation_force_opt_in` / `abandoned_fields`; Log tab has no pointer to
  the Logging group). This is a **documentation/UX gap to close**, not a
  reason to duplicate those fields onto the module's own pages — they are
  legitimately advanced/rare enough to stay config-only.
- Do **not** attempt to merge into one page: the module's pages need live
  JS behaviour system.xml can't give them, and system.xml needs the native
  scope switcher the module's pages deliberately don't expose (see Q3).
  If Magento's admin framework ever grows a way to give a custom page the
  same scope-switching UX as native config, revisit consolidating to one
  surface Woo/Shopify-style — but that is a platform-capability question,
  not a copy/wording one, so it's out of scope for this analysis.

---

## Cross-cutting question #3 — config-scope override opacity

**What exists today (confirmed, PRO-1274, `Model/Config/OverrideDetector.php`
+ `Controller/Adminhtml/Config/ClearOverride.php` +
`view/adminhtml/templates/settings/index.phtml` lines 103-180):** the
Settings page always saves at default scope; `OverrideDetector` reads the raw
`core_config_data` rows for the module's paths and flags every website/
store-view row that shadows a default-scope field, with a
"Overridden for &lt;website / store view&gt;" banner and a **Use default**
button (confirm → delete that one row → config cache flush). This already
solves *visibility* of existing overrides. It does not prevent a merchant from
creating a *new*, confusing override by wandering into native config and
picking a non-default scope without understanding what that means.

**Where do overrides come from in practice (confirmed from
`Setup/Patch/Data/MigrateLegacyConfig.php:118-120` and the multilingual mode-A
flow)?**
1. **Legacy migration cruft** — the 2.7.x/2.8.x → v3 upgrade path seeds a
   **website-scope** row 1:1 from each legacy website's config (comment:
   "Website-scope rows map 1:1; the default scope seeds website 0"). This is
   a one-time historical artifact, not something the current app writes
   going forward.
2. **App-managed store-view credentials** — multilingual mode A
   ("Per-language Smaily accounts") deliberately writes per-store-view
   `subdomain`/`username`/`password` rows; this is intentional and the app's
   own UI (Connection panel) is the only place that creates or edits them.
3. **A merchant manually setting a scope override in native config** — the
   one truly "raw scope mechanics" case: nothing here guides or discourages
   this today.

**Recommendation — sensible defaults that avoid raw scope mechanics for
most merchants:**
- Keep "the module's own pages always write default scope" as the one rule
  merchants need to know (already true, already documented in
  `docs/USER_GUIDE.md`).
- Treat case 1 (legacy migration website-scope rows) as **cleanup debt, not
  configuration**: `OverrideDetector` already has everything needed to spot
  a legacy-seeded website row whose value is now *identical* to the default
  — offer a one-click "clean up carried-over per-website settings from your
  old install" action (distinct from the existing manual "Use default",
  which is for intentional overrides) so merchants who never intended a
  website override don't have to understand what one is to get rid of it.
  This is a **new, small, reversible feature** to consider under a
  follow-up ticket, not something to build silently in this analysis pass.
- Treat case 2 (mode-A store-view credentials) as **app-managed, not
  merchant-managed**: never surface it as a generic "override" the merchant
  must reason about — the existing OverrideDetector banner already applies
  general wording ("Overridden for X") to this case too, which is
  technically true but pedagogically noisy for a value the app itself put
  there on purpose. Consider (follow-up, not in scope here) special-casing
  mode-A's own store-view rows out of the generic override banner, since
  the Connection panel already explains and manages them directly.
  Non-mode-A store/website overrides (case 1 and case 3) are the ones where
  the generic banner earns its keep.
- For case 3, the module doesn't need to prevent admins from using native
  Magento scope switching (that would fight the platform) — the existing
  Settings-page pointer to "Stores > Configuration" for advanced needs,
  combined with the override-awareness banner catching the result
  afterward, is the right amount of guidance for the fraction of merchants
  who go there.

---

## Design-pack leaks — confirmed, recommended for removal

Cross-checked every option label in the design pack
(`docs/Magento Connect admin visual system.zip`, extracted from
`Settings.dc.html`, `Setup Wizard.dc.html`, `Dashboard.dc.html`,
`Engine Automations.dc.html`, `Backfill.dc.html`, `Log.dc.html`) against real
code (`Model/Config.php`, `Model/ContactSync/Mode.php`,
`Model/Config/Source/SyncMode.php`, `Model/Adminhtml/WizardStepSaver.php`,
`Controller/Rss/Feed.php`), then cross-checked both against the Woo and
Shopify sibling plugins. Two leaks found; one is pure design-pack invention
confirmed absent from us AND both siblings (never-build), the other split
into a pure-invention part (never-build) and a part that turned out to be
real sibling functionality we're simply missing (a follow-up feature
candidate, not a leak — see #2 below):

### 1. "Opt-in mode" (Double opt-in vs Single opt-in) — REMOVE

`Settings.dc.html:73-90` mocks a Subscribers-tab dropdown "Opt-in mode:
Double opt-in (confirmation email) / Single opt-in", with a reactive
"Confirmation email (Smaily automation)" picker shown only for the "Double"
option. **Confirmed not real:** grepped `Model/Config.php`,
`Model/ContactSync/Mode.php`, `Model/Config/Source/SyncMode.php`,
`Model/Adminhtml/WizardStepSaver.php` — the only sync-mode concept that
exists is the three-way lawful-basis preset (consent / legitimate interest /
checkout opt-in), which is a different axis entirely (who gets synced, not
how their subscription is confirmed). "Double opt-in" IS a real Magento
concept, but it is **Magento core's own** setting
(*Stores > Configuration > Customers > Newsletter > Confirmation Email
Required*) — our module doesn't duplicate it, and correctly documents that
it never suppresses the double-opt-in confirmation *request* email
(`docs/USER_GUIDE.md` "Let Smaily Send Opt-In Emails" bullet:
"The double-opt-in confirmation *request* email is never suppressed"). There
is no "confirmation email (Smaily automation)" trigger anywhere in
`Model/Config/Source/Workflows.php` or the automations trigger set
(welcome / first_order / abandoned_cart only). **This confirms Erkki's
statement** — the design pack's opt-in-mode selector is a design-pack leak
and must not be built.

**Sibling confirmation (both plugins independently checked, both refute
it):**
- **Woo** [confirmed]: grepped the whole codebase for `double.opt`,
  `single.opt`, `confirmation.email`, `opt-in.*confirm`,
  `suppress.*email`, and every native WooCommerce/WordPress account-email
  hook — zero hits for a merchant-facing opt-in-mode setting. The only
  "double opt-in" mention anywhere is a PHPDoc comment on
  `includes/Smaily/Client.php:116` describing a `force_opt_in` **API
  parameter sent to Smaily itself** (bypass Smaily-side unsubscribe), which
  Woo surfaces as "Force opt-in on automation triggers" — the same concept
  as our own `automation_force_opt_in`, not an opt-in-mode selector.
- **Shopify** [confirmed]: grepped for the same pattern family. The only
  hits are (1) a code comment stating storefront newsletter-form
  submission *is* the single-opt-in consent act, with no confirmation-email
  flow at all, and (2) a hardcoded literal `'SINGLE_OPT_IN'` written to
  Shopify's own consent-record API when mirroring a Smaily unsubscribe back
  — Shopify's own fixed enum value, never configurable, never a
  `DOUBLE_OPT_IN` alternative anywhere in the codebase.

Both siblings' own "force opt-in" equivalent is worth a wording note (not a
functionality change): Woo's field is titled **"Force opt-in on automation
triggers"** with help text "Advanced: a welcome / abandoned-cart /
first-order automation will re-subscribe the contact in Smaily, overriding
an existing unsubscribe." — Shopify's is identical, explicitly "adapted
from the Woo strings." Ours is titled **"Automations May Re-Subscribe
(Advanced)"** (`system.xml:91`) with similar but independently-worded help
text. Since both siblings independently converged on the same field name,
this is a **wording-harmonization candidate** (see the harmonization table
at the end of this document) — not urgent, not a functionality change, just
a candidate for a future copy pass to rename our field to match.

### 2. RSS builder "Store view" selector — REMOVE; named "Source" picker —
NOT a leak, a real feature gap (follow-up, not urgent)

`Settings.dc.html:106-107` mocks the RSS section's URL builder with a
"Store view" dropdown (English/Estonian) and a "Source" dropdown with named
options ("Category: New Arrivals", "Best sellers"). Splitting this into two
findings after the sibling check:

- **"Store view" dropdown — confirmed pure design-pack invention, REMOVE.**
  Neither sibling's RSS builder has a store/language selector of any kind
  (Woo: category dropdown + limit + sort + order + tax rate, no store
  selector; Shopify: collection dropdown + limit + sort, no store
  selector) — in both siblings and in our own code, the feed is scoped by
  *which screen/domain* the builder is rendered on, never by an in-widget
  dropdown. Nothing backs this control anywhere. Never build it.
- **Named "Source" (category-name) picker — revised finding: this is real
  functionality in BOTH siblings, so it is not fair to call it a pure
  design-pack leak; it is a feature our builder does not (yet) have.**
  Woo's field is "Product category" (a live dropdown of the store's actual
  category names). Shopify's is "Collection" (a live dropdown of the
  store's actual collections, which is exactly where a merchant-named
  collection like "Best sellers" would come from — not a distinct "source
  type" concept, just an ordinary collection name) —
  `app/routes/app.settings.tsx:945-980`. **Our actual builder**
  (`view/adminhtml/templates/config/rss-builder.phtml`, mirroring
  `Controller/Rss/Feed.php:55-73`) only accepts a raw numeric **Category
  ID** typed by hand — no live category-name lookup. Since real
  functionality is defined as "us + the sibling plugins" per this
  analysis's binding authority, and two siblings independently built a
  named-category/collection picker, **this is a legitimate follow-up
  feature candidate** (a live AJAX category-tree dropdown replacing the
  numeric-ID input), not a design-pack fiction to blanket-delete. Recording
  it here as a finding for a Linear follow-up ticket; **not implementing it
  in this analysis pass** (out of scope — this document maps what to
  expose and what to call it, not net-new engineering).

*(The "Store view" leak lives only in the design pack's HTML mockups, not in
our shipped templates — `git status`/PRO-1281 already replaced any earlier
design-pack-derived markup with the real, functionality-backed templates
read above, so there is no code to delete for it; this section exists so no
future pass re-introduces it from the design pack.)*

---

## Per-area functionality + text

### A. Connection

**(a) Option set (all confirmed — `view/adminhtml/templates/panel/connection.phtml`,
`etc/adminhtml/system.xml` group `connection`, `Model/Config/Source/MultilingualMode.php`):**

| Control | Where | Real functionality |
|---|---|---|
| Subdomain | Wizard step 1 / Settings > Connection / native config | `Backend\Subdomain` normalizes a pasted full URL to bare subdomain |
| API Username | same | plain text |
| API Password | same | `Magento\Config\Model\Config\Backend\Encrypted` |
| Test Connection | same | AJAX `smaily_connect/api/testsmaily`, tests as-typed credentials, no save |
| Multilingual routing mode (4 cards: Single / Per-language accounts / Per-language workflows / Single workflow branching) | shown only when store views span >1 detected language | drives which of the below renders; saved as `multilingual_mode` |
| Per-language account blocks (subdomain/username/password/Test per language) | mode A only | writes per-store-view `core_config_data` rows |
| Default fallback account picker | mode A only | which per-language account's credentials serve unmatched scope + becomes the default-scope credentials |

**(b) Canonical EST+ENG (source: our own `i18n/en_US.csv`/`et_EE.csv`, real and bilingual):**

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
| "If your Smaily URL is https://demo.sendsmaily.net, the subdomain is 'demo'. Pasting the full URL also works." | (shipped, see i18n row) |

Native-config-only comment text (`system.xml:29,54`, English only — no
separate i18n needed, Magento translates `system.xml` via `<label>`/
`<comment>` `translate` attributes and the module's own .csv already carries
these strings): "Connect your Smaily account. You can find API credentials in
Smaily under Preferences > API. Credentials can be overridden per website, or
per store view for per-language Smaily accounts." — confirmed present in
`i18n/et_EE.csv`.

**Sibling cross-check — multilingual mode wording (both siblings have the
identical 4-mode concept, and independently converged on near-identical
naming that differs from ours):**

| Mode | Ours (EN) | Woo (EN) [confirmed] | Shopify (EN) [confirmed] |
|---|---|---|---|
| single | Single language | (single-language sites see no cards at all) | "Single language — one account, one workflow per trigger." |
| A | Per-language Smaily accounts | "Separate Smaily accounts" — "One Smaily subdomain per language. Each language has its own subscriber list and credentials." | "Separate Smaily accounts" — "One Smaily subdomain per language. Each language has its own subscriber list and credentials." (verbatim same as Woo) |
| B | One account, per-language workflows | "One account, per-language automations" (default) | "One account, per-language automations" (badge "Recommended") — "One Smaily account, but a separate automation workflow per language. Most common setup." |
| C | One workflow branching by language | "One account, one automation with branches" | "One account, one automation with branches" — "A single Smaily workflow that branches on the contact's language inside Smaily." |

Woo and Shopify use **word-for-word identical** labels and near-identical
help text for all 4 modes (Shopify's own code comments confirm it was
"adapted from the Woo strings"). Per this analysis's binding rule (sibling
wording wins on conflict), this is a genuine, concrete **wording-
harmonization candidate**: rename our mode B/C labels to match
("One account, per-language automations" / "One account, one automation
with branches") and mode A to "Separate Smaily accounts". Not urgent — this
is existing, shipped, correctly-functioning, already-bilingual copy, so
treat as a future copy-pass item, not a blocking defect. Logged in the
harmonization table at the end of this document.

**(c) REMOVE:** none found specific to this area beyond the two global leaks
above (neither touches Connection).

**(d) Screenshot would help:** none for the mode-card wording (resolved by
the sibling table above). The **4-card routing-mode chooser layout** (how
the cards + the "adapt live" default-account vs per-language-account
sections visually relate) is still worth a screenshot of the real sandbox
render — STATUS.md's PRO-1281 entry documents Playwright coverage of
"choice-cards reactivity," so this is confirmation-only, not a real gap.

---

### B. Subscribers

**(a) Option set (confirmed — `panel/subscribers.phtml`, `etc/adminhtml/system.xml`
group `subscribers`, `Model/ContactSync/Mode.php`, `Model/Config/Source/SyncFields.php`,
`Controller/Checkout/Optin.php`, `Plugin/SuppressNewsletterEmails.php`):**

| Control | Where | Real functionality |
|---|---|---|
| Enable subscriber synchronization | Settings tab only (wizard implies "on") | master toggle, `sync_enabled` |
| Contact sync mode — 3 lawful-basis presets (radio cards) | wizard step 2 / Settings | `SyncMode`: consent / legitimate_interest / checkout_optin — who gets synced + whether Smaily unsubscribes mirror back (consent mode only, two-way) |
| Extra fields to sync (8 checkboxes: first name, last name, prefix, gender, birthday, customer ID, customer group, subscription type) | same | `SyncFields`; empty values omitted, never wipes existing Smaily data |
| Include Guest Order Emails | **native config only** | always on in checkout-opt-in mode |
| Automations May Re-Subscribe (Advanced) | **native config only**, `depends` on legitimate_interest mode | `force_opt_in` on automation triggers |
| Show newsletter checkbox at checkout | wizard/Settings + native config | `Controller/Checkout/Optin.php` persists the choice, creates a real Magento subscriber |
| Let Smaily send opt-in emails (suppress Magento's own) | wizard/Settings + native config | `Plugin/SuppressNewsletterEmails.php` suppresses success/unsubscribe mail only — never the double-opt-in confirmation request (that's Magento core's own setting, untouched) |
| Import your existing subscribers (backfill) | wizard step 2 / Settings > Subscribers | `smaily:backfill:start contacts`; live progress via `smaily_connect/api/backfillstate`; Cancel button while running |

**(b) Canonical EST+ENG (our own i18n, real+bilingual):**

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
| Import your existing subscribers | Impordi oma olemasolevad tellijad |
| Import subscribers to Smaily | Impordi tellijad Smailysse |
| Cancel import | Katkesta import |

**Sibling cross-check — strong confirmation, no changes needed:** both Woo
and Shopify [both confirmed] use the exact same three lawful-basis preset
labels, verbatim: **"Subscribers only (consent)"**, **"All customers
(legitimate interest)"**, **"Checkout opt-in only"** — identical to ours,
three-way independent convergence. This is the strongest wording-parity
result in the whole audit; no harmonization needed here. Help text differs
slightly in phrasing across all three (each platform's own consent/sync
mechanics differ enough that verbatim-identical help text wouldn't be
accurate), which is expected and fine.

The "Automations May Re-Subscribe (Advanced)" ↔ "Force opt-in on automation
triggers" wording-harmonization note is logged under the design-pack-leaks
section #1 above (both siblings independently use "Force opt-in on
automation triggers").

**Sync-fields divergence is expected, not a gap:** Woo's field set (first/
last name, phone, birthday, gender, customer group, customer ID, first
registered, nickname, site title) and Shopify's (first name, last name,
language/locale, customer ID) differ from ours (first/last name, prefix,
gender, birthday, customer ID, customer group, subscription type) because
each reflects that platform's own customer data model — our set is
explicitly "legacy 2.8.x field set kept for back-compat" per
`Model/Config/Source/SyncFields.php`'s own doc comment. No action needed.

**(c) REMOVE:** the global "Opt-in mode" (Double/Single) leak lands squarely
in this area — see design-pack-leaks section above. Nothing else.

**(d) Screenshot would help:** none — this area's code + our own shipped
strings are complete and unambiguous, and the sibling cross-check above
confirms the core wording independently.

---

### C. Automations

**(a) Option set (confirmed — `panel/automations.phtml`,
`config/engine-automations.phtml`, `etc/adminhtml/system.xml` group
`automations`, `Model/Config/Source/AbandonedFields.php`,
`ViewModel/Adminhtml/AutomationsForm.php`):**

Two distinct sub-systems share this area:

1. **Store-event automations** (welcome / first order / abandoned cart) —
   enable toggle + workflow-id select per trigger, all loaded live from the
   Smaily account:
   - Welcome — fires when someone becomes a subscriber
   - First order — fires on first purchase, carries `order_id`,
     `order_total`, `order_currency`, `is_first_order`
   - Abandoned cart — enable + workflow + **cutoff minutes** (10–1440,
     default 30) + **Abandoned Cart Product Fields** (7 checkboxes: name,
     description, image URL, SKU, quantity, price, base price) —
     product-fields checkbox set is **native config only**, not on the
     module's own Automations panel
   - In multilingual modes A/B: a **per-language workflow mapping table**
     per trigger (language / workflow select / default-fallback radio)
2. **Engine-run (Campaign Intelligence) automations** — a dynamic list
   fetched from the engine catalog, each row: Enabled + Test mode toggles,
   Smaily Workflow select, Cooldown (days), Daily Cap (blank = no cap), Test
   Emails (comma list). Every trigger starts **disabled and in test mode**.
   Rendered from `ViewModel\Adminhtml\AutomationsForm` and shown on BOTH the
   native config Automations group and the module's own Settings >
   Automations tab (same block, `engine.automations`).

**(b) Canonical EST+ENG (our own i18n):**

| EN | ET |
|---|---|
| Map store events to Smaily automations | Seo poe sündmused Smaily automaatikatega |
| Refresh workflows | Värskenda töövooge |
| Welcome — fires when someone becomes a subscriber | Tervitus — käivitub, kui keegi saab tellijaks |
| First order — fires on a customer's first purchase | Esimene tellimus — käivitub kliendi esimesel ostul |
| Abandoned cart — fires when a cart is left behind | Hüljatud ostukorv — käivitub, kui ostukorv jäetakse maha |
| Wait [N] minutes of inactivity before the cart counts as abandoned… | (shipped, see i18n row) |
| Campaign Intelligence Automations | Campaign Intelligence'i automaatikad |
| Connect Smaily to set up automations | Automaatikate seadistamiseks ühenda Smaily |
| Smaily Workflow | Smaily töövoog |
| Cooldown (days) | Puhkeaeg (päevades) |
| Daily Cap | Päevalimiit |
| Test Emails | Test-aadressid |
| Save Intelligence Automations | Salvesta Intelligence'i automaatikad |

**Sibling cross-check:** both Woo and Shopify [both confirmed] have the
identical trigger set — Woo: "Welcome email" / "First-order email" /
"Abandoned-cart reminder"; Shopify: "Welcome" / "First order" / "Abandoned
checkout" (Shopify names it "checkout," matching Shopify's own domain
concept — expected divergence, not a gap, since Magento's "cart"/"quote"
terminology is the platform-correct term for us and Woo). Cutoff bounds
match exactly: both siblings clamp to a 10-minute minimum, ours does too
(`system.xml:150`, `validate-digits digits-range-10-1440`); Shopify's exact
constants are `CUTOFF_MIN_MINUTES = 10` / default 30 — identical to ours.
Both siblings' engine-automations sub-section is architecturally identical
to ours: a dynamic per-trigger list with Enabled + Test-mode toggles,
workflow picker, Cooldown (days, 1–365), and test-mode messaging — **and
both confirm the trigger name/description text comes from the engine's own
catalog API response, not the plugin's own i18n**, exactly matching our
`AutomationsForm`'s `localized()` picking `name_et`/`name_en` from the
engine catalog (PRO-1292). Strong architecture-parity confirmation, no
changes needed.

One wording difference worth noting (non-blocking): our field is "Test
Emails"; Shopify's is "Test addresses" with explicit help text ("Comma-
separated, up to 50 addresses.") and a proactive warning when enabled +
test mode + no addresses are set ("no emails will be sent to anyone until
you add at least one address"). Not a functionality gap (ours already
requires/uses test emails when in test mode), but the explicit "nothing
will send" warning is a nice UX touch worth a follow-up consideration — not
a wording change, a possible future safety-copy addition.

**(c) REMOVE:** none area-specific.

**(d) Screenshot would help:** the **engine-trigger card states**
(off/test/active pills + the dimmed-rows-under-warning-banner degraded
state when the catalog fails to load) — STATUS.md documents this was
already Playwright-verified in both locales, so this is low priority, but a
screenshot would confirm the final visual matches what Erkki expects for
"nothing reaches real customers without your explicit action" messaging.

---

### D. Campaign Intelligence

**(a) Option set (confirmed — `panel/intelligence.phtml`,
`etc/adminhtml/system.xml` group `intelligence`,
`Model/Config/Backend/EngineSetupToken.php`, `Controller/Adminhtml/Api/EngineExchange.php`):**

| Control | Where | Real functionality |
|---|---|---|
| Setup URL or token | wizard step 4 / Settings > Intelligence / native config | one-time exchange, never stored, `EngineExchange` controller |
| Connect button | same | AJAX exchange + tenant/version status |
| Storefront browse tracking | wizard step 4 (post-connect) / Settings / native config | off by default, respects cookie restriction mode, relayed server-side |
| Sync catalog / Sync customers / Sync orders | **Settings tab only** (not in wizard — wizard implies sync starts automatically post-connect) + native config | per-entity toggle for the live sync observers |
| Historical imports: catalog / customers / orders | **Settings tab only** | `smaily:backfill:start catalog\|customers\|orders`; live progress, Cancel |

**(b) Canonical EST+ENG (our own i18n):**

| EN | ET |
|---|---|
| Campaign Intelligence (optional) | Campaign Intelligence (valikuline) |
| Setup URL or token from Smaily | Smailylt saadud seadistus-URL või -võti |
| Connect | Ühenda |
| Enable storefront browse tracking (product views, searches, cart activity) | Luba poe sirvimise jälgimine (tootevaatamised, otsingud, ostukorvitegevus) |
| Sync catalog changes to the engine | Sünkrooni kataloogimuudatused mootorisse |
| Sync customer changes to the engine | Sünkrooni kliendimuudatused mootorisse |
| Sync order changes to the engine | Sünkrooni tellimusemuudatused mootorisse |
| Historical imports to Campaign Intelligence | Ajaloolised impordid Campaign Intelligence'i |
| Import catalog / Import customers / Import orders | Impordi kataloog / Impordi kliendid / Impordi tellimused |

**Sibling cross-check — worth Erkki's attention, not a REMOVE:** Woo's code
carries an explicit comment that per-domain sync toggles for
orders/customers/products **were removed** in Woo v3.9:
*"connecting the rec-engine syncs all domains unconditionally
(products/customers/orders fire while is_connected()). The per-domain sync
toggles were removed — only the browse-tracking preference... is persisted
here."* Shopify never had per-domain toggles at all — its Data
synchronisation card states plainly "all three sync automatically," no
toggle. **So both siblings have converged on "connect = everything syncs,
no per-entity opt-out," while our module still exposes three separate Sync
Catalog / Sync Customers / Sync Orders toggles** (real, wired to the live
sync observers, not a leak). This is a genuine product-shape question, not
a wording one — flagged here as a finding for Erkki to weigh in on (queued
in STATUS.md-style "question for Erkki," not auto-applied): keep our
per-entity toggles (more granular control, arguably useful for a merchant
who wants catalog-only sync) or follow siblings' simplification
(connect = sync everything, remove the toggles)? Not resolved by this
analysis — this document only maps what's real and what siblings do, it
doesn't decide product scope.

Setup-token connect wording matches closely: Woo "Connect Smaily Campaign
Intelligence" / "Paste the setup URL from your Smaily admin…"; Shopify
"Connect Campaign Intelligence" (button) / "Paste the one-time setup link…
It can be used exactly once." Browse-tracking concept and off-by-default
stance match across all three exactly.

**(c) REMOVE:** none area-specific.

**(d) Screenshot would help:** none — the sibling text above (both
confirmed) covers this area well enough without a visual reference.

---

### E. RSS (product feed)

**(a) Option set (confirmed — `panel/rss.phtml`, `config/rss-builder.phtml`,
`etc/adminhtml/system.xml` group `rss`, `Controller/Rss/Feed.php`):**

| Control | Where | Real functionality |
|---|---|---|
| Enable the product RSS feed | Settings > RSS + native config | per store view |
| Category ID (optional, numeric) | URL builder (Settings > RSS + native config) | filters feed to one category |
| Number of products (1–250, default 50) | same | `limit` param |
| Sort by (created_at / updated_at / name / price) | same | `sort` param |
| Sort order (asc/desc) | same | `order` param |
| Copy button | same | clipboard API + `execCommand` fallback for non-HTTPS admin |
| "Advanced RSS options in Stores > Configuration" deep link | Settings > RSS only | opens the native RSS config group, auto-expanded/scrolled (PRO-1281 carry-over) |

**(b) Canonical EST+ENG (our own i18n):**

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

**(c) REMOVE:** the "Store view" selector design-pack leak — see
design-pack-leaks section above; no sibling or our own code backs a
store-view dropdown inside the builder widget.

**Sibling cross-check (both confirmed real, both differ from our numeric-ID
approach):** Woo's builder has **Product category** (named dropdown),
**Limit**, **Sort by**, **Order**, and a **Tax rate %** field we don't have
at all; Shopify's has **Collection** (named dropdown), **Limit**, **Sort
by** (no separate Order field — sort direction is baked into each sort
option). Both siblings' feed is also opt-in-by-installation rather than a
global per-store-view toggle — our "Enable the product RSS feed" checkbox
is Magento-appropriate (multi-store-view scoping) and not a leak, just a
platform-native design without sibling equivalent.

**Follow-up feature candidate (not a leak, not in scope here):** a live
named-category picker (replacing the raw numeric Category ID input) would
bring us to sibling parity — see design-pack-leaks section #2 above for the
full reasoning. A Tax rate % field is Woo-only (WooCommerce prices are
often tax-exclusive in the underlying data; Magento prices returned by our
feed are already tax-included per `docs/USER_GUIDE.md`), so that one is not
a gap for us.

**(d) Screenshot would help:** the Woo/Shopify builder's named-category-vs-
numeric-ID difference is well enough described by the sibling agents'
citations that a screenshot isn't necessary to decide whether to build it —
only to design it, if/when a follow-up ticket picks it up.

---

### F. Dashboard

**(a) Option set — this is a read-only operational page, not a settings
form (confirmed — `dashboard/index.phtml`, `ViewModel\Adminhtml\DashboardData`):**

| Element | Real functionality |
|---|---|
| Verdict hero (incomplete / needs-attention-failures / needs-attention-engine-down / all-normal) | priority-ordered from real state: setup completeness, `getFailedLast24h()`, `isEngineDown()` |
| Connection strip (3 rows: Smaily, Campaign Intelligence, Browse tracking) | live connected/off/failed/pending pills from `isSmailyConnected`, `isEngineConnected`/`isEngineDown`, `isBrowseTrackingEnabled` |
| Metric tiles (Contact syncs delivered 30d, Catalog items delivered 30d [only if engine connected], Queued today, Failed 24h) | real local queue queries |
| Recent activity table (last 10 queue rows) | Source / Type / Entity / Status / Updated |
| Quick links | Settings, Log, Setup Wizard, Stores > Configuration |

**(b) Canonical EST+ENG (our own i18n):**

| EN | ET |
|---|---|
| Recent activity | Viimane tegevus |
| Quick links | Kiirlingid |
| Settings / Log / Setup Wizard | Seaded / Logi / Seadistusviisard |
| Source / Type / Entity / Status | Allikas / Tüüp / Kirje / Olek |
| "Setup is not finished yet — complete the setup wizard to start syncing." | (shipped, see i18n row) |
| "Smaily Connect is running, but %1 delivery(ies) failed in the last 24 hours." | (shipped) |
| "Everything is running — deliveries to Smaily are flowing normally." | (shipped) |

**Sibling cross-check — split result, Shopify is real parity, Woo has no
ongoing dashboard:**
- **Woo** [confirmed]: no ongoing Dashboard tab/page exists at all. The
  only comparable thing is the wizard's own final "Done" step summary card
  ("What's active" — connection status, sync/backfill counts, automation
  mapping counts), which is **wizard-only** (`inSettings` hides it) and
  disappears once setup is complete — there is no persistent operational
  dashboard on the ongoing Settings surface.
- **Shopify** [confirmed] — real, close parity to ours: a genuine ongoing
  Dashboard (`app/routes/app._index.tsx`) with a health-verdict hero,
  connection strip, 4 metric tiles, and a recent-activity feed — the same
  shape as ours. Verdict states: **"Setup incomplete"** (identical wording
  to ours), **"Running — with failures to review"** (ours: "Needs
  attention"), **"Everything's running"** (ours: "All systems normal").
  Metric tiles: "Customers synced" / "Events today" / "Catalog synced" /
  "Failed (24h)" (ours: Contact syncs / Catalog items / Queued today /
  Failed). Close conceptual match, different wording in the failure/
  all-clear states — a **wording-harmonization candidate** (logged in the
  table at the end of this document), not urgent.

**(c) REMOVE:** none — this page has no design-pack-only controls (it's
read-only, nothing to "leak" as a fake setting).

**(d) Screenshot would help:** none — Shopify's dashboard text above
(confirmed) is sufficient for a wording comparison; a visual screenshot
would only matter if a future pass decides to harmonize the tile/verdict
layout itself, which isn't proposed here.

---

### G. Log

**(a) Option set (confirmed — `smaily_log_grid.xml`, `log/details.phtml`,
`log/failed-banner.phtml`, `Controller/Adminhtml/Log/*`):**

| Element | Real functionality |
|---|---|
| Grid columns: Source, Type, Entity, Status, Attempts, Last Error, Created, Updated, Actions | `Ui\Component\LogSourceOptions`/`QueueStatusOptions`; union of two queue tables via synthetic `log_id` |
| Mass action: Retry | `Controller/Adminhtml/Log/MassRetry` — re-queues selected failed rows |
| Failed-24h banner | zero-state hidden; links to grid pre-filtered `status=failed` |
| Details slide-out | status pill, attempts (`N of MAX_ATTEMPTS`), created/updated, honest retry line (5 states: sent/sending/failed-terminal/scheduled-retry/waiting-for-flush), last error (redacted, tagged), payload as-sent or queued (redacted), last response (redacted) |
| PII redaction | `Model\Log\PayloadRedactor` — secrets never shown, emails masked, applied to error/payload/response |

**(b) Canonical EST+ENG (our own i18n):**

| EN | ET |
|---|---|
| Source / Type / Entity / Status / Attempts / Created / Updated / Last Error / Actions | Allikas / Tüüp / Kirje / Olek / Katsed / Loodud / Uuendatud / Viimane viga / Tegevused |
| Retry (mass action) | Proovi uuesti |
| "All %1 automatic attempts are used up — this row will NOT retry on its own. Select it in the log and press Retry to queue it again." | (shipped, see i18n row) |
| "%1 events failed in the last 24 hours" | (shipped) |
| "Sensitive values (passwords, API keys) are never shown here, and email addresses are masked." | (shipped) |

**Sibling cross-check:** both Woo and Shopify [both confirmed] have the same
shape — a filterable event/log table (source, status filters), a failed-
count banner ("N event(s) failed in the last 24 hours" — Shopify's wording
is close to ours), a details modal with Source/Status/Entity/Attempts/
error/response fields, and a Retry action. Shopify's retry is scoped
honestly the same way ours is (re-queues pending/failed ingest rows, cannot
replay an already-sent delivery) — same underlying constraint, same honest
framing. No wording conflicts worth harmonizing; column/field naming is
close enough across all three that no change is warranted.

**(c) REMOVE:** none.

**(d) Screenshot would help:** none — STATUS.md confirms Playwright already
verified the Details pill/redaction tags/retry line visually in both
locales, and the sibling text above confirms shape parity without needing a
visual.

---

### H. Backfill (historical import)

Not a standalone screen — embedded UI in two places, both driven by the
same `panels-js.phtml` `startBackfill`/`pollBackfill`/`renderBackfill`
machinery and the same `smaily_connect/api/backfillstate` endpoint:
1. Subscribers → Smaily: Settings > Subscribers tab / wizard step 2
   (`panel/subscribers.phtml`, one job: `contacts`)
2. Catalog / Customers / Orders → Campaign Intelligence: Settings >
   Intelligence tab only (`panel/intelligence.phtml`, three jobs)

**(a) Option set (confirmed):** Import button, Cancel import button (shown
only while running), progress bar (`.smaily-progress`, striped while
running), one of 5 terminal/live states.

**(b) Canonical EST+ENG (our own i18n) — the 5 states are the load-bearing
copy here:**

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

**Sibling cross-check:** both Woo and Shopify [both confirmed] use the same
terminal-state vocabulary — Woo: "Done — %1$d contacts synced (%2$d users
checked)." / "Backfill failed: %s" / "Backfill cancelled. Re-run when
ready." / "Last run finished %s."; Shopify: "Done — {N} synced." /
"Stopped — {N} processed before an error." / "Cancelled — {N} processed." /
"Syncing… {N} so far." Same shape as ours (done / stopped-before-error /
cancelled / in-progress, with a persisted last-run summary) — strong
architecture-parity confirmation, no wording harmonization needed, our
phrasing is already at least as clear (we additionally distinguish
"completed with N failed" from "stopped before an error," which neither
sibling's cited strings appear to separate as explicitly).

**(c) REMOVE:** none.

**(d) Screenshot would help:** the design pack's `Backfill.dc.html`
component demo — worth a glance to confirm the `.smaily-progress` states
(running/done/done-with-failures/stopped/cancelled → striped-accent /
success / danger / neutral) visually match what's shipped; STATUS.md
already claims this was Playwright-verified for at least the contacts
backfill, so this is low priority.

---

### I. Setup Wizard

**(a) Option set:** the 5-step shell (Connect → Subscribers → Automations →
Intelligence → Done) wrapping panels A–D above verbatim (`getChildHtml`
shares the exact same partials as the Settings tabs) plus wizard-only
chrome: stepper with done/active states, Back/Continue/Finish footer
buttons, and the step-5 "Done" summary card (confirmed —
`wizard/index.phtml`).

**(b) Canonical EST+ENG (our own i18n):**

| EN | ET |
|---|---|
| Connect / Subscribers / Automations / Intelligence | Ühendus / Tellijad / Automaatikad / (Intelligence — untranslated brand term, confirmed intentional, see i18n row) |
| Done (step label) | **[i18n gap — see note]** |
| You are all set! | Kõik on valmis! |
| Back | (shipped) |
| Continue / Finish | (shipped) |

**Minor i18n gap found (unrelated to design-pack leaks, worth a fast
follow-up):** the standalone step-5 label `__('Done')` in
`wizard/index.phtml:32` has **no entry at all** in either `i18n/en_US.csv`
or `i18n/et_EE.csv` (only the unrelated compound strings "Done, %1 of %2
synced." etc. exist). In an Estonian admin session this one word falls back
to the English source string "Done" instead of a translation (e.g.
"Valmis"). Trivial one-line i18n fix, flagged here since this analysis pass
surfaced it; not a design-pack leak, just a generation-tooling miss.

**(c) REMOVE:** none area-specific (the wizard reuses panels A–D verbatim,
so their REMOVE items apply here too — see Subscribers/RSS above; RSS isn't
in the wizard at all, so only the Subscribers opt-in-mode leak is
wizard-relevant, and it was never actually built into `panel/subscribers.phtml`).

**Sibling cross-check:** both siblings have a first-run wizard of the same
shape — Woo: 6 steps (React SPA, first-run gate redirects to it until
`smly_plus_setup_completed`); Shopify: 6 steps (`app.wizard.tsx`, same
underlying forms/actions as its Settings tabs — architecturally identical
pattern to ours: wizard and Settings share the same components). Step names
line up (Connect, Subscribers, an automations step, an intelligence step, a
final Done/summary step) — no wording conflicts beyond the mode-card labels
already covered under Connection above. One feature note (not a wording
item, not proposed for this pass): Shopify's final step has a live "What's
active" checklist (connection status, sync/backfill counts, per-automation
mapped-workflow counts) before landing on next steps — richer than our
current "Done" step's static links list. Worth a follow-up consideration,
not an urgent gap.

**(c) REMOVE:** none area-specific (the wizard reuses panels A–D verbatim,
so their REMOVE items apply here too — see Subscribers/RSS above; RSS isn't
in the wizard at all, so only the Subscribers opt-in-mode leak is
wizard-relevant, and it was never actually built into `panel/subscribers.phtml`).

**(d) Screenshot would help:** the **stepper's done/active visual states**
across a full run (STATUS.md documents Playwright coverage of "stepper +
choice-cards" already, so this is confirmation-only, not a real gap).

---

## Sibling cross-check — summary and wording-harmonization candidates

Both sibling passes are folded into the per-area sections above (each has
its own "Sibling cross-check" subsection with file:line citations from the
sibling agents). Top-line results:

1. **Architecture is one custom admin surface in both siblings** (Woo
   deliberately removed a second, native one; Shopify's platform never had
   one) — informs Q2 above. Our two-surface model is justified by a real
   Magento-specific capability (scoped config) neither sibling's platform
   offers, not by inertia.
2. **The "double opt-in / single opt-in" design-pack leak is refuted by
   both siblings independently** — neither has ever built such a setting;
   the nearest real concept in all three products is the Smaily-side
   "force opt-in on automation triggers" toggle, a different, narrower
   mechanism. Strongest possible confirmation for the REMOVE list.
3. **The RSS builder's named-category/collection picker is real in both
   siblings** — reclassified from "design-pack leak" to "follow-up feature
   candidate" (our current numeric-ID input is functionally complete but
   less friendly than either sibling's).
4. **The three lawful-basis sync-mode labels are verbatim-identical across
   all three products already** — no action needed, strongest positive
   confirmation in the audit.
5. **Woo's admin UI is genuinely bilingual (its own `.po` file, ~538
   msgids, effectively fully translated); Shopify's admin UI is English-
   only with no i18n mechanism at all** (confirmed by an explicit code
   comment in `engine-automations.tsx`). Our own module is bilingual
   (`i18n/en_US.csv`/`et_EE.csv`, 405 lines, ~98% genuinely distinct
   Estonian translations) — **better ET coverage than Shopify's own admin,
   on par with Woo's.** Where sibling Estonian wording is cited above, it
   is Woo's (Shopify has none to cite; its "ET" text quoted in a couple of
   agent findings, where present, comes from Shopify's separate public
   `/docs` help guide, not its admin UI, and is noted as reference-only).

### Wording-harmonization candidates (non-blocking — future copy pass, not part of this analysis's scope to apply)

These are cases where our shipped, working, bilingual copy differs from
wording that two — or in the multilingual-mode case, both — siblings
independently converged on. Per this analysis's binding rule ("sibling
wording wins on conflict"), they are logged here as the reconciliation
candidates that rule implies; none are proposed for immediate application,
since that would mean editing 405-entry i18n files and re-running the full
verification chain outside this analysis-only task's remit.

| Control | Current EN (ours) | Sibling wording (wins per rule) | Source |
|---|---|---|---|
| Multilingual mode A | "Per-language Smaily accounts" | "Separate Smaily accounts" | Woo + Shopify (verbatim identical) |
| Multilingual mode B | "One account, per-language workflows" | "One account, per-language automations" | Woo + Shopify (verbatim identical) |
| Multilingual mode C | "One workflow branching by language" | "One account, one automation with branches" | Woo + Shopify (verbatim identical) |
| Legitimate-interest re-subscribe toggle | "Automations May Re-Subscribe (Advanced)" | "Force opt-in on automation triggers" | Woo + Shopify (verbatim identical) |
| Dashboard verdict — failures state | "Needs attention" | "Running — with failures to review" | Shopify (only sibling with a real dashboard) |
| Dashboard verdict — all-clear state | "All systems normal" | "Everything's running" | Shopify (only sibling with a real dashboard) |
| Engine-automation test recipient field | "Test Emails" | "Test addresses" | Shopify |

### Product-shape questions surfaced (not wording — for Erkki, not auto-applied)

- **Per-entity Intelligence sync toggles** (Sync Catalog / Sync Customers /
  Sync Orders): both siblings have moved to "connect = sync everything, no
  per-domain toggle." Keep our more granular toggles, or simplify to match?
  See Campaign Intelligence area above.
- **RSS named-category/collection picker**: both siblings have one; we
  have raw numeric Category ID only. Follow-up feature candidate, not
  required by this analysis.
- **Wizard "What's active" live summary** (Shopify): richer final-step
  recap than our static links list. Follow-up UX idea, not required.
