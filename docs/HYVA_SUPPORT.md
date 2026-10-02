# Hyvä Theme Support

Status: **verified on a real Hyvä store.** The verification matrix below was
executed against Hyvä **1.5.2** (`hyva-themes/magento2-default-theme`,
Tailwind v4) and against the strict-CSP variant
(`hyva-themes/magento2-default-theme-csp` with storefront CSP *enforced* and
`unsafe-inline` removed from `script-src`), side by side with a Luma store
view as the regression control — every cell passed. The compat module
(`compat/hyva/`, `Hyva_SmailyConnect`) works as designed; one latent bug it
surfaced in the base module (personalization page vs full-page cache) is
fixed. Remaining before release: package publication (vendor/name decision)
and the Hyvä Checkout boundary confirmation — see "Open release decisions".

[Hyvä](https://www.hyva.io/) replaces Magento's Luma frontend stack
(RequireJS, jQuery, Knockout, `x-magento-init`) with Alpine.js 3 + Tailwind
CSS and is free/open-source since November 2025 (OSL-3.0; free license keys
via the Hyvä portal, source at
[github.com/hyva-themes](https://github.com/hyva-themes)). The admin is
untouched by themes, so only the storefront surfaces matter. Target: Hyvä
1.4+ (Tailwind v4).

This document is the audit of every Smaily Connect storefront surface,
what the compatibility module covers, and the executed verification
results.

## Storefront surface audit

Verdicts: **WORKS-AS-IS** (no change needed), **NEEDS-COMPAT-TEMPLATE**
(delivery/markup swap, logic unchanged), **NEEDS-JS-PORT** (real code
changes), **OUT-OF-SCOPE**.

| Surface | Evidence | Verdict |
|---|---|---|
| Browse tracker | `view/frontend/web/js/tracker.js:16` — AMD `define(['jquery', …])`; `:91` — `$.ajax` beacon fallback; `:148` — listens to Luma-only jQuery event `ajax:addToCart` (Hyvä documents no add-to-cart JS event); `view/frontend/templates/engine/tracker.phtml:16` — `x-magento-init` bootstrap (needs `Magento_Ui/js/core/app` runtime Hyvä doesn't load). Core batching/consent logic is framework-free. | **NEEDS-JS-PORT** |
| Attribution script | `view/frontend/web/js/attribution.js:11` — `define([], …)`: zero dependencies, pure vanilla inside, but AMD-wrapped; `view/frontend/templates/engine/attribution.phtml:16` — `x-magento-init` bootstrap. Logic ports 1:1. | **NEEDS-COMPAT-TEMPLATE** |
| Product/static page-context blocks | `view/frontend/templates/engine/context/product.phtml:21` and `context/static.phtml:23` — one-line inline `window.smailyPageContext` scripts rendered via `SecureHtmlRenderer::renderTag()`. No JS framework involved; CSP whitelisting happens in `Magento_Csp` (DI preference on the renderer), independent of the active theme, so it also holds under Hyvä stores running strict CSP. Blocks attach to `before.body.end`, which Hyvä's `default.xml` keeps. | **WORKS-AS-IS** (verified) |
| Checkout newsletter opt-in | `view/frontend/web/js/view/checkout/newsletter-optin.js:6-10` — `uiComponent`/`ko` component injected via a `LayoutProcessor` plugin (`Plugin/Checkout/AddNewsletterOptinToLayout.php`). Free Hyvä uses the **Luma-fallback checkout**: the checkout route switches to a Luma-based theme where RequireJS/Knockout load normally, so the component runs unchanged. | **WORKS-AS-IS** on Luma-fallback checkout (verified) |
| Guest email capture for abandoned carts | `view/frontend/web/js/view/form/element/email-mixin.js` — a RequireJS mixin on Magento's checkout email component (`view/frontend/requirejs-config.js`). The Luma-fallback checkout builds its RequireJS configuration from every module's frontend `requirejs-config.js` and runs Magento's own checkout JS, so the mixin applies there; on Hyvä's own pages RequireJS does not load and the mixin is never requested. | **WORKS-AS-IS** on Luma-fallback checkout (by construction, not verified on a Hyvä store) |
| Checkout opt-in and guest email capture on Hyvä Checkout | Hyvä Checkout is a separate commercial product with its own component system (Magewire), a different integration surface entirely. | **OUT-OF-SCOPE** (see boundary below) |
| My Account personalization page | `view/frontend/templates/privacy/form.phtml` — plain HTML POST form, zero JS (the Hyvä twin mirrors its known / could-not-load states); `view/frontend/layout/smaily_privacy_index.xml:13` — standard `content` container; `customer_account.xml:12` — nav entry via `Block\Account\PersonalizationLink` (a core `SortLink` that renders nothing without live Campaign Intelligence), which Hyvä's account navigation renders. Functional as-is; only the Luma CSS classes (`fieldset`/`legend`/`actions-toolbar`) render unstyled. | **WORKS-AS-IS** functionally; **NEEDS-COMPAT-TEMPLATE** for styling |
| Native newsletter block | No template override in this module; subscription is consumed server-side (`Observer/SubscriberSaveAfter.php` on `newsletter_subscriber_save_after`). Hyvä ships its own newsletter form template posting to the same core controller. | **WORKS-AS-IS** (verified) |
| RSS product feed | `Controller/Rss/Feed.php` — server-rendered XML, no frontend assets. | **WORKS-AS-IS** |
| Abandoned-cart restore link | `Controller/Cart/Restore.php` — server-side redirect, no frontend assets. | **WORKS-AS-IS** |

## What the compat module provides (`compat/hyva/`)

Module `Hyva_SmailyConnect`, composer `smaily/module-connect-hyva` (the
module name keeps the Hyvä compat-module naming convention; the composer
package is published under Smaily's own vendor namespace — decided, see
"Open release decisions"). It lives in this repository and will eventually
be published as its own package. It is excluded from the release ZIP artifact
but kept in the composer package on purpose: it is inert there — nothing
loads `compat/hyva/registration.php` — while enabling installation via a
path repository until the separate package exists (see
`compat/hyva/README.md`).

- `view/frontend/layout/hyva_default.xml` — `hyva_`-prefixed handle (loads
  only when a Hyvä theme is active) swaps the attribution and tracker
  templates on the blocks defined by the base module.
- `view/frontend/templates/engine/{attribution,tracker}.phtml` — bootstrap
  without `x-magento-init`: config in an inert
  `<script type="application/json">` block + a static same-origin JS file
  loaded with `defer`. No inline executable script at all, so nothing needs
  CSP whitelisting even under strict CSP.
- `view/frontend/web/js/smaily-attribution.js` — 1:1 vanilla port of the
  attribution module (AMD wrapper removed; exposes
  `window.smailyAttribution` for the tracker, mirroring the old AMD
  dependency).
- `view/frontend/web/js/smaily-tracker.js` — jQuery-free tracker port:
  `fetch(keepalive)` replaces the `$.ajax` fallback; `cart_add` is captured
  from the `checkout/cart/add` form submit (capture phase + immediate
  beacon flush) because Hyvä's default add-to-cart is a regular form POST
  and no add-to-cart JS event is documented. Semantic difference vs Luma:
  the event fires on the attempt, not on confirmed success — acceptable
  for a loss-tolerant popularity signal. Verified on Hyvä 1.5.2 (stock PDP
  form POST, with sku from the page context). Known remaining gap:
  third-party AJAX-add-to-cart modules that call `form.submit()`
  programmatically (fires no `submit` event) or replace the form bypass
  this capture; if a store reports missing `cart_add` events, add a
  `private-content-loaded` cart-diff listener as the success-side signal.
  The consent gate is the base tracker's (override, then Magento's cookie
  notice, otherwise none; no event and no session cookie without consent);
  consent given later on the page is picked up from Hyvä's cookie notice
  event `user-allowed-save-cookie` (window) instead of Luma's jQuery
  `user:allowed:save:cookie`, and from `smaily:consent-changed`. The event
  name is the one Hyvä's cookie notice dispatches when the shopper accepts:
  `Magento_Cookie/templates/notices.phtml`, `setAcceptCookies()`, sets
  the `user_allowed_save_cookie` cookie and then calls
  `window.dispatchEvent(new CustomEvent('user-allowed-save-cookie'))` —
  the same in `hyva-themes/magento2-default-theme` 1.4.0 and 1.5.2 and in
  `magento2-default-theme-csp` 1.5.2 (public sources, read 2026-10-03).
  Checked with the real script in a browser harness with stubbed globals;
  not yet re-run on a Hyvä store.
- `view/frontend/layout/hyva_smaily_privacy_index.xml` +
  `templates/privacy/form.phtml` — Tailwind-styled personalization form
  (same behaviour and translated phrases; classes only).
- `etc/frontend/events.xml` + `Observer/RegisterModuleForHyvaConfig.php` —
  registers the module for `bin/magento hyva:config:generate`
  (`app/etc/hyva-themes.json`) so the theme's Tailwind content scan picks
  up these templates.

No new user-facing phrases were introduced: the compat templates reuse the
exact strings already present in `i18n/en_US.csv` / `i18n/et_EE.csv`, which
apply globally at runtime.

## Verification environment (no portal key needed)

The matrix was executed in the repo's docker sandbox with Hyvä installed
from the public GitHub sources (the theme is OSL-3.0 / free since November
2025 — a portal license key only adds their private Packagist repo, which
also carries the commercial compat modules). Recipe, reproducible from
scratch:

1. Add composer VCS repositories for every needed `hyva-themes` GitHub repo
   — the theme's dependency closure is larger than the obvious four:
   `magento2-theme-module`, `magento2-default-theme`,
   `magento2-base-layout-reset`, `magento2-email-module`,
   `magento2-graphql-tokens`, `magento2-graphql-view-model`,
   `magento2-order-cancellation-webapi`, `magento2-mollie-theme-bundle`,
   `magento2-compat-module-fallback` (required by
   `mollie/magento2-hyva-compatibility`, which comes from public Packagist)
   and `magento2-theme-fallback` (for the Luma-fallback checkout). Use
   `"no-api": true` on each repository so composer clones over plain git —
   the GitHub-API driver hits the unauthenticated rate limit immediately.
2. `composer require hyva-themes/magento2-default-theme:~1.5.2
   hyva-themes/magento2-theme-fallback:*`, enable the new modules,
   `setup:upgrade`, `setup:di:compile`.
3. Register `Hyva_SmailyConnect`: in the sandbox (module bind-mounted at
   `app/code/Smaily/Connect`) a symlink is enough —
   `ln -s app/code/Smaily/Connect/compat/hyva app/code/Hyva/SmailyConnect`
   (the project autoloader's `psr-0 "": app/code/` covers the classes);
   real stores install it as a composer package (see
   `compat/hyva/README.md`). Then `bin/magento module:enable
   Hyva_SmailyConnect && bin/magento setup:upgrade`.
4. `bin/magento hyva:config:generate` (our registration observer adds the
   module to `app/etc/hyva-themes.json`), then build the theme CSS with
   Node 20+: `npm ci && npm run build` in
   `vendor/hyva-themes/magento2-default-theme/web/tailwind`.
5. Assign `Hyva/default` to the store view (`design/theme/theme_id`),
   keep another store view on Luma as the regression control. Enable the
   Luma-fallback checkout: `hyva_theme_fallback/general/enable=1`,
   `theme_full_path=frontend/Magento/luma`,
   `list_part_of_url={"_1":{"path":"/checkout"}}`.
6. Strict-CSP variant: `composer require
   hyva-themes/magento2-default-theme-csp:dev-main` (same Tailwind build in
   its own `web/tailwind`), assign `Hyva/default-csp`, set
   `csp/mode/storefront/report_only=0` **and**
   `csp/policies/storefront/scripts/inline=0` (removes `unsafe-inline`
   from `script-src`; inline scripts then run only via Magento's
   SecureHtmlRenderer hash/nonce whitelisting).

### Verification matrix — executed 2026-07-12, Hyvä 1.5.2 / Magento 2.4.8-p4

Luma column = the `et` store view on `Magento/luma` re-run with the Hyvä
packages installed and `Hyva_SmailyConnect` enabled, proving the compat
module changes nothing when the Hyvä theme is not active. All rows
Playwright-driven with the mock engine as the receiving end; browse events
were confirmed received by the engine (`ingest/browse`), not just relayed.

| Surface | Luma (regression) | Hyvä | Hyvä + strict CSP |
|---|---|---|---|
| Attribution: campaign-click landing sets cookies (FPC page) | pass | pass | pass |
| Tracker: product_view / search / checkout_start / checkout_complete reach the relay | pass | pass | pass (product_view, search) |
| Tracker: cart_add (Luma `ajax:addToCart`; Hyvä PDP form-submit capture) | pass | pass (sku from page context) | pass |
| Tracker: consent — without consent (cookie restriction mode on and the cookie notice not accepted; or cookie restriction mode off and no consent override) no browse event is sent, so no visitor token reaches the engine, and no session cookie is written; accepting the cookie notice starts tracking on the same page (Luma: jQuery `user:allowed:save:cookie`; Hyvä: window `user-allowed-save-cookie`, see `smaily-tracker.js` above) | not re-run on a real store since the consent gate (browser harness with the real script: pass); the earlier pass covered the behaviour it replaced | not re-run on a Hyvä store since the consent gate (browser harness with the real script: pass; event name checked against the Hyvä sources); the earlier pass (off / on-without / on-with) covered the behaviour it replaced | not re-run (same code path as the Hyvä column) |
| Page-context blocks render + execute (SecureHtmlRenderer) | pass | pass | pass (hash-whitelisted, zero CSP violations) |
| Checkout opt-in checkbox (Luma-fallback checkout; toggle persists, order placed) | pass | pass (checkout + success render `Magento/luma` via theme fallback) | n/a (the fallback checkout renders Luma; a no-inline CSP across Luma is a store-wide theme decision, not a module surface — Magento's default enforced checkout CSP was verified in the Luma pass) |
| Personalization page: nav link, Tailwind styling, save + persist | pass (after the FPC fix below) | pass (computed styles confirm the Tailwind classes resolved) | pass by construction (plain HTML form, zero scripts) |
| Newsletter form subscribe fires our observers (contact.sync + welcome automation enqueued) | pass (earlier pass) | pass (Hyvä's own Alpine form → core controller) | n/a (server-side) |
| RSS feed | pass (earlier pass) | n/a (no theme surface) | n/a |
| Tailwind build includes compat templates after `hyva:config:generate` | n/a | pass (`@source` entry present; computed 24px padding / `btn-primary` styles on the rendered form) | pass (same, CSP theme build) |
| `setup:upgrade` + `setup:di:compile` with `Hyva_SmailyConnect` enabled | pass | pass | pass |

Zero console errors from our code in every run. One **third-party** noise
finding, deliberately left alone: Hyvä's message-toast auto-dismiss throws
a benign `Transition was skipped` pageerror (an interrupted Alpine
transition); it reproduces with every Smaily asset blocked, so it is the
theme's, not ours.

### Bug found by this pass (fixed in the base module)

The personalization page (`smaily/privacy`) was **full-page cacheable**: on
cacheable pages Magento *depersonalizes* the customer session during
rendering, so `PrivacyForm::isProfilingAllowed()` saw a logged-out session
and the checkbox always rendered the default (checked) — a saved opt-out
never showed on the page, and the cached copy would be shared across
customers in the same FPC vary group. Theme-independent (Luma had it too);
fixed with `cacheable="false"` on the form block in
`smaily_privacy_index.xml`, the same pattern every core My Account page
uses.

## Hyvä Checkout boundary

**Decision needed before release:** the free Hyvä theme falls back to the
Luma checkout, where our Knockout opt-in component works unchanged — that
is the supported configuration. **Hyvä Checkout** (the separate commercial
product, Magewire-based) does not load Knockout components; supporting it
means writing a Magewire checkout component and testing against a paid
license. Recommendation: declare Hyvä Checkout out of scope for the first
Hyvä-support release, document the boundary ("the opt-in checkbox requires
the default checkout"), and revisit on demand. The server-side opt-in
endpoint (`smaily/checkout/optin`) and order-placement consumers are
checkout-implementation-agnostic, so a future Hyvä Checkout component only
needs the frontend half.

## Open release decisions

1. ~~Publish vendor/name~~ — **decided: `smaily/module-connect-hyva`**
   (Smaily's own vendor namespace; `hyva-themes/…` would have required
   adoption into the Hyvä compat-module tracker). The Magento module name
   stays `Hyva_SmailyConnect` per the Hyvä compat convention.
2. Hyvä Checkout: confirm the out-of-scope boundary above.
3. Whether the compat module stays in this repo (current setup: developed
   here, excluded from the main package artifacts) or moves to its own
   repository when published.
