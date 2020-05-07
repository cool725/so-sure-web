$('.confirmModal').on('show.bs.modal', function (event) {
    var modal = event.currentTarget;
    var checkboxes = $(modal).find('input[type="checkbox"][required]');
    var cases = $(modal).find('.cases a');

    // Updates for upgrade
    if ($('.modal-upgrade').length) {
        var newPremium = $('#new_premium'),
            oldPremiumPrice = $('#old_premium').data('old-premium'),
            phones = $('#imei_form_phone'),
            validUpgrade = $('#valid_upgrade'),
            newPremiumPrice = $('#imei_form_phone option:selected').data('premium'),
            limitDiff = 4,
            premiumDiff;

        newPremium.text(newPremiumPrice);

        // Set value on valid - default for normal imei update
        validUpgrade.text('✅');
        // Disable as not using the checkboxes
        $(modal).find('button[type="submit"]').attr('disabled', false);

        phones.on('change', function(e) {
            // Set price
            newPremiumPriceUpdate = $('#imei_form_phone option:selected').data('premium');
            newPremium.text(newPremiumPriceUpdate);
            premiumDiff = Math.abs(newPremiumPriceUpdate - oldPremiumPrice);

            if (premiumDiff <= limitDiff) {
                validUpgrade.text('✅');
                $(modal).find('button[type="submit"]').attr('disabled', false);
            } else {
                validUpgrade.text('⛔️ Requires old upgrade method');
                $(modal).find('button[type="submit"]').attr('disabled', true);
            }

            $('#diff_premium').text('£' + premiumDiff.toFixed(2));
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
