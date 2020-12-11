// phone-search-combined.js

require('jquery-validation');
require('../common/validation-methods.js');
let MobileDetect = require('mobile-detect');

if ($('.phone-search-combined').length) {

    // Vars
    const validateForm = $('.validate-form'),
          isIE   = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
          form   = $('.phone-search-combined'),
          phones = form.data('phones'),
          make   = $('.phone-search-make'),
          model  = $('.phone-search-model'),
          email  = $('.phone-search-email'),
          button = $('.phone-search-button'),
          first  = $('.phone-search-make option:first');

    // Phone detection using mobile-detect
    let mobileDetected = new MobileDetect(window.navigator.userAgent),
        makeDetected = mobileDetected.phone();

    let phonesToMatch = $.map(phones, function(key, make) {
        return [make];
    });

    let makeIs = makeDetected;

    const addValidationEmail = () => {
        validateForm.validate({
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            rules: {
                "launch_phone[make]": {
                    required: true
                },
                "launch_phone[model]": {
                    required: true
                },
                "launch_phone[email]": {
                    email: true,
                    emaildomain: true
                },
            },
            messages: {
                "launch_phone[make]": {
                    required: ''
                },
                "launch_phone[model]": {
                    required: ''
                },
                "launch_phone[email]": {
                    required: '',
                    email: '',
                    emaildomain: '',
                },
            },
            submitHandler: function(form) {
                form.submit();
            },
        });
    }

    // Check if validation client side and browser is not old IE and add
    if (validateForm.data('client-validation') && !isIE) {
        addValidationEmail();
    }

    const updateModels = () => {
        // Clear incase model change
        model.empty().prop('disabled', 'disabled');
        // Set the default value
        model.append($('<option />').val('').text('Select Model'));
        // Get phones from list that are featured
        $.each(phones[make.val()], function(key, value) {
            $.each(value, function(item, id) {
                if (!model.find('option[value="' + key +'"]').length) {
                    model.append($('<option />').val(item).text(key + ' ' + id['memory'] + 'GB'));
                }
            });
        });
        // Get phones from list that are not featured
        // $.each(phones[make.val()], function(key, value) {
        //     $.each(value, function(item, id) {
        //         if (!id['featured'] && !model.find('option[value="' + key +'"]').length) {
        //             model.append($('<option />').val(item).text(key + ' ' + id['memory'] + 'GB'));
        //         }
        //     });
        // });
    }

    // Dev
    // console.log(phones);

    // On 'Page Load' run update models
    updateModels();
    // Set the first option to select make if not defined
    first.text('Select Make');
    // Show the form now everything has loaded
    form.css('visibility', 'visible');
    // Mobile detection
    // Check match and set if found using mobile-detect
    if (makeIs == 'iPhone') {
        makeIs = 'Apple';
    }

    if (phonesToMatch.includes(makeIs) && !make.val()) {
        make.val(makeIs);
        updateModels();
        model.prop('disabled', '');
    }

    make.on('change', function(e) {
        updateModels();
        if ($(this).val()) {
            model.prop('disabled', '');
            model.focus();
        } else {
            model.prop('disabled', 'disabled').val('');
            email.prop('disabled', 'disabled').val('');
        }
    })

    model.on('change', function(e) {
        if ($(this).val()) {
            email.prop('disabled', '');
            email.focus();
        } else {
            email.prop('disabled', 'disabled');
        }
    })
}