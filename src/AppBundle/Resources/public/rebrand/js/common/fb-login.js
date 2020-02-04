// fb-login.js

$('.facebook_login').on('click', function() {
    FB.getLoginStatus(function(response) {
        if (response.status === 'connected') {
            document.location = $('#ss-root').data('fb-redirect');
        } else {
            FB.login(function(response) {
                if (response.authResponse) {
                    document.location = $('#ss-root').data('fb-redirect');
                } else {
                    alert('Cancelled.');
                }
            }, {scope: 'email'});
        }
    });
});
