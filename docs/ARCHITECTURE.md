# Architecture

Developer documentation for the `Smaily_Connect` module (namespace
`Smaily\Connect`). The module is a ground-up v3 rewrite targeting feature
parity with the Smaily Connect plugins for WooCommerce and Shopify; the
three connectors share wire contracts, so cross-platform consistency is a
design constraint, not an accident.

## Layout

Classes live at the **package root** (classic Magento module layout) so the
package works both as a composer dependency (`vendor/smaily/smailyformagento`)
and as a manual `app/code/Smaily/Connect` install — Magento's app/code
autoloader maps `Smaily\Connect\*` to the module root.

```
Api/                service contracts (queue handler interface)
Block/              adminhtml config renderers, the recommendations widget
Console/Command/    CLI (backfill, engine ping/disconnect, GDPR)
Controller/         frontend: rss, relay, recommendations, checkout optin,
                    cart restore, privacy
Controller/Adminhtml/  dashboard, wizard, settings, unified log, JSON api
Cron/               queue flushers, abandoned cart, reconcile, backfill tick,
                    janitor, health check
Model/
  Client/           Smaily marketing API client (Guzzle, Basic auth)
  Engine/           Campaign Intelligence: client, settings, ingest queue,
                    payload builders, attribution, browse validation
  Queue/            marketing event queue + per-type handlers
  ContactSync/      lawful-basis mode, payload builder, dispatcher, guard
  AbandonedCart/    quote-scan state, payload, restore tokens
  Automation/       trigger routing (multilingual mapping table + saver)
  Multilingual/     store-view → language, account-key → store-view
  Backfill/         chunked import jobs + processors
  Migration/        2.8.x config mapper (pure, unit-tested)
  Privacy/          profiling consent, data-subject erasure
Observer/           thin event bridges (all logic lives in Model/)
Plugin/             newsletter email suppression, config validation,
                    checkout layout injection
Setup/Patch/        legacy schema cleanup (Schema/), config migration (Data/)
Setup/Uninstall.php settings + flag removal on module:uninstall
ViewModel/          template data providers
view/               adminhtml pages/panels/grid, frontend JS + templates
i18n/               translation packs (en_US canonical, et_EE)
```

## The two delivery pipelines

Everything outbound flows through one of two **durable queues** — nothing
user-facing ever blocks on an HTTP call, and nothing is lost when an API is
down.

### Marketing events → Smaily (`smaily_event_queue`)

```
Observer / cron ──enqueue──> smaily_event_queue ──cron flush (1 min)──> Smaily API
```

- Event types (`Model/Queue/EventType`): `contact.sync` (batched per store
  view — per-language accounts hit the right credentials),
  `automation.trigger` (delivered one-by-one so a partial failure can never
  re-trigger an automation), `engine.identity_merge`,
  `engine.profiling_consent` (see Profiling consent below).
