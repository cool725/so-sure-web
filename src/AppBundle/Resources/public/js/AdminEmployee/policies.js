$(function () {
    $('#callModal').on('show.bs.modal', function(event) {
        //console.log('modal');
        var button = $(event.relatedTarget);
        var policyId  = button.data('policy-id');
        //console.log(policyId);
        $('#call_form_policyId').val(policyId);
    });
});
