/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */
var config = {
    config: {
        mixins: {
            // A guest's email reaches the cart as soon as it is typed (PRO-3693).
            'Magento_Checkout/js/view/form/element/email': {
                'Smaily_Connect/js/view/form/element/email-mixin': true
            }
        }
    }
};
