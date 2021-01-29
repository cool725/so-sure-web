// invite.js

require('../../sass/pages/invite.scss');

// Require BS component(s)
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/tab');

// Require components
let textFit = require('textfit');
require('../components/table.js');

$(function() {

    // Use textfit plugin for h1 tag
    textFit($('.fit'), {detectMultiLine: true});

    // Adjust top position of knowledge base as true value differs between browser
    let knowledgeBaseD = $('.knowledge-base__desktop'),
        tabsHeight     = knowledgeBaseD.find('.nav-item').height();
        knowledgeBaseD.css('top', -tabsHeight);

    // Tabs - style the arrow if open
    // $('.tab-link').on('click', function(e) {
    //     let clicked = $(this);
    //     $('.tab-indicator').removeClass('fa-arrow-circle-up').addClass('fa-arrow-circle-down');
    //     clicked.find('.tab-indicator').removeClass('fa-arrow-circle-down').addClass('fa-arrow-circle-up');
    // });

    // Carousel setup
    const startStep = () => {
        $('#hero_img_1_1').show().addClass('animated bounceInUp');
        $('#hero_img_1_2').show().addClass('animated zoomInLeft');
        $('#hero_img_overlay_1').show().addClass('animated delay-2s fadeIn');
    }

    const stepOne = () => {
        $('.animated').hide(function() {
            $('#hero_img_1_1').show().addClass('animated bounceInUp');
            $('#hero_img_1_2').show().addClass('animated zoomInLeft');
            $('#hero_img_overlay_1').show().addClass('animated delay-2s fadeIn');
        });
    }

    const stepTwo = () => {
        $('.animated').hide(function() {
            $('#hero_img_1_1').show().addClass('animated bounceInUp');
            $('#hero_img_1_2').show().addClass('animated zoomInLeft');
            $('#hero_img_2_1').show().addClass('animated slideInRight');
            $('#hero_img_overlay_2').show().addClass('animated delay-2s fadeIn');
        });
    }

    const stepThree = () => {
        $('.animated').hide(function() {
            $('#hero_img_3_1').show().addClass('animated bounceInUp');
            $('#hero_img_3_2').show().addClass('animated zoomInLeft');
            $('#hero_img_3_3').show().addClass('animated slideInRight');
            $('#hero_img_overlay_3').show().addClass('animated delay-2s fadeIn');
        });
    }

    // Init the animations
    startStep();

    // Carousel
    $('#invite_carousel').on('slid.bs.carousel', function (e) {

        switch(e.to) {
            case 0:
                stepOne();
                break;
            case 1:
                stepTwo();
                break;
            case 2:
                stepThree();
                break;
        }
    })

    // Scroll to feedback section
    $('#user_feedback_btn').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: $('#user_feedback').offset().top
        }, 500);
    });
});
