/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

/**
 * Magento's checkout keeps a guest's email in the browser until the payment
 * step. This puts it on the guest cart as soon as the field holds a valid
 * address, so an abandoned-cart reminder can reach a guest who leaves at the
 * shipping step (PRO-3693).
 *
 * It rides on Magento's own email check: checkEmailAvailability() runs only
 * once the field validates and the shopper has stopped typing for the
 * component's checkDelay. One request goes per change of the address, none
 * for a signed-in customer. Fire-and-forget: the answer is not read and a
 * failure changes nothing in the checkout.
 */
define([
    'mage/storage',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder'
], function (storage, customer, quote, urlBuilder) {
    'use strict';

    // Shared by every email field on the page, so the shipping and the
    // billing step never send the same address twice.
    var lastSent = null;

    /**
     * @param {String} email
     */
    function capture(email) {
        if (customer.isLoggedIn() || !email || email === lastSent) {
            return;
        }
        lastSent = email;

        storage.post(
            urlBuilder.createUrl('/smaily-connect/guest-carts/:cartId/email', {
                cartId: quote.getQuoteId()
            }),
            JSON.stringify({
                email: email
            }),
            false
        );
    }

    return function (Component) {
        return Component.extend({
            /**
             * An address Magento validated earlier (the field opens filled
             * in) goes to the cart without waiting for the shopper to type.
             *
             * @returns {Object}
             */
            initialize: function () {
                this._super();
                capture(quote.guestEmail);

                return this;
            },

            /**
             * Magento calls this only for a valid address, after the typing
             * pause.
             *
             * @returns {*}
             */
            checkEmailAvailability: function () {
                var result = this._super();

                capture(this.email());

                return result;
            }
        });
    };
});
