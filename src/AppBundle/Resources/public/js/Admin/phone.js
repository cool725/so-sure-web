$(function () {
    $('#phone-from-group').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false 
    });
    $('#phone-to-group').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false //Important! See issue #1075
    });
    $("#phone-from-group").on("dp.change", function (e) {
        $('#phone-to-group').data("DateTimePicker").minDate(e.date);
    });
    $("#phone-to-group").on("dp.change", function (e) {
        $('#phone-from-group').data("DateTimePicker").maxDate(e.date);
    });
});

$('#phoneModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var phone = button.data('phone');
  var ipt_rate = 1 + button.data('ipt-rate');
  var salva_standard = button.data('salva-standard');
  var salva_min = button.data('salva-min');
  var modal = $(this);
  modal.find('#phone-update-form').attr("action", update);
  if (phone) {
    modal.find('#prices').DataTable({
      destroy: true,
      paging: false,
      searching: false,
      data: phone.prices,
      columns: [
        { title: 'Valid From', data: 'valid_from' },
        { title: 'Valid To', data: 'valid_to' },
        { title: 'GWP', data: 'gwp' },
        { title: 'Premium', data: 'premium' },
        { title: 'Notes', data: 'notes' }
      ]
    });
    modal.find('.modal-title').text(phone.make + ' ' + phone.model + ' ' + phone.memory + 'GB');
    modal.find('#phone-gwp').val(phone.gwp);
    modal.find('#phone-salva').html('£' + salva_standard + ' / £' + salva_min);

    modal.find('#phone-gwp').keyup(function() {
      monthly = (modal.find('#phone-gwp').val() * ipt_rate).toFixed(2);
      modal.find('#phone-premium').val(monthly);
    });
    modal.find('#phone-gwp').keyup();
  }
});

$('#detailsModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var update = button.data('update');
  var phone = button.data('phone');
  var modal = $(this);
  modal.find('#details-update-form').attr("action", update);
  if (phone) {
    modal.find('.modal-title').text('Edit ' + phone.make + ' ' + phone.model + ' ' + phone.memory + 'GB');
    modal.find('#details-description').val(phone.description);
    modal.find('#details-fun-facts').val(phone.funFacts);
  }
});

$('.phone-active').click(function() {
    if (confirm('Are you sure you want to make this phone active/inactive?')) {
        var url = $(this).data('active');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location = window.location;
            }
        });
    }
});

$('.phone-highlight').click(function() {
    if (confirm('Are you sure you want to make this phone (un)highlighted?')) {
        var url = $(this).data('highlight');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location = window.location;
            }
        });
    }
});

$('.phone-newhighdemand').click(function() {
    if (confirm('Are you sure you want to set/unset this phone new & in high demand?')) {
        var url = $(this).data('newhighdemand');
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token },
            success: function(result) {
                window.location = window.location;
            }
        });
    }
});
