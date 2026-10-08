/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Hyvä port of Smaily_Connect/js/recommendations — no RequireJS, no jQuery.
 * Differences from the Luma build, and only these:
 *  - plain script + JSON config block instead of AMD + x-magento-init; it
 *    loads on every page and does nothing without a widget container;
 *  - consent arriving later: Hyvä's cookie notice dispatches the window
 *    event `user-allowed-save-cookie` instead of Luma's jQuery
 *    `user:allowed:save:cookie`.
 *
 * Storefront recommendations: fills the "Smaily recommendations" widget's
 * empty containers with the shopper's cards. After the page has loaded,
 * and only with the shopper's marketing consent, it asks the store's own
 * route (smaily/recommendations) ONCE per page and puts the HTML it answers
 * into every container. The store decides who the shopper is from its own
 * session and cookies; this sends nothing about the shopper. An empty
 * answer, an error or a timeout leaves the containers empty.
 *
 * Consent (marketing) is the browse tracker's rule, window.smailyConsent
 * (smaily-attribution.js, which loads first): the store's consentOverride,
 * else Magento's cookie notice accepted for this website
 * (config.websiteId), else none. Consent that arrives later on the page (Hyvä's user-allowed-save-cookie,
 * or smaily:consent-changed fired by the store's consent adapter) asks then.
 */
(function () {
    'use strict';

    var SLOT_SELECTOR = '[data-smaily-connect-recs]',
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed';

    var configEl = document.getElementById('smaily-recommendations-config'),
        config,
        asked = false;

    if (!configEl || !document.querySelector(SLOT_SELECTOR)
        || typeof window.smailyConsent !== 'function') {
        return;
    }

    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }
    if (!config || !config.url) {
        return;
    }

    function fill() {
        fetch(config.url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {Accept: 'text/html'}
        }).then(function (response) {
            return response.ok ? response.text() : '';
        }).then(function (html) {
            if (!html || !html.trim()) {
                return;
            }
            Array.prototype.forEach.call(document.querySelectorAll(SLOT_SELECTOR), function (slot) {
                slot.innerHTML = html;
            });
        }).catch(function () {});
    }

    function ask() {
        if (asked || !window.smailyConsent(config)) {
            return;
        }
        asked = true;
        fill();
    }

    function start() {
        ask();
        window.addEventListener('user-allowed-save-cookie', ask);
        document.addEventListener(CONSENT_CHANGED_EVENT, ask);
    }

    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }
})();
