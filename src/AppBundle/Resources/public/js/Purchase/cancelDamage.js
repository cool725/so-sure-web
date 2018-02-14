$(document).ready(function(){
    $('#cancel_form_cancel').on("click",function() {
        return confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.');
    });
});
