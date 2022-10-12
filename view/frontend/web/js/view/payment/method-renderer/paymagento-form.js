/*browser:true*/
/*global define*/

define(
	    [
	        'ko',
	        'jquery',
	        'Magento_Checkout/js/view/payment/default',
	        'Getnet_MagePayments/js/action/set-payment-method-action'
	    ],
    function (ko, $, Component, setPaymentMethodAction) {
        'use strict';
        return Component.extend({
            defaults: {
                redirectAfterPlaceOrder: false,
                template: 'Getnet_MagePayments/payment/paymagento-form.html'
            },

            afterPlaceOrder: function () {
                setPaymentMethodAction(this.messageContainer);
                return false;
            }
        });        
        
    }
);
