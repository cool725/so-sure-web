$('.confirmModal').on('show.bs.modal', function (event) {
    var modal = event.currentTarget;
    var checkboxes = $(modal).find('input[type="checkbox"][required]');
    var cases = $(modal).find('.cases a');

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
