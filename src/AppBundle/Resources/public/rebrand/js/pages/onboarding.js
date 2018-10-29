// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jssocials');
let Clipboard = require('clipboard');

$(function() {

    const carousel = $('#onboarding-carousel'),
          onNavMb  = $('.onboarding-controls__mobile'),
          onbNavDt = $('.onboarding-nav__desktop');
          // totalSlides = $('.carousel-item').length;

          // console.log(totalSlides);

    // TODO: Make dynamic
    carousel.on('slide.bs.carousel', function(e){

        if (onNavMb.length) {
            // If slide is greater than one add skip button
            if (e.to > 1) {
                $('#onboarding-btn--next').addClass('btn-hide');
                $('#onboarding-btn--skip').removeClass('btn-hide');
            }

            // If slide is last slide add login
            if (e.to == 4) {
                $('#onboarding-btn--skip').addClass('btn-hide');
                $('#onboarding-btn--login').removeClass('btn-hide');
            }
        }

        if (onbNavDt.length) {

            let activeItem = $('.onboarding-nav__inner.active');

            let nextItem = (activeItem) =>  {
                activeItem.removeClass('active').next().addClass('active');
            }

            if (e.to == 1) {
                nextItem(activeItem);
            }
            if (e.to == 2) {
                nextItem(activeItem);
            }
            if (e.to == 4) {
                nextItem(activeItem);
            }
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

});



