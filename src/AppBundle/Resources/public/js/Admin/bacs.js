$('.bacs-submit').click(function() {
    if (confirm('Have you manually submitted this file to bacs?')) {
        var url = $(this).data('bacs-action-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
               window.location.reload(false);
            }
        });
    }
});

$('.bacs-cancel').click(function() {
    if (confirm('Are you sure you wish to cancel this bacs file submission?')) {
        var url = $(this).data('bacs-action-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});

$('.bacs-approve').click(function() {
    if (confirm('Are you sure you wish to approve this payment?')) {
        var url = $(this).data('bacs-approve-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});

$('.bacs-reject').click(function() {
    if (confirm('Are you sure you wish to reject this payment?')) {
        var url = $(this).data('bacs-reject-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});

$('.bacs-meta-update').click(function() {
    if (confirm('Are you sure you wish to update the metadata amount?')) {
        var url = $(this).data('bacs-update-meta-url');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location.reload(false);
            }
        });
    }
});

$('#editSerialNumberModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget) // Button that triggered the modal
    var url = button.data('serial-number-url');
    var serialNumber = button.data('serial-number');
    var modal = $(this);
    $('#editSerialNumberForm').attr('action', url);
    $('#editSerialNumber').val(serialNumber);
});

$('#serialNumberModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget) // Button that triggered the modal
    var url = button.data('details-url');
    var serial = button.data('serial');
    var modal = $(this);
    modal.find('.modal-title').text('Loading Serial Number ' + serial);
    modal.find('#serial-details-wrapper div').remove();
    modal.find('#serial-details-wrapper').append('<table id="serial-details" width="100%" cellspacing="0" class="table-striped"></table>');
    $.ajax({
        url: url,
        type: 'GET',
        success: function(result) {
            modal.find('#serial-details').DataTable({
                destroy: true,
                paging: false,
                searching: false,
                data: result,
                columns: [
                    { title: 'Bank', data: 'bank_name' },
                    { title: 'Account', data: 'account_name' },
                    { title: 'Sort Code', data: 'displayable_sort_code' },
                    { title: 'Accout Number', data: 'displayable_account_number' },
                    { title: 'Mandate', data: 'mandate' },
                    { title: 'Mandate Status', data: 'mandate_status' },
                    { title: 'First Payment', data: 'initial_date' },
                    { title: 'Monthly Payment', data: 'monthly_day' }
                ]
            });
            modal.find('.modal-title').text('Mandates With Serial Number ' + serial);
        }
    });
});
