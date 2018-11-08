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
    const smsButtons = $(".sms-btn");
    let smsButtonClicked = false;
    smsButtons.find("a").click(function() {
        if (smsButtonClicked) {
            return;
        }
        let label = $(this).parent().append("p").text("...");
        $(this).remove();
        smsButtonClicked = true;
        $.ajax({
            url: "/user/applinksms",
            method: "POST",
            success: function(data) {
                label.text("Download link sent to your device.");
            },
            error: function(data) {
                label.text(data.responseText);
            }
        });
    }, );

    // the slides on the carousel.
    const carousel = $('#onboarding-carousel');
    const onNavMb  = $('.onboarding-controls__mobile');
    const onNavDt = $('.onboarding-nav__desktop');

    let slide = function(e) {
        const slides = [
            [null, 'next'],
            ['prev', 'next'],
            ['prev', 4],
            [1, 4],
            [2, 'login']
        ];

        // Mobile navigation buttons.
        if (onNavMb.length) {
            let slide = slides[e.to];
            let prev = $('#onboarding-btn--prev');
            let next = $('#onboarding-btn--next');
            let login = $('#onboarding-btn--login');
            let skip = [$('#onboarding-btn--prev-skip'), $('#onboarding-btn--skip')];

            prev.toggleClass('btn-hide', slide[0] != 'prev');
            next.toggleClass('btn-hide', slide[1] != 'next');
            login.toggleClass('btn-hide', slide[1] != 'login');
            for (let i = 0; i < 2; i++) {
                if (typeof slide[i] == 'number') {
                    skip[i].removeClass('btn-hide');
                    skip[i].attr('data-slide-to', slide[i])
                } else {
                    skip[i].addClass('btn-hide');
                }
            }
        }

        // Desktop navigation buttons.
        // IDEA: If I were using this more I could change the data-secondary-page thing so be a maximum and allow
        //       each navigation button to represent a range of slides.
        onNavDt.children().each(function() {
            $(this).toggleClass(
                'active',
                $(this).attr("data-slide-to") == e.to || $(this).attr("data-secondary-page") == e.to
            );
        });

        // If one of the sms buttons have been pressed we can remove them.
        if (smsButtonClicked) {
            smsButtons.remove();
        }
    };

    // when the carousel is triggered control the navigation buttons and set them at start
    carousel.on('slide.bs.carousel', slide);
    slide({"to": 0});

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
