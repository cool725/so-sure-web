$('#phoneModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var phone = button.data('phone');
  var modal = $(this);
  modal.find('#phone-update-form').attr("action", update);
  if (phone) {
    modal.find('.modal-title').text('Edit Phone');
    modal.find('#phone-make').val(phone.make);
    modal.find('#phone-model').val(phone.model);
    modal.find('#phone-devices').val(phone.devices);
    modal.find('#phone-memory').val(phone.memory);
    modal.find('#phone-policy').val(phone.policy_price);
    modal.find('#phone-loss').val(phone.loss_price);
  }
});

$('.phone-delete').click(function() {
    if (confirm('Are you sure you want to delete this phone?')) {
        var url = $(this).data('delete');
        $.ajax({
            url: url,
            type: 'DELETE',
            success: function(result) {
                window.location = window.location;
            }
        });
    }
});
