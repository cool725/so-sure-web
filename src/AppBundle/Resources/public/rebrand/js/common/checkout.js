// checkout.js

$(function() {

    let paymentForm = $('.payment-form');

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
            console.log(event.data.cardToken);
            let url = paymentForm.data('url'),
                csrf = paymentForm.data('csrf'),
                pennies = paymentForm.data('value');
            let redirect = paymentForm.data('redirect-url');
            $.post(url, {'csrf': csrf, 'token': event.data.cardToken, 'pennies': pennies}, function(resp) {
                if (resp.redirect) redirect = resp.redirect;
            }).fail(function() {
                $('.loading-screen').fadeOut();
            }).always(function() {
                if (redirect) {
                    window.location.href = redirect;
                } else {
                    window.location.reload(false);
                }
            });
        }
    });

    $('.btn-card-pay').on('click', function(e) {
        //console.log('click');
        e.preventDefault();
        Checkout.open();
    });
});
