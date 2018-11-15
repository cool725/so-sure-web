// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jssocials');
let Clipboard = require('clipboard');

$(function() {

    // Send app link via SMS
    let smsButtonCont = $('.onboarding__send-app-link'),
        smsBtn        = $('.onboarding__send-app-link a'),
        smsLoader     = smsBtn.next('span');

    smsBtn.on('click', function(e) {
        e.preventDefault();

        $(this).hide();

        smsLoader.show();

        $.ajax({
            url: '/user/applinksms',
            method: 'POST',
        })
        .done(function() {
            smsLoader.text('Download link sent to your deivce 😀');
            setTimeout(function() {
                // Hide all the sms links - use invisble so we dont jump the content
                smsButtonCont.addClass('invisible');
            }, 1500);
        })
        .fail(function() {
            smsLoader.text('Something went wrong 😥');
        });

    });


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
    };

    // when the carousel is triggered control the navigation buttons and set them at start
    carousel.on('slide.bs.carousel', slide);
    slide({'to': 0});

    // Copy scode
    // NOTE: Copies from hidden div with a body of text
    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip({'title':   'Copied 😀','trigger': 'manual'}).tooltip('show');

        setTimeout(function() {
            $('.btn-copy').tooltip('hide');
        }, 1500);
    });

    // Social Sharing
    // NOTE: We use JSSOCIALS - see fork on github for more info
    const onboardingShare = $('#onboarding-btn--share');

    // Share buttons
    $(onboardingShare).jsSocials({
        shares: ['whatsapp', 'twitter', 'facebook'],
        url:       $(onboardingShare).data('share-link'),
        text:      $(onboardingShare).data('share-text'),
        shareIn:   'popup',
        showLabel: false,
        showCount: false,
        on: {
            click: function(e) {
                console.log(this.share);
                sosure.track.byInvite(this.share);
            }
        }
    });

    // Email Invite code
    // NOTE:
    $('.btn-invite').on('click', function(e) {
        e.preventDefault();

        $.ajax({
            // url: '/path/to/file',
            // type: 'default GET (Other values: POST)',
            // dataType: 'default: Intelligent Guess (Other values: xml, json, script, or html)',
            // data: {param1: 'value1'},
        })
        .done(function() {
            $('.btn-invite').tooltip({
                'title':   'Your invite is on it\'s way 😀',
                'trigger': 'manual'
            }).tooltip('show');

            setTimeout(function() {
                $('.btn-invite').tooltip('hide');
            }, 1500);

            // Clear the input and suggest another one? TODO: Copy
            $('.input-invite').val().attr('placeholder', 'How about another one?');
        })
        .fail(function() {
            $('.btn-invite').tooltip({
                'title':   'Something went wrong 😥',
                'trigger': 'manual'
            }).tooltip('show');

            setTimeout(function() {
                $('.btn-invite').tooltip('hide');
            }, 1500);
        });

    });
});
