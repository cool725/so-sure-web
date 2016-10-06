$('.confirm-cancel').on("click",function() {
    return confirm('Are you sure you want to cancel this policy?');
});

$('.confirm-imei-update').on("click",function() {
    return confirm('Are you sure you want to update this imei???  You probably should NOT be updating.');
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
