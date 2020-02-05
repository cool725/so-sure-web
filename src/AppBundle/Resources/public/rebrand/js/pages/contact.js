// contact.js

require('../../sass/pages/contact.scss');

// Require BS component(s)
// require('bootstrap/js/dist/scrollspy');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');

$(function() {

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);

    const addValidation = () => {
        validateForm.validate({
            debug: false,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            focusCleanup: true,
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
                if (grecaptcha.getResponse()) {
                    form.submit();
                } else {
                    alert('Please confirm captcha to proceed')
                }
            }
        });
    }

    // Add validation
    if (validateForm.data('client-validation') && !isIE) {
        addValidation();
    }

});
