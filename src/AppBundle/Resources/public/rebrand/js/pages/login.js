// faq.js

require('../../sass/pages/login.scss');

// Require BS component(s)

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
require('../common/toggle-text.js');

// Facebook
AccountKit_OnInteractive = () => {
    AccountKit.init({
        appId: document.getElementById('login-account-kit').getAttribute('data-key'),
        state: document.getElementById('login-account-kit').getAttribute('data-csrf'),
        version: "v1.0",
        fbAppEventsEnabled: true
    });
}

// Load facebook SDK
window.fbAsyncInit = () => {
    FB.init({
        appId: document.getElementById('ss-root').getAttribute('data-fb-id'),
        xfbml: true,
        version: 'v2.12',
        status: true
    });
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
// End - Load facebook SDK


loginCallback = (response) => {
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

smsLogin = () => {
    let countryCode = document.getElementById("country_code").value;
    let phoneNumber = document.getElementById("phone_number").value;
    AccountKit.login(
        'PHONE',
        {countryCode: countryCode, phoneNumber: phoneNumber}, // will use default values if not specified
        loginCallback
    );
}

fb_login = () => {
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

const sosure = sosure || {};

sosure.login = (function() {
    let self = {};
    self.sms = null;
    self.email = null;
    self.accountkit = null;
    self.showSMSLogin = null;
    self.swapLogin = null;
    self.smsLoginbtn = null;
    // Facebook
    self.fbLoginBtn = null;
    // Validation
    self.form = null;
    self.isIE = null;
    self.submit = null;

    self.init = () => {
        self.sms = $('#login-account-kit');
        self.email = $('#login-email');
        self.accountkit = self.sms.data('toggle');
        self.swapLogin = $('#swap-login');
        self.fbLoginBtn = $('#fb-login');
        self.form = $('#login-email-form');
        self.smsLoginbtn = $('#sms-login-btn');
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
        self.formNumbers = $('#login-account-kit-form');
        if (self.formNumbers.data('client-validation') && !self.isIE) {
            self.addValidationNumbers();
        }
    }

    self.formToggle = () => {
        self.email.toggle();
        self.sms.toggle();
    }

    self.btnToggle = () => {
        self.swapLogin.find('span').toggleText('Mobile Number', 'Email');
    }

    // Validate the input
    self.addValidationNumbers = () => {
        self.formNumbers.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
            rules: {
                phone_number: {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneUK: true,
                }
            },
            messages: {
                phone_number: {
                    required: 'Please enter your phone number',
                    phoneUK: 'Please enter a valid phone number, UK numbers only!'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('id') == 'phone_number') {
                    error.insertAfter('#phone-number-holder');
                }
            },

            submitHandler: function(form) {
                form.submit();
            },
        });
    }

    self.addValidation = () => {
        self.form.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
            rules: {
                _username: {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                _password: {
                    required: true
                }
            },
            messages: {
                _username: {
                    required: 'Please enter your email address',
                    email: 'Please enter a valid email address',
                    emaildomain: 'Please enter a valid email address',
                },
                _password: {
                    required: 'Please enter your password'
                }
            },

            submitHandler: function(form) {
                form.submit();
            },
        });
    }

    return self;
})();

$(function() {

    sosure.login.init();

    // Check the data attr for account kit || check the url
    sosure.login.showSMSLogin = true;

    // On load sort content out based off type
    if (sosure.login.accountkit == '1' || window.location.hash == '#email') {
        sosure.login.showSMSLogin = false;
    }

    if (sosure.login.showSMSLogin == false) {

        // Toggle the forms
        sosure.login.formToggle();

        // Toggle the button
        sosure.login.btnToggle();
    }

    // If user clicks the email/sms button
    sosure.login.swapLogin.on('click', function(e) {
        e.preventDefault();

        // Toggle the forms
        sosure.login.formToggle();

        //Toggle the button
        sosure.login.btnToggle();
    });

    // Facebook
    sosure.login.fbLoginBtn.on('click', function(e) {
        e.preventDefault();

        fb_login();
    });

});
