// user-cancel.js

require('../../../sass/pages/user/user-cancel.scss');

// Require BS component(s)

// Require components
require('jquery-validation');
require('../../common/validation-methods.js');
import tracking from '../../common/track-data.js';

$(function() {

    let validateForm = $('.validate-form'),
        trackr;

    const addValidation = () => {
        validateForm.validate({
            debug: false,
            onkeyup: false,
            focusCleanup: false,
            onclick: false,
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            rules: {
                "cancel_form[reason]" : {
                    required: true
                }
            },
            messages: {
                "cancel_form[reason]" : {
                    required: 'Please select your reason for cancelling'
                }
            },

            submitHandler: function(form) {
                if (confirm('Are you sure you want to cancel your policy?')) {
                    tracking(trackr, '', '');
                    form.submit();
                }
            },
        })
    }

    // Add validation
    if (validateForm.data('client-validation')) {
        addValidation();
    }

    $('#cancel_form_reason').on('change', function(e) {
        let reason = $(this).val(),
            index = $(this).prop('selectedIndex');

        $('.reason').hide();
        $('#reason_' + index).fadeIn('fast');

        // Show other text input
        if (index == 8) {
            $('#cancel_form_othertxt').removeClass('hideme')
                                      .addClass('required');
        } else {
            $('#cancel_form_othertxt').addClass('hideme')
                                      .removeClass('required')
                                      .next('label').hide();
        }

        // Set tracking event
        if (reason != '') {
            trackr = 'cancel-page-' + reason;
        } else {
            trackr = '';
        }
    });
});
