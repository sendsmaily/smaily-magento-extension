/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Browse tracker: page-context events batched (5s window) to the plugin
 * relay (smaily/relay), which forwards them to the Campaign Intelligence
 * engine — the API key never reaches the browser. Loss-tolerant by design.
 *
 * Consent (marketing) is the rule in consent.js (the store's
 * consentOverride, else Magento's cookie notice accepted for this website,
 * else none). Without consent the tracker sends nothing and writes no session
 * cookie; consent that arrives later on the page (Magento's
 * user:allowed:save:cookie, or smaily:consent-changed fired by the store's
 * consent adapter) starts it. Every flush asks again and drops the queue
 * when consent is gone. Campaign-click capture (attribution.js) is not
 * gated.
 */
define([
    'jquery',
    'Smaily_Connect/js/attribution',
    'Smaily_Connect/js/consent'
], function ($, attribution, consent) {
    'use strict';

    var BATCH_WINDOW_MS = 5000,
        MAX_BATCH = 100,
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed';

    return function (config) {
        var helper = attribution(config.attribution),
            queue = [],
            flushTimer = null,
            started = false;

        function consentGiven() {
            return consent.consentGiven(config);
        }

        function sessionId() {
            return helper.getCookie(config.attribution.cookieSession);
        }

        function baseEvent(type) {
            var event = {
                event_id: helper.uuidv4(),
                session_id: sessionId(),
                event_type: type
            };
            var visitorToken = helper.getCookie(config.attribution.cookieVisitor);

            // The rec id/ctx cookies are deliberately NOT echoed here — see
            // Model/Engine/BrowseEventValidator for why.
            if (visitorToken) {
                event.smaily_visitor_token = visitorToken;
            }

            return event;
        }

        function push(event) {
            if (!started || !event.session_id) {
                return;
            }
            queue.push(event);
            if (queue.length >= MAX_BATCH) {
                flush();
            } else if (!flushTimer) {
                flushTimer = setTimeout(flush, BATCH_WINDOW_MS);
            }
        }

        function flush() {
            if (flushTimer) {
                clearTimeout(flushTimer);
                flushTimer = null;
            }
            if (!queue.length) {
                return;
            }
            if (!consentGiven()) {
                queue.length = 0;

                return;
            }
            var payload = JSON.stringify({events: queue.splice(0, MAX_BATCH)});

            if (navigator.sendBeacon) {
                navigator.sendBeacon(config.relayUrl, new Blob([payload], {type: 'application/json'}));
            } else {
                $.ajax({
                    url: config.relayUrl,
                    method: 'POST',
                    data: payload,
                    contentType: 'application/json',
                    global: false
                });
            }
        }

        function trackPageContext() {
            var ctx = window.smailyPageContext || {type: null},
                event;

            switch (ctx.type) {
                case 'product':
                    event = baseEvent('product_view');
                    if (ctx.sku) {
                        event.sku = ctx.sku;
                    }
                    if (ctx.categoryPath) {
                        event.category_path = ctx.categoryPath;
                    }
                    break;

                case 'category':
                    event = baseEvent('category_view');
                    if (ctx.categoryPath) {
                        event.category_path = ctx.categoryPath;
                    }
                    break;

                case 'checkout':
                    event = baseEvent('checkout_start');
                    break;

                case 'success':
                    event = baseEvent('checkout_complete');
                    break;

                default:
                    if (window.location.pathname.indexOf('catalogsearch') !== -1) {
                        var query = new URLSearchParams(window.location.search).get('q');

                        if (query) {
                            event = baseEvent('search');
                            event.search_query = query;
                        }
                    }
            }

            if (event) {
                push(event);
            }
        }

        // Luma fires ajax:addToCart on successful add-to-cart requests.
        $(document).on('ajax:addToCart', function (jqEvent, data) {
            var event = baseEvent('cart_add'),
                sku = data && data.sku ? data.sku : null;

            if (sku) {
                event.sku = sku;
            }
            push(event);
        });

        function start() {
            if (started || !consentGiven()) {
                return;
            }
            started = true;
            helper.ensureSession();
            trackPageContext();
        }

        window.addEventListener('pagehide', flush);

        start();
        $(document).on('user:allowed:save:cookie ' + CONSENT_CHANGED_EVENT, start);
    };
});
