// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jssocials');
let Clipboard = require('clipboard');

$(function() {
    // buttons to get link to app download sent to their phone via sms.
    const smsButtons = $('.sms-btn');
    let smsButtonClicked = false;
    smsButtons.find('a').click(function() {
        if (smsButtonClicked) {
            return;
        }
        let label = $(this).parent().append('p').text('...');
        $(this).remove();
        smsButtonClicked = true;
        $.ajax({
            url: '/user/applinksms',
            method: 'POST',
            success: function(data) {
                label.text('Download link sent to your device.');
            },
            error: function(data) {
                label.text(data.responseText);
            }
        });
    }, );

    // the slides on the carousel.
    const carousel = $('#onboarding-carousel'),
          onNavMb  = $('.onboarding-controls__mobile'),
          onNavDt  = $('.onboarding-nav__desktop');

    let slide = function(e) {

        // Mobile navigation buttons.
        if (onNavMb.length) {
            let btnLeft   = $('#onboarding-btn--left'),
                btnRight  = $('#onboarding-btn--right'),
                title     = $('#onboarding--title'),
                loginPath = btnRight.data('href');

            switch(e.to) {
                case 0:
                    btnLeft.addClass('disabled')
                           .removeAttr('data-slide-to');
                    btnRight.attr('data-slide-to', 1);
                    break;
                case 1:
                    btnLeft.removeClass('disabled')
                           .attr('data-slide-to', 0);
                    btnRight.removeClass('opacity-50')
                            .attr('data-slide-to', 2)
                            .text('NEXT');
                    break;
                case 2:
                    btnLeft.attr('data-slide-to', 1);
                    btnRight.addClass('opacity-50')
                            .attr('href', '#')
                            .attr('data-slide-to', 4)
                            .text('SKIP');
                    break;
                case 3:
                    btnLeft.attr('data-slide-to', 2);
                    btnRight.removeClass('opacity-50')
                            .attr('href', '#')
                            .text('FINISH')
                    break;
                case 4:
                    btnLeft.attr('data-slide-to', 2);
                    btnRight.removeClass('opacity-50')
                            .removeAttr('data-slide-to')
                            .attr('href', loginPath)
                            .text('LOGIN');
                    break;
            }
        }

        // Desktop navigation buttons.
        if (onNavDt.length) {
            // IDEA: If I were using this more I could change the data-secondary-page thing so be a maximum and allow each navigation button to represent a range of slides.
            onNavDt.children().each(function() {
                $(this).toggleClass(
                    'active',
                    $(this).attr('data-slide-to') == e.to || $(this).attr('data-secondary-page') == e.to
                );
            });
        }

        // If one of the sms buttons have been pressed we can remove them.
        if (smsButtonClicked) {
            smsButtons.remove();
        }
    };

    // when the carousel is triggered control the navigation buttons and set them at start
    carousel.on('slide.bs.carousel', slide);
    slide({'to': 0});

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
    let onboardingShare = $('#onboarding-btn--share');
    //
    // Share buttons
    $(onboardingShare).jsSocials({
        shares: ['whatsapp', 'twitter', 'facebook'],
        url: $(onboardingShare).data('share-link'),
        text: $(onboardingShare).data('share-text'),
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
