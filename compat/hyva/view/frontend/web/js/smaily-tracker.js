/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Hyvä port of Smaily_Connect/js/tracker — no RequireJS, no jQuery.
 * Differences from the Luma build, and only these:
 *  - plain script + JSON config block instead of AMD + x-magento-init;
 *  - fetch(keepalive) replaces the $.ajax sendBeacon fallback;
 *  - cart_add: Hyvä fires no `ajax:addToCart` jQuery event, and its default
 *    add-to-cart is a regular form POST to checkout/cart/add — so the event
 *    is captured at form-submit time (see TODO below);
 *  - consent arriving later: Hyvä's cookie notice dispatches the window
 *    event `user-allowed-save-cookie` instead of Luma's jQuery
 *    `user:allowed:save:cookie`.
 *
 * Browse tracker: page-context events batched (5s window) to the plugin
 * relay (smaily/relay), which forwards them to the Campaign Intelligence
 * engine — the API key never reaches the browser. Loss-tolerant by design.
 *
 * Consent (marketing), as in the WooCommerce plugin: (1) the store's own
 * window.smailyConnect.consentOverride() when it is a function — its
 * answer, true or false, decides; (2) otherwise, under Magento cookie
 * restriction mode, the user_allowed_save_cookie cookie; (3) otherwise no
 * consent. Without consent the tracker sends nothing and writes no session
 * cookie; consent that arrives later on the page (Hyvä's
 * user-allowed-save-cookie, or smaily:consent-changed fired by the store's
 * consent adapter) starts it. Every flush asks again and drops the queue
 * when consent is gone. Campaign-click capture (smaily-attribution.js) is
 * not gated.
 */
(function () {
    'use strict';

    var BATCH_WINDOW_MS = 5000,
        MAX_BATCH = 100,
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed';

    var configEl = document.getElementById('smaily-tracker-config'),
        config,
        helper,
        queue = [],
        flushTimer = null,
        started = false;

    if (!configEl || typeof window.smailyAttribution !== 'function') {
        return;
    }

    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }

    helper = window.smailyAttribution(config.attribution);

    function consentGiven() {
        var site = window.smailyConnect;

        if (site && typeof site.consentOverride === 'function') {
            return site.consentOverride() === true;
        }
        if (config.cookieRestriction) {
            return helper.getCookie('user_allowed_save_cookie') !== null;
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
        } else if (window.fetch) {
            fetch(config.relayUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: payload,
                keepalive: true
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

    // Hyvä ships no `ajax:addToCart`; its default add-to-cart is a regular
    // form POST to checkout/cart/add, so catch it at submit time (capture
    // phase) and flush immediately — sendBeacon survives the navigation.
    // Semantic difference vs Luma: fires on the ATTEMPT, not on confirmed
    // success (acceptable for a loss-tolerant popularity signal).
    // Verified on Hyvä 1.5.2 (default theme, PDP form POST): the event
    // fires with the page-context sku and flushes before navigation.
    // Known remaining gap: third-party AJAX-add-to-cart modules that call
    // form.submit() programmatically (fires no `submit` event) or replace
    // the form bypass this capture. If a store reports missing cart_add
    // events, add a `private-content-loaded` cart-diff listener
    // (event.detail.data.cart) as the success-side signal.
    document.addEventListener('submit', function (submitEvent) {
        var form = submitEvent.target,
            action = form && form.getAttribute ? String(form.getAttribute('action') || '') : '';

        if (action.indexOf('checkout/cart/add') === -1) {
            return;
        }
        var event = baseEvent('cart_add'),
            ctx = window.smailyPageContext || {};

        // Hyvä's PDP form posts the product id, not the sku — reuse the
        // page-context sku (present on product pages, absent on listings).
        if (ctx.type === 'product' && ctx.sku) {
            event.sku = ctx.sku;
        }
        push(event);
        flush();
    }, true);

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
    window.addEventListener('user-allowed-save-cookie', start);
    document.addEventListener(CONSENT_CHANGED_EVENT, start);
})();
