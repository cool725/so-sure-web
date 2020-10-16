// phone-search-dropdown.js
require('./select.js');
let MobileDetect = require('mobile-detect');

$(function() {

    if ($('.phone-search-dropdown').length) {

        // Elements & Date
        let phones = $('.phone-search-dropdown').data('phones'),
            make   = $('.phone-search-dropdown__make'),
            model  = $('.phone-search-dropdown__model'),
            memory = $('.phone-search-dropdown__memory'),
            button = $('.phone-search-dropdown__button'),
            quote  = $('.get-a-quote'),
            arule  = $('#a-rule');

        // Phone detection using mobile-detect
        let mobileDetected = new MobileDetect(window.navigator.userAgent),
            makeDetected = mobileDetected.phone();

        let phonesToMatch = $.map(phones, function(key, make) {
            return [make];
        });

        let makeIs = makeDetected;

        const updateModels = () => {

            // Clear incase model change
            model.empty().prop('disabled', 'disabled');
            memory.empty().prop('disabled', 'disabled');

            // Set the default value
            model.append($('<option />').val('').text('model'));
            memory.append($('<option />').val('').text('GB size'));

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
            memory.append($('<option />').val('').text('GB size'));


            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });

            // If only one option for size auto select
            // Note: The placeholder value means a length of 2
            if (memory.find('option').length == 2) {
                memory.find('option:eq(1)').prop('selected', true).resizeselect();
                button.prop('disabled', '').removeClass('btn-outline-white').addClass('btn-success btn-shadow');
            }
        }

        quote.removeClass('disabled');

        updateModels();

        // Check match and set if found using mobile-detect
        if (makeIs == 'iPhone') {
            makeIs = 'Apple';
        }

        if (phonesToMatch.includes(makeIs) && !make.val()) {
            make.val(makeIs);
            updateModels();
            model.prop('disabled', '');
        }

        make.resizeselect();

        make.on('change', function() {

            updateModels();

            if ($(this).val()) {
                model.prop('disabled', '');
                $(this).addClass('valid-select');

                let value = $(this).val();

                if (value.charAt(0).match(/[AEIOU]/)) {
                    arule.text('an')
                } else {
                    arule.text('a')
                }

            } else {
                model.prop('disabled', 'disabled').val('');
                $(this).removeClass('valid-select');
            }

            button.prop('disabled', 'disabled')
            .removeClass('btn-success btn-green-gradient')
            .addClass('btn-outline-white');

            model.resizeselect();

        });

        model.on('change', function() {

            updateMemory();

            if ($(this).val()) {
                memory.prop('disabled', '');
                $(this).addClass('valid-select');
            } else {
                memory.prop('disabled', 'disabled').val('');
                $(this).removeClass('valid-select');
            }

            memory.resizeselect();

        });

        memory.on('change', function() {

            if ($(this).val()) {
                $(this).addClass('valid-select');
                button.prop('disabled', '')
                .removeClass('btn-outline-white')
                .addClass('btn-success btn-green-gradient');
            } else {
                $(this).removeClass('valid-select');
                button.prop('disabled', 'disabled')
                .removeClass('btn-success btn-green-gradient')
                .addClass('btn-outline-white');
            }

        });
    }
});
