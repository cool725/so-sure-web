AccountKit_OnInteractive = function(){
    AccountKit.init(
        {
            appId: document.getElementById('login-account-kit').getAttribute('data-key'),
            state: document.getElementById('login-account-kit').getAttribute('data-csrf'),
            version:"v1.0",
            fbAppEventsEnabled:true
        }
    );
};

window.fbAsyncInit = function() {
  FB.init({
    appId: document.getElementById('ss-root').getAttribute('data-fb-id'),
    xfbml: true,
    version: 'v2.12',
    status     : true
  });
};

// login callback
function loginCallback(response) {
    if (response.status === "PARTIALLY_AUTHENTICATED") {
        $('#accountkit-code').val(response.code);
        $('#accountkit-csrf').val(response.state);
        $('#accountkit-form').submit();
    }
    else if (response.status === "NOT_AUTHENTICATED") {
        // handle authentication failure
    }
    else if (response.status === "BAD_PARAMS") {
        // handle bad parameters
    }
}

(function(d, s, id) {
  var js, fjs = d.getElementsByTagName(s)[0];
  if (d.getElementById(id)) {
    return;
  }
  js = d.createElement(s);
  js.id = id;
  js.src = "//connect.facebook.net/en_US/sdk.js";
  fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

// phone form submission handler
function smsLogin() {
    var countryCode = document.getElementById("country_code").value;
    var phoneNumber = document.getElementById("phone_number").value;
    AccountKit.login(
        'PHONE',
        {countryCode: countryCode, phoneNumber: phoneNumber}, // will use default values if not specified
        loginCallback
    );
}

function onLogin(loginResponse) {
    // Send headers to your server and validate user by calling Digits API
    var oAuthHeaders = loginResponse.oauth_echo_headers;
    var verifyData = {
        credentials: oAuthHeaders['X-Verify-Credentials-Authorization'],
        provider: oAuthHeaders['X-Auth-Service-Provider']
    };
    $('#credentials').val(verifyData.credentials);
    $('#provider').val(verifyData.provider);
    $('#digits-form').submit();
}

var loadDigitsInterval;

function loadDigits() {
  window.Digits.init({ consumerKey: $('.login-digits').data('key') }).done(function() {
      clearInterval(loadDigitsInterval);
      $('.digits-loading').hide();
      window.Digits.embed({
        container: '.digits-container',
          phoneNumber: '+44'
      })
      .done(onLogin)
      .fail(function() { alert('Sorry, there seems to be a temporary issue with logging in.  Please try the email login or contact support@wearesosure.com'); });
  })
}

function fb_login() {
    FB.getLoginStatus(function(response) {
        if (response.status === 'connected') {
            document.location = $('#ss-root').data('fb-redirect');
        } else {
            // not_authorized
            FB.login(function(response) {
                if (response.authResponse) {
                    document.location = $('#ss-root').data('fb-redirect');
                }
            }, {scope: 'email'});
        }
    });
}

$.fn.extend({
    toggleText: function(a, b){
        return this.text(this.text() == b ? a : b);
    }
});

$(function() {

    // Action of button to toggle between login screens
    $('#swap-login').on('click', function(e) {
        e.preventDefault();
        $('.login-email, .login-account-kit').toggle();
        $(this).find('span').toggleText('SMS', 'email');
        $('.error-text').hide();
    });

    // Check the data attr for account kit || check the url
    var showMobileLogin = true;

    if ($('.login-account-kit').data('toggle') == "1"
      || window.location.hash == "#email") {
        showMobileLogin = false;
    }

    // Swap to email
    if (showMobileLogin == false) {
        $('.login-email, .login-account-kit').toggle();
        $('#swap-login span').toggleText('mobile', 'email');
    }

    $('.btn-facebook-login').on('click', function(e) {
        e.preventDefault();
        fb_login();
    });

    // When clicking the sms login check window opens if not provide temp message
    $('#sms-login__btn').on('click', function(e) {
        e.preventDefault();
        $('#sms-login__warning').show();
        $('#sms-login__btn').prop('disabled', true);
        $('#btn-spinner').show();
        smsLogin();
    });

    // Hide warning if we leave the window
    $(window).blur(function() {
        $('#sms-login__warning').hide();
        $('#btn-spinner').hide();
        // After blur - if refocus enable button again (Mobile issue)
        $(window).focus(function() {
            $('#sms-login__btn').prop('disabled', false);
        });
    });

});
