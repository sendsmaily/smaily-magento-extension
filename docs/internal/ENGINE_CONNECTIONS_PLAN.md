# Engine connections per store — build plan (CON-237, phase 2 of CON-279)

Internal working plan for the six-step build; the maintainer keeps it current between steps. Steps 1–3 are merged (#250, #251, #252); the "as built" notes below record what each step left for the next.


Owner decisions (Erkki, 2026-11-03):
- The engine store follows the store (business), not the Smaily account. "One store in several versions" = one engine store (today). "Separate stores" = each separate store connects its own engine store.
- Engine allows one engine store per Smaily account (`tenants.smaily_subdomain` unique), so websites of separate stores that share one Smaily account share one connection.
- (a) A website moved to another Smaily account leaves its customers/orders in the old engine store; its products leave via the nightly manifest. The guide says so.
- (b) Separate stores -> one store: §16 for each website connection, drop its pending rows, offer Run again on the primary. Hook: event `smaily_connect_store_layout_joined` (fired by `Model\StoreLayout::toOneStore()`, data `website_ids`, `website_id`) — already in master (#249).
- (c) Contract 1.25.0 §1 answer carries `smaily_subdomain` (trimmed, lower case, or null). Compare case-insensitively with the website's own account and refuse a mismatch before storing anything or starting an import; without the field (older engine), fall back to refusing a `tenant_id` another connection holds.
- Per-language Smaily accounts inside one store (INT-208) are OUT of scope: keep one engine store per Smaily account and never join several accounts' data into one connection.
- Layout runtime service: `Model\StoreLayout::isSeparateStores()` (#249).

## No DB schema change
- `smaily_ingest_queue.store_id` (nullable smallint) = the connection's canonical store view at every enqueue; router: store view -> website -> connection. NULL = primary (all rows queued before the upgrade; legacy catalog rows carry today's canonicalStoreId(), the primary's). `Log\Resend` already copies store_id.
- `smaily_event_queue.website_id` routes engine.identity_merge and engine.profiling_consent (website -> connection; 0 = primary).
- `smaily_backfill_job.website_id` = connection key for engine jobs (today Job::ENGINE_WEBSITE_ID = 0 = primary).
- Per-connection state in suffixed flag codes (`flag` table).
- Cost: a row whose store view was deleted cannot be routed (waits). Column comment says "i18n context" — note in ARCHITECTURE only; do not touch db_schema.xml.

## 1. Connection model
- Group key: normalized Smaily subdomain (Model/SubdomainNormalizer).
- One store: every website -> primary (today, incl. mode A and the multi-website pilot store).
- Separate stores: a website belongs to the connection whose recorded subdomain equals its own Smaily account's subdomain (website-scope account; in mode A its fallback account; resolved website -> default). No match = not connected.
- `Model/Engine/Connection` (value object): key (0 = primary/default scope, else anchor website id), settings (scope-bound Settings), websiteIds(), storeIds(), canonicalStoreId() (default store view of the installation's default website if served, else of the lowest-id served website), isPrimary(), servesAllWebsites().
- `Model/Engine/Connections` (registry, memoized per request): all(), primary(), forWebsite(), forStore(), forKey(), forRowStoreId(?int) (NULL -> primary). In one-store mode reads nothing extra and returns the primary for every website.
- Settings: primary = existing default-scope `smaily_connect/intelligence/*` rows (no migration). Other connections = same paths at website scope of the anchor website: API key (encrypted), tenant id/name, base URL, version, endpoints map, config (incl. config.history), issued_at, refused_at, browse_tracking, email_workflows.
- New paths: `smaily_connect/intelligence/smaily_subdomain` (account the connection was made for, written at connect), `smaily_connect/intelligence/website_connection = 1` (own-scope mark, website scope only).
- Exact-scope rule: website reads fall back to default, so a website connection is detected by the mark; a website-scope Settings always writes EVERY path ('' for none: recordRefusal/clearRefusal, email_workflows, browse_tracking), never deletes a single path; clear() deletes all rows of that scope.
- Settings refactor: private ?int $websiteId + withWebsite(int): self (clone, fresh memos); every getValue/save/delete passes the scope; the DI singleton stays the primary.
- Clients: a Client per connection via generated ClientFactory (['settings' => …]); lastExchange()/wasThrottled() per instance.
- Flags: base code + `_c<key>`; key 0 keeps the bare code: HistoryImport::FLAG, CatalogManifest::FLAG_UNSENT, HealthCheck engine-down/notified/tenant-inactive flags, StorefrontRecommendations::PAUSE_KEY (cache key).

## 2. Setup, start, uninstall, ping, health
- Connector::connect($input, int $websiteId): resolve target (website subdomain == primary's recorded subdomain, or no primary and one-store -> default scope; else websites/<id>); refuse when the website is already covered ("connected through website X"); refuse a tenant_id another connection holds; §1 `smaily_subdomain` check (decision c); storeExchange() on the bound Settings writing smaily_subdomain; ImportsOnConnect::start(Connection).
- store_config as today; plugin_info.site_url stays Client::siteUrl().
- §21 not built in Magento yet; when built: selected website's account, shop_url = website base URL.
- §16: Settings Disconnect acts on the selected website's connection; `smaily:engine:disconnect --website=<code>` (without: all); Setup\Uninstall::endEngineConnection() reads core_config_data for every (scope, scope_id) with connected = 1 and calls Disconnector for each, staying DI-free (CON-220).
- Website delete: observer sends §16 for a website with its own connection.
- HealthCheck loops Connections::all() with own client/flags; notices name the connection's websites; smaily:engine:ping and Api/EnginePing take a website; refusal per connection.

## 3. Flows
- Live catalog: product -> every connection that owns it (assigned to one of its websites; primary also gets products in no website). CatalogPayloadBuilder::build($product, Connection): websites() limited to the connection's; price/URL/currency at storeIdForProduct($product, $connection); row store_id = connection canonical store. Byte-dedupe per connection; IngestQueue::latestUndeliveredPayloads() gains a store-id filter. buildChanged() markers stay connection-less and build per owning connection.
- Product leaving a connection's websites: next §3c manifest tombstones it (no save-time detection).
- §3b removals: one row per connection for every connection (not_found = success).
- §3c manifest: SendCatalogManifest loops connections; CatalogProductLoader::loadForManifest()/loadPage() optional website filter (catalog_product_website subquery, plus "in no website" for primary; never addPriceData — PRO-2506); servesAllWebsites() -> no filter (byte-identical query). Skip reasons and 50,000 cap per connection.
- Customers §4: EngineCustomerScope takes a Connection (group a loops its websites; group b requires o.store_id IN connection stores); CustomerIngest::saved()/joined() per connection with that connection's horizon; group c only the current store's connection; CustomerPayloadBuilder::build($customer, Connection) sends `website` only when customer.website_id is in the connection.
- Orders §5: OrderIngest -> forStore(order.store_id); none serves -> nothing queued; store view gone -> primary only while single connection; row store_id = connection canonical store; refund collapse unchanged.
- Browse §6: Controller/Relay/Index uses the current website's connection (gate, browse toggle, client).
- Storefront config §15 + trackers: ViewModel/EngineState, AttributionManager::getClientConfig(), Controller/Attribution/Land, StorefrontRecommendations + Controller/Recommendations/Index + Block/Widget/Recommendations read Connections::forStore(current).
- Identity merge §7 + login token: CustomerLogin enqueues with website_id = login website and gates on that connection; StoreVisitorToken only when that connection is connected; IdentityMergeHandler/MergeCustomerSender use the row's connection (0 = primary). pausedEventTypes only when every connection is refused; otherwise a refused connection's rows are released per row.
- Profiling consent §10, privacy, GDPR: ProfilingConsent::queueForEngine() one row per connection (website_id = connection canonical website); OptOutReplay to the connection whose batch confirmed; smaily:gdpr export/erase to every connection, output labelled per connection.
- Email designs §22/§23 + pushed workflow: admin uses the selected website's connection (EmailDesigns, EmailDesignsData, Api/Designs, Api/DesignLink); EmailDesignWorkflows::remember() writes that connection's scope; PushedWorkflow reads the event website's connection.
- Engine automations §11–§13, §17–§20, branding §18: Automations/Save and AutomationsForm per selected website's connection.
- §25: HistoryImport, HistoryProgress, HistoryConfig take a Connection; EngineOrdersProcessor window queries filter store_id IN connection stores.

## 4. Queue, imports, pace, Log
- IngestQueue::claimBatch($domain, $limit, ?Connection): store_id IN (…) plus OR store_id IS NULL for primary; catalog_changed claim unfiltered; countPending() global.
- FlushIngestQueue: round-robin per connection (one batch per domain plus §3b); stopped per connection (429/refusal); haltedDomains keyed connection+domain; transport failure stops that connection for the run.
- IngestPace shared (one budget per run); IMPORT_BACKLOG_ROWS unchanged.
- JobManager hasCompleted/latest/isStalled/requestCancel gain ?int $websiteId (connection key); ImportStarter uses it; EngineImportGuard per connection; BackfillTick unchanged (processors read connection from job.website_id).
- Log: Details shows "Campaign Intelligence store: <tenant name> (<websites>)"; Log\AccountWait explains a wait for the row's connection.

## 5. Admin
- Settings > Intelligence follows #249's website selector: one store -> installation-wide connection as today; separate stores -> selected website's connection (tenant name, "covers websites A, B (same Smaily account)", Connect/Disconnect, Check again, browse toggle, email designs, engine automations, Catalog/Customers/Orders cards). Api/BackfillState, Api/EngineExchange take website -> connection. A sibling website shows the same connection with a note.
- Dashboard card lists connections; degraded when any is refused/down, naming it. CustomersImportNotice and the unsent-manifest banner per connection. smaily:backfill:start|status --website.

## 6. Migration
- No re-setup: default-scope rows = primary; engine jobs website_id 0, unsuffixed flags, NULL/canonical-store rows already mean primary.
- Data patch Setup/Patch/Data/RecordEngineConnectionAccount.php: writes smaily_subdomain for an existing primary = default-scope subdomain, else the default website's subdomain. Config row only.
- Pilot store (multi-website, one Smaily account) = one store: byte-identical payloads; a regression test pins it.

## Risks
1. Wrong setup link in a website view -> mitigated by §1 smaily_subdomain (1.25.0) + tenant_id check; show tenant name and websites after connecting.
2. Moving a website to another account (decision a).
3. Separate -> one with website connections (decision b, event hook).
4. Website delete -> observer §16.
5. Fan-out cost: product on N stores built N times; catalog import once per connection.
6. Store view deleted with rows pending -> rows wait unrouted.
7. Cross-store rec_id/visitor tokens on one cookie domain: engine tolerance unverified.
8. DI-free uninstall must cover every scope.

## Tests
Unit: ConnectionsTest (one store; separate stores; two websites on one account; mode A; CON-192 shape; no primary); SettingsTest (website scope never inherits default refused_at/email_workflows; clear() per scope); ConnectorTest (target scope, already covered, same tenant, smaily_subdomain mismatch/absent); per-connection CatalogPayloadBuilder; CustomerPayloadBuilder website rule; FlushIngestQueue round-robin + halt per connection; UninstallTest all scopes, DI-free.
Integration: IngestQueue claim filter incl. NULL -> primary; EngineCustomerScope per connection; CatalogProductLoader website filter; two-tenant mock-engine run (ImportPaceTest pattern) asserting every row reaches only its key; manifest per connection; single-connection golden test (payloads and queries unchanged).

## PR steps (1–4 ship dark: nothing can create a second connection before step 5)
1. Connection model: Engine/Connection, Engine/Connections (reads StoreLayout), scope-aware Settings, ClientFactory wiring, data patch RecordEngineConnectionAccount.
2. Queue routing: store_id = connection canonical store at every enqueue; claimBatch/latestUndeliveredPayloads/hasRow scoped; FlushIngestQueue loop; OptOutReplay; Log Details.
3. Per-connection payloads and imports: catalog builder/ingest/loader/manifest; customer scope/ingest/builder; order ingest; processors, HistoryImport, HistoryProgress, HistoryConfig; JobManager, ImportStarter, ImportsOnConnect, EngineImportGuard.
4. Storefront and event-queue flows: EngineState, Relay, Land, recommendations, AttributionManager; CustomerLogin, StoreVisitorToken, merge handler/sender; profiling consent + handler, Privacy, PersonalizationLink; PushedWorkflow, EmailDesignWorkflows, GdprCommand.
5. Connect, disconnect, health: Connector (+ §1 smaily_subdomain), EngineExchange, Disconnector/Uninstall, engine commands, HealthCheck, CustomersImportNotice, Dashboard, website-delete observer, the layout-joined event listener (decision b).
6. Admin UI on #249's selector: panels, cards, engine automations, email designs; i18n en_US/et_EE; guide EN/ET (incl. decision a); ARCHITECTURE; changelog fragment.

## Step 1 as built (#250, c590e4f)
- Connection: (int $key, Settings $settings, array $websiteIds, array $storeIds, ?int $canonicalStoreId, bool $servesAllWebsites); PRIMARY_KEY = 0; key(), settings(), websiteIds() (asc), storeIds(), canonicalStoreId(): ?int (null when it serves no website -> rows NULL = primary), isPrimary(), servesAllWebsites().
- Connections (DI shared): all() (primary first), primary(), forWebsite(int): ?Connection, forStore(int): ?Connection, forKey(int), forRowStoreId(?int) (NULL -> primary), client(Connection): Client (DI client for primary, memoized ClientFactory client otherwise), accountOf(int $websiteId), installationAccount(), reset(). Website 0 / store 0 -> primary (legacy event rows carry website 0). Under one store forStore() always returns the primary; under separate stores a deleted store view -> null.
- Settings: withWebsite(int): self (clone, fresh memos), getWebsiteId(): ?int, isWebsiteConnection(), getSmailySubdomain(), recordSmailySubdomain(), storeExchange(array $response, string $accountSubdomain = ''); website scope writes every path ('' for unset), browse_tracking '' only on first connect, clear() deletes WEBSITE_PATHS of that scope; primary clear() also deletes smaily_subdomain.
- ClientFactory (hand-written): create(['settings' => Settings]): Client.
- Connector takes Connections; passes installationAccount() to storeExchange(); calls reset().
- Data patch RecordEngineConnectionAccount (after MigrateLegacyConfig).
- Setup\Uninstall unchanged (smaily_connect/% delete covers every scope); endEngineConnection() still §16 for default scope only -> step 5.
- Notes for later steps: CON-192 stores under separate stores: only websites on the default website's account stay on the primary once routing lands; others need their own connection -> step 5/6 needs a changelog fragment + guide note + an admin notice. StoreLayout::isSeparateStores() runs one table query per request on 2+ websites (storefront cost, step 4). EmailDesignWorkflows (step 4) and WizardStepSaver's browse-tracking save (step 6) write the default scope directly; at website scope they must write '' and never delete. ScopeConfig may serve stale values in the same request after storeExchange().

## Step 2 as built (#251, e838154)
- IngestQueue: claimBatch($domain, $limit, ?Connection $engineConnection = null); latestUndeliveredPayloads($domain, $entityIds, ?Connection = null); hasRow($domain, $entityId, ?Connection = null); enqueueChangedPayloads($domain, $payloads, Connection $connection) (stores connection canonical store, dedupes within the connection); newEvent($domain, $payload, ?int $storeId = null); enqueue/enqueueReturningId/enqueueMany keep ?int $storeId (callers pass canonicalStoreId()).
- Claim filter: a connection with servesAllWebsites() has NO filter (one store runs identical SQL); a non-primary serving no website claims nothing; a primary serving no website claims only NULL rows; otherwise store_id IN (its stores) (+ NULL for primary).
- Connection::eventWebsiteId(): 0 for primary, else the lowest served website — the website_id of its rows in smaily_event_queue. Primary MUST stay 0 (consent waiting check and existing rows).
- FlushIngestQueue(Connections, IngestQueue, CatalogIngest, OptOutReplay, Json, Logger, IngestPace, JobManager): round-robin per connection, own client, 429/transport/refusal stops only that connection, haltedDomains per connection+domain.
- OptOutReplay::afterConfirmed($domain, $items, Connection); ProfilingConsent::resendOptOuts(array $emails, int $websiteId = 0); EventQueue::waitingProfilingOptOuts(array $entityIds, ?int $websiteId = null).
- Log\EngineStore (isEngineRow, connectionOf, label — null with one connection); Details shows "Campaign Intelligence store" only with 2+ connections; Log\AccountWait(HandlerPool, Log\EngineStore).
- Connections added as last ctor arg to CatalogIngest, OrderIngest, CustomerIngest, EngineCustomersProcessor, EngineOrdersProcessor, IdentityMergeHandler — each enqueue/hasRow uses Connections::primary() with a "CON-237 … later step" comment. Step 3 replaces: CatalogIngest (enqueue, enqueueBuilt, enqueueRemovals), OrderIngest, CustomerIngest, EngineCustomersProcessor, EngineOrdersProcessor. Step 4: IdentityMergeHandler::isQueuedCustomer() row's own connection; ProfilingConsent::queueForEngine() one row per connection with eventWebsiteId().
- MergeCustomerSender and CatalogManifest unchanged (record-only rows; steps 3/4 own them).
- countPending() global -> IMPORT_BACKLOG_ROWS shared across connections.
- Unroutable rows: Details says "None: …", AccountWait returns false -> retry line "Waiting for the next queue flush"; step 5/6 should give them a sentence.
- Test helper trait Test/Unit/Support/EngineConnectionsStub (oneEngineConnection(), engineConnection(), engineConnections()). Integration Test/Integration/Engine/QueueRoutingTest (two-engine mock, golden single-connection run).
- Connections now loads on every enqueue incl. storefront order/customer save (getStores, getWebsites, one isSeparateStores() query on 2+ websites).
- New et_EE strings: "Campaign Intelligence'i pood"; "Puudub: selle rea veebisaidi jaoks pole ühtegi Campaign Intelligence'i poodi ühendatud."

## Step 3 as built (#252, 97b008d)
- Connection: servesWebsite(int), ownsProductOn(array), flagCode(string) (bare for key 0, `_c<key>` otherwise). Connections: connected(), anyConnected(). Stub trait: engineJobConnection().
- CatalogPayloadBuilder::build(Product, Connection), buildTombstone(Product, Connection) (required). CustomerPayloadBuilder::build($customer, ?Connection = null) (null = old behaviour; MergeCustomerSender still passes none -> step 4 must pass the row's connection).
- CatalogProductLoader load/loadPage/loadForManifest take Connection; count(Connection). CatalogIngest: no Settings; enqueueProduct($p, ?$jobId, ?Connection $only); tombstones keyed product id -> connection key -> row. CatalogManifest::send(Connection) (cron loops all()), readUnsent(?Connection = null); ctor takes Connections. A connection serving no website sends no manifest (safety) and clears its unsent flag. Manifest Log row now carries the canonical store_id (1 under one store).
- IngestQueue::countPending($domain, ?Connection = null). EngineCustomerScope count/page/includes take Connection. CustomerIngest adds StoreManagerInterface. OrderIngest routes per order.
- HistoryImport: state/begin/update/horizonNow/ordersPage take Connection; beginQueued() loops connections. HistoryConfig forNewImport(Connection)/stored(Connection), ctor (Connections, Logger). HistoryProgress takes Connections; report() loops; card() resolves the job's connection.
- JobManager hasCompleted/latest/isStalled/requestCancel take ?int $websiteId; startIfIdle cancels stalled per connection for engine imports. EngineImportGuard::refusal($type, $target, ?Connection). ImportStarter: website -> its connection; no website -> every connected connection. ImportsOnConnect::start(Connection) (Connector passes the primary). New Model/Backfill/EngineJobConnection (processors resolve the job's connection; stop a job whose connection is gone or refused).
- Observers ProductDeleteBefore, ProductImportDelete, OrderSaveAfter, CreditmemoSave gate on Connections::anyConnected().
- Currency read at the connection's canonical store (unchanged bytes; known mislabel noted in ARCHITECTURE).
- For steps 4–6: Cron/BackfillTick refusal gate still reads the primary's isRefused() for any engine job -> step 5 should check the job's own connection. Admin still primary-only: BackfillState (requestCancel/isStalled without website), CustomersImportNotice (hasCompleted/findActive/isStalled without key), DashboardData (readUnsent() no connection), Connector always starts the primary's imports -> step 5/6. HealthCheck engine-down flags and storefront paths should use Connection::flagCode().
- Gotcha: a full vendor/bin/phpcs run took 30–105 min on this Mac under load.
