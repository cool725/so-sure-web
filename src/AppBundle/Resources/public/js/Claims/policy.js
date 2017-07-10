$('#notesModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var notes = button.data('notes');
  var modal = $(this);
  modal.find('#notes-update-form').attr("action", update);
  modal.find('#notes').text(notes);
});

$('#withdrawModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var modal = $(this);
  modal.find('#withdraw-form').attr("action", update);
});
