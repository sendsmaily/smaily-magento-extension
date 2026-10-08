/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * The shopper's marketing consent, one rule for the storefront scripts
 * (tracker.js, recommendations.js), as in the WooCommerce plugin: (1) the
 * store's own window.smailyConnect.consentOverride() when it is a function
 * — its answer, true or false, decides; (2) otherwise, under Magento cookie
 * restriction mode (config.cookieRestriction), the user_allowed_save_cookie
 * cookie accepted for this website (config.websiteId), as Magento reads it;
 * (3) otherwise no consent. The Hyvä scripts use the same rule from
 * window.smailyConsent (compat/hyva/.../smaily-attribution.js).
 */
define([], function () {
    'use strict';

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

        return match ? decodeURIComponent(match[1]) : null;
    }

    // The cookie notice's cookie is a JSON map of the website ids the
    // shopper accepted on ({"1":1}); one cookie domain can serve several
    // websites, so only this website's entry is consent — as Magento's
    // cookie helper and Hyvä's cookie notice read it. A value that does not
    // parse is no consent, as in the cookie helper.
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

    return {
        consentGiven: consentGiven
    };
});
