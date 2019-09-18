// phone-search-dropdown-card.js

$(function() {

    if ($('.phone-search-dropdown-card').length) {

        // Elements
        let form    = $('.phone-search-dropdown-card'),
            phones  = $('.phone-search-dropdown-card').data('phones'),
            make    = $('.phone-search-option-make'),
            model   = $('.phone-search-option-model'),
            memory  = $('.phone-search-option-memory'),
            button  = $('.phone-search-button'),
            firstOp = $('.phone-search-option-make option:first');


        const updateModels = () => {

            // Clear incase model change
            model.empty().prop('disabled', 'disabled');
            memory.empty().prop('disabled', 'disabled');

            // Set the default value
            model.append($('<option />').val('').text('Select Model'));
            memory.append($('<option />').val('').text('Select Memory Size'));

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
            memory.append($('<option />').val('').text('Select Memory Size'));


            // Get phones from list and add to options
            $.each(phones[make.val()][model.val()], function(key, value) {
                memory.append($('<option />').val(key).text(value['memory'] + 'GB'));
            });

            // If only one option for size auto select
            // Note: The placeholder value means a length of 2
            // if (memory.find('option').length == 2) {
            //     memory.find('option:eq(1)').prop('selected', true);
            //     button.prop('disabled', '')
            //           .removeClass('btn-secondary')
            //           .addClass('btn-success');

            //     button.focus();
            // }
        }


        // On load
        updateModels();

        // Change text for now
        // TODO If wins change FormType
        firstOp.text('Select Make');

        // Make form visible - hides above
        form.css('visibility', 'visible');

        // On Make change
        make.on('change', function(e) {

            updateModels();

            // Enable/disable model
            if ($(this).val()) {
                model.prop('disabled', '');
            } else {
                model.prop('disabled', 'disabled').val('');
            }

            // Adjust the button
            button.prop('disabled', 'disabled')
            .removeClass('btn-success')
            .addClass('btn-secondary');
        });

        // On Model change
        model.on('change', function(e) {

            updateMemory();

            // Enable/disable model
            if ($(this).val()) {
                memory.prop('disabled', '');
            } else {
                memory.prop('disabled', 'disabled').val('');
            }

            // Adjust the button
            button.prop('disabled', 'disabled')
            .removeClass('btn-success')
            .addClass('btn-secondary');
        });

        // On Memory change
        memory.on('change', function(e) {

            // Enable/disable model
            if ($(this).val()) {
                button.prop('disabled', '')
                      .removeClass('btn-secondary')
                      .addClass('btn-success');

                button.focus();
            } else {
                button.prop('disabled', 'disabled')
                .removeClass('btn-success')
                .addClass('btn-secondary');
            }
        });

    }

});