- **A contact row's entity (PRO-3767).** A `contact.sync` or
  `automation.trigger` row's `entity_id` is the contact's address, so the
  Log's Entity filter finds it — when the address fits the 64-character
  column. A longer address was cut silently (Magento's adapter runs with
  `SQL_MODE ''`), so the purchase's withdrawal of a waiting reminder and
  the purchase marker's "a reminder went out" check, which match the full
  address, missed that contact's rows. Now such an address is stored as
  the keyed hash the profiling-consent rows use
  (`Model\Privacy\AddressKey::of()` of the trimmed, lower-case address; 64
  hex characters). `Model\Queue\ContactEntity::of()` is the one rule, and
  `ContactSync\SyncDispatcher` — the only writer of these rows and the
  only caller of `cancelPendingAutomation()` / `hasDeliveredAutomation()` —
  passes every entity through it. The other readers compare stored values
  with stored values (`ResendGuard`'s "a later message of this kind
  already reached this contact", `Log\Resend` copying the entity), and
  `LocalEraser` already matches the address and its keyed hash
  (`ContactEntity::forms()`). The Log,
  Details and the Dashboard show such an entity by its first 12 characters
  (`Log\EntityLabel`, by `ContactEntity::isHash()`, as for a consent row);
  Details shows the address in
  the payload. No schema change; a row queued before keeps its cut
  address until retention removes it.
- Automation routing (`Model/Automation/Router`, Woo `Multilingual\Router`
  parity): multilingual modes `single`/`c` use the config-default workflow;
  modes `a`/`b` resolve `smaily_automation_mapping` rows — the exact
  (trigger, language) row first, then the trigger's `is_default_fallback`
  row, then the config default; no match anywhere is a terminal skip
  (`Queue\Skipped`: closed with the reason, read as Skipped in the Log). Rows
  are looked up for the event's website with legacy global rows
  (`website_id 0` — the 2.8.x migration's default-scope seeding, or a
  pre-Phase-3 save) as the fallback; a website-specific row wins. A matched
  row's `account_key` travels with the workflow (`WorkflowMatch`) and the
  handler posts through THAT account's credentials
  (`Multilingual\AccountResolver` maps the key to a store view; `default`
  = default scope) — so a mode-A fallback row never fires another
  account's workflow ID through the event store view's credentials. The
  admin panels write rows through `Model/Automation/MappingSaver` at the
  target website scope (full-desired-state sync: unique-key upsert,
  cleared rows deleted, other websites' rows untouched).
- Handlers are registered per type in `di.xml`
  (`Model/Queue/HandlerPool`); adding an event type = adding a handler.
- Payloads are built at enqueue time (`ContactSync\SubscriberPayloadBuilder`,
  `ContactSync\SyncDispatcher`) and stored on the row.

### Engine ingest → Campaign Intelligence (`smaily_ingest_queue`)

```
Observer / backfill ──enqueue──> smaily_ingest_queue ──cron flush (1 min)──> engine
       (domain: catalog | customers | orders | browse | catalog_remove | catalog_changed)
```

The nightly catalog manifest, like the browse relay, is not queued: it is
built and sent in its own cron run and only recorded in the queue table
(domain `catalog_manifest`) for the Log — see *Nightly catalog manifest*
below.

- One wire item per row; the row UUID doubles as the wire `event_id`, so
  engine-side transport dedup makes retries safe.
- `Cron/FlushIngestQueue` first builds the catalog rows of the products the
  stock hooks marked changed (see Stock changes below), then sends one batch
  per domain per run (batch caps
  100/100/50/100 per the contract) and maps the D6 response's
  `errors[].index` back onto individual rows — a 200 is never treated as
  all-or-nothing.
- Every catalog row carries `tags.product_id` — the platform parent product
  id (`Engine\Payload\ParentProductResolver`: a configurable child resolves
  to its parent's entity id, everything else to its own). It keys the
  engine's product-level removal; the `sku` keying is untouched.
- `category_path` is the slug path of the product's deepest category. A
  variant (configurable child) without a real category of its own takes its
  parent's — the same parent as its `tags.product_id`; the parent's category
  ids come from one `catalog_category_product` query per parent
  (`ParentProductResolver::parentCategoryIds()`, memoized, so the siblings
  in a backfill page share it), never a parent product load. A product with
  no real category (none assigned, or only the root — for a variant: on
  neither the variant nor its parent) is sent with the placeholder
  `uncategorized` and `tags.category_defaulted: "true"` (contract §3,
  v1.6.0; omitted otherwise), so the engine derives nothing from the
  placeholder slug.
- **Product delete** (`Observer/Engine/ProductDeleteBefore`): a
  parent/standalone hard-delete enqueues one `catalog_remove` row
  (`CatalogIngest::enqueueRemovals()`, the one builder of that row); the
  flusher drains those through its own non-D6 path to
  `POST /api/v1/ingest/catalog/remove` (contract §3b — the engine
  tombstones every row matching `tags.product_id`; `not_found` in the
  response is a success, never a retry). A configurable child's deletion
  keeps the per-SKU `in_stock=false` upsert instead — §3b is product-level
  and would tombstone the surviving parent/siblings. A merely
  disabled/hidden product flows through `ProductSaveAfter`'s soft
  tombstone, never §3b.
- **Product import Delete (PRO-3768).** Magento's product import with the
  Delete behaviour (`CatalogImportExport\Model\Import\Product::_deleteProducts()`)
  deletes each bunch with one SQL DELETE and fires no
  `catalog_product_delete_before` — only
  `catalog_product_import_bunch_delete_commit_before` (after the DELETE,
  inside its transaction) and `…_bunch_delete_after`, with the deleted
  `ids_to_delete`. `Model/Engine/ProductImportDelete` gives those products
  the removal above: `Observer/Engine/ProductImportBunchDelete` (on the
  `commit_before` event, so the rows commit or roll back with the delete)
  queues a `catalog_remove` row per product (one insert,
  `CatalogIngest::enqueueRemovals()`), and for a configurable child the
  per-SKU tombstone instead. The child's parent
  link (`catalog_product_super_link`, cascaded) and its row data are gone
  by then, so `Plugin/Engine/ProductImportBunch` — an after-plugin on
  `ImportExport\Model\ResourceModel\Import\Data::getNextUniqueBunch()`,
  the bunch read right before the DELETE — finds the bunch's configurable
  children (`ParentProductResolver::configurableChildIds()`, one query for
  the bunch) and builds their tombstones then
  (`CatalogIngest::buildTombstones()`, one collection;
  `enqueueBuilt()` queues them). Both hooks act only when the import data
  source says `catalog_product` + `delete`: Replace deletes through the
  same code but creates the products again at once. Every product import
  reads its bunches through the plugin, so that answer
  (`ProductImportDelete::isProductDelete()`, two reads of
  `importexport_importdata`) is kept for the bunch iteration and asked
  again once the data source has read its last bunch. No ImportExport type
  is named (the module is removable), as with the MSI plugins. A tool
  that writes the catalog tables itself, or a direct database delete,
  still sends nothing; the user guide says how to take such a product out
  of the recommendations (a product with the same SKU, saved disabled).
- **Stock changes (PRO-1951).** `in_stock` is the one catalog field the store
  can move without a product save, and the engine's back-in-stock feature
  reads it, so four hooks feed catalog ingest, all funnelling through
  `Engine\CatalogIngest`:
  `Observer/Engine/ProductSaveAfter` (`catalog_product_save_after`),
  `Observer/Engine/StockItemSaveAfter`
  (`cataloginventory_stock_item_save_after` — Advanced Inventory, the
  `products/{sku}/stockItems` endpoint, the non-MSI order decrement and
  restock) and one thin plugin per MSI seam:
  `Plugin/Engine/SourceItemsSave` on
  `InventoryApi\Api\SourceItemsSaveInterface` and
  `Plugin/Engine/SourceDeduction` on
  `InventorySourceDeductionApi\Model\SourceDeductionServiceInterface`. On a
  default 2.4.x install MSI owns inventory and *placing* an order decrements
  nothing — it writes a reservation, leaving `is_in_stock` untouched; the real
  decrement is the shipment's source deduction, and the credit-memo return to
  stock is its mirror. Both go through `SourceDeductionService`, neither
  through `SourceItemsSave`, hence the two seams. MSI is treated as an
  optional dependency: neither plugin names an MSI type at all, so on an
  install without the Inventory modules they are simply never wired and the
  legacy observer covers everything.
  `CatalogIngest` is also where the engine-connected gate is checked — once,
  for every path including the delete tombstone and the backfill pages.
- **Stock hooks record, the flusher builds (PRO-1967).** A stock write runs
  inside the order, shipment, credit-memo or import transaction, so the three
  stock hooks build nothing there: they queue one `catalog_changed` row per
  product (payload `[]`, `entity_id` = the product id; a queue domain of its
  own, never sent and never claimed by the catalog send). The legacy observer
  knows the product id (`CatalogIngest::markProductChanged()`); the MSI
  plugins hand all of a write's skus to `CatalogIngest::markSkusChanged()`,
  which counts a repeated sku once — one `sku IN (...)` lookup
  (`ProductResource::getProductsIdsBySkus()`) and one multi-row insert
  (`IngestQueue::enqueueMany()`), so a 20-line shipment or a Sources-grid mass
  action costs two queries, not 20 product builds. Each `FlushIngestQueue` run
  starts with `CatalogIngest::buildChanged()`: it claims up to 100 markers,
  loads their products as one collection through `CatalogProductLoader` —
  the class that loads the backfill page too, so the scope and the selected
  attributes are the same (`CatalogPayloadBuilder::PRODUCT_ATTRIBUTES`; the
  canonical store set before `addUrlRewrite()`) — builds each row through the
  same `CatalogPayloadBuilder` (a tombstone for a product that left the
  sellable set), hands the rows to `IngestQueue::enqueueChangedPayloads()`,
  which queues them with one insert, and deletes the markers. The
  rows go out in the same run, so the ~1–2 min latency holds; the build reads
  the committed state at flush time, so it can never be older than the change
  that queued the marker. The rows are built in the cron context, as the
  catalog import rows are, and the running context does not
  change a row (PRO-3731): `CatalogPayloadBuilder` reads `image_url` under
  the frontend emulation of the store the product is priced at, so the image
  size and the placeholder come from that store's storefront theme, never
  from the running area (cron's `crontab` area has no theme, and the admin's
  is the admin theme — both gave a placeholder link that does not open, also
  for a product with an image). With Use Web Server Rewrites off, Magento
  adds the running script's name to each link (`Store::_updatePathUseRewrites()`
  — `magento` under bin/magento), which the emulation does not change;
  `productUrl()` puts the storefront's `index.php` in its place
  (`Model\StorefrontScript`, shared with the abandoned-cart links and the
  site address sent at the engine setup exchange, PRO-3733). Category,
  parent and website lookups are memoized across the batch; the frontend
  emulations for a product's URL and image link stay per product and store,
  one after the other (PRO-1458 — Magento allows one emulation level, so one
  emulation per batch cannot serve a product priced on another website).
  Markers are never collapsed at insert time: a marker skipped because one
  for the product was already queued could be built before the skipping
  transaction commits, losing its change. They collapse at build time
  instead — several markers for one product build one row — and the queue
  leaves out a built row when the product's newest unsent catalog row
  (pending or being sent; one row per product read in SQL,
  `IngestQueue::latestUndeliveredPayloads()`) is that very row, compared as
  the queue stores it, which is what keeps a product save at one
  row: it queues its row itself and reaches the stock hooks on the way. A
  product deleted between marker and build is not in the collection; its
  marker is dropped, since the delete observer already sent the §3b removal
  (or, for a variant, its tombstone). Markers wait while sending is not
  allowed; a build that throws is logged and the run still sends the other
  domains, and its claimed markers (like those of a run that died) stay
  `sending` until `requeueStale()` hands them back. Product save, the
  delete tombstone and the backfill pages still build at once
  (`enqueueProduct()`), where a byte-identical row queued twice in a row
  within one request is dropped.
- **`in_stock` source, deliberately the legacy flag (PRO-1951).** It is read
  from `CatalogInventory`'s `is_in_stock`, which MSI keeps synced, not from
  MSI's salable-per-website quantity. A catalog row is keyed on `sku` per
  tenant and one installation is one tenant, so a per-website salable answer
  has nowhere to go until the multi-website tenant work (RFC Phase 4, gated on
  PRO-1459) gives each website its own tenant. `CatalogPayloadBuilder::
  isInStock()` drops the stock registry's per-request memo immediately before
  it reads (MSI mirrors onto the legacy row with direct SQL and so never
  invalidates that memo — otherwise selling out publishes `in_stock: true`).
  It lives at the read, not in a caller, so live hooks, the delete tombstone
  and the catalog import pages are all correct by construction. It
  falls back to **true** if the stock read throws: the fallback is
  deliberate — the engine's recommender excludes out-of-stock products, so
  defaulting to false would silently pull a product out of every campaign over
  a transient read error, which is the worse failure. The product's next
  save or stock change, or a catalog import, corrects a wrong `true`.
- **Catalog lifecycle: the full catalog once, then changes (PRO-1968).** As
  in the WooCommerce plugin, the whole catalog reaches the engine through
  the catalog import (`Job::TYPE_CATALOG`, paged by `Cron/BackfillTick` →
  `Model/Backfill/EngineCatalogProcessor`), and after that only changes
  do: product save, the stock hooks' markers and the delete paths above
  (~1–2 min). Connecting starts the import (PRO-3741): both connect
  paths — the admin Connect (`Controller/Adminhtml/Api/EngineExchange`,
  setup step and Settings tab) and the config save behind `bin/magento
  config:set smaily_connect/intelligence/setup_token`
  (`Model/Config/Backend/EngineSetupToken`) — call
  `Model/Backfill/CatalogImportOnConnect::start()` after a successful
  setup exchange. It queues the job through `JobManager::startIfIdle()`, as
  the admin Start import does, so the one-active-job lock keeps a reconnect
  from starting a second import while one is queued or running (a
  reconnect after it ended starts a fresh one, which the engine
  deduplicates); a config save without a token exchanges nothing and
  starts nothing. EngineExchange answers `catalogImportStarted`, and
  `panels-js.phtml` then shows an info banner with **Hold back the
  import**, which posts the BackfillState `cancel` (`requestCancel()`);
  the Settings tab's Catalog card renders the cancel's answer, which
  carries the import's state.
  The window: the job is `pending` until the next `smaily_backfill_tick`
  (every minute) picks it up, and its first page can be flushed in that
  same cron run (`smaily_flush_ingest_queue` follows it in the group).
  Cancelled while pending, the tick never runs it — nothing is queued or
  sent. Cancelled once running, the processor stops at its next page
  boundary (100 products): rows it already queued are still flushed, the
  rest are never queued; a cancel landing between the tick's
  `nextActive()` read and `markRunning()` can still let that first page
  through. The banner's answer says "Held back" when the cancel answer
  counts nothing processed, else how many were queued (read before the
  worker's current page is recorded, so it can lag by one page).
  EngineSetupToken adds a notice message instead, which `config:set`
  does not print; the user guide points CLI users to
  `smaily:backfill:status` and the card's Cancel import. The merchant
  starts the import again on the card or with `smaily:backfill:start
  catalog`. Both refuse the start while Campaign Intelligence is not
  connected (`Model/Backfill/EngineImportGuard`, PRO-1969; the customers and orders
  imports likewise, PRO-3742 — every engine-target type of
  `Job::TYPE_TARGETS`, never the contacts import to Smaily) and say why —
  the admin endpoint (`Controller/Adminhtml/Api/BackfillState`) answers
  with a `message` the card shows beside its button, the command exits
  with an error; started anyway, the catalog import would record every
  product as failed, since `CatalogIngest` queues nothing while
  disconnected, and a customers or orders import would queue rows with
  no tenant to send them to. An import queued or running that no tick has
  moved for an hour — a worker that dies on the same page every run, or
  no cron — is answered with `stalled: true` (`JobManager::isStalled()`:
  `isActiveAndMoving()`'s rule, `JobManager::STALLED_SECONDS`), shown on
  the card as *Stalled* with **Run again**; every start — the card, the
  command, connecting Campaign Intelligence (`CatalogImportOnConnect`) —
  goes through `JobManager::startIfIdle()`, which cancels it first
  (`requestCancel()`), so the one-active-job lock cannot keep the new one
  from starting (PRO-3915, PRO-3923). The tick takes the oldest active job
  that is not set aside (`nextActive()`, PRO-3927): a job is set aside
  while it is `running` and its own row's `updated_at` (refreshed by
  `markRunning()` and every `recordProgress()`) is older than
  `STALLED_SECONDS`; it is taken again only when no other active job
  waits, so a lone one is retried every tick and recovers by itself after
  a passing failure. A `pending` job is never set aside — the tick has not
  taken it up, so a wait in line never counts against it. The processors
  call `markRunning()` before they count the import's total (PRO-3950), so
  a worker that dies counting leaves a `running` job this rule sets aside,
  not a `pending` one that blocks the line.
  `isStalled()` is true for an import with a set-aside job, or by
  the rule above, so its card stays *Stalled* and **Run again** cancels it
  first while the tick runs the imports behind it. An import waits behind
  another only while no active job moves at all — the job at the front is
  `pending` and the tick never gets it going (no cron); the endpoint then
  answers the waiting import with `blocked_by` (the type of the job
  `nextActive()` takes) and the card names the blocking import's card
  instead of offering **Run again** (PRO-3923). There
  is no periodic full re-sync any more — the nightly `Cron/CatalogResync`
  of PRO-1951 is removed. A change no event sees (a CSV / `bin/magento
  import` run, an ERP link or any other direct write to the catalog
  tables) reaches the engine with the product's next save or stock change,
  or with a catalog import the merchant starts by hand; the user guide
  says so. The engine contract states this pattern (v1.8.3, §3 *Catalog
  sync lifecycle* and §3b; PRO-3740): a full import at setup, then
  changes, each re-sending the whole row (the engine's UPSERT clears an
  optional field a row omits, so `CatalogPayloadBuilder::buildTombstone()`
  is the full row with `in_stock: false`, never a patch); a full import
  by hand whenever the merchant starts one; no scheduled full re-sync. A
  catalog import does not remove a product that is missing from it — the
  engine deletes by absence only through the nightly catalog manifest
  (contract v1.12.0, §3c, below), so a product deleted where no event sees
  it (a direct database delete, a tool that bypasses Magento's import)
  leaves the engine's recommendations with the next manifest, and a stock
  change no event saw is corrected by it. A release that adds a catalog field, or corrects what one holds,
  tells the merchant to start the catalog import by hand: no scheduled
  re-sync carries it, and the manifest carries only `sku` and `in_stock`.
  On an upgrade, Magento ignores the removed job's leftover `cron_schedule`
  rows: it reads and prunes only the job codes its configuration lists.
- **Nightly catalog manifest (PRO-3854; contract §3c, v1.12.0).** At 03:30
  in the admin's time zone (Magento reads `crontab.xml` schedules in it),
  `Cron/SendCatalogManifest` runs `Model/Engine/CatalogManifest::send()`:
  the store's complete product list as `{sku, in_stock}` only, in one
  `POST` to the setup map's `ingest_catalog_manifest`
  (`Engine\Client::catalogManifest()`; a connection set up before v1.12.0
  has no such key and falls back to the contract's path on the engine base
  URL, contract §1 "map age", as `catalogRemove()` does). The engine
  tombstones every product missing from it, takes its stock where it
  differs and counts products it does not have; its guard removes nothing
  for an empty list or one that would remove more than 20% of the live
  catalog. *The list* is every product the catalog import sends a row for
  — `CatalogProductLoader::loadForManifest()` pages the same collection
  at the same canonical scope as the import's `loadPage()` (one page
  helper for both), every type and visibility, configurable parents and
  their variants each under its own key — less the products disabled at
  that scope (owner decision 2026-10-07, as in the WooCommerce plugin: the
  engine stops recommending them). The page filters on `status` in SQL,
  added after `setStoreId()`, so it reads the canonical store view's
  value, falling back to the default one;
  `Test/Integration/Engine/CatalogProductLoaderTest` runs that select on
  Magento's own product tables. `manifestItem()` still returns null for a
  disabled product, as a guard. Each item is `CatalogPayloadBuilder::manifestItem()`: the row's
  own `sku()` (the SKU, `mag-<entity_id>` when empty) and its `in_stock`
  as the sync sends it — a product that left the sellable set (not
  visible on its own, as a configurable's variant) is sent by the sync as
  its tombstone, so it is `false` here too; otherwise `isInStock()`. The
  page selects only `status` and `visibility` and no URL rewrites, 1,000
  products a page by entity id until a page comes back empty (an early
  stop would read as deletions), and keeps two values per product; the
  stock registry's memo is cleaned after each page. Peak memory on the
  2,046-product sandbox catalog: about 7 MB above the process baseline
  (22 MB on a cold first run); a 50,000-item list is about 21 MB.
  *Built right before the send, never queued*: a queued payload would be
  as old as its row, so the cron builds and sends in one run and writes
  the Log row afterwards (`IngestQueue::newEvent()`, saved by
  `markSent()`/`markFailed()`), domain `catalog_manifest`, which no
  flusher claims. A failed send (after `Engine\Client`'s own in-call
  retries on 429/5xx, 60-second timeout) is parked `failed` at once; the
  next night builds a new list, and `Log\ResendGuard` refuses **Send
  again** / **Retry** for the domain (`nightly`). The row's payload holds
  the counts, its `sent_payload` the first 20 items plus
  `products_not_shown`, its `last_response` the engine's answer
  (`removed`, `stock_fixed`, `missing_in_engine`, `guard_tripped`, …).
  *Not sent* — logged at info level only, no Log row — while sending is
  not allowed (`Settings::isSendingAllowed()`: disconnected, or the
  remembered refusal), while the catalog import is queued or running
  (`JobManager::isActiveAndMoving()`), while undelivered (pending or sending)
  `catalog`, `catalog_remove` or `catalog_changed` rows wait — a parked
  `failed` row does not hold it back, healing it is the list's job — or
  when building the list throws. The import wait is bounded (Woo
  PRO-3886): the tick writes the job it advances at every page, so an
  import counts only while some queued or running job was written within
  the last hour (`CatalogManifest::STALLED_IMPORT_SECONDS`, which is
  `JobManager::STALLED_SECONDS`); one the
  worker no longer moves holds nothing back, and neither does a catalog
  job the tick has set aside (`nextActive()`'s rule), however busy the
  imports behind it keep the worker (PRO-3950). A store with more than
  50,000 enabled products sends nothing (§3c forbids a partial list); its Log row
  is `failed` with `CatalogManifest::TOO_MANY_PRODUCTS`, shown in the
  admin's language (`Log\FailureMessage`). Each night no list goes out —
  skipped, not built, over the limit, or not taken by the engine — adds
  one to a count in the flag `smaily_connect_catalog_manifest_unsent`
  (`CatalogManifest::FLAG_UNSENT`, `{nights, reason}` with the night's
  `REASON_*`); a list sent, and a night when sending is not allowed (no
  list is due), delete it. A flag rather than a Log row: a skipped night
  has no exchange to show, and the Dashboard needs only this value; no
  table or column changes (PRO-3914).
- **Order line `sku`.** A line is the parent order item (a configurable's
  child item is skipped; its price is on the parent), keyed on the item's
  SKU — for a configurable, Magento's own copy of the chosen variant's SKU.
  An empty SKU falls back the way the catalog row does: `mag-<product
  entity_id>`. A configurable line's `product_id` is the parent's, so an
  empty-SKU configurable line keys on its child item instead (the variant's
  SKU, or `mag-<variant entity_id>`) and joins the variant's own catalog row
  (PRO-3715); any other line keeps its own product id.
- **Order return signals (contract §5, v1.8.0):** `items[].returned_at` is
  derived from the order's own credit memos on every build
  (`OrderPayloadBuilder`), never from a one-shot event — the engine replaces
  an order's items wholesale on re-ingest, so a later sync that omitted the
  field would erase the return. A line is marked returned only once its FULL
  quantity has been credited (a partly credited quantity stays kept, §5);
  neither reason field is sent, because Magento has no structured return
  taxonomy to map from. Only a refunded credit memo
  (`Creditmemo::STATE_REFUNDED`) counts (PRO-3798): a canceled one gave
  nothing back, and a pending (open) one has not refunded yet. Every
  Magento refund path stores its credit memo as refunded
  (`CreditmemoManagementInterface::cancel()` throws, and nothing in core
  sets the open or canceled state), so the other two come only from an
  extension. Such a state change saves an existing credit memo, which
  queues nothing on its own (`CreditmemoSave` below queues only a new
  one), so the corrected order reaches the engine with the order's next
  queued save or an order import. A partial credit memo leaves the order state alone,
  so `OrderSaveAfter`'s enqueue gate treats a moved `total_refunded` as a
  change worth re-sending. A credit memo that moves quantity and no money
  (the return of a fully discounted line, or one with an adjustment that
  cancels its money) moves neither, so the credit memo's own save queues
  the order too (PRO-1955): `Plugin/Engine/CreditmemoSave`, an
  after-plugin on the credit memo resource's `save()` — not the
  `sales_order_creditmemo_save_after` event, which fires before the credit
  memo's items, the rows the return is read from, are written — for a
  credit memo created by that save (one loaded from the database carries
  its original data). **One refund, one order row:** a refund saves the
  credit memo and the order one after the other, and each queues the
  order, in either order — the admin refund (`CreditmemoService`) saves the
  credit memo first and closes a fully refunded order at the order save;
  the REST refunds (`RefundOrder`, `RefundInvoice`) save the order first,
  before the credit memo, so the row the order save queued carried no
  `returned_at` until a later save. `Model\Engine\OrderIngest`, the one
  place an order becomes an ingest row, collapses the two: the later build
  replaces the payload of the row the earlier one queued while that row is
  pending and untried (`IngestQueue::replacePendingPayload()`), and an
  identical build queues nothing. Only builds next to each other for one
  order, one of them a credit memo's, collapse; order saves without a
  refund each queue their row, as before. The money gate stays: it is what
  catches a refund's order save, which the collapse then folds in.
- **A refused account stops every send path (PRO-2451).** Contract §2 answers
  `403 tenant_inactive` from every API-key-authenticated endpoint when the
  tenant is deactivated (operator suspension or a GDPR purge — the wire never
  tells the two apart, and the correct reaction is the same). `Engine\Client`
  records it at the single chokepoint every engine call passes through
  (`Engine\Settings::recordRefusal()`, the FIRST refusal's timestamp at
  default scope) and clears it on any authenticated call that answers — the
  health-check ping and the admin's "Check again" are the only calls that
  still go out. `Settings::isSendingAllowed()` (`isConnected() && !isRefused()`)
  is the one gate every SENDING path consults: `Cron/FlushIngestQueue`,
  the engine-bound backfills at `Cron/BackfillTick`'s router,
  `Controller/Relay/Index`, `Queue\Handler\IdentityMergeHandler`,
  `Queue\Handler\ProfilingConsentHandler` and the nightly
  `Engine\CatalogManifest`. The
  paths that loop re-ask it as they go, because a mid-run 403 can change
  its refusal half: the flusher only that half, per domain, once
  connectedness is proved (so one refusal ends the run); the merge and
  profiling-consent handlers per row (`Settings::sendingBlockedReason()`:
  a refusal leaves the row `Queue\Pending`, not connected names the
  reason).
  `isConnected()` stays the gate for everything that only enqueues, reads or
  displays, so live observers keep queueing and the store's history is intact
  when the account comes back. Rows a refusal met
  mid-batch go back to pending untouched (`IngestQueue::release()` — never
  burned, never failed, attempt counters intact); the janitor prunes them by
  its normal age rule.
- Browse events are the exception: loss-tolerant by design, they are relayed
  synchronously (`Controller/Relay/Index`) and never queued. The relay makes
  one short attempt (`Engine\Client::relayBrowse()`: 3 s, 2 s to connect,
  no retry, no back-off wait), so the engine never holds a storefront request.
- **Catalog price/URL/language scope (PRO-1352/1353):** one Magento
  installation is one engine tenant with one base currency (which every
  catalog row now names, contract §3 `currency`, v1.7.0) — the plugin, not
  the engine, is responsible for always sending one consistent scope's
  price. Both catalog
  ingest paths resolve through the SAME single canonical store
  (`CatalogPayloadBuilder::canonicalStoreId()` — the default store view of
  the default website, the same "default scope" concept as `Engine\Client`'s
  base-URL fallback and `Multilingual\AccountResolver`'s default account),
  never Magento's implicit current-store resolver: the backfill collection
  (`CatalogProductLoader`, which loads the import's page, `loadPage()`,
  and the stock-change batch) calls `setStoreId()` with it
  explicitly before `addUrlRewrite()`, and the live path
  (`ProductSaveAfter`/`ProductDeleteBefore`) re-scopes the product to it via
  `ProductRepository::getById($id, false, $canonicalStoreId)` before reading
  price whenever the admin save/delete didn't already resolve that same
  scope. This is a deliberate single-pinned-scope simplification, not a
  per-website fan-out: still one payload per product, so a multi-website
  installation with divergent per-website prices ingests one website's price
  per product. Each `smaily_ingest_queue` catalog row's `store_id` column
  records that canonical ingest scope for audit — not necessarily the store
  the price was read at, see the exception below.
- **The backfill page holds every product (PRO-2506):**
  `CatalogProductLoader` calls neither `addPriceData()` nor any store/website filter, so it pages exactly
  what `countProducts()` counts. `addPriceData()` INNER JOINs
  `catalog_product_index_price` on the scope's website (Magento's
  `Product\Collection::_productLimitationPrice()`), which kept every product
  outside the default website out of the catalog import — and every
  product the price index leaves out (disabled, and out of stock while the
  store hides out-of-stock products), so the import never corrected those
  either. Prices are read through the product's price
  info at the store `storeIdForProduct()` picks, as on the live path; the
  page selects `tax_class_id`, which the price index used to supply and the
  tax adjustment reads. A disabled or hidden product is sent as its
  tombstone (`in_stock: false`).
- **The backfill page selects what a product save holds (PRO-3692):** a
  collection item carries only the static columns and the attributes
  `CatalogProductLoader` selects, while a saved product carries all of them. The list
  holds every attribute the payload and Magento's price classes read:
  `special_from_date`/`special_to_date` (`SpecialPrice` checks the sale
  window; `on_sale_until` is `special_to_date`) and `price_type` (the bundle
  price classes price a bundle as fixed or dynamic by it).
  `EngineCatalogImportParityTest` sends one product through both paths
  and compares `price`, `compare_price` and `on_sale_until`. The flat
  product catalog does not apply: `Product\Collection::isEnabledFlat()` asks
  `Flat\State::isAvailable()`, which is true only in the frontend area
  (Magento_Catalog `etc/frontend/di.xml`), and the import runs in cron.
- **The one exception — a product outside the default website
  (PRO-1458):** such a product has no price of its own at the canonical
  scope, so reading it there reports a scope it never sells in. It is priced
  — and its URL built — at the default store view of the first website it IS
  assigned to (lowest website id; `CatalogPayloadBuilder::storeIdForProduct()`).
  The URL is generated from the product loaded at that same store, because
  Magento's URL model reads the rewrite and the base URL off the product's
  OWN store, not off whatever store is emulated around it. A product
  assigned to no website at all keeps the canonical scope and is still
  ingested, never skipped. The row's `currency` still names the canonical
  store's display currency: an install whose second website has a different
  base currency is mislabeled there, and per-website tenants (the
  multi-website RFC's Phase 4, PRO-1762) are the fix for that, not this.
- **Storefront URL (PRO-3660):** a store that sells on a separate
  (headless) storefront saves its address at website scope
  (`smaily_connect/connection/storefront_url`, Settings > Connection and
  the initial setup's Connect step — the same `panel/connection.phtml`
  field, posted by `panels.collect.connect()` and checked and saved by
  `WizardStepSaver::saveConnect()`, PRO-3802; the setup saves it with the
  connection, before its Contacts step switches contact sync on).
  `Model\StorefrontUrl::apply()` replaces the scheme, host and port of the
  product link with it — path and query string kept — read at the store the
  link was built for: `CatalogPayloadBuilder::productUrl()` after the
  frontend emulation, `Rss\FeedBuilder` for each item's `<link>` / `<guid>`.
  Image links never pass through it. The stored value is normalized again on
  read, so a value set past the admin's check (`config:set`) that is not an
  https host alone rewrites nothing. The field opens by itself from
  `Model\OrderOrigin`: `Observer\RecordOrderOrigin`
  (`sales_order_place_after`) stamps each order's time into one of two
  `smaily_connect_*` flags — an API order is placed in the `graphql` area, or
  in `webapi_rest` without the `form_key` cookie of Magento's storefront
  session; an order an admin places otherwise (`Model\OrderPlacer::isAdmin()`,
  the attribution stamp's rule: the admin's order screen or "Login as
  Customer") stamps neither flag (PRO-3949); every other order (Luma's
  checkout REST call carries the cookie) is a storefront order.
  `isApiOnly()`: an API order and no storefront order in the last 30 days. Installation-wide; no table or column.
  Connecting starts the catalog import, so `panel/intelligence.phtml`
  adds a note under the setup URL field while
  `WizardData::getSavedStorefrontUrl()` is empty for the selected website:
  set the Storefront URL first, or hold the import back (PRO-3745). The
  setup step points back to its Connect step (PRO-3802); Settings >
  Intelligence, which has its own Connect, points to the Connection tab.
  The note sits in the disconnected block, so it goes once connected. It
  is always drawn there, hidden while a Storefront URL is saved, and a
  successful Connection save shows or hides it by the Storefront URL it
  posted (`panels.saveStep('connect')` in `panel/panels-js.phtml`,
  PRO-3747): the Settings tabs and the setup steps switch without a
  reload.

### Queue semantics (both queues)

- **Retry policy:** max 5 attempts, the next one 60 s / 5 min / 15 min /
  1 h after a failure (`BACKOFF_SECONDS`; the fifth failure parks the row,
  so a row's attempts span about 81 minutes and the last step, 6 h, is
  never waited), then parked as `failed` for manual retry from the admin
  Log.
  Only failures that can plausibly pass later get those attempts: a
  permanent refusal (4xx other than 429) stops on the first one, parked as
  `failed` with a `permanent_http_<code>` reason. A 429 is parked for the
  `Retry-After` the response asked for (delta-seconds form, capped at 6 h;
  an HTTP-date falls back to the ladder step). Both clients type the
  failure where they throw it: the Smaily client a
  `Client\Exception\RequestRefusedException` for a 4xx other than 429
  (`AuthenticationException` and `PlanBlockedException` are kinds of it)
  and a `TransportException` for a 429, a 5xx or a network failure; the
  engine client an `EngineRequestException` or an
  `EngineTransportException`. In the marketing queue, one seam applies the
  verdict (PRO-1961): `Model\Queue\Failure::of()` reads the type of
  whatever a handler hands back or throws — a Smaily refusal, an engine
  refusal of an identity merge — and `Failure::permanent()` carries a
  handler's own verdict that a row can never be sent (a malformed payload,
  an event type no handler takes), parked on its first attempt with that
  reason and its real attempt count. `Cron\FlushEventQueue` applies it
  through `EventQueue::markFailedMany()`, once per verdict (PRO-1964): the
  rows that share one — Smaily's one answer to a contact-sync group, the
  same refusal of several automations, a type no handler takes — are
  recorded in one UPDATE (CASE on the row id for the values that differ)
  and, for the rows it parks, one error-log line ("Queue events failed
  permanently", with their count and ids). Each row still takes its own
  step on the ladder — attempts, status and next retry follow its own
  attempt count — and keeps its own exchange; a row with a verdict of its
  own (among them each contact a 203 group sends again alone) goes
  through `markFailed()` as before. `Engine\Client` +
  `Cron\FlushIngestQueue` do the same for the ingest queue through
  `IngestQueue::markFailedMany()` (PRO-3962): a batch the engine does not
  take — a transport failure, a whole-batch refusal, a refused
  catalog/remove wrapper — and the per-item errors of a D6 reply that
  share a reason are each recorded in one UPDATE and, for the rows it
  parks, one line ("Ingest events failed permanently"). Both queues write
  through the one `Model\Queue\RowWriter`, and a row parked alone gets the
  same line as a group, with a count of 1.
- **Smaily envelope codes (PRO-1962; proposed as the cross-platform
  canon for the WooCommerce and Shopify plugins).** Smaily can refuse a
  request with HTTP 200 and a non-101 code in the body (an
  `ApiException`). Code **203 "invalid data" is permanent**: identical
  data is rejected again, so the row fails on its first attempt as
  `permanent_envelope_203: <message>` (the Log strips the class from the
  error column and its filter, as it strips `permanent_http_<code>:`, and
  Details shows it as the failure class). **Every other non-success code
  keeps the retry ladder**, as before: 206 "email not found" is read by the
  purchase-marker check itself (PRO-3619), and no other code is known to be
  final. The list is `ApiException::PERMANENT_CODES`; adding a code there
  is the whole change.
- **A 203 on a group of contacts (PRO-3753).** Smaily answers one
  `{code, message}` per request, never per contact, so one invalid contact
  makes it refuse the whole group. When a `contact.sync` group (one store
  view's rows of a flush, up to the 200-row batch) or a contacts-import
  page (up to 500 per store view, `Backfill\ContactsProcessor`) is
  answered with 203 (`ApiException::isInvalidData()`), each of its
  contacts is posted again alone in the same run, through the same client: the valid ones sync, and only the
  refused one fails (`permanent_envelope_203`, Smaily's answer as its last
  response; the import counts it as failed). WooCommerce posts one contact
  per request, so the outcome is the same. A single contact's 203 is not
  sent again, and any other failure of the group stays the group's.
  **Request cost:** 1 + the group's size requests, only on a 203; every
  other outcome is one request per group, as before. An import page split
  this way can run past the tick's 20-second budget, which is checked
  between pages.
- **Claiming:** rows are claimed with a per-worker `claim_token`; only rows
  the worker actually won are processed, so concurrent flushes (manual cron,
  multi-node) can never double-send. Rows stuck in `sending` (killed
  worker) are requeued after 15 minutes. Every outcome of a row —
  `markSent()`, `markFailed()`, `markSkipped()` and the batched
  `markFailedMany()` — clears `claim_token` and `claimed_at` and sets
  `updated_at` to the outcome's time (PRO-3961): the row model still holds
  all three as they were before the claim, and the model's save writes
  every column it holds. So `updated_at` is the time of the row's latest
  outcome, the time that Details, the Log grid, the Dashboard's recent
  activity, the failure count of the health check and the retention sweep
  read.
- **Idempotency:** `event_uuid` is unique; callers may pass a deterministic
  UUID to make an enqueue idempotent.
- **Exchange evidence (PRO-1965, Woo F3-44 parity):** both clients keep
  the last request body and the reply to it (`lastExchange()`: HTTP status
  plus the decoded body, or the start of a non-JSON body via
  `Model\Client\ExchangeResponse`; no reply on a network failure; never a
  credential). The handlers and `Cron\FlushIngestQueue` hand it to
  `recordExchange()` on the queue, which sets `sent_payload` and
  `last_response` on the row model; `markSent()` / `markFailed()` store
  them with the outcome. A batched row keeps only its own part of the body
  in the wrapper's shape (`[contact]`, `{products: [item]}`,
  `{product_ids: [id]}`), and a D6 row only its own `errors[]` entries.
  An outcome that names no exchange keeps the one the row holds, so a retry
  never erases the evidence of the attempt before it (PRO-1963). A row that
  never reached the wire (a skip, a withdrawal, a refusal before any
  request) has no `sent_payload`, and the Details drawer says so. Nothing
  of it is logged; the drawer shows it through `PayloadRedactor`, and the
  Art. 17 eraser already anonymizes both columns.
- **Sending again (PRO-2454):** the Log's per-row **Send again** and the
  mass **Retry** both ask `Model\Log\ResendGuard` first — one server-owned
  answer, shaped after Woo's `TransactionalRetryGuard`: a reason code
  (`withdrawn` / `superseded` / `erased`, `nightly` for a catalog manifest
  row, plus the plain `not_failed` — only a failed row is sent again at
  all) with the merchant sentence beside it,
  or `''` for a row that is safe. `superseded` is the only one that costs a
  query: for an `automation.trigger` row it asks whether a LATER row of the
  same trigger and the same recipient was delivered (and not itself
  withdrawn), reading each row's trigger out of its payload. That query
  belongs to the table's owner (`EventQueue::laterDeliveredOfSameTrigger()`)
  and answers for a whole grid page or mass-retry selection at once. Ingest
  rows are idempotent upserts and `contact.sync` repeats a state, so only
  `erased` reaches them. Send again NEVER touches the failed row: it
  enqueues a NEW row with the same event and payload — the failed row is
  the history, the new row is the audit record (Woo DECISIONS, same call) —
  and the decision rides in the new row's payload under
  `Model\Log\Resend::PAYLOAD_KEY`
  (`_resend: {of, by, at}`, Z-suffixed), which both `decodePayload()`
  methods strip before anything goes on the wire. No column was added for
  it. The mass retry skips what the guard refuses and says how many. It
  never lists the selection (PRO-2510): `Controller\Adminhtml\Log\MassRetry`
  takes the grid's selection from the listing's data provider as an
  unloaded `Log\Collection` (filters plus the ticked or excluded log ids,
  instead of `Filter::getCollection()`, which loads every row of a "Select
  all" and re-filters by all their ids), and `Model\Log\SelectionRetry`
  reads its failed rows' log ids in one query and asks the guard and the
  queue about them `BATCH_SIZE` (1,000) ids of one queue at a time. A
  retried row turns pending, never delivered, so a batch's guard answer is
  the one the whole selection would get.
  `withdrawn` is a derived status, not a stored one: one rule — `status =
  sent` plus the `CANCELLED_RESPONSE` marker — applied by the grid's UNION
  and by `Model\Log\QueueRowLoader` for the single row the drawer and the
  resend route read, so the label, the status filter and the drawer all read
  one value, and the flusher still sees the terminal `sent` row it wrote.
  The grid's error column and the Details drawer both show the server's own
  message through `Model\Log\FailureMessage` (`Queue\Failure`'s
  `permanent_http_<code>:` prefix stripped — its shape is
  `Failure::CLASS_REGEX`, which the SQL filter below reads too —
  `PayloadRedactor` applied); the
  classification stays in the drawer. Deliberate exception (PRO-2508): a
  Smaily 401/403 stores the client's own sentence ("Smaily API credentials
  were rejected") instead of Smaily's body, because it names what to fix;
  the body stays in `last_response`. The package refusal is a client
  sentence too. Any other Smaily HTTP error quotes Smaily's answer after
  the status, as the engine client does (PRO-3749): "Smaily API request
  failed with HTTP <code>: <answer>", the answer being the JSON envelope's
  `message`, else the body's text without markup, cut to 500 characters;
  a JSON answer without a `message`, or an empty one, leaves the status
  alone. The grid's
  text filter on that column matches the shown text, not the stored value
  (PRO-2509): `Log\Collection::addFieldToFilter()` strips the same prefix
  in SQL, adds a LIKE per client message whose translation in the admin's
  language holds the term (`FailureMessage::storedPatternsShowing()`), and
  never matches an error the column shows as redacted JSON (`JSON_VALID`
  object/array), so a filter cannot probe a hidden secret.
- **Retention:** sent 30 days, failed 90 days (`Cron/QueueJanitor`). The
  same job sweeps `smaily_abandoned_cart` (PRO-2469) — it owns the schedule
  and the window, while the SQL stays with the table's owner
  (`StateManager::pruneTerminal()` / `pruneOrphans()`): a terminal row
  (`mailed`, `skipped`, `completed`, `expired`, `erased`) older than the 30-day window
  goes, and so does any row whose `quote_id` is no longer in `quote`,
  whatever its status; Magento's own quote cleanup does not cascade onto the
  side table, and a marker with nothing left to mark is dead weight. A live
  quote in a non-terminal status is never touched: that row is what keeps
  `Cron/AbandonedCart` from mailing the same cart twice.
- **Art. 17 erasure** does not wait for retention. `Model\Privacy\LocalEraser`
  (driven by `Console\Command\GdprCommand`) walks BOTH queue tables plus
  `smaily_abandoned_cart` for one address: a row that could still send
  (`pending`, `sending`) is DELETED, a row that is over (`sent`, `failed`)
  is ANONYMIZED in place — `entity_id`, `payload`, `sent_payload`,
  `last_response` and `last_error` become one placeholder, keys and
  structure kept, so the merchant keeps the record of the send. A row is
  matched by its `entity_id` (the address, or for a profiling-consent row,
  and a contact row of an address longer than the column, the opt-out
  record's keyed hash of it — PRO-3765, PRO-3767) or by DECODING the stored JSON (`Model\Privacy\PayloadAnonymizer`),
  never by searching its raw text: `json_encode` escapes a non-ASCII
  address, which a substring search would miss. The walk reads a narrow
  column list in 1000-row chunks and deletes or anonymizes each chunk's
  matches in one transaction before reading the next, so peak memory is one
  chunk; a blob is decoded once and the same decoding answers the match and
  feeds the redaction. The queue tables are `Cron\QueueJanitor::TABLES` —
  the pair the retention sweep prunes — and the cart rows go through
  `Model\AbandonedCart\StateManager`, their only owner. Both `retry()`
  methods skip a row carrying `Model\Privacy\Erasure::PLACEHOLDER` —
  reviving one would put the placeholder on the wire.
  **A cart row is always ANONYMIZED, never deleted (PRO-2467)**, whatever
  its status: it is not a message but the marker saying this quote has been
  handled, and the module may not touch the core `quote` table. Delete it
  and a quote that is still active and idle past the cutoff (a merchant who
  ran only `smaily:gdpr erase`, not Magento's own customer deletion) looks
  untracked to the next sweep and is mailed to the address just erased. What
  stays is an email-less tombstone — `email` NULL, status
  `StateManager::STATUS_ERASED` — which `excludeHandled()` covers like
  any other terminal status, so `Cron\AbandonedCart` neither mails that
  quote nor tracks it afresh, and the status no longer reads `mailed`, so the
  PRO-2453 purchase marker never fires for an erased contact either. The
  tombstone carries no special retention — it leaves on the ordinary 30-day
  cart sweep, like any other terminal row. The export lists cart rows by
  address, so a tombstone is by construction unlisted — there is no address
  left to match, and the row holds nothing else personal. Two writes may
  still reach a tombstone, and neither revives it (PRO-2469): a converting
  quote keeps the row `erased` instead of marking it `completed`, and a
  later checkout opt-in may write the address the shopper types afresh onto
  the row while the status stays `erased`, so no reminder is scheduled.
  **An active cart the tracker has not seen gets a tombstone too
  (PRO-3693)**: `StateManager::eraseForEmail()`, after the tracked rows,
  reads the
  active quotes whose `customer_email` or any `quote_address.email` is the
  address (case-insensitive) and writes an `erased` row for each one that
  is not erased yet — inserted, or an existing row (an `open` opt-in row
  under another address, say) turned into one. Without it, a cart idle less
  than the cutoff, or a guest's typed email the scan has not reached yet,
  would be mailed on the next sweep. The quote rows are only read. Its
  count is added to the cart rows' `anonymised` count.
  Counts and exported rows come back under merchant-facing labels (Queued
  messages, Engine queue, Abandoned carts), which is what the CLI prints.
  `smaily_order_attribution` is deliberately untouched: it holds only
  order id, recommendation id and visitor/session tokens, no contact
  identifier, and the engine-side erasure covers the engine's copy.

## Database tables

| Table | Purpose |
|---|---|
| `smaily_event_queue` | Marketing event queue |
| `smaily_ingest_queue` | Engine ingest queue |
| `smaily_abandoned_cart` | Per-quote send state (`open`/`mailed`/`skipped`/`completed`/`expired`/`erased`) + checkout opt-in flag (the core `quote` table is never altered) |
| `smaily_automation_mapping` | (website, trigger, language, account) → workflow |
| `smaily_backfill_job` | Chunked import jobs (cursor-resumable) |
| `smaily_order_attribution` | Recommendation attribution per order (sales connection) |

All schema is declarative (`etc/db_schema.xml` + whitelist). Both queues are
indexed for the reads their cron drains do (`status`/`domain` + `next_retry_at`,
`created_at` for the janitor); `smaily_event_queue` additionally carries
`(entity_id, event_type, status)` for the per-contact lookup
`EventQueue::cancelPendingAutomation()` runs on every order placed.
`Setup/Patch/Schema/MigrateLegacyQuoteColumns` drops the legacy 2.8.x
artifacts (`quote.reminder_date`, `quote.is_sent`, `smaily_customer_sync`)
because a renamed module's declarative schema cannot.
`Setup/Patch/Data/MigrateLegacyConfig` maps the 2.8.x `smaily/*` config rows
onto the v3 paths and, once every scope is written, deletes them at every
scope (the plaintext password among them); a downgrade to 2.8.x starts with
empty settings. A legacy *Enable Module = No* (default or website)
becomes contact sync, welcome and abandoned cart off at that scope (a
website with its own Yes under a default No gets the three at its scope with
their 2.8.x values); a store-view Smaily account row (2.8.x never read it) is not carried over, and
an admin notice names those store views.

Uninstalling removes what declarative schema does not: `Setup\Uninstall`
deletes the `smaily_connect/*` and legacy `smaily/*` config rows at every
scope and every `smaily_connect_*` flag row. Magento calls it on a composer
`module:uninstall`; `module:uninstall --non-composer` (app/code) only
reverts data patches, so `Setup/Patch/Data/RemoveSettingsOnUninstall`
(apply is a no-op) runs the same removal from `revert()`. Disabling runs
neither.

## Cron jobs (group `smaily_connect`)

The group runs in a separate process (`etc/config.xml`). Host cron should
invoke `bin/magento cron:run` every minute.

| Job | Schedule | Does |
|---|---|---|
| `smaily_flush_event_queue` | every minute | Drain marketing queue |
| `smaily_flush_ingest_queue` | every minute | Build the rows of stock-changed products, then drain engine queue (all domains) |
| `smaily_backfill_tick` | every minute | Advance the oldest active import one time-budgeted chunk |
| `smaily_abandoned_cart` | every 5 min | Scan idle quotes, enqueue automations |
| `smaily_contact_reconcile` | every 15 min | Smaily→Magento consent mirror |
| `smaily_health_check` | every 15 min | Engine-down / failure-volume / missing-consent-source notices |
| `smaily_queue_janitor` | daily 02:20 | Retention pruning (both queues + the abandoned-cart tracker) |
| `smaily_catalog_manifest` | daily 03:30 | The store's complete product list to the engine (contract §3c), when nothing holds it back |

## Key flows

### Consent reconcile (consent mode only)

`Cron/ContactReconcile` polls Smaily's action log
(`GET /api/history.php?since_seq_id=…&actions=optin,optout,delete,complaint`,
comma-separated — note the Woo reference's bracket-array form is a latent
bug there, not here) per **resolved account** (a website's own account, plus
one per distinct mode-A per-language account — `Multilingual\AccountResolver`,
deduplicated by resolved credentials) with a durable cursor per account
(`FlagManager`; a website's own account keeps its pre-existing
`smaily_connect_reconcile_seq_w<websiteId>` key, an additional per-language
account gets a `_<accountKey>`-suffixed one, since the action log's `seq_id`
numbering is per Smaily account), and mirrors state onto
`newsletter_subscriber` inside the `ContactSync\ReconcileGuard` so the
subscriber-save observer never echoes the write back to Smaily. Writes use
import mode — no Magento emails.

### Abandoned cart

`Cron/AbandonedCart` scans the native `quote` table (active, has items +
email, idle past cutoff, younger than 24 h; 100 per run per website, oldest
quote id first), leaves out in the same SQL every quote whose
`smaily_abandoned_cart` row is terminal (`StateManager::excludeHandled()`: a
LEFT JOIN on the unique `quote_id` with the terminal statuses in the ON
clause, `WHERE ... quote_id IS NULL` — one lookup per quote on that unique
index, an antijoin in MySQL's plan), marks `mailed` **before** dispatching
(a crash costs one reminder, never a duplicate), and enqueues the
automation. The payload's `abandoned_cart_url` is an HMAC-signed
`smaily/cart/restore?id=&ts=&token=` link (`AbandonedCart\RestoreTokenManager`,
keyed with the installation crypt key) that restores the exact quote. `ts` is
the moment the reminder was created, inside the signature; the link expires
30 days later and then lands on the cart page with a notice, restoring
nothing. A link without `ts` (issued before links carried it) is accepted for
30 days after the tracker row's `mail_sent_at`, and is expired without one.
The reminder is built in cron, under frontend emulation of the cart's store;
with Use Web Server Rewrites off, Magento still puts the running script's
name in `abandoned_cart_url` and `store_url` (`magento` under bin/magento),
so `Model\StorefrontScript` puts the storefront's `index.php` in its place,
as for catalog product links (PRO-3732).

**One build per store (PRO-1960).** Before the reminder rules below weigh
the page, the cron builds every candidate's payload, grouped by store: one
frontend emulation per store around the whole build (Magento allows one
emulation level, so nothing inside it emulates), and
`PayloadBuilder::buildAll($storeId, $quotes)` loads the products of all that
store's carts in one collection (each product once, only the lines that get
one of the ten slots) and resolves each product's description, image link and
regular price once, whatever number of carts hold it; the store's own fields
(name, `store_url`, group, website) resolve once per build, and each cart
keeps its own `abandoned_cart_url`. The regular price
still comes from Magento's price model, so configurable and tax-inclusive
prices are what they were. The rules then weigh the carts oldest id first,
whatever their store. A build that fails leaves the whole page for the next
run. On the sandbox (40 carts, 129 cart lines, 12 products) the builder's
own queries went from 92 (two per cart, plus 12 for the prices of the four
configurable products) to 14 (the same at 10 and 20 carts), payloads
byte-identical with rewrites on and off. Magento's own cart loading (`getAllVisibleItems()`) stays per
cart; with *Enable Inventory Check On Cart Load* on (Magento's default) it
runs MSI's stock checks for every cart line, about 21 queries a line — most
of a page's build queries. That check is Magento's and is left as it is.

**Handled carts never fill the page (PRO-3711).** A handled cart (a
terminal row: `mailed`, `skipped`, `completed`, `erased`, `expired`) that is
still active keeps matching the scan's `quote` filters for the rest of its
24 hours. The terminal rows were once dropped in PHP after the page of 100
was loaded, so on a store with more than 100 such carts in the window the
page held only handled carts and a newer cart was never loaded. The
exclusion is in the SQL, before the LIMIT, so the page holds only
candidates.

**The scan reads only the window (PRO-3730).** The page is ordered by
`main_table.entity_id + 0`, not by the bare `entity_id`. Ordered by the
column, MySQL and MariaDB switch, once more than a few thousand carts
changed in the window, from Magento's `QUOTE_STORE_ID_UPDATED_AT` index to
walking the primary key in id order from the store's first cart until 100
candidates turn up — on a store that keeps old carts, the whole table
(3M carts: ~3.07M rows, ~1.8 s per website per run). An expression cannot
be read from an index, so the plan stays on the store/updated_at range and
sorts the window (3M carts, 60k changed in 24 h: the 59k window rows, about
50–100 ms). The order is the same, so the page holds the same 100 carts. An
index hint (`IGNORE INDEX FOR ORDER BY (PRIMARY)`) does the same, but
`Zend_Db_Select` cannot place one after the table alias.

**One reminder per address per 24 hours (PRO-3693).** Once per page of
candidates the cron reads `StateManager::addressesRemindedSince()`: the
addresses (lower-cased) of the tracker rows with a `mail_sent_at` in the last
24 hours — a reminder delivered or still queued, for any cart, whatever the
row's status now. A candidate whose resolved address is in that set is marked
`skipped` (terminal, no `mail_sent_at`, so it is never weighed again and does
not start a new 24 hours) and the automation is recorded through
`SyncDispatcher::recordSkippedAutomation()`: `EventQueue::enqueueSkipped()`
stores the row already closed as skipped (`sent`, no `sent_payload`,
`Cron\AbandonedCart::SKIPPED_RECENTLY_REMINDED` in `last_error`, translated by
`Log\FailureMessage`), so the Log shows what would have gone out and why it
did not, and the flusher never claims it. A cart the cron marks `mailed` adds
the address its row holds (`StateManager::trackedAddresses()`, or the
resolved address for a new row) to the set before the next candidate, so two
carts of one address in one run get one reminder.

**Where a guest's email comes from (PRO-3693).** Magento's checkout keeps a
guest's email in the browser (`quote.guestEmail`) and sends it only with
set-payment-information / place-order; `isEmailAvailable` saves nothing and
shipping-information carries no email. So the module captures it, as the
WooCommerce plugin captures the billing email before submit:
`view/frontend/web/js/view/form/element/email-mixin.js` (a mixin on
`Magento_Checkout/js/view/form/element/email`, declared in
`view/frontend/requirejs-config.js`) posts the address when Magento runs its
own `checkEmailAvailability()` — only for a value the component validated,
after its `checkDelay` typing pause — and once at `initialize()` when the
field opens with an address Magento validated earlier. One request per
change of the value (shared by every email field on the page), none for a
signed-in customer, none while the website's abandoned-cart automation is
off (`window.checkoutConfig.smailyGuestCartEmail`, from
`AbandonedCart\GuestCartEmailConfigProvider`, added to Magento's
`CompositeConfigProvider` in `etc/frontend/di.xml`; the endpoint still checks
the switch itself), fire-and-forget. The endpoint is the anonymous REST
route `POST /V1/smaily-connect/guest-carts/:cartId/email` (`etc/webapi.xml`
→ `Api\GuestCartEmailInterface` → `AbandonedCart\GuestCartEmail`), keyed on
the masked cart id as Magento's own guest-carts routes are. It writes
`quote.customer_email` and nothing else, with one UPDATE on the checkout
connection — the quote model or repository would collect totals and fire the
quote save events — and only on an active guest cart (`customer_id` null,
repeated in the UPDATE's WHERE) with items, of a website with the
abandoned-cart automation on, after `Magento\Framework\Validator\EmailAddress`.
Every refusal answers `false` without saying why. Abuse guard, in the
application cache like the browse relay's limiter (fixed windows; a cache
flush resets every counter): 30 requests per caller per 10-minute window —
the `RemoteAddress` as it is for IPv4, its /64 for IPv6, the IPv4 address for
an IPv4-mapped one — 5 writes per cart (24 h, the scan's age limit), and 2000
accepted writes per hour for the whole installation, which bounds a caller
spread over many addresses. Behind a proxy whose forwarding header Magento is
not configured to read, every shopper shares the proxy's address, so the
per-caller limit becomes one limit for the whole store (USER_GUIDE, Abandoned
cart, says how to configure it). Hyvä's Luma-based checkout runs the same checkout JS, so the mixin
applies there; Hyvä Checkout and other third-party checkouts are not covered.
Who is reminded does not change: the automation still goes with
`force_opt_in=false` (`AutomationHandler`).

**The exit signal (PRO-2453, Woo PRO-1723 parity).** A follow-up series has
to stop when the shopper buys, and a Smaily-only "has ordered since" rule
cannot see a repeat buyer's history the way the store can — so the store
sends the signal. `Observer\OrderPlaced` reads the tracker row BEFORE
`markCompleted()` overwrites it (`StateManager::rowForQuote()`, status
`mailed`) and, when the feature is on for that website,
`ContactSync\SyncDispatcher::dispatchCartPurchase()` does two things:
`EventQueue::cancelPendingAutomation()` withdraws a reminder still sitting
`pending` for that address (terminally, the shape the flusher's own skip
records — `sent`, no `sent_payload`, `EventQueue::CANCELLED_RESPONSE` in
place of an API reply, so the Log keeps the withdrawal), and one plain
`contact.sync` carries `Trigger::ABANDONED_CART_PURCHASED_FIELD`
(`abandoned_cart_purchased_at`) with the order-placed moment. The field is
the merchant's workflow exit condition compared against
`abandoned_cart_automation_at`, so it carries the markers' own
`Trigger::MARKER_STAMP_FORMAT` — the two must sort against each other, and
why that format is what it is is stated there. The row carries the address
and that one field only: the reminder's cart and product fields are never
rewritten. The status IS the scope guard — a quote the extension
never tracked as abandoned (no row, a checkout-optin-only `open` row, or an
erased tombstone) sends nothing, so an ordinary purchase never creates a
contact.

Smaily creates a contact sent without a status as subscribed, so the marker
never creates one. It is queued only when `EventQueue::hasDeliveredAutomation()`
finds an abandoned-cart reminder to that address that went out (sent, with a
`sent_payload`, not withdrawn), and `Queue\Handler\ContactSyncHandler` reads
the contact (`GET contact.php`, in the cron, never at checkout) before it
posts the marker. For an address Smaily does not have (code 206) the handler
answers `Queue\Skipped` and `EventQueue::markSkipped()` closes the row —
`sent`, no `sent_payload`, the reason in `last_error` for the Log; a read
that fails leaves the row on the retry ladder with nothing posted.

### Attribution (FPC-safe by construction)

Landing capture is client-side (`view/frontend/web/js/attribution.js` —
URL params → first-party cookies), because server-side capture never runs
on FPC-cached pages. At order save (`Observer/Engine/OrderSaveAfter`, where
`entity_id` exists) cookies are stamped into `smaily_order_attribution`;
`OrderPayloadBuilder` forwards them on the order wire
(`smaily_rec_id` / `smaily_visitor_token` / `smaily_rec_ctx` /
`session_id`). The order is the only path these reach the engine on: since
contract v1.7.0 the engine ignores the rec id/context echo on browse
events, so the tracker no longer sends it. The rec id is shape-checked at
both ends (`Engine\RecId`, contract §5 — a malformed `smaily_rec_id`
rejects the whole order): the capture scripts write the cookie only for a
well-formed UUID, from `smaily_rec` or the guarded `utm_content` fallback,
and `OrderPayloadBuilder` omits a malformed stored value and sends the
order without it. The context cookie follows the contract's context cookie
rule: only a landing that writes the rec id cookie touches it, setting it
to the link's `smaily_ctx` or deleting it when the link has none, so the
two cookies always describe the same click
(`Test/Js/attribution-landing.html`). The visitor token, context and
session id are shape-checked by `Engine\AttributionShape` (WooCommerce's
PRO-1942 definitions, the visitor token capped at 64 characters in total;
since contract 1.11.0 a store-created `vs_` + 22 alphanumerics token is
accepted too, PRO-3912): the capture scripts write the visitor token and context cookies only in shape,
`AttributionManager::readCookies()` treats every off-shape cookie, the rec
id included, as absent (so a value can neither fail the side-table insert under a strict SQL mode
nor be stored cut short to the 64-character columns), and
`OrderPayloadBuilder` omits an off-shape stored value. Each signal is
dropped on its own.

An order an admin places with "Login as Customer" is stamped with nothing
(PRO-3925): that browser's cookies are the admin's own. By order time the
admin is on the customer session, so `AttributionManager::saveForOrder()`
asks `LoginAsCustomerApi`'s `GetLoggedAsCustomerAdminIdInterface` — the
check Magento_LoginAsCustomerSales marks such an order with — resolved by
name through the object manager only while Magento_LoginAsCustomer is
enabled (`Module\Manager::isEnabled()`), because the module is optional
(no composer or module.xml dependency). Nothing is stored, so the order
goes to the engine without the four signals; a shopper's own order is
stamped as before. An order created in the admin's own order screen
(`AdminOrder\Create`) is stamped with nothing too (PRO-3930): where the
admin shares the storefront's host, the store's cookies (path `/`) reach
admin requests, so they would be the admin's own browsing. The check is
the request's area — any order saved in an `adminhtml` request is an
admin's (`App\State::getAreaCode()`; no area set reads as not admin) — a
property of the request that needs no admin-only session object.
`saveForOrder()` asks one rule, `Model\OrderPlacer::isAdmin()`, for both
cases; `Model\OrderOrigin` asks the same rule (PRO-3949).

### Browse tracking

`view/frontend/web/js/tracker.js` (RequireJS; core is framework-free
vanilla for a future Hyvä path) reads page context from
`window.smailyPageContext` (set by FPC-cached per-page templates), batches
events for 5 s, and posts to `smaily/relay`. The relay
(`Controller/Relay/Index`) is CSRF-exempt (anonymous beacon), strictly
sanitized (`Engine\BrowseEventValidator` — UUID v4 event ids, event-type
enum, **no client-asserted `customer_email` or `external_id`** — identity
comes only from the engine-issued visitor token and the login identity
merge — and no `smaily_rec_id` / `smaily_ctx` hints the engine has ignored
since v1.7.0), rate-limited per connection address (Magento's
`RemoteAddress`: a forwarding header counts only where the store's own
configuration names it, with its trusted proxies), stamps
`source: plugin_magento` server-side, and forwards so the API key never
reaches the browser. Consent (marketing) is resolved as in the WooCommerce
plugin (F3-50, one standard signal, no per-vendor code): the store's own
`window.smailyConnect.consentOverride()` when defined, else
`user_allowed_save_cookie` under Magento cookie restriction mode
(`cookieRestriction` in the tracker config, from `ViewModel\EngineState`),
else no consent. The cookie is a JSON map of the website ids the shopper
accepted on (`{"1":1}`) and one cookie domain can serve several websites,
so only the current website's entry is consent (`websiteId` in the tracker
config), as Magento's cookie helper (`isUserNotAllowSaveCookie()`) and
Hyvä's cookie notice read it; a value that does not parse is no consent,
as in the cookie helper. Without consent the tracker sends nothing and writes no
`smaily_anon_sid` (the attribution scripts only expose `ensureSession()`;
the tracker calls it on consent). Consent that arrives later on the page —
Magento's `user:allowed:save:cookie` (jQuery, Luma), Hyvä's
`user-allowed-save-cookie` (window) or the documented
`smaily:consent-changed` (document) — starts it; each flush asks again and
drops the queue when consent is gone. Campaign-click capture stays
ungated. Only restriction mode is visible server-side
(`Model\Engine\ConsentSource`: on in every store view): without it,
`Cron\HealthCheck` posts a minor admin notice once (again after the
condition clears and returns), and the browse toggle's note in
`panel/intelligence.phtml` recommends the two consent sources. A store
view with a Storefront URL saved (`Config::hasStorefrontUrl()`, the
definition of a separate storefront everywhere in the admin) sells on a
separate storefront whose own consent banner decides, so the notice skips
it (`ConsentSource::isMissing()`), the note says so for the selected
website, and the Dashboard's browse-tracking card says the storefront must
send the events itself (`DashboardData::hasSeparateStorefront()`,
PRO-3918). Both notes are drawn, one hidden, and a successful Connection
save switches them by the Storefront URL it posted, as it does the
separate-storefront hint (`panels.saveStep('connect')`, PRO-3924).

### Storefront recommendations

A shopper's own recommendations (contract §15) on the store's pages,
behind the CMS widget **Smaily recommendations** (`etc/widget.xml`,
`Block\Widget\Recommendations`, no parameters); the WooCommerce plugin's
`StorefrontRecommendations` + `RecommendationsEndpoint` (PRO-3835/3857)
in Magento's shape (PRO-3790). Nothing is placed automatically.

- **FPC-safe by construction.** The widget renders an empty container
  (`[data-smaily-connect-recs]`), the same for every visitor, and nothing
  while `Settings::isSendingAllowed()` is false. `default.xml` adds
  `engine/recommendations.phtml` (same gate, `ViewModel\EngineState`):
  an `x-magento-init` keyed on the container, so RequireJS loads
  `js/recommendations.js` only on a page that has one. After `load`, and
  only with marketing consent (the tracker's rule, copied: override, else
  the cookie notice accepted on this website, else none; later consent via
  `user:allowed:save:cookie` / `smaily:consent-changed`), it asks the store
  route once per page and puts the HTML into every container. Hyvä:
  `compat/hyva` swaps the bootstrap for a JSON config + deferred
  `smaily-recommendations.js` (loaded on every page, inert without a
  container) and the cards template for Hyvä product cards
  (`hyva_smaily_recommendations_index.xml`).
- **The route** `Controller\Recommendations\Index` (GET
  `smaily/recommendations`) — guards in the Woo order: not
  `isSendingAllowed()` → bare 404; the per-address limit
  (`FixedWindowCounter`, 120 a minute, as the relay counts); a
  `Sec-Fetch-Site` of `cross-site` or `same-site` → empty 200. Then a
  layout result (`smaily_recommendations_index`: the `smaily.recommendations`
  block, `cacheable="false"`, so the answer never enters the FPC and the
  customer session is not depersonalized; plus the
  `product.price.render.default` block Magento_Catalog declares only in its
  default handle), or an empty 200 when there is nothing to show — no
  heading. Every answer carries `Cache-Control: no-store, private`.
- **Who is asked about** (`Model\Engine\StorefrontRecommendations`, server
  state only; nothing in the request names the shopper): a logged-in
  customer by the customer id (`customer_external_id`), only while
  `ProfilingConsent::isAllowed()` holds at the current store view — an
  objector is asked about by nothing, the token included; else a guest by
  the shape-checked visitor-token cookie (`AttributionManager::readCookies()`,
  `smaily_visitor_token`), unless Magento's cookie helper says the cookie
  notice holds consent back (`isUserNotAllowSaveCookie()`: restriction mode
  on and not accepted on this website — the half of the script's rule the
  server can see); else nobody. One identifier per request.
- **Engine call and caching.** `Client::customerRecommendations()` /
  `visitorRecommendations()`: map key `recommendations_customer`, falling
  back to the contract path for a connection set up before v1.9.0; one
  attempt, 10 s timeout. The answer's usable slots (well-formed rec id, an
  `external_id` or `sku`, at most 4) are cached in the application cache
  for an hour under `smaily_recs_` + sha256(tenant | identifier type |
  identifier), an empty answer included (§15: do not retry). Any failure
  is cached empty for 10 minutes; a timeout, network failure or 5xx also
  sets `smaily_recs_paused` for 2 minutes, during which every cache miss
  answers empty without a call (Woo PRO-3857). The engine connection is
  the installation's one tenant (`Engine\Settings`, default scope), as for
  every other engine call.
- **Cards** (`Model\Engine\RecommendedProducts`): one product collection
  at the current store view — enabled, visible in the catalog, in the
  store's website, with the listing attributes, prices and URL rewrites —
  matching each slot by `external_id` (the product id the catalog sync
  sends), else by `sku` (`mag-<id>` names the id). A product the collection
  does not return or that is not salable is dropped, nothing takes its
  place. The store's own name, image and price render; each link is the
  product URL + `smaily_rec=<rec_id>&smaily_ctx=storefront` (parameter
  names from the engine config, as the landing capture reads them), never
  `utm_*` or `smaily_vt`.

### Profiling consent

Opt-out model, default on: a shopper is profiled unless they said no.
`Model\Privacy\ProfilingConsent` owns the answer. Leaving marketing also
stops profiling (Erkki 2026-10-02, as in the WooCommerce plugin): a contact
Smaily reads back with `is_unsubscribed = 1` is not profiled, and
`Observer\Engine\SubscriberUnsubscribed` turns a newsletter unsubscribe into
an opt-out (`optOutOnUnsubscribe()`: the store's record at that moment,
marked as made by unsubscribing, plus an engine row, nothing written to the
contact's profiling field; a profiling opt-out the store already holds is
left as it is). Subscribing again turns profiling back on (PRO-3594, Erkki
2026-10-02): `Observer\Engine\SubscriberResubscribed` calls
`optInOnResubscribe()`, which lifts only a record made by unsubscribing —
forget, an engine opt-in row, cache `1` so a read before Smaily hears the
subscription cannot record the unsubscribe again. In `ProfilingOptOuts`
such a record is `{"at": moment, "by": "unsubscribe"}`; a plain moment
(every entry written before PRO-3594) is a profiling opt-out of its own. A
mirror read back from Smaily keeps the origin too (`is_unsubscribed = 1`
without `smaily_rec_profiling = 0` is by unsubscribing), and a record made
by unsubscribing is never carried to the contact's profiling field. Both
observers are deliberately outside the `ReconcileGuard`, so an unsubscribe
or a subscription Smaily's consent mirror writes counts too.

- **The page** (My Account > Personalization: `Controller\Privacy\Index`
  and `Save`, the nav link `Block\Account\PersonalizationLink`) exists
  only while `Engine\Settings::isSendingAllowed()` — connected and not
  refused, Woo PRO-2513/PRO-3189 parity (PRO-3579). Elsewhere both actions
  forward to `noroute` and the link renders nothing; stored choices are
  untouched. It shows `knownPreference()`, not `isAllowed()` (PRO-3591,
  Woo PRO-3189): the fail-open answer is cached as a guess (`?`, read by
  the gate as "profile"), and `knownPreference()` asks Smaily again over a
  cached guess and returns null when the answer is still a guess. Null
  renders a notice and an opt-out button (a form without the tick box,
  which `Save` reads as an opt-out).
- **A choice** (My Account > Personalization, `setAllowed()`) goes three
  ways: to the Smaily contact (`smaily_rec_profiling` 0/1 +
  `smaily_rec_profiling_ts`, Z-suffixed), into the store's own record
  (`Model\Privacy\ProfilingOptOuts`) and onto the marketing event queue as
  an `engine.profiling_consent` row for the engine's §10 opt-out endpoint.
  A failed Smaily write does not stop the other two. The row's `entity_id`
  is the keyed hash of the address the record is written under
  (`Model\Privacy\AddressKey`: HMAC-SHA256 with the installation crypt key
  of the trimmed, lower-case address, the crypt key parsed once per
  instance; 64 hex characters), never the address (PRO-3765): the address is in the
  payload the engine needs and nowhere else on the row, and the hash always
  fits the 64-character column, where a longer address was cut. A row
  queued before carries the plain address; it is not rewritten (no data
  patch) — a pending one drains within the retry window, a terminal one
  goes with the janitor's retention — and every reader below matches both
  forms, which also covers a **Send again** of such a row (`Log\Resend`
  copies the entity). The Log grid (`Ui\Component\LogEntityColumn`), the
  Details panel and the Dashboard's recent activity show the hash by its
  first 12 characters (`Log\EntityLabel`); the grid's Entity filter finds
  the row by them, as they are part of the stored value.
- **The store's record** is one flag row, `smaily_connect_profiling_optouts`:
  a map of a keyed hash of the address (HMAC-SHA256 with the installation
  crypt key) to the opt-out's moment (Unix time), held under a named lock
  for every change. Only opt-outs are kept — an opt-in removes the entry —
  so it grows with the number of people who said no, never with the
  contact base, and it holds no address. No table, no column. A read also
  finds an entry under an earlier crypt key (after a key rotation) or under
  the plain `sha1(address)` the record used before; any change to that
  address's entry rewrites it under the newest key.
- **A read** (`isAllowed()`, cached a day) resolves the Smaily contact
  against the record, and the newest choice wins (Woo PRO-3191/3192/3434
  parity). Only an opt-in on the contact (`smaily_rec_profiling = 1`) whose
  `smaily_rec_profiling_ts` is later than the store's opt-out lifts it — and
  then the record is cleared and the opt-in is queued for the engine. A `1`
  with no timestamp, an older one, or one not in exactly the
  `Y-m-d\TH:i:s\Z` form this module writes (strict parse with round-trip),
  or more than 5 minutes in the future, counts as older: the opt-out holds
  and is written to the contact again (its new moment recorded), but never
  to a contact Smaily does not have — the upsert would create one. When
  Smaily cannot be read, the record decides; a shopper without an entry is
  profiled (fail open, as before).
- **An opt-out made in Smaily** — `smaily_rec_profiling = 0` or
  `is_unsubscribed = 1` on a contact the record has no entry for — is
  recorded as a mirror (moment 0, so any dated opt-in is newer) and queued
  for the engine on the read that finds it (a My Account visit, or the
  identity merge at login). The rule throughout: every change to the record
  queues one engine row, and only the replay below queues another.
- **Delivery** is `Queue\Handler\ProfilingConsentHandler`, on the normal
  retry ladder, behind the same sending gate as the identity merge. A row is
  sent only while it still matches the record: a retry or a Send again of a
  choice the shopper has since replaced is closed without a call as
  `Queue\Skipped` (read as Skipped in the Log), so an older answer never
  undoes a newer one at the engine. A §10 404 (the engine
  holds nothing for that address) also closes the row — there is nothing to
  exclude yet; the replay below sends the opt-out again once there is. Any other engine 4xx is handed on whole, so `Queue\Failure`
  fails the row on the first attempt as `permanent_http_<code>` (PRO-3752).
  While Campaign Intelligence refuses the account the rows wait exactly as
  the identity merges below do (a `PausableEventHandlerInterface`, not
  claimed; a row that meets the refusal mid-run answered `Queue\Pending`),
  and the match against the record on delivery keeps the newest choice
  winning however many waited.
- **An opt-out the engine did not keep** (PRO-3760, Erkki 2026-10-05). The
  engine keeps a §10 opt-out only for a shopper it holds, and customer
  and order data carry no consent (§4, §5), so an opt-out made before the
  engine knew the shopper (a newsletter-only guest: the 404 above) would
  not hold once a later order (§5 creates the customer), a customer save or
  the customer/order history import creates them. So `Cron\FlushIngestQueue`,
  where the engine's D6 answer marks each row delivered, hands the items the
  engine confirmed (processed or deduplicated, not a per-item error) to
  `Model\Privacy\OptOutReplay::afterConfirmed()`, once per batch; that
  knows which field names the shopper in a customer and in an order item
  and passes their addresses to `ProfilingConsent::resendOptOuts()`. That
  reads the record once for the whole batch (`ProfilingOptOuts::moments()`,
  which hands back each address's key with its moment, so each address is
  hashed once) and queues an opt-out row with the stored
  moment (a mirror's 0: the moment of sending) through the same
  `queueForEngine()`, so the newest-choice match on delivery and the wait
  for a refused account apply to it. A shopper with no opt-out gets
  nothing. One already waiting (`EventQueue::waitingProfilingOptOuts()`: an
  opt-out row pending, in its backoff or being sent, found by the keyed hash
  or the plain address of a row queued before) gets no second row, so
  many orders make one; once that row is delivered, the next confirmation
  queues one again, which the engine takes as a no-op. The row is queued
  only after the engine confirmed the shopper, so it finds them.
- **Identity merge.** `Observer\Engine\CustomerLogin` (`customer_login`)
  queues the row when the request carries the session or visitor cookie
  (`AttributionManager::readCookies()`; no Magento session is needed).
  Magento's own login passes the customer model, a customer token login
  (`Integration\Model\CustomerTokenService`: GraphQL
  `generateCustomerToken`, REST `integration/customer/token`) the customer
  data object; both are taken, so a separate storefront that forwards the
  cookies on its login request gets the merge too (PRO-3917).
  An admin's "Login as Customer" queues nothing (PRO-3920): its storefront
  page (`loginascustomer/login/index`) logs the admin's browser in with
  `Session::loginById()`, so `customer_login` carries the admin's own
  cookies. The observer recognizes it by the request's route
  (`RequestInterface::getModuleName()` = `loginascustomer`), not through
  `LoginAsCustomerApi`: `AuthenticateCustomerBySecret` records the admin on
  the session (`SetLoggedAsCustomerAdminId`) only after the event, and the
  Magento_LoginAsCustomer* modules are optional, so the module takes no
  dependency on them. Login as Customer's GraphQL path
  (`generateCustomerTokenAsAdmin`) dispatches no `customer_login`.
  `Queue\Handler\IdentityMergeHandler` asks
  `isAllowed()` (at the customer's store view, whose Smaily account holds
  the contact) before each merge; an opted-out shopper's row is closed
  without a call as `Queue\Skipped`, so their browsing stays anonymous. Asked on the cron, not
  in the login observer, so a login never waits on a Smaily read. While
  Campaign Intelligence refuses the account (PRO-2451) its rows wait, as
  the ingest rows do (PRO-2466): the handler is an
  `Api\Queue\PausableEventHandlerInterface`, so `FlushEventQueue` does
  not claim its rows (`HandlerPool::pausedEventTypes()` →
  `EventQueue::claimBatch(…, $exceptTypes)`), and a row that meets the
  refusal mid-run is answered `Queue\Pending` and given back as it was
  (`EventQueue::release()`: pending, unclaimed, no attempt spent). Not
  claiming them, rather than claiming and releasing, keeps a long refusal's
  backlog from filling every batch ahead of the contact syncs. The Log's
  Details reads the same two facts (`Model\Log\AccountWait::waits()`,
  PRO-3753): a pending ingest row while the account is
  refused, or a pending row of a type `pausedEventTypes()` names, says it
  waits for the account to be active again, not for the next flush or
  retry.

## Admin UI

Four pages under **Marketing > Smaily Connect** (menu.xml): Dashboard,
Initial setup, Settings, Log. Design rules:

- **One source of truth.** The wizard and the Settings page write through
  the same `Model\Adminhtml\WizardStepSaver` into the same system-config
  paths declared in `etc/adminhtml/system.xml` — that section's
  `showInDefault`/`showInWebsite`/`showInStore` are all `"0"` (PRO-1461), so
  it never renders under Stores > Configuration for any admin; the
  declarations stay only for encrypted backend models and CLI
  `config:set`/`config:show`. There is no parallel settings store, and no
  second editing surface. The two secrets — the Smaily API password and the
  Campaign Intelligence API key — are declared sensitive in `etc/di.xml`
  (`Magento\Config\Model\Config\TypePool`), so `app:config:dump` never
  writes them to `app/etc/config.php`.
- **Multi-website (docs/internal/RFC_MULTI_WEBSITE.md).** `Model\Adminhtml\WebsiteContext`
  is the single seam every admin save/prefill path reads to know its target
  website: it resolves a `website` request param (validated against real
  websites) falling back to the installation's default website. On a
  2+-website install, the Settings page renders an explicit website
  selector next to the tab strip and the wizard renders a website-chooser
  step before Connect (own chrome, not Magento's native store-switcher);
  both work by reloading with `?website=<id>` — every AJAX call and the
  page's own prefill (`ViewModel\Adminhtml\WizardData::getBootJson()`)
  already read that same context, so no further plumbing is needed per
  field. Single-website installs never see either control.
  `Model\Adminhtml\SetupGuard`'s completed flag is website-scoped the same
  way, so each website's own onboarding is tracked independently (a new
  website falls back to the installation's existing completed flag via the
  normal website→default scope chain, same as any other field). Campaign
  Intelligence stays installation-wide regardless of the selected website
  (Phase 4 of the RFC, gated on engine-side confirmation).
- **Shared step partials.** The wizard's step content and the Settings
  tabs are the SAME templates (`view/adminhtml/templates/panel/*.phtml`),
  composed by two thin page templates (`wizard/index.phtml`,
  `settings/index.phtml`). Shared behavior (AJAX saves, test connection,
  live workflow dropdowns, backfill progress polling, field reactivity)
  lives once in `panel/panels-js.phtml`, which defines
  `window.smailyPanelsInit` — each page script passes jQuery in and drives
  the stepper (wizard) or the deep-linkable `?tab=` tabs (settings).
  Strings in these inline scripts are translated server-side with `__()`
  (js-translation.json never collects `$t()` from phtml).
- **Multilingual UI is part of the shared panels.** When more than one
  store-view language is detected (`Multilingual\AccountResolver`), the
  Connection panel renders the routing-mode choice cards; mode `a` swaps
  the single credential block for per-language blocks (each with its own
  Test connection — saved accounts re-test via `store_id` against the
  saved store-view credentials) plus a default-fallback picker whose
  account's credentials double as the website's account; the fallback
  language is saved per website as well (`Config::getFallbackLanguage()`
  reads the website value, else the default-scope value saved before
  PRO-3719). Modes `a`/`b` reveal
  the per-language workflow mapping editor on the Automations panel
  (workflow dropdowns loaded live per account). All sections show/hide
  live on mode change with no save round-trip; leaving mode `a` removes
  the per-store-view credential overrides on save (confirmed in the UI
  first). Single-language installs are locked to `single` and see none of
  this.
- **Dashboard is operational truth.** Every number on
  `dashboard/index.phtml` is a real local queue query
  (`Model\Adminhtml\DashboardStats`); the health verdict reuses the
  HealthCheck cron's failed-rows query (`Model\Health\QueueHealth`) and
  engine-down flag, so the dashboard can never disagree with the admin
  notifications. Unknowable numbers are omitted, not estimated.
- **"Connected" means Smaily accepted the credentials (PRO-3560).**
  `Config::isConnected()` only says that subdomain, username and password
  are filled in; it stays the gate for queueing and sending.
  `Model\Client\VerifiedCredentials` remembers which credentials Smaily
  accepted, as keyed hashes (`EncryptorInterface::hash`, the last 20) in
  one flag row, `smaily_connect_verified_credentials`. `SmailyClient`
  records the answers at its one chokepoint: a passed
  `validateCredentials()` accepts (Test connection, and the connection save
  — `WizardStepSaver::saveConnect()` checks what it just saved and never
  fails the save on the answer); any 401/403 refuses, wherever it came
  from, the queue's deliveries included. Changed credentials hash
  differently, so they are not connected until checked. The Dashboard's
  Smaily card and verdict and the Connection status (`boot.verified` from
  `WizardData`) all ask `isWebsiteVerified()` for the account saved for
  the target website — the single account, or in mode A the default
  fallback account: the account the connection save checks — so they
  cannot disagree, and a reload shows what the save showed (PRO-3719);
  each mode-A language block asks `isVerified()` for its store view. No
  page load calls Smaily. A third
  answer (PRO-3579, Woo `RefusalReason`): an error body with Smaily code
  227 ("A paid package is required") is the package, not the
  credentials — Smaily gives it before it authenticates. `SmailyClient`
  throws `PlanBlockedException` (a `TransportException`, HTTP 403, so the
  queue still parks the row on the spot; not an `AuthenticationException`)
  and `VerifiedCredentials::planBlocked()` moves the fingerprint from the
  accepted list to a second flag row,
  `smaily_connect_plan_blocked_credentials`; a later accept or refusal
  clears it. Such a store is *Not connected* — every request is refused —
  and `isWebsitePlanBlocked()` (`boot.planBlocked`, the Dashboard's card and
  verdict sentence) names the package instead of the credentials. The
  verdict order is: setup incomplete > Smaily not connected > Campaign
  Intelligence account not active > failures > engine unreachable > no
  nightly product list > all good.
- **No nightly product list for three nights (PRO-3914).**
  `DashboardData::getUnsentProductList()` reads the manifest's
  `FLAG_UNSENT` count (`CatalogManifest::readUnsent()`): from
  `UNSENT_PRODUCT_LIST_NIGHTS` (3) nights in a
  row, the verdict is degraded (its sentence when nothing outranks it) and
  a warning banner above it — shown whatever outranks it, as the failures
  banner is — says how many nights and why the last one did not go out,
  in the admin's language. Nothing while Campaign Intelligence is not
  connected or refuses the account, and nothing for a connection whose
  `issued_at` (`Settings::getIssuedAt()`, the setup exchange) is less than
  three days old.
- **Unified log.** One grid (`smaily_log_grid`) over BOTH queues:
  `Model\ResourceModel\Log\Collection` builds a `UNION ALL` of the two
  queue tables as a derived table, keyed by the synthetic
  `log_id` (`smaily-<id>` / `intelligence-<id>`) that
  `Model\Log\SelectionRetry` splits to route the mass retry back to the
  right queue. Grid filters/sorting apply to the outer select.
- **Wizard-first gating.** `Model\Adminhtml\SetupGuard`: while
  `smaily_connect/internal/setup_completed` is unset for the current
  `WebsiteContext` target, Dashboard/Settings/Log redirect to the wizard.
  The guard also tracks
  `smaily_connect/internal/last_seen_version` (module version read from
  composer.json via `Model\ModuleVersion`) and posts a one-time admin
  notice after a MAJOR version jump instead of any hard redirect.

## Wire contracts

The authoritative engine contract is
[RECENGINE_API_CONTRACT.md](RECENGINE_API_CONTRACT.md) (v1.12.0, byte-synced
across the Smaily connect repositories). Load-bearing invariants
implemented here:

- Endpoint URLs always come from the stored endpoints map
  (`Engine\Settings`), never concatenated; `{email}` placeholders are
  substituted with `str_replace`.
- Retry: 1/2/4/8/16 s on 429 (honoring `retry_after_seconds` from the
  body, up to 60 s) and 5xx; other 4xx never retry; every call has a 10 s
  connect and 30 s total timeout (`Engine\Client`). The storefront browse
  relay makes one 3 s attempt and never retries or waits.
- Smaily marketing API: HTTP Basic; success envelope `{code:101}`, 203 =
  invalid data, 206 = email not found (`Model\Client\SmailyClient`).
- Engine automations config (§13): every row carries all eight keys;
  validation is all-or-nothing; `per_language` rows saved by other
  platforms survive a Magento save. The engine stores a row asking for
  real sends in test mode until a Smaily operator switches them on, so
  `Automations\Save` reads §12 after a successful PUT and answers each
  trigger's stored `enabled` / `test_mode`; the Automations tab redraws
  its cards from that, never from the request.

## Extension points

- **New queue event type:** implement `Api\Queue\EventHandlerInterface`,
  register in the `HandlerPool` via `di.xml`.
- **New backfill:** implement `Model\Backfill\ProcessorInterface`, register
  under `"{job_type}:{target}"` in `Cron\BackfillTick`'s pool.
- **Payload shape changes:** each payload has exactly one builder class
  (`ContactSync\SubscriberPayloadBuilder`, `AbandonedCart\PayloadBuilder`,
  `Engine\Payload\*`) — the single source of truth used by both live hooks
  and backfills.

## Testing

See [../TESTING.md](../TESTING.md): unit suite + static analysis in CI, a
Docker Magento 2.4.8 sandbox for end-to-end smoke, and a scripted 2.8.x
upgrade-migration verification.
