// modalOnboarding.js

$(function() {

    // TODO: Refactor to be dynamic and handle any number of slides
    $('#onboarding-carousel').on('slide.bs.carousel', function(e){

        // Adjust controls dependant on slide
        if (e.to >= 1) {
            $('#onboarding-prev-btn').prop('disabled', '');
        } else {
            $('#onboarding-prev-btn').prop('disabled', 'disabled');
        }

        if (e.to == 2) {
            $('#onboarding-next-btn').addClass('hide-controls');
            $('#onboarding-get-started-btn').removeClass('hide-controls');
        } else {
            $('#onboarding-next-btn').removeClass('hide-controls');
            $('#onboarding-get-started-btn').addClass('hide-controls');
        }
    });
});



