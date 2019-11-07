// user-claim.js

require('../../../sass/pages/user/user-claim.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('tempusdominus-bootstrap-4');
require('moment');
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validationMethods.js');


$(function() {

    let validateForm = $('.validate-form');

    const addValidation = () => {
        validateForm.validate({
            debug: false,
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onkeyup: false,
            focusCleanup: false,
            onclick: false,
            rules: {
                "claim_form[signature]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    equalToIgnoreCase: '#username_signature'
                },
                "claim_form[name]" : {
                    required: true,
                    fullName: true
                },
                "claim_form[email]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "claim_form[phone]" : {
                    phoneUK: true,
                },
                "claim_form[timeToReach]" : {
                    required: true,
                },
                "claim_form[when]" : {
                    required: true,
                    validDate: true,
                    checkDateIsValid: true
                },
                "claim_form[time]" : {
                    required: true
                },
                "claim_form[phone]" : {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneIntl: true,
                }
            },
            messages: {
                "claim_form[signature]" : {
                    required: 'Please sign the declaration, make sure this matches the policy holders name'
                },
                "claim_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "claim_form[email]": {
                    required: 'Please enter a valid email address.'
                },
                "claim_form[phone]": {
                    required: 'Please enter a number we can reach you on',
                },
                "claim_form[timeToReach]": {
                    required: 'Please enter the best time to reach you',
                },
                "claim_form[type]": {
                    required: 'Please enter whats happened to your device',
                },
                "claim_form[network]": {
                    required: 'Please enter your network',
                },
                "claim_form[when]": {
                    required: 'Please enter the date this happened',
                    validDate: 'Please enter a valid date',
                    checkDateIsValid: 'Please enter a valid date',
                },
                "claim_form[time]": {
                    required: 'Please enter the time it happened',
                },
                "claim_form[where]": {
                    required: 'Please enter where it happened'
                },
                "claim_form[message]": {
                    required: 'Please enter in as much detail as possible what happened'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') == 'claim_form[timeToReach]') {
                    error.insertAfter($('#time_picker_one'));
                } else if (element.attr('name') == 'claim_form[when]') {
                     error.insertAfter($('#date_picker_one'));
                } else if (element.attr('name') == 'claim_form[time]') {
                     error.insertAfter($('#time_picker_two'));
                } else {
                    error.insertAfter(element);
                }
            },

            submitHandler: function(form) {
                form.submit();
            },

            showErrors: function(errorMap, errorList) {
                this.defaultShowErrors();
                var vals = [];
                for (var err in errorMap) {
                    var val = $('body').find('input[name="' + err + '"]').val()
                    vals.push({'name': err, 'value': val, 'message': errorMap[err]});
                }
                $.ajax({
                  method: "POST",
                  url: "/ops/validation",
                  contentType:"application/json; charset=utf-8",
                  dataType:"json",
                  data: JSON.stringify({ 'errors': vals, 'url': self.url })
                });
            }
        });
    }

    // Message character limit
    const messageCharacters = () => {
        let textMax = 50,
            textLength = $('#claim_form_message').val().length,
            textRemaining = textMax - textLength;

        if (textRemaining < 0) {
            textRemaining = 0;
        }

        $('#claim_message_length').html('Minimum of 50 characters (' + textRemaining + ' remaining)');
    }

    // Add validation
    if (validateForm.data('client-validation')) {
        addValidation();
    }

    // All claims should be limited to 28 days in the past
    let claimLimit = moment().subtract(28, 'days');

    // Generic time picker class
    $('.time-picker').datetimepicker({
        // useCurrent: false,
        format: 'LT'
    });

    // Generic date - limited to last 28 days
    $('.date-picker').datetimepicker({
        useCurrent: false,
        format: 'DD/MM/YYYY',
        minDate: claimLimit,
        maxDate: moment()
    });

    // Mask date inputs incase manual entry
    $('#claim_form_when').mask('00/00/0000');

    // Character min limit for message
    messageCharacters();

    // Adjust character min limit on keyup
    $('#claim_form_message').on('keyup', function() {
        messageCharacters();
    });

    // Back button
    $('#claim_back_btn').click(function() {
        $('#claimfnol_confirm_tab, #claimfnol_confirm').removeClass('active');
        $('#claimfnol_tab, #claimfnol').addClass('active');
        return false;
    });

    // Starling
    if ($('#claim_warning_modal').length) {
        $('#claim_warning_modal').modal({
            backdrop: 'static',
            keyboard: false,
            show: true
        });
    }

});
