$(function() {

    // Check for the dropdown
    if ($('.dropdown-phone-form').length) {

        // Elements & Data
        var phone      = $('#phone');
        var phoneName  = $('#phone-name');
        var phones     = $('#select-phone-data').data('phones');
        var memOptTest = $('#select-phone-data').data('show-single-mem-opt');
        var make       = $('.select-phone-make');
        var model      = $('.select-phone-model');
        var memory     = $('.select-phone-memory');
        var controls   = $('#quote-controls');
        var resetIt    = $('#reset-sticky');

        // Update Models Select
        var updateModels = function() {

            // Clear incase model change
            model.empty();
            memory.empty();

            // If make selected customise default value
            if (make.val()) {
                model.append($('<option />').val('').text('Now select your ' + make.val() + ' device'));
                phoneName.text(make.val() + '...');
            } else {
                model.append($('<option />').val('').text('Select your phone make first'));
                phoneName.text();
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
                memory.append($('<option />').val('').text('And finally select your memory size'));
                phoneName.text(make.val() + ' ' + model.val() + '...');
            } else {
                memory.append($('<option />').val('').text('Select your phone model first'));
                phoneName.text(make.val());
            }

            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + ' GB'));
            });

        }

        // Reset All
        var resetAll = function() {

            make.val('');
            model.empty().hide();
            memory.empty().hide();
            controls.hide();
            resetIt.hide();

            updateModels();

        }

        // When user selects make > update
        $('.select-phone-make').on('change', function() {

            // As model will be shown anyway
            memory.hide();
            controls.hide();

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

            // Update Memory
            updateMemory();

            var value = $(this).val();

            // Get memory options
            var memSel = memory.children('option').not('[value=""]').val();
            var memOpt = memory.children('option').not('[value=""]').size();

            // Check for memory test otherwise revert
            if (memOptTest == true) {
                // If more than one memory size
                if (memOpt > 1) {
                    if (value != '') {
                        memory.show();
                    } else {
                        memory.hide();
                    }
                } else {
                    if (value != '') {
                        memory.hide().val(memSel);
                        controls.show();
                        phoneName.text(make.val() + ' ' + model.val() + ' ' + memory.find('option:selected').text());
                    } else {
                        memory.hide();
                        phoneName.text(make.val() + ' ' + model.val() + '...');
                    }
                }
            } else {
                if (value != '') {
                    memory.show();
                } else {
                    memory.hide();
                }
            }
        });

        // When user selects memory > update
        $('.select-phone-memory').on('change', function() {

            if ($(this).val() != '') {
                controls.show();
                phoneName.text(make.val() + ' ' + model.val() + ' ' + memory.find('option:selected').text());
            } else {
                controls.hide();
                phoneName.text(make.val() + ' ' + model.val() + '...');
            }

        });

        // Update the models on load
        updateModels();
    }

});
