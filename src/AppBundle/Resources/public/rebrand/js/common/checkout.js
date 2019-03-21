// checkout.js

$(function() {
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
        title: $('.payment-form').data('title'),
        subtitle: $('.payment-form').data('subtitle'),
        logoUrl: 'https://cdn.so-sure.com/images/rebrand/logo/so-sure_logo_checkout.svg',
        themeColor: '#2593f3',
        forceMobileRedirect: true,
        redirectUrl: $('.payment-form').data('url'),
        cardTokenised: function(event) {
            $('html, body').animate({ scrollTop: 0 }, 'fast');
            // Show loading screen
            $('.loading-screen').fadeIn();
            // Scroll to top of page
            console.log(event.data.cardToken);
            var url = $('.payment-form').data('url');
            var csrf = $('.payment-form').data('csrf');
            var pennies = $('.payment-form').data('value');
            console.log(url);
            $.post(url, {'csrf': csrf, 'token': event.data.cardToken, 'pennies': pennies}, function(resp) {
                console.log(resp);
            }).fail(function() {
                $('.loading-screen').fadeOut();
            }).always(function() {
                let redirect = $('.payment-form').data('redirect-url');
                if (redirect) {
                    window.location.href = redirect;
                } else {
                    window.location.reload(false);
                }
            });
        }
    });
});
