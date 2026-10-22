# Hyva_SmailyConnect — Hyvä theme compatibility for Smaily Connect

Compatibility module that makes the
[Smaily Connect](https://github.com/sendsmaily/smaily-magento-extension)
storefront work on [Hyvä themes](https://www.hyva.io/) (free/open-source
since November 2025, OSL-3.0). It follows the standard Hyvä compat-module
pattern: a small separate module, `hyva_`-prefixed layout handles, no
RequireJS/jQuery/Knockout, strict-CSP-safe script delivery, and Tailwind
registration via `hyva:config:generate`.

The main `Smaily_Connect` module stays theme-agnostic; this module only
swaps the storefront templates that depend on Luma's JS stack. It is
versioned with Smaily Connect: install the same version of both.

The module is developed in the Smaily Connect repository
([`compat/hyva`](https://github.com/sendsmaily/smaily-magento-extension/tree/master/compat/hyva));
every Smaily Connect release publishes it to
[sendsmaily/smaily-magento-hyva](https://github.com/sendsmaily/smaily-magento-hyva),
which Packagist reads as `smaily/module-connect-hyva`, and attaches it to
the release as its own ZIP. Report issues and send changes to the Smaily
Connect repository.

> **Status: verified on Hyvä 1.5.2** (default theme AND the strict-CSP
> variant `Hyva/default-csp` with enforced no-inline `script-src`), with a
> Luma store view as the regression control — see
> [HYVA_SUPPORT.md](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/HYVA_SUPPORT.md)
> for the audit and the executed verification matrix. Known remaining gap:
> third-party AJAX-add-to-cart modules that submit programmatically bypass
> the `cart_add` form-submit capture (a `private-content-loaded` cart-diff
> fallback is the documented plan B).

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

## Installation

Prerequisites: Magento 2.4.4+, PHP 8.1–8.4, Hyvä theme 1.3+ (1.4+
recommended, Tailwind v4) — via the free license key from the
[Hyvä portal](https://www.hyva.io/) (private Packagist repo) or from source
at [github.com/hyva-themes](https://github.com/hyva-themes), and Node.js 20+
for the theme's Tailwind build. The
[user guide](https://smaily.com/connect-magento/#st-hyva) has the same
steps.

Install both modules the same way. A store that installed Smaily Connect
from the release ZIP takes this module from the release too: the composer
command would install a second copy of Smaily Connect.

### With composer

One command installs Smaily Connect and this module. Until 3.0.0 is
released, give the release candidate's exact version for both packages, as
the [releases page](https://github.com/sendsmaily/smaily-magento-extension/releases)
names it — `3.0.0-rc13` below: a standard Magento project accepts stable
versions only. From 3.0.0 on, `composer require smaily/module-connect-hyva`
is enough; it installs Smaily Connect too.

Run the commands from the Magento root, as the user that owns the Magento
files. `<Vendor>/<theme>` is the Hyvä theme your store uses. In production
mode (`bin/magento deploy:mode:show`):

```bash
bin/magento maintenance:enable
composer require smaily/smailyformagento:3.0.0-rc13 smaily/module-connect-hyva:3.0.0-rc13
bin/magento module:enable Smaily_Connect Hyva_SmailyConnect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento hyva:config:generate
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind ci
npm --prefix app/design/frontend/<Vendor>/<theme>/web/tailwind run build-prod
bin/magento setup:static-content:deploy en_US   # every locale your store uses
bin/magento maintenance:disable
bin/magento cache:flush
```

In developer or default mode, `composer require`, `module:enable`,
`setup:upgrade`, `hyva:config:generate`, the two `npm` commands and
`cache:flush` are enough.

### From the release ZIPs

Every release on the
[releases page](https://github.com/sendsmaily/smaily-magento-extension/releases)
carries two ZIPs, each with its `.sha256` file:
`smaily-connect-magento2.zip` (Smaily Connect) and
`smaily-connect-magento2-hyva.zip` (this module). Download all four files
into one folder and check both archives there — each must answer `OK`:

```bash
sha256sum -c smaily-connect-magento2.zip.sha256           # macOS: shasum -a 256 -c
sha256sum -c smaily-connect-magento2-hyva.zip.sha256
```

Neither ZIP has a top-level folder. Extract each into its module folder
(from the Magento root; give `unzip` the ZIP's full path if it is
elsewhere):

```bash
mkdir -p app/code/Smaily/Connect app/code/Hyva/SmailyConnect
unzip smaily-connect-magento2.zip -d app/code/Smaily/Connect
unzip smaily-connect-magento2-hyva.zip -d app/code/Hyva/SmailyConnect
```

Then run the commands above for your mode, without the `composer require`
line. The full ZIP steps for Smaily Connect — cron, updating, removing —
are in
[INSTALLING.md](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/INSTALLING.md).

### Updating

Update this module together with Smaily Connect, to the same version:
with composer, run the `composer require` command with the new version;
from the ZIPs, replace both module folders (remove the old files first).
Then run the commands for your mode again.

> The composer package name is `smaily/module-connect-hyva`, in Smaily's own
> vendor namespace; the Magento module name keeps the Hyvä
> compat-module convention (`Hyva_SmailyConnect`) — see
> [HYVA_SUPPORT.md](https://github.com/sendsmaily/smaily-magento-extension/blob/master/docs/HYVA_SUPPORT.md).

## License

GPL-3.0-only, same as Smaily Connect.
