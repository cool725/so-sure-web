// competition-questions.js

// Require components
let textFit = require('textfit');
require('jquery-validation');
require('../common/validationMethods.js');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    // Use textfit plugin for h1 tag
    // textFit($('.fit'), {detectMultiLine: true});

    let validateForm = $('.validate-form'),
        isIE = !!navigator.userAgent.match(/Trident/g) || !!navigator.userAgent.match(/MSIE/g),
        paymentForm = $('.payment-form');

    const addValidation = () => {
        validateForm.validate({
            debug: true,
            // When to validate
            validClass: 'is-valid-ss',
            errorClass: 'is-invalid',
            onfocusout: false,
            onkeyup: false,
            rules: {
                "questions_form[questionOne]" : {
                    required: true
                },
                "questions_form[questionTwo]" : {
                    required: true
                },
                "questions_form[questionThree]" : {
                    required: true
                }
            },
            messages: {
                "questions_form[questionOne]" : {
                    required: 'Please choose an answer...'
                },
                "questions_form[questionTwo]" : {
                    required: 'Please choose an answer...'
                },
                "questions_form[questionThree]" : {
                    required: 'Please choose an answer...'
                }
            },

            errorPlacement: function(error, element) {
                if (element.attr('name') == 'questions_form[questionOne]') {
                    error.insertAfter('#questions_form_questionOne');
                } else if(element.attr('name') == 'questions_form[questionTwo]') {
                    error.insertAfter('#questions_form_questionTwo');
                } else if(element.attr('name') == 'questions_form[questionThree]') {
                    error.insertAfter('#questions_form_questionThree');
                } else {
                    error.insertAfter(element);
                }
            },

            submitHandler: function(form) {
                form.submit();
            }
        });
    }

    // Add validation
    if (validateForm.data('client-validation') && !isIE) {
        addValidation();
    }

    // Step validation
    const questionValid = (question, questionCont) => {
        if ($('[name="questions_form['+question+']"]').valid()) {
            $(questionCont).fadeOut('slow', function() {
                $(this).next('.question').fadeIn();
            });
        }
    }

    // Continue button
    $('.question-continue').on('click', function(e) {
        e.preventDefault();

        let question = $(this).data('target'),
            questionCont = $(this).parent().parent();

        $('html, body').animate({ scrollTop: 0 }, 'slow');

        questionValid(question, questionCont);
    });

    $('.radio-btn').on('click', function(e) {
        e.preventDefault();

        $('.radio-btn').removeClass('radio-btn-active');
        $(this).addClass('radio-btn-active');

        // Set the value for the form element
        let val = $(this).data('value');
        console.log(val);
        $('#'+val).prop('checked', true);
    });

});
