// memory-search-dropdown.js

$(function() {

    if ($('.memory-search-dropdown').length) {

        // Elements & Date
        let phones  = $('.memory-search-dropdown').data('phones'),
            make    = $('.memory-search-dropdown__make'),
            model   = $('.memory-search-dropdown__model'),
            device  = model.data('model'),
            memory  = $('.memory-search-dropdown__memory'),
            button  = $('.memory-search-dropdown__button'),
            sizeTxt = $('#size_rule');

        const updateModels = () => {

            // Clear incase model change
            model.empty();
            model.append($('<option />').val(device).text(device));
            memory.empty();
            memory.append($('<option />').val('').text('GB size'));

        }

        const updateMemory = () => {

            // Clear incase model change
            memory.empty();

            // Set the default value
            memory.append($('<option />').val('').text('Select GB'));


            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });

            // If only one option for size auto select
            // Note: The placeholder value means a length of 2
            // if (memory.find('option').length == 2)
            // memory.find('option:eq(1)').prop('selected', true).resizeselect();
            // button.prop('disabled', '').removeClass('btn-outline-white').addClass('btn-success btn-shadow').addClass('animated heartBeat');
            // sizeTxt.toggleText('for exact price', 'get covered today');
            memory.resizeselect();
        }

        updateModels();
        updateMemory();

        make.resizeselect();
        model.resizeselect();

        memory.on('change', function() {

            if ($(this).val()) {
                $(this).addClass('valid-select');
                // sizeTxt.text('get covered 👇');
                button.prop('disabled', '').addClass('animated heartBeat');
            } else {
                $(this).removeClass('valid-select');
                // sizeTxt.text('to get covered');
                button.prop('disabled', 'disabled').removeClass('animated heartBeat');;
            }

        });

    }
});
