$('.confirmModal').on('show.bs.modal', function (event) {
    var modal = event.currentTarget;
    var checkboxes = $(modal).find('input[type="checkbox"][required]');
    var cases = $(modal).find('.cases a');

    // Updates for upgrade
    if ($('.modal-upgrade').length) {
        var newBinder = $('#new_binder'),
            oldBinderPrice = $('#old_binder').data('old-binder'),
            phones = $('#imei_form_phone'),
            validUpgrade = $('#valid_upgrade'),
            newBinderPrice = $('#imei_form_phone option:selected').data('binder'),
            limitDiff = 2,
            binderDiff;

        newBinder.text(newBinderPrice);

        // Set value on valid - default for normal imei update
        validUpgrade.text('✅');
        // Disable as not using the checkboxes
        $(modal).find('button[type="submit"]').attr('disabled', false);

        phones.on('change', function(e) {
            // Set price
            newBinderPriceUpdate = $('#imei_form_phone option:selected').data('binder');
            newBinder.text(newBinderPriceUpdate);
            binderDiff = newBinderPriceUpdate - oldBinderPrice;

            if (binderDiff < limitDiff) {
                validUpgrade.text('✅');
                $(modal).find('button[type="submit"]').attr('disabled', false);
            } else {
                validUpgrade.text('⛔️ Requires old upgrade method');
                $(modal).find('button[type="submit"]').attr('disabled', true);
            }
        });
    }

    checkboxes.on('input', function (e) {
        if ($(modal).find('input[type="checkbox"][required]:checked').length === checkboxes.length) {
            $(modal).find('button[type="submit"]').attr('disabled', false);
        } else {
            $(modal).find('button[type="submit"]').attr('disabled', true);
        }
    });

    cases.on('click', function (e) {
        $(modal).find('.form_note').val(this.innerText);
        // todo: get this working
        //$(modal).find('.cases-common').check();
    });
});
