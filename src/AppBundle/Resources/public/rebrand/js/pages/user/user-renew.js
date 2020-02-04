// user-renew.js

require('../../../sass/pages/user/user-renew.scss');

// Require BS component(s)
require('bootstrap/js/dist/button');

// Require components
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validation-methods.js');

$(function(){

    let limitedToYearly = false,
        errorText = $('#renew_errors'),
        renewForm = $('#renew_form_encodedAmount'),
        renewFormCashback = $('#renew_cashback_form_encodedAmount'),
        paymentBtns = $('.form-check-input-btn');

    // Clear the form values
    const clearValues = (e) => {
        renewForm.val('');
        renewFormCashback.val('');
    }

    const clearButtons = (e) => {
        paymentBtns.prop('checked', false);
        $('.continue-btn').addClass('disabled');
    }

    const useCashback = (useCashback) => {
        if (useCashback) {
            $('#renew_without_reward_btns, #renew_without_continue_btn').hide();
            $('#renew_with_reward_btns, #renew_with_cashback_continue_btn').show();
        } else {
            $('#renew_with_reward_btns, #renew_with_cashback_continue_btn').hide();
            $('#renew_without_reward_btns, #renew_without_continue_btn').show();
        }
    }

    // Button logic
    $('#renew_cashback_btn').on('click', function(e) {
        // e.preventDefault();
        console.log('Renew GET cashback selected');
        clearValues();
        clearButtons();
        useCashback(true);
    });

    $('#renew_reward_btn').on('click', function(e) {
        // e.preventDefault();
        console.log('Renew USING cashback selected');
        clearValues();
        clearButtons();
        useCashback(false);
    });

    if ($('.renew-yearly-only').length) {
        limitedToYearly = true;
    }

    paymentBtns.on('click', function(e) {

        let ammount;

        // If limited to yearly true, using slightly different markup
        if (limitedToYearly) {
            ammount = $(this).data('value');
        } else {
            ammount = $(this).val();
        }

        console.log(ammount);

        // Set ammount on hidden fields
        renewForm.val(ammount);
        renewFormCashback.val(ammount);

        // Enable continue and add feedback
        if (renewForm.val() != '') {
            $('#renew_without_continue_btn').removeClass('disabled');
        } else {
            $('#renew_without_continue_btn').addClass('disabled');
        }

        if (renewFormCashback.val() != '') {
            $('#renew_with_cashback_continue_btn').removeClass('disabled');
        } else {
            $('#renew_with_cashback_continue_btn').addClass('disabled');
        }

    });

    // Mask sort code inputs
    $('#renew_cashback_form_sortCode, #cashback_form_sortCode').mask('00-00-00');

    $('.validate-form-renew-cashback').validate({
        debug: false,
        onkeyup: false,
        validClass: 'has-success',
        rules: {
            "renew_cashback_form[accountName]" : {
                required: true,
                minlength: 2
            },
            "renew_cashback_form[sortCode]" : {
                required: true,
                minlength: 8,
                maxlength: 8,
            },
            "renew_cashback_form[accountNumber]" : {
                required: true,
                digits: true,
                minlength: 8,
                maxlength: 8
            }
        },
        messages: {
            "renew_cashback_form[accountName]" : {
                required: 'Please enter your full name as it appears on your bank account',
                minlength: 'Please enter your full name as it appears on your bank account',
            },
            "renew_cashback_form[sortCode]" : {
                required: 'Please enter your 6 digit sort code',
                minlength: 'Please enter your 6 digit sort code',
                maxlength: 'Please enter your 6 digit sort code',
            },
            "renew_cashback_form[accountNumber]" : {
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
