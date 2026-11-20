# Adobe Commerce Marketplace submission — Smaily Connect 3

The kit for submitting version 3 to the existing Marketplace listing of
`smaily/smailyformagento`, which shows version 2.8.0. The listing's
composer package name stays the same, so version 3 is a **new version of
that listing**, not a new product. The version submitted is **3.0.1**
(3.0.0 fails the portal's PHP check). Submission happens in the Commerce Developer
Portal, from the Smaily vendor account (CON-152).

Files here:

| File | Use in the portal |
|---|---|
| `smaily-connect-user-guide.pdf` | Documentation upload: the user guide, in English (Marketplace wants a PDF) |
| `user-guide.html` | Source of the PDF; print it with Chrome to rebuild it (see below) |
| `smaily-icon-240.png` | Extension icon, 240 × 240 px |
| `screenshots/1-dashboard.png` | Gallery image: Dashboard |
| `screenshots/2-initial-setup.png` | Gallery image: the initial setup, step 2, the contact sync mode |
| `screenshots/3-automations.png` | Gallery image: Settings › Automations |
| `screenshots/4-intelligence.png` | Gallery image: Settings › Intelligence |
| the release asset `smaily-connect-magento2.zip` of 3.0.1 | The package upload |

The screenshots come from the docker sandbox (Magento 2.4.8-p4, sample
data), cropped to the module's page: no admin menu, no Magento logo, no
system messages. Two values are neutral for the listing: the Campaign
Intelligence store shows as "Demo Store", and the three automation
workflows have neutral names in place of the demo account's own.

Rebuild the PDF after an edit of `user-guide.html`:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --no-pdf-header-footer \
  --print-to-pdf="$PWD/docs/internal/marketplace/smaily-connect-user-guide.pdf" \
  "file://$PWD/docs/internal/marketplace/user-guide.html"
```

## Technical pre-check (2026-11-14, on the 3.0.0 release ZIP)

Against Adobe's [technical review guidelines](https://developer.adobe.com/commerce/marketplace/guides/sellers/technical-review-guidelines):

| Rule | Result |
|---|---|
| Zip archive with `composer.json`, under 30 MB | ✅ 2.9 MB unpacked; `composer.json` at the root |
| `name`, `type`, `version` | ✅ `smaily/smailyformagento`, `magento2-module`, `3.0.0` |
| No `extra.map` / `extra.magento-root-dir`, no forbidden dependency, no inline alias | ✅ |
| `etc/module.xml`, `registration.php` in `autoload.files`, a PSR-4 namespace | ✅ |
| No `*` constraint on a Magento-vendor package | ✅ from 3.0.1: `require-dev` pins `magento/magento-coding-standard` to `^41.0` (3.0.0 had `*`) |
| No TODO / FIXME comments | ✅ none in PHP, PHTML, JS or XML |
| Coding standard | ✅ 0 errors, 2,092 warnings with `magento/magento-coding-standard` 41 (the newest), `--standard=Magento2` on the package |
| PHP versions not narrower than the claimed Commerce versions | ❌ on 3.0.0, refused at submit; ✅ from 3.0.1 (PHP 8.1–8.5) — see "Compatibility" |
| Installation with Varnish, production mode | ✅ by design: `setup:di:compile` runs in CI on 2.4.4-p13 and 2.4.9; only the module's own pages (personalization opt-out, the recommendations endpoint) are not cacheable |
| `repositories` key in `composer.json` | ⚠️ the Mage-OS mirror (development only) is declared; the guideline does not mention the key |

If the review refuses the `repositories` key, drop it from the published
`composer.json` (the development mirror can move into a local config).

## Compatibility to claim

The portal asks per minor line only: Adobe Commerce (cloud) 2.4, Adobe
Commerce (on-premises) 2.4, Magento Open Source 2.3 / 2.4. Claim
**Magento Open Source 2.4**, as the 2.8.x listing did; Adobe Commerce is
not tested.

**The portal validates the PHP range at submit** (2026-11-14, on 3.0.0):
"Failed to validate composer.json - Your extension should require an
active PHP version such as 8.5.0 … which is supported by the selected
Magento edition". Magento Open Source 2.4 includes 2.4.9, whose
`magento/framework` (103.0.9) allows PHP 8.3–8.5, so the package must
allow `~8.5.0`. 3.0.1 does (PR #259); 3.0.0 cannot be submitted.

| Commerce | Its PHP (`magento/framework`) |
|---|---|
| 2.4.4, 2.4.5 | 7.4, 8.1 |
| 2.4.6 | 8.1, 8.2 |
| 2.4.7 | 8.1, 8.2, 8.3 |
| 2.4.8 | 8.2, 8.3, 8.4 |
| 2.4.9 | 8.3, 8.4, 8.5 |

## Listing

Rules: Adobe's [marketing review guidelines](https://developer.adobe.com/commerce/marketplace/guides/sellers/marketing-review-guidelines)
— a title of at most five words, no "Magento", "Adobe", "M2",
"Extension", "Module" or "Plugin"; every fee in **bold** (in this
integration listing, in the bold "Account & Pricing" section of the
20 Nov 2026 format below, not in the opening paragraph); no logos of
Adobe or Magento. The long
description lists the features vertically, as bullets made with the
editor's own bullet button, under a "Features" heading formatted as
Header 2 or Header 3 (marketing review, 18 Nov 2026). An integration's
long description follows Adobe's integration format (marketing review,
20 Nov 2026), in this order:

1. An introductory paragraph about the integrated company or service;
   its first mention is a **bold** hyperlink to its website.
2. A second paragraph on what the extension does and how it works, what
   changes in the store's UI, and which customizations are possible,
   with examples.
3. An "Account & Pricing" section, its heading formatted as Header 2 or
   Header 3 and its content in **bold**: whether a separate account is
   required; whether it is created at installation or separately (with
   the sign-up link); whether the service has fees (with the pricing
   link); and whom to contact for account setup and pricing.
4. Then the Features and any other content.

**Title:** `Smaily Connect`

The owner's position (2026-11-20): "Smaily" in "Smaily Connect" is not
the developer's name. Smaily is the email marketing platform the
extension connects a store to, and the title names that platform, as an
integration's title names the service it integrates. The extension does
nothing without a Smaily account, so the name cannot be removed. The
marketing review of 18 Nov 2026 asked to remove "the developer/company
name" from the title. The portal has no field for a note to the
reviewer, so the long description's first sentence says what Smaily is.

The second refusal (20 Nov 2026) repeated the title objection. On the
owner's decision the title stays: a support request (SALES0907379, sent
through https://commercemarketplace.adobe.com/contact-us/, category
Seller) asks Adobe to accept "Smaily" as the integrated service's name.

**Category:** Marketing › Email Marketing and Marketing › Marketing Automation (as the listing has).

**Price:** Free.

**Short description** (the portal shows no field for it today; keep for later):

> Sync subscribers and customers to Smaily, run welcome, first-order and
> abandoned-cart automations, and add Smaily Campaign Intelligence
> product recommendations to your emails and store.

**Long description** (as resubmitted on 2026-11-20):

> **[Smaily](https://smaily.com/)** is an email marketing and automation
> platform for online stores. Merchants use it to design and send
> newsletters, run automated emails such as welcome series and
> abandoned-cart reminders, segment their contacts, and manage subscriber
> consent in line with the GDPR.
>
> Smaily Connect connects your Magento store to your Smaily account.
> After installation, a guided setup in the Magento admin asks for your
> Smaily API credentials and walks you through which contacts to sync,
> which Smaily workflows your automated emails start, and whether to add
> a newsletter checkbox at checkout. From then on, new subscribers and
> customers reach Smaily as they happen, an unsubscribe in Smaily comes
> back to the store, and store events start the welcome, first-order and
> abandoned-cart workflows you picked. On the storefront only two
> optional things change: a newsletter checkbox at checkout, and a
> product-recommendations widget you can place on any page. Everything
> else is set in the admin: the lawful basis for syncing, the workflows,
> single-use discount codes, and one Smaily account or workflow per store
> language. For example, a store can remind shoppers who left items in
> their cart with an email that shows those products and restores the
> cart in one click, or welcome every new subscriber with a discount
> code.
>
> ## Account & Pricing
>
> - **A Smaily account is required.**
> - **The account is not created during installation. Create it
>   separately at
>   [sendsmaily.net/account/register](https://www.sendsmaily.net/account/register/).**
> - **The extension is free. Smaily is a paid service and needs a plan
>   that includes API access: see
>   [smaily.com/pricing](https://smaily.com/pricing/).**
> - **Campaign Intelligence is an optional paid Smaily add-on; its price
>   depends on the number of contacts: see
>   [smaily.com/campaign-intelligence](https://smaily.com/campaign-intelligence/).**
> - **For account setup and pricing, contact Smaily at
>   support@smaily.com.**
>
> ## Features
>
> - Contact sync: new subscribers and customers reach Smaily as they
>   happen, and an unsubscribe in Smaily comes back to the store.
> - Three lawful bases to choose from: subscribers only (consent), all
>   customers (legitimate interest) or checkout opt-in only.
> - An optional newsletter checkbox at checkout for guests and customers.
> - Welcome, First order and Abandoned cart automations start the Smaily
>   workflows you pick, optionally with a single-use discount code from a
>   cart price rule.
> - Abandoned-cart reminders carry the cart's products and a secure link
>   that restores the exact cart.
> - Multilingual stores route each language to its own workflow or Smaily
>   account.
> - Optional Campaign Intelligence: personalized product recommendations
>   in Smaily emails and on your store's pages, attribution of the orders
>   they bring, and automated campaigns such as replenishment and
>   win-back.
> - A guided initial setup, one Settings page, a Dashboard and a Log of
>   every delivery with retry.
> - Durable queues with retries; imports of existing data run in the
>   background without slowing the store.
> - Several websites can be set up as one store or as separate stores
>   with their own Smaily accounts.
> - Privacy: credentials stored encrypted, browse tracking only after the
>   visitor's marketing consent, a personalization opt-out for shoppers,
>   and GDPR export and erasure from the command line.
> - Admin and storefront in English and Estonian; storefront texts also
>   in Russian, Finnish, Latvian and Lithuanian.
>
> When a Smaily account is connected, the extension sends Smaily an
> installation report: store addresses, versions, connected accounts,
> switched-on features and connection health, never customer, subscriber
> or order data.
>
> Full user guide: https://smaily.com/connect-magento/

**Release notes** (the portal's text, as entered for the version; for 3.0.1 add "Supports PHP 8.5 (Magento 2.4.9)."):

> Smaily Connect 3.0.0 is a rewrite of Smaily for Magento 2.8 under the
> name Smaily Connect. New: a guided initial setup, a Dashboard, a Log of
> every delivery with retry, contact sync modes with two-way unsubscribes,
> discount codes in automations, per-language Smaily accounts and
> workflows, separate stores per website, Campaign Intelligence product
> recommendations, browse tracking with consent, GDPR tools and the
> installation report. Upgrading from 2.8.x: run
> `composer require smaily/smailyformagento:^3.0` and `setup:upgrade`;
> the settings migrate automatically, and the user guide lists what
> changes.

**Support:** support@smaily.com · documentation
https://smaily.com/connect-magento/

**Icon:** `smaily-icon-240.png` — the Smaily mark from smaily.com (`Smly-favicon-500x500.png`, the site's own icon), scaled to the 240 × 240 px the portal asks for.

## Submission steps (portal)

What the portal holds, as met on 2026-11-14:

1. Extensions › Smaily Ecommerce Integration (M2) › **Submit a New
   Version** opens a draft with the version number fixed (it must match
   the package's `composer.json`). The draft copies the last version's
   marketing part, which counts as passed.
2. **Technical Submission**: attach the package (malware-checked on
   upload), tick the compatibility, the license (GPL-3.0), the user
   guide PDF (replace the old `userguide.pdf`), and the release notes (a
   list of changes; they cannot be edited later). **Submit** validates
   `composer.json` before anything is queued.
3. **Marketing Submission**: **Withdraw and Edit** reopens the passed
   marketing part (its review then runs again). Extension Description
   (title, long description in a rich-text editor, category), Images and
   Videos (icon 240 × 240; 2–15 gallery images), Compatibility (the same
   2.4 tick), Pricing (free), Extension Support (only for selling
   support), Additional Details. **Preview** shows the listing.
4. Submit both; the version is scheduled to launch on approval of both.

A refused marketing part is edited in place on the version's page and
sent again with **Submit** → **Confirm**; there is no field for a note to
the reviewer.

A draft that cannot pass (3.0.0, refused on PHP) is cancelled with
**Cancel Version Submission**, and the next version starts a new draft.

Not on Marketplace: the Hyvä companion `smaily/module-connect-hyva`. It
requires `hyva-themes/magento2-theme-module`, which Marketplace does not
serve; Hyvä stores keep installing it from Packagist.
