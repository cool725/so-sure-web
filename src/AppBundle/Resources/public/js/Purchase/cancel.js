// $(document).ready(function(){

//     $('.btn-cancel-policy').on("click", function() {
//         var reason = $(this).data('reason');
//         console.log(reason);
//         if (confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.')) {
//             $('#cancel_form_reason').val(reason);
//             $('#cancel_form').submit();
//         }
//     });

//     $('#cancel_form_cancel').on("click",function() {
//         return confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.');
//     });



// });

$(function(){

    $('.btn-cancel').click(function(event) {
        // Remove class from other buttons to get this choice
        $('.btn-cancel').removeClass('active user-choice');
        // Add the active class choice
        $(this).toggleClass('active user-choice');

        var reason = $(this).data('reason');
        console.log(reason);

        // if ($('.user-choice').length) {
        $('#btn-next-step').removeAttr('disabled');
        // }
    });

});
