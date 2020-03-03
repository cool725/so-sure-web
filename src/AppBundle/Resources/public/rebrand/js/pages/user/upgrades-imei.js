// upgrades-imei.js

require('../../../sass/pages/user/upgrades.scss');

// Require BS component(s)

// Require components
require('jquery-validation');
require('../../common/validation-methods.js');

$(function() {

    let validateForm = $('.validate-form-imei'),
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
                "form_upgrade_imei[imei]" : {
                    required: true,
                    minlength: 15,
                    imei: true
                },
                "purchase_form[amount]" : {
                    required: true
                },
                "form_upgrade_imei[serialNumber]" : {
                    required: true,
                    // alphanumeric: true
                }
            },
            messages: {
                "form_upgrade_imei[imei]" : {
                    required: 'Please enter a valid IMEI Number',
                    minlength: 'Please enter a valid IMEI Number',
                    imei: 'Please enter a valid IMEI Number'

                },
                "form_upgrade_imei[serialNumber]" : {
                    required: 'Please enter a valid serial number',
                    // alphanumeric: 'Please enter a valid serial number'
                }
            },

            // Error Reporting
            showErrors: function(errorMap, errorList) {
                this.defaultShowErrors();
                let vals = [];
                for (let err in errorMap) {
                    let val = $('body').find('input[name="' + err + '"]').val()
                    vals.push({'name': err, 'value': val, 'message': errorMap[err]});
                }
                $.ajax({
                  method: "POST",
                  url: "/ops/validation",
                  contentType:"application/json; charset=utf-8",
                  dataType:"json",
                  data: JSON.stringify({ 'errors': vals, 'url': self.url })
                });
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

    let imei  = $('.imei'),
        phone = imei.data('make');

    imei.change(function(event) {
        $(this).val($(this).val().replace(/\s/g,''));
    });

});
