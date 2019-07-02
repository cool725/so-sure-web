// user-claim-damage.js

// Require components
require('moment');
require('jquery-validation');
require('jquery-mask-plugin');
require('../../common/validationMethods.js');
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
            rules: {
                "claim_damage_form[typeDetails]" : {
                    required: true
                },
                "claim_damage_form[monthOfPurchase]" : {
                    required: true
                },
                "claim_damage_form[yearOfPurchase]" : {
                    required: true
                },
                "claim_damage_form[phoneStatus]" : {
                    required: true
                }
            },
            messages: {
                "claim_damage_form[typeDetails]" : {
                    required: 'Please select the type of damage'
                },
                "claim_damage_form[monthOfPurchase]": {
                    required: 'Please enter the month you bought your phone'
                },
                "claim_damage_form[yearOfPurchase]" : {
                    required: 'Please enter the month you bought your phone'
                },
                "claim_damage_form[phoneStatus]" : {
                    required: 'Please select the condition of your phone'
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

    $('#claim_damage_form_typeDetails').on('change', function(e) {
        if ($(this).val() == 'other') {
            $('#other_damage').slideDown();
            $('#claim_damage_form_typeDetailsOther').addClass('required');
        } else {
            $('#other_damage').slideUp();
            $('#claim_damage_form_typeDetailsOther').removeClass('required');
        }
    });

});
