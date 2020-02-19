// user-competition.js

require('../../../sass/pages/user/user-competition.scss');

// Require BS component(s)
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../../common/validation-methods.js');
let textFit = require('textfit');
require('../../components/table.js');

let Clipboard = require('clipboard');

import tracking from '../../common/track-data.js';

$(function() {

    // Starling
    if ($('#starling_modal').length) {
        $('#starling_modal').modal('show');
    }

    // Use textfit plugin for h1 tag
    textFit($('.fit'), {detectMultiLine: true});

    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('click', function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(e) {
        $('.btn-copy').tooltip({'title':   'Copied 😀','trigger': 'manual'})
                      .tooltip('show');

        tracking('', 'scodecopied', 'user-competition');

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

    $('.scroll-top').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: 0
        }, 300);
    });
});
