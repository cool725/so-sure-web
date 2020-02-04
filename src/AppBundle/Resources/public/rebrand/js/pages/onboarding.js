// onboarding.js

require('../../sass/pages/onboarding.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');
require('bootstrap/js/dist/carousel');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');
let Clipboard = require('clipboard');

import tracking from '../common/track-data.js';

$(function() {

    // Starling
    if ($('#starling_modal').length) {
        $('#starling_modal').modal('show');
    }

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
            smsLoader.hide();
            smsButtonCont.append('<small>Download link sent to your device 😀</small>');
            setTimeout(function() {
                // Hide all the sms links - use invisble so we dont jump the content
                smsButtonCont.addClass('invisible');
            }, 4000);
        })
        .fail(function(message) {
            smsLoader.hide();
            smsButtonCont.append('<small>Sorry, we were unable to send you a sms. You can try again later, or just go the App Store/Play Store and search for so-sure 😥</small>');
            setTimeout(function() {
                // Hide all the sms links - use invisble so we dont jump the content
                smsButtonCont.addClass('invisible');
            }, 4000);
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

        tracking('', 'onboarding', 'page-' + e.to);

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

        tracking('', 'scodecopied', 'onboarding');

        setTimeout(function() {
            $('.btn-copy').tooltip('hide');
        }, 1500);
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
                data: {
                    email: $('.input-invite').val(),
                    csrf: $('#email-csrf').val()
                }
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
                $('.btn-invite').tooltip({
                    'title':   'Sorry, we were unable to send your invitation. Please try again later 😥',
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
            url: $('#policy_download').data('path')
        })
        .done(function(data, status) {
            if (data.file) {
                policyFile = data.file;
                $('#policy_download').html('Download your policy details <i class="fal fa-download ml-2"></i>').removeClass('disabled');
            } else {
                setTimeout(downloadPolicy, 11000);
            }
        });
    };

    downloadPolicy();

    $('#policy_download').on('click', function(e) {
        e.preventDefault();

        if (policyFile) {
            window.open(policyFile);
        }
    });
});
