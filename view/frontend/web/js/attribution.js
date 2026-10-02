/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Recommendation attribution capture. Runs client-side so Full Page Cache
 * can never swallow a campaign-click landing: URL params -> first-party
 * cookies, later stamped onto the order server-side. Deliberately not gated
 * on analytics consent (functional first-party cookie for the merchant's
 * own email click) — matches the Woo/Shopify plugins.
 */
define([], function () {
    'use strict';

    // Contract §5: the engine validates smaily_rec_id as a UUID (8-4-4-4-12
    // hex) and rejects the whole order over a malformed one, so only a
    // well-formed id is ever written to the cookie (PRO-3576).
    var REC_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    function readParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    function validRecId(value) {
        return value && REC_ID_PATTERN.test(value) ? value : null;
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, ttlDays) {
        var expires = new Date(Date.now() + ttlDays * 86400000).toUTCString();

        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + expires
            + '; path=/; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function uuidv4() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);

        window.crypto.getRandomValues(bytes);
        bytes[6] = bytes[6] & 0x0f | 0x40;
        bytes[8] = bytes[8] & 0x3f | 0x80;
        var hex = Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');

        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
            + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    return function (config) {
        var recId = validRecId(readParam(config.paramRecId)),
            visitorToken = readParam(config.paramVisitor),
            context = readParam(config.paramContext);

        // utm_content fallback exists as a defensive dead path only — the
        // engine never issues utm_content rec ids (contract: GA pollution).
        if (!recId && readParam('utm_source') === 'smaily') {
            recId = validRecId(readParam('utm_content'));
        }

        if (recId) {
            setCookie(config.cookieRecId, recId, config.ttlRecIdDays);
        }
        if (visitorToken) {
            setCookie(config.cookieVisitor, visitorToken, config.ttlVisitorDays);
        }
        if (context) {
            setCookie(config.cookieContext, context, config.ttlContextDays);
        }

        // Persistent anonymous session id for browse events + identity merge.
        if (!getCookie(config.cookieSession)) {
            setCookie(config.cookieSession, uuidv4(), config.ttlSessionDays);
        }

        return {
            getCookie: getCookie,
            uuidv4: uuidv4
        };
    };
});
