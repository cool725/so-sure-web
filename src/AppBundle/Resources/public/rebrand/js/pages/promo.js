// promo.js

require('../../sass/pages/promo.scss');

// Require BS component(s)

// Require components

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

let textFit = require('textfit');

$(function() {

    textFit($('.fit')[0], {detectMultiLine: false});

    $('.terms-link').on('click', function(e) {
        e.preventDefault();

        $('html, body').animate({
            scrollTop: $('#terms_and_conditions').offset().top
        }, 500);
    });


});
