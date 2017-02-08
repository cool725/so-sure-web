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

$(function() {
  
    $(window).bind("load", function() { 
      loadDigits();
    });

    loadDigitsInterval = setInterval(function(){ loadDigits(); }, 10000);

    $('.swap-login').on('click', function() {
      $('.login-email').toggle();
      $('.login-digits').toggle();
    });

    if ($('.login-digits').data('toggle') == "1") {
      $('.login-email').toggle();
      $('.login-digits').toggle();
    }
    else {
      if (window.location.hash == "#email") {
        $('.login-email').toggle();
        $('.login-digits').toggle();
      }
    }
});