// purchase-payment.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../common/validationMethods.js');

const sosure = sosure || {};

sosure.purchaseStepPayment = (function() {
    let self = {};
    self.form = null;
    self.isIE = null;
    self.loader = null;
    self.webPay = null;

    self.init = () => {
        self.form = $('.validate-form');
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        self.loader = $('#so-sure-loader');
        self.webPay = $('#webpay-form');
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    }

    self.addValidation = () => {
        self.form.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            // onclick: false,
            rules: {

            },
            messages: {

            },

            errorPlacement: function(error, element) {
                if (element.attr('name') === "purchase_form[amount]") {
                    error.insertAfter($('.payment-options__title'));
                } else {
                    error.insertAfter(element);
                }
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    return self;
})();

$(function(){

    sosure.purchaseStepPayment.init();

    // TODO: Move to component
    $('.radio-btn').on('click', function(e) {
        e.preventDefault();

        $('.radio-btn').removeClass('radio-btn-active');
        $(this).addClass('radio-btn-active');

        // Set the value for the form element
        let val = $(this).data('value');
        $('input[name="purchase_form[amount]"][value="' + val + '"]').prop('checked', true);

        // Adjust the price in the copy
        let premium = $(this).data('premium-type');
        let price = $('#purchase_price');
        price.html('&pound;' + val + ' a ' + premium);
    });

    if ($.trim($('#Reference').val()).length > 0) {
        // Show loading overlay
        sosure.purchaseStepPayment.loader.show();
        sosure.purchaseStepPayment.webPay.submit();
    }
});
