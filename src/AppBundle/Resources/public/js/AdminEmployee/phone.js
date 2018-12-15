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
        { title: 'Premium (current ipt rate)', data: 'premium' },
        { title: 'Premium (ipt rate @ from date)', data: 'initial_premium' },
        { title: 'Premium (ipt rate @ to date)', data: 'final_premium' },
        { title: 'Excess (Damage/Theft)', data: 'excess_detail' },
        { title: 'picsure approved Excess (Damage/Theft)', data: 'picsure_excess_detail' },
        { title: 'Notes', data: 'notes' }
      ]
    });
    modal.find('.modal-title').text(phone.make + ' ' + phone.model + ' ' + phone.memory + 'GB');
    modal.find('#phone-gwp').val(phone.gwp);
    modal.find('#phone-salva').html('£' + salva_standard + ' / £' + salva_min);
    modal.find('#phone-desired-premium').val('');

    modal.find('#phone-gwp').keyup(function() {
      monthly = (modal.find('#phone-gwp').val() * ipt_rate).toFixed(2);
      modal.find('#phone-premium').val(monthly);
    });
    modal.find('#phone-gwp').keyup();

    modal.find('#phone-desired-premium-btn').on('click', function(e) {
        monthly = (modal.find('#phone-desired-premium').val() / ipt_rate).toFixed(2);
        $('#phone-gwp').val(monthly)
        modal.find('#phone-gwp').keyup();
    });
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
    modal.find('#details-canonical-path').val(phone.canonicalPath);
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
                window.location.reload(false);
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
                window.location.reload(false);
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
                window.location.reload(false);
            }
        });
    }
});


$("#checkPremiumButton").click(function(e) {
    $("#check-calculated-premium").val('checking...');
    $.ajax({
            type: "POST",
            url: "/admin/phone/checkpremium/" + $("#check-initial-price").val(),
            data: {
                price: $("#check-initial-price").val(),
                access_token: $(this).data('token')
            },
            success: function(result) {
                var data = $.parseJSON(result);
                $("#check-calculated-premium").val(data.calculatedPremium);
            },
            error: function(result) {
                $("#check-calculated-premium").val('Error...');
            }
        });
});