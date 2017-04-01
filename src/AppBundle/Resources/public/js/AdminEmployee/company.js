$('#belongModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var companyId = button.data('company-id');
  var companyName = button.data('company-name');
  var modal = $(this);
  if (companyId) {
    modal.find('.modal-title').text('Add user to ' + companyName);
    modal.find('#belongForm_companyId').val(companyId);
  }
});
