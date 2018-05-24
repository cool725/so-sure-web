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
        var controls   = $('#launch_phone_next');

        // Update Models Select
        var updateModels = function() {

            // Clear incase model change
            model.empty();
            memory.empty();

            // If make selected customise default value
            if (make.val()) {
                model.append($('<option />').val('').text('Now select your ' + make.val() + ' device'));
                // phoneName.text(make.val() + '...');
            } else {
                model.append($('<option />').val('').text('Select your phone make first'));
                // phoneName.text();
            }

            // Clear the other select out
            memory.append($('<option />').val('').text('Select your phone model first'));


            // Get phones from list and show featured
            $.each(phones[make.val()], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        if (memOptTest == true) {
                            model.append($('<option />').val(key).text(make.val() + ' ' + key));
                        } else {
                            model.append($('<option />').val(key).text(key));
                        }
                    }
                });
            });

            // Get phones from list and show the rest
            $.each(phones[make.val()], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (!mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        if (memOptTest == true) {
                            model.append($('<option />').val(key).text(make.val() + ' ' + key));
                        } else {
                            model.append($('<option />').val(key).text(key));
                        }
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
                // phoneName.text(make.val() + ' ' + model.val() + '...');
            } else {
                memory.append($('<option />').val('').text('Select your phone model first'));
                // phoneName.text(make.val());
            }

            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                if (memOptTest == true) {
                    memory.append($('<option />').val(key).text(make.val() + ' ' + model.val() + ' ' + value['memory'] + 'GB'));
                } else {
                    memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
                }
            });

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
                if (memOptTest == true) {
                    $(this).hide();
                }
            } else {
                model.hide();
                memory.hide();
                if (memOptTest == true) {

                }
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
                if (value != '') {
                    memory.show();
                    if (memOptTest == true) {
                        $(this).hide();
                    }
                } else {
                    make.show();
                    memory.hide();
                    if (memOptTest == true) {
                        $(this).hide();
                    }
                }
                // If more than one memory size
                // if (memOpt > 1) {
                //     if (value != '') {
                //         memory.show();
                //     } else {
                //         make.show();
                //         memory.hide();
                //     }
                // } else {
                //     // If only one memory option
                //     if (value != '') {
                //         memory.hide().val(memSel);
                //         controls.show();
                //         // $(this).val($(this).val() + );
                //         // phoneName.text(make.val() + ' ' + model.val() + ' ' + memory.find('option:selected').text());
                //     } else {
                //         make.show();
                //         memory.hide();
                //         // phoneName.text(make.val() + ' ' + model.val() + '...');
                //     }
                // }
            } else {
                if (value != '') {
                    memory.show();
                } else {
                    make.show();
                    memory.hide();
                }
            }
        });

        // When user selects memory > update
        $('.select-phone-memory').on('change', function() {

            if ($(this).val() != '') {
                controls.show();
                // phoneName.text(make.val() + ' ' + model.val() + ' ' + memory.find('option:selected').text());
            } else {
                controls.hide();
                if (memOptTest == true) {
                    $(this).hide();
                    model.show();
                }
                // phoneName.text(make.val() + ' ' + model.val() + '...');
            }

        });

        // Update the models on load
        updateModels();
    }

});
