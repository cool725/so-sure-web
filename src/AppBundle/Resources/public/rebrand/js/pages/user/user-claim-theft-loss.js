// user-claim-loss.js

// Require components
require('tempusdominus-bootstrap-4');
require('moment');
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validation-methods.js');
import bsCustomFileInput from 'bs-custom-file-input'

$(function() {

    bsCustomFileInput.init();

    let validateForm = $('.validate-form');

    const addValidation = () => {
        validateForm.validate({
            debug: false,
            onkeyup: false,
            onclick: false,
            onfocusout: false,
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            rules: {
                "claim_theftloss_form[hasContacted]" : {
                    required: true
                },
                "claim_theftloss_form[contactedPlace]" : {
                    required: true
                },
                "claim_theftloss_form[blockedDate]" : {
                    required: true
                },
                "claim_theftloss_form[reportedDate]" : {
                    required: true
                },
                "claim_theftloss_form[reportType]" : {
                    required: true
                }
            },
            messages: {
                "claim_theftloss_form[hasContacted]" : {
                    required: 'Please select if you contacted the place'
                },
                "claim_theftloss_form[contactedPlace]": {
                    required: 'Please enter the name of the place you last had your phone'
                },
                "claim_theftloss_form[blockedDate]" : {
                    required: 'Please enter when you contacted your network provider'
                },
                "claim_theftloss_form[reportedDate]" : {
                    required: 'Please enter when you reported it'
                },
                "claim_theftloss_form[reportType]" : {
                    required: 'Please select how you reported it'
                },
                "claim_theftloss_form[force]" : {
                    required: 'Please enter the police force reported to'
                },
                "claim_theftloss_form[crimeReferenceNumber]" : {
                    required: 'Please enter the crime ref no. they provided'
                },
                "claim_theftloss_form[proofOfLoss]" : {
                    required: 'Please upload your report my loss documentation'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') == 'claim_theftloss_form[blockedDate]') {
                    error.insertAfter($('#date_picker_one'));
                } else if (element.attr('name') == 'claim_theftloss_form[reportedDate]') {
                     error.insertAfter($('#date_picker_two'));
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

    // Add validation
    if (validateForm.data('client-validation')) {
        addValidation();
    }

    // All claims should be limited to 28 days in the past
    let claimLimit = moment().subtract(28, 'days');

    // Generic date - limited to last 28 days
    $('.date-picker').datetimepicker({
        useCurrent: false,
        format: 'DD/MM/YYYY',
        minDate: claimLimit
    });

    // Mask date inputs incase manual entry
    $('#claim_theftloss_form_blockedDate').mask('00/00/0000');

    $('#claim_theftloss_form_reportType').on('change', function(e) {
        if ($(this).val() == 'police-station') {
            $('#report_to_police').slideDown();
            $('#report_my_loss').slideUp();
            $('#claim_theftloss_form_force, #claim_theftloss_form_crimeReferenceNumber').addClass('required');
            $('#claim_theftloss_form_proofOfLoss').removeClass('required');
        } else if ($(this).val() == 'online') {
            $('#report_my_loss').slideDown();
            $('#report_to_police').slideUp();
            $('#claim_theftloss_form_proofOfLoss').addClass('required');
            $('#claim_theftloss_form_force, #claim_theftloss_form_crimeReferenceNumber').removeClass('required');
        } else {
            $('#report_to_police').slideUp();
            $('#report_my_loss').slideUp();
            $('#claim_theftloss_form_force, #claim_theftloss_form_crimeReferenceNumber, #claim_theftloss_form_proofOfLoss').removeClass('required');
        }
    });

});
