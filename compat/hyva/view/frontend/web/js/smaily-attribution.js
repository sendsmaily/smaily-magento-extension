/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Hyvä port of Smaily_Connect/js/attribution — identical logic, delivered
 * as a plain script instead of an AMD module (Hyvä ships no RequireJS).
 * Config is read from the inert <script type="application/json"> block
 * rendered by Hyva_SmailyConnect::engine/attribution.phtml.
 *
 * Recommendation attribution capture. Runs client-side so Full Page Cache
 * can never swallow a campaign-click landing: URL params -> first-party
 * cookies, later stamped onto the order server-side. Deliberately not gated
 * on analytics consent (functional first-party cookie for the merchant's
 * own email click) — matches the Woo/Shopify plugins.
 */
(function () {
    'use strict';

    // Contract §5: the engine validates smaily_rec_id as a UUID (8-4-4-4-12
    // hex) and rejects the whole order over a malformed one, so only a
    // well-formed id is ever written to the cookie (PRO-3576).
    var REC_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    // The visitor token and the context are written only in the shape the
    // order capture accepts (Engine\AttributionShape, PRO-3584).
    var VISITOR_TOKEN_PATTERN = /^vt_[A-Za-z0-9]{1,61}$/,
        CONTEXT_PATTERN = /^[A-Za-z0-9._-]{1,64}$/;

    function readParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    function validRecId(value) {
        return value && REC_ID_PATTERN.test(value) ? value : null;
    }

    function shaped(value, pattern) {
        return value && pattern.test(value) ? value : null;
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

    /**
     * Capture attribution params into cookies; returns the cookie helpers.
     * Exposed as window.smailyAttribution so smaily-tracker.js can reuse it
     * (mirrors the AMD dependency in the base module).
     */
    function capture(config) {
        var recId = validRecId(readParam(config.paramRecId)),
            visitorToken = shaped(readParam(config.paramVisitor), VISITOR_TOKEN_PATTERN),
            context = shaped(readParam(config.paramContext), CONTEXT_PATTERN);

        // utm_content fallback exists as a defensive dead path only — the
        // engine never issues utm_content rec ids (contract: GA pollution).
        if (!recId && readParam('utm_source') === 'smaily') {
            recId = validRecId(readParam('utm_content'));
        }

        // Contract "Cookie names", context cookie rule: the context cookie
        // describes the same landing as the rec id cookie, so a landing with
        // a rec id sets it to the link's context, or clears it when the link
        // has none; any other landing leaves it alone (PRO-3911).
        if (recId) {
            setCookie(config.cookieRecId, recId, config.ttlRecIdDays);
            setCookie(config.cookieContext, context || '', context ? config.ttlContextDays : -1);
        }
        if (visitorToken) {
            setCookie(config.cookieVisitor, visitorToken, config.ttlVisitorDays);
        }

        // The anonymous session id of browse events is not written here: the
        // browse tracker writes it once the visitor has consented
        // (ensureSession), as the WooCommerce plugin does.
        function ensureSession() {
            if (!getCookie(config.cookieSession)) {
                setCookie(config.cookieSession, uuidv4(), config.ttlSessionDays);
            }
        }

        return {
            getCookie: getCookie,
            uuidv4: uuidv4,
            ensureSession: ensureSession
        };
    }

    window.smailyAttribution = capture;

    var configEl = document.getElementById('smaily-attribution-config');

    if (configEl) {
        try {
            capture(JSON.parse(configEl.textContent));
        } catch (e) {
            // Malformed config: attribution stays off, never break the page.
        }
    }
})();
