// purchase-payment.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
let textFit = require('textfit');

$(function(){

    textFit($('.fit')[0], {detectMultiLine: false});

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
        checkoutForm = $('#checkout-form'),
        userCode = $('#purchase_form_promoCode');

    const addValidation = () => {
        validateForm.validate({
            debug: false,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            rules: {
                "purchase_form[promoCode]" : {
                    required: false,
                    minlength: 6,
                    maxlength: 8,
                    alphanumeric: true
                }
            },
            messages: {
                "purchase_form[promoCode]" : {
                    required: 'Please enter a valid code',
                    minlength: 'Please enter a valid code',
                    maxlength: 'Please enter a valid code',
                    alphanumeric: 'Please enter a valid code'
                }
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

    // Add validation
    if (validateForm.data('client-validation') && !isIE) {
        addValidation();
    }


    // Set payment type
    const payCtaCC    = $('#payment-cta-credit-card'),
          payCtaDD    = $('#payment-cta-direct-debit');

    $('.payment-card-type').on('click', function(e) {
        $('.payment-card-type').removeClass('active');
        $(this).addClass('active');

        let option = $(this).data('option');

        if (option == 'card') {
            payCtaCC.removeClass('hideme');
            payCtaDD.addClass('hideme');
        } else {
            payCtaCC.addClass('hideme');
            payCtaDD.removeClass('hideme');
        }
    });

    // Set payment cycle
    $('.payment-card-cycle').on('click', function(e) {
        e.preventDefault();

        $('.payment-card-cycle').removeClass('active');
        $(this).addClass('active');

        // Set the value for the form element
        let val = $(this).data('value');

        // Set the linked radio
        $('input[name="purchase_form[amount]"][value="' + val + '"]').prop('checked', true);

        // Get the payment freq
        let freq = $(this).data('premium-param');

        // Update the checkout URL
        let checkoutUrl = checkoutForm.data('checkout-url');
        let url = new URL(checkoutUrl);
        let amount = val * 100;
        url.searchParams.set('pennies', amount);
        url.searchParams.set('premium', freq);
        if (userCode.val()) {
            url.searchParams.set('scode', userCode.val());
        }

        // Set the new URL
        let newCheckoutUrl = url.href;
        checkoutForm.removeData('checkout-transaction-value');
        checkoutForm.removeData('checkout-url');
        checkoutForm.attr('data-checkout-transaction-value', amount);
        checkoutForm.attr('data-checkout-url', newCheckoutUrl);
    });

    userCode.on('blur', function(e) {
        if (validateForm.valid() == true) {
            checkoutForm.attr('data-scode', userCode.val())
            let checkoutUrl = checkoutForm.data('checkout-url');
            let url = new URL(checkoutUrl);

            if (userCode.val()) {
                url.searchParams.set('scode', userCode.val());
            } else {
                url.searchParams.delete('scode')
            }

            // Set the new URL
            let newCheckoutUrl = url.href;
            checkoutForm.removeData('checkout-url');
            checkoutForm.attr('data-checkout-url', newCheckoutUrl);
        }
    });
});
