$(function(){

    // Phone data
    var phones = $('#select-phone-data').data('phones');

    console.log(phones);

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
            model.append($('<option />').val('').text('Select your ' + make + ' device'));
        } else {
            model.append($('<option />').val('').text('Select your phone make first'));
        }
        memory.append($('<option />').val('').text('Select your phone model first'));

        // Get phones from list and add to options
        $.each(phones[make], function(key, value) {
            model.append($('<option />').val(key).text(key));
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
            memory.append($('<option />').val('').text('Select your ' + model + ' memory'));
        } else {
            memory.append($('<option />').val('').text('Select your phone model first'));
        }

        // Get phones from list and add to options
        $.each(phones[make][model], function(key, value) {
            memory.append($('<option />').val(key).text(value));
        });
    }

    // When user selects option update results
    $('.select-phone-make').on('change', function(e) {
        // if ($(this).val() != '') {
        //     $('.select-phones').show();
        // } else {
        //     $('.select-phones').hide();
        // }
        updateModels();
    });

    // When user selects option update results
    $('.select-phone-model').on('change', function(e) {
        updateMemory();
    });

    updateModels();
});
