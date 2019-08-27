// promo.js

require('../../sass/pages/promo.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

let textFit = require('textfit');

$(function() {

    textFit($('.fit')[0], {detectMultiLine: false});

    setTimeout(function() {
        $('.toast-1').toast('show');
    }, 2000);

    $('.toast-1').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-2').toast('show');
        }, 200);
    })

    $('.toast-2').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-3').toast('show');
        }, 200);
    })

    $('.toast-3').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-4').toast('show');
        }, 200);
    })

    $('.toast-4').on('hidden.bs.toast', function () {
        setTimeout(function() {
            $('.toast-5').toast('show');
        }, 200);
    })

    // $('.terms-link').on('click', function(e) {
    //     e.preventDefault();

    //     $('html, body').animate({
    //         scrollTop: $('#terms_and_conditions').offset().top
    //     }, 500);
    // });


});
