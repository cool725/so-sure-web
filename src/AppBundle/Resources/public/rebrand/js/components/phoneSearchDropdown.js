// phoneSearchDropdown.js

$(function() {

    if ($('.phone-search-dropdown').length) {

        // Elements & Date
        const phones = $('.phone-search-dropdown').data('phones'),
              make   = $('.phone-search-dropdown__make'),
              model  = $('.phone-search-dropdown__model'),
              memory = $('.phone-search-dropdown__memory');
              button = $('.phone-search-dropdown__button');

        let updateModels = () => {

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

        let updateMemory = () => {

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

        make.on('change', function() {

            updateModels();

            if ($(this).val() != '') {
                model.prop('disabled', '');
                $(this).addClass('valid-select');
            } else {
                model.prop('disabled', 'disabled').val('');
                $(this).removeClass('valid-select');
            }

            button.prop('disabled', 'disabled').removeClass('btn-success btn-gradient-green btn-shadow').addClass('btn-outline-white');

            model.resizeselect();
        });

        model.on('change', function() {

            updateMemory();

            if ($(this).val() != '') {
                memory.prop('disabled', '');
                $(this).addClass('valid-select');
            } else {
                memory.prop('disabled', 'disabled').val('');
                $(this).removeClass('valid-select');
            }

            // button.prop('disabled', 'disabled').removeClass('btn-success btn-shadow').addClass('btn-outline-white');
            memory.resizeselect();

        });

        memory.on('change', function() {

            if ($(this).val() != '') {
                $(this).addClass('valid-select');
                button.prop('disabled', '').removeClass('btn-outline-white').addClass('btn-success btn-shadow btn-gradient-green');
            } else {
                button.prop('disabled', 'disabled').removeClass('btn-success btn-gradient-green btn-shadow').addClass('btn-outline-white');
                $(this).removeClass('valid-select');
            }
        });

        updateModels();

        model.resizeselect();

    }
});
