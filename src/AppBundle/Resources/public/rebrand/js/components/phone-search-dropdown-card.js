// phone-search-dropdown-card.js

require('jquery-validation');
require('../common/validation-methods.js');

$(function() {

    if ($('.phone-search-dropdown-card').length) {

        let validateForm = $('.validate-form'),
            isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
            form    = $('.phone-search-dropdown-card'),
            phones  = $('.phone-search-dropdown-card').data('phones'),
            make    = $('.phone-search-option-make'),
            model   = $('.phone-search-option-model'),
            memory  = $('.phone-search-option-memory'),
            email   = $('.phone-search-email'),
            button  = $('.phone-search-button'),
            firstOp = $('.phone-search-option-make option:first');

        const addValidationEmail = () => {
            validateForm.validate({
                // When to validate
                validClass: 'is-valid-ss',
                errorClass: 'is-invalid',
                // onfocusout: false,
                onkeyup: false,
                onclick: false,
                rules: {
                    "launch_phone[email]": {
                        required: false,
                        email: true,
                        emaildomain: true
                    },
                },
                messages: {
                    "launch_phone[email]": {
                        required: 'Please enter your email address',
                        email: 'Please enter a valid email address',
                        emaildomain: 'Please enter a valid email address',
                    },
                },
                submitHandler: function(form) {
                    form.submit();
                },
            });
        }

        if (validateForm.data('client-validation') && !isIE) {
            addValidationEmail();
        }

        const updateModels = () => {

            // Clear incase model change
            model.empty().prop('disabled', 'disabled');
            memory.empty().prop('disabled', 'disabled');

            // Set the default value
            model.append($('<option />').val('').text('Select Model'));
            memory.append($('<option />').val('').text('Select Memory Size'));

            // Get phones from list and show featured
            $.each(phones[make.val()], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        model.append($('<option />').val(key).text(key));
                    }
                });
            });

            // Get phones from list and show the rest
            $.each(phones[make.val()], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (!mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        model.append($('<option />').val(key).text(key));
                    }
                });
            });
        }

        const updateMemory = () => {

            // Clear incase model change
            memory.empty();

            // Set the default value
            memory.append($('<option />').val('').text('Select Memory Size'));


            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });
        }

        // On load
        updateModels();

        // Change text for now
        // TODO If wins change FormType
        firstOp.text('Select Make');

        // Make form visible - hides above
        form.css('visibility', 'visible');

        // On Make change
        make.on('change', function(e) {

            updateModels();

            // Enable/disable model
            if ($(this).val()) {
                model.prop('disabled', '');
            } else {
                model.prop('disabled', 'disabled').val('');
            }

            // Adjust the button
            button.prop('disabled', 'disabled')
            .removeClass('btn-success')
            .addClass('btn-secondary');
        });

        // On Model change
        model.on('change', function(e) {

            updateMemory();

            // Enable/disable model
            if ($(this).val()) {
                memory.prop('disabled', '');
            } else {
                memory.prop('disabled', 'disabled').val('');
            }

            // Adjust the button
            button.prop('disabled', 'disabled')
            .removeClass('btn-success')
            .addClass('btn-secondary');
        });

        // On Memory change
        memory.on('change', function(e) {

            // Enable/disable model
            if ($(this).val()) {
                email.prop('disabled', '');
                button.prop('disabled', '')
                      .removeClass('btn-secondary')
                      .addClass('btn-success');

                email.focus();
            } else {
                email.prop('disabled', 'disabled');
                button.prop('disabled', 'disabled')
                .removeClass('btn-success')
                .addClass('btn-secondary');
            }
        });
    }

});
