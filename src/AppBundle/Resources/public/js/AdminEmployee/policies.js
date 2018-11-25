$(function () {
    $('#callModal').on('show.bs.modal', function(event) {
        //console.log('modal');
        var button = $(event.relatedTarget);
        var policyId  = button.data('policy-id');
        var name  = button.data('name');
        var number  = button.data('number');
        //console.log(policyId);
        $('#call_form_policyId').val(policyId);
        $('#call-form-name').html(name);
        $('#call-form-number').html(number);
    });
});
