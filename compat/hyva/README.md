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
| Smaily recommendations widget | Same delivery pattern (`js/smaily-recommendations.js`), jQuery-free; the cards the store answers render as Hyvä product cards (`templates/recommendations/cards.phtml`). |
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

This module lives in the Smaily Connect repository under `compat/hyva`. It
is not on Packagist yet, and neither the `smaily/smailyformagento` package
nor the release ZIP contains it (`/compat` is export-ignored, so GitHub's
"Source code" downloads leave it out too). Take it from a git clone of the
Smaily Connect version the store runs — the tag is the version,
`3.0.0-rc11` here:

```bash
git clone --depth 1 --branch 3.0.0-rc11 https://github.com/sendsmaily/smaily-magento-extension.git /tmp/smaily-connect
```

Smaily Connect installed with composer — copy the module into the project
and install it from there with a path repository:

```bash
mkdir -p packages
cp -R /tmp/smaily-connect/compat/hyva packages/module-connect-hyva
composer config repositories.smaily-hyva path packages/module-connect-hyva
composer require smaily/module-connect-hyva:@alpha
```

`@alpha` is needed: the module's version is `1.0.0-alpha1`, and a standard
Magento project installs only stable versions. Keep
`packages/module-connect-hyva` with the project (in its version control):
every `composer install` installs the module from that folder.

Smaily Connect installed from the release ZIP (`app/code/Smaily/Connect`)
— copy the module to `app/code` instead; requiring it with composer would
look for Smaily Connect as a composer package:

```bash
mkdir -p app/code/Hyva
cp -R /tmp/smaily-connect/compat/hyva app/code/Hyva/SmailyConnect
```

Then, in both cases:

```bash
bin/magento module:enable Hyva_SmailyConnect
bin/magento setup:upgrade
bin/magento hyva:config:generate
# rebuild the theme's Tailwind CSS (Node 20+):
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind ci
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind run build-prod
bin/magento cache:flush
```

When Smaily Connect is updated, replace the module folder the same way from
the new version's tag (composer: then `composer update
smaily/module-connect-hyva`), and run `bin/magento setup:upgrade`.

> The composer package name is `smaily/module-connect-hyva`, in Smaily's own
> vendor namespace; the Magento module name keeps the Hyvä
> compat-module convention (`Hyva_SmailyConnect`) — see
> `docs/HYVA_SUPPORT.md`.

## License

GPL-3.0-only, same as Smaily Connect.
