// user-dashboard.js

require('../../../sass/pages/user/user-dashboard.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../../common/validationMethods.js');
require('jquery-circle-progress');
require('jssocials');

let Clipboard = require('clipboard');

import tracking from '../../common/trackData.js';

$(function() {

    // Reward pot chart
    let rewardPotChart = $('#reward_pot_chart'),
        rewardPotValue = rewardPotChart.data('pot-value'),
        rewardPotMaxPot = rewardPotChart.data('max-pot'),
        total = Math.round((rewardPotValue / rewardPotMaxPot) * 100) / 100;

    $(rewardPotChart).circleProgress({
        value: total,
        size: 180,
        startAngle: -1.5,
        lineCap: 'square',
        emptyFill: '#efefef',
        fill: '#2593f3',
    });

    // Connection bonus chart
    let connectionBonusChart = $('#connection_bonus_chart'),
        totalBonusDays = connectionBonusChart.data('bonus-days-total'),
        bonusDaysLeft = connectionBonusChart.data('bonus-days-remaining'),
        bonus = Math.round((bonusDaysLeft / totalBonusDays) * 100) / 100;

    $(connectionBonusChart).circleProgress({
        value: bonus,
        size: 180,
        startAngle: -1.5,
        lineCap: 'square',
        emptyFill: '#efefef',
        fill: '#ed1c23',
    });

    $('.connection').tooltip();

    // Copy scode
    // NOTE: Copies from hidden div with a body of text
    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip({'title':   'Copied 😀','trigger': 'manual'})
                      .tooltip('show');

        tracking('', 'scodecopied', 'user-home');

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
                let location = 'user-home';
                tracking(this.share, 'invite', location);
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
                    $('.btn-invite').tooltip('hide');
                    // Temp solution to reloading page so invite appears in list
                                    // .removeAttr('disabled', '')
                                    // .html('invite');
                    location.reload();
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
});
