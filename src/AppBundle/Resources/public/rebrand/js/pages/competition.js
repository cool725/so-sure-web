// competition.js

require('../../sass/pages/competition.scss');

// Require components
let textFit = require('textfit');
require('jquery-validation');
require('../common/validationMethods.js');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    // Use textfit plugin for h1 tag
    textFit($('.fit'), {detectMultiLine: true});

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
                "lead_form[email]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
            },
            messages: {
                "lead_form[email]" : {
                    required: 'Please enter a valid email address.'
                },
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

});
