$('#confirmModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget); // Button that triggered the modal
    var claim = button.data('confirm-msg');
    var modal = $(this);

    modal.find('#confirm-msg').text(claim);
});
