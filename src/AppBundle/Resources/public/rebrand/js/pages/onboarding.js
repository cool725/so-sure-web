// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jssocials');
let Clipboard = require('clipboard');

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

    // Copy scode
    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    $('.btn-copy').tooltip({
        'title':   'copied',
        'trigger': 'manual'
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip('show');
        setTimeout(function() {
            $('.btn-copy').tooltip('hide');
        }, 1500);
    });

    // Social Sharing
    // Share buttons
    $('#onboarding-btn--share').jsSocials({
        shares: ['whatsapp', 'twitter', 'facebook'],
        url: $(this).data('share-link'),
        text: $(this).data('share-text'),
        shareIn: 'popup',
        showLabel: false,
        showCount: false,
        on: {
            click: function(e) {
                console.log(this.share);
                sosure.track.byInvite(this.share);
            }
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



