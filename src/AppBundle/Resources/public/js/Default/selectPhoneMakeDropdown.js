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

            // Clear incase make changed
            model.empty();
            memory.empty();

            // If make selected customise default value
            if (make) {
                model.append($('<option />').val('').text('Now select your ' + make + ' device'));
            } else {
                model.append($('<option />').val('').text('Select your phone make first'));
            }

            // Clear the other select out
            memory.append($('<option />').val('').text('Select your phone model first'));

            // TODO: Add Title - Top Models
            // model.append($('<option disabled />').val('').text('Top Models'));

            // Get phones from list and show featured
            $.each(phones[make], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        model.append($('<option />').val(key).text(key));
                    }
                });
            });

            // TODO: Add Title - Models
            // model.append($('<option disabled />').val('').text('Models'));

            // Get phones from list and show the rest
            $.each(phones[make], function(key, value) {
                $.each(value, function(key2, mod) {
                    if (!mod['featured'] && !model.find('option[value="' + key +'"]').length) {
                        model.append($('<option />').val(key).text(key));
                    }
                });
            });
        }

        var updateMemory = function() {
            // Make value
            var make = $('.select-phone-make').val();
            var model = $('.select-phone-model').val();
            var memory = $('.select-phone-memory');

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
                memory.append($('<option />').val(key).text(value['memory'] + ' GB'));
            });
        }

        var resetAll = function() {

            // Make value
            var make = $('.select-phone-make');
            var model = $('.select-phone-model');
            var memory = $('.select-phone-memory');

            // make.empty();
            model.empty().hide();
            memory.empty().hide();
            $('.select-phone-memory').hide();
            $('#close-sticky').hide();

            updateModels();
        }

        // When user selects option update results
        $('.select-phone-make').on('change', function(e) {

            // Update Models
            updateModels();

            if ($(this).val() != '') {
                // $(this).hide();
                $('.select-phone-model').show();
            } else {
                // $(this).show();
                $('.select-phone-model').hide();
            }
        });

        // When user selects option update results
        $('.select-phone-model').on('change', function(e) {

            // Update Memory
            updateMemory();

            if ($(this).val() != '') {
                // $(this).hide();
                $('.select-phone-memory').show();
            } else {
                // $(this).show();
                $('.select-phone-memory').hide();
            }
        });

        // When user selects option update results
        $('.select-phone-memory').on('change', function(e) {

            // TODO: Memory options if one dont show
            // var memOpt = $(this).children('option').not('[value=""]').size();
            // console.log(memOpt);

            if ($(this).val() != '') {
                $('.select-phone-btn').show();
                $('#close-sticky').css('display', 'block');
            } else {
                $('.select-phone-btn').hide()
            }
        });

        updateModels();

        $('#close-sticky').click(function(e) {
            e.preventDefault();
            resetAll();
        });
    }
});
