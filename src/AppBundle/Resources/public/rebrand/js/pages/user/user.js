// user.js

require('../../../sass/pages/user/user.scss');

// Require BS component(s)
require('bootstrap/js/dist/tab');
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/dropdown');
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../../common/validation-methods.js');
// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

let Clipboard = require('clipboard');
let textFit = require('textfit');

import tracking from '../../common/track-data.js';

$(function() {

    // Could move
    $('.connection').tooltip();

    // Textfit
    textFit($('.fit'), {detectMultiLine: false});

    // Tabs generic
    $('.show-tab').on('click', function (e) {
        e.preventDefault()
        let tab = $(this).data('tab-to');
        $(tab).tab('show')
    });

    // Copy btns generic
    $('.btn-copy').tooltip({
        trigger: 'click',
        placement: 'top'
    });

    $('.btn-copy').on('click', function(e) {
        e.preventDefault();
    });

    const setTooltip = (btn, message) => {
        $(btn).tooltip('hide')
         .attr('data-original-title', message)
         .tooltip('show');
    }

    const hideTooltip = (btn) => {
        setTimeout(function() {
            $(btn).tooltip('hide');
        }, 1000);
    }

    let clipboard = new Clipboard('.btn-copy');

    clipboard.on('success', function(e) {
        e.clearSelection();
        tracking('', 'scodecopied', 'user-dashboard-mb');
        setTooltip(e.trigger, 'Copied!');
        hideTooltip(e.trigger);
    });

    clipboard.on('error', function(e) {
        e.clearSelection();
        setTooltip(e.trigger, 'Failed!');
        hideTooltip(e.trigger);
    });

    // Email Invite code
    $('.invite-form').each(function() {
        $(this).validate({
            debug: true,
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
    });

    $('.btn-invite').on('click', function(e) {
        e.preventDefault();

        let formValid = $(this).data('form');

        if ($(formValid).valid()) {

            let btn = $(this),
                emailUrl = btn.data('path');

            $(this).attr('disabled', 'disabled')
                   .html('Sending <i class="fas fa-circle-notch fa-spin"></i>');

            $.ajax({
                url: emailUrl,
                type: 'POST',
                data: {
                    email: $('.input-invite').val(),
                    csrf: $('.email-csrf').val()
                }
            })
            .done(function(data) {
                // console.log(data);
                btn.tooltip({
                    'title':   'Your invite is on it\'s way 😀',
                    'trigger': 'manual'
                }).tooltip('show');

                setTimeout(function() {
                    btn.tooltip('hide')
                                    .removeAttr('disabled', '')
                                    .html('Send invite');
                    // location.reload();
                }, 1500);

                // Clear the input and suggest another one? TODO: Copy
                $('.input-invite').val('').attr('placeholder', 'Enter email address');
            })
            .fail(function(data) {
                btn.tooltip({
                    'title':   'Sorry, we were unable to send your invitation. Please try again later 😥',
                    'trigger': 'manual'
                }).tooltip('show');

                setTimeout(function() {
                    btn.tooltip('hide')
                                    .removeAttr('disabled', '')
                                    .html('Send invite');
                }, 1500);
            });
        }
    });
});
