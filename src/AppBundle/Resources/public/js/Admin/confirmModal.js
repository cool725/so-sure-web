$('.confirmModal').on('show.bs.modal', function (event) {
    $('#checkboxes input').on('input', function (e) {
        checkAll($('#checkboxes input').length)
    });
});

function checkAll(n) {
    if ($('#checkboxes input:checked').length === n) {
        $('#imei_form_update').attr('disabled', false)
    } else {
        $('#imei_form_update').attr('disabled', true)
    }
}
