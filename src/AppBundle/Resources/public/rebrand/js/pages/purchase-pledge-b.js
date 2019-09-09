// purchase-pledge-b.js

// Require BS component(s)
require('bootstrap/js/dist/collapse');
require('bootstrap/js/dist/dropdown');

// Require components
require('jquery-validation');
require('../common/validationMethods.js');

$(function(){

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);

    const addValidation = () => {
        validateForm.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            focusCleanup: true,
            onkeyup: false,
            onclick: false,
            rules: {

            },
            messages: {
                // Leave validation messages blank as class gets added to the label
                "purchase_form[agreedDamage]": {
                    required: ''
                },
                "purchase_form[agreedAgeLocation]": {
                    required: ''
                },
                "purchase_form[agreedExcess]": {
                    required: ''
                },
                "purchase_form[agreedTerms]": {
                    required: ''
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

});
