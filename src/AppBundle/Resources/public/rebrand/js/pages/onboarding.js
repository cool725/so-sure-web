// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components

$(function() {

    const carousel    = $('#onboarding-carousel'),
          totalSlides = $('.carousel-item').length;

          // console.log(totalSlides);

    carousel.on('slide.bs.carousel', function(e){

        if (e.to > 1) {
            $('#onboarding-btn--next').addClass('btn-hide');
            $('#onboarding-btn--skip').removeClass('btn-hide');
        }

        if (e.to == 4) {
            $('#onboarding-btn--skip').addClass('btn-hide');
            $('#onboarding-btn--login').removeClass('btn-hide');
        }

    });


    // const carousel    = $('#onboarding-carousel'),
    //       indicators  = $('.onboarding-indicators li'),
    //       // Get the number of slides to set when to show the controls correctly
    //       totalSlides = $('.carousel-item').length - 1;

    // carousel.on('slide.bs.carousel', function(e){

    //     // Adjust controls dependant on slide
    //     if (e.to >= 1) {
    //         $('#onboarding-prev-btn').removeClass('disabled');
    //     } else {
    //         $('#onboarding-prev-btn').addClass('disabled');
    //     }

    //     if (e.to == totalSlides) {
    //         $('#onboarding-next-btn').addClass('hide-controls');
    //         $('#onboarding-get-started-btn').removeClass('hide-controls');
    //     } else {
    //         $('#onboarding-next-btn').removeClass('hide-controls');
    //         $('#onboarding-get-started-btn').addClass('hide-controls');
    //     }

    // });

});



