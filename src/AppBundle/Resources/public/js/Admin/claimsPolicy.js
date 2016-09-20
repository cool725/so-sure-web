$('.confirm-cancel').on("click",function() {
    return confirm('Are you sure you want to cancel this policy?');
});

$('.confirm-imei-update').on("click",function() {
    return confirm('Are you sure you want to update this imei???  You probably should NOT be updating.');
});

$('.confirm-facebook-clear').on("click",function() {
    return confirm('Are you sure you want to clear this facebook user??? They will not be able to login with facebook anymore.');
});

$(document).ready(function(){
    $('[data-toggle="popover"]').popover();
});