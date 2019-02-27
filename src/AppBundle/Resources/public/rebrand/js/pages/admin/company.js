// company.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
// e.g. require('../components/banner.js');

$(function(){

    $('#belong_modal').on('show.bs.modal', function (event) {
        let button = $(event.relatedTarget),
            companyId = button.data('company-id'),
            companyName = button.data('company-name'),
            modal = $(this);

        if (companyId) {
            modal.find('.modal-title').text('Add user to ' + companyName);
            modal.find('#belongForm_companyId').val(companyId);
        }
    });

    let chargeModel = $('#companyForm_chargeModel'),
        renewalDays = $('#companyForm_renewalDays').parent().parent();

    renewalDays.hide();

    chargeModel.change(function() {
        if ($(this).val() == "one-off") {
            renewalDays.hide();
        } else if ($(this).val() == "ongoing") {
            renewalDays.show();
        }
    });

});

