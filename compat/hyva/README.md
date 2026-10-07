# Hyva_SmailyConnect — Hyvä theme compatibility for Smaily Connect

Compatibility module that makes the [Smaily Connect](../../README.md)
storefront work on [Hyvä themes](https://www.hyva.io/) (free/open-source
since November 2025, OSL-3.0). It follows the standard Hyvä compat-module
pattern: a small separate module, `hyva_`-prefixed layout handles, no
RequireJS/jQuery/Knockout, strict-CSP-safe script delivery, and Tailwind
registration via `hyva:config:generate`.

The main `Smaily_Connect` module stays theme-agnostic; this module only
swaps the storefront templates that depend on Luma's JS stack.

> **Status: verified on Hyvä 1.5.2** (default theme AND the strict-CSP
> variant `Hyva/default-csp` with enforced no-inline `script-src`), with a
> Luma store view as the regression control — see `docs/HYVA_SUPPORT.md`
> in the repository root for the audit and the executed verification
> matrix. Known remaining gap: third-party AJAX-add-to-cart modules that
> submit programmatically bypass the `cart_add` form-submit capture (a
> `private-content-loaded` cart-diff fallback is the documented plan B).

## What it does

| Surface | Change |
|---|---|
| Attribution script | AMD/`x-magento-init` bootstrap replaced by an inert JSON config block + a plain static JS file (`js/smaily-attribution.js`). No inline executable script — safe under strict CSP without whitelisting. |
| Browse tracker | Same delivery pattern (`js/smaily-tracker.js`); jQuery removed (`fetch` keepalive fallback instead of `$.ajax`); `cart_add` captured from the `checkout/cart/add` form submit instead of Luma's `ajax:addToCart` jQuery event. |
| Personalization opt-out form (My Account) | Tailwind-styled template (base template is functional but Luma-styled). |
| Tailwind build | `Observer/RegisterModuleForHyvaConfig` registers the module for `bin/magento hyva:config:generate`, so the theme's Tailwind content scan picks up this module's templates. |

Not touched (work as-is under Hyvä): the product/static page-context blocks
(inline scripts already whitelisted via Magento's `SecureHtmlRenderer`,
theme-independent), the checkout opt-in checkbox (Hyvä's free tier uses the
Luma-fallback checkout, where the Knockout component runs unchanged), the
native newsletter block (Hyvä ships its own form template; our observers
are server-side), and the RSS feed (no frontend assets). The commercial
**Hyvä Checkout** product is a separate integration surface and is out of
scope for this module.

## Installation (Hyvä dev/store)

Prerequisites: Magento 2.4.4+, Hyvä theme 1.3+ (1.4+ recommended,
Tailwind v4) — via the free license key from the
[Hyvä portal](https://www.hyva.io/) (private Packagist repo) or from source
at [github.com/hyva-themes](https://github.com/hyva-themes).

This module ships inside the Smaily Connect repository under `compat/hyva`
for now (inert there — nothing loads its `registration.php`; it is excluded
from the release ZIP artifact) and will be published as its own composer
package. Until then, install it with a path repository:

```bash
composer config repositories.smaily-hyva path vendor/smaily/smailyformagento/compat/hyva
composer require smaily/module-connect-hyva:@alpha
bin/magento module:enable Hyva_SmailyConnect
bin/magento setup:upgrade
bin/magento hyva:config:generate
# rebuild the theme's Tailwind CSS (Node 20+):
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind ci
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind run build-prod
bin/magento cache:flush
```

> The composer package is published under Smaily's own vendor namespace as
> `smaily/module-connect-hyva`; the Magento module name keeps the Hyvä
> compat-module convention (`Hyva_SmailyConnect`) — see
> `docs/HYVA_SUPPORT.md`.

## License

GPL-3.0-only, same as Smaily Connect.
