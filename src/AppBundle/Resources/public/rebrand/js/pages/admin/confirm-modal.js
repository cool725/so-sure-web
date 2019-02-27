// confirm-modal.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
// e.g. require('../components/banner.js');

$(function(){

    $('.confirm-modal').on('show.bs.modal', function (event) {
        let modal = $(event.currentTarget),
            checkboxes = $(modal).find('input[type="checkbox"][required]'),
            cases = $(modal).find('.cases a');

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

});
