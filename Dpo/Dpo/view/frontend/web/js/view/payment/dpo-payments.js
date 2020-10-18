/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component,
              rendererList
    ) {
        'use strict';

        rendererList.push(
            {
                type: 'dpo',
                component: 'Dpo_Dpo/js/view/payment/method-renderer/dpo-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
