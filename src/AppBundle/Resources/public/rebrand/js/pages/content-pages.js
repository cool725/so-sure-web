// faq.js

require('../../sass/pages/content-pages.scss');

// Require BS component(s)
require('bootstrap/js/dist/scrollspy');
// require('bootstrap/js/dist/dropdown');

// Require components
require('../common/fixed-width.js');
require('jquery-validation');
require('../common/validation-methods.js');
const { ajax } = require('jquery');
let textFit = require('textfit');

$(function() {

    // Init scrollspy
    $('body').scrollspy({
        target: '#faq-nav',
        offset: 200
    });

    $('#faq-nav a').on('click', function(e) {
        if (this.hash != '') {
            e.preventDefault();

            let hash = this.hash;

            $('html, body').animate({
                scrollTop: $(hash).offset().top
                }, 800, function(){

                window.location.hash = hash;
            });
        }
    });

    $('.back-to-top-faq').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: 0
        }, 300);
    });

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g);

    const addValidation = () => {
    validateForm.each(function() {
        $(this).validate({
        debug: false,
        // When to validate
        validClass: 'is-valid-ss',
        errorClass: 'is-invalid',
        onfocusout: false,
        onkeyup: false,
        rules: {
            "blog-lead-email" : {
            required: {
                depends:function(){
                    $(this).val($.trim($(this).val()));
                    return true;
                }
            },
            email: true,
            emaildomain: true
            },
        },
        messages: {
            "blog-lead-email" : {
            required: 'Please enter a valid email address.'
            },
        },

        submitHandler: function(form) {
            $(form).find('.blog-lead-submit').prop('disabled', 'disabled');
            $(form).find('.blog-lead-feedback').animate({opacity: 0});
            let data = {
                email: $(form).find('.blog-lead-email').val(),
                csrf: $(form).data('csrf')
            }
            $.ajax({
                url: $(form).data('lead'),
                type: 'POST',
                data: JSON.stringify(data),
                contentType: "application/json; charset=utf-8",
                dataType: "json",
            })
            .done(function(data) {
                $(form).find('.blog-lead-feedback').text('Thanks for signing up!');
                $(form).find('.blog-lead-email').prop('disabled', 'disabled');
            })
            .fail(function(data) {
                $(form).find('.blog-lead-feedback').text('Something went wrong, please try again');
                $(form).find('.blog-lead-email, .blog-lead-submit').prop('disabled', '');
            })
            .always(function(){
                $(form).find('.blog-lead-feedback').animate({opacity: 1});
            });
        }
        });
    });
    }

    // Add validation
    if (validateForm.data('client-validation') && !isIE) {
    addValidation();
    }
});
