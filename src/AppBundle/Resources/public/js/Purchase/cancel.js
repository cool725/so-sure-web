// $(document).ready(function(){

    $('.btn-cancel-policy').on("click", function() {
        var reason = $(this).data('reason');
        console.log(reason);
        if (confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.')) {
            $('#cancel_form_reason').val(reason);
            $('#cancel_form').submit();
        }
    });

//     $('#cancel_form_cancel').on("click",function() {
//         return confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.');
//     });



// });

$(function(){

    // Select cancel option add active class disable button
    $('.btn-cancel').on('click', function(e) {
        e.preventDefault();

        // Remove class from other buttons to get this choice
        $('.btn-cancel').removeClass('active user-choice');
        // Add the active class choice
        $(this).toggleClass('active user-choice');

        var data = $(this).data();
        // console.log(data.reason);

        // if ($('.user-choice').length) {
        $('#btn-next-step').removeAttr('disabled');
        $('#btn-next-step').data('info', data.target);
        // }
    });

    // Once active show the correct section to the user
    $('#btn-next-step').on('click', function(e) {
        e.preventDefault();

        var data = $(this).data();

        // console.log(data.info);

        $('.reasons').fadeOut('fast', function() {
            $(data.info).fadeIn();
        });
    });

    // If they want to go back allow
    $('.btn-back').on('click', function(e) {
        e.preventDefault();

        $('.information').fadeOut('fast', function() {
            $('.reasons').fadeIn();
        });
    });

    // Cancel button
    $('.btn-cancel-policy').on('click', function() {
        var reason = $(this).data('reason');
        if (confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.')) {
            $('#cancel_form_reason').val(reason);
            $('#cancel_form').submit();
        }
    });

});
