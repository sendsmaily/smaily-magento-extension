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
tracker, the campaign-click capture, the checkout newsletter checkbox and the
My Account personalization page — are not drawn by a separate storefront.
The storefront team adds their equivalent, described in
[What the storefront team must add](#what-the-storefront-team-must-add).

This page uses two example hosts: `backend.example.com` is the Magento back
end, `shop.example.com` is the storefront shoppers use.

## What works and what does not

Verdicts: **Works** — no storefront work needed. **Needs storefront work** —
works once the storefront team adds the piece described below. **Not
available** — no headless path today.

| Feature | Where it runs | On a headless storefront |
|---|---|---|
| Connection, settings, initial setup, Dashboard, Log | Magento admin | **Works** |
| Contact sync of customers and newsletter subscribers (live, reconcile, import) | Server: Magento events, cron | **Works** — a subscription or registration through GraphQL or REST is a normal Magento save |
| Abandoned-cart detection and reminder | Server: cron reads Magento carts | **Works** for carts that carry an email address (guest carts get one when the storefront sets the guest email) |
| Abandoned-cart link `{{abandoned_cart_url}}` | Back-end page `smaily/cart/restore` | **Not available** — see [Abandoned-cart link](#abandoned-cart-link) |
| Purchase marker, first-order automation | Server: order placement | **Works** |
| Welcome automation | Server: newsletter subscription | **Not available** for storefront signups — a signup through GraphQL or REST counts as an API signup, and the welcome fires only for signups in Magento's own storefront or the checkbox below |
| Checkout newsletter checkbox | Magento checkout (Luma/Knockout) | **Needs storefront work** — the storefront draws its own checkbox and subscribes the shopper; see [Newsletter consent](#newsletter-consent-at-checkout). The **Checkout opt-in only** sync mode does not work on a headless storefront |
| Catalog, customer and order sync to Campaign Intelligence | Server: Magento events, queue, cron | **Works**; product links need a check — see [Product links and images](#product-links-and-images) |
| RSS product feed `smaily/rss/feed` | Back-end page, read by Smaily | **Works** when Smaily can reach the back-end host; item links are the same product links as in the catalog |
| Campaign-click capture (`smaily_rec`, `smaily_vt`, `smaily_ctx`) | Storefront script | **Needs storefront work** |
| Recommendation attribution on orders | Server, reads the shopper's cookies at order placement | **Needs storefront work** — the order request must carry the cookies |
| Browse tracking (product views, searches, cart adds, checkout) | Storefront script posting to `smaily/relay` | **Needs storefront work** |
| Identity merge on login | Server: Magento's login event, reads the shopper's cookies | **Not available** — a GraphQL or REST login carries no storefront cookies. Browse events still carry the visitor token from a campaign click |
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
puts the product links on the storefront's address. Enter the storefront's
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
The RSS feed shows them within 15 minutes (its cache).

The storefront must open the path: either it routes `/<url-key>.html`
itself, or it redirects it to its own product page. **A redirect must keep
the query string**, or the tracking parameters are lost and recommendation
clicks are neither attributed nor tied to the shopper.

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
to Smaily and Campaign Intelligence need nothing.

### 2. Capture campaign clicks into cookies

On every page load, read these query parameters and store them as
first-party cookies (`path=/`, `SameSite=Lax`, `Secure` on https). Write a
value only when it has the shape shown; ignore it otherwise.

| Query parameter | Shape | Cookie | Lifetime |
|---|---|---|---|
| `smaily_rec` | UUID (`8-4-4-4-12` hex) | `smaily_rec_id` | 30 days |
| `smaily_vt` | `vt_` + 1–61 letters or digits | `smaily_rec_uid` | 365 days |
| `smaily_ctx` | 1–64 of `A-Z a-z 0-9 . _ -` | `smaily_rec_ctx` | 30 days |

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
  answer CORS with credentials allowed for the storefront's origin.
- **Storefront server calls the back end:** forward the four cookies from
  the shopper's request in the `Cookie` header of the place-order call.

Without this, orders reach Campaign Intelligence without attribution, and
recommendation emails show no conversions.

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
  characters; other fields are dropped. The server adds the time and the
  source.
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
  must be configured to trust its forwarding header.

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

## Abandoned-cart link

`{{abandoned_cart_url}}` points to the back-end page `smaily/cart/restore`,
which puts the cart into Magento's own checkout session and opens Magento's
cart page. A headless storefront keeps its cart differently, so the link
cannot bring the cart back there. In the abandoned-cart email, link to the
storefront's cart page instead. A signed-in customer finds the cart there,
because Magento keeps a customer's cart on the server; a guest finds it
only in the same browser, while the storefront still holds the cart.

## Before switching anything on

1. Install the module and connect it as usual; leave contact sync and
   automations off.
2. Set the **Storefront URL** when shoppers cannot open the back-end host.
   Run one catalog sync and open a product's `product_url` and `image_url`
   from **Log > Details**: the link opens the right product page on the
   storefront, with an added `?probe=1` still in the address bar after any
   redirect; the image loads.
3. With the storefront team's work in place: a click on a test link with
   `smaily_rec` and `smaily_vt` leaves the cookies of hand-off item 2; a test
   order shows them in the order's attribution (`smaily_order_attribution`
   table); a browse batch gets `{"ok":true,"accepted":…}` back from
   `smaily/relay` in the browser's network panel. Browse events are not
   queued, so they do not show in **Log**.
4. Choose the contact sync mode (not **Checkout opt-in only**), then switch
   on contact sync and the automations.
