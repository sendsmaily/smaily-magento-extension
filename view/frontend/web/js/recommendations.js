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
 * Consent (marketing) is the browse tracker's rule (tracker.js): (1) the
 * store's own window.smailyConnect.consentOverride() when it is a function
 * — its answer, true or false, decides; (2) otherwise, under Magento cookie
 * restriction mode, the user_allowed_save_cookie cookie accepted for this
 * website (config.websiteId); (3) otherwise no consent. Consent that
 * arrives later on the page (Magento's user:allowed:save:cookie, or
 * smaily:consent-changed fired by the store's consent adapter) asks then.
 */
define(['jquery'], function ($) {
    'use strict';

    var SLOT_SELECTOR = '[data-smaily-connect-recs]',
        CONSENT_CHANGED_EVENT = 'smaily:consent-changed',
        booted = false;

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

        return match ? decodeURIComponent(match[1]) : null;
    }

    // The cookie notice's cookie is a JSON map of the website ids the
    // shopper accepted on ({"1":1}); only this website's entry is consent.
    function cookieNoticeAccepted(config) {
        var accepted;

        try {
            accepted = JSON.parse(getCookie('user_allowed_save_cookie'));
        } catch (e) {
            return false;
        }

        return !!(accepted && accepted[config.websiteId]);
    }

    function consentGiven(config) {
        var site = window.smailyConnect;

        if (site && typeof site.consentOverride === 'function') {
            return site.consentOverride() === true;
        }
        if (config.cookieRestriction) {
            return cookieNoticeAccepted(config);
        }

        return false;
    }

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
            if (asked || !consentGiven(config)) {
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
