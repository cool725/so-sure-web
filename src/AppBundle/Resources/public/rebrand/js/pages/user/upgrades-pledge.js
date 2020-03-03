// upgrades-pledge.js

require('../../../sass/pages/user/upgrades.scss');

// Require BS component(s)

// Require components
require('jquery-validation');
require('../../common/validation-methods.js');

$(function() {

    let validateForm = $('.validate-form-pledge'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);

    const addValidation = () => {
        validateForm.validate({
            debug: false,
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
                "form_upgrade_pledge[agreedDamage]": {
                    required: ''
                },
                "form_upgrade_pledge[agreedAgeLocation]": {
                    required: ''
                },
                "form_upgrade_pledge[agreedExcess]": {
                    required: ''
                },
                "form_upgrade_pledge[agreedTerms]": {
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
