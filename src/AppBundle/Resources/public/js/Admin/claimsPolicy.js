$('.confirm-cancel').on("click",function() {
    if ($('.cancellation-reason').val() == 'user-requested') {
        var confirm_cancel = prompt('Are you sure you want to cancel this policy immediately? User Requested cancellations typically are set one month in advance.  Type YES to cancel immediately.');
        return confirm_cancel === "YES";
    } else {
        return confirm('Are you sure you want to cancel this policy?');
    }
});

$('.confirm-imei-update').on("click",function() {
    return confirm('Are you sure you want to update this imei?  This should only be done if the phone imei was incorrectly entered or post claim once the IMEI has changed.  This will trigger a Salva Policy Update.');
});

$('.confirm-phone-update').on("click",function() {
    return confirm('Are you sure you want to update this phone?  This should only be done if the phone was incorrectly selected or post claim if the phone make/model has changed.  This will trigger a Salva Policy Update, and possibly affect the pricing at Salva, however, it will not update the policy premium charge to the customer.');
});

$('.confirm-facebook-clear').on("click",function() {
    return confirm('Are you sure you want to clear this facebook user??? They will not be able to login with facebook anymore.');
});

$('.confirm-imei-rerun').on("click",function() {
    return confirm('Are you sure you want to rerun the imei/serial number checks? This costs £0.07.');
});


$(document).ready(function(){
    $('[data-toggle="popover"]').popover();

    $('.datetimepicker').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false
    });
});
