// company-phones.js

require('../../sass/pages/company-phones.scss');

// Require BS component(s)
// require('bootstrap/js/dist/carousel');

// Require components
// let textFit = require('textfit');
require('jquery-validation');
require('../common/validationMethods.js');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    // Use textfit plugin for h1 tag
    // textFit($('.fit')[0], {detectMultiLine: true});

    // Scroll to feedback section
    $('.get-in-touch').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: $('#get_in_touch').offset().top
        }, 500);
    });

    let validateForm = $('.validate-form');

    const addValidation = () => {
        validateForm.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            rules: {
                "lead_form[name]": {
                    required: true,
                },
                "lead_form[company]": {
                    required: true,
                },
                "lead_form[phone]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneIntl: true
                },
                "lead_form[email]": {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                "lead_form[phones]": {
                    required: true,
                    isOverTen: true
                },
                "lead_form[timeframe]": {
                    required: true,
                },
                "lead_form[message]": {
                    required: true,
                },
                recaptcha_response_field: {
                    required: true,
                    checkCaptcha: true
                },
            },
            messages: {
                "lead_form[name]": {
                    required: 'Please enter your full name',
                    fullName: 'Please enter your first and last name'
                },
                "lead_form[company]": {
                    required: 'Please enter your company name'
                },
                "lead_form[phone]": {
                    required: 'Please enter your phone number'
                },
                "lead_form[email]": {
                    required: 'Please enter a valid email address'
                },
                "lead_form[phones]": {
                    required: 'Please select no. of phones to insure'
                },
                "lead_form[timeframe]": {
                    required: 'Please enter when you wish to purchase'
                },
                "lead_form[message]": {
                    required: 'Please enter your message'
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
});
