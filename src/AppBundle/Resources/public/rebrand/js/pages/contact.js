// contact.js

require('../../sass/pages/contact.scss');

// Require BS component(s)
// require('bootstrap/js/dist/scrollspy');

// Require components
require('jquery-validation');
require('../common/validationMethods.js');

const sosure = sosure || {};

sosure.contactUs = (function() {
    let self = {};
    self.form = null;
    self.isIE = null;

    self.init = () => {
        self.form = $('.validate-form');
        self.isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);
        if (self.form.data('client-validation') && !self.isIE) {
            self.addValidation();
        }
    }

    self.addValidation = () => {
        self.form.validate({
            debug: false,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            focusCleanup: true,
            onkeyup: false,
            onclick: false,
            rules: {
                "contact_form[name]": {
                    required: true
                },
                "contact_form[email]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "contact_form[phone]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    }
                },
                "contact_form[message]": {
                    required: true
                },
            },
            messages: {
                "contact_form[name]": {
                    required: 'Please enter your name'
                },
                "contact_form[email]": {
                    required: 'Please enter your email'
                },
                "contact_form[phone]": {
                    required: 'Please enter your phone number'
                },
                "contact_form[message]": {
                    required: 'Please enter your message to us 🍆'
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

    sosure.contactUs.init();

});
