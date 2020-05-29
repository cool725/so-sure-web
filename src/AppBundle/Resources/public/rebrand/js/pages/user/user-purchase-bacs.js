// purchase-purchase-bacs.js

// Require BS component(s)

// Require components
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validation-methods.js');

$(function(){

    let validateForm = $('.validate-form'),
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
                "bacs_form[accountName]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    LastName: '#bacs_form_validateName'
                },
                "bacs_form[sortCode]": {
                    required: true,
                },
                "bacs_form[billingDate]": {
                    required: true,
                }
            },
            messages: {
                "bacs_form[accountName]": {
                    required: 'Please enter the name on the account',
                },
                "bacs_form[sortCode]": {
                    required: 'Please enter your sort code',
                },
                "bacs_form[accountNumber]": {
                    required: 'Please enter your account number',
                },
                "bacs_form[billingDate]": {
                    required: 'Please select a billing date',
                },
                "bacs_form[soleSignature]": {
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

    // Mask sort code input
    $('.sort-code').mask('00-00-00');

})
