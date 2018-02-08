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

        $('#btn-next-step').removeClass('disabled');
        $('#btn-next-step').data('info', data.target);
    });

    // Once active show the correct section to the user
    $('#btn-next-step').on('click', function(e) {
        e.preventDefault();

        // Get the data from the button
        var data = $(this).data();

        // Fade out choices fadeIn choices information
        $('.reasons').fadeOut('fast', function() {
            $(data.info).fadeIn();
        });
    });

    // If they want to go back allow
    $('.btn-back').on('click', function(e) {
        e.preventDefault();

        // Hide info and reset stuff
        $('.information').fadeOut('fast', function() {
            $('.reasons').fadeIn()
            $('.btn-cancel').removeClass('active user-choice');
            $('#btn-next-step').addClass('disabled');
        });
    });

    // Cancel button
    $('.btn-cancel-policy').on('click', function() {

        if ($(this).is('#btn-cancel-other')) {
            var reason = 'Other: ' + $('#other-reason').val();
        } else {
            var reason = $(this).data('reason');
        }

        console.log(reason);

        // $(this).attr('disabled');
        // $('.btn-back').addClass('disabled');
        // if (confirm('Are you sure you want to cancel your policy? Cancellation can not be undone and once cancelled, your phone will no longer be covered for Theft, Loss, & Accidental Damage.')) {
        //     $('#cancel_form_reason').val(reason);
        //     $('#cancel_form').submit();
        // }
    });

    // Other
    // $('#other-reason').on('keyup', function() {
    //     var newreason = $(this).val();
    //      $('#btn-cancel-other').data('reason', newreason);
    // });

});
