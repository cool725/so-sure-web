// login.js

require('../../sass/pages/login.scss');

// Require BS component(s)

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
require('../common/toggle-text.js');


$(function() {

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
        swapLogin = $('#swap_login'),
        formEmail = $('#login_email_form'),
        formSMS = $('#login_sms_form'),
        subSMS = $('#sms_login_btn'),
        errorSMS = $('#sms_error'),
        formCode = $('#verify_code_form'),
        codeVerify = $('#verify_code'),
        subVefify = $('#verify_code_btn');

    const addValidationEmail = () => {
        formEmail.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
            rules: {
                _username: {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    email: true,
                    emaildomain: true
                },
                _password: {
                    required: true
                }
            },
            messages: {
                _username: {
                    required: 'Please enter your email address',
                    email: 'Please enter a valid email address',
                    emaildomain: 'Please enter a valid email address',
                },
                _password: {
                    required: 'Please enter your password'
                }
            },

            submitHandler: function(form) {
                form.submit();
            },
        });
    }

    const addValidationSMS = () => {
        formSMS.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
            rules: {
                phone_number: {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    phoneUK: true,
                }
            },
            messages: {
                phone_number: {
                    required: 'Please enter your phone number',
                    phoneUK: 'Please enter a valid phone number, UK numbers only!'
                }
            },

            submitHandler: function(form) {
                subSMS.prop('disabled', 'disabled')

                let data = {
                    mobileNumber: $('#phone_number').val(),
                    csrf: formSMS.data('token')
                },
                url = formSMS.data('url');

                $.ajax({
                    url: url,
                    type: 'POST',
                    data: JSON.stringify(data),
                    contentType: "application/json; charset=utf-8",
                    dataType: "json"
                })
                .done(function(data) {
                    // Show modal to enter verify code
                    $('#sms_code_modal').modal({
                        backdrop: 'static',
                        keyboard: false,
                        show: true
                    });
                })
                .fail(function(data) {
                    errorSMS.text(data.responseJSON.description);
                    subSMS.prop('disabled', '');
                });
            },
        });
    }

    const addValidationCode = () => {
        formCode.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            // onfocusout: false,
            onkeyup: false,
            onclick: false,
            rules: {
                code: {
                    required: {
                        depends:function(){
                            $(this).val($.trim($(this).val()));
                            return true;
                        }
                    },
                    maxlength: 6,
                    minlength: 6
                }
            },
            messages: {
                code: {
                    required: 'Please enter the 6 digit verification code',
                    maxlength: 'Please enter the 6 digit verification code',
                    minlength: 'Please enter the 6 digit verification code'
                }
            },

            submitHandler: function(form) {
                form.submit();
            },
        });
    }

    // Add validation on load
    if (validateForm.data('client-validation') && !isIE) {
        addValidationEmail();
        addValidationSMS();
    }

    // Swap Login
    swapLogin.on('click', function(e) {
        e.preventDefault();

        // Toggle Forms
        formEmail.toggle();
        formSMS.toggle();

        // Toggle Text Swap Button
        swapLogin.find('span').toggleText('Mobile Number', 'Email');
    });

    // On show populate phone number and add validation
    $('#sms_code_modal').on('show.bs.modal', function (e) {
        let modal = $(this);
        modal.find('#mobile_number').val($('#phone_number').val());
        addValidationCode();
    });
});
