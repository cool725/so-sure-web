// purchase-payment.js

// Require BS component(s)
// require('bootstrap/js/dist/modal');
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../common/validationMethods.js');
let textFit = require('textfit');

$(function(){

    textFit($('.fit')[0], {detectMultiLine: false});

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
        paymentForm = $('.payment-form');

    const addValidation = () => {
        validateForm.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
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
            cardTokenised: function(event) {
                $('html, body').animate({ scrollTop: 0 }, 'fast');
                // Show loading screen
                $('.loading-screen').fadeIn();
                // Scroll to top of page
                // console.log(event.data.cardToken);
                let url = paymentForm.data('url'),
                    csrf = paymentForm.data('csrf'),
                    pennies = paymentForm.data('value');
                // console.log(url);
                $.post(url, {'csrf': csrf, 'token': event.data.cardToken, 'pennies': pennies}, function(resp) {
                    // console.log(resp);
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

    // TODO: Move to component
    $('.radio-btn').on('click', function(e) {
        e.preventDefault();

        $('.radio-btn').removeClass('radio-btn-active');
        $(this).addClass('radio-btn-active');

        // Set the value for the form element
        let val = $(this).data('value');
        $('input[name="purchase_form[amount]"][value="' + val + '"]').prop('checked', true);

        // Adjust the price in the copy
        let premium = $(this).data('premium-type'),
            price = $('#purchase_price');

        price.html('&pound;' + val + ' a ' + premium);

        let checkoutUrl = paymentForm.data('url'),
            type = $(this).data('premium-param');
            url = new URL(checkoutUrl);
            amount = val * 100;
        url.searchParams.set('pennies', amount);
        url.searchParams.set('premium', type);
        let newUrl = url.href;

        console.log(newUrl);

        // Clear payment data
        paymentForm.removeData('value');
        paymentForm.removeData('url');

        // Update the checkout form
        paymentForm.attr('data-value', amount);
        paymentForm.attr('data-url', newUrl);

        console.log(paymentForm.data());
    });

    console.log(paymentForm.data());

    $('.btn-card-pay').on('click', function(e) {
        e.preventDefault();

        // Ensurre that all previous configure event handlers are cleared before adding another
        Checkout.removeAllEventHandlers(Checkout.Events.CARD_TOKENISED);
        // Set configure
        configureCheckout();

        // Open the lightbox
        Checkout.open();
    });
});
