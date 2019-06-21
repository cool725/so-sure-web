// user-cashback.js

// require('../../../sass/pages/user/user-renew.scss');

// Require BS component(s)
// require('bootstrap/js/dist/button');

// Require components
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validationMethods.js');


$(function(){

    // Mask sort code inputs
    $('#cashback_form_sortCode').mask('00-00-00');

    $('.validate-form-cashback').validate({
        debug: false,
        onkeyup: true,
        validClass: 'has-success',
        rules: {
            "cashback_form[accountName]" : {
                required: true,
                minlength: 2
            },
            "cashback_form[sortCode]" : {
                required: true,
                minlength: 8,
                maxlength: 8,
            },
            "cashback_form[accountNumber]" : {
                required: true,
                digits: true,
                minlength: 8,
                maxlength: 8
            }
        },
        messages: {
            "cashback_form[accountName]" : {
                required: 'Please enter your full name as it appears on your bank account',
                minlength: 'Please enter your full name as it appears on your bank account',
            },
            "cashback_form[sortCode]" : {
                required: 'Please enter your 6 digit sort code',
                minlength: 'Please enter your 6 digit sort code',
                maxlength: 'Please enter your 6 digit sort code',
            },
            "cashback_form[accountNumber]" : {
                required: 'Please enter your 8 digit account number',
                digits: 'Please enter your 8 digit account number',
                minlength: 'Please enter your 8 digit account number',
                maxlength: 'Please enter your 8 digit account number',
            }
        },
        submitHandler: function(form) {
            form.submit();
        }
    });

});
