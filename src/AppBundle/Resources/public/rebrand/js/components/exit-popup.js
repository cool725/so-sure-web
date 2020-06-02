// exit-popup.js

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/tooltip');

// Require components
require('jquery-validation');
require('../common/validation-methods.js');

import Cookies from 'js.cookie';

// Get cookie
let interacted = false,
    exitCookie = Cookies.get('exit-popup-so-sure');

// Copy to Clipboard
let Clipboard = require('clipboard');

$(function() {

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
        console.info('Text:', e.text);
        setTooltip(e.trigger, 'Copied!');
        hideTooltip(e.trigger);
    });

    clipboard.on('error', function(e) {
        e.clearSelection();
        setTooltip(e.trigger, 'Failed!');
        hideTooltip(e.trigger);
    });

    // When mouseout of document show popup - check for interacted/cookie before showing
    $(document).on('mouseout', function(e) {
        if (!e.toElement && !e.relatedTarget && interacted == false && !exitCookie) {
            setTimeout(() => {
                $('#exit_popup_modal').modal('show');
            }, 1500)
        }
    });

    // Set the cookie if modal is closed
    $('#exit_popup_modal').on('hidden.bs.modal', function (e) {
        // Stop it showing
        interacted = true;
        // Set cookie for returning visitors
        Cookies.set('exit-popup-so-sure', 'true', { sameSite: 'strict' });
    });

    // Set the cookie if user
    $('.exit-popup-promo-page').on('click', function() {
        // Stop it showing
        interacted = true;
        // Set cookie for returning visitors
        Cookies.set('exit-popup-so-sure', 'true', { sameSite: 'strict' });
    });

    // Validate email field
    $('#exit_popup_form').validate({
        debug: false,
        // When to validate
        validClass: 'is-valid-ss',
        errorClass: 'is-invalid',
        onfocusout: false,
        onkeyup: false,
        rules: {
            'email': {
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
            'email': {
                required: 'Please enter a valid email',
                email: 'Please enter a valid email',
                emaildomain: "Please check you've entered a valid email"
            }
        },
        submitHandler: function() {
            // Don't subit the form after validation
            return false;
        }
    });

    $('#exit_popup_submit').on('click', function(e) {
        if ($('#exit_popup_form').valid()) {
            e.preventDefault();
            let emailUrl = $(this).data('path'),
                emailAddress = $('#exit_popup_email');

            $.ajax({
                url: emailUrl,
                type: 'POST',
                data: {
                    email: emailAddress.val()
                }
            })
            .done(function(data) {
                // To simulate change for now - remove before release
                $('.exit-offer').fadeOut('fast', function() {
                    $('.exit-code').fadeIn('fast');
                });
                // Set the cookie just incase they reload the page
                Cookies.set('exit-popup-so-sure', 'true', { sameSite: 'strict' });
            })
            .fail(function(data) {
                $('.exit-offer').fadeOut('fast', function() {
                    $('.exit-error').fadeIn('fast');
                });
            });
        }
    });
});
