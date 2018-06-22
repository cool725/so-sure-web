$('#invalidModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var submit = button.data('submit');
  var policyNumber = button.data('policy-number');
  var modal = $(this);
  if (submit) {
      modal.find('#invalid-picsure-form').attr('action', submit);
      modal.find('.modal-title').text('Invalid pic-sure - ' + policyNumber);
  }
});
