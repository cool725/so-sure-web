$('#claimsModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget); // Button that triggered the modal
    var claim = button.data('claim');
    var modal = $(this);

    if (claim) {
        modal.find('.modal-title').text('Claim: ' + claim.number);
        modal.find('#claims-detail-id').val(claim.id);
        modal.find('#claims-detail-delete-id').val(claim.id);
        modal.find('#claims-detail-policy').text(claim.policyNumber);
        modal.find('#claims-detail-type').val(claim.type);
        modal.find('#claims-detail-status').text(claim.status);
        modal.find('#claims-detail-initial-suspicion').text(claim.initialSuspicion);
        modal.find('#claims-detail-final-suspicion').text(claim.finalSuspicion);
        modal.find('#claims-detail-davies-status').text(claim.daviesStatus);
        modal.find('#claims-detail-notes').text(claim.notes);
        modal.find('#claims-detail-description').text(claim.description);
        modal.find('#claims-detail-replacement-imei').text(claim.replacementImei);
        if (!claim.validReplacementImei) {
            modal.find('#claims-detail-replacement-imei').html('<s>' + claim.replacementImei + '</s> <i class="fa fa-warning" title="Invalid IMEI Number (Luhn Failure)"></i>');
        }
        modal.find('#claims-detail-replacement-phone-details').text(claim.replacementPhoneDetails);
        modal.find('#claims-detail-replacement-phone').val(claim.replacementPhoneId);
        modal.find('#claims-detail-policy-phone').text(claim.policyPhone);
        modal.find('#claims-detail-loss').text((claim.lossDate) ? moment(claim.lossDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-notification').text((claim.notificationDate)? moment(claim.notificationDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-recorded').text((claim.recordedDate) ? moment(claim.recordedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-approved').val((claim.approvedDate) ? moment(claim.approvedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-approved-show').text((claim.approvedDate) ? moment(claim.approvedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-replacement').text(
            (claim.replacementReceivedDate) ? moment(claim.replacementReceivedDate).format('DD-MM-YYYY') : ''
        );
        modal.find('#claims-detail-closed').text((claim.closedDate) ? moment(claim.closedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-excess').text(claim.excess);
        modal.find('#claims-detail-unauthorized-calls').text(claim.unauthorizedCalls);
        modal.find('#claims-detail-accessories').text(claim.accessories);
        modal.find('#claims-detail-replacement-cost').text(claim.phoneReplacementCost);
        modal.find('#claims-detail-transaction').text(claim.transactionFees);
        modal.find('#claims-detail-handling').text(claim.claimHandlingFees);
        modal.find('#claims-detail-reserved').text(claim.reservedValue);
        modal.find('#claims-detail-incurred').text(claim.incurred);
    } else {
        modal.find('.modal-title').text('Claim: Unknown');
        modal.find('#claims-detail-id').val('');
        modal.find('#claims-detail-delete-id').val('');
        modal.find('#claims-detail-policy').text('');
        modal.find('#claims-detail-type').text('');
        modal.find('#claims-detail-status').text('');
        modal.find('#claims-detail-initial-suspicion').text('');
        modal.find('#claims-detail-final-suspicion').text('');
        modal.find('#claims-detail-davies-status').text('');
        modal.find('#claims-detail-notes').text('');
        modal.find('#claims-detail-description').text('');
        modal.find('#claims-detail-replacement-imei').text('');
        modal.find('#claims-detail-replacement-phone-details').text('');
        modal.find('#claims-detail-replacement-phone').val('');
        modal.find('#claims-detail-policy-phone').text('');
        modal.find('#claims-detail-loss').text('');
        modal.find('#claims-detail-notification').text('');
        modal.find('#claims-detail-recorded').text('');
        modal.find('#claims-detail-approved').text('');
        modal.find('#claims-detail-replacement').text('');
        modal.find('#claims-detail-closed').text('');
        modal.find('#claims-detail-excess').text('');
        modal.find('#claims-detail-unauthorized-calls').text('');
        modal.find('#claims-detail-accessories').text('');
        modal.find('#claims-detail-replacement-cost').text('');
        modal.find('#claims-detail-transaction').text('');
        modal.find('#claims-detail-handling').text('');
        modal.find('#claims-detail-reserved').text('');
        modal.find('#claims-detail-incurred').text('');
    }

    $("#change-claim-type").change(function(){
        if(this.checked) {
            $('#claims-detail-type').attr('disabled', false);
        } else {
            $('#claims-detail-type').attr('disabled', 'disabled');
        }
    });

    $("#change-approved-date").change(function(){
        if (this.checked) {
            $('#claims-detail-approved').attr('readonly', false).datetimepicker({
                format: 'DD-MM-YYYY'
            });
        }
    });

    $("#delete-button").click(function(){
        if (confirm('Are you sure you want to delete this claim?')) {
            $("#delete-claim-form").submit();
        }
    });

    $("#update-button").click(function() {
        //data setup before submit
        if ($("#change-approved-date").is(':checked')) {
            $('#new-approved-date').val(moment($('#claims-detail-approved').text()).format('YYYY-MM-DD'));
        }
        $("#phone-alternative-form").submit();
    });

    $("#update-replacement-phone").change(function(){
        if(this.checked) {
            $('#claims-detail-replacement-phone').attr('disabled', false);
        } else {
            $('#claims-detail-replacement-phone').attr('disabled', 'disabled');
        }
    });

});

$('#flagsModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget) // Button that triggered the modal
    var flags = button.data('flags');
    var update = button.data('update');
    var modal = $(this);
    if (flags) {
        $('form[name="claimflags"]').attr('action', update);
        $.each(flags, function(item) {
            $('#claimflags_ignoreWarningFlags').find($("input[value='" + item + "']")).prop('checked', flags[item]);
        });
    } else {
        $('#claimFlags').attr('action', null);
        $('#claimflags_ignoreWarningFlags').find($("input[value='" + item + "']")).prop('checked', false);
    }
});
