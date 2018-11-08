$('#confirmModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    var claim = button.data('confirm-msg');
    var imei = button.data('imei');
    var checkboxes = button.data('checkboxes');
    var modal = $(this);

    modal.find('#confirm-msg').text(claim);
    modal.find('#imei').text(imei);
    modal.find('#checkboxes').empty();

    for ( var i = 0; i < checkboxes.length; i++) {
        var current = checkboxes[i];

        var checkbox = document.createElement('tr'),
            td = document.createElement('td'),
            input = document.createElement('input'),
            label = document.createElement('label');

        input.setAttribute('type', 'checkbox');

        input.setAttribute('id', ('checkbox' + i));
        label.setAttribute('for', ('checkbox' + i));

        input.addEventListener('input', function (e) {
            checkAll(checkboxes.length);
        });

        label.innerText = current;

        td.append(input, label);
        checkbox.append(td);

        modal.find('#checkboxes').append(checkbox);
    }
});

function checkAll(n) {
    if ($('#checkboxes input:checked').length === n) {
        $('#imei_form_update').attr('disabled', false)
    } else {
        $('#imei_form_update').attr('disabled', true)
    }
}