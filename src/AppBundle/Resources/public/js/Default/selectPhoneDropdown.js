$(function() {

    if ($('.dropdown-phone-form--homepage').length) {

        // Elements & Data
        var phones = $('#dropdown-search').data('phones'),
            make   = $('.dropdown-phone-form--homepage_make'),
            model  = $('.dropdown-phone-form--homepage_model'),
            memory = $('.dropdown-phone-form--homepage_memory');

        var updateModels = function() {

            // Clear incase model change
            model.empty();
            memory.empty().prop('disabled', 'disabled');

            // Set the default value
            model.append($('<option />').val('').text('Model'));
            memory.append($('<option />').val('').text('Memory'));

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

        var updateMemory = function() {

            // Clear incase model change
            memory.empty();

            // Set the default value
            memory.append($('<option />').val('').text('Memory'));

            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });

        }

        // When user selects make > update
        make.on('change', function() {

            // Update Models
            updateModels();

            if ($(this).val() != '') {
                model.prop('disabled', '');
            } else {
                model.prop('disabled', 'disabled').val('');
            }

        });

        model.on('change', function() {

            // Update Memory
            updateMemory();

            if ($(this).val() != '') {
                memory.prop('disabled', '');
            } else {
                memory.prop('disabled', 'disabled').val('');
            }
        });

        updateModels();
    }

});
