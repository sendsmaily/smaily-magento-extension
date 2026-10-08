/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Storefront recommendations: fills the "Smaily recommendations" widget's
 * empty containers with the shopper's cards. After the page has loaded,
 * and only with the shopper's marketing consent, it asks the store's own
 * route (smaily/recommendations) ONCE per page and puts the HTML it answers
 * into every container. The store decides who the shopper is from its own
 * session and cookies; this sends nothing about the shopper. An empty
 * answer, an error or a timeout leaves the containers empty.
 *
 * Consent (marketing) is the browse tracker's rule, consent.js: the
 * store's consentOverride, else Magento's cookie notice accepted for this
 * website (config.websiteId), else none. Consent that
 * arrives later on the page (Magento's user:allowed:save:cookie, or
 * smaily:consent-changed fired by the store's consent adapter) asks then.
 */
define(['jquery', 'Smaily_Connect/js/consent'], function ($, consent) {
    'use strict';

    var SLOT_SELECTOR = '[data-smaily-connect-recs]',
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed',
        booted = false;

    function fill(url) {
        fetch(url, {
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

    // Called once per container (x-magento-init keyed on the container);
    // only the first call boots, so a page asks once however many it has.
    return function (config) {
        var asked = false;

        if (booted || !config || !config.url) {
            return;
        }
        booted = true;

        function ask() {
            if (asked || !consent.consentGiven(config)) {
                return;
            }
            asked = true;
            fill(config.url);
        }

        function start() {
            ask();
            $(document).on('user:allowed:save:cookie ' + CONSENT_CHANGED_EVENT, ask);
        }

        if (document.readyState === 'complete') {
            start();
        } else {
            window.addEventListener('load', start);
        }
    };
});
