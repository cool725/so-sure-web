$(function(){
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

    var chargeModel = $('#companyForm_chargeModel');
    var renewalDays = $('#companyForm_renewalDays').parent().parent();
    renewalDays.hide();
    chargeModel.change(function() {
        if ($(this).val() == "one-off") {
            renewalDays.hide();
        } else if ($(this).val() == "ongoing") {
            renewalDays.show();
        }
    });
});

