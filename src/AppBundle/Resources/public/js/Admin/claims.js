$('#claimsModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var claim = button.data('claim');
  var modal = $(this);
  if (claim) {
    modal.find('.modal-title').text('Claim: ' + claim.number);
    modal.find('#claims-detail-policy').text(claim.policyNumber);
    modal.find('#claims-detail-type').text(claim.type);
    modal.find('#claims-detail-status').text(claim.status);
    modal.find('#claims-detail-davies-status').text(claim.daviesStatus);
    modal.find('#claims-detail-notes').text(claim.notes);
    modal.find('#claims-detail-description').text(claim.description);
    modal.find('#claims-detail-loss').text(claim.lossDate);
    modal.find('#claims-detail-notification').text(claim.notificationDate);
    modal.find('#claims-detail-recorded').text(claim.recordedDate);
    modal.find('#claims-detail-replacement').text(claim.replacementReceivedDate);
    modal.find('#claims-detail-closed').text(claim.closedDate);
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
    modal.find('#claims-detail-policy').text('');
    modal.find('#claims-detail-type').text('');
    modal.find('#claims-detail-status').text('');
    modal.find('#claims-detail-davies-status').text('');
    modal.find('#claims-detail-notes').text('');
    modal.find('#claims-detail-description').text('');
    modal.find('#claims-detail-loss').text('');
    modal.find('#claims-detail-notification').text('');
    modal.find('#claims-detail-recorded').text('');
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
});
