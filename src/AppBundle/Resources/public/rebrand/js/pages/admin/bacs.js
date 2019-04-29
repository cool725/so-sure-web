// bacs.js

// require('../../../sass/pages/admin.scss');

// Require BS component(s)
// require('bootstrap/js/dist/tooltip');

// Require components
require('datatables.net')(window, $);
require('datatables.net-dt')(window, $);
require('moment');
// require('tempusdominus-bootstrap-4');

$(function(){

    $('#serial_number_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget),
            url = button.data('details-url'),
            serial = button.data('serial'),
            modal = $(this);

        modal.find('.modal-title').text('Loading Serial Number ' + serial);
        modal.find('.modal-body').append('<table id="serial_details" width="100%" cellspacing="0" class="table-striped"></table>');

        $.ajax({
            url: url,
            type: 'GET',
            success: function(result) {
                modal.find('#serial_details').DataTable({
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

    $('#edit_serial_number_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget),
            url = button.data('serial-number-url'),
            serialNumber = button.data('serial-number'),
            modal = $(this);

        $('#edit_serial_number_form').attr('action', url);
        $('#edit_serial_number').val(serialNumber);
    });

    $('.bacs-submit').on('click', function(e) {
         if (confirm('Have you manually submitted this file to bacs?')) {
            let url = $(this).data('bacs-action-url'),
                token = $(this).data('token');
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

    $('.bacs-cancel').on('click', function(e) {
        if (confirm('Are you sure you wish to cancel this bacs file submission?')) {
            let url = $(this).data('bacs-action-url'),
                token = $(this).data('token');
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

    $('.bacs-approve').on('click', function(e) {
        if (confirm('Are you sure you wish to approve this payment?')) {
            let url = $(this).data('bacs-approve-url'),
                token = $(this).data('token');
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

    $('.bacs-reject').on('click', function(e) {
        if (confirm('Are you sure you wish to reject this payment?')) {
            let url = $(this).data('bacs-reject-url'),
                token = $(this).data('token');
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

    $('.bacs-meta-update').on('click', function(e) {
        if (confirm('Are you sure you wish to update the metadata amount?')) {
            let url = $(this).data('bacs-update-meta-url'),
                token = $(this).data('token');
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

});
