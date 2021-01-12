// signup.js

require('../../sass/pages/signup.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
let Clipboard = require('clipboard');

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
                "influencerForm[firstName]": {
                    required: true
                },
                "influencerForm[lastName]": {
                    required: true
                },
                "influencerForm[email]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "influencerForm[gender]": {
                    required: true
                },
                "influencerForm[organisation]": {
                    required: true
                }
            },
            messages: {
                "influencerForm[firstName]": {
                    required: 'Please enter your first name'
                },
                "influencerForm[lastName]": {
                    required: 'Please enter your last name'
                },
                "influencerForm[email]": {
                    required: 'Please enter your email'
                },
                "influencerForm[gender]": {
                    required: 'Please choose your gender'
                },
                "influencerForm[organisation]": {
                    required: 'Please select the organisation you are with'
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

    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip({'title':   'Copied 😀','trigger': 'manual'})
                      .tooltip('show');
        setTimeout(function() {
            $('.btn-copy').tooltip('hide');
        }, 1500);
    });
});
