$(function(){

    if ($('.dropdown-phone-form').length) {

        // Phone data
        var phones = $('#select-phone-data').data('phones');

        // Update the select
        var updateModels = function() {
            // Make value
            var make = $('.select-phone-make').val();
            var model = $('.select-phone-model');
            var memory = $('.select-phone-memory');
            var phone = $('#phone');

            // Clear incase make changed
            model.empty();
            memory.empty();

            // If make selected customise default value
            if (make) {
                model.append($('<option />').val('').text('Now select your ' + make + ' device'));
            } else {
                model.append($('<option />').val('').text('Select your phone make first'));
            }

            memory.append($('<option />').val('').text('Select your phone model first'));

            // Get phones from list and add to options
            $.each(phones[make], function(key, value) {
                model.append($('<option />').val(key).text(key));
            });

            // Update text
            // phone.text(make);
        }

        var updateMemory = function() {
            // Make value
            var make = $('.select-phone-make').val();
            var model = $('.select-phone-model').val();
            var memory = $('.select-phone-memory');
            var phone = $('#phone');

            // Clear incase model changed
            memory.empty();

            // If model selected customise default value
            if (model) {
                memory.append($('<option />').val('').text('And finally select your memory size'));
            } else {
                memory.append($('<option />').val('').text('Select your phone model first'));
            }

            // Get phones from list and add to options
            $.each(phones[make][model], function(key, value) {
                memory.append($('<option />').val(key).text(value + ' GB'));
            });

            // Update text
            // phone.text(make + ' > ' + model);
        }

        var updateFinal = function() {
            // Make value
            var make = $('.select-phone-make').val();
            var model = $('.select-phone-model').val();
            var memory = $('.select-phone-memory').val();
            var phone = $('#phone');

            // Update text
            // phone.text(make + ' > ' + model + ' > ' + memory);
        }

        var checkForm = function() {

            // All values
            var make = $('.select-phone-make').val();
            var model = $('.select-phone-model').val();
            var memory = $('.select-phone-memory').val();
            var phone = $('#phone');

            if (make != '') {
                // $('.select-phone-make').hide();
                $('.select-phone-model').show();
            } else {
                // $('.select-phone-make').show();
                $('.select-phone-model').hide();
            }
            if (model != '') {
                // $('.select-phone-model').hide();
                $('.select-phone-memory').show();
            } else {
                // $('.select-phone-model').show();
                $('.select-phone-memory').hide();
            }
            if (memory != '') {
                $('#launch_phone_next').show();
            } else {
                $('#launch_phone_next').hide()
            }
        }

        // When user selects option update results
        $('.select-phone-make').on('change', function(e) {
            updateModels();
            checkForm();
        });

        // When user selects option update results
        $('.select-phone-model').on('change', function(e) {
            updateMemory();
            checkForm();
        });

        // When user selects option update results
        $('.select-phone-memory').on('change', function(e) {
            updateFinal();
            checkForm();
        });

        updateModels();

    }
});
