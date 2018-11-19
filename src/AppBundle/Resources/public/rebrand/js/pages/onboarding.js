// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jquery-validation');
require('../../../js/Default/jqueryValidatorMethods.js');
require('jssocials');
let Clipboard = require('clipboard');

$(function() {

    // Send app link via SMS
    let smsButtonCont = $('.onboarding__send-app-link'),
        smsBtn        = $('.onboarding__send-app-link a'),
        smsUrl        = smsBtn.data('path'),
        smsLoader     = smsBtn.next('span');

    smsBtn.on('click', function(e) {
        e.preventDefault();

        $(this).hide();

        smsLoader.show();

        $.ajax({
            url: smsUrl,
            method: 'POST',
        })
        .done(function() {
            smsLoader.text('Download link sent to your deivce 😀');
            setTimeout(function() {
                // Hide all the sms links - use invisble so we dont jump the content
                smsButtonCont.addClass('invisible');
            }, 1500);
        })
        .fail(function(message) {
            console.log(message);
            smsLoader.text('Something went wrong 😥');
        });

    });


    // the slides on the carousel.
    const carousel = $('#onboarding_carousel'),
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
    const onboardingShare = $('#onboarding_btn_share');

    // Share buttons
    $(onboardingShare).jsSocials({
        shares: ['whatsapp', 'twitter', 'facebook'],
        text:      $(onboardingShare).data('share-text'),
        url:       $(onboardingShare).data('share-link'),
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
    $('#invite_form').validate({
        debug: false,
        // When to validate
        validClass: 'is-valid-ss',
        errorClass: 'is-invalid',
        onfocusout: false,
        onkeyup: false,
        rules: {
            'email_invite': {
                required: {
                    depends:function(){
                        $(this).val($.trim($(this).val()));
                        return true;
                    }
                },
                email: true,
                emaildomain: true
            }
        },
        messages: {
            'email_invite': {
                required: 'Please enter a valid email',
                email: 'Please enter a valid email',
                emaildomain: "Please check you've entered a valid email"
            }
        },
        submitHandler: function(form) {
            // Don't subit the form after validation
            return false;
        }
    });

    $('.btn-invite').on('click', function(e) {
        e.preventDefault();

        if ($('#invite_form').valid()) {

            let emailUrl = $(this).data('path');

            $(this).attr('disabled', 'disabled')
                   .html('<i class="fas fa-circle-notch fa-spin"></i>');

            $.ajax({
                url: emailUrl,
                type: 'POST',
                data: {email: $('.input-invite').val()},
            })
            .done(function(data) {
                // console.log(data);
                $('.btn-invite').tooltip({
                    'title':   'Your invite is on it\'s way 😀',
                    'trigger': 'manual'
                }).tooltip('show');

                setTimeout(function() {
                    $('.btn-invite').tooltip('hide')
                                    .removeAttr('disabled', '')
                                    .html('invite');
                }, 1500);

                // Clear the input and suggest another one? TODO: Copy
                $('.input-invite').val('').attr('placeholder', 'Send another?');
            })
            .fail(function(data) {
                // console.log(data);
                $('.btn-invite').tooltip({
                    'title':   'Something went wrong 😥',
                    'trigger': 'manual'
                }).tooltip('show');

                setTimeout(function() {
                    $('.btn-invite').tooltip('hide')
                                    .removeAttr('disabled', '')
                                    .html('invite');
                }, 1500);
            });
        }
    });








    let policyFile = null;
    function downloadPolicy() {

        $.ajax({
            url: $(this).data('path'),
            type: 'POST',
            context: this
        })
        .done(function(data, status) {
            if (data.file) {
                policyFile = data.file
                $(this).html('Download your policy details <i class="fal fa-download ml-2"></i>');
            } else {
                setTimeout(downloadPolicy, 500);
            }
        })
        .fail(function() {
            setTimeout(downloadPolicy, 500);
        });
    };
    downloadPolicy();

    $('#policyDownload').on('click', function() {
        if (policyFile) {
            window.open(policyFile);
        }
    });
});
