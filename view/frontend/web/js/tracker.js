/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Browse tracker: page-context events batched (5s window) to the plugin
 * relay (smaily/relay), which forwards them to the Campaign Intelligence
 * engine — the API key never reaches the browser. Loss-tolerant by design.
 *
 * Consent (marketing), as in the WooCommerce plugin: (1) the store's own
 * window.smailyConnect.consentOverride() when it is a function — its
 * answer, true or false, decides; (2) otherwise, under Magento cookie
 * restriction mode, the user_allowed_save_cookie cookie accepted for this
 * website (config.websiteId), as Magento reads it; (3) otherwise no
 * consent. Without consent the tracker sends nothing and writes no session
 * cookie; consent that arrives later on the page (Magento's
 * user:allowed:save:cookie, or smaily:consent-changed fired by the store's
 * consent adapter) starts it. Every flush asks again and drops the queue
 * when consent is gone. Campaign-click capture (attribution.js) is not
 * gated.
 */
define(['jquery', 'Smaily_Connect/js/attribution'], function ($, attribution) {
    'use strict';

    var BATCH_WINDOW_MS = 5000,
        MAX_BATCH = 100,
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed';

    return function (config) {
        var helper = attribution(config.attribution),
            queue = [],
            flushTimer = null,
            started = false;

        // The cookie notice's cookie is a JSON map of the website ids the
        // shopper accepted on ({"1":1}); one cookie domain can serve several
        // websites, so only this website's entry is consent — as Magento's
        // cookie helper and Hyvä's cookie notice read it. A value that does
        // not parse is no consent, as in the cookie helper.
        function cookieNoticeAccepted() {
            var accepted;

            try {
                accepted = JSON.parse(helper.getCookie('user_allowed_save_cookie'));
            } catch (e) {
                return false;
            }

            return !!(accepted && accepted[config.websiteId]);
        }

        function consentGiven() {
            var site = window.smailyConnect;

            if (site && typeof site.consentOverride === 'function') {
                return site.consentOverride() === true;
            }
            if (config.cookieRestriction) {
                return cookieNoticeAccepted();
            }

            return false;
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
