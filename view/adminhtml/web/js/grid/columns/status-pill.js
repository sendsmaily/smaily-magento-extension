/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

/**
 * Status column of the unified log grid (PRO-3565): the stock select
 * column — same options, filter and sorting — drawn as the design pack's
 * status pill. The pill variant comes with each row from the server
 * (Ui\Component\LogStatusColumn), so the grid and the Details panel read
 * one mapping.
 */
define([
    'Magento_Ui/js/grid/columns/select'
], function (Select) {
    'use strict';

    return Select.extend({
        defaults: {
            bodyTmpl: 'Smaily_Connect/grid/cells/status-pill'
        },

        /**
         * @param {Object} row
         * @returns {String}
         */
        getPillClass: function (row) {
            var variant = String(row[this.index + '_pill'] || 'neutral').replace(/[^a-z]/g, '');

            return 'smaily-pill smaily-pill--' + (variant || 'neutral');
        }
    });
});
