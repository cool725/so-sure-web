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

        $('#btn-next-step').removeClass('disabled btn-green-hollow').addClass('btn-green');
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
            $(window).scrollTop(0);

            if (data.info == '#reason-8-info') {
                $('#btn-cancel-policy-other').addClass('disabled');
            }
        });
    });

    // If they want to go back allow
    $('.btn-back').on('click', function(e) {
        e.preventDefault();

        // Hide info and reset stuff
        $('.information').fadeOut('fast', function() {
            $('.reasons').fadeIn()
            $('.btn-cancel').removeClass('active user-choice');
            $('#btn-next-step').removeClass('btn-green').addClass('disabled btn-green-hollow');
            $('#btn-cancel-policy-other').removeClass('disabled');
            $(window).scrollTop(0);
        });
    });

    // Cancel button
    $('.btn-cancel-policy').on('click', function() {
        $(this).attr('disabled');
        $('.btn-back').addClass('disabled');
        if (confirm('Are you sure you want to cancel your policy?')) {
            var reason = $(this).data('reason');
            $('#cancel_form_reason').val(reason);

            var other = $('#other-reason').val();
            $('#cancel_form_othertxt').val(other);

            $('#cancel_form').submit();
        }
    });

    // Other form
    $('#other-reason').on('keyup', function(e) {
        if ($(this).val() != '') {
            $('#btn-cancel-policy-other').removeClass('disabled');
        } else {
            $('#btn-cancel-policy-other').addClass('disabled');
        }
    });

});
