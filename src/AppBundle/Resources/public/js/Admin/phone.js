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
    
    modal.find('#phone-gwp').keyup(function() {
      monthly = modal.find('#phone-gwp').val() * ipt_rate;
      modal.find('#phone-premium').val(monthly);
    });
    modal.find('#phone-gwp').keyup();
  }
});

var phoneToRow = function(item) {
  var row_data = [
    item['name'],
    item['replacement_price'],
    item['os'],
    item['processor_speed'],
    item['processor_cores'],
    item['ram'],
    item['ssd'],
    item['screen_physical'],
    item['screen_resolution'],
    item['camera'],
    item['lte']
  ];
  var row = "<tr><td>" + row_data.join('</td><td>') + "</td></tr>";

  return row;
}

$('#alternativeModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var query = button.data('query');
  var phone = button.data('phone');
  var modal = $(this);
  //modal.find('#phone-alternative-form').attr("action", update);
  if (phone) {
    modal.find('.modal-title').text('Alternatives to ' + phone.name);
  }

  var current_table_body = modal.find('#current-table-body');
  current_table_body.empty();
  current_table_body.append(phoneToRow(phone));

  $.getJSON(query, function( data ) {
    var table_body = modal.find('#alternative-table-body');
    table_body.empty();
    $.each( data['alternatives'], function( key, item ) {
      table_body.append(phoneToRow(item));
    });
  });
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
