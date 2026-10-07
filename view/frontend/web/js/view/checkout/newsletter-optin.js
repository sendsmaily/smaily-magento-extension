/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */
define([
    'uiComponent',
    'ko',
    'mage/storage',
    'Magento_Checkout/js/model/quote'
], function (Component, ko, storage, quote) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Smaily_Connect/checkout/newsletter-optin'
        },

        optedIn: ko.observable(false),

        initialize: function () {
            this._super();
            this.optedIn.subscribe(this.persist.bind(this));

            return this;
        },

        /**
         * Persist the opt-in choice server-side so order placement can read
         * it regardless of which payment flow places the order.
         *
         * @param {Boolean} value
         */
        persist: function (value) {
            storage.post(
                'smaily/checkout/optin',
                JSON.stringify({
                    opted_in: value === true,
                    form_key: window.checkoutConfig ? window.checkoutConfig.formKey : ''
                }),
                false
            );
        }
    });
});
