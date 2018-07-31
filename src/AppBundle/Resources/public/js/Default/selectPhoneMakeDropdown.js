$(function() {

    // Check for the dropdown
    if ($('.dropdown-phone-form').length) {

        // Elements & Data
        var phones     = $('#select-phone-data').data('phones');
        var make       = $('.select-phone-make');
        var model      = $('.select-phone-model');
        var memory     = $('.select-phone-memory');
        var controls   = $('#launch_phone_next');

        // Update Models Select
        var updateModels = function() {

            // Clear incase model change
            model.empty();
            memory.empty();

            // If make selected customise default value
            if (make.val()) {
                model.append($('<option />').val('').text('Now select your ' + make.val() + ' device'));
            } else {
                model.append($('<option />').val('').text('Find your phone for an instant quote...'));
            }

            // Clear the other select out
            memory.append($('<option />').val('').text('Select your phone model first'));

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

        // Update Memory Select
        var updateMemory = function() {

            // Clear incase model change
            memory.empty();

            // If model selected customise default value
            if (model) {
                memory.append($('<option />').val('').text('Which memory size ' + model.val() + '?'));
            } else {
                memory.append($('<option />').val('').text('Select your phone model first'));
            }

            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });

        }

        // When user selects make > update
        $('.select-phone-make').on('change', function() {

            // As model will be shown anyway
            memory.hide();
            controls.hide();

            $('.select-phone-make').addClass('.select-phone-make-focus');

            // Update Models
            updateModels();

            if ($(this).val() != '') {
                model.show();
            } else {
                model.hide();
                memory.hide();
            }

        });

        // When user selects model > update
        $('.select-phone-model').on('change', function() {

            var value = $(this).val();

            // Update Memory
            updateMemory();

            if (value != '') {
                memory.show();
            } else {
                make.show();
                memory.hide();
            }
        });

        // When user selects memory > update
        $('.select-phone-memory').on('change', function() {

            if ($(this).val() != '') {
                controls.show();
            } else {
                controls.hide();
            }

        });

        // Update the models on load
        updateModels();
    }

});
