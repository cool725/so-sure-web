// homepage.js

require('../../sass/pages/homepage.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');
require('bootstrap/js/dist/tab');

// Require components
require('../components/banner.js');
let textFit = require('textfit');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();


$(function() {

    textFit($('.fit'), {detectMultiLine: false});

    setTimeout(function() {
        $('.toast-1').toast('show');
    }, 2000);

    $('.toast-1').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-2').toast('show');
        }, 200);
    });

    $('.toast-2').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-3').toast('show');
        }, 200);
    });

    $('.toast-3').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-4').toast('show');
        }, 200);
    });

    $('.toast-4').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-5').toast('show');
        }, 200);
    });

    let videoCont = $('#hero_video'),
        video = $('#hero_video').get(0),
        play  = $('#play_video'),
        quote = $('#get_a_quote_video');

    $('#watch_tv_advert').on('click', function(e) {
        e.preventDefault();

        // Reset
        quote.hide();
        videoCont.css('filter', 'brightness(1)');

        let diff = 50;

        if( /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ) {
            diff = 100;
        }

        $('html, body').animate({
            scrollTop: ($('#cred').offset().top + diff)
        }, 500);

        if (video.paused) {
            video.play();
            videoCont.attr('controls', 'controls');
            play.fadeOut();
        } else {
            video.pause();
        }
    });

    play.on('click', function(e) {
        e.preventDefault();

        if (video.paused) {
            video.play();
            videoCont.attr('controls', 'controls');
            $(this).fadeOut();
        } else {
            video.pause();
        }
    });

    videoCont.on('ended', function(e) {
        quote.fadeIn();
        $(this).css('filter', 'brightness(50%)');
    });

});

