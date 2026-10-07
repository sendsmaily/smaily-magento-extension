# Headless Storefronts

A headless store runs Magento as the back end only. Shoppers browse and buy
on a separate storefront application (PWA Studio, Vue Storefront, a custom
JavaScript site and similar) that talks to Magento through GraphQL or
REST. Magento's own theme — Luma or Hyvä — is not what shoppers see.

Smaily Connect is installed in the Magento back end in the usual way (see
[INSTALLING.md](INSTALLING.md)). Everything it does on the server — contact
sync, automations, the catalog, customer and order sync to Campaign
Intelligence, the import, the log — works the same with any storefront. The
parts that Magento's theme draws in the shopper's browser — the browse
tracker, the campaign-click capture, the checkout newsletter checkbox, the
early capture of a guest's email at checkout and the My Account
personalization page — are not drawn by a separate storefront. The
storefront team adds their equivalent, described in
[What the storefront team must add](#what-the-storefront-team-must-add).

This page uses two example hosts: `backend.example.com` is the Magento back
end, `shop.example.com` is the storefront shoppers use.

## Setting up a store with a separate storefront

Everything a store with a separate storefront sets up beyond a normal
install, in this order. Each line links to its details below.

**A. In the Magento admin and in Smaily**

1. Check the versions first: Magento 2.4.4 or newer, PHP 8.1–8.4 — [INSTALLING.md](INSTALLING.md#before-you-start).
2. On the initial setup's **Connect** step, enter the storefront's address as **Storefront URL** (**Using a separate storefront?**) — [Storefront URL](#storefront-url).
3. Find out which other integrations sync newsletter subscribers or opt-outs with Smaily; switch them off when the **Contacts** step's save switches contact sync on (step 4), not before — until then they carry the opt-outs.
4. On the **Contacts** step choose **Subscribers only (consent)** or **All customers (legitimate interest)**, never **Checkout opt-in only** — [Newsletter consent at checkout](#5-newsletter-consent-at-checkout).
5. On the **Automations** step map the workflows but leave **Enabled** unticked — [Before switching anything on](#before-switching-anything-on).
6. Connect Campaign Intelligence only once the Storefront URL is saved: connecting starts the catalog import with the storefront's links — [Storefront URL](#storefront-url).
7. Open one `product_url` and `image_url` from **Log > Details** (needs C1) — [Check before recommendation emails](#check-before-recommendation-emails).
8. In Smaily's abandoned-cart template, link to the storefront's cart page instead of `{{abandoned_cart_url}}`, and to the storefront's address instead of a back-end `{{store_url}}` — [Abandoned-cart link](#abandoned-cart-link).
9. Once the links check out, switch the automations on under **Settings > Automations** — [Before switching anything on](#before-switching-anything-on).
10. Switch on **Enable storefront browse tracking** only once the storefront sends browse events (C5) — [Send browse events](#4-send-browse-events).

**B. On the Magento server (back end)**

1. Magento's cron runs every minute; it does all of the module's work — [INSTALLING.md](INSTALLING.md#4-cron).
2. `POST /smaily/relay` (browse tracking) and `GET /smaily/rss/feed` (RSS product blocks) answer from the public internet — [hand-off item 1](#1-make-the-modules-back-end-addresses-reachable).
3. When the shopper's browser places the order on the back end directly: CORS with credentials for the storefront's origin — [hand-off item 3](#3-carry-the-cookies-to-order-placement).
4. Behind a proxy or CDN, Magento sees the shopper's address, not the proxy's — [On the Magento back end](#on-the-magento-back-end).
5. Nothing else: the syncs, the imports, the abandoned-cart scan and the nightly product list run on the server as on any store — [On the Magento back end](#on-the-magento-back-end).

**C. On the storefront (its developer)**

1. Product links open: route `/<url-key>.html`, or redirect it to the product page keeping the query string — [Storefront URL](#storefront-url).
2. Campaign clicks (`smaily_rec`, `smaily_vt`, `smaily_ctx`) are written into first-party cookies on every page load; a landing that writes `smaily_rec_id` but has no `smaily_ctx` deletes the context cookie — [hand-off item 2](#2-capture-campaign-clicks-into-cookies).
3. Those cookies travel with the place-order request, and `smaily_anon_sid` and `smaily_rec_uid` with the login (customer token) request — [hand-off item 3](#3-carry-the-cookies-to-order-placement).
4. A guest's email goes on the cart as soon as it is typed (`setGuestEmailOnCart`) — [hand-off item 7](#7-put-a-guests-email-on-the-cart).
5. Browse events go to `/smaily/relay`, only with marketing-cookie consent — [hand-off item 4](#4-send-browse-events).
6. Checkout has the storefront's own unticked newsletter checkbox, which subscribes the shopper through Magento — [hand-off item 5](#5-newsletter-consent-at-checkout).
7. The merchant gets the storefront's cart page address for the abandoned-cart email — [Abandoned-cart link](#abandoned-cart-link).

Two features have no headless path: the My Account personalization page,
and the welcome automation for signups made on the storefront — see the
table below.

## What works and what does not

Verdicts: **Works** — no storefront work needed. **Needs storefront work** —
works once the storefront team adds the piece described below. **Not
available** — no headless path today.

| Feature | Where it runs | On a headless storefront |
|---|---|---|
| Connection, settings, initial setup, Dashboard, Log | Magento admin | **Works** |
| Contact sync of customers and newsletter subscribers (live, reconcile, import) | Server: Magento events, cron | **Works** — a subscription or registration through GraphQL or REST is a normal Magento save |
| Abandoned-cart detection and reminder | Server: cron reads Magento carts | **Works** for carts that carry an email address. A signed-in customer's cart has one; a guest's cart gets one when the storefront puts the email on the cart — see [hand-off item 7](#7-put-a-guests-email-on-the-cart) |
| Abandoned-cart link `{{abandoned_cart_url}}` | Back-end page `smaily/cart/restore` | **Not available** — see [Abandoned-cart link](#abandoned-cart-link) |
| Purchase marker, first-order automation | Server: order placement | **Works** |
| Welcome automation | Server: newsletter subscription | **Not available** for storefront signups — a signup through GraphQL or REST counts as an API signup, and the welcome fires only for signups in Magento's own storefront or the checkbox below |
| Guest email captured at checkout as soon as it is typed | Magento checkout (Luma/Knockout) script | **Needs storefront work** — see [hand-off item 7](#7-put-a-guests-email-on-the-cart) |
| Checkout newsletter checkbox | Magento checkout (Luma/Knockout) | **Needs storefront work** — the storefront draws its own checkbox and subscribes the shopper; see [Newsletter consent](#5-newsletter-consent-at-checkout). The **Checkout opt-in only** sync mode does not work on a headless storefront |
| Catalog, customer and order sync to Campaign Intelligence, the historical imports, the nightly product list (03:30) | Server: Magento events, queue, cron | **Works**; product links need a check — see [Product links and images](#product-links-and-images) |
| RSS product feed `smaily/rss/feed` | Back-end page, read by Smaily | **Works** when Smaily can reach the back-end host; item links are the same product links as in the catalog |
| Campaign-click capture (`smaily_rec`, `smaily_vt`, `smaily_ctx`) | Storefront script | **Needs storefront work** — see [hand-off item 2](#2-capture-campaign-clicks-into-cookies) |
| Recommendation attribution on orders | Server, reads the shopper's cookies at order placement | **Needs storefront work** — the order request must carry the cookies; see [hand-off item 3](#3-carry-the-cookies-to-order-placement) |
| Browse tracking (product views, searches, cart adds, checkout) | Storefront script posting to `smaily/relay` | **Needs storefront work** — see [hand-off item 4](#4-send-browse-events) |
| Identity merge on login | Server: Magento's login event, reads the shopper's cookies | **Needs storefront work** — a GraphQL `generateCustomerToken` or REST `integration/customer/token` login links the shopper's earlier browsing to their account, as a login on Magento's own pages does, when the login request carries the cookies; see [hand-off item 3](#3-carry-the-cookies-to-order-placement) |
| My Account > Personalization (profiling opt-out) | Magento customer account page | **Not available** — the module has no API for this choice |
| GDPR erase command | Server: CLI | **Works** |

## Product links and images

The module builds every product link from Magento itself:

- Campaign Intelligence catalog (`product_url`): Magento's own product URL
  for the store view (`getProductUrl()` under the store view's frontend
  settings). With the default **Use Categories Path for Product URLs = No**
  that is the store view's **Base Link URL** + the product's URL key + the
  URL suffix, for example `https://backend.example.com/oak-table.html`.
- RSS feed items (`<link>`, `<guid>`): the same URL.
- With a **Storefront URL** set (below), both start with the storefront's
  address instead.
- Images (catalog `image_url`, RSS `<enclosure>`, abandoned-cart
  `product_image_url_N`): Magento's resized product image under the store
  view's **Base URL for User Media Files**, for example
  `https://backend.example.com/media/catalog/product/cache/…/oak-table.jpg`.
- Order lines carry no links.

Images are normally fine on a headless store: storefronts usually load the
same media URLs from the back end. Open one `image_url` from **Log >
Details** after the first catalog sync to be sure it loads from outside.

Product links are the part to check. Campaign Intelligence puts
`product_url` into recommendation emails, with its tracking parameters
appended (`utm_source`, `utm_medium`, `smaily_rec`, `smaily_ctx`,
`smaily_vt`). On a headless store a Magento product URL often points at the
back-end host, which shoppers cannot open, or at a path the storefront does
not route.

### Storefront URL

**Settings > Connection > Using a separate storefront? > Storefront URL**
— also on the initial setup's Connect step — puts the product links on the
storefront's address. Enter the storefront's
address only — `https://shop.example.com`, https, no path, no query. Every
product link in the catalog sync and in the RSS feed then starts with it:
the scheme, host and port of Magento's link are replaced, the path and the
query string stay. `https://backend.example.com/oak-table.html` becomes
`https://shop.example.com/oak-table.html`. Image links do not change, and
nothing else moves — Magento's own emails, the abandoned-cart link and the
module's back-end addresses keep the back-end host. Empty, the links are
Magento's own. The value is kept per website, like the other connection
settings. The field opens by itself when the last 30 days' orders all came
through the API (GraphQL, or REST without Magento's storefront session).

After a change, run the catalog import again (**Settings > Intelligence >
Historical imports > Catalog**) so Campaign Intelligence gets the new links.
Connecting Campaign Intelligence starts the catalog import, so set the
Storefront URL before connecting Campaign Intelligence — on a new install,
on the initial setup's Connect step, which saves it before the setup's
Intelligence step — or press **Hold back the import** right after connecting and
run the import once the Storefront URL is set. While no Storefront URL is
saved, the initial setup's Intelligence step and **Settings > Intelligence**
say the same under their setup URL field, until Campaign Intelligence is
connected.
The RSS feed shows them within 15 minutes (its cache).

The storefront must open the path: either it routes `/<url-key>.html`
itself, or it redirects it to its own product page. **A redirect must keep
the query string**, or the tracking parameters are lost and recommendation
clicks are neither attributed nor tied to the shopper. The campaign-click
capture ([hand-off item 2](#2-capture-campaign-clicks-into-cookies)) runs
on the page the shopper lands on, after any redirect.

### Base Link URL

Without the module's setting, Magento's own configuration of the store view
decides the link: **Stores > Configuration > General > Web > Base URLs
(Secure) > Secure Base Link URL** (and the unsecure one) at the store
view's scope. Set to the storefront's address, the product link becomes
`https://shop.example.com/oak-table.html` as well, and the same routing
rule applies.

Changing the Base Link URL moves every link Magento builds for that store
view, not only the module's: Magento's own emails, the module's
`smaily/relay`, `smaily/rss/feed` and `smaily/cart/restore` addresses. On
the storefront host those paths then have to reach the back end (for
example a reverse proxy for `/smaily/`). Agree this with the storefront
team before changing it; many headless setups already set the Base Link URL
to the storefront for Magento's own emails.

### Check before recommendation emails

If the storefront's product pages use another pattern — for example a path
built from the SKU — and it does not redirect Magento-style paths, the
links in recommendation emails will not open the product page. Do not
switch on recommendation emails until a `product_url` from **Log >
Details** opens the right page, with its query string kept.

## On the Magento back end

The module adds nothing to install on the storefront host. On the back end
it needs what any Magento store with the module needs, plus the points
below:

- **Cron.** Magento's standard cron entry, every minute, runs the module's
  `smaily_connect` group: contact sync and automations, the abandoned-cart
  scan (every 5 minutes), the sync and imports to Campaign Intelligence and
  the nightly product list (03:30 in the store's default time zone). See
  [INSTALLING.md](INSTALLING.md#4-cron).
- **Outbound HTTPS** from the back end to the Smaily account's API
  (`https://<subdomain>.sendsmaily.net`) and to Campaign Intelligence (the
  address from the setup URL). Neither calls the back end, except Smaily
  reading the RSS feed.
- **Public paths.** `POST /smaily/relay` and `GET /smaily/rss/feed` — see
  [hand-off item 1](#1-make-the-modules-back-end-addresses-reachable).
- **CORS, when the shopper's browser calls the back end directly.** The
  module reads the attribution cookies from the request that places the
  order ([hand-off item 3](#3-carry-the-cookies-to-order-placement)). A
  browser sends cookies on a cross-origin `fetch()` only with
  `credentials: 'include'`, and reads the answer only when the back end
  answers with `Access-Control-Allow-Origin: https://shop.example.com` (the
  exact origin, not `*`) and `Access-Control-Allow-Credentials: true`.
  Magento has no CORS setting of its own: the web server or a CORS module
  answers it. The relay needs no CORS (hand-off item 4).
- **The shopper's address behind a proxy or CDN.** The relay (30 requests a
  minute) and the guest-email route of hand-off item 7 (30 requests per 10
  minutes) count requests per client address as Magento sees it. Behind a
  proxy Magento is not told about, every shopper has the proxy's address,
  and one limit then covers the whole store. Either the web server restores
  the shopper's address (for example nginx's real-IP module), or Magento's
  `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress` is given the proxy's
  header (`alternativeHeaders`, `trustedProxies`) in `app/etc/di.xml`.
- **Nothing else.** Contact sync, the automations, the order, customer and
  catalog sync, the historical imports, the abandoned-cart scan and the
  [nightly product check](USER_GUIDE.md#the-nightly-product-check) run on
  the server and do not depend on the storefront.

## What the storefront team must add

A hand-off list. Each item says what the storefront sends and where; every
address below is on the Magento back end unless it says otherwise.

### 1. Make the module's back-end addresses reachable

| Address | Method | Who calls it | Needed for |
|---|---|---|---|
| `https://backend.example.com/smaily/relay` | POST | The shopper's browser | Browse tracking |
| `https://backend.example.com/smaily/rss/feed` | GET | Smaily's servers | RSS product blocks in Smaily templates |

Headless back ends often answer only `/graphql`, `/rest` and `/media` to the
public. Allow these two paths too. The admin and the module's outbound calls
to Smaily and Campaign Intelligence need nothing. The module's other
storefront addresses — `smaily/cart/restore`, `smaily/checkout/optin` and
`smaily/privacy` — serve Magento's own theme and are not used by a separate
storefront. The guest-email route of item 7 is under `/rest`.

### 2. Capture campaign clicks into cookies

On every page load, read these query parameters and store them as
first-party cookies (`path=/`, `SameSite=Lax`, `Secure` on https). Write a
value only when it has the shape shown; ignore it otherwise.

| Query parameter | Shape | Cookie | Lifetime |
|---|---|---|---|
| `smaily_rec` | UUID (`8-4-4-4-12` hex) | `smaily_rec_id` | 30 days |
| `smaily_vt` | `vt_` + 1–61 letters or digits, or `vs_` + exactly 22 letters or digits | `smaily_rec_uid` | 365 days |
| `smaily_ctx` | 1–64 of `A-Z a-z 0-9 . _ -` | `smaily_rec_ctx` | 30 days |

When the address has no valid `smaily_rec` but has `utm_source=smaily`,
take `utm_content` as the `smaily_rec_id` value, under the same UUID rule,
as Magento's own storefront does.

The context cookie always describes the same click as `smaily_rec_id`:
write `smaily_rec_ctx` only on a landing that writes `smaily_rec_id`, from
that address's `smaily_ctx`, and on such a landing without a valid
`smaily_ctx` delete the cookie. Otherwise an earlier click's context stays
behind and the purchase is credited to the wrong channel — a click on an
email link without a context after a storefront click would count as the
storefront's sale. A landing
that writes no `smaily_rec_id` leaves both cookies as they are; a missing
`smaily_vt` leaves its cookie as it is.

The visitor token has two forms: `vt_`, issued by Campaign Intelligence in
email links, and `vs_`, a token a store may create for a consenting guest at
checkout (contract 1.11.0). The module does not create `vs_` tokens, but
sends a `vs_` token it finds in the `smaily_rec_uid` cookie with the order
unchanged, as it does a `vt_` token; a value of neither form is not sent.

The anonymous session id of browse events, the cookie `smaily_anon_sid`,
is not part of this capture: set it in step 4, with consent only.

These are the default names and lifetimes. A Campaign Intelligence account
can override them; ask Smaily support if the account was set up with other
names.

This capture is a functional cookie for the merchant's own email click, as
in Magento's own storefront, and does not wait for analytics consent.

### 3. Carry the cookies to order placement

The module stamps the four cookies onto the order when Magento saves it —
read from the request that places the order. On a headless storefront that
request is the storefront's GraphQL `placeOrder` (or REST) call, so it must
carry the cookies:

- **Browser calls the back end directly:** set the cookies on the parent
  domain both hosts share (`Domain=.example.com`) and send the place-order
  request with credentials (`credentials: 'include'`); the back end must
  answer CORS with credentials allowed for the storefront's origin (see
  [On the Magento back end](#on-the-magento-back-end)).
- **Storefront server calls the back end:** forward the four cookies from
  the shopper's request in the `Cookie` header of the place-order call.
- **GraphQL through a path on the storefront's own host** (a proxy to the
  back end): the proxy forwards the shopper's `Cookie` header.

The four cookies are the three of item 2 and `smaily_anon_sid` (item 4).
When the two hosts share no parent domain (for example `shop.example.com`
and `backend.example.net`), the browser never sends the storefront's
cookies to the back end: use the server or the proxy route.

Without this, orders reach Campaign Intelligence without attribution, and
recommendation emails show no conversions.

**The login request too.** When a shopper logs in — GraphQL
`generateCustomerToken`, or REST `POST /V1/integration/customer/token` —
the module links the browsing recorded before the login to their account.
It reads two of the cookies from that request: `smaily_anon_sid` (the
browsing session, item 4) and `smaily_rec_uid` (the visitor token, item 2);
either one is enough. Carry them the same way as for the place-order
request. A login request without them links nothing; the login itself
works as before. A shopper who turned profiling off is not linked, as on
Magento's own pages.

### 4. Send browse events

Only when **Enable storefront browse tracking (product views, searches,
cart activity)** is on in the module's settings; the relay answers 404
otherwise. And only for a shopper who allowed **marketing** cookies in the
storefront's consent banner — without that consent send no event and set
no `smaily_anon_sid`, as Magento's own tracker does (see the User Guide,
[Connecting your cookie consent tool](USER_GUIDE.md#connecting-your-cookie-consent-tool)).
With consent, when the cookie `smaily_anon_sid` is missing, set it to a new
random UUID v4 for 30 days.

Send events from the shopper's browser to
`https://backend.example.com/smaily/relay` as a JSON body:

```json
{"events": [
  {
    "event_id": "0b6f3c1e-2a4d-4c8e-9f10-5d2b7a9e4c31",
    "session_id": "<value of the smaily_anon_sid cookie>",
    "event_type": "product_view",
    "sku": "OAK-TABLE-120",
    "category_path": "dining/tables",
    "smaily_visitor_token": "<value of the smaily_rec_uid cookie, when set>"
  }
]}
```

- `event_id`: a new UUID v4 per event. `session_id`: required; an event
  without it is dropped.
- `event_type`: `product_view`, `category_view`, `search`, `cart_add`,
  `cart_remove`, `wishlist_add`, `wishlist_remove`, `checkout_start` or
  `checkout_complete`.
- Optional fields: `sku` (the Magento SKU — the same key the catalog sync
  sends), `category_path` (the category's URL path), `search_query`,
  `smaily_visitor_token`, `dwell_seconds`. Text fields up to 255
  characters (`session_id` up to 64); a longer value is dropped, not cut,
  and so is any other field. The server adds the time and the source.
  Magento's own tracker sends `product_view` and `category_view` (with the
  category's `url_path` as `category_path`), `search`, `cart_add`,
  `checkout_start` and `checkout_complete`.
- Up to 100 events per request; batch for a few seconds. Magento's own
  tracker batches for 5 seconds and sends on `pagehide`.
- Send `smaily_visitor_token` when the `smaily_rec_uid` cookie is set.
- Send with `navigator.sendBeacon()` and a `text/plain` body (for example
  `new Blob([json], {type: 'text/plain'})`). The relay reads the raw body
  and needs no cookie, session or form key, so this cross-origin request
  needs no CORS headers. A `fetch()` with `Content-Type: application/json`
  needs a CORS preflight the relay does not answer.
- Send from the browser, not from the storefront server: the relay limits
  each client address to 30 requests a minute, and a server would share one
  address for all shoppers. If the back end sits behind a proxy, Magento
  must be configured to trust its forwarding header (see
  [On the Magento back end](#on-the-magento-back-end)).

With a Storefront URL saved, the admin describes the separate storefront,
not Magento's own theme: the Dashboard's **Browse tracking** says **Your
separate storefront must send the events itself** while the setting is on,
and the note under the setting says the storefront's own cookie consent
banner decides. No admin notice asks for Magento's **Cookie Restriction
Mode** for a store view with a Storefront URL saved; one still comes for a
store view without one where the mode is off, because its visitors browse
Magento's own pages.

### 5. Newsletter consent at checkout

The module's checkout checkbox exists only in Magento's own checkout. On a
headless storefront:

- Draw the storefront's own unticked newsletter checkbox at checkout.
- When the shopper ticks it, subscribe the shopper through Magento — for
  example GraphQL `subscribeEmailToNewsletter`, or `is_subscribed` on the
  customer. The module syncs that subscription to Smaily like any other.
- Use the contact sync mode **Subscribers only (consent)** or **All
  customers (legitimate interest)**. **Checkout opt-in only** syncs nothing
  on a headless storefront: that mode accepts only the module's own
  checkbox.
- A subscription made this way fires no welcome automation (it counts as an
  API signup).

### 6. Links in Smaily templates

- **Abandoned-cart link.** See below.
- `{{store_url}}` in abandoned-cart emails is the store view's Base Link
  URL in Magento. If that is the back-end host, write the storefront's
  address in the template instead.

### 7. Put a guest's email on the cart

The abandoned-cart scan reminds a guest only when the guest's cart carries
an email address (the cart's own email, or its billing or shipping
address's). Magento's own checkout puts it there at the payment step; the
module's checkout script puts it there as soon as the guest types a valid
address, so a guest who leaves at the shipping step is reminded too. A
separate storefront does the same:

- Once the guest has typed a valid address and paused, call Magento's
  GraphQL `setGuestEmailOnCart` (`cart_id`, `email`) on the guest's cart.
  Many storefronts call it only when the shipping step is submitted; call
  it when the email field is filled in.
- A storefront built on REST can post the address to the module's route
  `POST /rest/V1/smaily-connect/guest-carts/<masked cart id>/email` with
  the body `{"email": "…"}`; it answers `true` or `false`. It accepts an
  address only while the abandoned-cart automation is on for the website,
  allows 30 requests per 10 minutes per client address and 5 changes per
  cart, so call it from the shopper's browser, not from the storefront
  server. A browser call to another host needs a CORS answer for this
  route from the back end (no credentials needed; see
  [On the Magento back end](#on-the-magento-back-end)).

As on any store, the reminder reaches only an address Smaily already has as
a contact that has not unsubscribed.

## Abandoned-cart link

`{{abandoned_cart_url}}` points to the back-end page `smaily/cart/restore`,
which puts the cart into Magento's own checkout session and opens Magento's
cart page. A headless storefront keeps its cart differently, so the link
cannot bring the cart back there. In the abandoned-cart email, link to the
storefront's cart page instead. A signed-in customer finds the cart there,
because Magento keeps a customer's cart on the server; a guest finds it
only in the same browser, while the storefront still holds the cart.

## Before switching anything on

Contact sync can go on from the initial setup; the automations wait until
the product links are checked.

1. Install the module and connect it as usual. On the initial setup's
   Connect step, set the **Storefront URL** (**Using a separate
   storefront?**) when shoppers cannot open the back-end host: it is saved
   with the connection, before the Contacts step switches contact sync on,
   and connecting Campaign Intelligence starts the catalog import with it.
   On the Contacts step choose **Subscribers only (consent)** or **All
   customers (legitimate interest)**, not **Checkout opt-in only** —
   saving the step switches contact sync on in that mode. On the
   Automations step map the workflows but leave **Enabled** unticked.
2. Connect Campaign Intelligence (the initial setup's Intelligence step, or
   **Settings > Intelligence**); connecting starts the catalog import. Open
   a product's `product_url` and `image_url` from **Log > Details**: the
   link opens the right product page on the storefront, with an added
   `?probe=1` still in the address bar after any redirect; the image loads.
3. Once the links check out, switch the automations on under **Settings >
   Automations**, with the abandoned-cart email linking to the storefront's
   cart page (see [Abandoned-cart link](#abandoned-cart-link)).
4. With the storefront team's work in place: a click on a test link with
   `smaily_rec` and `smaily_vt` leaves the cookies of hand-off item 2; a test
   order shows them in the order's attribution (`smaily_order_attribution`
   table); a browse batch gets `{"ok":true,"accepted":…}` back from
   `smaily/relay` in the browser's network panel. Browse events are not
   queued, so they do not show in **Log**. A guest who only types an email
   at checkout leaves it on the cart (the `customer_email` of the cart's
   row in the `quote` table), and the reminder reaches them after the
   automation's wait.
