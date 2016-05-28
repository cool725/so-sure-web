$('#phoneModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var phone = button.data('phone');
  var ipt_rate = 1 + button.data('ipt-rate');
  var modal = $(this);
  modal.find('#phone-update-form').attr("action", update);
  if (phone) {
    modal.find('.modal-title').text('Edit Phone');
    modal.find('#phone-make').val(phone.make);
    modal.find('#phone-model').val(phone.model);
    modal.find('#phone-devices').val(phone.devices.join('|'));
    modal.find('#phone-memory').val(phone.memory);
    modal.find('#phone-gwp').val(phone.gwp);
    if (phone.active) {
      modal.find('#phone-active-yes').prop('checked',true);
    } else {
      modal.find('#phone-active-no').prop('checked',true);
    }

    modal.find('#phone-gwp').keyup(function() {
      monthly = modal.find('#phone-gwp').val() * ipt_rate;
      modal.find('#phone-premium').val(monthly);
    });
    modal.find('#phone-gwp').keyup();
  }
});

$('.phone-delete').click(function() {
    if (confirm('Are you sure you want to delete this phone?')) {
        var url = $(this).data('delete');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'DELETE',
            data: { token: token },
            success: function(result) {
                window.location = window.location;
            }
        });
    }
});
