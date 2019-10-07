// phone-insurance.js

require('../../sass/pages/phone-insurance.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');

// Require components
require('../components/banner.js');
let textFit = require('textfit');

let jQueryBridget = require('jquery-bridget');
let Flickity = require('flickity');

// make Flickity a jQuery plugin
Flickity.setJQuery( $ );
jQueryBridget( 'flickity', Flickity, $ );

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {
    // Textfit h1
    if ($('.fit').length) {
        textFit($('.fit')[0]);
    }

    // Reviews
    let $carousel = $('#customer_reviews').flickity({
        wrapAround: true,
        prevNextButtons: false,
        pageDots: false
    });

    $('.review__controls-prev').on('click', function(e) {
        $carousel.flickity('previous');
    });

    $('.review__controls-next').on('click', function(e) {
        $carousel.flickity('next');
    });

});

