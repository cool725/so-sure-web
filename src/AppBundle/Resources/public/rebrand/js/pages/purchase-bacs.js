// purchase-payment.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('jquery-mask-plugin');
require('../common/validationMethods.js');
require('../common/checkout.js');

const sosure = sosure || {};

sosure.purchaseStepBacs = (function() {
    let self = {};
    self.form = null;
    self.isIE = null;
    self.loader = null;
    self.webPay = null;
    self.webPayBtn = null;

    self.init = () => {
        self.form = $('.validate-form');
        self.sortCodeMask();
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        self.loader = $('#so-sure-loader');
        self.webPay = $('#webpay-form');
        self.webPayBtn = $('#to_judo_form_submit');
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    }

    self.sortCodeMask = () => {
        // Mask sort code input
        $('.sort-code').mask('00-00-00');
    }

    self.addValidation = () => {
        self.form.validate({
            debug: false,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            // onclick: false,
            rules: {
                "bacs_form[accountName]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    LastName: '#bacs_form_validateName'
                },
                "bacs_form[sortCode]": {
                    required: true,
                },
                "bacs_form[validateSortCode]": {
                    required: true,
                    equalTo: '#bacs_form_sortCode',
                },
                "bacs_form[accountNumber]": {
                    required: true,
                },
                "bacs_form[validateAccountNumber]": {
                    required: true,
                    equalTo: '#bacs_form_accountNumber',
                }
            },
            messages: {
                "bacs_form[accountName]": {
                    required: 'Please enter the name on the account',
                },
                "bacs_form[sortCode]": {
                    required: 'Please enter your sort code',
                },
                "bacs_form[validateSortCode]": {
                    required: 'Please confirm your sort code',
                    equalTo: 'Your sort code doesn\'t match, please double check',
                },
                "bacs_form[accountNumber]": {
                    required: 'Please enter your account number',
                },
                "bacs_form[validateAccountNumber]": {
                    required: 'Please confirm your account number',
                    equalTo: 'Your account number doesn\'t match, please double check',
                },
                "bacs_form[soleSignature]": {
                    required: ''
                }
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    return self;
})();

$(function() {
    sosure.purchaseStepBacs.init();
    sosure.purchaseStepBacs.webPayBtn.on('click', function() {
        sosure.purchaseStepBacs.loader.show();
    });
    let webpay = $('#webpay-form');
    if (webpay.length) {
        sosure.purchaseStepBacs.loader.show();
        webpay.submit();
    }

    $('.btn-card-pay').on('click', function(e) {
        //console.log('click');
        e.preventDefault();
        Checkout.open();
    });

    Checkout.configure({
        publicKey: $('.payment-form').data('public-key'),
        customerEmail: $('.payment-form').data('customer-email'),
        value: $('.payment-form').data('value'),
        currency: $('.payment-form').data('currency'),
        debugMode: $('.payment-form').data('debug-mode'),
        paymentMode: $('.payment-form').data('payment-mode'),
        cardFormMode: $('.payment-form').data('card-form-mode'),
        cardTokenised: function(event) {
            console.log(event.data.cardToken);
        }
    });

});
