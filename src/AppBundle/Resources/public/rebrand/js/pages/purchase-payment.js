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
        paymentForm = $('.payment-form'),
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

    const configureCheckout = () => {
        Checkout.configure({
            publicKey: paymentForm.data('public-key'),
            customerEmail: paymentForm.data('customer-email'),
            value: paymentForm.data('value'),
            currency: paymentForm.data('currency'),
            debugMode: paymentForm.data('debug-mode'),
            paymentMode: paymentForm.data('payment-mode'),
            cardFormMode: paymentForm.data('card-form-mode'),
            title: paymentForm.data('title'),
            subtitle: paymentForm.data('subtitle'),
            logoUrl: 'https://cdn.so-sure.com/images/rebrand/logo/so-sure_logo_checkout.svg',
            themeColor: '#2593f3',
            forceMobileRedirect: true,
            redirectUrl: paymentForm.data('url'),
            userCode: paymentForm.data('scode'),
            cardTokenised: function(event) {
                $('html, body').animate({ scrollTop: 0 }, 'fast');
                $('.loading-screen').fadeIn();
                let url = paymentForm.data('url'),
                    csrf = paymentForm.data('csrf'),
                    pennies = paymentForm.data('value'),
                    scode = paymentForm.data('scode');
                $.post(url, {'csrf': csrf, 'token': event.data.cardToken, 'pennies': pennies, 'scode': scode}, function(resp) {
                    console.log(resp);
                }).fail(function() {
                    $('.loading-screen').fadeOut();
                }).always(function() {
                    let redirect = paymentForm.data('redirect-url');
                    if (redirect) {
                        window.location.href = redirect;
                    } else {
                        window.location.reload(false);
                    }
                });
            }
        });
    }

    const payOptionCC = $('#payment-option-credit-card'),
          payOptionDD = $('#payment-option-direct-debit'),
          payCtaCC    = $('#payment-cta-credit-card'),
          payCtaDD    = $('#payment-cta-direct-debit'),
          payOptionTl = $('#payment-option-title');

    $('.payment-card-type').on('click', function(e) {
        $('.payment-card-type').removeClass('active');
        $(this).addClass('active');

        let option = $(this).data('option');

        if (option == 'direct-debit') {
            payCtaCC.addClass('hideme');
            payCtaDD.removeClass('hideme');
        } else {
            payCtaCC.removeClass('hideme');
            payCtaDD.addClass('hideme');
        }
    });

    $('.payment-card-cycle').on('click', function(e) {
        e.preventDefault();

        $('.payment-card-cycle').removeClass('active');
        $(this).addClass('active');

        // Set the value for the form element
        let val   = $(this).data('value'),
            cycle = $(this).data('premium-type');

        // if (cycle === 'month') {
        //     payOptionCC.addClass('hideme');
        //     payOptionDD.find('.payment-card-type').addClass('active');
        //     payCtaCC.addClass('hideme');
        //     payCtaDD.removeClass('hideme');
        //     payOptionTl.addClass('hideme');
        // } else {
        //     payOptionCC.removeClass('hideme');
        //     payOptionDD.find('.payment-card-type').removeClass('active');
        //     payOptionCC.find('.payment-card-type').addClass('active');
        //     payCtaCC.removeClass('hideme');
        //     payCtaDD.addClass('hideme');
        //     payOptionTl.removeClass('hideme');
        // }

        // Set the linked radio
        $('input[name="purchase_form[amount]"][value="' + val + '"]').prop('checked', true);

        let checkoutUrl = paymentForm.data('url'),
            type = $(this).data('premium-param');
            url = new URL(checkoutUrl);
            amount = val * 100;
        url.searchParams.set('pennies', amount);
        url.searchParams.set('premium', type);

        if (userCode.val()) {
            url.searchParams.set('scode', userCode.val());
        }

        let newUrl = url.href;

        // Clear payment data
        paymentForm.removeData('value');
        paymentForm.removeData('url');

        // Update the checkout form
        paymentForm.attr('data-value', amount);
        paymentForm.attr('data-url', newUrl);
    });

    userCode.on('blur', function(e) {
        if (validateForm.valid() == true) {
            // Set scode if user adds one
            if (!paymentForm.data('scode') && userCode.val()) {
                paymentForm.attr('data-scode', userCode.val());
                let checkoutUrl = paymentForm.data('url'),
                    url = new URL(checkoutUrl);
                url.searchParams.set('scode', userCode.val());
                let newUrl = url.href;
                paymentForm.removeData('url');
                paymentForm.attr('data-url', newUrl);
            }
        }
    });

    $('.btn-card-pay').on('click', function(e) {
        e.preventDefault();

        if (validateForm.valid() == true) {
            // Ensurre that all previous configure event handlers are cleared before adding another
            Checkout.removeAllEventHandlers(Checkout.Events.CARD_TOKENISED);
            // Set configure
            configureCheckout();
            // Open the lightbox
            Checkout.open();
        }
    });
});
